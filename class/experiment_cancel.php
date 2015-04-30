<?php
include_once '/srv/www/htdocs/common/class/thrift_includes.php';

/**
 * Cancel the running experiment
 * @param $expId
 * @return mixed
 */
function cancelAiravataJob($expId)
{
    global $airavataclient,$transport;
    try
    {
    $airavataclient->terminateExperiment($expId, '00409bfe-8e5f-4e50-b8eb-138bf0158e90');
    $transport->close();
    return true;
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
   $transport->close();
   return false;
}

?>

