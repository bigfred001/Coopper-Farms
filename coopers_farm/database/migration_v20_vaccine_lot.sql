-- Coopers Farm — v20: store vaccine lot number and expiry on each vaccination record.
-- Run once in phpMyAdmin after migration_v19_vaccination_rounds.sql.
ALTER TABLE vaccinations
  ADD COLUMN lot_number VARCHAR(60) NULL AFTER dosage,
  ADD COLUMN lot_expiry DATE NULL AFTER lot_number;
