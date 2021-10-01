<?php

#    Her idea for a priorisation was:
#    High priority: Fit noise in ordinary 2DSA analysis
#    MidHigh priority: Iterative and MC method in ordinary 2DSA analysis, Fit Noise for MWL 2DSA
#    Medium priority: Custom Grid analyses, Fit Noise for PCSA and GeneticAlgorithm
#    MidLow priority: PCSA (except PCSA MC)
#    Low priority: GeneticAlgorithm and PCSA MC
    

if ( file_exists( __DIR__ . "/../priority_config.php" ) ) {
    include_once( __DIR__ . "/../priority_config.php" );
}

## temp def to be put in priority_config.php
$priority_config =
    [
# global options first
     "defaultpriority"     => 10000
     ,"datasetthreshold"   => 5
     ,"iterationthreshold" => 4
     ,"log"                => true
     ,"logfile"            => "/tmp/priority.log"

     ,"2DSA"               => 100
     ,"2DSA-LD"            => 200
     ,"2DSA-LD-FN"         => 300
     ,"2DSA-LD-FN-IT"      => 400
     ,"2DSA-LD-IT"         => 600
     ,"2DSA-LD-IT-MC"      => 700
     ,"2DSA-FN"            => 800
     ,"2DSA-FN-IT"         => 900
     ,"2DSA-IT"            => 1200
     ,"2DSA-IT-MC"         => 1300
     ,"2DSA-MC"            => 1400

     ,"PCSA"               => 100
     ,"PCSA-LD"            => 200
     ,"PCSA-LD-FN"         => 300
     ,"PCSA-LD-FN-IT"      => 400
     ,"PCSA-LD-IT"         => 600
     ,"PCSA-LD-IT-MC"      => 700
     ,"PCSA-FN"            => 800
     ,"PCSA-FN-IT"         => 900
     ,"PCSA-IT"            => 1200
     ,"PCSA-IT-MC"         => 1300
     ,"PCSA-MC"            => 1400

    ];

## payload example
##{
##  "s_grid_points": 64,
##  "ff0_grid_points": 64,
##  "uniform_grid": 8,
##  "s_min": "1",
##  "s_max": "10",
##  "ff0_min": "1",
##  "ff0_max": "4",
##  "mc_iterations": "3",
##  "req_mgroupcount": 1,
##  "tinoise_option": "0",
##  "rinoise_option": "0",
##  "fit_mb_select": "0",
##  "meniscus_range": 0,
##  "meniscus_points": 1,
##  "max_iterations": "3",
##  "debug_timings": 0,
##  "debug_level": "0",
##  "debug_text": "",
##  "experimentID": "1",
##  "timelast": "11047"
##};

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
    if ( !array_key_exists( "log", $priority_config )
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

    if ( $datasets > $priority_config[ "datasetthreshold" ] ) {
        $match .= "-LD";
        $matches[] = $match;
    }

    if ( $job_params[ "tinoise_option" ] != "0"
         || $job_params[ "rinoise_option" ] != "0" ) {
        $match .= "-FN";
        $matches[] = $match;
    }
    
    if ( $job_params[ "max_iterations" ] > $priority_config[ "iterationthreshold" ] 
         || $job_params[ "gfit_iterations" ] > $priority_config[ "iterationthreshold" ]
        ) {
        $match .= "-IT";
        $matches[] = $match;
    }

    if ( $job_params[ "mc_iterations" ] > 1 ) {
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
