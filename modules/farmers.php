<?php
//define relative base path and load required files
$base = '..';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ui.php';
require_once __DIR__ . '/../config/Database.php';

//ensure user is authenticated and initialize database instance
require_login();
$pdo = Database::getInstance();

$path = 'farmers.php';
$errors = [];
$form = [
    'first_name'      => '',
    'last_name'       => '',
    'address'         => '',
    'date_registered' => date('Y-m-d'),
    'contacts'        => ['']
];

$editId = (int) ($_GET['edit'] ?? 0);
$showForm = isset($_GET['new']) || $editId > 0;

//if editing, fetch existing data to populate form fields
if ($editId > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $stmt = $pdo->prepare("SELECT * FROM farmer WHERE farmer_id = :id");
    $stmt->execute(['id' => $editId]);
    $farmerData = $stmt->fetch();
    if ($farmerData) {
        $form['first_name']      = $farmerData['first_name'];
        $form['last_name']       = $farmerData['last_name'];
        $form['address']         = $farmerData['address'];
        $form['date_registered'] = $farmerData['date_registered'];

        // fetch associated contact numbers for this farmer
        $stmt_c = $pdo->prepare("SELECT contact_number FROM farmer_contact WHERE farmer_id = :id ORDER BY contact_id ASC");
        $stmt_c->execute(['id' => $editId]);
        $contacts = $stmt_c->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($contacts)) {
            $form['contacts'] = $contacts;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $mode = $_POST['mode'] ?? 'create';

    //handle delete operation (admin only)
    if ($mode === 'delete') {
        require_admin();
        $id = (int) ($_POST['id'] ?? 0);

        $stmt = $pdo->prepare("SELECT first_name, last_name FROM farmer WHERE farmer_id = :id");
        $stmt->execute(['id' => $id]);
        $farmer = $stmt->fetch();

        if (!$farmer) {
            flash('danger', 'That farmer no longer exists.');
            redirect($path);
        }

        //prevent deletion if farmer has registered farms linked
        $stmt_farm = $pdo->prepare("SELECT COUNT(*) FROM farm WHERE farmer_id = :id");
        $stmt_farm->execute(['id' => $id]);
        $farmCount = (int) $stmt_farm->fetchColumn();

        if ($farmCount > 0) {
            flash('danger', 'Cannot delete ' . $farmer['first_name'] . ' ' . $farmer['last_name'] . ' because they have registered farms.');
            redirect($path);
        }

        $stmt_del = $pdo->prepare("DELETE FROM farmer WHERE farmer_id = :id");
        $stmt_del->execute(['id' => $id]);

        flash('success', 'Farmer deleted successfully.');
        redirect($path);
    }

    //sanitize incoming form inputs
    $form['first_name']      = trim($_POST['first_name'] ?? '');
    $form['last_name']       = trim($_POST['last_name'] ?? '');
    $form['address']         = trim($_POST['address'] ?? '');
    $form['date_registered'] = trim($_POST['date_registered'] ?? date('Y-m-d'));
    
    //ensure contacts is an array
    $rawContacts = $_POST['contacts'] ?? [''];
    $form['contacts']        = is_array($rawContacts) ? $rawContacts : [''];

    //validate required fields
    if (empty($form['first_name'])) $errors[] = 'First name is required.';
    if (empty($form['last_name'])) $errors[] = 'Last name is required.';
    if (empty($form['address'])) $errors[] = 'Address is required.';
    
    //validate registration date and prevent future dates
    if (empty($form['date_registered']) || !strtotime($form['date_registered'])) {
        $errors[] = 'A valid registration date is required.';
    } elseif (strtotime($form['date_registered']) > time()) {
        $errors[] = 'Registration date cannot be set in the future.';
    }

    //validate contact numbers format
    foreach ($form['contacts'] as $contact_number) {
        $contact_number = trim($contact_number);
        if (!empty($contact_number)) {
            if (!preg_match('/^[0-9\+\-\s\(\)]+$/', $contact_number) || strlen(str_replace(['+','-',' ','(',')'], '', $contact_number)) < 7) {
                $errors[] = 'Contact number "' . htmlspecialchars($contact_number) . '" is invalid. Please enter a valid phone number.';
            }
        }
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            if ($mode === 'update' && $editId > 0) {
                //update existing farmer record
                $sql = "UPDATE farmer
                        SET first_name = :first_name,
                            last_name = :last_name,
                            address = :address,
                            date_registered = :date_registered,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE farmer_id = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    'first_name'      => $form['first_name'],
                    'last_name'       => $form['last_name'],
                    'address'         => $form['address'],
                    'date_registered' => $form['date_registered'],
                    'id'              => $editId
                ]);

                if ($stmt->rowCount() === 0) {
                    throw new Exception('Farmer record not found or no changes were made.');
                }

                $farmer_id = $editId;

                //remove old contacts before re-inserting submitted contacts
                $stmt_del_c = $pdo->prepare("DELETE FROM farmer_contact WHERE farmer_id = :id");
                $stmt_del_c->execute(['id' => $farmer_id]);
            } else {
                //insert new farmer record and return new id
                $sql = "INSERT INTO farmer (first_name, last_name, address, date_registered)
                        VALUES (:first_name, :last_name, :address, :date_registered)
                        RETURNING farmer_id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    'first_name'      => $form['first_name'],
                    'last_name'       => $form['last_name'],
                    'address'         => $form['address'],
                    'date_registered' => $form['date_registered']
                ]);
                $result = $stmt->fetch();
                $farmer_id = $result['farmer_id'];
            }

            //loop and insert contact numbers safely
            foreach ($form['contacts'] as $contact_number) {
                $contact_number = trim($contact_number);
                if (!empty($contact_number)) {
                    $stmt_contact = $pdo->prepare("INSERT INTO farmer_contact (farmer_id, contact_number) VALUES (:farmer_id, :contact_number)");
                    $stmt_contact->execute([
                        'farmer_id'      => $farmer_id,
                        'contact_number' => $contact_number
                    ]);
                }
            }

           $pdo->commit();
            if ($mode === 'update' && $editId > 0) {
                flash('success', 'Farmer updated successfully.');
            } else {
                flash('success', 'Farmer added successfully.');
            }
            redirect($path);

        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = $e->getMessage() !== 'Exception' && !empty($e->getMessage()) ? $e->getMessage() : 'Unable to save farmer record. Please verify your inputs and try again.';
        }
    }
}

//lookups, search, filter, sort, and pagination parameters
$search        = trim($_GET['search'] ?? '');
$addressFilter = trim($_GET['address'] ?? '');
$sort          = $_GET['sort'] ?? 'farmer_id';
$order         = strtoupper($_GET['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

$allowedSorts = [
    'farmer_id'       => 'f.farmer_id',
    'name'            => 'f.last_name',
    'address'         => 'f.address',
    'date_registered' => 'f.date_registered'
];
$sortColumn = $allowedSorts[$sort] ?? 'f.farmer_id';

$whereClauses = [];
$params = [];

//fix: use subquery lookup for search so searching by contact number lists all of that farmer's numbers
if ($search !== '') {
    $whereClauses[] = "(
        f.first_name ILIKE :search 
        OR f.last_name ILIKE :search 
        OR f.farmer_id IN (
            SELECT fc_sub.farmer_id 
            FROM farmer_contact fc_sub 
            WHERE fc_sub.contact_number ILIKE :search_contact
        )
    )";
    $params['search'] = '%' . $search . '%';
    $params['search_contact'] = '%' . $search . '%';
}

if ($addressFilter !== '') {
    $whereClauses[] = "f.address = :address";
    $params['address'] = $addressFilter;
}

$whereSql = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

//fetch distinct addresses for dropdown filter
$addresses = $pdo->query("SELECT DISTINCT address FROM farmer ORDER BY address ASC")->fetchAll(PDO::FETCH_COLUMN);

$perPage = 10;
$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

//get total row count for pagination
$countSql = "
    SELECT COUNT(DISTINCT f.farmer_id)
    FROM farmer f
    $whereSql
";
$stmtCount = $pdo->prepare($countSql);
$stmtCount->execute($params);
$totalRows = (int) $stmtCount->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $perPage));

//fetch paginated farmer records with aggregated contact numbers
$sql_all = "
    SELECT f.farmer_id, f.first_name, f.last_name, f.address, f.date_registered,
           COALESCE(STRING_AGG(fc.contact_number, ', ' ORDER BY fc.contact_id), 'None') AS contacts
    FROM farmer f
    LEFT JOIN farmer_contact fc ON f.farmer_id = fc.farmer_id
    $whereSql
    GROUP BY f.farmer_id
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
$farmers = $stmt_all->fetchAll();

$nextOrder = $order === 'ASC' ? 'DESC' : 'ASC';

//include header here so flash messages render properly
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Farmer Management</h2>
    <?php if (!$showForm): ?>
        <a href="?new=1" class="btn btn-success">+ Add New Farmer</a>
    <?php else: ?>
        <a href="farmers.php" class="btn btn-secondary">Back to List</a>
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
        <h4 class="mb-3"><?= $editId > 0 ? 'Edit Farmer' : 'Register New Farmer' ?></h4>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="mode" value="<?= $editId > 0 ? 'update' : 'create' ?>">

            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label">First Name</label>
                    <input type="text" name="first_name" class="form-control" value="<?= htmlspecialchars($form['first_name']) ?>" required>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Last Name</label>
                    <input type="text" name="last_name" class="form-control" value="<?= htmlspecialchars($form['last_name']) ?>" required>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Date Registered</label>
                    <input type="date" name="date_registered" class="form-control" value="<?= htmlspecialchars($form['date_registered']) ?>" max="<?= date('Y-m-d') ?>" required>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">Address</label>
                <textarea name="address" class="form-control" rows="2" required><?= htmlspecialchars($form['address']) ?></textarea>
            </div>

            <div class="mb-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <label class="form-label mb-0">Contact Numbers</label>
                    <button type="button" class="btn btn-sm btn-outline-success" id="add-contact-btn">
                        <i class="bi bi-plus-lg"></i> Add Another Number
                    </button>
                </div>
                
                <div id="contact-list">
                    <?php foreach ($form['contacts'] as $index => $contact): ?>
                        <div class="input-group mb-2 contact-row">
                            <input type="text" name="contacts[]" class="form-control" value="<?= htmlspecialchars($contact) ?>" placeholder="Enter phone number (e.g. 09123456789)">
                            <?php if ($index > 0): ?>
                                <button type="button" class="btn btn-outline-danger remove-contact-btn" title="Remove">
                                    <i class="bi bi-trash"></i>
                                </button>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <button type="submit" class="btn btn-success"><?= $editId > 0 ? 'Update Farmer' : 'Save Farmer' ?></button>
            <a href="farmers.php" class="btn btn-outline-secondary">Cancel</a>
        </form>
    </div>

<?php else: ?>
    <!-- search & filter bar -->
    <div class="card shadow-sm p-3 mb-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-5">
                <label class="form-label small text-muted mb-1">Search Name or Contact</label>
                <input type="text" name="search" class="form-control" placeholder="Search farmer..." value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label small text-muted mb-1">Filter by Address</label>
                <select name="address" class="form-select">
                    <option value="">All Addresses</option>
                    <?php foreach ($addresses as $addr): ?>
                        <option value="<?= htmlspecialchars($addr) ?>" <?= $addressFilter === $addr ? 'selected' : '' ?>>
                            <?= htmlspecialchars($addr) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-success w-100">Filter</button>
                <a href="farmers.php" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>

    <!-- data table view -->
    <div class="card shadow-sm p-4">
        <div class="table-responsive">
            <table class="table table-striped align-middle">
                <thead>
                    <tr>
                        <th><a href="?sort=farmer_id&order=<?= $nextOrder ?>&search=<?= urlencode($search) ?>&address=<?= urlencode($addressFilter) ?>" class="text-decoration-none text-dark">ID</a></th>
                        <th><a href="?sort=name&order=<?= $nextOrder ?>&search=<?= urlencode($search) ?>&address=<?= urlencode($addressFilter) ?>" class="text-decoration-none text-dark">Name</a></th>
                        <th>Contact Numbers</th>
                        <th><a href="?sort=address&order=<?= $nextOrder ?>&search=<?= urlencode($search) ?>&address=<?= urlencode($addressFilter) ?>" class="text-decoration-none text-dark">Address</a></th>
                        <th><a href="?sort=date_registered&order=<?= $nextOrder ?>&search=<?= urlencode($search) ?>&address=<?= urlencode($addressFilter) ?>" class="text-decoration-none text-dark">Date Registered</a></th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($farmers)): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted">No farmers found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($farmers as $f): ?>
                            <tr>
                                <td><?= (int) $f['farmer_id'] ?></td>
                                <td><?= htmlspecialchars($f['first_name'] . ' ' . $f['last_name']) ?></td>
                                <td><?= htmlspecialchars($f['contacts']) ?></td>
                                <td><?= htmlspecialchars($f['address']) ?></td>
                                <td><?= htmlspecialchars($f['date_registered']) ?></td>
                                <td>
                                    <a href="view.php?type=farmer&id=<?= (int) $f['farmer_id'] ?>" class="btn btn-sm btn-info text-white">View</a>
                                    <a href="?edit=<?= (int) $f['farmer_id'] ?>" class="btn btn-sm btn-primary">Edit</a>
                                    <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                            <input type="hidden" name="mode" value="delete">
                                            <input type="hidden" name="id" value="<?= (int) $f['farmer_id'] ?>">
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
                            <a class="page-link" href="?page=<?= $p ?>&sort=<?= urlencode($sort) ?>&order=<?= urlencode($order) ?>&search=<?= urlencode($search) ?>&address=<?= urlencode($addressFilter) ?>">
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