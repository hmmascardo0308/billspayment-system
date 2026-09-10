<?php
// export_volume.php - Export volume report to Excel
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

// Start session and check permissions
session_start();
@include_once __DIR__ . '/../../../templates/middleware.php';
$id = resolve_user_identifier();
if (empty($id)) { header('Location: ../../../login_form.php'); exit; }
if (!function_exists('has_any_permission') || !has_any_permission(['Volume Report','Bills Payment'])) { header('Location: ../../home.php'); exit; }

// Get current user email and display name
$current_user_email = $_SESSION['admin_email'] ?? $_SESSION['user_email'] ?? '';
$display_name = $_SESSION['admin_name'] ?? $_SESSION['user_name'] ?? $current_user_email;

// Get filter parameters from GET
$partner_id = $_GET['partner_id'] ?? '';
$time_frame = $_GET['time_frame'] ?? 'date_range';
$date_from = $_GET['date_from'] ?? date('Y-m-d');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$month_from = $_GET['month_from'] ?? date('Y-m');
$month_to = $_GET['month_to'] ?? date('Y-m');
$date_from_daily = $_GET['date_from_daily'] ?? date('Y-m-d');
$selected_day = $_GET['selected_day'] ?? 'all';
$selected_month = $_GET['selected_month'] ?? 'all';

// Fix date handling
if ($time_frame === 'daily') {
    $date_from = $date_from_daily;
    $date_to = $date_from_daily;
}

// ============================================
// Calculate start and end datetime for use in queries
// ============================================
$start_datetime = '';
$end_datetime = '';

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

// Function to build WHERE clause
function buildWhereClauseForExport(
    string $time_frame,
    string|int $partner_id,
    ?string $date_from,
    ?string $date_to,
    ?string $month_from,
    ?string $month_to,
    ?string $selected_day = null,
    ?string $selected_month = null
) {
    global $conn;
    
    $conditions = [];
    
    if (!empty($partner_id)) {
        $conditions[] = "bt.partner_id_kpx = '" . mysqli_real_escape_string($conn, $partner_id) . "'";
    }
    
    switch ($time_frame) {
        case 'daily':
            if (!empty($date_from)) {
                $start_datetime = $date_from . ' 00:00:00';
                $end_datetime = $date_from . ' 23:59:59';
                $conditions[] = "((bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND (bt.status IS NULL OR bt.status = '')) OR bt.cancellation_date BETWEEN '$start_datetime' AND '$end_datetime')";
            }
            break;
            
        case 'date_range':
            if (!empty($date_from) && !empty($date_to)) {
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
// Function to clean partner name for display
// ============================================
function cleanPartnerNameForExport($partner_id, $sub_billers_name = '') {
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

// Get partner name for display (for the filter summary)
$selected_partner_name = '';
if (!empty($partner_id)) {
    $name_query = "SELECT partner_name FROM masterdata.partner_masterfile WHERE partner_id_kpx = '" . mysqli_real_escape_string($conn, $partner_id) . "'";
    $name_result = mysqli_query($conn, $name_query);
    if ($name_row = mysqli_fetch_assoc($name_result)) {
        $selected_partner_name = $name_row['partner_name'];
    }
}

// Build the WHERE clause
$where_clause = buildWhereClauseForExport($time_frame, $partner_id, $date_from, $date_to, $month_from, $month_to, $selected_day, $selected_month);

// ============================================
// QUERY - With partner_masterfile JOIN and all charge fields
// ============================================
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
                WHEN UPPER(COALESCE(pm.charge_to, '')) = 'CUSTOMER' 
                     AND UPPER(COALESCE(pm.serviceCharge, '')) = 'DAILY'
                THEN bt.amount_paid - IFNULL(bt.charge_to_partner, 0)
                WHEN UPPER(COALESCE(pm.charge_to, '')) = 'BOTH'
                THEN bt.amount_paid - IFNULL(bt.charge_to_partner, 0)
                WHEN UPPER(COALESCE(pm.charge_to, '')) = 'PARTNER'
                THEN bt.amount_paid
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
    CASE WHEN pm.partner_name IS NULL THEN 1 ELSE 0 END,
    pm.partner_name ASC,
    total_volume DESC";

$results = mysqli_query($conn, $query);

// Debug: Check for query errors
if (!$results) {
    error_log("MySQL Error: " . mysqli_error($conn));
    error_log("Query: " . $query);
}

// Prepare display data with variance calculations
$display_results = [];
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

while ($row = mysqli_fetch_assoc($results)) {
    // Calculate variance for this row
    $row['variance_volume'] = ($row['total_volume'] ?? 0) - ($row['settlement_volume'] ?? 0);
    
    // Check charge type for variance calculation
    $charge_to = $row['charge_to'] ?? '';
    $serviceCharge = $row['serviceCharge'] ?? '';
    $is_partner_charge = (strtoupper($charge_to) === 'PARTNER');
    $is_customer_daily = (strtoupper($charge_to) === 'CUSTOMER' && strtoupper($serviceCharge) === 'DAILY');
    $is_customer_non_daily = (strtoupper($charge_to) === 'CUSTOMER' && strtoupper($serviceCharge) !== 'DAILY');
    $is_both = (strtoupper($charge_to) === 'BOTH');
    
    if ($is_partner_charge || $is_customer_non_daily) {
        $row['variance_amount'] = ($row['total_amount_paid'] ?? 0) - ($row['settlement_amount_paid'] ?? 0);
    } elseif ($is_customer_daily) {
        $row['variance_amount'] = ($row['total_amount_paid'] ?? 0) - (($row['settlement_amount_paid'] ?? 0) + ($row['settlement_charge_partner'] ?? 0));
    } elseif ($is_both) {
        $row['variance_amount'] = ($row['total_amount_paid'] ?? 0) - (($row['settlement_amount_paid'] ?? 0) + ($row['settlement_charge'] ?? 0));
    } else {
        $row['variance_amount'] = ($row['total_amount_paid'] ?? 0) - (($row['settlement_amount_paid'] ?? 0) + ($row['settlement_charge'] ?? 0));
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
    $total_volume += $row['total_volume'];
    $total_amount += $row['total_amount_paid'];
    $total_charge_partner += $row['total_charge_partner'];
    $total_charge_customer += $row['total_charge_customer'];
    $total_charge += $row['total_charge'];
    $total_settlement_volume += $row['settlement_volume'];
    $total_settlement_amount += $row['settlement_amount_paid'];
    $total_settlement_charge_partner += $row['settlement_charge_partner'];
    $total_settlement_charge_customer += $row['settlement_charge_customer'];
    $total_settlement_charge += $row['settlement_charge'];
    $total_variance_volume += $row['variance_volume'];
    $total_variance_amount += $row['variance_amount'];
}

// Create new Spreadsheet
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Set timezone to Asia/Manila
date_default_timezone_set('Asia/Manila');

// HEADER SECTION
// Row 1: BILLS PAYMENT DEPARTMENT - Centered, Bold
$sheet->setCellValue('A1', 'BILLS PAYMENT DEPARTMENT');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16)->getColor()->setRGB('000000');

// Row 2: VOLUME REPORT
$time_frame_display = strtoupper($time_frame);
if ($time_frame === 'daily') {
    $time_frame_display = 'DAILY';
} elseif ($time_frame === 'date_range') {
    $time_frame_display = 'DATE RANGE';
} elseif ($time_frame === 'monthly') {
    $time_frame_display = 'MONTHLY';
}
$sheet->setCellValue('A2', "VOLUME REPORT - $time_frame_display");
$sheet->getStyle('A2')->getFont()->setBold(true)->setSize(14)->getColor()->setRGB('000000');

// Row 3: Empty row
$sheet->setCellValue('A3', '');

// Row 4: Partners
$sheet->setCellValue('A4', 'Partner Name');
$sheet->setCellValue('B4', $selected_partner_name ?: 'All Partners');
$sheet->getStyle('A4')->getFont()->setBold(true)->getColor()->setRGB('000000');
$sheet->getStyle('B4')->getFont()->getColor()->setRGB('000000');

// Row 5: Generated Date
$generated_date = date('F m, Y h:i:s A');
$sheet->setCellValue('A5', 'Generated Date');
$sheet->setCellValue('B5', $generated_date);
$sheet->getStyle('A5')->getFont()->setBold(true)->getColor()->setRGB('000000');
$sheet->getStyle('B5')->getFont()->getColor()->setRGB('000000');

// Row 6: Filtered Date
$filtered_date = '';
if ($time_frame === 'daily') {
    $filtered_date = date('F d, Y', strtotime($date_from));
} elseif ($time_frame === 'date_range') {
    $filtered_date = date('F d, Y', strtotime($date_from)) . ' to ' . date('F d, Y', strtotime($date_to));
    if ($selected_day && $selected_day !== 'all') {
        $selected_date = date('Y-m-d', strtotime($date_from . ' + ' . ($selected_day - 1) . ' days'));
        $filtered_date .= " (Day $selected_day: " . date('F d, Y', strtotime($selected_date)) . ')';
    }
} elseif ($time_frame === 'monthly') {
    $filtered_date = date('F Y', strtotime($month_from . '-01')) . ' to ' . date('F Y', strtotime($month_to . '-01'));
    if ($selected_month && $selected_month !== 'all') {
        $selected_month_date = date('Y-m', strtotime($month_from . ' + ' . ($selected_month - 1) . ' months'));
        $filtered_date .= " (Month $selected_month: " . date('F Y', strtotime($selected_month_date . '-01')) . ')';
    }
}
$sheet->setCellValue('A6', 'Filtered Date');
$sheet->setCellValue('B6', $filtered_date);
$sheet->getStyle('A6')->getFont()->setBold(true)->getColor()->setRGB('000000');
$sheet->getStyle('B6')->getFont()->getColor()->setRGB('000000');

// Row 7: Filter Type
$sheet->setCellValue('A7', 'Filter Type');
$sheet->setCellValue('B7', $time_frame_display);
$sheet->getStyle('A7')->getFont()->setBold(true)->getColor()->setRGB('000000');
$sheet->getStyle('B7')->getFont()->getColor()->setRGB('000000');

// Row 8: Generated By
$sheet->setCellValue('A8', 'Generated By');
$sheet->setCellValue('B8', $display_name);
$sheet->getStyle('A8')->getFont()->setBold(true)->getColor()->setRGB('000000');
$sheet->getStyle('B8')->getFont()->getColor()->setRGB('000000');

// Row 9: Empty row before table
$sheet->setCellValue('A9', '');

// ============================================
// TABLE HEADERS
// Columns: No., Partner Name, Charge Type, Biller's Name, 
//          Transaction (Vol, Amount, Partner, Customer), 
//          Cancelled (Vol, Amount, Partner, Customer),
//          NET (Vol, Amount, Partner, Customer),
//          Settlement (Vol, Amount, Partner, Customer),
//          Variance (Vol, Amount)
// ============================================

// ROW 10 - Main header row with rowspans
$sheet->setCellValue('A10', 'No.');
$sheet->setCellValue('B10', 'Partner Name');
$sheet->setCellValue('C10', 'Charge Type');
$sheet->setCellValue('D10', "Biller's Name");

// Transaction group (columns E-H)
$sheet->setCellValue('E10', 'Transaction');
$sheet->mergeCells('E10:H10');

// Cancelled group (columns I-L)
$sheet->setCellValue('I10', 'Cancelled Transaction');
$sheet->mergeCells('I10:L10');

// NET group (columns M-P)
$sheet->setCellValue('M10', 'NET');
$sheet->mergeCells('M10:P10');

// Settlement group (columns Q-T)
$sheet->setCellValue('Q10', 'Settlement');
$sheet->mergeCells('Q10:T10');

// Variance group (columns U-V)
$sheet->setCellValue('U10', 'Variance');
$sheet->mergeCells('U10:V10');

// ROW 11 - Sub-header row
// Columns A-D: Leave empty (these will be merged from row 10)
$sheet->setCellValue('A11', '');
$sheet->setCellValue('B11', '');
$sheet->setCellValue('C11', '');
$sheet->setCellValue('D11', '');

// Transaction sub-headers (E-H)
$sheet->setCellValue('E11', 'Vol.');
$sheet->setCellValue('F11', 'Amount');
$sheet->setCellValue('G11', 'Partner');
$sheet->setCellValue('H11', 'Customer');

// Cancelled sub-headers (I-L)
$sheet->setCellValue('I11', 'Vol.');
$sheet->setCellValue('J11', 'Amount');
$sheet->setCellValue('K11', 'Partner');
$sheet->setCellValue('L11', 'Customer');

// NET sub-headers (M-P)
$sheet->setCellValue('M11', 'Vol.');
$sheet->setCellValue('N11', 'Amount');
$sheet->setCellValue('O11', 'Partner');
$sheet->setCellValue('P11', 'Customer');

// Settlement sub-headers (Q-T)
$sheet->setCellValue('Q11', 'Vol.');
$sheet->setCellValue('R11', 'Amount');
$sheet->setCellValue('S11', 'Partner');
$sheet->setCellValue('T11', 'Customer');

// Variance sub-headers (U-V)
$sheet->setCellValue('U11', 'Vol.');
$sheet->setCellValue('V11', 'Amount');

// MERGE cells for rowspan (A-D spanning rows 10-11)
$sheet->mergeCells('A10:A11');
$sheet->mergeCells('B10:B11');
$sheet->mergeCells('C10:C11');
$sheet->mergeCells('D10:D11');

// Style the header rows (both rows 10 and 11) - No background, black text
$headerStyle = [
    'font' => [
        'bold' => true,
        'color' => ['rgb' => '000000'],
        'size' => 10,
    ],
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
    'fill' => [
        'fillType' => Fill::FILL_NONE,
    ],
];

// Apply header style to both rows
$sheet->getStyle('A10:V11')->applyFromArray($headerStyle);

// Set auto-width for all columns
foreach (range('A', 'V') as $column) {
    $sheet->getColumnDimension($column)->setAutoSize(true);
}

// Set row heights for header rows
$sheet->getRowDimension(10)->setRowHeight(25);
$sheet->getRowDimension(11)->setRowHeight(25);

// DATA ROWS - Starting from row 12
$row = 12;
$counter = 1;

// Define style for data rows - No background, black text
$dataStyle = [
    'font' => [
        'color' => ['rgb' => '000000'],
    ],
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
    'fill' => [
        'fillType' => Fill::FILL_NONE,
    ],
];

foreach ($display_results as $data) {
    // Clean partner name
    $partner_name = cleanPartnerNameForExport($data['partner_id_kpx'], $data['sub_billers_name']);
    
    // Check if this is an unassigned partner
    $is_unassigned = (strpos($data['partner_id_kpx'], 'UNKNOWN_') === 0);
    
    // Get the sub_billers_name for display
    $display_sub_biller = $data['sub_billers_name'] ?? '-';
    if ($is_unassigned && $display_sub_biller === '-') {
        $display_sub_biller = 'Unassigned Partner Transaction';
    }
    
    // Get charge type display
    $charge_type_display = $data['charge_type_display'] ?? 'N/A';
    
    $sheet->setCellValue('A' . $row, $counter++);
    $sheet->setCellValue('B' . $row, $partner_name);
    $sheet->setCellValue('C' . $row, $charge_type_display);
    $sheet->setCellValue('D' . $row, $display_sub_biller);
    
    // Apply italic style for unassigned partners (but keep text black)
    if ($is_unassigned) {
        $sheet->getStyle('B' . $row)->getFont()->setItalic(true)->getColor()->setRGB('000000');
    }
    
    // TRANSACTION columns (E-H)
    $sheet->setCellValue('E' . $row, number_format($data['datetime_volume']));
    $sheet->setCellValue('F' . $row, $data['datetime_amount_paid']);
    $sheet->setCellValue('G' . $row, $data['datetime_charge_partner']);
    $sheet->setCellValue('H' . $row, $data['datetime_charge_customer']);
    
    // CANCELLED columns (I-L)
    $sheet->setCellValue('I' . $row, number_format($data['cancellation_volume']));
    $sheet->setCellValue('J' . $row, abs($data['cancellation_amount_paid']));
    $sheet->setCellValue('K' . $row, abs($data['cancellation_charge_partner']));
    $sheet->setCellValue('L' . $row, abs($data['cancellation_charge_customer']));
    
    // NET columns (M-P)
    $sheet->setCellValue('M' . $row, number_format($data['total_volume']));
    $sheet->setCellValue('N' . $row, $data['total_amount_paid']);
    $sheet->setCellValue('O' . $row, $data['total_charge_partner']);
    $sheet->setCellValue('P' . $row, $data['total_charge_customer']);
    
    // SETTLEMENT columns (Q-T)
    $sheet->setCellValue('Q' . $row, number_format($data['settlement_volume']));
    $sheet->setCellValue('R' . $row, $data['settlement_amount_paid']);
    $sheet->setCellValue('S' . $row, $data['settlement_charge_partner']);
    $sheet->setCellValue('T' . $row, $data['settlement_charge_customer']);
    
    // VARIANCE columns (U-V)
    $sheet->setCellValue('U' . $row, number_format($data['variance_volume']));
    $sheet->setCellValue('V' . $row, $data['variance_amount']);
    
    // Apply data style to all columns
    $sheet->getStyle('A' . $row . ':V' . $row)->applyFromArray($dataStyle);
    
    // Apply number formatting for currency columns
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
    
    // Bold the NET columns
    $sheet->getStyle('M' . $row . ':P' . $row)->getFont()->setBold(true)->getColor()->setRGB('000000');
    
    // Left align text columns
    $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('B' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $sheet->getStyle('C' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $sheet->getStyle('D' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    
    $row++;
}

// TOTAL ROW
if (!empty($display_results)) {
    $sheet->setCellValue('A' . $row, '');
    $sheet->setCellValue('B' . $row, '');
    $sheet->setCellValue('C' . $row, '');
    $sheet->setCellValue('D' . $row, 'TOTAL');
    $sheet->getStyle('D' . $row)->getFont()->setBold(true)->getColor()->setRGB('000000');
    $sheet->getStyle('D' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    
    // Transaction totals
    $sheet->setCellValue('E' . $row, number_format($total_datetime_volume));
    $sheet->setCellValue('F' . $row, $total_datetime_amount);
    $sheet->setCellValue('G' . $row, $total_datetime_charge_partner);
    $sheet->setCellValue('H' . $row, $total_datetime_charge_customer);
    
    // Cancelled totals
    $sheet->setCellValue('I' . $row, number_format($total_cancellation_volume));
    $sheet->setCellValue('J' . $row, abs($total_cancellation_amount));
    $sheet->setCellValue('K' . $row, abs($total_cancellation_charge_partner));
    $sheet->setCellValue('L' . $row, abs($total_cancellation_charge_customer));
    
    // NET totals
    $sheet->setCellValue('M' . $row, number_format($total_volume));
    $sheet->setCellValue('N' . $row, $total_amount);
    $sheet->setCellValue('O' . $row, $total_charge_partner);
    $sheet->setCellValue('P' . $row, $total_charge_customer);
    
    // Settlement totals
    $sheet->setCellValue('Q' . $row, number_format($total_settlement_volume));
    $sheet->setCellValue('R' . $row, $total_settlement_amount);
    $sheet->setCellValue('S' . $row, $total_settlement_charge_partner);
    $sheet->setCellValue('T' . $row, $total_settlement_charge_customer);
    
    // Variance totals
    $sheet->setCellValue('U' . $row, number_format($total_variance_volume));
    $sheet->setCellValue('V' . $row, $total_variance_amount);
    
    // Apply data style to Total
    $totalStyle = $dataStyle;
    $totalStyle['font']['bold'] = true;
    $sheet->getStyle('A' . $row . ':V' . $row)->applyFromArray($totalStyle);
    
    // Apply number formatting for Total
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
    
    // Bold the NET columns in total row
    $sheet->getStyle('M' . $row . ':P' . $row)->getFont()->setBold(true)->getColor()->setRGB('000000');
    
    // Add double border on top of Total
    $sheet->getStyle('A' . $row . ':V' . $row)->getBorders()->getTop()->setBorderStyle(Border::BORDER_DOUBLE);
}

// Auto-size columns for all columns
foreach (range('A', 'V') as $column) {
    $sheet->getColumnDimension($column)->setAutoSize(true);
}

// Create the Excel file
$filename = 'Volume_Report_' . date('Y-m-d_His') . '.xlsx';

// Set headers for download
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

// Write the file to output
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
?>