<?php
    session_start();
    require_once __DIR__ . '/../config/config.php';
    require '../vendor/autoload.php';

    if (!isset($_SESSION['admin_name'])) {
        header('location:../login_form.php');
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
        <title>Post File</title>
        <!-- custom CSS file link  -->
        <link rel="stylesheet" href="../assets/css/billspaymentImportFile.css?v=<?php echo time(); ?>">
        <link rel="icon" href="../images/MLW logo.png" type="image/png">
        <!-- Bootstrap CSS -->
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet" />
        <!-- Font Awesome for icons -->
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
        <!-- SweetAlert2 CSS -->
        <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.min.css" rel="stylesheet">
        <script src="../assets/js/sweetalert2.all.min.js"></script>
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
        </style>
    </head>
    <body>
        <div class="top-content">
            <div class="usernav">
                <h4 style="margin-right: 0.5rem; font-size: 1rem;"><?php echo $_SESSION['admin_name'] ?></h4>
                <h5 style="font-size: 1rem;"><?php echo "- ".$_SESSION['admin_email']."" ?></h5>
            </div>
            <?php include '../templates/admin/sidebar.php'; ?>
        </div>
        <div id="loading-overlay">
            <div style="text-align: center;">
                <div class="loading-spinner"></div>
                <div class="loading-text"></div>
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

                        <!-- Proceed Button -->
                        <div class="col-md-3 mb-3 d-flex" style="flex: 0 0 auto;">
                            <input type="submit" class="btn btn-danger" name="proceed" value="Proceed">
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
                                <tbody>
                                    <!-- Sample data rows -->
                                    <?php 
                                    if (empty($transactions)) :
                                        echo '<tr><td colspan="7" class="text-center">No transactions found for the selected date.</td></tr>';
                                    else:
                                        $hasPendingTransactions = false;
                                        // Check if there are any pending transactions
                                        foreach ($transactions as $transaction) {
                                            if ($transaction['post_transaction'] === 'unposted') {
                                                $hasPendingTransactions = true;
                                                break;
                                            }
                                        }

                                        if ($hasPendingTransactions) :
                                            // Loop through transactions and display them
                                            foreach ($transactions as $transaction): 
                                            ?>
                                                <tr>
                                                    <td class="text-center"><?php echo htmlspecialchars($transaction['branch_id']); ?></td>
                                                    <td class="text-nowrap"><?php echo htmlspecialchars($transaction['outlet']); ?></td>
                                                    <td class="text-nowrap"><?php echo htmlspecialchars($transaction['region']); ?></td>
                                                    <td class="text-nowrap"><?php echo htmlspecialchars($transaction['reference_no']); ?></td>
                                                    <td class="text-end"><?php echo number_format($transaction['amount_paid'], 2); ?></td>
                                                    <td class="text-end"><?php echo number_format($transaction['charge_to_partner'], 2); ?></td>
                                                    <td class="text-end"><?php echo number_format($transaction['charge_to_customer'], 2); ?></td>
                                                </tr>
                                            <?php 
                                            endforeach;
                                        else:
                                            foreach ($transactions as $transaction): 
                                                ?>
                                                <tr>
                                                    <td class="text-center"><?php echo htmlspecialchars($transaction['branch_id']); ?></td>
                                                    <td class="text-nowrap"><?php echo htmlspecialchars($transaction['outlet']); ?></td>
                                                    <td class="text-nowrap"><?php echo htmlspecialchars($transaction['region']); ?></td>
                                                    <td class="text-nowrap"><?php echo htmlspecialchars($transaction['reference_no']); ?></td>
                                                    <td class="text-end"><?php echo number_format($transaction['amount_paid'], 2); ?></td>
                                                    <td class="text-end"><?php echo number_format($transaction['charge_to_partner'], 2); ?></td>
                                                    <td class="text-end"><?php echo number_format($transaction['charge_to_customer'], 2); ?></td>
                                                </tr>
                                                <?php
                                            endforeach;
                                            // echo '<tr><td colspan="7" class="text-center">No pending transactions found for the selected date.</td></tr>';
                                        endif;
                                    endif;
                                    ?>
                                </tbody>
                            </table>
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
                    <?php 
                            // Check if there are any pending transactions
                            $hasPendingTransactions = false;
                            if (!empty($transactions)) {
                                foreach ($transactions as $transaction) {
                                    if ($transaction['post_transaction'] === 'unposted') {
                                        $hasPendingTransactions = true;
                                        break;
                                    }
                                }
                            }
                    if($hasPendingTransactions) : ?>
                        <div class="card-footer bg-light">
                            <div class="d-flex flex-wrap justify-content-center gap-2">
                                <button type="button" name="posted" class="btn btn-success btn-md">
                                    <i class="fas fa-check me-1"></i> POST
                                </button>
                            </div>
                        </div>
                    <?php endif; ?>
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
                
                // Handle "POST" button click
                if (postButton) {
                    postButton.addEventListener('click', function(e) {
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
                                
                                // Create form data for POST request
                                const formData = new FormData();
                                formData.append('posted', 'true'); // Changed from 'post_transactions' to 'posted'
                                formData.append('startingDate', startingDateInput.value);
                                
                                // Send AJAX request to post transactions
                                fetch(window.location.href, {
                                    method: 'POST',
                                    body: formData
                                })
                                .then(response => response.text())
                                .then(data => {
                                    hideLoading();
                                    
                                    // Set session variable to indicate successful posting
                                    sessionStorage.setItem('posted', 'true');
                                    
                                    Swal.fire({
                                        icon: 'success',
                                        title: 'Success!',
                                        text: 'Transactions have been posted successfully.',
                                        confirmButtonColor: '#28a745'
                                    }).then(() => {
                                        // Refresh the page to show updated data
                                        // window.location.reload();
                                        window.location.href = window.location.pathname;
                                    });
                                })
                                .catch(error => {
                                    hideLoading();
                                    console.error('Error:', error);
                                    Swal.fire({
                                        icon: 'error',
                                        title: 'Network Error',
                                        text: 'A network error occurred. Please check your connection and try again.',
                                        confirmButtonColor: '#dc3545'
                                    }).then(() => {
                                        // Refresh the page to show updated data
                                        // window.location.reload();
                                        window.location.href = window.location.pathname;
                                    });
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
    </body>
</html>