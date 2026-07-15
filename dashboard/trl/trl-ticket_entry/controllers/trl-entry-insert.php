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

if (!function_exists('has_permission') || !has_permission('TRL Ticket Entry')) {
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

$payload = [
    'transfer_datetime' => trl_required('transfer_datetime'),
    'ref_no' => trl_required('ref_no'),
    'source_mode' => trl_required('source_mode'),
    'include_ref_no' => trl_required('include_ref_no'),
    'wrong_biller_id' => trl_required_any(['wrong_biller_id', 'sub_billers_id', 'subbiller_id', 'subbiller', 'subbillerid']),
    'biller_name' => trl_required_any(['biller_name', 'sub_billers_name', 'subbiller', 'subbiller_name']),
    'account_no' => trl_required('account_no'),
    'name' => trl_required('name'),
    'payment_branch_id' => trl_required('payment_branch_id'),
    'payment_branch_name' => trl_required('payment_branch_name'),
    'payment_branch' => trl_required('payment_branch'),
    'amount' => trl_required('amount'),
    'type_of_request' => trl_required('type_of_request'),
    'correct_biller_id' => trl_required('correct_biller_id'),
    'correct_biller_name' => trl_required('correct_biller_name'),
    'wrong_amount' => trl_required_any(['wrong_amount', 'reported_value']),
    'correct_amount' => trl_required_any(['correct_amount', 'actual_value']),
    'difference_value' => trl_required('difference_value'),
    'reason' => trl_required('reason')
];

$requiredKeys = [
    'transfer_datetime', 'wrong_biller_id', 'biller_name', 'account_no', 'name',
    'payment_branch_id', 'payment_branch_name', 'amount', 'type_of_request', 'reason'
];

if (strcasecmp($payload['type_of_request'], 'WRONG BILLER') === 0) {
    $requiredKeys[] = 'correct_biller_id';
    $requiredKeys[] = 'correct_biller_name';
}

if (strcasecmp($payload['type_of_request'], 'OVERSTATED AMOUNT') === 0 || strcasecmp($payload['type_of_request'], 'CANCELLED TRANSACTION') === 0) {
    $requiredKeys[] = 'wrong_amount';
    $requiredKeys[] = 'correct_amount';
}

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

$branchColumn = 'payment_branch';
$colCheck = mysqli_query($conn, "SHOW COLUMNS FROM mldb.trl LIKE 'payment_branch'");
if (!$colCheck || mysqli_num_rows($colCheck) === 0) {
    $branchColumn = 'payment_branch_name';
}

$paymentBranchValue = $payload['payment_branch_name'] !== '' ? $payload['payment_branch_name'] : $payload['payment_branch'];

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
                'existing_trl_no' => (int) $existingTrlNo
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
    reason
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode([
        'success' => false,
        'message' => 'Unable to prepare insert statement.'
    ]);
    exit;
}

$stmt->bind_param(
    'ssssssssdss',
    $payload['transfer_datetime'],
    $payload['ref_no'],
    $payload['wrong_biller_id'],
    $payload['biller_name'],
    $payload['account_no'],
    $payload['name'],
    $payload['payment_branch_id'],
    $paymentBranchValue,
    $amount,
    $payload['type_of_request'],
    $payload['reason']
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

    if (strcasecmp($payload['type_of_request'], 'OVERSTATED AMOUNT') === 0) {
        $osSql = "INSERT INTO mldb.trl_overstatedamount (trl_no, wrong_amount, correct_amount, difference) VALUES (?, ?, ?, ?)";
        $osStmt = $conn->prepare($osSql);
        if (!$osStmt) {
            throw new Exception('Unable to prepare overstated amount insert statement.');
        }

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

    $conn->commit();
    $conn->autocommit(true);

    echo json_encode([
        'success' => true,
        'message' => 'Transaction Request Log has been submitted successfully!'
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
