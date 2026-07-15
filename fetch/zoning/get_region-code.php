<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/config.php';

// Query main zones and return JSON
// ensure DB connection
if (!isset($conn) || !$conn) {
    echo json_encode(['success' => false, 'error' => 'Database connection not available']);
    exit;
}

// Read optional zone parameter (GET or POST)
$zone = '';
if (isset($_GET['zone'])) {
    $zone = trim($_GET['zone']);
} elseif (isset($_POST['zone'])) {
    $zone = trim($_POST['zone']);
}

$exclude_region_code_list = "('HEADOFFICE1', 'HEADOFFICE2', 'SHOWROOM1', 'SHOWROOM2', 'MANCOMM1', 'MANCOMM2', 'VISMINSUP', 'LNCRSUP')";
$exclude_zone_code_list = "('HO','JEW')";

if ($zone !== '') {
    // perform case-insensitive match for zone_code to be more robust
    $sql = "SELECT DISTINCT region_code, region_description FROM masterdata.region_masterfile WHERE UPPER(zone_code) = UPPER(?) AND NOT region_code IN $exclude_region_code_list ORDER BY region_description";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode(['success' => false, 'error' => $conn->error ?? 'Prepare failed']);
        exit;
    }
    // trim and use the provided zone
    $z = trim($zone);
    $stmt->bind_param('s', $z);
    $stmt->execute();
    $res = $stmt->get_result();
} else {
    // return all regions when zone not provided
    $sql = "SELECT DISTINCT region_code, region_description FROM masterdata.region_masterfile WHERE NOT zone_code IN $exclude_zone_code_list AND NOT region_code IN $exclude_region_code_list ORDER BY region_description";
    $res = $conn->query($sql);
    if ($res === false) {
        echo json_encode(['success' => false, 'error' => $conn->error ?? 'Query failed']);
        exit;
    }
}

$data = [];
while ($row = $res->fetch_assoc()) {
    $data[] = [
        'region_code' => $row['region_code'],
        'region_description' => $row['region_description']
    ];
}

echo json_encode(['success' => true, 'data' => $data]);
exit;
?>