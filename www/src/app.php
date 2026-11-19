<?php

// Nustatome Lietuvos laiko juostą
date_default_timezone_set('Europe/Vilnius');

// Automatinis Composer arba vietinių klasių užkrovimas
if (file_exists(__DIR__ . '/autoload.php')) {
    require_once __DIR__ . '/autoload.php';
}
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
} else {
    require_once __DIR__ . '/MeshCoreDecoder.php';
    require_once __DIR__ . '/RawPacketRepository.php';
}

use MeshCore\MeshCoreDecoder;
use MeshCore\RawPacketRepository;

// Nustatome CORS ir JSON antraštes
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Shared-Secret');
header('Content-Type: application/json; charset=utf-8');

// Išankstinė OPTIONS užklausa (Pre-flight CORS)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$decoder = new MeshCoreDecoder();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Inicializuojame duomenų bazės saugyklą
$dbConnected = false;
$dbError = null;
$repository = null;

try {
    $repository = new RawPacketRepository();
    $dbConnected = true;
} catch (\Throwable $e) {
    $dbError = $e->getMessage();
}

$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path       = rtrim($requestUri, '/');

// Nustatome ar kreipiamasi į /api/nodes endpointą
$isNodesEndpoint = (
    preg_match('#/(api/)?nodes(\.php)?$#', $path) || 
    (isset($_GET['endpoint']) && $_GET['endpoint'] === 'nodes') ||
    (isset($_GET['action']) && $_GET['action'] === 'nodes') ||
    basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'nodes.php'
);

// Nustatome ar kreipiamasi į /api/repyters arba /api/repeaters endpointą (visi kartotuvai)
$isRepytersEndpoint = (
    preg_match('#/(api/)?(api/)?(repyters|repeaters)(\.json|\.php)?$#i', $path) || 
    (isset($_GET['endpoint']) && in_array(strtolower($_GET['endpoint']), ['repyters', 'repeaters', 'r'])) ||
    (isset($_GET['action']) && in_array(strtolower($_GET['action']), ['repyters', 'repeaters', 'r'])) ||
    in_array(basename($_SERVER['SCRIPT_FILENAME'] ?? ''), ['repyters.php', 'repeaters.php', 'r.json'])
);

// Nustatome ar kreipiamasi į /api/api/repeater arba /api/repeater endpointą (TIK 1 kartotuvas pagal requested_date)
$isSingleRepeaterEndpoint = (
    preg_match('#/(api/)?(api/)?(repeater|repyter)(\.json|\.php)?$#i', $path) || 
    (isset($_GET['endpoint']) && in_array(strtolower($_GET['endpoint']), ['repeater', 'repyter'])) ||
    (isset($_GET['action']) && in_array(strtolower($_GET['action']), ['repeater', 'repyter'])) ||
    in_array(basename($_SERVER['SCRIPT_FILENAME'] ?? ''), ['repeater.php', 'repyter.php'])
);

// Nustatome ar kreipiamasi į /api/telemetry arba /telemetry endpointą (telemetrijos istorija grafikams)
$isTelemetryEndpoint = (
    preg_match('#/(api/)?telemetry(\.php)?$#', $path) || 
    (isset($_GET['endpoint']) && $_GET['endpoint'] === 'telemetry') ||
    (isset($_GET['action']) && $_GET['action'] === 'telemetry') ||
    basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'telemetry.php'
);

// Nustatome ar kreipiamasi į /api/bots arba /bots endpointą (ESP32 botų sąrašas)
$isBotsEndpoint = (
    preg_match('#/(api/)?bots(\.php)?$#', $path) || 
    (isset($_GET['endpoint']) && $_GET['endpoint'] === 'bots') ||
    (isset($_GET['action']) && $_GET['action'] === 'bots') ||
    basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'bots.php'
);

$subpath = isset($_GET['subpath']) ? trim((string)$_GET['subpath'], '/') : '';

// Nustatome ar kreipiamasi į /api/node/list arba /node/list endpointą
$isNodeListEndpoint = (
    preg_match('#/(api/)?node/list(\.json|\.php)?$#i', $path) ||
    strtolower($subpath) === 'list' ||
    (isset($_GET['endpoint']) && in_array(strtolower($_GET['endpoint']), ['node_list', 'node/list'])) ||
    ((isset($_GET['endpoint']) && strtolower($_GET['endpoint']) === 'node') && (isset($_GET['action']) && strtolower($_GET['action']) === 'list')) ||
    ((isset($_GET['action']) && strtolower($_GET['action']) === 'node_list'))
);

// Nustatome ar kreipiamasi į /node/{public_key} arba /api/node/{public_key} endpointą
$isNodeDetailEndpoint = false;
$nodeDetailPublicKey = null;

if (!$isNodeListEndpoint) {
    if (!empty($subpath) && !in_array(strtolower($subpath), ['list', 'index', 'index.php', ''])) {
        $isNodeDetailEndpoint = true;
        $nodeDetailPublicKey = $subpath;
    } elseif (preg_match('#/(api/)?node/([a-zA-Z0-9_!\-#\.\+]+)(\.json|\.php)?$#i', $path, $m)) {
        $rawTarget = trim($m[2]);
        if (!in_array(strtolower($rawTarget), ['list', 'index', 'index.php', ''])) {
            $isNodeDetailEndpoint = true;
            $nodeDetailPublicKey = $rawTarget;
        }
    } elseif ((isset($_GET['endpoint']) && in_array(strtolower($_GET['endpoint']), ['node', 'node_detail'])) && !empty($_GET['public_key'])) {
        $isNodeDetailEndpoint = true;
        $nodeDetailPublicKey = (string)$_GET['public_key'];
    } elseif (!empty($_GET['public_key'])) {
        $isNodeDetailEndpoint = true;
        $nodeDetailPublicKey = (string)$_GET['public_key'];
    } elseif (!empty($_GET['node_id'])) {
        $isNodeDetailEndpoint = true;
        $nodeDetailPublicKey = (string)$_GET['node_id'];
    } elseif (!empty($_GET['bot_id']) && (isset($_GET['endpoint']) && in_array(strtolower($_GET['endpoint']), ['node', 'node_detail']))) {
        $isNodeDetailEndpoint = true;
        $nodeDetailPublicKey = (string)$_GET['bot_id'];
    }
}

// Jei kreipiamasi tiesiai į /api/node arba /node be mazgo identifikatoriaus – iškart grąžiname 404 klaidą
$isDirectNodeRootRequest = (
    preg_match('#^/(api/)?node/?$#i', $path) ||
    ((isset($_GET['endpoint']) && in_array(strtolower($_GET['endpoint']), ['node', 'node_detail'])) && empty($nodeDetailPublicKey))
);

// =========================================================================
// 🌐 API UŽKLAUSŲ ŽURNALAVIMAS (API LOGS)
// Fiksuojame kiekvieną kreipimąsi į API, kliento IP, User-Agent, path ir grąžintą atsakymą
// =========================================================================
$apiRequestStartTime = microtime(true);
$apiRawInputForLog = ($method === 'POST') ? (string)file_get_contents('php://input') : '';
ob_start();

if ($dbConnected && $repository !== null) {
    $apiLogEndpointName = '/api/status';
    if ($isSingleRepeaterEndpoint) {
        $apiLogEndpointName = '/api/repeater';
    } elseif ($isRepytersEndpoint) {
        $apiLogEndpointName = '/api/repyters';
    } elseif ($isNodeListEndpoint) {
        $apiLogEndpointName = '/api/node/list';
    } elseif ($isNodeDetailEndpoint) {
        $apiLogEndpointName = '/api/node/{key}';
    } elseif ($isNodesEndpoint) {
        $apiLogEndpointName = '/api/nodes';
    } elseif ($isTelemetryEndpoint) {
        $apiLogEndpointName = '/api/telemetry';
    } elseif ($isBotsEndpoint) {
        $apiLogEndpointName = '/api/bots';
    } elseif ($isDirectNodeRootRequest) {
        $apiLogEndpointName = '/api/node';
    } elseif ($method === 'POST') {
        $apiLogEndpointName = '/api/raw';
    }

    register_shutdown_function(function () use (
        $repository,
        $apiRequestStartTime,
        $method,
        $apiLogEndpointName,
        $apiRawInputForLog
    ) {
        try {
            $responseContent = ob_get_contents();
            if ($responseContent === false) {
                $responseContent = '';
            }
            $responseSize = strlen($responseContent);
            $durationMs = (int)round((microtime(true) - $apiRequestStartTime) * 1000);
            $statusCode = (int)(http_response_code() ?: 200);

            // Kliento IP adreso nustatymas (palaikomas Cloudflare ir reverse proxy)
            $clientIp = '';
            if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
                $clientIp = trim((string)$_SERVER['HTTP_CF_CONNECTING_IP']);
            } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
                $clientIp = trim((string)$_SERVER['HTTP_X_REAL_IP']);
            } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $parts = explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']);
                $clientIp = trim($parts[0]);
            } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
                $clientIp = trim((string)$_SERVER['REMOTE_ADDR']);
            }

            // Bot ID nustatymas iš GET, Header arba POST body
            $detectedBotId = $_GET['bot_id'] ?? ($_GET['bot'] ?? ($_SERVER['HTTP_X_BOT_ID'] ?? null));
            if (empty($detectedBotId) && $apiRawInputForLog !== '') {
                $decodedInput = json_decode($apiRawInputForLog, true);
                if (is_array($decodedInput) && !empty($decodedInput['bot_id'])) {
                    $detectedBotId = (string)$decodedInput['bot_id'];
                }
            }

            // Svarbiausios užklausos ir atsakymo antraštės
            $capturedHeaders = [];
            $headerKeys = [
                'HTTP_USER_AGENT'        => 'User-Agent',
                'HTTP_X_BOT_ID'          => 'X-Bot-ID',
                'HTTP_REFERER'           => 'Referer',
                'HTTP_ORIGIN'            => 'Origin',
                'HTTP_ACCEPT'            => 'Accept',
                'HTTP_ACCEPT_LANGUAGE'   => 'Accept-Language',
                'CONTENT_TYPE'           => 'Content-Type',
                'HTTP_CF_CONNECTING_IP'  => 'CF-Connecting-IP',
                'HTTP_CF_IPCOUNTRY'      => 'CF-IPCountry',
                'HTTP_X_FORWARDED_FOR'   => 'X-Forwarded-For',
                'REMOTE_ADDR'            => 'Remote-Addr',
            ];
            foreach ($headerKeys as $serverKey => $label) {
                if (!empty($_SERVER[$serverKey])) {
                    $capturedHeaders[$label] = (string)$_SERVER[$serverKey];
                }
            }
            if (function_exists('headers_list')) {
                foreach (headers_list() as $hLine) {
                    if (stripos($hLine, 'X-Cache:') === 0) {
                        $capturedHeaders['Response-X-Cache'] = trim(substr($hLine, 8));
                    }
                }
            }

            $repository->logApiRequest([
                'ip_address'    => $clientIp,
                'method'        => $method,
                'endpoint'      => $apiLogEndpointName,
                'request_uri'   => $_SERVER['REQUEST_URI'] ?? '/',
                'bot_id'        => $detectedBotId,
                'user_agent'    => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'status_code'   => $statusCode,
                'duration_ms'   => $durationMs,
                'response_size' => $responseSize,
                'request_body'  => $apiRawInputForLog,
                'response_body' => $responseContent,
                'headers_json'  => json_encode($capturedHeaders, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (\Throwable $e) {
            // Niekada netrikdome atsakymo grąžinimo
        }
    });
}

if ($isDirectNodeRootRequest && !$isNodeListEndpoint && !$isNodeDetailEndpoint) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error'   => 'Nenurodytas mazgo identifikatorius (public_key arba node_id). Kreipkitės /api/node/{public_key} arba /api/node/list.'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}


// -----------------------------------------------------------------------------
// 📡 ENDPOINT: GET /api/api/repeater (arba /api/repeater)
// Grąžina TIK 1 kartotuvą pagal requested_date prioritetą (tuščia data -> seniausia data)
// ir iškart pažymi requested_date = CURRENT_TIMESTAMP duomenų bazėje.
// Formatas: JSON objektas (arba 1 elemento masyvas su ?format=array)
// {
//   "name": "b9dd",
//   "public_id": "b9ddd788b7a59eab40eaa49a2534fa9e7fc0ef7d920ef4811139998cddd631db",
//   "password": "hello",
//   "isDirect": false,
//   "status": false,
//   "telemetry": true,
//   "requested_date": "2026-10-02 19:20:00"
// }
// -----------------------------------------------------------------------------
if ($isSingleRepeaterEndpoint) {
    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'error'   => 'Metodas nepalaikomas. /api/repeater priima tik GET užklausas.'
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    $requestedBotId = null;
    if (!empty($_GET['bot_id'])) {
        $requestedBotId = strtoupper(substr(trim((string)$_GET['bot_id']), 0, 16));
    } elseif (!empty($_SERVER['HTTP_X_BOT_ID'])) {
        $requestedBotId = strtoupper(substr(trim((string)$_SERVER['HTTP_X_BOT_ID']), 0, 16));
    }

    // Be bot_id jokie kartotuvai neatiduodami
    if (empty($requestedBotId)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error'   => 'Nenurodytas boto identifikatorius. Privaloma nurodyti ?bot_id=... parametrą arba X-Bot-ID antraštę (pvz. ?bot_id=KLP).'
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($repository && !$repository->isBotEnabled($requestedBotId)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error'   => "Botas '{$requestedBotId}' yra išjungtas per administravimo pultą."
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    $repeater = ($repository) ? $repository->getNextRepeaterToRequest($requestedBotId) : null;

    if (!$repeater) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error'   => "Nėra aktyvių kartotuvų eilėje botui '{$requestedBotId}' (visi atjungti, update=false arba viršijo nesėkmingų bandymų limitą)."
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Priverstinai nustatome status: false, kad ESP32 botas siųstų TIK telemetrijos užklausą (be GET_STATUS)
    $repeater['status'] = false;

    // Jei klientas paprašo formato kaip masyvo per ?format=array:
    $wantsArray = (isset($_GET['format']) && strtolower($_GET['format']) === 'array');
    echo json_encode($wantsArray ? [$repeater] : $repeater, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// -----------------------------------------------------------------------------
// 📡 ENDPOINT: GET /api/repyters (Visi stebimi kartotuvai)
// Formatas:
// [
//   {
//     "name": "b9dd",
//     "public_id": "b9ddd788b7a59eab40eaa49a2534fa9e7fc0ef7d920ef4811139998cddd631db",
//     "password": "hello",
//     "isDirect": false,
//     "status": false,
//     "telemetry": true
//   },
//   ...
// ]
// -----------------------------------------------------------------------------
if ($isRepytersEndpoint) {
    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'error'   => 'Metodas nepalaikomas. /api/repyters priima tik GET užklausas.'
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    $requestedBotId = null;
    if (!empty($_GET['bot_id'])) {
        $requestedBotId = strtoupper(substr(trim((string)$_GET['bot_id']), 0, 16));
    } elseif (!empty($_SERVER['HTTP_X_BOT_ID'])) {
        $requestedBotId = strtoupper(substr(trim((string)$_SERVER['HTTP_X_BOT_ID']), 0, 16));
    }

    // Be bot_id jokie kartotuvai neatiduodami
    if (empty($requestedBotId)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error'   => 'Nenurodytas boto identifikatorius. Privaloma nurodyti ?bot_id=... parametrą arba X-Bot-ID antraštę (pvz. ?bot_id=KLP).'
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($repository && !$repository->isBotEnabled($requestedBotId)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error'   => "Botas '{$requestedBotId}' yra išjungtas per administravimo pultą."
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    $repeatersList = ($repository) ? $repository->getMonitoredRepeaters($requestedBotId) : [];

    echo json_encode($repeatersList, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// -----------------------------------------------------------------------------
// 📡 ENDPOINT: GET /api/nodes (Kartotuvų sąrašas su paskutine telemetrija)
// -----------------------------------------------------------------------------
if ($isNodesEndpoint) {
    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'error'   => 'Metodas nepalaikomas. /api/nodes priima tik GET užklausas.'
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!$dbConnected || !$repository) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error'   => 'Duomenų bazė nepasiekiama: ' . $dbError,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    $all = isset($_GET['all']) && ($_GET['all'] === '1' || $_GET['all'] === 'true');
    $targetId = trim((string)($_GET['target'] ?? ($_GET['node_id'] ?? ($_GET['public_key'] ?? ($_GET['node'] ?? '')))));
    $repeaters = $repository->getRepeatersWithLatestTelemetry(!$all);

    // =========================================================================
    // 🇱🇹 PAPILDOMAS SLUOKSNIS: Geolokacijos filtracija (tik Lietuvos mazgai)
    // Atlieka filtraciją prieš formuojant galutinį JSON, jei įjungta admine.
    // =========================================================================
    $repeaters = $repository->filterNodesByGeolocationSettings($repeaters);

    // =========================================================================
    // ⏱️ PAPILDOMAS SLUOKSNIS: Telemetrijos senaties filtracija (senesni nei X d.)
    // =========================================================================
    $repeaters = $repository->filterNodesByTelemetryAgeSettings($repeaters);

    // =========================================================================
    // 📊 PAPILDOMAS SLUOKSNIS: Telemetrijos filtracija (nerodyti be telemetrijos)
    // Galima perduoti telemetry_only=1, has_telemetry=1 arba hide_no_telemetry=1
    // =========================================================================
    $telemetryOnly = false;
    if (isset($_GET['telemetry_only']) && ($_GET['telemetry_only'] === '1' || $_GET['telemetry_only'] === 'true')) {
        $telemetryOnly = true;
    } elseif (isset($_GET['has_telemetry']) && ($_GET['has_telemetry'] === '1' || $_GET['has_telemetry'] === 'true')) {
        $telemetryOnly = true;
    } elseif (isset($_GET['hide_no_telemetry']) && ($_GET['hide_no_telemetry'] === '1' || $_GET['hide_no_telemetry'] === 'true')) {
        $telemetryOnly = true;
    }

    if ($telemetryOnly) {
        $repeaters = array_values(array_filter($repeaters, function ($node) use ($targetId) {
            if (!empty($targetId)) {
                $ct = strtolower(ltrim(trim($targetId), '!#'));
                if (!empty($node['public_key']) && strtolower($node['public_key']) === $ct) return true;
                if (!empty($node['node_id']) && strtolower(ltrim($node['node_id'], '!#')) === $ct) return true;
            }
            return ($node['battery_v'] !== null || $node['temperature_c'] !== null || !empty($node['telemetry_updated_at']));
        }));
    }

    // Jei buvo nurodytas konkretus mazgas (?target= arba ?node_id= arba ?public_key=), užtikriname, kad jis BŪTŲ grąžintas sąraše
    if (!empty($targetId) && $repository) {
        $cleanTarget = strtolower(ltrim(trim($targetId), '!#'));
        $targetFound = false;
        foreach ($repeaters as $r) {
            if (
                (!empty($r['public_key']) && strtolower($r['public_key']) === $cleanTarget) ||
                (!empty($r['node_id']) && strtolower(ltrim($r['node_id'], '!#')) === $cleanTarget) ||
                (strlen($cleanTarget) >= 4 && !empty($r['public_key']) && substr(strtolower($r['public_key']), 0, strlen($cleanTarget)) === $cleanTarget)
            ) {
                $targetFound = true;
                break;
            }
        }

        if (!$targetFound) {
            try {
                $tStmt = $repository->getPdo()->prepare("
                    SELECT n.node_id, n.node_name, n.public_key, n.bot_id, n.is_repeater, n.is_disabled,
                           n.latitude, n.longitude, n.first_seen_at, n.last_seen_at, n.telemetry_updated_at,
                           b.name AS bot_name, b.enabled AS bot_enabled, b.public_key AS bot_public_key,
                           b.latitude AS bot_latitude, b.longitude AS bot_longitude
                    FROM nodes n
                    LEFT JOIN bots b ON UPPER(n.bot_id) = UPPER(b.bot_id)
                    WHERE (n.is_repeater = 1 OR n.is_repeater = true)
                      AND (LOWER(n.public_key) = :t1 OR LOWER(n.node_id) = :t2 OR LOWER(n.public_key) LIKE :t3 OR LOWER(n.node_id) LIKE :t4)
                    ORDER BY (LOWER(n.public_key) = :t_exact) DESC, n.id ASC
                    LIMIT 1
                ");
                $tStmt->execute([
                    ':t1'      => $cleanTarget,
                    ':t2'      => $cleanTarget,
                    ':t3'      => $cleanTarget . '%',
                    ':t4'      => '%' . $cleanTarget . '%',
                    ':t_exact' => $cleanTarget,
                ]);
                $tRow = $tStmt->fetch(PDO::FETCH_ASSOC);
                if ($tRow) {
                    $telemStmt = $repository->getPdo()->prepare("
                        SELECT battery_v, temperature_c, recorded_at
                        FROM telemetry
                        WHERE (node_id = :nid OR (public_key IS NOT NULL AND public_key = :pk))
                          AND type IN ('TELEMETRY', 'STATUS')
                        ORDER BY recorded_at DESC, id DESC
                        LIMIT 1
                    ");
                    $telemStmt->execute([
                        ':nid' => $tRow['node_id'] ?: '',
                        ':pk'  => $tRow['public_key'] ?: ''
                    ]);
                    $telemRow = $telemStmt->fetch(PDO::FETCH_ASSOC);

                    $tNodeId = !empty($tRow['node_id']) 
                        ? (string)$tRow['node_id'] 
                        : (!empty($tRow['public_key']) ? substr($tRow['public_key'], 0, 4) : 'node');

                    $repeaters[] = [
                        'name'                 => !empty($tRow['node_name']) ? (string)$tRow['node_name'] : $tNodeId,
                        'node_id'              => $tNodeId,
                        'public_key'           => !empty($tRow['public_key']) ? strtolower(trim((string)$tRow['public_key'])) : null,
                        'bot_id'               => !empty($tRow['bot_id']) ? (string)$tRow['bot_id'] : 'KLP',
                        'bot_name'             => $tRow['bot_name'] ?? null,
                        'bot_enabled'          => isset($tRow['bot_enabled']) ? (bool)$tRow['bot_enabled'] : true,
                        'bot_public_key'       => $tRow['bot_public_key'] ?? null,
                        'bot_latitude'         => isset($tRow['bot_latitude']) && $tRow['bot_latitude'] !== null ? (float)$tRow['bot_latitude'] : null,
                        'bot_longitude'        => isset($tRow['bot_longitude']) && $tRow['bot_longitude'] !== null ? (float)$tRow['bot_longitude'] : null,
                        'is_repeater'          => (bool)$tRow['is_repeater'],
                        'is_disabled'          => (bool)($tRow['is_disabled'] ?? false),
                        'latitude'             => ($tRow['latitude'] !== null) ? (float)$tRow['latitude'] : null,
                        'longitude'            => ($tRow['longitude'] !== null) ? (float)$tRow['longitude'] : null,
                        'battery_v'            => ($telemRow && $telemRow['battery_v'] !== null) ? (float)$telemRow['battery_v'] : null,
                        'temperature_c'        => ($telemRow && $telemRow['temperature_c'] !== null) ? (float)$telemRow['temperature_c'] : null,
                        'telemetry_updated_at' => ($telemRow && !empty($telemRow['recorded_at'])) ? $telemRow['recorded_at'] : $tRow['telemetry_updated_at'],
                        'telemetry_fail_count' => 0,
                        'first_seen_at'        => $tRow['first_seen_at'] ?? null,
                        'last_seen_at'         => $tRow['last_seen_at'],
                    ];
                }
            } catch (\Throwable $e) {}
        }
    }

    $allBots = ($repository) ? $repository->getBotsWithNodeCounts() : [];

    echo json_encode([
        'success'                => true,
        'endpoint'               => '/api/nodes',
        'filter'                 => $all ? 'all_nodes' : 'repeaters_only',
        'telemetry_only'         => $telemetryOnly,
        'telemetry_chart_days'   => $repository ? (int)$repository->getSetting('telemetry_chart_days', 7) : 7,
        'max_telemetry_age_days' => $repository ? (int)$repository->getSetting('max_telemetry_age_days', 0) : 0,
        'count'                  => count($repeaters),
        'nodes'                  => $repeaters,
        'bots'                   => $allBots,
        'timestamp'              => date('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// -----------------------------------------------------------------------------
// 🤖 ENDPOINT: GET /api/bots (ESP32 botų / vartų sąrašas)
// -----------------------------------------------------------------------------
if ($isBotsEndpoint) {
    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'error'   => 'Metodas nepalaikomas. /api/bots priima tik GET užklausas.'
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!$dbConnected || !$repository) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error'   => 'Duomenų bazė nepasiekiama: ' . $dbError,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    $bots = $repository->getBotsWithNodeCounts();
    echo json_encode([
        'success'   => true,
        'endpoint'  => '/api/bots',
        'count'     => count($bots),
        'bots'      => $bots,
        'timestamp' => date('Y-m-d H:i:s'),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// -----------------------------------------------------------------------------
// 📈 ENDPOINT: GET /api/telemetry (arba /telemetry)
// Grąžina mazgo telemetrijos istoriją (temperatūrą ir baterijos įtampą/srovę) grafikams.
// Parametrai:
//   node_id (arba node, public_key) - mazgo identifikatorius
//   days - dienų skaičius (numatytai: 'telemetry_chart_days' nustatymas, pvz. 7)
// -----------------------------------------------------------------------------
if ($isTelemetryEndpoint) {
    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'error'   => 'Metodas nepalaikomas. /api/telemetry priima tik GET užklausas.'
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!$dbConnected || !$repository) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error'   => 'Duomenų bazė nepasiekiama: ' . $dbError
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    $nodeId = $_GET['node_id'] ?? ($_GET['node'] ?? ($_GET['public_key'] ?? null));
    $days = (isset($_GET['days']) && is_numeric($_GET['days'])) ? (int)$_GET['days'] : null;

    if (empty($nodeId)) {
        // Bendras filtruotas telemetrijos sąrašas
        $limit = max(1, min(500, (int)($_GET['limit'] ?? 50)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));
        $result = $repository->getFilteredTelemetry([], $limit, $offset);
        echo json_encode(array_merge(['success' => true], $result), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    $history = $repository->getNodeTelemetryHistory((string)$nodeId, $days);

    echo json_encode([
        'success'              => true,
        'endpoint'             => '/api/telemetry',
        'node_id'              => (string)$nodeId,
        'days'                 => $history['days'],
        'telemetry_chart_days' => $history['days'],
        'count'                => count($history['records']),
        'records'              => $history['records'],
        'timestamp'            => date('Y-m-d H:i:s'),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// -----------------------------------------------------------------------------
// 📋 ENDPOINT: GET /api/node/list (arba /node/list)
// Surenka visus įjungtus (enabled) mazgus su telemetrija.
// Kešuojamas pagal administravimo pulto 'api_cache_ttl_mins' nustatymą (minutėmis).
// -----------------------------------------------------------------------------
if ($isNodeListEndpoint) {
    http_response_code(200);
    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'error'   => 'Metodas nepalaikomas. /api/node/list priima tik GET užklausas.'
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    $apiCacheTtlMins = $repository ? (int)$repository->getSetting('api_cache_ttl_mins', 5) : 5;
    $cacheService = new \MeshCore\Services\ApiCacheService();
    $cacheKey = 'node_list';

    // Tikriname kešą
    $cached = $cacheService->get($cacheKey, $apiCacheTtlMins);
    if ($cached !== null) {
        $remaining = $cacheService->getRemainingTtl($cacheKey, $apiCacheTtlMins);
        header('X-Cache: HIT');
        header('Cache-Control: public, max-age=' . $remaining);
        echo $cached;
        exit;
    }

    if (!$dbConnected || !$repository) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error'   => 'Duomenų bazė nepasiekiama: ' . $dbError,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Automatiškai ištaisome prefiksų kolizijas (pvz. 'abcd' klientas vs kartotuvas) ir išvalome ne-kartotuvų telemetriją
    try {
        $repository->cleanupInvalidTelemetry();
    } catch (\Throwable $e) {}

    // Gauname tik kartotuvus (is_repeater = true), nes telemetrija renkama ir priskiriama tik kartotuvams
    $allNodes = $repository->getRepeatersWithLatestTelemetry(true);

    // Filtruojame: tik įjungti (is_disabled = false/0/null) IR turintys telemetriją
    $enabledWithTelemetry = [];
    foreach ($allNodes as $node) {
        if (!empty($node['is_disabled'])) {
            continue;
        }

        $hasTelemetry = (
            $node['battery_v'] !== null ||
            $node['temperature_c'] !== null ||
            !empty($node['telemetry_updated_at'])
        );

        if (!$hasTelemetry) {
            continue;
        }

        $enabledWithTelemetry[] = [
            'node_id'              => $node['node_id'],
            'node_name'            => $node['name'] ?? $node['node_name'] ?? $node['node_id'],
            'public_key'           => !empty($node['public_key']) ? strtolower(trim((string)$node['public_key'])) : null,
            'bot_id'               => $node['bot_id'] ?? null,
            'bot_name'             => $node['bot_name'] ?? null,
            'bot_enabled'          => isset($node['bot_enabled']) ? (bool)$node['bot_enabled'] : true,
            'is_repeater'          => (bool)($node['is_repeater'] ?? false),
            'latitude'             => ($node['latitude'] !== null) ? (float)$node['latitude'] : null,
            'longitude'            => ($node['longitude'] !== null) ? (float)$node['longitude'] : null,
            'battery_v'            => ($node['battery_v'] !== null) ? (float)$node['battery_v'] : null,
            'temperature_c'        => ($node['temperature_c'] !== null) ? (float)$node['temperature_c'] : null,
            'telemetry_updated_at' => $node['telemetry_updated_at'] ?? null,
            'last_seen_at'         => $node['last_seen_at'] ?? null,
            'first_seen_at'        => $node['first_seen_at'] ?? null,
            'telemetry_fail_count' => (int)($node['telemetry_fail_count'] ?? 0),
        ];
    }

    $payload = [
        'success'     => true,
        'endpoint'    => '/api/node/list',
        'ttl_minutes' => $apiCacheTtlMins,
        'cached'      => false,
        'count'       => count($enabledWithTelemetry),
        'nodes'       => $enabledWithTelemetry,
        'timestamp'   => date('Y-m-d H:i:s'),
    ];

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($apiCacheTtlMins > 0) {
        $cacheService->set($cacheKey, $json);
        header('X-Cache: MISS');
        header('Cache-Control: public, max-age=' . ($apiCacheTtlMins * 60));
    } else {
        header('X-Cache: BYPASS');
        header('Cache-Control: no-cache, no-store, must-revalidate');
    }

    echo $json;
    exit;
}

// -----------------------------------------------------------------------------
// 📈 ENDPOINT: GET /node/{public_key} (arba /api/node/{public_key})
// Grąžina surinktą konkretaus mazgo telemetriją tam tikram dienų skaičiui.
// Dienų skaičius nustatomas admine ('api_node_telemetry_days', pvz. 7 d.).
// Kešuojamas pagal tą patį TTL nustatymą ('api_cache_ttl_mins', minutėmis).
// -----------------------------------------------------------------------------
if ($isNodeDetailEndpoint) {
    http_response_code(200);
    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'error'   => 'Metodas nepalaikomas. /node/{public_key} priima tik GET užklausas.'
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    $cleanTarget = strtolower(trim((string)$nodeDetailPublicKey));
    $strippedTarget = ltrim($cleanTarget, '!#');

    $apiCacheTtlMins = $repository ? (int)$repository->getSetting('api_cache_ttl_mins', 5) : 5;
    $configuredLimit = 50;
    if ($repository) {
        $val = $repository->getSetting('api_node_telemetry_limit', null);
        if ($val === null) {
            $val = $repository->getSetting('api_node_telemetry_days', 50);
        }
        $configuredLimit = max(1, (int)$val);
    }

    $limit = $configuredLimit;
    if (isset($_GET['limit']) && is_numeric($_GET['limit']) && (int)$_GET['limit'] > 0) {
        $limit = min(5000, max(1, (int)$_GET['limit']));
    } elseif (isset($_GET['count']) && is_numeric($_GET['count']) && (int)$_GET['count'] > 0) {
        $limit = min(5000, max(1, (int)$_GET['count']));
    } elseif (isset($_GET['records']) && is_numeric($_GET['records']) && (int)$_GET['records'] > 0) {
        $limit = min(5000, max(1, (int)$_GET['records']));
    }

    $cacheService = new \MeshCore\Services\ApiCacheService();
    $cacheKey = 'node_' . md5($cleanTarget . '_' . $limit);

    // Tikriname kešą
    $bypassCache = isset($_GET['nocache']) || isset($_GET['no_cache']) || isset($_GET['force']);
    if (!$bypassCache) {
        $cached = $cacheService->get($cacheKey, $apiCacheTtlMins);
        if ($cached !== null) {
            $remaining = $cacheService->getRemainingTtl($cacheKey, $apiCacheTtlMins);
            header('X-Cache: HIT');
            header('Cache-Control: public, max-age=' . $remaining);
            echo $cached;
            exit;
        }
    }

    if (!$dbConnected || !$repository) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error'   => 'Duomenų bazė nepasiekiama: ' . $dbError,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 1. Surandame mazgo duomenis iš 'nodes' lentelės (pirmiausia sutvarkome prefiksų kolizijas)
    try {
        $repository->cleanupInvalidTelemetry();
    } catch (\Throwable $e) {}
    $nodeInfo = null;
    $resolvedNodeId = $strippedTarget;
    $resolvedPublicKey = $cleanTarget;

    try {
        $pdo = $repository->getPdo();
        $nStmt = $pdo->prepare("
            SELECT n.node_id, n.node_name, n.public_key, n.bot_id, n.is_repeater, n.is_disabled,
                   n.latitude, n.longitude, n.battery_v AS advert_battery_v, n.temperature_c AS node_temp_c,
                   n.first_seen_at, n.last_seen_at, n.telemetry_updated_at, n.telemetry_fail_count,
                   b.name AS bot_name, b.enabled AS bot_enabled
            FROM nodes n
            LEFT JOIN bots b ON UPPER(n.bot_id) = UPPER(b.bot_id)
            WHERE (n.is_repeater = 1 OR n.is_repeater = true)
              AND (
                   LOWER(n.public_key) = :pk1
                OR LOWER(n.node_id) = :nid1
                OR LOWER(n.node_id) = :nid2
                OR LOWER(n.bot_id) = :bid1
                OR (LENGTH(:pk_prefix) >= 4 AND LOWER(n.public_key) LIKE :pk_prefix_like)
                OR LOWER(n.node_name) = :name1
              )
            ORDER BY (LOWER(n.public_key) = :pk_exact) DESC, n.id ASC
            LIMIT 1
        ");
        $nStmt->execute([
            ':pk1'            => $cleanTarget,
            ':nid1'           => $cleanTarget,
            ':nid2'           => $strippedTarget,
            ':bid1'           => $cleanTarget,
            ':pk_prefix'      => $strippedTarget,
            ':pk_prefix_like' => $strippedTarget . '%',
            ':name1'          => $cleanTarget,
            ':pk_exact'       => $cleanTarget,
        ]);
        $nodeRow = $nStmt->fetch(PDO::FETCH_ASSOC);

        if ($nodeRow) {
            $resolvedNodeId = $nodeRow['node_id'] ?: $strippedTarget;
            $resolvedPublicKey = $nodeRow['public_key'] ? strtolower($nodeRow['public_key']) : $cleanTarget;

            $nodeInfo = [
                'node_id'              => $nodeRow['node_id'],
                'node_name'            => $nodeRow['node_name'] ?: $nodeRow['node_id'],
                'public_key'           => $nodeRow['public_key'] ? strtolower($nodeRow['public_key']) : null,
                'bot_id'               => $nodeRow['bot_id'] ?? null,
                'bot_name'             => $nodeRow['bot_name'] ?? null,
                'bot_enabled'          => isset($nodeRow['bot_enabled']) ? (bool)$nodeRow['bot_enabled'] : true,
                'is_repeater'          => (bool)$nodeRow['is_repeater'],
                'is_disabled'          => (bool)($nodeRow['is_disabled'] ?? false),
                'latitude'             => ($nodeRow['latitude'] !== null) ? (float)$nodeRow['latitude'] : null,
                'longitude'            => ($nodeRow['longitude'] !== null) ? (float)$nodeRow['longitude'] : null,
                'telemetry_updated_at' => $nodeRow['telemetry_updated_at'],
                'last_seen_at'         => $nodeRow['last_seen_at'],
                'first_seen_at'        => $nodeRow['first_seen_at'] ?? null,
                'telemetry_fail_count' => (int)($nodeRow['telemetry_fail_count'] ?? 0),
            ];
        }
    } catch (\Throwable $e) {}

    // 2. Nuskaitome telemetrijos įrašus pagal nurodytą paskutinių įrašų limitą
    $telemetryRecords = [];

    try {
        $pdo = $repository->getPdo();
        $safeLimit = max(1, min(5000, (int)$limit));

        $pk4 = (strlen($resolvedPublicKey) >= 4) ? substr($resolvedPublicKey, 0, 4) : $resolvedNodeId;
        $pk8 = (strlen($resolvedPublicKey) >= 8) ? substr($resolvedPublicKey, 0, 8) : $resolvedNodeId;
        $cleanStrip = ltrim($cleanTarget, '!#');
        $nodeIdStrip = ltrim($resolvedNodeId, '!#');

        $tSql = "
            SELECT id, recorded_at, type, battery_v, battery_mv, temperature_c,
                   rssi, snr, noise_floor, uptime_seconds, air_time_seconds,
                   packets_recv, packets_sent, tx_queue_len, error_count, bot_id
            FROM telemetry
            WHERE (
                LOWER(public_key) = :pk
                OR LOWER(public_key) = :clean_target_pk
                OR LOWER(node_id) = :nid
                OR LOWER(node_id) = :nid_strip
                OR LOWER(node_id) = :clean_target_nid
                OR LOWER(node_id) = :clean_strip_nid
                OR LOWER(node_id) = :pk4
                OR LOWER(node_id) = :pk8
                OR (LENGTH(:pk_prefix) >= 4 AND LOWER(public_key) LIKE :pk_prefix_like)
            )
            ORDER BY recorded_at DESC, id DESC
            LIMIT {$safeLimit}
        ";
        $tStmt = $pdo->prepare($tSql);
        $tStmt->execute([
            ':pk'               => $resolvedPublicKey,
            ':clean_target_pk'  => $cleanTarget,
            ':nid'              => $resolvedNodeId,
            ':nid_strip'        => $nodeIdStrip,
            ':clean_target_nid' => $cleanTarget,
            ':clean_strip_nid'  => $cleanStrip,
            ':pk4'              => $pk4,
            ':pk8'              => $pk8,
            ':pk_prefix'        => $pk8,
            ':pk_prefix_like'   => $pk8 . '%',
        ]);
        $rows = $tStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Kadangi paėmėme naujausius įrašus (ORDER BY recorded_at DESC),
        // apverčiame masyvą, kad telemetrijos taškai eitų chronologine tvarka (seniausias -> naujausias)
        $rows = array_reverse($rows);

        $tz = new DateTimeZone('Europe/Vilnius');

        foreach ($rows as $r) {
            $rawDate = $r['recorded_at'];
            $timeFormatted = $rawDate;
            $timeShort = $rawDate;
            $iso = $rawDate;

            try {
                $dt = new DateTime($rawDate, $tz);
                $timeFormatted = $dt->format('Y-m-d H:i:s');
                $timeShort = $dt->format('m-d H:i');
                $iso = $dt->format(DateTimeInterface::ATOM);
            } catch (\Throwable $e) {}

            $battV = ($r['battery_v'] !== null)
                ? (float)$r['battery_v']
                : (($r['battery_mv'] !== null) ? round(((int)$r['battery_mv']) / 1000.0, 2) : null);

            $tempC = ($r['temperature_c'] !== null) ? (float)$r['temperature_c'] : null;

            $item = [
                'time'          => $timeFormatted,
                'time_short'    => $timeShort,
                'iso'           => $iso,
                'type'          => $r['type'],
                'battery_v'     => $battV,
                'temperature_c' => $tempC,
            ];

            if ($r['rssi'] !== null) $item['rssi'] = (float)$r['rssi'];
            if ($r['snr'] !== null) $item['snr'] = (float)$r['snr'];
            if ($r['noise_floor'] !== null) $item['noise_floor'] = (int)$r['noise_floor'];
            if ($r['uptime_seconds'] !== null) $item['uptime_seconds'] = (int)$r['uptime_seconds'];
            if ($r['air_time_seconds'] !== null) $item['air_time_seconds'] = (int)$r['air_time_seconds'];
            if ($r['packets_recv'] !== null) $item['packets_recv'] = (int)$r['packets_recv'];
            if ($r['packets_sent'] !== null) $item['packets_sent'] = (int)$r['packets_sent'];
            if (!empty($r['bot_id'])) $item['bot_id'] = $r['bot_id'];

            $telemetryRecords[] = $item;
        }
    } catch (\Throwable $e) {
        $queryError = $e->getMessage();
    }

    // Jei nerasta nei mazgo informacijos, nei telemetrijos įrašų - grąžiname 404
    if ($nodeInfo === null && empty($telemetryRecords)) {
        http_response_code(404);
        echo json_encode([
            'success'    => false,
            'error'      => 'Mazgas su nurodytu raktu nerastas arba neturi telemetrijos duomenų.',
            'public_key' => $cleanTarget,
            'limit'      => $limit,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    $payload = [
        'success'     => true,
        'endpoint'    => '/node/' . $cleanTarget,
        'public_key'  => $resolvedPublicKey ?: $cleanTarget,
        'limit'       => $limit,
        'ttl_minutes' => $apiCacheTtlMins,
        'cached'      => false,
        'node'        => $nodeInfo,
        'count'       => count($telemetryRecords),
        'telemetry'   => $telemetryRecords,
        'timestamp'   => date('Y-m-d H:i:s'),
    ];

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($apiCacheTtlMins > 0) {
        $cacheService->set($cacheKey, $json);
        header('X-Cache: MISS');
        header('Cache-Control: public, max-age=' . ($apiCacheTtlMins * 60));
    } else {
        header('X-Cache: BYPASS');
        header('Cache-Control: no-cache, no-store, must-revalidate');
    }

    echo $json;
    exit;
}

// GET užklausos atveju pateikiame endpoint informaciją, būseną ir eilių statistiką
if ($method === 'GET') {
    $stats = ($dbConnected && $repository) ? $repository->getStats() : null;
    $nodeStats = ($dbConnected && $repository) ? $repository->getNodeStats() : null;

    echo json_encode([
        'status'      => 'active',
        'service'     => 'MeshCore LoRa RAW Packet Decoder & Database Storage API',
        'file'        => 'app.php',
        'description' => 'Priima visas pagautas neapdorotas (RAW) LoRa užklausas tiesiai į šį vienintelį endpointą ir saugo bei apdoroja serveryje.',
        'database'    => [
            'status' => $dbConnected ? 'connected' : 'error',
            'error'  => $dbError,
            'config' => [
                'host'     => RawPacketRepository::DEFAULT_CONFIG['host'],
                'port'     => RawPacketRepository::DEFAULT_CONFIG['port'],
                'database' => RawPacketRepository::DEFAULT_CONFIG['dbname'],
                'user'     => RawPacketRepository::DEFAULT_CONFIG['user'],
            ],
            'queue_stats' => $stats,
            'node_stats'  => $nodeStats,
        ],
        'endpoints' => [
            'GET /api/repeater' => 'Grąžina TIK 1 kartotuvą eilėje (su tuščia arba seniausia requested_date) ir pažymi DB requested_date = CURRENT_TIMESTAMP.',
            'GET /api/repyters'     => 'Grąžina visų stebimų kartotuvų sąrašą tiksliai tokiu formatu, kokio reikalauja ESP32 bot (name, public_id, password, isDirect, status, telemetry).',
            'GET /api/nodes'        => 'Grąžina žemėlapiui optimizuotus kartotuvus (nodes) su paskutine telemetrija, surūšiuotus pagal telemetrijos atnaujinimo datą nuo naujausio.',
            'GET /api/node/list'    => 'Grąžina visus įjungtus (enabled) mazgus su telemetrija. Atsakymas kešuojamas (TTL nustatomas admine minutėmis).',
            'GET /node/{public_key}' => 'Grąžina konkretaus mazgo paskutinius telemetrijos įrašus (kiekis nustatomas admine arba per ?limit=). Kešuojamas pagal TTL.',
            'POST /api/raw'         => 'Visas LoRa srautas (RX/TX) išsaugomas DB eilėje (processed = false). Dekodavimas vyksta fone per process_queue.php.',
        ],
        'deprecated_endpoints' => [
            'POST /api/adverts'   => 'Panaikinta. Skelbimai apdorojami serveryje iš RAW srauto.',
            'POST /api/telemetry' => 'Panaikinta. Telemetrija apdorojama serveryje iš RAW srauto.',
        ],
        'sorting_rule' => 'Prioritetas įrašams su tuščia data (processed_at IS NULL), o po to vėliausia processed_at su processed = false.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// Tikriname, ar tai POST užklausa
if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error'   => 'Metodas nepalaikomas. Siųskite POST užklausą į /api/raw.'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// Nuskaitome užklausos turinį
$rawBody = file_get_contents('php://input');

// Papildomas rakto gavimas iš antraščių arba GET parametrų
$headerSecret = $_SERVER['HTTP_X_SHARED_SECRET'] 
    ?? ($_GET['shared_secret_hex'] ?? ($_GET['secret'] ?? ($_GET['key'] ?? null)));

$payloadData = null;
$rawHex = null;
$sharedSecret = $headerSecret;

// 1. Bandome iškoduoti kaip JSON
$jsonData = json_decode($rawBody, true);

if (is_array($jsonData)) {
    if (!empty($headerSecret) && empty($jsonData['shared_secret_hex'])) {
        $jsonData['shared_secret_hex'] = $headerSecret;
    }
    $payloadData  = $jsonData;
    $rawHex       = $jsonData['raw_hex'] ?? ($jsonData['raw'] ?? null);
    $sharedSecret = $jsonData['shared_secret_hex'] ?? $headerSecret;
}
// 2. Bandome gauti iš $_POST (Form data)
elseif (!empty($_POST['raw_hex']) || !empty($_POST['raw'])) {
    $rawHex = $_POST['raw_hex'] ?? $_POST['raw'];
    $sharedSecret = $_POST['shared_secret_hex'] ?? ($_POST['secret'] ?? ($_POST['key'] ?? $headerSecret));
    $payloadData = [
        'raw_hex'           => $rawHex,
        'shared_secret_hex' => $sharedSecret,
        'direction'         => $_POST['direction'] ?? null,
        'rssi'              => $_POST['rssi'] ?? null,
        'snr'               => $_POST['snr'] ?? null,
    ];
}
// 3. Bandome tiesioginį RAW tekstą/heksą iš body
elseif (!empty(trim($rawBody))) {
    $rawHex = trim($rawBody);
    $payloadData = [
        'raw_hex'           => $rawHex,
        'shared_secret_hex' => $headerSecret,
    ];
} else {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Tuščia POST užklausa. Nepateikta jokių duomenų.'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($rawHex)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Nenurodytas raw_hex paketas užklausoje.'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// -----------------------------------------------------------------------------
// 💾 1. DUOMENŲ BAZĖ: Išsaugome atėjusį RAW įrašą su processed = false ir processed_at = NULL
// -----------------------------------------------------------------------------
$recordId = null;
$dbSaveError = null;

if ($dbConnected && $repository) {
    try {
        $recordId = $repository->saveRaw($payloadData, $sharedSecret);
    } catch (\Throwable $e) {
        $dbSaveError = $e->getMessage();
    }
}

// -----------------------------------------------------------------------------
// ⚙️ 2. REALAUS LAIKO APDOROJIMAS SU ATSARGINE EILE (FALLBACK QUEUE)
// -----------------------------------------------------------------------------
// Iškart dekoduojame ir apdorojame gautą paketą (~2–4 ms), kad maršrutai (last_path),
// telemetrija (telemetry_updated_at) ir sesijos (skip_login) atsinaujintų akimirksniu,
// nelaukiant iki 60 sek. trunkančio process_queue.php cron kvietimo.
// Jei dėl kokios nors priežasties tiesioginis apdorojimas nepavyktų, įrašas lieka
// raw_packets eilėje (processed = false) ir bus apdorotas per process_queue.php.
$forceSync = true;
$decoded = null;

if ($dbConnected && $repository && $recordId) {
    try {
        $decoded = $repository->processRecord((int)$recordId, $decoder);
    } catch (\Throwable $e) {
        $forceSync = false;
    }
} elseif ($forceSync) {
    if (is_array($payloadData) && count($payloadData) > 2) {
        $decoded = $decoder->decodeApiPayload($payloadData);
    } else {
        $decoded = $decoder->decodeRawPacket($rawHex, $sharedSecret);
    }
}

// Log katalogas diagnostikai
$logDir = __DIR__ . '/../logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0777, true);
}

$logEntry = [
    'received_at' => date('Y-m-d H:i:s'),
    'db_id'       => $recordId,
    'mode'        => $forceSync ? 'sync' : 'queued_async',
    'raw_hex'     => $rawHex,
    'direction'   => $payloadData['direction'] ?? null,
    'db_error'    => $dbSaveError,
];
if ($forceSync) {
    $logEntry['decoded'] = $decoded;
}
@file_put_contents($logDir . '/raw.log', json_encode($logEntry, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND);

// Papildome atsakymą duomenų bazės metaduomenimis (atsakome žaibiškai, be blokuojančio dekodavimo)
$response = [
    'success'   => ($dbSaveError === null),
    'message'   => $forceSync
        ? 'RAW paketas išsaugotas ir sinchroniškai iškoduotas.'
        : 'RAW paketas sėkmingai priimtas ir patalpintas į apdorojimo eilę (dekodavimas per process_queue.php).',
    'queued'    => !$forceSync,
    'database'  => [
        'stored'        => $recordId !== null,
        'record_id'     => $recordId,
        'processed'     => $forceSync && !empty($decoded['success']),
        'db_error'      => $dbSaveError ?? $dbError,
    ],
    'raw_hex'   => $rawHex,
];

if ($forceSync) {
    $response['decoded'] = $decoded;
}

if ($dbSaveError === null) {
    http_response_code(200);
} else {
    http_response_code(500);
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
