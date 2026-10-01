-- =============================================================================
-- MySQL Duomenų Bazės Sukūrimo ir Migracijos Failas
-- Duomenų bazė: u236217820_telemerty
-- Lentelės: raw_packets, nodes, telemetry
-- =============================================================================

-- Pasirinktinai sukuriame duomenų bazę, jei jos dar nėra:
-- CREATE DATABASE IF NOT EXISTS `u236217820_telemerty` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- USE `u236217820_telemerty`;

-- -----------------------------------------------------------------------------
-- 1. LENTELĖ: raw_packets (Neapdorotas LoRa paketas ir asinchroninė eilė)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `raw_packets` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `direction`         VARCHAR(16) NULL DEFAULT NULL COMMENT 'Paketo kryptis: RX (gautas iš eterio) arba TX (išsiųstas)',
    `raw_hex`           LONGTEXT NOT NULL COMMENT 'Neapdorota heksadecimali paketo eilutė',
    `length`            INT UNSIGNED NULL DEFAULT NULL COMMENT 'Paketo ilgis baitais',
    `rssi`              DECIMAL(6, 2) NULL DEFAULT NULL COMMENT 'Gauto signalo lygis dBm (pvz. -85.50)',
    `snr`               DECIMAL(6, 2) NULL DEFAULT NULL COMMENT 'Signalo/triukšmo santykis dB (pvz. 9.75)',
    `target_name`       VARCHAR(128) NULL DEFAULT NULL COMMENT 'Taikinio kartotuvo vardas / prefiksas (pvz. b9dd)',
    `target_public_key` VARCHAR(128) NULL DEFAULT NULL COMMENT 'Taikinio kartotuvo viešasis raktas (64 hex simboliai)',
    `sender_name`       VARCHAR(128) NULL DEFAULT NULL COMMENT 'Siuntėjo mazgo vardas',
    `sender_public_key` VARCHAR(128) NULL DEFAULT NULL COMMENT 'Siuntėjo mazgo viešasis raktas (64 hex simboliai)',
    `my_public_key`     VARCHAR(128) NULL DEFAULT NULL COMMENT 'Mūsų mazgo viešasis raktas',
    `shared_secret_hex` VARCHAR(128) NULL DEFAULT NULL COMMENT '32 baitų Curve25519 bendras raktas dešifravimui',
    `is_encrypted`      TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Ar paketas yra šifruotas (0=ne, 1=taip)',
    `payload_json`      JSON NULL COMMENT 'Pilnas originalus HTTP POST JSON turinys',
    `decoded_json`      JSON NULL COMMENT 'Serveryje iškoduotas turinys (telemetrija, būsena, skelbimai)',
    `error_message`     TEXT NULL DEFAULT NULL COMMENT 'Klaidos pranešimas, jei iškoduoti nepavyko',
    
    -- Pagrindiniai eiliškumo ir būsenos laukai:
    `processed`         TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Apdorojimo būsena: 0=false (neapdorota), 1=true (apdorota)',
    `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Įrašo sukūrimo / gavimo data',
    `processed_at`      TIMESTAMP NULL DEFAULT NULL COMMENT 'Data, kada įrašas paimtas procesinimui',
    `completed_at`      TIMESTAMP NULL DEFAULT NULL COMMENT 'Data, kada procesinimas sėkmingai baigtas',

    -- Indeksai greitam filtravimui ir prioritetiniam eiliškumui:
    INDEX `idx_processed_queue` (`processed`, `processed_at`, `id`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_direction` (`direction`),
    INDEX `idx_target_name` (`target_name`),
    INDEX `idx_sender_public_key` (`sender_public_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='MeshCore LoRa neapdorotų paketų ir apdorojimo eilės lentelė';


-- -----------------------------------------------------------------------------
-- 2. LENTELĖ: nodes (MeshCore tinkle aptiktų mazgų tapatybės ir ADVERT duomenys)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nodes` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `public_key`        VARCHAR(64) NOT NULL UNIQUE COMMENT 'Ed25519 32 baitų viešasis raktas (64 hex simboliai)',
    `node_name`         VARCHAR(128) NULL DEFAULT NULL COMMENT 'Mazgo pavadinimas (pvz. LT-LM Tilžė B9DD)',
    `node_id`           VARCHAR(16) NULL DEFAULT NULL COMMENT 'Trumpasis mazgo ID (pvz. b9dd)',
    `advert_format`     VARCHAR(8) NULL DEFAULT NULL COMMENT 'Skelbimo formatas: A (su parašu, >=101B) arba B (be parašo)',
    `advert_timestamp`  BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'Mazgo paskutinio skelbimo laiko žyma (timestamp)',
    `signature`         VARCHAR(128) NULL DEFAULT NULL COMMENT 'Ed25519 parašas (64 baitai = 128 hex simboliai)',
    
    -- Vėliavėlės ir tipas:
    `flags_raw`         VARCHAR(10) NULL DEFAULT NULL COMMENT 'Vėliavėlių heksadecimali reikšmė (pvz. 0x92)',
    `node_type`         TINYINT UNSIGNED NULL DEFAULT NULL COMMENT 'Mazgo tipas: 0=generic, 1=chat, 2=repeater, 3=room',
    `node_type_str`     VARCHAR(32) NULL DEFAULT NULL COMMENT 'Mazgo tipo tekstinis pavadinimas (repeater, chat, room)',
    `is_repeater`       TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Ar mazgas yra kartotuvas',
    `is_chat`           TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Ar mazgas yra pokalbių klientas',
    `is_room`           TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Ar mazgas yra kambario serveris',
    `has_location`      TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Ar skelbime yra GPS koordinatės',
    
    -- Vietovės duomenys:
    `latitude`          DECIMAL(10, 7) NULL DEFAULT NULL COMMENT 'GPS Platuma (pvz. 55.705207)',
    `longitude`         DECIMAL(10, 7) NULL DEFAULT NULL COMMENT 'GPS Ilguma (pvz. 21.145874)',
    
    -- Telemetrija iš skelbimo:
    `battery_mv`        INT UNSIGNED NULL DEFAULT NULL COMMENT 'Baterijos įtampa milivoltais (mV)',
    `battery_v`         DECIMAL(4, 2) NULL DEFAULT NULL COMMENT 'Baterijos įtampa voltais (V)',
    
    -- Paskutinio radijo signalo metrika:
    `last_rssi`         DECIMAL(6, 2) NULL DEFAULT NULL COMMENT 'Paskutinio gauto paketo RSSI dBm',
    `last_snr`          DECIMAL(6, 2) NULL DEFAULT NULL COMMENT 'Paskutinio gauto paketo SNR dB',
    
    -- Neapdoroti JSON objektai analizei:
    `flags_json`        JSON NULL COMMENT 'Pilnas flags objektas',
    `raw_advert_json`   JSON NULL COMMENT 'Pilnas iškoduoto skelbimo (advert) JSON objektas',
    
    -- Laiko žymos:
    `first_seen_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Pirmą kartą užregistruotas tinkle',
    `last_seen_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Paskutinį kartą atnaujinta',

    -- Indeksai greitai paieškai:
    INDEX `idx_node_name` (`node_name`),
    INDEX `idx_node_id` (`node_id`),
    INDEX `idx_node_type` (`node_type`),
    INDEX `idx_is_repeater` (`is_repeater`),
    INDEX `idx_last_seen_at` (`last_seen_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='MeshCore tinklo mazgų (kartotuvų, klientų) duomenų lentelė';


-- -----------------------------------------------------------------------------
-- 3. LENTELĖ: telemetry (Kompaktiška laiko eilučių telemetrija ir būsena grafikams)
-- Tik svarbiausi rodikliai, optimizuota greitam agregavimui ir grafikų braižymui.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `telemetry` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `node_id`           VARCHAR(16) NOT NULL COMMENT 'Trumpasis mazgo ID (pvz. b9dd)',
    `public_key`        VARCHAR(64) NULL DEFAULT NULL COMMENT 'Mazgo 32 baitų viešasis raktas (64 hex)',
    `raw_packet_id`     BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'Nuoroda į raw_packets įrašą',
    `type`              VARCHAR(16) NOT NULL DEFAULT 'STATUS' COMMENT 'Šaltinis: STATUS, TELEMETRY, ADVERT',
    
    -- Telemetrijos ir jutiklių rodikliai:
    `battery_mv`        INT UNSIGNED NULL DEFAULT NULL COMMENT 'Baterijos įtampa milivoltais (mV)',
    `battery_v`         DECIMAL(4, 2) NULL DEFAULT NULL COMMENT 'Baterijos įtampa voltais (V)',
    `temperature_c`     DECIMAL(4, 1) NULL DEFAULT NULL COMMENT 'Temperatūra Celsijumi (°C)',
    
    -- Radijo signalo kokybė (grafikams):
    `rssi`              DECIMAL(6, 2) NULL DEFAULT NULL COMMENT 'Signalo stiprumas dBm (pvz. -88.5)',
    `snr`               DECIMAL(6, 2) NULL DEFAULT NULL COMMENT 'Signalo/triukšmo santykis dB (pvz. 9.75)',
    `noise_floor`       SMALLINT NULL DEFAULT NULL COMMENT 'Eterio triukšmo lygis dBm (pvz. -115)',
    
    -- Kartotuvo veikimo ir apkrovos statistika (grafikams):
    `uptime_seconds`    INT UNSIGNED NULL DEFAULT NULL COMMENT 'Veikimo laikas sekundėmis',
    `air_time_seconds`  INT UNSIGNED NULL DEFAULT NULL COMMENT 'Transliavimo eteryje laikas sekundėmis',
    `packets_recv`      INT UNSIGNED NULL DEFAULT NULL COMMENT 'Iš viso gautų paketų (RX)',
    `packets_sent`      INT UNSIGNED NULL DEFAULT NULL COMMENT 'Iš viso išsiųstų paketų (TX)',
    `tx_queue_len`      SMALLINT UNSIGNED NULL DEFAULT NULL COMMENT 'Siuntimo buferio/eilės ilgis',
    `error_count`       SMALLINT UNSIGNED NULL DEFAULT NULL COMMENT 'Užregistruotų klaidų skaičius',
    
    -- Laiko žyma (grafikų X ašis):
    `recorded_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Įrašo laiko žyma (grafikų X ašis)',
    
    -- Indeksai greitoms laiko intervalo ir mazgo užklausoms (pvz. paskutinės 24h):
    INDEX `idx_telemetry_node_time` (`node_id`, `recorded_at`),
    INDEX `idx_telemetry_pubkey_time` (`public_key`, `recorded_at`),
    INDEX `idx_telemetry_time` (`recorded_at`),
    INDEX `idx_telemetry_raw_packet` (`raw_packet_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Kompaktiška telemetrijos ir kartotuvų būsenos lentelė grafikams ir statistikai';
