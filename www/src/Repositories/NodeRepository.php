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

        // Telemetrijos (baterijos) rodiklius priskiriame TIK kartotuvams:
        $batteryMv = ($isRepeater && isset($advert['battery']['voltage_mv'])) ? (int)$advert['battery']['voltage_mv'] : null;
        $batteryV = ($isRepeater && isset($advert['battery']['voltage_v'])) ? (float)$advert['battery']['voltage_v'] : null;

        $rssi = isset($packetMeta['rssi']) ? (float)$packetMeta['rssi'] : null;
        $snr = isset($packetMeta['snr']) ? (float)$packetMeta['snr'] : null;

        $heardBotId = !empty($packetMeta['bot_id']) 
            ? strtoupper(substr(trim((string)$packetMeta['bot_id']), 0, 16)) 
            : (!empty($packetMeta['payload_json']) && is_string($packetMeta['payload_json']) && ($pj = json_decode($packetMeta['payload_json'], true)) && !empty($pj['bot_id']) 
                ? strtoupper(substr(trim((string)$pj['bot_id']), 0, 16)) 
                : 'KLP');

        // Ištraukiame atvirkštinį maršrutą (Reverse Path) ir šuolių skaičių iš ADVERT paketo
        $routeInfo = $this->extractReturnPathFromMeta($packetMeta, $rssi, $snr);
        $candidatePath = $routeInfo['path'];
        $candidateHops = $routeInfo['hops'];

        // Nuskaitome esamą mazgo būseną (jei jau egzistuoja DB), kad palygintume kelio ilgį ir apsaugotume nuo aidų
        $existingNode = $this->findExistingNodeState($pubKey);

        // Boto ir maršruto parinkimas pagal trumpiausią kelią (hops), apkrovos balansavimą ir GPS atsarginį variantą
        $resolved = $this->resolveOptimalBotAndPath(
            $existingNode,
            $heardBotId,
            $candidatePath,
            $candidateHops,
            $rssi,
            $snr,
            $lat,
            $lon,
            false
        );

        $botId = $resolved['bot_id'];
        $resolvedPath = $resolved['last_path'];
        $resetFails = !empty($resolved['reset_fails']);

        $flagsJson = !empty($flags) ? json_encode($flags, JSON_UNESCAPED_UNICODE) : null;
        $rawAdvertJson = json_encode($advert, JSON_UNESCAPED_UNICODE);

        $defaultPassword = $isRepeater ? 'hello' : null;
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'pgsql') {
            $sql = <<<SQL
            INSERT INTO nodes (
                public_key, node_name, node_id, password, advert_format, advert_timestamp, signature,
                flags_raw, node_type, node_type_str, is_repeater, is_chat, is_room,
                has_location, latitude, longitude, battery_mv, battery_v,
                last_rssi, last_snr, flags_json, raw_advert_json, bot_id, last_path, first_seen_at, last_seen_at,
                requested_date, telemetry_updated_at, "update"
            ) VALUES (
                :public_key, :node_name, :node_id, :default_password, :advert_format, :advert_timestamp, :signature,
                :flags_raw, :node_type, :node_type_str, :is_repeater, :is_chat, :is_room,
                :has_location, :latitude, :longitude, :battery_mv, :battery_v,
                :last_rssi, :last_snr, :flags_json::jsonb, :raw_advert_json::jsonb, :bot_id, :last_path, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP,
                NULL, NULL, :initial_update
            )
            ON CONFLICT (public_key) DO UPDATE SET
                node_name = COALESCE(EXCLUDED.node_name, nodes.node_name),
                node_id = COALESCE(EXCLUDED.node_id, nodes.node_id),
                password = COALESCE(NULLIF(nodes.password, ''), EXCLUDED.password),
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
                last_path = CASE WHEN UPPER(COALESCE(nodes.last_path, '')) = 'FLOOD' THEN 'FLOOD' ELSE :last_path_upd END,
                telemetry_fail_count = CASE WHEN :reset_fails_cnt = 1 THEN 0 ELSE nodes.telemetry_fail_count END,
                last_failed_request_at = CASE WHEN :reset_fails_at = 1 THEN NULL ELSE nodes.last_failed_request_at END,
                last_seen_at = CURRENT_TIMESTAMP,
                "update" = CASE 
                    WHEN EXCLUDED.is_repeater = false OR nodes.is_disabled = true THEN false
                    WHEN (nodes.is_repeater = false OR nodes.is_repeater IS NULL) AND EXCLUDED.is_repeater = true AND :auto_add_flag = 1 
                    THEN true 
                    ELSE nodes."update" 
                END
SQL;
        } else {
            $sql = <<<SQL
            INSERT INTO `nodes` (
                `public_key`, `node_name`, `node_id`, `password`, `advert_format`, `advert_timestamp`, `signature`,
                `flags_raw`, `node_type`, `node_type_str`, `is_repeater`, `is_chat`, `is_room`,
                `has_location`, `latitude`, `longitude`, `battery_mv`, `battery_v`,
                `last_rssi`, `last_snr`, `flags_json`, `raw_advert_json`, `bot_id`, `last_path`, `first_seen_at`, `last_seen_at`,
                `requested_date`, `telemetry_updated_at`, `update`
            ) VALUES (
                :public_key, :node_name, :node_id, :default_password, :advert_format, :advert_timestamp, :signature,
                :flags_raw, :node_type, :node_type_str, :is_repeater, :is_chat, :is_room,
                :has_location, :latitude, :longitude, :battery_mv, :battery_v,
                :last_rssi, :last_snr, :flags_json, :raw_advert_json, :bot_id, :last_path, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP,
                NULL, NULL, :initial_update
            )
            ON DUPLICATE KEY UPDATE
                `node_name` = COALESCE(VALUES(`node_name`), `node_name`),
                `node_id` = COALESCE(VALUES(`node_id`), `node_id`),
                `password` = COALESCE(NULLIF(`password`, ''), VALUES(`password`)),
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
                `last_path` = IF(UPPER(COALESCE(`last_path`, '')) = 'FLOOD', 'FLOOD', :last_path_upd),
                `telemetry_fail_count` = IF(:reset_fails_cnt = 1, 0, `telemetry_fail_count`),
                `last_failed_request_at` = IF(:reset_fails_at = 1, NULL, `last_failed_request_at`),
                `last_seen_at` = CURRENT_TIMESTAMP,
                `update` = CASE 
                    WHEN VALUES(`is_repeater`) = 0 OR `is_disabled` = 1 THEN 0
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
        $stmt->bindValue(':default_password', $defaultPassword);
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
        $stmt->bindValue(':last_path', $resolvedPath);
        $stmt->bindValue(':last_path_upd', $resolvedPath);
        $stmt->bindValue(':reset_fails_cnt', $resetFails ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':reset_fails_at', $resetFails ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':initial_update', ($driver === 'pgsql') 
            ? ($initialUpdate ? 'true' : 'false') 
            : ($initialUpdate ? 1 : 0));
        $stmt->bindValue(':auto_add_flag', $autoAdd ? 1 : 0, PDO::PARAM_INT);

        $res = $stmt->execute();

        if (!empty($heardBotId)) {
            try {
                $bStmt = $this->pdo->prepare("UPDATE bots SET last_seen_at = CURRENT_TIMESTAMP WHERE UPPER(bot_id) = :b_id");
                $bStmt->execute([':b_id' => $heardBotId]);
            } catch (\Throwable $e) {}
        }

        return $res;
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

        $sets = [];
        if (!$hasTelemetry) {
            $sets[] = 'last_seen_at = CURRENT_TIMESTAMP';
        } else {
            $sets[] = 'last_seen_at = COALESCE(last_seen_at, CURRENT_TIMESTAMP)';
        }
        $params = [':id1' => $id, ':id2' => $id];

        $heardBotId = !empty($botId) ? strtoupper(substr(trim($botId), 0, 16)) : null;
        $cleanReturnPath = ($returnPath !== null && trim($returnPath) !== '') ? strtoupper(trim($returnPath)) : null;

        $existingNode = $this->findExistingNodeState($id, $hasTelemetry);
        if ($hasTelemetry && $existingNode === null) {
            // Telemetrija priskiriama TIK kartotuvams (is_repeater = 1). Jei kartotuvas nerastas – neatnaujiname kliento!
            return false;
        }
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        // Jei turime maršrutą arba tai yra telemetrijos atsakymas – vertiname optimalų botą ir kelią
        if ($existingNode !== null && ($cleanReturnPath !== null || $hasTelemetry)) {
            $candidateHops = ($cleanReturnPath !== null) ? $this->getPathHopCount($cleanReturnPath) : null;
            $lat = isset($existingNode['latitude']) && $existingNode['latitude'] !== null ? (float)$existingNode['latitude'] : null;
            $lon = isset($existingNode['longitude']) && $existingNode['longitude'] !== null ? (float)$existingNode['longitude'] : null;

            $resolved = $this->resolveOptimalBotAndPath(
                $existingNode,
                $heardBotId ?? ($existingNode['bot_id'] ?? 'KLP'),
                $cleanReturnPath,
                $candidateHops,
                $rssi,
                $snr,
                $lat,
                $lon,
                $hasTelemetry
            );

            if (!empty($resolved['bot_id'])) {
                if ($driver === 'pgsql') {
                    $sets[] = 'bot_id = CASE WHEN is_bot_locked THEN bot_id ELSE :bot_id END';
                } else {
                    $sets[] = 'bot_id = CASE WHEN is_bot_locked = 1 THEN bot_id ELSE :bot_id END';
                }
                $params[':bot_id'] = $resolved['bot_id'];
            }

            if ($resolved['last_path'] !== null) {
                if ($driver === 'pgsql') {
                    $sets[] = "last_path = CASE WHEN UPPER(COALESCE(last_path, '')) = 'FLOOD' THEN 'FLOOD' ELSE :last_path END";
                } else {
                    $sets[] = "last_path = IF(UPPER(COALESCE(last_path, '')) = 'FLOOD', 'FLOOD', :last_path)";
                }
                $params[':last_path'] = $resolved['last_path'];
            }

            if (!empty($resolved['reset_fails']) && !$hasTelemetry) {
                $sets[] = 'telemetry_fail_count = 0';
                $sets[] = 'last_failed_request_at = NULL';
            }
        } elseif ($existingNode === null && !empty($heardBotId)) {
            if ($driver === 'pgsql') {
                $sets[] = 'bot_id = CASE WHEN is_bot_locked THEN bot_id ELSE :bot_id END';
            } else {
                $sets[] = 'bot_id = CASE WHEN is_bot_locked = 1 THEN bot_id ELSE :bot_id END';
            }
            $params[':bot_id'] = $heardBotId;
        }

        if ($hasTelemetry) {
            $sets[] = 'telemetry_updated_at = CURRENT_TIMESTAMP';
            $sets[] = 'telemetry_fail_count = 0';
            $sets[] = 'last_failed_request_at = NULL';
        }

        if ($hasTelemetry && $batteryV !== null && $batteryV >= 0.0 && $batteryV <= 15.0) {
            $sets[] = 'battery_v = :batt_v';
            $params[':batt_v'] = $batteryV;
        }

        if ($hasTelemetry && $batteryMv !== null && $batteryMv >= 0 && $batteryMv <= 15000) {
            $sets[] = 'battery_mv = :batt_mv';
            $params[':batt_mv'] = $batteryMv;
        }

        if ($hasTelemetry && $temperatureC !== null && $temperatureC >= -60.0 && $temperatureC <= 100.0) {
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
        if ($existingNode !== null && !empty($existingNode['id'])) {
            unset($params[':id1'], $params[':id2']);
            $params[':target_id'] = (int)$existingNode['id'];
            $repGuard = $hasTelemetry ? ' AND (is_repeater = 1 OR is_repeater = true)' : '';
            $sql = "UPDATE nodes SET {$setString} WHERE id = :target_id{$repGuard}";
        } else {
            $repGuard = $hasTelemetry ? ' AND (is_repeater = 1 OR is_repeater = true)' : '';
            $sql = "UPDATE nodes SET {$setString} WHERE (LOWER(public_key) = LOWER(:id1) OR LOWER(node_id) = LOWER(:id2)){$repGuard}";
        }

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
        $sql = "SELECT id, bot_id, raw_hex, rssi, snr, decoded_json, created_at
                FROM (
                    SELECT id, bot_id, raw_hex, rssi, snr, decoded_json, created_at
                    FROM {$this->rawPacketsTable}
                    WHERE direction = 'RX' OR direction IS NULL
                    ORDER BY id DESC
                    LIMIT 3000
                ) recent_pkts
                ORDER BY id ASC";
        $stmt = $this->pdo->query($sql);
        $count = 0;

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $advert = null;
            $dec = null;
            if (!empty($row['decoded_json'])) {
                $dec = is_string($row['decoded_json']) ? json_decode($row['decoded_json'], true) : $row['decoded_json'];
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
                if (is_array($dec) && !empty($dec['path'])) {
                    $row['path'] = $dec['path'];
                }
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
        $cleanId = trim($publicKeyOrNodeId);

        if (array_key_exists('node_name', $fields)) {
            $sets[] = "node_name = :node_name";
            $params[':node_name'] = $fields['node_name'] !== null ? trim((string)$fields['node_name']) : null;
        }

        if (array_key_exists('password', $fields)) {
            $colPass = ($driver === 'pgsql') ? 'password' : '`password`';
            $sets[] = "{$colPass} = :password";
            $trimmedPass = $fields['password'] !== null ? trim((string)$fields['password']) : '';
            $params[':password'] = ($trimmedPass !== '') ? $trimmedPass : 'hello';
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
            if (!empty($fields['is_disabled']) && !array_key_exists('update', $fields)) {
                $colUpdate = ($driver === 'pgsql') ? '"update"' : '`update`';
                $sets[] = "{$colUpdate} = :auto_update_off";
                $params[':auto_update_off'] = ($driver === 'pgsql') ? 'false' : 0;
            }
        }

        if (array_key_exists('is_repeater', $fields)) {
            $sets[] = "is_repeater = :is_repeater";
            $params[':is_repeater'] = ($driver === 'pgsql') 
                ? (!empty($fields['is_repeater']) ? 'true' : 'false') 
                : (!empty($fields['is_repeater']) ? 1 : 0);
            if (empty($fields['is_repeater']) && !array_key_exists('update', $fields)) {
                $colUpdate = ($driver === 'pgsql') ? '"update"' : '`update`';
                $sets[] = "{$colUpdate} = :auto_rep_update_off";
                $params[':auto_rep_update_off'] = ($driver === 'pgsql') ? 'false' : 0;
            }
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
            $newBotId = strtoupper(substr(trim((string)$fields['bot_id']), 0, 16));
            $sets[] = "bot_id = :bot_id";
            $params[':bot_id'] = $newBotId;

            // Jei keičiamas bot_id į kitą botą, o last_path nebuvo eksplicitiškai pateiktas,
            // numetame senąjį kelią į NULL (nebent buvo priverstinis 'FLOOD'), nes senas kelias priklausė kitam botui
            if (!array_key_exists('last_path', $fields)) {
                $existing = $this->findExistingNodeState($cleanId);
                if ($existing && strtoupper(trim((string)($existing['bot_id'] ?? ''))) !== $newBotId) {
                    if ($driver === 'pgsql') {
                        $sets[] = "last_path = CASE WHEN UPPER(COALESCE(last_path, '')) = 'FLOOD' THEN 'FLOOD' ELSE NULL END";
                    } else {
                        $sets[] = "last_path = IF(UPPER(COALESCE(last_path, '')) = 'FLOOD', 'FLOOD', NULL)";
                    }
                }
            }
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

        if (strlen($cleanId) > 16) {
            $sql = "UPDATE nodes SET {$setString} WHERE LOWER(public_key) = LOWER(:id1)";
            $params[':id1'] = $cleanId;
        } else {
            $sql = "UPDATE nodes SET {$setString} WHERE LOWER(public_key) = LOWER(:id1) OR LOWER(node_id) = LOWER(:id2)";
            $params[':id1'] = $cleanId;
            $params[':id2'] = $cleanId;
        }

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
     * Ištraukia atvirkštinį maršrutą (Reverse Path) ir šuolių skaičių iš paketo metaduomenų.
     *
     * @return array{path: ?string, hops: ?int}
     */
    private function extractReturnPathFromMeta(?array $packetMeta, ?float $rssi, ?float $snr): array
    {
        if (empty($packetMeta)) {
            return ['path' => null, 'hops' => null];
        }

        $dec = null;
        if (!empty($packetMeta['decoded_json'])) {
            $dec = is_string($packetMeta['decoded_json'])
                ? json_decode($packetMeta['decoded_json'], true)
                : $packetMeta['decoded_json'];
        } elseif (isset($packetMeta['header']) || isset($packetMeta['encryption'])) {
            $dec = $packetMeta;
        }

        $routeType = $packetMeta['header']['route_type'] ?? ($dec['header']['route_type'] ?? null);
        $isDirectRoute = ($routeType !== null && in_array((int)$routeType, [2, 3], true));

        $returnedPath = $packetMeta['encryption']['parsed_data']['returned_path']
            ?? ($dec['encryption']['parsed_data']['returned_path'] ?? null);

        $isAlreadyForward = false;
        $pathData = null;

        if (is_array($returnedPath) && isset($returnedPath['hop_count'])) {
            // SVARBU: Jei tai PAYLOAD_TYPE_PATH paketas (tiek 0x22 DIRECT, tiek 0x21 FLOOD!),
            // jo viduje esantis 'returned_path' yra tikrasis priekinis kelias (Botas -> Kartotuvas),
            // kuriuo mūsų užklausa pasiekė kartotuvą (pvz., B9DD -> 4607), o išorinis FLOOD kelias
            // yra atgalinis (Kartotuvas -> Botas, pvz., 3BBD -> B9DD), kuris gali būti asimetrinis!
            $pathData = $returnedPath;
            $isAlreadyForward = true;
        } elseif ($isDirectRoute) {
            // Kai paprastas RESPONSE paketas atkeliauja per ROUTE_TYPE_DIRECT (2 arba 3),
            // tarpiniai kartotuvai nuima savo šuolius iš išorinės antraštės (todėl hop_count tampa 0!).
            return ['path' => null, 'hops' => null];
        } else {
            $pathData = $packetMeta['path'] ?? ($dec['path'] ?? null);
        }

        if (!is_array($pathData) || !isset($pathData['hop_count'])) {
            return ['path' => null, 'hops' => null];
        }

        $hopCount = (int)$pathData['hop_count'];
        $incomingHops = (isset($pathData['hops']) && is_array($pathData['hops'])) ? $pathData['hops'] : [];

        // 0 šuolių (Tiesioginis ryšys):
        // Saugomės nuo asimetrinio silpno signalo (kai kartotuvas ant aukšto bokšto vos girdimas < -98 dBm,
        // bet botas tiesiogiai atgal jo nepasieks).
        if ($hopCount === 0) {
            if (($rssi === null || $rssi >= -98.0) && ($snr === null || $snr >= -5.0)) {
                return ['path' => 'DIRECT', 'hops' => 0];
            }
            return ['path' => null, 'hops' => null];
        }

        // Daugiapakopis ryšys (1..N šuolių):
        // Jei kelias paimtas iš FLOOD išorinės antraštės – apverčiame (Reverse Path).
        // Jei kelias paimtas iš PAYLOAD_TYPE_PATH vidinio 'returned_path' – jis jau yra Botas->Kartotuvas tvarka!
        if ($hopCount > 0 && !empty($incomingHops)) {
            if ($hopCount === 1 && $rssi !== null && $rssi < -105.0) {
                return ['path' => null, 'hops' => null];
            }
            $orderedHops = $isAlreadyForward ? $incomingHops : array_reverse($incomingHops);
            $cleanHops = [];
            foreach ($orderedHops as $h) {
                $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', (string)$h));
                if (strlen($hex) === 2) {
                    // Senas ESP32 botų firmware tikisi 2 baitų (4 hex simbolių) hash kiekvienam šuoliui.
                    // Bandome vienareikšmiškai išplėsti 1 baito prefiksą pagal žinomus kartotuvus DB:
                    $resolved4 = $this->resolveOneByteHopToTwoBytes($hex);
                    if ($resolved4 === null) {
                        return ['path' => null, 'hops' => null];
                    }
                    $hex = $resolved4;
                }
                if (strlen($hex) !== 4) {
                    return ['path' => null, 'hops' => null];
                }
                $cleanHops[] = $hex;
            }
            if (!empty($cleanHops)) {
                return [
                    'path' => implode('', $cleanHops),
                    'hops' => count($cleanHops),
                ];
            }
        }

        return ['path' => null, 'hops' => null];
    }

    /**
     * Pagalbinis metodas atbuliniam keliui išgauti iš dekoduoto paketo masyvo arba jo 'path' elemento
     * (naudojamas tiek ADVERT, tiek TELEMETRY / RESPONSE / PATH paketams).
     */
    public function extractReturnPathString(?array $pathOrDecoded, ?float $rssi = null, ?float $snr = null): ?string
    {
        if (empty($pathOrDecoded) || !is_array($pathOrDecoded)) {
            return null;
        }
        $meta = (isset($pathOrDecoded['header']) || isset($pathOrDecoded['path']) || isset($pathOrDecoded['encryption']))
            ? $pathOrDecoded
            : ['path' => $pathOrDecoded];
        $res = $this->extractReturnPathFromMeta($meta, $rssi, $snr);
        return $res['path'];
    }

    /**
     * Jei paketas atėjo su 1 baito (2 hex simbolių) šuolio prefiksu, bando surasti
     * vienintelį aktyvų kartotuvą DB ir grąžinti jo 2 baitų (4 hex simbolių) prefiksą,
     * kad senas ESP32 firmware (kuris naudoja routeHashSize = 2) galėtų korektiškai maršrutizuoti.
     */
    private function resolveOneByteHopToTwoBytes(string $twoHexChars): ?string
    {
        $prefix = strtolower(trim($twoHexChars));
        if (strlen($prefix) !== 2) {
            return null;
        }
        try {
            $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $repCond = ($driver === 'pgsql') ? 'is_repeater = true' : '(`is_repeater` = 1 OR `is_repeater` = true)';
            $stmt = $this->pdo->prepare("
                SELECT public_key
                FROM nodes
                WHERE {$repCond} AND LOWER(public_key) LIKE :pref
                LIMIT 2
            ");
            $stmt->execute([':pref' => $prefix . '%']);
            $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (is_array($rows) && count($rows) === 1 && strlen((string)$rows[0]) >= 4) {
                return strtoupper(substr((string)$rows[0], 0, 4));
            }
        } catch (\Throwable $e) {}
        return null;
    }

    /**
     * Apskaičiuoja šuolių (hops) skaičių iš išsaugotos last_path reikšmės.
     * 'DIRECT' -> 0, 'B9DD' -> 1, 'B9DD1BBD' -> 2, NULL / 'FLOOD' -> 999
     */
    public function getPathHopCount(?string $path): int
    {
        if ($path === null) {
            return 999;
        }
        $clean = strtoupper(trim($path));
        if ($clean === '' || $clean === 'FLOOD') {
            return 999;
        }
        if ($clean === 'DIRECT') {
            return 0;
        }
        $hex = preg_replace('/[^0-9A-F]/', '', $clean);
        $len = strlen($hex);
        if ($len === 0) {
            return 999;
        }
        // Standartinis 16-bit (2 baitų = 4 hex simbolių) mazgo hash
        if ($len % 4 === 0) {
            return (int)($len / 4);
        }
        // 1 baito (2 hex simbolių) hash palaikymas
        return max(1, (int)ceil($len / 2));
    }

    /**
     * Nuskaito esamą mazgo būseną iš 'nodes' lentelės.
     */
    private function findExistingNodeState(string $pubKeyOrNodeId, bool $repeaterOnly = false): ?array
    {
        $clean = strtolower(trim($pubKeyOrNodeId));
        if ($clean === '') {
            return null;
        }
        try {
            $repCond = $repeaterOnly ? 'AND (is_repeater = 1 OR is_repeater = true)' : '';
            $stmt = $this->pdo->prepare("
                SELECT id, public_key, node_id, is_repeater, bot_id, is_bot_locked, last_path,
                       last_seen_at, telemetry_updated_at, telemetry_fail_count,
                       last_rssi, last_snr, latitude, longitude
                FROM nodes
                WHERE (LOWER(public_key) = :id1 OR LOWER(node_id) = :id2) {$repCond}
                ORDER BY (LOWER(public_key) = :id_exact) DESC, is_repeater DESC, id ASC
                LIMIT 1
            ");
            $stmt->execute([':id1' => $clean, ':id2' => $clean, ':id_exact' => $clean]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Patikrina ar nurodytas botas yra aktyvus (enabled = 1).
     */
    private function isBotEnabledInDb(string $botId): bool
    {
        if ($this->botRepo !== null) {
            return $this->botRepo->isBotEnabled($botId);
        }
        $clean = strtoupper(substr(trim($botId), 0, 32));
        if ($clean === '') {
            return true;
        }
        try {
            $stmt = $this->pdo->prepare("SELECT enabled FROM bots WHERE UPPER(bot_id) = :b_id LIMIT 1");
            $stmt->execute([':b_id' => $clean]);
            $val = $stmt->fetchColumn();
            return ($val === false) ? true : (bool)$val;
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * Grąžina kiekvieno boto aktyvių stebimų kartotuvų skaičių krūvio balansavimui.
     *
     * @return array<string, int>
     */
    private function getBotActiveRepeaterCounts(): array
    {
        $counts = [];
        try {
            $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $updateCond = ($driver === 'pgsql') ? '"update" = true' : '(`update` = 1 OR `update` = true)';
            $disabledCond = ($driver === 'pgsql') ? '(is_disabled = false OR is_disabled IS NULL)' : '(`is_disabled` = 0 OR `is_disabled` IS NULL)';
            $repCond = ($driver === 'pgsql') ? 'is_repeater = true' : '(`is_repeater` = 1 OR `is_repeater` = true)';

            $sql = "SELECT UPPER(bot_id) AS b_id, COUNT(*) AS cnt
                    FROM nodes
                    WHERE {$repCond} AND {$updateCond} AND {$disabledCond} AND bot_id IS NOT NULL AND bot_id != ''
                    GROUP BY UPPER(bot_id)";
            $stmt = $this->pdo->query($sql);
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (!empty($r['b_id'])) {
                    $counts[strtoupper(trim((string)$r['b_id']))] = (int)$r['cnt'];
                }
            }
        } catch (\Throwable $e) {}
        return $counts;
    }

    /**
     * Išmanus boto ir kelio (last_path) parinkimas pagal trumpiausią kelią (hops),
     * apsaugą nuo to paties paketo aidų bei apkrovos balansavimą tarp gretimų botų.
     *
     * @return array{bot_id: string, last_path: ?string, reset_fails: bool}
     */
    private function resolveOptimalBotAndPath(
        ?array $existingNode,
        string $heardBotId,
        ?string $candidatePath,
        ?int $candidateHops,
        ?float $rssi,
        ?float $snr,
        ?float $lat,
        ?float $lon,
        bool $isTelemetryResponse = false
    ): array {
        $heardBotId = strtoupper(substr(trim($heardBotId), 0, 16));
        if ($heardBotId === '') {
            $heardBotId = 'KLP';
        }
        $heardBotEnabled = $this->isBotEnabledInDb($heardBotId);

        // 1. NAUJAS MAZGAS (dar nėra 'nodes' lentelėje):
        // Priskiriame botui, kuris faktiškai išgirdo paketą (jei botas įjungtas), ir išsaugome kelią.
        if ($existingNode === null) {
            if ($heardBotEnabled && $candidatePath !== null) {
                return [
                    'bot_id'      => $heardBotId,
                    'last_path'   => $candidatePath,
                    'reset_fails' => false,
                ];
            }

            if (!$heardBotEnabled && $lat !== null && $lon !== null && (abs($lat) > 0.0001 || abs($lon) > 0.0001)) {
                $closest = ($this->botRepo !== null)
                    ? $this->botRepo->findClosestBot($lat, $lon, true)
                    : $this->findClosestBotViaPdo($lat, $lon);
                if ($closest && !empty($closest['bot_id'])) {
                    return [
                        'bot_id'      => strtoupper(substr(trim((string)$closest['bot_id']), 0, 16)),
                        'last_path'   => null,
                        'reset_fails' => false,
                    ];
                }
            }

            return [
                'bot_id'      => $heardBotId,
                'last_path'   => $candidatePath,
                'reset_fails' => false,
            ];
        }

        // 2. ESAMAS MAZGAS:
        $existingBotId = !empty($existingNode['bot_id'])
            ? strtoupper(substr(trim((string)$existingNode['bot_id']), 0, 16))
            : 'KLP';
        $isLocked = !empty($existingNode['is_bot_locked']);
        $existingPath = ($existingNode['last_path'] !== null && trim((string)$existingNode['last_path']) !== '')
            ? strtoupper(trim((string)$existingNode['last_path']))
            : null;
        $isForceFlood = ($existingPath === 'FLOOD');

        $oldHops = $this->getPathHopCount($existingPath);
        $newHops = ($candidatePath !== null)
            ? ($candidateHops ?? $this->getPathHopCount($candidatePath))
            : 999;

        $existingFailCount = (int)($existingNode['telemetry_fail_count'] ?? 0);
        $existingSnr = ($existingNode['last_snr'] !== null) ? (float)$existingNode['last_snr'] : null;

        $now = time();
        $lastSeenTs = !empty($existingNode['last_seen_at']) ? strtotime((string)$existingNode['last_seen_at']) : 0;
        $telemUpdatedTs = !empty($existingNode['telemetry_updated_at']) ? strtotime((string)$existingNode['telemetry_updated_at']) : 0;
        $secSinceSeen = ($lastSeenTs > 0) ? max(0, $now - $lastSeenTs) : 999999;
        $secSinceTelem = ($telemUpdatedTs > 0) ? max(0, $now - $telemUpdatedTs) : 999999;

        // 2A. Paketas gautas per TĄ PATĮ botą, kuriam mazgas jau priskirtas:
        if ($heardBotId === $existingBotId) {
            if ($isForceFlood) {
                return [
                    'bot_id'      => $existingBotId,
                    'last_path'   => 'FLOOD',
                    'reset_fails' => false,
                ];
            }

            $chosenPath = $existingPath;
            if ($candidatePath !== null) {
                $hasProvenWorkingPath = ($existingPath !== null && $existingFailCount === 0 && $telemUpdatedTs > 0 && $secSinceTelem < 86400);
                if (!$isTelemetryResponse && $hasProvenWorkingPath) {
                    // Jei jau turime išbandytą ir veikiantį telemetrijos kelią (0 klaidų per 24 val.),
                    // neleidžiame nepatikrintam ADVERT atgaliniam keliui (pvz., asimetriniam B9DD4BBD) jo perrašyti!
                    $chosenPath = $existingPath;
                } elseif ($newHops <= $oldHops) {
                    // Trumpesnis arba tokio pat ilgio kelias – atnaujiname!
                    // Jei kelias tokio pat ilgio ir gautas per tą pačią bangą (< 30s), pasiliekame geresnį SNR
                    if ($newHops === $oldHops && $existingPath !== null && $secSinceSeen < 30 && $snr !== null && $existingSnr !== null && $snr < $existingSnr) {
                        $chosenPath = $existingPath;
                    } else {
                        $chosenPath = $candidatePath;
                    }
                } else {
                    // Naujas kelias ILGESNIS už turimą ($newHops > $oldHops):
                    // Saugomės, kad vėluojantis kelių šuolių aidas neperrašytų ką tik gauto trumpesnio kelio!
                    if ($isTelemetryResponse) {
                        // Jei tai naujas apklausos ciklas (>= 45s nuo paskutinės telemetrijos),
                        // vadinasi trumpesnis kelias neatsakė ir atsakymas grįžo šiuo nauju keliu – išsaugome jį.
                        if ($secSinceTelem >= 45) {
                            $chosenPath = $candidatePath;
                        }
                    } else {
                        // ADVERT paketas: priimame ilgesnį kelią tik jei senasis kelias turi klaidų arba paseno (> 30 min.)
                        if ($existingFailCount > 0 || $secSinceSeen > 1800) {
                            $chosenPath = $candidatePath;
                        }
                    }
                }
            }

            return [
                'bot_id'      => $existingBotId,
                'last_path'   => $chosenPath,
                'reset_fails' => false,
            ];
        }

        // 2B. Paketas gautas per KITĄ botą ($heardBotId !== $existingBotId):
        // Jei priskyrimas užrakintas arba naujasis botas išjungtas – boto ir esamo boto kelio nekeičiame
        if ($isLocked || !$heardBotEnabled) {
            return [
                'bot_id'      => $existingBotId,
                'last_path'   => $isForceFlood ? 'FLOOD' : $existingPath,
                'reset_fails' => false,
            ];
        }

        $existingBotEnabled = $this->isBotEnabledInDb($existingBotId);
        $shouldSwitchBot = false;

        if (!$existingBotEnabled) {
            // Senasis botas išjungtas admine -> perima aktyvus botas
            $shouldSwitchBot = true;
        } elseif ($isTelemetryResponse) {
            // Šis botas ką tik sėkmingai gavo šifruotą telemetrijos atsakymą iš kartotuvo!
            $shouldSwitchBot = true;
        } elseif ($existingFailCount >= 1 && $newHops < 999) {
            // Senasis botas nesėkmingai bando pasiekti kartotuvą, o šis botas jį girdi su aiškiu keliu
            $shouldSwitchBot = true;
        } elseif ($newHops < $oldHops) {
            // Šis botas turi GRIEŽTAI TRUMPESNĮ kelią (pvz., 0 šuolių DIRECT vietoj 1-2 šuolių arba FLOOD)
            $shouldSwitchBot = true;
        } elseif ($newHops === $oldHops && $newHops < 999) {
            // Abu botai turi VIENODO ILGIO kelią (pvz., abu girdi per 0 šuolių arba abu per 1 šuolį):
            // Paskirstome krūvį tolygiai tarp gretimų botų!
            $counts = $this->getBotActiveRepeaterCounts();
            $existingLoad = $counts[$existingBotId] ?? 0;
            $heardLoad = $counts[$heardBotId] ?? 0;

            if (($existingLoad - $heardLoad) >= 2) {
                // Naujasis botas turi bent 2 kartotuvais mažiau nei esamas -> perima darbui subalansuoti
                $shouldSwitchBot = true;
            } elseif ($existingLoad >= $heardLoad && $snr !== null && $existingSnr !== null && ($snr - $existingSnr) >= 3.0 && $secSinceSeen > 30) {
                // Krūvis panašus, bet šis botas girdi ženkliai stipresnį signalą (+3 dB SNR)
                $shouldSwitchBot = true;
            }
        } elseif ($newHops > $oldHops && $newHops < 999 && $secSinceSeen > 7200) {
            // Senasis botas mazgo negirdėjo jau > 2 val., o naujasis botas jį girdi
            $shouldSwitchBot = true;
        }

        if ($shouldSwitchBot) {
            return [
                'bot_id'      => $heardBotId,
                'last_path'   => $isForceFlood ? 'FLOOD' : $candidatePath,
                'reset_fails' => ($existingFailCount > 0),
            ];
        }

        return [
            'bot_id'      => $existingBotId,
            'last_path'   => $isForceFlood ? 'FLOOD' : $existingPath,
            'reset_fails' => false,
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
