<?php
include '../../../../config/config.php';
session_start();
include '../../../../templates/middleware.php';

header('Content-Type: application/json');

$userId = resolve_user_identifier();
$hasEntryAccess = function_exists('has_any_permission') && has_any_permission(['TRL Entry', 'Bills Payment']);
$canApprove = !empty($userId) && $hasEntryAccess && (
    (($_SESSION['user_type'] ?? '') === 'admin') ||
    ((string) $userId === '17098209')
);

if (!$canApprove) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You are not authorized to approve pending transactions.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$trlNo = (int) ($_POST['trl_no'] ?? 0);
if ($trlNo <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid transaction.']);
    exit;
}

$conn->autocommit(false);
try {
    $lookupStmt = $conn->prepare("SELECT t.trl_no
        FROM mldb.trl t
        WHERE t.trl_no = ?
          AND t.status = 'PENDING_APPROVAL'
          AND EXISTS (SELECT 1 FROM mldb.trl_attachments a WHERE a.trl_no = t.trl_no)
        LIMIT 1 FOR UPDATE");
    if (!$lookupStmt) throw new Exception('Unable to prepare transaction lookup.');
    $lookupStmt->bind_param('i', $trlNo);
    if (!$lookupStmt->execute()) {
        $lookupStmt->close();
        throw new Exception('Unable to load the pending transaction.');
    }
    $pendingTransaction = $lookupStmt->get_result()->fetch_assoc();
    $lookupStmt->close();

    if (!$pendingTransaction) {
        throw new Exception('The transaction was not found, has no attachment, or was already confirmed.');
    }

    $approveStmt = $conn->prepare("UPDATE mldb.trl
        SET status = NULL
        WHERE trl_no = ? AND status = 'PENDING_APPROVAL'");
    if (!$approveStmt) throw new Exception('Unable to prepare transaction confirmation.');
    $approveStmt->bind_param('i', $trlNo);
    if (!$approveStmt->execute() || $approveStmt->affected_rows !== 1) {
        $approveStmt->close();
        throw new Exception('The transaction could not be confirmed.');
    }
    $approveStmt->close();

    $conn->commit();
    $conn->autocommit(true);
    echo json_encode([
        'success' => true,
        'message' => 'The transaction is now available in Transaction Request Log - Review.',
        'redirect' => 'trl-entry.php?mode=pending'
    ]);
    exit;
} catch (Exception $e) {
    $conn->rollback();
    $conn->autocommit(true);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}
