<?php
include_once __DIR__ . '/../../includes/bootstrap.php';
include_once __DIR__ . '/../../includes/ticket_queries.php';
global $conn;
st_require_login('../../../../login_form.php');

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    st_json(false, 'Invalid request method.', [], 405);
}

function st_trail_delta_base_prefix()
{
    $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    $pos = stripos($scriptName, '/dashboard/');
    if ($pos === false) {
        return '';
    }
    return substr($scriptName, 0, $pos);
}

function st_trail_delta_row_relevant($scope, $row, $userId)
{
    $scope = strtoupper((string) $scope);
    $handler = strtoupper(trim((string) ($row['current_handler_role'] ?? '')));
    $assignedTo = (int) ($row['assigned_to'] ?? 0);
    $createdBy = (int) ($row['created_by'] ?? 0);
    $vpoOwner = (int) ($row['vpo_owner'] ?? 0);
    $cadOwner = (int) ($row['cad_owner'] ?? 0);

    if ($scope === 'BRANCH') {
        return $userId !== null && $createdBy === (int) $userId;
    }

    if ($scope === 'VPO') {
        if ($userId === null) {
            return false;
        }
        if ($handler === 'VPO' && $assignedTo <= 0) {
            return true;
        }
        return (($handler === 'VPO' && $assignedTo === (int) $userId) || $vpoOwner === (int) $userId);
    }

    if ($scope === 'CAD') {
        if ($userId === null) {
            return false;
        }
        if ($handler === 'CAD' && $assignedTo <= 0) {
            return true;
        }
        return (($handler === 'CAD' && $assignedTo === (int) $userId) || $cadOwner === (int) $userId);
    }

    if ($scope === 'REPORT' || $scope === 'MAINT') {
        return true;
    }

    return false;
}

$scope = strtoupper(trim((string) ($_GET['scope'] ?? '')));
if (!in_array($scope, ['BRANCH', 'VPO', 'CAD', 'REPORT', 'MAINT'], true)) {
    st_json(false, 'Invalid scope.', [], 400);
}

if ($scope === 'BRANCH') {
    st_require_permission_api(['Support Ticket Create']);
} elseif ($scope === 'VPO') {
    st_require_permission_api(['Support Ticket VPO']);
} elseif ($scope === 'CAD') {
    st_require_permission_api(['Support Ticket CAD']);
} elseif ($scope === 'REPORT') {
    st_require_permission_api(['Support Ticket Report']);
} elseif ($scope === 'MAINT') {
    st_require_permission_api(['Support Ticket Report', 'Maintenance Support Ticket']);
}

$ticketId = (int) ($_GET['ticket_id'] ?? 0);
$sinceTrailId = (int) ($_GET['since_trail_id'] ?? 0);
if ($ticketId <= 0) {
    st_json(false, 'Invalid ticket id.', [], 400);
}
if ($sinceTrailId < 0) {
    $sinceTrailId = 0;
}

$userId = st_user_id_or_null();
$schema = st_schema();

$ticketSql = "SELECT id, ticket_number, current_handler_role, assigned_to, created_by, vpo_owner, cad_owner
              FROM {$schema}.tickets
              WHERE id = ?
              LIMIT 1";
$ticketStmt = $conn->prepare($ticketSql);
if (!$ticketStmt) {
    st_json(false, 'Unable to prepare ticket query.', [], 500);
}

$ticketStmt->bind_param('i', $ticketId);
if (!$ticketStmt->execute()) {
    $ticketStmt->close();
    st_json(false, 'Unable to fetch ticket.', [], 500);
}

$ticketRes = $ticketStmt->get_result();
$ticketRow = $ticketRes ? $ticketRes->fetch_assoc() : null;
$ticketStmt->close();

if (!$ticketRow) {
    st_json(true, 'No ticket found.', [
        'ticket_id' => $ticketId,
        'next_trail_id' => $sinceTrailId,
        'trail_deltas' => [],
    ]);
}

if (!st_trail_delta_row_relevant($scope, $ticketRow, $userId)) {
    st_json(true, 'Ticket not relevant to current scope.', [
        'ticket_id' => $ticketId,
        'next_trail_id' => $sinceTrailId,
        'trail_deltas' => [],
    ]);
}

$trailSql = "SELECT id AS trail_id, ticket_id, type, sender_role, target_role, message, meta, created_at
             FROM {$schema}.ticket_trails
             WHERE ticket_id = ? AND id > ?
             ORDER BY id ASC
             LIMIT 300";
$trailStmt = $conn->prepare($trailSql);
if (!$trailStmt) {
    st_json(false, 'Unable to prepare trail query.', [], 500);
}

$trailStmt->bind_param('ii', $ticketId, $sinceTrailId);
if (!$trailStmt->execute()) {
    $trailStmt->close();
    st_json(false, 'Unable to fetch trail deltas.', [], 500);
}

$trailRes = $trailStmt->get_result();
$rows = [];
$trailIds = [];
$nextTrailId = $sinceTrailId;
if ($trailRes) {
    while ($row = $trailRes->fetch_assoc()) {
        $trailId = (int) ($row['trail_id'] ?? 0);
        if ($trailId > $nextTrailId) {
            $nextTrailId = $trailId;
        }
        $rows[] = $row;
        if ($trailId > 0) {
            $trailIds[] = $trailId;
        }
    }
}
$trailStmt->close();

$attachmentsByTrail = [];
if (!empty($trailIds)) {
    $safeIds = [];
    foreach ($trailIds as $id) {
        $n = (int) $id;
        if ($n > 0) {
            $safeIds[] = $n;
        }
    }

    if (!empty($safeIds)) {
        $inClause = implode(',', $safeIds);
        $attSql = "SELECT id, ticket_trail_id, file_name, file_size
                   FROM {$schema}.ticket_attachments
                   WHERE ticket_trail_id IN ({$inClause})
                   ORDER BY id ASC";
        $attRes = $conn->query($attSql);
        if ($attRes) {
            $prefix = st_trail_delta_base_prefix();
            while ($att = $attRes->fetch_assoc()) {
                $trailId = (int) ($att['ticket_trail_id'] ?? 0);
                if ($trailId <= 0) {
                    continue;
                }
                if (!isset($attachmentsByTrail[$trailId])) {
                    $attachmentsByTrail[$trailId] = [];
                }

                $attId = (int) ($att['id'] ?? 0);
                $attachmentsByTrail[$trailId][] = [
                    'id' => $attId,
                    'file_name' => (string) ($att['file_name'] ?? 'Attachment'),
                    'file_size' => (string) ($att['file_size'] ?? ''),
                    'download_url' => $prefix . '/dashboard/support_ticket/controllers/attachments/download.php?id=' . $attId,
                ];
            }
        }
    }
}

$deltas = [];
foreach ($rows as $row) {
    $trailId = (int) ($row['trail_id'] ?? 0);
    $deltas[] = [
        'trail_id' => $trailId,
        'type' => (string) ($row['type'] ?? 'message'),
        'sender_role' => (string) ($row['sender_role'] ?? 'SYSTEM'),
        'message' => (string) ($row['message'] ?? ''),
        'created_at' => (string) ($row['created_at'] ?? ''),
        'attachments' => $attachmentsByTrail[$trailId] ?? [],
    ];
}

st_json(true, 'Trail deltas fetched.', [
    'ticket_id' => $ticketId,
    'next_trail_id' => $nextTrailId,
    'trail_deltas' => [
        $ticketId => $deltas,
    ],
]);
