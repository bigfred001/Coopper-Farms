-- Coopers Farm — v24: feed is costed from the quantity bought and the total paid.
-- unit_cost keeps more decimals so 500 for 30 kg (16.6667/kg) is not rounded away.
-- Run once in phpMyAdmin.
ALTER TABLE feed_stock MODIFY COLUMN unit_cost DECIMAL(10,4) NULL DEFAULT 0;
