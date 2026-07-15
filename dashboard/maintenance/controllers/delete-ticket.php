<?php
include_once __DIR__ . '/../../support_ticket/includes/bootstrap.php';
require_once __DIR__ . '/../../../config/config.php';

st_require_login('../../../login_form.php');
st_require_permission_page(['Support Ticket Report', 'Maintenance Support Ticket'], '../../home.php');

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    st_redirect_with_flash('maintenance_ticket', 'danger', 'Invalid request method.', '../ticket/ticket-managment.php');
}

$ticketId = (int) ($_POST['ticket_id'] ?? 0);
$returnMode = strtolower(trim((string) ($_POST['return_mode'] ?? 'open')));
if (!in_array($returnMode, ['open', 'active', 'closed'], true)) {
    $returnMode = 'open';
}

$redirectUrl = '../ticket/ticket-managment.php?mode=' . urlencode($returnMode);

if ($ticketId <= 0) {
    st_redirect_with_flash('maintenance_ticket', 'danger', 'Invalid ticket selected.', $redirectUrl);
}

$schema = st_schema();

$ticketNumber = '';
$createdById = 0;
$vpoOwnerId = 0;
$cadOwnerId = 0;
$q = $conn->prepare("SELECT ticket_number, created_by, vpo_owner, cad_owner FROM {$schema}.tickets WHERE id = ? LIMIT 1");
if ($q) {
    $q->bind_param('i', $ticketId);
    if ($q->execute()) {
        $res = $q->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        if ($row) {
            $ticketNumber = (string) ($row['ticket_number'] ?? '');
            $createdById = (int) ($row['created_by'] ?? 0);
            $vpoOwnerId = (int) ($row['vpo_owner'] ?? 0);
            $cadOwnerId = (int) ($row['cad_owner'] ?? 0);
        }
    }
    $q->close();
}

if ($ticketNumber === '') {
    st_redirect_with_flash('maintenance_ticket', 'danger', 'Ticket not found or already deleted.', $redirectUrl);
}

$conn->begin_transaction();

try {
    $deleteByTicketId = function ($tableName) use ($conn, $schema, $ticketId) {
        if (!st_table_exists($conn, $tableName)) {
            return;
        }

        $sql = "DELETE FROM {$schema}.{$tableName} WHERE ticket_id = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception('Unable to prepare delete for ' . $tableName . '.');
        }

        $stmt->bind_param('i', $ticketId);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new Exception('Unable to delete related rows from ' . $tableName . ': ' . $err);
        }
        $stmt->close();
    };

    $deleteByTicketNumber = function ($tableName) use ($conn, $schema, $ticketNumber) {
        if (!st_table_exists($conn, $tableName)) {
            return;
        }

        $sql = "DELETE FROM {$schema}.{$tableName} WHERE ticket_number = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception('Unable to prepare delete for ' . $tableName . '.');
        }

        $stmt->bind_param('s', $ticketNumber);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new Exception('Unable to delete related rows from ' . $tableName . ': ' . $err);
        }
        $stmt->close();
    };

    $deleteByTicketId('ticket_attachments');
    $deleteByTicketId('ticket_trails');
    $deleteByTicketNumber('ticket_info_wrongbiller');
    $deleteByTicketNumber('ticket_info_overstatedamount');
    $deleteByTicketNumber('ticket_info_cancelledtransaction');
    $deleteByTicketNumber('ticket_info');
    $deleteByTicketNumber('ticket_badge');

    $del = $conn->prepare("DELETE FROM {$schema}.tickets WHERE id = ? LIMIT 1");
    if (!$del) {
        throw new Exception('Unable to prepare final ticket delete.');
    }
    $del->bind_param('i', $ticketId);
    if (!$del->execute()) {
        $del->close();
        throw new Exception('Unable to delete ticket.');
    }
    $affected = (int) $del->affected_rows;
    $del->close();

    if ($affected <= 0) {
        throw new Exception('Ticket was not deleted.');
    }

    if (st_table_exists($conn, 'ticket_active')) {
        $ownerIds = array_unique(array_values(array_filter([
            $createdById,
            $vpoOwnerId,
            $cadOwnerId,
        ], function ($id) {
            return (int) $id > 0;
        })));

        foreach ($ownerIds as $ownerId) {
            st_sync_ticket_active_counts($conn, (int) $ownerId);
        }
    }

    $conn->commit();
    st_redirect_with_flash('maintenance_ticket', 'success', 'Ticket ' . $ticketNumber . ' deleted successfully.', $redirectUrl);
} catch (Throwable $e) {
    $conn->rollback();
    st_redirect_with_flash('maintenance_ticket', 'danger', 'Delete failed: ' . $e->getMessage(), $redirectUrl);
}
