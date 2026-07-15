<?php
include_once __DIR__ . '/../../includes/bootstrap.php';
global $conn;
st_require_login('../../../../login_form.php');
st_require_permission_page(['Support Ticket Create'], '../../../home.php');

$returnMode = strtolower(trim((string) ($_POST['return_mode'] ?? '')));
$redirectBack = '../../create-ticket.php';
if (in_array($returnMode, ['open', 'closed'], true)) {
    $redirectBack .= '?mode=' . $returnMode;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    st_redirect_with_flash('create_ticket', 'danger', 'Invalid request method.', $redirectBack);
}

$ticketId = (int) ($_POST['ticket_id'] ?? 0);
$userId = st_user_id_or_null();

if ($ticketId <= 0 || $userId === null) {
    st_redirect_with_flash('create_ticket', 'danger', 'Invalid ticket or user context.', $redirectBack);
}

$conn->autocommit(false);

try {
    $schema = st_schema();

    $checkSql = "SELECT id, status, created_by FROM {$schema}.tickets WHERE id = ? FOR UPDATE";
    $checkStmt = $conn->prepare($checkSql);
    if (!$checkStmt) {
        throw new Exception('Unable to prepare ticket lock query.');
    }

    $checkStmt->bind_param('i', $ticketId);
    if (!$checkStmt->execute()) {
        $checkStmt->close();
        throw new Exception('Unable to lock ticket row.');
    }

    $res = $checkStmt->get_result();
    $ticket = $res ? $res->fetch_assoc() : null;
    $checkStmt->close();

    if (!$ticket) {
        throw new Exception('Ticket not found.');
    }

    if ((int) $ticket['created_by'] !== (int) $userId) {
        throw new Exception('You can only close your own ticket.');
    }

    $statusNow = strtolower((string) ($ticket['status'] ?? ''));
    if ($statusNow !== 'open') {
        throw new Exception('Only open tickets can be closed by Branch.');
    }

    $updSql = "UPDATE {$schema}.tickets
               SET status = 'closed',
                   closed_at = NOW(),
                   close_type = 'immediate',
                   updated_at = NOW()
               WHERE id = ?";
    $updStmt = $conn->prepare($updSql);
    if (!$updStmt) {
        throw new Exception('Unable to prepare close update.');
    }

    $updStmt->bind_param('i', $ticketId);
    if (!$updStmt->execute()) {
        $updStmt->close();
        throw new Exception('Unable to close ticket.');
    }
    $updStmt->close();

    st_insert_trail($conn, $ticketId, 'close', $userId, 'BRANCH', null, 'Ticket closed by Branch.', null);
    st_insert_trail($conn, $ticketId, 'message', null, 'SYSTEM', null, 'Ticket has been closed by Branch.', ['automation' => true]);

    $conn->commit();
    $conn->autocommit(true);
    st_redirect_with_flash('create_ticket', 'success', 'Ticket closed immediately.', $redirectBack);
} catch (Exception $e) {
    $conn->rollback();
    $conn->autocommit(true);
    st_redirect_with_flash('create_ticket', 'danger', $e->getMessage(), $redirectBack);
}
