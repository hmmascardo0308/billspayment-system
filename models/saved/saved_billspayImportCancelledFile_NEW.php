<?php
require_once __DIR__ . '/../../config/config.php';
require '../../vendor/autoload.php';
session_start();

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as PhpSpreadsheetDate;

/**
 * Resolve a partner search key (G3) to actual partner identifiers.
 * Priority: partner_id_kpx -> partner_id -> partner_name match.
 * Returns ['partner_id'=>string|null,'partner_id_kpx'=>string|null,'partner_name'=>string|null,'gl_code'=>string|null]
 */
function resolvePartnerRecord($conn, $search)
{
    $out = ['partner_id' => null, 'partner_id_kpx' => null, 'partner_name' => null];
    if (empty($search)) return $out;

    // try partner_id_kpx first
    $sql = "SELECT partner_id, partner_id_kpx, partner_name, gl_code FROM masterdata.partner_masterfile WHERE partner_id_kpx = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('s', $search);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows > 0) {
            $row = $res->fetch_assoc();
            $out['partner_id'] = $row['partner_id'] ?? null;
            $out['partner_id_kpx'] = $row['partner_id_kpx'] ?? null;
            $out['partner_name'] = $row['partner_name'] ?? null;
            $out['gl_code'] = $row['gl_code'] ?? null;
            $stmt->close();
            return $out;
        }
        $stmt->close();
    }

    // try partner_id
    $sql = "SELECT partner_id, partner_id_kpx, partner_name, gl_code FROM masterdata.partner_masterfile WHERE partner_id = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('s', $search);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows > 0) {
            $row = $res->fetch_assoc();
            $out['partner_id'] = $row['partner_id'] ?? null;
            $out['partner_id_kpx'] = $row['partner_id_kpx'] ?? null;
            $out['partner_name'] = $row['partner_name'] ?? null;
            $out['gl_code'] = $row['gl_code'] ?? null;
            $stmt->close();
            return $out;
        }
        $stmt->close();
    }

    // lastly try partner_name (case-insensitive)
    $sql = "SELECT partner_id, partner_id_kpx, partner_name, gl_code FROM masterdata.partner_masterfile WHERE LOWER(partner_name) = LOWER(?) LIMIT 1";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('s', $search);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows > 0) {
            $row = $res->fetch_assoc();
            $out['partner_id'] = $row['partner_id'] ?? null;
            $out['partner_id_kpx'] = $row['partner_id_kpx'] ?? null;
            $out['partner_name'] = $row['partner_name'] ?? null;
            $out['gl_code'] = $row['gl_code'] ?? null;
            $stmt->close();
            return $out;
        }
        $stmt->close();
    }

    return $out;
}

function c_parse_datetime($value)
{
    if ($value === null) return '';
    if (is_numeric($value)) {
        try {
            return PhpSpreadsheetDate::excelToDateTimeObject($value)->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            return '';
        }
    }

    $text = trim((string)$value);
    if ($text === '') return '';
    $ts = strtotime($text);
    return $ts ? date('Y-m-d H:i:s', $ts) : '';
}

function c_parse_amount($value)
{
    $text = trim((string)$value);
    if ($text === '') return 0.0;
    $negative = false;
    if (preg_match('/^\(.*\)$/', $text)) {
        $negative = true;
        $text = str_replace(['(', ')'], '', $text);
    }
    $text = preg_replace('/[^0-9.\-]/', '', $text);
    $num = is_numeric($text) ? floatval($text) : 0.0;
    return $negative ? ($num * -1) : $num;
}

function c_validate_file($conn, $file)
{
    $filePath = $file['path'] ?? '';
    $fileName = $file['name'] ?? '';
    $partnerId = $file['partner_id'] ?? '';
    // resolve partner search key (G3) to actual partner ids; do not insert raw G3 into partner_id
    $resolvedPartner = resolvePartnerRecord($conn, $partnerId);
    $p_resolved_id = $resolvedPartner['partner_id'] ?? null;
    $p_resolved_kpx = $resolvedPartner['partner_id_kpx'] ?? null;
    $p_resolved_name = $resolvedPartner['partner_name'] ?? null;
    $p_resolved_gl = $resolvedPartner['gl_code'] ?? null;
    $sourceType = strtoupper($file['source_type'] ?? 'KPX');

    $result = [
        'valid' => true,
        'row_count' => 0,
        'errors' => [],
        'warnings' => [],
        'preview_data' => [],
        'transaction_summary' => [
            'summary' => [
                'count' => 0,
                'principal' => 0,
                'charge_partner' => 0,
                'charge_customer' => 0,
                'total_charge' => 0
            ]
        ],
        'duplicate_rows' => 0,
        'new_rows' => 0,
        'posted_rows' => 0,
        'unposted_rows' => 0,
        'source_type' => $sourceType
    ];

    if (!$filePath || !file_exists($filePath)) {
        $result['valid'] = false;
        $result['errors'][] = ['row' => 'N/A', 'message' => 'File missing on disk'];
        return $result;
    }

    try {
        $spreadsheet = IOFactory::load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();

        // Extract report date from cell A3 if present. Expect formats like:
        // "REPORT DATE :\t JANUARY 05 2026" or simply "JANUARY 05 2026"
        $rawA3 = trim((string)$worksheet->getCell('A3')->getValue());
        $report_date_raw = '';
        $report_date = null;
        if ($rawA3 !== '') {
            if (preg_match('/([A-Za-z]+\s+\d{1,2}\s+\d{4})/i', $rawA3, $m)) {
                $report_date_raw = trim($m[1]);
                $ts = strtotime($report_date_raw);
                if ($ts !== false) {
                    $report_date = date('Y-m-d', $ts);
                }
            } else {
                // fallback: try direct parse of entire A3
                $ts = strtotime($rawA3);
                if ($ts !== false) {
                    $report_date_raw = trim($rawA3);
                    $report_date = date('Y-m-d', $ts);
                }
            }
        }
        $highestRow = $worksheet->getHighestRow();

        for ($row = 7; $row <= $highestRow; $row++) {
            $cellB = trim((string)$worksheet->getCell('B' . $row)->getValue());
            $cellD = trim((string)$worksheet->getCell('D' . $row)->getValue());
            if ($cellB === '' && $cellD === '') break;

            $result['row_count']++;

            $datetime = c_parse_datetime($worksheet->getCell('B' . $row)->getValue());
            $referenceNo = trim((string)$worksheet->getCell('D' . $row)->getValue());
            $controlNo = trim((string)$worksheet->getCell('E' . $row)->getValue());
            $accountNo = trim((string)$worksheet->getCell('F' . $row)->getValue());
            $accountName = trim((string)$worksheet->getCell('G' . $row)->getValue());
            $payor = trim((string)$worksheet->getCell('H' . $row)->getValue());

            $principal = c_parse_amount($worksheet->getCell('J' . $row)->getValue());
            $chargeCustomer = c_parse_amount($worksheet->getCell('L' . $row)->getValue());
            $chargePartner = c_parse_amount($worksheet->getCell('M' . $row)->getValue());
            $cancelCharge = c_parse_amount($worksheet->getCell('K' . $row)->getValue());

            $result['transaction_summary']['summary']['count']++;
            $result['transaction_summary']['summary']['principal'] += abs($principal);
            $result['transaction_summary']['summary']['charge_partner'] += abs($chargePartner);
            $result['transaction_summary']['summary']['charge_customer'] += abs($chargeCustomer);
            $result['transaction_summary']['summary']['total_charge'] += abs($cancelCharge + $chargeCustomer + $chargePartner);

            if ($result['row_count'] <= 20) {
                $result['preview_data'][] = [
                    'datetime' => $datetime,
                    'reference_no' => $referenceNo,
                    'control_no' => $controlNo,
                    'account_no' => $accountNo,
                    'account_name' => $accountName,
                    'payor' => $payor,
                    'principal' => $principal,
                    'charge_customer' => $chargeCustomer,
                    'charge_partner' => $chargePartner,
                    'cancellation_charge' => $cancelCharge,
                    'branch_name' => trim((string)$worksheet->getCell('O' . $row)->getValue())
                ];
            }

            if ($referenceNo === '' || $datetime === '') {
                $result['new_rows']++;
                continue;
            }

            $sql = "SELECT post_transaction, COUNT(*) as cnt
                    FROM mldb.billspayment_transaction
                    WHERE reference_no = ? AND (`datetime` = ? OR cancellation_date = ?)";

            if (!empty($partnerId) && strtoupper($partnerId) !== 'ALL') {
                if (!empty($p_resolved_id) || !empty($p_resolved_kpx)) {
                    $sql .= " AND (partner_id = ? OR partner_id_kpx = ?) GROUP BY post_transaction";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param('sssss', $referenceNo, $datetime, $datetime, $p_resolved_id, $p_resolved_kpx);
                } else {
                    // partner search provided but not resolved; do not apply partner filter
                    $sql .= " GROUP BY post_transaction";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param('sss', $referenceNo, $datetime, $datetime);
                }
            } else {
                $sql .= " GROUP BY post_transaction";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('sss', $referenceNo, $datetime, $datetime);
            }

            $stmt->execute();
            $res = $stmt->get_result();
            $found = 0;
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $cnt = intval($r['cnt'] ?? 0);
                    $found += $cnt;
                    $status = strtolower(trim((string)($r['post_transaction'] ?? '')));
                    if ($status === 'posted') $result['posted_rows'] += $cnt;
                    else $result['unposted_rows'] += $cnt;
                }
            }
            $stmt->close();

            if ($found > 0) $result['duplicate_rows']++;
            else $result['new_rows']++;
        }

        // attach report date info to the result for UI and downstream import
        $result['report_date_raw'] = $report_date_raw;
        $result['report_date'] = $report_date; // Y-m-d or null

        if ($result['row_count'] === 0) {
            $result['valid'] = false;
            $result['errors'][] = ['row' => 'N/A', 'message' => 'No data found (rows start at 7)'];
        }

        if (isset($spreadsheet) && is_object($spreadsheet)) {
            try { $spreadsheet->disconnectWorksheets(); } catch (Exception $e) {}
            unset($worksheet, $spreadsheet);
        }
    } catch (Exception $e) {
        $result['valid'] = false;
        $result['errors'][] = ['row' => 'N/A', 'message' => $e->getMessage()];
    }

    return $result;
}

if (!isset($_SESSION['uploaded_files']) || !is_array($_SESSION['uploaded_files']) || empty($_SESSION['uploaded_files'])) {
    ?>
    <!doctype html>
    <html><head><meta charset="utf-8"><title>No files uploaded</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    </head><body>
    <div class="container py-5">
      <div class="alert alert-info text-center">
        <h4>No files uploaded</h4>
        <p>Please go back to the upload page and select files to import.</p>
        <a href="../../dashboard/billspayment/import/billspay-cancellation.php" class="btn btn-primary">Go to Upload Page</a>
      </div>
    </div>
    </body></html>
    <?php
    exit;
}

$renderFiles = $_SESSION['uploaded_files'];
$validCount = 0;
foreach ($renderFiles as &$file) {
    $validationResult = c_validate_file($conn, $file);
    $file['validation_result'] = $validationResult;
    $file['status'] = $validationResult['valid'] ? 'valid' : 'invalid';
    if ($file['status'] === 'valid') $validCount++;
}
unset($file);
$_SESSION['uploaded_files'] = $renderFiles;
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Cancellation Batch Validator</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://kit.fontawesome.com/30b908cc5a.js" crossorigin="anonymous"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
.validation-container { display:grid; grid-template-columns:repeat(auto-fill,minmax(450px,1fr)); gap:20px; margin:30px 0; }
.file-validation-card { border:2px solid #dee2e6; border-radius:10px; padding:20px; background:#fff; box-shadow:0 2px 8px rgba(0,0,0,0.1); transition:all .3s ease; }
.file-validation-card:hover { box-shadow:0 4px 12px rgba(0,0,0,0.15); }
.file-validation-card.valid { border-color:#28a745; background-color:#f0fff4; }
.file-validation-card.invalid { border-color:#dc3545; background-color:#fff5f5; }
.status-badge { display:inline-block; padding:6px 14px; border-radius:20px; font-size:12px; font-weight:700; text-transform:uppercase; }
.status-valid { background:#28a745; color:#fff; }
.status-invalid { background:#dc3545; color:#fff; }
.badge-source { padding:4px 10px; border-radius:4px; font-size:13px; font-weight:600; }
.badge-kpx { background-color:#0d6efd; color:#fff; }
.badge-kp7 { background-color:#198754; color:#fff; }
.swal-wide { width:90% !important; max-width:1400px !important; }
</style>
</head>
<body>
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center">
        <h3>Cancellation File Validation</h3>
        <div>
            <a href="../../dashboard/billspayment/import/billspay-cancellation.php" class="btn btn-secondary btn-sm">
                <i class="fa-solid fa-arrow-left"></i> Back to Upload
            </a>
            <?php if ($validCount > 0): ?>
                <button id="confirmImport" class="btn btn-success btn-sm">
                    <i class="fa-solid fa-file-import"></i> <?php echo $validCount === 1 ? 'Import File' : 'Import All (' . $validCount . ')'; ?>
                </button>
            <?php endif; ?>
        </div>
    </div>

    <div class="d-flex justify-content-end mb-2">
        <div style="min-width:220px; text-align:right;">
            <label for="fileFilter" class="me-2" style="font-weight:600;">Show:</label>
            <select id="fileFilter" class="form-select form-select-sm" style="display:inline-block; width:160px;">
                <option value="all">All</option>
                <option value="invalid">Invalid</option>
                <option value="valid">Valid</option>
            </select>
        </div>
    </div>

    <div class="validation-container">
        <?php foreach ($renderFiles as $file): ?>
            <div class="file-validation-card <?php echo htmlspecialchars($file['status']); ?>" data-status="<?php echo htmlspecialchars($file['status']); ?>" data-name="<?php echo htmlspecialchars($file['name']); ?>">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div>
                        <h5 class="mb-1"><?php echo htmlspecialchars($file['name']); ?></h5>
                        <small class="text-muted"><?php echo htmlspecialchars($file['partner_name'] ?? 'Unknown Partner'); ?></small>
                    </div>
                    <span class="status-badge status-<?php echo htmlspecialchars($file['status']); ?>"><?php echo htmlspecialchars($file['status']); ?></span>
                </div>

                <div class="mb-3">
                    <div class="row">
                        <div class="col-6">
                            <small class="text-muted d-block">Partner ID</small>
                            <strong><?php echo htmlspecialchars($file['partner_id'] ?? ''); ?></strong>
                        </div>
                        <div class="col-6">
                            <small class="text-muted d-block">Source Type</small>
                            <span class="badge-source badge-<?php echo strtolower($file['source_type'] ?? 'kpx'); ?>"><?php echo htmlspecialchars($file['source_type'] ?? 'KPX'); ?></span>
                        </div>
                    </div>
                </div>

                <div class="mb-2">
                    <small class="text-muted">Rows Found:</small>
                    <strong><?php echo intval($file['validation_result']['row_count'] ?? 0); ?></strong>
                </div>

                <div class="btn-group mt-2" role="group">
                    <button class="btn btn-sm btn-info" onclick="viewDetails('<?php echo htmlspecialchars($file['id']); ?>')">
                        <i class="fa-solid fa-eye"></i> View Details
                    </button>
                    <button class="btn btn-sm btn-success" onclick="viewTransactionSummary('<?php echo htmlspecialchars($file['id']); ?>')">
                        <i class="fa-solid fa-chart-bar"></i> Transaction Summary
                    </button>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
const filesData = <?php echo json_encode($renderFiles); ?>;

function formatStatusLabel(s) {
    if (!s) return 'Pending';
    return s.toString().replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
}

function formatPHP(n) {
    return '₱ ' + (parseFloat(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
}

document.addEventListener('DOMContentLoaded', function() {
    const filterEl = document.getElementById('fileFilter');
    if (!filterEl) return;
    filterEl.addEventListener('change', function(e) {
        const val = e.target.value;
        document.querySelectorAll('.file-validation-card').forEach(function(card) {
            const status = (card.dataset.status || '').toLowerCase();
            card.style.display = (val === 'all' || status === val) ? '' : 'none';
        });
    });

    const confirmBtn = document.getElementById('confirmImport');
    if (confirmBtn) {
        confirmBtn.addEventListener('click', function() {
            Swal.fire({
                title: 'Confirm Import',
                text: 'Proceed importing all valid cancellation files?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Import'
            }).then(function(res) {
                if (!res.isConfirmed) return;
                confirmBtn.disabled = true;
                confirmBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Importing...';

                $.ajax({
                    url: 'saved_billspayImportCancelledFile.php',
                    method: 'POST',
                    data: { perform_import: 1, is_ajax: 1 },
                    dataType: 'json'
                }).done(function(resp) {
                    confirmBtn.disabled = false;
                    confirmBtn.innerHTML = '<i class="fa-solid fa-file-import"></i> Confirm Import';
                    if (resp && resp.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Import Complete',
                            text: 'Imported: ' + (resp.imported || 0) + ' | Failed: ' + (resp.failed || 0)
                        }).then(function() {
                            window.location.href = '../../dashboard/billspayment/import/billspay-cancellation.php';
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Import Error',
                            text: (resp && resp.errors) ? resp.errors.join(' | ') : 'Unknown error'
                        });
                    }
                }).fail(function(xhr, status, err) {
                    confirmBtn.disabled = false;
                    confirmBtn.innerHTML = '<i class="fa-solid fa-file-import"></i> Confirm Import';
                    Swal.fire({ icon: 'error', title: 'Request Failed', text: err || status });
                });
            });
        });
    }
});

function viewDetails(fileId) {
    const fileData = filesData.find(f => String(f.id) === String(fileId));
    if (!fileData || !fileData.validation_result) {
        Swal.fire({ icon: 'error', title: 'Error', text: 'Validation data not available' });
        return;
    }

    const vr = fileData.validation_result;
    const preview = vr.preview_data || [];

    let html = `
        <div style="text-align:left; max-height:600px; overflow-y:auto;">
            <div class="mb-3">
                <h5>File Information</h5>
                <table class="table table-sm table-bordered">
                    <tr><th width="30%">File Name</th><td>${fileData.name || ''}</td></tr>
                    <tr><th>Partner</th><td>${fileData.partner_name || ''}</td></tr>
                    <tr><th>Partner ID</th><td>${fileData.partner_id || ''}</td></tr>
                    <tr><th>Report Date</th><td>${fileData.validation_result && (fileData.validation_result.report_date ? fileData.validation_result.report_date : (fileData.validation_result.report_date_raw || '')) || ''}</td></tr>
                    <tr><th>Source Type</th><td>${fileData.source_type || ''}</td></tr>
                    <tr><th>Total Rows</th><td>${vr.row_count || 0}</td></tr>
                    <tr><th>Status</th><td>${formatStatusLabel(fileData.status || 'pending')}</td></tr>
                </table>
            </div>`;

    if (vr.errors && vr.errors.length > 0) {
        html += '<div class="alert alert-danger"><ul class="mb-0">';
        vr.errors.forEach(e => { html += `<li>${e.message || ''}</li>`; });
        html += '</ul></div>';
    }

    html += `
        <div class="mb-3">
            <h5>Data Preview (first 20 rows)</h5>
            <div style="overflow:auto; max-height:400px;">
                <table class="table table-sm table-striped table-bordered">
                    <thead class="table-dark">
                        <tr>
                            <th>Datetime</th>
                            <th>Reference #</th>
                            <th>Control #</th>
                            <th>Account #</th>
                            <th>Account Name</th>
                            <th>Payor</th>
                            <th>Principal</th>
                            <th>Charge (Cust)</th>
                            <th>Charge (Partner)</th>
                            <th>Cancellation Charge</th>
                            <th>Branch</th>
                        </tr>
                    </thead>
                    <tbody>`;

    if (preview.length === 0) {
        html += '<tr><td colspan="11" class="text-center text-muted">No preview data available</td></tr>';
    } else {
        preview.forEach(r => {
            html += `<tr>
                <td>${r.datetime || ''}</td>
                <td>${r.reference_no || ''}</td>
                <td>${r.control_no || ''}</td>
                <td>${r.account_no || ''}</td>
                <td>${r.account_name || ''}</td>
                <td>${r.payor || ''}</td>
                <td>${formatPHP(r.principal)}</td>
                <td>${formatPHP(r.charge_customer)}</td>
                <td>${formatPHP(r.charge_partner)}</td>
                <td>${formatPHP(r.cancellation_charge)}</td>
                <td>${r.branch_name || ''}</td>
            </tr>`;
        });
    }

    html += '</tbody></table></div></div></div>';

    Swal.fire({
        title: '<strong>File Details: ' + (fileData.name || '') + '</strong>',
        html: html,
        width: '95%',
        showCloseButton: true,
        confirmButtonText: 'Close',
        customClass: { container: 'swal-wide' }
    });
}

function viewTransactionSummary(fileId) {
    const fileData = filesData.find(f => String(f.id) === String(fileId));
    if (!fileData || !fileData.validation_result || !fileData.validation_result.transaction_summary) {
        Swal.fire({ icon: 'error', title: 'Error', text: 'Transaction summary not available' });
        return;
    }

    const s = fileData.validation_result.transaction_summary.summary || {};

    const html = `
        <div style="text-align:left; max-height:650px; overflow-y:auto;">
            <div class="mb-4" style="background:linear-gradient(135deg, #dc3545 0%, #c82333 100%); padding:15px; border-radius:10px;">
                <h4 class="text-white text-center mb-0"><i class="fa-solid fa-chart-line"></i> Cancellation Summary</h4>
            </div>

            <table class="table table-bordered table-sm">
                <tbody>
                    <tr><th width="40%">TOTAL COUNT</th><td><strong>${s.count || 0}</strong></td></tr>
                    <tr><th>TOTAL PRINCIPAL</th><td><strong>${formatPHP(s.principal)}</strong></td></tr>
                    <tr><th>CHARGE TO PARTNER</th><td><strong>${formatPHP(s.charge_partner)}</strong></td></tr>
                    <tr><th>CHARGE TO CUSTOMER</th><td><strong>${formatPHP(s.charge_customer)}</strong></td></tr>
                    <tr><th>TOTAL CHARGE</th><td><strong>${formatPHP(s.total_charge)}</strong></td></tr>
                </tbody>
            </table>

            <div class="mt-3 alert alert-light border">
                <div><strong>File:</strong> ${fileData.name || ''}</div>
                <div><strong>Partner:</strong> ${fileData.partner_name || ''} (${fileData.partner_id || ''})</div>
                <div><strong>Source Type:</strong> ${fileData.source_type || ''}</div>
                <div><strong>Report Date:</strong> ${fileData.validation_result && (fileData.validation_result.report_date ? fileData.validation_result.report_date : (fileData.validation_result.report_date_raw || fileData.report_date || fileData.report_date_raw || '')) || ''}</div>
                <div><strong>Duplicate Rows:</strong> ${fileData.validation_result.duplicate_rows || 0}</div>
                <div><strong>New Rows:</strong> ${fileData.validation_result.new_rows || 0}</div>
            </div>
        </div>`;

    Swal.fire({
        title: '<strong><i class="fa-solid fa-file-invoice"></i> Transaction Summary: ' + (fileData.name || '') + '</strong>',
        html: html,
        width: '80%',
        showCloseButton: true,
        confirmButtonText: 'Close',
        customClass: { container: 'swal-wide' }
    });
}
</script>
</body>
</html>
