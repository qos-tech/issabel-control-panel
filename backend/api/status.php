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

$amiPassword = obtenerClaveAMIAdmin('/var/www/html/');
$config = new Config($amiPassword);
$cache = new Cache($config->getCacheFile(), $config->getCacheTtl());

if ($cache->hasFresh()) {
    $cache->outputAndExit();
}

$ami = null;

try {
    $repository = new DevicesRepository($config);
    $channels = new ChannelsService();
    $extensionsService = new ExtensionsService($repository, $channels);
    $trunksService = new TrunksService();
    $queuesService = new QueuesService();

    $maps = $repository->loadDeviceMaps();
    $extensionsMap = isset($maps[0]) ? $maps[0] : array();
    $trunksMap = isset($maps[1]) ? $maps[1] : array();

    $ami = new AmiClient(
        $config->getAmiHost(),
        $config->getAmiPort(),
        $config->getAmiUser(),
        $config->getAmiPassword()
    );
    $ami->connect();

    $items = $extensionsService->collectDevices($ami, $extensionsMap, $trunksMap);
    $extensionsService->addFallbackExtensions($items, $extensionsMap);
    $trunksService->addFallbackTrunks($items, $trunksMap);

    $activeChannels = $channels->getActiveChannels($ami, $extensionsMap, $trunksMap);
    $channels->applyActiveChannels($items, $activeChannels);

    $split = $trunksService->splitItems($items);
    $queues = $queuesService->collectQueues($ami);

    $extensions = isset($split['extensions']) ? $split['extensions'] : array();
    $trunks = isset($split['trunks']) ? $split['trunks'] : array();
    $unknown = isset($split['unknown']) ? $split['unknown'] : array();

    $output = json_encode(array(
        'success' => true,
        'extensions' => $extensions,
        'trunks' => $trunks,
        'unknown' => $unknown,
        'summary' => array(
            'extensions' => count($extensions),
            'trunks' => count($trunks),
            'unknown' => count($unknown)
        ),
        'queues' => $queues
    ));

    $cache->write($output);
    echo $output;
} catch (Exception $e) {
    $output = json_encode(array(
        'success' => false,
        'error' => $e->getMessage()
    ));

    echo $output;
}

if ($ami) {
    $ami->close();
}
