<?php
/*
 * cancel_result.php
 *
 * Turns one remote_exec result into a cancellation outcome.
 *
 * WHY THIS IS ITS OWN FILE. The decision it makes is the same one the rest of
 * the outage work is built on: a fact about the transport must never become a
 * fact about the job. queue_viewer.php used to get this exactly backwards --
 * it ran a bare exec( "ssh ... scancel" ), discarded the result, and returned
 * true unconditionally, so the LIMS wrote queueStatus='aborted' whether or not
 * scancel had ever reached the cluster. A job left running on an unreachable
 * cluster then showed as cancelled, and nobody went looking for it again.
 *
 * Keeping the mapping here rather than inside the page means it can be
 * asserted without a web server, a database or a cluster, the same way
 * cluster_probe_status_from_result() is.
 */

## The job is off the cluster; the LIMS may mark it aborted.
const CANCEL_CANCELED     = 'CANCELED';     ## scancel accepted the request
const CANCEL_GONE         = 'GONE';         ## scheduler has no such job any more

## The job may well still be running; the LIMS must not claim otherwise.
const CANCEL_UNREACHABLE  = 'UNREACHABLE';  ## never got an answer from the cluster
const CANCEL_REFUSED      = 'REFUSED';      ## scheduler answered and said no
const CANCEL_UNCONFIGURED = 'UNCONFIGURED'; ## cluster is not in global_config.php

/**
 * True when an outcome means the job is definitely no longer on the cluster.
 * The single place that decides whether a cancel may be written as 'aborted'.
 */
function cancel_outcome_is_settled( $outcome )
{
   return $outcome === CANCEL_CANCELED || $outcome === CANCEL_GONE;
}

/**
 * Map a remote_exec result onto array( 'outcome' => CANCEL_*, 'message' => ... ).
 * The message is written to be shown to the user as-is.
 */
function cancel_outcome_from_result( $res, $cluster )
{
   if ( remote_exec_infra_fault( $res ) )
      return array(
         'outcome' => CANCEL_UNREACHABLE,
         'message' => "Cancel not sent: cluster $cluster did not respond, so the job may still be running."
                      . " Try again once the cluster is reachable."
      );

   if ( ! empty( $res[ 'ok' ] ) )
      return array( 'outcome' => CANCEL_CANCELED, 'message' => 'Job has been canceled' );

   ## The cluster answered, and said no. One rejection is not a failure:
   ## slurm reports an invalid job id for anything it has already forgotten,
   ## which means the job finished or was cancelled earlier. That is the
   ## outcome the user asked for, so it is reported as settled rather than
   ## leaving the row stuck in the queue with no way to clear it.
   $said = trim( ( isset( $res[ 'text' ] ) ? $res[ 'text' ] : '' )
                 . ' ' . ( isset( $res[ 'stderr' ] ) ? $res[ 'stderr' ] : '' ) );

   if ( preg_match( '/invalid job id|invalid user id|job.*not found/i', $said ) )
      return array(
         'outcome' => CANCEL_GONE,
         'message' => "Job was no longer in the queue on $cluster"
      );

   $exit = isset( $res[ 'exit_code' ] ) ? $res[ 'exit_code' ] : '?';

   return array(
      'outcome' => CANCEL_REFUSED,
      'message' => "Cancel refused by $cluster: " . ( $said !== '' ? $said : "scancel exited $exit" )
   );
}
