-- Additive migration for a gfac.runtime_prediction table created before
-- prediction_cluster/prediction_cluster_source existed (runtime_pilot_table.sql
-- now creates both directly, for a fresh table).
--
-- Both ALTERs are a no-op if the columns are already there (MariaDB 10.0.2+),
-- so running this against an already-migrated table is safe and leaves its
-- records alone, same as runtime_pilot_table.sql's own CREATE TABLE IF NOT EXISTS.

ALTER TABLE `runtime_prediction`
  ADD COLUMN IF NOT EXISTS `prediction_cluster`        varchar(80) DEFAULT NULL AFTER `destination`,
  ADD COLUMN IF NOT EXISTS `prediction_cluster_source` varchar(16) DEFAULT NULL AFTER `prediction_cluster`;
