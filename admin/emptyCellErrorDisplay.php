<?php
   session_start();
   include '../config/config.php';

   if (!isset($_SESSION['admin_name'])) {
      header('location:../login_form.php');
   }

   if (!isset($_SESSION['empty_cell_errors']) || empty($_SESSION['empty_cell_errors'])) {
      header('location:billspaymentImportFile.php');
   }
?>
<!DOCTYPE html>
<html lang="en">
<head>
   <meta charset="UTF-8">
   <meta http-equiv="X-UA-Compatible" content="IE=edge">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title>Empty Cell Errors</title>
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
                <h3 class="mb-0"><i class="fas fa-exclamation-triangle me-2"></i>Empty Cell Errors</h3>
            </div>
            <div class="print-header">
                <h2>Empty Cell Error Report</h2>
                <p>File: <?php echo htmlspecialchars($_SESSION['original_file_name'] ?? 'Unknown file'); ?></p>
                <p>Source File Type: <?php echo htmlspecialchars($_SESSION['source_file_type'] ?? 'Unknown'); ?></p>
                <p>Date Picker: <?php echo htmlspecialchars($_SESSION['transactionDate'] ?? date('Y-m-d')); ?></p>
                <p>Date: <?php echo date('Y-m-d H:i:s'); ?></p>
            </div>
            <div class="card-body">
                <div class="alert alert-warning mb-4">
                    <h4><i class="fas fa-exclamation-circle me-2"></i>Empty Branch ID Cells</h4>
                    <p>The following rows have empty values in the branch ID cell (Column N):</p>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-bordered table-striped">
                        <thead class="table-dark sticky-top">
                            <tr>
                                <th>No.</th>
                                <th>ML Branch Outlet</th>
                                <th>Region</th>
                                <th>Row in Excel</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            if(isset($_SESSION['empty_cell_errors']) && !empty($_SESSION['empty_cell_errors'])):
                                foreach($_SESSION['empty_cell_errors'] as $index => $error): 
                            ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td><?php echo htmlspecialchars($error['outlet']); ?></td>
                                <td><?php echo htmlspecialchars($error['region']); ?></td>
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
                <input type="hidden" id="original_filename" value="<?php echo htmlspecialchars(addslashes($_SESSION['original_file_name'] ?? 'empty_cell_errors')); ?>">
                <input type="hidden" id="file_type" value="<?php echo htmlspecialchars(addslashes($_SESSION['source_file_type'] ?? 'Unknown')); ?>">
                <input type="hidden" id="date_picker" value="<?php echo htmlspecialchars(addslashes($_SESSION['transactionDate'] ?? date('Y-m-d'))); ?>">
                <input type="hidden" id="current_date" value="<?php echo htmlspecialchars(date('Y-m-d H:i:s')); ?>">
            </div>
            <div class="card-footer text-center">
                <p class="mb-0">Please fill in the empty branch ID cells in column N and try again.</p>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Reference to external script file -->
    <script src="../assets/js/emptyCellErrorFunctions.js"></script>
</body>
</html>
