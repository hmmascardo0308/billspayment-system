<?php
include '../../../../config/config.php';
session_start();
include '../../../../templates/middleware.php';
require_once '../../../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

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

$isAllPartners = $partnerKey === 'all';
$isPartnerMasterfile = strpos($partnerKey, 'masterfile:') === 0 || strpos($partnerKey, 'directbiller:') === 0;
$partnerId = preg_replace('/^(masterfile|directbiller|subbiller):/', '', $partnerKey);
$partnerName = $isAllPartners ? 'All Partners' : '';

$masterfileIdExpression = "CASE WHEN COALESCE(TRIM(partner_id_kpx), '') <> '' THEN CONVERT(TRIM(partner_id_kpx) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci ELSE CONVERT(TRIM(COALESCE(partner_id, '')) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci END";
$masterfileIdSExpression = "CASE WHEN COALESCE(TRIM(s.partner_id_kpx), '') <> '' THEN CONVERT(TRIM(s.partner_id_kpx) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci ELSE CONVERT(TRIM(COALESCE(s.partner_id, '')) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci END";

if (!$isAllPartners) {
    $partnerTable = $isPartnerMasterfile ? 'masterdata.partner_masterfile' : 'mldb.subbiller';
    $partnerIdExpression = $isPartnerMasterfile
        ? $masterfileIdExpression
        : "TRIM(COALESCE(partner_id_kpx, ''))";
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
            $partnerResult = $partnerStmt->get_result();
            if ($partnerResult && ($partnerRow = $partnerResult->fetch_assoc())) {
                $partnerName = (string) ($partnerRow['partner_name'] ?? '');
            }
        }
        $partnerStmt->close();
    }

    if ($partnerName === '') {
        http_response_code(404);
        exit('Partner not found');
    }
}

$branchColumn = 'payment_branch';
$columnCheck = mysqli_query($conn, "SHOW COLUMNS FROM mldb.trl LIKE 'payment_branch'");
if (!$columnCheck || mysqli_num_rows($columnCheck) === 0) {
    $fallbackCheck = mysqli_query($conn, "SHOW COLUMNS FROM mldb.trl LIKE 'payment_branch_name'");
    $branchColumn = ($fallbackCheck && mysqli_num_rows($fallbackCheck) > 0)
        ? 'payment_branch_name'
        : null;
}
$branchSelect = $branchColumn !== null ? "t.{$branchColumn} AS payment_branch" : "'' AS payment_branch";

$partnerJoin = '';
$partnerWhere = '';
if (!$isAllPartners) {
    if ($isPartnerMasterfile) {
        $partnerJoin = "INNER JOIN masterdata.partner_masterfile s
            ON CONVERT(TRIM(CAST(t.wrong_biller_id AS CHAR)) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci = {$masterfileIdSExpression}
            OR (TRIM(COALESCE(t.biller_name, '')) <> ''
                AND CONVERT(UPPER(TRIM(t.biller_name)) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci =
                    CONVERT(UPPER(TRIM(s.partner_name)) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci)";
        $partnerWhere = "{$masterfileIdSExpression} = CONVERT(? USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AND";
    } else {
        $partnerJoin = "INNER JOIN mldb.subbiller s
            ON CAST(t.wrong_biller_id AS CHAR) = CAST(s.sub_billers_id AS CHAR)";
        $partnerWhere = 's.partner_id_kpx = ? AND';
    }
}

$sql = "SELECT
        t.trl_no,
        t.transfer_datetime,
        t.ref_no,
        t.wrong_biller_id,
        t.biller_name,
        t.account_no,
        t.name,
        t.payment_branch_id,
        {$branchSelect},
        t.amount,
        t.type_of_request,
        wb.correct_biller_id,
        wb.correct_biller_name,
        oa.wrong_amount AS oa_wrong_amount,
        oa.correct_amount AS oa_correct_amount,
        oa.difference AS oa_difference,
        ct.wrong_amount AS ct_wrong_amount,
        ct.correct_amount AS ct_correct_amount,
        t.reason,
        t.status
    FROM mldb.trl t
    {$partnerJoin}
    LEFT JOIN mldb.trl_wrongbiller wb ON wb.trl_no = t.trl_no
    LEFT JOIN mldb.trl_overstatedamount oa ON oa.trl_no = t.trl_no
    LEFT JOIN mldb.trl_cancelledtransaction ct ON ct.trl_no = t.trl_no
    WHERE {$partnerWhere} t.status = 'REFUNDED'
    ORDER BY t.transfer_datetime DESC, t.trl_no DESC";

$rows = [];
$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    exit('Unable to prepare refunded export');
}

if (!$isAllPartners) {
    $stmt->bind_param('s', $partnerId);
}

if (!$stmt->execute()) {
    $stmt->close();
    http_response_code(500);
    exit('Unable to load refunded transactions');
}

$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $type = strtoupper(trim((string) ($row['type_of_request'] ?? '')));
    $wrongAmount = null;
    $correctAmount = null;
    $difference = null;

    if ($type === 'OVERSTATED AMOUNT') {
        $wrongAmount = $row['oa_wrong_amount'];
        $correctAmount = $row['oa_correct_amount'];
        $difference = $row['oa_difference'];
    } elseif ($type === 'CANCELLED TRANSACTION') {
        $wrongAmount = $row['ct_wrong_amount'];
        $correctAmount = $row['ct_correct_amount'];
    }

    $rows[] = [
        (string) ($row['trl_no'] ?? ''),
        (string) ($row['transfer_datetime'] ?? ''),
        (string) ($row['ref_no'] ?? ''),
        (string) ($row['wrong_biller_id'] ?? ''),
        (string) ($row['biller_name'] ?? ''),
        (string) ($row['account_no'] ?? ''),
        (string) ($row['name'] ?? ''),
        (string) ($row['payment_branch_id'] ?? ''),
        (string) ($row['payment_branch'] ?? ''),
        (float) ($row['amount'] ?? 0),
        $type,
        (string) ($row['correct_biller_id'] ?? ''),
        (string) ($row['correct_biller_name'] ?? ''),
        $wrongAmount === null ? '-' : (float) $wrongAmount,
        $correctAmount === null ? '-' : (float) $correctAmount,
        $difference === null ? '-' : (float) $difference,
        (string) ($row['reason'] ?? ''),
        (string) ($row['status'] ?? ''),
    ];
}
$stmt->close();

$headers = [
    'TRL NO.', 'TRANS. DATE/TIME', 'REF. NO.', 'WRONG BILLER ID', 'BILLER NAME',
    'ACCOUNT NO.', 'NAME', 'PAYMENT BRANCH ID', 'PAYMENT BRANCH', 'AMOUNT',
    'TYPE OF REQUEST', 'CORRECT BILLER ID', 'CORRECT BILLER NAME',
    'WRONG AMOUNT', 'CORRECT AMOUNT', 'DIFFERENCE', 'REASON', 'STATUS'
];

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('REFUNDED');
$sheet->fromArray($headers, null, 'A1');
$sheet->getStyle('A1:R1')->getFont()->setBold(true);
$sheet->getStyle('A1:R1')->getFill()
    ->setFillType(Fill::FILL_SOLID)
    ->getStartColor()->setARGB('FFF3F4F6');
$sheet->getStyle('A1:R1')->getAlignment()
    ->setHorizontal(Alignment::HORIZONTAL_CENTER)
    ->setVertical(Alignment::VERTICAL_CENTER);
$sheet->freezePane('A2');
$sheet->setAutoFilter('A1:R1');

if (!empty($rows)) {
    $sheet->fromArray($rows, null, 'A2');
}

$lastRow = max(2, count($rows) + 1);
foreach ($rows as $index => $row) {
    $excelRow = $index + 2;
    foreach (['A', 'C', 'D', 'F', 'H', 'L'] as $column) {
        $valueIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($column) - 1;
        $sheet->setCellValueExplicit($column . $excelRow, (string) $row[$valueIndex], DataType::TYPE_STRING);
    }
}

$sheet->getStyle('J2:J' . $lastRow)->getNumberFormat()->setFormatCode('#,##0.00');
$sheet->getStyle('N2:P' . $lastRow)->getNumberFormat()->setFormatCode('#,##0.00');
$sheet->getStyle('J2:J' . $lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
$sheet->getStyle('N2:P' . $lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

for ($columnIndex = 1; $columnIndex <= count($headers); $columnIndex++) {
    $column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnIndex);
    $sheet->getColumnDimension($column)->setAutoSize(true);
}

$safePartner = preg_replace('/[^a-zA-Z0-9\-_ ]+/', '', $partnerName);
$safePartner = trim(preg_replace('/\s+/', '_', $safePartner));
if ($safePartner === '') {
    $safePartner = 'Partner';
}
$filename = 'TRL_Refunded_' . $safePartner . '_' . date('Ymd_His') . '.xlsx';

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
