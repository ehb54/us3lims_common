<?php

if ( file_exists( __DIR__ . "/../priority_config.php" ) ) {
    include_once( __DIR__ . "/../priority_config.php" );
}

function priority_nice_string() {
    global $priority_config;
    if ( !isset( $priority_config )
         || !is_array( $priority_config )
         || !count( $priority_config )
         || !array_key_exists( "priority_sbatch", $priority_config )
        ) {
        priority_log( "priority_nice_string() returns nothing" );
        return "";
    }
    priority_log( "priority_nice_string() returns :\n" . $priority_config[ "priority_sbatch" ] );
    return $priority_config[ "priority_sbatch" ];
}

function priority_nice( $p, $msg = "" ) {
    global $priority_config;
    $ret =
        "## priority.php : --nice : $msg\n"
        . "#SBATCH --nice=$p";
    priority_log( $ret );
    $priority_config[ "priority_sbatch" ] = $ret;
    return $ret;
}

function priority_log( $msg ) {
    global $priority_config;
    if ( !isset( $priority_config )
         || !is_array( $priority_config )
         || !array_key_exists( "log", $priority_config )
         || !$priority_config[ "log" ]
         || !array_key_exists( "logfile", $priority_config )
        ) {
        return;
    }
    error_log( "$msg\n", 3, $priority_config[ "logfile" ] );
}

function priority( $analysis_type, $datasets, $job_params ) {
    global $priority_config;
    if ( !isset( $priority_config )
         || !is_array( $priority_config )
         || !count( $priority_config ) ) {
        return "";
    }
    unset( $priority_config[ "priority_sbatch" ] );

    priority_log(
        sprintf( "priority( $analysis_type, $datasets, %s )"
                 , json_encode( $job_params, JSON_PRETTY_PRINT ) )
        );
    
    $matches   = [];
    $match     = $analysis_type;
    $matches[] = $match;

    if (
        isset( $priority_config[ "datasetthreshold" ] )
        && $datasets > $priority_config[ "datasetthreshold" ]
        ) {
        $match .= "-LD";
        $matches[] = $match;
    }

    if (
        ( isset( $job_params[ "tinoise_option" ] )
          && $job_params[ "tinoise_option" ] != "0" )
        || ( isset( $job_params[ "rinoise_option" ] )
             && $job_params[ "rinoise_option" ] != "0" )
        ) {
        $match .= "-FN";
        $matches[] = $match;
    }
    
    if (
        ( isset( $job_params[ "max_iterations" ] )
          && $job_params[ "max_iterations" ] > $priority_config[ "iterationthreshold" ] )
        || ( isset( $job_params[ "gfit_iterations" ] )
             && $job_params[ "gfit_iterations" ] > $priority_config[ "iterationthreshold" ] )
        ) {
        $match .= "-IT";
        $matches[] = $match;
    }

    if (
        isset( $job_params[ "mc_iterations" ] )
        && $job_params[ "mc_iterations" ] > 1
        ) {
        $match .= "-MC";
        $matches[] = $match;
    }

    $matches = array_reverse( $matches );

    foreach ( $matches as $v ) {
        if ( array_key_exists( $v, $priority_config ) ) {
            return priority_nice( $priority_config[ $v ], "priority_config key $v" );
        }
    }
    
    if ( array_key_exists( "defaultpriority", $priority_config ) ) {
        return priority_nice(
            $priority_config[ "defaultpriority" ], "priority_config key " . $matches[0] . " not found, using defaultpriority"
            );
    }
    return priority_nice( 10000, "priority_config key " . $matches[0] . " not found and no default defined - arbitrarily set to 10000" );
}
