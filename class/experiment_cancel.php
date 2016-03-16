<?php

require_once(dirname(__FILE__) . '/ultrascan-airavata-bridge/AiravataWrapper.php');
use SCIGAP\AiravataWrapper;

/**
 * Cancel the running experiment
 * @param $expId
 * @return mixed
 */
function cancelAiravataJob($expId)
{
    $airavataWrapper = new AiravataWrapper();

    $cancelResult = $airavataWrapper->terminate_airavata_experiment($expId);

    if ($cancelResult['terminateStatus']) {
        return true;
    } else {
        echo "Experiment Termination Failed: " . $cancelResult['message'] . PHP_EOL;
        return false;
    }
}

?>

