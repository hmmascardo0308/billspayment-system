
<?php
// Connect to the database
require_once __DIR__ . '/../../../config/config.php';
require '../../../vendor/autoload.php';

// Start the session

session_start();
@include_once __DIR__ . '/../../../templates/middleware.php';
$id = resolve_user_identifier();
if (empty($id)) { header('Location: ../../../login_form.php'); exit; }
if (!function_exists('has_any_permission') || !has_any_permission(['Import Cancellation','Bills Payment'])) { header('Location: ../../home.php'); exit; }


// prefer explicit session values for current user email
$current_user_email = $_SESSION['admin_email'] ?? $_SESSION['user_email'] ?? '';

// dropdown queries for partner list
$partnersQuery = "SELECT partner_name FROM masterdata.partner_masterfile ORDER BY partner_name";
$partnersResult = $conn->query($partnersQuery);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Cancellation | <?php if($_SESSION['user_type'] === 'admin' || $_SESSION['user_type'] === 'user') echo ucfirst($_SESSION['user_type']); else echo "Guest";?></title>
    <!-- custom CSS file link  -->
    <link rel="stylesheet" href="../../../assets/css/templates/style.css?v=<?php echo time(); ?>">
    <script src="https://kit.fontawesome.com/30b908cc5a.js" crossorigin="anonymous"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="../../../assets/js/sweetalert2.all.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>

    <link rel="icon" href="../../../images/MLW logo.png" type="image/png">
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
            /* Make sure the table-responsive container shows all content */
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

        
        /* Enhanced SweetAlert2 backdrop for confidentiality */
        .swal2-container.swal2-backdrop-show {
            backdrop-filter: blur(10px);
            background-color: rgba(0,0,0,0.8) !important;
        }
        
        /* Make sure the modal itself is still clear */
        .swal2-popup {
            backdrop-filter: none !important;
            box-shadow: 0 0 20px rgba(0,0,0,0.5);
        }
        

        /* File Upload Area Styles */
       .file-upload-area {
            border: 2px dashed rgba(220,53,69,0.16);
            border-radius: 10px;
            padding: 34px 18px;
            text-align: center;
            background: #fff;
            transition: all 180ms ease;
            cursor: pointer;
            user-select: none;
        }

        .file-upload-area.drag-over { background:#fff5f5; transform: translateY(-4px); box-shadow: 0 10px 20px rgba(220,53,69,0.06); border-color:#dc3545; }

        .file-upload-icon i { font-size:36px; color:#dc3545; margin-bottom:8px; }
        .file-upload-area h5 { margin:8px 0 4px; font-weight:700; }
        .file-upload-area p { margin:0; color:#6c757d; }
        /* Mode card selector (match transaction UI) */
        .mode-cards { display:flex; gap:8px; align-items:center; }
        .mode-card {
            border: 1px solid #e9ecef;
            padding: 8px 12px;
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

        .file-upload-area.drag-over {
            border-color: #dc3545;
            background-color: #ffe5e5;
        }

        .file-upload-area:hover {
            border-color: #dc3545;
            background-color: #fff;
        }

        .file-upload-icon {
            font-size: 48px;
            color: #6c757d;
            margin-bottom: 15px;
        }

        /* File Cards Container */
        .files-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }

        /* Individual File Card */
        .file-card {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            background-color: #fff;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            display: flex;
            flex-direction: column;
            gap: 10px;
            transition: all 0.3s ease;
        }

        .file-card:hover {
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }

        .file-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 1px solid #dee2e6;
            padding-bottom: 10px;
        }

        .file-card-info {
            flex: 1;
        }

        .file-card-label {
            font-size: 11px;
            color: #6c757d;
            text-transform: uppercase;
            font-weight: 600;
            margin-bottom: 3px;
        }

        .file-card-value {
            font-size: 14px;
            color: #212529;
            font-weight: 500;
            word-break: break-word;
        }

        .file-card-delete {
            cursor: pointer;
            color: #dc3545;
            font-size: 20px;
            transition: all 0.2s ease;
        }

        .file-card-delete:hover {
            color: #bb2d3b;
            transform: scale(1.1);
        }

        .file-card-body {
            display: flex;
            gap: 15px;
        }

        .file-card-detail {
            flex: 1;
        }

        .badge-source {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
        }

        .badge-kpx {
            background-color: #0d6efd;
            color: white;
        }

        .badge-kp7 {
            background-color: #198754;
            color: white;
        }

        /* Tooltip for partner name */
        .partner-tooltip {
            position: relative;
            cursor: help;
            display: inline-block;
        }

        .partner-tooltip .tooltip-text {
            visibility: hidden;
            width: 200px;
            background-color: #212529;
            color: #fff;
            text-align: center;
            border-radius: 6px;
            padding: 8px;
            position: absolute;
            z-index: 1000;
            bottom: 125%;
            left: 50%;
            margin-left: -100px;
            opacity: 0;
            transition: opacity 0.3s;
            font-size: 12px;
        }

        .partner-tooltip .tooltip-text::after {
            content: "";
            position: absolute;
            top: 100%;
            left: 50%;
            margin-left: -5px;
            border-width: 5px;
            border-style: solid;
            border-color: #212529 transparent transparent transparent;
        }

        .partner-tooltip:hover .tooltip-text {
            visibility: visible;
            opacity: 1;
        }

        /* Proceed Button Container (top-right, sticky) */
        .proceed-container {
            margin-top: 0;
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 12px;
            position: sticky;
            top: 12px;
            z-index: 1050;
        }

        .btn-proceed {
            min-width: 200px;
            padding: 12px 30px;
            font-size: 16px;
            font-weight: 600;
        }

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

        .file-name-display {
            font-size: 12px;
            color: #6c757d;
            margin-top: 3px;
        }

        .empty-state {
            text-align: center;
            padding: 20px;
            color: #6c757d;
        }
        
        /* Page header, card and upload area - match transaction UI */
        .bp-section-header {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 18px;
            background: #ffffff;
            border-radius: 8px;
            color: #212529;
            margin: 18px 0 8px;
            box-shadow: 0 6px 18px rgba(16,24,40,0.04);
            border-left: 4px solid #dc3545;
        }

    .bp-section-title { display:flex; align-items:center; gap:12px; }
        .bp-section-title i { font-size:32px; color: #dc3545; }
        .bp-section-title h2 { margin:0; font-size:20px; color:#212529; font-weight:700; }
        .bp-section-sub { margin:0; font-size:13px; color:#6c757d; }
        .bp-card { background:#ffffff; border-radius:8px; box-shadow:0 6px 18px rgba(0,0,0,0.04); border:1px solid #f1f1f1; }
    </style>
</head>
<body>
    <div class="main-container">
        <!-- Show and Hide Side Nav Menu -->
        <?php include '../../../templates/header_ui.php'; ?>
        <?php include '../../../templates/sidebar.php'; ?>
        <div id="loading-overlay">
            <div class="loading-spinner"></div>
        </div>
        <div class="bp-section-header">
            <div class="bp-section-title">
                <i class="fa-solid fa-cloud-arrow-up" aria-hidden="true"></i>
                <div>
                    <h2>Import Cancellation</h2>
                    <p class="bp-section-sub">Upload Excel files (.xls, .xlsx) for processing</p>
                </div>
            </div>
        </div>
        <div class="bp-card container-fluid mt-3 p-4">
            <div class="bp-card-body">
                <!-- Mode Toggle (Auto / Manual) + Proceed (moved to top-right) -->
                <div class="mb-3 d-flex align-items-center justify-content-between" style="gap:12px;">
                    <div class="d-flex align-items-center" style="gap:12px;">
                        <label class="form-label me-2 mb-0">Import Mode:</label>
                        <div class="mode-cards">
                                <label class="mode-card selected" data-mode="auto">
                                    <input type="radio" name="importMode" id="modeAuto" value="auto" checked style="display:none;">
                                    <div class="mode-icon"><i class="fa-solid fa-cloud-arrow-up"></i></div>
                                    <div class="mode-text">
                                        <div class="mode-label">Auto</div>
                                        <small>Drag &amp; Drop</small>
                                    </div>
                                </label>
                                <label class="mode-card" data-mode="manual">
                                    <input type="radio" name="importMode" id="modeManual" value="manual" style="display:none;">
                                    <div class="mode-icon"><i class="fa-solid fa-file-lines"></i></div>
                                    <div class="mode-text">
                                        <div class="mode-label">Manual</div>
                                        <small>Form Upload</small>
                                    </div>
                                </label>
                        </div>
                    </div>

                    <div id="proceedContainer" class="proceed-container" style="display: none;">
                        <button type="button" class="btn btn-danger btn-proceed" id="proceedBtn">
                            <i class="fa-solid fa-paper-plane me-2" aria-hidden="true"></i>Proceed
                        </button>
                    </div>
                </div>

                <!-- Drag and Drop Upload Area -->
                <div class="file-upload-area" id="fileUploadArea">
                    <div class="file-upload-icon">
                        <i class="fa-solid fa-cloud-arrow-up"></i>
                    </div>
                    <h5>Drag &amp; Drop Files Here</h5>
                    <p class="text-muted">or click to browse</p>
                    <p class="text-muted"><small>Supports multiple Excel files (.xls, .xlsx)</small></p>
                    <input type="file" id="fileInput" accept=".xls,.xlsx,.csv,.xlsm,.xlsb,.ods,.tsv" multiple style="display: none;">
                </div>
                <!-- Manual Import Area (hidden by default) - transaction-style -->
                <div id="manualArea" style="display:none;">
                    <form id="manualUploadForm" action="../../../models/saved/saved_billspayImportCancelledFile.php" method="post" enctype="multipart/form-data">
                        <input type="hidden" name="upload" value="1">
                        <input type="hidden" name="report_date" id="manualReportDate" value="">
                        <input type="hidden" name="report_date_raw" id="manualReportDateRaw" value="">
                        <div class="row mt-3">
                            <div class="col-md-5 mb-3">
                                <div class="d-flex align-items-center">
                                    <label class="form-label me-2 mb-0">Partners Name:</label>
                                    <input list="manualCompanyList" id="manualCompanyInput" name="partner_name" class="form-control" placeholder="Search or type company name" required />
                                    <datalist id="manualCompanyList">
                                        <?php
                                            if ($partnersResult && mysqli_num_rows($partnersResult) > 0) {
                                                // populate datalist options
                                                mysqli_data_seek($partnersResult, 0);
                                                while ($row = mysqli_fetch_assoc($partnersResult)) {
                                                    $partner_names = htmlspecialchars($row['partner_name']);
                                                    echo "<option value=\"{$partner_names}\"></option>\n";
                                                }
                                            }
                                        ?>
                                    </datalist>
                                </div>
                            </div>
                            <div class="col-md-3 mb-3">
                                <div class="d-flex align-items-center">
                                    <label for="manualFileType" class="form-label me-2 mb-0">Source File Type:</label>
                                    <select id="manualFileType" class="form-select" name="fileType" required>
                                        <option value="">Select Source File Type</option>
                                        <option value="KPX">KPX</option>
                                        <option value="KP7">KP7</option>
                                    </select>
                                </div>
                            </div>

                            <div class="col-md-4 mb-3 d-flex">
                                <input type="file" name="import_file" accept=".xls,.xlsx" class="form-control me-2" required />
                                <input type="submit" class="btn btn-danger" id="manualProceed" value="Proceed">
                            </div>
                        </div>
                    </form>
                </div>
                <!-- Files Container -->
                <div id="filesContainer" class="files-container"></div>
            </div>
        </div>
    </div>
</body><?php include '../../../templates/footer.php'; ?>
<!-- Manual input uses datalist; no Select2 init required -->

<!-- IMPORT MODE RADIO BUTTONS -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const modeAuto = document.getElementById('modeAuto');
    const modeManual = document.getElementById('modeManual');
    const manualArea = document.getElementById('manualArea');
    const fileUploadArea = document.getElementById('fileUploadArea');
    const filesContainer = document.getElementById('filesContainer');
    const proceedContainer = document.getElementById('proceedContainer');

    function applyManualTemplate() {
        if (manualArea) manualArea.style.display = 'block';
        if (fileUploadArea) fileUploadArea.style.display = 'none';
        if (filesContainer) filesContainer.style.display = 'none';
        if (proceedContainer) proceedContainer.style.display = 'none';
    }

    function applyAutoTemplate() {
        if (manualArea) manualArea.style.display = 'none';
        if (fileUploadArea) fileUploadArea.style.display = 'block';
        if (filesContainer) filesContainer.style.display = '';
        if (proceedContainer) proceedContainer.style.display = 'none';
    }

    function updateMode() {
        if (modeManual && modeManual.checked) {
            applyManualTemplate();
        } else {
            applyAutoTemplate();
        }
    }

    if (modeAuto) modeAuto.addEventListener('change', updateMode);
    if (modeManual) modeManual.addEventListener('change', updateMode);

    // initialize on load
    updateMode();
});
</script>

<script>
// mode-card click handling to keep visuals and radios in sync
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.mode-card').forEach(function(card) {
        card.addEventListener('click', function(e) {
            var input = card.querySelector('input[type="radio"]');
            if (input) {
                input.checked = true;
                input.dispatchEvent(new Event('change'));
            }
            document.querySelectorAll('.mode-card').forEach(function(c){ c.classList.remove('selected'); });
            card.classList.add('selected');
        });
    });

    // ensure selected class matches radio initial state
    var checked = document.querySelector('input[name="importMode"]:checked');
    if (checked) {
        var parent = checked.closest('.mode-card');
        if (parent) {
            document.querySelectorAll('.mode-card').forEach(function(c){ c.classList.remove('selected'); });
            parent.classList.add('selected');
        }
    }
});
</script>

<!-- Drag and Drop File Upload under the Developer Area -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script>
    // Adapted Auto / Batch upload logic from billspay-transaction.php
    document.addEventListener('DOMContentLoaded', function() {
        const fileUploadArea = $('#fileUploadArea');
        const fileInput = $('#fileInput');
        const filesContainer = $('#filesContainer');
        const proceedContainer = $('#proceedContainer');
        const proceedBtn = $('#proceedBtn');

        // Global array to store added files
        var uploadedFiles = window.uploadedFiles || [];

        // Click to open file dialog
        fileUploadArea.on('click', function() {
            if (fileInput.length && fileInput[0]) {
                try { fileInput[0].click(); } catch (e) { fileInput.trigger('click'); }
            }
        });

        // File input change
        fileInput.on('change', function(e) { handleFiles(e.target.files); });

        // Drag/drop visual states
        fileUploadArea.on('dragover', function(e){ e.preventDefault(); e.stopPropagation(); $(this).addClass('drag-over'); });
        fileUploadArea.on('dragleave dragend', function(e){ e.preventDefault(); e.stopPropagation(); $(this).removeClass('drag-over'); });
        fileUploadArea.on('drop', function(e){
            e.preventDefault(); e.stopPropagation(); $(this).removeClass('drag-over');
            const files = e.originalEvent.dataTransfer.files; handleFiles(files);
        });

        function handleFiles(files) {
            const fileArray = Array.from(files || []);
            const allowed = ['xls','xlsx','csv','xlsm','xlsb','ods','tsv'];
            const excelFiles = fileArray.filter(f => {
                const ext = (f.name.split('.').pop() || '').toLowerCase();
                return allowed.includes(ext);
            });
            if (excelFiles.length === 0) {
                Swal.fire({ icon: 'warning', title: 'Invalid File Type', text: 'Please select only spreadsheet files (xls, xlsx, csv, xlsm, xlsb, ods, tsv)', confirmButtonText: 'OK' });
                return;
            }
            excelFiles.forEach(f => processFile(f));
        }

        // CSV/TSV identifier extractor: returns { partnerId, sourceType, usedDelimiter, rowIndex }
        function parseCsvIdentifiers(text, ext) {
            text = (text || '').replace(/^\uFEFF/, '');
            const delimCandidates = ext === 'tsv' ? ['\t', ',', ';', '|'] : [',', ';', '\t', '|'];

            function parseRows(input, delimiter) {
                const rows = [];
                let row = [];
                let field = '';
                let inQuotes = false;
                for (let i = 0; i < input.length; i++) {
                    const ch = input[i];
                    const next = input[i+1];
                    if (ch === '"') {
                        if (inQuotes && next === '"') { field += '"'; i++; }
                        else { inQuotes = !inQuotes; }
                    } else if (!inQuotes && ch === delimiter) {
                        row.push(field); field = '';
                    } else if (!inQuotes && (ch === '\n' || ch === '\r')) {
                        if (ch === '\r' && next === '\n') { i++; }
                        row.push(field); field = '';
                        rows.push(row); row = [];
                    } else {
                        field += ch;
                    }
                }

                    // Manual form: intercept submit to run duplicate check before uploading
                    const manualForm = document.getElementById('manualUploadForm');
                    if (manualForm) {
                        // helper: extract report date from uploaded file (A3 cell) and return {raw, iso}
                        function extractReportDateFromFile(file, cb) {
                            const ext = (file.name.split('.').pop() || '').toLowerCase();
                            try {
                                if (ext === 'csv' || ext === 'tsv' || ext === 'txt') {
                                    const r = new FileReader();
                                    r.onload = function(ev) {
                                        try {
                                            const txt = (ev.target.result || '').toString();
                                            const lines = txt.split(/\r?\n/);
                                            // prefer line 3 (index 2) then fallback to first non-empty
                                            const candidate = (lines[2] || lines.find(l => l.trim() !== '') || '').toString();
                                            const m = candidate.match(/([A-Za-z]+\s+\d{1,2}\s+\d{4})/i);
                                            if (m && m[1]) {
                                                const raw = m[1].trim();
                                                const ts = Date.parse(raw);
                                                const iso = !isNaN(ts) ? (new Date(ts).toISOString().slice(0,10)) : '';
                                                return cb(raw, iso);
                                            }
                                            // fallback: try entire candidate
                                            const ts2 = Date.parse(candidate);
                                            if (!isNaN(ts2)) return cb(candidate.trim(), new Date(ts2).toISOString().slice(0,10));
                                        } catch(e) {}
                                        return cb('', '');
                                    };
                                    r.readAsText(file);
                                } else {
                                    const r2 = new FileReader();
                                    r2.onload = function(ev2) {
                                        try {
                                            const data = new Uint8Array(ev2.target.result);
                                            const wb = XLSX.read(data, { type: 'array' });
                                            const sh = wb.Sheets[wb.SheetNames[0]];
                                            const a3 = sh && sh['A3'] ? String(sh['A3'].v || '').trim() : '';
                                            if (a3) {
                                                const mm = a3.match(/([A-Za-z]+\s+\d{1,2}\s+\d{4})/i);
                                                if (mm && mm[1]) {
                                                    const raw = mm[1].trim();
                                                    const ts = Date.parse(raw);
                                                    const iso = !isNaN(ts) ? (new Date(ts).toISOString().slice(0,10)) : '';
                                                    return cb(raw, iso);
                                                }
                                                const ts2 = Date.parse(a3);
                                                if (!isNaN(ts2)) return cb(a3.trim(), new Date(ts2).toISOString().slice(0,10));
                                            }
                                        } catch(e) {}
                                        return cb('', '');
                                    };
                                    r2.readAsArrayBuffer(file);
                                }
                            } catch (ex) { return cb('', ''); }
                        }

                        manualForm.addEventListener('submit', function(e) {
                            e.preventDefault();
                            const fileInputManual = manualForm.querySelector('input[name="import_file"]');
                            if (!fileInputManual || !fileInputManual.files || fileInputManual.files.length === 0) {
                                Swal.fire({ icon: 'warning', title: 'No file selected', text: 'Please choose a file to upload.' });
                                return;
                            }
                            const f = fileInputManual.files[0];

                            // extract report date first, then run duplicate check
                            $('#loading-overlay').show();
                            extractReportDateFromFile(f, function(raw, iso) {
                                try {
                                    // set hidden inputs so normal form submit includes them
                                    let hid = document.getElementById('manualReportDate');
                                    if (!hid) { hid = document.createElement('input'); hid.type='hidden'; hid.name='report_date'; hid.id='manualReportDate'; manualForm.appendChild(hid); }
                                    let hid2 = document.getElementById('manualReportDateRaw');
                                    if (!hid2) { hid2 = document.createElement('input'); hid2.type='hidden'; hid2.name='report_date_raw'; hid2.id='manualReportDateRaw'; manualForm.appendChild(hid2); }
                                    hid.value = iso || '';
                                    hid2.value = raw || '';
                                } catch(e) {}

                                // Build FormData for duplicate check (server accepts files[] + partner_ids[] + source_types[])
                                const fd = new FormData();
                                fd.append('files[]', f);
                                const src = manualForm.querySelector('select[name="fileType"]') ? manualForm.querySelector('select[name="fileType"]').value : '';
                                fd.append('source_types[]', src || 'KPX');
                                // include extracted report date for server-side use in pre-check if desired
                                if ((iso || '') !== '') fd.append('report_dates[]', iso);
                                else fd.append('report_dates[]', '');
                                fd.append('check_duplicates', '1');

                                // send duplicate check
                                $.ajax({ url: '../../../models/saved/saved_billspayImportCancelledFile.php', type: 'POST', data: fd, processData: false, contentType: false, dataType: 'json' })
                                    .done(function(resp) {
                                        $('#loading-overlay').hide();
                                        if (resp && resp.success && Array.isArray(resp.files) && resp.files.length > 0) {
                                            const files = resp.files;
                                            const dup = files.filter(x => x.hasDuplicates);
                                            if (dup.length > 0) {
                                                showDuplicateModal(files, dup);
                                                return;
                                            }
                                            // no duplicates — submit the form (will hit server upload handler)
                                            manualForm.submit();
                                        } else {
                                            Swal.fire({ icon: 'error', title: 'Validation Error', text: (resp && resp.error) ? resp.error : 'Unable to validate file.' });
                                        }
                                    }).fail(function(xhr, status, err) {
                                        $('#loading-overlay').hide();
                                        Swal.fire({ icon: 'error', title: 'Validation Error', text: 'An error occurred while checking for duplicates. Please try again.' });
                                    });
                            });
                        });
                    }
                if (field !== '' || row.length > 0) { row.push(field); rows.push(row); }
                return rows;
            }

            // helper: mark rows that look suspicious (embedded xml/zip fragments)
            function isSuspiciousRow(r) {
                if (!r || !r.length) return false;
                const joined = r.join(' ').toString();
                return /<\?xml|<worksheet|<sheetData|<c r=|<a:theme|PK\x03\x04|\x00|xl\//i.test(joined) || /<[^>]+>/.test(joined) && (joined.match(/</g)||[]).length > 3;
            }

            let rows = [];
            let usedDelim = null;
            for (let d of delimCandidates) {
                rows = parseRows(text, d);
                const idx = rows.findIndex(r => ((r[6]||'').toString().trim() !== '' || (r[7]||'').toString().trim() !== ''));
                if (idx !== -1) { usedDelim = d; break; }
            }

            // prefer row 3 (index 2) when available and not suspicious
            let finalRow = [];
            let finalRowIndex = -1;
            if (rows && rows.length) {
                if (rows[2] && ((rows[2][6]||'').toString().trim() !== '' || (rows[2][7]||'').toString().trim() !== '') && !isSuspiciousRow(rows[2])) {
                    finalRow = rows[2]; finalRowIndex = 2;
                }

                // otherwise prefer top window rows 0..5 that are not suspicious
                if (finalRowIndex === -1) {
                    for (let i = 0; i <= Math.min(5, rows.length-1); i++) {
                        const r = rows[i] || [];
                        if (((r[6]||'').toString().trim() !== '' || (r[7]||'').toString().trim() !== '') && !isSuspiciousRow(r)) { finalRow = r; finalRowIndex = i; break; }
                    }
                }

                // if still not found, scan all rows for non-suspicious G/H
                if (finalRowIndex === -1) {
                    for (let i = 0; i < rows.length; i++) {
                        const r = rows[i] || [];
                        if (((r[6]||'').toString().trim() !== '' || (r[7]||'').toString().trim() !== '') && !isSuspiciousRow(r)) { finalRow = r; finalRowIndex = i; break; }
                    }
                }

                // forgiving search for KPX/KP7 in any column (prefer non-suspicious rows)
                if (finalRowIndex === -1) {
                    for (let i = 0; i < rows.length; i++) {
                        const r = rows[i] || [];
                        if (isSuspiciousRow(r)) continue;
                        for (let c = 0; c < r.length; c++) {
                            const cell = (r[c] || '').toString().trim().toUpperCase();
                            if (cell === 'KPX' || cell === 'KP7') { finalRow = r; finalRowIndex = i; break; }
                        }
                        if (finalRowIndex !== -1) break;
                    }
                }

                // as last resort accept suspicious rows (preserve original behaviour)
                if (finalRowIndex === -1) {
                    const idx = rows.findIndex(r => ((r[6]||'').toString().trim() !== '' || (r[7]||'').toString().trim() !== ''));
                    if (idx !== -1) { finalRow = rows[idx]; finalRowIndex = idx; }
                    else { finalRow = rows[2] || rows[0] || []; finalRowIndex = rows.indexOf(finalRow) >= 0 ? rows.indexOf(finalRow) : 0; }
                }
            }

            const finalPartner = (finalRow[6] || '').toString().trim();
            const finalSource = ((finalRow[7] || '') + '').toString().trim();

            // Determine cell addresses (column letters) for partner/source within finalRow
            function colLetter(n) {
                let s = '';
                let num = n + 1; // make 1-based
                while (num > 0) {
                    const m = (num - 1) % 26;
                    s = String.fromCharCode(65 + m) + s;
                    num = Math.floor((num - 1) / 26);
                }
                return s;
            }

            let partnerCellCol = null, sourceCellCol = null;
            if (finalRow && finalRow.length) {
                for (let c = 0; c < finalRow.length; c++) {
                    const cell = (finalRow[c] || '').toString();
                    if (!partnerCellCol && finalPartner) {
                        if (cell.indexOf(finalPartner) !== -1 || (finalPartner && cell.trim() === finalPartner)) partnerCellCol = c;
                    }
                    if (!sourceCellCol && finalSource) {
                        if (cell.toString().toUpperCase().indexOf(String(finalSource).toUpperCase()) !== -1 || cell.toString().toUpperCase() === String(finalSource).toUpperCase()) sourceCellCol = c;
                    }
                }
            }

            const partnerCellAddr = (partnerCellCol !== null) ? (colLetter(partnerCellCol) + (finalRowIndex + 1)) : null;
            const sourceCellAddr = (sourceCellCol !== null) ? (colLetter(sourceCellCol) + (finalRowIndex + 1)) : null;

            // Debug log final detection including cell addresses
            try {
                console.log('CSV identifier detection', {
                    usedDelimiter: usedDelim,
                    rowsCount: rows.length,
                    finalRowIndex: finalRowIndex,
                    finalSample: finalRow,
                    partnerId: finalPartner,
                    sourceType: finalSource,
                    partnerCellCol: partnerCellCol,
                    partnerCellAddr: partnerCellAddr,
                    sourceCellCol: sourceCellCol,
                    sourceCellAddr: sourceCellAddr,
                    suspicious: isSuspiciousRow(finalRow)
                });
            } catch(e) { /* ignore */ }

            return { partnerId: finalPartner || '', sourceType: finalSource || '', sampleRow: finalRow, usedDelimiter: usedDelim, rowIndex: finalRowIndex, rowsCount: rows.length, partnerCellCol, partnerCellAddr, sourceCellCol, sourceCellAddr };
        }

        function processFile(file) {
            window._filesBeingRead = window._filesBeingRead || 0; window._filesBeingRead++; $('#loading-overlay').css('display','flex'); proceedBtn.prop('disabled', true);
            const ext = (file.name.split('.').pop() || '').toLowerCase();
            const reader = new FileReader();

            // helper to parse workbook and perform validation + enqueue file
            function parseWorkbook(workbook) {
                const firstSheet = workbook.Sheets[workbook.SheetNames[0]];

                const partnerIdCell = firstSheet['G3'];
                const partnerId = partnerIdCell ? String(partnerIdCell.v).trim() : '';
                const sourceTypeCell = firstSheet['H3'];
                let sourceType = sourceTypeCell ? String(sourceTypeCell.v).trim().toUpperCase() : '';

                // Debug: log exact values read from workbook cells
                try {
                    console.log('Parsed workbook identifiers', {
                        file: file.name,
                        rawG3: partnerIdCell ? partnerIdCell.v : null,
                        rawH3: sourceTypeCell ? sourceTypeCell.v : null,
                        partnerId: partnerId,
                        sourceType: sourceType
                    });
                } catch (lerr) { console.log('Workbook log error', lerr); }

                if (sourceType !== 'KPX' && sourceType !== 'KP7') {
                    Swal.fire({ icon:'error', title:'Invalid Source Type', html:`File: <strong>${file.name}</strong><br>Source Type in H3 must be KPX or KP7. Found: "${sourceType}"` });
                    window._filesBeingRead--; if (window._filesBeingRead <= 0) { window._filesBeingRead = 0; $('#loading-overlay').hide(); proceedBtn.prop('disabled', false); }
                    return false;
                }
                if (!partnerId) {
                    Swal.fire({ icon:'error', title:'Missing Partner ID', html:`File: <strong>${file.name}</strong><br>Partner ID not found in G3.` });
                    window._filesBeingRead--; if (window._filesBeingRead <= 0) { window._filesBeingRead = 0; $('#loading-overlay').hide(); proceedBtn.prop('disabled', false); }
                    return false;
                }

                // prevent duplicate filename entries
                const existing = uploadedFiles.find(u => u.name === file.name);
                if (existing) {
                    Swal.fire({ icon:'warning', title:'Duplicate File', text:`"${file.name}" has already been added.`, confirmButtonText:'OK' });
                    window._filesBeingRead--; if (window._filesBeingRead <= 0) { window._filesBeingRead = 0; $('#loading-overlay').hide(); proceedBtn.prop('disabled', false); }
                    return false;
                }

                // fetch partner name
                $.ajax({ url: '../../../fetch/get_partner_name.php', method: 'POST', data: { partner_id: partnerId }, dataType: 'json', success: function(resp){
                    const partnerName = resp.success ? resp.partner_name : 'Unknown Partner';
                    const reportCell = firstSheet['A3'];
                    const reportRaw = reportCell ? String(reportCell.v).trim() : '';
                    let reportDateRaw = '';
                    let reportDate = null;
                    if (reportRaw) {
                        const m = reportRaw.match(/([A-Za-z]+\s+\d{1,2}\s+\d{4})/i);
                        if (m && m[1]) {
                            reportDateRaw = m[1].trim();
                            const ts = Date.parse(reportDateRaw);
                            if (!isNaN(ts)) reportDate = new Date(ts).toISOString().slice(0,10);
                        } else {
                            const ts2 = Date.parse(reportRaw);
                            if (!isNaN(ts2)) { reportDateRaw = reportRaw; reportDate = new Date(ts2).toISOString().slice(0,10); }
                        }
                    }
                    const fileData = { file: file, name: file.name, partnerId: partnerId, partnerName: partnerName, sourceType: sourceType, sampleG: partnerId, sampleH: sourceTypeCell ? String(sourceTypeCell.v) : '', sampleRowIndex: 2, usedDelimiter: null, reportDateRaw: reportDateRaw, reportDate: reportDate, id: Date.now() + Math.random() };
                    uploadedFiles.push(fileData); renderFileCards();
                    window._filesBeingRead--; if (window._filesBeingRead <= 0) { window._filesBeingRead = 0; $('#loading-overlay').hide(); proceedBtn.prop('disabled', false); }
                }, error: function(){
                    const reportCell = firstSheet && firstSheet['A3'] ? String(firstSheet['A3'].v).trim() : '';
                    let reportDateRawErr = '';
                    let reportDateErr = null;
                    if (reportCell) {
                        const mm = reportCell.match(/([A-Za-z]+\s+\d{1,2}\s+\d{4})/i);
                        if (mm && mm[1]) {
                            reportDateRawErr = mm[1].trim();
                            const tss = Date.parse(reportDateRawErr);
                            if (!isNaN(tss)) reportDateErr = new Date(tss).toISOString().slice(0,10);
                        }
                    }
                    const fileData = { file: file, name: file.name, partnerId: partnerId, partnerName: 'Loading...', sourceType: sourceType, sampleG: partnerId, sampleH: sourceTypeCell ? String(sourceTypeCell.v) : '', sampleRowIndex: 2, usedDelimiter: null, reportDateRaw: reportDateRawErr, reportDate: reportDateErr, id: Date.now() + Math.random() };
                    uploadedFiles.push(fileData); renderFileCards(); window._filesBeingRead--; if (window._filesBeingRead <= 0) { window._filesBeingRead = 0; $('#loading-overlay').hide(); proceedBtn.prop('disabled', false); }
                }});

                return true;
            }

            reader.onload = function(e) {
                try {
                    let workbook;
                    if (ext === 'csv' || ext === 'tsv') {
                        try {
                            const text = e.target.result || '';
                            const parsed = parseCsvIdentifiers(text, ext);
                            const partnerId = parsed.partnerId;
                            const sourceType = (parsed.sourceType || '').toString().trim().toUpperCase();

                            // Debug log parsed CSV info
                            try { console.log('Parsed CSV identifiers', { file: file.name, partnerId: partnerId, sourceType: sourceType, usedDelimiter: parsed.usedDelimiter, rowIndex: parsed.rowIndex, sampleRow: parsed.sampleRow }); } catch (l) { console.log('CSV log error', l); }

                            // Fallback: if the uploaded .csv actually contains binary/xlsx or XML content
                            const firstCell = (parsed.sampleRow && parsed.sampleRow[0]) ? String(parsed.sampleRow[0]) : '';
                            // broaden detection: look for zip header, xml markers, or worksheet/sheetData tags commonly embedded
                            const looksLikeZip = firstCell.indexOf('PK\u0003\u0004') !== -1
                                || firstCell.startsWith('PK')
                                || /<a:theme/i.test(firstCell)
                                || /<\?xml/i.test(firstCell)
                                || /<worksheet/i.test(firstCell)
                                || /<sheetData/i.test(firstCell)
                                || /<c r=/i.test(firstCell)
                                || /xl\//i.test(firstCell)
                                || /\x00/.test(firstCell);
                            if (looksLikeZip) {
                                console.log('CSV appears to contain binary/xlsx content — retrying as binary workbook for', file.name);
                                const readerBin = new FileReader();
                                readerBin.onload = function(ev2) {
                                    try {
                                        const data2 = new Uint8Array(ev2.target.result);
                                        const workbook2 = XLSX.read(data2, { type: 'array' });
                                        parseWorkbook(workbook2);
                                    } catch (re) {
                                        console.error('Binary fallback parse failed for', file.name, re);
                                        Swal.fire({ icon:'error', title:'File Processing Error', html:`Cannot parse file as CSV or XLSX: <strong>${file.name}</strong><br>${re && re.message ? re.message : re}` });
                                        window._filesBeingRead--; if (window._filesBeingRead <= 0) { window._filesBeingRead = 0; $('#loading-overlay').hide(); proceedBtn.prop('disabled', false); }
                                    }
                                };
                                try { readerBin.readAsArrayBuffer(file); } catch (rerr) {
                                    console.error('readAsArrayBuffer failed in fallback for', file.name, rerr);
                                    Swal.fire({ icon:'error', title:'File Processing Error', html:`Cannot parse file: <strong>${file.name}</strong>.` });
                                    window._filesBeingRead--; if (window._filesBeingRead <= 0) { window._filesBeingRead = 0; $('#loading-overlay').hide(); proceedBtn.prop('disabled', false); }
                                }
                                return;
                            }

                            if (sourceType !== 'KPX' && sourceType !== 'KP7') {
                                Swal.fire({ icon:'error', title:'Invalid Source Type', html:`File: <strong>${file.name}</strong><br>Source Type in H3 must be KPX or KP7. Found: "${sourceType}"` });
                                window._filesBeingRead--; if (window._filesBeingRead <= 0) { window._filesBeingRead = 0; $('#loading-overlay').hide(); proceedBtn.prop('disabled', false); }
                                return;
                            }
                            if (!partnerId) {
                                Swal.fire({ icon:'error', title:'Missing Partner ID', html:`File: <strong>${file.name}</strong><br>Partner ID not found in G3.` });
                                window._filesBeingRead--; if (window._filesBeingRead <= 0) { window._filesBeingRead = 0; $('#loading-overlay').hide(); proceedBtn.prop('disabled', false); }
                                return;
                            }

                            const existing = uploadedFiles.find(u => u.name === file.name);
                            if (existing) {
                                Swal.fire({ icon:'warning', title:'Duplicate File', text:`"${file.name}" has already been added.`, confirmButtonText:'OK' });
                                window._filesBeingRead--; if (window._filesBeingRead <= 0) { window._filesBeingRead = 0; $('#loading-overlay').hide(); proceedBtn.prop('disabled', false); }
                                return;
                            }

                            $.ajax({ url: '../../../fetch/get_partner_name.php', method: 'POST', data: { partner_id: partnerId }, dataType: 'json', success: function(resp){
                                const partnerName = resp.success ? resp.partner_name : 'Unknown Partner';
                                // attempt to read report date from row/column if available (CSV/TSV forgiving)
                                let reportDateRawParsed = '';
                                let reportDateParsed = null;
                                try {
                                    if (parsed && parsed.rowIndex === 2 && parsed.sampleRow && parsed.sampleRow[0]) {
                                        const candidate = String(parsed.sampleRow[0]).trim();
                                        const mm = candidate.match(/([A-Za-z]+\s+\d{1,2}\s+\d{4})/i);
                                        if (mm && mm[1]) {
                                            reportDateRawParsed = mm[1].trim();
                                            const ts = Date.parse(reportDateRawParsed);
                                            if (!isNaN(ts)) reportDateParsed = new Date(ts).toISOString().slice(0,10);
                                        }
                                    }
                                } catch(e) {}
                                const fileData = { file: file, name: file.name, partnerId: partnerId, partnerName: partnerName, sourceType: sourceType, sampleG: partnerId, sampleH: parsed.sampleRow && parsed.sampleRow[7] ? parsed.sampleRow[7] : '', sampleRowIndex: parsed.rowIndex, usedDelimiter: parsed.usedDelimiter, reportDateRaw: reportDateRawParsed, reportDate: reportDateParsed, id: Date.now() + Math.random() };
                                uploadedFiles.push(fileData); renderFileCards();
                                window._filesBeingRead--; if (window._filesBeingRead <= 0) { window._filesBeingRead = 0; $('#loading-overlay').hide(); proceedBtn.prop('disabled', false); }
                            }, error: function(){
                                let reportDateRawParsedErr = '';
                                let reportDateParsedErr = null;
                                try {
                                    if (parsed && parsed.rowIndex === 2 && parsed.sampleRow && parsed.sampleRow[0]) {
                                        const candidate = String(parsed.sampleRow[0]).trim();
                                        const mm = candidate.match(/([A-Za-z]+\s+\d{1,2}\s+\d{4})/i);
                                        if (mm && mm[1]) {
                                            reportDateRawParsedErr = mm[1].trim();
                                            const ts = Date.parse(reportDateRawParsedErr);
                                            if (!isNaN(ts)) reportDateParsedErr = new Date(ts).toISOString().slice(0,10);
                                        }
                                    }
                                } catch(e) {}
                                const fileData = { file: file, name: file.name, partnerId: partnerId, partnerName: 'Loading...', sourceType: sourceType, sampleG: partnerId, sampleH: parsed.sampleRow && parsed.sampleRow[7] ? parsed.sampleRow[7] : '', sampleRowIndex: parsed.rowIndex, usedDelimiter: parsed.usedDelimiter, reportDateRaw: reportDateRawParsedErr, reportDate: reportDateParsedErr, id: Date.now() + Math.random() };
                                uploadedFiles.push(fileData); renderFileCards(); window._filesBeingRead--; if (window._filesBeingRead <= 0) { window._filesBeingRead = 0; $('#loading-overlay').hide(); proceedBtn.prop('disabled', false); }
                            }});
                        } catch (parseErr) {
                            console.error('CSV parse error:', parseErr);
                            Swal.fire({ icon:'error', title:'File Processing Error', html:`Error parsing CSV: <strong>${file.name}</strong><br>${parseErr && parseErr.message ? parseErr.message : parseErr}` });
                            window._filesBeingRead--; if (window._filesBeingRead <= 0) { window._filesBeingRead = 0; $('#loading-overlay').hide(); proceedBtn.prop('disabled', false); }
                        }
                    } else {
                        const data = new Uint8Array(e.target.result);
                        workbook = XLSX.read(data, { type: 'array' });
                        parseWorkbook(workbook);
                    }
                } catch (err) {
                    console.error('Error processing file:', err);
                    // try fallback using binary string if not attempted yet
                    if (!file._binaryTried && ext !== 'csv' && ext !== 'tsv') {
                        file._binaryTried = true;
                        const reader2 = new FileReader();
                        reader2.onload = function(e2) {
                            try {
                                const workbook2 = XLSX.read(e2.target.result, { type: 'binary' });
                                parseWorkbook(workbook2);
                            } catch (err2) {
                                console.error('Fallback binary read failed:', err2);
                                Swal.fire({ icon:'error', title:'File Processing Error', html:`Error reading file: <strong>${file.name}</strong><br>${err2.message}` });
                                window._filesBeingRead--; if (window._filesBeingRead <= 0) { window._filesBeingRead = 0; $('#loading-overlay').hide(); proceedBtn.prop('disabled', false); }
                            }
                        };
                        try { reader2.readAsBinaryString(file); } catch (rerr) {
                            console.error('readAsBinaryString not supported:', rerr);
                            Swal.fire({ icon:'error', title:'File Processing Error', html:`Cannot parse file: <strong>${file.name}</strong>. Browser does not support binary fallback.` });
                            window._filesBeingRead--; if (window._filesBeingRead <= 0) { window._filesBeingRead = 0; $('#loading-overlay').hide(); proceedBtn.prop('disabled', false); }
                        }
                    } else {
                        Swal.fire({ icon:'error', title:'File Processing Error', html:`Error reading file: <strong>${file.name}</strong><br>${err.message}` });
                        window._filesBeingRead--; if (window._filesBeingRead <= 0) { window._filesBeingRead = 0; $('#loading-overlay').hide(); proceedBtn.prop('disabled', false); }
                    }
                }
            };

            if (ext === 'csv' || ext === 'tsv') {
                reader.readAsText(file);
            } else {
                reader.readAsArrayBuffer(file);
            }
        }

        function renderFileCards() {
            filesContainer.empty(); if (!uploadedFiles.length) { proceedContainer.hide(); return; }
            uploadedFiles.forEach(fd => {
                // Determine status icon based on file state (keep consistent with transaction UI)
                let statusIcon = '';
                if (fd.status === 'reading') statusIcon = '<i class="fa-solid fa-spinner fa-spin text-primary"></i>';
                else if (fd.status === 'valid') statusIcon = '<i class="fa-solid fa-circle-check text-success"></i>';
                else if (fd.status === 'duplicates') statusIcon = '<i class="fa-solid fa-circle-xmark text-warning"></i>';
                else if (fd.status === 'error') statusIcon = '<i class="fa-solid fa-circle-exclamation text-danger"></i>';

                const card = $(`
                    <div class="file-card" data-id="${fd.id}">
                        <div class="file-card-header">
                            <div class="file-card-info">
                                <div class="file-card-label">Filename ${statusIcon ? `<span class="ms-2">${statusIcon}</span>` : ''}</div>
                                <div class="file-card-value">${fd.name}</div>
                            </div>
                            <div class="file-card-delete" title="Remove file"><i class="fa-solid fa-xmark"></i></div>
                        </div>
                        <div class="file-card-body"></div>
                        <div class="file-card-footer">
                            <div class="file-card-detail">
                                <div class="file-card-label">Partner ID</div>
                                <div class="file-card-value partner-tooltip">${fd.partnerId}<span class="tooltip-text">${fd.partnerName}</span></div>
                            </div>
                            <div class="file-card-detail">
                                <div class="file-card-label">Source Type</div>
                                <div class="file-card-value">
                                    <span class="badge-source ${fd.sourceType === 'KPX' ? 'badge-kpx' : 'badge-kp7'}">${fd.sourceType}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                `);
                card.find('.file-card-delete').on('click', function(){ removeFile(fd.id); });
                filesContainer.append(card);
            });
            proceedContainer.show();
        }

        function removeFile(id) { uploadedFiles = uploadedFiles.filter(f => f.id !== id); renderFileCards(); }

        // Proceed button: start duplicate checks similar to transaction page
        proceedBtn.on('click', function(){
            if (uploadedFiles.length === 0) { Swal.fire({ icon:'warning', title:'No Files Selected', text:'Please select at least one file to proceed.' }); return; }
            $('#loading-overlay').css('display','flex'); checkForDuplicates();
        });

        function checkForDuplicates() {
            uploadedFiles.forEach(f => f.status = 'reading'); renderFileCards();
            const BATCH_SIZE = 50; let index = 0; const aggregateResults = [];
            $('#loading-overlay').css('display','flex'); $('#loading-overlay .loading-spinner').hide();

            var modalHtml = '<div class="duplicate-modal">'
                + '<div class="duplicate-modal-content">'
                + '<div class="duplicate-modal-header">'
                + '<div class="duplicate-modal-header-title">'
                + '<i class="fa-solid fa-shield-halved"></i>'
                + '<h4 id="duplicate-check-header">Checking files (0/' + uploadedFiles.length + ')</h4>'
                + '</div>'
                + '<div class="duplicate-progress-bar-container">'
                + '<div class="duplicate-progress-bar" id="duplicate-progress-bar"></div>'
                + '</div>'
                + '</div>'
                + '<div class="duplicate-modal-body">'
                + '<div id="duplicate-check-list"></div>'
                + '</div>'
                + '<div class="duplicate-modal-footer">'
                + '<div id="duplicate-check-footer">'
                + '<span class="duplicate-footer-icon"><i class="fa-solid fa-file-circle-check"></i> Validating files</span>'
                + '<span id="duplicate-progress-text"><strong>0</strong> / ' + uploadedFiles.length + '</span>'
                + '</div>'
                + '</div>'
                + '</div></div>';

            $('body').append(modalHtml);
            const $list = $('#duplicate-check-list'); $list.empty(); uploadedFiles.forEach((f, idx) => { $list.append($(`<div class="check-item checking" data-idx="${idx}"><div class="name">${f.name}</div><div class="status"><i class="fa-solid fa-spinner fa-spin status-icon-checking"></i></div></div>`)); });

            let processedCount = 0; const totalCount = uploadedFiles.length;
            function updateHeader(){ $('#duplicate-check-header').text('Checking files (' + processedCount + '/' + totalCount + ')'); $('#duplicate-progress-text').html('<strong>' + processedCount + '</strong> / ' + totalCount); $('#duplicate-progress-bar').css('width', ((processedCount/totalCount)*100) + '%'); }

            function processBatch(start) {
                const formData = new FormData(); const batch = uploadedFiles.slice(start, start + BATCH_SIZE);
                batch.forEach(b => { formData.append('files[]', b.file); formData.append('partner_ids[]', b.partnerId); formData.append('source_types[]', b.sourceType); });
                formData.append('check_duplicates', '1');
                return $.ajax({ url: '../../../models/saved/saved_billspayImportCancelledFile.php', type: 'POST', data: formData, processData:false, contentType:false, dataType:'json' });
            }

            function next() {
                if (index >= uploadedFiles.length) {
                    $('#loading-overlay .loading-spinner').show(); const flat = [].concat.apply([], aggregateResults);
                    flat.forEach((res, idx) => { if (uploadedFiles[idx]) { if (res.hasDuplicates) { uploadedFiles[idx].status = 'duplicates'; uploadedFiles[idx].duplicateCount = res.duplicateRows; uploadedFiles[idx].newCount = res.newRows; } else { uploadedFiles[idx].status = 'valid'; } } });
                    renderFileCards(); $('.duplicate-modal').remove(); $('#loading-overlay').hide(); const filesWithDuplicates = flat.filter(f => f.hasDuplicates);
                    if (filesWithDuplicates.length > 0) { showDuplicateModal(flat, filesWithDuplicates); } else { proceedWithUpload('skip'); }
                    return;
                }

                $('#loading-overlay').css('display','flex');
                processBatch(index).done(function(response){ if (response && response.success && Array.isArray(response.files)) {
                    aggregateResults.push(response.files);
                    response.files.forEach(function(res,j){ var globalIndex = index + j; var $item = $list.find('.check-item[data-idx="' + globalIndex + '"]'); if ($item.length) { if (res.hasDuplicates) { $item.removeClass('checking').addClass('warning'); $item.find('.status').html('<i class="fa-solid fa-circle-exclamation status-icon-warning"></i>'); } else { $item.removeClass('checking').addClass('success'); $item.find('.status').html('<i class="fa-solid fa-circle-check status-icon-success"></i>'); } setTimeout(function(){ $item.addClass('fade-up'); setTimeout(function(){ $item.remove(); processedCount++; updateHeader(); }, 400); }, 300 + (j*60)); } }); index += BATCH_SIZE; setTimeout(next,50);
                } else { $('#loading-overlay').hide(); uploadedFiles.forEach(f=>f.status='error'); renderFileCards(); Swal.fire({ icon:'error', title:'Validation Error', text:(response && response.error) ? response.error : 'An error occurred while checking for duplicates.' }); } }).fail(function(xhr,status,error){ $('#loading-overlay .loading-spinner').show(); $('.duplicate-modal').remove(); $('#loading-overlay').hide(); uploadedFiles.forEach(f=>f.status='error'); renderFileCards(); Swal.fire({ icon:'error', title:'Validation Error', text: 'An error occurred while checking for duplicates. Please try again.' }); console.error('Duplicate check batch error:', error, xhr.responseText); });
            }

            next();
        }

        function showDuplicateModal(allFiles, filesWithDuplicates) {
            // For cancellations we enforce Reference No uniqueness — do not allow override.
            let totalDuplicates = 0; let totalNew = 0; let totalRows = 0;
            allFiles.forEach(f=>{ totalDuplicates += f.duplicateRows||0; totalNew += f.newRows||0; totalRows += f.totalRows||0; });
            let fileListHTML = '<div id="duplicate-details" style="max-height:260px; overflow-y:auto; margin-top:12px; text-align:left; border-top:1px solid #eee; padding-top:12px;">';
            filesWithDuplicates.forEach(file => { fileListHTML += `<div style="padding:10px; border:1px solid #eee; margin-bottom:8px; border-radius:6px; background-color:#fff8f0;"><strong>📄 ${file.fileName}</strong><br><small style="color:#666;">Partner: ${file.partnerId || 'N/A'} | Type: ${file.sourceType || 'N/A'}</small><br><small style="color:#d32f2f;">⚠️ ${file.duplicateRows.toLocaleString()} duplicate reference(s) found</small></div>`; });
            fileListHTML += '</div>';

            const summaryHTML = `<div style="text-align:center; margin-bottom:12px;"><div style="background-color:#fff3f3; padding:12px; border-radius:8px; margin-bottom:10px; border-left:4px solid #f44336;"><p style="margin:0; color:#000; font-size:15px;"><strong style="color:#000;">${filesWithDuplicates.length}</strong> file(s) contain duplicate Reference No(s)</p></div><div style="background-color:#fafafa; padding:10px; border-radius:6px;"><p style="margin:6px 0; color:#d32f2f; font-size:14px;"><i class="fa-solid fa-exclamation-triangle"></i> <strong>${totalDuplicates.toLocaleString()}</strong> duplicate reference(s) detected</p></div></div>`;

            // Only offer removal of offending files — cancellation imports must not insert duplicate Reference Nos
            Swal.fire({
                title: '<i class="fa-solid fa-ban" style="color:#d32f2f;"></i> Duplicate Reference Number(s) Detected',
                html: summaryHTML + fileListHTML + '<p style="margin-top:10px; text-align:left; color:#555;">Duplicates must be removed or fixed. Import will not proceed while duplicate Reference No(s) exist.</p>',
                icon: 'error',
                showCloseButton: true,
                showCancelButton: true,
                cancelButtonText: 'Cancel',
                showDenyButton: true,
                denyButtonText: 'Skip',
                confirmButtonText: '<i class="fa-solid fa-trash"></i> Remove Duplicate Files',
                confirmButtonColor: '#6c757d',
                allowOutsideClick:false,
                allowEscapeKey:false,
                width: 700,
                didOpen: () => {}
            }).then((res) => {
                // res.isConfirmed => Remove duplicates from the upload list but do NOT auto-proceed
                // res.isDenied => Remove duplicates and proceed with upload (skip duplicates)
                // res.isDismissed or Cancel => do nothing
                if (res && res.isConfirmed) {
                    filesWithDuplicates.forEach(f => {
                        uploadedFiles = uploadedFiles.filter(u => !(u.name === f.fileName && String(u.partnerId) === String(f.partnerId)));
                    });
                    renderFileCards();
                    if (uploadedFiles.length === 0) {
                        Swal.fire({ icon:'info', title:'Duplicates Removed', text:'All duplicate files were removed. No files left to import.' });
                    } else {
                        Swal.fire({ icon:'info', title:'Duplicates Removed', text:'Duplicate files were removed. Click Proceed to validate remaining files.' });
                    }
                } else if (res && res.isDenied) {
                    // Remove duplicate files and immediately proceed with upload (skipping duplicates)
                    filesWithDuplicates.forEach(f => {
                        uploadedFiles = uploadedFiles.filter(u => !(u.name === f.fileName && String(u.partnerId) === String(f.partnerId)));
                    });
                    renderFileCards();
                    // proceed with upload, passing decision 'skip'
                    proceedWithUpload('skip');
                } else {
                    // Cancelled/closed - do nothing
                }
            });
        }

        function proceedWithUpload(userDecision) {
            $('#loading-overlay').css('display','flex');
            const formData = new FormData();
            uploadedFiles.forEach(f => {
                formData.append('files[]', f.file);
                formData.append('partner_ids[]', f.partnerId);
                formData.append('source_types[]', f.sourceType);
                formData.append('report_dates[]', f.reportDate || '');
            });
            formData.append('upload','1');
            formData.append('user_decision', userDecision || 'skip');
            sessionStorage.setItem('uploadedFilesData', JSON.stringify(uploadedFiles.map(f => ({ name: f.name, partnerId: f.partnerId, partnerName: f.partnerName, sourceType: f.sourceType, reportDate: f.reportDate || '', reportDateRaw: f.reportDateRaw || '' }))));

            $.ajax({
                url: '../../../models/saved/saved_billspayImportCancelledFile.php',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                dataType: 'json',
                success: function(resp) {
                    // If server returned JSON with redirect, trust it; otherwise fallback to known validator path
                    try {
                        console.log('Upload response', resp);
                        if (resp && resp.success && resp.redirect) {
                            window.location.href = resp.redirect;
                            return;
                        }
                    } catch (e) { console.warn('Response parsing error', e); }
                    // fallback redirect
                    window.location.href = '../../../models/saved/saved_billspayImportCancelledFile_NEW.php';
                },
                error: function(xhr, status, err) {
                    $('#loading-overlay').hide();
                    console.error('Upload failed', status, err, xhr.responseText);
                    // Try to show server-provided JSON error if available
                    var msg = 'An error occurred while uploading files. Please try again.';
                    try {
                        var json = xhr && xhr.responseText ? JSON.parse(xhr.responseText) : null;
                        if (json && json.error) msg = json.error;
                    } catch(e) {}
                    Swal.fire({ icon:'error', title:'Upload Error', text: msg });
                }
            });
        }
    });
</script>
</html>