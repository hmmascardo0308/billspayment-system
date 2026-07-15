<?php
// Connect to the database
require_once __DIR__ . '/../../../config/config.php';

// Start the session
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_type'])) {
    http_response_code(403);
    exit('Unauthorized access');
}

ignore_user_abort(true);

// Get filter parameters
$partner = isset($_GET['partner']) ? $_GET['partner'] : '';
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';
$post_transaction = isset($_GET['post_transaction']) ? $_GET['post_transaction'] : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';
$source_file = isset($_GET['source_file']) ? $_GET['source_file'] : '';
$mainzone = isset($_GET['mainzone']) ? $_GET['mainzone'] : '';
$zone = isset($_GET['zone']) ? $_GET['zone'] : '';
$region = isset($_GET['region']) ? $_GET['region'] : '';
$branch = isset($_GET['branch']) ? $_GET['branch'] : '';
$search = isset($_GET['search']) ? $_GET['search'] : '';

// Build WHERE conditions (same as in main report)
$whereConditions = [];
$params = [];
$types = '';

// Always exclude these branch/status rows from report results
$whereConditions[] = "NOT (branch_id IN ('1', '2', '4937', '4938', '4962', '4987', '4993', '4944') AND status IS NULL)";

if (!empty($search)) {
    $whereConditions[] = "(reference_no LIKE ?)";
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $types .= 's';
}

if (!empty($partner) && $partner !== 'All') {
    if($partner === 'SECURITY BANK') {
        $whereConditions[] = "(partner_name = ? AND sub_billers_id IS NULL)";
    }elseif($partner === 'MYLORA CORPORATION' || $partner === 'JUNANS MARKETING'){
        $whereConditions[] = "sub_billers_name = ?";
    }else{
        $whereConditions[] = "partner_name = ?";
    }
    $params[] = $partner;
    $types .= 's';
}

if (!empty($start_date)) {
    $whereConditions[] = "(DATE(datetime) >= ? OR DATE(cancellation_date) >= ? OR DATE(report_date) >= ?)";
    $params[] = $start_date;
    $params[] = $start_date;
    $params[] = $start_date;
    $types .= 'sss';
}

if (!empty($end_date)) {
    $whereConditions[] = "(DATE(datetime) <= ? OR DATE(cancellation_date) <= ? OR DATE(report_date) <= ?)";
    $params[] = $end_date;
    $params[] = $end_date;
    $params[] = $end_date;
    $types .= 'sss';
}

if (!empty($post_transaction) && $post_transaction !== 'All') {
    $whereConditions[] = "post_transaction = ?";
    $params[] = $post_transaction;
    $types .= 's';
}

if (!empty($status) && $status !== 'All') {
    if ($status === 'active') {
        // Handle cases for Active status (NULL or empty values in database)
        $whereConditions[] = "status IS NULL";
    } else {
        // Handle other specific statuses
        $whereConditions[] = "status = '*'";
    }
}

if (!empty($source_file) && $source_file !== 'All') {
    $whereConditions[] = "source_file = ?";
    $params[] = $source_file;
    $types .= 's';
}

//for mainzone and zone filtering
if($mainzone ==='VISMIN'){
    if (!empty($zone) && $zone !== 'All') {
        $whereConditions[] = "zone_code = ?";
        $params[] = $zone;
        $types .= 's';
    }else{
        $whereConditions[] = "zone_code IN ('VIS', 'MIN')";
    }
}elseif($mainzone ==='LNCR'){
    if (!empty($zone) && $zone !== 'All') {
        $whereConditions[] = "zone_code = ?";
        $params[] = $zone;
        $types .= 's';
    }else{
        $whereConditions[] = "zone_code IN ('LZN', 'NCR')";
    }
}

if (!empty($region) && $region !== 'All') {
    $whereConditions[] = "region_code = ?";
    $params[] = $region;
    $types .= 's';
}

if (!empty($branch) && $branch !== 'All') {
    $whereConditions[] = "branch_id = ?";
    $params[] = $branch;
    $types .= 's';
}

// Build WHERE clause
$whereClause = '';
if (!empty($whereConditions)) {
    $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);
}

// Check database connection
if (!$conn) {
    http_response_code(500);
    exit('Database connection failed');
}

// First, get total count
$countQuery = "SELECT COUNT(*) as total FROM mldb.billspayment_transaction $whereClause";
$totalRows = 0;

if (!empty($params)) {
    $stmt = $conn->prepare($countQuery);
    if ($stmt) {
        if (!empty($types)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result) {
            $row = $result->fetch_assoc();
            $totalRows = (int)$row['total'];
        }
        $stmt->close();
    }
} else {
    $result = $conn->query($countQuery);
    if ($result) {
        $row = $result->fetch_assoc();
        $totalRows = (int)$row['total'];
    }
}

// Check if there's data to export
if ($totalRows === 0) {
    http_response_code(204);
    exit('No data found for the selected filters');
}

// 1. Determine chunk size dynamically
if ($totalRows <= 10000) {
    $chunkSize = 1000;
} elseif ($totalRows <= 100000) {
    $chunkSize = 2000;
} elseif ($totalRows <= 300000) {
    $chunkSize = 3000;
} else {
    $chunkSize = 5000;
}

// 2. Calculate total chunks and offsets
$totalChunks = ceil($totalRows / $chunkSize);
$offsets = [];
for ($i = 0; $i < $totalChunks; $i++) {
    $offsets[] = $i * $chunkSize;
}

// 3. Set PHP limits based on total chunks
$secondsPerChunk = 2; // estimated seconds per chunk
$maxExecutionTime = max(300, $totalChunks * $secondsPerChunk);

if ($totalRows <= 100000) {
    $memoryLimit = '128M';
} elseif ($totalRows <= 300000) {
    $memoryLimit = '256M';
} else {
    $memoryLimit = '512M';
}

ini_set('max_execution_time', $maxExecutionTime);
set_time_limit($maxExecutionTime);
ini_set('memory_limit', $memoryLimit);

// Set headers for CSV download
$filename = 'Transaction_Details_Report_' . date('Y-m-d_H-i-s') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');

// Open output stream
$output = fopen('php://output', 'w');

// Add BOM for proper UTF-8 encoding in Excel
fwrite($output, "\xEF\xBB\xBF");

function displayFilterValue($value) {
    return ($value === '' || $value === null) ? 'All' : $value;
}

function displayDateRange($startDate, $endDate) {
    if ($startDate !== '' && $endDate !== '') {
        return ($startDate === $endDate) ? $startDate : ($startDate . ' to ' . $endDate);
    }
    if ($startDate !== '') {
        return $startDate;
    }
    if ($endDate !== '') {
        return $endDate;
    }
    return 'All';
}

function displayStatusFilterValue($value) {
    if ($value === '' || $value === null) {
        return 'All';
    }
    return ucfirst(strtolower($value));
}

$generatedByEmail = $_SESSION['admin_email'] ?? $_SESSION['user_email'] ?? '';
$generatedBy = '';
if ($generatedByEmail !== '') {
    $userQuery = "SELECT CONCAT_WS(' ', first_name, middle_name, last_name) as fullname FROM mldb.user_form WHERE email = ?";
    $userParams = [$generatedByEmail];
    $userRow = fetchSingleRow($conn, $userQuery, 's', $userParams);
    if ($userRow && !empty(trim((string)$userRow['fullname']))) {
        $generatedBy = trim((string)$userRow['fullname']);
    }
}
if ($generatedBy === '') {
    $generatedBy = $_SESSION['username'] ?? $generatedByEmail;
}

// Report information block. Header row is intentionally written on CSV row 9.
fputcsv($output, ['BILLS PAYMENT DEPARTMENT']);
fputcsv($output, ['TRANSACTION DETAILS REPORT']);
fputcsv($output, []);
fputcsv($output, ['Generated Date', date('Y-m-d H:i:s'), 'Source File', displayFilterValue($source_file), 'Mainzone', displayFilterValue($mainzone)]);
fputcsv($output, ['Filtered Date', displayDateRange($start_date, $end_date), 'Transaction Status', displayStatusFilterValue($status), 'Zone', displayFilterValue($zone)]);
fputcsv($output, ['Generated By', $generatedBy, '', '', 'Region', displayFilterValue($region)]);
fputcsv($output, ['', '', '', '', 'Branch Name', displayFilterValue($branch)]);
fputcsv($output, []);

// Write CSV header
$headers = [
    'CAD Status',
    'Billing Invoice',
    'Transaction Status',
    'Transaction Date',
    'Cancelled Date',
    'Reference Number',
    'Branch ID',
    'Branch Name',
    'Source',
    'Partner Name',
    'Partner ID (KP7)',
    'Partner ID (KPX)',
    'GL Code',
    'GL Description',
    'Principal Amount',
    'Charge to Partner',
    'Charge to Customer'
];

fputcsv($output, $headers);

// Alternative formatNumberAsDecimal function
function formatNumberAsDecimal($value) {
    // Convert to float, format with 2 decimal places, add tab character to force text
    return "\t" . number_format((float)($value ?? 0), 2, '.', '');
}

function formatSummaryDecimal($value) {
    return "\t" . number_format((float)($value ?? 0), 2, '.', '');
}

function bindParamsByReference($stmt, $types, &$params) {
    if (empty($types)) {
        return true;
    }
    $refs = [$types];
    foreach ($params as $key => $value) {
        $refs[] = &$params[$key];
    }
    return call_user_func_array([$stmt, 'bind_param'], $refs);
}

function fetchSingleRow($conn, $query, $types, &$params) {
    if (!empty($params)) {
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            return null;
        }
        if (!bindParamsByReference($stmt, $types, $params)) {
            $stmt->close();
            return null;
        }
        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        return $row;
    }

    $result = $conn->query($query);
    return $result ? $result->fetch_assoc() : null;
}

// Fetch and write data in chunks to handle large datasets
// $offset = 0;

foreach ($offsets as $offset) {
    // Query for chunk of data
    $dataQuery = "SELECT * FROM mldb.billspayment_transaction 
                  $whereClause 
                  ORDER BY datetime DESC 
                  LIMIT $chunkSize OFFSET $offset";
    
    $data = [];
    if (!empty($params)) {
        $stmt = $conn->prepare($dataQuery);
        if ($stmt) {
            if (!empty($types)) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $data[] = $row;
                }
            }
            $stmt->close();
        }
    } else {
        $result = $conn->query($dataQuery);
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $data[] = $row;
            }
        }
    }
    
    // Write data rows
    foreach ($data as $row) {
        $partner_name_raw = !empty(trim((string)($row['sub_billers_name'] ?? '')))
            ? $row['sub_billers_name']
            : ($row['partner_name'] ?? '');

        $csvRow = [
            $row['post_transaction'] ?? '',
            $row['billing_invoice'] ?? '',
            ($row['status'] === null || $row['status'] === '') ? 'Active' : 'Cancelled',
            $row['datetime'] ? date('F d, Y', strtotime($row['datetime'])) : '',
            $row['cancellation_date'] ? date('F d, Y', strtotime($row['cancellation_date'])) : '',
            $row['reference_no'] ?? '',
            $row['branch_id'] ?? '',
            $row['outlet'] ?? '',
            $row['source_file'] ?? '',
            $partner_name_raw,
            $row['partner_id'] ?? '',
            $row['partner_id_kpx'] ?? '',
            $row['mpm_gl_code'] ?? '',
            $row['mpm_gl_description'] ?? '',
            formatNumberAsDecimal($row['amount_paid']),
            formatNumberAsDecimal($row['charge_to_partner']),
            formatNumberAsDecimal($row['charge_to_customer'])
        ];
        
        fputcsv($output, $csvRow);
    }
    
    $offset += $chunkSize;
    $recordsProcessed = count($data);
    
}

// Calculate and add summary results block
$summaryQuery = "SELECT
                    COALESCE(SUM(CASE WHEN status IS NULL OR status = '' THEN 1 ELSE 0 END), 0) as summary_volume,
                    COALESCE(SUM(CASE WHEN status IS NULL OR status = '' THEN ABS(amount_paid) ELSE 0 END), 0) as summary_principal,
                    COALESCE(SUM(CASE WHEN status IS NULL OR status = '' THEN ABS(charge_to_partner) ELSE 0 END), 0) as summary_charge_partner,
                    COALESCE(SUM(CASE WHEN status IS NULL OR status = '' THEN ABS(charge_to_customer) ELSE 0 END), 0) as summary_charge_customer,
                    COALESCE(SUM(CASE WHEN status = '*' OR status = 'cancelled' THEN 1 ELSE 0 END), 0) as adjustment_volume,
                    COALESCE(SUM(CASE WHEN status = '*' OR status = 'cancelled' THEN ABS(amount_paid) ELSE 0 END), 0) as adjustment_principal,
                    COALESCE(SUM(CASE WHEN status = '*' OR status = 'cancelled' THEN ABS(charge_to_partner) ELSE 0 END), 0) as adjustment_charge_partner,
                    COALESCE(SUM(CASE WHEN status = '*' OR status = 'cancelled' THEN ABS(charge_to_customer) ELSE 0 END), 0) as adjustment_charge_customer
                FROM mldb.billspayment_transaction $whereClause";

$summaryRow = fetchSingleRow($conn, $summaryQuery, $types, $params) ?: [];

$summary = [
    'volume' => (int)($summaryRow['summary_volume'] ?? 0),
    'principal' => (float)($summaryRow['summary_principal'] ?? 0),
    'partner' => (float)($summaryRow['summary_charge_partner'] ?? 0),
    'customer' => (float)($summaryRow['summary_charge_customer'] ?? 0)
];
$summary['total_charge'] = $summary['partner'] - $summary['customer'];

$adjustment = [
    'volume' => (int)($summaryRow['adjustment_volume'] ?? 0),
    'principal' => (float)($summaryRow['adjustment_principal'] ?? 0),
    'partner' => (float)($summaryRow['adjustment_charge_partner'] ?? 0),
    'customer' => (float)($summaryRow['adjustment_charge_customer'] ?? 0)
];
$adjustment['total_charge'] = $adjustment['partner'] - $adjustment['customer'];

$net = [
    'volume' => $summary['volume'] - $adjustment['volume'],
    'principal' => $summary['principal'] - $adjustment['principal'],
    'partner' => $summary['partner'] - $adjustment['partner'],
    'customer' => $summary['customer'] - $adjustment['customer'],
    'total_charge' => $summary['total_charge'] - $adjustment['total_charge']
];
$net['settlement_amount'] = $net['principal'] - $net['total_charge'];

fputcsv($output, []);
fputcsv($output, ['SUMMARY RESULTS']);
fputcsv($output, ['SUMMARY', '', 'ADJUSTMENT', '', 'NET']);
fputcsv($output, ['Volume', $summary['volume'], 'Volume', $adjustment['volume'], 'Volume', $net['volume']]);
fputcsv($output, ['Principal', formatSummaryDecimal($summary['principal']), 'Principal', formatSummaryDecimal($adjustment['principal']), 'Principal', formatSummaryDecimal($net['principal'])]);
fputcsv($output, ['Charge to Partner', formatSummaryDecimal($summary['partner']), 'Charge to Partner', formatSummaryDecimal($adjustment['partner']), 'Charge to Partner', formatSummaryDecimal($net['partner'])]);
fputcsv($output, ['Charge to Customer', formatSummaryDecimal($summary['customer']), 'Charge to Customer', formatSummaryDecimal($adjustment['customer']), 'Charge to Customer', formatSummaryDecimal($net['customer'])]);
fputcsv($output, ['Total Charge', formatSummaryDecimal($summary['total_charge']), 'Total Charge', formatSummaryDecimal($adjustment['total_charge']), 'Total Charge', formatSummaryDecimal($net['total_charge'])]);
fputcsv($output, ['', '', '', '', 'Settlement Amount', formatSummaryDecimal($net['settlement_amount'])]);

// Close output stream
fclose($output);

// Close database connection
$conn->close();
exit();
?>
