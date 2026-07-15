<?php
include_once __DIR__ . '/../../support_ticket/includes/bootstrap.php';
include_once __DIR__ . '/../../support_ticket/includes/ticket_queries.php';
require_once __DIR__ . '/../../../config/config.php';


st_require_login('../../../login_form.php');
st_require_permission_page(['Support Ticket Report', 'Maintenance Support Ticket'], '../../home.php');

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    st_redirect_with_flash('maintenance_ticket', 'danger', 'Invalid request method.', '../ticket/ticket-managment.php');
}

$ticketId = (int) ($_POST['ticket_id'] ?? 0);
$target = strtoupper(trim((string) ($_POST['target'] ?? '')));
$returnMode = strtolower(trim((string) ($_POST['return_mode'] ?? 'open')));
if (!in_array($returnMode, ['open', 'active', 'closed'], true)) {
    $returnMode = 'open';
}

$redirectUrl = '../ticket/ticket-managment.php?mode=' . urlencode($returnMode);

if ($ticketId <= 0) {
    st_redirect_with_flash('maintenance_ticket', 'danger', 'Invalid ticket selected.', $redirectUrl);
}

$schema = st_schema();
$q = $conn->prepare("SELECT ticket_number, vpo_owner, cad_owner, status FROM {$schema}.tickets WHERE id = ? LIMIT 1");
if (!$q) {
    st_redirect_with_flash('maintenance_ticket', 'danger', 'Unable to prepare ticket lookup.', $redirectUrl);
}

$q->bind_param('i', $ticketId);
$q->execute();
$res = $q->get_result();
$row = $res ? $res->fetch_assoc() : null;
$q->close();

if (!$row) {
    st_redirect_with_flash('maintenance_ticket', 'danger', 'Ticket not found.', $redirectUrl);
}

$status = strtolower((string) ($row['status'] ?? ''));
if ($status !== 'resolved') {
    st_redirect_with_flash('maintenance_ticket', 'danger', 'Ticket is not resolved and cannot be re-opened.', $redirectUrl);
}

$conn->begin_transaction();

try {
    if ($target === 'VPO') {
        $updateSql = "UPDATE {$schema}.tickets SET status = 'accepted', assigned_to = vpo_owner, current_handler_role = 'VPO', updated_at = NOW() WHERE id = ? AND status = 'resolved'";
        $stmt = $conn->prepare($updateSql);
        if (!$stmt) throw new Exception('Unable to prepare reopen update.');
        $stmt->bind_param('i', $ticketId);
        if (!$stmt->execute()) { $stmt->close(); throw new Exception('Unable to reopen ticket to VPO.'); }
        if ($stmt->affected_rows <= 0) { $stmt->close(); throw new Exception('Ticket was not reopened (status may have changed).'); }
        $stmt->close();

        $vpoOwner = (int) ($row['vpo_owner'] ?? 0);
        $ownerNames = st_get_user_names_by_id_numbers($conn, [$vpoOwner]);
        $vpoName = $vpoOwner > 0 ? ($ownerNames[$vpoOwner] ?? ('ID ' . $vpoOwner)) : 'Not assigned';

        st_insert_trail($conn, $ticketId, 'message', null, 'SYSTEM', 'VPO', 'Ticket has been re-opened and assigned to VPO: ' . $vpoName, null);

        if ($vpoOwner > 0) st_sync_ticket_active_counts($conn, $vpoOwner);

        $conn->commit();
        st_redirect_with_flash('maintenance_ticket', 'success', 'Ticket has been reopened and was assigned to VPO.', $redirectUrl);
    } elseif ($target === 'CAD') {
        // If CAD owner exists reopen to CAD, otherwise fallback to reopening to VPO
        $cadOwner = (int) ($row['cad_owner'] ?? 0);
        $vpoOwner = (int) ($row['vpo_owner'] ?? 0);

        if ($cadOwner > 0) {
            $updateSql = "UPDATE {$schema}.tickets SET status = 'resolving', assigned_to = cad_owner, current_handler_role = 'CAD', updated_at = NOW() WHERE id = ? AND status = 'resolved'";
            $stmt = $conn->prepare($updateSql);
            if (!$stmt) throw new Exception('Unable to prepare reopen update.');
            $stmt->bind_param('i', $ticketId);
            if (!$stmt->execute()) { $stmt->close(); throw new Exception('Unable to reopen ticket to CAD.'); }
            if ($stmt->affected_rows <= 0) { $stmt->close(); throw new Exception('Ticket was not reopened (status may have changed).'); }
            $stmt->close();

            $ownerNames = st_get_user_names_by_id_numbers($conn, [$cadOwner]);
            $cadName = $cadOwner > 0 ? ($ownerNames[$cadOwner] ?? ('ID ' . $cadOwner)) : 'Not assigned';

            st_insert_trail($conn, $ticketId, 'message', null, 'SYSTEM', 'CAD', 'Ticket has been re-opened and assigned to CAD: ' . $cadName, null);

            if ($cadOwner > 0) st_sync_ticket_active_counts($conn, $cadOwner);

            $conn->commit();
            st_redirect_with_flash('maintenance_ticket', 'success', 'Ticket has been reopened and was assigned to CAD.', $redirectUrl);
        } else {
            // fallback: assign back to VPO when CAD owner is missing
            $updateSql = "UPDATE {$schema}.tickets SET status = 'accepted', assigned_to = vpo_owner, current_handler_role = 'VPO', updated_at = NOW() WHERE id = ? AND status = 'resolved'";
            $stmt = $conn->prepare($updateSql);
            if (!$stmt) throw new Exception('Unable to prepare reopen update.');
            $stmt->bind_param('i', $ticketId);
            if (!$stmt->execute()) { $stmt->close(); throw new Exception('Unable to reopen ticket to VPO (fallback).'); }
            if ($stmt->affected_rows <= 0) { $stmt->close(); throw new Exception('Ticket was not reopened (status may have changed).'); }
            $stmt->close();

            $ownerNames = st_get_user_names_by_id_numbers($conn, [$vpoOwner]);
            $vpoName = $vpoOwner > 0 ? ($ownerNames[$vpoOwner] ?? ('ID ' . $vpoOwner)) : 'Not assigned';

            st_insert_trail($conn, $ticketId, 'message', null, 'SYSTEM', 'VPO', 'Ticket has been re-opened and assigned to VPO: ' . $vpoName, null);

            if ($vpoOwner > 0) st_sync_ticket_active_counts($conn, $vpoOwner);

            $conn->commit();
            st_redirect_with_flash('maintenance_ticket', 'danger', 'Missing CAD Owner, Ticket has been reopened and automatically assigned to VPO.', $redirectUrl);
        }
    } else {
        throw new Exception('Invalid target selected.');
    }
} catch (Throwable $e) {
    $conn->rollback();
    st_redirect_with_flash('maintenance_ticket', 'danger', 'Reopen failed: ' . $e->getMessage(), $redirectUrl);
}
