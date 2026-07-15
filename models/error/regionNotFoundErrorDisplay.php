<?php
include '../../config/config.php';
require '../../vendor/autoload.php';

session_start();

if (isset($_SESSION['user_type'])) {
    $current_user_email = '';
    if ($_SESSION['user_type'] === 'admin' && isset($_SESSION['admin_email'])) {
        $current_user_email = $_SESSION['admin_email'];
    } elseif ($_SESSION['user_type'] === 'user' && isset($_SESSION['user_email'])) {
        $current_user_email = $_SESSION['user_email'];
        if($_SESSION['user_email'] === 'balb01013333' || $_SESSION['user_email'] === 'pera94005055'){
            header("Location:../../index.php");
            session_destroy();
            exit();
        }
    }else{
        header("Location:../../index.php");
        session_destroy();
        exit();
    }
}

// Get region not found data from session
$regionNotFoundData = $_SESSION['region_not_found_data'] ?? [];
$originalFileName = $_SESSION['original_file_name'] ?? 'Unknown File';
$sourceFileType = $_SESSION['source_file_type'] ?? 'Unknown';
$transactionDate = $_SESSION['transactionDate'] ?? date('Y-m-d');

// Calculate totals
$totalRows = count($regionNotFoundData);
$totalAmount = 0;
$totalChargeCustomer = 0;
$totalChargePartner = 0;

foreach ($regionNotFoundData as $row) {
    $totalAmount += floatval($row['amount_paid']);
    $totalChargeCustomer += floatval($row['amount_charge_customer']);
    $totalChargePartner += floatval($row['amount_charge_partner']);
}

function formatCurrency($amount) {
    return '₱ ' . number_format((float)$amount, 2);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Region Not Found - Import Error</title>
    <link rel="stylesheet" href="../../assets/css/billspaymentImportFile.css">
    <link rel="icon" href="../../images/MLW logo.png" type="image/png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.all.min.js"></script>
</head>
<body>
    <div class="container-fluid mt-4">
        <div class="alert alert-danger border-0 shadow-sm" role="alert">
            <div class="d-flex align-items-center mb-3">
                <i class="fas fa-exclamation-triangle fa-2x text-danger me-3"></i>
                <div>
                    <h4 class="alert-heading mb-1">Region Validation Error</h4>
                    <p class="mb-0">The following records have branch outlets that don't match the database region for their Branch ID.</p>
                </div>
            </div>
            
            <div class="row mb-3">
                <div class="col-md-6">
                    <p class="mb-1"><strong>File:</strong> <?php echo htmlspecialchars($originalFileName); ?></p>
                    <p class="mb-1"><strong>Source Type:</strong> <?php echo htmlspecialchars($sourceFileType); ?></p>
                </div>
                <div class="col-md-6">
                    <p class="mb-1"><strong>Transaction Date:</strong> <?php echo date('F d, Y', strtotime($transactionDate)); ?></p>
                    <p class="mb-1"><strong>Affected Records:</strong> <?php echo number_format($totalRows); ?></p>
                </div>
            </div>

            <div class="row mb-3 justify-content-evenly">
                <div class="col-md-3 col-sm-6 mb-3">
                    <div class="card bg-light border-0">
                        <div class="card-body text-center">
                            <h5 class="card-title text-primary">Total Amount</h5>
                            <h4 class="text-primary"><?php echo formatCurrency($totalAmount); ?></h4>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6 mb-3">
                    <div class="card bg-light border-0">
                        <div class="card-body text-center">
                            <h5 class="card-title text-info">Customer Charges</h5>
                            <h4 class="text-info"><?php echo formatCurrency($totalChargeCustomer); ?></h4>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6 mb-3">
                    <div class="card bg-light border-0">
                        <div class="card-body text-center">
                            <h5 class="card-title text-warning">Partner Charges</h5>
                            <h4 class="text-warning"><?php echo formatCurrency($totalChargePartner); ?></h4>
                        </div>
                    </div>
                </div>
            </div>

            <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                <table class="table table-striped table-hover">
                    <thead class="table-dark sticky-top">
                        <tr>
                            <th>Row</th>
                            <th>Branch Outlet (File)</th>
                            <th>Region Description</th>
                            <th>Reference Number</th>
                            <th>Payor Name</th>
                            <th>Amount Paid</th>
                            <th>Customer Charge</th>
                            <th>Partner Charge</th>
                            <th>Date/Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($regionNotFoundData as $row): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['row']); ?></td>
                            <td><span class="text-danger fw-bold"><?php echo htmlspecialchars($row['branch_outlet']); ?></span></td>
                            <td><?php echo htmlspecialchars($row['region_description']); ?></td>
                            <td><?php echo htmlspecialchars($row['reference_number']); ?></td>
                            <td><?php echo htmlspecialchars($row['payor_name']); ?></td>
                            <td><?php echo formatCurrency($row['amount_paid']); ?></td>
                            <td><?php echo formatCurrency($row['amount_charge_customer']); ?></td>
                            <td><?php echo formatCurrency($row['amount_charge_partner']); ?></td>
                            <td><?php echo htmlspecialchars($row['datetime']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="d-flex justify-content-between mt-4">
                <!-- <button type="button" class="btn btn-secondary" onclick="window.history.back()">
                    <i class="fas fa-arrow-left me-2"></i>Go Back
                </button> -->
                <button type="button" class="btn btn-primary" onclick="window.print()">
                    <i class="fas fa-print me-2"></i>Print Report
                </button>
                <!-- <button type="button" class="btn btn-danger" onclick="clearSession()">
                    <i class="fas fa-times me-2"></i>Clear & Start Over
                </button> -->
                <button type="button" class="btn btn-success btn-lg" onclick="clearAndReturn()">
                    <i class="fas fa-upload me-2"></i>Upload Different File
                </button>
            </div>
        </div>
    </div>

    <!-- <script>
        function clearSession() {
            Swal.fire({
                title: 'Clear Session?',
                text: "This will clear all uploaded data and start over.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Yes, clear it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    // Send POST request with JSON data
                    fetch('clearSession.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            action: 'clear_import_session'
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.status === 'success') {
                            Swal.fire({
                                title: 'Success!',
                                text: 'Session cleared successfully. Redirecting...',
                                icon: 'success',
                                timer: 1500,
                                showConfirmButton: false
                            }).then(() => {
                                // Redirect to main page or file import page
                                window.location.href = 'billspaymentImportFile.php';
                            });
                        } else {
                            Swal.fire({
                                title: 'Error!',
                                text: data.message || 'Failed to clear session',
                                icon: 'error'
                            });
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        Swal.fire({
                            title: 'Error!',
                            text: 'An error occurred while clearing the session',
                            icon: 'error'
                        });
                    });
                }
            });
        }
    </script> -->
    <script>
        function clearAndReturn() {
            Swal.fire({
                title: 'Clear Session Data?',
                text: 'This will clear all current session data and return to the upload page.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#28a745',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, clear and return',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    fetch('../../clear/clearSession.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({action: 'clear_import_session'})
                    })
                    .then(() => {
                        window.location.href = '../../dashboard/billspayment/import/billspay-transaction.php';
                    });
                }
            });
        }
    </script>
</body>
</html>