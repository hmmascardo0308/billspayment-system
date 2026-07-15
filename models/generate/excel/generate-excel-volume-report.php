<?php
// Connect to the database
require_once __DIR__ . '/../../../config/config.php';
require '../../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

// Start the session
session_start();

if (!isset($_SESSION['user_type'])) {
    http_response_code(403);
    exit('Unauthorized access');
}

if (isset($_POST['action']) && $_POST['action'] === 'export_excel') {
    $partner = $_POST['partner'];
    $filterType = $_POST['filterType'];
    $startDate = $_POST['startDate'];
    $endDate = $_POST['endDate'];

    // Convert date formats based on filter type
    // Keep original inputs for display decisions
    $origStartDate = $startDate;
    $origEndDate = $endDate;

    if ($filterType === 'daily') {
        // Daily: single date or date range if endDate provided
        $filterType1 = 'Daily';
        if (!empty($endDate) && $endDate !== $startDate) {
            // treat as date range
            $dateOBJ = date('F d, Y', strtotime($startDate)) . ' to ' . date('F d, Y', strtotime($endDate));
            $sqlDATE = "(
                        DATE(bt.datetime) BETWEEN '$startDate' AND '$endDate'
                        OR DATE(bt.cancellation_date) BETWEEN '$startDate' AND '$endDate'
                        OR DATE(report_date) BETWEEN '$startDate' AND '$endDate'
                    )";
        } else {
            // single day
            $dateOBJ = date('F d, Y', strtotime($startDate));
            $sqlDATE = "(
                        DATE(bt.datetime) = '$startDate'
                        OR DATE(bt.cancellation_date) = '$startDate'
                        OR DATE(report_date) = '$startDate'
                    )";
        }
    } else {
        if ($filterType === 'monthly') {
            $filterType1 = 'Monthly';
            $startDate = $startDate . '-01';
            $endDate = date('Y-m-t', strtotime($endDate . '-01'));
            // If the provided start and end month are the same, display single month
            $startLabel = date('F Y', strtotime($startDate));
            $endLabel = date('F Y', strtotime($endDate));
            $dateOBJ = ($startLabel === $endLabel) ? $startLabel : ($startLabel . ' to ' . $endLabel);
        } elseif ($filterType === 'yearly') {
            $filterType1 = 'Yearly';
            $startDate = $startDate . '-01-01';
            $endDate = $endDate . '-12-31';
            // If the provided start and end year are the same, display single year
            $startLabel = date('Y', strtotime($startDate));
            $endLabel = date('Y', strtotime($endDate));
            $dateOBJ = ($startLabel === $endLabel) ? $startLabel : ($startLabel . ' to ' . $endLabel);
        } else {
            // fallback
            $filterType1 = ucfirst($filterType);
            $dateOBJ = date('F d, Y', strtotime($startDate));
        }

        $sqlDATE = "(
                        DATE(bt.datetime) BETWEEN '$startDate' AND '$endDate'
                        OR DATE(bt.cancellation_date) BETWEEN '$startDate' AND '$endDate'
                    )";
    }

    // Use normalized partner_key and aggregate to avoid duplicate rows
    // Ensure date condition variable used by queries is available
    $dateCondition = $sqlDATE;

    // Build main WHERE clause for final query (filter by partner at final_merged level)
    $mainWhereClause = '1=1';
    if ($partner !== 'All') {
        $partnerEsc = mysqli_real_escape_string($conn, $partner);
        $mainWhereClause .= " AND (fm.direct_billers_name = '{$partnerEsc}' OR fm.sub_billers_name = '{$partnerEsc}')";
    }
    $DataQuery = "WITH bank_clean AS (
        SELECT 
            bank_name,
            MAX(bank_abbreviation) AS bank_abbreviation
        FROM masterdata.bank_table
        GROUP BY bank_name
    ),
    direct_biller AS (
        SELECT
            pm.partner_id,
            pm.partner_id_kpx,
            pm.gl_code,
            pm.partner_name AS direct_billers_name,
            NULL AS sub_billers_name,
            b.bank_abbreviation,
            pm.settled_online_check,
            pm.charge_to,
            pm.status
        FROM masterdata.partner_masterfile pm
        LEFT JOIN bank_clean b
            ON pm.bank = b.bank_name
    ),

    sub_biller AS (
        SELECT
            partner_id_kpx,
            sub_billers_id,
            partner_name AS direct_billers_name,
            sub_billers_name,
            NULL AS sub_gl_code
        FROM masterdata.subbiller
    ),

    merged_left AS (
        SELECT
            d.partner_id,
            COALESCE(d.partner_id_kpx, s.partner_id_kpx) AS partner_id_kpx,
            s.sub_billers_id,
            COALESCE(d.gl_code, s.sub_gl_code) AS gl_code,

            CASE 
                WHEN d.direct_billers_name = s.sub_billers_name 
                THEN s.direct_billers_name 
                ELSE d.direct_billers_name 
            END AS direct_billers_name,

            COALESCE(s.sub_billers_name, d.sub_billers_name) AS sub_billers_name,

            d.bank_abbreviation,
            d.settled_online_check,
            d.charge_to

        FROM direct_biller d
        LEFT JOIN sub_biller s
            ON d.direct_billers_name = s.sub_billers_name
        WHERE COALESCE(d.status, '') = 'ACTIVE'
    ),

    unmatched_sub AS (
        SELECT
            NULL AS partner_id,
            s.partner_id_kpx,
            s.sub_billers_id,
            s.sub_gl_code AS gl_code,
            s.direct_billers_name,
            s.sub_billers_name,

            NULL AS bank_abbreviation,
            NULL AS settled_online_check,
            NULL AS charge_to

        FROM sub_biller s
        WHERE NOT EXISTS (
            SELECT 1 
            FROM direct_biller d 
            WHERE d.direct_billers_name = s.sub_billers_name 
            AND COALESCE(d.status,'') = 'ACTIVE'
        )
    ),

    final_merged AS (
        SELECT * FROM merged_left
        UNION ALL
        SELECT * FROM unmatched_sub
    ),

    summary_vol AS (
        SELECT
            bt.sub_billers_id,
            bt.partner_id,
            bt.partner_id_kpx,
            COUNT(*) AS vol1,
            SUM(bt.amount_paid) AS principal1,
            SUM(bt.charge_to_partner + bt.charge_to_customer) AS charge1
        FROM mldb.billspayment_transaction bt
        WHERE 
            $dateCondition
        AND bt.status IS NULL 
        AND bt.branch_id NOT IN ('1','2','4937','4938','4962','4987','4993','4944')
        GROUP BY bt.sub_billers_id, bt.partner_id, bt.partner_id_kpx
    ),

    adjustment_vol AS (
        SELECT
            bt.sub_billers_id,
            bt.partner_id,
            bt.partner_id_kpx,
            COUNT(*) AS vol2,
            SUM(bt.amount_paid) AS principal2,
            SUM(bt.charge_to_partner + bt.charge_to_customer) AS charge2
        FROM mldb.billspayment_transaction bt
        WHERE 
            $dateCondition
        AND bt.status='*' 
        AND bt.branch_id NOT IN ('1','2','4937','4938','4962','4987','4993','4944')
        GROUP BY bt.sub_billers_id, bt.partner_id, bt.partner_id_kpx
    )

    -- FINAL RESULT
    SELECT
        fm.partner_id,
        fm.partner_id_kpx,
        fm.sub_billers_id,
        fm.gl_code,

        CASE 
            WHEN fm.sub_billers_id IS NOT NULL 
            THEN fm.sub_billers_name 
            ELSE fm.direct_billers_name 
        END AS partner_name,

        CASE 
            WHEN fm.sub_billers_id IS NOT NULL 
            THEN fm.direct_billers_name 
            ELSE NULL 
        END AS billers_name,

        CONCAT(fm.bank_abbreviation, ' ', fm.settled_online_check) AS bank_abbreviation,
        fm.charge_to AS charging_type,

        COALESCE(sv.vol1, 0) AS summary_vol,
        COALESCE(sv.principal1, 0) AS summary_principal,
        COALESCE(sv.charge1, 0) AS summary_charge,

        COALESCE(av.vol2, 0) AS adjustment_vol,
        COALESCE(ABS(av.principal2), 0) AS adjustment_principal,
        COALESCE(ABS(av.charge2), 0) AS adjustment_charge,

        (COALESCE(sv.vol1,0) - COALESCE(av.vol2,0)) AS net_vol,
        (COALESCE(sv.principal1,0) - COALESCE(ABS(av.principal2),0)) AS net_principal,
        (COALESCE(sv.charge1,0) - COALESCE(ABS(av.charge2),0)) AS net_charge

    FROM final_merged fm

    LEFT JOIN summary_vol sv
        ON (
            (fm.sub_billers_id IS NOT NULL AND fm.sub_billers_id = sv.sub_billers_id)
            OR
            (fm.sub_billers_id IS NULL 
                AND fm.partner_id = sv.partner_id 
                AND fm.partner_id_kpx = sv.partner_id_kpx)
        )

    LEFT JOIN adjustment_vol av
        ON (
            (fm.sub_billers_id IS NOT NULL AND fm.sub_billers_id = av.sub_billers_id)
            OR
            (fm.sub_billers_id IS NULL 
                AND fm.partner_id = av.partner_id 
                AND fm.partner_id_kpx = av.partner_id_kpx)
        )
    WHERE 
        $mainWhereClause
    ORDER BY partner_name";

    try {
        $DataResult = $conn->query($DataQuery);
        
        if ($DataResult) {
            $data = $DataResult->fetch_all(MYSQLI_ASSOC);
            
            // Create new Spreadsheet object
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            
            // Set document properties
            $spreadsheet->getProperties()
                ->setCreator("ML Bills Payment System")
                ->setLastModifiedBy("ML System")
                ->setTitle("Volume Report")
                ->setSubject("Bills Payment Volume Report")
                ->setDescription("Volume report generated from ML Bills Payment System");

            // Set sheet title
            $sheet->setTitle('Volume Report');
            
            // Create header with department info
            $sheet->setCellValue('A1', 'BILLS PAYMENT DEPARTMENT');
            // $sheet->mergeCells('A1:M1');

            if ($filterType === 'weekly') {
                $filterType1 = 'Daily';
                $startLabel = date('F d, Y', strtotime($startDate));
                $endLabel = date('F d, Y', strtotime($endDate));
                $dateOBJ = ($startLabel === $endLabel) ? $startLabel : ($startLabel . ' to ' . $endLabel);
            } elseif ($filterType === 'monthly') {
                $filterType1 = 'Monthly';
                $startLabel = date('F Y', strtotime($startDate));
                $endLabel = date('F Y', strtotime($endDate));
                $dateOBJ = ($startLabel === $endLabel) ? $startLabel : ($startLabel . ' to ' . $endLabel);
            } elseif ($filterType === 'yearly') {
                $filterType1 = 'Yearly';
                $startLabel = date('Y', strtotime($startDate));
                $endLabel = date('Y', strtotime($endDate));
                $dateOBJ = ($startLabel === $endLabel) ? $startLabel : ($startLabel . ' to ' . $endLabel);
            }

            $sheet->setCellValue('A2', 'VOLUME REPORT - ' . strtoupper($filterType1));
            // $sheet->mergeCells('A2:M2');

            // Add empty row for spacing
            $sheet->setCellValue('A3', '');

            // Report details in table format
            $sheet->setCellValue('A4', 'Partners');
            $sheet->setCellValue('B4', ($partner === 'All' ? 'All' : $partner));

            $sheet->setCellValue('A5', 'Generated Date');
            $sheet->setCellValue('B5', date('F d, Y h:i A'));

            $sheet->setCellValue('A6', 'Filtered Date');
            $sheet->setCellValue('B6', $dateOBJ);

            $sheet->setCellValue('A7', 'Filter Type');
            $sheet->setCellValue('B7', ucfirst($filterType));

            $sheet->setCellValue('A8', 'Generated By');
            $sheet->setCellValue('B8', $_SESSION['admin_name'] ?? $_SESSION['user_name']);
            $sheet->setCellValue('A9', '');

            // Style the department header
            $departmentStyle = [
                'font' => ['bold' => true, 'size' => 14]
                // ,
                // 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]
            ];
            $sheet->getStyle('A1')->applyFromArray($departmentStyle);

            // Style the report type header
            $reportTypeStyle = [
                'font' => ['bold' => true, 'size' => 12]
                // ,
                // 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]
            ];
            $sheet->getStyle('A2')->applyFromArray($reportTypeStyle);

            // Style the report details section
            $detailsLabelStyle = [
                'font' => ['bold' => true]
                // ,
                // 'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => 'e9ecef']],
                // 'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
            ];
            // $detailsValueStyle = [
            //     'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
            // ];

            $sheet->getStyle('A4:A8')->applyFromArray($detailsLabelStyle);
            // $sheet->getStyle('B4:B8')->applyFromArray($detailsValueStyle);

            // Adjust column widths for details section
            $sheet->getColumnDimension('A')->setWidth(15);
            $sheet->getColumnDimension('B')->setWidth(25);

            // Table headers (moved down to accommodate new structure)
            $headerRow = 11;
            $sheet->setCellValue('A' . ($headerRow-1), 'No.');
            $sheet->mergeCells('A10:A11');
            $sheet->setCellValue('B' . ($headerRow-1), 'Partner Name');
            $sheet->mergeCells('B10:B11');
            $sheet->setCellValue('C' . ($headerRow-1), 'Biller\'s Name');
            $sheet->mergeCells('C10:C11');
            $sheet->setCellValue('D' . ($headerRow-1), 'Bank');
            $sheet->mergeCells('D10:D11');
            $sheet->setCellValue('E' . ($headerRow-1), 'Charging Type');
            $sheet->mergeCells('E10:E11');
            
            // KP7 / KPX headers
            $sheet->setCellValue('F' . ($headerRow - 1), 'KP7 / KPX');
            $sheet->mergeCells('F' . ($headerRow - 1) . ':H' . ($headerRow - 1));
            $sheet->setCellValue('F' . $headerRow, 'Vol.');
            $sheet->setCellValue('G' . $headerRow, 'Principal');
            $sheet->setCellValue('H' . $headerRow, 'Charge');
            
            // Adjustment headers
            $sheet->setCellValue('I' . ($headerRow - 1), 'Adjustment');
            $sheet->mergeCells('I' . ($headerRow - 1) . ':K' . ($headerRow - 1));
            $sheet->setCellValue('I' . $headerRow, 'Vol.');
            $sheet->setCellValue('J' . $headerRow, 'Principal');
            $sheet->setCellValue('K' . $headerRow, 'Charge');
            
            // Net headers
            $sheet->setCellValue('L' . ($headerRow - 1), 'Net');
            $sheet->mergeCells('L' . ($headerRow - 1) . ':N' . ($headerRow - 1));
            $sheet->setCellValue('L' . $headerRow, 'Vol.');
            $sheet->setCellValue('M' . $headerRow, 'Principal');
            $sheet->setCellValue('N' . $headerRow, 'Charge');
            
            // Style the headers
            $headerStyle = [
                'font' => ['bold' => true],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
            ];
            
            $sheet->getStyle('A10:N11')->applyFromArray($headerStyle);
            
            // Initialize totals
            $totals = [
                'summaryVol' => 0,
                'summaryPrincipal' => 0,
                'summaryCharge' => 0,
                'adjustmentVol' => 0,
                'adjustmentPrincipal' => 0,
                'adjustmentCharge' => 0,
                'netVol' => 0,
                'netPrincipal' => 0,
                'netCharge' => 0
            ];
            
            // Populate data
            $row = 12; // Changed from 8 to 12
            foreach ($data as $index => $rowData) {

                $sheet->setCellValue('A' . $row, $index + 1);
                $sheet->setCellValue('B' . $row, $rowData['partner_name']); // Partner Name - use partner_name for main display
                $sheet->setCellValue('C' . $row, $rowData['billers_name']); // Bank - empty as per original
                $sheet->setCellValue('D' . $row, $rowData['bank_abbreviation']); // Biller's Name - empty as per original
                $sheet->setCellValue('E' . $row, $rowData['charging_type']); // Biller's Name - empty as per original
                $sheet->setCellValue('F' . $row, (int)$rowData['summary_vol']);
                $sheet->setCellValue('G' . $row, (float)$rowData['summary_principal']);
                $sheet->setCellValue('H' . $row, (float)($rowData['summary_charge'] ?? $rowData['summary_charges'] ?? 0));
                $sheet->setCellValue('I' . $row, (int)$rowData['adjustment_vol']);
                $sheet->setCellValue('J' . $row, (float)$rowData['adjustment_principal']);
                $sheet->setCellValue('K' . $row, (float)($rowData['adjustment_charge'] ?? $rowData['adjustment_charges'] ?? 0));
                $sheet->setCellValue('L' . $row, (int)$rowData['net_vol']);
                $sheet->setCellValue('M' . $row, (float)$rowData['net_principal']);
                $sheet->setCellValue('N' . $row, (float)($rowData['net_charge'] ?? $rowData['net_charges'] ?? 0));

                // Add to totals
                $totals['summaryVol'] += $rowData['summary_vol'];
                $totals['summaryPrincipal'] += $rowData['summary_principal'];
                $totals['summaryCharge'] += ($rowData['summary_charge'] ?? $rowData['summary_charges'] ?? 0);
                $totals['adjustmentVol'] += $rowData['adjustment_vol'];
                $totals['adjustmentPrincipal'] += $rowData['adjustment_principal'];
                $totals['adjustmentCharge'] += ($rowData['adjustment_charge'] ?? $rowData['adjustment_charges'] ?? 0);
                $totals['netVol'] += $rowData['net_vol'];
                $totals['netPrincipal'] += $rowData['net_principal'];
                $totals['netCharge'] += ($rowData['net_charge'] ?? $rowData['net_charges'] ?? 0);
                
                $row++;
            }
            
            // Add totals row
            $sheet->setCellValue('A' . $row, 'Total:');
            $sheet->mergeCells('A' . $row . ':E' . $row); // Merge A to E for "Total:" label
            $sheet->setCellValue('F' . $row, (int)$totals['summaryVol']);
            $sheet->setCellValue('G' . $row, (float)$totals['summaryPrincipal']);
            $sheet->setCellValue('H' . $row, (float)$totals['summaryCharge']);
            
            $sheet->setCellValue('I' . $row, (int)$totals['adjustmentVol']);
            $sheet->setCellValue('J' . $row, (float)$totals['adjustmentPrincipal']);
            $sheet->setCellValue('K' . $row, (float)$totals['adjustmentCharge']);
        
            $sheet->setCellValue('L' . $row, (int)$totals['netVol']);
            $sheet->setCellValue('M' . $row, (float)$totals['netPrincipal']);
            $sheet->setCellValue('N' . $row, (float)$totals['netCharge']);

            // Apply number formatting to volume columns (whole numbers)
            $volumeColumns = ['F', 'I', 'L'];
            foreach ($volumeColumns as $col) {
                $sheet->getStyle($col . '12:' . $col . $row)->getNumberFormat()->setFormatCode('#,##0');
                $sheet->getStyle($col . '12:' . $col . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            }

            // Apply number formatting to amount columns (2 decimal places)
            $amountColumns = ['G', 'H', 'J', 'K', 'M', 'N'];
            foreach ($amountColumns as $col) {
                $sheet->getStyle($col . '12:' . $col . $row)->getNumberFormat()->setFormatCode('#,##0.00');
            }
            
            // Style the totals row
            $totalStyle = [
                'font' => ['bold' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => 'f8f9fa']],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT, 'vertical' => Alignment::VERTICAL_CENTER]
            ];
            $sheet->getStyle('A' . $row . ':N' . $row)->applyFromArray($totalStyle);
            
            // Auto-fit columns
            foreach (range('A', 'N') as $column) {
                $sheet->getColumnDimension($column)->setAutoSize(true);
            }
            
            // Set borders for data area
            $dataStyle = [
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
            ];
            $sheet->getStyle('A11:N' . $row)->applyFromArray($dataStyle);
            
            // Generate filename
            $filename = 'Volume_Report_' . date('Y-m-d_H-i-s') . '.xlsx';
            
            // Set headers for download
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment;filename="' . $filename . '"');
            header('Cache-Control: max-age=0');
            
            // Create Excel file
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
            
            // Clean up
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
            
        } else {
            echo json_encode(['status' => 'error', 'message' => 'No data found']);
        }
    } catch (Exception $e) {
        error_log("Excel export error: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'Error generating Excel file']);
    }
    exit();
}
?>
