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

// Get error data from session
$partnerGLCodeErrors = $_SESSION['partner_GLCode_not_found_data'] ?? [];
$originalFileName = $_SESSION['original_file_name'] ?? 'Unknown';
$sourceFileType = $_SESSION['source_file_type'] ?? 'Unknown';
$transactionDate = $_SESSION['transactionDate'] ?? date('Y-m-d');

if (empty($partnerGLCodeErrors)) {
    header("Location: ../../dashboard/billspayment/import/billspay-transaction.php");
    exit();
}

// Group partners by partner name to avoid duplicates
$groupedPartners = [];
$totalRecordsAffected = 0;

foreach ($partnerGLCodeErrors as $error) {
    $partnerName = $error['partner_name'];
    $partnerId = $error['partner_id'];
    $partnerIdKpx = $error['partner_id_kpx'];
    $glCode = $error['gl_code'];
    
    // Create a unique key for grouping
    $uniqueKey = $partnerName . '|' . $partnerId . '|' . $partnerIdKpx;
    
    if (!isset($groupedPartners[$uniqueKey])) {
        $groupedPartners[$uniqueKey] = [
            'partner_name' => $partnerName,
            'partner_id' => $partnerId,
            'partner_id_kpx' => $partnerIdKpx,
            'gl_code' => $glCode,
            'record_count' => 0,
            'affected_rows' => []
        ];
    }
    
    $groupedPartners[$uniqueKey]['record_count']++;
    $groupedPartners[$uniqueKey]['affected_rows'][] = $error['row'] ?? 'N/A';
    $totalRecordsAffected++;
}

function formatCurrency($amount) {
    return '₱ ' . number_format((float)$amount, 2);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Partners Missing GL Code | <?php echo ucfirst($_SESSION['user_type'] ?? 'Guest'); ?></title>
    
    <link rel="stylesheet" href="../../assets/css/billspaymentImportFile.css?v=<?php echo time(); ?>">
    <link rel="icon" href="../../images/MLW logo.png" type="image/png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.min.css" rel="stylesheet">
    <script src="../../assets/js/sweetalert2.all.min.js"></script>
    <style>
        .table th {
            background-color: #343a40 !important;
            color: white !important;
            font-weight: 600;
            text-align: center;
            vertical-align: middle;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .table td {
            vertical-align: middle;
        }
        
        /* Center align specific columns to match headers */
        .table td:first-child {
            text-align: center;
            font-weight: 600;
        }
        
        .table td:nth-child(5) {
            text-align: center;
        }
        
        .badge {
            font-size: 0.85em;
        }
        
        .alert-info {
            border-left: 4px solid #0dcaf0;
        }
        
        .table-hover tbody tr:hover {
            background-color: rgba(0, 123, 255, 0.1);
        }
        
        .text-muted {
            cursor: help;
        }
        
        .card-header h5 {
            font-weight: 600;
        }
        
        /* Table scroll styles */
        .table-responsive {
            max-height: 500px;
            overflow-y: auto;
            overflow-x: auto;
            border: 1px solid #dee2e6;
            border-radius: 0.375rem;
        }
        
        .table-container {
            position: relative;
        }
        
        /* Custom scrollbar for webkit browsers */
        .table-responsive::-webkit-scrollbar {
            width: 8px;
            height: 8px;
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
        
        /* Ensure table maintains full width */
        .table {
            margin-bottom: 0;
            min-width: 100%;
        }
        
        /* Sticky header enhancement */
        .table thead th {
            border-bottom: 2px solid #dee2e6;
            box-shadow: 0 2px 2px -1px rgba(0, 0, 0, 0.4);
        }
        
        /* Row hover effect enhancement */
        .table tbody tr:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transition: all 0.2s ease;
        }
        
        @media print {
            .btn, .alert-info {
                display: none !important;
            }
            
            .table-responsive {
                max-height: none !important;
                overflow: visible !important;
            }
        }
        
        /* Mobile responsive adjustments */
        @media (max-width: 768px) {
            .table-responsive {
                max-height: 400px;
            }
            
            .table th, .table td {
                font-size: 0.875rem;
                padding: 0.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="container-fluid py-4">
        <div class="alert alert-danger">
            <div class="d-flex align-items-center mb-3">
                <i class="fas fa-exclamation-triangle fa-2x me-3"></i>
                <div>
                    <h4 class="alert-heading mb-1">Partners Missing GL Code Detected</h4>
                    <p class="mb-0">The following partners do not have GL Codes assigned. Please contact your administrator to assign GL Codes before importing.</p>
                </div>
            </div>
            
            <hr>
            
            <div class="row mb-3">
                <div class="col-md-6">
                    <strong><i class="fas fa-file me-2"></i>File Name:</strong> <?php echo htmlspecialchars($originalFileName); ?>
                </div>
                <div class="col-md-6">
                    <strong><i class="fas fa-calendar me-2"></i>Upload Date:</strong> <?php echo date('F d, Y', strtotime($transactionDate)); ?>
                </div>
            </div>
            
            <div class="row mb-3">
                <div class="col-md-6">
                    <strong><i class="fas fa-cog me-2"></i>File Type:</strong> <?php echo htmlspecialchars($sourceFileType); ?>
                </div>
                <div class="col-md-6">
                    <strong><i class="fas fa-user me-2"></i>Uploaded By:</strong> <?php echo htmlspecialchars($_SESSION['admin_name'] ?? $_SESSION['user_name'] ?? 'Unknown'); ?>
                </div>
            </div>
        </div>

        <div class="card shadow">
            <div class="card-header bg-danger text-white">
                <h5 class="mb-0">
                    <i class="fas fa-list me-2"></i>Partners Missing GL Code 
                    (<?php echo number_format(count($groupedPartners)); ?> Bills Payment partner) (<?php echo number_format($totalRecordsAffected); ?> total records)
                </h5>
            </div>
            <div class="card-body">
                <div class="table-container">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead class="table-dark">
                                <tr>
                                    <th width="5%">#</th>
                                    <th width="25%">Partner Name</th>
                                    <th width="12%">KP7 Partner ID</th>
                                    <th width="12%">KPX Partner ID</th>
                                    <th width="8%">Records Count</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $counter = 1;
                                foreach ($groupedPartners as $partner): 
                                    // Sort affected rows for better display
                                    sort($partner['affected_rows'], SORT_NUMERIC);
                                    $rowsDisplay = implode(', ', array_slice($partner['affected_rows'], 0, 5));
                                    if (count($partner['affected_rows']) > 5) {
                                        $rowsDisplay .= ', ... +' . (count($partner['affected_rows']) - 5) . ' more';
                                    }
                                ?>
                                <tr>
                                    <td><?php echo number_format($counter++); ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($partner['partner_name']); ?></strong>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary"><?php echo htmlspecialchars($partner['partner_id'] !== null ? $partner['partner_id'] : '-'); ?></span>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary"><?php echo htmlspecialchars($partner['partner_id_kpx'] !== null ? $partner['partner_id_kpx'] : '-'); ?></span>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary"><?php echo number_format($partner['record_count']); ?></span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="text-center mt-4">
            <button type="button" class="btn btn-primary btn-lg me-3" onclick="goBack()">
                <i class="fas fa-arrow-left me-2"></i>Back to Import
            </button>
            <button type="button" class="btn btn-info btn-lg" onclick="printErrors()">
                <i class="fas fa-print me-2"></i>Print Report
            </button>
        </div>
    </div>

    <script>
        function goBack() {
            Swal.fire({
                title: 'Return to Import Page?',
                text: 'This will clear the current error data.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Yes, go back',
                cancelButtonText: 'Stay here'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = '../../dashboard/billspayment/import/billspay-transaction.php';
                }
            });
        }

        function printErrors() {
            window.print();
        }
    </script>
</body>
</html>