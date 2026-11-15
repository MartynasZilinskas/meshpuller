<?php

namespace MeshCore\Repositories;

use PDO;

class ApiLogRepository
{
    private PDO $pdo;
    private static bool $tableChecked = false;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    private function getDriver(): string
    {
        return (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    public function ensureTableExists(): void
    {
        if (self::$tableChecked) {
            return;
        }
        try {
            if ($this->getDriver() === 'pgsql') {
                $sql = <<<SQL
                CREATE TABLE IF NOT EXISTS api_logs (
                    id BIGSERIAL PRIMARY KEY,
                    ip_address VARCHAR(64) NOT NULL DEFAULT '',
                    method VARCHAR(12) NOT NULL DEFAULT 'GET',
                    endpoint VARCHAR(64) NOT NULL DEFAULT '',
                    request_uri TEXT NOT NULL,
                    bot_id VARCHAR(32) NULL,
                    user_agent TEXT NULL,
                    client_type VARCHAR(32) NOT NULL DEFAULT 'Unknown',
                    status_code SMALLINT NOT NULL DEFAULT 200,
                    duration_ms INT NULL,
                    response_size INT NULL,
                    request_body TEXT NULL,
                    response_body TEXT NULL,
                    headers_json TEXT NULL,
                    created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
                );
                CREATE INDEX IF NOT EXISTS idx_api_logs_created_at ON api_logs (created_at);
                CREATE INDEX IF NOT EXISTS idx_api_logs_ip ON api_logs (ip_address);
                CREATE INDEX IF NOT EXISTS idx_api_logs_endpoint ON api_logs (endpoint);
                CREATE INDEX IF NOT EXISTS idx_api_logs_client_type ON api_logs (client_type);
                CREATE INDEX IF NOT EXISTS idx_api_logs_bot_id ON api_logs (bot_id);
                CREATE INDEX IF NOT EXISTS idx_api_logs_status ON api_logs (status_code);
SQL;
            } else {
                $sql = <<<SQL
                CREATE TABLE IF NOT EXISTS `api_logs` (
                    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    `ip_address`    VARCHAR(64) NOT NULL DEFAULT '',
                    `method`        VARCHAR(12) NOT NULL DEFAULT 'GET',
                    `endpoint`      VARCHAR(64) NOT NULL DEFAULT '',
                    `request_uri`   VARCHAR(1024) NOT NULL DEFAULT '',
                    `bot_id`        VARCHAR(32) NULL DEFAULT NULL,
                    `user_agent`    TEXT NULL DEFAULT NULL,
                    `client_type`   VARCHAR(32) NOT NULL DEFAULT 'Unknown',
                    `status_code`   SMALLINT NOT NULL DEFAULT 200,
                    `duration_ms`   INT NULL DEFAULT NULL,
                    `response_size` INT NULL DEFAULT NULL,
                    `request_body`  LONGTEXT NULL DEFAULT NULL,
                    `response_body` LONGTEXT NULL DEFAULT NULL,
                    `headers_json`  TEXT NULL DEFAULT NULL,
                    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX `idx_api_logs_created_at` (`created_at`),
                    INDEX `idx_api_logs_ip` (`ip_address`),
                    INDEX `idx_api_logs_endpoint` (`endpoint`),
                    INDEX `idx_api_logs_client_type` (`client_type`),
                    INDEX `idx_api_logs_bot_id` (`bot_id`),
                    INDEX `idx_api_logs_status` (`status_code`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
            }
            $this->pdo->exec($sql);
            self::$tableChecked = true;
        } catch (\Throwable $e) {
            // Ignoruojame jei nepavyko sukurti
        }
    }

    /**
     * Klasifikuoja klientą pagal User-Agent, Bot ID ir endpointą
     */
    public static function classifyClient(?string $userAgent, ?string $botId, string $endpoint = ''): string
    {
        $ua = trim((string)$userAgent);
        $uaLower = strtolower($ua);

        // 1. ESP32 / Arduino / MeshCore įrenginys
        if (
            str_contains($uaLower, 'esp32') ||
            str_contains($uaLower, 'esp8266') ||
            str_contains($uaLower, 'arduino') ||
            str_contains($uaLower, 'meshcore') ||
            str_contains($uaLower, 'httpclient')
        ) {
            return 'ESP32';
        }

        // 2. Žinomi paieškos / skenavimo botai ir crawleriai
        $crawlerKeywords = [
            'bot', 'crawler', 'spider', 'slurp', 'ahrefs', 'semrush', 'mj12bot',
            'dotbot', 'bingpreview', 'facebookexternalhit', 'telegrambot',
            'discordbot', 'whatsapp', 'twitterbot', 'linkedinbot', 'embedly',
            'bytespider', 'gptbot', 'claudebot', 'applebot', 'yandex', 'baiduspider',
            'petalbot', 'censys', 'zgrab', 'masscan', 'nmap', 'nikto', 'sqlmap'
        ];
        foreach ($crawlerKeywords as $kw) {
            if (str_contains($uaLower, $kw)) {
                return 'Crawler/Bot';
            }
        }

        // 3. CLI įrankiai, skriptai, bibliotekos
        $scriptKeywords = [
            'curl', 'wget', 'python', 'requests', 'urllib', 'httpx', 'aiohttp',
            'postman', 'insomnia', 'guzzle', 'axios', 'node-fetch', 'undici',
            'go-http-client', 'okhttp', 'java/', 'libwww', 'perl', 'ruby', 'php',
            'powershell', 'restsharp', 'apache-httpclient'
        ];
        foreach ($scriptKeywords as $kw) {
            if (str_contains($uaLower, $kw)) {
                return 'CLI/Script';
            }
        }

        // 4. Jei nėra User-Agent, bet kreipiamasi į /api/repeater arba /api/raw su bot_id
        if ($ua === '' && !empty($botId) && in_array($endpoint, ['/api/repeater', '/api/raw'], true)) {
            return 'ESP32';
        }

        // 5. Įprasta naršyklė
        if (
            str_contains($uaLower, 'mozilla/') &&
            (str_contains($uaLower, 'chrome/') || str_contains($uaLower, 'safari/') || str_contains($uaLower, 'firefox/') || str_contains($uaLower, 'edg/') || str_contains($uaLower, 'opera'))
        ) {
            return 'Browser';
        }

        if ($ua === '') {
            return 'Empty-UA';
        }

        return 'Unknown';
    }

    /**
     * Įrašo API užklausos logą į duomenų bazę
     */
    public function logRequest(array $data): void
    {
        try {
            $this->ensureTableExists();

            $ipAddress = substr(trim((string)($data['ip_address'] ?? '')), 0, 64);
            $method = strtoupper(substr(trim((string)($data['method'] ?? 'GET')), 0, 12));
            $endpoint = substr(trim((string)($data['endpoint'] ?? '')), 0, 64);
            $requestUri = substr(trim((string)($data['request_uri'] ?? '')), 0, 1024);
            $botId = isset($data['bot_id']) && trim((string)$data['bot_id']) !== ''
                ? strtoupper(substr(trim((string)$data['bot_id']), 0, 32))
                : null;
            $userAgent = isset($data['user_agent']) ? substr((string)$data['user_agent'], 0, 2000) : null;
            $clientType = $data['client_type'] ?? self::classifyClient($userAgent, $botId, $endpoint);
            $statusCode = (int)($data['status_code'] ?? 200);
            $durationMs = isset($data['duration_ms']) ? (int)$data['duration_ms'] : null;
            $responseSize = isset($data['response_size']) ? (int)$data['response_size'] : null;

            // Apribojame saugomų body dydį (pvz. iki 32 KB), kad DB neišsipūstų
            $requestBody = isset($data['request_body']) && $data['request_body'] !== ''
                ? substr((string)$data['request_body'], 0, 32768)
                : null;
            $responseBody = isset($data['response_body']) && $data['response_body'] !== ''
                ? substr((string)$data['response_body'], 0, 32768)
                : null;
            $headersJson = isset($data['headers_json']) && $data['headers_json'] !== ''
                ? substr((string)$data['headers_json'], 0, 8192)
                : null;

            $stmt = $this->pdo->prepare("
                INSERT INTO api_logs (
                    ip_address, method, endpoint, request_uri, bot_id,
                    user_agent, client_type, status_code, duration_ms,
                    response_size, request_body, response_body, headers_json
                ) VALUES (
                    :ip_address, :method, :endpoint, :request_uri, :bot_id,
                    :user_agent, :client_type, :status_code, :duration_ms,
                    :response_size, :request_body, :response_body, :headers_json
                )
            ");

            $stmt->execute([
                ':ip_address' => $ipAddress,
                ':method' => $method,
                ':endpoint' => $endpoint,
                ':request_uri' => $requestUri,
                ':bot_id' => $botId,
                ':user_agent' => $userAgent,
                ':client_type' => $clientType,
                ':status_code' => $statusCode,
                ':duration_ms' => $durationMs,
                ':response_size' => $responseSize,
                ':request_body' => $requestBody,
                ':response_body' => $responseBody,
                ':headers_json' => $headersJson,
            ]);

            // Kartais (1 iš 100 užklausų) automatiškai apvalome įrašus, senesnius nei 14 d. arba viršijančius 15000 eilučių
            if (mt_rand(1, 100) === 1) {
                $this->pruneOldLogs(15000);
            }
        } catch (\Throwable $e) {
            // Niekada nesugriauname pagrindinio API atsakymo dėl logo klaidos
        }
    }

    /**
     * Automatinis senų įrašų apvalymas, kad lentelė neaugtų be ribų
     */
    public function pruneOldLogs(int $keepLatest = 15000): void
    {
        try {
            if ($this->getDriver() === 'pgsql') {
                $stmt = $this->pdo->prepare("
                    DELETE FROM api_logs
                    WHERE id < COALESCE((
                        SELECT id FROM api_logs ORDER BY id DESC OFFSET :offset LIMIT 1
                    ), 0)
                ");
                $stmt->bindValue(':offset', $keepLatest, PDO::PARAM_INT);
                $stmt->execute();
            } else {
                $stmt = $this->pdo->prepare("
                    SELECT id FROM api_logs ORDER BY id DESC LIMIT 1 OFFSET :offset
                ");
                $stmt->bindValue(':offset', $keepLatest, PDO::PARAM_INT);
                $stmt->execute();
                $cutoffId = $stmt->fetchColumn();
                if ($cutoffId) {
                    $delStmt = $this->pdo->prepare("DELETE FROM api_logs WHERE id < :cutoff_id");
                    $delStmt->execute([':cutoff_id' => (int)$cutoffId]);
                }
            }
        } catch (\Throwable $e) {}
    }

    /**
     * Grąžina filtruotą ir puslapiuotą API užklausų sąrašą
     */
    public function getFilteredLogs(
        array $filters = [],
        int $limit = 50,
        int $offset = 0,
        ?string $sortBy = 'id',
        ?string $sortDir = 'DESC'
    ): array {
        $this->ensureTableExists();

        $where = [];
        $params = [];

        if (!empty($filters['search'])) {
            $s = '%' . trim((string)$filters['search']) . '%';
            $where[] = "(
                ip_address LIKE :s_ip
                OR request_uri LIKE :s_uri
                OR endpoint LIKE :s_ep
                OR COALESCE(user_agent, '') LIKE :s_ua
                OR COALESCE(bot_id, '') LIKE :s_bot
                OR COALESCE(client_type, '') LIKE :s_ct
                OR COALESCE(response_body, '') LIKE :s_resp
                OR COALESCE(request_body, '') LIKE :s_req
            )";
            $params[':s_ip'] = $s;
            $params[':s_uri'] = $s;
            $params[':s_ep'] = $s;
            $params[':s_ua'] = $s;
            $params[':s_bot'] = $s;
            $params[':s_ct'] = $s;
            $params[':s_resp'] = $s;
            $params[':s_req'] = $s;
        }

        if (!empty($filters['ip'])) {
            $ipVal = trim((string)$filters['ip']);
            if (str_contains($ipVal, '*') || str_contains($ipVal, '%')) {
                $where[] = "ip_address LIKE :f_ip";
                $params[':f_ip'] = str_replace('*', '%', $ipVal);
            } else {
                $where[] = "ip_address LIKE :f_ip";
                $params[':f_ip'] = '%' . $ipVal . '%';
            }
        }

        if (!empty($filters['endpoint']) && $filters['endpoint'] !== 'all') {
            $where[] = "endpoint = :f_endpoint";
            $params[':f_endpoint'] = trim((string)$filters['endpoint']);
        }

        if (!empty($filters['method']) && $filters['method'] !== 'all') {
            $where[] = "method = :f_method";
            $params[':f_method'] = strtoupper(trim((string)$filters['method']));
        }

        if (!empty($filters['client_type']) && $filters['client_type'] !== 'all') {
            $ct = trim((string)$filters['client_type']);
            if ($ct === 'non_esp32') {
                $where[] = "client_type != 'ESP32'";
            } elseif ($ct === 'suspicious') {
                $where[] = "client_type IN ('Crawler/Bot', 'CLI/Script', 'Empty-UA', 'Unknown')";
            } else {
                $where[] = "client_type = :f_client_type";
                $params[':f_client_type'] = $ct;
            }
        }

        if (!empty($filters['bot_id']) && $filters['bot_id'] !== 'all') {
            $bId = trim((string)$filters['bot_id']);
            if ($bId === '__NONE__') {
                $where[] = "(bot_id IS NULL OR bot_id = '')";
            } else {
                $where[] = "bot_id = :f_bot_id";
                $params[':f_bot_id'] = strtoupper($bId);
            }
        }

        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $st = trim((string)$filters['status']);
            if ($st === '2xx') {
                $where[] = "(status_code >= 200 AND status_code < 300)";
            } elseif ($st === '4xx') {
                $where[] = "(status_code >= 400 AND status_code < 500)";
            } elseif ($st === '5xx') {
                $where[] = "status_code >= 500";
            } elseif ($st === 'err') {
                $where[] = "status_code >= 400";
            } elseif (is_numeric($st)) {
                $where[] = "status_code = :f_status";
                $params[':f_status'] = (int)$st;
            }
        }

        $whereSql = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

        // Count query
        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM api_logs {$whereSql}");
        foreach ($params as $k => $v) {
            $countStmt->bindValue($k, $v);
        }
        $countStmt->execute();
        $totalCount = (int)$countStmt->fetchColumn();

        $allowedSort = [
            'id' => 'id',
            'created_at' => 'created_at',
            'ip_address' => 'ip_address',
            'method' => 'method',
            'endpoint' => 'endpoint',
            'client_type' => 'client_type',
            'bot_id' => 'bot_id',
            'status_code' => 'status_code',
            'duration_ms' => 'duration_ms',
            'response_size' => 'response_size',
        ];
        $sortCol = $allowedSort[$sortBy ?? 'id'] ?? 'id';
        $dir = strtoupper((string)$sortDir) === 'ASC' ? 'ASC' : 'DESC';

        // Select su trumpesniu response_preview sąrašui (kad greitai krautųsi)
        $sql = "
            SELECT
                id,
                ip_address,
                method,
                endpoint,
                request_uri,
                bot_id,
                user_agent,
                client_type,
                status_code,
                duration_ms,
                response_size,
                SUBSTRING(request_body, 1, 300) AS request_preview,
                SUBSTRING(response_body, 1, 400) AS response_preview,
                created_at
            FROM api_logs
            {$whereSql}
            ORDER BY {$sortCol} {$dir}, id DESC
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'items' => $items,
            'total_filtered' => $totalCount,
        ];
    }

    /**
     * Grąžina vieno API logo pilną informaciją (su pilnu request/response body ir headeriais)
     */
    public function getLogDetail(int $id): ?array
    {
        $this->ensureTableExists();

        $stmt = $this->pdo->prepare("SELECT * FROM api_logs WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Grąžina statistiką, unikalius endpointus ir aktyviausius IP adresus
     */
    public function getLogStats(): array
    {
        $this->ensureTableExists();

        $stats = [
            'total' => 0,
            'unique_ips' => 0,
            'esp32_count' => 0,
            'browser_count' => 0,
            'bot_script_count' => 0,
            'error_count' => 0,
            'top_ips' => [],
            'endpoints' => [],
        ];

        try {
            $summaryStmt = $this->pdo->query("
                SELECT
                    COUNT(*) AS total,
                    COUNT(DISTINCT ip_address) AS unique_ips,
                    SUM(CASE WHEN client_type = 'ESP32' THEN 1 ELSE 0 END) AS esp32_count,
                    SUM(CASE WHEN client_type = 'Browser' THEN 1 ELSE 0 END) AS browser_count,
                    SUM(CASE WHEN client_type IN ('Crawler/Bot', 'CLI/Script', 'Empty-UA', 'Unknown') THEN 1 ELSE 0 END) AS bot_script_count,
                    SUM(CASE WHEN status_code >= 400 THEN 1 ELSE 0 END) AS error_count
                FROM api_logs
            ");
            $row = $summaryStmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $stats['total'] = (int)($row['total'] ?? 0);
                $stats['unique_ips'] = (int)($row['unique_ips'] ?? 0);
                $stats['esp32_count'] = (int)($row['esp32_count'] ?? 0);
                $stats['browser_count'] = (int)($row['browser_count'] ?? 0);
                $stats['bot_script_count'] = (int)($row['bot_script_count'] ?? 0);
                $stats['error_count'] = (int)($row['error_count'] ?? 0);
            }

            // Aktyviausi IP adresai (Top 12)
            $topIpsStmt = $this->pdo->query("
                SELECT
                    ip_address,
                    COUNT(*) AS req_count,
                    MAX(client_type) AS main_client_type,
                    MAX(bot_id) AS last_bot_id,
                    MAX(user_agent) AS sample_ua,
                    MAX(created_at) AS last_seen_at
                FROM api_logs
                GROUP BY ip_address
                ORDER BY req_count DESC
                LIMIT 12
            ");
            $stats['top_ips'] = $topIpsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // Unikalūs endpointai filtravimui
            $epStmt = $this->pdo->query("
                SELECT endpoint, COUNT(*) AS cnt
                FROM api_logs
                WHERE endpoint != ''
                GROUP BY endpoint
                ORDER BY cnt DESC
            ");
            $stats['endpoints'] = $epStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {}

        return $stats;
    }

    /**
     * Išvalo API logus (visus arba tik tam tikro tipo)
     */
    public function clearLogs(?string $mode = 'all'): int
    {
        $this->ensureTableExists();

        if ($mode === 'esp32') {
            $stmt = $this->pdo->prepare("DELETE FROM api_logs WHERE client_type = 'ESP32'");
            $stmt->execute();
            return $stmt->rowCount();
        }

        if ($mode === 'older_24h') {
            if ($this->getDriver() === 'pgsql') {
                $stmt = $this->pdo->prepare("DELETE FROM api_logs WHERE created_at < NOW() - INTERVAL '24 HOURS'");
            } else {
                $stmt = $this->pdo->prepare("DELETE FROM api_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
            }
            $stmt->execute();
            return $stmt->rowCount();
        }

        $stmt = $this->pdo->prepare("DELETE FROM api_logs");
        $stmt->execute();
        return $stmt->rowCount();
    }
}
