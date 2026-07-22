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

$partnerKey = trim((string) ($_GET['partner_id'] ?? ''));
if ($partnerKey === '') {
    http_response_code(400);
    exit('Missing partner_id');
}

$isPartnerMasterfile = strpos($partnerKey, 'masterfile:') === 0 || strpos($partnerKey, 'directbiller:') === 0;
$partnerId = preg_replace('/^(masterfile|directbiller|subbiller):/', '', $partnerKey);
$partnerTable = $isPartnerMasterfile ? 'masterdata.partner_masterfile' : 'masterdata.subbiller';
$partnerIdExpression = $isPartnerMasterfile
    ? "CASE WHEN COALESCE(TRIM(partner_id_kpx), '') <> '' THEN CONVERT(TRIM(partner_id_kpx) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci ELSE CONVERT(TRIM(COALESCE(partner_id, '')) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci END"
    : "TRIM(COALESCE(partner_id_kpx, ''))";
$partnerIdSExpression = "CASE WHEN COALESCE(TRIM(s.partner_id_kpx), '') <> '' THEN CONVERT(TRIM(s.partner_id_kpx) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci ELSE CONVERT(TRIM(COALESCE(s.partner_id, '')) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci END";

$partnerName = '';
$partnerStmt = $conn->prepare(
    "SELECT TRIM(COALESCE(partner_name, '')) AS partner_name
     FROM {$partnerTable}
     WHERE {$partnerIdExpression} = ?
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

$includeSummary = true;
if (isset($_GET['include_summary'])) {
    $val = strtolower(trim((string) $_GET['include_summary']));
    $includeSummary = in_array($val, ['1','true','yes','y'], true);
}

$selected = [];
if (isset($_GET['subbiller_ids']) && $_GET['subbiller_ids'] !== '') {
    $selected = array_filter(array_map('trim', explode(',', (string) $_GET['subbiller_ids'])));
} elseif (isset($_GET['subbiller']) && is_array($_GET['subbiller'])) {
    $selected = array_map('trim', $_GET['subbiller']);
}

if (empty($selected)) {
    http_response_code(400);
    exit('No subbiller selected');
}


function trl_set_header_row($sheet, $colCount) {
    $endCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colCount);
    $sheet->getStyle('A1:' . $endCol . '1')->getFont()->setBold(true);
    $sheet->getStyle('A1:' . $endCol . '1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF3F4F6');
}

function trl_auto_size($sheet, $colCount) {
    for ($i = 1; $i <= $colCount; $i++) {
        $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }
}

function trl_sheet_name($name) {
    $safe = preg_replace('/[\[\]\:\*\?\/\\\\]/', ' ', (string) $name);
    $safe = trim(preg_replace('/\s+/', ' ', $safe));
    if ($safe === '') {
        $safe = 'Sheet';
    }
    return mb_substr($safe, 0, 31);
}

// prepare subbiller names map
$subMap = [];
$placeholders = [];
foreach ($selected as $s) {
    $placeholders[] = $conn->real_escape_string((string)$s);
}

$inList = "'" . implode("','", $placeholders) . "'";

$mapSql = $isPartnerMasterfile
    ? "SELECT " . $partnerIdExpression . " AS id,
              COALESCE(NULLIF(TRIM(partner_name), ''), 'UNKNOWN BILLER') AS name
       FROM masterdata.partner_masterfile
       WHERE " . $partnerIdExpression . " = CONVERT(? USING utf8mb4) COLLATE utf8mb4_0900_ai_ci
         AND " . $partnerIdExpression . " IN ($inList)
       ORDER BY partner_name ASC"
    : "SELECT TRIM(COALESCE(sub_billers_id, '')) AS id,
              COALESCE(NULLIF(TRIM(sub_billers_name), ''), 'UNKNOWN BILLER') AS name
       FROM masterdata.subbiller
       WHERE TRIM(COALESCE(partner_id_kpx, '')) = ?
         AND sub_billers_id IN ($inList)
       ORDER BY sub_billers_name ASC";
$mapStmt = $conn->prepare($mapSql);
if ($mapStmt) {
    $mapStmt->bind_param('s', $partnerId);
    if ($mapStmt->execute()) {
        $res = $mapStmt->get_result();
        while ($r = $res->fetch_assoc()) { $subMap[(string)$r['id']] = (string)$r['name']; }
    }
    $mapStmt->close();
}

$spreadsheet = new Spreadsheet();

// If includeSummary, build the filtered SUMMARY sheet
if ($includeSummary) {
    $yearColumns = [];
    $rowsBySubBiller = [];
    $totalsByYear = [];
    $grandTotal = 0.0;

    $summaryJoin = $isPartnerMasterfile
        ? "INNER JOIN masterdata.partner_masterfile s
            ON CONVERT(TRIM(CAST(t.wrong_biller_id AS CHAR)) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci = " . $partnerIdSExpression . "
            OR (TRIM(COALESCE(t.biller_name, '')) <> '' AND CONVERT(UPPER(TRIM(t.biller_name)) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci = CONVERT(UPPER(TRIM(s.partner_name)) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci)"
        : "INNER JOIN masterdata.subbiller s ON BINARY TRIM(CAST(t.wrong_biller_id AS CHAR)) = BINARY TRIM(CAST(s.sub_billers_id AS CHAR))";
    $summaryName = $isPartnerMasterfile ? 's.partner_name' : 's.sub_billers_name';
    $summaryId = $isPartnerMasterfile ? $partnerIdSExpression : 's.sub_billers_id';
    $summaryPartnerId = $isPartnerMasterfile
        ? $partnerIdSExpression
        : 's.partner_id_kpx';
    $summarySql = "
        SELECT COALESCE(NULLIF(TRIM({$summaryName}), ''), 'UNKNOWN BILLER') AS sub_biller_name, YEAR(t.transfer_datetime) AS report_year, SUM(COALESCE(t.amount,0)) AS total_amount
        FROM mldb.trl t
        {$summaryJoin}
        WHERE {$summaryPartnerId} = ?
          AND t.transfer_datetime IS NOT NULL
          AND t.status IS NULL
          AND {$summaryId} IN ($inList)
        GROUP BY COALESCE(NULLIF(TRIM({$summaryName}), ''), 'UNKNOWN BILLER'), YEAR(t.transfer_datetime)
        ORDER BY sub_biller_name ASC, report_year ASC
    ";

    $sStmt = $conn->prepare($summarySql);
    if ($sStmt) {
        $sStmt->bind_param('s', $partnerId);
        if ($sStmt->execute()) {
            $res = $sStmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $sb = (string) ($r['sub_biller_name'] ?? 'UNKNOWN BILLER');
                $yr = (int) ($r['report_year'] ?? 0);
                $amt = (float) ($r['total_amount'] ?? 0);
                if ($yr <= 0) continue;
                $yearColumns[$yr] = true;
                if (!isset($rowsBySubBiller[$sb])) { $rowsBySubBiller[$sb] = ['name'=>$sb,'years'=>[],'total'=>0.0]; }
                $rowsBySubBiller[$sb]['years'][$yr] = $amt;
                $rowsBySubBiller[$sb]['total'] += $amt;
                if (!isset($totalsByYear[$yr])) $totalsByYear[$yr] = 0.0;
                $totalsByYear[$yr] += $amt;
                $grandTotal += $amt;
            }
        }
        $sStmt->close();
    }

    $yearColumns = array_keys($yearColumns); sort($yearColumns);

    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('SUMMARY');
    $firstHeader = strtoupper((string)$partnerName) . "\n" . ($isPartnerMasterfile ? 'BILLER' : 'SUB BILLERS');
    $summaryHeaders = [$firstHeader];
    foreach ($yearColumns as $y) $summaryHeaders[] = (string)$y;
    $summaryHeaders[] = 'TOTAL AMOUNT';
    $sheet->fromArray($summaryHeaders, null, 'A1');
    trl_set_header_row($sheet, count($summaryHeaders));
    $sheet->getStyle('A1')->getAlignment()->setWrapText(true)->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getRowDimension(1)->setRowHeight(36);

    $rIdx = 2;
    foreach ($rowsBySubBiller as $row) {
        $line = [$row['name']];
        foreach ($yearColumns as $y) $line[] = isset($row['years'][$y]) ? (float)$row['years'][$y] : '-';
        $line[] = (float)$row['total'];
        $sheet->fromArray($line, null, 'A' . $rIdx); $rIdx++;
    }

    $totalsLine = ['TOTAL'];
    foreach ($yearColumns as $y) $totalsLine[] = isset($totalsByYear[$y]) ? (float)$totalsByYear[$y] : '-';
    $totalsLine[] = (float)$grandTotal;
    $sheet->fromArray($totalsLine, null, 'A' . $rIdx);
    $endCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($summaryHeaders));
    $sheet->getStyle('A' . $rIdx . ':' . $endCol . $rIdx)->getFont()->setBold(true);

    // highlight totals and add Grand Total row similar to main exporter
    $lastColIdx = count($summaryHeaders);
    $lastColLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($lastColIdx);
    if ($rIdx > 2) {
        $sheet->getStyle($lastColLetter . '2:' . $lastColLetter . ($rIdx - 1))->getFont()->getColor()->setARGB('FFFF0000');
    }
    $sheet->getStyle($lastColLetter . $rIdx)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFF00');
    $sheet->getStyle($lastColLetter . $rIdx)->getFont()->getColor()->setARGB('FF000000');
    $sheet->getStyle($lastColLetter . $rIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

    $spacerRow = $rIdx + 1; $sheet->setCellValue('A' . $spacerRow, '');
    $grandRow = $spacerRow + 1;
    $penultColIdx = max(1, $lastColIdx - 1);
    $penultColLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($penultColIdx);
    $sheet->setCellValue($penultColLetter . $grandRow, 'Grand Total');
    $sheet->setCellValue($lastColLetter . $grandRow, (float)$grandTotal);
    $sheet->getStyle($penultColLetter . $grandRow)->getFont()->setBold(true)->getColor()->setARGB('FF000000');
    $sheet->getStyle($penultColLetter . $grandRow)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFF00');
    $sheet->getStyle($lastColLetter . $grandRow)->getFont()->setBold(true)->getColor()->setARGB('FF000000');
    $sheet->getStyle($lastColLetter . $grandRow)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFF00');
    $sheet->getStyle($lastColLetter . $grandRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $sheet->getStyle($penultColLetter . $grandRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

    trl_auto_size($sheet, \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestColumn()));
}

// add per-subbiller sheets (filtered selection)
$sheetNamesUsed = [];
if ($includeSummary) {
    $sheetNamesUsed['SUMMARY'] = true;
}

$dynHeaders = ['TRL NO.','TRANS. DATE/TIME','REF. NO.','WRONG BILLER ID','BILLER NAME','ACCOUNT NO.','NAME','PAYMENT BRANCH ID','PAYMENT BRANCH','AMOUNT','TYPE OF REQUEST','CORRECT BILLER ID','CORRECT BILLER NAME','WRONG AMOUNT','CORRECT AMOUNT','DIFFERENCE','REASON'];

$dynFilter = $isPartnerMasterfile
    ? "(CAST(t.wrong_biller_id AS CHAR) = CAST(? AS CHAR)
        OR (TRIM(COALESCE(t.biller_name, '')) <> '' AND TRIM(t.biller_name) = TRIM(?)))"
    : "CAST(t.wrong_biller_id AS CHAR) = CAST(? AS CHAR)";
$dynSql = "SELECT t.trl_no, t.transfer_datetime, t.ref_no, t.wrong_biller_id, t.biller_name, t.account_no, t.name, t.payment_branch_id, t.amount, t.type_of_request, t.reason, wb.correct_biller_id, wb.correct_biller_name, oa.wrong_amount AS oa_wrong_amount, oa.correct_amount AS oa_correct_amount, oa.difference AS oa_difference, ct.wrong_amount AS ct_wrong_amount, ct.correct_amount AS ct_correct_amount FROM mldb.trl t LEFT JOIN mldb.trl_wrongbiller wb ON wb.trl_no = t.trl_no LEFT JOIN mldb.trl_overstatedamount oa ON oa.trl_no = t.trl_no LEFT JOIN mldb.trl_cancelledtransaction ct ON ct.trl_no = t.trl_no WHERE {$dynFilter} AND t.status IS NULL ORDER BY t.transfer_datetime DESC, t.trl_no DESC";

$dynStmt = $conn->prepare($dynSql);
$firstSubUsed = false;
foreach ($selected as $sid) {
    $sname = isset($subMap[$sid]) ? $subMap[$sid] : $sid;
    $baseName = trl_sheet_name($sname);
    $sheetName = $baseName; $counter = 2;
    while (isset($sheetNamesUsed[$sheetName])) { $suffix = ' ' . $counter; $sheetName = mb_substr($baseName, 0, max(1, 31 - strlen($suffix))) . $suffix; $counter++; }
    $sheetNamesUsed[$sheetName] = true;

        // Reuse the active worksheet for the first sub-biller when SUMMARY is not included
        if (!$includeSummary && !$firstSubUsed) {
            $sbSheet = $spreadsheet->getActiveSheet();
            $sbSheet->setTitle($sheetName);
            $firstSubUsed = true;
        } else {
            $sbSheet = $spreadsheet->createSheet();
            $sbSheet->setTitle($sheetName);
        }
    $sbSheet->fromArray($dynHeaders, null, 'A1'); trl_set_header_row($sbSheet, count($dynHeaders));

    $dynRows = [];
    $totalAmount = 0.0;
    if ($dynStmt) {
        if ($isPartnerMasterfile) {
            $dynStmt->bind_param('ss', $sid, $sname);
        } else {
            $dynStmt->bind_param('s', $sid);
        }
        if ($dynStmt->execute()) {
            $res = $dynStmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $typeNorm = strtoupper(trim((string) ($r['type_of_request'] ?? '')));
                $wrongAmount = null; $correctAmount = null; $difference = null;
                if ($typeNorm === 'OVERSTATED AMOUNT') { $wrongAmount = $r['oa_wrong_amount']; $correctAmount = $r['oa_correct_amount']; $difference = $r['oa_difference']; }
                elseif ($typeNorm === 'CANCELLED TRANSACTION') { $wrongAmount = $r['ct_wrong_amount']; $correctAmount = $r['ct_correct_amount']; }
                $amount = (float) ($r['amount'] ?? 0);
                $totalAmount += $amount;
                $dynRows[] = [(int)($r['trl_no']??0),(string)($r['transfer_datetime']??''),(string)($r['ref_no']??''),(string)($r['wrong_biller_id']??''),(string)($r['biller_name']??''),(string)($r['account_no']??''),(string)($r['name']??''),(string)($r['payment_branch_id']??''), '', $amount, $typeNorm, (string)($r['correct_biller_id']??''),(string)($r['correct_biller_name']??''), $wrongAmount ?? '-', $correctAmount ?? '-', $difference ?? '-', (string)($r['reason']??'')];
            }
        }
    }

    if (!empty($dynRows)) {
        $sbSheet->fromArray($dynRows, null, 'A2');
        // ensure ACCOUNT NO. (col F) as text
        for ($i = 0; $i < count($dynRows); $i++) {
            $acct = (string) $dynRows[$i][5]; $rowIndex = 2 + $i; $sbSheet->setCellValueExplicit('F' . $rowIndex, $acct, DataType::TYPE_STRING);
        }
    }

    $lastDataRow = max(1, 1 + count($dynRows));
    $totalRow = $lastDataRow + 2;
    $sbSheet->setCellValue('I' . $totalRow, 'Total Amount');
    $sbSheet->setCellValue('J' . $totalRow, $totalAmount);
    $sbSheet->getStyle('I' . $totalRow . ':J' . $totalRow)->getFont()->setBold(true);
    $sbSheet->getStyle('I' . $totalRow)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFF00');
    $sbSheet->getStyle('J' . $totalRow)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFF00');

    $bodyEnd = max(2, $lastDataRow);
    $sbSheet->getStyle('A2:A' . $bodyEnd)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $sbSheet->getStyle('B2:I' . $bodyEnd)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $sbSheet->getStyle('J2:J' . $bodyEnd)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

    trl_auto_size($sbSheet, \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sbSheet->getHighestColumn()));
}

$spreadsheet->setActiveSheetIndex(0);

$safePartner = preg_replace('/[^a-zA-Z0-9\-_ ]+/', '', $partnerName);
$safePartner = trim(preg_replace('/\s+/', '_', $safePartner)); if ($safePartner === '') $safePartner = 'Partner';
$filename = 'TRL-' . $safePartner . '_' . date('Ymd_His') . '.xlsx';
while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
$writer = new Xlsx($spreadsheet); $writer->save('php://output'); exit;
