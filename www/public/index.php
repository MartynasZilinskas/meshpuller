<?php

$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path       = rtrim($requestUri, '/');
$method     = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Nukreipiame /api/raw, /api/nodes arba /raw tiesiai į pilną app.php su DB saugykla
if (preg_match('#/(api/)?(raw|nodes)$#', $path) || $path === '' || $path === '/') {
    require_once __DIR__ . '/../src/app.php';
    exit;
}

// Jei bandoma kreiptis į panaikintus adverts arba telemetry endpointus
if (preg_match('#/(api/)?(adverts|telemetry)$#', $path)) {
    http_response_code(410); // 410 Gone
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status'  => 'deprecated',
        'error'   => 'Šis endpointas panaikintas. Visi LoRa duomenys dabar siunčiami į /api/raw ir apdorojami serveryje.',
        'target'  => '/api/raw',
        'method'  => 'POST',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// Visi kiti kreipiniai perduodami į app.php
require_once __DIR__ . '/../src/app.php';
