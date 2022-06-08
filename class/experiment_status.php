<?php

require_once dirname(__FILE__) . '/ultrascan-airavata-bridge/AiravataWrapper.php';
use SCIGAP\AiravataWrapper;

/**
 * Get a string containing the given experiment's status
 * @param $expId
 * @return mixed
 */
function getExperimentStatus($expId)
{
    try {
        $airavataWrapper = new AiravataWrapper();
    } catch (Exception $e) {
        return 'UNKNOWN';
    }
    return $airavataWrapper->get_experiment_status($expId);
}

