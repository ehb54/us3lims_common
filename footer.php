<?php
/*
 * bottom.php
 *
 */

 // Some pages seem to need a couple of <br /> to signal 
  //  the end of the page...

date_default_timezone_set( 'America/Chicago' );
$filename = basename( $_SERVER['PHP_SELF'] );
## The legacy complete config.php sets $is_cli, but the schema-v1
## base+overlay contract has no slot for it (us3_dbinst_config_base_types()'s
## fixed key set does not include it), so it is unset here whenever gridctl's
## submitone.php includes a page like queue_setup_1.php or PCSA_1.php for a
## CLI-driven autoflow submission. filectime() would then run against
## $_SERVER['PHP_SELF'], which a CLI process never sets to a real file, and
## the warning would be reported as the submission's failure reason by
## submitone.php's check_cli_errors_in_dump() (gridctl).
if ( !isset( $is_cli ) ) {
   $is_cli = php_sapi_name() === 'cli';
}
if ( $is_cli ) {
   $modtime = "autoflow submitted";
} else {
   $modtime = date( "F d, Y", filectime( $filename ) );
}

echo<<<HTML
  <!-- end content -->
  <div class='clear-both'>&nbsp;</div>

  <!-- end page -->
  <div id="footer">
	<div id='info'>
	</div>

	  <div id='info2'><hr />Last modified on $modtime --
         <a href='license.php'>Copyright &copy; notice and license information</a> UltraScan Project
      </div>
 
  </div>
</div>

</body>
</html>

HTML;
?>
