-- ============================================================
-- COOPERS FARM - Complete Database Schema
-- Poultry Management System
-- ============================================================

CREATE DATABASE IF NOT EXISTS coopers_farm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE coopers_farm;

-- ============================================================
-- USERS & AUTH
-- ============================================================

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) UNIQUE NOT NULL,
    phone VARCHAR(20),
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('customer','admin','farm_worker') DEFAULT 'customer',
    location TEXT,
    profile_image VARCHAR(255),
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ============================================================
-- FARM - FLOCK MANAGEMENT
-- ============================================================

CREATE TABLE IF NOT EXISTS flocks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    flock_name VARCHAR(100) NOT NULL,
    bird_type ENUM('broiler','layer','cockerel','turkey') NOT NULL,
    quantity INT NOT NULL,
    arrival_date DATE NOT NULL,
    source VARCHAR(150),
    housing_unit VARCHAR(50),
    status ENUM('active','sold','depleted','quarantine') DEFAULT 'active',
    notes TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

-- ============================================================
-- VACCINATION MANAGEMENT
-- ============================================================

CREATE TABLE IF NOT EXISTS vaccination_schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    schedule_name VARCHAR(100) NOT NULL,
    bird_type ENUM('broiler','layer','cockerel','turkey'),
    description TEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS vaccination_schedule_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    schedule_id INT NOT NULL,
    day_number INT NOT NULL,
    vaccine_name VARCHAR(100) NOT NULL,
    dosage VARCHAR(50),
    method ENUM('drinking_water','injection','eye_drop','spray') DEFAULT 'drinking_water',
    notes TEXT,
    FOREIGN KEY (schedule_id) REFERENCES vaccination_schedules(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS vaccinations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    flock_id INT NOT NULL,
    vaccine_name VARCHAR(100) NOT NULL,
    date_given DATE NOT NULL,
    next_due_date DATE,
    dosage VARCHAR(50),
    method ENUM('drinking_water','injection','eye_drop','spray') DEFAULT 'drinking_water',
    administered_by VARCHAR(100),
    birds_vaccinated INT,
    notes TEXT,
    status ENUM('completed','scheduled','overdue','missed') DEFAULT 'completed',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (flock_id) REFERENCES flocks(id) ON DELETE CASCADE
);

-- ============================================================
-- HEALTH & MORTALITY
-- ============================================================

CREATE TABLE IF NOT EXISTS health_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    flock_id INT NOT NULL,
    record_date DATE NOT NULL,
    type ENUM('disease','injury','checkup','treatment','vaccination') NOT NULL,
    disease_name VARCHAR(100),
    symptoms TEXT,
    treatment TEXT,
    treated_by VARCHAR(100),
    outcome ENUM('recovered','ongoing','died','pending') DEFAULT 'pending',
    affected_count INT DEFAULT 0,
    status ENUM('active','completed','monitoring') DEFAULT 'active',
    description TEXT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (flock_id) REFERENCES flocks(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS mortality_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    flock_id INT NOT NULL,
    log_date DATE NOT NULL,
    count INT NOT NULL DEFAULT 0,
    cause VARCHAR(150),
    notes TEXT,
    recorded_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (flock_id) REFERENCES flocks(id) ON DELETE CASCADE,
    FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS daily_health_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    flock_id INT NOT NULL,
    log_date DATE NOT NULL,
    appetite ENUM('normal','low','none') DEFAULT 'normal',
    activity ENUM('normal','low','lethargic') DEFAULT 'normal',
    droppings ENUM('normal','watery','bloody','discolored') DEFAULT 'normal',
    respiratory ENUM('normal','coughing','sneezing','wheezing') DEFAULT 'normal',
    notes TEXT,
    recorded_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (flock_id) REFERENCES flocks(id) ON DELETE CASCADE,
    FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
);

-- ============================================================
-- EGG PRODUCTION
-- ============================================================

CREATE TABLE IF NOT EXISTS egg_production (
    id INT AUTO_INCREMENT PRIMARY KEY,
    flock_id INT NOT NULL,
    production_date DATE NOT NULL,
    quantity INT NOT NULL DEFAULT 0,
    broken_eggs INT DEFAULT 0,
    sold_eggs INT DEFAULT 0,
    notes TEXT,
    recorded_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (flock_id) REFERENCES flocks(id) ON DELETE CASCADE,
    FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
);

-- ============================================================
-- FEED MANAGEMENT
-- ============================================================

CREATE TABLE IF NOT EXISTS feed_stock (
    id INT AUTO_INCREMENT PRIMARY KEY,
    feed_type VARCHAR(100) NOT NULL,
    brand VARCHAR(100),
    quantity_kg DECIMAL(10,2) NOT NULL DEFAULT 0,
    unit_cost DECIMAL(10,2) DEFAULT 0,
    reorder_level DECIMAL(10,2) DEFAULT 50,
    supplier VARCHAR(150),
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS feed_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    flock_id INT NOT NULL,
    feed_stock_id INT,
    feed_type VARCHAR(100) NOT NULL,
    quantity_kg DECIMAL(10,2) NOT NULL,
    log_date DATE NOT NULL,
    cost DECIMAL(10,2),
    notes TEXT,
    recorded_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (flock_id) REFERENCES flocks(id) ON DELETE CASCADE,
    FOREIGN KEY (feed_stock_id) REFERENCES feed_stock(id) ON DELETE SET NULL,
    FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
);

-- ============================================================
-- PRODUCTS (E-COMMERCE)
-- ============================================================

CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    category ENUM('eggs','broiler','layer','byproduct','other') NOT NULL,
    description TEXT,
    price DECIMAL(10,2) NOT NULL,
    unit VARCHAR(50) DEFAULT 'piece',
    stock_quantity INT DEFAULT 0,
    min_order INT DEFAULT 1,
    image_url VARCHAR(255),
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS inventory_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    change_amount INT NOT NULL,
    reason ENUM('sale','restock','damage','adjustment','production') NOT NULL,
    notes TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

-- ============================================================
-- ORDERS
-- ============================================================

CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    order_number VARCHAR(20) UNIQUE NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL,
    delivery_fee DECIMAL(10,2) DEFAULT 0,
    payment_method ENUM('momo_mtn','momo_vodafone','momo_airteltigo','cash') DEFAULT 'cash',
    payment_status ENUM('pending','paid','failed','refunded') DEFAULT 'pending',
    order_status ENUM('pending','confirmed','processing','out_for_delivery','delivered','cancelled') DEFAULT 'pending',
    delivery_address TEXT,
    delivery_date DATE,
    customer_name VARCHAR(100),
    customer_phone VARCHAR(20),
    customer_email VARCHAR(150),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    product_name VARCHAR(150) NOT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    subtotal DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);

-- ============================================================
-- SALES TRACKING
-- ============================================================

CREATE TABLE IF NOT EXISTS sales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT,
    sale_date DATE NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL,
    payment_method VARCHAR(50),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL
);

-- ============================================================
-- SUBSCRIPTIONS
-- ============================================================

CREATE TABLE IF NOT EXISTS subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL,
    frequency ENUM('weekly','biweekly','monthly') DEFAULT 'weekly',
    delivery_day ENUM('monday','tuesday','wednesday','thursday','friday','saturday') DEFAULT 'monday',
    delivery_address TEXT NOT NULL,
    status ENUM('active','paused','cancelled') DEFAULT 'active',
    next_delivery DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);

-- ============================================================
-- NOTIFICATIONS & ALERTS
-- ============================================================

CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type ENUM('vaccination_due','low_stock','new_order','health_alert','mortality') NOT NULL,
    title VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    is_read TINYINT(1) DEFAULT 0,
    related_id INT,
    related_type VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- SEED DATA
-- ============================================================

-- Default admin user (password: password)
-- Hash below is bcrypt of the string: password
INSERT IGNORE INTO users (name, email, phone, password_hash, role) VALUES 
('Farm Manager', 'admin@coopersfarm.com', '+233241234567', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin'),
('John Customer', 'customer@example.com', '+233201234567', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'customer');

-- Sample flocks
INSERT IGNORE INTO flocks (flock_name, bird_type, quantity, arrival_date, source, housing_unit, status) VALUES
('Batch A - Layers', 'layer', 500, '2024-01-15', 'Accra Hatchery', 'House 1', 'active'),
('Batch B - Broilers', 'broiler', 300, '2024-03-01', 'Tema Poultry', 'House 2', 'active'),
('Batch C - Layers', 'layer', 400, '2024-04-10', 'Accra Hatchery', 'House 3', 'active');

-- Sample products
INSERT IGNORE INTO products (name, category, description, price, unit, stock_quantity) VALUES
('Fresh Eggs (Crate of 30)', 'eggs', 'Farm-fresh eggs, 30 pieces per crate. Collected daily from our healthy layers.', 25.00, 'crate', 150),
('Fresh Eggs (Half Crate)', 'eggs', 'Farm-fresh eggs, 15 pieces. Perfect for small families.', 13.00, 'half-crate', 200),
('Live Broiler (Medium)', 'broiler', 'Live broiler chicken, approximately 2-2.5kg. Healthy and well-fed.', 45.00, 'bird', 80),
('Live Broiler (Large)', 'broiler', 'Live broiler chicken, approximately 3-3.5kg. Premium quality.', 65.00, 'bird', 50),
('Day-Old Chicks (Layer)', 'layer', 'Healthy day-old layer chicks, vaccinated and ready for rearing.', 8.00, 'chick', 200),
('Day-Old Chicks (Broiler)', 'broiler', 'Healthy day-old broiler chicks. Fast-growing and disease-resistant.', 6.00, 'chick', 300);

-- Sample vaccination schedules
INSERT IGNORE INTO vaccination_schedules (schedule_name, bird_type, description) VALUES
('Standard Layer Schedule', 'layer', 'Recommended vaccination schedule for layer birds'),
('Standard Broiler Schedule', 'broiler', 'Recommended vaccination schedule for broiler birds');

INSERT IGNORE INTO vaccination_schedule_items (schedule_id, day_number, vaccine_name, dosage, method) VALUES
(1, 1, "Marek's Disease", '0.2ml', 'injection'),
(1, 7, 'Newcastle Disease (Lasota)', '1 drop', 'eye_drop'),
(1, 14, 'Gumboro (IBD)', 'In water', 'drinking_water'),
(1, 21, 'Newcastle Booster', 'In water', 'drinking_water'),
(1, 42, 'Fowl Pox', '0.1ml', 'injection'),
(2, 1, 'Marek''s Disease', '0.2ml', 'injection'),
(2, 7, 'Newcastle Disease', '1 drop', 'eye_drop'),
(2, 14, 'Gumboro', 'In water', 'drinking_water');

-- Sample feed stock
INSERT IGNORE INTO feed_stock (feed_type, brand, quantity_kg, unit_cost, reorder_level) VALUES
('Chick Starter', 'Vendor Feeds', 200.00, 2.50, 50),
('Grower Feed', 'Vendome Pro', 350.00, 2.20, 100),
('Layer Mash', 'Accra Mills', 500.00, 2.00, 150),
('Finisher Feed', 'Vendome Pro', 180.00, 2.30, 75);

-- Sample egg production (last 7 days)
INSERT IGNORE INTO egg_production (flock_id, production_date, quantity, broken_eggs, sold_eggs) VALUES
(1, CURDATE() - INTERVAL 6 DAY, 380, 5, 360),
(1, CURDATE() - INTERVAL 5 DAY, 395, 3, 380),
(1, CURDATE() - INTERVAL 4 DAY, 410, 7, 390),
(1, CURDATE() - INTERVAL 3 DAY, 405, 4, 400),
(1, CURDATE() - INTERVAL 2 DAY, 420, 6, 405),
(1, CURDATE() - INTERVAL 1 DAY, 415, 5, 410),
(1, CURDATE(), 388, 4, 0);

-- Sample sales
INSERT IGNORE INTO sales (sale_date, total_amount, payment_method) VALUES
(CURDATE() - INTERVAL 6 DAY, 1250.00, 'momo_mtn'),
(CURDATE() - INTERVAL 5 DAY, 980.00, 'cash'),
(CURDATE() - INTERVAL 4 DAY, 1450.00, 'momo_vodafone'),
(CURDATE() - INTERVAL 3 DAY, 1100.00, 'momo_mtn'),
(CURDATE() - INTERVAL 2 DAY, 1600.00, 'cash'),
(CURDATE() - INTERVAL 1 DAY, 1350.00, 'momo_mtn'),
(CURDATE(), 890.00, 'momo_airteltigo');
