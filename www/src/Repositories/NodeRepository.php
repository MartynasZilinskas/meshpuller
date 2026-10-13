<?php

namespace MeshCore\Repositories;

use MeshCore\Contracts\BotRepositoryInterface;
use MeshCore\Contracts\NodeRepositoryInterface;
use MeshCore\Contracts\PollingQueueServiceInterface;
use MeshCore\Contracts\SettingsRepositoryInterface;
use MeshCore\MeshCoreDecoder;
use PDO;

/**
 * Class NodeRepository
 *
 * Atsakinga už mazgų (kartotuvų, naudotojų, kambarių) duomenų valdymą 'nodes' lentelėje pagal SRP.
 */
class NodeRepository implements NodeRepositoryInterface
{
    private PDO $pdo;
    private ?SettingsRepositoryInterface $settingsRepo;
    private ?PollingQueueServiceInterface $pollingQueueService;
    private ?BotRepositoryInterface $botRepo;
    private string $rawPacketsTable;

    public function __construct(
        PDO $pdo,
        ?SettingsRepositoryInterface $settingsRepo = null,
        ?PollingQueueServiceInterface $pollingQueueService = null,
        ?BotRepositoryInterface $botRepo = null,
        string $rawPacketsTable = 'raw_packets'
    ) {
        $this->pdo                 = $pdo;
        $this->settingsRepo        = $settingsRepo;
        $this->pollingQueueService = $pollingQueueService;
        $this->botRepo             = $botRepo;
        $this->rawPacketsTable     = preg_replace('/[^a-zA-Z0-9_]/', '', $rawPacketsTable);
    }

    public function setSettingsRepository(SettingsRepositoryInterface $settingsRepo): void
    {
        $this->settingsRepo = $settingsRepo;
    }

    public function setPollingQueueService(PollingQueueServiceInterface $pollingQueueService): void
    {
        $this->pollingQueueService = $pollingQueueService;
    }

    public function setBotRepository(BotRepositoryInterface $botRepo): void
    {
        $this->botRepo = $botRepo;
    }

    /**
     * Įrašo arba atnaujina mazgo duomenis 'nodes' lentelėje pagal gautą ADVERT paketą.
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

        $autoAdd = false;
        if ($this->settingsRepo !== null) {
            $autoAdd = (bool)$this->settingsRepo->getSetting('auto_add_new_repeaters', false);
        }
        $initialUpdate = ($isRepeater && $autoAdd);

        $lat = isset($advert['location']['latitude']) ? (float)$advert['location']['latitude'] : null;
        $lon = isset($advert['location']['longitude']) ? (float)$advert['location']['longitude'] : null;

        $batteryMv = isset($advert['battery']['voltage_mv']) ? (int)$advert['battery']['voltage_mv'] : null;
        $batteryV = isset($advert['battery']['voltage_v']) ? (float)$advert['battery']['voltage_v'] : null;

        $rssi = isset($packetMeta['rssi']) ? (float)$packetMeta['rssi'] : null;
        $snr = isset($packetMeta['snr']) ? (float)$packetMeta['snr'] : null;

        $heardBotId = !empty($packetMeta['bot_id']) 
            ? strtoupper(substr(trim((string)$packetMeta['bot_id']), 0, 16)) 
            : (!empty($packetMeta['payload_json']) && is_string($packetMeta['payload_json']) && ($pj = json_decode($packetMeta['payload_json'], true)) && !empty($pj['bot_id']) 
                ? strtoupper(substr(trim((string)$pj['bot_id']), 0, 16)) 
                : 'KLP');

        // Boto nustatymas:
        // Jei advertas turi galiojančias GPS koordinates (ne null ir ne 0, 0), priskiriame arčiausiai esančiam botui.
        // Jei koordinačių nėra arba jos yra 0, 0 (arba nėra botų su koordinatėmis) - priskiriame botui, kuris išgirdo šį paketą.
        $hasCoords = ($lat !== null && $lon !== null && (abs($lat) > 0.0001 || abs($lon) > 0.0001));
        $botId = $heardBotId;

        if ($hasCoords) {
            $closestBot = ($this->botRepo !== null)
                ? $this->botRepo->findClosestBot($lat, $lon, false)
                : $this->findClosestBotViaPdo($lat, $lon);

            if ($closestBot && !empty($closestBot['bot_id'])) {
                $botId = strtoupper(substr(trim((string)$closestBot['bot_id']), 0, 16));
            }
        }

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
                bot_id = CASE WHEN nodes.is_bot_locked THEN nodes.bot_id ELSE COALESCE(EXCLUDED.bot_id, nodes.bot_id) END,
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
                `bot_id` = CASE WHEN `is_bot_locked` = 1 THEN `bot_id` ELSE COALESCE(VALUES(`bot_id`), `bot_id`) END,
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
     * Atnaujina mazgo 'last_seen_at' ir telemetrijos/radijo/boto rodiklius 'nodes' lentelėje.
     */
    public function touchNodeSeen(
        string $publicKeyOrNodeId,
        ?string $botId = null,
        ?float $batteryV = null,
        ?int $batteryMv = null,
        ?float $rssi = null,
        ?float $snr = null,
        bool $hasTelemetry = false,
        ?float $temperatureC = null,
        ?string $returnPath = null
    ): bool {
        $id = trim($publicKeyOrNodeId);
        if (empty($id)) {
            return false;
        }

        $sets = ['last_seen_at = CURRENT_TIMESTAMP'];
        $params = [':id1' => $id, ':id2' => $id];

        $finalBotId = !empty($botId) ? strtoupper(substr(trim($botId), 0, 16)) : null;
        if ($latitude !== null && $longitude !== null && (abs($latitude) > 0.0001 || abs($longitude) > 0.0001)) {
            $closest = ($this->botRepo !== null)
                ? $this->botRepo->findClosestBot($latitude, $longitude, false)
                : $this->findClosestBotViaPdo($latitude, $longitude);
            if ($closest && !empty($closest['bot_id'])) {
                $finalBotId = strtoupper(substr(trim((string)$closest['bot_id']), 0, 16));
            }
        }

        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if (!empty($finalBotId)) {
            if ($driver === 'pgsql') {
                $sets[] = 'bot_id = CASE WHEN is_bot_locked THEN bot_id ELSE :bot_id END';
            } else {
                $sets[] = 'bot_id = CASE WHEN is_bot_locked = 1 THEN bot_id ELSE :bot_id END';
            }
            $params[':bot_id'] = $finalBotId;
        }

        if ($returnPath !== null && trim($returnPath) !== '') {
            if ($driver === 'pgsql') {
                $sets[] = "last_path = CASE WHEN last_path = 'FLOOD' THEN 'FLOOD' ELSE :last_path END";
            } else {
                $sets[] = "last_path = IF(last_path = 'FLOOD', 'FLOOD', :last_path)";
            }
            $params[':last_path'] = strtoupper(trim($returnPath));
        }

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
            $res = $stmt->execute($params);

            if (!empty($botId)) {
                $cleanBot = strtoupper(substr(trim($botId), 0, 32));
                try {
                    $bStmt = $this->pdo->prepare("UPDATE bots SET last_seen_at = CURRENT_TIMESTAMP WHERE UPPER(bot_id) = :b_id");
                    $bStmt->execute([':b_id' => $cleanBot]);
                } catch (\Throwable $e) {}
            }

            return $res;
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
            $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
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
        $stmt = $this->pdo->query("SELECT id, raw_hex, rssi, snr, decoded_json FROM {$this->rawPacketsTable} ORDER BY id ASC");
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
     * Patikrina ar duomenų bazėje išvis yra bent 1 mazgas
     */
    public function hasAnyNodes(): bool
    {
        try {
            return ((int)$this->pdo->query("SELECT COUNT(*) FROM nodes")->fetchColumn()) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Atnaujina mazgo parametrus
     */
    public function updateNodeDetails(string $publicKeyOrNodeId, array $fields): bool
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
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

        if (array_key_exists('bot_id', $fields)) {
            $sets[] = "bot_id = :bot_id";
            $params[':bot_id'] = strtoupper(substr(trim((string)$fields['bot_id']), 0, 16));
        }

        if (array_key_exists('is_bot_locked', $fields)) {
            $sets[] = "is_bot_locked = :is_bot_locked";
            $params[':is_bot_locked'] = ($driver === 'pgsql') 
                ? (!empty($fields['is_bot_locked']) ? 'true' : 'false') 
                : (!empty($fields['is_bot_locked']) ? 1 : 0);
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
    public function getFilteredNodes(
        array $filters = [],
        int $limit = 50,
        int $offset = 0,
        ?string $sortBy = null,
        ?string $sortDir = 'DESC'
    ): array {
        if ($this->pollingQueueService !== null) {
            $this->pollingQueueService->syncFailedTelemetryAttempts(180);
        }

        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $colUpdate = ($driver === 'pgsql') ? '"update"' : '`update`';

        $where = [];
        $params = [];

        if (!empty($filters['search'])) {
            $s = '%' . trim(strtolower($filters['search'])) . '%';
            $where[] = "(LOWER(nodes.node_name) LIKE :search1 OR LOWER(nodes.node_id) LIKE :search2 OR LOWER(nodes.public_key) LIKE :search3)";
            $params[':search1'] = $s;
            $params[':search2'] = $s;
            $params[':search3'] = $s;
        }

        if (!empty($filters['type'])) {
            if ($filters['type'] === 'repeater') {
                $where[] = "(nodes.is_repeater = 1 OR nodes.is_repeater = true)";
            } elseif ($filters['type'] === 'chat') {
                $where[] = "(nodes.is_chat = 1 OR nodes.is_chat = true)";
            } elseif ($filters['type'] === 'room') {
                $where[] = "(nodes.is_room = 1 OR nodes.is_room = true)";
            }
        }

        if (isset($filters['update']) && $filters['update'] !== '') {
            $upVal = ($driver === 'pgsql') 
                ? (!empty($filters['update']) ? 'true' : 'false') 
                : (!empty($filters['update']) ? 1 : 0);
            $where[] = "nodes.{$colUpdate} = :filter_update";
            $params[':filter_update'] = $upVal;
        }

        if (!empty($filters['status'])) {
            if ($filters['status'] === 'disabled') {
                $where[] = "(nodes.is_disabled = 1 OR nodes.is_disabled = true)";
            } elseif ($filters['status'] === 'active') {
                $where[] = "(nodes.is_disabled = 0 OR nodes.is_disabled = false OR nodes.is_disabled IS NULL)";
            }
        } elseif (isset($filters['is_disabled']) && $filters['is_disabled'] !== '') {
            if (!empty($filters['is_disabled'])) {
                $where[] = "(nodes.is_disabled = 1 OR nodes.is_disabled = true)";
            } else {
                $where[] = "(nodes.is_disabled = 0 OR nodes.is_disabled = false OR nodes.is_disabled IS NULL)";
            }
        }

        if (!empty($filters['bot_id'])) {
            $where[] = "UPPER(nodes.bot_id) = :filter_bot_id";
            $params[':filter_bot_id'] = strtoupper(trim((string)$filters['bot_id']));
        }

        $whereSql = !empty($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

        $countSql = "SELECT COUNT(*) FROM nodes {$whereSql}";
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($params);
        $totalCount = (int)$countStmt->fetchColumn();

        $allowedCols = [
            'name'         => 'nodes.node_name',
            'node_name'    => 'nodes.node_name',
            'node_id'      => 'nodes.node_id',
            'type'         => 'nodes.is_repeater',
            'is_repeater'  => 'nodes.is_repeater',
            'bot_id'       => 'nodes.bot_id',
            'status'       => 'nodes.is_disabled',
            'is_disabled'  => 'nodes.is_disabled',
            'update'       => 'nodes.' . $colUpdate,
            'password'     => 'nodes.password',
            'coords'       => 'nodes.latitude',
            'latitude'     => 'nodes.latitude',
            'last_seen_at' => 'nodes.last_seen_at',
        ];
        $orderCol = $allowedCols[$sortBy ?? ''] ?? 'nodes.last_seen_at';
        $orderDir = (strtoupper((string)$sortDir) === 'ASC') ? 'ASC' : 'DESC';

        $sql = "SELECT nodes.*, b.name AS bot_name, b.enabled AS bot_enabled, b.latitude AS bot_latitude, b.longitude AS bot_longitude 
                FROM nodes 
                LEFT JOIN bots b ON UPPER(nodes.bot_id) = UPPER(b.bot_id) 
                {$whereSql} 
                ORDER BY {$orderCol} {$orderDir}, nodes.id DESC 
                LIMIT " . (int)$limit . " OFFSET " . (int)$offset;
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
     * Suranda arčiausiai esantį botą tiesiogiai per PDO užklausą (jei botRepo nebuvo perduotas)
     */
    private function findClosestBotViaPdo(float $latitude, float $longitude): ?array
    {
        try {
            $stmt = $this->pdo->query("SELECT bot_id, name, latitude, longitude, enabled FROM bots WHERE latitude IS NOT NULL AND longitude IS NOT NULL");
            $bots = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $closest = null;
            $minDist = PHP_FLOAT_MAX;
            foreach ($bots as $b) {
                $bLat = (float)$b['latitude'];
                $bLon = (float)$b['longitude'];
                if (abs($bLat) < 0.0001 && abs($bLon) < 0.0001) continue;

                $earthRadius = 6371; // km
                $dLat = deg2rad($bLat - $latitude);
                $dLon = deg2rad($bLon - $longitude);
                $a = sin($dLat / 2) * sin($dLat / 2) +
                     cos(deg2rad($latitude)) * cos(deg2rad($bLat)) *
                     sin($dLon / 2) * sin($dLon / 2);
                $dist = $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
                if ($dist < $minDist) {
                    $minDist = $dist;
                    $closest = $b;
                }
            }
            return $closest;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
