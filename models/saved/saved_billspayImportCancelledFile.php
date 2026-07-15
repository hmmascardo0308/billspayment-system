<?php

require_once __DIR__ . '/../../config/config.php';
require '../../vendor/autoload.php';

// Start the session
session_start();

if (isset($_SESSION['user_type'])) {
    $current_user_email = '';
    if ($_SESSION['user_type'] === 'admin' && isset($_SESSION['admin_email'])) {
        $current_user_email = $_SESSION['admin_email'];
    } elseif ($_SESSION['user_type'] === 'user' && isset($_SESSION['user_email'])) {
        $current_user_email = $_SESSION['user_email'];
        if($_SESSION['user_email'] === 'balb01013333' || $_SESSION['user_email'] === 'pera94005055'){
            header("Location:../../index.php");
            session_destroy();
            exit();
        }
    }else{
        header("Location:../../index.php");
        session_destroy();
        exit();
    }
}

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Helper: resolve partner name from partner id or partner id kpx
 */
function getPartnerName($conn, $partnerId) {
    if (empty($partnerId)) return 'Unknown Partner';
    $query = "SELECT partner_name FROM masterdata.partner_masterfile WHERE partner_id = ? OR partner_id_kpx = ? LIMIT 1";
    $stmt = $conn->prepare($query);
    if (!$stmt) return 'Unknown Partner';
    $stmt->bind_param("ss", $partnerId, $partnerId);
    $stmt->execute();
    $result = $stmt->get_result();
    $name = 'Unknown Partner';
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $name = $row['partner_name'];
    }
    $stmt->close();
    return $name;
}

/**
 * Resolve partner search key (G3 or supplied partner identifier) to partner_id / partner_id_kpx / partner_name
 */
function findPartnerRecord($conn, $search)
{
    $out = ['partner_id'=>null,'partner_id_kpx'=>null,'partner_name'=>null,'gl_code'=>null];
    if (empty($search)) return $out;

    // try partner_id_kpx
    $sql = "SELECT partner_id, partner_id_kpx, partner_name, gl_code FROM masterdata.partner_masterfile WHERE partner_id_kpx = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('s', $search);
        $stmt->execute();
        $r = $stmt->get_result();
        if ($r && $r->num_rows>0) { $row = $r->fetch_assoc(); $out['partner_id']=$row['partner_id']??null; $out['partner_id_kpx']=$row['partner_id_kpx']??null; $out['partner_name']=$row['partner_name']??null; $out['gl_code']=$row['gl_code']??null; $stmt->close(); return $out; }
        $stmt->close();
    }

    // try partner_id
    $sql = "SELECT partner_id, partner_id_kpx, partner_name, gl_code FROM masterdata.partner_masterfile WHERE partner_id = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('s', $search);
        $stmt->execute();
        $r = $stmt->get_result();
        if ($r && $r->num_rows>0) { $row = $r->fetch_assoc(); $out['partner_id']=$row['partner_id']??null; $out['partner_id_kpx']=$row['partner_id_kpx']??null; $out['partner_name']=$row['partner_name']??null; $out['gl_code']=$row['gl_code']??null; $stmt->close(); return $out; }
        $stmt->close();
    }

    // try partner_name (case-insensitive)
    $sql = "SELECT partner_id, partner_id_kpx, partner_name, gl_code FROM masterdata.partner_masterfile WHERE LOWER(partner_name) = LOWER(?) LIMIT 1";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('s', $search);
        $stmt->execute();
        $r = $stmt->get_result();
        if ($r && $r->num_rows>0) { $row = $r->fetch_assoc(); $out['partner_id']=$row['partner_id']??null; $out['partner_id_kpx']=$row['partner_id_kpx']??null; $out['partner_name']=$row['partner_name']??null; $out['gl_code']=$row['gl_code']??null; $stmt->close(); return $out; }
        $stmt->close();
    }

    return $out;
}


ini_set('memory_limit', '-1');
set_time_limit(0);
// Enable mysqli exceptions for clearer DB errors during import
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// AJAX Endpoint: Check for Duplicate Records (batch)
if (isset($_POST['check_duplicates']) && isset($_FILES['files'])) {
    header('Content-Type: application/json');
    $results = [];
    $fileCount = count($_FILES['files']['name']);

    for ($i = 0; $i < $fileCount; $i++) {
        if ($_FILES['files']['error'][$i] === UPLOAD_ERR_OK) {
            $tmpPath = $_FILES['files']['tmp_name'][$i];
            $fileName = $_FILES['files']['name'][$i];
            $partnerId = $_POST['partner_ids'][$i] ?? '';
            // resolve partner search key (G3) to actual partner ids
            $resolved = findPartnerRecord($conn, $partnerId);
            $p_resolved_id = $resolved['partner_id'] ?? null;
            $p_resolved_kpx = $resolved['partner_id_kpx'] ?? null;
            $sourceType = $_POST['source_types'][$i] ?? '';

            try {
                // Load spreadsheet (works with .xls/.xlsx/.csv when Excel-format)
                $spreadsheet = IOFactory::load($tmpPath);
                $worksheet = $spreadsheet->getActiveSheet();
                $highestRow = $worksheet->getHighestRow();

                $totalRows = 0;
                $duplicateRows = 0;
                $newRows = 0;
                $postedRows = 0;
                $unpostedRows = 0;

                // Cancellation files: data rows start at row 7 (B..Q columns)
                for ($row = 7; $row <= $highestRow; $row++) {
                    $cellB = trim((string)$worksheet->getCell('B' . $row)->getValue()); // datetime
                    $cellD = trim((string)$worksheet->getCell('D' . $row)->getValue()); // reference_no

                    // stop on empty row
                    if ($cellB === '' && $cellD === '') break;

                    $totalRows++;

                    $reference_number = $cellD;
                    $datetimeValue = $cellB;
                    $datetime = null;

                    if (is_numeric($datetimeValue)) {
                        $datetime = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($datetimeValue)->format('Y-m-d H:i:s');
                    } elseif (!empty($datetimeValue)) {
                        $datetime = date('Y-m-d H:i:s', strtotime($datetimeValue));
                    }

                    if (empty($reference_number) || empty($datetime)) {
                        $newRows++;
                        continue;
                    }

                    // Check DB for duplicates (transaction table) and also cancellation table
                    $row_count_total = 0;

                    // First check billspayment_transaction for posted/unposted matches
                    $sql = "SELECT post_transaction, COUNT(*) as cnt FROM mldb.billspayment_transaction WHERE reference_no = ? AND (`datetime` = ? OR cancellation_date = ? )";
                    if (!empty($partnerId) && strtoupper($partnerId) !== 'ALL' && (!empty($p_resolved_id) || !empty($p_resolved_kpx))) {
                        $sql .= " AND (partner_id = ? OR partner_id_kpx = ?)";
                        $sql .= " GROUP BY post_transaction";
                        $stmt = $conn->prepare($sql);
                        $stmt->bind_param("sssss", $reference_number, $datetime, $datetime, $p_resolved_id, $p_resolved_kpx);
                    } else {
                        $sql .= " GROUP BY post_transaction";
                        $stmt = $conn->prepare($sql);
                        $stmt->bind_param("sss", $reference_number, $datetime, $datetime);
                    }
                    $stmt->execute();
                    $result = $stmt->get_result();
                    if ($result) {
                        while ($r = $result->fetch_assoc()) {
                            $cnt = intval($r['cnt']);
                            $row_count_total += $cnt;
                            $status = isset($r['post_transaction']) ? strtolower(trim($r['post_transaction'])) : '';
                            if ($status === 'posted') $postedRows += $cnt; else $unpostedRows += $cnt;
                        }
                    }
                    $stmt->close();

                    // Also check billspayment_cancellation table for existing reference_no
                    $cSql = "SELECT COUNT(*) as cnt FROM mldb.billspayment_cancellation WHERE reference_no = ?";
                    if (!empty($partnerId) && strtoupper($partnerId) !== 'ALL' && (!empty($p_resolved_id) || !empty($p_resolved_kpx))) {
                        $cSql .= " AND (partner_id = ? OR partner_id_kpx = ?)";
                        $cStmt = $conn->prepare($cSql);
                        if ($cStmt) { $cStmt->bind_param("sss", $reference_number, $p_resolved_id, $p_resolved_kpx); $cStmt->execute(); $cRes = $cStmt->get_result(); if ($cRes && ($crow = $cRes->fetch_assoc())) { $ccnt = intval($crow['cnt']); if ($ccnt > 0) { $row_count_total += $ccnt; $postedRows += $ccnt; } } $cStmt->close(); }
                    } else {
                        $cStmt = $conn->prepare($cSql);
                        if ($cStmt) { $cStmt->bind_param("s", $reference_number); $cStmt->execute(); $cRes = $cStmt->get_result(); if ($cRes && ($crow = $cRes->fetch_assoc())) { $ccnt = intval($crow['cnt']); if ($ccnt > 0) { $row_count_total += $ccnt; $postedRows += $ccnt; } } $cStmt->close(); }
                    }

                    if ($row_count_total > 0) $duplicateRows++; else $newRows++;
                }

                // Free resources
                if (isset($spreadsheet) && is_object($spreadsheet)) {
                    try { $spreadsheet->disconnectWorksheets(); } catch (Exception $e) {}
                    unset($worksheet, $spreadsheet);
                    if (function_exists('gc_collect_cycles')) gc_collect_cycles();
                }

                $results[] = [
                    'fileName' => $fileName,
                    'partnerId' => $partnerId,
                    'sourceType' => $sourceType,
                    'totalRows' => $totalRows,
                    'duplicateRows' => $duplicateRows,
                    'newRows' => $newRows,
                    'postedRows' => $postedRows,
                    'unpostedRows' => $unpostedRows,
                    'hasDuplicates' => ($duplicateRows > 0)
                ];

            } catch (Exception $e) {
                $results[] = [ 'fileName' => $fileName, 'error' => $e->getMessage() ];
            }
        }
    }

    echo json_encode(['success' => true, 'files' => $results]);
    exit;
}

/**
 * Simple dispatcher helpers for source types (KPX / KP7).
 * KP7 is currently under development; KPX uses the existing import flow below.
 */
function handleKP7()
{
    echo '<script>alert("KP7 import is under development.");window.history.back();</script>';
    exit;
}

/**
 * Handle KPX import (existing cancellation flow).
 * Kept as a function to separate KPX/KP7 handling.
 */
function handleKPXImport($tmpPath, $fileName, $fileExt)
{
    global $conn;
    // replicate the existing KPX cancellation import flow
    $rows = [];
    $startRow = 7; // 1-based
    $startColIndex = 1; // B -> index 1 (0-based)
    $endColIndex = 16; // Q -> index 16 (0-based), inclusive

    // read partner name from POST (support both 'partner_name' and 'partner')
    $partnerName = $_POST['partner_name'] ?? $_POST['partner'] ?? '';
    // get partner_id, partner_id_kpx, gl_code converted based on selected partner name for cancellation
    $partnerSQL = "SELECT DISTINCT partner_name, partner_id, partner_id_kpx, gl_code FROM masterdata.partner_masterfile WHERE partner_name = ? LIMIT 1";
    $partnerId = null;
    $partnerIdKpx = null;
    $glCode = null;
    if (!empty($partnerName) && strtoupper($partnerName) !== 'ALL') {
        $stmt = $conn->prepare($partnerSQL);
        $stmt->bind_param("s", $partnerName);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $partnerId = $row['partner_id'];
            $partnerIdKpx = $row['partner_id_kpx'];
            $glCode = $row['gl_code'];
        }
        $stmt->close();
    }

    // get branch_id, branch_code, zone_code, region_code, region based on reference number provided in POST
    $referenceNo = $_POST['reference_no'] ?? '';
    $BranchNameSql = "SELECT DISTINCT branch_id, branch_code, zone_code, region_code, region FROM billspayment_transaction WHERE reference_no = ? LIMIT 1";
    $branchId = null;
    $branchCode = null;
    $zoneCode = null;
    $regionCode = null;
    $region = null;
    if (!empty($referenceNo)) {
        $stmt = $conn->prepare($BranchNameSql);
        $stmt->bind_param("s", $referenceNo);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $branchId = $row['branch_id'];
            $branchCode = $row['branch_code'];
            $zoneCode = $row['zone_code'];
            $regionCode = $row['region_code'];
            $region = $row['region'];
        }
        $stmt->close();
    }

    try {
        // Attempt to extract report date from cell A3 (common to both CSV and XLSX)
        $report_date_raw = '';
        $report_date = null;
        try {
            $tmpSp = IOFactory::load($tmpPath);
            $tmpWs = $tmpSp->getActiveSheet();
            $a3 = trim((string)$tmpWs->getCell('A3')->getValue());
            if ($a3 !== '') {
                if (preg_match('/([A-Za-z]+\s+\d{1,2}\s+\d{4})/i', $a3, $mm)) {
                    $report_date_raw = trim($mm[1]);
                    $ts = strtotime($report_date_raw);
                    if ($ts !== false) $report_date = date('Y-m-d', $ts);
                } else {
                    $ts2 = strtotime($a3);
                    if ($ts2 !== false) { $report_date_raw = trim($a3); $report_date = date('Y-m-d', $ts2); }
                }
            }
            if (isset($tmpSp) && is_object($tmpSp)) { try { $tmpSp->disconnectWorksheets(); } catch (Exception $e) {} unset($tmpWs,$tmpSp); }
        } catch (Exception $e) {
            // ignore extraction errors
        }
        if ($fileExt === 'csv') {
            // Parse CSV
            $handle = fopen($tmpPath, 'r');
            if ($handle === false) throw new Exception('Unable to open uploaded CSV file.');

            $rowIndex = 0;
            while (($data = fgetcsv($handle)) !== false) {
                $rowIndex++;
                if ($rowIndex < $startRow) continue; // skip until start row

                // Ensure the row has enough columns
                $rowData = [];
                $allEmpty = true;
                for ($i = $startColIndex; $i <= $endColIndex; $i++) {
                    $value = isset($data[$i]) ? trim((string)$data[$i]) : '';
                    $rowData[] = $value;
                    if ($value !== '') $allEmpty = false;
                }

                if ($allEmpty) break;
                $rows[] = $rowData;
            }
            fclose($handle);

            // If CSV parse yielded no rows (could be due to unusual delimiters or embedded Excel content),
            // try loading with PhpSpreadsheet as a fallback (it can handle many CSV variations and Excel-wrapped files).
            if (empty($rows)) {
                try {
                    $spreadsheet = IOFactory::load($tmpPath);
                    $worksheet = $spreadsheet->getActiveSheet();
                    $highestRow = $worksheet->getHighestRow();

                    for ($r = $startRow; $r <= $highestRow; $r++) {
                        $rowData = [];
                        $allEmpty = true;
                        for ($c = 2; $c <= 17; $c++) {
                            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c);
                            $cell = $worksheet->getCell($colLetter . $r);
                            $cellValue = $cell !== null ? $cell->getValue() : '';
                            $value = trim((string)$cellValue);
                            $rowData[] = $value;
                            if ($value !== '') $allEmpty = false;
                        }
                        if ($allEmpty) break;
                        $rows[] = $rowData;
                    }
                    if (isset($spreadsheet) && is_object($spreadsheet)) {
                        try { $spreadsheet->disconnectWorksheets(); } catch (Exception $e) {}
                        unset($worksheet, $spreadsheet);
                        if (function_exists('gc_collect_cycles')) gc_collect_cycles();
                    }
                } catch (Exception $e) {
                    // swallow fallback errors; original check below will show the friendly alert
                }
            }
        } elseif (in_array($fileExt, ['xls', 'xlsx'])) {
            // Use PhpSpreadsheet for Excel files
            $spreadsheet = IOFactory::load($tmpPath);
            $worksheet = $spreadsheet->getActiveSheet();
            $highestRow = $worksheet->getHighestRow();

            for ($r = $startRow; $r <= $highestRow; $r++) {
                $rowData = [];
                $allEmpty = true;
                // Column B..Q correspond to column numbers 2..17
                for ($c = 2; $c <= 17; $c++) {
                    $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c);
                    $cell = $worksheet->getCell($colLetter . $r);
                    $cellValue = $cell !== null ? $cell->getValue() : '';
                    $value = trim((string)$cellValue);
                    $rowData[] = $value;
                    if ($value !== '') $allEmpty = false;
                }
                if ($allEmpty) break;
                $rows[] = $rowData;
            }
            // Free resources
            if (isset($spreadsheet) && is_object($spreadsheet)) {
                try { $spreadsheet->disconnectWorksheets(); } catch (Exception $e) {}
                unset($worksheet, $spreadsheet);
                if (function_exists('gc_collect_cycles')) gc_collect_cycles();
            }
        } else {
            throw new Exception('Unsupported file type. Accepted: .csv, .xls, .xlsx');
        }

        if (empty($rows)) {
            echo '<script>alert("No data found starting at row 7 in columns B to Q.");window.history.back();</script>';
            exit;
        }

        $outDir = __DIR__ . '/temporary';
        if (!is_dir($outDir)) mkdir($outDir, 0777, true);

        $outFile = $outDir . '/import_cancelled_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.json';
        file_put_contents($outFile, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // store import metadata in session for display on summary
        $uploadedBy = '';
        if (isset($_SESSION['user_type'])) {
            if ($_SESSION['user_type'] === 'admin' && isset($_SESSION['admin_name'])) $uploadedBy = $_SESSION['admin_name'];
            elseif ($_SESSION['user_type'] === 'user' && isset($_SESSION['user_name'])) $uploadedBy = $_SESSION['user_name'];
        }
        $source = $_POST['fileType'] ?? $_POST['source'] ?? '';

        $_SESSION['import_meta'] = [
            'partner_id' => $partnerId,
            'partner_id_kpx' => $partnerIdKpx,
            'gl_code' => $glCode,
            'partner_name' => $partnerName,
            'reference_no' => $referenceNo,
            'source' => $source,
            'report_date' => $report_date,
            'report_date_raw' => $report_date_raw,
            'uploaded_date' => date('Y-m-d'),
            'uploaded_by' => $uploadedBy,
            'branch_id' => $branchId,
            'branch_code' => $branchCode,
            'zone_code' => $zoneCode,
            'region_code' => $regionCode,
            'region' => $region,
            'rows_saved' => count($rows),
            'json_file' => basename($outFile)
        ];

        echo '<script>alert("File processed successfully. Rows saved: ' . count($rows) . '");window.location.href = "' . basename(__FILE__) . '";</script>';
        exit;
    } catch (Exception $e) {
        echo '<script>alert("Error processing file: ' . addslashes($e->getMessage()) . '");window.history.back();</script>';
        exit;
    }
}

/**
 * Insert KPX cancellation rows into DB.
 * Uses global $conn. Returns array result for JSON response.
 */
function insertKPXCancellationRows(array $rowsToInsert, array $meta)
{
    global $conn;

    $insertSQL = "INSERT INTO mldb.billspayment_cancellation (
        cancellation_datetime,
        report_date,
        sendout_datetime,
        source_file,
        control_no, 
        reference_no, 
        ir_no,
        payor, 
        account_no, 
        account_name, 
        principal_amount, 
        charge_to_customer, 
        charge_to_partner,
        cancellation_charge, 
        resource, 
        branch_id, 
        branch_code, 
        branch_name, 
        zone_code, 
        region_code,
        region, 
        remote_branch, 
        remote_operator, 
        partner_name, 
        partner_id, 
        partner_id_kpx, 
        mpm_gl_code,
        imported_by, 
        imported_date
    ) VALUES (" . rtrim(str_repeat('?,', 29), ',') . ")";

    $stmt = $conn->prepare($insertSQL);
    if (!$stmt) return ['success' => false, 'error' => 'Prepare failed: ' . $conn->error];

    // types: cancellation_datetime(s), report_date(s), sendout_datetime(s), source_file(s), control_no(s), reference_no(s), ir_no(s), payor(s), account_no(s), account_name(s),
    // principal_amount(d), charge_to_customer(d), charge_to_partner(d), cancellation_charge(d),
    // resource(s), branch_id(s), branch_code(s), branch_name(s), zone_code(s), region_code(s), region(s), remote_branch(s), remote_operator(s), partner_name(s), partner_id(s), partner_id_kpx(s), mpm_gl_code(s), imported_by(s), imported_date(s)
    $types = str_repeat('s', 10) . 'dddd' . str_repeat('s', 15); // 10s + 4d + 15s = 29

    $conn->begin_transaction();
    $inserted = 0;
    $errors = [];

    $cleanNumber = function($v) {
        $s = trim((string)$v);
        if ($s === '') return 0.0;
        $s = preg_replace('/[\(\)\$,\s]/', '', $s);
        $s = preg_replace('/[^0-9.\-]/', '', $s);
        return is_numeric($s) ? floatval($s) : 0.0;
    };

    try {
        foreach ($rowsToInsert as $row) {
            // same mapping as previous inline logic
            $cancellation_datetime = date('Y-m-d H:i:s', strtotime($row[0]));
            // report_date may be supplied in meta as Y-m-d; normalize or null
            $report_date = isset($meta['report_date']) && $meta['report_date'] !== '' ? $meta['report_date'] : null;
            $sendout_datetime = date('Y-m-d H:i:s', strtotime($row[1] ?? ''));
            $source_file = $meta['source'] ?? '';
            $control_no = $row[3] ?? null;
            $reference_no = $row[2] ?? null;
            $ir_no = $row[7] ?? null;
            $payor = $row[6] ?? null;
            $account_no = $row[4] ?? null;
            $account_name = $row[5] ?? null;

            $principal_amount = $cleanNumber($row[8] ?? '');
            $charge_to_customer = $cleanNumber($row[10] ?? '');
            $charge_to_partner = $cleanNumber($row[11] ?? '');
            $cancellation_charge = $cleanNumber($row[9] ?? '');

            $resource = $row[12] ?? null;
            $branch_id = $meta['branch_id'] ?? null;
            $branch_code = $meta['branch_code'] ?? null;
            $branch_name = $row[13] ?? null;
            $zone_code = $meta['zone_code'] ?? null;
            $region_code = $meta['region_code'] ?? null;
            $region = $meta['region'] ?? null;
            $remote_branch = $row[15] ?? null;
            $remote_operator = $row[14] ?? null;

            $partner_name = $meta['partner_name'] ?? null;
            $partner_id = $meta['partner_id'] ?? null;
            $partner_id_kpx = $meta['partner_id_kpx'] ?? null;
            $mpm_gl_code = $meta['gl_code'] ?? null;

            $imported_by = $meta['uploaded_by'] ?? null;
            $imported_date = $meta['uploaded_date'] ?? date('Y-m-d');

            $params = [ $types,
                $cancellation_datetime, $report_date, $sendout_datetime, $source_file, $control_no, $reference_no, $ir_no,
                $payor, $account_no, $account_name, $principal_amount, $charge_to_customer, $charge_to_partner,
                $cancellation_charge, $resource, $branch_id, $branch_code, $branch_name, $zone_code, $region_code,
                $region, $remote_branch, $remote_operator, $partner_name, $partner_id, $partner_id_kpx, $mpm_gl_code,
                $imported_by, $imported_date
            ];

            $bindParams = [];
            foreach ($params as $key => $value) $bindParams[$key] = &$params[$key];

            $bindResult = @call_user_func_array([$stmt, 'bind_param'], $bindParams);
            if ($bindResult === false) {
                $err = $stmt->error ?: $conn->error;
                $errors[] = 'bind_param failed: ' . $err;
                error_log('[IMPORT ERROR] bind_param failed: ' . $err);
                continue;
            }

            if (!$stmt->execute()) {
                $err = $stmt->error ?: $conn->error;
                $errors[] = 'execute failed: ' . $err;
                error_log('[IMPORT ERROR] execute failed: ' . $err);
            } else {
                $inserted++;
            }
        }

        if (empty($errors)) {
            $conn->commit();
            return ['success' => true, 'inserted' => $inserted];
        } else {
            $conn->rollback();
            return ['success' => false, 'error' => implode('; ', $errors)];
        }
    } catch (Exception $e) {
        $conn->rollback();
        return ['success' => false, 'error' => $e->getMessage()];
    } finally {
        if (isset($stmt) && $stmt) $stmt->close();
    }
}

/**
 * Stubbed KP7 insert — returns not implemented.
 */
function insertKP7CancellationRows(array $rowsToInsert, array $meta)
{
    return ['success' => false, 'error' => 'KP7 insertion not implemented yet'];
}

// Handle cancel action (clear uploaded data / session)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_import') {
    // Delete temporary JSON files for this import
    $temporaryDir = __DIR__ . '/temporary';
    $deleted = 0;
    if (is_dir($temporaryDir)) {
        $files = glob($temporaryDir . '/import_cancelled_*.json');
        if (!empty($files)) {
            foreach ($files as $f) {
                if (is_file($f)) {
                    @unlink($f);
                    $deleted++;
                }
            }
        }
    }

    // Clear relevant session keys used by import workflow
    $keysToClear = [
        'partnerselection', 'ready_to_override_data', 'processed_override_data',
        'Matched_BranchID_data', 'cancellation_BranchID_data', 'original_file_name',
        'source_file_type', 'transactionDate'
    ];
    foreach ($keysToClear as $k) {
        if (isset($_SESSION[$k])) unset($_SESSION[$k]);
    }

    // Also clear POST and FILES arrays on server side for this request
    $_POST = [];
    $_FILES = [];

    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'deleted_files' => $deleted]);
    exit;
}

// Handle uploaded file (supports .csv, .xls, .xlsx)
// Handle upload: support both Manual single-file (`import_file`) and Auto batch (`files[]`).
if (isset($_POST['upload'])) {
    // Auto batch mode: client posts files[] + partner_ids[] + source_types[]
    if (isset($_FILES['files']) && is_array($_FILES['files']['name']) && count($_FILES['files']['name']) > 0) {
        $uploadedFiles = [];
        $tmpDir = __DIR__ . '/../../admin/temporary/';
        if (!is_dir($tmpDir)) @mkdir($tmpDir, 0777, true);

        $fileCount = count($_FILES['files']['name']);
        // Server-side pre-upload duplicate check: ensure no reference_no already exists
        $precheckResults = [];
        for ($i = 0; $i < $fileCount; $i++) {
            if ($_FILES['files']['error'][$i] !== UPLOAD_ERR_OK) continue;
            $tmpPath = $_FILES['files']['tmp_name'][$i];
            $fileName = $_FILES['files']['name'][$i];
            $partnerIdCheck = $_POST['partner_ids'][$i] ?? '';
            // Resolve partner search key to actual partner identifiers (do not treat raw G3 as partner_id)
            $resolvedPartner = findPartnerRecord($conn, $partnerIdCheck);
            $p_resolved_id = $resolvedPartner['partner_id'] ?? null;
            $p_resolved_kpx = $resolvedPartner['partner_id_kpx'] ?? null;

            try {
                $spreadsheet = IOFactory::load($tmpPath);
                $worksheet = $spreadsheet->getActiveSheet();
                $highestRow = $worksheet->getHighestRow();

                $duplicateRows = 0; $totalRows = 0; $postedRows = 0; $unpostedRows = 0;
                for ($row = 7; $row <= $highestRow; $row++) {
                    $cellB = trim((string)$worksheet->getCell('B' . $row)->getValue());
                    $cellD = trim((string)$worksheet->getCell('D' . $row)->getValue());
                    if ($cellB === '' && $cellD === '') break;
                    $totalRows++;
                    $reference_number = $cellD;
                    $datetimeValue = $cellB;
                    $datetime = null;
                    if (is_numeric($datetimeValue)) {
                        $datetime = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($datetimeValue)->format('Y-m-d H:i:s');
                    } elseif (!empty($datetimeValue)) {
                        $datetime = date('Y-m-d H:i:s', strtotime($datetimeValue));
                    }
                    if (empty($reference_number) || empty($datetime)) continue;

                    $row_count_total = 0;
                    // check transactions
                    $sql = "SELECT post_transaction, COUNT(*) as cnt FROM mldb.billspayment_transaction WHERE reference_no = ? AND (`datetime` = ? OR cancellation_date = ? )";
                    if (!empty($partnerIdCheck) && strtoupper($partnerIdCheck) !== 'ALL' && (!empty($p_resolved_id) || !empty($p_resolved_kpx))) {
                        $sql .= " AND (partner_id = ? OR partner_id_kpx = ?) GROUP BY post_transaction";
                        $sstmt = $conn->prepare($sql);
                        $sstmt->bind_param("sssss", $reference_number, $datetime, $datetime, $p_resolved_id, $p_resolved_kpx);
                    } else {
                        $sql .= " GROUP BY post_transaction";
                        $sstmt = $conn->prepare($sql);
                        $sstmt->bind_param("sss", $reference_number, $datetime, $datetime);
                    }
                    $sstmt->execute();
                    $sres = $sstmt->get_result();
                    if ($sres) {
                        while ($r = $sres->fetch_assoc()) {
                            $cnt = intval($r['cnt']);
                            $row_count_total += $cnt;
                            $status = isset($r['post_transaction']) ? strtolower(trim($r['post_transaction'])) : '';
                            if ($status === 'posted') $postedRows += $cnt; else $unpostedRows += $cnt;
                        }
                    }
                    $sstmt->close();

                    // check cancellations table
                    $cSql = "SELECT COUNT(*) as cnt FROM mldb.billspayment_cancellation WHERE reference_no = ?";
                    if (!empty($partnerIdCheck) && strtoupper($partnerIdCheck) !== 'ALL' && (!empty($p_resolved_id) || !empty($p_resolved_kpx))) {
                        $cSql .= " AND (partner_id = ? OR partner_id_kpx = ?)";
                        $cstmt = $conn->prepare($cSql);
                        if ($cstmt) { $cstmt->bind_param("sss", $reference_number, $p_resolved_id, $p_resolved_kpx); $cstmt->execute(); $cRes = $cstmt->get_result(); if ($cRes && ($crow = $cRes->fetch_assoc())) { $ccnt = intval($crow['cnt']); if ($ccnt > 0) { $row_count_total += $ccnt; $postedRows += $ccnt; } } $cstmt->close(); }
                    } else {
                        $cstmt = $conn->prepare($cSql);
                        if ($cstmt) { $cstmt->bind_param("s", $reference_number); $cstmt->execute(); $cRes = $cstmt->get_result(); if ($cRes && ($crow = $cRes->fetch_assoc())) { $ccnt = intval($crow['cnt']); if ($ccnt > 0) { $row_count_total += $ccnt; $postedRows += $ccnt; } } $cstmt->close(); }
                    }

                    if ($row_count_total > 0) $duplicateRows++;
                }
                if (isset($spreadsheet) && is_object($spreadsheet)) { try { $spreadsheet->disconnectWorksheets(); } catch (Exception $e) {} unset($worksheet,$spreadsheet); }

                $precheckResults[] = [ 'fileName'=>$fileName, 'totalRows'=>$totalRows, 'duplicateRows'=>$duplicateRows, 'postedRows'=>$postedRows, 'unpostedRows'=>$unpostedRows, 'hasDuplicates'=>($duplicateRows>0) ];
            } catch (Exception $e) {
                $precheckResults[] = [ 'fileName'=>$fileName, 'error'=>$e->getMessage() ];
            }
        }

        // if any duplicates found, abort upload and inform client
        $anyDup = false; foreach ($precheckResults as $pr) { if (isset($pr['hasDuplicates']) && $pr['hasDuplicates']) { $anyDup = true; break; } }
        $isAjaxReq = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        if ($anyDup) {
            if ($isAjaxReq) {
                header('Content-Type: application/json');
                echo json_encode(['success'=>false,'error'=>'Duplicate Reference No detected in uploaded files','files'=>$precheckResults]);
                exit;
            } else {
                $msg = 'Duplicate Reference No detected in uploaded files. Upload aborted.';
                echo '<script>alert("' . addslashes($msg) . '");window.history.back();</script>';
                exit;
            }
        }
        for ($i = 0; $i < $fileCount; $i++) {
            if ($_FILES['files']['error'][$i] !== UPLOAD_ERR_OK) continue;
            $origTmp = $_FILES['files']['tmp_name'][$i];
            $origName = $_FILES['files']['name'][$i];
            $partnerIdClient = $_POST['partner_ids'][$i] ?? '';
            $sourceTypeClient = $_POST['source_types'][$i] ?? '';

            $fileId = uniqid('file_', true);
            $destName = $fileId . '_' . basename($origName);
            $destPath = $tmpDir . $destName;
            if (@move_uploaded_file($origTmp, $destPath) || @copy($origTmp, $destPath)) {
                // Resolve partner name if available
                $partnerName = 'Unknown Partner';
                if (!empty($partnerIdClient)) {
                    $partnerName = getPartnerName($conn, $partnerIdClient);
                }

                $uploadedFiles[] = [
                    'id' => $fileId,
                    'name' => $origName,
                    'path' => $destPath,
                    'partner_id' => $partnerIdClient ?: '',
                    'partner_name' => $partnerName,
                    'source_type' => strtoupper($sourceTypeClient ?: 'KPX'),
                    'status' => 'pending',
                    'validation_result' => null,
                    'uploaded_by' => $current_user_email ?? '',
                    'uploaded_date' => date('Y-m-d H:i:s'),
                    'report_date' => isset($_POST['report_dates'][$i]) ? trim($_POST['report_dates'][$i]) : null
                ];
            }
        }

        if (!empty($uploadedFiles)) {
            $_SESSION['uploaded_files'] = $uploadedFiles;
            $_SESSION['batch_upload'] = true;
            $_SESSION['user_decision'] = $_POST['user_decision'] ?? 'skip';
            // Ensure session is written before responding so the validator page can read it
            @session_write_close();
            // If request is AJAX (X-Requested-With), return JSON so client-side can redirect reliably
            $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'redirect' => '/billspayment/models/saved/saved_billspayImportCancelledFile_NEW.php']);
                exit;
            }
            // Non-AJAX fallback: redirect to the batch validation UI which displays cards (absolute path from webroot)
            header('Location: /billspayment/models/saved/saved_billspayImportCancelledFile_NEW.php');
            exit;
        } else {
            echo '<script>alert("No file uploaded or upload error.");window.history.back();</script>';
            exit;
        }
    }
    // Manual single-file mode
    elseif (isset($_FILES['import_file']) && $_FILES['import_file']['error'] === UPLOAD_ERR_OK) {
        $tmpPath = $_FILES['import_file']['tmp_name'];
        $fileName = $_FILES['import_file']['name'];
        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        // Determine source/file type selection from the manual form (KPX or KP7)
        $selectedSource = strtoupper($_POST['fileType'] ?? $_POST['source'] ?? 'KPX');
        if ($selectedSource === 'KP7') {
            // KP7 support is not yet implemented — short-circuit with message
            handleKP7();
        }

        // Delegate to source-specific handler. KP7 already short-circuited above.
        handleKPXImport($tmpPath, $fileName, $fileExt);
    } else {
        echo '<script>alert("No file uploaded or upload error.");window.history.back();</script>';
        exit;
    }
}

// Accept either an explicit 'action=confirm_import' or the form field 'confirm_import'
if ((isset($_POST['action']) && $_POST['action'] === 'confirm_import') || isset($_POST['confirm_import'])) {
    // Load latest JSON rows
    $temporaryDir = __DIR__ . '/temporary';
    $latestFile = null;
    $rowsToInsert = [];
    if (is_dir($temporaryDir)) {
        $files = glob($temporaryDir . '/import_cancelled_*.json');
        if (!empty($files)) {
            usort($files, function($a, $b) {
                return filemtime($b) - filemtime($a);
            });
            $latestFile = $files[0];
            $content = @file_get_contents($latestFile);
            if ($content !== false) {
                $decoded = json_decode($content, true);
                if (is_array($decoded)) $rowsToInsert = $decoded;
            }
        }
    }

    header('Content-Type: application/json');

    if (empty($rowsToInsert)) {
        echo json_encode(['success' => false, 'error' => 'No rows to import']);
        exit;
    }

    // Dispatch insertion to the appropriate source handler
    $meta = $_SESSION['import_meta'] ?? [];
    $source = strtoupper($meta['source'] ?? 'KPX');
    if ($source === 'KP7') {
        $result = insertKP7CancellationRows($rowsToInsert, $meta);
    } else {
        $result = insertKPXCancellationRows($rowsToInsert, $meta);
    }

    echo json_encode($result);
    exit;
}

// ============================================================================
// Batch Import: perform_import (process all files stored in session)
// Expects: POST perform_import=1; optional is_ajax=1
// ============================================================================
if (isset($_POST['perform_import']) && isset($_SESSION['uploaded_files']) && is_array($_SESSION['uploaded_files'])) {
    $isAjax = isset($_POST['is_ajax']) && $_POST['is_ajax'] == '1';
    $imported = 0; $failed = 0; $errors = [];

    foreach ($_SESSION['uploaded_files'] as $f) {
        $path = $f['path'] ?? '';
        $sourceType = strtoupper($f['source_type'] ?? 'KPX');
        $partnerId = $f['partner_id'] ?? '';

        if (empty($path) || !file_exists($path)) {
            $failed++; $errors[] = "File missing: {$f['name']}"; continue;
        }

        // Load rows from file (B..Q starting at row 7) similar to handleKPXImport
        $rows = [];
        try {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if ($ext === 'csv') {
                if (($handle = fopen($path, 'r')) !== false) {
                    $rowIndex = 0;
                    while (($data = fgetcsv($handle)) !== false) {
                        $rowIndex++;
                        if ($rowIndex < 7) continue;
                        $rowData = [];$allEmpty=true;
                        for ($i = 1; $i <= 16; $i++) {
                            $v = isset($data[$i]) ? trim((string)$data[$i]) : '';
                            $rowData[] = $v; if ($v !== '') $allEmpty=false;
                        }
                        if ($allEmpty) break;
                        $rows[] = $rowData;
                    }
                    fclose($handle);
                }
                if (empty($rows)) {
                    // fallback to PhpSpreadsheet
                    $spreadsheet = IOFactory::load($path);
                    $worksheet = $spreadsheet->getActiveSheet();
                    $highestRow = $worksheet->getHighestRow();
                    for ($r = 7; $r <= $highestRow; $r++) {
                        $rowData = []; $allEmpty=true;
                            for ($c = 2; $c <= 17; $c++) {
                                $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c);
                                $cell = $worksheet->getCell($colLetter . $r);
                                $val = $cell !== null ? trim((string)$cell->getValue()) : '';
                                $rowData[] = $val; if ($val !== '') $allEmpty=false;
                            }
                        if ($allEmpty) break; $rows[] = $rowData;
                    }
                    if (isset($spreadsheet) && is_object($spreadsheet)) { try { $spreadsheet->disconnectWorksheets(); } catch (Exception $e) {} unset($worksheet,$spreadsheet); }
                }
            } else {
                $spreadsheet = IOFactory::load($path);
                $worksheet = $spreadsheet->getActiveSheet();
                $highestRow = $worksheet->getHighestRow();
                for ($r = 7; $r <= $highestRow; $r++) {
                    $rowData = []; $allEmpty=true;
                    for ($c = 2; $c <= 17; $c++) {
                        $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c);
                        $cell = $worksheet->getCell($colLetter . $r);
                        $val = $cell !== null ? trim((string)$cell->getValue()) : '';
                        $rowData[] = $val; if ($val !== '') $allEmpty=false;
                    }
                    if ($allEmpty) break; $rows[] = $rowData;
                }
                if (isset($spreadsheet) && is_object($spreadsheet)) { try { $spreadsheet->disconnectWorksheets(); } catch (Exception $e) {} unset($worksheet,$spreadsheet); }
            }

            if (empty($rows)) { $failed++; $errors[] = "No data in file: {$f['name']}"; continue; }

            // Build meta - resolve partner search key to actual ids
            $resolved = findPartnerRecord($conn, $partnerId);
            $meta = [
                'partner_id' => $resolved['partner_id'] ?? null,
                'partner_id_kpx' => $resolved['partner_id_kpx'] ?? null,
                'gl_code' => $resolved['gl_code'] ?? null,
                'partner_name' => $resolved['partner_name'] ?? ($f['partner_name'] ?? null),
                'reference_no' => null,
                'source' => $sourceType,
                'uploaded_date' => date('Y-m-d'),
                'uploaded_by' => $f['uploaded_by'] ?? null,
                'report_date' => $f['report_date'] ?? null,
                'branch_id' => null,'branch_code'=>null,'zone_code'=>null,'region_code'=>null,'region'=>null
            ];

            $res = insertKPXCancellationRows($rows, $meta);
            if ($res['success']) { $imported += ($res['inserted'] ?? 0); } else { $failed++; $errors[] = "{$f['name']}: " . ($res['error'] ?? 'Import failed'); }
        } catch (Exception $e) { $failed++; $errors[] = "{$f['name']}: " . $e->getMessage(); }
        // attempt to delete temp file
        if (!empty($path) && file_exists($path)) @unlink($path);
    }

    // clear session
    unset($_SESSION['uploaded_files']); unset($_SESSION['batch_upload']); unset($_SESSION['user_decision']);

    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success'=>$imported>0,'imported'=>$imported,'failed'=>$failed,'errors'=>$errors]);
        exit;
    }

    // non-ajax fallback: show result and redirect back
    $msg = htmlspecialchars(json_encode(['imported'=>$imported,'failed'=>$failed,'errors'=>$errors]));
    echo "<script>alert('Import complete. Imported: {$imported} Failed: {$failed}');window.location.href='" . basename(__FILE__) . "';</script>";
    exit;
}

?>

<?php
// Load latest processed JSON file from temporary folder for display
$temporaryDir = __DIR__ . '/temporary';
$latestFile = null;
$rows = [];
$fileCreatedAt = null;
if (is_dir($temporaryDir)) {
    $files = glob($temporaryDir . '/import_cancelled_*.json');
    if (!empty($files)) {
        usort($files, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        $latestFile = $files[0];
        $fileContent = @file_get_contents($latestFile);
        if ($fileContent !== false) {
            $decoded = json_decode($fileContent, true);
            if (is_array($decoded)) $rows = $decoded;
        }
        $fileCreatedAt = $latestFile ? date('Y-m-d H:i:s', filemtime($latestFile)) : null;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import File - Summary</title>
    <!-- custom CSS file link  -->
    <link rel="stylesheet" href="../../assets/css/billspaymentImportFile.css?v=1">
    <link rel="icon" href="../../images/MLW logo.png" type="image/png">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet" />
    <!-- Font Awesome for icons -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- SweetAlert2 CSS (styling only) -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.min.css" rel="stylesheet">

    <!-- SWEET ALERT CONFIRM AND CANCEL BUTTONS -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
       /* Print styles */
        @media print {
            body * {
                visibility: hidden;
                visibility: visible;
            }
            .alert-warning {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                border: none !important;
                background-color: white !important;
                color: black !important;
            }
            .alert-warning .d-flex {
                display: none !important;
            }
            .alert-warning h4 {
                text-align: center;
                font-size: 18px;
                margin-bottom: 15px;
            }
            .alert-warning p {
                text-align: center;
                margin-bottom: 15px;
            }
            .table-responsive {
                max-height: none !important;
                height: auto !important;
                overflow: visible !important;
            }
            .table {
                width: 100%;
                border-collapse: collapse;
                page-break-inside: auto;
            }
            .table th, .table td {
                border: 1px solid #000;
            }
            .table tr {
                page-break-inside: avoid;
                page-break-after: auto;
            }
            .sticky-top {
                position: static;
            }
        }

        .swal2-container.swal2-backdrop-show {
            backdrop-filter: blur(10px);
            background-color: rgba(0,0,0,0.8) !important;
        }
        .swal2-popup {
            backdrop-filter: none !important;
            box-shadow: 0 0 20px rgba(0,0,0,0.5);
        }

        /* Loading overlay visuals (static) */
        #loading-overlay { display: none; position: fixed; inset: 0; background: rgba(255,255,255,0.8); z-index: 1050; }
        .loading-spinner { width: 3rem; height: 3rem; border-radius: 50%; border: 4px solid #ccc; border-top-color: #0d6efd; margin: 20% auto; }
    </style>
</head>
<body>
    <div id="loading-overlay">
        <div class="loading-spinner"></div>
    </div>

    <div id="summary-section">
        <div id="upload-success" class="container-fluid py-4" style="margin-top: 20px;">
            <div class="text-center mb-4">
                <div class="card shadow-sm border-0 bg-light py-4 position-relative">
                    <button type="button" class="btn-close position-absolute top-0 end-0 m-3" aria-label="Close" onclick="confirmCancel()"></button>
                    <h3 class="text-center fw-bold text-primary">Would you like to proceed inserting the data?</h3>
                    <div class="card-body">
                        <form method="post" id="confirmImportForm" class="d-inline">
                            <input type="hidden" name="confirm_import" value="1">
                            <button type="button" class="btn btn-success btn-lg me-3 shadow-sm" onclick="confirmImport()">
                                <i class="fas fa-check-circle me-2"></i>Confirm Import
                            </button>
                        </form>
                        <button type="button" class="btn btn-danger btn-lg shadow-sm" onclick="confirmCancel()">
                            <i class="fas fa-times-circle me-2"></i>Cancel
                        </button>
                    </div>
                </div>
            </div>
            <div class="row mt-4 gx-4">
                <!-- Import Details Card -->
                <div class="col-md-3">
                    <div class="card shadow border-0 h-100">
                        <div class="card-header bg-success text-white py-3 position-relative">
                            <button type="button" class="btn-close btn-close-white position-absolute top-0 end-0 m-2" aria-label="Close" onclick="confirmCancel()"></button>
                            <h4 class="mb-0 text-center"><i class="fas fa-info-circle me-2"></i>Import Details</h4>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover align-middle">
                                    <thead>
                                        <tr class="table-secondary">
                                            <th>Property</th>
                                            <th>Value</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td><i class="fas fa-id-card text-primary me-2"></i>KP7 Partner ID</td>
                                            <td class="fw-semibold"><?php echo htmlspecialchars($_SESSION['import_meta']['partner_id'] ?? '—'); ?></td>
                                        </tr>
                                        <tr>
                                            <td><i class="fas fa-id-card text-primary me-2"></i>KPX Partner ID</td>
                                            <td class="fw-semibold"><?php echo htmlspecialchars($_SESSION['import_meta']['partner_id_kpx'] ?? '—'); ?></td>
                                        </tr>
                                        <tr>
                                            <td><i class="fas fa-id-card text-primary me-2"></i>GL Code</td>
                                            <td class="fw-semibold"><?php echo htmlspecialchars($_SESSION['import_meta']['gl_code'] ?? '—'); ?></td>
                                        </tr>
                                        <tr>
                                            <td><i class="fas fa-building text-primary me-2"></i>Partner Name</td>
                                            <td class="fw-semibold"><?php echo htmlspecialchars($_SESSION['import_meta']['partner_name'] ?? '—'); ?></td>
                                        </tr>
                                        <tr>
                                            <td><i class="fas fa-list-ol text-primary me-2"></i>Rows Imported</td>
                                            <td id="rowsImported" class="fw-semibold">0</td>
                                        </tr>
                                        <tr>
                                            <td><i class="fas fa-file-import text-primary me-2"></i>Source</td>
                                            <td class="fw-semibold"><?php echo htmlspecialchars($_SESSION['import_meta']['source'] ?? '—'); ?></td>
                                        </tr>
                                        <tr>
                                            <td><i class="fas fa-calendar-day text-primary me-2"></i>Report Date</td>
                                            <td class="fw-semibold"><?php echo !empty($_SESSION['import_meta']['report_date']) ? htmlspecialchars(date('F d, Y', strtotime($_SESSION['import_meta']['report_date']))) : (htmlspecialchars($_SESSION['import_meta']['report_date_raw'] ?? '—')); ?></td>
                                        </tr>
                                        <tr>
                                            <td><i class="fas fa-calendar-alt text-primary me-2"></i>Uploaded Date</td>
                                            <td class="fw-semibold"><?php echo htmlspecialchars(date('F d, Y', strtotime($_SESSION['import_meta']['uploaded_date'])) ?? '—'); ?></td>
                                        </tr>
                                        <tr>
                                            <td><i class="fas fa-calendar-alt text-primary me-2"></i>Uploaded By</td>
                                            <td class="fw-semibold"><?php echo htmlspecialchars($_SESSION['import_meta']['uploaded_by'] ?? '—'); ?></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Transaction Summary Table -->
                <div class="col-md-9">
                    <div class="card shadow border-0">
                        <div class="card-header bg-danger text-white py-3 position-relative">
                            <button type="button" class="btn-close btn-close-white position-absolute top-0 end-0 m-2" aria-label="Close" onclick="confirmCancel()"></button>
                            <h4 class="mb-0 text-center"><i class="fas fa-chart-line me-2"></i>Cancellation Summary</h4>
                        </div>
                        <div class="card-body">
                            <table class="table table-bordered table-hover align-middle">
                                <thead>
                                    <tr class="bg-danger text-white text-center fw-bold">
                                        <th class="text-center" style="width: 33%">CANCELLED TRANSACTIONS</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td class="border-end">
                                            <div class="row">
                                                <div class="col-6 fw-semibold"><i class="fas fa-calculator text-secondary me-2"></i>TOTAL COUNT</div>
                                                <div class="col-6 text-end fw-bold"><span id="totalCount">0</span></div>
                                            </div>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="border-end">
                                            <div class="row">
                                                <div class="col-6 fw-semibold"><i class="fas fa-money-bill-wave text-success me-2"></i>TOTAL PRINCIPAL</div>
                                                <div class="col-6 text-end fw-bold"><span id="totalPrincipal">PHP 0.00</span></div>
                                            </div>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="border-end">
                                            <div class="row">
                                                <div class="col-6 fw-semibold"><i class="fas fa-receipt text-danger me-2"></i>TOTAL CHARGE</div>
                                                <div class="col-6 text-end fw-bold"><span id="totalCharge">PHP 0.00</span></div>
                                            </div>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="border-end">
                                            <div class="row">
                                                <div class="col-6 fw-semibold"><i class="fas fa-building text-primary me-2"></i>CHARGE TO PARTNER</div>
                                                <div class="col-6 text-end fw-bold"><span id="chargeToPartner">PHP 0.00</span></div>
                                            </div>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="border-end">
                                            <div class="row">
                                                <div class="col-6 fw-semibold"><i class="fas fa-user text-info me-2"></i>CHARGE TO CUSTOMER</div>
                                                <div class="col-6 text-end fw-bold"><span id="chargeToCustomer">PHP 0.00</span></div>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- DISPLAYED TBODY TABLE -->
    <script>
        // Rows data injected from PHP
        const rowsData = <?php echo json_encode($rows, JSON_UNESCAPED_UNICODE); ?> || [];

        // Parse numeric string to float. Handles parentheses and currency symbols/commas.
        function parseNumber(value) {
            if (value === null || value === undefined) return 0;
            let s = String(value).trim();
            if (s === '') return 0;
            let negative = false;
            if (/^\(.*\)$/.test(s)) {
                negative = true;
                s = s.replace(/[()]/g, '');
            }
            // Remove any non-digit, non-dot, non-minus characters (commas, currency signs, spaces)
            s = s.replace(/[^0-9.\-]/g, '');
            let n = parseFloat(s);
            if (isNaN(n)) n = 0;
            return negative ? -n : n;
        }

        function formatPHP(amount) {
            return 'PHP ' + Number(amount).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function computeCancellationSummary() {
            const totalRows = rowsData.length;

            // Column indexes relative to B..Q: B=0, ..., J=8, K=9, L=10, M=11
            const IDX_PRINCIPAL = 8; // J
            const IDX_CHARGE = 9; // K
            const IDX_CHARGE_TO_CUSTOMER = 10; // L
            const IDX_CHARGE_TO_PARTNER = 11; // M

            let sumPrincipal = 0;
            let sumCharge = 0;
            let sumChargeToCustomer = 0;
            let sumChargeToPartner = 0;

            for (let i = 0; i < rowsData.length; i++) {
                const row = rowsData[i] || [];
                sumPrincipal += parseNumber(row[IDX_PRINCIPAL] || '');
                sumCharge += parseNumber(row[IDX_CHARGE] || '');
                sumChargeToCustomer += parseNumber(row[IDX_CHARGE_TO_CUSTOMER] || '');
                sumChargeToPartner += parseNumber(row[IDX_CHARGE_TO_PARTNER] || '');
            }

            // Update DOM
            const elRowsImported = document.getElementById('rowsImported');
            const elTotalCount = document.getElementById('totalCount');
            const elTotalPrincipal = document.getElementById('totalPrincipal');
            const elTotalCharge = document.getElementById('totalCharge');
            const elChargeToPartner = document.getElementById('chargeToPartner');
            const elChargeToCustomer = document.getElementById('chargeToCustomer');

            if (elRowsImported) elRowsImported.textContent = totalRows;
            if (elTotalCount) elTotalCount.textContent = totalRows;
            if (elTotalPrincipal) elTotalPrincipal.textContent = formatPHP(sumPrincipal);
            if (elTotalCharge) elTotalCharge.textContent = formatPHP(sumCharge);
            if (elChargeToPartner) elChargeToPartner.textContent = formatPHP(sumChargeToPartner);
            if (elChargeToCustomer) elChargeToCustomer.textContent = formatPHP(sumChargeToCustomer);
        }

        document.addEventListener('DOMContentLoaded', function() {
            computeCancellationSummary();
        });
    </script>

    
    <script>
        function confirmImport() {
            // Show loading
            document.getElementById('loading-overlay').style.display = 'block';

            fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=confirm_import'
            }).then(r => r.json()).then(resp => {
                document.getElementById('loading-overlay').style.display = 'none';
                if (resp.success) {
                    const insertedCount = resp.inserted || 0;
                    Swal.fire({
                        icon: 'success',
                        title: 'Data Successfully Imported',
                        html: `
                            <div class="text-center">
                                <div class="alert alert-success">
                                    <strong>${insertedCount}</strong> records inserted.
                                </div>
                            </div>
                        `,
                        showConfirmButton: true,
                        confirmButtonText: 'Close',
                        confirmButtonColor: '#28a745',
                        allowOutsideClick: false,
                        allowEscapeKey: false
                    }).then((result) => {
                        if (result.isConfirmed) {
                            // clear temporary/session data on server (reuse cancel_import handler)
                            fetch(window.location.href, {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                body: 'action=cancel_import'
                            }).then(() => {
                                // Redirect after server cleared data
                                window.location.href = '../../dashboard/billspayment/import/billspay-transaction.php';
                            }).catch(() => {
                                // fallback redirect even if AJAX fails
                                window.location.href = '../../dashboard/billspayment/import/billspay-transaction.php';
                            });
                        }
                    });
                } else {
                    Swal.fire({ icon: 'error', title: 'Import Failed', text: resp.error || 'Unknown error' });
                }
            }).catch(err => {
                document.getElementById('loading-overlay').style.display = 'none';
                Swal.fire({ icon: 'error', title: 'Request Failed', text: err.message || 'Network error' });
            });
        }
        function confirmCancel() {
            Swal.fire({
                title: 'Notice',
                text: "Cancelling the process will discard all uploaded data",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Yes, cancel it!',
                cancelButtonText: 'No, continue'
            }).then((result) => {
                if (result.isConfirmed) {
                    // POST to this same endpoint to clear server-side data
                    fetch(window.location.href, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'action=cancel_import'
                    }).then(r => r.json()).then(resp => {
                        // Redirect back to import page after server cleared data
                        window.location.href = '../../dashboard/billspayment/import/billspay-cancellation.php';
                    }).catch(err => {
                        // fallback redirect even if AJAX fails
                        window.location.href = '../../dashboard/billspayment/import/billspay-cancellation.php';
                    });
                }
            });
        }
    </script>
</body>
</html>