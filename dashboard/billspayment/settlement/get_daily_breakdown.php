<?php
// get_daily_breakdown.php - AJAX endpoint for fetching daily breakdown
header('Content-Type: application/json');

// Add cache control headers
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// Connect to the database
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../vendor/autoload.php';

// Start the session
session_start();
@include_once __DIR__ . '/../../../templates/middleware.php';
$id = resolve_user_identifier();
if (empty($id)) { 
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit; 
}
if (!function_exists('has_any_permission') || !has_any_permission(['Settlement Per Bank','Bills Payment'])) { 
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit; 
}

// Get parameters
$partner_id = isset($_GET['partner_id']) ? trim($_GET['partner_id']) : '';
$bank = isset($_GET['bank']) ? trim($_GET['bank']) : '';
$settlement_type = isset($_GET['settlement_type']) ? trim($_GET['settlement_type']) : '';
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';

// Validate required parameters
if (empty($partner_id)) {
    echo json_encode(['success' => false, 'message' => 'Partner ID is required']);
    exit;
}

try {
    // Build the query
    $sql = "SELECT 
                DATE(bt.datetime) as transaction_date,
                COUNT(*) as txn_count,
                SUM(CASE WHEN bt.amount_paid > 0 THEN bt.amount_paid ELSE 0 END) as total_principal,
                (SUM(bt.charge_to_customer) + SUM(bt.charge_to_partner)) as total_charge,
                SUM(CASE WHEN bt.amount_paid < 0 THEN bt.amount_paid ELSE 0 END) as total_adjustment,
                SUM(bt.amount_paid) + (SUM(bt.charge_to_customer) + SUM(bt.charge_to_partner)) as amount_for_settlement
            FROM mldb.billspayment_transaction bt
            LEFT JOIN masterdata.partner_masterfile pm ON bt.partner_id_kpx = pm.partner_id_kpx
            WHERE bt.partner_id_kpx = ?";
    
    $params = [$partner_id];
    $types = "s";
    
    if (!empty($date_from)) {
        $sql .= " AND DATE(bt.datetime) >= ?";
        $params[] = $date_from;
        $types .= "s";
    }
    
    if (!empty($date_to)) {
        $sql .= " AND DATE(bt.datetime) <= ?";
        $params[] = $date_to;
        $types .= "s";
    }
    
    if (!empty($bank)) {
        $sql .= " AND pm.bank = ?";
        $params[] = $bank;
        $types .= "s";
    }
    
    if (!empty($settlement_type)) {
        $sql .= " AND pm.settled_online_check = ?";
        $params[] = $settlement_type;
        $types .= "s";
    }
    
    $sql .= " GROUP BY DATE(bt.datetime) 
              ORDER BY transaction_date ASC";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
        exit;
    }
    
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = [
            'transaction_date' => $row['transaction_date'],
            'txn_count' => (int)$row['txn_count'],
            'total_principal' => (float)$row['total_principal'],
            'total_charge' => (float)$row['total_charge'],
            'total_adjustment' => (float)$row['total_adjustment'],
            'amount_for_settlement' => (float)$row['amount_for_settlement']
        ];
    }
    
    $stmt->close();
    
    echo json_encode([
        'success' => true,
        'data' => $data,
        'count' => count($data)
    ]);
    
} catch (Exception $e) {
    error_log("Error in get_daily_breakdown: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching daily breakdown: ' . $e->getMessage()
    ]);
}
?>