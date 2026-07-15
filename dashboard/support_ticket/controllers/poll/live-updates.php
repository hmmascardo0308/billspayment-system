<?php
include_once __DIR__ . '/../../includes/bootstrap.php';
include_once __DIR__ . '/../../includes/ticket_queries.php';
global $conn;
st_require_login('../../../../login_form.php');

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    st_json(false, 'Invalid request method.', [], 405);
}

function st_live_parse_ticket_numbers($raw)
{
    $list = [];
    if (is_array($raw)) {
        $items = $raw;
    } else {
        $items = explode(',', (string) $raw);
    }

    foreach ($items as $item) {
        $v = strtoupper(trim((string) $item));
        if ($v === '') {
            continue;
        }
        if (!preg_match('/^[A-Z0-9_.-]+$/', $v)) {
            continue;
        }
        $list[$v] = true;
    }

    return array_keys($list);
}

function st_live_parse_open_state($raw)
{
    $map = [];
    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        return $map;
    }

    foreach ($decoded as $ticketId => $lastTrailId) {
        if (!is_numeric($ticketId) || !is_numeric($lastTrailId)) {
            continue;
        }
        $tid = (int) $ticketId;
        $lid = (int) $lastTrailId;
        if ($tid <= 0) {
            continue;
        }
        if ($lid < 0) {
            $lid = 0;
        }
        $map[$tid] = $lid;
    }

    return $map;
}

function st_live_base_prefix()
{
    $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    $pos = stripos($scriptName, '/dashboard/');
    if ($pos === false) {
        return '';
    }
    return substr($scriptName, 0, $pos);
}

function st_live_row_relevant($scope, $row, $userId)
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
        // Include unassigned VPO queue rows so open-mode state stays in sync.
        if ($handler === 'VPO' && $assignedTo <= 0) {
            return true;
        }
        return (($handler === 'VPO' && $assignedTo === (int) $userId) || $vpoOwner === (int) $userId);
    }

    if ($scope === 'CAD') {
        if ($userId === null) {
            return false;
        }
        // Include unassigned CAD queue rows so open-mode state stays in sync.
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

function st_live_get_ticket_statuses($conn, $scope, $ticketNumbers, $userId)
{
    $out = [];
    if (empty($ticketNumbers)) {
        return $out;
    }

    $schema = st_schema();
    $safeLiterals = [];
    foreach ($ticketNumbers as $tn) {
        $v = strtoupper(trim((string) $tn));
        if ($v === '' || !preg_match('/^[A-Z0-9_.-]+$/', $v)) {
            continue;
        }
        $safeLiterals[] = "'" . $conn->real_escape_string($v) . "'";
    }

    if (empty($safeLiterals)) {
        return $out;
    }

    $inClause = implode(',', $safeLiterals);
    $sql = "SELECT ticket_number, status, current_handler_role, assigned_to, created_by, vpo_owner, cad_owner
            FROM {$schema}.tickets
            WHERE UPPER(ticket_number) IN ({$inClause})";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return $out;
    }

    if (!$stmt->execute()) {
        $stmt->close();
        return $out;
    }

    $res = $stmt->get_result();
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $ticketNumber = strtoupper(trim((string) ($row['ticket_number'] ?? '')));
            if ($ticketNumber === '') {
                continue;
            }

            if (!st_live_row_relevant($scope, $row, $userId)) {
                continue;
            }

            $out[$ticketNumber] = [
                'status' => (string) ($row['status'] ?? ''),
                'handler_role' => (string) ($row['current_handler_role'] ?? ''),
                'assigned_to' => (int) ($row['assigned_to'] ?? 0),
            ];
        }
    }

    $stmt->close();
    return $out;
}

function st_live_notification_text($scope, $row, $attachmentCount)
{
    $scope = strtoupper((string) $scope);
    $sender = strtoupper(trim((string) ($row['sender_role'] ?? 'SYSTEM')));
    $type = strtolower(trim((string) ($row['type'] ?? 'message')));
    $ticketNumber = trim((string) ($row['ticket_number'] ?? ''));
    $message = trim((string) ($row['message'] ?? ''));
    $messageLower = strtolower($message);

    $meta = json_decode((string) ($row['meta'] ?? ''), true);
    if (!is_array($meta)) {
        $meta = [];
    }

    if ($sender === 'SYSTEM') {
        if (strpos($messageLower, 're-opened') !== false || strpos($messageLower, 'reopened') !== false) {
            return 'SYSTEM: Ticket ' . $ticketNumber . ' has been re-opened';
        }
        if (strpos($messageLower, 'deleted') !== false) {
            return 'SYSTEM: Ticket ' . $ticketNumber . ' has been deleted';
        }

        if ($scope === 'REPORT' || $scope === 'MAINT') {
            if ($message !== '') {
                return 'SYSTEM: ' . $message;
            }
        }

        return '';
    }

    $attachSuffix = ((int) $attachmentCount > 0) ? ' with attachment' : '';

    if ($scope === 'BRANCH') {
        if ($sender !== 'VPO' && $sender !== 'CAD') {
            return '';
        }

        if ($type === 'resolve') {
            return $sender . ': Resolved the ticket ' . $ticketNumber . ' and will be closed within 24 hours';
        }

        if ($type === 'close') {
            return $sender . ': Closed the ticket ' . $ticketNumber;
        }

        if ($type === 'message') {
            return $sender . ': Sent a new message' . $attachSuffix;
        }

        return '';
    }

    if ($scope === 'VPO' || $scope === 'CAD') {
        if ($sender === 'BRANCH' && $type === 'message') {
            if (!empty($meta['reopened'])) {
                return 'Branch: Re-opened the ticket ' . $ticketNumber;
            }
            return 'Branch: Sent a new message' . $attachSuffix;
        }
        return '';
    }

    if ($scope === 'REPORT' || $scope === 'MAINT') {
        if ($type === 'resolve') {
            return $sender . ': Resolved the ticket ' . $ticketNumber;
        }
        if ($type === 'close') {
            return $sender . ': Closed the ticket ' . $ticketNumber;
        }
        if ($type === 'message') {
            return $sender . ': Sent a new message' . $attachSuffix;
        }
    }

    return '';
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

$userId = st_user_id_or_null();
$cursor = (int) ($_GET['cursor'] ?? 0);
if ($cursor < 0) {
    $cursor = 0;
}
$bootstrap = ((string) ($_GET['bootstrap'] ?? '0')) === '1';

$ticketNumbers = st_live_parse_ticket_numbers($_GET['ticket_numbers'] ?? '');
$ticketNumberSet = [];
foreach ($ticketNumbers as $tn) {
    $ticketNumberSet[$tn] = true;
}

$openState = st_live_parse_open_state($_GET['open_state'] ?? '{}');

$badgeCounts = [];
if (($scope === 'BRANCH' || $scope === 'VPO' || $scope === 'CAD') && !empty($ticketNumbers)) {
    $badgeCounts = st_get_ticket_badge_counts($conn, $ticketNumbers, $scope);
}

$ticketStatuses = st_live_get_ticket_statuses($conn, $scope, $ticketNumbers, $userId);

$schema = st_schema();

if ($bootstrap) {
    $maxSql = "SELECT COALESCE(MAX(id), 0) AS max_id FROM {$schema}.ticket_trails";
    $maxRes = $conn->query($maxSql);
    $maxId = 0;
    if ($maxRes) {
        $row = $maxRes->fetch_assoc();
        $maxId = (int) ($row['max_id'] ?? 0);
    }

    st_json(true, 'Live updates bootstrap.', [
        'next_cursor' => $maxId,
        'badge_counts' => $badgeCounts,
        'ticket_statuses' => $ticketStatuses,
        'notifications' => [],
        'trail_deltas' => [],
    ]);
}

$sql = "SELECT
            tt.id AS trail_id,
            tt.ticket_id,
            tt.type,
            tt.sender_role,
            tt.target_role,
            tt.message,
            tt.meta,
            tt.created_at,
            t.ticket_number,
            t.current_handler_role,
            t.assigned_to,
            t.created_by,
            t.vpo_owner,
            t.cad_owner,
            t.status
        FROM {$schema}.ticket_trails tt
        INNER JOIN {$schema}.tickets t ON t.id = tt.ticket_id
        WHERE tt.id > ?
        ORDER BY tt.id ASC
        LIMIT 300";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    st_json(false, 'Unable to prepare trail updates query.', [], 500);
}

$stmt->bind_param('i', $cursor);
if (!$stmt->execute()) {
    $stmt->close();
    st_json(false, 'Unable to fetch trail updates.', [], 500);
}

$res = $stmt->get_result();
$rows = [];
$nextCursor = $cursor;
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $trailId = (int) ($row['trail_id'] ?? 0);
        if ($trailId > $nextCursor) {
            $nextCursor = $trailId;
        }
        $rows[] = $row;
    }
}
$stmt->close();

$relevantRows = [];
foreach ($rows as $row) {
    $ticketNumber = strtoupper(trim((string) ($row['ticket_number'] ?? '')));
    if (!empty($ticketNumberSet) && !isset($ticketNumberSet[$ticketNumber])) {
        continue;
    }

    if (!st_live_row_relevant($scope, $row, $userId)) {
        continue;
    }

    $row['ticket_number'] = $ticketNumber;
    $relevantRows[] = $row;
}

$trailIds = [];
foreach ($relevantRows as $row) {
    $trailId = (int) ($row['trail_id'] ?? 0);
    if ($trailId > 0) {
        $trailIds[$trailId] = true;
    }
}
$trailIds = array_keys($trailIds);

$attachmentCounts = [];
$attachmentsByTrail = [];
if (!empty($trailIds)) {
    $placeholders = implode(',', array_fill(0, count($trailIds), '?'));
    $types = str_repeat('i', count($trailIds));
    $attSql = "SELECT id, ticket_trail_id, file_name, file_size FROM {$schema}.ticket_attachments WHERE ticket_trail_id IN ({$placeholders}) ORDER BY id ASC";
    $attStmt = $conn->prepare($attSql);
    if ($attStmt) {
        $attStmt->bind_param($types, ...$trailIds);
        if ($attStmt->execute()) {
            $attRes = $attStmt->get_result();
            if ($attRes) {
                $prefix = st_live_base_prefix();
                while ($att = $attRes->fetch_assoc()) {
                    $trailId = (int) ($att['ticket_trail_id'] ?? 0);
                    if ($trailId <= 0) {
                        continue;
                    }
                    if (!isset($attachmentCounts[$trailId])) {
                        $attachmentCounts[$trailId] = 0;
                    }
                    $attachmentCounts[$trailId]++;

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
        $attStmt->close();
    }
}

$notifications = [];
$trailDeltas = [];

foreach ($relevantRows as $row) {
    $trailId = (int) ($row['trail_id'] ?? 0);
    $ticketId = (int) ($row['ticket_id'] ?? 0);
    $ticketNumber = (string) ($row['ticket_number'] ?? '');
    $attachmentCount = (int) ($attachmentCounts[$trailId] ?? 0);

    $notifyText = st_live_notification_text($scope, $row, $attachmentCount);
    if ($notifyText !== '') {
        $notifications[] = [
            'trail_id' => $trailId,
            'ticket_id' => $ticketId,
            'ticket_number' => $ticketNumber,
            'text' => $notifyText,
        ];
    }

    $lastOpenTrailId = isset($openState[$ticketId]) ? (int) $openState[$ticketId] : null;
    if ($lastOpenTrailId !== null && $trailId > $lastOpenTrailId) {
        if (!isset($trailDeltas[$ticketId])) {
            $trailDeltas[$ticketId] = [];
        }

        $trailDeltas[$ticketId][] = [
            'trail_id' => $trailId,
            'type' => (string) ($row['type'] ?? 'message'),
            'sender_role' => (string) ($row['sender_role'] ?? 'SYSTEM'),
            'message' => (string) ($row['message'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'attachment_count' => $attachmentCount,
            'attachments' => $attachmentsByTrail[$trailId] ?? [],
        ];
    }
}

st_json(true, 'Live updates fetched.', [
    'next_cursor' => $nextCursor,
    'badge_counts' => $badgeCounts,
    'ticket_statuses' => $ticketStatuses,
    'notifications' => $notifications,
    'trail_deltas' => $trailDeltas,
]);
