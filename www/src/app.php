<?php

// Nustatome Lietuvos laiko juostą
date_default_timezone_set('Europe/Vilnius');

// Automatinis Composer arba vietinių klasių užkrovimas
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
    // Užtikriname, kad lentelė ir indeksai egzistuoja
    $repository->createTableIfNotExists();
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
    $repeaters = $repository->getRepeatersWithLatestTelemetry(!$all);

    // =========================================================================
    // 🇱🇹 PAPILDOMAS SLUOKSNIS: Geolokacijos filtracija (tik Lietuvos mazgai)
    // Atlieka filtraciją prieš formuojant galutinį JSON, jei įjungta admine.
    // =========================================================================
    $repeaters = $repository->filterNodesByGeolocationSettings($repeaters);

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
        $repeaters = array_values(array_filter($repeaters, function ($node) {
            return ($node['battery_v'] !== null || $node['temperature_c'] !== null);
        }));
    }

    echo json_encode([
        'success'              => true,
        'endpoint'             => '/api/nodes',
        'filter'               => $all ? 'all_nodes' : 'repeaters_only',
        'telemetry_only'       => $telemetryOnly,
        'telemetry_chart_days' => $repository ? (int)$repository->getSetting('telemetry_chart_days', 7) : 7,
        'count'                => count($repeaters),
        'repeaters'            => $repeaters,
        'nodes'                => $repeaters,
        'timestamp'            => date('Y-m-d H:i:s'),
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
// ⚙️ 2. ASINCHRONINIS / SINCHRONINIS REŽIMAS
// -----------------------------------------------------------------------------
// Pagal nutylėjimą: ASINCHRONINIS (paketas patenka į eilę su processed = false,
// o dekodavimas vyksta fone paleidus process_queue.php).
// Jei testavimo tikslais pateiktas ?sync=1 arba ?decode=1 – galima atlikti ir tiesioginį dekodavimą.
$forceSync = isset($_GET['sync']) || isset($_GET['decode']) || (!empty($payloadData['sync']));

$decoded = null;
if ($forceSync) {
    if (is_array($payloadData) && count($payloadData) > 2) {
        $decoded = $decoder->decodeApiPayload($payloadData);
    } else {
        $decoded = $decoder->decodeRawPacket($rawHex, $sharedSecret);
    }

    if ($dbConnected && $repository && $recordId) {
        try {
            if (!empty($decoded['advert']) && is_array($decoded['advert']) && !empty($decoded['advert']['public_key'])) {
                $repository->upsertNodeFromAdvert($decoded['advert'], $payloadData);
            }

            $repository->extractAndSaveTelemetry($recordId, $decoded, $payloadData);

            if (is_array($payloadData) && !empty($payloadData['direction']) && strtoupper((string)$payloadData['direction']) === 'RX') {
                $senderKey = !empty($payloadData['sender_public_key']) ? (string)$payloadData['sender_public_key'] : null;
                if ($senderKey) {
                    $bId = !empty($payloadData['bot_id']) ? (string)$payloadData['bot_id'] : 'KLP';
                    $r = isset($payloadData['rssi']) ? (float)$payloadData['rssi'] : null;
                    $s = isset($payloadData['snr']) ? (float)$payloadData['snr'] : null;
                    $repository->touchNodeSeen($senderKey, $bId, null, null, $r, $s);
                }
            }

            $errorMsg = null;
            if (!empty($decoded['error'])) {
                $errorMsg = (string)$decoded['error'];
            } elseif (!empty($decoded['encryption']['decryption_error'])) {
                $errorMsg = (string)$decoded['encryption']['decryption_error'];
            } elseif (!empty($decoded['advert']['error'])) {
                $errorMsg = (string)$decoded['advert']['error'];
            }

            if (!empty($decoded['success'])) {
                $repository->completeProcessing($recordId, $decoded, $errorMsg);
            } else {
                $repository->failProcessing($recordId, $errorMsg ?? 'Dekodavimo klaida', $decoded);
            }
        } catch (\Throwable $e) {
            // DB atnaujinimo klaida
        }
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
