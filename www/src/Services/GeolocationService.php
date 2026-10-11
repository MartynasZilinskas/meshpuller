<?php

namespace MeshCore\Services;

use MeshCore\Contracts\GeolocationServiceInterface;
use MeshCore\Contracts\SettingsRepositoryInterface;
use MeshCore\Repositories\SettingsRepository;
use PDO;

/**
 * Class GeolocationService
 *
 * Atsakinga už Lietuvos teritorijos koordinačių patikrą, mazgų geofiltrą ir telemetrijos senumo filtrą pagal SRP.
 */
class GeolocationService implements GeolocationServiceInterface
{
    private SettingsRepositoryInterface $settingsRepo;
    private ?PDO $pdo;

    public function __construct(SettingsRepositoryInterface $settingsRepo, ?PDO $pdo = null)
    {
        $this->settingsRepo = $settingsRepo;
        $this->pdo          = $pdo;
    }

    /**
     * Patikrina ar geografinis taškas patenka į nustatytas Lietuvos ribas
     */
    public function isPointInLithuania(?float $lat, ?float $lon, ?array $settings = null): bool
    {
        if ($lat === null || $lon === null) {
            return false;
        }

        // (0, 0) koordinatės nėra reali geografinė vieta
        if (abs($lat) < 0.001 && abs($lon) < 0.001) {
            return false;
        }

        $cfg = $settings ?: $this->settingsRepo->getAllSettings();
        $latMin = (float)($cfg['lithuania_lat_min'] ?? SettingsRepository::DEFAULT_SYSTEM_SETTINGS['lithuania_lat_min']);
        $latMax = (float)($cfg['lithuania_lat_max'] ?? SettingsRepository::DEFAULT_SYSTEM_SETTINGS['lithuania_lat_max']);
        $lonMin = (float)($cfg['lithuania_lon_min'] ?? SettingsRepository::DEFAULT_SYSTEM_SETTINGS['lithuania_lon_min']);
        $lonMax = (float)($cfg['lithuania_lon_max'] ?? SettingsRepository::DEFAULT_SYSTEM_SETTINGS['lithuania_lon_max']);

        return ($lat >= $latMin && $lat <= $latMax && $lon >= $lonMin && $lon <= $lonMax);
    }

    /**
     * Filtruoja mazgų sąrašą pagal Lietuvos geolokaciją prieš formuojant JSON
     */
    public function filterNodesByGeolocationSettings(array $nodes): array
    {
        $settings = $this->settingsRepo->getAllSettings();

        // Jei filtras išjungtas admine – grąžiname visus mazgus nepakeistus
        if (empty($settings['filter_lithuania_enabled'])) {
            return $nodes;
        }

        $includeNoCoords = !empty($settings['filter_lithuania_include_no_coords']);

        $filtered = [];
        foreach ($nodes as $node) {
            $lat = isset($node['latitude']) && $node['latitude'] !== null ? (float)$node['latitude'] : null;
            $lon = isset($node['longitude']) && $node['longitude'] !== null ? (float)$node['longitude'] : null;

            if ($lat === null || $lon === null || (abs($lat) < 0.001 && abs($lon) < 0.001)) {
                if ($includeNoCoords) {
                    $filtered[] = $node;
                }
                continue;
            }

            if ($this->isPointInLithuania($lat, $lon, $settings)) {
                $filtered[] = $node;
            }
        }

        return array_values($filtered);
    }

    /**
     * Filtruoja mazgus pagal telemetrijos senaties limitą dienomis.
     */
    public function filterNodesByTelemetryAgeSettings(array $nodes, ?array $settings = null): array
    {
        $settings = $settings ?: $this->settingsRepo->getAllSettings();
        $maxDays = isset($settings['max_telemetry_age_days']) ? (int)$settings['max_telemetry_age_days'] : 0;

        if ($maxDays <= 0) {
            return $nodes;
        }

        $cutoffTimestamp = time() - ($maxDays * 86400);
        $filtered = [];

        foreach ($nodes as $node) {
            $telemTimeStr = $node['telemetry_updated_at'] ?? null;
            if (!empty($telemTimeStr)) {
                $telemTimestamp = strtotime($telemTimeStr);
                if ($telemTimestamp && $telemTimestamp < $cutoffTimestamp) {
                    continue;
                }
            } else {
                $firstSeenStr = $node['first_seen_at'] ?? null;
                $firstSeenTimestamp = !empty($firstSeenStr) ? strtotime($firstSeenStr) : 0;
                if ($firstSeenTimestamp > 0 && $firstSeenTimestamp < $cutoffTimestamp) {
                    continue;
                } elseif ($firstSeenTimestamp === 0) {
                    continue;
                }
            }

            $filtered[] = $node;
        }

        return array_values($filtered);
    }

    /**
     * Grąžina geolokacijos filtro statistiką administratoriaus pultui
     */
    public function getGeolocationFilterStats(): array
    {
        $settings = $this->settingsRepo->getAllSettings();
        $total = 0;
        $inLt = 0;
        $outsideLt = 0;
        $noCoords = 0;

        if ($this->pdo) {
            try {
                $stmt = $this->pdo->query("SELECT latitude, longitude FROM nodes");
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $total++;
                    $lat = $row['latitude'] !== null ? (float)$row['latitude'] : null;
                    $lon = $row['longitude'] !== null ? (float)$row['longitude'] : null;

                    if ($lat === null || $lon === null || (abs($lat) < 0.001 && abs($lon) < 0.001)) {
                        $noCoords++;
                    } elseif ($this->isPointInLithuania($lat, $lon, $settings)) {
                        $inLt++;
                    } else {
                        $outsideLt++;
                    }
                }
            } catch (\Throwable $e) {}
        }

        return [
            'settings'         => $settings,
            'total_nodes'      => $total,
            'nodes_in_lt'      => $inLt,
            'nodes_outside_lt' => $outsideLt,
            'nodes_no_coords'  => $noCoords,
        ];
    }
}
