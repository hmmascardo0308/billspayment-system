<?php
include_once __DIR__ . '/../../includes/bootstrap.php';

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
$closeMode = strtolower(trim((string) ($_POST['close_mode'] ?? '')));
$userId = st_user_id_or_null();

if ($ticketId <= 0 || $userId === null) {
    st_redirect_with_flash('vpo_ticket', 'danger', 'Invalid ticket or user context.', $redirectBack);
}

if (!in_array($closeMode, ['auto', 'immediate'], true)) {
    st_redirect_with_flash('vpo_ticket', 'danger', 'Invalid close mode.', $redirectBack);
}

$conn->autocommit(false);

try {
    $schema = st_schema();

    $checkSql = "SELECT id, status, current_handler_role, assigned_to FROM {$schema}.tickets WHERE id = ? FOR UPDATE";
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

    if ((string) $ticket['current_handler_role'] !== 'VPO' || (int) $ticket['assigned_to'] !== (int) $userId) {
        throw new Exception('Ticket is not assigned to you as VPO.');
    }

    if ((string) $ticket['status'] === 'closed') {
        throw new Exception('Ticket is already closed.');
    }

    if ($closeMode === 'immediate') {
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
            throw new Exception('Unable to close ticket immediately.');
        }
        $updStmt->close();

        st_insert_trail($conn, $ticketId, 'close', $userId, 'VPO', null, 'Ticket closed immediately.', null);
        st_insert_trail($conn, $ticketId, 'message', null, 'SYSTEM', null, 'Ticket has been closed by VPO.', ['automation' => true]);

        $conn->commit();
        $conn->autocommit(true);
        st_redirect_with_flash('vpo_ticket', 'success', 'Ticket closed immediately.', $redirectBack);
    }

    $dt = new DateTime('now');
    $dt->modify('+24 hours');
    $autoCloseAt = $dt->format('Y-m-d H:i:s');
    $durationText = '24 hours';

    $updSql = "UPDATE {$schema}.tickets
               SET status = 'resolved',
                   auto_close_at = ?,
                   close_type = 'auto',
                   updated_at = NOW()
               WHERE id = ?";
    $updStmt = $conn->prepare($updSql);
    if (!$updStmt) {
        throw new Exception('Unable to prepare auto-close update.');
    }

    $updStmt->bind_param('si', $autoCloseAt, $ticketId);
    if (!$updStmt->execute()) {
        $updStmt->close();
        throw new Exception('Unable to mark ticket as resolved.');
    }
    $updStmt->close();

    st_insert_trail(
        $conn,
        $ticketId,
        'resolve',
        $userId,
        'VPO',
        null,
        null,
        ['auto_close_duration' => $durationText, 'auto_close_at' => $autoCloseAt]
    );
    st_insert_trail(
        $conn,
        $ticketId,
        'message',
        null,
        'SYSTEM',
        null,
        'Ticket has been marked as resolved. It will be automatically closed after ' . $durationText . '.',
        ['automation' => true]
    );

    $conn->commit();
    $conn->autocommit(true);
    st_redirect_with_flash('vpo_ticket', 'success', 'Ticket marked for auto-close.', $redirectBack);
} catch (Exception $e) {
    $conn->rollback();
    $conn->autocommit(true);
    st_redirect_with_flash('vpo_ticket', 'danger', $e->getMessage(), $redirectBack);
}
