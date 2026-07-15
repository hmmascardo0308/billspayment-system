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

// Build the query to fetch settlement data
try {
    $where_conditions = [];
    $params = [];
    $types = "";
    
    if (!empty($selected_partner)) {
        $where_conditions[] = "bt.partner_id_kpx = ?";
        $params[] = $selected_partner;
        $types .= "s";
    }
    
    if (!empty($selected_bank)) {
        $where_conditions[] = "pm.bank = ?";
        $params[] = $selected_bank;
        $types .= "s";
    }
    
    if (!empty($selected_settlement_type)) {
        $where_conditions[] = "pm.settled_online_check = ?";
        $params[] = $selected_settlement_type;
        $types .= "s";
    }
    
    // UPDATED: Date range filters - Check both datetime and cancellation_date (same logic as main query)
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
    
    if (!empty($params)) {
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
        } else {
            $result = false;
        }
    } else {
        $result = $conn->query($sql);
    }
    
    // UPDATED GROUPING
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
        ]
    ];
    
    $grand_totals = ['txn_count' => 0, 'principal' => 0, 'charge' => 0, 'adjustment' => 0, 'settlement' => 0];
    
    // Store all rows with their indices for filtering
    $all_rows = [];
    $row_index = 0;
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $charge_to = strtoupper($row['charge_to'] ?? '');
            $serviceCharge = strtoupper($row['serviceCharge'] ?? '');
            
            // UPDATED GROUPING LOGIC
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
            
            // Store the row with its index
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
                'group_key' => $group_key
            ];
            
            $all_rows[] = $row_data;
            $row_index++;
        }
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

// HIDE COLUMNS D THROUGH G - can be unhidden by user
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

// Row 10: Headers
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
    
    // Group header
    $sheet->mergeCells('A' . $row . ':H' . $row);
    $sheet->setCellValue('A' . $row, $group_data['display_name']);
    $sheet->getStyle('A' . $row)->getFont()->setBold(true);
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
    
    // Group subtotal row
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
    $sheet->getStyle('A' . $row . ':H' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('E8F4FD');
    $sheet->getStyle('A' . $row . ':H' . $row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $row++;
    
    // Add a blank row between groups (except after the last group)
    $row++;
}

// Grand Total - UPDATED to include all groups
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