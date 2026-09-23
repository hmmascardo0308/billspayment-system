<?php
// cad_loggings_reports.php
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../vendor/autoload.php';

date_default_timezone_set('Asia/Manila');

session_start();
@include_once __DIR__ . '/../../../templates/middleware.php';
$id = resolve_user_identifier();
if (empty($id)) { header('Location: ../../../login_form.php'); exit; }
if (!function_exists('has_any_permission') || !has_any_permission(['CAD Loggings', 'VPO'])) { header('Location: ../../home.php'); exit; }

$current_user_email = $_SESSION['admin_email'] ?? $_SESSION['user_email'] ?? '';

$current_transacted_by = strtoupper(trim(
    $_SESSION['admin_name'] ?? $_SESSION['user_name'] ?? 'SYSTEM'
));

// -------------------------------------------------------------------------
// Determine which remarks the current user is allowed to edit.
// -------------------------------------------------------------------------
$is_superuser    = (isset($_SESSION['access_level']) && intval($_SESSION['access_level']) === -1)
                || (isset($_SESSION['user_access_level']) && intval($_SESSION['user_access_level']) === -1);

$can_edit_vpo    = $is_superuser || (function_exists('has_permission') && has_permission('VPO'));
$can_edit_cad    = $is_superuser || (function_exists('has_permission') && has_permission('CAD Loggings'));

if (!$can_edit_vpo && !$can_edit_cad) {
    $can_edit_cad = true;
}

// Expose to JS
$client_perms = [
    'canEditVpo' => $can_edit_vpo,
    'canEditCad' => $can_edit_cad,
    'canAttach'  => true,
    'canEditStatus' => true,
];

// ---- Automatic routing target based on the CURRENT user's role ----
if ($is_superuser) {
    $auto_send_to = null;
} elseif ($can_edit_vpo && !$can_edit_cad) {
    $auto_send_to = 'CAD - Bills Payment';
} elseif ($can_edit_cad && !$can_edit_vpo) {
    $auto_send_to = 'VPO';
} elseif ($can_edit_vpo && $can_edit_cad) {
    $auto_send_to = 'VPO';
} else {
    $auto_send_to = null;
}

define('CAD_ATTACHMENT_REL_PATH', '../cad_loggings_attachments/');
define('CAD_ATTACHMENT_DIR', 'C:\\xampp\\htdocs\\BillsPayment\\dashboard\\cad-loggings\\cad_loggings_attachments');
define('CAD_ATTACHMENT_ALLOWED_EXT', ['jpg', 'jpeg', 'pdf', 'xls', 'xlsx']);
define('CAD_ATTACHMENT_MAX_BYTES', 10 * 1024 * 1024); // 10 MB per file

// =========================================================================
// AJAX endpoint — inline remarks update
// =========================================================================
if (isset($_GET['action']) && $_GET['action'] === 'update_remarks') {
    header('Content-Type: application/json');

    $rowId = (int)($_POST['id'] ?? 0);
    $field = trim($_POST['field'] ?? '');
    $value = trim((string)($_POST['value'] ?? ''));

    if ($rowId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid record ID.']);
        exit;
    }

    $allowedFields = ['vpo_remarks', 'cad_remarks'];
    if (!in_array($field, $allowedFields, true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid field.']);
        exit;
    }

    $field_allowed = false;
    if ($field === 'vpo_remarks' && $can_edit_vpo) {
        $field_allowed = true;
    } elseif ($field === 'cad_remarks' && $can_edit_cad) {
        $field_allowed = true;
    }

    if (!$field_allowed) {
        echo json_encode(['success' => false, 'message' => 'You do not have permission to edit this remark.']);
        exit;
    }

    $value = strtoupper($value);
    if (strlen($value) > 255) { $value = substr($value, 0, 255); }

    $bpReference = '';
    $sqlFetch = "SELECT bp_reference_no, status FROM cad_loggings.cad_loggings_requests WHERE id = ? LIMIT 1";
    if ($stmtF = $conn->prepare($sqlFetch)) {
        $stmtF->bind_param('i', $rowId);
        $stmtF->execute();
        $resF = $stmtF->get_result();
        if ($rowF = $resF->fetch_assoc()) {
            $bpReference = $rowF['bp_reference_no'];
            if (strtoupper((string)($rowF['status'] ?? '')) === 'CLOSED') {
                $stmtF->close();
                echo json_encode(['success' => false, 'message' => 'This record is CLOSED and can no longer be edited.']);
                exit;
            }
        }
        $stmtF->close();
    }

    if ($bpReference === '') {
        echo json_encode(['success' => false, 'message' => 'Record not found.']);
        exit;
    }

    $remarked_by = $current_transacted_by;
    $remarked_at = date('Y-m-d H:i:s');

    try {
        $conn->begin_transaction();

        $sql = "UPDATE cad_loggings.cad_loggings_requests SET {$field} = ? WHERE id = ? LIMIT 1";
        if (!$stmt = $conn->prepare($sql)) {
            throw new Exception('Prepare failed: ' . $conn->error);
        }
        $stmt->bind_param('si', $value, $rowId);
        if (!$stmt->execute()) {
            throw new Exception('Execute failed: ' . $stmt->error);
        }
        $stmt->close();

        $new_send_to = null;
        if ($field === 'vpo_remarks' && $can_edit_vpo && !$is_superuser) {
            $new_send_to = 'CAD - Bills Payment';
        } elseif ($field === 'cad_remarks' && $can_edit_cad && !$is_superuser) {
            $new_send_to = 'VPO';
        } elseif ($auto_send_to !== null) {
            $new_send_to = $auto_send_to;
        }

        if ($new_send_to !== null) {
            $sqlRoute = "UPDATE cad_loggings.cad_loggings_requests SET send_to = ? WHERE id = ? LIMIT 1";
            if ($stmtR = $conn->prepare($sqlRoute)) {
                $stmtR->bind_param('si', $new_send_to, $rowId);
                if (!$stmtR->execute()) {
                    throw new Exception('Auto-route failed: ' . $stmtR->error);
                }
                $stmtR->close();
            } else {
                throw new Exception('Auto-route prepare failed: ' . $conn->error);
            }
        }

        if ($field === 'vpo_remarks') {
            $sqlHist = "INSERT INTO cad_loggings.vpo_remarks_history
                        (bp_reference_no, vpo_remarks, remarked_by, remarked_at)
                        VALUES (?, ?, ?, ?)";
        } else {
            $sqlHist = "INSERT INTO cad_loggings.cad_remarks_history
                        (bp_reference_no, cad_remarks, remarked_by, remarked_at)
                        VALUES (?, ?, ?, ?)";
        }

        if (!$stmtH = $conn->prepare($sqlHist)) {
            throw new Exception('History prepare failed: ' . $conn->error);
        }
        $stmtH->bind_param('ssss', $bpReference, $value, $remarked_by, $remarked_at);
        if (!$stmtH->execute()) {
            throw new Exception('History execute failed: ' . $stmtH->error);
        }
        $stmtH->close();

        $conn->commit();
        echo json_encode([
            'success'  => true,
            'message'  => 'Remarks updated successfully.',
            'value'    => $value,
            'send_to'  => $new_send_to,
        ]);
    } catch (Exception $e) {
        $conn->rollback();
        error_log('update_remarks error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}
// =========================================================================

// =========================================================================
// AJAX endpoint — update status
// Both VPO and CAD users can change the status.
// Auto-route: VPO → CAD - Bills Payment ; CAD → VPO
// Status is limited to OPEN and CLOSED only.
// When status = CLOSED → closed_at, real_cancelled_date, closed_by are stamped.
// When status = OPEN   → closed_at, real_cancelled_date, closed_by are cleared.
// A record that is already CLOSED cannot be changed again.
// =========================================================================
if (isset($_GET['action']) && $_GET['action'] === 'update_status') {
    header('Content-Type: application/json');

    $rowId  = (int)($_POST['id'] ?? 0);
    $status = strtoupper(trim((string)($_POST['status'] ?? '')));

    if ($rowId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid record ID.']);
        exit;
    }

    $allowedStatuses = ['OPEN', 'CLOSED'];
    if (!in_array($status, $allowedStatuses, true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid status value.']);
        exit;
    }

    // ---- Block any status change on an already-CLOSED record ----
    $sqlChk = "SELECT status FROM cad_loggings.cad_loggings_requests WHERE id = ? LIMIT 1";
    if ($stmtChk = $conn->prepare($sqlChk)) {
        $stmtChk->bind_param('i', $rowId);
        $stmtChk->execute();
        $resChk = $stmtChk->get_result();
        if ($rowChk = $resChk->fetch_assoc()) {
            if (strtoupper((string)$rowChk['status']) === 'CLOSED') {
                $stmtChk->close();
                echo json_encode(['success' => false, 'message' => 'This record is already CLOSED and its status can no longer be changed.']);
                exit;
            }
        }
        $stmtChk->close();
    }

    // Auto-route based on current user's role
    $new_send_to = $auto_send_to; // may be null for superuser

    // When status = CLOSED → stamp closed_at, real_cancelled_date, closed_by.
    // When status = OPEN  → clear all three.
    $now = date('Y-m-d H:i:s');
    if ($status === 'CLOSED') {
        $closed_at_val           = $now;
        $real_cancelled_date_val = $now;
        $closed_by_val           = $current_transacted_by;
    } else {
        $closed_at_val           = null;
        $real_cancelled_date_val = null;
        $closed_by_val           = null;
    }

    try {
        $conn->begin_transaction();

        $sql = "UPDATE cad_loggings.cad_loggings_requests
                SET status = ?, closed_at = ?, real_cancelled_date = ?, closed_by = ?
                WHERE id = ? LIMIT 1";
        if (!$stmt = $conn->prepare($sql)) {
            throw new Exception('Prepare failed: ' . $conn->error);
        }
        $stmt->bind_param('ssssi', $status, $closed_at_val, $real_cancelled_date_val, $closed_by_val, $rowId);
        if (!$stmt->execute()) {
            throw new Exception('Execute failed: ' . $stmt->error);
        }
        $stmt->close();

        if ($new_send_to !== null) {
            $sqlRoute = "UPDATE cad_loggings.cad_loggings_requests SET send_to = ? WHERE id = ? LIMIT 1";
            if ($stmtR = $conn->prepare($sqlRoute)) {
                $stmtR->bind_param('si', $new_send_to, $rowId);
                if (!$stmtR->execute()) {
                    throw new Exception('Auto-route failed: ' . $stmtR->error);
                }
                $stmtR->close();
            } else {
                throw new Exception('Auto-route prepare failed: ' . $conn->error);
            }
        }

        $conn->commit();
        echo json_encode([
            'success'             => true,
            'message'             => 'Status updated successfully.',
            'value'               => $status,
            'send_to'             => $new_send_to,
            'closed_at'           => $closed_at_val,
            'real_cancelled_date' => $real_cancelled_date_val,
            'closed_by'           => $closed_by_val,
        ]);
    } catch (Exception $e) {
        $conn->rollback();
        error_log('update_status error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}
// =========================================================================

// =========================================================================
// AJAX endpoint — upload additional attachments (append-only)
// =========================================================================
if (isset($_GET['action']) && $_GET['action'] === 'upload_attachments') {
    header('Content-Type: application/json');

    $rowId = (int)($_POST['id'] ?? 0);
    if ($rowId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid record ID.']);
        exit;
    }

    $bpReference   = '';
    $existingFiles = [];
    $rowStatus     = '';
    $sqlFetch = "SELECT bp_reference_no, attachment_files, status
                 FROM cad_loggings.cad_loggings_requests WHERE id = ? LIMIT 1";
    if ($stmtF = $conn->prepare($sqlFetch)) {
        $stmtF->bind_param('i', $rowId);
        $stmtF->execute();
        $resF = $stmtF->get_result();
        if ($rowF = $resF->fetch_assoc()) {
            $bpReference = $rowF['bp_reference_no'];
            $rowStatus   = strtoupper((string)$rowF['status']);
            if (!empty($rowF['attachment_files'])) {
                foreach (explode(',', $rowF['attachment_files']) as $f) {
                    $f = trim($f);
                    if ($f !== '') $existingFiles[] = $f;
                }
            }
        }
        $stmtF->close();
    }

    if ($bpReference === '') {
        echo json_encode(['success' => false, 'message' => 'Record not found.']);
        exit;
    }

    // ---- Block uploads on CLOSED records ----
    if ($rowStatus === 'CLOSED') {
        echo json_encode(['success' => false, 'message' => 'This record is CLOSED and no longer accepts attachments.']);
        exit;
    }

    if (empty($_FILES['attachments']['name'][0])) {
        echo json_encode(['success' => false, 'message' => 'No files selected.']);
        exit;
    }

    if (!is_dir(CAD_ATTACHMENT_DIR)) {
        @mkdir(CAD_ATTACHMENT_DIR, 0775, true);
    }

    $uploaded_filenames = [];
    $errors = [];
    $file_count = count($_FILES['attachments']['name']);

    for ($i = 0; $i < $file_count; $i++) {
        $origName = $_FILES['attachments']['name'][$i];
        $tmpPath  = $_FILES['attachments']['tmp_name'][$i];
        $errCode  = $_FILES['attachments']['error'][$i];
        $size     = $_FILES['attachments']['size'][$i];

        if ($errCode !== UPLOAD_ERR_OK) { $errors[] = "$origName: upload error"; continue; }
        if ($size > CAD_ATTACHMENT_MAX_BYTES) { $errors[] = "$origName: exceeds 10 MB"; continue; }

        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (!in_array($ext, CAD_ATTACHMENT_ALLOWED_EXT, true)) {
            $errors[] = "$origName: unsupported extension";
            continue;
        }

        $baseName = pathinfo($origName, PATHINFO_FILENAME);
        $safeBase = preg_replace('/\s+/', '_', $baseName);
        $safeBase = preg_replace('/[^A-Za-z0-9._-]/', '', $safeBase);
        if ($safeBase === '') { $safeBase = 'file'; }

        $safeRef   = preg_replace('/[^A-Za-z0-9._-]/', '', $bpReference);
        $finalName = $safeRef . '_' . $safeBase . '.' . $ext;
        $destPath  = CAD_ATTACHMENT_DIR . DIRECTORY_SEPARATOR . $finalName;

        $counter = 1;
        while (file_exists($destPath)) {
            $finalName = $safeRef . '_' . $safeBase . '_' . $counter . '.' . $ext;
            $destPath  = CAD_ATTACHMENT_DIR . DIRECTORY_SEPARATOR . $finalName;
            $counter++;
        }

        if (move_uploaded_file($tmpPath, $destPath)) {
            $uploaded_filenames[] = $finalName;
        } else {
            error_log("CAD attachment move failed: {$origName} -> {$destPath}");
            $errors[] = "$origName: could not save";
        }
    }

    if (empty($uploaded_filenames)) {
        echo json_encode([
            'success' => false,
            'message' => 'No files were uploaded. ' . implode('; ', $errors)
        ]);
        exit;
    }

    $allFiles = array_merge($existingFiles, $uploaded_filenames);
    $csv      = implode(',', $allFiles);

    try {
        $sql = "UPDATE cad_loggings.cad_loggings_requests SET attachment_files = ? WHERE id = ? LIMIT 1";
        if (!$stmt = $conn->prepare($sql)) {
            throw new Exception('Prepare failed: ' . $conn->error);
        }
        $stmt->bind_param('si', $csv, $rowId);
        if (!$stmt->execute()) {
            throw new Exception('Execute failed: ' . $stmt->error);
        }
        $stmt->close();

        echo json_encode([
            'success'    => true,
            'message'    => 'Attachment(s) uploaded successfully.',
            'files'      => $uploaded_filenames,
            'all_files'  => $allFiles,
            'warnings'   => $errors
        ]);
    } catch (Exception $e) {
        foreach ($uploaded_filenames as $f) {
            @unlink(CAD_ATTACHMENT_DIR . DIRECTORY_SEPARATOR . $f);
        }
        error_log('upload_attachments error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}
// =========================================================================

// =========================================================================
// AJAX endpoint — fetch remarks history (thread) for a BP Ref No.
// =========================================================================
if (isset($_GET['action']) && $_GET['action'] === 'get_remarks_history') {
    header('Content-Type: application/json');

    $bpRef = trim((string)($_GET['bp_ref'] ?? ''));
    if ($bpRef === '') {
        echo json_encode(['success' => false, 'message' => 'Missing BP Ref No.', 'items' => []]);
        exit;
    }

    $items = [];

    try {
        $sqlV = "SELECT bp_reference_no, vpo_remarks AS remarks, remarked_by, remarked_at
                 FROM cad_loggings.vpo_remarks_history
                 WHERE bp_reference_no = ?
                 ORDER BY remarked_at ASC, id ASC";
        if ($stmtV = $conn->prepare($sqlV)) {
            $stmtV->bind_param('s', $bpRef);
            $stmtV->execute();
            $resV = $stmtV->get_result();
            while ($rowV = $resV->fetch_assoc()) {
                $items[] = [
                    'source'      => 'VPO',
                    'remarks'     => $rowV['remarks'],
                    'remarked_by' => $rowV['remarked_by'],
                    'remarked_at' => $rowV['remarked_at'],
                ];
            }
            $stmtV->close();
        }

        $sqlC = "SELECT bp_reference_no, cad_remarks AS remarks, remarked_by, remarked_at
                 FROM cad_loggings.cad_remarks_history
                 WHERE bp_reference_no = ?
                 ORDER BY remarked_at ASC, id ASC";
        if ($stmtC = $conn->prepare($sqlC)) {
            $stmtC->bind_param('s', $bpRef);
            $stmtC->execute();
            $resC = $stmtC->get_result();
            while ($rowC = $resC->fetch_assoc()) {
                $items[] = [
                    'source'      => 'CAD',
                    'remarks'     => $rowC['remarks'],
                    'remarked_by' => $rowC['remarked_by'],
                    'remarked_at' => $rowC['remarked_at'],
                ];
            }
            $stmtC->close();
        }

        usort($items, function ($a, $b) {
            $ta = strtotime($a['remarked_at']);
            $tb = strtotime($b['remarked_at']);
            if ($ta === $tb) return 0;
            return $ta < $tb ? -1 : 1;
        });

        echo json_encode(['success' => true, 'items' => $items]);
    } catch (Exception $e) {
        error_log('get_remarks_history error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage(), 'items' => []]);
    }
    exit;
}
// =========================================================================

// ---------- Filter / paging / sorting inputs ----------
$search       = trim($_GET['q']          ?? '');
$filterReason = trim($_GET['reason']     ?? '');
$filterFault  = trim($_GET['fault']      ?? '');
$filterAction = trim($_GET['pa']         ?? '');
$filterStatus = trim($_GET['status']     ?? '');
$filterSendTo = trim($_GET['send_to']    ?? '');
$dateFrom     = trim($_GET['date_from']  ?? '');
$dateTo       = trim($_GET['date_to']    ?? '');

$allowedSortCols = [
    'id', 'payment_date', 'cancelled_date', 'region', 'branch_name',
    'bp_reference_no', 'account_no', 'account_name', 'amount_paid',
    'reason', 'fault', 'partner_action', 'requested_date', 'transacted_by',
    'original_biller', 'correct_biller', 'status', 'send_to'
];
$sortCol = $_GET['sort'] ?? 'id';
if (!in_array($sortCol, $allowedSortCols, true)) $sortCol = 'id';
$sortDir = strtoupper($_GET['dir'] ?? 'DESC');
if (!in_array($sortDir, ['ASC', 'DESC'], true)) $sortDir = 'DESC';

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$offset  = ($page - 1) * $perPage;

$where = []; $params = []; $types = '';

if ($search !== '') {
    $where[] = "(bp_reference_no LIKE ? OR account_no LIKE ? OR account_name LIKE ? OR transacted_by LIKE ? OR original_biller LIKE ? OR correct_biller LIKE ?)";
    $like = '%' . $search . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types   .= 'ssssss';
}
if ($filterReason !== '') { $where[] = "reason = ?";         $params[] = $filterReason; $types .= 's'; }
if ($filterFault  !== '') { $where[] = "fault = ?";          $params[] = $filterFault;  $types .= 's'; }
if ($filterAction !== '') { $where[] = "partner_action = ?"; $params[] = $filterAction; $types .= 's'; }
if ($filterStatus !== '') { $where[] = "status = ?";         $params[] = $filterStatus; $types .= 's'; }
if ($filterSendTo !== '') { $where[] = "send_to = ?";        $params[] = $filterSendTo; $types .= 's'; }
if ($dateFrom !== '')     { $where[] = "DATE(requested_date) >= ?"; $params[] = $dateFrom; $types .= 's'; }
if ($dateTo !== '')       { $where[] = "DATE(requested_date) <= ?"; $params[] = $dateTo;   $types .= 's'; }

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$totalRows = 0;
$countSql  = "SELECT COUNT(*) AS c FROM cad_loggings.cad_loggings_requests $whereSql";
if ($stmtC = $conn->prepare($countSql)) {
    if ($types !== '') { $stmtC->bind_param($types, ...$params); }
    $stmtC->execute();
    $resC = $stmtC->get_result();
    if ($rowC = $resC->fetch_assoc()) { $totalRows = (int)$rowC['c']; }
    $stmtC->close();
}

$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

$rows = [];
$sql = "SELECT
            id, payment_date, cancelled_date, region, branch_id, branch_name,
            original_biller_id, original_biller, correct_biller_id, correct_biller,
            bp_reference_no, account_no, account_name, amount_paid, correct_amount,
            ir_no, reason, fault, partner_action, attachment_files,
            vpo_remarks, cad_remarks, requested_date, transacted_by, status, send_to,
            closed_at, real_cancelled_date, closed_by
        FROM cad_loggings.cad_loggings_requests
        $whereSql
        ORDER BY $sortCol $sortDir
        LIMIT ? OFFSET ?";

if ($stmt = $conn->prepare($sql)) {
    $bindTypes  = $types . 'ii';
    $bindParams = $params;
    $bindParams[] = $perPage;
    $bindParams[] = $offset;
    $stmt->bind_param($bindTypes, ...$bindParams);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) { $rows[] = $r; }
    $stmt->close();
}

$reasonOptions = []; $faultOptions = []; $actionOptions = []; $sendToOptions = [];

if ($r1 = $conn->query("SELECT DISTINCT reason FROM cad_loggings.cad_loggings_requests WHERE reason IS NOT NULL AND reason <> '' ORDER BY reason ASC")) {
    while ($x = $r1->fetch_assoc()) { $reasonOptions[] = $x['reason']; } $r1->free();
}
if ($r2 = $conn->query("SELECT DISTINCT fault FROM cad_loggings.cad_loggings_requests WHERE fault IS NOT NULL AND fault <> '' ORDER BY fault ASC")) {
    while ($x = $r2->fetch_assoc()) { $faultOptions[] = $x['fault']; } $r2->free();
}
if ($r3 = $conn->query("SELECT DISTINCT partner_action FROM cad_loggings.cad_loggings_requests WHERE partner_action IS NOT NULL AND partner_action <> '' ORDER BY partner_action ASC")) {
    while ($x = $r3->fetch_assoc()) { $actionOptions[] = $x['partner_action']; } $r3->free();
}

$statusOptions = ['OPEN', 'CLOSED'];

if ($r5 = $conn->query("SELECT DISTINCT send_to FROM cad_loggings.cad_loggings_requests WHERE send_to IS NOT NULL AND send_to <> '' ORDER BY send_to ASC")) {
    while ($x = $r5->fetch_assoc()) { $sendToOptions[] = $x['send_to']; } $r5->free();
}

function sortUrl($col, $currentCol, $currentDir) {
    $dir = ($col === $currentCol && $currentDir === 'ASC') ? 'DESC' : 'ASC';
    $qs  = $_GET; $qs['sort'] = $col; $qs['dir'] = $dir; $qs['page'] = 1;
    return '?' . http_build_query($qs);
}
function sortIcon($col, $currentCol, $currentDir) {
    if ($col !== $currentCol) return '<i class="fa-solid fa-sort" style="opacity:.3"></i>';
    return $currentDir === 'ASC' ? '<i class="fa-solid fa-sort-up"></i>' : '<i class="fa-solid fa-sort-down"></i>';
}
function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES); }

function statusBadge($status) {
    $status = strtoupper((string)$status);
    if ($status === '') return '<span class="badge badge-none">—</span>';
    $class = 'badge-status-default';
    if ($status === 'OPEN')       $class = 'badge-status-open';
    elseif ($status === 'CLOSED') $class = 'badge-status-closed';
    return '<span class="badge ' . $class . '">' . e($status) . '</span>';
}

function sendToBadge($sendTo) {
    $sendTo = (string)$sendTo;
    if ($sendTo === '') return '<span class="badge badge-none">—</span>';
    $cls = 'badge-sendto-default';
    if ($sendTo === 'VPO') $cls = 'badge-sendto-vpo';
    elseif ($sendTo === 'CAD - Bills Payment') $cls = 'badge-sendto-cad';
    return '<span class="badge ' . $cls . '">' . e($sendTo) . '</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CAD Loggings Report | <?php if($_SESSION['user_type'] === 'admin' || $_SESSION['user_type'] === 'user') echo ucfirst($_SESSION['user_type']); else echo "Guest";?></title>
    <link rel="stylesheet" href="../../../assets/css/templates/style.css?v=<?php echo time(); ?>">
    <script src="https://kit.fontawesome.com/30b908cc5a.js" crossorigin="anonymous"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="../../../assets/js/sweetalert2.all.min.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
    <link rel="icon" href="../../../images/MLW logo.png" type="image/png">
    <link rel="stylesheet" href="cad_reports.css?v=<?= time(); ?>">
</head>
<body>
    <div class="main-container">
        <?php include '../../../templates/header_ui.php'; ?>
        <?php include '../../../templates/sidebar.php'; ?>

        <div class="bp-section-header" role="region" aria-label="Page title">
            <div class="bp-section-title">
                <i class="fa-solid fa-layer-group" aria-hidden="true"></i>
                <div><h2>CAD Loggings Report</h2></div>
            </div>
        </div>

        <!-- Filters -->
        <form method="GET" class="cad-filters">
            <div class="filter-group">
                <label for="q">Search</label>
                <input type="text" id="q" name="q" placeholder="Search Ref. No."
                       value="<?= e($search) ?>">
            </div>

            <div class="filter-group">
                <label for="reason">Reason</label>
                <select id="reason" name="reason">
                    <option value="">-- All --</option>
                    <?php foreach ($reasonOptions as $opt): ?>
                        <option value="<?= e($opt) ?>" <?= $filterReason === $opt ? 'selected' : '' ?>><?= e($opt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label for="fault">Fault</label>
                <select id="fault" name="fault">
                    <option value="">-- All --</option>
                    <?php foreach ($faultOptions as $opt): ?>
                        <option value="<?= e($opt) ?>" <?= $filterFault === $opt ? 'selected' : '' ?>><?= e($opt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label for="pa">Partner Action</label>
                <select id="pa" name="pa">
                    <option value="">-- All --</option>
                    <?php foreach ($actionOptions as $opt): ?>
                        <option value="<?= e($opt) ?>" <?= $filterAction === $opt ? 'selected' : '' ?>><?= e($opt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label for="status">Status</label>
                <select id="status" name="status">
                    <option value="">-- All --</option>
                    <?php foreach ($statusOptions as $opt): ?>
                        <option value="<?= e($opt) ?>" <?= $filterStatus === $opt ? 'selected' : '' ?>><?= e($opt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label for="send_to">Send To</label>
                <select id="send_to" name="send_to">
                    <option value="">-- All --</option>
                    <?php foreach ($sendToOptions as $opt): ?>
                        <option value="<?= e($opt) ?>" <?= $filterSendTo === $opt ? 'selected' : '' ?>><?= e($opt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label for="date_from">Requested From</label>
                <input type="date" id="date_from" name="date_from" value="<?= e($dateFrom) ?>">
            </div>

            <div class="filter-group">
                <label for="date_to">Requested To</label>
                <input type="date" id="date_to" name="date_to" value="<?= e($dateTo) ?>">
            </div>

            <div class="filter-actions">
                <button type="submit" class="btn-apply"><i class="fa-solid fa-filter"></i> Apply</button>
                <a href="cad_loggings.php" class="btn-new"><i class="fa-solid fa-plus"></i> New</a>
                <a href="?" class="btn-reset"><i class="fa-solid fa-rotate-left"></i></a>

            </div>
        </form>

        <!-- Table -->
        <div class="cad-table-wrap">
            <table class="cad-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th><a href="<?= e(sortUrl('bp_reference_no', $sortCol, $sortDir)) ?>">BP Ref No <?= sortIcon('bp_reference_no', $sortCol, $sortDir) ?></a></th>
                        <th><a href="<?= e(sortUrl('status', $sortCol, $sortDir)) ?>">Status <?= sortIcon('status', $sortCol, $sortDir) ?></a></th>
                        <th><a href="<?= e(sortUrl('send_to', $sortCol, $sortDir)) ?>">Location <?= sortIcon('send_to', $sortCol, $sortDir) ?></a></th>
                        <th><a href="<?= e(sortUrl('reason', $sortCol, $sortDir)) ?>">Reason <?= sortIcon('reason', $sortCol, $sortDir) ?></a></th>
                        <th><a href="<?= e(sortUrl('payment_date', $sortCol, $sortDir)) ?>">Payment Date <?= sortIcon('payment_date', $sortCol, $sortDir) ?></a></th>
                        <th><a href="<?= e(sortUrl('cancelled_date', $sortCol, $sortDir)) ?>">Cancelled Date <?= sortIcon('cancelled_date', $sortCol, $sortDir) ?></a></th>
                        <th><a href="<?= e(sortUrl('account_no', $sortCol, $sortDir)) ?>">Account No <?= sortIcon('account_no', $sortCol, $sortDir) ?></a></th>
                        <th><a href="<?= e(sortUrl('account_name', $sortCol, $sortDir)) ?>">Account Name <?= sortIcon('account_name', $sortCol, $sortDir) ?></a></th>
                        <th><a href="<?= e(sortUrl('amount_paid', $sortCol, $sortDir)) ?>">Amount <?= sortIcon('amount_paid', $sortCol, $sortDir) ?></a></th>
                        <th><a href="<?= e(sortUrl('original_biller', $sortCol, $sortDir)) ?>">Partner <?= sortIcon('original_biller', $sortCol, $sortDir) ?></a></th>
                        <th><a href="<?= e(sortUrl('correct_biller', $sortCol, $sortDir)) ?>">Correct Partner <?= sortIcon('correct_biller', $sortCol, $sortDir) ?></a></th>
                        <th>Correct Amount</th>
                        <th><a href="<?= e(sortUrl('fault', $sortCol, $sortDir)) ?>">Fault <?= sortIcon('fault', $sortCol, $sortDir) ?></a></th>
                        <th><a href="<?= e(sortUrl('partner_action', $sortCol, $sortDir)) ?>">Partner Action <?= sortIcon('partner_action', $sortCol, $sortDir) ?></a></th>
                        <th><a href="<?= e(sortUrl('requested_date', $sortCol, $sortDir)) ?>">Requested Date <?= sortIcon('requested_date', $sortCol, $sortDir) ?></a></th>
                        <th><a href="<?= e(sortUrl('transacted_by', $sortCol, $sortDir)) ?>">Transacted By <?= sortIcon('transacted_by', $sortCol, $sortDir) ?></a></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="17" style="text-align:center; padding: 30px; color:#6b7280;">
                                <i class="fa-solid fa-inbox" style="font-size:24px; display:block; margin-bottom:8px;"></i>
                                No records found.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php $loopIndex = 0; ?>
                        <?php foreach ($rows as $r): ?>
                        <?php
                            $loopIndex++;
                            $rowNumber = $offset + $loopIndex;

                            $attachments = [];
                            if (!empty($r['attachment_files'])) {
                                foreach (explode(',', $r['attachment_files']) as $f) {
                                    $f = trim($f);
                                    if ($f !== '') $attachments[] = $f;
                                }
                            }
                            $rowData = [
                                'id'                  => $r['id'],
                                'bp_reference_no'     => $r['bp_reference_no'],
                                'payment_date'        => $r['payment_date']   ? date('m/d/Y', strtotime($r['payment_date']))   : '',
                                'cancelled_date'      => $r['cancelled_date'] ? date('m/d/Y', strtotime($r['cancelled_date'])) : '',
                                'region'              => $r['region'],
                                'branch_id'           => $r['branch_id'],
                                'branch_name'         => $r['branch_name'],
                                'original_biller'     => $r['original_biller_id'] . ($r['original_biller'] ? ' - ' . $r['original_biller'] : ''),
                                'correct_biller'      => $r['correct_biller_id'] ? ($r['correct_biller_id'] . ' - ' . $r['correct_biller']) : '',
                                'account_no'          => $r['account_no'],
                                'account_name'        => $r['account_name'],
                                'amount_paid'         => number_format((float)$r['amount_paid'], 2),
                                'correct_amount'      => $r['correct_amount'] !== null ? number_format((float)$r['correct_amount'], 2) : '',
                                'ir_no'               => $r['ir_no'],
                                'reason'              => $r['reason'],
                                'fault'               => $r['fault'],
                                'partner_action'      => $r['partner_action'],
                                'status'              => $r['status'],
                                'send_to'             => $r['send_to'],
                                'vpo_remarks'         => $r['vpo_remarks'],
                                'cad_remarks'         => $r['cad_remarks'],
                                'requested_date'      => $r['requested_date'] ? date('m/d/Y H:i:s', strtotime($r['requested_date'])) : '',
                                'transacted_by'       => $r['transacted_by'],
                                'attachments'         => $attachments,
                                'closed_at'           => $r['closed_at'] ? date('m/d/Y H:i:s', strtotime($r['closed_at'])) : '',
                                'real_cancelled_date' => $r['real_cancelled_date'] ? date('m/d/Y H:i:s', strtotime($r['real_cancelled_date'])) : '',
                                'closed_by'           => $r['closed_by'] ?? '',
                            ];
                        ?>
                        <tr class="cad-row" data-row='<?= e(json_encode($rowData, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'>
                            <td><?= $rowNumber ?></td>
                            <td><strong><?= e($r['bp_reference_no']) ?></strong></td>
                            <td class="status-cell-<?= (int)$r['id'] ?>"><?= statusBadge($r['status']) ?></td>
                            <td class="sendto-cell-<?= (int)$r['id'] ?>"><?= sendToBadge($r['send_to']) ?></td>
                            <td>
                                <?php if ($r['reason']): ?>
                                    <span class="badge badge-reason"><?= e($r['reason']) ?></span>
                                <?php else: ?><span class="badge badge-none">—</span><?php endif; ?>
                            </td>
                            <td><?= $r['payment_date'] ? date('m/d/Y', strtotime($r['payment_date'])) : '—' ?></td>
                            <td><?= $r['cancelled_date'] ? date('m/d/Y', strtotime($r['cancelled_date'])) : '—' ?></td>
                            <td><?= e($r['account_no']) ?></td>
                            <td class="col-wrap"><?= e($r['account_name']) ?></td>
                            <td style="text-align:right;">₱ <?= number_format((float)$r['amount_paid'], 2) ?></td>
                            <td class="col-wrap">
                                <?php if (!empty($r['original_biller_id'])): ?>
                                    <?= e($r['original_biller_id']) ?> -
                                    <?php if (!empty($r['original_biller'])): ?>
                                        <br><small style="color:#6b7280;"><?= e($r['original_biller']) ?></small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color:#9ca3af;">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="col-wrap">
                                <?php if (!empty($r['correct_biller_id'])): ?>
                                    <?= e($r['correct_biller_id']) ?> -
                                    <?php if (!empty($r['correct_biller'])): ?>
                                        <br><small style="color:#6b7280;"><?= e($r['correct_biller']) ?></small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color:#9ca3af;">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:right;">
                                <?= $r['correct_amount'] !== null ? '₱ ' . number_format((float)$r['correct_amount'], 2) : '<span style="color:#9ca3af;">—</span>' ?>
                            </td>
                            <td>
                                <?php if ($r['fault']): ?>
                                    <span class="badge badge-fault"><?= e($r['fault']) ?></span>
                                <?php else: ?><span class="badge badge-none">—</span><?php endif; ?>
                            </td>
                            <td>
                                <?php if ($r['partner_action']): ?>
                                    <span class="badge badge-action"><?= e($r['partner_action']) ?></span>
                                <?php else: ?><span class="badge badge-none">—</span><?php endif; ?>
                            </td>
                            <td><?= $r['requested_date'] ? date('m/d/Y H:i:s', strtotime($r['requested_date'])) : '—' ?></td>
                            <td><?= e($r['transacted_by']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pager -->
        <?php if ($totalRows > 0): ?>
        <div class="cad-pager">
            <div>
                Showing <strong><?= $offset + 1 ?></strong> – <strong><?= min($offset + $perPage, $totalRows) ?></strong>
                of <strong><?= $totalRows ?></strong> record(s). <span style="color: red; font-weight: bold; font-size: 14px;">Double click row to view details / edit remarks.</span>
            </div>
            <div class="pages">
                <?php
                    $qBase = $_GET; unset($qBase['page']);
                    $mkUrl = function ($p) use ($qBase) { $qBase['page'] = $p; return '?' . http_build_query($qBase); };
                ?>
                <a class="<?= $page <= 1 ? 'disabled' : '' ?>" href="<?= e($mkUrl(max(1, $page - 1))) ?>">&laquo; Prev</a>
                <?php
                    $start = max(1, $page - 2);
                    $end   = min($totalPages, $page + 2);
                    if ($start > 1) {
                        echo '<a href="' . e($mkUrl(1)) . '">1</a>';
                        if ($start > 2) echo '<span>…</span>';
                    }
                    for ($p = $start; $p <= $end; $p++) {
                        if ($p === $page) echo '<span class="active">' . $p . '</span>';
                        else echo '<a href="' . e($mkUrl($p)) . '">' . $p . '</a>';
                    }
                    if ($end < $totalPages) {
                        if ($end < $totalPages - 1) echo '<span>…</span>';
                        echo '<a href="' . e($mkUrl($totalPages)) . '">' . $totalPages . '</a>';
                    }
                ?>
                <a class="<?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= e($mkUrl(min($totalPages, $page + 1))) ?>">Next &raquo;</a>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <!-- View modal -->
    <div class="cad-modal" id="rowModal">
        <div class="cad-modal-box">
            <div class="cad-modal-header">
                <div class="header-left">
                    <div class="modal-eyebrow">
                        <i class="fa-solid fa-file-lines"></i> CAD Log Details
                    </div>
                    <h3>
                        <span class="bp-chip" id="modalBpRef">—</span>
                        <span id="modalStatus"></span>
                    </h3>
                </div>
                <button type="button" class="close-modal" title="Close" aria-label="Close">&times;</button>
            </div>
            <div class="cad-modal-body" id="rowModalBody">
                <!-- filled dynamically -->
            </div>
            <div class="cad-modal-footer">
                <button type="button" class="close-modal">
                    <i class="fa-solid fa-xmark"></i> Close
                </button>
            </div>
        </div>
    </div>

    <?php include '../../../templates/footer.php'; ?>

    <script>
        window.CAD_PERMS = <?= json_encode($client_perms, JSON_UNESCAPED_SLASHES) ?>;
        window.CAD_AUTO_SEND_TO = <?= json_encode($auto_send_to) ?>;

        $(document).ready(function() {
            $('#reason, #fault, #pa, #status, #send_to').select2({ width: '100%', allowClear: true, placeholder: '-- All --' });

            function esc(s) {
                return String(s == null ? '' : s)
                    .replace(/&/g,'&amp;').replace(/</g,'&lt;')
                    .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
            }
            function field(label, value, extraClass) {
                extraClass = extraClass || '';
                var empty = (value === undefined || value === null || value === '' || value === '—');
                var cls = 'field ' + extraClass + (empty ? ' empty' : '');
                var displayVal = empty ? '—' : esc(value);
                return '<div class="' + cls.trim() + '">' +
                        '<div class="lbl">' + esc(label) + '</div>' +
                        '<div class="val">' + displayVal + '</div>' +
                       '</div>';
            }
            function section(title, icon, innerHtml) {
                return '<div class="cad-section">' +
                        '<div class="cad-section-title"><i class="fa-solid ' + icon + '"></i> ' + esc(title) + '</div>' +
                        innerHtml +
                       '</div>';
            }
            function fileIconClass(fname) {
                var ext = (fname.split('.').pop() || '').toLowerCase();
                if (ext === 'pdf')  return { cls: 'pdf', ic: 'fa-file-pdf' };
                if (ext === 'xls' || ext === 'xlsx') return { cls: 'xls', ic: 'fa-file-excel' };
                if (ext === 'jpg' || ext === 'jpeg') return { cls: 'jpg', ic: 'fa-file-image' };
                return { cls: 'file', ic: 'fa-file' };
            }

            function readonlyBlock(label, currentValue, icon) {
                var safeVal = esc(currentValue == null || currentValue === '' ? '—' : currentValue);
                return '' +
                    '<div class="field full">' +
                      '<div class="cad-edit-block">' +
                        '<label><i class="fa-solid ' + (icon || 'fa-comment-dots') + '"></i> ' + esc(label) + '</label>' +
                        '<div class="readonly-remark">' + safeVal + '</div>' +
                      '</div>' +
                    '</div>';
            }

            function editableBlock(label, fieldKey, rowId, currentValue, icon) {
                var safeVal = esc(currentValue == null ? '' : currentValue);
                return '' +
                    '<div class="field full">' +
                      '<div class="cad-edit-block">' +
                        '<label><i class="fa-solid ' + (icon || 'fa-pen-to-square') + '"></i> ' + esc(label) + '</label>' +
                        '<textarea data-field="' + fieldKey + '" data-id="' + rowId + '" placeholder="Enter ' + esc(label) + '...">' + safeVal + '</textarea>' +
                        '<div class="cad-edit-actions">' +
                          '<span class="save-status"></span>' +
                          '<button type="button" class="btn-save-small" data-field="' + fieldKey + '" data-id="' + rowId + '">' +
                            '<i class="fa-solid fa-floppy-disk"></i> Save ' + esc(label.split(' ')[0]) + ' Remarks'+
                          '</button>' +
                        '</div>' +
                      '</div>' +
                    '</div>';
            }

            function statusEditorBlock(rowId, currentStatus) {
                var statuses = ['OPEN', 'CLOSED'];
                var opts = '';
                statuses.forEach(function(s) {
                    opts += '<option value="' + s + '"' + (s === (currentStatus || '').toUpperCase() ? ' selected' : '') + '>' + s + '</option>';
                });
                return '' +
                    '<div class="field full">' +
                      '<div class="cad-edit-block">' +
                        '<label><i class="fa-solid fa-circle-check"></i> Status</label>' +
                        '<div class="status-editor">' +
                          '<select data-id="' + rowId + '" class="status-select">' + opts + '</select>' +
                          '<button type="button" class="btn-save-status" data-id="' + rowId + '">' +
                            '<i class="fa-solid fa-floppy-disk"></i> Save Status' +
                          '</button>' +
                          '<span class="save-status"></span>' +
                        '</div>' +
                      '</div>' +
                    '</div>';
            }

            function sendToReadonlyBlock(currentSendTo) {
                var val = (currentSendTo || '—');
                var cls = 'badge-sendto-default';
                if (val === 'VPO') cls = 'badge-sendto-vpo';
                else if (val === 'CAD - Bills Payment') cls = 'badge-sendto-cad';
                var badge = (currentSendTo === '' || currentSendTo == null)
                    ? '<span style="color:#9ca3af;">—</span>'
                    : '<span class="badge ' + cls + '">' + esc(val) + '</span>';
                return '' +
                    '<div class="field full">' +
                      '<div class="cad-edit-block">' +
                        '<label><i class="fa-solid fa-paper-plane"></i> Sent To ' +
                          '<span class="lock-icon" title="Auto-routed"><i class="fa-solid fa-lock"></i></span>' +
                        '</label>' +
                        '<div class="sendto-readonly">' + badge + '</div>' +
                      '</div>' +
                    '</div>';
            }

            function attachmentUploaderBlock(rowId) {
                return '' +
                    '<div class="attach-uploader">' +
                      '<label><i class="fa-solid fa-cloud-arrow-up"></i> Add Attachment(s)</label>' +
                      '<input type="file" class="attach-upload-input" data-id="' + rowId + '" ' +
                             'accept=".jpg,.jpeg,.pdf,.xls,.xlsx" multiple>' +
                      '<span class="attach-hint">Allowed: JPG, JPEG, PDF, XLS, XLSX · Max 10 MB per file · Multiple files supported.</span>' +
                      '<button type="button" class="btn-upload" data-id="' + rowId + '">' +
                        '<i class="fa-solid fa-upload"></i> Upload' +
                      '</button>' +
                      '<span class="upload-status"></span>' +
                    '</div>';
            }

            function openRowModal(raw) {
                var d;
                try { d = JSON.parse(raw); } catch(e) { console.error(e); return; }

                $('#modalBpRef').text(d.bp_reference_no || '—');
                var statusUpper = (d.status || '').toUpperCase();
                var statusClass = 'badge-status-default';
                if (statusUpper === 'OPEN') statusClass = 'badge-status-open';
                else if (statusUpper === 'CLOSED') statusClass = 'badge-status-closed';
                $('#modalStatus').html('<span class="badge ' + statusClass + '" id="modalStatusBadge">' + esc(statusUpper || '—') + '</span>');

                var html = '';

                // ---- Lock editing when the record is CLOSED ----
                var isClosed = (statusUpper === 'CLOSED');

                var txnGrid = '<div class="cad-grid">';
                txnGrid += field('Payment Date', d.payment_date);
                txnGrid += field('Cancelled Date', d.cancelled_date);
                txnGrid += field('Account No', d.account_no, 'mono');
                txnGrid += field('Account Name', d.account_name);
                txnGrid += field('Amount Paid', d.amount_paid ? '₱ ' + d.amount_paid : '', 'mono');
                txnGrid += field('Correct Amount', d.correct_amount ? '₱ ' + d.correct_amount : '', 'mono');
                txnGrid += field('Region', d.region);
                txnGrid += field('Branch', d.branch_id + (d.branch_name ? ' - ' + d.branch_name : ''));
                txnGrid += field('IR No', d.ir_no, 'mono');
                txnGrid += field('Closed At', d.closed_at, 'mono');
                txnGrid += field('Real Cancelled Date (Transaction Closed Date)', d.real_cancelled_date, 'mono');
                txnGrid += field('Closed By', d.closed_by, 'mono');
                txnGrid += '</div>';
                html += section('Transaction Information', 'fa-receipt', txnGrid);

                var billerGrid = '<div class="cad-grid">';
                billerGrid += field('Partner', d.original_biller, 'full');
                billerGrid += field('Correct Partner', d.correct_biller, 'full');
                billerGrid += '</div>';
                html += section('Partner Information', 'fa-building', billerGrid);

                var clsGrid = '<div class="cad-grid">';
                clsGrid += field('Reason', d.reason);
                clsGrid += field('Fault', d.fault);
                clsGrid += field('Partner Action', d.partner_action, 'full');
                clsGrid += '</div>';
                html += section('Classification', 'fa-tags', clsGrid);

                // Status editor — locked when CLOSED
                if (isClosed) {
                    var statusInner = '<div class="cad-grid">' +
                        '<div class="field full">' +
                          '<div class="cad-edit-block">' +
                            '<label><i class="fa-solid fa-circle-check"></i> Status ' +
                              '<span class="lock-icon" title="Locked"><i class="fa-solid fa-lock"></i></span>' +
                            '</label>' +
                            '<div class="readonly-remark">CLOSED — this log can no longer be edited.</div>' +
                          '</div>' +
                        '</div>' +
                    '</div>';
                    html += section('Update Status', 'fa-circle-check', statusInner);
                } else {
                    var statusInner = '<div class="cad-grid">' + statusEditorBlock(d.id, d.status) + '</div>';
                    html += section('Update Status', 'fa-circle-check', statusInner);
                }

                var sendToInner = '<div class="cad-grid">' +
                    '<div id="modalSendToWrap" data-id="' + d.id + '">' + sendToReadonlyBlock(d.send_to) + '</div>' +
                '</div>';
                html += section('Send To', 'fa-paper-plane', sendToInner);

                var canEditVpo = !!(window.CAD_PERMS && window.CAD_PERMS.canEditVpo);
                var canEditCad = !!(window.CAD_PERMS && window.CAD_PERMS.canEditCad);

                // Force read-only when CLOSED
                var remarksInner = '<div class="cad-grid">';
                remarksInner += (canEditVpo && !isClosed)
                    ? editableBlock('VPO Remarks', 'vpo_remarks', d.id, d.vpo_remarks, 'fa-comment-dots')
                    : readonlyBlock('VPO Remarks', d.vpo_remarks, 'fa-comment-dots');
                remarksInner += (canEditCad && !isClosed)
                    ? editableBlock('CAD Remarks', 'cad_remarks', d.id, d.cad_remarks, 'fa-clipboard-check')
                    : readonlyBlock('CAD Remarks', d.cad_remarks, 'fa-clipboard-check');
                remarksInner += '</div>';
                html += section('Remarks (clear remarks field to add new)', 'fa-pen-to-square', remarksInner);

                html += section(
                    'Remarks History',
                    'fa-clock-rotate-left',
                    '<div id="remarksHistoryThread" class="cad-thread" data-bp-ref="' + esc(d.bp_reference_no) + '">' +
                      '<div class="thread-loading"><i class="fa-solid fa-spinner fa-spin"></i> Loading history…</div>' +
                    '</div>'
                );

                var attachInner = '';
                if (d.attachments && d.attachments.length) {
                    attachInner += '<div class="cad-attach-grid">';
                    d.attachments.forEach(function(f) {
                        var meta = fileIconClass(f);
                        var url = '<?= e(CAD_ATTACHMENT_REL_PATH) ?>' + encodeURIComponent(f);
                        attachInner +=
                            '<a class="cad-attach-card" target="_blank" href="' + url + '">' +
                              '<div class="att-icon ' + meta.cls + '"><i class="fa-solid ' + meta.ic + '"></i></div>' +
                              '<div class="att-meta">' +
                                '<div class="att-name">' + esc(f) + '</div>' +
                                '<div class="att-hint">Click to open</div>' +
                              '</div>' +
                            '</a>';
                    });
                    attachInner += '</div>';
                } else {
                    attachInner = '<div style="color:#9ca3af; font-size:13px;">No attachments uploaded.</div>';
                }

                // Uploader — only when NOT closed
                if (!isClosed) {
                    attachInner += attachmentUploaderBlock(d.id);
                } else {
                    attachInner += '<div class="attach-locked-note">' +
                        '<i class="fa-solid fa-lock"></i> This record is CLOSED — attachments are locked.' +
                    '</div>';
                }
                html += section('Attachments', 'fa-paperclip', attachInner);

                var metaGrid = '<div class="cad-grid">';
                metaGrid += field('Requested Date', d.requested_date);
                metaGrid += field('Transacted By', d.transacted_by);
                metaGrid += '</div>';
                html += section('Transaction History', 'fa-clock-rotate-left', metaGrid);

                $('#rowModalBody').html(html);
                $('#rowModal').addClass('open');
                $('#rowModalBody').scrollTop(0);

                loadRemarksHistory(d.bp_reference_no);
            }

            $(document).on('dblclick', 'tr.cad-row', function(e) {
                if ($(e.target).closest('a, button, input, select, textarea, label').length) return;
                var raw = $(this).attr('data-row');
                if (raw) openRowModal(raw);
            });

            function refreshModalSendTo(newVal) {
                var $wrap = $('#modalSendToWrap');
                if (!$wrap.length) return;
                var badgeCls = 'badge-sendto-default';
                if (newVal === 'VPO') badgeCls = 'badge-sendto-vpo';
                else if (newVal === 'CAD - Bills Payment') badgeCls = 'badge-sendto-cad';
                var badge = (newVal === '' || newVal == null)
                    ? '<span style="color:#9ca3af;">—</span>'
                    : '<span class="badge ' + badgeCls + '">' + esc(newVal) + '</span>';
                $wrap.html(
                    '<div class="field full">' +
                      '<div class="cad-edit-block">' +
                        '<label><i class="fa-solid fa-paper-plane"></i> Sent To ' +
                          '<span class="lock-icon" title="Auto-routed"><i class="fa-solid fa-lock"></i></span>' +
                        '</label>' +
                        '<div class="sendto-readonly">' + badge + '</div>' +
                      '</div>' +
                    '</div>'
                );
            }

            function refreshTableSendTo(rowId, newVal) {
                var cls = 'badge-sendto-default';
                if (newVal === 'VPO') cls = 'badge-sendto-vpo';
                else if (newVal === 'CAD - Bills Payment') cls = 'badge-sendto-cad';
                var inner = (newVal === '' || newVal == null)
                    ? '<span class="badge badge-none">—</span>'
                    : '<span class="badge ' + cls + '">' + esc(newVal) + '</span>';
                $('.sendto-cell-' + rowId).html(inner);
            }

            function loadRemarksHistory(bpRef) {
                var $wrap = $('#remarksHistoryThread');
                if (!$wrap.length || !bpRef) return;

                $.ajax({
                    url: '?action=get_remarks_history',
                    type: 'GET',
                    dataType: 'json',
                    data: { bp_ref: bpRef },
                    success: function(res) {
                        if (!res || !res.success) {
                            $wrap.html('<div class="thread-empty">' +
                                '<i class="fa-solid fa-triangle-exclamation"></i> ' +
                                esc(res && res.message ? res.message : 'Unable to load history.') +
                                '</div>');
                            return;
                        }

                        var items = res.items || [];
                        if (!items.length) {
                            $wrap.html('<div class="thread-empty">' +
                                '<i class="fa-regular fa-comment-dots"></i> No remarks history yet.' +
                                '</div>');
                            return;
                        }

                        var html = '';
                        items.forEach(function(it) {
                            var src     = (it.source || '').toUpperCase();
                            var srcCls  = (src === 'VPO') ? 'vpo' : 'cad';
                            var srcLbl  = (src === 'VPO') ? 'VPO Remarks' : 'CAD Remarks';
                            var srcIcon = (src === 'VPO') ? 'fa-comment-dots' : 'fa-clipboard-check';

                            var rawDate = it.remarked_at || '';
                            var when = rawDate;
                            try {
                                var dt = new Date(rawDate.replace(' ', 'T'));
                                if (!isNaN(dt.getTime())) {
                                    when = dt.toLocaleString('en-US', {
                                        year: 'numeric', month: 'short', day: '2-digit',
                                        hour: '2-digit', minute: '2-digit', second: '2-digit',
                                        hour12: true
                                    });
                                }
                            } catch(e) {}

                            html +=
                                '<div class="thread-item ' + srcCls + '">' +
                                  '<div class="thread-icon"><i class="fa-solid ' + srcIcon + '"></i></div>' +
                                  '<div class="thread-body">' +
                                    '<div class="thread-meta">' +
                                      '<span class="thread-badge ' + srcCls + '">' + esc(srcLbl) + '</span>' +
                                      '<span class="thread-by"><i class="fa-solid fa-user"></i> ' + esc(it.remarked_by || '—') + '</span>' +
                                      '<span class="thread-when"><i class="fa-regular fa-clock"></i> ' + esc(when) + '</span>' +
                                    '</div>' +
                                    '<div class="thread-text">' + esc(it.remarks || '—') + '</div>' +
                                  '</div>' +
                                '</div>';
                        });

                        $wrap.html(html);
                    },
                    error: function() {
                        $wrap.html('<div class="thread-empty">' +
                            '<i class="fa-solid fa-triangle-exclamation"></i> Failed to load history.' +
                            '</div>');
                    }
                });
            }

            $(document).on('click', '.btn-save-small', function() {
                var $btn       = $(this);
                var field      = $btn.data('field');
                var rowId      = $btn.data('id');
                var $block     = $btn.closest('.cad-edit-block');
                var $textarea  = $block.find('textarea');
                var $status    = $block.find('.save-status');
                var value      = $textarea.val();

                $btn.prop('disabled', true);
                $status.removeClass('ok error').text('Saving...');

                $.ajax({
                    url: '?action=update_remarks',
                    type: 'POST',
                    dataType: 'json',
                    data: { id: rowId, field: field, value: value },
                    success: function(res) {
                        $btn.prop('disabled', false);
                        if (res && res.success) {
                            $status.addClass('ok').text('Saved.');
                            if (typeof res.value === 'string') {
                                $textarea.val(res.value);
                            }

                            var $row = $('tr.cad-row[data-row*=\'"id":' + rowId + '\']');
                            $row.each(function() {
                                try {
                                    var obj = JSON.parse($(this).attr('data-row'));
                                    if (obj.id == rowId) {
                                        obj[field] = res.value;
                                        if (res.send_to) obj.send_to = res.send_to;
                                        $(this).attr('data-row', JSON.stringify(obj));
                                    }
                                } catch(e) {}
                            });

                            if (res.send_to) {
                                refreshModalSendTo(res.send_to);
                                refreshTableSendTo(rowId, res.send_to);
                            }

                            var bpRef = $('#remarksHistoryThread').data('bp-ref') || '';
                            if (bpRef) { loadRemarksHistory(bpRef); }

                            setTimeout(function() { $status.text(''); }, 1500);
                        } else {
                            $status.addClass('error').text(res && res.message ? res.message : 'Failed.');
                        }
                    },
                    error: function(xhr) {
                        $btn.prop('disabled', false);
                        var msg = 'Request failed.';
                        try { msg = JSON.parse(xhr.responseText).message || msg; } catch(e) {}
                        $status.addClass('error').text(msg);
                    }
                });
            });

            $(document).on('click', '.btn-save-status', function() {
                var $btn     = $(this);
                var rowId    = $btn.data('id');
                var $block   = $btn.closest('.status-editor');
                var $select  = $block.find('.status-select');
                var $status  = $block.find('.save-status');
                var newVal   = $select.val();

                $btn.prop('disabled', true);
                $status.removeClass('ok error').text('Saving...');

                $.ajax({
                    url: '?action=update_status',
                    type: 'POST',
                    dataType: 'json',
                    data: { id: rowId, status: newVal },
                    success: function(res) {
                        $btn.prop('disabled', false);
                        if (res && res.success) {
                            $status.addClass('ok').text('Saved.');

                            var statusUpper = (res.value || '').toUpperCase();
                            var statusClass = 'badge-status-default';
                            if (statusUpper === 'OPEN') statusClass = 'badge-status-open';
                            else if (statusUpper === 'CLOSED') statusClass = 'badge-status-closed';
                            $('#modalStatusBadge')
                                .attr('class', 'badge ' + statusClass)
                                .text(statusUpper);

                            function fmtDT(s) {
                                if (!s) return '';
                                try {
                                    var dt = new Date(s.replace(' ', 'T'));
                                    if (isNaN(dt.getTime())) return s;
                                    return dt.toLocaleString('en-US', {
                                        year:'numeric', month:'2-digit', day:'2-digit',
                                        hour:'2-digit', minute:'2-digit', second:'2-digit', hour12:true
                                    });
                                } catch(e) { return s; }
                            }

                            var $row = $('tr.cad-row[data-row*=\'"id":' + rowId + '\']');
                            $row.each(function() {
                                try {
                                    var obj = JSON.parse($(this).attr('data-row'));
                                    if (obj.id == rowId) {
                                        obj.status = res.value;
                                        if (res.send_to) obj.send_to = res.send_to;
                                        if (typeof res.closed_at !== 'undefined') {
                                            obj.closed_at = res.closed_at ? fmtDT(res.closed_at) : '';
                                        }
                                        if (typeof res.real_cancelled_date !== 'undefined') {
                                            obj.real_cancelled_date = res.real_cancelled_date ? fmtDT(res.real_cancelled_date) : '';
                                        }
                                        if (typeof res.closed_by !== 'undefined') {
                                            obj.closed_by = res.closed_by || '';
                                        }
                                        $(this).attr('data-row', JSON.stringify(obj));
                                    }
                                } catch(e) {}
                            });

                            var statusUpper2 = (res.value || '').toUpperCase();
                            var cellClass = 'badge-status-default';
                            if (statusUpper2 === 'OPEN') cellClass = 'badge-status-open';
                            else if (statusUpper2 === 'CLOSED') cellClass = 'badge-status-closed';
                            $('.status-cell-' + rowId).html('<span class="badge ' + cellClass + '">' + statusUpper2 + '</span>');

                            if (res.send_to) {
                                refreshModalSendTo(res.send_to);
                                refreshTableSendTo(rowId, res.send_to);
                            }

                            var $modalBody = $('#rowModalBody');
                            if ($modalBody.length && $modalBody.find('.cad-section').length) {
                                var $fields = $modalBody.find('.cad-section:first .field');
                                $fields.each(function() {
                                    var $f = $(this);
                                    var lbl = $f.find('.lbl').text().trim();
                                    if (lbl === 'Closed At') {
                                        var v = res.closed_at ? fmtDT(res.closed_at) : '—';
                                        $f.find('.val').text(v);
                                        $f.toggleClass('empty', !res.closed_at);
                                    } else if (lbl === 'Real Cancelled Date (Transaction Closed Date)') {
                                        var v2 = res.real_cancelled_date ? fmtDT(res.real_cancelled_date) : '—';
                                        $f.find('.val').text(v2);
                                        $f.toggleClass('empty', !res.real_cancelled_date);
                                    } else if (lbl === 'Closed By') {
                                        var v3 = res.closed_by || '—';
                                        $f.find('.val').text(v3);
                                        $f.toggleClass('empty', !res.closed_by);
                                    }
                                });
                            }

                            // If just set to CLOSED → close the modal and reload so the
                            // record locks everywhere (the row becomes read-only next open).
                            if (statusUpper === 'CLOSED') {
                                setTimeout(function() {
                                    $('#rowModal').removeClass('open');
                                    Swal.fire({
                                        icon: 'info',
                                        title: 'Record Closed',
                                        text: 'This record is now CLOSED. It can no longer be edited.',
                                        timer: 1800,
                                        showConfirmButton: false
                                    }).then(function() {
                                        // Refresh the page so the row reflects the locked state
                                        window.location.reload();
                                    });
                                }, 800);
                                return;
                            }

                            setTimeout(function() { $status.text(''); }, 1500);
                        } else {
                            $status.addClass('error').text(res && res.message ? res.message : 'Failed.');
                        }
                    },
                    error: function(xhr) {
                        $btn.prop('disabled', false);
                        var msg = 'Request failed.';
                        try { msg = JSON.parse(xhr.responseText).message || msg; } catch(e) {}
                        $status.addClass('error').text(msg);
                    }
                });
            });

            $(document).on('click', '.btn-upload', function() {
                var $btn       = $(this);
                var rowId      = $btn.data('id');
                var $uploader  = $btn.closest('.attach-uploader');
                var $input     = $uploader.find('.attach-upload-input');
                var $status    = $uploader.find('.upload-status');
                var files      = $input[0].files;

                if (!files || !files.length) {
                    $status.addClass('error').removeClass('ok').text('Please select at least one file.');
                    return;
                }

                var fd = new FormData();
                fd.append('id', rowId);
                for (var i = 0; i < files.length; i++) {
                    fd.append('attachments[]', files[i]);
                }

                $btn.prop('disabled', true);
                $status.removeClass('ok error').text('Uploading...');

                $.ajax({
                    url: '?action=upload_attachments',
                    type: 'POST',
                    data: fd,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: function(res) {
                        $btn.prop('disabled', false);
                        if (res && res.success) {
                            $status.addClass('ok').text(res.message || 'Uploaded.');
                            $input.val('');

                            var $section = $uploader.closest('.cad-section');
                            var $grid = $section.find('.cad-attach-grid');
                            if (!$grid.length) {
                                $grid = $('<div class="cad-attach-grid"></div>');
                                $section.find('.attach-uploader').before($grid);
                            }
                            $section.find('div').filter(function() {
                                return $(this).text().trim() === 'No attachments uploaded.' && $(this).css('color') === 'rgb(156, 163, 175)';
                            }).remove();

                            (res.files || []).forEach(function(f) {
                                var meta = fileIconClass(f);
                                var url = '<?= e(CAD_ATTACHMENT_REL_PATH) ?>' + encodeURIComponent(f);
                                $grid.append(
                                    '<a class="cad-attach-card" target="_blank" href="' + url + '">' +
                                      '<div class="att-icon ' + meta.cls + '"><i class="fa-solid ' + meta.ic + '"></i></div>' +
                                      '<div class="att-meta">' +
                                        '<div class="att-name">' + esc(f) + '</div>' +
                                        '<div class="att-hint">Click to open</div>' +
                                      '</div>' +
                                    '</a>'
                                );
                            });

                            var $row = $('tr.cad-row[data-row*=\'"id":' + rowId + '\']');
                            $row.each(function() {
                                try {
                                    var obj = JSON.parse($(this).attr('data-row'));
                                    if (obj.id == rowId) {
                                        obj.attachments = res.all_files || obj.attachments;
                                        $(this).attr('data-row', JSON.stringify(obj));
                                    }
                                } catch(e) {}
                            });

                            if (res.warnings && res.warnings.length) {
                                $status.append(' (Some skipped: ' + res.warnings.join('; ') + ')');
                            }
                            setTimeout(function() { $status.text(''); }, 2500);
                        } else {
                            $status.addClass('error').text(res && res.message ? res.message : 'Upload failed.');
                        }
                    },
                    error: function(xhr) {
                        $btn.prop('disabled', false);
                        var msg = 'Upload failed.';
                        try { msg = JSON.parse(xhr.responseText).message || msg; } catch(e) {}
                        $status.addClass('error').text(msg);
                    }
                });
            });

            $(document).on('click', '.close-modal', function() {
                $('#rowModal').removeClass('open');
            });
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape') $('#rowModal').removeClass('open');
            });
        });
    </script>
</body>
</html>