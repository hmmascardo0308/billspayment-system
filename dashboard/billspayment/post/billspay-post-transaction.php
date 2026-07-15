<?php
// Connect to the database
require_once __DIR__ . '/../../../config/config.php';

require '../../../vendor/autoload.php';

// Start the session
session_start();


if (isset($_SESSION['user_type'])) {
    $current_user_email = '';
    if ($_SESSION['user_type'] === 'admin' && isset($_SESSION['admin_email'])) {
        $current_user_email = $_SESSION['admin_email'];
    } elseif ($_SESSION['user_type'] === 'user' && isset($_SESSION['user_email'])) {
        $current_user_email = $_SESSION['user_email'];
    }
}

// Prevent headers already sent: ensure unauthenticated users are redirected before any output
if (empty($_SESSION['user_type'])) {
    header('Location: ../../../logout.php');
    exit();
}

if(isset($_POST['proceed'])) {
    $startingDate = $_POST['startingDate'];

    // Validate the input
    if (!empty($startingDate)) {
        try {
            // Parse the month-year input (format: YYYY-MM)
            $year = date('Y', strtotime($startingDate));
            $month = date('m', strtotime($startingDate));
            $lastDay = date('t', strtotime($startingDate));
            
            // Create date range for the selected month
            $startDate = date('Y-m-d H:i:s', strtotime($year . '-' . $month . '-01 00:00:00')); // Start of the month

            $endDate = date('Y-m-d H:i:s', strtotime($year . '-' . $month . '-' . $lastDay . ' 23:59:59')); // Last day of the month

            // SELECT query to get bills payment transactions
            $query = "SELECT * FROM mldb.billspayment_transaction WHERE post_transaction = 'unposted' AND (datetime BETWEEN ? AND ? OR cancellation_date BETWEEN ? AND ?) ORDER BY datetime, cancellation_date DESC";

            // Prepare and execute the statement
            $stmt = $conn->prepare($query);
            $stmt->bind_param("ssss", $startDate, $endDate, $startDate, $endDate);
            $stmt->execute();
            $result = $stmt->get_result();
            
            // Fetch all results
            $transactions = [];
            $totalTransactions = 0;
            $totalPrincipalAmount = 0;
            $totalChargeToPartner = 0;
            $totalChargeToCustomer = 0;
            
            foreach ($result as $row) {
                $transactions[] = $row;
                $totalTransactions++;
                $totalPrincipalAmount += $row['amount_paid'];
                $totalChargeToPartner += $row['charge_to_partner'];
                $totalChargeToCustomer += $row['charge_to_customer'];
            }

            // Store results in session variables
            $_SESSION['transactions'] = $transactions;
            $_SESSION['totalTransactions'] = $totalTransactions;
            $_SESSION['totalPrincipalAmount'] = $totalPrincipalAmount;
            $_SESSION['totalChargeToPartner'] = $totalChargeToPartner;
            $_SESSION['totalChargeToCustomer'] = $totalChargeToCustomer;

            $_SESSION['startdate'] = $startDate;
            $_SESSION['enddate'] = $endDate;
            // Close the statement

            $stmt->close();
            
        } catch (Exception $e) {
            $error_message = "Error retrieving data: " . $e->getMessage();
        }
    } else {
        $error_message = "Please select a valid starting date.";
    }
}

if(isset($_POST['posted'])) {
    $transactions = $_SESSION['transactions'] ?? [];
    $startDate = $_SESSION['startdate'] ?? '';
    $endDate = $_SESSION['enddate'] ?? '';
    $dsql = "UPDATE mldb.billspayment_transaction SET post_transaction = 'posted' WHERE datetime BETWEEN ? AND ? OR cancellation_date BETWEEN ? AND ?";
    $dstmt = $conn->prepare($dsql);
    $dstmt->bind_param("ssss", $startDate, $endDate, $startDate, $endDate);
    $dstmt->execute();
    $dstmt->close();
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Post Transaction | <?php if($_SESSION['user_type'] === 'admin' || $_SESSION['user_type'] === 'user') echo ucfirst($_SESSION['user_type']); else echo "Guest";?></title>
    <!-- custom CSS file link  -->
    <link rel="stylesheet" href="../../../assets/css/templates/style.css?v=<?php echo time(); ?>">
    <script src="https://kit.fontawesome.com/30b908cc5a.js" crossorigin="anonymous"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="../../../assets/js/sweetalert2.all.min.js"></script>

    <link rel="icon" href="../../../images/MLW logo.png" type="image/png">

    <style>
        /* Print styles */
        @media print {
            body * {
                visibility: hidden;
                visibility: visible;
            }
            .alert-warning {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                border: none !important;
                background-color: white !important;
                color: black !important;
            }
            .alert-warning .d-flex {
                display: none !important;
            }
            .alert-warning h4 {
                text-align: center;
                font-size: 18px;
                margin-bottom: 15px;
            }
            .alert-warning p {
                text-align: center;
                margin-bottom: 15px;
            }

            /* Card responsive enhancements */
            .card {
                border-radius: 0.5rem;
                transition: box-shadow 0.15s ease-in-out;
            }

            .card:hover {
                box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15) !important;
            }

            .card-header {
                border-bottom: 2px solid rgba(255, 255, 255, 0.2);
            }

            .card-footer {
                border-top: 1px solid #dee2e6;
            }

            /* Make sure the table-responsive container shows all content */
            .table-responsive {
                max-height: none !important;
                height: auto !important;
                overflow: visible !important;
                border-radius: 0.375rem;
                max-height: 60vh;
                overflow-y: auto;
            }
            .table {
                width: 100%;
                border-collapse: collapse;
                page-break-inside: auto;
                margin-bottom: 0;
            }

            .table th {
                background-color: #343a40 !important;
                position: sticky;
                top: 0;
                z-index: 10;
            }

            .table thead th {
                position: sticky;
                top: 0;
                background-color: #212529 !important;
                color: white !important;
                z-index: 10;
                box-shadow: 0 2px 2px -1px rgba(0, 0, 0, 0.4);
                border-bottom: 2px solid #454d55;
            }

            .table th, .table td {
                border: 1px solid #000;
            }
            .table tr {
                page-break-inside: avoid;
                page-break-after: auto;
            }
            .sticky-top {
                position: sticky !important;
                top: 0 !important;
                z-index: 1020;
            }
        }

        /* Ensure table header stays on top during scroll */
        .sticky-top {
            position: sticky !important;
            top: 0 !important;
            z-index: 1020;
        }

        /* Custom scrollbar styling */
        .table-responsive::-webkit-scrollbar {
            width: 8px;
        }

        .table-responsive::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }

        .table-responsive::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 4px;
        }

        .table-responsive::-webkit-scrollbar-thumb:hover {
            background: #555;
        }

        

        /* Mobile responsiveness */
        @media (max-width: 768px) {
            .card-body {
                padding: 0.75rem;
            }
            
            .table-responsive {
                max-height: 300px;
                font-size: 0.875rem;
            }
            
            .table thead th {
                padding: 0.5rem 0.25rem;
                font-size: 0.8rem;
            }

            .btn-sm {
                padding: 0.25rem 0.5rem;
                font-size: 0.775rem;
            }

            .table td {
                padding: 0.5rem 0.25rem;
                font-size: 0.8rem;
            }
            
            .alert {
                padding: 0.5rem;
                font-size: 0.875rem;
            }
            
            .card-header h4 {
                font-size: 1.25rem;
            }
            
        }

        @media (max-width: 576px) {
            .container-fluid {
                padding-left: 0.5rem;
                padding-right: 0.5rem;
            }
            
            .table-responsive {
                max-height: 250px;
            }

            .table thead th {
                padding: 0.4rem 0.2rem;
                font-size: 0.75rem;
            }
            
            .table td {
                padding: 0.4rem 0.2rem;
                font-size: 0.75rem;
            }
            
            .d-flex.gap-2 {
                gap: 0.5rem !important;
            }
            
            .btn-sm {
                padding: 0.2rem 0.4rem;
                font-size: 0.75rem;
            }
        }

        /* Print styles enhancement */
        @media print {
            .card {
                box-shadow: none !important;
                border: 1px solid #000 !important;
            }
            
            .card-header, .card-footer {
                background-color: white !important;
                color: black !important;
            }
            
            .card-footer {
                display: none !important;
            }

            .table-responsive {
                max-height: none !important;
                overflow: visible !important;
                border: none !important;
            }
            
            .table thead th {
                position: static !important;
                box-shadow: none !important;
            }
            
            .sticky-top {
                position: static !important;
            }
        }

        /* Scrollable table with sticky header */
        .table-responsive {
            max-height: 300px; /* Adjust height as needed */
            overflow-y: auto;
            border: 1px solid #dee2e6;
            border-radius: 0.375rem;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        }

        .table {
            margin-bottom: 0;
        }

        .table thead th {
            position: sticky;
            top: 0;
            background-color: #212529 !important;
            color: white !important;
            z-index: 10;
            box-shadow: 0 2px 2px -1px rgba(0, 0, 0, 0.4);
            border-bottom: 2px solid #454d55;
        }

        /* Ensure table header stays on top during scroll */
        .sticky-top {
            position: sticky !important;
            top: 0 !important;
            z-index: 1020;
        }

        /* Custom scrollbar styling */
        .table-responsive::-webkit-scrollbar {
            width: 8px;
        }

        .table-responsive::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }

        .table-responsive::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 4px;
        }

        .table-responsive::-webkit-scrollbar-thumb:hover {
            background: #555;
        }

        /* Mobile responsiveness for scrollable table */
        @media (max-width: 768px) {
            .table-responsive {
                max-height: 300px;
                font-size: 0.875rem;
            }
            
            .table thead th {
                padding: 0.5rem 0.25rem;
                font-size: 0.8rem;
            }
            
            .table td {
                padding: 0.5rem 0.25rem;
                font-size: 0.8rem;
            }
        }

        @media (max-width: 576px) {
            .table-responsive {
                max-height: 250px;
            }
            
            .table thead th {
                padding: 0.4rem 0.2rem;
                font-size: 0.75rem;
            }
            
            .table td {
                padding: 0.4rem 0.2rem;
                font-size: 0.75rem;
            }
        }

        /* Print styles - remove scroll and show full table */
        @media print {
            .table-responsive {
                max-height: none !important;
                overflow: visible !important;
                border: none !important;
            }
            
            .table thead th {
                position: static !important;
                box-shadow: none !important;
            }
            
            .sticky-top {
                position: static !important;
            }
        }

        
        /* Enhanced SweetAlert2 backdrop for confidentiality */
        .swal2-container.swal2-backdrop-show {
            backdrop-filter: blur(10px);
            background-color: rgba(0,0,0,0.8) !important;
        }
        
        /* Make sure the modal itself is still clear */
        .swal2-popup {
            backdrop-filter: none !important;
            box-shadow: 0 0 20px rgba(0,0,0,0.5);
        }

        /* Remove borders from summary table */
        .alert-info .table {
            border: none;
        }

        .alert-info .table td {
            border: none !important;
            border-top: none !important;
            border-bottom: none !important;
            border-left: none !important;
            border-right: none !important;
        }

        .alert-info .table tbody tr {
            border: none;
        }

        /* Remove hover effect and shadow from summary table */
        .alert-info .table tbody tr:hover {
            background-color: transparent !important;
            box-shadow: none !important;
        }

        .alert-info .table-hover tbody tr:hover {
            background-color: transparent !important;
        }

        .alert-info .table {
            box-shadow: none !important;
        }

        /* Ensure no borders in print mode for summary table */
        @media print {
            .alert-info .table,
            .alert-info .table td,
            .alert-info .table tbody tr {
                border: none !important;
                box-shadow: none !important;
            }
        }

        /* Loading overlay styles */
        #loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.7);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            backdrop-filter: blur(3px);
        }

        .loading-content {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
        }

        .loading-spinner {
            width: 60px;
            height: 60px;
            border: 6px solid #f3f3f3;
            border-top: 6px solid #dc3545;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-bottom: 20px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Loading text */
        .loading-text {
            color: white;
            font-size: 18px;
            font-weight: 500;
            text-align: center;
            margin-top: 0;
        }

        /* Section header matching new header_ui style: white with red left accent */
        .bp-section-header {
            background: #ffffff;
            border-left: 6px solid #dc3545;
            padding: 12px 16px;
            margin: 10px 0 18px 0;
            box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.04);
        }
        .bp-section-header h2 {
            margin: 0;
            font-size: 1.25rem;
            color: #212529;
            font-weight: 700;
        }
    </style>
</head>
<body>
    <div class="main-container">
        <?php include '../../../templates/header_ui.php'; ?>
        <!-- Show and Hide Side Nav Menu -->
        <?php include '../../../templates/sidebar.php'; ?>

        <?php
            $showPostButton = false;
            if (!empty($_SESSION['startdate']) && !empty($_SESSION['enddate'])) {
                $psql = "SELECT COUNT(*) as cnt FROM mldb.billspayment_transaction WHERE post_transaction = 'unposted' AND (datetime BETWEEN ? AND ? OR cancellation_date BETWEEN ? AND ? )";
                $pstmt = $conn->prepare($psql);
                if ($pstmt) {
                    $pstmt->bind_param('ssss', $_SESSION['startdate'], $_SESSION['enddate'], $_SESSION['startdate'], $_SESSION['enddate']);
                    $pstmt->execute();
                    $cres = $pstmt->get_result();
                    $crow = $cres->fetch_assoc();
                    $unpostedCount = intval($crow['cnt'] ?? 0);
                    if ($unpostedCount > 0) {
                        $showPostButton = true;
                    }
                    $pstmt->close();
                }
            }
        ?>
        <div id="loading-overlay">
            <div class="loading-spinner"></div>
        </div>
        <div class="bp-section-header" role="region" aria-label="Page title">
            <div class="bp-section-title">
                <i class="fa-solid fa-check-to-slot" aria-hidden="true"></i>
                <div>
                    <h2>Post Transaction</h2>
                    <p class="bp-section-sub">Post unposted transactions for the selected month</p>
                </div>
            </div>
        </div>

        <div class="container-fluid border border-danger rounded mt-3">
            <div class="container-fluid">
                <form id="uploadForm" action="" method="post" enctype="multipart/form-data">
                    <div class="row mt-4 w-100 align-items-center" style="display: -webkit-inline-box; display: -moz-inline-box; display: -ms-inline-flexbox; flex-wrap: wrap; gap: 15px;">
                        <!-- Starting Date Picker -->
                        <div class="col-md-3 mb-3" style="flex: 0 0 auto;">
                            <div class="d-flex align-items-center">
                                <label for="startingDate" class="form-label me-2 mb-0 text-nowrap">Date:</label>
                                <input type="month" id="startingDate" name="startingDate" class="form-control" required>
                            </div>
                        </div>

                        <!-- Proceed and Post Buttons -->
                        <div class="col-md-3 mb-3 d-flex" style="flex: 0 0 auto; gap:8px;">
                            <input type="submit" class="btn btn-danger" name="proceed" value="Proceed">
                            <button type="button" id="postInlineButton" class="btn btn-success" <?php echo $showPostButton ? '' : 'style="display:none;"'; ?>>
                                <i class="fas fa-check me-1"></i>POST
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <?php if (isset($_POST['proceed'])) :
            $transactions = $_SESSION['transactions'] ?? [];
            $totalTransactions = $_SESSION['totalTransactions'] ?? 0;
            $totalPrincipalAmount = $_SESSION['totalPrincipalAmount'] ?? 0;
            $totalChargeToPartner = $_SESSION['totalChargeToPartner'] ?? 0;
            $totalChargeToCustomer = $_SESSION['totalChargeToCustomer'] ?? 0;
        ?>
            <div class="container accordion mt-3">
                <div class="card shadow-sm">
                    <div class="card-header bg-danger text-white">
                        <h4 class="text-center mb-0">Preview</h4>
                    </div>
                    <div class="card-body">
                        <!-- Branch ID Not Found Alert -->
                        <div class="alert alert-success d-flex align-items-start mb-3" role="alert">
                            <i class="fas fa-exclamation-circle me-2 mt-1"></i>
                            <div>
                                <h6 class="alert-heading mb-1">Bills Payment Report</h6>
                                <p class="mb-0">Date: <strong><?php echo $_POST['startingDate']; ?></strong></p>
                            </div>
                        </div>
                        
                        <!-- Rows per page selector -->
                        <div class="d-flex justify-content-end align-items-center mb-2">
                            <label for="rowsPerPage" class="me-2 mb-0">Rows:</label>
                            <select id="rowsPerPage" class="form-select form-select-sm" style="width:120px;">
                                <option value="10" selected>10</option>
                                <option value="100">100</option>
                                <option value="200">200</option>
                                <option value="500">500</option>
                                <option value="1000">1000</option>
                                <option value="all">All</option>
                            </select>
                        </div>

                        <!-- Data Table -->
                        <div class="table-responsive">
                            <table class="table table-striped table-bordered table-hover">
                                <thead class="table-dark sticky-top">
                                    <tr>
                                        <th scope="col" class="text-nowrap">Branch ID</th>
                                        <th scope="col" class="text-nowrap">ML Branch Outlet</th>
                                        <th scope="col" class="text-nowrap">Region</th>
                                        <th scope="col" class="text-nowrap">Reference Number</th>
                                        <th scope="col" class="text-nowrap">Amount Paid</th>
                                        <th scope="col" class="text-nowrap">Charge to Partner</th>
                                        <th scope="col" class="text-nowrap">Charge to Customer</th>
                                    </tr>
                                </thead>
                                <tbody id="transactionsBody">
                                    <tr><td colspan="7" class="text-center">Loading transactions...</td></tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination controls -->
                        <div class="d-flex justify-content-between align-items-center mt-2">
                            <div id="pageInfo" class="text-muted">&nbsp;</div>
                            <div>
                                <button id="prevPage" class="btn btn-sm btn-outline-secondary me-1">Prev</button>
                                <button id="nextPage" class="btn btn-sm btn-outline-secondary">Next</button>
                            </div>
                        </div>
                        
                        <!-- Error Summary -->
                        <div class="alert alert-info d-flex align-items-start mt-3" role="alert">
                            <i class="fas fa-info-circle me-2 mt-1"></i>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <tbody>
                                        <?php if (empty($transactions)) :?>
                                            <tr>
                                                <td><strong>Total Number of Transaction</strong></td>
                                                <td>:</td>
                                                <td>N/A</td>
                                            </tr>
                                            <tr>
                                                <td><strong>Total Principal Amount</strong></td>
                                                <td>:</td>
                                                <td>N/A</td>
                                            </tr>
                                            <tr>
                                                <td><strong>Total Charge to Partner</strong></td>
                                                <td>:</td>
                                                <td>N/A</td>
                                            </tr>
                                            <tr>
                                                <td><strong>Total Charge to Customer</strong></td>
                                                <td>:</td>
                                                <td>N/A</td>
                                            </tr>
                                        <?php else: 
                                            $hasPendingTransactions = false;
                                            // Check if there are any pending transactions
                                            foreach ($transactions as $transaction) {
                                                if ($transaction['post_transaction'] === 'unposted') {
                                                    $hasPendingTransactions = true;
                                                    break;
                                                }
                                            }

                                            if ($hasPendingTransactions) :
                                            
                                            ?>
                                            
                                                <tr>
                                                    <td><strong>Total Number of Transaction</strong></td>
                                                    <td>:</td>
                                                    <td><?php echo number_format($totalTransactions); ?></td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Total Principal Amount</strong></td>
                                                    <td>:</td>
                                                    <td><?php echo number_format($totalPrincipalAmount, 2); ?></td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Total Charge to Partner</strong></td>
                                                    <td>:</td>
                                                    <td><?php echo number_format($totalChargeToPartner, 2); ?></td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Total Charge to Customer</strong></td>
                                                    <td>:</td>
                                                    <td><?php echo number_format($totalChargeToCustomer, 2); ?></td>
                                                </tr>
                                                
                                            <?php else: ?>
                                                <tr>
                                                    <td><strong>Total Number of Transaction</strong></td>
                                                    <td>:</td>
                                                    <td><?php echo $totalTransactions; ?></td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Total Principal Amount</strong></td>
                                                    <td>:</td>
                                                    <td><?php echo number_format($totalPrincipalAmount, 2); ?></td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Total Charge to Partner</strong></td>
                                                    <td>:</td>
                                                    <td><?php echo number_format($totalChargeToPartner, 2); ?></td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Total Charge to Customer</strong></td>
                                                    <td>:</td>
                                                    <td><?php echo number_format($totalChargeToCustomer, 2); ?></td>
                                                </tr>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Action Buttons in Card Footer -->
                    <!-- Footer actions moved inline next to Proceed. Footer intentionally left empty. -->
                </div>
            </div>
        <?php endif; ?>

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const startingDateInput = document.getElementById('startingDate');
                const uploadForm = document.getElementById('uploadForm');
                const loadingOverlay = document.getElementById('loading-overlay');
                const postButton = document.querySelector('button[name="posted"]');
                
                // Function to show loading overlay
                function showLoading(message = 'Processing...') {
                    const loadingText = document.querySelector('.loading-text');
                    if (loadingText) {
                        loadingText.textContent = message;
                    }
                    loadingOverlay.style.display = 'flex';
                }
                
                // Function to hide loading overlay
                function hideLoading() {
                    loadingOverlay.style.display = 'none';
                }
                
                // Handle "Proceed" form submission
                if (uploadForm) {
                    uploadForm.addEventListener('submit', function(e) {
                        // Store the current selected date before form submission
                        const currentDate = startingDateInput.value;
                        if (currentDate) {
                            sessionStorage.setItem('selectedBillsPaymentDate', currentDate);
                            sessionStorage.setItem('hasFormSubmission', 'true');
                            showLoading('Loading transactions...');
                        } else {
                            e.preventDefault();
                            Swal.fire({
                                icon: 'warning',
                                title: 'Date Required',
                                text: 'Please select a date before proceeding.',
                                confirmButtonColor: '#dc3545'
                            });
                        }
                    });
                }
                
                // Handle inline POST button click (calls lightweight endpoint to avoid session locking)
                const postInlineButton = document.getElementById('postInlineButton');
                if (postInlineButton) {
                    postInlineButton.addEventListener('click', function(e) {
                        e.preventDefault();
                        Swal.fire({
                            title: 'Confirm Posting',
                            text: 'Are you sure you want to post these transactions? This action cannot be undone.',
                            icon: 'warning',
                            showCancelButton: true,
                            confirmButtonColor: '#28a745',
                            cancelButtonColor: '#6c757d',
                            confirmButtonText: 'Yes, Post Transactions',
                            cancelButtonText: 'Cancel'
                        }).then((result) => {
                            if (result.isConfirmed) {
                                showLoading('Posting transactions to database...');

                                // Call lightweight endpoint which DOES NOT use PHP session to avoid session lock delays
                                $.ajax({
                                    url: 'post_transactions_ajax.php',
                                    method: 'POST',
                                    data: {
                                        startDate: '<?php echo $_SESSION['startdate'] ?? ''; ?>',
                                        endDate: '<?php echo $_SESSION['enddate'] ?? ''; ?>'
                                    },
                                    dataType: 'json',
                                    success: function(resp) {
                                        hideLoading();
                                        if (resp.success) {
                                            Swal.fire({
                                                icon: 'success',
                                                title: 'Success!',
                                                text: resp.message || 'Transactions posted',
                                                confirmButtonColor: '#28a745'
                                            }).then(() => {
                                                // Reload page to refresh UI
                                                window.location.href = window.location.pathname;
                                            });
                                        } else {
                                            showError(resp.message || 'Failed to post transactions');
                                        }
                                    },
                                    error: function(xhr, status, err) {
                                        hideLoading();
                                        console.error('Error posting:', err);
                                        showError('Network or server error while posting');
                                    }
                                });

                            }
                        });
                    });
                }
                
                // Hide loading overlay when page loads (in case of redirect)
                window.addEventListener('load', function() {
                    hideLoading();
                });
                
                // Set input type to month for better UX
                startingDateInput.type = 'month';
                
                // Check if there's a POST value to maintain the selected date
                <?php if (isset($_POST['startingDate']) && !empty($_POST['startingDate'])): ?>
                    startingDateInput.value = '<?php echo $_POST['startingDate']; ?>';
                    sessionStorage.setItem('selectedBillsPaymentDate', '<?php echo $_POST['startingDate']; ?>');
                    sessionStorage.setItem('hasFormSubmission', 'true');
                <?php else: ?>
                    sessionStorage.removeItem('selectedBillsPaymentDate');
                    sessionStorage.removeItem('hasFormSubmission');
                    startingDateInput.value = '';
                <?php endif; ?>
                
                // Function to format date to "Month Year" format
                function formatToMonthYear(dateValue) {
                    if (!dateValue) return '--------- ----';
                    
                    const date = new Date(dateValue + '-01');
                    const options = { year: 'numeric', month: 'long' };
                    return date.toLocaleDateString('en-US', options);
                }
                
                // Function to update the display
                function updateDateDisplay() {
                    const selectedValue = startingDateInput.value;
                    const formattedDate = formatToMonthYear(selectedValue);
                    
                    const dateDisplay = document.querySelector('.alert-success p strong');
                    if (dateDisplay) {
                        dateDisplay.textContent = formattedDate;
                    }
                    
                    const allDateDisplays = document.querySelectorAll('[data-date-display]');
                    allDateDisplays.forEach(display => {
                        display.textContent = formattedDate;
                    });
                }
                
                // Listen for changes on the date input
                startingDateInput.addEventListener('change', function() {
                    updateDateDisplay();
                    if (this.value) {
                        sessionStorage.setItem('selectedBillsPaymentDate', this.value);
                    } else {
                        sessionStorage.removeItem('selectedBillsPaymentDate');
                    }
                });
                
                // Set initial display
                updateDateDisplay();

                // Rows-per-page control to limit rendered rows and reduce UI lag
                const rowsPerPageSelect = document.getElementById('rowsPerPage');
                function applyRowLimit() {
                    const tbody = document.querySelector('.table tbody');
                    if (!tbody) return;
                    const rows = Array.from(tbody.querySelectorAll('tr'));
                    const val = rowsPerPageSelect ? rowsPerPageSelect.value : '10';
                    if (val === 'all') {
                        rows.forEach(r => r.style.display = '');
                    } else {
                        const limit = parseInt(val, 10) || 10;
                        rows.forEach((r, idx) => {
                            // Keep header-like no-data rows visible
                            if (r.querySelector('td') && r.querySelector('td').getAttribute('colspan')) {
                                r.style.display = '';
                                return;
                            }
                            r.style.display = (idx < limit) ? '' : 'none';
                        });
                    }
                }

                if (rowsPerPageSelect) {
                    rowsPerPageSelect.addEventListener('change', applyRowLimit);
                    // Apply initial limit after small timeout to ensure table rows exist
                    setTimeout(applyRowLimit, 50);
                }

                // --- AJAX paged fetch implementation ---
                let currentPage = 1;
                let totalRows = 0;

                const prevBtn = document.getElementById('prevPage');
                const nextBtn = document.getElementById('nextPage');
                const pageInfo = document.getElementById('pageInfo');

                function renderRows(rows) {
                    const tbody = document.getElementById('transactionsBody');
                    if (!tbody) return;
                    if (!rows || rows.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="7" class="text-center">No transactions found for the selected date.</td></tr>';
                        return;
                    }
                    let html = '';
                    rows.forEach(function(tx) {
                        html += '<tr>' +
                            '<td class="text-center">' + (tx.branch_id !== null ? escapeHtml(tx.branch_id) : '') + '</td>' +
                            '<td class="text-nowrap">' + (tx.outlet !== null ? escapeHtml(tx.outlet) : '') + '</td>' +
                            '<td class="text-nowrap">' + (tx.region !== null ? escapeHtml(tx.region) : '') + '</td>' +
                            '<td class="text-nowrap">' + (tx.reference_no !== null ? escapeHtml(tx.reference_no) : '') + '</td>' +
                            '<td class="text-end">' + (tx.amount_paid !== null ? numberWithCommas(parseFloat(tx.amount_paid).toFixed(2)) : '0.00') + '</td>' +
                            '<td class="text-end">' + (tx.charge_to_partner !== null ? numberWithCommas(parseFloat(tx.charge_to_partner).toFixed(2)) : '0.00') + '</td>' +
                            '<td class="text-end">' + (tx.charge_to_customer !== null ? numberWithCommas(parseFloat(tx.charge_to_customer).toFixed(2)) : '0.00') + '</td>' +
                        '</tr>';
                    });
                    tbody.innerHTML = html;
                }

                function updatePaginationControls(page, limit) {
                    const total = totalRows || 0;
                    const lim = (limit === 'all') ? total : parseInt(limit, 10) || 10;
                    const totalPages = (lim === 0) ? 1 : Math.max(1, Math.ceil(total / lim));
                    currentPage = Math.min(Math.max(1, page), totalPages);
                    pageInfo.textContent = 'Page ' + currentPage + ' of ' + totalPages + ' — Showing ' + ((total===0)?0:((currentPage-1)*lim+1)) + ' to ' + Math.min(currentPage*lim, total) + ' of ' + total + ' rows';
                    prevBtn.disabled = (currentPage <= 1);
                    nextBtn.disabled = (currentPage >= totalPages);
                }

                function fetchTransactions(page = 1) {
                    const sm = startingDateInput.value || '';
                    if (!sm) return;
                    const rpp = rowsPerPageSelect ? rowsPerPageSelect.value : '10';
                    const limit = (rpp === 'all') ? 'all' : parseInt(rpp, 10) || 10;
                    const offset = (page - 1) * (limit === 'all' ? 0 : limit);
                    showLoading('Loading transactions...');
                    $.ajax({
                        url: 'fetch_transactions_ajax.php',
                        method: 'POST',
                        data: { startingMonth: sm, limit: limit, offset: offset },
                        dataType: 'json',
                        success: function(resp) {
                            hideLoading();
                            if (resp && resp.success) {
                                totalRows = parseInt(resp.total || 0, 10);
                                renderRows(resp.rows || []);
                                updatePaginationControls(page, rpp);
                            } else {
                                renderRows([]);
                                showError(resp.message || 'Unable to load transactions');
                            }
                        },
                        error: function() {
                            hideLoading();
                            showError('Network error while fetching transactions');
                        }
                    });
                }

                // Prev/Next handlers
                if (prevBtn) prevBtn.addEventListener('click', function() { fetchTransactions(currentPage - 1); });
                if (nextBtn) nextBtn.addEventListener('click', function() { fetchTransactions(currentPage + 1); });

                // When rows-per-page changes, fetch page 1
                if (rowsPerPageSelect) rowsPerPageSelect.addEventListener('change', function() { fetchTransactions(1); });

                // helper functions
                function escapeHtml(text) {
                    if (typeof text !== 'string') return text;
                    return text.replace(/[&"'<>]/g, function (a) { return {'&':'&amp;','"':'&quot;',"'":"&#39;","<":"&lt;",">":"&gt;"}[a]; });
                }

                function numberWithCommas(x) {
                    if (x === undefined || x === null) return '0.00';
                    return x.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                }

                // If the user had proceeded server-side, initialize the AJAX fetch
                <?php if (isset($_POST['proceed'])): ?>
                    setTimeout(function() { fetchTransactions(1); }, 50);
                <?php endif; ?>
                
                // Handle browser back/forward navigation
                window.addEventListener('pageshow', function(event) {
                    if (event.persisted) {
                        <?php if (!isset($_POST['startingDate'])): ?>
                            sessionStorage.removeItem('selectedBillsPaymentDate');
                            sessionStorage.removeItem('hasFormSubmission');
                            startingDateInput.value = '';
                            updateDateDisplay();
                        <?php endif; ?>
                    }
                });
            });

            // Function to manually clear the stored date (can be called from other parts of the application)
            function clearStoredBillsPaymentDate() {
                sessionStorage.removeItem('selectedBillsPaymentDate');
                sessionStorage.removeItem('hasFormSubmission');
                const startingDateInput = document.getElementById('startingDate');
                if (startingDateInput) {
                    startingDateInput.value = '';
                    // Update display
                    const dateDisplay = document.querySelector('.alert-success p strong');
                    if (dateDisplay) {
                        dateDisplay.textContent = '--------- ----';
                    }
                }
            }

            // Function to set a specific date programmatically
            function setBillsPaymentDate(dateValue) {
                const startingDateInput = document.getElementById('startingDate');
                if (startingDateInput) {
                    startingDateInput.value = dateValue;
                    if (dateValue) {
                        sessionStorage.setItem('selectedBillsPaymentDate', dateValue);
                    } else {
                        sessionStorage.removeItem('selectedBillsPaymentDate');
                    }
                    
                    // Trigger change event to update display
                    startingDateInput.dispatchEvent(new Event('change'));
                }
            }

            // Function to reset the form to default state
            function resetBillsPaymentForm() {
                clearStoredBillsPaymentDate();
                // Optionally reload the page to ensure clean state
                window.location.href = window.location.pathname;
            }
        </script>
        
    </div>
</body>
<?php include '../../../templates/footer.php'; ?>
</html>