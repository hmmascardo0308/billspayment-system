<?php
include '../../../../config/config.php';
require '../../../../vendor/autoload.php';
session_start();

include '../../../../templates/middleware.php';
$id = resolve_user_identifier();
if (empty($id)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!function_exists('has_any_permission') || !has_any_permission(['TRL Import', 'Bills Payment'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

use PhpOffice\PhpSpreadsheet\IOFactory;

header('Content-Type: application/json');

// If this is a request to remove duplicates from an existing session set
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'remove_duplicates') {
    $duplicates = $_POST['duplicates'] ?? [];
    if (!is_array($duplicates)) $duplicates = [$duplicates];

    $rows = $_SESSION['trl_import_rows'] ?? [];
    if (empty($rows)) {
        echo json_encode(['success' => false, 'message' => 'No session rows to filter.']);
        exit;
    }

    $dupsMap = array_flip(array_map('strval', $duplicates));
    $filtered = array_values(array_filter($rows, function($r) use ($dupsMap) {
        $ref = isset($r['ref_no']) ? (string) $r['ref_no'] : '';
        return $ref === '' || !isset($dupsMap[$ref]);
    }));

    $_SESSION['trl_import_rows'] = $filtered;
    $_SESSION['trl_import_summary'] = [
        'total_rows' => count($filtered),
        'duplicate_rows' => 0,
        'unique_rows' => count($filtered)
    ];

    $_SESSION['trl_import_duplicate_result'] = [
        'checked' => true,
        'all_unique' => true,
        'duplicates' => []
    ];

    echo json_encode([
        'success' => true,
        'message' => 'Duplicates removed from session rows.',
        'redirect' => 'trl-import-preview.php',
        'total_rows' => count($filtered),
        'no_new_rows' => count($filtered) === 0
    ]);
    exit;
}

if (!isset($_FILES['files']) || !is_array($_FILES['files']['name'])) {
    echo json_encode(['success' => false, 'message' => 'No files uploaded.']);
    exit;
}

function trl_normalize_header($value) {
    $value = strtoupper(trim((string) $value));
    $value = preg_replace('/\s+/', ' ', $value);
    return $value;
}

function trl_find_header_row($sheet, $maxRows = 30) {
    $requiredHeaders = [
        'TRANS. DATE/TIME', // mldb.trl.transfer_datetime
        'REF. NO.', // mldb.trl.ref_no
        'WRONG BILLER ID', // mldb.trl.wrong_biller_id
        'BILLER NAME', // mldb.trl.biller_name
        'ACCOUNT NO.', // mldb.trl.account_no
        'NAME', // mldb.trl.name
        'PAYMENT BRANCH ID', // mldb.trl.payment_branch_id
        'PAYMENT BRANCH', // mldb.trl.payment_branch
        'AMOUNT', // mldb.trl.amount
        'TYPE OF REQUEST', // mldb.trl.type_of_request
        'CORRECT BILLER ID', // mldb.trl.correct_biller_id
        'CORRECT BILLER NAME', // mldb.trl.correct_biller_name
        'REASON', // mldb.trl.reason
        'WRONG AMOUNT', // mldb.trl_overstatedamount.wrong_amount or mldb.trl_cancelledtransaction.wrong_amount
        'CORRECT AMOUNT', // mldb.trl_overstatedamount.correct_amount or mldb.trl_cancelledtransaction.correct_amount
        'REPORTED VALUE', // backward-compatible alias for wrong_amount
        'ACTUAL VALUE', // backward-compatible alias for correct_amount
        'DIFFERENCE' // mldb.trl_overstatedamount.difference
    ];

    $highestColumn = $sheet->getHighestColumn();
    for ($row = 1; $row <= $maxRows; $row++) {
        $headersFound = [];
        for ($col = 'A'; $col <= $highestColumn; $col++) {
            $cellValue = trl_normalize_header($sheet->getCell($col . $row)->getValue());
            if ($cellValue !== '') {
                $headersFound[$cellValue] = $col;
            }
            if ($col === 'ZZ') {
                break;
            }
        }

        $matched = 0;
        foreach ($requiredHeaders as $required) {
            if (isset($headersFound[trl_normalize_header($required)])) {
                $matched++;
            }
        }

        $legacyHeaders = [
            'TRANS. DATE/TIME',
            'REF. NO.',
            'BILLER NAME',
            'ACCOUNT NO.',
            'NAME',
            'PAYMENT BRANCH',
            'AMOUNT',
            'REASON'
        ];
        $legacyMatched = 0;
        foreach ($legacyHeaders as $legacyHeader) {
            if (isset($headersFound[trl_normalize_header($legacyHeader)])) {
                $legacyMatched++;
            }
        }

        if ($matched >= 9 || $legacyMatched === count($legacyHeaders)) {
            return [$row, $headersFound];
        }
    }

    return [null, []];
}

function trl_parse_datetime($value) {
    if ($value === null || $value === '') {
        return null;
    }

    if (is_numeric($value)) {
        try {
            return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value)->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            return null;
        }
    }

    $timestamp = strtotime((string) $value);
    if ($timestamp === false) {
        return null;
    }

    return date('Y-m-d H:i:s', $timestamp);
}

function trl_normalize_request_type($value) {
    return strtoupper(trim((string) $value));
}

function trl_lookup_key($value) {
    $value = strtoupper(trim((string) $value));
    $value = preg_replace('/[^A-Z0-9]+/', ' ', $value);
    return trim(preg_replace('/\s+/', ' ', $value));
}

function trl_find_lookup_match($value, $lookup, $minimumScore = 0.88) {
    $key = trl_lookup_key($value);
    if ($key === '') {
        return null;
    }
    if (isset($lookup[$key])) {
        return $lookup[$key];
    }

    $best = null;
    $bestScore = 0.0;
    foreach ($lookup as $candidateKey => $candidate) {
        $maxLength = max(strlen($key), strlen($candidateKey));
        if ($maxLength === 0) {
            continue;
        }
        $score = 1 - (levenshtein($key, $candidateKey) / $maxLength);
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $candidate;
        }
    }

    return $bestScore >= $minimumScore ? $best : null;
}

function trl_infer_request_type($reason) {
    $reason = trl_normalize_request_type($reason);
    $types = [
        'NO PAYMENT RECEIVED',
        'DOUBLE POSTING',
        'MULTI POSTING',
        'TRIPLE POSTING',
        'WRONG BILLER',
        'OVERSTATED AMOUNT',
        'CANCELLED TRANSACTION',
        'UNREFLECTED TRXN',
        'UNREFLECTED TRANSACTION'
    ];
    foreach ($types as $type) {
        if (strpos($reason, $type) !== false) {
            return $type === 'UNREFLECTED TRANSACTION' ? 'UNREFLECTED TRXN' : $type;
        }
    }
    return 'UNSPECIFIED';
}

function trl_extract_intended_biller($reason) {
    if (preg_match('/\bINTENDED\s+(?:FOR|TO)\s+(.+?)\s*$/i', (string) $reason, $matches)) {
        return trim($matches[1], " \t\n\r\0\x0B.-");
    }
    return '';
}

function trl_extract_reason_amounts($reason) {
    $number = '([0-9][0-9,]*(?:\.[0-9]+)?)';
    $pattern = '/OVERSTATED\s+AMOUNT\s*(?:PHP)?\s*' . $number
        . '\s+INSTEAD\s+OF\s+(?:PHP)?\s*' . $number
        . '\s+WITH\s+THE\s+DIFFERENCE\s+OF\s+(?:PHP)?\s*' . $number . '/i';

    if (!preg_match($pattern, (string) $reason, $matches)) {
        return [null, null, null];
    }

    return [
        (float) str_replace(',', '', $matches[1]),
        (float) str_replace(',', '', $matches[2]),
        (float) str_replace(',', '', $matches[3])
    ];
}

$subBillerLookup = [];
$subBillerResult = mysqli_query($conn, "SELECT sub_billers_id, sub_billers_name FROM mldb.subbiller WHERE TRIM(COALESCE(sub_billers_name, '')) <> ''");
if ($subBillerResult) {
    while ($subBiller = mysqli_fetch_assoc($subBillerResult)) {
        $key = trl_lookup_key($subBiller['sub_billers_name'] ?? '');
        if ($key !== '' && !isset($subBillerLookup[$key])) {
            $subBillerLookup[$key] = [
                'id' => (string) ($subBiller['sub_billers_id'] ?? ''),
                'name' => (string) ($subBiller['sub_billers_name'] ?? '')
            ];
        }
    }
}

$directBillerLookup = [];
$directBillerResult = mysqli_query($conn, "SELECT partner_id_kpx, partner_name FROM mldb.directbiller WHERE TRIM(COALESCE(partner_name, '')) <> ''");
if ($directBillerResult) {
    while ($directBiller = mysqli_fetch_assoc($directBillerResult)) {
        $key = trl_lookup_key($directBiller['partner_name'] ?? '');
        if ($key !== '' && !isset($directBillerLookup[$key])) {
            $directBillerLookup[$key] = [
                'id' => (string) ($directBiller['partner_id_kpx'] ?? ''),
                'name' => (string) ($directBiller['partner_name'] ?? '')
            ];
        }
    }
}

$branchLookup = [];
$branchResult = mysqli_query($conn, "SELECT branch_id, branch_name FROM masterdata.branch_profile WHERE TRIM(COALESCE(branch_name, '')) <> ''");
if ($branchResult) {
    while ($branch = mysqli_fetch_assoc($branchResult)) {
        $key = trl_lookup_key($branch['branch_name'] ?? '');
        if ($key !== '' && !isset($branchLookup[$key])) {
            $branchLookup[$key] = [
                'id' => (string) ($branch['branch_id'] ?? ''),
                'name' => (string) ($branch['branch_name'] ?? '')
            ];
        }
    }
}

$allRows = [];
$fileResults = [];
$fileCount = count($_FILES['files']['name']);

for ($i = 0; $i < $fileCount; $i++) {
    if ($_FILES['files']['error'][$i] !== UPLOAD_ERR_OK) {
        $fileResults[] = [
            'fileName' => $_FILES['files']['name'][$i] ?? 'Unknown',
            'totalRows' => 0,
            'duplicateRows' => 0,
            'isUnique' => false,
            'error' => 'Upload error.'
        ];
        continue;
    }

    $tmpPath = $_FILES['files']['tmp_name'][$i];
    $fileName = $_FILES['files']['name'][$i];

    try {
        $reader = IOFactory::createReaderForFile($tmpPath);
        if (method_exists($reader, 'setReadDataOnly')) {
            $reader->setReadDataOnly(true);
        }
        $spreadsheet = $reader->load($tmpPath);
        $rowsForFile = 0;
        $processedSheets = 0;
        $skippedSheets = [];

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $sheetName = trim((string) $sheet->getTitle());

            list($headerRow, $headerMap) = trl_find_header_row($sheet);
            if ($headerRow === null) {
                $skippedSheets[] = $sheetName !== '' ? $sheetName : 'Untitled sheet';
                continue;
            }
            $processedSheets++;

        $legacyFormat = !isset($headerMap[trl_normalize_header('TYPE OF REQUEST')])
            && !isset($headerMap[trl_normalize_header('WRONG BILLER ID')])
            && !isset($headerMap[trl_normalize_header('PAYMENT BRANCH ID')]);

        $sheetBillerName = '';
        for ($titleRow = 1; $titleRow < $headerRow; $titleRow++) {
            $candidate = trim((string) $sheet->getCell('A' . $titleRow)->getValue());
            if ($candidate !== '') {
                $sheetBillerName = $candidate;
                break;
            }
        }
        // Legacy workbooks sometimes use an operational abbreviation in the
        // sheet title instead of the catalog partner name.
        $sheetBillerAliases = [
            'METROBANK RTA' => [
                'lookup_name' => 'METROBANK COLLECTION',
                'display_name' => 'METROBANK REMIT TO ACCOUNT',
                'force_group' => true
            ],
            'RUBELS MOTOR PARTS' => [
                'lookup_name' => '',
                'display_name' => 'RUBELS MOTOR PARTS',
                'force_group' => true
            ]
        ];
        $sheetBillerAliasKey = trl_lookup_key($sheetBillerName);
        $usesSheetBillerAlias = isset($sheetBillerAliases[$sheetBillerAliasKey]);
        $sheetBillerLookupName = $usesSheetBillerAlias
            ? $sheetBillerAliases[$sheetBillerAliasKey]['lookup_name']
            : $sheetBillerName;
        $sheetBiller = trl_find_lookup_match($sheetBillerLookupName, $subBillerLookup);
        if (!$sheetBiller) {
            $sheetBiller = trl_find_lookup_match($sheetBillerLookupName, $directBillerLookup);
        }
        $sheetBillerDisplayName = $usesSheetBillerAlias
            ? $sheetBillerAliases[$sheetBillerAliasKey]['display_name']
            : (string) ($sheetBiller['name'] ?? $sheetBillerName);
        $forceSheetBillerGroup = $usesSheetBillerAlias
            && !empty($sheetBillerAliases[$sheetBillerAliasKey]['force_group']);

        $requiredMap = [
            'TRANS. DATE/TIME' => 'transfer_datetime',
            'REF. NO.' => 'ref_no',
            'WRONG BILLER ID' => 'wrong_biller_id',
            'BILLER NAME' => 'biller_name',
            'ACCOUNT NO.' => 'account_no',
            'NAME' => 'name',
            'PAYMENT BRANCH ID' => 'payment_branch_id',
            'PAYMENT BRANCH' => 'payment_branch',
            'AMOUNT' => 'amount',
            'TYPE OF REQUEST' => 'type_of_request',
            'CORRECT BILLER ID' => 'correct_biller_id',
            'CORRECT BILLER NAME' => 'correct_biller_name',
            'REASON' => 'reason',
            'WRONG AMOUNT' => 'wrong_amount',
            'CORRECT AMOUNT' => 'correct_amount',
            'REPORTED VALUE' => 'wrong_amount',
            'ACTUAL VALUE' => 'correct_amount',
            'DIFFERENCE' => 'difference_value'
        ];

        $highestRow = $sheet->getHighestRow();

        for ($row = $headerRow + 1; $row <= $highestRow; $row++) {
            $sectionMarkerA = trl_normalize_header($sheet->getCell('A' . $row)->getValue());
            $sectionMarkerB = trl_normalize_header($sheet->getCell('B' . $row)->getValue());
            if ($sectionMarkerA === 'MBTC COL' && $sectionMarkerB === 'MBTC RTA') {
                break;
            }

            $record = [
                'transfer_datetime' => null,
                'ref_no' => '',
                'wrong_biller_id' => '',
                'biller_name' => '',
                'account_no' => '',
                'name' => '',
                'payment_branch_id' => '',
                'payment_branch' => '',
                'amount' => 0,
                'type_of_request' => '',
                'correct_biller_id' => '',
                'correct_biller_name' => '',
                'wrong_amount' => null,
                'correct_amount' => null,
                'difference_value' => null,
                'reason' => '',
                'duplicate_ok' => true,
                'source_file' => $fileName,
                'source_sheet' => $sheetName,
                'source_format' => $legacyFormat ? 'legacy' : 'standard'
            ];

            $hasData = false;
            foreach ($requiredMap as $excelHeader => $fieldName) {
                $normalized = trl_normalize_header($excelHeader);
                $col = $headerMap[$normalized] ?? null;
                if (!$col) {
                    continue;
                }

                $rawValue = $sheet->getCell($col . $row)->getValue();
                $value = is_string($rawValue) ? trim($rawValue) : $rawValue;

                if ($value !== null && $value !== '') {
                    $hasData = true;
                }

                if ($fieldName === 'transfer_datetime') {
                    $record[$fieldName] = trl_parse_datetime($value);
                } elseif ($fieldName === 'amount') {
                    // AMOUNT is a core column; keep numeric conversion with 0 fallback.
                    $record[$fieldName] = is_numeric($value) ? (float) $value : (float) str_replace(',', '', (string) $value);
                } elseif ($fieldName === 'wrong_amount' || $fieldName === 'correct_amount' || $fieldName === 'difference_value') {
                    // Optional supplemental numeric columns should remain NULL when empty.
                    if ($value === null || $value === '') {
                        $record[$fieldName] = null;
                    } else {
                        $parsed = is_numeric($value) ? (float) $value : (float) str_replace(',', '', (string) $value);
                        $record[$fieldName] = $parsed;
                    }
                } else {
                    $record[$fieldName] = trim((string) $value);
                }
            }

            if ($legacyFormat) {
                $record['type_of_request'] = trl_infer_request_type($record['reason']);

                $rowBiller = $forceSheetBillerGroup
                    ? null
                    : trl_find_lookup_match($record['biller_name'], $subBillerLookup);
                $wrongBiller = $rowBiller ?: $sheetBiller;
                if ($wrongBiller) {
                    $record['wrong_biller_id'] = $wrongBiller['id'];
                }
                if ($forceSheetBillerGroup || ($rowBiller === null && $sheetBillerName !== '' && $sheetBiller)) {
                    $record['biller_name'] = $sheetBillerDisplayName;
                }

                $branch = trl_find_lookup_match($record['payment_branch'], $branchLookup, 0.94);
                if ($branch) {
                    $record['payment_branch_id'] = $branch['id'];
                    $record['payment_branch'] = $branch['name'];
                }

                if ($record['type_of_request'] === 'WRONG BILLER') {
                    $intendedBillerName = trl_extract_intended_biller($record['reason']);
                    $intendedBiller = trl_find_lookup_match($intendedBillerName, $subBillerLookup, 0.84);
                    if (!$intendedBiller) {
                        $intendedBiller = trl_find_lookup_match($intendedBillerName, $directBillerLookup, 0.84);
                    }
                    $record['correct_biller_id'] = $intendedBiller ? $intendedBiller['id'] : '';
                    $record['correct_biller_name'] = $intendedBiller ? $intendedBiller['name'] : $intendedBillerName;
                }

                if ($record['type_of_request'] === 'OVERSTATED AMOUNT') {
                    list($wrongAmount, $correctAmount, $difference) = trl_extract_reason_amounts($record['reason']);
                    $record['wrong_amount'] = $wrongAmount;
                    $record['correct_amount'] = $correctAmount;
                    $record['difference_value'] = $difference;
                }
            }

            // Canonicalize request type and keep only type-owned supplemental fields.
            $type = trl_normalize_request_type($record['type_of_request'] ?? '');
            $record['type_of_request'] = $type;

            // Correct biller fields belong only to WRONG BILLER rows.
            if ($type !== 'WRONG BILLER') {
                $record['correct_biller_id'] = '';
                $record['correct_biller_name'] = '';
            }

            // Amount supplemental fields belong only to OVERSTATED/CANCELLED.
            if ($type !== 'OVERSTATED AMOUNT' && $type !== 'CANCELLED TRANSACTION') {
                $record['wrong_amount'] = null;
                $record['correct_amount'] = null;
            }

            // Difference belongs only to OVERSTATED AMOUNT.
            if ($type !== 'OVERSTATED AMOUNT') {
                $record['difference_value'] = null;
            }

            if (!$hasData) {
                continue;
            }

            // Totals, spacers, and footers may appear between multiple TRL
            // sections in the same worksheet. Skip them instead of stopping.
            $refVal = isset($record['ref_no']) ? trim((string) $record['ref_no']) : '';
            if ($refVal === '') {
                continue;
            }

            // A worksheet may repeat its table header for another section.
            if (trl_normalize_header($refVal) === trl_normalize_header('REF. NO.')) {
                continue;
            }

            $allRows[] = $record;
            $rowsForFile++;
        }

        }

        $fileResults[] = [
            'fileName' => $fileName,
            'totalRows' => $rowsForFile,
            'duplicateRows' => 0,
            'isUnique' => $processedSheets > 0,
            'error' => $processedSheets > 0 ? null : 'No worksheet with a valid TRL header was found.',
            'sheetCount' => $spreadsheet->getSheetCount(),
            'processedSheetCount' => $processedSheets,
            'skippedSheets' => $skippedSheets
        ];

        if (isset($spreadsheet) && is_object($spreadsheet)) {
            try {
                $spreadsheet->disconnectWorksheets();
            } catch (Exception $e) {
            }
        }
    } catch (Exception $e) {
        $fileResults[] = [
            'fileName' => $fileName,
            'totalRows' => 0,
            'duplicateRows' => 0,
            'isUnique' => false,
            'error' => $e->getMessage()
        ];
    }
}

if (empty($allRows)) {
    echo json_encode([
        'success' => false,
        'message' => 'No valid rows were found in the uploaded file(s).',
        'files' => $fileResults
    ]);
    exit;
}

// Persist parsed rows in session first
$_SESSION['trl_import_rows'] = $allRows;

// Check duplicates by `ref_no` against mldb.trl.ref_no
$refNos = [];
foreach ($allRows as $r) {
    $ref = isset($r['ref_no']) ? trim((string) $r['ref_no']) : '';
    if ($ref !== '') $refNos[$ref] = true;
}

$duplicates = [];
if (!empty($refNos)) {
    $escaped = [];
    foreach (array_keys($refNos) as $v) {
        $escaped[] = "'" . mysqli_real_escape_string($conn, $v) . "'";
    }
    $in = implode(',', $escaped);
    $sql = "SELECT ref_no FROM trl WHERE ref_no IN ($in)";
    $res = mysqli_query($conn, $sql);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $duplicates[] = (string) $row['ref_no'];
        }
    }
}

$duplicates = array_values(array_unique($duplicates));

// mark rows that are duplicates
$duplicateCount = 0;
if (!empty($duplicates)) {
    $dupMap = array_flip($duplicates);
    foreach ($_SESSION['trl_import_rows'] as &$r) {
        $ref = isset($r['ref_no']) ? (string) $r['ref_no'] : '';
        if ($ref !== '' && isset($dupMap[$ref])) {
            $r['duplicate_ok'] = false;
            $duplicateCount++;
        } else {
            $r['duplicate_ok'] = true;
        }
    }
    unset($r);
}

// Update per-file duplicate counts in fileResults
if (!empty($duplicates)) {
    $dupsMap = array_flip($duplicates);
    foreach ($fileResults as &$fr) {
        $count = 0;
        foreach ($_SESSION['trl_import_rows'] as $r) {
            if (($r['source_file'] ?? '') === ($fr['fileName'] ?? '') && isset($r['ref_no']) && $r['ref_no'] !== '' && isset($dupsMap[$r['ref_no']])) {
                $count++;
            }
        }
        $fr['duplicateRows'] = $count;
        if ($count > 0) $fr['isUnique'] = false;
    }
    unset($fr);
}

$_SESSION['trl_import_summary'] = [
    'total_rows' => count($_SESSION['trl_import_rows']),
    'duplicate_rows' => $duplicateCount,
    'unique_rows' => count($_SESSION['trl_import_rows']) - $duplicateCount
];

$_SESSION['trl_import_duplicate_result'] = [
    'checked' => true,
    'all_unique' => empty($duplicates),
    'duplicates' => $duplicates
];

// If duplicates found, return them so the frontend can prompt the user
if (!empty($duplicates)) {
    echo json_encode([
        'success' => true,
        'message' => 'TRL data fetched. Duplicates found.',
        'files' => $fileResults,
        'duplicates' => $duplicates
    ]);
    exit;
}

// otherwise redirect to preview
echo json_encode([
    'success' => true,
    'message' => 'TRL data fetched and duplicate check passed.',
    'files' => $fileResults,
    'redirect' => 'trl-import-preview.php'
]);
exit;
