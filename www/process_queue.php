<?php

/**
 * CLI / Cron Darbininkas (Worker)
 *
 * Apdoroja duomenų bazėje esančius neapdorotus RAW paketus pagal nustatytą prioritetą:
 * 1. Įrašai su tuščia apdorojimo data (processed_at IS NULL) - apdorojami pirmi.
 * 2. Įrašai su vėliausia apdorojimo data, kurių processed = false (processed_at DESC) - pakartotinis bandymas.
 *
 * Paleidimas:
 *   php process_queue.php              (apdoroja iki 50 paketų partiją)
 *   php process_queue.php --limit=100  (apdoroja nurodytą skaičių)
 *   php process_queue.php --daemon     (veikia nuolat fone)
 */

if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Šis skriptas skirtas paleisti per komandinę eilutę (CLI).']);
    exit;
}

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    require_once __DIR__ . '/src/MeshCoreDecoder.php';
    require_once __DIR__ . '/src/RawPacketRepository.php';
}

use MeshCore\MeshCoreDecoder;
use MeshCore\RawPacketRepository;

$decoder = new MeshCoreDecoder();
$repository = new RawPacketRepository();
$repository->createTableIfNotExists();

// Nuskaitome CLI argumentus
$options = getopt('', ['limit::', 'daemon', 'sleep::']);
$limit = isset($options['limit']) ? (int)$options['limit'] : 50;
$daemon = isset($options['daemon']);
$sleepSec = isset($options['sleep']) ? (int)$options['sleep'] : 2;

echo "========================================================\n";
echo "  MeshCore RAW Paketų Eilės Apdorotojas (Worker)\n";
echo "========================================================\n";
echo "DB: " . RawPacketRepository::DEFAULT_CONFIG['dbname'] . "\n";
echo "Režimas: " . ($daemon ? "Demonas (nuolatinis veikimas)" : "Vienkartinė partija (maks. {$limit} įrašų)") . "\n";
echo "Prioritetas: tuščia processed_at pirma -> vėliausia processed_at su processed=false\n\n";

$processedCount = 0;
$errorCount = 0;

do {
    $item = $repository->fetchNextForProcessing();

    if ($item === null) {
        if ($daemon) {
            sleep($sleepSec);
            continue;
        } else {
            echo "Eilė tuščia. Nėra neapdorotų paketų.\n";
            break;
        }
    }

    $id = (int)$item['id'];
    $rawHex = $item['raw_hex'];
    echo sprintf("[%s] Paimtas įrašas #%d (raw_hex: %s...)\n", date('H:i:s'), $id, substr($rawHex, 0, 16));

    try {
        $result = $repository->processRecord($id, $decoder);
        if (!empty($result['success'])) {
            $processedCount++;
            $type = $result['payload_type_name'] ?? ($result['type'] ?? 'UNKNOWN');
            echo sprintf("  -> Sėkmingai iškoduota! Tipas: %s | processed = true\n", $type);
        } else {
            $errorCount++;
            $err = $result['error'] ?? 'Nežinoma klaida';
            echo sprintf("  -> Klaida iškoduojant: %s | processed lieka false\n", $err);
        }
    } catch (\Throwable $e) {
        $errorCount++;
        echo sprintf("  -> Kritinė klaida: %s\n", $e->getMessage());
    }

    if (!$daemon && ($processedCount + $errorCount) >= $limit) {
        echo "\nPasiektas nustatytas limitas ({$limit}). Baigiama.\n";
        break;
    }

} while ($daemon || ($processedCount + $errorCount) < $limit);

$stats = $repository->getStats();
echo "\n========================================================\n";
echo "Statistika:\n";
echo "  Apdorota šios sesijos metu: {$processedCount}\n";
echo "  Klaidų: {$errorCount}\n";
echo "  Iš viso DB: " . ($stats['total_count'] ?? 0) . "\n";
echo "  Laukia (niekada nebandyta): " . ($stats['pending_never_processed'] ?? 0) . "\n";
echo "  Kartojama / procese: " . ($stats['in_progress_or_retry'] ?? 0) . "\n";
echo "  Pilnai atlikta: " . ($stats['completed_count'] ?? 0) . "\n";
echo "========================================================\n";
