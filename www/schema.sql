-- =============================================================================
-- MySQL Duomenų Bazės Sukūrimo ir Migracijos Failas
-- Duomenų bazė: u236217820_telemerty
-- Lentelė: raw_packets
-- =============================================================================

-- Pasirinktinai sukuriame duomenų bazę, jei jos dar nėra:
-- CREATE DATABASE IF NOT EXISTS `u236217820_telemerty` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- USE `u236217820_telemerty`;

DROP TABLE IF EXISTS `raw_packets`;

CREATE TABLE `raw_packets` (
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
    -- Užklausai: WHERE processed = 0 ORDER BY CASE WHEN processed_at IS NULL THEN 0 ELSE 1 END ASC, processed_at DESC, id ASC
    INDEX `idx_processed_queue` (`processed`, `processed_at`, `id`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_direction` (`direction`),
    INDEX `idx_target_name` (`target_name`),
    INDEX `idx_sender_public_key` (`sender_public_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='MeshCore LoRa neapdorotų paketų ir apdorojimo eilės lentelė';
