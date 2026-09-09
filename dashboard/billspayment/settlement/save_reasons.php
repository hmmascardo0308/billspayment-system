<?php
// save_reasons.php
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
$reason_data_json = isset($_POST['reason_data']) ? trim($_POST['reason_data']) : '';
$saved_by = isset($_POST['saved_by']) ? trim($_POST['saved_by']) : '';
$partner_filter = isset($_POST['partner_filter']) ? trim($_POST['partner_filter']) : '';
$bank_filter = isset($_POST['bank_filter']) ? trim($_POST['bank_filter']) : '';
$settlement_type_filter = isset($_POST['settlement_type_filter']) ? trim($_POST['settlement_type_filter']) : '';
$date_from = isset($_POST['date_from']) ? trim($_POST['date_from']) : '';
$date_to = isset($_POST['date_to']) ? trim($_POST['date_to']) : '';
$rfp_no = isset($_POST['rfp_no']) ? trim($_POST['rfp_no']) : '';

// Decode reason data
$reason_data = [];
if (!empty($reason_data_json)) {
    $reason_data = json_decode($reason_data_json, true);
    if (!is_array($reason_data)) {
        $reason_data = [];
    }
}

// Validate
if (empty($reason_data)) {
    echo json_encode(['success' => false, 'message' => 'No reasons to save.']);
    exit;
}

// Get saved by name
if (empty($saved_by)) {
    if (isset($_SESSION['user_type'])) {
        if ($_SESSION['user_type'] === 'admin') {
            $saved_by = $_SESSION['admin_name'] ?? 'ADMIN';
        } elseif ($_SESSION['user_type'] === 'user') {
            $saved_by = $_SESSION['user_name'] ?? 'USER';
        } else {
            $saved_by = 'SYSTEM';
        }
    } else {
        $saved_by = 'SYSTEM';
    }
}

$save_date = date('Y-m-d H:i:s');
$save_date_display = date('M d, Y H:i:s');
$total_updated = 0;

try {
    // Start transaction
    $conn->begin_transaction();
    
    foreach ($reason_data as $row_index => $reason_info) {
        $partner_id = isset($reason_info['partner_id']) ? $reason_info['partner_id'] : '';
        $reason = isset($reason_info['reason']) ? $reason_info['reason'] : '';
        
        if (empty($partner_id) || empty($reason)) {
            continue;
        }
        
        // Build WHERE clause for this specific partner with filters
        $where_conditions = [];
        $params = [];
        $types = "";
        
        $where_conditions[] = "bt.partner_id_kpx = ?";
        $params[] = $partner_id;
        $types .= "s";
        
        if (!empty($bank_filter)) {
            $where_conditions[] = "pm.bank = ?";
            $params[] = $bank_filter;
            $types .= "s";
        }
        
        if (!empty($settlement_type_filter)) {
            $where_conditions[] = "pm.settled_online_check = ?";
            $params[] = $settlement_type_filter;
            $types .= "s";
        }
        
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
        
        // Only update unsettled records (not settled yet)
        $where_conditions[] = "(bt.settle_unsettle IS NULL OR bt.settle_unsettle = '' OR bt.settle_unsettle != 'Settled')";
        $where_conditions[] = "(bt.status IS NULL OR bt.status = '')";
        
        $sql = "UPDATE mldb.billspayment_transaction bt
                LEFT JOIN masterdata.partner_masterfile pm ON bt.partner_id_kpx = pm.partner_id_kpx
                SET bt.reason_not_settled = ?
                WHERE " . implode(" AND ", $where_conditions);
        
        $params = array_merge([$reason], $params);
        $types = "s" . $types;
        
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $total_updated += $stmt->affected_rows;
            $stmt->close();
        }
    }
    
    // Log the action
    error_log("Reasons saved - By: $saved_by, Date: $save_date, Updated: $total_updated transactions");
    
    // Commit transaction
    $conn->commit();
    
    // Return success response
    echo json_encode([
        'success' => true,
        'message' => "Successfully saved reasons for $total_updated transaction(s).",
        'data' => [
            'updated_count' => $total_updated,
            'saved_by' => $saved_by,
            'save_date' => $save_date_display
        ]
    ]);
    
} catch (Exception $e) {
    $conn->rollback();
    error_log("Save reasons error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error saving reasons: ' . $e->getMessage()
    ]);
}

$conn->close();
?>