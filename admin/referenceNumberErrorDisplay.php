<?php
   session_start();
   include '../config/config.php';

   if (!isset($_SESSION['admin_name'])) {
      header('location:../login_form.php');
   }

   if (!isset($_SESSION['duplicate_references']) || empty($_SESSION['duplicate_references'])) {
      header('location:billspaymentImportFile.php');
   }
?>
<!DOCTYPE html>
<html lang="en">
<head>
   <meta charset="UTF-8">
   <meta http-equiv="X-UA-Compatible" content="IE=edge">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title>Duplicate Reference Numbers</title>
   <link rel="stylesheet" href="../assets/css/billspaymentImportFile.css?v=<?php echo time(); ?>">
   <link rel="icon" href="../images/MLW logo.png" type="image/png">
   <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
   <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
   <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.min.css" rel="stylesheet">
   <script src="../assets/js/sweetalert2.all.min.js"></script>
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
   </style>
</head>
<body>
    <div class="container mt-5">
        <div id="errorContainer" class="card shadow">
            <div class="card-header bg-danger text-white">
                <h3 class="mb-0"><i class="fas fa-exclamation-triangle me-2"></i>Duplicate Reference Numbers</h3>
            </div>
            <div class="print-header">
                <h2>Duplicate Reference Numbers Report</h2>
                <p>File: <?php echo htmlspecialchars($_SESSION['original_file_name'] ?? 'Unknown file'); ?></p>
                <p>Source File Type: <?php echo htmlspecialchars($_SESSION['source_file_type'] ?? 'Unknown'); ?></p>
                <p>Date Picker: <?php echo htmlspecialchars(date('F j, Y', strtotime($_SESSION['transactionDate'] ?? date('Y-m-d')))); ?></p>
                <p>Date: <?php echo date('F j, Y'); ?></p>
            </div>
            <div class="card-body">
                <div class="alert alert-warning mb-4">
                    <h4><i class="fas fa-exclamation-circle me-2"></i>Duplicate Reference Numbers Found</h4>
                    <p>The following reference numbers appear more than once in the file:</p>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-bordered table-striped">
                        <thead class="table-dark sticky-top">
                            <tr>
                                <th>No.</th>
                                <th>Reference Number</th>
                                <th>Payor</th>
                                <th>Amount</th>
                                <th>Row in Excel</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            if(isset($_SESSION['duplicate_references']) && !empty($_SESSION['duplicate_references'])):
                                foreach($_SESSION['duplicate_references'] as $index => $error): 
                            ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td><?php echo htmlspecialchars($error['reference_no']); ?></td>
                                <td><?php echo htmlspecialchars($error['payor']); ?></td>
                                <td><?php echo number_format($error['amount'], 2); ?></td>
                                <td><?php echo htmlspecialchars($error['row']); ?></td>
                            </tr>
                            <?php 
                                endforeach; 
                            endif;
                            ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="mt-4 d-flex justify-content-center no-print">
                    <button type="button" class="btn btn-secondary me-2" onclick="printReport()">
                        <i class="fas fa-print me-2"></i>Print
                    </button>
                    <button type="button" class="btn btn-primary me-2" onclick="exportToPDF()">
                        <i class="fas fa-file-pdf me-2"></i>Export to PDF
                    </button>
                    <a href="billspaymentImportFile.php" class="btn btn-danger">
                        <i class="fas fa-times-circle me-2"></i>Close
                    </a>
                </div>
                
                <!-- Hidden inputs for JavaScript -->
                <input type="hidden" id="original_filename" value="<?php echo htmlspecialchars(addslashes($_SESSION['original_file_name'] ?? 'reference_number_errors')); ?>">
                <input type="hidden" id="file_type" value="<?php echo htmlspecialchars(addslashes($_SESSION['source_file_type'] ?? 'Unknown')); ?>">
                <input type="hidden" id="date_picker" value="<?php echo htmlspecialchars(addslashes(date('F j, Y', strtotime($_SESSION['transactionDate'] ?? date('Y-m-d'))))); ?>">
                <input type="hidden" id="current_date" value="<?php echo htmlspecialchars(date('F j, Y')); ?>">
            </div>
            <div class="card-footer text-center">
                <p class="mb-0">Please remove duplicate reference numbers and try again.</p>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Replace the inline functions with reference to external file -->
    <script src="../assets/js/referenceNumberErrorFunctions.js"></script>
</body>
</html>
