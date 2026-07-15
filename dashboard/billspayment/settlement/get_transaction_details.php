<?php
require_once __DIR__ . '/../../../config/config.php';
session_start();
@include_once __DIR__ . '/../../../templates/middleware.php';
$id = resolve_user_identifier();

header('Content-Type: application/json');

if (empty($id)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!function_exists('has_any_permission') || !has_any_permission(['Adjustment Entry Per Branch','Bills Payment'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

$transactionId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($transactionId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid transaction ID']);
    exit;
}

$sql = "SELECT * FROM mldb.billspayment_transaction WHERE id = ?";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "i", $transactionId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if ($row = mysqli_fetch_assoc($result)) {
    echo json_encode(['success' => true, 'data' => $row]);
} else {
    echo json_encode(['success' => false, 'message' => 'Transaction not found']);
}