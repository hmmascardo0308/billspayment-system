<?php
include '../../config/config.php';
require '../../vendor/autoload.php';

session_start();

if (isset($_SESSION['user_type'])) {
    $current_user_email = '';
    if ($_SESSION['user_type'] === 'admin' && isset($_SESSION['admin_email'])) {
        $current_user_email = $_SESSION['admin_email'];
    } elseif ($_SESSION['user_type'] === 'user' && isset($_SESSION['user_email'])) {
        $current_user_email = $_SESSION['user_email'];
        if ($_SESSION['user_email'] === 'balb01013333' || $_SESSION['user_email'] === 'pera94005055') {
            header("Location:../../index.php");
            session_destroy();
            exit();
        }
    } else {
        header("Location:../../index.php");
        session_destroy();
        exit();
    }
}

$consolidated_data = $_SESSION['consolidated_data'] ?? [];
$validation_error_json = $_SESSION['validation_error_json'] ?? '';
$validation_payload = [];

if (is_string($validation_error_json) && $validation_error_json !== '') {
    $decoded = json_decode($validation_error_json, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $validation_payload = $decoded;
        if (empty($consolidated_data) && isset($decoded['rows']) && is_array($decoded['rows'])) {
            $consolidated_data = $decoded['rows'];
        }
    }
}

$validation_summary = $validation_payload['summary'] ?? [];

// Redirect to the import page if consolidated_data is empty
if (empty($consolidated_data)) {
    header("Location:../../dashboard/billspayment/import/billspay-transaction.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php if(isset($consolidated_data[0]['source_partner']) && $consolidated_data[0]['source_partner'] === 'All') :?>
            Consolidated 
            <?php else: ?>
            Partner 
            <?php endif; ?> Transaction Detected</title>
    <link rel="icon" href="../../images/MLW logo.png" type="image/png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.all.min.js"></script>
    <style>
        /* Ensure text selection is enabled */
        .table,
        .table td,
        .table th {
            user-select: text !important;
            -webkit-user-select: text !important;
            -moz-user-select: text !important;
            -ms-user-select: text !important;
        }

        /* Highlight selected text */
        .table td::selection,
        .table th::selection {
            background-color: #007bff;
            color: white;
        }

        .table td::-moz-selection,
        .table th::-moz-selection {
            background-color: #007bff;
            color: white;
        }

        /* Make table cells more selectable */
        .table td,
        .table th {
            cursor: text;
        }

        /* Add hover effect to indicate selectable content */
        .table td:hover {
            background-color: #f8f9fa;
        }

        /* Style for copy button */
        .copy-btn {
            opacity: 1;
            transition: opacity 0.2s;
        }

        .table tr:hover .copy-btn {
            opacity: 1;
        }

        /* Placeholder styles */
        .placeholder-glow {
            display: flex;
            align-items: center;
        }

        .placeholder {
            background-color: #e9ecef;
            border-radius: 0.375rem;
            display: inline-block;
        }

        .loading-row {
            animation: pulse 1.5s infinite;
        }

        @keyframes pulse {
            0% {
                opacity: 1;
            }

            50% {
                opacity: 0.5;
            }

            100% {
                opacity: 1;
            }
        }

        .data-row {
            transition: opacity 0.3s ease-in-out;
        }

        .loading-row {
            transition: opacity 0.3s ease-in-out;
        }

        /* Progress indicator styles */
        .loading-progress {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 3px;
            background-color: #e9ecef;
            z-index: 1050;
        }

        .loading-progress-bar {
            height: 100%;
            background: linear-gradient(90deg, #007bff, #0056b3);
            width: 0%;
            transition: width 0.3s ease;
        }
    </style>
</head>

<body>
    <div class="container-fluid py-4">
        <div role="alert">
            <div class="d-flex align-items-center mb-3">
                <i class="fas fa-exclamation-triangle fa-3x text-danger me-3"></i>
                <div>
                    <h4 class="alert-heading mb-1">
                        <?php if(isset($consolidated_data[0]['source_partner']) && $consolidated_data[0]['source_partner'] === 'All') :?>
                        Consolidated 
                        <?php else: ?>
                        Partner 
                        <?php endif; ?>
                        Transaction Detected!</h4>
                </div>
            </div>

            <hr class="my-3">

            <div class="row mb-3">
                <div class="col-md-6">
                    <p><strong><i class="fas fa-file text-primary me-2"></i>Filename:</strong> <?php echo htmlspecialchars($consolidated_data[0]['original_file_name'] ?? null); ?></p>
                    <p><strong><i class="fas fa-code-branch text-info me-2"></i>Source:</strong> <?php echo htmlspecialchars($consolidated_data[0]['source_file_type'] ?? null) . ' System'; ?></p>
                </div>
                <div class="col-md-3">
                    <p><strong><i class="fas fa-building text-success me-2"></i>Partners:</strong> <?php echo htmlspecialchars($consolidated_data[0]['source_partner'] ?? null); ?></p>
                    <p><strong><i class="fas fa-list-ol text-danger me-2"></i>No. of <?php if(isset($consolidated_data[0]['source_partner']) && $consolidated_data[0]['source_partner'] === 'All') :?>
                        Consolidated 
                        <?php else: ?>
                        Partner 
                        <?php endif; ?> Records:</strong> <?php echo htmlspecialchars(number_format(count($consolidated_data)).''); ?></p>
                </div>
                <div class="col-md-3">
                    <p><strong><i class="fas fa-calendar text-success me-2"></i>Upload Date:</strong> <?php echo !empty($consolidated_data[0]['uploaded_date']) ? htmlspecialchars(date('F d, Y', strtotime($consolidated_data[0]['uploaded_date']))) : ''; ?></p>
                    <p><strong><i class="fas fa-file-import text-danger me-2"></i>Uploaded By:</strong> <?php echo htmlspecialchars($consolidated_data[0]['uploaded_by']?? null); ?></p>
                </div>
            </div>

            <?php if (!empty($validation_summary)): ?>
                <div class="row mb-3">
                    <div class="col-12">
                        <div class="alert alert-light border">
                            <strong>Validation Summary:</strong>
                            Duplicate: <?php echo intval($validation_summary['duplicate'] ?? 0); ?>,
                            Partner Not Found: <?php echo intval($validation_summary['partner_not_found'] ?? 0); ?>,
                            Branch ID Not Found: <?php echo intval($validation_summary['branch_id_not_found'] ?? 0); ?>,
                            Region Not Found: <?php echo intval($validation_summary['region_not_found'] ?? 0); ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="card shadow">
            <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-table me-2"></i><?php if(isset($consolidated_data[0]['source_partner']) && $consolidated_data[0]['source_partner'] === 'All') :?>
                        Consolidated 
                        <?php else: ?>
                        Partner 
                        <?php endif; ?> Transaction Details</h5>
                <div>
                    <button type="button" class="btn btn-light btn-sm" onclick="copyAllData()" title="Copy all data">
                        <i class="fas fa-copy me-1"></i>Copy All Data
                    </button>
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                    <table class="table table-striped table-hover" id="consolidatedTable">
                        <thead class="table-dark sticky-top">
                            <tr>
                                <th scope="col">#</th>
                                <th scope="col">Row in Excel</th>
                                <th scope="col">Report Date</th>
                                <th scope="col">Transaction Date</th>
                                <th scope="col">Reference Number</th>
                                <th scope="col">Payor Name</th>
                                <th scope="col">Amount Paid</th>
                                <th scope="col">Charge to Customer</th>
                                <th scope="col">Charge to Partner</th>
                                <th scope="col">Branch ID</th>
                                <th scope="col">Branch Outlet</th>
                                <th scope="col">Region Code</th>
                                <th scope="col">Region Name</th>
                                <th scope="col">Partner ID</th>
                                <th scope="col">Partner Name</th>
                                <th scope="col">Error Remarks</th>
                            </tr>
                        </thead>
                        <tbody id="tableBody">
                            <?php if (!empty($consolidated_data)): ?>
                                <!-- Loading placeholders (initially visible) -->
                                <?php for ($i = 0; $i < min(5, count($consolidated_data)); $i++): ?>
                                    <tr class="placeholder-glow loading-row">
                                        <td><span class="placeholder col-2"></span></td>
                                        <td><span class="placeholder col-6"></span></td>
                                        <td><span class="placeholder col-8"></span></td>
                                        <td><span class="placeholder col-8"></span></td>
                                        <td><span class="placeholder col-10"></span></td>
                                        <td><span class="placeholder col-12"></span></td>
                                        <td><span class="placeholder col-6"></span></td>
                                        <td><span class="placeholder col-6"></span></td>
                                        <td><span class="placeholder col-6"></span></td>
                                        <td><span class="placeholder col-4"></span></td>
                                        <td><span class="placeholder col-8"></span></td>
                                        <td><span class="placeholder col-4"></span></td>
                                        <td><span class="placeholder col-10"></span></td>
                                        <td><span class="placeholder col-6"></span></td>
                                        <td><span class="placeholder col-12"></span></td>
                                        <td><span class="placeholder col-4"></span></td>
                                    </tr>
                                <?php endfor; ?>
                                
                                <!-- Actual data rows (initially hidden) -->
                                <?php foreach ($consolidated_data as $i => $row): ?>
                                    <tr class="data-row" style="display: none;">
                                        <td><?= $i + 1 ?></td>
                                        <td><?= htmlspecialchars($row['row_in_excel'] ?? '') ?></td>
                                        <td><?= !empty($row['report_date']) ? htmlspecialchars(date('F d, Y', strtotime($row['report_date']))) : '' ?></td>
                                        <td><?= !empty($row['transaction_date']) ? htmlspecialchars(date('F d, Y', strtotime($row['transaction_date']))) : '' ?></td>
                                        <td><?= htmlspecialchars($row['reference_number'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($row['payor_name'] ?? '') ?></td>
                                        <td class="text-end"><?= isset($row['amount_paid']) ? '₱ ' . number_format((float)$row['amount_paid'], 2) : null ?></td>
                                        <td class="text-end"><?= isset($row['amount_charge_customer']) ? '₱ ' . number_format((float)$row['amount_charge_customer'], 2) : null ?></td>
                                        <td class="text-end"><?= isset($row['amount_charge_partner']) ? '₱ ' . number_format((float)$row['amount_charge_partner'], 2) : null ?></td>
                                        <td class="text-end"><?php $branchVal = $row['branch_id'] ?? null; echo htmlspecialchars(($branchVal == 0 ? '' : $branchVal)); ?></td>
                                        <td class="text-end"><?= htmlspecialchars($row['ml_outlet'] ?? null) ?></td>
                                        <td class="text-end"><?= htmlspecialchars($row['region_code'] ?? null) ?></td>
                                        <td class="text-end"><?= htmlspecialchars($row['region'] ?? null) ?></td>
                                        <td><?php
                                            $sourceType = $row['source_file_type'] ?? $consolidated_data[0]['source_file_type'] ?? '';
                                            if (strtoupper($sourceType) === 'KPX') {
                                                $displayPartnerId = $row['partner_id_kpx'] ?? $row['partner_id'] ?? '';
                                            } else {
                                                $displayPartnerId = $row['partner_id'] ?? $row['partner_id_kpx'] ?? '';
                                            }
                                            echo htmlspecialchars($displayPartnerId);
                                        ?></td>
                                        <td><?= htmlspecialchars($row['partner_name'] ?? '') ?></td>
                                        <td class="text-center" data-error-remarks="<?= htmlspecialchars($row['error_remarks'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                            <?php
                                            $remarks = $row['error_remarks'] ?? '';
                                            if (!empty($remarks)) {
                                                $remarkList = array_filter(array_map('trim', explode(';', $remarks)));
                                                $popoverContent = '';
                                                foreach ($remarkList as $remark) {
                                                    $popoverContent .= '<span class="badge bg-danger me-1">' . htmlspecialchars($remark) . '</span>';
                                                }
                                                ?>
                                                <a href="#" tabindex="-1"
                                                    data-bs-toggle="popover"
                                                    data-bs-trigger="hover focus"
                                                    data-bs-html="true"
                                                    data-bs-content="<?= htmlspecialchars($popoverContent, ENT_QUOTES, 'UTF-8') ?>"
                                                    title="Error Remarks">
                                                    <i class="fas fa-info-circle text-danger me-2"></i>
                                                </a>
                                                <?php
                                            } else {
                                                echo '<span class="text-muted">No remarks</span>';
                                            }
                                            ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="16" class="text-center text-muted">
                                        <i class="fas fa-info-circle me-2"></i>
                                        No consolidated error results found.
                                        <?php if (isset($_SESSION['consolidated_data'])): ?>
                                            <br><small>Session data exists but is empty: <?= count($_SESSION['consolidated_data']) ?> records</small>
                                        <?php else: ?>
                                            <br><small>No session data found</small>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="text-center mt-4">
            <button type="button" class="btn btn-success btn-lg" onclick="clearAndReturn()">
                <i class="fas fa-upload me-2"></i>Upload Different File
            </button>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>

        function clearAndReturn() {
            Swal.fire({
                title: 'Clear Session Data?',
                text: 'This will clear all current session data and return to the upload page.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#28a745',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, clear and return',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    fetch('../clear/clearSession.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                            },
                            body: JSON.stringify({
                                action: 'clear_import_session'
                            })
                        })
                        .then(() => {
                            window.location.href = '../../dashboard/billspayment/import/billspay-transaction.php';
                        });
                }
            });
        }

        function copyRowData(index) {
            const row = document.querySelector(`#consolidatedTable tbody tr:nth-child(${index + 1})`);
            const cells = row.querySelectorAll('td:not(:last-child)');
            const rowData = Array.from(cells).map(cell => cell.textContent.trim()).join('\t');

            navigator.clipboard.writeText(rowData).then(() => {
                Swal.fire({
                    title: 'Copied!',
                    text: 'Row data copied to clipboard',
                    icon: 'success',
                    timer: 1500,
                    showConfirmButton: false
                });
            }).catch(err => {
                console.error('Failed to copy: ', err);
                // Fallback for older browsers
                const textArea = document.createElement('textarea');
                textArea.value = rowData;
                document.body.appendChild(textArea);
                textArea.select();
                document.execCommand('copy');
                document.body.removeChild(textArea);

                Swal.fire({
                    title: 'Copied!',
                    text: 'Row data copied to clipboard',
                    icon: 'success',
                    timer: 1500,
                    showConfirmButton: false
                });
            });
        }

        function copyAllData() {
            const table = document.getElementById('consolidatedTable');
            const rows = table.querySelectorAll('tbody tr');
            const allData = [];

            // Add header (include all columns)
            const headers = Array.from(table.querySelectorAll('thead th')).map(th => th.textContent.trim());
            allData.push(headers.join('\t'));

            // Add data rows (use data-error-remarks for last column, normalize to ;)
            rows.forEach(row => {
                const cells = row.querySelectorAll('td');
                const rowData = Array.from(cells).map((cell, idx) => {
                    if (idx === cells.length - 1) {
                        // Normalize separators to semicolon for Error Remarks
                        let remarks = cell.getAttribute('data-error-remarks') || '';
                        return remarks
                            .split(/[;,]+/)
                            .map(part => part.trim())
                            .filter(Boolean)
                            .join(';');
                    } else {
                        // For all other columns, get text content and remove only ₱ sign (keep number format)
                        let cellText = cell.textContent.trim();
                        
                        // Check if it's a monetary value (contains ₱ symbol)
                        if (cellText.includes('₱')) {
                            // Remove ₱ symbol and spaces
                            let numericValue = cellText.replace(/₱\s*/g, '');
                            // Remove commas for parsing
                            let cleanNumber = numericValue.replace(/,/g, '');
                            // Parse as float and format to 2 decimal places with commas
                            if (!isNaN(parseFloat(cleanNumber))) {
                                return parseFloat(cleanNumber).toLocaleString('en-US', {
                                    minimumFractionDigits: 2,
                                    maximumFractionDigits: 2
                                });
                            }
                        }
                        
                        // For non-monetary values, return as is
                        return cellText;
                    }
                }).join('\t');
                allData.push(rowData);
            });

            const fullData = allData.join('\n');

            navigator.clipboard.writeText(fullData).then(() => {
                Swal.fire({
                    title: 'Copied!',
                    text: 'All table data copied to clipboard',
                    icon: 'success',
                    timer: 1500,
                    showConfirmButton: false
                });
            }).catch(err => {
                console.error('Failed to copy: ', err);
                // Fallback for older browsers
                const textArea = document.createElement('textarea');
                textArea.value = fullData;
                document.body.appendChild(textArea);
                textArea.select();
                document.execCommand('copy');
                document.body.removeChild(textArea);

                Swal.fire({
                    title: 'Copied!',
                    text: 'All table data copied to clipboard',
                    icon: 'success',
                    timer: 1500,
                    showConfirmButton: false
                });
            });
        }

        // Enable text selection on double-click
        document.addEventListener('DOMContentLoaded', function() {
            const tableCells = document.querySelectorAll('.table td, .table th');
            tableCells.forEach(cell => {
                cell.addEventListener('dblclick', function() {
                    const selection = window.getSelection();
                    const range = document.createRange();
                    range.selectNodeContents(this);
                    selection.removeAllRanges();
                    selection.addRange(range);
                });
            });
        });

        // Initialize Bootstrap popovers
        document.addEventListener('DOMContentLoaded', function() {
            var popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
            popoverTriggerList.forEach(function(popoverTriggerEl) {
                new bootstrap.Popover(popoverTriggerEl);
            });
        });

        // Show actual data rows and hide placeholders
        document.addEventListener('DOMContentLoaded', function() {
            const dataRows = document.querySelectorAll('.data-row');
            const tableBody = document.getElementById('tableBody');
            const placeholders = tableBody.querySelectorAll('.loading-row');

            // Initially hide all data rows
            dataRows.forEach(row => {
                row.style.display = 'none';
            });

            // Remove loading placeholders completely instead of just hiding them
            placeholders.forEach(placeholder => {
                placeholder.remove();
            });

            // Show data rows with a delay
            let delay = 0;
            dataRows.forEach(row => {
                setTimeout(() => {
                    row.style.display = '';
                }, delay);
                delay += 50; // Reduced delay for smoother loading
            });
        });
    </script>
</body>

</html>