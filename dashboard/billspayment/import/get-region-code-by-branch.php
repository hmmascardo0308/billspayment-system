<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../../../config/config.php';
session_start();
@include_once __DIR__ . '/../../../templates/middleware.php';

function region_json_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

$id = function_exists('resolve_user_identifier') ? resolve_user_identifier() : null;
if (empty($id)) {
    region_json_response(['success' => false, 'error' => 'Unauthorized'], 401);
}

if (!function_exists('has_any_permission') || !has_any_permission(['Import Transaction', 'Bills Payment'])) {
    region_json_response(['success' => false, 'error' => 'Forbidden'], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    region_json_response(['success' => false, 'error' => 'Method not allowed'], 405);
}

$input = json_decode((string)file_get_contents('php://input'), true);
$branchIds = [];
$regionNames = [];
$kpxRegionNames = [];
$branchCodeLookups = [];

if (isset($input['branch_ids']) && is_array($input['branch_ids'])) {
    $branchIds = $input['branch_ids'];
} elseif (isset($_POST['branch_ids']) && is_array($_POST['branch_ids'])) {
    $branchIds = $_POST['branch_ids'];
} elseif (isset($_POST['branch_id'])) {
    $branchIds = [$_POST['branch_id']];
}

if (isset($input['region_names']) && is_array($input['region_names'])) {
    $regionNames = $input['region_names'];
} elseif (isset($_POST['region_names']) && is_array($_POST['region_names'])) {
    $regionNames = $_POST['region_names'];
}

if (isset($input['kpx_region_names']) && is_array($input['kpx_region_names'])) {
    $kpxRegionNames = $input['kpx_region_names'];
} elseif (isset($_POST['kpx_region_names']) && is_array($_POST['kpx_region_names'])) {
    $kpxRegionNames = $_POST['kpx_region_names'];
}

if (isset($input['branch_code_lookups']) && is_array($input['branch_code_lookups'])) {
    $branchCodeLookups = $input['branch_code_lookups'];
} elseif (isset($_POST['branch_code_lookups']) && is_array($_POST['branch_code_lookups'])) {
    $branchCodeLookups = $_POST['branch_code_lookups'];
}

$branchIds = array_values(array_unique(array_filter(array_map(static function ($branchId): string {
    return trim((string)$branchId);
}, $branchIds), static function (string $branchId): bool {
    return $branchId !== '';
})));

if (empty($branchIds)) {
    $branchIds = [];
}

$branches = [];
if (!empty($branchIds)) {
    $stmt = $conn->prepare('SELECT region_code, zone FROM masterdata.branch_profile WHERE branch_id = ? LIMIT 1');
    if (!$stmt) {
        region_json_response(['success' => false, 'error' => 'Unable to prepare branch lookup'], 500);
    }

    foreach ($branchIds as $branchId) {
        $stmt->bind_param('s', $branchId);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if ($result && $row = $result->fetch_assoc()) {
                $branches[$branchId] = [
                    'region_code' => $row['region_code'] ?? null,
                    'zone_code' => $row['zone'] ?? null,
                ];
            } else {
                $branches[$branchId] = [
                    'region_code' => null,
                    'zone_code' => null,
                ];
            }
        } else {
            $branches[$branchId] = [
                'region_code' => null,
                'zone_code' => null,
            ];
        }
    }

    $stmt->close();
}

$regionNames = array_values(array_unique(array_filter(array_map(static function ($regionName): string {
    return trim((string)$regionName);
}, $regionNames), static function (string $regionName): bool {
    return $regionName !== '';
})));

$regionNameMap = [];
if (!empty($regionNames)) {
    $regionStmt = $conn->prepare('SELECT region_code, zone_code FROM masterdata.region_masterfile WHERE region_desc_kp7 = ? LIMIT 1');
    if (!$regionStmt) {
        region_json_response(['success' => false, 'error' => 'Unable to prepare region lookup'], 500);
    }

    foreach ($regionNames as $regionName) {
        $regionStmt->bind_param('s', $regionName);
        if ($regionStmt->execute()) {
            $result = $regionStmt->get_result();
            if ($result && $row = $result->fetch_assoc()) {
                $regionNameMap[$regionName] = [
                    'region_code' => $row['region_code'] ?? null,
                    'zone_code' => $row['zone_code'] ?? null,
                ];
            } else {
                $regionNameMap[$regionName] = [
                    'region_code' => null,
                    'zone_code' => null,
                ];
            }
        } else {
            $regionNameMap[$regionName] = [
                'region_code' => null,
                'zone_code' => null,
            ];
        }
    }

    $regionStmt->close();
}

$kpxRegionNames = array_values(array_unique(array_filter(array_map(static function ($regionName): string {
    return trim((string)$regionName);
}, $kpxRegionNames), static function (string $regionName): bool {
    return $regionName !== '';
})));

$kpxRegionNameMap = [];
if (!empty($kpxRegionNames)) {
    $kpxRegionStmt = $conn->prepare('SELECT region_code, zone_code FROM masterdata.region_masterfile WHERE gl_region = ? LIMIT 1');
    if (!$kpxRegionStmt) {
        region_json_response(['success' => false, 'error' => 'Unable to prepare KPX region lookup'], 500);
    }

    foreach ($kpxRegionNames as $regionName) {
        $kpxRegionStmt->bind_param('s', $regionName);
        if ($kpxRegionStmt->execute()) {
            $result = $kpxRegionStmt->get_result();
            if ($result && $row = $result->fetch_assoc()) {
                $kpxRegionNameMap[$regionName] = [
                    'region_code' => $row['region_code'] ?? null,
                    'zone_code' => $row['zone_code'] ?? null,
                ];
            } else {
                $kpxRegionNameMap[$regionName] = [
                    'region_code' => null,
                    'zone_code' => null,
                ];
            }
        } else {
            $kpxRegionNameMap[$regionName] = [
                'region_code' => null,
                'zone_code' => null,
            ];
        }
    }

    $kpxRegionStmt->close();
}

$branchCodeMap = [];
if (!empty($branchCodeLookups)) {
    $branchCodeStmt = $conn->prepare('SELECT branch_id FROM masterdata.branch_profile WHERE code = ? AND region_code = ? LIMIT 1');
    if (!$branchCodeStmt) {
        region_json_response(['success' => false, 'error' => 'Unable to prepare branch code lookup'], 500);
    }

    foreach ($branchCodeLookups as $lookup) {
        if (!is_array($lookup)) continue;

        $code = trim((string)($lookup['code'] ?? ''));
        $regionCode = trim((string)($lookup['region_code'] ?? ''));
        if ($code === '' || $regionCode === '') continue;

        $key = $code . '|' . $regionCode;
        if (array_key_exists($key, $branchCodeMap)) continue;

        $branchCodeStmt->bind_param('ss', $code, $regionCode);
        if ($branchCodeStmt->execute()) {
            $result = $branchCodeStmt->get_result();
            if ($result && $row = $result->fetch_assoc()) {
                $branchCodeMap[$key] = [
                    'branch_id' => $row['branch_id'] ?? null,
                ];
            } else {
                $branchCodeMap[$key] = [
                    'branch_id' => null,
                ];
            }
        } else {
            $branchCodeMap[$key] = [
                'branch_id' => null,
            ];
        }
    }

    $branchCodeStmt->close();
}

region_json_response([
    'success' => true,
    'branches' => $branches,
    'region_names' => $regionNameMap,
    'kpx_region_names' => $kpxRegionNameMap,
    'branch_codes' => $branchCodeMap,
    'regions' => array_map(static function (array $branch): ?string {
        return $branch['region_code'];
    }, $branches),
]);
