<?php

/**
 * Duomenų Bazės Migracijos Skriptas (MySQL / PostgreSQL)
 *
 * Paleidimas:
 *   Komandinėje eilutėje: php www/migrate.php
 *   Naršyklėje:           https://telemetry.ite.lt/migrate.php (arba /api/migrate.php)
 */

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    require_once __DIR__ . '/src/MeshCoreDecoder.php';
    require_once __DIR__ . '/src/RawPacketRepository.php';
}

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
    output("Pradedama migracija...", "info");

    $repo = new RawPacketRepository();
    $pdo = $repo->getPdo();
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $serverVersion = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);

    output("Prisijungta sėkmingai prie DB!", "success");
    output("DB Tipas (Driver): {$driver} (Versija: {$serverVersion})", "info");
    output("Vartotojas: " . RawPacketRepository::DEFAULT_CONFIG['user'], "info");
    output("Duomenų bazė: " . RawPacketRepository::DEFAULT_CONFIG['dbname'], "info");

    // Sukuriame lentelę
    $repo->createTableIfNotExists();
    output("Lentelė 'raw_packets' ir indeksai sėkmingai patikrinti / sukurti!", "success");

    // Patikriname lentelės stulpelius
    if ($driver === 'mysql') {
        $stmt = $pdo->query("DESCRIBE raw_packets");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$isCli) {
            echo "<div class='card'><h3>📋 Lentelės 'raw_packets' struktūra (MySQL):</h3><table>";
            echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
            foreach ($columns as $col) {
                echo "<tr><td>{$col['Field']}</td><td>{$col['Type']}</td><td>{$col['Null']}</td><td>{$col['Key']}</td><td>{$col['Default']}</td><td>{$col['Extra']}</td></tr>";
            }
            echo "</table></div>";
        } else {
            echo "\n📋 Lentelės 'raw_packets' stulpeliai:\n";
            foreach ($columns as $col) {
                echo sprintf("  - %-20s %-25s Default: %s\n", $col['Field'], $col['Type'], $col['Default'] ?? 'NULL');
            }
        }

        $idxStmt = $pdo->query("SHOW INDEX FROM raw_packets");
        $indexes = $idxStmt->fetchAll(PDO::FETCH_ASSOC);
        $idxNames = array_unique(array_column($indexes, 'Key_name'));
        output("\nIndeksai: " . implode(', ', $idxNames), "info");

    } else {
        $stmt = $pdo->query("SELECT column_name, data_type, is_nullable, column_default FROM information_schema.columns WHERE table_name = 'raw_packets'");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($isCli) {
            echo "\n📋 Lentelės 'raw_packets' stulpeliai (PostgreSQL):\n";
            foreach ($columns as $col) {
                echo sprintf("  - %-20s %-20s\n", $col['column_name'], $col['data_type']);
            }
        }
    }

    $stats = $repo->getStats();
    output("\nEsama lentelės būsena:", "info");
    output("  Viso įrašų: " . ($stats['total_count'] ?? 0), "info");
    output("  Laukia apdorojimo: " . ($stats['pending_never_processed'] ?? 0), "info");
    output("  Apdorota: " . ($stats['completed_count'] ?? 0), "info");

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
