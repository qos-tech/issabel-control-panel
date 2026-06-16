<?php

class QueuesService
{
    private $memberDisplayNames;

    public function __construct()
    {
        $this->memberDisplayNames = array();
    }

    public function collectQueues($ami)
    {
        try {
            $ami->send(array('Action' => 'QueueStatus'));
            $events = $ami->readResponse('QueueStatusComplete', 3);
        } catch (Exception $e) {
            return array();
        }

        $queues = array();

        foreach ($events as $event) {
            $eventName = isset($event['Event']) ? $event['Event'] : '';

            if ($eventName === 'QueueParams') {
                $queueId = isset($event['Queue']) ? $event['Queue'] : '';
                $queueName = $this->readFirst($event, array('QueueName', 'Queue'), '');

                if ($queueId === '' || $this->shouldIgnoreQueue($queueId, $queueName)) {
                    continue;
                }

                if (!isset($queues[$queueId])) {
                    $queues[$queueId] = $this->createQueue($event);
                } else {
                    $queues[$queueId]['name'] = $this->readFirst($event, array('QueueName', 'Queue'), $queues[$queueId]['name']);
                    $queues[$queueId]['strategy'] = $this->readFirst($event, array('Strategy'), $queues[$queueId]['strategy']);
                    $queues[$queueId]['calls'] = $this->readFirst($event, array('Calls'), $queues[$queueId]['calls']);
                    $queues[$queueId]['holdtime'] = $this->readFirst($event, array('Holdtime'), $queues[$queueId]['holdtime']);
                    $queues[$queueId]['completed'] = $this->readFirst($event, array('Completed'), $queues[$queueId]['completed']);
                    $queues[$queueId]['abandoned'] = $this->readFirst($event, array('Abandoned'), $queues[$queueId]['abandoned']);
                }
            } elseif ($eventName === 'QueueMember') {
                $queueId = isset($event['Queue']) ? $event['Queue'] : '';

                if ($queueId === '' || $this->shouldIgnoreQueue($queueId, $queueId)) {
                    continue;
                }

                if (!isset($queues[$queueId])) {
                    $queues[$queueId] = $this->createQueue(array('Queue' => $queueId));
                }

                $member = $this->createMember($event, $ami);
                $queues[$queueId]['members'][] = $member;
                $queues[$queueId]['members_total']++;

                if ($member['paused'] === '1') {
                    $queues[$queueId]['members_paused']++;
                } elseif ($member['in_call'] === '1' || $member['status_code'] === '2' || $member['status_code'] === '3' || $member['status_code'] === '7') {
                    $queues[$queueId]['members_busy']++;
                } elseif ($member['status_code'] === '1') {
                    $queues[$queueId]['members_available']++;
                }
            } elseif ($eventName === 'QueueEntry') {
                $queueId = isset($event['Queue']) ? $event['Queue'] : '';

                if ($queueId === '' || $this->shouldIgnoreQueue($queueId, $queueId)) {
                    continue;
                }

                if (!isset($queues[$queueId])) {
                    $queues[$queueId] = $this->createQueue(array('Queue' => $queueId));
                }

                $queues[$queueId]['entries'][] = $this->createEntry($event);
            }
        }

        $output = array_values($queues);
        usort($output, array($this, 'compareQueues'));

        return $output;
    }

    public function compareQueues($a, $b)
    {
        $left = isset($a['queue']) ? $a['queue'] : '';
        $right = isset($b['queue']) ? $b['queue'] : '';

        return strnatcasecmp($left, $right);
    }

    private function createQueue($event)
    {
        $queueId = isset($event['Queue']) ? $event['Queue'] : '';
        $name = isset($event['Queue']) ? $event['Queue'] : '';

        if (isset($event['QueueName']) && trim($event['QueueName']) !== '') {
            $name = trim($event['QueueName']);
        }

        return array(
            'queue' => $queueId,
            'name' => $name,
            'strategy' => isset($event['Strategy']) ? $event['Strategy'] : '',
            'calls' => isset($event['Calls']) ? (string)$event['Calls'] : '0',
            'holdtime' => isset($event['Holdtime']) ? (string)$event['Holdtime'] : '0',
            'completed' => isset($event['Completed']) ? (string)$event['Completed'] : '0',
            'abandoned' => isset($event['Abandoned']) ? (string)$event['Abandoned'] : '0',
            'members_total' => 0,
            'members_available' => 0,
            'members_busy' => 0,
            'members_paused' => 0,
            'members' => array(),
            'entries' => array()
        );
    }

    private function createMember($event, $ami)
    {
        $paused = isset($event['Paused']) ? (string)$event['Paused'] : '0';
        $inCall = isset($event['InCall']) ? (string)$event['InCall'] : '0';
        $statusCode = isset($event['Status']) ? (string)$event['Status'] : '0';
        $location = $this->readFirst($event, array('Location', 'Interface'), '');
        $extension = $this->extractExtension($location);
        $displayName = '';

        if ($extension !== '') {
            $displayName = $this->getMemberDisplayName($ami, $extension);
        }

        return array(
            'name' => $this->readFirst($event, array('Name', 'MemberName', 'Member'), ''),
            'location' => $location,
            'membership' => $this->readFirst($event, array('Membership', 'MembershipType'), ''),
            'penalty' => $this->readFirst($event, array('Penalty'), '0'),
            'calls_taken' => $this->readFirst($event, array('CallsTaken', 'Calls'), '0'),
            'last_call' => $this->readFirst($event, array('LastCall'), '0'),
            'last_pause' => $this->readFirst($event, array('LastPause'), '0'),
            'extension' => $extension,
            'display_name' => $displayName,
            'paused' => $paused,
            'in_call' => $inCall,
            'status_code' => $statusCode,
            'status' => $this->translateMemberStatus($statusCode, $paused, $inCall)
        );
    }

    private function createEntry($event)
    {
        return array(
            'position' => $this->readFirst($event, array('Position'), '0'),
            'channel' => $this->readFirst($event, array('Channel'), ''),
            'callerid_num' => $this->readFirst($event, array('CallerIDNum'), ''),
            'callerid_name' => $this->readFirst($event, array('CallerIDName'), ''),
            'wait' => $this->readFirst($event, array('Wait'), '0')
        );
    }

    private function translateMemberStatus($statusCode, $paused, $inCall)
    {
        if ($paused === '1') {
            return 'Pausado';
        }

        if ($inCall === '1') {
            return 'Em chamada';
        }

        $map = array(
            '1' => 'Disponível',
            '2' => 'Em uso',
            '3' => 'Ocupado',
            '4' => 'Inválido',
            '5' => 'Indisponível',
            '6' => 'Tocando',
            '7' => 'Ring in use',
            '8' => 'Em espera'
        );

        return isset($map[$statusCode]) ? $map[$statusCode] : 'Desconhecido';
    }

    private function readFirst($event, $keys, $defaultValue)
    {
        foreach ($keys as $key) {
            if (isset($event[$key]) && $event[$key] !== '') {
                return (string)$event[$key];
            }
        }

        return $defaultValue;
    }

    private function shouldIgnoreQueue($queueId, $queueName)
    {
        return strtolower(trim($queueId)) === 'default' || strtolower(trim($queueName)) === 'default';
    }

    private function extractExtension($location)
    {
        $location = trim((string)$location);

        if ($location === '') {
            return '';
        }

        if (preg_match('/^Local\/([0-9]+)@/i', $location, $matches)) {
            return $matches[1];
        }

        if (preg_match('/^(PJSIP|SIP|IAX2)\/([0-9]+)/i', $location, $matches)) {
            return $matches[2];
        }

        return '';
    }

    private function getMemberDisplayName($ami, $extension)
    {
        if (isset($this->memberDisplayNames[$extension])) {
            return $this->memberDisplayNames[$extension];
        }

        $displayName = '';

        try {
            $lines = $ami->command('database get AMPUSER/' . $extension . ' cidname', 2);
            $cidName = $this->parseDatabaseGetValue($lines);
            $displayName = $this->cleanMemberDisplayName($extension, $cidName);
        } catch (Exception $e) {
            $displayName = $extension;
        }

        $this->memberDisplayNames[$extension] = $displayName;

        return $displayName;
    }

    private function parseDatabaseGetValue($lines)
    {
        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || stripos($line, 'database entry not found') !== false) {
                continue;
            }

            if (preg_match('/^Value:[[:space:]]*(.+)$/i', $line, $matches)) {
                return trim($matches[1]);
            }
        }

        return '';
    }

    private function cleanMemberDisplayName($extension, $cidName)
    {
        $extension = trim((string)$extension);
        $cidName = trim((string)$cidName);

        if ($cidName === '') {
            return $extension;
        }

        $pattern = '/^Ramal[[:space:]]+' . preg_quote($extension, '/') . '[[:space:]]*-[[:space:]]*/i';
        $cleanName = preg_replace($pattern, '', $cidName);
        $cleanName = trim(preg_replace('/[[:space:]]+/', ' ', $cleanName));

        if ($cleanName !== '') {
            return $cleanName;
        }

        return $extension;
    }
}
