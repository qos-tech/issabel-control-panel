<?php

class DevicesRepository
{
    private $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    public function loadDeviceMaps()
    {
        $db = $this->connect();
        $extensions = array();
        $trunks = array();

        $res = $db->query('
            SELECT id, tech, dial, description
            FROM devices
        ');

        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $id = trim(isset($row['id']) ? $row['id'] : '');
                $dial = trim(isset($row['dial']) ? $row['dial'] : '');
                $description = trim(isset($row['description']) ? $row['description'] : '');
                $tech = strtoupper(trim(isset($row['tech']) ? $row['tech'] : ''));

                $info = array(
                    'id' => $id,
                    'label' => $description !== '' ? $description : $id,
                    'tech' => $tech,
                    'dial' => $dial
                );

                if ($id !== '') {
                    $extensions[$id] = $info;
                }

                if ($dial !== '') {
                    $dialName = preg_replace('/^(PJSIP|SIP|IAX2)\//i', '', $dial);
                    $extensions[$dialName] = $info;
                }
            }
        }

        $res = $db->query('
            SELECT trunkid, name, tech, channelid, disabled
            FROM trunks
        ');

        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $disabled = isset($row['disabled']) ? $row['disabled'] : 'off';

                if ($disabled === 'on') {
                    continue;
                }

                $name = trim(isset($row['name']) ? $row['name'] : '');
                $channelid = trim(isset($row['channelid']) ? $row['channelid'] : '');
                $tech = strtoupper(trim(isset($row['tech']) ? $row['tech'] : ''));

                $info = array(
                    'id' => $channelid !== '' ? $channelid : $name,
                    'label' => $name !== '' ? $name : $channelid,
                    'tech' => $tech,
                    'channelid' => $channelid
                );

                if ($channelid !== '') {
                    $trunks[$channelid] = $info;
                }

                if ($name !== '') {
                    $trunks[$name] = $info;
                }
            }
        }

        $db->close();

        return array($extensions, $trunks);
    }

    public function classifyDevice($name, $extensions, $trunks)
    {
        $cleanName = trim($name);

        if (isset($trunks[$cleanName])) {
            return array(
                'type' => 'trunk',
                'label' => $trunks[$cleanName]['label'],
                'source' => 'trunks'
            );
        }

        if (isset($extensions[$cleanName])) {
            return array(
                'type' => 'extension',
                'label' => $extensions[$cleanName]['label'],
                'source' => 'devices'
            );
        }

        return array(
            'type' => 'unknown',
            'label' => $cleanName,
            'source' => 'unknown'
        );
    }

    private function connect()
    {
        $dbConfig = $this->config->getDatabaseConfig();
        $mysqli = new mysqli(
            $dbConfig['host'],
            $dbConfig['user'],
            $dbConfig['pass'],
            $dbConfig['name']
        );

        if ($mysqli->connect_error) {
            throw new Exception('Erro ao conectar no banco: ' . $mysqli->connect_error);
        }

        $mysqli->set_charset('utf8');

        return $mysqli;
    }
}
