<?php
//define relative base path and load required files
$base = '..';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ui.php';
require_once __DIR__ . '/../config/Database.php';

//ensure user is authenticated and initialize database instance
require_login();
$pdo = Database::getInstance();

//fixed absolute path to relative filename to support deployment under subfolders like /agrisys/
$path = 'crops.php';
$errors = [];
$form = [
    'category_id'         => '',
    'crop_name'           => '',
    'variety'             => '',
    'growing_period_days' => '',
    'season_ids'          => []
];

$editId = (int) ($_GET['edit'] ?? 0);
$showForm = isset($_GET['new']) || $editId > 0;

//if editing, fetch existing crop and associative season selections
if ($editId > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $stmt = $pdo->prepare("SELECT * FROM crop WHERE crop_id = :id");
    $stmt->execute(['id' => $editId]);
    $cropData = $stmt->fetch();
    if ($cropData) {
        $form['category_id']         = $cropData['category_id'];
        $form['crop_name']           = $cropData['crop_name'];
        $form['variety']             = $cropData['variety'];
        $form['growing_period_days'] = $cropData['growing_period_days'];

        //fetch associated seasons for this crop
        $stmt_s = $pdo->prepare("SELECT season_id FROM crop_season WHERE crop_id = :id");
        $stmt_s->execute(['id' => $editId]);
        $form['season_ids'] = $stmt_s->fetchAll(PDO::FETCH_COLUMN);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $mode = $_POST['mode'] ?? 'create';

    //handle delete operation (admin only)
    if ($mode === 'delete') {
        require_admin();
        $id = (int) ($_POST['id'] ?? 0);

        //prevent deletion if crop is linked to existing plantings
        $stmt_c = $pdo->prepare("SELECT COUNT(*) FROM planting WHERE crop_id = :id");
        $stmt_c->execute(['id' => $id]);
        if ((int) $stmt_c->fetchColumn() > 0) {
            flash('danger', 'Cannot delete crop because it is linked to existing plantings.');
            redirect($path);
        }

        $stmt = $pdo->prepare("DELETE FROM crop WHERE crop_id = :id");
        $stmt->execute(['id' => $id]);

        flash('success', 'Crop deleted successfully.');
        redirect($path);
    }

    //sanitize incoming form inputs
    $form['category_id']         = (int) ($_POST['category_id'] ?? 0);
    $form['crop_name']           = trim($_POST['crop_name'] ?? '');
    $form['variety']             = trim($_POST['variety'] ?? '');
    $form['growing_period_days'] = trim($_POST['growing_period_days'] ?? '');
    
    //check and ensure season ids is an array
    $rawSeasons = $_POST['season_ids'] ?? [];
    $form['season_ids']          = is_array($rawSeasons) ? array_map('intval', $rawSeasons) : [];

    //validate required fields and constraints
    if ($form['category_id'] <= 0) {
        $errors[] = 'Crop category is required.';
    } else {
        //explicit existence check for category_id (fixes validation gap)
        $stmtCheckCat = $pdo->prepare("SELECT COUNT(*) FROM crop_category WHERE category_id = :id");
        $stmtCheckCat->execute(['id' => $form['category_id']]);
        if ((int) $stmtCheckCat->fetchColumn() === 0) {
            $errors[] = 'Selected crop category does not exist.';
        }
    }

    if (empty($form['crop_name'])) $errors[] = 'Crop name is required.';
    if (empty($form['variety'])) $errors[] = 'Variety is required.';
    if (!ctype_digit((string) $form['growing_period_days']) || (int) $form['growing_period_days'] <= 0) {
        $errors[] = 'Growing period (days) must be a positive whole number.';
    }
    if (empty($form['season_ids'])) $errors[] = 'Select at least one recommended season.';

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            if ($mode === 'update' && $editId > 0) {
                //update existing crop record
                $sql = "UPDATE crop
                        SET category_id = :category_id,
                            crop_name = :crop_name,
                            variety = :variety,
                            growing_period_days = :growing_period_days,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE crop_id = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    'category_id'         => $form['category_id'],
                    'crop_name'           => $form['crop_name'],
                    'variety'             => $form['variety'],
                    'growing_period_days' => (int) $form['growing_period_days'],
                    'id'                  => $editId
                ]);

                //check rowCount() to ensure valid record update
                if ($stmt->rowCount() === 0) {
                    throw new Exception('Crop record not found or no changes were made.');
                }

                $crop_id = $editId;

                //clear old season mappings before re-inserting
                $pdo->prepare("DELETE FROM crop_season WHERE crop_id = :id")->execute(['id' => $crop_id]);
            } else {
                //insert new crop record and return its new ID
                $sql = "INSERT INTO crop (category_id, crop_name, variety, growing_period_days)
                        VALUES (:category_id, :crop_name, :variety, :growing_period_days)
                        RETURNING crop_id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    'category_id'         => $form['category_id'],
                    'crop_name'           => $form['crop_name'],
                    'variety'             => $form['variety'],
                    'growing_period_days' => (int) $form['growing_period_days']
                ]);
                $crop_id = (int) $stmt->fetchColumn();
            }

            //insert selected recommended seasons
            $stmt_season = $pdo->prepare("INSERT INTO crop_season (crop_id, season_id) VALUES (:crop_id, :season_id)");
            foreach (array_unique($form['season_ids']) as $season_id) {
                if ($season_id > 0) {
                    $stmt_season->execute(['crop_id' => $crop_id, 'season_id' => $season_id]);
                }
            }

           $pdo->commit();
            if ($mode === 'update' && $editId > 0) {
                flash('success', 'Crop updated successfully.');
            } else {
                flash('success', 'Crop added successfully.');
            }
            redirect($path);
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = $e->getMessage() !== 'Exception' && !empty($e->getMessage()) ? $e->getMessage() : 'Unable to save crop record. Please verify your inputs and try again.';
        }
    }
}

//lookups for dropdowns
$categories = $pdo->query("SELECT category_id, category_name FROM crop_category ORDER BY category_name ASC")->fetchAll();
$seasons    = $pdo->query("SELECT season_id, season_name FROM season ORDER BY season_id ASC")->fetchAll();

//search, filter, sort, and pagination setup
$search         = trim($_GET['search'] ?? '');
$categoryFilter = (int) ($_GET['category'] ?? 0);
$sort           = $_GET['sort'] ?? 'crop_name';
$order          = strtoupper($_GET['order'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';

$allowedSorts = [
    'crop_id'             => 'c.crop_id',
    'crop_name'           => 'c.crop_name',
    'variety'             => 'c.variety',
    'category_name'       => 'cc.category_name',
    'growing_period_days' => 'c.growing_period_days'
];
$sortColumn = $allowedSorts[$sort] ?? 'c.crop_name';

$whereClauses = [];
$params = [];

if ($search !== '') {
    $whereClauses[] = "(c.crop_name ILIKE :search OR c.variety ILIKE :search)";
    $params['search'] = '%' . $search . '%';
}
if ($categoryFilter > 0) {
    $whereClauses[] = "c.category_id = :category_id";
    $params['category_id'] = $categoryFilter;
}

$whereSql = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

$perPage = 10;
$page    = max(1, (int) ($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

//calculate total rows for pagination
$stmtCount = $pdo->prepare("
    SELECT COUNT(DISTINCT c.crop_id)
    FROM crop c
    JOIN crop_category cc ON c.category_id = cc.category_id
    $whereSql
");
$stmtCount->execute($params);
$totalRows  = (int) $stmtCount->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $perPage));

//fetch paginated crop records with aggregated seasons
$sql_all = "
    SELECT c.crop_id, c.crop_name, c.variety, c.growing_period_days,
           cc.category_name,
           COALESCE(STRING_AGG(DISTINCT s.season_name, ', ' ORDER BY s.season_name), '-') AS seasons
    FROM crop c
    JOIN crop_category cc ON c.category_id = cc.category_id
    LEFT JOIN crop_season cs ON c.crop_id = cs.crop_id
    LEFT JOIN season s ON cs.season_id = s.season_id
    $whereSql
    GROUP BY c.crop_id, cc.category_name
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
$crops = $stmt_all->fetchAll();

$nextOrder = $order === 'ASC' ? 'DESC' : 'ASC';

//include header view
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Crop Management</h2>
    <?php if (!$showForm): ?>
        <a href="?new=1" class="btn btn-success">+ Add New Crop</a>
    <?php else: ?>
        <a href="crops.php" class="btn btn-secondary">Back to List</a>
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
        <h4 class="mb-3"><?= $editId > 0 ? 'Edit Crop' : 'Register New Crop' ?></h4>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="mode" value="<?= $editId > 0 ? 'update' : 'create' ?>">

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Crop Name</label>
                    <input type="text" name="crop_name" class="form-control" value="<?= htmlspecialchars($form['crop_name']) ?>" placeholder="e.g. Rice, Corn, Tomato" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Variety</label>
                    <input type="text" name="variety" class="form-control" value="<?= htmlspecialchars($form['variety']) ?>" placeholder="e.g. NSIC Rc 222, Yellow Corn" required>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Category</label>
                    <select name="category_id" class="form-select" required>
                        <option value="">-- Select Category --</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= (int) $cat['category_id'] ?>" <?= $form['category_id'] == $cat['category_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat['category_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Growing Period (Days)</label>
                    <input type="number" min="1" name="growing_period_days" class="form-control" value="<?= htmlspecialchars($form['growing_period_days']) ?>" required>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label d-block">Recommended Season(s)</label>
                <?php foreach ($seasons as $s): ?>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" name="season_ids[]" id="season_<?= $s['season_id'] ?>"
                               value="<?= (int) $s['season_id'] ?>" <?= in_array($s['season_id'], $form['season_ids']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="season_<?= $s['season_id'] ?>">
                            <?= htmlspecialchars($s['season_name']) ?>
                        </label>
                    </div>
                <?php endforeach; ?>
            </div>

            <button type="submit" class="btn btn-success"><?= $editId > 0 ? 'Update Crop' : 'Save Crop' ?></button>
            <a href="crops.php" class="btn btn-outline-secondary">Cancel</a>
        </form>
    </div>
<?php else: ?>
    <!-- search & filter bar -->
    <div class="card shadow-sm p-3 mb-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-5">
                <label class="form-label small text-muted mb-1">Search Crop Name or Variety</label>
                <input type="text" name="search" class="form-control" placeholder="Search crops..." value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label small text-muted mb-1">Filter by Category</label>
                <select name="category" class="form-select">
                    <option value="0">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= (int) $cat['category_id'] ?>" <?= $categoryFilter === (int) $cat['category_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat['category_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-success w-100">Filter</button>
                <a href="crops.php" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>

    <!-- data table view -->
    <div class="card shadow-sm p-4">
        <div class="table-responsive">
            <table class="table table-striped align-middle">
                <thead>
                    <tr>
                        <th><a href="?sort=crop_id&order=<?= $nextOrder ?>&search=<?= urlencode($search) ?>&category=<?= $categoryFilter ?>" class="text-decoration-none text-dark">ID</a></th>
                        <th><a href="?sort=crop_name&order=<?= $nextOrder ?>&search=<?= urlencode($search) ?>&category=<?= $categoryFilter ?>" class="text-decoration-none text-dark">Crop Name</a></th>
                        <th><a href="?sort=variety&order=<?= $nextOrder ?>&search=<?= urlencode($search) ?>&category=<?= $categoryFilter ?>" class="text-decoration-none text-dark">Variety</a></th>
                        <th><a href="?sort=category_name&order=<?= $nextOrder ?>&search=<?= urlencode($search) ?>&category=<?= $categoryFilter ?>" class="text-decoration-none text-dark">Category</a></th>
                        <th><a href="?sort=growing_period_days&order=<?= $nextOrder ?>&search=<?= urlencode($search) ?>&category=<?= $categoryFilter ?>" class="text-decoration-none text-dark">Growing Period</a></th>
                        <th>Recommended Season</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($crops)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted">No crops found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($crops as $c): ?>
                            <tr>
                                <td><?= (int) $c['crop_id'] ?></td>
                                <td><?= htmlspecialchars($c['crop_name']) ?></td>
                                <td><?= htmlspecialchars($c['variety']) ?></td>
                                <td><?= htmlspecialchars($c['category_name']) ?></td>
                                <td><?= (int) $c['growing_period_days'] ?> days</td>
                                <td><?= htmlspecialchars($c['seasons']) ?></td>
                                <td>
                                    <a href="view.php?type=crop&id=<?= (int) $c['crop_id'] ?>" class="btn btn-sm btn-info text-white">View</a>
                                    <a href="?edit=<?= (int) $c['crop_id'] ?>" class="btn btn-sm btn-primary">Edit</a>
                                    <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                            <input type="hidden" name="mode" value="delete">
                                            <input type="hidden" name="id" value="<?= (int) $c['crop_id'] ?>">
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
                            <a class="page-link" href="?page=<?= $p ?>&sort=<?= urlencode($sort) ?>&order=<?= urlencode($order) ?>&search=<?= urlencode($search) ?>&category=<?= $categoryFilter ?>">
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