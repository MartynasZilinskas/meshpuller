<?php

namespace MeshCore\Database;

use PDO;

/**
 * Class SchemaManager
 *
 * Atsakinga už schemos (lentelių, indeksų, stulpelių migracijų) sukūrimą ir priežiūrą pagal SRP.
 */
class SchemaManager
{
    private PDO $pdo;
    private string $table;

    public function __construct(PDO $pdo, string $table = 'raw_packets')
    {
        $this->pdo   = $pdo;
        $this->table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    }

    /**
     * Sukuria lenteles ir būtinus indeksus, jei jie dar neegzistuoja
     */
    public function createTableIfNotExists(?callable $postMigrationCleanup = null): void
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        // 1. raw_packets lentelė
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

            CREATE INDEX IF NOT EXISTS idx_{$this->table}_queue_priority 
            ON {$this->table} (processed, (CASE WHEN processed_at IS NULL THEN 0 ELSE 1 END), processed_at DESC, id ASC)
            WHERE processed = false;

            CREATE INDEX IF NOT EXISTS idx_{$this->table}_created_at 
            ON {$this->table} (created_at DESC);

            CREATE INDEX IF NOT EXISTS idx_{$this->table}_bot_id 
            ON {$this->table} (bot_id);
SQL;
        } else {
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

        // 2. nodes lentelė
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

        // Migracijos 'nodes' lentelei
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

                $this->pdo->exec("DELETE FROM telemetry WHERE type = 'ADVERT' OR (type != 'TELEMETRY' AND type != 'STATUS')");
                
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

                try {
                    $this->pdo->exec("ALTER TABLE `nodes` MODIFY COLUMN `last_seen_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP");
                } catch (\Throwable $e) {}
            } catch (\Throwable $e) {}
        }

        // 3. telemetry lentelė
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

        if ($driver === 'pgsql') {
            try {
                $this->pdo->exec('ALTER TABLE telemetry ADD COLUMN IF NOT EXISTS bot_id VARCHAR(16) NULL DEFAULT \'KLP\'');
                $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_telemetry_bot_id ON telemetry (bot_id)');
                $this->pdo->exec('ALTER TABLE telemetry ADD COLUMN IF NOT EXISTS raw_packet_id BIGINT NULL');
                $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_telemetry_raw_packet ON telemetry (raw_packet_id)');
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

        // 4. system_settings lentelė
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

        if ($postMigrationCleanup !== null && is_callable($postMigrationCleanup)) {
            call_user_func($postMigrationCleanup);
        }
    }
}
