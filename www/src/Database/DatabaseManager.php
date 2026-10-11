<?php

namespace MeshCore\Database;

use MeshCore\Contracts\DatabaseConnectionInterface;
use PDO;
use PDOException;

/**
 * Class DatabaseManager
 *
 * Atsakinga už prisijungimo prie duomenų bazės (PostgreSQL / MySQL) užmezgimą,
 * konfigūracijos valdymą ir laiko juostos nustatymą pagal SRP.
 */
class DatabaseManager implements DatabaseConnectionInterface
{
    private PDO $pdo;
    private array $config;
    private string $driver;

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

        if ($pdo !== null) {
            $this->pdo = $pdo;
            $this->driver = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        } else {
            $this->connect();
        }
    }

    private function connect(): void
    {
        $availableDrivers = PDO::getAvailableDrivers();
        $configuredDriver = $this->config['driver'] ?? null;

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
            $this->driver = $driver;
            $this->configureTimezone();
        } catch (PDOException $e) {
            if ($driver === 'pgsql' && in_array('mysql', $availableDrivers)) {
                try {
                    $mysqlPort = ($port === 5432) ? 3306 : $port;
                    $mysqlDsn = "mysql:host={$host};port={$mysqlPort};dbname={$dbname};charset=utf8mb4";
                    $this->pdo = new PDO($mysqlDsn, $user, $pass, $options);
                    $this->driver = 'mysql';
                    $this->configureTimezone();
                    return;
                } catch (\Throwable $ignored) {
                }
            }
            throw new PDOException("Nepavyko prisijungti prie duomenų bazės [{$dbname} per {$driver}:{$port}]: " . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }

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
        }
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function getDriver(): string
    {
        if (empty($this->driver)) {
            $this->driver = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        }
        return $this->driver;
    }
}
