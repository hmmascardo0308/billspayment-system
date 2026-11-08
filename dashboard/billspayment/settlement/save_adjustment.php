<?php
// save_adjustment.php
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../vendor/autoload.php';
session_start();
@include_once __DIR__ . '/../../../templates/middleware.php';

header('Content-Type: application/json');

$id = resolve_user_identifier();
if (empty($id)) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}
if (!function_exists('has_any_permission') ||
    !has_any_permission(['Adjustment Entry Per Branch', 'Bills Payment'])) {
    echo json_encode(['success' => false, 'message' => 'No permission']);
    exit;
}

$display_name = 'GUEST';
if (isset($_SESSION['user_type'])) {
    if ($_SESSION['user_type'] === 'admin') {
        $display_name = $_SESSION['admin_name'] ?? 'ADMIN';
    } elseif ($_SESSION['user_type'] === 'user') {
        $display_name = $_SESSION['user_name'] ?? 'USER';
    }
}

// Accept JSON or multipart
if (!empty($_POST)) {
    $payload = $_POST;
    if (isset($_POST['payload'])) {
        $payload = json_decode($_POST['payload'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo json_encode(['success' => false, 'message' => 'Invalid JSON payload: ' . json_last_error_msg()]);
            exit;
        }
        if (!is_array($payload)) $payload = [];
    }
} else {
    $payload = json_decode(file_get_contents('php://input'), true) ?: [];
}

$txId       = (int)($payload['id'] ?? 0);
$reason     = $payload['reason'] ?? '';
$reasonData = $payload['reason_data'] ?? [];
$common     = $payload['common'] ?? [];

if (!$txId || !$reason) {
    echo json_encode(['success' => false, 'message' => 'Missing transaction id or reason']);
    exit;
}

// ============================================================
// HELPER: Normalize any datetime-ish string to MySQL DATETIME
// Accepts: "2026-10-26T14:51", "2026-10-26 14:51", "2026-10-26 14:51:00"
// Returns "YYYY-MM-DD HH:MM:SS" or null
// ============================================================
function normalize_datetime($val) {
    if (empty($val)) return null;
    $val = trim((string)$val);
    if ($val === '') return null;

    // Already correct?
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $val)) {
        return $val;
    }
    // YYYY-MM-DDTHH:MM
    if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $val)) {
        return str_replace('T', ' ', $val) . ':00';
    }
    // YYYY-MM-DDTHH:MM:SS
    if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $val)) {
        return str_replace('T', ' ', $val);
    }
    // YYYY-MM-DD
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
        return $val . ' 00:00:00';
    }
    // Fallback: try strtotime
    $ts = strtotime($val);
    if ($ts === false) return null;
    return date('Y-m-d H:i:s', $ts);
}

// ============================================================
// HELPER: Nullable numeric cast — returns null if empty, else float
// ============================================================
function num_or_null($val) {
    if ($val === null || $val === '' || $val === 'null') return null;
    return (float)$val;
}

// ============================================================
// HELPER: Nullable string — returns null if empty/undefined
// ============================================================
function str_or_null($val) {
    if ($val === null || $val === '' || $val === 'null') return null;
    return (string)$val;
}

// ============================================================
// 1) Fetch original transaction
// ============================================================
$sql = "SELECT * FROM mldb.billspayment_transaction WHERE id = ? LIMIT 1";
$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Prepare failed (select): ' . mysqli_error($conn)]);
    exit;
}
mysqli_stmt_bind_param($stmt, 'i', $txId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$src = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);
if (!$src) {
    echo json_encode(['success' => false, 'message' => 'Transaction not found']);
    exit;
}

// ============================================================
// 2) Handle file uploads
// ============================================================
function save_upload($field) {
    if (!empty($_FILES[$field]) && $_FILES[$field]['error'] === UPLOAD_ERR_OK) {
        $dir = __DIR__ . '/../../../uploads/adjustments/';
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
                return null;
            }
        }
        $ext  = pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION);
        $base = pathinfo($_FILES[$field]['name'], PATHINFO_FILENAME);
        $base = preg_replace('/[^a-zA-Z0-9._-]/', '_', $base);
        $name = time() . '_' . uniqid() . '_' . $base . ($ext ? '.' . $ext : '');
        if (move_uploaded_file($_FILES[$field]['tmp_name'], $dir . $name)) {
            return $name;
        }
    }
    return null;
}

$incorrectDoc  = save_upload('incorrect_document');
$nopayProof    = save_upload('nopay_proof');
$generalAttach = save_upload('common_attachments');

// ============================================================
// 3) Map reason-specific columns (defaults to NULL)
// ============================================================
$reasonCols = [
    'late_payment_date'               => null,
    'late_justification'              => null,
    'late_fee_reversal'               => null,
    'incorrect_original_amount'       => null,
    'incorrect_current_amount'        => null,
    'incorrect_correct_amount'        => null,
    'incorrect_difference'            => null,
    'incorrect_document'              => $incorrectDoc,
    'wrong_current_biller'            => null,
    'wrong_correct_biller'            => null,
    'wrong_invoice'                   => null,
    'wrong_action'                    => null,
    'dup_original_ref'                => null,
    'dup_duplicate_ref'               => null,
    'dup_original_transaction_date'   => null,
    'dup_duplicate_transaction_date'  => null,
    'dup_amount'                      => null,
    'dup_action'                      => null,
    'cust_authorization'              => null,
    'cust_auth_ref'                   => null,
    'cust_reason'                     => null,
    'cust_consent'                    => null,
    'sys_module'                      => null,
    'sys_error_code'                  => null,
    'sys_error_datetime'              => null,
    'sys_ticket'                      => null,
    'sys_root_cause'                  => null,
    'nopay_payment_date'              => null,
    'nopay_status'                    => null,
    'nopay_method'                    => null,
    'nopay_action'                    => null,
    'nopay_proof'                     => $nopayProof,
];

switch ($reason) {
    case 'late_posting':
        $reasonCols['late_payment_date']  = normalize_datetime($reasonData['late_original_date'] ?? null);
        $reasonCols['late_justification'] = str_or_null($reasonData['late_justification'] ?? null);
        $reasonCols['late_fee_reversal']  = str_or_null($reasonData['late_fee_reversal'] ?? 'no') ?: 'no';
        break;

    case 'incorrect_amount':
        $reasonCols['incorrect_original_amount'] = num_or_null($reasonData['incorrect_original_amount'] ?? null);
        $reasonCols['incorrect_current_amount']  = num_or_null($reasonData['incorrect_current_amount'] ?? null);
        $reasonCols['incorrect_correct_amount']  = num_or_null($reasonData['incorrect_correct_amount'] ?? null);
        $reasonCols['incorrect_difference'] =
            ($reasonCols['incorrect_correct_amount'] ?? 0) - ($reasonCols['incorrect_current_amount'] ?? 0);
        break;

    case 'wrong_biller':
        $reasonCols['wrong_current_biller'] = str_or_null($reasonData['wrong_current_biller'] ?? null);
        $reasonCols['wrong_correct_biller'] = str_or_null($reasonData['wrong_correct_biller'] ?? null);
        $reasonCols['wrong_invoice']        = str_or_null($reasonData['wrong_invoice'] ?? null);
        $reasonCols['wrong_action']         = str_or_null($reasonData['wrong_action'] ?? null);
        break;

    case 'duplicate_entry':
        $reasonCols['dup_original_ref']               = str_or_null($reasonData['dup_original_id'] ?? null);
        $reasonCols['dup_duplicate_ref']              = str_or_null($reasonData['dup_duplicate_id'] ?? null);
        // Original date = source transaction datetime
        $reasonCols['dup_original_transaction_date']  = normalize_datetime($src['datetime'] ?? null);
        $reasonCols['dup_duplicate_transaction_date'] = normalize_datetime($reasonData['dup_duplicate_transaction_date'] ?? null);
        $reasonCols['dup_amount']                     = num_or_null($reasonData['dup_amount'] ?? null);
        $reasonCols['dup_action']                     = str_or_null($reasonData['dup_action'] ?? null);
        break;

    case 'customer_request':
        $reasonCols['cust_authorization'] = str_or_null($reasonData['cust_authorization'] ?? null);
        $reasonCols['cust_auth_ref']      = str_or_null($reasonData['cust_auth_ref'] ?? null);
        $reasonCols['cust_reason']        = str_or_null($reasonData['cust_reason'] ?? null);
        $reasonCols['cust_consent']       = !empty($reasonData['cust_consent']) ? 1 : 0;
        break;

    case 'system_error':
        $reasonCols['sys_module']         = str_or_null($reasonData['sys_module'] ?? null);
        $reasonCols['sys_error_code']     = str_or_null($reasonData['sys_error_code'] ?? null);
        $reasonCols['sys_error_datetime'] = normalize_datetime($reasonData['sys_error_datetime'] ?? null);
        $reasonCols['sys_ticket']         = str_or_null($reasonData['sys_ticket'] ?? null);
        $reasonCols['sys_root_cause']     = str_or_null($reasonData['sys_root_cause'] ?? null);
        break;

    case 'no_payment':
        $reasonCols['nopay_payment_date'] = normalize_datetime($reasonData['nopay_record_date'] ?? null);
        $reasonCols['nopay_status']       = str_or_null($reasonData['nopay_status'] ?? null);
        $reasonCols['nopay_method']       = str_or_null($reasonData['nopay_method'] ?? null);
        $reasonCols['nopay_action']       = str_or_null($reasonData['nopay_action'] ?? null);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Invalid reason']);
        exit;
}

// ============================================================
// 4) Copy all source columns, then override reason-specific
// ============================================================
$copyCols = [
    'status','billing_invoice','run_date','report_date','settlement_date','datetime',
    'cancellation_date','source_file','control_no','reference_no','payor','address',
    'account_no','account_name','amount_paid','charge_to_customer','charge_to_partner',
    'contact_no','other_details','branch_id','branch_code','outlet','zone_code',
    'region_code','region_code_tg','region','region_tg','operator','remote_branch',
    'remote_operator','2nd_approver','sub_billers_id','sub_billers_name',
    'partner_name','partner_id','partner_id_kpx','mpm_gl_code','settled_by',
    'settle_unsettle','reason_not_settled','claim_unclaim','imported_by',
    'imported_date','rfp_no','cad_no','hold_status','post_transaction','import_status'
];

$row = [];
foreach ($copyCols as $c) {
    $row[$c] = $src[$c] ?? null;
}

// Merge reason-specific cols
$row = array_merge($row, $reasonCols);

// ============================================================
// 5) Common + audit fields
// ============================================================
$row['reason_for_adjustment']     = $reason;

// FIX: normalize datetime-local string safely
$effectiveRaw = $common['effective_date'] ?? '';
$row['adjustment_effective_date'] = normalize_datetime($effectiveRaw) ?: date('Y-m-d H:i:s');

$row['reviewed_by']       = str_or_null($common['approver'] ?? null) ?: $display_name;
$row['internal_notes']    = str_or_null($common['internal_notes'] ?? null);
$row['customer_comments'] = str_or_null($common['customer_comments'] ?? null);
$row['attachments']       = $generalAttach
                            ?: str_or_null($common['attachments'] ?? null);

// ============================================================
// 6) Build dynamic INSERT — robust type binding
// ============================================================
$cols = array_keys($row);
$placeholders = implode(',', array_fill(0, count($cols), '?'));

// Build type string: match column order, NULLs use 's' (sent as null via bind)
$types = '';
foreach ($row as $v) {
    if ($v === null)              $types .= 's';
    elseif (is_int($v))           $types .= 'i';
    elseif (is_float($v))         $types .= 'd';
    elseif (is_bool($v))          $types .= 'i';
    else                          $types .= 's';
}

$colList = '`' . implode('`,`', $cols) . '`';
$sql = "INSERT INTO mldb.reference_adjustment_entry ($colList) VALUES ($placeholders)";
$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    echo json_encode([
        'success' => false,
        'message' => 'Prepare failed: ' . mysqli_error($conn),
        'sql_debug' => $colList
    ]);
    exit;
}

// Build reference array — must match order of $row
$vals = array_values($row);
mysqli_stmt_bind_param($stmt, $types, ...$vals);

if (!mysqli_stmt_execute($stmt)) {
    echo json_encode([
        'success' => false,
        'message' => 'Insert failed: ' . mysqli_stmt_error($stmt),
        'types'   => $types,
        'col_count' => count($cols),
        'val_count' => count($vals)
    ]);
    exit;
}

$newId = mysqli_insert_id($conn);
mysqli_stmt_close($stmt);

// ============================================================
// 7) Optionally mark the source transaction as adjusted
// ============================================================
// Uncomment if you want to flag the source row after adjustment:
// $u = mysqli_prepare($conn, "UPDATE mldb.billspayment_transaction SET status='Adjusted', settle_unsettle='Adjusted' WHERE id=?");
// mysqli_stmt_bind_param($u, 'i', $txId);
// mysqli_stmt_execute($u);
// mysqli_stmt_close($u);

echo json_encode([
    'success' => true,
    'message' => 'Adjustment saved successfully',
    'new_id'  => $newId
]);