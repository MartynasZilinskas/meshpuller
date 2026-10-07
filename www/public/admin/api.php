<?php

require_once __DIR__ . '/config.php';

// Dinamiškai nustatome šakninį katalogą (tiek www/admin, tiek www/public/admin atveju)
$possibleSrc = [
    __DIR__ . '/../src',
    __DIR__ . '/../../src',
];
$possibleVendor = [
    __DIR__ . '/../vendor',
    __DIR__ . '/../../vendor',
];

$srcDir = null;
foreach ($possibleSrc as $dir) {
    if (file_exists($dir . '/MeshCoreDecoder.php')) {
        $srcDir = $dir;
        break;
    }
}

$vendorDir = null;
foreach ($possibleVendor as $dir) {
    if (file_exists($dir . '/autoload.php')) {
        $vendorDir = $dir;
        break;
    }
}

if ($vendorDir) {
    require_once $vendorDir . '/autoload.php';
} elseif ($srcDir) {
    require_once $srcDir . '/MeshCoreDecoder.php';
    require_once $srcDir . '/RawPacketRepository.php';
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Nepavyko rasti MeshCore klasių katalogo']);
    exit;
}

use MeshCore\MeshCoreDecoder;
use MeshCore\RawPacketRepository;

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// -----------------------------------------------------------------------------
// 1. Vieši veiksmai (Prisijungimas / Būsenos patikra)
// -----------------------------------------------------------------------------
if ($action === 'check_auth') {
    echo json_encode([
        'success'       => true,
        'authenticated' => isAdminLoggedIn(),
        'login_time'    => $_SESSION['meshcore_admin_login_time'] ?? null
    ]);
    exit;
}

if ($action === 'login') {
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Metodas nepalaikomas']);
        exit;
    }

    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true) ?: $_POST;
    $password = trim($data['password'] ?? '');

    if (empty($password)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Įveskite administratoriaus slaptažodį']);
        exit;
    }

    if (adminLogin($password)) {
        echo json_encode([
            'success' => true,
            'message' => 'Sėkmingai prisijungta prie administravimo pulto'
        ]);
    } else {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error'   => 'Neteisingas administratoriaus slaptažodis!'
        ]);
    }
    exit;
}

if ($action === 'logout') {
    adminLogout();
    echo json_encode(['success' => true, 'message' => 'Atsijungta']);
    exit;
}

// -----------------------------------------------------------------------------
// 2. Visi tolesni veiksmai reikalauja aktyvios administratoriaus sesijos
// -----------------------------------------------------------------------------
requireAdminAuthJson();

$repository = null;
$decoder = new MeshCoreDecoder();

try {
    $repository = new RawPacketRepository();
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Nepavyko prisijungti prie duomenų bazės: ' . $e->getMessage()
    ]);
    exit;
}

// Duomenų bazės / sistemos statistika
if ($action === 'stats') {
    try {
        $nodeStats = $repository->getNodeStats();
        $rawStats  = $repository->getStats();
        $telStats  = $repository->getTelemetryStats();

        echo json_encode([
            'success'   => true,
            'nodes'     => $nodeStats,
            'raw'       => $rawStats,
            'telemetry' => $telStats
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// -----------------------------------------------------------------------------
// ⚙️ Sistemos ir geolokacijos nustatymų valdymas
// -----------------------------------------------------------------------------
if ($action === 'settings') {
    try {
        $geoStats = $repository->getGeolocationFilterStats();
        echo json_encode([
            'success'  => true,
            'settings' => $geoStats['settings'],
            'stats'    => [
                'total_nodes'      => $geoStats['total_nodes'],
                'nodes_in_lt'      => $geoStats['nodes_in_lt'],
                'nodes_outside_lt' => $geoStats['nodes_outside_lt'],
                'nodes_no_coords'  => $geoStats['nodes_no_coords']
            ]
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'save_settings') {
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Metodas nepalaikomas']);
        exit;
    }

    try {
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true) ?: $_POST;

        $newSettings = [];
        if (isset($data['enable_source_routing'])) {
            $newSettings['enable_source_routing'] = ($data['enable_source_routing'] === true || $data['enable_source_routing'] === '1' || $data['enable_source_routing'] === 1);
        }
        if (isset($data['filter_lithuania_enabled'])) {
            $newSettings['filter_lithuania_enabled'] = ($data['filter_lithuania_enabled'] === true || $data['filter_lithuania_enabled'] === '1' || $data['filter_lithuania_enabled'] === 1);
        }
        if (isset($data['filter_lithuania_include_no_coords'])) {
            $newSettings['filter_lithuania_include_no_coords'] = ($data['filter_lithuania_include_no_coords'] === true || $data['filter_lithuania_include_no_coords'] === '1' || $data['filter_lithuania_include_no_coords'] === 1);
        }
        if (isset($data['lithuania_lat_min']) && is_numeric($data['lithuania_lat_min'])) {
            $newSettings['lithuania_lat_min'] = (float)$data['lithuania_lat_min'];
        }
        if (isset($data['lithuania_lat_max']) && is_numeric($data['lithuania_lat_max'])) {
            $newSettings['lithuania_lat_max'] = (float)$data['lithuania_lat_max'];
        }
        if (isset($data['lithuania_lon_min']) && is_numeric($data['lithuania_lon_min'])) {
            $newSettings['lithuania_lon_min'] = (float)$data['lithuania_lon_min'];
        }
        if (isset($data['lithuania_lon_max']) && is_numeric($data['lithuania_lon_max'])) {
            $newSettings['lithuania_lon_max'] = (float)$data['lithuania_lon_max'];
        }
        if (isset($data['max_telemetry_fails']) && is_numeric($data['max_telemetry_fails'])) {
            $newSettings['max_telemetry_fails'] = max(0, (int)$data['max_telemetry_fails']);
        }
        if (isset($data['min_telemetry_interval_mins']) && is_numeric($data['min_telemetry_interval_mins'])) {
            $newSettings['min_telemetry_interval_mins'] = max(0, (int)$data['min_telemetry_interval_mins']);
        }
        if (isset($data['min_retry_interval_mins']) && is_numeric($data['min_retry_interval_mins'])) {
            $newSettings['min_retry_interval_mins'] = max(1, (int)$data['min_retry_interval_mins']);
        }
        if (isset($data['telemetry_chart_days']) && is_numeric($data['telemetry_chart_days'])) {
            $newSettings['telemetry_chart_days'] = max(1, min(365, (int)$data['telemetry_chart_days']));
        }
        if (isset($data['auto_add_new_repeaters'])) {
            $newSettings['auto_add_new_repeaters'] = ($data['auto_add_new_repeaters'] === true || $data['auto_add_new_repeaters'] === '1' || $data['auto_add_new_repeaters'] === 1);
        }

        $saved = $repository->saveSettings($newSettings);
        $geoStats = $repository->getGeolocationFilterStats();

        echo json_encode([
            'success'  => true,
            'message'  => 'Nustatymai sėkmingai išsaugoti!',
            'settings' => $saved,
            'stats'    => [
                'total_nodes'      => $geoStats['total_nodes'],
                'nodes_in_lt'      => $geoStats['nodes_in_lt'],
                'nodes_outside_lt' => $geoStats['nodes_outside_lt'],
                'nodes_no_coords'  => $geoStats['nodes_no_coords']
            ]
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Mazgų sąrašas su filtrais
if ($action === 'nodes') {
    $filters = [
        'search' => $_GET['search'] ?? null,
        'type'   => $_GET['type'] ?? null,
        'update' => isset($_GET['update']) && $_GET['update'] !== '' ? $_GET['update'] : null,
    ];
    $limit   = max(1, min(500, (int)($_GET['limit'] ?? 50)));
    $offset  = max(0, (int)($_GET['offset'] ?? 0));
    $sortBy  = $_GET['sort_by'] ?? null;
    $sortDir = $_GET['sort_dir'] ?? 'desc';

    try {
        $result = $repository->getFilteredNodes($filters, $limit, $offset, $sortBy, $sortDir);
        echo json_encode(array_merge(['success' => true], $result), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Mazgo nustatymų atnaujinimas
if ($action === 'update_node') {
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Metodas nepalaikomas']);
        exit;
    }

    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true) ?: $_POST;

    $publicKeyOrNodeId = trim($data['public_key'] ?? $data['node_id'] ?? '');
    if (empty($publicKeyOrNodeId)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Nenurodytas mazgo identifikatorius (public_key arba node_id)']);
        exit;
    }

    $fieldsToUpdate = [];
    if (array_key_exists('node_name', $data)) {
        $fieldsToUpdate['node_name'] = $data['node_name'];
    }
    if (array_key_exists('password', $data)) {
        $fieldsToUpdate['password'] = $data['password'];
    }
    if (array_key_exists('update', $data)) {
        $fieldsToUpdate['update'] = (bool)$data['update'];
    }
    if (array_key_exists('is_repeater', $data)) {
        $fieldsToUpdate['is_repeater'] = (bool)$data['is_repeater'];
    }
    if (array_key_exists('latitude', $data)) {
        $fieldsToUpdate['latitude'] = $data['latitude'];
    }
    if (array_key_exists('longitude', $data)) {
        $fieldsToUpdate['longitude'] = $data['longitude'];
    }
    if (array_key_exists('telemetry_fail_count', $data)) {
        $fieldsToUpdate['telemetry_fail_count'] = max(0, (int)$data['telemetry_fail_count']);
    }
    if (array_key_exists('last_path', $data)) {
        $fieldsToUpdate['last_path'] = $data['last_path'];
    }

    try {
        $updated = $repository->updateNodeDetails($publicKeyOrNodeId, $fieldsToUpdate);
        if ($updated) {
            echo json_encode([
                'success' => true,
                'message' => 'Mazgo nustatymai sėkmingai išsaugoti!'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'error'   => 'Mazgas nerastas arba duomenys nepasikeitė'
            ]);
        }
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Telemetrijos įrašų sąrašas su filtrais
if ($action === 'telemetry') {
    $filters = [
        'node_id' => $_GET['node_id'] ?? null,
        'type'    => $_GET['type'] ?? null,
        'from'    => $_GET['from'] ?? null,
        'to'      => $_GET['to'] ?? null,
    ];
    $limit   = max(1, min(500, (int)($_GET['limit'] ?? 50)));
    $offset  = max(0, (int)($_GET['offset'] ?? 0));
    $sortBy  = $_GET['sort_by'] ?? null;
    $sortDir = $_GET['sort_dir'] ?? 'desc';

    try {
        $result = $repository->getFilteredTelemetry($filters, $limit, $offset, $sortBy, $sortDir);
        echo json_encode(array_merge(['success' => true], $result), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// RAW paketų sąrašas debugginimui
if ($action === 'raw_packets') {
    $filters = [
        'node'      => $_GET['node'] ?? null,
        'direction' => $_GET['direction'] ?? null,
        'status'    => $_GET['status'] ?? null,
        'search'    => $_GET['search'] ?? null,
    ];
    $limit   = max(1, min(500, (int)($_GET['limit'] ?? 50)));
    $offset  = max(0, (int)($_GET['offset'] ?? 0));
    $sortBy  = $_GET['sort_by'] ?? null;
    $sortDir = $_GET['sort_dir'] ?? 'desc';

    try {
        if (($filters['status'] ?? '') === 'errors' || $offset === 0) {
            $repository->syncErrorsFromDecodedJson(100);
        }
        $result = $repository->getFilteredRawPackets($filters, $limit, $offset, $sortBy, $sortDir);
        echo json_encode(array_merge(['success' => true], $result), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Vieno RAW paketo pilna informacija
if ($action === 'raw_packet_detail') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Nenurodytas paketo ID']);
        exit;
    }

    try {
        $packet = $repository->getRawPacketDetails($id);
        if ($packet) {
            // Iššifruojame JSON laukus jei jie saugomi kaip string
            if (isset($packet['payload_json']) && is_string($packet['payload_json'])) {
                $decPayload = json_decode($packet['payload_json'], true);
                $packet['payload_json'] = ($decPayload !== null) ? $decPayload : $packet['payload_json'];
            }
            if (isset($packet['decoded_json']) && is_string($packet['decoded_json'])) {
                $decDecoded = json_decode($packet['decoded_json'], true);
                $packet['decoded_json'] = ($decDecoded !== null) ? $decDecoded : $packet['decoded_json'];
            }

            // Užtikriname, kad net su sugadintais UTF-8 simboliais JSON bus suformuotas
            $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE;
            if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
                $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
            }

            $encoded = json_encode(['success' => true, 'packet' => $packet], $flags);
            if ($encoded === false) {
                // Fallback jei vis tiek kilo kodavimo klaida
                $cleanPacket = array_map(function($val) {
                    if (is_string($val)) {
                        return mb_convert_encoding($val, 'UTF-8', 'UTF-8');
                    }
                    return $val;
                }, $packet);
                echo json_encode(['success' => true, 'packet' => $cleanPacket], $flags);
            } else {
                echo $encoded;
            }
        } else {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => "Paketas #{$id} nerastas duomenų bazėje"]);
        }
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// RAW paketo pakartotinis procesinimas (Reprocess)
if ($action === 'reprocess_packet') {
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Metodas nepalaikomas']);
        exit;
    }

    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true) ?: $_POST;
    $id = (int)($data['id'] ?? $_GET['id'] ?? 0);

    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Nenurodytas paketo ID']);
        exit;
    }

    try {
        $processResult = $repository->processRecord($id, $decoder);
        echo json_encode([
            'success' => true,
            'message' => 'Paketas apdorotas iš naujo',
            'result'  => $processResult
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Eilės paketo apdorojimas (Batch Worker paleidimas per UI)
if ($action === 'process_queue_batch') {
    $limit = max(1, min(100, (int)($_POST['limit'] ?? $_GET['limit'] ?? 20)));
    $processed = 0;
    $results = [];

    try {
        for ($i = 0; $i < $limit; $i++) {
            $record = $repository->processNext($decoder);
            if ($record === null) {
                break;
            }
            $processed++;
            $results[] = [
                'id'         => $record['id'] ?? null,
                'status'     => $record['status'] ?? null,
                'error'      => $record['error'] ?? null,
                'type'       => $record['type'] ?? null,
                'direction'  => $record['direction'] ?? null,
            ];
        }

        echo json_encode([
            'success'   => true,
            'processed' => $processed,
            'results'   => $results
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// -----------------------------------------------------------------------------
// 📋 Kartotuvų telemetrijos užklausų eilė (/api/repeater)
// -----------------------------------------------------------------------------
if ($action === 'node_queue') {
    try {
        $botId = isset($_GET['bot_id']) && trim($_GET['bot_id']) !== '' ? trim($_GET['bot_id']) : null;
        $monitoredOnly = !isset($_GET['monitored_only']) || ($_GET['monitored_only'] === '1' || $_GET['monitored_only'] === 'true');
        $search = isset($_GET['search']) && trim($_GET['search']) !== '' ? trim($_GET['search']) : null;

        $queue = $repository->getNodePollingQueue($botId, $monitoredOnly, $search);
        $bots = $repository->getAvailableBotSources();

        $totalCount = count($queue);
        $pendingFirst = 0;
        $successCount = 0;
        $timeoutCount = 0;
        $blockedCount = 0;
        $rateLimitedCount = 0;

        $inProgressCount = 0;

        foreach ($queue as $item) {
            if (!empty($item['is_blocked'])) $blockedCount++;
            elseif (!empty($item['is_rate_limited'])) $rateLimitedCount++;
            elseif ($item['attempt_status'] === 'in_progress') $inProgressCount++;
            elseif ($item['attempt_status'] === 'never') $pendingFirst++;
            elseif ($item['attempt_status'] === 'success') $successCount++;
            elseif ($item['attempt_status'] === 'timeout') $timeoutCount++;
        }

        echo json_encode([
            'success'   => true,
            'count'     => $totalCount,
            'queue'     => $queue,
            'bots'      => $bots,
            'stats'     => [
                'total'         => $totalCount,
                'pending_first' => $pendingFirst,
                'in_progress'   => $inProgressCount,
                'success'       => $successCount,
                'timeout'       => $timeoutCount,
                'blocked'       => $blockedCount,
                'rate_limited'  => $rateLimitedCount
            ]
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// -----------------------------------------------------------------------------
// 🔄 Nuresetinti vieno mazgo requested_date (nukreipia į eilės pradžią)
// -----------------------------------------------------------------------------
if ($action === 'reset_requested_date') {
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Metodas nepalaikomas']);
        exit;
    }

    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true) ?: $_POST;
    $identifier = trim((string)($data['identifier'] ?? $data['node_id'] ?? $data['public_key'] ?? $_GET['identifier'] ?? ''));

    if ($identifier === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Nenurodytas mazgo identifikatorius (node_id arba public_key)']);
        exit;
    }

    try {
        $ok = $repository->resetNodeRequestedDate($identifier, true);
        if ($ok) {
            echo json_encode([
                'success' => true,
                'message' => 'Mazgas sėkmingai nukreiptas į eilės pradžią (requested_date išvalyta į NULL, fail=0)'
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode([
                'success' => false,
                'error'   => 'Nepavyko atnaujinti requested_date šiam mazgui'
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// -----------------------------------------------------------------------------
// ⚡ Nuresetinti visų mazgų requested_date
// -----------------------------------------------------------------------------
if ($action === 'reset_all_requested_dates') {
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Metodas nepalaikomas']);
        exit;
    }

    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true) ?: $_POST;
    $botId = isset($data['bot_id']) && trim($data['bot_id']) !== '' ? trim($data['bot_id']) : null;
    $monitoredOnly = !isset($data['monitored_only']) || ($data['monitored_only'] === true || $data['monitored_only'] === '1');

    try {
        $count = $repository->resetAllRequestedDates($botId, $monitoredOnly, true);
        echo json_encode([
            'success' => true,
            'message' => "Išvalyta {$count} mazgų requested_date data ir nuresetinti fail skaičiai. Eilė atstatyta.",
            'count'   => $count
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// -----------------------------------------------------------------------------
// 🧹 Nuresetinti vieno mazgo nesėkmingų bandymų (fail) skaičių į 0
// -----------------------------------------------------------------------------
if ($action === 'reset_node_fail_count') {
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Metodas nepalaikomas']);
        exit;
    }

    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true) ?: $_POST;
    $identifier = trim((string)($data['identifier'] ?? $data['node_id'] ?? $data['public_key'] ?? $_GET['identifier'] ?? ''));

    if ($identifier === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Nenurodytas mazgo identifikatorius (node_id arba public_key)']);
        exit;
    }

    try {
        $ok = $repository->resetNodeFailCount($identifier);
        if ($ok) {
            echo json_encode([
                'success' => true,
                'message' => 'Mazgo nesėkmingų bandymų (fail) skaičius sėkmingai nustatytas į 0'
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode([
                'success' => false,
                'error'   => 'Nepavyko nuresetinti fail skaičiaus šiam mazgui'
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// -----------------------------------------------------------------------------
// 🧹 Nuresetinti visų mazgų nesėkmingų bandymų (fail) skaičių į 0
// -----------------------------------------------------------------------------
if ($action === 'reset_all_fail_counts') {
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Metodas nepalaikomas']);
        exit;
    }

    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true) ?: $_POST;
    $botId = isset($data['bot_id']) && trim($data['bot_id']) !== '' ? trim($data['bot_id']) : null;

    try {
        $count = $repository->resetAllFailCounts($botId);
        echo json_encode([
            'success' => true,
            'message' => "Sėkmingai nuresetintas {$count} mazgų fail skaičius į 0. Mazgai vėl gali būti imami į eilę.",
            'count'   => $count
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Jei veiksmas neatpažintas
http_response_code(400);
echo json_encode(['success' => false, 'error' => "Neatpažintas veiksmas: '{$action}'"]);
