<?php
// billspay-transaction_monthly.php
// Connect to the database
include '../../../config/config.php';
require '../../../vendor/autoload.php';

// Start the session
session_start();
@include_once __DIR__ . '/../../../templates/middleware.php';
$id = resolve_user_identifier();
if (empty($id)) { header('Location: ../../../login_form.php'); exit; }
if (!function_exists('has_any_permission') || !has_any_permission(['Import Transaction','Bills Payment'])) { header('Location: ../../home.php'); exit; }

// prefer explicit session values for current user email
$current_user_email = $_SESSION['admin_email'] ?? $_SESSION['user_email'] ?? '';
// Extract imported_by from session
$imported_by = $_SESSION['admin_name'] ?? $_SESSION['user_name'] ?? 'System';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Monthly Transaction | <?php if($_SESSION['user_type'] === 'admin' || $_SESSION['user_type'] === 'user') echo ucfirst($_SESSION['user_type']); else echo "Guest";?></title>
    <link rel="stylesheet" href="../../../assets/css/templates/style.css?v=<?php echo time(); ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://kit.fontawesome.com/30b908cc5a.js" crossorigin="anonymous"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script src="../../../assets/js/sweetalert2.all.min.js"></script>
    <link rel="icon" href="../../../images/MLW logo.png" type="image/png">
    <link rel="stylesheet" href="css/billspay_transaction.css?v=<?= time(); ?>">

    <style>
        .import-container {
            padding: 1.5rem;
            max-width: 100%;
        }
        .upload-card {
            background: #fff;
            border-radius: 0.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .upload-zone {
            border: 2px dashed #ced4da;
            border-radius: 0.5rem;
            padding: 2rem;
            text-align: center;
            background: #f8f9fa;
            transition: border-color 0.2s, background 0.2s;
            cursor: pointer;
        }
        .upload-zone:hover,
        .upload-zone.dragover {
            border-color: #0d6efd;
            background: #e7f1ff;
        }
        .upload-zone i {
            font-size: 2.5rem;
            color: #6c757d;
            margin-bottom: 0.75rem;
        }
        .table-display-wrapper {
            overflow-x: auto;
            max-height: 70vh;
            border: 1px solid #dee2e6;
            border-radius: 0.375rem;
            background: #fff;
        }
        #table-display {
            margin-bottom: 0;
            font-size: 0.85rem;
            white-space: nowrap;
        }
        #table-display thead th {
            position: sticky;
            top: 0;
            background: #212529;
            color: #fff;
            z-index: 2;
            font-weight: 600;
            padding: 0.5rem 0.75rem;
        }
        #table-display tbody td {
            padding: 0.4rem 0.75rem;
            vertical-align: middle;
        }
        #table-display tbody tr:nth-child(even) {
            background-color: #f8f9fa;
        }
        .file-info {
            display: none;
            margin-top: 1rem;
        }
        .preview-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin-bottom: 0.75rem;
        }
        .preview-header h5 {
            margin: 0;
        }
        #row-count {
            font-size: 0.9rem;
            color: #6c757d;
        }
        .btn-clear {
            display: none;
        }
    </style>
</head>
<body>
    <div class="main-container">
        <?php include '../../../templates/header_ui.php'; ?>
        <?php include '../../../templates/sidebar.php'; ?>
        <div class="container-fluid import-container">

            <div class="upload-card">
                <h4 class="mb-3"><i class="fas fa-file-excel me-2 text-success"></i>Upload Monthly Transaction Excel</h4>
                <p class="text-muted small mb-3">Upload an Excel file (.xlsx / .xls). Columns <strong>A–V</strong> will be read and displayed below (preview only — no import yet).</p>

                <div class="upload-zone" id="upload-zone">
                    <i class="fas fa-cloud-upload-alt"></i>
                    <p class="mb-1 fw-semibold">Drag &amp; drop your Excel file here</p>
                    <p class="text-muted small mb-3">or click to browse</p>
                    <input type="file" id="excel-file" accept=".xlsx,.xls,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel" hidden>
                    <button type="button" class="btn btn-primary btn-sm" id="browse-btn">
                        <i class="fas fa-folder-open me-1"></i> Browse File
                    </button>
                </div>

                <div class="file-info" id="file-info">
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <span class="badge bg-success"><i class="fas fa-check me-1"></i>File loaded</span>
                        <span id="file-name" class="fw-semibold"></span>
                        <span id="file-size" class="text-muted small"></span>
                        <button type="button" class="btn btn-outline-secondary btn-sm btn-clear" id="clear-btn">
                            <i class="fas fa-times me-1"></i> Clear
                        </button>
                    </div>
                </div>
            </div>

            <div id="preview-section" style="display: none;">
                <div class="preview-header">
                    <h5><i class="fas fa-table me-2"></i>Preview (Columns A–V)</h5>
                    <span id="row-count"></span>
                </div>
                <div class="table-display-wrapper">
                    <table class="table table-bordered table-hover table-sm" id="table-display">
                        <thead></thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>

    <script>
    (function () {
        const COL_COUNT = 22; // A–V (0-indexed: 0..21)
        const COL_LETTERS = Array.from({ length: COL_COUNT }, (_, i) =>
            String.fromCharCode(65 + i)
        );

        const uploadZone   = document.getElementById('upload-zone');
        const fileInput    = document.getElementById('excel-file');
        const browseBtn    = document.getElementById('browse-btn');
        const fileInfo     = document.getElementById('file-info');
        const fileNameEl   = document.getElementById('file-name');
        const fileSizeEl   = document.getElementById('file-size');
        const clearBtn     = document.getElementById('clear-btn');
        const previewSec   = document.getElementById('preview-section');
        const tableDisplay = document.getElementById('table-display');
        const thead        = tableDisplay.querySelector('thead');
        const tbody        = tableDisplay.querySelector('tbody');
        const rowCountEl   = document.getElementById('row-count');

        // Open file dialog
        browseBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            fileInput.click();
        });
        uploadZone.addEventListener('click', () => fileInput.click());

        // Drag & drop
        uploadZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            uploadZone.classList.add('dragover');
        });
        uploadZone.addEventListener('dragleave', () => {
            uploadZone.classList.remove('dragover');
        });
        uploadZone.addEventListener('drop', (e) => {
            e.preventDefault();
            uploadZone.classList.remove('dragover');
            const files = e.dataTransfer.files;
            if (files.length) handleFile(files[0]);
        });

        fileInput.addEventListener('change', () => {
            if (fileInput.files.length) handleFile(fileInput.files[0]);
        });

        clearBtn.addEventListener('click', resetUI);

        function resetUI() {
            fileInput.value = '';
            fileInfo.style.display = 'none';
            clearBtn.style.display = 'none';
            previewSec.style.display = 'none';
            thead.innerHTML = '';
            tbody.innerHTML = '';
            rowCountEl.textContent = '';
        }

        function formatBytes(bytes) {
            if (bytes < 1024) return bytes + ' B';
            if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
            return (bytes / 1048576).toFixed(2) + ' MB';
        }

        function handleFile(file) {
            const validTypes = [
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.ms-excel',
                'application/octet-stream'
            ];
            const validExt = /\.(xlsx|xls)$/i.test(file.name);

            if (!validExt && !validTypes.includes(file.type)) {
                Swal.fire({
                    icon: 'error',
                    title: 'Invalid file',
                    text: 'Please upload a valid Excel file (.xlsx or .xls).'
                });
                return;
            }

            fileNameEl.textContent = file.name;
            fileSizeEl.textContent = '(' + formatBytes(file.size) + ')';
            fileInfo.style.display = 'block';
            clearBtn.style.display = 'inline-block';

            const reader = new FileReader();
            reader.onload = function (e) {
                try {
                    const data = new Uint8Array(e.target.result);
                    const workbook = XLSX.read(data, { type: 'array', cellDates: true });

                    // Use first sheet
                    const sheetName = workbook.SheetNames[0];
                    const worksheet = workbook.Sheets[sheetName];

                    // Convert to array of arrays (header: 1 → raw rows)
                    const rawRows = XLSX.utils.sheet_to_json(worksheet, {
                        header: 1,
                        defval: '',
                        raw: false
                    });

                    if (!rawRows.length) {
                        Swal.fire({
                            icon: 'warning',
                            title: 'Empty sheet',
                            text: 'The selected sheet contains no data.'
                        });
                        return;
                    }

                    renderTable(rawRows);
                } catch (err) {
                    console.error(err);
                    Swal.fire({
                        icon: 'error',
                        title: 'Parse error',
                        text: 'Could not read the Excel file. Please check the file format.'
                    });
                }
            };
            reader.onerror = function () {
                Swal.fire({
                    icon: 'error',
                    title: 'Read error',
                    text: 'Failed to read the file.'
                });
            };
            reader.readAsArrayBuffer(file);
        }

        function renderTable(rows) {
            thead.innerHTML = '';
            tbody.innerHTML = '';

            // Header row: Column letters A–V
            const headerTr = document.createElement('tr');
            COL_LETTERS.forEach((letter) => {
                const th = document.createElement('th');
                th.textContent = letter;
                headerTr.appendChild(th);
            });
            thead.appendChild(headerTr);

            // Data rows – only columns 0..21 (A–V)
            let dataRowCount = 0;
            rows.forEach((row) => {
                // Skip completely empty rows
                const hasContent = row.slice(0, COL_COUNT).some(
                    (cell) => cell !== null && cell !== undefined && String(cell).trim() !== ''
                );
                if (!hasContent) return;

                dataRowCount++;
                const tr = document.createElement('tr');
                for (let c = 0; c < COL_COUNT; c++) {
                    const td = document.createElement('td');
                    let val = row[c];
                    if (val === null || val === undefined) val = '';
                    // Keep date objects readable
                    if (val instanceof Date) {
                        val = val.toLocaleDateString();
                    }
                    td.textContent = String(val);
                    tr.appendChild(td);
                }
                tbody.appendChild(tr);
            });

            rowCountEl.textContent = dataRowCount + ' row' + (dataRowCount !== 1 ? 's' : '') + ' displayed (columns A–V)';
            previewSec.style.display = 'block';

            // Scroll preview into view
            previewSec.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    })();
    </script>

<?php include '../../../templates/footer.php'; ?>
</html>