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
$path = 'farms.php';
$errors = [];
$form = [
    'farmer_id'      => '',
    'farm_name'      => '',
    'location'       => '',
    'area_hectares'  => '',
    'soil_ids'       => [],
    'irrigation_ids' => []
];

$editId = (int) ($_GET['edit'] ?? 0);
$showForm = isset($_GET['new']) || $editId > 0;

//if editing, fetch existing farm details and associated soil/irrigation options
if ($editId > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $stmt = $pdo->prepare("SELECT * FROM farm WHERE farm_id = :id");
    $stmt->execute(['id' => $editId]);
    $farmData = $stmt->fetch();
    if ($farmData) {
        $form['farmer_id']      = $farmData['farmer_id'];
        $form['farm_name']      = $farmData['farm_name'];
        $form['location']       = $farmData['location'];
        $form['area_hectares']  = $farmData['area_hectares'];

        // fetch associated soil types
        $stmt_s = $pdo->prepare("SELECT soil_id FROM farm_soil WHERE farm_id = :id");
        $stmt_s->execute(['id' => $editId]);
        $form['soil_ids'] = $stmt_s->fetchAll(PDO::FETCH_COLUMN);

        // fetch associated irrigation types
        $stmt_i = $pdo->prepare("SELECT irrigation_id FROM farm_irrigation WHERE farm_id = :id");
        $stmt_i->execute(['id' => $editId]);
        $form['irrigation_ids'] = $stmt_i->fetchAll(PDO::FETCH_COLUMN);
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
            // prevent deletion if farm has linked plantings
            $stmt_c = $pdo->prepare("SELECT COUNT(*) FROM planting WHERE farm_id = :id");
            $stmt_c->execute(['id' => $id]);
            if ((int) $stmt_c->fetchColumn() > 0) {
                flash('danger', 'Cannot delete farm because it has registered planting records.');
                redirect($path);
            }

            $stmt = $pdo->prepare("DELETE FROM farm WHERE farm_id = :id");
            $stmt->execute(['id' => $id]);

            flash('success', 'Farm deleted successfully.');
            redirect($path);
        } catch (Exception $e) {
            flash('danger', 'Failed to delete farm record. Please try again.');
            redirect($path);
        }
    }

    //sanitize incoming inputs
    $form['farmer_id']      = (int) ($_POST['farmer_id'] ?? 0);
    $form['farm_name']      = trim($_POST['farm_name'] ?? '');
    $form['location']       = trim($_POST['location'] ?? '');
    $form['area_hectares']  = trim($_POST['area_hectares'] ?? '');
    
    //check and ensure arrays are valid
    $form['soil_ids']       = is_array($_POST['soil_ids'] ?? null) ? array_map('intval', $_POST['soil_ids']) : [];
    $form['irrigation_ids'] = is_array($_POST['irrigation_ids'] ?? null) ? array_map('intval', $_POST['irrigation_ids']) : [];

    //validate form fields
    if ($form['farmer_id'] <= 0) {
        $errors[] = 'Valid farmer must be selected.';
    } else {
        //explicit existence check for foreign key farmer_id (fixes requirement)
        $stmtCheckFarmer = $pdo->prepare("SELECT COUNT(*) FROM farmer WHERE farmer_id = :id");
        $stmtCheckFarmer->execute(['id' => $form['farmer_id']]);
        if ((int) $stmtCheckFarmer->fetchColumn() === 0) {
            $errors[] = 'Selected farmer does not exist in the database.';
        }
    }

    if (empty($form['farm_name'])) $errors[] = 'Farm name is required.';
    if (empty($form['location'])) $errors[] = 'Location is required.';
    if (!is_numeric($form['area_hectares']) || (float) $form['area_hectares'] <= 0) {
        $errors[] = 'Area in hectares must be a positive number.';
    }
    if (empty($form['soil_ids'])) $errors[] = 'Select at least one soil type.';
    if (empty($form['irrigation_ids'])) $errors[] = 'Select at least one irrigation type.';

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            if ($mode === 'update' && $editId > 0) {
                //update existing farm record
                $sql = "UPDATE farm
                        SET farmer_id = :farmer_id,
                            farm_name = :farm_name,
                            location = :location,
                            area_hectares = :area,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE farm_id = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    'farmer_id' => $form['farmer_id'],
                    'farm_name' => $form['farm_name'],
                    'location'  => $form['location'],
                    'area'      => $form['area_hectares'],
                    'id'        => $editId
                ]);

                //check rowCount() to ensure valid record update
                if ($stmt->rowCount() === 0) {
                    throw new Exception('Farm record not found or no changes were made.');
                }

                $farm_id = $editId;

                // clear old relationships before re-inserting
                $pdo->prepare("DELETE FROM farm_soil WHERE farm_id = :id")->execute(['id' => $farm_id]);
                $pdo->prepare("DELETE FROM farm_irrigation WHERE farm_id = :id")->execute(['id' => $farm_id]);
                
                $successMessage = 'Farm updated successfully.';
            } else {
                //insert new farm record and return id
                $sql = "INSERT INTO farm (farmer_id, farm_name, location, area_hectares)
                        VALUES (:farmer_id, :farm_name, :location, :area)
                        RETURNING farm_id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    'farmer_id' => $form['farmer_id'],
                    'farm_name' => $form['farm_name'],
                    'location'  => $form['location'],
                    'area'      => $form['area_hectares']
                ]);
                $farm_id = (int) $stmt->fetchColumn();
                
                $successMessage = 'Farm added successfully.';
            }

            //insert selected soil types
            $stmt_soil = $pdo->prepare("INSERT INTO farm_soil (farm_id, soil_id) VALUES (:farm_id, :soil_id)");
            foreach (array_unique($form['soil_ids']) as $soil_id) {
                if ($soil_id > 0) {
                    $stmt_soil->execute(['farm_id' => $farm_id, 'soil_id' => $soil_id]);
                }
            }

            //insert selected irrigation types
            $stmt_irr = $pdo->prepare("INSERT INTO farm_irrigation (farm_id, irrigation_id) VALUES (:farm_id, :irrigation_id)");
            foreach (array_unique($form['irrigation_ids']) as $irr_id) {
                if ($irr_id > 0) {
                    $stmt_irr->execute(['farm_id' => $farm_id, 'irrigation_id' => $irr_id]);
                }
            }

            $pdo->commit();
            flash('success', $successMessage);
            redirect($path);

        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = $e->getMessage() !== 'Exception' && !empty($e->getMessage()) ? $e->getMessage() : 'Unable to save farm record. Please check your inputs and try again.';
        }
    }
}

//lookups for dropdown lists
$farmers_list     = $pdo->query("SELECT farmer_id, first_name, last_name FROM farmer ORDER BY last_name ASC")->fetchAll();
$soil_types       = $pdo->query("SELECT soil_id, soil_name FROM soil_type ORDER BY soil_name ASC")->fetchAll();
$irrigation_types = $pdo->query("SELECT irrigation_id, irrigation_name FROM irrigation_type ORDER BY irrigation_name ASC")->fetchAll();

//search, filter, sort, and pagination configuration
$search           = trim($_GET['search'] ?? '');
$irrigationFilter = (int) ($_GET['irrigation'] ?? 0);
$sort             = $_GET['sort'] ?? 'farm_id';
$order            = strtoupper($_GET['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

$allowedSorts = [
    'farm_id'       => 'f.farm_id',
    'farm_name'     => 'f.farm_name',
    'owner'         => 'fr.last_name',
    'location'      => 'f.location',
    'area_hectares' => 'f.area_hectares'
];
$sortColumn = $allowedSorts[$sort] ?? 'f.farm_id';

$whereClauses = [];
$params = [];

if ($search !== '') {
    $whereClauses[] = "(f.farm_name ILIKE :search OR f.location ILIKE :search OR fr.first_name ILIKE :search OR fr.last_name ILIKE :search)";
    $params['search'] = '%' . $search . '%';
}
if ($irrigationFilter > 0) {
    $whereClauses[] = "EXISTS (SELECT 1 FROM farm_irrigation fi2 WHERE fi2.farm_id = f.farm_id AND fi2.irrigation_id = :irr_id)";
    $params['irr_id'] = $irrigationFilter;
}

$whereSql = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

$perPage = 10;
$page    = max(1, (int) ($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

//calculate total rows for pagination
$stmtCount = $pdo->prepare("
    SELECT COUNT(DISTINCT f.farm_id)
    FROM farm f
    JOIN farmer fr ON f.farmer_id = fr.farmer_id
    $whereSql
");
$stmtCount->execute($params);
$totalRows  = (int) $stmtCount->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $perPage));

//fetch paginated records with aggregated relations
$sql_all = "
    SELECT f.farm_id, f.farm_name, f.location, f.area_hectares,
           fr.first_name, fr.last_name,
           COALESCE(STRING_AGG(DISTINCT st.soil_name, ', '), '-') AS soils,
           COALESCE(STRING_AGG(DISTINCT it.irrigation_name, ', '), '-') AS irrigations
    FROM farm f
    JOIN farmer fr ON f.farmer_id = fr.farmer_id
    LEFT JOIN farm_soil fs ON f.farm_id = fs.farm_id
    LEFT JOIN soil_type st ON fs.soil_id = st.soil_id
    LEFT JOIN farm_irrigation fi ON f.farm_id = fi.farm_id
    LEFT JOIN irrigation_type it ON fi.irrigation_id = it.irrigation_id
    $whereSql
    GROUP BY f.farm_id, fr.first_name, fr.last_name
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
$farms = $stmt_all->fetchAll();

$nextOrder = $order === 'ASC' ? 'DESC' : 'ASC';

//include header view
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Farm Management</h2>
    <?php if (!$showForm): ?>
        <a href="?new=1" class="btn btn-success">+ Add New Farm</a>
    <?php else: ?>
        <a href="farms.php" class="btn btn-secondary">Back to List</a>
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
        <h4 class="mb-3"><?= $editId > 0 ? 'Edit Farm' : 'Register New Farm' ?></h4>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="mode" value="<?= $editId > 0 ? 'update' : 'create' ?>">

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Owner Farmer</label>
                    <select name="farmer_id" class="form-select" required>
                        <option value="">-- Select Farmer --</option>
                        <?php foreach ($farmers_list as $fr): ?>
                            <option value="<?= (int) $fr['farmer_id'] ?>" <?= $form['farmer_id'] == $fr['farmer_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($fr['last_name'] . ', ' . $fr['first_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Farm Name</label>
                    <input type="text" name="farm_name" class="form-control" value="<?= htmlspecialchars($form['farm_name']) ?>" required>
                </div>
            </div>

            <div class="row">
                <div class="col-md-8 mb-3">
                    <label class="form-label">Location / Address</label>
                    <input type="text" name="location" class="form-control" value="<?= htmlspecialchars($form['location']) ?>" required>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Area (Hectares)</label>
                    <input type="number" step="0.01" min="0.01" name="area_hectares" class="form-control" value="<?= htmlspecialchars($form['area_hectares']) ?>" required>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label d-block">Soil Type(s)</label>
                    <?php foreach ($soil_types as $st): ?>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="checkbox" name="soil_ids[]" id="soil_<?= $st['soil_id'] ?>"
                                   value="<?= (int) $st['soil_id'] ?>" <?= in_array($st['soil_id'], $form['soil_ids']) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="soil_<?= $st['soil_id'] ?>">
                                <?= htmlspecialchars($st['soil_name']) ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label d-block">Irrigation Type(s)</label>
                    <?php foreach ($irrigation_types as $it): ?>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="checkbox" name="irrigation_ids[]" id="irr_<?= $it['irrigation_id'] ?>"
                                   value="<?= (int) $it['irrigation_id'] ?>" <?= in_array($it['irrigation_id'], $form['irrigation_ids']) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="irr_<?= $it['irrigation_id'] ?>">
                                <?= htmlspecialchars($it['irrigation_name']) ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <button type="submit" class="btn btn-success"><?= $editId > 0 ? 'Update Farm' : 'Save Farm' ?></button>
            <a href="farms.php" class="btn btn-outline-secondary">Cancel</a>
        </form>
    </div>
<?php else: ?>
    <!-- search & filter bar -->
    <div class="card shadow-sm p-3 mb-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-5">
                <label class="form-label small text-muted mb-1">Search Farm, Location, or Owner</label>
                <input type="text" name="search" class="form-control" placeholder="Search farms..." value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label small text-muted mb-1">Filter by Irrigation Type</label>
                <select name="irrigation" class="form-select">
                    <option value="0">All Irrigation Types</option>
                    <?php foreach ($irrigation_types as $it): ?>
                        <option value="<?= (int) $it['irrigation_id'] ?>" <?= $irrigationFilter === (int) $it['irrigation_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($it['irrigation_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-success w-100">Filter</button>
                <a href="farms.php" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>

    <!-- data table view -->
    <div class="card shadow-sm p-4">
        <div class="table-responsive">
            <table class="table table-striped align-middle">
                <thead>
                    <tr>
                        <th><a href="?sort=farm_id&order=<?= $nextOrder ?>&search=<?= urlencode($search) ?>&irrigation=<?= $irrigationFilter ?>" class="text-decoration-none text-dark">ID</a></th>
                        <th><a href="?sort=farm_name&order=<?= $nextOrder ?>&search=<?= urlencode($search) ?>&irrigation=<?= $irrigationFilter ?>" class="text-decoration-none text-dark">Farm Name</a></th>
                        <th><a href="?sort=owner&order=<?= $nextOrder ?>&search=<?= urlencode($search) ?>&irrigation=<?= $irrigationFilter ?>" class="text-decoration-none text-dark">Owner</a></th>
                        <th><a href="?sort=location&order=<?= $nextOrder ?>&search=<?= urlencode($search) ?>&irrigation=<?= $irrigationFilter ?>" class="text-decoration-none text-dark">Location</a></th>
                        <th><a href="?sort=area_hectares&order=<?= $nextOrder ?>&search=<?= urlencode($search) ?>&irrigation=<?= $irrigationFilter ?>" class="text-decoration-none text-dark">Area (Ha)</a></th>
                        <th>Soil Type</th>
                        <th>Irrigation</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($farms)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted">No farms found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($farms as $f): ?>
                            <tr>
                                <td><?= (int) $f['farm_id'] ?></td>
                                <td><?= htmlspecialchars($f['farm_name']) ?></td>
                                <td><?= htmlspecialchars($f['first_name'] . ' ' . $f['last_name']) ?></td>
                                <td><?= htmlspecialchars($f['location']) ?></td>
                                <td><?= number_format((float) $f['area_hectares'], 2) ?></td>
                                <td><?= htmlspecialchars($f['soils']) ?></td>
                                <td><?= htmlspecialchars($f['irrigations']) ?></td>
                                <td>
                                    <a href="view.php?type=farm&id=<?= (int) $f['farm_id'] ?>" class="btn btn-sm btn-info text-white">View</a>
                                    <a href="?edit=<?= (int) $f['farm_id'] ?>" class="btn btn-sm btn-primary">Edit</a>
                                    <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                            <input type="hidden" name="mode" value="delete">
                                            <input type="hidden" name="id" value="<?= (int) $f['farm_id'] ?>">
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
                            <a class="page-link" href="?page=<?= $p ?>&sort=<?= urlencode($sort) ?>&order=<?= urlencode($order) ?>&search=<?= urlencode($search) ?>&irrigation=<?= $irrigationFilter ?>">
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