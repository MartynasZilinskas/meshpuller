<?php
http_response_code(200);
$_GET['endpoint'] = 'node_list';
require_once __DIR__ . '/../../../src/app.php';
