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

require_once __DIR__ . '/../../src/app.php';
