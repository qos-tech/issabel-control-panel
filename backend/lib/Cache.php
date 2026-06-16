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

    public function outputAndExit()
    {
        readfile($this->file);
        exit;
    }

    public function write($content)
    {
        @file_put_contents($this->file, $content);
    }
}
