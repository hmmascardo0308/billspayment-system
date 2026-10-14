<?php
// export_volume.php - Export volume report to Excel (multi-sheet)
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

// Start session and check permissions
session_start();
@include_once __DIR__ . '/../../../templates/middleware.php';
$id = resolve_user_identifier();
if (empty($id)) { header('Location: ../../../login_form.php'); exit; }
if (!function_exists('has_any_permission') || !has_any_permission(['Volume Report','Bills Payment'])) { header('Location: ../../home.php'); exit; }

// Get current user email and display name
$current_user_email = $_SESSION['admin_email'] ?? $_SESSION['user_email'] ?? '';
$display_name = $_SESSION['admin_name'] ?? $_SESSION['user_name'] ?? $current_user_email;

// ============================================
// Get filter parameters from GET
// ============================================
$partner_id      = $_GET['partner_id'] ?? '';
$time_frame      = $_GET['time_frame'] ?? 'date_range';
$date_from       = $_GET['date_from'] ?? date('Y-m-d');
$date_to         = $_GET['date_to'] ?? date('Y-m-d');
$month_from      = $_GET['month_from'] ?? date('Y-m');
$month_to        = $_GET['month_to'] ?? date('Y-m');
$date_from_daily = $_GET['date_from_daily'] ?? date('Y-m-d');
$selected_day    = $_GET['selected_day'] ?? 'all';
$selected_month  = $_GET['selected_month'] ?? 'all';

// Normalize empty values
if ($selected_day === '' || $selected_day === null)     $selected_day = 'all';
if ($selected_month === '' || $selected_month === null) $selected_month = 'all';

// Fix date handling for daily
if ($time_frame === 'daily') {
    $date_from = $date_from_daily;
    $date_to   = $date_from_daily;
}

// ============================================
// Determine whether we need multiple sheets
// ============================================
$create_multi_sheet = false;
$period_list = [];

if ($time_frame === 'date_range' && ($selected_day === 'all' || $selected_day === '')) {
    $start_ts = strtotime($date_from);
    $end_ts   = strtotime($date_to);
    if ($start_ts !== false && $end_ts !== false && $start_ts < $end_ts) {
        $create_multi_sheet = true;
        $current = $date_from;
        while (strtotime($current) <= strtotime($date_to)) {
            $period_list[] = [
                'label'      => date('M j', strtotime($current)),
                'sheet_name' => date('M j', strtotime($current)),
                'start'      => $current . ' 00:00:00',
                'end'        => $current . ' 23:59:59',
            ];
            $current = date('Y-m-d', strtotime($current . ' + 1 day'));
        }
    }
} elseif ($time_frame === 'monthly' && ($selected_month === 'all' || $selected_month === '')) {
    $start_ts = strtotime($month_from . '-01');
    $end_ts   = strtotime($month_to . '-01');
    if ($start_ts !== false && $end_ts !== false && $start_ts < $end_ts) {
        $create_multi_sheet = true;
        $current = $month_from;
        while (strtotime($current) <= strtotime($month_to)) {
            $period_list[] = [
                'label'      => date('M Y', strtotime($current . '-01')),
                'sheet_name' => date('M Y', strtotime($current . '-01')),
                'start'      => $current . '-01 00:00:00',
                'end'        => date('Y-m-t 23:59:59', strtotime($current . '-01')),
            ];
            $current = date('Y-m', strtotime($current . ' + 1 month'));
        }
    }
}

// ============================================
// Calculate start and end datetime for the SUMMARY sheet
// ============================================
$start_datetime = '';
$end_datetime   = '';

if ($time_frame === 'daily') {
    $start_datetime = $date_from . ' 00:00:00';
    $end_datetime   = $date_from . ' 23:59:59';

} elseif ($time_frame === 'date_range') {
    if ($selected_day && $selected_day !== 'all') {
        $selected_date  = date('Y-m-d', strtotime($date_from . ' + ' . ((int)$selected_day - 1) . ' days'));
        $start_datetime = $selected_date . ' 00:00:00';
        $end_datetime   = $selected_date . ' 23:59:59';
    } else {
        $start_datetime = $date_from . ' 00:00:00';
        $end_datetime   = $date_to . ' 23:59:59';
    }

} elseif ($time_frame === 'monthly') {
    if ($selected_month && $selected_month !== 'all') {
        $selected_month_date = date('Y-m', strtotime($month_from . ' + ' . ((int)$selected_month - 1) . ' months'));
        $start_datetime      = $selected_month_date . '-01 00:00:00';
        $end_datetime        = date('Y-m-t 23:59:59', strtotime($selected_month_date . '-01'));
    } else {
        $start_datetime = $month_from . '-01 00:00:00';
        $end_datetime   = date('Y-m-t 23:59:59', strtotime($month_to . '-01'));
    }
}

// ============================================
// FUNCTION: Get charge type display
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
// FUNCTION: Resolve partner display info
// ============================================
function resolvePartnerDisplayForExport($partner_id, $sub_billers_name = '', $masterfile_name = '', $bt_partner_name = '') {
    if (empty($partner_id) || strpos($partner_id, 'UNKNOWN_') === 0) {
        $name = !empty($sub_billers_name) && $sub_billers_name !== '-'
            ? $sub_billers_name . ' (Unassigned)'
            : 'Unassigned Partner';
        return ['name' => $name, 'is_unassigned' => true, 'is_unregistered' => false];
    }

    if (!empty($masterfile_name)) {
        return ['name' => $masterfile_name, 'is_unassigned' => false, 'is_unregistered' => false];
    }

    $fallback = (!empty($bt_partner_name) && $bt_partner_name !== '-') ? $bt_partner_name : $partner_id;
    return [
        'name'            => $fallback . ' — Not registered in Partner Masterfile',
        'is_unassigned'   => false,
        'is_unregistered' => true,
    ];
}

// Legacy wrapper
function cleanPartnerNameForExport($partner_id, $sub_billers_name = '', $partner_name = '', $bt_partner_name = '') {
    $r = resolvePartnerDisplayForExport($partner_id, $sub_billers_name, $partner_name, $bt_partner_name);
    return $r['name'];
}

// ============================================
// FUNCTION: Run the partner-aggregate queries for a given datetime window
// ============================================
function getReportDataForRange($conn, $partner_id, $start_datetime, $end_datetime) {
    $partner_filter = '';
    if (!empty($partner_id)) {
        $partner_filter = " AND bt.partner_id_kpx = '" . mysqli_real_escape_string($conn, $partner_id) . "'";
    }

    // ---- NORMAL / ACTIVE transactions ----
    $normal_sql = "
        SELECT
            bt.partner_id_kpx,
            bt.sub_billers_name,
            MAX(bt.partner_name) AS bt_partner_name,
            pm.partner_name,
            pm.charge_sched,
            pm.charge_to,
            COUNT(*)                              AS datetime_volume,
            SUM(bt.amount_paid)                   AS datetime_amount_paid,
            SUM(bt.charge_to_partner)             AS datetime_charge_partner,
            SUM(bt.charge_to_customer)            AS datetime_charge_customer,
            SUM(bt.charge_to_partner + bt.charge_to_customer) AS datetime_charge_total,
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
            COUNT(*)                              AS cancellation_volume,
            SUM(bt.amount_paid)                   AS cancellation_amount_paid,
            SUM(bt.charge_to_partner)             AS cancellation_charge_partner,
            SUM(bt.charge_to_customer)            AS cancellation_charge_customer,
            SUM(bt.charge_to_partner + bt.charge_to_customer) AS cancellation_charge_total
        FROM mldb.billspayment_transaction bt
        LEFT JOIN masterdata.partner_masterfile pm ON bt.partner_id_kpx = pm.partner_id_kpx
        WHERE bt.cancellation_date BETWEEN '{$start_datetime}' AND '{$end_datetime}'
          {$partner_filter}
        GROUP BY bt.partner_id_kpx, bt.sub_billers_name, pm.partner_name, pm.charge_sched, pm.charge_to
    ";

    $normal_result    = mysqli_query($conn, $normal_sql);
    $cancelled_result = mysqli_query($conn, $cancelled_sql);

    if (!$normal_result) {
        error_log("MySQL Error (normal) in getReportDataForRange: " . mysqli_error($conn));
        error_log("Query: " . $normal_sql);
    }
    if (!$cancelled_result) {
        error_log("MySQL Error (cancelled) in getReportDataForRange: " . mysqli_error($conn));
        error_log("Query: " . $cancelled_sql);
    }

    // Build index of cancelled rows
    $cancelled_by_key = [];
    if ($cancelled_result) {
        while ($row = mysqli_fetch_assoc($cancelled_result)) {
            $key = ($row['partner_id_kpx'] ?? '') . '||' . ($row['sub_billers_name'] ?? '');
            $cancelled_by_key[$key] = $row;
        }
    }

    // Merge
    $merged = [];
    if ($normal_result) {
        while ($row = mysqli_fetch_assoc($normal_result)) {
            $key = ($row['partner_id_kpx'] ?? '') . '||' . ($row['sub_billers_name'] ?? '');
            $row['cancellation_volume']          = 0;
            $row['cancellation_amount_paid']     = 0;
            $row['cancellation_charge_partner']  = 0;
            $row['cancellation_charge_customer'] = 0;
            $row['cancellation_charge_total']    = 0;

            if (isset($cancelled_by_key[$key])) {
                $c = $cancelled_by_key[$key];
                $row['cancellation_volume']          = (int)$c['cancellation_volume'];
                $row['cancellation_amount_paid']     = (float)$c['cancellation_amount_paid'];
                $row['cancellation_charge_partner']  = (float)$c['cancellation_charge_partner'];
                $row['cancellation_charge_customer'] = (float)$c['cancellation_charge_customer'];
                $row['cancellation_charge_total']    = (float)$c['cancellation_charge_total'];
                unset($cancelled_by_key[$key]);
            }

            // NET
            $row['total_volume']          = $row['datetime_volume'] - $row['cancellation_volume'];
            $row['total_amount_paid']     = $row['datetime_amount_paid'] + $row['cancellation_amount_paid'];
            $row['total_charge_partner']  = $row['datetime_charge_partner'] - $row['cancellation_charge_partner'];
            $row['total_charge_customer'] = $row['datetime_charge_customer'] - $row['cancellation_charge_customer'];
            $row['total_charge']          = $row['datetime_charge_total'] - $row['cancellation_charge_total'];

            // Ensure all numeric keys exist with defaults
            $row['datetime_volume']          = (int)$row['datetime_volume'];
            $row['datetime_amount_paid']     = (float)$row['datetime_amount_paid'];
            $row['datetime_charge_partner']  = (float)$row['datetime_charge_partner'];
            $row['datetime_charge_customer'] = (float)$row['datetime_charge_customer'];
            $row['datetime_charge_total']    = (float)$row['datetime_charge_total'];
            $row['settlement_volume']          = (int)($row['settlement_volume'] ?? 0);
            $row['settlement_amount_paid']     = (float)($row['settlement_amount_paid'] ?? 0);
            $row['settlement_charge_partner']  = (float)($row['settlement_charge_partner'] ?? 0);
            $row['settlement_charge_customer'] = (float)($row['settlement_charge_customer'] ?? 0);
            $row['settlement_charge']          = (float)($row['settlement_charge'] ?? 0);
            $row['bt_partner_name']            = $row['bt_partner_name'] ?? '';

            $merged[$key] = $row;
        }
    }

    // Cancelled-only rows (no active counterpart in the window)
    foreach ($cancelled_by_key as $key => $c) {
        $row = [
            'partner_id_kpx'              => $c['partner_id_kpx'],
            'sub_billers_name'            => $c['sub_billers_name'],
            'bt_partner_name'             => $c['bt_partner_name'] ?? '',
            'partner_name'                => $c['partner_name'],
            'charge_sched'                => $c['charge_sched'],
            'charge_to'                   => $c['charge_to'],
            'datetime_volume'             => 0,
            'datetime_amount_paid'        => 0,
            'datetime_charge_partner'     => 0,
            'datetime_charge_customer'    => 0,
            'datetime_charge_total'       => 0,
            'cancellation_volume'         => (int)$c['cancellation_volume'],
            'cancellation_amount_paid'    => (float)$c['cancellation_amount_paid'],
            'cancellation_charge_partner' => (float)$c['cancellation_charge_partner'],
            'cancellation_charge_customer'=> (float)$c['cancellation_charge_customer'],
            'cancellation_charge_total'   => (float)$c['cancellation_charge_total'],
            'settlement_volume'           => 0,
            'settlement_amount_paid'      => 0,
            'settlement_charge_partner'   => 0,
            'settlement_charge_customer'  => 0,
            'settlement_charge'           => 0,
        ];
        $row['total_volume']          = -$row['cancellation_volume'];
        $row['total_amount_paid']     = $row['cancellation_amount_paid'];
        $row['total_charge_partner']  = -$row['cancellation_charge_partner'];
        $row['total_charge_customer'] = -$row['cancellation_charge_customer'];
        $row['total_charge']          = -$row['cancellation_charge_total'];
        $merged[$key] = $row;
    }

    $merged = array_values($merged);

    // Sort: NULL partner_name first, then partner_name ASC, then total_volume DESC
    usort($merged, function ($a, $b) {
        $aName = !empty($a['partner_name']) ? $a['partner_name'] : ($a['bt_partner_name'] ?? '');
        $bName = !empty($b['partner_name']) ? $b['partner_name'] : ($b['bt_partner_name'] ?? '');

        $aNull = empty($aName) ? 1 : 0;
        $bNull = empty($bName) ? 1 : 0;
        if ($aNull !== $bNull) return $bNull - $aNull;

        $cmp = strcmp((string)$aName, (string)$bName);
        if ($cmp !== 0) return $cmp;

        return ($b['total_volume'] ?? 0) <=> ($a['total_volume'] ?? 0);
    });

    // ---- Compute variance + charge_type_display, accumulate totals ----
    $totals = [
        'datetime_volume' => 0, 'datetime_amount_paid' => 0,
        'datetime_charge_partner' => 0, 'datetime_charge_customer' => 0,
        'cancellation_volume' => 0, 'cancellation_amount_paid' => 0,
        'cancellation_charge_partner' => 0, 'cancellation_charge_customer' => 0,
        'total_volume' => 0, 'total_amount_paid' => 0,
        'total_charge_partner' => 0, 'total_charge_customer' => 0, 'total_charge' => 0,
        'settlement_volume' => 0, 'settlement_amount_paid' => 0,
        'settlement_charge_partner' => 0, 'settlement_charge_customer' => 0, 'settlement_charge' => 0,
        'variance_volume' => 0, 'variance_amount' => 0,
    ];

    $unregistered_partner_ids = [];

    foreach ($merged as &$row) {
        // Variance volume
        $row['variance_volume'] = ($row['total_volume'] ?? 0) - ($row['settlement_volume'] ?? 0);

        // Variance amount (depends on charge type)
        $charge_to    = $row['charge_to'] ?? '';
        $charge_sched = $row['charge_sched'] ?? '';
        $is_partner_charge     = (strtoupper($charge_to) === 'PARTNER');
        $is_customer_daily     = (strtoupper($charge_to) === 'CUSTOMER' && in_array(strtoupper($charge_sched), ['DAILY', 'PER TRANSACTION']));
        $is_customer_non_daily = (strtoupper($charge_to) === 'CUSTOMER' && !in_array(strtoupper($charge_sched), ['DAILY', 'PER TRANSACTION']));
        $is_both               = (strtoupper($charge_to) === 'BOTH');

        if ($is_partner_charge || $is_customer_non_daily) {
            $row['variance_amount'] = ($row['total_amount_paid'] ?? 0) - ($row['settlement_amount_paid'] ?? 0);
        } elseif ($is_customer_daily) {
            $row['variance_amount'] = ($row['total_amount_paid'] ?? 0) - (($row['settlement_amount_paid'] ?? 0) + ($row['settlement_charge_partner'] ?? 0));
        } elseif ($is_both) {
            $row['variance_amount'] = ($row['total_amount_paid'] ?? 0) - (($row['settlement_amount_paid'] ?? 0) + ($row['settlement_charge'] ?? 0));
        } else {
            $row['variance_amount'] = ($row['total_amount_paid'] ?? 0) - (($row['settlement_amount_paid'] ?? 0) + ($row['settlement_charge'] ?? 0));
        }

        $row['charge_type_display'] = getChargeTypeDisplay($row['charge_sched'] ?? '', $row['charge_to'] ?? '');

        // Track unregistered partner IDs
        if (!empty($row['partner_id_kpx']) && empty($row['partner_name'])) {
            $unregistered_partner_ids[$row['partner_id_kpx']] = true;
        }

        // Totals
        $totals['datetime_volume']           += $row['datetime_volume'];
        $totals['datetime_amount_paid']      += $row['datetime_amount_paid'];
        $totals['datetime_charge_partner']   += $row['datetime_charge_partner'];
        $totals['datetime_charge_customer']  += $row['datetime_charge_customer'];
        $totals['cancellation_volume']          += $row['cancellation_volume'];
        $totals['cancellation_amount_paid']     += $row['cancellation_amount_paid'];
        $totals['cancellation_charge_partner']  += $row['cancellation_charge_partner'];
        $totals['cancellation_charge_customer'] += $row['cancellation_charge_customer'];
        $totals['total_volume']          += $row['total_volume'];
        $totals['total_amount_paid']     += $row['total_amount_paid'];
        $totals['total_charge_partner']  += $row['total_charge_partner'];
        $totals['total_charge_customer'] += $row['total_charge_customer'];
        $totals['total_charge']          += $row['total_charge'];
        $totals['settlement_volume']          += $row['settlement_volume'];
        $totals['settlement_amount_paid']     += $row['settlement_amount_paid'];
        $totals['settlement_charge_partner']  += $row['settlement_charge_partner'];
        $totals['settlement_charge_customer'] += $row['settlement_charge_customer'];
        $totals['settlement_charge']          += $row['settlement_charge'];
        $totals['variance_volume'] += $row['variance_volume'];
        $totals['variance_amount'] += $row['variance_amount'];
    }
    unset($row);

    return [
        'rows' => $merged,
        'totals' => $totals,
        'unregistered_partner_ids' => array_keys($unregistered_partner_ids),
    ];
}

// Get partner name for display (for the filter summary)
$selected_partner_name = '';
if (!empty($partner_id)) {
    $name_query = "SELECT partner_name FROM masterdata.partner_masterfile WHERE partner_id_kpx = '" . mysqli_real_escape_string($conn, $partner_id) . "'";
    $name_result = mysqli_query($conn, $name_query);
    if ($name_row = mysqli_fetch_assoc($name_result)) {
        $selected_partner_name = $name_row['partner_name'];
    }
}

// ============================================
// Build a human-readable "Displayed Filter" label
// ============================================
$display_filter_label = '';
if ($time_frame === 'daily') {
    $display_filter_label = 'Daily: ' . date('F d, Y', strtotime($date_from));
} elseif ($time_frame === 'date_range') {
    if ($selected_day && $selected_day !== 'all') {
        $single_date = date('Y-m-d', strtotime($date_from . ' + ' . ((int)$selected_day - 1) . ' days'));
        $display_filter_label = 'Date Range (Single Date): ' . date('F d, Y', strtotime($single_date));
    } else {
        $display_filter_label = 'Date Range: ' . date('F d, Y', strtotime($date_from)) . ' to ' . date('F d, Y', strtotime($date_to));
    }
} elseif ($time_frame === 'monthly') {
    if ($selected_month && $selected_month !== 'all') {
        $single_month = date('Y-m', strtotime($month_from . ' + ' . ((int)$selected_month - 1) . ' months'));
        $display_filter_label = 'Monthly (Single Month): ' . date('F Y', strtotime($single_month . '-01'));
    } else {
        $display_filter_label = 'Monthly: ' . date('F Y', strtotime($month_from . '-01')) . ' to ' . date('F Y', strtotime($month_to . '-01'));
    }
}

// ============================================
// Create new Spreadsheet
// ============================================
$spreadsheet = new Spreadsheet();
date_default_timezone_set('Asia/Manila');

// ============================================
// FUNCTION: Build a worksheet for a given range
// ============================================
function buildWorksheet(
    Worksheet $sheet,
    array $rows,
    array $totals,
    string $time_frame,
    string $time_frame_display,
    string $selected_partner_name,
    string $display_filter_label,
    string $display_name,
    array $unregistered_partner_ids = []
) {
    $dataStyle = [
        'font' => ['color' => ['rgb' => '000000']],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER,
        ],
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['rgb' => '000000'],
            ],
        ],
        'fill' => ['fillType' => Fill::FILL_NONE],
    ];

    // ============================================
    // HEADER SECTION
    // ============================================
    $sheet->setCellValue('A1', 'BILLS PAYMENT DEPARTMENT');
    $sheet->getStyle('A1')->applyFromArray([
        'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => '000000']],
    ]);

    $sheet->setCellValue('A2', "VOLUME REPORT - $time_frame_display");
    $sheet->getStyle('A2')->applyFromArray([
        'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => '000000']],
    ]);

    $sheet->setCellValue('A3', '');

    $sheet->setCellValue('A4', 'Partner Name');
    $sheet->setCellValue('B4', $selected_partner_name ?: 'All Partners');
    $sheet->getStyle('A4')->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => '000000']],
    ]);
    $sheet->getStyle('B4')->applyFromArray([
        'font' => ['color' => ['rgb' => '000000']],
    ]);

    $generated_date = date('F m, Y h:i:s A');
    $sheet->setCellValue('A5', 'Generated Date');
    $sheet->setCellValue('B5', $generated_date);
    $sheet->getStyle('A5')->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => '000000']],
    ]);
    $sheet->getStyle('B5')->applyFromArray([
        'font' => ['color' => ['rgb' => '000000']],
    ]);

    $sheet->setCellValue('A6', 'Displayed Filter');
    $sheet->setCellValue('B6', $display_filter_label);
    $sheet->getStyle('A6')->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => '000000']],
    ]);
    $sheet->getStyle('B6')->applyFromArray([
        'font' => ['color' => ['rgb' => '000000']],
    ]);

    $sheet->setCellValue('A7', 'Filter Type');
    $sheet->setCellValue('B7', $time_frame_display);
    $sheet->getStyle('A7')->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => '000000']],
    ]);
    $sheet->getStyle('B7')->applyFromArray([
        'font' => ['color' => ['rgb' => '000000']],
    ]);

    $sheet->setCellValue('A8', 'Generated By');
    $sheet->setCellValue('B8', $display_name);
    $sheet->getStyle('A8')->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => '000000']],
    ]);
    $sheet->getStyle('B8')->applyFromArray([
        'font' => ['color' => ['rgb' => '000000']],
    ]);

    $sheet->setCellValue('A9', '');

    // ============================================
    // UNREGISTERED PARTNER WARNING BANNER (if any)
    // ============================================
    $header_row_offset = 0;
    if (!empty($unregistered_partner_ids)) {
        // --- Warning title row ---
        $sheet->setCellValue('A9', '⚠ WARNING: Unregistered Partner IDs detected');
        $sheet->getStyle('A9')->applyFromArray([
            'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'B26A00']],
            'fill' => [
                'fillType'   => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'FFF8E1'],
            ],
        ]);

        // --- Warning body row (merged across A:V) ---
        $warning_text = 'The following Partner IDs exist in billspayment_transaction but are NOT registered in partner_masterfile. '
                      . 'The report is showing the name from the transaction record as a fallback. Please register these IDs in the Partner Masterfile: '
                      . implode(', ', $unregistered_partner_ids);

        $sheet->setCellValue('A10', $warning_text);
        $sheet->mergeCells('A10:V10');
        $sheet->getStyle('A10')->applyFromArray([
            'font' => ['italic' => true, 'color' => ['rgb' => 'B26A00']],
            'fill' => [
                'fillType'   => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'FFF8E1'],
            ],
            'alignment' => ['wrapText' => true, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(10)->setRowHeight(45);

        $header_row_offset = 2;
        $sheet->setCellValue('A11', '');
    }

    $header_row = 10 + $header_row_offset;

    // ============================================
    // TABLE HEADERS
    // ============================================
    $sheet->setCellValue('A' . $header_row, 'No.');
    $sheet->setCellValue('B' . $header_row, 'Partner Name');
    $sheet->setCellValue('C' . $header_row, 'Charge Type');
    $sheet->setCellValue('D' . $header_row, "Biller's Name");

    $sheet->setCellValue('E' . $header_row, 'Transaction');
    $sheet->mergeCells('E' . $header_row . ':H' . $header_row);

    $sheet->setCellValue('I' . $header_row, 'Cancelled Transaction');
    $sheet->mergeCells('I' . $header_row . ':L' . $header_row);

    $sheet->setCellValue('M' . $header_row, 'NET');
    $sheet->mergeCells('M' . $header_row . ':P' . $header_row);

    $sheet->setCellValue('Q' . $header_row, 'Settlement');
    $sheet->mergeCells('Q' . $header_row . ':T' . $header_row);

    $sheet->setCellValue('U' . $header_row, 'Variance');
    $sheet->mergeCells('U' . $header_row . ':V' . $header_row);

    // Sub-header row
    $sub_row = $header_row + 1;
    $sheet->setCellValue('A' . $sub_row, '');
    $sheet->setCellValue('B' . $sub_row, '');
    $sheet->setCellValue('C' . $sub_row, '');
    $sheet->setCellValue('D' . $sub_row, '');

    $sheet->setCellValue('E' . $sub_row, 'Vol.');
    $sheet->setCellValue('F' . $sub_row, 'Amount');
    $sheet->setCellValue('G' . $sub_row, 'Partner');
    $sheet->setCellValue('H' . $sub_row, 'Customer');

    $sheet->setCellValue('I' . $sub_row, 'Vol.');
    $sheet->setCellValue('J' . $sub_row, 'Amount');
    $sheet->setCellValue('K' . $sub_row, 'Partner');
    $sheet->setCellValue('L' . $sub_row, 'Customer');

    $sheet->setCellValue('M' . $sub_row, 'Vol.');
    $sheet->setCellValue('N' . $sub_row, 'Amount');
    $sheet->setCellValue('O' . $sub_row, 'Partner');
    $sheet->setCellValue('P' . $sub_row, 'Customer');

    $sheet->setCellValue('Q' . $sub_row, 'Vol.');
    $sheet->setCellValue('R' . $sub_row, 'Amount');
    $sheet->setCellValue('S' . $sub_row, 'Partner');
    $sheet->setCellValue('T' . $sub_row, 'Customer');

    $sheet->setCellValue('U' . $sub_row, 'Vol.');
    $sheet->setCellValue('V' . $sub_row, 'Amount');

    $sheet->mergeCells('A' . $header_row . ':A' . $sub_row);
    $sheet->mergeCells('B' . $header_row . ':B' . $sub_row);
    $sheet->mergeCells('C' . $header_row . ':C' . $sub_row);
    $sheet->mergeCells('D' . $header_row . ':D' . $sub_row);

    $headerStyle = [
        'font' => [
            'bold'  => true,
            'color' => ['rgb' => '000000'],
            'size'  => 10,
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical'   => Alignment::VERTICAL_CENTER,
        ],
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color'       => ['rgb' => '000000'],
            ],
        ],
        'fill' => ['fillType' => Fill::FILL_NONE],
    ];
    $sheet->getStyle('A' . $header_row . ':V' . $sub_row)->applyFromArray($headerStyle);

    $sheet->getRowDimension($header_row)->setRowHeight(25);
    $sheet->getRowDimension($sub_row)->setRowHeight(25);

    // ============================================
    // DATA ROWS
    // ============================================
    $row = $sub_row + 1;
    $counter = 1;

    foreach ($rows as $data) {
        // Resolve display info (with fallback + unregistered flag)
        $info = resolvePartnerDisplayForExport(
            $data['partner_id_kpx'] ?? '',
            $data['sub_billers_name'] ?? '',
            $data['partner_name'] ?? '',
            $data['bt_partner_name'] ?? ''
        );
        $partner_name    = $info['name'];
        $is_unassigned   = $info['is_unassigned'];
        $is_unregistered = $info['is_unregistered'];

        $display_sub_biller = $data['sub_billers_name'] ?? '-';
        if ($is_unassigned && $display_sub_biller === '-') {
            $display_sub_biller = 'Unassigned Partner Transaction';
        }

        $charge_type_display = $data['charge_type_display'] ?? 'N/A';

        $sheet->setCellValue('A' . $row, $counter++);
        $sheet->setCellValue('B' . $row, $partner_name);
        $sheet->setCellValue('C' . $row, $charge_type_display);
        $sheet->setCellValue('D' . $row, $display_sub_biller);

        // ---- Cell styling for column B ----
        if ($is_unassigned) {
            $sheet->getStyle('B' . $row)->applyFromArray([
                'font' => ['italic' => true, 'color' => ['rgb' => '000000']],
            ]);
        } elseif ($is_unregistered) {
            // Amber italic
            $sheet->getStyle('B' . $row)->applyFromArray([
                'font' => ['italic' => true, 'color' => ['rgb' => 'B26A00']],
            ]);

            // Attach an Excel comment
            $comment = $sheet->getComment('B' . $row);
            $comment->setAuthor('System');
            $comment->getText()->createTextRun(
                'Partner ID ' . $data['partner_id_kpx'] .
                ' is not registered in the Partner Masterfile. Please register it.'
            );
            $comment->setWidth('240pt');
            $comment->setHeight('80pt');
        }

        $sheet->setCellValue('E' . $row, number_format($data['datetime_volume']));
        $sheet->setCellValue('F' . $row, $data['datetime_amount_paid']);
        $sheet->setCellValue('G' . $row, $data['datetime_charge_partner']);
        $sheet->setCellValue('H' . $row, $data['datetime_charge_customer']);

        $sheet->setCellValue('I' . $row, number_format($data['cancellation_volume']));
        $sheet->setCellValue('J' . $row, abs($data['cancellation_amount_paid']));
        $sheet->setCellValue('K' . $row, abs($data['cancellation_charge_partner']));
        $sheet->setCellValue('L' . $row, abs($data['cancellation_charge_customer']));

        $sheet->setCellValue('M' . $row, number_format($data['total_volume']));
        $sheet->setCellValue('N' . $row, $data['total_amount_paid']);
        $sheet->setCellValue('O' . $row, $data['total_charge_partner']);
        $sheet->setCellValue('P' . $row, $data['total_charge_customer']);

        $sheet->setCellValue('Q' . $row, number_format($data['settlement_volume']));
        $sheet->setCellValue('R' . $row, $data['settlement_amount_paid']);
        $sheet->setCellValue('S' . $row, $data['settlement_charge_partner']);
        $sheet->setCellValue('T' . $row, $data['settlement_charge_customer']);

        $sheet->setCellValue('U' . $row, number_format($data['variance_volume']));
        $sheet->setCellValue('V' . $row, $data['variance_amount']);

        $sheet->getStyle('A' . $row . ':V' . $row)->applyFromArray($dataStyle);

        // Number formats
        $sheet->getStyle('F' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('G' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('H' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('J' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('K' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('L' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('N' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('O' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('P' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('R' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('S' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('T' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('V' . $row)->getNumberFormat()->setFormatCode('#,##0.00');

        // NET columns bold
        $sheet->getStyle('M' . $row . ':P' . $row)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => '000000']],
        ]);

        // Alignment
        $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('B' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $sheet->getStyle('C' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $sheet->getStyle('D' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

        $row++;
    }

    // ============================================
    // TOTAL ROW
    // ============================================
    if (!empty($rows)) {
        $sheet->setCellValue('A' . $row, '');
        $sheet->setCellValue('B' . $row, '');
        $sheet->setCellValue('C' . $row, '');
        $sheet->setCellValue('D' . $row, 'TOTAL');
        $sheet->getStyle('D' . $row)->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['rgb' => '000000']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
        ]);

        $sheet->setCellValue('E' . $row, number_format($totals['datetime_volume']));
        $sheet->setCellValue('F' . $row, $totals['datetime_amount_paid']);
        $sheet->setCellValue('G' . $row, $totals['datetime_charge_partner']);
        $sheet->setCellValue('H' . $row, $totals['datetime_charge_customer']);

        $sheet->setCellValue('I' . $row, number_format($totals['cancellation_volume']));
        $sheet->setCellValue('J' . $row, abs($totals['cancellation_amount_paid']));
        $sheet->setCellValue('K' . $row, abs($totals['cancellation_charge_partner']));
        $sheet->setCellValue('L' . $row, abs($totals['cancellation_charge_customer']));

        $sheet->setCellValue('M' . $row, number_format($totals['total_volume']));
        $sheet->setCellValue('N' . $row, $totals['total_amount_paid']);
        $sheet->setCellValue('O' . $row, $totals['total_charge_partner']);
        $sheet->setCellValue('P' . $row, $totals['total_charge_customer']);

        $sheet->setCellValue('Q' . $row, number_format($totals['settlement_volume']));
        $sheet->setCellValue('R' . $row, $totals['settlement_amount_paid']);
        $sheet->setCellValue('S' . $row, $totals['settlement_charge_partner']);
        $sheet->setCellValue('T' . $row, $totals['settlement_charge_customer']);

        $sheet->setCellValue('U' . $row, number_format($totals['variance_volume']));
        $sheet->setCellValue('V' . $row, $totals['variance_amount']);

        $totalStyle = $dataStyle;
        $totalStyle['font']['bold'] = true;
        $sheet->getStyle('A' . $row . ':V' . $row)->applyFromArray($totalStyle);

        $sheet->getStyle('F' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('G' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('H' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('J' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('K' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('L' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('N' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('O' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('P' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('R' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('S' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('T' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('V' . $row)->getNumberFormat()->setFormatCode('#,##0.00');

        $sheet->getStyle('M' . $row . ':P' . $row)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => '000000']],
        ]);

        $sheet->getStyle('A' . $row . ':V' . $row)->getBorders()->getTop()->setBorderStyle(Border::BORDER_DOUBLE);
    }

    // Auto-size columns
    foreach (range('A', 'V') as $column) {
        $sheet->getColumnDimension($column)->setAutoSize(true);
    }
}

// ============================================
// BUILD SUMMARY SHEET (Sheet 1)
// ============================================
$sheet_summary = $spreadsheet->getActiveSheet();
$sheet_summary->setTitle('Summary');

$time_frame_display = strtoupper($time_frame);
if ($time_frame === 'daily') {
    $time_frame_display = 'DAILY';
} elseif ($time_frame === 'date_range') {
    $time_frame_display = 'DATE RANGE';
} elseif ($time_frame === 'monthly') {
    $time_frame_display = 'MONTHLY';
}

// Get summary data for the full range
$summary_data = getReportDataForRange($conn, $partner_id, $start_datetime, $end_datetime);

buildWorksheet(
    $sheet_summary,
    $summary_data['rows'],
    $summary_data['totals'],
    $time_frame,
    $time_frame_display,
    $selected_partner_name,
    $display_filter_label,
    $display_name,
    $summary_data['unregistered_partner_ids']
);

// ============================================
// BUILD ONE SHEET PER PERIOD (only if multi-sheet)
// ============================================
if ($create_multi_sheet && !empty($period_list)) {
    foreach ($period_list as $period) {
        $newSheet = $spreadsheet->createSheet();

        $sheet_name = $period['sheet_name'];
        $sheet_name = preg_replace('/[:\\\\\/\?\*\[\]]/', '', $sheet_name);
        $sheet_name = substr($sheet_name, 0, 31);
        if (empty($sheet_name)) {
            $sheet_name = 'Sheet';
        }
        $base_name = $sheet_name;
        $suffix = 1;
        while ($spreadsheet->sheetNameExists($sheet_name)) {
            $sheet_name = substr($base_name, 0, 28) . '_' . $suffix;
            $suffix++;
        }
        $newSheet->setTitle($sheet_name);

        $period_data = getReportDataForRange($conn, $partner_id, $period['start'], $period['end']);

        if ($time_frame === 'date_range') {
            $period_filter_label = 'Date: ' . date('F d, Y (D)', strtotime($period['start']));
        } elseif ($time_frame === 'monthly') {
            $period_filter_label = 'Month: ' . date('F Y', strtotime($period['start']));
        } else {
            $period_filter_label = $period['label'];
        }

        buildWorksheet(
            $newSheet,
            $period_data['rows'],
            $period_data['totals'],
            $time_frame,
            $time_frame_display,
            $selected_partner_name,
            $period_filter_label,
            $display_name,
            $period_data['unregistered_partner_ids']
        );
    }
}

// Set active sheet back to Summary
$spreadsheet->setActiveSheetIndex(0);

// ============================================
// Create the Excel file
// ============================================
$filename = 'Volume_Report_' . date('Y-m-d_His') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
?>