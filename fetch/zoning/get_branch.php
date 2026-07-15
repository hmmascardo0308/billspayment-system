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
// Read optional parameters (GET or POST)
$zone = '';
$mainzone = '';
$region = '';
if (isset($_GET['zone'])) {
    $zone = trim($_GET['zone']);
} elseif (isset($_POST['zone'])) {
    $zone = trim($_POST['zone']);
}
if (isset($_GET['mainzone'])) {
    $mainzone = trim($_GET['mainzone']);
} elseif (isset($_POST['mainzone'])) {
    $mainzone = trim($_POST['mainzone']);
}
if (isset($_GET['region'])) {
    $region = trim($_GET['region']);
} elseif (isset($_POST['region'])) {
    $region = trim($_POST['region']);
}

$exclude_branch_id_list = "('581', '2607', '1', '2', '4987', '4938', '4944', '4962', '4993', '4937')";

// Build query with optional filters
$where = [];
$params = [];
$types = '';
$sql = "SELECT DISTINCT branch_id, branch_name FROM masterdata.branch_profile WHERE ml_matic_status IN ('Active','Pending','Inactive') AND branch_id NOT IN $exclude_branch_id_list";

// Apply mainzone filter (skip if empty or All)
if ($mainzone !== '' && $mainzone !== 'All') {
    $sql .= " AND mainzone = ?";
    $where[] = $mainzone;
    $types .= 's';
}

// Apply zone filter (skip if empty, All, or Showroom — Showroom is handled separately)
if ($zone !== '' && $zone !== 'All' && $zone !== 'Showroom') {
    $sql .= " AND zone = ?";
    $where[] = $zone;
    $types .= 's';
}

// Special case: Showroom zone filters by ml_matic_region instead of zone
if ($zone === 'Showroom') {
    $showroomRegionMap = [
        'LZN' => 'LNCR',
        'NCR' => 'LNCR',
        'VIS' => 'VISMIN',
        'MIN' => 'VISMIN',
    ];

    if ($region !== '' && $region !== 'All' && isset($showroomRegionMap[$region])) {
        $sql .= " AND zone = ? AND ml_matic_region = ?";
        $where[] = $region;
        $where[] = $showroomRegionMap[$region] . ' Showroom';
        $types .= 'ss';
    } elseif ($mainzone === 'VISMIN') {
        $sql .= " AND ml_matic_region = ?";
        $where[] = 'VISMIN Showroom';
        $types .= 's';
    } elseif ($mainzone === 'LNCR') {
        $sql .= " AND ml_matic_region = ?";
        $where[] = 'LNCR Showroom';
        $types .= 's';
    } else {
        $sql .= " AND ml_matic_region IN ('VISMIN Showroom', 'LNCR Showroom')";
    }
}

// Apply region filter (skip if empty, All, or Showroom zone)
if ($region !== '' && $region !== 'All' && $zone !== 'Showroom') {
    $sql .= " AND region_code = ?";
    $where[] = $region;
    $types .= 's';
}

$sql .= " ORDER BY branch_name";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode(['success' => false, 'error' => $conn->error ?? 'Prepare failed']);
    exit;
}

if (!empty($where)) {
    // bind params dynamically (prepare array of references)
    $bind_names = [];
    $bind_names[] = & $types;
    for ($i = 0; $i < count($where); $i++) {
        $bind_name = 'bind' . $i;
        $$bind_name = $where[$i];
        $bind_names[] = &$$bind_name;
    }
    if (!call_user_func_array([$stmt, 'bind_param'], $bind_names)) {
        echo json_encode(['success' => false, 'error' => 'bind_param failed: ' . $stmt->error]);
        exit;
    }
}

if (!$stmt->execute()) {
    echo json_encode(['success' => false, 'error' => 'execute failed: ' . $stmt->error]);
    exit;
}

$res = $stmt->get_result();
if ($res === false) {
    // fallback: bind_result and fetch manually if get_result not available
    $stmt->store_result();
    $stmt->bind_result($col_branch_id, $col_branch_name);
    $data = [];
    while ($stmt->fetch()) {
        $data[] = ['branch_id' => $col_branch_id, 'branch_name' => $col_branch_name];
    }
    echo json_encode(['success' => true, 'data' => $data]);
    exit;
}

$data = [];
while ($row = $res->fetch_assoc()) {
    $data[] = [
        'branch_id' => $row['branch_id'],
        'branch_name' => $row['branch_name']
    ];
}

echo json_encode(['success' => true, 'data' => $data]);
exit;
?>