<?php

require_once __DIR__ . '/src/MeshCoreDecoder.php';

use MeshCore\MeshCoreDecoder;

echo "=== MESHCORE DECODER TESTAS ===\n\n";

$decoder = new MeshCoreDecoder();

// 1. Tikras vartotojo pateiktas ADVERT paketas (LT-LM Tilžė B9DD)
$sampleAdvertHex = "1200B9DDD788B7A59EAB40EAA49A2534FA9E7FC0EF7D920EF4811139998CDDD631DB1E4FBD6A391AB38BB4377CC027E83B53BF9C467A3978D9700C65D74835FF8DD48670498C063FCB81E7827BCB96B1E78E57A08EDFC98E88AC099115F0132475C555FB610D9277FE510312A942014C542D4C4D2054696CC5BEC4972042394444";

echo "1. Dekoduojamas realus ADVERT paketas iš eterio:\n";
$decodedAdvert = $decoder->decodeRawPacket($sampleAdvertHex);
echo json_encode($decodedAdvert, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

// 2. Pilno /api/raw JSON paketo dekodavimo testas (su šifravimu ir raktu)
echo "2. Dekoduojama pilna /api/raw užklausa su šifruotu kroviniu:\n";

// Sugeneruojame testinį šifruotą paketą su žinomu raktu
$testSecretHex = "3f8a91c0e2bd1987542da691ef02479e120894567ac83d1059fba023e174b890";
$secretBytes = hex2bin($testSecretHex);
$encKey = substr($secretBytes, 0, 16);
$macKey = substr($secretBytes, 16, 16);

// Plaintext: [timestamp 4B][REQ_TYPE_GET_STATUS 0x01][reserved 4B][nonce 4B]
$ts = 1790700050;
$plain = pack('V', $ts) . chr(0x01) . pack('V', 0) . pack('V', 123456);

// PKCS7 padding
$pad = 16 - (strlen($plain) % 16);
$plainPadded = $plain . str_repeat(chr($pad), $pad);

// Encrypt AES-128-CBC
$ciphertext = openssl_encrypt($plainPadded, 'aes-128-cbc', $encKey, OPENSSL_RAW_DATA | OPENSSL_NO_PADDING, str_repeat("\0", 16));
$mac = substr(hash_hmac('sha256', $ciphertext, $macKey, true), 0, 4);

// MeshCore paketas: Header (REQ=0, ROUTE=FLOOD=1 -> 0x01), PathLen (0), DestHash (0xB9), SrcHash (0xAA), Ciphertext, MAC
$header = chr((0x00 << 2) | 0x01); // 0x01
$pathLen = chr(0x00);
$destHash = chr(0xB9);
$srcHash = chr(0xAA);

$rawPacket = $header . $pathLen . $destHash . $srcHash . $ciphertext . $mac;
$rawHex = strtoupper(bin2hex($rawPacket));

$apiRawRequest = [
    'direction'         => 'TX',
    'timestamp'         => 1790700050,
    'length'            => strlen($rawPacket),
    'raw_hex'           => $rawHex,
    'target_name'       => 'b9dd',
    'target_public_key' => 'b9ddd788b7a59eab40eaa49a2534fa9e7fc0ef7d920ef4811139998cddd631db',
    'my_public_key'     => '0bbd84b028bdf755edd8f30c6f67839c3f81846c9f0079f377ea32a1e93410f3',
    'shared_secret_hex' => $testSecretHex,
    'is_encrypted'      => true
];

$decodedApi = $decoder->decodeApiPayload($apiRawRequest);
echo json_encode($decodedApi, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

echo "=== TESTAS BAIGTAS SĖKMINGAI ===\n";
