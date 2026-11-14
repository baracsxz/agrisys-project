<?php
//define base path and load required dependencies
$base = '.';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/Database.php';

//ensure user is authenticated
require_login();
$pdo = Database::getInstance();

//capture optional date-range filter parameters from query string
$startDate = trim($_GET['start_date'] ?? '');
$endDate   = trim($_GET['end_date'] ?? '');
$activeTab = trim($_GET['tab'] ?? 'farmers'); // track active report tab view

//initialize dynamic date filter clauses and parameter arrays for prepared statements
$dateFilterHarvest = '';
$dateFilterPlanting = '';
$paramsHarvest = [];
$paramsPlanting = [];

if ($startDate !== '' && $endDate !== '') {
    $dateFilterHarvest = "WHERE h.harvest_date BETWEEN :start_d AND :end_d";
    $dateFilterPlanting = "WHERE p.planting_date BETWEEN :start_d AND :end_d";
    $paramsHarvest['start_d'] = $startDate;
    $paramsHarvest['end_d'] = $endDate;
    $paramsPlanting['start_d'] = $startDate;
    $paramsPlanting['end_d'] = $endDate;
}

//fetch overall summary metrics (independent of date filters for baseline counts)
$total_farmers = (int) $pdo->query("SELECT COUNT(*) FROM farmer")->fetchColumn();
$total_farms   = (int) $pdo->query("SELECT COUNT(*) FROM farm")->fetchColumn();
$total_crops   = (int) $pdo->query("SELECT COUNT(*) FROM crop")->fetchColumn();

//fetch filtered summary metrics using prepared statements to prevent type errors
$stmtYield = $pdo->prepare("SELECT COALESCE(SUM(quantity_kg), 0) FROM harvest h $dateFilterHarvest");
$stmtYield->execute($paramsHarvest);
$total_yield = (float) $stmtYield->fetchColumn();

$stmtVal = $pdo->prepare("SELECT COALESCE(SUM(quantity_kg * selling_price_per_kg), 0) FROM harvest h $dateFilterHarvest");
$stmtVal->execute($paramsHarvest);
$total_value = (float) $stmtVal->fetchColumn();

//report 1: fetch all registered farmers and aggregate their associated farms with area summaries
$farmersWithFarms = $pdo->query("
    SELECT f.farmer_id, f.first_name, f.last_name, f.address, f.date_registered,
           COALESCE(STRING_AGG(fa.farm_name || ' (' || fa.area_hectares || ' ha)', ', ' ORDER BY fa.farm_id), 'No Registered Farms') AS farms_list
    FROM farmer f
    LEFT JOIN farm fa ON f.farmer_id = fa.farmer_id
    GROUP BY f.farmer_id
    ORDER BY f.last_name ASC
")->fetchAll();

//report 2: fetch crops planted per farm, incorporating optional date-range filters
$stmtCropsFarm = $pdo->prepare("
    SELECT f.farm_name, fr.first_name, fr.last_name,
           c.crop_name, c.variety, p.area_planted, p.planting_date, ps.status_name
    FROM planting p
    JOIN farm f ON p.farm_id = f.farm_id
    JOIN farmer fr ON f.farmer_id = fr.farmer_id
    JOIN crop c ON p.crop_id = c.crop_id
    JOIN planting_status ps ON p.status_id = ps.status_id
    $dateFilterPlanting
    ORDER BY f.farm_name ASC, p.planting_date DESC
");
$stmtCropsFarm->execute($paramsPlanting);
$cropsPerFarm = $stmtCropsFarm->fetchAll();

//report 3: fetch plantings breakdown grouped by status and calculate total acreage
$plantingsByStatus = $pdo->query("
    SELECT ps.status_name, COUNT(p.planting_id) as total_count, COALESCE(SUM(p.area_planted), 0) as total_area
    FROM planting_status ps
    LEFT JOIN planting p ON ps.status_id = p.status_id
    GROUP BY ps.status_id, ps.status_name
    ORDER BY ps.status_id ASC
")->fetchAll();

//report 4: fetch detailed list of all harvest records matching date criteria
$stmtHarvestList = $pdo->prepare("
    SELECT h.harvest_id, h.harvest_date, h.quantity_kg, h.selling_price_per_kg,
           (h.quantity_kg * h.selling_price_per_kg) AS total_value,
           f.farm_name, c.crop_name, c.variety, qg.grade_name, hr.remark_description
    FROM harvest h
    JOIN planting p ON h.planting_id = p.planting_id
    JOIN farm f ON p.farm_id = f.farm_id
    JOIN crop c ON p.crop_id = c.crop_id
    JOIN quality_grade qg ON h.grade_id = qg.grade_id
    JOIN harvest_remark hr ON h.remark_id = hr.remark_id
    $dateFilterHarvest
    ORDER BY h.harvest_date DESC
");
$stmtHarvestList->execute($paramsHarvest);
$harvestRecordsList = $stmtHarvestList->fetchAll();

//report 5a: fetch harvest yield summary broken down by crop and variety
$stmtCropRep = $pdo->prepare("
    SELECT c.crop_name, c.variety, 
           SUM(h.quantity_kg) as total_qty, 
           SUM(h.quantity_kg * h.selling_price_per_kg) as total_val
    FROM harvest h 
    JOIN planting p ON h.planting_id = p.planting_id 
    JOIN crop c ON p.crop_id = c.crop_id 
    $dateFilterHarvest
    GROUP BY c.crop_name, c.variety
    ORDER BY total_qty DESC
");
$stmtCropRep->execute($paramsHarvest);
$crop_reports = $stmtCropRep->fetchAll();

//report 5b: fetch harvest breakdown sorted by quality grade categories
$stmtGradeRep = $pdo->prepare("
    SELECT qg.grade_id, qg.grade_name, 
           SUM(h.quantity_kg) as total_qty, 
           SUM(h.quantity_kg * h.selling_price_per_kg) as total_val
    FROM harvest h
    JOIN quality_grade qg ON h.grade_id = qg.grade_id
    $dateFilterHarvest
    GROUP BY qg.grade_id, qg.grade_name
    ORDER BY qg.grade_id ASC
");
$stmtGradeRep->execute($paramsHarvest);
$grade_reports = $stmtGradeRep->fetchAll();

//include layout header
require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>System Reports & Analytics</h2>
    <!-- print button triggers browser print functionality for reports -->
    <button onclick="window.print();" class="btn btn-outline-secondary"><i class="bi bi-printer"></i> Print Report</button>
</div>

<!-- date-range filter bar meeting evaluation requirements -->
<div class="card shadow-sm p-3 mb-4">
    <form method="GET" class="row g-3 align-items-end">
        <!-- preserve active tab state when applying filters -->
        <input type="hidden" name="tab" value="<?= htmlspecialchars($activeTab) ?>">
        <div class="col-md-4">
            <label class="form-label small text-muted mb-1">Start Date Filter</label>
            <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($startDate) ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label small text-muted mb-1">End Date Filter</label>
            <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($endDate) ?>">
        </div>
        <div class="col-md-4 d-flex gap-2">
            <button type="submit" class="btn btn-success w-100">Apply Filter</button>
            <a href="reports.php" class="btn btn-outline-secondary">Reset</a>
        </div>
    </form>
</div>

<!-- metric summary cards showcasing dynamic database aggregates -->
<div class="row text-white mb-4 g-3">
    <div class="col-md">
        <div class="card bg-success p-3 shadow-sm">
            <h6 class="text-uppercase small mb-1">Total Farmers</h6>
            <h3 class="mb-0"><?= number_format($total_farmers) ?></h3>
        </div>
    </div>
    <div class="col-md">
        <div class="card bg-primary p-3 shadow-sm">
            <h6 class="text-uppercase small mb-1">Total Farms</h6>
            <h3 class="mb-0"><?= number_format($total_farms) ?></h3>
        </div>
    </div>
    <div class="col-md">
        <div class="card bg-warning text-dark p-3 shadow-sm">
            <h6 class="text-uppercase small mb-1">Registered Crops</h6>
            <h3 class="mb-0"><?= number_format($total_crops) ?></h3>
        </div>
    </div>
    <div class="col-md">
        <div class="card bg-info text-dark p-3 shadow-sm">
            <h6 class="text-uppercase small mb-1">Filtered Yield</h6>
            <h3 class="mb-0"><?= number_format($total_yield, 2) ?> kg</h3>
        </div>
    </div>
    <div class="col-md">
        <div class="card bg-dark text-white p-3 shadow-sm">
            <h6 class="text-uppercase small mb-1">Filtered Est. Value</h6>
            <h3 class="mb-0">PHP <?= number_format($total_value, 2) ?></h3>
        </div>
    </div>
</div>

<!-- report navigation tabs to separate different administrative views cleanly -->
<ul class="nav nav-tabs mb-4">
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'farmers' ? 'active fw-bold text-success' : 'text-dark' ?>" href="?tab=farmers&start_date=<?= $startDate ?>&end_date=<?= $endDate ?>">Farmers & Farms</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'crops_farm' ? 'active fw-bold text-success' : 'text-dark' ?>" href="?tab=crops_farm&start_date=<?= $startDate ?>&end_date=<?= $endDate ?>">Crops per Farm</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'status' ? 'active fw-bold text-success' : 'text-dark' ?>" href="?tab=status&start_date=<?= $startDate ?>&end_date=<?= $endDate ?>">Plantings by Status</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'harvest_list' ? 'active fw-bold text-success' : 'text-dark' ?>" href="?tab=harvest_list&start_date=<?= $startDate ?>&end_date=<?= $endDate ?>">Harvest Records List</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'analytics' ? 'active fw-bold text-success' : 'text-dark' ?>" href="?tab=analytics&start_date=<?= $startDate ?>&end_date=<?= $endDate ?>">Yield & Quality Analytics</a>
    </li>
</ul>

<!-- report 1 view: farmers with their registered farms -->
<?php if ($activeTab === 'farmers'): ?>
<div class="card shadow-sm p-4 mb-4">
    <h4 class="mb-3">Report: Farmers with their Registered Farms</h4>
    <p class="text-muted small">Displays all registered system farmers and aggregates their associated agricultural lands using SQL string aggregation.</p>
    <div class="table-responsive">
        <table class="table table-striped align-middle">
            <thead>
                <tr>
                    <th>Farmer ID</th>
                    <th>Name</th>
                    <th>Address</th>
                    <th>Date Registered</th>
                    <th>Registered Farms & Areas</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($farmersWithFarms)): ?>
                    <tr><td colspan="5" class="text-center text-muted">No farmer records found.</td></tr>
                <?php else: ?>
                    <?php foreach ($farmersWithFarms as $f): ?>
                        <tr>
                            <td><?= (int) $f['farmer_id'] ?></td>
                            <td><?= htmlspecialchars($f['first_name'] . ' ' . $f['last_name']) ?></td>
                            <td><?= htmlspecialchars($f['address']) ?></td>
                            <td><?= htmlspecialchars($f['date_registered']) ?></td>
                            <td><?= htmlspecialchars($f['farms_list']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- report 2 view: crops planted per farm -->
<?php if ($activeTab === 'crops_farm'): ?>
<div class="card shadow-sm p-4 mb-4">
    <h4 class="mb-3">Report: Crops Planted per Farm</h4>
    <p class="text-muted small">Tracks crop distribution across individual farm units, respecting any applied planting date filters.</p>
    <div class="table-responsive">
        <table class="table table-striped align-middle">
            <thead>
                <tr>
                    <th>Farm Name</th>
                    <th>Owner</th>
                    <th>Crop Name & Variety</th>
                    <th>Area Planted</th>
                    <th>Planting Date</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($cropsPerFarm)): ?>
                    <tr><td colspan="6" class="text-center text-muted">No planting records found for the selected filter.</td></tr>
                <?php else: ?>
                    <?php foreach ($cropsPerFarm as $cpf): ?>
                        <tr>
                            <td><?= htmlspecialchars($cpf['farm_name']) ?></td>
                            <td><?= htmlspecialchars($cpf['first_name'] . ' ' . $cpf['last_name']) ?></td>
                            <td><?= htmlspecialchars($cpf['crop_name'] . ' (' . $cpf['variety'] . ')') ?></td>
                            <td><?= number_format((float) $cpf['area_planted'], 2) ?> ha</td>
                            <td><?= htmlspecialchars($cpf['planting_date']) ?></td>
                            <td><span class="badge bg-success"><?= htmlspecialchars($cpf['status_name']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- report 3 view: plantings by status breakdown -->
<?php if ($activeTab === 'status'): ?>
<div class="card shadow-sm p-4 mb-4">
    <h4 class="mb-3">Report: Plantings Breakdown by Status</h4>
    <p class="text-muted small">Summarizes operational activity counts and total cultivated hectares categorized by planting status.</p>
    <div class="table-responsive">
        <table class="table table-striped align-middle">
            <thead>
                <tr>
                    <th>Status</th>
                    <th>Total Plantings</th>
                    <th>Total Area Planted (ha)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($plantingsByStatus as $pbs): ?>
                    <tr>
                        <td><span class="badge bg-secondary"><?= htmlspecialchars($pbs['status_name']) ?></span></td>
                        <td><?= (int) $pbs['total_count'] ?></td>
                        <td><?= number_format((float) $pbs['total_area'], 2) ?> ha</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- report 4 view: complete harvest records list -->
<?php if ($activeTab === 'harvest_list'): ?>
<div class="card shadow-sm p-4 mb-4">
    <h4 class="mb-3">Report: Detailed Harvest Records List</h4>
    <p class="text-muted small">Comprehensive log of harvest yields, financial valuations, quality grades, and remarks.</p>
    <div class="table-responsive">
        <table class="table table-striped align-middle">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Harvest Date</th>
                    <th>Farm</th>
                    <th>Crop</th>
                    <th>Quantity</th>
                    <th>Price/kg</th>
                    <th>Total Value</th>
                    <th>Grade</th>
                    <th>Remarks</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($harvestRecordsList)): ?>
                    <tr><td colspan="9" class="text-center text-muted">No harvest records found matching the criteria.</td></tr>
                <?php else: ?>
                    <?php foreach ($harvestRecordsList as $hrl): ?>
                        <tr>
                            <td><?= (int) $hrl['harvest_id'] ?></td>
                            <td><?= htmlspecialchars($hrl['harvest_date']) ?></td>
                            <td><?= htmlspecialchars($hrl['farm_name']) ?></td>
                            <td><?= htmlspecialchars($hrl['crop_name'] . ' (' . $hrl['variety'] . ')') ?></td>
                            <td><?= number_format((float) $hrl['quantity_kg'], 2) ?> kg</td>
                            <td>PHP <?= number_format((float) $hrl['selling_price_per_kg'], 2) ?></td>
                            <td>PHP <?= number_format((float) $hrl['total_value'], 2) ?></td>
                            <td><span class="badge bg-info text-dark"><?= htmlspecialchars($hrl['grade_name']) ?></span></td>
                            <td><?= htmlspecialchars($hrl['remark_description']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- report 5 view: crop-yield and quality grade analytics -->
<?php if ($activeTab === 'analytics'): ?>
<div class="row">
    <div class="col-md-12 mb-4">
        <div class="card shadow-sm p-4">
            <h4 class="mb-3">Harvest Yield Summary by Crop</h4>
            <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <thead>
                        <tr>
                            <th>Crop Name</th>
                            <th>Variety</th>
                            <th>Total Yield (kg)</th>
                            <th>Estimated Market Value (PHP)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($crop_reports)): ?>
                            <tr><td colspan="4" class="text-center text-muted">No harvest data available for reporting.</td></tr>
                        <?php else: ?>
                            <?php foreach ($crop_reports as $r): ?>
                                <tr>
                                    <td><?= htmlspecialchars($r['crop_name']) ?></td>
                                    <td><?= htmlspecialchars($r['variety']) ?></td>
                                    <td><?= number_format((float) $r['total_qty'], 2) ?> kg</td>
                                    <td>PHP <?= number_format((float) $r['total_val'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-md-12 mb-4">
        <div class="card shadow-sm p-4">
            <h4 class="mb-3">Harvest Breakdown by Quality Grade</h4>
            <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <thead>
                        <tr>
                            <th>Quality Grade</th>
                            <th>Total Yield (kg)</th>
                            <th>Estimated Market Value (PHP)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($grade_reports)): ?>
                            <tr><td colspan="3" class="text-center text-muted">No grade data available for reporting.</td></tr>
                        <?php else: ?>
                            <?php foreach ($grade_reports as $g): ?>
                                <tr>
                                    <td><span class="badge bg-info text-dark"><?= htmlspecialchars($g['grade_name']) ?></span></td>
                                    <td><?= number_format((float) $g['total_qty'], 2) ?> kg</td>
                                    <td>PHP <?= number_format((float) $g['total_val'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php 
//include layout footer
require_once __DIR__ . '/includes/footer.php'; 
?>