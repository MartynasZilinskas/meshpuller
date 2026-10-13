<?php

namespace MeshCore\Services;

use DateTime;
use DateTimeInterface;
use DateTimeZone;
use MeshCore\Contracts\PollingQueueServiceInterface;
use MeshCore\Contracts\SettingsRepositoryInterface;
use PDO;

/**
 * Class PollingQueueService
 *
 * Atsakinga už ESP32 kartotuvų telemetrijos užklausų eilės valdymą, botų koordinavimą ir bandymų apskaitą pagal SRP.
 */
class PollingQueueService implements PollingQueueServiceInterface
{
    private PDO $pdo;
    private ?SettingsRepositoryInterface $settingsRepo;

    public function __construct(PDO $pdo, ?SettingsRepositoryInterface $settingsRepo = null)
    {
        $this->pdo          = $pdo;
        $this->settingsRepo = $settingsRepo;
    }

    public function setSettingsRepository(SettingsRepositoryInterface $settingsRepo): void
    {
        $this->settingsRepo = $settingsRepo;
    }

    private function getSetting(string $key, $default = null)
    {
        if ($this->settingsRepo !== null) {
            return $this->settingsRepo->getSetting($key, $default);
        }
        return $default;
    }

    /**
     * Nustato arba atnaujina mazgo slaptažodį ir 'update' būseną 'nodes' lentelėje.
     */
    public function setNodeMonitoring(string $publicKeyOrNodeId, ?string $password = null, ?bool $update = null): bool
    {
        if ($password === null && $update === null) {
            return false;
        }

        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
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
     * Grąžina stebimų kartotuvų sąrašą tiksliai ESP32 /api/repyters formatu.
     */
    public function getMonitoredRepeaters(?string $botId = null): array
    {
        if (empty($botId)) {
            return [];
        }

        $cleanBotId = strtoupper(substr(trim((string)$botId), 0, 16));

        // Patikriname ar šis botas nėra išjungtas (enabled = 0) bots lentelėje
        if ($this->pdo) {
            try {
                $bCheck = $this->pdo->prepare("SELECT enabled FROM bots WHERE UPPER(bot_id) = :b_id LIMIT 1");
                $bCheck->execute([':b_id' => $cleanBotId]);
                $bEnabled = $bCheck->fetchColumn();
                if ($bEnabled !== false && !$bEnabled) {
                    return []; // Botas išjungtas per administravimo pultą
                }
            } catch (\Throwable $e) {}
        }

        $repeaters = [];
        $knownKeys = [];
        $driver = $this->pdo ? $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) : null;
        $dbSuccess = false;

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
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
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
                $dbSuccess = false;
            }
        }

        if ($dbSuccess && !empty($repeaters)) {
            return $repeaters;
        }

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

        // Fallback: repeaters.json
        $filePaths = [
            __DIR__ . '/../../repeaters.json',
            __DIR__ . '/../../r.json',
            __DIR__ . '/../../../XIAO_S3_Mesh_Bot/repeaters.json'
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

        return $repeaters;
    }

    /**
     * Sinchronizuoja ir padidina nesėkmingų bandymų gauti telemetriją skaičių (telemetry_fail_count).
     */
    public function syncFailedTelemetryAttempts(int $timeoutSeconds = 180): int
    {
        if (!$this->pdo) {
            return 0;
        }

        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
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
     * Grąžina sekantį 1 stebimą kartotuvą, kurį botas turi užklausti.
     */
    public function getNextRepeaterToRequest(?string $botId = null): ?array
    {
        if (empty($botId)) {
            return null;
        }

        $cleanBotId = strtoupper(substr(trim((string)$botId), 0, 16));

        // Patikriname ar šis botas nėra išjungtas (enabled = 0) bots lentelėje
        if ($this->pdo) {
            try {
                $bCheck = $this->pdo->prepare("SELECT enabled FROM bots WHERE UPPER(bot_id) = :b_id LIMIT 1");
                $bCheck->execute([':b_id' => $cleanBotId]);
                $bEnabled = $bCheck->fetchColumn();
                if ($bEnabled !== false && !$bEnabled) {
                    return null; // Botas išjungtas per administravimo pultą
                }
            } catch (\Throwable $e) {}
        }

        $driver = $this->pdo ? $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) : null;
        if (!$this->pdo) {
            return null;
        }

        $this->syncFailedTelemetryAttempts(180);

        $maxFails = (int)$this->getSetting('max_telemetry_fails', 20);
        $failFilterPgsql = ($maxFails > 0) ? " AND (COALESCE(telemetry_fail_count, 0) < {$maxFails})" : "";
        $failFilterMysql = ($maxFails > 0) ? " AND (COALESCE(`telemetry_fail_count`, 0) < {$maxFails})" : "";

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
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
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
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                $failCount = (int)($row['telemetry_fail_count'] ?? 0);
                $lastFailedAt = $row['last_failed_request_at'] ?? null;

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
     * Grąžina kartotuvų telemetrijos užklausų eilę.
     */
    public function getNodePollingQueue(?string $botId = null, bool $monitoredOnly = true, ?string $search = null): array
    {
        if (!$this->pdo) {
            return [];
        }

        $this->syncFailedTelemetryAttempts(180);

        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $updateCol = ($driver === 'pgsql') ? '"update"' : '`update`';
        $repCol = ($driver === 'pgsql') ? 'is_repeater' : '`is_repeater`';

        $where = [];
        $params = [];

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
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }

        $result = [];
        $queueIndex = 1;
        $now = time();

        $tz = new DateTimeZone('Europe/Vilnius');
        $formatLt = function(?string $dateStr) use ($tz): ?string {
            if (!$dateStr) return null;
            try {
                $dt = new DateTime($dateStr, $tz);
                $dt->setTimezone($tz);
                return $dt->format('Y-m-d H:i:s');
            } catch (\Throwable $e) {
                return $dateStr;
            }
        };

        $formatIsoLt = function(?string $dateStr) use ($tz): ?string {
            if (!$dateStr) return null;
            try {
                $dt = new DateTime($dateStr, $tz);
                $dt->setTimezone($tz);
                return $dt->format(DateTimeInterface::ATOM);
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

            $battV = ($row['last_battery_v'] !== null) ? (float)$row['last_battery_v'] : null;
            $tempC = ($row['last_temperature_c'] !== null) ? (float)$row['last_temperature_c'] : null;

            $rawReqDate = $row['requested_date'];
            $rawTelemDate = $row['last_telemetry_at'] ?? $row['telemetry_updated_at'];
            $hasPriorTelemetry = ($rawTelemDate !== null && trim((string)$rawTelemDate) !== '');

            $failCount = (int)($row['telemetry_fail_count'] ?? 0);
            $isBlocked = ($maxFails > 0 && $failCount >= $maxFails);

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

                if ($telemTime >= ($reqTime - 30)) {
                    $attemptStatus = 'success';
                    $attemptLabel = '✅ Telemetrija gauta';
                    $attemptColor = 'green';
                } else {
                    if ($elapsedReqSec < 180) {
                        $attemptStatus = 'in_progress';
                        $attemptLabel = '⏳ Laukiama atsakymo...';
                        $attemptColor = 'blue';
                    } else {
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
            $stmt = $this->pdo->query("
                SELECT DISTINCT bot_id FROM (
                    SELECT bot_id FROM bots WHERE bot_id IS NOT NULL AND bot_id != ''
                    UNION
                    SELECT bot_id FROM nodes WHERE bot_id IS NOT NULL AND bot_id != ''
                ) combined_bots
                ORDER BY bot_id ASC
            ");
            return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (\Throwable $e) {
            try {
                $stmt = $this->pdo->query("SELECT DISTINCT bot_id FROM nodes WHERE bot_id IS NOT NULL AND bot_id != '' ORDER BY bot_id ASC");
                return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
            } catch (\Throwable $e2) {
                return [];
            }
        }
    }

    /**
     * Nustato requested_date = NULL konkrečiam mazgui.
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
     * Nustato telemetry_fail_count = 0 ir last_failed_request_at = NULL visiems mazgams.
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
     */
    public function resetAllRequestedDates(?string $botId = null, bool $monitoredOnly = true, bool $resetFailCount = false): int
    {
        if (!$this->pdo) {
            return 0;
        }
        try {
            $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
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
}
