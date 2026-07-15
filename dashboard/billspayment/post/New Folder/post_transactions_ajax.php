<?php
// Lightweight endpoint to post transactions for the selected date range.
// Does NOT start a session to avoid session file locking and improve responsiveness.
require_once __DIR__ . '/../../../../config/config.php';


header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$startDate = $_POST['startDate'] ?? '';
$endDate = $_POST['endDate'] ?? '';

if (empty($startDate) || empty($endDate)) {
    echo json_encode(['success' => false, 'message' => 'Missing date range']);
    exit;
}

try {
    // Use a single bulk update
    $updateSql = "UPDATE mldb.billspayment_transaction SET post_transaction = 'posted' WHERE post_transaction = 'unposted' AND (datetime BETWEEN ? AND ? OR cancellation_date BETWEEN ? AND ? )";
    $stmt = $conn->prepare($updateSql);
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }
    $stmt->bind_param('ssss', $startDate, $endDate, $startDate, $endDate);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    echo json_encode(['success' => true, 'affected' => $affected, 'message' => "Posted {$affected} transaction(s)"]);
    exit;
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}
