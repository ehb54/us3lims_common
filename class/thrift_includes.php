<?php
/*
 * thrift_includes.php
 *
 * Includes to enable use of the Thrift and Airavata APIs
 *
 */
$GLOBALS['THRIFT_ROOT'] = '/opt/thrift/lib/Thrift/';
$GLOBALS['AIRAVATA_ROOT'] = '/opt/thrift/lib/Airavata/';

require_once $GLOBALS['THRIFT_ROOT'] . 'Transport/TTransport.php';
require_once $GLOBALS['THRIFT_ROOT'] . 'Transport/TBufferedTransport.php';
require_once $GLOBALS['THRIFT_ROOT'] . 'Transport/TSocket.php';
require_once $GLOBALS['THRIFT_ROOT'] . 'Protocol/TProtocol.php';
require_once $GLOBALS['THRIFT_ROOT'] . 'Protocol/TBinaryProtocol.php';
require_once $GLOBALS['THRIFT_ROOT'] . 'Exception/TException.php';
require_once $GLOBALS['THRIFT_ROOT'] . 'Exception/TApplicationException.php';
require_once $GLOBALS['THRIFT_ROOT'] . 'Exception/TProtocolException.php';
require_once $GLOBALS['THRIFT_ROOT'] . 'Exception/TTransportException.php';
require_once $GLOBALS['THRIFT_ROOT'] . 'Base/TBase.php';
require_once $GLOBALS['THRIFT_ROOT'] . 'Type/TType.php';
require_once $GLOBALS['THRIFT_ROOT'] . 'Type/TMessageType.php';
require_once $GLOBALS['THRIFT_ROOT'] . 'Factory/TStringFuncFactory.php';
require_once $GLOBALS['THRIFT_ROOT'] . 'StringFunc/TStringFunc.php';
require_once $GLOBALS['THRIFT_ROOT'] . 'StringFunc/Core.php';

require_once $GLOBALS['AIRAVATA_ROOT'] . 'API/Airavata.php';
require_once $GLOBALS['AIRAVATA_ROOT'] . 'Model/Workspace/Types.php';
require_once $GLOBALS['AIRAVATA_ROOT'] . 'Model/Workspace/Experiment/Types.php';
require_once $GLOBALS['AIRAVATA_ROOT'] . 'API/Error/Types.php';
require_once $GLOBALS['AIRAVATA_ROOT'] . 'Model/AppCatalog/AppInterface/Types.php';
require_once $GLOBALS['AIRAVATA_ROOT'] . 'Model/AppCatalog/AppDeployment/Types.php';
require_once $GLOBALS['AIRAVATA_ROOT'] . 'Model/AppCatalog/ComputeResource/Types.php';

use Thrift\Protocol\TBinaryProtocol;
use Thrift\Transport\TBufferedTransport;
use Thrift\Transport\TSocket;
use Thrift\Exception\TException;

use Airavata\API\AiravataClient;
use Airavata\API\Error\InvalidRequestException;
use Airavata\API\Error\AiravataClientException;
use Airavata\API\Error\AiravataSystemException;
use Airavata\API\Error\ExperimentNotFoundException;
use Airavata\Model\Workspace\Experiment\ComputationalResourceScheduling;
use Airavata\Model\AppCatalog\AppInterface\InputDataObjectType;
use Airavata\Model\Workspace\Experiment\UserConfigurationData;
use Airavata\Model\Workspace\Experiment\AdvancedOutputDataHandling;
use Airavata\Model\Workspace\Experiment\Experiment;
use Airavata\Model\Workspace\Experiment\ExperimentState;
use Airavata\Model\AppCatalog\AppInterface\DataType;
use Airavata\Model\Workspace\Project;
use Airavata\Model\Workspace\Experiment\JobState;
use Airavata\Model\AppCatalog\ComputeResource\JobSubmissionInterface;
use Airavata\Model\AppCatalog\ComputeResource\JobSubmissionProtocol;

function print_error_message($message)
{
    echo '<div class="alert alert-danger">' . $message . '</div>';
}

try{
$airavataconfig = parse_ini_file("/srv/www/htdocs/common/class/airavata-client-properties.ini");

$transport = new TSocket($airavataconfig['AIRAVATA_SERVER'], $airavataconfig['AIRAVATA_PORT']);
$transport->setRecvTimeout($airavataconfig['AIRAVATA_TIMEOUT']);
$transport->setSendTimeout($airavataconfig['AIRAVATA_TIMEOUT']);

$protocol = new TBinaryProtocol($transport);
$transport->open();
$airavataclient = new AiravataClient($protocol);
}
catch (TException $ire)
    {
        print_error_message('TException!<br><br>' . $ire->getMessage());
    }
    catch (AiravataClientException $ace)
    {
        print_error_message('AiravataClientException!<br><br>' . $ace->getMessage());
    }
    catch (AiravataSystemException $ase)
    {
        print_error_message('AiravataSystemException!<br><br>' . $ase->getMessage());
    }

?>
