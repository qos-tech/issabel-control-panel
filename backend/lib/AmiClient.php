<?php

class AmiClient
{
    private $host;
    private $port;
    private $username;
    private $password;
    private $socket;

    public function __construct($host, $port, $username, $password)
    {
        $this->host = $host;
        $this->port = (int)$port;
        $this->username = $username;
        $this->password = $password;
        $this->socket = null;
    }

    public function connect()
    {
        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($this->host, $this->port, $errno, $errstr, 2);

        if (!$socket) {
            throw new Exception('Falha ao conectar no AMI: ' . $errstr . ' (' . $errno . ')');
        }

        stream_set_timeout($socket, 2);
        fgets($socket);

        $this->socket = $socket;

        $this->send(array(
            'Action' => 'Login',
            'Username' => $this->username,
            'Secret' => $this->password,
            'Events' => 'off'
        ));

        $response = $this->readResponse(null, 2);

        if (!isset($response[0]['Response']) || $response[0]['Response'] !== 'Success') {
            $this->close();
            throw new Exception('Falha no login AMI');
        }
    }

    public function send($params)
    {
        $socket = $this->getSocket();

        foreach ($params as $key => $value) {
            fwrite($socket, $key . ': ' . $value . "\r\n");
        }

        fwrite($socket, "\r\n");
    }

    public function command($command, $timeout)
    {
        $this->send(array(
            'Action' => 'Command',
            'Command' => $command
        ));

        return $this->readUntilEndCommand($timeout);
    }

    public function readResponse($completeEvent, $timeout)
    {
        $socket = $this->getSocket();
        $events = array();
        $current = array();
        $start = microtime(true);

        while (!feof($socket)) {
            $line = fgets($socket);

            if ($line === false) {
                if ((microtime(true) - $start) >= $timeout) {
                    break;
                }

                usleep(50000);
                continue;
            }

            $line = rtrim($line, "\r\n");

            if ($line === '') {
                if (!empty($current)) {
                    $events[] = $current;

                    if ($completeEvent !== null && isset($current['Event']) && $current['Event'] === $completeEvent) {
                        break;
                    }

                    if ($completeEvent === null && isset($current['Response'])) {
                        break;
                    }

                    if (isset($current['Response']) && $current['Response'] === 'Error') {
                        break;
                    }

                    $current = array();
                }

                continue;
            }

            $parts = explode(':', $line, 2);

            if (count($parts) === 2) {
                $current[trim($parts[0])] = trim($parts[1]);
            }

            if ((microtime(true) - $start) >= $timeout) {
                break;
            }
        }

        return $events;
    }

    public function close()
    {
        if ($this->socket) {
            $this->send(array('Action' => 'Logoff'));
            fclose($this->socket);
            $this->socket = null;
        }
    }

    private function readUntilEndCommand($timeout)
    {
        $socket = $this->getSocket();
        $outputs = array();
        $current = array();
        $start = microtime(true);

        while (!feof($socket)) {
            $line = fgets($socket);

            if ($line === false) {
                if ((microtime(true) - $start) >= $timeout) {
                    break;
                }

                usleep(50000);
                continue;
            }

            $line = rtrim($line, "\r\n");

            if ($line === '') {
                if (!empty($current)) {
                    if (isset($current['Output'])) {
                        $outputs[] = $current['Output'];

                        if (trim($current['Output']) === '--END COMMAND--') {
                            break;
                        }
                    }

                    $current = array();
                }

                continue;
            }

            $parts = explode(':', $line, 2);

            if (count($parts) === 2) {
                $current[trim($parts[0])] = trim($parts[1]);
            }

            if ((microtime(true) - $start) >= $timeout) {
                break;
            }
        }

        return $outputs;
    }

    private function getSocket()
    {
        if (!$this->socket) {
            throw new Exception('AMI não conectado');
        }

        return $this->socket;
    }
}
