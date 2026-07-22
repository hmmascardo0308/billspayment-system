<?php
include '../../../../config/config.php';
session_start();
include '../../../../templates/middleware.php';

header('Content-Type: application/json; charset=utf-8');

function trl_json_response($statusCode, $payload)
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

$id = resolve_user_identifier();
if (empty($id)) {
    trl_json_response(401, ['ok' => false, 'message' => 'Unauthorized']);
}

if (!function_exists('has_any_permission') || !has_any_permission(['TRL Report', 'Bills Payment'])) {
    trl_json_response(403, ['ok' => false, 'message' => 'Forbidden']);
}

$partnerKey = trim((string) ($_GET['partner_id'] ?? ''));
$subbillerId = trim((string) ($_GET['subbiller_id'] ?? ''));

if ($partnerKey === '' || $subbillerId === '') {
    trl_json_response(400, ['ok' => false, 'message' => 'Missing partner_id or subbiller_id']);
}

$isAllPartners = $partnerKey === 'all';
$isPartnerMasterfile = strpos($partnerKey, 'masterfile:') === 0 || strpos($partnerKey, 'directbiller:') === 0;
$partnerId = preg_replace('/^(masterfile|directbiller|subbiller):/', '', $partnerKey);
$partnerMasterfileIdSql = "CASE WHEN COALESCE(TRIM(partner_id_kpx), '') <> '' THEN CONVERT(TRIM(partner_id_kpx) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci ELSE CONVERT(TRIM(COALESCE(partner_id, '')) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci END";
$usesBillerName = $isAllPartners && strpos($subbillerId, 'name:') === 0;
$billerNameValue = $usesBillerName ? trim(substr($subbillerId, 5)) : '';

if ($usesBillerName && $billerNameValue === '') {
    trl_json_response(400, ['ok' => false, 'message' => 'Missing biller name']);
}

$partnerName = '';
$subbillerName = '';

$metaSql = $usesBillerName
    ? "SELECT 'All' AS partner_name,
              COALESCE(NULLIF(TRIM(biller_name), ''), 'UNKNOWN BILLER') AS sub_biller_name
       FROM mldb.trl
       WHERE UPPER(TRIM(COALESCE(biller_name, ''))) = UPPER(TRIM(?))
       LIMIT 1"
    : ($isAllPartners
    ? "SELECT 'All' AS partner_name,
              COALESCE(NULLIF(TRIM(biller_name), ''), CONCAT('BILLER ', TRIM(COALESCE(wrong_biller_id, '')))) AS sub_biller_name
       FROM mldb.trl
       WHERE CAST(wrong_biller_id AS CHAR) = CAST(? AS CHAR)
       LIMIT 1"
    : ($isPartnerMasterfile
    ? "SELECT TRIM(COALESCE(partner_name, '')) AS partner_name,
              COALESCE(NULLIF(TRIM(partner_name), ''), 'UNKNOWN BILLER') AS sub_biller_name
       FROM masterdata.partner_masterfile
       WHERE " . $partnerMasterfileIdSql . " = CONVERT(? USING utf8mb4) COLLATE utf8mb4_0900_ai_ci
         AND " . $partnerMasterfileIdSql . " = CONVERT(? USING utf8mb4) COLLATE utf8mb4_0900_ai_ci
       LIMIT 1"
    : "SELECT TRIM(COALESCE(partner_name, '')) AS partner_name,
              COALESCE(NULLIF(TRIM(sub_billers_name), ''), 'UNKNOWN BILLER') AS sub_biller_name
       FROM mldb.subbiller
       WHERE TRIM(COALESCE(partner_id_kpx, '')) = ?
         AND CAST(sub_billers_id AS CHAR) = CAST(? AS CHAR)
       LIMIT 1"));
$metaStmt = $conn->prepare($metaSql);

if ($metaStmt) {
    if ($usesBillerName) {
        $metaStmt->bind_param('s', $billerNameValue);
    } elseif ($isAllPartners) {
        $metaStmt->bind_param('s', $subbillerId);
    } else {
        $metaStmt->bind_param('ss', $partnerId, $subbillerId);
    }
    if ($metaStmt->execute()) {
        $metaRes = $metaStmt->get_result();
        if ($metaRes && ($metaRow = $metaRes->fetch_assoc())) {
            $partnerName = trim((string) ($metaRow['partner_name'] ?? ''));
            $subbillerName = (string) ($metaRow['sub_biller_name'] ?? 'UNKNOWN BILLER');
        }
    }
    $metaStmt->close();
}

if ($partnerName === '') {
    trl_json_response(404, ['ok' => false, 'message' => 'Subbiller not found for the selected partner']);
}

$summaryYears = [];
$summaryByYear = [];
$summaryTotal = 0.0;

$transactionFilter = $usesBillerName
    ? "UPPER(TRIM(COALESCE(t.biller_name, ''))) = UPPER(TRIM(?))"
    : ($isPartnerMasterfile
    ? "(CAST(t.wrong_biller_id AS CHAR) = CAST(? AS CHAR)
         OR (TRIM(COALESCE(t.biller_name, '')) <> '' AND TRIM(t.biller_name) = TRIM(?)))"
    : "CAST(t.wrong_biller_id AS CHAR) = CAST(? AS CHAR)");

$summaryStmt = $conn->prepare(
    "SELECT
        YEAR(t.transfer_datetime) AS report_year,
        SUM(COALESCE(t.amount, 0)) AS total_amount
     FROM mldb.trl t
     WHERE {$transactionFilter}
       AND t.transfer_datetime IS NOT NULL
       AND t.status IS NULL
     GROUP BY YEAR(t.transfer_datetime)
     ORDER BY report_year ASC"
);

if ($summaryStmt) {
    if ($usesBillerName) {
        $summaryStmt->bind_param('s', $billerNameValue);
    } elseif ($isPartnerMasterfile) {
        $summaryStmt->bind_param('ss', $subbillerId, $subbillerName);
    } else {
        $summaryStmt->bind_param('s', $subbillerId);
    }
    if ($summaryStmt->execute()) {
        $summaryRes = $summaryStmt->get_result();
        while ($s = $summaryRes->fetch_assoc()) {
            $year = (int) ($s['report_year'] ?? 0);
            $amount = (float) ($s['total_amount'] ?? 0);
            if ($year <= 0) {
                continue;
            }

            $summaryYears[] = (string) $year;
            $summaryByYear[(string) $year] = $amount;
            $summaryTotal += $amount;
        }
    }
    $summaryStmt->close();
}

$rows = [];

$detailSql = "
    SELECT
        t.trl_no,
        t.transfer_datetime,
        t.ref_no,
        t.wrong_biller_id,
        t.biller_name,
        t.account_no,
        t.name,
        t.payment_branch_id,
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
    WHERE {$transactionFilter}
      AND t.status IS NULL
    ORDER BY t.transfer_datetime DESC, t.trl_no DESC
";

$detailStmt = $conn->prepare($detailSql);
if ($detailStmt) {
    if ($usesBillerName) {
        $detailStmt->bind_param('s', $billerNameValue);
    } elseif ($isPartnerMasterfile) {
        $detailStmt->bind_param('ss', $subbillerId, $subbillerName);
    } else {
        $detailStmt->bind_param('s', $subbillerId);
    }
    if ($detailStmt->execute()) {
        $detailRes = $detailStmt->get_result();
        while ($r = $detailRes->fetch_assoc()) {
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

            $rows[] = [
                'trl_no' => (string) ($r['trl_no'] ?? ''),
                'transfer_datetime' => (string) ($r['transfer_datetime'] ?? ''),
                'ref_no' => (string) ($r['ref_no'] ?? ''),
                'wrong_biller_id' => (string) ($r['wrong_biller_id'] ?? ''),
                'biller_name' => (string) ($r['biller_name'] ?? ''),
                'account_no' => (string) ($r['account_no'] ?? ''),
                'customer_name' => (string) ($r['name'] ?? ''),
                'payment_branch_id' => (string) ($r['payment_branch_id'] ?? ''),
                'amount' => (float) ($r['amount'] ?? 0),
                'type_of_request' => $typeNorm,
                'correct_biller_id' => (string) ($r['correct_biller_id'] ?? ''),
                'correct_biller_name' => (string) ($r['correct_biller_name'] ?? ''),
                'wrong_amount_display' => $wrongAmount !== null ? number_format((float) $wrongAmount, 2) : '-',
                'correct_amount_display' => $correctAmount !== null ? number_format((float) $correctAmount, 2) : '-',
                'difference_display' => $difference !== null ? number_format((float) $difference, 2) : '-',
                'reason' => (string) ($r['reason'] ?? ''),
            ];
        }
    }
    $detailStmt->close();
}

trl_json_response(200, [
    'ok' => true,
    'partner_id' => $partnerKey,
    'partner_name' => $partnerName,
    'subbiller_id' => $subbillerId,
    'subbiller_name' => $subbillerName,
    'summary' => [
        'years' => $summaryYears,
        'by_year' => $summaryByYear,
        'total' => $summaryTotal,
    ],
    'rows' => $rows,
]);
