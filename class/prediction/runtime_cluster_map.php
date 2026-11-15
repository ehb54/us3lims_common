<?php
/*
 * runtime_cluster_map.php
 *
 * Destination name to the numeric code the runtime model was fitted with.
 *
 * WHY A CODE AT ALL. The model's cluster input is one indicator per training
 * code, labelled cluster==10.0 and so on. A submission names its destination,
 * so something has to turn the name into the code before the prediction is
 * made. Afterwards is too late: a prediction with no indicator set is not the
 * model, it is an input the model never saw.
 *
 * WHERE THIS CAME FROM. uslims_metadata_format.json in dbutils, under
 * job.cluster.@attributes.name, which is the table the extraction used to
 * assign these codes and the same one the exporter reads when it fits. It is
 * reproduced here rather than read at run time for two reasons: that file sits
 * under ~us3, which the web tier is not guaranteed to read, and a file the
 * advisory reads live could change mid-window and move the predictions without
 * leaving any trace in the records.
 *
 * Codes are historical assignments and do not change for a cluster that
 * already has one. Regenerate from dbutils if a cluster is ever added:
 *
 *   jq '.. | objects | ."job.cluster.@attributes.name"? // empty' \
 *      uslims_metadata_format.json
 *
 * A change here changes predictions, so it must come with a bump to
 * runtime_record::ADAPTER_VERSION. The artifact hash does not cover this file;
 * the adapter version is what covers it, and every record carries both.
 *
 * PILOT SCOPE IS NOT EXPRESSED HERE. This says which codes exist, not which
 * destinations the evaluation covers. Those are different facts and holding
 * them in one place is how they drift apart. The population belongs to the
 * readout.
 */

class runtime_cluster_map
{
   /** Name to code, lower case. Several names can share a code: a renamed host keeps its identity. */
   private static $codes = array(
      'alamo.uthscsa.edu' => 1,
      'anvil.rcac.purdue.edu' => 2,
      'aucserver.plantbio.lu.se' => 3,
      'bcf.uthscsa.edu' => 4,
      'bridges2.psc.edu' => 5,
      'chinook.hs.umt.edu' => 6,
      'comet.sdsc.edu' => 7,
      'comet.sdsc.xsede.org' => 7,
      'demeler1.uleth.ca' => 8,
      'demeler3.uleth.ca' => 9,
      'demeler9.uleth.ca' => 10,
      'uslims.uleth.ca' => 10,
      'expanse.sdsc.edu' => 11,
      'gordon.sdsc.edu' => 12,
      'gordon.sdsc.xsede.org' => 13,
      'h7-380489.ad.psu.edu' => 14,
      'h7-380489.huck.psu.edu' => 14,
      'jetstream.jetdomain' => 15,
      'js-157-184.jetstream-cloud.org' => 16,
      'js-169-137.jetstream-cloud.org' => 16,
      'jureca.fz-juelich.de' => 17,
      'juropa.fz-juelich.de' => 18,
      'juwels.fz-juelich.de' => 19,
      'login.gscc.umt.edu' => 20,
      'lonestar5.tacc.teragrid.org' => 21,
      'ls5.tacc.utexas.edu' => 21,
      'lonestar.tacc.teragrid.org' => 22,
      'ls6.tacc.utexas.edu' => 23,
      'puhti.csc.fi' => 24,
      'stampede2.tacc.xsede.org' => 25,
      'stampede.tacc.xsede.org' => 26,
      'taito.csc.fi' => 27,
      'trestles.sdsc.edu' => 28,
      'ultrascan.chemie.uni-konstanz.de' => 29,
      'us-lims1.aalto.fi' => 30,
      'us-lims2.aalto.fi' => 31,
      'uslimstest.genapp.rocks' => 32,
      'bic-auc-1' => 33,
      'bic-auc-1.canterbury.ac.nz' => 33,
      'nrch.umt.edu' => 34,
      'mvtpart43.mvt.uni-erlangen.de' => 35,
   );

   /**
    * The code for a destination name, or null when it has none.
    *
    * Case-insensitive, matching the recode rule the extraction used, so a
    * request naming the host in any case resolves the same way.
    */
   public static function code( $cluster_name )
   {
      $wanted = strtolower( trim( (string) $cluster_name ) );

      if ( $wanted === '' )
      {
         return null;
      }

      return array_key_exists( $wanted, self::$codes ) ? (float) self::$codes[ $wanted ] : null;
   }

   /** Every name that resolves, for a deployment check. */
   public static function names()
   {
      return array_keys( self::$codes );
   }
}
