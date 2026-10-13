<?php

/**
 * Duomenų Bazės Migracijos Skriptas (MySQL / PostgreSQL)
 * Sukuria ir atnaujina 'raw_packets', 'nodes' bei 'telemetry' lenteles ir indeksus.
 *
 * Paleidimas:
 *   Komandinėje eilutėje: php www/migrate.php
 *   Naršyklėje:           https://telemetry.ite.lt/migrate.php
 */

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

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>MeshCore DB Migracija</title>';
    echo '<style>body{font-family:ui-monospace,monospace;background:#0d1117;color:#c9d1d9;padding:24px;line-height:1.5;}';
    echo 'h1{color:#58a6ff;} .success{color:#3fb950;} .error{color:#f85149;} .card{background:#161b22;padding:16px;border-radius:8px;border:1px solid #30363d;margin-bottom:16px;} table{border-collapse:collapse;width:100%;margin-top:10px;} th,td{border:1px solid #30363d;padding:8px 12px;text-align:left;} th{background:#21262d;}</style></head><body>';
    echo '<h1>🚀 MeshCore Duomenų Bazės Migracija</h1>';
}

function output(string $msg, string $type = 'info'): void {
    global $isCli;
    if ($isCli) {
        $prefix = $type === 'success' ? '✅ ' : ($type === 'error' ? '❌ ' : 'ℹ️ ');
        echo $prefix . $msg . "\n";
    } else {
        $cls = $type === 'success' ? 'success' : ($type === 'error' ? 'error' : '');
        echo "<div class='{$cls}'>{$msg}</div>";
    }
}

try {
    output("Pradedama duomenų bazės migracija...", "info");

    $decoder = new MeshCoreDecoder();
    $repo = new RawPacketRepository();
    $pdo = $repo->getPdo();
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $serverVersion = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);

    output("Prisijungta sėkmingai prie DB!", "success");
    output("DB Tipas (Driver): {$driver} (Versija: {$serverVersion})", "info");
    output("Vartotojas: " . RawPacketRepository::DEFAULT_CONFIG['user'], "info");
    output("Duomenų bazė: " . RawPacketRepository::DEFAULT_CONFIG['dbname'], "info");

    // 1. Sukuriame lenteles 'bots', 'nodes', 'telemetry' ir 'raw_packets' bei indeksus
    $repo->createTableIfNotExists();
    output("Lentelės 'bots', 'nodes', 'telemetry' ir 'raw_packets' bei jų indeksai sėkmingai patikrinti / sukurti!", "success");

    // Užtikriname, kad esamiems įrašams būtų priskirtas numatytasis 'KLP' bot_id
    try {
        $pdo->exec("UPDATE nodes SET bot_id = 'KLP' WHERE bot_id IS NULL OR bot_id = ''");
        $pdo->exec("UPDATE raw_packets SET bot_id = 'KLP' WHERE bot_id IS NULL OR bot_id = ''");
        $pdo->exec("UPDATE telemetry SET bot_id = 'KLP' WHERE bot_id IS NULL OR bot_id = ''");
        output("Esamiems įrašams priskirtas numatytasis bot_id = 'KLP'", "info");
    } catch (\Throwable $e) {}

    // Užtikriname, kad 'nodes.last_seen_at' neturėtų automatinio ON UPDATE CURRENT_TIMESTAMP
    if ($driver !== 'pgsql') {
        try {
            $pdo->exec("ALTER TABLE `nodes` MODIFY COLUMN `last_seen_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP");
            output("Užtikrinta: 'nodes.last_seen_at' atnaujinamas tik pasirodžius paketui tinkle (be auto-update)", "info");
        } catch (\Throwable $e) {}
    }

    // Užpildome 'nodes.telemetry_updated_at' esamiems mazgams iš 'telemetry' lentelės, jei data tuščia
    try {
        if ($driver === 'pgsql') {
            $pdo->exec("UPDATE nodes n SET telemetry_updated_at = sub.max_rec
                        FROM (
                            SELECT public_key, node_id, MAX(recorded_at) as max_rec
                            FROM telemetry
                            WHERE battery_v IS NOT NULL OR temperature_c IS NOT NULL
                            GROUP BY public_key, node_id
                        ) sub
                        WHERE (n.public_key IS NOT NULL AND n.public_key = sub.public_key)
                           OR (n.node_id IS NOT NULL AND n.node_id = sub.node_id)
                          AND n.telemetry_updated_at IS NULL");
        } else {
            $pdo->exec("UPDATE `nodes` n
                        JOIN (
                            SELECT public_key, node_id, MAX(recorded_at) as max_rec
                            FROM telemetry
                            WHERE battery_v IS NOT NULL OR temperature_c IS NOT NULL
                            GROUP BY public_key, node_id
                        ) sub ON (n.public_key IS NOT NULL AND n.public_key = sub.public_key) OR (n.node_id IS NOT NULL AND n.node_id = sub.node_id)
                        SET n.telemetry_updated_at = sub.max_rec
                        WHERE n.telemetry_updated_at IS NULL");
        }
        output("Sinchronizuota 'telemetry_updated_at' data esamiems mazgams iš 'telemetry' lentelės", "info");
    } catch (\Throwable $e) {}

    // 2. Patikriname visų keturių lentelių stulpelius
    $tablesToCheck = ['bots', 'nodes', 'telemetry', 'raw_packets'];

    foreach ($tablesToCheck as $tbl) {
        if ($driver === 'mysql') {
            $stmt = $pdo->query("DESCRIBE {$tbl}");
            $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!$isCli) {
                echo "<div class='card'><h3>📋 Lentelės '{$tbl}' struktūra (MySQL):</h3><table>";
                echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
                foreach ($columns as $col) {
                    echo "<tr><td>{$col['Field']}</td><td>{$col['Type']}</td><td>{$col['Null']}</td><td>{$col['Key']}</td><td>{$col['Default']}</td><td>{$col['Extra']}</td></tr>";
                }
                echo "</table></div>";
            } else {
                echo "\n📋 Lentelės '{$tbl}' stulpeliai:\n";
                foreach ($columns as $col) {
                    echo sprintf("  - %-20s %-25s Default: %s\n", $col['Field'], $col['Type'], $col['Default'] ?? 'NULL');
                }
            }

            $idxStmt = $pdo->query("SHOW INDEX FROM {$tbl}");
            $indexes = $idxStmt->fetchAll(PDO::FETCH_ASSOC);
            $idxNames = array_unique(array_column($indexes, 'Key_name'));
            output("Indeksai lentelėje '{$tbl}': " . implode(', ', $idxNames), "info");

        } else {
            $stmt = $pdo->query("SELECT column_name, data_type, is_nullable, column_default FROM information_schema.columns WHERE table_name = '{$tbl}'");
            $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($isCli) {
                echo "\n📋 Lentelės '{$tbl}' stulpeliai (PostgreSQL):\n";
                foreach ($columns as $col) {
                    echo sprintf("  - %-20s %-20s\n", $col['column_name'], $col['data_type']);
                }
            }
        }
    }

    // 3. Automatinis 'nodes' užpildymas iš esamų 'raw_packets' ADVERT skelbimų (jei tokių yra)
    $backfilledNodes = $repo->backfillNodesFromRawPackets($decoder);
    if ($backfilledNodes > 0) {
        output("Aptikta ir perkelta į 'nodes' lentelę esamų skelbimų: {$backfilledNodes}", "success");
    }

    // 4. Automatinis 'telemetry' užpildymas iš esamų 'raw_packets' paketų (STATUS, TELEMETRY, ADVERT)
    $backfilledTelemetry = $repo->backfillTelemetryFromRawPackets($decoder);
    if ($backfilledTelemetry > 0) {
        output("Aptikta ir perkelta į 'telemetry' lentelę esamų telemetrijos taškų: {$backfilledTelemetry}", "success");
    }

    // 5. Išvalome neteisingus/iškreiptus telemetrijos įrašus (pvz. >15V ar sugedusio šifro triukšmą)
    $cleaned = $repo->cleanupInvalidTelemetry();
    if ($cleaned > 0) {
        output("Išvalyti neteisingi/iškreipti telemetrijos įrašai iš 'telemetry': {$cleaned}", "success");
    }

    // 6. Kartotuvų slaptažodžių ir 'update' būsenos sinchronizavimas
    $nodeStats = $repo->getNodeStats();
    $monitoredCount = (int)($nodeStats['monitored_repeaters_count'] ?? 0);

    if ($monitoredCount === 0) {
        // Jei dar nėra nė vieno kartotuvo su update=1, inicializuojame pradinius kartotuvus
        $seedList = [
            ['id' => 'b9dd', 'name' => 'LT-LM Tilžė B9DD', 'pub' => 'b9ddd788b7a59eab40eaa49a2534fa9e7fc0ef7d920ef4811139998cddd631db', 'pass' => 'hello'],
            ['id' => '1bbd', 'name' => 'LT-LM 1BBD',       'pub' => '1bbd45c1ae2b2241ae997f0723066461bc8b0283abeee165d67ef9b35e9afb64', 'pass' => 'hello'],
            ['id' => 'f9f8', 'name' => 'LT-LM F9F8',       'pub' => 'f9f86539d970c347818e4e6c8da7e9181b0fc502b9b3ee1a2f7b4358a352a38c', 'pass' => 'hello'],
        ];

        foreach ($seedList as $s) {
            if ($driver === 'pgsql') {
                $sql = 'INSERT INTO nodes (public_key, node_id, node_name, password, "update", is_repeater, first_seen_at, last_seen_at) 
                        VALUES (:pub, :id, :name, :pass, true, true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                        ON CONFLICT (public_key) DO UPDATE SET password = EXCLUDED.password, "update" = true, is_repeater = true';
            } else {
                $sql = 'INSERT INTO `nodes` (`public_key`, `node_id`, `node_name`, `password`, `update`, `is_repeater`, `first_seen_at`, `last_seen_at`) 
                        VALUES (:pub, :id, :name, :pass, 1, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                        ON DUPLICATE KEY UPDATE `password` = VALUES(`password`), `update` = 1, `is_repeater` = 1';
            }
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':pub'  => $s['pub'],
                    ':id'   => $s['id'],
                    ':name' => $s['name'],
                    ':pass' => $s['pass'],
                ]);
            } catch (\Throwable $e) {}
        }
        output("Pradiniai kartotuvai (b9dd, 1bbd, f9f8) sėkmingai pažymėti su update=true ir slaptažodžiu 'hello'!", "success");
        $nodeStats = $repo->getNodeStats();
    }

    // 7. Statistikos išvedimas
    $rawStats = $repo->getStats();
    $telemetryStats = $repo->getTelemetryStats();

    output("\n📊 Esama sistemos būsena:", "info");
    $allBots = $repo->getAllBots();
    output("  [bots] Iš viso registruotų botų (vartų): " . count($allBots), "info");
    output("  [raw_packets] Iš viso paketų: " . ($rawStats['total_count'] ?? 0), "info");
    output("  [raw_packets] Laukia eilėje: " . ($rawStats['pending_never_processed'] ?? 0), "info");
    output("  [raw_packets] Apdorota: " . ($rawStats['completed_count'] ?? 0), "info");
    output("  [nodes] Iš viso žinomų mazgų: " . ($nodeStats['total_nodes'] ?? 0), "info");
    output("  [nodes] Kartotuvų (repeaters): " . ($nodeStats['repeaters_count'] ?? 0), "info");
    output("  [nodes] Stebimų kartotuvų (update=1): " . ($nodeStats['monitored_repeaters_count'] ?? 0), "info");
    output("  [nodes] Išjungtų mazgų (disabled): " . ($nodeStats['disabled_nodes_count'] ?? 0), "info");
    output("  [nodes] Su GPS koordinatėmis: " . ($nodeStats['nodes_with_location'] ?? 0), "info");
    output("  [telemetry] Iš viso telemetrijos taškų: " . ($telemetryStats['total_points'] ?? 0), "info");
    output("  [telemetry] Stebimi unikalūs mazgai: " . ($telemetryStats['monitored_nodes'] ?? 0), "info");
    output("  [telemetry] Paskutinis telemetrijos įrašas: " . ($telemetryStats['last_telemetry_at'] ?? 'nėra'), "info");

    output("\n🎉 Migracija baigta sėkmingai!", "success");

} catch (\Throwable $e) {
    output("KLAIDA: " . $e->getMessage(), "error");
    if (!$isCli) {
        echo "<pre style='color:#f85149;margin-top:12px;'>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    }
}

if (!$isCli) {
    echo '</body></html>';
}
