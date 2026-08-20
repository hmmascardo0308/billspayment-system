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

$current_user_email = $_SESSION['admin_email'] ?? $_SESSION['user_email'] ?? '';
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

// ===== DISPLAY DATA (limited to first 1000 rows) =====
$display_data = array_slice($csv_data, 0, $display_limit);
$displayed_count = count($display_data);
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
            max-height: 650px;
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
            color: #007bff;
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
        
        .status-chart {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .status-item {
            flex: 1;
            min-width: 100px;
            background: #f8f9fa;
            padding: 10px;
            border-radius: 6px;
            text-align: center;
        }
        
        .status-item .count {
            font-size: 20px;
            font-weight: bold;
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
        
        @media (max-width: 768px) {
            .stats-card .number {
                font-size: 20px;
            }
            .table td {
                max-width: 100px;
            }
            .status-item {
                min-width: 60px;
                padding: 5px;
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
                                <div class="col-md-5">
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
                                <div class="col-md-2">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="clearData()">
                                        <i class="fas fa-times me-2"></i>Clear
                                    </button>
                                </div>
                                <div class="col-md-2">
                                    <a href="#" class="btn btn-outline-success w-100" onclick="downloadTemplate()">
                                        <i class="fas fa-download me-2"></i>Template
                                    </a>
                                </div>
                            </div>
                        </form>
                    </div>
                    
                    <?php if ($file_uploaded && !empty($csv_data)): ?>
                        <!-- Statistics Cards (FULL DATA) -->
                        <div class="row mb-4">
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
                        
                        <!-- Status Distribution (FULL DATA) -->
                        <?php if (!empty($status_counts)): ?>
                        <div class="row mb-3">
                            <div class="col-12">
                                <div class="card">
                                    <div class="card-body">
                                        <h6 class="card-title mb-2">
                                            <i class="fas fa-chart-pie me-2"></i>Status Distribution
                                        </h6>
                                        <div class="status-chart">
                                            <?php foreach ($status_counts as $status => $count): ?>
                                            <div class="status-item">
                                                <div class="badge-status <?= strtolower(trim($status)) ?> d-inline-block mb-1">
                                                    <?= htmlspecialchars($status) ?>
                                                </div>
                                                <div class="count"><?= number_format($count) ?></div>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Data Table (DISPLAY LIMITED TO 1000) -->
                        <div class="card">
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
                                            Display limited to first 1000 rows (all <?= number_format($total_records) ?> records are counted & will be imported)
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
                                                <tr>
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
        
        // Import uses FULL data count (from PHP)
        function importData() {
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
                    Swal.fire({
                        title: 'Importing...',
                        text: 'Please wait while we import the transactions.',
                        allowOutsideClick: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                    
                    // Note: Currently still reads only visible table rows.
                    // For full import you should later change this to send session data
                    // or create a dedicated server-side import endpoint that uses $_SESSION['csv_data'].
                    const tableData = [];
                    const table = document.querySelector('#transactionTable');
                    const rows = table.querySelectorAll('tbody tr');
                    
                    rows.forEach(row => {
                        const rowData = [];
                        const cells = row.querySelectorAll('td');
                        for (let i = 1; i < cells.length; i++) {
                            let text = cells[i].textContent.trim();
                            text = text.replace('₱ ', '').replace(/,/g, '');
                            text = text.replace(/POSTED|PENDING|FAILED/g, '');
                            rowData.push(text.trim());
                        }
                        if (rowData.length > 0 && rowData.some(cell => cell !== '')) {
                            tableData.push(rowData);
                        }
                    });
                    
                    $.ajax({
                        url: 'import_transaction.php',
                        type: 'POST',
                        data: {
                            action: 'import',
                            data: JSON.stringify(tableData),
                            headers: JSON.stringify(<?= json_encode($headers) ?>)
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
                    text = text.replace(/POSTED|PENDING|FAILED/g, '');
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
        
        // Template download
        function downloadTemplate() {
            const headers = <?= json_encode($headers) ?>;
            if (headers && headers.length > 0) {
                let csv = headers.join(',') + '\n';
                const sampleRow = headers.map(h => {
                    if (h === 'DATE') return '45500';
                    if (h === 'AMOUNT PAID') return '1000.00';
                    if (h === 'STATUS') return 'PENDING';
                    if (h === 'CONTROL NO' || h === 'REFERENCE NO') return 'SAMPLE001';
                    return '';
                });
                csv += sampleRow.join(',');
                
                const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = 'transaction_template.csv';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                window.URL.revokeObjectURL(url);
            } else {
                const headers = ['DATE','CONTROL NO','REFERENCE NO','PAYOR NAME','ADDRESS','ACCOUNT NO.','ACCOUNT NAME','AMOUNT PAID','CHARGE TO CUSTOMER','CHARGE TO PARTNER','OTHER DETAILS','BRANCH ID','ML OUTLET','REGION CODE','REGION NAME','OPERATOR','REMOTE BRANCH','REMOTE OPERATOR','2ND APPROVER','PARTNER ID','PARTNER NAME','STATUS'];
                let csv = headers.join(',') + '\n';
                csv += '45500,SAMPLE001,SAMPLE002,SAMPLE PAYOR,ADDRESS,123456789,SAMPLE ACCOUNT,1000.00,0,0,PAYMENT,BR001,OUTLET001,REG001,REGION NAME,OPERATOR NAME,,,,PARTNER001,PARTNER NAME,PENDING';
                
                const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = 'transaction_template.csv';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                window.URL.revokeObjectURL(url);
            }
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