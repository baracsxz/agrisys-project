<?php
//load authentication and database configuration files
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/Database.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    //check if credentials fields are empty
    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password.';
    } else {
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare("
                SELECT u.user_id, u.username, u.password, u.is_active, r.role_name
                FROM app_user u
                JOIN role r ON u.role_id = r.role_id
                WHERE u.username = :username
                LIMIT 1
            ");
            $stmt->execute(['username' => $username]);
            $user = $stmt->fetch();

            // verify user account status and hashed password
            if ($user && $user['is_active'] && password_verify($password, $user['password'])) {
                session_regenerate_id(true);
                $_SESSION['user_id']  = $user['user_id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role']     = $user['role_name'];

                header("Location: dashboard.php");
                exit;
            } else {
                $error = 'Invalid username or password.';
            }
        } catch (Exception $e) {
            //log the real technical error securely on the server instead of leaking details
            error_log('login database error: ' . $e->getMessage());
            //display a generic, safe user-friendly message
            $error = 'Unable to sign in right now.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - AgriSys</title>
    <!-- Bootstrap 5 CSS CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex flex-column min-vh-100">

    <!-- main centered login container -->
    <div class="container d-flex flex-column justify-content-center align-items-center my-auto py-5" style="flex: 1;">
        <div class="row justify-content-center w-100">
            <div class="col-md-5 col-lg-4">
                
                <!-- brand header -->
                <div class="text-center mb-4">
                    <h1 class="fw-bold text-success">🌱 AgriSys</h1>
                    <p class="text-muted">Agriculture Information System</p>
                </div>

                <!-- login card -->
                <div class="card shadow border-0 rounded-4 p-4 bg-white">
                    <h4 class="fw-semibold text-dark mb-4 text-center">Sign In</h4>

                    <?php if (!empty($error)): ?>
                        <div class="alert alert-danger alert-dismissible fade show py-2" role="alert">
                            <?= htmlspecialchars($error) ?>
                            <button type="button" class="btn-close py-2" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label text-secondary small fw-bold">USERNAME</label>
                            <input type="text" name="username" class="form-control form-control-lg fs-6" placeholder="Enter username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required autofocus>
                        </div>
                        <div class="mb-4">
                            <label class="form-label text-secondary small fw-bold">PASSWORD</label>
                            <input type="password" name="password" class="form-control form-control-lg fs-6" placeholder="Enter password" required>
                        </div>
                        <button type="submit" class="btn btn-success btn-lg w-100 py-2 fs-6 fw-semibold shadow-sm">Login to Dashboard</button>
                    </form>
                </div>

                <!-- footer copyright inside container -->
                <div class="text-center text-muted mt-4">
                    <small>&copy; <?= date('Y') ?> AgriSys CTU AP3 Midterm Project. All rights reserved.</small>
                </div>

            </div>
        </div>
    </div>

    <!-- Bootstrap 5 JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>