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

// Read optional mainzone parameter (GET or POST)
$mainzone = '';
if (isset($_GET['mainzone'])) {
    $mainzone = trim($_GET['mainzone']);
} elseif (isset($_POST['mainzone'])) {
    $mainzone = trim($_POST['mainzone']);
}

$exclude_list = "('HO','JEW','VISMIN-MANCOMM','LNCR-MANCOMM','VISMIN-SUPPORT','LNCR-SUPPORT')";

if ($mainzone !== '') {
    $sql = "SELECT DISTINCT zone_code FROM masterdata.zone_masterfile WHERE main_zone_code = ? AND NOT zone_code IN $exclude_list ORDER BY zone_code";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode(['success' => false, 'error' => $conn->error ?? 'Prepare failed']);
        exit;
    }
    $stmt->bind_param('s', $mainzone);
    $stmt->execute();
    $res = $stmt->get_result();
} else {
    // return all zones when mainzone not provided
    $sql = "SELECT DISTINCT zone_code FROM masterdata.zone_masterfile WHERE NOT zone_code IN $exclude_list ORDER BY zone_code";
    $res = $conn->query($sql);
    if ($res === false) {
        echo json_encode(['success' => false, 'error' => $conn->error ?? 'Query failed']);
        exit;
    }
}

$data = [];
while ($row = $res->fetch_assoc()) {
    $data[] = $row['zone_code'];
}

echo json_encode(['success' => true, 'data' => $data]);
exit;
?>