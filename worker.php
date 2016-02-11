<?php
error_reporting(E_ALL);
ini_set('display_errors',1);

//  AcendaWorker\Base handles: gearman connection - loads of configs - init of logger
require_once __DIR__."/../../core/Base.php";
require_once(__DIR__ . "/classes/RecurringImport.php");
require_once __DIR__ . "/vendors/autoload.php";

class WorkerRecurringImport extends AcendaWorker\Base {
    private $recurringImport;

    public function __construct() {
        parent::__construct(__DIR__);
    }

    public function recurringImport($job) {
        //print_r(array_merge_recursive($this->configs->service, $this->configs->job)); exit;
        $this->recurringImport = new RecurringImport(array_merge_recursive($this->configs->service, $this->configs->job), $this->logger, $this->getCouchBase());
        $this->recurringImport->process();
    }
}


$workerRecurringImport  = new WorkerRecurringImport();
$workerRecurringImport->worker->addFunction('recurring_import',
                                            [$workerRecurringImport, 'recurringImport']);
$workerRecurringImport->worker->work();
