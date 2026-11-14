<?php

// load your existing project database connection class
require_once __DIR__ . '/config/Database.php';

try {
    // get the pdo instance using your database config settings
    $pdo = Database::getInstance();

    echo "connected to agrisys_db successfully." . PHP_EOL;
    echo PHP_EOL;

    // start transaction
    $pdo->beginTransaction();

    // clear existing records safely across all tables before inserting fresh data
    $pdo->exec("
        TRUNCATE TABLE
            harvest,
            planting,
            farm_soil,
            farm_irrigation,
            crop_season,
            crop,
            farm,
            farmer_contact,
            farmer,
            soil_type,
            irrigation_type,
            season,
            quality_grade,
            crop_category,
            planting_status
        RESTART IDENTITY CASCADE;
    ");

    // =====================================================
    // 1. insert lookup / reference tables
    // =====================================================

    // soil types
    $pdo->exec("
        INSERT INTO soil_type (soil_name, description) VALUES
        ('Loam', 'Balanced mixture of sand, silt, and clay'),
        ('Clay Loam', 'Fine-textured soil with moderate clay content'),
        ('Sandy Loam', 'Light soil with high sand content'),
        ('Clay', 'Heavy, moisture-retentive soil')
        ON CONFLICT (soil_name) DO NOTHING;
    ");

    // irrigation types
    $pdo->exec("
        INSERT INTO irrigation_type (irrigation_name, description) VALUES
        ('Rainfed', 'Dependent on natural rainfall'),
        ('Irrigated', 'Supplied with water via artificial canals or systems'),
        ('Drip Irrigation', 'Precision watering directly to plant roots')
        ON CONFLICT (irrigation_name) DO NOTHING;
    ");

    // seasons
    $pdo->exec("
        INSERT INTO season (season_name, description) VALUES
        ('Wet Season', 'Rainy monsoon period'),
        ('Dry Season', 'Summer and dry months'),
        ('Year Round', 'Suitable across all seasons')
        ON CONFLICT (season_name) DO NOTHING;
    ");

    // quality grades
    $pdo->exec("
        INSERT INTO quality_grade (grade_name, description) VALUES
        ('Grade A', 'High quality harvest with premium standards'),
        ('Grade B', 'Good harvest with minor blemishes'),
        ('Grade C', 'Acceptable commercial quality')
        ON CONFLICT (grade_name) DO NOTHING;
    ");

    // crop categories
    $pdo->exec("
        INSERT INTO crop_category (category_name, description) VALUES
        ('Cereal', 'Grain crops like rice and corn'),
        ('Vegetable', 'Edible plant parts and garden vegetables'),
        ('Fruit', 'Sweet and fleshy tree or plant fruits'),
        ('Legume', 'Pod-bearing plants like peanuts'),
        ('Root Crop', 'Underground tubers and roots')
        ON CONFLICT (category_name) DO NOTHING;
    ");

    // planting statuses
    $pdo->exec("
        INSERT INTO planting_status (status_name) VALUES
        ('Planted'),
        ('Growing'),
        ('Harvested'),
        ('Failed')
        ON CONFLICT (status_name) DO NOTHING;
    ");

    echo "lookup tables populated successfully." . PHP_EOL;


    // =====================================================
    // 2. insert 15 farmers
    // =====================================================
    $farmers = [
        ["Juan", "Dela Cruz", "09171234567", "Cebu City"],
        ["Maria", "Santos", "09181234567", "Carcar City, Cebu"],
        ["Pedro", "Reyes", "09191234567", "Danao City, Cebu"],
        ["Ana", "Garcia", "09201234567", "Argao, Cebu"],
        ["Jose", "Mendoza", "09211234567", "Bogo City, Cebu"],
        ["Ramon", "Flores", "09221234567", "Toledo City, Cebu"],
        ["Elena", "Cruz", "09231234567", "Naga City, Cebu"],
        ["Antonio", "Lim", "09241234567", "Minglanilla, Cebu"],
        ["Rosa", "Fernandez", "09251234567", "Liloan, Cebu"],
        ["Mario", "Villanueva", "09261234567", "Compostela, Cebu"],
        ["Teresa", "Navarro", "09271234567", "Talisay City, Cebu"],
        ["Carlos", "Ramos", "09281234567", "Consolacion, Cebu"],
        ["Liza", "Torres", "09291234567", "Dalaguete, Cebu"],
        ["Roberto", "Castillo", "09301234567", "Barili, Cebu"],
        ["Susan", "Aquino", "09311234567", "Sibonga, Cebu"]
    ];

    $stmt = $pdo->prepare("
        INSERT INTO farmer (first_name, last_name, contact_number, address, date_registered)
        VALUES (?, ?, ?, ?, '2026-10-26')
        RETURNING farmer_id
    ");

    $farmerIds = [];
    foreach ($farmers as $farmer) {
        $stmt->execute($farmer);
        $farmerIds[] = $stmt->fetchColumn();
    }
    echo "15 farmer records inserted." . PHP_EOL;


    // =====================================================
    // 3. insert 15 farms
    // =====================================================
    $farms = [
        ["Dela Cruz Farm", "Cebu City", 2.50, "Loam", "Rainfed"],
        ["Santos Rice Farm", "Carcar City, Cebu", 4.20, "Clay Loam", "Irrigated"],
        ["Reyes Agricultural Farm", "Danao City, Cebu", 3.80, "Sandy Loam", "Rainfed"],
        ["Garcia Vegetable Farm", "Argao, Cebu", 1.75, "Loam", "Drip Irrigation"],
        ["Mendoza Farm", "Bogo City, Cebu", 5.00, "Clay", "Irrigated"],
        ["Flores Corn Farm", "Toledo City, Cebu", 3.40, "Sandy Loam", "Rainfed"],
        ["Cruz Family Farm", "Naga City, Cebu", 2.80, "Loam", "Irrigated"],
        ["Lim Agricultural Farm", "Minglanilla, Cebu", 2.25, "Clay Loam", "Drip Irrigation"],
        ["Fernandez Farm", "Liloan, Cebu", 3.10, "Sandy Loam", "Rainfed"],
        ["Villanueva Green Farm", "Compostela, Cebu", 4.50, "Loam", "Irrigated"],
        ["Navarro Rice Field", "Talisay City, Cebu", 3.75, "Clay", "Irrigated"],
        ["Ramos Farm", "Consolacion, Cebu", 2.60, "Loam", "Rainfed"],
        ["Torres Vegetable Farm", "Dalaguete, Cebu", 1.80, "Sandy Loam", "Drip Irrigation"],
        ["Castillo Agricultural Farm", "Barili, Cebu", 5.50, "Clay Loam", "Irrigated"],
        ["Aquino Farm", "Sibonga, Cebu", 3.20, "Loam", "Rainfed"]
    ];

    $stmt = $pdo->prepare("
        INSERT INTO farm (farmer_id, farm_name, location, area_hectares, soil_type, irrigation_type)
        VALUES (?, ?, ?, ?, ?, ?)
        RETURNING farm_id
    ");

    $farmIds = [];
    foreach ($farms as $index => $farm) {
        $stmt->execute([
            $farmerIds[$index],
            $farm[0], $farm[1], $farm[2], $farm[3], $farm[4]
        ]);
        $farmIds[] = $stmt->fetchColumn();
    }
    echo "15 farm records inserted." . PHP_EOL;


    // =====================================================
    // 4. insert 10 crops (without explicit IDs so identity generation works)
    // =====================================================
    $crops = [
        ["Rice", "NSIC Rc 222", "Cereal", 115, "Wet Season"],
        ["Corn", "Yellow Corn", "Cereal", 100, "Dry Season"],
        ["Tomato", "Diamante Max", "Vegetable", 90, "Dry Season"],
        ["Eggplant", "Morena", "Vegetable", 100, "Year Round"],
        ["Banana", "Lakatan", "Fruit", 300, "Year Round"],
        ["Mango", "Carabao", "Fruit", 150, "Dry Season"],
        ["Peanut", "BPI Pn 9", "Legume", 110, "Dry Season"],
        ["Cassava", "Golden Yellow", "Root Crop", 240, "Year Round"],
        ["Sweet Potato", "NSIC Sp 30", "Root Crop", 120, "Year Round"],
        ["Watermelon", "Sweet 16", "Fruit", 85, "Dry Season"]
    ];

    $stmt = $pdo->prepare("
        INSERT INTO crop (crop_name, variety, crop_category, growing_period_days, recommended_season)
        VALUES (?, ?, ?, ?, ?)
        RETURNING crop_id
    ");

    $cropIds = [];
    foreach ($crops as $crop) {
        $stmt->execute($crop);
        $cropIds[] = $stmt->fetchColumn();
    }
    echo "10 crop records inserted." . PHP_EOL;


    // =====================================================
    // 5. insert planting records
    // =====================================================
    $stmt = $pdo->prepare("
        INSERT INTO planting (farm_id, crop_id, planting_date, area_planted, seed_quantity, expected_harvest_date, status)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        RETURNING planting_id
    ");

    $startDate = new DateTimeImmutable("2026-02-01");
    $plantingIds = [];
    for ($i = 1; $i <= 28; $i++) {
        $farmIndex = ($i - 1) % 15;
        $cropIndex = ($i - 1) % 10;
        $pDate = $startDate->modify("+" . (($i - 1) * 3) . " days");
        
        $growingDays = $crops[$cropIndex][3]; // adjusted index since crop_id was removed from array
        $expectedHarvest = $pDate->modify("+" . $growingDays . " days");
        $status = ($i <= 20) ? "Harvested" : "Growing";
        $areaPlanted = 0.50 + (($i % 10) * 0.10);
        $seedQuantity = 20 + (($i % 8) * 5);

        $stmt->execute([
            $farmIds[$farmIndex],
            $cropIds[$cropIndex],
            $pDate->format("Y-m-d"),
            $areaPlanted,
            $seedQuantity,
            $expectedHarvest->format("Y-m-d"),
            $status
        ]);
        $plantingIds[] = $stmt->fetchColumn();
    }
    echo "planting records inserted successfully." . PHP_EOL;


    // =====================================================
    // 6. insert 20 harvest records
    // =====================================================
    $stmt = $pdo->prepare("
        INSERT INTO harvest (planting_id, harvest_date, quantity_kg, selling_price_per_kg, quality_grade, remarks)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    for ($i = 1; $i <= 20; $i++) {
        $pDate = $startDate->modify("+" . (($i - 1) * 3) . " days");
        $cropIndex = ($i - 1) % 10;
        $growingDays = $crops[$cropIndex][3];
        $hDate = $pDate->modify("+" . $growingDays . " days");
        
        $quantity = 900.00 + (($i - 1) * 125.00);
        $price = 22.00 + (($i % 8) * 3.50);
        
        if ($i % 3 == 1) {
            $grade = "Grade A";
            $remarks = "high quality harvest";
        } elseif ($i % 3 == 2) {
            $grade = "Grade B";
            $remarks = "good harvest";
        } else {
            $grade = "Grade C";
            $remarks = "acceptable quality";
        }

        $stmt->execute([
            $plantingIds[$i - 1],
            $hDate->format("Y-m-d"),
            $quantity,
            $price,
            $grade,
            $remarks
        ]);
    }
    echo "20 harvest records inserted." . PHP_EOL;

    // commit transaction
    $pdo->commit();

    echo PHP_EOL;
    echo "=========================================" . PHP_EOL;
    echo "all data successfully seeded into agrisys_db!" . PHP_EOL;
    echo "=========================================" . PHP_EOL;

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "database error: " . $e->getMessage() . PHP_EOL;
}

?>