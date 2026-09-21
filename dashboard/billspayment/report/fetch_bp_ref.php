<?php
// fetch_bp_ref.php
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../vendor/autoload.php';

session_start();
@include_once __DIR__ . '/../../../templates/middleware.php';
$id = resolve_user_identifier();
if (empty($id)) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }
if (!function_exists('has_any_permission') || !has_any_permission(['Volume Report','Bills Payment'])) {
    http_response_code(403); echo json_encode(['error' => 'Forbidden']); exit;
}

header('Content-Type: application/json');

$ref_no = trim($_GET['ref_no'] ?? '');
$mode   = trim($_GET['mode']   ?? 'detail');

if ($ref_no === '') {
    echo json_encode(['found' => false, 'message' => 'Missing reference number']);
    exit;
}

try {
    // ---- Duplicate check mode ----
    if ($mode === 'check') {
        $sqlCheck = "SELECT 1 FROM cad_loggings.cad_loggings_requests
                     WHERE bp_reference_no = ?
                     LIMIT 1";
        if ($stmtC = $conn->prepare($sqlCheck)) {
            $stmtC->bind_param('s', $ref_no);
            $stmtC->execute();
            $resC = $stmtC->get_result();
            $exists = ($resC->num_rows > 0);
            $stmtC->close();

            echo json_encode([
                'found'     => true,
                'duplicate' => $exists,
                'message'   => $exists
                               ? 'This BP Ref. No. has already been logged.'
                               : 'OK to proceed.'
            ]);
            exit;
        }
    }

    // ---- Default detail lookup ----
    $sql = "SELECT
                bt.reference_no,
                bt.datetime,
                bt.cancellation_date,
                bt.partner_id_kpx,
                pm.partner_name,
                bt.region,
                bt.branch_id,
                bt.outlet,
                bt.amount_paid,
                bt.account_no,
                bt.account_name,
                bt.status
            FROM mldb.billspayment_transaction bt
            LEFT JOIN masterdata.partner_masterfile pm
                   ON pm.partner_id_kpx = bt.partner_id_kpx
            WHERE bt.reference_no = ?
              AND bt.status = '*'
            LIMIT 1";

    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param('s', $ref_no);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            echo json_encode([
                'found' => true,
                'data' => [
                    'reference_no'      => $row['reference_no'],
                    'date_of_payment'   => $row['datetime'],
                    'date_cancelled'    => $row['cancellation_date'],
                    'wrong_biller'      => $row['partner_id_kpx'],
                    'wrong_biller_text' => $row['partner_id_kpx'] . ' - ' . ($row['partner_name'] ?? ''),
                    'regionname'        => $row['region'],
                    'branchname'        => $row['branch_id'],
                    'branchname_text'   => $row['branch_id'] . ' - ' . ($row['outlet'] ?? ''),
                    'amount'            => abs((float)$row['amount_paid']),
                    'account_no'        => $row['account_no'],
                    'account_name'      => $row['account_name'],
                    'status'            => $row['status'],
                ]
            ]);
        } else {
            echo json_encode([
                'found'   => false,
                'message' => 'No cancelled transaction found for this BP Ref No. You may continue logging manually.'
            ]);
        }
        $stmt->close();
    } else {
        echo json_encode(['found' => false, 'message' => 'Query prepare failed']);
    }
} catch (Exception $e) {
    error_log('fetch_bp_ref error: ' . $e->getMessage());
    echo json_encode(['found' => false, 'message' => 'Server error']);
}