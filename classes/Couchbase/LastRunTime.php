<?php
//namespace Classes\Couchbase;
require_once(__DIR__ . "/Base.php");

/*
** LastRunTime
** Store and Get the last time a script was ran
*/

class LastRunTime extends Base {

  /*
  ** Get last run time
  */
  public function getDatetime($key, $defaultDays = 30) {
    $return = new \DateTime();
    $return->setTimezone(new \DateTimeZone('America/Los_Angeles'));
    $result = $this->get($key);
    if (!$result) {
        $return->setTimestamp(strtotime((int)$defaultDays . ' days ago'));
    } else {
        $return->setTimestamp($result);
    }
    return $return;
  }

  /*
  ** Set last run time
  */
  public function setDatetime($key, $value) {
    $this->set($key, $value);
  }

  /*
  ** Get current timestamp (PST)
  */
  static function getCurrentTimestamp() {
    $D = new \DateTime('NOW');
    $D->setTimezone(new \DateTimeZone('America/Los_Angeles'));
    return $D->getTimestamp();
  }

}

?>
