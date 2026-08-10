<?php
// export_bank_settlement_pdf.php
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

use Dompdf\Dompdf;
use Dompdf\Options;

// Function to calculate settlement amount based on charge type (same as main file)
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
    
    // For BOTH charge types: Use the original calculation (Principal + both charges + adjustment)
    if ($charge_to_upper === 'BOTH') {
        return $principal + $charge_to_customer + $charge_to_partner + $adjustment;
    }
    
    // Default fallback: Original calculation
    return $principal + $charge_to_customer + $charge_to_partner + $adjustment;
}

// Get filter values from GET parameters
$selected_partner = isset($_GET['partner']) ? trim($_GET['partner']) : '';
$selected_bank = isset($_GET['bank']) ? trim($_GET['bank']) : '';
$selected_settlement_type = isset($_GET['settlement_type']) ? trim($_GET['settlement_type']) : '';
$selected_date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$selected_date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';

// Get excluded rows from GET parameters (comma-separated list of row indices)
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
 * Get bank abbreviation from database
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
 */
function getSettlementAbbreviation(string $settlement_type): string {
    if (empty($settlement_type)) return '';
    return strtoupper(trim($settlement_type)) === 'CHECK' ? 'CHK' : 'ONL';
}

/**
 * Format date for CAD number
 */
function formatCADDate(?string $date_from, ?string $date_to): string {
    if (empty($date_from) && empty($date_to)) {
        return date('Y-m-d');
    }
    
    $date = !empty($date_to) ? $date_to : $date_from;
    $timestamp = strtotime($date);
    return date('Y-m', $timestamp) . '-' . sprintf('%05d', date('d', $timestamp));
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

/**
 * Get bank details from database
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
    
    // ============================================
    // PROCESS COMBINED DATA
    // ============================================
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
    
    // Define groups
    $groups = [
        'CHARGE BY CUSTOMER DAILY' => [
            'display_name' => 'NOTE: CHARGE BY CUSTOMER DAILY',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]
        ],
        'CHARGE BY CUSTOMER WEEKLY' => [
            'display_name' => 'NOTE: CHARGE BY CUSTOMER WEEKLY',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]
        ],
        'CHARGE BY PARTNER DAILY' => [
            'display_name' => 'NOTE: CHARGE BY PARTNER DAILY',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]
        ],
        'CHARGE BY PARTNER WEEKLY' => [
            'display_name' => 'NOTE: CHARGE BY PARTNER WEEKLY',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]
        ],
        'CHARGE BY PARTNER SEMI MONTHLY' => [
            'display_name' => 'NOTE: CHARGE BY PARTNER SEMI-MONTHLY',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]
        ],
        'CHARGE BY PARTNER MONTHLY' => [
            'display_name' => 'NOTE: CHARGE BY PARTNER MONTHLY',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]
        ],
        'CHARGE BY BOTH DAILY' => [
            'display_name' => 'NOTE: CHARGE BY BOTH (CUSTOMER & PARTNER) DAILY',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]
        ],
        'CHARGE BY BOTH WEEKLY' => [
            'display_name' => 'NOTE: CHARGE BY BOTH (CUSTOMER & PARTNER) WEEKLY',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]
        ],
        'CHARGE BY BOTH MONTHLY' => [
            'display_name' => 'NOTE: CHARGE BY BOTH (CUSTOMER & PARTNER) MONTHLY',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]
        ],
        'UNCATEGORIZED' => [
            'display_name' => '⚠️ PARTNERS WITHOUT CHARGE TYPE (UNCATEGORIZED)',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]
        ]
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
        $charge_to_customer = (float)($row['charge_to_customer'] ?? 0);
        $charge_to_partner = (float)($row['charge_to_partner'] ?? 0);
        $adjustment = (float)($row['total_adjustment'] ?? 0);
        
        // Calculate settlement amount based on charge type
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
    
    // Filter out excluded rows
    $excluded_rows_set = array_flip($excluded_rows);
    $filtered_rows = array_filter($all_rows, function($row) use ($excluded_rows_set) {
        return !isset($excluded_rows_set[$row['row_index']]);
    });
    
    // Rebuild groups with filtered rows
    $groups = [
        'CHARGE BY CUSTOMER DAILY' => [
            'display_name' => 'NOTE: CHARGE BY CUSTOMER DAILY',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]
        ],
        'CHARGE BY CUSTOMER WEEKLY' => [
            'display_name' => 'NOTE: CHARGE BY CUSTOMER WEEKLY',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]
        ],
        'CHARGE BY PARTNER DAILY' => [
            'display_name' => 'NOTE: CHARGE BY PARTNER DAILY',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]
        ],
        'CHARGE BY PARTNER WEEKLY' => [
            'display_name' => 'NOTE: CHARGE BY PARTNER WEEKLY',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]
        ],
        'CHARGE BY PARTNER SEMI MONTHLY' => [
            'display_name' => 'NOTE: CHARGE BY PARTNER SEMI-MONTHLY',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]
        ],
        'CHARGE BY PARTNER MONTHLY' => [
            'display_name' => 'NOTE: CHARGE BY PARTNER MONTHLY',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]
        ],
        'CHARGE BY BOTH DAILY' => [
            'display_name' => 'NOTE: CHARGE BY BOTH (CUSTOMER & PARTNER) DAILY',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]
        ],
        'CHARGE BY BOTH WEEKLY' => [
            'display_name' => 'NOTE: CHARGE BY BOTH (CUSTOMER & PARTNER) WEEKLY',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]
        ],
        'CHARGE BY BOTH MONTHLY' => [
            'display_name' => 'NOTE: CHARGE BY BOTH (CUSTOMER & PARTNER) MONTHLY',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]
        ],
        'UNCATEGORIZED' => [
            'display_name' => '⚠️ PARTNERS WITHOUT CHARGE TYPE (UNCATEGORIZED)',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]
        ]
    ];
    
    $grand_totals = ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0];
    
    foreach ($filtered_rows as $row_data) {
        $group_key = $row_data['group_key'];
        
        if (!isset($groups[$group_key])) {
            continue;
        }
        
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
    
    // ============================================
    // CAD NUMBER GENERATION - CORRECTED
    // ============================================
    
    // Get bank abbreviation
    $bank_abbreviation = '';
    if (!empty($selected_bank)) {
        $bank_details = getBankDetails($conn, $selected_bank);
        if ($bank_details) {
            $bank_abbreviation = $bank_details['bank_abbreviation'];
        }
    }
    
    // Get settlement type abbreviation - only if a specific type is selected
    $settlement_abbr = '';
    if (!empty($selected_settlement_type)) {
        $settlement_abbr = getSettlementAbbreviation($selected_settlement_type);
    }
    
    // Generate CAD number - conditionally include settlement abbreviation
    $cad_date = formatCADDate($selected_date_from, $selected_date_to);
    
    // Start building CAD number with bank abbreviation or default
    if (!empty($bank_abbreviation)) {
        $cad_number = $bank_abbreviation;
    } else {
        // If no bank selected, use a default prefix
        $cad_number = 'RFP';
    }
    
    // Add settlement type if selected
    if (!empty($settlement_abbr)) {
        $cad_number .= '-' . $settlement_abbr;
    }
    
    // Add the date part
    $cad_number .= '-' . $cad_date;
    
    // Format date range for display
    $date_range_display = formatDateRange($selected_date_from, $selected_date_to);
    
    // Current date for the header
    $current_date = strtoupper(date('F d, Y'));

} catch (Exception $e) {
    error_log("Error in export_bank_settlement_pdf: " . $e->getMessage());
    die("Error generating PDF: " . $e->getMessage());
}

// ============================================
// GENERATE HTML FOR PDF
// ============================================

$html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Settlement Report</title>
    <style>
        @page {
            margin: 15mm 12mm 15mm 12mm;
            font-size: 9pt;
        }
        body {
            font-family: "Helvetica", "Arial", sans-serif;
            font-size: 9pt;
            line-height: 1.3;
            color: #333;
        }
        .header-title {
            text-align: center;
            font-size: 14pt;
            font-weight: bold;
            letter-spacing: 1px;
            padding: 4px 0;
            border-bottom: 2px solid #000;
            margin-bottom: 6px;
        }
        .company-name {
            font-size: 11pt;
            font-weight: bold;
            float: left;
        }
        .date-info {
            font-size: 9pt;
            font-weight: bold;
            float: right;
        }
        .clearfix::after {
            content: "";
            clear: both;
            display: table;
        }
        .info-row {
            margin: 2px 0;
            font-size: 9pt;
        }
        .info-row strong {
            font-weight: bold;
        }
        .section-spacer {
            height: 4px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 8pt;
            margin-top: 4px;
        }
        table th {
            background-color: #e9ecef;
            font-weight: bold;
            text-align: center;
            padding: 4px 6px;
            border: 1px solid #333;
            font-size: 7.5pt;
        }
        table td {
            padding: 3px 6px;
            border: 1px solid #333;
            vertical-align: middle;
            font-size: 7.5pt;
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-left { text-align: left; }
        .group-header td {
            font-weight: bold;
            background-color: #f1f3f5;
            padding: 4px 8px;
            font-size: 8pt;
        }
        .group-header-uncategorized td {
            background-color: #fff3cd;
            color: #856404;
        }
        .group-header-both td {
            background-color: #d1ecf1;
            color: #0c5460;
        }
        .subtotal-row td {
            font-weight: bold;
            background-color: #e8f4fd;
            padding: 4px 6px;
        }
        .subtotal-uncategorized td {
            background-color: #fff3cd;
        }
        .subtotal-both td {
            background-color: #d1ecf1;
        }
        .grand-total td {
            font-weight: bold;
            background-color: #f8f9fa;
            padding: 5px 6px;
            border-top: 2px solid #333;
            font-size: 8.5pt;
        }
        .negative-amount {
            color: #dc3545;
        }
        .positive-amount {
            color: #28a745;
        }
        .footer {
            margin-top: 8px;
            font-size: 7pt;
            text-align: center;
            color: #6c757d;
            border-top: 1px solid #ddd;
            padding-top: 4px;
        }
        .signature-section {
            margin-top: 15px;
            font-size: 8pt;
        }
        .signature-section table {
            border: none;
            width: 100%;
            margin-top: 4px;
        }
        .signature-section td {
            border: none;
            padding: 4px 12px 4px 0;
            vertical-align: top;
            text-align: left;
        }
        .signature-name {
            font-weight: bold;
            font-size: 9pt;
            margin-bottom: 2px;
        }
        .signature-blank {
            border-bottom: 1px solid #333;
            width: 180px;
            display: inline-block;
            height: 1px;
            margin-top: 2px;
        }
        .signature-title {
            font-size: 7pt;
            color: #555;
            margin-top: 2px;
        }
        .page-break {
            page-break-after: always;
        }
        .amount-col {
            font-weight: 500;
        }
        .col-partner { width: 28%; }
        .col-account { width: 22%; }
        .col-acctno { width: 25%; }
        .col-settlement { width: 25%; text-align: right; }
    </style>
</head>
<body>';

// HEADER SECTION
$html .= '
<div class="header-title">REQUEST FOR PAYMENT FORM</div>

<div class="clearfix">
    <div class="company-name">M. LHUILLIER PHILIPPINES, INC.</div>
    <div class="date-info">DATE: ' . $current_date . '</div>
</div>

<div class="clearfix" style="margin-top: 2px;">
    <div style="float: left; font-size: 10pt; font-weight: bold;">BILLS PAYMENT SETTLEMENT</div>
    <div style="float: right; font-size: 9pt; font-weight: bold;">CAD NO.: ' . $cad_number . '</div>
</div>

<div style="text-align: right; font-size: 9pt; font-weight: bold; margin-top: 2px;">RFP NO.: </div>

<div class="section-spacer"></div>

<div class="info-row"><strong>BANK NAME:</strong> ' . ($selected_bank ?: '') . '</div>
<div class="info-row"><strong>DATE OF TRANSACTION:</strong> ' . $date_range_display . '</div>
<div class="info-row"><strong>MODE OF PAYMENT:</strong> </div>

<div class="section-spacer"></div>';

// TABLE SECTION
$html .= '<table>
    <thead>
        <tr>
            <th class="col-partner">LIST OF BILLS PAYMENT PARTNER</th>
            <th class="col-account">ACCOUNT NAME</th>
            <th class="col-acctno">ACCOUNT NUMBER</th>
            <th class="col-settlement">AMOUNT FOR SETTLEMENT</th>
        </tr>
    </thead>
    <tbody>';

// DATA ROWS
foreach ($groups as $group_key => $group_data) {
    if (empty($group_data['rows'])) {
        continue;
    }
    
    $is_uncategorized = ($group_key === 'UNCATEGORIZED');
    $is_both = (strpos($group_key, 'BOTH') !== false);
    
    // Group header
    $group_class = '';
    if ($is_uncategorized) {
        $group_class = 'group-header-uncategorized';
    } elseif ($is_both) {
        $group_class = 'group-header-both';
    }
    
    $html .= '<tr class="group-header ' . $group_class . '">';
    $html .= '<td colspan="4">' . htmlspecialchars($group_data['display_name']) . '</td>';
    $html .= '</tr>';
    
    // Data rows
    foreach ($group_data['rows'] as $row_data) {
        $settlement = $row_data['settlement_amount'];
        $settlement_class = ($settlement < 0) ? 'negative-amount' : '';
        
        $html .= '<tr>';
        $html .= '<td class="text-left">' . htmlspecialchars($row_data['partner_name']) . '</td>';
        $html .= '<td class="text-left">' . htmlspecialchars($row_data['account_name']) . '</td>';
        $html .= '<td class="text-center">' . htmlspecialchars($row_data['account_number']) . '</td>';
        $html .= '<td class="text-right amount-col ' . $settlement_class . '"> ' . number_format($settlement, 2) . '</td>';
        $html .= '</tr>';
    }
    
    // Group subtotal
    $subtotal_class = '';
    if ($is_uncategorized) {
        $subtotal_class = 'subtotal-uncategorized';
    } elseif ($is_both) {
        $subtotal_class = 'subtotal-both';
    }
    
    $totals = $group_data['totals'];
    $settlement_class = ($totals['settlement'] < 0) ? 'negative-amount' : '';
    
    // $html .= '<tr class="Subtotal ' . $subtotal_class . '">';
    // $html .= '<td colspan="3" class="text-right"><strong>Subtotal - ' . htmlspecialchars($group_data['display_name']) . '</strong></td>';
    // $html .= '<td class="text-right amount-col ' . $settlement_class . '"> ' . number_format($totals['settlement'], 2) . '</td>';
    // $html .= '</tr>';
}

// GRAND TOTAL
$settlement_class = ($grand_totals['settlement'] < 0) ? 'negative-amount' : '';

$html .= '<tr class="grand-total">';
$html .= '<td colspan="3" class="text-right"><strong>GRAND TOTAL</strong></td>';
$html .= '<td class="text-right amount-col ' . $settlement_class . '"> ' . number_format($grand_totals['settlement'], 2) . '</td>';
$html .= '</tr>';

$html .= '</tbody></table>';

// SIGNATURE SECTION - Matches the Excel sample format
$html .= '
<div class="signature-section">
    <table class="signature-table">
        <tr>
            <td style="width: 50%; text-align: left;">
                <div style="font-weight: bold; font-size: 9pt;">Prepared by :</div>
                <div style="margin-top: 10px;">
                    <div style="font-weight: bold; font-size: 9pt;">' . htmlspecialchars($display_name) . '</div>
                    <span class="signature-blank"></span>
                    <div style="font-size: 7pt; color: #555;">Accounting Staff</div>
                </div>
            </td>
            <td style="width: 50%; text-align: left;">
                <div style="font-weight: bold; font-size: 9pt;">Checked by :</div>
                <div style="margin-top: 10px;">
                    <div style="font-weight: bold; font-size: 9pt;">{Accounting Staff}</div>
                    <span class="signature-blank"></span>
                    <div style="font-size: 7pt; color: #555;">Accounting Staff</div>
                </div>
            </td>
        </tr>
        <tr>
            <td style="width: 50%; text-align: left; padding-top: 15px;">
                <div style="font-weight: bold; font-size: 9pt;">Reviewed by :</div>
                <div style="margin-top: 10px;">
                    <div style="font-weight: bold; font-size: 9pt;">ELVIE CILLO</div>
                    <span class="signature-blank"></span>
                    <div style="font-size: 7pt; color: #555;">Department Manager</div>
                </div>
            </td>
            <td style="width: 50%; text-align: left; padding-top: 15px;">
                <div style="font-weight: bold; font-size: 9pt;">Noted by :</div>
                <div style="margin-top: 10px;">
                    <div style="font-weight: bold; font-size: 9pt;">LUELLA PERALTA</div>
                    <span class="signature-blank"></span>
                    <div style="font-size: 7pt; color: #555;">Division Manager</div>
                </div>
            </td>
        </tr>
    </table>
</div>

</body>
</html>';

// ============================================
// GENERATE PDF
// ============================================

try {
    $options = new Options();
    $options->set('defaultFont', 'Helvetica');
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', false);
    $options->set('isFontSubsettingEnabled', true);
    
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    
    // Output PDF
    $filename = $cad_number . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    echo $dompdf->output();
    exit;
    
} catch (Exception $e) {
    error_log("PDF Generation Error: " . $e->getMessage());
    die("Error generating PDF: " . $e->getMessage());
}
?>