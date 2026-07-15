<?php
require_once __DIR__ . '/../../../config/config.php';
require '../../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

session_start();

if (!isset($_SESSION['user_type'])) {
    http_response_code(403);
    exit('Unauthorized access');
}

if (!isset($_POST['action']) || $_POST['action'] !== 'export_excel') {
    http_response_code(400);
    exit('Invalid request');
}

$partner = $_POST['partner'] ?? 'All';
$filterType = $_POST['filterType'] ?? '';
$startDate = $_POST['startDate'] ?? '';
$endDate = $_POST['endDate'] ?? '';

if ($startDate === '') {
    http_response_code(400);
    exit('Missing start date');
}

if ($endDate === '') {
    $endDate = $startDate;
}

$displayFilter = ucfirst(str_replace('-', ' ', $filterType));
$dateCondition = '';
$dateLabel = '';

switch ($filterType) {
    case 'daily':
        $dateCondition = "(DATE(bt.datetime) = '$startDate' OR DATE(bt.cancellation_date) = '$startDate')";
        $dateLabel = date('F d, Y', strtotime($startDate));
        $displayFilter = 'Per Day';
        break;

    case 'date-range':
        $dateCondition = "(DATE(bt.datetime) BETWEEN '$startDate' AND '$endDate' OR DATE(bt.cancellation_date) BETWEEN '$startDate' AND '$endDate')";
        $dateLabel = date('F d, Y', strtotime($startDate)) . ' to ' . date('F d, Y', strtotime($endDate));
        $displayFilter = 'Date Range';
        break;

    case 'monthly':
        $monthStart = $startDate . '-01';
        $monthEnd = date('Y-m-t', strtotime($monthStart));
        $dateCondition = "(DATE(bt.datetime) BETWEEN '$monthStart' AND '$monthEnd' OR DATE(bt.cancellation_date) BETWEEN '$monthStart' AND '$monthEnd')";
        $dateLabel = date('F Y', strtotime($monthStart));
        $displayFilter = 'Per Month';
        break;

    case 'monthly-range':
        $monthStart = $startDate . '-01';
        $monthEnd = date('Y-m-t', strtotime($endDate . '-01'));
        $dateCondition = "(DATE(bt.datetime) BETWEEN '$monthStart' AND '$monthEnd' OR DATE(bt.cancellation_date) BETWEEN '$monthStart' AND '$monthEnd')";
        $startLabel = date('F Y', strtotime($monthStart));
        $endLabel = date('F Y', strtotime($monthEnd));
        $dateLabel = ($startLabel === $endLabel) ? $startLabel : ($startLabel . ' to ' . $endLabel);
        $displayFilter = 'Monthly Range';
        break;

    case 'yearly':
        $yearStart = $startDate . '-01-01';
        $yearEnd = $startDate . '-12-31';
        $dateCondition = "(DATE(bt.datetime) BETWEEN '$yearStart' AND '$yearEnd' OR DATE(bt.cancellation_date) BETWEEN '$yearStart' AND '$yearEnd')";
        $dateLabel = date('Y', strtotime($yearStart));
        $displayFilter = 'Per Year';
        break;

    case 'yearly-range':
        $yearStart = $startDate . '-01-01';
        $yearEnd = $endDate . '-12-31';
        $dateCondition = "(DATE(bt.datetime) BETWEEN '$yearStart' AND '$yearEnd' OR DATE(bt.cancellation_date) BETWEEN '$yearStart' AND '$yearEnd')";
        $startLabel = date('Y', strtotime($yearStart));
        $endLabel = date('Y', strtotime($yearEnd));
        $dateLabel = ($startLabel === $endLabel) ? $startLabel : ($startLabel . ' to ' . $endLabel);
        $displayFilter = 'Yearly Range';
        break;

    default:
        http_response_code(400);
        exit('Invalid filter type');
}

$DataQuery = "WITH summary_vol AS (
                SELECT
                    CASE 
                        WHEN bt.partner_id IS NOT NULL THEN bt.partner_id
                        WHEN bt.partner_id_kpx IS NOT NULL THEN bt.partner_id_kpx
                        ELSE CONCAT('temp_', bt.partner_name)
                    END COLLATE utf8mb4_general_ci AS partner_key,
                    bt.partner_name,
                    COUNT(*) AS vol1,
                    SUM(bt.amount_paid) AS principal1,
                    SUM(bt.charge_to_partner + bt.charge_to_customer) AS charge1
                FROM mldb.billspayment_transaction AS bt
                WHERE $dateCondition
                  AND bt.status IS NULL
                  AND bt.branch_id NOT IN ('1', '2', '4937', '4938', '4962', '4987', '4993', '4944')
                GROUP BY
                    CASE 
                        WHEN bt.partner_id IS NOT NULL THEN bt.partner_id
                        WHEN bt.partner_id_kpx IS NOT NULL THEN bt.partner_id_kpx
                        ELSE CONCAT('temp_', bt.partner_name)
                    END COLLATE utf8mb4_general_ci,
                    bt.partner_name
            ),
            adjustment_vol AS (
                SELECT
                    CASE 
                        WHEN bt.partner_id IS NOT NULL THEN bt.partner_id
                        WHEN bt.partner_id_kpx IS NOT NULL THEN bt.partner_id_kpx
                        ELSE CONCAT('temp_', bt.partner_name)
                    END COLLATE utf8mb4_general_ci AS partner_key,
                    bt.partner_name,
                    COUNT(*) AS vol2,
                    SUM(bt.amount_paid) AS principal2,
                    SUM(bt.charge_to_partner + bt.charge_to_customer) AS charge2
                FROM mldb.billspayment_transaction AS bt
                WHERE $dateCondition
                  AND bt.status = '*'
                  AND bt.branch_id NOT IN ('1', '2', '4937', '4938', '4962', '4987', '4993', '4944')
                GROUP BY
                    CASE 
                        WHEN bt.partner_id IS NOT NULL THEN bt.partner_id
                        WHEN bt.partner_id_kpx IS NOT NULL THEN bt.partner_id_kpx
                        ELSE CONCAT('temp_', bt.partner_name)
                    END COLLATE utf8mb4_general_ci,
                    bt.partner_name
            ),
            all_partners AS (
                SELECT 
                    COALESCE(mpm.partner_id, mpm.partner_id_kpx, CONCAT('temp_', mpm.partner_name)) AS partner_key,
                    mpm.partner_name
                FROM masterdata.partner_masterfile AS mpm
                WHERE mpm.status = 'ACTIVE'

                UNION
                SELECT partner_key, partner_name FROM summary_vol

                UNION
                SELECT partner_key, partner_name FROM adjustment_vol
            )
            SELECT
                ap.partner_name,
                (SUM(COALESCE(sv.vol1, 0)) - SUM(COALESCE(av.vol2, 0))) AS net_vol,
                (SUM(COALESCE(sv.principal1, 0)) - SUM(COALESCE(ABS(av.principal2), 0))) AS net_principal,
                (SUM(COALESCE(sv.charge1, 0)) - SUM(COALESCE(ABS(av.charge2), 0))) AS net_charges
            FROM all_partners AS ap
            LEFT JOIN summary_vol AS sv ON (ap.partner_key = sv.partner_key OR ap.partner_name = sv.partner_name)
            LEFT JOIN adjustment_vol AS av ON (ap.partner_key = av.partner_key OR ap.partner_name = av.partner_name)
            LEFT JOIN masterdata.partner_masterfile AS mpm ON (ap.partner_name = mpm.partner_name)
            WHERE (mpm.status = 'ACTIVE' OR mpm.status IS NULL)";

if ($partner !== 'All') {
    $DataQuery .= " AND ap.partner_name = '" . mysqli_real_escape_string($conn, $partner) . "'";
}

$DataQuery .= " GROUP BY ap.partner_name HAVING ap.partner_name IS NOT NULL ORDER BY ap.partner_name";

try {
    $DataResult = $conn->query($DataQuery);
    if (!$DataResult) {
        throw new Exception('Query failed');
    }

    $data = $DataResult->fetch_all(MYSQLI_ASSOC);

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    $spreadsheet->getProperties()
        ->setCreator('ML Bills Payment System')
        ->setLastModifiedBy('ML System')
        ->setTitle('Balance Sheet Report')
        ->setSubject('Balance Sheet Report')
        ->setDescription('Balance sheet report generated from ML Bills Payment System');

    $sheet->setTitle('Balance Sheet Report');
    $sheet->setCellValue('A1', 'BILLS PAYMENT DEPARTMENT');
    $sheet->setCellValue('A2', 'BALANCE SHEET REPORT');
    $sheet->setCellValue('A3', '');
    $sheet->setCellValue('A4', 'Partners');
    $sheet->setCellValue('B4', ($partner === 'All' ? 'All' : $partner));
    $sheet->setCellValue('A5', 'Generated Date');
    $sheet->setCellValue('B5', date('F d, Y h:i A'));
    $sheet->setCellValue('A6', 'Filtered Date');
    $sheet->setCellValue('B6', $dateLabel);
    $sheet->setCellValue('A7', 'Filter Type');
    $sheet->setCellValue('B7', $displayFilter);
    $sheet->setCellValue('A8', 'Generated By');
    $sheet->setCellValue('B8', $_SESSION['admin_name'] ?? $_SESSION['user_name']);

    $sheet->setCellValue('A10', 'No.');
    $sheet->setCellValue('B10', 'Partner Name');
    $sheet->setCellValue('C10', 'Net Vol.');
    $sheet->setCellValue('D10', 'Net Principal');
    $sheet->setCellValue('E10', 'Net Charge');
    $sheet->setCellValue('F10','Paid Service Charge');
    $sheet->setCellValue('G10','Accounts Payable to Partner');
    $sheet->setCellValue('H10','Service Charge');
    $sheet->setCellValue('I10','BPW undeducted');
    $sheet->setCellValue('J10','BPX undeducted');
    $sheet->setCellValue('K10','Audit findings');
    $sheet->setCellValue('L10','Banks');
    $sheet->setCellValue('M10','Others');
    $sheet->setCellValue('N10','Accounts Receivable from Partner');
    $sheet->setCellValue('O10','Balances');

    $sheet->getStyle('A1')->applyFromArray(['font' => ['bold' => true, 'size' => 14]]);
    $sheet->getStyle('A2')->applyFromArray(['font' => ['bold' => true, 'size' => 12]]);
    $sheet->getStyle('A4:A8')->applyFromArray(['font' => ['bold' => true]]);

    $headerStyle = [
        'font' => ['bold' => true],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => 'f8f9fa']]
    ];
    $sheet->getStyle('A10:O10')->applyFromArray($headerStyle);

    $totals = ['vol' => 0, 'principal' => 0, 'charge' => 0];
    $row = 11;

    foreach ($data as $index => $rowData) {
        $netVol = (int)($rowData['net_vol'] ?? 0);
        $netPrincipal = (float)($rowData['net_principal'] ?? 0);
        $netCharge = (float)($rowData['net_charges'] ?? 0);

        $sheet->setCellValue('A' . $row, $index + 1);
        $sheet->setCellValue('B' . $row, $rowData['partner_name'] ?? '');
        $sheet->setCellValue('C' . $row, $netVol);
        $sheet->setCellValue('D' . $row, $netPrincipal);
        $sheet->setCellValue('E' . $row, $netCharge);

        $totals['vol'] += $netVol;
        $totals['principal'] += $netPrincipal;
        $totals['charge'] += $netCharge;

        $row++;
    }

    $sheet->setCellValue('A' . ($row+1), 'Total:');
    $sheet->mergeCells('A' . ($row+1) . ':B' . ($row+1));
    $sheet->setCellValue('C' . ($row+1), $totals['vol']);
    $sheet->setCellValue('D' . ($row+1), $totals['principal']);
    $sheet->setCellValue('E' . ($row+1), $totals['charge']);

    $sheet->getStyle('A' . ($row+1) . ':E' . ($row+1))->applyFromArray([
        'font' => ['bold' => true],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => 'f8f9fa']],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT, 'vertical' => Alignment::VERTICAL_CENTER]
    ]);

    $sheet->getStyle('C11:C' . ($row+1))->getNumberFormat()->setFormatCode('#,##0');
    $sheet->getStyle('D11:E' . ($row+1))->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle('O10:E' . ($row+1))->applyFromArray([
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
    ]);

    foreach (['A' => 10, 'B' => 35, 'C' => 15, 'D' => 18, 'E' => 15] as $column => $width) {
        $sheet->getColumnDimension($column)->setWidth($width);
    }

    $filename = 'Balance_Sheet_Report_' . date('Y-m-d_H-i-s') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');

    $spreadsheet->disconnectWorksheets();
    unset($spreadsheet);
} catch (Exception $e) {
    error_log('Balance sheet excel export error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Error generating Excel file']);
}
exit();
?>