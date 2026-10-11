-- Coopers Farm — v27: end-of-day counts. Each count is saved against the day it closes.
-- Run once in phpMyAdmin, after v26.
CREATE TABLE IF NOT EXISTS day_counts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  day_log_id INT NOT NULL,
  item_type ENUM('egg','batch','feed','cash') NOT NULL,
  item_id INT NOT NULL DEFAULT 0,
  item_name VARCHAR(160) NOT NULL,
  unit VARCHAR(12) NOT NULL,
  system_qty DECIMAL(12,2) NOT NULL,
  counted_qty DECIMAL(12,2) NOT NULL,
  difference DECIMAL(12,2) NOT NULL,
  reason VARCHAR(60) NULL,
  note VARCHAR(255) NULL,
  counted_by INT NULL,
  counted_at DATETIME NOT NULL,
  INDEX (day_log_id)
);
