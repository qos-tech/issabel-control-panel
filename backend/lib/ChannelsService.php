<?php

class ChannelsService
{
    public function normalizeStatus($deviceState, $contactStatus, $activeChannels)
    {
        $state = strtolower((string)$deviceState);
        $contact = strtolower((string)$contactStatus);
        $channels = (int)$activeChannels;

        if (strpos($state, 'ring') !== false) {
            return 'ringing';
        }

        if (strpos($state, 'not in use') !== false) {
            if ($contact === 'unreachable' || $contact === 'unknown') {
                return 'offline';
            }

            return 'online';
        }

        if (strpos($state, 'in use') !== false || strpos($state, 'busy') !== false || $channels > 0) {
            return 'busy';
        }

        if ($contact === 'reachable' || $contact === 'nonqualified' || $contact === 'ok') {
            return 'online';
        }

        if ($contact === 'unreachable' || $contact === 'unknown') {
            return 'offline';
        }

        return 'unknown';
    }

    public function getActiveChannels($ami, $extensionsMap, $trunksMap)
    {
        $ami->send(array('Action' => 'CoreShowChannels'));
        $events = $ami->readResponse('CoreShowChannelsComplete', 2);
        $channels = array();

        foreach ($events as $event) {
            $eventName = isset($event['Event']) ? $event['Event'] : '';

            if ($eventName !== 'CoreShowChannel') {
                continue;
            }

            $channel = isset($event['Channel']) ? $event['Channel'] : '';

            if ($channel === '') {
                continue;
            }

            if (!preg_match('/^(PJSIP|SIP|IAX2)\/([^\/\-]+)/', $channel, $matches)) {
                continue;
            }

            $tech = strtoupper($matches[1]);
            $device = $matches[2];
            $bridgeId = isset($event['BridgeID']) ? $event['BridgeID'] : '';
            $linkedId = isset($event['Linkedid']) ? $event['Linkedid'] : '';
            $uniqueId = isset($event['Uniqueid']) ? $event['Uniqueid'] : '';
            $groupKey = $bridgeId !== '' ? $bridgeId : ($linkedId !== '' ? $linkedId : $uniqueId);

            $channels[] = array(
                'channel' => $channel,
                'tech' => $tech,
                'device' => $device,
                'group_key' => $groupKey,
                'callerid_num' => isset($event['CallerIDNum']) ? $event['CallerIDNum'] : '',
                'callerid_name' => isset($event['CallerIDName']) ? $event['CallerIDName'] : '',
                'connected_line_num' => isset($event['ConnectedLineNum']) ? $event['ConnectedLineNum'] : '',
                'connected_line_name' => isset($event['ConnectedLineName']) ? $event['ConnectedLineName'] : '',
                'exten' => isset($event['Exten']) ? $event['Exten'] : '',
                'context' => isset($event['Context']) ? $event['Context'] : '',
                'application' => isset($event['Application']) ? $event['Application'] : '',
                'application_data' => isset($event['ApplicationData']) ? $event['ApplicationData'] : '',
                'duration' => isset($event['Duration']) ? $event['Duration'] : ''
            );
        }

        return $this->buildActiveMap($channels, $extensionsMap, $trunksMap);
    }

    public function applyActiveChannels(&$items, $activeChannels)
    {
        foreach ($items as $index => $item) {
            $name = $item['name'];

            if (!isset($activeChannels[$name])) {
                continue;
            }

            $active = $activeChannels[$name];
            $items[$index]['active_channels'] = (string)$active['channels'];
            $items[$index]['device_state'] = 'In use';
            $items[$index]['status'] = 'busy';
            $items[$index]['direction'] = isset($active['direction']) ? $active['direction'] : 'unknown';
            $items[$index]['direction_label'] = isset($active['direction_label']) ? $active['direction_label'] : 'Ocupado';
            $items[$index]['other_party'] = isset($active['other_party']) ? $active['other_party'] : '';
            $items[$index]['callerid_num'] = isset($active['callerid_num']) ? $active['callerid_num'] : '';
            $items[$index]['callerid_name'] = isset($active['callerid_name']) ? $active['callerid_name'] : '';
            $items[$index]['connected_line_num'] = isset($active['connected_line_num']) ? $active['connected_line_num'] : '';
            $items[$index]['connected_line_name'] = isset($active['connected_line_name']) ? $active['connected_line_name'] : '';
            $items[$index]['current_exten'] = isset($active['exten']) ? $active['exten'] : '';
            $items[$index]['current_context'] = isset($active['context']) ? $active['context'] : '';
            $items[$index]['current_application'] = isset($active['application']) ? $active['application'] : '';
            $items[$index]['current_application_data'] = isset($active['application_data']) ? $active['application_data'] : '';
        }
    }

    private function buildActiveMap($channels, $extensionsMap, $trunksMap)
    {
        $active = array();

        foreach ($channels as $channelInfo) {
            $name = $channelInfo['device'];
            $peerInfo = array();

            foreach ($channels as $candidate) {
                if ($candidate['channel'] === $channelInfo['channel']) {
                    continue;
                }

                if (
                    $channelInfo['group_key'] !== ''
                    && $candidate['group_key'] !== ''
                    && $candidate['group_key'] === $channelInfo['group_key']
                ) {
                    $peerInfo = $candidate;
                    break;
                }
            }

            $direction = $this->detectCallDirection($name, $channelInfo, $peerInfo, $extensionsMap, $trunksMap);

            if (!isset($active[$name])) {
                $active[$name] = array(
                    'tech' => $channelInfo['tech'],
                    'channels' => 0,
                    'state' => 'In use',
                    'direction' => $direction['direction'],
                    'direction_label' => $direction['direction_label'],
                    'other_party' => $direction['other_party'],
                    'callerid_num' => $channelInfo['callerid_num'],
                    'callerid_name' => $channelInfo['callerid_name'],
                    'connected_line_num' => $channelInfo['connected_line_num'],
                    'connected_line_name' => $channelInfo['connected_line_name'],
                    'exten' => $channelInfo['exten'],
                    'context' => $channelInfo['context'],
                    'application' => $channelInfo['application'],
                    'application_data' => $channelInfo['application_data'],
                    'duration' => $channelInfo['duration'],
                    'duration_seconds' => $this->parseDurationToSeconds($channelInfo['duration'])
                );
            }

            $active[$name]['channels']++;

            $candidateDuration = $this->parseDurationToSeconds($channelInfo['duration']);

            if ($candidateDuration > $active[$name]['duration_seconds']) {
                $active[$name]['duration'] = $channelInfo['duration'];
                $active[$name]['duration_seconds'] = $candidateDuration;
            }
        }

        return $active;
    }

    private function detectCallDirection($deviceName, $channelInfo, $peerInfo, $extensionsMap, $trunksMap)
    {
        $device = $this->normalizeNumberOnly($deviceName);
        $caller = $this->normalizeNumberOnly(isset($channelInfo['callerid_num']) ? $channelInfo['callerid_num'] : '');
        $connected = $this->normalizeNumberOnly(isset($channelInfo['connected_line_num']) ? $channelInfo['connected_line_num'] : '');
        $exten = $this->normalizeNumberOnly(isset($channelInfo['exten']) ? $channelInfo['exten'] : '');
        $peerDevice = isset($peerInfo['device']) ? $peerInfo['device'] : '';
        $peerName = $this->normalizeNumberOnly($peerDevice);
        $peerCaller = $this->normalizeNumberOnly(isset($peerInfo['callerid_num']) ? $peerInfo['callerid_num'] : '');
        $peerExten = $this->normalizeNumberOnly(isset($peerInfo['exten']) ? $peerInfo['exten'] : '');

        if ($caller !== '' && $this->isKnownExtension($caller, $extensionsMap) && $this->isKnownExtension($device, $extensionsMap)) {
            $other = $caller === $device ? ($exten !== '' ? $exten : ($peerName !== '' ? $peerName : $connected)) : $caller;

            return array('direction' => 'internal', 'direction_label' => 'Interna', 'other_party' => $other);
        }

        if ($caller !== '' && $caller === $device) {
            $other = $exten !== '' ? $exten : ($connected !== '' ? $connected : $peerName);

            if ($other !== '' && $this->isKnownExtension($other, $extensionsMap)) {
                return array('direction' => 'internal', 'direction_label' => 'Interna', 'other_party' => $other);
            }

            return array('direction' => 'outbound', 'direction_label' => 'Saída', 'other_party' => $other);
        }

        if ($exten !== '' && $exten === $device) {
            $other = $caller !== '' ? $caller : ($peerName !== '' ? $peerName : $connected);

            if ($other !== '' && $this->isKnownExtension($other, $extensionsMap)) {
                return array('direction' => 'internal', 'direction_label' => 'Interna', 'other_party' => $other);
            }

            return array('direction' => 'inbound', 'direction_label' => 'Entrada', 'other_party' => $other);
        }

        if (!empty($peerInfo)) {
            $other = $peerName !== '' ? $peerName : ($peerCaller !== '' ? $peerCaller : $peerExten);

            if ($other !== '' && $this->isKnownExtension($other, $extensionsMap)) {
                return array('direction' => 'internal', 'direction_label' => 'Interna', 'other_party' => $other);
            }

            if ($caller !== '' && $caller !== $device) {
                return array('direction' => 'inbound', 'direction_label' => 'Entrada', 'other_party' => $caller);
            }

            return array('direction' => 'outbound', 'direction_label' => 'Saída', 'other_party' => $other);
        }

        if ($caller !== '' && $caller !== $device) {
            return array('direction' => 'inbound', 'direction_label' => 'Entrada', 'other_party' => $caller);
        }

        return array(
            'direction' => 'unknown',
            'direction_label' => 'Ocupado',
            'other_party' => $connected !== '' ? $connected : $exten
        );
    }

    private function normalizeNumberOnly($value)
    {
        return preg_replace('/[^0-9]/', '', (string)$value);
    }

    private function isKnownExtension($value, $extensionsMap)
    {
        $value = $this->normalizeNumberOnly($value);

        return $value !== '' && isset($extensionsMap[$value]);
    }

    private function parseDurationToSeconds($duration)
    {
        $duration = trim((string)$duration);

        if ($duration === '') {
            return 0;
        }

        if (ctype_digit($duration)) {
            return (int)$duration;
        }

        if (preg_match('/^([0-9]+):([0-9]{2}):([0-9]{2})$/', $duration, $matches)) {
            return ((int)$matches[1] * 3600) + ((int)$matches[2] * 60) + (int)$matches[3];
        }

        if (preg_match('/^([0-9]+):([0-9]{2})$/', $duration, $matches)) {
            return ((int)$matches[1] * 60) + (int)$matches[2];
        }

        return 0;
    }
}
