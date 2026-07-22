<?php
include '../../../../config/config.php';
session_start();
include '../../../../templates/middleware.php';

$id = resolve_user_identifier();
if (empty($id)) {
    header('Location: ../../../../login_form.php');
    exit;
}

if (!function_exists('has_any_permission') || !has_any_permission(['TRL Import', 'Bills Payment'])) {
    header('Location: ../../../home.php');
    exit;
}

$rows = $_SESSION['trl_import_rows'] ?? [];
if (empty($rows)) {
    $_SESSION['trl_import_flash'] = [
        'type' => 'error',
        'message' => 'No TRL rows found in session. Please upload files again.'
    ];
    header('Location: ../trl-import-preview.php');
    exit;
}

$inserted = 0;
$failed = 0;
$redirectUrl = '../trl-import-preview.php';

$sql = "INSERT INTO mldb.trl (
    transfer_datetime,
    ref_no,
    wrong_biller_id,
    biller_name,
    account_no,
    name,
    payment_branch_id,
    payment_branch,
    amount,
    type_of_request,
    reason,
    remarks
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

$wrongSql = "INSERT INTO mldb.trl_wrongbiller (
    trl_no,
    correct_biller_id,
    correct_biller_name
) VALUES (?, ?, ?)";

$osSql = "INSERT INTO mldb.trl_overstatedamount (
    trl_no,
    wrong_amount,
    correct_amount,
    difference
) VALUES (?, ?, ?, ?)";

$ctSql = "INSERT INTO mldb.trl_cancelledtransaction (
    trl_no,
    wrong_amount,
    correct_amount
) VALUES (?, ?, ?)";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    $_SESSION['trl_import_flash'] = [
        'type' => 'error',
        'message' => 'Failed to prepare insert statement: ' . $conn->error
    ];
    header('Location: ../trl-import-preview.php');
    exit;
}

$wrongStmt = $conn->prepare($wrongSql);
if (!$wrongStmt) {
    $_SESSION['trl_import_flash'] = [
        'type' => 'error',
        'message' => 'Failed to prepare wrong biller insert statement: ' . $conn->error
    ];
    header('Location: ../trl-import-preview.php');
    exit;
}

$osStmt = $conn->prepare($osSql);
if (!$osStmt) {
    $_SESSION['trl_import_flash'] = [
        'type' => 'error',
        'message' => 'Failed to prepare overstated amount insert statement: ' . $conn->error
    ];
    header('Location: ../trl-import-preview.php');
    exit;
}

$ctStmt = $conn->prepare($ctSql);
if (!$ctStmt) {
    $_SESSION['trl_import_flash'] = [
        'type' => 'error',
        'message' => 'Failed to prepare cancelled transaction insert statement: ' . $conn->error
    ];
    header('Location: ../trl-import-preview.php');
    exit;
}

$conn->autocommit(false);

try {
    foreach ($rows as $row) {
        $transferDatetime = !empty($row['transfer_datetime']) ? $row['transfer_datetime'] : null;
        $refNo = trim((string) ($row['ref_no'] ?? ''));
        $wrongBillerId = trim((string) ($row['wrong_biller_id'] ?? ''));
        $wrongBillerIdValue = preg_match('/^-?\d+$/', $wrongBillerId) ? (int) $wrongBillerId : null;
        $billerName = trim((string) ($row['biller_name'] ?? ''));
        $accountNo = trim((string) ($row['account_no'] ?? ''));
        $name = trim((string) ($row['name'] ?? ''));
        $paymentBranchId = trim((string) ($row['payment_branch_id'] ?? ''));
        $paymentBranchIdValue = preg_match('/^-?\d+$/', $paymentBranchId) ? (int) $paymentBranchId : null;
        $paymentBranch = trim((string) ($row['payment_branch'] ?? ''));
        $amount = (float) ($row['amount'] ?? 0);
        $typeOfRequest = trim((string) ($row['type_of_request'] ?? ''));
        $correctBillerId = trim((string) ($row['correct_biller_id'] ?? ''));
        $correctBillerName = trim((string) ($row['correct_biller_name'] ?? ''));
        $reason = trim((string) ($row['reason'] ?? ''));
        $remarks = 'data uploaded from excel file.';
        $isLegacyFormat = (($row['source_format'] ?? '') === 'legacy');

        $stmt->bind_param(
            'ssisssisdsss',
            $transferDatetime,
            $refNo,
            $wrongBillerIdValue,
            $billerName,
            $accountNo,
            $name,
            $paymentBranchIdValue,
            $paymentBranch,
            $amount,
            $typeOfRequest,
            $reason,
            $remarks
        );

        if (!$stmt->execute()) {
            $failed++;
            continue;
        }

        $trlNo = (int) $conn->insert_id;
        if ($trlNo <= 0) {
            $failed++;
            continue;
        }

        if (strcasecmp($typeOfRequest, 'WRONG BILLER') === 0) {
            if (!$isLegacyFormat && ($correctBillerId === '' || $correctBillerName === '')) {
                $failed++;
                continue;
            }

            // Legacy summary files may identify the intended biller by name only.
            // Store an unresolved ID as SQL NULL because the database column is
            // nullable integer; an empty string is invalid in strict SQL mode.
            if ($correctBillerId !== '' || $correctBillerName !== '') {
                $correctBillerIdValue = $correctBillerId !== '' ? (int) $correctBillerId : null;
                $wrongStmt->bind_param('iis', $trlNo, $correctBillerIdValue, $correctBillerName);
                if (!$wrongStmt->execute()) {
                    $failed++;
                    continue;
                }
            }
        }

        // If OVERSTATED AMOUNT, persist into separate table
        if (strcasecmp($typeOfRequest, 'OVERSTATED AMOUNT') === 0) {
            // obtain wrong/correct/difference from import row if present
            $reported = null;
            $actual = null;
            $difference = null;
            if (isset($row['wrong_amount']) && $row['wrong_amount'] !== '') {
                $reported = is_numeric($row['wrong_amount']) ? (float) $row['wrong_amount'] : (float) str_replace(',', '', (string) $row['wrong_amount']);
            } else {
                // fallback: use amount as wrong amount
                $reported = $amount;
            }
            if (isset($row['correct_amount']) && $row['correct_amount'] !== '') {
                $actual = is_numeric($row['correct_amount']) ? (float) $row['correct_amount'] : (float) str_replace(',', '', (string) $row['correct_amount']);
            }
            if (isset($row['difference_value']) && $row['difference_value'] !== '') {
                $difference = is_numeric($row['difference_value']) ? (float) $row['difference_value'] : (float) str_replace(',', '', (string) $row['difference_value']);
            }

            // compute difference if both reported and actual present and difference missing
            if ($difference === null && $reported !== null && $actual !== null) {
                $difference = $reported - $actual;
            }

            // Bind and insert (allow NULLs for actual/difference if not provided)
            $repVal = $reported !== null ? $reported : null;
            $actVal = $actual !== null ? $actual : null;
            $diffVal = $difference !== null ? $difference : null;

            $osStmt->bind_param('iddd', $trlNo, $repVal, $actVal, $diffVal);
            if (!$osStmt->execute()) {
                $failed++;
                continue;
            }
        }

        // If CANCELLED TRANSACTION, persist into cancelled table
        if (strcasecmp($typeOfRequest, 'CANCELLED TRANSACTION') === 0) {
            $reported = null;
            $actual = null;
            if (isset($row['wrong_amount']) && $row['wrong_amount'] !== '') {
                $reported = is_numeric($row['wrong_amount']) ? (float) $row['wrong_amount'] : (float) str_replace(',', '', (string) $row['wrong_amount']);
            } else {
                $reported = $amount;
            }
            if (isset($row['correct_amount']) && $row['correct_amount'] !== '') {
                $actual = is_numeric($row['correct_amount']) ? (float) $row['correct_amount'] : (float) str_replace(',', '', (string) $row['correct_amount']);
            }

            $repVal = $reported !== null ? $reported : null;
            $actVal = $actual !== null ? $actual : null;

            $ctStmt->bind_param('idd', $trlNo, $repVal, $actVal);
            if (!$ctStmt->execute()) {
                $failed++;
                continue;
            }
        }

        $inserted++;
    }

    if ($failed > 0) {
        $conn->rollback();
        $_SESSION['trl_import_flash'] = [
            'type' => 'error',
            'message' => "Insert rolled back. Inserted: {$inserted}, Failed: {$failed}."
        ];
    } else {
        $conn->commit();
        $_SESSION['trl_review_flash'] = [
            'type' => 'success',
            'message' => "TRL import complete. Inserted {$inserted} row(s).",
            'rows' => (int) $inserted
        ];
        $redirectUrl = '../../trl-review/trl-review.php';
        unset($_SESSION['trl_import_rows'], $_SESSION['trl_import_summary'], $_SESSION['trl_import_duplicate_result']);
    }
} catch (Exception $e) {
    $conn->rollback();
    $_SESSION['trl_import_flash'] = [
        'type' => 'error',
        'message' => 'Insert failed: ' . $e->getMessage()
    ];
}

$stmt->close();
$wrongStmt->close();
$osStmt->close();
$ctStmt->close();
$conn->autocommit(true);

header('Location: ' . $redirectUrl);
exit;
