<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../../../config/config.php';
session_start();
@include_once __DIR__ . '/../../../templates/middleware.php';

function partner_json_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

$id = function_exists('resolve_user_identifier') ? resolve_user_identifier() : null;
if (empty($id)) {
    partner_json_response(['success' => false, 'error' => 'Unauthorized'], 401);
}

if (!function_exists('has_any_permission') || !has_any_permission(['Import Transaction', 'Bills Payment'])) {
    partner_json_response(['success' => false, 'error' => 'Forbidden'], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    partner_json_response(['success' => false, 'error' => 'Method not allowed'], 405);
}

$input = json_decode((string)file_get_contents('php://input'), true);
$partners = isset($input['partners']) && is_array($input['partners']) ? $input['partners'] : [];

if (empty($partners)) {
    partner_json_response(['success' => true, 'partners' => []]);
}

$lookupResults = [];
$stmtByPartnerId = $conn->prepare('SELECT partner_id_kpx, gl_code FROM masterdata.partner_masterfile WHERE partner_id = ? LIMIT 1');
$stmtByKpx = $conn->prepare('SELECT partner_id, gl_code FROM masterdata.partner_masterfile WHERE partner_id_kpx = ? LIMIT 1');
$stmtByName = $conn->prepare('SELECT partner_id, partner_id_kpx, gl_code FROM masterdata.partner_masterfile WHERE tg_partner_name = ? LIMIT 1');

if (!$stmtByPartnerId || !$stmtByKpx || !$stmtByName) {
    partner_json_response(['success' => false, 'error' => 'Unable to prepare partner lookup'], 500);
}

foreach ($partners as $partner) {
    if (!is_array($partner)) continue;

    $key = trim((string)($partner['key'] ?? ''));
    $sourceType = strtoupper(trim((string)($partner['source_type'] ?? '')));
    $partnerId = trim((string)($partner['partner_id'] ?? ''));
    $partnerIdKpx = trim((string)($partner['partner_id_kpx'] ?? ''));
    $partnerName = trim((string)($partner['partner_name'] ?? ''));

    if ($key === '') {
        $key = $sourceType === 'KP7' && $partnerId !== '' ? 'kp7:' . $partnerId : ($partnerIdKpx !== '' ? 'kpx:' . $partnerIdKpx : 'name:' . $partnerName);
    }

    $lookupResults[$key] = [
        'partner_id' => $partnerId !== '' ? $partnerId : null,
        'partner_id_kpx' => $partnerIdKpx !== '' ? $partnerIdKpx : null,
        'gl_code' => null,
    ];

    if ($sourceType === 'KP7' && $partnerId !== '') {
        $stmtByPartnerId->bind_param('s', $partnerId);
        if ($stmtByPartnerId->execute()) {
            $result = $stmtByPartnerId->get_result();
            if ($result && $row = $result->fetch_assoc()) {
                $lookupResults[$key]['partner_id'] = $partnerId;
                $lookupResults[$key]['partner_id_kpx'] = $row['partner_id_kpx'] ?? null;
                $lookupResults[$key]['gl_code'] = $row['gl_code'] ?? null;
                continue;
            }
        }
    }

    if ($partnerIdKpx !== '') {
        $stmtByKpx->bind_param('s', $partnerIdKpx);
        if ($stmtByKpx->execute()) {
            $result = $stmtByKpx->get_result();
            if ($result && $row = $result->fetch_assoc()) {
                $lookupResults[$key]['partner_id'] = $row['partner_id'] ?? null;
                $lookupResults[$key]['gl_code'] = $row['gl_code'] ?? null;
                continue;
            }
        }
    }

    if ($partnerName !== '') {
        $stmtByName->bind_param('s', $partnerName);
        if ($stmtByName->execute()) {
            $result = $stmtByName->get_result();
            if ($result && $row = $result->fetch_assoc()) {
                $lookupResults[$key]['partner_id'] = $row['partner_id'] ?? null;
                $lookupResults[$key]['partner_id_kpx'] = $row['partner_id_kpx'] ?? null;
                $lookupResults[$key]['gl_code'] = $row['gl_code'] ?? null;
            }
        }
    }
}

$stmtByPartnerId->close();
$stmtByKpx->close();
$stmtByName->close();

partner_json_response([
    'success' => true,
    'partners' => $lookupResults,
]);
