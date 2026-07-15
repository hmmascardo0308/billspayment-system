<?php
include '../../../../config/config.php';
session_start();
include '../../../../templates/middleware.php';

header('Content-Type: application/json');

$id = resolve_user_identifier();
if (empty($id)) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

if (!function_exists('has_any_permission') || !has_any_permission(['TRL Review', 'Bills Payment'])) {
    echo json_encode(['success' => false, 'message' => 'You do not have permission to perform this action.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$action = trim((string) ($_POST['action'] ?? ''));
$trlNo = (int) ($_POST['trl_no'] ?? 0);
if ($action !== 'confirm_refund' || $trlNo <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid request payload.']);
    exit;
}

$conn->autocommit(false);

try {
    $lockSql = "SELECT trl_no, ref_no, status FROM mldb.trl WHERE trl_no = ? LIMIT 1 FOR UPDATE";
    $lockStmt = $conn->prepare($lockSql);
    if (!$lockStmt) {
        throw new Exception('Unable to prepare record lookup.');
    }

    $lockStmt->bind_param('i', $trlNo);
    if (!$lockStmt->execute()) {
        $lockStmt->close();
        throw new Exception('Unable to fetch selected TRL row.');
    }

    $result = $lockStmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $lockStmt->close();

    if (!$row) {
        throw new Exception('Selected TRL row was not found.');
    }

    if (!is_null($row['status']) && trim((string) $row['status']) !== '') {
        throw new Exception('This reference has already been refunded.');
    }

    $newStatus = 'REFUNDED';
    // Detect if optional columns exist and include them in the update statement
    $hasDateRefunded = false;
    $hasRefundedBy = false;
    $c1 = mysqli_query($conn, "SHOW COLUMNS FROM mldb.trl LIKE 'date_refunded'");
    if ($c1 && mysqli_num_rows($c1) > 0) $hasDateRefunded = true;
    $c2 = mysqli_query($conn, "SHOW COLUMNS FROM mldb.trl LIKE 'refunded_by'");
    if ($c2 && mysqli_num_rows($c2) > 0) $hasRefundedBy = true;

    $updSql = "UPDATE mldb.trl SET status = ?";
    if ($hasDateRefunded) {
        $updSql .= ", date_refunded = NOW()";
    }
    if ($hasRefundedBy) {
        $updSql .= ", refunded_by = ?";
    }
    $updSql .= " WHERE trl_no = ? AND status IS NULL";

    $updStmt = $conn->prepare($updSql);
    if (!$updStmt) {
        throw new Exception('Unable to prepare refund update.');
    }

    // Bind parameters according to whether refunded_by is included
    if ($hasRefundedBy) {
        // Prefer human-friendly name if available, fall back to identifier
        $refundedBy = (string) $id;
        if (function_exists('get_user_row')) {
            $urow = get_user_row($id);
            if ($urow) {
                $fn = trim((string) ($urow['first_name'] ?? ''));
                $ln = trim((string) ($urow['last_name'] ?? ''));
                $fullname = trim($fn . ' ' . $ln);
                if ($fullname !== '') {
                    $refundedBy = $fullname . ' (' . (string) $id . ')';
                }
            }
        }
        // status, refunded_by, trl_no
        $updStmt->bind_param('ssi', $newStatus, $refundedBy, $trlNo);
    } else {
        // status, trl_no
        $updStmt->bind_param('si', $newStatus, $trlNo);
    }
    if (!$updStmt->execute()) {
        $updStmt->close();
        throw new Exception('Failed to update refund status.');
    }

    if ($updStmt->affected_rows < 1) {
        $updStmt->close();
        throw new Exception('Refund status was not updated. It may have been processed already.');
    }

    $updStmt->close();

    $conn->commit();
    $conn->autocommit(true);

    $resp = [
        'success' => true,
        'message' => sprintf('Refund confirmed for Reference No. %s', (string) ($row['ref_no'] ?? '')),
        'trl_no' => $trlNo,
        'ref_no' => (string) ($row['ref_no'] ?? ''),
        'status' => $newStatus
    ];
    if (!empty($hasRefundedBy) && isset($refundedBy)) {
        $resp['refunded_by'] = $refundedBy;
    }
    if (!empty($hasDateRefunded)) {
        $resp['date_refunded'] = date('Y-m-d H:i:s');
    }

    echo json_encode($resp);
    exit;
} catch (Exception $e) {
    $conn->rollback();
    $conn->autocommit(true);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
    exit;
}
