<?php
//determine relative base path if needed
$base = $base ?? '';
$current_page = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agriculture IS</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <!-- SweetAlert2 CSS & JS CDN -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <!-- Unified External Custom JS -->
    <script src="<?= $base ?>/assets/js/main.js"></script>
    <!-- Unified External Custom CSS -->
    <link href="<?= $base ?>/assets/css/style.css" rel="stylesheet">
</head>

<body class="bg-light d-flex flex-column min-vh-100">

    <!-- MOBILE HEADER (Visible only on small screens) -->
    <header class="navbar navbar-dark sticky-top bg-success shadow d-lg-none p-2" style="z-index: 1060;">
        <div class="container-fluid">
            <a class="navbar-brand fw-bold mb-0 text-white" href="<?= $base ?>/dashboard.php">🌱 AgriSys</a>
            <button class="navbar-toggler" type="button" id="sidebarToggle" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
        </div>
    </header>

    <!-- Sidebar Navigation -->
    <nav id="sidebar">
        <div>
            <!-- Branding Header -->
            <div class="brand-area d-flex align-items-center gap-3">
                <div>
                    <h6 class="mb-0 fw-bold">🌱 AgriSys</h6>
                    <small style="font-size: 0.75rem; color: #a3cfbb;">Agriculture Information System</small>
                </div>
            </div>

            <!-- Main Menu Links -->
            <div class="py-3">
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a href="<?= $base ?>/dashboard.php" class="nav-link <?= ($current_page == 'dashboard.php') ? 'active' : '' ?>">
                            <i class="bi bi-grid-fill"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="<?= $base ?>/modules/farmers.php" class="nav-link <?= ($current_page == 'farmers.php') ? 'active' : '' ?>">
                            <i class="bi bi-people-fill"></i> Farmers
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="<?= $base ?>/modules/farms.php" class="nav-link <?= ($current_page == 'farms.php') ? 'active' : '' ?>">
                            <i class="bi bi-house-door-fill"></i> Farms
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="<?= $base ?>/modules/crops.php" class="nav-link <?= ($current_page == 'crops.php') ? 'active' : '' ?>">
                            <i class="bi bi-tree-fill"></i> Crops
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="<?= $base ?>/modules/plantings.php" class="nav-link <?= ($current_page == 'plantings.php') ? 'active' : '' ?>">
                            <i class="bi bi-layers-fill"></i> Planting records
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="<?= $base ?>/modules/harvests.php" class="nav-link <?= ($current_page == 'harvests.php') ? 'active' : '' ?>">
                            <i class="bi bi-basket-fill"></i> Harvest records
                        </a>
                    </li>
                </ul>

                <!-- Output Section -->
                <div class="section-title">Output</div>
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a href="<?= $base ?>/reports.php" class="nav-link <?= ($current_page == 'reports.php') ? 'active' : '' ?>">
                            <i class="bi bi-file-earmark-bar-graph-fill"></i> Reports
                        </a>
                    </li>
                </ul>

                <!-- System Section -->
                <div class="section-title">System</div>
                <ul class="nav flex-column">
                    <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                    <li class="nav-item">
                        <a href="<?= $base ?>/modules/users.php" class="nav-link <?= ($current_page == 'users.php') ? 'active' : '' ?>">
                            <i class="bi bi-shield-lock-fill"></i> Manage Users
                        </a>
                    </li>
                    <?php endif; ?>
                    <li class="nav-item">
                        <a href="<?= $base ?>/resetpassword.php" class="nav-link <?= ($current_page == 'resetpassword.php') ? 'active' : '' ?>">
                            <i class="bi bi-key-fill"></i> Change Password
                        </a>
                    </li>
                </ul>
            </div>
        </div>

        <!-- User Profile & Sign Out at Bottom -->
        <div class="p-3 border-top border-success border-opacity-25">
            <div class="d-flex align-items-center gap-3 mb-3 px-2">
                <div class="bg-warning text-dark rounded-circle fw-bold d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                    <?= strtoupper(substr($_SESSION['username'] ?? 'A', 0, 2)) ?>
                </div>
                <div class="overflow-hidden">
                    <h6 class="mb-0 text-truncate fw-semibold" style="font-size: 0.9rem;"><?= htmlspecialchars($_SESSION['username'] ?? 'Administrator') ?></h6>
                    <small class="text-white-50 text-uppercase" style="font-size: 0.7rem;"><?= htmlspecialchars($_SESSION['role'] ?? 'Admin') ?></small>
                </div>
            </div>

            <a href="<?= $base ?>/logout.php" class="nav-link text-danger bg-danger bg-opacity-10 rounded">
                <i class="bi bi-box-arrow-left"></i> Sign out
            </a>
        </div>
    </nav>

    <!-- Main Content Wrapper -->
    <div id="main-content">
        <div class="container-fluid">
            <?php 
            //render any active session flash messages as SweetAlert toasts
            if (function_exists('render_flash')) {
                render_flash();
            }
            ?>