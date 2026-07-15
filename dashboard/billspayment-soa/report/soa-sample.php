<?php
// Connect to the database
require_once __DIR__ . '/../../../config/config.php';
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
        }
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
        <center><h1>Billing Invoice Report</h1></center>
        <div class="container-fluid">
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
                            <div class="mb-3">
                                <label class="h6 text-muted">Total Amount: ₱<span id="total-amount"> 0.00</span></label>
                            </div>
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
                                                    
                                                    echo "<tr class='table-row-clickable' ondblclick='showSOADetails(this)' style='cursor: pointer;' 
                                                        data-status='" . htmlspecialchars($row['status']) . "'
                                                        data-date='{$formatted_date}'
                                                        data-reference='" . htmlspecialchars($row['reference_number'] ?? 'N/A') . "'
                                                        data-partner='" . htmlspecialchars($row['partner_Name'] ?? 'N/A') . "'

                                                        data-customer-tin='" . htmlspecialchars($row['partner_Tin'] ?? 'N/A') . "' // Add this line
                                                        data-address='" . htmlspecialchars($row['address'] ?? 'N/A') . "' // Add this line
                                                        data-business-style='" . htmlspecialchars($row['business_style'] ?? 'N/A') . "' // Add this line

                                                        data-po='" . htmlspecialchars($row['po_number'] ?? 'N/A') . "'
                                                        data-service-charge='" . htmlspecialchars(str_replace(',', '', $row['service_charge'] ?? '0')) . "'
                                                        data-from-date='{$from_date}'
                                                        data-to-date='{$to_date}'

                                                        data-formula='" . htmlspecialchars($row['formula'] ?? 'N/A') . "' // Add this line
                                                        data-formula-details='" . htmlspecialchars($row['formulaInc_Exc'] ?? 'N/A') . "' // Add this line

                                                        data-transactions='" . htmlspecialchars($row['number_of_transactions'] ?? '0') . "'
                                                        data-amount='" . htmlspecialchars($row['amount'] ?? '0') . "'
                                                        data-vat='" . htmlspecialchars(str_replace(',', '', $row['vat_amount'] ?? '0')) . "'
                                                        data-net-vat='" . htmlspecialchars(str_replace(',', '', $row['net_of_vat'] ?? '0')) . "'
                                                        data-withholding='" . htmlspecialchars(str_replace(',', '', $row['withholding_tax'] ?? '0')) . "'
                                                        data-net-amount='" . htmlspecialchars(str_replace(',', '', $row['net_amount_due'] ?? '0')) . "'
                                                        data-created-by='" . htmlspecialchars($row['prepared_by'] ?? 'N/A') . "'
                                                        data-reviewed-by='" . htmlspecialchars($row['reviewed_by'] ?? 'N/A') . "'
                                                        data-approved-by='" . htmlspecialchars($row['noted_by'] ?? 'N/A') . "'
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
                                        <tr class="border-top">
                                            
                                        </tr>
                                        <tr><td></td></tr>
                                        <tr>
                                            <td class="fw-bold">Total Amount Due:</td>
                                            <td class="text-end"></td>
                                        </tr>
                                        <tr>
                                            <td class="fw-bold">Less Withholding Tax:</td>
                                            <td class="text-end"></td>
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
                                <div class="col-md-6">
                                    <table class="table table-sm table-borderless mb-0">
                                        <tr>
                                            <td class="fw-bold">Prepared By:</td>
                                            <td class="fw-bold">Reviewed By:</td>
                                        </tr>
                                        <tr>
                                            <td id="modal-created-by"></td>
                                            <td id="modal-reviewed-by"></td>
                                        </tr>
                                    </table>
                                </div>
                                <div class="col-md-6">
                                    <table class="table table-sm table-borderless mb-0">
                                        <tr>
                                            <td class="fw-bold">Approved By:</td>
                                            <td class="fw-bold">Cancelled By:</td>
                                        </tr>
                                        <tr>
                                            <td id="modal-approved-by"></td>
                                            <td id="modal-cancelled-by"></td>
                                        </tr>
                                        <tr>
                                            
                                            
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" onclick="printModalContent()">
                        <i class="fas fa-print me-1"></i>Print Details
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
    const createdBy = row.getAttribute('data-created-by');
    const reviewedBy = row.getAttribute('data-reviewed-by');
    const approvedBy = row.getAttribute('data-approved-by');
    const cancelledBy = row.getAttribute('data-cancelled-by');
    
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
    
    // Set status badge
    const statusElement = document.getElementById('modal-status');
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
    
    // Populate modal fields
    document.getElementById('modal-date').textContent = date || 'N/A';
    document.getElementById('modal-reference').textContent = reference || 'N/A';
    document.getElementById('modal-partner').textContent = partner || 'N/A';

    document.getElementById('modal-customer-tin').textContent = customertin || 'N/A';
    document.getElementById('modal-address').textContent = address || 'N/A';
    document.getElementById('modal-business-style').textContent = businessstyle || 'N/A';

    document.getElementById('modal-po').textContent = po || 'N/A';
    document.getElementById('modal-service-charge').textContent = formatModalCurrency(serviceCharge);
    document.getElementById('modal-from-date').textContent = fromDate || 'N/A';
    document.getElementById('modal-to-date').textContent = toDate || 'N/A';

    document.getElementById('modal-formula').textContent = formula || 'N/A';
    document.getElementById('modal-formula-details').textContent = formulaDetails || 'N/A';

    document.getElementById('modal-transactions').textContent = formatModalNumber(transactions);
    document.getElementById('modal-amount').textContent = formatModalCurrency(amount);
    document.getElementById('modal-vat').textContent = formatModalCurrency(vat);
    document.getElementById('modal-net-vat').textContent = formatModalCurrency(netVat);
    document.getElementById('modal-withholding').textContent = formatModalCurrency(withholding);
    document.getElementById('modal-net-amount').textContent = formatModalCurrency(netAmount);
    
    document.getElementById('modal-created-by').textContent = createdBy || 'N/A';
    document.getElementById('modal-reviewed-by').textContent = reviewedBy || 'N/A';
    document.getElementById('modal-approved-by').textContent = approvedBy || 'N/A';
    document.getElementById('modal-cancelled-by').textContent = cancelledBy || 'N/A';
    
    // Show the modal
    const modal = new bootstrap.Modal(document.getElementById('soaDetailsModal'));
    modal.show();
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
        // Get all table rows (excluding "No data" row)
        this.allRows = Array.from(this.tbody.querySelectorAll('tr')).filter(row => 
            !row.querySelector('td[colspan]')
        );
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
        if (this.filteredRows.length === 0) {
            this.showNoDataMessage();
            return;
        }
        
        const totalRecords = this.filteredRows.length;
        const totalPages = this.recordsPerPage === totalRecords ? 1 : 
            Math.ceil(totalRecords / this.recordsPerPage);
        
        // Ensure current page is valid
        if (this.currentPage > totalPages) {
            this.currentPage = totalPages || 1;
        }
        
        const startIndex = (this.currentPage - 1) * this.recordsPerPage;
        const endIndex = this.recordsPerPage === totalRecords ? totalRecords : 
            Math.min(startIndex + this.recordsPerPage, totalRecords);
        
        // Hide all rows
        this.allRows.forEach(row => row.style.display = 'none');
        
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
                const amountText = netAmountCell.textContent.replace(/[₱,]/g, '');
                const amount = parseFloat(amountText);
                if (!isNaN(amount)) {
                    total += amount;
                }
            }
        });
        
        document.getElementById('total-amount').textContent = total.toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }
    
    updatePaginationInfo(start, end, total) {
        document.getElementById('startRecord').textContent = start;
        document.getElementById('endRecord').textContent = end;
        document.getElementById('totalRecords').textContent = total;
    }
    
    updatePaginationControls(totalPages) {
        const prevPage = document.getElementById('prevPage');
        const nextPage = document.getElementById('nextPage');
        
        // Clear existing page numbers
        const existingPageNumbers = this.paginationControls.querySelectorAll('.page-number');
        existingPageNumbers.forEach(el => el.remove());
        
        // Show pagination controls if there are pages
        if (totalPages > 0) {
            this.paginationControls.style.display = 'flex';
        } else {
            this.paginationControls.style.display = 'none';
            return;
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
        this.allRows.forEach(row => row.style.display = 'none');
        document.getElementById('startRecord').textContent = '0';
        document.getElementById('endRecord').textContent = '0';
        document.getElementById('totalRecords').textContent = '0';
        document.getElementById('total-amount').textContent = '0.00';
        
        // Hide pagination controls when no data
        this.paginationControls.style.display = 'none';
        
        // Show "No data" message
        if (!this.tbody.querySelector('.no-data-row')) {
            const noDataRow = document.createElement('tr');
            noDataRow.className = 'no-data-row';
            noDataRow.innerHTML = '<td colspan="18" class="text-center text-muted">No data available matching your filters</td>';
            this.tbody.appendChild(noDataRow);
        }
    }
    
    // Enhanced filter method with range filtering for From Date and To Date columns
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
            
            // Date range filtering - check if row's date range overlaps with filter date range
            if (startDate || endDate) {
                const fromDateCell = cells[6]; // From Date column
                const toDateCell = cells[7];   // To Date column
                
                if (fromDateCell && toDateCell) {
                    const fromDateText = fromDateCell.textContent.trim();
                    const toDateText = toDateCell.textContent.trim();
                    
                    // Skip rows with N/A dates
                    if (fromDateText !== 'N/A' && toDateText !== 'N/A') {
                        const rowFromDate = this.parseDate(fromDateText);
                        const rowToDate = this.parseDate(toDateText);
                        
                        if (rowFromDate && rowToDate) {
                            const filterStartDate = startDate ? new Date(startDate) : null;
                            const filterEndDate = endDate ? new Date(endDate) : null;
                            
                            // Check for overlap between filter range and row range
                            if (filterStartDate && filterEndDate) {
                                // Both dates specified - check for overlap
                                // Overlap exists if: filterStart <= rowEnd AND filterEnd >= rowStart
                                if (!(filterStartDate <= rowToDate && filterEndDate >= rowFromDate)) {
                                    return false;
                                }
                            } else if (filterStartDate) {
                                // Only start date - row's to date must be >= filter start date
                                if (rowToDate < filterStartDate) {
                                    return false;
                                }
                            } else if (filterEndDate) {
                                // Only end date - row's from date must be <= filter end date
                                if (rowFromDate > filterEndDate) {
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
                    const rowStatus = statusCell.textContent.toLowerCase();
                    if (!rowStatus.includes(statusFilter)) return false;
                }
            }
            
            // Text search filtering
            if (searchTerm) {
                const rowText = row.textContent.toLowerCase();
                if (!rowText.includes(searchTerm)) return false;
            }
            
            return true;
        });
        
        this.currentPage = 1;
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
            // Format: "Oct 02, 2025" or "Oct 2, 2025"
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
            // Format: "October 02, 2025" (full month name)
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
            // Format: "10/02/2025" or "10/2/2025"
            {
                regex: /^(\d{1,2})\/(\d{1,2})\/(\d{4})$/,
                parse: (match) => {
                    return new Date(parseInt(match[3]), parseInt(match[1]) - 1, parseInt(match[2]));
                }
            },
            // Format: "2025-10-02"
            {
                regex: /^(\d{4})-(\d{1,2})-(\d{1,2})$/,
                parse: (match) => {
                    return new Date(parseInt(match[1]), parseInt(match[2]) - 1, parseInt(match[3]));
                }
            },
            // Format: "02-10-2025" or "2-10-2025"
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
        
        // If all parsing attempts fail, return null
        console.warn('Could not parse date:', cleanDateString);
        return null;
    }
    
    // Clear all filters
    clearFilters() {
        document.getElementById('start_date').value = '';
        document.getElementById('end_date').value = '';
        document.getElementById('status_filter').value = '';
        document.getElementById('search_input').value = '';
        
        this.filteredRows = [...this.allRows];
        this.currentPage = 1;
        this.updateDisplay();
    }
}

// Initialize pagination when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    // Initialize pagination
    const pagination = new TablePagination('users-table', 'recordsPerPage', 'paginationControls');
    
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
        clearButton.addEventListener('click', function() {
            pagination.clearFilters();
        });
    }
    
    // Date validation
    if (startDateInput && endDateInput) {
        startDateInput.addEventListener('change', function() {
            if (endDateInput.value && this.value > endDateInput.value) {
                alert('Start date cannot be after end date');
                this.value = '';
            }
        });
        
        endDateInput.addEventListener('change', function() {
            if (startDateInput.value && this.value < startDateInput.value) {
                alert('End date cannot be before start date');
                this.value = '';
            }
        });
    }
});
</script>
</html>