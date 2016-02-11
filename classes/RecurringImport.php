<?php
require_once(__DIR__ . "/Transport.php");
require_once(__DIR__ . "/AcendaModels.php");
require_once(__DIR__ . "/Template.php");
require_once(__DIR__ . "/Couchbase/LastRunTime.php");
require_once (__DIR__ . "/Lock.php");

class RecurringImport {
    private $logger;
    private $configs;
    private $subscriber;
    private $models;
    private $subscription;
    private $acenda;
    private $errors = [];

    public $service_id;
    public $store_id;

    public function __construct($configs, $logger, $couchbaseCluster) {
        $this->configs = $configs;
        echo "Reccuring import ".date("Y-m-d H:i:s")." - {$this->configs['acenda']['store']['name']}\n";
        $this->logger = $logger;
        $this->service_id = $this->configs['acenda']['service']['id'];
        $this->store_id = $this->configs['acenda']['store']['id'];
        $this->store_name = $this->configs['acenda']['store']['name'];
        $this->acenda = new Acenda\Client(  $this->configs['acenda']['credentials']['client_id'],
                                            $this->configs['acenda']['credentials']['client_secret'],
                                            $this->store_name
         );

        $this->lastRunTime = new lastRunTime(   $this->store_id,
                                                $couchbaseCluster
                                            );
        $this->models = new AcendaModels(
                                            $this->configs['acenda']['credentials']['client_id'],
                                            $this->configs['acenda']['credentials']['client_secret'],
                                            $this->store_name
                                        );
        $this->subscription = $this->configs["acenda"]["subscription"];
        if(empty($this->configs["acenda"]["subscription"])) {
            echo "We weren't able to retrieve the subscriber's config.\n";
            die();
        }
    }

    public function process() {
        echo "Processing\n";
        $last_time_ran = $this->lastRunTime->getDatetime('lastTime');
        echo "LastRunTime: ".date("Y-m-d H:i:s",$last_time_ran->getTimestamp())."\n";
        $tmp_query = (!empty($this->subscription['credentials']['query'])) ? json_decode($this->subscription['credentials']['query'], true) : [];
        $tmp_query["date_created"]["\$gt"] = date("Y-m-d H:i:s",$last_time_ran->getTimestamp());
        echo "Query:\n";
        var_dump($tmp_query);
        echo "\n";

        echo "Config:\n";
        var_dump($this->configs);
        echo "\n";

        switch (strtolower($this->configs['acenda']['subscription']['credentials']['import_type'])) {
            case "inventory":
            case "variant":
                $this->model = "Variant";
                break;
            default:
                $this->model = "ProductImport";
                break;
        }
        $this->getFile();
        $this->handleErrors();

        echo "End LastRunTime: ".date("Y-m-d H:i:s", LastRunTime::getCurrentTimestamp())."\n\n";
        $this->lastRunTime->setDatetime('lastTime', LastRunTime::getCurrentTimestamp());
    }
    private function processHeaders($token){
        $headers = $this->acenda->get("/import/headers/".$token, []);
        if ($headers->code == 200){
            $headers = $headers->body->result;
            $import = [];
            if(!isset($this->configs['acenda']['subscription']['credentials']['match'])) {
                $this->configs['acenda']['subscription']['credentials']['match'] = '';
            }

            if ($this->configs['acenda']['subscription']['credentials']['match'] == "" || in_array($this->configs['subscription']['credentials']['match'], $headers->headers)){
                foreach($headers->headers as $field){
                    $data = ['name' => $field];
                    if ( strtolower($this->configs['acenda']['subscription']['credentials']['match']) == $field){ $data['match'] = true; }
                    $import[$field] = $data;
                }
                $return = $this->acenda->post("/import/queue/".$token, [
                    'import' => $import
                ]);
            }else{
                array_push($this->errors, "The field to match (".$this->configs['acenda']['subscription']['credentials']['match'].") does not exist in the CSV file.");
                $this->logger->addError("The field to match (".$this->configs['acenda']['subscription']['credentials']['match'].") does not exist in the CSV file.");
            }
        }
    }

    private function generateUrl(){
        $url = "";
        switch(isset($_SERVER['ACENDA_MODE']) ? $_SERVER['ACENDA_MODE'] : null){
            case "acendavm":
                $url = "http://admin.acendev";
                break;
            case "development":
                $url = "https://admin.acenda.devserver";
                break;
            default:
                $url = "https://admin.acenda.com";
                break;
        }
        return $url."/preview/".md5($this->configs['acenda']['store']['name'])."/api/import/upload?access_token=".Acenda\Authentication::getToken();
    }
    private function processImport(){
        // initialise the curl request
        $request = curl_init($this->generateUrl());

        // send a file
        curl_setopt($request, CURLOPT_POST, true);
        curl_setopt(
            $request,
            CURLOPT_POSTFIELDS,
            array(
            'import' => curl_file_create(realpath($this->path),'text/csv'),
              'model' => $this->model
            ));

        // output the response
        curl_setopt($request, CURLOPT_RETURNTRANSFER, true);

        $return = json_decode(curl_exec($request), true);
        // close the session
        curl_close($request);

        if ($return){
            $this->processHeaders($return['result']);
        }else{
            array_push($this->errors, "The file import failed.");
            $this->logger->addError("The file import failed.");
        }
    }

    // This function check the file and rewrite the file in local under UNIX code
    private function CSVFileCheck($path_to_file){
        ini_set("auto_detect_line_endings", true);

        $this->path = "/tmp/".uniqid().".csv";

        $fd_read = fopen($path_to_file, "r");
        $fd_write = fopen($this->path, "w");

        while($line = fgetcsv($fd_read)){
            fputcsv($fd_write, $line);
        }

        fclose($fd_read);
        fclose($fd_write);

        $this->processImport();
    }

    private function UnzipFile($info){
        $c = file_get_contents('/tmp/'.basename($this->configs['subscription']['credentials']['file_url']));
        $where = '/tmp/'.$info['filename'];
        file_put_contents($where, $c);

        if (\Comodojo\Zip\Zip::check($where)){
            $zip = \Comodojo\Zip\Zip::open($where);
            $where = '/tmp/'.uniqid();
            $zip->extract($where);

            if (is_dir($where)){
                $directories = scandir($where);
                foreach($directories as $dir){
                    if ($dir != "." && $dir != ".."){
                        $i = pathinfo($where."/".$dir);
                        if (isset($i['extension']) && $i['extension'] === 'csv'){
                            $this->CSVFileCheck($where."/".$dir);
                        }else{
                            array_push($this->errors, "A file in the extracted folder (".$i['filename'].") is not valid.");
                            $this->logger->addError("A file in the extracted folder (".$i['filename'].") is not valid.");
                        }
                    }
                }
            }else{
                $i = pathinfo($where);
                if ($i['extension'] === 'csv'){
                    $this->checkFileFromZip($where);
                }else{
                    array_push($this->errors, "The file extracted is not a proper CSV file (".$i['extension'].").");
                    $this->logger->addError("The file extracted is not a proper CSV file (".$i['extension'].").");
                }
            }
        }else{
            array_push($this->errors, "The ZIP file provided seems corrupted (".$where.").");
            $this->logger->addError("The ZIP file provided seems corrupted (".$where.").");
        }
    }

    private function handleErrors(){
        $return = $this->acenda->post("/log", [
            'type' => 'url_based_import',
            'type_id' => $this->configs['acenda']['service']['id'],
            'data' => json_encode($this->errors)
        ]);
    }

    private function getFileSftp($url) {
        $urlParts = parse_url($url);

        $this->sftp = new SFTP($urlParts['host']);
        if (!$this->sftp->login($urlParts['user'], $urlParts['pass'])) {
          array_push($this->errors, 'could not connect via sftp - '.$url);
          $this->logger->addError('could not connect via sftp - '.$url);
          return false;
       };
       $this->sftp->chdir($urlParts['path']);
       $data = $this->sftp->get(basename($urlParts['path']));
       return @file_put_contents('/tmp/'.basename($url),$data); 
    }
    private function getFileFtp($url) {
        $data = @file_get_contents($url);
        if(!$data) return false;
    return @file_put_contents('/tmp/'.basename($url),$data);
    } 
    private function getFileHttp($url) {
        $data = @file_get_contents($url);
        if(!$data) return false;
    return @file_put_contents('/tmp/'.basename($url),$data);
    } 
    private function getFile(){

    $protocol = strtok($this->configs['acenda']['subscription']['credentials']['file_url'],':/');
    switch($protocol) {
            case 'http':
            case 'https':
                $resp=$this->getFileHttp($this->configs['acenda']['subscription']['credentials']['file_url']);
        break;
            case 'sftp':
                $resp=$this->getFileSftp($this->configs['acenda']['subscription']['credentials']['file_url']);
            break;
            case 'ftp':
                $resp=$this->getFileFtp($this->configs['acenda']['subscription']['credentials']['file_url']);
            break;
            default:
               $resp=false;
            break;
    }
        if($resp){
            $info = pathinfo($this->configs['acenda']['subscription']['credentials']['file_url']);
            switch($info["extension"]){
                case "csv":
                    $this->CSVFileCheck('/tmp/'.basename($this->configs['acenda']['subscription']['credentials']['file_url']));
                    break;
                case "zip":
                    $this->UnzipFile($info);
                    break;
                default:
                    array_push($this->errors, "The extension ".$info['extension']." is not allowed for the moment.");
                    $this->logger->addError("The extension ".$info['extension']." is not allowed for the moment.");
                    break;
            }
        }else{
            array_push($this->errors, "The file provided at the URL ".$this->configs['acenda']['subscription']['credentials']['file_url']." couldn't be reached.");
            $this->logger->addError("The file provided at the URL ".$this->configs['acenda']['subscription']['credentials']['file_url']." couldn't be reached.");
        }
    }
}
