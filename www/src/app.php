<?php

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

// GET užklausos atveju pateikiame endpoint informaciją, būseną ir eilių statistiką
if ($method === 'GET') {
    $stats = ($dbConnected && $repository) ? $repository->getStats() : null;

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
        ],
        'endpoints' => [
            'POST /api/raw' => 'Visas LoRa srautas (RX/TX) siunčiamas čia. Visi paketai išsaugomi DB ir automatiškai iškoduojami serveryje.',
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
// 💾 1. DUOMENŲ BAZĖ: Išsaugome atėjusį RAW įrašą su processed = false
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
// ⚙️ 2. SERVERYJE: Atliekame dešifravimą ir pilną protokolo iškodavimą
// -----------------------------------------------------------------------------
$decoded = null;
if (is_array($payloadData) && count($payloadData) > 2) {
    $decoded = $decoder->decodeApiPayload($payloadData);
} else {
    $decoded = $decoder->decodeRawPacket($rawHex, $sharedSecret);
}

// -----------------------------------------------------------------------------
// 💾 3. DUOMENŲ BAZĖ: Užfiksuojame apdorojimą (processed_at, po to processed = true)
// -----------------------------------------------------------------------------
if ($dbConnected && $repository && $recordId) {
    try {
        if (!empty($decoded['success'])) {
            // Pasižymi apdorojimo datą ir uždeda processed = true
            $repository->completeProcessing($recordId, $decoded);
        } else {
            // Fiksuoja klaidą, palieka processed = false su veliausia processed_at
            $repository->failProcessing($recordId, $decoded['error'] ?? 'Dekodavimo klaida');
        }
    } catch (\Throwable $e) {
        // DB atnaujinimo klaida
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
    'raw_request' => $payloadData,
    'decoded'     => $decoded,
];
@file_put_contents($logDir . '/raw.log', json_encode($logEntry, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND);

// Papildome atsakymą duomenų bazės metaduomenimis
$response = [
    'success'            => !empty($decoded['success']),
    'database'           => [
        'stored'        => $recordId !== null,
        'record_id'     => $recordId,
        'processed'     => !empty($decoded['success']),
        'db_error'      => $dbSaveError ?? $dbError,
    ],
    'decoded'            => $decoded,
];

if (!empty($decoded['success'])) {
    http_response_code(200);
} else {
    http_response_code(422); // Unprocessable Entity
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
