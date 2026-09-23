<?php
// cad_loggings.php
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../vendor/autoload.php';

date_default_timezone_set('Asia/Manila');

session_start();
@include_once __DIR__ . '/../../../templates/middleware.php';
$id = resolve_user_identifier();
if (empty($id)) { header('Location: ../../../login_form.php'); exit; }
if (!function_exists('has_any_permission') || !has_any_permission(['CAD Loggings', 'VPO'])) { header('Location: ../../home.php'); exit; }

$current_user_email = $_SESSION['admin_email'] ?? $_SESSION['user_email'] ?? '';

// ---- Attachment config ----
define('CAD_ATTACHMENT_DIR', 'C:\\xampp\\htdocs\\BillsPayment\\dashboard\\cad-loggings\\cad_loggings_attachments');
define('CAD_ATTACHMENT_ALLOWED_EXT', ['jpg', 'jpeg', 'pdf', 'xls', 'xlsx']);
define('CAD_ATTACHMENT_MAX_BYTES', 10 * 1024 * 1024); // 10 MB per file

// ---- Current user ----
$current_transacted_by = strtoupper(trim(
    $_SESSION['admin_name'] ?? $_SESSION['user_name'] ?? 'SYSTEM'
));

// ---- Today's date ----
$today_date = date('Y-m-d');

// ---- Permission flags ----
$has_vpo_perm = function_exists('has_any_permission') && has_any_permission(['VPO']);
$has_cad_perm = function_exists('has_any_permission') && has_any_permission(['CAD Loggings']);

// ---- User Type label ----
if ($has_vpo_perm && $has_cad_perm) {
    $user_type_label = 'VPO / CAD - Bills Payment';
} elseif ($has_vpo_perm) {
    $user_type_label = 'VPO';
} elseif ($has_cad_perm) {
    $user_type_label = 'CAD - Bills Payment';
} else {
    $user_type_label = 'GUEST';
}

// ---- Routing ----
if ($has_vpo_perm && $has_cad_perm) {
    $send_to = 'VPO';
} elseif ($has_vpo_perm) {
    $send_to = 'CAD - Bills Payment';
} elseif ($has_cad_perm) {
    $send_to = 'VPO';
} else {
    $send_to = '';
}

// ---- Handle form POST ----
$flash_success = $_SESSION['cad_flash_success'] ?? '';
$flash_error   = $_SESSION['cad_flash_error']   ?? '';
unset($_SESSION['cad_flash_success'], $_SESSION['cad_flash_error']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bp_ref_no'])) {
    $payment_date      = trim($_POST['date_of_payment']        ?? '');
    $cancelled_date    = trim($_POST['date_cancelled']         ?? '');
    $region            = strtoupper(trim($_POST['regionname']          ?? ''));
    $branch_id         = trim($_POST['branchname']                     ?? '');
    $original_biller_id   = trim($_POST['original_biller']                   ?? '');
    $bp_reference      = strtoupper(trim($_POST['bp_ref_no']           ?? ''));
    $account_no        = strtoupper(trim($_POST['account_no']          ?? ''));
    $account_name      = strtoupper(trim($_POST['account_name']        ?? ''));
    $amount_paid       = $_POST['amount'] ?? '';
    $reason            = strtoupper(trim($_POST['reason']              ?? ''));
    $fault             = strtoupper(trim($_POST['fault']               ?? ''));
    $partner_action    = strtoupper(trim($_POST['partner_action']      ?? ''));
    $ir_no             = strtoupper(trim($_POST['irno']                ?? ''));
    $vpo_remarks       = strtoupper(trim($_POST['vpo_remarks']         ?? ''));
    $cad_remarks       = strtoupper(trim($_POST['cad_status_remarks']  ?? ''));
    $requested_date    = date('Y-m-d H:i:s');

    $status            = 'OPEN';

    $correct_biller_id = '';
    $correct_biller    = '';
    $correct_amount    = null;

    if ($reason === 'WRONG BILLER') {
        $correct_biller_id = trim($_POST['correct_biller'] ?? '');
    } elseif ($reason === 'WRONG AMOUNT') {
        $correct_amount_raw = $_POST['correct_amount'] ?? '';
        if ($correct_amount_raw !== '') {
            $correct_amount = abs((float)$correct_amount_raw);
        }
    }

    $transacted_by = $current_transacted_by;

    if (!$has_vpo_perm) {
        $vpo_remarks = '';
    }
    if (!$has_cad_perm) {
        $partner_action = '';
        $cad_remarks    = '';
    }

    if ($payment_date === '' || $bp_reference === '' || $account_no === '' ||
        $account_name === '' || $amount_paid === '' || $reason === '' || $fault === '') {
        $_SESSION['cad_flash_error'] = 'Please fill in all required fields.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    if ($reason === 'WRONG BILLER' && $correct_biller_id === '') {
        $_SESSION['cad_flash_error'] = 'Please select a Correct Partner for "Wrong Partner" reason.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    if ($reason === 'WRONG AMOUNT' && ($correct_amount === null)) {
        $_SESSION['cad_flash_error'] = 'Please enter a Correct Amount for "Wrong Amount" reason.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    if ($has_cad_perm && $partner_action === '') {
        $_SESSION['cad_flash_error'] = 'Please select a Partner Action.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    if ($partner_action !== '' &&
        !in_array($partner_action, ['PARTNER ALLOWED CANCELLATION', 'PARTNER NOT ALLOWED CANCELLATION'], true)) {
        $_SESSION['cad_flash_error'] = 'Invalid Partner Action value.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    $sqlDup = "SELECT id FROM cad_loggings.cad_loggings_requests WHERE bp_reference_no = ? LIMIT 1";
    if ($stmtDup = $conn->prepare($sqlDup)) {
        $stmtDup->bind_param('s', $bp_reference);
        $stmtDup->execute();
        $resDup = $stmtDup->get_result();
        $isDup  = ($resDup->num_rows > 0);
        $stmtDup->close();

        if ($isDup) {
            $_SESSION['cad_flash_error'] = 'This BP Ref. No. (' . $bp_reference . ') has already been logged.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
    }

    $branch_name = $branch_id;
    if ($branch_id !== '') {
        $sqlBranch = "SELECT ml_matic_branch_name
                      FROM masterdata.branch_profile
                      WHERE branch_id = ?
                      LIMIT 1";
        if ($stmtB = $conn->prepare($sqlBranch)) {
            $stmtB->bind_param('s', $branch_id);
            $stmtB->execute();
            $resB = $stmtB->get_result();
            if ($rowB = $resB->fetch_assoc()) {
                $branch_name = strtoupper(trim($rowB['ml_matic_branch_name']));
            }
            $stmtB->close();
        }
    }

    $original_biller = $original_biller_id;
    if ($original_biller_id !== '') {
        $sqlP = "SELECT partner_name
                 FROM masterdata.partner_masterfile
                 WHERE partner_id_kpx = ?
                 LIMIT 1";
        if ($stmtP = $conn->prepare($sqlP)) {
            $stmtP->bind_param('s', $original_biller_id);
            $stmtP->execute();
            $resP = $stmtP->get_result();
            if ($rowP = $resP->fetch_assoc()) {
                $original_biller = strtoupper(trim($rowP['partner_name']));
            }
            $stmtP->close();
        }
    }

    if ($correct_biller_id !== '') {
        $sqlP2 = "SELECT partner_name
                  FROM masterdata.partner_masterfile
                  WHERE partner_id_kpx = ?
                  LIMIT 1";
        if ($stmtP2 = $conn->prepare($sqlP2)) {
            $stmtP2->bind_param('s', $correct_biller_id);
            $stmtP2->execute();
            $resP2 = $stmtP2->get_result();
            if ($rowP2 = $resP2->fetch_assoc()) {
                $correct_biller = strtoupper(trim($rowP2['partner_name']));
            }
            $stmtP2->close();
        }
    }

    $amount_paid = abs((float)$amount_paid);
    $cancelled_date_val = ($cancelled_date === '') ? null : $cancelled_date;
    $partner_action_val = ($partner_action === '') ? null : $partner_action;

    // ---- Handle file uploads ----
    $uploaded_filenames = [];

    if (!is_dir(CAD_ATTACHMENT_DIR)) {
        @mkdir(CAD_ATTACHMENT_DIR, 0775, true);
    }

    if (!empty($_FILES['attachments']['name'][0])) {
        $file_count = count($_FILES['attachments']['name']);

        for ($i = 0; $i < $file_count; $i++) {
            $origName = $_FILES['attachments']['name'][$i];
            $tmpPath  = $_FILES['attachments']['tmp_name'][$i];
            $errCode  = $_FILES['attachments']['error'][$i];
            $size     = $_FILES['attachments']['size'][$i];

            if ($errCode !== UPLOAD_ERR_OK) continue;
            if ($size > CAD_ATTACHMENT_MAX_BYTES) continue;

            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            if (!in_array($ext, CAD_ATTACHMENT_ALLOWED_EXT, true)) continue;

            $baseName = pathinfo($origName, PATHINFO_FILENAME);
            $safeBase = preg_replace('/\s+/', '_', $baseName);
            $safeBase = preg_replace('/[^A-Za-z0-9._-]/', '', $safeBase);
            if ($safeBase === '') $safeBase = 'file';

            $safeRef  = preg_replace('/[^A-Za-z0-9._-]/', '', $bp_reference);
            $finalName = $safeRef . '_' . $safeBase . '.' . $ext;
            $destPath = CAD_ATTACHMENT_DIR . DIRECTORY_SEPARATOR . $finalName;

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
            }
        }
    }

    $attachment_files_str = !empty($uploaded_filenames)
        ? implode(',', $uploaded_filenames)
        : null;

    $sql = "INSERT INTO cad_loggings.cad_loggings_requests (
                payment_date,
                cancelled_date,
                region,
                branch_id,
                branch_name,
                original_biller_id,
                original_biller,
                correct_biller_id,
                correct_biller,
                bp_reference_no,
                account_no,
                account_name,
                amount_paid,
                correct_amount,
                ir_no,
                reason,
                fault,
                partner_action,
                attachment_files,
                vpo_remarks,
                cad_remarks,
                requested_date,
                transacted_by,
                user_type,
                status,
                send_to
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    try {
        $conn->begin_transaction();

        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param(
                'ssssssssssssddssssssssssss',
                $payment_date,
                $cancelled_date_val,
                $region,
                $branch_id,
                $branch_name,
                $original_biller_id,
                $original_biller,
                $correct_biller_id,
                $correct_biller,
                $bp_reference,
                $account_no,
                $account_name,
                $amount_paid,
                $correct_amount,
                $ir_no,
                $reason,
                $fault,
                $partner_action_val,
                $attachment_files_str,
                $vpo_remarks,
                $cad_remarks,
                $requested_date,
                $transacted_by,
                $user_type_label,
                $status,
                $send_to
            );

            if (!$stmt->execute()) {
                throw new Exception('Execute failed: ' . $stmt->error);
            }
            $stmt->close();

            if ($has_vpo_perm && $vpo_remarks !== '') {
                $sqlVpoHist = "INSERT INTO cad_loggings.vpo_remarks_history
                               (bp_reference_no, vpo_remarks, remarked_by, remarked_at)
                               VALUES (?, ?, ?, ?)";
                if ($stmtVpo = $conn->prepare($sqlVpoHist)) {
                    $stmtVpo->bind_param('ssss', $bp_reference, $vpo_remarks, $transacted_by, $requested_date);
                    if (!$stmtVpo->execute()) {
                        throw new Exception('VPO remarks history insert failed: ' . $stmtVpo->error);
                    }
                    $stmtVpo->close();
                } else {
                    throw new Exception('VPO remarks history prepare failed: ' . $conn->error);
                }
            }

            if ($has_cad_perm && $cad_remarks !== '') {
                $sqlCadHist = "INSERT INTO cad_loggings.cad_remarks_history
                               (bp_reference_no, cad_remarks, remarked_by, remarked_at)
                               VALUES (?, ?, ?, ?)";
                if ($stmtCad = $conn->prepare($sqlCadHist)) {
                    $stmtCad->bind_param('ssss', $bp_reference, $cad_remarks, $transacted_by, $requested_date);
                    if (!$stmtCad->execute()) {
                        throw new Exception('CAD remarks history insert failed: ' . $stmtCad->error);
                    }
                    $stmtCad->close();
                } else {
                    throw new Exception('CAD remarks history prepare failed: ' . $conn->error);
                }
            }

            $conn->commit();
            $_SESSION['cad_flash_success'] = 'CAD log saved successfully. Sent to: ' . ($send_to !== '' ? $send_to : '—');
        } else {
            throw new Exception('Failed to prepare statement: ' . $conn->error);
        }
    } catch (Exception $e) {
        $conn->rollback();
        error_log('cad_loggings insert error: ' . $e->getMessage());
        $_SESSION['cad_flash_error'] = 'INSERT error: ' . $e->getMessage();
    }

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// ---- Fetch dropdown data ----
$regions = [];
$branches = [];
$billers = [];

try {
    $sql = "SELECT DISTINCT gl_region FROM masterdata.branch_profile WHERE gl_region IS NOT NULL AND gl_region <> '' ORDER BY gl_region ASC";
    if ($result = $conn->query($sql)) {
        while ($row = $result->fetch_assoc()) { $regions[] = $row['gl_region']; }
        $result->free();
    }

    $sql = "SELECT DISTINCT branch_id, ml_matic_branch_name, branch_type, gl_region FROM masterdata.branch_profile WHERE branch_type in ('Showroom', 'Branch') ORDER BY ml_matic_branch_name ASC";
    if ($result = $conn->query($sql)) {
        while ($row = $result->fetch_assoc()) { $branches[] = $row; }
        $result->free();
    }

    $sql = "SELECT DISTINCT partner_id_kpx, partner_name FROM masterdata.partner_masterfile WHERE partner_id_kpx IS NOT NULL AND partner_id_kpx <> '' ORDER BY partner_name ASC";
    if ($result = $conn->query($sql)) {
        while ($row = $result->fetch_assoc()) { $billers[] = $row; }
        $result->free();
    }
} catch (Exception $e) {
    error_log('CAD Loggings dropdown fetch error: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CAD Loggings | <?php if($_SESSION['user_type'] === 'admin' || $_SESSION['user_type'] === 'user') echo ucfirst($_SESSION['user_type']); else echo "Guest";?></title>
    <link rel="stylesheet" href="../../../assets/css/templates/style.css?v=<?php echo time(); ?>">
    <script src="https://kit.fontawesome.com/30b908cc5a.js" crossorigin="anonymous"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="../../../assets/js/sweetalert2.all.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet" />
    <link rel="icon" href="../../../images/MLW logo.png" type="image/png">
    <link rel="stylesheet" href="cad.css?v=<?= time(); ?>">
</head>
<body>
    <div class="main-container">
        <?php include '../../../templates/header_ui.php'; ?>
        <?php include '../../../templates/sidebar.php'; ?>

        <div class="bp-section-header" role="region" aria-label="Page title">
            <div class="bp-section-title">
                <i class="fa-solid fa-layer-group" aria-hidden="true"></i>
                <div><h2>CAD Loggings</h2></div>
            </div>
        </div>

       <div class="cad-form-container">
    <div class="cad-form-card">
        <form id="cadLoggingForm" method="POST" action="" enctype="multipart/form-data">

            <!-- TOP SECTION -->
            <div class="cad-form-grid">
                <div class="cad-form-group bp-ref-wrap">
                    <label for="bp_ref_no">BP Ref. No.:</label>
                    <select id="bp_ref_no" name="bp_ref_no" class="bp-ref-select" required
                            data-placeholder="Type to search or enter BP Ref. No..." style="width:100%;"></select>
                    <i class="fa-solid fa-spinner fa-spin bp-ref-spinner" id="bpRefSpinner"></i>
                </div>

                <div class="cad-form-group">
                    <label for="original_biller">Partner Name:</label>
                    <select id="original_biller" name="original_biller" class="cad-select"
                            data-placeholder="-- Partner ID / Partner Name --" disabled>
                        <option value=""></option>
                        <?php foreach ($billers as $biller): ?>
                            <option value="<?= htmlspecialchars($biller['partner_id_kpx'], ENT_QUOTES) ?>">
                                <?= htmlspecialchars($biller['partner_id_kpx'] . ' - ' . $biller['partner_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="cad-form-group">
                    <label for="account_no">Account No.:</label>
                    <input type="text" id="account_no" name="account_no" placeholder="Account number"
                           class="readonly-input" required disabled>
                </div>

                <div class="cad-form-group">
                    <label for="account_name">Account Name:</label>
                    <input type="text" id="account_name" name="account_name" placeholder="Account name"
                           class="readonly-input" required disabled>
                </div>

                <div class="cad-form-group">
                    <label for="branchname">Branch Name:</label>
                    <select id="branchname" name="branchname" class="cad-select" required
                            data-placeholder="-- Branch (Branch ID / Branch Name) --" disabled>
                        <option value=""></option>
                        <?php foreach ($branches as $branch): ?>
                            <option value="<?= htmlspecialchars($branch['branch_id'], ENT_QUOTES) ?>"
                                    data-region="<?= htmlspecialchars($branch['gl_region'] ?? '', ENT_QUOTES) ?>">
                                <?= htmlspecialchars($branch['branch_id'] . ' - ' . $branch['ml_matic_branch_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="cad-form-group">
                    <label for="regionname">Region Name:</label>
                    <select id="regionname" name="regionname" class="cad-select" required
                            data-placeholder="-- Region --" disabled>
                        <option value=""></option>
                        <?php foreach ($regions as $region): ?>
                            <option value="<?= htmlspecialchars($region, ENT_QUOTES) ?>">
                                <?= htmlspecialchars($region) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="cad-form-group">
                    <label for="date_of_payment">Transaction Date</label>
                    <input type="date" id="date_of_payment" name="date_of_payment"
                           class="readonly-input" required disabled>
                </div>

                <div class="cad-form-group">
                    <label for="date_cancelled">Date Cancelled:</label>
                    <input type="date" id="date_cancelled" name="date_cancelled"
                           class="readonly-input" disabled>
                </div>

                <div class="cad-form-group">
                    <label for="amount">Amount:</label>
                    <input type="number" id="amount" name="amount" step="0.01" min="0" placeholder="0.00"
                           class="readonly-input" required disabled>
                </div>
            </div>

            <!-- LOCKABLE SECTION -->
            <div class="cad-form-grid" id="lockableSection" style="margin-top: 15px;">

                <div class="cad-form-group">
                    <label for="reason">Reason:</label>
                    <select id="reason" name="reason" class="cad-select" required
                            data-placeholder="-- Select Reason --">
                        <option value=""></option>
                        <option value="WRONG BILLER">Wrong Partner</option>
                        <option value="WRONG AMOUNT">Wrong Amount</option>
                    </select>
                </div>

                <div class="cad-form-group reason-dependent" id="wrapCorrectBiller">
                    <label for="correct_biller">Correct Partner:</label>
                    <select id="correct_biller" name="correct_biller" class="cad-select"
                            data-placeholder="-- Select Partner (Partner ID / Partner Name) --">
                        <option value=""></option>
                        <?php foreach ($billers as $biller): ?>
                            <option value="<?= htmlspecialchars($biller['partner_id_kpx'], ENT_QUOTES) ?>">
                                <?= htmlspecialchars($biller['partner_id_kpx'] . ' - ' . $biller['partner_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="cad-form-group reason-dependent" id="wrapCorrectAmount">
                    <label for="correct_amount">Correct Amount:</label>
                    <input type="number" id="correct_amount" name="correct_amount"
                           step="0.01" min="0" placeholder="0.00" style="width:100%;">
                </div>

                <div class="cad-form-group">
                    <label for="fault">Fault:</label>
                    <select id="fault" name="fault" class="cad-select" required
                            data-placeholder="-- Select Fault --">
                        <option value=""></option>
                        <option value="FLA">FLA</option>
                        <option value="CUSTOMER">Customer</option>
                        <option value="SYSTEM ERROR">System Error</option>
                    </select>
                </div>

                <div class="cad-form-group">
                    <label for="irno">IR No.:</label>
                    <input type="text" id="irno" name="irno" placeholder="Enter IR Number" required>
                </div>

                <!-- ATTACHMENTS -->
                <div class="cad-form-group full-width" style="margin-top: 15px;">
                    <label for="attachments">Supporting Attachment(s) (JPG, PDF, Excel):</label>

                    <input type="file" id="attachments" name="attachments[]"
                           accept=".jpg,.jpeg,.pdf,.xls,.xlsx"
                           multiple
                           style="position:absolute; left:-9999px; top:auto; width:1px; height:1px; opacity:0;" required>

                    <label class="attach-drop-zone" id="attachDropZone" for="attachments">
                        <div class="drop-icon"><i class="fa-solid fa-cloud-arrow-up"></i></div>
                        <div class="drop-text">
                            <strong>Click to browse</strong> or drag &amp; drop files here
                        </div>
                    </label>

                    <small class="attach-hint">
                        Allowed: JPG, JPEG, PDF, XLS, XLSX · Max 10 MB per file · Multiple files supported.
                        Files will be saved as <code>{BP Ref No}_{OriginalFileName}.{ext}</code>.
                    </small>
                    <div class="attach-list" id="attachList"></div>
                </div>

                <div class="cad-form-group full-width">
                    <label for="vpo_remarks">VPO Remarks:
                        <?php if (!$has_vpo_perm): ?>
                            <span class="perm-hint">(VPO only)</span>
                        <?php endif; ?>
                    </label>
                    <textarea id="vpo_remarks" name="vpo_remarks"
                              placeholder="<?= $has_vpo_perm ? 'Enter VPO remarks' : 'View only — VPO permission required' ?>"
                              <?= $has_vpo_perm ? '' : 'readonly' ?>
                              class="<?= $has_vpo_perm ? '' : 'readonly-input' ?>"></textarea>
                </div>

                <div class="cad-form-group">
                    <label for="partner_action">Partner Action:
                        <span id="partnerActionHint" class="perm-hint"></span>
                    </label>
                    <select id="partner_action" name="partner_action"
                            class="cad-select <?= $has_cad_perm ? '' : 'readonly-select' ?>"
                            data-placeholder="-- Select Partner Action --"
                            <?= $has_cad_perm ? '' : 'disabled' ?>>
                        <option value=""></option>
                        <option value="PARTNER ALLOWED CANCELLATION">Partner Allowed Cancellation</option>
                        <option value="PARTNER NOT ALLOWED CANCELLATION">Partner Not Allowed Cancellation</option>
                    </select>
                </div>

                <div class="cad-form-group full-width">
                    <label for="cad_status_remarks">CAD Remarks:
                        <?php if (!$has_cad_perm): ?>
                            <span class="perm-hint">(CAD Bills Payment only)</span>
                        <?php endif; ?>
                    </label>
                    <textarea id="cad_status_remarks" name="cad_status_remarks"
                              placeholder="<?= $has_cad_perm ? 'Enter CAD remarks' : 'View only — CAD permission required' ?>"
                              <?= $has_cad_perm ? '' : 'readonly' ?>
                              class="<?= $has_cad_perm ? '' : 'readonly-input' ?>"></textarea>
                </div>

                <div class="cad-form-group">
                    <label for="logged_date">Logged Date:</label>
                    <input type="date" id="logged_date" name="logged_date" class="readonly-input"
                           value="<?= htmlspecialchars($today_date, ENT_QUOTES) ?>"
                           readonly tabindex="-1">
                </div>

                <div class="cad-form-group">
                    <label for="transacted_by">Logged By:</label>
                    <input type="text" id="transacted_by" class="readonly-input"
                           value="<?= htmlspecialchars($current_transacted_by, ENT_QUOTES) ?>"
                           readonly tabindex="-1">
                </div>

                <div class="cad-form-group">
                    <label for="user_type">User Type:</label>
                    <input type="text" id="user_type" class="readonly-input"
                           value="<?= htmlspecialchars($user_type_label, ENT_QUOTES) ?>"
                           readonly tabindex="-1">
                </div>

                <div class="cad-form-group">
                    <label for="send_to">Send To:</label>
                    <input type="text" id="send_to" class="readonly-input"
                           value="<?= htmlspecialchars($send_to, ENT_QUOTES) ?>"
                           readonly tabindex="-1">
                </div>

            </div>

            <div class="cad-form-actions">
                <button type="reset" class="cad-btn cad-btn-secondary">Clear</button>
                <button type="submit" id="saveLogBtn" class="cad-btn cad-btn-primary">
                    <i class="fa-solid fa-save"></i> Save Log
                </button>
            </div>
        </form>
    </div>
</div>
    </div>

    <?php include '../../../templates/footer.php'; ?>

    <script>
        var CAN_EDIT_VPO = <?= $has_vpo_perm ? 'true' : 'false' ?>;
        var CAN_EDIT_CAD = <?= $has_cad_perm ? 'true' : 'false' ?>;

        $(document).ready(function() {

            // ============================================================
            //  Initialize Select2 on ALL .cad-select elements,
            //  INCLUDING disabled ones — this keeps their width/height
            //  identical to enabled siblings so the grid doesn't stretch.
            // ============================================================
            $('.cad-select').each(function() {
                var $el = $(this);
                var isDisabled = $el.prop('disabled');

                $el.select2({
                    width: '100%',
                    placeholder: $el.data('placeholder') || '-- Select --',
                    allowClear: true,
                    disabled: isDisabled
                });

                // Ensure the underlying <select> disabled state matches
                if (isDisabled) {
                    $el.prop('disabled', true);
                    $el.addClass('readonly-select');
                }
            });

            // Prevent user from opening the Partner dropdown ONLY when disabled
            $('#original_biller').on('select2:opening', function(e) {
                if ($(this).prop('disabled')) {
                    e.preventDefault();
                }
            });

            // ---- Region -> Branch filtering ----
            var $regionSelect = $('#regionname');
            var $branchSelect = $('#branchname');

            var allBranchOptions = [];
            $branchSelect.find('option').each(function() {
                allBranchOptions.push({
                    value: $(this).val(),
                    text: $(this).text(),
                    region: $(this).data('region')
                });
            });

            function filterBranches() {
                var selectedRegion   = $regionSelect.val();
                var branchDisabled   = $branchSelect.prop('disabled');
                var currentBranchVal = $branchSelect.val();

                // Destroy existing Select2 so we can rebuild the options
                if ($branchSelect.data('select2')) {
                    $branchSelect.select2('destroy');
                }

                // Temporarily remove disabled so we can manipulate options cleanly
                $branchSelect.prop('disabled', false);

                $branchSelect.empty();
                $branchSelect.append('<option value=""></option>');

                $.each(allBranchOptions, function(i, opt) {
                    if (opt.value === '') return;
                    if (!selectedRegion || opt.region === selectedRegion) {
                        $branchSelect.append(
                            $('<option>', { value: opt.value, text: opt.text })
                                .attr('data-region', opt.region)
                        );
                    }
                });

                // Restore disabled state + visual class
                $branchSelect.prop('disabled', branchDisabled);
                $branchSelect.toggleClass('readonly-select', branchDisabled);

                // Re-init Select2 with the preserved disabled state
                $branchSelect.select2({
                    width: '100%',
                    placeholder: $branchSelect.data('placeholder') || '-- Select Branch --',
                    allowClear: true,
                    disabled: branchDisabled
                });

                // Restore previous selection if still available
                if (currentBranchVal && $branchSelect.find('option[value="' + currentBranchVal + '"]').length) {
                    $branchSelect.val(currentBranchVal).trigger('change.select2');
                }
            }

            $regionSelect.on('change', filterBranches);
            filterBranches();

            // ---- Reason toggle ----
            var $reason = $('#reason');
            var $wrapCorrectBiller = $('#wrapCorrectBiller');
            var $wrapCorrectAmount = $('#wrapCorrectAmount');
            var $correctBiller = $('#correct_biller');
            var $correctAmount = $('#correct_amount');
            var $partnerAction = $('#partner_action');
            var $partnerActionHint = $('#partnerActionHint');

            function toggleReasonFields() {
                var val = ($reason.val() || '').toUpperCase();

                $wrapCorrectBiller.removeClass('visible');
                $wrapCorrectAmount.removeClass('visible');

                if (val !== 'WRONG BILLER') {
                    $correctBiller.val(null).trigger('change.select2');
                }
                if (val !== 'WRONG AMOUNT') {
                    $correctAmount.val('');
                }

                if (val === 'WRONG BILLER') {
                    $wrapCorrectBiller.addClass('visible');
                } else if (val === 'WRONG AMOUNT') {
                    $wrapCorrectAmount.addClass('visible');
                }

                if (CAN_EDIT_CAD) {
                    $partnerActionHint.text('(required)');
                    $partnerAction.prop('required', true);
                } else {
                    $partnerActionHint.text('(CAD Bills Payment only)');
                    $partnerAction.prop('required', false);
                }
            }

            $reason.on('change', toggleReasonFields);
            toggleReasonFields();

            // ============================================================
            //  AUTOFILL FIELD LOCK/UNLOCK
            // ============================================================
            var $autofillFields = $(
                '#original_biller, #account_no, #account_name, #branchname, ' +
                '#regionname, #date_of_payment, #date_cancelled, #amount'
            );

            // Toggle disabled state on a Select2-backed select.
            // Destroys and re-initializes Select2 so the disabled state is
            // guaranteed to apply, and toggles the .readonly-select class.
            function setSelect2Disabled($sel, disabled) {
                var placeholder = $sel.data('placeholder') || '-- Select --';
                var currentVal  = $sel.val();

                if ($sel.data('select2')) {
                    $sel.select2('destroy');
                }

                $sel.prop('disabled', disabled);
                $sel.toggleClass('readonly-select', disabled);

                $sel.select2({
                    width: '100%',
                    placeholder: placeholder,
                    allowClear: true,
                    disabled: disabled
                });

                if (currentVal !== null && currentVal !== undefined && currentVal !== '') {
                    $sel.val(currentVal).trigger('change.select2');
                }
            }

            function lockAutofillFields() {
                // Text / date / number inputs
                $('#account_no, #account_name, #date_of_payment, #date_cancelled, #amount')
                    .prop('disabled', true)
                    .addClass('readonly-input');

                // Select2 selects
                setSelect2Disabled($('#original_biller'), true);
                setSelect2Disabled($('#branchname'),     true);
                setSelect2Disabled($('#regionname'),     true);
            }

            // Partial unlock — used when the BP Ref IS found and NOT cancelled.
            // Keeps Partner Name locked (derived from lookup) but allows the
            // other fields to be editable.
            function unlockAutofillFields() {
                // Enable text / date / number inputs
                $('#account_no, #account_name, #date_of_payment, #date_cancelled, #amount')
                    .prop('disabled', false)
                    .removeClass('readonly-input');

                // Partner Name stays locked forever (always derived from lookup)
                setSelect2Disabled($('#original_biller'), true);

                // Enable Region + Branch for manual selection
                setSelect2Disabled($('#regionname'), false);
                filterBranches(); // rebuilds branch options based on current region

                // Branch must be re-enabled AFTER filterBranches rebuild
                setSelect2Disabled($('#branchname'), false);
            }

            // Full unlock — used when the BP Ref is NOT found in the DB.
            // ALL autofill fields become user-input, INCLUDING Partner Name.
            function unlockAllAutofillFields() {
                // Enable ALL text / date / number inputs
                $('#account_no, #account_name, #date_of_payment, #date_cancelled, #amount')
                    .prop('disabled', false)
                    .prop('readonly', false)
                    .removeAttr('disabled')
                    .removeClass('readonly-input');

                // Enable ALL Select2 selects, INCLUDING Partner Name
                setSelect2Disabled($('#original_biller'), false);
                setSelect2Disabled($('#regionname'), false);

                filterBranches(); // rebuild branch list based on region

                setSelect2Disabled($('#branchname'), false);
            }

            // Default state: locked
            lockAutofillFields();

            // ============================================================
            //  FILE ATTACHMENTS + DRAG & DROP
            // ============================================================
            var $attachInput = $('#attachments');
            var $attachList  = $('#attachList');
            var $dropZone    = $('#attachDropZone');

            var currentFiles = [];

            function renderAttachList() {
                $attachList.empty();
                if (!currentFiles.length) return;

                $.each(currentFiles, function(i, f) {
                    var $item = $('<div class="attach-item"></div>');
                    var $name = $('<span class="attach-name"></span>')
                        .append($('<i class="fa-solid fa-paperclip"></i>'))
                        .append($('<span></span>').text(f.name + ' (' + Math.round(f.size / 1024) + ' KB)'));

                    var $remove = $('<i class="fa-solid fa-xmark attach-remove" title="Remove"></i>').on('click', function(e) {
                        e.stopPropagation();
                        currentFiles.splice(i, 1);
                        syncInputFiles();
                        renderAttachList();
                    });

                    $item.append($name).append($remove);
                    $attachList.append($item);
                });
            }

            function syncInputFiles() {
                var dataTransfer = new DataTransfer();
                currentFiles.forEach(function(file) { dataTransfer.items.add(file); });
                $attachInput[0].files = dataTransfer.files;
            }

            function isFileAllowed(file) {
                var allowedExt = ['jpg', 'jpeg', 'pdf', 'xls', 'xlsx'];
                var ext = (file.name.split('.').pop() || '').toLowerCase();
                if (allowedExt.indexOf(ext) === -1) {
                    Swal.fire({ icon: 'warning', title: 'Unsupported File',
                                text: file.name + ' is not an allowed file type.' });
                    return false;
                }
                if (file.size > 10 * 1024 * 1024) {
                    Swal.fire({ icon: 'warning', title: 'File Too Large',
                                text: file.name + ' exceeds the 10 MB limit.' });
                    return false;
                }
                return true;
            }

            function addFiles(fileList) {
                var added = 0;
                Array.from(fileList).forEach(function(file) {
                    if (!isFileAllowed(file)) return;
                    var isDup = currentFiles.some(function(f) {
                        return f.name === file.name && f.size === file.size;
                    });
                    if (isDup) return;
                    currentFiles.push(file);
                    added++;
                });
                if (added > 0) {
                    syncInputFiles();
                    renderAttachList();
                }
            }

            $dropZone.on('click', function(e) {
                if ($(e.target).hasClass('attach-remove') || $(e.target).closest('.attach-remove').length) return;
                $attachInput.trigger('click');
            });

            $attachInput.on('change', function() {
                addFiles(this.files);
            });

            ['dragenter', 'dragover'].forEach(function(evtName) {
                $dropZone.on(evtName, function(e) {
                    e.preventDefault(); e.stopPropagation();
                    $dropZone.addClass('dragover');
                });
            });

            ['dragleave', 'drop'].forEach(function(evtName) {
                $dropZone.on(evtName, function(e) {
                    e.preventDefault(); e.stopPropagation();
                    if (evtName === 'dragleave' && this.contains(e.relatedTarget)) return;
                    $dropZone.removeClass('dragover');
                });
            });

            $dropZone.on('drop', function(e) {
                var dt = e.originalEvent.dataTransfer;
                if (dt && dt.files && dt.files.length) {
                    addFiles(dt.files);
                }
            });

            $(document).on('dragover drop', function(e) {
                if (!$dropZone[0].contains(e.target)) {
                    e.preventDefault();
                }
            });

            // ============================================================
            //  CANCELLED LOCK / UNLOCK
            // ============================================================
            var $lockableSection = $('#lockableSection');
            var $saveLogBtn      = $('#saveLogBtn');

            var vpoRemarksWasReadonly = $('#vpo_remarks').prop('readonly') === true;
            var cadRemarksWasReadonly = $('#cad_status_remarks').prop('readonly') === true;
            var partnerActionWasDisabled = $('#partner_action').prop('disabled') === true;

            function lockFormForCancelled() {
                $lockableSection.addClass('cad-locked-section');
                $lockableSection.find('input, select, textarea').prop('disabled', true);

                // Lock autofill fields too
                lockAutofillFields();

                $dropZone.css('pointer-events', 'none');

                $saveLogBtn.prop('disabled', true)
                            .html('<i class="fa-solid fa-lock"></i> Logging Disabled');

                currentFiles = [];
                $attachList.empty();
                $attachInput.val('');
            }

            function unlockForm() {
                $lockableSection.removeClass('cad-locked-section');
                $lockableSection.find('input, select, textarea').prop('disabled', false);

                $('#logged_date').prop('readonly', true);
                $('#transacted_by').prop('readonly', true);
                $('#user_type').prop('readonly', true);
                $('#send_to').prop('readonly', true);

                if (!CAN_EDIT_VPO || vpoRemarksWasReadonly) {
                    $('#vpo_remarks').prop('readonly', true).addClass('readonly-input');
                } else {
                    $('#vpo_remarks').prop('readonly', false).removeClass('readonly-input');
                }

                if (!CAN_EDIT_CAD || cadRemarksWasReadonly) {
                    $('#cad_status_remarks').prop('readonly', true).addClass('readonly-input');
                } else {
                    $('#cad_status_remarks').prop('readonly', false).removeClass('readonly-input');
                }

                if (!CAN_EDIT_CAD || partnerActionWasDisabled) {
                    $('#partner_action').prop('disabled', true);
                    $('#partner_action').addClass('readonly-select');
                } else {
                    $('#partner_action').prop('disabled', false);
                    $('#partner_action').removeClass('readonly-select');
                }

                // Sync Select2 disabled state for lockable selects
                $lockableSection.find('.cad-select').each(function() {
                    var $el = $(this);
                    if ($el.data('select2')) {
                        $el.select2('enable', !$el.prop('disabled'));
                    }
                });

                $dropZone.css('pointer-events', '');

                $saveLogBtn.prop('disabled', false)
                            .html('<i class="fa-solid fa-save"></i> Save Log');

                toggleReasonFields();
            }

            // Clear button: reset values + re-lock autofills
            $('.cad-btn-secondary').on('click', function() {
                currentFiles = [];
                $attachList.empty();
                $attachInput.val('');
                lastRef = '';

                unlockForm();

                // Clear autofill field values
                $('#original_biller').val(null).trigger('change.select2');
                $('#account_no, #account_name, #amount').val('');
                $('#date_of_payment, #date_cancelled').val('');
                $('#regionname').val(null).trigger('change.select2');
                filterBranches();
                $('#branchname').val(null).trigger('change.select2');

                // Re-lock them
                lockAutofillFields();
            });

            // Guard submit + re-enable autofill fields so they POST
            $('#cadLoggingForm').on('submit', function(e) {
                if ($lockableSection.hasClass('cad-locked-section')) {
                    e.preventDefault();
                    Swal.fire({
                        icon: 'error',
                        title: 'Logging Disabled',
                        text: 'This transaction is cancelled and cannot be logged.'
                    });
                    return false;
                }
                // Re-enable so disabled values get submitted
                $autofillFields.prop('disabled', false);
            });

            // ============================================================
            //  BP REF AUTOCOMPLETE + LOOKUP
            // ============================================================
            var $bpRef   = $('#bp_ref_no');
            var $spinner = $('#bpRefSpinner');

            $bpRef.select2({
                width: '100%',
                placeholder: $bpRef.data('placeholder') || 'Type to search or enter BP Ref. No...',
                allowClear: true,
                minimumInputLength: 2,
                tags: true,
                createTag: function(params) {
                    var term = $.trim(params.term);
                    if (term === '') return null;
                    return { id: term, text: term, newTag: true };
                },
                ajax: {
                    url: 'search_bp_ref.php',
                    dataType: 'json',
                    delay: 300,
                    data: function(params) { return { q: params.term }; },
                    processResults: function(data) { return { results: data.results || [] }; },
                    cache: true
                }
            });

            var lastRef = '';

            function checkDuplicateThenLookup(refNo) {
                if (!refNo) return;
                $spinner.show();

                $.ajax({
                    url: 'fetch_bp_ref.php',
                    type: 'GET',
                    dataType: 'json',
                    data: { ref_no: refNo, mode: 'check' },
                    success: function(chk) {
                        if (chk && chk.duplicate) {
                            $spinner.hide();
                            Swal.fire({
                                icon: 'warning',
                                title: 'Already Logged',
                                text: chk.message || 'This BP Ref. No. has already been logged.'
                            });
                            $bpRef.val(null).trigger('change.select2');
                            lastRef = '';
                            lockAutofillFields();
                            return;
                        }

                        $.ajax({
                            url: 'fetch_bp_ref.php',
                            type: 'GET',
                            dataType: 'json',
                            data: { ref_no: refNo },
                            success: function(res) {
                                $spinner.hide();

                                if (!res || !res.found) {
                                    // Reference doesn't exist — unlock ALL fields for manual entry
                                    unlockAllAutofillFields();

                                    // Clear stale values
                                    $('#original_biller').val(null).trigger('change.select2');
                                    $('#account_no, #account_name, #amount').val('');
                                    $('#date_of_payment, #date_cancelled').val('');
                                    $('#regionname').val(null).trigger('change.select2');
                                    filterBranches();
                                    $('#branchname').val(null).trigger('change.select2');

                                    Swal.fire({
                                        icon: 'info',
                                        title: 'Not Found',
                                        html: 'BP Ref. No. <b>' + refNo + '</b> was not found in the Billspayment Transaction table.<br><br>' +
                                              'You may now enter the details manually.',
                                        confirmButtonText: 'Continue Manually'
                                    });
                                    return;
                                }

                                var d = res.data;

                                // Lock autofill fields — values will stay read-only
                                lockAutofillFields();

                                if (d.original_biller) {
                                    setSelectValue($('#original_biller'), d.original_biller, d.original_biller_text);
                                } else {
                                    $('#original_biller').val(null).trigger('change.select2');
                                }

                                if (d.date_of_payment) {
                                    $('#date_of_payment').val(String(d.date_of_payment).substring(0, 10));
                                }
                                if (d.date_cancelled) {
                                    $('#date_cancelled').val(String(d.date_cancelled).substring(0, 10));
                                }
                                if (d.regionname) {
                                    setSelectValue($('#regionname'), d.regionname);
                                    filterBranches();
                                }
                                if (d.branchname) {
                                    setSelectValue($('#branchname'), d.branchname, d.branchname_text);
                                }
                                if (d.amount !== null && d.amount !== undefined) {
                                    $('#amount').val(d.amount);
                                }
                                if (d.account_no)   $('#account_no').val(d.account_no);
                                if (d.account_name) $('#account_name').val(d.account_name);

                                if (d.is_cancelled) {
                                    lockFormForCancelled();
                                } else {
                                    unlockForm();
                                }

                                Swal.fire({
                                    icon: 'success',
                                    title: 'Auto-filled',
                                    text: 'Transaction details loaded.',
                                    timer: 1300,
                                    showConfirmButton: false
                                }).then(function() {
                                    if (d.is_cancelled) {
                                        Swal.fire({
                                            icon: 'warning',
                                            title: 'Cancelled Status Detected',
                                            html: 'BP Ref. No. <b>' + refNo + '</b> is already a <b>CANCELLED</b> transaction.<br><br>' +
                                                  '<b>Logging is disabled for this transaction.</b><br>' +
                                                  'Please verify the details, then clear the form to log another transaction.',
                                            confirmButtonText: 'Understood'
                                        });
                                    }
                                });
                            },
                            error: function(xhr) {
                                $spinner.hide();
                                var msg = 'Lookup failed.';
                                try { msg = JSON.parse(xhr.responseText).message || msg; } catch(e) {}
                                Swal.fire({ icon: 'error', title: 'Error', text: msg });
                            }
                        });
                    },
                    error: function(xhr) {
                        $spinner.hide();
                        Swal.fire({ icon: 'error', title: 'Error', text: 'Duplicate check failed.' });
                    }
                });
            }

            function setSelectValue($select, value, displayText) {
                if (value === null || value === undefined || value === '') return;
                if ($select.find('option[value="' + value + '"]').length === 0) {
                    $select.append($('<option>', {
                        value: value,
                        text: displayText || value
                    }));
                }
                $select.val(value).trigger('change.select2');
            }

            $bpRef.on('select2:select', function(e) {
                var refNo = e.params.data.id;
                if (!refNo) return;
                if (refNo === lastRef) return;
                lastRef = refNo;
                checkDuplicateThenLookup(refNo);
            });

            // Flash messages
            <?php if ($flash_success): ?>
            Swal.fire({
                icon: 'success',
                title: 'Saved',
                html: <?= json_encode($flash_success) ?>,
                timer: 2200,
                showConfirmButton: false
            });
            <?php endif; ?>

            <?php if ($flash_error): ?>
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: <?= json_encode($flash_error) ?>
            });
            <?php endif; ?>
        });
    </script>
</body>
</html>