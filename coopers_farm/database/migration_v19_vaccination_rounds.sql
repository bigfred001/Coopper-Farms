-- Coopers Farm — v19: vaccination rounds reach 100% before "completed"
-- Run once in phpMyAdmin (or mysql CLI) before deploying v19 pages.
ALTER TABLE vaccinations
  MODIFY COLUMN status ENUM('completed','scheduled','overdue','missed','in_progress')
  NOT NULL DEFAULT 'completed';
