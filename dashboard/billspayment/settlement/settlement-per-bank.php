<?php
// Add cache control headers to prevent browser caching
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// Connect to the database
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../vendor/autoload.php';

// Start the session
session_start();
@include_once __DIR__ . '/../../../templates/middleware.php';
$id = resolve_user_identifier();
if (empty($id)) { header('Location: ../../../login_form.php'); exit; }
if (!function_exists('has_any_permission') || !has_any_permission(['Settlement Per Bank','Bills Payment'])) { header('Location: ../../home.php'); exit; }

// prefer explicit session values for current user email; don't gate on role
$current_user_email = $_SESSION['admin_email'] ?? $_SESSION['user_email'] ?? '';

// Fetch filter data using MySQLi
try {
    // Get distinct partners (partner_id_kpx + partner_name)
    $partners_query = "SELECT DISTINCT partner_id_kpx, partner_name FROM masterdata.partner_masterfile WHERE partner_id_kpx IS NOT NULL AND partner_id_kpx != '' ORDER BY partner_name";
    $partners_result = $conn->query($partners_query);
    $partners = [];
    while ($row = $partners_result->fetch_assoc()) {
        $partners[] = $row;
    }

    // Get distinct banks from partner_masterfile
    $banks_query = "SELECT DISTINCT bank FROM masterdata.partner_masterfile WHERE bank IS NOT NULL AND bank != '' ORDER BY bank";
    $banks_result = $conn->query($banks_query);
    $banks = [];
    while ($row = $banks_result->fetch_assoc()) {
        $banks[] = $row;
    }

    // Get distinct settlement types from partner_masterfile
    $settlement_types_query = "SELECT DISTINCT settled_online_check FROM masterdata.partner_masterfile WHERE settled_online_check IS NOT NULL AND settled_online_check != '' ORDER BY settled_online_check";
    $settlement_types_result = $conn->query($settlement_types_query);
    $settlement_types = [];
    while ($row = $settlement_types_result->fetch_assoc()) {
        $settlement_types[] = $row;
    }

} catch (Exception $e) {
    error_log("Error fetching filter data: " . $e->getMessage());
    $partners = [];
    $banks = [];
    $settlement_types = [];
}

// Get filter values from GET parameters with proper sanitization
$selected_partner = isset($_GET['partner']) ? trim($_GET['partner']) : '';
$selected_bank = isset($_GET['bank']) ? trim($_GET['bank']) : '';
$selected_settlement_type = isset($_GET['settlement_type']) ? trim($_GET['settlement_type']) : '';
$selected_date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$selected_date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';

// Debug: Log the actual GET parameters
error_log("Settlement Page - GET parameters: " . print_r($_GET, true));
error_log("Settlement Page - Selected bank: " . $selected_bank);

// Store filters in session to maintain state
if (!empty(array_filter($_GET))) {
    $_SESSION['settlement_filters'] = $_GET;
    error_log("Settlement Page - Stored filters in session: " . print_r($_SESSION['settlement_filters'], true));
}

// Flag to check if filters are applied
$has_filters = !empty(array_filter($_GET));
$has_date_range = !empty($selected_date_from) || !empty($selected_date_to);

// Function to get daily breakdown for a partner (used inline)
function getDailyBreakdown($conn, $partner_id, $bank, $settlement_type, $date_from, $date_to) {
    $where_conditions = [];
    $params = [];
    $types = "";
    
    // Partner filter - required
    $where_conditions[] = "bt.partner_id_kpx = ?";
    $params[] = $partner_id;
    $types .= "s";
    
    // Bank filter - join with partner_masterfile
    if (!empty($bank)) {
        $where_conditions[] = "pm.bank = ?";
        $params[] = $bank;
        $types .= "s";
    }
    
    // Settlement type filter - from partner_masterfile
    if (!empty($settlement_type)) {
        $where_conditions[] = "pm.settled_online_check = ?";
        $params[] = $settlement_type;
        $types .= "s";
    }
    
    // Date range filters - Check both datetime and cancellation_date (same logic as main query)
    if (!empty($date_from) && !empty($date_to)) {
        $where_conditions[] = "(DATE(bt.datetime) BETWEEN ? AND ? OR DATE(bt.cancellation_date) BETWEEN ? AND ?)";
        $params[] = $date_from;
        $params[] = $date_to;
        $params[] = $date_from;
        $params[] = $date_to;
        $types .= "ssss";
    } elseif (!empty($date_from)) {
        $where_conditions[] = "(DATE(bt.datetime) >= ? OR DATE(bt.cancellation_date) >= ?)";
        $params[] = $date_from;
        $params[] = $date_from;
        $types .= "ss";
    } elseif (!empty($date_to)) {
        $where_conditions[] = "(DATE(bt.datetime) <= ? OR DATE(bt.cancellation_date) <= ?)";
        $params[] = $date_to;
        $params[] = $date_to;
        $types .= "ss";
    }
    
    // Build the daily breakdown query
    $sql = "SELECT 
                DATE(bt.datetime) as transaction_date,
                COUNT(*) as txn_count,
                SUM(CASE WHEN bt.amount_paid > 0 THEN bt.amount_paid ELSE 0 END) as total_principal,
                (SUM(bt.charge_to_customer) + SUM(bt.charge_to_partner)) as total_charge,
                SUM(CASE WHEN bt.amount_paid < 0 THEN bt.amount_paid ELSE 0 END) as total_adjustment,
                SUM(bt.amount_paid) + (SUM(bt.charge_to_customer) + SUM(bt.charge_to_partner)) as amount_for_settlement
            FROM mldb.billspayment_transaction bt
            LEFT JOIN masterdata.partner_masterfile pm ON bt.partner_id_kpx = pm.partner_id_kpx";
    
    if (!empty($where_conditions)) {
        $sql .= " WHERE " . implode(" AND ", $where_conditions);
    }
    
    $sql .= " GROUP BY DATE(bt.datetime)
              ORDER BY transaction_date ASC";
    
    // Execute with prepared statement
    if (!empty($params)) {
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            $data = [];
            while ($row = $result->fetch_assoc()) {
                $data[] = [
                    'transaction_date' => $row['transaction_date'],
                    'txn_count' => (int)($row['txn_count'] ?? 0),
                    'total_principal' => (float)($row['total_principal'] ?? 0),
                    'total_charge' => (float)($row['total_charge'] ?? 0),
                    'total_adjustment' => (float)($row['total_adjustment'] ?? 0),
                    'amount_for_settlement' => (float)($row['amount_for_settlement'] ?? 0)
                ];
            }
            return $data;
        }
    }
    return [];
}

// Function to generate daily breakdown HTML
function generateDailyBreakdownHTML($data) {
    if (empty($data)) {
        return '<div style="text-align: center; padding: 10px; color: #6c757d;">No daily transactions found for this date range.</div>';
    }
    
    $html = '<table class="daily-breakdown-table" style="width: 100%; border-collapse: collapse; margin: 5px 0;">';
    $html .= '<thead>';
    $html .= '<tr style="background-color: #e9ecef; font-size: 12px;">';
    $html .= '<th style="padding: 6px 12px; text-align: center; width: 120px;">Date</th>';
    $html .= '<th style="padding: 6px 12px; text-align: center;">Volume Count</th>';
    $html .= '<th style="padding: 6px 12px; text-align: right;">Principal</th>';
    $html .= '<th style="padding: 6px 12px; text-align: right;">Charge</th>';
    $html .= '<th style="padding: 6px 12px; text-align: right;">Adjustment</th>';
    $html .= '<th style="padding: 6px 12px; text-align: right;">Settlement</th>';
    $html .= '</tr>';
    $html .= '</thead>';
    $html .= '<tbody>';
    
    $dailyTotals = [
        'txn_count' => 0,
        'principal' => 0,
        'charge' => 0,
        'adjustment' => 0,
        'settlement' => 0
    ];
    
    foreach ($data as $daily) {
        $dailyTotals['txn_count'] += $daily['txn_count'];
        $dailyTotals['principal'] += $daily['total_principal'];
        $dailyTotals['charge'] += $daily['total_charge'];
        $dailyTotals['adjustment'] += $daily['total_adjustment'];
        $dailyTotals['settlement'] += $daily['amount_for_settlement'];
        
        $adjClass = '';
        $adjSign = '';
        $adjAmount = $daily['total_adjustment'];
        if ($adjAmount < 0) {
            $adjClass = 'color: #dc3545;';
        } else if ($adjAmount > 0) {
            $adjClass = 'color: #28a745;';
        }
        $adjSign = $adjAmount >= 0 ? '+' : '';
        
        $settleClass = $daily['amount_for_settlement'] < 0 ? 'color: #dc3545;' : '';
        
        $html .= '<tr class="daily-breakdown-row" style="font-size: 13px;">';
        $html .= '<td style="padding: 6px 12px; text-align: center; font-weight: 500; color: #495057;">' . 
            date('M d, Y', strtotime($daily['transaction_date'])) . '</td>';
        $html .= '<td style="padding: 6px 12px; text-align: center;">' . 
            number_format($daily['txn_count'], 0) . '</td>';
        $html .= '<td style="padding: 6px 12px; text-align: right;">₱ ' . 
            number_format($daily['total_principal'], 2) . '</td>';
        $html .= '<td style="padding: 6px 12px; text-align: right;">₱ ' . 
            number_format($daily['total_charge'], 2) . '</td>';
        $html .= '<td style="padding: 6px 12px; text-align: right; ' . $adjClass . '">' . 
            $adjSign . '₱ ' . number_format($daily['total_adjustment'], 2) . '</td>';
        $html .= '<td style="padding: 6px 12px; text-align: right; font-weight: 600; ' . $settleClass . '">₱ ' . 
            number_format($daily['amount_for_settlement'], 2) . '</td>';
        $html .= '</tr>';
    }
    
    // Daily subtotal
    $adjClass = $dailyTotals['adjustment'] < 0 ? 'color: #dc3545;' : ($dailyTotals['adjustment'] > 0 ? 'color: #28a745;' : '');
    $adjSign = $dailyTotals['adjustment'] >= 0 ? '+' : '';
    $settleClass = $dailyTotals['settlement'] < 0 ? 'color: #dc3545;' : '';
    
    $html .= '<tr class="daily-subtotal-row" style="font-size: 13px; font-weight: 600; background-color: #e9ecef;">';
    $html .= '<td style="padding: 6px 12px; text-align: right; font-weight: 600;">DAILY SUBTOTAL</td>';
    $html .= '<td style="padding: 6px 12px; text-align: center;">' . number_format($dailyTotals['txn_count'], 0) . '</td>';
    $html .= '<td style="padding: 6px 12px; text-align: right;">₱ ' . number_format($dailyTotals['principal'], 2) . '</td>';
    $html .= '<td style="padding: 6px 12px; text-align: right;">₱ ' . number_format($dailyTotals['charge'], 2) . '</td>';
    $html .= '<td style="padding: 6px 12px; text-align: right; ' . $adjClass . '">' . 
        $adjSign . '₱ ' . number_format($dailyTotals['adjustment'], 2) . '</td>';
    $html .= '<td style="padding: 6px 12px; text-align: right; ' . $settleClass . '">₱ ' . 
        number_format($dailyTotals['settlement'], 2) . '</td>';
    $html .= '</tr>';
    
    $html .= '</tbody></table>';
    return $html;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settlement Per Bank | <?php if($_SESSION['user_type'] === 'admin' || $_SESSION['user_type'] === 'user') echo ucfirst($_SESSION['user_type']); else echo "Guest";?></title>
    <!-- custom CSS file link  -->
    <link rel="stylesheet" href="../../../assets/css/templates/style.css?v=<?php echo time(); ?>">
    <script src="https://kit.fontawesome.com/30b908cc5a.js" crossorigin="anonymous"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="../../../assets/js/sweetalert2.all.min.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
    <link rel="icon" href="../../../images/MLW logo.png" type="image/png">
    <link rel="stylesheet" href="css/settlement_bank.css?v=<?= time(); ?>">
    <style>
        .chevron-toggle {
            cursor: pointer;
            transition: transform 0.3s ease;
            display: inline-block;
            width: 20px;
            text-align: center;
            font-size: 14px;
            color: #007bff;
        }
        .chevron-toggle.expanded {
            transform: rotate(180deg);
        }
        .chevron-toggle:hover {
            color: #0056b3;
        }
        .chevron-toggle.loading {
            color: #ffc107;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .daily-breakdown-row {
            background-color: #f8f9fa !important;
        }
        .daily-breakdown-row td {
            padding: 8px 12px !important;
            font-size: 13px !important;
            border-bottom: 1px dashed #dee2e6 !important;
        }
        .daily-breakdown-row .date-cell {
            font-weight: 500;
            color: #495057;
        }
        .daily-breakdown-row .daily-total {
            font-weight: 600;
        }
        .daily-breakdown-row .daily-settlement {
            font-weight: 600;
        }
        .daily-subtotal-row {
            background-color: #e9ecef !important;
        }
        .daily-subtotal-row td {
            padding: 8px 12px !important;
            font-weight: 600;
            border-top: 2px solid #dee2e6 !important;
        }
        .chevron-placeholder {
            display: inline-block;
            width: 20px;
        }
        .data-row .partner-name-cell {
            position: relative;
        }
        .daily-breakdown-container {
            animation: slideDown 0.3s ease;
        }
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        .daily-breakdown-row .txn-count,
        .daily-breakdown-row .amount-col {
            font-size: 13px !important;
        }
        .daily-breakdown-loading {
            text-align: center;
            padding: 15px !important;
            color: #6c757d;
        }
        .daily-breakdown-loading i {
            font-size: 24px;
            margin-bottom: 5px;
            display: block;
        }
        .daily-breakdown-error {
            text-align: center;
            padding: 15px !important;
            color: #dc3545;
        }
        .daily-breakdown-error i {
            font-size: 24px;
            margin-bottom: 5px;
            display: block;
        }
        .daily-breakdown-container td {
            padding: 0 !important;
        }
        .daily-breakdown-table {
            width: 100% !important;
        }
        .daily-breakdown-table td, 
        .daily-breakdown-table th {
            padding: 6px 12px !important;
        }
    </style>
</head>
<body>
    <!-- Loading Modal - Visible by default -->
    <div id="loadingModal">
        <div class="loading-content">
            <div class="loading-spinner">
                <div class="spinner-ring"></div>
                <i class="fas fa-chart-line spinner-icon"></i>
            </div>
            <h3 class="loading-title">Loading Settlement Data</h3>
            <p class="loading-subtitle">Please wait while we fetch your data<span class="dots"></span></p>
            
            <div class="loading-progress-container">
                <div class="loading-progress">
                    <div class="loading-progress-bar" id="progressBar"></div>
                </div>
            </div>
            
            <div class="loading-steps">
                <div class="step active" id="step1">
                    <div class="step-icon">
                        <i class="fas fa-database"></i>
                        <span class="step-number">1</span>
                    </div>
                    <span class="step-label">Fetching</span>
                </div>
                <div class="step" id="step2">
                    <div class="step-icon">
                        <i class="fas fa-calculator"></i>
                        <span class="step-number">2</span>
                    </div>
                    <span class="step-label">Processing</span>
                </div>
                <div class="step" id="step3">
                    <div class="step-icon">
                        <i class="fas fa-file-alt"></i>
                        <span class="step-number">3</span>
                    </div>
                    <span class="step-label">Generating</span>
                </div>
            </div>
            
            <div class="loading-time">
                <i class="far fa-clock"></i>
                <span>Elapsed: </span>
                <span class="time-value" id="elapsedTime">0</span>
                <span>s</span>
            </div>
        </div>
    </div>

    <!-- Main Content - Hidden initially -->
    <div class="main-container main-content-hidden" id="mainContent">
        <?php include '../../../templates/header_ui.php'; ?>
        <!-- Show and Hide Side Nav Menu -->
        <?php include '../../../templates/sidebar.php'; ?>

        <div class="bp-section-header" role="region" aria-label="Page title">
            <div class="bp-section-title">
                <i class="fa-solid fa-layer-group" aria-hidden="true"></i>
                <div>
                    <h2>Settlement Per Bank</h2>
                </div>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="filter-section">
            <form method="GET" action="" class="filter-form" id="filterForm">
                <!-- Partner Filter -->
                <div class="filter-group">
                    <label for="partner">Partner</label>
                    <select id="partner" name="partner" class="select2-dropdown" data-selected="<?php echo htmlspecialchars($selected_partner); ?>">
                        <option value="">All Partners</option>
                        <?php foreach ($partners as $partner): ?>
                            <option value="<?php echo htmlspecialchars($partner['partner_id_kpx']); ?>" 
                                <?php echo ($selected_partner == $partner['partner_id_kpx']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($partner['partner_id_kpx'] . ' - ' . $partner['partner_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Bank Filter -->
                <div class="filter-group">
                    <label for="bank">Bank</label>
                    <select id="bank" name="bank" class="select2-dropdown" data-selected="<?php echo htmlspecialchars($selected_bank); ?>">
                        <option value="">All Banks</option>
                        <?php foreach ($banks as $bank): ?>
                            <option value="<?php echo htmlspecialchars($bank['bank']); ?>" 
                                <?php echo ($selected_bank == $bank['bank']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($bank['bank']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Settlement Type Filter -->
                <div class="filter-group">
                    <label for="settlement_type">Settlement Type</label>
                    <select id="settlement_type" name="settlement_type" class="select2-dropdown" data-selected="<?php echo htmlspecialchars($selected_settlement_type); ?>">
                        <option value="">All Types</option>
                        <?php foreach ($settlement_types as $type): ?>
                            <option value="<?php echo htmlspecialchars($type['settled_online_check']); ?>" 
                                <?php echo ($selected_settlement_type == $type['settled_online_check']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($type['settled_online_check']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Date Range Filters -->
                <div class="filter-group">
                    <label for="date_from">Transaction Date From</label>
                    <input type="date" id="date_from" name="date_from" 
                           value="<?php echo htmlspecialchars($selected_date_from); ?>">
                </div>

                <div class="filter-group">
                    <label for="date_to">Transaction Date To</label>
                    <input type="date" id="date_to" name="date_to" 
                           value="<?php echo htmlspecialchars($selected_date_to); ?>">
                </div>

                <!-- Action Buttons -->
                <div class="filter-actions">
                    <button type="submit" class="btn-filter" id="filterBtn">
                        <i class="fas fa-search" aria-hidden="true"></i> Filter
                    </button>
                    <a href="settlement-per-bank.php" class="btn-reset"><i class="fas fa-undo" aria-hidden="true"></i> Reset</a>
                </div>
            </form>
        </div>

        <!-- Results Section -->
        <div id="resultsContainer">
        <?php
        // Process the filters and display results
        if ($has_filters) {
            try {
                // Build the WHERE clause for the main query
                $where_conditions = [];
                $params = [];
                $types = "";
                
                // Partner filter
                if (!empty($selected_partner)) {
                    $where_conditions[] = "bt.partner_id_kpx = ?";
                    $params[] = $selected_partner;
                    $types .= "s";
                }
                
                // Bank filter - join with partner_masterfile
                if (!empty($selected_bank)) {
                    $where_conditions[] = "pm.bank = ?";
                    $params[] = $selected_bank;
                    $types .= "s";
                }
                
                // Settlement type filter - from partner_masterfile
                if (!empty($selected_settlement_type)) {
                    $where_conditions[] = "pm.settled_online_check = ?";
                    $params[] = $selected_settlement_type;
                    $types .= "s";
                }
                
                // Date range filters - Check both datetime and cancellation_date
                if (!empty($selected_date_from) && !empty($selected_date_to)) {
                    // When both dates are provided, check both datetime and cancellation_date
                    $where_conditions[] = "(DATE(bt.datetime) BETWEEN ? AND ? OR DATE(bt.cancellation_date) BETWEEN ? AND ?)";
                    $params[] = $selected_date_from;
                    $params[] = $selected_date_to;
                    $params[] = $selected_date_from;
                    $params[] = $selected_date_to;
                    $types .= "ssss";
                } elseif (!empty($selected_date_from)) {
                    // Only from date provided
                    $where_conditions[] = "(DATE(bt.datetime) >= ? OR DATE(bt.cancellation_date) >= ?)";
                    $params[] = $selected_date_from;
                    $params[] = $selected_date_from;
                    $types .= "ss";
                } elseif (!empty($selected_date_to)) {
                    // Only to date provided
                    $where_conditions[] = "(DATE(bt.datetime) <= ? OR DATE(bt.cancellation_date) <= ?)";
                    $params[] = $selected_date_to;
                    $params[] = $selected_date_to;
                    $types .= "ss";
                }
                
                // Build the full query with JOIN to get all partner data from partner_masterfile
                $sql = "SELECT 
                            bt.partner_id_kpx,
                            pm.partner_name,
                            pm.partner_accName,
                            pm.bank_accNumber,
                            pm.bank,
                            pm.settled_online_check as settlement_type,
                            pm.charge_to,
                            pm.serviceCharge,
                            COUNT(*) as txn_count,
                            SUM(CASE WHEN bt.amount_paid > 0 THEN bt.amount_paid ELSE 0 END) as total_principal,
                            (SUM(bt.charge_to_customer) + SUM(bt.charge_to_partner)) as total_charge,
                            SUM(CASE WHEN bt.amount_paid < 0 THEN bt.amount_paid ELSE 0 END) as total_adjustment,
                            SUM(bt.amount_paid) + (SUM(bt.charge_to_customer) + SUM(bt.charge_to_partner)) as amount_for_settlement,
                            MAX(bt.datetime) as last_transaction_date,
                            MIN(bt.datetime) as first_transaction_date
                        FROM mldb.billspayment_transaction bt
                        LEFT JOIN masterdata.partner_masterfile pm ON bt.partner_id_kpx = pm.partner_id_kpx";
                
                if (!empty($where_conditions)) {
                    $sql .= " WHERE " . implode(" AND ", $where_conditions);
                }
                
                $sql .= " GROUP BY bt.partner_id_kpx, pm.partner_name, pm.partner_accName, pm.bank_accNumber, pm.bank, pm.settled_online_check, pm.charge_to, pm.serviceCharge 
                          ORDER BY 
                            CASE 
                                WHEN pm.charge_to = 'CUSTOMER' AND pm.serviceCharge = 'DAILY' THEN 1
                                WHEN pm.charge_to = 'CUSTOMER' AND pm.serviceCharge = 'WEEKLY' THEN 2
                                WHEN pm.charge_to = 'PARTNER' AND pm.serviceCharge = 'DAILY' THEN 3
                                WHEN pm.charge_to = 'PARTNER' AND pm.serviceCharge = 'WEEKLY' THEN 4
                                WHEN pm.charge_to = 'PARTNER' AND pm.serviceCharge = 'SEMI-MONTHLY' THEN 5
                                WHEN pm.charge_to = 'PARTNER' AND pm.serviceCharge = 'MONTHLY' THEN 6
                                ELSE 7
                            END,
                            pm.partner_name";
                
                // Execute with prepared statement
                if (!empty($params)) {
                    $stmt = $conn->prepare($sql);
                    if ($stmt) {
                        $stmt->bind_param($types, ...$params);
                        $stmt->execute();
                        $result = $stmt->get_result();
                    } else {
                        error_log("Settlement - Prepare failed: " . $conn->error);
                        $result = false;
                    }
                } else {
                    $result = $conn->query($sql);
                }
                
                if ($result && $result->num_rows > 0) {
                    // Initialize arrays for grouping
                    $groups = [
                        'CHARGE BY CUSTOMER DAILY' => [
                            'display_name' => 'NOTE: CHARGE BY CUSTOMER DAILY',
                            'icon' => 'fa-user',
                            'rows' => [],
                            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge' => 0, 'adjustment' => 0, 'settlement' => 0]
                        ],
                        'CHARGE BY CUSTOMER WEEKLY' => [
                            'display_name' => 'NOTE: CHARGE BY CUSTOMER WEEKLY',
                            'icon' => 'fa-user-clock',
                            'rows' => [],
                            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge' => 0, 'adjustment' => 0, 'settlement' => 0]
                        ],
                        'CHARGE BY PARTNER DAILY' => [
                            'display_name' => 'NOTE: CHARGE BY PARTNER DAILY',
                            'icon' => 'fa-calendar-day',
                            'rows' => [],
                            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge' => 0, 'adjustment' => 0, 'settlement' => 0]
                        ],
                        'CHARGE BY PARTNER WEEKLY' => [
                            'display_name' => 'NOTE: CHARGE BY PARTNER WEEKLY',
                            'icon' => 'fa-calendar-week',
                            'rows' => [],
                            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge' => 0, 'adjustment' => 0, 'settlement' => 0]
                        ],
                        'CHARGE BY PARTNER SEMI MONTHLY' => [
                            'display_name' => 'NOTE: CHARGE BY PARTNER SEMI-MONTHLY',
                            'icon' => 'fa-calendar-alt',
                            'rows' => [],
                            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge' => 0, 'adjustment' => 0, 'settlement' => 0]
                        ],
                        'CHARGE BY PARTNER MONTHLY' => [
                            'display_name' => 'NOTE: CHARGE BY PARTNER MONTHLY',
                            'icon' => 'fa-calendar-check',
                            'rows' => [],
                            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge' => 0, 'adjustment' => 0, 'settlement' => 0]
                        ]
                    ];
                    
                    // Initialize grand totals
                    $grand_totals = ['txn_count' => 0, 'principal' => 0, 'charge' => 0, 'adjustment' => 0, 'settlement' => 0];
                    
                    $row_index = 0;
                    // Pre-fetch daily breakdown data for all partners (only if date range is selected)
                    $daily_breakdown_cache = [];
                    if ($has_date_range) {
                        while ($row = $result->fetch_assoc()) {
                            $partner_id = $row['partner_id_kpx'];
                            $daily_data = getDailyBreakdown(
                                $conn, 
                                $partner_id, 
                                $selected_bank, 
                                $selected_settlement_type, 
                                $selected_date_from, 
                                $selected_date_to
                            );
                            $daily_breakdown_cache[$partner_id] = $daily_data;
                        }
                        // Reset the result pointer to fetch again for the main display
                        $result->data_seek(0);
                    }
                    
                    while ($row = $result->fetch_assoc()) {
                        $charge_to = strtoupper($row['charge_to'] ?? '');
                        $serviceCharge = strtoupper($row['serviceCharge'] ?? '');
                        
                        // Determine which group this belongs to
                        $group_key = null;
                        if ($charge_to === 'CUSTOMER') {
                            if ($serviceCharge === 'DAILY') {
                                $group_key = 'CHARGE BY CUSTOMER DAILY';
                            } elseif ($serviceCharge === 'WEEKLY') {
                                $group_key = 'CHARGE BY CUSTOMER WEEKLY';
                            }
                        } elseif ($charge_to === 'PARTNER') {
                            if ($serviceCharge === 'DAILY') {
                                $group_key = 'CHARGE BY PARTNER DAILY';
                            } elseif ($serviceCharge === 'WEEKLY') {
                                $group_key = 'CHARGE BY PARTNER WEEKLY';
                            } elseif ($serviceCharge === 'SEMI-MONTHLY') {
                                $group_key = 'CHARGE BY PARTNER SEMI MONTHLY';
                            } elseif ($serviceCharge === 'MONTHLY') {
                                $group_key = 'CHARGE BY PARTNER MONTHLY';
                            }
                        }
                        
                        if ($group_key === null) {
                            continue;
                        }
                        
                        $txn_count = (int)($row['txn_count'] ?? 0);
                        $principal = (float)($row['total_principal'] ?? 0);
                        $charge = (float)($row['total_charge'] ?? 0);
                        $adjustment = (float)($row['total_adjustment'] ?? 0);
                        $settlement_amount = (float)($row['amount_for_settlement'] ?? 0);
                        
                        // Add to group totals
                        $groups[$group_key]['totals']['txn_count'] += $txn_count;
                        $groups[$group_key]['totals']['principal'] += $principal;
                        $groups[$group_key]['totals']['charge'] += $charge;
                        $groups[$group_key]['totals']['adjustment'] += $adjustment;
                        $groups[$group_key]['totals']['settlement'] += $settlement_amount;
                        
                        // Add to grand totals
                        $grand_totals['txn_count'] += $txn_count;
                        $grand_totals['principal'] += $principal;
                        $grand_totals['charge'] += $charge;
                        $grand_totals['adjustment'] += $adjustment;
                        $grand_totals['settlement'] += $settlement_amount;
                        
                        // Get daily breakdown from cache
                        $partner_id = $row['partner_id_kpx'];
                        $daily_data = $has_date_range && isset($daily_breakdown_cache[$partner_id]) 
                            ? $daily_breakdown_cache[$partner_id] 
                            : [];
                        $daily_html = !empty($daily_data) ? generateDailyBreakdownHTML($daily_data) : '';
                        
                        // Store row data with daily breakdown included
                        $groups[$group_key]['rows'][] = [
                            'row_index' => $row_index,
                            'partner_id' => $partner_id,
                            'partner_name' => $row['partner_name'] ?? $row['partner_id_kpx'],
                            'account_name' => $row['partner_accName'] ?? 'N/A',
                            'account_number' => $row['bank_accNumber'] ?? 'N/A',
                            'txn_count' => $txn_count,
                            'principal' => $principal,
                            'charge' => $charge,
                            'adjustment' => $adjustment,
                            'settlement_amount' => $settlement_amount,
                            'is_negative' => $settlement_amount < 0,
                            'excluded' => false,
                            'has_daily_breakdown' => $has_date_range,
                            'daily_html' => $daily_html
                        ];
                        $row_index++;
                    }
                    
                    // Remove empty groups
                    $groups = array_filter($groups, function($group) {
                        return !empty($group['rows']);
                    });
                    ?>
                    
                    <div class="table-container">
                        <div class="table-header">
                            <h3 class="table-title">
                                <i class="fas fa-file-invoice" aria-hidden="true"></i> Settlement Summary
                            </h3>
                            <span class="table-badge">
                                <i class="fas fa-layer-group"></i> Total Partners: <?php 
                                    $total_partners = 0;
                                    foreach ($groups as $group) {
                                        $total_partners += count($group['rows']);
                                    }
                                    echo $total_partners; 
                                ?>
                            </span>
                            <?php if (!empty($selected_bank)): ?>
                            <span class="table-badge" style="background: #007bff; color: white;">
                                <i class="fas fa-university"></i> Bank: <?php echo htmlspecialchars($selected_bank); ?>
                            </span>
                            <?php endif; ?>
                            <?php if ($has_date_range): ?>
                            <span class="table-badge" style="background: #009022; color: white;">
                                <i class="fas fa-calendar-alt"></i> 
                                <?php 
                                $date_range = '';
                                if (!empty($selected_date_from)) $date_range .= 'From: ' . date('M d, Y', strtotime($selected_date_from));
                                if (!empty($selected_date_to)) $date_range .= ' To: ' . date('M d, Y', strtotime($selected_date_to));
                                echo htmlspecialchars($date_range);
                                ?>
                            </span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="table-controls">
                            <div class="checkbox-controls">
                                <label class="checkbox-label">
                                    <input type="checkbox" id="selectAllRows" onchange="toggleAllRows(this)">
                                    <span>Select/Deselect All</span>
                                </label>
                                <button class="btn-recalculate" onclick="recalculateTotals()">
                                    <i class="fas fa-calculator"></i> Recalculate Totals
                                </button>
                            </div>
                        </div>
                        
                        <table class="settlement-table" id="settlementTable">
                            <thead>
                                <tr>
                                    <th class="center" style="width: 40px;">
                                        <input type="checkbox" id="selectAllHeader" onchange="toggleAllRows(this)">
                                    </th>
                                    <th class="center" style="width: 30px;"></th>
                                    <th class="center">LIST OF BILLS PAYMENT PARTNER</th>
                                    <th class="center">ACCOUNT NAME</th>
                                    <th class="center">ACCOUNT NUMBER</th>
                                    <th class="center">VOLUME COUNT</th>
                                    <th class="center">PRINCIPAL</th>
                                    <th class="center">CHARGE</th>
                                    <th class="center">ADJUSTMENT (add/less)</th>
                                    <th class="center settlement-col">AMOUNT FOR SETTLEMENT</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $group_index = 0;
                                foreach ($groups as $group_key => $group_data): 
                                    $group_index++;
                                    $is_last_group = $group_index === count($groups);
                                ?>
                                    <!-- Group Header -->
                                    <tr class="group-header-row">
                                        <td colspan="10">
                                            <i class="fas <?php echo $group_data['icon']; ?>"></i>
                                            <?php echo htmlspecialchars($group_data['display_name']); ?>
                                        </td>
                                    </tr>
                                    
                                    <!-- Group Data Rows -->
                                    <?php foreach ($group_data['rows'] as $row_data): ?>
                                        <tr class="data-row <?php echo $row_data['is_negative'] ? 'negative-row' : ''; ?>" 
                                            data-row-index="<?php echo $row_data['row_index']; ?>"
                                            data-settlement="<?php echo $row_data['settlement_amount']; ?>"
                                            data-principal="<?php echo $row_data['principal']; ?>"
                                            data-charge="<?php echo $row_data['charge']; ?>"
                                            data-adjustment="<?php echo $row_data['adjustment']; ?>"
                                            data-txn-count="<?php echo $row_data['txn_count']; ?>"
                                            data-partner-id="<?php echo $row_data['partner_id']; ?>">
                                            <td class="center checkbox-cell">
                                                <input type="checkbox" class="row-checkbox" 
                                                       data-row-index="<?php echo $row_data['row_index']; ?>"
                                                       onchange="updateTotals()" checked>
                                            </td>
                                            <td class="center">
                                                <?php if ($row_data['has_daily_breakdown'] && !empty($row_data['daily_html'])): ?>
                                                    <span class="chevron-toggle" 
                                                          data-partner-id="<?php echo $row_data['partner_id']; ?>"
                                                          data-row-index="<?php echo $row_data['row_index']; ?>"
                                                          onclick="toggleDailyBreakdown(this, <?php echo $row_data['row_index']; ?>)">
                                                        <i class="fas fa-chevron-down"></i>
                                                    </span>
                                                <?php elseif ($row_data['has_daily_breakdown'] && empty($row_data['daily_html'])): ?>
                                                    <span class="chevron-placeholder"></span>
                                                <?php else: ?>
                                                    <span class="chevron-placeholder"></span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="partner-name-cell">
                                                <?php echo htmlspecialchars($row_data['partner_name']); ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($row_data['account_name']); ?></td>
                                            <td class="center"><?php echo htmlspecialchars($row_data['account_number']); ?></td>
                                            <td class="center txn-count"><?php echo number_format($row_data['txn_count']); ?></td>
                                            <td class="right amount-col principal">₱ <?php echo number_format($row_data['principal'], 2); ?></td>
                                            <td class="right amount-col charge">₱ <?php echo number_format($row_data['charge'], 2); ?></td>
                                            <td class="right amount-col adjustment <?php echo $row_data['adjustment'] < 0 ? 'negative-amount' : ($row_data['adjustment'] > 0 ? 'positive-amount' : ''); ?>">
                                                <?php echo ($row_data['adjustment'] >= 0 ? '+' : ''); ?>₱ <?php echo number_format($row_data['adjustment'], 2); ?>
                                            </td>
                                            <td class="right settlement-col settlement-amount <?php echo $row_data['is_negative'] ? 'negative-amount' : ''; ?>">
                                                ₱ <?php echo number_format($row_data['settlement_amount'], 2); ?>
                                            </td>
                                        </tr>
                                        
                                        <!-- Daily Breakdown Container (pre-loaded with HTML) -->
                                        <?php if ($row_data['has_daily_breakdown'] && !empty($row_data['daily_html'])): ?>
                                            <tr class="daily-breakdown-container" 
                                                id="dailyBreakdown_<?php echo $row_data['row_index']; ?>" 
                                                style="display: none;">
                                                <td colspan="10">
                                                    <?php echo $row_data['daily_html']; ?>
                                                </td>
                                            </tr>
                                        <?php elseif ($row_data['has_daily_breakdown'] && empty($row_data['daily_html'])): ?>
                                            <tr class="daily-breakdown-container" 
                                                id="dailyBreakdown_<?php echo $row_data['row_index']; ?>" 
                                                style="display: none;">
                                                <td colspan="10" class="daily-breakdown-error">
                                                    <i class="fas fa-info-circle"></i>
                                                    No daily transactions found for this date range.
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                    
                                    <!-- Group Subtotal -->
                                    <tr class="group-subtotal-row" data-group="<?php echo $group_key; ?>">
                                        <td colspan="5" style="text-align: right;">
                                            <strong>Subtotal - <?php echo htmlspecialchars($group_data['display_name']); ?></strong>
                                        </td>
                                        <td class="center group-txn-count"><?php echo number_format($group_data['totals']['txn_count']); ?></td>
                                        <td class="right group-principal">₱ <?php echo number_format($group_data['totals']['principal'], 2); ?></td>
                                        <td class="right group-charge">₱ <?php echo number_format($group_data['totals']['charge'], 2); ?></td>
                                        <td class="right group-adjustment <?php echo $group_data['totals']['adjustment'] < 0 ? 'negative-amount' : ($group_data['totals']['adjustment'] > 0 ? 'positive-amount' : ''); ?>">
                                            <?php echo ($group_data['totals']['adjustment'] >= 0 ? '+' : ''); ?>₱ <?php echo number_format($group_data['totals']['adjustment'], 2); ?>
                                        </td>
                                        <td class="right settlement-col group-settlement <?php echo $group_data['totals']['settlement'] < 0 ? 'negative-amount' : ''; ?>">
                                            ₱ <?php echo number_format($group_data['totals']['settlement'], 2); ?>
                                        </td>
                                    </tr>
                                    
                                    <?php if (!$is_last_group): ?>
                                        <tr style="height: 8px; background: transparent;">
                                            <td colspan="10" style="border: none; padding: 0;"></td>
                                        </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                
                                <!-- Grand Total Row -->
                                <tr class="grand-total-row">
                                    <td colspan="5" style="text-align: right;">GRAND TOTAL</td>
                                    <td class="center grand-txn-count"><?php echo number_format($grand_totals['txn_count']); ?></td>
                                    <td class="right grand-principal">₱ <?php echo number_format($grand_totals['principal'], 2); ?></td>
                                    <td class="right grand-charge">₱ <?php echo number_format($grand_totals['charge'], 2); ?></td>
                                    <td class="right grand-adjustment <?php echo $grand_totals['adjustment'] < 0 ? 'negative-amount' : ($grand_totals['adjustment'] > 0 ? 'positive-amount' : ''); ?>">
                                        <?php echo ($grand_totals['adjustment'] >= 0 ? '+' : ''); ?>₱ <?php echo number_format($grand_totals['adjustment'], 2); ?>
                                    </td>
                                    <td class="right settlement-col grand-settlement <?php echo $grand_totals['settlement'] < 0 ? 'negative-amount' : ''; ?>">
                                        ₱ <?php echo number_format($grand_totals['settlement'], 2); ?>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        
                        <div class="export-buttons">
                            <button class="btn-export excel" onclick="exportToExcel()">
                                <i class="fas fa-file-excel"></i> Export Excel
                            </button>
                        </div>
                    </div>
                    
                <?php
                } else {
                    echo '<div class="no-records">';
                    echo '<i class="fas fa-inbox"></i>';
                    echo '<p>No records found matching your filters.</p>';
                    echo '<p style="font-size: 14px; margin-top: 5px;">Please try adjusting your filter criteria.</p>';
                    echo '</div>';
                }
                
            } catch (Exception $e) {
                error_log("Error fetching settlement data: " . $e->getMessage());
                echo '<div class="no-records" style="border: 1px solid #dc3545; color: #dc3545;">';
                echo '<i class="fas fa-exclamation-triangle"></i>';
                echo '<p>Error fetching data. Please try again.</p>';
                echo '<p style="font-size: 14px; margin-top: 5px;">' . htmlspecialchars($e->getMessage()) . '</p>';
                echo '</div>';
            }
        } else {
            echo '<div class="no-records">';
            echo '<i class="fas fa-filter"></i>';
            echo '<p>Please select filters and click Filter to view settlement data.</p>';
            echo '<p style="font-size: 14px; margin-top: 5px;">Use the filters above to search for specific settlement records.</p>';
            echo '</div>';
        }
        ?>
        </div>

    </div>
    <?php include '../../../templates/footer.php'; ?>

<script>
    // Global variables
    var loadingTimer = null;
    var seconds = 0;
    var stepInterval = null;
    var isPageLoaded = false;
    
    $(document).ready(function() {
        // Get initial values from PHP data attributes
        var initialPartner = $('#partner').data('selected') || '';
        var initialBank = $('#bank').data('selected') || '';
        var initialSettlement = $('#settlement_type').data('selected') || '';
        
        // Initialize Select2 for better dropdowns
        $('.select2-dropdown').select2({
            placeholder: function() {
                return $(this).data('placeholder') || 'Select an option';
            },
            allowClear: true,
            width: '100%'
        });
        
        // Set initial values for Select2
        if (initialPartner) {
            $('#partner').val(initialPartner).trigger('change');
        }
        if (initialBank) {
            $('#bank').val(initialBank).trigger('change');
        }
        if (initialSettlement) {
            $('#settlement_type').val(initialSettlement).trigger('change');
        }

        // Start the loading timer immediately
        startLoadingTimer();
        
        // Start step animation
        startStepAnimation();

        // Hide loading modal and show content when page is fully loaded
        $(window).on('load', function() {
            isPageLoaded = true;
            setTimeout(function() {
                hideLoadingModal();
                showMainContent();
            }, 500);
        });

        // Also hide loading if there's an error or no content after timeout
        setTimeout(function() {
            if (!isPageLoaded) {
                var hasContent = $('#resultsContainer').children().length > 0;
                if (hasContent) {
                    hideLoadingModal();
                    showMainContent();
                }
            }
        }, 10000);

        // Handle form submission
        $('#filterForm').on('submit', function(e) {
            e.preventDefault();
            
            var hasFilters = false;
            $(this).find('select, input[type="date"]').each(function() {
                var val = $(this).val();
                if (val && val.trim() !== '' && val !== '0') {
                    hasFilters = true;
                    return false;
                }
            });
            
            if (!hasFilters) {
                Swal.fire({
                    icon: 'info',
                    title: 'No Filters Selected',
                    text: 'Please select at least one filter to search.',
                    confirmButtonColor: '#007bff'
                });
                return;
            }
            
            showLoadingModal();
            hideMainContent();
            resetAndStartTimer();
            
            var formData = $(this).serialize();
            
            $.ajax({
                url: window.location.href,
                type: 'GET',
                data: formData,
                dataType: 'html',
                cache: false,
                timeout: 60000,
                success: function(response) {
                    var tempDiv = $('<div>').html(response);
                    var newContent = tempDiv.find('#resultsContainer').html();
                    if (newContent) {
                        $('#resultsContainer').html(newContent);
                    } else {
                        var content = response;
                        var start = content.indexOf('<div id="resultsContainer">');
                        if (start !== -1) {
                            var end = content.indexOf('</div>', start);
                            if (end !== -1) {
                                $('#resultsContainer').html(content.substring(start, end + 6));
                            }
                        }
                    }
                    
                    setTimeout(function() {
                        hideLoadingModal();
                        showMainContent();
                    }, 300);
                },
                error: function(xhr, status, error) {
                    hideLoadingModal();
                    showMainContent();
                    var errorMsg = 'Failed to load data. Please try again.';
                    if (status === 'timeout') {
                        errorMsg = 'Request timed out. The query may be taking too long. Please try with fewer filters.';
                    }
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: errorMsg,
                        confirmButtonColor: '#dc3545'
                    });
                    console.error('AJAX Error:', status, error);
                }
            });
        });
    });

    function showLoadingModal() {
        var modal = document.getElementById('loadingModal');
        if (modal) {
            modal.classList.remove('hidden');
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }
    }

    function hideLoadingModal() {
        var modal = document.getElementById('loadingModal');
        if (modal) {
            modal.classList.add('hidden');
            modal.style.display = 'none';
            document.body.style.overflow = '';
            if (loadingTimer) {
                clearInterval(loadingTimer);
                loadingTimer = null;
            }
            if (stepInterval) {
                clearInterval(stepInterval);
                stepInterval = null;
            }
        }
    }

    function showMainContent() {
        var mainContent = document.getElementById('mainContent');
        if (mainContent) {
            mainContent.classList.remove('main-content-hidden');
            mainContent.classList.add('main-content-visible');
        }
    }

    function hideMainContent() {
        var mainContent = document.getElementById('mainContent');
        if (mainContent) {
            mainContent.classList.remove('main-content-visible');
            mainContent.classList.add('main-content-hidden');
        }
    }

    function startLoadingTimer() {
        if (loadingTimer) {
            clearInterval(loadingTimer);
            loadingTimer = null;
        }
        seconds = 0;
        var elapsedElement = document.getElementById('elapsedTime');
        if (elapsedElement) {
            elapsedElement.textContent = '0';
        }
        loadingTimer = setInterval(function() {
            seconds++;
            var elapsedElement = document.getElementById('elapsedTime');
            if (elapsedElement) {
                elapsedElement.textContent = seconds;
            }
        }, 1000);
    }

    function resetAndStartTimer() {
        if (loadingTimer) {
            clearInterval(loadingTimer);
            loadingTimer = null;
        }
        seconds = 0;
        var elapsedElement = document.getElementById('elapsedTime');
        if (elapsedElement) {
            elapsedElement.textContent = '0';
        }
        resetSteps();
        loadingTimer = setInterval(function() {
            seconds++;
            var elapsedElement = document.getElementById('elapsedTime');
            if (elapsedElement) {
                elapsedElement.textContent = seconds;
            }
        }, 1000);
        startStepAnimation();
    }

    function resetSteps() {
        $('#step1').removeClass('active completed');
        $('#step2').removeClass('active completed');
        $('#step3').removeClass('active completed');
        $('#step1').addClass('active');
    }

    function startStepAnimation() {
        if (stepInterval) {
            clearInterval(stepInterval);
            stepInterval = null;
        }
        var step = 1;
        
        stepInterval = setInterval(function() {
            if (step === 1) {
                $('#step1').removeClass('active').addClass('completed');
                $('#step2').addClass('active');
                step = 2;
            } else if (step === 2) {
                $('#step2').removeClass('active').addClass('completed');
                $('#step3').addClass('active');
                step = 3;
            } else if (step === 3) {
                // Keep step 3 active
            }
        }, 2000);
    }

    function resetFilters() {
        document.getElementById('partner').value = '';
        document.getElementById('bank').value = '';
        document.getElementById('settlement_type').value = '';
        document.getElementById('date_from').value = '';
        document.getElementById('date_to').value = '';
        
        $('.select2-dropdown').val('').trigger('change');
        
        showLoadingModal();
        hideMainContent();
        resetAndStartTimer();
        
        var cleanUrl = window.location.pathname;
        
        $.ajax({
            url: cleanUrl,
            type: 'GET',
            dataType: 'html',
            cache: false,
            timeout: 60000,
            success: function(response) {
                var tempDiv = $('<div>').html(response);
                var newContent = tempDiv.find('#resultsContainer').html();
                if (newContent) {
                    $('#resultsContainer').html(newContent);
                }
                setTimeout(function() {
                    hideLoadingModal();
                    showMainContent();
                }, 300);
            },
            error: function() {
                hideLoadingModal();
                showMainContent();
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Failed to reset filters. Please try again.',
                    confirmButtonColor: '#dc3545'
                });
            }
        });
    }

    // ============================================
    // DAILY BREAKDOWN TOGGLE FUNCTION (now inline)
    // ============================================
    
    function toggleDailyBreakdown(element, rowIndex) {
        var breakdownRow = document.getElementById('dailyBreakdown_' + rowIndex);
        var chevron = element;
        
        if (!breakdownRow) {
            return;
        }
        
        // If already expanded, collapse it
        if (breakdownRow.style.display !== 'none') {
            breakdownRow.style.display = 'none';
            chevron.classList.remove('expanded');
            chevron.innerHTML = '<i class="fas fa-chevron-down"></i>';
            return;
        }
        
        // Show the breakdown (already pre-loaded)
        breakdownRow.style.display = 'table-row';
        chevron.classList.add('expanded');
        chevron.innerHTML = '<i class="fas fa-chevron-up"></i>';
    }

    // ============================================
    // CHECKBOX AND TOTALS RECALCULATION FUNCTIONS
    // ============================================
    
    function toggleAllRows(checkbox) {
        var isChecked = $(checkbox).prop('checked');
        $('.row-checkbox').prop('checked', isChecked);
        updateTotals();
    }
    
    function updateTotals() {
        var dataRows = $('.data-row');
        var groupTotals = {};
        var grandTotals = {
            txn_count: 0,
            principal: 0,
            charge: 0,
            adjustment: 0,
            settlement: 0
        };
        
        dataRows.each(function() {
            var row = $(this);
            var checkbox = row.find('.row-checkbox');
            var isChecked = checkbox.prop('checked');
            
            var txnCount = parseInt(row.data('txn-count')) || 0;
            var principal = parseFloat(row.data('principal')) || 0;
            var charge = parseFloat(row.data('charge')) || 0;
            var adjustment = parseFloat(row.data('adjustment')) || 0;
            var settlement = parseFloat(row.data('settlement')) || 0;
            
            var groupRow = row.prevAll('.group-header-row').first();
            var groupKey = groupRow.find('td').text().trim();
            
            if (!groupTotals[groupKey]) {
                groupTotals[groupKey] = {
                    txn_count: 0,
                    principal: 0,
                    charge: 0,
                    adjustment: 0,
                    settlement: 0
                };
            }
            
            if (isChecked) {
                groupTotals[groupKey].txn_count += txnCount;
                groupTotals[groupKey].principal += principal;
                groupTotals[groupKey].charge += charge;
                groupTotals[groupKey].adjustment += adjustment;
                groupTotals[groupKey].settlement += settlement;
                
                grandTotals.txn_count += txnCount;
                grandTotals.principal += principal;
                grandTotals.charge += charge;
                grandTotals.adjustment += adjustment;
                grandTotals.settlement += settlement;
            }
            
            if (isChecked) {
                row.removeClass('excluded-row');
            } else {
                row.addClass('excluded-row');
            }
        });
        
        // Update group subtotals
        $('.group-subtotal-row').each(function() {
            var groupRow = $(this);
            var displayText = groupRow.find('td').first().text().trim();
            var matchedKey = null;
            for (var key in groupTotals) {
                if (displayText.indexOf(key) !== -1 || key.indexOf(displayText) !== -1) {
                    matchedKey = key;
                    break;
                }
            }
            
            if (matchedKey && groupTotals[matchedKey]) {
                var totals = groupTotals[matchedKey];
                groupRow.find('.group-txn-count').text(formatNumberInt(totals.txn_count));
                groupRow.find('.group-principal').text('₱ ' + formatNumberDecimal(totals.principal));
                groupRow.find('.group-charge').text('₱ ' + formatNumberDecimal(totals.charge));
                
                var adjText = (totals.adjustment >= 0 ? '+' : '') + '₱ ' + formatNumberDecimal(totals.adjustment);
                groupRow.find('.group-adjustment').text(adjText);
                groupRow.find('.group-adjustment').removeClass('negative-amount positive-amount');
                if (totals.adjustment < 0) {
                    groupRow.find('.group-adjustment').addClass('negative-amount');
                } else if (totals.adjustment > 0) {
                    groupRow.find('.group-adjustment').addClass('positive-amount');
                }
                
                groupRow.find('.group-settlement').text('₱ ' + formatNumberDecimal(totals.settlement));
                groupRow.find('.group-settlement').removeClass('negative-amount');
                if (totals.settlement < 0) {
                    groupRow.find('.group-settlement').addClass('negative-amount');
                }
            }
        });
        
        // Update grand totals
        $('.grand-total-row').find('.grand-txn-count').text(formatNumberInt(grandTotals.txn_count));
        $('.grand-total-row').find('.grand-principal').text('₱ ' + formatNumberDecimal(grandTotals.principal));
        $('.grand-total-row').find('.grand-charge').text('₱ ' + formatNumberDecimal(grandTotals.charge));
        
        var grandAdjText = (grandTotals.adjustment >= 0 ? '+' : '') + '₱ ' + formatNumberDecimal(grandTotals.adjustment);
        $('.grand-total-row').find('.grand-adjustment').text(grandAdjText);
        $('.grand-total-row').find('.grand-adjustment').removeClass('negative-amount positive-amount');
        if (grandTotals.adjustment < 0) {
            $('.grand-total-row').find('.grand-adjustment').addClass('negative-amount');
        } else if (grandTotals.adjustment > 0) {
            $('.grand-total-row').find('.grand-adjustment').addClass('positive-amount');
        }
        
        $('.grand-total-row').find('.grand-settlement').text('₱ ' + formatNumberDecimal(grandTotals.settlement));
        $('.grand-total-row').find('.grand-settlement').removeClass('negative-amount');
        if (grandTotals.settlement < 0) {
            $('.grand-total-row').find('.grand-settlement').addClass('negative-amount');
        }
        
        // Update select all checkbox state
        var totalCheckboxes = $('.row-checkbox').length;
        var checkedCheckboxes = $('.row-checkbox:checked').length;
        var selectAllHeader = $('#selectAllHeader');
        var selectAllRows = $('#selectAllRows');
        
        if (totalCheckboxes > 0 && checkedCheckboxes === totalCheckboxes) {
            selectAllHeader.prop('checked', true);
            selectAllRows.prop('checked', true);
            selectAllHeader.prop('indeterminate', false);
            selectAllRows.prop('indeterminate', false);
        } else if (checkedCheckboxes === 0) {
            selectAllHeader.prop('checked', false);
            selectAllRows.prop('checked', false);
            selectAllHeader.prop('indeterminate', false);
            selectAllRows.prop('indeterminate', false);
        } else {
            selectAllHeader.prop('checked', false);
            selectAllRows.prop('checked', false);
            selectAllHeader.prop('indeterminate', true);
            selectAllRows.prop('indeterminate', true);
        }
    }
    
    function recalculateTotals() {
        updateTotals();
        Swal.fire({
            icon: 'success',
            title: 'Totals Updated',
            text: 'Settlement totals have been recalculated based on selected rows.',
            timer: 1500,
            showConfirmButton: false
        });
    }
    
    // Legacy numberFormat for backward compatibility
    function numberFormat(value) {
        // Check if it's an integer (like txn_count)
        if (Number.isInteger(parseFloat(value)) && parseFloat(value) % 1 === 0) {
            return formatNumberInt(value);
        }
        return formatNumberDecimal(value);
    }
    
    function formatNumberInt(value) {
        return new Intl.NumberFormat('en-US', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 0
        }).format(value);
    }
    
    function formatNumberDecimal(value) {
        return new Intl.NumberFormat('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(value);
    }
    
    // Add CSS for excluded rows and animations
    var style = document.createElement('style');
    style.innerHTML = `
        .excluded-row {
            opacity: 0.6;
            background-color: #f8f9fa !important;
        }
        .excluded-row td {
            text-decoration: line-through;
            color: #6c757d !important;
        }
        .excluded-row .settlement-amount {
            color: #dc3545 !important;
        }
        .checkbox-controls {
            display: flex;
            align-items: center;
            gap: 15px;
            margin: 10px 0;
        }
        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 5px;
            cursor: pointer;
            font-size: 14px;
            color: #495057;
        }
        .checkbox-label input[type="checkbox"] {
            width: 16px;
            height: 16px;
            cursor: pointer;
        }
        .btn-recalculate {
            padding: 6px 15px;
            background-color: #880000;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
            transition: background-color 0.2s;
        }
        .btn-recalculate:hover {
            background-color: #ce1b1b;
        }
        .btn-recalculate i {
            margin-right: 5px;
        }
        .checkbox-cell {
            width: 40px;
            text-align: center;
        }
        .checkbox-cell input[type="checkbox"] {
            width: 16px;
            height: 16px;
            cursor: pointer;
        }
        .table-controls {
            padding: 10px 0;
            border-bottom: 1px solid #dee2e6;
            margin-bottom: 10px;
        }
        #selectAllHeader {
            width: 16px;
            height: 16px;
            cursor: pointer;
        }
        .daily-breakdown-table {
            background-color: #fff;
            border-radius: 4px;
            overflow: hidden;
        }
        .daily-breakdown-table td, .daily-breakdown-table th {
            border: 1px solid #dee2e6;
        }
    `;
    document.head.appendChild(style);

    function exportToExcel() {
        showLoadingModal();
        setTimeout(function() {
            var table = document.getElementById('settlementTable');
            if (!table) {
                hideLoadingModal();
                Swal.fire({
                    icon: 'warning',
                    title: 'No Data',
                    text: 'No data available to export.',
                    confirmButtonColor: '#ffc107'
                });
                return;
            }
            
            var partner = $('#partner').val() || '';
            var bank = $('#bank').val() || '';
            var settlementType = $('#settlement_type').val() || '';
            var dateFrom = $('#date_from').val() || '';
            var dateTo = $('#date_to').val() || '';
            
            var excludedRows = [];
            $('.data-row').each(function() {
                var checkbox = $(this).find('.row-checkbox');
                if (!checkbox.prop('checked')) {
                    var rowIndex = $(this).data('row-index');
                    if (rowIndex !== undefined) {
                        excludedRows.push(rowIndex);
                    }
                }
            });
            
            var exportUrl = 'export_bank_settlement.php?';
            exportUrl += 'partner=' + encodeURIComponent(partner);
            exportUrl += '&bank=' + encodeURIComponent(bank);
            exportUrl += '&settlement_type=' + encodeURIComponent(settlementType);
            exportUrl += '&date_from=' + encodeURIComponent(dateFrom);
            exportUrl += '&date_to=' + encodeURIComponent(dateTo);
            
            if (excludedRows.length > 0) {
                exportUrl += '&excluded_rows=' + encodeURIComponent(excludedRows.join(','));
            }
            
            window.open(exportUrl, '_blank');
            
            setTimeout(function() {
                hideLoadingModal();
            }, 500);
        }, 300);
    }
</script>

</body>
</html>