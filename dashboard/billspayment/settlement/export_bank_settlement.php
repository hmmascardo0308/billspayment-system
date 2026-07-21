<?php
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

// Get filter values from GET parameters
$selected_partner = isset($_GET['partner']) ? trim($_GET['partner']) : '';
$selected_bank = isset($_GET['bank']) ? trim($_GET['bank']) : '';
$selected_settlement_type = isset($_GET['settlement_type']) ? trim($_GET['settlement_type']) : '';
$selected_date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$selected_date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';

// Get excluded rows from GET parameters (comma-separated list of row indices)
$excluded_rows = isset($_GET['excluded_rows']) ? explode(',', trim($_GET['excluded_rows'])) : [];
$excluded_rows = array_filter($excluded_rows, 'is_numeric'); // Sanitize

/**
 * Get bank abbreviation from database
 * 
 * @param mysqli $conn Database connection
 * @param string $bank_name Bank name to look up
 * @return string Bank abbreviation or empty string if not found
 */
function getBankAbbreviation(mysqli $conn, string $bank_name): string {
    if (empty($bank_name)) return '';
    
    $query = "SELECT bank_abbreviation FROM mldb.bank_table WHERE bank_name = ?";
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param("s", $bank_name);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            return $row['bank_abbreviation'];
        }
    }
    return '';
}

/**
 * Get settlement type abbreviation
 * 
 * @param string $settlement_type Settlement type (CHECK or ONLINE)
 * @return string Abbreviation (CHK or ONL) or empty string
 */
function getSettlementAbbreviation(string $settlement_type): string {
    if (empty($settlement_type)) return '';
    return strtoupper($settlement_type) === 'CHECK' ? 'CHK' : 'ONL';
}

/**
 * Format date for CAD number
 * 
 * @param string|null $date_from Start date
 * @param string|null $date_to End date
 * @return string Formatted date string (YYYY-MM-DD or YYYY-MM-DDDD)
 */
function formatCADDate(?string $date_from, ?string $date_to): string {
    if (empty($date_from) && empty($date_to)) {
        return date('Y-m-d');
    }
    
    // Use the last day of the period for the CAD number
    $date = !empty($date_to) ? $date_to : $date_from;
    $timestamp = strtotime($date);
    return date('Y-m', $timestamp) . '-' . sprintf('%05d', date('d', $timestamp));
}

/**
 * Format date range for display
 * 
 * @param string|null $date_from Start date
 * @param string|null $date_to End date
 * @return string Formatted date range
 */
function formatDateRange(?string $date_from, ?string $date_to): string {
    if (empty($date_from) && empty($date_to)) {
        return strtoupper(date('F d, Y'));
    }
    
    $from = strtotime($date_from);
    $to = !empty($date_to) ? strtotime($date_to) : $from;
    
    if ($from == $to) {
        // Single date: June 12, 2026
        return strtoupper(date('F d, Y', $from));
    } else {
        // Date range: June 01 - 10, 2026
        $from_month = date('F', $from);
        $to_month = date('F', $to);
        $from_day = date('d', $from);
        $to_day = date('d', $to);
        $to_year = date('Y', $to);
        
        // Check if months are the same
        if ($from_month == $to_month) {
            // Same month: June 01 - 10, 2026
            return strtoupper($from_month . ' ' . $from_day . ' - ' . $to_day . ', ' . $to_year);
        } else {
            // Different months: June 01 - July 10, 2026
            return strtoupper(date('F d', $from) . ' - ' . date('F d, Y', $to));
        }
    }
}

/**
 * Get bank details from database
 * 
 * @param mysqli $conn Database connection
 * @param string $bank_name Bank name to look up
 * @return array|null Bank details or null if not found
 */
function getBankDetails(mysqli $conn, string $bank_name): ?array {
    if (empty($bank_name)) return null;
    
    $query = "SELECT bank_abbreviation FROM mldb.bank_table WHERE bank_name = ?";
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param("s", $bank_name);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            return $row;
        }
    }
    return null;
}

// Build the queries - ADAPTED from settlement-per-bank.php logic
try {
    // ============================================
    // BUILD SEPARATE WHERE CONDITIONS FOR REGULAR AND ADJUSTMENT
    // ============================================
    $where_conditions_regular = [];
    $where_conditions_adjustment = [];
    $params_regular = [];
    $params_adjustment = [];
    $types_regular = "";
    $types_adjustment = "";
    
    // Partner filter - applies to both
    if (!empty($selected_partner)) {
        $where_conditions_regular[] = "bt.partner_id_kpx = ?";
        $params_regular[] = $selected_partner;
        $types_regular .= "s";
        
        $where_conditions_adjustment[] = "bt.partner_id_kpx = ?";
        $params_adjustment[] = $selected_partner;
        $types_adjustment .= "s";
    }
    
    // Bank filter - applies to both
    if (!empty($selected_bank)) {
        $where_conditions_regular[] = "pm.bank = ?";
        $params_regular[] = $selected_bank;
        $types_regular .= "s";
        
        $where_conditions_adjustment[] = "pm.bank = ?";
        $params_adjustment[] = $selected_bank;
        $types_adjustment .= "s";
    }
    
    // Settlement type filter - applies to both
    if (!empty($selected_settlement_type)) {
        $where_conditions_regular[] = "pm.settled_online_check = ?";
        $params_regular[] = $selected_settlement_type;
        $types_regular .= "s";
        
        $where_conditions_adjustment[] = "pm.settled_online_check = ?";
        $params_adjustment[] = $selected_settlement_type;
        $types_adjustment .= "s";
    }
    
    // ============================================
    // REGULAR TRANSACTIONS: Based on datetime, NOT cancelled
    // ============================================
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
    
    // Regular transactions: EXCLUDE cancelled/voided
    $where_conditions_regular[] = "(bt.status IS NULL OR bt.status = '')";
    
    // ============================================
    // ADJUSTMENTS: Based on cancellation_date, ONLY cancelled
    // ============================================
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
    
    // Adjustments: ONLY cancelled/voided
    $where_conditions_adjustment[] = "(bt.status IS NOT NULL AND bt.status != '')";
    
    // ============================================
    // QUERY 1: Regular transactions (not cancelled)
    // ============================================
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
                    (SUM(bt.charge_to_customer) + SUM(bt.charge_to_partner)) as total_charge,
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
    
    // ============================================
    // QUERY 2: Adjustments (cancelled transactions)
    // ============================================
    $adjustment_sql = "SELECT 
                            bt.partner_id_kpx,
                            SUM(CASE WHEN bt.amount_paid < 0 THEN bt.amount_paid ELSE 0 END) as total_adjustment
                        FROM mldb.billspayment_transaction bt
                        LEFT JOIN masterdata.partner_masterfile pm ON bt.partner_id_kpx = pm.partner_id_kpx
                        WHERE " . implode(" AND ", $where_conditions_adjustment) . "
                        GROUP BY bt.partner_id_kpx";
    
    // ============================================
    // EXECUTE QUERIES
    // ============================================
    
    // Execute regular query
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
    
    // Execute adjustment query
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
    
    // ============================================
    // COMBINE RESULTS IN PHP
    // ============================================
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
                'total_charge' => (float)($row['total_charge'] ?? 0),
                'total_adjustment' => 0, // Will be updated from adjustment query
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
                // Partner has only adjustments, no regular transactions
                // Fetch partner details separately
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
                            'total_charge' => 0,
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
    
    // ============================================
    // PROCESS COMBINED DATA
    // ============================================
    $data_array = [];
    if (!empty($combined_data)) {
        $data_array = array_values($combined_data);
        
        // Sort by charge_to and serviceCharge
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
    
    // Define groups - same as settlement-per-bank.php
    $groups = [
        'CHARGE BY CUSTOMER DAILY' => [
            'display_name' => 'NOTE: CHARGE BY CUSTOMER DAILY',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge' => 0, 'adjustment' => 0, 'settlement' => 0]
        ],
        'CHARGE BY CUSTOMER WEEKLY' => [
            'display_name' => 'NOTE: CHARGE BY CUSTOMER WEEKLY',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge' => 0, 'adjustment' => 0, 'settlement' => 0]
        ],
        'CHARGE BY PARTNER DAILY' => [
            'display_name' => 'NOTE: CHARGE BY PARTNER DAILY',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge' => 0, 'adjustment' => 0, 'settlement' => 0]
        ],
        'CHARGE BY PARTNER WEEKLY' => [
            'display_name' => 'NOTE: CHARGE BY PARTNER WEEKLY',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge' => 0, 'adjustment' => 0, 'settlement' => 0]
        ],
        'CHARGE BY PARTNER SEMI MONTHLY' => [
            'display_name' => 'NOTE: CHARGE BY PARTNER SEMI-MONTHLY',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge' => 0, 'adjustment' => 0, 'settlement' => 0]
        ],
        'CHARGE BY PARTNER MONTHLY' => [
            'display_name' => 'NOTE: CHARGE BY PARTNER MONTHLY',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge' => 0, 'adjustment' => 0, 'settlement' => 0]
        ],
        'CHARGE BY BOTH DAILY' => [
            'display_name' => 'NOTE: CHARGE BY BOTH (CUSTOMER & PARTNER) DAILY',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge' => 0, 'adjustment' => 0, 'settlement' => 0]
        ],
        'CHARGE BY BOTH WEEKLY' => [
            'display_name' => 'NOTE: CHARGE BY BOTH (CUSTOMER & PARTNER) WEEKLY',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge' => 0, 'adjustment' => 0, 'settlement' => 0]
        ],
        'CHARGE BY BOTH MONTHLY' => [
            'display_name' => 'NOTE: CHARGE BY BOTH (CUSTOMER & PARTNER) MONTHLY',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge' => 0, 'adjustment' => 0, 'settlement' => 0]
        ],
        'UNCATEGORIZED' => [
            'display_name' => '⚠️ PARTNERS WITHOUT CHARGE TYPE (UNCATEGORIZED)',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge' => 0, 'adjustment' => 0, 'settlement' => 0]
        ]
    ];
    
    $grand_totals = ['txn_count' => 0, 'principal' => 0, 'charge' => 0, 'adjustment' => 0, 'settlement' => 0];
    
    // Store all rows with their indices for filtering
    $all_rows = [];
    $row_index = 0;
    
    foreach ($data_array as $row) {
        $charge_to = strtoupper(trim($row['charge_to'] ?? ''));
        $serviceCharge = strtoupper(trim($row['serviceCharge'] ?? ''));
        
        // Determine which group this belongs to
        $group_key = null;
        
        if (empty($charge_to)) {
            $group_key = 'UNCATEGORIZED';
        } elseif ($charge_to === 'CUSTOMER') {
            if ($serviceCharge === 'DAILY') {
                $group_key = 'CHARGE BY CUSTOMER DAILY';
            } elseif ($serviceCharge === 'WEEKLY') {
                $group_key = 'CHARGE BY CUSTOMER WEEKLY';
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
        $charge = (float)($row['total_charge'] ?? 0);
        $adjustment = (float)($row['total_adjustment'] ?? 0);
        $settlement_amount = $principal + $charge + $adjustment;
        $settled_count = (int)($row['settled_count'] ?? 0);
        $unsettled_count = (int)($row['unsettled_count'] ?? 0);
        $is_fully_settled = ($settled_count > 0 && $unsettled_count == 0);
        $is_partially_settled = ($settled_count > 0 && $unsettled_count > 0);
        
        // Determine status text
        if ($is_fully_settled) {
            $status = 'Settled';
        } elseif ($is_partially_settled) {
            $status = 'Partial';
        } else {
            $status = 'Unsettled';
        }
        
        $row_data = [
            'row_index' => $row_index,
            'partner_name' => $row['partner_name'] ?? $row['partner_id_kpx'],
            'account_name' => $row['partner_accName'] ?? 'N/A',
            'account_number' => $row['bank_accNumber'] ?? 'N/A',
            'txn_count' => $txn_count,
            'principal' => $principal,
            'charge' => $charge,
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
    
    // Filter out excluded rows based on row_index
    $excluded_rows_set = array_flip($excluded_rows);
    $filtered_rows = array_filter($all_rows, function($row) use ($excluded_rows_set) {
        return !isset($excluded_rows_set[$row['row_index']]);
    });
    
    // Rebuild groups with filtered rows
    $groups = [
        'CHARGE BY CUSTOMER DAILY' => [
            'display_name' => 'NOTE: CHARGE BY CUSTOMER DAILY',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge' => 0, 'adjustment' => 0, 'settlement' => 0]
        ],
        'CHARGE BY CUSTOMER WEEKLY' => [
            'display_name' => 'NOTE: CHARGE BY CUSTOMER WEEKLY',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge' => 0, 'adjustment' => 0, 'settlement' => 0]
        ],
        'CHARGE BY PARTNER DAILY' => [
            'display_name' => 'NOTE: CHARGE BY PARTNER DAILY',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge' => 0, 'adjustment' => 0, 'settlement' => 0]
        ],
        'CHARGE BY PARTNER WEEKLY' => [
            'display_name' => 'NOTE: CHARGE BY PARTNER WEEKLY',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge' => 0, 'adjustment' => 0, 'settlement' => 0]
        ],
        'CHARGE BY PARTNER SEMI MONTHLY' => [
            'display_name' => 'NOTE: CHARGE BY PARTNER SEMI-MONTHLY',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge' => 0, 'adjustment' => 0, 'settlement' => 0]
        ],
        'CHARGE BY PARTNER MONTHLY' => [
            'display_name' => 'NOTE: CHARGE BY PARTNER MONTHLY',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge' => 0, 'adjustment' => 0, 'settlement' => 0]
        ],
        'CHARGE BY BOTH DAILY' => [
            'display_name' => 'NOTE: CHARGE BY BOTH (CUSTOMER & PARTNER) DAILY',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge' => 0, 'adjustment' => 0, 'settlement' => 0]
        ],
        'CHARGE BY BOTH WEEKLY' => [
            'display_name' => 'NOTE: CHARGE BY BOTH (CUSTOMER & PARTNER) WEEKLY',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge' => 0, 'adjustment' => 0, 'settlement' => 0]
        ],
        'CHARGE BY BOTH MONTHLY' => [
            'display_name' => 'NOTE: CHARGE BY BOTH (CUSTOMER & PARTNER) MONTHLY',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge' => 0, 'adjustment' => 0, 'settlement' => 0]
        ],
        'UNCATEGORIZED' => [
            'display_name' => '⚠️ PARTNERS WITHOUT CHARGE TYPE (UNCATEGORIZED)',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge' => 0, 'adjustment' => 0, 'settlement' => 0]
        ]
    ];
    
    // Populate groups and calculate totals from filtered rows
    $grand_totals = ['txn_count' => 0, 'principal' => 0, 'charge' => 0, 'adjustment' => 0, 'settlement' => 0];
    
    foreach ($filtered_rows as $row_data) {
        $group_key = $row_data['group_key'];
        
        if (!isset($groups[$group_key])) {
            continue;
        }
        
        // Add to group
        $groups[$group_key]['rows'][] = $row_data;
        
        // Update group totals
        $groups[$group_key]['totals']['txn_count'] += $row_data['txn_count'];
        $groups[$group_key]['totals']['principal'] += $row_data['principal'];
        $groups[$group_key]['totals']['charge'] += $row_data['charge'];
        $groups[$group_key]['totals']['adjustment'] += $row_data['adjustment'];
        $groups[$group_key]['totals']['settlement'] += $row_data['settlement_amount'];
        
        // Update grand totals
        $grand_totals['txn_count'] += $row_data['txn_count'];
        $grand_totals['principal'] += $row_data['principal'];
        $grand_totals['charge'] += $row_data['charge'];
        $grand_totals['adjustment'] += $row_data['adjustment'];
        $grand_totals['settlement'] += $row_data['settlement_amount'];
    }
    
    // Remove empty groups
    $groups = array_filter($groups, function($group) {
        return !empty($group['rows']);
    });
    
    // Get bank abbreviation and settlement type
    $bank_abbreviation = '';
    $settlement_abbr = '';
    
    if (!empty($selected_bank)) {
        $bank_details = getBankDetails($conn, $selected_bank);
        if ($bank_details) {
            $bank_abbreviation = $bank_details['bank_abbreviation'];
        }
    }
    
    if (!empty($selected_settlement_type)) {
        $settlement_abbr = getSettlementAbbreviation($selected_settlement_type);
    } else {
        // If no settlement type selected, check if all selected banks have the same settlement type
        $settlement_abbr = 'CHK'; // Default
    }
    
    // Generate CAD number
    $cad_date = formatCADDate($selected_date_from, $selected_date_to);
    $cad_number = $bank_abbreviation . '-' . $settlement_abbr . '-' . $cad_date;
    
    // Format date range for display
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

// Set column widths
$sheet->getColumnDimension('A')->setWidth(35);
$sheet->getColumnDimension('B')->setWidth(25);
$sheet->getColumnDimension('C')->setWidth(20);
$sheet->getColumnDimension('D')->setWidth(12);
$sheet->getColumnDimension('E')->setWidth(18);
$sheet->getColumnDimension('F')->setWidth(15);
$sheet->getColumnDimension('G')->setWidth(20);
$sheet->getColumnDimension('H')->setWidth(22);

// HIDE COLUMNS D, E, F, G - can be unhidden by user
$sheet->getColumnDimension('D')->setVisible(false);
$sheet->getColumnDimension('E')->setVisible(false);
$sheet->getColumnDimension('F')->setVisible(false);
$sheet->getColumnDimension('G')->setVisible(false);

// Set column C (Account Number) as text to prevent scientific notation
$sheet->getStyle('C:C')->getNumberFormat()->setFormatCode('@');

// Row 1: REQUEST FOR PAYMENT FORM (merged A-H)
$sheet->mergeCells('A1:H1');
$sheet->setCellValue('A1', 'REQUEST FOR PAYMENT FORM');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
$sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Row 2: M. LHUILLIER PHILIPPINES, INC. (A2) and DATE (H2)
$sheet->setCellValue('A2', 'M. LHUILLIER PHILIPPINES, INC.');
$sheet->getStyle('A2')->getFont()->setBold(true)->setSize(12);

$sheet->setCellValue('H2', 'DATE: ' . $current_date);
$sheet->getStyle('H2')->getFont()->setBold(true)->setSize(12);
$sheet->getStyle('H2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

// Row 3: BILLS PAYMENT SETTLEMENT (A3) and CAD NO. (H3)
$sheet->setCellValue('A3', 'BILLS PAYMENT SETTLEMENT');
$sheet->getStyle('A3')->getFont()->setBold(true)->setSize(12);

$sheet->setCellValue('H3', 'CAD NO.: ' . $cad_number);
$sheet->getStyle('H3')->getFont()->setBold(true)->setSize(12);
$sheet->getStyle('H3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

// Row 4: RFP NO. (H4)
$sheet->setCellValue('H4', 'RFP NO.: ');
$sheet->getStyle('H4')->getFont()->setBold(true)->setSize(12);
$sheet->getStyle('H4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

// Row 6: BANK NAME (A6)
$sheet->setCellValue('A6', 'BANK NAME: ' . ($selected_bank ?: ''));
$sheet->getStyle('A6')->getFont()->setBold(true)->setSize(14);

// Row 7: DATE OF TRANSACTION (A7)
$sheet->setCellValue('A7', 'DATE OF TRANSACTION: ' . $date_range_display);
$sheet->getStyle('A7')->getFont()->setBold(true)->setSize(12);

// Row 8: MODE OF PAYMENT (A8)
$sheet->setCellValue('A8', 'MODE OF PAYMENT: ');
$sheet->getStyle('A8')->getFont()->setBold(true)->setSize(12);

// Row 10: Headers - Only visible columns A, B, C, H (D, E, F, G are hidden)
$headers = ['LIST OF BILLS PAYMENT PARTNER', 'ACCOUNT NAME', 'ACCOUNT NUMBER', 'VOLUME COUNT', 'PRINCIPAL', 'CHARGE', 'ADJUSTMENT (add/less)', 'AMOUNT FOR SETTLEMENT'];
$col = 'A';
foreach ($headers as $header) {
    $sheet->setCellValue($col . '10', $header);
    $sheet->getStyle($col . '10')->getFont()->setBold(true);
    $sheet->getStyle($col . '10')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle($col . '10')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $col++;
}

// Row 11+: Data rows
$row = 11;

// Only output groups that have rows
foreach ($groups as $group_key => $group_data) {
    // Skip empty groups
    if (empty($group_data['rows'])) {
        continue;
    }
    
    // Check if this is the UNCATEGORIZED group
    $is_uncategorized = ($group_key === 'UNCATEGORIZED');
    $is_both = (strpos($group_key, 'BOTH') !== false);
    
    // Group header with styling
    $sheet->mergeCells('A' . $row . ':H' . $row);
    $sheet->setCellValue('A' . $row, $group_data['display_name']);
    $sheet->getStyle('A' . $row)->getFont()->setBold(true);
    
    // Apply group-specific styling
    if ($is_uncategorized) {
        $sheet->getStyle('A' . $row . ':H' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF3CD');
        $sheet->getStyle('A' . $row)->getFont()->getColor()->setARGB(Color::COLOR_DARKYELLOW);
    } elseif ($is_both) {
        $sheet->getStyle('A' . $row . ':H' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('D1ECF1');
        $sheet->getStyle('A' . $row)->getFont()->getColor()->setARGB(Color::COLOR_DARKBLUE);
    }
    
    $sheet->getStyle('A' . $row . ':H' . $row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $row++;
    
    // Data rows
    foreach ($group_data['rows'] as $row_data) {
        $sheet->setCellValue('A' . $row, $row_data['partner_name']);
        $sheet->setCellValue('B' . $row, $row_data['account_name']);
        
        // Set account number as text to prevent scientific notation
        $sheet->setCellValueExplicit('C' . $row, $row_data['account_number'], DataType::TYPE_STRING);
        
        $sheet->setCellValue('D' . $row, $row_data['txn_count']);
        $sheet->setCellValue('E' . $row, $row_data['principal']);
        $sheet->setCellValue('F' . $row, $row_data['charge']);
        $sheet->setCellValue('G' . $row, $row_data['adjustment']);
        $sheet->setCellValue('H' . $row, $row_data['settlement_amount']);
        
        // Apply number formatting
        $sheet->getStyle('E' . $row . ':H' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('D' . $row)->getNumberFormat()->setFormatCode('#,##0');
        
        $sheet->getStyle('A' . $row . ':H' . $row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $row++;
    }
    
    // Group subtotal row with styling
    $sheet->mergeCells('A' . $row . ':C' . $row);
    $sheet->setCellValue('A' . $row, 'Subtotal - ' . $group_data['display_name']);
    $sheet->getStyle('A' . $row . ':H' . $row)->getFont()->setBold(true);
    $sheet->setCellValue('D' . $row, $group_data['totals']['txn_count']);
    $sheet->setCellValue('E' . $row, $group_data['totals']['principal']);
    $sheet->setCellValue('F' . $row, $group_data['totals']['charge']);
    $sheet->setCellValue('G' . $row, $group_data['totals']['adjustment']);
    $sheet->setCellValue('H' . $row, $group_data['totals']['settlement']);
    
    $sheet->getStyle('E' . $row . ':H' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle('D' . $row)->getNumberFormat()->setFormatCode('#,##0');
    
    // Apply subtotal styling
    if ($is_uncategorized) {
        $sheet->getStyle('A' . $row . ':H' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF3CD');
    } elseif ($is_both) {
        $sheet->getStyle('A' . $row . ':H' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('D1ECF1');
    } else {
        $sheet->getStyle('A' . $row . ':H' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('E8F4FD');
    }
    
    $sheet->getStyle('A' . $row . ':H' . $row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $row++;
    
    // Add a blank row between groups (except after the last group)
    $row++;
}

// Grand Total
$sheet->mergeCells('A' . $row . ':C' . $row);
$sheet->setCellValue('A' . $row, 'GRAND TOTAL');
$sheet->getStyle('A' . $row . ':H' . $row)->getFont()->setBold(true);
$sheet->setCellValue('D' . $row, $grand_totals['txn_count']);
$sheet->setCellValue('E' . $row, $grand_totals['principal']);
$sheet->setCellValue('F' . $row, $grand_totals['charge']);
$sheet->setCellValue('G' . $row, $grand_totals['adjustment']);
$sheet->setCellValue('H' . $row, $grand_totals['settlement']);

$sheet->getStyle('E' . $row . ':H' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
$sheet->getStyle('D' . $row)->getNumberFormat()->setFormatCode('#,##0');
$sheet->getStyle('A' . $row . ':H' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('F8F9FA');
$sheet->getStyle('A' . $row . ':H' . $row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

// Auto-size columns for better display
foreach (range('A', 'H') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// Set the filename to the CAD number
$filename = $cad_number . '.xlsx';

// Set headers for download
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Create and output the file
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
?>