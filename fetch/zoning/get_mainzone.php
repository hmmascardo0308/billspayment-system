<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/config.php';

// Query main zones and return JSON
$dataQuery = "SELECT main_zone_code FROM masterdata.main_zone_masterfile WHERE main_zone_code NOT IN ('JEW', 'HO') ORDER BY main_zone_code";

if (!isset($conn) || !$conn) {
    echo json_encode(['success' => false, 'error' => 'Database connection not available']);
    exit;
}

$mainzone_result = $conn->query($dataQuery);
if (!$mainzone_result) {
    echo json_encode(['success' => false, 'error' => $conn->error ?? 'Query failed']);
    exit;
}

$data = [];
while ($row = $mainzone_result->fetch_assoc()) {
    $data[] = $row['main_zone_code'];
}

echo json_encode(['success' => true, 'data' => $data]);
exit;
?>
?>