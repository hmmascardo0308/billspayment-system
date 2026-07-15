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

$isAjax = st_is_ajax_request();
$fail = function ($message, $statusCode = 400) use ($isAjax, $redirectBack) {
    if ($isAjax) {
        st_json(false, $message, [], $statusCode);
    }
    st_redirect_with_flash('vpo_ticket', 'danger', $message, $redirectBack);
};

$ok = function ($message, $data = []) use ($isAjax, $redirectBack) {
    if ($isAjax) {
        st_json(true, $message, $data, 200);
    }
    st_redirect_with_flash('vpo_ticket', 'success', $message, $redirectBack);
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $fail('Invalid request method.', 405);
}

$action = trim((string) ($_POST['action'] ?? 'reply'));
$ticketId = (int) ($_POST['ticket_id'] ?? 0);
$message = trim((string) ($_POST['message'] ?? ''));
$userId = st_user_id_or_null();

if ($ticketId <= 0 || $userId === null) {
    $fail('Invalid ticket or user context.', 401);
}

if (!in_array($action, ['reply', 'transfer_to_cad'], true)) {
    $fail('Invalid action.');
}

if ($action === 'reply' && $message === '') {
    $fail('Reply message is required.');
}

$conn->autocommit(false);

try {
    $schema = st_schema();

    $lockSql = "SELECT id, status, current_handler_role, assigned_to, vpo_owner, cad_owner
                FROM {$schema}.tickets
                WHERE id = ? FOR UPDATE";
    $lockStmt = $conn->prepare($lockSql);
    if (!$lockStmt) {
        throw new Exception('Unable to prepare ticket lock query.');
    }

    $lockStmt->bind_param('i', $ticketId);
    if (!$lockStmt->execute()) {
        $lockStmt->close();
        throw new Exception('Unable to lock ticket row.');
    }

    $res = $lockStmt->get_result();
    $ticket = $res ? $res->fetch_assoc() : null;
    $lockStmt->close();

    if (!$ticket) {
        throw new Exception('Ticket not found.');
    }

    $currentHandler = strtoupper((string) ($ticket['current_handler_role'] ?? ''));
    $assignedTo = (int) ($ticket['assigned_to'] ?? 0);
    $vpoOwner = (int) ($ticket['vpo_owner'] ?? 0);
    $cadOwner = (int) ($ticket['cad_owner'] ?? 0);
    $isAssignedVpo = ($currentHandler === 'VPO' && $assignedTo === (int) $userId);
    $isVpoParticipant = ($vpoOwner > 0 && $vpoOwner === (int) $userId);

    if ($action === 'transfer_to_cad' && !$isAssignedVpo) {
        throw new Exception('Ticket is no longer assigned to you for transfer.');
    }

    if ($action === 'reply' && !($isAssignedVpo || $isVpoParticipant)) {
        throw new Exception('Ticket is not associated with your VPO account.');
    }

    if ((string) $ticket['status'] === 'closed') {
        throw new Exception('Cannot update a closed ticket.');
    }

    if ($action === 'reply') {
        $replyTargetRole = $currentHandler === 'CAD' ? 'CAD' : 'BRANCH';
        $trailId = st_insert_trail($conn, $ticketId, 'message', $userId, 'VPO', $replyTargetRole, $message, null);
        $conn->commit();
        $conn->autocommit(true);
        $ok('Reply submitted successfully.', [
            'trail_id' => (int) $trailId,
            'ticket_id' => (int) $ticketId,
        ]);
    }

    $transferMessage = $message !== '' ? $message : 'Ticket transferred to CAD.';

    if ($cadOwner > 0) {
        $updSql = "UPDATE {$schema}.tickets
                   SET current_handler_role = 'CAD',
                       assigned_to = ?,
                       status = 'resolving',
                       updated_at = NOW()
                   WHERE id = ? AND current_handler_role = 'VPO' AND assigned_to = ?";
        $updStmt = $conn->prepare($updSql);
        if (!$updStmt) {
            throw new Exception('Unable to prepare transfer update.');
        }

        $updStmt->bind_param('iii', $cadOwner, $ticketId, $userId);
    } else {
        $updSql = "UPDATE {$schema}.tickets
                   SET current_handler_role = 'CAD',
                       assigned_to = NULL,
                       cad_owner = NULL,
                       status = 'transferred',
                       updated_at = NOW()
                   WHERE id = ? AND current_handler_role = 'VPO' AND assigned_to = ?";
        $updStmt = $conn->prepare($updSql);
        if (!$updStmt) {
            throw new Exception('Unable to prepare transfer update.');
        }

        $updStmt->bind_param('ii', $ticketId, $userId);
    }
    if (!$updStmt->execute()) {
        $updStmt->close();
        throw new Exception('Unable to transfer ticket to CAD.');
    }

    if ($updStmt->affected_rows <= 0) {
        $updStmt->close();
        throw new Exception('Ticket transfer failed due to queue state change.');
    }
    $updStmt->close();

    st_insert_trail($conn, $ticketId, 'transfer', $userId, 'VPO', 'CAD', $transferMessage, null);
    st_insert_trail(
        $conn,
        $ticketId,
        'message',
        null,
        'SYSTEM',
        'CAD',
        'Ticket has been transferred to CAD.',
        ['automation' => true]
    );

    $conn->commit();
    $conn->autocommit(true);
    $ok('Ticket transferred to CAD.');
} catch (Exception $e) {
    $conn->rollback();
    $conn->autocommit(true);
    $fail($e->getMessage(), 500);
}
