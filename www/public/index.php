<?php

// Automatinis Composer arba vietinės klasės užkrovimas
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
} else {
    require_once __DIR__ . '/../src/MeshCoreDecoder.php';
}

use MeshCore\MeshCoreDecoder;

header('Content-Type: application/json; charset=utf-8');

$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method     = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$decoder = new MeshCoreDecoder();

// Būsenos ir informacijos puslapis
if ($method === 'GET') {
    echo json_encode([
        'status'    => 'active',
        'service'   => 'MeshCore LoRa Telemetry & RAW Decoder API',
        'endpoints' => [
            'POST /api/raw'       => 'Priima ir automatiškai dešifruoja neapdorotus LoRa paketus',
            'POST /api/adverts'   => 'Priima mazgų tapatybės skelbimus (ADVERT)',
            'POST /api/telemetry' => 'Priima kartotuvų telemetrijos duomenis',
        ],
        'version'   => '1.0.0'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Metodas nepalaikomas. Naudokite POST.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Skaitome gautą JSON turinį
$rawBody = file_get_contents('php://input');
if (empty($rawBody)) {
    http_response_code(400);
    echo json_encode(['error' => 'Tuščias užklausos turinys.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Neteisingas JSON formatas.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Log katalogas diagnostikai
$logDir = __DIR__ . '/../logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0777, true);
}

// 1. RAW Srauto apdorojimas (/api/raw)
if ($requestUri === '/api/raw') {
    $decodedResult = $decoder->decodeApiPayload($payload);

    // Išsaugome įrašą diagnostikai
    $logEntry = [
        'received_at' => date('Y-m-d H:i:s'),
        'raw_request' => $payload,
        'decoded'     => $decodedResult,
    ];
    @file_put_contents($logDir . '/raw_packets.log', json_encode($logEntry, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND);

    http_response_code(200);
    echo json_encode([
        'status'  => 'success',
        'message' => 'RAW paketas gautas ir išanalizuotas',
        'decoded' => $decodedResult,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// 2. Skelbimų apdorojimas (/api/adverts)
if ($requestUri === '/api/adverts') {
    $logEntry = [
        'received_at' => date('Y-m-d H:i:s'),
        'advert'      => $payload,
    ];
    @file_put_contents($logDir . '/adverts.log', json_encode($logEntry, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND);

    http_response_code(200);
    echo json_encode([
        'status'  => 'success',
        'message' => 'ADVERT skelbimas išsaugotas',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// 3. Telemetrijos apdorojimas (/api/telemetry)
if ($requestUri === '/api/telemetry') {
    $logEntry = [
        'received_at' => date('Y-m-d H:i:s'),
        'telemetry'   => $payload,
    ];
    @file_put_contents($logDir . '/telemetry.log', json_encode($logEntry, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND);

    http_response_code(200);
    echo json_encode([
        'status'  => 'success',
        'message' => 'Telemetrijos duomenys išsaugoti',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// Jei kreipiamasi į nežinomą endpoint'ą:
http_response_code(404);
echo json_encode(['error' => 'Endpointas nerastas: ' . htmlspecialchars($requestUri)], JSON_UNESCAPED_UNICODE);
