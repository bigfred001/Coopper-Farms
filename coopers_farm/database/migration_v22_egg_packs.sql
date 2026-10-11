-- Coopers Farm — v22: eggs per pack on egg products, and packing of eggs with carry-over.
-- Run once in phpMyAdmin.
ALTER TABLE products ADD COLUMN egg_units INT NULL;

-- Existing egg products get their size from their name (crate of 30, half crate = 15)
UPDATE products SET egg_units = 30 WHERE category='eggs' AND name LIKE '%Crate of 30%';
UPDATE products SET egg_units = 15 WHERE category='eggs' AND name LIKE '%Half%';

CREATE TABLE IF NOT EXISTS egg_pack_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  production_id INT NOT NULL,          -- egg_production.id that made the packs; -1 = opening balance
  product_id INT NULL,
  packs INT NOT NULL DEFAULT 0,
  eggs INT NOT NULL DEFAULT 0,         -- packs x eggs per pack
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (production_id)
);

-- Eggs already added to stock as crates by the old system count as packed, so nothing is added twice.
INSERT INTO egg_pack_log (production_id, product_id, packs, eggs)
SELECT -1, NULL, 0, COALESCE(SUM(quantity - broken_eggs - rejected_eggs), 0) FROM egg_production;
