-- Prediction records for the runtime advisory pilot.
--
-- One table in gfac, not one per instance: a host serves several uslims3_*
-- instances and the pilot's records belong together, so every row names the
-- instance database it came from.
--
-- SCOPE. This exists for the evaluation window and is meant to be dropped
-- afterwards. The DROP is at the bottom of this file so removing it is not a
-- reconstruction job.
--
-- WHAT IS DELIBERATELY NOT HERE. No request XML, no researcher identity, no
-- scientific result content. The join keys are keys, not predictors. The
-- feature vector is stored because the evaluation needs the inputs the
-- prediction was actually made from, and it holds numbers and category codes
-- only.
--
-- A NOTE ON THE JOIN KEY. The caller does not pass the scheduler's job id,
-- so gfac_id is null at insert even though sbatch has already run by then.
-- The durable key is (us3_db, request_id); gfac_id is here so a later pass can
-- fill it in, and so a row with it set can be joined directly.

CREATE TABLE IF NOT EXISTS `runtime_prediction` (
  `id`                        int(11)      NOT NULL AUTO_INCREMENT,

  -- Provenance: which contract, which frozen model, which adapter.
  `record_schema`             varchar(16)  NOT NULL,
  `artifact_sha256`           char(64)     NOT NULL,
  `adapter_version`           varchar(32)  NOT NULL,

  -- Identity of the attempt this recommendation was made for.
  `us3_db`                    varchar(64)  NOT NULL,
  `request_id`                int(11)      NOT NULL,
  `gfac_id`                   varchar(80)  DEFAULT NULL,
  `decided_at`                datetime     NOT NULL,

  -- What was being submitted, and where.
  `family`                    varchar(16)  NOT NULL,
  `destination`               varchar(80)  NOT NULL,

  -- The inputs the prediction was made from, as JSON: numbers, category codes
  -- and explicit nulls for a value the fitted imputer handled.
  `features`                  longtext     DEFAULT NULL,

  -- The recommendation. Null where status is not 'ok'.
  `prediction_seconds`        double       DEFAULT NULL,
  `multiplier`                double       DEFAULT NULL,
  `allowance_seconds`         int(11)      DEFAULT NULL,

  -- The incumbent, recorded separately from what was actually emitted.
  `formula_reference_seconds` int(11)      DEFAULT NULL,
  `formula_version`           varchar(32)  DEFAULT NULL,
  `emitted_directive`         varchar(32)  DEFAULT NULL,
  `requested_ranks`           int(11)      DEFAULT NULL,

  -- The inherited gate's verdict, recorded but not applied to the allowance.
  `gate_accepted`             tinyint(1)   DEFAULT NULL,

  -- 'ok', or why no recommendation was produced.
  `status`                    varchar(24)  NOT NULL,
  `reason`                    varchar(255) DEFAULT NULL,

  -- Monotonic, in microseconds: the advisory path's own cost.
  `evaluate_us`               int(11)      DEFAULT NULL,

  PRIMARY KEY (`id`),
  KEY `ndx_runtime_prediction_attempt` (`us3_db`, `request_id`),
  KEY `ndx_runtime_prediction_decided` (`decided_at`),
  KEY `ndx_runtime_prediction_status`  (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- Removal, after the window and after the records have been collected:
--
--   DROP TABLE IF EXISTS `runtime_prediction`;
