<?php
use phpseclib\Net\SFTP;

class Transport {
    public $fail=false;
    private $sftp;

    function __construct($url, $username, $password, $path) {
        try {
            $this->sftp = new SFTP($url);
            if (!$this->sftp->login($username, $password)) {
                echo "FTP connection failed\n";
                $fail=true;
            } else {
                $this->sftp->chdir($path);
            }
        } catch (Exception $e) {
            echo 'Caught exception: ',  $e->getMessage(), "\n";die();
        }
    }

    function upload($filename, $data) {
        $this->sftp->put($filename, $data);
    }
}
?>
