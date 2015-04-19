<?php
include_once '/srv/www/htdocs/common/class/thrift_includes.php';
use Airavata\Model\Workspace\Experiment\ErrorDetails;

/**
 * Get experiment error incase of failure
 * @param $expId
 * @return mixed
 */
function getExperimentErrors($expId)
{
    global $airavataclient;
    try
    {
	$experiment  = $airavataclient->getExperiment($expId);
	$experrors = $experiment->errors;
	if($experrors != null){
		foreach($experrors as $experror){
		$actualError = $experror->actualErrorMessage;
		return $actualError;
		}
	}
     return 'Error not found in Airavata';
    }
    catch (InvalidRequestException $ire)
    {
        echo 'InvalidRequestException!<br><br>' . $ire->getMessage();
    }
    catch (ExperimentNotFoundException $enf)
    {
        echo 'ExperimentNotFoundException!<br><br>' . $enf->getMessage();
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

}

?>

