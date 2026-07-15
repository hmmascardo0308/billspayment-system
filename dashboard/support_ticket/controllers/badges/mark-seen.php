<?php
include_once __DIR__ . '/../../includes/bootstrap.php';
global $conn;
st_require_login('../../../../login_form.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    st_json(false, 'Invalid request method.', [], 405);
}

$ticketId = (int) ($_POST['ticket_id'] ?? 0);
$role = strtoupper(trim((string) ($_POST['role'] ?? '')));
$userId = st_user_id_or_null();

if ($ticketId <= 0 || $userId === null) {
    st_json(false, 'Invalid ticket or user context.', [], 400);
}

if (!in_array($role, ['BRANCH', 'VPO', 'CAD'], true)) {
    st_json(false, 'Invalid role.', [], 400);
}

if (!st_table_exists($conn, 'ticket_badge')) {
    st_json(true, 'Badge table not available, skipping.', []);
}

$schema = st_schema();
$sql = "SELECT ticket_number, created_by, vpo_owner, cad_owner
        FROM {$schema}.tickets
        WHERE id = ?
        LIMIT 1";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    st_json(false, 'Unable to prepare lookup query.', [], 500);
}

$stmt->bind_param('i', $ticketId);
if (!$stmt->execute()) {
    $stmt->close();
    st_json(false, 'Unable to fetch ticket.', [], 500);
}

$res = $stmt->get_result();
$ticket = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$ticket) {
    st_json(false, 'Ticket not found.', [], 404);
}

$ticketNumber = trim((string) ($ticket['ticket_number'] ?? ''));
if ($ticketNumber === '') {
    st_json(false, 'Ticket number not found.', [], 404);
}

$isAllowed = false;
if ($role === 'BRANCH' && (int) ($ticket['created_by'] ?? 0) === (int) $userId) {
    $isAllowed = true;
}
if ($role === 'VPO' && (int) ($ticket['vpo_owner'] ?? 0) === (int) $userId) {
    $isAllowed = true;
}
if ($role === 'CAD' && (int) ($ticket['cad_owner'] ?? 0) === (int) $userId) {
    $isAllowed = true;
}

if (!$isAllowed) {
    st_json(false, 'Not allowed to mark this ticket badge as seen.', [], 403);
}

st_ticket_badge_mark_seen($conn, $ticketNumber, $role);
st_sync_ticket_active_counts($conn, $userId);
if ($role === 'VPO' || $role === 'CAD') {
    st_ticket_active_mark_seen($conn, $userId, $role);
}

st_json(true, 'Badge seen status updated.', [
    'ticket_number' => $ticketNumber,
    'role' => $role,
]);
