<?php
// define base path for root directory files
$base = '.';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/Database.php';

require_login(); // forces user to log in first
$pdo = Database::getInstance();

// fetch real-time metrics for dashboard cards (fully matching professor requirements)
$totalFarmers        = (int) $pdo->query("SELECT COUNT(*) FROM farmer")->fetchColumn();
$totalFarms          = (int) $pdo->query("SELECT COUNT(*) FROM farm")->fetchColumn();
$totalCrops          = (int) $pdo->query("SELECT COUNT(*) FROM crop")->fetchColumn();
$totalPlantings      = (int) $pdo->query("SELECT COUNT(*) FROM planting")->fetchColumn();
$totalHarvestRecords = (int) $pdo->query("SELECT COUNT(*) FROM harvest")->fetchColumn();
$totalYieldKg        = (float) $pdo->query("SELECT COALESCE(SUM(quantity_kg), 0) FROM harvest")->fetchColumn();

// fetch plantings breakdown by status
$statusBreakdown = $pdo->query("
    SELECT ps.status_name, COUNT(p.planting_id) as count
    FROM planting_status ps
    LEFT JOIN planting p ON ps.status_id = p.status_id
    GROUP BY ps.status_id, ps.status_name
    ORDER BY ps.status_id ASC
")->fetchAll();

// fetch recent plantings (latest 5)
$recentPlantings = $pdo->query("
    SELECT p.planting_id, p.planting_date, p.area_planted, f.farm_name, c.crop_name, c.variety, ps.status_name
    FROM planting p
    JOIN farm f ON p.farm_id = f.farm_id
    JOIN crop c ON p.crop_id = c.crop_id
    JOIN planting_status ps ON p.status_id = ps.status_id
    ORDER BY p.planting_id DESC
    LIMIT 5
")->fetchAll();

// fetch recent harvests (latest 5)
$recentHarvests = $pdo->query("
    SELECT h.harvest_id, h.harvest_date, h.quantity_kg, h.selling_price_per_kg,
           (h.quantity_kg * h.selling_price_per_kg) AS estimated_value,
           f.farm_name, c.crop_name, c.variety, qg.grade_name
    FROM harvest h
    JOIN planting p ON h.planting_id = p.planting_id
    JOIN farm f ON p.farm_id = f.farm_id
    JOIN crop c ON p.crop_id = c.crop_id
    JOIN quality_grade qg ON h.grade_id = qg.grade_id
    ORDER BY h.harvest_id DESC
    LIMIT 5
")->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<div class="p-4 mb-4 bg-white rounded-3 shadow-sm">
    <div class="container-fluid py-2">
        <h1 class="display-6 fw-bold text-success">Welcome to AgriSys, <?= htmlspecialchars($_SESSION['username'] ?? 'User') ?>!</h1>
        <p class="col-md-9 fs-5 text-muted">Use the navigation bar on the left or the summary cards below to manage agricultural information, track farmer records, crops, farms, plantings, and harvest outputs.</p>
        <hr class="my-3">
        <p class="mb-0">Current role permissions: <span class="badge bg-<?= ($_SESSION['role'] ?? '') === 'admin' ? 'danger' : 'secondary' ?>"><?= htmlspecialchars(ucfirst($_SESSION['role'] ?? 'staff')) ?></span></p>
    </div>
</div>

<!-- quick metrics summary cards (Row 1) -->
<div class="row g-4 mb-4">
    <div class="col-md-4">
        <div class="card shadow-sm border-start border-success border-4 p-3">
            <h6 class="text-muted text-uppercase small mb-1">Total Farmers</h6>
            <h3 class="fw-bold mb-0 text-success"><?= number_format($totalFarmers) ?></h3>
            <a href="modules/farmers.php" class="small text-decoration-none mt-2 d-block">Manage Farmers &rarr;</a>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm border-start border-primary border-4 p-3">
            <h6 class="text-muted text-uppercase small mb-1">Total Farms</h6>
            <h3 class="fw-bold mb-0 text-primary"><?= number_format($totalFarms) ?></h3>
            <a href="modules/farms.php" class="small text-decoration-none mt-2 d-block">Manage Farms &rarr;</a>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm border-start border-warning border-4 p-3">
            <h6 class="text-muted text-uppercase small mb-1">Total Crops</h6>
            <h3 class="fw-bold mb-0 text-warning"><?= number_format($totalCrops) ?></h3>
            <a href="modules/crops.php" class="small text-decoration-none mt-2 d-block">View Crops &rarr;</a>
        </div>
    </div>
</div>

<!-- quick metrics summary cards (Row 2) -->
<div class="row g-4 mb-4">
    <div class="col-md-4">
        <div class="card shadow-sm border-start border-info border-4 p-3">
            <h6 class="text-muted text-uppercase small mb-1">Total Planting Records</h6>
            <h3 class="fw-bold mb-0 text-info"><?= number_format($totalPlantings) ?></h3>
            <a href="modules/plantings.php" class="small text-decoration-none mt-2 d-block">View Plantings &rarr;</a>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm border-start border-dark border-4 p-3">
            <h6 class="text-muted text-uppercase small mb-1">Total Harvest Records</h6>
            <h3 class="fw-bold mb-0 text-dark"><?= number_format($totalHarvestRecords) ?></h3>
            <a href="modules/harvests.php" class="small text-decoration-none mt-2 d-block">View Harvests &rarr;</a>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm border-start border-success border-4 p-3">
            <h6 class="text-muted text-uppercase small mb-1">Total Harvested Quantity</h6>
            <h3 class="fw-bold mb-0 text-success"><?= number_format($totalYieldKg, 2) ?> kg</h3>
            <a href="modules/harvests.php" class="small text-decoration-none mt-2 d-block">View Harvests &rarr;</a>
        </div>
    </div>
</div>

<!-- plantings by status & recent plantings section -->
<div class="row g-4 mb-4">
    <!-- plantings by status summary table -->
    <div class="col-md-4">
        <div class="card shadow-sm p-4 h-100">
            <h5 class="mb-3">Plantings by Status</h5>
            <div class="table-responsive">
                <table class="table table-striped align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Status</th>
                            <th class="text-end">Count</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($statusBreakdown as $sb): ?>
                            <tr>
                                <td><?= htmlspecialchars($sb['status_name']) ?></td>
                                <td class="text-end"><span class="badge bg-secondary"><?= (int) $sb['count'] ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- recent plantings table -->
    <div class="col-md-8">
        <div class="card shadow-sm p-4 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">Recent Plantings</h5>
                <a href="modules/plantings.php" class="small text-decoration-none">View All &rarr;</a>
            </div>
            <div class="table-responsive">
                <table class="table table-striped align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Farm</th>
                            <th>Crop</th>
                            <th>Area</th>
                            <th>Date</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentPlantings)): ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted">No planting records found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recentPlantings as $rp): ?>
                                <tr>
                                    <td><?= htmlspecialchars($rp['farm_name']) ?></td>
                                    <td><?= htmlspecialchars($rp['crop_name'] . ' (' . $rp['variety'] . ')') ?></td>
                                    <td><?= number_format((float) $rp['area_planted'], 2) ?> ha</td>
                                    <td><?= htmlspecialchars($rp['planting_date']) ?></td>
                                    <td><span class="badge bg-success"><?= htmlspecialchars($rp['status_name']) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- recent harvests table -->
<div class="card shadow-sm p-4 mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0">Recent Harvests</h5>
        <a href="modules/harvests.php" class="small text-decoration-none">View All &rarr;</a>
    </div>
    <div class="table-responsive">
        <table class="table table-striped align-middle mb-0">
            <thead>
                <tr>
                    <th>Farm</th>
                    <th>Crop</th>
                    <th>Harvest Date</th>
                    <th>Quantity</th>
                    <th>Est. Value</th>
                    <th>Grade</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentHarvests)): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted">No harvest records found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recentHarvests as $rh): ?>
                        <tr>
                            <td><?= htmlspecialchars($rh['farm_name']) ?></td>
                            <td><?= htmlspecialchars($rh['crop_name'] . ' (' . $rh['variety'] . ')') ?></td>
                            <td><?= htmlspecialchars($rh['harvest_date']) ?></td>
                            <td><?= number_format((float) $rh['quantity_kg'], 2) ?> kg</td>
                            <td>PHP <?= number_format((float) $rh['estimated_value'], 2) ?></td>
                            <td><span class="badge bg-info text-dark"><?= htmlspecialchars($rh['grade_name']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

//sample comment as per professor
<h4>sample push</h4>
<?php require_once __DIR__ . '/includes/footer.php'; ?>