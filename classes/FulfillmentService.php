<?php
require_once(__DIR__ . "/Couchbase/LastRunTime.php");
use phpseclib\Net\SFTP;

class FulfillmentService {
    private $logger;
    private $configs;
    private $subscriber;
    private $subscription;
    private $acenda;
    private $errors = [];
    private $urlParts = [];
    private $username = '';
    private $password = '';
    private $protocol = 'ftp';
    private $host = '';
    private $remote_path = '';
    private $path = '';
    private $default_carrier='';
    private $default_shipping_method='';


    public $service_id;
    public $store_id;

    public function __construct($configs, $logger, $couchbaseCluster) {
        $this->configs = $configs;
        echo "Shipping ".date("Y-m-d H:i:s")." - {$this->configs['acenda']['store']['name']}\n";
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

        $this->urlParts = parse_url($this->configs['acenda']['subscription']['credentials']['file_url']);

        if(empty($this->urlParts['host'])) {
            $this->host= $this->configs['acenda']['subscription']['credentials']['file_url'];
        } else {
            $this->host = $this->urlParts['host'];
        }


        $this->protocol = strtok($this->configs['acenda']['subscription']['credentials']['file_url'],':/');        
        if(!empty($this->urlParts['user'])){
            $this->username = urldecode($this->urlParts['user']);
        }
        if(!empty($this->urlParts['pass'])){
            $this->password = urldecode($this->urlParts['pass']);
        }        
        if(!empty($this->configs['acenda']['subscription']['credentials']['default_carrier'])){
            $this->default_carrier = $this->configs['acenda']['subscription']['credentials']['default_carrier'];
        }
        if(!empty($this->configs['acenda']['subscription']['credentials']['default_shipping_method'])){
            $this->default_shipping_method = $this->configs['acenda']['subscription']['credentials']['default_shipping_method'];
        }        
        if(!empty($this->configs['acenda']['subscription']['credentials']['username'])) {
            $this->username = $this->configs['acenda']['subscription']['credentials']['username'];
        }
        if(!empty($this->configs['acenda']['subscription']['credentials']['password'])) {
            $this->password = $this->configs['acenda']['subscription']['credentials']['password'];
        }
        if(!empty($this->configs['acenda']['subscription']['credentials']['protocol'])) {
            $this->protocol = $this->configs['acenda']['subscription']['credentials']['protocol'];
        }

        if(empty($this->urlParts['path']) || $this->urlParts['path'] == $this->host) {
            $this->remote_path = '.';
        } else {
            $this->remote_path = $this->urlParts['path'];
        }

        $prefix = $this->configs['acenda']['subscription']['credentials']['file_prefix'];
        $files = $this->getFileList();
        if(is_array($files)) {
            foreach($files as $file) {
               if($prefix && substr($file,0,strlen($prefix))!=$prefix) continue;
               if(strtolower(pathinfo($file, PATHINFO_EXTENSION))!== 'csv') continue;
               echo "getting ". $file . "\n";
               $this->getFile($file);               
            }
        }
        $this->handleErrors();

        echo "End LastRunTime: ".date("Y-m-d H:i:s", LastRunTime::getCurrentTimestamp())."\n\n";
        $this->lastRunTime->setDatetime('lastTime', LastRunTime::getCurrentTimestamp());
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
    private function processFile(){
        echo "processing file {$this->path}\n";
        $fp = fopen($this->path,'r');
        // $fieldNames=fgetcsv($fp); 
        $fieldNames = ['tracking_numbers','order_number','shipping_carrier','shipping_method','items','item_quantities'];
        $fulfillments = [];
        $items = [];
        $orders = [];

        while($data=fgetcsv($fp)) {
            $row = array_combine(array_intersect_key($fieldNames, $data), array_intersect_key($data, $fieldNames));
            $row = array_map('trim',$row);
 
            if(!isset($row['items']) || !$row['items']){ 
                $row['items'] = [];
            }
            if(!isset($row['item_quantities']) || !$row['item_quantities']){ 
                $row['item_quantities'] = [];
            }
            if(!isset($row['tracking_numbers']) || !$row['tracking_numbers']) { 
                // skipping row for not having all tracking info               
                continue;
            }
            if(is_string($row['items'])) {
               $row['items'] = explode('|',$row['items']); 
            }
            if(is_string($row['item_quantities'])) {
               $row['item_quantities'] = explode('|',$row['item_quantities']); 
            }

            $row['tracking_numbers'] = explode('|',$row['tracking_numbers']);
            if(isset($row['order_number']) && is_numeric($row['order_number'])) {
                $response = $this->acenda->get('order',['query'=>['order_number'=>$row['order_number']]]);
                if(isset($response->body->result[0]->id)) {
                    $row['order_id'] = $response->body->result[0]->id;
                } else {
                    // unknown order number
                    continue;
                }
                // fetch items and fulfillments for the order and check to see if we really can fulfill the item in question
              
                $response=$this->acenda->get('order/'.$row['order_id'].'/fulfillments');
                if($response->body) {
                    $result = $response->body->result;
                    $fulfillments[$row['order_id']] = $result;
                }

                if(!isset($items[$row['order_id']])) {                 
                    $response=$this->acenda->get('order/'.$row['order_id'].'/items');
                    if($response->body) {
                        $result = $response->body->result;
                        $items[$row['order_id']] = $result;
                    }
                } 

                $new_fulfillment = [];
                $new_fulfillment['tracking_numbers'] = $row['tracking_numbers'];
                $new_fulfillment['tracking_urls'] = [];
                $new_fulfillment['tracking_company'] = !empty($row['shipping_carrier'])?$row['shipping_carrier']:$this->default_carrier;
                $new_fulfillment['shipping_method'] = !empty($row['shipping_method'])?$row['shipping_method']:$this->default_shipping_method;
                $new_fulfillment['status'] = 'success';
                // if no skus listed.. add all skus
                if(!count($row['items'])) {
                    echo "no items listed, adding all\n";
                    $row['items'] = [];
                    foreach($items[$row['order_id']] as $i => $order_item) {
                        $row['items'][]=$order_item->sku;
                    }
                }


                foreach($row['items'] as $index => $item){
                    foreach($items[$row['order_id']] as $i => $order_item) {
                        if($order_item->sku == trim($item)) {
                            if($order_item->fulfilled_quantity < $order_item->quantity) {
                                $f_item = [];
                                $f_item['id'] = $order_item->id;
                                if(isset($row['item_quantities'][$index]) && is_numeric($row['item_quantities'][$index])) {
                                    $f_item['quantity'] = $row['item_quantities'][$index];
                                    if(($f_item['quantity'] +  $order_item->fulfilled_quantity) >  $order_item->quantity) {
                                         $f_item['quantity'] = $order_item->quantity - $order_item->fulfilled_quantity;    
                                    }
                                } else { 
                                    $f_item['quantity'] = $order_item->quantity - $order_item->fulfilled_quantity;
                                }
                                $new_fulfillment['items'][] = $f_item;
                            }
                        }
                    }
                }

                if(isset($new_fulfillment['items']) && count($new_fulfillment['items'])) {
                   $p_response = $this->acenda->post('order/'.$row['order_id']. '/fulfillments',$new_fulfillment);                    
                   if($p_response->code >= 200 && $p_response->code < 300 && $this->configs['acenda']['subscription']['credentials']['charge_order']) {
                         // delay capture items 
                        $new_fulfillment_id = $p_response->body->result;
                        $f_response = $this->acenda->get('order/'.$row['order_id'].'/fulfillments/'.$new_fulfillment_id);
                        if($f_response->code >=200 && $f_response->code <300) {
                            $new_fulfillment = $f_response->body->result;               
                            $o_response=$this->acenda->get('order/'.$row['order_id']);
                            if($o_response->body) {
                                $result = $o_response->body->result;
                                $orders[$row['order_id']] = $result;
                            }
                            $this->captureFulfillment($orders[$row['order_id']],$new_fulfillment);
                        } else {
                            echo "couldnt get new fulfillment #".$new_fulfillment. "\n";
                            // couldnt get new fulfillment
                        }
                   }
                }
            }
        }
        fclose($fp);
        $this->renameFile($this->filename,$this->filename.'.processed');
    }
    private function captureFulfillment($order, $fulfillment) {

        echo "Capturing for order ".$order->order_number."\n";
        if($order->charge_amount >= $order->total) {
            echo "order full amount has already been captured\n";
            return false;
        }        
        $subtotal = 0.00;
        foreach($fulfillment->items as $item) {
            $subtotal += $item->price * $item->quantity;
        }
        $t_response=$this->acenda->post('taxdata/calculate',['shipping_rate'=>$order->shipping_rate,'shipping_zip'=>$order->shipping_zip,'subtotal'=>$subtotal]);
        if($t_response->code >=200 && $t_response->code < 300) {

            $total = $subtotal + $t_response->body->result->tax;
            if($total >= $order->charge_amount) {
                $total = $order->total - $order->charge_amount;
            }
            echo "total: ". number_format($total)."\n";
            $c_response = $this->acenda->post('order/'.$order->id.'/delaycapture',['amount'=>$total]);
            if($c_response->code >=200 && $c_response->code < 300)
            {
                echo "capture successful!\n";
                return true;
            } else {
                echo "capture failed\n";
                print_r($c_response);
                return false;
            }

        } else {
            // tax calculate failed
            return false;
        }
    }
    // This function check the file and rewrite the file in local under UNIX code
    private function CSVFileCheck($path_to_file){
        ini_set("auto_detect_line_endings", true);
        $this->filename = basename($path_to_file);
        $this->path = "/tmp/".uniqid().".csv";
        $fd_read = fopen($path_to_file, "r");
        $fd_write = fopen($this->path, "w");

        while($line = fgetcsv($fd_read)){
            fputcsv($fd_write, $line);
        }

        fclose($fd_read);
        fclose($fd_write);

        $this->processFile();
    }

    private function UnzipFile($info){
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
                        if (isset($i['extension']) && strtolower($i['extension']) === 'csv'){
                            $this->CSVFileCheck($where."/".$dir);
                        }else{
                            array_push($this->errors, "A file in the extracted folder (".$i['filename'].") is not valid.");
                            $this->logger->addError("A file in the extracted folder (".$i['filename'].") is not valid.");
                        }
                    }
                }
            }else{
                $i = pathinfo($where);
                if (strtolower($i['extension']) === 'csv'){
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
    private function getFileListFtp($url) {
        echo "connecting to ".$this->host."\nwith ".$this->username.":".$this->password."\n";        
        $conn_id = ftp_connect($this->host,@$this->urlParts['port']?$this->urlParts['port']:21);
        if(ftp_login($conn_id,$this->username, $this->password)) {
            ftp_pasv($conn_id, true);
            $contents = ftp_nlist($conn_id,@$this->remote_path?$this->remote_path:'.');
            print_r($contents);
            return $contents;
        }
        else {
          array_push($this->errors, 'could not connect via ftp - '.$url);
          $this->logger->addError('could not connect via ftp - '.$url);
          return false;
        }
    }
    private function getFileListSftp($url) {

        $this->sftp = new SFTP($this->host,@$this->urlParts['port']?$this->urlParts['port']:22);
        if (!$this->sftp->login($this->username, $this->password)) {
          array_push($this->errors, 'could not connect via sftp - '.$url);
          $this->logger->addError('could not connect via sftp - '.$url);
          return false;
       };
       $files = $this->sftp->nlist(@$this->remote_path?$this->remote_path:'.');
       return $files;
    }
    private function renameFileSftp($url,$oldFilenname,$newFilename) {

        $this->sftp = new SFTP($this->host);
        if (!$this->sftp->login($this->username, $this->password)) {
            array_push($this->errors, 'could not connect via sftp - '.$url);
            $this->logger->addError('could not connect via sftp - '.$url);
            return false;
        };
        $this->sftp->chdir($this->remote_path);
        return $this->sftp->rename($oldFilename,$newFilename);
    }
    private function renameFileFtp($url,$oldFilename,$newFilename){
        $conn_id = ftp_connect($this->host,@$this->urlParts['port']?$this->urlParts['port']:21);
        if(!ftp_login($conn_id,$this->username, $this->password)) {
            array_push($this->errors, 'could not connect via ftp - '.$url);
            $this->logger->addError('could not connect via ftp - '.$url);
            return false;
        };
            ftp_pasv($conn_id, true);            
        @ftp_chdir($conn_id,($this->remote_path[0]=='/')?substr($this->remote_path,1):$this->remote_path);
        return ftp_rename($conn_id,$oldFilename,$newFilename);
    }
    private function renameFile($oldFilename,$newFilename) {
        echo "renaming $oldFilename to $newFilename\n";
        $protocol = $this->protocol;
        switch(strtolower($protocol)) {
                case 'sftp':
                    $ret=$this->renameFileSftp($this->configs['acenda']['subscription']['credentials']['file_url'],$oldFilename,$newFilename);
                break;
                case 'ftp':
                    $ret=$this->renameFileFtp($this->configs['acenda']['subscription']['credentials']['file_url'],$oldFilename,$newFilename);
                break;
                default:
                    $ret=false;
                break;
        }  

        return $ret;     
    }
    private function getFileSftp($url) {

        $this->sftp = new SFTP($this->host);
        if (!$this->sftp->login($this->username, $this->password)) {
          array_push($this->errors, 'could not connect via sftp - '.$url);
          $this->logger->addError('could not connect via sftp - '.$url);
          return false;
       };
       $this->sftp->chdir($this->remote_path);
       $data = $this->sftp->get(basename($url));
       return @file_put_contents('/tmp/'.basename($url),$data); 
    }
    private function getFileFtp($url) {
        $conn_id = ftp_connect($this->host,@$this->urlParts['port']?$this->urlParts['port']:21);
        if(!ftp_login($conn_id,$this->username, $this->password)) {
          array_push($this->errors, 'could not connect via ftp - '.$url);
          $this->logger->addError('could not connect via ftp - '.$url);
          return false;
       };
        ftp_pasv($conn_id, true);            
       @ftp_chdir($conn_id,($this->remote_path[0]=='/')?substr($this->remote_path,1):$this->remote_path);
       return ftp_get($conn_id,'/tmp/'.basename($url),basename($url),FTP_ASCII );
    } 

    private function getFileList() {
        $protocol = $this->protocol;
                echo "connecting to ".$this->host."\nwith ".$this->username.":".$this->password."\n";    
        switch(strtolower($protocol)) {
                case 'sftp':
                    $files=$this->getFileListSftp($this->configs['acenda']['subscription']['credentials']['file_url']);
                break;
                case 'ftp':
                    $files=$this->getFileListFtp($this->configs['acenda']['subscription']['credentials']['file_url']);
                break;
                default:
                    $files=false;
                break;
        }  

        return $files;
    }

    private function getFile($filename){
        $protocol = $this->protocol;
        switch(strtolower($protocol)) {
                case 'sftp':
                    $resp=$this->getFileSftp($this->configs['acenda']['subscription']['credentials']['file_url'].'/'.$filename);
                break;
                case 'ftp':
                    $resp=$this->getFileFtp($this->configs['acenda']['subscription']['credentials']['file_url'].'/'.$filename);
                break;
                default:
                   $resp=false;
                break;
        }
        if($resp){
            $info = pathinfo($this->configs['acenda']['subscription']['credentials']['file_url'].'/'.$filename);
            switch(strtolower($info["extension"])){
                case "csv":
                    $this->CSVFileCheck('/tmp/'.$filename);
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
            array_push($this->errors, "The file provided at the URL ".$this->configs['acenda']['subscription']['credentials']['file_url'].'/'.$filename." couldn't be reached.");
            $this->logger->addError("The file provided at the URL ".$this->configs['acenda']['subscription']['credentials']['file_url'].'/'.$filename." couldn't be reached.");
        }
    }
}
