<?php
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../vendor/autoload.php';

session_start();
@include_once __DIR__ . '/../../../templates/middleware.php';
$id = resolve_user_identifier();
if (empty($id)) { header('Location: ../../../login_form.php'); exit; }

if (!function_exists('has_any_permission') || !has_any_permission(['Cancellation Report','Bills Payment'])) { header('Location: ../../home.php'); exit; }

// prefer explicit session values for current user email; avoid role-based gating
$current_user_email = $_SESSION['admin_email'] ?? $_SESSION['user_email'] ?? '';

// AJAX handler for fetching cancellation data
if (isset($_POST['action']) && $_POST['action'] === 'get_cancellation_data') {
    ob_clean();
    header('Content-Type: application/json');

    $partner = isset($_POST['partner']) ? trim($_POST['partner']) : '';
    $start_date = isset($_POST['start_date']) ? trim($_POST['start_date']) : '';
    $end_date = isset($_POST['end_date']) ? trim($_POST['end_date']) : '';
    $source_file = isset($_POST['source_file']) ? trim($_POST['source_file']) : '';
    $region = isset($_POST['region']) ? trim($_POST['region']) : '';
    $branch = isset($_POST['branch']) ? trim($_POST['branch']) : '';
    $search = isset($_POST['search']) ? trim($_POST['search']) : '';
    $page = isset($_POST['page']) ? max(1, (int)$_POST['page']) : 1;
    $rows_per_page = isset($_POST['rows_per_page']) ? max(1, (int)$_POST['rows_per_page']) : 10;

    $where = [];
    $params = [];
    $types = '';

    if ($search !== '') {
        $where[] = "(reference_no LIKE ? OR account_no LIKE ? OR account_name LIKE ? OR partner_name LIKE ? )";
        $like = "%{$search}%";
        $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
        $types .= 'ssss';
    }
    if ($partner !== '' && $partner !== 'All') {
        $where[] = "partner_name = ?";
        $params[] = $partner; $types .= 's';
    }
    if ($start_date !== '') {
        $where[] = "DATE(cancellation_datetime) >= ?";
        $params[] = $start_date; $types .= 's';
    }
    if ($end_date !== '') {
        $where[] = "DATE(cancellation_datetime) <= ?";
        $params[] = $end_date; $types .= 's';
    }
    if ($source_file !== '' && $source_file !== 'All') {
        $where[] = "source_file = ?";
        $params[] = $source_file; $types .= 's';
    }
    if ($region !== '' && $region !== 'All') {
        $where[] = "region_code = ?"; $params[] = $region; $types .= 's';
    }
    if ($branch !== '' && $branch !== 'All') {
        $where[] = "branch_id = ?"; $params[] = $branch; $types .= 's';
    }

    $whereClause = '';
    if (!empty($where)) $whereClause = 'WHERE ' . implode(' AND ', $where);

    // Count total
    $countSql = "SELECT COUNT(*) as total FROM mldb.billspayment_cancellation $whereClause";
    $total = 0;
    if (!empty($params)) {
        $cstmt = $conn->prepare($countSql);
        if ($cstmt) {
            $bind = array_merge([$types], $params);
            $tmp = [];
            foreach ($bind as $k => $v) $tmp[$k] = &$bind[$k];
            call_user_func_array([$cstmt, 'bind_param'], $tmp);
            $cstmt->execute();
            $cres = $cstmt->get_result();
            if ($cres) { $crow = $cres->fetch_assoc(); $total = intval($crow['total']); }
            $cstmt->close();
        }
    } else {
        $r = $conn->query($countSql);
        if ($r) { $row = $r->fetch_assoc(); $total = intval($row['total']); }
    }

    // Totals
    $totals = ['principal' => 0, 'partner' => 0, 'customer' => 0];
    $totalsSql = "SELECT COALESCE(SUM(principal_amount),0) as total_principal, COALESCE(SUM(charge_to_partner),0) as total_partner, COALESCE(SUM(charge_to_customer),0) as total_customer FROM mldb.billspayment_cancellation $whereClause";
    if (!empty($params)) {
        $tstmt = $conn->prepare($totalsSql);
        if ($tstmt) {
            $bind = array_merge([$types], $params);
            $tmp = []; foreach ($bind as $k => $v) $tmp[$k] = &$bind[$k];
            call_user_func_array([$tstmt, 'bind_param'], $tmp);
            $tstmt->execute(); $tres = $tstmt->get_result(); if ($tres) { $trow = $tres->fetch_assoc(); $totals['principal'] = floatval($trow['total_principal']); $totals['partner'] = floatval($trow['total_partner']); $totals['customer'] = floatval($trow['total_customer']); } $tstmt->close();
        }
    } else {
        $r = $conn->query($totalsSql); if ($r) { $trow = $r->fetch_assoc(); $totals['principal'] = floatval($trow['total_principal']); $totals['partner'] = floatval($trow['total_partner']); $totals['customer'] = floatval($trow['total_customer']); }
    }

    // Data with pagination
    $offset = ($page - 1) * $rows_per_page;
    $dataSql = "SELECT * FROM mldb.billspayment_cancellation $whereClause ORDER BY cancellation_datetime DESC LIMIT ?, ?";
    $data = [];
    if (!empty($params)) {
        $dstmt = $conn->prepare($dataSql);
        if ($dstmt) {
            // bind params + offset,limit
            $fullTypes = $types . 'ii';
            $bindVals = array_merge([$fullTypes], $params, [$offset, $rows_per_page]);
            $tmp = []; foreach ($bindVals as $k => $v) $tmp[$k] = &$bindVals[$k];
            call_user_func_array([$dstmt, 'bind_param'], $tmp);
            $dstmt->execute(); $dres = $dstmt->get_result(); if ($dres) { while ($r = $dres->fetch_assoc()) $data[] = $r; } $dstmt->close();
        }
    } else {
        $q = $conn->prepare($dataSql);
        $q->bind_param('ii', $offset, $rows_per_page);
        $q->execute(); $dres = $q->get_result(); if ($dres) { while ($r = $dres->fetch_assoc()) $data[] = $r; } $q->close();
    }

    echo json_encode(['success' => true, 'data' => $data, 'pagination' => ['total' => $total, 'page' => $page, 'rows_per_page' => $rows_per_page], 'totals' => $totals]);
    exit;
}

// Render page
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Cancellation Report</title>
    <link rel="stylesheet" href="../../../assets/css/templates/style.css?v=<?php echo time(); ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Select2 -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2-bootstrap-5-theme/1.3.0/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
    <link rel="icon" href="../../../images/MLW logo.png" type="image/png">
    <style>
        /* Improve cancellation table readability: prevent wrapping so each row is one line */
        #resultsTable { min-width: 1800px; }
        #resultsTable th { white-space: nowrap; vertical-align: middle; font-weight:600; }
        #resultsTable td { white-space: nowrap; vertical-align: middle; overflow: visible; }
        #resultsTable th, #resultsTable td { padding: .65rem .75rem; }
        #resultsTable td.text-end, #resultsTable th.text-end { text-align: right; }
        /* Slightly increase font for readability */
        #resultsTable th, #resultsTable td { font-size: 0.9rem; }
        /* Make the table container scroll horizontally */
        .table-responsive { overflow-x: auto; }
        /* Pagination: make page links red to match header/theme */
        #pagination .page-link {
            color: #b02a37;
            border-color: #b02a37;
            background-color: transparent;
        }
        #pagination .page-link:hover, #pagination .page-link:focus {
            color: #fff;
            background-color: #b02a37;
            border-color: #b02a37;
        }
        #pagination .page-item.active .page-link {
            color: #fff;
            background-color: #b02a37;
            border-color: #b02a37;
        }
        #pagination .page-item.disabled .page-link {
            color: #6c757d;
            border-color: #dee2e6;
        }
        /* Export preview: keep header labels on a single line (no wrapping) */
        #exportPreviewTable th { white-space: nowrap; }
        #exportPreviewTable td { white-space: nowrap; }
    </style>
</head>
<body>
<?php include '../../../templates/header_ui.php'; ?>
<?php include '../../../templates/sidebar.php'; ?>
<div id="loading-overlay">
    <div class="loading-spinner"></div>
</div>
<div class="bp-section-header" role="region" aria-label="Page title">
    <div class="bp-section-title">
        <i class="fa-solid fa-file-invoice-dollar" aria-hidden="true"></i>
        <div>
            <h2>Cancellation Report</h2>
            <p class="bp-section-sub">Cancellation listing and filters</p>
        </div>
    </div>
</div>
<div class="bp-card container-fluid mt-3 p-4">
    <div class="card mb-3">
        <div class="card-header">
            <div class="row g-2 align-items-end">
                <div class="col-md-2 col-sm-6">
                    <label class="form-label small text-muted mb-1">Partner:</label>
                    <select id="partnerlistDropdown" class="form-select form-select-sm select2" aria-label="Select Partner" name="partnerlist" data-placeholder="Search Partner...">
                        <option value="">Select Partner</option>
                        <option value="All">All</option>
                        <?php
                        $pQ = $conn->query("SELECT partner_name FROM masterdata.partner_masterfile ORDER BY partner_name");
                        if ($pQ) while ($r = $pQ->fetch_assoc()) { $pn = htmlspecialchars($r['partner_name']); echo "<option value='". $pn ."'>" . ucfirst($pn) . "</option>"; }
                        ?>
                    </select>
                </div>

                <div class="col-md-3 col-sm-6">
                    <label class="form-label small text-muted mb-1">Cancellation Date:</label>
                    <div class="row g-1">
                        <div class="col-6">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text">From</span>
                                <input type="date" id="start_date" name="start_date" class="form-control" max="<?php echo date('Y-m-d'); ?>">
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text">To</span>
                                <input type="date" id="end_date" name="end_date" class="form-control" max="<?php echo date('Y-m-d'); ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-2 col-sm-6">
                    <label class="form-label small text-muted mb-1">Source File:</label>
                    <select id="source_file_filter" name="source_file" class="form-select form-select-sm">
                        <option value="All">All</option>
                        <option value="KPX">KPX</option>
                        <option value="KP7">KP7</option>
                    </select>
                </div>

                <div class="col-md-2 col-sm-6">
                    <label class="form-label small text-muted mb-1">Region:</label>
                    <select id="region_filter" name="region" class="form-select form-select-sm">
                        <option value="All">All Region</option>
                        <?php
                            $regionQ = $conn->query("SELECT DISTINCT region FROM mldb.billspayment_cancellation WHERE region IS NOT NULL AND region <> '' ORDER BY region");
                            if ($regionQ) while ($rr = $regionQ->fetch_assoc()) { $rv = htmlspecialchars($rr['region']); echo "<option value='". $rv ."'>" . $rv . "</option>"; }
                        ?>
                    </select>
                </div>

                <div class="col-md-2 col-sm-6">
                    <label class="form-label small text-muted mb-1">Branch Name:</label>
                    <select id="branchDropdown" class="form-select form-select-sm select2" aria-label="Select Branch Name" name="branch" data-placeholder="Search Branch Name...">
                        <option value="All">All Branch Name</option>
                        <?php
                            $branchQ = $conn->query("SELECT DISTINCT branch_name FROM mldb.billspayment_cancellation WHERE branch_name IS NOT NULL AND branch_name <> '' ORDER BY branch_name");
                            if ($branchQ) while ($br = $branchQ->fetch_assoc()) { $bn = htmlspecialchars($br['branch_name']); echo "<option value='". $bn ."'>" . $bn . "</option>"; }
                        ?>
                    </select>
                </div>

                <div class="col-md-2 col-sm-6">
                    <label class="form-label small text-muted mb-1">Search:</label>
                    <input id="search_input" class="form-control form-control-sm" placeholder="Search reference/account/name">
                </div>

                <div class="col-md-1 col-sm-6">
                    <button type="button" id="searchButton" class="btn btn-danger btn-sm w-100"><i class="fas fa-search me-1"></i> Search</button>
                </div>
            </div>
        </div>
        <div class="card-body">
            <div class="row g-2 align-items-center">
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1">Rows</label>
                    <select id="rowsPerPage" class="form-select form-select-sm" style="width:auto;"><option value="5" selected>5</option><option value="10">10</option><option value="25">25</option><option value="50">50</option><option value="100">100</option></select>
                </div>
                <div class="col-md-10 d-flex justify-content-end align-items-center">
                    <button id="openExportModalBtn" class="btn btn-outline-secondary btn-sm me-2">Export</button>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-striped mb-0" id="resultsTable">
                    <thead class="table-light">
                        <tr>
                            <th style="width:48px;">No.</th>
                                            <th style="width:160px;">Report Date</th>
                                            <th style="width:160px;">Cancellation Date/Time</th>
                            <th style="width:160px;">Sendout Date/Time</th>
                            <th style="width:220px;">Partner Name</th>
                            <th style="width:160px;">Reference No.</th>
                            <th style="width:120px;">Control No</th>
                            <th style="width:120px;">Account No</th>
                            <th style="width:220px;">Account Name</th>
                            <th style="width:160px;">Payor</th>
                            <th style="width:120px;">IR No.</th>
                            <th class="text-end" style="width:120px;">Amount</th>
                            <th class="text-end" style="width:120px;">Cancellation Charge</th>
                            <th class="text-end" style="width:100px;">CTC</th>
                            <th class="text-end" style="width:100px;">CTP</th>
                            <th style="width:140px;">Resource</th>
                            <th style="width:160px;">Branch Name</th>
                            <th style="width:140px;">Remote Operator</th>
                            <th style="width:140px;">Remote Branch</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
        <div class="card-footer">
            <nav><ul class="pagination" id="pagination"></ul></nav>
        </div>
    </div>
</div>
<?php include '../../../templates/footer.php'; ?>
<script>
// --- Export modal & preview logic ---
// Build a 5-row sample dataset for preview (UI-only sample)
function getSampleRows() {
    return [
        { report_date: '2026-03-09', cancellation_datetime: '2026-03-08 10:12:00', sendout_datetime: '2026-03-07 08:00:00', partner_name: 'JT Enterprises', reference_no: 'REF001', control_no: 'CTL001', account_no: 'ACC001', account_name: 'Juan Dela Cruz', payor: 'John', ir_no: 'IR001', principal_amount: 1250.50, cancellation_charge: 50.00, charge_to_customer: 50.00, charge_to_partner: 0.00, resource: 'KPX', branch_name: 'Main Branch', remote_operator: 'OP01', remote_branch: 'RB01' },
        { report_date: '2026-03-09', cancellation_datetime: '2026-03-08 11:20:00', sendout_datetime: '2026-03-07 09:00:00', partner_name: 'ABC Trading', reference_no: 'REF002', control_no: 'CTL002', account_no: 'ACC002', account_name: 'Maria Santos', payor: 'Maria', ir_no: 'IR002', principal_amount: 2300.00, cancellation_charge: 75.00, charge_to_customer: 75.00, charge_to_partner: 0.00, resource: 'KP7', branch_name: 'North Branch', remote_operator: 'OP02', remote_branch: 'RB02' },
        { report_date: '2026-03-09', cancellation_datetime: '2026-03-08 12:05:00', sendout_datetime: '2026-03-07 10:00:00', partner_name: 'Global Supply', reference_no: 'REF003', control_no: 'CTL003', account_no: 'ACC003', account_name: 'Pedro Reyes', payor: 'Pedro', ir_no: 'IR003', principal_amount: 500.75, cancellation_charge: 25.00, charge_to_customer: 25.00, charge_to_partner: 0.00, resource: 'KPX', branch_name: 'South Branch', remote_operator: 'OP03', remote_branch: 'RB03' },
        { report_date: '2026-03-09', cancellation_datetime: '2026-03-08 13:33:00', sendout_datetime: '2026-03-07 11:00:00', partner_name: 'JT Enterprises', reference_no: 'REF004', control_no: 'CTL004', account_no: 'ACC004', account_name: 'Ana Lopez', payor: 'Ana', ir_no: 'IR004', principal_amount: 150.00, cancellation_charge: 10.00, charge_to_customer: 10.00, charge_to_partner: 0.00, resource: 'KP7', branch_name: 'East Branch', remote_operator: 'OP04', remote_branch: 'RB04' },
        { report_date: '2026-03-09', cancellation_datetime: '2026-03-08 14:40:00', sendout_datetime: '2026-03-07 12:00:00', partner_name: 'Alpha Co', reference_no: 'REF005', control_no: 'CTL005', account_no: 'ACC005', account_name: 'Lito Cruz', payor: 'Lito', ir_no: 'IR005', principal_amount: 980.00, cancellation_charge: 40.00, charge_to_customer: 40.00, charge_to_partner: 0.00, resource: 'KPX', branch_name: 'West Branch', remote_operator: 'OP05', remote_branch: 'RB05' }
    ];
}

function buildExportColumns(includePartnerColumn, partnerColumnLast) {
    // Base columns in the order desired; partner column will be inserted/removed per rules
    var cols = [
        { key: 'no', label: 'No.' },
        { key: 'report_date', label: 'Report Date' },
        { key: 'cancellation_datetime', label: 'Cancellation Date/Time' },
        { key: 'sendout_datetime', label: 'Sendout Date/Time' },
        { key: 'reference_no', label: 'Reference No.' },
        { key: 'control_no', label: 'Control No' },
        { key: 'account_no', label: 'Account No' },
        { key: 'account_name', label: 'Account Name' },
        { key: 'payor', label: 'Payor' },
        { key: 'ir_no', label: 'IR No.' },
        { key: 'principal_amount', label: 'Amount' },
        { key: 'cancellation_charge', label: 'Cancellation Charge' },
        { key: 'charge_to_customer', label: 'Charge To Customer' },
        { key: 'charge_to_partner', label: 'Charge to Partner' },
        { key: 'resource', label: 'Resource' },
        { key: 'branch_name', label: 'Branch Name' },
        { key: 'remote_operator', label: 'Remote Operator' },
        { key: 'remote_branch', label: 'Remote Branch' }
    ];

    if (includePartnerColumn) {
        var partnerCol = { key: 'partner_name', label: 'Partner Name' };
        if (partnerColumnLast) cols.push(partnerCol); else cols.unshift(partnerCol);
    }

    return cols;
}

function renderExportPreview() {
    var partner = $('#partnerlistDropdown').val();
    var start = $('#start_date').val();
    var end = $('#end_date').val();

    var includePartner = !partner || partner === '' || partner === 'All' ? true : false;
    // In Case1 (no specific partner) partner column should be LAST (after Remote Branch)
    var cols = buildExportColumns(includePartner, includePartner);

    var sample = getSampleRows().slice(0,5);

    // Build table head
    var thead = $('#exportPreviewTable thead'); thead.empty();
    var headRow = $('<tr></tr>');
    cols.forEach(function(c){ headRow.append('<th>'+c.label+'</th>'); });
    thead.append(headRow);

    // Build table body with 5 sample rows
    var tbody = $('#exportPreviewTable tbody'); tbody.empty();
    sample.forEach(function(r, idx){
        var tr = $('<tr></tr>');
        cols.forEach(function(c){
            var v;
            if (c.key === 'no') {
                v = idx + 1;
            } else {
                v = r[c.key];
                if (c.key === 'principal_amount' || c.key === 'cancellation_charge' || c.key === 'charge_to_customer' || c.key === 'charge_to_partner') {
                    v = formatPHP(v);
                }
            }
            tr.append('<td>' + (v === undefined ? '' : v) + '</td>');
        });
        tbody.append(tr);
    });

    // Update partner header area (for Case 2)
    if (!includePartner) {
        $('#exportPartnerHeader').show().text('Partner Name: ' + partner);
    } else {
        $('#exportPartnerHeader').hide().text('');
    }
}

// Build pages select and modal pagination based on total rows and main rows_per_page
function buildExportPagesUI(totalRows, rowsPerPageMain, currentPage) {
    var total = parseInt(totalRows) || 0;
    var rpp = parseInt(rowsPerPageMain) || parseInt($('#rowsPerPage').val()||5);
    var pages = rpp > 0 ? Math.max(1, Math.ceil(total / rpp)) : 1;

    var select = $('#exportPagesSelect');
    // preserve user-configured selection/input
    var prevSelectVal = select.val();
    var prevInputVal = $('#exportPagesInput').val();
    select.empty();
    select.append('<option value="All">All</option>');
    for (var i=1;i<=pages;i++) select.append('<option value="'+i+'">'+i+'</option>');
    // restore previous export configuration selection/input if still valid
    if (prevSelectVal !== undefined && select.find('option[value="'+prevSelectVal+'"]').length) {
        select.val(prevSelectVal);
    }
    if (prevInputVal !== undefined) $('#exportPagesInput').val(prevInputVal);
    // Do NOT overwrite user's export configuration otherwise
    var curSel = parseInt(currentPage) || 1;

    // build compact pagination controls (place them in the right-side container)
    var container = $('#exportModalPaginationContainer');
    container.empty();

    // create pagination nav with limited numeric buttons and ellipses for large page counts
    var pagNav = $('<nav aria-label="Export pages"><ul id="exportModalPagination" class="pagination pagination-sm mb-0"></ul></nav>');
    var pag = pagNav.find('#exportModalPagination');

    // helper to push a page button
    function pushPageButton(p, active) {
        var li = $('<li class="page-item" data-page="'+p+'"></li>');
        if (active) li.addClass('active');
        var a = $('<a href="#" class="page-link export-page-link" data-page="'+p+'">'+p+'</a>');
        li.append(a); pag.append(li);
    }

    // previous button
    pag.append('<li class="page-item"><a href="#" class="page-link export-page-prev" aria-label="Previous">&laquo;</a></li>');

    var current = parseInt(currentPage) || curSel || 1;

    var maxButtons = 5; // show at most 5 numeric buttons (first, last, and up to 3 middle)
    if (pages <= maxButtons) {
        for (var p=1;p<=pages;p++) pushPageButton(p, p===current);
    } else {
        // always show first
        pushPageButton(1, current===1);
        var middleCount = maxButtons - 2; // slots for middle buttons
        var half = Math.floor(middleCount/2);
        var left = current - half;
        var right = left + middleCount - 1;
        if (left < 2) { left = 2; right = left + middleCount - 1; }
        if (right > pages-1) { right = pages-1; left = right - middleCount + 1; }
        if (left > 2) pag.append('<li class="page-item disabled"><span class="page-link">&hellip;</span></li>');
        for (var p=left;p<=right;p++) pushPageButton(p, p===current);
        if (right < pages-1) pag.append('<li class="page-item disabled"><span class="page-link">&hellip;</span></li>');
        // always show last
        pushPageButton(pages, current===pages);
    }

    // next button
    pag.append('<li class="page-item"><a href="#" class="page-link export-page-next" aria-label="Next">&raquo;</a></li>');

    container.append(pagNav);
    // mark active page in pagination (highlight) without touching export inputs
    if (curSel) {
        pag.find('.page-item').removeClass('active');
        pag.find('.page-link[data-page="'+curSel+'"]').closest('.page-item').addClass('active');
    }
}

// Fetch actual data for the selected page (uses main UI rowsPerPage to compute which records are on that page)
function fetchExportPreviewPage(page) {
    page = parseInt(page) || 1;
    var post = {
        action: 'get_cancellation_data',
        page: page,
        rows_per_page: parseInt($('#rowsPerPage').val()||5),
        start_date: $('#start_date').val(),
        end_date: $('#end_date').val(),
        partner: $('#partnerlistDropdown').val(),
        source_file: $('#source_file_filter').val(),
        region: $('#region_filter').val(),
        branch: $('#branchDropdown').val(),
        search: $('#search_input').val()
    };

    $.post(location.href, post, function(resp){
        if (!resp || !resp.success) {
            // fallback to sample
            renderExportPreview();
            return;
        }

        // update last query info
        window.cancellation_last_query = { total: resp.pagination.total || 0, rows_per_page: resp.pagination.rows_per_page || post.rows_per_page, current_page: resp.pagination.page || page };

        var partner = post.partner;
        var includePartner = !partner || partner === '' || partner === 'All' ? true : false;
        var cols = buildExportColumns(includePartner, includePartner);

        // build head
        var thead = $('#exportPreviewTable thead'); thead.empty();
        var headRow = $('<tr></tr>'); cols.forEach(function(c){ headRow.append('<th>'+c.label+'</th>'); }); thead.append(headRow);

        // build body showing only first 5 rows from resp.data
        var tbody = $('#exportPreviewTable tbody'); tbody.empty();
        var rows = resp.data || [];
        var previewRows = rows.slice(0,5);
        if (previewRows.length === 0) {
            tbody.append('<tr><td colspan="'+cols.length+'" class="text-center">No data for this page</td></tr>');
        } else {
                previewRows.forEach(function(r, idx){
                    var tr = $('<tr></tr>');
                    cols.forEach(function(c){
                        var v;
                        if (c.key === 'no') {
                            var offset = ((page-1) * post.rows_per_page) || 0;
                            v = offset + idx + 1;
                        } else {
                            v = r[c.key];
                            if (c.key === 'principal_amount' || c.key === 'cancellation_charge' || c.key === 'charge_to_customer' || c.key === 'charge_to_partner') v = formatPHP(v);
                        }
                        tr.append('<td>' + (v === undefined ? '' : v) + '</td>');
                    });
                    tbody.append(tr);
                });
        }

        // update partner header area
        if (!includePartner) { $('#exportPartnerHeader').show().text('Partner Name: ' + partner); } else { $('#exportPartnerHeader').hide().text(''); }

        // rebuild pages UI so dropdown/pagination reflect latest totals and current page
        buildExportPagesUI(window.cancellation_last_query.total, window.cancellation_last_query.rows_per_page, window.cancellation_last_query.current_page);

        // highlight current page in modal pagination
        $('#exportModalPagination .page-item').removeClass('active');
        $('#exportModalPagination .page-link[data-page="'+page+'"]').closest('.page-item').addClass('active');

    }, 'json');
}

// helper to convert preview to CSV and trigger download (UI placeholder)
function downloadPreviewCSV() {
    var partner = $('#partnerlistDropdown').val();
    var includePartner = !partner || partner === '' || partner === 'All' ? true : false;
    var cols = buildExportColumns(includePartner, includePartner);

    // parse pages input (supports All, single number, ranges like 1-3, and lists like 1,3,5)
    var raw = ($('#exportPagesInput').val() || '').trim();

    // determine total pages from last query (fallback to 1)
    var last = window.cancellation_last_query || { total: 0, rows_per_page: parseInt($('#rowsPerPage').val()||5) };
    var totalPages = Math.max(1, Math.ceil((last.total || 0) / (last.rows_per_page || parseInt($('#rowsPerPage').val()||5))));

    function parsePages(rawStr) {
        if (!rawStr) return [1];
        if (/^\s*All\s*$/i.test(rawStr)) {
            var a = []; for (var i=1;i<=totalPages;i++) a.push(i); return a;
        }
        var set = {};
        rawStr.split(',').forEach(function(tok){
            tok = tok.trim();
            if (!tok) return;
            var m = tok.match(/^(\d+)\s*-\s*(\d+)$/);
            if (m) {
                var s = parseInt(m[1]), e = parseInt(m[2]);
                if (s>e) { var t=s; s=e; e=t; }
                for (var p=s;p<=e;p++) if (p>=1 && p<=totalPages) set[p]=true;
            } else {
                var n = parseInt(tok);
                if (!isNaN(n) && n>=1 && n<=totalPages) set[n]=true;
            }
        });
        var out = Object.keys(set).map(function(x){ return parseInt(x); }).sort(function(a,b){return a-b;});
        if (out.length === 0) out = [1];
        return out;
    }

    var pagesToFetch = parsePages(raw);

    // Build CSV by fetching each page's actual data from server (without touching main table)
    var rowsPerPage = parseInt($('#rowsPerPage').val()||5);
    var ajaxes = pagesToFetch.map(function(p){
        return $.post(location.href, { action: 'get_cancellation_data', page: p, rows_per_page: rowsPerPage, start_date: $('#start_date').val(), end_date: $('#end_date').val(), partner: $('#partnerlistDropdown').val(), source_file: $('#source_file_filter').val(), region: $('#region_filter').val(), branch: $('#branchDropdown').val(), search: $('#search_input').val() }, null, 'json');
    });

    // wait for all AJAX calls
    $.when.apply($, ajaxes).done(function() {
        // arguments handling: when multiple deferreds, arguments is array of arrays; when one, it's single response
        var responses = [];
        if (ajaxes.length === 1) {
            responses.push(arguments[0]);
        } else {
            for (var i=0;i<arguments.length;i++) responses.push(arguments[i][0]);
        }

        var lines = [];
        // If specific partner selected, add a single top row: A1=label, B1=value
        if (!includePartner) {
            lines.push('"' + 'Partner Name'.replace(/"/g,'""') + '","' + partner.replace(/"/g,'""') + '"');
        }

        // header
        lines.push(cols.map(function(c){ return '"' + c.label.replace(/"/g,'""') + '"'; }).join(','));

        responses.forEach(function(resp){
            if (!resp || !resp.success) return;
            var dataRows = resp.data || [];
            var pageNum = resp.pagination && resp.pagination.page ? parseInt(resp.pagination.page) : null;
            var offset = pageNum ? ((pageNum - 1) * rowsPerPage) : 0;
            dataRows.forEach(function(r, idx){
                var line = cols.map(function(c){
                    var v;
                    if (c.key === 'no') {
                        v = offset + idx + 1;
                    } else {
                        v = r[c.key] === undefined ? '' : r[c.key];
                        if (typeof v === 'number') v = v.toFixed(2);
                    }
                    return '"' + String(v).replace(/"/g,'""') + '"';
                }).join(',');
                lines.push(line);
            });
        });

        var csv = lines.join('\n');
        var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = 'cancellation_export.csv';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);

    }).fail(function(){
        alert('Failed to fetch data for export. Please try again.');
    });
}

// create modal markup and append to body once
$(function(){
    var modalHtml = `
    <div class="modal fade" id="exportModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Export Preview - Cancellation Report</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <div id="exportPartnerHeader" style="font-weight:700;margin-bottom:8px;display:none;"></div>
            <div class="table-responsive" style="max-height:60vh;overflow:auto;">
                <table class="table table-sm table-bordered" id="exportPreviewTable">
                    <thead></thead>
                    <tbody></tbody>
                </table>
            </div>
            <div class="mt-3 d-flex justify-content-between align-items-center">
                <div class="d-flex gap-2 align-items-center">
                    <label class="mb-0">Pages:</label>
                    <select id="exportPagesSelect" class="form-select form-select-sm" style="width:auto;">
                        <option value="All">All</option>
                        <option value="1" selected>1</option>
                    </select>
                    <div style="width:8px"></div>
                    <label class="mb-0">Or enter pages:</label>
                    <input id="exportPagesInput" class="form-control form-control-sm" style="width:220px;" placeholder="e.g. 1,3,4 or 1-4 or All" value="1">
                </div>
                <div id="exportModalPaginationContainer"></div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" id="downloadExportBtn" class="btn btn-danger">Download (CSV preview)</button>
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          </div>
        </div>
      </div>
    </div>`;

    $('body').append(modalHtml);

    // Wire modal events
    $('#openExportModalBtn').on('click', function(){
        var last = window.cancellation_last_query || { total:0, rows_per_page: parseInt($('#rowsPerPage').val()||5), current_page:1 };
        buildExportPagesUI(last.total, last.rows_per_page, last.current_page);
        // load preview for current page by default
        fetchExportPreviewPage(last.current_page || 1);
        var modal = new bootstrap.Modal(document.getElementById('exportModal'));
        modal.show();
    });

    // when filters change on main UI, update modal pages UI and preview if modal open
    $('#partnerlistDropdown, #start_date, #end_date, #rowsPerPage, #source_file_filter, #region_filter, #branchDropdown, #search_input').on('change', function(){
        var last = window.cancellation_last_query || { total:0, rows_per_page: parseInt($('#rowsPerPage').val()||5), current_page:1 };
        buildExportPagesUI(last.total, last.rows_per_page, last.current_page);
        // if modal is visible, refresh preview
        if ($('#exportModal').hasClass('show')) fetchExportPreviewPage(last.current_page || 1);
    });

    // pages select change -> only update the export input (do NOT change main table pagination)
    $(document).on('change', '#exportPagesSelect', function(e){ e.stopPropagation(); var v = $(this).val(); if (v === 'All') { $('#exportPagesInput').val('All'); } else { $('#exportPagesInput').val(v); } /* no table navigation triggered here */ });

    // modal pagination numeric button click -> update export input and refresh modal preview only
    $(document).on('click', '#exportModalPaginationContainer .export-page-link', function(e){
        e.preventDefault(); e.stopPropagation();
        var p = parseInt($(this).data('page'));
        if (!isNaN(p)) {
            // Only update modal preview and pagination highlight; do NOT change export configuration inputs
            fetchExportPreviewPage(p);
        }
    });

    // prev/next buttons
    $(document).on('click', '#exportModalPaginationContainer .export-page-prev', function(e){
        e.preventDefault(); e.stopPropagation();
        // determine current from active page button or exportPagesSelect
        var active = parseInt($('#exportModalPagination .page-item.active').data('page')) || parseInt($('#exportPagesSelect').val()) || 1;
        var target = Math.max(1, active - 1);
        fetchExportPreviewPage(target);
    });
    $(document).on('click', '#exportModalPaginationContainer .export-page-next', function(e){
        e.preventDefault(); e.stopPropagation();
        var last = window.cancellation_last_query || { total:0, rows_per_page: parseInt($('#rowsPerPage').val()||5) };
        var totalPages = Math.max(1, Math.ceil((last.total||0)/(last.rows_per_page||parseInt($('#rowsPerPage').val()||5))));
        var active = parseInt($('#exportModalPagination .page-item.active').data('page')) || parseInt($('#exportPagesSelect').val()) || 1;
        var target = Math.min(totalPages, active + 1);
        fetchExportPreviewPage(target);
    });

    // removed jump input handlers (jump input removed)

    $(document).on('click', '#downloadExportBtn', function(){ downloadPreviewCSV(); });
});

// --- end export modal & preview logic ---
function formatPHP(n){ return '₱ ' + (parseFloat(n||0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2})); }
function formatLongDate(d) {
    if (!d) return '';
    try {
        // accept YYYY-MM-DD or full datetime
        const dt = new Date(d);
        if (isNaN(dt)) return d;
        return dt.toLocaleDateString('en-US', { month: 'long', day: '2-digit', year: 'numeric' });
    } catch (e) { return d; }
}
// Build a compact, scalable main pagination UI inside #pagination
function buildMainPagination(totalRows, currentPage, rowsPerPage) {
    var total = parseInt(totalRows) || 0;
    var rpp = parseInt(rowsPerPage) || parseInt($('#rowsPerPage').val()||5);
    var pages = rpp > 0 ? Math.max(1, Math.ceil(total / rpp)) : 1;

    var pg = $('#pagination'); pg.empty();

    // helper to create page item
    function liFor(p, active) {
        return `<li class="page-item ${active? 'active':''}"><a class="page-link" href="#" data-page="${p}">${p}</a></li>`;
    }

    // prev
    var prevPage = Math.max(1, (currentPage||1) - 1);
    pg.append(`<li class="page-item"><a class="page-link" href="#" data-page="${prevPage}">&laquo;</a></li>`);

    var maxButtons = 5;
    var cur = parseInt(currentPage) || 1;
    if (pages <= maxButtons) {
        for (var i=1;i<=pages;i++) pg.append(liFor(i, i===cur));
    } else {
        pg.append(liFor(1, cur===1));
        var middleCount = maxButtons - 2;
        var half = Math.floor(middleCount/2);
        var left = cur - half;
        var right = left + middleCount - 1;
        if (left < 2) { left = 2; right = left + middleCount - 1; }
        if (right > pages-1) { right = pages-1; left = right - middleCount + 1; }
        if (left > 2) pg.append('<li class="page-item disabled"><span class="page-link">&hellip;</span></li>');
        for (var p=left;p<=right;p++) pg.append(liFor(p, p===cur));
        if (right < pages-1) pg.append('<li class="page-item disabled"><span class="page-link">&hellip;</span></li>');
        pg.append(liFor(pages, cur===pages));
    }

    var nextPage = Math.min(pages, cur + 1);
    pg.append(`<li class="page-item"><a class="page-link" href="#" data-page="${nextPage}">&raquo;</a></li>`);

    // Append a jump input as a list item
    // (No jump input for main pagination by design)
}
function loadCancellations(page=1){
    const post = { action: 'get_cancellation_data', page: page, rows_per_page: parseInt($('#rowsPerPage').val()||5), start_date: $('#start_date').val(), end_date: $('#end_date').val(), partner: $('#partnerlistDropdown').val(), search: $('#search_input').val() };
    $.post(location.href, post, function(resp){
        if(!resp || !resp.success) return;
        // store last query info for export modal (total rows and rows per page used in main UI)
        window.cancellation_last_query = {
            total: resp.pagination.total || 0,
            rows_per_page: resp.pagination.rows_per_page || parseInt($('#rowsPerPage').val()||5),
            current_page: resp.pagination.page || page
        };
        const tbody = $('#resultsTable tbody'); tbody.empty();
        resp.data.forEach(function(r, idx){
            const no = ((resp.pagination.page-1) * resp.pagination.rows_per_page) + idx + 1;
            tbody.append(`<tr>
                <td>${no}</td>
                <td>${formatLongDate(r.report_date)}</td>
                <td>${r.cancellation_datetime||''}</td>
                <td>${r.sendout_datetime||''}</td>
                <td>${r.partner_name||''}</td>
                <td>${r.reference_no||''}</td>
                <td>${r.control_no||''}</td>
                <td>${r.account_no||''}</td>
                <td>${r.account_name||''}</td>
                <td>${r.payor||''}</td>
                <td>${r.ir_no||''}</td>
                <td class="text-end">${formatPHP(r.principal_amount)}</td>
                <td class="text-end">${formatPHP(r.cancellation_charge)}</td>
                <td class="text-end">${formatPHP(r.charge_to_customer||r.charge_to_costumer)}</td>
                <td class="text-end">${formatPHP(r.charge_to_partner)}</td>
                <td>${r.resource||''}</td>
                <td>${r.branch_name||''}</td>
                <td>${r.remote_operator||''}</td>
                <td>${r.remote_branch||''}</td>
            </tr>`);
        });
        // pagination (use scalable pager)
        const total = resp.pagination.total; const rpp = resp.pagination.rows_per_page;
        buildMainPagination(total, resp.pagination.page, rpp);
        // totals display removed per UI request
    }, 'json');
}
$(document).on('click', '#pagination .page-link', function(e){ e.preventDefault(); loadCancellations(parseInt($(this).data('page'))); });
// main pagination has no jump 'Go' control
$('#searchButton').on('click', function(){ loadCancellations(1); });
$('#rowsPerPage').on('change', function(){ loadCancellations(1); });
$(document).ready(function(){
    // Initialize Select2 for partner search and branch if needed
    try {
        $('#partnerlistDropdown').select2({
            theme: 'bootstrap-5',
            placeholder: 'Search or select a Partner...',
            allowClear: true,
            width: '100%'
        });
    } catch (e) {}

    loadCancellations(1);
    // Inline Today button behavior: show button on date focus, set both dates to today and lock end_date
    function todayISO(){ const d = new Date(); const mm = String(d.getMonth()+1).padStart(2,'0'); const dd = String(d.getDate()).padStart(2,'0'); return `${d.getFullYear()}-${mm}-${dd}`; }
    // When user changes start_date manually: if it's today, lock end_date and sync; otherwise unlock end_date
    $('#start_date').on('change', function(){ const v = $(this).val(); if(v === todayISO()){ $('#end_date').val(v).prop('readonly', true).prop('disabled', true); } else { $('#end_date').prop('readonly', false).prop('disabled', false); } });
});
</script>
</body>
</html>
