<?php

/**
 * Duomenų Bazės Migracijos Skriptas (MySQL / PostgreSQL)
 * Sukuria ir atnaujina 'raw_packets', 'nodes' bei 'telemetry' lenteles ir indeksus.
 *
 * Paleidimas:
 *   Komandinėje eilutėje: php www/migrate.php
 *   Naršyklėje:           https://telemetry.ite.lt/migrate.php
 */

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

    // 1. Sukuriame lenteles 'raw_packets', 'nodes' ir 'telemetry' bei indeksus
    $repo->createTableIfNotExists();
    output("Lentelės 'raw_packets', 'nodes' ir 'telemetry' bei jų indeksai sėkmingai patikrinti / sukurti!", "success");

    // 2. Patikriname visų trijų lentelių stulpelius
    $tablesToCheck = ['raw_packets', 'nodes', 'telemetry'];

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

    // 5. Statistikos išvedimas
    $rawStats = $repo->getStats();
    $nodeStats = $repo->getNodeStats();
    $telemetryStats = $repo->getTelemetryStats();

    output("\n📊 Esama sistemos būsena:", "info");
    output("  [raw_packets] Iš viso paketų: " . ($rawStats['total_count'] ?? 0), "info");
    output("  [raw_packets] Laukia eilėje: " . ($rawStats['pending_never_processed'] ?? 0), "info");
    output("  [raw_packets] Apdorota: " . ($rawStats['completed_count'] ?? 0), "info");
    output("  [nodes] Iš viso žinomų mazgų: " . ($nodeStats['total_nodes'] ?? 0), "info");
    output("  [nodes] Kartotuvų (repeaters): " . ($nodeStats['repeaters_count'] ?? 0), "info");
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
