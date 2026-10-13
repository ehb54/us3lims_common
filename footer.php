<?php
/*
 * bottom.php
 *
 */

 // Some pages seem to need a couple of <br /> to signal 
  //  the end of the page...

date_default_timezone_set( 'America/Chicago' );
$filename = basename( $_SERVER['PHP_SELF'] );
## $is_cli used to only ever come from config.php (config.php.template's own
## $is_cli = php_sapi_name() == 'cli'), which is reliable for a dbinstance
## still on the legacy complete config.php -- but the schema-v1 base+overlay
## contract has no slot for it (us3_dbinst_config_base_types()'s fixed key
## set does not include it), so any instance on that contract, fresh or
## migrated, lost it entirely. That left $is_cli unset here whenever
## gridctl's submitone.php includes a page like queue_setup_1.php or
## PCSA_1.php as part of a CLI-driven autoflow submission (round-13 should
## fix, dbinst#57): filectime() then ran against $_SERVER['PHP_SELF'], which
## a CLI process never sets to a real file, emitting a warning that
## submitone.php's own check_cli_errors_in_dump() (gridctl) then picked up
## as this submission's own failure reason, masking whatever actually
## failed. php_sapi_name() answers the same question config.php's own
## assignment does, directly, with nothing to lose if $is_cli was never set.
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
