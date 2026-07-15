<?php
include_once __DIR__ . '/../../includes/bootstrap.php';
include_once __DIR__ . '/../../includes/ticket_queries.php';

global $conn;

st_require_login('../../../../login_form.php');
st_require_permission_page(['Support Ticket VPO'], '../../../home.php');

$returnMode = strtolower(trim((string) ($_POST['return_mode'] ?? '')));
$redirectBack = '../../bpo-ticket.php';
if (in_array($returnMode, ['open', 'active', 'closed'], true)) {
    $redirectBack .= '?mode=' . $returnMode;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    st_redirect_with_flash('vpo_ticket', 'danger', 'Invalid request method.', $redirectBack);
}

$ticketId = (int) ($_POST['ticket_id'] ?? 0);
$userId = st_user_id_or_null();
if ($ticketId <= 0 || $userId === null) {
    st_redirect_with_flash('vpo_ticket', 'danger', 'Invalid ticket or user context.', $redirectBack);
}

$conn->autocommit(false);

try {
    $schema = st_schema();

    $lockSql = "SELECT id, current_handler_role, assigned_to, status
                FROM {$schema}.tickets
                WHERE id = ?
                FOR UPDATE";
    $lockStmt = $conn->prepare($lockSql);
    if (!$lockStmt) {
        throw new Exception('Unable to prepare ticket lock query.');
    }

    $lockStmt->bind_param('i', $ticketId);
    if (!$lockStmt->execute()) {
        $lockStmt->close();
        throw new Exception('Unable to lock ticket row.');
    }

    $lockRes = $lockStmt->get_result();
    $lockedTicket = $lockRes ? $lockRes->fetch_assoc() : null;
    $lockStmt->close();

    if (!$lockedTicket) {
        throw new Exception('Ticket not found.');
    }

    $currentHandler = strtoupper(trim((string) ($lockedTicket['current_handler_role'] ?? '')));
    $assignedTo = (int) ($lockedTicket['assigned_to'] ?? 0);
    $statusNow = strtolower(trim((string) ($lockedTicket['status'] ?? '')));

    if ($currentHandler !== 'VPO' || !in_array($statusNow, ['open', 'accepted'], true)) {
        throw new Exception('Ticket is no longer in VPO open queue.');
    }

    if ($assignedTo > 0) {
        if ($assignedTo === (int) $userId) {
            throw new Exception('You already accepted this ticket. Refreshing ticket list.');
        }

        $emailMap = st_get_user_emails_by_id_numbers($conn, [$assignedTo]);
        $assigneeEmail = trim((string) ($emailMap[$assignedTo] ?? ''));
        if ($assigneeEmail === '') {
            $assigneeEmail = 'ID ' . $assignedTo;
        }

        throw new Exception('This ticket has already been accepted by ' . $assigneeEmail . '.');
    }

    $updateSql = "UPDATE {$schema}.tickets
                  SET status = 'accepted',
                      assigned_to = ?,
                      vpo_owner = COALESCE(vpo_owner, ?),
                      updated_at = NOW()
                  WHERE id = ?
                    AND current_handler_role = 'VPO'
                    AND assigned_to IS NULL
                    AND status IN ('open', 'accepted')";
    $stmt = $conn->prepare($updateSql);
    if (!$stmt) {
        throw new Exception('Unable to prepare accept update.');
    }

    $stmt->bind_param('iii', $userId, $userId, $ticketId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new Exception('Unable to accept ticket.');
    }

    if ($stmt->affected_rows <= 0) {
        $stmt->close();
        throw new Exception('Ticket is already assigned or no longer in VPO open queue.');
    }
    $stmt->close();

    st_insert_trail($conn, $ticketId, 'accept', $userId, 'VPO', 'BRANCH', 'Ticket accepted by VPO.', null);
    st_insert_trail(
        $conn,
        $ticketId,
        'message',
        null,
        'SYSTEM',
        null,
        'Ticket has been accepted by VPO and is now under investigation.',
        ['automation' => true]
    );

    $conn->commit();
    $conn->autocommit(true);

    st_redirect_with_flash('vpo_ticket', 'success', 'Ticket accepted successfully.', $redirectBack);
} catch (Exception $e) {
    $conn->rollback();
    $conn->autocommit(true);
    $message = $e->getMessage();
    $refreshBack = $redirectBack;
    if (stripos($message, 'already been accepted by') !== false || stripos($message, 'already accepted this ticket') !== false) {
        $refreshBack .= (strpos($refreshBack, '?') !== false ? '&' : '?') . 'st_refresh=1';
    }
    st_redirect_with_flash('vpo_ticket', 'danger', $message, $refreshBack);
}
