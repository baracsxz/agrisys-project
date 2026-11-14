<?php
//define base path and load required dependencies
$base = '..';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/Database.php';

//restrict access to admin role centrally using require_admin()
require_admin();

$pdo = Database::getInstance();
$error = '';
$success = '';

//fetch available roles from the lookup table
$roles = $pdo->query("SELECT role_id, role_name FROM role ORDER BY role_id ASC")->fetchAll();
$allowedRoleIds = array_column($roles, 'role_id');

$editId = (int) ($_GET['edit'] ?? 0);
$form = [
    'username'  => '',
    'role_id'   => 2,
    'is_active' => 1
];

//if editing, fetch user details for pre-filling the form
if ($editId > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $stmtEdit = $pdo->prepare("SELECT * FROM app_user WHERE user_id = :id");
    $stmtEdit->execute(['id' => $editId]);
    $userData = $stmtEdit->fetch();
    if ($userData) {
        $form['username']  = $userData['username'];
        $form['role_id']   = $userData['role_id'];
        $form['is_active'] = $userData['is_active'] ? 1 : 0;
    }
}

//handle form submissions (create, update, & delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $username  = trim($_POST['username'] ?? '');
        $password  = $_POST['password'] ?? '';
        $role_id   = (int) ($_POST['role_id'] ?? 0);
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if (empty($username) || $role_id <= 0) {
            $error = 'Username and role are required.';
        } elseif ($action === 'create' && empty($password)) {
            $error = 'Password is required for new users.';
        } elseif (!empty($password) && strlen($password) < 6) {
            $error = 'Password must be at least 6 characters long.';
        } elseif (!in_array($role_id, $allowedRoleIds, true)) {
            $error = 'Selected role is invalid.';
        } else {
            //check if username already exists for other user IDs
            $checkSql = "SELECT COUNT(*) FROM app_user WHERE username = :username";
            $checkParams = ['username' => $username];
            if ($action === 'update' && $editId > 0) {
                $checkSql .= " AND user_id != :edit_id";
                $checkParams['edit_id'] = $editId;
            }
            $check = $pdo->prepare($checkSql);
            $check->execute($checkParams);

            if ($check->fetchColumn() > 0) {
                $error = 'Username is already taken.';
            } else {
                try {
                    if ($action === 'update' && $editId > 0) {
                        //update existing user record (with or without password change)
                        if (!empty($password)) {
                            $hashed = password_hash($password, PASSWORD_DEFAULT);
                            $stmt = $pdo->prepare("UPDATE app_user SET role_id = :role_id, username = :username, password = :password, is_active = :is_active, updated_at = CURRENT_TIMESTAMP WHERE user_id = :id");
                            $stmt->execute([
                                'role_id'   => $role_id,
                                'username'  => $username,
                                'password'  => $hashed,
                                'is_active' => $is_active,
                                'id'        => $editId
                            ]);
                        } else {
                            $stmt = $pdo->prepare("UPDATE app_user SET role_id = :role_id, username = :username, is_active = :is_active, updated_at = CURRENT_TIMESTAMP WHERE user_id = :id");
                            $stmt->execute([
                                'role_id'   => $role_id,
                                'username'  => $username,
                                'is_active' => $is_active,
                                'id'        => $editId
                            ]);
                        }
                        $success = "User '{$username}' updated successfully.";
                        $editId = 0;
                        $form = ['username' => '', 'role_id' => 2, 'is_active' => 1];
                    } else {
                        // insert new user record
                        $hashed = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare("INSERT INTO app_user (role_id, username, password, is_active) VALUES (:role_id, :username, :password, :is_active)");
                        $stmt->execute([
                            'role_id'   => $role_id,
                            'username'  => $username,
                            'password'  => $hashed,
                            'is_active' => $is_active
                        ]);
                        $success = "User '{$username}' created successfully.";
                        $form = ['username' => '', 'role_id' => 2, 'is_active' => 1];
                    }
                } catch (Exception $e) {
                    $error = 'Unable to save user account. Please try again.';
                }
            }
        }
    } elseif ($action === 'delete') {
        // fix: ensure target id is strictly cast to integer
        $target_id = (int) ($_POST['user_id'] ?? 0);

        if ($target_id === (int) ($_SESSION['user_id'] ?? 0)) {
            $error = 'You cannot delete your own currently logged-in account.';
        } elseif ($target_id > 0) {
            // fix: wrap delete operation in a try/catch block
            try {
                $stmt = $pdo->prepare("DELETE FROM app_user WHERE user_id = :id");
                $stmt->execute(['id' => $target_id]);
                $success = 'User account deleted.';
            } catch (Exception $e) {
                $error = 'Failed to delete user account due to database constraints.';
            }
        }
    }
}

//fetch all registered users joined with role lookup
$users = $pdo->query("
    SELECT u.user_id, u.username, u.is_active, u.created_at, r.role_name
    FROM app_user u
    JOIN role r ON u.role_id = r.role_id
    ORDER BY u.user_id ASC
")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>User Management</h2>
    <?php if ($editId > 0): ?>
        <a href="users.php" class="btn btn-outline-secondary btn-sm">Cancel Edit</a>
    <?php endif; ?>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if (!empty($success)): ?>
    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<div class="row">
    <!-- create / edit user form -->
    <div class="col-md-4 mb-4">
        <div class="card shadow-sm p-4">
            <h5 class="mb-3"><?= $editId > 0 ? 'Edit User Account' : 'Create New User' ?></h5>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="<?= $editId > 0 ? 'update' : 'create' ?>">

                <div class="mb-3">
                    <label class="form-label">Username</label>
                    <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($form['username']) ?>" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Password</label>
                    <input type="password" name="password" class="form-control" minlength="6" <?= $editId === 0 ? 'required' : '' ?>>
                    <div class="form-text"><?= $editId > 0 ? 'Leave blank to keep current password.' : 'Must be at least 6 characters long.' ?></div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Role</label>
                    <select name="role_id" class="form-select" required>
                        <?php foreach ($roles as $r): ?>
                            <option value="<?= (int) $r['role_id'] ?>" <?= $form['role_id'] == $r['role_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars(ucfirst($r['role_name'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3 form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1" <?= $form['is_active'] ? 'checked' : '' ?>>
                    <label class="form-check-label fw-bold" for="is_active">Active Account</label>
                </div>

                <button type="submit" class="btn btn-success w-100"><?= $editId > 0 ? 'Update User' : 'Add User' ?></button>
                <?php if ($editId > 0): ?>
                    <a href="users.php" class="btn btn-outline-secondary w-100 mt-2">Cancel</a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- users list table -->
    <div class="col-md-8">
        <div class="card shadow-sm p-4">
            <h5 class="mb-3">Registered Accounts</h5>
            <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Username</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Created At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                            <tr>
                                <td><?= (int) $u['user_id'] ?></td>
                                <td><?= htmlspecialchars($u['username']) ?></td>
                                <td>
                                    <span class="badge bg-<?= $u['role_name'] === 'admin' ? 'danger' : 'secondary' ?>">
                                        <?= htmlspecialchars(ucfirst($u['role_name'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-<?= $u['is_active'] ? 'success' : 'warning' ?>">
                                        <?= $u['is_active'] ? 'Active' : 'Inactive' ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars(date('M d, Y', strtotime($u['created_at']))) ?></td>
                                <td>
                                    <a href="?edit=<?= (int) $u['user_id'] ?>" class="btn btn-sm btn-primary me-1">Edit</a>
                                    <?php if ((int)$u['user_id'] !== (int)($_SESSION['user_id'] ?? 0)): ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="user_id" value="<?= (int) $u['user_id'] ?>">
                                            <button type="button" class="btn btn-sm btn-outline-danger delete-btn">Delete</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-muted small">Current User</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>