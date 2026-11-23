<?php

require __DIR__ . '/../www/src/autoload.php';

use MeshCore\MeshCoreDecoder;

$secret = str_repeat("\x42", 32);
$decoder = new MeshCoreDecoder();
$decode = static function (string $plain) use ($decoder, $secret): array {
    $plain = str_pad($plain, (int)ceil(strlen($plain) / 16) * 16, "\0");
    $ciphertext = openssl_encrypt($plain, 'aes-128-ecb', substr($secret, 0, 16), OPENSSL_RAW_DATA | OPENSSL_NO_PADDING);
    if ($ciphertext === false) {
        throw new RuntimeException('Could not encrypt the PATH fixture.');
    }
    $mac = substr(hash_hmac('sha256', $ciphertext, $secret, true), 0, 2);
    $packet = "\x22\x41\xab\xbc\x0a\x01" . $mac . $ciphertext;
    $decoded = $decoder->decodeRawPacket(bin2hex($packet), bin2hex($secret));
    return $decoded['encryption']['parsed_data'] ?? [];
};

$route = "\x82\x37\x57\x77\x97\x17\x27";
$reciprocal = $decode($route . "\xff\x11\x22\x33\x44");
if (($reciprocal['returned_path']['hops'] ?? null) !== ['375777', '971727'] ||
    ($reciprocal['path_extra_type'] ?? null) !== 0xff ||
    isset($reciprocal['timestamp']) || isset($reciprocal['response_type']) || isset($reciprocal['sensors'])) {
    throw new RuntimeException('Reciprocal PATH should contain a route, not telemetry or login data.');
}

$login = $decode($route . "\x01\x2a\x00\x00\x00\x00\x00\x00\x01");
if (($login['response_type'] ?? null) !== 'LOGIN_SUCCESS') {
    throw new RuntimeException('PATH with an embedded login response no longer decodes.');
}

$stats = pack('v4', 3840, 0, (-100 & 0xffff), (-90 & 0xffff)) . str_repeat("\0", 44);
$status = $decode($route . "\x01" . pack('V', 42) . $stats);
if (($status['response_type'] ?? null) !== 'REPEATER_STATS' ||
    ($status['repeater_stats']['battery_mv'] ?? null) !== 3840) {
    throw new RuntimeException('A STATUS battery reading with a zero low byte was misclassified.');
}

echo "MeshCore PATH decoder checks passed.\n";
