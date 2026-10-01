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
        } catch (PDOException $e) {
            // Jei bandyta pgsql ir nepavyko, bet serveryje yra mysql: automatinis bandymas jungtis per mysql
            if ($driver === 'pgsql' && in_array('mysql', $availableDrivers)) {
                try {
                    $mysqlPort = ($port === 5432) ? 3306 : $port;
                    $mysqlDsn = "mysql:host={$host};port={$mysqlPort};dbname={$dbname};charset=utf8mb4";
                    $this->pdo = new PDO($mysqlDsn, $user, $pass, $options);
                    return;
                } catch (\Throwable $ignored) {
                    // Ignoruojame ir metame pradinę klaidą
                }
            }
            throw new PDOException("Nepavyko prisijungti prie duomenų bazės [{$dbname} per {$driver}:{$port}]: " . $e->getMessage(), (int)$e->getCode(), $e);
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
SQL;
        } else {
            // MySQL / MariaDB / SQLite variantas
            $sql = <<<SQL
            CREATE TABLE IF NOT EXISTS {$this->table} (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                direction VARCHAR(16) NULL,
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
                INDEX idx_{$this->table}_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
        }

        $this->pdo->exec($sql);
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
        $payloadJson     = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        
        $sql = "INSERT INTO {$this->table} (
                    direction, raw_hex, length, rssi, snr,
                    target_name, target_public_key, sender_name, sender_public_key,
                    my_public_key, shared_secret_hex, is_encrypted, payload_json,
                    processed, created_at, processed_at
                ) VALUES (
                    :direction, :raw_hex, :length, :rssi, :snr,
                    :target_name, :target_public_key, :sender_name, :sender_public_key,
                    :my_public_key, :shared_secret_hex, :is_encrypted, :payload_json,
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
    public function completeProcessing(int $id, array $decodedData): bool
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $decodedJson = json_encode($decodedData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $sql = "UPDATE {$this->table}
                SET processed = :processed,
                    completed_at = CURRENT_TIMESTAMP,
                    decoded_json = :decoded_json,
                    error_message = NULL
                WHERE id = :id";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':decoded_json', $decodedJson);

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
     * @return bool
     */
    public function failProcessing(int $id, string $errorMessage): bool
    {
        $sql = "UPDATE {$this->table}
                SET error_message = :error_message
                WHERE id = :id";

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            ':id'            => $id,
            ':error_message' => $errorMessage,
        ]);
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
                $decoded = $decoder->decodeRawPacket($rawHex, $secret);
            }

            if (!empty($decoded['success'])) {
                $this->completeProcessing($id, $decoded);
            } else {
                $err = $decoded['error'] ?? 'Nepavyko iškoduoti paketo';
                $this->failProcessing($id, $err);
                // Išsaugome ir dalinius duomenis, jei pavyko perskaityti bent headerį
                $this->updateDecodedJsonWithoutCompleting($id, $decoded);
            }

            return $decoded;
        } catch (\Throwable $e) {
            $this->failProcessing($id, $e->getMessage());
            throw $e;
        }
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
}
