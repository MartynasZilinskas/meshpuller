<?php

namespace MeshCore\Repositories;

use DateTime;
use DateTimeInterface;
use DateTimeZone;
use MeshCore\Contracts\GeolocationServiceInterface;
use MeshCore\Contracts\NodeRepositoryInterface;
use MeshCore\Contracts\SettingsRepositoryInterface;
use MeshCore\Contracts\TelemetryRepositoryInterface;
use MeshCore\MeshCoreDecoder;
use PDO;

/**
 * Class TelemetryRepository
 *
 * Atsakinga už telemetrijos rodiklių (baterija, temperatūra, laiko eilutės) saugojimą,
 * valymą ir atvaizdavimą pagal SRP.
 */
class TelemetryRepository implements TelemetryRepositoryInterface
{
    private PDO $pdo;
    private ?NodeRepositoryInterface $nodeRepo;
    private ?SettingsRepositoryInterface $settingsRepo;
    private ?GeolocationServiceInterface $geolocationService;
    private string $rawPacketsTable;

    public function __construct(
        PDO $pdo,
        ?NodeRepositoryInterface $nodeRepo = null,
        ?SettingsRepositoryInterface $settingsRepo = null,
        ?GeolocationServiceInterface $geolocationService = null,
        string $rawPacketsTable = 'raw_packets'
    ) {
        $this->pdo                = $pdo;
        $this->nodeRepo           = $nodeRepo;
        $this->settingsRepo       = $settingsRepo;
        $this->geolocationService = $geolocationService;
        $this->rawPacketsTable    = preg_replace('/[^a-zA-Z0-9_]/', '', $rawPacketsTable);
    }

    public function setNodeRepository(NodeRepositoryInterface $nodeRepo): void
    {
        $this->nodeRepo = $nodeRepo;
    }

    public function setSettingsRepository(SettingsRepositoryInterface $settingsRepo): void
    {
        $this->settingsRepo = $settingsRepo;
    }

    public function setGeolocationService(GeolocationServiceInterface $geolocationService): void
    {
        $this->geolocationService = $geolocationService;
    }

    /**
     * Ištraukia telemetrijos rodiklius iš iškoduoto paketo ir įrašo į 'telemetry' lentelę.
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
        } elseif (isset($parsed['sensors']) || (isset($parsed['response_type']) && $parsed['response_type'] === 'CAYENNE_LPP_TELEMETRY')) {
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

        if ($type === null || ($type !== 'STATUS' && $type !== 'TELEMETRY')) {
            return null;
        }

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

        $incomingHops = $decoded['path']['hops'] ?? [];
        $pathHopCount = isset($decoded['path']['hop_count']) ? (int)$decoded['path']['hop_count'] : null;
        $returnPath = null;
        if ($pathHopCount === 0) {
            if ($rssi !== null && $rssi >= -95.0) {
                $returnPath = 'DIRECT';
            }
        } elseif (!empty($incomingHops) && is_array($incomingHops)) {
            $computedPath = implode('', array_reverse($incomingHops));
            if ($pathHopCount === 1 && $rssi !== null && $rssi < -100.0) {
                $returnPath = null;
            } else {
                $returnPath = $computedPath;
            }
        }

        if ($this->nodeRepo !== null) {
            if (!empty($publicKey)) {
                $this->nodeRepo->touchNodeSeen($publicKey, $botId, $batteryV, $batteryMv, $rssi, $snr, $hasTelemetry, $temperatureC, $returnPath);
            } elseif (!empty($nodeId) && $nodeId !== 'node') {
                $this->nodeRepo->touchNodeSeen($nodeId, $botId, $batteryV, $batteryMv, $rssi, $snr, $hasTelemetry, $temperatureC, $returnPath);
            }
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
     * Įrašo telemetrijos eilutę į 'telemetry' lentelę su dublikatų prevencija.
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
     * Grąžina telemetrijos istoriją konkrečiam mazgui.
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
        $stmt = $this->pdo->query("SELECT * FROM {$this->rawPacketsTable} ORDER BY id ASC");
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
     * Gauna kartotuvus su naujausia telemetrija žemėlapio atvaizdavimui.
     */
    public function getRepeatersWithLatestTelemetry(bool $repeatersOnly = true, int $limit = 500): array
    {
        $where = [];
        if ($repeatersOnly) {
            $where[] = "(n.is_repeater = 1 OR n.is_repeater = true)";
        }
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
            $batteryV = ($row['last_battery_v'] !== null) ? (float)$row['last_battery_v'] : null;
            $tempC = ($row['last_temperature_c'] !== null) ? (float)$row['last_temperature_c'] : null;
            $telemUpdatedAt = $row['last_telemetry_at'] ?? $row['telemetry_updated_at'];

            $nodeId = !empty($row['node_id']) 
                ? (string)$row['node_id'] 
                : (!empty($row['public_key']) ? substr($row['public_key'], 0, 4) : 'node');

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

        if ($this->geolocationService !== null) {
            return $this->geolocationService->filterNodesByTelemetryAgeSettings($result);
        }

        return $result;
    }

    /**
     * Išvalo neteisingus ar iškreiptus telemetrijos įrašus.
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

    /**
     * Grąžina filtruotus telemetrijos įrašus su puslapiavimu.
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

        $countSql = "SELECT COUNT(*) FROM telemetry {$whereSql}";
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($params);
        $totalCount = (int)$countStmt->fetchColumn();

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
     * Grąžina konkretaus mazgo telemetrijos istoriją grafikui.
     */
    public function getNodeTelemetryHistory(string $nodeIdentifier, ?int $days = null): array
    {
        $chartDays = 7;
        if ($this->settingsRepo !== null) {
            $chartDays = ($days !== null && $days > 0) ? min(365, $days) : (int)$this->settingsRepo->getSetting('telemetry_chart_days', 7);
        } else {
            $chartDays = ($days !== null && $days > 0) ? min(365, $days) : 7;
        }
        if ($chartDays <= 0) {
            $chartDays = 7;
        }

        $clean = trim(strtolower($nodeIdentifier));
        $resolvedNodeId = $clean;
        $resolvedPubKey = $clean;

        try {
            $nStmt = $this->pdo->prepare("SELECT node_id, public_key, node_name FROM nodes WHERE LOWER(node_id) = :id1 OR LOWER(public_key) = :id2 OR LOWER(public_key) LIKE :id3 OR LOWER(node_name) = :id4 LIMIT 1");
            $nStmt->execute([
                ':id1' => $clean,
                ':id2' => $clean,
                ':id3' => $clean . '%',
                ':id4' => $clean,
            ]);
            $nodeRow = $nStmt->fetch(PDO::FETCH_ASSOC);
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
        $tz = new DateTimeZone('Europe/Vilnius');

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':nid'         => $resolvedNodeId,
                ':pkey'        => $resolvedPubKey,
                ':pkey_prefix' => substr($resolvedPubKey, 0, 4),
                ':from_date'   => $fromDate,
            ]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($rows as $r) {
                $rawDate = $r['recorded_at'];
                $timeFormatted = $rawDate;
                $timeShort = $rawDate;
                $iso = $rawDate;

                try {
                    $dt = new DateTime($rawDate, $tz);
                    $dt->setTimezone($tz);
                    $timeFormatted = $dt->format('Y-m-d H:i');
                    $timeShort = $dt->format('m-d H:i');
                    $iso = $dt->format(DateTimeInterface::ATOM);
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
}
