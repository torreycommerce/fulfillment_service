<?php

class AcendaModels {
  private $acenda;
  private $errors = [];

  public function __construct($client_id, $client_secret, $store_name) {
    echo "Store name: ".$store_name."\n";
    echo "Client_id: ".$client_id."\n";
    echo "Client_secret: ".$client_secret."\n";
    try {
        $this->acenda = new Acenda\Client(
          $client_id,
          $client_secret,
          $store_name//,
          //true // by pass ssl
        );
    }catch (Exception $e){
        echo "We weren't able to connect to Acenda, please check your credentials and/or try again later.\n";
        echo 'Caught exception: ',  $e->getMessage(), "\n";
        die();
    }
  }

  public function query($api_name, $query=[], $last_id=null){
    try {
      $response = $this->acenda->get('/'.$api_name, $query);
     } catch (AcendaException $e) {
       $this->addError($api_name, $query, $e->getMessage());
       return false;
    }

    if (!empty($response->body->result) && $response->code == 200){
      return $response;
    }
    return [];
  }

  public function getErrors() {
    return $this->errors;
  }

  private function addError($api_name, $query, $message="") {
      $this->errors[] = 'Api: {$api_name}, Query: {$query}, Message: '.$message;
      $this->pushEvent('service', $this->service_id, 'fail_query', 'Api: {$api_name}, query: {$query}, message: '.$message);
  }

  public function pushEvent($subject_type, $subject_id, $verb, $message, $arguments=[]) {
    $response = $this->acenda->post('/event', ['subject_type' => $subject_type, 'subject_id' => $subject_id,
     'message' => $message, 'verb' => $verb, 'arguments' => $arguments]);
  }

}

?>
