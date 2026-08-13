<?php
// settlement-per-bank.php

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

// ============================================
// FUNCTION: Get bank abbreviation from database only
// ============================================
function getBankAbbreviation(mysqli $conn, string $bank_name): string {
    if (empty($bank_name)) {
        return '';
    }

    $bank_name = trim($bank_name);
    $bank_name_upper = strtoupper($bank_name);

    // Try 1: exact match in mldb.bank_table
    $query = "SELECT bank_abbreviation FROM mldb.bank_table WHERE bank_name = ? LIMIT 1";
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param("s", $bank_name);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            if (!empty($row['bank_abbreviation'])) {
                $stmt->close();
                return strtoupper(trim($row['bank_abbreviation']));
            }
        }
        $stmt->close();
    }

    // Try 2: LIKE match in mldb.bank_table (more tolerant)
    $query = "SELECT bank_abbreviation FROM mldb.bank_table 
              WHERE UPPER(bank_name) LIKE CONCAT('%', UPPER(?), '%') 
                 OR UPPER(?) LIKE CONCAT('%', UPPER(bank_name), '%')
              LIMIT 1";
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param("ss", $bank_name, $bank_name);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            if (!empty($row['bank_abbreviation'])) {
                $stmt->close();
                return strtoupper(trim($row['bank_abbreviation']));
            }
        }
        $stmt->close();
    }

    // Try 3: partner_masterfile exact + LIKE
    $query2 = "SELECT DISTINCT bank_abbreviation FROM masterdata.partner_masterfile 
               WHERE (bank = ? OR UPPER(bank) LIKE CONCAT('%', UPPER(?), '%'))
                 AND bank_abbreviation IS NOT NULL AND bank_abbreviation != '' 
               LIMIT 1";
    $stmt2 = $conn->prepare($query2);
    if ($stmt2) {
        $stmt2->bind_param("ss", $bank_name, $bank_name);
        $stmt2->execute();
        $result2 = $stmt2->get_result();
        if ($row2 = $result2->fetch_assoc()) {
            if (!empty($row2['bank_abbreviation'])) {
                $stmt2->close();
                return strtoupper(trim($row2['bank_abbreviation']));
            }
        }
        $stmt2->close();
    }

    // If no abbreviation found, return empty string
    // The caller should handle this case and use 'RFP' as fallback
    return '';
}

// ============================================
// FUNCTION: Get bank for a partner
// ============================================
function getPartnerBank(mysqli $conn, string $partner_id): string {
    if (empty($partner_id)) {
        return '';
    }
    
    $query = "SELECT bank FROM masterdata.partner_masterfile WHERE partner_id_kpx = ? LIMIT 1";
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param("s", $partner_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $stmt->close();
            return trim($row['bank'] ?? '');
        }
        $stmt->close();
    }
    return '';
}

// ============================================
// FUNCTION: Get settlement type abbreviation
// ============================================
function getSettlementAbbreviation(string $settlement_type): string {
    if (empty($settlement_type)) return '';
    $type = strtoupper(trim($settlement_type));
    if ($type === 'CHECK' || $type === 'CHEQUE') return 'CHK';
    if ($type === 'ONLINE' || $type === 'ONL') return 'ONL';
    return strtoupper(substr($type, 0, 3));
}

// ============================================
// FUNCTION: Format date for CAD number (YYYY-MM-000DD)
// ============================================
function formatCADDate(?string $date_from, ?string $date_to): string {
    if (empty($date_from) && empty($date_to)) {
        return date('Y-m') . '-' . sprintf('%05d', (int)date('d'));
    }

    $date = !empty($date_to) ? $date_to : $date_from;
    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return date('Y-m') . '-' . sprintf('%05d', (int)date('d'));
    }
    // YYYY-MM-000DD  (day padded to 5 digits)
    return date('Y-m', $timestamp) . '-' . sprintf('%05d', (int)date('d', $timestamp));
}

// ============================================
// FUNCTION: Calculate settlement amount based on charge type
// ============================================
function calculateSettlementAmount($charge_to, $service_charge, $principal, $charge_to_customer, $charge_to_partner, $adjustment) {
    $charge_to_upper = strtoupper(trim($charge_to));
    $service_charge_upper = strtoupper(trim($service_charge));
    
    // For WEEKLY, MONTHLY, SEMI-MONTHLY: Amount = Principal + Adjustment (no charge deduction)
    // This applies to both PARTNER and CUSTOMER charge types
    if (($charge_to_upper === 'PARTNER' || $charge_to_upper === 'CUSTOMER') && 
        in_array($service_charge_upper, ['WEEKLY', 'MONTHLY', 'SEMI-MONTHLY'])) {
        return $principal + $adjustment;
    }
    
    // For DAILY (both CUSTOMER and PARTNER): Amount = Principal - Charge to Partner + Adjustment
    if (($charge_to_upper === 'CUSTOMER' || $charge_to_upper === 'PARTNER') && $service_charge_upper === 'DAILY') {
        return $principal - $charge_to_partner + $adjustment;
    }
    
    // For BOTH DAILY: Amount = Principal - Charge to Partner + Adjustment
    if ($charge_to_upper === 'BOTH' && $service_charge_upper === 'DAILY') {
        return $principal - $charge_to_partner + $adjustment;
    }
    
    // For BOTH charge types (WEEKLY/MONTHLY): Use the original calculation (Principal + both charges + adjustment)
    if ($charge_to_upper === 'BOTH') {
        return $principal + $charge_to_customer + $charge_to_partner + $adjustment;
    }
    
    // Default fallback
    return $principal + $charge_to_customer + $charge_to_partner + $adjustment;
}

// ============================================
// FETCH FILTER DATA
// ============================================
try {
    // Get distinct partners (partner_id_kpx + partner_name)
    $partners_query = "SELECT DISTINCT partner_id_kpx, partner_name, bank FROM masterdata.partner_masterfile WHERE partner_id_kpx IS NOT NULL AND partner_id_kpx != '' ORDER BY partner_name";
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
$selected_rfp_no = isset($_GET['rfp_no']) ? trim($_GET['rfp_no']) : '';

// Flag to check if filters are applied
$has_filters = !empty(array_filter($_GET));

// ============================================
// AUTO-POPULATE BANK FROM PARTNER
// ============================================
$auto_selected_bank = '';
if (!empty($selected_partner) && empty($selected_bank)) {
    $auto_selected_bank = getPartnerBank($conn, $selected_partner);
    if (!empty($auto_selected_bank)) {
        $selected_bank = $auto_selected_bank;
        $_GET['bank'] = $auto_selected_bank;
    }
}

// ============================================
// RETRIEVE RFP NO. FROM DATABASE IF NOT PROVIDED
// ============================================
if (empty($selected_rfp_no) && $has_filters) {
    $rfp_query = "SELECT DISTINCT rfp_no FROM mldb.billspayment_transaction bt 
                  LEFT JOIN masterdata.partner_masterfile pm ON bt.partner_id_kpx = pm.partner_id_kpx
                  WHERE 1=1";
    
    $rfp_params = [];
    $rfp_types = "";
    
    if (!empty($selected_partner)) {
        $rfp_query .= " AND bt.partner_id_kpx = ?";
        $rfp_params[] = $selected_partner;
        $rfp_types .= "s";
    }
    if (!empty($selected_bank)) {
        $rfp_query .= " AND pm.bank = ?";
        $rfp_params[] = $selected_bank;
        $rfp_types .= "s";
    }
    if (!empty($selected_settlement_type)) {
        $rfp_query .= " AND pm.settled_online_check = ?";
        $rfp_params[] = $selected_settlement_type;
        $rfp_types .= "s";
    }
    if (!empty($selected_date_from) && !empty($selected_date_to)) {
        $rfp_query .= " AND bt.datetime BETWEEN ? AND ?";
        $rfp_params[] = $selected_date_from . ' 00:00:00';
        $rfp_params[] = $selected_date_to . ' 23:59:59';
        $rfp_types .= "ss";
    } elseif (!empty($selected_date_from)) {
        $rfp_query .= " AND bt.datetime >= ?";
        $rfp_params[] = $selected_date_from . ' 00:00:00';
        $rfp_types .= "s";
    } elseif (!empty($selected_date_to)) {
        $rfp_query .= " AND bt.datetime <= ?";
        $rfp_params[] = $selected_date_to . ' 23:59:59';
        $rfp_types .= "s";
    }
    
    $rfp_query .= " AND rfp_no IS NOT NULL AND rfp_no != '' LIMIT 1";
    
    $rfp_stmt = $conn->prepare($rfp_query);
    if ($rfp_stmt && !empty($rfp_params)) {
        $rfp_stmt->bind_param($rfp_types, ...$rfp_params);
        $rfp_stmt->execute();
        $rfp_result = $rfp_stmt->get_result();
        if ($rfp_row = $rfp_result->fetch_assoc()) {
            $selected_rfp_no = $rfp_row['rfp_no'];
            $_GET['rfp_no'] = $selected_rfp_no;
        }
        $rfp_stmt->close();
    }
}

// ============================================
// GENERATE CAD NUMBER
// ============================================
$bank_abbreviation = '';
$cad_number = '';
$cad_generated = false;

// Prefer generating a fresh CAD from the current filters.
// Only reuse a DB value when it already looks correct (starts with a real bank abbr).
$existing_cad = '';
if (!empty($selected_rfp_no)) {
    $cad_query = "SELECT DISTINCT cad_no FROM mldb.billspayment_transaction 
                  WHERE rfp_no = ? AND cad_no IS NOT NULL AND cad_no != '' LIMIT 1";
    $cad_stmt = $conn->prepare($cad_query);
    if ($cad_stmt) {
        $cad_stmt->bind_param("s", $selected_rfp_no);
        $cad_stmt->execute();
        $cad_result = $cad_stmt->get_result();
        if ($cad_row = $cad_result->fetch_assoc()) {
            $existing_cad = trim($cad_row['cad_no']);
        }
        $cad_stmt->close();
    }
}

// Resolve bank abbreviation first (needed for both generation and validation)
if (!empty($selected_bank)) {
    $bank_abbreviation = getBankAbbreviation($conn, $selected_bank);
    error_log("Settlement Page - Bank abbreviation for '$selected_bank': '$bank_abbreviation'");
}

// If we still don't have a bank abbreviation, try to get it from the partner
if (empty($bank_abbreviation) && !empty($selected_partner)) {
    $abbr_query = "SELECT DISTINCT bank_abbreviation FROM masterdata.partner_masterfile 
                   WHERE partner_id_kpx = ? AND bank_abbreviation IS NOT NULL AND bank_abbreviation != '' LIMIT 1";
    $abbr_stmt = $conn->prepare($abbr_query);
    if ($abbr_stmt) {
        $abbr_stmt->bind_param("s", $selected_partner);
        $abbr_stmt->execute();
        $abbr_result = $abbr_stmt->get_result();
        if ($abbr_row = $abbr_result->fetch_assoc()) {
            $bank_abbreviation = strtoupper(trim($abbr_row['bank_abbreviation']));
            error_log("Settlement Page - Bank abbreviation from partner: '$bank_abbreviation'");
        }
        $abbr_stmt->close();
    }
}

// Decide whether the existing CAD is usable
$use_existing = false;
if (!empty($existing_cad) && !empty($bank_abbreviation)) {
    // Accept only if it starts with the correct bank abbr (reject old RFP- fallbacks)
    if (stripos($existing_cad, $bank_abbreviation . '-') === 0) {
        $use_existing = true;
        $cad_number = $existing_cad;
        $cad_generated = true;
    }
} elseif (!empty($existing_cad) && empty($bank_abbreviation) && stripos($existing_cad, 'RFP-') !== 0) {
    // No bank selected – keep non-RFP existing value
    $use_existing = true;
    $cad_number = $existing_cad;
    $cad_generated = true;
}

if (!$use_existing) {
    // Generate a new CAD
    $settlement_abbr = '';
    if (!empty($selected_settlement_type)) {
        $settlement_abbr = getSettlementAbbreviation($selected_settlement_type);
    }

    $cad_date = formatCADDate($selected_date_from, $selected_date_to);

    if (!empty($bank_abbreviation)) {
        $cad_number = $bank_abbreviation;
        $cad_generated = true;
    } else {
        error_log("Settlement Page - WARNING: No bank abbreviation found for bank='$selected_bank' partner='$selected_partner'. Using 'RFP' as fallback.");
        $cad_number = 'RFP';
        $cad_generated = false; // CAD is not valid for settlement
    }

    if (!empty($settlement_abbr)) {
        $cad_number .= '-' . $settlement_abbr;
    }

    $cad_number .= '-' . $cad_date;
}

error_log("Settlement Page - Final CAD Number: " . $cad_number);
error_log("Settlement Page - CAD Generated: " . ($cad_generated ? 'Yes' : 'No'));

// Store filters in session to maintain state
if (!empty(array_filter($_GET))) {
    $_SESSION['settlement_filters'] = $_GET;
}

// Check if date range exists and if dates are different (not the same day)
$has_date_range = false;
if (!empty($selected_date_from) && !empty($selected_date_to)) {
    if ($selected_date_from !== $selected_date_to) {
        $has_date_range = true;
    }
} elseif (!empty($selected_date_from) || !empty($selected_date_to)) {
    $has_date_range = false;
}

// ============================================
// FUNCTION: Get daily breakdown for a partner
// ============================================
function getDailyBreakdown(mysqli $conn, string $partner_id, string $bank, string $settlement_type, string $date_from, string $date_to) {
    if (empty($partner_id)) {
        return [];
    }
    
    $where_conditions_regular = [];
    $where_conditions_adjustment = [];
    $params_regular = [];
    $params_adjustment = [];
    $types_regular = "";
    $types_adjustment = "";
    
    $where_conditions_regular[] = "bt.partner_id_kpx = ?";
    $params_regular[] = $partner_id;
    $types_regular .= "s";
    
    $where_conditions_adjustment[] = "bt.partner_id_kpx = ?";
    $params_adjustment[] = $partner_id;
    $types_adjustment .= "s";
    
    if (!empty($bank)) {
        $where_conditions_regular[] = "pm.bank = ?";
        $params_regular[] = $bank;
        $types_regular .= "s";
        
        $where_conditions_adjustment[] = "pm.bank = ?";
        $params_adjustment[] = $bank;
        $types_adjustment .= "s";
    }
    
    if (!empty($settlement_type)) {
        $where_conditions_regular[] = "pm.settled_online_check = ?";
        $params_regular[] = $settlement_type;
        $types_regular .= "s";
        
        $where_conditions_adjustment[] = "pm.settled_online_check = ?";
        $params_adjustment[] = $settlement_type;
        $types_adjustment .= "s";
    }
    
    if (!empty($date_from) && !empty($date_to)) {
        $where_conditions_regular[] = "bt.datetime BETWEEN ? AND ?";
        $params_regular[] = $date_from . ' 00:00:00';
        $params_regular[] = $date_to . ' 23:59:59';
        $types_regular .= "ss";
    } elseif (!empty($date_from)) {
        $where_conditions_regular[] = "bt.datetime >= ?";
        $params_regular[] = $date_from . ' 00:00:00';
        $types_regular .= "s";
    } elseif (!empty($date_to)) {
        $where_conditions_regular[] = "bt.datetime <= ?";
        $params_regular[] = $date_to . ' 23:59:59';
        $types_regular .= "s";
    }
    
    $where_conditions_regular[] = "(bt.status IS NULL OR bt.status = '')";
    
    if (!empty($date_from) && !empty($date_to)) {
        $where_conditions_adjustment[] = "bt.cancellation_date BETWEEN ? AND ?";
        $params_adjustment[] = $date_from . ' 00:00:00';
        $params_adjustment[] = $date_to . ' 23:59:59';
        $types_adjustment .= "ss";
    } elseif (!empty($date_from)) {
        $where_conditions_adjustment[] = "bt.cancellation_date >= ?";
        $params_adjustment[] = $date_from . ' 00:00:00';
        $types_adjustment .= "s";
    } elseif (!empty($date_to)) {
        $where_conditions_adjustment[] = "bt.cancellation_date <= ?";
        $params_adjustment[] = $date_to . ' 23:59:59';
        $types_adjustment .= "s";
    }
    
    $where_conditions_adjustment[] = "(bt.status IS NOT NULL AND bt.status != '')";
    
    $sql = "SELECT 
                transaction_date,
                SUM(txn_count) as txn_count,
                SUM(total_principal) as total_principal,
                SUM(charge_to_customer) as charge_to_customer,
                SUM(charge_to_partner) as charge_to_partner,
                SUM(total_adjustment) as total_adjustment,
                MAX(settle_status) as settle_status
            FROM (
                SELECT 
                    DATE(bt.datetime) as transaction_date,
                    COUNT(*) as txn_count,
                    SUM(CASE WHEN bt.amount_paid > 0 THEN bt.amount_paid ELSE 0 END) as total_principal,
                    SUM(bt.charge_to_customer) as charge_to_customer,
                    SUM(bt.charge_to_partner) as charge_to_partner,
                    0 as total_adjustment,
                    MAX(bt.settle_unsettle) as settle_status
                FROM mldb.billspayment_transaction bt
                LEFT JOIN masterdata.partner_masterfile pm ON bt.partner_id_kpx = pm.partner_id_kpx
                WHERE " . implode(" AND ", $where_conditions_regular) . "
                GROUP BY DATE(bt.datetime)
                
                UNION ALL
                
                SELECT 
                    DATE(bt.cancellation_date) as transaction_date,
                    0 as txn_count,
                    0 as total_principal,
                    0 as charge_to_customer,
                    0 as charge_to_partner,
                    SUM(CASE WHEN bt.amount_paid < 0 THEN bt.amount_paid ELSE 0 END) as total_adjustment,
                    '' as settle_status
                FROM mldb.billspayment_transaction bt
                LEFT JOIN masterdata.partner_masterfile pm ON bt.partner_id_kpx = pm.partner_id_kpx
                WHERE " . implode(" AND ", $where_conditions_adjustment) . "
                GROUP BY DATE(bt.cancellation_date)
            ) combined
            GROUP BY transaction_date
            ORDER BY transaction_date ASC";
    
    $all_params = array_merge($params_regular, $params_adjustment);
    $all_types = $types_regular . $types_adjustment;
    
    if (!empty($all_params)) {
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($all_types, ...$all_params);
            $stmt->execute();
            $result = $stmt->get_result();
            $data = [];
            while ($row = $result->fetch_assoc()) {
                $status = $row['settle_status'] ?? '';
                $is_settled = (strtoupper(trim($status)) === 'SETTLED');
                
                $charge_to = '';
                $service_charge = '';
                $partner_sql = "SELECT COALESCE(charge_to, '') as charge_to, COALESCE(serviceCharge, '') as serviceCharge 
                                FROM masterdata.partner_masterfile 
                                WHERE partner_id_kpx = ?";
                $partner_stmt = $conn->prepare($partner_sql);
                if ($partner_stmt) {
                    $partner_stmt->bind_param("s", $partner_id);
                    $partner_stmt->execute();
                    $partner_result = $partner_stmt->get_result();
                    if ($partner_row = $partner_result->fetch_assoc()) {
                        $charge_to = $partner_row['charge_to'] ?? '';
                        $service_charge = $partner_row['serviceCharge'] ?? '';
                    }
                    $partner_stmt->close();
                }
                
                $principal = (float)($row['total_principal'] ?? 0);
                $charge_to_customer = (float)($row['charge_to_customer'] ?? 0);
                $charge_to_partner = (float)($row['charge_to_partner'] ?? 0);
                $adjustment = (float)($row['total_adjustment'] ?? 0);
                
                $amount_for_settlement = calculateSettlementAmount(
                    $charge_to,
                    $service_charge,
                    $principal,
                    $charge_to_customer,
                    $charge_to_partner,
                    $adjustment
                );
                
                $data[] = [
                    'transaction_date' => $row['transaction_date'],
                    'txn_count' => (int)($row['txn_count'] ?? 0),
                    'total_principal' => $principal,
                    'charge_to_customer' => $charge_to_customer,
                    'charge_to_partner' => $charge_to_partner,
                    'total_adjustment' => $adjustment,
                    'amount_for_settlement' => $amount_for_settlement,
                    'settle_status' => $status,
                    'is_settled' => $is_settled
                ];
            }
            return $data;
        }
    }
    return [];
}

// ============================================
// FUNCTION: Generate daily breakdown HTML
// ============================================
function generateDailyBreakdownHTML(array $data): string {
    if (empty($data)) {
        return '<div style="text-align: center; padding: 10px; color: #6c757d;">No daily transactions found for this date range.</div>';
    }
    
    $html = '<table class="daily-breakdown-table" style="width: 100%; border-collapse: collapse; margin: 5px 0;">';
    $html .= '<thead>';
    $html .= '<tr style="background-color: #e9ecef; font-size: 12px;">';
    $html .= '<th style="padding: 6px 12px; text-align: center; width: 120px;">Date</th>';
    $html .= '<th style="padding: 6px 12px; text-align: center;">Volume Count</th>';
    $html .= '<th style="padding: 6px 12px; text-align: right;">Principal</th>';
    $html .= '<th style="padding: 6px 12px; text-align: right;">Charge to Customer</th>';
    $html .= '<th style="padding: 6px 12px; text-align: right;">Charge to Partner</th>';
    $html .= '<th style="padding: 6px 12px; text-align: right;">Adjustment</th>';
    $html .= '<th style="padding: 6px 12px; text-align: right;">Settlement</th>';
    $html .= '<th style="padding: 6px 12px; text-align: center;">Status</th>';
    $html .= '</tr>';
    $html .= '</thead>';
    $html .= '<tbody>';
    
    $dailyTotals = [
        'txn_count' => 0,
        'principal' => 0,
        'charge_to_customer' => 0,
        'charge_to_partner' => 0,
        'adjustment' => 0,
        'settlement' => 0,
        'settled_count' => 0,
        'unsettled_count' => 0
    ];
    
    foreach ($data as $daily) {
        $dailyTotals['txn_count'] += $daily['txn_count'];
        $dailyTotals['principal'] += $daily['total_principal'];
        $dailyTotals['charge_to_customer'] += $daily['charge_to_customer'];
        $dailyTotals['charge_to_partner'] += $daily['charge_to_partner'];
        $dailyTotals['adjustment'] += $daily['total_adjustment'];
        $dailyTotals['settlement'] += $daily['amount_for_settlement'];
        
        if ($daily['is_settled']) {
            $dailyTotals['settled_count'] += $daily['txn_count'];
        } else {
            $dailyTotals['unsettled_count'] += $daily['txn_count'];
        }
        
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
        
        $statusBadge = $daily['is_settled'] 
            ? '<span class="settled-status"><i class="fas fa-check-circle"></i> Settled</span>'
            : '<span class="unsettled-status"><i class="fas fa-clock"></i> Unsettled</span>';
        
        $html .= '<tr class="daily-breakdown-row ' . ($daily['is_settled'] ? 'settled-row' : 'unsettled-row') . '" style="font-size: 13px;">';
        $html .= '<td style="padding: 6px 12px; text-align: center; font-weight: 500; color: #495057;">' . 
            date('M d, Y', strtotime($daily['transaction_date'])) . '</td>';
        $html .= '<td style="padding: 6px 12px; text-align: center;">' . 
            number_format($daily['txn_count'], 0) . '</td>';
        $html .= '<td style="padding: 6px 12px; text-align: right;">₱ ' . 
            number_format($daily['total_principal'], 2) . '</td>';
        $html .= '<td style="padding: 6px 12px; text-align: right;">₱ ' . 
            number_format($daily['charge_to_customer'], 2) . '</td>';
        $html .= '<td style="padding: 6px 12px; text-align: right;">₱ ' . 
            number_format($daily['charge_to_partner'], 2) . '</td>';
        
        if ($daily['total_adjustment'] != 0) {
            $html .= '<td style="padding: 6px 12px; text-align: right; ' . $adjClass . '">' . 
                $adjSign . '₱ ' . number_format($daily['total_adjustment'], 2) . '</td>';
        } else {
            $html .= '<td style="padding: 6px 12px; text-align: right;"></td>';
        }
        
        $html .= '<td style="padding: 6px 12px; text-align: right; font-weight: 600; ' . $settleClass . '">₱ ' . 
            number_format($daily['amount_for_settlement'], 2) . '</td>';
        $html .= '<td style="padding: 6px 12px; text-align: center;">' . $statusBadge . '</td>';
        $html .= '</tr>';
    }
    
    $adjClass = $dailyTotals['adjustment'] < 0 ? 'color: #dc3545;' : ($dailyTotals['adjustment'] > 0 ? 'color: #28a745;' : '');
    $adjSign = $dailyTotals['adjustment'] >= 0 ? '+' : '';
    $settleClass = $dailyTotals['settlement'] < 0 ? 'color: #dc3545;' : '';
    
    $html .= '<tr class="daily-subtotal-row" style="font-size: 13px; font-weight: 600; background-color: #e9ecef;">';
    $html .= '<td style="padding: 6px 12px; text-align: right; font-weight: 600;">DAILY SUBTOTAL</td>';
    $html .= '<td style="padding: 6px 12px; text-align: center;">' . number_format($dailyTotals['txn_count'], 0) . '</td>';
    $html .= '<td style="padding: 6px 12px; text-align: right;">₱ ' . number_format($dailyTotals['principal'], 2) . '</td>';
    $html .= '<td style="padding: 6px 12px; text-align: right;">₱ ' . number_format($dailyTotals['charge_to_customer'], 2) . '</td>';
    $html .= '<td style="padding: 6px 12px; text-align: right;">₱ ' . number_format($dailyTotals['charge_to_partner'], 2) . '</td>';
    
    if ($dailyTotals['adjustment'] != 0) {
        $html .= '<td style="padding: 6px 12px; text-align: right; ' . $adjClass . '">' . 
            $adjSign . '₱ ' . number_format($dailyTotals['adjustment'], 2) . '</td>';
    } else {
        $html .= '<td style="padding: 6px 12px; text-align: right;"></td>';
    }
    
    $html .= '<td style="padding: 6px 12px; text-align: right; ' . $settleClass . '">₱ ' . 
        number_format($dailyTotals['settlement'], 2) . '</td>';
    $html .= '<td style="padding: 6px 12px; text-align: center; font-size: 11px;">';
    if ($dailyTotals['settled_count'] > 0 && $dailyTotals['unsettled_count'] > 0) {
        $html .= '<span style="color: #28a745;">' . number_format($dailyTotals['settled_count']) . ' Settled</span> / ';
        $html .= '<span style="color: #dc3545;">' . number_format($dailyTotals['unsettled_count']) . ' Unsettled</span>';
    } elseif ($dailyTotals['settled_count'] > 0) {
        $html .= '<span class="settled-status">All ' . number_format($dailyTotals['settled_count']) . ' Settled</span>';
    } else {
        $html .= '<span class="unsettled-status">All ' . number_format($dailyTotals['unsettled_count']) . ' Unsettled</span>';
    }
    $html .= '</td>';
    $html .= '</tr>';
    
    $html .= '</tbody></table>';
    return $html;
}

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

// ============================================
// REASON NOT SETTLED OPTIONS
// ============================================
$reason_options = [
    '' => 'Select Reason',
    'For Partner Verification' => 'For Partner Verification',
    'System Error' => 'System Error',
    'For Further Checking' => 'For Further Checking'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settlement Per Bank | <?php if($_SESSION['user_type'] === 'admin' || $_SESSION['user_type'] === 'user') echo ucfirst($_SESSION['user_type']); else echo "Guest";?></title>
    <link rel="stylesheet" href="../../../assets/css/templates/style.css?v=<?php echo time(); ?>">
    <script src="https://kit.fontawesome.com/30b908cc5a.js" crossorigin="anonymous"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="../../../assets/js/sweetalert2.all.min.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
    <link rel="icon" href="../../../images/MLW logo.png" type="image/png">
    <link rel="stylesheet" href="css/settlement_bank.css?v=<?= time(); ?>">

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
                                data-bank="<?php echo htmlspecialchars($partner['bank'] ?? ''); ?>"
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

                <!-- RFP No. Field -->
                <div class="filter-group" style="flex: 0 0 200px;">
                    <label for="rfp_no">RFP No.</label>
                    <input type="text" id="rfp_no" name="rfp_no" 
                           placeholder="Enter RFP No." 
                           value="<?php echo htmlspecialchars($selected_rfp_no); ?>"
                           style="width: 100%; padding: 8px 12px; border: 1px solid #ced4da; border-radius: 4px;">
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
                $where_conditions_regular = [];
                $where_conditions_adjustment = [];
                $params_regular = [];
                $params_adjustment = [];
                $types_regular = "";
                $types_adjustment = "";
                
                if (!empty($selected_partner)) {
                    $where_conditions_regular[] = "bt.partner_id_kpx = ?";
                    $params_regular[] = $selected_partner;
                    $types_regular .= "s";
                    
                    $where_conditions_adjustment[] = "bt.partner_id_kpx = ?";
                    $params_adjustment[] = $selected_partner;
                    $types_adjustment .= "s";
                }
                
                if (!empty($selected_bank)) {
                    $where_conditions_regular[] = "pm.bank = ?";
                    $params_regular[] = $selected_bank;
                    $types_regular .= "s";
                    
                    $where_conditions_adjustment[] = "pm.bank = ?";
                    $params_adjustment[] = $selected_bank;
                    $types_adjustment .= "s";
                }
                
                if (!empty($selected_settlement_type)) {
                    $where_conditions_regular[] = "pm.settled_online_check = ?";
                    $params_regular[] = $selected_settlement_type;
                    $types_regular .= "s";
                    
                    $where_conditions_adjustment[] = "pm.settled_online_check = ?";
                    $params_adjustment[] = $selected_settlement_type;
                    $types_adjustment .= "s";
                }
                
                if (!empty($selected_date_from) && !empty($selected_date_to)) {
                    $where_conditions_regular[] = "bt.datetime BETWEEN ? AND ?";
                    $params_regular[] = $selected_date_from . ' 00:00:00';
                    $params_regular[] = $selected_date_to . ' 23:59:59';
                    $types_regular .= "ss";
                } elseif (!empty($selected_date_from)) {
                    $where_conditions_regular[] = "bt.datetime >= ?";
                    $params_regular[] = $selected_date_from . ' 00:00:00';
                    $types_regular .= "s";
                } elseif (!empty($selected_date_to)) {
                    $where_conditions_regular[] = "bt.datetime <= ?";
                    $params_regular[] = $selected_date_to . ' 23:59:59';
                    $types_regular .= "s";
                }
                
                $where_conditions_regular[] = "(bt.status IS NULL OR bt.status = '')";
                
                if (!empty($selected_date_from) && !empty($selected_date_to)) {
                    $where_conditions_adjustment[] = "bt.cancellation_date BETWEEN ? AND ?";
                    $params_adjustment[] = $selected_date_from . ' 00:00:00';
                    $params_adjustment[] = $selected_date_to . ' 23:59:59';
                    $types_adjustment .= "ss";
                } elseif (!empty($selected_date_from)) {
                    $where_conditions_adjustment[] = "bt.cancellation_date >= ?";
                    $params_adjustment[] = $selected_date_from . ' 00:00:00';
                    $types_adjustment .= "s";
                } elseif (!empty($selected_date_to)) {
                    $where_conditions_adjustment[] = "bt.cancellation_date <= ?";
                    $params_adjustment[] = $selected_date_to . ' 23:59:59';
                    $types_adjustment .= "s";
                }
                
                $where_conditions_adjustment[] = "(bt.status IS NOT NULL AND bt.status != '')";
                
                $regular_sql = "SELECT 
                    bt.partner_id_kpx,
                    pm.partner_name,
                    pm.partner_accName,
                    pm.bank_accNumber,
                    pm.bank,
                    pm.settled_online_check as settlement_type,
                    COALESCE(pm.charge_to, '') as charge_to,
                    COALESCE(pm.serviceCharge, '') as serviceCharge,
                    COUNT(*) as txn_count,
                    SUM(CASE WHEN bt.amount_paid > 0 THEN bt.amount_paid ELSE 0 END) as total_principal,
                    SUM(bt.charge_to_customer) as charge_to_customer,
                    SUM(bt.charge_to_partner) as charge_to_partner,
                    SUM(CASE WHEN bt.settle_unsettle = 'Settled' THEN 1 ELSE 0 END) as settled_count,
                    SUM(CASE WHEN bt.settle_unsettle IS NULL 
                              OR bt.settle_unsettle = '' 
                              OR bt.settle_unsettle != 'Settled' 
                         THEN 1 ELSE 0 END) as unsettled_count,
                    MAX(bt.datetime) as last_transaction_date,
                    MIN(bt.datetime) as first_transaction_date
                FROM mldb.billspayment_transaction bt
                LEFT JOIN masterdata.partner_masterfile pm 
                    ON bt.partner_id_kpx = pm.partner_id_kpx
                WHERE " . implode(" AND ", $where_conditions_regular) . "
                GROUP BY bt.partner_id_kpx, 
                         pm.partner_name, 
                         pm.partner_accName, 
                         pm.bank_accNumber, 
                         pm.bank, 
                         pm.settled_online_check, 
                         pm.charge_to, 
                         pm.serviceCharge";
                
                $adjustment_sql = "SELECT 
                                        bt.partner_id_kpx,
                                        SUM(CASE WHEN bt.amount_paid < 0 THEN bt.amount_paid ELSE 0 END) as total_adjustment
                                    FROM mldb.billspayment_transaction bt
                                    LEFT JOIN masterdata.partner_masterfile pm ON bt.partner_id_kpx = pm.partner_id_kpx
                                    WHERE " . implode(" AND ", $where_conditions_adjustment) . "
                                    GROUP BY bt.partner_id_kpx";
                
                $regular_result = null;
                if (!empty($params_regular)) {
                    $stmt = $conn->prepare($regular_sql);
                    if ($stmt) {
                        $stmt->bind_param($types_regular, ...$params_regular);
                        $stmt->execute();
                        $regular_result = $stmt->get_result();
                    } else {
                        error_log("Settlement - Regular query prepare failed: " . $conn->error);
                        $regular_result = false;
                    }
                } else {
                    $regular_result = $conn->query($regular_sql);
                }
                
                $adjustment_result = null;
                if (!empty($params_adjustment)) {
                    $stmt = $conn->prepare($adjustment_sql);
                    if ($stmt) {
                        $stmt->bind_param($types_adjustment, ...$params_adjustment);
                        $stmt->execute();
                        $adjustment_result = $stmt->get_result();
                    } else {
                        error_log("Settlement - Adjustment query prepare failed: " . $conn->error);
                        $adjustment_result = false;
                    }
                } else {
                    $adjustment_result = $conn->query($adjustment_sql);
                }
                
                $combined_data = [];
                
                if ($regular_result && $regular_result->num_rows > 0) {
                    while ($row = $regular_result->fetch_assoc()) {
                        $partner_id = $row['partner_id_kpx'];
                        $combined_data[$partner_id] = [
                            'partner_id_kpx' => $partner_id,
                            'partner_name' => $row['partner_name'] ?? $partner_id,
                            'partner_accName' => $row['partner_accName'] ?? 'N/A',
                            'bank_accNumber' => $row['bank_accNumber'] ?? 'N/A',
                            'bank' => $row['bank'] ?? '',
                            'settlement_type' => $row['settlement_type'] ?? '',
                            'charge_to' => $row['charge_to'] ?? '',
                            'serviceCharge' => $row['serviceCharge'] ?? '',
                            'settle_unsettle' => $row['settle_unsettle'] ?? '',
                            'txn_count' => (int)($row['txn_count'] ?? 0),
                            'total_principal' => (float)($row['total_principal'] ?? 0),
                            'charge_to_customer' => (float)($row['charge_to_customer'] ?? 0),
                            'charge_to_partner' => (float)($row['charge_to_partner'] ?? 0),
                            'total_adjustment' => 0,
                            'settled_count' => (int)($row['settled_count'] ?? 0),
                            'unsettled_count' => (int)($row['unsettled_count'] ?? 0),
                            'last_transaction_date' => $row['last_transaction_date'] ?? null,
                            'first_transaction_date' => $row['first_transaction_date'] ?? null
                        ];
                    }
                }
                
                if ($adjustment_result && $adjustment_result->num_rows > 0) {
                    while ($row = $adjustment_result->fetch_assoc()) {
                        $partner_id = $row['partner_id_kpx'];
                        if (isset($combined_data[$partner_id])) {
                            $combined_data[$partner_id]['total_adjustment'] = (float)($row['total_adjustment'] ?? 0);
                        } else {
                            $partner_details_sql = "SELECT 
                                                        partner_name,
                                                        partner_accName,
                                                        bank_accNumber,
                                                        bank,
                                                        settled_online_check as settlement_type,
                                                        COALESCE(charge_to, '') as charge_to,
                                                        COALESCE(serviceCharge, '') as serviceCharge
                                                    FROM masterdata.partner_masterfile 
                                                    WHERE partner_id_kpx = ?";
                            $stmt = $conn->prepare($partner_details_sql);
                            if ($stmt) {
                                $stmt->bind_param("s", $partner_id);
                                $stmt->execute();
                                $details_result = $stmt->get_result();
                                if ($details_result && $details_result->num_rows > 0) {
                                    $details = $details_result->fetch_assoc();
                                    $combined_data[$partner_id] = [
                                        'partner_id_kpx' => $partner_id,
                                        'partner_name' => $details['partner_name'] ?? $partner_id,
                                        'partner_accName' => $details['partner_accName'] ?? 'N/A',
                                        'bank_accNumber' => $details['bank_accNumber'] ?? 'N/A',
                                        'bank' => $details['bank'] ?? '',
                                        'settlement_type' => $details['settlement_type'] ?? '',
                                        'charge_to' => $details['charge_to'] ?? '',
                                        'serviceCharge' => $details['serviceCharge'] ?? '',
                                        'settle_unsettle' => '',
                                        'txn_count' => 0,
                                        'total_principal' => 0,
                                        'charge_to_customer' => 0,
                                        'charge_to_partner' => 0,
                                        'total_adjustment' => (float)($row['total_adjustment'] ?? 0),
                                        'settled_count' => 0,
                                        'unsettled_count' => 0,
                                        'last_transaction_date' => null,
                                        'first_transaction_date' => null
                                    ];
                                }
                            }
                        }
                    }
                }
                
                if (!empty($combined_data)) {
                    $data_array = array_values($combined_data);
                    
                    usort($data_array, function($a, $b) {
                        $order = [
                            'CUSTOMER_DAILY' => 1,
                            'CUSTOMER_WEEKLY' => 2,
                            'CUSTOMER_MONTHLY' => 3,
                            'PARTNER_DAILY' => 4,
                            'PARTNER_WEEKLY' => 5,
                            'PARTNER_SEMI-MONTHLY' => 6,
                            'PARTNER_MONTHLY' => 7,
                            'BOTH_DAILY' => 8,
                            'BOTH_WEEKLY' => 9,
                            'BOTH_MONTHLY' => 10,
                            'UNCATEGORIZED' => 11
                        ];
                        
                        $charge_to = strtoupper(trim($a['charge_to'] ?? ''));
                        $serviceCharge = strtoupper(trim($a['serviceCharge'] ?? ''));
                        $key_a = $charge_to . '_' . $serviceCharge;
                        
                        $charge_to_b = strtoupper(trim($b['charge_to'] ?? ''));
                        $serviceCharge_b = strtoupper(trim($b['serviceCharge'] ?? ''));
                        $key_b = $charge_to_b . '_' . $serviceCharge_b;
                        
                        $order_a = $order[$key_a] ?? 12;
                        $order_b = $order[$key_b] ?? 12;
                        
                        if ($order_a == $order_b) {
                            return strcmp($a['partner_name'] ?? '', $b['partner_name'] ?? '');
                        }
                        return $order_a - $order_b;
                    });
                    
                    $groups = [
                        'CHARGE BY CUSTOMER DAILY' => [
                            'display_name' => 'NOTE: CHARGE BY CUSTOMER DAILY',
                            'icon' => 'fa-user',
                            'rows' => [],
                            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0, 'settled_count' => 0, 'unsettled_count' => 0]
                        ],
                        'CHARGE BY CUSTOMER WEEKLY' => [
                            'display_name' => 'NOTE: CHARGE BY CUSTOMER WEEKLY',
                            'icon' => 'fa-user-clock',
                            'rows' => [],
                            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0, 'settled_count' => 0, 'unsettled_count' => 0]
                        ],
                        'CHARGE BY CUSTOMER MONTHLY' => [
                            'display_name' => 'NOTE: CHARGE BY CUSTOMER MONTHLY',
                            'icon' => 'fa-user-plus',
                            'rows' => [],
                            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0, 'settled_count' => 0, 'unsettled_count' => 0]
                        ],
                        'CHARGE BY PARTNER DAILY' => [
                            'display_name' => 'NOTE: CHARGE BY PARTNER DAILY',
                            'icon' => 'fa-calendar-day',
                            'rows' => [],
                            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0, 'settled_count' => 0, 'unsettled_count' => 0]
                        ],
                        'CHARGE BY PARTNER WEEKLY' => [
                            'display_name' => 'NOTE: CHARGE BY PARTNER WEEKLY',
                            'icon' => 'fa-calendar-week',
                            'rows' => [],
                            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0, 'settled_count' => 0, 'unsettled_count' => 0]
                        ],
                        'CHARGE BY PARTNER SEMI MONTHLY' => [
                            'display_name' => 'NOTE: CHARGE BY PARTNER SEMI-MONTHLY',
                            'icon' => 'fa-calendar-alt',
                            'rows' => [],
                            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0, 'settled_count' => 0, 'unsettled_count' => 0]
                        ],
                        'CHARGE BY PARTNER MONTHLY' => [
                            'display_name' => 'NOTE: CHARGE BY PARTNER MONTHLY',
                            'icon' => 'fa-calendar-check',
                            'rows' => [],
                            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0, 'settled_count' => 0, 'unsettled_count' => 0]
                        ],
                        'CHARGE BY BOTH DAILY' => [
                            'display_name' => 'NOTE: CHARGE BY BOTH (CUSTOMER & PARTNER) DAILY',
                            'icon' => 'fa-handshake',
                            'rows' => [],
                            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0, 'settled_count' => 0, 'unsettled_count' => 0],
                            'is_both' => true
                        ],
                        'CHARGE BY BOTH WEEKLY' => [
                            'display_name' => 'NOTE: CHARGE BY BOTH (CUSTOMER & PARTNER) WEEKLY',
                            'icon' => 'fa-handshake',
                            'rows' => [],
                            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0, 'settled_count' => 0, 'unsettled_count' => 0],
                            'is_both' => true
                        ],
                        'CHARGE BY BOTH MONTHLY' => [
                            'display_name' => 'NOTE: CHARGE BY BOTH (CUSTOMER & PARTNER) MONTHLY',
                            'icon' => 'fa-handshake',
                            'rows' => [],
                            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0, 'settled_count' => 0, 'unsettled_count' => 0],
                            'is_both' => true
                        ],
                        'UNCATEGORIZED' => [
                            'display_name' => '⚠️ PARTNERS WITHOUT CHARGE TYPE (UNCATEGORIZED)',
                            'icon' => 'fa-exclamation-triangle',
                            'rows' => [],
                            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0, 'settled_count' => 0, 'unsettled_count' => 0],
                            'is_uncategorized' => true
                        ]
                    ];
                    
                    $grand_totals = ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0, 'settled_count' => 0, 'unsettled_count' => 0];
                    
                    $row_index = 0;
                    $daily_breakdown_cache = [];
                    if ($has_date_range) {
                        $valid_partners = [];
                        foreach ($data_array as $row) {
                            $partner_id = $row['partner_id_kpx'];
                            if (!empty($partner_id)) {
                                $valid_partners[] = $partner_id;
                            }
                        }
                        
                        foreach ($valid_partners as $partner_id) {
                            $daily_data = getDailyBreakdown(
                                $conn, 
                                $partner_id, 
                                $selected_bank, 
                                $selected_settlement_type, 
                                $selected_date_from, 
                                $selected_date_to
                            );
                            if (!empty($daily_data)) {
                                $daily_breakdown_cache[$partner_id] = $daily_data;
                            }
                        }
                    }
                    
                    foreach ($data_array as $row) {
                        $charge_to = strtoupper(trim($row['charge_to'] ?? ''));
                        $serviceCharge = strtoupper(trim($row['serviceCharge'] ?? ''));
                        
                        $group_key = null;
                        
                        if (empty($charge_to)) {
                            $group_key = 'UNCATEGORIZED';
                        } elseif ($charge_to === 'CUSTOMER') {
                            if ($serviceCharge === 'DAILY') {
                                $group_key = 'CHARGE BY CUSTOMER DAILY';
                            } elseif ($serviceCharge === 'WEEKLY') {
                                $group_key = 'CHARGE BY CUSTOMER WEEKLY';
                            } elseif ($serviceCharge === 'MONTHLY') {
                                $group_key = 'CHARGE BY CUSTOMER MONTHLY';
                            } else {
                                $group_key = 'UNCATEGORIZED';
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
                            } else {
                                $group_key = 'UNCATEGORIZED';
                            }
                        } elseif ($charge_to === 'BOTH') {
                            if ($serviceCharge === 'DAILY') {
                                $group_key = 'CHARGE BY BOTH DAILY';
                            } elseif ($serviceCharge === 'WEEKLY') {
                                $group_key = 'CHARGE BY BOTH WEEKLY';
                            } elseif ($serviceCharge === 'MONTHLY') {
                                $group_key = 'CHARGE BY BOTH MONTHLY';
                            } else {
                                $group_key = 'UNCATEGORIZED';
                            }
                        } else {
                            $group_key = 'UNCATEGORIZED';
                        }
                        
                        if (!isset($groups[$group_key])) {
                            $group_key = 'UNCATEGORIZED';
                        }
                        
                        $txn_count = (int)($row['txn_count'] ?? 0);
                        $principal = (float)($row['total_principal'] ?? 0);
                        $charge_to_customer = (float)($row['charge_to_customer'] ?? 0);
                        $charge_to_partner = (float)($row['charge_to_partner'] ?? 0);
                        $adjustment = (float)($row['total_adjustment'] ?? 0);
                        
                        $settlement_amount = calculateSettlementAmount(
                            $charge_to,
                            $serviceCharge,
                            $principal,
                            $charge_to_customer,
                            $charge_to_partner,
                            $adjustment
                        );
                        
                        $settled_count = (int)($row['settled_count'] ?? 0);
                        $unsettled_count = (int)($row['unsettled_count'] ?? 0);
                        $is_fully_settled = ($settled_count > 0 && $unsettled_count == 0);
                        $is_partially_settled = ($settled_count > 0 && $unsettled_count > 0);
                        
                        $groups[$group_key]['totals']['txn_count'] += $txn_count;
                        $groups[$group_key]['totals']['principal'] += $principal;
                        $groups[$group_key]['totals']['charge_to_customer'] += $charge_to_customer;
                        $groups[$group_key]['totals']['charge_to_partner'] += $charge_to_partner;
                        $groups[$group_key]['totals']['adjustment'] += $adjustment;
                        $groups[$group_key]['totals']['settlement'] += $settlement_amount;
                        $groups[$group_key]['totals']['settled_count'] += $settled_count;
                        $groups[$group_key]['totals']['unsettled_count'] += $unsettled_count;
                        
                        $grand_totals['txn_count'] += $txn_count;
                        $grand_totals['principal'] += $principal;
                        $grand_totals['charge_to_customer'] += $charge_to_customer;
                        $grand_totals['charge_to_partner'] += $charge_to_partner;
                        $grand_totals['adjustment'] += $adjustment;
                        $grand_totals['settlement'] += $settlement_amount;
                        $grand_totals['settled_count'] += $settled_count;
                        $grand_totals['unsettled_count'] += $unsettled_count;
                        
                        $partner_id = $row['partner_id_kpx'];
                        $daily_data = $has_date_range && isset($daily_breakdown_cache[$partner_id]) 
                            ? $daily_breakdown_cache[$partner_id] 
                            : [];
                        $daily_html = !empty($daily_data) ? generateDailyBreakdownHTML($daily_data) : '';
                        
                        $is_excluded = $is_fully_settled;
                        
                        $groups[$group_key]['rows'][] = [
                            'row_index' => $row_index,
                            'partner_id' => $partner_id,
                            'partner_name' => $row['partner_name'] ?? $row['partner_id_kpx'],
                            'account_name' => $row['partner_accName'] ?? 'N/A',
                            'account_number' => $row['bank_accNumber'] ?? 'N/A',
                            'txn_count' => $txn_count,
                            'principal' => $principal,
                            'charge_to_customer' => $charge_to_customer,
                            'charge_to_partner' => $charge_to_partner,
                            'adjustment' => $adjustment,
                            'settlement_amount' => $settlement_amount,
                            'is_negative' => $settlement_amount < 0,
                            'excluded' => $is_excluded,
                            'is_fully_settled' => $is_fully_settled,
                            'is_partially_settled' => $is_partially_settled,
                            'settled_count' => $settled_count,
                            'unsettled_count' => $unsettled_count,
                            'has_daily_breakdown' => $has_date_range,
                            'daily_html' => $daily_html,
                            'charge_to' => $charge_to,
                            'service_charge' => $serviceCharge
                        ];
                        $row_index++;
                    }
                    
                    $groups = array_filter($groups, function($group) {
                        return !empty($group['rows']);
                    });
                    
                    $has_uncategorized = isset($groups['UNCATEGORIZED']) && !empty($groups['UNCATEGORIZED']['rows']);
                    $has_both = false;
                    foreach ($groups as $key => $group) {
                        if (isset($group['is_both']) && $group['is_both'] === true && !empty($group['rows'])) {
                            $has_both = true;
                            break;
                        }
                    }
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
                            <span class="table-badge">
                                <i class="fas fa-university"></i> Bank: <?php echo htmlspecialchars($selected_bank); ?>
                            </span>
                            <?php endif; ?>
                            <?php if (!empty($selected_rfp_no)): ?>
                            <span class="table-badge">
                                <i class="fas fa-file-invoice"></i> RFP No.: <?php echo htmlspecialchars($selected_rfp_no); ?>
                            </span>
                            <?php endif; ?>
                            <?php if (!empty($cad_number)): ?>
                            <span class="table-badge" id="currentCadBadge">
                                <i class="fas fa-hashtag"></i> CAD No.: <?php echo htmlspecialchars($cad_number); ?>
                            </span>
                            <!-- Hidden input so JS can always read the CURRENT CAD after AJAX filter -->
                            <input type="hidden" id="currentCadNo" value="<?php echo htmlspecialchars($cad_number); ?>">
                            <input type="hidden" id="cadGenerated" value="<?php echo $cad_generated ? 'true' : 'false'; ?>">
                            <?php endif; ?>
                            <?php if ($has_date_range): ?>
                            <span class="table-badge">
                                <i class="fas fa-calendar-alt"></i> 
                                <?php 
                                $date_range = '';
                                if (!empty($selected_date_from)) $date_range .= 'From: ' . date('M d, Y', strtotime($selected_date_from));
                                if (!empty($selected_date_to)) $date_range .= ' To: ' . date('M d, Y', strtotime($selected_date_to));
                                echo htmlspecialchars($date_range);
                                ?>
                            </span>
                            <?php endif; ?>
                            <?php if (!$cad_generated && !empty($selected_partner)): ?>
                            <span class="table-badge" style="background: #dc3545; color: #fff;">
                                <i class="fas fa-exclamation-triangle"></i> Invalid Bank - Cannot Settle
                            </span>
                            <?php endif; ?>
                        </div>
                        
                        <?php if (!$cad_generated && !empty($selected_partner)): ?>
                        <div class="alert-danger-custom">
                            <i class="fas fa-exclamation-triangle"></i>
                            <strong>Error:</strong> 
                            The selected partner does not have a valid bank abbreviation configured. 
                            Please ensure the partner's bank is properly set in the partner masterfile and bank abbreviations are configured in the bank table.
                            <strong>Settlement is disabled until this is fixed.</strong>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($has_both): ?>
                        <div class="alert-info-custom">
                            <i class="fas fa-info-circle"></i>
                            <strong>Information:</strong> 
                            Partners with <strong>"BOTH"</strong> charge type are shown in the BOTH groups. These partners charge both customers and partners.
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($has_uncategorized): ?>
                        <div class="alert-warning-custom">
                            <i class="fas fa-exclamation-triangle"></i>
                            <strong>Warning:</strong> 
                            Some partners do not have a valid charge type configured. These are shown in the 
                            <strong>"UNCATEGORIZED"</strong> group. Please update the partner masterfile to properly categorize these partners.
                        </div>
                        <?php endif; ?>
                        
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
                                    <th class="center">PARTNER</th>
                                    <th class="center">ACCOUNT NAME</th>
                                    <th class="center">ACCOUNT NUMBER</th>
                                    <th class="center">VOLUME COUNT</th>
                                    <th class="center">PRINCIPAL</th>
                                    <th class="center" colspan="2">CHARGE</th>
                                    <th class="center">ADJUSTMENT (add/less)</th>
                                    <th class="center settlement-col">AMOUNT FOR SETTLEMENT</th>
                                    <th class="center">STATUS</th>
                                    <th class="center" style="min-width: 180px;">REASON NOT SETTLED</th>
                                </tr>
                                <tr>
                                    <th colspan="7" style="font-style: italic; font-size: 12px; color: #ff0000;">Check Partners to Proceed Settlement</th>
                                    <th class="center">CUSTOMER</th>
                                    <th class="center">PARTNER</th>
                                    <th colspan="4"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $group_index = 0;
                                foreach ($groups as $group_key => $group_data): 
                                    $group_index++;
                                    $is_last_group = $group_index === count($groups);
                                    $is_uncategorized = isset($group_data['is_uncategorized']) && $group_data['is_uncategorized'] === true;
                                    $is_both = isset($group_data['is_both']) && $group_data['is_both'] === true;
                                ?>
                                    <!-- Group Header -->
                                    <tr class="group-header-row <?php echo $is_uncategorized ? 'uncategorized' : ''; ?> <?php echo $is_both ? 'both' : ''; ?>">
                                        <td colspan="13">
                                            <i class="fas <?php echo $group_data['icon']; ?>"></i>
                                            <?php echo htmlspecialchars($group_data['display_name']); ?>
                                            <?php if ($is_uncategorized): ?>
                                                <span style="font-size: 12px; font-weight: normal; margin-left: 10px; color: #856404;">
                                                    (Partners without charge type - needs configuration)
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($is_both): ?>
                                                <span style="font-size: 12px; font-weight: normal; margin-left: 10px; color: #0c5460;">
                                                    (Charge applies to both customers and partners)
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    
                                    <?php foreach ($group_data['rows'] as $row_data): 
                                        $is_settled = $row_data['is_fully_settled'];
                                        $is_partial = $row_data['is_partially_settled'];
                                        $status_class = $is_settled ? 'settled' : ($is_partial ? 'partial' : 'unsettled');
                                        $status_text = $is_settled ? 'Settled' : ($is_partial ? 'Partial' : 'Unsettled');
                                    ?>
                                        <tr class="data-row <?php echo $row_data['is_negative'] ? 'negative-row' : ''; ?> <?php echo $is_settled ? 'settled-row' : ($is_partial ? 'partial-row' : 'unsettled-row'); ?>" 
                                            data-row-index="<?php echo $row_data['row_index']; ?>"
                                            data-settlement="<?php echo $row_data['settlement_amount']; ?>"
                                            data-principal="<?php echo $row_data['principal']; ?>"
                                            data-charge-to-customer="<?php echo $row_data['charge_to_customer']; ?>"
                                            data-charge-to-partner="<?php echo $row_data['charge_to_partner']; ?>"
                                            data-adjustment="<?php echo $row_data['adjustment']; ?>"
                                            data-txn-count="<?php echo $row_data['txn_count']; ?>"
                                            data-partner-id="<?php echo $row_data['partner_id']; ?>"
                                            data-partner-name="<?php echo htmlspecialchars($row_data['partner_name']); ?>"
                                            data-is-settled="<?php echo $is_settled ? 'true' : 'false'; ?>"
                                            data-charge-to="<?php echo $row_data['charge_to']; ?>"
                                            data-service-charge="<?php echo $row_data['service_charge']; ?>">
                                            <td class="center checkbox-cell">
                                                <input type="checkbox" class="row-checkbox" 
                                                       data-row-index="<?php echo $row_data['row_index']; ?>"
                                                       onchange="updateTotals(); toggleReasonDropdown(this)"
                                                       <?php echo ($is_settled || $row_data['excluded']) ? 'disabled checked' : ''; ?>
                                                       <?php echo (!$cad_generated && !$is_settled) ? 'disabled' : ''; ?>>
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
                                                <?php if ($is_uncategorized): ?>
                                                    <span style="font-size: 10px; color: #856404; margin-left: 5px;">
                                                        <i class="fas fa-exclamation-circle"></i>
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($is_both): ?>
                                                    <span style="font-size: 10px; color: #0c5460; margin-left: 5px;">
                                                        <i class="fas fa-handshake"></i>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($row_data['account_name']); ?></td>
                                            <td class="center"><?php echo htmlspecialchars($row_data['account_number']); ?></td>
                                            <td class="center txn-count"><?php echo number_format($row_data['txn_count']); ?></td>
                                            <td class="right amount-col principal">₱ <?php echo number_format($row_data['principal'], 2); ?></td>
                                            <td class="right amount-col charge-to-customer">₱ <?php echo number_format($row_data['charge_to_customer'], 2); ?></td>
                                            <td class="right amount-col charge-to-partner">₱ <?php echo number_format($row_data['charge_to_partner'], 2); ?></td>
                                            <td class="right amount-col adjustment <?php echo $row_data['adjustment'] < 0 ? 'negative-amount' : ($row_data['adjustment'] > 0 ? 'positive-amount' : ''); ?>">
                                                <?php if ($row_data['adjustment'] != 0): ?>
                                                    <?php echo ($row_data['adjustment'] >= 0 ? '+' : ''); ?>₱ <?php echo number_format($row_data['adjustment'], 2); ?>
                                                <?php endif; ?>
                                            </td>
                                            <td class="right settlement-col settlement-amount <?php echo $row_data['is_negative'] ? 'negative-amount' : ''; ?>">
                                                ₱ <?php echo number_format($row_data['settlement_amount'], 2); ?>
                                            </td>
                                            <td class="center">
                                                <span class="status-badge <?php echo $status_class; ?>">
                                                    <?php if ($is_settled): ?>
                                                        <i class="fas fa-check-circle"></i> Settled
                                                    <?php elseif ($is_partial): ?>
                                                        <i class="fas fa-clock"></i> Partial
                                                        <span style="font-size: 10px; display: block; color: #666;">
                                                            <?php echo $row_data['settled_count']; ?> settled and <?php echo $row_data['unsettled_count']; ?> unsettled
                                                        </span>
                                                    <?php else: ?>
                                                        <i class="fas fa-clock"></i> Unsettled
                                                    <?php endif; ?>
                                                </span>
                                            </td>
                                            <td class="center reason-cell">
                                                <?php if ($is_settled): ?>
                                                    <span style="color: #6c757d; font-size: 12px;">N/A (Settled)</span>
                                                <?php else: ?>
                                                    <select class="reason-dropdown" 
                                                            data-row-index="<?php echo $row_data['row_index']; ?>"
                                                            data-partner-id="<?php echo $row_data['partner_id']; ?>"
                                                            <?php echo ($row_data['excluded'] || $is_settled) ? 'disabled' : ''; ?>
                                                            onchange="updateReason(this, <?php echo $row_data['row_index']; ?>)">
                                                        <option value="">Select Reason</option>
                                                        <?php foreach ($reason_options as $value => $label): ?>
                                                            <?php if ($value !== ''): ?>
                                                                <option value="<?php echo htmlspecialchars($value); ?>">
                                                                    <?php echo htmlspecialchars($label); ?>
                                                                </option>
                                                            <?php endif; ?>
                                                        <?php endforeach; ?>
                                                    </select>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        
                                        <?php if ($row_data['has_daily_breakdown'] && !empty($row_data['daily_html'])): ?>
                                            <tr class="daily-breakdown-container" 
                                                id="dailyBreakdown_<?php echo $row_data['row_index']; ?>" 
                                                style="display: none;">
                                                <td colspan="13">
                                                    <?php echo $row_data['daily_html']; ?>
                                                </td>
                                            </tr>
                                        <?php elseif ($row_data['has_daily_breakdown'] && empty($row_data['daily_html'])): ?>
                                            <tr class="daily-breakdown-container" 
                                                id="dailyBreakdown_<?php echo $row_data['row_index']; ?>" 
                                                style="display: none;">
                                                <td colspan="13" class="daily-breakdown-error">
                                                    <i class="fas fa-info-circle"></i>
                                                    No daily transactions found for this date range.
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                    
                                    <tr class="group-subtotal-row <?php echo $is_uncategorized ? 'uncategorized-subtotal' : ''; ?> <?php echo $is_both ? 'both-subtotal' : ''; ?>" data-group="<?php echo $group_key; ?>">
                                        <td colspan="5" style="text-align: right;">
                                            <strong>Subtotal - <?php echo htmlspecialchars($group_data['display_name']); ?></strong>
                                        </td>
                                        <td class="center group-txn-count"><?php echo number_format($group_data['totals']['txn_count']); ?></td>
                                        <td class="right group-principal">₱ <?php echo number_format($group_data['totals']['principal'], 2); ?></td>
                                        <td class="right group-charge-to-customer">₱ <?php echo number_format($group_data['totals']['charge_to_customer'], 2); ?></td>
                                        <td class="right group-charge-to-partner">₱ <?php echo number_format($group_data['totals']['charge_to_partner'], 2); ?></td>
                                        <td class="right group-adjustment <?php echo $group_data['totals']['adjustment'] < 0 ? 'negative-amount' : ($group_data['totals']['adjustment'] > 0 ? 'positive-amount' : ''); ?>">
                                            <?php if ($group_data['totals']['adjustment'] != 0): ?>
                                                <?php echo ($group_data['totals']['adjustment'] >= 0 ? '+' : ''); ?>₱ <?php echo number_format($group_data['totals']['adjustment'], 2); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td class="right settlement-col group-settlement <?php echo $group_data['totals']['settlement'] < 0 ? 'negative-amount' : ''; ?>">
                                            ₱ <?php echo number_format($group_data['totals']['settlement'], 2); ?>
                                        </td>
                                        <td class="center group-status">
                                            <?php 
                                            $g_settled = $group_data['totals']['settled_count'] ?? 0;
                                            $g_unsettled = $group_data['totals']['unsettled_count'] ?? 0;
                                            if ($g_settled > 0 && $g_unsettled == 0): ?>
                                                <span class="settled-status"><i class="fas fa-check-circle"></i> All Settled</span>
                                            <?php elseif ($g_settled > 0 && $g_unsettled > 0): ?>
                                                <span style="color: #856404;"><i class="fas fa-clock"></i> <?php echo number_format($g_settled); ?> Settled / <?php echo number_format($g_unsettled); ?> Unsettled</span>
                                            <?php else: ?>
                                                <span class="unsettled-status"><i class="fas fa-clock"></i> All Unsettled</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="center group-reason"></td>
                                    </tr>
                                    
                                    <?php if (!$is_last_group): ?>
                                        <tr style="height: 8px; background: transparent;">
                                            <td colspan="13" style="border: none; padding: 0;"></td>
                                        </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                
                                <tr class="grand-total-row">
                                    <td colspan="5" style="text-align: right;">GRAND TOTAL</td>
                                    <td class="center grand-txn-count"><?php echo number_format($grand_totals['txn_count']); ?></td>
                                    <td class="right grand-principal">₱ <?php echo number_format($grand_totals['principal'], 2); ?></td>
                                    <td class="right grand-charge-to-customer">₱ <?php echo number_format($grand_totals['charge_to_customer'], 2); ?></td>
                                    <td class="right grand-charge-to-partner">₱ <?php echo number_format($grand_totals['charge_to_partner'], 2); ?></td>
                                    <td class="right grand-adjustment <?php echo $grand_totals['adjustment'] < 0 ? 'negative-amount' : ($grand_totals['adjustment'] > 0 ? 'positive-amount' : ''); ?>">
                                        <?php if ($grand_totals['adjustment'] != 0): ?>
                                            <?php echo ($grand_totals['adjustment'] >= 0 ? '+' : ''); ?>₱ <?php echo number_format($grand_totals['adjustment'], 2); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="right settlement-col grand-settlement <?php echo $grand_totals['settlement'] < 0 ? 'negative-amount' : ''; ?>">
                                        ₱ <?php echo number_format($grand_totals['settlement'], 2); ?>
                                    </td>
                                    <td class="center grand-status">
                                        <?php 
                                        $g_settled = $grand_totals['settled_count'] ?? 0;
                                        $g_unsettled = $grand_totals['unsettled_count'] ?? 0;
                                        if ($g_settled > 0 && $g_unsettled == 0): ?>
                                            <span class="settled-status"><i class="fas fa-check-circle"></i> All Settled</span>
                                        <?php elseif ($g_settled > 0 && $g_unsettled > 0): ?>
                                            <span style="color: #856404;"><i class="fas fa-clock"></i> <?php echo number_format($g_settled); ?> Settled / <?php echo number_format($g_unsettled); ?> Unsettled</span>
                                        <?php else: ?>
                                            <span class="unsettled-status"><i class="fas fa-clock"></i> All Unsettled</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="center grand-reason"></td>
                                </tr>
                            </tbody>
                        </table>
                        
                        <div class="export-buttons">
                            <button class="btn-export excel" onclick="exportToExcel()">
                                <i class="fas fa-file-excel"></i> Export Excel
                            </button>
                            <button class="btn-export pdf" onclick="exportToPDF()">
                                <i class="fa-solid fa-file-pdf"></i> Export PDF
                            </button>
                            <button class="btn-export settle <?php echo !$cad_generated ? 'btn-disabled' : ''; ?>" 
                                    onclick="settleSelected()" 
                                    <?php echo !$cad_generated ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : ''; ?> 
                                    style="margin-left: 10px;">
                                <i class="fas fa-check-circle"></i> Settle
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
    var reasonData = {};
    
    // NOTE: Do NOT store CAD number here from PHP – it becomes stale after AJAX filter.
    // Always read the live value from #currentCadNo (updated with the results).
    
    $(document).ready(function() {
        var initialPartner = $('#partner').data('selected') || '';
        var initialBank = $('#bank').data('selected') || '';
        var initialSettlement = $('#settlement_type').data('selected') || '';
        
        $('.select2-dropdown').select2({
            placeholder: function() {
                return $(this).data('placeholder') || 'Select an option';
            },
            allowClear: true,
            width: '100%'
        });
        
        // Auto-populate bank when partner is selected
        $('#partner').on('change', function() {
            var selectedOption = $(this).find('option:selected');
            var bank = selectedOption.data('bank') || '';
            if (bank) {
                $('#bank').val(bank).trigger('change');
            } else {
                $('#bank').val('').trigger('change');
            }
        });
        
        if (initialPartner) {
            $('#partner').val(initialPartner).trigger('change');
        }
        if (initialBank) {
            $('#bank').val(initialBank).trigger('change');
        }
        if (initialSettlement) {
            $('#settlement_type').val(initialSettlement).trigger('change');
        }

        startLoadingTimer();
        startStepAnimation();

        $(window).on('load', function() {
            isPageLoaded = true;
            setTimeout(function() {
                hideLoadingModal();
                showMainContent();
            }, 500);
        });

        setTimeout(function() {
            if (!isPageLoaded) {
                var hasContent = $('#resultsContainer').children().length > 0;
                if (hasContent) {
                    hideLoadingModal();
                    showMainContent();
                }
            }
        }, 10000);

        $('#filterForm').on('submit', function(e) {
            e.preventDefault();
            
            var hasFilters = false;
            $(this).find('select, input[type="date"], input[name="rfp_no"]').each(function() {
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

    function toggleDailyBreakdown(element, rowIndex) {
        var breakdownRow = document.getElementById('dailyBreakdown_' + rowIndex);
        var chevron = element;
        
        if (!breakdownRow) {
            return;
        }
        
        if (breakdownRow.style.display !== 'none') {
            breakdownRow.style.display = 'none';
            chevron.classList.remove('expanded');
            chevron.innerHTML = '<i class="fas fa-chevron-down"></i>';
            return;
        }
        
        breakdownRow.style.display = 'table-row';
        chevron.classList.add('expanded');
        chevron.innerHTML = '<i class="fas fa-chevron-up"></i>';
    }

    // ============================================
    // REASON NOT SETTLED FUNCTIONS
    // ============================================
    
    function toggleReasonDropdown(checkbox) {
        var row = $(checkbox).closest('.data-row');
        var reasonSelect = row.find('.reason-dropdown');
        var isChecked = $(checkbox).prop('checked');
        var isDisabled = $(checkbox).prop('disabled');
        
        if (isChecked) {
            reasonSelect.prop('disabled', true);
            reasonSelect.val('');
            reasonSelect.css('opacity', '0.5');
            // Remove stored reason
            var rowIndex = row.data('row-index');
            if (rowIndex !== undefined) {
                delete reasonData[rowIndex];
            }
        } else if (!isDisabled) {
            reasonSelect.prop('disabled', false);
            reasonSelect.css('opacity', '1');
        }
    }

    function updateReason(element, rowIndex) {
        var reason = $(element).val();
        var partnerId = $(element).data('partner-id');
        
        if (reason) {
            reasonData[rowIndex] = {
                reason: reason,
                partner_id: partnerId
            };
        } else {
            delete reasonData[rowIndex];
        }
    }

    function toggleAllRows(checkbox) {
        var isChecked = $(checkbox).prop('checked');
        var cadGenerated = $('#cadGenerated').val() === 'true';
        
        // If CAD is not generated, only allow toggling settled rows
        $('.row-checkbox:not(:disabled)').each(function() {
            var row = $(this).closest('.data-row');
            var isSettled = row.data('is-settled') === true;
            
            if (!cadGenerated && !isSettled) {
                // Don't toggle unsettled rows when CAD is invalid
                return;
            }
            
            $(this).prop('checked', isChecked);
            toggleReasonDropdown(this);
        });
        updateTotals();
    }
    
    function updateTotals() {
        var dataRows = $('.data-row');
        var groupTotals = {};
        var grandTotals = {
            txn_count: 0,
            principal: 0,
            charge_to_customer: 0,
            charge_to_partner: 0,
            adjustment: 0,
            settlement: 0,
            settled_count: 0,
            unsettled_count: 0
        };
        
        dataRows.each(function() {
            var row = $(this);
            var checkbox = row.find('.row-checkbox');
            var isChecked = checkbox.prop('checked') && !checkbox.prop('disabled');
            var isSettled = row.data('is-settled') === true;
            
            var txnCount = parseInt(row.data('txn-count')) || 0;
            var principal = parseFloat(row.data('principal')) || 0;
            var chargeToCustomer = parseFloat(row.data('charge-to-customer')) || 0;
            var chargeToPartner = parseFloat(row.data('charge-to-partner')) || 0;
            var adjustment = parseFloat(row.data('adjustment')) || 0;
            var settlement = parseFloat(row.data('settlement')) || 0;
            
            var groupRow = row.prevAll('.group-header-row').first();
            var groupKey = groupRow.find('td').text().trim();
            
            if (!groupTotals[groupKey]) {
                groupTotals[groupKey] = {
                    txn_count: 0,
                    principal: 0,
                    charge_to_customer: 0,
                    charge_to_partner: 0,
                    adjustment: 0,
                    settlement: 0,
                    settled_count: 0,
                    unsettled_count: 0
                };
            }
            
            if (isSettled) {
                groupTotals[groupKey].settled_count += txnCount;
            } else {
                groupTotals[groupKey].unsettled_count += txnCount;
            }
            
            if (isChecked) {
                groupTotals[groupKey].txn_count += txnCount;
                groupTotals[groupKey].principal += principal;
                groupTotals[groupKey].charge_to_customer += chargeToCustomer;
                groupTotals[groupKey].charge_to_partner += chargeToPartner;
                groupTotals[groupKey].adjustment += adjustment;
                groupTotals[groupKey].settlement += settlement;
                
                grandTotals.txn_count += txnCount;
                grandTotals.principal += principal;
                grandTotals.charge_to_customer += chargeToCustomer;
                grandTotals.charge_to_partner += chargeToPartner;
                grandTotals.adjustment += adjustment;
                grandTotals.settlement += settlement;
            }
            
            if (isSettled) {
                grandTotals.settled_count += txnCount;
            } else {
                grandTotals.unsettled_count += txnCount;
            }
        });
        
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
                groupRow.find('.group-charge-to-customer').text('₱ ' + formatNumberDecimal(totals.charge_to_customer));
                groupRow.find('.group-charge-to-partner').text('₱ ' + formatNumberDecimal(totals.charge_to_partner));
                
                if (totals.adjustment != 0) {
                    var adjText = (totals.adjustment >= 0 ? '+' : '') + '₱ ' + formatNumberDecimal(totals.adjustment);
                    groupRow.find('.group-adjustment').text(adjText);
                    groupRow.find('.group-adjustment').removeClass('negative-amount positive-amount');
                    if (totals.adjustment < 0) {
                        groupRow.find('.group-adjustment').addClass('negative-amount');
                    } else if (totals.adjustment > 0) {
                        groupRow.find('.group-adjustment').addClass('positive-amount');
                    }
                } else {
                    groupRow.find('.group-adjustment').text('');
                    groupRow.find('.group-adjustment').removeClass('negative-amount positive-amount');
                }
                
                groupRow.find('.group-settlement').text('₱ ' + formatNumberDecimal(totals.settlement));
                groupRow.find('.group-settlement').removeClass('negative-amount');
                if (totals.settlement < 0) {
                    groupRow.find('.group-settlement').addClass('negative-amount');
                }
                
                var gSettled = totals.settled_count || 0;
                var gUnsettled = totals.unsettled_count || 0;
                var statusHtml = '';
                if (gSettled > 0 && gUnsettled == 0) {
                    statusHtml = '<span class="settled-status"><i class="fas fa-check-circle"></i> All Settled</span>';
                } else if (gSettled > 0 && gUnsettled > 0) {
                    statusHtml = '<span style="color: #856404;"><i class="fas fa-clock"></i> ' + formatNumberInt(gSettled) + ' Settled / ' + formatNumberInt(gUnsettled) + ' Unsettled</span>';
                } else {
                    statusHtml = '<span class="unsettled-status"><i class="fas fa-clock"></i> All Unsettled</span>';
                }
                groupRow.find('.group-status').html(statusHtml);
            }
        });
        
        $('.grand-total-row').find('.grand-txn-count').text(formatNumberInt(grandTotals.txn_count));
        $('.grand-total-row').find('.grand-principal').text('₱ ' + formatNumberDecimal(grandTotals.principal));
        $('.grand-total-row').find('.grand-charge-to-customer').text('₱ ' + formatNumberDecimal(grandTotals.charge_to_customer));
        $('.grand-total-row').find('.grand-charge-to-partner').text('₱ ' + formatNumberDecimal(grandTotals.charge_to_partner));
        
        if (grandTotals.adjustment != 0) {
            var grandAdjText = (grandTotals.adjustment >= 0 ? '+' : '') + '₱ ' + formatNumberDecimal(grandTotals.adjustment);
            $('.grand-total-row').find('.grand-adjustment').text(grandAdjText);
            $('.grand-total-row').find('.grand-adjustment').removeClass('negative-amount positive-amount');
            if (grandTotals.adjustment < 0) {
                $('.grand-total-row').find('.grand-adjustment').addClass('negative-amount');
            } else if (grandTotals.adjustment > 0) {
                $('.grand-total-row').find('.grand-adjustment').addClass('positive-amount');
            }
        } else {
            $('.grand-total-row').find('.grand-adjustment').text('');
            $('.grand-total-row').find('.grand-adjustment').removeClass('negative-amount positive-amount');
        }
        
        $('.grand-total-row').find('.grand-settlement').text('₱ ' + formatNumberDecimal(grandTotals.settlement));
        $('.grand-total-row').find('.grand-settlement').removeClass('negative-amount');
        if (grandTotals.settlement < 0) {
            $('.grand-total-row').find('.grand-settlement').addClass('negative-amount');
        }
        
        var gSettled = grandTotals.settled_count || 0;
        var gUnsettled = grandTotals.unsettled_count || 0;
        var statusHtml = '';
        if (gSettled > 0 && gUnsettled == 0) {
            statusHtml = '<span class="settled-status"><i class="fas fa-check-circle"></i> All Settled</span>';
        } else if (gSettled > 0 && gUnsettled > 0) {
            statusHtml = '<span style="color: #856404;"><i class="fas fa-clock"></i> ' + formatNumberInt(gSettled) + ' Settled / ' + formatNumberInt(gUnsettled) + ' Unsettled</span>';
        } else {
            statusHtml = '<span class="unsettled-status"><i class="fas fa-clock"></i> All Unsettled</span>';
        }
        $('.grand-total-row').find('.grand-status').html(statusHtml);
        
        var totalCheckboxes = $('.row-checkbox:not(:disabled)').length;
        var checkedCheckboxes = $('.row-checkbox:not(:disabled):checked').length;
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

    function exportToExcel() {
        var rfpNo = $('#rfp_no').val().trim();
        if (!rfpNo) {
            Swal.fire({
                icon: 'warning',
                title: 'RFP No. Required',
                text: 'Please enter an RFP No. before exporting.',
                confirmButtonColor: '#ffc107'
            });
            return;
        }
        
        var hasUnsettled = false;
        $('.data-row').each(function() {
            var isSettled = $(this).data('is-settled') === true;
            if (!isSettled) {
                hasUnsettled = true;
                return false;
            }
        });
        
        if (hasUnsettled) {
            Swal.fire({
                icon: 'warning',
                title: 'Unsettled Transactions',
                text: 'Cannot export. Please settle all transactions before exporting.',
                confirmButtonColor: '#ffc107'
            });
            return;
        }
        
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
            exportUrl += '&rfp_no=' + encodeURIComponent(rfpNo);
            
            if (excludedRows.length > 0) {
                exportUrl += '&excluded_rows=' + encodeURIComponent(excludedRows.join(','));
            }
            
            // Include reason data for excluded rows
            var reasonDataStr = JSON.stringify(reasonData);
            exportUrl += '&reason_data=' + encodeURIComponent(reasonDataStr);
            
            window.open(exportUrl, '_blank');
            
            setTimeout(function() {
                hideLoadingModal();
            }, 500);
        }, 300);
    }

    function exportToPDF() {
        var rfpNo = $('#rfp_no').val().trim();
        if (!rfpNo) {
            Swal.fire({
                icon: 'warning',
                title: 'RFP No. Required',
                text: 'Please enter an RFP No. before exporting.',
                confirmButtonColor: '#ffc107'
            });
            return;
        }
        
        var hasUnsettled = false;
        $('.data-row').each(function() {
            var isSettled = $(this).data('is-settled') === true;
            if (!isSettled) {
                hasUnsettled = true;
                return false;
            }
        });
        
        if (hasUnsettled) {
            Swal.fire({
                icon: 'warning',
                title: 'Unsettled Transactions',
                text: 'Cannot export. Please settle all transactions before exporting.',
                confirmButtonColor: '#ffc107'
            });
            return;
        }
        
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
            
            var exportUrl = 'export_bank_settlement_pdf.php?';
            exportUrl += 'partner=' + encodeURIComponent(partner);
            exportUrl += '&bank=' + encodeURIComponent(bank);
            exportUrl += '&settlement_type=' + encodeURIComponent(settlementType);
            exportUrl += '&date_from=' + encodeURIComponent(dateFrom);
            exportUrl += '&date_to=' + encodeURIComponent(dateTo);
            exportUrl += '&rfp_no=' + encodeURIComponent(rfpNo);
            
            if (excludedRows.length > 0) {
                exportUrl += '&excluded_rows=' + encodeURIComponent(excludedRows.join(','));
            }
            
            // Include reason data for excluded rows
            var reasonDataStr = JSON.stringify(reasonData);
            exportUrl += '&reason_data=' + encodeURIComponent(reasonDataStr);
            
            window.open(exportUrl, '_blank');
            
            setTimeout(function() {
                hideLoadingModal();
            }, 500);
        }, 300);
    }

    // ============================================
    // SETTLE FUNCTION - UPDATED (No page reload)
    // ============================================
    function settleSelected() {
        // Check if CAD is valid
        var cadGenerated = $('#cadGenerated').val() === 'true';
        if (!cadGenerated) {
            Swal.fire({
                icon: 'error',
                title: 'Cannot Settle',
                text: 'The selected partner does not have a valid bank abbreviation configured. Please fix the partner\'s bank configuration first.',
                confirmButtonColor: '#dc3545'
            });
            return;
        }
        
        // Get RFP No. from input
        var rfpNo = $('#rfp_no').val().trim();
        
        if (!rfpNo) {
            Swal.fire({
                icon: 'warning',
                title: 'RFP No. Required',
                text: 'Please enter an RFP No.',
                confirmButtonColor: '#ffc107'
            });
            return;
        }
        
        // Read the CURRENT CAD number from the results area (survives AJAX filter)
        var cadNo = $('#currentCadNo').val() || '';
        
        // Fallback – extract from the badge text if the hidden input is missing
        if (!cadNo) {
            var badgeText = $('#currentCadBadge').text() || $('.table-badge:contains("CAD No.")').first().text() || '';
            var match = badgeText.match(/CAD No\.:\s*([A-Z0-9\-]+)/i);
            if (match) {
                cadNo = match[1].trim();
            }
        }
        
        if (!cadNo) {
            Swal.fire({
                icon: 'warning',
                title: 'CAD No. Missing',
                text: 'CAD No. is not available. Please refresh the page and try again.',
                confirmButtonColor: '#ffc107'
            });
            return;
        }
        
        // Get all checked rows that are NOT settled
        var checkedRows = [];
        var totalVolume = 0;
        var totalSettlement = 0;
        var skippedSettled = 0;
        var unsettledReasons = [];
        
        $('.data-row').each(function() {
            var checkbox = $(this).find('.row-checkbox');
            var isSettled = $(this).data('is-settled') === true;
            
            if (checkbox.prop('checked') && !checkbox.prop('disabled')) {
                if (isSettled) {
                    skippedSettled++;
                    return;
                }
                
                var partnerId = $(this).data('partner-id');
                var partnerName = $(this).data('partner-name') || $(this).find('.partner-name-cell').text().trim();
                var txnCount = parseInt($(this).data('txn-count')) || 0;
                var settlement = parseFloat($(this).data('settlement')) || 0;
                var rowIndex = $(this).data('row-index');
                
                checkedRows.push({
                    partner_id: partnerId,
                    partner_name: partnerName,
                    txn_count: txnCount,
                    settlement_amount: settlement,
                    row_index: rowIndex
                });
                totalVolume += txnCount;
                totalSettlement += settlement;
            }
        });
        
        // Check for unchecked rows with reasons
        $('.data-row').each(function() {
            var checkbox = $(this).find('.row-checkbox');
            var isSettled = $(this).data('is-settled') === true;
            var rowIndex = $(this).data('row-index');
            
            if (!checkbox.prop('checked') && !isSettled && rowIndex !== undefined) {
                var reason = reasonData[rowIndex];
                if (reason && reason.reason) {
                    unsettledReasons.push({
                        partner_id: reason.partner_id,
                        reason: reason.reason
                    });
                }
            }
        });
        
        if (checkedRows.length === 0) {
            var msg = 'No unsettled rows selected.';
            if (skippedSettled > 0) {
                msg += ' ' + skippedSettled + ' selected row(s) are already settled and cannot be processed again.';
            }
            Swal.fire({
                icon: 'warning',
                title: 'No Rows to Settle',
                text: msg,
                confirmButtonColor: '#ffc107'
            });
            return;
        }
        
        var skipMsg = skippedSettled > 0 ? `<p style="color: #856404; margin-top: 10px;"><i class="fas fa-info-circle"></i> ${skippedSettled} already settled row(s) were skipped.</p>` : '';
        
        var reasonMsg = '';
        if (unsettledReasons.length > 0) {
            reasonMsg = `
                <div style="background: #f8f9fa; padding: 10px; border-radius: 4px; margin-top: 10px;">
                    <p><strong>Reasons for not settling:</strong></p>
                    <ul style="margin: 5px 0; padding-left: 20px;">
                        ${unsettledReasons.map(r => `<li>${r.partner_id}: ${r.reason}</li>`).join('')}
                    </ul>
                </div>
            `;
        }
        
        Swal.fire({
            title: 'Settle Selected Transactions?',
            html: `
                <div style="text-align: left; max-height: 300px; overflow-y: auto;">
                    <p><strong>RFP No.:</strong> ${rfpNo}</p>
                    <p><strong>CAD No.:</strong> ${cadNo}</p>
                    <p><strong>You are about to settle:</strong></p>
                    <ul style="margin: 10px 0; padding-left: 20px;">
                        ${checkedRows.map(row => 
                            `<li><strong>${row.partner_name}</strong> - ${row.txn_count.toLocaleString()} transactions (₱ ${row.settlement_amount.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})})</li>`
                        ).join('')}
                    </ul>
                    <div style="background: #f8f9fa; padding: 10px; border-radius: 4px; margin-top: 10px;">
                        <p><strong>Total Partners:</strong> ${checkedRows.length}</p>
                        <p><strong>Total Volume Count:</strong> ${totalVolume.toLocaleString()}</p>
                        <p><strong>Total Settlement Amount:</strong> ₱ ${totalSettlement.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</p>
                    </div>
                    ${reasonMsg}
                    <p style="color: #856404; margin-top: 10px;">
                        <i class="fas fa-exclamation-triangle"></i> 
                        This will mark <strong>ALL</strong> unsettled transactions for the selected partners as <strong>SETTLED</strong>.
                    </p>
                    <p style="color: #0c5460; margin-top: 5px;">
                        <i class="fas fa-info-circle"></i> 
                        Settled By: <strong><?php echo $display_name; ?></strong>
                    </p>
                    ${skipMsg}
                </div>
            `,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#28a745',
            cancelButtonColor: '#dc3545',
            confirmButtonText: 'Yes, Settle Selected',
            cancelButtonText: 'Cancel',
            preConfirm: () => {
                return new Promise((resolve) => {
                    Swal.fire({
                        title: 'Processing Settlement',
                        html: 'Please wait while we process the settlement...<br><small>This may take a few moments.</small>',
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                    
                    var settledBy = '<?php echo addslashes($display_name); ?>';
                    var partnerIds = checkedRows.map(row => row.partner_id);
                    
                    var partner = $('#partner').val() || '';
                    var bank = $('#bank').val() || '';
                    var settlementType = $('#settlement_type').val() || '';
                    var dateFrom = $('#date_from').val() || '';
                    var dateTo = $('#date_to').val() || '';
                    
                    // Prepare reason data for unsettled rows
                    var reasonDataStr = JSON.stringify(reasonData);
                    
                    $.ajax({
                        url: 'settle_transactions.php',
                        type: 'POST',
                        data: {
                            partner_ids: partnerIds,
                            settled_by: settledBy,
                            rfp_no: rfpNo,
                            cad_no: cadNo,
                            partner_filter: partner,
                            bank_filter: bank,
                            settlement_type_filter: settlementType,
                            date_from: dateFrom,
                            date_to: dateTo,
                            reason_data: reasonDataStr
                        },
                        dataType: 'json',
                        timeout: 120000,
                        success: function(response) {
                            if (response.success) {
                                resolve({ success: true, message: response.message, data: response.data, partnerIds: partnerIds });
                            } else {
                                resolve({ success: false, message: response.message || 'Settlement failed.' });
                            }
                        },
                        error: function(xhr, status, error) {
                            var errorMsg = 'An error occurred while processing settlement.';
                            if (status === 'timeout') {
                                errorMsg = 'Request timed out. The settlement may still be processing. Please check the status.';
                            }
                            resolve({ success: false, message: errorMsg });
                        }
                    });
                });
            }
        }).then((result) => {
            if (result.isConfirmed && result.value) {
                if (result.value.success) {
                    // Update the UI to reflect settlement changes WITHOUT reloading the page
                    var settledPartnerIds = result.value.partnerIds || [];
                    
                    // 1. Mark the settled rows as settled in the UI
                    $('.data-row').each(function() {
                        var partnerId = $(this).data('partner-id');
                        if (settledPartnerIds.includes(partnerId)) {
                            // Update data attribute
                            $(this).data('is-settled', true);
                            
                            // Update checkbox - disable and check it
                            var checkbox = $(this).find('.row-checkbox');
                            checkbox.prop('disabled', true);
                            checkbox.prop('checked', true);
                            
                            // Update status badge
                            var statusCell = $(this).find('.status-badge');
                            statusCell.removeClass('unsettled partial');
                            statusCell.addClass('settled');
                            statusCell.html('<i class="fas fa-check-circle"></i> Settled');
                            
                            // Update reason dropdown - disable and hide
                            var reasonSelect = $(this).find('.reason-dropdown');
                            reasonSelect.prop('disabled', true);
                            reasonSelect.val('');
                            reasonSelect.css('opacity', '0.5');
                            
                            // Update row styling
                            $(this).removeClass('unsettled-row partial-row');
                            $(this).addClass('settled-row');
                            
                            // Remove from reason data
                            var rowIndex = $(this).data('row-index');
                            if (rowIndex !== undefined) {
                                delete reasonData[rowIndex];
                            }
                        }
                    });
                    
                    // 2. Recalculate all totals
                    updateTotals();
                    
                    // 3. Show success message
                    Swal.fire({
                        icon: 'success',
                        title: 'Settlement Successful',
                        html: `
                            <p>${result.value.message}</p>
                            ${result.value.data ? `
                                <div style="text-align: left; margin-top: 15px; background: #f8f9fa; padding: 15px; border-radius: 4px;">
                                    <strong>Settlement Details:</strong>
                                    <ul style="margin-top: 10px; padding-left: 20px;">
                                        <li>RFP No.: ${rfpNo}</li>
                                        <li>CAD No.: ${cadNo}</li>
                                        <li>Total Partners: ${result.value.data.total_partners}</li>
                                        <li>Total Transactions: ${result.value.data.total_transactions.toLocaleString()}</li>
                                        <li>Total Amount: ₱ ${result.value.data.total_amount.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</li>
                                        <li>Settled By: ${result.value.data.settled_by}</li>
                                        <li>Settlement Date: ${result.value.data.settlement_date}</li>
                                    </ul>
                                </div>
                            ` : ''}
                            ${reasonData && Object.keys(reasonData).length > 0 ? `
                                <div style="text-align: left; margin-top: 15px; background: #fff3cd; padding: 15px; border-radius: 4px; border: 1px solid #ffc107;">
                                    <strong><i class="fas fa-info-circle"></i> Reasons for Not Settled:</strong>
                                    <ul style="margin-top: 10px; padding-left: 20px;">
                                        ${Object.values(reasonData).map(r => `<li>${r.partner_id}: ${r.reason}</li>`).join('')}
                                    </ul>
                                </div>
                            ` : ''}
                            <div style="text-align: left; margin-top: 15px; background: #d4edda; padding: 15px; border-radius: 4px; border: 1px solid #28a745;">
                                <strong><i class="fas fa-check-circle"></i> Ready to Export:</strong>
                                <p style="margin-top: 5px;">You can now export this settlement to Excel or PDF using the buttons above.</p>
                            </div>
                        `,
                        confirmButtonColor: '#28a745',
                        confirmButtonText: 'OK'
                    });
                    
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Settlement Failed',
                        text: result.value.message,
                        confirmButtonColor: '#dc3545'
                    });
                }
            }
        });
    }
</script>

</body>
</html>