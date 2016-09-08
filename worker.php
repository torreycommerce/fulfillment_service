<?php
error_reporting(E_ALL);
ini_set('display_errors',1);

//  AcendaWorker\Base handles: gearman connection - loads of configs - init of logger
require_once __DIR__."/../../core/Base.php";
require_once(__DIR__ . "/classes/FulfillmentService.php");
require_once __DIR__ . "/vendors/autoload.php";

class WorkerFulfillmentService extends AcendaWorker\Base {
    private $FulfillmentService;

    public function __construct() {
        parent::__construct(__DIR__);
    }

    public function FulfillmentService($job) {
        $this->FulfillmentService = new FulfillmentService(array_merge_recursive($this->configs->service, $this->configs->job), $this->logger, $this->getCouchBase());
        // If you want to test - comment out this line (And comment out process)
        // Then set the string contents of the file in the service subscription
//        $this->FulfillmentService->testStringContents();
        $this->FulfillmentService->process();
    }
}


$workerFulfillmentService  = new WorkerFulfillmentService();
$workerFulfillmentService->worker->addFunction('fulfillment',
                                            [$workerFulfillmentService, 'FulfillmentService']);
$workerFulfillmentService->worker->work();
