<?php

/**
 * MeshCore RAW Paketų Eilės Apdorotojas (Worker / CLI / HTTP Cron)
 *
 * Apdoroja duomenų bazėje esančius neapdorotus RAW paketus pagal nustatytą prioritetą:
 * 1. Įrašai su tuščia apdorojimo data (processed_at IS NULL) - apdorojami pirmi.
 * 2. Įrašai su vėliausia apdorojimo data, kurių processed = false (processed_at DESC) - pakartotinis bandymas.
 *
 * Paleidimas:
 *   Per CLI / terminalą:
 *     php process_queue.php              (apdoroja iki 50 paketų partiją)
 *     php process_queue.php --limit=100  (apdoroja nurodytą skaičių)
 *     php process_queue.php --daemon     (veikia nuolat fone)
 *
 *   Per HTTP / cPanel Cron:
 *     curl "https://telemetry.ite.lt/process_queue.php?limit=50"
 */

date_default_timezone_set('Europe/Vilnius');
$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    header('Access-Control-Allow-Origin: *');
    header('Content-Type: application/json; charset=utf-8');
}

if (file_exists(__DIR__ . '/src/autoload.php')) {
    require_once __DIR__ . '/src/autoload.php';
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

// Parametrų nuskaitymas (CLI arba HTTP)
if ($isCli) {
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
} else {
    $limit = isset($_GET['limit']) ? max(1, min(200, (int)$_GET['limit'])) : 50;
    $daemon = false;
    $sleepSec = 0;
}

$processedCount = 0;
$errorCount = 0;
$logItems = [];

do {
    $item = $repository->fetchNextForProcessing();

    if ($item === null) {
        if ($daemon) {
            sleep($sleepSec);
            continue;
        } else {
            if ($isCli) {
                echo "Eilė tuščia. Nėra neapdorotų paketų.\n";
            }
            break;
        }
    }

    $id = (int)$item['id'];
    $rawHex = $item['raw_hex'];
    if ($isCli) {
        echo sprintf("[%s] Paimtas įrašas #%d (raw_hex: %s...)\n", date('H:i:s'), $id, substr($rawHex, 0, 16));
    }

    $itemSummary = [
        'id'      => $id,
        'raw_hex' => substr($rawHex, 0, 32) . '...',
    ];

    try {
        $result = $repository->processRecord($id, $decoder);
        if (!empty($result['success'])) {
            $processedCount++;
            $type = $result['payload_type_name'] ?? ($result['type'] ?? 'UNKNOWN');
            $itemSummary['success'] = true;
            $itemSummary['type'] = $type;
            if ($isCli) {
                echo sprintf("  -> Sėkmingai iškoduota! Tipas: %s | processed = true\n", $type);
            }
        } else {
            $errorCount++;
            $err = $result['error'] ?? 'Nežinoma klaida';
            $itemSummary['success'] = false;
            $itemSummary['error'] = $err;
            if ($isCli) {
                echo sprintf("  -> Klaida iškoduojant: %s | processed lieka false\n", $err);
            }
        }
    } catch (\Throwable $e) {
        $errorCount++;
        $itemSummary['success'] = false;
        $itemSummary['error'] = $e->getMessage();
        if ($isCli) {
            echo sprintf("  -> Kritinė klaida: %s\n", $e->getMessage());
        }
    }

    $logItems[] = $itemSummary;

    if (!$daemon && ($processedCount + $errorCount) >= $limit) {
        if ($isCli) {
            echo "\nPasiektas nustatytas limitas ({$limit}). Baigiama.\n";
        }
        break;
    }

} while ($daemon || ($processedCount + $errorCount) < $limit);

$stats = $repository->getStats();

if ($isCli) {
    echo "\n========================================================\n";
    echo "Statistika:\n";
    echo "  Apdorota šios sesijos metu: {$processedCount}\n";
    echo "  Klaidų: {$errorCount}\n";
    echo "  Iš viso DB: " . ($stats['total_count'] ?? 0) . "\n";
    echo "  Laukia (niekada nebandyta): " . ($stats['pending_never_processed'] ?? 0) . "\n";
    echo "  Kartojama / procese: " . ($stats['in_progress_or_retry'] ?? 0) . "\n";
    echo "  Pilnai atlikta: " . ($stats['completed_count'] ?? 0) . "\n";
    echo "========================================================\n";
} else {
    echo json_encode([
        'success'         => true,
        'batch_processed' => $processedCount,
        'batch_errors'    => $errorCount,
        'items'           => $logItems,
        'stats'           => $stats,
        'timestamp'       => date('Y-m-d H:i:s'),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
