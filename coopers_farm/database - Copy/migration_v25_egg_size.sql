-- Coopers Farm — v25: egg products have a size (small, medium, large).
-- Crates are then tracked per crate type and size. Run once in phpMyAdmin, after v24.
ALTER TABLE products ADD COLUMN egg_size VARCHAR(10) NULL AFTER egg_units;

-- Set the size of existing egg products from their names
UPDATE products SET egg_size = 'large'  WHERE category='eggs' AND name LIKE '%Large%';
UPDATE products SET egg_size = 'medium' WHERE category='eggs' AND name LIKE '%Medium%';
UPDATE products SET egg_size = 'small'  WHERE category='eggs' AND name LIKE '%Small%';
