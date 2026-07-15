<?php
require_once __DIR__ . '/../../config/config.php';
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

use BcMath\Number;



   if (!isset($_SESSION['missing_branch_ids']) || empty($_SESSION['missing_branch_ids'])) {
      header('location:../../dashboard/billspayment/import/billspay-transaction.php');
   }

   // Debug function to check session data
   function debugSessionData() {
       if (isset($_SESSION['missing_branch_ids'])) {
           error_log("Missing Branch IDs: " . print_r($_SESSION['missing_branch_ids'], true));
       }
       if (isset($_SESSION['selected_region'])) {
           error_log("Selected Region: " . $_SESSION['selected_region']);
       }
       if (isset($_SESSION['selected_branch'])) {
           error_log("Selected Branch: " . $_SESSION['selected_branch']);
       }
   }
   
   // Call debug function (remove in production)
   // debugSessionData();

   // Function to get branch details from database - FIXED
   function getBranchDetails($conn, $branchId) {
       // Add error checking and use correct table name with schema
       $sql = "SELECT partner_id, region, region_code FROM masterdata.branch_profile WHERE branch_id = ?";
       $stmt = $conn->prepare($sql);
       
       if (!$stmt) {
           error_log("SQL prepare failed: " . $conn->error);
           error_log("SQL query: " . $sql);
           return null;
       }
       
       $stmt->bind_param("s", $branchId);
       
       if (!$stmt->execute()) {
           error_log("SQL execute failed: " . $stmt->error);
           $stmt->close();
           return null;
       }
       
       $result = $stmt->get_result();
       
       if ($row = $result->fetch_assoc()) {
           $stmt->close();
           return $row;
       }
       
       $stmt->close();
       return null;
   }

   // Enhanced function to validate and enrich missing branch data - IMPROVED ERROR HANDLING
   function validateAndEnrichBranchData($conn, &$missingBranchIds) {
       if (empty($missingBranchIds) || !is_array($missingBranchIds)) {
           return;
       }
       
       foreach ($missingBranchIds as $index => &$error) {
           // Clean up data
           $error['branch_outlet'] = trim($error['branch_outlet'] ?? $error['outlet'] ?? '');
           $error['branch_id'] = trim($error['branch_id'] ?? '');
           $error['partner_id'] = trim($error['partner_id'] ?? '');
           $error['region_description'] = trim($error['region_description'] ?? $error['region'] ?? '');
           $error['region_code'] = trim($error['region_code'] ?? '');
           
           // If branch_id exists but other fields are missing, try to get them from database
           if (!empty($error['branch_id'])) {
               try {
                   $branchDetails = getBranchDetails($conn, $error['branch_id']);
                   if ($branchDetails) {
                       // Branch exists in database, so it's not actually missing
                       error_log("Branch ID {$error['branch_id']} found in database, removing from missing list");
                       unset($missingBranchIds[$index]);
                       continue;
                   }
               } catch (Exception $e) {
                   error_log("Error checking branch details for ID {$error['branch_id']}: " . $e->getMessage());
                   // Continue processing even if there's an error
               }
           }
           
           // If essential fields are missing, mark for removal
           if (empty($error['branch_outlet']) && empty($error['branch_id']) && empty($error['region_description'])) {
               error_log("Removing empty error entry at index $index");
               unset($missingBranchIds[$index]);
           }
       }
       
       // Re-index array after removing elements
       $missingBranchIds = array_values($missingBranchIds);
   }

   // Validate and enrich the missing branch data - IMPROVED ERROR HANDLING
   if (isset($_SESSION['missing_branch_ids']) && !empty($_SESSION['missing_branch_ids'])) {
       try {
           validateAndEnrichBranchData($conn, $_SESSION['missing_branch_ids']);
           
           // If no missing branches after validation, redirect back
           if (empty($_SESSION['missing_branch_ids'])) {
               $_SESSION['success_message'] = "All branch IDs were found in the database after validation.";
               header('location:../../dashboard/billspayment/import/billspay-transaction.php');
               exit();
           }
       } catch (Exception $e) {
           error_log("Error during branch validation: " . $e->getMessage());
           // Continue to display the page even if validation fails
       }
   }
?>
<!DOCTYPE html>
<html lang="en">
<head>
   <meta charset="UTF-8">
   <meta http-equiv="X-UA-Compatible" content="IE=edge">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title>Branch ID Errors</title>
   <link rel="stylesheet" href="../../assets/css/billspaymentImportFile.css?v=<?php echo time(); ?>">
   <link rel="icon" href="../../images/MLW logo.png" type="image/png">
   <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
   <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
   <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.min.css" rel="stylesheet">
   <script src="../../assets/js/sweetalert2.all.min.js"></script>
   <style>
       /* Print styles */
       @media print {
           body * {
               visibility: hidden;
           }
           #errorContainer, #errorContainer * {
               visibility: visible;
           }
           #errorContainer {
               position: absolute;
               left: 0;
               top: 0;
               width: 100%;
           }
           .print-header {
               display: block !important;
           }
           .no-print {
               display: none !important;
           }
       }
       .print-header {
           display: none;
           text-align: center;
           margin-bottom: 20px;
       }
       .table-responsive {
           max-height: 500px;
           overflow-y: auto;
       }
       .empty-field {
           color: #6c757d;
           font-style: italic;
       }
       .warning-row {
           background-color: #fff3cd !important;
       }
   </style>
</head>
<body>
    <div class="container mt-5">
        <div id="errorContainer" class="card shadow">
            <div class="card-header bg-danger text-white">
                <h3 class="mb-0"><i class="fas fa-exclamation-triangle me-2"></i>Branch ID Errors</h3>
            </div>
            <div class="print-header">
                <h2>Branch ID Error Report</h2>
                <p>File: <?php echo htmlspecialchars($_SESSION['original_file_name'] ?? 'Unknown file'); ?></p>
                <p>Source File Type: <?php echo htmlspecialchars($_SESSION['source_file_type'] ?? 'Unknown'); ?></p>
                <p>Selected Region: <?php echo htmlspecialchars($_SESSION['selected_region'] ?? 'Not specified'); ?></p>
                <p>Selected Branch: <?php echo htmlspecialchars($_SESSION['selected_branch'] ?? 'Not specified'); ?></p>
                <p>Date Picker: <?php echo htmlspecialchars($_SESSION['transactionDate'] ?? date('Y-m-d')); ?></p>
                <p>Date: <?php echo date('Y-m-d H:i:s'); ?></p>
            </div>
            <div class="card-body">
                <div class="alert alert-warning mb-4">
                    <h4><i class="fas fa-exclamation-circle me-2"></i>Branch ID Not Found</h4>
                    <p>The following branch IDs were not found in the branch profile database:</p>
                    <?php if (isset($_SESSION['selected_region']) && $_SESSION['selected_region'] !== 'ALL'): ?>
                        <p><strong>Selected Region:</strong> <?php echo htmlspecialchars($_SESSION['selected_region']); ?></p>
                    <?php endif; ?>
                    <?php if (isset($_SESSION['selected_branch']) && $_SESSION['selected_branch'] !== 'ALL'): ?>
                        <p><strong>Selected Branch:</strong> <?php echo htmlspecialchars($_SESSION['selected_branch']); ?></p>
                    <?php endif; ?>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-bordered table-striped">
                        <thead class="table-dark sticky-top">
                            <tr>
                                <th>No.</th>
                                <th>Branch ID</th>
                                <th>ML Branch Outlet</th>
                                <th>Reference Number</th>
                                <th>Amount Paid</th>
                                <th>Charge to Partner</th>
                                <th>Charge to Customer</th>
                                <th>Region</th>
                                <th>Row in Excel</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            if(isset($_SESSION['missing_branch_ids']) && !empty($_SESSION['missing_branch_ids'])):
                                $displayIndex = 0;
                                $totalErrors = count($_SESSION['missing_branch_ids']);
                                
                                foreach($_SESSION['missing_branch_ids'] as $index => $error): 
                                    // Enhanced validation - show all errors but highlight incomplete ones
                                    $branchOutlet = $error['branch_outlet'] ?? $error['outlet'] ?? '';
                                    $branchId = $error['branch_id'] ?? '';
                                    $regionDesc = $error['region_description'] ?? $error['region'] ?? '';
                                    $referenceNumber = $error['reference_number'] ?? '';
                                    
                                    $hasEmptyFields = empty(trim($branchOutlet)) || 
                                                    empty(trim($branchId)) || 
                                                    empty(trim($regionDesc)) || 
                                                    empty(trim($referenceNumber));
                                    
                                    $displayIndex++;
                                    
                                    $rowClass = $hasEmptyFields ? 'warning-row' : '';
                            ?>
                            <tr class="<?php echo $rowClass; ?>">
                                <td><?php echo $displayIndex; ?></td>
                                <td>
                                    <?php 
                                    echo !empty(trim($branchId)) ? htmlspecialchars($branchId) : '<span class="empty-field">N/A</span>';
                                    ?>
                                </td>
                                <td>
                                    <?php 
                                    echo !empty(trim($branchOutlet)) ? htmlspecialchars($branchOutlet) : '<span class="empty-field">N/A</span>';
                                    ?>
                                </td>
                                <td>
                                    <?php 
                                    echo !empty(trim($referenceNumber)) ? htmlspecialchars($referenceNumber) : '<span class="empty-field">N/A</span>';
                                    ?>
                                </td>
                                <td>
                                    <?php 
                                    $amountPaid = $error['amount_paid'] ?? 0;
                                    echo number_format(floatval($amountPaid), 2);
                                    ?>
                                </td>
                                <td>
                                    <?php 
                                    $chargeToPartner = $error['amount_charge_partner'] ?? 0;
                                    echo number_format(floatval($chargeToPartner), 2);
                                    ?>
                                </td>
                                <td>
                                    <?php 
                                    $chargeToCustomer = $error['amount_charge_customer'] ?? 0;
                                    echo number_format(floatval($chargeToCustomer), 2);
                                    ?>
                                </td>
                                <td>
                                    <?php 
                                    echo !empty(trim($regionDesc)) ? htmlspecialchars($regionDesc) : '<span class="empty-field">N/A</span>';
                                    ?>
                                </td>
                                <td><?php echo htmlspecialchars($error['row'] ?? 'Unknown'); ?></td>
                            </tr>
                            <?php 
                                endforeach;
                            else:
                            ?>
                            <tr>
                                <td colspan="9" class="text-center">
                                    <em>No missing branch IDs found.</em>
                                </td>
                            </tr>
                            <?php 
                            endif;
                            ?>
                        </tbody>
                    </table>
                </div>
                
                <?php if (isset($totalErrors) && $totalErrors > 0): ?>
                <div class="alert alert-info mt-3">
                    <strong>Total Errors Found:</strong> <?php echo $totalErrors; ?> branch ID(s)
                    <br><small class="text-muted">Rows highlighted in yellow have incomplete data.</small>
                </div>
                <?php endif; ?>
                
                <div class="mt-4 d-flex justify-content-center no-print">
                    <button type="button" class="btn btn-secondary me-2" onclick="printReport()">
                        <i class="fas fa-print me-2"></i>Print
                    </button>
                    <button type="button" class="btn btn-primary me-2" onclick="exportToPDF()">
                        <i class="fas fa-file-pdf me-2"></i>Export to PDF
                    </button>
                    <a href="../../dashboard/billspayment/import/billspay-transaction.php" class="btn btn-danger">
                        <i class="fas fa-times-circle me-2"></i>Close
                    </a>
                    <button type="button" class="btn btn-warning me-2" onclick="debugInfo()">
                        <i class="fas fa-info me-2"></i>Details
                    </button>
                </div>
                
                <!-- Hidden inputs for JavaScript -->
                <input type="hidden" id="original_filename" value="<?php echo htmlspecialchars(addslashes($_SESSION['original_file_name'] ?? 'branch_id_errors')); ?>">
                <input type="hidden" id="file_type" value="<?php echo htmlspecialchars(addslashes($_SESSION['source_file_type'] ?? 'Unknown')); ?>">
                <input type="hidden" id="date_picker" value="<?php echo htmlspecialchars(addslashes($_SESSION['transactionDate'] ?? date('Y-m-d'))); ?>">
                <input type="hidden" id="current_date" value="<?php echo htmlspecialchars(date('Y-m-d H:i:s')); ?>">
                <input type="hidden" id="selected_region" value="<?php echo htmlspecialchars(addslashes($_SESSION['selected_region'] ?? 'ALL')); ?>">
                <input type="hidden" id="selected_branch" value="<?php echo htmlspecialchars(addslashes($_SESSION['selected_branch'] ?? 'ALL')); ?>">
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function debugInfo() {
            const sessionData = {
                selectedRegion: document.getElementById('selected_region').value,
                selectedBranch: document.getElementById('selected_branch').value,
                fileName: document.getElementById('original_filename').value,
                fileType: document.getElementById('file_type').value,
                transactionDate: document.getElementById('date_picker').value
            };
            
            Swal.fire({
                title: 'Details',
                html: `
                    <div style="text-align: left;">
                        <p><strong>Transaction Date:</strong> ${sessionData.transactionDate}</p>
                        <p><strong>File Name:</strong> ${sessionData.fileName}</p>
                        <p><strong>Source File Type:</strong> ${sessionData.fileType}</p>
                        <p><strong>Selected Region:</strong> ${sessionData.selectedRegion}</p>
                        <p><strong>Selected Branch:</strong> ${sessionData.selectedBranch}</p>
                    </div>
                `,
                icon: 'info',
                width: 600
            });
        }
        
        function printReport() {
            window.print();
        }
        
        function exportToPDF() {
            // You can implement PDF export functionality here
            alert('PDF export functionality would be implemented here');
        }
    </script>
</body>
</html>