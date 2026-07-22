<?php
include '../../../../config/config.php';
session_start();
include '../../../../templates/middleware.php';

header('Content-Type: application/json');

$id = resolve_user_identifier();
if (empty($id)) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

if (!function_exists('has_any_permission') || !has_any_permission(['TRL Entry', 'Bills Payment'])) {
    echo json_encode(['success' => false, 'message' => 'You do not have permission to perform this action.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

function trl_required($key) {
    return trim((string) ($_POST[$key] ?? ''));
}

function trl_required_any($keys) {
    foreach ($keys as $k) {
        $v = trim((string) ($_POST[$k] ?? ''));
        if ($v !== '') {
            return $v;
        }
    }
    return '';
}

function trl_entry_uploaded_files($fieldName) {
    if (!isset($_FILES[$fieldName])) {
        return [];
    }

    $upload = $_FILES[$fieldName];
    if (!is_array($upload['name'])) {
        return [$upload];
    }

    $files = [];
    $count = count($upload['name']);
    for ($index = 0; $index < $count; $index++) {
        $files[] = [
            'name' => $upload['name'][$index] ?? '',
            'type' => $upload['type'][$index] ?? '',
            'tmp_name' => $upload['tmp_name'][$index] ?? '',
            'error' => $upload['error'][$index] ?? UPLOAD_ERR_NO_FILE,
            'size' => $upload['size'][$index] ?? 0
        ];
    }
    return $files;
}

function trl_entry_validate_attachments($files) {
    $allowedExtensions = ['png', 'jpeg', 'jpg', 'gif', 'webp', 'pdf', 'docx', 'txt', 'xlsx', 'csv', 'ods'];
    if (count($files) > 10) {
        throw new Exception('A maximum of 10 attachments is allowed.');
    }

    $validated = [];
    foreach ($files as $file) {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new Exception('One of the attachments could not be uploaded.');
        }

        $name = basename((string) ($file['name'] ?? ''));
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $size = (int) ($file['size'] ?? 0);
        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($name === '' || !in_array($extension, $allowedExtensions, true)) {
            throw new Exception('An attachment has an unsupported file type.');
        }
        if ($size <= 0 || $size > 10 * 1024 * 1024) {
            throw new Exception($name . ' must be between 1 byte and 10 MB.');
        }
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new Exception('Unable to verify uploaded attachment: ' . $name);
        }

        $fileInfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = (string) ($fileInfo->file($tmpName) ?: 'application/octet-stream');
        $file['name'] = $name;
        $file['mime_type'] = $mimeType;
        $validated[] = $file;
    }
    return $validated;
}

function trl_entry_insert_attachment($conn, $trlNo, $createdBy, $file) {
    $binary = file_get_contents($file['tmp_name']);
    if ($binary === false) {
        throw new Exception('Unable to read attachment: ' . $file['name']);
    }

    $sql = "INSERT INTO mldb.trl_attachments (trl_no, file_name, mime_type, file_size, file_data, created_by) VALUES (?, ?, ?, ?, ?, ?)";
    $statement = $conn->prepare($sql);
    if (!$statement) {
        throw new Exception('Unable to prepare attachment insert.');
    }

    $fileName = (string) $file['name'];
    $mimeType = (string) $file['mime_type'];
    $fileSize = (int) $file['size'];
    $createdByValue = (string) $createdBy;
    $null = null;
    $statement->bind_param('issibs', $trlNo, $fileName, $mimeType, $fileSize, $null, $createdByValue);
    $statement->send_long_data(4, $binary);
    if (!$statement->execute()) {
        $statement->close();
        throw new Exception('Unable to save attachment: ' . $fileName);
    }
    $statement->close();
}

$attachments = [];

$requestCategory = trl_required('type_of_request');
$adjustmentType = trl_required('adjustment_type');
$changeDetailsType = trl_required('change_details_type');
$allowedAdjustmentTypes = [
    'NO PAYMENT RECEIVED',
    'DOUBLE POSTING',
    'MULTI POSTING',
    'TRIPLE POSTING',
    'WRONG BILLER',
    'OVERSTATED AMOUNT',
    'CANCELLED TRANSACTION',
    'UNREFLECTED TRXN'
];
$allowedChangeDetailsTypes = [
    'WRONG ACCOUNT NAME',
    'WRONG ACCOUNT NUMBER',
    'WRONG PAYMENT TYPE'
];

if (strcasecmp($requestCategory, 'Adjustment') === 0) {
    $adjustmentType = strtoupper($adjustmentType);
    if (!in_array($adjustmentType, $allowedAdjustmentTypes, true)) {
        echo json_encode([
            'success' => false,
            'message' => 'Please select a valid adjustment type.'
        ]);
        exit;
    }
    $effectiveRequestType = $adjustmentType;
} elseif (strcasecmp($requestCategory, 'Change Details') === 0) {
    $changeDetailsType = strtoupper($changeDetailsType);
    if (!in_array($changeDetailsType, $allowedChangeDetailsTypes, true)) {
        echo json_encode([
            'success' => false,
            'message' => 'Please select a valid change details type.'
        ]);
        exit;
    }
    $effectiveRequestType = $changeDetailsType;
} else {
    $effectiveRequestType = $requestCategory;
}

$payload = [
    'transfer_datetime' => trl_required('transfer_datetime'),
    'ref_no' => trl_required('ref_no'),
    'source_mode' => trl_required('source_mode'),
    'include_ref_no' => trl_required('include_ref_no'),
    // accept values from either the request form keys or the original transaction columns
    'wrong_biller_id' => trl_required_any(['wrong_biller_id', 'sub_billers_id', 'subbiller_id', 'subbiller', 'subbillerid']),
    'biller_name' => trl_required_any(['biller_name', 'sub_billers_name', 'subbiller', 'subbiller_name']),
    'account_no' => trl_required('account_no'),
    'name' => trl_required('name'),
    'payment_branch_id' => trl_required('payment_branch_id'),
    'payment_branch_name' => trl_required('payment_branch_name'),
    'payment_branch' => trl_required('payment_branch'),
    'amount' => trl_required('amount'),
    'type_of_request' => $effectiveRequestType,
    'correct_biller_id' => trl_required('correct_biller_id'),
    'correct_biller_name' => trl_required('correct_biller_name'),
    // support new field names and fallback to old names for compatibility
    'wrong_amount' => trl_required_any(['wrong_amount', 'reported_value']),
    'correct_amount' => trl_required_any(['correct_amount', 'actual_value']),
    'difference_value' => trl_required('difference_value'),
    'wrong_detail' => trl_required('wrong_detail'),
    'correct_detail' => trl_required('correct_detail'),
    'reason' => trl_required('reason')
];

$requiredKeys = [
    // Original biller fields may be empty for direct biller or partner transactions.
    'transfer_datetime', 'account_no', 'name',
    'payment_branch_id', 'payment_branch_name', 'amount', 'type_of_request', 'reason'
];

// If the request type requires correction details, make those fields required
if (strcasecmp($payload['type_of_request'], 'WRONG BILLER') === 0) {
    $requiredKeys[] = 'correct_biller_id';
    $requiredKeys[] = 'correct_biller_name';
}

// If the request type requires reported/actual values
if (strcasecmp($payload['type_of_request'], 'OVERSTATED AMOUNT') === 0 || strcasecmp($payload['type_of_request'], 'CANCELLED TRANSACTION') === 0) {
    $requiredKeys[] = 'wrong_amount';
    $requiredKeys[] = 'correct_amount';
}

if (in_array(strtoupper($payload['type_of_request']), $allowedChangeDetailsTypes, true)) {
    $requiredKeys[] = 'wrong_detail';
    $requiredKeys[] = 'correct_detail';
}

// Reference number is required for AUTO mode, or when manual includes it via the toggle
if (strcasecmp($payload['source_mode'], 'auto') === 0 || $payload['include_ref_no'] === '1') {
    $requiredKeys[] = 'ref_no';
}

$missing = [];
foreach ($requiredKeys as $k) {
    if ($payload[$k] === '') {
        $missing[] = $k;
    }
}

if (!empty($missing)) {
    echo json_encode([
        'success' => false,
        'message' => 'Please complete all required fields before submitting.'
    ]);
    exit;
}

$amount = is_numeric($payload['amount']) ? (float) $payload['amount'] : (float) str_replace(',', '', $payload['amount']);

// Parse reported/actual/difference if provided
$reported = null;
$actual = null;
$difference = null;
if ($payload['wrong_amount'] !== '') {
    $reported = is_numeric($payload['wrong_amount']) ? (float) $payload['wrong_amount'] : (float) str_replace(',', '', $payload['wrong_amount']);
}
if ($payload['correct_amount'] !== '') {
    $actual = is_numeric($payload['correct_amount']) ? (float) $payload['correct_amount'] : (float) str_replace(',', '', $payload['correct_amount']);
}
if ($payload['difference_value'] !== '') {
    $difference = is_numeric($payload['difference_value']) ? (float) $payload['difference_value'] : (float) str_replace(',', '', $payload['difference_value']);
}

// Prefer payment_branch (requested target), fallback to payment_branch_name only if needed.
$branchColumn = 'payment_branch';
$colCheck = mysqli_query($conn, "SHOW COLUMNS FROM mldb.trl LIKE 'payment_branch'");
if (!$colCheck || mysqli_num_rows($colCheck) === 0) {
    $branchColumn = 'payment_branch_name';
}

$paymentBranchValue = $payload['payment_branch_name'] !== '' ? $payload['payment_branch_name'] : $payload['payment_branch'];
$wrongBillerIdValue = $payload['wrong_biller_id'] !== '' ? (int) $payload['wrong_biller_id'] : null;
$billerNameValue = $payload['biller_name'] !== '' ? $payload['biller_name'] : null;

try {
    $attachments = trl_entry_validate_attachments(trl_entry_uploaded_files('attachments'));
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}

$trlStatus = empty($attachments) ? 'DRAFT' : null;

// Duplicate check: ensure reference number isn't already present
$refToCheck = trim((string) $payload['ref_no']);
if ($refToCheck !== '') {
    $chkSql = "SELECT trl_no FROM mldb.trl WHERE ref_no = ? LIMIT 1";
    $chkStmt = $conn->prepare($chkSql);
    if ($chkStmt) {
        $chkStmt->bind_param('s', $refToCheck);
        if (!$chkStmt->execute()) {
            $chkStmt->close();
            echo json_encode(['success' => false, 'message' => 'Unable to verify reference number.', 'code' => 'REF_CHECK_FAILED']);
            exit;
        }
        $chkStmt->bind_result($existingTrlNo);
        if ($chkStmt->fetch()) {
            $chkStmt->close();
            echo json_encode([
                'success' => false,
                'message' => sprintf('REFERENCE NO: %s is already written', $refToCheck),
                'code' => 'DUPLICATE_REF_NO',
                'ref_no' => $refToCheck,
                'existing_trl_no' => (int)$existingTrlNo
            ]);
            exit;
        }
        $chkStmt->close();
    }
}

$sql = "INSERT INTO mldb.trl (
    transfer_datetime,
    ref_no,
    wrong_biller_id,
    biller_name,
    account_no,
    name,
    payment_branch_id,
    {$branchColumn},
    amount,
    type_of_request,
    reason,
    status
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode([
        'success' => false,
        'message' => 'Unable to prepare insert statement.'
    ]);
    exit;
}

$stmt->bind_param(
    'ssisssssdsss',
    $payload['transfer_datetime'],
    $payload['ref_no'],
    $wrongBillerIdValue,
    $billerNameValue,
    $payload['account_no'],
    $payload['name'],
    $payload['payment_branch_id'],
    $paymentBranchValue,
    $amount,
    $payload['type_of_request'],
    $payload['reason'],
    $trlStatus
);

$conn->autocommit(false);

try {
    if (!$stmt->execute()) {
        throw new Exception('Failed to insert TRL record.');
    }

    $trlNo = (int) $conn->insert_id;
    if ($trlNo <= 0) {
        throw new Exception('Invalid TRL number generated.');
    }

    if (strcasecmp($payload['type_of_request'], 'WRONG BILLER') === 0) {
        $wrongSql = "INSERT INTO mldb.trl_wrongbiller (trl_no, correct_biller_id, correct_biller_name) VALUES (?, ?, ?)";
        $wrongStmt = $conn->prepare($wrongSql);
        if (!$wrongStmt) {
            throw new Exception('Unable to prepare wrong biller insert statement.');
        }

        $wrongStmt->bind_param(
            'iss',
            $trlNo,
            $payload['correct_biller_id'],
            $payload['correct_biller_name']
        );

        if (!$wrongStmt->execute()) {
            $wrongStmt->close();
            throw new Exception('Failed to insert wrong biller correction details.');
        }

        $wrongStmt->close();
    }

    // If the request type is OVERSTATED AMOUNT, insert overstated amount details into separate table
    if (strcasecmp($payload['type_of_request'], 'OVERSTATED AMOUNT') === 0) {
        $osSql = "INSERT INTO mldb.trl_overstatedamount (trl_no, wrong_amount, correct_amount, difference) VALUES (?, ?, ?, ?)";
        $osStmt = $conn->prepare($osSql);
        if (!$osStmt) {
            throw new Exception('Unable to prepare overstated amount insert statement.');
        }

        // compute difference if not provided
        $repVal = $reported !== null ? $reported : 0.0;
        $actVal = $actual !== null ? $actual : 0.0;
        $diffVal = $difference !== null ? $difference : ($repVal - $actVal);

        $osStmt->bind_param(
            'iddd',
            $trlNo,
            $repVal,
            $actVal,
            $diffVal
        );

        if (!$osStmt->execute()) {
            $osStmt->close();
            throw new Exception('Failed to insert overstated amount details.');
        }

        $osStmt->close();
    }

    // If the request type is CANCELLED TRANSACTION, insert cancelled transaction details
    if (strcasecmp($payload['type_of_request'], 'CANCELLED TRANSACTION') === 0) {
        $ctSql = "INSERT INTO mldb.trl_cancelledtransaction (trl_no, wrong_amount, correct_amount) VALUES (?, ?, ?)";
        $ctStmt = $conn->prepare($ctSql);
        if (!$ctStmt) {
            throw new Exception('Unable to prepare cancelled transaction insert statement.');
        }

        $repValC = $reported !== null ? $reported : 0.0;
        $actValC = $actual !== null ? $actual : 0.0;

        $ctStmt->bind_param('idd', $trlNo, $repValC, $actValC);

        if (!$ctStmt->execute()) {
            $ctStmt->close();
            throw new Exception('Failed to insert cancelled transaction details.');
        }

        $ctStmt->close();
    }

    foreach ($attachments as $attachment) {
        trl_entry_insert_attachment($conn, $trlNo, $id, $attachment);
    }

    // If reason is empty, attempt to auto-build it for certain request types
    if (trim((string)$payload['reason']) === '') {
        $tor = strtoupper(trim((string)$payload['type_of_request']));
        if ($tor === 'OVERSTATED AMOUNT' && $reported !== null && $actual !== null) {
            $diff = $difference !== null ? $difference : ($reported - $actual);
            $payload['reason'] = sprintf(
                'OVERSTATED AMOUNT PHP %s INSTEAD OF PHP %s WITH THE DIFFERENCE OF PHP %s',
                number_format($reported, 2, '.', ','),
                number_format($actual, 2, '.', ','),
                number_format($diff, 2, '.', ',')
            );
        } elseif ($tor === 'CANCELLED TRANSACTION' && $reported !== null && $actual !== null) {
            $payload['reason'] = sprintf(
                'Wrong amount posted PHP %s instead of PHP %s',
                number_format($reported, 2, '.', ','),
                number_format($actual, 2, '.', ',')
            );
        }
    }

    $conn->commit();
    $conn->autocommit(true);

    echo json_encode([
        'success' => true,
        'title' => empty($attachments) ? 'Draft Saved' : 'Transaction Request Log',
        'message' => empty($attachments)
            ? 'No attachment was provided, so the transaction was saved as a draft.'
            : 'Transaction Request Log has been submitted for review successfully!',
        'redirect' => empty($attachments)
            ? 'trl-entry.php?mode=draft'
            : '../trl-review/trl-review.php'
    ]);
    exit;
} catch (Exception $e) {
    $conn->rollback();
    $conn->autocommit(true);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
    exit;
}
