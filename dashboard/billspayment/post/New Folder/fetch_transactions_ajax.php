<?php
// Lightweight AJAX endpoint to return paged transactions as JSON
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../../config/config.php';


$startingMonth = $_POST['startingMonth'] ?? '';
$limit = $_POST['limit'] ?? 10;
$offset = $_POST['offset'] ?? 0;

if (empty($startingMonth)) {
    echo json_encode(['success' => false, 'message' => 'Missing startingMonth']);
    exit;
}

// Normalize limit/offset
if ($limit === 'all') {
    // cap to a reasonably large number to avoid OOM; caller intentionally asked all
    $limit = 1000000;
}
$limit = (int)$limit;
$offset = (int)$offset;

// parse startingMonth (expect YYYY-MM)
try {
    $year = date('Y', strtotime($startingMonth . '-01'));
    $month = date('m', strtotime($startingMonth . '-01'));
    $lastDay = date('t', strtotime($startingMonth . '-01'));
    $startDate = date('Y-m-d H:i:s', strtotime($year . '-' . $month . '-01 00:00:00'));
    $endDate = date('Y-m-d H:i:s', strtotime($year . '-' . $month . '-' . $lastDay . ' 23:59:59'));
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Invalid startingMonth format']);
    exit;
}

// Count total matching rows (unposted within date range)
$countSql = "SELECT COUNT(*) AS cnt FROM mldb.billspayment_transaction WHERE post_transaction = 'unposted' AND (datetime BETWEEN ? AND ? OR cancellation_date BETWEEN ? AND ? )";
if ($cstmt = $conn->prepare($countSql)) {
    $cstmt->bind_param('ssss', $startDate, $endDate, $startDate, $endDate);
    $cstmt->execute();
    $cres = $cstmt->get_result();
    $crow = $cres->fetch_assoc();
    $total = intval($crow['cnt'] ?? 0);
    $cstmt->close();
} else {
    echo json_encode(['success' => false, 'message' => 'DB error preparing count']);
    exit;
}

// Fetch paged rows
$selectSql = "SELECT branch_id, outlet, region, reference_no, amount_paid, charge_to_partner, charge_to_customer FROM mldb.billspayment_transaction WHERE post_transaction = 'unposted' AND (datetime BETWEEN ? AND ? OR cancellation_date BETWEEN ? AND ?) ORDER BY datetime, cancellation_date DESC LIMIT ? OFFSET ?";
if ($stmt = $conn->prepare($selectSql)) {
    // bind params (s s s s i i)
    $stmt->bind_param('ssssii', $startDate, $endDate, $startDate, $endDate, $limit, $offset);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $rows[] = $r;
    }
    $stmt->close();
    echo json_encode(['success' => true, 'total' => $total, 'rows' => $rows]);
    exit;
} else {
    echo json_encode(['success' => false, 'message' => 'DB error preparing select']);
    exit;
}
