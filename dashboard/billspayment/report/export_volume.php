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
// FIX: Calculate start and end datetime for use in queries
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
                $conditions[] = "(bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' OR bt.cancellation_date BETWEEN '$start_datetime' AND '$end_datetime')";
            }
            break;
            
        case 'date_range':
            if (!empty($date_from) && !empty($date_to)) {
                if ($selected_day && $selected_day !== 'all') {
                    $selected_date = date('Y-m-d', strtotime($date_from . ' + ' . ($selected_day - 1) . ' days'));
                    $start_datetime = $selected_date . ' 00:00:00';
                    $end_datetime = $selected_date . ' 23:59:59';
                    $conditions[] = "(bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' OR bt.cancellation_date BETWEEN '$start_datetime' AND '$end_datetime')";
                } else {
                    $start_datetime = $date_from . ' 00:00:00';
                    $end_datetime = $date_to . ' 23:59:59';
                    $conditions[] = "(bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' OR bt.cancellation_date BETWEEN '$start_datetime' AND '$end_datetime')";
                }
            }
            break;
            
        case 'monthly':
            if (!empty($month_from) && !empty($month_to)) {
                if ($selected_month && $selected_month !== 'all') {
                    $selected_month_date = date('Y-m', strtotime($month_from . ' + ' . ($selected_month - 1) . ' months'));
                    $start_datetime = $selected_month_date . '-01 00:00:00';
                    $end_datetime = date('Y-m-t 23:59:59', strtotime($selected_month_date . '-01'));
                    $conditions[] = "(bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' OR bt.cancellation_date BETWEEN '$start_datetime' AND '$end_datetime')";
                } else {
                    $start_datetime = $month_from . '-01 00:00:00';
                    $end_datetime = date('Y-m-t 23:59:59', strtotime($month_to . '-01'));
                    $conditions[] = "(bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' OR bt.cancellation_date BETWEEN '$start_datetime' AND '$end_datetime')";
                }
            }
            break;
    }
    
    return !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';
}

// Get partner name
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
// FIX: Updated query to properly separate normal and cancelled transactions
// ============================================
$query = "SELECT 
            bt.partner_id_kpx,
            CASE 
                WHEN bt.sub_billers_name IS NULL OR bt.sub_billers_name = '' THEN '-'
                ELSE bt.sub_billers_name
            END as sub_billers_name,
            -- Normal Transactions (datetime between range AND cancellation_date IS NULL)
            COUNT(CASE WHEN bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND bt.cancellation_date IS NULL THEN 1 END) as datetime_volume,
            SUM(CASE WHEN bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND bt.cancellation_date IS NULL THEN bt.amount_paid ELSE 0 END) as datetime_amount_paid,
            SUM(CASE WHEN bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND bt.cancellation_date IS NULL THEN (bt.charge_to_partner + bt.charge_to_customer) ELSE 0 END) as datetime_charge,
            -- Cancelled Transactions (cancellation_date between range)
            COUNT(CASE WHEN bt.cancellation_date BETWEEN '$start_datetime' AND '$end_datetime' THEN 1 END) as cancellation_volume,
            SUM(CASE WHEN bt.cancellation_date BETWEEN '$start_datetime' AND '$end_datetime' THEN bt.amount_paid ELSE 0 END) as cancellation_amount_paid,
            SUM(CASE WHEN bt.cancellation_date BETWEEN '$start_datetime' AND '$end_datetime' THEN (bt.charge_to_partner + bt.charge_to_customer) ELSE 0 END) as cancellation_charge,
            -- NET values (datetime - cancellation)
            (COUNT(CASE WHEN bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND bt.cancellation_date IS NULL THEN 1 END) - 
             COUNT(CASE WHEN bt.cancellation_date BETWEEN '$start_datetime' AND '$end_datetime' THEN 1 END)) as total_volume,
            (SUM(CASE WHEN bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND bt.cancellation_date IS NULL THEN bt.amount_paid ELSE 0 END) + 
             SUM(CASE WHEN bt.cancellation_date BETWEEN '$start_datetime' AND '$end_datetime' THEN bt.amount_paid ELSE 0 END)) as total_amount_paid,
            (SUM(CASE WHEN bt.datetime BETWEEN '$start_datetime' AND '$end_datetime' AND bt.cancellation_date IS NULL THEN (bt.charge_to_partner + bt.charge_to_customer) ELSE 0 END) + 
             SUM(CASE WHEN bt.cancellation_date BETWEEN '$start_datetime' AND '$end_datetime' THEN (bt.charge_to_partner + bt.charge_to_customer) ELSE 0 END)) as total_charge
          FROM mldb.billspayment_transaction bt
          $where_clause
          GROUP BY bt.partner_id_kpx, 
            CASE 
                WHEN bt.sub_billers_name IS NULL OR bt.sub_billers_name = '' THEN '-'
                ELSE bt.sub_billers_name
            END
          ORDER BY bt.partner_id_kpx, total_volume DESC";

$results = mysqli_query($conn, $query);

// Get partner names mapping
$partner_names = [];
$name_query = "SELECT partner_id_kpx, partner_name FROM masterdata.partner_masterfile";
$name_result = mysqli_query($conn, $name_query);
while ($name_row = mysqli_fetch_assoc($name_result)) {
    $partner_names[$name_row['partner_id_kpx']] = $name_row['partner_name'];
}

// Prepare display data
$display_results = [];
$total_datetime_volume = 0;
$total_datetime_amount = 0;
$total_datetime_charge = 0;
$total_cancellation_volume = 0;
$total_cancellation_amount = 0;
$total_cancellation_charge = 0;
$total_volume = 0;
$total_amount = 0;
$total_charge = 0;

while ($row = mysqli_fetch_assoc($results)) {
    $display_results[] = $row;
    $total_datetime_volume += $row['datetime_volume'];
    $total_datetime_amount += $row['datetime_amount_paid'];
    $total_datetime_charge += $row['datetime_charge'];
    $total_cancellation_volume += $row['cancellation_volume'];
    $total_cancellation_amount += $row['cancellation_amount_paid'];
    $total_cancellation_charge += $row['cancellation_charge'];
    $total_volume += $row['total_volume'];
    $total_amount += $row['total_amount_paid'];
    $total_charge += $row['total_charge'];
}

// Create new Spreadsheet
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Set timezone to Asia/Manila
date_default_timezone_set('Asia/Manila');

// HEADER SECTION
// Row 1: BILLS PAYMENT DEPARTMENT - Centered, Bold
$sheet->setCellValue('A1', 'BILLS PAYMENT DEPARTMENT');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);

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
$sheet->getStyle('A2')->getFont()->setBold(true)->setSize(14);

// Row 3: Empty row
$sheet->setCellValue('A3', '');

// Row 4: Partners
$sheet->setCellValue('A4', 'Partner Name');
$sheet->setCellValue('B4', $selected_partner_name ?: 'All Partners');
$sheet->getStyle('A4')->getFont()->setBold(true);

// Row 5: Generated Date
$generated_date = date('F m, Y h:i:s A');
$sheet->setCellValue('A5', 'Generated Date');
$sheet->setCellValue('B5', $generated_date);
$sheet->getStyle('A5')->getFont()->setBold(true);

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
$sheet->getStyle('A6')->getFont()->setBold(true);

// Row 7: Filter Type
$sheet->setCellValue('A7', 'Filter Type');
$sheet->setCellValue('B7', $time_frame_display);
$sheet->getStyle('A7')->getFont()->setBold(true);

// Row 8: Generated By
$sheet->setCellValue('A8', 'Generated By');
$sheet->setCellValue('B8', $display_name);
$sheet->getStyle('A8')->getFont()->setBold(true);

// Row 9: Empty row before table
$sheet->setCellValue('A9', '');

// ============================================
// TABLE HEADERS - With proper rowspan (2 rows)
// ============================================

// ROW 10 - Main header row
// Columns A-C: Main headers that will span 2 rows
$sheet->setCellValue('A10', 'No.');
$sheet->setCellValue('B10', 'Partner Name');
$sheet->setCellValue('C10', "Biller's Name");

// Columns D-F: Datetime group header (spans 3 columns)
$sheet->setCellValue('D10', 'Normal Transaction');
$sheet->mergeCells('D10:F10');

// Columns G-I: Cancellation group header (spans 3 columns)
$sheet->setCellValue('G10', 'Cancelled Transaction');
$sheet->mergeCells('G10:I10');

// Columns J-L: Net group header (spans 3 columns)
$sheet->setCellValue('J10', 'Net');
$sheet->mergeCells('J10:L10');

// ROW 11 - Sub-header row
// Columns A-C: Leave empty (these will be merged from row 10)
$sheet->setCellValue('A11', '');
$sheet->setCellValue('B11', '');
$sheet->setCellValue('C11', '');

// Columns D-F: Datetime sub-headers
$sheet->setCellValue('D11', 'Vol.');
$sheet->setCellValue('E11', 'Principal');
$sheet->setCellValue('F11', 'Charge');

// Columns G-I: Cancellation sub-headers
$sheet->setCellValue('G11', 'Vol.');
$sheet->setCellValue('H11', 'Principal');
$sheet->setCellValue('I11', 'Charge');

// Columns J-L: Net sub-headers
$sheet->setCellValue('J11', 'Vol.');
$sheet->setCellValue('K11', 'Principal');
$sheet->setCellValue('L11', 'Charge');

// MERGE cells for rowspan (A-C spanning rows 10-11)
$sheet->mergeCells('A10:A11');
$sheet->mergeCells('B10:B11');
$sheet->mergeCells('C10:C11');

// Style the header rows (both rows 10 and 11)
$headerStyle = [
    'font' => [
        'bold' => true,
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
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => 'F0F0F0'],
    ],
];

// Apply header style to both rows
$sheet->getStyle('A10:L11')->applyFromArray($headerStyle);

// Set auto-width for all columns
foreach (range('A', 'L') as $column) {
    $sheet->getColumnDimension($column)->setAutoSize(true);
}

// Set row heights for header rows
$sheet->getRowDimension(10)->setRowHeight(25);
$sheet->getRowDimension(11)->setRowHeight(25);

// DATA ROWS - Starting from row 12
$row = 12;
$counter = 1;

// Define style for data rows
$dataStyle = [
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
];

// Define column-specific styles
$normalStyle = [
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
    ],
];

$cancelledStyle = [
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
    ],
];

$netStyle = [
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
    ],
];

foreach ($display_results as $data) {
    $partner_name = $partner_names[$data['partner_id_kpx']] ?? $data['partner_id_kpx'];
    
    $sheet->setCellValue('A' . $row, $counter++);
    $sheet->setCellValue('B' . $row, $partner_name);
    $sheet->setCellValue('C' . $row, $data['sub_billers_name'] ?? '-');
    
    // NORMAL columns (D, E, F)
    $sheet->setCellValue('D' . $row, number_format($data['datetime_volume']));
    $sheet->setCellValue('E' . $row, $data['datetime_amount_paid']);
    $sheet->setCellValue('F' . $row, $data['datetime_charge']);
    
    // CANCELLED columns (G, H, I) - display as positive numbers
    $sheet->setCellValue('G' . $row, number_format($data['cancellation_volume']));
    $sheet->setCellValue('H' . $row, abs($data['cancellation_amount_paid']));
    $sheet->setCellValue('I' . $row, abs($data['cancellation_charge']));
    
    // NET columns (J, K, L)
    $sheet->setCellValue('J' . $row, number_format($data['total_volume']));
    $sheet->setCellValue('K' . $row, $data['total_amount_paid']);
    $sheet->setCellValue('L' . $row, $data['total_charge']);
    
    // Apply data style
    $sheet->getStyle('A' . $row . ':L' . $row)->applyFromArray($dataStyle);
    
    // Apply column-specific background colors
    $sheet->getStyle('D' . $row . ':F' . $row)->applyFromArray($normalStyle);
    $sheet->getStyle('G' . $row . ':I' . $row)->applyFromArray($cancelledStyle);
    $sheet->getStyle('J' . $row . ':L' . $row)->applyFromArray($netStyle);
    
    // Apply number formatting for currency columns
    $sheet->getStyle('E' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle('F' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle('H' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle('I' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle('K' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle('L' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
    
    // Bold the NET columns
    $sheet->getStyle('J' . $row . ':L' . $row)->getFont()->setBold(true);
    
    // Left align text columns
    $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('B' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $sheet->getStyle('C' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    
    $row++;
}

// TOTAL ROW
if (!empty($display_results)) {
    $sheet->setCellValue('A' . $row, '');
    $sheet->setCellValue('B' . $row, '');
    $sheet->setCellValue('C' . $row, 'TOTAL');
    $sheet->getStyle('C' . $row)->getFont()->setBold(true);
    $sheet->getStyle('C' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    
    $sheet->setCellValue('D' . $row, number_format($total_datetime_volume));
    $sheet->setCellValue('E' . $row, $total_datetime_amount);
    $sheet->setCellValue('F' . $row, $total_datetime_charge);
    $sheet->setCellValue('G' . $row, number_format($total_cancellation_volume));
    $sheet->setCellValue('H' . $row, abs($total_cancellation_amount));
    $sheet->setCellValue('I' . $row, abs($total_cancellation_charge));
    $sheet->setCellValue('J' . $row, number_format($total_volume));
    $sheet->setCellValue('K' . $row, $total_amount);
    $sheet->setCellValue('L' . $row, $total_charge);
    
    // Apply data style to Total
    $sheet->getStyle('A' . $row . ':L' . $row)->applyFromArray($dataStyle);
    
    // Apply column-specific background colors to Total
    $sheet->getStyle('D' . $row . ':F' . $row)->applyFromArray($normalStyle);
    $sheet->getStyle('G' . $row . ':I' . $row)->applyFromArray($cancelledStyle);
    $sheet->getStyle('J' . $row . ':L' . $row)->applyFromArray($netStyle);
    
    // Apply number formatting for Total
    $sheet->getStyle('E' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle('F' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle('H' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle('I' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle('K' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle('L' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
    
    // Make Total row bold
    $sheet->getStyle('C' . $row . ':L' . $row)->getFont()->setBold(true);
    
    // Add double border on top of Total
    $sheet->getStyle('A' . $row . ':L' . $row)->getBorders()->getTop()->setBorderStyle(Border::BORDER_DOUBLE);
}

// Auto-size columns for all columns
foreach (range('A', 'L') as $column) {
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