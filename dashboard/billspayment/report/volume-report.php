<?php
// Connect to the database
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../vendor/autoload.php';

// Start the session
session_start();
@include_once __DIR__ . '/../../../templates/middleware.php';
$id = resolve_user_identifier();
if (empty($id)) { header('Location: ../../../login_form.php'); exit; }
if (!function_exists('has_any_permission') || !has_any_permission(['Volume Report','Bills Payment'])) { header('Location: ../../home.php'); exit; }

// prefer explicit session values for current user email; avoid role-based gating
$current_user_email = $_SESSION['admin_email'] ?? $_SESSION['user_email'] ?? '';

// Initialize variables for filter values
$partner_id = $_POST['partner_id'] ?? '';
$time_frame = $_POST['time_frame'] ?? 'date_range';

// FIX: Properly handle date values based on time frame
if ($time_frame === 'daily') {
    $date_from = $_POST['date_from_daily'] ?? date('Y-m-d');
    $date_to = $date_from;
} else {
    $date_from = $_POST['date_from'] ?? date('Y-m-d');
    $date_to = $_POST['date_to'] ?? date('Y-m-d');
}

$month_from = $_POST['month_from'] ?? date('Y-m');
$month_to = $_POST['month_to'] ?? date('Y-m');
// Always use full range — day/month filter buttons removed; breakdown is shown via expandable rows
$selected_day = 'all';
$selected_month = 'all';
$results = [];
$daily_summary = [];
$monthly_summary = [];
$partner_period_details = []; // [partner_key][period_key] => metrics row for expandable breakdown

// FIX: Function to build the WHERE clause with proper connection handling
function buildWhereClause(
    string $time_frame,
    string|int $partner_id,
    ?string $date_from,
    ?string $date_to,
    ?string $month_from,
    ?string $month_to,
    ?string $selected_day = null,
    ?string $selected_month = null
) {
    global $conn; // FIX: Use global keyword to access $conn
    
    $conditions = [];
    
    // Add partner condition if selected
    if (!empty($partner_id)) {
        $conditions[] = "bt.partner_id_kpx = '" . mysqli_real_escape_string($conn, $partner_id) . "'";
    }
    
    // FIX: Add date conditions based on time frame
    switch ($time_frame) {
        case 'daily':
            // FIX: Use the date from daily field
            if (!empty($date_from)) {
                $start_datetime = $date_from . ' 00:00:00';
                $end_datetime = $date_from . ' 23:59:59';
                $conditions[] = "((bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '')) OR bt.cancellation_date BETWEEN '$start_datetime' AND '$end_datetime')";
            }
            break;
            
        case 'date_range':
            if (!empty($date_from) && !empty($date_to)) {
                // If a specific day is selected, filter for that day only
                if ($selected_day && $selected_day !== 'all') {
                    $selected_date = date('Y-m-d', strtotime($date_from . ' + ' . ($selected_day - 1) . ' days'));
                    $start_datetime = $selected_date . ' 00:00:00';
                    $end_datetime = $selected_date . ' 23:59:59';
                    $conditions[] = "((bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '')) OR bt.cancellation_date BETWEEN '$start_datetime' AND '$end_datetime')";
                } else {
                    $start_datetime = $date_from . ' 00:00:00';
                    $end_datetime = $date_to . ' 23:59:59';
                    $conditions[] = "((bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '')) OR bt.cancellation_date BETWEEN '$start_datetime' AND '$end_datetime')";
                }
            }
            break;
            
        case 'monthly':
            if (!empty($month_from) && !empty($month_to)) {
                // If a specific month is selected
                if ($selected_month && $selected_month !== 'all') {
                    $selected_month_date = date('Y-m', strtotime($month_from . ' + ' . ($selected_month - 1) . ' months'));
                    $start_datetime = $selected_month_date . '-01 00:00:00';
                    $end_datetime = date('Y-m-t 23:59:59', strtotime($selected_month_date . '-01'));
                    $conditions[] = "((bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '')) OR bt.cancellation_date BETWEEN '$start_datetime' AND '$end_datetime')";
                } else {
                    $start_datetime = $month_from . '-01 00:00:00';
                    $end_datetime = date('Y-m-t 23:59:59', strtotime($month_to . '-01'));
                    $conditions[] = "((bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '')) OR bt.cancellation_date BETWEEN '$start_datetime' AND '$end_datetime')";
                }
            }
            break;
    }
    
    return !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';
}

/**
 * Run the partner-level aggregate query for a given datetime window.
 * Returns array of associative rows (same shape as main report query).
 */
function getPartnerPeriodResults(string $start_datetime, string $end_datetime, string|int $partner_id = ''): array {
    global $conn;
    
    $partner_condition = '';
    if (!empty($partner_id)) {
        $partner_condition = " AND bt.partner_id_kpx = '" . mysqli_real_escape_string($conn, $partner_id) . "'";
    }
    
    $where = "WHERE ((bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '')) OR bt.cancellation_date BETWEEN '$start_datetime' AND '$end_datetime')" . $partner_condition;
    
    $query = "SELECT 
            COALESCE(NULLIF(bt.partner_id_kpx, ''), CONCAT('UNKNOWN_', bt.sub_billers_name, '_', bt.id)) as partner_id_kpx,
            CASE 
                WHEN bt.sub_billers_name IS NULL OR bt.sub_billers_name = '' THEN '-'
                ELSE bt.sub_billers_name
            END as sub_billers_name,
            pm.partner_name,
            pm.serviceCharge,
            pm.charge_to,
            COUNT(CASE WHEN bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '') THEN 1 END) as datetime_volume,
            SUM(CASE WHEN bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '') THEN bt.amount_paid ELSE 0 END) as datetime_amount_paid,
            SUM(CASE WHEN bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '') THEN bt.charge_to_partner ELSE 0 END) as datetime_charge_partner,
            SUM(CASE WHEN bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '') THEN bt.charge_to_customer ELSE 0 END) as datetime_charge_customer,
            SUM(CASE WHEN bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '') THEN (bt.charge_to_partner + bt.charge_to_customer) ELSE 0 END) as datetime_charge_total,
            COUNT(CASE WHEN bt.cancellation_date BETWEEN '$start_datetime' AND '$end_datetime' THEN 1 END) as cancellation_volume,
            SUM(CASE WHEN bt.cancellation_date BETWEEN '$start_datetime' AND '$end_datetime' THEN bt.amount_paid ELSE 0 END) as cancellation_amount_paid,
            SUM(CASE WHEN bt.cancellation_date BETWEEN '$start_datetime' AND '$end_datetime' THEN bt.charge_to_partner ELSE 0 END) as cancellation_charge_partner,
            SUM(CASE WHEN bt.cancellation_date BETWEEN '$start_datetime' AND '$end_datetime' THEN bt.charge_to_customer ELSE 0 END) as cancellation_charge_customer,
            SUM(CASE WHEN bt.cancellation_date BETWEEN '$start_datetime' AND '$end_datetime' THEN (bt.charge_to_partner + bt.charge_to_customer) ELSE 0 END) as cancellation_charge_total,
            (COUNT(CASE WHEN bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '') THEN 1 END) - 
             COUNT(CASE WHEN bt.cancellation_date BETWEEN '$start_datetime' AND '$end_datetime' THEN 1 END)) as total_volume,
            (SUM(CASE WHEN bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '') THEN bt.amount_paid ELSE 0 END) + 
             SUM(CASE WHEN bt.cancellation_date BETWEEN '$start_datetime' AND '$end_datetime' THEN bt.amount_paid ELSE 0 END)) as total_amount_paid,
            (SUM(CASE WHEN bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '') THEN bt.charge_to_partner ELSE 0 END) - 
             SUM(CASE WHEN bt.cancellation_date BETWEEN '$start_datetime' AND '$end_datetime' THEN bt.charge_to_partner ELSE 0 END)) as total_charge_partner,
            (SUM(CASE WHEN bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '') THEN bt.charge_to_customer ELSE 0 END) - 
             SUM(CASE WHEN bt.cancellation_date BETWEEN '$start_datetime' AND '$end_datetime' THEN bt.charge_to_customer ELSE 0 END)) as total_charge_customer,
            (SUM(CASE WHEN bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '') THEN (bt.charge_to_partner + bt.charge_to_customer) ELSE 0 END) - 
             SUM(CASE WHEN bt.cancellation_date BETWEEN '$start_datetime' AND '$end_datetime' THEN (bt.charge_to_partner + bt.charge_to_customer) ELSE 0 END)) as total_charge,
            COUNT(CASE WHEN bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '') AND bt.settle_unsettle = 'Settled' THEN 1 END) as settlement_volume,
            SUM(CASE 
                WHEN bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' 
                     AND (bt.status IS NULL OR bt.status = '') 
                     AND bt.settle_unsettle = 'Settled' 
                THEN 
                    CASE 
                        -- CHARGE BY CUSTOMER DAILY: deduct partner charge
                        WHEN UPPER(COALESCE(pm.charge_to, '')) = 'CUSTOMER' 
                             AND UPPER(COALESCE(pm.serviceCharge, '')) = 'DAILY'
                        THEN bt.amount_paid - IFNULL(bt.charge_to_partner, 0)
                        -- BOTH: deduct partner charge
                        WHEN UPPER(COALESCE(pm.charge_to, '')) = 'BOTH'
                        THEN bt.amount_paid - IFNULL(bt.charge_to_partner, 0)
                        -- CHARGE BY PARTNER (any frequency): full amount_paid
                        WHEN UPPER(COALESCE(pm.charge_to, '')) = 'PARTNER'
                        THEN bt.amount_paid
                        -- CHARGE BY CUSTOMER (Monthly/Semi-monthly/Weekly/other): full amount_paid
                        ELSE bt.amount_paid
                    END
                ELSE 0 
            END) as settlement_amount_paid,
            SUM(CASE WHEN bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '') AND bt.settle_unsettle = 'Settled' THEN bt.charge_to_partner ELSE 0 END) as settlement_charge_partner,
            SUM(CASE WHEN bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '') AND bt.settle_unsettle = 'Settled' THEN bt.charge_to_customer ELSE 0 END) as settlement_charge_customer,
            SUM(CASE WHEN bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '') AND bt.settle_unsettle = 'Settled' THEN (bt.charge_to_partner + bt.charge_to_customer) ELSE 0 END) as settlement_charge
          FROM mldb.billspayment_transaction bt
          LEFT JOIN masterdata.partner_masterfile pm ON bt.partner_id_kpx = pm.partner_id_kpx
          $where
          GROUP BY 
            COALESCE(NULLIF(bt.partner_id_kpx, ''), CONCAT('UNKNOWN_', bt.sub_billers_name, '_', bt.id)),
            CASE 
                WHEN bt.sub_billers_name IS NULL OR bt.sub_billers_name = '' THEN '-'
                ELSE bt.sub_billers_name
            END,
            pm.partner_name,
            pm.serviceCharge,
            pm.charge_to
          ORDER BY 
            CASE WHEN pm.partner_name IS NULL THEN 1 ELSE 0 END,
            pm.partner_name ASC,
            total_volume DESC";
    
    $res = mysqli_query($conn, $query);
    $rows = [];
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            $r['variance_volume'] = ($r['total_volume'] ?? 0) - ($r['settlement_volume'] ?? 0);
            
            // Check charge type for variance calculation
            $charge_to = $r['charge_to'] ?? '';
            $serviceCharge = $r['serviceCharge'] ?? '';
            $is_partner_charge = (strtoupper($charge_to) === 'PARTNER');
            $is_customer_daily = (strtoupper($charge_to) === 'CUSTOMER' && strtoupper($serviceCharge) === 'DAILY');
            $is_customer_non_daily = (strtoupper($charge_to) === 'CUSTOMER' && strtoupper($serviceCharge) !== 'DAILY');
            $is_both = (strtoupper($charge_to) === 'BOTH');
            
            if ($is_partner_charge || $is_customer_non_daily) {
                // CHARGE BY PARTNER or CHARGE BY CUSTOMER (WEEKLY/MONTHLY/SEMI-MONTHLY/other):
                // Settlement Amount = full amount_paid → variance = Net Amount - Settlement Amount (should be 0)
                $r['variance_amount'] = ($r['total_amount_paid'] ?? 0) - ($r['settlement_amount_paid'] ?? 0);
            } elseif ($is_customer_daily) {
                // CHARGE BY CUSTOMER DAILY: variance = Net Amount - Settlement Amount - Settlement Charge to Partner
                $r['variance_amount'] = ($r['total_amount_paid'] ?? 0) - (($r['settlement_amount_paid'] ?? 0) + ($r['settlement_charge_partner'] ?? 0));
            } elseif ($is_both) {
                // BOTH: variance = Net Amount - Settlement Amount - Total Settlement Charge
                $r['variance_amount'] = ($r['total_amount_paid'] ?? 0) - (($r['settlement_amount_paid'] ?? 0) + ($r['settlement_charge'] ?? 0));
            } else {
                // Fallback: variance = Net Amount - Settlement Amount - Total Settlement Charge
                $r['variance_amount'] = ($r['total_amount_paid'] ?? 0) - (($r['settlement_amount_paid'] ?? 0) + ($r['settlement_charge'] ?? 0));
            }
            
            $r['charge_type_display'] = getChargeTypeDisplay($r['serviceCharge'] ?? '', $r['charge_to'] ?? '');
            $rows[] = $r;
        }
    } else {
        error_log("MySQL Error in getPartnerPeriodResults: " . mysqli_error($conn));
    }
    return $rows;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_report'])) {
    // selected_day / selected_month always 'all' (filter buttons removed; use expandable breakdown instead)
    $selected_day = 'all';
    $selected_month = 'all';
    
    // FIX: Re-fetch date values for daily time frame
    if ($time_frame === 'daily') {
        $date_from = $_POST['date_from_daily'] ?? date('Y-m-d');
        $date_to = $date_from;
    }
    
    // ============================================
    // FIX: Define start and end datetime variables
    // ============================================
    $start_datetime = '';
    $end_datetime = '';
    
    // FIX: Calculate start and end datetime for use in queries
    if ($time_frame === 'daily') {
        $start_datetime = $date_from . ' 00:00:00';
        $end_datetime = $date_from . ' 23:59:59';
    } elseif ($time_frame === 'date_range') {
        if ($selected_day && $selected_day !== 'all') {
            $selected_date = date('Y-m-d', strtotime($date_from . ' + ' . ($selected_day - 1) . ' days'));
            $start_datetime = $selected_date . ' 00:00:00';
            $end_datetime = $selected_date . ' 23:59:59';
        } else {
            $start_datetime = $date_from . ' 00:00:00';
            $end_datetime = $date_to . ' 23:59:59';
        }
    } elseif ($time_frame === 'monthly') {
        if ($selected_month && $selected_month !== 'all') {
            $selected_month_date = date('Y-m', strtotime($month_from . ' + ' . ($selected_month - 1) . ' months'));
            $start_datetime = $selected_month_date . '-01 00:00:00';
            $end_datetime = date('Y-m-t 23:59:59', strtotime($selected_month_date . '-01'));
        } else {
            $start_datetime = $month_from . '-01 00:00:00';
            $end_datetime = date('Y-m-t 23:59:59', strtotime($month_to . '-01'));
        }
    }
    
    // Build the query for billspayment_transaction with detailed breakdown
    // FIX: Use separate conditions for normal and cancelled transactions
    $where_clause = buildWhereClause($time_frame, $partner_id, $date_from, $date_to, $month_from, $month_to, $selected_day, $selected_month);
    
    if (!empty($where_clause) && !empty($start_datetime) && !empty($end_datetime)) {
        // ============================================
        // FIX: Updated query - Transactions based on datetime with status NULL/empty
        // Cancelled transactions based on cancellation_date
        // ORDER BY partner_name ASC for proper alphabetical sorting
        // ============================================
        $query = "SELECT 
            -- FIX: Use COALESCE to handle NULL/empty partner_id_kpx
            COALESCE(NULLIF(bt.partner_id_kpx, ''), CONCAT('UNKNOWN_', bt.sub_billers_name, '_', bt.id)) as partner_id_kpx,
            CASE 
                WHEN bt.sub_billers_name IS NULL OR bt.sub_billers_name = '' THEN '-'
                ELSE bt.sub_billers_name
            END as sub_billers_name,
            -- Get partner_name and charge type fields from masterdata table using LEFT JOIN
            pm.partner_name,
            pm.serviceCharge,
            pm.charge_to,
            -- Transactions (based on datetime AND status IS NULL OR status = '')
            COUNT(CASE WHEN bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '') THEN 1 END) as datetime_volume,
            SUM(CASE WHEN bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '') THEN bt.amount_paid ELSE 0 END) as datetime_amount_paid,
            SUM(CASE WHEN bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '') THEN bt.charge_to_partner ELSE 0 END) as datetime_charge_partner,
            SUM(CASE WHEN bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '') THEN bt.charge_to_customer ELSE 0 END) as datetime_charge_customer,
            SUM(CASE WHEN bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '') THEN (bt.charge_to_partner + bt.charge_to_customer) ELSE 0 END) as datetime_charge_total,
            -- Cancelled Transactions (based on cancellation_date)
            COUNT(CASE WHEN bt.cancellation_date BETWEEN '$start_datetime' AND '$end_datetime' THEN 1 END) as cancellation_volume,
            SUM(CASE WHEN bt.cancellation_date BETWEEN '$start_datetime' AND '$end_datetime' THEN bt.amount_paid ELSE 0 END) as cancellation_amount_paid,
            SUM(CASE WHEN bt.cancellation_date BETWEEN '$start_datetime' AND '$end_datetime' THEN bt.charge_to_partner ELSE 0 END) as cancellation_charge_partner,
            SUM(CASE WHEN bt.cancellation_date BETWEEN '$start_datetime' AND '$end_datetime' THEN bt.charge_to_customer ELSE 0 END) as cancellation_charge_customer,
            SUM(CASE WHEN bt.cancellation_date BETWEEN '$start_datetime' AND '$end_datetime' THEN (bt.charge_to_partner + bt.charge_to_customer) ELSE 0 END) as cancellation_charge_total,
            -- NET values (datetime - cancellation)
            (COUNT(CASE WHEN bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '') THEN 1 END) - 
             COUNT(CASE WHEN bt.cancellation_date BETWEEN '$start_datetime' AND '$end_datetime' THEN 1 END)) as total_volume,
            (SUM(CASE WHEN bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '') THEN bt.amount_paid ELSE 0 END) + 
             SUM(CASE WHEN bt.cancellation_date BETWEEN '$start_datetime' AND '$end_datetime' THEN bt.amount_paid ELSE 0 END)) as total_amount_paid,
            (SUM(CASE WHEN bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '') THEN bt.charge_to_partner ELSE 0 END) - 
             SUM(CASE WHEN bt.cancellation_date BETWEEN '$start_datetime' AND '$end_datetime' THEN bt.charge_to_partner ELSE 0 END)) as total_charge_partner,
            (SUM(CASE WHEN bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '') THEN bt.charge_to_customer ELSE 0 END) - 
             SUM(CASE WHEN bt.cancellation_date BETWEEN '$start_datetime' AND '$end_datetime' THEN bt.charge_to_customer ELSE 0 END)) as total_charge_customer,
            (SUM(CASE WHEN bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '') THEN (bt.charge_to_partner + bt.charge_to_customer) ELSE 0 END) - 
             SUM(CASE WHEN bt.cancellation_date BETWEEN '$start_datetime' AND '$end_datetime' THEN (bt.charge_to_partner + bt.charge_to_customer) ELSE 0 END)) as total_charge,
            -- SETTLEMENT Transactions (include all settled based on datetime and status NULL/empty)
            -- Settlement Amount adjusted by charge type:
            --   CHARGE BY PARTNER (any frequency) → amount_paid (full amount)
            --   CHARGE BY CUSTOMER DAILY → amount_paid - charge_to_partner
            --   BOTH → amount_paid - charge_to_partner
            --   CHARGE BY CUSTOMER (Monthly/Semi-monthly/Weekly) → amount_paid (full amount)
            COUNT(CASE WHEN bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '') AND bt.settle_unsettle = 'Settled' THEN 1 END) as settlement_volume,
            SUM(CASE 
                WHEN bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' 
                     AND (bt.status IS NULL OR bt.status = '') 
                     AND bt.settle_unsettle = 'Settled' 
                THEN 
                    CASE 
                        -- CHARGE BY CUSTOMER DAILY: deduct partner charge
                        WHEN UPPER(COALESCE(pm.charge_to, '')) = 'CUSTOMER' 
                             AND UPPER(COALESCE(pm.serviceCharge, '')) = 'DAILY'
                        THEN bt.amount_paid - IFNULL(bt.charge_to_partner, 0)
                        -- BOTH: deduct partner charge
                        WHEN UPPER(COALESCE(pm.charge_to, '')) = 'BOTH'
                        THEN bt.amount_paid - IFNULL(bt.charge_to_partner, 0)
                        -- CHARGE BY PARTNER (any frequency): full amount_paid
                        WHEN UPPER(COALESCE(pm.charge_to, '')) = 'PARTNER'
                        THEN bt.amount_paid
                        -- CHARGE BY CUSTOMER (Monthly/Semi-monthly/Weekly/other): full amount_paid
                        ELSE bt.amount_paid
                    END
                ELSE 0 
            END) as settlement_amount_paid,
            SUM(CASE WHEN bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '') AND bt.settle_unsettle = 'Settled' THEN bt.charge_to_partner ELSE 0 END) as settlement_charge_partner,
            SUM(CASE WHEN bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '') AND bt.settle_unsettle = 'Settled' THEN bt.charge_to_customer ELSE 0 END) as settlement_charge_customer,
            SUM(CASE WHEN bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '') AND bt.settle_unsettle = 'Settled' THEN (bt.charge_to_partner + bt.charge_to_customer) ELSE 0 END) as settlement_charge
          FROM mldb.billspayment_transaction bt
          LEFT JOIN masterdata.partner_masterfile pm ON bt.partner_id_kpx = pm.partner_id_kpx
          $where_clause
          GROUP BY 
            COALESCE(NULLIF(bt.partner_id_kpx, ''), CONCAT('UNKNOWN_', bt.sub_billers_name, '_', bt.id)),
            CASE 
                WHEN bt.sub_billers_name IS NULL OR bt.sub_billers_name = '' THEN '-'
                ELSE bt.sub_billers_name
            END,
            pm.partner_name,
            pm.serviceCharge,
            pm.charge_to
          ORDER BY 
            -- Put NULL partner names (unassigned) at the top
            CASE WHEN pm.partner_name IS NULL THEN 1 ELSE 0 END,
            -- Then sort alphabetically by partner name
            pm.partner_name ASC,
            -- Then by total volume descending within each partner
            total_volume DESC";
        
        $results = mysqli_query($conn, $query);
        
        // Debug: Check for query errors
        if (!$results) {
            error_log("MySQL Error: " . mysqli_error($conn));
            error_log("Query: " . $query);
        }
    }
    
    // FIX: Get daily summary for daily time frame with NET values
    // ADDED: Settlement summary for daily
    if ($time_frame === 'daily' && !empty($date_from)) {
        $start_datetime = $date_from . ' 00:00:00';
        $end_datetime = $date_from . ' 23:59:59';
        
        $day_query = "SELECT 
                        -- Normal/Success transactions (datetime with status NULL/empty AND cancellation_date IS NULL)
                        COUNT(CASE WHEN bt.cancellation_date IS NULL AND bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '') THEN 1 END) as success_volume,
                        SUM(CASE WHEN bt.cancellation_date IS NULL AND bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '') THEN bt.amount_paid ELSE 0 END) as success_amount,
                        SUM(CASE WHEN bt.cancellation_date IS NULL AND bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '') THEN (bt.charge_to_partner + bt.charge_to_customer) ELSE 0 END) as success_charge,
                        -- Cancelled transactions (based on cancellation_date)
                        COUNT(CASE WHEN bt.cancellation_date BETWEEN '$start_datetime' AND '$end_datetime' THEN 1 END) as cancelled_volume,
                        SUM(CASE WHEN bt.cancellation_date BETWEEN '$start_datetime' AND '$end_datetime' THEN bt.amount_paid ELSE 0 END) as cancelled_amount,
                        SUM(CASE WHEN bt.cancellation_date BETWEEN '$start_datetime' AND '$end_datetime' THEN (bt.charge_to_partner + bt.charge_to_customer) ELSE 0 END) as cancelled_charge,
                        -- NET volume (success - cancelled)
                        (COUNT(CASE WHEN bt.cancellation_date IS NULL AND bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '') THEN 1 END) - 
                         COUNT(CASE WHEN bt.cancellation_date BETWEEN '$start_datetime' AND '$end_datetime' THEN 1 END)) as net_volume,
                        (SUM(CASE WHEN bt.cancellation_date IS NULL AND bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '') THEN bt.amount_paid ELSE 0 END) - 
                         SUM(CASE WHEN bt.cancellation_date BETWEEN '$start_datetime' AND '$end_datetime' THEN bt.amount_paid ELSE 0 END)) as net_amount,
                        (SUM(CASE WHEN bt.cancellation_date IS NULL AND bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '') THEN (bt.charge_to_partner + bt.charge_to_customer) ELSE 0 END) + 
                         SUM(CASE WHEN bt.cancellation_date BETWEEN '$start_datetime' AND '$end_datetime' THEN (bt.charge_to_partner + bt.charge_to_customer) ELSE 0 END)) as net_charge,
                        -- SETTLEMENT Summary (based on datetime with status NULL/empty)
                        COUNT(CASE WHEN bt.settle_unsettle = 'Settled' AND bt.cancellation_date IS NULL AND bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '') THEN 1 END) as settlement_volume,
                        SUM(CASE WHEN bt.settle_unsettle = 'Settled' AND bt.cancellation_date IS NULL AND bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '') THEN bt.amount_paid ELSE 0 END) as settlement_amount,
                        SUM(CASE WHEN bt.settle_unsettle = 'Settled' AND bt.cancellation_date IS NULL AND bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '') THEN (bt.charge_to_partner + bt.charge_to_customer) ELSE 0 END) as settlement_charge
                      FROM mldb.billspayment_transaction bt
                      WHERE ((bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '')) OR bt.cancellation_date BETWEEN '$start_datetime' AND '$end_datetime')";
        
        if (!empty($partner_id)) {
            $day_query .= " AND bt.partner_id_kpx = '" . mysqli_real_escape_string($conn, $partner_id) . "'";
        }
        
        $day_result = mysqli_query($conn, $day_query);
        $day_data = mysqli_fetch_assoc($day_result);
        
        $daily_summary[1] = [
            'date' => $date_from,
            'day_number' => 1,
            'total_volume' => $day_data['net_volume'] ?? 0,
            'total_amount_paid' => $day_data['net_amount'] ?? 0,
            'total_charge' => $day_data['net_charge'] ?? 0,
            'success_volume' => $day_data['success_volume'] ?? 0,
            'cancelled_volume' => $day_data['cancelled_volume'] ?? 0,
            'settlement_volume' => $day_data['settlement_volume'] ?? 0,
            'settlement_amount' => $day_data['settlement_amount'] ?? 0,
            'settlement_charge' => $day_data['settlement_charge'] ?? 0
        ];
    }
    
    // FIX: Get daily summary for the date range with NET values
    // ADDED: Settlement summary for each day
    if ($time_frame === 'date_range' && !empty($date_from) && !empty($date_to)) {
        $start_date = $date_from;
        $end_date = $date_to;
        $current_date = $start_date;
        $day_counter = 1;
        
        while (strtotime($current_date) <= strtotime($end_date)) {
            $start_datetime = $current_date . ' 00:00:00';
            $end_datetime = $current_date . ' 23:59:59';
            
            $day_query = "SELECT 
                            -- Normal/Success transactions (datetime with status NULL/empty AND cancellation_date IS NULL)
                            COUNT(CASE WHEN bt.cancellation_date IS NULL AND bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '') THEN 1 END) as success_volume,
                            SUM(CASE WHEN bt.cancellation_date IS NULL AND bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '') THEN bt.amount_paid ELSE 0 END) as success_amount,
                            SUM(CASE WHEN bt.cancellation_date IS NULL AND bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '') THEN (bt.charge_to_partner + bt.charge_to_customer) ELSE 0 END) as success_charge,
                            -- Cancelled transactions (based on cancellation_date)
                            COUNT(CASE WHEN bt.cancellation_date BETWEEN '$start_datetime' AND '$end_datetime' THEN 1 END) as cancelled_volume,
                            SUM(CASE WHEN bt.cancellation_date BETWEEN '$start_datetime' AND '$end_datetime' THEN bt.amount_paid ELSE 0 END) as cancelled_amount,
                            SUM(CASE WHEN bt.cancellation_date BETWEEN '$start_datetime' AND '$end_datetime' THEN (bt.charge_to_partner + bt.charge_to_customer) ELSE 0 END) as cancelled_charge,
                            -- NET volume (success - cancelled)
                            (COUNT(CASE WHEN bt.cancellation_date IS NULL AND bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '') THEN 1 END) - 
                             COUNT(CASE WHEN bt.cancellation_date BETWEEN '$start_datetime' AND '$end_datetime' THEN 1 END)) as net_volume,
                            (SUM(CASE WHEN bt.cancellation_date IS NULL AND bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '') THEN bt.amount_paid ELSE 0 END) + 
                             SUM(CASE WHEN bt.cancellation_date BETWEEN '$start_datetime' AND '$end_datetime' THEN bt.amount_paid ELSE 0 END)) as net_amount,
                            (SUM(CASE WHEN bt.cancellation_date IS NULL AND bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '') THEN (bt.charge_to_partner + bt.charge_to_customer) ELSE 0 END) + 
                             SUM(CASE WHEN bt.cancellation_date BETWEEN '$start_datetime' AND '$end_datetime' THEN (bt.charge_to_partner + bt.charge_to_customer) ELSE 0 END)) as net_charge,
                            -- SETTLEMENT Summary (based on datetime with status NULL/empty)
                            COUNT(CASE WHEN bt.settle_unsettle = 'Settled' AND bt.cancellation_date IS NULL AND bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '') THEN 1 END) as settlement_volume,
                            SUM(CASE WHEN bt.settle_unsettle = 'Settled' AND bt.cancellation_date IS NULL AND bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '') THEN bt.amount_paid ELSE 0 END) as settlement_amount,
                            SUM(CASE WHEN bt.settle_unsettle = 'Settled' AND bt.cancellation_date IS NULL AND bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '') THEN (bt.charge_to_partner + bt.charge_to_customer) ELSE 0 END) as settlement_charge
                          FROM mldb.billspayment_transaction bt
                          WHERE ((bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '')) OR bt.cancellation_date BETWEEN '$start_datetime' AND '$end_datetime')";
            
            if (!empty($partner_id)) {
                $day_query .= " AND bt.partner_id_kpx = '" . mysqli_real_escape_string($conn, $partner_id) . "'";
            }
            
            $day_result = mysqli_query($conn, $day_query);
            $day_data = mysqli_fetch_assoc($day_result);
            
            $daily_summary[$day_counter] = [
                'date' => $current_date,
                'day_number' => $day_counter,
                'total_volume' => $day_data['net_volume'] ?? 0,
                'total_amount_paid' => $day_data['net_amount'] ?? 0,
                'total_charge' => $day_data['net_charge'] ?? 0,
                'success_volume' => $day_data['success_volume'] ?? 0,
                'cancelled_volume' => $day_data['cancelled_volume'] ?? 0,
                'settlement_volume' => $day_data['settlement_volume'] ?? 0,
                'settlement_amount' => $day_data['settlement_amount'] ?? 0,
                'settlement_charge' => $day_data['settlement_charge'] ?? 0
            ];
            
            // Collect per-partner breakdown for this day (for expandable rows)
            $period_rows = getPartnerPeriodResults($start_datetime, $end_datetime, $partner_id);
            foreach ($period_rows as $prow) {
                $pkey = $prow['partner_id_kpx'] . '|' . ($prow['sub_billers_name'] ?? '-');
                if (!isset($partner_period_details[$pkey])) {
                    $partner_period_details[$pkey] = [];
                }
                $partner_period_details[$pkey][$current_date] = $prow;
            }
            
            $current_date = date('Y-m-d', strtotime($current_date . ' + 1 day'));
            $day_counter++;
        }
    }
    
    // FIX: Get monthly summary for the month range with NET values
    // ADDED: Settlement summary for each month
    if ($time_frame === 'monthly' && !empty($month_from) && !empty($month_to)) {
        $start_month = $month_from;
        $end_month = $month_to;
        $current_month = $start_month;
        $month_counter = 1;
        
        while (strtotime($current_month) <= strtotime($end_month)) {
            $start_datetime = $current_month . '-01 00:00:00';
            $end_datetime = date('Y-m-t 23:59:59', strtotime($current_month . '-01'));
            
            $month_query = "SELECT 
                            -- Normal/Success transactions (datetime with status NULL/empty AND cancellation_date IS NULL)
                            COUNT(CASE WHEN bt.cancellation_date IS NULL AND bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '') THEN 1 END) as success_volume,
                            SUM(CASE WHEN bt.cancellation_date IS NULL AND bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '') THEN bt.amount_paid ELSE 0 END) as success_amount,
                            SUM(CASE WHEN bt.cancellation_date IS NULL AND bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '') THEN (bt.charge_to_partner + bt.charge_to_customer) ELSE 0 END) as success_charge,
                            -- Cancelled transactions (based on cancellation_date)
                            COUNT(CASE WHEN bt.cancellation_date BETWEEN '$start_datetime' AND '$end_datetime' THEN 1 END) as cancelled_volume,
                            SUM(CASE WHEN bt.cancellation_date BETWEEN '$start_datetime' AND '$end_datetime' THEN bt.amount_paid ELSE 0 END) as cancelled_amount,
                            SUM(CASE WHEN bt.cancellation_date BETWEEN '$start_datetime' AND '$end_datetime' THEN (bt.charge_to_partner + bt.charge_to_customer) ELSE 0 END) as cancelled_charge,
                            -- NET volume (success - cancelled)
                            (COUNT(CASE WHEN bt.cancellation_date IS NULL AND bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '') THEN 1 END) - 
                             COUNT(CASE WHEN bt.cancellation_date BETWEEN '$start_datetime' AND '$end_datetime' THEN 1 END)) as net_volume,
                            (SUM(CASE WHEN bt.cancellation_date IS NULL AND bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '') THEN bt.amount_paid ELSE 0 END) + 
                             SUM(CASE WHEN bt.cancellation_date BETWEEN '$start_datetime' AND '$end_datetime' THEN bt.amount_paid ELSE 0 END)) as net_amount,
                            (SUM(CASE WHEN bt.cancellation_date IS NULL AND bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '') THEN (bt.charge_to_partner + bt.charge_to_customer) ELSE 0 END) + 
                             SUM(CASE WHEN bt.cancellation_date BETWEEN '$start_datetime' AND '$end_datetime' THEN (bt.charge_to_partner + bt.charge_to_customer) ELSE 0 END)) as net_charge,
                            -- SETTLEMENT Summary (based on datetime with status NULL/empty)
                            COUNT(CASE WHEN bt.settle_unsettle = 'Settled' AND bt.cancellation_date IS NULL AND bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '') THEN 1 END) as settlement_volume,
                            SUM(CASE WHEN bt.settle_unsettle = 'Settled' AND bt.cancellation_date IS NULL AND bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '') THEN bt.amount_paid ELSE 0 END) as settlement_amount,
                            SUM(CASE WHEN bt.settle_unsettle = 'Settled' AND bt.cancellation_date IS NULL AND bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '') THEN (bt.charge_to_partner + bt.charge_to_customer) ELSE 0 END) as settlement_charge
                          FROM mldb.billspayment_transaction bt
                          WHERE ((bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '')) OR bt.cancellation_date BETWEEN '$start_datetime' AND '$end_datetime')";
            
            if (!empty($partner_id)) {
                $month_query .= " AND bt.partner_id_kpx = '" . mysqli_real_escape_string($conn, $partner_id) . "'";
            }
            
            $month_result = mysqli_query($conn, $month_query);
            $month_data = mysqli_fetch_assoc($month_result);
            
            $monthly_summary[$month_counter] = [
                'month' => $current_month,
                'month_number' => $month_counter,
                'month_name' => date('M Y', strtotime($current_month . '-01')),
                'total_volume' => $month_data['net_volume'] ?? 0,
                'total_amount_paid' => $month_data['net_amount'] ?? 0,
                'total_charge' => $month_data['net_charge'] ?? 0,
                'success_volume' => $month_data['success_volume'] ?? 0,
                'cancelled_volume' => $month_data['cancelled_volume'] ?? 0,
                'settlement_volume' => $month_data['settlement_volume'] ?? 0,
                'settlement_amount' => $month_data['settlement_amount'] ?? 0,
                'settlement_charge' => $month_data['settlement_charge'] ?? 0
            ];
            
            // Collect per-partner breakdown for this month (for expandable rows)
            $period_rows = getPartnerPeriodResults($start_datetime, $end_datetime, $partner_id);
            foreach ($period_rows as $prow) {
                $pkey = $prow['partner_id_kpx'] . '|' . ($prow['sub_billers_name'] ?? '-');
                if (!isset($partner_period_details[$pkey])) {
                    $partner_period_details[$pkey] = [];
                }
                $partner_period_details[$pkey][$current_month] = $prow;
            }
            
            $current_month = date('Y-m', strtotime($current_month . ' + 1 month'));
            $month_counter++;
        }
    }
}

// Get partner name for display
$selected_partner_name = '';
if (!empty($partner_id)) {
    $name_query = "SELECT partner_name FROM masterdata.partner_masterfile WHERE partner_id_kpx = '" . mysqli_real_escape_string($conn, $partner_id) . "'";
    $name_result = mysqli_query($conn, $name_query);
    if ($name_row = mysqli_fetch_assoc($name_result)) {
        $selected_partner_name = $name_row['partner_name'];
    }
}

// ============================================
// FIX: Function to clean partner name for display
// ============================================
function cleanPartnerName($partner_id, $sub_billers_name = '') {
    global $conn;
    
    // If partner_id is empty or starts with 'UNKNOWN_', show sub_billers_name
    if (empty($partner_id) || strpos($partner_id, 'UNKNOWN_') === 0) {
        return !empty($sub_billers_name) && $sub_billers_name !== '-' 
            ? $sub_billers_name . ' (Unassigned)' 
            : 'Unassigned Partner';
    }
    
    // Try to get partner name from masterfile
    $name_query = "SELECT partner_name FROM masterdata.partner_masterfile WHERE partner_id_kpx = '" . mysqli_real_escape_string($conn, $partner_id) . "'";
    $name_result = mysqli_query($conn, $name_query);
    if ($name_row = mysqli_fetch_assoc($name_result)) {
        return $name_row['partner_name'];
    }
    
    // Fallback: return the partner_id
    return $partner_id;
}

// ============================================
// FUNCTION TO GET CHARGE TYPE DISPLAY
// ============================================
function getChargeTypeDisplay($serviceCharge, $charge_to) {
    // Handle NULL or empty values
    if (empty($serviceCharge) && empty($charge_to)) {
        return 'N/A';
    }
    
    // Build the charge type string based on available data
    $charge_parts = [];
    
    // Add charge_to description
    if (!empty($charge_to)) {
        if (strtoupper($charge_to) === 'PARTNER') {
            $charge_parts[] = 'CHARGE BY PARTNER';
        } elseif (strtoupper($charge_to) === 'CUSTOMER') {
            $charge_parts[] = 'CHARGE BY CUSTOMER';
        } else {
            $charge_parts[] = strtoupper($charge_to);
        }
    }
    
    // Add serviceCharge description
    if (!empty($serviceCharge)) {
        $charge_parts[] = strtoupper($serviceCharge);
    }
    
    return !empty($charge_parts) ? implode(' ', $charge_parts) : 'N/A';
}

// ============================================
// FUNCTION TO CALCULATE VARIANCE
// ============================================
function calculateVariance($net_value, $settlement_value) {
    return $net_value - $settlement_value;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Volume Report | <?php if($_SESSION['user_type'] === 'admin' || $_SESSION['user_type'] === 'user') echo ucfirst($_SESSION['user_type']); else echo "Guest";?></title>
    <link rel="stylesheet" href="../../../assets/css/templates/style.css?v=<?php echo time(); ?>">
    <script src="https://kit.fontawesome.com/30b908cc5a.js" crossorigin="anonymous"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="../../../assets/js/sweetalert2.all.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet" />
    <link rel="icon" href="../../../images/MLW logo.png" type="image/png">
    <link rel="stylesheet" href="css/volume.css?v=<?= time(); ?>">

    <style>
        /* Variance column styling */
        .variance-negative {
            color: #d93025;
            font-weight: bold;
        }
        .variance-positive {
            color: #1e8e3e;
            font-weight: bold;
        }
        .variance-zero {
            color: #666;
        }
        .variance-header {
            background: #f3e5f5 !important;
            color: #6a1b9a !important;
        }
        .variance-cell {
            background: #f3e5f5;
        }
        /* Charge Type styling */
        .charge-type-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .charge-type-partner {
            background: #e3f2fd;
           
        }
        .charge-type-customer {
            background: #f3e5f5;
            color: #6a1b9a;
        }
        .charge-type-daily {
            background: #e8f5e9;
            color: #2e7d32;
        }
        .charge-type-weekly {
            background: #fff3e0;
            color: #e65100;
        }
        .charge-type-monthly {
            background: #fce4ec;
            color: #c62828;
        }
        .charge-type-default {
            background: #f5f5f5;
            color: #616161;
        }
        /* Charge column headers */
        .charge-partner-header {
            background: #e3f2fd !important;
            color: #1565c0 !important;
        }
        .charge-customer-header {
            background: #f3e5f5 !important;
            color: #6a1b9a !important;
        }
        .charge-total-header {
            background: #e8f5e9 !important;
            color: #2e7d32 !important;
        }
        .charge-partner-cell {
            background: #e3f2fd;
        }
        .charge-customer-cell {
            background: #f3e5f5;
        }
        .charge-total-cell {
            background: #e8f5e9;
        }
        /* Expandable partner breakdown */
        .partner-main-row:hover {
            background: #f0f7ff;
        }
        .expand-chevron.expanded {
            transform: rotate(90deg);
        }
        .partner-detail-row td {
            border-top: none !important;
            font-size: 13px;
        }
        .partner-detail-row:hover {
            background: #eef2f7 !important;
        }
    </style>
</head>
<body>
    <div class="main-container">
        <?php include '../../../templates/header_ui.php'; ?>
        <?php include '../../../templates/sidebar.php'; ?>
        
        <!-- Loading Overlay -->
        <div id="loading-overlay">
            <div class="loading-spinner"></div>
            <div class="loading-text">Loading Report...</div>
            <div class="loading-subtext">Please wait while we process your request</div>
            <div class="loading-progress">
                <div class="loading-progress-bar"></div>
            </div>
        </div>
        
        <div class="bp-section-header" role="region" aria-label="Page title">
            <div class="bp-section-title">
                <i class="fa-solid fa-layer-group" aria-hidden="true"></i>
                <div>
                    <h2>Volume Report</h2>
                    <!-- <p class="bp-section-sub">Summary of transaction volumes by partner and period</p> -->
                </div>
            </div>
        </div>

        <!-- Filter Form -->
        <div class="filter-container">
            <form method="POST" action="" class="filter-form" id="filterForm">
                <div class="form-group">
                    <label for="partner_id">Partners Name</label>
                    <select id="partner_id" name="partner_id" class="partner-select">
                        <option value="">All Partners</option>
                        <?php
                        $partners_query = "SELECT partner_id_kpx, partner_name FROM masterdata.partner_masterfile ORDER BY partner_name";
                        $partners_result = mysqli_query($conn, $partners_query);
                        while ($partner = mysqli_fetch_assoc($partners_result)) {
                            $selected = ($partner['partner_id_kpx'] == $partner_id) ? 'selected' : '';
                            echo "<option value='" . htmlspecialchars($partner['partner_id_kpx']) . "' $selected>" . htmlspecialchars($partner['partner_name']) . "</option>";
                        }
                        ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="time_frame">Time Frame</label>
                    <select id="time_frame" name="time_frame" onchange="toggleDateFields()">
                        <option value="daily" <?php echo $time_frame == 'daily' ? 'selected' : ''; ?>>Daily</option>
                        <option value="date_range" <?php echo $time_frame == 'date_range' ? 'selected' : ''; ?>>Date Range</option>
                        <option value="monthly" <?php echo $time_frame == 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                    </select>
                </div>

                <!-- FIX: Daily Date Group - Changed name to date_from_daily -->
                <div class="form-group" id="daily_date_group" style="display: <?php echo $time_frame == 'daily' ? 'flex' : 'none'; ?>;">
                    <label for="date_from_daily">Date</label>
                    <input type="date" id="date_from_daily" name="date_from_daily" value="<?php echo ($time_frame == 'daily') ? $date_from : date('Y-m-d'); ?>">
                </div>

                <!-- Date Range Group - Start Date -->
                <div class="form-group" id="date_range_group" style="display: <?php echo $time_frame == 'date_range' ? 'flex' : 'none'; ?>;">
                    <label for="date_from_range">Transaction Date From</label>
                    <input type="date" id="date_from_range" name="date_from" value="<?php echo ($time_frame == 'date_range') ? $date_from : date('Y-m-d'); ?>">
                </div>

                <!-- Date Range Group - End Date -->
                <div class="form-group" id="date_to_group" style="display: <?php echo $time_frame == 'date_range' ? 'flex' : 'none'; ?>;">
                    <label for="date_to">Transaction End Date</label>
                    <input type="date" id="date_to" name="date_to" value="<?php echo ($time_frame == 'date_range') ? $date_to : date('Y-m-d'); ?>">
                </div>

                <!-- Monthly Group - Month From -->
                <div class="form-group" id="month_from_group" style="display: <?php echo $time_frame == 'monthly' ? 'flex' : 'none'; ?>;">
                    <label for="month_from">Transaction Month From</label>
                    <input type="month" id="month_from" name="month_from" value="<?php echo $month_from; ?>">
                </div>

                <!-- Monthly Group - Month To -->
                <div class="form-group" id="month_to_group" style="display: <?php echo $time_frame == 'monthly' ? 'flex' : 'none'; ?>;">
                    <label for="month_to">Transaction Month To</label>
                    <input type="month" id="month_to" name="month_to" value="<?php echo $month_to; ?>">
                </div>

                <div class="form-group" style="justify-content: flex-end;">
                    <div class="btn-group">
                        <button type="submit" name="generate_report" class="btn-generate" id="generateBtn">
                            <i class="fa-solid fa-file-lines"></i> Generate
                        </button>
                        <button type="button" class="btn-export" onclick="exportReport()">
                            <i class="fa-solid fa-file-export"></i> Export to Excel
                        </button>
                        <button type="button" class="btn-debug" onclick="debugReport()">
                            <i class="fa-solid fa-bug"></i> Debug Report
                        </button>
                        <a href="volume-report.php" class="btn-reset"><i class="fa-solid fa-rotate"></i> Clear</a>
                    </div>
                </div>
            </form>
        </div>

        <!-- Results Section -->
        <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_report'])): ?>
            <!-- Results Table (day/month filter buttons removed — use expandable chevron rows for breakdown) -->
            <div class="results-container" id="resultsContainer">
                <?php if ($results && mysqli_num_rows($results) > 0): 
                    $total_records = mysqli_num_rows($results);
                    $total_datetime_volume = 0;
                    $total_datetime_amount = 0;
                    $total_datetime_charge_partner = 0;
                    $total_datetime_charge_customer = 0;
                    $total_datetime_charge_total = 0;
                    $total_cancellation_volume = 0;
                    $total_cancellation_amount = 0;
                    $total_cancellation_charge_partner = 0;
                    $total_cancellation_charge_customer = 0;
                    $total_cancellation_charge_total = 0;
                    $total_volume = 0;
                    $total_amount = 0;
                    $total_charge_partner = 0;
                    $total_charge_customer = 0;
                    $total_charge = 0;
                    $total_settlement_volume = 0;
                    $total_settlement_amount = 0;
                    $total_settlement_charge_partner = 0;
                    $total_settlement_charge_customer = 0;
                    $total_settlement_charge = 0;
                    $total_variance_volume = 0;
                    $total_variance_amount = 0;
                    
                    $display_results = [];
                    while ($row = mysqli_fetch_assoc($results)) {
                        // Calculate variance for this row
                        $row['variance_volume'] = calculateVariance($row['total_volume'], $row['settlement_volume']);
                        
                        // Check charge type for variance calculation
                        $charge_to = $row['charge_to'] ?? '';
                        $serviceCharge = $row['serviceCharge'] ?? '';
                        $is_partner_charge = (strtoupper($charge_to) === 'PARTNER');
                        $is_customer_daily = (strtoupper($charge_to) === 'CUSTOMER' && strtoupper($serviceCharge) === 'DAILY');
                        $is_customer_non_daily = (strtoupper($charge_to) === 'CUSTOMER' && strtoupper($serviceCharge) !== 'DAILY');
                        $is_both = (strtoupper($charge_to) === 'BOTH');
                        
                        if ($is_partner_charge || $is_customer_non_daily) {
                            // CHARGE BY PARTNER or CHARGE BY CUSTOMER (WEEKLY/MONTHLY/SEMI-MONTHLY/other):
                            // Settlement Amount = full amount_paid → variance = Net Amount - Settlement Amount (should be 0)
                            $row['variance_amount'] = $row['total_amount_paid'] - $row['settlement_amount_paid'];
                        } elseif ($is_customer_daily) {
                            // CHARGE BY CUSTOMER DAILY: variance = Net Amount - Settlement Amount - Settlement Charge to Partner
                            $row['variance_amount'] = $row['total_amount_paid'] - ($row['settlement_amount_paid'] + $row['settlement_charge_partner']);
                        } elseif ($is_both) {
                            // BOTH: variance = Net Amount - Settlement Amount - Total Settlement Charge
                            $row['variance_amount'] = $row['total_amount_paid'] - ($row['settlement_amount_paid'] + $row['settlement_charge']);
                        } else {
                            // Fallback: variance = Net Amount - Settlement Amount - Total Settlement Charge
                            $row['variance_amount'] = $row['total_amount_paid'] - ($row['settlement_amount_paid'] + $row['settlement_charge']);
                        }
                        
                        // Get charge type display
                        $row['charge_type_display'] = getChargeTypeDisplay($row['serviceCharge'] ?? '', $row['charge_to'] ?? '');
                        
                        $display_results[] = $row;
                        $total_datetime_volume += $row['datetime_volume'];
                        $total_datetime_amount += $row['datetime_amount_paid'];
                        $total_datetime_charge_partner += $row['datetime_charge_partner'];
                        $total_datetime_charge_customer += $row['datetime_charge_customer'];
                        $total_datetime_charge_total += $row['datetime_charge_total'];
                        $total_cancellation_volume += $row['cancellation_volume'];
                        $total_cancellation_amount += $row['cancellation_amount_paid'];
                        $total_cancellation_charge_partner += $row['cancellation_charge_partner'];
                        $total_cancellation_charge_customer += $row['cancellation_charge_customer'];
                        $total_cancellation_charge_total += $row['cancellation_charge_total'];
                        // FIX: These are now NET values (datetime - cancellation)
                        $total_volume += $row['total_volume'];
                        $total_amount += $row['total_amount_paid'];
                        $total_charge_partner += $row['total_charge_partner'];
                        $total_charge_customer += $row['total_charge_customer'];
                        $total_charge += $row['total_charge'];
                        // Settlement totals
                        $total_settlement_volume += $row['settlement_volume'];
                        $total_settlement_amount += $row['settlement_amount_paid'];
                        $total_settlement_charge_partner += $row['settlement_charge_partner'];
                        $total_settlement_charge_customer += $row['settlement_charge_customer'];
                        $total_settlement_charge += $row['settlement_charge'];
                        // Variance totals
                        $total_variance_volume += $row['variance_volume'];
                        $total_variance_amount += $row['variance_amount'];
                    }
                ?>
                    <!-- Summary Statistics -->
                    <div class="summary-stats">
                        <div class="stat-card">
                            <div class="stat-value"><?php echo number_format($total_records); ?></div>
                            <div class="stat-label">Total Records</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value"><?php echo number_format($total_volume); ?></div>
                            <div class="stat-label">Net Volume</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value"><?php echo number_format($total_amount, 2); ?></div>
                            <div class="stat-label">Net Amount</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value"><?php echo number_format($total_charge, 2); ?></div>
                            <div class="stat-label">Net Charge</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value" style="color: #6a1b9a;"><?php echo number_format($total_variance_volume); ?></div>
                            <div class="stat-label">Variance Volume</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value" style="color: #6a1b9a;"><?php echo number_format($total_variance_amount, 2); ?></div>
                            <div class="stat-label">Variance Amount</div>
                        </div>
                    </div>

                    <!-- Table with Scroll -->
                    <div class="table-wrapper">
                        <table class="results-table">
                            <thead>
                                <tr>
                                    <th rowspan="3" style="min-width: 40px;"></th>
                                    <th rowspan="3" style="min-width: 50px;">No.</th>
                                    <th rowspan="3" style="min-width: 180px;">Partner Name</th>
                                    <th rowspan="3" style="min-width: 140px;">Charge Type</th>
                                    <th rowspan="3" style="min-width: 160px;">Biller's Name</th>
                                    <th colspan="4" style="background: #e6f4ea; color: #000000;">Transaction</th>
                                    <th colspan="4" style="background: #fce8e6; color: #000000;">Cancelled Transaction</th>
                                    <th colspan="4" style="background: #e8f0fe; color: #000000;">NET</th>
                                    <th colspan="4" style="background: #fff3cd; color: #000000;">Settlement</th>
                                    <th colspan="2" style="background: #f3e5f5; color: #000000;">Variance</th>
                                </tr>
                                <tr>
                                    <th colspan="2"></th>
                                    <th colspan="2">₱ Charge</th>
                                    <th colspan="2"></th>
                                    <th colspan="2">₱ Charge</th>
                                    <th colspan="2"></th>
                                    <th colspan="2">₱ Charge</th>
                                    <th colspan="2"></th>
                                    <th colspan="2">₱ Charge</th>
                                    <th colspan="2"></th>
                                </tr>
                                <tr>
                                    <th>Vol.</th>
                                    <th>₱ Amount</th>
                                    <th>Partner</th>
                                    <th>Customer</th>
                                    <th>Vol.</th>
                                    <th>₱ Amount</th>
                                    <th>Partner</th>
                                    <th>Customer</th>
                                    <th>Vol.</th>
                                    <th>₱ Amount</th>
                                    <th>Partner</th>
                                    <th>Customer</th>
                                    <th>Vol.</th>
                                    <th>₱ Amount</th>
                                    <th>Partner</th>
                                    <th>Customer</th>
                                    <th>Vol.</th>
                                    <th>₱ Amount</th>
                                </tr>

                            </thead>
                            <tbody>
                                <?php 
                                $counter = 1;
                                $show_breakdown = ($time_frame === 'date_range' || $time_frame === 'monthly');
                                
                                foreach ($display_results as $row): 
                                    // ============================================
                                    // FIX: Clean partner name display
                                    // ============================================
                                    $partner_name = cleanPartnerName($row['partner_id_kpx'], $row['sub_billers_name']);
                                    
                                    // Check if this is an unassigned partner
                                    $is_unassigned = (strpos($row['partner_id_kpx'], 'UNKNOWN_') === 0);
                                    
                                    // Get the sub_billers_name for display
                                    $display_sub_biller = $row['sub_billers_name'] ?? '-';
                                    if ($is_unassigned && $display_sub_biller === '-') {
                                        $display_sub_biller = 'Unassigned Partner Transaction';
                                    }
                                    
                                    // Calculate variance values
                                    $variance_volume = $row['variance_volume'];
                                    $variance_amount = $row['variance_amount'];
                                    
                                    // Determine variance styling
                                    $volume_class = '';
                                    $amount_class = '';
                                    if ($variance_volume > 0) {
                                        $volume_class = 'variance-positive';
                                    } elseif ($variance_volume < 0) {
                                        $volume_class = 'variance-negative';
                                    } else {
                                        $volume_class = 'variance-zero';
                                    }
                                    
                                    if ($variance_amount > 0) {
                                        $amount_class = 'variance-positive';
                                    } elseif ($variance_amount < 0) {
                                        $amount_class = 'variance-negative';
                                    } else {
                                        $amount_class = 'variance-zero';
                                    }
                                    
                                    // Get charge type display
                                    $charge_type_display = $row['charge_type_display'];
                                    
                                    // Determine charge type CSS class for styling
                                    $charge_type_class = 'charge-type-default';
                                    if (stripos($charge_type_display, 'PARTNER') !== false) {
                                        $charge_type_class = 'charge-type-partner';
                                    } elseif (stripos($charge_type_display, 'CUSTOMER') !== false) {
                                        $charge_type_class = 'charge-type-customer';
                                    }
                                    
                                    if (stripos($charge_type_display, 'DAILY') !== false) {
                                        $charge_type_class .= ' charge-type-daily';
                                    } elseif (stripos($charge_type_display, 'WEEKLY') !== false) {
                                        $charge_type_class .= ' charge-type-weekly';
                                    } elseif (stripos($charge_type_display, 'MONTHLY') !== false) {
                                        $charge_type_class .= ' charge-type-monthly';
                                    }
                                    
                                    $pkey = $row['partner_id_kpx'] . '|' . ($row['sub_billers_name'] ?? '-');
                                    $period_details = $partner_period_details[$pkey] ?? [];
                                    $has_details = $show_breakdown && !empty($period_details);
                                    $row_id = 'partner-row-' . $counter;
                                ?>
                                    <tr class="partner-main-row" data-row-id="<?php echo $row_id; ?>">
                                        <td style="text-align: center; cursor: <?php echo $has_details ? 'pointer' : 'default'; ?>;" <?php if ($has_details): ?>onclick="togglePartnerBreakdown('<?php echo $row_id; ?>')"<?php endif; ?>>
                                            <?php if ($has_details): ?>
                                                <i class="fa-solid fa-chevron-right expand-chevron" id="chevron-<?php echo $row_id; ?>" style="transition: transform 0.2s; color: #1a73e8;"></i>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $counter++; ?></td>
                                        <td style="text-align: left; padding-left: 15px; <?php echo $is_unassigned ? 'color: #d93025; font-style: italic;' : ''; ?>">
                                            <?php echo htmlspecialchars($partner_name); ?>
                                            <?php if ($is_unassigned): ?>
                                                <span style="font-size: 10px; color: #d93025; display: block;">(No Partner ID)</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: center;">
                                            <span class="charge-type-badge <?php echo $charge_type_class; ?>">
                                                <?php echo htmlspecialchars($charge_type_display); ?>
                                            </span>
                                        </td>
                                        <td style="text-align: left; padding-left: 15px;"><?php echo htmlspecialchars($display_sub_biller); ?></td>
                                        <!-- Transaction -->
                                        <td><?php echo number_format($row['datetime_volume']); ?></td>
                                        <td style="text-align: right;"><?php echo number_format($row['datetime_amount_paid'], 2); ?></td>
                                        <td><?php echo number_format($row['datetime_charge_partner'], 2); ?></td>
                                        <td><?php echo number_format($row['datetime_charge_customer'], 2); ?></td>
                                        <!-- Cancelled Transaction -->
                                        <td><?php echo number_format($row['cancellation_volume']); ?></td>
                                        <td style="text-align: right;"><?php echo number_format(abs($row['cancellation_amount_paid']), 2); ?></td>
                                        <td><?php echo number_format(abs($row['cancellation_charge_partner']), 2); ?></td>
                                        <td><?php echo number_format(abs($row['cancellation_charge_customer']), 2); ?></td>
                                        <!-- NET -->
                                        <td><strong><?php echo number_format($row['total_volume']); ?></strong></td>
                                        <td><strong><?php echo number_format($row['total_amount_paid'], 2); ?></strong></td>
                                        <td><strong><?php echo number_format($row['total_charge_partner'], 2); ?></strong></td>
                                        <td><strong><?php echo number_format($row['total_charge_customer'], 2); ?></strong></td>
                                        <!-- Settlement -->
                                        <td><?php echo number_format($row['settlement_volume']); ?></td>
                                        <td><?php echo number_format($row['settlement_amount_paid'], 2); ?></td>
                                        <td><?php echo number_format($row['settlement_charge_partner'], 2); ?></td>
                                        <td><?php echo number_format($row['settlement_charge_customer'], 2); ?></td>
                                        <!-- Variance -->
                                        <td class="<?php echo $volume_class; ?>">
                                            <?php echo number_format($variance_volume); ?>
                                        </td>
                                        <td class="<?php echo $amount_class; ?>">
                                            <?php echo number_format($variance_amount, 2); ?>
                                        </td>
                                    </tr>
                                    <?php if ($has_details): 
                                        // Sort period keys chronologically
                                        ksort($period_details);
                                        foreach ($period_details as $period_key => $drow):
                                            $period_label = $period_key;
                                            if ($time_frame === 'date_range') {
                                                $period_label = date('M j, Y (D)', strtotime($period_key));
                                            } elseif ($time_frame === 'monthly') {
                                                $period_label = date('M Y', strtotime($period_key . '-01'));
                                            }
                                            
                                            // Calculate variance for detail row
                                            $dv_vol = ($drow['total_volume'] ?? 0) - ($drow['settlement_volume'] ?? 0);
                                            
                                            // Check charge type for variance calculation
                                            $d_charge_to = $drow['charge_to'] ?? '';
                                            $d_serviceCharge = $drow['serviceCharge'] ?? '';
                                            $d_is_partner_charge = (strtoupper($d_charge_to) === 'PARTNER');
                                            $d_is_customer_daily = (strtoupper($d_charge_to) === 'CUSTOMER' && strtoupper($d_serviceCharge) === 'DAILY');
                                            $d_is_customer_non_daily = (strtoupper($d_charge_to) === 'CUSTOMER' && strtoupper($d_serviceCharge) !== 'DAILY');
                                            $d_is_both = (strtoupper($d_charge_to) === 'BOTH');
                                            
                                            if ($d_is_partner_charge || $d_is_customer_non_daily) {
                                                // CHARGE BY PARTNER or CHARGE BY CUSTOMER (WEEKLY/MONTHLY/SEMI-MONTHLY/other):
                                                // Settlement Amount = full amount_paid → variance = Net Amount - Settlement Amount (should be 0)
                                                $dv_amt = ($drow['total_amount_paid'] ?? 0) - ($drow['settlement_amount_paid'] ?? 0);
                                            } elseif ($d_is_customer_daily) {
                                                // CHARGE BY CUSTOMER DAILY: variance = Net Amount - Settlement Amount - Settlement Charge to Partner
                                                $dv_amt = ($drow['total_amount_paid'] ?? 0) - (($drow['settlement_amount_paid'] ?? 0) + ($drow['settlement_charge_partner'] ?? 0));
                                            } elseif ($d_is_both) {
                                                // BOTH: variance = Net Amount - Settlement Amount - Total Settlement Charge
                                                $dv_amt = ($drow['total_amount_paid'] ?? 0) - (($drow['settlement_amount_paid'] ?? 0) + ($drow['settlement_charge'] ?? 0));
                                            } else {
                                                // Fallback: variance = Net Amount - Settlement Amount - Total Settlement Charge
                                                $dv_amt = ($drow['total_amount_paid'] ?? 0) - (($drow['settlement_amount_paid'] ?? 0) + ($drow['settlement_charge'] ?? 0));
                                            }
                                            
                                            $dv_vol_class = $dv_vol > 0 ? 'variance-positive' : ($dv_vol < 0 ? 'variance-negative' : 'variance-zero');
                                            $dv_amt_class = $dv_amt > 0 ? 'variance-positive' : ($dv_amt < 0 ? 'variance-negative' : 'variance-zero');
                                    ?>
                                    <tr class="partner-detail-row detail-of-<?php echo $row_id; ?>" style="display: none; background: #f8f9fa;">
                                        <td></td>
                                        <td></td>
                                        <td style="text-align: left; padding-left: 30px; font-size: 13px; color: #555;">
                                            <i class="fa-solid fa-calendar-day" style="margin-right: 6px; color: #1a73e8;"></i>
                                            <?php echo htmlspecialchars($period_label); ?>
                                        </td>
                                        <td style="text-align: center; font-size: 12px; color: #888;">—</td>
                                        <td style="text-align: left; padding-left: 15px; font-size: 12px; color: #888;">—</td>
                                        <!-- Transaction -->
                                        <td><?php echo number_format($drow['datetime_volume'] ?? 0); ?></td>
                                        <td style="text-align: right;"><?php echo number_format($drow['datetime_amount_paid'] ?? 0, 2); ?></td>
                                        <td><?php echo number_format($drow['datetime_charge_partner'] ?? 0, 2); ?></td>
                                        <td><?php echo number_format($drow['datetime_charge_customer'] ?? 0, 2); ?></td>
                                        <!-- Cancelled Transaction -->
                                        <td><?php echo number_format($drow['cancellation_volume'] ?? 0); ?></td>
                                        <td style="text-align: right;"><?php echo number_format(abs($drow['cancellation_amount_paid'] ?? 0), 2); ?></td>
                                        <td><?php echo number_format(abs($drow['cancellation_charge_partner'] ?? 0), 2); ?></td>
                                        <td><?php echo number_format(abs($drow['cancellation_charge_customer'] ?? 0), 2); ?></td>
                                        <!-- NET -->
                                        <td><strong><?php echo number_format($drow['total_volume'] ?? 0); ?></strong></td>
                                        <td><strong><?php echo number_format($drow['total_amount_paid'] ?? 0, 2); ?></strong></td>
                                        <td><strong><?php echo number_format($drow['total_charge_partner'] ?? 0, 2); ?></strong></td>
                                        <td><strong><?php echo number_format($drow['total_charge_customer'] ?? 0, 2); ?></strong></td>
                                        <!-- Settlement -->
                                        <td><?php echo number_format($drow['settlement_volume'] ?? 0); ?></td>
                                        <td><?php echo number_format($drow['settlement_amount_paid'] ?? 0, 2); ?></td>
                                        <td><?php echo number_format($drow['settlement_charge_partner'] ?? 0, 2); ?></td>
                                        <td><?php echo number_format($drow['settlement_charge_customer'] ?? 0, 2); ?></td>
                                        <!-- Variance -->
                                        <td class="<?php echo $dv_vol_class; ?>">
                                            <?php echo number_format($dv_vol); ?>
                                        </td>
                                        <td class="<?php echo $dv_amt_class; ?>">
                                            <?php echo number_format($dv_amt, 2); ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; endif; ?>
                                <?php endforeach; ?>
                                
                                <!-- FIX: TOTAL now uses NET values -->
                                <tr class="grand-total">
                                    <td colspan="5" style="text-align: right; padding-right: 20px;">TOTAL</td>
                                    <td><?php echo number_format($total_datetime_volume); ?></td>
                                    <td style="text-align: right;"><?php echo number_format($total_datetime_amount, 2); ?></td>
                                    <td><?php echo number_format($total_datetime_charge_partner, 2); ?></td>
                                    <td><?php echo number_format($total_datetime_charge_customer, 2); ?></td>
                                    <!-- Cancellation totals -->
                                    <td><?php echo number_format($total_cancellation_volume); ?></td>
                                    <td style="text-align: right;"><?php echo number_format(abs($total_cancellation_amount), 2); ?></td>
                                    <td><?php echo number_format(abs($total_cancellation_charge_partner), 2); ?></td>
                                    <td><?php echo number_format(abs($total_cancellation_charge_customer), 2); ?></td>
                                    <!-- NET totals -->
                                    <td><strong><?php echo number_format($total_volume); ?></strong></td>
                                    <td><strong><?php echo number_format($total_amount, 2); ?></strong></td>
                                    <td><strong><?php echo number_format($total_charge_partner, 2); ?></strong></td>
                                    <td><strong><?php echo number_format($total_charge_customer, 2); ?></strong></td>
                                    <!-- Settlement totals -->
                                    <td><strong><?php echo number_format($total_settlement_volume); ?></strong></td>
                                    <td><strong><?php echo number_format($total_settlement_amount, 2); ?></strong></td>
                                    <td><strong><?php echo number_format($total_settlement_charge_partner, 2); ?></strong></td>
                                    <td><strong><?php echo number_format($total_settlement_charge_customer, 2); ?></strong></td>
                                    <!-- Variance totals -->
                                    <td>
                                        <strong><?php echo number_format($total_variance_volume); ?></strong>
                                    </td>
                                    <td>
                                        <strong><?php echo number_format($total_variance_amount, 2); ?></strong>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="no-results">
                        <i class="fa-solid fa-inbox"></i>
                        <h3>No Results Found</h3>
                        <p>No transactions found for the selected criteria. Please adjust your filters and try again.</p>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
        $(document).ready(function() {
            $('.partner-select').select2({
                placeholder: 'Search for a partner...',
                allowClear: true,
                width: '100%'
            });
            toggleDateFields();
            
            // Hide loading overlay if it's showing and page is fully loaded
            if (document.readyState === 'complete') {
                hideLoading();
            }
            
            // ============================================
            // ATTACH LOADING TO DAY AND MONTH FILTER FORMS
            // ============================================
            // Day filter forms
            $('.filter-day-form').on('submit', function() {
                showLoading('Filtering by Day...');
                return true;
            });
            
            // Month filter forms
            $('.filter-month-form').on('submit', function() {
                showLoading('Filtering by Month...');
                return true;
            });
        });

        // ============================================
        // LOADING OVERLAY FUNCTIONS
        // ============================================
        function showLoading(message = 'Loading Report...') {
            const overlay = document.getElementById('loading-overlay');
            if (overlay) {
                const textElement = overlay.querySelector('.loading-text');
                if (textElement) {
                    textElement.textContent = message;
                }
                overlay.classList.add('active');
                document.body.classList.add('loading');
                
                // Reset progress bar animation
                const progressBar = overlay.querySelector('.loading-progress-bar');
                if (progressBar) {
                    progressBar.style.animation = 'none';
                    // Trigger reflow
                    void progressBar.offsetWidth;
                    progressBar.style.animation = 'progress 2s ease-in-out infinite';
                }
            }
        }

        function hideLoading() {
            const overlay = document.getElementById('loading-overlay');
            if (overlay) {
                overlay.classList.remove('active');
                document.body.classList.remove('loading');
            }
        }

        // ============================================
        // HANDLE PAGE REFRESH - Show loading on refresh
        // ============================================
        // This will show loading when the page is being refreshed
        window.addEventListener('beforeunload', function(e) {
            // Only show if we have results or filters applied
            const hasResults = document.querySelector('.results-container');
            const hasFilters = document.querySelector('.filter-container');
            
            if (hasResults || hasFilters) {
                // We can't show the overlay on beforeunload reliably,
                // but we can set a flag to show it when the page loads
                sessionStorage.setItem('pageRefreshing', 'true');
            }
        });

        // ============================================
        // FORM SUBMISSION WITH LOADING OVERLAY
        // ============================================
        document.addEventListener('DOMContentLoaded', function() {
            const filterForm = document.getElementById('filterForm');
            if (filterForm) {
                filterForm.addEventListener('submit', function(e) {
                    const submitButton = document.activeElement;
                    // Only show loading if Generate button is clicked
                    if (submitButton && submitButton.name === 'generate_report') {
                        const timeFrame = document.getElementById('time_frame').value;
                        let loadingMessage = 'Generating Report...';
                        
                        switch(timeFrame) {
                            case 'daily':
                                loadingMessage = 'Loading Daily Report...';
                                break;
                            case 'date_range':
                                loadingMessage = 'Loading Date Range Report...';
                                break;
                            case 'monthly':
                                loadingMessage = 'Loading Monthly Report...';
                                break;
                            default:
                                loadingMessage = 'Generating Report...';
                        }
                        
                        showLoading(loadingMessage);
                        
                        // Safety timeout to hide loading if something goes wrong
                        if (window.loadingTimeout) {
                            clearTimeout(window.loadingTimeout);
                        }
                        window.loadingTimeout = setTimeout(function() {
                            hideLoading();
                            // Show error message if still loading
                            Swal.fire({
                                icon: 'warning',
                                title: 'Loading Timeout',
                                text: 'The report is taking longer than expected. Please try again.',
                                confirmButtonColor: '#1a73e8'
                            });
                        }, 60000); // 60 seconds max
                    }
                });
            }

            // Hide loading when page is fully loaded
            window.addEventListener('load', function() {
                setTimeout(function() {
                    hideLoading();
                }, 500);
            });

            // Also hide loading when results are displayed
            if (document.querySelector('.results-container') || document.querySelector('.no-results')) {
                setTimeout(function() {
                    hideLoading();
                }, 300);
            }
        });

        // ============================================
        // TOGGLE DATE FIELDS
        // ============================================
        function toggleDateFields() {
            const timeFrame = document.getElementById('time_frame').value;
            
            // Hide all groups first
            document.getElementById('daily_date_group').style.display = 'none';
            document.getElementById('date_range_group').style.display = 'none';
            document.getElementById('date_to_group').style.display = 'none';
            document.getElementById('month_from_group').style.display = 'none';
            document.getElementById('month_to_group').style.display = 'none';
            
            // Show appropriate groups based on time frame
            if (timeFrame === 'daily') {
                document.getElementById('daily_date_group').style.display = 'flex';
            } else if (timeFrame === 'date_range') {
                document.getElementById('date_range_group').style.display = 'flex';
                document.getElementById('date_to_group').style.display = 'flex';
            } else if (timeFrame === 'monthly') {
                document.getElementById('month_from_group').style.display = 'flex';
                document.getElementById('month_to_group').style.display = 'flex';
            }
        }

        // Toggle per-partner day/month breakdown rows
        function togglePartnerBreakdown(rowId) {
            const detailRows = document.querySelectorAll('.detail-of-' + rowId);
            const chevron = document.getElementById('chevron-' + rowId);
            if (!detailRows.length) return;
            
            const isHidden = detailRows[0].style.display === 'none' || detailRows[0].style.display === '';
            detailRows.forEach(function(r) {
                r.style.display = isHidden ? 'table-row' : 'none';
            });
            if (chevron) {
                if (isHidden) {
                    chevron.classList.add('expanded');
                } else {
                    chevron.classList.remove('expanded');
                }
            }
        }

        // ============================================
        // DAY AND MONTH SELECTORS
        // ============================================
        function setSelectedDay(day) {
            document.getElementById('selected_day_input').value = day;
            // Show loading will be triggered by the form submit event
        }

        function setSelectedMonth(month) {
            document.getElementById('selected_month_input').value = month;
            // Show loading will be triggered by the form submit event
        }

        // ============================================
        // FORM VALIDATION
        // ============================================
        document.getElementById('filterForm').addEventListener('submit', function(e) {
            const timeFrame = document.getElementById('time_frame').value;
            let isValid = true;
            let errorMessage = '';

            if (timeFrame === 'daily') {
                const date = document.getElementById('date_from_daily').value;
                if (!date) {
                    isValid = false;
                    errorMessage = 'Please select a date.';
                }
            } else if (timeFrame === 'date_range') {
                const dateFrom = document.getElementById('date_from_range').value;
                const dateTo = document.getElementById('date_to').value;
                if (!dateFrom || !dateTo) {
                    isValid = false;
                    errorMessage = 'Please select both start date and end date.';
                } else if (dateFrom > dateTo) {
                    isValid = false;
                    errorMessage = 'Start Date cannot be later than End Date.';
                }
            } else if (timeFrame === 'monthly') {
                const monthFrom = document.getElementById('month_from').value;
                const monthTo = document.getElementById('month_to').value;
                if (!monthFrom || !monthTo) {
                    isValid = false;
                    errorMessage = 'Please select both month from and month to.';
                } else if (monthFrom > monthTo) {
                    isValid = false;
                    errorMessage = 'Month From cannot be later than Month To.';
                }
            }

            if (!isValid) {
                e.preventDefault();
                hideLoading(); // Hide loading if validation fails
                Swal.fire({
                    icon: 'warning',
                    title: 'Validation Error',
                    text: errorMessage,
                    confirmButtonColor: '#1a73e8'
                });
            }
        });

        // ============================================
        // EXPORT REPORT
        // ============================================
        function exportReport() {
            // Get the current filter values from the form
            const partnerId = document.getElementById('partner_id').value || '';
            const timeFrame = document.getElementById('time_frame').value;
            
            let dateFrom = '';
            let dateTo = '';
            let monthFrom = '';
            let monthTo = '';
            let dateFromDaily = '';
            let selectedDay = document.getElementById('selected_day_input')?.value || 'all';
            let selectedMonth = document.getElementById('selected_month_input')?.value || 'all';
            
            if (timeFrame === 'daily') {
                dateFromDaily = document.getElementById('date_from_daily').value || '';
            } else if (timeFrame === 'date_range') {
                dateFrom = document.getElementById('date_from_range').value || '';
                dateTo = document.getElementById('date_to').value || '';
            } else if (timeFrame === 'monthly') {
                monthFrom = document.getElementById('month_from').value || '';
                monthTo = document.getElementById('month_to').value || '';
            }
            
            // If no data is selected, show a warning
            if (!partnerId && !dateFromDaily && !dateFrom && !monthFrom) {
                Swal.fire({
                    icon: 'warning',
                    title: 'No Data to Export',
                    text: 'Please generate a report first before exporting.',
                    confirmButtonColor: '#1a73e8'
                });
                return;
            }
            
            // Show loading for export
            showLoading('Preparing Export...');
            
            // Build the export URL with parameters
            let url = 'export_volume.php?';
            url += 'partner_id=' + encodeURIComponent(partnerId);
            url += '&time_frame=' + encodeURIComponent(timeFrame);
            url += '&date_from=' + encodeURIComponent(dateFrom);
            url += '&date_to=' + encodeURIComponent(dateTo);
            url += '&month_from=' + encodeURIComponent(monthFrom);
            url += '&month_to=' + encodeURIComponent(monthTo);
            url += '&date_from_daily=' + encodeURIComponent(dateFromDaily);
            url += '&selected_day=' + encodeURIComponent(selectedDay);
            url += '&selected_month=' + encodeURIComponent(selectedMonth);
            
            // Open the export in a new window/tab
            const exportWindow = window.open(url, '_blank');
            
            // Hide loading after a short delay
            setTimeout(() => {
                hideLoading();
            }, 2000);
            
            // Handle cases where popup is blocked
            if (!exportWindow || exportWindow.closed || typeof exportWindow.closed == 'undefined') {
                hideLoading();
                Swal.fire({
                    icon: 'warning',
                    title: 'Popup Blocked',
                    text: 'Please allow popups for this site or click the link below to download.',
                    confirmButtonColor: '#1a73e8',
                    footer: `<a href="${url}" target="_blank">Click here to download if the file doesn\'t start automatically</a>`
                });
            }
        }

        // ============================================
        // DEBUG REPORT
        // ============================================
        function debugReport() {
            // Get current filter values for debugging
            const partnerId = document.getElementById('partner_id').value || 'All';
            const timeFrame = document.getElementById('time_frame').value;
            let dateInfo = '';
            
            if (timeFrame === 'daily') {
                dateInfo = document.getElementById('date_from_daily').value || 'Not set';
            } else if (timeFrame === 'date_range') {
                const from = document.getElementById('date_from_range').value || 'Not set';
                const to = document.getElementById('date_to').value || 'Not set';
                dateInfo = from + ' to ' + to;
            } else if (timeFrame === 'monthly') {
                const from = document.getElementById('month_from').value || 'Not set';
                const to = document.getElementById('month_to').value || 'Not set';
                dateInfo = from + ' to ' + to;
            }
            
            Swal.fire({
                icon: 'info',
                title: 'Debug Information',
                html: `
                    <div style="text-align: left;">
                        <p><strong>Partner:</strong> ${partnerId}</p>
                        <p><strong>Time Frame:</strong> ${timeFrame}</p>
                        <p><strong>Date Range:</strong> ${dateInfo}</p>
                        <p><strong>Selected Day:</strong> ${document.getElementById('selected_day_input')?.value || 'all'}</p>
                        <p><strong>Selected Month:</strong> ${document.getElementById('selected_month_input')?.value || 'all'}</p>
                        <p><strong>User:</strong> <?php echo $current_user_email; ?></p>
                        <p><strong>PHP Version:</strong> <?php echo phpversion(); ?></p>
                    </div>
                `,
                confirmButtonColor: '#1a73e8',
                width: 600
            });
        }

        // ============================================
        // HANDLE AJAX REQUESTS (if any)
        // ============================================
        // This will hide loading if there's an AJAX error
        $(document).ajaxError(function() {
            hideLoading();
        });

        // Hide loading when page is fully loaded (additional safety)
        $(window).on('load', function() {
            setTimeout(function() {
                hideLoading();
            }, 500);
        });
        
        // ============================================
        // HANDLE RESET BUTTON
        // ============================================
        // Show loading when reset link is clicked
        document.addEventListener('DOMContentLoaded', function() {
            const resetLink = document.querySelector('.btn-reset');
            if (resetLink) {
                resetLink.addEventListener('click', function(e) {
                    showLoading('Resetting Filters...');
                    // The page will reload, so we don't need to hide
                });
            }
        });
    </script>

    <?php include '../../../templates/footer.php'; ?>
</body>
</html>