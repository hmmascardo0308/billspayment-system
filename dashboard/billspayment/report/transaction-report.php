<?php
// Connect to the database
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../vendor/autoload.php';

// Start the session
session_start();
@include_once __DIR__ . '/../../../templates/middleware.php';
$id = resolve_user_identifier();
if (empty($id)) { header('Location: ../../../login_form.php'); exit; }
if (!function_exists('has_any_permission') || !has_any_permission(['Transaction Report','Bills Payment'])) { header('Location: ../../home.php'); exit; }
// prefer explicit session values for current user email; avoid role-based gating
$current_user_email = $_SESSION['admin_email'] ?? $_SESSION['user_email'] ?? '';

// Fetch distinct partners for the dropdown filter
$partners = [];
try {
    $result = $conn->query("SELECT DISTINCT partner_id_kpx, partner_name FROM masterdata.partner_masterfile WHERE partner_id_kpx IS NOT NULL AND partner_name != '' ORDER BY partner_name ASC");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $partners[] = $row;
        }
    }
} catch (Exception $e) {
    // Handle error quietly or log it
}

// Capture submitted filter values to keep them selected/filled after submission
$selected_partner = $_GET['partner_id'] ?? '';
$date_from        = $_GET['date_from'] ?? '';
$date_to          = $_GET['date_to'] ?? '';
$selected_status  = $_GET['status'] ?? '';
$search_keyword   = $_GET['search'] ?? '';

// Fetch transaction data based on filters
$transactions = [];
$has_filters = !empty($selected_partner) || !empty($date_from) || !empty($date_to) || !empty($selected_status) || !empty($search_keyword);

// Summary variables
$normal = [
    'volume' => 0,
    'principal' => 0,
    'charge_to_partner' => 0,
    'charge_to_customer' => 0,
    'total_charge' => 0
];

$adjustment = [
    'volume' => 0,
    'principal' => 0,
    'charge_to_partner' => 0,
    'charge_to_customer' => 0,
    'total_charge' => 0
];

if ($has_filters) {
    try {
        // ============================================
        // FIX: Build separate queries for normal and cancelled
        // ============================================
        
        // Base conditions
        $base_conditions = "1=1";
        $params = [];
        $types = "";
        
        // Partner filter
        if (!empty($selected_partner)) {
            $base_conditions .= " AND bt.partner_id_kpx = ?";
            $params[] = $selected_partner;
            $types .= "s";
        }
        
        // Search filter - reference_no and outlet (branch name)
        if (!empty($search_keyword)) {
            $base_conditions .= " AND (bt.reference_no LIKE ? OR bt.outlet LIKE ?)";
            $search_param = "%" . $search_keyword . "%";
            $params[] = $search_param;
            $params[] = $search_param;
            $types .= "ss";
        }
        
        // ============================================
        // QUERY 1: NORMAL TRANSACTIONS (datetime BETWEEN range AND cancellation_date IS NULL)
        // ============================================
        $normal_sql = "SELECT 
                        bt.*,
                        pm.partner_name
                    FROM mldb.billspayment_transaction bt
                    LEFT JOIN masterdata.partner_masterfile pm ON bt.partner_id_kpx = pm.partner_id_kpx
                    WHERE $base_conditions";
        
        $normal_params = $params;
        $normal_types = $types;
        
        // Date filter for NORMAL transactions - only datetime
        if (!empty($date_from) && !empty($date_to)) {
            $normal_sql .= " AND DATE(bt.datetime) BETWEEN ? AND ?";
            $normal_params[] = $date_from;
            $normal_params[] = $date_to;
            $normal_types .= "ss";
        } elseif (!empty($date_from)) {
            $normal_sql .= " AND DATE(bt.datetime) >= ?";
            $normal_params[] = $date_from;
            $normal_types .= "s";
        } elseif (!empty($date_to)) {
            $normal_sql .= " AND DATE(bt.datetime) <= ?";
            $normal_params[] = $date_to;
            $normal_types .= "s";
        }
        
        // FIX: Normal transactions MUST have cancellation_date IS NULL
        $normal_sql .= " AND bt.cancellation_date IS NULL";
        
        // Status filter for NORMAL - only 'active' or all (excluding cancelled)
        if (!empty($selected_status)) {
            if ($selected_status === 'active') {
                $normal_sql .= " AND (bt.status IS NULL OR bt.status = '')";
            } elseif ($selected_status === 'cancelled') {
                // If status is cancelled, don't return any normal transactions
                $normal_sql .= " AND 1=0";
            }
        } else {
            // If no status filter, exclude cancelled from normal
            $normal_sql .= " AND (bt.status IS NULL OR bt.status = '')";
        }
        
        $normal_sql .= " ORDER BY bt.datetime ASC";
        
        // Execute NORMAL query
        $normal_stmt = $conn->prepare($normal_sql);
        if (!empty($normal_params)) {
            $normal_stmt->bind_param($normal_types, ...$normal_params);
        }
        $normal_stmt->execute();
        $normal_result = $normal_stmt->get_result();
        
        // Process normal transactions
        while ($row = $normal_result->fetch_assoc()) {
            $transactions[] = $row;
            
            // Normal transaction (positive amounts)
            $amount_paid = floatval($row['amount_paid'] ?? 0);
            $charge_to_partner = floatval($row['charge_to_partner'] ?? 0);
            $charge_to_customer = floatval($row['charge_to_customer'] ?? 0);
            $total_charge = $charge_to_partner + $charge_to_customer;
            
            $normal['volume']++;
            $normal['principal'] += $amount_paid;
            $normal['charge_to_partner'] += $charge_to_partner;
            $normal['charge_to_customer'] += $charge_to_customer;
            $normal['total_charge'] += $total_charge;
        }
        $normal_stmt->close();
        
        // ============================================
        // QUERY 2: CANCELLED TRANSACTIONS (cancellation_date BETWEEN range)
        // ============================================
        $cancelled_sql = "SELECT 
                            bt.*,
                            pm.partner_name
                        FROM mldb.billspayment_transaction bt
                        LEFT JOIN masterdata.partner_masterfile pm ON bt.partner_id_kpx = pm.partner_id_kpx
                        WHERE $base_conditions";
        
        $cancelled_params = $params;
        $cancelled_types = $types;
        
        // Date filter for CANCELLED transactions - only cancellation_date
        if (!empty($date_from) && !empty($date_to)) {
            $cancelled_sql .= " AND DATE(bt.cancellation_date) BETWEEN ? AND ?";
            $cancelled_params[] = $date_from;
            $cancelled_params[] = $date_to;
            $cancelled_types .= "ss";
        } elseif (!empty($date_from)) {
            $cancelled_sql .= " AND DATE(bt.cancellation_date) >= ?";
            $cancelled_params[] = $date_from;
            $cancelled_types .= "s";
        } elseif (!empty($date_to)) {
            $cancelled_sql .= " AND DATE(bt.cancellation_date) <= ?";
            $cancelled_params[] = $date_to;
            $cancelled_types .= "s";
        }
        
        // FIX: Cancelled transactions MUST have cancellation_date IS NOT NULL
        $cancelled_sql .= " AND bt.cancellation_date IS NOT NULL";
        
        // Status filter for CANCELLED - only 'cancelled'
        if (!empty($selected_status)) {
            if ($selected_status === 'cancelled') {
                $cancelled_sql .= " AND bt.status = '*'";
            } elseif ($selected_status === 'active') {
                // If status is active, don't return any cancelled transactions
                $cancelled_sql .= " AND 1=0";
            }
        } else {
            // If no status filter, include all cancelled
            $cancelled_sql .= " AND bt.status = '*'";
        }
        
        $cancelled_sql .= " ORDER BY bt.datetime ASC";
        
        // Execute CANCELLED query
        $cancelled_stmt = $conn->prepare($cancelled_sql);
        if (!empty($cancelled_params)) {
            $cancelled_stmt->bind_param($cancelled_types, ...$cancelled_params);
        }
        $cancelled_stmt->execute();
        $cancelled_result = $cancelled_stmt->get_result();
        
        // Process cancelled transactions
        while ($row = $cancelled_result->fetch_assoc()) {
            $transactions[] = $row;
            
            // Adjustment/Cancellation - stored as negative in DB,
            // but we accumulate/display as positive so net subtraction works correctly
            $amount_paid = floatval($row['amount_paid'] ?? 0);
            $charge_to_partner = floatval($row['charge_to_partner'] ?? 0);
            $charge_to_customer = floatval($row['charge_to_customer'] ?? 0);
            $total_charge = $charge_to_partner + $charge_to_customer;
            
            $adjustment['volume']++;
            $adjustment['principal'] += abs($amount_paid);
            $adjustment['charge_to_partner'] += abs($charge_to_partner);
            $adjustment['charge_to_customer'] += abs($charge_to_customer);
            $adjustment['total_charge'] += abs($total_charge);
        }
        $cancelled_stmt->close();
        
        // Sort combined transactions by datetime
        usort($transactions, function($a, $b) {
            return strtotime($a['datetime'] ?? '') - strtotime($b['datetime'] ?? '');
        });
        
    } catch (Exception $e) {
        // Handle error quietly or log it
        $error_message = $e->getMessage();
    }
}

// Calculate Net values
$net = [
    'volume' => $normal['volume'] - $adjustment['volume'],
    'principal' => $normal['principal'] - $adjustment['principal'],
    'charge_to_partner' => $normal['charge_to_partner'] - $adjustment['charge_to_partner'],
    'charge_to_customer' => $normal['charge_to_customer'] - $adjustment['charge_to_customer'],
    'total_charge' => $normal['total_charge'] - $adjustment['total_charge']
];

// Calculate Settlement Amount (Net Principal - Net Total Charge)
$settlement_amount = $net['principal'] - $net['total_charge'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transaction Details Report | <?php if($_SESSION['user_type'] === 'admin' || $_SESSION['user_type'] === 'user') echo ucfirst($_SESSION['user_type']); else echo "Guest";?></title>
    <link rel="stylesheet" href="../../../assets/css/templates/style.css?v=<?php echo time(); ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2-bootstrap-5-theme/1.3.0/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    <script src="https://kit.fontawesome.com/30b908cc5a.js" crossorigin="anonymous"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../../assets/js/sweetalert2.all.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
    <link rel="icon" href="../../../images/MLW logo.png" type="image/png">
    <link rel="stylesheet" href="css/t-report.css?v=<?= time(); ?>">

</head>
<body>
    <div class="main-container">
        <?php include '../../../templates/header_ui.php'; ?>
        <?php include '../../../templates/sidebar.php'; ?>
        
        <div class="bp-section-header" role="region" aria-label="Page title">
            <div class="bp-section-title">
                <i class="fa-solid fa-file-invoice-dollar" aria-hidden="true"></i>
                <div>
                    <h2>Transaction Details Report</h2>
                    <p class="bp-section-sub">Detailed transaction filters and listing</p>
                </div>
            </div>
        </div>

        <div class="filter-container">
    <div class="filter-header">
        <i class="fa-solid fa-filter"></i> Filter Transactions
    </div>
    <div class="filter-body">
        <form method="GET" action="" id="filterForm">
            <div class="filter-row">
                <div class="filter-group">
                    <label for="partner_id">Partner Name</label>
                    <select class="select2-field" id="partner_id" name="partner_id">
                        <option value="">All Partners</option>
                        <?php foreach ($partners as $partner): ?>
                            <option value="<?php echo htmlspecialchars($partner['partner_id_kpx']); ?>" <?php echo ($selected_partner === $partner['partner_id_kpx']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($partner['partner_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="date_from">Transaction Date From</label>
                    <input type="date" id="date_from" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                </div>

                <div class="filter-group">
                    <label for="date_to">Transaction Date To</label>
                    <input type="date" id="date_to" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                </div>

                <div class="filter-group">
                    <label for="status">Transaction Status</label>
                    <select id="status" name="status">
                        <option value="">All Status</option>
                        <option value="active" <?php echo ($selected_status === 'active') ? 'selected' : ''; ?>>Active</option>
                        <option value="cancelled" <?php echo ($selected_status === 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>

                <div class="filter-group search-group">
                    <label for="search">Search</label>
                    <div class="search-icon">
                        <input type="text" id="search" name="search" placeholder="Reference # or Branch name..." value="<?php echo htmlspecialchars($search_keyword); ?>">
                        <i class="fa-solid fa-search"></i>
                    </div>
                </div>

                <div class="filter-actions">
                    <button type="submit">
                        <i class="fa-solid fa-magnifying-glass"></i> Filter
                    </button>
                    <a href="<?php echo $_SERVER['PHP_SELF']; ?>">
                        Reset
                    </a>
                </div>
            </div>
        </form>
    </div>
</div>

        <!-- Results Table -->
        <div class="container-fluid mt-4">
            <div class="card shadow-sm">
                <div class="card-header bg-light fw-bold text-secondary d-flex justify-content-between align-items-center">
                    <span><i class="fa-solid fa-table me-2"></i> Transaction Results</span>
                    <?php if ($has_filters): ?>
                        <span class="badge bg-danger rounded-pill"><?php echo count($transactions); ?> records found</span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if ($has_filters): ?>
                        <?php if (!empty($transactions)): ?>
                            <!-- Left-Right Layout: Summary on Left, Table on Right -->
                            <div class="summary-wrapper">
                                <!-- Left: Summary Section -->
                                <div class="summary-left">
                                    <div class="summary-card">
                                        <div class="summary-title">
                                            <i class="fa-solid fa-chart-bar me-2"></i> Summary Results
                                        </div>
                                        
                                        <!-- Normal Transaction Summary -->
                                        <div class="summary-section">
                                            <div class="section-header">
                                                <span><i class="fa-solid fa-circle-check text-success me-1"></i> Normal Transaction</span>
                                                <span class="badge bg-success"><?php echo number_format($normal['volume']); ?></span>
                                            </div>
                                            <div class="summary-row">
                                                <span class="label">Principal</span>
                                                <span class="value">₱ <?php echo number_format($normal['principal'], 2); ?></span>
                                            </div>
                                            <div class="summary-row">
                                                <span class="label">Charge to Partner</span>
                                                <span class="value">₱ <?php echo number_format($normal['charge_to_partner'], 2); ?></span>
                                            </div>
                                            <div class="summary-row">
                                                <span class="label">Charge to Customer</span>
                                                <span class="value">₱ <?php echo number_format($normal['charge_to_customer'], 2); ?></span>
                                            </div>
                                            <div class="summary-row total">
                                                <span class="label">Total Charge</span>
                                                <span class="value">₱ <?php echo number_format($normal['total_charge'], 2); ?></span>
                                            </div>
                                        </div>

                                        <!-- Adjustment/Cancellation Summary -->
                                        <div class="summary-section">
                                            <div class="section-header">
                                                <span><i class="fa-solid fa-circle-xmark text-danger me-1"></i> Adjustment</span>
                                                <span class="badge bg-danger"><?php echo number_format($adjustment['volume']); ?></span>
                                            </div>
                                            <div class="summary-row">
                                                <span class="label">Principal</span>
                                                <span class="value">₱ <?php echo number_format($adjustment['principal'], 2); ?></span>
                                            </div>
                                            <div class="summary-row">
                                                <span class="label">Charge to Partner</span>
                                                <span class="value">₱ <?php echo number_format($adjustment['charge_to_partner'], 2); ?></span>
                                            </div>
                                            <div class="summary-row">
                                                <span class="label">Charge to Customer</span>
                                                <span class="value">₱ <?php echo number_format($adjustment['charge_to_customer'], 2); ?></span>
                                            </div>
                                            <div class="summary-row total">
                                                <span class="label">Total Charge</span>
                                                <span class="value">₱ <?php echo number_format($adjustment['total_charge'], 2); ?></span>
                                            </div>
                                        </div>

                                        <!-- Net Summary -->
                                        <div class="summary-section net-section">
                                            <div class="section-header">
                                                <span><i class="fa-solid fa-calculator me-1"></i> Net</span>
                                                <span class="badge bg-primary"><?php echo number_format($net['volume']); ?></span>
                                            </div>
                                            <div class="summary-row">
                                                <span class="label">Principal</span>
                                                <span class="value">₱ <?php echo number_format($net['principal'], 2); ?></span>
                                            </div>
                                            <div class="summary-row">
                                                <span class="label">Charge to Partner</span>
                                                <span class="value">₱ <?php echo number_format($net['charge_to_partner'], 2); ?></span>
                                            </div>
                                            <div class="summary-row">
                                                <span class="label">Charge to Customer</span>
                                                <span class="value">₱ <?php echo number_format($net['charge_to_customer'], 2); ?></span>
                                            </div>
                                            <div class="summary-row total">
                                                <span class="label">Total Charge</span>
                                                <span class="value">₱ <?php echo number_format($net['total_charge'], 2); ?></span>
                                            </div>
                                            <div class="settlement-amount">
                                                <div class="label">
                                                    <i class="fa-solid fa-hand-holding-dollar me-1"></i> Settlement Amount: ₱ <?php echo number_format($settlement_amount, 2); ?></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Right: Table -->
                                <div class="summary-right">
                                    <div class="table-responsive">
                                        <table class="table table-hover table-striped table-bordered">
                                            <thead>
                                                <tr>
                                                    <th colspan="10"></th>
                                                    <th colspan="2">Charge to</th>
                                                    <th colspan="3"></th>
                                                </tr>
                                                <tr>
                                                    <th class="counter-column">#</th>
                                                    <th>Status</th>
                                                    <th>Transaction Date</th>
                                                    <th>Cancelled Date</th>
                                                    <th>Reference No.</th>
                                                    <th>Branch ID</th>
                                                    <th>Branch Name</th>
                                                    <th>Partner ID</th>
                                                    <th>Partner Name</th>
                                                    <th>Principal Amount</th>
                                                    <th>Partner</th>
                                                    <th>Customer</th>
                                                    <th>Billing Invoice</th>
                                                    <th>Settlement Status</th>
                                                    <th>CAD Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php 
                                                $counter = 1;
                                                foreach ($transactions as $transaction): 
                                                    // Determine status
                                                    $status = (empty($transaction['status']) || $transaction['status'] === null) ? 'Active' : 'Cancelled';
                                                    $statusClass = ($status === 'Active') ? 'badge-active' : 'badge-cancelled';
                                                    
                                                    // Determine settlement status
                                                    $settlementStatus = isset($transaction['settle_unsettle']) ? $transaction['settle_unsettle'] : '-';
                                                    $settlementClass = '';
                                                    if (strtolower($settlementStatus) === 'settled' || strtolower($settlementStatus) === 'yes') {
                                                        $settlementClass = 'badge-settled';
                                                    } else {
                                                        $settlementClass = 'badge-unsettled';
                                                    }
                                                    
                                                    // Determine CAD status
                                                    $cadStatus = isset($transaction['post_transaction']) ? $transaction['post_transaction'] : '-';
                                                    $cadClass = '';
                                                    if (strtolower($cadStatus) === 'posted' || strtolower($cadStatus) === 'yes') {
                                                        $cadClass = 'badge-posted';
                                                    } else {
                                                        $cadClass = 'badge-unposted';
                                                    }
                                                ?>
                                                <tr>
                                                    <td class="counter-column"><?php echo $counter++; ?></td>
                                                    <td>
                                                        <span class="badge-status <?php echo $statusClass; ?>">
                                                            <?php echo htmlspecialchars($status); ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo !empty($transaction['datetime']) ? date('m/d/Y h:i:s A', strtotime($transaction['datetime'])) : '-'; ?></td>
                                                    <td><?php echo !empty($transaction['cancellation_date']) ? date('m/d/Y', strtotime($transaction['cancellation_date'])) : '-'; ?></td>
                                                    <td><span class="text-truncate-custom" title="<?php echo htmlspecialchars($transaction['reference_no'] ?? ''); ?>"><?php echo htmlspecialchars($transaction['reference_no'] ?? '-'); ?></span></td>
                                                    <td><?php echo htmlspecialchars($transaction['branch_id'] ?? '-'); ?></td>
                                                    <td><?php echo htmlspecialchars($transaction['outlet'] ?? '-'); ?></td>
                                                    <td><?php echo htmlspecialchars($transaction['partner_id_kpx'] ?? '-'); ?></td>
                                                    <td><?php echo htmlspecialchars($transaction['partner_name'] ?? '-'); ?></td>
                                                    <td class="text-end"><?php echo !empty($transaction['amount_paid']) ? number_format($transaction['amount_paid'], 2) : '0.00'; ?></td>
                                                    <td class="text-end"><?php echo !empty($transaction['charge_to_partner']) ? number_format($transaction['charge_to_partner'], 2) : '0.00'; ?></td>
                                                    <td class="text-end"><?php echo !empty($transaction['charge_to_customer']) ? number_format($transaction['charge_to_customer'], 2) : '0.00'; ?></td>
                                                    <td><?php echo htmlspecialchars($transaction['billing_invoice'] ?? '-'); ?></td>
                                                    <td>
                                                        <span class="badge-status <?php echo $settlementClass; ?>">
                                                            <?php echo htmlspecialchars($settlementStatus); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span class="badge-status <?php echo $cadClass; ?>">
                                                            <?php echo htmlspecialchars($cadStatus); ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="no-results">
                                <i class="fa-solid fa-search"></i>
                                <h5>No transactions found</h5>
                                <p class="text-muted">Try adjusting your filter criteria</p>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="no-results">
                            <i class="fa-solid fa-filter"></i>
                            <h5>Apply filters to view transactions</h5>
                            <p class="text-muted">Select a partner, date range, or status to display results</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        </div>

    <script>
        $(document).ready(function() {
            $('.select2-field').select2({
                theme: 'bootstrap-5',
                placeholder: 'Select a Partner',
                allowClear: true
            });
        });
    </script>
</body>
<?php include '../../../templates/footer.php'; ?>
</html>