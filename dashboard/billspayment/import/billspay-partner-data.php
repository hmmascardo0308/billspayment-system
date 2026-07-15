<?php
// Connect to the database
include '../../../config/config.php';
require '../../../vendor/autoload.php';

// Start the session
session_start();


if (isset($_SESSION['user_type'])) {
    $current_user_email = '';
    if ($_SESSION['user_type'] === 'admin' && isset($_SESSION['admin_email'])) {
        $current_user_email = $_SESSION['admin_email'];
    } elseif ($_SESSION['user_type'] === 'user' && isset($_SESSION['user_email'])) {
        $current_user_email = $_SESSION['user_email'];
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Partner Data | <?php if($_SESSION['user_type'] === 'admin' || $_SESSION['user_type'] === 'user') echo ucfirst($_SESSION['user_type']); else echo "Guest";?></title>
    <!-- custom CSS file link  -->
    <link rel="stylesheet" href="../../../assets/css/templates/style.css?v=<?php echo time(); ?>">
    <script src="https://kit.fontawesome.com/30b908cc5a.js" crossorigin="anonymous"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="../../../assets/js/sweetalert2.all.min.js"></script>

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
            border: 3px dashed #dee2e6;
            border-radius: 10px;
            padding: 40px;
            text-align: center;
            background-color: #f8f9fa;
            transition: all 0.3s ease;
            cursor: pointer;
            margin-bottom: 20px;
        }

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
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }

        /* Individual File Card */
        .file-card {
            border: 1px solid #dee2e6;
            border-radius: 10px;
            padding: 14px;
            background-color: #fff;
            box-shadow: 0 2px 4px rgba(0,0,0,0.06);
            display: flex;
            flex-direction: column;
            gap: 8px;
            transition: all 0.2s ease;
            min-height: 96px;
            position: relative;
            overflow: hidden;
        }

        .file-card:hover { box-shadow: 0 6px 12px rgba(0,0,0,0.08); }

        .file-card-header { display:flex; gap:10px; align-items:flex-start; }

        .file-card-info { flex: 1 1 auto; }

        .file-card-label { font-size: 12px; color: #6c757d; font-weight:600; margin-bottom:4px; }
        .file-card-value { font-size: 14px; color:#212529; font-weight:600; word-break: break-word; }

        .file-card-delete { cursor:pointer; color:#6c757d; padding:6px; border-radius:6px; background: rgba(255,255,255,0.6); position:absolute; top:10px; right:10px; z-index:6; }
        .file-card-delete:hover { background:#f8f9fa; color:#dc3545; transform: none; }

        /* Footer container stays inside card flow and is pushed to bottom */
        .file-card-footer {
            margin-top: auto;
            text-align: right;
            display: flex;
            gap: 8px;
            align-items: center;
            justify-content: flex-end;
            padding-top: 6px;
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

        /* Duplicate check live list inside overlay (improved) */
        .duplicate-wrapper {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 12px;
        }

        #duplicate-check-list {
            width: 560px;
            max-height: 420px;
            overflow: auto;
            background: #ffffff;
            border-radius: 8px;
            padding: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.35);
            text-align: left;
        }

        #duplicate-check-header {
            font-weight: 700;
            margin-bottom: 8px;
            font-size: 14px;
            color: #333;
        }

        .check-item {
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap: 12px;
            padding:10px 8px;
            border-bottom:1px solid #f1f1f1;
            font-size:13px;
        }

        .check-item .name { flex:1; margin-right:12px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .check-item .status { width:40px; text-align:center; margin-left:8px; }

        .fade-up {
            animation: fadeUp 700ms forwards;
        }

        @keyframes fadeUp {
            to { transform: translateY(-18px); opacity: 0; }
        }

        .empty-state {
            text-align: center;
            padding: 20px;
            color: #6c757d;
        }
        
        /* Branded section header and card */
        .bp-section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 6px 0 0 0;
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
                    <h2>Import Partner Data - (UNDER CONSTRUCTION)</h2>
                    <!-- <p class="bp-section-sub">Import and manage partner data for bills payment transactions.</p> -->
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
                                        <small>Drag & Drop</small>
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
                    <h5>Drag & Drop Files Here</h5>
                    <p class="text-muted">or click to browse</p>
                    <p class="text-muted"><small>Supports multiple Excel files (.xls, .xlsx)</small></p>
                    <input type="file" id="fileInput" accept=".xls,.xlsx" multiple style="display: none;">
                </div>

                <!-- Manual Import Area (hidden by default) -->
                <div id="manualArea" style="display:none;">
                    <form id="manualUploadForm" action="#" method="post" enctype="multipart/form-data">
                        <div class="row mt-3">
                            <div class="col-md-5 mb-3">
                                <div class="d-flex align-items-center">
                                    <label class="form-label me-2 mb-0">Partners Name:</label>
                                    <input list="manualCompanyList" id="manualCompanyInput" name="company" class="form-control" placeholder="Search or type company name" required />
                                    <datalist id="manualCompanyList"></datalist>
                                    <!-- hidden select kept for compatibility -->
                                    <select id="manualCompanyDropdown" name="company_select" style="display:none;"></select>
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
                                <input id="manualFileInput" type="file" name="import_file" accept=".xls,.xlsx" class="form-control me-2" />
                                <input type="submit" class="btn btn-danger" id="manualProceed" value="Proceed" style="display:none;">
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Files Container -->
                <div id="filesContainer" class="files-container"></div>

                <!-- Removed bottom Proceed button; top button used instead -->
            </div>
        </div>
    </div>

    <!-- Radio Button Script -->
    <script>
    $(function() {
        function showUploadUnavailableAlert() {
            Swal.fire({
                icon: 'error',
                title: 'Opps',
                allowEnterKey: false,
                allowOutsideClick: false,
                allowEscapeKey: false,
                text: 'Upload is currently unavailable. I\'ll be right back.',
                confirmButtonText: 'OK'
            });
        }

        function setMode(mode) {
            if (mode === 'manual') {
                Swal.fire({
                    icon: 'error',
                    title: 'Not Available',
                    allowEnterKey: false,
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    text: 'Manual import is currently unavailable. Please use Auto (Drag & Drop).',
                    confirmButtonText: 'OK'
                });

                mode = 'auto';
                $('input[name="importMode"][value="auto"]').prop('checked', true);
            }

            $('.mode-card').removeClass('selected');
            $('.mode-card[data-mode="' + mode + '"]').addClass('selected');

            if (mode === 'manual') {
                $('#manualArea').show();
                $('#fileUploadArea').hide();
                $('#filesContainer').hide();
                $('#proceedContainer').hide();
            } else {
                $('#manualArea').hide();
                $('#fileUploadArea').show();
                $('#filesContainer').show();

                // Show top proceed button only if there are uploaded cards/files
                if ($('#filesContainer').children().length > 0) {
                    $('#proceedContainer').show();
                } else {
                    $('#proceedContainer').hide();
                }
            }
        }

        // Radio change handler
        $('input[name="importMode"]').on('change', function() {
            setMode($(this).val());
        });

        // Clickable card behavior
        $('.mode-card').on('click', function() {
            var mode = $(this).data('mode');
            $('input[name="importMode"][value="' + mode + '"]').prop('checked', true).trigger('change');
        });

        // Manual proceed button visibility
        function updateManualProceedVisibility() {
            var fi = $('#manualFileInput')[0];
            var hasNativeFile = !!(fi && fi.files && fi.files.length > 0);
            if (hasNativeFile) {
                $('#manualProceed').show();
            } else {
                $('#manualProceed').hide();
            }
        }

        $('#manualFileInput').on('change', updateManualProceedVisibility);

        // Block auto upload interactions while page is under construction
        $('#fileUploadArea').on('dragover', function(e) {
            e.preventDefault();
            e.stopPropagation();
        });

        $('#fileUploadArea').on('drop', function(e) {
            e.preventDefault();
            e.stopPropagation();
            showUploadUnavailableAlert();
        });

        $('#fileUploadArea').on('click', function(e) {
            e.preventDefault();
            showUploadUnavailableAlert();
        });

        $('#fileInput').on('change', function(e) {
            e.preventDefault();
            this.value = '';
            showUploadUnavailableAlert();
        });

        // Default mode
        setMode('auto');
        updateManualProceedVisibility();
    });
    </script>
</body>
<?php include '../../../templates/footer.php'; ?>
</html>