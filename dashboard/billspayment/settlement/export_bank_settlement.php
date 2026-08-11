<?php
// export_bank_settlement.php
// Add cache control headers
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

// Function to calculate settlement amount based on charge type (same as main file)
// ============================================
// FUNCTION: Calculate settlement amount based on charge type
// ============================================
function calculateSettlementAmount($charge_to, $service_charge, $principal, $charge_to_customer, $charge_to_partner, $adjustment) {
    $charge_to_upper = strtoupper(trim($charge_to));
    $service_charge_upper = strtoupper(trim($service_charge));
    
    // For WEEKLY, MONTHLY, SEMI-MONTHLY: Amount = Principal + Adjustment (no charge deduction)
    if ($charge_to_upper === 'PARTNER' && in_array($service_charge_upper, ['WEEKLY', 'MONTHLY', 'SEMI-MONTHLY'])) {
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

// Get filter values from GET parameters
$selected_partner = isset($_GET['partner']) ? trim($_GET['partner']) : '';
$selected_bank = isset($_GET['bank']) ? trim($_GET['bank']) : '';
$selected_settlement_type = isset($_GET['settlement_type']) ? trim($_GET['settlement_type']) : '';
$selected_date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$selected_date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$selected_rfp_no = isset($_GET['rfp_no']) ? trim($_GET['rfp_no']) : '';

// Validate RFP No.
if (empty($selected_rfp_no)) {
    die("RFP No. is required for Excel export.");
}

// Get excluded rows from GET parameters
$excluded_rows = isset($_GET['excluded_rows']) ? explode(',', trim($_GET['excluded_rows'])) : [];
$excluded_rows = array_filter($excluded_rows, 'is_numeric');

// Get current user name for Prepared By
$display_name = 'GUEST';
if (isset($_SESSION['user_type'])) {
    if ($_SESSION['user_type'] === 'admin') {
        $display_name = $_SESSION['admin_name'] ?? 'ADMIN';
    } elseif ($_SESSION['user_type'] === 'user') {
        $display_name = $_SESSION['user_name'] ?? 'USER';
    }
}

/**
 * Get bank abbreviation from multiple sources (IMPROVED - matches settlement-per-bank.php)
 */
function getBankAbbreviation(mysqli $conn, string $bank_name): string {
    if (empty($bank_name)) return '';

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

    // Try 2: LIKE match in mldb.bank_table
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

    // Try 4: Known bank abbreviations (expanded)
    $known_banks = [
        'ASIA UNITED BANK CORPORATION' => 'AUB',
        'ASIA UNITED BANK CORPORATION (AUB)' => 'AUB',
        'ASIA UNITED BANK' => 'AUB',
        'ASIA UNITED' => 'AUB',
        'AUB' => 'AUB',
        'BANK OF THE PHILIPPINE ISLANDS' => 'BPI',
        'BANK OF THE PHILIPPINE ISLANDS (BPI)' => 'BPI',
        'BPI' => 'BPI',
        'BANCO DE ORO' => 'BDO',
        'BANCO DE ORO (BDO)' => 'BDO',
        'BDO UNIBANK' => 'BDO',
        'BDO' => 'BDO',
        'METROPOLITAN BANK & TRUST COMPANY' => 'MBT',
        'METROPOLITAN BANK AND TRUST COMPANY' => 'MBT',
        'METROPOLITAN BANK & TRUST COMPANY (METROBANK)' => 'MBT',
        'METROBANK' => 'MBT',
        'MBT' => 'MBT',
        'PHILIPPINE NATIONAL BANK' => 'PNB',
        'PHILIPPINE NATIONAL BANK (PNB)' => 'PNB',
        'PNB' => 'PNB',
        'UNION BANK OF THE PHILIPPINES' => 'UBP',
        'UNION BANK OF THE PHILIPPINES (UNIONBANK)' => 'UBP',
        'UNIONBANK' => 'UBP',
        'UBP' => 'UBP',
        'SECURITY BANK CORPORATION' => 'SBC',
        'SECURITY BANK' => 'SBC',
        'SBC' => 'SBC',
        'CHINA BANKING CORPORATION' => 'CBC',
        'CHINA BANK' => 'CBC',
        'CBC' => 'CBC',
        'LAND BANK OF THE PHILIPPINES' => 'LBP',
        'LAND BANK OF THE PHILIPPINES (LBP)' => 'LBP',
        'LANDBANK' => 'LBP',
        'LBP' => 'LBP',
        'DEVELOPMENT BANK OF THE PHILIPPINES' => 'DBP',
        'DEVELOPMENT BANK OF THE PHILIPPINES (DBP)' => 'DBP',
        'DBP' => 'DBP',
    ];

    foreach ($known_banks as $known_name => $abbr) {
        if (stripos($bank_name_upper, $known_name) !== false || stripos($known_name, $bank_name_upper) !== false) {
            return $abbr;
        }
    }

    // Try 5: first-letter fallback
    $words = preg_split('/[\s,()&\-]+/', $bank_name);
    $abbr = '';
    foreach ($words as $word) {
        $word = trim($word);
        if (!empty($word) && strlen($word) > 1 && !in_array(strtoupper($word), ['OF','THE','AND','BANK','CORPORATION','CORP','INC','LTD'])) {
            $abbr .= strtoupper($word[0]);
        }
    }
    if (strlen($abbr) >= 2) {
        return substr($abbr, 0, 4);
    }

    return '';
}

/**
 * Get settlement type abbreviation
 */
function getSettlementAbbreviation(string $settlement_type): string {
    if (empty($settlement_type)) return '';
    $type = strtoupper(trim($settlement_type));
    if ($type === 'CHECK' || $type === 'CHEQUE') return 'CHK';
    if ($type === 'ONLINE' || $type === 'ONL') return 'ONL';
    return strtoupper(substr($type, 0, 3));
}

/**
 * Format date for CAD number (YYYY-MM-000DD)
 */
function formatCADDate(?string $date_from, ?string $date_to): string {
    if (empty($date_from) && empty($date_to)) {
        return date('Y-m') . '-' . sprintf('%05d', (int)date('d'));
    }

    $date = !empty($date_to) ? $date_to : $date_from;
    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return date('Y-m') . '-' . sprintf('%05d', (int)date('d'));
    }
    return date('Y-m', $timestamp) . '-' . sprintf('%05d', (int)date('d', $timestamp));
}

/**
 * Format date range for display
 */
function formatDateRange(?string $date_from, ?string $date_to): string {
    if (empty($date_from) && empty($date_to)) {
        return strtoupper(date('F d, Y'));
    }
    
    $from = strtotime($date_from);
    $to = !empty($date_to) ? strtotime($date_to) : $from;
    
    if ($from == $to) {
        return strtoupper(date('F d, Y', $from));
    } else {
        $from_month = date('F', $from);
        $to_month = date('F', $to);
        $from_day = date('d', $from);
        $to_day = date('d', $to);
        $to_year = date('Y', $to);
        
        if ($from_month == $to_month) {
            return strtoupper($from_month . ' ' . $from_day . ' - ' . $to_day . ', ' . $to_year);
        } else {
            return strtoupper(date('F d', $from) . ' - ' . date('F d, Y', $to));
        }
    }
}

// Build the queries
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
    
    $data_array = [];
    if (!empty($combined_data)) {
        $data_array = array_values($combined_data);
        
        usort($data_array, function($a, $b) {
            $order = [
                'CUSTOMER_DAILY' => 1,
                'CUSTOMER_WEEKLY' => 2,
                'PARTNER_DAILY' => 3,
                'PARTNER_WEEKLY' => 4,
                'PARTNER_SEMI-MONTHLY' => 5,
                'PARTNER_MONTHLY' => 6,
                'BOTH_DAILY' => 7,
                'BOTH_WEEKLY' => 8,
                'BOTH_MONTHLY' => 9,
                'UNCATEGORIZED' => 10
            ];
            
            $charge_to = strtoupper(trim($a['charge_to'] ?? ''));
            $serviceCharge = strtoupper(trim($a['serviceCharge'] ?? ''));
            $key_a = $charge_to . '_' . $serviceCharge;
            
            $charge_to_b = strtoupper(trim($b['charge_to'] ?? ''));
            $serviceCharge_b = strtoupper(trim($b['serviceCharge'] ?? ''));
            $key_b = $charge_to_b . '_' . $serviceCharge_b;
            
            $order_a = $order[$key_a] ?? 11;
            $order_b = $order[$key_b] ?? 11;
            
            if ($order_a == $order_b) {
                return strcmp($a['partner_name'] ?? '', $b['partner_name'] ?? '');
            }
            return $order_a - $order_b;
        });
    }
    
    $groups = [
        'CHARGE BY CUSTOMER DAILY' => ['display_name' => 'NOTE: CHARGE BY CUSTOMER DAILY', 'rows' => [], 'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]],
        'CHARGE BY CUSTOMER WEEKLY' => ['display_name' => 'NOTE: CHARGE BY CUSTOMER WEEKLY', 'rows' => [], 'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]],
        'CHARGE BY PARTNER DAILY' => ['display_name' => 'NOTE: CHARGE BY PARTNER DAILY', 'rows' => [], 'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]],
        'CHARGE BY PARTNER WEEKLY' => ['display_name' => 'NOTE: CHARGE BY PARTNER WEEKLY', 'rows' => [], 'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]],
        'CHARGE BY PARTNER SEMI MONTHLY' => ['display_name' => 'NOTE: CHARGE BY PARTNER SEMI-MONTHLY', 'rows' => [], 'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]],
        'CHARGE BY PARTNER MONTHLY' => ['display_name' => 'NOTE: CHARGE BY PARTNER MONTHLY', 'rows' => [], 'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]],
        'CHARGE BY BOTH DAILY' => ['display_name' => 'NOTE: CHARGE BY BOTH (CUSTOMER & PARTNER) DAILY', 'rows' => [], 'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]],
        'CHARGE BY BOTH WEEKLY' => ['display_name' => 'NOTE: CHARGE BY BOTH (CUSTOMER & PARTNER) WEEKLY', 'rows' => [], 'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]],
        'CHARGE BY BOTH MONTHLY' => ['display_name' => 'NOTE: CHARGE BY BOTH (CUSTOMER & PARTNER) MONTHLY', 'rows' => [], 'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]],
        'UNCATEGORIZED' => ['display_name' => '⚠️ PARTNERS WITHOUT CHARGE TYPE (UNCATEGORIZED)', 'rows' => [], 'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]]
    ];
    
    $grand_totals = ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0];
    $all_rows = [];
    $row_index = 0;
    
    foreach ($data_array as $row) {
        $charge_to = strtoupper(trim($row['charge_to'] ?? ''));
        $serviceCharge = strtoupper(trim($row['serviceCharge'] ?? ''));
        
        $group_key = null;
        
        if (empty($charge_to)) {
            $group_key = 'UNCATEGORIZED';
        } elseif ($charge_to === 'CUSTOMER') {
            if ($serviceCharge === 'DAILY') $group_key = 'CHARGE BY CUSTOMER DAILY';
            elseif ($serviceCharge === 'WEEKLY') $group_key = 'CHARGE BY CUSTOMER WEEKLY';
            else $group_key = 'UNCATEGORIZED';
        } elseif ($charge_to === 'PARTNER') {
            if ($serviceCharge === 'DAILY') $group_key = 'CHARGE BY PARTNER DAILY';
            elseif ($serviceCharge === 'WEEKLY') $group_key = 'CHARGE BY PARTNER WEEKLY';
            elseif ($serviceCharge === 'SEMI-MONTHLY') $group_key = 'CHARGE BY PARTNER SEMI MONTHLY';
            elseif ($serviceCharge === 'MONTHLY') $group_key = 'CHARGE BY PARTNER MONTHLY';
            else $group_key = 'UNCATEGORIZED';
        } elseif ($charge_to === 'BOTH') {
            if ($serviceCharge === 'DAILY') $group_key = 'CHARGE BY BOTH DAILY';
            elseif ($serviceCharge === 'WEEKLY') $group_key = 'CHARGE BY BOTH WEEKLY';
            elseif ($serviceCharge === 'MONTHLY') $group_key = 'CHARGE BY BOTH MONTHLY';
            else $group_key = 'UNCATEGORIZED';
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
            $charge_to, $serviceCharge, $principal, $charge_to_customer, $charge_to_partner, $adjustment
        );
        
        $settled_count = (int)($row['settled_count'] ?? 0);
        $unsettled_count = (int)($row['unsettled_count'] ?? 0);
        $is_fully_settled = ($settled_count > 0 && $unsettled_count == 0);
        $is_partially_settled = ($settled_count > 0 && $unsettled_count > 0);
        
        if ($is_fully_settled) $status = 'Settled';
        elseif ($is_partially_settled) $status = 'Partial';
        else $status = 'Unsettled';
        
        $row_data = [
            'row_index' => $row_index,
            'partner_name' => $row['partner_name'] ?? $row['partner_id_kpx'],
            'account_name' => $row['partner_accName'] ?? 'N/A',
            'account_number' => $row['bank_accNumber'] ?? 'N/A',
            'txn_count' => $txn_count,
            'principal' => $principal,
            'charge_to_customer' => $charge_to_customer,
            'charge_to_partner' => $charge_to_partner,
            'adjustment' => $adjustment,
            'settlement_amount' => $settlement_amount,
            'status' => $status,
            'is_fully_settled' => $is_fully_settled,
            'is_partially_settled' => $is_partially_settled,
            'settled_count' => $settled_count,
            'unsettled_count' => $unsettled_count,
            'group_key' => $group_key,
            'charge_to' => $charge_to,
            'service_charge' => $serviceCharge
        ];
        
        $all_rows[] = $row_data;
        $row_index++;
    }
    
    $excluded_rows_set = array_flip($excluded_rows);
    $filtered_rows = array_filter($all_rows, function($row) use ($excluded_rows_set) {
        return !isset($excluded_rows_set[$row['row_index']]);
    });
    
    // Rebuild groups
    $groups = [
        'CHARGE BY CUSTOMER DAILY' => ['display_name' => 'NOTE: CHARGE BY CUSTOMER DAILY', 'rows' => [], 'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]],
        'CHARGE BY CUSTOMER WEEKLY' => ['display_name' => 'NOTE: CHARGE BY CUSTOMER WEEKLY', 'rows' => [], 'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]],
        'CHARGE BY PARTNER DAILY' => ['display_name' => 'NOTE: CHARGE BY PARTNER DAILY', 'rows' => [], 'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]],
        'CHARGE BY PARTNER WEEKLY' => ['display_name' => 'NOTE: CHARGE BY PARTNER WEEKLY', 'rows' => [], 'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]],
        'CHARGE BY PARTNER SEMI MONTHLY' => ['display_name' => 'NOTE: CHARGE BY PARTNER SEMI-MONTHLY', 'rows' => [], 'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]],
        'CHARGE BY PARTNER MONTHLY' => ['display_name' => 'NOTE: CHARGE BY PARTNER MONTHLY', 'rows' => [], 'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]],
        'CHARGE BY BOTH DAILY' => ['display_name' => 'NOTE: CHARGE BY BOTH (CUSTOMER & PARTNER) DAILY', 'rows' => [], 'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]],
        'CHARGE BY BOTH WEEKLY' => ['display_name' => 'NOTE: CHARGE BY BOTH (CUSTOMER & PARTNER) WEEKLY', 'rows' => [], 'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]],
        'CHARGE BY BOTH MONTHLY' => ['display_name' => 'NOTE: CHARGE BY BOTH (CUSTOMER & PARTNER) MONTHLY', 'rows' => [], 'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]],
        'UNCATEGORIZED' => ['display_name' => '⚠️ PARTNERS WITHOUT CHARGE TYPE (UNCATEGORIZED)', 'rows' => [], 'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]]
    ];
    
    $grand_totals = ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0];
    
    foreach ($filtered_rows as $row_data) {
        $group_key = $row_data['group_key'];
        if (!isset($groups[$group_key])) continue;
        
        $groups[$group_key]['rows'][] = $row_data;
        $groups[$group_key]['totals']['txn_count'] += $row_data['txn_count'];
        $groups[$group_key]['totals']['principal'] += $row_data['principal'];
        $groups[$group_key]['totals']['charge_to_customer'] += $row_data['charge_to_customer'];
        $groups[$group_key]['totals']['charge_to_partner'] += $row_data['charge_to_partner'];
        $groups[$group_key]['totals']['adjustment'] += $row_data['adjustment'];
        $groups[$group_key]['totals']['settlement'] += $row_data['settlement_amount'];
        
        $grand_totals['txn_count'] += $row_data['txn_count'];
        $grand_totals['principal'] += $row_data['principal'];
        $grand_totals['charge_to_customer'] += $row_data['charge_to_customer'];
        $grand_totals['charge_to_partner'] += $row_data['charge_to_partner'];
        $grand_totals['adjustment'] += $row_data['adjustment'];
        $grand_totals['settlement'] += $row_data['settlement_amount'];
    }
    
    $groups = array_filter($groups, function($group) {
        return !empty($group['rows']);
    });

    // CHECK FOR UNSETTLED TRANSACTIONS
    $has_unsettled = false;
    foreach ($groups as $group_data) {
        foreach ($group_data['rows'] as $row_data) {
            if ($row_data['status'] !== 'Settled') {
                $has_unsettled = true;
                break 2;
            }
        }
    }

    if ($has_unsettled) {
        die("Cannot export Excel: There are unsettled transactions. Please settle all transactions before exporting.");
    }
    
    // ============================================
    // CAD NUMBER GENERATION - FIXED (matches settlement-per-bank.php)
    // ============================================
    
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

    $bank_abbreviation = '';
    if (!empty($selected_bank)) {
        $bank_abbreviation = getBankAbbreviation($conn, $selected_bank);
    }
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
            }
            $abbr_stmt->close();
        }
    }

    $cad_number = '';
    $use_existing = false;
    if (!empty($existing_cad) && !empty($bank_abbreviation)) {
        if (stripos($existing_cad, $bank_abbreviation . '-') === 0) {
            $use_existing = true;
            $cad_number = $existing_cad;
        }
    } elseif (!empty($existing_cad) && empty($bank_abbreviation) && stripos($existing_cad, 'RFP-') !== 0) {
        $use_existing = true;
        $cad_number = $existing_cad;
    }

    if (!$use_existing) {
        $settlement_abbr = '';
        if (!empty($selected_settlement_type)) {
            $settlement_abbr = getSettlementAbbreviation($selected_settlement_type);
        }

        $cad_date = formatCADDate($selected_date_from, $selected_date_to);

        if (!empty($bank_abbreviation)) {
            $cad_number = $bank_abbreviation;
        } else {
            error_log("Excel Export - WARNING: No bank abbreviation found for bank='$selected_bank' partner='$selected_partner'. Using 'RFP' as fallback.");
            $cad_number = 'RFP';
        }

        if (!empty($settlement_abbr)) {
            $cad_number .= '-' . $settlement_abbr;
        }

        $cad_number .= '-' . $cad_date;
    }

    error_log("Excel Export - Final CAD Number: " . $cad_number);
    
    $date_range_display = formatDateRange($selected_date_from, $selected_date_to);
    $current_date = strtoupper(date('F d, Y'));

} catch (Exception $e) {
    error_log("Error in export_bank_settlement: " . $e->getMessage());
    die("Error generating export: " . $e->getMessage());
}

// Create Excel file using PhpSpreadsheet
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Color;

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

$sheet->getColumnDimension('A')->setWidth(35);
$sheet->getColumnDimension('B')->setWidth(25);
$sheet->getColumnDimension('C')->setWidth(20);
$sheet->getColumnDimension('D')->setWidth(22);

$sheet->getStyle('C:C')->getNumberFormat()->setFormatCode('@');

// Row 1: REQUEST FOR PAYMENT FORM
$sheet->mergeCells('A1:D1');
$sheet->setCellValue('A1', 'REQUEST FOR PAYMENT FORM');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
$sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Row 2
$sheet->setCellValue('A2', 'M. LHUILLIER PHILIPPINES, INC.');
$sheet->getStyle('A2')->getFont()->setBold(true)->setSize(12);
$sheet->setCellValue('D2', 'DATE: ' . $current_date);
$sheet->getStyle('D2')->getFont()->setBold(true)->setSize(12);
$sheet->getStyle('D2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

// Row 3
$sheet->setCellValue('A3', 'BILLS PAYMENT SETTLEMENT');
$sheet->getStyle('A3')->getFont()->setBold(true)->setSize(12);
$sheet->setCellValue('D3', 'CAD NO.: ' . $cad_number);
$sheet->getStyle('D3')->getFont()->setBold(true)->setSize(12);
$sheet->getStyle('D3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

// Row 4
$sheet->setCellValue('D4', 'RFP NO.: ' . $selected_rfp_no);
$sheet->getStyle('D4')->getFont()->setBold(true)->setSize(12);
$sheet->getStyle('D4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

// Row 6-8
$sheet->setCellValue('A6', 'BANK NAME: ' . ($selected_bank ?: ''));
$sheet->getStyle('A6')->getFont()->setBold(true)->setSize(14);
$sheet->setCellValue('A7', 'DATE OF TRANSACTION: ' . $date_range_display);
$sheet->getStyle('A7')->getFont()->setBold(true)->setSize(12);
$sheet->setCellValue('A8', 'MODE OF PAYMENT: ');
$sheet->getStyle('A8')->getFont()->setBold(true)->setSize(12);

// Headers
$headers = ['LIST OF BILLS PAYMENT PARTNER', 'ACCOUNT NAME', 'ACCOUNT NUMBER', 'AMOUNT FOR SETTLEMENT'];
$col = 'A';
foreach ($headers as $header) {
    $sheet->setCellValue($col . '10', $header);
    $sheet->getStyle($col . '10')->getFont()->setBold(true);
    $sheet->getStyle($col . '10')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle($col . '10')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $col++;
}

$row = 11;

foreach ($groups as $group_key => $group_data) {
    if (empty($group_data['rows'])) continue;
    
    $is_uncategorized = ($group_key === 'UNCATEGORIZED');
    $is_both = (strpos($group_key, 'BOTH') !== false);
    
    $sheet->mergeCells('A' . $row . ':D' . $row);
    $sheet->setCellValue('A' . $row, $group_data['display_name']);
    $sheet->getStyle('A' . $row)->getFont()->setBold(true);
    
    if ($is_uncategorized) {
        $sheet->getStyle('A' . $row . ':D' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF3CD');
        $sheet->getStyle('A' . $row)->getFont()->getColor()->setARGB(Color::COLOR_DARKYELLOW);
    } elseif ($is_both) {
        $sheet->getStyle('A' . $row . ':D' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFF');
        $sheet->getStyle('A' . $row)->getFont()->getColor()->setARGB(Color::COLOR_DARKBLUE);
    }
    
    $sheet->getStyle('A' . $row . ':D' . $row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $row++;
    
    foreach ($group_data['rows'] as $row_data) {
        $sheet->setCellValue('A' . $row, $row_data['partner_name']);
        $sheet->setCellValue('B' . $row, $row_data['account_name']);
        $sheet->setCellValueExplicit('C' . $row, $row_data['account_number'], DataType::TYPE_STRING);
        $sheet->setCellValue('D' . $row, $row_data['settlement_amount']);
        $sheet->getStyle('D' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('A' . $row . ':D' . $row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $row++;
    }
    
    // Group subtotal
    $sheet->mergeCells('A' . $row . ':C' . $row);
    $sheet->setCellValue('A' . $row, 'Subtotal - ' . $group_data['display_name']);
    $sheet->getStyle('A' . $row . ':D' . $row)->getFont()->setBold(true);
    $sheet->setCellValue('D' . $row, $group_data['totals']['settlement']);
    $sheet->getStyle('D' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
    
    if ($is_uncategorized) {
        $sheet->getStyle('A' . $row . ':D' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF3CD');
    } elseif ($is_both) {
        $sheet->getStyle('A' . $row . ':D' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFF');
    } else {
        $sheet->getStyle('A' . $row . ':D' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFF');
    }
    
    $sheet->getStyle('A' . $row . ':D' . $row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $row++;
    $row++; // blank row between groups
}

// Grand Total
$sheet->mergeCells('A' . $row . ':C' . $row);
$sheet->setCellValue('A' . $row, 'GRAND TOTAL');
$sheet->getStyle('A' . $row . ':D' . $row)->getFont()->setBold(true);
$sheet->setCellValue('D' . $row, $grand_totals['settlement']);
$sheet->getStyle('D' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
$sheet->getStyle('A' . $row . ':D' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('F8F9FA');
$sheet->getStyle('A' . $row . ':D' . $row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

$row += 2;

// Signature Section
$sheet->setCellValue('A' . $row, 'Prepared by :');
$sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(10);
$sheet->setCellValue('C' . $row, 'Checked by :');
$sheet->getStyle('C' . $row)->getFont()->setBold(true)->setSize(10);
$row++;

$sheet->setCellValue('A' . $row, $display_name);
$sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(10);
$sheet->setCellValue('C' . $row, '{Accounting Staff}');
$sheet->getStyle('C' . $row)->getFont()->setBold(true)->setSize(10);
$row++;

$sheet->setCellValue('A' . $row, 'Accounting Staff');
$sheet->getStyle('A' . $row)->getFont()->setSize(9);
$sheet->setCellValue('C' . $row, 'Accounting Staff');
$sheet->getStyle('C' . $row)->getFont()->setSize(9);
$row += 2;

$sheet->setCellValue('A' . $row, 'Reviewed by :');
$sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(10);
$sheet->setCellValue('C' . $row, 'Noted by :');
$sheet->getStyle('C' . $row)->getFont()->setBold(true)->setSize(10);
$row++;

$sheet->setCellValue('A' . $row, 'ELVIE CILLO');
$sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(10);
$sheet->setCellValue('C' . $row, 'LUELLA PERALTA');
$sheet->getStyle('C' . $row)->getFont()->setBold(true)->setSize(10);
$row++;

$sheet->setCellValue('A' . $row, 'Department Manager');
$sheet->getStyle('A' . $row)->getFont()->setSize(9);
$sheet->setCellValue('C' . $row, 'Division Manager');
$sheet->getStyle('C' . $row)->getFont()->setSize(9);

$start_row = $row - 5;
$sheet->mergeCells('A' . $start_row . ':B' . $start_row);
$sheet->mergeCells('C' . $start_row . ':D' . $start_row);
$sheet->mergeCells('A' . ($start_row+1) . ':B' . ($start_row+1));
$sheet->mergeCells('C' . ($start_row+1) . ':D' . ($start_row+1));
$sheet->mergeCells('A' . ($start_row+2) . ':B' . ($start_row+2));
$sheet->mergeCells('C' . ($start_row+2) . ':D' . ($start_row+2));
$sheet->mergeCells('A' . ($start_row+4) . ':B' . ($start_row+4));
$sheet->mergeCells('C' . ($start_row+4) . ':D' . ($start_row+4));
$sheet->mergeCells('A' . ($start_row+5) . ':B' . ($start_row+5));
$sheet->mergeCells('C' . ($start_row+5) . ':D' . ($start_row+5));
$sheet->mergeCells('A' . ($start_row+6) . ':B' . ($start_row+6));
$sheet->mergeCells('C' . ($start_row+6) . ':D' . ($start_row+6));

$sheet->getStyle('A' . $start_row . ':D' . ($start_row+6))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_NONE);

for ($i = $start_row; $i <= $start_row+6; $i++) {
    $sheet->getRowDimension($i)->setRowHeight(22);
}

foreach (range('A', 'D') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

$filename = $cad_number . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
?>
