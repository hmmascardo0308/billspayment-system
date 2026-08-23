<?php
// Prevent any accidental output before we may need pure JSON
ob_start();

// Connect to the database
include '../../../config/config.php';
require '../../../vendor/autoload.php';

// Safe session start
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Handle AJAX clear request FIRST
if (isset($_POST['action']) && $_POST['action'] === 'clear_csv_data') {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    unset($_SESSION['csv_data']);
    unset($_SESSION['csv_headers']);
    unset($_SESSION['csv_uploaded']);
    $_SESSION['csv_uploaded'] = false;

    session_write_close();

    echo json_encode(['success' => true, 'message' => 'Data cleared']);
    exit;
}

// Handle AJAX import from session
if (isset($_POST['action']) && $_POST['action'] === 'import_from_session') {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    $response = ['success' => false, 'message' => ''];

    try {
        // Get data from session
        if (!isset($_SESSION['csv_data']) || empty($_SESSION['csv_data'])) {
            throw new Exception('No data found in session.');
        }

        $data = $_SESSION['csv_data'];
        $headers = $_SESSION['csv_headers'];

        if (empty($data) || empty($headers)) {
            throw new Exception('No data to import.');
        }

        // Set timezone to Asia/Manila
        date_default_timezone_set('Asia/Manila');
        $imported_date = date('Y-m-d H:i:s');
        $imported_by = $_SESSION['admin_name'] ?? $_SESSION['user_name'] ?? 'System';

        // Map column indices
        $date_index = array_search('DATE', $headers);
        $control_no_index = array_search('CONTROL NO', $headers);
        $reference_no_index = array_search('REFERENCE NO', $headers);
        $payor_index = array_search('PAYOR NAME', $headers);
        $address_index = array_search('ADDRESS', $headers);
        $account_no_index = array_search('ACCOUNT NO.', $headers);
        $account_name_index = array_search('ACCOUNT NAME', $headers);
        $amount_paid_index = array_search('AMOUNT PAID', $headers);
        $charge_to_customer_index = array_search('CHARGE TO CUSTOMER', $headers);
        $charge_to_partner_index = array_search('CHARGE TO PARTNER', $headers);
        $other_details_index = array_search('OTHER DETAILS', $headers);
        $branch_id_index = array_search('BRANCH ID', $headers);
        $outlet_index = array_search('ML OUTLET', $headers);
        $region_code_index = array_search('REGION CODE', $headers);
        $region_name_index = array_search('REGION NAME', $headers);
        $operator_index = array_search('OPERATOR', $headers);
        $remote_branch_index = array_search('REMOTE BRANCH', $headers);
        $remote_operator_index = array_search('REMOTE OPERATOR', $headers);
        $second_approver_index = array_search('2ND APPROVER', $headers);
        $partner_id_index = array_search('PARTNER ID', $headers);
        $partner_name_index = array_search('PARTNER NAME', $headers);
        $status_index = array_search('STATUS', $headers);

        // Prepare SQL statement
        $sql = "INSERT INTO mldb.billspayment_transaction_per_month (
            datetime, control_no, reference_no, payor, address, account_no, 
            account_name, amount_paid, charge_to_customer, charge_to_partner, 
            other_details, branch_id, outlet, region_code_tg, region_tg, 
            operator, remote_branch, remote_operator, `2nd_approver`, 
            partner_id, partner_name, status, imported_by, imported_date
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        // Check if $conn exists and is a valid mysqli object
        if (!isset($conn) || !($conn instanceof mysqli)) {
            if (file_exists('../../../config/config.php')) {
                include '../../../config/config.php';
            }
            if (!isset($conn) || !($conn instanceof mysqli)) {
                throw new Exception('Database connection not available. Please check config.php');
            }
        }

        if (!$conn->ping()) {
            $conn->close();
            include '../../../config/config.php';
            if (!isset($conn) || !($conn instanceof mysqli)) {
                throw new Exception('Database connection lost and could not reconnect.');
            }
        }

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            throw new Exception('Failed to prepare SQL statement: ' . $conn->error);
        }

        $inserted_count = 0;
        $error_count = 0;
        $errors = [];

        // Convert CSV null-like values (\N, NULL, empty, etc.) to actual PHP null
        function nullify($value) {
            if ($value === null) {
                return null;
            }
            $v = trim((string)$value);
            $null_values = ['', 'null', 'NULL', '\N', '\\N', 'N/A', 'n/a', '-', '--'];
            if (in_array($v, $null_values, true)) {
                return null;
            }
            return $v;
        }

        // Helper function to extract numeric part from Partner ID
        function extractNumericPartnerId($value) {
            $value = nullify($value);
            if ($value === null || $value === '') {
                return null;
            }
            $numeric = preg_replace('/[^0-9.]/', '', $value);
            if ($numeric === '' || $numeric === null) {
                return null;
            }
            return $numeric;
        }

        // Process each row
        foreach ($data as $row) {
            // Get values with proper null handling for \N / empty
            $datetime_raw   = isset($row[$date_index]) ? trim((string)$row[$date_index]) : null;
            $control_no     = nullify(isset($row[$control_no_index]) ? $row[$control_no_index] : null);
            $reference_no   = nullify(isset($row[$reference_no_index]) ? $row[$reference_no_index] : null);
            $payor          = nullify(isset($row[$payor_index]) ? $row[$payor_index] : null);
            $address        = nullify(isset($row[$address_index]) ? $row[$address_index] : null);
            $account_no     = nullify(isset($row[$account_no_index]) ? $row[$account_no_index] : null);
            $account_name   = nullify(isset($row[$account_name_index]) ? $row[$account_name_index] : null);
            $amount_paid    = isset($row[$amount_paid_index]) ? floatval(str_replace(',', '', (string)$row[$amount_paid_index])) : 0;
            $charge_to_customer = isset($row[$charge_to_customer_index]) ? floatval(str_replace(',', '', (string)$row[$charge_to_customer_index])) : 0;
            $charge_to_partner  = isset($row[$charge_to_partner_index]) ? floatval(str_replace(',', '', (string)$row[$charge_to_partner_index])) : 0;
            $other_details  = nullify(isset($row[$other_details_index]) ? $row[$other_details_index] : null);
            $branch_id      = nullify(isset($row[$branch_id_index]) ? $row[$branch_id_index] : null);
            $outlet         = nullify(isset($row[$outlet_index]) ? $row[$outlet_index] : null);
            $region_code    = nullify(isset($row[$region_code_index]) ? $row[$region_code_index] : null);
            $region_name    = nullify(isset($row[$region_name_index]) ? $row[$region_name_index] : null);
            $operator       = nullify(isset($row[$operator_index]) ? $row[$operator_index] : null);
            $remote_branch  = nullify(isset($row[$remote_branch_index]) ? $row[$remote_branch_index] : null);
            $remote_operator= nullify(isset($row[$remote_operator_index]) ? $row[$remote_operator_index] : null);
            $second_approver= nullify(isset($row[$second_approver_index]) ? $row[$second_approver_index] : null);
            $partner_id_raw = isset($row[$partner_id_index]) ? $row[$partner_id_index] : null;
            $partner_name   = nullify(isset($row[$partner_name_index]) ? $row[$partner_name_index] : null);
            $status         = nullify(isset($row[$status_index]) ? $row[$status_index] : null);

            // Extract only numeric part from Partner ID (also nullifies \N/empty)
            $partner_id = extractNumericPartnerId($partner_id_raw);

            // Convert Excel date / string date to proper datetime; fall back to import time only if unusable
            $datetime = nullify($datetime_raw);
            if ($datetime !== null && is_numeric($datetime) && $datetime > 40000) {
                $timestamp = ($datetime - 25569) * 86400;
                $datetime = date('Y-m-d H:i:s', $timestamp);
            } elseif ($datetime !== null && strtotime($datetime)) {
                $datetime = date('Y-m-d H:i:s', strtotime($datetime));
            } else {
                $datetime = $imported_date;
            }

            // Do NOT skip rows just because CONTROL NO (or other optional fields) is \N/empty.
            // Those fields will be inserted as NULL; the rest of the available data is still imported.

            // Bind parameters - status is now properly preserved
            // Note: passing PHP null with "s" type causes mysqli to send NULL to MySQL
            $stmt->bind_param(
                "sssssssddsssssssssssssss",
                $datetime,
                $control_no,
                $reference_no,
                $payor,
                $address,
                $account_no,
                $account_name,
                $amount_paid,
                $charge_to_customer,
                $charge_to_partner,
                $other_details,
                $branch_id,
                $outlet,
                $region_code,
                $region_name,
                $operator,
                $remote_branch,
                $remote_operator,
                $second_approver,
                $partner_id,
                $partner_name,
                $status,
                $imported_by,
                $imported_date
            );

            if ($stmt->execute()) {
                $inserted_count++;
            } else {
                $error_count++;
                $ctrl_display = $control_no ?? '(null)';
                $errors[] = "Error inserting row (Control No: $ctrl_display): " . $stmt->error;
            }
        }

        $stmt->close();

        $response['success'] = true;
        $response['message'] = "Successfully imported $inserted_count records.";
        if ($error_count > 0) {
            $response['message'] .= " Failed to import $error_count records.";
            $response['errors'] = $errors;
        }

    } catch (Exception $e) {
        $response['message'] = 'Import failed: ' . $e->getMessage();
    }

    session_write_close();
    echo json_encode($response);
    exit;
}

// Normal page flow
@include_once __DIR__ . '/../../../templates/middleware.php';
$id = resolve_user_identifier();
if (empty($id)) {
    header('Location: ../../../login_form.php');
    exit;
}
if (!function_exists('has_any_permission') || !has_any_permission(['Import Transaction','Bills Payment'])) {
    header('Location: ../../home.php');
    exit;
}

$current_user_name = $_SESSION['admin_name'] ?? $_SESSION['user_name'] ?? '';
$imported_by = $_SESSION['admin_name'] ?? $_SESSION['user_name'] ?? 'System';

// Initialize variables
$csv_data = [];
$headers = [];
$file_uploaded = false;
$error_message = '';

// Maximum rows to DISPLAY (stats still use full data)
$display_limit = 1000;

// Handle file upload – CSV ONLY
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file'];
    
    if ($file['error'] === UPLOAD_ERR_OK) {
        $file_info = pathinfo($file['name']);
        $extension = strtolower($file_info['extension'] ?? '');
        
        if ($extension === 'csv') {
            try {
                $handle = fopen($file['tmp_name'], 'r');
                if ($handle !== false) {
                    // Headers = row 1
                    $headers = fgetcsv($handle);
                    
                    // Data starts from row 2
                    while (($row = fgetcsv($handle)) !== false) {
                        if (isset($row[0]) && $row[0] !== '' && $row[0] !== null) {
                            if (is_string($row[0]) && trim($row[0]) === '') {
                                continue;
                            }
                            $csv_data[] = $row;
                        }
                    }
                    fclose($handle);
                    $file_uploaded = true;
                } else {
                    $error_message = 'Unable to open the uploaded CSV file.';
                }
            } catch (Exception $e) {
                $error_message = 'Error processing file: ' . $e->getMessage();
            }
        } else {
            $error_message = 'Please upload a CSV (.csv) file only.';
        }
    } else {
        $error_message = 'File upload error. Please try again.';
    }
}

// Store / retrieve from session
if ($file_uploaded && !empty($csv_data)) {
    $_SESSION['csv_data'] = $csv_data;
    $_SESSION['csv_headers'] = $headers;
    $_SESSION['csv_uploaded'] = true;
} elseif (isset($_SESSION['csv_uploaded']) && $_SESSION['csv_uploaded']) {
    $csv_data = $_SESSION['csv_data'] ?? [];
    $headers = $_SESSION['csv_headers'] ?? [];
    $file_uploaded = true;
}

// ===== FULL DATA STATISTICS (always based on original complete data) =====
$total_records = count($csv_data);

$total_amount = 0;
$posted_count = 0;
$status_index = array_search('STATUS', $headers);
$amount_index = array_search('AMOUNT PAID', $headers);
$partner_id_index = array_search('PARTNER ID', $headers);
$partner_name_index = array_search('PARTNER NAME', $headers);

foreach ($csv_data as $row) {
    if ($amount_index !== false && isset($row[$amount_index]) && is_numeric($row[$amount_index])) {
        $total_amount += floatval($row[$amount_index]);
    }
    if ($status_index !== false && isset($row[$status_index]) && strtoupper(trim($row[$status_index])) === 'POSTED') {
        $posted_count++;
    }
}

// Status distribution (full data)
$status_counts = [];
if ($status_index !== false) {
    foreach ($csv_data as $row) {
        $status = isset($row[$status_index]) ? strtoupper(trim($row[$status_index])) : 'UNKNOWN';
        if (!isset($status_counts[$status])) {
            $status_counts[$status] = 0;
        }
        $status_counts[$status]++;
    }
}

// ===== PARTNER ID MAPPING RULES =====
// Define mapping for partner IDs based on partner name
$partner_mapping = [
    'BAYADCENTER' => '9999',
    'SKYPAY API' => '811'
];

// Apply mapping to CSV data
if ($partner_id_index !== false && $partner_name_index !== false && !empty($csv_data)) {
    $mapped_count = 0;
    foreach ($csv_data as &$row) {
        if (isset($row[$partner_name_index]) && isset($row[$partner_id_index])) {
            $partner_name = trim((string)$row[$partner_name_index]);
            $current_partner_id = trim((string)$row[$partner_id_index]);
            
            // Check if this partner name needs mapping
            foreach ($partner_mapping as $name_pattern => $new_id) {
                if (stripos($partner_name, $name_pattern) !== false) {
                    // Only map if the current partner ID doesn't already match the target
                    if ($current_partner_id !== $new_id) {
                        $row[$partner_id_index] = $new_id;
                        $mapped_count++;
                    }
                    break;
                }
            }
        }
    }
    unset($row); // Break reference
    
    // Store mapped data back to session
    $_SESSION['csv_data'] = $csv_data;
}

// ===== PARTNER ID VALIDATION against masterdata.partner_masterfile.partner_id_kpx =====
$invalid_partner_ids = [];      // unique list of missing Partner IDs
$invalid_partner_rows = 0;      // how many rows have an invalid Partner ID
$empty_partner_rows = 0;        // how many rows have empty/null/\N Partner ID
$empty_partner_ids = [];        // unique list of empty values found
$partner_name_lookup = [];      // cache for partner names

if ($partner_id_index !== false && !empty($csv_data)) {

    // 1. Collect all unique non-empty Partner IDs from the CSV
    $csv_partner_ids = [];
    $empty_values = ['', 'null', 'NULL', '\N', '\\N'];
    
    foreach ($csv_data as $row) {
        if (isset($row[$partner_id_index])) {
            $pid = trim((string)$row[$partner_id_index]);
            
            // Check if the value is empty, null, or \N
            if ($pid === '' || in_array($pid, $empty_values, true)) {
                $empty_partner_rows++;
                $empty_partner_ids[$pid] = true;
                continue;
            }
            
            // Only add non-empty values for validation
            $csv_partner_ids[$pid] = true;
        }
    }
    $csv_partner_ids = array_keys($csv_partner_ids);
    $empty_partner_ids = array_keys($empty_partner_ids);

    if (!empty($csv_partner_ids)) {
        // 2. Fetch valid partner_id_kpx and partner_name from the masterfile
        $valid_partner_ids = [];
        $partner_name_lookup = [];

        try {
            // Check if $conn exists and is a valid mysqli object
            if (!isset($conn) || !($conn instanceof mysqli)) {
                if (file_exists('../../../config/config.php')) {
                    include '../../../config/config.php';
                }
            }
            
            if (isset($conn) && $conn instanceof mysqli) {
                $result = $conn->query(
                    "SELECT partner_id_kpx, partner_name 
                     FROM masterdata.partner_masterfile 
                     WHERE partner_id_kpx IS NOT NULL 
                       AND TRIM(partner_id_kpx) <> ''"
                );
                if ($result) {
                    while ($r = $result->fetch_assoc()) {
                        $valid_partner_ids[trim($r['partner_id_kpx'])] = true;
                        $partner_name_lookup[trim($r['partner_id_kpx'])] = $r['partner_name'];
                    }
                    $result->free();
                }
            } else {
                error_log('Database connection not available for partner validation.');
            }
        } catch (Exception $e) {
            error_log('Partner ID validation error: ' . $e->getMessage());
        }

        // 3. Find which CSV Partner IDs do NOT exist in the masterfile
        foreach ($csv_partner_ids as $pid) {
            if (!isset($valid_partner_ids[$pid])) {
                $invalid_partner_ids[] = $pid;
            }
        }

        // 4. Count how many rows are affected by invalid IDs (excluding empty ones)
        if (!empty($invalid_partner_ids)) {
            $invalid_set = array_flip($invalid_partner_ids);
            foreach ($csv_data as $row) {
                $pid = isset($row[$partner_id_index]) ? trim((string)$row[$partner_id_index]) : '';
                // Skip empty values
                if ($pid === '' || in_array($pid, ['null', 'NULL', '\N', '\\N'], true)) {
                    continue;
                }
                if ($pid !== '' && isset($invalid_set[$pid])) {
                    $invalid_partner_rows++;
                }
            }
        }
    }
}

// ===== DISPLAY DATA (limited to first 1000 rows) =====
$display_data = array_slice($csv_data, 0, $display_limit);
$displayed_count = count($display_data);

$current_user_name = $_SESSION['admin_name'] ?? $_SESSION['user_name'] ?? '';


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Transaction - Monthly | <?php if($_SESSION['user_type'] === 'admin' || $_SESSION['user_type'] === 'user') echo ucfirst($_SESSION['user_type']); else echo "Guest";?></title>
    <link rel="stylesheet" href="../../../assets/css/templates/style.css?v=<?php echo time(); ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://kit.fontawesome.com/30b908cc5a.js" crossorigin="anonymous"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../../assets/js/sweetalert2.all.min.js"></script>
    <link rel="icon" href="../../../images/MLW logo.png" type="image/png">
    <link rel="stylesheet" href="css/billspay_transaction.css?v=<?= time(); ?>">
    <style>
        .upload-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 2px dashed #dee2e6;
        }
        .table-responsive {
            max-height: 600px;
            overflow-y: auto;
        }
       
        .table th {
            position: sticky;
            top: 0;
            background: #343a40;
            color: white;
            z-index: 10;
        }
        .table td {
            white-space: nowrap;
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .table-striped tbody tr:nth-of-type(odd) {
            background-color: rgba(0,0,0,.02);
        }
        .badge-status {
            padding: 5px 10px;
            border-radius: 20px;
            font-weight: 500;
        }
        .badge-status.posted {
            background: #d4edda;
            color: #155724;
        }
        .badge-status.pending {
            background: #fff3cd;
            color: #856404;
        }
        .badge-status.failed {
            background: #f8d7da;
            color: #721c24;
        }
        .badge-status.unknown {
            background: #e2e3e5;
            color: #383d41;
        }
        .stats-card {
            background: #fee5e5;
            padding: 5px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 5px;
            text-align: center;
            transition: transform 0.2s;
        }
        .stats-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        .stats-card .number {
            font-size: 20px;
            font-weight: bold;
            color: #ff0000;
        }
        .stats-card .label {
            color: #6c757d;
            font-size: 14px;
            margin-top: 5px;
        }
        .stats-card.primary .number,
        .stats-card.success .number,
        .stats-card.warning .number,
        .stats-card.info .number,
        .stats-card.danger .number {
            color: #dc3545;
        }
        
        /* Compact Status Distribution */
        .status-chart-compact {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
        }
        .status-item-compact {
            background: #f8f9fa;
            padding: 4px 10px;
            border-radius: 4px;
            text-align: center;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .status-item-compact .badge-status {
            padding: 2px 8px;
            font-size: 12px;
        }
        .status-item-compact .count {
            font-size: 14px;
            font-weight: bold;
            color: #ff0000;
        }
        
        .data-info {
            font-size: 14px;
            color: #6c757d;
        }
        
        .display-limit-note {
            font-size: 13px;
            color: #856404;
            background: #fff3cd;
            padding: 6px 12px;
            border-radius: 6px;
            display: inline-block;
        }
        
        .partner-empty {
            color: #856404;
            font-style: italic;
            background: #fff3cd;
            padding: 2px 8px;
            border-radius: 4px;
            border: 1px dashed #ffc107;
        }
        
        .partner-mapped {
            color: #155724;
            background: #d4edda;
            padding: 2px 8px;
            border-radius: 4px;
            border: 1px solid #c3e6cb;
            font-weight: 500;
        }
        
        /* Compact validation summary */
        .validation-summary-compact {
            padding: 6px 12px;
            margin-bottom: 10px;
            border-radius: 4px;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 6px 12px;
            font-size: 13px;
        }
        .validation-summary-compact .badge-item {
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .validation-summary-compact .badge-item .badge {
            font-size: 12px;
            padding: 3px 8px;
        }
        .validation-summary-compact .divider {
            color: #6c757d;
            opacity: 0.3;
        }
        .validation-summary-compact .badge.bg-danger,
        .validation-summary-compact .badge.bg-success {
            font-size: 12px;
            padding: 3px 8px;
        }
        
        /* Compact Status Distribution Card */
        .compact-card {
            margin-bottom: 10px;
        }
        .compact-card .card-body {
            padding: 8px 12px;
        }
        .compact-card .card-title {
            font-size: 13px;
            margin-bottom: 4px;
        }
        .compact-card .card-title i {
            font-size: 12px;
        }
        
        /* Data Table Card - added bottom margin for spacing */
        .data-table-card {
            margin-bottom: 30px;
        }
        
        .mapping-info {
            font-size: 12px;
            color: #155724;
            background: #d4edda;
            padding: 4px 10px;
            border-radius: 4px;
            display: inline-block;
        }
        
        @media (max-width: 768px) {
            .stats-card .number {
                font-size: 20px;
            }
            .table td {
                max-width: 100px;
            }
            .status-item-compact {
                padding: 3px 6px;
                font-size: 12px;
            }
            .validation-summary-compact {
                flex-direction: column;
                align-items: flex-start;
                gap: 4px;
            }
            .data-table-card {
                margin-bottom: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="main-container">
        <?php include '../../../templates/header_ui.php'; ?>
        <?php include '../../../templates/sidebar.php'; ?>
        
        <div class="container-fluid">
            <div class="row">
                <div class="col-12">
                    <h2 style="text-align: center; margin-top: 1%; font-size: 25px;">
                        Import Monthly Transactions
                    </h2>
                    
                    <?php if ($error_message): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="fas fa-exclamation-circle me-2"></i>
                            <?= htmlspecialchars($error_message) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Upload Section -->
                    <div class="upload-section">
                        <form method="POST" enctype="multipart/form-data" id="uploadForm">
                            <div class="row align-items-end">
                                <div class="col-md-6">
                                    <label for="csv_file" class="form-label fw-bold">
                                        <i class="fas fa-file-upload me-2"></i>Upload CSV File
                                    </label>
                                    <input type="file" class="form-control" id="csv_file" name="csv_file" accept=".csv">
                                </div>
                                <div class="col-md-3">
                                    <button type="submit" class="btn btn-danger w-100">
                                        <i class="fas fa-upload me-2"></i>Upload & Display
                                    </button>
                                </div>
                                <div class="col-md-3">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="clearData()">
                                        <i class="fas fa-times me-2"></i>Clear
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                    
                    <?php if ($file_uploaded && !empty($csv_data)): ?>
                        <!-- Statistics Cards (FULL DATA) -->
                        <div class="row mb-3">
                            <div class="col-md-3 col-sm-6">
                                <div class="stats-card primary">
                                    <div class="number"><?= number_format($total_records) ?></div>
                                    <div class="label"><i class="fas fa-file-invoice me-1"></i>Total Transactions</div>
                                </div>
                            </div>
                            <div class="col-md-3 col-sm-6">
                                <div class="stats-card success">
                                    <div class="number">₱ <?= number_format($total_amount, 2) ?></div>
                                    <div class="label"><i class="fas fa-money-bill-wave me-1"></i>Total Amount</div>
                                </div>
                            </div>
                            <div class="col-md-3 col-sm-6">
                                <div class="stats-card info">
                                    <div class="number"><?= number_format($posted_count) ?></div>
                                    <div class="label"><i class="fas fa-check-circle me-1"></i>Posted</div>
                                </div>
                            </div>
                            <div class="col-md-3 col-sm-6">
                                <div class="stats-card warning">
                                    <div class="number"><?= number_format($total_records - $posted_count) ?></div>
                                    <div class="label"><i class="fas fa-clock me-1"></i>Other Status</div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Status Distribution (COMPACT) -->
                        <?php if (!empty($status_counts)): ?>
                        <div class="compact-card card">
                            <div class="card-body">
                                <h6 class="card-title mb-1">
                                    <i class="fas fa-chart-pie me-1"></i>Status Distribution
                                </h6>
                                <div class="status-chart-compact">
                                    <?php foreach ($status_counts as $status => $count): ?>
                                    <div class="status-item-compact">
                                        <span class="badge-status <?= strtolower(trim($status)) ?>">
                                            <?= htmlspecialchars($status) ?>
                                        </span>
                                        <span class="count"><?= number_format($count) ?></span>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Partner ID Mapping Info -->
                        <?php if ($partner_id_index !== false && $partner_name_index !== false): ?>
                        <div class="mapping-info mb-2">
                            <i class="fas fa-exchange-alt me-1"></i>
                            <strong>Auto-mapping:</strong>
                            BAYADCENTER → 9999 | SKYPAY API → 811
                        </div>
                        <?php endif; ?>

                        <!-- Partner ID Validation Summary - COMPACT SINGLE ROW -->
                        <?php if ($partner_id_index !== false): ?>
                        <div class="validation-summary-compact alert <?= (!empty($invalid_partner_ids) || $empty_partner_rows > 0) ? 'alert-danger' : 'alert-success' ?> border-0 shadow-sm">
                            <i class="fas fa-id-card me-1"></i>
                            <strong>Unknown Partner ID:</strong>
                            
                            <?php if ($empty_partner_rows > 0): ?>
                            <span class="badge-item">
                                <span class="badge bg-danger">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    <?= number_format($empty_partner_rows) ?> empty
                                </span>
                            </span>
                            <?php endif; ?>
                            
                            <?php if (!empty($invalid_partner_ids)): ?>
                            <span class="badge-item">
                                <span class="badge bg-danger">
                                    <i class="fas fa-times-circle"></i>
                                    <?= number_format($invalid_partner_rows) ?> rows
                                </span>
                            </span>
                            <?php endif; ?>
                            
                            <?php if (empty($invalid_partner_ids) && $empty_partner_rows == 0): ?>
                            <span class="badge bg-success">
                                <i class="fas fa-check-circle"></i> All valid
                            </span>
                            <?php endif; ?>
                            
                            <?php if (!empty($invalid_partner_ids)): ?>
                            <span class="divider">|</span>
                            <span class="badge-item">
                                <span class="text-danger fw-bold">Invalid IDs:</span>
                                <?php foreach ($invalid_partner_ids as $bad_id): ?>
                                    <span class="badge bg-danger"><?= htmlspecialchars($bad_id) ?></span>
                                <?php endforeach; ?>
                            </span>
                            <?php endif; ?>
                            
                            <?php if ($empty_partner_rows > 0): ?>
                            <span class="divider">|</span>
                            <span class="badge-item">
                                <span class="text-danger fw-bold">Empty values:</span>
                                <?php foreach ($empty_partner_ids as $empty_val): ?>
                                    <span class="badge bg-danger text-light">
                                        <?= $empty_val === '' ? '(empty)' : htmlspecialchars($empty_val) ?>
                                    </span>
                                <?php endforeach; ?>
                            </span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Data Table (DISPLAY LIMITED TO 1000) -->
                        <div class="card data-table-card">
                            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <h5 class="mb-0">
                                    <i class="fas fa-table me-2"></i>Transaction Data
                                </h5>
                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                    <span class="data-info">
                                        Showing <?= number_format($displayed_count) ?> of <?= number_format($total_records) ?> records
                                    </span>
                                    <?php if ($total_records > $display_limit): ?>
                                        <span class="display-limit-note">
                                            <i class="fas fa-info-circle me-1"></i>
                                            Display limited to first 1000 rows but all rows will be imported.
                                        </span>
                                    <?php endif; ?>
                                    <button class="btn btn-success btn-sm" onclick="importData()">
                                        <i class="fas fa-save me-2"></i>Import
                                    </button>
                                    <button class="btn btn-secondary btn-sm" onclick="exportData()">
                                        <i class="fas fa-file-export me-2"></i>Export
                                    </button>
                                </div>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-striped table-hover table-bordered mb-0" id="transactionTable">
                                        <thead>
                                            <tr>
                                                <th class="text-center">#</th>
                                                <?php foreach ($headers as $header): ?>
                                                    <th><?= htmlspecialchars($header) ?></th>
                                                <?php endforeach; ?>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($display_data as $index => $row): ?>
                                                <?php 
                                                // Check if this row has an empty/null Partner ID
                                                $has_empty_partner = false;
                                                $is_empty_value = false;
                                                $empty_values = ['', 'null', 'NULL', '\N', '\\N'];
                                                if ($partner_id_index !== false && isset($row[$partner_id_index])) {
                                                    $pid = trim((string)$row[$partner_id_index]);
                                                    if ($pid === '' || in_array($pid, $empty_values, true)) {
                                                        $has_empty_partner = true;
                                                        $is_empty_value = $pid;
                                                    }
                                                }
                                                
                                                // Check if this row has an invalid Partner ID
                                                $has_invalid_partner = false;
                                                if ($partner_id_index !== false && isset($row[$partner_id_index])) {
                                                    $pid = trim((string)$row[$partner_id_index]);
                                                    if ($pid !== '' && !in_array($pid, $empty_values, true) && 
                                                        !empty($invalid_partner_ids) && in_array($pid, $invalid_partner_ids, true)) {
                                                        $has_invalid_partner = true;
                                                    }
                                                }
                                                
                                                // Check if this row was mapped
                                                $is_mapped = false;
                                                $original_partner_id = '';
                                                if ($partner_id_index !== false && $partner_name_index !== false && isset($row[$partner_id_index]) && isset($row[$partner_name_index])) {
                                                    $pid = trim((string)$row[$partner_id_index]);
                                                    $pname = trim((string)$row[$partner_name_index]);
                                                    foreach ($partner_mapping as $name_pattern => $new_id) {
                                                        if (stripos($pname, $name_pattern) !== false) {
                                                            $is_mapped = true;
                                                            $original_partner_id = $new_id;
                                                            break;
                                                        }
                                                    }
                                                }
                                                
                                                $row_class = '';
                                                if ($has_invalid_partner) {
                                                    $row_class = 'table-danger';
                                                } elseif ($has_empty_partner) {
                                                    $row_class = 'table-warning';
                                                } elseif ($is_mapped) {
                                                    $row_class = 'table-success';
                                                }
                                                ?>
                                                <tr class="<?= $row_class ?>">
                                                    <td class="text-center"><?= $index + 1 ?></td>
                                                    <?php foreach ($headers as $col_index => $header): ?>
                                                        <td>
                                                            <?php 
                                                            $value = isset($row[$col_index]) ? $row[$col_index] : '';
                                                            
                                                            if ($header === 'STATUS') {
                                                                $status_class = strtolower(trim($value));
                                                                if (!in_array($status_class, ['posted', 'pending', 'failed'])) {
                                                                    $status_class = 'unknown';
                                                                }
                                                                echo '<span class="badge-status ' . $status_class . '">' . htmlspecialchars($value) . '</span>';
                                                            } elseif ($header === 'AMOUNT PAID' && is_numeric($value)) {
                                                                echo '₱ ' . number_format(floatval($value), 2);
                                                            } elseif ($header === 'DATE') {
                                                                if (is_numeric($value) && $value > 40000) {
                                                                    $timestamp = ($value - 25569) * 86400;
                                                                    echo date('Y-m-d H:i:s', $timestamp);
                                                                } else {
                                                                    echo htmlspecialchars($value);
                                                                }
                                                            } elseif ($header === 'CONTROL NO' || $header === 'REFERENCE NO') {
                                                                echo '<code>' . htmlspecialchars($value) . '</code>';
                                                            } elseif ($header === 'PARTNER ID') {
                                                                $pid = trim((string)$value);
                                                                $empty_values = ['', 'null', 'NULL', '\N', '\\N'];
                                                                
                                                                // Check if this is a mapped value
                                                                $is_mapped_value = false;
                                                                if ($partner_name_index !== false && isset($row[$partner_name_index])) {
                                                                    $pname = trim((string)$row[$partner_name_index]);
                                                                    foreach ($partner_mapping as $name_pattern => $new_id) {
                                                                        if (stripos($pname, $name_pattern) !== false && $pid === $new_id) {
                                                                            $is_mapped_value = true;
                                                                            break;
                                                                        }
                                                                    }
                                                                }
                                                                
                                                                // Check if empty/null
                                                                if ($pid === '' || in_array($pid, $empty_values, true)) {
                                                                    echo '<span class="partner-empty" title="Partner ID is empty or null">';
                                                                    echo '<i class="fas fa-exclamation-triangle me-1"></i>';
                                                                    echo $pid === '' ? '(empty)' : htmlspecialchars($pid);
                                                                    echo '</span>';
                                                                } 
                                                                // Check if mapped
                                                                elseif ($is_mapped_value) {
                                                                    echo '<span class="partner-mapped" title="Partner ID was auto-mapped">';
                                                                    echo '<i class="fas fa-exchange-alt me-1"></i>';
                                                                    echo htmlspecialchars($pid);
                                                                    echo ' <small>(mapped)</small>';
                                                                    echo '</span>';
                                                                }
                                                                // Check if invalid
                                                                elseif (!empty($invalid_partner_ids) && in_array($pid, $invalid_partner_ids, true)) {
                                                                    echo '<span class="badge bg-danger" title="Partner ID not found in masterfile">'
                                                                       . htmlspecialchars($pid) . ' <i class="fas fa-times-circle"></i></span>';
                                                                } 
                                                                // Valid
                                                                else {
                                                                    // Check if we have the partner name from lookup
                                                                    if (isset($partner_name_lookup[$pid])) {
                                                                        echo '<span title="' . htmlspecialchars($partner_name_lookup[$pid]) . '">';
                                                                        echo '<code>' . htmlspecialchars($pid) . '</code>';
                                                                        echo ' <small class="text-muted">' . htmlspecialchars($partner_name_lookup[$pid]) . '</small>';
                                                                        echo '</span>';
                                                                    } else {
                                                                        echo '<code>' . htmlspecialchars($pid) . '</code>';
                                                                    }
                                                                }
                                                            } elseif ($header === 'PARTNER NAME') {
                                                                // Check if this partner name triggered a mapping
                                                                $pname = trim((string)$value);
                                                                $is_mapped_name = false;
                                                                $mapped_to = '';
                                                                foreach ($partner_mapping as $name_pattern => $new_id) {
                                                                    if (stripos($pname, $name_pattern) !== false) {
                                                                        $is_mapped_name = true;
                                                                        $mapped_to = $new_id;
                                                                        break;
                                                                    }
                                                                }
                                                                if ($is_mapped_name) {
                                                                    echo '<span class="partner-mapped" title="Mapped to Partner ID: ' . $mapped_to . '">';
                                                                    echo htmlspecialchars($value);
                                                                    echo ' <i class="fas fa-arrow-right"></i> ' . $mapped_to;
                                                                    echo '</span>';
                                                                } else {
                                                                    echo htmlspecialchars($value);
                                                                }
                                                            } else {
                                                                echo htmlspecialchars($value);
                                                            }
                                                            ?>
                                                        </td>
                                                    <?php endforeach; ?>
                                                </tr>
                                            <?php endforeach; ?>
                                            
                                            <?php if (empty($display_data)): ?>
                                                <tr>
                                                    <td colspan="<?= count($headers) + 1 ?>" class="text-center py-4">
                                                        <i class="fas fa-inbox fa-2x text-muted d-block mb-2"></i>
                                                        <span class="text-muted">No data available</span>
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        
                    <?php elseif ($file_uploaded && empty($csv_data)): ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            No data found in the file. Please check if the file contains valid data in column 1 (DATE column).
                        </div>
                    <?php else: ?>
                        <!-- Empty State -->
                        <div class="text-center py-5">
                            <div class="mb-4">
                                <i class="fas fa-file-upload fa-4x text-muted"></i>
                            </div>
                            <h4>No File Uploaded</h4>
                            <p class="text-muted">Upload a CSV file to view transactions</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Robust clear function
        function clearData() {
            Swal.fire({
                title: 'Clear Data?',
                text: "This will remove all uploaded data from the session.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, clear!'
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        title: 'Clearing...',
                        text: 'Please wait while we clear the data.',
                        allowOutsideClick: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });

                    $.ajax({
                        url: window.location.pathname,
                        type: 'POST',
                        data: { action: 'clear_csv_data' },
                        dataType: 'json',
                        cache: false,
                        timeout: 10000,
                        success: function(response) {
                            if (response && response.success) {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Cleared!',
                                    text: response.message || 'Data has been cleared successfully.',
                                    timer: 1200,
                                    showConfirmButton: false
                                }).then(() => {
                                    window.location.href = window.location.pathname;
                                });
                            } else {
                                window.location.href = window.location.pathname;
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error('Clear AJAX Error:', status, error, xhr.responseText);
                            Swal.fire({
                                icon: 'warning',
                                title: 'Clear attempted',
                                text: 'Reloading page to ensure data is cleared.',
                                timer: 1500,
                                showConfirmButton: false
                            }).then(() => {
                                window.location.href = window.location.pathname;
                            });
                        }
                    });
                }
            });
        }
        
        // Import using session data
        function importData() {
            // Check if there are issues with Partner IDs
            <?php if (!empty($invalid_partner_ids) || $empty_partner_rows > 0): ?>
            let warningMessage = 'The following Partner ID issues were found:\n\n';
            <?php if ($empty_partner_rows > 0): ?>
            warningMessage += '• <?= number_format($empty_partner_rows) ?> row(s) have empty/null Partner ID\n';
            <?php endif; ?>
            <?php if (!empty($invalid_partner_ids)): ?>
            warningMessage += '• <?= number_format($invalid_partner_rows) ?> row(s) have invalid Partner ID\n';
            warningMessage += '  Invalid IDs: <?= implode(', ', $invalid_partner_ids) ?>\n';
            <?php endif; ?>
            warningMessage += '\nDo you want to continue with the import anyway?';
            
            Swal.fire({
                title: 'Warning: Partner ID Issues',
                text: warningMessage,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#28a745',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Yes, import anyway!',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    proceedWithImport();
                }
            });
            <?php else: ?>
            Swal.fire({
                title: 'Import Transactions?',
                text: "This will import all <?= number_format($total_records) ?> transactions to the database.",
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#28a745',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Yes, import all!',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    proceedWithImport();
                }
            });
            <?php endif; ?>
        }
        
        function proceedWithImport() {
            Swal.fire({
                title: 'Importing...',
                text: 'Please wait while we import the transactions.',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            // Use the session data import endpoint
            $.ajax({
                url: window.location.pathname,
                type: 'POST',
                data: {
                    action: 'import_from_session'
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Success!',
                            text: response.message || 'All transactions imported successfully.',
                            timer: 3000
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error!',
                            text: response.message || 'Failed to import transactions. Please try again.'
                        });
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Import Error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Error!',
                        text: 'Failed to import transactions. Please try again.'
                    });
                }
            });
        }
        
        // Export currently exports only displayed rows
        function exportData() {
            const table = document.querySelector('#transactionTable');
            let csv = [];
            
            const headers = [];
            table.querySelectorAll('thead th').forEach((th, index) => {
                if (index > 0) {
                    headers.push(th.textContent.trim());
                }
            });
            csv.push(headers.join(','));
            
            table.querySelectorAll('tbody tr').forEach(row => {
                const rowData = [];
                const cells = row.querySelectorAll('td');
                for (let i = 1; i < cells.length; i++) {
                    let text = cells[i].textContent.trim();
                    text = text.replace('₱ ', '').replace(/,/g, '');
                    text = text.replace(/\(empty\)/g, '');
                    text = text.replace(/\\N/g, '');
                    text = text.replace(/\(mapped\)/g, '');
                    text = text.replace(/mapped/g, '');
                    text = text.trim();
                    if (text.includes(',') || text.includes('"')) {
                        text = '"' + text.replace(/"/g, '""') + '"';
                    }
                    rowData.push(text);
                }
                if (rowData.length > 0 && rowData.some(cell => cell !== '')) {
                    csv.push(rowData.join(','));
                }
            });
            
            const blob = new Blob(['\uFEFF' + csv.join('\n')], { type: 'text/csv;charset=utf-8;' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'transactions_export_' + new Date().toISOString().slice(0,10) + '.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        }
        
        // Auto-submit on file select
        $(document).ready(function() {
            $('#csv_file').change(function() {
                if ($(this).val()) {
                    const submitBtn = $(this).closest('form').find('button[type="submit"]');
                    const originalText = submitBtn.html();
                    submitBtn.html('<i class="fas fa-spinner fa-spin me-2"></i>Uploading...');
                    submitBtn.prop('disabled', true);
                    
                    $('#uploadForm').submit();
                    
                    setTimeout(function() {
                        submitBtn.html(originalText);
                        submitBtn.prop('disabled', false);
                    }, 3000);
                }
            });
        });
    </script>
    
    <?php include '../../../templates/footer.php'; ?>
</body>
</html>