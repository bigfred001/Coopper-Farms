-- Coopers Farm — DELETE ALL DATA, KEEP ALL TABLES.
-- Back up first (phpMyAdmin: Export → Quick → SQL). This cannot be undone.
--
-- STEP 1: run this and copy the single value in the result cell.
SET SESSION group_concat_max_len = 100000;
SELECT GROUP_CONCAT(CONCAT('TRUNCATE TABLE `', table_name, '`;') SEPARATOR ' ') AS clear_all
FROM information_schema.tables
WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE';

-- STEP 2: run the SQL:  SET FOREIGN_KEY_CHECKS = 0;
--                       then paste the copied statements and run them,
--                       then run:  SET FOREIGN_KEY_CHECKS = 1;

-- STEP 3: restore the admin login (password: password — change it after signing in)
INSERT INTO users (name, email, phone, password_hash, role) VALUES
('Farm Manager', 'admin@coopersfarm.com', '+233241234567', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin');
