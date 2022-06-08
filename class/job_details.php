<?php

require_once dirname(__FILE__) . '/ultrascan-airavata-bridge/AiravataWrapper.php';
use SCIGAP\AiravataWrapper;

/**
 * Get a string containing the given experiment's details
 * @param $expId
 * @return mixed
 */
function getJobDetails($expId)
{
    $airavataWrapper = new AiravataWrapper();

    return $airavataWrapper->get_job_details($expId);
}


