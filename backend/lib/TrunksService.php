<?php

class TrunksService
{
    public function addFallbackTrunks(&$items, $trunksMap)
    {
        $seenTrunks = array();

        foreach ($items as $item) {
            if (isset($item['type']) && $item['type'] === 'trunk') {
                $seenTrunks[$item['name']] = true;
            }
        }

        foreach ($trunksMap as $trunkInfo) {
            $id = $trunkInfo['id'];

            if ($id === '' || isset($seenTrunks[$id])) {
                continue;
            }

            $items[] = array(
                'name' => $id,
                'label' => $trunkInfo['label'],
                'tech' => $trunkInfo['tech'],
                'type' => 'trunk',
                'source' => 'trunks',
                'device_state' => 'Unknown',
                'active_channels' => '0',
                'contact_status' => 'Unknown',
                'status' => 'unknown'
            );

            $seenTrunks[$id] = true;
        }
    }

    public function splitItems($items)
    {
        $ignoreNames = array('dummy_endpoint', 'anonymous');
        $extensions = array();
        $trunks = array();
        $unknown = array();

        foreach ($items as $item) {
            $rawName = isset($item['name']) ? $item['name'] : '';
            $name = strtolower(trim($rawName));

            if ($name === '' || in_array($name, $ignoreNames, true)) {
                continue;
            }

            if (isset($item['type']) && $item['type'] === 'trunk') {
                $trunks[] = $item;
            } elseif (isset($item['type']) && $item['type'] === 'extension') {
                $extensions[] = $item;
            } else {
                $unknown[] = $item;
            }
        }

        usort($extensions, array($this, 'compareByName'));
        usort($trunks, array($this, 'compareByName'));
        usort($unknown, array($this, 'compareByName'));

        return array(
            'extensions' => $extensions,
            'trunks' => $trunks,
            'unknown' => $unknown
        );
    }

    public function compareByName($a, $b)
    {
        $left = isset($a['name']) ? $a['name'] : '';
        $right = isset($b['name']) ? $b['name'] : '';

        return strnatcasecmp($left, $right);
    }
}
