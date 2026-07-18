<?php
// settle_transactions.php
header('Content-Type: application/json');
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../vendor/autoload.php';

session_start();
@include_once __DIR__ . '/../../../templates/middleware.php';

// Set timezone to Asia/Manila
date_default_timezone_set('Asia/Manila');

// Check authentication
$id = resolve_user_identifier();
if (empty($id)) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

if (!function_exists('has_any_permission') || !has_any_permission(['Settlement Per Bank','Bills Payment'])) {
    echo json_encode(['success' => false, 'message' => 'Permission denied.']);
    exit;
}

// Get POST data
$partner_ids = isset($_POST['partner_ids']) ? $_POST['partner_ids'] : [];
$settled_by = isset($_POST['settled_by']) ? trim($_POST['settled_by']) : '';
$partner_filter = isset($_POST['partner_filter']) ? trim($_POST['partner_filter']) : '';
$bank_filter = isset($_POST['bank_filter']) ? trim($_POST['bank_filter']) : '';
$settlement_type_filter = isset($_POST['settlement_type_filter']) ? trim($_POST['settlement_type_filter']) : '';
$date_from = isset($_POST['date_from']) ? trim($_POST['date_from']) : '';
$date_to = isset($_POST['date_to']) ? trim($_POST['date_to']) : '';

// Validate
if (empty($partner_ids) || !is_array($partner_ids)) {
    echo json_encode(['success' => false, 'message' => 'No partners selected for settlement.']);
    exit;
}

if (empty($settled_by)) {
    if (isset($_SESSION['user_type'])) {
        if ($_SESSION['user_type'] === 'admin') {
            $settled_by = $_SESSION['admin_name'] ?? 'ADMIN';
        } elseif ($_SESSION['user_type'] === 'user') {
            $settled_by = $_SESSION['user_name'] ?? 'USER';
        } else {
            $settled_by = 'SYSTEM';
        }
    } else {
        $settled_by = 'SYSTEM';
    }
}

// Build WHERE clause for the update - ONLY unsettled records
$where_conditions = [];
$params = [];
$types = "";

// Partner filter - use partner_ids array
if (!empty($partner_ids)) {
    $placeholders = implode(',', array_fill(0, count($partner_ids), '?'));
    $where_conditions[] = "bt.partner_id_kpx IN ($placeholders)";
    foreach ($partner_ids as $pid) {
        $params[] = $pid;
        $types .= "s";
    }
}

// Bank filter
if (!empty($bank_filter)) {
    $where_conditions[] = "pm.bank = ?";
    $params[] = $bank_filter;
    $types .= "s";
}

// Settlement type filter
if (!empty($settlement_type_filter)) {
    $where_conditions[] = "pm.settled_online_check = ?";
    $params[] = $settlement_type_filter;
    $types .= "s";
}

// Date range filters
if (!empty($date_from) && !empty($date_to)) {
    $where_conditions[] = "bt.datetime BETWEEN ? AND ?";
    $params[] = $date_from . ' 00:00:00';
    $params[] = $date_to . ' 23:59:59';
    $types .= "ss";
} elseif (!empty($date_from)) {
    $where_conditions[] = "bt.datetime >= ?";
    $params[] = $date_from . ' 00:00:00';
    $types .= "s";
} elseif (!empty($date_to)) {
    $where_conditions[] = "bt.datetime <= ?";
    $params[] = $date_to . ' 23:59:59';
    $types .= "s";
}

// CRITICAL: Only update records that are NOT settled
$where_conditions[] = "(bt.settle_unsettle IS NULL OR bt.settle_unsettle = '' OR bt.settle_unsettle != 'Settled')";
// Also exclude cancelled/voided
$where_conditions[] = "(bt.status IS NULL OR bt.status = '')";

$where_clause = "WHERE " . implode(" AND ", $where_conditions);

// Get settlement timestamp
$settlement_date = date('Y-m-d H:i:s');
$settlement_date_display = date('M d, Y H:i:s');

try {
    // Start transaction
    $conn->begin_transaction();
    
    // First, get count and amount for confirmation (only unsettled)
    $count_sql = "SELECT 
                    COUNT(*) as total_transactions,
                    SUM(bt.amount_paid) + (SUM(bt.charge_to_customer) + SUM(bt.charge_to_partner)) as total_amount,
                    COUNT(DISTINCT bt.partner_id_kpx) as total_partners
                  FROM mldb.billspayment_transaction bt
                  LEFT JOIN masterdata.partner_masterfile pm ON bt.partner_id_kpx = pm.partner_id_kpx
                  " . $where_clause;
    
    $count_stmt = $conn->prepare($count_sql);
    if (!empty($params)) {
        $count_stmt->bind_param($types, ...$params);
    }
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $count_data = $count_result->fetch_assoc();
    $count_stmt->close();
    
    if (!$count_data || $count_data['total_transactions'] == 0) {
        $conn->rollback();
        echo json_encode([
            'success' => false,
            'message' => 'No unsettled transactions found for the selected criteria.'
        ]);
        exit;
    }
    
    // Update ONLY unsettled transactions
    $update_sql = "UPDATE mldb.billspayment_transaction bt
                   LEFT JOIN masterdata.partner_masterfile pm ON bt.partner_id_kpx = pm.partner_id_kpx
                   SET 
                       bt.settle_unsettle = 'Settled',
                       bt.settlement_date = ?,
                       bt.settled_by = ?
                   " . $where_clause;
    
    // Add settlement_date and settled_by to params
    $update_params = array_merge([$settlement_date, $settled_by], $params);
    $update_types = "ss" . $types;
    
    $update_stmt = $conn->prepare($update_sql);
    if (!$update_stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    $update_stmt->bind_param($update_types, ...$update_params);
    $update_stmt->execute();
    $affected_rows = $update_stmt->affected_rows;
    $update_stmt->close();
    
    if ($affected_rows === false) {
        throw new Exception("Update failed: " . $conn->error);
    }
    
    // Commit transaction
    $conn->commit();
    
    // Log the settlement action
    error_log("Settlement performed by: $settled_by, Date: $settlement_date, Affected rows: $affected_rows, Partners: " . implode(',', $partner_ids));
    
    // Return success response
    echo json_encode([
        'success' => true,
        'message' => "Successfully settled $affected_rows transaction(s).",
        'data' => [
            'total_transactions' => (int)($count_data['total_transactions'] ?? 0),
            'total_amount' => (float)($count_data['total_amount'] ?? 0),
            'total_partners' => (int)($count_data['total_partners'] ?? 0),
            'settled_by' => $settled_by,
            'settlement_date' => $settlement_date_display,
            'affected_rows' => $affected_rows
        ]
    ]);
    
} catch (Exception $e) {
    $conn->rollback();
    error_log("Settlement error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error processing settlement: ' . $e->getMessage()
    ]);
}

$conn->close();
?>