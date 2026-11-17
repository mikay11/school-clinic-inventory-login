-- MySQL schema for School Clinic Inventory System
-- Import this file once through phpMyAdmin (InfinityFree: MySQL Databases -> phpMyAdmin).
-- It is safe to import more than once: seed rows are inserted only when missing.
--
-- IMPORTANT FOR INFINITYFREE: replace `school_clinic_inventory` below with the database
-- name InfinityFree assigned to you (for example if0_12345678_school_clinic).
-- You can also delete the CREATE DATABASE line and select your database in phpMyAdmin first.

CREATE DATABASE IF NOT EXISTS school_clinic_inventory CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE school_clinic_inventory;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  full_name VARCHAR(120) NOT NULL,
  role VARCHAR(50) NOT NULL DEFAULT 'clinic_personnel',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_email (email),
  CONSTRAINT chk_edu_email CHECK (email REGEXP '^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\\.edu\\.ph$')
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS medicines (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  generic_name VARCHAR(150) NOT NULL DEFAULT '',
  category VARCHAR(80) NOT NULL DEFAULT 'Tablet',
  unit VARCHAR(40) NOT NULL DEFAULT 'tablet',
  location VARCHAR(80) NOT NULL DEFAULT '',
  description VARCHAR(255) NOT NULL DEFAULT '',
  low_stock_threshold INT UNSIGNED NOT NULL DEFAULT 20,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_med_name (name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS medicine_batches (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  medicine_id INT UNSIGNED NOT NULL,
  batch_no VARCHAR(60) NOT NULL,
  quantity INT UNSIGNED NOT NULL DEFAULT 0,
  received_date DATE NOT NULL,
  expiration_date DATE NOT NULL,
  supplier VARCHAR(120) NOT NULL DEFAULT '',
  remarks VARCHAR(255) NOT NULL DEFAULT '',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_batch_med (medicine_id),
  KEY idx_batch_exp (expiration_date),
  CONSTRAINT fk_batch_med FOREIGN KEY (medicine_id) REFERENCES medicines (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS inventory_activities (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  activity_type VARCHAR(30) NOT NULL,
  medicine_id INT UNSIGNED NULL,
  quantity INT NOT NULL DEFAULT 0,
  note VARCHAR(255) NULL,
  performed_by VARCHAR(120) NOT NULL DEFAULT 'Clinic Personnel',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_activity_created (created_at),
  CONSTRAINT fk_activity_med FOREIGN KEY (medicine_id) REFERENCES medicines (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notifications (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  message VARCHAR(255) NOT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS app_settings (
  setting_key VARCHAR(80) NOT NULL PRIMARY KEY,
  setting_value VARCHAR(255) NOT NULL DEFAULT '',
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS password_resets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  token_hash VARCHAR(255) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_reset_user (user_id),
  KEY idx_reset_token (token_hash),
  CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Default account. Login: nurse@school.edu.ph / Clinic@2026
-- Change this password immediately after the first login (Settings page).
-- ---------------------------------------------------------------------------
INSERT INTO users (email, password_hash, full_name, role, is_active)
SELECT 'nurse@school.edu.ph', '$2y$10$lhXPFtkh3i1cacx46YVvHuhl8l8Jtap61S09gSaloZbTiFyhdwlCa', 'Clinic Nurse', 'clinic_personnel', 1
WHERE NOT EXISTS (SELECT 1 FROM users WHERE email = 'nurse@school.edu.ph');

-- ---------------------------------------------------------------------------
-- Sample medicines
-- ---------------------------------------------------------------------------
INSERT INTO medicines (name, generic_name, category, unit, location, description, low_stock_threshold)
SELECT 'Biogesic', 'Paracetamol', 'Tablet', 'tablet', 'Cabinet A', 'Fever and mild pain relief', 20
WHERE NOT EXISTS (SELECT 1 FROM medicines WHERE name = 'Biogesic');

INSERT INTO medicines (name, generic_name, category, unit, location, description, low_stock_threshold)
SELECT 'Tempra', 'Paracetamol', 'Syrup', 'mL', 'Cabinet B', 'Fever and pain relief for children', 60
WHERE NOT EXISTS (SELECT 1 FROM medicines WHERE name = 'Tempra');

INSERT INTO medicines (name, generic_name, category, unit, location, description, low_stock_threshold)
SELECT 'Medicol', 'Mefenamic Acid', 'Capsule', 'capsule', 'Cabinet A', 'Pain and menstrual cramp relief', 20
WHERE NOT EXISTS (SELECT 1 FROM medicines WHERE name = 'Medicol');

INSERT INTO medicines (name, generic_name, category, unit, location, description, low_stock_threshold)
SELECT 'Amoxicillin', 'Amoxicillin', 'Capsule', 'capsule', 'Cabinet A', 'Antibiotic - dispense only as prescribed', 20
WHERE NOT EXISTS (SELECT 1 FROM medicines WHERE name = 'Amoxicillin');

INSERT INTO medicines (name, generic_name, category, unit, location, description, low_stock_threshold)
SELECT 'Cetirizine', 'Cetirizine', 'Tablet', 'tablet', 'Cabinet B', 'Antihistamine for allergies', 20
WHERE NOT EXISTS (SELECT 1 FROM medicines WHERE name = 'Cetirizine');

INSERT INTO medicines (name, generic_name, category, unit, location, description, low_stock_threshold)
SELECT 'Solmux', 'Carbocisteine', 'Syrup', 'mL', 'Cabinet B', 'Mucolytic for cough', 60
WHERE NOT EXISTS (SELECT 1 FROM medicines WHERE name = 'Solmux');

INSERT INTO medicines (name, generic_name, category, unit, location, description, low_stock_threshold)
SELECT 'Betadine', 'Povidone-Iodine', 'Liquid', 'mL', 'Cabinet C', 'Antiseptic for wound care', 100
WHERE NOT EXISTS (SELECT 1 FROM medicines WHERE name = 'Betadine');

INSERT INTO medicines (name, generic_name, category, unit, location, description, low_stock_threshold)
SELECT 'Hydrite', 'Oral Rehydration Salts', 'Powder', 'sachet', 'Emergency Kit', 'Rehydration for diarrhea and dehydration', 30
WHERE NOT EXISTS (SELECT 1 FROM medicines WHERE name = 'Hydrite');

-- ---------------------------------------------------------------------------
-- Sample batches (dates stay relevant because they are relative to today)
-- ---------------------------------------------------------------------------
INSERT INTO medicine_batches (medicine_id, batch_no, quantity, received_date, expiration_date, supplier, remarks)
SELECT (SELECT id FROM medicines WHERE name = 'Biogesic'), 'BT-BIO-2601', 120,
       DATE_SUB(CURDATE(), INTERVAL 90 DAY), DATE_ADD(CURDATE(), INTERVAL 275 DAY),
       'City Health Office', 'Initial stock'
WHERE NOT EXISTS (SELECT 1 FROM medicine_batches WHERE batch_no = 'BT-BIO-2601');

INSERT INTO medicine_batches (medicine_id, batch_no, quantity, received_date, expiration_date, supplier, remarks)
SELECT (SELECT id FROM medicines WHERE name = 'Tempra'), 'BT-TEM-2602', 15,
       DATE_SUB(CURDATE(), INTERVAL 120 DAY), DATE_ADD(CURDATE(), INTERVAL 45 DAY),
       'City Health Office', 'Below minimum level'
WHERE NOT EXISTS (SELECT 1 FROM medicine_batches WHERE batch_no = 'BT-TEM-2602');

INSERT INTO medicine_batches (medicine_id, batch_no, quantity, received_date, expiration_date, supplier, remarks)
SELECT (SELECT id FROM medicines WHERE name = 'Medicol'), 'BT-MED-2603', 8,
       DATE_SUB(CURDATE(), INTERVAL 200 DAY), DATE_ADD(CURDATE(), INTERVAL 12 DAY),
       'District Health Unit', 'Needs restocking'
WHERE NOT EXISTS (SELECT 1 FROM medicine_batches WHERE batch_no = 'BT-MED-2603');

INSERT INTO medicine_batches (medicine_id, batch_no, quantity, received_date, expiration_date, supplier, remarks)
SELECT (SELECT id FROM medicines WHERE name = 'Amoxicillin'), 'BT-AMX-2604', 60,
       DATE_SUB(CURDATE(), INTERVAL 40 DAY), DATE_ADD(CURDATE(), INTERVAL 200 DAY),
       'City Health Office', ''
WHERE NOT EXISTS (SELECT 1 FROM medicine_batches WHERE batch_no = 'BT-AMX-2604');

INSERT INTO medicine_batches (medicine_id, batch_no, quantity, received_date, expiration_date, supplier, remarks)
SELECT (SELECT id FROM medicines WHERE name = 'Cetirizine'), 'BT-CET-2605', 40,
       DATE_SUB(CURDATE(), INTERVAL 30 DAY), DATE_ADD(CURDATE(), INTERVAL 400 DAY),
       'Private Donation', ''
WHERE NOT EXISTS (SELECT 1 FROM medicine_batches WHERE batch_no = 'BT-CET-2605');

INSERT INTO medicine_batches (medicine_id, batch_no, quantity, received_date, expiration_date, supplier, remarks)
SELECT (SELECT id FROM medicines WHERE name = 'Solmux'), 'BT-SOL-2511', 24,
       DATE_SUB(CURDATE(), INTERVAL 400 DAY), DATE_SUB(CURDATE(), INTERVAL 10 DAY),
       'District Health Unit', 'For disposal'
WHERE NOT EXISTS (SELECT 1 FROM medicine_batches WHERE batch_no = 'BT-SOL-2511');

INSERT INTO medicine_batches (medicine_id, batch_no, quantity, received_date, expiration_date, supplier, remarks)
SELECT (SELECT id FROM medicines WHERE name = 'Betadine'), 'BT-BET-2606', 20,
       DATE_SUB(CURDATE(), INTERVAL 60 DAY), DATE_ADD(CURDATE(), INTERVAL 300 DAY),
       'City Health Office', ''
WHERE NOT EXISTS (SELECT 1 FROM medicine_batches WHERE batch_no = 'BT-BET-2606');

INSERT INTO medicine_batches (medicine_id, batch_no, quantity, received_date, expiration_date, supplier, remarks)
SELECT (SELECT id FROM medicines WHERE name = 'Hydrite'), 'BT-HYD-2607', 100,
       DATE_SUB(CURDATE(), INTERVAL 15 DAY), DATE_ADD(CURDATE(), INTERVAL 150 DAY),
       'Emergency Stock Program', ''
WHERE NOT EXISTS (SELECT 1 FROM medicine_batches WHERE batch_no = 'BT-HYD-2607');

-- ---------------------------------------------------------------------------
-- Sample activity log
-- ---------------------------------------------------------------------------
INSERT INTO inventory_activities (activity_type, medicine_id, quantity, note, performed_by)
SELECT 'add_medicine', (SELECT id FROM medicines WHERE name = 'Biogesic'), 120,
       'New medicine added with initial stock (batch BT-BIO-2601)', 'Clinic Nurse'
WHERE NOT EXISTS (SELECT 1 FROM inventory_activities WHERE note = 'New medicine added with initial stock (batch BT-BIO-2601)');

INSERT INTO inventory_activities (activity_type, medicine_id, quantity, note, performed_by)
SELECT 'stock_out', (SELECT id FROM medicines WHERE name = 'Biogesic'), -6,
       'Batch BT-BIO-2601 - dispensed to student', 'Clinic Nurse'
WHERE NOT EXISTS (SELECT 1 FROM inventory_activities WHERE note = 'Batch BT-BIO-2601 - dispensed to student');

INSERT INTO inventory_activities (activity_type, medicine_id, quantity, note, performed_by)
SELECT 'stock_in', (SELECT id FROM medicines WHERE name = 'Amoxicillin'), 60,
       'Batch BT-AMX-2604 received from City Health Office', 'Clinic Nurse'
WHERE NOT EXISTS (SELECT 1 FROM inventory_activities WHERE note = 'Batch BT-AMX-2604 received from City Health Office');

-- ---------------------------------------------------------------------------
-- Default settings and starter notifications
-- ---------------------------------------------------------------------------
INSERT INTO app_settings (setting_key, setting_value) SELECT 'clinic_name', 'School Clinic' WHERE NOT EXISTS (SELECT 1 FROM app_settings WHERE setting_key = 'clinic_name');
INSERT INTO app_settings (setting_key, setting_value) SELECT 'clinic_address', 'Main Campus, School Health Office' WHERE NOT EXISTS (SELECT 1 FROM app_settings WHERE setting_key = 'clinic_address');
INSERT INTO app_settings (setting_key, setting_value) SELECT 'clinic_contact', '' WHERE NOT EXISTS (SELECT 1 FROM app_settings WHERE setting_key = 'clinic_contact');
INSERT INTO app_settings (setting_key, setting_value) SELECT 'low_stock_threshold', '20' WHERE NOT EXISTS (SELECT 1 FROM app_settings WHERE setting_key = 'low_stock_threshold');
INSERT INTO app_settings (setting_key, setting_value) SELECT 'expiry_window', '30' WHERE NOT EXISTS (SELECT 1 FROM app_settings WHERE setting_key = 'expiry_window');
INSERT INTO app_settings (setting_key, setting_value) SELECT 'notify_low_stock', '1' WHERE NOT EXISTS (SELECT 1 FROM app_settings WHERE setting_key = 'notify_low_stock');
INSERT INTO app_settings (setting_key, setting_value) SELECT 'notify_expiring', '1' WHERE NOT EXISTS (SELECT 1 FROM app_settings WHERE setting_key = 'notify_expiring');
INSERT INTO app_settings (setting_key, setting_value) SELECT 'notify_expired', '1' WHERE NOT EXISTS (SELECT 1 FROM app_settings WHERE setting_key = 'notify_expired');
INSERT INTO app_settings (setting_key, setting_value) SELECT 'notify_summary', '0' WHERE NOT EXISTS (SELECT 1 FROM app_settings WHERE setting_key = 'notify_summary');
INSERT INTO app_settings (setting_key, setting_value) SELECT 'items_per_page', '10' WHERE NOT EXISTS (SELECT 1 FROM app_settings WHERE setting_key = 'items_per_page');
INSERT INTO app_settings (setting_key, setting_value) SELECT 'date_format', 'M j, Y' WHERE NOT EXISTS (SELECT 1 FROM app_settings WHERE setting_key = 'date_format');
INSERT INTO app_settings (setting_key, setting_value) SELECT 'landing_page', 'dashboard' WHERE NOT EXISTS (SELECT 1 FROM app_settings WHERE setting_key = 'landing_page');

INSERT INTO notifications (message, is_read)
SELECT 'Welcome to the School Clinic Inventory System. Low stock and expiration alerts will appear here.', 1
WHERE NOT EXISTS (SELECT 1 FROM notifications WHERE message = 'Welcome to the School Clinic Inventory System. Low stock and expiration alerts will appear here.');

INSERT INTO notifications (message, is_read)
SELECT 'Batch BT-SOL-2511 of Solmux has expired. Please arrange disposal.', 0
WHERE NOT EXISTS (SELECT 1 FROM notifications WHERE message = 'Batch BT-SOL-2511 of Solmux has expired. Please arrange disposal.');
