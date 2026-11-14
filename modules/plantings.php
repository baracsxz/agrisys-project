<?php
//define base path and load required dependencies
$base = '..';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ui.php';
require_once __DIR__ . '/../config/Database.php';

//ensure user is authenticated and initialize database connection
require_login();
$pdo = Database::getInstance();

//fixed absolute path to relative form to prevent deployment issues under /agrisys/
$path = 'plantings.php';
$errors = [];
$form = [
    'farm_id'                 => '',
    'crop_id'                 => '',
    'status_id'               => 1,
    'planting_date'           => date('Y-m-d'),
    'area_planted'            => '',
    'seed_quantity'           => '',
    'expected_harvest_date' => ''
];

$editId = (int) ($_GET['edit'] ?? 0);
$showForm = isset($_GET['new']) || $editId > 0;

//if editing, fetch existing planting record data
if ($editId > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $stmt = $pdo->prepare("SELECT * FROM planting WHERE planting_id = :id");
    $stmt->execute(['id' => $editId]);
    $plantingData = $stmt->fetch();
    if ($plantingData) {
        $form['farm_id']                 = $plantingData['farm_id'];
        $form['crop_id']                 = $plantingData['crop_id'];
        $form['status_id']               = $plantingData['status_id'];
        $form['planting_date']           = $plantingData['planting_date'];
        $form['area_planted']            = $plantingData['area_planted'];
        $form['seed_quantity']           = $plantingData['seed_quantity'];
        $form['expected_harvest_date'] = $plantingData['expected_harvest_date'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $mode = $_POST['mode'] ?? 'create';

    //handle delete operation (admin only)
    if ($mode === 'delete') {
        require_admin();
        $id = (int) ($_POST['id'] ?? 0);

        try {
            //prevent deletion if planting has linked harvest records
            $stmt_c = $pdo->prepare("SELECT COUNT(*) FROM harvest WHERE planting_id = :id");
            $stmt_c->execute(['id' => $id]);
            if ((int) $stmt_c->fetchColumn() > 0) {
                flash('danger', 'Cannot delete planting record because it has linked harvest records.');
                redirect($path);
            }

            $stmt = $pdo->prepare("DELETE FROM planting WHERE planting_id = :id");
            $stmt->execute(['id' => $id]);

            flash('success', 'Planting record deleted successfully.');
            redirect($path);
        } catch (Exception $e) {
            flash('danger', 'Failed to delete planting record. Please try again.');
            redirect($path);
        }
    }

    //sanitize incoming inputs
    $form['farm_id']                 = (int) ($_POST['farm_id'] ?? 0);
    $form['crop_id']                 = (int) ($_POST['crop_id'] ?? 0);
    $form['status_id']               = (int) ($_POST['status_id'] ?? 0);
    $form['planting_date']           = trim($_POST['planting_date'] ?? '');
    $form['area_planted']            = trim($_POST['area_planted'] ?? '');
    $form['seed_quantity']           = trim($_POST['seed_quantity'] ?? '');
    $form['expected_harvest_date'] = trim($_POST['expected_harvest_date'] ?? '');

    //validate farm existence explicitly (fixes validation gap)
    if ($form['farm_id'] <= 0) {
        $errors[] = 'Valid farm must be selected.';
    } else {
        $stmtCheckFarm = $pdo->prepare("SELECT COUNT(*) FROM farm WHERE farm_id = :id");
        $stmtCheckFarm->execute(['id' => $form['farm_id']]);
        if ((int) $stmtCheckFarm->fetchColumn() === 0) {
            $errors[] = 'Selected farm does not exist.';
        }
    }

    //validate crop existence explicitly (fixes validation gap)
    if ($form['crop_id'] <= 0) {
        $errors[] = 'Valid crop must be selected.';
    } else {
        $stmtCheckCrop = $pdo->prepare("SELECT COUNT(*) FROM crop WHERE crop_id = :id");
        $stmtCheckCrop->execute(['id' => $form['crop_id']]);
        if ((int) $stmtCheckCrop->fetchColumn() === 0) {
            $errors[] = 'Selected crop does not exist.';
        }
    }

    if ($form['status_id'] <= 0) $errors[] = 'Planting status is required.';

    if (!is_numeric($form['area_planted']) || (float) $form['area_planted'] <= 0) {
        $errors[] = 'Area planted must be a positive number.';
    } elseif ($form['farm_id'] > 0) {
        //aggregate total planted area across all records for this farm (excluding current record if editing)
        $stmtExistingArea = $pdo->prepare("
            SELECT COALESCE(SUM(area_planted), 0) 
            FROM planting 
            WHERE farm_id = :farm_id " . ($editId > 0 ? "AND planting_id != :edit_id" : "")
        );
        $paramsArea = ['farm_id' => $form['farm_id']];
        if ($editId > 0) {
            $paramsArea['edit_id'] = $editId;
        }
        $stmtExistingArea->execute($paramsArea);
        $usedArea = (float) $stmtExistingArea->fetchColumn();

        //fetch total max farm area
        $stmtFarmArea = $pdo->prepare("SELECT area_hectares FROM farm WHERE farm_id = :id");
        $stmtFarmArea->execute(['id' => $form['farm_id']]);
        $maxArea = $stmtFarmArea->fetchColumn();

        if ($maxArea !== false) {
            $totalProjectedArea = $usedArea + (float) $form['area_planted'];
            if ($totalProjectedArea > (float) $maxArea) {
                $errors[] = 'Total planted area (' . number_format($totalProjectedArea, 2) . ' ha) would exceed the total farm area (' . number_format((float)$maxArea, 2) . ' ha). Already used: ' . number_format($usedArea, 2) . ' ha.';
            }
        }
    }

    if (!is_numeric($form['seed_quantity']) || (float) $form['seed_quantity'] <= 0) {
        $errors[] = 'Seed quantity must be a positive number.';
    }

    // validate planting date and prevent future dates (fixes professor requirement)
    if (empty($form['planting_date']) || !strtotime($form['planting_date'])) {
        $errors[] = 'A valid planting date is required.';
    } elseif (strtotime($form['planting_date']) > time()) {
        $errors[] = 'Planting date cannot be set in the future.';
    }

    if (empty($form['expected_harvest_date']) || !strtotime($form['expected_harvest_date'])) {
        $errors[] = 'A valid expected harvest date is required.';
    } elseif (!empty($form['planting_date']) && strtotime($form['expected_harvest_date']) < strtotime($form['planting_date'])) {
        $errors[] = 'Expected harvest date cannot be earlier than the planting date.';
    }

    if (empty($errors)) {
        try {
            if ($mode === 'update' && $editId > 0) {
                //update existing planting record
                $sql = "UPDATE planting
                        SET farm_id = :farm_id,
                            crop_id = :crop_id,
                            status_id = :status_id,
                            planting_date = :p_date,
                            area_planted = :area_planted,
                            seed_quantity = :seed_quantity,
                            expected_harvest_date = :e_date,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE planting_id = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    'farm_id'       => $form['farm_id'],
                    'crop_id'       => $form['crop_id'],
                    'status_id'     => $form['status_id'],
                    'p_date'        => $form['planting_date'],
                    'area_planted'  => $form['area_planted'],
                    'seed_quantity' => $form['seed_quantity'],
                    'e_date'        => $form['expected_harvest_date'],
                    'id'            => $editId
                ]);

                //check rowCount() to ensure valid record update and prevent false success on invalid edit ids
                if ($stmt->rowCount() === 0) {
                    throw new Exception('Planting record not found or no changes were made.');
                }
            } else {
                //insert new planting record
                $sql = "INSERT INTO planting (farm_id, crop_id, status_id, planting_date, area_planted, seed_quantity, expected_harvest_date)
                        VALUES (:farm_id, :crop_id, :status_id, :p_date, :area_planted, :seed_quantity, :e_date)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    'farm_id'       => $form['farm_id'],
                    'crop_id'       => $form['crop_id'],
                    'status_id'     => $form['status_id'],
                    'p_date'        => $form['planting_date'],
                    'area_planted'  => $form['area_planted'],
                    'seed_quantity' => $form['seed_quantity'],
                    'e_date'        => $form['expected_harvest_date']
                ]);
            }

            $msg = ($mode === 'update' && $editId > 0) ? 'Planting record updated successfully.' : 'Planting record added successfully.';
            flash('success', $msg);
            redirect($path);
            
        } catch (Exception $e) {
            //catch custom exception message or fallback to default error
            $errors[] = $e->getMessage() !== 'Exception' && !empty($e->getMessage()) ? $e->getMessage() : 'Unable to save planting record. Please verify your inputs and try again.';
        }
    }
}

//lookups for dropdown lists
$farms_list    = $pdo->query("SELECT farm_id, farm_name, area_hectares FROM farm ORDER BY farm_name ASC")->fetchAll();
$crops_list    = $pdo->query("SELECT crop_id, crop_name, variety, growing_period_days FROM crop ORDER BY crop_name ASC")->fetchAll();
$statuses_list = $pdo->query("SELECT status_id, status_name FROM planting_status ORDER BY status_id ASC")->fetchAll();

//search, filter, sort, and pagination configuration
$search       = trim($_GET['search'] ?? '');
$statusFilter = (int) ($_GET['status'] ?? 0);
$sort         = $_GET['sort'] ?? 'planting_id';
$order        = strtoupper($_GET['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

$allowedSorts = [
    'planting_id'           => 'p.planting_id',
    'farm_name'             => 'f.farm_name',
    'crop_name'             => 'c.crop_name',
    'planting_date'         => 'p.planting_date',
    'area_planted'          => 'p.area_planted',
    'expected_harvest_date' => 'p.expected_harvest_date',
    'status_name'           => 'ps.status_name'
];
$sortColumn = $allowedSorts[$sort] ?? 'p.planting_id';

$whereClauses = [];
$params = [];

if ($search !== '') {
    $whereClauses[] = "(f.farm_name ILIKE :search OR c.crop_name ILIKE :search OR c.variety ILIKE :search)";
    $params['search'] = '%' . $search . '%';
}
if ($statusFilter > 0) {
    $whereClauses[] = "p.status_id = :status_id";
    $params['status_id'] = $statusFilter;
}

$whereSql = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

$perPage = 10;
$page    = max(1, (int) ($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

//calculate total rows for pagination
$stmtCount = $pdo->prepare("
    SELECT COUNT(*)
    FROM planting p
    JOIN farm f ON p.farm_id = f.farm_id
    JOIN crop c ON p.crop_id = c.crop_id
    JOIN planting_status ps ON p.status_id = ps.status_id
    $whereSql
");
$stmtCount->execute($params);
$totalRows  = (int) $stmtCount->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $perPage));

//fetch paginated planting records
$sql_all = "
    SELECT p.planting_id, p.planting_date, p.area_planted, p.seed_quantity, p.expected_harvest_date,
           f.farm_name, c.crop_name, c.variety, ps.status_name
    FROM planting p
    JOIN farm f ON p.farm_id = f.farm_id
    JOIN crop c ON p.crop_id = c.crop_id
    JOIN planting_status ps ON p.status_id = ps.status_id
    $whereSql
    ORDER BY $sortColumn $order
    LIMIT :limit OFFSET :offset
";
$stmt_all = $pdo->prepare($sql_all);
foreach ($params as $key => $val) {
    $stmt_all->bindValue(':' . $key, $val);
}
$stmt_all->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt_all->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt_all->execute();
$plantings = $stmt_all->fetchAll();

$nextOrder = $order === 'ASC' ? 'DESC' : 'ASC';

//include header view
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Planting Management</h2>
    <?php if (!$showForm): ?>
        <a href="?new=1" class="btn btn-success">+ Add New Planting</a>
    <?php else: ?>
        <a href="plantings.php" class="btn btn-secondary">Back to List</a>
    <?php endif; ?>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $error): ?>
                <li><?= htmlspecialchars($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($showForm): ?>
    <!-- form view (create / edit) -->
    <div class="card shadow-sm p-4 mb-4">
        <h4 class="mb-3"><?= $editId > 0 ? 'Edit Planting' : 'Add New Planting Record' ?></h4>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="mode" value="<?= $editId > 0 ? 'update' : 'create' ?>">

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Farm</label>
                    <select name="farm_id" class="form-select" required>
                        <option value="">-- Select Farm --</option>
                        <?php foreach ($farms_list as $f): ?>
                            <option value="<?= (int) $f['farm_id'] ?>" <?= $form['farm_id'] == $f['farm_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($f['farm_name']) ?> (<?= number_format((float) $f['area_hectares'], 2) ?> ha)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-6 mb-3">
                    <label class="form-label">Crop</label>
                    <select name="crop_id" id="crop_select" class="form-select" required>
                        <option value="">-- Select Crop --</option>
                        <?php foreach ($crops_list as $c): ?>
                            <option value="<?= (int) $c['crop_id'] ?>" data-days="<?= (int) $c['growing_period_days'] ?>" <?= $form['crop_id'] == $c['crop_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['crop_name'] . ' (' . $c['variety'] . ')') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label">Area Planted (Hectares)</label>
                    <input type="number" step="0.01" min="0.01" name="area_planted" class="form-control" value="<?= htmlspecialchars($form['area_planted']) ?>" required>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Seed Quantity (kg)</label>
                    <input type="number" step="0.01" min="0.01" name="seed_quantity" class="form-control" value="<?= htmlspecialchars($form['seed_quantity']) ?>" required>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Status</label>
                    <select name="status_id" class="form-select" required>
                        <?php foreach ($statuses_list as $st): ?>
                            <option value="<?= (int) $st['status_id'] ?>" <?= $form['status_id'] == $st['status_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($st['status_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Planting Date</label>
                    <input type="date" name="planting_date" id="planting_date" class="form-control" value="<?= htmlspecialchars($form['planting_date']) ?>" max="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Expected Harvest Date</label>
                    <input type="date" name="expected_harvest_date" id="expected_harvest_date" class="form-control" value="<?= htmlspecialchars($form['expected_harvest_date']) ?>" required>
                </div>
            </div>

            <button type="submit" class="btn btn-success"><?= $editId > 0 ? 'Update Planting' : 'Save Planting' ?></button>
            <a href="plantings.php" class="btn btn-outline-secondary">Cancel</a>
        </form>
    </div>
<?php else: ?>
    <!-- search & filter bar -->
    <div class="card shadow-sm p-3 mb-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-5">
                <label class="form-label small text-muted mb-1">Search Farm or Crop</label>
                <input type="text" name="search" class="form-control" placeholder="Search plantings..." value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label small text-muted mb-1">Filter by Status</label>
                <select name="status" class="form-select">
                    <option value="0">All Statuses</option>
                    <?php foreach ($statuses_list as $st): ?>
                        <option value="<?= (int) $st['status_id'] ?>" <?= $statusFilter === (int) $st['status_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($st['status_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-success w-100">Filter</button>
                <a href="plantings.php" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>

    <!-- data table view -->
    <div class="card shadow-sm p-4">
        <div class="table-responsive">
            <table class="table table-striped align-middle">
                <thead>
                    <tr>
                        <th><a href="?sort=planting_id&order=<?= $nextOrder ?>&search=<?= urlencode($search) ?>&status=<?= $statusFilter ?>" class="text-decoration-none text-dark">ID</a></th>
                        <th><a href="?sort=farm_name&order=<?= $nextOrder ?>&search=<?= urlencode($search) ?>&status=<?= $statusFilter ?>" class="text-decoration-none text-dark">Farm</a></th>
                        <th><a href="?sort=crop_name&order=<?= $nextOrder ?>&search=<?= urlencode($search) ?>&status=<?= $statusFilter ?>" class="text-decoration-none text-dark">Crop (Variety)</a></th>
                        <th><a href="?sort=area_planted&order=<?= $nextOrder ?>&search=<?= urlencode($search) ?>&status=<?= $statusFilter ?>" class="text-decoration-none text-dark">Area (Ha)</a></th>
                        <th>Seed (kg)</th>
                        <th><a href="?sort=planting_date&order=<?= $nextOrder ?>&search=<?= urlencode($search) ?>&status=<?= $statusFilter ?>" class="text-decoration-none text-dark">Planting Date</a></th>
                        <th><a href="?sort=expected_harvest_date&order=<?= $nextOrder ?>&search=<?= urlencode($search) ?>&status=<?= $statusFilter ?>" class="text-decoration-none text-dark">Expected Harvest</a></th>
                        <th><a href="?sort=status_name&order=<?= $nextOrder ?>&search=<?= urlencode($search) ?>&status=<?= $statusFilter ?>" class="text-decoration-none text-dark">Status</a></th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($plantings)): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted">No planting records found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($plantings as $p): ?>
                            <?php
                            $badgeClass = match ($p['status_name']) {
                                'Growing'   => 'success',
                                'Harvested' => 'primary',
                                'Damaged'   => 'danger',
                                default     => 'secondary',
                            };
                            ?>
                            <tr>
                                <td><?= (int) $p['planting_id'] ?></td>
                                <td><?= htmlspecialchars($p['farm_name']) ?></td>
                                <td><?= htmlspecialchars($p['crop_name'] . ' (' . $p['variety'] . ')') ?></td>
                                <td><?= number_format((float) $p['area_planted'], 2) ?></td>
                                <td><?= number_format((float) $p['seed_quantity'], 2) ?></td>
                                <td><?= htmlspecialchars($p['planting_date']) ?></td>
                                <td><?= htmlspecialchars($p['expected_harvest_date']) ?></td>
                                <td><span class="badge bg-<?= $badgeClass ?>"><?= htmlspecialchars($p['status_name']) ?></span></td>
                                <td>
                                    <a href="view.php?type=planting&id=<?= (int) $p['planting_id'] ?>" class="btn btn-sm btn-info text-white">View</a>
                                    <a href="?edit=<?= (int) $p['planting_id'] ?>" class="btn btn-sm btn-primary">Edit</a>
                                    <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                            <input type="hidden" name="mode" value="delete">
                                            <input type="hidden" name="id" value="<?= (int) $p['planting_id'] ?>">
                                            <button type="button" class="btn btn-sm btn-danger delete-btn">Delete</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- pagination links -->
        <?php if ($totalPages > 1): ?>
            <nav class="mt-3">
                <ul class="pagination justify-content-end mb-0">
                    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                        <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                            <a class="page-link" href="?page=<?= $p ?>&sort=<?= urlencode($sort) ?>&order=<?= urlencode($order) ?>&search=<?= urlencode($search) ?>&status=<?= $statusFilter ?>">
                                <?= $p ?>
                            </a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php 
//include footer
require_once __DIR__ . '/../includes/footer.php';