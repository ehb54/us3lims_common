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
  if ( ob_get_level() > 0 )
    ob_flush();
  flush();
}
