<?php

header('Content-Type: application/json; charset=utf-8');

require_once '/var/www/html/libs/misc.lib.php';
require_once dirname(__DIR__) . '/lib/Config.php';
require_once dirname(__DIR__) . '/lib/Cache.php';
require_once dirname(__DIR__) . '/lib/AmiClient.php';
require_once dirname(__DIR__) . '/lib/DevicesRepository.php';
require_once dirname(__DIR__) . '/lib/ChannelsService.php';
require_once dirname(__DIR__) . '/lib/ExtensionsService.php';
require_once dirname(__DIR__) . '/lib/TrunksService.php';
require_once dirname(__DIR__) . '/lib/QueuesService.php';

function controlPanelFinalizeResponse($payload, $startedAt, $cacheHit)
{
    if (!isset($payload['summary']) || !is_array($payload['summary'])) {
        $payload['summary'] = array();
    }

    if (!isset($payload['summary']['generated_at']) || $payload['summary']['generated_at'] === '') {
        $payload['summary']['generated_at'] = date('c');
    }

    $payload['summary']['cache_hit'] = $cacheHit ? true : false;
    $payload['summary']['elapsed_ms'] = (int)round((microtime(true) - $startedAt) * 1000);

    return $payload;
}

function controlPanelBuildEmptyHeavyData()
{
    return array(
        'items' => array(),
        'extensions_map' => array(),
        'trunks_map' => array(),
        'queue_names' => array()
    );
}

function controlPanelConnectAmi($config)
{
    $ami = new AmiClient(
        $config->getAmiHost(),
        $config->getAmiPort(),
        $config->getAmiUser(),
        $config->getAmiPassword()
    );
    $ami->connect();

    return $ami;
}

function controlPanelBuildHeavyData($repository, $extensionsService, $trunksService, $ami)
{
    $maps = $repository->loadDeviceMaps();
    $extensionsMap = isset($maps[0]) ? $maps[0] : array();
    $trunksMap = isset($maps[1]) ? $maps[1] : array();
    $queueNames = $repository->loadQueueNames();
    $items = array();

    if ($ami) {
        try {
            $items = $extensionsService->collectDevices($ami, $extensionsMap, $trunksMap);
        } catch (Exception $e) {
            $items = array();
        }
    }

    $extensionsService->addFallbackExtensions($items, $extensionsMap);
    $trunksService->addFallbackTrunks($items, $trunksMap);

    return array(
        'items' => $items,
        'extensions_map' => $extensionsMap,
        'trunks_map' => $trunksMap,
        'queue_names' => $queueNames
    );
}

$startedAt = microtime(true);
$amiPassword = obtenerClaveAMIAdmin('/var/www/html/');
$config = new Config($amiPassword);
$responseCache = new Cache($config->getCacheFile(), 1);
$heavyCache = new Cache('/tmp/control_panel_status_heavy_cache.json', 10);

if ($responseCache->hasFresh()) {
    $cachedPayload = $responseCache->readData();

    if (is_array($cachedPayload)) {
        echo json_encode(controlPanelFinalizeResponse($cachedPayload, $startedAt, true));
        exit;
    }
}

$ami = null;

try {
    $repository = new DevicesRepository($config);
    $channels = new ChannelsService();
    $extensionsService = new ExtensionsService($repository, $channels);
    $trunksService = new TrunksService();
    $queuesService = new QueuesService();

    $heavyData = $heavyCache->readData();
    $hasHeavyData = is_array($heavyData);
    $heavyIsFresh = $heavyCache->hasFresh() && $hasHeavyData;

    if (!$heavyIsFresh) {
        try {
            $ami = controlPanelConnectAmi($config);
        } catch (Exception $e) {
            $ami = null;
        }

        try {
            $heavyData = controlPanelBuildHeavyData($repository, $extensionsService, $trunksService, $ami);
            $heavyCache->writeData($heavyData);
            $hasHeavyData = true;
        } catch (Exception $e) {
            if (!$hasHeavyData) {
                $heavyData = controlPanelBuildEmptyHeavyData();
                $hasHeavyData = true;
            }
        }
    }

    if (!$hasHeavyData) {
        $heavyData = controlPanelBuildEmptyHeavyData();
    }

    if (!$ami) {
        try {
            $ami = controlPanelConnectAmi($config);
        } catch (Exception $e) {
            $ami = null;
        }
    }

    $items = isset($heavyData['items']) && is_array($heavyData['items']) ? $heavyData['items'] : array();
    $extensionsMap = isset($heavyData['extensions_map']) && is_array($heavyData['extensions_map']) ? $heavyData['extensions_map'] : array();
    $trunksMap = isset($heavyData['trunks_map']) && is_array($heavyData['trunks_map']) ? $heavyData['trunks_map'] : array();
    $queueNames = isset($heavyData['queue_names']) && is_array($heavyData['queue_names']) ? $heavyData['queue_names'] : array();
    $activeChannels = array();
    $queues = array();

    if ($ami) {
        try {
            $activeChannels = $channels->getActiveChannels($ami, $extensionsMap, $trunksMap);
        } catch (Exception $e) {
            $activeChannels = array();
        }

        try {
            $queues = $queuesService->collectQueues($ami, $activeChannels, $queueNames);
        } catch (Exception $e) {
            $queues = array();
        }
    }

    $channels->applyActiveChannels($items, $activeChannels);

    $split = $trunksService->splitItems($items);
    $extensions = isset($split['extensions']) ? $split['extensions'] : array();
    $trunks = isset($split['trunks']) ? $split['trunks'] : array();
    $unknown = isset($split['unknown']) ? $split['unknown'] : array();

    $payload = array(
        'success' => true,
        'extensions' => $extensions,
        'trunks' => $trunks,
        'unknown' => $unknown,
        'summary' => array(
            'extensions' => count($extensions),
            'trunks' => count($trunks),
            'unknown' => count($unknown),
            'generated_at' => date('c'),
            'cache_hit' => false,
            'elapsed_ms' => 0
        ),
        'queues' => $queues
    );

    $payload = controlPanelFinalizeResponse($payload, $startedAt, false);
    $responseCache->writeData($payload);
    echo json_encode($payload);
} catch (Exception $e) {
    $payload = array(
        'success' => false,
        'extensions' => array(),
        'trunks' => array(),
        'unknown' => array(),
        'summary' => array(
            'extensions' => 0,
            'trunks' => 0,
            'unknown' => 0,
            'generated_at' => date('c'),
            'cache_hit' => false,
            'elapsed_ms' => 0
        ),
        'queues' => array(),
        'error' => $e->getMessage()
    );

    echo json_encode(controlPanelFinalizeResponse($payload, $startedAt, false));
}

if ($ami) {
    $ami->close();
}
