
<?php
// Connect to the database
require_once __DIR__ . '/../../../config/config.php';
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
            border: 3px dashed #dee2e6;
            border-radius: 10px;
            padding: 40px;
            text-align: center;
            background-color: #f8f9fa;
            transition: all 0.3s ease;
            cursor: pointer;
            margin-bottom: 20px;
        }

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
        
    </style>
</head>
<body>
    <div class="main-container">
        <div class="top-content">
            <div class="nav-container">
                <i id="menu-btn" class="fa-solid fa-bars"></i>
                <div class="usernav">
                    <h6><?php 
                            if($_SESSION['user_type'] === 'admin'){
                                echo $_SESSION['admin_name'];
                            }elseif($_SESSION['user_type'] === 'user'){
                                echo $_SESSION['user_name']; 
                            }else{
                                echo "GUEST";
                            }
                    ?></h6>
                    <h6 style="margin-left:5px;"><?php 
                        if($_SESSION['user_type'] === 'admin'){
                            echo "(".$_SESSION['admin_email'].")";
                        }elseif($_SESSION['user_type'] === 'user'){
                            echo "(".$_SESSION['user_email'].")";
                        }else{
                            echo "GUEST";
                        }
                    ?></h6>
                </div>
            </div>
        </div>
        <!-- Show and Hide Side Nav Menu -->
        <?php include '../../../templates/sidebar.php'; ?>
        <div id="loading-overlay">
            <div class="loading-spinner"></div>
        </div>
        <center><h1>Import Cancellation</h1></center>
        <div class="container-fluid border border-danger rounded mt-3 p-4">
            <div class="container-fluid">
                <!-- Mode Toggle (Auto / Manual) + Proceed (moved to top-right) -->
                <div class="mb-3 d-flex align-items-center justify-content-between" style="gap:12px;">
                    <div class="d-flex align-items-center" style="gap:12px;">
                        <label class="form-label me-2 mb-0">Import Mode:</label>
                        <div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="importMode" id="modeAuto" value="auto" checked>
                                <label class="form-check-label" for="modeAuto">Auto</label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="importMode" id="modeManual" value="manual">
                                <label class="form-check-label" for="modeManual">Manual</label>
                            </div>
                        </div>
                    </div>
                    <div id="proceedContainer" class="proceed-container" style="display: none;">
                        <button type="button" class="btn btn-danger btn-proceed" id="proceedBtn">
                            Proceed <i class="fa-solid fa-arrow-right ms-2"></i>
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
                    <form id="manualUploadForm" action="../../../models/saved/saved_billspayImportCancelledFile.php" method="post" enctype="multipart/form-data">
                        <div class="row mt-3 align-items-center">
                            <div class="col-md-5 mb-3">
                                <label for="partnerlistDropdown" class="form-label mb-0">Partners Name:</label>
                                <select id="partnerlistDropdown" class="form-select select2 form-select-sm" aria-label="Select Partner" name="partner" required 
                                    data-placeholder="Search or select a Partner..." style="width:100%; min-width:160px;">
                                    <option value="">Select Partner</option>
                                    <option value="All">All</option>
                                    <?php 
                                        if ($partnersResult && mysqli_num_rows($partnersResult) > 0) {
                                            while ($row = mysqli_fetch_assoc($partnersResult)) {
                                                $partner_names = htmlspecialchars($row['partner_name']);
                                                $selected = (isset($_GET['partner_name']) && $_GET['partner_name'] == $partner_names) ? 'selected' : '';
                                                echo "<option value='$partner_names' $selected>" . ucfirst($partner_names) . "</option>";
                                            }
                                        }
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label for="manualFileType" class="form-label mb-0">Source File Type:</label>
                                <select id="manualFileType" class="form-select" name="fileType" required>
                                    <option value="">Select Source File Type</option>
                                    <option value="KPX">KPX</option>
                                    <option value="KP7">KP7</option>
                                </select>
                            </div>

                            <div class="col-md-4 mb-3 d-flex align-items-center"><input type="file" name="import_file" accept=".csv" class="form-control me-2" required />
                                <input type="submit" class="btn btn-danger" name="upload" value="Proceed">
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</body><?php include '../../../templates/footer.php'; ?>
<!-- PARTNER DROPDOWN USING SELECT2 -->
<script>
    // Initialize Select2 for partner dropdown
    $('#partnerlistDropdown').select2({
        placeholder: 'Search or select a Partner...',
        allowClear: true,
        width: 'style',
        dropdownAutoWidth: true
    });
</script>

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

<!-- Drag and Drop File Upload under the Developer Area -->
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const fileUploadArea = document.getElementById('fileUploadArea');
        const fileInput = document.getElementById('fileInput');

        if (!fileUploadArea) return;

        // Visual drag states
        ['dragenter', 'dragover'].forEach(evt => {
            fileUploadArea.addEventListener(evt, function(e) {
                e.preventDefault();
                e.stopPropagation();
                fileUploadArea.classList.add('drag-over');
            });
        });

        ['dragleave', 'dragend'].forEach(evt => {
            fileUploadArea.addEventListener(evt, function(e) {
                e.preventDefault();
                e.stopPropagation();
                fileUploadArea.classList.remove('drag-over');
            });
        });

        // Handle dropped files
        fileUploadArea.addEventListener('drop', function(e) {
            e.preventDefault();
            e.stopPropagation();
            fileUploadArea.classList.remove('drag-over');

            const dt = e.dataTransfer;
            const files = dt ? dt.files : null;
            if (files && files.length > 0) {
                // Build a short HTML list of filenames
                let listHtml = `<p>${files.length} file(s) detected.</p><ul style="text-align:left; margin-left:1.1rem;">`;
                for (let i = 0; i < files.length; i++) {
                    listHtml += `<li>${files[i].name}</li>`;
                }
                listHtml += `</ul>`;

                // Show SweetAlert2 notice (Under Development)
                Swal.fire({
                    title: 'Under Development Area',
                    html: listHtml + '<p>This import/cancellation drag-and-drop feature is currently under development and is read-only.</p>',
                    icon: 'info',
                    confirmButtonText: 'OK'
                });
            }
        });

        // Allow clicking the area to open file picker
        fileUploadArea.addEventListener('click', function() {
            if (fileInput) fileInput.click();
        });

        // If files are selected via the input, show same alert
        if (fileInput) {
            fileInput.addEventListener('change', function(e) {
                const files = e.target.files;
                if (files && files.length > 0) {
                    let listHtml = `<p>${files.length} file(s) selected.</p><ul style="text-align:left; margin-left:1.1rem;">`;
                    for (let i = 0; i < files.length; i++) listHtml += `<li>${files[i].name}</li>`;
                    listHtml += `</ul>`;

                    Swal.fire({
                        title: 'Under Development Area',
                        html: listHtml + '<p>This import/cancellation file selection is currently under development and is read-only.</p>',
                        icon: 'info',
                        confirmButtonText: 'OK'
                    });

                    // Reset input so same file can be re-selected if needed
                    e.target.value = '';
                }
            });
        }
    });
</script>
</html>