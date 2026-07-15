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

// Get partner not found data from session
$partnerNotFoundData = $_SESSION['partner_not_found_data'] ?? [];
$originalFileName = $_SESSION['original_file_name'] ?? 'Unknown File';
$sourceFileType = $_SESSION['source_file_type'] ?? 'Unknown';
$transactionDate = $_SESSION['transactionDate'] ?? date('Y-m-d');

// Remove duplicates and get unique partner IDs
$uniquePartners = [];
$seenPartners = [];

foreach ($partnerNotFoundData as $data) {
    $partnerId = $data['partner_id'] ?? '';
    $partnerName = $data['partner_name'] ?? '';
    
    if (!isset($seenPartners[$partnerId])) {
        $seenPartners[$partnerId] = true;
        $uniquePartners[] = [
            'partner_id' => $partnerId,
            'partner_name' => $partnerName
        ];
    }
}

// Handle form submissions
$showSuccessAlert = false;
$showErrorAlert = false;
$errorMessage = '';

function formatCurrency($amount) {
    return '₱ ' . number_format((float)$amount, 2);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Partner Not Found - Import Error</title>
    <link rel="stylesheet" href="../../assets/css/billspaymentImportFile.css">
    <link rel="icon" href="../../images/MLW logo.png" type="image/png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.all.min.js"></script>

    <style>
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
        
        /* Ensure the table header stays visible when scrolling */
        .sticky-top {
            position: sticky;
            top: 0;
            z-index: 1020;
        }
        
        /* Table row height to maintain consistent 6-row visibility */
        .table tbody tr {
            height: 60px;
        }
        
        /* Smooth scrolling */
        .table-responsive {
            scroll-behavior: smooth;
        }
    </style>
</head>
<body>
    <div class="container-fluid mt-4">
        <div class="alert alert-warning border-0 shadow-sm" role="alert">
            <div class="d-flex align-items-center mb-3">
                <i class="fas fa-exclamation-triangle fa-2x text-warning me-3"></i>
                <div>
                    <h4 class="alert-heading mb-1">Missing Partners Found</h4>
                    <p class="mb-0">The following partner IDs from Excel file and were not recorded in the Masterlist System.</p>
                </div>
            </div>
            
            <div class="row mb-3">
                <div class="col-md-6">
                    <strong><i class="fas fa-file me-2"></i>File:</strong> <?php echo htmlspecialchars($originalFileName); ?>
                </div>
                <div class="col-md-6">
                    <strong><i class="fas fa-cogs me-2"></i>Source:</strong> <?php echo htmlspecialchars($sourceFileType); ?>
                </div>
            </div>

            <div class="row mb-3 justify-content-center">
                <div class="col-md-4 col-sm-6 mb-3">
                    <div class="card bg-primary text-white text-center">
                        <div class="card-body">
                            <i class="fas fa-building fa-2x mb-2"></i>
                            <h5 class="card-title">Missing Partners</h5>
                            <h3 class="mb-0"><?php echo count($uniquePartners); ?></h3>
                        </div>
                    </div>
                </div>
            </div>

            <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                <table class="table table-striped table-hover mb-0">
                    <thead class="table-dark sticky-top">
                        <tr>
                            <th scope="col">#</th>
                            <th scope="col">Partner ID</th>
                            <th scope="col">Partner's Name</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($uniquePartners)): ?>
                            <tr>
                                <td colspan="3" class="text-center text-muted">No missing partners found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($uniquePartners as $index => $partner): ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td class="fw-bold"><?php echo htmlspecialchars($partner['partner_id']); ?></td>
                                <td><?php echo htmlspecialchars($partner['partner_name']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="d-flex justify-content-center align-items-center flex-wrap gap-3 mt-4">
                <form method="post" class="d-inline" action="../generate/pdf/generatePartnerErrorPDF.php" target="_blank">
                    <input type="hidden" name="action" value="export_to_pdf">
                    <input type="hidden" name="partner_data" value="<?php echo htmlspecialchars(json_encode($uniquePartners)); ?>">
                    <input type="hidden" name="file_name" value="<?php echo htmlspecialchars($originalFileName); ?>">
                    <input type="hidden" name="source_type" value="<?php echo htmlspecialchars($sourceFileType); ?>">
                    <input type="hidden" name="transaction_date" value="<?php echo htmlspecialchars($transactionDate); ?>">
                    <button type="submit" class="btn btn-lg shadow-sm" style="background-color: #ffcccb; border-color: #ffcccb; color: #721c24;" onmouseover="this.style.backgroundColor='#ff9999'" onmouseout="this.style.backgroundColor='#ffcccb'">
                        <i class="fas fa-file-pdf me-2"></i>Export to PDF
                    </button>
                </form>
                
                <button type="button" class="btn btn-lg shadow-sm" style="background-color: #90ee90; border-color: #90ee90; color: #2d5016;" onmouseover="this.style.backgroundColor='#7dd87d'" onmouseout="this.style.backgroundColor='#90ee90'" onclick="clearAndReturn()">
                    <i class="fas fa-upload me-2"></i>Upload Different File
                </button>
            </div>
        </div>
    </div>

    <script>

        <?php if ($showErrorAlert): ?>
            document.addEventListener('DOMContentLoaded', function() {
                Swal.fire({
                    icon: "error",
                    title: "Database Error",
                    text: "Failed to add partners to database: <?php echo addslashes($errorMessage); ?>",
                    confirmButtonText: "OK"
                });
            });
        <?php endif; ?>

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
                    fetch('../clear/clearSession.php', {
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