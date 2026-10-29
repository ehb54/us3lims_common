<?php
/* Dispatch ledger tables: the one definition used by upgrade migrations, new
 * instance creation and tests. Instance tables hold requests, attempts and
 * events; gfac tables hold controller claims, rotation state, turn events and
 * decisions. All InnoDB, all times UTC from the database server. PHP 7.2 compatible. */

function dispatch_instance_table_ddl( $db )
{
   $states = "'PENDING','ASSESSING','WAITING','BLOCKED','STAGING','SUBMITTING','SUBMISSION_UNKNOWN',"
           . "'ACCEPTED','MONITORED','CLOSED','FAILED','EXPIRED','CANCELLED'";
   return array(
      'dispatch_request' => "CREATE TABLE IF NOT EXISTS `$db`.`dispatch_request` (
         dispatchRequestID int(11) NOT NULL AUTO_INCREMENT,
         logicalKey varchar(191) NOT NULL,
         HPCAnalysisRequestID int(11) DEFAULT NULL,
         autoflowAnalysisID int(11) DEFAULT NULL,
         stageName varchar(32) NOT NULL DEFAULT 'interactive',
         stageOccurrence int(11) NOT NULL DEFAULT 1,
         parentBatchKey varchar(191) DEFAULT NULL,
         batchSealed tinyint(1) NOT NULL DEFAULT 1,
         submitterID int(11) NOT NULL,
         investigatorID int(11) NOT NULL,
         pool varchar(64) NOT NULL,
         pinnedCluster varchar(64) DEFAULT NULL,
         state enum($states) NOT NULL DEFAULT 'PENDING',
         claimOwner varchar(128) DEFAULT NULL,
         claimGeneration int(11) NOT NULL DEFAULT 0,
         claimExpiresAt datetime DEFAULT NULL,
         cancelRequested tinyint(1) NOT NULL DEFAULT 0,
         retryAt datetime DEFAULT NULL,
         deadlineAt datetime NOT NULL,
         notifyAt datetime DEFAULT NULL,
         notifiedAt datetime DEFAULT NULL,
         policyVersion varchar(16) DEFAULT NULL,
         planHash varchar(16) DEFAULT NULL,
         selectedCluster varchar(64) DEFAULT NULL,
         inputRef varchar(255) NOT NULL DEFAULT '',
         inputChecksum varchar(64) NOT NULL,
         intakeChecksum varchar(64) NOT NULL DEFAULT '',
         priorityDirective text,
         reason text,
         reasonCode varchar(32) DEFAULT NULL,
         createdAt datetime NOT NULL,
         updatedAt datetime NOT NULL,
         PRIMARY KEY (dispatchRequestID),
         UNIQUE KEY logicalKey (logicalKey),
         KEY ready (state, retryAt),
         KEY batch (parentBatchKey),
         KEY request (HPCAnalysisRequestID)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Dispatch requests (one per HPC request or batch child)'",
      'dispatch_attempt' => "CREATE TABLE IF NOT EXISTS `$db`.`dispatch_attempt` (
         attemptID int(11) NOT NULL AUTO_INCREMENT,
         dispatchRequestID int(11) NOT NULL,
         attemptToken varchar(40) NOT NULL,
         cluster varchar(64) NOT NULL,
         planHash varchar(16) NOT NULL,
         planJson mediumtext NOT NULL,
         artifactChecksum varchar(64) DEFAULT NULL,
         phase enum('INTENT','ACCEPTED','NOT_ACCEPTED','UNKNOWN') NOT NULL DEFAULT 'INTENT',
         schedulerJobID varchar(80) DEFAULT NULL,
         outcomeReason varchar(255) DEFAULT NULL,
         createdAt datetime NOT NULL,
         resolvedAt datetime DEFAULT NULL,
         PRIMARY KEY (attemptID),
         UNIQUE KEY attemptToken (attemptToken),
         UNIQUE KEY schedulerJob (cluster, schedulerJobID),
         KEY request (dispatchRequestID)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Submission attempts: intent before sbatch, then outcome'",
      'dispatch_event' => "CREATE TABLE IF NOT EXISTS `$db`.`dispatch_event` (
         eventID int(11) NOT NULL AUTO_INCREMENT,
         dispatchRequestID int(11) NOT NULL,
         fromState varchar(24) DEFAULT NULL,
         toState varchar(24) NOT NULL,
         claimGeneration int(11) NOT NULL,
         reason text,
         createdAt datetime NOT NULL,
         PRIMARY KEY (eventID),
         KEY request (dispatchRequestID)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Append-only dispatch state history'",
   );
}

function dispatch_gfac_table_ddl( $gdb = 'gfac' )
{
   return array(
      'dispatch_controller_claim' => "CREATE TABLE IF NOT EXISTS `$gdb`.`dispatch_controller_claim` (
         controller varchar(128) NOT NULL,
         claimOwner varchar(128) DEFAULT NULL,
         claimGeneration int(11) NOT NULL DEFAULT 0,
         claimExpiresAt datetime DEFAULT NULL,
         PRIMARY KEY (controller)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='One availability-decision lane per shared scheduler'",
      'dispatch_rotation' => "CREATE TABLE IF NOT EXISTS `$gdb`.`dispatch_rotation` (
         scopeKey varchar(191) NOT NULL,
         stateJson text NOT NULL,
         updatedAt datetime NOT NULL,
         PRIMARY KEY (scopeKey)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Rotation state per instance, pool, group and policy version'",
      'dispatch_turn_event' => "CREATE TABLE IF NOT EXISTS `$gdb`.`dispatch_turn_event` (
         turnEventID int(11) NOT NULL AUTO_INCREMENT,
         scopeKey varchar(191) NOT NULL,
         unitKey varchar(191) NOT NULL,
         member varchar(64) NOT NULL,
         roundTotal int(11) NOT NULL,
         attemptToken varchar(40) DEFAULT NULL,
         status enum('PROVISIONAL','CONFIRMED','REFUNDED') NOT NULL DEFAULT 'PROVISIONAL',
         createdAt datetime NOT NULL,
         resolvedAt datetime DEFAULT NULL,
         PRIMARY KEY (turnEventID),
         UNIQUE KEY unit (scopeKey, unitKey)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='One turn per dispatch unit: spent, confirmed or refunded once'",
      'dispatch_decision' => "CREATE TABLE IF NOT EXISTS `$gdb`.`dispatch_decision` (
         decisionID int(11) NOT NULL AUTO_INCREMENT,
         instanceDb varchar(64) NOT NULL,
         dispatchRequestID int(11) NOT NULL,
         pool varchar(64) NOT NULL,
         policyVersion varchar(16) NOT NULL,
         outcome varchar(16) NOT NULL,
         chosen varchar(64) DEFAULT NULL,
         reason text,
         candidatesJson mediumtext,
         policyJson text,
         createdAt datetime NOT NULL,
         PRIMARY KEY (decisionID),
         KEY request (instanceDb, dispatchRequestID),
         KEY created (createdAt)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Request-linked dispatch decision history'",
      'dispatch_alert' => "CREATE TABLE IF NOT EXISTS `$gdb`.`dispatch_alert` (
         alertID int(11) NOT NULL AUTO_INCREMENT,
         dedupKey varchar(191) NOT NULL,
         kind varchar(32) NOT NULL,
         urgency enum('urgent','normal') NOT NULL,
         destination varchar(64) DEFAULT NULL,
         instanceDb varchar(64) DEFAULT NULL,
         detail text,
         firstAt datetime NOT NULL,
         lastAt datetime NOT NULL,
         resolvedAt datetime DEFAULT NULL,
         resolution varchar(255) DEFAULT NULL,
         emailedAt datetime DEFAULT NULL,
         openFlag tinyint(1) DEFAULT 1,
         PRIMARY KEY (alertID),
         UNIQUE KEY openItem (dedupKey, openFlag),
         KEY instance (instanceDb, resolvedAt)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Operator alert items; one open item per condition'",
      'dispatch_alert_request' => "CREATE TABLE IF NOT EXISTS `$gdb`.`dispatch_alert_request` (
         alertID int(11) NOT NULL,
         instanceDb varchar(64) NOT NULL,
         dispatchRequestID int(11) NOT NULL,
         PRIMARY KEY (alertID, instanceDb, dispatchRequestID)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Requests affected by an alert item, per instance'",
      'dispatch_heartbeat' => "CREATE TABLE IF NOT EXISTS `$gdb`.`dispatch_heartbeat` (
         worker varchar(128) NOT NULL,
         beatAt datetime NOT NULL,
         PRIMARY KEY (worker)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Dispatch worker liveness, checked independently'",
   );
}

## Which database server a connection reached. Two connections with the same
## identity can share one transaction across gfac and instance databases.
function dispatch_server_identity( $link )
{
   try {
      $res = mysqli_query( $link, 'SELECT @@hostname, @@port, @@datadir' );
   }
   catch ( mysqli_sql_exception $e ) {
      return '';
   }
   $row = $res ? mysqli_fetch_row( $res ) : null;
   return $row ? implode( ':', $row ) : '';
}

## Create any missing tables; returns a list of errors (empty on success).
function dispatch_schema_ensure( $link, array $ddl )
{
   $errors = array();
   foreach ( $ddl as $table => $sql ) {
      if ( ! @mysqli_query( $link, $sql ) ) {
         $errors[] = "$table: " . mysqli_error( $link );
      }
   }
   return $errors;
}

## Tables missing or not InnoDB in $db; empty when the schema is ready.
function dispatch_schema_problems( $link, $db, array $tables )
{
   $problems = array();
   foreach ( $tables as $table ) {
      $stmt = mysqli_prepare( $link,
         'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?' );
      mysqli_stmt_bind_param( $stmt, 'ss', $db, $table );
      mysqli_stmt_execute( $stmt );
      mysqli_stmt_bind_result( $stmt, $engine );
      $found = mysqli_stmt_fetch( $stmt );
      mysqli_stmt_close( $stmt );
      if ( ! $found ) {
         $problems[] = "$db.$table is missing";
      }
      elseif ( strcasecmp( (string) $engine, 'InnoDB' ) !== 0 ) {
         $problems[] = "$db.$table uses $engine, not InnoDB";
      }
   }
   return $problems;
}
