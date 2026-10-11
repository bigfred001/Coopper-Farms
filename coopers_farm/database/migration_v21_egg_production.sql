-- Coopers Farm — v21: egg production with hens present, rejects and grades.
-- Run once in phpMyAdmin. updated_by already exists from an earlier migration, so it is not added here.
ALTER TABLE egg_production
  ADD COLUMN hens_present INT NULL AFTER quantity,
  ADD COLUMN rejected_eggs INT NOT NULL DEFAULT 0 AFTER broken_eggs,
  ADD COLUMN grade_large INT NOT NULL DEFAULT 0 AFTER rejected_eggs,
  ADD COLUMN grade_medium INT NOT NULL DEFAULT 0 AFTER grade_large,
  ADD COLUMN grade_small INT NOT NULL DEFAULT 0 AFTER grade_medium;
