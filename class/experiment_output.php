<?php
include_once '/srv/www/htdocs/common/class/thrift_includes_0_15.php';

$expId = $argv[1];
$outputs = getExperimentOutputs($expId);
foreach ($outputs as $output)
{
    echo "$output->name : $output->type: $output->value      <br><br>";
}
#var_dump($outputs);
$transport->close();


/**
 * Get the experiment with the given ID
 * @param $expId
 * @return null
 */
function getExperimentOutputs($expId)
{
    global $airavataclient;

    try
    {
       return $airavataclient->getExperimentOutputs($expId);
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
    catch (TTransportException $tte)
    {
        echo 'TTransportException!<br><br>' . $tte->getMessage();
    }
    catch (\Exception $e)
    {
        echo 'Exception!<br><br>' . $e->getMessage();
    }
}

?>

