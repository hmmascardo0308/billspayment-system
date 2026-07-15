<?php
session_start();
include '../config/config.php';

if (!isset($_SESSION['admin_name'])) {
    header('location:../login_form.php');
}

$duplicate_data = $_SESSION['duplicate_data'] ?? [];
$original_file_name = $_SESSION['original_file_name'] ?? 'Unknown File';
$source_file_type = $_SESSION['source_file_type'] ?? 'Unknown';
$transactionDate = $_SESSION['transactionDate'] ?? date('Y-m-d');

// Remove duplicate entries from the duplicate_data array to ensure accurate count
$unique_duplicates = [];
$seen_combinations = [];

foreach ($duplicate_data as $duplicate) {
    // Create a unique key based on datetime, reference_number, and numeric_number
    $unique_key = $duplicate['datetime'] . '_' . $duplicate['reference_number'];
    
    if (!isset($seen_combinations[$unique_key])) {
        $seen_combinations[$unique_key] = true;
        $unique_duplicates[] = $duplicate;
    }
}

// Update the duplicate_data with unique entries only
$duplicate_data = $unique_duplicates;
$_SESSION['duplicate_data'] = $duplicate_data; // Update session with cleaned data
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Duplicate Transaction Error</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.all.min.js"></script>
    <style>
        /* Ensure text selection is enabled */
        .table, .table td, .table th {
            user-select: text !important;
            -webkit-user-select: text !important;
            -moz-user-select: text !important;
            -ms-user-select: text !important;
        }
        
        /* Highlight selected text */
        .table td::selection, .table th::selection {
            background-color: #007bff;
            color: white;
        }
        
        .table td::-moz-selection, .table th::-moz-selection {
            background-color: #007bff;
            color: white;
        }
        
        /* Make table cells more selectable */
        .table td, .table th {
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
    </style>
</head>
<body>
    <div class="container-fluid py-4">
        <div role="alert">
            <div class="d-flex align-items-center mb-3">
                <i class="fas fa-exclamation-triangle fa-3x text-danger me-3"></i>
                <div>
                    <h4 class="alert-heading mb-1">Duplicate Transaction Detected!</h4>
                    <p class="mb-0">The following transactions already exist in the database with 'posted' status and cannot be imported again.</p>
                </div>
            </div>
            
            <hr class="my-3">
            
            <div class="row mb-3">
                <div class="col-md-6">
                    <p><strong><i class="fas fa-file text-primary me-2"></i>Filename:</strong> <?php echo htmlspecialchars($original_file_name); ?></p>
                    <p><strong><i class="fas fa-code-branch text-info me-2"></i>Source:</strong> <?php echo htmlspecialchars($source_file_type); ?> System</p>
                </div>
                <div class="col-md-6">
                    <p><strong><i class="fas fa-calendar text-success me-2"></i>Upload Date:</strong> <?php echo htmlspecialchars(date('F d, Y')); ?></p>
                    <p><strong><i class="fas fa-list-ol text-danger me-2"></i>Duplicate Records:</strong> <?php echo count($duplicate_data); ?></p>
                </div>
            </div>
        </div>

        <div class="card shadow">
            <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-table me-2"></i>Duplicate Transaction Details</h5>
                <div>
                    <button type="button" class="btn btn-light btn-sm" onclick="copyAllData()" title="Copy all data">
                        <i class="fas fa-copy me-1"></i>Copy All Data
                    </button>
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                    <table class="table table-striped table-hover" id="duplicateTable">
                        <thead class="table-dark sticky-top">
                            <tr>
                                <th scope="col">#</th>
                                <th scope="col">Row in Excel</th>
                                <th scope="col">Status</th>
                                <!-- <th scope="col">Transaction Number</th> -->
                                <th scope="col">Reference Number</th>
                                <th scope="col">DateTime</th>
                                <th scope="col">Payor Name</th>
                                <th scope="col">Amount Paid</th>
                                <th scope="col">Charge to Customer</th>
                                <th scope="col">Charge to Partner</th>
                                <th scope="col">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($duplicate_data)): ?>
                                <tr>
                                    <td colspan="9" class="text-center text-muted">No duplicate transactions found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($duplicate_data as $index => $duplicate): ?>
                                <tr>
                                    <td><?php echo $index + 1; ?></td>
                                    <td><span class="badge bg-info"><?php echo htmlspecialchars($duplicate['row']); ?></span></td>
                                    <td>
                                        <?php if ($duplicate['is_cancellation']): ?>
                                            <span class="badge bg-warning text-dark">Cancelled</span>
                                        <?php else: ?>
                                            <span class="badge bg-success">Regular</span>
                                        <?php endif; ?>
                                    </td>
                                    <!-- <td><code class="user-select-all"><?php //echo htmlspecialchars($duplicate['numeric_number']); ?></code></td> -->
                                    <td class="user-select-all"><?php echo htmlspecialchars($duplicate['reference_number']); ?></td>
                                    <td class="user-select-all"><?php echo htmlspecialchars($duplicate['datetime']); ?></td>
                                    <td class="user-select-all"><?php echo htmlspecialchars($duplicate['payor_name']); ?></td>
                                    <td class="text-end user-select-all">₱ <?php echo number_format($duplicate['amount_paid'], 2); ?></td>
                                    <td class="text-end user-select-all">₱ <?php echo number_format($duplicate['amount_charge_customer'], 2); ?></td>
                                    <td class="text-end user-select-all">₱ <?php echo number_format($duplicate['amount_charge_partner'], 2); ?></td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-outline-primary copy-btn" 
                                                onclick="copyRowData(<?php echo $index; ?>)" 
                                                title="Copy row data">
                                            <i class="fas fa-copy"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
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

    <script>

        function goBack() {
            window.location.href = 'billspaymentImportFile.php';
        }

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
                    fetch('clearSession.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({action: 'clear_import_session'})
                    })
                    .then(() => {
                        window.location.href = 'billspaymentImportFile.php';
                    });
                }
            });
        }

        function copyRowData(index) {
            const row = document.querySelector(`#duplicateTable tbody tr:nth-child(${index + 1})`);
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
            const table = document.getElementById('duplicateTable');
            const rows = table.querySelectorAll('tbody tr');
            const allData = [];
            
            // Add header
            const headers = Array.from(table.querySelectorAll('thead th:not(:last-child)')).map(th => th.textContent.trim());
            allData.push(headers.join('\t'));
            
            // Add data rows
            rows.forEach(row => {
                const cells = row.querySelectorAll('td:not(:last-child)');
                const rowData = Array.from(cells).map(cell => cell.textContent.trim()).join('\t');
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
    </script>
</body>
</html>