<?php
include_once '/srv/www/htdocs/common/class/thrift_includes.php';

use Airavata\Model\Workspace\Experiment\ExperimentState;
use Airavata\Model\Workspace\Experiment\JobState;

/**
 * Get a string containing the given experiment's status
 * @param $expId
 * @return mixed
 */
function getExperimentStatus($expId)
{
    global $airavataclient;
    #var_dump($expId);
    try
    {
        $experimentStatus = $airavataclient->getExperimentStatus($expId);
	$status = ExperimentState::$__names[$experimentStatus->experimentState];

        switch ($status)
        {
        case 'EXECUTING':
                $jobStatus = $airavataclient->getJobStatuses($expId);
                $jobName = array_keys($jobStatus);
                $jobState = JobState::$__names[$jobStatus[$jobName[0]]->jobState];
//                $status   = $jobState;
		if($jobState != 'COMPLETE'){
			$status    = $jobState;
		}
                break;
	case '':
        case 'UNKNOWN':
//		$status    = 'EXECUTING';
        //        sleep( 5 );
        //        $jobStatus = $airavataclient->getJobStatuses($expId);
        //        $jobName   = array_keys($jobStatus);
        //        $jobState  = JobState::$__names[$jobStatus[$jobName[0]]->jobState];
        //        $status    = $jobState;
                break;
        default:
                break;
        }
    }
    catch (InvalidRequestException $ire)
    {
        echo 'InvalidRequestException!<br><br>' . $ire->getMessage();
    }
    catch (ExperimentNotFoundException $enf)
    {
        echo 'ExperimentNotFoundException!<br><br>' . $enf->getMessage();
		$status    = 'EXP_NOT_FOUND';
    }
    catch (AiravataClientException $ace)
    {
        echo 'AiravataClientException!<br><br>' . $ace->getMessage();
    }
    catch (AiravataSystemException $ase)
    {
        echo 'AiravataSystemException!<br><br>' . $ase->getMessage();
    }
    catch (\Exception $e)
    {
        echo 'Exception!<br><br>' . $e->getMessage();
    }
    return $status;
}

?>

