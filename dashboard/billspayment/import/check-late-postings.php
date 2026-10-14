<?php
// check-late-postings.php
// Detects unsettled transactions older than the selected date_from
// (typically yesterday's late postings) so the user can be prompted.

header('Content-Type: application/json');
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../vendor/autoload.php';

session_start();
if (!isset($_SESSION['user_type'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

// ============================================
// Inputs
// ============================================
$date_from         = trim($_POST['date_from'] ?? '');
$date_to           = trim($_POST['date_to'] ?? '');
$partner           = trim($_POST['partner'] ?? '');
$bank              = trim($_POST['bank'] ?? '');
$settlement_type   = trim($_POST['settlement_type'] ?? '');
$lookback_days     = max(1, min(30, (int)($_POST['lookback_days'] ?? 7))); // clamp 1..30

if ($date_from === '') {
    echo json_encode(['status' => 'success', 'has_late' => false, 'rows' => [], 'message' => 'No date_from provided.']);
    exit;
}

// ============================================
// Only run when range is a single day (date_from == date_to)
// This is the typical settlement scenario the prompt is designed for.
// ============================================
if ($date_to !== '' && $date_from !== $date_to) {
    echo json_encode(['status' => 'success', 'has_late' => false, 'rows' => [], 'message' => 'Range spans multiple days; prompt skipped.']);
    exit;
}

// Compute the lookback window (today's date_from minus N days)
$window_start = date('Y-m-d', strtotime($date_from . ' -' . $lookback_days . ' days')) . ' 00:00:00';
$window_end   = date('Y-m-d', strtotime($date_from)) . ' 00:00:00'; // exclusive of selected day

// ============================================
// Build dynamic WHERE
// ============================================
$where = [
    "bt.settle_unsettle IS NULL OR bt.settle_unsettle = '' OR bt.settle_unsettle != 'Settled'",
    "(bt.status IS NULL OR bt.status = '')", // exclude cancellations
    "bt.datetime >= ?",
    "bt.datetime < ?"
];
$params = [$window_start, $window_end];
$types  = "ss";

if ($partner !== '') {
    $where[] = "bt.partner_id_kpx = ?";
    $params[] = $partner;
    $types .= "s";
}

if ($bank !== '') {
    $where[] = "pm.bank = ?";
    $params[] = $bank;
    $types .= "s";
}

if ($settlement_type !== '') {
    $where[] = "pm.settled_online_check = ?";
    $params[] = $settlement_type;
    $types .= "s";
}

$where_sql = implode(" AND ", $where);

// ============================================
// Aggregate per day per partner
// ============================================
$sql = "
    SELECT
        DATE(bt.datetime) AS txn_date,
        bt.partner_id_kpx,
        COALESCE(pm.partner_name, bt.partner_id_kpx) AS partner_name,
        COUNT(*) AS txn_count,
        SUM(CASE WHEN bt.amount_paid > 0 THEN bt.amount_paid ELSE 0 END) AS principal,
        SUM(bt.charge_to_customer) AS charge_to_customer,
        SUM(bt.charge_to_partner) AS charge_to_partner,
        SUM(CASE WHEN bt.import_status = 'Late Posting' THEN 1 ELSE 0 END) AS late_count
    FROM mldb.billspayment_transaction bt
    LEFT JOIN masterdata.partner_masterfile pm
        ON bt.partner_id_kpx = pm.partner_id_kpx
    WHERE $where_sql
    GROUP BY DATE(bt.datetime), bt.partner_id_kpx, pm.partner_name
    ORDER BY txn_date ASC, partner_name ASC
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode(['status' => 'error', 'message' => 'Prepare failed: ' . $conn->error]);
    exit;
}

$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

$rows = [];
$total_count = 0;
$total_late  = 0;

while ($r = $res->fetch_assoc()) {
    $r['txn_count']  = (int)$r['txn_count'];
    $r['late_count'] = (int)$r['late_count'];
    $r['principal']  = (float)$r['principal'];
    $r['charge_to_customer'] = (float)$r['charge_to_customer'];
    $r['charge_to_partner']  = (float)$r['charge_to_partner'];
    $rows[] = $r;
    $total_count += $r['txn_count'];
    $total_late  += $r['late_count'];
}
$stmt->close();

echo json_encode([
    'status'     => 'success',
    'has_late'   => !empty($rows),
    'rows'       => $rows,
    'summary'    => [
        'total_records' => $total_count,
        'late_records'  => $total_late,
        'earliest_date' => !empty($rows) ? $rows[0]['txn_date'] : null,
        'latest_date'   => !empty($rows) ? end($rows)['txn_date'] : null,
        'window_start'  => substr($window_start, 0, 10),
        'window_end'    => substr($window_end, 0, 10),
        'lookback_days' => $lookback_days,
    ]
]);
exit;