-- Coopers Farm — v28: every export of an end-of-day report is logged. Run once in phpMyAdmin, after v27.
CREATE TABLE IF NOT EXISTS report_exports (
  id INT AUTO_INCREMENT PRIMARY KEY,
  day_report_id INT NOT NULL,
  format ENUM('csv','excel','pdf') NOT NULL,
  exported_by INT NULL,
  exported_by_name VARCHAR(120) NULL,
  exported_at DATETIME NOT NULL,
  ip_address VARCHAR(45) NULL,
  INDEX (day_report_id)
);
