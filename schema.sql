-- reset existing tables cleanly
DROP SCHEMA public CASCADE;
CREATE SCHEMA public;

-- 1. role lookup table
CREATE TABLE role (
    role_id SERIAL PRIMARY KEY,
    role_name VARCHAR(30) NOT NULL UNIQUE,
    description TEXT
);

INSERT INTO role (role_name, description) VALUES
('admin', 'full system administrator'),
('staff', 'agricultural technician and data encoder');

-- 2. app_user table
CREATE TABLE app_user (
    user_id SERIAL PRIMARY KEY,
    role_id INT NOT NULL REFERENCES role(role_id) ON DELETE RESTRICT,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- insert default admin user (username: admin, password: password123)
INSERT INTO app_user (role_id, username, password) VALUES
(1, 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi');

-- 3. farmer table (updated with check constraint to prevent future registration dates)
CREATE TABLE farmer (
    farmer_id SERIAL PRIMARY KEY,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    address VARCHAR(150) NOT NULL,
    date_registered DATE NOT NULL DEFAULT CURRENT_DATE CHECK (date_registered <= CURRENT_DATE),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 4. farmer_contact table (1-to-many normalization for multivalued contact_number)
CREATE TABLE farmer_contact (
    contact_id SERIAL PRIMARY KEY,
    farmer_id INT NOT NULL REFERENCES farmer(farmer_id) ON DELETE CASCADE,
    contact_type VARCHAR(20) DEFAULT 'Mobile',
    contact_number VARCHAR(20) NOT NULL
);

-- 5. farm table
CREATE TABLE farm (
    farm_id SERIAL PRIMARY KEY,
    farmer_id INT NOT NULL REFERENCES farmer(farmer_id) ON DELETE RESTRICT,
    farm_name VARCHAR(100) NOT NULL,
    location VARCHAR(150) NOT NULL,
    area_hectares NUMERIC(8,2) NOT NULL CHECK (area_hectares > 0),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 6. soil_type lookup table
CREATE TABLE soil_type (
    soil_id SERIAL PRIMARY KEY,
    soil_name VARCHAR(50) NOT NULL UNIQUE,
    description TEXT
);

INSERT INTO soil_type (soil_name, description) VALUES
('Loam', 'balanced sand, silt, and clay'),
('Clay Loam', 'nutrient-rich soil with good water retention'),
('Sandy Loam', 'well-draining fertile soil'),
('Clay', 'heavy moisture-retaining soil');

-- 7. farm_soil associative table (many-to-many: farm <-> soil_type)
CREATE TABLE farm_soil (
    farm_id INT NOT NULL REFERENCES farm(farm_id) ON DELETE CASCADE,
    soil_id INT NOT NULL REFERENCES soil_type(soil_id) ON DELETE RESTRICT,
    PRIMARY KEY (farm_id, soil_id)
);

-- 8. irrigation_type lookup table
CREATE TABLE irrigation_type (
    irrigation_id SERIAL PRIMARY KEY,
    irrigation_name VARCHAR(50) NOT NULL UNIQUE,
    description TEXT
);

INSERT INTO irrigation_type (irrigation_name, description) VALUES
('Rainfed', 'relies solely on rainfall'),
('Irrigated', 'supplied via canal or surface water system'),
('Drip Irrigation', 'targeted root-zone watering');

-- 9. farm_irrigation associative table (many-to-many: farm <-> irrigation_type)
CREATE TABLE farm_irrigation (
    farm_id INT NOT NULL REFERENCES farm(farm_id) ON DELETE CASCADE,
    irrigation_id INT NOT NULL REFERENCES irrigation_type(irrigation_id) ON DELETE RESTRICT,
    PRIMARY KEY (farm_id, irrigation_id)
);

-- 10. crop_category lookup table
CREATE TABLE crop_category (
    category_id SERIAL PRIMARY KEY,
    category_name VARCHAR(50) NOT NULL UNIQUE,
    description TEXT
);

INSERT INTO crop_category (category_name, description) VALUES
('Cereal', 'rice, corn, and staple grains'),
('Vegetable', 'leafy, root, and fruiting vegetables'),
('Fruit', 'orchard and tropical fruit crops'),
('Legume', 'beans, peanuts, and nitrogen-fixing crops'),
('Root Crop', 'cassava, sweet potato, and tubers');

-- 11. crop table
CREATE TABLE crop (
    crop_id SERIAL PRIMARY KEY,
    category_id INT NOT NULL REFERENCES crop_category(category_id) ON DELETE RESTRICT,
    crop_name VARCHAR(80) NOT NULL,
    variety VARCHAR(80) NOT NULL,
    growing_period_days INT NOT NULL CHECK (growing_period_days > 0),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 12. season lookup table
CREATE TABLE season (
    season_id SERIAL PRIMARY KEY,
    season_name VARCHAR(30) NOT NULL UNIQUE,
    description TEXT
);

INSERT INTO season (season_name, description) VALUES
('Wet Season', 'rainy months suitable for water-intensive crops'),
('Dry Season', 'sunny months requiring scheduled irrigation'),
('Year Round', 'can be cultivated throughout the year');

-- 13. crop_season associative table (many-to-many: crop <-> season)
CREATE TABLE crop_season (
    crop_id INT NOT NULL REFERENCES crop(crop_id) ON DELETE CASCADE,
    season_id INT NOT NULL REFERENCES season(season_id) ON DELETE RESTRICT,
    PRIMARY KEY (crop_id, season_id)
);

-- 14. planting_status lookup table
CREATE TABLE planting_status (
    status_id SERIAL PRIMARY KEY,
    status_name VARCHAR(30) NOT NULL UNIQUE
);

INSERT INTO planting_status (status_name) VALUES
('Growing'),
('Harvested'),
('Damaged'),
('Cancelled');

-- 15. planting table (updated with check constraint to prevent future planting dates)
CREATE TABLE planting (
    planting_id SERIAL PRIMARY KEY,
    farm_id INT NOT NULL REFERENCES farm(farm_id) ON DELETE RESTRICT,
    crop_id INT NOT NULL REFERENCES crop(crop_id) ON DELETE RESTRICT,
    status_id INT NOT NULL DEFAULT 1 REFERENCES planting_status(status_id) ON DELETE RESTRICT,
    planting_date DATE NOT NULL CHECK (planting_date <= CURRENT_DATE),
    area_planted NUMERIC(8,2) NOT NULL CHECK (area_planted > 0),
    seed_quantity NUMERIC(8,2) NOT NULL CHECK (seed_quantity > 0),
    expected_harvest_date DATE NOT NULL CHECK (expected_harvest_date >= planting_date),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 16. quality_grade lookup table
CREATE TABLE quality_grade (
    grade_id SERIAL PRIMARY KEY,
    grade_name VARCHAR(30) NOT NULL UNIQUE,
    description TEXT
);

INSERT INTO quality_grade (grade_name, description) VALUES
('Grade A', 'premium export or top market quality'),
('Grade B', 'standard commercial quality'),
('Grade C', 'acceptable processing or local market grade');

-- 17. harvest_remark lookup table (matches controlled dropdown requirement)
CREATE TABLE harvest_remark (
    remark_id SERIAL PRIMARY KEY,
    remark_description VARCHAR(200) NOT NULL UNIQUE
);

INSERT INTO harvest_remark (remark_description) VALUES
('Good Harvest'),
('Acceptable Quality'),
('Low Yield'),
('Poor Quality'),
('Damaged Harvest');

-- 18. harvest table (updated to disallow zero quantities and prevent future harvest dates)
CREATE TABLE harvest (
    harvest_id SERIAL PRIMARY KEY,
    planting_id INT NOT NULL REFERENCES planting(planting_id) ON DELETE RESTRICT,
    grade_id INT NOT NULL REFERENCES quality_grade(grade_id) ON DELETE RESTRICT,
    remark_id INT NOT NULL REFERENCES harvest_remark(remark_id) ON DELETE RESTRICT,
    harvest_date DATE NOT NULL CHECK (harvest_date <= CURRENT_DATE),
    quantity_kg NUMERIC(10,2) NOT NULL CHECK (quantity_kg > 0),
    selling_price_per_kg NUMERIC(10,2) NOT NULL CHECK (selling_price_per_kg >= 0),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);