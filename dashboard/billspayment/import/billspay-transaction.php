<?php
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
    <title>Import Transaction | <?php if($_SESSION['user_type'] === 'admin' || $_SESSION['user_type'] === 'user') echo ucfirst($_SESSION['user_type']); else echo "Guest";?></title>
    <link rel="stylesheet" href="../../../assets/css/templates/style.css?v=<?php echo time(); ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://kit.fontawesome.com/30b908cc5a.js" crossorigin="anonymous"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script src="../../../assets/js/sweetalert2.all.min.js"></script>
    <link rel="icon" href="../../../images/MLW logo.png" type="image/png">
    <link rel="stylesheet" href="css/billspay_transaction.css?v=<?= time(); ?>">

</head>
<body>
    <div class="main-container">
        <?php include '../../../templates/header_ui.php'; ?>
        <?php include '../../../templates/sidebar.php'; ?>

        <div class="container-fluid import-container">
            <div class="card">
                <div class="card-header bg-danger text-white">
                    <h5 class="card-title mb-0"><i class="fas fa-file-excel"></i> Import Bills Payment Transactions</h5>
                </div>
                <div class="card-body">
                    <!-- Drag and Drop Zone -->
                    <div class="row mb-3">
                        <div class="col-12">
                            <div class="drop-zone" id="dropZone">
                                <i class="fas fa-cloud-upload-alt drop-zone-icon"></i>
                                <div class="drop-zone-text">
                                    <strong>Drag & Drop</strong> your excel files here
                                </div>
                                <div class="drop-zone-subtext">
                                    or <span class="browse-link" id="browseLink">browse files</span> to upload
                                </div>
                                <div class="supported-formats">
                                    <span class="badge bg-success"><i class="fas fa-file-excel"></i> .xlsx</span>
                                    <span class="badge bg-success"><i class="fas fa-file-excel"></i> .xls</span>
                                </div>
                                <div class="file-count-indicator" id="fileCountIndicator">
                                    <span class="badge bg-secondary" id="dropFileCount">0 files</span>
                                </div>
                                <div class="upload-progress" id="uploadProgress">
                                    <div class="progress">
                                        <div class="progress-bar progress-bar-striped progress-bar-animated bg-danger" 
                                             role="progressbar" style="width: 0%" id="uploadProgressBar"></div>
                                    </div>
                                    <small class="text-muted" id="uploadProgressText">Uploading...</small>
                                </div>
                                <input type="file" id="excel_file" class="file-input-hidden" accept=".xlsx, .xls" multiple>
                            </div>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="row align-items-center">
                        <div class="col-md-12">
                            <button type="button" id="btn_process" class="btn btn-danger" disabled>
                                <i class="fas fa-eye"></i> Preview Data
                            </button>
                            <button type="button" id="btn_clear_files" class="btn btn-secondary ms-2" style="display:none;">
                                <i class="fas fa-trash"></i> Clear All
                            </button>
                            <span id="file_count_badge" class="badge bg-info file-count-badge" style="display:none;">0 files</span>
                        </div>
                    </div>

                    <!-- File List Display -->
                    <div id="file_list_container" class="file-list-container" style="display:none;">
                        <div id="file_list" style="border-left: 8px solid #136c25; border-radius: 8px;"></div>
                    </div>

                    <!-- Validation Summary -->
                    <div id="validation_summary" class="validation-summary">
                        <div class="row">
                            <div class="col-md-3">
                                <span class="badge bg-info">
                                    <i class="fas fa-list"></i> Total: <span id="totalRecords">0</span>
                                </span>
                            </div>
                            <div class="col-md-3">
                                <span class="badge bg-warning text-dark">
                                    <i class="fas fa-store"></i> Empty / Not Found Branch ID: <span id="emptyBranchCount">0</span>
                                </span>
                            </div>
                            <div class="col-md-3">
                                <span class="badge bg-danger">
                                    <i class="fa-solid fa-triangle-exclamation"></i> Empty Partner ID: <span id="emptyPartnerCount">0</span>
                                </span>
                            </div>
                            <div class="col-md-3">
                                <span class="badge bg-primary">
                                    <i class="fas fa-exclamation-circle"></i> Unrecognized Partner: <span id="unrecognizedPartnerCount">0</span>
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="table-responsive d-none" id="preview_section">
                        <table class="table table-bordered table-striped table-hover table-sm visual-table" id="transaction_table">
                            <thead class="table-dark">
                                <tr>
                                    <th>#</th>
                                    <th>Status</th>
                                    <th>Billing Invoice</th>
                                    <th>Report Date</th>
                                    <th>Settlement Date</th>
                                    <th>Datetime</th>
                                    <th>Cancellation Date</th>
                                    <th>Source File</th>
                                    <th>Run Date</th>
                                    <th>Control No</th>
                                    <th>Reference No</th>
                                    <th>Payor</th>
                                    <th>Address</th>
                                    <th>Account No</th>
                                    <th>Account Name</th>
                                    <th>Amount Paid</th>
                                    <th>Charge to Customer</th>
                                    <th>Charge to Partner</th>
                                    <th>Contact No</th>
                                    <th>Other Details</th>
                                    <th>Branch ID</th>
                                    <th>Branch Code</th>
                                    <th>Outlet</th>
                                    <th>Zone Code</th>
                                    <th>Region Code</th>
                                    <th>Region Code TG</th>
                                    <th>Region</th>
                                    <th>Region TG</th>
                                    <th>Operator</th>
                                    <th>Remote Branch</th>
                                    <th>Remote Operator</th>
                                    <th>2nd Approver</th>
                                    <th>Sub Billers ID</th>
                                    <th>Sub Billers Name</th>
                                    <th>Partner Name</th>
                                    <th>Partner ID</th>
                                    <th>Partner ID KPX</th>
                                    <th>MPM GL Code</th>
                                    <th>Settle/Unsettle</th>
                                    <th>Claim/Unclaim</th>
                                    <th>Imported By</th>
                                    <th>Imported Date</th>
                                    <th>RFP No</th>
                                    <th>CAD No</th>
                                    <th>Hold Status</th>
                                    <th>Post Status</th>
                                </tr>
                            </thead>
                            <tbody>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination Controls -->
                    <div id="pagination_container" class="pagination-container d-none">
                        <button id="prev_page" class="btn btn-sm btn-secondary">
                            <i class="fas fa-chevron-left"></i> Previous
                        </button>
                        <div id="page_buttons" class="pagination-controls"></div>
                        <button id="next_page" class="btn btn-sm btn-secondary">
                            Next <i class="fas fa-chevron-right"></i>
                        </button>
                        <span id="page_info" class="page-info"></span>
                    </div>

                    <!-- Summary Button -->
                    <div id="summary_section" style="display: none;">
                        <div class="row mt-3">
                            <div class="col-12">
                                <button type="button" id="btn_summary" class="btn btn-danger btn-lg w-100">
                                    <i class="fas fa-chart-bar"></i> View Transaction Summary
                                </button>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <!-- Warning Modal -->
    <div class="modal fade" id="warningModal" tabindex="-1" aria-labelledby="warningModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title" id="warningModalLabel">
                        <i class="fas fa-exclamation-triangle"></i> Remarks
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="warningContent" class="modal-warning-list">
                        <!-- Warnings will be dynamically inserted here -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times"></i> Close
                    </button>
                    <button type="button" class="btn btn-warning" id="proceedImportBtn">
                        <i class="fas fa-upload"></i> Proceed with Import
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Import Disabled Modal (Critical Errors) -->
    <div class="modal fade" id="importDisabledModal" tabindex="-1" aria-labelledby="importDisabledModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="importDisabledModalLabel">
                        <i class="fas fa-ban"></i> Import Disabled
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="importDisabledContent">
                        <!-- Content will be dynamically inserted here -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times"></i> Close
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Summary Modal -->
    <div class="modal fade" id="summaryModal" tabindex="-1" aria-labelledby="summaryModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="summaryModalLabel">
                        <i class="fas fa-chart-bar"></i> Transaction Summary
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="summaryQuickStats" class="mb-3"></div>
                    <div class="summary-modal-grid" id="summaryGrid">
                        <!-- Summary cards will be inserted here -->
                    </div>
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped table-hover summary-table" id="summaryTable">
                            <thead class="table-dark">
                                <tr>
                                    <th style="width: 25%;">Metric</th>
                                    <th class="text-center" style="width: 25%;">SUMMARY</th>
                                    <th class="text-center" style="width: 25%;">ADJUSTMENTS</th>
                                    <th class="text-center" style="width: 25%;">NET</th>
                                </tr>
                            </thead>
                            <tbody id="summaryBody">
                                <!-- Summary data will be inserted here -->
                            </tbody>
                        </table>
                    </div>
                    <div class="alert alert-danger mt-3">
                        <i class="fas fa-info-circle"></i> 
                        <strong>Note:</strong> Positive amounts represent credits/inflows, negative amounts represent debits/outflows.
                        <br>
                        <span class="badge bg-success">SUMMARY</span> = Transactions with positive Amount Paid
                        <span class="badge bg-danger text-white ms-2">ADJUSTMENTS</span> = Transactions with negative Amount Paid
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times"></i> Close
                    </button>
                    <button type="button" class="btn btn-success" id="btn_export_summary">
                        <i class="fas fa-file-excel"></i> Export Summary
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
    $(document).ready(function() {
        let fileDataMap = {}; // Store file data: { fileName: { binaryData, file } }
        let allRows = [];
        let currentPage = 1;
        const rowsPerPage = 50;
        let totalPages = 0;
        let warningData = {
            emptyBranchRows: [],
            emptyPartnerRows: [],
            unrecognizedPartnerRows: []
        };
        let importDisabled = false;

        const current_user = "<?php echo $imported_by; ?>";
        const imported_date = "<?php echo date('Y-m-d'); ?>";

        function isEmptyValue(value) {
            if (value === null || value === undefined) return true;
            if (typeof value === 'string') {
                const trimmed = value.trim();
                if (trimmed === '') return true;
                if (trimmed === 'Not Found') return true;
                if (trimmed === 'NULL') return true;
                if (trimmed === 'null') return true;
                if (trimmed === 'undefined') return true;
                if (trimmed === 'N/A') return true;
                if (trimmed === '--') return true;
                if (trimmed === '0') return true;
                if (trimmed === '0.00') return true;
                return false;
            }
            if (typeof value === 'number') {
                return value === 0;
            }
            return false;
        }

        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        function convertToYMD(dateString) {
            if (!dateString || dateString === null || dateString === undefined || dateString === '') {
                return '';
            }

            if (/^\d{4}-\d{2}-\d{2}$/.test(dateString)) {
                return dateString;
            }

            try {
                let date = new Date(dateString);
                if (!isNaN(date.getTime())) {
                    let year = date.getFullYear();
                    let month = String(date.getMonth() + 1).padStart(2, '0');
                    let day = String(date.getDate()).padStart(2, '0');
                    return `${year}-${month}-${day}`;
                }
            } catch (e) {}

            const monthMap = {
                'JANUARY': '01', 'FEBRUARY': '02', 'MARCH': '03', 'APRIL': '04',
                'MAY': '05', 'JUNE': '06', 'JULY': '07', 'AUGUST': '08',
                'SEPTEMBER': '09', 'OCTOBER': '10', 'NOVEMBER': '11', 'DECEMBER': '12',
                'JAN': '01', 'FEB': '02', 'MAR': '03', 'APR': '04',
                'MAY': '05', 'JUN': '06', 'JUL': '07', 'AUG': '08',
                'SEP': '09', 'OCT': '10', 'NOV': '11', 'DEC': '12'
            };

            let match = dateString.match(/^([A-Za-z]+)\s+(\d{1,2}),?\s+(\d{4})$/i);
            if (match) {
                let monthName = match[1].toUpperCase();
                let day = String(parseInt(match[2])).padStart(2, '0');
                let year = match[3];
                if (monthMap[monthName]) {
                    return `${year}-${monthMap[monthName]}-${day}`;
                }
            }

            match = dateString.match(/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/);
            if (match) {
                let month = String(parseInt(match[1])).padStart(2, '0');
                let day = String(parseInt(match[2])).padStart(2, '0');
                let year = match[3];
                return `${year}-${month}-${day}`;
            }

            return dateString;
        }

        function normalizeDateTime(value) {
            if (!value || value === null || value === undefined || value === '') {
                return null;
            }
            
            // If it's already in YYYY-MM-DD HH:MM:SS format, return as is
            if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(value)) {
                return value;
            }
            
            try {
                let date = new Date(value);
                if (!isNaN(date.getTime())) {
                    let year = date.getFullYear();
                    let month = String(date.getMonth() + 1).padStart(2, '0');
                    let day = String(date.getDate()).padStart(2, '0');
                    let hours = String(date.getHours()).padStart(2, '0');
                    let minutes = String(date.getMinutes()).padStart(2, '0');
                    let seconds = String(date.getSeconds()).padStart(2, '0');
                    return `${year}-${month}-${day} ${hours}:${minutes}:${seconds}`;
                }
            } catch (e) {}
            
            return String(value);
        }

        function formatValue(value, highlightNotFound = true) {
            if (value === null || value === undefined || value === '') {
                return '';
            }
            if (highlightNotFound && value === 'Not Found') {
                return `<span class="text-not-found">${value}</span>`;
            }
            return value;
        }

        // Helper function to safely parse a number from various formats
        function parseAmount(value) {
            if (value === null || value === undefined || value === '') return 0;
            
            // If it's already a number, return it
            if (typeof value === 'number') return value;
            
            // If it's a string, clean it and parse
            if (typeof value === 'string') {
                // Remove currency symbols, commas, and extra spaces
                let cleaned = value.replace(/[₱ $,]/g, '').trim();
                // Handle negative numbers with parentheses or minus sign
                if (cleaned.startsWith('(') && cleaned.endsWith(')')) {
                    cleaned = '-' + cleaned.slice(1, -1);
                }
                // Parse the number
                const parsed = parseFloat(cleaned);
                return isNaN(parsed) ? 0 : parsed;
            }
            
            return 0;
        }

        // Function to calculate summary statistics
        function calculateSummary(rows) {
            // Separate positive and negative rows based on amount_paid
            let positiveRows = rows.filter(row => parseAmount(row.amount_paid) > 0);
            let negativeRows = rows.filter(row => parseAmount(row.amount_paid) < 0);
            
            // Summary (Positive Rows)
            const summaryCount = positiveRows.length;
            const summaryPrincipal = positiveRows.reduce((sum, row) => sum + parseAmount(row.amount_paid), 0);
            const summaryCTC = positiveRows.reduce((sum, row) => sum + parseAmount(row.charge_to_customer), 0);
            const summaryCTP = positiveRows.reduce((sum, row) => sum + parseAmount(row.charge_to_partner), 0);
            const summaryCharge = summaryCTC + summaryCTP;
            
            // Adjustments (Negative Rows) - convert to positive for display
            const adjCount = negativeRows.length;
            const adjPrincipal = Math.abs(negativeRows.reduce((sum, row) => sum + parseAmount(row.amount_paid), 0));
            const adjCTC = Math.abs(negativeRows.reduce((sum, row) => sum + parseAmount(row.charge_to_customer), 0));
            const adjCTP = Math.abs(negativeRows.reduce((sum, row) => sum + parseAmount(row.charge_to_partner), 0));
            const adjCharge = adjCTC + adjCTP;
            
            // Net Calculations
            const netCount = summaryCount - adjCount;
            const netPrincipal = summaryPrincipal - adjPrincipal;
            const netCTC = summaryCTC - adjCTC;
            const netCTP = summaryCTP - adjCTP;
            const netCharge = summaryCharge - adjCharge;
            const settlementAmount = netPrincipal - netCharge;
            
            return {
                summary: {
                    count: summaryCount,
                    principal: summaryPrincipal,
                    charge: summaryCharge,
                    ctc: summaryCTC,
                    ctp: summaryCTP
                },
                adjustments: {
                    count: adjCount,
                    principal: adjPrincipal,
                    charge: adjCharge,
                    ctc: adjCTC,
                    ctp: adjCTP
                },
                net: {
                    count: netCount,
                    principal: netPrincipal,
                    charge: netCharge,
                    ctc: netCTC,
                    ctp: netCTP,
                    settlement: settlementAmount
                }
            };
        }

        // Function to format currency
        function formatCurrency(amount) {
            // Ensure amount is a number
            const numAmount = parseFloat(amount) || 0;
            return '₱ ' + numAmount.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        }

        // Function to format number with commas
        function formatNumber(num) {
            // Ensure num is a number
            const numAmount = parseInt(num) || 0;
            return numAmount.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        }

        // Function to display summary
        function displaySummary(rows) {
            const stats = calculateSummary(rows);
            
            // Quick stats
            const quickStatsHtml = `
                <div class="row">
                    <div class="col-md-3">
                        <div class="card bg-light">
                            <div class="card-body text-center">
                                <h6 class="card-title">Total Records</h6>
                                <h4>${formatNumber(rows.length)}</h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-success text-white">
                            <div class="card-body text-center">
                                <h6 class="card-title">Summary (Positive)</h6>
                                <h4>${formatNumber(stats.summary.count)}</h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-danger text-light">
                            <div class="card-body text-center">
                                <h6 class="card-title">Adjustments (Negative)</h6>
                                <h4>${formatNumber(stats.adjustments.count)}</h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-info text-white">
                            <div class="card-body text-center">
                                <h6 class="card-title">Net Records</h6>
                                <h4>${formatNumber(stats.net.count)}</h4>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            $('#summaryQuickStats').html(quickStatsHtml);
            
            // Summary Grid (Card View)
            const gridHtml = `
                <div class="summary-card summary-positive">
                    <h6 class="text-success">SUMMARY (Positive Amounts)</h6>
                    <div class="summary-item">
                        <span class="label">TOTAL COUNT:</span>
                        <span class="value">${formatNumber(stats.summary.count)}</span>
                    </div>
                    <div class="summary-item">
                        <span class="label">TOTAL PRINCIPAL (PHP):</span>
                        <span class="value text-success">${formatCurrency(stats.summary.principal)}</span>
                    </div>
                    <div class="summary-item">
                        <span class="label">TOTAL CHARGE (PHP):</span>
                        <span class="value text-success">${formatCurrency(stats.summary.charge)}</span>
                    </div>
                    <div class="summary-item">
                        <span class="label">TOTAL CTC (PHP):</span>
                        <span class="value text-success">${formatCurrency(stats.summary.ctc)}</span>
                    </div>
                    <div class="summary-item">
                        <span class="label">TOTAL CTP (PHP):</span>
                        <span class="value text-success">${formatCurrency(stats.summary.ctp)}</span>
                    </div>
                </div>
                <div class="summary-card summary-adjustment">
                    <h6 class="text-danger">ADJUSTMENTS (Negative Amounts)</h6>
                    <div class="summary-item">
                        <span class="label">TOTAL COUNT:</span>
                        <span class="value">${formatNumber(stats.adjustments.count)}</span>
                    </div>
                    <div class="summary-item">
                        <span class="label">TOTAL PRINCIPAL (PHP):</span>
                        <span class="value text-danger">${formatCurrency(stats.adjustments.principal)}</span>
                    </div>
                    <div class="summary-item">
                        <span class="label">TOTAL CHARGE (PHP):</span>
                        <span class="value text-danger">${formatCurrency(stats.adjustments.charge)}</span>
                    </div>
                    <div class="summary-item">
                        <span class="label">TOTAL CTC (PHP):</span>
                        <span class="value text-danger">${formatCurrency(stats.adjustments.ctc)}</span>
                    </div>
                    <div class="summary-item">
                        <span class="label">TOTAL CTP (PHP):</span>
                        <span class="value text-danger">${formatCurrency(stats.adjustments.ctp)}</span>
                    </div>
                </div>
                <div class="summary-card summary-net">
                    <h6 class="text-primary">NET</h6>
                    <div class="summary-item">
                        <span class="label">TOTAL COUNT:</span>
                        <span class="value">${formatNumber(stats.net.count)}</span>
                    </div>
                    <div class="summary-item">
                        <span class="label">TOTAL PRINCIPAL (PHP):</span>
                        <span class="value text-primary">${formatCurrency(stats.net.principal)}</span>
                    </div>
                    <div class="summary-item">
                        <span class="label">TOTAL CHARGE (PHP):</span>
                        <span class="value text-primary">${formatCurrency(stats.net.charge)}</span>
                    </div>
                    <div class="summary-item">
                        <span class="label">TOTAL CTC (PHP):</span>
                        <span class="value text-primary">${formatCurrency(stats.net.ctc)}</span>
                    </div>
                    <div class="summary-item">
                        <span class="label">TOTAL CTP (PHP):</span>
                        <span class="value text-primary">${formatCurrency(stats.net.ctp)}</span>
                    </div>
                    <div class="summary-item" style="border-top: 2px solid #007bff; padding-top: 8px; margin-top: 8px;">
                        <span class="label"><strong>SETTLEMENT AMOUNT (PHP):</strong></span>
                        <span class="value text-success" style="font-size: 1.1em;">${formatCurrency(stats.net.settlement)}</span>
                    </div>
                </div>
            `;
            $('#summaryGrid').html(gridHtml);
            
            // Main summary table
            const summaryData = [
                {
                    metric: 'TOTAL COUNT',
                    summary: formatNumber(stats.summary.count),
                    adjustments: formatNumber(stats.adjustments.count),
                    net: formatNumber(stats.net.count)
                },
                {
                    metric: 'TOTAL PRINCIPAL (PHP)',
                    summary: formatCurrency(stats.summary.principal),
                    adjustments: formatCurrency(stats.adjustments.principal),
                    net: formatCurrency(stats.net.principal)
                },
                {
                    metric: 'TOTAL CHARGE (PHP)',
                    summary: formatCurrency(stats.summary.charge),
                    adjustments: formatCurrency(stats.adjustments.charge),
                    net: formatCurrency(stats.net.charge)
                },
                {
                    metric: 'TOTAL CTC (PHP)',
                    summary: formatCurrency(stats.summary.ctc),
                    adjustments: formatCurrency(stats.adjustments.ctc),
                    net: formatCurrency(stats.net.ctc)
                },
                {
                    metric: 'TOTAL CTP (PHP)',
                    summary: formatCurrency(stats.summary.ctp),
                    adjustments: formatCurrency(stats.adjustments.ctp),
                    net: formatCurrency(stats.net.ctp)
                }
            ];
            
            let html = '';
            summaryData.forEach((item, index) => {
                let rowClass = '';
                if (index === summaryData.length - 1) rowClass = 'total-row';
                html += `
                    <tr class="${rowClass}">
                        <td><strong>${item.metric}</strong></td>
                        <td class="text-end ${index > 0 ? 'text-positive' : ''}">${item.summary}</td>
                        <td class="text-end ${index > 0 ? 'text-negative' : ''}">${item.adjustments}</td>
                        <td class="text-end ${index > 0 ? 'text-net' : ''}">${item.net}</td>
                    </tr>
                `;
            });
            
            // Add settlement amount row
            html += `
                <tr class="settlement-row" style="border-top: 3px solid #c50000;">
                    <td><strong>SETTLEMENT AMOUNT (PHP)</strong></td>
                    <td class="text-end">-</td>
                    <td class="text-end">-</td>
                    <td class="text-end text-success" style="font-size: 1.2em;">
                        <strong>${formatCurrency(stats.net.settlement)}</strong>
                    </td>
                </tr>
            `;
            
            $('#summaryBody').html(html);
            
            // Show summary button
            $('#summary_section').show();
        }

        // Update file list display
        function updateFileList() {
            const fileListContainer = $('#file_list_container');
            const fileList = $('#file_list');
            const fileCountBadge = $('#file_count_badge');
            const btnClearFiles = $('#btn_clear_files');
            const dropFileCount = $('#dropFileCount');
            
            const fileNames = Object.keys(fileDataMap);
            
            // Update drop zone file count
            if (fileNames.length > 0) {
                dropFileCount.text(`${fileNames.length} file${fileNames.length > 1 ? 's' : ''}`)
                    .removeClass('bg-secondary')
                    .addClass('bg-success');
            } else {
                dropFileCount.text('0 files')
                    .removeClass('bg-success')
                    .addClass('bg-secondary');
            }
            
            if (fileNames.length === 0) {
                fileListContainer.hide();
                fileCountBadge.hide();
                btnClearFiles.hide();
                $('#btn_process').prop('disabled', true);
                return;
            }
            
            fileListContainer.show();
            fileCountBadge.show().text(fileNames.length + ' file' + (fileNames.length > 1 ? 's' : ''));
            btnClearFiles.show();
            
            let html = '';
            fileNames.forEach((fileName, index) => {
                const fileData = fileDataMap[fileName];
                const fileSize = formatFileSize(fileData.file.size);
                const status = fileData.processed ? '<i class="fa-solid fa-check-double" style="color: green;"></i> Processed' : '<i class="fa-solid fa-file-excel" style="color: green;"></i> Ready';
                html += `
                    <div class="file-item">
                        <span class="file-name">${index + 1}. ${fileName}</span>
                        <span class="file-size">${fileSize}</span>
                        <span class="file-status">${status}</span>
                        <span class="remove-file" data-filename="${fileName}" title="Remove file">
                            <i class="fas fa-times-circle"></i>
                        </span>
                    </div>
                `;
            });
            fileList.html(html);
            
            // Handle remove file click
            $('.remove-file').on('click', function() {
                const fileName = $(this).data('filename');
                removeFile(fileName);
            });
            
            // Enable process button if there are files
            $('#btn_process').prop('disabled', false);
        }

        // Remove a file from the list
        function removeFile(fileName) {
            delete fileDataMap[fileName];
            updateFileList();
            
            // Clear binary data if no files left
            if (Object.keys(fileDataMap).length === 0) {
                $('#preview_section').addClass('d-none');
                $('#pagination_container').addClass('d-none');
                $('#validation_summary').hide();
                $('#summary_section').hide();
                allRows = [];
            }
        }

        // Clear all files
        function clearAllFiles() {
            fileDataMap = {};
            updateFileList();
            $('#excel_file').val('');
            $('#preview_section').addClass('d-none');
            $('#pagination_container').addClass('d-none');
            $('#validation_summary').hide();
            $('#summary_section').hide();
            allRows = [];
            $('#dropFileCount').text('0 files').removeClass('bg-success').addClass('bg-secondary');
        }

        // Handle file selection (used by both drag-drop and browse)
        function handleFiles(files) {
            if (!files || files.length === 0) return;

            let addedCount = 0;
            let duplicateCount = 0;
            let totalFiles = files.length;
            let processedFiles = 0;

            // Show progress
            $('#uploadProgress').show();
            $('#uploadProgressBar').css('width', '0%');
            $('#uploadProgressText').text(`Processing ${totalFiles} file(s)...`);

            for (let i = 0; i < files.length; i++) {
                const file = files[i];
                const fileName = file.name;
                
                // Check if file is Excel
                const ext = fileName.split('.').pop().toLowerCase();
                if (!['xlsx', 'xls'].includes(ext)) {
                    duplicateCount++;
                    processedFiles++;
                    updateProgress(processedFiles, totalFiles);
                    continue;
                }

                // Check if file already exists in the map
                if (fileDataMap[fileName]) {
                    duplicateCount++;
                    processedFiles++;
                    updateProgress(processedFiles, totalFiles);
                    continue;
                }

                // Read file as binary data
                const reader = new FileReader();
                reader.onload = function(e) {
                    fileDataMap[fileName] = {
                        binaryData: e.target.result,
                        file: file,
                        processed: false
                    };
                    addedCount++;
                    processedFiles++;
                    
                    updateProgress(processedFiles, totalFiles);
                    
                    // Update file list
                    updateFileList();
                    
                    if (processedFiles === totalFiles) {
                        // All files processed
                        setTimeout(() => {
                            $('#uploadProgress').fadeOut();
                            let message = `${addedCount} file(s) added to the queue.`;
                            if (duplicateCount > 0) {
                                message += ` ${duplicateCount} file(s) skipped (duplicates or invalid format).`;
                            }
                            if (addedCount > 0) {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Files Added',
                                    text: message,
                                    timer: 3000,
                                    showConfirmButton: false
                                });
                            }
                        }, 500);
                    }
                };
                reader.readAsArrayBuffer(file);
            }

            function updateProgress(processed, total) {
                const percent = Math.round((processed / total) * 100);
                $('#uploadProgressBar').css('width', percent + '%');
                $('#uploadProgressText').text(`Processing ${processed} of ${total} files...`);
                if (processed === total) {
                    $('#uploadProgressText').text('Complete!');
                }
            }

            // Reset file input
            $('#excel_file').val('');
        }

        // Process multiple files
        function processAllFiles() {
            const fileNames = Object.keys(fileDataMap);
            if (fileNames.length === 0) {
                Swal.fire('Info', 'No files to process.', 'info');
                return;
            }

            $('#file_status_badge')
                .removeClass('bg-success bg-warning bg-danger')
                .addClass('bg-warning')
                .text('Processing...');

            Swal.fire({
                title: 'Processing Excel Files...',
                text: `Processing ${fileNames.length} file(s)...`,
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });

            let allPayloadRows = [];
            let totalProcessed = 0;
            let fileErrors = [];

            // Process each file
            fileNames.forEach((fileName, fileIndex) => {
                try {
                    const fileData = fileDataMap[fileName];
                    const data = new Uint8Array(fileData.binaryData);
                    const workbook = XLSX.read(data, {type: 'array'});
                    const firstSheetName = workbook.SheetNames[0];
                    const worksheet = workbook.Sheets[firstSheetName];

                    let cellA9 = worksheet['A9'] ? String(worksheet['A9'].v).trim() : '';
                    let sourceFile = '';
                    let isKP7 = false;
                    
                    if (cellA9.toUpperCase().includes('STATUS')) {
                        sourceFile = 'KP7';
                        isKP7 = true;
                    } else if (cellA9 === 'No') {
                        sourceFile = 'KPX';
                    }

                    let cellB3 = worksheet['B3'] ? worksheet['B3'].v : null;
                    let reportDate = cellB3 ? convertToYMD(String(cellB3)) : null;

                    // Get run_date from cell B7
                    let cellB7 = worksheet['B7'] ? worksheet['B7'].v : null;
                    let runDate = cellB7 ? normalizeDateTime(String(cellB7)) : null;

                    let range = XLSX.utils.decode_range(worksheet['!ref']);
                    let payloadRows = [];

                    if (isKP7) {
                        for (let r = 9; r <= range.e.r; r++) {
                            let datetimeCell = worksheet[XLSX.utils.encode_cell({r: r, c: 2})];
                            
                            if (!datetimeCell || datetimeCell.v === null || String(datetimeCell.v).trim() === "") {
                                break;
                            }

                            let branchName = worksheet[XLSX.utils.encode_cell({r: r, c: 14})] ? 
                                String(worksheet[XLSX.utils.encode_cell({r: r, c: 14})].v).trim() : null;
                            
                            let regionValue = worksheet[XLSX.utils.encode_cell({r: r, c: 15})] ? 
                                String(worksheet[XLSX.utils.encode_cell({r: r, c: 15})].v).trim() : null;

                            let partnerId = worksheet[XLSX.utils.encode_cell({r: r, c: 18})] ? 
                                String(worksheet[XLSX.utils.encode_cell({r: r, c: 18})].v).trim() : null;
                            
                            // Get Region Code TG from column O (index 14)
                            let regionCodeTg = worksheet[XLSX.utils.encode_cell({r: r, c: 14})] ? 
                                String(worksheet[XLSX.utils.encode_cell({r: r, c: 14})].v).trim() : null;

                            let rowData = {
                                status: null,
                                billing_invoice: null,
                                report_date: reportDate,
                                settlement_date: null,
                                datetime: datetimeCell.w ? datetimeCell.w : datetimeCell.v,
                                cancellation_date: null,
                                source_file: sourceFile,
                                run_date: runDate,
                                control_no: worksheet[XLSX.utils.encode_cell({r: r, c: 3})] ? worksheet[XLSX.utils.encode_cell({r: r, c: 3})].v : null,
                                reference_no: worksheet[XLSX.utils.encode_cell({r: r, c: 4})] ? worksheet[XLSX.utils.encode_cell({r: r, c: 4})].v : null,
                                payor: worksheet[XLSX.utils.encode_cell({r: r, c: 5})] ? worksheet[XLSX.utils.encode_cell({r: r, c: 5})].v : null,
                                address: worksheet[XLSX.utils.encode_cell({r: r, c: 6})] ? worksheet[XLSX.utils.encode_cell({r: r, c: 6})].v : null,
                                account_no: worksheet[XLSX.utils.encode_cell({r: r, c: 7})] ? worksheet[XLSX.utils.encode_cell({r: r, c: 7})].v : null,
                                account_name: worksheet[XLSX.utils.encode_cell({r: r, c: 8})] ? worksheet[XLSX.utils.encode_cell({r: r, c: 8})].v : null,
                                amount_paid: worksheet[XLSX.utils.encode_cell({r: r, c: 9})] ? worksheet[XLSX.utils.encode_cell({r: r, c: 9})].v : 0,
                                charge_to_customer: worksheet[XLSX.utils.encode_cell({r: r, c: 11})] ? worksheet[XLSX.utils.encode_cell({r: r, c: 11})].v : 0,
                                charge_to_partner: worksheet[XLSX.utils.encode_cell({r: r, c: 10})] ? worksheet[XLSX.utils.encode_cell({r: r, c: 10})].v : 0,
                                contact_no: worksheet[XLSX.utils.encode_cell({r: r, c: 12})] ? worksheet[XLSX.utils.encode_cell({r: r, c: 12})].v : null,
                                other_details: worksheet[XLSX.utils.encode_cell({r: r, c: 13})] ? worksheet[XLSX.utils.encode_cell({r: r, c: 13})].v : null,
                                branch_id: null,
                                ml_matic_branch_name: branchName,
                                region_value: regionValue,
                                region_code_tg: regionCodeTg,
                                region_tg: worksheet[XLSX.utils.encode_cell({r: r, c: 15})] ? worksheet[XLSX.utils.encode_cell({r: r, c: 15})].v : null,
                                operator: worksheet[XLSX.utils.encode_cell({r: r, c: 16})] ? worksheet[XLSX.utils.encode_cell({r: r, c: 16})].v : null,
                                remote_branch: null,
                                remote_operator: null,
                                second_approver: null,
                                sub_billers_id: null,
                                sub_billers_name: null,
                                partner_name: null,
                                partner_id: partnerId,
                                partner_id_kpx: null,
                                mpm_gl_code: null,
                                settle_unsettle: 'Unsettle',
                                claim_unclaim: null,
                                imported_by: current_user,
                                imported_date: imported_date,
                                rfp_no: null,
                                cad_no: null,
                                hold_status: null,
                                post_transaction: 'unposted'
                            };
                            payloadRows.push(rowData);
                        }
                    } else {
                        for (let r = 9; r <= range.e.r; r++) {
                            let datetimeCell = worksheet[XLSX.utils.encode_cell({r: r, c: 1})];
                            
                            if (!datetimeCell || datetimeCell.v === null || String(datetimeCell.v).trim() === "") {
                                break;
                            }

                            // Get Region Code TG from column O (index 14)
                            let regionCodeTg = worksheet[XLSX.utils.encode_cell({r: r, c: 14})] ? 
                                String(worksheet[XLSX.utils.encode_cell({r: r, c: 14})].v).trim() : null;

                            let rowData = {
                                status: null,
                                billing_invoice: null,
                                report_date: reportDate,
                                settlement_date: null,
                                datetime: datetimeCell.w ? datetimeCell.w : datetimeCell.v,
                                cancellation_date: '',
                                source_file: sourceFile,
                                run_date: runDate,
                                control_no: worksheet[XLSX.utils.encode_cell({r: r, c: 2})] ? worksheet[XLSX.utils.encode_cell({r: r, c: 2})].v : null,
                                reference_no: worksheet[XLSX.utils.encode_cell({r: r, c: 3})] ? worksheet[XLSX.utils.encode_cell({r: r, c: 3})].v : null,
                                payor: worksheet[XLSX.utils.encode_cell({r: r, c: 4})] ? worksheet[XLSX.utils.encode_cell({r: r, c: 4})].v : null,
                                address: worksheet[XLSX.utils.encode_cell({r: r, c: 5})] ? worksheet[XLSX.utils.encode_cell({r: r, c: 5})].v : null,
                                account_no: worksheet[XLSX.utils.encode_cell({r: r, c: 6})] ? worksheet[XLSX.utils.encode_cell({r: r, c: 6})].v : null,
                                account_name: worksheet[XLSX.utils.encode_cell({r: r, c: 7})] ? worksheet[XLSX.utils.encode_cell({r: r, c: 7})].v : null,
                                amount_paid: worksheet[XLSX.utils.encode_cell({r: r, c: 8})] ? worksheet[XLSX.utils.encode_cell({r: r, c: 8})].v : 0,
                                charge_to_customer: worksheet[XLSX.utils.encode_cell({r: r, c: 9})] ? worksheet[XLSX.utils.encode_cell({r: r, c: 9})].v : 0,
                                charge_to_partner: worksheet[XLSX.utils.encode_cell({r: r, c: 10})] ? worksheet[XLSX.utils.encode_cell({r: r, c: 10})].v : 0,
                                contact_no: null,
                                other_details: worksheet[XLSX.utils.encode_cell({r: r, c: 11})] ? worksheet[XLSX.utils.encode_cell({r: r, c: 11})].v : null,
                                branch_id: worksheet[XLSX.utils.encode_cell({r: r, c: 12})] ? worksheet[XLSX.utils.encode_cell({r: r, c: 12})].v : null,
                                ml_matic_branch_name: null,
                                region_value: null,
                                region_code_tg: regionCodeTg,
                                region_tg: worksheet[XLSX.utils.encode_cell({r: r, c: 15})] ? worksheet[XLSX.utils.encode_cell({r: r, c: 15})].v : null,
                                operator: worksheet[XLSX.utils.encode_cell({r: r, c: 16})] ? worksheet[XLSX.utils.encode_cell({r: r, c: 16})].v : null,
                                remote_branch: worksheet[XLSX.utils.encode_cell({r: r, c: 17})] ? worksheet[XLSX.utils.encode_cell({r: r, c: 17})].v : null,
                                remote_operator: worksheet[XLSX.utils.encode_cell({r: r, c: 18})] ? worksheet[XLSX.utils.encode_cell({r: r, c: 18})].v : null,
                                second_approver: worksheet[XLSX.utils.encode_cell({r: r, c: 19})] ? worksheet[XLSX.utils.encode_cell({r: r, c: 19})].v : null,
                                sub_billers_id: null,
                                sub_billers_name: null,
                                partner_id_kpx: worksheet[XLSX.utils.encode_cell({r: r, c: 20})] ? worksheet[XLSX.utils.encode_cell({r: r, c: 20})].v : null,
                                partner_name: null,
                                partner_id: null,
                                mpm_gl_code: null,
                                settle_unsettle: 'Unsettle',
                                claim_unclaim: null,
                                imported_by: current_user,
                                imported_date: imported_date,
                                rfp_no: null,
                                cad_no: null,
                                hold_status: null,
                                post_transaction: 'unposted'
                            };
                            payloadRows.push(rowData);
                        }
                    }

                    if (payloadRows.length > 0) {
                        allPayloadRows = allPayloadRows.concat(payloadRows);
                        totalProcessed += payloadRows.length;
                        // Mark file as processed
                        fileDataMap[fileName].processed = true;
                    } else {
                        fileErrors.push(`${fileName}: No transaction records found.`);
                    }
                } catch (error) {
                    fileErrors.push(`${fileName}: ${error.message}`);
                }
            });

            if (allPayloadRows.length === 0) {
                Swal.close();
                Swal.fire('Error', 'No valid transaction records found in any of the selected files. Please check file formats.', 'error');
                return;
            }

            if (fileErrors.length > 0) {
                console.warn('File processing errors:', fileErrors);
            }

            // Update file list to show processed status
            updateFileList();

            // Send all rows to server for lookup
            $.ajax({
                url: 'process-lookup.php',
                type: 'POST',
                data: { 
                    rows: JSON.stringify(allPayloadRows),
                    source_file: 'multiple',
                    is_kp7: 0
                },
                dataType: 'json',
                success: function(response) {
                    Swal.close();
                    if(response.status === 'success') {
                        allRows = response.data;

                        allRows = processCancelledTransactions(allRows);
                        
                        // Debug: Log partner data
                        console.log('=== Partner Data Debug ===');
                        allRows.forEach((row, index) => {
                            console.log(`Row ${index + 1}:`, {
                                partner_id_kpx: row.partner_id_kpx,
                                partner_name: row.partner_name,
                                is_empty: isEmptyValue(row.partner_id_kpx),
                                is_not_found: row.partner_name === 'Not Found'
                            });
                        });
                        
                        let emptyBranchRows = [];
                        let emptyPartnerRows = [];
                        let unrecognizedPartnerRows = [];
                        importDisabled = false;
                        
                        allRows.forEach((row, index) => {
                            const rowNum = index + 1;
                            
                            if (isEmptyValue(row.branch_id)) {
                                emptyBranchRows.push({
                                    row: rowNum,
                                    payor: row.payor || 'N/A',
                                    branch_id: row.branch_id !== undefined && row.branch_id !== null ? String(row.branch_id) : '(empty)'
                                });
                            }
                            
                            if (isEmptyValue(row.partner_id_kpx)) {
                                emptyPartnerRows.push({
                                    row: rowNum,
                                    payor: row.payor || 'N/A',
                                    account_no: row.account_no || 'N/A'
                                });
                                importDisabled = true;
                            } else if (row.partner_name === 'Not Found' || 
                                       row.partner_name === null || 
                                       row.partner_name === undefined || 
                                       row.partner_name === '') {
                                unrecognizedPartnerRows.push({
                                    row: rowNum,
                                    partner_id_kpx: row.partner_id_kpx,
                                    payor: row.payor || 'N/A',
                                    partner_name: row.partner_name || '(empty)'
                                });
                                importDisabled = true;
                            }
                        });
                        
                        warningData = {
                            emptyBranchRows: emptyBranchRows,
                            emptyPartnerRows: emptyPartnerRows,
                            unrecognizedPartnerRows: unrecognizedPartnerRows
                        };
                        
                        $('#totalRecords').text(allRows.length);
                        $('#emptyBranchCount').text(emptyBranchRows.length);
                        $('#emptyPartnerCount').text(emptyPartnerRows.length);
                        $('#unrecognizedPartnerCount').text(unrecognizedPartnerRows.length);
                        $('#validation_summary').show();
                        
                        let warningHtml = '';
                        let hasWarnings = false;
                        
                        if (emptyBranchRows.length > 0) {
                            hasWarnings = true;
                            warningHtml += `
                                <div class="alert alert-info">
                                    <h6><i class="fas fa-store"></i> Empty Branch ID Found</h6>
                                    <p>The following rows have empty or missing Branch ID:</p>
                                    <table class="table table-sm table-bordered table-striped">
                                        <thead class="table-dark">
                                            <tr>
                                                <th>Row #</th>
                                                <th>Branch ID Value</th>
                                                <th>Payor</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            ${emptyBranchRows.map(item => `
                                                <tr>
                                                    <td><strong>${item.row}</strong></td>
                                                    <td><code>${item.branch_id}</code></td>
                                                    <td>${item.payor}</td>
                                                </tr>
                                            `).join('')}
                                        </tbody>
                                    </table>
                                    <small class="text-muted">These rows will be imported with 'Not Found' values for branch-related fields.</small>
                                </div>
                            `;
                        }
                        
                        if (emptyPartnerRows.length > 0) {
                            hasWarnings = true;
                            warningHtml += `
                                <div class="alert alert-danger critical-error">
                                    <h6><i class="fas fa-ban"></i> Empty Partner ID KPX - Import Disabled</h6>
                                    <p>The following rows have empty or missing Partner ID KPX.</p>
                                    <table class="table table-sm table-bordered table-striped">
                                        <thead class="table-dark">
                                            <tr>
                                                <th>Row #</th>
                                                <th>Payor</th>
                                                <th>Account No</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            ${emptyPartnerRows.map(item => `
                                                <tr>
                                                    <td><strong>${item.row}</strong></td>
                                                    <td>${item.payor}</td>
                                                    <td>${item.account_no}</td>
                                                </tr>
                                            `).join('')}
                                        </tbody>
                                    </table>
                                    <div class="alert alert-danger mt-2 mb-0">
                                        <i class="fas fa-exclamation-triangle"></i> 
                                        <strong>Action Required:</strong> Please provide valid Partner ID KPX for these rows before importing.
                                    </div>
                                </div>
                            `;
                        }
                        
                        if (unrecognizedPartnerRows.length > 0) {
                            hasWarnings = true;
                            warningHtml += `
                                <div class="alert alert-danger critical-error">
                                    <h6><i class="fas fa-exclamation-circle"></i> Unrecognized / Unregistered Partner ID KPX - Import Disabled</h6>
                                    <p>The following partner IDs were not found in the partner masterfile. <strong>Import is disabled until these are registered.</strong></p>
                                    <table class="table table-sm table-bordered table-striped">
                                        <thead class="table-dark">
                                            <tr>
                                                <th>Row #</th>
                                                <th>Partner ID KPX</th>
                                                <th>Payor</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            ${unrecognizedPartnerRows.map(item => `
                                                <tr>
                                                    <td><strong>${item.row}</strong></td>
                                                    <td><code>${item.partner_id_kpx}</code></td>
                                                    <td>${item.payor}</td>
                                                </tr>
                                            `).join('')}
                                        </tbody>
                                    </table>
                                    <div class="alert alert-danger mt-2 mb-0">
                                        <i class="fas fa-exclamation-triangle"></i> 
                                        <strong>Action Required:</strong> Please register these partner IDs in the masterfile before importing.
                                    </div>
                                </div>
                            `;
                        }
                        
                        if (hasWarnings) {
                            let statusBadge = '';
                            if (importDisabled) {
                                statusBadge = `
                                    <div class="alert alert-danger mb-3">
                                        <i class="fas fa-ban"></i> 
                                        <strong>Import Disabled:</strong> Errors found. Please fix the issues below before proceeding.
                                    </div>
                                `;
                            }
                            
                            warningHtml = `
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle"></i> <strong>${allRows.length}</strong> records loaded from ${Object.keys(fileDataMap).length} file(s) with validation issues detected:
                                    <ul class="mb-0 mt-2">
                                        ${emptyBranchRows.length > 0 ? `<li><span class="badge bg-info">${emptyBranchRows.length}</span> Empty / Not Found Branch ID rows</li>` : ''}
                                        ${emptyPartnerRows.length > 0 ? `<li><span class="badge bg-danger">${emptyPartnerRows.length}</span> Empty Partner ID KPX rows</li>` : ''}
                                        ${unrecognizedPartnerRows.length > 0 ? `<li><span class="badge bg-primary">${unrecognizedPartnerRows.length}</span> Unrecognized Partner ID KPX rows</li>` : ''}
                                    </ul>
                                </div>
                                ${statusBadge}
                                ${warningHtml}
                            `;
                            $('#warningContent').html(warningHtml);
                            $('#warningModal').modal('show');
                            
                            if (importDisabled) {
                                $('#proceedImportBtn')
                                    .prop('disabled', true)
                                    .addClass('import-disabled')
                                    .html('<i class="fas fa-ban"></i> Import Disabled - Fix Errors');
                            } else {
                                $('#proceedImportBtn')
                                    .prop('disabled', false)
                                    .removeClass('import-disabled')
                                    .html('<i class="fas fa-upload"></i> Proceed with Import');
                            }
                            
                            $('#proceedImportBtn').off('click').on('click', function() {
                                if (!importDisabled) {
                                    $('#warningModal').modal('hide');
                                    proceedWithImport(allRows);
                                }
                            });
                        } else {
                            proceedWithImport(allRows);
                        }
                        
                        totalPages = Math.ceil(allRows.length / rowsPerPage);
                        currentPage = 1;
                        
                        renderTable();
                        renderPagination();
                        
                        // Display summary
                        displaySummary(allRows);
                        
                        $('#preview_section').removeClass('d-none');
                        $('#pagination_container').removeClass('d-none');
                        
                        $('#file_status_badge')
                            .removeClass('bg-success bg-warning bg-danger')
                            .addClass(importDisabled ? 'bg-danger' : 'bg-success')
                            .text(importDisabled ? 'Import Disabled - Fix Errors' : 'Loaded ' + allRows.length + ' Records from ' + Object.keys(fileDataMap).length + ' file(s)');
                    } else {
                        $('#file_status_badge')
                            .removeClass('bg-success bg-warning bg-danger')
                            .addClass('bg-danger')
                            .text('Error');
                        Swal.fire('Error', response.message, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    Swal.close();
                    $('#file_status_badge')
                        .removeClass('bg-success bg-warning bg-danger')
                        .addClass('bg-danger')
                        .text('Error');
                    Swal.fire('Error', 'Failed to fetch background profile match data. Please try again.', 'error');
                    console.error('AJAX Error:', error);
                    console.error('Status:', status);
                    console.error('Response:', xhr.responseText);
                }
            });
        }

        // Parse amount function
        function parseAmount(value) {
            if (value === null || value === undefined || value === '') return 0;
            
            // If it's already a number, return it
            if (typeof value === 'number') return value;
            
            // If it's a string, clean it and parse
            if (typeof value === 'string') {
                // Remove currency symbols, commas, and extra spaces
                let cleaned = value.replace(/[₱ $,]/g, '').trim();
                // Handle negative numbers with parentheses or minus sign
                if (cleaned.startsWith('(') && cleaned.endsWith(')')) {
                    cleaned = '-' + cleaned.slice(1, -1);
                }
                // Parse the number
                const parsed = parseFloat(cleaned);
                return isNaN(parsed) ? 0 : parsed;
            }
            
            return 0;
        }


        // Helper function to set cancellation date for cancelled transactions (* status)
        function processCancelledTransactions(rows) {
            rows.forEach(row => {
                const amountPaid = parseAmount(row.amount_paid);
                const chargeCustomer = parseAmount(row.charge_to_customer);
                const chargePartner = parseAmount(row.charge_to_partner);
                
                const hasNegativeAmount = amountPaid < 0 || chargeCustomer < 0 || chargePartner < 0;
                
                if (hasNegativeAmount) {
                    // Mark as cancelled with *
                    row.status = '*';
                    
                    // Set cancellation_date to report_date at midnight
                    if (row.report_date && row.report_date.trim() !== '') {
                        const ymd = row.report_date.trim();
                        // Format as datetime: YYYY-MM-DD 00:00:00
                        row.cancellation_date = `${ymd} 00:00:00`;
                    } else {
                        row.cancellation_date = null;
                    }
                }
            });
            return rows;
        }

        function renderTable() {
            let tbody = $('#transaction_table tbody');
            tbody.empty();

            const startIndex = (currentPage - 1) * rowsPerPage;
            const endIndex = Math.min(startIndex + rowsPerPage, allRows.length);
            const pageRows = allRows.slice(startIndex, endIndex);

            pageRows.forEach((row, index) => {
                const rowNum = startIndex + index + 1;
                let rowClass = '';
                
                // Check for negative values in Amount Paid, Charge to Customer, or Charge to Partner
                const amountPaid = parseAmount(row.amount_paid);
                const chargeCustomer = parseAmount(row.charge_to_customer);
                const chargePartner = parseAmount(row.charge_to_partner);
                
                // Determine if this row has negative amount
                const hasNegativeAmount = amountPaid < 0 || chargeCustomer < 0 || chargePartner < 0;
                
                if (hasNegativeAmount) {
                    rowClass += ' has-negative-amount';
                }
                
                if (isEmptyValue(row.branch_id)) {
                    rowClass += ' has-empty-branch';
                }
                
                if (isEmptyValue(row.partner_id_kpx)) {
                    rowClass += ' has-empty-partner';
                } else if (row.partner_name === 'Not Found' && !isEmptyValue(row.partner_id_kpx)) {
                    rowClass += ' has-unrecognized-partner';
                }
                
                // Set status value - asterisk for negative amounts, otherwise use existing status or empty
                let statusValue = row.status;
                if (hasNegativeAmount) {
                    statusValue = '*';
                } else if (!statusValue || statusValue === null || statusValue === undefined || statusValue === '') {
                    statusValue = '';
                }
                
                let tr = `<tr class="${rowClass}">
                    <td>${rowNum}</td>
                    <td>${formatValue(statusValue)}</td>
                    <td>${formatValue(row.billing_invoice)}</td>
                    <td>${formatValue(row.report_date)}</td>
                    <td>${formatValue(row.settlement_date)}</td>
                    <td>${formatValue(row.datetime)}</td>
                    <td>${formatValue(row.cancellation_date)}</td>
                    <td>${formatValue(row.source_file)}</td>
                    <td>${formatValue(row.run_date)}</td>
                    <td>${formatValue(row.control_no)}</td>
                    <td>${formatValue(row.reference_no)}</td>
                    <td>${formatValue(row.payor)}</td>
                    <td>${formatValue(row.address)}</td>
                    <td>${formatValue(row.account_no)}</td>
                    <td>${formatValue(row.account_name)}</td>
                    <td>${formatValue(row.amount_paid)}</td>
                    <td>${formatValue(row.charge_to_customer)}</td>
                    <td>${formatValue(row.charge_to_partner)}</td>
                    <td>${formatValue(row.contact_no)}</td>
                    <td>${formatValue(row.other_details)}</td>
                    <td>${formatValue(row.branch_id)}</td>
                    <td class="table-info"><strong>${formatValue(row.branch_code ?? 'Not Found')}</strong></td>
                    <td class="table-info"><strong>${formatValue(row.outlet ?? 'Not Found')}</strong></td>
                    <td class="table-info"><strong>${formatValue(row.zone_code ?? 'Not Found')}</strong></td>
                    <td class="table-info"><strong>${formatValue(row.region_code ?? 'Not Found')}</strong></td>
                    <td class="table-info"><strong>${formatValue(row.region_code_tg ?? 'Not Found')}</strong></td>
                    <td class="table-info"><strong>${formatValue(row.region ?? 'Not Found')}</strong></td>
                    <td>${formatValue(row.region_tg)}</td>
                    <td>${formatValue(row.operator)}</td>
                    <td>${formatValue(row.remote_branch)}</td>
                    <td>${formatValue(row.remote_operator)}</td>
                    <td>${formatValue(row.second_approver)}</td>
                    <td>${formatValue(row.sub_billers_id)}</td>
                    <td>${formatValue(row.sub_billers_name)}</td>
                    <td class="table-warning"><strong>${formatValue(row.partner_name ?? 'Not Found')}</strong></td>
                    <td class="table-warning"><strong>${formatValue(row.partner_id ?? 'Not Found')}</strong></td>
                    <td class="col-u-highlight">${formatValue(row.partner_id_kpx)}</td>
                    <td class="table-warning"><strong>${formatValue(row.mpm_gl_code ?? 'Not Found')}</strong></td>
                    <td>${formatValue(row.settle_unsettle)}</td>
                    <td>${formatValue(row.claim_unclaim)}</td>
                    <td>${formatValue(row.imported_by)}</td>
                    <td>${formatValue(row.imported_date)}</td>
                    <td>${formatValue(row.rfp_no)}</td>
                    <td>${formatValue(row.cad_no)}</td>
                    <td>${formatValue(row.hold_status)}</td>
                    <td>${formatValue(row.post_transaction)}</td>
                </tr>`;
                tbody.append(tr);
            });
        }

        function renderPagination() {
            const container = $('#page_buttons');
            container.empty();

            const maxButtons = 5;
            let startPage = Math.max(1, currentPage - Math.floor(maxButtons / 2));
            let endPage = Math.min(totalPages, startPage + maxButtons - 1);
            
            if (endPage - startPage < maxButtons - 1) {
                startPage = Math.max(1, endPage - maxButtons + 1);
            }

            if (startPage > 1) {
                container.append(`<button class="page-btn btn btn-sm btn-outline-secondary" data-page="1">1</button>`);
                if (startPage > 2) {
                    container.append(`<span class="mx-1">...</span>`);
                }
            }

            for (let i = startPage; i <= endPage; i++) {
                const activeClass = i === currentPage ? 'active' : '';
                container.append(`<button class="page-btn btn btn-sm btn-outline-secondary ${activeClass}" data-page="${i}">${i}</button>`);
            }

            if (endPage < totalPages) {
                if (endPage < totalPages - 1) {
                    container.append(`<span class="mx-1">...</span>`);
                }
                container.append(`<button class="page-btn btn btn-sm btn-outline-secondary" data-page="${totalPages}">${totalPages}</button>`);
            }

            const startIndex = (currentPage - 1) * rowsPerPage + 1;
            const endIndex = Math.min(currentPage * rowsPerPage, allRows.length);
            $('#page_info').text(`Showing ${startIndex} - ${endIndex} of ${allRows.length} records`);

            $('#prev_page').prop('disabled', currentPage === 1);
            $('#next_page').prop('disabled', currentPage === totalPages);

            $('.page-btn').on('click', function() {
                const page = parseInt($(this).data('page'));
                if (page !== currentPage) {
                    currentPage = page;
                    renderTable();
                    renderPagination();
                    $('#preview_section').get(0).scrollTop = 0;
                }
            });
        }

        $('#prev_page').on('click', function() {
            if (currentPage > 1) {
                currentPage--;
                renderTable();
                renderPagination();
                $('#preview_section').get(0).scrollTop = 0;
            }
        });

        $('#next_page').on('click', function() {
            if (currentPage < totalPages) {
                currentPage++;
                renderTable();
                renderPagination();
                $('#preview_section').get(0).scrollTop = 0;
            }
        });

        // Summary button click handler
        $('#btn_summary').on('click', function() {
            if (allRows.length > 0) {
                displaySummary(allRows);
                $('#summaryModal').modal('show');
            } else {
                Swal.fire('Info', 'No data available to summarize.', 'info');
            }
        });

        // Export summary button click handler
        $('#btn_export_summary').on('click', function() {
            if (allRows.length === 0) {
                Swal.fire('Info', 'No data to export.', 'info');
                return;
            }
            
            const stats = calculateSummary(allRows);
            
            // Create CSV content
            let csvContent = "Metric,SUMMARY,ADJUSTMENTS,NET\n";
            csvContent += `TOTAL COUNT,${stats.summary.count},${stats.adjustments.count},${stats.net.count}\n`;
            csvContent += `TOTAL PRINCIPAL (PHP),${stats.summary.principal.toFixed(2)},${stats.adjustments.principal.toFixed(2)},${stats.net.principal.toFixed(2)}\n`;
            csvContent += `TOTAL CHARGE (PHP),${stats.summary.charge.toFixed(2)},${stats.adjustments.charge.toFixed(2)},${stats.net.charge.toFixed(2)}\n`;
            csvContent += `TOTAL CTC (PHP),${stats.summary.ctc.toFixed(2)},${stats.adjustments.ctc.toFixed(2)},${stats.net.ctc.toFixed(2)}\n`;
            csvContent += `TOTAL CTP (PHP),${stats.summary.ctp.toFixed(2)},${stats.adjustments.ctp.toFixed(2)},${stats.net.ctp.toFixed(2)}\n`;
            csvContent += `SETTLEMENT AMOUNT (PHP),,,${stats.net.settlement.toFixed(2)}\n`;
            
            // Create download
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', `transaction_summary_${new Date().toISOString().slice(0,10)}.csv`);
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);
            
            Swal.fire('Success', 'Summary exported successfully!', 'success');
        });

        function proceedWithImport(rows) {
            if (importDisabled) {
                Swal.fire({
                    icon: 'error',
                    title: 'Import Disabled',
                    html: `
                        <div class="text-start">
                            <p>Import is currently disabled due to remarks found:</p>
                            <ul class="text-start">
                                ${warningData.emptyPartnerRows.length > 0 ? `<li><span class="badge bg-danger">${warningData.emptyPartnerRows.length}</span> Empty Partner ID KPX rows</li>` : ''}
                                ${warningData.unrecognizedPartnerRows.length > 0 ? `<li><span class="badge bg-danger">${warningData.unrecognizedPartnerRows.length}</span> Unrecognized Partner ID KPX rows</li>` : ''}
                            </ul>
                            <div class="alert alert-danger mt-2">
                                <i class="fas fa-exclamation-triangle"></i> 
                                Please fix these issues before importing.
                            </div>
                        </div>
                    `,
                    confirmButtonText: 'OK'
                });
                return;
            }

            Swal.fire({
                icon: 'question',
                title: 'Ready to Import',
                html: `
                    <div class="text-start">
                        <p>You are about to import <strong>${rows.length}</strong> records from ${Object.keys(fileDataMap).length} file(s).</p>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i> 
                            Please ensure you have reviewed the data before proceeding.
                        </div>
                        <div class="row mt-3">
                            <div class="col-md-4">
                                <span class="badge bg-info">Total: ${rows.length}</span>
                            </div>
                            <div class="col-md-4">
                                <span class="badge bg-warning">Empty Branch: ${warningData.emptyBranchRows.length}</span>
                            </div>
                            <div class="col-md-4">
                                <span class="badge bg-success">Partner Issues: ${warningData.emptyPartnerRows.length + warningData.unrecognizedPartnerRows.length}</span>
                            </div>
                        </div>
                        <div class="mt-2">
                            <small class="text-muted">Files: ${Object.keys(fileDataMap).join(', ')}</small>
                        </div>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonColor: '#28a745',
                cancelButtonColor: '#d33',
                confirmButtonText: '<i class="fas fa-upload"></i> Import Now',
                cancelButtonText: '<i class="fas fa-times"></i> Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        title: 'Importing transactions...',
                        text: 'Please wait while the records are saved.',
                        allowOutsideClick: false,
                        didOpen: () => { Swal.showLoading(); }
                    });

                    $.ajax({
                        url: 'process-lookup.php',
                        type: 'POST',
                        data: {
                            action: 'import',
                            rows: JSON.stringify(rows)
                        },
                        dataType: 'json',
                        success: function(response) {
                            Swal.close();

                            if (response.status === 'success' || response.status === 'partial') {
                                const inserted = parseInt(response.inserted || 0, 10);
                                const duplicateCount = parseInt(response.duplicate_count || 0, 10);
                                const errorCount = parseInt(response.error_count || 0, 10);
                                const duplicateRows = (response.duplicates || []).slice(0, 10).map(item => {
                                    const source = item.type === 'file' ? 'same file batch' : 'database';
                                    return `<li>Row ${item.row}: ${item.reference_no || '(blank reference)'} (${source})</li>`;
                                }).join('');
                                const errorRows = (response.errors || []).slice(0, 10).map(item => {
                                    return `<li>Row ${item.row}: ${item.message || 'Unable to insert'}</li>`;
                                }).join('');

                                Swal.fire({
                                    icon: errorCount > 0 ? 'warning' : 'success',
                                    title: errorCount > 0 ? 'Import Partially Completed' : 'Import Completed',
                                    html: `
                                        <div class="text-start">
                                            <p><strong>${inserted}</strong> record(s) imported.</p>
                                            <p><strong>${duplicateCount}</strong> duplicate record(s) skipped.</p>
                                            ${errorCount > 0 ? `<p><strong>${errorCount}</strong> record(s) failed.</p>` : ''}
                                            ${duplicateRows ? `<hr><strong>Duplicate samples:</strong><ul>${duplicateRows}</ul>` : ''}
                                            ${errorRows ? `<hr><strong>Error samples:</strong><ul>${errorRows}</ul>` : ''}
                                        </div>
                                    `,
                                    showConfirmButton: true
                                }).then(() => {
                                    if (inserted > 0) {
                                        window.location.reload();
                                    }
                                });
                            } else {
                                Swal.fire('Error', response.message || 'Import failed. Please try again.', 'error');
                            }
                        },
                        error: function(xhr, status, error) {
                            Swal.close();
                            Swal.fire('Error', 'Failed to import transactions. Please try again.', 'error');
                            console.error('Import AJAX Error:', error);
                            console.error('Status:', status);
                            console.error('Response:', xhr.responseText);
                        }
                    });
                }
            });
        }

        // ============================================
        // DRAG AND DROP FUNCTIONALITY
        // ============================================
        
        const dropZone = document.getElementById('dropZone');
        const fileInput = document.getElementById('excel_file');
        const browseLink = document.getElementById('browseLink');

        // Prevent default drag behaviors
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            document.addEventListener(eventName, (e) => {
                e.preventDefault();
                e.stopPropagation();
            });
        });

        // Highlight drop zone when file is dragged over
        ['dragenter', 'dragover'].forEach(eventName => {
            dropZone.addEventListener(eventName, (e) => {
                dropZone.classList.add('dragover');
            });
        });

        ['dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, (e) => {
                dropZone.classList.remove('dragover');
            });
        });

        // Handle dropped files
        dropZone.addEventListener('drop', (e) => {
            const files = e.dataTransfer.files;
            handleFiles(files);
        });

        // Click on drop zone opens file dialog
        dropZone.addEventListener('click', (e) => {
            // Prevent opening dialog if clicking on remove buttons or other interactive elements
            if (e.target.closest('.remove-file') || e.target.closest('.btn')) {
                return;
            }
            fileInput.click();
        });

        // Browse link opens file dialog
        browseLink.addEventListener('click', (e) => {
            e.stopPropagation();
            fileInput.click();
        });

        // Handle file selection via input
        fileInput.addEventListener('change', function(e) {
            handleFiles(e.target.files);
        });

        // ============================================
        // END OF DRAG AND DROP FUNCTIONALITY
        // ============================================

        // Process button click handler
        $('#btn_process').on('click', function() {
            processAllFiles();
        });

        // Clear all files button
        $('#btn_clear_files').on('click', function() {
            Swal.fire({
                title: 'Clear All Files?',
                text: 'This will remove all files from the queue.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Yes, clear all',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    clearAllFiles();
                    Swal.fire('Cleared', 'All files have been removed from the queue.', 'success');
                }
            });
        });

        // Initialize file list
        updateFileList();

        // Keyboard shortcut: Ctrl+Shift+C to clear all files
        $(document).on('keydown', function(e) {
            if (e.ctrlKey && e.shiftKey && (e.key === 'C' || e.key === 'c')) {
                e.preventDefault();
                if (Object.keys(fileDataMap).length > 0) {
                    $('#btn_clear_files').click();
                }
            }
        });

    });
    </script>
</body>
<?php include '../../../templates/footer.php'; ?>
</html>