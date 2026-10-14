<?php
// volume-report.php
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

// Selected day/month for date buttons
$selected_day = $_POST['selected_day'] ?? 'all';
$selected_month = $_POST['selected_month'] ?? 'all';

$results = [];
$daily_summary = [];
$monthly_summary = [];

// ============================================
// Calculate start/end datetime ONCE for reuse
// ============================================
function calculateDateRange(
    string $time_frame,
    ?string $date_from,
    ?string $date_to,
    ?string $month_from,
    ?string $month_to,
    ?string $selected_day = null,
    ?string $selected_month = null
): array {
    $start_datetime = '';
    $end_datetime = '';

    if ($time_frame === 'daily') {
        $start_datetime = $date_from . ' 00:00:00';
        $end_datetime = $date_from . ' 23:59:59';
    } elseif ($time_frame === 'date_range') {
        if ($selected_day && $selected_day !== 'all' && $date_from) {
            $selected_date = date('Y-m-d', strtotime($date_from . ' + ' . ($selected_day - 1) . ' days'));
            $start_datetime = $selected_date . ' 00:00:00';
            $end_datetime = $selected_date . ' 23:59:59';
        } else {
            $start_datetime = $date_from . ' 00:00:00';
            $end_datetime = $date_to . ' 23:59:59';
        }
    } elseif ($time_frame === 'monthly') {
        if ($selected_month && $selected_month !== 'all' && $month_from) {
            $selected_month_date = date('Y-m', strtotime($month_from . ' + ' . ($selected_month - 1) . ' months'));
            $start_datetime = $selected_month_date . '-01 00:00:00';
            $end_datetime = date('Y-m-t 23:59:59', strtotime($selected_month_date . '-01'));
        } else {
            $start_datetime = $month_from . '-01 00:00:00';
            $end_datetime = date('Y-m-t 23:59:59', strtotime($month_to . '-01'));
        }
    }

    return [$start_datetime, $end_datetime];
}

// ============================================
// Build partner filter fragment (sargable, escaped once)
// ============================================
function buildPartnerFilter(string|int $partner_id, string $alias = 'bt'): string {
    global $conn;
    if (empty($partner_id)) {
        return '';
    }
    return " AND {$alias}.partner_id_kpx = '" . mysqli_real_escape_string($conn, $partner_id) . "'";
}

// ============================================
// Main aggregated query — split into UNION ALL
// for normal and cancelled halves so indexes can be used.
//
// FIX: add MAX(bt.partner_name) AS bt_partner_name as a fallback
// when the partner is not registered in partner_masterfile.
// ============================================
function fetchPartnerAggregates(
    string $start_datetime,
    string $end_datetime,
    string $partner_filter
): array {
    global $conn;

    // ---- NORMAL / ACTIVE transactions ----
    $normal_sql = "
        SELECT
            bt.partner_id_kpx,
            bt.sub_billers_name,
            MAX(bt.partner_name) AS bt_partner_name,
            pm.partner_name,
            pm.charge_sched,
            pm.charge_to,
            COUNT(*)                              AS normal_volume,
            SUM(bt.amount_paid)                   AS normal_amount_paid,
            SUM(bt.charge_to_partner)             AS normal_charge_partner,
            SUM(bt.charge_to_customer)            AS normal_charge_customer,
            SUM(bt.charge_to_partner + bt.charge_to_customer) AS normal_charge_total,
            COUNT(CASE WHEN bt.settle_unsettle = 'Settled' THEN 1 END) AS settlement_volume,
            SUM(CASE
                WHEN bt.settle_unsettle = 'Settled' THEN
                    CASE
                        WHEN UPPER(COALESCE(pm.charge_to, '')) = 'CUSTOMER'
                             AND UPPER(COALESCE(pm.charge_sched, '')) IN ('DAILY', 'PER TRANSACTION')
                        THEN bt.amount_paid - IFNULL(bt.charge_to_partner, 0)
                        WHEN UPPER(COALESCE(pm.charge_to, '')) = 'BOTH'
                        THEN bt.amount_paid - IFNULL(bt.charge_to_partner, 0)
                        WHEN UPPER(COALESCE(pm.charge_to, '')) = 'PARTNER'
                        THEN bt.amount_paid
                        ELSE bt.amount_paid
                    END
                ELSE 0
            END) AS settlement_amount_paid,
            SUM(CASE WHEN bt.settle_unsettle = 'Settled' THEN bt.charge_to_partner ELSE 0 END)  AS settlement_charge_partner,
            SUM(CASE WHEN bt.settle_unsettle = 'Settled' THEN bt.charge_to_customer ELSE 0 END) AS settlement_charge_customer,
            SUM(CASE WHEN bt.settle_unsettle = 'Settled' THEN (bt.charge_to_partner + bt.charge_to_customer) ELSE 0 END) AS settlement_charge
        FROM mldb.billspayment_transaction bt
        LEFT JOIN masterdata.partner_masterfile pm ON bt.partner_id_kpx = pm.partner_id_kpx
        WHERE bt.datetime BETWEEN '{$start_datetime}' AND '{$end_datetime}'
          AND (bt.status IS NULL OR bt.status = '')
          {$partner_filter}
        GROUP BY bt.partner_id_kpx, bt.sub_billers_name, pm.partner_name, pm.charge_sched, pm.charge_to
    ";

    // ---- CANCELLED transactions ----
    $cancelled_sql = "
        SELECT
            bt.partner_id_kpx,
            bt.sub_billers_name,
            MAX(bt.partner_name) AS bt_partner_name,
            pm.partner_name,
            pm.charge_sched,
            pm.charge_to,
            COUNT(*)                              AS cancel_volume,
            SUM(bt.amount_paid)                   AS cancel_amount_paid,
            SUM(bt.charge_to_partner)             AS cancel_charge_partner,
            SUM(bt.charge_to_customer)            AS cancel_charge_customer,
            SUM(bt.charge_to_partner + bt.charge_to_customer) AS cancel_charge_total
        FROM mldb.billspayment_transaction bt
        LEFT JOIN masterdata.partner_masterfile pm ON bt.partner_id_kpx = pm.partner_id_kpx
        WHERE bt.cancellation_date BETWEEN '{$start_datetime}' AND '{$end_datetime}'
          {$partner_filter}
        GROUP BY bt.partner_id_kpx, bt.sub_billers_name, pm.partner_name, pm.charge_sched, pm.charge_to
    ";

    $normal_result    = mysqli_query($conn, $normal_sql);
    $cancelled_result = mysqli_query($conn, $cancelled_sql);

    if (!$normal_result) {
        error_log("MySQL Error (normal): " . mysqli_error($conn));
        error_log("Query: " . $normal_sql);
    }
    if (!$cancelled_result) {
        error_log("MySQL Error (cancelled): " . mysqli_error($conn));
        error_log("Query: " . $cancelled_sql);
    }

    // ---- Build an index of cancelled rows keyed by (partner_id_kpx + sub_billers_name) ----
    $cancelled_by_key = [];
    if ($cancelled_result) {
        while ($row = mysqli_fetch_assoc($cancelled_result)) {
            $key = ($row['partner_id_kpx'] ?? '') . '||' . ($row['sub_billers_name'] ?? '');
            $cancelled_by_key[$key] = $row;
        }
    }

    // ---- Merge normal + cancelled ----
    $merged = [];
    if ($normal_result) {
        while ($row = mysqli_fetch_assoc($normal_result)) {
            $key = ($row['partner_id_kpx'] ?? '') . '||' . ($row['sub_billers_name'] ?? '');
            $row['cancel_volume']          = 0;
            $row['cancel_amount_paid']     = 0;
            $row['cancel_charge_partner']  = 0;
            $row['cancel_charge_customer'] = 0;
            $row['cancel_charge_total']    = 0;

            if (isset($cancelled_by_key[$key])) {
                $c = $cancelled_by_key[$key];
                $row['cancel_volume']          = (int)$c['cancel_volume'];
                $row['cancel_amount_paid']     = (float)$c['cancel_amount_paid'];
                $row['cancel_charge_partner']  = (float)$c['cancel_charge_partner'];
                $row['cancel_charge_customer'] = (float)$c['cancel_charge_customer'];
                $row['cancel_charge_total']    = (float)$c['cancel_charge_total'];
                unset($cancelled_by_key[$key]);
            }

            $row['datetime_volume']          = (int)$row['normal_volume'];
            $row['datetime_amount_paid']     = (float)$row['normal_amount_paid'];
            $row['datetime_charge_partner']  = (float)$row['normal_charge_partner'];
            $row['datetime_charge_customer'] = (float)$row['normal_charge_customer'];
            $row['datetime_charge_total']    = (float)$row['normal_charge_total'];
            $row['cancellation_volume']          = $row['cancel_volume'];
            $row['cancellation_amount_paid']     = $row['cancel_amount_paid'];
            $row['cancellation_charge_partner']  = $row['cancel_charge_partner'];
            $row['cancellation_charge_customer'] = $row['cancel_charge_customer'];
            $row['cancellation_charge_total']    = $row['cancel_charge_total'];

            // NET
            $row['total_volume']          = $row['datetime_volume'] - $row['cancellation_volume'];
            $row['total_amount_paid']     = $row['datetime_amount_paid'] + $row['cancellation_amount_paid'];
            $row['total_charge_partner']  = $row['datetime_charge_partner'] - $row['cancellation_charge_partner'];
            $row['total_charge_customer'] = $row['datetime_charge_customer'] - $row['cancellation_charge_customer'];
            $row['total_charge']          = $row['datetime_charge_total'] - $row['cancellation_charge_total'];

            // FIX: normalize bt_partner_name
            $row['bt_partner_name'] = $row['bt_partner_name'] ?? '';

            $merged[$key] = $row;
        }
    }

    // ---- Cancelled-only keys (no active counterpart) ----
    foreach ($cancelled_by_key as $key => $c) {
        $row = [
            'partner_id_kpx'      => $c['partner_id_kpx'],
            'sub_billers_name'    => $c['sub_billers_name'],
            'bt_partner_name'     => $c['bt_partner_name'] ?? '',
            'partner_name'        => $c['partner_name'],
            'charge_sched'        => $c['charge_sched'],
            'charge_to'           => $c['charge_to'],
            'datetime_volume'     => 0,
            'datetime_amount_paid'=> 0,
            'datetime_charge_partner' => 0,
            'datetime_charge_customer'=> 0,
            'datetime_charge_total'   => 0,
            'cancellation_volume'          => (int)$c['cancel_volume'],
            'cancellation_amount_paid'     => (float)$c['cancel_amount_paid'],
            'cancellation_charge_partner'  => (float)$c['cancel_charge_partner'],
            'cancellation_charge_customer' => (float)$c['cancel_charge_customer'],
            'cancellation_charge_total'    => (float)$c['cancel_charge_total'],
            'settlement_volume'            => 0,
            'settlement_amount_paid'       => 0,
            'settlement_charge_partner'    => 0,
            'settlement_charge_customer'   => 0,
            'settlement_charge'            => 0,
        ];
        $row['total_volume']          = -$row['cancellation_volume'];
        $row['total_amount_paid']     = $row['cancellation_amount_paid'];
        $row['total_charge_partner']  = -$row['cancellation_charge_partner'];
        $row['total_charge_customer'] = -$row['cancellation_charge_customer'];
        $row['total_charge']          = -$row['cancellation_charge_total'];
        $merged[$key] = $row;
    }

    return array_values($merged);
}

// ============================================
// Single grouped query for daily summary
// ============================================
function fetchDailySummary(
    string $start_datetime,
    string $end_datetime,
    string $partner_filter,
    array $all_dates
): array {
    global $conn;

    $sql = "
        SELECT
            DATE(bt.datetime) AS d,
            COUNT(CASE WHEN bt.cancellation_date IS NULL AND (bt.status IS NULL OR bt.status = '') THEN 1 END) AS success_volume,
            SUM(CASE WHEN bt.cancellation_date IS NULL AND (bt.status IS NULL OR bt.status = '') THEN bt.amount_paid ELSE 0 END) AS success_amount,
            SUM(CASE WHEN bt.cancellation_date IS NULL AND (bt.status IS NULL OR bt.status = '') THEN (bt.charge_to_partner + bt.charge_to_customer) ELSE 0 END) AS success_charge,
            COUNT(CASE WHEN bt.cancellation_date BETWEEN '{$start_datetime}' AND '{$end_datetime}' THEN 1 END) AS cancelled_volume,
            SUM(CASE WHEN bt.cancellation_date BETWEEN '{$start_datetime}' AND '{$end_datetime}' THEN bt.amount_paid ELSE 0 END) AS cancelled_amount,
            SUM(CASE WHEN bt.cancellation_date BETWEEN '{$start_datetime}' AND '{$end_datetime}' THEN (bt.charge_to_partner + bt.charge_to_customer) ELSE 0 END) AS cancelled_charge,
            COUNT(CASE WHEN bt.settle_unsettle = 'Settled' AND bt.cancellation_date IS NULL AND (bt.status IS NULL OR bt.status = '') THEN 1 END) AS settlement_volume,
            SUM(CASE WHEN bt.settle_unsettle = 'Settled' AND bt.cancellation_date IS NULL AND (bt.status IS NULL OR bt.status = '') THEN bt.amount_paid ELSE 0 END) AS settlement_amount,
            SUM(CASE WHEN bt.settle_unsettle = 'Settled' AND bt.cancellation_date IS NULL AND (bt.status IS NULL OR bt.status = '') THEN (bt.charge_to_partner + bt.charge_to_customer) ELSE 0 END) AS settlement_charge
        FROM mldb.billspayment_transaction bt
        WHERE bt.datetime BETWEEN '{$start_datetime}' AND '{$end_datetime}'
           OR bt.cancellation_date BETWEEN '{$start_datetime}' AND '{$end_datetime}'
           {$partner_filter}
        GROUP BY DATE(bt.datetime)
    ";

    $res = mysqli_query($conn, $sql);
    if (!$res) {
        error_log("MySQL Error (daily summary): " . mysqli_error($conn));
        return [];
    }

    $by_date = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $by_date[$row['d']] = $row;
    }

    $summary = [];
    $counter = 1;
    foreach ($all_dates as $date) {
        $r = $by_date[$date] ?? null;
        $successVol   = $r ? (int)$r['success_volume']    : 0;
        $cancelVol    = $r ? (int)$r['cancelled_volume']  : 0;
        $successAmt   = $r ? (float)$r['success_amount']  : 0;
        $cancelAmt    = $r ? (float)$r['cancelled_amount']: 0;
        $successChg   = $r ? (float)$r['success_charge']  : 0;
        $cancelChg    = $r ? (float)$r['cancelled_charge']: 0;

        $summary[$counter] = [
            'date'               => $date,
            'day_number'         => $counter,
            'total_volume'       => $successVol - $cancelVol,
            'total_amount_paid'  => $successAmt + $cancelAmt,
            'total_charge'       => $successChg + $cancelChg,
            'success_volume'     => $successVol,
            'cancelled_volume'   => $cancelVol,
            'settlement_volume'  => $r ? (int)$r['settlement_volume'] : 0,
            'settlement_amount'  => $r ? (float)$r['settlement_amount'] : 0,
            'settlement_charge'  => $r ? (float)$r['settlement_charge'] : 0,
        ];
        $counter++;
    }

    return $summary;
}

// ============================================
// Single grouped query for monthly summary
// ============================================
function fetchMonthlySummary(
    string $start_month,
    string $end_month,
    string $partner_filter
): array {
    global $conn;

    $start_datetime = $start_month . '-01 00:00:00';
    $end_datetime   = date('Y-m-t 23:59:59', strtotime($end_month . '-01'));

    $sql = "
        SELECT
            DATE_FORMAT(bt.datetime, '%Y-%m') AS m,
            COUNT(CASE WHEN bt.cancellation_date IS NULL AND (bt.status IS NULL OR bt.status = '') THEN 1 END) AS success_volume,
            SUM(CASE WHEN bt.cancellation_date IS NULL AND (bt.status IS NULL OR bt.status = '') THEN bt.amount_paid ELSE 0 END) AS success_amount,
            SUM(CASE WHEN bt.cancellation_date IS NULL AND (bt.status IS NULL OR bt.status = '') THEN (bt.charge_to_partner + bt.charge_to_customer) ELSE 0 END) AS success_charge,
            COUNT(CASE WHEN bt.cancellation_date BETWEEN '{$start_datetime}' AND '{$end_datetime}' THEN 1 END) AS cancelled_volume,
            SUM(CASE WHEN bt.cancellation_date BETWEEN '{$start_datetime}' AND '{$end_datetime}' THEN bt.amount_paid ELSE 0 END) AS cancelled_amount,
            SUM(CASE WHEN bt.cancellation_date BETWEEN '{$start_datetime}' AND '{$end_datetime}' THEN (bt.charge_to_partner + bt.charge_to_customer) ELSE 0 END) AS cancelled_charge,
            COUNT(CASE WHEN bt.settle_unsettle = 'Settled' AND bt.cancellation_date IS NULL AND (bt.status IS NULL OR bt.status = '') THEN 1 END) AS settlement_volume,
            SUM(CASE WHEN bt.settle_unsettle = 'Settled' AND bt.cancellation_date IS NULL AND (bt.status IS NULL OR bt.status = '') THEN bt.amount_paid ELSE 0 END) AS settlement_amount,
            SUM(CASE WHEN bt.settle_unsettle = 'Settled' AND bt.cancellation_date IS NULL AND (bt.status IS NULL OR bt.status = '') THEN (bt.charge_to_partner + bt.charge_to_customer) ELSE 0 END) AS settlement_charge
        FROM mldb.billspayment_transaction bt
        WHERE bt.datetime BETWEEN '{$start_datetime}' AND '{$end_datetime}'
           OR bt.cancellation_date BETWEEN '{$start_datetime}' AND '{$end_datetime}'
           {$partner_filter}
        GROUP BY DATE_FORMAT(bt.datetime, '%Y-%m')
    ";

    $res = mysqli_query($conn, $sql);
    if (!$res) {
        error_log("MySQL Error (monthly summary): " . mysqli_error($conn));
        return [];
    }

    $by_month = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $by_month[$row['m']] = $row;
    }

    $summary = [];
    $counter = 1;
    $current = $start_month;
    while (strtotime($current) <= strtotime($end_month)) {
        $r = $by_month[$current] ?? null;
        $successVol = $r ? (int)$r['success_volume']   : 0;
        $cancelVol  = $r ? (int)$r['cancelled_volume'] : 0;
        $successAmt = $r ? (float)$r['success_amount'] : 0;
        $cancelAmt  = $r ? (float)$r['cancelled_amount'] : 0;
        $successChg = $r ? (float)$r['success_charge'] : 0;
        $cancelChg  = $r ? (float)$r['cancelled_charge'] : 0;

        $summary[$counter] = [
            'month'              => $current,
            'month_number'       => $counter,
            'month_name'         => date('M Y', strtotime($current . '-01')),
            'total_volume'       => $successVol - $cancelVol,
            'total_amount_paid'  => $successAmt + $cancelAmt,
            'total_charge'       => $successChg + $cancelChg,
            'success_volume'     => $successVol,
            'cancelled_volume'   => $cancelVol,
            'settlement_volume'  => $r ? (int)$r['settlement_volume'] : 0,
            'settlement_amount'  => $r ? (float)$r['settlement_amount'] : 0,
            'settlement_charge'  => $r ? (float)$r['settlement_charge'] : 0,
        ];

        $current = date('Y-m', strtotime($current . ' + 1 month'));
        $counter++;
    }

    return $summary;
}

// ============================================
// Handle form submission
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_report'])) {
    $selected_day   = $_POST['selected_day']   ?? 'all';
    $selected_month = $_POST['selected_month'] ?? 'all';

    if ($time_frame === 'daily') {
        $date_from = $_POST['date_from_daily'] ?? date('Y-m-d');
        $date_to   = $date_from;
    }

    [$start_datetime, $end_datetime] = calculateDateRange(
        $time_frame, $date_from, $date_to, $month_from, $month_to, $selected_day, $selected_month
    );

    $partner_filter = buildPartnerFilter($partner_id);

    if (!empty($start_datetime) && !empty($end_datetime)) {
        $rows = fetchPartnerAggregates($start_datetime, $end_datetime, $partner_filter);

        // Sort: NULL partner_name first, then partner_name ASC, then total_volume DESC
        // FIX: also consider bt_partner_name so unregistered partners sort sensibly
        usort($rows, function ($a, $b) {
            $aName = !empty($a['partner_name']) ? $a['partner_name'] : ($a['bt_partner_name'] ?? '');
            $bName = !empty($b['partner_name']) ? $b['partner_name'] : ($b['bt_partner_name'] ?? '');

            $aNull = empty($aName) ? 1 : 0;
            $bNull = empty($bName) ? 1 : 0;
            if ($aNull !== $bNull) return $bNull - $aNull;

            $cmp = strcmp((string)$aName, (string)$bName);
            if ($cmp !== 0) return $cmp;

            return ($b['total_volume'] ?? 0) <=> ($a['total_volume'] ?? 0);
        });

        $results = $rows; // now an array, not a mysqli_result
    }

    if ($time_frame === 'daily' && !empty($date_from)) {
        $all_dates = [$date_from];
        $daily_summary = fetchDailySummary($start_datetime, $end_datetime, $partner_filter, $all_dates);
    }

    if ($time_frame === 'date_range' && !empty($date_from) && !empty($date_to)) {
        $all_dates = [];
        $cur = $date_from;
        while (strtotime($cur) <= strtotime($date_to)) {
            $all_dates[] = $cur;
            $cur = date('Y-m-d', strtotime($cur . ' + 1 day'));
        }
        $daily_summary = fetchDailySummary($start_datetime, $end_datetime, $partner_filter, $all_dates);
    }

    if ($time_frame === 'monthly' && !empty($month_from) && !empty($month_to)) {
        $monthly_summary = fetchMonthlySummary($month_from, $month_to, $partner_filter);
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
// Function to clean partner name for display
// FIX: fall back to billspayment_transaction.partner_name when
// the partner is not registered in partner_masterfile
//
// Returns: [ 'name' => '...', 'is_unregistered' => bool, 'partner_id' => '...' ]
// ============================================
function resolvePartnerDisplay($partner_id, $sub_billers_name = '', $masterfile_name = '', $bt_partner_name = '') {
    // No partner ID at all → unassigned
    if (empty($partner_id)) {
        $name = !empty($sub_billers_name) && $sub_billers_name !== '-'
            ? $sub_billers_name . ' (Unassigned)'
            : 'Unassigned Partner';
        return ['name' => $name, 'is_unassigned' => true, 'is_unregistered' => false];
    }

    // Partner ID registered in masterfile → normal path
    if (!empty($masterfile_name)) {
        return ['name' => $masterfile_name, 'is_unassigned' => false, 'is_unregistered' => false];
    }

    // Partner ID NOT in masterfile → fall back to bt.partner_name
    $fallback = '';
    if (!empty($bt_partner_name) && $bt_partner_name !== '-') {
        $fallback = $bt_partner_name;
    } else {
        $fallback = $partner_id; // last resort — show the raw ID
    }

    return [
        'name'             => $fallback,
        'is_unassigned'    => false,
        'is_unregistered'  => true,
    ];
}

// ============================================
// Legacy wrapper (kept for backward compatibility)
// ============================================
function cleanPartnerName($partner_id, $sub_billers_name = '', $masterfile_name = '', $bt_partner_name = '') {
    $r = resolvePartnerDisplay($partner_id, $sub_billers_name, $masterfile_name, $bt_partner_name);
    return $r['name'];
}

// ============================================
// FUNCTION TO GET CHARGE TYPE DISPLAY
// 'PER TRANSACTION' displayed as 'DAILY'
// ============================================
function getChargeTypeDisplay($charge_sched, $charge_to) {
    if (empty($charge_sched) && empty($charge_to)) {
        return 'N/A';
    }

    $charge_parts = [];

    if (!empty($charge_to)) {
        if (strtoupper($charge_to) === 'PARTNER') {
            $charge_parts[] = 'CHARGE BY PARTNER';
        } elseif (strtoupper($charge_to) === 'CUSTOMER') {
            $charge_parts[] = 'CHARGE BY CUSTOMER';
        } else {
            $charge_parts[] = strtoupper($charge_to);
        }
    }

    if (!empty($charge_sched)) {
        $charge_sched_upper = strtoupper($charge_sched);
        if ($charge_sched_upper === 'PER TRANSACTION') {
            $charge_parts[] = 'DAILY';
        } else {
            $charge_parts[] = $charge_sched_upper;
        }
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
        .variance-negative { color: #d93025; font-weight: bold; }
        .variance-positive { color: #1e8e3e; font-weight: bold; }
        .variance-zero { color: #666; }
        .variance-header { background: #f3e5f5 !important; color: #6a1b9a !important; }
        .variance-cell { background: #f3e5f5; }
        .charge-type-badge {
            display: inline-block; padding: 3px 10px; border-radius: 12px;
            font-size: 11px; font-weight: 600; text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .charge-type-partner { background: #e3f2fd; }
        .charge-type-customer { background: #f3e5f5; color: #6a1b9a; }
        .charge-type-daily { background: #e8f5e9; color: #2e7d32; }
        .charge-type-weekly { background: #fff3e0; color: #e65100; }
        .charge-type-monthly { background: #fce4ec; color: #c62828; }
        .charge-type-default { background: #f5f5f5; color: #616161; }
        .charge-partner-header { background: #e3f2fd !important; color: #1565c0 !important; }
        .charge-customer-header { background: #f3e5f5 !important; color: #6a1b9a !important; }
        .charge-total-header { background: #e8f5e9 !important; color: #2e7d32 !important; }
        .charge-partner-cell { background: #e3f2fd; }
        .charge-customer-cell { background: #f3e5f5; }
        .charge-total-cell { background: #e8f5e9; }

        .date-filter-bar {
            display: flex; align-items: center; flex-wrap: wrap; gap: 10px;
            padding: 10px 10px; background: #f8f9fa; border: 1px solid #e0e0e0;
            border-radius: 8px; margin-bottom: 15px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        }
        .date-filter-label {
            font-weight: 600; color: #333; font-size: 13px;
            margin-right: 4px; white-space: nowrap;
        }
        .date-filter-form {
            display: flex; flex-wrap: wrap; gap: 6px; align-items: center;
        }
        .date-filter-btn {
            padding: 6px 14px; border: 1px solid #d0d0d0; background: #fff;
            color: #333; border-radius: 20px; font-size: 12px; font-weight: 500;
            cursor: pointer; transition: all 0.2s ease; white-space: nowrap;
        }
        .date-filter-btn:hover {
            background: #fde3e3; border-color: #e8211a; color: #e81a1a;
        }
        .date-filter-btn.active {
            background: #e81a1a; border-color: #e81a1a; color: #fff;
            box-shadow: 0 2px 4px rgba(26, 115, 232, 0.3);
        }
        .date-filter-btn i { margin-right: 4px; }

        /* Unregistered partner warning */
        .partner-warning {
            display: block;
            font-size: 10px;
            color: #b26a00;
            font-style: italic;
            margin-top: 2px;
            line-height: 1.3;
        }
        .partner-unregistered-name {
            color: #b26a00;
            font-weight: 600;
        }
        .partner-id-chip {
            display: inline-block;
            background: #ffd2d2;
            color: #b20000;
            padding: 1px 6px;
            border-radius: 4px;
            font-family: monospace;
            font-size: 11px;
        }
    </style>
</head>
<body>
    <div class="main-container">
        <?php include '../../../templates/header_ui.php'; ?>
        <?php include '../../../templates/sidebar.php'; ?>

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

                <div class="form-group" id="daily_date_group" style="display: <?php echo $time_frame == 'daily' ? 'flex' : 'none'; ?>;">
                    <label for="date_from_daily">Date</label>
                    <input type="date" id="date_from_daily" name="date_from_daily" value="<?php echo ($time_frame == 'daily') ? $date_from : date('Y-m-d'); ?>">
                </div>

                <div class="form-group" id="date_range_group" style="display: <?php echo $time_frame == 'date_range' ? 'flex' : 'none'; ?>;">
                    <label for="date_from_range">Transaction Date From</label>
                    <input type="date" id="date_from_range" name="date_from" value="<?php echo ($time_frame == 'date_range') ? $date_from : date('Y-m-d'); ?>">
                </div>

                <div class="form-group" id="date_to_group" style="display: <?php echo $time_frame == 'date_range' ? 'flex' : 'none'; ?>;">
                    <label for="date_to">Transaction End Date</label>
                    <input type="date" id="date_to" name="date_to" value="<?php echo ($time_frame == 'date_range') ? $date_to : date('Y-m-d'); ?>">
                </div>

                <div class="form-group" id="month_from_group" style="display: <?php echo $time_frame == 'monthly' ? 'flex' : 'none'; ?>;">
                    <label for="month_from">Transaction Month From</label>
                    <input type="month" id="month_from" name="month_from" value="<?php echo $month_from; ?>">
                </div>

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
            <div class="results-container" id="resultsContainer">

                <?php if (($time_frame === 'date_range' || $time_frame === 'monthly')
                          && (($time_frame === 'date_range' && !empty($date_from) && !empty($date_to))
                              || ($time_frame === 'monthly' && !empty($month_from) && !empty($month_to)))): ?>
                <div class="date-filter-bar">
                    <span class="date-filter-label">
                        <i class="fa-solid fa-filter"></i> Filter by Date:
                    </span>
                    <form method="POST" action="" class="date-filter-form" id="dateFilterForm">
                        <input type="hidden" name="partner_id" value="<?php echo htmlspecialchars($partner_id); ?>">
                        <input type="hidden" name="time_frame" value="<?php echo htmlspecialchars($time_frame); ?>">
                        <input type="hidden" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                        <input type="hidden" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                        <input type="hidden" name="month_from" value="<?php echo htmlspecialchars($month_from); ?>">
                        <input type="hidden" name="month_to" value="<?php echo htmlspecialchars($month_to); ?>">
                        <input type="hidden" name="date_from_daily" value="<?php echo htmlspecialchars($date_from); ?>">
                        <input type="hidden" name="generate_report" value="1">

                        <button type="submit" name="selected_day" value="all"
                                class="date-filter-btn <?php echo (($time_frame === 'date_range' && $selected_day === 'all') || ($time_frame === 'monthly' && $selected_month === 'all')) ? 'active' : ''; ?>">
                            <i class="fa-solid fa-calendar-days"></i> All
                        </button>

                        <?php if ($time_frame === 'date_range'): ?>
                            <?php
                            $current = $date_from;
                            $idx = 1;
                            while (strtotime($current) <= strtotime($date_to)):
                                $is_active = ($selected_day !== 'all' && (int)$selected_day === $idx) ? 'active' : '';
                            ?>
                                <button type="submit" name="selected_day" value="<?php echo $idx; ?>"
                                        class="date-filter-btn <?php echo $is_active; ?>">
                                    <?php echo date('m/d (D)', strtotime($current)); ?>
                                </button>
                            <?php
                                $current = date('Y-m-d', strtotime($current . ' + 1 day'));
                                $idx++;
                            endwhile;
                            ?>
                        <?php elseif ($time_frame === 'monthly'): ?>
                            <?php
                            $current = $month_from;
                            $idx = 1;
                            while (strtotime($current) <= strtotime($month_to)):
                                $is_active = ($selected_month !== 'all' && (int)$selected_month === $idx) ? 'active' : '';
                            ?>
                                <button type="submit" name="selected_month" value="<?php echo $idx; ?>"
                                        class="date-filter-btn <?php echo $is_active; ?>">
                                    <?php echo date('M Y', strtotime($current . '-01')); ?>
                                </button>
                            <?php
                                $current = date('Y-m', strtotime($current . ' + 1 month'));
                                $idx++;
                            endwhile;
                            ?>
                        <?php endif; ?>
                    </form>
                </div>
                <?php endif; ?>

                <?php if (!empty($results)):
                    $total_records = count($results);
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
                    $unregistered_partner_ids = [];

                    $display_results = [];
                    foreach ($results as $row) {
                        $row['variance_volume'] = calculateVariance($row['total_volume'], $row['settlement_volume']);

                        $charge_to = $row['charge_to'] ?? '';
                        $charge_sched = $row['charge_sched'] ?? '';
                        $is_partner_charge       = (strtoupper($charge_to) === 'PARTNER');
                        $is_customer_daily       = (strtoupper($charge_to) === 'CUSTOMER' && in_array(strtoupper($charge_sched), ['DAILY', 'PER TRANSACTION']));
                        $is_customer_non_daily   = (strtoupper($charge_to) === 'CUSTOMER' && !in_array(strtoupper($charge_sched), ['DAILY', 'PER TRANSACTION']));
                        $is_both                 = (strtoupper($charge_to) === 'BOTH');

                        if ($is_partner_charge || $is_customer_non_daily) {
                            $row['variance_amount'] = $row['total_amount_paid'] - $row['settlement_amount_paid'];
                        } elseif ($is_customer_daily) {
                            $row['variance_amount'] = $row['total_amount_paid'] - ($row['settlement_amount_paid'] + $row['settlement_charge_partner']);
                        } elseif ($is_both) {
                            $row['variance_amount'] = $row['total_amount_paid'] - ($row['settlement_amount_paid'] + $row['settlement_charge']);
                        } else {
                            $row['variance_amount'] = $row['total_amount_paid'] - ($row['settlement_amount_paid'] + $row['settlement_charge']);
                        }

                        $row['charge_type_display'] = getChargeTypeDisplay($row['charge_sched'] ?? '', $row['charge_to'] ?? '');

                      // Track unregistered partner IDs for the summary banner (store the fallback name too)
if (!empty($row['partner_id_kpx']) && empty($row['partner_name'])) {
    $fallback_name = !empty($row['bt_partner_name']) && $row['bt_partner_name'] !== '-'
        ? $row['bt_partner_name']
        : $row['partner_id_kpx']; // last resort = raw ID

    // If multiple rows share the same partner_id, keep the first non-empty name
    if (!isset($unregistered_partner_ids[$row['partner_id_kpx']]) || empty($unregistered_partner_ids[$row['partner_id_kpx']])) {
        $unregistered_partner_ids[$row['partner_id_kpx']] = $fallback_name;
    }
}
                        $display_results[] = $row;

                        $total_datetime_volume          += $row['datetime_volume'];
                        $total_datetime_amount          += $row['datetime_amount_paid'];
                        $total_datetime_charge_partner  += $row['datetime_charge_partner'];
                        $total_datetime_charge_customer += $row['datetime_charge_customer'];
                        $total_datetime_charge_total    += $row['datetime_charge_total'];
                        $total_cancellation_volume          += $row['cancellation_volume'];
                        $total_cancellation_amount          += $row['cancellation_amount_paid'];
                        $total_cancellation_charge_partner  += $row['cancellation_charge_partner'];
                        $total_cancellation_charge_customer += $row['cancellation_charge_customer'];
                        $total_cancellation_charge_total    += $row['cancellation_charge_total'];
                        $total_volume          += $row['total_volume'];
                        $total_amount          += $row['total_amount_paid'];
                        $total_charge_partner  += $row['total_charge_partner'];
                        $total_charge_customer += $row['total_charge_customer'];
                        $total_charge          += $row['total_charge'];
                        $total_settlement_volume          += $row['settlement_volume'];
                        $total_settlement_amount          += $row['settlement_amount_paid'];
                        $total_settlement_charge_partner  += $row['settlement_charge_partner'];
                        $total_settlement_charge_customer += $row['settlement_charge_customer'];
                        $total_settlement_charge          += $row['settlement_charge'];
                        $total_variance_volume += $row['variance_volume'];
                        $total_variance_amount += $row['variance_amount'];
                    }
                ?>
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
                            <div class="stat-value"><?php echo number_format($total_variance_volume); ?></div>
                            <div class="stat-label">Variance Volume</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value"><?php echo number_format($total_variance_amount, 2); ?></div>
                            <div class="stat-label">Variance Amount</div>
                        </div>
                    </div>

                    <?php if (!empty($unregistered_partner_ids)): ?>
                    <div class="unregistered-banner" style="
                        background: #ffe1e1;
                        border: 1px solid #ff8080;
                        border-left: 5px solid #f50000;
                        border-radius: 6px;
                        padding: 10px 14px;
                        margin-bottom: 15px;
                        color: #282828;
                        font-size: 13px;
                        line-height: 1.5;
                    ">
                        <div style="display: flex; align-items: flex-start; gap: 10px;">
                            <i class="fa-solid fa-triangle-exclamation" style="color: #f57c00; font-size: 18px; margin-top: 2px;"></i>
                            <div>
                                <strong>Unregistered Partner IDs detected (<?php echo count($unregistered_partner_ids); ?>):</strong>
                                The following Partner IDs exist in <code>Bills Payment Transaction</code> but are <strong>not registered</strong> in <code>Masterdata Partner Masterfile</code>. The report shows the name from the transaction record as a fallback. Please register these IDs in the Partner Masterfile.
                                <div style="margin-top: 8px; display: flex; flex-wrap: wrap; gap: 6px;">
    <?php foreach ($unregistered_partner_ids as $pid => $pname): ?>
        <span class="partner-id-chip" title="<?php echo htmlspecialchars($pname, ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo htmlspecialchars($pid); ?>
        </span>
    <?php endforeach; ?>
</div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="table-wrapper">
                        <table class="results-table">
                            <thead>
                                <tr>
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
                                foreach ($display_results as $row):
                                    // FIX: resolve display + flags
                                    $info = resolvePartnerDisplay(
                                        $row['partner_id_kpx'] ?? '',
                                        $row['sub_billers_name'] ?? '',
                                        $row['partner_name'] ?? '',
                                        $row['bt_partner_name'] ?? ''
                                    );
                                    $partner_name     = $info['name'];
                                    $is_unassigned    = $info['is_unassigned'];
                                    $is_unregistered  = $info['is_unregistered'];

                                    $display_sub_biller = $row['sub_billers_name'] ?? '-';
                                    if ($is_unassigned && $display_sub_biller === '-') {
                                        $display_sub_biller = 'Unassigned Partner Transaction';
                                    }

                                    $variance_volume = $row['variance_volume'];
                                    $variance_amount = $row['variance_amount'];

                                    if ($variance_volume > 0) $volume_class = 'variance-positive';
                                    elseif ($variance_volume < 0) $volume_class = 'variance-negative';
                                    else $volume_class = 'variance-zero';

                                    if ($variance_amount > 0) $amount_class = 'variance-positive';
                                    elseif ($variance_amount < 0) $amount_class = 'variance-negative';
                                    else $amount_class = 'variance-zero';

                                    $charge_type_display = $row['charge_type_display'];
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
                                ?>
                                    <tr>
                                        <td><?php echo $counter++; ?></td>
                                        <td style="text-align: left; padding-left: 15px; <?php echo $is_unassigned ? 'color: #d93025; font-style: italic;' : ($is_unregistered ? 'color: #b26a00;' : ''); ?>">
                                            <?php if ($is_unregistered): ?>
                                                <span class="partner-unregistered-name"><?php echo htmlspecialchars($partner_name); ?></span>
                                                <span class="partner-warning">
                                                    <i class="fa-solid fa-triangle-exclamation"></i>
                                                    Partner ID <strong><?php echo htmlspecialchars($row['partner_id_kpx']); ?></strong>
                                                    is not registered in the Partner Masterfile. Please register it.
                                                </span>
                                            <?php else: ?>
                                                <?php echo htmlspecialchars($partner_name); ?>
                                                <?php if ($is_unassigned): ?>
                                                    <span style="font-size: 10px; color: #d93025; display: block;">(No Partner ID)</span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: center;">
                                            <span class="charge-type-badge <?php echo $charge_type_class; ?>">
                                                <?php echo htmlspecialchars($charge_type_display); ?>
                                            </span>
                                        </td>
                                        <td style="text-align: left; padding-left: 15px;"><?php echo htmlspecialchars($display_sub_biller); ?></td>
                                        <td><?php echo number_format($row['datetime_volume']); ?></td>
                                        <td style="text-align: right;"><?php echo number_format($row['datetime_amount_paid'], 2); ?></td>
                                        <td><?php echo number_format($row['datetime_charge_partner'], 2); ?></td>
                                        <td><?php echo number_format($row['datetime_charge_customer'], 2); ?></td>
                                        <td><?php echo number_format($row['cancellation_volume']); ?></td>
                                        <td style="text-align: right;"><?php echo number_format(abs($row['cancellation_amount_paid']), 2); ?></td>
                                        <td><?php echo number_format(abs($row['cancellation_charge_partner']), 2); ?></td>
                                        <td><?php echo number_format(abs($row['cancellation_charge_customer']), 2); ?></td>
                                        <td><strong><?php echo number_format($row['total_volume']); ?></strong></td>
                                        <td><strong><?php echo number_format($row['total_amount_paid'], 2); ?></strong></td>
                                        <td><strong><?php echo number_format($row['total_charge_partner'], 2); ?></strong></td>
                                        <td><strong><?php echo number_format($row['total_charge_customer'], 2); ?></strong></td>
                                        <td><?php echo number_format($row['settlement_volume']); ?></td>
                                        <td><?php echo number_format($row['settlement_amount_paid'], 2); ?></td>
                                        <td><?php echo number_format($row['settlement_charge_partner'], 2); ?></td>
                                        <td><?php echo number_format($row['settlement_charge_customer'], 2); ?></td>
                                        <td class="<?php echo $volume_class; ?>">
                                            <?php echo number_format($variance_volume); ?>
                                        </td>
                                        <td class="<?php echo $amount_class; ?>">
                                            <?php echo number_format($variance_amount, 2); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                                <tr class="grand-total">
                                    <td colspan="4" style="text-align: right; padding-right: 20px;">TOTAL</td>
                                    <td><?php echo number_format($total_datetime_volume); ?></td>
                                    <td style="text-align: right;"><?php echo number_format($total_datetime_amount, 2); ?></td>
                                    <td><?php echo number_format($total_datetime_charge_partner, 2); ?></td>
                                    <td><?php echo number_format($total_datetime_charge_customer, 2); ?></td>
                                    <td><?php echo number_format($total_cancellation_volume); ?></td>
                                    <td style="text-align: right;"><?php echo number_format(abs($total_cancellation_amount), 2); ?></td>
                                    <td><?php echo number_format(abs($total_cancellation_charge_partner), 2); ?></td>
                                    <td><?php echo number_format(abs($total_cancellation_charge_customer), 2); ?></td>
                                    <td><strong><?php echo number_format($total_volume); ?></strong></td>
                                    <td><strong><?php echo number_format($total_amount, 2); ?></strong></td>
                                    <td><strong><?php echo number_format($total_charge_partner, 2); ?></strong></td>
                                    <td><strong><?php echo number_format($total_charge_customer, 2); ?></strong></td>
                                    <td><strong><?php echo number_format($total_settlement_volume); ?></strong></td>
                                    <td><strong><?php echo number_format($total_settlement_amount, 2); ?></strong></td>
                                    <td><strong><?php echo number_format($total_settlement_charge_partner, 2); ?></strong></td>
                                    <td><strong><?php echo number_format($total_settlement_charge_customer, 2); ?></strong></td>
                                    <td><strong><?php echo number_format($total_variance_volume); ?></strong></td>
                                    <td><strong><?php echo number_format($total_variance_amount, 2); ?></strong></td>
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

            if (document.readyState === 'complete') {
                hideLoading();
            }
        });

        function showLoading(message = 'Loading Report...') {
            const overlay = document.getElementById('loading-overlay');
            if (overlay) {
                const textElement = overlay.querySelector('.loading-text');
                if (textElement) textElement.textContent = message;
                overlay.classList.add('active');
                document.body.classList.add('loading');

                const progressBar = overlay.querySelector('.loading-progress-bar');
                if (progressBar) {
                    progressBar.style.animation = 'none';
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

        document.addEventListener('DOMContentLoaded', function() {
            const filterForm = document.getElementById('filterForm');
            if (filterForm) {
                filterForm.addEventListener('submit', function(e) {
                    const submitButton = document.activeElement;
                    if (submitButton && submitButton.name === 'generate_report') {
                        const timeFrame = document.getElementById('time_frame').value;
                        let loadingMessage = 'Generating Report...';
                        switch(timeFrame) {
                            case 'daily':      loadingMessage = 'Loading Daily Report...'; break;
                            case 'date_range': loadingMessage = 'Loading Date Range Report...'; break;
                            case 'monthly':    loadingMessage = 'Loading Monthly Report...'; break;
                        }
                        showLoading(loadingMessage);

                        if (window.loadingTimeout) clearTimeout(window.loadingTimeout);
                        window.loadingTimeout = setTimeout(function() {
                            hideLoading();
                            Swal.fire({
                                icon: 'warning',
                                title: 'Loading Timeout',
                                text: 'The report is taking longer than expected. Please try again.',
                                confirmButtonColor: '#1a73e8'
                            });
                        }, 60000);
                    }
                });
            }

            const dateFilterForm = document.getElementById('dateFilterForm');
            if (dateFilterForm) {
                dateFilterForm.addEventListener('submit', function(e) {
                    const clickedBtn = document.activeElement;
                    if (clickedBtn && clickedBtn.classList.contains('date-filter-btn')) {
                        showLoading('Filtering by Date...');
                    }
                });
            }

            window.addEventListener('load', function() {
                setTimeout(function() { hideLoading(); }, 500);
            });

            if (document.querySelector('.results-container') || document.querySelector('.no-results')) {
                setTimeout(function() { hideLoading(); }, 300);
            }
        });

        function toggleDateFields() {
            const timeFrame = document.getElementById('time_frame').value;
            document.getElementById('daily_date_group').style.display = 'none';
            document.getElementById('date_range_group').style.display = 'none';
            document.getElementById('date_to_group').style.display = 'none';
            document.getElementById('month_from_group').style.display = 'none';
            document.getElementById('month_to_group').style.display = 'none';

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

        document.getElementById('filterForm').addEventListener('submit', function(e) {
            const timeFrame = document.getElementById('time_frame').value;
            let isValid = true;
            let errorMessage = '';

            if (timeFrame === 'daily') {
                const date = document.getElementById('date_from_daily').value;
                if (!date) { isValid = false; errorMessage = 'Please select a date.'; }
            } else if (timeFrame === 'date_range') {
                const dateFrom = document.getElementById('date_from_range').value;
                const dateTo = document.getElementById('date_to').value;
                if (!dateFrom || !dateTo) {
                    isValid = false; errorMessage = 'Please select both start date and end date.';
                } else if (dateFrom > dateTo) {
                    isValid = false; errorMessage = 'Start Date cannot be later than End Date.';
                }
            } else if (timeFrame === 'monthly') {
                const monthFrom = document.getElementById('month_from').value;
                const monthTo = document.getElementById('month_to').value;
                if (!monthFrom || !monthTo) {
                    isValid = false; errorMessage = 'Please select both month from and month to.';
                } else if (monthFrom > monthTo) {
                    isValid = false; errorMessage = 'Month From cannot be later than Month To.';
                }
            }

            if (!isValid) {
                e.preventDefault();
                hideLoading();
                Swal.fire({
                    icon: 'warning',
                    title: 'Validation Error',
                    text: errorMessage,
                    confirmButtonColor: '#1a73e8'
                });
            }
        });

        function exportReport() {
            const partnerId = document.getElementById('partner_id').value || '';
            const timeFrame = document.getElementById('time_frame').value;

            let dateFrom = '';
            let dateTo = '';
            let monthFrom = '';
            let monthTo = '';
            let dateFromDaily = '';
            let selectedDay = 'all';
            let selectedMonth = 'all';

            const hiddenSelectedDay = document.querySelector('input[name="selected_day"]:not([type="hidden"])');
            if (hiddenSelectedDay) selectedDay = hiddenSelectedDay.value;
            const hiddenSelectedMonth = document.querySelector('input[name="selected_month"]:not([type="hidden"])');
            if (hiddenSelectedMonth) selectedMonth = hiddenSelectedMonth.value;

            if (timeFrame === 'daily') {
                dateFromDaily = document.getElementById('date_from_daily').value || '';
            } else if (timeFrame === 'date_range') {
                dateFrom = document.getElementById('date_from_range').value || '';
                dateTo = document.getElementById('date_to').value || '';
            } else if (timeFrame === 'monthly') {
                monthFrom = document.getElementById('month_from').value || '';
                monthTo = document.getElementById('month_to').value || '';
            }

            if (!partnerId && !dateFromDaily && !dateFrom && !monthFrom) {
                Swal.fire({
                    icon: 'warning',
                    title: 'No Data to Export',
                    text: 'Please generate a report first before exporting.',
                    confirmButtonColor: '#1a73e8'
                });
                return;
            }

            showLoading('Preparing Export...');

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

            const exportWindow = window.open(url, '_blank');

            setTimeout(() => { hideLoading(); }, 2000);

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

        function debugReport() {
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
                        <p><strong>User:</strong> <?php echo $current_user_email; ?></p>
                        <p><strong>PHP Version:</strong> <?php echo phpversion(); ?></p>
                    </div>
                `,
                confirmButtonColor: '#1a73e8',
                width: 600
            });
        }

        $(document).ajaxError(function() { hideLoading(); });

        $(window).on('load', function() {
            setTimeout(function() { hideLoading(); }, 500);
        });

        document.addEventListener('DOMContentLoaded', function() {
            const resetLink = document.querySelector('.btn-reset');
            if (resetLink) {
                resetLink.addEventListener('click', function(e) {
                    showLoading('Resetting Filters...');
                });
            }
        });
    </script>

    <?php include '../../../templates/footer.php'; ?>
</body>
</html>