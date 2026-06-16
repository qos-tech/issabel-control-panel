<?php

class ExtensionsService
{
    private $repository;
    private $channels;

    public function __construct($repository, $channels)
    {
        $this->repository = $repository;
        $this->channels = $channels;
    }

    public function collectDevices($ami, $extensionsMap, $trunksMap)
    {
        $items = array();
        $pjsip = $this->getPjsipEndpoints($ami, $extensionsMap, $trunksMap);
        $contacts = $this->getPjsipContacts($ami);

        foreach ($pjsip as $name => $item) {
            if (isset($contacts[$name])) {
                $item['contact_status'] = $contacts[$name]['contact_status'];
            }

            $item['status'] = $this->channels->normalizeStatus(
                $item['device_state'],
                $item['contact_status'],
                $item['active_channels']
            );

            $items[] = $item;
        }

        $iax = $this->getIaxPeers($ami, $extensionsMap, $trunksMap);

        foreach ($iax as $item) {
            $items[] = $item;
        }

        $sip = $this->getSipPeersByCommand($ami, $extensionsMap, $trunksMap);

        foreach ($sip as $item) {
            $items[] = $item;
        }

        return $items;
    }

    public function addFallbackExtensions(&$items, $extensionsMap)
    {
        $seenExtensions = array();

        foreach ($items as $item) {
            if (isset($item['type']) && $item['type'] === 'extension') {
                $seenExtensions[$item['name']] = true;
            }
        }

        foreach ($extensionsMap as $extInfo) {
            $id = $extInfo['id'];

            if ($id === '' || isset($seenExtensions[$id])) {
                continue;
            }

            $items[] = array(
                'name' => $id,
                'label' => $extInfo['label'],
                'tech' => $extInfo['tech'],
                'type' => 'extension',
                'source' => 'devices',
                'device_state' => 'Unknown',
                'active_channels' => '0',
                'contact_status' => 'Unknown',
                'status' => 'unknown'
            );

            $seenExtensions[$id] = true;
        }
    }

    private function getPjsipEndpoints($ami, $extensions, $trunks)
    {
        $ami->send(array('Action' => 'PJSIPShowEndpoints'));
        $events = $ami->readResponse('EndpointListComplete', 2);
        $items = array();

        foreach ($events as $event) {
            $eventName = isset($event['Event']) ? $event['Event'] : '';

            if ($eventName !== 'EndpointList') {
                continue;
            }

            $name = isset($event['ObjectName']) ? $event['ObjectName'] : null;

            if (!$name) {
                continue;
            }

            $class = $this->repository->classifyDevice($name, $extensions, $trunks);
            $items[$name] = array(
                'name' => $name,
                'label' => $class['label'],
                'tech' => 'PJSIP',
                'type' => $class['type'],
                'source' => $class['source'],
                'device_state' => isset($event['DeviceState']) ? $event['DeviceState'] : 'Unknown',
                'active_channels' => isset($event['ActiveChannels']) ? $event['ActiveChannels'] : '0',
                'contact_status' => 'Unknown',
                'status' => 'unknown'
            );
        }

        return $items;
    }

    private function getPjsipContacts($ami)
    {
        $ami->send(array('Action' => 'PJSIPShowContacts'));
        $events = $ami->readResponse('ContactListComplete', 2);
        $contacts = array();

        foreach ($events as $event) {
            $eventName = isset($event['Event']) ? $event['Event'] : '';

            if ($eventName !== 'ContactList') {
                continue;
            }

            $endpoint = isset($event['Endpoint']) ? $event['Endpoint'] : null;

            if (!$endpoint) {
                continue;
            }

            $contacts[$endpoint] = array(
                'contact_status' => isset($event['Status']) ? $event['Status'] : 'Unknown'
            );
        }

        return $contacts;
    }

    private function getIaxPeers($ami, $extensions, $trunks)
    {
        $ami->send(array('Action' => 'IAXpeerlist'));
        $events = $ami->readResponse('PeerlistComplete', 1);
        $items = array();

        foreach ($events as $event) {
            $eventName = isset($event['Event']) ? $event['Event'] : '';

            if ($eventName !== 'PeerEntry' && $eventName !== 'IAXpeerlist') {
                continue;
            }

            $name = null;

            if (isset($event['ObjectName'])) {
                $name = $event['ObjectName'];
            } elseif (isset($event['Peer'])) {
                $name = $event['Peer'];
            } elseif (isset($event['Name'])) {
                $name = $event['Name'];
            }

            if (!$name) {
                continue;
            }

            $class = $this->repository->classifyDevice($name, $extensions, $trunks);
            $peerStatus = isset($event['Status']) ? $event['Status'] : 'Unknown';
            $statusLower = strtolower($peerStatus);
            $status = (strpos($statusLower, 'ok') !== false || strpos($statusLower, 'reachable') !== false) ? 'online' : 'unknown';

            $items[$name] = array(
                'name' => $name,
                'label' => $class['label'],
                'tech' => 'IAX2',
                'type' => $class['type'],
                'source' => $class['source'],
                'device_state' => $peerStatus,
                'active_channels' => '0',
                'contact_status' => $peerStatus,
                'status' => $status
            );
        }

        return $items;
    }

    private function getSipPeersByCommand($ami, $extensions, $trunks)
    {
        $lines = $ami->command('sip show peers', 3);
        $items = $this->parseSipPeerLines($lines, $extensions, $trunks);

        if (!empty($items)) {
            return $items;
        }

        $output = @shell_exec('/usr/sbin/asterisk -rx "sip show peers" 2>/dev/null');

        if (!$output) {
            $output = @shell_exec('/bin/asterisk -rx "sip show peers" 2>/dev/null');
        }

        if (!$output) {
            $output = @shell_exec('asterisk -rx "sip show peers" 2>/dev/null');
        }

        if (!$output) {
            return array();
        }

        return $this->parseSipPeerLines(explode("\n", $output), $extensions, $trunks);
    }

    private function parseSipPeerLines($lines, $extensions, $trunks)
    {
        $items = array();

        foreach ($lines as $line) {
            $line = trim($line);

            if (
                $line === ''
                || stripos($line, 'Name/username') !== false
                || stripos($line, 'sip peers') !== false
                || stripos($line, '--END COMMAND--') !== false
            ) {
                continue;
            }

            $parts = preg_split('/\s+/', $line);

            if (count($parts) < 2) {
                continue;
            }

            $nameRaw = $parts[0];
            $name = $nameRaw;

            if (strpos($nameRaw, '/') !== false) {
                $nameParts = explode('/', $nameRaw);
                $name = trim($nameParts[0]);
            }

            if ($name === '') {
                continue;
            }

            $status = 'unknown';
            $lineLower = strtolower($line);

            if (strpos($lineLower, 'ok') !== false) {
                $status = 'online';
            } elseif (strpos($lineLower, 'unreachable') !== false || strpos($lineLower, 'unknown') !== false) {
                $status = 'offline';
            }

            $class = $this->repository->classifyDevice($name, $extensions, $trunks);
            $items[$name] = array(
                'name' => $name,
                'label' => $class['label'],
                'tech' => 'SIP',
                'type' => $class['type'],
                'source' => $class['source'],
                'device_state' => $status === 'online' ? 'Not in use' : 'Unknown',
                'active_channels' => '0',
                'contact_status' => $status === 'online' ? 'Reachable' : 'Unknown',
                'status' => $status
            );
        }

        return $items;
    }
}
