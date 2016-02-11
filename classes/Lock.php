<?php

class Lock{
    private $fp;
    
    function __construct($script_path, $script_name) {
        $this->fp=fopen($script_path,'r');
        if (!flock($this->fp,LOCK_EX|LOCK_NB)) {
            die('The Script is already Running: '.$script_name.' '.PHP_EOL);
        }
    }

    function __destruct() {
        flock($this->fp, LOCK_UN);
        fclose($this->fp);  
    }
}

?>