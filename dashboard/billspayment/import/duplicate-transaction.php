<?php
// Duplicate Transaction Checker
ob_start();
require_once __DIR__ . '/../../../config/config.php';
require '../../../vendor/autoload.php';
session_start();
@include_once __DIR__ . '/../../../templates/middleware.php';
$id = resolve_user_identifier();
if (empty($id)) { header('Location: ../../../login_form.php'); exit; }
if (!function_exists('has_any_permission') || !has_any_permission(['Duplicate Transaction','Bills Payment'])) { header('Location: ../../home.php'); exit; }

// simple user email for permission checks
$current_user_email = '';
// prefer explicit session values for current user email
$current_user_email = $_SESSION['admin_email'] ?? $_SESSION['user_email'] ?? '';

// AJAX: find duplicate groups in billspayment_transaction
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['check_duplicates_db'])) {
    if (ob_get_length()) {
        ob_clean();
    }
    header('Content-Type: application/json');
    $mode = isset($_POST['mode']) ? trim($_POST['mode']) : 'normal';

    // Normal mode: sequential scan (read rows in deterministic order and compare adjacent rows)
    if ($mode === 'normal') {
        $results = [];

        $sql = "SELECT id, reference_no, datetime, partner_id, partner_name, partner_id_kpx, amount_paid, payor, branch_id, imported_by, imported_date, status
                FROM billspayment_transaction
                ORDER BY reference_no, partner_id, amount_paid, datetime, id";

        $res = $conn->query($sql);
        if ($res && $res->num_rows > 0) {
            $currentGroup = [];
            $prevKey = null;

            while ($row = $res->fetch_assoc()) {
                $ref = isset($row['reference_no']) ? $row['reference_no'] : '';
                // normalize date portion (YYYY-MM-DD) for comparison
                $date = isset($row['datetime']) && $row['datetime'] !== null ? substr($row['datetime'], 0, 10) : '';
                $partner = isset($row['partner_id']) ? $row['partner_id'] : '';
                $amount = isset($row['amount_paid']) ? $row['amount_paid'] : '';

                $key = $ref . '|' . $date . '|' . $partner . '|' . $amount;

                if ($prevKey === null) {
                    // first row
                    $currentGroup = [$row];
                    $prevKey = $key;
                } elseif ($key === $prevKey) {
                    // same group, append
                    $currentGroup[] = $row;
                } else {
                    // boundary: flush if duplicate group (more than 1 row)
                    if (count($currentGroup) > 1) {
                        $grpRef = isset($currentGroup[0]['reference_no']) ? $currentGroup[0]['reference_no'] : '';
                        $grpDate = isset($currentGroup[0]['datetime']) ? substr($currentGroup[0]['datetime'], 0, 10) : '';
                        $grpPartner = isset($currentGroup[0]['partner_id']) ? $currentGroup[0]['partner_id'] : '';
                        $results[] = ['group_key' => $grpRef . '|' . $grpDate . '|' . $grpPartner, 'rows' => $currentGroup];
                    }
                    // start new group
                    $currentGroup = [$row];
                    $prevKey = $key;
                }
            }

            // flush last group
            if (!empty($currentGroup) && count($currentGroup) > 1) {
                $grpRef = isset($currentGroup[0]['reference_no']) ? $currentGroup[0]['reference_no'] : '';
                $grpDate = isset($currentGroup[0]['datetime']) ? substr($currentGroup[0]['datetime'], 0, 10) : '';
                $grpPartner = isset($currentGroup[0]['partner_id']) ? $currentGroup[0]['partner_id'] : '';
                $results[] = ['group_key' => $grpRef . '|' . $grpDate . '|' . $grpPartner, 'rows' => $currentGroup];
            }
        }

        echo json_encode(['success' => true, 'mode' => 'normal', 'groups' => $results]);
        exit;
    }

    // Dev mode: return all rows for reference_no's that have more than one occurrence
    if ($mode === 'dev') {
        $results = [];
        // use fully-qualified table name for clarity
        $sql = "SELECT t.* FROM mldb.billspayment_transaction t
                 JOIN (
                   SELECT reference_no
                   FROM mldb.billspayment_transaction
                   GROUP BY reference_no
                   HAVING COUNT(*) > 1
                 ) dup ON t.reference_no = dup.reference_no
                 ORDER BY t.reference_no, t.id";
        $res = $conn->query($sql);
        if ($res && $res->num_rows > 0) {
            while ($row = $res->fetch_assoc()) {
                $ref = $row['reference_no'];
                if (!isset($results[$ref])) $results[$ref] = [];
                $results[$ref][] = $row;
            }
        }
        // convert to array of groups
        $out = [];
        foreach ($results as $ref => $rows) {
            $out[] = ['reference_no' => $ref, 'rows' => $rows];
        }
        echo json_encode(['success' => true, 'mode' => 'dev', 'groups' => $out]);
        exit;
    }

    // Summary mode: global duplicates by reference_no (used when legacy grouping finds nothing)
    if ($mode === 'summary') {
        $summary = [];
        // Exclude rows where status = '*' (cancellation footprint) from summary counts
        $sql = "SELECT reference_no, COUNT(*) AS total_count
                FROM mldb.billspayment_transaction
                WHERE COALESCE(status, '') <> '*'
                GROUP BY reference_no
                HAVING COUNT(*) > 1
                ORDER BY total_count DESC";
        $res = $conn->query($sql);
        if ($res && $res->num_rows > 0) {
            while ($r = $res->fetch_assoc()) {
                $summary[] = ['reference_no' => $r['reference_no'], 'total_count' => intval($r['total_count'])];
            }
        }
        echo json_encode(['success' => true, 'mode' => 'summary', 'groups' => $summary]);
        exit;
    }

    // default fallback: behave like legacy grouping (reference+date+partner+amount)
    $results = [];
    $groupSql = "SELECT reference_no, DATE(datetime) AS dt, partner_id, amount_paid, COUNT(*) AS cnt
                 FROM billspayment_transaction
                 GROUP BY reference_no, DATE(datetime), partner_id, amount_paid
                 HAVING cnt > 1
                 ORDER BY reference_no, dt";
    $groups = $conn->query($groupSql);
    if ($groups && $groups->num_rows > 0) {
        while ($g = $groups->fetch_assoc()) {
            // fetch all rows for this group
            $ref = $conn->real_escape_string($g['reference_no']);
            $dt = $g['dt'];
            $partner = $conn->real_escape_string($g['partner_id']);
            $amount = $g['amount_paid'];

            $rowsSql = "SELECT id, reference_no, datetime, partner_id, partner_name, partner_id_kpx, amount_paid, payor, branch_id, imported_by, imported_date
                        FROM billspayment_transaction
                        WHERE reference_no = '" . $ref . "' AND DATE(datetime) = '" . $dt . "' AND partner_id = '" . $partner . "' AND amount_paid = '" . $amount . "'
                        ORDER BY id ASC";

            $rowsRes = $conn->query($rowsSql);
            $rows = [];
            if ($rowsRes && $rowsRes->num_rows > 0) {
                while ($r = $rowsRes->fetch_assoc()) {
                    $rows[] = $r;
                }
            }

            if (!empty($rows)) {
                $results[] = ['group_key' => $g['reference_no'] . '|' . $g['dt'] . '|' . $g['partner_id'], 'rows' => $rows];
            }
        }
    }

    echo json_encode(['success' => true, 'mode' => 'legacy', 'groups' => $results]);
    exit;
}

// AJAX: delete single duplicate row
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_duplicate']) && !empty($_POST['id'])) {
    if (ob_get_length()) {
        ob_clean();
    }
    header('Content-Type: application/json');
    $id = intval($_POST['id']);
    $del = $conn->query("DELETE FROM billspayment_transaction WHERE id = " . $id);
    if ($del) echo json_encode(['success' => true]); else echo json_encode(['success' => false, 'error' => $conn->error]);
    exit;
}

// AJAX: delete multiple duplicate ids
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_multiple'])) {
    if (ob_get_length()) {
        ob_clean();
    }
    header('Content-Type: application/json');

    $idsInput = isset($_POST['ids']) ? $_POST['ids'] : [];
    if (!is_array($idsInput)) {
        $idsInput = explode(',', (string)$idsInput);
    }

    $clean = [];
    foreach ($idsInput as $raw) {
        $id = intval($raw);
        if ($id > 0) {
            $clean[$id] = $id;
        }
    }

    if (empty($clean)) {
        echo json_encode(['success' => false, 'error' => 'No ids']);
        exit;
    }

    $in = implode(',', $clean);
    $del = $conn->query("DELETE FROM billspayment_transaction WHERE id IN (" . $in . ")");
    if ($del) {
        echo json_encode(['success' => true, 'deleted_count' => intval($conn->affected_rows)]);
    } else {
        echo json_encode(['success' => false, 'error' => $conn->error]);
    }
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Duplicate Checker - Transaction</title>
    <link rel="stylesheet" href="../../../assets/css/templates/style.css?v=<?php echo time(); ?>">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://kit.fontawesome.com/30b908cc5a.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.9.3/html2pdf.bundle.min.js"></script>
    <link rel="icon" href="../../../images/MLW logo.png" type="image/png">
    <style>
           /* Loading Overlay */
        #loading-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.7);
            z-index: 9999;
            justify-content: center;
            align-items: center;
        }

        .loading-spinner {
            border: 5px solid #f3f3f3;
            border-top: 5px solid #dc3545;
            border-radius: 50%;
            width: 60px;
            height: 60px;
            animation: spin 1s linear infinite;
        }
          @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        #loading-overlay{ position:fixed; inset:0; background:rgba(0,0,0,0.35); display:none; align-items:center; justify-content:center; z-index:20000 }
        .modal-card{ background:#fff; border-radius:10px; padding:14px; width:100%; max-width:100%; max-height:85vh; overflow:auto; box-sizing:border-box; }
        .dup-row{ border-radius:8px; padding:12px; margin-bottom:8px; display:flex; justify-content:space-between; align-items:center }
        .dup-row.green{ background:#e6ffed; border:1px solid #b6f0c1 }
        .dup-row.red{ background:#fff2f2; border:1px solid #f5bcbc }
        .dup-actions button{ background:transparent; border:none; cursor:pointer; font-size:18px; color:#212529; padding:6px; border-radius:6px }
        .dup-actions button:hover { color:#dc3545; background: rgba(220,53,69,0.06); }
        .controls { display:flex; gap:8px; align-items:center }
        #btn-delete-all { background:#6c757d; color:#fff; border:none; padding:8px 14px; border-radius:8px; font-weight:700; transition:all 160ms ease; cursor:pointer }
        #btn-delete-all:hover { background:#5a6268; transform:translateY(-1px); color:#fff }
        /* Dev mode cell coloring */
        .cell-green{ background:#e6ffed !important; }
        .cell-red{ background:#fff2f2 !important; }
        /* Dev mode card containers */
        .dev-group-card { 
            margin-bottom:16px; 
            background:#fff; 
            border:1px solid #e9ecef; 
            border-radius:10px; 
            box-shadow: 0 2px 4px rgba(0,0,0,0.04);
            overflow:hidden;
        }
        .dev-group-header {
            padding:12px 16px;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-bottom:1px solid #dee2e6;
            font-weight:700;
            color:#212529;
        }
        .dev-group-body {
            padding:0;
            overflow-x:auto;
            overflow-y:visible;
        }
        /* Dev mode table */
        .dev-table { 
            width:100%; 
            border-collapse:collapse; 
            font-size:12px;
            min-width: 100%;
            border: 1px solid #e6e6e6;
        }
        .dev-table thead th { 
            padding:10px 8px; 
            background:#fff;
            border: 1px solid #e9ecef;
            font-weight:700; 
            text-align:left;
            white-space:nowrap;
            position:sticky;
            top:0;
            z-index:2;
        }
        .dev-table tbody td { 
            padding:8px; 
            border: 1px solid #e9ecef; 
            vertical-align:top;
            max-width:200px;
            overflow:hidden;
            text-overflow:ellipsis;
        }
        .dev-table tbody tr:hover { background:#f8f9fa; }
        .dev-table .action-col { width:60px; text-align:center; white-space:nowrap; }
        .dev-delete-btn { 
            background:transparent; 
            border:none; 
            color:#6c757d; 
            cursor:pointer; 
            padding:6px; 
            border-radius:6px;
            font-size:16px;
        }
        .dev-delete-btn:hover { background:#fff2f2; color:#dc3545; }
        /* Mode toggle styling */
        .mode-toggle { display:inline-flex; background:#fff; border:1px solid #e9ecef; border-radius:8px; overflow:hidden; }
        .mode-toggle .mode-btn { padding:6px 12px; border:0; background:transparent; cursor:pointer; font-weight:700; color:#495057; }
        .mode-toggle .mode-btn.active { background:#dc3545; color:#fff; }
        /* Dev table improvements */
        .group-table { border:1px solid #e6e6e6; border-collapse:collapse; }
        .group-table thead th { position: sticky; top: 0; background: #fff; z-index: 3; border:1px solid #e9ecef; }
        .group-table tbody td { border:1px solid #e9ecef; padding:8px; }
        .dev-cell { white-space:nowrap; max-width:200px; overflow:hidden; text-overflow:ellipsis; }
        .dup-actions .btn-compare { background:transparent; border:none; color:#6c757d; cursor:pointer; padding:6px; border-radius:6px; font-size:16px; }
        .dup-actions .btn-compare:hover { background:#e9f2ff; color:#0d6efd; }
        .dev-group-card.dev-highlight { box-shadow: 0 0 0 4px rgba(13,110,253,0.12); border-color:#0d6efd; transition:box-shadow 240ms ease; }
        .dev-ref-highlight { background: #fff3cd; color: #856404; padding:2px 6px; border-radius:4px; }
    </style>
</head>
<body>
    <?php include '../../../templates/header_ui.php'; ?>
    <?php include '../../../templates/sidebar.php'; ?>

    <div style="padding:18px;">
        <?php bp_section_header_html('fa-solid fa-code-compare', 'Duplicate Checker', 'Transaction duplicates in the database'); ?>

        <div style="margin-top:12px; display:flex; align-items:center; gap:12px;">
            <div class="controls" style="display:flex; gap:8px; align-items:center;">
                <button id="btn-check" class="btn-proceed">Check Duplicates</button>
                <button id="btn-export" class="btn-proceed" style="display:none;background:#0d6efd;color:#fff;margin-left:6px;">Export</button>
                <button id="btn-delete-all" class="btn-proceed" style="display:none;background:#6c757d;">Delete All Duplicates</button>
            </div>
            <div style="margin-left:auto; display:flex; flex-direction:column; align-items:flex-end; gap:6px;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <label style="font-weight:700; color:#495057; margin-right:6px;">Mode:</label>
                    <div id="mode-toggle" class="mode-toggle" role="tablist" aria-label="Duplicate checker mode">
                        <button type="button" class="mode-btn active" data-mode="normal" aria-pressed="true">Normal Mode</button>
                        <button type="button" class="mode-btn" data-mode="dev" aria-pressed="false">Dev Mode</button>
                    </div>
                </div>
                <div style="width:100%; display:flex; justify-content:flex-end;">
                    <div id="dev-include-container" style="display:none; align-items:center;">
                        <label style="font-weight:700; color:#495057; margin-right:6px; font-size:13px;">Show:</label>
                        <select id="dev-include" style="padding:6px;border-radius:6px;border:1px solid #e9ecef;font-weight:700;">
                            <option value="all">All</option>
                            <option value="cancelled">No Cancelled</option>
                        </select>
                        <input id="dev-search" placeholder="Search Reference No." style="margin-left:8px;padding:6px;border-radius:6px;border:1px solid #e9ecef;width:240px;font-weight:700;display:inline-block;" />
                    </div>
                </div>
            </div>
        </div>
        <div id="result-count" style="margin-top:10px;color:#6c757d"></div>
    </div>

    <div id="loading-overlay">
            <div class="loading-spinner"></div>
        </div>

    <!-- Duplicates container (inline under the result-count) -->
    <div id="duplicates-container" style="display:block; width:100%;">
        <div class="modal-card" id="normal-card" style="max-height:calc(100vh - 110px); width:100%; max-width:100%; display:none;"> </div>
        <div class="modal-card" id="dev-card" style="max-height:calc(100vh - 110px); width:100%; max-width:100%; display:none;"> </div>
    </div>

    <script>
        // Renderers for Normal and Dev modes
        // Format a date string to "Month dd, yyyy" (e.g. January 01, 2026)
        function formatLongDate(val){
            if(!val && val !== 0) return '';
            try{
                var s = String(val).trim();
                if(s === '') return '';
                // convert common SQL datetime 'YYYY-MM-DD HH:MM:SS' to ISO 'YYYY-MM-DDTHH:MM:SS'
                if(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/.test(s)){
                    s = s.replace(' ', 'T');
                }
                // If it's just date 'YYYY-MM-DD' it's parseable
                var d = new Date(s);
                if(isNaN(d.getTime())) return val;
                return d.toLocaleDateString('en-US', { month: 'long', day: '2-digit', year: 'numeric' });
            } catch(e){ return val; }
        }
        // ensure removeSummaryIcon exists in global scope (used by renderers)
        function removeSummaryIcon(){
            try{
                console.debug('[dup-check] removeSummaryIcon called');
                $('#btn-summary').remove();
            } catch(e){ console.debug('[dup-check] removeSummaryIcon error', e); }
        }
        function renderNormal(groups){
            console.debug('[dup-check] renderNormal called, groups count:', groups ? groups.length : 0);
            if(!groups || groups.length === 0){
                $('#normal-card').html('<div style="padding:10px;color:#6c757d">No duplicates found.</div>');
                $('#btn-delete-all').hide();
                $('#result-count').text('');
                return;
            }
            let html = '<div style="overflow:auto"><table class="group-table"><thead><tr><th style="width:60%">Reference No.</th><th style="width:20%">Duplicate Count</th></tr></thead><tbody>';
            groups.forEach(function(g){
                html += '<tr><td>' + (g.reference_no||'') + '</td><td style="text-align:right">' + (g.count||0) + '</td></tr>';
            });
            html += '</tbody></table></div>';
            $('#normal-card').html(html);
            $('#result-count').text('Found ' + groups.length + ' reference_no(s) with duplicates.');
            $('#btn-delete-all').hide();
            // hide export when not in Dev mode
            $('#btn-export').hide();
            setTimeout(function(){ var el = document.getElementById('normal-card'); if(el) el.scrollIntoView({behavior:'smooth', block:'start'}); }, 60);
        }

        // Legacy detailed renderer (used by restored Normal mode)
        function renderLegacy(groups){
            console.debug('[dup-check] renderLegacy called, groups count:', groups ? groups.length : 0);
            removeSummaryIcon();
            window.lastNormalDuplicateIds = [];
            if(!groups || groups.length === 0){
                $('#normal-card').html('<div style="padding:10px;color:#6c757d">No duplicates found.</div>');
                $('#btn-delete-all').hide();
                $('#result-count').text('');
                return;
            }
            let html = '';
            let totalDuplicates = 0;
            const duplicateIds = [];
            groups.forEach(function(g){
                const rows = g.rows || [];
                if(rows.length === 0) return;
                html += '<div style="margin-bottom:8px;font-weight:700;">Reference No.: '+ (rows[0].reference_no || '') +'</div>';
                rows.forEach(function(r, idx){
                    const cls = idx === 0 ? 'green' : 'red';
                    if(idx > 0){
                        totalDuplicates++;
                        const rowId = parseInt(r.id, 10);
                        if(!isNaN(rowId) && rowId > 0) duplicateIds.push(rowId);
                    }
                    const partnerName = r.partner_name || '';
                    const partnerKpx = r.partner_id_kpx || '';
                    const partnerId = r.partner_id || '';
                          const amount = (typeof r.amount_paid !== 'undefined' && r.amount_paid !== null && r.amount_paid !== '') ? ('₱' + Number(r.amount_paid).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2})) : '';
                          const formattedDate = formatLongDate(r.datetime || '');
                          var actionHtml = '';
                          if(idx > 0){
                              actionHtml = '<button class="btn-delete" title="Delete" data-id="'+r.id+'"><i class="fa-solid fa-trash"></i></button>';
                          } else {
                              actionHtml = '<button class="btn-compare" title="Compare in Dev Mode" data-ref="'+ (r.reference_no || '') +'"><i class="fa-solid fa-arrow-right"></i></button>';
                          }
                          html += '<div class="dup-row '+cls+'" data-id="'+r.id+'">'
                                + '<div><div><strong>'+ (r.reference_no || '') +'</strong></div>'
                                + '<div style="font-size:12px;color:#6c757d">'
                                    + '<span title="Datetime">'+ formattedDate +'</span> • '
                                    + '<span title="Partner Name">'+ partnerName +'</span> • '
                                    + '<span title="Partner ID">'+ partnerId +'</span> • '
                                    + '<span title="Partner KPX ID">'+ partnerKpx +'</span> • '
                                    + '<span title="Amount">'+ amount +'</span>'
                                    + ' • <span title="Imported By">Imported by: '+ (r.imported_by || '') +'</span>'
                                    + ' • <span title="Imported Date">'+ formatLongDate(r.imported_date || '') +'</span>'
                                + '</div></div>'
                                + '<div class="dup-actions">' + actionHtml + '</div>'
                                + '</div>';
                });
                html += '<hr>';
            });
            window.lastNormalDuplicateIds = Array.from(new Set(duplicateIds));
            $('#normal-card').html(html);
            $('#result-count').text('Found '+ totalDuplicates +' duplicate row(s).');
            if(window.lastNormalDuplicateIds.length>0) $('#btn-delete-all').show(); else $('#btn-delete-all').hide();
            // hide export when showing legacy view
            $('#btn-export').hide();
            setTimeout(function(){ var el = document.getElementById('normal-card'); if(el) el.scrollIntoView({behavior:'smooth', block:'start'}); }, 60);
        }

        function renderDev(groups){
            console.debug('[dup-check] renderDev called, groups count:', groups ? groups.length : 0);
            removeSummaryIcon();
            // store raw dev groups for re-filtering later
            window.lastDevGroupsRaw = groups || [];

            if(!groups || groups.length === 0){
                $('#dev-card').html('<div style="padding:10px;color:#6c757d">No duplicates found.</div>');
                $('#btn-delete-all').hide();
                $('#btn-export').hide();
                $('#result-count').text('');
                window.lastDevGroups = [];
                return;
            }
            // read include filter: 'all' or 'cancelled' (when 'cancelled' we exclude rows with status='*')
            const includeMode = ($('#dev-include').length ? $('#dev-include').val() : 'all') || 'all';
            // read search term (reference no) for quick filtering
            const searchTerm = ($('#dev-search').length ? String($('#dev-search').val() || '').trim().toLowerCase() : '');
            // columns to compare (in requested order)
            const columns = [
                'id','status','billing_invoice','datetime','cancellation_date','source_file','control_no','reference_no','payor','address','account_no','account_name','amount_paid','charge_to_customer','charge_to_partner','contact_no','other_details','branch_id','branch_code','outlet','zone_code','region_code','region','operator','remote_branch','remote_operator','2nd_approver','partner_name','partner_id','partner_id_kpx','mpm_gl_code','settle_unsettle','claim_unclaim','imported_by','imported_date','rfp_no','cad_no','hold_status','post_transaction'
            ];

            let html = '';
            let totalDupRows = 0;
            const filteredGroups = [];

            groups.forEach(function(g){
                const rows = Array.isArray(g.rows) ? g.rows.slice() : [];
                if(rows.length === 0) return;

                // apply include filter: if includeMode === 'cancelled', exclude status === '*'
                const rowsFiltered = (includeMode === 'cancelled') ? rows.filter(function(r){ return String(r.status || '').trim() !== '*'; }) : rows.slice();
                // only treat as duplicate group if there are at least 2 rows after filtering
                if(!rowsFiltered || rowsFiltered.length < 2) return;

                // if searchTerm provided, only include groups whose reference_no contains the term
                // or whose rows contain a matching id (allow searching by id or reference)
                if(searchTerm){
                    const refMatch = String(g.reference_no || '').toLowerCase().indexOf(searchTerm) !== -1;
                    const idMatch = rowsFiltered.some(function(r){ return String(r.id || '').indexOf(searchTerm) !== -1; });
                    if(!refMatch && !idMatch) return;
                }
                filteredGroups.push({ reference_no: g.reference_no, rows: rowsFiltered });

                // Card container for each reference_no group (store encoded ref)
                html += '<div class="dev-group-card" data-ref="' + encodeURIComponent(g.reference_no || '') + '">';
                
                // Card header
                html += '<div class="dev-group-header">';
                html += 'Reference No.: <strong>' + (g.reference_no||'') + '</strong>';
                html += ' &nbsp;<span style="color:#6c757d;font-weight:400;">(' + rowsFiltered.length + ' rows)</span>';
                html += '</div>';
                
                // Card body with table
                html += '<div class="dev-group-body">';

                // determine uniformity per column
                const uniform = {};
                columns.forEach(function(col){
                    const vals = new Set(rows.map(r => (typeof r[col] === 'undefined' || r[col] === null) ? '' : String(r[col])));
                    uniform[col] = (vals.size === 1);
                });

                // build table
                html += '<table class="dev-table">';
                html += '<thead><tr>';
                html += '<th class="action-col">Action</th>'; // DELETE ICON COLUMN FIRST
                columns.forEach(function(col){ html += '<th>' + col + '</th>'; });
                html += '</tr></thead><tbody>';

                rowsFiltered.forEach(function(r, idx){
                    html += '<tr data-id="'+(r.id||'')+'">';
                    
                    // Action column (delete icon) - LEFTMOST
                    html += '<td class="action-col">';
                    html += '<button class="dev-delete-btn btn-delete" title="Delete this row" data-id="'+r.id+'">';
                    html += '<i class="fa-solid fa-trash"></i>';
                    html += '</button>';
                    html += '</td>';
                    
                    // Data columns with green/red coloring
                    columns.forEach(function(col){
                        var raw = (typeof r[col] === 'undefined' || r[col] === null) ? '' : String(r[col]);
                        var display = raw;
                        // format date-like columns
                        if(col === 'datetime' || col.toLowerCase().includes('date')){
                            display = formatLongDate(raw);
                        }
                        const cls = uniform[col] ? 'cell-green' : 'cell-red';
                        html += '<td class="'+cls+'" title="'+$('<div>').text(display).html()+'">' + $('<div>').text(display).html() + '</td>';
                    });
                    
                    html += '</tr>';
                    if(idx>0) totalDupRows++;
                });
                html += '</tbody></table>';
                html += '</div>'; // dev-group-body
                html += '</div>'; // dev-group-card
            });

            $('#dev-card').html(html);
            $('#result-count').text('Found '+ totalDupRows +' duplicate row(s) across ' + filteredGroups.length + ' reference number(s).');
            // HIDE bulk delete button in Dev Mode - manual deletion only
            $('#btn-delete-all').hide();
            // Show Export button in Dev Mode only when there are filtered groups
            if(filteredGroups.length > 0) $('#btn-export').show(); else $('#btn-export').hide();
            // store last dev groups for export (filtered) and raw
            window.lastDevGroups = filteredGroups;
            setTimeout(function(){ var el = document.getElementById('dev-card'); if(el) el.scrollIntoView({behavior:'smooth', block:'start'}); }, 60);
        }

        function showOverlay(){ $('#loading-overlay').css('display','flex'); }
        function hideOverlay(){ $('#loading-overlay').hide(); }

        $(function(){
            $('#btn-check').on('click', function(){
                showOverlay();
                // read mode from the toggle buttons
                const mode = $('#mode-toggle .mode-btn.active').data('mode') || 'normal';
                const target = (mode === 'dev') ? '#dev-card' : '#normal-card';
                $(target).html('Checking duplicates...');
                console.debug('[dup-check] initiating check, mode:', mode);
                // measure latency
                var _dup_start = (typeof performance !== 'undefined' && performance.now) ? performance.now() : Date.now();
                $.post(window.location.href, { check_duplicates_db: 1, mode: mode }, function(resp){
                        console.debug('[dup-check] check_duplicates response', resp);
                        if(resp && resp.success){
                                // Normal (legacy) - if no groups found, request global summary
                                    if(resp.mode === 'normal'){
                                        if(!resp.groups || resp.groups.length === 0){
                                            // request summary counts
                                            // clear the checking text
                                            $('#normal-card').html('');
                                            requestSummary();
                                        } else {
                                            removeSummaryIcon();
                                            renderLegacy(resp.groups);
                                        }
                                    }
                                    else if(resp.mode === 'dev') { removeSummaryIcon(); renderDev(resp.groups); }
                                    else renderNormal(resp.groups);
                        } else { $('#modal-card').html('<div style="padding:10px;color:#c00">Error occurred</div>'); }
                    hideOverlay();
                }, 'json')
                .fail(function(jqxhr, status, err){
                    console.debug('[dup-check] request failed', status, err);
                    var t = ($('#mode-toggle .mode-btn.active').data('mode') === 'dev') ? '#dev-card' : '#normal-card';
                    $(t).html('<div style="padding:10px;color:#c00">Request failed</div>');
                    hideOverlay();
                })
                .always(function(){
                    try{
                        var _end = (typeof performance !== 'undefined' && performance.now) ? performance.now() : Date.now();
                        var _lat = (_end - _dup_start);
                        console.info('[dup-check] latency: ' + _lat.toFixed(2) + ' ms');
                    }catch(e){ console.debug('[dup-check] latency calc error', e); }
                });
            });

                // request summary (global reference_no counts)
                function requestSummary(){
                    $.post(window.location.href, { check_duplicates_db: 1, mode: 'summary' }, function(sr){
                        if(sr && sr.success && sr.groups && sr.groups.length>0){
                            // show yellow question icon/button next to Check Duplicates
                            showSummaryIcon(sr.groups);
                            // Inform the user that a summary is available
                            $('#normal-card').html('<div style="padding:10px;color:#6c757d">No grouped duplicates found. A global summary is available (click the yellow icon).</div>');
                            $('#result-count').text('0 duplicates found');
                        } else {
                            removeSummaryIcon();
                            $('#normal-card').html('<div style="padding:10px;color:#6c757d">No duplicates found.</div>');
                            $('#result-count').text('0 duplicates found');
                        }
                    }, 'json');
                }

                // show the yellow question icon with click handler to open modal with summary table
                function showSummaryIcon(groups){
                    removeSummaryIcon();
                    const btn = $('<button id="btn-summary" title="Show global duplicate summary" style="background:#ffd966;border:0;padding:8px 10px;border-radius:6px;margin-left:8px;cursor:pointer;color:#212529;font-weight:700;"></button>');
                    btn.html('<i class="fa-solid fa-circle-question"></i>');
                    $('#btn-check').after(btn);
                    btn.on('click', function(){
                        // build improved HTML table using existing styles
                        let html = '<div style="max-height:60vh; overflow:auto; padding:12px 6px; position:relative;">';
                        html += '<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">';
                        html += '<div style="color:#6c757d;font-size:13px;">Switch to dev mode for root checking duplicates to confirm duplicates issue</div>';
                        html += '<div><button id="switch-to-dev" class="btn-proceed" style="background:#dc3545;color:#fff;border:none;padding:6px 10px;border-radius:6px;font-weight:700;">Switch to Dev Mode</button></div>';
                        html += '</div>';
                        html += '<table class="group-table" style="width:100%;">';
                        html += '<thead><tr><th style="text-align:left;padding:8px;">Reference No.</th><th style="text-align:right;padding:8px;">Total Count</th></tr></thead><tbody>';
                        groups.forEach(function(g){ html += '<tr><td style="padding:8px;border-bottom:1px solid #f5f5f5;">'+ $('<div>').text(g.reference_no).html() +'</td><td style="padding:8px;text-align:right;border-bottom:1px solid #f5f5f5;">'+ (g.total_count||0) +'</td></tr>'; });
                        html += '</tbody></table></div>';

                        Swal.fire({
                            title: 'Potential duplicates (summary)',
                            html: html,
                            width: '80%',
                            showConfirmButton: false,
                            showCloseButton: true,
                            didOpen: () => {
                                // attach handler for switch to dev
                                $('#switch-to-dev').on('click', function(){
                                    // remove summary icon before switching
                                    removeSummaryIcon();
                                    // switch toggle to dev and trigger check
                                        $('#mode-toggle .mode-btn').removeClass('active').attr('aria-pressed','false');
                                        $('#mode-toggle .mode-btn[data-mode="dev"]').addClass('active').attr('aria-pressed','true');
                                        // show appropriate container
                                        $('#normal-card').hide(); $('#dev-card').show();
                                        Swal.close();
                                        $('#btn-check').trigger('click');
                                });
                            }
                        });
                    });
                }

                function removeSummaryIcon(){ $('#btn-summary').remove(); }

            // Mode toggle click handler - show separate containers
            $(document).on('click', '#mode-toggle .mode-btn', function(){
                $('#mode-toggle .mode-btn').removeClass('active').attr('aria-pressed','false');
                $(this).addClass('active').attr('aria-pressed','true');
                const mode = $(this).data('mode');
                if(mode === 'dev'){
                    $('#dev-card').show();
                    $('#normal-card').hide();
                    // export button visibility depends on whether dev data exists
                    if(window.lastDevGroups && window.lastDevGroups.length>0) $('#btn-export').show(); else $('#btn-export').hide();
                    // show include filter control in dev mode
                    $('#dev-include-container').show();
                    // always hide bulk delete in Dev mode
                    $('#btn-delete-all').hide();
                } else {
                    $('#normal-card').show();
                    $('#dev-card').hide();
                    $('#btn-export').hide();
                    // hide include filter control in normal mode
                    $('#dev-include-container').hide();
                    // show bulk delete only if there are red duplicate rows in normal view
                    if((window.lastNormalDuplicateIds && window.lastNormalDuplicateIds.length > 0) || $('#normal-card .dup-row.red').length > 0) $('#btn-delete-all').show(); else $('#btn-delete-all').hide();
                }
            });

            // delete single (with SweetAlert2 confirmation)
            $(document).on('click', '.btn-delete', function(){
                const id = $(this).data('id');
                Swal.fire({
                    title: 'Delete this duplicate?',
                    text: 'This will permanently remove the selected duplicate row.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#dc3545',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: 'Delete',
                    cancelButtonText: 'Cancel'
                }).then((result) => {
                    if (result.isConfirmed) {
                        showOverlay();
                        $.post(window.location.href, { delete_duplicate: 1, id: id }, function(res){
                            hideOverlay();
                            if(res && res.success){
                                // remove from whichever container(s) contain the id
                                $('[data-id="'+id+'"]').remove();
                                Swal.fire('Deleted','Row removed','success');
                            } else {
                                Swal.fire('Error','Delete failed','error');
                            }
                        }, 'json').fail(function(){ hideOverlay(); Swal.fire('Error','Request failed','error'); });
                    }
                });
            });

            // compare (arrow) button on green rows - switch to Dev Mode and scroll to matching group
            function gotoDevRef(ref){
                if(!ref) return;

                // ensure dev mode is active and visible
                $('#mode-toggle .mode-btn').removeClass('active').attr('aria-pressed','false');
                $('#mode-toggle .mode-btn[data-mode="dev"]').addClass('active').attr('aria-pressed','true');
                $('#normal-card').hide(); $('#dev-card').show();
                $('#dev-include-container').show();

                // copy the reference into the search box and trigger a render/search
                $('#dev-search').val(ref);

                // If there is no dev data loaded yet, prompt to load it and then perform search
                if(!window.lastDevGroupsRaw || window.lastDevGroupsRaw.length === 0){
                    Swal.fire({
                        title: 'Load Dev Data?',
                        text: 'Dev data is not loaded. Load root data and search for the reference?',
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonColor: '#0d6efd',
                        cancelButtonColor: '#6c757d',
                        confirmButtonText: 'Load & Search',
                        cancelButtonText: 'Cancel'
                    }).then((result) => {
                        if(result.isConfirmed){
                            // trigger a dev-mode check which will call renderDev; renderDev reads the search box value
                            $('#btn-check').trigger('click');
                            // poll for the results to be available
                            const start = Date.now();
                            const poll = setInterval(function(){
                                if(window.lastDevGroups && typeof window.lastDevGroups !== 'undefined'){
                                    clearInterval(poll);
                                    // if rendered groups exist, highlight the first one
                                    const $card = $('#dev-card .dev-group-card').first();
                                    if($card && $card.length){
                                        const $headerRef = $card.find('.dev-group-header strong').first();
                                        $card[0].scrollIntoView({behavior:'smooth', block:'center'});
                                        $card.addClass('dev-highlight');
                                        if($headerRef && $headerRef.length) $headerRef.addClass('dev-ref-highlight');
                                        setTimeout(function(){ if($headerRef && $headerRef.length) $headerRef.removeClass('dev-ref-highlight'); $card.removeClass('dev-highlight'); }, 3000);
                                    } else {
                                        Swal.fire('Not found in Dev Data','The selected reference is not present in the loaded Dev data.','info');
                                    }
                                } else if(Date.now() - start > 8000){
                                    clearInterval(poll);
                                }
                            }, 250);
                        }
                    });
                    return;
                }

                // If dev data is already loaded, re-render using stored raw data (search box already set)
                renderDev(window.lastDevGroupsRaw);

                // After render, locate first visible card (filtered) and highlight
                setTimeout(function(){
                    const $card = $('#dev-card .dev-group-card').first();
                    if($card && $card.length){
                        const $headerRef = $card.find('.dev-group-header strong').first();
                        $card[0].scrollIntoView({behavior:'smooth', block:'center'});
                        $card.addClass('dev-highlight');
                        if($headerRef && $headerRef.length) $headerRef.addClass('dev-ref-highlight');
                        setTimeout(function(){ if($headerRef && $headerRef.length) $headerRef.removeClass('dev-ref-highlight'); $card.removeClass('dev-highlight'); }, 3000);
                    } else {
                        Swal.fire('Not found in Dev Data','The selected reference is not present in the loaded Dev data.','info');
                    }
                }, 220);
            }

            $(document).on('click', '.btn-compare', function(){
                const ref = $(this).data('ref');
                gotoDevRef(ref);
            });

            // delete all duplicates (delete all red rows currently shown in Normal mode)
            $('#btn-delete-all').on('click', function(){
                // Prefer stable duplicate IDs captured during render; fallback to visible red rows.
                let ids = Array.isArray(window.lastNormalDuplicateIds) ? window.lastNormalDuplicateIds.slice() : [];
                if(ids.length === 0){
                    $('#normal-card .dup-row.red').each(function(){
                        const rowId = parseInt($(this).data('id'), 10);
                        if(!isNaN(rowId) && rowId > 0) ids.push(rowId);
                    });
                    ids = Array.from(new Set(ids));
                }
                if(ids.length === 0) { Swal.fire('Nothing to delete','No duplicate rows selected','info'); return; }
                Swal.fire({
                    title: 'Delete ALL duplicates?',
                    text: 'This will permanently remove all duplicate rows currently displayed.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#dc3545',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: 'Delete All',
                    cancelButtonText: 'Cancel'
                }).then((result) => {
                    if (result.isConfirmed) {
                        const handleBulkDeleteSuccess = function(deletedCount){
                            $('#normal-card .dup-row.red').remove();
                            window.lastNormalDuplicateIds = [];
                            $('#btn-delete-all').hide();
                            $('#result-count').text('');
                            const msg = 'Selected rows removed' + (deletedCount > 0 ? ' (' + deletedCount + ' row(s)).' : '.');
                            Swal.fire('Deleted', msg, 'success').then(function(){
                                // Refresh only after modal closes so overlay does not appear behind the success modal.
                                $('#btn-check').trigger('click');
                            });
                        };

                        showOverlay();
                        const idsPayload = ids.join(',');
                        $.ajax({
                            type: 'POST',
                            url: window.location.href,
                            data: { delete_multiple: 1, ids: idsPayload }
                        }).done(function(rawResp){
                            let resp = rawResp;
                            if(typeof rawResp === 'string'){
                                try {
                                    resp = JSON.parse(rawResp);
                                } catch(parseErr){
                                    hideOverlay();
                                    Swal.fire('Error','Unexpected server response while deleting duplicates.','error');
                                    return;
                                }
                            }
                            hideOverlay();
                            if(resp && resp.success){
                                const deletedCount = parseInt(resp.deleted_count || 0, 10);
                                handleBulkDeleteSuccess(deletedCount);
                            } else {
                                Swal.fire('Error',(resp && resp.error) ? resp.error : 'Delete failed','error');
                            }
                        }).fail(function(jqxhr, status, err){
                            // Sometimes response is returned but jQuery enters fail (e.g., parser issues/proxy quirks).
                            const body = jqxhr && typeof jqxhr.responseText === 'string' ? jqxhr.responseText.trim() : '';
                            if(body){
                                try {
                                    const parsed = JSON.parse(body);
                                    hideOverlay();
                                    if(parsed && parsed.success){
                                        const deletedCount = parseInt(parsed.deleted_count || 0, 10);
                                        handleBulkDeleteSuccess(deletedCount);
                                        return;
                                    }
                                    Swal.fire('Error',(parsed && parsed.error) ? parsed.error : 'Delete failed','error');
                                    return;
                                } catch(e) {
                                    // continue to generic error below
                                }
                            }
                            hideOverlay();
                            Swal.fire('Error','Request failed (' + (status || 'unknown') + '). ' + (err || ''),'error');
                        });
                    }
                });
            });

            // Helpers for export
            function escapeHtml(str){
                return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
            }

            function exportToExcel(groups){
                const columns = [
                    'id','status','billing_invoice','datetime','cancellation_date','source_file','control_no','reference_no','payor','address','account_no','account_name','amount_paid','charge_to_customer','charge_to_partner','contact_no','other_details','branch_id','branch_code','outlet','zone_code','region_code','region','operator','remote_branch','remote_operator','2nd_approver','partner_name','partner_id','partner_id_kpx','mpm_gl_code','settle_unsettle','claim_unclaim','imported_by','imported_date','rfp_no','cad_no','hold_status','post_transaction'
                ];

                let html = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta http-equiv="X-UA-Compatible" content="IE=edge" /><meta name="viewport" content="width=device-width, initial-scale=1.0" /><style>table{border-collapse:collapse;font-family:Arial,Helvetica,sans-serif}th,td{border:1px solid #e9ecef;padding:6px;}</style></head><body>';
                html += '<h3>Duplicate Checker - Dev Export</h3>';

                groups.forEach(function(g){
                    const rows = g.rows || [];
                    if(rows.length === 0) return;
                    const uniform = {};
                    columns.forEach(function(col){
                        const vals = new Set(rows.map(r => (typeof r[col] === 'undefined' || r[col] === null) ? '' : String(r[col])));
                        uniform[col] = (vals.size === 1);
                    });

                    html += '<div style="margin-top:14px;margin-bottom:6px;font-weight:700;">Reference No.: ' + escapeHtml(g.reference_no || '') + ' (' + rows.length + ' rows)</div>';
                    html += '<table><thead><tr><th>Action</th>';
                    columns.forEach(function(col){ html += '<th>' + escapeHtml(col) + '</th>'; });
                    html += '</tr></thead><tbody>';

                    rows.forEach(function(r){
                        html += '<tr>';
                        html += '<td></td>';
                        columns.forEach(function(col){
                            var raw = (typeof r[col] === 'undefined' || r[col] === null) ? '' : String(r[col]);
                            var display = raw;
                            if(col === 'datetime' || col.toLowerCase().includes('date')){ display = formatLongDate(raw); }
                            const bg = uniform[col] ? '#e6ffed' : '#fff2f2';
                            html += '<td style="background:' + bg + ';">' + escapeHtml(display) + '</td>';
                        });
                        html += '</tr>';
                    });

                    html += '</tbody></table>';
                });

                html += '</body></html>';

                const blob = new Blob([html], { type: 'application/vnd.ms-excel' });
                const ts = new Date().toISOString().replace(/[:.]/g,'-');
                const filename = 'duplicate_report_dev_' + ts + '.xls';
                const link = document.createElement('a');
                link.href = URL.createObjectURL(blob);
                link.download = filename;
                document.body.appendChild(link);
                link.click();
                setTimeout(function(){ URL.revokeObjectURL(link.href); link.remove(); }, 5000);
            }

            function exportToPDF(groups){
                // build same HTML as Excel export but render to PDF via html2pdf
                let html = '<div style="font-family:Arial,Helvetica,sans-serif;">';
                html += '<h3>Duplicate Checker - Dev Export</h3>';
                const columns = [
                    'id','status','billing_invoice','datetime','cancellation_date','source_file','control_no','reference_no','payor','address','account_no','account_name','amount_paid','charge_to_customer','charge_to_partner','contact_no','other_details','branch_id','branch_code','outlet','zone_code','region_code','region','operator','remote_branch','remote_operator','2nd_approver','partner_name','partner_id','partner_id_kpx','mpm_gl_code','settle_unsettle','claim_unclaim','imported_by','imported_date','rfp_no','cad_no','hold_status','post_transaction'
                ];

                groups.forEach(function(g){
                    const rows = g.rows || [];
                    if(rows.length === 0) return;
                    const uniform = {};
                    columns.forEach(function(col){
                        const vals = new Set(rows.map(r => (typeof r[col] === 'undefined' || r[col] === null) ? '' : String(r[col])));
                        uniform[col] = (vals.size === 1);
                    });

                    html += '<div style="margin-top:14px;margin-bottom:6px;font-weight:700;">Reference No.: ' + escapeHtml(g.reference_no || '') + ' (' + rows.length + ' rows)</div>';
                    html += '<table style="width:100%;border-collapse:collapse;">';
                    html += '<thead><tr><th style="border:1px solid #e9ecef;padding:6px;">Action</th>';
                    columns.forEach(function(col){ html += '<th style="border:1px solid #e9ecef;padding:6px;">' + escapeHtml(col) + '</th>'; });
                    html += '</tr></thead><tbody>';

                    rows.forEach(function(r){
                        html += '<tr>';
                        html += '<td style="border:1px solid #e9ecef;padding:6px;"></td>';
                        columns.forEach(function(col){
                            var raw = (typeof r[col] === 'undefined' || r[col] === null) ? '' : String(r[col]);
                            var display = raw;
                            if(col === 'datetime' || col.toLowerCase().includes('date')){ display = formatLongDate(raw); }
                            const bg = uniform[col] ? '#e6ffed' : '#fff2f2';
                            html += '<td style="background:' + bg + ';border:1px solid #e9ecef;padding:6px;">' + escapeHtml(display) + '</td>';
                        });
                        html += '</tr>';
                    });

                    html += '</tbody></table>';
                });

                html += '</div>';

                // create container and call html2pdf
                const container = document.createElement('div');
                container.style.padding = '10px';
                container.innerHTML = html;
                document.body.appendChild(container);

                const opt = {
                    margin:       10,
                    filename:     'duplicate_report_dev_' + new Date().toISOString().replace(/[:.]/g,'-') + '.pdf',
                    image:        { type: 'jpeg', quality: 0.98 },
                    html2canvas:  { scale: 2, useCORS: true },
                    jsPDF:        { unit: 'mm', format: 'a4', orientation: 'landscape' }
                };

                // html2pdf available via CDN
                try {
                    html2pdf().set(opt).from(container).save().then(function(){ setTimeout(function(){ container.remove(); }, 5000); });
                } catch (e) {
                    container.remove();
                    Swal.fire('Error','PDF export failed: ' + (e.message || e),'error');
                }
            }

            // open modal to choose format
            $('#btn-export').on('click', function(){
                const groups = window.lastDevGroups || [];
                if(!groups || groups.length === 0){ Swal.fire('No data','No dev-mode data to export','info'); return; }

                const html = '<div style="display:flex;gap:10px;justify-content:center;padding:8px;"><button id="export-excel" class="swal2-confirm swal2-styled" style="background:#0d6efd;border:none;color:#fff;">Export Excel</button><button id="export-pdf" class="swal2-confirm swal2-styled" style="background:#6c757d;border:none;color:#fff;">Export PDF</button></div>';

                Swal.fire({ title: 'Export format', html: html, showConfirmButton: false, showCloseButton: true, didOpen: () => {
                    document.getElementById('export-excel').onclick = function(){ exportToExcel(groups); Swal.close(); };
                    document.getElementById('export-pdf').onclick = function(){ exportToPDF(groups); Swal.close(); };
                }});
            });

            // ensure duplicates container is visible when results are present
            // (click-outside handler removed because results are inline)
            // initialize both containers with default messages so mode switching preserves content
            $('#normal-card').html('<div style="padding:10px;color:#6c757d">Check Duplicate in Normal Mode</div>');
            $('#dev-card').html('<div style="padding:10px;color:#6c757d">Check Duplicate in Root Mode</div>');
            window.lastNormalDuplicateIds = [];
            // ensure only active mode's container is visible
            if($('#mode-toggle .mode-btn.active').data('mode') === 'dev'){
                $('#dev-card').show(); $('#normal-card').hide();
                $('#btn-export').hide();
                $('#btn-delete-all').hide();
                $('#dev-include-container').show();
            } else {
                $('#normal-card').show(); $('#dev-card').hide();
                // show delete-all only when normal view already has red duplicate rows
                if((window.lastNormalDuplicateIds && window.lastNormalDuplicateIds.length > 0) || $('#normal-card .dup-row.red').length > 0) $('#btn-delete-all').show(); else $('#btn-delete-all').hide();
                $('#btn-export').hide();
                $('#dev-include-container').hide();
            }

            // when include selection changes, re-render dev view using stored raw groups
            $(document).on('change', '#dev-include', function(){
                if(window.lastDevGroupsRaw && window.lastDevGroupsRaw.length>0){
                    renderDev(window.lastDevGroupsRaw);
                }
            });
            // realtime search for reference no in dev mode
            $(document).on('input', '#dev-search', function(){
                if(window.lastDevGroupsRaw && window.lastDevGroupsRaw.length>0){
                    renderDev(window.lastDevGroupsRaw);
                }
            });
        });
    </script>
    <?php include '../../../templates/footer.php'; ?>
</body>
</html>
