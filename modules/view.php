<?php
$base = '..';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ui.php';
require_once __DIR__ . '/../config/Database.php';

require_login();
$pdo = Database::getInstance();

$type = $_GET['type'] ?? '';
$id   = (int) ($_GET['id'] ?? 0);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container my-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Record Details Inspection</h2>
        <a href="javascript:history.back()" class="btn btn-secondary">Go Back</a>
    </div>

    <?php if ($type === 'farmer' && $id > 0): 
        $stmt = $pdo->prepare("SELECT * FROM farmer WHERE farmer_id = :id");
        $stmt->execute(['id' => $id]);
        $farmer = $stmt->fetch();
        
        $c_stmt = $pdo->prepare("SELECT contact_number FROM farmer_contact WHERE farmer_id = :id ORDER BY contact_id ASC");
        $c_stmt->execute(['id' => $id]);
        $contacts = $c_stmt->fetchAll(PDO::FETCH_COLUMN);

        $f_stmt = $pdo->prepare("
            SELECT f.*, 
                   COALESCE(STRING_AGG(DISTINCT st.soil_name, ', '), '-') AS soils,
                   COALESCE(STRING_AGG(DISTINCT it.irrigation_name, ', '), '-') AS irrigations
            FROM farm f
            LEFT JOIN farm_soil fs ON f.farm_id = fs.farm_id
            LEFT JOIN soil_type st ON fs.soil_id = st.soil_id
            LEFT JOIN farm_irrigation fi ON f.farm_id = fi.farm_id
            LEFT JOIN irrigation_type it ON fi.irrigation_id = it.irrigation_id
            WHERE f.farmer_id = :id
            GROUP BY f.farm_id
        ");
        $f_stmt->execute(['id' => $id]);
        $farms = $f_stmt->fetchAll();
    ?>
        <?php if (!$farmer): ?>
            <div class="alert alert-warning">Farmer record not found.</div>
        <?php else: ?>
            <div class="card shadow-sm p-4 mb-4">
                <h4>Farmer: <?= htmlspecialchars($farmer['first_name'] . ' ' . $farmer['last_name']) ?></h4>
                <p class="mb-2"><strong>Address:</strong> <?= htmlspecialchars($farmer['address']) ?></p>
                <p class="mb-2"><strong>Date Registered:</strong> <?= htmlspecialchars($farmer['date_registered'] ?? 'N/A') ?></p>
                <p class="mb-0"><strong>Contact Numbers:</strong> <?= !empty($contacts) ? implode(', ', $contacts) : 'None' ?></p>
            </div>

            <div class="card shadow-sm p-4">
                <h5 class="mb-3">Associated Farms</h5>
                <div class="list-group">
                    <?php if (empty($farms)): ?>
                        <div class="text-muted">No farms registered under this farmer.</div>
                    <?php else: ?>
                        <?php foreach ($farms as $farm): ?>
                            <div class="list-group-item mb-2 border rounded p-3">
                                <h6 class="mb-1"><strong><?= htmlspecialchars($farm['farm_name']) ?></strong> (<?= number_format((float) $farm['area_hectares'], 2) ?> Hectares)</h6>
                                <p class="mb-1 text-muted small"><strong>Location:</strong> <?= htmlspecialchars($farm['location']) ?></p>
                                <p class="mb-1 small"><strong>Soil Type(s):</strong> <?= htmlspecialchars($farm['soils']) ?></p>
                                <p class="mb-0 small"><strong>Irrigation Type(s):</strong> <?= htmlspecialchars($farm['irrigations']) ?></p>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

    <?php elseif ($type === 'farm' && $id > 0):
        $stmt = $pdo->prepare("
            SELECT f.*, fr.first_name, fr.last_name, fr.address AS farmer_address,
                   COALESCE(STRING_AGG(DISTINCT st.soil_name, ', '), '-') AS soils,
                   COALESCE(STRING_AGG(DISTINCT it.irrigation_name, ', '), '-') AS irrigations
            FROM farm f
            JOIN farmer fr ON f.farmer_id = fr.farmer_id
            LEFT JOIN farm_soil fs ON f.farm_id = fs.farm_id
            LEFT JOIN soil_type st ON fs.soil_id = st.soil_id
            LEFT JOIN farm_irrigation fi ON f.farm_id = fi.farm_id
            LEFT JOIN irrigation_type it ON fi.irrigation_id = it.irrigation_id
            WHERE f.farm_id = :id
            GROUP BY f.farm_id, fr.first_name, fr.last_name, fr.address
        ");
        $stmt->execute(['id' => $id]);
        $farm = $stmt->fetch();

        $p_stmt = $pdo->prepare("
            SELECT p.*, c.crop_name, c.variety, ps.status_name
            FROM planting p
            JOIN crop c ON p.crop_id = c.crop_id
            JOIN planting_status ps ON p.status_id = ps.status_id
            WHERE p.farm_id = :id
            ORDER BY p.planting_date DESC
        ");
        $p_stmt->execute(['id' => $id]);
        $plantings = $p_stmt->fetchAll();
    ?>
        <?php if (!$farm): ?>
            <div class="alert alert-warning">Farm record not found.</div>
        <?php else: ?>
            <div class="card shadow-sm p-4 mb-4">
                <h4>Farm: <?= htmlspecialchars($farm['farm_name']) ?></h4>
                <p class="mb-2"><strong>Owner:</strong> <?= htmlspecialchars($farm['first_name'] . ' ' . $farm['last_name']) ?> (<?= htmlspecialchars($farm['farmer_address']) ?>)</p>
                <p class="mb-2"><strong>Location:</strong> <?= htmlspecialchars($farm['location']) ?></p>
                <p class="mb-2"><strong>Total Area:</strong> <?= number_format((float) $farm['area_hectares'], 2) ?> Hectares</p>
                <p class="mb-2"><strong>Soil Type(s):</strong> <?= htmlspecialchars($farm['soils']) ?></p>
                <p class="mb-0"><strong>Irrigation Type(s):</strong> <?= htmlspecialchars($farm['irrigations']) ?></p>
            </div>

            <div class="card shadow-sm p-4">
                <h5 class="mb-3">Planting History on this Farm</h5>
                <?php if (empty($plantings)): ?>
                    <div class="text-muted">No planting records found for this farm.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped align-middle">
                            <thead>
                                <tr>
                                    <th>Crop (Variety)</th>
                                    <th>Area Planted</th>
                                    <th>Seed (kg)</th>
                                    <th>Planting Date</th>
                                    <th>Expected Harvest</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($plantings as $pl): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($pl['crop_name'] . ' (' . $pl['variety'] . ')') ?></td>
                                        <td><?= number_format((float) $pl['area_planted'], 2) ?> ha</td>
                                        <td><?= number_format((float) $pl['seed_quantity'], 2) ?> kg</td>
                                        <td><?= htmlspecialchars($pl['planting_date']) ?></td>
                                        <td><?= htmlspecialchars($pl['expected_harvest_date']) ?></td>
                                        <td><span class="badge bg-secondary"><?= htmlspecialchars($pl['status_name']) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    <?php elseif ($type === 'crop' && $id > 0):
        $stmt = $pdo->prepare("
            SELECT c.*, cc.category_name,
                   COALESCE(STRING_AGG(DISTINCT s.season_name, ', ' ORDER BY s.season_name), '-') AS seasons
            FROM crop c
            JOIN crop_category cc ON c.category_id = cc.category_id
            LEFT JOIN crop_season cs ON c.crop_id = cs.crop_id
            LEFT JOIN season s ON cs.season_id = s.season_id
            WHERE c.crop_id = :id
            GROUP BY c.crop_id, cc.category_name
        ");
        $stmt->execute(['id' => $id]);
        $crop = $stmt->fetch();

        $p_stmt = $pdo->prepare("
            SELECT p.*, f.farm_name, ps.status_name
            FROM planting p
            JOIN farm f ON p.farm_id = f.farm_id
            JOIN planting_status ps ON p.status_id = ps.status_id
            WHERE p.crop_id = :id
            ORDER BY p.planting_date DESC
        ");
        $p_stmt->execute(['id' => $id]);
        $plantings = $p_stmt->fetchAll();
    ?>
        <?php if (!$crop): ?>
            <div class="alert alert-warning">Crop record not found.</div>
        <?php else: ?>
            <div class="card shadow-sm p-4 mb-4">
                <h4>Crop: <?= htmlspecialchars($crop['crop_name']) ?> (<?= htmlspecialchars($crop['variety']) ?>)</h4>
                <p class="mb-2"><strong>Category:</strong> <?= htmlspecialchars($crop['category_name']) ?></p>
                <p class="mb-2"><strong>Growing Period:</strong> <?= (int) $crop['growing_period_days'] ?> days</p>
                <p class="mb-0"><strong>Recommended Season(s):</strong> <?= htmlspecialchars($crop['seasons']) ?></p>
            </div>

            <div class="card shadow-sm p-4">
                <h5 class="mb-3">Plantings Utilizing This Crop</h5>
                <?php if (empty($plantings)): ?>
                    <div class="text-muted">No plantings recorded for this crop.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped align-middle">
                            <thead>
                                <tr>
                                    <th>Farm</th>
                                    <th>Area (Ha)</th>
                                    <th>Planting Date</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($plantings as $pl): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($pl['farm_name']) ?></td>
                                        <td><?= number_format((float) $pl['area_planted'], 2) ?></td>
                                        <td><?= htmlspecialchars($pl['planting_date']) ?></td>
                                        <td><span class="badge bg-secondary"><?= htmlspecialchars($pl['status_name']) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    <?php elseif ($type === 'planting' && $id > 0):
        $stmt = $pdo->prepare("
            SELECT p.*, f.farm_name, f.location, fr.first_name, fr.last_name,
                   c.crop_name, c.variety, cc.category_name, ps.status_name
            FROM planting p
            JOIN farm f ON p.farm_id = f.farm_id
            JOIN farmer fr ON f.farmer_id = fr.farmer_id
            JOIN crop c ON p.crop_id = c.crop_id
            JOIN crop_category cc ON c.category_id = cc.category_id
            JOIN planting_status ps ON p.status_id = ps.status_id
            WHERE p.planting_id = :id
        ");
        $stmt->execute(['id' => $id]);
        $planting = $stmt->fetch();

        $h_stmt = $pdo->prepare("
            SELECT h.*, qg.grade_name, hr.remark_description,
                   (h.quantity_kg * h.selling_price_per_kg) AS estimated_value
            FROM harvest h
            JOIN quality_grade qg ON h.grade_id = qg.grade_id
            JOIN harvest_remark hr ON h.remark_id = hr.remark_id
            WHERE h.planting_id = :id
            ORDER BY h.harvest_date DESC
        ");
        $h_stmt->execute(['id' => $id]);
        $harvests = $h_stmt->fetchAll();
    ?>
        <?php if (!$planting): ?>
            <div class="alert alert-warning">Planting record not found.</div>
        <?php else: ?>
            <div class="card shadow-sm p-4 mb-4">
                <h4>Planting #<?= (int) $planting['planting_id'] ?> — <?= htmlspecialchars($planting['crop_name'] . ' (' . $planting['variety'] . ')') ?></h4>
                <p class="mb-2"><strong>Farm:</strong> <?= htmlspecialchars($planting['farm_name']) ?> (Owner: <?= htmlspecialchars($planting['first_name'] . ' ' . $planting['last_name']) ?>)</p>
                <p class="mb-2"><strong>Area Planted:</strong> <?= number_format((float) $planting['area_planted'], 2) ?> ha | <strong>Seed Quantity:</strong> <?= number_format((float) $planting['seed_quantity'], 2) ?> kg</p>
                <p class="mb-2"><strong>Planting Date:</strong> <?= htmlspecialchars($planting['planting_date']) ?> | <strong>Expected Harvest:</strong> <?= htmlspecialchars($planting['expected_harvest_date']) ?></p>
                <p class="mb-0"><strong>Status:</strong> <span class="badge bg-success"><?= htmlspecialchars($planting['status_name']) ?></span></p>
            </div>

            <div class="card shadow-sm p-4">
                <h5 class="mb-3">Associated Harvest Yields</h5>
                <?php if (empty($harvests)): ?>
                    <div class="text-muted">No harvest records tied to this planting yet.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped align-middle">
                            <thead>
                                <tr>
                                    <th>Harvest Date</th>
                                    <th>Quantity (kg)</th>
                                    <th>Price/kg</th>
                                    <th>Est. Value</th>
                                    <th>Grade</th>
                                    <th>Remarks</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($harvests as $h): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($h['harvest_date']) ?></td>
                                        <td><?= number_format((float) $h['quantity_kg'], 2) ?> kg</td>
                                        <td>PHP <?= number_format((float) $h['selling_price_per_kg'], 2) ?></td>
                                        <td>PHP <?= number_format((float) $h['estimated_value'], 2) ?></td>
                                        <td><span class="badge bg-info text-dark"><?= htmlspecialchars($h['grade_name']) ?></span></td>
                                        <td><?= htmlspecialchars($h['remark_description']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    <?php elseif ($type === 'harvest' && $id > 0):
        $stmt = $pdo->prepare("
            SELECT h.*, (h.quantity_kg * h.selling_price_per_kg) AS estimated_value,
                   p.planting_date, p.area_planted,
                   f.farm_name, f.location, fr.first_name, fr.last_name,
                   c.crop_name, c.variety,
                   qg.grade_name, hr.remark_description
            FROM harvest h
            JOIN planting p ON h.planting_id = p.planting_id
            JOIN farm f ON p.farm_id = f.farm_id
            JOIN farmer fr ON f.farmer_id = fr.farmer_id
            JOIN crop c ON p.crop_id = c.crop_id
            JOIN quality_grade qg ON h.grade_id = qg.grade_id
            JOIN harvest_remark hr ON h.remark_id = hr.remark_id
            WHERE h.harvest_id = :id
        ");
        $stmt->execute(['id' => $id]);
        $harvest = $stmt->fetch();
    ?>
        <?php if (!$harvest): ?>
            <div class="alert alert-warning">Harvest record not found.</div>
        <?php else: ?>
            <div class="card shadow-sm p-4 mb-4">
                <h4>Harvest Record #<?= (int) $harvest['harvest_id'] ?></h4>
                <p class="mb-2"><strong>Farm & Crop:</strong> <?= htmlspecialchars($harvest['farm_name']) ?> — <?= htmlspecialchars($harvest['crop_name'] . ' (' . $harvest['variety'] . ')') ?></p>
                <p class="mb-2"><strong>Farmer Owner:</strong> <?= htmlspecialchars($harvest['first_name'] . ' ' . $harvest['last_name']) ?></p>
                <p class="mb-2"><strong>Harvest Date:</strong> <?= htmlspecialchars($harvest['harvest_date']) ?></p>
                <p class="mb-2"><strong>Quantity Yield:</strong> <?= number_format((float) $harvest['quantity_kg'], 2) ?> kg</p>
                <p class="mb-2"><strong>Selling Price per kg:</strong> PHP <?= number_format((float) $harvest['selling_price_per_kg'], 2) ?></p>
                <p class="mb-2"><strong>Estimated Total Value:</strong> <strong>PHP <?= number_format((float) $harvest['estimated_value'], 2) ?></strong></p>
                <p class="mb-2"><strong>Quality Grade:</strong> <span class="badge bg-info text-dark"><?= htmlspecialchars($harvest['grade_name']) ?></span></p>
                <p class="mb-0"><strong>Remarks:</strong> <?= htmlspecialchars($harvest['remark_description']) ?></p>
            </div>
        <?php endif; ?>

    <?php else: ?>
        <div class="alert alert-warning">Invalid view parameters or record not found. Use links like <code>view.php?type=farmer&id=1</code>.</div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>