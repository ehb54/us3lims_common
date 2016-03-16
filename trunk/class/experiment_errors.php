<?php

require_once(dirname(__FILE__) . '/ultrascan-airavata-bridge/AiravataWrapper.php');
use SCIGAP\AiravataWrapper;

/**
 * Get experiment error incase of failure
 * @param $expId
 * @return mixed
 */
function getExperimentErrors($expId)
{

    $airavataWrapper = new AiravataWrapper();

    return $airavataWrapper->get_experiment_errors($expId);

}

?>

