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

// Sugeneruojame testinį šifruotą paketą su žinomu raktu pagal MeshCrypto.h
$testSecretHex = "3f8a91c0e2bd1987542da691ef02479e120894567ac83d1059fba023e174b890";
$secretBytes = hex2bin($testSecretHex);
$encKey = substr($secretBytes, 0, 16);

// Plaintext: [timestamp 4B][REQ_TYPE_GET_STATUS 0x01][reserved 4B][nonce 4B]
$ts = 1790700145;
$plain = pack('V', $ts) . chr(0x01) . pack('V', 0) . pack('V', 0x78563412);
$plainPadded = str_pad($plain, 16, "\0"); // zero padding

// Encrypt AES-128-ECB
$ciphertext = openssl_encrypt($plainPadded, 'aes-128-ecb', $encKey, OPENSSL_RAW_DATA | OPENSSL_NO_PADDING);
// MeshCrypto 2-byte MAC per HMAC-SHA256(ciphertext, full 32B secret)
$mac = substr(hash_hmac('sha256', $ciphertext, $secretBytes, true), 0, 2);

// MeshCore paketas: Header (REQ=0, ROUTE=FLOOD=1 -> 0x01), PathLen (0), DestHash (0xB9), SrcHash (0xAA), MAC (2B), Ciphertext (16B)
$header = chr((0x00 << 2) | 0x01); // 0x01
$pathLen = chr(0x00);
$destHash = chr(0xB9);
$srcHash = chr(0xAA);

$rawPacket = $header . $pathLen . $destHash . $srcHash . $mac . $ciphertext;
$rawHex = strtoupper(bin2hex($rawPacket));

$apiRawRequest = [
    'direction'         => 'TX',
    'timestamp'         => 1790700145,
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

// 3. Duomenų bazės eiliškumo ir prioriteto rūšiavimo testas
echo "3. Duomenų bazės ir eiliškumo prioriteto testas:\n";
require_once __DIR__ . '/src/RawPacketRepository.php';
use MeshCore\RawPacketRepository;

try {
    // Testui lokaliai naudojame SQLite PDO atmintyje, jei nėra lokalaus PostgreSQL
    $testPdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $testRepo = new RawPacketRepository(['table' => 'raw_packets'], $testPdo);
    $testRepo->createTableIfNotExists();

    // Įrašome 3 įrašus skirtingomis būsenomis:
    // A) Senas įrašas, bet su processed_at prieš 10 min (nebaigtas bandymas)
    $id1 = $testRepo->saveRaw(['raw_hex' => '0100B9AA01', 'direction' => 'RX']);
    $testPdo->exec("UPDATE raw_packets SET processed_at = datetime('now', '-10 minutes') WHERE id = {$id1}");

    // B) Įrašas su vėliausia processed_at prieš 2 min (naujesnis bandymas)
    $id2 = $testRepo->saveRaw(['raw_hex' => '0100B9AA02', 'direction' => 'RX']);
    $testPdo->exec("UPDATE raw_packets SET processed_at = datetime('now', '-2 minutes') WHERE id = {$id2}");

    // C) Naujas ką tik atėjęs įrašas su TUŠČIA apdorojimo data (processed_at IS NULL)
    $id3 = $testRepo->saveRaw(['raw_hex' => '0100B9AA03', 'direction' => 'RX']);

    echo "Pateikti įrašai testavimui:\n";
    echo "  - Įrašas #{$id1}: processed=false, processed_at=-10min\n";
    echo "  - Įrašas #{$id2}: processed=false, processed_at=-2min\n";
    echo "  - Įrašas #{$id3}: processed=false, processed_at=NULL (naujas!)\n\n";

    // Pasiimame 1-ąjį pagal prioritetą:
    $first = $testRepo->fetchNextForProcessing();
    echo "Pirmas paimtas (turi būti #{$id3} su tuščia data): ID #{$first['id']} (processed_at automatiškai užfiksuota)\n";

    // Pasiimame 2-ąjį pagal prioritetą:
    $second = $testRepo->fetchNextForProcessing();
    echo "Antras paimtas (turi būti #{$id2} su vėliausia data): ID #{$second['id']}\n";

    // Pasiimame 3-ąjį:
    $third = $testRepo->fetchNextForProcessing();
    echo "Trečias paimtas (turi būti #{$id1}): ID #{$third['id']}\n";

    // Pažymime sėkmingą apdorojimą
    $testRepo->completeProcessing((int)$first['id'], ['status' => 'OK']);
    echo "Įrašas #{$first['id']} apdorotas -> processed = true!\n";

    // 4. Testuojame 'nodes' ir 'telemetry' bei getRepeatersWithLatestTelemetry
    echo "\n4. Testuojame kartotuvų ir telemetrijos ištraukimą:\n";
    $testRepo->upsertNodeFromAdvert([
        'format'     => 'A',
        'public_key' => 'b9ddd788b7a59eab40eaa49a2534fa9e7fc0ef7d920ef4811139998cddd631db',
        'node_name'  => 'LT-LM Tilžė B9DD',
        'flags'      => [
            'raw'         => '0x92',
            'type'        => 2,
            'type_str'    => 'repeater',
            'repeater'    => true,
            'hasLocation' => true,
        ],
        'location'   => [
            'latitude'  => 55.705207,
            'longitude' => 21.145874,
        ],
        'battery'    => [
            'voltage_mv' => 4120,
            'voltage_v'  => 4.12,
        ]
    ], ['rssi' => -85.5, 'snr' => 9.75]);

    // Įrašome telemetrijos įrašą su temperatūra
    $testRepo->insertTelemetryRecord([
        'node_id'       => 'b9dd',
        'public_key'    => 'b9ddd788b7a59eab40eaa49a2534fa9e7fc0ef7d920ef4811139998cddd631db',
        'raw_packet_id' => 999,
        'type'          => 'TELEMETRY',
        'battery_v'     => 4.15,
        'battery_mv'    => 4150,
        'temperature_c' => 22.8,
        'rssi'          => -84.0,
        'snr'           => 10.2,
        'recorded_at'   => date('Y-m-d H:i:s'),
    ]);

    $repeaters = $testRepo->getRepeatersWithLatestTelemetry();
    echo "Gauti kartotuvai iš getRepeatersWithLatestTelemetry():\n";
    echo json_encode($repeaters, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

} catch (\Throwable $e) {
    echo "DB testavimo klaida: " . $e->getMessage() . "\n";
}

echo "\n=== TESTAS BAIGTAS SĖKMINGAI ===\n";
