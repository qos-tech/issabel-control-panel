<?php

class Cache
{
    private $file;
    private $ttl;

    public function __construct($file, $ttl)
    {
        $this->file = $file;
        $this->ttl = (int)$ttl;
    }

    public function hasFresh()
    {
        return is_file($this->file) && (time() - filemtime($this->file)) <= $this->ttl;
    }

    public function hasFile()
    {
        return is_file($this->file);
    }

    public function read()
    {
        if (!$this->hasFile()) {
            return null;
        }

        return @file_get_contents($this->file);
    }

    public function readData()
    {
        $content = $this->read();

        if ($content === false || $content === null || $content === '') {
            return null;
        }

        $data = json_decode($content, true);

        if (!is_array($data)) {
            return null;
        }

        return $data;
    }

    public function write($content)
    {
        @file_put_contents($this->file, $content);
    }

    public function writeData($data)
    {
        $content = json_encode($data);

        if ($content === false) {
            return false;
        }

        $this->write($content);

        return true;
    }
}
