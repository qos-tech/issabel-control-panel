<?php

class Config
{
    private $cacheFile;
    private $cacheTtl;
    private $amiHost;
    private $amiPort;
    private $amiUser;
    private $amiPassword;

    public function __construct($amiPassword)
    {
        $this->cacheFile = '/tmp/control_panel_status_cache.json';
        $this->cacheTtl = 2;
        $this->amiHost = '127.0.0.1';
        $this->amiPort = 5038;
        $this->amiUser = 'admin';
        $this->amiPassword = $amiPassword;
    }

    public function getCacheFile()
    {
        return $this->cacheFile;
    }

    public function getCacheTtl()
    {
        return $this->cacheTtl;
    }

    public function getAmiHost()
    {
        return $this->amiHost;
    }

    public function getAmiPort()
    {
        return $this->amiPort;
    }

    public function getAmiUser()
    {
        return $this->amiUser;
    }

    public function getAmiPassword()
    {
        return $this->amiPassword;
    }

    public function getDatabaseConfig()
    {
        $conf = $this->readKeyValueFile('/etc/amportal.conf');

        return array(
            'host' => isset($conf['AMPDBHOST']) ? $conf['AMPDBHOST'] : 'localhost',
            'user' => isset($conf['AMPDBUSER']) ? $conf['AMPDBUSER'] : 'asteriskuser',
            'pass' => isset($conf['AMPDBPASS']) ? $conf['AMPDBPASS'] : '',
            'name' => isset($conf['AMPDBNAME']) ? $conf['AMPDBNAME'] : 'asterisk'
        );
    }

    private function readKeyValueFile($file)
    {
        $config = array();

        if (!is_file($file)) {
            return $config;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                continue;
            }

            $parts = explode('=', $line, 2);

            if (count($parts) !== 2) {
                continue;
            }

            $config[trim($parts[0])] = trim($parts[1]);
        }

        return $config;
    }
}
