<?php

    session_start();
    include '../config/config.php';
    require '../vendor/autoload.php';

    if (!isset($_SESSION['admin_name'])) {
        header('location:../login_form.php');
    }

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import File</title>
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
            /* Make sure the table-responsive container shows all content */
            .table-responsive {
                max-height: none !important;
                height: auto !important;
                overflow: visible !important;
            }
            .table {
                width: 100%;
                border-collapse: collapse;
                page-break-inside: auto;
            }
            .table th, .table td {
                border: 1px solid #000;
            }
            .table tr {
                page-break-inside: avoid;
                page-break-after: auto;
            }
            .sticky-top {
                position: static;
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
        
    </style>
</head>

<body>
    <div>
        <div class="top-content">
            <div class="usernav">
                <h4 style="margin-right: 0.5rem; font-size: 1rem;"><?php echo $_SESSION['admin_name'] ?></h4>
                <h5 style="font-size: 1rem;"><?php echo "- ".$_SESSION['admin_email']."" ?></h5>
            </div>
            <?php include '../templates/admin/sidebar.php'; ?>
        </div>
    </div>
    <div id="loading-overlay">
        <div class="loading-spinner"></div>
    </div>
    <div class="container-fluid border border-danger rounded mt-3">
        <div class="container-fluid">
            <form id="uploadForm" action="../models/saved/saved_billspaymentImportFile.php" method="post" enctype="multipart/form-data">
                <div class="row mt-4 w-100 align-items-center">
                                    <!-- Partners Name Dropdown -->
                    <?php
                        // removed server-side partner query — partners will be loaded dynamically via AJAX
                    ?>
                    <div class="col-md-5 mb-3">
                        <div class="d-flex align-items-center">
                            <label class="form-label me-2 mb-0">Partners Name:</label>
                            <select id="companyDropdown" class="form-select select2" aria-label="Select Company" name="company" required 
                                data-placeholder="Search or select a company...">
                                    <option value="">Select Company</option>
                                    <option value="All">All</option>
                                    <!-- options will be populated by JS when Source File Type is selected -->
                            </select>
                        </div>
                    </div>
                        <!-- Source File Type Dropdown -->
                    <div class="col-md-3 mb-3">
                        <div class="d-flex align-items-center">
                            <label for="fileType" class="form-label me-2 mb-0">Source File Type:</label>
                            <select id="fileType" class="form-select" aria-label="Select File Type" name="fileType" required>
                                <option value="">Select Source File Type</option>
                                <option value="KPX">KPX </option>
                                <option value="KP7">KP7 </option>
                            </select>
                        </div>
                    </div>

                        <!-- Date Picker -->
                    <!-- <div class="col-md-3 mb-3">
                        <div class="d-flex align-items-center">
                            <label for="datePicker" class="form-label me-2 mb-0">Select Date:</label>
                            <input type="date" id="datePicker" name="datePicker" class="form-control" required>
                        </div>
                    </div> -->

                        <!-- File Upload Form -->
                    <div class="col-md-6 mb-3 d-flex">
                            <input type="file" name="import_file" accept=".xls,.xlsx" class="form-control me-2" required />
                            <input type="submit" class="btn btn-danger" name="upload" value="Proceed">
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Import Logs Section -->
    <div class="container-fluid border border-info rounded mt-4">
        <div class="container-fluid">
            <!-- Logs Header -->
            <div class="row mt-3 mb-3">
                <div class="col-md-8">
                    <h4 class="text-primary mb-2">
                        <i class="fas fa-file-import me-2"></i>Import Excel Logs
                    </h4>
                    <p class="text-muted mb-0">Monitor and manage your Excel file import history</p>
                </div>
                <div class="col-md-4 text-end">
                    <button class="btn btn-outline-primary btn-sm">
                        <i class="fas fa-download me-1"></i>Export Logs
                    </button>
                    <button class="btn btn-outline-secondary btn-sm ms-2">
                        <i class="fas fa-sync-alt me-1"></i>Refresh
                    </button>
                </div>
            </div>

            <!-- Statistics Cards Row -->
            <div class="row mb-4">
                <div class="col-lg-3 col-md-6 mb-2">
                    <div class="card border-success">
                        <div class="card-body p-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h5 class="text-success mb-1">142</h5>
                                    <small class="text-muted">Successful</small>
                                </div>
                                <i class="fas fa-check-circle text-success fa-2x"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 mb-2">
                    <div class="card border-danger">
                        <div class="card-body p-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h5 class="text-danger mb-1">8</h5>
                                    <small class="text-muted">Failed</small>
                                </div>
                                <i class="fas fa-times-circle text-danger fa-2x"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 mb-2">
                    <div class="card border-warning">
                        <div class="card-body p-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h5 class="text-warning mb-1">3</h5>
                                    <small class="text-muted">Processing</small>
                                </div>
                                <i class="fas fa-clock text-warning fa-2x"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 mb-2">
                    <div class="card border-info">
                        <div class="card-body p-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h5 class="text-info mb-1">24,567</h5>
                                    <small class="text-muted">Records</small>
                                </div>
                                <i class="fas fa-database text-info fa-2x"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="row mb-3">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="mb-0"><i class="fas fa-filter me-2"></i>Filter Logs</h6>
                        </div>
                        <div class="card-body">
                            <form class="row g-2">
                                <div class="col-md-2">
                                    <label for="logPartnerFilter" class="form-label">Partner</label>
                                    <select class="form-select form-select-sm" id="logPartnerFilter">
                                        <option value="">All Partners</option>
                                        <option value="partner1">ABC Corporation</option>
                                        <option value="partner2">XYZ Industries</option>
                                        <option value="partner3">Global Solutions</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label for="logFileTypeFilter" class="form-label">File Type</label>
                                    <select class="form-select form-select-sm" id="logFileTypeFilter">
                                        <option value="">All Types</option>
                                        <option value="KPX">KPX</option>
                                        <option value="KP7">KP7</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label for="logStatusFilter" class="form-label">Status</label>
                                    <select class="form-select form-select-sm" id="logStatusFilter">
                                        <option value="">All Status</option>
                                        <option value="success">Success</option>
                                        <option value="error">Error</option>
                                        <option value="processing">Processing</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label for="logDateFrom" class="form-label">Date From</label>
                                    <input type="date" class="form-control form-control-sm" id="logDateFrom">
                                </div>
                                <div class="col-md-2">
                                    <label for="logDateTo" class="form-label">Date To</label>
                                    <input type="date" class="form-control form-control-sm" id="logDateTo">
                                </div>
                                <div class="col-md-2 d-flex align-items-end">
                                    <button type="button" class="btn btn-primary btn-sm w-100">
                                        <i class="fas fa-search me-1"></i>Filter
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Logs Table -->
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h6 class="mb-0">Import History</h6>
                            <small class="text-muted">Showing recent 10 imports</small>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive" style="max-height: 400px;">
                                <table class="table table-sm table-hover mb-0">
                                    <thead class="table-dark sticky-top">
                                        <tr>
                                            <th style="width: 50px;">#</th>
                                            <th>File Name</th>
                                            <th>Partner</th>
                                            <th>Type</th>
                                            <th>Upload Date</th>
                                            <th>Records</th>
                                            <th>Status</th>
                                            <th>User</th>
                                            <th style="width: 100px;">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td>001</td>
                                            <td>
                                                <i class="fas fa-file-excel text-success me-1"></i>
                                                <small>bills_payment_2024_01.xlsx</small>
                                            </td>
                                            <td><small>ABC Corporation</small></td>
                                            <td><span class="badge bg-primary">KPX</span></td>
                                            <td><small>2024-01-15 14:30</small></td>
                                            <td><small>1,250</small></td>
                                            <td>
                                                <span class="badge bg-success">Success</span>
                                            </td>
                                            <td><small>Admin User</small></td>
                                            <td>
                                                <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#logDetailsModal" title="View Details">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button class="btn btn-outline-success btn-sm" title="Download">
                                                    <i class="fas fa-download"></i>
                                                </button>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td>002</td>
                                            <td>
                                                <i class="fas fa-file-excel text-danger me-1"></i>
                                                <small>payment_data_error.xlsx</small>
                                            </td>
                                            <td><small>XYZ Industries</small></td>
                                            <td><span class="badge bg-secondary">KP7</span></td>
                                            <td><small>2024-01-14 09:15</small></td>
                                            <td><small>0</small></td>
                                            <td>
                                                <span class="badge bg-danger">Error</span>
                                            </td>
                                            <td><small>John Doe</small></td>
                                            <td>
                                                <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#logDetailsModal" title="View Details">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button class="btn btn-outline-warning btn-sm" title="Retry">
                                                    <i class="fas fa-redo"></i>
                                                </button>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td>003</td>
                                            <td>
                                                <i class="fas fa-file-excel text-warning me-1"></i>
                                                <small>monthly_bills_jan.xlsx</small>
                                            </td>
                                            <td><small>Global Solutions</small></td>
                                            <td><span class="badge bg-primary">KPX</span></td>
                                            <td><small>2024-01-14 16:45</small></td>
                                            <td><small>2,100</small></td>
                                            <td>
                                                <span class="badge bg-warning text-dark">Processing</span>
                                            </td>
                                            <td><small>Jane Smith</small></td>
                                            <td>
                                                <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#logDetailsModal" title="View Details">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button class="btn btn-outline-secondary btn-sm" title="Cancel">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td>004</td>
                                            <td>
                                                <i class="fas fa-file-excel text-success me-1"></i>
                                                <small>quarterly_report_q4.xlsx</small>
                                            </td>
                                            <td><small>XYZ Industries</small></td>
                                            <td><span class="badge bg-primary">KPX</span></td>
                                            <td><small>2024-01-12 08:45</small></td>
                                            <td><small>3,420</small></td>
                                            <td>
                                                <span class="badge bg-success">Success</span>
                                            </td>
                                            <td><small>John Doe</small></td>
                                            <td>
                                                <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#logDetailsModal" title="View Details">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button class="btn btn-outline-success btn-sm" title="Download">
                                                    <i class="fas fa-download"></i>
                                                </button>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td>005</td>
                                            <td>
                                                <i class="fas fa-file-excel text-success me-1"></i>
                                                <small>bills_december_2023.xlsx</small>
                                            </td>
                                            <td><small>ABC Corporation</small></td>
                                            <td><span class="badge bg-secondary">KP7</span></td>
                                            <td><small>2024-01-11 13:22</small></td>
                                            <td><small>1,890</small></td>
                                            <td>
                                                <span class="badge bg-success">Success</span>
                                            </td>
                                            <td><small>Admin User</small></td>
                                            <td>
                                                <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#logDetailsModal" title="View Details">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button class="btn btn-outline-success btn-sm" title="Download">
                                                    <i class="fas fa-download"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="card-footer">
                            <div class="row align-items-center">
                                <div class="col-md-6">
                                    <small class="text-muted">Showing 1 to 5 of 153 entries</small>
                                </div>
                                <div class="col-md-6">
                                    <nav aria-label="Logs pagination">
                                        <ul class="pagination pagination-sm justify-content-end mb-0">
                                            <li class="page-item disabled">
                                                <a class="page-link" href="#" tabindex="-1">Previous</a>
                                            </li>
                                            <li class="page-item active">
                                                <a class="page-link" href="#">1</a>
                                            </li>
                                            <li class="page-item">
                                                <a class="page-link" href="#">2</a>
                                            </li>
                                            <li class="page-item">
                                                <a class="page-link" href="#">3</a>
                                            </li>
                                            <li class="page-item">
                                                <a class="page-link" href="#">Next</a>
                                            </li>
                                        </ul>
                                    </nav>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Log Details Modal -->
    <div class="modal fade" id="logDetailsModal" tabindex="-1" aria-labelledby="logDetailsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="logDetailsModalLabel">
                        <i class="fas fa-info-circle me-2"></i>Import Log Details
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- File Information -->
                    <div class="card mb-3">
                        <div class="card-header">
                            <h6 class="mb-0"><i class="fas fa-file-excel me-2"></i>File Information</h6>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <p class="mb-2"><strong>File Name:</strong> bills_payment_2024_01.xlsx</p>
                                    <p class="mb-2"><strong>File Size:</strong> 2.4 MB</p>
                                    <p class="mb-2"><strong>File Type:</strong> KPX</p>
                                </div>
                                <div class="col-md-6">
                                    <p class="mb-2"><strong>Partner:</strong> ABC Corporation</p>
                                    <p class="mb-2"><strong>Upload Date:</strong> 2024-01-15 14:30:25</p>
                                    <p class="mb-2"><strong>Uploaded By:</strong> Admin User</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Processing Summary -->
                    <div class="card mb-3">
                        <div class="card-header">
                            <h6 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Processing Summary</h6>
                        </div>
                        <div class="card-body">
                            <div class="row text-center">
                                <div class="col-3">
                                    <div class="border rounded p-2">
                                        <h5 class="text-success mb-1">1,248</h5>
                                        <small class="text-muted">Processed</small>
                                    </div>
                                </div>
                                <div class="col-3">
                                    <div class="border rounded p-2">
                                        <h5 class="text-warning mb-1">2</h5>
                                        <small class="text-muted">Warnings</small>
                                    </div>
                                </div>
                                <div class="col-3">
                                    <div class="border rounded p-2">
                                        <h5 class="text-danger mb-1">0</h5>
                                        <small class="text-muted">Errors</small>
                                    </div>
                                </div>
                                <div class="col-3">
                                    <div class="border rounded p-2">
                                        <h5 class="text-info mb-1">1,250</h5>
                                        <small class="text-muted">Total</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Processing Log -->
                    <div class="card">
                        <div class="card-header">
                            <h6 class="mb-0"><i class="fas fa-list-alt me-2"></i>Processing Log</h6>
                        </div>
                        <div class="card-body p-0">
                            <div class="processing-log" style="max-height: 250px; overflow-y: auto; padding: 1rem;">
                                <div class="alert alert-success alert-sm py-2">
                                    <small><i class="fas fa-check me-2"></i><strong>[14:30:25]</strong> File upload completed successfully</small>
                                </div>
                                <div class="alert alert-info alert-sm py-2">
                                    <small><i class="fas fa-info me-2"></i><strong>[14:30:26]</strong> Starting file validation...</small>
                                </div>
                                <div class="alert alert-success alert-sm py-2">
                                    <small><i class="fas fa-check me-2"></i><strong>[14:30:27]</strong> File format validation passed</small>
                                </div>
                                <div class="alert alert-info alert-sm py-2">
                                    <small><i class="fas fa-info me-2"></i><strong>[14:30:28]</strong> Processing 1,250 records...</small>
                                </div>
                                <div class="alert alert-warning alert-sm py-2">
                                    <small><i class="fas fa-exclamation-triangle me-2"></i><strong>[14:30:35]</strong> Warning: Row 45 - Invalid date format, using default</small>
                                </div>
                                <div class="alert alert-warning alert-sm py-2">
                                    <small><i class="fas fa-exclamation-triangle me-2"></i><strong>[14:30:42]</strong> Warning: Row 128 - Missing optional field 'Notes'</small>
                                </div>
                                <div class="alert alert-success alert-sm py-2">
                                    <small><i class="fas fa-check me-2"></i><strong>[14:31:15]</strong> Successfully imported 1,248 records</small>
                                </div>
                                <div class="alert alert-success alert-sm py-2">
                                    <small><i class="fas fa-check me-2"></i><strong>[14:31:16]</strong> Import process completed</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-success btn-sm">
                        <i class="fas fa-download me-1"></i>Download Report
                    </button>
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>


    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
    <!-- SweetAlert2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.all.min.js"></script>
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            if (typeof $ !== "undefined" && $.fn.select2) {
                // console.log("Initializing Select2 for partner dropdown");
                $("#companyDropdown").select2({
                    placeholder: "Search or select a company...",
                    allowClear: true,
                    width: "100%",
                    minimumResultsForSearch: 0,
                    dropdownParent: $("#companyDropdown").parent()
                });
            } else {
                console.error("jQuery or Select2 library not loaded");
            }
        });
    </script>
    <script>
        // script.js or within <script> tags in <head> or before </body>
        document.getElementById('uploadForm').addEventListener('submit', function() {
            // Show loading overlay when form is submitted
            document.getElementById('loading-overlay').style.display = 'block';
        });
            // Load partners for a given fileType via AJAX and populate the partner dropdown
        function loadPartnersForFileType(fileType) {
            var $select = $('#companyDropdown');

            // clear existing options and add defaults
            $select.empty();
            $select.append($('<option>', { value: '', text: 'Select Company' }));
            $select.append($('<option>', { value: 'All', text: 'All' }));

            if (!fileType) {
                $select.val('');
                if ($.fn.select2) $select.trigger('change.select2');
                return;
            }

            // console.log('Loading partners for fileType=', fileType);

            $.ajax({
                url: '../fetch/get_partners.php',
                method: 'GET',
                data: { fileType: fileType },
                dataType: 'json',
                success: function(response) {
                    // new response format: { success: true, data: [...] } or { success: false, error: '...' }
                    if (!response) {
                        console.error('Empty response from server');
                        return;
                    }
                    if (response.success === false) {
                        console.error('get_partners error:', response.error || 'unknown');
                        return;
                    }

                    var list = Array.isArray(response.data) ? response.data : response;
                    if (list.length === 0) {
                        console.log('No partners returned for', fileType);
                    }

                    list.forEach(function(p) {
                        // ensure we have partner_id and partner_name
                        var id = p.partner_id || p.partner_id_kpx || '';
                        var name = p.partner_name || p.name || '';
                        if (id && name) {
                            $select.append($('<option>', {
                                value: id,
                                text: name
                            }));
                        }
                    });

                    // update Select2 / native select UI
                    $select.val('');
                    if ($.fn.select2) {
                        $select.trigger('change.select2');
                    } else {
                        $select.trigger('change');
                    }
                },
                error: function(xhr, status, err) {
                    console.error('Failed to load partners', status, err);
                }
            });
        }

        $(document).ready(function() {
            // initialize select2 (kept existing)
            if (typeof $ !== 'undefined' && $.fn.select2) {
                $('#companyDropdown').select2({
                    placeholder: "Search or select a company...",
                    allowClear: true,
                    width: '100%',
                    dropdownParent: $('#companyDropdown').parent(),
                    minimumResultsForSearch: 0
                });
            }

            // when Source File Type changes, reload partners
            $('#fileType').on('change', function() {
                var fileType = $(this).val();
                loadPartnersForFileType(fileType);
            });

            // if fileType already selected on page load, load partners
            var initialFileType = $('#fileType').val();
            if (initialFileType) {
                loadPartnersForFileType(initialFileType);
            }

            // Add/replace company dropdown change handler
            $('#companyDropdown').on('change', function() {
                var selectedValue = $(this).val();
                var fileType = $('#fileType').val();

                // If "All" selected while Source File Type is KPX, show a note
                if (selectedValue === 'All' && fileType === 'KPX') {
                    Swal.fire({
                        title: 'Note',
                        text: 'No All Partners Available for KPX',
                        icon: 'info',
                        confirmButtonText: 'OK',
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                    }).then(function() {
                        // optional: reset selection so user picks a specific partner
                        window.location.href = '../admin/billspaymentImportFile.php';
                    });
                }

                // keep existing behavior for date picker
                var datePicker = $('#datePicker');
                datePicker.prop('disabled', false);
                datePicker.prop('required', true);
            });

            // Form validation
            $('#uploadForm').on('submit', function(e) {
                var selectedCompany = $('#companyDropdown').val();
                var datePicker = $('#datePicker');
                var fileType = $('#fileType').val();
                
                // Validate source file type is selected
                if (!fileType) {
                    e.preventDefault();
                    Swal.fire({
                        title: 'Missing File Type',
                        text: 'Please select a source file type (KPX or KP7).',
                        icon: 'warning',
                        confirmButtonText: 'OK'
                    });
                    return false;
                }
                
                // Show loading overlay
                document.getElementById('loading-overlay'). style.display = 'block';
            });
        });
        // Add the JavaScript function for the confirmation
        function confirmCancel() {
            Swal.fire({
                title: 'Are you sure?',
                text: "Cancelling the process will discard all uploaded data",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Yes, cancel it!',
                cancelButtonText: 'No, continue'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = 'billspaymentImportFile.php';
                }
            });
        }
    </script>
</body>
</html>