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

    $partner_options = 'SELECT DISTINCT partner_Name FROM mldb.soa_transaction WHERE status = "Reviewed" group by partner_Name';
    $partner_result = mysqli_query($conn, $partner_options);

    $report_data = 'SELECT * FROM mldb.soa_transaction WHERE status = "Reviewed" order by date desc';
    $report_result = mysqli_query($conn, $report_data);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Billing Invoice Approval | <?php if($_SESSION['user_type'] === 'admin' || $_SESSION['user_type'] === 'user') echo ucfirst($_SESSION['user_type']); else echo "Guest";?></title>
    <!-- custom CSS file link  -->
    <link rel="stylesheet" href="../../../assets/css/templates/style.css?v=<?php echo time(); ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <script src="https://kit.fontawesome.com/30b908cc5a.js" crossorigin="anonymous"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="../../../assets/js/sweetalert2.all.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <link rel="icon" href="../../../images/MLW logo.png" type="image/png">
    <style>
        /* Add CSS for active row styling */
        .table-row-active {
            background-color: #f8d7da !important;
            border-left: 4px solid #dc3545 !important;
        }

        .table-row-active td {
            background-color: inherit !important;
        }

        /* Add CSS for disabled row styling */
        .table-row-disabled {
            opacity: 0.5 !important;
            pointer-events: none !important;
            background-color: #f8f9fa !important;
        }

        .table-row-disabled td {
            background-color: inherit !important;
        }

        .table-row-disabled input[type="checkbox"] {
            opacity: 0.3 !important;
            cursor: not-allowed !important;
        }

        /* Style checkbox with danger theme */
        .form-check-input {
            border-color: #dc3545 !important;
        }

        .form-check-input:checked {
            background-color: #dc3545 !important;
            border-color: #dc3545 !important;
        }

        .form-check-input:focus {
            border-color: #dc3545 !important;
            box-shadow: 0 0 0 0.25rem rgba(220, 53, 69, 0.25) !important;
        }

        /* Style checkbox in active row with danger theme */
        .table-row-active input[type="checkbox"] {
            accent-color: #dc3545 !important;
            border-color: #dc3545 !important;
        }

        .table-row-active input[type="checkbox"]:checked {
            background-color: #dc3545 !important;
            border-color: #dc3545 !important;
        }

        .table-row-active input[type="checkbox"]:focus {
            box-shadow: 0 0 0 0.25rem rgba(220, 53, 69, 0.25) !important;
            border-color: #dc3545 !important;
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
        <center><h1>Billing Invoice Approval</h1></center>
        <div class="container-fluid">
            <div class="row">
                <div class="col-md-18">
                    <div class="card">
                        <div class="card-header">
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
                                    <label for="partner_filter" class="form-label small text-muted">Partners:</label>
                                    <select id="partner_filter" name="partner" class="form-select form-select-sm">
                                        <option value="">All Partners</option>
                                        <?php 
                                            if ($partner_result && mysqli_num_rows($partner_result) > 0) {
                                                while ($row = mysqli_fetch_assoc($partner_result)) {
                                                    $partner = htmlspecialchars($row['partner_Name']);
                                                    $selected = (isset($_GET['partner']) && $_GET['partner'] == $partner) ? 'selected' : '';
                                                    echo "<option value='" . $partner . "' " . $selected . ">" . ucfirst($partner) . "</option>";
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
                            <div class="row g-3 align-items-end">
                                <!-- Action Buttons + Total -->
                                <div class="col-md-4 d-flex align-items-center">
                                    <div>
                                        <button type="button" id="approveBtn" class="btn btn-success btn-sm me-2">
                                            <i class="fas fa-check"></i> Approve
                                        </button>
                                        <button type="button" id="cancelBtn" class="btn btn-danger btn-sm">
                                            <i class="fas fa-times"></i> Cancel
                                        </button>
                                    </div>
                                    <div class="ms-3 fw-semibold">Total Amount:&nbsp;<span id="totalAmount"></span></div>
                                </div>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th><input class="form-check-input" type="checkbox"></th>
                                            <th style="min-width: 120px;" class='text-truncate text-center'>Date</th>
                                            <th style="min-width: 150px;" class='text-truncate text-center'>Control Number</th>
                                            <th style="min-width: 200px;" class='text-truncate text-center'>Partner Name</th>
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
                                                $row_counter = 0; // Add a counter for unique IDs
                                                while ($row = mysqli_fetch_assoc($report_result)) {
                                                    $row_counter++; // Increment counter for each row
                                                    
                                                    // Add to total amount (only if it's numeric)
                                                    $total_amount += is_numeric(str_replace(',', '', $row['net_amount_due'])) ? floatval(str_replace(',', '', $row['net_amount_due'])) : 0;
                                                    
                                                    // Format date
                                                    $formatted_date = date('F d, Y', strtotime($row['date']));
                                                    $from_date = !empty($row['from_date']) ? date('F d, Y', strtotime($row['from_date'])) : 'N/A';
                                                    $to_date = !empty($row['to_date']) ? date('F d, Y', strtotime($row['to_date'])) : 'N/A';
                                                    
                                                    // Build the table row
                                                    echo "<tr class='table-row-clickable' ondblclick='showSOADetails(this)' style='cursor: pointer;'>";
                                                    echo "<td class='text-truncate'><input class='form-check-input row-checkbox' type='checkbox' 
                                                        data-date='{$formatted_date}'
                                                        data-reference='" . htmlspecialchars($row['reference_number'] ?? 'N/A') . "'
                                                        data-partner='" . htmlspecialchars($row['partner_Name'] ?? 'N/A') . "'
                                                        data-service-charge='" . str_replace(',', '', $row['service_charge']) . "'
                                                        data-from-date='{$from_date}'
                                                        data-to-date='{$to_date}'
                                                        data-amount='" . str_replace(',', '', $row['amount']) . "'
                                                        data-vat='" . str_replace(',', '', $row['vat_amount']) . "'
                                                        data-net-vat='" . str_replace(',', '', $row['net_of_vat']) . "'
                                                        data-withholding='" . str_replace(',', '', $row['withholding_tax']) . "'
                                                        data-net-amount='" . str_replace(',', '', $row['net_amount_due']) . "'
                                                        data-created-by='" . htmlspecialchars($row['prepared_by'] ?? 'N/A') . "'></td>";
                                                    echo "<td class='text-truncate'>{$formatted_date}</td>";
                                                    echo "<td class='text-start text-truncate'>" . htmlspecialchars($row['reference_number'] ?? 'N/A') . "</td>";
                                                    echo "<td class='text-truncate' style='max-width: 400px;'>" . htmlspecialchars($row['partner_Name'] ?? 'N/A') . "</td>";
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
                                                    echo "</tr>";
                                                }
                                                
                                                // Update total amount display with correct element ID
                                                echo "<script>
                                                    document.addEventListener('DOMContentLoaded', function() {
                                                        const totalElement = document.getElementById('totalAmount');
                                                        if (totalElement) {
                                                            totalElement.textContent = '₱" . number_format($total_amount, 2) . "';
                                                        }
                                                    });
                                                </script>";
                                                
                                            } else {
                                                echo "<tr><td colspan='14' class='text-center text-muted'>No data available</td></tr>";
                                            }
                                        ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Transaction Details Modal -->
        <div class="modal fade" id="transactionModal" tabindex="-1" aria-labelledby="transactionModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="transactionModalLabel">
                            <i class="fas fa-file-invoice"></i> Transaction Details
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Date:</label>
                                    <p class="form-control-plaintext" id="modalDate">-</p>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Control Number:</label>
                                    <p class="form-control-plaintext" id="modalControlNumber">-</p>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Partner Name:</label>
                                    <p class="form-control-plaintext" id="modalPartnerName">-</p>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Service Charge:</label>
                                    <p class="form-control-plaintext" id="modalServiceCharge">-</p>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">From Date:</label>
                                    <p class="form-control-plaintext" id="modalFromDate">-</p>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">To Date:</label>
                                    <p class="form-control-plaintext" id="modalToDate">-</p>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Created By:</label>
                                    <p class="form-control-plaintext" id="modalCreatedBy">-</p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">No. of Transactions:</label>
                                    <p class="form-control-plaintext" id="modalTransactions">-</p>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Amount:</label>
                                    <p class="form-control-plaintext" id="modalAmount">-</p>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">VAT Amount:</label>
                                    <p class="form-control-plaintext" id="modalVatAmount">-</p>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Net of VAT:</label>
                                    <p class="form-control-plaintext" id="modalNetOfVat">-</p>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Withholding Tax:</label>
                                    <p class="form-control-plaintext" id="modalWithholdingTax">-</p>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Net Amount Due:</label>
                                    <p class="form-control-plaintext text-success fw-bold fs-5" id="modalNetAmountDue">-</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times"></i> Close
                        </button>
                        <button type="button" class="btn btn-primary" id="modalActionBtn">
                            <i class="fas fa-check"></i> Confirm Selection
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
<?php include '../../../templates/footer.php'; ?>
<script>
// Move the showTransactionModal function outside of DOMContentLoaded to make it globally accessible
function showTransactionModal(checkbox) {
    // Get the row that contains this checkbox
    const row = checkbox.closest('tr');
    
    // Extract data from checkbox data attributes (more reliable than cell text)
    const date = checkbox.getAttribute('data-date');
    const controlNumber = checkbox.getAttribute('data-reference');
    const partnerName = checkbox.getAttribute('data-partner');
    const serviceCharge = '₱' + parseFloat(checkbox.getAttribute('data-service-charge')).toLocaleString('en-US', {minimumFractionDigits: 2});
    const fromDate = checkbox.getAttribute('data-from-date');
    const toDate = checkbox.getAttribute('data-to-date');
    const amount = '₱' + parseFloat(checkbox.getAttribute('data-amount')).toLocaleString('en-US', {minimumFractionDigits: 2});
    const vatAmount = '₱' + parseFloat(checkbox.getAttribute('data-vat')).toLocaleString('en-US', {minimumFractionDigits: 2});
    const netOfVat = '₱' + parseFloat(checkbox.getAttribute('data-net-vat')).toLocaleString('en-US', {minimumFractionDigits: 2});
    const withholdingTax = '₱' + parseFloat(checkbox.getAttribute('data-withholding')).toLocaleString('en-US', {minimumFractionDigits: 2});
    const netAmountDue = '₱' + parseFloat(checkbox.getAttribute('data-net-amount')).toLocaleString('en-US', {minimumFractionDigits: 2});
    const createdBy = checkbox.getAttribute('data-created-by');
    
    // Get transactions from table cell (7th column, index 7)
    const transactions = row.cells[7].textContent.trim();
    
    // Update modal content
    document.getElementById('modalDate').textContent = date;
    document.getElementById('modalControlNumber').textContent = controlNumber;
    document.getElementById('modalPartnerName').textContent = partnerName;
    document.getElementById('modalServiceCharge').textContent = serviceCharge;
    document.getElementById('modalFromDate').textContent = fromDate;
    document.getElementById('modalToDate').textContent = toDate;
    document.getElementById('modalTransactions').textContent = transactions;
    document.getElementById('modalAmount').textContent = amount;
    document.getElementById('modalVatAmount').textContent = vatAmount;
    document.getElementById('modalNetOfVat').textContent = netOfVat;
    document.getElementById('modalWithholdingTax').textContent = withholdingTax;
    document.getElementById('modalNetAmountDue').textContent = netAmountDue;
    document.getElementById('modalCreatedBy').textContent = createdBy;
    
    // Show the modal
    const modal = new bootstrap.Modal(document.getElementById('transactionModal'));
    modal.show();
}

// Function to update button states based on checkbox selections
function updateButtonStates() {
    const checkedCheckboxes = document.querySelectorAll('tbody input[type="checkbox"]:checked');
    const approveBtn = document.getElementById('approveBtn');
    const cancelBtn = document.getElementById('cancelBtn');
    
    if (checkedCheckboxes.length > 0) {
        // Enable buttons when at least one checkbox is checked
        if (approveBtn) {
            approveBtn.disabled = false;
            approveBtn.classList.remove('btn-success');
            approveBtn.classList.add('btn-success');
        }
        if (cancelBtn) {
            cancelBtn.disabled = false;
            cancelBtn.classList.remove('btn-danger');
            cancelBtn.classList.add('btn-danger');
        }
    } else {
        // Disable buttons when no checkboxes are checked
        if (approveBtn) {
            approveBtn.disabled = true;
            approveBtn.classList.remove('btn-success');
            approveBtn.classList.add('btn-success');
        }
        if (cancelBtn) {
            cancelBtn.disabled = true;
            cancelBtn.classList.remove('btn-danger');
            cancelBtn.classList.add('btn-danger');
        }
    }
}

// Function to clear checkbox and row selection
function clearSelectionAndRow() {
    // Find all checked checkboxes and uncheck them
    const checkedCheckboxes = document.querySelectorAll('tbody input[type="checkbox"]:checked');
    checkedCheckboxes.forEach(checkbox => {
        checkbox.checked = false;
    });
    
    // Clear all row selections and reset all rows to normal state
    const tableRows = document.querySelectorAll('tbody tr.table-row-clickable');
    tableRows.forEach(row => {
        row.classList.remove('table-row-active');
        row.classList.remove('table-row-disabled');
        row.style.pointerEvents = 'auto';
        row.style.opacity = '1';
        
        // Enable all checkboxes
        const checkbox = row.querySelector('input[type="checkbox"]');
        if (checkbox) {
            checkbox.disabled = false;
            checkbox.style.opacity = '1';
            checkbox.style.cursor = 'pointer';
        }
    });
    
    // Also uncheck header checkbox if it exists
    const headerCheckbox = document.querySelector('thead input[type="checkbox"]');
    if (headerCheckbox) {
        headerCheckbox.checked = false;
    }
    
    // Update button states after clearing selections - This will disable the buttons
    updateButtonStates();
}

// Function to parse date from table cell (e.g., "October 18, 2025" to Date object)
function parseTableDate(dateString) {
    return new Date(dateString);
}

// Function to filter table rows
function filterTable() {
    const startDate = document.getElementById('start_date').value;
    const endDate = document.getElementById('end_date').value;
    const selectedPartner = document.getElementById('partner_filter').value.toLowerCase();
    const searchTerm = document.getElementById('search_input').value.toLowerCase();
    
    const tableRows = document.querySelectorAll('tbody tr.table-row-clickable');
    let visibleRowCount = 0;
    let totalAmount = 0;
    
    tableRows.forEach(row => {
        let showRow = true;
        
        // Get row data
        const rowDateCell = row.cells[1].textContent.trim(); // Date column
        const rowPartner = row.cells[3].textContent.trim().toLowerCase(); // Partner Name column
        const rowNetAmount = row.cells[12].textContent.trim(); // Net Amount Due column
        
        // Convert table date to comparison format
        const rowDate = parseTableDate(rowDateCell);
        
        // Date filtering
        if (startDate && endDate) {
            const filterStartDate = new Date(startDate);
            const filterEndDate = new Date(endDate);
            
            if (rowDate < filterStartDate || rowDate > filterEndDate) {
                showRow = false;
            }
        } else if (startDate) {
            const filterStartDate = new Date(startDate);
            if (rowDate < filterStartDate) {
                showRow = false;
            }
        } else if (endDate) {
            const filterEndDate = new Date(endDate);
            if (rowDate > filterEndDate) {
                showRow = false;
            }
        }
        
        // Partner filtering
        if (selectedPartner && !rowPartner.includes(selectedPartner)) {
            showRow = false;
        }
        
        // Search filtering (search in all visible text content)
        if (searchTerm) {
            const rowText = row.textContent.toLowerCase();
            if (!rowText.includes(searchTerm)) {
                showRow = false;
            }
        }
        
        // Show/hide row
        if (showRow) {
            row.style.display = '';
            visibleRowCount++;
            
            // Add to total amount (extract numeric value from formatted currency)
            const numericAmount = parseFloat(rowNetAmount.replace(/[₱,]/g, ''));
            if (!isNaN(numericAmount)) {
                totalAmount += numericAmount;
            }
        } else {
            row.style.display = 'none';
            
            // Clear selection if row is hidden
            row.classList.remove('table-row-active');
            row.classList.remove('table-row-disabled');
            const checkbox = row.querySelector('input[type="checkbox"]');
            if (checkbox) {
                checkbox.checked = false;
                checkbox.disabled = false;
            }
        }
    });
    
    // Update total amount
    const totalElement = document.getElementById('totalAmount');
    if (totalElement) {
        totalElement.textContent = '₱' + totalAmount.toLocaleString('en-US', {minimumFractionDigits: 2});
    }
    
    // Show message if no rows match filter
    let noDataRow = document.querySelector('#no-data-row');
    if (visibleRowCount === 0) {
        if (!noDataRow) {
            const tbody = document.querySelector('tbody');
            noDataRow = document.createElement('tr');
            noDataRow.id = 'no-data-row';
            noDataRow.innerHTML = '<td colspan="14" class="text-center text-muted">No data matches the current filters</td>';
            tbody.appendChild(noDataRow);
        }
        noDataRow.style.display = '';
    } else {
        if (noDataRow) {
            noDataRow.style.display = 'none';
        }
    }
}

// Function to clear all filters
function clearFilters() {
    // Clear form inputs
    document.getElementById('start_date').value = '';
    document.getElementById('end_date').value = '';
    document.getElementById('partner_filter').value = '';
    document.getElementById('search_input').value = '';
    
    // Show all rows
    const tableRows = document.querySelectorAll('tbody tr.table-row-clickable');
    tableRows.forEach(row => {
        row.style.display = '';
    });
    
    // Hide no data message
    const noDataRow = document.querySelector('#no-data-row');
    if (noDataRow) {
        noDataRow.style.display = 'none';
    }
    
    // Clear any selections
    clearSelectionAndRow();
    
    // Recalculate total amount for all visible rows
    let totalAmount = 0;
    tableRows.forEach(row => {
        const rowNetAmount = row.cells[12].textContent.trim();
        const numericAmount = parseFloat(rowNetAmount.replace(/[₱,]/g, ''));
        if (!isNaN(numericAmount)) {
            totalAmount += numericAmount;
        }
    });
    
    // Update total amount
    const totalElement = document.getElementById('totalAmount');
    if (totalElement) {
        totalElement.textContent = '₱' + totalAmount.toLocaleString('en-US', {minimumFractionDigits: 2});
    }
    
    // Show success message
    // Swal.fire({
    //     icon: 'success',
    //     title: 'Filters Cleared',
    //     text: 'All filters have been cleared successfully.',
    //     timer: 1500,
    //     showConfirmButton: false
    // });
}

document.addEventListener('DOMContentLoaded', function() {
    // Initialize button states on page load
    updateButtonStates();
    
    // Add click event listener to all table rows
    const tableRows = document.querySelectorAll('tbody tr.table-row-clickable');
    
    tableRows.forEach(row => {
        row.addEventListener('click', function(e) {
            // Prevent checkbox click from triggering row selection
            if (e.target.type === 'checkbox') {
                return;
            }
            
            // Check if this row is already active
            const isCurrentlyActive = this.classList.contains('table-row-active');
            
            if (isCurrentlyActive) {
                // If clicking on already active row, deselect it and enable all rows
                tableRows.forEach(r => {
                    r.classList.remove('table-row-active');
                    r.classList.remove('table-row-disabled');
                    r.style.pointerEvents = 'auto';
                    r.style.opacity = '1';
                    
                    // Enable all checkboxes and uncheck them
                    const checkbox = r.querySelector('input[type="checkbox"]');
                    if (checkbox) {
                        checkbox.disabled = false;
                        checkbox.checked = false;
                        checkbox.style.opacity = '1';
                        checkbox.style.cursor = 'pointer';
                    }
                });
                
                // Update button states after unchecking all
                updateButtonStates();
            } else {
                // Remove active class from all rows and enable them first
                tableRows.forEach(r => {
                    r.classList.remove('table-row-active');
                    r.classList.remove('table-row-disabled');
                    r.style.pointerEvents = 'auto';
                    r.style.opacity = '1';
                    
                    // Enable checkboxes in all rows and uncheck them
                    const checkbox = r.querySelector('input[type="checkbox"]');
                    if (checkbox) {
                        checkbox.disabled = false;
                        checkbox.checked = false;
                        checkbox.style.opacity = '1';
                        checkbox.style.cursor = 'pointer';
                    }
                });
                
                // Add active class to clicked row
                this.classList.add('table-row-active');
                
                // Disable other rows
                tableRows.forEach(r => {
                    if (r !== this) {
                        r.classList.add('table-row-disabled');
                        r.style.pointerEvents = 'none';
                        r.style.opacity = '0.5';
                        
                        // Disable checkboxes in other rows
                        const checkbox = r.querySelector('input[type="checkbox"]');
                        if (checkbox) {
                            checkbox.disabled = true;
                            checkbox.checked = false;
                            checkbox.style.opacity = '0.3';
                            checkbox.style.cursor = 'not-allowed';
                        }
                    }
                });
                
                // Update button states after unchecking all
                updateButtonStates();
            }
        });
        
        // Handle checkbox click
        const checkbox = row.querySelector('input[type="checkbox"]');
        if (checkbox) {
            checkbox.addEventListener('click', function(e) {
                e.stopPropagation(); // Prevent row click event
                
                // Check if the row is selected (has table-row-active class) before showing modal
                const parentRow = this.closest('tr');
                const isRowSelected = parentRow.classList.contains('table-row-active');
                
                if (this.checked) {
                    if (isRowSelected) {
                        // Only show modal if row is selected
                        showTransactionModal(this);
                        // Update button states after checking
                        updateButtonStates();
                    } else {
                        // Uncheck the checkbox if row is not selected
                        this.checked = false;
                        
                        // Show alert or notification that row must be selected first
                        Swal.fire({
                            icon: 'warning',
                            title: 'Row Not Selected',
                            text: 'Please select a table row first before checking the checkbox.',
                            confirmButtonText: 'OK',
                            allowOutsideClick: false
                        });
                    }
                } else {
                    // If unchecking, hide modal if it's open
                    const modalElement = document.getElementById('transactionModal');
                    const modal = bootstrap.Modal.getInstance(modalElement);
                    if (modal) {
                        modal.hide();
                    }
                    // Update button states after unchecking
                    updateButtonStates();
                }
            });
        }
    });
    
    // Add "Select All" functionality for header checkbox
    const headerCheckbox = document.querySelector('thead input[type="checkbox"]');
    if (headerCheckbox) {
        headerCheckbox.addEventListener('change', function() {
            const allCheckboxes = document.querySelectorAll('tbody input[type="checkbox"]:not(:disabled)');
            allCheckboxes.forEach(checkbox => {
                // Only check visible checkboxes
                const row = checkbox.closest('tr');
                if (row.style.display !== 'none') {
                    checkbox.checked = this.checked;
                }
            });
            
            // Update button states after select all/none
            updateButtonStates();
        });
    }
    
    // Handle modal close events
    const modal = document.getElementById('transactionModal');
    if (modal) {
        // Handle close button click
        const closeButton = modal.querySelector('.btn-secondary[data-bs-dismiss="modal"]');
        if (closeButton) {
            closeButton.addEventListener('click', function() {
                clearSelectionAndRow();
            });
        }
        
        // Handle X button click
        const xButton = modal.querySelector('.btn-close');
        if (xButton) {
            xButton.addEventListener('click', function() {
                clearSelectionAndRow();
            });
        }
        
        // Handle modal hidden event (covers all ways of closing the modal)
        modal.addEventListener('hidden.bs.modal', function() {
            clearSelectionAndRow();
        });
        
        // Handle ESC key press to close modal
        modal.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                clearSelectionAndRow();
            }
        });
    }
    
    // Add filter event listeners
    const startDateInput = document.getElementById('start_date');
    const endDateInput = document.getElementById('end_date');
    const partnerFilter = document.getElementById('partner_filter');
    const searchInput = document.getElementById('search_input');
    const clearFiltersBtn = document.getElementById('clearFilters');
    
    // Date filtering
    if (startDateInput) {
        startDateInput.addEventListener('change', function() {
            // Validate date range
            const startDate = new Date(this.value);
            const endDate = endDateInput.value ? new Date(endDateInput.value) : null;
            
            if (endDate && startDate > endDate) {
                Swal.fire({
                    icon: 'error',
                    title: 'Invalid Date Range',
                    text: 'Start date cannot be after end date.',
                    confirmButtonText: 'OK'
                });
                this.value = '';
                return;
            }
            
            filterTable();
        });
    }
    
    if (endDateInput) {
        endDateInput.addEventListener('change', function() {
            // Validate date range
            const endDate = new Date(this.value);
            const startDate = startDateInput.value ? new Date(startDateInput.value) : null;
            
            if (startDate && endDate < startDate) {
                Swal.fire({
                    icon: 'error',
                    title: 'Invalid Date Range',
                    text: 'End date cannot be before start date.',
                    confirmButtonText: 'OK'
                });
                this.value = '';
                return;
            }
            
            filterTable();
        });
    }
    
    // Partner filtering
    if (partnerFilter) {
        partnerFilter.addEventListener('change', filterTable);
    }
    
    // Search filtering (with debounce to avoid excessive filtering)
    if (searchInput) {
        let searchTimeout;
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(filterTable, 300); // 300ms delay
        });
    }
    
    // Clear filters button
    if (clearFiltersBtn) {
        clearFiltersBtn.addEventListener('click', clearFilters);
    }
});
</script>
</html>