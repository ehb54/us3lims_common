<?php
/* Map remote execution results to cancellation outcomes. */

## Cancellation accepted or job already absent; LIMS may mark it aborted.
const CANCEL_CANCELED     = 'CANCELED';     ## scancel accepted the request
const CANCEL_GONE         = 'GONE';         ## scheduler has no such job any more

## The job may well still be running; the LIMS must not claim otherwise.
const CANCEL_UNREACHABLE  = 'UNREACHABLE';  ## never got an answer from the cluster
const CANCEL_REFUSED      = 'REFUSED';      ## scheduler answered and said no
const CANCEL_UNCONFIGURED = 'UNCONFIGURED'; ## cluster is not in global_config.php

/** Whether cancellation may be recorded as aborted. */
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
   {
      return array(
         'outcome' => CANCEL_UNREACHABLE,
         'message' => "Cancel not sent: cluster $cluster did not respond, so the job may still be running."
                      . " Try again once the cluster is reachable."
      );
   }

   if ( ! empty( $res[ 'ok' ] ) )
   {
      return array( 'outcome' => CANCEL_CANCELED, 'message' => 'Job has been canceled' );
   }

   ## Treat an absent job as a settled cancellation.
   $said = trim( ( isset( $res[ 'text' ] ) ? $res[ 'text' ] : '' )
                 . ' ' . ( isset( $res[ 'stderr' ] ) ? $res[ 'stderr' ] : '' ) );

   $outcome = CANCEL_REFUSED;
   $exit = isset( $res[ 'exit_code' ] ) ? $res[ 'exit_code' ] : '?';
   $message = "Cancel refused by $cluster: " . ( $said !== '' ? $said : "scancel exited $exit" );

   if ( preg_match( '/invalid job id|job.*not found/i', $said ) )
   {
      $outcome = CANCEL_GONE;
      $message = "Job was no longer in the queue on $cluster";
   }

   return array( 'outcome' => $outcome, 'message' => $message );
}
