<?php
// Connect to the database
// include '../config/config.php';

require_once __DIR__ . '/../config/config.php';


// Start the session
session_start();

if (isset($_SESSION['user_type'])) {
    $current_user_email = '';
    if ($_SESSION['user_type'] === 'admin' && isset($_SESSION['admin_email'])) {
        $current_user_email = $_SESSION['admin_email'];
    } elseif ($_SESSION['user_type'] === 'user' && isset($_SESSION['user_email'])) {
        $current_user_email = $_SESSION['user_email'];
    }else{
        // Redirect to login page if user_type is not set
        header("Location: ../../../index.php");
        session_abort();
        session_destroy();
        exit();
    }
}else{
        // Redirect to login page if user_type is not set
        header("Location: ../../../index.php");
        session_abort();
        session_destroy();
        exit();
    }

// Handle AJAX request FIRST, before any HTML output
if (isset($_POST['action']) && $_POST['action'] === 'get_transaction_data') {
    // Clear any output that might have been generated
    ob_clean();
    
    // Set proper headers for JSON response
    header('Content-Type: application/json');
    
    $search = isset($_POST['search']) ? trim($_POST['search']) : '';
    $page = isset($_POST['page']) ? (int)$_POST['page'] : 1;
    $rowsPerPage = isset($_POST['rows_per_page']) ? (int)$_POST['rows_per_page'] : 10;
    
    $offset = ($page - 1) * $rowsPerPage;
    
    try {
        // Build WHERE clause
        $whereClause = '';
        $params = [];
        $types = '';
        
        if (!empty($search)) {
            $whereClause = "WHERE reference_no LIKE ?";
            $searchParam = "%$search%";
            $params = [$searchParam];
            $types = 's';
        }
        
        // Count total records
        $countQuery = "SELECT COUNT(*) as total FROM billspayment_transaction $whereClause";
        $countStmt = $conn->prepare($countQuery);
        
        if (!$countStmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        if (!empty($params)) {
            $countStmt->bind_param($types, ...$params);
        }
        
        $countStmt->execute();
        $countResult = $countStmt->get_result();
        $totalRows = $countResult->fetch_assoc()['total'];
        $countStmt->close();
        
        // Get paginated data
        $dataQuery = "SELECT * FROM billspayment_transaction $whereClause ORDER BY id DESC LIMIT ? OFFSET ?";
        $dataParams = $params;
        $dataTypes = $types . 'ii';
        $dataParams[] = $rowsPerPage;
        $dataParams[] = $offset;
        
        $dataStmt = $conn->prepare($dataQuery);
        
        if (!$dataStmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $dataStmt->bind_param($dataTypes, ...$dataParams);
        $dataStmt->execute();
        $dataResult = $dataStmt->get_result();
        
        $data = [];
        while ($row = $dataResult->fetch_assoc()) {
            $data[] = $row;
        }
        $dataStmt->close();
        
        // Calculate totals
        $totalQuery = "SELECT 
                        COALESCE(SUM(amount_paid), 0) as total_principal,
                        COALESCE(SUM(charge_to_partner), 0) as total_charge_partner,
                        COALESCE(SUM(charge_to_customer), 0) as total_charge_customer 
                    FROM billspayment_transaction $whereClause";
        
        $totalStmt = $conn->prepare($totalQuery);
        
        if (!$totalStmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        if (!empty($params)) {
            $totalStmt->bind_param($types, ...$params);
        }
        
        $totalStmt->execute();
        $totalResult = $totalStmt->get_result();
        $totals = $totalResult->fetch_assoc();
        $totalStmt->close();
        
        $response = [
            'success' => true,
            'data' => $data,
            'pagination' => [
                'current_page' => $page,
                'total_pages' => ceil($totalRows / $rowsPerPage),
                'total_rows' => (int)$totalRows,
                'rows_per_page' => $rowsPerPage
            ],
            'totals' => [
                'total_principal' => (float)$totals['total_principal'],
                'total_charge_partner' => (float)$totals['total_charge_partner'],
                'total_charge_customer' => (float)$totals['total_charge_customer']
            ]
        ];
        
        echo json_encode($response);
        exit;
        
    } catch (Exception $e) {
        $errorResponse = [
            'success' => false, 
            'message' => 'Database error: ' . $e->getMessage(),
            'error_details' => [
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]
        ];
        
        echo json_encode($errorResponse);
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | <?php if($_SESSION['user_type'] === 'admin' || $_SESSION['user_type'] === 'user') echo ucfirst($_SESSION['user_type']); else echo "Guest";?></title>
    <!-- custom CSS file link  -->
    <link rel="stylesheet" href="../assets/css/templates/style.css?v=<?php echo time(); ?>">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Select2 CSS -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet">
    <!-- Select2 Bootstrap theme -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2-bootstrap-5-theme/1.3.0/select2-bootstrap-5-theme.min.css" rel="stylesheet">

    <script src="https://kit.fontawesome.com/30b908cc5a.js" crossorigin="anonymous"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    <!-- Bootstrap JS Bundle (includes Popper) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script src="../assets/js/sweetalert2.all.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
    
    <link rel="icon" href="../images/MLW logo.png" type="image/png">
    <style>
        /* Remove border from Principal Amount card */
        /* #modal-amount-paid {
            border: none !important;
        } */

        /* If you want to remove border from the entire card container */
        .modal-body .card {
            border: none !important;
        }

        /* Alternative: Remove border from all cards in the modal */
        .modal-body .card {
            border: none;
            box-shadow: none;
        }

        /* If you want to remove border from specific card only */
        .modal-body .card:first-child {
            border: none;
            box-shadow: none;
        }
    </style>
</head>

<body>
   <div class="main-container">

    <?php include '../templates/header_ui.php'; ?>
    <!-- Show and Hide Side Nav Menu -->
    <?php include '../templates/sidebar.php'; ?>
    <div class="container-fluid">
        <div class="row">
            <div class="col-md-18">
                <div class="card">
                    <div class="card-header">
                        <div class="row g-3 align-items-end justify-content-between">
                            <div class="col-md-3">
                                <label id="searchInstruction" class="h5 text-muted" style="display:none;">INSTRUCTION: <i>To view, double click the row</i></label>
                            </div>
                            
                            <!-- Search Input and Button Group -->
                            <div class="col-md-6">
                                <div class="d-flex justify-content-end align-items-end gap-2">
                                    <div class="flex-grow-1" style="max-width: 300px;">
                                        <label for="search_input" class="form-label small text-muted">Search Reference Number:</label>
                                        <input type="text" 
                                            id="search_input" 
                                            name="search" 
                                            class="form-control" 
                                            placeholder="Search by Reference Number...">
                                    </div>
                                    <div class="flex-shrink-0">
                                        <button type="button" id="searchButton" class="btn btn-danger">
                                            <i class="fas fa-search"></i> Search
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card-body" style="display: none;">
                        <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                            <table id="transactionReportTable" class="table table-bordered table-hover table-striped">
                                <thead class="table-light sticky-top">
                                    <tr>
                                        <th rowspan="2" class='text-truncate text-center align-middle'>CAD Status</th>
                                        <th rowspan="2" class='text-truncate text-center align-middle'>Billing Invoice</th>
                                        <th rowspan="2" class='text-truncate text-center align-middle'>Transaction Status</th>
                                        <th rowspan="2" class='text-truncate text-center align-middle'>Transaction Date</th>
                                        <th rowspan="2" class='text-truncate text-center align-middle'>Cancelled Date</th>
                                        <th rowspan="2" class='text-truncate text-center align-middle'>Reference Number</th>
                                        <th rowspan="2" class='text-truncate text-center align-middle'>Branch ID</th>
                                        <th rowspan="2" class='text-truncate text-center align-middle'>Branch Name</th>
                                        <th rowspan="2" class='text-center align-middle'>Source</th>
                                        <th rowspan="2" class='text-truncate text-center align-middle'>Partner Name</th>
                                        <th colspan="2" class='text-truncate text-center align-middle'>Partner ID</th>
                                        <th rowspan="2" class='text-truncate text-center align-middle'>GL Code</th>
                                        <th rowspan="2" class='text-truncate text-center align-middle'>GL Description</th>
                                        <th rowspan="2" class='text-truncate text-center align-middle'>Principal Amount</th>
                                        <th colspan="2" class='text-truncate text-center align-middle'>Charge to</th>
                                    </tr>
                                    <tr>
                                        <th class='text-center'>KP7</th>
                                        <th class='text-center'>KPX</th>
                                        <th class='text-center'>Partner</th>
                                        <th class='text-center'>Customer</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <!-- Data will be populated via JavaScript -->
                                </tbody>
                                    <tfoot class="sticky-bottom table-dark">
                                        <tr>
                                            <th colspan="14" style="text-align:right">Total : </th>
                                            <th id="totalPrincipalAmount" class="text-end">0.00</th>
                                            <th id="totalChargetoPartner" class="text-end">0.00</th>
                                            <th id="totalChargetoCustomer" class="text-end">0.00</th>
                                        </tr>
                                    </tfoot>
                            </table>
                        </div>
                        
                        <!-- Transaction Details Modal -->
                        <div class="modal fade" id="transactionModal" tabindex="-1" aria-labelledby="transactionModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
                            <div class="modal-dialog modal-xl">
                                <div class="modal-content">
                                    <div class="modal-header bg-danger text-white">
                                        <h5 class="modal-title" id="transactionModalLabel">
                                            <i class="fas fa-receipt"></i> Transaction Details
                                        </h5>
                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="container-fluid">
                                            <div class="row">
                                                <div class="col-md-6 pb-3">
                                                    <h6 class=" border-bottom pb-2">
                                                        <i class="fas fa-info-circle text-danger"></i> Transaction Information
                                                    </h6>
                                                    <table>
                                                        <tbody>
                                                            <tr>
                                                                <td style="width: 180px;">
                                                                    <strong>CAD Status:</strong>
                                                                </td>
                                                                <td>
                                                                    <span id="modal-cad-status" class="text-muted">Posted</span>
                                                                </td>
                                                            </tr>
                                                            <tr>
                                                                <td>
                                                                    <strong>Source:</strong>
                                                                </td>
                                                                <td>
                                                                    <span id="modal-source-file" class="text-muted"></span>
                                                                </td>
                                                            </tr>
                                                            <tr>
                                                                <td>
                                                                    <strong>Transaction Date:</strong>
                                                                </td>
                                                                <td>
                                                                    <span id="modal-datetime" class="text-muted"></span>
                                                                </td>
                                                            </tr>
                                                            <tr>
                                                                <td>
                                                                    <strong>Cancelled Date:</strong>
                                                                </td>
                                                                <td>
                                                                    <span id="modal-cancelled-date" class="text-muted"></span>
                                                                </td>
                                                            </tr>
                                                            <tr>
                                                                <td>
                                                                    <strong>Reference Number:</strong>
                                                                </td>
                                                                <td>
                                                                    <mark>
                                                                        <span id="modal-reference-no" class="text-muted"></span>
                                                                    </mark>
                                                                </td>
                                                            </tr>
                                                            <tr>
                                                                <td>
                                                                    <strong>Control Number:</strong>
                                                                </td>
                                                                <td>
                                                                    <mark>
                                                                        <span id="modal-control-number" class="text-muted"></span>
                                                                    </mark>
                                                                </td>
                                                            </tr>
                                                            <tr>
                                                                <td>
                                                                    <strong>Billing Invoice:</strong>
                                                                </td>
                                                                <td>
                                                                    <span id="modal-billing-invoice" class="text-muted"></span>
                                                                </td>
                                                            </tr>
                                                            <tr>
                                                                <td>
                                                                    <strong>Transaction Status:</strong>
                                                                </td>
                                                                <td>
                                                                    <span id="modal-status" class="text-muted"></span>
                                                                </td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                </div>
                                                <div class="col-md-6 pb-3">
                                                    <h6 class="border-bottom pb-2">
                                                        <i class="fas fa-university text-danger"></i> Branch Information
                                                    </h6>
                                                    <table>
                                                        <tbody>
                                                            <tr>
                                                                <td style="width: 130px;">
                                                                    <strong>Mainzone:</strong>
                                                                </td>
                                                                <td>
                                                                    <span id="modal-mainzone" class="text-muted"></span>
                                                                </td>
                                                            </tr>
                                                            <tr>
                                                                <td>
                                                                    <strong>Zone:</strong>
                                                                </td>
                                                                <td>
                                                                    <span id="modal-zone-code" class="text-muted"></span>
                                                                </td>
                                                            </tr>
                                                            <tr>
                                                                <td>
                                                                    <strong>Region Code:</strong>
                                                                </td>
                                                                <td>
                                                                    <span id="modal-region-code" class="text-muted"></span>
                                                                </td>
                                                            </tr>
                                                            <tr>
                                                                <td>
                                                                    <strong>Region Name:</strong>
                                                                </td>
                                                                <td>
                                                                    <span id="modal-region-name" class="text-muted"></span>
                                                                </td>
                                                            </tr>
                                                            <tr>
                                                                <td>
                                                                    <strong>Branch Name:</strong>
                                                                </td>
                                                                <td>
                                                                    <span id="modal-outlet" class="text-muted"></span>
                                                                </td>
                                                            </tr>
                                                            <tr>
                                                                <td>
                                                                    <strong>Branch ID:</strong>
                                                                </td>
                                                                <td>
                                                                    <span id="modal-branch-id" class="text-muted"></span>
                                                                </td>
                                                            </tr>
                                                            <tr>
                                                                <td>
                                                                    <strong>Branch Code:</strong>
                                                                </td>
                                                                <td>
                                                                    <span id="modal-branch-code" class="text-muted"></span>
                                                                </td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>   
                                            <div class="row">
                                                <div class="col-md-12 pb-3">
                                                    <!-- <h6 class="border-bottom pb-2">
                                                        <i class="fas fa-building text-danger"></i> Partner Information
                                                    </h6> -->
                                                    <table>
                                                        <tbody>
                                                            <tr>
                                                                <td style="width: 180px;">
                                                                    <strong>Partner Name:</strong>
                                                                </td>
                                                                <td>
                                                                    <span id="modal-partner-name" class="text-muted"></span>
                                                                </td>
                                                            </tr>
                                                            <tr>
                                                                <td>
                                                                    <strong>Partner ID (KP7):</strong>
                                                                </td>
                                                                <td>
                                                                    <span id="modal-partner-id" class="text-muted"></span>
                                                                </td>
                                                            </tr>
                                                            <tr>
                                                                <td>
                                                                    <strong>Partner ID (KPX):</strong>
                                                                </td>
                                                                <td>
                                                                    <span id="modal-partner-id-kpx" class="text-muted"></span>
                                                                </td>
                                                            </tr>
                                                            <tr>
                                                                <td>
                                                                    <strong>GL Code:</strong>
                                                                </td>
                                                                <td>
                                                                    <span id="modal-gl-code" class="text-muted"></span>
                                                                </td>
                                                            </tr>
                                                            <tr>
                                                                <td>
                                                                    <strong>GL Description:</strong>
                                                                </td>
                                                                <td>
                                                                    <span id="modal-gl-description" class="text-muted"></span>
                                                                </td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-12 pb-3">
                                                    <!-- <h6 class="border-bottom pb-2">
                                                        <i class="fas fa-credit-card-alt text-danger"></i> Payor Information
                                                    </h6> -->
                                                    <table>
                                                        <tbody>
                                                            <tr>
                                                                <td style="width: 180px;">
                                                                    <strong>Payor Name:</strong>
                                                                </td>
                                                                <td>
                                                                    <span id="modal-payor-name" class="text-muted"></span>
                                                                </td>
                                                            </tr>
                                                            <tr>
                                                                <td>
                                                                    <strong>Account Number:</strong>
                                                                </td>
                                                                <td>
                                                                    <span id="modal-account-number" class="text-muted"></span>
                                                                </td>
                                                            </tr>
                                                            <tr>
                                                                <td>
                                                                    <strong>Account Name:</strong>
                                                                </td>
                                                                <td>
                                                                    <span id="modal-account-name" class="text-muted"></span>
                                                                </td>
                                                            </tr>
                                                            <tr>
                                                                <td>
                                                                    <strong>Address:</strong>
                                                                </td>
                                                                <td>
                                                                    <span id="modal-address" class="text-muted"></span>
                                                                </td>
                                                            </tr>
                                                            <tr>
                                                                <td>
                                                                    <strong>Contact Number:</strong>
                                                                </td>
                                                                <td>
                                                                    <span id="modal-contact-number" class="text-muted"></span>
                                                                </td>
                                                            </tr>
                                                            <tr>
                                                                <td>
                                                                    <strong>Operator:</strong>
                                                                </td>
                                                                <td>
                                                                    <span id="modal-operator" class="text-muted"></span>
                                                                </td>
                                                            </tr>
                                                            <tr>
                                                                <td>
                                                                    <strong>Remote Branch:</strong>
                                                                </td>
                                                                <td>
                                                                    <span id="modal-remote-branch" class="text-muted"></span>
                                                                </td>
                                                            </tr>
                                                            <tr>
                                                                <td>
                                                                    <strong>Remote Operator:</strong>
                                                                </td>
                                                                <td>
                                                                    <span id="modal-remote-operator" class="text-muted"></span>
                                                                </td>
                                                            </tr>
                                                            <tr>
                                                                <td>
                                                                    <strong>Second Approver:</strong>
                                                                </td>
                                                                <td>
                                                                    <span id="modal-second-approver" class="text-muted"></span>
                                                                </td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-12 pb-3">
                                                    <!-- <h6 class="border-bottom pb-2">
                                                        <i class="fas fa-user text-danger"></i> Personnel Information
                                                    </h6> -->
                                                    <table>
                                                        <tbody>
                                                            <tr>
                                                                <td style="width: 180px;">
                                                                    <strong>Uploaded By:</strong>
                                                                </td>
                                                                <td>
                                                                    <span id="modal-uploaded-by" class="text-muted">Test</span>
                                                                </td>
                                                            </tr>
                                                            <tr>
                                                                <td>
                                                                    <strong>Uploaded Date:</strong>
                                                                </td>
                                                                <td>
                                                                    <span id="modal-uploaded-date" class="text-muted">01-01-2026</span>
                                                                </td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-12">
                                                    <!-- <h6 class="border-bottom pb-2">
                                                        <i class="fas fa-money-bill-wave text-danger"></i> Financial Details
                                                    </h6> -->
                                                    <div class="row">
                                                        <div class="col-md-4">
                                                            <div class="card">
                                                                <div class="card-body text-center">
                                                                    <h6 class="card-title">Principal Amount</h6>
                                                                    <h4 id="modal-amount-paid" class="card-text text-danger fw-bold">₱0.00</h4>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-4">
                                                            <div class="card">
                                                                <div class="card-body text-center">
                                                                    <h6 class="card-title">Charge to Partner</h6>
                                                                    <h4 id="modal-charge-partner" class="card-text text-danger fw-bold">₱0.00</h4>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-4">
                                                            <div class="card">
                                                                <div class="card-body text-center">
                                                                    <h6 class="card-title">Charge to Customer</h6>
                                                                    <h4 id="modal-charge-customer" class="card-text text-danger fw-bold">₱0.00</h4>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <!-- <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                            <i class="fas fa-times"></i> Close
                                        </button>
                                    </div> -->
                                </div>
                            </div>
                        </div>
                        
                        <!-- Pagination Controls -->
                        <div class="d-flex justify-content-between align-items-center mt-3">
                            <div class="d-flex align-items-center">
                                <span class="me-2">Show:</span>
                                <select id="rowsPerPage" class="form-select form-select-sm" style="width: auto;">
                                    <option value="5">5</option>
                                    <option value="10" selected>10</option>
                                    <option value="25">25</option>
                                    <option value="50">50</option>
                                    <option value="100">100</option>
                                </select>
                                <span class="ms-2">entries</span>
                            </div>
                            
                            <div id="pagination-info" class="text-muted">
                                Showing 0 to 0 of 0 entries
                            </div>
                            
                            <nav aria-label="Table pagination">
                                <ul id="pagination" class="pagination pagination-sm mb-0">
                                    <!-- Pagination will be generated by JavaScript -->
                                </ul>
                            </nav>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
<?php include '../templates/footer.php'; ?>
<script>
$(document).ready(function() {
    let currentPage = 1;
    let rowsPerPage = 10;
    
    // Search button click handler
    $('#searchButton').on('click', function() {
        const searchValue = $('#search_input').val().trim();
        
        if (searchValue === '') {
            Swal.fire({
                icon: 'warning',
                title: 'Search Required',
                text: 'Please enter a reference number to search.',
                confirmButtonText: 'OK'
            });
            return;
        }
        
        currentPage = 1; // Reset to first page when searching
        loadTransactionData();
    });
    
    // Enter key handler for search input
    $('#search_input').on('keypress', function(e) {
        if (e.which === 13) { // Enter key
            const searchValue = $(this).val().trim();
            
            if (searchValue === '') {
                Swal.fire({
                    icon: 'warning',
                    title: 'Search Required',
                    text: 'Please enter a reference number to search.',
                    confirmButtonText: 'OK'
                });
                return;
            }
            
            currentPage = 1;
            loadTransactionData();
        }
    });
    
    // Clear search and hide table when input is cleared
    $('#search_input').on('input', function() {
        const searchValue = $(this).val().trim();
        if (searchValue === '') {
            $('.card-body').hide();
            $('#transactionReportTable tbody').empty();
            updateTotals({ total_principal: 0, total_charge_partner: 0, total_charge_customer: 0 });
            $('#searchInstruction').hide();
        }
    });
    
    // Rows per page change handler
    $('#rowsPerPage').on('change', function() {
        rowsPerPage = parseInt($(this).val());
        currentPage = 1;
        loadTransactionData();
    });
    
    // Function to load transaction data based on search
    function loadTransactionData() {
        const searchValue = $('#search_input').val().trim();
        
        // Show the table container
        $('.card-body').show();
        
        // Show loading state
        $('#transactionReportTable tbody').html('<tr><td colspan="17" class="text-center"><i class="fas fa-spinner fa-spin"></i> Searching...</td></tr>');
        
        $.ajax({
            url: 'home.php',
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'get_transaction_data',
                search: searchValue,
                page: currentPage,
                rows_per_page: rowsPerPage
            },
            success: function(response) {
                
                if (response && response.success) {
                    if (response.data && response.data.length > 0) {
                        populateTable(response.data);
                        updatePagination(response.pagination);
                        updateTotals(response.totals);
                        
                        // Show success message
                        showSearchResults(response.pagination.total_rows, searchValue);
                        $('#searchInstruction').show();
                    } else {
                        // No results found
                        $('#transactionReportTable tbody').html(
                            '<tr><td colspan="17" class="text-center text-muted">' +
                            '<i class="fas fa-search"></i><br>' +
                            'No transactions found for reference number: <strong>' + searchValue + '</strong>' +
                            '</td></tr>'
                        );
                        updatePagination({ current_page: 1, total_pages: 0, total_rows: 0, rows_per_page: rowsPerPage });
                        updateTotals({ total_principal: 0, total_charge_partner: 0, total_charge_customer: 0 });
                        $('#searchInstruction').hide();
                        
                        // Show no results message
                        Swal.fire({
                            icon: 'info',
                            title: 'No Results Found',
                            text: 'No transactions found for reference number: ' + searchValue,
                            confirmButtonText: 'OK'
                        });
                    }
                } else {
                    $('#transactionReportTable tbody').html(
                        '<tr><td colspan="17" class="text-center text-danger">' +
                        '<i class="fas fa-exclamation-triangle"></i> Error: ' + (response.message || 'Unknown error') +
                        '</td></tr>'
                    );
                    
                    Swal.fire({
                        icon: 'error',
                        title: 'Search Error',
                        text: response.message || 'An error occurred while searching.',
                        confirmButtonText: 'OK'
                    });
                }
            },
            error: function(xhr, status, error) {
                
                $('#transactionReportTable tbody').html(
                    '<tr><td colspan="17" class="text-center text-danger">' +
                    '<i class="fas fa-exclamation-triangle"></i> Error loading data. Check console for details.' +
                    '</td></tr>'
                );
                
                Swal.fire({
                    icon: 'error',
                    title: 'Connection Error',
                    text: 'Failed to connect to the server. Please check the console for details.',
                    confirmButtonText: 'OK'
                });
            }
        });
    }
    
    // Function to show search results notification
    function showSearchResults(totalRows, searchValue) {
        const Toast = Swal.mixin({
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 3000,
            timerProgressBar: true
        });
        
        Toast.fire({
            icon: 'success',
            title: `Found ${totalRows} result(s) for "${searchValue}"`
        });
    }
    
    // Function to populate table with data
    function populateTable(data) {
        const tbody = $('#transactionReportTable tbody');
        tbody.empty();
        
        if (data.length === 0) {
            tbody.html('<tr><td colspan="17" class="text-center text-muted">No records found</td></tr>');
            return;
        }
        
        data.forEach(function(row, index) {
            // Highlight matching reference numbers
            const searchValue = $('#search_input').val().trim().toLowerCase();
            const refNumber = row.reference_no || '';
            const highlightedRef = refNumber.toLowerCase().includes(searchValue) ? 
                refNumber.replace(new RegExp(searchValue, 'gi'), '<mark>$&</mark>') : refNumber;
            
            const tr = $('<tr>').append(
                $('<td>').html(getCadStatusBadge(row.post_transaction || '-')),
                $('<td>').text(row.billing_invoice || '-'),
                $('<td>').html(getStatusBadge(row.status || '')),
                $('<td>').text(formatDate(row.datetime) || '-'),
                $('<td>').text(formatDate(row.cancellation_date) || '-'),
                $('<td>').html(highlightedRef),
                $('<td>').text(row.branch_id || '-'),
                $('<td>').text(row.outlet || '-'),
                $('<td>').text(row.source_file || '-'),
                $('<td class="text-truncate">').text(row.partner_name || '-'),
                $('<td>').text(row.partner_id || '-'),
                $('<td>').text(row.partner_id_kpx || '-'),
                $('<td>').text(row.mpm_gl_code || '-'),
                $('<td>').text(row.mpm_gl_description || '-'),
                $('<td>').addClass('text-end').text(formatCurrency(row.amount_paid)),
                $('<td>').addClass('text-end').text(formatCurrency(row.charge_to_partner)),
                $('<td>').addClass('text-end').text(formatCurrency(row.charge_to_customer))
            );
            
            // Add hover effect
            tr.hover(
                function() { $(this).addClass('table-active'); },
                function() { $(this).removeClass('table-active'); }
            );
            
            // Row click handler to open modal
            tr.on('click', function() {
                openTransactionModal(row);
            });
            
            tbody.append(tr);
        });
        // Show instruction now that data has been rendered
        $('#searchInstruction').show();
    }
    
    // Function to get CAD Status badge
    function getCadStatusBadge(cadStatus) {
        let displayStatus = '';
        let badgeClass = '';
        
        if (cadStatus === null || cadStatus === '' || cadStatus === undefined) {
            displayStatus = 'Unknown';
            badgeClass = 'badge bg-secondary';
        } else if (cadStatus.toLowerCase() === 'unposted') {
            displayStatus = 'Unposted';
            badgeClass = 'badge bg-warning text-dark';
        } else if (cadStatus.toLowerCase() === 'posted') {
            displayStatus = 'Posted';
            badgeClass = 'badge bg-success text-white';
        } else {
            // Handle other CAD statuses
            displayStatus = cadStatus;
            // Default to info for unknown CAD statuses
            badgeClass = 'badge bg-info text-white';
        }
        
        return `<span class="${badgeClass}">${displayStatus}</span>`;
    }
    
    // Function to get Transaction Status badge (existing function with enhancements)
    function getStatusBadge(status) {
        // Convert status based on rules
        let displayStatus = '';
        let badgeClass = '';
        
        if (status === '*') {
            displayStatus = 'Cancelled';
            badgeClass = 'badge bg-danger text-white';
        } else if (status === null || status === '' || status === undefined) {
            displayStatus = 'Active';
            badgeClass = 'badge bg-success text-white';
        } else {
            // Handle other existing statuses
            displayStatus = status.toString();
            const statusClasses = {
                'SUCCESS': 'badge bg-success text-white',
                'PENDING': 'badge bg-warning text-dark',
                'FAILED': 'badge bg-danger text-white',
                'CANCELLED': 'badge bg-danger text-white',
                'ACTIVE': 'badge bg-success text-white',
                'COMPLETED': 'badge bg-info text-white',
                'PROCESSING': 'badge bg-primary text-white'
            };
            badgeClass = statusClasses[status.toUpperCase()] || 'badge bg-secondary text-white';
        }
        
        return `<span class="${badgeClass}">${displayStatus}</span>`;
    }

    // Update the no results and error messages to use correct colspan
    // Function to load transaction data based on search
    function loadTransactionData() {
        const searchValue = $('#search_input').val().trim();
        
        // Show the table container
        $('.card-body').show();
        
        // Show loading state
        $('#transactionReportTable tbody').html('<tr><td colspan="17" class="text-center"><i class="fas fa-spinner fa-spin"></i> Searching...</td></tr>');
        
        $.ajax({
            url: 'home.php',
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'get_transaction_data',
                search: searchValue,
                page: currentPage,
                rows_per_page: rowsPerPage
            },
            success: function(response) {
                
                if (response && response.success) {
                    if (response.data && response.data.length > 0) {
                        populateTable(response.data);
                        updatePagination(response.pagination);
                        updateTotals(response.totals);
                        
                        // Show success message
                        showSearchResults(response.pagination.total_rows, searchValue);
                    } else {
                        // No results found
                        $('#transactionReportTable tbody').html(
                            '<tr><td colspan="17" class="text-center text-muted">' +
                            '<i class="fas fa-search"></i><br>' +
                            'No transactions found for reference number: <strong>' + searchValue + '</strong>' +
                            '</td></tr>'
                        );
                        updatePagination({ current_page: 1, total_pages: 0, total_rows: 0, rows_per_page: rowsPerPage });
                        updateTotals({ total_principal: 0, total_charge_partner: 0, total_charge_customer: 0 });
                        
                        // Show no results message
                        Swal.fire({
                            icon: 'info',
                            title: 'No Results Found',
                            text: 'No transactions found for reference number: ' + searchValue,
                            confirmButtonText: 'OK'
                        });
                    }
                } else {
                    $('#transactionReportTable tbody').html(
                        '<tr><td colspan="17" class="text-center text-danger">' +
                        '<i class="fas fa-exclamation-triangle"></i> Error: ' + (response.message || 'Unknown error') +
                        '</td></tr>'
                    );
                    
                    Swal.fire({
                        icon: 'error',
                        title: 'Search Error',
                        text: response.message || 'An error occurred while searching.',
                        confirmButtonText: 'OK'
                    });
                }
            },
            error: function(xhr, status, error) {
                
                $('#transactionReportTable tbody').html(
                    '<tr><td colspan="17" class="text-center text-danger">' +
                    '<i class="fas fa-exclamation-triangle"></i> Error loading data. Check console for details.' +
                    '</td></tr>'
                );
                
                Swal.fire({
                    icon: 'error',
                    title: 'Connection Error',
                    text: 'Failed to connect to the server. Please check the console for details.',
                    confirmButtonText: 'OK'
                });
            }
        });
    }
    
    // Function to update pagination
    function updatePagination(pagination) {
        const paginationContainer = $('#pagination');
        const paginationInfo = $('#pagination-info');
        
        paginationContainer.empty();
        
        // Update info
        const startEntry = pagination.total_rows > 0 ? ((pagination.current_page - 1) * pagination.rows_per_page) + 1 : 0;
        const endEntry = Math.min(pagination.current_page * pagination.rows_per_page, pagination.total_rows);
        paginationInfo.text(`Showing ${startEntry} to ${endEntry} of ${pagination.total_rows} entries`);
        
        if (pagination.total_pages <= 1) return;
        
        // Previous button
        const prevDisabled = pagination.current_page === 1 ? 'disabled' : '';
        paginationContainer.append(`
            <li class="page-item ${prevDisabled}">
                <a class="page-link" href="#" data-page="${pagination.current_page - 1}">&laquo; Previous</a>
            </li>
        `);
        
        // Page numbers
        const startPage = Math.max(1, pagination.current_page - 2);
        const endPage = Math.min(pagination.total_pages, pagination.current_page + 2);
        
        if (startPage > 1) {
            paginationContainer.append('<li class="page-item"><a class="page-link" href="#" data-page="1">1</a></li>');
            if (startPage > 2) {
                paginationContainer.append('<li class="page-item disabled"><span class="page-link">...</span></li>');
            }
        }
        
        for (let i = startPage; i <= endPage; i++) {
            const active = i === pagination.current_page ? 'active' : '';
            paginationContainer.append(`
                <li class="page-item ${active}">
                    <a class="page-link" href="#" data-page="${i}">${i}</a>
                </li>
            `);
        }
        
        if (endPage < pagination.total_pages) {
            if (endPage < pagination.total_pages - 1) {
                paginationContainer.append('<li class="page-item disabled"><span class="page-link">...</span></li>');
            }
            paginationContainer.append(`<li class="page-item"><a class="page-link" href="#" data-page="${pagination.total_pages}">${pagination.total_pages}</a></li>`);
        }
        
        // Next button
        const nextDisabled = pagination.current_page === pagination.total_pages ? 'disabled' : '';
        paginationContainer.append(`
            <li class="page-item ${nextDisabled}">
                <a class="page-link" href="#" data-page="${pagination.current_page + 1}">Next &raquo;</a>
            </li>
        `);
    }
    
    // Pagination click handler
    $(document).on('click', '#pagination .page-link', function(e) {
        e.preventDefault();
        const page = parseInt($(this).data('page'));
        if (!isNaN(page) && page !== currentPage) {
            currentPage = page;
            loadTransactionData();
        }
    });
    
    // Function to update totals
    function updateTotals(totals) {
        $('#totalPrincipalAmount').text(formatCurrency(totals.total_principal || 0));
        $('#totalChargetoPartner').text(formatCurrency(totals.total_charge_partner || 0));
        $('#totalChargetoCustomer').text(formatCurrency(totals.total_charge_customer || 0));
    }
    
    // Helper function to format currency
    function formatCurrency(amount) {
        if (amount === null || amount === undefined || amount === '') return '0.00';
        return parseFloat(amount).toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }
    
    // Helper function to format date in (F d, Y) format
    function formatDate(dateString) {
        if (!dateString) return '';
        const date = new Date(dateString);
        if (isNaN(date.getTime())) return dateString;
        
        // Format as "F d, Y" (e.g., "January 21, 2025")
        return date.toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'long',  // Full month name (January, February, etc.)
            day: 'numeric'  // Day without leading zero
        });
    }
    
    // Function to open transaction modal and populate data
    function openTransactionModal(transaction) {
        // Set modal content
        $('#modal-reference-no').text(transaction.reference_no || '-');
        $('#modal-status').html(getStatusBadge(transaction.status || ''));
        $('#modal-cad-status').html(getCadStatusBadge(transaction.post_transaction || '-'));
        $('#modal-datetime').text(formatDate(transaction.datetime) || '-');
        $('#modal-cancelled-date').text(formatDate(transaction.cancellation_date) || '-');
        $('#modal-billing-invoice').text(transaction.billing_invoice || '-');
        if (transaction.zone_code === 'VIS' || transaction.zone_code === 'MIN') {
            $('#modal-mainzone').text('VISMIN' || '-');
        } else if (transaction.zone_code === 'LZN' || transaction.zone_code === 'NCR') {
            $('#modal-mainzone').text('LNCR' || '-');
        }
        $('#modal-zone-code').text(transaction.zone_code || '-');
        $('#modal-zone-code').text(transaction.zone_code || '-');
        $('#modal-region-code').text(transaction.region_code || '-');
        $('#modal-region-name').text(transaction.region || '-');
        $('#modal-branch-code').text(transaction.branch_code || '-');
        $('#modal-branch-id').text(transaction.branch_id || '-');
        $('#modal-outlet').text(transaction.outlet || '-');
        $('#modal-source-file').text(transaction.source_file || '-');
        $('#modal-partner-name').text(transaction.partner_name || '-');
        $('#modal-partner-id').text(transaction.partner_id || '-');
        $('#modal-partner-id-kpx').text(transaction.partner_id_kpx || '-');
        $('#modal-gl-code').text(transaction.mpm_gl_code || '-');
        $('#modal-gl-description').text(transaction.mpm_gl_description || '-');
        $('#modal-control-number').text(transaction.control_no || '-');
        $('#modal-payor-name').text(transaction.payor || '-');
        $('#modal-account-number').text(transaction.account_no || '-');
        $('#modal-account-name').text(transaction.account_name || '-');
        $('#modal-address').text(transaction.address || '-');
        $('#modal-contact-number').text(transaction.contact_no || '-');
        $('#modal-operator').text(transaction.operator || '-');
        $('#modal-remote-branch').text(transaction.remote_branch || '-');
        $('#modal-remote-operator').text(transaction.remote_operator || '-');
        $('#modal-second-approver').text(transaction['2nd_approver'] || '-');
        $('#modal-uploaded-by').text(transaction.imported_by || '-');
        $('#modal-uploaded-date').text(formatDate(transaction.imported_date) || '-');
        $('#modal-amount-paid').text(formatCurrency(transaction.amount_paid));
        $('#modal-charge-partner').text(formatCurrency(transaction.charge_to_partner));
        $('#modal-charge-customer').text(formatCurrency(transaction.charge_to_customer));
        
        // Show the modal
        $('#transactionModal').modal('show');
    }
});
</script>
</html>