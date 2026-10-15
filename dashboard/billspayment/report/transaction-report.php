<?php
// Connect to the database transaction-report.php

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../vendor/autoload.php';

// Start the session
session_start();
@include_once __DIR__ . '/../../../templates/middleware.php';
$id = resolve_user_identifier();
if (empty($id)) { header('Location: ../../../login_form.php'); exit; }
if (!function_exists('has_any_permission') || !has_any_permission(['Transaction Report','Bills Payment'])) { header('Location: ../../home.php'); exit; }
$current_user_email = $_SESSION['admin_email'] ?? $_SESSION['user_email'] ?? '';

// ============================================
// Fetch distinct partners (cached in session for 5 minutes)
// ============================================
$partners = [];
$cache_key = 'partners_cache_' . session_id();
$cache_ttl = 300; // 5 minutes

if (isset($_SESSION[$cache_key]) && (time() - $_SESSION[$cache_key]['ts']) < $cache_ttl) {
    $partners = $_SESSION[$cache_key]['data'];
} else {
    try {
        $result = $conn->query("SELECT DISTINCT partner_id_kpx, partner_name FROM masterdata.partner_masterfile WHERE partner_id_kpx IS NOT NULL AND partner_name != '' ORDER BY partner_name ASC");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $partners[] = $row;
            }
        }
        $_SESSION[$cache_key] = ['ts' => time(), 'data' => $partners];
    } catch (Exception $e) {
        // silent
    }
}

// Build partner lookup for display (avoids LEFT JOIN later)
$partner_lookup = [];
foreach ($partners as $p) {
    $partner_lookup[$p['partner_id_kpx']] = $p['partner_name'];
}

// ============================================
// Capture filter values
// ============================================
$selected_partner = $_GET['partner_id'] ?? '';
$date_from        = $_GET['date_from'] ?? '';
$date_to          = $_GET['date_to'] ?? '';
$selected_status  = $_GET['status'] ?? '';
$search_keyword   = trim($_GET['search'] ?? '');

// Pagination
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 100;
$offset   = ($page - 1) * $per_page;

// ============================================
// Summary accumulators
// ============================================
$normal = [
    'volume' => 0, 'principal' => 0, 'charge_to_partner' => 0,
    'charge_to_customer' => 0, 'total_charge' => 0
];
$adjustment = [
    'volume' => 0, 'principal' => 0, 'charge_to_partner' => 0,
    'charge_to_customer' => 0, 'total_charge' => 0
];

$transactions = [];
$total_records = 0;
$total_pages = 1;
$error_message = null;

$has_filters = !empty($selected_partner) || !empty($date_from) || !empty($date_to) || !empty($selected_status) || !empty($search_keyword);

if ($has_filters) {
    try {
        // ============================================
        // Prepare date boundaries (avoid DATE() function)
        // ============================================
        $dt_from = null; $dt_to_excl = null;
        if (!empty($date_from)) {
            $dt_from = $date_from . ' 00:00:00';
        }
        if (!empty($date_to)) {
            $dt_to_excl = date('Y-m-d', strtotime($date_to . ' +1 day')) . ' 00:00:00';
        }

        // ============================================
        // Build common WHERE fragments (partner + search)
        // ============================================
        $common_where  = "";
        $common_params = [];
        $common_types  = "";

        if (!empty($selected_partner)) {
            $common_where .= " AND bt.partner_id_kpx = ?";
            $common_params[] = $selected_partner;
            $common_types .= "s";
        }

        if (!empty($search_keyword)) {
            $common_where .= " AND (bt.reference_no LIKE ? OR bt.outlet LIKE ?)";
            $search_param = $search_keyword . "%";
            $common_params[] = $search_param;
            $common_params[] = $search_param;
            $common_types .= "ss";
        }

        // ============================================
        // Determine status flags
        // ============================================
        $include_normal    = true;
        $include_cancelled = true;

        if ($selected_status === 'active') {
            $include_cancelled = false;
        } elseif ($selected_status === 'cancelled') {
            $include_normal = false;
        }

        // ============================================
        // Build UNION ALL query
        // ============================================
        $union_parts  = [];
        $union_params = [];
        $union_types  = "";

        // ---- Normal branch ----
        if ($include_normal) {
            $normal_where  = "bt.cancellation_date IS NULL AND (bt.status IS NULL OR bt.status = '')";
            $normal_params = [];
            $normal_types  = "";

            if ($dt_from !== null && $dt_to_excl !== null) {
                $normal_where .= " AND bt.datetime >= ? AND bt.datetime < ?";
                $normal_params[] = $dt_from;
                $normal_params[] = $dt_to_excl;
                $normal_types .= "ss";
            } elseif ($dt_from !== null) {
                $normal_where .= " AND bt.datetime >= ?";
                $normal_params[] = $dt_from;
                $normal_types .= "s";
            } elseif ($dt_to_excl !== null) {
                $normal_where .= " AND bt.datetime < ?";
                $normal_params[] = $dt_to_excl;
                $normal_types .= "s";
            }

            $normal_params = array_merge($normal_params, $common_params);
            $normal_types .= $common_types;

           $union_parts[] = "(
    SELECT bt.id, bt.status, bt.datetime, bt.cancellation_date, bt.reference_no,
           bt.branch_id, bt.outlet, bt.partner_id_kpx, bt.partner_name, bt.amount_paid,
           bt.charge_to_partner, bt.charge_to_customer, bt.billing_invoice,
           bt.settle_unsettle, bt.post_transaction, bt.import_status,
           'normal' AS row_type
    FROM mldb.billspayment_transaction bt
    WHERE " . ltrim($normal_where, " AND ") . $common_where . "
)";
            $union_params = array_merge($union_params, $normal_params);
            $union_types .= $normal_types;
        }

        // ---- Cancelled branch ----
        if ($include_cancelled) {
            $cancelled_where  = "bt.cancellation_date IS NOT NULL AND bt.status = '*'";
            $cancelled_params = [];
            $cancelled_types  = "";

            if ($dt_from !== null && $dt_to_excl !== null) {
                $cancelled_where .= " AND bt.cancellation_date >= ? AND bt.cancellation_date < ?";
                $cancelled_params[] = $dt_from;
                $cancelled_params[] = $dt_to_excl;
                $cancelled_types .= "ss";
            } elseif ($dt_from !== null) {
                $cancelled_where .= " AND bt.cancellation_date >= ?";
                $cancelled_params[] = $dt_from;
                $cancelled_types .= "s";
            } elseif ($dt_to_excl !== null) {
                $cancelled_where .= " AND bt.cancellation_date < ?";
                $cancelled_params[] = $dt_to_excl;
                $cancelled_types .= "s";
            }

            $cancelled_params = array_merge($cancelled_params, $common_params);
            $cancelled_types .= $common_types;

            $union_parts[] = "(
    SELECT bt.id, bt.status, bt.datetime, bt.cancellation_date, bt.reference_no,
           bt.branch_id, bt.outlet, bt.partner_id_kpx, bt.partner_name, bt.amount_paid,
           bt.charge_to_partner, bt.charge_to_customer, bt.billing_invoice,
           bt.settle_unsettle, bt.post_transaction, bt.import_status,
           'cancelled' AS row_type
    FROM mldb.billspayment_transaction bt
    WHERE " . ltrim($cancelled_where, " AND ") . $common_where . "
)";
            $union_params = array_merge($union_params, $cancelled_params);
            $union_types .= $cancelled_types;
        }

        if (empty($union_parts)) {
            $transactions = [];
        } else {
            $union_sql = implode(" UNION ALL ", $union_parts);

            // ============================================
            // SUMMARY QUERY (aggregate, no LIMIT)
            // ============================================
            $summary_sql = "
                SELECT
                    SUM(CASE WHEN row_type='normal' THEN 1 ELSE 0 END) AS normal_vol,
                    SUM(CASE WHEN row_type='normal' THEN amount_paid ELSE 0 END) AS normal_principal,
                    SUM(CASE WHEN row_type='normal' THEN charge_to_partner ELSE 0 END) AS normal_ctp,
                    SUM(CASE WHEN row_type='normal' THEN charge_to_customer ELSE 0 END) AS normal_ctc,
                    SUM(CASE WHEN row_type='cancelled' THEN 1 ELSE 0 END) AS adj_vol,
                    SUM(CASE WHEN row_type='cancelled' THEN ABS(amount_paid) ELSE 0 END) AS adj_principal,
                    SUM(CASE WHEN row_type='cancelled' THEN ABS(charge_to_partner) ELSE 0 END) AS adj_ctp,
                    SUM(CASE WHEN row_type='cancelled' THEN ABS(charge_to_customer) ELSE 0 END) AS adj_ctc
                FROM ( $union_sql ) AS combined
            ";

            $summary_stmt = $conn->prepare($summary_sql);
            if (!empty($union_params)) {
                $summary_stmt->bind_param($union_types, ...$union_params);
            }
            $summary_stmt->execute();
            $summary_row = $summary_stmt->get_result()->fetch_assoc();
            $summary_stmt->close();

            if ($summary_row) {
                $normal['volume']             = (int)($summary_row['normal_vol'] ?? 0);
                $normal['principal']          = (float)($summary_row['normal_principal'] ?? 0);
                $normal['charge_to_partner']  = (float)($summary_row['normal_ctp'] ?? 0);
                $normal['charge_to_customer'] = (float)($summary_row['normal_ctc'] ?? 0);
                $normal['total_charge']       = $normal['charge_to_partner'] + $normal['charge_to_customer'];

                $adjustment['volume']             = (int)($summary_row['adj_vol'] ?? 0);
                $adjustment['principal']          = (float)($summary_row['adj_principal'] ?? 0);
                $adjustment['charge_to_partner']  = (float)($summary_row['adj_ctp'] ?? 0);
                $adjustment['charge_to_customer'] = (float)($summary_row['adj_ctc'] ?? 0);
                $adjustment['total_charge']       = $adjustment['charge_to_partner'] + $adjustment['charge_to_customer'];
            }

            // ============================================
            // COUNT QUERY (for pagination)
            // ============================================
            $count_sql = "SELECT COUNT(*) AS total FROM ( $union_sql ) AS combined";
            $count_stmt = $conn->prepare($count_sql);
            if (!empty($union_params)) {
                $count_stmt->bind_param($union_types, ...$union_params);
            }
            $count_stmt->execute();
            $total_records = (int)($count_stmt->get_result()->fetch_assoc()['total'] ?? 0);
            $count_stmt->close();
            $total_pages = max(1, (int)ceil($total_records / $per_page));

            if ($page > $total_pages) {
                $page = $total_pages;
                $offset = ($page - 1) * $per_page;
            }

            // ============================================
            // DATA QUERY (paginated)
            // ============================================
            $data_sql = $union_sql . " ORDER BY datetime ASC LIMIT ? OFFSET ?";
            $data_stmt = $conn->prepare($data_sql);
            $data_params = $union_params;
            $data_types  = $union_types . "ii";
            $data_params[] = $per_page;
            $data_params[] = $offset;

            if (!empty($data_params)) {
                $data_stmt->bind_param($data_types, ...$data_params);
            }
            $data_stmt->execute();
            $data_result = $data_stmt->get_result();
            while ($row = $data_result->fetch_assoc()) {
                $transactions[] = $row;
            }
            $data_stmt->close();
        }

    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// ============================================
// Net values
// ============================================
$net = [
    'volume'             => $normal['volume'] - $adjustment['volume'],
    'principal'          => $normal['principal'] - $adjustment['principal'],
    'charge_to_partner'  => $normal['charge_to_partner'] - $adjustment['charge_to_partner'],
    'charge_to_customer' => $normal['charge_to_customer'] - $adjustment['charge_to_customer'],
    'total_charge'       => $normal['total_charge'] - $adjustment['total_charge']
];
$settlement_amount = $net['principal'] - $net['total_charge'];

// Helper to build pagination URLs preserving filters
function build_page_url($page_num) {
    $params = $_GET;
    $params['page'] = $page_num;
    return '?' . http_build_query($params);
}
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
                            <a href="<?php echo $_SERVER['PHP_SELF']; ?>">Reset</a>
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
                        <span class="badge bg-danger rounded-pill"><?php echo number_format($total_records); ?> records found</span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if ($has_filters): ?>
                        <?php if (!empty($transactions)): ?>
                            <div class="summary-wrapper">
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

                                        <!-- Adjustment Summary -->
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
                                                    <i class="fa-solid fa-hand-holding-dollar me-1"></i> Settlement Amount: ₱ <?php echo number_format($settlement_amount, 2); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="summary-right">
                                    <div class="table-responsive">
                                        <table class="table table-hover table-striped table-bordered">
                                            <thead>
                                                <tr>
                                                    <th colspan="10"></th>
                                                    <th colspan="2">Charge to</th>
                                                    <th colspan="4"></th>
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
                                                    <th>Import Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                $counter = $offset + 1;
                                                foreach ($transactions as $transaction):
                                                    $status = (empty($transaction['status']) || $transaction['status'] === null) ? 'Active' : 'Cancelled';
                                                    $statusClass = ($status === 'Active') ? 'badge-active' : 'badge-cancelled';

                                                    $settlementStatus = $transaction['settle_unsettle'] ?? '-';
                                                    $settlementClass = (strtolower($settlementStatus) === 'settled' || strtolower($settlementStatus) === 'yes') ? 'badge-settled' : 'badge-unsettled';

                                                    $cadStatus = $transaction['post_transaction'] ?? '-';
                                                    $cadClass = (strtolower($cadStatus) === 'posted' || strtolower($cadStatus) === 'yes') ? 'badge-posted' : 'badge-unposted';

                                                    // Resolve partner name from lookup; fall back to transaction's own partner_name
                                                    $partner_id = $transaction['partner_id_kpx'] ?? '';
                                                    $is_fallback = false;

                                                    if (isset($partner_lookup[$partner_id])) {
                                                        $partner_name = $partner_lookup[$partner_id];
                                                    } elseif (!empty($transaction['partner_name'])) {
                                                        $partner_name = $transaction['partner_name'];
                                                        $is_fallback = true;
                                                    } else {
                                                        $partner_name = '-';
                                                    }

                                                             // Import Status (Late Posting flag)
                                                    $importStatus = trim((string)($transaction['import_status'] ?? ''));
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
                                                    <td class="<?php echo $is_fallback ? 'partner-fallback' : ''; ?>">
    <?php echo htmlspecialchars($partner_name); ?>
</td>
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
                                                    <td>
                                                        <?php if ($importStatus === ''): ?>
                                                            <span class="import-status-dash">-</span>
                                                        <?php elseif (strcasecmp($importStatus, 'Late Posting') === 0): ?>
                                                            <span class="badge-late-posting">
                                                                <i class="fa-solid fa-clock"></i> Late Posting
                                                            </span>
                                                        <?php else: ?>
                                                            <?php echo htmlspecialchars($importStatus); ?>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>

                                    <!-- Pagination -->
                                    <?php if ($total_pages > 1): ?>
                                        <nav aria-label="Transaction pagination" class="mt-3">
                                            <ul class="pagination justify-content-center flex-wrap">
                                                <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                                                    <a class="page-link" href="<?php echo ($page <= 1) ? '#' : htmlspecialchars(build_page_url(1)); ?>">First</a>
                                                </li>
                                                <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                                                    <a class="page-link" href="<?php echo ($page <= 1) ? '#' : htmlspecialchars(build_page_url($page - 1)); ?>">Prev</a>
                                                </li>
                                                <?php
                                                $start = max(1, $page - 2);
                                                $end   = min($total_pages, $page + 2);
                                                for ($i = $start; $i <= $end; $i++):
                                                ?>
                                                    <li class="page-item <?php echo ($i === $page) ? 'active' : ''; ?>">
                                                        <a class="page-link" href="<?php echo htmlspecialchars(build_page_url($i)); ?>"><?php echo $i; ?></a>
                                                    </li>
                                                <?php endfor; ?>
                                                <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
                                                    <a class="page-link" href="<?php echo ($page >= $total_pages) ? '#' : htmlspecialchars(build_page_url($page + 1)); ?>">Next</a>
                                                </li>
                                                <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
                                                    <a class="page-link" href="<?php echo ($page >= $total_pages) ? '#' : htmlspecialchars(build_page_url($total_pages)); ?>">Last</a>
                                                </li>
                                            </ul>
                                            <p class="text-center text-muted small">
                                                Page <?php echo $page; ?> of <?php echo $total_pages; ?> — showing <?php echo count($transactions); ?> of <?php echo number_format($total_records); ?> records
                                            </p>
                                        </nav>
                                    <?php endif; ?>
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