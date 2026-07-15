<?php
// Connect to the database
require_once __DIR__ . '/../../../config/config.php';

require '../../../vendor/autoload.php';

// Start the session
session_start();
@include_once __DIR__ . '/../../../templates/middleware.php';
$id = resolve_user_identifier();
if (empty($id)) { header('Location: ../../../login_form.php'); exit; }
if (!function_exists('has_any_permission') || !has_any_permission(['Maintenance Masterfiles Partner List', 'Masterfile Partner List'])) { header('Location: ../../home.php'); exit; }


// prefer explicit session values for current user email
$current_user_email = $_SESSION['admin_email'] ?? $_SESSION['user_email'] ?? '';
$newPartners = [];
$existedPartners = [];
$manualUploadError = '';
$isManualUploadPreview = false;
$defaultPartnerMode = 'manual';
$uploadSaveResult = $_SESSION['partner_upload_save_result'] ?? null;
unset($_SESSION['partner_upload_save_result']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_partner_upload'])) {
    $newPartnersForSave = $_SESSION['partner_upload_newPartners'] ?? [];
    $existedPartnersForSave = $_SESSION['partner_upload_existedPartners'] ?? [];
    $insertedCount = 0;
    $updatedCount = 0;

    $saveFields = [
        'partner_id','partner_id_kpx','partner_type','gl_code','partner_name','tg_partner_name','inc_exc','withheld','partnerTin',
        'address','businessStyle','abbreviation','partner_accName','bank_accNumber','bank',
        'settled_online_check','settled_sched','charge_to','settlement_status','charge_sched','serviceCharge',
        'payment_option','transaction_range','transaction_path','status'
    ];

    try {
        $conn->begin_transaction();

        if (!empty($newPartnersForSave)) {
            $insertSql = "INSERT INTO masterdata.partner_masterfile (
                partner_id, partner_id_kpx, partner_type, gl_code, partner_name, tg_partner_name, inc_exc, withheld, partnerTin,
                address, businessStyle, abbreviation, partner_accName, bank_accNumber, bank,
                settled_online_check, settled_sched, charge_to, settlement_status, charge_sched, serviceCharge,
                payment_option, transaction_range, transaction_path, status
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
            )";
            $insertStmt = $conn->prepare($insertSql);
            if (!$insertStmt) throw new Exception('Prepare insert failed: ' . $conn->error);

            foreach ($newPartnersForSave as $csvRow) {
                $vals = [];
                foreach ($saveFields as $f) { $vals[] = trim((string)($csvRow[$f] ?? '')); }
                $types = str_repeat('s', count($vals));
                $bind = [];
                $bind[] = &$types;
                for ($i = 0; $i < count($vals); $i++) { $bind[] = &$vals[$i]; }
                call_user_func_array([$insertStmt, 'bind_param'], $bind);
                if (!$insertStmt->execute()) {
                    throw new Exception('Insert execute failed: ' . $insertStmt->error);
                }
                $insertedCount++;
            }
            $insertStmt->close();
        }

        if (!empty($existedPartnersForSave)) {
            $updateSqlByPartnerId = "UPDATE masterdata.partner_masterfile SET
                partner_id = ?, partner_id_kpx = ?, partner_type = ?, gl_code = ?, partner_name = ?, tg_partner_name = ?, inc_exc = ?, withheld = ?, partnerTin = ?,
                address = ?, businessStyle = ?, abbreviation = ?, partner_accName = ?, bank_accNumber = ?, bank = ?,
                settled_online_check = ?, settled_sched = ?, charge_to = ?, settlement_status = ?, charge_sched = ?, serviceCharge = ?,
                payment_option = ?, transaction_range = ?, transaction_path = ?, status = ?
                WHERE partner_id = ?";
            $updateSqlByKpx = "UPDATE masterdata.partner_masterfile SET
                partner_id = ?, partner_id_kpx = ?, partner_type = ?, gl_code = ?, partner_name = ?, tg_partner_name = ?, inc_exc = ?, withheld = ?, partnerTin = ?,
                address = ?, businessStyle = ?, abbreviation = ?, partner_accName = ?, bank_accNumber = ?, bank = ?,
                settled_online_check = ?, settled_sched = ?, charge_to = ?, settlement_status = ?, charge_sched = ?, serviceCharge = ?,
                payment_option = ?, transaction_range = ?, transaction_path = ?, status = ?
                WHERE partner_id_kpx = ?";
            $updateStmtByPartnerId = $conn->prepare($updateSqlByPartnerId);
            if (!$updateStmtByPartnerId) throw new Exception('Prepare update by partner_id failed: ' . $conn->error);
            $updateStmtByKpx = $conn->prepare($updateSqlByKpx);
            if (!$updateStmtByKpx) throw new Exception('Prepare update by partner_id_kpx failed: ' . $conn->error);

            foreach ($existedPartnersForSave as $item) {
                $dbRow = $item['db'] ?? [];
                $csvRow = $item['csv'] ?? [];
                $resolved = [];

                foreach ($saveFields as $f) {
                    $dbVal = trim((string)($dbRow[$f] ?? ''));
                    $csvVal = trim((string)($csvRow[$f] ?? ''));
                    if ($dbVal === '' && $csvVal !== '') {
                        $resolved[$f] = $csvVal;
                    } elseif ($dbVal !== '' && $csvVal === '') {
                        $resolved[$f] = $dbVal;
                    } else {
                        $resolved[$f] = $csvVal;
                    }
                }

                $csvPartnerId = trim((string)($csvRow['partner_id'] ?? ''));
                $wherePartnerId = trim((string)($dbRow['partner_id'] ?? ''));
                $wherePartnerKpx = trim((string)($dbRow['partner_id_kpx'] ?? ''));
                $useKpxWhere = ($csvPartnerId === '');

                $vals = [];
                foreach ($saveFields as $f) { $vals[] = $resolved[$f]; }
                if ($useKpxWhere) {
                    if ($wherePartnerKpx === '') { continue; }
                    $vals[] = $wherePartnerKpx;
                } else {
                    if ($wherePartnerId === '') { continue; }
                    $vals[] = $wherePartnerId;
                }

                $types = str_repeat('s', count($vals));
                $bind = [];
                $bind[] = &$types;
                for ($i = 0; $i < count($vals); $i++) { $bind[] = &$vals[$i]; }
                if ($useKpxWhere) {
                    call_user_func_array([$updateStmtByKpx, 'bind_param'], $bind);
                    if (!$updateStmtByKpx->execute()) {
                        throw new Exception('Update execute by partner_id_kpx failed: ' . $updateStmtByKpx->error);
                    }
                    if ($updateStmtByKpx->affected_rows >= 0) { $updatedCount++; }
                } else {
                    call_user_func_array([$updateStmtByPartnerId, 'bind_param'], $bind);
                    if (!$updateStmtByPartnerId->execute()) {
                        throw new Exception('Update execute by partner_id failed: ' . $updateStmtByPartnerId->error);
                    }
                    if ($updateStmtByPartnerId->affected_rows >= 0) { $updatedCount++; }
                }
            }
            $updateStmtByPartnerId->close();
            $updateStmtByKpx->close();
        }

        $conn->commit();
        unset($_SESSION['partner_upload_newPartners'], $_SESSION['partner_upload_existedPartners']);
        $_SESSION['partner_upload_save_result'] = [
            'status' => 'success',
            'inserted' => $insertedCount,
            'updated' => $updatedCount
        ];
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['partner_upload_save_result'] = [
            'status' => 'error',
            'message' => $e->getMessage()
        ];
    }

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// get table display for partners
if (isset($_POST['action']) && $_POST['action'] === 'generate_partner_list') {
    header('Content-Type: application/json');

    try {
        $partnerQuery = "SELECT * FROM masterdata.partner_masterfile WHERE status = 'Active' ORDER BY partner_name";
        $stmt = $conn->prepare($partnerQuery);

        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $conn->error);
        }

        $stmt->execute();
        $result = $stmt->get_result();

        $partners = [];
        while ($row = $result->fetch_assoc()) {
            $partners[] = $row;
        }

        $stmt->close();

        echo json_encode([
            'status' => 'success',
            'data' => $partners
        ]);
        exit();
    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Database error: ' . $e->getMessage()
        ]);
        exit();
    }
}

// update partner handler (AJAX)
if (isset($_POST['action']) && $_POST['action'] === 'update_partner') {
    header('Content-Type: application/json');
    try {
        $partnerId = isset($_POST['partner_id']) ? $_POST['partner_id'] : null;
        $updatesJson = isset($_POST['updates']) ? $_POST['updates'] : null;
        if (!$partnerId || !$updatesJson) {
            throw new Exception('Missing parameters');
        }

        $updates = json_decode($updatesJson, true);
        if (!is_array($updates) || empty($updates)) {
            throw new Exception('No updates provided');
        }

        // allowlist fields to update
        $allowed = [
            'partner_id_kpx','partner_type','gl_code','partner_name','tg_partner_name','inc_exc','withheld','partnerTin',
            'address','businessStyle','abbreviation','series_number','partner_accName','bank_accNumber',
            'bank','settled_online_check','settled_sched','charge_to','charge_sched','serviceCharge',
            'payment_option','transaction_range','transaction_path','status'
        ];

        $setParts = [];
        $params = [];
        $types = '';
        foreach ($updates as $k => $v) {
            if (in_array($k, $allowed, true)) {
                $setParts[] = "`$k` = ?";
                $params[] = $v;
                $types .= 's';
            }
        }

        if (empty($setParts)) {
            throw new Exception('No valid fields to update');
        }

        $params[] = $partnerId;
        $types .= 's';

        $sql = "UPDATE masterdata.partner_masterfile SET " . implode(', ', $setParts) . " WHERE partner_id = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);

        // bind params dynamically
        $bindNames = [];
        $bindNames[] = & $types;
        for ($i = 0; $i < count($params); $i++) {
            $bindNames[] = & $params[$i];
        }
        call_user_func_array([$stmt, 'bind_param'], $bindNames);

        if (!$stmt->execute()) {
            throw new Exception('Execute failed: ' . $stmt->error);
        }

        // fetch updated row
        $sel = $conn->prepare('SELECT * FROM masterdata.partner_masterfile WHERE partner_id = ? LIMIT 1');
        $sel->bind_param('s', $partnerId);
        $sel->execute();
        $res = $sel->get_result();
        $updated = $res->fetch_assoc();
        $sel->close();
        $stmt->close();

        echo json_encode(['status' => 'success', 'data' => $updated]);
        exit();
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['manual_upload_preview'])) {
    $isManualUploadPreview = true;
    $defaultPartnerMode = 'auto';
    try {
        if (!isset($_FILES['import_file']) || !is_uploaded_file($_FILES['import_file']['tmp_name'])) {
            throw new Exception('Please upload a CSV file.');
        }

        $tmpPath = $_FILES['import_file']['tmp_name'];
        $uploadedName = $_FILES['import_file']['name'] ?? '';
        $ext = strtolower(pathinfo($uploadedName, PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            throw new Exception('Only CSV file is supported for Manual Upload preview.');
        }

        $dbRows = [];
        $dbQuery = "SELECT 
            partner_id,
            partner_id_kpx,
            partner_type,
            gl_code,
            partner_name,
            tg_partner_name,
            inc_exc,
            withheld,
            partnerTin,
            address,
            businessStyle,
            abbreviation,
            partner_accName,
            bank_accNumber,
            bank,
            series_number,
            settled_online_check,
            settled_sched,
            charge_to,
            settlement_status,
            charge_sched,
            serviceCharge,
            payment_option,
            transaction_range,
            transaction_path,
            status
        FROM masterdata.partner_masterfile";
        $dbStmt = $conn->prepare($dbQuery);
        if (!$dbStmt) {
            throw new Exception('Prepare failed: ' . $conn->error);
        }
        $dbStmt->execute();
        $dbResult = $dbStmt->get_result();
        while ($db = $dbResult->fetch_assoc()) {
            foreach ($db as $k => $v) {
                $db[$k] = trim((string)$v);
            }
            $dbRows[] = $db;
        }
        $dbStmt->close();

        $byKpx = [];
        $byName = [];
        foreach ($dbRows as $row) {
            $k = strtolower($row['partner_id_kpx']);
            $n = strtolower($row['partner_name']);
            if ($k !== '') { $byKpx[$k][] = $row; }
            if ($n !== '') { $byName[$n][] = $row; }
        }

        if (($handle = fopen($tmpPath, 'r')) === false) {
            throw new Exception('Unable to read uploaded CSV file.');
        }

        $lineNumber = 0;
        while (($cols = fgetcsv($handle)) !== false) {
            $lineNumber++;
            if ($lineNumber === 1) { continue; } // header

            $csvRow = [
                'partner_id' => trim((string)($cols[0] ?? '')),
                'partner_id_kpx' => trim((string)($cols[1] ?? '')),
                'partner_type' => trim((string)($cols[3] ?? '')),
                'gl_code' => trim((string)($cols[4] ?? '')),
                'partner_name' => trim((string)($cols[5] ?? '')),
                'tg_partner_name' => trim((string)($cols[6] ?? '')),
                'inc_exc' => trim((string)($cols[8] ?? '')),
                'withheld' => trim((string)($cols[9] ?? '')),
                'partnerTin' => trim((string)($cols[10] ?? '')),
                'address' => trim((string)($cols[11] ?? '')),
                'businessStyle' => trim((string)($cols[12] ?? '')),
                'abbreviation' => trim((string)($cols[13] ?? '')),
                'partner_accName' => trim((string)($cols[14] ?? '')),
                'bank_accNumber' => trim((string)($cols[15] ?? '')),
                'bank' => trim((string)($cols[16] ?? '')),
                'series_number' => '',
                'settled_online_check' => trim((string)($cols[18] ?? '')),
                'settled_sched' => trim((string)($cols[19] ?? '')),
                'charge_to' => trim((string)($cols[20] ?? '')),
                'settlement_status' => trim((string)($cols[26] ?? '')),
                'charge_sched' => trim((string)($cols[21] ?? '')),
                'serviceCharge' => trim((string)($cols[22] ?? '')),
                'payment_option' => trim((string)($cols[23] ?? '')),
                'transaction_range' => trim((string)($cols[24] ?? '')),
                'transaction_path' => trim((string)($cols[25] ?? '')),
                'status' => trim((string)($cols[27] ?? ''))
            ];

            $csvKpx = $csvRow['partner_id_kpx'];
            $csvName = $csvRow['partner_name'];
            $csvStatus = $csvRow['status'];

            if ($csvKpx === '' && $csvName === '' && $csvStatus === '') {
                continue;
            }

            $exactMatch = false;
            foreach ($dbRows as $dbRow) {
                if (
                    strcasecmp($dbRow['partner_id_kpx'], $csvKpx) === 0 &&
                    strcasecmp($dbRow['partner_name'], $csvName) === 0
                ) {
                    $exactMatch = true;
                    break;
                }
            }
            if ($exactMatch) {
                continue;
            }

            $kpxMatched = $csvKpx !== '' && isset($byKpx[strtolower($csvKpx)]);
            $nameMatched = $csvName !== '' && isset($byName[strtolower($csvName)]);
            $existsByAnyField = $kpxMatched || $nameMatched;

            if ($existsByAnyField) {
                $bestDb = null;
                $candidatePool = [];
                if ($kpxMatched) { $candidatePool = array_merge($candidatePool, $byKpx[strtolower($csvKpx)]); }
                if ($nameMatched) { $candidatePool = array_merge($candidatePool, $byName[strtolower($csvName)]); }
                if (!empty($candidatePool)) {
                    $bestDb = $candidatePool[0];
                } else {
                    $bestDb = [];
                }

                $existedPartners[] = [
                    'db' => $bestDb,
                    'csv' => $csvRow
                ];
            } else {
                $newPartners[] = $csvRow;
            }
        }
        fclose($handle);
        $_SESSION['partner_upload_newPartners'] = $newPartners;
        $_SESSION['partner_upload_existedPartners'] = $existedPartners;
    } catch (Exception $e) {
        $manualUploadError = $e->getMessage();
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Partner List | <?php if($_SESSION['user_type'] === 'admin' || $_SESSION['user_type'] === 'user') echo ucfirst($_SESSION['user_type']); else echo "Guest";?></title>
    <!-- custom CSS file link  -->
    <link rel="stylesheet" href="../../../assets/css/templates/style.css?v=<?php echo time(); ?>">
    <script src="https://kit.fontawesome.com/30b908cc5a.js" crossorigin="anonymous"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="../../../assets/js/sweetalert2.all.min.js"></script>

    <link rel="icon" href="../../../images/MLW logo.png" type="image/png">
    <style>
        /* Keep cells on a single line and allow full content horizontally.
           Table will expand horizontally and the container will scroll. */
        .table-responsive { overflow-x: auto; }
        /* Let table size to its content so columns show full values on one line */
        #partnerTable { table-layout: auto; width: auto; min-width: 100%; }
        #partnerTable thead th,
        #partnerTable tbody td {
            white-space: nowrap;
            word-break: normal;
            overflow: visible;
        }
        /* Ensure the responsive wrapper shows horizontal scrollbar when needed */
        .table-responsive .table { width: auto; }
    </style>
    <style>
        /* Row hover pointer */
        #partnerTable tbody tr:hover { cursor: pointer; background-color: #f8f9fa; }

        /* Revamped modal styles */
        #partnerModalOverlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.55); z-index:1200; backdrop-filter: blur(2px); }
        #partnerModal { display:none; position:fixed; left:50%; top:50%; transform:translate(-50%,-50%); background:#ffffff; z-index:1210; width:94%; max-width:980px; max-height:86vh; overflow:hidden; border-radius:10px; box-shadow:0 16px 40px rgba(2,6,23,0.32); font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial; }
        #partnerModal .modal-header { display:flex; gap:12px; align-items:center; padding:16px 20px; border-bottom:1px solid #f1f5f9; }
        #partnerModal .modal-title { margin:0; font-size:18px; font-weight:700; color:#0f172a; }
        #partnerModal .modal-sub { margin:0; font-size:13px; color:#475569; }
        .partner-status-badge { font-size:12px; padding:6px 10px; border-radius:999px; font-weight:600; display:inline-block; }
        .badge-active { background: #dcfce7; color: #166534; }
        .badge-inactive { background:#eef2ff; color:#3730a3; }
        .badge-other { background:#f1f5f9; color:#0f172a; }
        #partnerModal .modal-actions { margin-left:auto; display:flex; gap:8px; align-items:center; }
        #partnerModal .modal-close { background:transparent; border:0; font-size:20px; cursor:pointer; color:#0f172a; padding:6px; border-radius:6px; }
        #partnerModal .modal-close:hover { background:#f8fafc; }
        #partnerModal .modal-body { padding:14px 20px; overflow:auto; max-height:62vh; }
        /* Two-column responsive grid for details */
        .partner-details-grid { display:grid; grid-template-columns: 1fr 1fr; gap:12px 24px; }
        .partner-detail { background: #fff; padding:10px 12px; border-radius:8px; border:1px solid #f1f5f9; }
        .partner-detail-key { color:#475569; font-size:12px; font-weight:700; margin-bottom:6px; }
        .partner-detail-val { color:#0f172a; font-size:14px; word-break:break-word; }
        @media (max-width:700px) { .partner-details-grid { grid-template-columns: 1fr; } #partnerModal { width:96%; } }
        #partnerModal .modal-footer { padding:12px 20px; border-top:1px solid #f1f5f9; display:flex; justify-content:flex-end; gap:8px; background:#fff; }
        .btn-ghost { background:transparent; border:1px solid #e2e8f0; color:#0f172a; padding:8px 12px; border-radius:8px; cursor:pointer; display:inline-flex; align-items:center; gap:8px; transition: all 160ms ease; }
        .btn-ghost .btn-label { font-weight:600; font-size:13px; }
        .btn-ghost:hover { background:#f8fafc; transform:translateY(-2px); box-shadow:0 6px 18px rgba(2,6,23,0.06); }
        .btn-primary { background:#0ea5a4; border:0; color:#fff; padding:8px 12px; border-radius:8px; cursor:pointer; display:inline-flex; align-items:center; gap:8px; transition: all 160ms ease; }
        .btn-primary:hover { filter:brightness(0.95); transform:translateY(-1px); }
        .modal-close { transition: all 140ms ease; }
        .modal-close:hover { transform:translateY(-1px); color:#0b1220; }
        /* Mode card selector */
        .mode-cards { display:flex; gap:8px; }
        .mode-card {
            border: 1px solid #e9ecef;
            padding: 8px 10px;
            border-radius: 8px;
            cursor: pointer;
            min-width: 120px;
            text-align: left;
            background: #fff;
            transition: all 120ms ease;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            display:flex;
            flex-direction:row;
            align-items:center;
            gap:10px;
        }
        .mode-card .mode-icon { font-size:18px; color:#6c757d; width:28px; text-align:center; }
        .mode-card .mode-text { display:flex; flex-direction:column; }
        .mode-card .mode-label { font-weight:700; margin:0; font-size:13px; }
        .mode-card small { color:#6c757d; display:block; font-size:11px; }
        .mode-card.selected { border-color: #dc3545; box-shadow: 0 8px 24px rgba(220,53,69,0.06); }
        .mode-card.selected .mode-icon { color:#dc3545; }
        /* Sticky header for developer modal tables */
        #newPartnerDeveloperListModal .table-responsive,
        #existedPartnerDeveloperListModal .table-responsive {
            max-height: calc(100vh - 220px);
            overflow: auto;
        }
        #newPartnerDeveloperTable thead th,
        #existedPartnerDeveloperTable thead th {
            position: sticky;
            top: 0;
            z-index: 5;
            background: #fff;
            box-shadow: inset 0 -1px 0 #dee2e6;
        }
    </style>
</head>
<body>
    <div class="main-container">
        <?php include '../../../templates/header_ui.php'; ?>
        <!-- Show and Hide Side Nav Menu -->
        <?php include '../../../templates/sidebar.php'; ?>
        <div id="loading-overlay">
            <div class="loading-spinner"></div>
        </div>
        <div class="bp-section-header" role="region" aria-label="Page title">
            <div class="bp-section-title">
                <i class="fa-solid fa-layer-group" aria-hidden="true"></i>
                <div>
                    <h2>Partner List</h2>
                </div>
            </div>
        </div>
        <div class="container-fluid">
            <div class="mb-3 d-flex align-items-center justify-content-between" style="gap:12px;">
                <div class="d-flex align-items-center" style="gap:12px;">
                    <div class="mode-cards">
                        <label class="mode-card <?php echo $defaultPartnerMode === 'manual' ? 'selected' : ''; ?>" data-mode="manual">
                            <input type="radio" name="partnerMode" value="manualedit" style="display:none;">
                            <div class="mode-icon"><i class="fa-solid fa-file-lines"></i></div>
                            <div class="mode-text">
                                <div class="mode-label">Manual Edit</div>
                                <small>click row to edit</small>
                            </div>
                        </label>
                        <label class="mode-card <?php echo $defaultPartnerMode === 'auto' ? 'selected' : ''; ?>" data-mode="auto">
                            <input type="radio" name="partnerMode" value="manualupload" style="display:none;" <?php echo $defaultPartnerMode === 'auto' ? 'checked' : ''; ?>>
                            <div class="mode-icon"><i class="fa-solid fa-cloud-arrow-up"></i></div>
                            <div class="mode-text">
                                <div class="mode-label">Manual Upload</div>
                                <small>Excel Upload</small>
                            </div>
                        </label>
                    </div>
                </div>
            </div>
            <!-- MANUAL EDIT SECTION -->
            <div id="manualEditSection" class="row">
                <div class="col-md-18">
                    <div class="card">
                        <div class="card-header">
                            <div class="row g-2 align-items-end justify-content-between">
                                <div class="col-md-6 ms-auto">
                                    <label for="searchInput" class="form-label mb-1">Search Partner</label>
                                    <input
                                        type="text"
                                        id="searchInput"
                                        class="form-control"
                                        placeholder="Search by any field..."
                                        list="searchSuggestions"
                                    >
                                    <datalist id="searchSuggestions">
                                    </datalist>
                                </div>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-striped table-hover" id="partnerTable">
                                    <thead>
                                        <tr>
                                            <th>Partner Name</th>
                                            <th>Partner ID</th>
                                            <th>KPX ID</th>
                                            <th>GL Code</th>
                                            <th>Partner Account Name</th>
                                            <th>Bank Account Number</th>
                                            <th>Bank</th>
                                            <th>Payment Method</th>
                                                <th>Charge To</th>
                                                <th>Charge Schedule</th>
                                                <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    </tbody>
                                </table>
                                    <nav aria-label="Partner table pagination">
                                        <ul class="pagination" id="partnerPagination"></ul>
                                    </nav>
                                    <!-- Partner detail modal -->
                                    <div id="partnerModalOverlay" aria-hidden="true"></div>
                                    <div id="partnerModal" role="dialog" aria-modal="true" aria-labelledby="partnerModalTitle">
                                        <div class="modal-header">
                                            <div>
                                                <h5 id="partnerModalTitle" class="modal-title">Partner Details</h5>
                                                <div id="partnerModalSub" class="modal-sub">&nbsp;</div>
                                            </div>
                                            <div class="modal-actions">
                                                <span id="partnerStatusBadge" class="partner-status-badge badge-other">&nbsp;</span>
                                                <button type="button" class="btn-ghost" id="partnerModalEditBtn" title="Edit"><i class="fa fa-pen"></i><span class="btn-label"> Edit</span></button>
                                                <button type="button" class="modal-close" id="partnerModalClose" aria-label="Close">×</button>
                                            </div>
                                        </div>
                                        <div class="modal-body" id="partnerModalBody"></div>
                                        <div class="modal-footer">
                                            <button class="btn-ghost" id="partnerModalCopyBtn">Copy details</button>
                                            <button class="btn-primary" id="partnerModalCloseBtn">Close</button>
                                        </div>
                                    </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- MANUAL UPLOAD SECTION -->
            <div id="manualUploadSection" class="row" style="display:none;">
                <div class="col-md-18">
                    <div class="card">
                        <div class="card-header">
                            <div class="row mt-3 align-items-end">
                                <div class="col-md-8 mb-3 d-flex align-items-end">
                                    <div class="w-100">
                                        <label for="uploadFileInput" class="form-label mb-1">Select Excel File:</label>
                                        <div class="d-flex align-items-center">
                                            <form id="partnerUploadForm" action="" method="post" enctype="multipart/form-data" class="d-flex flex-grow-1 align-items-center mb-0">
                                                <input id="uploadFileInput" type="file" name="import_file" accept=".csv" class="form-control me-2" required />
                                                <input type="hidden" name="manual_upload_preview" value="1">
                                                <button type="submit" class="btn btn-danger text-nowrap" id="uploadProceedBtn">Proceed</button>
                                            </form>
                                            <button type="button" class="btn btn-danger text-nowrap ms-2" id="submitBtn" style="<?php echo ($isManualUploadPreview && (count($newPartners) + count($existedPartners) > 0)) ? '' : 'display:none;'; ?>">Submit</button>
                                            <form method="post" id="partnerUploadSubmitForm" class="d-none">
                                                <input type="hidden" name="submit_partner_upload" value="1">
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div>
                                No. of new partners: <span id="newPartnerCount"><?php echo count($newPartners); ?></span>
                                <br>
                                No. of existed partners: <span id="existedPartnerCount"><?php echo count($existedPartners); ?></span>
                            </div>
                            <?php if ($manualUploadError !== ''): ?>
                                <div class="alert alert-danger mt-2 mb-0"><?php echo htmlspecialchars($manualUploadError); ?></div>
                            <?php endif; ?>
                        </div>
                        <!-- <div class="card-body" style="display: none;"> -->
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-striped table-hover" id="partnerUploadTable">
                                    <thead>
                                        <tr>
                                            <th colspan="2">New Partners List</th>
                                            <th><button type="button" class="btn btn-danger btn-sm" id="viewNewPartnerDeveloperListBtn" style="<?php echo !empty($newPartners) ? '' : 'display:none;'; ?>">View Developers List</button></th>
                                        </tr>
                                        <tr>
                                            <th>KPX Partner ID</th>
                                            <th>Partner Name</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($newPartners)): ?>
                                            <tr><td colspan="3" class="text-center">No new partners found.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($newPartners as $partner): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($partner['partner_id_kpx'] ?? ''); ?></td>
                                                    <td><?php echo htmlspecialchars($partner['partner_name'] ?? ''); ?></td>
                                                    <td><?php echo htmlspecialchars($partner['status'] ?? ''); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover" id="partnerUploadTable">
                                    <thead>
                                        <tr>
                                            <th colspan="6">Existed Partners List</th>
                                            <th><button type="button" class="btn btn-danger btn-sm" id="viewExistedPartnerDeveloperListBtn" style="<?php echo !empty($existedPartners) ? '' : 'display:none;'; ?>">View Developers List</button></th>
                                        </tr>
                                        <tr>
                                            <th colspan="2">KPX Partner ID</th>
                                            <th colspan="2">Partner Name</th>
                                            <th colspan="2">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($existedPartners)): ?>
                                            <tr><td colspan="7" class="text-center">No existed partners with mismatch found.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($existedPartners as $partner): ?>
                                                <tr>
                                                    <?php $db = $partner['db'] ?? []; $csv = $partner['csv'] ?? []; ?>
                                                    <td class="<?php echo strcasecmp((string)($csv['partner_id_kpx'] ?? ''), (string)($db['partner_id_kpx'] ?? '')) === 0 ? 'table-success' : 'table-danger'; ?>"><?php echo htmlspecialchars($db['partner_id_kpx'] ?? ''); ?></td>
                                                    <td class="<?php echo strcasecmp((string)($csv['partner_id_kpx'] ?? ''), (string)($db['partner_id_kpx'] ?? '')) === 0 ? 'table-success' : 'table-warning'; ?>"><?php echo htmlspecialchars($csv['partner_id_kpx'] ?? ''); ?></td>
                                                    <td class="<?php echo strcasecmp((string)($csv['partner_name'] ?? ''), (string)($db['partner_name'] ?? '')) === 0 ? 'table-success' : 'table-danger'; ?>"><?php echo htmlspecialchars($db['partner_name'] ?? ''); ?></td>
                                                    <td class="<?php echo strcasecmp((string)($csv['partner_name'] ?? ''), (string)($db['partner_name'] ?? '')) === 0 ? 'table-success' : 'table-warning'; ?>"><?php echo htmlspecialchars($csv['partner_name'] ?? ''); ?></td>
                                                    <td class="<?php echo strcasecmp((string)($csv['status'] ?? ''), (string)($db['status'] ?? '')) === 0 ? 'table-success' : 'table-danger'; ?>"><?php echo htmlspecialchars($db['status'] ?? ''); ?></td>
                                                    <td class="<?php echo strcasecmp((string)($csv['status'] ?? ''), (string)($db['status'] ?? '')) === 0 ? 'table-success' : 'table-warning'; ?>"><?php echo htmlspecialchars($csv['status'] ?? ''); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="newPartnerDeveloperListModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-fullscreen modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">New Partners - Developer List</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="text" class="form-control mb-3" id="newPartnerDeveloperSearchInput" placeholder="Search by any column...">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover" id="newPartnerDeveloperTable">
                            <thead><tr id="newPartnerDeveloperHeaderRow"></tr></thead>
                            <tbody id="newPartnerDeveloperTableBody"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="existedPartnerDeveloperListModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-fullscreen modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Existed Partners - Developer List</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="text" class="form-control mb-3" id="existedPartnerDeveloperSearchInput" placeholder="Search by any column...">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover" id="existedPartnerDeveloperTable">
                            <thead><tr id="existedPartnerDeveloperHeaderRow"></tr></thead>
                            <tbody id="existedPartnerDeveloperTableBody"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    </script>
</body>
<?php include '../../../templates/footer.php'; ?>

<!-- PARTNER LIST -->
 <script>
    $(function () {
    const $tableBody = $('#partnerTable tbody');
    const $searchInput = $('#searchInput');
    const $searchSuggestions = $('#searchSuggestions');
    const $loadingOverlay = $('#loading-overlay');
    const $pagination = $('#partnerPagination');

    let allPartners = [];
    let filteredPartners = [];
    let currentPage = 1;
    const rowsPerPage = 10;
    const newPartnersDeveloperData = <?php echo json_encode($newPartners, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    const existedPartnersDeveloperData = <?php echo json_encode($existedPartners, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    const developerColumns = ['partner_id','partner_id_kpx','partner_type','gl_code','partner_name','tg_partner_name','inc_exc','withheld','partnerTin','address','businessStyle','abbreviation','partner_accName','bank_accNumber','bank','series_number','settled_online_check','settled_sched','charge_to','settlement_status','charge_sched','serviceCharge','payment_option','transaction_range','transaction_path','status'];

        function escapeHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function getField(row, keys, defaultValue = '-') {
            for (const key of keys) {
                if (row[key] !== undefined && row[key] !== null && row[key] !== '') {
                    return row[key];
                }
            }
            return defaultValue;
        }

        function renderTableRows(rows) {
            if (!rows.length) {
                $tableBody.html('<tr><td colspan="11" class="text-center">No partner records found.</td></tr>');
                return;
            }

            const html = rows.map((row) => {
                const partnerName = getField(row, ['partner_name', 'name']);
                const partnerId = getField(row, ['partner_id']);
                const kpxId = getField(row, ['partner_id_kpx']);
                const glCode = getField(row, ['gl_code']);
                const accName = getField(row, ['partner_accName']);
                const bankAcc = getField(row, ['bank_accNumber']);
                const bank = getField(row, ['bank']);
                const paymentMethod = getField(row, ['settled_online_check', 'payment_method']);
                const chargeTo = getField(row, ['charge_to']);
                const chargeSched = getField(row, ['charge_sched']);
                const status = getField(row, ['status']);

                return `
                    <tr data-partner-id="${escapeHtml(partnerId)}">
                        <td>${escapeHtml(partnerName)}</td>
                        <td>${escapeHtml(partnerId)}</td>
                        <td>${escapeHtml(kpxId)}</td>
                        <td>${escapeHtml(glCode)}</td>
                        <td>${escapeHtml(accName)}</td>
                        <td>${escapeHtml(bankAcc)}</td>
                        <td>${escapeHtml(bank)}</td>
                        <td>${escapeHtml(paymentMethod)}</td>
                        <td>${escapeHtml(chargeTo)}</td>
                        <td>${escapeHtml(chargeSched)}</td>
                        <td>${escapeHtml(status)}</td>
                    </tr>
                `;
            }).join('');

            $tableBody.html(html);
        }

        function updateSuggestions(rows) {
            const uniqueNames = [...new Set(rows.map((row) => getField(row, ['partner_name'], '')))]
                .filter((name) => name !== '')
                .sort((a, b) => a.localeCompare(b));

            const optionsHtml = uniqueNames
                .map((name) => `<option value="${escapeHtml(name)}"></option>`)
                .join('');

            $searchSuggestions.html(optionsHtml);
        }

        function filterAndRender() {
            const keyword = $searchInput.val().toLowerCase().trim();

            if (!keyword) {
                filteredPartners = allPartners.slice();
                currentPage = 1;
                renderTableRowsPaged();
                return;
            }

            const filteredRows = allPartners.filter((row) => {
                const partnerName = getField(row, ['partner_name', 'name'], '').toString();
                const partnerId = getField(row, ['partner_id'], '').toString();
                const kpxId = getField(row, ['partner_id_kpx'], '').toString();
                const glCode = getField(row, ['gl_code'], '').toString();
                const accName = getField(row, ['partner_accName'], '').toString();
                const bankAcc = getField(row, ['bank_accNumber'], '').toString();
                const bank = getField(row, ['bank'], '').toString();
                const paymentMethod = getField(row, ['settled_online_check', 'payment_method'], '').toString();
                const chargeTo = getField(row, ['charge_to'], '').toString();
                const chargeSched = getField(row, ['charge_sched'], '').toString();
                const status = getField(row, ['status'], '').toString();

                const searchableText = [
                    partnerName,
                    partnerId,
                    kpxId,
                    glCode,
                    accName,
                    bankAcc,
                    bank,
                    paymentMethod,
                    chargeTo,
                    chargeSched
                ,
                    status
                ].join(' ').toLowerCase();

                return searchableText.includes(keyword);
            });

            filteredPartners = filteredRows;
            currentPage = 1;
            renderTableRowsPaged();
        }

        function renderTableRowsPaged() {
            const total = filteredPartners.length;
            const start = (currentPage - 1) * rowsPerPage;
            const end = start + rowsPerPage;
            const pageRows = filteredPartners.slice(start, end);
            renderTableRows(pageRows);
            renderPaginationControls(total);
        }

        function renderPaginationControls(totalItems) {
            const totalPages = Math.max(1, Math.ceil(totalItems / rowsPerPage));
            const maxVisible = 5; // show at most 5 page buttons
            let start = 1;
            let end = totalPages;

            if (totalPages > maxVisible) {
                const half = Math.floor(maxVisible / 2);
                start = currentPage - half;
                end = currentPage + half;
                if (start < 1) {
                    start = 1;
                    end = maxVisible;
                }
                if (end > totalPages) {
                    end = totalPages;
                    start = totalPages - maxVisible + 1;
                }
            }

            let html = '';
            // prev
            html += `<li class="page-item ${currentPage === 1 ? 'disabled' : ''}"><a class="page-link" href="#" data-page="${currentPage - 1}">Previous</a></li>`;

            if (start > 1) {
                html += `<li class="page-item"><a class="page-link" href="#" data-page="1">1</a></li>`;
                if (start > 2) html += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
            }

            for (let p = start; p <= end; p++) {
                html += `<li class="page-item ${p === currentPage ? 'active' : ''}"><a class="page-link" href="#" data-page="${p}">${p}</a></li>`;
            }

            if (end < totalPages) {
                if (end < totalPages - 1) html += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
                html += `<li class="page-item"><a class="page-link" href="#" data-page="${totalPages}">${totalPages}</a></li>`;
            }

            // next
            html += `<li class="page-item ${currentPage === totalPages ? 'disabled' : ''}"><a class="page-link" href="#" data-page="${currentPage + 1}">Next</a></li>`;
            $pagination.html(html);
        }

        // handle pagination clicks
        $(document).on('click', '#partnerPagination .page-link', function (e) {
            e.preventDefault();
            const tgt = $(this).data('page');
            if (!tgt || tgt < 1) return;
            const totalPages = Math.max(1, Math.ceil(filteredPartners.length / rowsPerPage));
            if (tgt > totalPages) return;
            currentPage = tgt;
            renderTableRowsPaged();
            // scroll to top of table after page change
            $('html, body').animate({ scrollTop: $('#partnerTable').offset().top - 80 }, 150);
        });

        // show partner modal with full details
        function showPartnerModal(partner) {
            if (!partner) return;

            // Header title and subtitle
            const title = partner.partner_name || partner.name || 'Partner Details';
            const subtitleParts = [];
            if (partner.partner_id) subtitleParts.push('ID: ' + partner.partner_id);
            if (partner.bank) subtitleParts.push(partner.bank);
            $('#partnerModalTitle').text(title);
            $('#partnerModalSub').text(subtitleParts.join(' • '));

            // Status badge
            const status = (partner.status || '').toString().toLowerCase();
            const $badge = $('#partnerStatusBadge');
            $badge.removeClass('badge-active badge-inactive badge-other');
            if (status === 'active') { $badge.addClass('badge-active').text('ACTIVE'); }
            else if (status === 'inactive') { $badge.addClass('badge-inactive').text('INACTIVE'); }
            else { $badge.addClass('badge-other').text((partner.status || '').toString().toUpperCase()); }

            // Map of fields to display (two-column grid)
            const map = [
                ['id', 'ID'],
                ['partner_id', 'Partner ID'],
                ['partner_id_kpx', 'KPX ID'],
                ['partner_type', 'Partner Type'],
                ['gl_code', 'GL Code'],
                ['partner_name', 'Partner Name'],
                ['tg_partner_name', 'TG Partner Name'],
                ['inc_exc', 'Pricing Type'],
                ['withheld', 'Withheld'],
                ['partnerTin', 'Partner Tin'],
                ['address', 'Address'],
                ['businessStyle', 'Business Style'],
                ['abbreviation', 'Abbreviation'],
                ['series_number', 'Series Number'],
                ['partner_accName', 'Partner Account Name'],
                ['bank_accNumber', 'Bank Account Number'],
                ['bank', 'Bank'],
                ['settled_online_check', 'Settles Payment Method'],
                ['settled_sched', 'Settled Schedule'],
                ['charge_to', 'Charge To'],
                ['charge_sched', 'Charge Schedule'],
                ['serviceCharge', 'Service Charge'],
                ['payment_option', 'Payment Option'],
                ['transaction_range', 'Transaction Range'],
                ['transaction_path', 'Transaction Path'],
                ['status', 'Status']
            ];

            let html = '<div class="partner-details-grid">';
            map.forEach(function(pair) {
                const key = pair[0];
                const label = pair[1];
                const val = (partner[key] !== undefined && partner[key] !== null) ? partner[key] : '';
                html += `
                    <div class="partner-detail">
                        <div class="partner-detail-key">${escapeHtml(label)}</div>
                        <div class="partner-detail-val" data-key="${escapeHtml(key)}">${escapeHtml(val)}</div>
                    </div>
                `;
            });
            html += '</div>';

            $('#partnerModalBody').html(html);

            // Show modal
            $('#partnerModalOverlay').fadeIn(120);
            $('#partnerModal').fadeIn(160).attr('aria-hidden', 'false');

            // Wire copy button
            $('#partnerModalCopyBtn').off('click').on('click', function () { copyPartnerDetails(partner); });

        // edit / save button toggles based on whether inputs are present
            $('#partnerModalEditBtn').off('click').on('click', function () {
            if ($('#partnerModalBody').find('.modal-edit-input').length) {
                savePartnerEdits(partner);
            } else {
                enterEditMode(partner);
            }
        });

        function enterEditMode(partner) {
            $('#partnerModalEditBtn').html('<i class="fa fa-check"></i><span class="btn-label"> Save</span>').attr('title', 'Save').attr('data-editing','1');
            // turn values into inputs
            $('#partnerModalBody').find('.partner-detail-val').each(function () {
                const $val = $(this);
                const key = $val.data('key');
                const text = $val.text();
                if (key === 'address') {
                    $val.html(`<textarea class="modal-edit-input" data-key="${escapeHtml(key)}" style="width:100%;min-height:64px">${escapeHtml(text)}</textarea>`);
                } else {
                    $val.html(`<input class="modal-edit-input" data-key="${escapeHtml(key)}" type="text" value="${escapeHtml(text)}" style="width:100%">`);
                }
            });
        }

        function savePartnerEdits(partner) {
                const updates = {};
                $('#partnerModalBody').find('.modal-edit-input').each(function() {
                    const $i = $(this);
                    const k = $i.data('key');
                    const v = $i.val();
                    updates[k] = v;
                });
                // disable button
                $('#partnerModalEditBtn').prop('disabled', true).addClass('disabled');
                $.ajax({
                    url: 'masterfile-partner-list.php',
                    method: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'update_partner',
                        partner_id: partner.partner_id || partner.id,
                        updates: JSON.stringify(updates)
                    },
                    success: function(resp) {
                        if (resp && resp.status === 'success' && resp.data) {
                            // update local copy
                            const updated = resp.data;
                            // replace in allPartners
                            for (let i=0;i<allPartners.length;i++) {
                                if (String(allPartners[i].partner_id) === String(updated.partner_id) || String(allPartners[i].id) === String(updated.id)) {
                                    allPartners[i] = updated;
                                    break;
                                }
                            }
                            filteredPartners = allPartners.slice();
                            renderTableRowsPaged();
                            // reset edit button UI then refresh modal content
                            $('#partnerModalEditBtn').html('<i class="fa fa-pen"></i><span class="btn-label"> Edit</span>').attr('title','Edit').removeAttr('data-editing');
                            showPartnerModal(updated);
                            Swal.fire({ toast:true, position:'top-end', timer:1200, showConfirmButton:false, icon:'success', title:'Saved' });
                        } else {
                            Swal.fire({ icon:'error', title:'Save Failed', text: (resp && resp.message) ? resp.message : 'Unable to save changes.' });
                        }
                    },
                    error: function() {
                        Swal.fire({ icon:'error', title:'Save Failed', text: 'Unable to save changes.' });
                    },
                    complete: function() {
                        $('#partnerModalEditBtn').prop('disabled', false).removeClass('disabled');
                    }
                });
            }
        }

        function copyPartnerDetails(partner) {
            if (!partner) return;
            const fields = [
                'partner_name','partner_id','partner_id_kpx','gl_code','partner_accName','bank_accNumber','bank','settled_online_check','charge_to','charge_sched','status'
            ];
            const lines = [];
            fields.forEach(function(k) {
                const v = (partner[k] !== undefined && partner[k] !== null) ? partner[k] : '';
                lines.push(k + ': ' + v);
            });
            const text = lines.join('\n');
            try {
                navigator.clipboard.writeText(text).then(function() {
                    Swal.fire({ toast: true, position: 'top-end', timer: 1200, showConfirmButton: false, icon: 'success', title: 'Copied' });
                }, function() {
                    Swal.fire({ icon: 'info', title: 'Copy', text: 'Unable to access clipboard.' });
                });
            } catch (e) {
                // fallback
                const $tmp = $('<textarea>').val(text).appendTo('body').select();
                try { document.execCommand('copy'); Swal.fire({ toast: true, position: 'top-end', timer: 1200, showConfirmButton: false, icon: 'success', title: 'Copied' }); } catch (er) { Swal.fire({ icon: 'info', title: 'Copy', text: 'Unable to copy.' }); }
                $tmp.remove();
            }
        }

        function hidePartnerModal() {
            $('#partnerModalOverlay').fadeOut(120);
            $('#partnerModal').fadeOut(140);
            $('#partnerModal').attr('aria-hidden', 'true');
        }

        function showModalById(id) {
            if (window.bootstrap && bootstrap.Modal) {
                bootstrap.Modal.getOrCreateInstance(document.getElementById(id)).show();
                return;
            }
            if (window.jQuery && typeof $('#' + id).modal === 'function') {
                $('#' + id).modal('show');
                return;
            }
            $('#' + id).addClass('show').css('display', 'block').attr('aria-modal', 'true').removeAttr('aria-hidden');
            $('body').addClass('modal-open').append('<div class="modal-backdrop fade show"></div>');
        }

        function hideModalById(id) {
            if (window.bootstrap && bootstrap.Modal) {
                bootstrap.Modal.getOrCreateInstance(document.getElementById(id)).hide();
                return;
            }
            if (window.jQuery && typeof $('#' + id).modal === 'function') {
                $('#' + id).modal('hide');
                return;
            }
            $('#' + id).removeClass('show').css('display', 'none').removeAttr('aria-modal').attr('aria-hidden', 'true');
            $('.modal-backdrop').remove();
            $('body').removeClass('modal-open');
        }

        function renderNewPartnerDeveloperTable(rows) {
            const $header = $('#newPartnerDeveloperHeaderRow');
            const $body = $('#newPartnerDeveloperTableBody');
            $header.html(developerColumns.map((c) => `<th>${escapeHtml(c)}</th>`).join(''));
            if (!rows.length) {
                $body.html(`<tr><td colspan="${developerColumns.length}" class="text-center">No records found.</td></tr>`);
                return;
            }
            const html = rows.map((row) => `<tr>${developerColumns.map((c) => `<td>${escapeHtml(row[c] ?? '')}</td>`).join('')}</tr>`).join('');
            $body.html(html);
        }

        function renderExistedPartnerDeveloperTable(items) {
            const $header = $('#existedPartnerDeveloperHeaderRow');
            const $body = $('#existedPartnerDeveloperTableBody');
            $header.html(`<th>Source</th>${developerColumns.map((c) => `<th>${escapeHtml(c)}</th>`).join('')}`);
            if (!items.length) {
                $body.html(`<tr><td colspan="${developerColumns.length + 1}" class="text-center">No records found.</td></tr>`);
                return;
            }
            let html = '';
            items.forEach((item) => {
                const db = item.db || {};
                const csv = item.csv || {};
                html += '<tr><td><strong>Database</strong></td>';
                developerColumns.forEach((col) => {
                    const matched = String(db[col] ?? '').toLowerCase() === String(csv[col] ?? '').toLowerCase();
                    const cls = matched ? 'table-success' : 'table-danger';
                    html += `<td class="${cls}">${escapeHtml(db[col] ?? '')}</td>`;
                });
                html += '</tr>';
                html += '<tr><td><strong>CSV File</strong></td>';
                developerColumns.forEach((col) => {
                    const matched = String(db[col] ?? '').toLowerCase() === String(csv[col] ?? '').toLowerCase();
                    const cls = matched ? 'table-success' : 'table-warning';
                    html += `<td class="${cls}">${escapeHtml(csv[col] ?? '')}</td>`;
                });
                html += '</tr>';
            });
            $body.html(html);
        }

        // click handlers to open/close modal
        $(document).on('click', '#partnerTable tbody tr', function () {
            const pid = $(this).attr('data-partner-id');
            if (!pid) return;
            const partner = allPartners.find(function(p) { return String(p.partner_id) === String(pid) || String(p.id) === String(pid); });
            if (partner) showPartnerModal(partner);
        });

        $('#partnerModalClose, #partnerModalCloseBtn, #partnerModalOverlay').on('click', function () { hidePartnerModal(); });

        function loadPartnerTableData() {
            $.ajax({
                url: 'masterfile-partner-list.php',
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'generate_partner_list'
                },
                beforeSend: function () {
                    $loadingOverlay.show();
                },
                success: function (response) {
                    if (response && response.status === 'success' && Array.isArray(response.data)) {
                        allPartners = response.data;
                        filteredPartners = allPartners.slice();
                        currentPage = 1;
                        updateSuggestions(allPartners);
                        renderTableRowsPaged();
                    } else {
                        allPartners = [];
                        filteredPartners = [];
                        updateSuggestions(allPartners);
                        renderTableRowsPaged();
                    }
                },
                error: function () {
                    allPartners = [];
                    filteredPartners = [];
                    updateSuggestions(allPartners);
                    renderTableRowsPaged();

                    Swal.fire({
                        icon: 'error',
                        title: 'Load Failed',
                        text: 'Unable to load partner list. Please try again.'
                    });
                },
                complete: function () {
                    $loadingOverlay.hide();
                }
            });
        }

        // Mode card switching with template toggling
        function setMode(mode) {
            $('.mode-card').removeClass('selected');
            $('.mode-card[data-mode="' + mode + '"]').addClass('selected');

            if (mode === 'manual') {
                $('#manualUploadSection').hide();
                $('#manualEditSection').show();
            } else {
                $('#manualEditSection').hide();
                $('#manualUploadSection').show();
                // populate upload partner datalist if empty
                if (!$('#uploadPartnerList option').length && allPartners.length) {
                    populateUploadPartnerList();
                }
            }
        }

        const initialMode = <?php echo json_encode($defaultPartnerMode); ?>;
        setMode(initialMode === 'auto' ? 'auto' : 'manual');

        $(document).on('click', '.mode-card', function () {
            var $card = $(this);
            if ($card.hasClass('selected')) return;
            var mode = $card.data('mode');
            var radioValue = mode === 'manual' ? 'manualedit' : 'manualupload';
            $('input[name="partnerMode"]').prop('checked', false);
            $('input[name="partnerMode"][value="' + radioValue + '"]').prop('checked', true);
            setMode(mode);
        });

        function populateUploadPartnerList() {
            var names = [...new Set(allPartners.map(function(p) {
                return (p.partner_name || '').trim();
            }))].filter(function(n) { return n !== ''; }).sort();
            var html = names.map(function(n) {
                return '<option value="' + escapeHtml(n) + '">';
            }).join('');
            $('#uploadPartnerList').html(html);
        }

        // Handle upload form submission
        $('#partnerUploadForm').on('submit', function (e) {
            var fileInput = $('#uploadFileInput')[0];
            if (!fileInput.files.length) {
                e.preventDefault();
                Swal.fire({ icon: 'warning', title: 'Missing File', text: 'Please select an Excel file to upload.' });
                return;
            }
            var newCount = parseInt($('#newPartnerCount').text(), 10) || 0;
            var existedCount = parseInt($('#existedPartnerCount').text(), 10) || 0;
            if ((newCount + existedCount) > 0) {
                $('#submitBtn').show();
            } else {
                $('#submitBtn').hide();
            }
            // Show loading while form submits
            $loadingOverlay.show();
        });

        $searchInput.on('input', filterAndRender);

        $('#viewNewPartnerDeveloperListBtn').on('click', function () {
            renderNewPartnerDeveloperTable(newPartnersDeveloperData);
            showModalById('newPartnerDeveloperListModal');
        });
        $('#viewExistedPartnerDeveloperListBtn').on('click', function () {
            renderExistedPartnerDeveloperTable(existedPartnersDeveloperData);
            showModalById('existedPartnerDeveloperListModal');
        });

        $('#newPartnerDeveloperSearchInput').on('input', function () {
            const keyword = ($(this).val() || '').toLowerCase().trim();
            const filtered = !keyword ? newPartnersDeveloperData : newPartnersDeveloperData.filter((row) =>
                developerColumns.some((c) => String(row[c] ?? '').toLowerCase().includes(keyword))
            );
            renderNewPartnerDeveloperTable(filtered);
        });

        $('#existedPartnerDeveloperSearchInput').on('input', function () {
            const keyword = ($(this).val() || '').toLowerCase().trim();
            const filtered = !keyword ? existedPartnersDeveloperData : existedPartnersDeveloperData.filter((item) => {
                const db = item.db || {};
                const csv = item.csv || {};
                return developerColumns.some((c) =>
                    String(db[c] ?? '').toLowerCase().includes(keyword) || String(csv[c] ?? '').toLowerCase().includes(keyword)
                );
            });
            renderExistedPartnerDeveloperTable(filtered);
        });

        $('#newPartnerDeveloperListModal .btn-close').on('click', function () {
            hideModalById('newPartnerDeveloperListModal');
        });
        $('#existedPartnerDeveloperListModal .btn-close').on('click', function () {
            hideModalById('existedPartnerDeveloperListModal');
        });

        $('#submitBtn').on('click', function () {
            $('#partnerUploadSubmitForm').trigger('submit');
        });

        <?php if ($uploadSaveResult): ?>
        <?php if (($uploadSaveResult['status'] ?? '') === 'success'): ?>
        Swal.fire({
            icon: 'success',
            title: 'Partner Upload Saved',
            html: 'Inserted: <b><?php echo (int)($uploadSaveResult['inserted'] ?? 0); ?></b><br>Updated: <b><?php echo (int)($uploadSaveResult['updated'] ?? 0); ?></b>'
        });
        <?php else: ?>
        Swal.fire({
            icon: 'error',
            title: 'Save Failed',
            text: <?php echo json_encode($uploadSaveResult['message'] ?? 'Unknown error'); ?>
        });
        <?php endif; ?>
        <?php endif; ?>

        loadPartnerTableData();
    });

 </script>
</html>
