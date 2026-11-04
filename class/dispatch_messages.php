<?php
/* User-facing dispatch messages, from a request's persisted state and reason
 * code, never from its free-text reason, which holds operator diagnostics.
 * Used by the queue pages, the autoflow status and the operator dashboard.
 * Returns plain text; callers escape it for HTML. PHP 7.2 compatible. */

include_once __DIR__ . '/dispatch_contract.php';

/**
 * $r: state, reasonCode, and where known selectedCluster, schedulerJobID,
 * retryAt (UTC), notifiedAt, cancelRequested.
 */
function dispatch_public_message( array $r )
{
   $code = isset( $r[ 'reasonCode' ] ) ? (string) $r[ 'reasonCode' ] : '';
   $where = ! empty( $r[ 'selectedCluster' ] ) ? $r[ 'selectedCluster' ] : 'the selected destination';
   $job = isset( $r[ 'schedulerJobID' ] ) ? (string) $r[ 'schedulerJobID' ] : '';
   $next = ! empty( $r[ 'retryAt' ] ) ? " The next check is at {$r['retryAt']} UTC." : '';

   switch ( $r[ 'state' ] ) {
      case DISPATCH_PENDING:
      case DISPATCH_ASSESSING:
         $text = 'Analysis received. Checking available resources.';
         break;
      case DISPATCH_WAITING:
         $waits = array(
            'unreachable'          => 'The destination could not be reached. No job was submitted; another attempt is scheduled.',
            'local_failure'        => 'The analysis could not be prepared for submission on this server. No job was submitted; another attempt is scheduled.',
            'availability_unknown' => 'Resource availability could not be checked. Your analysis is waiting; the next check is scheduled.',
            'occupied'             => 'No permitted resource can start this analysis now. Your analysis is waiting for one.',
            'operator'             => 'The permitted resources are taken out of service by the site operator. Your analysis is waiting for one to return.',
            'site_blocked'         => 'Job submission is paused at this site. Your analysis is submitted when it reopens.',
            'busy_deciding'        => 'Another analysis is being placed; yours is next.',
            'no_permission'        => 'Your permitted destinations changed; checking them again.',
         );
         $text = ( isset( $waits[ $code ] ) ? $waits[ $code ] : 'Waiting for dispatch. No job has been submitted yet.' ) . $next;
         if ( ! empty( $r[ 'notifiedAt' ] ) ) {
            $text .= ' This analysis has been waiting longer than usual.';
         }
         break;
      case DISPATCH_STAGING:
         $text = "Preparing the analysis for $where.";
         break;
      case DISPATCH_SUBMITTING:
         $text = "Submitting to $where.";
         break;
      case DISPATCH_SUBMISSION_UNKNOWN:
         $text = 'Submission confirmation was lost. We are checking whether the job was accepted. Do not resubmit.';
         break;
      case DISPATCH_ACCEPTED:
         $text = "The scheduler accepted your analysis at $where as job $job. LIMS is restoring tracking.";
         break;
      case DISPATCH_MONITORED:
         $text = "Submitted to $where as job $job. Waiting for the scheduler.";
         break;
      case DISPATCH_BLOCKED:
         $blocks = array(
            'no_permission' => 'This account has no permitted destination for this analysis. Contact your administrator.',
            'no_fit'        => 'This analysis exceeds the limits of the permitted resources.',
            'input'         => 'The analysis input is missing or changed since it was received. Please resubmit.',
            'configuration' => 'The metascheduler pool is not configured correctly. Contact your administrator.',
         );
         $text = isset( $blocks[ $code ] ) ? $blocks[ $code ] : 'This analysis needs administrator attention.';
         break;
      case DISPATCH_FAILED:
         $fails = array(
            'pool_disabled'    => 'Not submitted: the metascheduler pool is disabled. Choose a cluster and resubmit.',
            'rejected'         => 'Not submitted: the destination rejected this analysis.',
            'staging_rejected' => 'Not submitted: the destination refused the analysis files.',
            'interrupted'      => 'Not submitted: the submission was interrupted. Please resubmit.',
            'operator_close'   => 'The submission could not be confirmed and an administrator closed this request. Resubmit it if you still need it.',
         );
         $text = isset( $fails[ $code ] ) ? $fails[ $code ] : 'Not submitted.';
         break;
      case DISPATCH_EXPIRED:
         $text = 'Not submitted: no cluster was available within the maximum wait. Please resubmit.';
         break;
      case DISPATCH_CANCELLED:
         $text = isset( $r[ 'cancelRequested' ] ) && (int) $r[ 'cancelRequested' ] === 2
               ? 'Cancelled at the scheduler.' : 'Cancelled before submission.';
         break;
      case DISPATCH_CLOSED:
         $text = 'Finished.';
         break;
      default:
         $text = (string) $r[ 'state' ];
   }
   if ( isset( $r[ 'cancelRequested' ] ) && (int) $r[ 'cancelRequested' ] === 1 && ! dispatch_state_is_terminal( $r[ 'state' ] ) ) {
      $text .= ' Cancellation requested; it completes once the submission outcome is known.';
   }
   return $text;
}
