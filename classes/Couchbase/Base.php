<?php
//namespace Classes\Couchbase;

/*
** Abstract class Base
*/
abstract class Base {

  protected   $couchCluster;
  protected   $couchBucket;
  protected   $storage_prefix;

  function __construct($store_id, $couchbaseCluster) {
      $this->couchBucket = $couchbaseCluster;
      $this->storage_prefix = $store_id;
  }

  /*
  ** Store value into key
  */
  public function set($key, $data) {
    $key = $this->addPrefix($key);
    $data = json_encode($data);
    $this->couchBucket->upsert($key, $data);
  }

  /*
  ** Get value from key
  */
  public function get($key) {
    $key = $this->addPrefix($key);
    try {
      $result = $this->couchBucket->get($key);
      return json_decode($result->value);
    } catch (\CouchbaseException $e) {
      if (strpos($e->getMessage(), 'The key does not exist on the server') === FALSE) {
        \Log::debug("Exception from couchbase: " . $e->getMessage());
        throw $e;
      }
      return [];
    }
  }

  /*
  ** Remove key
  */
  public function delete($key) {
    $key = $this->addPrefix($key);
    try {
      \Log::debug("Removing key: " . $key);
      $this->couchBucket->remove($key);
    } catch (\CouchbaseException $e) {
      if (strpos($e->getMessage(), 'The key does not exist on the server') === FALSE) {
        \Log::debug("Exception from couchbase: " . $e->getMessage());
        throw $e;
      }
    }
  }

  /*
  ** Add Prefix based on store_id
  */
  private function addPrefix($key) {
    $className = get_class($this);
    return $this->storage_prefix."-".$className."-".$key;
  }

}

?>
