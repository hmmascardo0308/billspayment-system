<?php
include '../../../../config/config.php';
session_start();
include '../../../../templates/middleware.php';
require_once '../../../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

$id = resolve_user_identifier();
if (empty($id)) {
    http_response_code(401);
    exit('Unauthorized');
}

if (!function_exists('has_any_permission') || !has_any_permission(['TRL Report', 'Bills Payment'])) {
    http_response_code(403);
    exit('Forbidden');
}

$partnerId = trim((string) ($_GET['partner_id'] ?? ''));
if ($partnerId === '') {
    http_response_code(400);
    exit('Missing partner_id');
}

$partnerName = '';
$partnerStmt = $conn->prepare(
    "SELECT TRIM(COALESCE(partner_name, '')) AS partner_name
     FROM mldb.subbiller
     WHERE TRIM(COALESCE(partner_id_kpx, '')) = ?
       AND TRIM(COALESCE(partner_name, '')) <> ''
     LIMIT 1"
);

if ($partnerStmt) {
    $partnerStmt->bind_param('s', $partnerId);
    if ($partnerStmt->execute()) {
        $partnerRes = $partnerStmt->get_result();
        if ($partnerRes && ($pr = $partnerRes->fetch_assoc())) {
            $partnerName = (string) ($pr['partner_name'] ?? '');
        }
    }
    $partnerStmt->close();
}

if ($partnerName === '') {
    http_response_code(404);
    exit('Partner not found');
}

function trl_sheet_name($name) {
    $safe = preg_replace('/[\[\]\:\*\?\/\\\\]/', ' ', (string) $name);
    $safe = trim(preg_replace('/\s+/', ' ', $safe));
    if ($safe === '') {
        $safe = 'Sheet';
    }
    return mb_substr($safe, 0, 31);
}

function trl_set_header_row($sheet, $colCount) {
    $endCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colCount);
    $sheet->getStyle('A1:' . $endCol . '1')->getFont()->setBold(true);
    $sheet->getStyle('A1:' . $endCol . '1')->getFill()
        ->setFillType(Fill::FILL_SOLID)
        ->getStartColor()->setARGB('FFF3F4F6');
}

function trl_auto_size($sheet, $colCount) {
    for ($i = 1; $i <= $colCount; $i++) {
        $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }
}

function trl_num_or_dash($v) {
    return $v === null ? '-' : (float) $v;
}

// Build SUMMARY data (status IS NULL)
$yearColumns = [];
$rowsBySubBiller = [];
$totalsByYear = [];
$grandTotal = 0.0;

$summarySql = "
    SELECT
        COALESCE(NULLIF(TRIM(s.sub_billers_name), ''), 'UNKNOWN BILLER') AS sub_biller_name,
        YEAR(t.transfer_datetime) AS report_year,
        SUM(COALESCE(t.amount, 0)) AS total_amount
    FROM mldb.trl t
    INNER JOIN mldb.subbiller s
        ON CAST(t.wrong_biller_id AS CHAR) = CAST(s.sub_billers_id AS CHAR)
    WHERE s.partner_id_kpx = ?
      AND t.transfer_datetime IS NOT NULL
      AND t.status IS NULL
    GROUP BY COALESCE(NULLIF(TRIM(s.sub_billers_name), ''), 'UNKNOWN BILLER'), YEAR(t.transfer_datetime)
    ORDER BY sub_biller_name ASC, report_year ASC
";

$summaryStmt = $conn->prepare($summarySql);
if ($summaryStmt) {
    $summaryStmt->bind_param('s', $partnerId);
    if ($summaryStmt->execute()) {
        $result = $summaryStmt->get_result();
        while ($r = $result->fetch_assoc()) {
            $subBiller = (string) ($r['sub_biller_name'] ?? 'UNKNOWN BILLER');
            $year = (int) ($r['report_year'] ?? 0);
            $amount = (float) ($r['total_amount'] ?? 0);
            if ($year <= 0) {
                continue;
            }

            $yearColumns[$year] = true;
            if (!isset($rowsBySubBiller[$subBiller])) {
                $rowsBySubBiller[$subBiller] = [
                    'name' => $subBiller,
                    'years' => [],
                    'total' => 0.0,
                ];
            }

            $rowsBySubBiller[$subBiller]['years'][$year] = $amount;
            $rowsBySubBiller[$subBiller]['total'] += $amount;

            if (!isset($totalsByYear[$year])) {
                $totalsByYear[$year] = 0.0;
            }
            $totalsByYear[$year] += $amount;
            $grandTotal += $amount;
        }
    }
    $summaryStmt->close();
}

$yearColumns = array_keys($yearColumns);
sort($yearColumns);
ksort($rowsBySubBiller, SORT_NATURAL | SORT_FLAG_CASE);

// Determine payment branch field
$branchColumn = 'payment_branch';
$colCheck = mysqli_query($conn, "SHOW COLUMNS FROM mldb.trl LIKE 'payment_branch'");
if (!$colCheck || mysqli_num_rows($colCheck) === 0) {
    $colCheck2 = mysqli_query($conn, "SHOW COLUMNS FROM mldb.trl LIKE 'payment_branch_name'");
    if ($colCheck2 && mysqli_num_rows($colCheck2) > 0) {
        $branchColumn = 'payment_branch_name';
    } else {
        $branchColumn = null;
    }
}
$branchSelect = $branchColumn !== null ? "t.{$branchColumn} AS payment_branch" : "'' AS payment_branch";

// Build REFUNDED data (status IS NOT NULL)
$refundedRows = [];
$refundedSql = "SELECT
        t.trl_no,
        t.transfer_datetime,
        t.ref_no,
        t.wrong_biller_id,
        t.biller_name,
        t.account_no,
        t.name,
        t.payment_branch_id,
        " . $branchSelect . ",
        t.amount,
        t.type_of_request,
        t.reason,
        t.status,
        wb.correct_biller_id,
        wb.correct_biller_name,
        oa.wrong_amount AS oa_wrong_amount,
        oa.correct_amount AS oa_correct_amount,
        oa.difference AS oa_difference,
        ct.wrong_amount AS ct_wrong_amount,
        ct.correct_amount AS ct_correct_amount
    FROM mldb.trl t
    INNER JOIN mldb.subbiller s
        ON CAST(t.wrong_biller_id AS CHAR) = CAST(s.sub_billers_id AS CHAR)
    LEFT JOIN mldb.trl_wrongbiller wb ON wb.trl_no = t.trl_no
    LEFT JOIN mldb.trl_overstatedamount oa ON oa.trl_no = t.trl_no
    LEFT JOIN mldb.trl_cancelledtransaction ct ON ct.trl_no = t.trl_no
    WHERE s.partner_id_kpx = ?
      AND t.status IS NOT NULL
    ORDER BY t.transfer_datetime DESC, t.trl_no DESC";

$refundedStmt = $conn->prepare($refundedSql);
if ($refundedStmt) {
    $refundedStmt->bind_param('s', $partnerId);
    if ($refundedStmt->execute()) {
        $result = $refundedStmt->get_result();
        while ($r = $result->fetch_assoc()) {
            $typeNorm = strtoupper(trim((string) ($r['type_of_request'] ?? '')));
            $wrongAmount = null;
            $correctAmount = null;
            $difference = null;

            if ($typeNorm === 'OVERSTATED AMOUNT') {
                $wrongAmount = $r['oa_wrong_amount'];
                $correctAmount = $r['oa_correct_amount'];
                $difference = $r['oa_difference'];
            } elseif ($typeNorm === 'CANCELLED TRANSACTION') {
                $wrongAmount = $r['ct_wrong_amount'];
                $correctAmount = $r['ct_correct_amount'];
            }

            $refundedRows[] = [
                (int) ($r['trl_no'] ?? 0),
                (string) ($r['transfer_datetime'] ?? ''),
                (string) ($r['ref_no'] ?? ''),
                (string) ($r['wrong_biller_id'] ?? ''),
                (string) ($r['biller_name'] ?? ''),
                (string) ($r['account_no'] ?? ''),
                (string) ($r['name'] ?? ''),
                (string) ($r['payment_branch_id'] ?? ''),
                (string) ($r['payment_branch'] ?? ''),
                (float) ($r['amount'] ?? 0),
                $typeNorm,
                (string) ($r['correct_biller_id'] ?? ''),
                (string) ($r['correct_biller_name'] ?? ''),
                trl_num_or_dash($wrongAmount),
                trl_num_or_dash($correctAmount),
                trl_num_or_dash($difference),
                (string) ($r['reason'] ?? ''),
                (string) ($r['status'] ?? ''),
            ];
        }
    }
    $refundedStmt->close();
}

// Get all subbillers of the partner for dynamic sheets
$subbillers = [];
$subStmt = $conn->prepare(
    "SELECT
        TRIM(COALESCE(sub_billers_id, '')) AS sub_billers_id,
        COALESCE(NULLIF(TRIM(sub_billers_name), ''), 'UNKNOWN BILLER') AS sub_billers_name
     FROM mldb.subbiller
     WHERE TRIM(COALESCE(partner_id_kpx, '')) = ?
     ORDER BY sub_billers_name ASC"
);
if ($subStmt) {
    $subStmt->bind_param('s', $partnerId);
    if ($subStmt->execute()) {
        $res = $subStmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $sid = (string) ($row['sub_billers_id'] ?? '');
            if ($sid === '') {
                continue;
            }
            $subbillers[] = [
                'id' => $sid,
                'name' => (string) ($row['sub_billers_name'] ?? 'UNKNOWN BILLER'),
            ];
        }
    }
    $subStmt->close();
}

$spreadsheet = new Spreadsheet();

// Sheet 1: SUMMARY
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('SUMMARY');

    $firstHeader = strtoupper((string) $partnerName) . "\nSUB BILLERS";
$summaryHeaders = [$firstHeader];
foreach ($yearColumns as $year) {
    $summaryHeaders[] = (string) $year;
}
$summaryHeaders[] = 'TOTAL AMOUNT';
$sheet->fromArray($summaryHeaders, null, 'A1');
trl_set_header_row($sheet, count($summaryHeaders));

// Make the first header show partner name on first line and "BILLERS" on second line,
// enable wrap text and center alignment for visual parity with the web UI.
$sheet->getStyle('A1')->getAlignment()
    ->setWrapText(true)
    ->setHorizontal(Alignment::HORIZONTAL_CENTER)
    ->setVertical(Alignment::VERTICAL_CENTER);
$sheet->getRowDimension(1)->setRowHeight(36);

$rowIdx = 2;
foreach ($rowsBySubBiller as $row) {
    $line = [$row['name']];
    foreach ($yearColumns as $year) {
        $line[] = isset($row['years'][$year]) ? (float) $row['years'][$year] : '-';
    }
    $line[] = (float) $row['total'];
    $sheet->fromArray($line, null, 'A' . $rowIdx);
    $rowIdx++;
}

$totalsLine = ['TOTAL'];
foreach ($yearColumns as $year) {
    $totalsLine[] = isset($totalsByYear[$year]) ? (float) $totalsByYear[$year] : '-';
}
$totalsLine[] = (float) $grandTotal;
$sheet->fromArray($totalsLine, null, 'A' . $rowIdx);
$endColSummary = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($summaryHeaders));
$sheet->getStyle('A' . $rowIdx . ':' . $endColSummary . $rowIdx)->getFont()->setBold(true);

// style per-row totals (make per-row total values red) and highlight overall/grand totals
$lastColIdx = count($summaryHeaders);
$lastColLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($lastColIdx);
if ($rowIdx > 2) {
    // rows 2..(rowIdx-1) are data rows - highlight their total values in red
    $sheet->getStyle($lastColLetter . '2:' . $lastColLetter . ($rowIdx - 1))
        ->getFont()->getColor()->setARGB('FFFF0000');
}

// highlight overall total cell (last cell of totals row)
$sheet->getStyle($lastColLetter . $rowIdx)
    ->getFill()->setFillType(Fill::FILL_SOLID)
    ->getStartColor()->setARGB('FFFFFF00');
$sheet->getStyle($lastColLetter . $rowIdx)->getFont()->getColor()->setARGB('FF000000');
$sheet->getStyle($lastColLetter . $rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

// add one spacer row then a separate Grand Total row (label left, value right, highlighted)
$spacerRow = $rowIdx + 1;
$sheet->setCellValue('A' . $spacerRow, '');

$grandRow = $spacerRow + 1;
$penultColIdx = max(1, $lastColIdx - 1);
$penultColLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($penultColIdx);
$sheet->setCellValue($penultColLetter . $grandRow, 'Grand Total');
$sheet->setCellValue($lastColLetter . $grandRow, (float) $grandTotal);
$sheet->getStyle($penultColLetter . $grandRow)->getFont()->setBold(true)->getColor()->setARGB('FF000000');
$sheet->getStyle($penultColLetter . $grandRow)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFF00');
$sheet->getStyle($lastColLetter . $grandRow)->getFont()->setBold(true)->getColor()->setARGB('FF000000');
$sheet->getStyle($lastColLetter . $grandRow)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFF00');
$sheet->getStyle($lastColLetter . $grandRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
$sheet->getStyle($penultColLetter . $grandRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

// Sheet 2: REFUNDED
$refundedSheet = $spreadsheet->createSheet();
$refundedSheet->setTitle('REFUNDED');
$refHeaders = [
    'TRL NO.', 'TRANS. DATE/TIME', 'REF. NO.', 'WRONG BILLER ID', 'BILLER NAME',
    'ACCOUNT NO.', 'NAME', 'PAYMENT BRANCH ID', 'PAYMENT BRANCH', 'AMOUNT',
    'TYPE OF REQUEST', 'CORRECT BILLER ID', 'CORRECT BILLER NAME',
    'WRONG AMOUNT', 'CORRECT AMOUNT', 'DIFFERENCE', 'REASON', 'STATUS'
];
$refundedSheet->fromArray($refHeaders, null, 'A1');
trl_set_header_row($refundedSheet, count($refHeaders));
if (!empty($refundedRows)) {
    $refundedSheet->fromArray($refundedRows, null, 'A2');
    // Ensure ACCOUNT NO. (column F) is written as text to avoid Excel scientific notation
    for ($i = 0; $i < count($refundedRows); $i++) {
        $acct = (string) $refundedRows[$i][5];
        $rowIndex = 2 + $i;
        $refundedSheet->setCellValueExplicit('F' . $rowIndex, $acct, DataType::TYPE_STRING);
    }
}

// Align refunded sheet: TRL NO and numeric columns right, text left
$lastRefRow = max(2, $refundedSheet->getHighestRow());
$refundedSheet->getStyle('A2:A' . $lastRefRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
$refundedSheet->getStyle('B2:I' . $lastRefRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
$refundedSheet->getStyle('J2:J' . $lastRefRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
$refundedSheet->getStyle('K2:M' . $lastRefRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
$refundedSheet->getStyle('N2:P' . $lastRefRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
$refundedSheet->getStyle('Q2:Q' . $lastRefRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

// Dynamic sheets: one per subbiller (status must be NULL)
$dynSql = "SELECT
        t.trl_no,
        t.transfer_datetime,
        t.ref_no,
        t.wrong_biller_id,
        t.biller_name,
        t.account_no,
        t.name,
        t.payment_branch_id,
        " . $branchSelect . ",
        t.amount,
        t.type_of_request,
        t.reason,
        wb.correct_biller_id,
        wb.correct_biller_name,
        oa.wrong_amount AS oa_wrong_amount,
        oa.correct_amount AS oa_correct_amount,
        oa.difference AS oa_difference,
        ct.wrong_amount AS ct_wrong_amount,
        ct.correct_amount AS ct_correct_amount
    FROM mldb.trl t
    LEFT JOIN mldb.trl_wrongbiller wb ON wb.trl_no = t.trl_no
    LEFT JOIN mldb.trl_overstatedamount oa ON oa.trl_no = t.trl_no
    LEFT JOIN mldb.trl_cancelledtransaction ct ON ct.trl_no = t.trl_no
    WHERE CAST(t.wrong_biller_id AS CHAR) = CAST(? AS CHAR)
      AND t.status IS NULL
    ORDER BY t.transfer_datetime DESC, t.trl_no DESC";

$dynStmt = $conn->prepare($dynSql);

$dynHeaders = [
    'TRL NO.', 'TRANS. DATE/TIME', 'REF. NO.', 'WRONG BILLER ID', 'BILLER NAME',
    'ACCOUNT NO.', 'NAME', 'PAYMENT BRANCH ID', 'PAYMENT BRANCH', 'AMOUNT',
    'TYPE OF REQUEST', 'CORRECT BILLER ID', 'CORRECT BILLER NAME',
    'WRONG AMOUNT', 'CORRECT AMOUNT', 'DIFFERENCE', 'REASON'
];

$sheetNamesUsed = ['SUMMARY' => true, 'REFUNDED' => true];

if ($dynStmt) {
    foreach ($subbillers as $sb) {
        $sid = (string) $sb['id'];
        $sname = (string) $sb['name'];

        $dynRows = [];
        $totalAmount = 0.0;

        $dynStmt->bind_param('s', $sid);
        if ($dynStmt->execute()) {
            $res = $dynStmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $typeNorm = strtoupper(trim((string) ($r['type_of_request'] ?? '')));
                $wrongAmount = null;
                $correctAmount = null;
                $difference = null;

                if ($typeNorm === 'OVERSTATED AMOUNT') {
                    $wrongAmount = $r['oa_wrong_amount'];
                    $correctAmount = $r['oa_correct_amount'];
                    $difference = $r['oa_difference'];
                } elseif ($typeNorm === 'CANCELLED TRANSACTION') {
                    $wrongAmount = $r['ct_wrong_amount'];
                    $correctAmount = $r['ct_correct_amount'];
                }

                $amount = (float) ($r['amount'] ?? 0);
                $totalAmount += $amount;

                $dynRows[] = [
                    (int) ($r['trl_no'] ?? 0),
                    (string) ($r['transfer_datetime'] ?? ''),
                    (string) ($r['ref_no'] ?? ''),
                    (string) ($r['wrong_biller_id'] ?? ''),
                    (string) ($r['biller_name'] ?? ''),
                    (string) ($r['account_no'] ?? ''),
                    (string) ($r['name'] ?? ''),
                    (string) ($r['payment_branch_id'] ?? ''),
                    (string) ($r['payment_branch'] ?? ''),
                    $amount,
                    $typeNorm,
                    (string) ($r['correct_biller_id'] ?? ''),
                    (string) ($r['correct_biller_name'] ?? ''),
                    trl_num_or_dash($wrongAmount),
                    trl_num_or_dash($correctAmount),
                    trl_num_or_dash($difference),
                    (string) ($r['reason'] ?? ''),
                ];
            }
        }

        $baseName = trl_sheet_name($sname);
        $sheetName = $baseName;
        $counter = 2;
        while (isset($sheetNamesUsed[$sheetName])) {
            $suffix = ' ' . $counter;
            $sheetName = mb_substr($baseName, 0, max(1, 31 - strlen($suffix))) . $suffix;
            $counter++;
        }
        $sheetNamesUsed[$sheetName] = true;

        $sbSheet = $spreadsheet->createSheet();
        $sbSheet->setTitle($sheetName);
        $sbSheet->fromArray($dynHeaders, null, 'A1');
        trl_set_header_row($sbSheet, count($dynHeaders));

        $lastDataRow = 1;
        if (!empty($dynRows)) {
            $sbSheet->fromArray($dynRows, null, 'A2');
            $lastDataRow = 1 + count($dynRows);
            // Ensure ACCOUNT NO. (column F) is written as text to avoid Excel scientific notation
            for ($i = 0; $i < count($dynRows); $i++) {
                $acct = (string) $dynRows[$i][5];
                $rowIndex = 2 + $i;
                $sbSheet->setCellValueExplicit('F' . $rowIndex, $acct, DataType::TYPE_STRING);
            }
        }

        // Add one blank row, then Total Amount label/value near the AMOUNT column.
        $totalRow = $lastDataRow + 2;
        $sbSheet->setCellValue('I' . $totalRow, 'Total Amount');
        $sbSheet->setCellValue('J' . $totalRow, $totalAmount);
        $sbSheet->getStyle('I' . $totalRow . ':J' . $totalRow)->getFont()->setBold(true);
        $sbSheet->getStyle('J' . $totalRow)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFFFFF00');

        // Align dynamic sheet body: numeric columns right, text left for consistency
        $bodyEnd = max(2, $lastDataRow);
        $sbSheet->getStyle('A2:A' . $bodyEnd)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sbSheet->getStyle('B2:I' . $bodyEnd)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $sbSheet->getStyle('J2:J' . $bodyEnd)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sbSheet->getStyle('K2:M' . $bodyEnd)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $sbSheet->getStyle('N2:P' . $bodyEnd)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sbSheet->getStyle('Q2:Q' . $bodyEnd)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

        // Align total label/value: label left, value right
        $sbSheet->getStyle('I' . $totalRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $sbSheet->getStyle('J' . $totalRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    }

    $dynStmt->close();
}

// Style numeric columns as 2 decimal places
$summaryNumberStart = 2;
$summaryNumberEnd = count($summaryHeaders);
for ($i = $summaryNumberStart; $i <= $summaryNumberEnd; $i++) {
    $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
    $sheet->getStyle($col . '2:' . $col . max(2, $sheet->getHighestRow()))
        ->getNumberFormat()->setFormatCode('#,##0.00');
}

$refundedSheet->getStyle('J2:J' . max(2, $refundedSheet->getHighestRow()))
    ->getNumberFormat()->setFormatCode('#,##0.00');
$refundedSheet->getStyle('N2:P' . max(2, $refundedSheet->getHighestRow()))
    ->getNumberFormat()->setFormatCode('#,##0.00');

for ($si = 0; $si < $spreadsheet->getSheetCount(); $si++) {
    $ws = $spreadsheet->getSheet($si);
    $title = $ws->getTitle();
    if ($title !== 'SUMMARY' && $title !== 'REFUNDED') {
        $ws->getStyle('J2:J' . max(2, $ws->getHighestRow()))->getNumberFormat()->setFormatCode('#,##0.00');
        $ws->getStyle('N2:P' . max(2, $ws->getHighestRow()))->getNumberFormat()->setFormatCode('#,##0.00');
    }
    trl_auto_size($ws, \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($ws->getHighestColumn()));
}

$spreadsheet->setActiveSheetIndex(0);

$safePartner = preg_replace('/[^a-zA-Z0-9\-_ ]+/', '', $partnerName);
$safePartner = trim(preg_replace('/\s+/', '_', $safePartner));
if ($safePartner === '') {
    $safePartner = 'Partner';
}

$filename = 'TRL_Report_' . $safePartner . '_' . date('Ymd_His') . '.xlsx';

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
