<?php

header('Content-Type: application/json; charset=utf-8');

$cacheFile = '/tmp/control_panel_status_cache.json';
$cacheTtl = 2;

if (is_file($cacheFile) && (time() - filemtime($cacheFile)) <= $cacheTtl) {
    readfile($cacheFile);
    exit;
}

require_once "/var/www/html/libs/misc.lib.php";

function readConfigFile($file)
{
    $config = [];

    if (!is_file($file)) {
        return $config;
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $config[trim($key)] = trim($value);
    }

    return $config;
}

function dbConnect()
{
    $conf = readConfigFile('/etc/amportal.conf');

    $host = $conf['AMPDBHOST'] ?? 'localhost';
    $user = $conf['AMPDBUSER'] ?? 'asteriskuser';
    $pass = $conf['AMPDBPASS'] ?? '';
    $db   = $conf['AMPDBNAME'] ?? 'asterisk';

    $mysqli = new mysqli($host, $user, $pass, $db);

    if ($mysqli->connect_error) {
        throw new Exception("Erro ao conectar no banco: " . $mysqli->connect_error);
    }

    $mysqli->set_charset('utf8');

    return $mysqli;
}

function loadDeviceMaps()
{
    $db = dbConnect();

    $extensions = [];
    $trunks = [];

    $res = $db->query("
        SELECT id, tech, dial, description
        FROM devices
    ");

    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $id = trim($row['id']);
            $dial = trim($row['dial']);
            $description = trim($row['description'] ?? '');
            $tech = strtoupper(trim($row['tech'] ?? ''));

            $info = [
                'id' => $id,
                'label' => $description !== '' ? $description : $id,
                'tech' => $tech,
                'dial' => $dial,
            ];

            if ($id !== '') {
                $extensions[$id] = $info;
            }

            if ($dial !== '') {
                $dialName = preg_replace('/^(PJSIP|SIP|IAX2)\//i', '', $dial);
                $extensions[$dialName] = $info;
            }
        }
    }

    $res = $db->query("
        SELECT trunkid, name, tech, channelid, disabled
        FROM trunks
    ");

    if ($res) {
        while ($row = $res->fetch_assoc()) {
            if (($row['disabled'] ?? 'off') === 'on') {
                continue;
            }

            $name = trim($row['name']);
            $channelid = trim($row['channelid']);
            $tech = strtoupper(trim($row['tech'] ?? ''));

            $info = [
                'id' => $channelid !== '' ? $channelid : $name,
                'label' => $name !== '' ? $name : $channelid,
                'tech' => $tech,
                'channelid' => $channelid,
            ];

            if ($channelid !== '') {
                $trunks[$channelid] = $info;
            }

            if ($name !== '') {
                $trunks[$name] = $info;
            }
        }
    }

    $db->close();

    return [$extensions, $trunks];
}

function classifyDevice($name, $extensions, $trunks)
{
    $cleanName = trim($name);

    if (isset($trunks[$cleanName])) {
        return [
            'type' => 'trunk',
            'label' => $trunks[$cleanName]['label'],
            'source' => 'trunks',
        ];
    }

    if (isset($extensions[$cleanName])) {
        return [
            'type' => 'extension',
            'label' => $extensions[$cleanName]['label'],
            'source' => 'devices',
        ];
    }

    return [
        'type' => 'unknown',
        'label' => $cleanName,
        'source' => 'unknown',
    ];
}

function getAmiPassword()
{
    return obtenerClaveAMIAdmin("/var/www/html/");
}

function amiConnect()
{
    $fp = @fsockopen("127.0.0.1", 5038, $errno, $errstr, 2);

    if (!$fp) {
        throw new Exception("Falha ao conectar no AMI: $errstr ($errno)");
    }

    stream_set_timeout($fp, 2);

    fgets($fp);

    amiSend($fp, [
        "Action" => "Login",
        "Username" => "admin",
        "Secret" => getAmiPassword(),
        "Events" => "off",
    ]);

    $response = amiReadResponse($fp, null, 2);

    if (!isset($response[0]["Response"]) || $response[0]["Response"] !== "Success") {
        fclose($fp);
        throw new Exception("Falha no login AMI");
    }

    return $fp;
}

function amiSend($fp, array $params)
{
    foreach ($params as $key => $value) {
        fwrite($fp, $key . ": " . $value . "\r\n");
    }

    fwrite($fp, "\r\n");
}

function amiReadResponse($fp, $completeEvent = null, $timeout = 2)
{
    $events = [];
    $current = [];
    $start = microtime(true);

    while (!feof($fp)) {
        $line = fgets($fp);

        if ($line === false) {
            if ((microtime(true) - $start) >= $timeout) {
                break;
            }

            usleep(50000);
            continue;
        }

        $line = rtrim($line, "\r\n");

        if ($line === "") {
            if (!empty($current)) {
                $events[] = $current;

                if ($completeEvent !== null && isset($current["Event"]) && $current["Event"] === $completeEvent) {
                    break;
                }

                if ($completeEvent === null && isset($current["Response"])) {
                    break;
                }

                if (isset($current["Response"]) && $current["Response"] === "Error") {
                    break;
                }

                $current = [];
            }

            continue;
        }

        $parts = explode(":", $line, 2);

        if (count($parts) === 2) {
            $current[trim($parts[0])] = trim($parts[1]);
        }

        if ((microtime(true) - $start) >= $timeout) {
            break;
        }
    }

    return $events;
}

function amiReadUntilEndCommand($fp, $timeout = 1)
{
    $outputs = [];
    $current = [];
    $start = microtime(true);

    while (!feof($fp)) {
        $line = fgets($fp);

        if ($line === false) {
            if ((microtime(true) - $start) >= $timeout) {
                break;
            }

            usleep(50000);
            continue;
        }

        $line = rtrim($line, "\r\n");

        if ($line === "") {
            if (!empty($current)) {
                if (isset($current["Output"])) {
                    $outputs[] = $current["Output"];

                    if (trim($current["Output"]) === "--END COMMAND--") {
                        break;
                    }
                }

                $current = [];
            }

            continue;
        }

        $parts = explode(":", $line, 2);

        if (count($parts) === 2) {
            $current[trim($parts[0])] = trim($parts[1]);
        }

        if ((microtime(true) - $start) >= $timeout) {
            break;
        }
    }

    return $outputs;
}

function amiCommand($fp, $command, $timeout = 1)
{
    amiSend($fp, [
        "Action" => "Command",
        "Command" => $command,
    ]);

    return amiReadUntilEndCommand($fp, $timeout);
}

function normalizeStatus($deviceState, $contactStatus, $activeChannels)
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

function normalizeNumberOnly($value)
{
    return preg_replace('/[^0-9]/', '', (string)$value);
}

function isKnownExtension($value, $extensionsMap)
{
    $value = normalizeNumberOnly($value);

    return $value !== '' && isset($extensionsMap[$value]);
}

function detectCallDirection($deviceName, $channelInfo, $peerInfo, $extensionsMap, $trunksMap)
{
    $device = normalizeNumberOnly($deviceName);
    $caller = normalizeNumberOnly($channelInfo["callerid_num"] ?? '');
    $connected = normalizeNumberOnly($channelInfo["connected_line_num"] ?? '');
    $exten = normalizeNumberOnly($channelInfo["exten"] ?? '');

    $peerDevice = $peerInfo["device"] ?? '';
    $peerName = normalizeNumberOnly($peerDevice);
    $peerCaller = normalizeNumberOnly($peerInfo["callerid_num"] ?? '');
    $peerExten = normalizeNumberOnly($peerInfo["exten"] ?? '');

    if ($caller !== '' && isKnownExtension($caller, $extensionsMap) && isKnownExtension($device, $extensionsMap)) {
        if ($caller === $device) {
            $other = $exten !== '' ? $exten : ($peerName !== '' ? $peerName : $connected);
        } else {
            $other = $caller;
        }

        return [
            'direction' => 'internal',
            'direction_label' => 'Interna',
            'other_party' => $other,
        ];
    }

    if ($caller !== '' && $caller === $device) {
        $other = $exten !== '' ? $exten : ($connected !== '' ? $connected : $peerName);

        if ($other !== '' && isKnownExtension($other, $extensionsMap)) {
            return [
                'direction' => 'internal',
                'direction_label' => 'Interna',
                'other_party' => $other,
            ];
        }

        return [
            'direction' => 'outbound',
            'direction_label' => 'Saída',
            'other_party' => $other,
        ];
    }

    if ($exten !== '' && $exten === $device) {
        $other = $caller !== '' ? $caller : ($peerName !== '' ? $peerName : $connected);

        if ($other !== '' && isKnownExtension($other, $extensionsMap)) {
            return [
                'direction' => 'internal',
                'direction_label' => 'Interna',
                'other_party' => $other,
            ];
        }

        return [
            'direction' => 'inbound',
            'direction_label' => 'Entrada',
            'other_party' => $other,
        ];
    }

    if (!empty($peerInfo)) {
        $other = $peerName !== '' ? $peerName : ($peerCaller !== '' ? $peerCaller : $peerExten);

        if ($other !== '' && isKnownExtension($other, $extensionsMap)) {
            return [
                'direction' => 'internal',
                'direction_label' => 'Interna',
                'other_party' => $other,
            ];
        }

        if ($caller !== '' && $caller !== $device) {
            return [
                'direction' => 'inbound',
                'direction_label' => 'Entrada',
                'other_party' => $caller,
            ];
        }

        return [
            'direction' => 'outbound',
            'direction_label' => 'Saída',
            'other_party' => $other,
        ];
    }

    if ($caller !== '' && $caller !== $device) {
        return [
            'direction' => 'inbound',
            'direction_label' => 'Entrada',
            'other_party' => $caller,
        ];
    }

    return [
        'direction' => 'unknown',
        'direction_label' => 'Ocupado',
        'other_party' => $connected !== '' ? $connected : $exten,
    ];
}

function getPjsipEndpoints($fp, $extensions, $trunks)
{
    amiSend($fp, [
        "Action" => "PJSIPShowEndpoints",
    ]);

    $events = amiReadResponse($fp, "EndpointListComplete", 2);

    $items = [];

    foreach ($events as $event) {
        if (($event["Event"] ?? "") !== "EndpointList") {
            continue;
        }

        $name = $event["ObjectName"] ?? null;

        if (!$name) {
            continue;
        }

        $class = classifyDevice($name, $extensions, $trunks);

        $items[$name] = [
            "name" => $name,
            "label" => $class["label"],
            "tech" => "PJSIP",
            "type" => $class["type"],
            "source" => $class["source"],
            "device_state" => $event["DeviceState"] ?? "Unknown",
            "active_channels" => $event["ActiveChannels"] ?? "0",
            "contact_status" => "Unknown",
            "status" => "unknown",
        ];
    }

    return $items;
}

function getPjsipContacts($fp)
{
    amiSend($fp, [
        "Action" => "PJSIPShowContacts",
    ]);

    $events = amiReadResponse($fp, "ContactListComplete", 2);

    $contacts = [];

    foreach ($events as $event) {
        if (($event["Event"] ?? "") !== "ContactList") {
            continue;
        }

        $endpoint = $event["Endpoint"] ?? null;

        if (!$endpoint) {
            continue;
        }

        $contacts[$endpoint] = [
            "contact_status" => $event["Status"] ?? "Unknown",
        ];
    }

    return $contacts;
}

function getIaxPeers($fp, $extensions, $trunks)
{
    amiSend($fp, [
        "Action" => "IAXpeerlist",
    ]);

    $events = amiReadResponse($fp, "PeerlistComplete", 1);

    $items = [];

    foreach ($events as $event) {
        $eventName = $event["Event"] ?? "";

        if (!in_array($eventName, ["PeerEntry", "IAXpeerlist"], true)) {
            continue;
        }

        $name = $event["ObjectName"] ?? $event["Peer"] ?? $event["Name"] ?? null;

        if (!$name) {
            continue;
        }

        $class = classifyDevice($name, $extensions, $trunks);
        $peerStatus = $event["Status"] ?? "Unknown";
        $statusLower = strtolower($peerStatus);

        $items[$name] = [
            "name" => $name,
            "label" => $class["label"],
            "tech" => "IAX2",
            "type" => $class["type"],
            "source" => $class["source"],
            "device_state" => $peerStatus,
            "active_channels" => "0",
            "contact_status" => $peerStatus,
            "status" => (strpos($statusLower, "ok") !== false || strpos($statusLower, "reachable") !== false) ? "online" : "unknown",
        ];
    }

    return $items;
}

function parseSipPeerLines($lines, $extensions, $trunks)
{
    $items = [];

    foreach ($lines as $line) {
        $line = trim($line);

        if (
            $line === ""
            || stripos($line, "Name/username") !== false
            || stripos($line, "sip peers") !== false
            || stripos($line, "--END COMMAND--") !== false
        ) {
            continue;
        }

        $parts = preg_split('/\s+/', $line);

        if (count($parts) < 2) {
            continue;
        }

        $nameRaw = $parts[0];

        if (strpos($nameRaw, '/') !== false) {
            $nameParts = explode("/", $nameRaw);
            $name = trim($nameParts[0]);
        } else {
            $name = trim($nameRaw);
        }

        if ($name === "") {
            continue;
        }

        $status = "unknown";
        $lineLower = strtolower($line);

        if (strpos($lineLower, "ok") !== false) {
            $status = "online";
        } elseif (
            strpos($lineLower, "unreachable") !== false
            || strpos($lineLower, "unknown") !== false
        ) {
            $status = "offline";
        }

        $class = classifyDevice($name, $extensions, $trunks);

        $items[$name] = [
            "name" => $name,
            "label" => $class["label"],
            "tech" => "SIP",
            "type" => $class["type"],
            "source" => $class["source"],
            "device_state" => $status === "online" ? "Not in use" : "Unknown",
            "active_channels" => "0",
            "contact_status" => $status === "online" ? "Reachable" : "Unknown",
            "status" => $status,
        ];
    }

    return $items;
}

function getSipPeersByCommand($fp, $extensions, $trunks)
{
    // Primeiro tenta via AMI Command.
    $lines = amiCommand($fp, "sip show peers", 3);
    $items = parseSipPeerLines($lines, $extensions, $trunks);

    if (!empty($items)) {
        return $items;
    }

    // Fallback local via CLI, porque em algumas versões o AMI Command
    // não entrega o output completo ou não termina como esperado.
    $output = @shell_exec('/usr/sbin/asterisk -rx "sip show peers" 2>/dev/null');

    if (!$output) {
        $output = @shell_exec('/bin/asterisk -rx "sip show peers" 2>/dev/null');
    }

    if (!$output) {
        $output = @shell_exec('asterisk -rx "sip show peers" 2>/dev/null');
    }

    if (!$output) {
        return [];
    }

    $cliLines = explode("\n", $output);

    return parseSipPeerLines($cliLines, $extensions, $trunks);
}


function getActiveChannels($fp, $extensionsMap, $trunksMap)
{
    amiSend($fp, [
        "Action" => "CoreShowChannels",
    ]);

    $events = amiReadResponse($fp, "CoreShowChannelsComplete", 2);

    $channels = [];

    foreach ($events as $event) {
        if (($event["Event"] ?? "") !== "CoreShowChannel") {
            continue;
        }

        $channel = $event["Channel"] ?? "";

        if ($channel === "") {
            continue;
        }

        if (!preg_match('/^(PJSIP|SIP|IAX2)\/([^\/\-]+)/', $channel, $m)) {
            continue;
        }

        $tech = strtoupper($m[1]);
        $device = $m[2];

        $bridgeId = $event["BridgeID"] ?? '';
        $linkedId = $event["Linkedid"] ?? '';
        $uniqueId = $event["Uniqueid"] ?? '';

        $groupKey = $bridgeId !== '' ? $bridgeId : ($linkedId !== '' ? $linkedId : $uniqueId);

        $channels[] = [
            "channel" => $channel,
            "tech" => $tech,
            "device" => $device,
            "group_key" => $groupKey,
            "callerid_num" => $event["CallerIDNum"] ?? '',
            "callerid_name" => $event["CallerIDName"] ?? '',
            "connected_line_num" => $event["ConnectedLineNum"] ?? '',
            "connected_line_name" => $event["ConnectedLineName"] ?? '',
            "exten" => $event["Exten"] ?? '',
            "context" => $event["Context"] ?? '',
            "application" => $event["Application"] ?? '',
            "application_data" => $event["ApplicationData"] ?? '',
            "duration" => $event["Duration"] ?? '',
        ];
    }

    $active = [];

    foreach ($channels as $channelInfo) {
        $name = $channelInfo["device"];

        $peerInfo = [];

        foreach ($channels as $candidate) {
            if ($candidate["channel"] === $channelInfo["channel"]) {
                continue;
            }

            if (
                $channelInfo["group_key"] !== ''
                && $candidate["group_key"] !== ''
                && $candidate["group_key"] === $channelInfo["group_key"]
            ) {
                $peerInfo = $candidate;
                break;
            }
        }

        $direction = detectCallDirection(
            $name,
            $channelInfo,
            $peerInfo,
            $extensionsMap,
            $trunksMap
        );

        if (!isset($active[$name])) {
            $active[$name] = [
                "tech" => $channelInfo["tech"],
                "channels" => 0,
                "state" => "In use",
                "direction" => $direction["direction"],
                "direction_label" => $direction["direction_label"],
                "other_party" => $direction["other_party"],
                "callerid_num" => $channelInfo["callerid_num"],
                "callerid_name" => $channelInfo["callerid_name"],
                "connected_line_num" => $channelInfo["connected_line_num"],
                "connected_line_name" => $channelInfo["connected_line_name"],
                "exten" => $channelInfo["exten"],
                "context" => $channelInfo["context"],
                "application" => $channelInfo["application"],
                "application_data" => $channelInfo["application_data"],
            ];
        }

        $active[$name]["channels"]++;
    }

    return $active;
}

function applyActiveChannels(&$items, $activeChannels)
{
    foreach ($items as &$item) {
        $name = $item["name"];

        if (isset($activeChannels[$name])) {
            $item["active_channels"] = (string)$activeChannels[$name]["channels"];
            $item["device_state"] = "In use";
            $item["status"] = "busy";

            $item["direction"] = $activeChannels[$name]["direction"] ?? "unknown";
            $item["direction_label"] = $activeChannels[$name]["direction_label"] ?? "Ocupado";
            $item["other_party"] = $activeChannels[$name]["other_party"] ?? "";

            $item["callerid_num"] = $activeChannels[$name]["callerid_num"] ?? "";
            $item["callerid_name"] = $activeChannels[$name]["callerid_name"] ?? "";
            $item["connected_line_num"] = $activeChannels[$name]["connected_line_num"] ?? "";
            $item["connected_line_name"] = $activeChannels[$name]["connected_line_name"] ?? "";
            $item["current_exten"] = $activeChannels[$name]["exten"] ?? "";
            $item["current_context"] = $activeChannels[$name]["context"] ?? "";
            $item["current_application"] = $activeChannels[$name]["application"] ?? "";
            $item["current_application_data"] = $activeChannels[$name]["application_data"] ?? "";
        }
    }

    unset($item);
}

function addFallbackDevicesAndTrunks(&$items, $extensionsMap, $trunksMap)
{
    $seenExtensions = [];
    $seenTrunks = [];

    foreach ($items as $item) {
        if ($item["type"] === "extension") {
            $seenExtensions[$item["name"]] = true;
        }

        if ($item["type"] === "trunk") {
            $seenTrunks[$item["name"]] = true;
        }
    }

    foreach ($extensionsMap as $extInfo) {
        $id = $extInfo["id"];

        if ($id === '' || isset($seenExtensions[$id])) {
            continue;
        }

        $items[] = [
            "name" => $id,
            "label" => $extInfo["label"],
            "tech" => $extInfo["tech"],
            "type" => "extension",
            "source" => "devices",
            "device_state" => "Unknown",
            "active_channels" => "0",
            "contact_status" => "Unknown",
            "status" => "unknown",
        ];

        $seenExtensions[$id] = true;
    }

    foreach ($trunksMap as $trunkInfo) {
        $id = $trunkInfo["id"];

        if ($id === '' || isset($seenTrunks[$id])) {
            continue;
        }

        $items[] = [
            "name" => $id,
            "label" => $trunkInfo["label"],
            "tech" => $trunkInfo["tech"],
            "type" => "trunk",
            "source" => "trunks",
            "device_state" => "Unknown",
            "active_channels" => "0",
            "contact_status" => "Unknown",
            "status" => "unknown",
        ];

        $seenTrunks[$id] = true;
    }
}

try {
    [$extensionsMap, $trunksMap] = loadDeviceMaps();

    $fp = amiConnect();

    $items = [];

    $pjsip = getPjsipEndpoints($fp, $extensionsMap, $trunksMap);
    $contacts = getPjsipContacts($fp);

    foreach ($pjsip as $name => $item) {
        if (isset($contacts[$name])) {
            $item["contact_status"] = $contacts[$name]["contact_status"];
        }

        $item["status"] = normalizeStatus(
            $item["device_state"],
            $item["contact_status"],
            $item["active_channels"]
        );

        $items[] = $item;
    }

    $iax = getIaxPeers($fp, $extensionsMap, $trunksMap);

    foreach ($iax as $item) {
        $items[] = $item;
    }

    $sip = getSipPeersByCommand($fp, $extensionsMap, $trunksMap);

    foreach ($sip as $item) {
        $items[] = $item;
    }

    addFallbackDevicesAndTrunks($items, $extensionsMap, $trunksMap);

    $activeChannels = getActiveChannels($fp, $extensionsMap, $trunksMap);
    applyActiveChannels($items, $activeChannels);

    amiSend($fp, [
        "Action" => "Logoff",
    ]);

    fclose($fp);

    $ignoreNames = [
        'dummy_endpoint',
        'anonymous',
    ];

    $extensions = [];
    $trunks = [];
    $unknown = [];

    foreach ($items as $item) {
        $name = strtolower(trim($item["name"] ?? ''));

        if ($name === '' || in_array($name, $ignoreNames, true)) {
            continue;
        }

        if ($item["type"] === "trunk") {
            $trunks[] = $item;
        } elseif ($item["type"] === "extension") {
            $extensions[] = $item;
        } else {
            $unknown[] = $item;
        }
    }

    usort($extensions, fn($a, $b) => strnatcasecmp($a["name"], $b["name"]));
    usort($trunks, fn($a, $b) => strnatcasecmp($a["name"], $b["name"]));
    usort($unknown, fn($a, $b) => strnatcasecmp($a["name"], $b["name"]));

    $output = json_encode([
        "success" => true,
        "extensions" => $extensions,
        "trunks" => $trunks,
        "unknown" => $unknown,
        "summary" => [
            "extensions" => count($extensions),
            "trunks" => count($trunks),
            "unknown" => count($unknown),
        ],
    ]);

    file_put_contents($cacheFile, $output);

    echo $output;

} catch (Exception $e) {
    $output = json_encode([
        "success" => false,
        "error" => $e->getMessage(),
    ]);

    echo $output;
}
