<?php

namespace MeshCore;

use PDO;
use PDOException;

/**
 * Class RawPacketRepository
 *
 * Atsakinga už neapdorotų (RAW) LoRa paketų saugojimą ir eiliškumo valdymą PostgreSQL duomenų bazėje.
 * 
 * Reikalavimai:
 * - Saugo visus atėjusius raw įrašus.
 * - Turi `processed` (boolean, pagal nutylėjimą false).
 * - Turi įrašo įrašymo datą (`created_at`).
 * - Turi apdorojimo pradžios datą (`processed_at`), kuri įrašoma kai įrašas paimamas procesinimui.
 * - Pabaigus procesinimą uždeda `processed = true` (ir `completed_at`).
 * - Rūšiavimas / eiliškumas:
 *   Prioritetas įrašams su tuščia data (`processed_at IS NULL`),
 *   o po to vėliausia apdorojimo data su `processed = false` (`processed_at DESC, id ASC`).
 */
class RawPacketRepository
{
    private PDO $pdo;
    private string $table;
    private array $config;

    /**
     * Numatytieji duomenų bazės nustatymai pagal pateiktus reikalavimus:
     */
    public const DEFAULT_CONFIG = [
        'driver'   => 'pgsql',
        'host'     => 'localhost',
        'port'     => 5432,
        'dbname'   => 'u236217820_telemerty',
        'user'     => 'u236217820_telemerty',
        'password' => 'telemerty-A1',
        'table'    => 'raw_packets',
    ];

    public function __construct(?array $config = null, ?PDO $pdo = null)
    {
        $this->config = array_merge(self::DEFAULT_CONFIG, $config ?? []);
        $this->table  = preg_replace('/[^a-zA-Z0-9_]/', '', $this->config['table'] ?? 'raw_packets');

        if ($pdo !== null) {
            $this->pdo = $pdo;
        } else {
            $this->connect();
        }
    }

    /**
     * Užmezga ryšį su PostgreSQL duomenų baze
     */
    private function connect(): void
    {
        $availableDrivers = PDO::getAvailableDrivers();
        $configuredDriver = $this->config['driver'] ?? null;

        // Nustatome numatytąjį draiverį: jei yra mysql, bet nėra pgsql, arba jei nurodyta mysql
        if ($configuredDriver === 'mysql' || (!$configuredDriver && in_array('mysql', $availableDrivers) && !in_array('pgsql', $availableDrivers))) {
            $driver = 'mysql';
        } else {
            $driver = $configuredDriver ?: 'pgsql';
        }

        $host   = $this->config['host'] ?? 'localhost';
        $port   = $this->config['port'] ?? ($driver === 'mysql' ? 3306 : 5432);
        $dbname = $this->config['dbname'] ?? 'u236217820_telemerty';
        $user   = $this->config['user'] ?? 'u236217820_telemerty';
        $pass   = $this->config['password'] ?? 'telemerty-A1';

        $dsn = "{$driver}:host={$host};port={$port};dbname={$dbname}";
        if ($driver === 'mysql') {
            $dsn .= ";charset=utf8mb4";
        }
        
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $this->pdo = new PDO($dsn, $user, $pass, $options);
            $this->configureTimezone();
        } catch (PDOException $e) {
            // Jei bandyta pgsql ir nepavyko, bet serveryje yra mysql: automatinis bandymas jungtis per mysql
            if ($driver === 'pgsql' && in_array('mysql', $availableDrivers)) {
                try {
                    $mysqlPort = ($port === 5432) ? 3306 : $port;
                    $mysqlDsn = "mysql:host={$host};port={$mysqlPort};dbname={$dbname};charset=utf8mb4";
                    $this->pdo = new PDO($mysqlDsn, $user, $pass, $options);
                    $this->configureTimezone();
                    return;
                } catch (\Throwable $ignored) {
                    // Ignoruojame ir metame pradinę klaidą
                }
            }
            throw new PDOException("Nepavyko prisijungti prie duomenų bazės [{$dbname} per {$driver}:{$port}]: " . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    /**
     * Nustato Lietuvos laiko juostą PHP ir MySQL sesijai (Europe/Vilnius, pvz. +03:00 / +02:00)
     */
    private function configureTimezone(): void
    {
        try {
            date_default_timezone_set('Europe/Vilnius');
            if ($this->pdo) {
                $offsetSec = (new \DateTimeZone('Europe/Vilnius'))->getOffset(new \DateTime());
                $sign = $offsetSec >= 0 ? '+' : '-';
                $hours = floor(abs($offsetSec) / 3600);
                $mins = floor((abs($offsetSec) % 3600) / 60);
                $tzOffset = sprintf('%s%02d:%02d', $sign, $hours, $mins);
                $this->pdo->exec("SET time_zone = '{$tzOffset}'");
            }
        } catch (\Throwable $ignored) {
            // Ignoruojame jei DB nepalaiko SET time_zone
        }
    }

    /**
     * Grąžina aktyvų PDO objektą
     */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Sukuria lentelę ir būtinus indeksus, jei jie dar neegzistuoja
     */
    public function createTableIfNotExists(): void
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'pgsql') {
            $sql = <<<SQL
            CREATE TABLE IF NOT EXISTS {$this->table} (
                id BIGSERIAL PRIMARY KEY,
                direction VARCHAR(16) NULL,
                raw_hex TEXT NOT NULL,
                length INTEGER NULL,
                rssi NUMERIC(6, 2) NULL,
                snr NUMERIC(6, 2) NULL,
                target_name VARCHAR(128) NULL,
                target_public_key VARCHAR(128) NULL,
                sender_name VARCHAR(128) NULL,
                sender_public_key VARCHAR(128) NULL,
                my_public_key VARCHAR(128) NULL,
                shared_secret_hex VARCHAR(128) NULL,
                is_encrypted BOOLEAN NOT NULL DEFAULT false,
                bot_id VARCHAR(16) NOT NULL DEFAULT 'KLP',
                payload_json JSONB NULL,
                decoded_json JSONB NULL,
                error_message TEXT NULL,
                processed BOOLEAN NOT NULL DEFAULT false,
                created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                processed_at TIMESTAMP WITHOUT TIME ZONE NULL,
                completed_at TIMESTAMP WITHOUT TIME ZONE NULL
            );

            -- Optimizuotas indeksas užklausai su prioritetu: processed=false, tuščia processed_at pirma, vėliausia processed_at po to
            CREATE INDEX IF NOT EXISTS idx_{$this->table}_queue_priority 
            ON {$this->table} (processed, (CASE WHEN processed_at IS NULL THEN 0 ELSE 1 END), processed_at DESC, id ASC)
            WHERE processed = false;

            CREATE INDEX IF NOT EXISTS idx_{$this->table}_created_at 
            ON {$this->table} (created_at DESC);

            CREATE INDEX IF NOT EXISTS idx_{$this->table}_bot_id 
            ON {$this->table} (bot_id);
SQL;
        } else {
            // MySQL / MariaDB / SQLite variantas
            $sql = <<<SQL
            CREATE TABLE IF NOT EXISTS {$this->table} (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                direction VARCHAR(16) NULL,
                bot_id VARCHAR(16) NOT NULL DEFAULT 'KLP',
                raw_hex LONGTEXT NOT NULL,
                length INT UNSIGNED NULL,
                rssi DECIMAL(6, 2) NULL,
                snr DECIMAL(6, 2) NULL,
                target_name VARCHAR(128) NULL,
                target_public_key VARCHAR(128) NULL,
                sender_name VARCHAR(128) NULL,
                sender_public_key VARCHAR(128) NULL,
                my_public_key VARCHAR(128) NULL,
                shared_secret_hex VARCHAR(128) NULL,
                is_encrypted TINYINT(1) NOT NULL DEFAULT 0,
                payload_json JSON NULL,
                decoded_json JSON NULL,
                error_message TEXT NULL,
                processed TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                processed_at TIMESTAMP NULL DEFAULT NULL,
                completed_at TIMESTAMP NULL DEFAULT NULL,
                INDEX idx_{$this->table}_queue (processed, processed_at, id),
                INDEX idx_{$this->table}_created (created_at),
                INDEX idx_{$this->table}_bot_id (bot_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
        }

        $this->pdo->exec($sql);

        // ---------------------------------------------------------------------
        // 2. Sukuriame 'nodes' lentelę mazgų tapatybėms ir ADVERT duomenims
        // ---------------------------------------------------------------------
        if ($driver === 'pgsql') {
            $nodesSql = <<<SQL
            CREATE TABLE IF NOT EXISTS nodes (
                id BIGSERIAL PRIMARY KEY,
                public_key VARCHAR(64) NOT NULL UNIQUE,
                node_name VARCHAR(128) NULL,
                node_id VARCHAR(16) NULL,
                password VARCHAR(128) NULL,
                "update" BOOLEAN NOT NULL DEFAULT false,
                is_disabled BOOLEAN NOT NULL DEFAULT false,
                bot_id VARCHAR(16) NOT NULL DEFAULT 'KLP',
                advert_format VARCHAR(8) NULL,
                advert_timestamp BIGINT NULL,
                signature VARCHAR(128) NULL,
                flags_raw VARCHAR(10) NULL,
                node_type SMALLINT NULL,
                node_type_str VARCHAR(32) NULL,
                is_repeater BOOLEAN NOT NULL DEFAULT false,
                is_chat BOOLEAN NOT NULL DEFAULT false,
                is_room BOOLEAN NOT NULL DEFAULT false,
                has_location BOOLEAN NOT NULL DEFAULT false,
                latitude NUMERIC(10, 7) NULL,
                longitude NUMERIC(10, 7) NULL,
                battery_mv INT NULL,
                battery_v NUMERIC(4, 2) NULL,
                temperature_c NUMERIC(4, 1) NULL,
                last_rssi NUMERIC(6, 2) NULL,
                last_snr NUMERIC(6, 2) NULL,
                flags_json JSONB NULL,
                raw_advert_json JSONB NULL,
                first_seen_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_seen_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                requested_date TIMESTAMP WITHOUT TIME ZONE NULL,
                telemetry_updated_at TIMESTAMP WITHOUT TIME ZONE NULL,
                telemetry_fail_count INTEGER NOT NULL DEFAULT 0,
                last_failed_request_at TIMESTAMP WITHOUT TIME ZONE NULL
            );
            CREATE INDEX IF NOT EXISTS idx_nodes_node_name ON nodes (node_name);
            CREATE INDEX IF NOT EXISTS idx_nodes_node_id ON nodes (node_id);
            CREATE INDEX IF NOT EXISTS idx_nodes_node_type ON nodes (node_type);
            CREATE INDEX IF NOT EXISTS idx_nodes_is_repeater ON nodes (is_repeater);
            CREATE INDEX IF NOT EXISTS idx_nodes_update ON nodes ("update");
            CREATE INDEX IF NOT EXISTS idx_nodes_is_disabled ON nodes (is_disabled);
            CREATE INDEX IF NOT EXISTS idx_nodes_bot_id ON nodes (bot_id);
            CREATE INDEX IF NOT EXISTS idx_nodes_last_seen_at ON nodes (last_seen_at);
            CREATE INDEX IF NOT EXISTS idx_nodes_requested_date ON nodes (requested_date);
            CREATE INDEX IF NOT EXISTS idx_nodes_telemetry_updated_at ON nodes (telemetry_updated_at);
SQL;
        } else {
            $nodesSql = <<<SQL
            CREATE TABLE IF NOT EXISTS `nodes` (
                `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `public_key`        VARCHAR(64) NOT NULL UNIQUE,
                `node_name`         VARCHAR(128) NULL DEFAULT NULL,
                `node_id`           VARCHAR(16) NULL DEFAULT NULL,
                `password`          VARCHAR(128) NULL DEFAULT NULL,
                `update`            TINYINT(1) NOT NULL DEFAULT 0,
                `is_disabled`       TINYINT(1) NOT NULL DEFAULT 0,
                `bot_id`            VARCHAR(16) NOT NULL DEFAULT 'KLP',
                `advert_format`     VARCHAR(8) NULL DEFAULT NULL,
                `advert_timestamp`  BIGINT UNSIGNED NULL DEFAULT NULL,
                `signature`         VARCHAR(128) NULL DEFAULT NULL,
                `flags_raw`         VARCHAR(10) NULL DEFAULT NULL,
                `node_type`         TINYINT UNSIGNED NULL DEFAULT NULL,
                `node_type_str`     VARCHAR(32) NULL DEFAULT NULL,
                `is_repeater`       TINYINT(1) NOT NULL DEFAULT 0,
                `is_chat`           TINYINT(1) NOT NULL DEFAULT 0,
                `is_room`           TINYINT(1) NOT NULL DEFAULT 0,
                `has_location`      TINYINT(1) NOT NULL DEFAULT 0,
                `latitude`          DECIMAL(10, 7) NULL DEFAULT NULL,
                `longitude`         DECIMAL(10, 7) NULL DEFAULT NULL,
                `battery_mv`        INT UNSIGNED NULL DEFAULT NULL,
                `battery_v`         DECIMAL(4, 2) NULL DEFAULT NULL,
                `temperature_c`     DECIMAL(4, 1) NULL DEFAULT NULL,
                `last_rssi`         DECIMAL(6, 2) NULL DEFAULT NULL,
                `last_snr`          DECIMAL(6, 2) NULL DEFAULT NULL,
                `flags_json`        JSON NULL,
                `raw_advert_json`   JSON NULL,
                `first_seen_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `last_seen_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `requested_date`    TIMESTAMP NULL DEFAULT NULL,
                `telemetry_updated_at` TIMESTAMP NULL DEFAULT NULL,
                `telemetry_fail_count` INT NOT NULL DEFAULT 0,
                `last_failed_request_at` TIMESTAMP NULL DEFAULT NULL,
                INDEX `idx_node_name` (`node_name`),
                INDEX `idx_node_id` (`node_id`),
                INDEX `idx_node_type` (`node_type`),
                INDEX `idx_is_repeater` (`is_repeater`),
                INDEX `idx_nodes_update` (`update`),
                INDEX `idx_nodes_is_disabled` (`is_disabled`),
                INDEX `idx_nodes_bot_id` (`bot_id`),
                INDEX `idx_nodes_requested_date` (`requested_date`),
                INDEX `idx_nodes_telemetry_updated_at` (`telemetry_updated_at`),
                INDEX `idx_last_seen_at` (`last_seen_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
        }

        $this->pdo->exec($nodesSql);

        // Užtikriname, kad esamoje 'nodes', 'raw_packets' ir 'telemetry' lentelėse būtų reikalingi stulpeliai (saugi migracija)
        if ($driver === 'pgsql') {
            try {
                $this->pdo->exec('ALTER TABLE nodes ADD COLUMN IF NOT EXISTS password VARCHAR(128) NULL');
                $this->pdo->exec('ALTER TABLE nodes ADD COLUMN IF NOT EXISTS "update" BOOLEAN NOT NULL DEFAULT false');
                $this->pdo->exec('ALTER TABLE nodes ADD COLUMN IF NOT EXISTS is_disabled BOOLEAN NOT NULL DEFAULT false');
                $this->pdo->exec('ALTER TABLE nodes ADD COLUMN IF NOT EXISTS bot_id VARCHAR(16) NOT NULL DEFAULT \'KLP\'');
                $this->pdo->exec('ALTER TABLE nodes ADD COLUMN IF NOT EXISTS temperature_c NUMERIC(4, 1) NULL');
                $this->pdo->exec('ALTER TABLE nodes ADD COLUMN IF NOT EXISTS requested_date TIMESTAMP WITHOUT TIME ZONE NULL');
                $this->pdo->exec('ALTER TABLE nodes ADD COLUMN IF NOT EXISTS telemetry_updated_at TIMESTAMP WITHOUT TIME ZONE NULL');
                $this->pdo->exec('ALTER TABLE nodes ADD COLUMN IF NOT EXISTS telemetry_fail_count INTEGER NOT NULL DEFAULT 0');
                $this->pdo->exec('ALTER TABLE nodes ADD COLUMN IF NOT EXISTS last_failed_request_at TIMESTAMP WITHOUT TIME ZONE NULL');
                $this->pdo->exec('ALTER TABLE nodes ADD COLUMN IF NOT EXISTS last_path VARCHAR(64) NULL');
                $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_nodes_update ON nodes ("update")');
                $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_nodes_is_disabled ON nodes (is_disabled)');
                $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_nodes_bot_id ON nodes (bot_id)');
                $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_nodes_requested_date ON nodes (requested_date)');
                $this->pdo->exec('ALTER TABLE ' . $this->table . ' ADD COLUMN IF NOT EXISTS bot_id VARCHAR(16) NOT NULL DEFAULT \'KLP\'');
                $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_' . $this->table . '_bot_id ON ' . $this->table . ' (bot_id)');

                // Užtikriname, kad telemetry lentelėje nebūtų ADVERT įrašų ir atnaujiname telemetry_updated_at
                $this->pdo->exec("DELETE FROM telemetry WHERE type = 'ADVERT' OR (type != 'TELEMETRY' AND type != 'STATUS')");
                $this->pdo->exec("
                    UPDATE nodes n
                    SET telemetry_updated_at = (
                        SELECT MAX(t.recorded_at)
                        FROM telemetry t
                        WHERE (t.node_id = n.node_id OR (n.public_key IS NOT NULL AND t.public_key = n.public_key))
                          AND t.type IN ('TELEMETRY', 'STATUS')
                    )
                ");
            } catch (\Throwable $e) {}
        } else {
            try {
                $hasPassword = false;
                $hasUpdate = false;
                $hasIsDisabled = false;
                $hasBotId = false;
                $hasReqDate = false;
                $hasTelemDate = false;
                $hasTempC = false;
                $hasFailCount = false;
                $hasLastFailedReq = false;
                $hasLastPath = false;
                $colsStmt = $this->pdo->query("SHOW COLUMNS FROM `nodes`");
                while ($c = $colsStmt->fetch(\PDO::FETCH_ASSOC)) {
                    $fn = strtolower($c['Field'] ?? '');
                    if ($fn === 'password') $hasPassword = true;
                    if ($fn === 'update' || $fn === 'updete') $hasUpdate = true;
                    if ($fn === 'is_disabled') $hasIsDisabled = true;
                    if ($fn === 'bot_id') $hasBotId = true;
                    if ($fn === 'requested_date') $hasReqDate = true;
                    if ($fn === 'telemetry_updated_at') $hasTelemDate = true;
                    if ($fn === 'temperature_c') $hasTempC = true;
                    if ($fn === 'telemetry_fail_count') $hasFailCount = true;
                    if ($fn === 'last_failed_request_at') $hasLastFailedReq = true;
                    if ($fn === 'last_path') $hasLastPath = true;
                }
                if (!$hasPassword) {
                    $this->pdo->exec("ALTER TABLE `nodes` ADD COLUMN `password` VARCHAR(128) NULL DEFAULT NULL AFTER `node_id`");
                }
                if (!$hasLastPath) {
                    $this->pdo->exec("ALTER TABLE `nodes` ADD COLUMN `last_path` VARCHAR(64) NULL DEFAULT NULL AFTER `password`");
                }
                if (!$hasUpdate) {
                    $this->pdo->exec("ALTER TABLE `nodes` ADD COLUMN `update` TINYINT(1) NOT NULL DEFAULT 0 AFTER `last_path`");
                    try {
                        $this->pdo->exec("ALTER TABLE `nodes` ADD INDEX `idx_nodes_update` (`update`)");
                    } catch (\Throwable $e) {}
                }
                if (!$hasIsDisabled) {
                    $this->pdo->exec("ALTER TABLE `nodes` ADD COLUMN `is_disabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `update`");
                    try {
                        $this->pdo->exec("ALTER TABLE `nodes` ADD INDEX `idx_nodes_is_disabled` (`is_disabled`)");
                    } catch (\Throwable $e) {}
                }
                if (!$hasBotId) {
                    $this->pdo->exec("ALTER TABLE `nodes` ADD COLUMN `bot_id` VARCHAR(16) NOT NULL DEFAULT 'KLP' AFTER `is_disabled`");
                    try {
                        $this->pdo->exec("ALTER TABLE `nodes` ADD INDEX `idx_nodes_bot_id` (`bot_id`)");
                    } catch (\Throwable $e) {}
                }
                if (!$hasReqDate) {
                    $this->pdo->exec("ALTER TABLE `nodes` ADD COLUMN `requested_date` TIMESTAMP NULL DEFAULT NULL AFTER `last_seen_at`");
                    try {
                        $this->pdo->exec("ALTER TABLE `nodes` ADD INDEX `idx_nodes_requested_date` (`requested_date`)");
                    } catch (\Throwable $e) {}
                }
                if (!$hasTelemDate) {
                    $this->pdo->exec("ALTER TABLE `nodes` ADD COLUMN `telemetry_updated_at` TIMESTAMP NULL DEFAULT NULL AFTER `requested_date`");
                    try {
                        $this->pdo->exec("ALTER TABLE `nodes` ADD INDEX `idx_nodes_telemetry_updated_at` (`telemetry_updated_at`)");
                    } catch (\Throwable $e) {}
                }
                if (!$hasTempC) {
                    $this->pdo->exec("ALTER TABLE `nodes` ADD COLUMN `temperature_c` DECIMAL(4, 1) NULL DEFAULT NULL AFTER `battery_v`");
                }
                if (!$hasFailCount) {
                    $this->pdo->exec("ALTER TABLE `nodes` ADD COLUMN `telemetry_fail_count` INT NOT NULL DEFAULT 0 AFTER `telemetry_updated_at`");
                }
                if (!$hasLastFailedReq) {
                    $this->pdo->exec("ALTER TABLE `nodes` ADD COLUMN `last_failed_request_at` TIMESTAMP NULL DEFAULT NULL AFTER `telemetry_fail_count`");
                }

                // Užtikriname, kad telemetry lentelėje nebūtų ADVERT įrašų (tik TELEMETRY ir STATUS laikoma telemetrija)
                $this->pdo->exec("DELETE FROM telemetry WHERE type = 'ADVERT' OR (type != 'TELEMETRY' AND type != 'STATUS')");
                
                // Sinchronizuojame nodes.telemetry_updated_at tik pagal tikrus TELEMETRY ir STATUS įrašus
                $this->pdo->exec("
                    UPDATE nodes n 
                    LEFT JOIN (
                        SELECT 
                            COALESCE(public_key, node_id) AS node_key,
                            MAX(recorded_at) AS max_telem
                        FROM telemetry
                        WHERE type IN ('TELEMETRY', 'STATUS')
                        GROUP BY COALESCE(public_key, node_id)
                    ) t ON (n.public_key = t.node_key OR n.node_id = t.node_key)
                    SET n.telemetry_updated_at = t.max_telem
                ");

                // Tikriname raw_packets bot_id
                $hasRawBotId = false;
                $rawColsStmt = $this->pdo->query("SHOW COLUMNS FROM `{$this->table}`");
                while ($c = $rawColsStmt->fetch(\PDO::FETCH_ASSOC)) {
                    if (strtolower($c['Field'] ?? '') === 'bot_id') $hasRawBotId = true;
                }
                if (!$hasRawBotId) {
                    $this->pdo->exec("ALTER TABLE `{$this->table}` ADD COLUMN `bot_id` VARCHAR(16) NOT NULL DEFAULT 'KLP' AFTER `direction`");
                    try {
                        $this->pdo->exec("ALTER TABLE `{$this->table}` ADD INDEX `idx_raw_packets_bot_id` (`bot_id`)");
                    } catch (\Throwable $e) {}
                }

                // Užtikriname, kad 'last_seen_at' neturėtų automatinio ON UPDATE CURRENT_TIMESTAMP
                try {
                    $this->pdo->exec("ALTER TABLE `nodes` MODIFY COLUMN `last_seen_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP");
                } catch (\Throwable $e) {}
            } catch (\Throwable $e) {}
        }

        // ---------------------------------------------------------------------
        // 3. Sukuriame 'telemetry' lentelę laiko eilučių metrikoms ir grafikams
        // ---------------------------------------------------------------------
        if ($driver === 'pgsql') {
            $telemetrySql = <<<SQL
            CREATE TABLE IF NOT EXISTS telemetry (
                id BIGSERIAL PRIMARY KEY,
                node_id VARCHAR(16) NOT NULL,
                public_key VARCHAR(64) NULL,
                raw_packet_id BIGINT NULL,
                type VARCHAR(16) NOT NULL DEFAULT 'STATUS',
                bot_id VARCHAR(16) NULL DEFAULT 'KLP',
                battery_mv INT NULL,
                battery_v NUMERIC(4, 2) NULL,
                temperature_c NUMERIC(4, 1) NULL,
                rssi NUMERIC(6, 2) NULL,
                snr NUMERIC(6, 2) NULL,
                noise_floor SMALLINT NULL,
                uptime_seconds INT NULL,
                air_time_seconds INT NULL,
                packets_recv INT NULL,
                packets_sent INT NULL,
                tx_queue_len SMALLINT NULL,
                error_count SMALLINT NULL,
                recorded_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
            );
            CREATE INDEX IF NOT EXISTS idx_telemetry_node_time ON telemetry (node_id, recorded_at);
            CREATE INDEX IF NOT EXISTS idx_telemetry_pubkey_time ON telemetry (public_key, recorded_at);
            CREATE INDEX IF NOT EXISTS idx_telemetry_bot_id ON telemetry (bot_id);
            CREATE INDEX IF NOT EXISTS idx_telemetry_time ON telemetry (recorded_at);
            CREATE INDEX IF NOT EXISTS idx_telemetry_raw_packet ON telemetry (raw_packet_id);
SQL;
        } else {
            $telemetrySql = <<<SQL
            CREATE TABLE IF NOT EXISTS `telemetry` (
                `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `node_id`           VARCHAR(16) NOT NULL,
                `public_key`        VARCHAR(64) NULL DEFAULT NULL,
                `raw_packet_id`     BIGINT UNSIGNED NULL DEFAULT NULL,
                `type`              VARCHAR(16) NOT NULL DEFAULT 'STATUS',
                `bot_id`            VARCHAR(16) NULL DEFAULT 'KLP',
                `battery_mv`        INT UNSIGNED NULL DEFAULT NULL,
                `battery_v`         DECIMAL(4, 2) NULL DEFAULT NULL,
                `temperature_c`     DECIMAL(4, 1) NULL DEFAULT NULL,
                `rssi`              DECIMAL(6, 2) NULL DEFAULT NULL,
                `snr`               DECIMAL(6, 2) NULL DEFAULT NULL,
                `noise_floor`       SMALLINT NULL DEFAULT NULL,
                `uptime_seconds`    INT UNSIGNED NULL DEFAULT NULL,
                `air_time_seconds`  INT UNSIGNED NULL DEFAULT NULL,
                `packets_recv`      INT UNSIGNED NULL DEFAULT NULL,
                `packets_sent`      INT UNSIGNED NULL DEFAULT NULL,
                `tx_queue_len`      SMALLINT UNSIGNED NULL DEFAULT NULL,
                `error_count`       SMALLINT UNSIGNED NULL DEFAULT NULL,
                `recorded_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_telemetry_node_time` (`node_id`, `recorded_at`),
                INDEX `idx_telemetry_pubkey_time` (`public_key`, `recorded_at`),
                INDEX `idx_telemetry_bot_id` (`bot_id`),
                INDEX `idx_telemetry_time` (`recorded_at`),
                INDEX `idx_telemetry_raw_packet` (`raw_packet_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
        }

        $this->pdo->exec($telemetrySql);

        // Safe migration for telemetry bot_id
        if ($driver === 'pgsql') {
            try {
                $this->pdo->exec('ALTER TABLE telemetry ADD COLUMN IF NOT EXISTS bot_id VARCHAR(16) NULL DEFAULT \'KLP\'');
                $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_telemetry_bot_id ON telemetry (bot_id)');
            } catch (\Throwable $e) {}
        } else {
            try {
                $hasTelemBotId = false;
                $telemColsStmt = $this->pdo->query("SHOW COLUMNS FROM `telemetry`");
                while ($c = $telemColsStmt->fetch(\PDO::FETCH_ASSOC)) {
                    if (strtolower($c['Field'] ?? '') === 'bot_id') $hasTelemBotId = true;
                }
                if (!$hasTelemBotId) {
                    $this->pdo->exec("ALTER TABLE `telemetry` ADD COLUMN `bot_id` VARCHAR(16) NULL DEFAULT 'KLP' AFTER `type`");
                    try {
                        $this->pdo->exec("ALTER TABLE `telemetry` ADD INDEX `idx_telemetry_bot_id` (`bot_id`)");
                    } catch (\Throwable $e) {}
                }
            } catch (\Throwable $e) {}
        }

        // Užtikriname, kad 'telemetry' lentelėje būtų 'raw_packet_id' stulpelis (saugi migracija)
        if ($driver === 'pgsql') {
            try {
                $this->pdo->exec('ALTER TABLE telemetry ADD COLUMN IF NOT EXISTS raw_packet_id BIGINT NULL');
                $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_telemetry_raw_packet ON telemetry (raw_packet_id)');
            } catch (\Throwable $e) {}
        } else {
            try {
                $hasRawPacketId = false;
                $colsStmt = $this->pdo->query("SHOW COLUMNS FROM `telemetry`");
                while ($c = $colsStmt->fetch(\PDO::FETCH_ASSOC)) {
                    if (strtolower($c['Field'] ?? '') === 'raw_packet_id') $hasRawPacketId = true;
                }
                if (!$hasRawPacketId) {
                    $this->pdo->exec("ALTER TABLE `telemetry` ADD COLUMN `raw_packet_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `public_key`");
                    try {
                        $this->pdo->exec("ALTER TABLE `telemetry` ADD INDEX `idx_telemetry_raw_packet` (`raw_packet_id`)");
                    } catch (\Throwable $e) {}
                }
            } catch (\Throwable $e) {}
        }

        // ---------------------------------------------------------------------
        // 4. Sukuriame 'system_settings' lentelę sistemos ir žemėlapio konfigūracijai
        // ---------------------------------------------------------------------
        try {
            if ($driver === 'pgsql') {
                $this->pdo->exec("
                    CREATE TABLE IF NOT EXISTS system_settings (
                        setting_key VARCHAR(64) PRIMARY KEY,
                        setting_value TEXT NULL,
                        updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
                    )
                ");
            } else {
                $this->pdo->exec("
                    CREATE TABLE IF NOT EXISTS `system_settings` (
                        `setting_key`   VARCHAR(64) NOT NULL PRIMARY KEY,
                        `setting_value` TEXT NULL,
                        `updated_at`    TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
            }
        } catch (\Throwable $e) {}

        // Automatiškai pašaliname senus iškreiptus/klaidingus telemetrijos taškus (pvz. su >15V ar triukšmu)
        $this->cleanupInvalidTelemetry();
    }

    /**
     * Išsaugo naują atėjusį neapdorotą (RAW) įrašą.
     * Nustato:
     * - processed = false (pagal nutylėjimą)
     * - created_at = CURRENT_TIMESTAMP
     * - processed_at = NULL
     *
     * @param array|string $data Gauti duomenys (JSON asociatyvus masyvas arba grynas raw_hex)
     * @param string|null $sharedSecretHex Bendras šifravimo raktas (jei nurodytas atskirai)
     * @return int Sukurto įrašo ID
     */
    public function saveRaw($data, ?string $sharedSecretHex = null): int
    {
        if (is_string($data)) {
            $rawHex = trim($data);
            $payload = [
                'raw_hex'           => $rawHex,
                'shared_secret_hex' => $sharedSecretHex,
            ];
        } else {
            $payload = $data;
            $rawHex  = trim((string)($payload['raw_hex'] ?? ($payload['raw'] ?? '')));
            if (!empty($sharedSecretHex) && empty($payload['shared_secret_hex'])) {
                $payload['shared_secret_hex'] = $sharedSecretHex;
            }
        }

        if (empty($rawHex)) {
            throw new \InvalidArgumentException('Nepateiktas privalomas raw_hex laukelis.');
        }

        $direction       = !empty($payload['direction']) ? substr((string)$payload['direction'], 0, 16) : null;
        $length          = isset($payload['length']) ? (int)$payload['length'] : (int)(strlen($rawHex) / 2);
        $rssi            = isset($payload['rssi']) ? (float)$payload['rssi'] : null;
        $snr             = isset($payload['snr']) ? (float)$payload['snr'] : null;
        $targetName      = !empty($payload['target_name']) ? (string)$payload['target_name'] : null;
        $targetPubKey    = !empty($payload['target_public_key']) ? (string)$payload['target_public_key'] : null;
        $senderName      = !empty($payload['sender_name']) ? (string)$payload['sender_name'] : null;
        $senderPubKey    = !empty($payload['sender_public_key']) ? (string)$payload['sender_public_key'] : null;
        $myPubKey        = !empty($payload['my_public_key']) ? (string)$payload['my_public_key'] : null;
        $secret          = !empty($payload['shared_secret_hex']) ? (string)$payload['shared_secret_hex'] : $sharedSecretHex;
        $isEncrypted     = !empty($payload['is_encrypted']) || !empty($secret);
        $botId           = !empty($payload['bot_id']) ? strtoupper(substr(trim((string)$payload['bot_id']), 0, 16)) : 'KLP';
        $payloadJson     = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        // -------------------------------------------------------------
        // 🛡️ DUBLIKATŲ / AIDŲ FILTRAVIMAS SERVERYJE
        // -------------------------------------------------------------
        // Jei gautas RX paketas, tikriname, ar per pastarąsias 20s tas pats bot_id
        // jau neįrašė to paties paketo (pagal raw_hex arba ištrauktą krovinį).
        if ($direction === 'RX') {
            $dedupWindowSec = 20;
            $timeCondition = ($driver === 'pgsql')
                ? "created_at >= NOW() - INTERVAL '{$dedupWindowSec} seconds'"
                : "created_at >= NOW() - INTERVAL {$dedupWindowSec} SECOND";
            $exactHexOp = ($driver === 'pgsql') ? "raw_hex ILIKE :raw_hex" : "raw_hex = :raw_hex";

            // 1. Tikriname tikslų raw_hex dublikatą
            $checkSql = "SELECT id FROM {$this->table}
                         WHERE bot_id = :bot_id 
                           AND direction = :direction 
                           AND {$exactHexOp} 
                           AND {$timeCondition} 
                         ORDER BY id DESC LIMIT 1";
            $checkStmt = $this->pdo->prepare($checkSql);
            $checkStmt->execute([
                ':bot_id'    => $botId,
                ':direction' => $direction,
                ':raw_hex'   => $rawHex,
            ]);
            $existingId = $checkStmt->fetchColumn();
            if ($existingId) {
                return (int)$existingId;
            }

            // 2. Tikriname pagal paketo krovinį (payloadHex), nes maršruto baitai (hops) skiriasi, o duomenys tie patys
            $extractedPayload = MeshCoreDecoder::extractPayloadHex($rawHex);
            if (!empty($extractedPayload) && strlen($extractedPayload) >= 8) {
                $likeOp = ($driver === 'pgsql') ? 'ILIKE' : 'LIKE';
                $payloadSql = "SELECT id FROM {$this->table}
                               WHERE bot_id = :bot_id 
                                 AND direction = :direction 
                                 AND raw_hex {$likeOp} :payload_suffix 
                                 AND {$timeCondition} 
                               ORDER BY id DESC LIMIT 1";
                $pStmt = $this->pdo->prepare($payloadSql);
                $pStmt->execute([
                    ':bot_id'         => $botId,
                    ':direction'      => $direction,
                    ':payload_suffix' => '%' . $extractedPayload,
                ]);
                $existingPayloadId = $pStmt->fetchColumn();
                if ($existingPayloadId) {
                    return (int)$existingPayloadId;
                }
            }
        }
        
        $sql = "INSERT INTO {$this->table} (
                    direction, raw_hex, length, rssi, snr,
                    target_name, target_public_key, sender_name, sender_public_key,
                    my_public_key, shared_secret_hex, is_encrypted, bot_id, payload_json,
                    processed, created_at, processed_at
                ) VALUES (
                    :direction, :raw_hex, :length, :rssi, :snr,
                    :target_name, :target_public_key, :sender_name, :sender_public_key,
                    :my_public_key, :shared_secret_hex, :is_encrypted, :bot_id, :payload_json,
                    false, CURRENT_TIMESTAMP, NULL
                )";

        if ($driver === 'pgsql') {
            $sql .= " RETURNING id";
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':direction', $direction);
            $stmt->bindValue(':raw_hex', $rawHex);
            $stmt->bindValue(':length', $length, PDO::PARAM_INT);
            $stmt->bindValue(':rssi', $rssi);
            $stmt->bindValue(':snr', $snr);
            $stmt->bindValue(':target_name', $targetName);
            $stmt->bindValue(':target_public_key', $targetPubKey);
            $stmt->bindValue(':sender_name', $senderName);
            $stmt->bindValue(':sender_public_key', $senderPubKey);
            $stmt->bindValue(':my_public_key', $myPubKey);
            $stmt->bindValue(':shared_secret_hex', $secret);
            $stmt->bindValue(':is_encrypted', $isEncrypted, PDO::PARAM_BOOL);
            $stmt->bindValue(':bot_id', $botId);
            $stmt->bindValue(':payload_json', $payloadJson);
            $stmt->execute();
            return (int)$stmt->fetchColumn();
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':direction', $direction);
        $stmt->bindValue(':raw_hex', $rawHex);
        $stmt->bindValue(':length', $length, PDO::PARAM_INT);
        $stmt->bindValue(':rssi', $rssi);
        $stmt->bindValue(':snr', $snr);
        $stmt->bindValue(':target_name', $targetName);
        $stmt->bindValue(':target_public_key', $targetPubKey);
        $stmt->bindValue(':sender_name', $senderName);
        $stmt->bindValue(':sender_public_key', $senderPubKey);
        $stmt->bindValue(':my_public_key', $myPubKey);
        $stmt->bindValue(':shared_secret_hex', $secret);
        $stmt->bindValue(':is_encrypted', $isEncrypted ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':bot_id', $botId);
        $stmt->bindValue(':payload_json', $payloadJson);
        $stmt->execute();

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Paima sekantį įrašą apdorojimui pagal griežtą vartotojo nurodytą taisyklę:
     * - Filtruojama tik neapdoroti įrašai (`processed = false`).
     * - Prioritetas įrašams su tuščia apdorojimo data (`processed_at IS NULL` pirma).
     * - Po to vėliausia apdorojimo data su processed false (`processed_at DESC, id ASC`).
     * 
     * Pasiėmus įrašą, jam IŠKART įrašoma `processed_at = CURRENT_TIMESTAMP`.
     * 
     * PostgreSQL atveju naudojama `FOR UPDATE SKIP LOCKED`, kad keli lygiagretūs
     * darbininkai (workers) niekada nepasiimtų to paties įrašo.
     *
     * @return array|null Paimtas įrašas arba null, jei eilė tuščia
     */
    public function fetchNextForProcessing(): ?array
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'pgsql') {
            // Atominis paėmimas ir datos atnaujinimas vienoje užklausoje per CTE
            $sql = <<<SQL
            WITH next_item AS (
                SELECT id 
                FROM {$this->table}
                WHERE processed = false
                ORDER BY 
                    CASE WHEN processed_at IS NULL THEN 0 ELSE 1 END ASC,
                    processed_at DESC,
                    id ASC
                LIMIT 1
                FOR UPDATE SKIP LOCKED
            )
            UPDATE {$this->table}
            SET processed_at = CURRENT_TIMESTAMP
            FROM next_item
            WHERE {$this->table}.id = next_item.id
            RETURNING {$this->table}.*;
SQL;
            $stmt = $this->pdo->query($sql);
            $row = $stmt->fetch();
            return $row ?: null;
        }

        // Standartinė transakcija kitoms DB (MySQL/SQLite)
        $this->pdo->beginTransaction();
        try {
            $selectSql = <<<SQL
            SELECT * FROM {$this->table}
            WHERE processed = 0 OR processed = false
            ORDER BY 
                CASE WHEN processed_at IS NULL THEN 0 ELSE 1 END ASC,
                processed_at DESC,
                id ASC
            LIMIT 1
            FOR UPDATE
SQL;
            $stmt = $this->pdo->query($selectSql);
            $row = $stmt->fetch();

            if ($row) {
                $updateSql = "UPDATE {$this->table} SET processed_at = CURRENT_TIMESTAMP WHERE id = :id";
                $uStmt = $this->pdo->prepare($updateSql);
                $uStmt->execute([':id' => $row['id']]);
                $this->pdo->commit();
                $row['processed_at'] = date('Y-m-d H:i:s');
                return $row;
            }

            $this->pdo->commit();
            return null;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Pažymi įrašą kaip sėkmingai apdorotą.
     * Tik dabar uždeda `processed = true`, įrašo `completed_at` ir išsaugo `decoded_json`.
     *
     * @param int $id Įrašo ID
     * @param array $decodedData Iškoduoto paketo duomenys
     * @return bool
     */
    public function completeProcessing(int $id, array $decodedData, ?string $errorMessage = null): bool
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $decodedJson = json_encode($decodedData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $sql = "UPDATE {$this->table}
                SET processed = :processed,
                    completed_at = CURRENT_TIMESTAMP,
                    decoded_json = :decoded_json,
                    error_message = :error_message
                WHERE id = :id";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':decoded_json', $decodedJson);
        $stmt->bindValue(':error_message', $errorMessage);

        if ($driver === 'pgsql') {
            $stmt->bindValue(':processed', true, PDO::PARAM_BOOL);
        } else {
            $stmt->bindValue(':processed', 1, PDO::PARAM_INT);
        }

        return $stmt->execute();
    }

    /**
     * Pažymi apdorojimo klaidą.
     * `processed` LIEKA false, kad įrašas pagal taisyklę būtų išsaugotas su veliausia processed_at
     * ir galėtų būti peržiūrėtas arba pakartotinai apdorotas.
     *
     * @param int $id Įrašo ID
     * @param string $errorMessage Klaidos pranešimas
     * @param array|null $partialDecoded Daliniai iškoduoti duomenys (jei yra)
     * @return bool
     */
    public function failProcessing(int $id, string $errorMessage, ?array $partialDecoded = null): bool
    {
        $decodedJson = $partialDecoded ? json_encode($partialDecoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

        $sql = "UPDATE {$this->table}
                SET error_message = :error_message,
                    processed_at = CURRENT_TIMESTAMP" .
                    ($decodedJson !== null ? ", decoded_json = :decoded_json" : "") .
                " WHERE id = :id";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':error_message', $errorMessage);
        if ($decodedJson !== null) {
            $stmt->bindValue(':decoded_json', $decodedJson);
        }

        return $stmt->execute();
    }

    /**
     * Apdoroja konkretų įrašą su MeshCoreDecoder:
     * - Užfiksuoja `processed_at`, jei dar nebuvo užfiksuota
     * - Atlieka dekodavimą / dešifravimą
     * - Pabaigus uždeda `processed = true`
     *
     * @param int $id Įrašo ID
     * @param MeshCoreDecoder $decoder Dekoderio objektas
     * @return array Iškoduotas rezultatas
     */
    public function processRecord(int $id, MeshCoreDecoder $decoder): array
    {
        // Pasižymime processed_at, jei dar nebuvo nustatyta
        $markSql = "UPDATE {$this->table} SET processed_at = CURRENT_TIMESTAMP WHERE id = :id AND processed_at IS NULL";
        $mStmt = $this->pdo->prepare($markSql);
        $mStmt->execute([':id' => $id]);

        // Gauname įrašo duomenis
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->table} WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $record = $stmt->fetch();

        if (!$record) {
            throw new \RuntimeException("Įrašas ID #{$id} nerastas duomenų bazėje.");
        }

        $rawHex = $record['raw_hex'];
        $secret = $record['shared_secret_hex'];

        $payload = null;
        if (!empty($record['payload_json'])) {
            $payload = json_decode($record['payload_json'], true);
        }

        try {
            if (is_array($payload)) {
                $decoded = $decoder->decodeApiPayload($payload);
            } else {
                $decoded = $decoder->decodeRawPacket($rawHex, $secret, $record['my_public_key'] ?? null);
            }

            // Jei pakete aptiktas ADVERT skelbimas – iškart atnaujiname/įrašome į 'nodes' lentelę
            if (!empty($decoded['advert']) && is_array($decoded['advert']) && !empty($decoded['advert']['public_key'])) {
                $this->upsertNodeFromAdvert($decoded['advert'], $record);
            }

            // Įrašome telemetrijos ir būsenos rodiklius į 'telemetry' lentelę grafikams ir statistikai
            $this->extractAndSaveTelemetry($id, $decoded, $record);

            // Jei gautas RX paketas iš žinomo siuntėjo mazgo – atnaujiname jo last_seen_at tinkle
            if (!empty($record['direction']) && strtoupper((string)$record['direction']) === 'RX') {
                $senderKey = !empty($record['sender_public_key']) ? (string)$record['sender_public_key'] : null;
                if ($senderKey) {
                    $bId = !empty($record['bot_id']) ? (string)$record['bot_id'] : 'KLP';
                    $r = isset($record['rssi']) ? (float)$record['rssi'] : null;
                    $s = isset($record['snr']) ? (float)$record['snr'] : null;
                    $this->touchNodeSeen($senderKey, $bId, null, null, $r, $s);
                }
            }

            // Nustatome ar dekodavimo metu kilo klaidų (dešifravimo, skelbimo ar bendrų)
            $errorMsg = null;
            if (!empty($decoded['error'])) {
                $errorMsg = (string)$decoded['error'];
            } elseif (!empty($decoded['encryption']['decryption_error'])) {
                $errorMsg = (string)$decoded['encryption']['decryption_error'];
            } elseif (!empty($decoded['advert']['error'])) {
                $errorMsg = (string)$decoded['advert']['error'];
            }

            if (!empty($decoded['success'])) {
                $this->completeProcessing($id, $decoded, $errorMsg);
            } else {
                $err = $errorMsg ?: 'Nepavyko iškoduoti paketo';
                $this->failProcessing($id, $err, $decoded);
            }

            return $decoded;
        } catch (\Throwable $e) {
            $this->failProcessing($id, $e->getMessage());
            throw $e;
        }
    }

    /**
     * Sinchronizuoja ir atnaujina 'error_message' stulpelį esamiems įrašams duomenų bazėje,
     * jei decoded_json turi dešifravimo ar skelbimo klaidą, bet error_message buvo NULL.
     */
    public function syncErrorsFromDecodedJson(int $limit = 500): int
    {
        $count = 0;
        try {
            $sql = "SELECT id, decoded_json, error_message FROM {$this->table} 
                    WHERE decoded_json IS NOT NULL 
                    ORDER BY id DESC LIMIT " . (int)$limit;
            $stmt = $this->pdo->query($sql);
            $updateStmt = $this->pdo->prepare("UPDATE {$this->table} SET error_message = :err WHERE id = :id");

            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $dec = is_string($row['decoded_json']) ? json_decode($row['decoded_json'], true) : $row['decoded_json'];
                if (!is_array($dec)) continue;

                $err = null;
                if (!empty($dec['error'])) {
                    $err = (string)$dec['error'];
                } elseif (!empty($dec['encryption']['decryption_error'])) {
                    $err = (string)$dec['encryption']['decryption_error'];
                } elseif (!empty($dec['advert']['error'])) {
                    $err = (string)$dec['advert']['error'];
                }

                if ($err !== null && (empty($row['error_message']) || $row['error_message'] !== $err)) {
                    $updateStmt->execute([':err' => $err, ':id' => $row['id']]);
                    $count++;
                }
            }
        } catch (\Throwable $e) {
            // Ignoruojame
        }
        return $count;
    }

    /**
     * Paima sekantį prioritetinį įrašą iš eilės, jį iškoduoja ir pažymi kaip processed = true.
     *
     * @param MeshCoreDecoder $decoder
     * @return array|null Iškoduotas rezultatas arba null, jei nėra ką apdoroti
     */
    public function processNext(MeshCoreDecoder $decoder): ?array
    {
        $item = $this->fetchNextForProcessing();
        if (!$item) {
            return null;
        }

        return $this->processRecord((int)$item['id'], $decoder);
    }

    /**
     * Atnaujina decoded_json lauką neuždedant processed=true (pvz. klaidų analizei)
     */
    private function updateDecodedJsonWithoutCompleting(int $id, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $this->pdo->prepare("UPDATE {$this->table} SET decoded_json = :json WHERE id = :id");
        $stmt->execute([':id' => $id, ':json' => $json]);
    }

    /**
     * Grąžina bendrą įrašų ir eilių statistiką
     */
    public function getStats(): array
    {
        $sql = <<<SQL
        SELECT 
            COUNT(*) AS total_count,
            COUNT(*) FILTER (WHERE processed = false AND processed_at IS NULL) AS pending_never_processed,
            COUNT(*) FILTER (WHERE processed = false AND processed_at IS NOT NULL) AS in_progress_or_retry,
            COUNT(*) FILTER (WHERE processed = true) AS completed_count,
            COUNT(*) FILTER (WHERE error_message IS NOT NULL) AS error_count
        FROM {$this->table}
SQL;
        try {
            $stmt = $this->pdo->query($sql);
            $stats = $stmt->fetch();
            return $stats ?: [];
        } catch (\Throwable $e) {
            // Paprastas fallback, jei DB nepalaiko FILTER
            $fallback = <<<SQL
            SELECT 
                COUNT(*) AS total_count,
                SUM(CASE WHEN processed = false AND processed_at IS NULL THEN 1 ELSE 0 END) AS pending_never_processed,
                SUM(CASE WHEN processed = false AND processed_at IS NOT NULL THEN 1 ELSE 0 END) AS in_progress_or_retry,
                SUM(CASE WHEN processed = true THEN 1 ELSE 0 END) AS completed_count,
                SUM(CASE WHEN error_message IS NOT NULL THEN 1 ELSE 0 END) AS error_count
            FROM {$this->table}
SQL;
            $stmt = $this->pdo->query($fallback);
            return $stmt->fetch() ?: [];
        }
    }

    /**
     * Įrašo arba atnaujina mazgo duomenis 'nodes' lentelėje iš gauto ADVERT skelbimo.
     *
     * @param array $advert Iškoduotas advert masyvas
     * @param array|null $packetMeta Papildomi paketo metaduomenys (rssi, snr ir t.t.)
     * @return bool
     */
    public function upsertNodeFromAdvert(array $advert, ?array $packetMeta = null): bool
    {
        $pubKey = strtolower(trim($advert['public_key'] ?? ''));
        if (empty($pubKey)) {
            return false;
        }

        $nodeName = $advert['node_name'] ?? null;
        $nodeId = substr($pubKey, 0, 4);
        $format = $advert['format'] ?? null;
        $timestamp = isset($advert['timestamp']) ? (int)$advert['timestamp'] : null;
        $signature = $advert['signature'] ?? null;

        $flags = $advert['flags'] ?? [];
        $flagsRaw = $flags['raw'] ?? null;
        $nodeType = isset($flags['type']) ? (int)$flags['type'] : null;
        $nodeTypeStr = $flags['type_str'] ?? null;
        $isRepeater = !empty($flags['repeater']) || (strtolower((string)$nodeTypeStr) === 'repeater') || ($nodeType === 2);
        $isChat = !empty($flags['chat']);
        $isRoom = !empty($flags['room']);
        $hasLocation = !empty($flags['hasLocation']);

        $autoAdd = (bool)$this->getSetting('auto_add_new_repeaters', false);
        $initialUpdate = ($isRepeater && $autoAdd);

        $lat = isset($advert['location']['latitude']) ? (float)$advert['location']['latitude'] : null;
        $lon = isset($advert['location']['longitude']) ? (float)$advert['location']['longitude'] : null;

        $batteryMv = isset($advert['battery']['voltage_mv']) ? (int)$advert['battery']['voltage_mv'] : null;
        $batteryV = isset($advert['battery']['voltage_v']) ? (float)$advert['battery']['voltage_v'] : null;

        $rssi = isset($packetMeta['rssi']) ? (float)$packetMeta['rssi'] : null;
        $snr = isset($packetMeta['snr']) ? (float)$packetMeta['snr'] : null;

        $botId = !empty($packetMeta['bot_id']) 
            ? strtoupper(substr(trim((string)$packetMeta['bot_id']), 0, 16)) 
            : (!empty($packetMeta['payload_json']) && is_string($packetMeta['payload_json']) && ($pj = json_decode($packetMeta['payload_json'], true)) && !empty($pj['bot_id']) 
                ? strtoupper(substr(trim((string)$pj['bot_id']), 0, 16)) 
                : 'KLP');

        $flagsJson = !empty($flags) ? json_encode($flags, JSON_UNESCAPED_UNICODE) : null;
        $rawAdvertJson = json_encode($advert, JSON_UNESCAPED_UNICODE);

        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'pgsql') {
            $sql = <<<SQL
            INSERT INTO nodes (
                public_key, node_name, node_id, advert_format, advert_timestamp, signature,
                flags_raw, node_type, node_type_str, is_repeater, is_chat, is_room,
                has_location, latitude, longitude, battery_mv, battery_v,
                last_rssi, last_snr, flags_json, raw_advert_json, bot_id, first_seen_at, last_seen_at,
                requested_date, telemetry_updated_at, "update"
            ) VALUES (
                :public_key, :node_name, :node_id, :advert_format, :advert_timestamp, :signature,
                :flags_raw, :node_type, :node_type_str, :is_repeater, :is_chat, :is_room,
                :has_location, :latitude, :longitude, :battery_mv, :battery_v,
                :last_rssi, :last_snr, :flags_json::jsonb, :raw_advert_json::jsonb, :bot_id, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP,
                NULL, NULL, :initial_update
            )
            ON CONFLICT (public_key) DO UPDATE SET
                node_name = COALESCE(EXCLUDED.node_name, nodes.node_name),
                node_id = COALESCE(EXCLUDED.node_id, nodes.node_id),
                advert_format = COALESCE(EXCLUDED.advert_format, nodes.advert_format),
                advert_timestamp = COALESCE(EXCLUDED.advert_timestamp, nodes.advert_timestamp),
                signature = COALESCE(EXCLUDED.signature, nodes.signature),
                flags_raw = COALESCE(EXCLUDED.flags_raw, nodes.flags_raw),
                node_type = COALESCE(EXCLUDED.node_type, nodes.node_type),
                node_type_str = COALESCE(EXCLUDED.node_type_str, nodes.node_type_str),
                is_repeater = EXCLUDED.is_repeater,
                is_chat = EXCLUDED.is_chat,
                is_room = EXCLUDED.is_room,
                has_location = EXCLUDED.has_location,
                latitude = COALESCE(EXCLUDED.latitude, nodes.latitude),
                longitude = COALESCE(EXCLUDED.longitude, nodes.longitude),
                battery_mv = COALESCE(EXCLUDED.battery_mv, nodes.battery_mv),
                battery_v = COALESCE(EXCLUDED.battery_v, nodes.battery_v),
                last_rssi = COALESCE(EXCLUDED.last_rssi, nodes.last_rssi),
                last_snr = COALESCE(EXCLUDED.last_snr, nodes.last_snr),
                flags_json = COALESCE(EXCLUDED.flags_json, nodes.flags_json),
                raw_advert_json = EXCLUDED.raw_advert_json,
                bot_id = COALESCE(EXCLUDED.bot_id, nodes.bot_id),
                last_seen_at = CURRENT_TIMESTAMP,
                "update" = CASE 
                    WHEN (nodes.is_repeater = false OR nodes.is_repeater IS NULL) AND EXCLUDED.is_repeater = true AND :auto_add_flag = 1 
                    THEN true 
                    ELSE nodes."update" 
                END
SQL;
        } else {
            $sql = <<<SQL
            INSERT INTO `nodes` (
                `public_key`, `node_name`, `node_id`, `advert_format`, `advert_timestamp`, `signature`,
                `flags_raw`, `node_type`, `node_type_str`, `is_repeater`, `is_chat`, `is_room`,
                `has_location`, `latitude`, `longitude`, `battery_mv`, `battery_v`,
                `last_rssi`, `last_snr`, `flags_json`, `raw_advert_json`, `bot_id`, `first_seen_at`, `last_seen_at`,
                `requested_date`, `telemetry_updated_at`, `update`
            ) VALUES (
                :public_key, :node_name, :node_id, :advert_format, :advert_timestamp, :signature,
                :flags_raw, :node_type, :node_type_str, :is_repeater, :is_chat, :is_room,
                :has_location, :latitude, :longitude, :battery_mv, :battery_v,
                :last_rssi, :last_snr, :flags_json, :raw_advert_json, :bot_id, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP,
                NULL, NULL, :initial_update
            )
            ON DUPLICATE KEY UPDATE
                `node_name` = COALESCE(VALUES(`node_name`), `node_name`),
                `node_id` = COALESCE(VALUES(`node_id`), `node_id`),
                `advert_format` = COALESCE(VALUES(`advert_format`), `advert_format`),
                `advert_timestamp` = COALESCE(VALUES(`advert_timestamp`), `advert_timestamp`),
                `signature` = COALESCE(VALUES(`signature`), `signature`),
                `flags_raw` = COALESCE(VALUES(`flags_raw`), `flags_raw`),
                `node_type` = COALESCE(VALUES(`node_type`), `node_type`),
                `node_type_str` = COALESCE(VALUES(`node_type_str`), `node_type_str`),
                `is_repeater` = VALUES(`is_repeater`),
                `is_chat` = VALUES(`is_chat`),
                `is_room` = VALUES(`is_room`),
                `has_location` = VALUES(`has_location`),
                `latitude` = COALESCE(VALUES(`latitude`), `latitude`),
                `longitude` = COALESCE(VALUES(`longitude`), `longitude`),
                `battery_mv` = COALESCE(VALUES(`battery_mv`), `battery_mv`),
                `battery_v` = COALESCE(VALUES(`battery_v`), `battery_v`),
                `last_rssi` = COALESCE(VALUES(`last_rssi`), `last_rssi`),
                `last_snr` = COALESCE(VALUES(`last_snr`), `last_snr`),
                `flags_json` = COALESCE(VALUES(`flags_json`), `flags_json`),
                `raw_advert_json` = VALUES(`raw_advert_json`),
                `bot_id` = COALESCE(VALUES(`bot_id`), `bot_id`),
                `last_seen_at` = CURRENT_TIMESTAMP,
                `update` = CASE 
                    WHEN (`is_repeater` = 0 OR `is_repeater` IS NULL) AND VALUES(`is_repeater`) = 1 AND :auto_add_flag = 1 
                    THEN 1 
                    ELSE `update` 
                END
SQL;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':public_key', $pubKey);
        $stmt->bindValue(':node_name', $nodeName);
        $stmt->bindValue(':node_id', $nodeId);
        $stmt->bindValue(':advert_format', $format);
        $stmt->bindValue(':advert_timestamp', $timestamp);
        $stmt->bindValue(':signature', $signature);
        $stmt->bindValue(':flags_raw', $flagsRaw);
        $stmt->bindValue(':node_type', $nodeType);
        $stmt->bindValue(':node_type_str', $nodeTypeStr);
        $stmt->bindValue(':is_repeater', $isRepeater ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':is_chat', $isChat ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':is_room', $isRoom ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':has_location', $hasLocation ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':latitude', $lat);
        $stmt->bindValue(':longitude', $lon);
        $stmt->bindValue(':battery_mv', $batteryMv);
        $stmt->bindValue(':battery_v', $batteryV);
        $stmt->bindValue(':last_rssi', $rssi);
        $stmt->bindValue(':last_snr', $snr);
        $stmt->bindValue(':flags_json', $flagsJson);
        $stmt->bindValue(':raw_advert_json', $rawAdvertJson);
        $stmt->bindValue(':bot_id', $botId);
        $stmt->bindValue(':initial_update', ($driver === 'pgsql') 
            ? ($initialUpdate ? 'true' : 'false') 
            : ($initialUpdate ? 1 : 0));
        $stmt->bindValue(':auto_add_flag', $autoAdd ? 1 : 0, PDO::PARAM_INT);

        return $stmt->execute();
    }

    /**
     * Atnaujina mazgo 'last_seen_at' ir telemetrijos/radijo/boto rodiklius 'nodes' lentelėje,
     * kai mazgas pasirodo tinkle (pvz. atėjo telemetrijos paketas, atsakymas į užklausą arba gautas paketas iš jo).
     *
     * 'telemetry_updated_at' atnaujinamas TIK TADA, kai gauta nauja telemetrija (baterija, temperatūra ar būsenos duomenys).
     *
     * @param string $identifier Mazgo viešasis raktas (64 baitai) arba trumpasis mazgo ID (4 baitai)
     * @param string|null $botId ESP32 boto identifikatorius (pvz. 'KLP')
     * @param float|null $batteryV Baterijos įtampa V
     * @param int|null $batteryMv Baterijos įtampa mV
     * @param float|null $rssi Signalo stiprumas dBm
     * @param float|null $snr Triukšmo santykis dB
     * @param bool $hasTelemetry Ar šiame pakete buvo telemetrijos rodiklių
     * @param float|null $temperatureC Jutiklio temperatūra °C
     * @return bool
     */
    public function touchNodeSeen(
        string $identifier, 
        ?string $botId = null, 
        ?float $batteryV = null, 
        ?int $batteryMv = null, 
        ?float $rssi = null, 
        ?float $snr = null,
        bool $hasTelemetry = false,
        ?float $temperatureC = null,
        ?string $lastPath = null
    ): bool
    {
        $id = trim($identifier);
        if (empty($id)) {
            return false;
        }

        $sets = ['last_seen_at = CURRENT_TIMESTAMP'];
        $params = [':id1' => $id, ':id2' => $id];

        if (!empty($botId)) {
            $sets[] = 'bot_id = :bot_id';
            $params[':bot_id'] = strtoupper(substr(trim($botId), 0, 16));
        }

        if ($lastPath !== null && trim($lastPath) !== '') {
            $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
            if ($driver === 'pgsql') {
                $sets[] = "last_path = CASE WHEN last_path = 'FLOOD' THEN 'FLOOD' ELSE :last_path END";
            } else {
                $sets[] = "last_path = IF(last_path = 'FLOOD', 'FLOOD', :last_path)";
            }
            $params[':last_path'] = strtoupper(trim($lastPath));
        }

        // 'telemetry_updated_at' atnaujinamas TIK TADA, kai gautas tikras TELEMETRY arba STATUS paketas (hasTelemetry === true)
        // Sėkmingai gavus telemetriją, nesėkmingų bandymų skaičius (telemetry_fail_count) nusinulina
        if ($hasTelemetry) {
            $sets[] = 'telemetry_updated_at = CURRENT_TIMESTAMP';
            $sets[] = 'telemetry_fail_count = 0';
        }

        if ($batteryV !== null && $batteryV >= 0.0 && $batteryV <= 15.0) {
            $sets[] = 'battery_v = :batt_v';
            $params[':batt_v'] = $batteryV;
        }

        if ($batteryMv !== null && $batteryMv >= 0 && $batteryMv <= 15000) {
            $sets[] = 'battery_mv = :batt_mv';
            $params[':batt_mv'] = $batteryMv;
        }

        if ($temperatureC !== null && $temperatureC >= -60.0 && $temperatureC <= 100.0) {
            $sets[] = 'temperature_c = :temp_c';
            $params[':temp_c'] = $temperatureC;
        }

        if ($rssi !== null) {
            $sets[] = 'last_rssi = :rssi';
            $params[':rssi'] = $rssi;
        }

        if ($snr !== null) {
            $sets[] = 'last_snr = :snr';
            $params[':snr'] = $snr;
        }

        $setString = implode(', ', $sets);
        $sql = "UPDATE nodes SET {$setString} WHERE LOWER(public_key) = LOWER(:id1) OR LOWER(node_id) = LOWER(:id2)";

        try {
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute($params);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Grąžina visų arba nurodyto tipo mazgų sąrašą iš 'nodes' lentelės.
     */
    public function getNodes(?string $type = null, int $limit = 100): array
    {
        $sql = "SELECT * FROM nodes";
        $params = [];

        if ($type === 'repeater') {
            $sql .= " WHERE is_repeater = 1 OR is_repeater = true";
        } elseif ($type === 'chat') {
            $sql .= " WHERE is_chat = 1 OR is_chat = true";
        } elseif ($type === 'room') {
            $sql .= " WHERE is_room = 1 OR is_room = true";
        }

        $sql .= " ORDER BY last_seen_at DESC LIMIT " . (int)$limit;
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Grąžina 'nodes' lentelės statistiką
     */
    public function getNodeStats(): array
    {
        try {
            $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
            $updateCond = ($driver === 'pgsql') ? '"update" = true' : '`update` = 1 OR `update` = true';
            $disabledCond = ($driver === 'pgsql') ? 'is_disabled = true' : '`is_disabled` = 1 OR `is_disabled` = true';
            $sql = <<<SQL
            SELECT 
                COUNT(*) AS total_nodes,
                SUM(CASE WHEN is_repeater = 1 OR is_repeater = true THEN 1 ELSE 0 END) AS repeaters_count,
                SUM(CASE WHEN is_chat = 1 OR is_chat = true THEN 1 ELSE 0 END) AS chat_nodes_count,
                SUM(CASE WHEN is_room = 1 OR is_room = true THEN 1 ELSE 0 END) AS rooms_count,
                SUM(CASE WHEN has_location = 1 OR has_location = true THEN 1 ELSE 0 END) AS nodes_with_location,
                SUM(CASE WHEN ({$updateCond}) AND NOT ({$disabledCond}) THEN 1 ELSE 0 END) AS monitored_repeaters_count,
                SUM(CASE WHEN {$disabledCond} THEN 1 ELSE 0 END) AS disabled_nodes_count
            FROM nodes
SQL;
            $stmt = $this->pdo->query($sql);
            return $stmt->fetch() ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Nuskenuoja jau esamus 'raw_packets' įrašus ir užpildo 'nodes' lentelę
     */
    public function backfillNodesFromRawPackets(MeshCoreDecoder $decoder): int
    {
        $stmt = $this->pdo->query("SELECT id, raw_hex, rssi, snr, decoded_json FROM {$this->table} ORDER BY id ASC");
        $count = 0;

        while ($row = $stmt->fetch()) {
            $advert = null;
            if (!empty($row['decoded_json'])) {
                $dec = json_decode($row['decoded_json'], true);
                if (!empty($dec['advert'])) {
                    $advert = $dec['advert'];
                }
            }

            if (!$advert && !empty($row['raw_hex'])) {
                $dec = $decoder->decodeRawPacket($row['raw_hex']);
                if (!empty($dec['advert'])) {
                    $advert = $dec['advert'];
                }
            }

            if ($advert && !empty($advert['public_key'])) {
                if ($this->upsertNodeFromAdvert($advert, $row)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Ištraukia telemetrijos rodiklius iš iškoduoto paketo ir įrašo į 'telemetry' lentelę.
     * Išsaugo tik svarbius rodiklius grafikams ir statistikai (be sunkių JSON).
     *
     * @param int $rawPacketId Nuoroda į raw_packets įrašo ID
     * @param array $decoded Iškoduoto paketo duomenys iš MeshCoreDecoder
     * @param array|null $record Originalus raw_packets įrašas iš DB
     * @return int|null Sukurto telemetrijos įrašo ID arba null
     */
    public function extractAndSaveTelemetry(int $rawPacketId, array $decoded, ?array $record = null): ?int
    {
        $nodeId = null;
        $publicKey = null;
        $type = null;

        $batteryMv = null;
        $batteryV = null;
        $temperatureC = null;
        $uptimeSeconds = null;
        $airTimeSeconds = null;
        $noiseFloor = null;
        $rssi = isset($record['rssi']) ? (float)$record['rssi'] : null;
        $snr = isset($record['snr']) ? (float)$record['snr'] : null;
        $packetsRecv = null;
        $packetsSent = null;
        $txQueueLen = null;
        $errorCount = null;
        $recordedAt = null;

        // 1. Tikriname ar yra REPEATER_STATS (Būsenos atsakas į REQ_TYPE_GET_STATUS)
        $parsed = $decoded['encryption']['parsed_data'] ?? ($decoded['repeater_stats'] ?? null);
        if (!empty($parsed['repeater_stats'])) {
            $stats = $parsed['repeater_stats'];
            $type = 'STATUS';
            $batteryMv = isset($stats['battery_mv']) ? (int)$stats['battery_mv'] : null;
            $batteryV = isset($stats['battery_volts']) ? (float)$stats['battery_volts'] : ($batteryMv !== null ? round($batteryMv / 1000.0, 2) : null);
            $uptimeSeconds = isset($stats['uptime_seconds']) ? (int)$stats['uptime_seconds'] : null;
            $airTimeSeconds = isset($stats['air_time_seconds']) ? (int)$stats['air_time_seconds'] : null;
            $noiseFloor = isset($stats['noise_floor_dbm']) ? (int)$stats['noise_floor_dbm'] : null;
            $rssi = isset($stats['last_rssi_dbm']) ? (float)$stats['last_rssi_dbm'] : $rssi;
            $snr = isset($stats['last_snr_db']) ? (float)$stats['last_snr_db'] : $snr;
            $packetsRecv = isset($stats['packets_received']) ? (int)$stats['packets_received'] : null;
            $packetsSent = isset($stats['packets_sent']) ? (int)$stats['packets_sent'] : null;
            $txQueueLen = isset($stats['tx_queue_len']) ? (int)$stats['tx_queue_len'] : null;
            $errorCount = isset($stats['error_events']) ? (int)$stats['error_events'] : null;
        }
        // 2. Tikriname ar yra CAYENNE_LPP_TELEMETRY (Telemetrijos jutikliai)
        elseif (isset($parsed['sensors']) || (isset($parsed['response_type']) && $parsed['response_type'] === 'CAYENNE_LPP_TELEMETRY')) {
            $type = 'TELEMETRY';
            if (!empty($parsed['sensors']) && is_array($parsed['sensors'])) {
                foreach ($parsed['sensors'] as $sensor) {
                    if (isset($sensor['temperature_c']) && $temperatureC === null) {
                        $temperatureC = (float)$sensor['temperature_c'];
                    }
                    if (isset($sensor['voltage_mv']) && $batteryMv === null) {
                        $batteryMv = (int)$sensor['voltage_mv'];
                        $batteryV = (float)($sensor['voltage_v'] ?? round($batteryMv / 1000.0, 2));
                    }
                }
            }
        }

        // Tik TELEMETRY ir STATUS laikomi telemetrija. ADVERT nėra telemetrija!
        if ($type === null || ($type !== 'STATUS' && $type !== 'TELEMETRY')) {
            return null;
        }

        // Mazgo tapatybės nustatymas
        if (empty($nodeId)) {
            if (!empty($record['sender_name'])) {
                $nodeId = $record['sender_name'];
            } elseif (!empty($record['sender_public_key'])) {
                $publicKey = strtolower($record['sender_public_key']);
                $nodeId = substr($publicKey, 0, 4);
            } elseif (!empty($record['target_public_key'])) {
                $publicKey = strtolower($record['target_public_key']);
                $nodeId = substr($publicKey, 0, 4);
            } elseif (!empty($record['target_name'])) {
                $nodeId = $record['target_name'];
            } else {
                $rawHex = $record['raw_hex'] ?? ($decoded['raw_hex'] ?? '');
                if (strlen($rawHex) >= 6) {
                    $nodeId = strtolower(substr($rawHex, 2, 4));
                } else {
                    $nodeId = 'node';
                }
            }
        }

        // Bandome surasti pilną public_key iš 'nodes' lentelės, jei žinomas tik trumpasis ID
        if (empty($publicKey) && !empty($nodeId) && strlen($nodeId) === 4) {
            try {
                $nStmt = $this->pdo->prepare("SELECT public_key FROM nodes WHERE node_id = :nid OR public_key LIKE :pref LIMIT 1");
                $nStmt->execute([':nid' => $nodeId, ':pref' => $nodeId . '%']);
                $nRow = $nStmt->fetch();
                if ($nRow) {
                    $publicKey = $nRow['public_key'];
                }
            } catch (\Throwable $ignored) {}
        }

        if (empty($recordedAt)) {
            $recordedAt = !empty($record['created_at']) ? $record['created_at'] : date('Y-m-d H:i:s');
        }

        $botId = !empty($record['bot_id']) 
            ? strtoupper(substr(trim((string)$record['bot_id']), 0, 16))
            : (!empty($record['payload_json']) && is_string($record['payload_json']) && ($pj = json_decode($record['payload_json'], true)) && !empty($pj['bot_id'])
                ? strtoupper(substr(trim((string)$pj['bot_id']), 0, 16))
                : 'KLP');

        $hasTelemetry = true;

        // Išskiriame grįžtamąjį kelią (reverse path):
        $incomingHops = $decoded['path']['hops'] ?? [];
        $pathHopCount = isset($decoded['path']['hop_count']) ? (int)$decoded['path']['hop_count'] : null;
        $returnPath = null;
        if ($pathHopCount === 0) {
            // Tiesioginis ryšys (DIRECT 0 šuolių) galimas TIK jei signalas stiprus (>= -95 dBm).
            // Priešingu atveju tai tolimas signalas, kurio ESP32 tiesiogiai nepasieks.
            if ($rssi !== null && $rssi >= -95.0) {
                $returnPath = 'DIRECT';
            }
        } elseif (!empty($incomingHops) && is_array($incomingHops)) {
            // Apverčiame šuolių tvarką: Target -> [H1, H2] -> Us  =>  Us -> [H2, H1] -> Target
            $computedPath = implode('', array_reverse($incomingHops));

            // Apsauga nuo asimetrinio 1 šuolio spąstų:
            // Jei gautas tik 1 šuolis, bet signalas silpnas (RSSI < -100 dBm),
            // tai yra asimetrinis tolimas ryšys, kurio mūsų ESP32 siųstuvas tiesiogiai nepasieks.
            // Tokiu atveju neperrašome esamo daugiapakopio kelio!
            if ($pathHopCount === 1 && $rssi !== null && $rssi < -100.0) {
                $returnPath = null;
            } else {
                $returnPath = $computedPath;
            }
        }

        // Atnaujiname mazgo 'last_seen_at' ir telemetrijos rodiklius 'nodes' lentelėje
        // Jei pakete buvo telemetrijos duomenų, atnaujiname ir 'telemetry_updated_at' bei išmoktą maršrutą
        if (!empty($publicKey)) {
            $this->touchNodeSeen($publicKey, $botId, $batteryV, $batteryMv, $rssi, $snr, $hasTelemetry, $temperatureC, $returnPath);
        } elseif (!empty($nodeId) && $nodeId !== 'node') {
            $this->touchNodeSeen($nodeId, $botId, $batteryV, $batteryMv, $rssi, $snr, $hasTelemetry, $temperatureC, $returnPath);
        }

        return $this->insertTelemetryRecord([
            'node_id'          => $nodeId,
            'public_key'       => $publicKey,
            'raw_packet_id'    => $rawPacketId,
            'type'             => $type,
            'bot_id'           => $botId,
            'battery_mv'       => $batteryMv,
            'battery_v'        => $batteryV,
            'temperature_c'    => $temperatureC,
            'rssi'             => $rssi,
            'snr'              => $snr,
            'noise_floor'      => $noiseFloor,
            'uptime_seconds'   => $uptimeSeconds,
            'air_time_seconds' => $airTimeSeconds,
            'packets_recv'     => $packetsRecv,
            'packets_sent'     => $packetsSent,
            'tx_queue_len'     => $txQueueLen,
            'error_count'      => $errorCount,
            'recorded_at'      => $recordedAt,
        ]);
    }

    /**
     * Įrašo telemetrijos eilutę į 'telemetry' lentelę.
     * Užkerta kelią dubliavimui pagal raw_packet_id.
     */
    public function insertTelemetryRecord(array $data): ?int
    {
        if (!empty($data['raw_packet_id'])) {
            $checkStmt = $this->pdo->prepare("SELECT id FROM telemetry WHERE raw_packet_id = :rpid LIMIT 1");
            $checkStmt->execute([':rpid' => $data['raw_packet_id']]);
            if ($existing = $checkStmt->fetch()) {
                return (int)$existing['id'];
            }
        }

        // -------------------------------------------------------------
        // 🛡️ Apsauga nuo telemetrijos dublikatų:
        // Jei tam pačiam mazgui (pagal public_key arba node_id) per pastarąsias 60s jau yra įrašyta
        // tokia pati telemetrija (identiška įtampa ir temperatūra), naujos eilutės nebekuriame!
        // -------------------------------------------------------------
        $nodeKey = !empty($data['public_key']) ? (string)$data['public_key'] : null;
        $nodeId  = !empty($data['node_id']) ? (string)$data['node_id'] : null;
        $type    = !empty($data['type']) ? (string)$data['type'] : 'STATUS';
        $bmv     = isset($data['battery_mv']) && $data['battery_mv'] !== null ? (int)$data['battery_mv'] : null;
        $temp    = isset($data['temperature_c']) && $data['temperature_c'] !== null ? (float)$data['temperature_c'] : null;

        if ($nodeKey !== null || ($nodeId !== null && $nodeId !== 'node')) {
            $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $timeCond = ($driver === 'pgsql')
                ? "recorded_at >= NOW() - INTERVAL '60 seconds'"
                : "recorded_at >= NOW() - INTERVAL 60 SECOND";

            $whereNode = [];
            $params = [':type' => $type];
            if ($nodeKey !== null) {
                $whereNode[] = "public_key = :pk";
                $params[':pk'] = $nodeKey;
            }
            if ($nodeId !== null && $nodeId !== 'node') {
                $whereNode[] = "node_id = :nid";
                $params[':nid'] = $nodeId;
            }

            if (!empty($whereNode)) {
                $nodeCond = '(' . implode(' OR ', $whereNode) . ')';
                $valConds = [];
                if ($bmv !== null) {
                    $valConds[] = "battery_mv = :bmv";
                    $params[':bmv'] = $bmv;
                }
                if ($temp !== null) {
                    $valConds[] = "ABS(COALESCE(temperature_c, 0) - :temp) < 0.1";
                    $params[':temp'] = $temp;
                }

                if (!empty($valConds)) {
                    $valSql = implode(' AND ', $valConds);
                    $dupSql = "SELECT id FROM telemetry 
                               WHERE {$nodeCond} 
                                 AND type = :type 
                                 AND {$valSql} 
                                 AND {$timeCond} 
                               ORDER BY id DESC LIMIT 1";
                    $dupStmt = $this->pdo->prepare($dupSql);
                    $dupStmt->execute($params);
                    $dupId = $dupStmt->fetchColumn();
                    if ($dupId) {
                        return (int)$dupId;
                    }
                }
            }
        }

        $botId = !empty($data['bot_id']) ? strtoupper(substr(trim((string)$data['bot_id']), 0, 16)) : 'KLP';

        $sql = <<<SQL
        INSERT INTO telemetry (
            node_id, public_key, raw_packet_id, type, bot_id,
            battery_mv, battery_v, temperature_c,
            rssi, snr, noise_floor,
            uptime_seconds, air_time_seconds,
            packets_recv, packets_sent, tx_queue_len, error_count,
            recorded_at
        ) VALUES (
            :node_id, :public_key, :raw_packet_id, :type, :bot_id,
            :battery_mv, :battery_v, :temperature_c,
            :rssi, :snr, :noise_floor,
            :uptime_seconds, :air_time_seconds,
            :packets_recv, :packets_sent, :tx_queue_len, :error_count,
            :recorded_at
        )
SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':node_id', $data['node_id']);
        $stmt->bindValue(':public_key', $data['public_key']);
        $stmt->bindValue(':raw_packet_id', $data['raw_packet_id']);
        $stmt->bindValue(':type', $data['type'] ?? 'STATUS');
        $stmt->bindValue(':bot_id', $botId);
        $stmt->bindValue(':battery_mv', $data['battery_mv']);
        $stmt->bindValue(':battery_v', $data['battery_v']);
        $stmt->bindValue(':temperature_c', $data['temperature_c']);
        $stmt->bindValue(':rssi', $data['rssi']);
        $stmt->bindValue(':snr', $data['snr']);
        $stmt->bindValue(':noise_floor', $data['noise_floor']);
        $stmt->bindValue(':uptime_seconds', $data['uptime_seconds']);
        $stmt->bindValue(':air_time_seconds', $data['air_time_seconds']);
        $stmt->bindValue(':packets_recv', $data['packets_recv']);
        $stmt->bindValue(':packets_sent', $data['packets_sent']);
        $stmt->bindValue(':tx_queue_len', $data['tx_queue_len']);
        $stmt->bindValue(':error_count', $data['error_count']);
        $stmt->bindValue(':recorded_at', $data['recorded_at'] ?? date('Y-m-d H:i:s'));

        $stmt->execute();
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Grąžina telemetrijos istoriją konkrečiam mazgui (grafikų braižymui).
     */
    public function getTelemetryHistory(string $nodeId, ?string $from = null, ?string $to = null, int $limit = 500): array
    {
        $sql = "SELECT * FROM telemetry WHERE (node_id = :nid OR public_key = :pk)";
        $params = [':nid' => $nodeId, ':pk' => $nodeId];

        if ($from !== null) {
            $sql .= " AND recorded_at >= :from";
            $params[':from'] = $from;
        }
        if ($to !== null) {
            $sql .= " AND recorded_at <= :to";
            $params[':to'] = $to;
        }

        $sql .= " ORDER BY recorded_at ASC LIMIT " . (int)$limit;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Grąžina 'telemetry' lentelės bendrą statistiką.
     */
    public function getTelemetryStats(): array
    {
        try {
            $sql = <<<SQL
            SELECT 
                COUNT(*) AS total_points,
                COUNT(DISTINCT node_id) AS monitored_nodes,
                MAX(recorded_at) AS last_telemetry_at
            FROM telemetry
SQL;
            $stmt = $this->pdo->query($sql);
            return $stmt->fetch() ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Nuskenuoja jau esamus 'raw_packets' įrašus ir užpildo 'telemetry' lentelę.
     */
    public function backfillTelemetryFromRawPackets(MeshCoreDecoder $decoder): int
    {
        $stmt = $this->pdo->query("SELECT * FROM {$this->table} ORDER BY id ASC");
        $count = 0;

        while ($row = $stmt->fetch()) {
            $id = (int)$row['id'];
            $decoded = null;

            if (!empty($row['decoded_json'])) {
                $decoded = json_decode($row['decoded_json'], true);
            }

            if (!$decoded && !empty($row['raw_hex'])) {
                $decoded = $decoder->decodeRawPacket($row['raw_hex'], $row['shared_secret_hex'] ?? null);
            }

            if ($decoded) {
                $savedId = $this->extractAndSaveTelemetry($id, $decoded, $row);
                if ($savedId !== null) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Gauna kartotuvus (arba visus mazgus) su naujausia telemetrija, optimizuota žemėlapio atvaizdavimui.
     * Rūšiuoja pagal telemetrijos atnaujinimo datą nuo naujausio.
     *
     * @param bool $repeatersOnly Jei true - atrenka tik kartotuvus (is_repeater = true)
     * @param int $limit Maksimalus įrašų skaičius
     * @return array Žemėlapiui paruoštas mazgų sąrašas
     */
    public function getRepeatersWithLatestTelemetry(bool $repeatersOnly = true, int $limit = 500): array
    {
        $where = [];
        if ($repeatersOnly) {
            $where[] = "(n.is_repeater = 1 OR n.is_repeater = true)";
        }
        // Išjungtų mazgų nerodome žemėlapyje
        $where[] = "(n.is_disabled = 0 OR n.is_disabled = false OR n.is_disabled IS NULL)";
        $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

        $sql = <<<SQL
        SELECT 
            n.node_id,
            n.node_name,
            n.public_key,
            n.bot_id,
            n.is_repeater,
            n.is_disabled,
            n.latitude,
            n.longitude,
            n.battery_v AS advert_battery_v,
            n.temperature_c AS node_temperature_c,
            n.first_seen_at,
            n.last_seen_at,
            n.telemetry_updated_at,
            n.telemetry_fail_count,
            (
                SELECT t.battery_v 
                FROM telemetry t 
                WHERE (t.node_id = n.node_id OR (n.public_key IS NOT NULL AND t.public_key = n.public_key)) 
                  AND t.type IN ('TELEMETRY', 'STATUS')
                  AND t.battery_v IS NOT NULL 
                  AND t.battery_v >= 0.0 AND t.battery_v <= 15.0
                ORDER BY t.recorded_at DESC, t.id DESC 
                LIMIT 1
            ) AS last_battery_v,
            (
                SELECT t.temperature_c 
                FROM telemetry t 
                WHERE (t.node_id = n.node_id OR (n.public_key IS NOT NULL AND t.public_key = n.public_key)) 
                  AND t.type IN ('TELEMETRY', 'STATUS')
                  AND t.temperature_c IS NOT NULL 
                ORDER BY t.recorded_at DESC, t.id DESC 
                LIMIT 1
            ) AS last_temperature_c,
            (
                SELECT t.recorded_at 
                FROM telemetry t 
                WHERE (t.node_id = n.node_id OR (n.public_key IS NOT NULL AND t.public_key = n.public_key)) 
                  AND t.type IN ('TELEMETRY', 'STATUS')
                ORDER BY t.recorded_at DESC, t.id DESC 
                LIMIT 1
            ) AS last_telemetry_at
        FROM nodes n
        {$whereClause}
        ORDER BY 
            CASE WHEN n.telemetry_updated_at IS NOT NULL THEN 0 ELSE 1 END,
            n.telemetry_updated_at DESC,
            n.last_seen_at DESC
        LIMIT {$limit}
SQL;

        try {
            $stmt = $this->pdo->query($sql);
            $rows = $stmt->fetchAll() ?: [];
        } catch (\Throwable $e) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            $lat = ($row['latitude'] !== null) ? (float)$row['latitude'] : null;
            $lon = ($row['longitude'] !== null) ? (float)$row['longitude'] : null;

            // Baterijos įkrova V: TIK iš 'telemetry' lentelės (TELEMETRY ir STATUS), advert nelaikomas telemetrija
            $batteryV = ($row['last_battery_v'] !== null) ? (float)$row['last_battery_v'] : null;

            // Temperatūra °C: TIK iš 'telemetry' lentelės (TELEMETRY ir STATUS), advert nelaikomas telemetrija
            $tempC = ($row['last_temperature_c'] !== null) ? (float)$row['last_temperature_c'] : null;

            // Telemetrijos atnaujinimo data: TIK iš tikros telemetrijos (last_telemetry_at arba išvalytas telemetry_updated_at)
            $telemUpdatedAt = $row['last_telemetry_at'] ?? $row['telemetry_updated_at'];

            $nodeId = !empty($row['node_id']) 
                ? (string)$row['node_id'] 
                : (!empty($row['public_key']) ? substr($row['public_key'], 0, 4) : 'node');

            // Tik žemėlapiui reikalingi duomenys (be slaptažodžių, vidinių vėliavėlių ar perteklinių struktūrų)
            $result[] = [
                'name'                 => !empty($row['node_name']) ? (string)$row['node_name'] : $nodeId,
                'node_id'              => $nodeId,
                'bot_id'               => !empty($row['bot_id']) ? (string)$row['bot_id'] : 'KLP',
                'is_repeater'          => (bool)$row['is_repeater'],
                'is_disabled'          => (bool)($row['is_disabled'] ?? false),
                'latitude'             => $lat,
                'longitude'            => $lon,
                'battery_v'            => $batteryV,
                'temperature_c'        => $tempC,
                'telemetry_updated_at' => $telemUpdatedAt,
                'telemetry_fail_count' => (int)($row['telemetry_fail_count'] ?? 0),
                'first_seen_at'        => $row['first_seen_at'] ?? null,
                'last_seen_at'         => $row['last_seen_at'],
            ];
        }

        // Rūšiuojame pagal telemetrijos atnaujinimo datą nuo naujausio (naujausia telemetrija viršuje)
        usort($result, function ($a, $b) {
            $timeA = !empty($a['telemetry_updated_at']) ? strtotime($a['telemetry_updated_at']) : 0;
            $timeB = !empty($b['telemetry_updated_at']) ? strtotime($b['telemetry_updated_at']) : 0;
            if ($timeA === $timeB) {
                $seenA = !empty($a['last_seen_at']) ? strtotime($a['last_seen_at']) : 0;
                $seenB = !empty($b['last_seen_at']) ? strtotime($b['last_seen_at']) : 0;
                return $seenB <=> $seenA;
            }
            return $timeB <=> $timeA;
        });

        // Pritaikome telemetrijos senaties filtrą, jei nustatytas admine
        return $this->filterNodesByTelemetryAgeSettings($result);
    }

    /**
     * Išvalo neteisingus ar iškreiptus telemetrijos įrašus iš 'telemetry' lentelės
     * (pvz. klaidingai iškoduotus paketus su 42V ar absurdiškais triukšmais).
     */
    public function cleanupInvalidTelemetry(): int
    {
        try {
            $sql = "DELETE FROM telemetry WHERE (battery_v IS NOT NULL AND (battery_v > 15.0 OR battery_v < 0.0)) OR (battery_mv IS NOT NULL AND (battery_mv > 15000 OR battery_mv < 0)) OR noise_floor < -160 OR rssi < -160 OR snr > 50 OR snr < -50";
            return (int)$this->pdo->exec($sql);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    // =========================================================================
    // ⚙️ SISTEMOS NUSTATYMAI IR GEOLOKACIJOS FILTRACIJA (LIETUVOS RIBOS)
    // =========================================================================

    /**
     * Numatytieji sistemos ir žemėlapio nustatymai
     */
    public const DEFAULT_SYSTEM_SETTINGS = [
        'enable_source_routing'              => true,
        'filter_lithuania_enabled'           => false,
        'filter_lithuania_include_no_coords' => false,
        'lithuania_lat_min'                  => 53.85,
        'lithuania_lat_max'                  => 56.45,
        'lithuania_lon_min'                  => 20.90,
        'lithuania_lon_max'                  => 26.85,
        'max_telemetry_fails'                => 20,
        'auto_add_new_repeaters'             => false,
        'min_telemetry_interval_mins'        => 60,
        'min_retry_interval_mins'            => 15,
        'telemetry_chart_days'               => 7,
        'max_telemetry_age_days'             => 0,
    ];

    /**
     * Grąžina visus sistemos nustatymus iš DB arba atsarginio failo
     */
    public function getAllSettings(): array
    {
        $settings = self::DEFAULT_SYSTEM_SETTINGS;

        // 1. Nuskaitome iš DB 'system_settings' lentelės
        try {
            $stmt = $this->pdo->query("SELECT setting_key, setting_value FROM system_settings");
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $k = $row['setting_key'];
                $v = $row['setting_value'];
                if ($v === 'true' || $v === '1') $val = true;
                elseif ($v === 'false' || $v === '0') $val = false;
                elseif (is_numeric($v)) $val = (float)$v;
                else $val = $v;
                $settings[$k] = $val;
            }
        } catch (\Throwable $e) {
            // 2. Jei DB klaida, skaitome iš settings.json failo
            $file = __DIR__ . '/../settings.json';
            if (file_exists($file)) {
                $fileSettings = json_decode(file_get_contents($file), true) ?: [];
                $settings = array_merge($settings, $fileSettings);
            }
        }

        return $settings;
    }

    /**
     * Grąžina vieną nustatymo reikšmę
     */
    public function getSetting(string $key, $default = null)
    {
        $all = $this->getAllSettings();
        return $all[$key] ?? $default;
    }

    /**
     * Išsaugo nustatymus į DB ir settings.json failą
     */
    public function saveSettings(array $newSettings): array
    {
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $current = $this->getAllSettings();
        $updated = array_merge($current, $newSettings);

        foreach ($newSettings as $k => $v) {
            $valStr = is_bool($v) ? ($v ? '1' : '0') : (string)$v;
            try {
                if ($driver === 'pgsql') {
                    $sql = "INSERT INTO system_settings (setting_key, setting_value, updated_at)
                            VALUES (:key, :val, CURRENT_TIMESTAMP)
                            ON CONFLICT (setting_key) 
                            DO UPDATE SET setting_value = EXCLUDED.setting_value, updated_at = CURRENT_TIMESTAMP";
                } else {
                    $sql = "INSERT INTO `system_settings` (`setting_key`, `setting_value`, `updated_at`)
                            VALUES (:key, :val, CURRENT_TIMESTAMP)
                            ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`), `updated_at` = CURRENT_TIMESTAMP";
                }
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([':key' => $k, ':val' => $valStr]);
            } catch (\Throwable $e) {}
        }

        // Taip pat išsaugome į atsarginį failą www/settings.json
        try {
            $file = __DIR__ . '/../settings.json';
            file_put_contents($file, json_encode($updated, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {}

        return $updated;
    }

    /**
     * Patikrina ar geografinis taškas patenka į nustatytas Lietuvos ribas
     */
    public function isPointInLithuania(?float $lat, ?float $lon, ?array $settings = null): bool
    {
        if ($lat === null || $lon === null) {
            return false;
        }

        // (0, 0) koordinatės nėra reali geografinė vieta
        if (abs($lat) < 0.001 && abs($lon) < 0.001) {
            return false;
        }

        $cfg = $settings ?: $this->getAllSettings();
        $latMin = (float)($cfg['lithuania_lat_min'] ?? self::DEFAULT_SYSTEM_SETTINGS['lithuania_lat_min']);
        $latMax = (float)($cfg['lithuania_lat_max'] ?? self::DEFAULT_SYSTEM_SETTINGS['lithuania_lat_max']);
        $lonMin = (float)($cfg['lithuania_lon_min'] ?? self::DEFAULT_SYSTEM_SETTINGS['lithuania_lon_min']);
        $lonMax = (float)($cfg['lithuania_lon_max'] ?? self::DEFAULT_SYSTEM_SETTINGS['lithuania_lon_max']);

        return ($lat >= $latMin && $lat <= $latMax && $lon >= $lonMin && $lon <= $lonMax);
    }

    /**
     * Papildomas sluoksnis: filtruoja mazgų sąrašą pagal Lietuvos geolokaciją prieš formuojant JSON
     *
     * @param array $nodes Mazgų masyvas
     * @return array Atfiltruotas mazgų masyvas
     */
    public function filterNodesByGeolocationSettings(array $nodes): array
    {
        $settings = $this->getAllSettings();

        // Jei filtras išjungtas admine – grąžiname visus mazgus nepakeistus
        if (empty($settings['filter_lithuania_enabled'])) {
            return $nodes;
        }

        $includeNoCoords = !empty($settings['filter_lithuania_include_no_coords']);

        $filtered = [];
        foreach ($nodes as $node) {
            $lat = isset($node['latitude']) && $node['latitude'] !== null ? (float)$node['latitude'] : null;
            $lon = isset($node['longitude']) && $node['longitude'] !== null ? (float)$node['longitude'] : null;

            if ($lat === null || $lon === null || (abs($lat) < 0.001 && abs($lon) < 0.001)) {
                // Mazgas be koordinačių
                if ($includeNoCoords) {
                    $filtered[] = $node;
                }
                continue;
            }

            if ($this->isPointInLithuania($lat, $lon, $settings)) {
                $filtered[] = $node;
            }
        }

        return array_values($filtered);
    }

    /**
     * Filtruoja mazgus pagal telemetrijos senaties limitą dienomis.
     * Jei nurodytas dienų skaičius > 0 ir mazgo telemetrija nebuvo atnaujinta ilgiau nei šis limitas,
     * mazgas pašalinamas iš žemėlapio ir viešo sąrašo.
     *
     * @param array $nodes Mazgų masyvas
     * @param array|null $settings Nustatymai (jei null, nuskaitomi automatiškai)
     * @return array Atfiltruotas mazgų masyvas
     */
    public function filterNodesByTelemetryAgeSettings(array $nodes, ?array $settings = null): array
    {
        $settings = $settings ?: $this->getAllSettings();
        $maxDays = isset($settings['max_telemetry_age_days']) ? (int)$settings['max_telemetry_age_days'] : 0;

        // Jei limitas 0 arba neigiamas – filtras išjungtas, rodomi visi mazgai
        if ($maxDays <= 0) {
            return $nodes;
        }

        $cutoffTimestamp = time() - ($maxDays * 86400);
        $filtered = [];

        foreach ($nodes as $node) {
            $telemTimeStr = $node['telemetry_updated_at'] ?? null;
            if (!empty($telemTimeStr)) {
                $telemTimestamp = strtotime($telemTimeStr);
                if ($telemTimestamp && $telemTimestamp < $cutoffTimestamp) {
                    // Telemetrija neatnaujinta ilgiau nei nurodytas dienų skaičius
                    continue;
                }
            } else {
                // Mazgas apskritai neturi telemetrijos.
                // Tikriname kada mazgas pirmą kartą atsirado tinkle (first_seen_at)
                $firstSeenStr = $node['first_seen_at'] ?? null;
                $firstSeenTimestamp = !empty($firstSeenStr) ? strtotime($firstSeenStr) : 0;
                if ($firstSeenTimestamp > 0 && $firstSeenTimestamp < $cutoffTimestamp) {
                    continue;
                } elseif ($firstSeenTimestamp === 0) {
                    continue;
                }
            }

            $filtered[] = $node;
        }

        return array_values($filtered);
    }

    /**
     * Grąžina geolokacijos filtro statistiką administratoriaus pultui
     */
    public function getGeolocationFilterStats(): array
    {
        $settings = $this->getAllSettings();
        $total = 0;
        $inLt = 0;
        $outsideLt = 0;
        $noCoords = 0;

        try {
            $stmt = $this->pdo->query("SELECT latitude, longitude FROM nodes");
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $total++;
                $lat = $row['latitude'] !== null ? (float)$row['latitude'] : null;
                $lon = $row['longitude'] !== null ? (float)$row['longitude'] : null;

                if ($lat === null || $lon === null || (abs($lat) < 0.001 && abs($lon) < 0.001)) {
                    $noCoords++;
                } elseif ($this->isPointInLithuania($lat, $lon, $settings)) {
                    $inLt++;
                } else {
                    $outsideLt++;
                }
            }
        } catch (\Throwable $e) {}

        return [
            'settings'         => $settings,
            'total_nodes'      => $total,
            'nodes_in_lt'      => $inLt,
            'nodes_outside_lt' => $outsideLt,
            'nodes_no_coords'  => $noCoords,
        ];
    }

    /**
     * Nustato arba atnaujina mazgo slaptažodį ir 'update' būseną 'nodes' lentelėje.
     *
     * @param string $publicKeyOrNodeId Mazgo viešasis raktas (64 hex) arba trumpasis node_id (pvz. b9dd)
     * @param string|null $password Slaptažodis (arba null, jei nekeičiamas)
     * @param bool|null $update Ar įtraukti į stebimus/atnaujinamus kartotuvus (arba null, jei nekeičiamas)
     * @return bool Ar pavyko atnaujinti bent vieną įrašą
     */
    public function setNodeMonitoring(string $publicKeyOrNodeId, ?string $password = null, ?bool $update = null): bool
    {
        if ($password === null && $update === null) {
            return false;
        }

        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $colUpdate = ($driver === 'pgsql') ? '"update"' : '`update`';
        $colPass = ($driver === 'pgsql') ? 'password' : '`password`';

        $sets = [];
        $params = [];

        if ($password !== null) {
            $sets[] = "{$colPass} = :password";
            $params[':password'] = trim($password);
        }

        if ($update !== null) {
            $sets[] = "{$colUpdate} = :update";
            $params[':update'] = ($driver === 'pgsql') ? ($update ? 'true' : 'false') : ($update ? 1 : 0);
        }

        $setString = implode(', ', $sets);
        $cleanId = trim($publicKeyOrNodeId);

        $sql = "UPDATE nodes SET {$setString} WHERE LOWER(public_key) = LOWER(:id1) OR LOWER(node_id) = LOWER(:id2)";
        $params[':id1'] = $cleanId;
        $params[':id2'] = $cleanId;

        try {
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute($params);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Grąžina stebimų kartotuvų sąrašą tiksliai ESP32 /api/repyters formatu:
     * - Slaptažodis imamas tiesiai iš DB 'password' laukelio (jei NULL arba tuščias, grąžinama "")
     * - Įtraukiami TIK tie mazgai, kurių `update` yra true (1)
     *
     * [
     *   {
     *     "name": "b9dd",
     *     "public_id": "b9ddd788b7a59eab40eaa49a2534fa9e7fc0ef7d920ef4811139998cddd631db",
     *     "password": "",
     *     "isDirect": false,
     *     "status": true,
     *     "telemetry": true
     *   },
     *   ...
     * ]
     *
     * @return array
     */
    public function getMonitoredRepeaters(?string $botId = null): array
    {
        // Be botId jokie kartotuvai neatiduodami
        if (empty($botId)) {
            return [];
        }

        $repeaters = [];
        $knownKeys = [];
        $driver = $this->pdo ? $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) : null;
        $dbSuccess = false;

        // 1. Pirmiausia skaitome iš 'nodes' DB lentelės mazgus, kuriems update = true / 1
        // ir kurių nesėkmingų bandymų skaičius neviršija max_telemetry_fails nustatymo
        if ($this->pdo) {
            try {
                $maxFails = (int)$this->getSetting('max_telemetry_fails', 20);
                $failFilterPgsql = ($maxFails > 0) ? " AND (COALESCE(telemetry_fail_count, 0) < {$maxFails})" : "";
                $failFilterMysql = ($maxFails > 0) ? " AND (COALESCE(`telemetry_fail_count`, 0) < {$maxFails})" : "";

                $sourceRoutingEnabled = (bool)$this->getSetting('enable_source_routing', true);
                $minIntervalMins = (int)$this->getSetting('min_telemetry_interval_mins', 60);
                $intervalFilterPgsql = "";
                $intervalFilterMysql = "";
                if ($minIntervalMins > 0) {
                    $intervalFilterPgsql = " AND (telemetry_updated_at IS NULL OR telemetry_updated_at <= (CURRENT_TIMESTAMP - INTERVAL '{$minIntervalMins} minutes'))";
                    $intervalFilterMysql = " AND (`telemetry_updated_at` IS NULL OR `telemetry_updated_at` <= DATE_SUB(NOW(), INTERVAL {$minIntervalMins} MINUTE))";
                }

                $botFilterPgsql = "";
                $botFilterMysql = "";
                $params = [];
                if (!empty($botId)) {
                    $cleanBotId = strtoupper(substr(trim((string)$botId), 0, 16));
                    $botFilterPgsql = " AND (bot_id = :bot_id)";
                    $botFilterMysql = " AND (`bot_id` = :bot_id)";
                    $params[':bot_id'] = $cleanBotId;
                }

                if ($driver === 'pgsql') {
                    $sql = "SELECT public_key, node_id, node_name, is_repeater, password, \"update\", bot_id, last_path 
                            FROM nodes 
                            WHERE \"update\" = true AND (is_disabled = false OR is_disabled IS NULL) {$failFilterPgsql} {$intervalFilterPgsql} {$botFilterPgsql}
                            ORDER BY last_seen_at DESC";
                } else {
                    $sql = "SELECT public_key, node_id, node_name, is_repeater, `password`, `update`, `bot_id`, `last_path` 
                            FROM `nodes` 
                            WHERE `update` = 1 AND (`is_disabled` = 0 OR `is_disabled` IS NULL) {$failFilterMysql} {$intervalFilterMysql} {$botFilterMysql}
                            ORDER BY last_seen_at DESC";
                }

                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
                while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    if (empty($row['public_key'])) {
                        continue;
                    }
                    $pubId = strtolower(trim($row['public_key']));
                    if (!isset($knownKeys[$pubId])) {
                        $knownKeys[$pubId] = true;
                        $shortName = !empty($row['node_id']) 
                            ? strtolower(trim($row['node_id'])) 
                            : substr($pubId, 0, 4);

                        $password = ($row['password'] !== null) 
                            ? trim((string)$row['password']) 
                            : '';

                        $rawPath = trim((string)($row['last_path'] ?? ''));
                        if (!$sourceRoutingEnabled || strtoupper($rawPath) === 'FLOOD') {
                            $lastPath = 'FLOOD';
                            $isDirect = false;
                        } else {
                            $lastPath = $rawPath;
                            $isDirect = ($lastPath === 'DIRECT');
                        }

                        $repeaters[] = [
                            'name'      => $shortName,
                            'public_id' => $pubId,
                            'password'  => $password,
                            'path'      => $lastPath,
                            'isDirect'  => $isDirect,
                            'status'    => false,
                            'telemetry' => true,
                            'bot_id'    => $row['bot_id'] ?? null,
                        ];
                    }
                }
                $dbSuccess = true;
            } catch (\Throwable $e) {
                // Jei lentelė arba stulpelis dar neegzistuoja, tęsiame su fallback
                $dbSuccess = false;
            }
        }

        // Jei DB užklausa pavyko ir buvo rasta bent viena eilutė, grąžiname tiesiai iš DB!
        if ($dbSuccess && !empty($repeaters)) {
            return $repeaters;
        }

        // Jei DB veikia, bet update=1 mazgų nerasta:
        // Patikriname ar 'nodes' lentelėje išvis yra mazgų.
        // Jei yra mazgų, bet visi turi update=0, vartotojas tyčia atjungė arba dar neįjungė -> grąžiname tuščią sąrašą []
        // Tik jei DB visiškai tuščia (0 mazgų) arba DB nepasiekiama, naudojame fallback.
        $hasNodesInDb = false;
        if ($dbSuccess && $this->pdo) {
            try {
                $count = (int)$this->pdo->query("SELECT COUNT(*) FROM `nodes`")->fetchColumn();
                if ($count > 0) {
                    $hasNodesInDb = true;
                }
            } catch (\Throwable $e) {}
        }

        if ($hasNodesInDb && $dbSuccess) {
            return [];
        }

        // 2. Fallback: jei DB nepasiekiama arba visiškai tuščia, skaitome iš repeaters.json failo
        $filePaths = [
            __DIR__ . '/../repeaters.json',
            __DIR__ . '/../r.json',
            __DIR__ . '/../../XIAO_S3_Mesh_Bot/repeaters.json'
        ];

        foreach ($filePaths as $path) {
            if (file_exists($path)) {
                $content = @file_get_contents($path);
                if ($content) {
                    $json = @json_decode($content, true);
                    if (is_array($json)) {
                        $list = isset($json['repeaters']) && is_array($json['repeaters']) ? $json['repeaters'] : $json;
                        foreach ($list as $item) {
                            if (!empty($item['public_id']) || !empty($item['public_key'])) {
                                $pubId = strtolower(trim($item['public_id'] ?? $item['public_key']));
                                if (!isset($knownKeys[$pubId])) {
                                    $knownKeys[$pubId] = true;
                                    $repeaters[] = [
                                        'name'      => (string)($item['name'] ?? substr($pubId, 0, 4)),
                                        'public_id' => $pubId,
                                        'password'  => (isset($item['password']) && $item['password'] !== null) ? (string)$item['password'] : '',
                                        'isDirect'  => isset($item['isDirect']) ? (bool)$item['isDirect'] : false,
                                        'status'    => isset($item['status']) ? (bool)$item['status'] : true,
                                        'telemetry' => isset($item['telemetry']) ? (bool)$item['telemetry'] : true,
                                    ];
                                }
                            }
                        }
                        if (!empty($repeaters)) {
                            break;
                        }
                    }
                }
            }
        }

        // 3. Galutinis numatytasis fallback jei failo nėra
        if (empty($repeaters)) {
            $repeaters = [
                [
                    'name'      => 'b9dd',
                    'public_id' => 'b9ddd788b7a59eab40eaa49a2534fa9e7fc0ef7d920ef4811139998cddd631db',
                    'password'  => '',
                    'isDirect'  => false,
                    'status'    => false,
                    'telemetry' => true,
                ],
                [
                    'name'      => '1bbd',
                    'public_id' => '1bbd45c1ae2b2241ae997f0723066461bc8b0283abeee165d67ef9b35e9afb64',
                    'password'  => '',
                    'isDirect'  => false,
                    'status'    => false,
                    'telemetry' => true,
                ],
                [
                    'name'      => 'f9f8',
                    'public_id' => 'f9f86539d970c347818e4e6c8da7e9181b0fc502b9b3ee1a2f7b4358a352a38c',
                    'password'  => '',
                    'isDirect'  => false,
                    'status'    => false,
                    'telemetry' => true,
                ]
            ];
        }

        return $repeaters;
    }

    /**
     * Sinchronizuoja ir padidina nesėkmingų bandymų gauti telemetriją skaičių (telemetry_fail_count)
     * visiems mazgams, kuriems buvo išsiųsta užklausa prieš daugiau nei $timeoutSeconds sek.,
     * bet telemetrija nebuvo gauta ir šis bandymas dar nebuvo užskaitytas kaip nesėkmingas.
     */
    public function syncFailedTelemetryAttempts(int $timeoutSeconds = 180): int
    {
        if (!$this->pdo) {
            return 0;
        }

        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $timeout = (int)$timeoutSeconds;

        try {
            if ($driver === 'pgsql') {
                $sql = "UPDATE nodes
                SET 
                    telemetry_fail_count = telemetry_fail_count + 1,
                    last_failed_request_at = requested_date
                WHERE requested_date IS NOT NULL
                  AND requested_date <= (CURRENT_TIMESTAMP - INTERVAL '{$timeout} seconds')
                  AND (telemetry_updated_at IS NULL OR telemetry_updated_at < requested_date)
                  AND (last_failed_request_at IS NULL OR last_failed_request_at < requested_date)";
                return (int)$this->pdo->exec($sql);
            }

            // MySQL / SQLite
            $sql = "UPDATE `nodes`
            SET 
                `telemetry_fail_count` = `telemetry_fail_count` + 1,
                `last_failed_request_at` = `requested_date`
            WHERE `requested_date` IS NOT NULL
              AND `requested_date` <= DATE_SUB(NOW(), INTERVAL {$timeout} SECOND)
              AND (`telemetry_updated_at` IS NULL OR `telemetry_updated_at` < `requested_date`)
              AND (`last_failed_request_at` IS NULL OR `last_failed_request_at` < `requested_date`)";
            return (int)$this->pdo->exec($sql);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Grąžina sekantį 1 stebimą kartotuvą (update = true), kurį botas turi užklausti.
     * Atrankos taisyklė:
     * - Pirmenybė kartotuvams su tuščia 'requested_date' (pvz. ką tik gautas ADVERT arba niekada neužklaustas).
     * - Po to kartotuvams su seniausia 'requested_date' (Round-Robin eilė).
     *
     * Pasiėmus kartotuvą, jo 'requested_date' duomenų bazėje IŠKART atnaujinama į CURRENT_TIMESTAMP.
     *
     * @return array|null Kartotuvo konfigūracija arba null
     */
    public function getNextRepeaterToRequest(?string $botId = null): ?array
    {
        // Be botId jokie kartotuvai neatiduodami
        if (empty($botId)) {
            return null;
        }

        $driver = $this->pdo ? $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) : null;
        if (!$this->pdo) {
            return null;
        }

        // Prieš parenkant sekantį kartotuvą, atnaujiname nesėkmingų bandymų skaičius tiems, kurie jau patyrė timeout
        $this->syncFailedTelemetryAttempts(180);

        $maxFails = (int)$this->getSetting('max_telemetry_fails', 20);
        $failFilterPgsql = ($maxFails > 0) ? " AND (COALESCE(telemetry_fail_count, 0) < {$maxFails})" : "";
        $failFilterMysql = ($maxFails > 0) ? " AND (COALESCE(`telemetry_fail_count`, 0) < {$maxFails})" : "";

        // Minimalus telemetrijos atnaujinimo intervalas (minutėmis) (default 60 min)
        // Supaprastinta logika:
        // Imamas TIK mazgas, kuris turi būti stebimas (is_repeater=true, update=true)
        // IR kurio requested_date IS NULL (dar neužklaustas)
        // ARBA nuo requested_date jau praėjo nustatytas laiko tikrinimo intervalas.
        $minIntervalMins = (int)$this->getSetting('min_telemetry_interval_mins', 60);

        if ($minIntervalMins > 0) {
            $timingFilterPgsql = " AND (
                requested_date IS NULL 
                OR requested_date <= (CURRENT_TIMESTAMP - INTERVAL '{$minIntervalMins} minutes')
            )";

            $timingFilterMysql = " AND (
                `requested_date` IS NULL 
                OR `requested_date` <= DATE_SUB(NOW(), INTERVAL {$minIntervalMins} MINUTE)
            )";
        } else {
            // Jei nustatyta 0 min., laukiama tik kol baigsis einamasis laukimo langas eteryje (180 sek.)
            $timingFilterPgsql = " AND (
                requested_date IS NULL 
                OR requested_date <= (CURRENT_TIMESTAMP - INTERVAL '180 seconds')
            )";
            $timingFilterMysql = " AND (
                `requested_date` IS NULL 
                OR `requested_date` <= DATE_SUB(NOW(), INTERVAL 180 SECOND)
            )";
        }

        $botFilterPgsql = "";
        $botFilterMysql = "";
        $params = [];
        if (!empty($botId)) {
            $cleanBotId = strtoupper(substr(trim((string)$botId), 0, 16));
            $botFilterPgsql = " AND (nodes.bot_id = :bot_id)";
            $botFilterMysql = " AND (`nodes`.`bot_id` = :bot_id)";
            $params[':bot_id'] = $cleanBotId;
        }
        $sourceRoutingEnabled = (bool)$this->getSetting('enable_source_routing', true);

        if ($driver === 'pgsql') {
            $sql = <<<SQL
            WITH next_rep AS (
                SELECT id, public_key, node_id, node_name, password, requested_date, telemetry_updated_at, last_failed_request_at, bot_id, last_path
                FROM nodes
                WHERE (is_repeater = true) AND ("update" = true) AND (is_disabled = false OR is_disabled IS NULL) {$failFilterPgsql} {$timingFilterPgsql} {$botFilterPgsql}
                ORDER BY 
                    CASE WHEN requested_date IS NULL THEN 0 ELSE 1 END ASC,
                    requested_date ASC,
                    id ASC
                LIMIT 1
                FOR UPDATE SKIP LOCKED
            )
            UPDATE nodes
            SET 
                telemetry_fail_count = CASE 
                    WHEN next_rep.requested_date IS NOT NULL 
                         AND (next_rep.telemetry_updated_at IS NULL OR next_rep.telemetry_updated_at < next_rep.requested_date)
                         AND (next_rep.last_failed_request_at IS NULL OR next_rep.last_failed_request_at < next_rep.requested_date)
                    THEN nodes.telemetry_fail_count + 1 
                    ELSE nodes.telemetry_fail_count 
                END,
                last_failed_request_at = CASE 
                    WHEN next_rep.requested_date IS NOT NULL 
                         AND (next_rep.telemetry_updated_at IS NULL OR next_rep.telemetry_updated_at < next_rep.requested_date)
                         AND (next_rep.last_failed_request_at IS NULL OR next_rep.last_failed_request_at < next_rep.requested_date)
                    THEN next_rep.requested_date 
                    ELSE nodes.last_failed_request_at 
                END,
                requested_date = CURRENT_TIMESTAMP
            FROM next_rep
            WHERE nodes.id = next_rep.id
            RETURNING nodes.public_key, nodes.node_id, nodes.node_name, nodes.password, nodes.requested_date, nodes.telemetry_fail_count, nodes.bot_id, nodes.last_path;
SQL;
            try {
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                if ($row) {
                    $pubId = strtolower(trim($row['public_key']));
                    $shortName = !empty($row['node_id']) 
                        ? strtolower(trim($row['node_id'])) 
                        : substr($pubId, 0, 4);
                    $password = ($row['password'] !== null) 
                        ? trim((string)$row['password']) 
                        : '';
                    $rawPath = trim((string)($row['last_path'] ?? ''));
                    if (!$sourceRoutingEnabled || strtoupper($rawPath) === 'FLOOD') {
                        $lastPath = 'FLOOD';
                        $isDirect = false;
                    } else {
                        $lastPath = $rawPath;
                        $isDirect = ($lastPath === 'DIRECT');
                    }

                    return [
                        'name'                 => $shortName,
                        'public_id'            => $pubId,
                        'password'             => $password,
                        'path'                 => $lastPath,
                        'isDirect'             => $isDirect,
                        'status'               => false,
                        'telemetry'            => true,
                        'bot_id'               => $row['bot_id'] ?? null,
                        'requested_date'       => $row['requested_date'] ?? date('Y-m-d H:i:s'),
                        'telemetry_fail_count' => (int)($row['telemetry_fail_count'] ?? 0),
                    ];
                }
                return null;
            } catch (\Throwable $e) {
                return null;
            }
        }

        // MySQL / SQLite
        $this->pdo->beginTransaction();
        try {
            $selectSql = <<<SQL
            SELECT id, public_key, node_id, node_name, `password`, `requested_date`, `telemetry_updated_at`, `last_failed_request_at`, `telemetry_fail_count`, `bot_id`, `last_path`
            FROM `nodes`
            WHERE (`is_repeater` = 1 OR `is_repeater` = true)
              AND (`update` = 1 OR `update` = true)
              AND (`is_disabled` = 0 OR `is_disabled` IS NULL)
              {$failFilterMysql}
              {$timingFilterMysql}
              {$botFilterMysql}
            ORDER BY 
                CASE WHEN `requested_date` IS NULL THEN 0 ELSE 1 END ASC,
                `requested_date` ASC,
                `id` ASC
            LIMIT 1
            FOR UPDATE
SQL;
            $stmt = $this->pdo->prepare($selectSql);
            $stmt->execute($params);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($row) {
                $failCount = (int)($row['telemetry_fail_count'] ?? 0);
                $lastFailedAt = $row['last_failed_request_at'] ?? null;

                // Jei mazgas turėjo ankstesnę užklausą ir negavo telemetrijos, fiksuojame nesėkmingą bandymą
                if (!empty($row['requested_date'])) {
                    $rDate = $row['requested_date'];
                    $tDate = $row['telemetry_updated_at'] ?? null;
                    if (($tDate === null || $tDate < $rDate) && ($lastFailedAt === null || $lastFailedAt < $rDate)) {
                        $failCount++;
                        $lastFailedAt = $rDate;
                    }
                }

                $now = date('Y-m-d H:i:s');
                $updateSql = "UPDATE `nodes` 
                              SET `requested_date` = :now, 
                                  `telemetry_fail_count` = :fail_count, 
                                  `last_failed_request_at` = :last_failed 
                              WHERE `id` = :id";
                $uStmt = $this->pdo->prepare($updateSql);
                $uStmt->execute([
                    ':now'        => $now,
                    ':fail_count' => $failCount,
                    ':last_failed'=> $lastFailedAt,
                    ':id'         => $row['id']
                ]);
                $this->pdo->commit();

                $pubId = strtolower(trim($row['public_key']));
                $shortName = !empty($row['node_id']) 
                    ? strtolower(trim($row['node_id'])) 
                    : substr($pubId, 0, 4);
                $password = ($row['password'] !== null) 
                    ? trim((string)$row['password']) 
                    : '';
                $rawPath = trim((string)($row['last_path'] ?? ''));
                if (!$sourceRoutingEnabled || strtoupper($rawPath) === 'FLOOD') {
                    $lastPath = 'FLOOD';
                    $isDirect = false;
                } else {
                    $lastPath = $rawPath;
                    $isDirect = ($lastPath === 'DIRECT');
                }

                return [
                    'name'                 => $shortName,
                    'public_id'            => $pubId,
                    'password'             => $password,
                    'path'                 => $lastPath,
                    'isDirect'             => $isDirect,
                    'status'               => false,
                    'telemetry'            => true,
                    'bot_id'               => $row['bot_id'] ?? null,
                    'requested_date'       => $now,
                    'telemetry_fail_count' => $failCount,
                ];
            }

            $this->pdo->commit();
            return null;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return null;
        }
    }

    /**
     * Grąžina kartotuvų telemetrijos užklausų eilę (kokia seka jie bus užklausiami per /api/repeater).
     */
    public function getNodePollingQueue(?string $botId = null, bool $monitoredOnly = true, ?string $search = null): array
    {
        if (!$this->pdo) {
            return [];
        }

        // Prieš formuojant eilę, atnaujiname nesėkmingų bandymų skaičių mazgams, kurių užklausa patyrė timeout
        $this->syncFailedTelemetryAttempts(180);

        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $updateCol = ($driver === 'pgsql') ? '"update"' : '`update`';
        $repCol = ($driver === 'pgsql') ? 'is_repeater' : '`is_repeater`';

        $where = [];
        $params = [];

        // Neįtraukiame išjungtų mazgų į eilę
        $where[] = "(n.is_disabled = 0 OR n.is_disabled = false OR n.is_disabled IS NULL)";

        if ($monitoredOnly) {
            $where[] = "({$updateCol} = 1 OR {$updateCol} = true)";
        } else {
            $where[] = "({$repCol} = 1 OR {$repCol} = true)";
        }

        if (!empty($botId)) {
            $where[] = "n.bot_id = :bot_id";
            $params[':bot_id'] = $botId;
        }

        if (!empty($search)) {
            $s = '%' . strtolower(trim($search)) . '%';
            $where[] = "(LOWER(n.node_name) LIKE :search1 OR LOWER(n.node_id) LIKE :search2 OR LOWER(n.public_key) LIKE :search3)";
            $params[':search1'] = $s;
            $params[':search2'] = $s;
            $params[':search3'] = $s;
        }

        $whereSql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

        $sql = <<<SQL
        SELECT 
            n.id,
            n.node_id,
            n.node_name,
            n.public_key,
            n.bot_id,
            n.is_repeater,
            n.update,
            n.is_disabled,
            n.latitude,
            n.longitude,
            n.battery_v AS advert_battery_v,
            n.temperature_c AS node_temperature_c,
            n.last_seen_at,
            n.requested_date,
            n.telemetry_updated_at,
            n.telemetry_fail_count,
            n.last_path,
            (
                SELECT t.battery_v 
                FROM telemetry t 
                WHERE (t.node_id = n.node_id OR (n.public_key IS NOT NULL AND t.public_key = n.public_key)) 
                  AND t.type IN ('TELEMETRY', 'STATUS')
                  AND t.battery_v IS NOT NULL 
                  AND t.battery_v >= 0.0 AND t.battery_v <= 15.0
                ORDER BY t.recorded_at DESC, t.id DESC 
                LIMIT 1
            ) AS last_battery_v,
            (
                SELECT t.temperature_c 
                FROM telemetry t 
                WHERE (t.node_id = n.node_id OR (n.public_key IS NOT NULL AND t.public_key = n.public_key)) 
                  AND t.type IN ('TELEMETRY', 'STATUS')
                  AND t.temperature_c IS NOT NULL 
                ORDER BY t.recorded_at DESC, t.id DESC 
                LIMIT 1
            ) AS last_temperature_c,
            (
                SELECT t.recorded_at 
                FROM telemetry t 
                WHERE (t.node_id = n.node_id OR (n.public_key IS NOT NULL AND t.public_key = n.public_key)) 
                  AND t.type IN ('TELEMETRY', 'STATUS')
                ORDER BY t.recorded_at DESC, t.id DESC 
                LIMIT 1
            ) AS last_telemetry_at
        FROM nodes n
        {$whereSql}
        ORDER BY 
            CASE WHEN n.requested_date IS NULL THEN 0 ELSE 1 END ASC,
            n.requested_date ASC,
            n.id ASC
SQL;

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }

        $result = [];
        $queueIndex = 1;
        $now = time();

        $tz = new \DateTimeZone('Europe/Vilnius');
        $formatLt = function(?string $dateStr) use ($tz): ?string {
            if (!$dateStr) return null;
            try {
                $dt = new \DateTime($dateStr, $tz);
                $dt->setTimezone($tz);
                return $dt->format('Y-m-d H:i:s');
            } catch (\Throwable $e) {
                return $dateStr;
            }
        };

        $formatIsoLt = function(?string $dateStr) use ($tz): ?string {
            if (!$dateStr) return null;
            try {
                $dt = new \DateTime($dateStr, $tz);
                $dt->setTimezone($tz);
                return $dt->format(\DateTimeInterface::ATOM);
            } catch (\Throwable $e) {
                return $dateStr;
            }
        };

        $maxFails = (int)$this->getSetting('max_telemetry_fails', 20);

        foreach ($rows as $row) {
            $nodeId = !empty($row['node_id']) 
                ? (string)$row['node_id'] 
                : (!empty($row['public_key']) ? substr($row['public_key'], 0, 4) : 'node');
            $name = !empty($row['node_name']) ? (string)$row['node_name'] : $nodeId;
            $bot = !empty($row['bot_id']) ? (string)$row['bot_id'] : 'KLP';

            // Telemetrijos rodmenys TIK iš 'telemetry' lentelės (TELEMETRY ir STATUS), advert nelaikomas telemetrija
            $battV = ($row['last_battery_v'] !== null) ? (float)$row['last_battery_v'] : null;
            $tempC = ($row['last_temperature_c'] !== null) ? (float)$row['last_temperature_c'] : null;

            $rawReqDate = $row['requested_date'];
            // Telemetrijos data TIK iš tikros telemetrijos (TELEMETRY ir STATUS)
            $rawTelemDate = $row['last_telemetry_at'] ?? $row['telemetry_updated_at'];

            $hasPriorTelemetry = ($rawTelemDate !== null && trim((string)$rawTelemDate) !== '');

            $failCount = (int)($row['telemetry_fail_count'] ?? 0);
            $isBlocked = ($maxFails > 0 && $failCount >= $maxFails);

            // Paskutinio bandymo rezultato įvertinimas
            $attemptStatus = 'never';
            $attemptLabel = '🚀 Prioritetas (Laukia 1-os užklausos)';
            $attemptColor = 'green';

            if ($isBlocked) {
                $attemptStatus = 'blocked';
                $attemptLabel = "⛔ Sustabdytas (viršytas limitas: {$failCount}/{$maxFails})";
                $attemptColor = 'red';
            } elseif ($rawReqDate !== null && trim($rawReqDate) !== '') {
                $reqTime = strtotime($rawReqDate);
                $elapsedReqSec = max(0, $now - $reqTime);
                $telemTime = $rawTelemDate ? strtotime($rawTelemDate) : 0;

                // Jei telemetrijos įrašas buvo gautas po užklausos (arba per paskutines 30 sek. iki jos)
                if ($telemTime >= ($reqTime - 30)) {
                    $attemptStatus = 'success';
                    $attemptLabel = '✅ Telemetrija gauta';
                    $attemptColor = 'green';
                } else {
                    // Jei užklausa išsiųsta neseniai (iki 3 minučių) - vis dar laukiama paketo eteryje
                    if ($elapsedReqSec < 180) {
                        $attemptStatus = 'in_progress';
                        $attemptLabel = '⏳ Laukiama atsakymo...';
                        $attemptColor = 'blue';
                    } else {
                        // Praėjo daugiau nei 3 minutės ir atsakymas neatėjo
                        $attemptStatus = 'timeout';
                        $failSuffix = ($failCount > 1) ? " ({$failCount}x)" : '';
                        $attemptLabel = $hasPriorTelemetry 
                            ? "❌ Negauta (Timeout{$failSuffix}) - Yra ankstesnė telemetrija"
                            : "❌ Negauta (Timeout{$failSuffix}) - Nėra telemetrijos";
                        $attemptColor = 'red';
                    }
                }
            }

            $minIntervalMins = (int)$this->getSetting('min_telemetry_interval_mins', 60);
            $requiredSec = $minIntervalMins * 60;

            $elapsedReqSec = ($rawReqDate !== null && trim((string)$rawReqDate) !== '') 
                ? max(0, $now - strtotime($rawReqDate)) 
                : null;

            $isRateLimited = false;
            $rateLimitRemainingMins = 0;
            $isRetryCooldown = false;
            $retryRemainingMins = 0;

            if ($minIntervalMins > 0 && $elapsedReqSec !== null && $elapsedReqSec < $requiredSec) {
                $remainingMins = ceil(($requiredSec - $elapsedReqSec) / 60);
                if ($attemptStatus === 'success') {
                    $isRateLimited = true;
                    $rateLimitRemainingMins = $remainingMins;
                } elseif ($attemptStatus === 'timeout') {
                    $isRetryCooldown = true;
                    $retryRemainingMins = $remainingMins;
                }
            }

            // Mazgas gali patekti į eilę TIK jei:
            // 1. Nėra užblokuotas (viršytas fail limitas)
            // 2. Šiuo metu nevyksta užklausa eteryje (< 180s)
            // 3. requested_date IS NULL arba jau praėjo nustatytas intervalas (min_telemetry_interval_mins)
            $isInProgress = ($attemptStatus === 'in_progress');
            $hasWaitedInterval = ($elapsedReqSec === null || $elapsedReqSec >= $requiredSec);
            $isEligibleForNext = (!$isBlocked && !$isInProgress && $hasWaitedInterval);
            $queuePos = $isEligibleForNext ? $queueIndex++ : null;

            $result[] = [
                'queue_pos'                => $queuePos,
                'id'                       => (int)$row['id'],
                'node_id'                  => $nodeId,
                'name'                     => $name,
                'public_key'               => $row['public_key'],
                'bot_id'                   => $bot,
                'is_repeater'              => (bool)$row['is_repeater'],
                'update'                   => (bool)$row['update'],
                'is_disabled'              => (bool)($row['is_disabled'] ?? false),
                'requested_date'           => $formatLt($rawReqDate),
                'requested_date_iso'       => $formatIsoLt($rawReqDate),
                'telemetry_updated_at'     => $formatLt($rawTelemDate),
                'telemetry_updated_at_iso' => $formatIsoLt($rawTelemDate),
                'telemetry_fail_count'     => $failCount,
                'is_blocked'               => $isBlocked,
                'max_telemetry_fails'      => $maxFails,
                'is_rate_limited'          => $isRateLimited,
                'rate_limit_remaining_mins'=> $rateLimitRemainingMins,
                'min_interval_mins'        => $minIntervalMins,
                'is_retry_cooldown'        => $isRetryCooldown,
                'retry_remaining_mins'     => $retryRemainingMins,
                'min_retry_interval_mins'  => $minIntervalMins,
                'is_in_progress'           => $isInProgress,
                'elapsed_req_sec'          => $elapsedReqSec ?? null,
                'remaining_wait_sec'       => $isInProgress ? max(0, 180 - ($elapsedReqSec ?? 0)) : 0,
                'has_prior_telemetry'      => $hasPriorTelemetry,
                'last_seen_at'             => $formatLt($row['last_seen_at']),
                'last_seen_at_iso'         => $formatIsoLt($row['last_seen_at']),
                'battery_v'                => $battV,
                'temperature_c'            => $tempC,
                'attempt_status'           => $attemptStatus,
                'attempt_label'            => $attemptLabel,
                'attempt_color'            => $attemptColor,
                'last_path'                => $row['last_path'] ?? null,
                'path_display'             => empty($row['last_path']) 
                    ? 'FLOOD (visas tinklas)' 
                    : ($row['last_path'] === 'DIRECT' ? 'DIRECT (0 šuolių)' : 'DIRECT per [' . $row['last_path'] . ']'),
                'tz'                       => 'Europe/Vilnius',
            ];
        }

        return $result;
    }

    /**
     * Grąžina visus duomenų bazėje esančius unikalius bot_id šaltinius.
     */
    public function getAvailableBotSources(): array
    {
        if (!$this->pdo) {
            return [];
        }
        try {
            $stmt = $this->pdo->query("SELECT DISTINCT bot_id FROM nodes WHERE bot_id IS NOT NULL AND bot_id != '' ORDER BY bot_id ASC");
            return $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Patikrina ar duomenų bazėje išvis yra bent 1 mazgas
     */
    public function hasAnyNodes(): bool
    {
        if (!$this->pdo) {
            return false;
        }
        try {
            return ((int)$this->pdo->query("SELECT COUNT(*) FROM nodes")->fetchColumn()) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Nustato requested_date = NULL konkrečiam mazgui (nukreipia į eilės viršų).
     * Taip pat pagal nutylėjimą nuresetina nesėkmingų bandymų skaičių į 0.
     */
    public function resetNodeRequestedDate(string $identifier, bool $resetFailCount = true): bool
    {
        if (!$this->pdo) {
            return false;
        }
        try {
            $cleanId = trim($identifier);
            $failSql = $resetFailCount ? ", telemetry_fail_count = 0, last_failed_request_at = NULL" : "";
            $stmt = $this->pdo->prepare("UPDATE nodes SET requested_date = NULL{$failSql} WHERE LOWER(public_key) = LOWER(:id1) OR LOWER(node_id) = LOWER(:id2)");
            return $stmt->execute([':id1' => $cleanId, ':id2' => $cleanId]);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Nustato telemetry_fail_count = 0 ir išvalo last_failed_request_at konkrečiam mazgui.
     */
    public function resetNodeFailCount(string $identifier): bool
    {
        if (!$this->pdo) {
            return false;
        }
        try {
            $cleanId = trim($identifier);
            $stmt = $this->pdo->prepare("UPDATE nodes SET telemetry_fail_count = 0, last_failed_request_at = NULL WHERE LOWER(public_key) = LOWER(:id1) OR LOWER(node_id) = LOWER(:id2)");
            return $stmt->execute([':id1' => $cleanId, ':id2' => $cleanId]);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Nustato telemetry_fail_count = 0 ir last_failed_request_at = NULL visiems (arba nurodyto boto) mazgams.
     */
    public function resetAllFailCounts(?string $botId = null): int
    {
        if (!$this->pdo) {
            return 0;
        }
        try {
            $where = [];
            $params = [];
            if (!empty($botId)) {
                $where[] = "bot_id = :bot_id";
                $params[':bot_id'] = $botId;
            }
            $whereSql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
            $stmt = $this->pdo->prepare("UPDATE nodes SET telemetry_fail_count = 0, last_failed_request_at = NULL {$whereSql}");
            $stmt->execute($params);
            return $stmt->rowCount();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Nustato requested_date = NULL visiems arba nurodyto boto mazgams.
     * Taip pat gali nuresetinti ir nesėkmingų bandymų skaičių į 0.
     */
    public function resetAllRequestedDates(?string $botId = null, bool $monitoredOnly = true, bool $resetFailCount = false): int
    {
        if (!$this->pdo) {
            return 0;
        }
        try {
            $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
            $updateCol = ($driver === 'pgsql') ? '"update"' : '`update`';
            $repCol = ($driver === 'pgsql') ? 'is_repeater' : '`is_repeater`';

            $where = [];
            $params = [];

            if ($monitoredOnly) {
                $where[] = "({$updateCol} = 1 OR {$updateCol} = true)";
            } else {
                $where[] = "({$repCol} = 1 OR {$repCol} = true)";
            }

            if (!empty($botId)) {
                $where[] = "bot_id = :bot_id";
                $params[':bot_id'] = $botId;
            }

            $whereSql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
            $failSql = $resetFailCount ? ", telemetry_fail_count = 0, last_failed_request_at = NULL" : "";
            $stmt = $this->pdo->prepare("UPDATE nodes SET requested_date = NULL{$failSql} {$whereSql}");
            $stmt->execute($params);
            return $stmt->rowCount();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Pilnas mazgo nustatymų atnaujinimas administratoriui
     *
     * @param string $publicKeyOrNodeId
     * @param array $fields Galimi laukai: node_name, password, update, is_repeater, latitude, longitude
     * @return bool
     */
    public function updateNodeDetails(string $publicKeyOrNodeId, array $fields): bool
    {
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $sets = [];
        $params = [];

        if (array_key_exists('node_name', $fields)) {
            $sets[] = "node_name = :node_name";
            $params[':node_name'] = $fields['node_name'] !== null ? trim((string)$fields['node_name']) : null;
        }

        if (array_key_exists('password', $fields)) {
            $colPass = ($driver === 'pgsql') ? 'password' : '`password`';
            $sets[] = "{$colPass} = :password";
            $params[':password'] = $fields['password'] !== null ? trim((string)$fields['password']) : null;
        }

        if (array_key_exists('update', $fields)) {
            $colUpdate = ($driver === 'pgsql') ? '"update"' : '`update`';
            $sets[] = "{$colUpdate} = :update";
            $params[':update'] = ($driver === 'pgsql') 
                ? (!empty($fields['update']) ? 'true' : 'false') 
                : (!empty($fields['update']) ? 1 : 0);
        }

        if (array_key_exists('is_disabled', $fields)) {
            $colDisabled = ($driver === 'pgsql') ? 'is_disabled' : '`is_disabled`';
            $sets[] = "{$colDisabled} = :is_disabled";
            $params[':is_disabled'] = ($driver === 'pgsql') 
                ? (!empty($fields['is_disabled']) ? 'true' : 'false') 
                : (!empty($fields['is_disabled']) ? 1 : 0);
        }

        if (array_key_exists('is_repeater', $fields)) {
            $sets[] = "is_repeater = :is_repeater";
            $params[':is_repeater'] = ($driver === 'pgsql') 
                ? (!empty($fields['is_repeater']) ? 'true' : 'false') 
                : (!empty($fields['is_repeater']) ? 1 : 0);
        }

        if (array_key_exists('latitude', $fields)) {
            $sets[] = "latitude = :latitude";
            $params[':latitude'] = ($fields['latitude'] !== null && $fields['latitude'] !== '') ? (float)$fields['latitude'] : null;
        }

        if (array_key_exists('longitude', $fields)) {
            $sets[] = "longitude = :longitude";
            $params[':longitude'] = ($fields['longitude'] !== null && $fields['longitude'] !== '') ? (float)$fields['longitude'] : null;
        }

        if (array_key_exists('telemetry_fail_count', $fields)) {
            $sets[] = "telemetry_fail_count = :telemetry_fail_count";
            $cnt = max(0, (int)$fields['telemetry_fail_count']);
            $params[':telemetry_fail_count'] = $cnt;
            if ($cnt === 0) {
                $sets[] = "last_failed_request_at = NULL";
            }
        }

        if (array_key_exists('last_path', $fields)) {
            $sets[] = "last_path = :last_path";
            $params[':last_path'] = ($fields['last_path'] !== null && trim((string)$fields['last_path']) !== '') 
                ? strtoupper(trim((string)$fields['last_path'])) 
                : null;
        }

        if (empty($sets)) {
            return false;
        }

        $setString = implode(', ', $sets);
        $cleanId = trim($publicKeyOrNodeId);

        $sql = "UPDATE nodes SET {$setString} WHERE LOWER(public_key) = LOWER(:id1) OR LOWER(node_id) = LOWER(:id2)";
        $params[':id1'] = $cleanId;
        $params[':id2'] = $cleanId;

        try {
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute($params);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Grąžina filtruotus mazgus su puslapiavimu ir bendru skaičiumi
     */
    public function getFilteredNodes(array $filters = [], int $limit = 50, int $offset = 0, ?string $sortBy = null, ?string $sortDir = 'DESC'): array
    {
        // Atnaujiname nesėkmingų bandymų skaičių mazgams prieš formuojant sąrašą
        $this->syncFailedTelemetryAttempts(180);

        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $colUpdate = ($driver === 'pgsql') ? '"update"' : '`update`';

        $where = [];
        $params = [];

        if (!empty($filters['search'])) {
            $s = '%' . trim(strtolower($filters['search'])) . '%';
            $where[] = "(LOWER(node_name) LIKE :search1 OR LOWER(node_id) LIKE :search2 OR LOWER(public_key) LIKE :search3)";
            $params[':search1'] = $s;
            $params[':search2'] = $s;
            $params[':search3'] = $s;
        }

        if (!empty($filters['type'])) {
            if ($filters['type'] === 'repeater') {
                $where[] = "(is_repeater = 1 OR is_repeater = true)";
            } elseif ($filters['type'] === 'chat') {
                $where[] = "(is_chat = 1 OR is_chat = true)";
            } elseif ($filters['type'] === 'room') {
                $where[] = "(is_room = 1 OR is_room = true)";
            }
        }

        if (isset($filters['update']) && $filters['update'] !== '') {
            $upVal = ($driver === 'pgsql') 
                ? (!empty($filters['update']) ? 'true' : 'false') 
                : (!empty($filters['update']) ? 1 : 0);
            $where[] = "{$colUpdate} = :filter_update";
            $params[':filter_update'] = $upVal;
        }

        if (!empty($filters['status'])) {
            if ($filters['status'] === 'disabled') {
                $where[] = "(is_disabled = 1 OR is_disabled = true)";
            } elseif ($filters['status'] === 'active') {
                $where[] = "(is_disabled = 0 OR is_disabled = false OR is_disabled IS NULL)";
            }
        } elseif (isset($filters['is_disabled']) && $filters['is_disabled'] !== '') {
            if (!empty($filters['is_disabled'])) {
                $where[] = "(is_disabled = 1 OR is_disabled = true)";
            } else {
                $where[] = "(is_disabled = 0 OR is_disabled = false OR is_disabled IS NULL)";
            }
        }

        $whereSql = !empty($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

        // Gauname bendrą kiekį
        $countSql = "SELECT COUNT(*) FROM nodes {$whereSql}";
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($params);
        $totalCount = (int)$countStmt->fetchColumn();

        // Rūšiavimas pagal pasirinktą stulpelį
        $allowedCols = [
            'name'         => 'node_name',
            'node_name'    => 'node_name',
            'node_id'      => 'node_id',
            'type'         => 'is_repeater',
            'is_repeater'  => 'is_repeater',
            'bot_id'       => 'bot_id',
            'status'       => 'is_disabled',
            'is_disabled'  => 'is_disabled',
            'update'       => $colUpdate,
            'password'     => 'password',
            'coords'       => 'latitude',
            'latitude'     => 'latitude',
            'last_seen_at' => 'last_seen_at',
        ];
        $orderCol = $allowedCols[$sortBy ?? ''] ?? 'last_seen_at';
        $orderDir = (strtoupper((string)$sortDir) === 'ASC') ? 'ASC' : 'DESC';

        // Gauname įrašus
        $sql = "SELECT * FROM nodes {$whereSql} ORDER BY {$orderCol} {$orderDir}, id DESC LIMIT " . (int)$limit . " OFFSET " . (int)$offset;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $nodes = $stmt->fetchAll() ?: [];

        return [
            'total' => $totalCount,
            'limit' => $limit,
            'offset' => $offset,
            'nodes' => $nodes
        ];
    }

    /**
     * Grąžina filtruotus telemetrijos įrašus su puslapiavimu
     */
    public function getFilteredTelemetry(array $filters = [], int $limit = 50, int $offset = 0, ?string $sortBy = null, ?string $sortDir = 'DESC'): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['node_id'])) {
            $cleanNode = trim(strtolower($filters['node_id']));
            $where[] = "(LOWER(node_id) = :node_id1 OR LOWER(public_key) = :node_id2)";
            $params[':node_id1'] = $cleanNode;
            $params[':node_id2'] = $cleanNode;
        }

        if (!empty($filters['type'])) {
            $cleanType = strtoupper(trim($filters['type']));
            if ($cleanType === 'STATUS' || $cleanType === 'TELEMETRY') {
                $where[] = "type = :type";
                $params[':type'] = $cleanType;
            } else {
                $where[] = "type IN ('TELEMETRY', 'STATUS')";
            }
        } else {
            $where[] = "type IN ('TELEMETRY', 'STATUS')";
        }

        if (!empty($filters['from'])) {
            $where[] = "recorded_at >= :from_date";
            $params[':from_date'] = $filters['from'];
        }

        if (!empty($filters['to'])) {
            $where[] = "recorded_at <= :to_date";
            $params[':to_date'] = $filters['to'];
        }

        $whereSql = !empty($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

        // Bendras kiekis
        $countSql = "SELECT COUNT(*) FROM telemetry {$whereSql}";
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($params);
        $totalCount = (int)$countStmt->fetchColumn();

        // Rūšiavimas pagal pasirinktą stulpelį
        $allowedCols = [
            'id'             => 'id',
            'node_id'        => 'node_id',
            'type'           => 'type',
            'battery_v'      => 'battery_v',
            'temperature_c'  => 'temperature_c',
            'rssi'           => 'rssi',
            'snr'            => 'snr',
            'uptime'         => 'uptime_seconds',
            'uptime_seconds' => 'uptime_seconds',
            'packets'        => 'packets_recv',
            'packets_recv'   => 'packets_recv',
            'recorded_at'    => 'recorded_at',
        ];
        $orderCol = $allowedCols[$sortBy ?? ''] ?? 'recorded_at';
        $orderDir = (strtoupper((string)$sortDir) === 'ASC') ? 'ASC' : 'DESC';

        // Įrašai
        $sql = "SELECT * FROM telemetry {$whereSql} ORDER BY {$orderCol} {$orderDir}, id DESC LIMIT " . (int)$limit . " OFFSET " . (int)$offset;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $records = $stmt->fetchAll() ?: [];

        return [
            'total' => $totalCount,
            'limit' => $limit,
            'offset' => $offset,
            'records' => $records
        ];
    }

    /**
     * Grąžina konkretaus mazgo telemetrijos istoriją (temperatūrą ir baterijos įtampą/srovę) grafikui.
     * Laikotarpis nustatomas dienomis (jei nenurodyta, imamas iš sistemos nustatymo 'telemetry_chart_days', numatytai 7 d.).
     */
    public function getNodeTelemetryHistory(string $nodeIdentifier, ?int $days = null): array
    {
        if (!$this->pdo) {
            return ['days' => 7, 'records' => []];
        }

        $chartDays = ($days !== null && $days > 0) ? min(365, $days) : (int)$this->getSetting('telemetry_chart_days', 7);
        if ($chartDays <= 0) {
            $chartDays = 7;
        }

        $clean = trim(strtolower($nodeIdentifier));
        $resolvedNodeId = $clean;
        $resolvedPubKey = $clean;

        // Bandome išsiaiškinti pilną mazgo tapatybę iš 'nodes' lentelės
        try {
            $nStmt = $this->pdo->prepare("SELECT node_id, public_key, node_name FROM nodes WHERE LOWER(node_id) = :id1 OR LOWER(public_key) = :id2 OR LOWER(public_key) LIKE :id3 OR LOWER(node_name) = :id4 LIMIT 1");
            $nStmt->execute([
                ':id1' => $clean,
                ':id2' => $clean,
                ':id3' => $clean . '%',
                ':id4' => $clean,
            ]);
            $nodeRow = $nStmt->fetch(\PDO::FETCH_ASSOC);
            if ($nodeRow) {
                if (!empty($nodeRow['node_id'])) $resolvedNodeId = strtolower($nodeRow['node_id']);
                if (!empty($nodeRow['public_key'])) $resolvedPubKey = strtolower($nodeRow['public_key']);
            }
        } catch (\Throwable $e) {}

        $fromDate = date('Y-m-d H:i:s', strtotime("-{$chartDays} days"));

        $sql = "SELECT recorded_at, battery_v, battery_mv, temperature_c 
                FROM telemetry 
                WHERE (LOWER(node_id) = :nid OR LOWER(public_key) = :pkey OR LOWER(node_id) = :pkey_prefix)
                  AND recorded_at >= :from_date
                  AND (battery_v IS NOT NULL OR battery_mv IS NOT NULL OR temperature_c IS NOT NULL)
                ORDER BY recorded_at ASC 
                LIMIT 2000";

        $records = [];
        $tz = new \DateTimeZone('Europe/Vilnius');

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':nid'         => $resolvedNodeId,
                ':pkey'        => $resolvedPubKey,
                ':pkey_prefix' => substr($resolvedPubKey, 0, 4),
                ':from_date'   => $fromDate,
            ]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

            foreach ($rows as $r) {
                $rawDate = $r['recorded_at'];
                $timeFormatted = $rawDate;
                $timeShort = $rawDate;
                $iso = $rawDate;

                try {
                    $dt = new \DateTime($rawDate, $tz);
                    $dt->setTimezone($tz);
                    $timeFormatted = $dt->format('Y-m-d H:i');
                    $timeShort = $dt->format('m-d H:i');
                    $iso = $dt->format(\DateTimeInterface::ATOM);
                } catch (\Throwable $e) {}

                $battV = ($r['battery_v'] !== null) 
                    ? (float)$r['battery_v'] 
                    : (($r['battery_mv'] !== null) ? round(((int)$r['battery_mv']) / 1000.0, 2) : null);

                $tempC = ($r['temperature_c'] !== null) ? (float)$r['temperature_c'] : null;

                $records[] = [
                    'time'          => $timeFormatted,
                    'time_short'    => $timeShort,
                    'iso'           => $iso,
                    'battery_v'     => $battV,
                    'temperature_c' => $tempC,
                ];
            }
        } catch (\Throwable $e) {
            return ['days' => $chartDays, 'records' => [], 'error' => $e->getMessage()];
        }

        return [
            'days'    => $chartDays,
            'records' => $records
        ];
    }

    /**
     * Grąžina filtruotus raw_packets įrašus debugginimui
     */
    public function getFilteredRawPackets(array $filters = [], int $limit = 50, int $offset = 0, ?string $sortBy = null, ?string $sortDir = 'DESC'): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['node'])) {
            $n = '%' . trim(strtolower($filters['node'])) . '%';
            $where[] = "(LOWER(target_name) LIKE :node_search1 OR LOWER(target_public_key) LIKE :node_search2 OR LOWER(sender_name) LIKE :node_search3 OR LOWER(sender_public_key) LIKE :node_search4)";
            $params[':node_search1'] = $n;
            $params[':node_search2'] = $n;
            $params[':node_search3'] = $n;
            $params[':node_search4'] = $n;
        }

        if (!empty($filters['direction'])) {
            $where[] = "direction = :direction";
            $params[':direction'] = strtoupper(trim($filters['direction']));
        }

        if (isset($filters['status']) && $filters['status'] !== '') {
            if ($filters['status'] === 'errors') {
                $where[] = "(error_message IS NOT NULL AND error_message != '')";
            } elseif ($filters['status'] === 'pending') {
                $where[] = "(processed = 0 OR processed = false)";
            } elseif ($filters['status'] === 'processed') {
                $where[] = "(processed = 1 OR processed = true)";
            }
        }

        if (!empty($filters['search'])) {
            $s = '%' . trim(strtolower($filters['search'])) . '%';
            $where[] = "(LOWER(raw_hex) LIKE :text_search1 OR LOWER(target_name) LIKE :text_search2 OR LOWER(sender_name) LIKE :text_search3 OR LOWER(error_message) LIKE :text_search4)";
            $params[':text_search1'] = $s;
            $params[':text_search2'] = $s;
            $params[':text_search3'] = $s;
            $params[':text_search4'] = $s;
        }

        $whereSql = !empty($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

        // Bendras kiekis
        $countSql = "SELECT COUNT(*) FROM raw_packets {$whereSql}";
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($params);
        $totalCount = (int)$countStmt->fetchColumn();

        // Rūšiavimas pagal pasirinktą stulpelį
        $allowedCols = [
            'id'            => 'id',
            'bot_id'        => 'bot_id',
            'direction'     => 'direction',
            'sender'        => 'sender_name',
            'sender_name'   => 'sender_name',
            'target'        => 'target_name',
            'target_name'   => 'target_name',
            'length'        => 'length',
            'rssi'          => 'rssi',
            'snr'           => 'snr',
            'status'        => 'processed',
            'processed'     => 'processed',
            'error'         => 'error_message',
            'error_message' => 'error_message',
            'created_at'    => 'id',
        ];
        $orderCol = $allowedCols[$sortBy ?? ''] ?? 'id';
        $orderDir = (strtoupper((string)$sortDir) === 'ASC') ? 'ASC' : 'DESC';

        // Įrašai
        $sql = "SELECT id, bot_id, direction, length, rssi, snr, target_name, target_public_key, sender_name, sender_public_key, is_encrypted, processed, created_at, processed_at, completed_at, error_message, SUBSTRING(raw_hex, 1, 80) AS raw_hex_preview FROM raw_packets {$whereSql} ORDER BY {$orderCol} {$orderDir} LIMIT " . (int)$limit . " OFFSET " . (int)$offset;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $packets = $stmt->fetchAll() ?: [];

        return [
            'total' => $totalCount,
            'limit' => $limit,
            'offset' => $offset,
            'packets' => $packets
        ];
    }

    /**
     * Grąžina pilną vieno raw_packet įrašo informaciją peržiūrai
     */
    public function getRawPacketDetails(int $id): ?array
    {
        $table = $this->table;
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM {$table} WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch();
            if (!$row) return null;

            $row['telemetry_records'] = [];
            try {
                $telStmt = $this->pdo->prepare("SELECT * FROM telemetry WHERE raw_packet_id = :id");
                $telStmt->execute([':id' => $id]);
                $row['telemetry_records'] = $telStmt->fetchAll() ?: [];
            } catch (\Throwable $ignored) {
                // telemetry lentelė gali neturėti raw_packet_id stulpelio
            }

            return $row;
        } catch (\Throwable $e) {
            return null;
        }
    }
}

