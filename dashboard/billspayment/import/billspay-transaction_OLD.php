<?php
// Connect to the database
include '../../../config/config.php';
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

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Transaction | <?php if($_SESSION['user_type'] === 'admin' || $_SESSION['user_type'] === 'user') echo ucfirst($_SESSION['user_type']); else echo "Guest";?></title>
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
    <div class="main-container">
        <div class="top-content">
            <div class="nav-container">
                <i id="menu-btn" class="fa-solid fa-bars"></i>
                <div class="usernav">
                <h6><?php 
                        if($_SESSION['user_type'] === 'admin'){
                            echo $_SESSION['admin_name'];
                        }elseif($_SESSION['user_type'] === 'user'){
                            echo $_SESSION['user_name']; 
                        }else{
                            echo "GUEST";
                        }
                ?></h6>
                <h6 style="margin-left:5px;"><?php 
                    if($_SESSION['user_type'] === 'admin'){
                        echo "(".$_SESSION['admin_email'].")";
                    }elseif($_SESSION['user_type'] === 'user'){
                        echo "(".$_SESSION['user_email'].")";
                    }else{
                        echo "GUEST";
                    }
                    ?></h6>
                </div>
            </div>
        </div>
        <!-- Show and Hide Side Nav Menu -->
        <?php include '../../../templates/sidebar.php'; ?>
        <div id="loading-overlay">
            <div class="loading-spinner"></div>
        </div>
        <center><h3>Import Transaction</h3></center>
        <div class="container-fluid border border-danger rounded mt-3">
            <div class="container-fluid">
                <form id="uploadForm" action="../../../models/saved/saved_billspaymentImportFile.php" method="post" enctype="multipart/form-data">
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
        function loadPartnersForFileType() {
            var $select = $('#companyDropdown');

            // clear existing options and add defaults
            $select.empty();
            $select.append($('<option>', { value: '', text: 'Select Company' }));
            $select.append($('<option>', { value: 'All', text: 'All' }));

            $.ajax({
                url: '../../../fetch/get_partners.php',
                method: 'GET',
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
                        console.log('No partners returned');
                    }

                    list.forEach(function(p) {
                        // use partner_name as both value and display text
                        var name = p.partner_name || '';
                        if (name) {
                            $select.append($('<option>', {
                                value: name,  // using partner_name as value
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

            // Load all partners on page load
            loadPartnersForFileType();

            // Add file type change handler to check for All + KPX combination
            $('#fileType').on('change', function() {
                var fileType = $(this).val();
                var selectedCompany = $('#companyDropdown').val();

                // If "All" is selected and user chooses KPX, show error
                if (selectedCompany === 'All' && fileType === 'KPX') {
                    Swal.fire({
                        title: 'Note',
                        text: 'No All Partners Available for KPX',
                        icon: 'info',
                        confirmButtonText: 'OK',
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                    }).then(function() {
                        // Reset file type selection
                        $('#fileType').val('');
                        // Or optionally reset company selection
                        // $('#companyDropdown').val('').trigger('change');
                    });
                }
            });

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
                        // Reset company selection
                        $('#companyDropdown').val('').trigger('change');
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

                // Additional validation for All + KPX combination
                if (selectedCompany === 'All' && fileType === 'KPX') {
                    e.preventDefault();
                    Swal.fire({
                        title: 'Invalid Combination',
                        text: 'No All Partners Available for KPX. Please select a specific partner.',
                        icon: 'error',
                        confirmButtonText: 'OK'
                    });
                    return false;
                }
                
                // Show loading overlay
                document.getElementById('loading-overlay').style.display = 'block';
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
                    window.location.href = 'billspay-transaction.php';
                }
            });
        }
    </script>
</body>
<?php include '../../../templates/footer.php'; ?>


</html>
