<?php

    session_start();
    require_once __DIR__ . '/../config/config.php';
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
    <title>Import File Cancellation</title>
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
            <form id="uploadForm" action="../models/saved/saved_billspaymentImportFileCancellation.php" method="post" enctype="multipart/form-data">
                <div class="row mt-4 w-100 align-items-center">
                                        <!-- Partners Name Dropdown -->
                        <?php
                            // Fetch partners from the database
                            $partners = [];
                            $sql = "SELECT partner_id, partner_name FROM masterdata.partner_masterfile ORDER BY partner_name ASC";
                            $result = $conn->query($sql);
                            if ($result && $result->num_rows > 0) {
                                while ($row = $result->fetch_assoc()) {
                                    $partners[] = ['partner_id' => $row['partner_id'], 'partner_name' => $row['partner_name']];
                                }
                            }
                        ?>
                        <div class="col-md-5 mb-3">
                            <div class="d-flex align-items-center">
                                <label class="form-label me-2 mb-0">Partners Name:</label>
                                    <select id="companyDropdown" class="form-select select2" aria-label="Select Company" name="company" required 
                                        data-placeholder="Search or select a company...">
                                                    <option value="">Select Company</option> 
                                                    <option value="All">All</option>
                                        <?php foreach ($partners as $partner): ?>
                                            <option value="<?php echo htmlspecialchars($partner['partner_id']); ?>"><?php echo (isset($_SESSION['selected_partner']) && $_SESSION['selected_partner'] === $partner['partner_name']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($partner['partner_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
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

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
    <!-- SweetAlert2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.all.min.js"></script>
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            if (typeof $ !== "undefined" && $.fn.select2) {
                console.log("Initializing Select2 for partner dropdown");
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
            // Loop through each element and set its display style to "block"
        for (var i = 0; i < elements.length; i++) {
            elements[i].style.display = "block";
        }

        $(document).ready(function() {
            $('#companyDropdown').select2({
                placeholder: "Search or select a company...",
                allowClear: true,
                width: '100%',
                dropdownParent: $('#companyDropdown').parent(),
                minimumResultsForSearch: 0, // Always show search box
                searchInputPlaceholder: 'Type to search partners...',
                language: {
                    noResults: function() {
                        return "No partner found with that name";
                    }
                }
            });

            // Add change event handler for company dropdown
            $('#companyDropdown').on('change', function() {
                var selectedValue = $(this).val();
                var datePicker = $('#datePicker');
                
                // Always keep date picker enabled and required regardless of selection
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
                
                // Validate date is selected regardless of partner selection
                if (!datePicker.val()) {
                    e.preventDefault();
                    Swal.fire({
                        title: 'Missing Date',
                        text: 'Please select a date for the upload.',
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