<?php
// Connect to the database
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../vendor/autoload.php';
// Start the session
session_start();
@include_once __DIR__ . '/../../../templates/middleware.php';
$id = resolve_user_identifier();
if (empty($id)) { header('Location: ../../../login_form.php'); exit; }
if (!function_exists('has_any_permission') || !has_any_permission(['Adjustment Entry Per Branch','Bills Payment'])) { header('Location: ../../home.php'); exit; }
// prefer explicit session values for current user email
$current_user_email = $_SESSION['admin_email'] ?? $_SESSION['user_email'] ?? '';

$display_name = 'GUEST';
$display_email = '';
if (isset($_SESSION['user_type'])) {
    if ($_SESSION['user_type'] === 'admin') {
        $display_name = $_SESSION['admin_name'] ?? 'ADMIN';
        $display_email = $_SESSION['admin_email'] ?? '';
    } elseif ($_SESSION['user_type'] === 'user') {
        $display_name = $_SESSION['user_name'] ?? 'USER';
        $display_email = $_SESSION['user_email'] ?? '';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Adjustment Entry | <?php if($_SESSION['user_type'] === 'admin' || $_SESSION['user_type'] === 'user') echo ucfirst($_SESSION['user_type']); else echo "Guest";?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../../assets/css/templates/style.css?v=<?php echo filemtime('../../../assets/css/templates/style.css'); ?>">
    <link rel="stylesheet" href="https://kit.fontawesome.com/30b908cc5a.js" crossorigin="anonymous" async>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js" defer></script>
    <script src="../../../assets/js/sweetalert2.all.min.js" defer></script>
    <link rel="stylesheet" href="css/adjustment.css?v=<?= time(); ?>">
    
    <link rel="icon" href="../../../images/MLW logo.png" type="image/png">

    <style>
        .conditional-field-group {
            background: #f8f9fa;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin: 10px 0;
            border-radius: 4px;
            display: none;
        }
        .conditional-field-group.active {
            display: block;
            animation: fadeIn 0.3s ease-in;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .field-label {
            font-weight: 600;
            color: #495057;
            margin-bottom: 4px;
        }
        .required-asterisk {
            color: #dc3545;
            margin-left: 2px;
        }
        .adjustment-summary {
            background: #e9ecef;
            padding: 10px 15px;
            border-radius: 4px;
            margin-top: 10px;
        }
    </style>
</head>
<body>
    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Loading...</span>
        </div>
        <p class="mt-2">Loading transactions...</p>
    </div>

    <div class="main-container">
        <?php include '../../../templates/header_ui.php'; ?>
        <?php include '../../../templates/sidebar.php'; ?>
        
        <div class="bp-section-header" role="region" aria-label="Page title">
            <div class="bp-section-title">
                <i class="fa-solid fa-layer-group" aria-hidden="true"></i>
                <div>
                    <h2>Adjustment Entry</h2>
                </div>
            </div>
        </div>

        <div class="container-fluid mt-4">
            <!-- Filter Form -->
            <div class="filter-form border p-4 rounded">
                <form method="GET" id="filterForm">
                    <div class="row g-3">
                        <div class="col-md-5">
                            <label for="partner_id_kpx" class="form-label">Partner Name: <span class="text-danger">*</span></label>
                            <select name="partner_id_kpx" id="partner_id_kpx" class="form-select select2" required>
                                <option value="">Select Partner</option>
                                <?php
                                // Optimized query with caching
                                $partnerQuery = "SELECT DISTINCT partner_id_kpx, partner_name 
                                                 FROM masterdata.partner_masterfile 
                                                 WHERE partner_id_kpx IS NOT NULL 
                                                 ORDER BY partner_name ASC";
                                $partnerResult = mysqli_query($conn, $partnerQuery);
                                while ($partner = mysqli_fetch_assoc($partnerResult)) {
                                    $selected = (isset($_GET['partner_id_kpx']) && $_GET['partner_id_kpx'] == $partner['partner_id_kpx']) ? 'selected' : '';
                                    echo "<option value='" . htmlspecialchars($partner['partner_id_kpx']) . "' $selected>" 
                                        . htmlspecialchars($partner['partner_name']) . " (" . htmlspecialchars($partner['partner_id_kpx']) . ")</option>";
                                }
                                ?>
                            </select>
                        </div>
                        
                        <div class="col-md-5">
                            <label for="reference_no" class="form-label">Reference No <span class="text-danger">*</span></label>
                            <input type="text" name="reference_no" id="reference_no" 
                                   class="form-control reference-disabled" 
                                   value="<?php echo isset($_GET['reference_no']) ? htmlspecialchars($_GET['reference_no']) : ''; ?>"
                                   placeholder="Enter Reference Number"
                                   <?php echo empty($_GET['partner_id_kpx']) ? 'disabled' : ''; ?>  required >
                        </div>
                        
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-danger w-100" id="searchBtn" <?php echo empty($_GET['partner_id_kpx']) ? 'disabled' : ''; ?>>
                                <i class="fas fa-search"></i> Search
                            </button>
                            <a href="adjustment-entry-per-branch.php" class="btn btn-secondary w-100 ms-2"><i class="fa-solid fa-rotate"></i> Clear</a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Results Table -->
            <?php
            // Only query if partner is selected and show loading indicator
            $showResults = !empty($_GET['partner_id_kpx']);
            $result = null;
            
            if ($showResults) {
                $whereClauses = [];
                $params = [];
                $types = "";

                if (!empty($_GET['partner_id_kpx'])) {
                    $whereClauses[] = "partner_id_kpx = ?";
                    $params[] = $_GET['partner_id_kpx'];
                    $types .= "s";
                }

                if (!empty($_GET['reference_no'])) {
                    $whereClauses[] = "reference_no LIKE ?";
                    $params[] = "%" . $_GET['reference_no'] . "%";
                    $types .= "s";
                }

                // Optimized query with all requested fields
                $sql = "SELECT 
                            id,
                            partner_id_kpx,
                            partner_name,
                            settle_unsettle,
                            status,
                            datetime,
                            cancellation_date,
                            reference_no,
                            control_no,
                            amount_paid,
                            charge_to_customer,
                            charge_to_partner,
                            other_details,
                            branch_id,
                            report_date,
                            outlet
                        FROM mldb.billspayment_transaction";
                if (!empty($whereClauses)) {
                    $sql .= " WHERE " . implode(" AND ", $whereClauses);
                }
                $sql .= " ORDER BY datetime DESC LIMIT 10";

                $stmt = mysqli_prepare($conn, $sql);
                if (!empty($params)) {
                    mysqli_stmt_bind_param($stmt, $types, ...$params);
                }
                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);
            }
            ?>

            <div class="table-responsive">
                <?php if ($showResults): ?>
                    <?php if ($result && mysqli_num_rows($result) > 0): ?>
                        <table class="table table-striped table-hover align-middle">
                            <thead>
                                <tr>
                                    <th class="col-partner">Partner Name</th>
                                    <th class="col-ref">Reference No</th>
                                    <th class="col-ref">Control No</th>
                                    <th class="col-amount">Amount Paid</th>
                                    <th class="col-charge">Charge to Customer</th>
                                    <th class="col-charge">Charge to Partner</th>
                                    <th class="col-status">Settle/Unsettle</th>
                                    <th class="col-status">Status</th>
                                    <th class="col-date">Datetime</th>
                                    <th class="col-date">Cancellation Date</th>
                                    <th class="col-details">Other Details</th>
                                    <th>Branch ID</th>
                                    <th>Outlet</th>
                                    <th>Report Date</th>
                                    <th class="col-action">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $hasDisabledTransactions = false;
                                while ($row = mysqli_fetch_assoc($result)):
                                    // Check transaction statuses
                                    $status = $row['status'] ?? '';
                                    $settleStatus = strtolower($row['settle_unsettle'] ?? '');
                                    
                                    // Check if cancelled
                                    $isCancelled = (strpos($status, '*') !== false) || 
                                                   (strtolower($status) === 'cancelled') ||
                                                   ($settleStatus === 'cancelled');
                                    
                                    // Check if settled
                                    $isSettled = ($settleStatus === 'settled');
                                    
                                    // Combined condition - disable if cancelled OR settled
                                    $disableAdjustment = $isCancelled || $isSettled;
                                    
                                    if ($disableAdjustment) {
                                        $hasDisabledTransactions = true;
                                    }
                                    
                                    // Determine row styling
                                    $rowClass = '';
                                    if ($isCancelled) {
                                        $rowClass = 'table-danger';
                                    } elseif ($isSettled) {
                                        $rowClass = 'table-settled';
                                    }
                                ?>
                                    <tr class="<?php echo $rowClass; ?>"
                                        data-id="<?php echo $row['id']; ?>"
                                        data-datetime="<?php echo $row['datetime']; ?>"
                                        data-amount="<?php echo $row['amount_paid']; ?>"
                                        data-ref="<?php echo htmlspecialchars($row['reference_no']); ?>"
                                        title="Double-click to view full details">
                                        <td class="col-partner">
                                            <span class="fw-semibold">
                                                <?php echo htmlspecialchars($row['partner_name'] ?? 'N/A'); ?>
                                                <?php if ($isCancelled): ?>
                                                    <span class="cancelled-indicator" title="This transaction is CANCELLED">*</span>
                                                <?php endif; ?>
                                                <?php if ($isSettled): ?>
                                                    <span class="badge bg-success ms-1" style="font-size: 0.6rem;">SETTLED</span>
                                                <?php endif; ?>
                                            </span>
                                        </td>
                                        <td class="col-ref">
                                            <span class="fw-semibold <?php echo $isCancelled ? 'text-danger' : ($isSettled ? 'text-success' : 'text-primary'); ?>">
                                                <?php echo htmlspecialchars($row['reference_no']); ?>
                                            </span>
                                        </td>
                                        <td class="col-ref">
                                            <span class="fw-semibold">
                                                <?php echo htmlspecialchars($row['control_no'] ?? '—'); ?>
                                            </span>
                                        </td>
                                        <td class="col-amount">
                                            <span class="amount <?php echo ($isCancelled || $isSettled) ? 'amount-negative' : 'amount-positive'; ?>">
                                                ₱<?php echo number_format($row['amount_paid'] ?? 0, 2); ?>
                                            </span>
                                        </td>
                                        <td class="col-charge">
                                            <span class="amount">
                                                ₱<?php echo number_format($row['charge_to_customer'] ?? 0, 2); ?>
                                            </span>
                                        </td>
                                        <td class="col-charge">
                                            <span class="amount">
                                                ₱<?php echo number_format($row['charge_to_partner'] ?? 0, 2); ?>
                                            </span>
                                        </td>
                                        <td class="col-status">
                                            <?php 
                                            $badgeClass = 'status-unsettled';
                                            if ($settleStatus === 'settled') {
                                                $badgeClass = 'status-settled';
                                            } elseif ($settleStatus === 'cancelled' || $isCancelled) {
                                                $badgeClass = 'status-cancelled';
                                            }
                                            ?>
                                            <span class="status-badge <?php echo $badgeClass; ?>">
                                                <?php echo ucfirst($settleStatus); ?>
                                                <?php if ($isCancelled): ?>
                                                    <span class="cancelled-indicator" style="font-size:0.8rem;">*</span>
                                                <?php endif; ?>
                                            </span>
                                        </td>
                                        <td class="col-status">
                                            <span class="status-badge <?php echo $isCancelled ? 'status-cancelled' : (($status == 'Active') ? 'status-settled' : 'status-unsettled'); ?>">
                                                <?php echo htmlspecialchars($status ?: 'Active'); ?>
                                                <?php if ($isCancelled): ?>
                                                    <span class="cancelled-indicator" style="font-size:0.8rem;">*</span>
                                                <?php endif; ?>
                                            </span>
                                        </td>
                                        <td class="col-date">
                                            <?php 
                                            $datetime = $row['datetime'] ?? '';
                                            echo $datetime ? date('Y-m-d H:i', strtotime($datetime)) : '—';
                                            ?>
                                        </td>
                                        <td class="col-date">
                                            <?php 
                                            $cancellationDate = $row['cancellation_date'] ?? '';
                                            echo $cancellationDate ? date('Y-m-d H:i', strtotime($cancellationDate)) : '—';
                                            ?>
                                        </td>
                                        <td class="col-details">
                                            <?php 
                                            $details = $row['other_details'] ?? '';
                                            echo !empty($details) ? htmlspecialchars($details) : '—';
                                            ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($row['branch_id'] ?? '—'); ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($row['outlet'] ?? '—'); ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($row['report_date'] ?? '—'); ?>
                                        </td>
                                        <td class="col-action">
                                            <?php if ($disableAdjustment): ?>
                                                <!-- Disabled button for cancelled OR settled transactions -->
                                                <button class="btn btn-sm btn-secondary settle-btn" disabled 
                                                        title="<?php echo $isSettled ? 'This transaction is SETTLED and cannot be adjusted' : 'This transaction is CANCELLED and cannot be adjusted'; ?>">
                                                    <i class="fas fa-ban"></i> 
                                                    <?php echo $isSettled ? 'Settled' : 'Cancelled'; ?>
                                                </button>
                                            <?php else: ?>
                                                <!-- Active button for non-cancelled and unsettled transactions -->
                                                <button class="btn btn-sm btn-warning settle-btn" 
                                                        data-id="<?php echo $row['id']; ?>"
                                                        data-ref="<?php echo htmlspecialchars($row['reference_no']); ?>"
                                                        data-control="<?php echo htmlspecialchars($row['control_no'] ?? ''); ?>"
                                                        data-amount="<?php echo $row['amount_paid']; ?>"
                                                        data-partner="<?php echo htmlspecialchars($row['partner_name']); ?>"
                                                        data-datetime="<?php echo $row['datetime']; ?>">
                                                    <i class="fas fa-edit"></i> Reason For Adjustment
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                        
                        <!-- Legend and record count -->
                        <div class="d-flex justify-content-between align-items-center flex-wrap mt-2">
                            <div class="text-muted small">
                                <i class="fas fa-info-circle"></i> Showing <?php echo mysqli_num_rows($result); ?> record(s)
                                &nbsp;|&nbsp;
                                <i class="fas fa-mouse-pointer"></i> Double-click row to view full details
                            </div>
                            <?php if ($hasDisabledTransactions): ?>
                                <div class="legend-asterisk">
                                    <i class="fas fa-info-circle"></i> 
                                    <span class="text-success fw-bold">● Settled</span> &nbsp;
                                    <span class="text-danger fw-bold">● Cancelled</span> &nbsp;
                                    <span class="text-muted">Adjustments not allowed for settled or cancelled transactions.</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="no-results">
                            <i class="fas fa-inbox"></i>
                            <h5>No transactions found</h5>
                            <p>No records match your search criteria for the selected partner.</p>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="no-results">
                        <i class="fas fa-search"></i>
                        <h5>Select a Partner to Begin</h5>
                        <p>Please select a partner and reference number to view transactions.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        $(document).ready(function() {
            // Initialize Select2 with performance optimizations
            $('.select2').select2({
                placeholder: "Select Partner",
                allowClear: true,
                width: '100%'
            });

            // Enable/disable reference input and search button
            $('#partner_id_kpx').on('change', function() {
                const hasPartner = $(this).val() !== '';
                const referenceInput = $('#reference_no');
                const searchBtn = $('#searchBtn');
                
                if (hasPartner) {
                    referenceInput.prop('disabled', false).removeClass('reference-disabled');
                    searchBtn.prop('disabled', false);
                } else {
                    referenceInput.prop('disabled', true).addClass('reference-disabled');
                    searchBtn.prop('disabled', true);
                }
            });

            // Show loading on form submit
            $('#filterForm').on('submit', function(e) {
                const partner = $('#partner_id_kpx').val();
                if (!partner) {
                    e.preventDefault();
                    Swal.fire({
                        icon: 'warning',
                        title: 'Partner Required',
                        text: 'Please select a partner before searching.',
                        confirmButtonColor: '#3085d6',
                        timer: 3000
                    });
                    return false;
                }
                
                // Show loading overlay
                $('#loadingOverlay').addClass('active');
            });

            // Hide loading on page load complete
            $(window).on('load', function() {
                $('#loadingOverlay').removeClass('active');
            });

            // Function to show/hide conditional fields based on reason selection
            function toggleConditionalFields(reason) {
                // Hide all conditional groups first
                $('.conditional-field-group').removeClass('active');
                
                // Show the relevant group
                if (reason && reason !== '') {
                    const targetGroup = $('#conditionalFields-' + reason);
                    if (targetGroup.length) {
                        targetGroup.addClass('active');
                    }
                }
            }

            // Settlement action - only for non-cancelled AND non-settled transactions
            $('.settle-btn:not([disabled])').on('click', function(e) {
                e.stopPropagation(); // prevent triggering row dblclick handler
                const id = $(this).data('id');
                const ref = $(this).data('ref');
                const control = $(this).data('control');
                const amount = $(this).data('amount');
                const partner = $(this).data('partner');
                const datetime = $(this).data('datetime');
                
                // Check if already settled - additional client-side check
                const row = $(this).closest('tr');
                const settleStatus = row.find('.col-status:first .status-badge').text().trim().toLowerCase();
                
                if (settleStatus === 'settled') {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Transaction Already Settled',
                        text: 'This transaction has already been settled and cannot be adjusted.',
                        confirmButtonColor: '#3085d6'
                    });
                    return;
                }
                
                // Build the HTML for the modal
                let modalHtml = `
                    <div class="text-start">
                        <div class="adjustment-summary">
                            <div class="row">
                                <div class="col-6"><strong>Reference:</strong> ${ref}</div>
                                <div class="col-6"><strong>Control No:</strong> ${control || '—'}</div>
                                <div class="col-6"><strong>Partner:</strong> ${partner}</div>
                                <div class="col-6"><strong>Amount:</strong> ₱${parseFloat(amount).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</div>
                            </div>
                        </div>
                        <div class="mb-3 mt-3">
                            <label class="form-label fw-bold">Reason for Adjustment: <span class="required-asterisk">*</span></label>
                            <select class="form-select" id="adjustmentReason">
                                <option value="">Select reason</option>
                                <option value="late_posting">Late Posting</option>
                                <option value="incorrect_amount">Incorrect Amount</option>
                                <option value="wrong_biller">Wrong Biller</option>
                                <option value="duplicate_entry">Duplicate Entry</option>
                                <option value="customer_request">Customer Request</option>
                                <option value="system_error">System Error</option>
                                <option value="no_payment">No Payment</option>
                            </select>
                        </div>
                `;

                // Late Posting Fields - Set Posting Date as datetime (readonly)
                const formattedDatetime = datetime ? new Date(datetime).toISOString().split('T')[0] : '';
                
                modalHtml += `
                    <div class="conditional-field-group" id="conditionalFields-late_posting">
                        <h6 class="text-primary"><i class="fas fa-clock"></i> Late Posting Details</h6>
                        <div class="row">
                            <div class="col-md-6 mb-2">
                                <label class="field-label">Original Payment Date <span class="required-asterisk">*</span></label>
                                <input type="date" class="form-control" id="late_original_date">
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="field-label">Posting Date <span class="required-asterisk">*</span></label>
                                <input type="date" class="form-control" id="late_posting_date" value="${formattedDatetime}" readonly style="background-color: #e9ecef;">
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="field-label">Grace Period End Date</label>
                                <input type="date" class="form-control" id="late_grace_period">
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="field-label">Late Fee Reversal Requested</label>
                                <select class="form-control" id="late_fee_reversal">
                                    <option value="no">No</option>
                                    <option value="yes">Yes</option>
                                </select>
                            </div>
                            <div class="col-12 mb-2">
                                <label class="field-label">Reason for Late Posting <span class="required-asterisk">*</span></label>
                                <textarea class="form-control" id="late_justification" rows="2" placeholder="Why did this post late?"></textarea>
                            </div>
                        </div>
                    </div>
                `;

                // Incorrect Amount Fields - Set Amount Currently Applied as amount_paid
                modalHtml += `
                    <div class="conditional-field-group" id="conditionalFields-incorrect_amount">
                        <h6 class="text-primary"><i class="fa-solid fa-peso-sign"></i> Incorrect Amount Details</h6>
                        <div class="row">
                            <div class="col-md-6 mb-2">
                                <label class="field-label">Original Amount Paid <span class="required-asterisk">*</span></label>
                                <input type="number" class="form-control" id="incorrect_original_amount" step="0.01" placeholder="0.00">
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="field-label">Amount Currently Applied <span class="required-asterisk">*</span></label>
                                <input type="number" class="form-control" id="incorrect_current_amount" step="0.01" value="${amount}" readonly style="background-color: #e9ecef;">
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="field-label">Correct Amount <span class="required-asterisk">*</span></label>
                                <input type="number" class="form-control" id="incorrect_correct_amount" step="0.01" placeholder="0.00">
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="field-label">Difference Amount</label>
                                <input type="text" class="form-control" id="incorrect_difference" readonly placeholder="Auto-calculated">
                            </div>
                            <div class="col-12 mb-2">
                                <label class="field-label">Supporting Document</label>
                                <input type="file" class="form-control" id="incorrect_document" accept=".pdf,.jpg,.jpeg,.png">
                                <small class="text-muted">Upload supporting attachment.</small>
                            </div>
                        </div>
                    </div>
                `;

                // Wrong Biller Fields - Get partner names for dropdown
                <?php
                // Fetch distinct partner names for dropdown
                $partnerDropdownQuery = "SELECT DISTINCT partner_name 
                                        FROM masterdata.partner_masterfile 
                                        WHERE partner_name IS NOT NULL 
                                        ORDER BY partner_name ASC";
                $partnerDropdownResult = mysqli_query($conn, $partnerDropdownQuery);
                $partnerOptions = '';
                while ($partnerRow = mysqli_fetch_assoc($partnerDropdownResult)) {
                    $partnerOptions .= "<option value='" . htmlspecialchars($partnerRow['partner_name']) . "'>" . htmlspecialchars($partnerRow['partner_name']) . "</option>";
                }
                ?>
                
                modalHtml += `
                    <div class="conditional-field-group" id="conditionalFields-wrong_biller">
                        <h6 class="text-primary"><i class="fas fa-building"></i> Wrong Biller Details</h6>
                        <div class="row">
                            <div class="col-md-6 mb-2">
                                <label class="field-label">Current Biller <span class="required-asterisk">*</span></label>
                                <select class="form-control" id="wrong_current_biller" disabled style="background-color: #e9ecef;">
                                    <option value="${partner}">${partner}</option>
                                    <?php echo $partnerOptions; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="field-label">Correct Biller <span class="required-asterisk">*</span></label>
                                <select class="form-control" id="wrong_correct_biller">
                                    <option value="">Select correct biller...</option>
                                    <?php echo $partnerOptions; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="field-label">Invoice Number</label>
                                <input type="text" class="form-control" id="wrong_invoice" placeholder="Invoice number if applicable">
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="field-label">Action Required</label>
                                <select class="form-control" id="wrong_action">
                                    <option value="transfer">Transfer funds to correct account</option>
                                    <option value="reverse">Reverse from wrong account first</option>
                                    <option value="both">Both transfer and reverse</option>
                                </select>
                            </div>
                        </div>
                    </div>
                `;

                // Duplicate Entry Fields - Set Original Reference No. as reference_no
                modalHtml += `
                    <div class="conditional-field-group" id="conditionalFields-duplicate_entry">
                        <h6 class="text-primary"><i class="fas fa-copy"></i> Duplicate Entry Details</h6>
                        <div class="row">
                            <div class="col-md-6 mb-2">
                                <label class="field-label">Original Reference No. <span class="required-asterisk">*</span></label>
                                <input type="text" class="form-control" id="dup_original_id" value="${ref}" readonly style="background-color: #e9ecef;">
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="field-label">Duplicate Reference No. <span class="required-asterisk">*</span></label>
                                <input type="text" class="form-control" id="dup_duplicate_id" placeholder="Enter duplicate reference">
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="field-label">Transaction Date <span class="required-asterisk">*</span></label>
                                <input type="date" class="form-control" id="dup_transaction_date">
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="field-label">Amount of Duplicate <span class="required-asterisk">*</span></label>
                                <input type="number" class="form-control" id="dup_amount" step="0.01" placeholder="0.00">
                            </div>
                            <div class="col-12 mb-2">
                                <label class="field-label">Action to Take <span class="required-asterisk">*</span></label>
                                <select class="form-control" id="dup_action">
                                    <option value="void">Void Duplicate</option>
                                    <option value="refund">Refund Duplicate</option>
                                    <option value="credit">Keep as Credit</option>
                                </select>
                            </div>
                        </div>
                    </div>
                `;

                // Customer Request Fields
                modalHtml += `
                    <div class="conditional-field-group" id="conditionalFields-customer_request">
                        <h6 class="text-primary"><i class="fas fa-user"></i> Customer Request Details</h6>
                        <div class="row">
                            <div class="col-md-6 mb-2">
                                <label class="field-label">Customer Authorization Received <span class="required-asterisk">*</span></label>
                                <select class="form-control" id="cust_authorization">
                                    <option value="no">No</option>
                                    <option value="yes">Yes</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="field-label">Authorization Reference</label>
                                <input type="text" class="form-control" id="cust_auth_ref" placeholder="Ticket/email/call log ID">
                            </div>
                            <div class="col-12 mb-2">
                                <label class="field-label">Customer Reason Summary <span class="required-asterisk">*</span></label>
                                <textarea class="form-control" id="cust_reason" rows="2"></textarea>
                            </div>
                            <div class="col-12 mb-2">
                                <label class="field-label">Customer Approval/Consent</label>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="cust_consent">
                                    <label class="form-check-label" for="cust_consent">
                                        I confirm customer has approved this adjustment
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                `;

                // System Error Fields
                modalHtml += `
                    <div class="conditional-field-group" id="conditionalFields-system_error">
                        <h6 class="text-primary"><i class="fas fa-desktop"></i> System Error Details</h6>
                        <div class="row">
                            <div class="col-md-6 mb-2">
                                <label class="field-label">System Name <span class="required-asterisk">*</span></label>
                                <input type="text" class="form-control" id="sys_module">
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="field-label">Error Code/Message</label>
                                <input type="text" class="form-control" id="sys_error_code">
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="field-label">Date/Time of Error <span class="required-asterisk">*</span></label>
                                <input type="datetime-local" class="form-control" id="sys_error_datetime">
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="field-label">IT Ticket Number</label>
                                <input type="text" class="form-control" id="sys_ticket" placeholder="IT ticket reference">
                            </div>
                            <div class="col-12 mb-2">
                                <label class="field-label">Root Cause Summary <span class="required-asterisk">*</span></label>
                                <textarea class="form-control" id="sys_root_cause" rows="2" placeholder="Brief description of the system error"></textarea>
                            </div>
                        </div>
                    </div>
                `;

                // No Payment Fields
                modalHtml += `
                    <div class="conditional-field-group" id="conditionalFields-no_payment">
                        <h6 class="text-primary"><i class="fas fa-times-circle"></i> No Payment Details</h6>
                        <div class="row">
                            <div class="col-md-6 mb-2">
                                <label class="field-label">Payment Record Date <span class="required-asterisk">*</span></label>
                                <input type="date" class="form-control" id="nopay_record_date">
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="field-label">Actual Payment Status <span class="required-asterisk">*</span></label>
                                <select class="form-control" id="nopay_status">
                                    <option value="bank_rejected">Bank Rejected</option>
                                    <option value="check_bounced">Check Bounced</option>
                                    <option value="never_sent">Never Sent</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="field-label">Original Payment Method <span class="required-asterisk">*</span></label>
                                <select class="form-control" id="nopay_method">
                                    <option value="credit_card">Credit Card</option>
                                    <option value="ach">ACH</option>
                                    <option value="check">Check</option>
                                    <option value="cash">Cash</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="field-label">Action to Take <span class="required-asterisk">*</span></label>
                                <select class="form-control" id="nopay_action">
                                    <option value="remove">Remove from Account</option>
                                    <option value="send_invoice">Send New Invoice</option>
                                    <option value="contact_customer">Contact Customer</option>
                                </select>
                            </div>
                            <div class="col-12 mb-2">
                                <label class="field-label">Proof of Non-Payment</label>
                                <input type="file" class="form-control" id="nopay_proof" accept=".pdf,.jpg,.jpeg,.png">
                                <small class="text-muted">Upload bank rejection notice or statement</small>
                            </div>
                        </div>
                    </div>
                `;

                // Common fields for all adjustments - Set Reviewed by as $display_name
                modalHtml += `
                        <div class="mt-3">
                            <div class="row">
                                <div class="col-md-6 mb-2">
                                    <label class="field-label">Adjustment Effective Date <span class="required-asterisk">*</span></label>
                                    <input type="date" class="form-control" id="common_effective_date">
                                </div>
                                <div class="col-md-6 mb-2">
                                    <label class="field-label">Reviewed by:</label>
                                    <input type="text" class="form-control" id="common_approver" value="<?php echo htmlspecialchars($display_name); ?>" readonly style="background-color: #e9ecef;">
                                </div>
                                <div class="col-12 mb-2">
                                    <label class="field-label">Internal Notes</label>
                                    <textarea class="form-control" id="common_internal_notes" rows="2"></textarea>
                                </div>
                                <div class="col-12 mb-2">
                                    <label class="field-label">Comments from Customers</label>
                                    <textarea class="form-control" id="common_customer_comments" rows="2"></textarea>
                                </div>
                                <div class="col-12 mb-2">
                                    <label class="field-label">Attachments (General)</label>
                                    <input type="file" class="form-control" id="common_attachments" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                                    <small class="text-muted">Upload any additional supporting documents</small>
                                </div>
                            </div>
                        </div>
                    </div>
                `;

                Swal.fire({
                    title: 'Reason For Adjustment',
                    html: modalHtml,
                    icon: 'question',
                    confirmButtonText: 'Submit Adjustment',
                    cancelButtonText: 'Cancel',
                    showCancelButton: true,
                    confirmButtonColor: '#ffc107',
                    cancelButtonColor: '#6c757d',
                    width: '800px',
                    preConfirm: () => {
                        const reason = document.getElementById('adjustmentReason').value;
                        if (!reason) {
                            Swal.showValidationMessage('Please select a reason for adjustment');
                            return false;
                        }

                        // Collect common fields
                        const commonData = {
                            effective_date: document.getElementById('common_effective_date')?.value || '',
                            approver: document.getElementById('common_approver')?.value || '',
                            internal_notes: document.getElementById('common_internal_notes')?.value || '',
                            customer_comments: document.getElementById('common_customer_comments')?.value || '',
                            attachments: document.getElementById('common_attachments')?.files[0]?.name || ''
                        };

                        // Collect reason-specific fields
                        let reasonData = {};
                        let allFields = {};

                        // Late Posting
                        if (reason === 'late_posting') {
                            const fields = ['late_original_date', 'late_posting_date', 'late_grace_period', 'late_fee_reversal', 'late_justification'];
                            fields.forEach(f => {
                                const el = document.getElementById(f);
                                if (el) reasonData[f] = el.value;
                            });
                            if (!reasonData.late_original_date || !reasonData.late_posting_date || !reasonData.late_justification) {
                                Swal.showValidationMessage('Please fill in all required fields for Late Posting');
                                return false;
                            }
                        }

                        // Incorrect Amount
                        if (reason === 'incorrect_amount') {
                            const fields = ['incorrect_original_amount', 'incorrect_current_amount', 'incorrect_correct_amount'];
                            fields.forEach(f => {
                                const el = document.getElementById(f);
                                if (el) reasonData[f] = el.value;
                            });
                            if (!reasonData.incorrect_original_amount || !reasonData.incorrect_current_amount || !reasonData.incorrect_correct_amount) {
                                Swal.showValidationMessage('Please fill in all required fields for Incorrect Amount');
                                return false;
                            }
                        }

                        // Wrong Biller
                        if (reason === 'wrong_biller') {
                            const fields = ['wrong_current_biller', 'wrong_correct_biller'];
                            fields.forEach(f => {
                                const el = document.getElementById(f);
                                if (el) reasonData[f] = el.value;
                            });
                            if (!reasonData.wrong_current_biller || !reasonData.wrong_correct_biller) {
                                Swal.showValidationMessage('Please fill in all required fields for Wrong Biller');
                                return false;
                            }
                        }

                        // Duplicate Entry
                        if (reason === 'duplicate_entry') {
                            const fields = ['dup_original_id', 'dup_duplicate_id', 'dup_transaction_date', 'dup_amount', 'dup_action'];
                            fields.forEach(f => {
                                const el = document.getElementById(f);
                                if (el) reasonData[f] = el.value;
                            });
                            if (!reasonData.dup_original_id || !reasonData.dup_duplicate_id || !reasonData.dup_transaction_date || !reasonData.dup_amount || !reasonData.dup_action) {
                                Swal.showValidationMessage('Please fill in all required fields for Duplicate Entry');
                                return false;
                            }
                        }

                        // Customer Request
                        if (reason === 'customer_request') {
                            const fields = ['cust_authorization', 'cust_reason'];
                            fields.forEach(f => {
                                const el = document.getElementById(f);
                                if (el) reasonData[f] = el.value;
                            });
                            if (!reasonData.cust_authorization || !reasonData.cust_reason) {
                                Swal.showValidationMessage('Please fill in all required fields for Customer Request');
                                return false;
                            }
                        }

                        // System Error
                        if (reason === 'system_error') {
                            const fields = ['sys_module', 'sys_error_datetime', 'sys_root_cause'];
                            fields.forEach(f => {
                                const el = document.getElementById(f);
                                if (el) reasonData[f] = el.value;
                            });
                            if (!reasonData.sys_module || !reasonData.sys_error_datetime || !reasonData.sys_root_cause) {
                                Swal.showValidationMessage('Please fill in all required fields for System Error');
                                return false;
                            }
                        }

                        // No Payment
                        if (reason === 'no_payment') {
                            const fields = ['nopay_record_date', 'nopay_status', 'nopay_method', 'nopay_action'];
                            fields.forEach(f => {
                                const el = document.getElementById(f);
                                if (el) reasonData[f] = el.value;
                            });
                            if (!reasonData.nopay_record_date || !reasonData.nopay_status || !reasonData.nopay_method || !reasonData.nopay_action) {
                                Swal.showValidationMessage('Please fill in all required fields for No Payment');
                                return false;
                            }
                        }

                        allFields = {
                            id: id,
                            reference: ref,
                            control_no: control,
                            reason: reason,
                            reason_data: reasonData,
                            common: commonData
                        };

                        return allFields;
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        const data = result.value;
                        // Here you would send the data to your backend
                        console.log('Adjustment submitted:', data);
                        
                        // Display success message with details
                        let detailsHtml = `
                            <div class="text-start">
                                <p><strong>Reference:</strong> ${data.reference}</p>
                                <p><strong>Control No:</strong> ${data.control_no || '—'}</p>
                                <p><strong>Reason:</strong> ${data.reason.replace('_', ' ').toUpperCase()}</p>
                                <hr>
                                <p><strong>Adjustment Effective Date:</strong> ${data.common.effective_date || 'Not set'}</p>
                                <p><strong>Reviewed by:</strong> ${data.common.approver || 'Not specified'}</p>
                                ${data.common.internal_notes ? `<p><strong>Internal Notes:</strong> ${data.common.internal_notes}</p>` : ''}
                                ${data.common.customer_comments ? `<p><strong>Customer Comments:</strong> ${data.common.customer_comments}</p>` : ''}
                            </div>
                        `;

                        Swal.fire({
                            icon: 'success',
                            title: 'Adjustment Submitted',
                            html: detailsHtml,
                            confirmButtonColor: '#198754',
                            confirmButtonText: 'OK'
                        });
                    }
                });

                // Event listener for reason selection change
                $(document).on('change', '#adjustmentReason', function() {
                    toggleConditionalFields($(this).val());
                    
                    // Auto-calculate difference for incorrect amount
                    if ($(this).val() === 'incorrect_amount') {
                        calculateDifference();
                    }
                });

                // Calculate difference for incorrect amount
                function calculateDifference() {
                    const original = parseFloat(document.getElementById('incorrect_original_amount')?.value) || 0;
                    const current = parseFloat(document.getElementById('incorrect_current_amount')?.value) || 0;
                    const correct = parseFloat(document.getElementById('incorrect_correct_amount')?.value) || 0;
                    const diff = correct - current;
                    const diffField = document.getElementById('incorrect_difference');
                    if (diffField) {
                        diffField.value = diff !== 0 ? `₱${diff.toFixed(2)}` : '₱0.00';
                    }
                }

                // Recalculate on input change
                $(document).on('input', '#incorrect_original_amount, #incorrect_current_amount, #incorrect_correct_amount', function() {
                    if ($('#adjustmentReason').val() === 'incorrect_amount') {
                        calculateDifference();
                    }
                });
            });

            // Double-click a row to view full transaction details
            $(document).on('dblclick', 'tbody tr', function() {
                const id = $(this).data('id');
                if (!id) return;

                Swal.fire({
                    title: 'Loading...',
                    allowOutsideClick: false,
                    didOpen: () => Swal.showLoading()
                });

                $.ajax({
                    url: 'get_transaction_details.php',
                    method: 'GET',
                    data: { id: id },
                    dataType: 'json',
                    success: function(response) {
                        if (!response.success) {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: response.message || 'Failed to load transaction details.'
                            });
                            return;
                        }

                        const d = response.data;
                        const formatVal = (val) => (val === null || val === '' || val === undefined) ? '—' : val;
                        const formatAmount = (val) => (val !== null && val !== '' && val !== undefined) ? '₱' + parseFloat(val).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) : '—';
                        const formatDate = (val) => val ? new Date(val).toLocaleString() : '—';
                        const isCancelledRow = d.cancellation_date || (d.status && d.status.toLowerCase().includes('cancel'));
                        const isSettledRow = d.settle_unsettle && d.settle_unsettle.toLowerCase() === 'settled';

                        let titleText = 'Transaction Details';
                        if (isCancelledRow) {
                            titleText += ' <span class="text-danger">(CANCELLED)</span>';
                        } else if (isSettledRow) {
                            titleText += ' <span class="text-success">(SETTLED)</span>';
                        }

                        const html = `
                            <div class="text-start" style="max-height: 60vh; overflow-y: auto;">
                                <div class="detail-section-title">Transaction Info</div>
                                <div class="row detail-row mb-2">
                                    <div class="col-6"><strong>Status:</strong> ${formatVal(d.status)}</div>
                                    <div class="col-6"><strong>Settle/Unsettle:</strong> ${formatVal(d.settle_unsettle)}</div>
                                    <div class="col-6"><strong>Reference No:</strong> ${formatVal(d.reference_no)}</div>
                                    <div class="col-6"><strong>Control No:</strong> ${formatVal(d.control_no)}</div>
                                    <div class="col-6"><strong>Billing Invoice:</strong> ${formatVal(d.billing_invoice)}</div>
                                    <div class="col-6"><strong>Source File:</strong> ${formatVal(d.source_file)}</div>
                                    <div class="col-6"><strong>Datetime:</strong> ${formatDate(d.datetime)}</div>
                                    <div class="col-6"><strong>Report Date:</strong> ${formatVal(d.report_date)}</div>
                                    <div class="col-6"><strong>Settlement Date:</strong> ${formatVal(d.settlement_date)}</div>
                                    <div class="col-6"><strong>Cancellation Date:</strong> ${formatDate(d.cancellation_date)}</div>
                                </div>

                                <div class="detail-section-title">Payor Info</div>
                                <div class="row detail-row mb-2">
                                    <div class="col-6"><strong>Payor:</strong> ${formatVal(d.payor)}</div>
                                    <div class="col-6"><strong>Account No:</strong> ${formatVal(d.account_no)}</div>
                                    <div class="col-6"><strong>Account Name:</strong> ${formatVal(d.account_name)}</div>
                                    <div class="col-6"><strong>Contact No:</strong> ${formatVal(d.contact_no)}</div>
                                    <div class="col-12"><strong>Address:</strong> ${formatVal(d.address)}</div>
                                </div>

                                <div class="detail-section-title">Partner Info</div>
                                <div class="row detail-row mb-2">
                                    <div class="col-6"><strong>Partner Name:</strong> ${formatVal(d.partner_name)}</div>
                                    <div class="col-6"><strong>Partner ID:</strong> ${formatVal(d.partner_id)}</div>
                                    <div class="col-6"><strong>Partner ID (KPX):</strong> ${formatVal(d.partner_id_kpx)}</div>
                                    <div class="col-6"><strong>MPM GL Code:</strong> ${formatVal(d.mpm_gl_code)}</div>
                                    <div class="col-6"><strong>Sub Billers ID:</strong> ${formatVal(d.sub_billers_id)}</div>
                                    <div class="col-6"><strong>Sub Billers Name:</strong> <span style="color: green; font-weight: bold;"> ${formatVal(d.sub_billers_name)}</span></div>
                                </div>

                                <div class="detail-section-title">Amounts</div>
                                <div class="row detail-row mb-2">
                                    <div class="col-6"><strong>Amount Paid:</strong> ${formatAmount(d.amount_paid)}</div>
                                    <div class="col-6"><strong>Charge to Customer:</strong> ${formatAmount(d.charge_to_customer)}</div>
                                    <div class="col-6"><strong>Charge to Partner:</strong> ${formatAmount(d.charge_to_partner)}</div>
                                    <div class="col-6"><strong>New Amount:</strong> ${formatAmount(d.new_amount)}</div>
                                    <div class="col-6"><strong>Deducted Amount:</strong> ${formatAmount(d.deducted_amount)}</div>
                                </div>

                                <div class="detail-section-title">Location / Branch Info</div>
                                <div class="row detail-row mb-2">
                                    <div class="col-6"><strong>Branch ID:</strong> ${formatVal(d.branch_id)}</div>
                                    <div class="col-6"><strong>Branch Code:</strong> ${formatVal(d.branch_code)}</div>
                                    <div class="col-6"><strong>Outlet:</strong> ${formatVal(d.outlet)}</div>
                                    <div class="col-6"><strong>Zone Code:</strong> ${formatVal(d.zone_code)}</div>
                                    <div class="col-6"><strong>Region Code:</strong> ${formatVal(d.region_code)}</div>
                                    <div class="col-6"><strong>Region Code (TG):</strong> ${formatVal(d.region_code_tg)}</div>
                                    <div class="col-6"><strong>Region:</strong> ${formatVal(d.region)}</div>
                                    <div class="col-6"><strong>Region (TG):</strong> ${formatVal(d.region_tg)}</div>
                                    <div class="col-6"><strong>Remote Branch:</strong> ${formatVal(d.remote_branch)}</div>
                                    <div class="col-6"><strong>Remote Operator:</strong> ${formatVal(d.remote_operator)}</div>
                                </div>

                                <div class="detail-section-title">Handling / Approval</div>
                                <div class="row detail-row mb-2">
                                    <div class="col-6"><strong>Operator:</strong> ${formatVal(d.operator)}</div>
                                    <div class="col-6"><strong>2nd Approver:</strong> ${formatVal(d['2nd_approver'])}</div>
                                    <div class="col-6"><strong>Claim/Unclaim:</strong> ${formatVal(d.claim_unclaim)}</div>
                                    <div class="col-6"><strong>Hold Status:</strong> ${formatVal(d.hold_status)}</div>
                                    <div class="col-6"><strong>Post Transaction:</strong> ${formatVal(d.post_transaction)}</div>
                                </div>

                                <div class="detail-section-title">Adjustment Info</div>
                                <div class="row detail-row mb-2">
                                    <div class="col-6"><strong>Reason for Adjustment:</strong> ${formatVal(d.reason_for_adjustment)}</div>
                                    <div class="col-6"><strong>RFP No:</strong> ${formatVal(d.rfp_no)}</div>
                                    <div class="col-6"><strong>CAD No:</strong> ${formatVal(d.cad_no)}</div>
                                    <div class="col-12"><strong>Other Details:</strong> ${formatVal(d.other_details)}</div>
                                </div>

                                <div class="detail-section-title">Import Info</div>
                                <div class="row detail-row mb-2">
                                    <div class="col-6"><strong>Imported By:</strong> ${formatVal(d.imported_by)}</div>
                                    <div class="col-6"><strong>Imported Date:</strong> ${formatVal(d.imported_date)}</div>
                                </div>
                            </div>
                        `;

                        Swal.fire({
                            title: titleText,
                            html: html,
                            width: '800px',
                            showCloseButton: true,
                            showConfirmButton: false
                        });
                    },
                    error: function() {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'Failed to load transaction details. Please try again.'
                        });
                    }
                });
            });
        });
    </script>
    
    <?php include '../../../templates/footer.php'; ?>
</body>
</html>