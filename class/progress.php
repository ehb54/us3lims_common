<?php
// Submission progress as plain markup, so CSP allows it; common.css shows only the newest line.
function submit_progress( $msg )
{
  static $styled = false;

  if ( PHP_SAPI === 'cli' )
    return;

  if ( ! $styled )
  {
    echo "<link rel='stylesheet' type='text/css' href='css/common.css' />\n";
    $styled = true;
  }

  echo "<div class='submit-progress'>" . htmlspecialchars( $msg ) . "</div>\n";

  // A buffer not started with PHP_OUTPUT_HANDLER_FLUSHABLE (e.g. gridctl
  // submitone.php's) raises a notice on ob_flush(); ob_get_level() alone
  // doesn't tell flushable apart from that.
  $status = ob_get_status();
  if ( ! empty( $status ) && ( $status[ 'flags' ] & PHP_OUTPUT_HANDLER_FLUSHABLE ) )
    ob_flush();
  flush();
}
