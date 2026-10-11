-- Coopers Farm — v23: bird products draw from a batch; eggs are counted from crate stock.
-- Run once in phpMyAdmin, after v22.
ALTER TABLE products ADD COLUMN flock_id INT NULL AFTER category, ADD INDEX (flock_id);

-- Link existing broiler and layer products to the first active batch of the same type.
-- Check the result and change any product that should use a different batch.
UPDATE products p
SET p.flock_id = (SELECT f.id FROM flocks f WHERE f.bird_type = p.category AND f.status='active' ORDER BY f.created_at LIMIT 1)
WHERE p.category IN ('broiler','layer');
