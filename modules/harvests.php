<?php
//define base path and load required dependencies
$base = '..';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ui.php';
require_once __DIR__ . '/../config/Database.php';

//ensure user is authenticated and initialize database connection
require_login();
$pdo = Database::getInstance();

$path = 'harvests.php';
$errors = [];
$form = [
    'planting_id'          => '',
    'grade_id'             => '',
    'remark_id'            => '',
    'harvest_date'         => date('Y-m-d'),
    'quantity_kg'          => '',
    'selling_price_per_kg' => ''
];

$editId = (int) ($_GET['edit'] ?? 0);
$showForm = isset($_GET['new']) || $editId > 0;

//if editing, fetch existing harvest record details
if ($editId > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $stmt = $pdo->prepare("SELECT * FROM harvest WHERE harvest_id = :id");
    $stmt->execute(['id' => $editId]);
    $harvestData = $stmt->fetch();
    if ($harvestData) {
        $form['planting_id']          = $harvestData['planting_id'];
        $form['grade_id']             = $harvestData['grade_id'];
        $form['remark_id']            = $harvestData['remark_id'];
        $form['harvest_date']         = $harvestData['harvest_date'];
        $form['quantity_kg']          = $harvestData['quantity_kg'];
        $form['selling_price_per_kg'] = $harvestData['selling_price_per_kg'];
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
            $pdo->beginTransaction();

            //fetch planting_id before deleting harvest record
            $stmt_h = $pdo->prepare("SELECT planting_id FROM harvest WHERE harvest_id = :id");
            $stmt_h->execute(['id' => $id]);
            $planting_id = $stmt_h->fetchColumn();

            //delete the harvest record
            $stmt = $pdo->prepare("DELETE FROM harvest WHERE harvest_id = :id");
            $stmt->execute(['id' => $id]);

            if ($planting_id) {
                //check if any other harvest records still remain for this planting
                $checkRemain = $pdo->prepare("SELECT COUNT(*) FROM harvest WHERE planting_id = :p_id");
                $checkRemain->execute(['p_id' => $planting_id]);
                $remainingHarvests = (int) $checkRemain->fetchColumn();

                //if no other harvests remain, revert planting status back to 'Growing'
                if ($remainingHarvests === 0) {
                    $statusStmt = $pdo->prepare("SELECT status_id FROM planting_status WHERE LOWER(status_name) = 'growing'");
                    $statusStmt->execute();
                    $growingStatusId = $statusStmt->fetchColumn();

                    if ($growingStatusId) {
                        $pdo->prepare("UPDATE planting SET status_id = :s_id, updated_at = CURRENT_TIMESTAMP WHERE planting_id = :p_id")
                            ->execute(['s_id' => $growingStatusId, 'p_id' => $planting_id]);
                    }
                }
            }

            $pdo->commit();
            flash('success', 'Harvest record deleted successfully.');
            redirect($path);
        } catch (Exception $e) {
            $pdo->rollBack();
            flash('danger', 'Failed to delete harvest record.');
            redirect($path);
        }
    }

    //sanitize incoming inputs
    $form['planting_id']          = (int) ($_POST['planting_id'] ?? 0);
    $form['grade_id']             = (int) ($_POST['grade_id'] ?? 0);
    $form['remark_id']            = (int) ($_POST['remark_id'] ?? 0);
    $form['harvest_date']         = trim($_POST['harvest_date'] ?? '');
    $form['quantity_kg']          = trim($_POST['quantity_kg'] ?? '');
    $form['selling_price_per_kg'] = trim($_POST['selling_price_per_kg'] ?? '');

    //explicit existence check for planting_id
    if ($form['planting_id'] <= 0) {
        $errors[] = 'Planting record reference is required.';
    } else {
        $stmtCheckPlanting = $pdo->prepare("SELECT COUNT(*) FROM planting WHERE planting_id = :id");
        $stmtCheckPlanting->execute(['id' => $form['planting_id']]);
        if ((int) $stmtCheckPlanting->fetchColumn() === 0) {
            $errors[] = 'Selected planting record does not exist.';
        }
    }

    //explicit existence check for grade_id
    if ($form['grade_id'] <= 0) {
        $errors[] = 'Quality grade is required.';
    } else {
        $stmtCheckGrade = $pdo->prepare("SELECT COUNT(*) FROM quality_grade WHERE grade_id = :id");
        $stmtCheckGrade->execute(['id' => $form['grade_id']]);
        if ((int) $stmtCheckGrade->fetchColumn() === 0) {
            $errors[] = 'Selected quality grade does not exist.';
        }
    }

    //explicit existence check for remark_id
    if ($form['remark_id'] <= 0) {
        $errors[] = 'Harvest remark is required.';
    } else {
        $stmtCheckRemark = $pdo->prepare("SELECT COUNT(*) FROM harvest_remark WHERE remark_id = :id");
        $stmtCheckRemark->execute(['id' => $form['remark_id']]);
        if ((int) $stmtCheckRemark->fetchColumn() === 0) {
            $errors[] = 'Selected harvest remark does not exist.';
        }
    }

    //validate harvest quantity: strictly greater than 0
    if (!is_numeric($form['quantity_kg']) || (float) $form['quantity_kg'] <= 0) {
        $errors[] = 'Harvest quantity (kg) must be a positive number greater than zero.';
    }

    //validate selling price per kg: non-negative
    if (!is_numeric($form['selling_price_per_kg']) || (float) $form['selling_price_per_kg'] < 0) {
        $errors[] = 'Selling price per kg must be a valid non-negative number.';
    }

    //validate harvest date and prevent future dates
    if (empty($form['harvest_date']) || !strtotime($form['harvest_date'])) {
        $errors[] = 'A valid harvest date is required.';
    } elseif (strtotime($form['harvest_date']) > time()) {
        $errors[] = 'Harvest date cannot be set in the future.';
    } elseif ($form['planting_id'] > 0) {
        $stmtPDate = $pdo->prepare("SELECT planting_date FROM planting WHERE planting_id = :id");
        $stmtPDate->execute(['id' => $form['planting_id']]);
        $pDate = $stmtPDate->fetchColumn();
        if ($pDate && strtotime($form['harvest_date']) < strtotime($pDate)) {
            $errors[] = 'Harvest date cannot be earlier than the planting date (' . $pDate . ').';
        }
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            //determine appropriate planting status based on harvest remark description
            $remarkQuery = $pdo->prepare("SELECT remark_description FROM harvest_remark WHERE remark_id = :r_id");
            $remarkQuery->execute(['r_id' => $form['remark_id']]);
            $remarkDesc = strtolower($remarkQuery->fetchColumn() ?? '');

            //choose status name based on remark text (supports damaged vs harvested)
            $targetStatusName = (str_contains($remarkDesc, 'damaged') || str_contains($remarkDesc, 'poor')) ? 'Damaged' : 'Harvested';

            $statusFetch = $pdo->prepare("SELECT status_id FROM planting_status WHERE LOWER(status_name) = :s_name");
            $statusFetch->execute(['s_name' => strtolower($targetStatusName)]);
            $targetStatusId = $statusFetch->fetchColumn();

            if ($mode === 'update' && $editId > 0) {
                //fetch old planting id before update to handle changes if planting reference was modified
                $oldPlantingQuery = $pdo->prepare("SELECT planting_id FROM harvest WHERE harvest_id = :id");
                $oldPlantingQuery->execute(['id' => $editId]);
                $oldPlantingId = $oldPlantingQuery->fetchColumn();

                $sql = "UPDATE harvest
                        SET planting_id = :p_id,
                            grade_id = :grade_id,
                            remark_id = :remark_id,
                            harvest_date = :h_date,
                            quantity_kg = :qty,
                            selling_price_per_kg = :price,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE harvest_id = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    'p_id'      => $form['planting_id'],
                    'grade_id'  => $form['grade_id'],
                    'remark_id' => $form['remark_id'],
                    'h_date'    => $form['harvest_date'],
                    'qty'       => $form['quantity_kg'],
                    'price'     => $form['selling_price_per_kg'],
                    'id'        => $editId
                ]);

                if ($stmt->rowCount() === 0) {
                    throw new Exception('Harvest record not found or no changes were made.');
                }

                //update status for the newly assigned planting
                if ($targetStatusId) {
                    $pdo->prepare("UPDATE planting SET status_id = :s_id, updated_at = CURRENT_TIMESTAMP WHERE planting_id = :p_id")
                        ->execute(['s_id' => $targetStatusId, 'p_id' => $form['planting_id']]);
                }

                //if planting reference was changed, check if old planting needs to revert back to growing
                if ($oldPlantingId && (int)$oldPlantingId !== (int)$form['planting_id']) {
                    $checkOldRemain = $pdo->prepare("SELECT COUNT(*) FROM harvest WHERE planting_id = :p_id");
                    $checkOldRemain->execute(['p_id' => $oldPlantingId]);
                    if ((int)$checkOldRemain->fetchColumn() === 0) {
                        $growingFetch = $pdo->prepare("SELECT status_id FROM planting_status WHERE LOWER(status_name) = 'growing'");
                        $growingFetch->execute();
                        $growingId = $growingFetch->fetchColumn();
                        if ($growingId) {
                            $pdo->prepare("UPDATE planting SET status_id = :s_id, updated_at = CURRENT_TIMESTAMP WHERE planting_id = :p_id")
                                ->execute(['s_id' => $growingId, 'p_id' => $oldPlantingId]);
                        }
                    }
                }
            } else {
                $sql = "INSERT INTO harvest (planting_id, grade_id, remark_id, harvest_date, quantity_kg, selling_price_per_kg)
                        VALUES (:p_id, :grade_id, :remark_id, :h_date, :qty, :price)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    'p_id'      => $form['planting_id'],
                    'grade_id'  => $form['grade_id'],
                    'remark_id' => $form['remark_id'],
                    'h_date'    => $form['harvest_date'],
                    'qty'       => $form['quantity_kg'],
                    'price'     => $form['selling_price_per_kg']
                ]);

                //update linked planting status based on remark evaluation
                if ($targetStatusId) {
                    $pdo->prepare("UPDATE planting SET status_id = :s_id, updated_at = CURRENT_TIMESTAMP WHERE planting_id = :p_id")
                        ->execute(['s_id' => $targetStatusId, 'p_id' => $form['planting_id']]);
                }
            }

            $pdo->commit();
            $msg = ($mode === 'update' && $editId > 0) ? 'Harvest record updated successfully.' : 'Harvest record added successfully.';
            flash('success', $msg);
            redirect($path);
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = $e->getMessage() !== 'Exception' && !empty($e->getMessage()) ? $e->getMessage() : 'Unable to save harvest record.';
        }
    }
}

//lookups for dropdown lists
$plantings_list = $pdo->query("
    SELECT p.planting_id, f.farm_name, c.crop_name, c.variety, p.planting_date
    FROM planting p
    JOIN farm f ON p.farm_id = f.farm_id
    JOIN crop c ON p.crop_id = c.crop_id
    ORDER BY p.planting_id DESC
")->fetchAll();

$grades_list  = $pdo->query("SELECT grade_id, grade_name FROM quality_grade ORDER BY grade_id ASC")->fetchAll();
$remarks_list = $pdo->query("SELECT remark_id, remark_description FROM harvest_remark ORDER BY remark_id ASC")->fetchAll();

//search, filter, sort, and pagination setup
$search      = trim($_GET['search'] ?? '');
$gradeFilter = (int) ($_GET['grade'] ?? 0);
$sort        = $_GET['sort'] ?? 'harvest_id';
$order       = strtoupper($_GET['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

$allowedSorts = [
    'harvest_id'           => 'h.harvest_id',
    'farm_name'            => 'f.farm_name',
    'crop_name'            => 'c.crop_name',
    'harvest_date'         => 'h.harvest_date',
    'quantity_kg'          => 'h.quantity_kg',
    'selling_price_per_kg' => 'h.selling_price_per_kg',
    'grade_name'           => 'qg.grade_name'
];
$sortColumn = $allowedSorts[$sort] ?? 'h.harvest_id';

$whereClauses = [];
$params = [];

if ($search !== '') {
    $whereClauses[] = "(f.farm_name ILIKE :search OR c.crop_name ILIKE :search OR hr.remark_description ILIKE :search)";
    $params['search'] = '%' . $search . '%';
}
if ($gradeFilter > 0) {
    $whereClauses[] = "h.grade_id = :grade_id";
    $params['grade_id'] = $gradeFilter;
}

$whereSql = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

$perPage = 10;
$page    = max(1, (int) ($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

//calculate total rows for pagination
$stmtCount = $pdo->prepare("
    SELECT COUNT(*)
    FROM harvest h
    JOIN planting p ON h.planting_id = p.planting_id
    JOIN farm f ON p.farm_id = f.farm_id
    JOIN crop c ON p.crop_id = c.crop_id
    JOIN quality_grade qg ON h.grade_id = qg.grade_id
    JOIN harvest_remark hr ON h.remark_id = hr.remark_id
    $whereSql
");
$stmtCount->execute($params);
$totalRows  = (int) $stmtCount->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $perPage));

//fetch paginated harvest records
$sql_all = "
    SELECT h.harvest_id, h.harvest_date, h.quantity_kg, h.selling_price_per_kg,
           (h.quantity_kg * h.selling_price_per_kg) AS estimated_value,
           f.farm_name, c.crop_name, c.variety,
           qg.grade_name, hr.remark_description
    FROM harvest h
    JOIN planting p ON h.planting_id = p.planting_id
    JOIN farm f ON p.farm_id = f.farm_id
    JOIN crop c ON p.crop_id = c.crop_id
    JOIN quality_grade qg ON h.grade_id = qg.grade_id
    JOIN harvest_remark hr ON h.remark_id = hr.remark_id
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
$harvests = $stmt_all->fetchAll();

$nextOrder = $order === 'ASC' ? 'DESC' : 'ASC';

//include header view
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Harvest Management</h2>
    <?php if (!$showForm): ?>
        <a href="?new=1" class="btn btn-success">+ Add New Harvest</a>
    <?php else: ?>
        <a href="harvests.php" class="btn btn-secondary">Back to List</a>
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
        <h4 class="mb-3"><?= $editId > 0 ? 'Edit Harvest' : 'Record New Harvest' ?></h4>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="mode" value="<?= $editId > 0 ? 'update' : 'create' ?>">

            <div class="mb-3">
                <label class="form-label">Planting Reference (Farm & Crop)</label>
                <select name="planting_id" class="form-select" required>
                    <option value="">-- Select Planting Record --</option>
                    <?php foreach ($plantings_list as $p): ?>
                        <option value="<?= (int) $p['planting_id'] ?>" <?= $form['planting_id'] == $p['planting_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars('ID: ' . $p['planting_id'] . ' - ' . $p['farm_name'] . ' (' . $p['crop_name'] . ' - ' . $p['variety'] . ', planted ' . $p['planting_date'] . ')') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label">Harvest Date</label>
                    <input type="date" name="harvest_date" class="form-control" value="<?= htmlspecialchars($form['harvest_date']) ?>" max="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Quantity (kg)</label>
                    <input type="number" step="0.01" min="0.01" name="quantity_kg" class="form-control" value="<?= htmlspecialchars($form['quantity_kg']) ?>" required>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Selling Price per kg (PHP)</label>
                    <input type="number" step="0.01" min="0" name="selling_price_per_kg" class="form-control" value="<?= htmlspecialchars($form['selling_price_per_kg']) ?>" required>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Quality Grade</label>
                    <select name="grade_id" class="form-select" required>
                        <option value="">-- Select Quality Grade --</option>
                        <?php foreach ($grades_list as $g): ?>
                            <option value="<?= (int) $g['grade_id'] ?>" <?= $form['grade_id'] == $g['grade_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($g['grade_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Harvest Remarks</label>
                    <select name="remark_id" class="form-select" required>
                        <option value="">-- Select Remark --</option>
                        <?php foreach ($remarks_list as $rm): ?>
                            <option value="<?= (int) $rm['remark_id'] ?>" <?= $form['remark_id'] == $rm['remark_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($rm['remark_description']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <button type="submit" class="btn btn-success"><?= $editId > 0 ? 'Update Harvest' : 'Save Harvest' ?></button>
            <a href="harvests.php" class="btn btn-outline-secondary">Cancel</a>
        </form>
    </div>
<?php else: ?>
    <!-- search & filter bar -->
    <div class="card shadow-sm p-3 mb-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-5">
                <label class="form-label small text-muted mb-1">Search Farm, Crop, or Remark</label>
                <input type="text" name="search" class="form-control" placeholder="Search harvests..." value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label small text-muted mb-1">Filter by Quality Grade</label>
                <select name="grade" class="form-select">
                    <option value="0">All Grades</option>
                    <?php foreach ($grades_list as $g): ?>
                        <option value="<?= (int) $g['grade_id'] ?>" <?= $gradeFilter === (int) $g['grade_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($g['grade_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-success w-100">Filter</button>
                <a href="harvests.php" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>

    <!-- data table view -->
    <div class="card shadow-sm p-4">
        <div class="table-responsive">
            <table class="table table-striped align-middle">
                <thead>
                    <tr>
                        <th><a href="?sort=harvest_id&order=<?= $nextOrder ?>&search=<?= urlencode($search) ?>&grade=<?= $gradeFilter ?>" class="text-decoration-none text-dark">ID</a></th>
                        <th><a href="?sort=farm_name&order=<?= $nextOrder ?>&search=<?= urlencode($search) ?>&grade=<?= $gradeFilter ?>" class="text-decoration-none text-dark">Farm</a></th>
                        <th><a href="?sort=crop_name&order=<?= $nextOrder ?>&search=<?= urlencode($search) ?>&grade=<?= $gradeFilter ?>" class="text-decoration-none text-dark">Crop</a></th>
                        <th><a href="?sort=harvest_date&order=<?= $nextOrder ?>&search=<?= urlencode($search) ?>&grade=<?= $gradeFilter ?>" class="text-decoration-none text-dark">Harvest Date</a></th>
                        <th><a href="?sort=quantity_kg&order=<?= $nextOrder ?>&search=<?= urlencode($search) ?>&grade=<?= $gradeFilter ?>" class="text-decoration-none text-dark">Quantity (kg)</a></th>
                        <th><a href="?sort=selling_price_per_kg&order=<?= $nextOrder ?>&search=<?= urlencode($search) ?>&grade=<?= $gradeFilter ?>" class="text-decoration-none text-dark">Price/kg</a></th>
                        <th>Est. Value</th>
                        <th><a href="?sort=grade_name&order=<?= $nextOrder ?>&search=<?= urlencode($search) ?>&grade=<?= $gradeFilter ?>" class="text-decoration-none text-dark">Grade</a></th>
                        <th>Remarks</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($harvests)): ?>
                        <tr>
                            <td colspan="10" class="text-center text-muted">No harvest records found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($harvests as $h): ?>
                            <tr>
                                <td><?= (int) $h['harvest_id'] ?></td>
                                <td><?= htmlspecialchars($h['farm_name']) ?></td>
                                <td><?= htmlspecialchars($h['crop_name'] . ' (' . $h['variety'] . ')') ?></td>
                                <td><?= htmlspecialchars($h['harvest_date']) ?></td>
                                <td><?= number_format((float) $h['quantity_kg'], 2) ?> kg</td>
                                <td>PHP <?= number_format((float) $h['selling_price_per_kg'], 2) ?></td>
                                <td>PHP <?= number_format((float) $h['estimated_value'], 2) ?></td>
                                <td><span class="badge bg-info text-dark"><?= htmlspecialchars($h['grade_name']) ?></span></td>
                                <td><?= htmlspecialchars($h['remark_description']) ?></td>
                                <td>
                                    <a href="view.php?type=harvest&id=<?= (int) $h['harvest_id'] ?>" class="btn btn-sm btn-info text-white">View</a>
                                    <a href="?edit=<?= (int) $h['harvest_id'] ?>" class="btn btn-sm btn-primary">Edit</a>
                                    <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                            <input type="hidden" name="mode" value="delete">
                                            <input type="hidden" name="id" value="<?= (int) $h['harvest_id'] ?>">
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
                            <a class="page-link" href="?page=<?= $p ?>&sort=<?= urlencode($sort) ?>&order=<?= urlencode($order) ?>&search=<?= urlencode($search) ?>&grade=<?= $gradeFilter ?>">
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