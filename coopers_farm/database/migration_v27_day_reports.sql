-- Coopers Farm — v27: end-of-day report. Ending the day saves a report built from what was logged that day.
-- Run once in phpMyAdmin, after v26. (If you already ran an earlier v27 with day_counts, that table is not used and can be dropped.)
CREATE TABLE IF NOT EXISTS day_reports (
  id INT AUTO_INCREMENT PRIMARY KEY,
  day_log_id INT NOT NULL,
  generated_at DATETIME NOT NULL,
  generated_by INT NULL,
  report_json LONGTEXT NOT NULL,
  INDEX (day_log_id)
);
