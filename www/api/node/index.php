<?php
http_response_code(200);

if (!empty($_GET['subpath'])) {
    $sub = trim((string)$_GET['subpath']);
    if (strtolower($sub) === 'list') {
        $_GET['endpoint'] = 'node_list';
    } elseif (!in_array(strtolower($sub), ['index', 'index.php', ''])) {
        $_GET['endpoint'] = 'node';
        $_GET['public_key'] = $sub;
    }
}

if (!empty($_GET['public_key']) && empty($_GET['endpoint'])) {
    $_GET['endpoint'] = 'node';
    $_GET['subpath'] = (string)$_GET['public_key'];
}

if (!empty($_GET['node_id']) && empty($_GET['endpoint'])) {
    $_GET['endpoint'] = 'node';
    $_GET['public_key'] = (string)$_GET['node_id'];
    $_GET['subpath'] = (string)$_GET['node_id'];
}

if (!empty($_GET['bot_id']) && empty($_GET['endpoint'])) {
    $_GET['endpoint'] = 'node';
    $_GET['public_key'] = (string)$_GET['bot_id'];
    $_GET['subpath'] = (string)$_GET['bot_id'];
}

// Ištraukiame subpath arba public_key tiesiai iš REQUEST_URI
if (empty($_GET['subpath']) && empty($_GET['endpoint'])) {
    $uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if (preg_match('#/(api/)?node/([a-zA-Z0-9_!\-#\.\+]+)/?$#i', $uriPath, $m)) {
        $sub = trim($m[2]);
        if (strtolower($sub) === 'list') {
            $_GET['endpoint'] = 'node_list';
        } elseif (!in_array(strtolower($sub), ['index', 'index.php', ''])) {
            $_GET['endpoint'] = 'node';
            $_GET['public_key'] = $sub;
            $_GET['subpath'] = $sub;
        }
    }
}

// Jei kreipiamasi tiesiai į /api/node be jokio parametro ar subpath – iškart grąžiname 404
if (empty($_GET['endpoint']) && empty($_GET['subpath']) && empty($_GET['public_key'])) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error'   => 'Nenurodytas mazgo identifikatorius (public_key arba node_id). Kreipkitės /api/node/{public_key} arba /api/node/list.'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/../../src/app.php';
