-- Coopers Farm — v26: start and end of day.
-- Each start and end is one row. Only one day can be open at a time. Run once in phpMyAdmin.
CREATE TABLE IF NOT EXISTS day_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  day_date DATE NOT NULL,
  status ENUM('open','closed') NOT NULL DEFAULT 'open',
  started_at DATETIME NOT NULL,
  started_by INT NULL,
  ended_at DATETIME NULL,
  ended_by INT NULL,
  INDEX (status)
);
