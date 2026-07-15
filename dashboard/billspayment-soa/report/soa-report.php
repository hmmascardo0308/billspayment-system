<?php
// Connect to the database
require_once __DIR__ . '/../../../config/config.php';
require '../../../vendor/autoload.php';

// Start the session
session_start();
@include_once __DIR__ . '/../../../templates/middleware.php';
$id = resolve_user_identifier();
if (empty($id)) { header('Location: ../../../login_form.php'); exit; }
if (!function_exists('has_any_permission') || !has_any_permission(['SOA Report','Bills Payment'])) { header('Location: ../../home.php'); exit; }


// prefer explicit session values for current user email
$current_user_email = $_SESSION['admin_email'] ?? $_SESSION['user_email'] ?? '';

    $status_options = 'SELECT status FROM mldb.soa_transaction group by status';
    $status_result = mysqli_query($conn, $status_options);

    $report_data = 'SELECT * FROM mldb.soa_transaction order by date desc';
    $report_result = mysqli_query($conn, $report_data);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Billing Invoice Report | <?php if($_SESSION['user_type'] === 'admin' || $_SESSION['user_type'] === 'user') echo ucfirst($_SESSION['user_type']); else echo "Guest";?></title>
    <!-- custom CSS file link  -->
    <link rel="stylesheet" href="../../../assets/css/templates/style.css?v=<?php echo time(); ?>">
    <script src="https://kit.fontawesome.com/30b908cc5a.js" crossorigin="anonymous"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../../assets/js/sweetalert2.all.min.js"></script>
    <!-- Google Fonts for Great Vibes -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Great+Vibes&display=swap" rel="stylesheet">

    <link rel="icon" href="../../../images/MLW logo.png" type="image/png">
    <style>
        /* Custom scrollbar styling */
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

        /* Custom styling for warning badge with black text */
        .badge.bg-warning {
            color: #000000 !important;
        }

        /* Sticky header enhancement */
        .sticky-top {
            position: sticky;
            top: 0;
            z-index: 10;
            background: #f8f9fa !important;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        /* Row hover effect */
        .table-row-clickable:hover {
            background-color: #e3f2fd !important;
            transform: scale(1.01);
            transition: all 0.2s ease;
        }

        /* Electronic signature styling with Great Vibes font */
        #modal-prepared-electronic-signature,
        #modal-prepared-electronic-date,
        #modal-reviewed-electronic-signature,
        #modal-reviewed-electronic-date,
        #modal-approved-electronic-signature,
        #modal-approved-electronic-date {
            font-family: 'Great Vibes', cursive !important;
            font-size: 1.2em !important;
            font-weight: 400 !important;
            color: #2c3e50 !important;
        }

        /* SweetAlert Print Options Styling */
        .swal-print-options {
            width: 500px !important;
        }

        .btn-print-with-form,
        .btn-print-without-form {
            border-radius: 5px !important;
            padding: 10px 20px !important;
            font-weight: 600 !important;
            margin: 0 5px !important;
        }

        .btn-print-with-form {
            background-color: #007bff !important;
            border: 2px solid #007bff !important;
            color: white !important;
        }

        .btn-print-with-form:hover {
            background-color: #0056b3 !important;
            border-color: #0056b3 !important;
        }

        .btn-print-without-form {
            background-color: #6c757d !important;
            border: 2px solid #6c757d !important;
            color: white !important;
        }

        .btn-print-without-form:hover {
            background-color: #545b62 !important;
            border-color: #545b62 !important;
        }

        /* Coming Soon SweetAlert Styling */

        .coming-soon-title {
            color: #17a2b8 !important;
            font-weight: 600 !important;
        }

        .coming-soon-content {
            color: #6c757d !important;
            font-size: 16px !important;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .table-responsive {
                max-height: 400px;
            }
            
            .table th, .table td {
                font-size: 12px;
                padding: 0.5rem 0.25rem;
            }
        }

        /* Print styles */
        @media print {
            .table-responsive {
                overflow: visible !important;
                max-height: none !important;
            }
            
            /* Ensure Great Vibes font is preserved in print */
            #modal-prepared-electronic-signature,
            #modal-prepared-electronic-date,
            #modal-reviewed-electronic-signature,
            #modal-reviewed-electronic-date,
            #modal-approved-electronic-signature,
            #modal-approved-electronic-date {
                font-family: 'Great Vibes', cursive !important;
                font-size: 1.2em !important;
                -webkit-print-color-adjust: exact !important;
                color-adjust: exact !important;
            }
        }

        /* SweetAlert custom styling */
        /* .swal-wide {
            width: 600px !important;
        }

        .swal2-html-container {
            text-align: left !important;
        } */
    </style>
</head>
<body>
    <div class="main-container">
        <?php include '../../../templates/header_ui.php'; ?>
        <!-- Show and Hide Side Nav Menu -->
        <?php include '../../../templates/sidebar.php'; ?>
        <div id="loading-overlay">
            <div class="loading-spinner"></div>
        </div>
        <div class="bp-section-header" role="region" aria-label="Page title">
            <div class="bp-section-title">
                <i class="fa-solid fa-file-invoice" aria-hidden="true"></i>
                <div>
                    <h2>Billing Invoice Report</h2>
                    <p class="bp-section-sub">Billing invoice list and filters</p>
                </div>
            </div>
        </div>
        <div class="bp-card container-fluid mt-3 p-4">
            <div class="row">
                <div class="col-md-18">
                    <div class="card">
                        <div class="card-header">
                            <div class="mb-3">
                                <label class="h5 text-muted">INSTRUCTION: <i>To print, double click the row</i></label>
                            </div>
                            <div class="row g-3 align-items-end">
                                <!-- From Date -->
                                <div class="col-md-2">
                                    <label for="start_date" class="form-label small text-muted">From Date:</label>
                                    <input type="date" 
                                        id="start_date" 
                                        name="start_date" 
                                        class="form-control form-control-sm" 
                                        required 
                                        max="<?php echo date('Y-m-d'); ?>">
                                    <div class="invalid-feedback">
                                        Please select a valid start date.
                                    </div>
                                </div>
                                
                                <!-- To Date -->
                                <div class="col-md-2">
                                    <label for="end_date" class="form-label small text-muted">To Date:</label>
                                    <input type="date" 
                                        id="end_date" 
                                        name="end_date" 
                                        class="form-control form-control-sm" 
                                        required 
                                        max="<?php echo date('Y-m-d'); ?>">
                                    <div class="invalid-feedback">
                                        Please select a valid end date.
                                    </div>
                                </div>
                                
                                <!-- Status Dropdown -->
                                <div class="col-md-2">
                                    <label for="status_filter" class="form-label small text-muted">Status:</label>
                                    <select id="status_filter" name="status" class="form-select form-select-sm">
                                        <option value="">All Status</option>
                                        <?php 
                                            if ($status_result && mysqli_num_rows($status_result) > 0) {
                                                while ($row = mysqli_fetch_assoc($status_result)) {
                                                    $status = htmlspecialchars($row['status']);
                                                    $selected = (isset($_GET['status']) && $_GET['status'] == $status) ? 'selected' : '';
                                                    echo "<option value='$status' $selected>" . ucfirst($status) . "</option>";
                                                }
                                            }
                                        ?>
                                    </select>
                                </div>
                                
                                <!-- Search Input -->
                                <div class="col-md-4">
                                    <label for="search_input" class="form-label small text-muted">Search:</label>
                                    <input type="text" 
                                        id="search_input" 
                                        name="search" 
                                        class="form-control form-control-sm" 
                                        placeholder="Search by any field...">
                                </div>
                                
                                <!-- Action Buttons -->
                                <div class="col-md-2">
                                    <div class="btn-group w-100" role="group">
                                        <button type="button" id="clearFilters" class="btn btn-outline-secondary btn-sm">
                                            <i class="fas fa-times"></i> Clear
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="card-body">
                            <!-- <div class="mb-3">
                                <label class="h6 text-muted">Total Amount: ₱<span id="total-amount"> 0.00</span></label>
                            </div> -->
                            <div class="table-responsive" style="max-height: 600px; overflow-y: auto; overflow-x: auto;">
                                <table class="table table-hover table-striped" id="users-table" style="min-width: 1800px;">
                                    <thead class="table-light sticky-top">
                                        <tr>
                                            <th style="min-width: 100px;" class='text-truncate text-center'>Status</th>
                                            <th style="min-width: 120px;" class='text-truncate text-center'>Date</th>
                                            <th style="min-width: 150px;" class='text-truncate text-center'>Control Number</th>
                                            <th style="min-width: 200px;" class='text-truncate text-center'>Partner Name</th>
                                            <th style="min-width: 120px;" class='text-truncate text-center'>PO Number</th>
                                            <th style="min-width: 130px;" class='text-truncate text-center'>Service Charge</th>
                                            <th style="min-width: 120px;" class='text-truncate text-center'>From Date</th>
                                            <th style="min-width: 120px;" class='text-truncate text-center'>To Date</th>
                                            <th style="min-width: 100px;" class='text-truncate text-center'>No. of Transactions</th>
                                            <th style="min-width: 120px;" class='text-truncate text-center'>Amount</th>
                                            <th style="min-width: 120px;" class='text-truncate text-center'>VAT Amount</th>
                                            <th style="min-width: 120px;" class='text-truncate text-center'>Net of VAT</th>
                                            <th style="min-width: 130px;" class='text-truncate text-center'>Withholding Tax</th>
                                            <th style="min-width: 130px;" class='text-truncate text-center'>Net Amount Due</th>
                                            <th style="min-width: 120px;" class='text-truncate text-center'>Created By</th>
                                            <th style="min-width: 120px;" class='text-truncate text-center'>Reviewed By</th>
                                            <th style="min-width: 120px;" class='text-truncate text-center'>Approved By</th>
                                            <th style="min-width: 120px;" class='text-truncate text-center'>Cancelled By</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                            // Add this function at the top after the database queries
                                            function formatCurrency($value) {
                                                if (is_numeric($value)) {
                                                    return "₱" . number_format($value, 2);
                                                }
                                                return htmlspecialchars($value ?? 'N/A');
                                            }

                                            function formatNumber($value) {
                                                if (is_numeric($value)) {
                                                    return number_format($value, 2);
                                                }
                                                return htmlspecialchars($value ?? 'N/A');
                                            }

                                            if ($report_result && mysqli_num_rows($report_result) > 0) {
                                                $total_amount = 0;
                                                while ($row = mysqli_fetch_assoc($report_result)) {
                                                    // Add to total amount (only if it's numeric)
                                                    $total_amount += is_numeric($row['net_amount_due']) ? floatval($row['net_amount_due']) : 0;
                                                    
                                                    // Format date
                                                    $formatted_date = date('F d, Y', strtotime($row['date']));
                                                    $from_date = !empty($row['from_date']) ? date('F d, Y', strtotime($row['from_date'])) : 'N/A';
                                                    $to_date = !empty($row['to_date']) ? date('F d, Y', strtotime($row['to_date'])) : 'N/A';
                                                    
                                                    // Status badge
                                                    $status_class = '';
                                                    switch(strtolower($row['status'])) {
                                                        case 'approved':
                                                            $status_class = 'badge bg-success';
                                                            break;
                                                        case 'prepared':
                                                            $status_class = 'badge bg-secondary';
                                                            break;
                                                        case 'cancelled':
                                                            $status_class = 'badge bg-danger';
                                                            break;
                                                        default:
                                                            $status_class = 'badge bg-warning';
                                                    }
                                                    
                                                    // Prepare prepared signature attribute: try to resolve stored id to actual signature image
                                                    $prepared_sig_attr = '';
                                                    if (!empty($row['prepared_signature'])) {
                                                        $possible_id = $conn->real_escape_string($row['prepared_signature']);
                                                        $sigRes = $conn->query("SELECT signature FROM mldb.user_sig WHERE id_number='" . $possible_id . "' LIMIT 1");
                                                        if ($sigRes && $sigRes->num_rows) {
                                                            $sigRow = $sigRes->fetch_assoc();
                                                            if (!empty($sigRow['signature'])) {
                                                                $prepared_sig_attr = 'data:image/png;base64,' . base64_encode($sigRow['signature']);
                                                            }
                                                        }
                                                        if ($prepared_sig_attr === '') {
                                                            // fallback to literal stored value
                                                            $prepared_sig_attr = $row['prepared_signature'];
                                                        }
                                                    }

                                                    // Prepare reviewed signature attribute similarly: if the DB stores an id, resolve to image blob
                                                    $reviewed_sig_attr = '';
                                                    if (!empty($row['reviewed_signature'])) {
                                                        $possible_rev = $conn->real_escape_string($row['reviewed_signature']);
                                                        $revRes = $conn->query("SELECT signature FROM mldb.user_sig WHERE id_number='" . $possible_rev . "' LIMIT 1");
                                                        if ($revRes && $revRes->num_rows) {
                                                            $revRow = $revRes->fetch_assoc();
                                                            if (!empty($revRow['signature'])) {
                                                                $reviewed_sig_attr = 'data:image/png;base64,' . base64_encode($revRow['signature']);
                                                            }
                                                        }
                                                        if ($reviewed_sig_attr === '') {
                                                            // if DB contains an id but there's no stored blob, display a friendly placeholder
                                                            if (!empty($possible_rev)) {
                                                                $reviewed_sig_attr = 'electronically signed';
                                                            } else {
                                                                // fallback to literal stored value if it was not an id
                                                                $reviewed_sig_attr = $row['reviewed_signature'];
                                                            }
                                                        }
                                                    }

                                                    // Prepare noted/approved signature attribute: resolve id to image blob when possible
                                                    $noted_sig_attr = '';
                                                    if (!empty($row['noted_signature'])) {
                                                        $possible_noted = $conn->real_escape_string($row['noted_signature']);
                                                        $notedRes = $conn->query("SELECT signature FROM mldb.user_sig WHERE id_number='" . $possible_noted . "' LIMIT 1");
                                                        if ($notedRes && $notedRes->num_rows) {
                                                            $notedRow = $notedRes->fetch_assoc();
                                                            if (!empty($notedRow['signature'])) {
                                                                $noted_sig_attr = 'data:image/png;base64,' . base64_encode($notedRow['signature']);
                                                            }
                                                        }
                                                        if ($noted_sig_attr === '') {
                                                            if (!empty($possible_noted)) {
                                                                $noted_sig_attr = 'electronically signed';
                                                            } else {
                                                                $noted_sig_attr = $row['noted_signature'];
                                                            }
                                                        }
                                                    }

                                                    echo "<tr class='table-row-clickable' ondblclick='showSOADetails(this)' style='cursor: pointer;' 
                                                        data-status='" . htmlspecialchars($row['status']) . "'
                                                        data-date='{$formatted_date}'
                                                        data-reference='" . htmlspecialchars($row['reference_number'] ?? 'N/A') . "'
                                                        data-partner='" . htmlspecialchars($row['partner_Name'] ?? 'N/A') . "'
                                                        data-customer-tin='" . htmlspecialchars($row['partner_Tin'] ?? 'N/A') . "'
                                                        data-address='" . htmlspecialchars($row['address'] ?? 'N/A') . "'
                                                        data-business-style='" . htmlspecialchars($row['business_style'] ?? 'N/A') . "'
                                                        data-po='" . htmlspecialchars($row['po_number'] ?? 'N/A') . "'
                                                        data-service-charge='" . htmlspecialchars(str_replace(',', '', $row['service_charge'] ?? '0')) . "'
                                                        data-from-date='{$from_date}'
                                                        data-to-date='{$to_date}'
                                                        data-formula='" . htmlspecialchars($row['formula'] ?? 'N/A') . "'
                                                        data-formula-details='" . htmlspecialchars($row['formulaInc_Exc'] ?? 'N/A') . "'
                                                        data-transactions='" . htmlspecialchars($row['number_of_transactions'] ?? '0') . "'
                                                        data-amount='" . htmlspecialchars($row['amount'] ?? '0') . "'
                                                        data-vat='" . htmlspecialchars(str_replace(',', '', $row['vat_amount'] ?? '0')) . "'
                                                        data-net-vat='" . htmlspecialchars(str_replace(',', '', $row['net_of_vat'] ?? '0')) . "'
                                                        data-withholding='" . htmlspecialchars(str_replace(',', '', $row['withholding_tax'] ?? '0')) . "'
                                                        data-net-amount='" . htmlspecialchars(str_replace(',', '', $row['net_amount_due'] ?? '0')) . "'
                                                        data-amount-add='" . htmlspecialchars($row['amount_add'] ?? '0') . "'
                                                        data-number-of-days='" . htmlspecialchars($row['numberOf_days'] ?? '0') . "'
                                                        data-add-amount='" . htmlspecialchars($row['add_amount'] ?? '0') . "'

                                                        data-prepared-signature='" . htmlspecialchars($prepared_sig_attr ?? '') . "'
                                                        data-prepared-date-signature='" . htmlspecialchars($row['preparedDate_signature'] ?? '') . "'
                                                        data-created-by='" . htmlspecialchars($row['prepared_by'] ?? 'N/A') . "'

                                                        data-reviewed-signature='" . htmlspecialchars($reviewed_sig_attr ?? $row['reviewed_signature'] ?? '') . "'
                                                        data-reviewed-date-signature='" . htmlspecialchars($row['reviewedDate_signature'] ?? '') . "'
                                                        data-reviewed-by='" . htmlspecialchars($row['reviewed_by'] ?? 'N/A') . "'

                                                        data-noted-signature='" . htmlspecialchars($noted_sig_attr ?? $row['noted_signature'] ?? '') . "'
                                                        data-noted-date-signature='" . htmlspecialchars($row['notedDate_signature'] ?? '') . "'
                                                        data-noted-for='" . htmlspecialchars($row['noted_by'] ?? '') . "'
                                                        data-approved-by='" . htmlspecialchars($row['notedFix_signature'] ?? 'N/A') . "'

                                                        data-cancelled-date='" . htmlspecialchars($row['cancelled_date'] ?? '') . "'
                                                        data-cancellation-reason='" . htmlspecialchars($row['reasonOf_cancellation'] ?? '') . "'
                                                        data-cancelled-by='" . htmlspecialchars($row['cancelled_by'] ?? 'N/A') . "'>";

                                                    echo "<td><span class='{$status_class}'>" . ucfirst(htmlspecialchars($row['status'])) . "</span></td>";
                                                    echo "<td class='text-truncate'>{$formatted_date}</td>";
                                                    echo "<td class='text-center text-truncate'>" . htmlspecialchars($row['reference_number'] ?? 'N/A') . "</td>";
                                                    echo "<td class='text-truncate' style='max-width: 400px;'>" . htmlspecialchars($row['partner_Name'] ?? 'N/A') . "</td>";
                                                    echo "<td>" . htmlspecialchars($row['po_number'] ?? 'N/A') . "</td>";
                                                    echo "<td class='text-center'>" . formatCurrency($row['service_charge']) . "</td>";
                                                    echo "<td class='text-truncate'>{$from_date}</td>";
                                                    echo "<td class='text-truncate'>{$to_date}</td>";
                                                    echo "<td class='text-center'>" . number_format($row['number_of_transactions'] ?? 0) . "</td>";
                                                    echo "<td class='text-end'>" . formatCurrency(str_replace(',', '', $row['amount'])) . "</td>";
                                                    echo "<td class='text-end'>" . formatCurrency(str_replace(',', '', $row['vat_amount'])) . "</td>";
                                                    echo "<td class='text-end'>" . formatCurrency(str_replace(',', '', $row['net_of_vat'])) . "</td>";
                                                    echo "<td class='text-end'>" . formatCurrency(str_replace(',', '', $row['withholding_tax'])) . "</td>";
                                                    echo "<td class='text-end'>" . formatCurrency(str_replace(',', '', $row['net_amount_due'])) . "</td>";
                                                    echo "<td class='text-truncate'>" . htmlspecialchars($row['prepared_by'] ?? 'N/A') . "</td>";
                                                    echo "<td class='text-truncate'>" . htmlspecialchars($row['reviewed_by'] ?? 'N/A') . "</td>";
                                                    echo "<td class='text-truncate'>" . htmlspecialchars($row['noted_by'] ?? 'N/A') . "</td>";
                                                    echo "<td class='text-truncate'>" . htmlspecialchars($row['cancelled_by'] ?? 'N/A') . "</td>";
                                                    echo "</tr>";
                                                }
                                                
                                                // Update total amount display with JavaScript
                                                echo "<script>document.getElementById('total-amount').textContent = '" . number_format($total_amount, 2) . "';</script>";
                                                
                                            } else {
                                                echo "<tr><td colspan='18' class='text-center text-muted'>No data available</td></tr>";
                                            }
                                        ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Pagination Controls -->
                            <div class="d-flex justify-content-between align-items-center mt-3">
                                <div class="d-flex align-items-center">
                                    <label for="recordsPerPage" class="form-label me-2 small text-muted">Show:</label>
                                    <select id="recordsPerPage" class="form-select form-select-sm" style="width: auto;">
                                        <option value="10">10</option>
                                        <option value="25" selected>25</option>
                                        <option value="50">50</option>
                                        <option value="100">100</option>
                                        <option value="all">All</option>
                                    </select>
                                    <span class="ms-2 small text-muted">entries</span>
                                </div>
                                
                                <div class="small text-muted" id="paginationInfo">
                                    Showing <span id="startRecord">1</span> to <span id="endRecord">25</span> of <span id="totalRecords">0</span> entries
                                </div>
                                
                                <nav aria-label="Table pagination">
                                    <ul class="pagination pagination-sm mb-0" id="paginationControls">
                                        <li class="page-item" id="prevPage">
                                            <a class="page-link" href="#" aria-label="Previous">
                                                <span aria-hidden="true">&laquo;</span>
                                            </a>
                                        </li>
                                        <!-- Page numbers will be inserted here by JavaScript -->
                                        <li class="page-item" id="nextPage">
                                            <a class="page-link" href="#" aria-label="Next">
                                                <span aria-hidden="true">&raquo;</span>
                                            </a>
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
    <!-- Bootstrap Modal for SOA Details -->
    <div class="modal fade" id="soaDetailsModal" tabindex="-1" aria-labelledby="soaDetailsModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="soaDetailsModalLabel">
                        <i class="fas fa-file-invoice-dollar me-2"></i>Billing Invoice Transaction Details
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- Period Information -->
                    <!-- <div class="col-md-6">
                        <div class="card border-0 bg-light mb-3">
                            <div class="card-header bg-info text-white py-2">
                                <h6 class="mb-0"><i class="fas fa-calendar-alt me-1"></i>Period Information</h6>
                            </div>
                            <div class="card-body py-2">
                                <table class="table table-sm table-borderless mb-0">
                                    <tr>
                                        <td class="fw-bold">From Date:</td>
                                        
                                    </tr>
                                    <tr>
                                        <td class="fw-bold">To Date:</td>
                                        
                                    </tr>
                                    
                                </table>
                            </div>
                        </div>
                    </div> -->

                    <!-- Basic Information -->
                    <div class="card border-0 bg-light mb-3">
                        <div class="card-header text-dark py-2">
                            <h6 class="mb-0"><i class="fas fa-info-circle me-1"></i>Basic Information</h6>
                        </div>
                        <div class="card-body py-2">
                            <div class="row">
                                <div class="col-md-10">
                                    <table class="table table-sm table-borderless mb-0">
                                        <tr>
                                            <td class="fw-bold">Status:</td>
                                            <td><span id="modal-status" class="badge"></span></td>
                                        </tr>
                                        <tr>
                                            <td class="fw-bold">Date:</td>
                                            <td id="modal-date"></td>
                                        </tr>
                                        <tr>
                                            <td class="fw-bold text-truncate">Control Number:</td>
                                            <td id="modal-reference"></td>
                                        </tr>
                                        <tr>
                                            <td class="fw-bold text-truncate">Partner Name:</td>
                                            <td id="modal-partner" class="text-truncate" ></td>
                                        </tr>
                                        <tr>
                                            <td class="fw-bold text-truncate">Customer TIN:</td>
                                            <td id="modal-customer-tin"></td>
                                        </tr>
                                        <tr>
                                            <td class="fw-bold">Address:</td>
                                            <td class="text-truncate" id="modal-address" style="max-width: 900px;"></td>
                                        </tr>
                                        <tr>
                                            <td class="fw-bold text-truncate">Business Style:</td>
                                            <td id="modal-business-style"></td>
                                        </tr>
                                        <tr>
                                            <td class="fw-bold text-truncate">PO Number:</td>
                                            <td id="modal-po"></td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Amount Breakdown -->
                    <div class="card border-0 bg-light mb-3">
                        <div class="card-header text-dark py-2">
                            <h6 class="mb-0"><i class="fas fa-calculator me-1"></i>Amount Breakdown &nbsp;<span class="badge text-bg-danger" id="modal-formula"></span></h6>
                        </div>
                        <div class="card-body py-2">
                            <div class="row">
                                <div class="col-md-6">
                                    <table class="table table-sm table-borderless mb-0">
                                        <tr>
                                            <td colspan="4" class="text-center">
                                                <span class="badge text-bg-secondary fw-bold">PARTICULARS</span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td class="fw-bold" colspan="3">Service Charge:</td>
                                            <td id="modal-service-charge" class="text-end"></td>
                                        </tr>
                                        <tr>
                                            <td class="fw-bold" colspan="3">No. of Transactions:</td>
                                            <td id="modal-transactions" class="text-end"></td>
                                        </tr>
                                        <tr>
                                            <td class="fw-bold">From:</td>
                                            <td id="modal-from-date" class="text-start"></td>
                                            <td class="fw-bold">To:</td>
                                            <td id="modal-to-date" class="text-end"></td>
                                        </tr>
                                        <tr>
                                            <td id="modal-formula-details" colspan="4"></td>
                                        </tr>
                                        <tr id="bank-charge-row">
                                            <td class="fw-bold" colspan="2">Add Bank Charges: </td>
                                            <td id="modal-bank-charge" class="text-end"></td>
                                            <td><span style="font-size:12px; color:#d70c0c; margin-left:5px; font-weight:700; font-style:italic;">(Added in NET AMOUNT DUE)</span></td>
                                        </tr>
                                    </table>
                                </div>
                                <div class="col-md-6">
                                    <table class="table table-sm table-borderless mb-0">
                                        <tr >
                                            <td colspan="2" class="text-center">
                                                <span class="badge text-bg-secondary fw-bold">AMOUNT</span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td class="fw-bold">Gross Amount:</td>
                                            <td id="modal-amount" class="text-end"></td>
                                        </tr>
                                        <tr>
                                            <td class="fw-bold">VAT Amount:</td>
                                            <td id="modal-vat" class="text-end"></td>
                                        </tr>
                                        <tr>
                                            <td class="fw-bold">Net of VAT:</td>
                                            <td id="modal-net-vat" class="text-end"></td>
                                        </tr>
                                        <tr>
                                            <td class="fw-bold">Withholding Tax:</td>
                                            <td id="modal-withholding" class="text-end"></td>
                                        </tr>
                                        <tr id="add-amount-row">
                                            <td class="fw-bold">Add Amount:</td>
                                            <td id="modal-add-amount" class="text-end"></td>
                                        </tr>
                                        <tr class="border-top">
                                            
                                        </tr>
                                        <tr><td></td></tr>
                                        <tr>
                                            <td class="fw-bold">Total Amount Due:</td>
                                            <td id="modal-total-amount-due" class="text-end"></td>
                                        </tr>
                                        <tr>
                                            <td class="fw-bold">Less Withholding Tax:</td>
                                            <td id="modal-withholding-display" class="text-end"></td>
                                        </tr>
                                        <tr class="border-top">
                                            <td class="fw-bold h6 text-success">Net Amount Due:</td>
                                            <td id="modal-net-amount" class="text-end fw-bold h6 text-success"></td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Personnel Information -->
                    <div class="card border-0 bg-light">
                        <div class="card-header text-dark py-2">
                            <h6 class="mb-0"><i class="fas fa-users me-1"></i>Personnel Information</h6>
                        </div>
                        <div class="card-body py-2">
                            <div class="row">
                                <!-- Always show all personnel columns, let JavaScript handle visibility -->
                                <div class="col-md-3" id="prepared-by-section">
                                    <table class="table table-sm table-borderless mb-0">
                                        <tr>
                                            <td class="fw-bold">Prepared By:</td>
                                        </tr>
                                        <tr>
                                            <td id="modal-prepared-electronic-signature"></td>
                                        </tr>
                                        <tr>
                                            <td id="modal-prepared-electronic-date"></td>
                                        </tr>
                                        <tr>
                                            <td id="modal-created-by"></td>
                                        </tr>
                                        <tr>
                                            <td class="fw-bold">Accounting Staff</td>
                                        </tr>
                                    </table>
                                </div>
                                
                                <div class="col-md-3" id="reviewed-by-section">
                                    <table class="table table-sm table-borderless mb-0">
                                        <tr>
                                            <td class="fw-bold">Reviewed By:</td>
                                        </tr>
                                        <tr>
                                            <td id="modal-reviewed-electronic-signature"></td>
                                        </tr>
                                        <tr>
                                            <td id="modal-reviewed-electronic-date"></td>
                                        </tr>
                                        <tr>
                                            <td id="modal-reviewed-by"></td>
                                        </tr>
                                        <tr>
                                            <td class="fw-bold">Department Manager</td>
                                        </tr>
                                    </table>
                                </div>
                                
                                <div class="col-md-3" id="approved-by-section">
                                    <table class="table table-sm table-borderless mb-0">
                                        <tr>
                                            <td class="fw-bold">Approved By:</td>
                                        </tr>
                                        <tr>
                                            <td id="modal-approved-electronic-signature"></td>
                                        </tr>
                                        <tr>
                                            <td id="modal-approved-electronic-date"></td>
                                        </tr>
                                        <tr>
                                            <td><i>for: </i><i id="modal-approved-for"></i></td>
                                        </tr>
                                        <tr>
                                            <td id="modal-approved-by"></td>
                                        </tr>
                                        <tr>
                                            <td class="fw-bold">Division Head</td>
                                        </tr>
                                    </table>
                                </div>
                                
                                <div class="col-md-3" id="cancelled-by-section">
                                    <table class="table table-sm table-borderless mb-0">
                                        <tr>
                                            <td class="fw-bold">Cancelled By:</td>
                                        </tr>
                                        <tr>
                                            <td id="modal-cancelled-by"></td>
                                        </tr>
                                        <tr>
                                            <td class="fw-bold">Division Head</td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" onclick="printModalContent()">
                        <i class="fas fa-print me-1"></i>Print
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Close
                    </button>
                </div>
            </div>
        </div>
    </div>
</body>
<?php include '../../../templates/footer.php'; ?>
<script>
// Function to show SOA details in modal
function showSOADetails(row) {
    // Get data from row attributes
    const status = row.getAttribute('data-status');
    const date = row.getAttribute('data-date');
    const reference = row.getAttribute('data-reference');
    const partner = row.getAttribute('data-partner');

    const customertin = row.getAttribute('data-customer-tin');
    const address = row.getAttribute('data-address');
    const businessstyle = row.getAttribute('data-business-style');

    const po = row.getAttribute('data-po');
    const serviceCharge = row.getAttribute('data-service-charge');
    const fromDate = row.getAttribute('data-from-date');
    const toDate = row.getAttribute('data-to-date');

    const formula = row.getAttribute('data-formula');
    const formulaDetails = row.getAttribute('data-formula-details');

    const transactions = row.getAttribute('data-transactions');
    const amount = row.getAttribute('data-amount');
    const vat = row.getAttribute('data-vat');
    const netVat = row.getAttribute('data-net-vat');
    const withholding = row.getAttribute('data-withholding');
    const netAmount = row.getAttribute('data-net-amount');
    
    // Get cancellation data inside the function
    const cancelledDate = row.getAttribute('data-cancelled-date');
    const cancellationReason = row.getAttribute('data-cancellation-reason');
    const cancelledBy = row.getAttribute('data-cancelled-by');
    
    // Get new bank charges and add amount data
    const amountAdd = row.getAttribute('data-amount-add');
    const numberOfDays = row.getAttribute('data-number-of-days');
    const addAmount = row.getAttribute('data-add-amount');
    
    // Get electronic signature data
    const preparedSignature = row.getAttribute('data-prepared-signature');
    const preparedDateSignature = row.getAttribute('data-prepared-date-signature');
    const createdBy = row.getAttribute('data-created-by');

    const reviewedSignature = row.getAttribute('data-reviewed-signature');
    const reviewedDateSignature = row.getAttribute('data-reviewed-date-signature');
    const reviewedBy = row.getAttribute('data-reviewed-by');

    const notedSignature = row.getAttribute('data-noted-signature');
    const notedDateSignature = row.getAttribute('data-noted-date-signature');
    const notedFor = row.getAttribute('data-noted-for');
    const approvedBy = row.getAttribute('data-approved-by');

    // Normalize signature values: if the value is a numeric id (no blob),
    // replace it with friendly fallback text to avoid showing raw IDs.
    const isNumericId = (val) => val && /^\d+$/.test(val.trim());
    if (isNumericId(preparedSignature)) {
        // prepared signature has id but no blob
        row.setAttribute('data-prepared-signature', 'electronically signed');
    }
    if (isNumericId(reviewedSignature)) {
        row.setAttribute('data-reviewed-signature', 'electronically signed');
    }
    if (isNumericId(notedSignature)) {
        row.setAttribute('data-noted-signature', 'electronically signed');
    }

    // Function to replace name or signature placeholder with actual signature
    // Accepts either `id_number` (digits) or `full_name` text. If element
    // currently shows 'electronically signed' or an id, we prefer to query
    // by id when available.
    const fetchAndReplaceSignature = async (elementId, { id, name }) => {
        // if no id and no valid name, nothing to lookup
        if (!id && (!name || name === 'N/A')) return;
        try {
            const payload = id ? { id_number: id } : { full_name: name };
            const resp = await fetch('get-user-signature.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await resp.json();
            console.log('get-user-signature response', payload, data);
            const el = document.getElementById(elementId);
            if (!el) return;
            // If we started with an id, we already set friendly text; only
            // replace when a blob is returned. If no blob, keep 'electronically signed'.
            if (data && data.signature) {
                el.innerHTML = '<img src="' + data.signature + '" alt="signature" style="max-height:72px;object-fit:contain;display:block;margin:0;" />';
            } else if (data && data.id) {
                // keep friendly text when id exists but no blob
                el.textContent = 'electronically signed';
                console.log('signature blob not found, id present:', data.id);
            } else {
                console.log('no user found for', payload, data);
            }
        } catch (e) {
            // ignore errors silently
            console.error('Signature lookup failed', e);
        }
    };

    // NOTE: signature lookups will be called later, after modal text assignments,
    // to avoid being overwritten by subsequent assignments in this function.
    
    // Format currency function
    function formatModalCurrency(value) {
        if (value && !isNaN(parseFloat(value))) {
            return "₱" + parseFloat(value).toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }
        return value || 'N/A';
    }
    
    // Format number function
    function formatModalNumber(value) {
        if (value && !isNaN(parseFloat(value))) {
            return parseFloat(value).toLocaleString('en-US', {
                minimumFractionDigits: 0,
                maximumFractionDigits: 0
            });
        }
        return value || 'N/A';
    }
    
    // Format date function for electronic signatures
    function formatSignatureDate(dateValue) {
        if (!dateValue || dateValue === 'N/A' || dateValue.trim() === '') {
            return 'N/A';
        }
        try {
            const date = new Date(dateValue);
            if (!isNaN(date.getTime())) {
                // Format to match PHP's "F d, Y" format exactly
                const options = {
                    year: 'numeric',
                    month: 'long',
                    day: '2-digit'
                };
                return date.toLocaleDateString('en-US', options);
            }
        } catch (e) {
            return dateValue;
        }
        return dateValue;
    }
    
    // Calculate Total Amount Due (Gross Amount + VAT Amount)
    function calculateTotalAmountDue(grossAmount, vatAmount) {
        const gross = parseFloat(grossAmount) || 0;
        const vat = parseFloat(vatAmount) || 0;
        return gross + vat;
    }
    
    // Set status badge
    const statusElement = document.getElementById('modal-status');
    if (statusElement) {
        statusElement.textContent = status ? status.charAt(0).toUpperCase() + status.slice(1) : 'N/A';
        
        // Set status badge class
        statusElement.className = 'badge ';
        switch(status ? status.toLowerCase() : '') {
            case 'approved':
                statusElement.className += 'bg-success';
                break;
            case 'prepared':
                statusElement.className += 'bg-secondary';
                break;
            case 'cancelled':
                statusElement.className += 'bg-danger';
                break;
            default:
                statusElement.className += 'bg-warning';
        }
    }
    
    // Populate modal fields with null checks
    const modalElements = {
        'modal-date': date || 'N/A',
        'modal-reference': reference || 'N/A',
        'modal-partner': partner || 'N/A',
        'modal-customer-tin': customertin || 'N/A',
        'modal-address': address || 'N/A',
        'modal-business-style': businessstyle || 'N/A',
        'modal-po': po || 'N/A',
        'modal-service-charge': formatModalCurrency(serviceCharge),
        'modal-from-date': fromDate || 'N/A',
        'modal-to-date': toDate || 'N/A',
        'modal-formula': formula || 'N/A',
        'modal-formula-details': formulaDetails || 'N/A',
        'modal-transactions': formatModalNumber(transactions),
        'modal-amount': formatModalCurrency(amount),
        'modal-vat': formatModalCurrency(vat),
        'modal-net-vat': formatModalCurrency(netVat),
        'modal-withholding': formatModalCurrency(withholding),
        'modal-net-amount': formatModalCurrency(netAmount)
    };
    
    // Populate all modal elements
    Object.keys(modalElements).forEach(id => {
        const element = document.getElementById(id);
        if (element) {
            element.textContent = modalElements[id];
        }
    });
    
    // Populate electronic signature fields
    const preparedElectronicSig = document.getElementById('modal-prepared-electronic-signature');
    const preparedElectronicDate = document.getElementById('modal-prepared-electronic-date');
    const reviewedElectronicSig = document.getElementById('modal-reviewed-electronic-signature');
    const reviewedElectronicDate = document.getElementById('modal-reviewed-electronic-date');
    const approvedElectronicSig = document.getElementById('modal-approved-electronic-signature');
    const approvedElectronicDate = document.getElementById('modal-approved-electronic-date');
    const approvedForElement = document.getElementById('modal-approved-for');
    
    if (preparedElectronicSig) {
        if (preparedSignature && preparedSignature.indexOf('data:image/') === 0) {
            preparedElectronicSig.innerHTML = '<img src="' + preparedSignature + '" alt="Prepared signature" style="max-height:72px;object-fit:contain;display:block;margin:0;" />';
        } else {
            preparedElectronicSig.textContent = preparedSignature || '';
        }
    }
    if (preparedElectronicDate) preparedElectronicDate.textContent = formatSignatureDate(preparedDateSignature);
    if (reviewedElectronicSig) {
        if (reviewedSignature && reviewedSignature.indexOf('data:image/') === 0) {
            reviewedElectronicSig.innerHTML = '<img src="' + reviewedSignature + '" alt="Reviewed signature" style="max-height:72px;object-fit:contain;display:block;margin:0;" />';
        } else {
            reviewedElectronicSig.textContent = reviewedSignature || '';
        }
    }
    if (reviewedElectronicDate) reviewedElectronicDate.textContent = formatSignatureDate(reviewedDateSignature);
    if (approvedElectronicSig) {
        if (notedSignature && notedSignature.indexOf('data:image/') === 0) {
            approvedElectronicSig.innerHTML = '<img src="' + notedSignature + '" alt="Approved signature" style="max-height:72px;object-fit:contain;display:block;margin:0;" />';
        } else if (notedSignature === 'electronically signed') {
            approvedElectronicSig.textContent = 'electronically signed';
        } else {
            approvedElectronicSig.textContent = notedSignature || '';
        }
    }
    if (approvedElectronicDate) approvedElectronicDate.textContent = formatSignatureDate(notedDateSignature);
    if (approvedForElement) approvedForElement.textContent = notedFor || '';
    
    // Handle Bank Charges and Add Amount based on PO number
    const hasPO = po && po.trim() !== '' && po !== 'N/A';
    
    const modalBankCharge = document.getElementById('modal-bank-charge');
    const modalAddAmount = document.getElementById('modal-add-amount');
    
    if (hasPO) {
        // Show bank charges calculation if PO exists
        const bankChargeDisplay = (amountAdd && numberOfDays && amountAdd !== '0' && numberOfDays !== '0') 
            ? `${formatModalCurrency(amountAdd).replace('₱', '')} × ${numberOfDays}` 
            : 'N/A';
        
        if (modalBankCharge) modalBankCharge.textContent = bankChargeDisplay;
        if (modalAddAmount) modalAddAmount.textContent = formatModalCurrency(addAmount);
    } else {
        // Hide or show N/A when no PO
        if (modalBankCharge) modalBankCharge.textContent = 'N/A';
        if (modalAddAmount) modalAddAmount.textContent = 'N/A';
    }
    
    // Calculate and display Total Amount Due
    const totalAmountDue = calculateTotalAmountDue(amount, vat);
    const modalTotalAmountDue = document.getElementById('modal-total-amount-due');
    const modalWithholdingDisplay = document.getElementById('modal-withholding-display');
    
    if (modalTotalAmountDue) modalTotalAmountDue.textContent = formatModalCurrency(totalAmountDue);
    if (modalWithholdingDisplay) modalWithholdingDisplay.textContent = formatModalCurrency(withholding);

    const bankChargeRow = document.getElementById('bank-charge-row');
    const addAmountRow = document.getElementById('add-amount-row');

    if (hasPO && (amountAdd !== '0' || addAmount !== '0')) {
        if (bankChargeRow) bankChargeRow.style.display = '';
        if (addAmountRow) addAmountRow.style.display = '';
    } else {
        if (bankChargeRow) bankChargeRow.style.display = 'none';
        if (addAmountRow) addAmountRow.style.display = 'none';
    }

    // Handle personnel information visibility based on status using section IDs
    const preparedBySection = document.getElementById('prepared-by-section');
    const reviewedBySection = document.getElementById('reviewed-by-section');
    const approvedBySection = document.getElementById('approved-by-section');
    const cancelledBySection = document.getElementById('cancelled-by-section');
    
    if (preparedBySection && reviewedBySection && approvedBySection && cancelledBySection) {
        // Reset all sections to default state first and restore original labels
        preparedBySection.style.display = 'block';
        preparedBySection.className = 'col-md-3';
        reviewedBySection.style.display = 'block';
        reviewedBySection.className = 'col-md-3';
        approvedBySection.style.display = 'block';
        approvedBySection.className = 'col-md-3';
        cancelledBySection.style.display = 'block';
        cancelledBySection.className = 'col-md-3';

        // Reset labels to original text
        const reviewedByLabel = reviewedBySection.querySelector('.fw-bold');
        const approvedByLabel = approvedBySection.querySelector('.fw-bold');

        if (reviewedByLabel) {
            reviewedByLabel.textContent = 'Reviewed By:';
        }
        if (approvedByLabel) {
            approvedByLabel.textContent = 'Approved By:';
        }

        // Reset content
        const modalCreatedBy = document.getElementById('modal-created-by');
        const modalReviewedBy = document.getElementById('modal-reviewed-by');
        const modalApprovedBy = document.getElementById('modal-approved-by');
        
        if (modalCreatedBy) modalCreatedBy.textContent = createdBy || 'N/A';
        if (modalReviewedBy) modalReviewedBy.textContent = reviewedBy || 'N/A';
        if (modalApprovedBy) modalApprovedBy.textContent = approvedBy || 'N/A';

        // After assigning name text, replace signature placeholders with images.
        // Prefer querying by id if the signature placeholder value is numeric.
        const preparedSigEl = document.getElementById('modal-prepared-electronic-signature');
        const reviewedSigEl = document.getElementById('modal-reviewed-electronic-signature');
        const approvedSigEl = document.getElementById('modal-approved-electronic-signature');

        const getTextOrChildText = (el) => el ? (el.textContent || el.innerText || '').trim() : '';

        // If placeholder shows digits or 'electronically signed', try lookup.
        const preparedPlaceholder = getTextOrChildText(preparedSigEl);
        const reviewedPlaceholder = getTextOrChildText(reviewedSigEl);
        const approvedPlaceholder = getTextOrChildText(approvedSigEl);

        const digitsOnly = (v) => v && /^\d+$/.test(v);

        // prepared
        if (digitsOnly(preparedPlaceholder)) {
            fetchAndReplaceSignature('modal-prepared-electronic-signature', { id: preparedPlaceholder, name: createdBy });
        } else if (preparedPlaceholder === 'electronically signed') {
            fetchAndReplaceSignature('modal-prepared-electronic-signature', { id: null, name: createdBy });
        }

        // reviewed
        if (digitsOnly(reviewedPlaceholder)) {
            fetchAndReplaceSignature('modal-reviewed-electronic-signature', { id: reviewedPlaceholder, name: reviewedBy });
        } else if (reviewedPlaceholder === 'electronically signed') {
            fetchAndReplaceSignature('modal-reviewed-electronic-signature', { id: null, name: reviewedBy });
        }

        // approved
        if (digitsOnly(approvedPlaceholder)) {
            fetchAndReplaceSignature('modal-approved-electronic-signature', { id: approvedPlaceholder, name: notedFor || approvedBy });
        } else if (approvedPlaceholder === 'electronically signed') {
            fetchAndReplaceSignature('modal-approved-electronic-signature', { id: null, name: notedFor || approvedBy });
        }

        if (status && status.toLowerCase() === 'prepared') {
            reviewedBySection.style.display = 'none';
            approvedBySection.style.display = 'none';
            cancelledBySection.style.display = 'none';
            preparedBySection.className = 'col-md-12';
        } else if (status && status.toLowerCase() === 'reviewed') {
            approvedBySection.style.display = 'none';
            cancelledBySection.style.display = 'none';
            preparedBySection.className = 'col-md-6';
            reviewedBySection.className = 'col-md-6';
        } else if (status && status.toLowerCase() === 'approved') {
            cancelledBySection.style.display = 'none';
            preparedBySection.className = 'col-md-4';
            reviewedBySection.className = 'col-md-4';
            approvedBySection.className = 'col-md-4';
        } else if (status && status.toLowerCase() === 'cancelled') {
            const hasReviewedBy = reviewedBy && reviewedBy.trim() !== '' && reviewedBy !== 'N/A';
            
            if (hasReviewedBy) {
                cancelledBySection.style.display = 'none';
                if (approvedByLabel) {
                    approvedByLabel.textContent = 'Cancelled By:';
                }
                if (modalApprovedBy) modalApprovedBy.textContent = cancelledBy || 'N/A';
                preparedBySection.className = 'col-md-4';
                reviewedBySection.className = 'col-md-4';
                approvedBySection.className = 'col-md-4';
            } else {
                approvedBySection.style.display = 'none';
                cancelledBySection.style.display = 'none';
                if (reviewedByLabel) {
                    reviewedByLabel.textContent = 'Cancelled By:';
                }
                if (modalReviewedBy) modalReviewedBy.textContent = cancelledBy || 'N/A';
                preparedBySection.className = 'col-md-6';
                reviewedBySection.className = 'col-md-6';
            }
        }
    }
    
    // Handle modal footer buttons based on status
    const printButton = document.querySelector('.modal-footer .btn-primary');
    const closeButton = document.querySelector('.modal-footer .btn-secondary');
    const modalFooter = document.querySelector('.modal-footer');

    if (modalFooter) {
        // Remove any existing reason button
        const existingReasonButton = modalFooter.querySelector('.btn-warning');
        if (existingReasonButton) {
            existingReasonButton.remove();
        }

        if (status && status.toLowerCase() === 'approved') {
            if (printButton) {
                printButton.style.display = 'inline-block';
                printButton.innerHTML = '<i class="fas fa-print me-1"></i>Print';
                // Update the onclick to show SweetAlert instead of direct print
                printButton.onclick = function() {
                    showPrintOptions();
                };
            }
        } else if (status && (status.toLowerCase() === 'prepared' || status.toLowerCase() === 'reviewed')) {
            if (printButton) printButton.style.display = 'none';
        } else if (status && status.toLowerCase() === 'cancelled') {
            if (printButton) printButton.style.display = 'none';
            
            const reasonButton = document.createElement('button');
            reasonButton.type = 'button';
            reasonButton.className = 'btn btn-warning';
            reasonButton.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i>Reason of Cancellation';
            
            reasonButton.onclick = function() {
                showCancellationReason(cancelledBy, reference, cancellationReason, cancelledDate);
            };
            
            if (closeButton) {
                modalFooter.insertBefore(reasonButton, closeButton);
            } else {
                modalFooter.appendChild(reasonButton);
            }
        } else {
            if (printButton) printButton.style.display = 'inline-block';
        }
    }

    // Add this new function after the showCancellationReason function
    function showPrintOptions() {
        Swal.fire({
            title: 'Print Options',
            text: 'Choose your preferred print format:',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-print me-1"></i>Print With Form',
            cancelButtonText: '<i class="fas fa-file-alt me-1"></i>Print Without Form',
            reverseButtons: true,
            confirmButtonColor: '#007bff',
            cancelButtonColor: '#6c757d',
            allowEnterKey: false,
            customClass: {
                popup: 'swal-print-options',
                confirmButton: 'btn-print-with-form',
                cancelButton: 'btn-print-without-form'
            },
            buttonsStyling: false
        }).then((result) => {
            if (result.isConfirmed) {
                // Print With Form
                printModalContentWithForm();
            } else if (result.dismiss === Swal.DismissReason.cancel) {
                // Print Without Form
                printModalContentWithoutForm();
            }
            // If dismissed by ESC or outside click, do nothing
        });
    }

    function printModalContentWithForm() {
        // Get the reference number from the modal
        const reference = document.getElementById('modal-reference')?.textContent || '';
        
        if (!reference || reference === 'N/A') {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'No reference number found for this transaction.',
                confirmButtonText: 'OK'
            });
            return;
        }
        
        // Construct the URL with the reference parameter
        const printUrl = `../../../models/print/pdf/PrintWithFormOld.php?reference=${encodeURIComponent(reference)}`;
        
        // Open the print page in a new window
        const printWindow = window.open(printUrl, '_blank', 'width=800,height=600,scrollbars=yes,resizable=yes');
        
        // Check if the window was blocked by popup blocker
        if (!printWindow || printWindow.closed || typeof printWindow.closed === 'undefined') {
            Swal.fire({
                icon: 'warning',
                title: 'Popup Blocked',
                html: `
                    <p>The print window was blocked by your browser's popup blocker.</p>
                    <p>Please allow popups for this site or use the direct link below:</p>
                    <a href="${printUrl}" target="_blank" class="btn btn-primary mt-2">
                        <i class="fas fa-external-link-alt me-1"></i>Open Print Page
                    </a>
                `,
                showConfirmButton: false,
                showCloseButton: true
            });
        } else {
            // Focus on the new window
            printWindow.focus();
        }
    }

    function printModalContentWithoutForm() {
        Swal.fire({
            title: 'Coming Soon!',
            text: 'This feature for Print Without Form is currently under development.',
            icon: 'info',
            confirmButtonText: 'Got it!',
            confirmButtonColor: '#007bff',
            allowOutsideClick: false,
            allowEnterKey: false,
            allowEscapeKey: false,
            customClass: {
                popup: 'swal2-coming-soon',
                title: 'coming-soon-title',
                content: 'coming-soon-content'
            }
        });
    }
    
    // Show the modal
    try {
        const modal = new bootstrap.Modal(document.getElementById('soaDetailsModal'));
        modal.show();
    } catch (error) {
        console.error('Error showing modal:', error);
        // Fallback: try jQuery if Bootstrap 5 fails
        try {
            $('#soaDetailsModal').modal('show');
        } catch (jqueryError) {
            console.error('jQuery modal also failed:', jqueryError);
            alert('Unable to open modal. Please refresh the page and try again.');
        }
    }
}

function showCancellationReason(cancelledBy, reference, cancellationReason, cancelledDate) {
    let formattedCancelledDate = 'N/A';
    if (cancelledDate && cancelledDate !== 'N/A' && cancelledDate.trim() !== '') {
        try {
            const date = new Date(cancelledDate);
            if (!isNaN(date.getTime())) {
                formattedCancelledDate = date.toLocaleDateString('en-US', {
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric'
                });
            }
        } catch (e) {
            formattedCancelledDate = cancelledDate;
        }
    }

    Swal.fire({
        title: 'Cancellation Information',
        html: `
            <div class="text-start">
                <p><strong>Control Number:</strong> ${reference || 'N/A'}</p>
                <p><strong>Cancelled By:</strong> ${cancelledBy || 'N/A'}</p>
                <p><strong>Cancelled Date:</strong> ${formattedCancelledDate}</p>
                <hr>
                <p><strong>Reason for Cancellation:</strong></p>
                <div class="alert alert-danger">
                    <i class="fas fa-times-circle me-2"></i>
                    ${cancellationReason || 'No reason provided'}
                </div>
            </div>
        `,
        icon: 'warning',
        confirmButtonText: 'Close',
        confirmButtonColor: '#6c757d',
        allowOutsideClick: false,
        allowEnterKey: false,
        allowEscapeKey: false,
        customClass: {
            popup: 'swal-wide'
        }
    });
}

// Enhanced Pagination functionality with date filtering
class TablePagination {
    constructor(tableId, recordsPerPageId, paginationControlsId) {
        this.table = document.getElementById(tableId);
        this.tbody = this.table.querySelector('tbody');
        this.recordsPerPageSelect = document.getElementById(recordsPerPageId);
        this.paginationControls = document.getElementById(paginationControlsId);
        this.currentPage = 1;
        this.recordsPerPage = 25;
        this.allRows = [];
        this.filteredRows = [];
        
        this.init();
    }
    
    init() {
        // Get all table rows (excluding "No data" row and any script tags)
        this.allRows = Array.from(this.tbody.querySelectorAll('tr')).filter(row => {
            // Exclude rows with colspan (no data messages) and ensure it's a data row
            const hasColspan = row.querySelector('td[colspan]');
            const hasData = row.cells && row.cells.length > 1;
            return !hasColspan && hasData;
        });
        
        this.filteredRows = [...this.allRows];
        
        // Set up event listeners
        this.recordsPerPageSelect.addEventListener('change', () => {
            this.recordsPerPage = this.recordsPerPageSelect.value === 'all' ? 
                this.filteredRows.length : parseInt(this.recordsPerPageSelect.value);
            this.currentPage = 1;
            this.updateDisplay();
        });
        
        // Initial display
        this.updateDisplay();
    }
    
    updateDisplay() {
        // Remove any existing no-data row first
        const existingNoDataRow = this.tbody.querySelector('.no-data-row');
        if (existingNoDataRow) {
            existingNoDataRow.remove();
        }
        
        if (this.filteredRows.length === 0) {
            this.showNoDataMessage();
            return;
        }
        
        const totalRecords = this.filteredRows.length;
        const totalPages = this.recordsPerPage === totalRecords || this.recordsPerPage >= totalRecords ? 1 : 
            Math.ceil(totalRecords / this.recordsPerPage);
        
        // Ensure current page is valid
        if (this.currentPage > totalPages && totalPages > 0) {
            this.currentPage = totalPages;
        } else if (this.currentPage < 1) {
            this.currentPage = 1;
        }
        
        const startIndex = (this.currentPage - 1) * this.recordsPerPage;
        const endIndex = this.recordsPerPage >= totalRecords ? totalRecords : 
            Math.min(startIndex + this.recordsPerPage, totalRecords);
        
        // Hide all rows first
        this.allRows.forEach(row => {
            row.style.display = 'none';
        });
        
        // Show only current page rows
        for (let i = startIndex; i < endIndex; i++) {
            if (this.filteredRows[i]) {
                this.filteredRows[i].style.display = '';
            }
        }
        
        // Update pagination info
        this.updatePaginationInfo(startIndex + 1, endIndex, totalRecords);
        
        // Update pagination controls
        this.updatePaginationControls(totalPages);
        
        // Update total amount for filtered results
        this.updateTotalAmount();
    }
    
    updateTotalAmount() {
        let total = 0;
        this.filteredRows.forEach(row => {
            const netAmountCell = row.cells[13]; // Net Amount Due column (index 13)
            if (netAmountCell) {
                // Get the text content and remove currency symbols and commas
                const amountText = netAmountCell.textContent.replace(/[₱,\s]/g, '');
                const amount = parseFloat(amountText);
                if (!isNaN(amount)) {
                    total += amount;
                }
            }
        });
        
        // Format and display the total
        const totalAmountElement = document.getElementById('total-amount');
        if (totalAmountElement) {
            totalAmountElement.textContent = total.toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }
    }
    
    updatePaginationInfo(start, end, total) {
        const startElement = document.getElementById('startRecord');
        const endElement = document.getElementById('endRecord');
        const totalElement = document.getElementById('totalRecords');
        
        if (startElement) startElement.textContent = start;
        if (endElement) endElement.textContent = end;
        if (totalElement) totalElement.textContent = total;
    }
    
    updatePaginationControls(totalPages) {
        const prevPage = document.getElementById('prevPage');
        const nextPage = document.getElementById('nextPage');
        
        // Clear existing page numbers
        const existingPageNumbers = this.paginationControls.querySelectorAll('.page-number');
        existingPageNumbers.forEach(el => el.remove());
        
        // Show/hide pagination controls
        if (totalPages <= 1) {
            this.paginationControls.style.display = 'none';
            return;
        } else {
            this.paginationControls.style.display = 'flex';
        }
        
        // Update previous button
        if (this.currentPage <= 1) {
            prevPage.classList.add('disabled');
        } else {
            prevPage.classList.remove('disabled');
        }
        
        // Add page numbers
        const maxVisiblePages = 5;
        let startPage = Math.max(1, this.currentPage - Math.floor(maxVisiblePages / 2));
        let endPage = Math.min(totalPages, startPage + maxVisiblePages - 1);
        
        // Adjust start page if we're near the end
        if (endPage - startPage < maxVisiblePages - 1) {
            startPage = Math.max(1, endPage - maxVisiblePages + 1);
        }
        
        // Add first page and ellipsis if needed
        if (startPage > 1) {
            this.addPageNumber(1);
            if (startPage > 2) {
                this.addEllipsis();
            }
        }
        
        // Add visible page numbers
        for (let i = startPage; i <= endPage; i++) {
            this.addPageNumber(i);
        }
        
        // Add ellipsis and last page if needed
        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                this.addEllipsis();
            }
            this.addPageNumber(totalPages);
        }
        
        // Update next button
        if (this.currentPage >= totalPages) {
            nextPage.classList.add('disabled');
        } else {
            nextPage.classList.remove('disabled');
        }
        
        // Add click events for prev/next
        prevPage.onclick = (e) => {
            e.preventDefault();
            if (this.currentPage > 1) {
                this.currentPage--;
                this.updateDisplay();
            }
        };
        
        nextPage.onclick = (e) => {
            e.preventDefault();
            if (this.currentPage < totalPages) {
                this.currentPage++;
                this.updateDisplay();
            }
        };
    }
    
    addPageNumber(pageNum) {
        const li = document.createElement('li');
        li.className = `page-item page-number ${pageNum === this.currentPage ? 'active' : ''}`;
        
        const a = document.createElement('a');
        a.className = 'page-link';
        a.href = '#';
        a.textContent = pageNum;
        a.onclick = (e) => {
            e.preventDefault();
            this.currentPage = pageNum;
            this.updateDisplay();
        };
        
        li.appendChild(a);
        this.paginationControls.insertBefore(li, document.getElementById('nextPage'));
    }
    
    addEllipsis() {
        const li = document.createElement('li');
        li.className = 'page-item disabled page-number';
        
        const span = document.createElement('span');
        span.className = 'page-link';
        span.textContent = '...';
        
        li.appendChild(span);
        this.paginationControls.insertBefore(li, document.getElementById('nextPage'));
    }
    
    showNoDataMessage() {
        // Hide all rows
        this.allRows.forEach(row => row.style.display = 'none');
        
        // Update pagination info
        this.updatePaginationInfo(0, 0, 0);
        
        // Update total amount
        const totalAmountElement = document.getElementById('total-amount');
        if (totalAmountElement) {
            totalAmountElement.textContent = '0.00';
        }
        
        // Hide pagination controls
        this.paginationControls.style.display = 'none';
        
        // Show "No data" message
        if (!this.tbody.querySelector('.no-data-row')) {
            const noDataRow = document.createElement('tr');
            noDataRow.className = 'no-data-row';
            noDataRow.innerHTML = '<td colspan="18" class="text-center text-muted py-4">No data available matching your filters</td>';
            this.tbody.appendChild(noDataRow);
        }
    }
    
    // Enhanced filter method with date filtering based on Date column
    applyFilters() {
        const startDate = document.getElementById('start_date').value;
        const endDate = document.getElementById('end_date').value;
        const statusFilter = document.getElementById('status_filter').value.toLowerCase();
        const searchTerm = document.getElementById('search_input').value.toLowerCase();
        
        // Remove existing "No data" row
        const existingNoDataRow = this.tbody.querySelector('.no-data-row');
        if (existingNoDataRow) {
            existingNoDataRow.remove();
        }
        
        this.filteredRows = this.allRows.filter(row => {
            const cells = row.cells;
            if (!cells || cells.length === 0) return false;
            
            // Date filtering based on Date column (index 1)
            if (startDate || endDate) {
                const dateCell = cells[1]; // Date column
                
                if (dateCell) {
                    const dateText = dateCell.textContent.trim();
                    
                    // Skip rows with N/A dates
                    if (dateText && dateText !== 'N/A') {
                        const rowDate = this.parseDate(dateText);
                        
                        if (rowDate) {
                            // Create filter dates without time components for accurate comparison
                            const filterStartDate = startDate ? new Date(startDate) : null;
                            const filterEndDate = endDate ? new Date(endDate) : null;
                            
                            // Normalize all dates to midnight for comparison
                            const rowDateNormalized = new Date(rowDate.getFullYear(), rowDate.getMonth(), rowDate.getDate());
                            const filterStartNormalized = filterStartDate ? new Date(filterStartDate.getFullYear(), filterStartDate.getMonth(), filterStartDate.getDate()) : null;
                            const filterEndNormalized = filterEndDate ? new Date(filterEndDate.getFullYear(), filterEndDate.getMonth(), filterEndDate.getDate()) : null;
                            
                            // Check if row date falls within filter range (inclusive)
                            if (filterStartNormalized && filterEndNormalized) {
                                if (rowDateNormalized < filterStartNormalized || rowDateNormalized > filterEndNormalized) {
                                    return false;
                                }
                            } else if (filterStartNormalized) {
                                if (rowDateNormalized < filterStartNormalized) {
                                    return false;
                                }
                            } else if (filterEndNormalized) {
                                if (rowDateNormalized > filterEndNormalized) {
                                    return false;
                                }
                            }
                        }
                    }
                }
            }
            
            // Status filtering (column index 0)
            if (statusFilter) {
                const statusCell = cells[0];
                if (statusCell) {
                    const statusBadge = statusCell.querySelector('.badge') || statusCell;
                    const rowStatus = statusBadge.textContent.toLowerCase().trim();
                    if (!rowStatus.includes(statusFilter)) return false;
                }
            }
            
            // Text search filtering - search across all visible text content
            if (searchTerm) {
                const rowText = Array.from(cells).map(cell => 
                    cell.textContent || ''
                ).join(' ').toLowerCase();
                if (!rowText.includes(searchTerm)) return false;
            }
            
            return true;
        });
        
        this.currentPage = 1; // Reset to first page after filtering
        this.updateDisplay();
    }
    
    // Enhanced parseDate method to handle multiple date formats accurately
    parseDate(dateString) {
        if (!dateString || dateString === 'N/A') {
            return null;
        }
        
        // Clean the date string
        const cleanDateString = dateString.trim();
        
        // Try different date formats
        const formats = [
            // Format: "October 02, 2025" or "October 2, 2025" (full month name)
            {
                regex: /^(\w+)\s+(\d{1,2}),\s+(\d{4})$/,
                parse: (match) => {
                    const monthNames = ['January', 'February', 'March', 'April', 'May', 'June',
                                      'July', 'August', 'September', 'October', 'November', 'December'];
                    const monthIndex = monthNames.indexOf(match[1]);
                    if (monthIndex !== -1) {
                        return new Date(parseInt(match[3]), monthIndex, parseInt(match[2]));
                    }
                    return null;
                }
            },
            // Format: "Oct 02, 2025" or "Oct 2, 2025" (short month name)
            {
                regex: /^(\w{3})\s+(\d{1,2}),\s+(\d{4})$/,
                parse: (match) => {
                    const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
                                      'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                    const monthIndex = monthNames.indexOf(match[1]);
                    if (monthIndex !== -1) {
                        return new Date(parseInt(match[3]), monthIndex, parseInt(match[2]));
                    }
                    return null;
                }
            },
            // Format: "2025-10-02" (ISO format)
            {
                regex: /^(\d{4})-(\d{1,2})-(\d{1,2})$/,
                parse: (match) => {
                    return new Date(parseInt(match[1]), parseInt(match[2]) - 1, parseInt(match[3]));
                }
            },
            // Format: "10/02/2025" or "10/2/2025" (US format)
            {
                regex: /^(\d{1,2})\/(\d{1,2})\/(\d{4})$/,
                parse: (match) => {
                    return new Date(parseInt(match[3]), parseInt(match[1]) - 1, parseInt(match[2]));
                }
            },
            // Format: "02-10-2025" or "2-10-2025" (DD-MM-YYYY)
            {
                regex: /^(\d{1,2})-(\d{1,2})-(\d{4})$/,
                parse: (match) => {
                    return new Date(parseInt(match[3]), parseInt(match[2]) - 1, parseInt(match[1]));
                }
            }
        ];
        
        // Try each format
        for (let format of formats) {
            const match = cleanDateString.match(format.regex);
            if (match) {
                const parsedDate = format.parse(match);
                if (parsedDate && !isNaN(parsedDate.getTime())) {
                    return parsedDate;
                }
            }
        }
        
        // Fallback: try JavaScript's Date constructor
        const fallbackDate = new Date(cleanDateString);
        if (!isNaN(fallbackDate.getTime())) {
            return fallbackDate;
        }
        
        return null;
    }
    
    // Clear all filters
    clearFilters() {
        // Clear all filter inputs
        const startDateInput = document.getElementById('start_date');
        const endDateInput = document.getElementById('end_date');
        const statusFilter = document.getElementById('status_filter');
        const searchInput = document.getElementById('search_input');
        
        if (startDateInput) startDateInput.value = '';
        if (endDateInput) endDateInput.value = '';
        if (statusFilter) statusFilter.value = '';
        if (searchInput) searchInput.value = '';
        
        // Reset filtered rows to all rows
        this.filteredRows = [...this.allRows];
        this.currentPage = 1;
        this.updateDisplay();
    }
}

// Global pagination variable
let pagination;

// Initialize pagination when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    // Initialize pagination with a small delay to ensure DOM is fully ready
    setTimeout(() => {
        pagination = new TablePagination('users-table', 'recordsPerPage', 'paginationControls');
        
        // Search functionality with debounce
        const searchInput = document.getElementById('search_input');
        if (searchInput) {
            let searchTimeout;
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    pagination.applyFilters();
                }, 300);
            });
        }
        
        // Date filter functionality
        const startDateInput = document.getElementById('start_date');
        const endDateInput = document.getElementById('end_date');
        
        if (startDateInput) {
            startDateInput.addEventListener('change', () => {
                pagination.applyFilters();
            });
        }
        
        if (endDateInput) {
            endDateInput.addEventListener('change', () => {
                pagination.applyFilters();
            });
        }
        
        // Status filter functionality
        const statusFilter = document.getElementById('status_filter');
        if (statusFilter) {
            statusFilter.addEventListener('change', () => {
                pagination.applyFilters();
            });
        }
        
        // Clear filters functionality
        const clearButton = document.getElementById('clearFilters');
        if (clearButton) {
            clearButton.addEventListener('click', function(e) {
                e.preventDefault();
                pagination.clearFilters();
            });
        }
        
        // Date validation
        if (startDateInput && endDateInput) {
            startDateInput.addEventListener('change', function() {
                if (endDateInput.value && this.value > endDateInput.value) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Invalid Date Range',
                        text: 'Start date cannot be after end date',
                        confirmButtonText: 'OK'
                    });
                    this.value = '';
                }
            });
            
            endDateInput.addEventListener('change', function() {
                if (startDateInput.value && this.value < startDateInput.value) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Invalid Date Range',
                        text: 'End date cannot be before start date',
                        confirmButtonText: 'OK'
                    });
                    this.value = '';
                }
            });
        }
    }, 100);
});

// Function for printing modal content
function printModalContent() {
    window.print();
}
</script>
</html>