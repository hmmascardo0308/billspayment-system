<?php
include '../../../config/config.php';
session_start();
include '../../../templates/middleware.php';

$id = resolve_user_identifier();
if (empty($id)) {
    header('Location: ../../../login_form.php');
    exit;
}

if (!function_exists('has_any_permission') || !has_any_permission(['TRL Review', 'Bills Payment'])) {
    header('Location: ../../home.php');
    exit;
}

$searchRef = trim((string) ($_GET['search_ref'] ?? ''));
$refFilter = strtolower(trim((string) ($_GET['ref_filter'] ?? 'all')));
if (!in_array($refFilter, ['all', 'empty'], true)) {
    $refFilter = 'all';
}

function trl_review_normalize_type($value) {
    $type = strtoupper(trim((string) $value));
    return $type !== '' ? $type : 'UNSPECIFIED';
}

$rows = [];
$grouped = [];
$typeLabels = [];
$rowsByNo = [];

// Determine which payment branch column exists on the trl table to avoid
// referencing a non-existent column in the SELECT list (some environments
// use `payment_branch`, others historically used `payment_branch_name`).
$branchColumn = 'payment_branch';
$colCheck = mysqli_query($conn, "SHOW COLUMNS FROM mldb.trl LIKE 'payment_branch'");
if (!$colCheck || mysqli_num_rows($colCheck) === 0) {
    $colCheck2 = mysqli_query($conn, "SHOW COLUMNS FROM mldb.trl LIKE 'payment_branch_name'");
    if ($colCheck2 && mysqli_num_rows($colCheck2) > 0) {
        $branchColumn = 'payment_branch_name';
    } else {
        // No branch name column available; fall back to empty string
        $branchColumn = null;
    }
}

if ($branchColumn !== null) {
    $branchSelect = "t.{$branchColumn} AS payment_branch";
} else {
    $branchSelect = "'' AS payment_branch";
}

$remarksSelect = "'' AS remarks";
$remarksColumnCheck = mysqli_query($conn, "SHOW COLUMNS FROM mldb.trl LIKE 'remarks'");
if ($remarksColumnCheck && mysqli_num_rows($remarksColumnCheck) > 0) {
    $remarksSelect = 't.remarks AS remarks';
}

$sql = "SELECT
            t.trl_no,
            t.transfer_datetime,
            t.ref_no,
            t.wrong_biller_id,
            t.biller_name,
            t.account_no,
            t.name,
            t.payment_branch_id,
            " . $branchSelect . ",
            t.amount,
            t.type_of_request,
            t.reason,
            " . $remarksSelect . ",
            t.status,
            wb.correct_biller_id,
            wb.correct_biller_name,
            oa.wrong_amount AS oa_wrong_amount,
            oa.correct_amount AS oa_correct_amount,
            oa.difference AS oa_difference,
            ct.wrong_amount AS ct_wrong_amount,
            ct.correct_amount AS ct_correct_amount
        FROM mldb.trl t
        LEFT JOIN mldb.trl_wrongbiller wb ON wb.trl_no = t.trl_no
        LEFT JOIN mldb.trl_overstatedamount oa ON oa.trl_no = t.trl_no
        LEFT JOIN mldb.trl_cancelledtransaction ct ON ct.trl_no = t.trl_no
        WHERE t.status IS NULL";

$types = '';
$params = [];

if ($searchRef !== '') {
    $sql .= " AND t.ref_no LIKE ?";
    $types .= 's';
    $params[] = '%' . $searchRef . '%';
}

$sql .= " ORDER BY t.transfer_datetime DESC, t.trl_no DESC, t.type_of_request ASC";

$stmt = $conn->prepare($sql);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $typeNorm = trl_review_normalize_type($row['type_of_request'] ?? '');

            $wrongAmount = null;
            $correctAmount = null;
            $difference = null;

            if ($typeNorm === 'OVERSTATED AMOUNT') {
                $wrongAmount = $row['oa_wrong_amount'];
                $correctAmount = $row['oa_correct_amount'];
                $difference = $row['oa_difference'];
            } elseif ($typeNorm === 'CANCELLED TRANSACTION') {
                $wrongAmount = $row['ct_wrong_amount'];
                $correctAmount = $row['ct_correct_amount'];
            }

            $item = [
                'trl_no' => (int) ($row['trl_no'] ?? 0),
                'transfer_datetime' => (string) ($row['transfer_datetime'] ?? ''),
                'ref_no' => (string) ($row['ref_no'] ?? ''),
                'wrong_biller_id' => (string) ($row['wrong_biller_id'] ?? ''),
                'biller_name' => (string) ($row['biller_name'] ?? ''),
                'account_no' => (string) ($row['account_no'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'payment_branch_id' => (string) ($row['payment_branch_id'] ?? ''),
                'payment_branch' => (string) ($row['payment_branch'] ?? ''),
                'amount' => (float) ($row['amount'] ?? 0),
                'type_of_request' => $typeNorm,
                'correct_biller_id' => (string) ($row['correct_biller_id'] ?? ''),
                'correct_biller_name' => (string) ($row['correct_biller_name'] ?? ''),
                'wrong_amount' => $wrongAmount,
                'correct_amount' => $correctAmount,
                'difference_value' => $difference,
                'reason' => (string) ($row['reason'] ?? ''),
                'remarks' => (string) ($row['remarks'] ?? '')
            ];

            $rows[] = $item;
            if (!isset($grouped[$typeNorm])) {
                $grouped[$typeNorm] = [];
            }
            $grouped[$typeNorm][] = $item;
            if (!isset($typeLabels[$typeNorm])) {
                $typeLabels[$typeNorm] = $typeNorm;
            }
            $rowsByNo[(string) $item['trl_no']] = $item;
        }
    }
    $stmt->close();
}

$processedCount = 0;
if ($searchRef !== '') {
    $statusStmt = $conn->prepare("SELECT SUM(CASE WHEN status = 'REFUNDED' THEN 1 ELSE 0 END) AS processed_count FROM mldb.trl WHERE ref_no = ?");
    if ($statusStmt) {
        $statusStmt->bind_param('s', $searchRef);
        if ($statusStmt->execute()) {
            $statusResult = $statusStmt->get_result();
            if ($statusResult) {
                $statusRow = $statusResult->fetch_assoc();
                $processedCount = (int) ($statusRow['processed_count'] ?? 0);
            }
        }
        $statusStmt->close();
    }
}

$flash = $_SESSION['trl_review_flash'] ?? null;
unset($_SESSION['trl_review_flash']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Transaction Request Log - Review</title>
    <link rel="icon" href="../../../images/MLW%20logo.png" type="image/png">
    <link rel="stylesheet" href="../../../assets/css/templates/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="trl-review.css?v=<?php echo time(); ?>">
</head>
<body>
    <div class="main-container">
        <?php include '../../../templates/header_ui.php'; ?>
        <?php include '../../../templates/sidebar.php'; ?>

        <?php bp_section_header_html('fa-solid fa-clipboard-check', 'Transaction Request Log - Review'); ?>

        <div class="bp-card container-fluid mt-3 p-4 trl-review-wrap">
            <div class="trl-review-topbar">
                <form method="get" class="trl-search-top" autocomplete="off">
                    <div class="trl-field">
                        <label for="searchRefNo">Reference No. Search</label>
                        <div class="trl-search-input-wrap">
                            <input id="searchRefNo" name="search_ref" type="text" placeholder="Enter reference number" value="<?php echo htmlspecialchars($searchRef); ?>">
                            <button type="submit" class="btn btn-danger search-btn">Search</button>
                        </div>
                    </div>
                </form>
            </div>

            <?php if ($flash): ?>
                <div class="trl-alert <?php echo htmlspecialchars((string) ($flash['type'] ?? 'info')); ?>">
                    <?php echo htmlspecialchars((string) ($flash['message'] ?? '')); ?>
                </div>
            <?php endif; ?>

            <section class="trl-summary-grid">
                <div class="summary-card">
                    <span>Total Pending Rows</span>
                    <strong><?php echo count($rows); ?></strong>
                </div>
                <div class="summary-card">
                    <span>Request Type Groups</span>
                    <strong><?php echo count($grouped); ?></strong>
                </div>
            </section>

            <section class="trl-table-section">
                <?php if (empty($rows)): ?>
                    <div class="trl-empty">No pending records found for review.</div>
                <?php else: ?>
                    <div class="trl-filter-bar">
                        <label for="typeFilter">Filter by Request Type</label>
                        <select id="typeFilter">
                            <option value="">All types</option>
                            <?php foreach ($typeLabels as $norm => $lbl): ?>
                                <option value="<?php echo htmlspecialchars($norm); ?>"><?php echo htmlspecialchars($lbl); ?> (<?php echo count($grouped[$norm]); ?>)</option>
                            <?php endforeach; ?>
                        </select>

                        <label for="refFilterBottom">Reference Filter</label>
                        <select id="refFilterBottom">
                            <option value="">All</option>
                            <option value="empty">Empty Reference No.</option>
                        </select>

                        <div class="trl-filter-actions">
                            <button id="toggleExpandAll" class="btn btn-outline-secondary">Collapse All</button>
                        </div>
                    </div>

                    <div id="typeSections">
                        <?php foreach ($grouped as $norm => $grows): ?>
                            <?php
                                $hasCorrect = ($norm === 'WRONG BILLER');
                                $hasReported = ($norm === 'OVERSTATED AMOUNT' || $norm === 'CANCELLED TRANSACTION');
                                $hasActual = ($norm === 'OVERSTATED AMOUNT' || $norm === 'CANCELLED TRANSACTION');
                                $hasDifference = ($norm === 'OVERSTATED AMOUNT');
                            ?>
                            <section class="trl-type-section" data-type="<?php echo htmlspecialchars($norm); ?>" id="section-<?php echo md5($norm); ?>">
                                <header class="trl-type-header">
                                    <div class="ttl">
                                        <?php echo htmlspecialchars($typeLabels[$norm]); ?>
                                        <small>(<?php echo count($grows); ?>)</small>
                                    </div>
                                    <div class="hdr-actions">
                                        <button class="toggle-section btn btn-sm">Collapse</button>
                                    </div>
                                </header>
                                <div class="trl-table-wrap">
                                    <table class="trl-table" data-section-type="<?php echo htmlspecialchars($norm); ?>">
                                        <thead>
                                            <tr>
                                                <th>TRL NO.</th>
                                                <th>TRANS. DATE/TIME</th>
                                                <th>REF. NO.</th>
                                                <th>WRONG BILLER ID</th>
                                                <th>BILLER NAME</th>
                                                <th>ACCOUNT NO.</th>
                                                <th>NAME</th>
                                                <th>PAYMENT BRANCH ID</th>
                                                <th>PAYMENT BRANCH</th>
                                                <th>AMOUNT</th>
                                                <th>TYPE OF REQUEST</th>
                                                <?php if ($hasCorrect): ?>
                                                    <th>CORRECT BILLER ID</th>
                                                    <th>CORRECT BILLER NAME</th>
                                                <?php endif; ?>
                                                <?php if ($hasReported || $hasActual || $hasDifference): ?>
                                                    <?php if ($hasReported): ?><th>WRONG AMOUNT</th><?php endif; ?>
                                                    <?php if ($hasActual): ?><th>CORRECT AMOUNT</th><?php endif; ?>
                                                    <?php if ($hasDifference): ?><th>DIFFERENCE</th><?php endif; ?>
                                                <?php endif; ?>
                                                <th>REASON</th>
                                                <th>REMARKS</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($grows as $row): ?>
                                                <tr class="trl-row-clickable" data-trl-no="<?php echo (int) $row['trl_no']; ?>">
                                                    <td><?php echo (int) ($row['trl_no'] ?? 0); ?></td>
                                                    <td><?php echo htmlspecialchars((string) ($row['transfer_datetime'] ?? '')); ?></td>
                                                    <td><?php echo htmlspecialchars((string) ($row['ref_no'] ?? '')); ?></td>
                                                    <td><?php echo htmlspecialchars((string) ($row['wrong_biller_id'] ?? '')); ?></td>
                                                    <td><?php echo htmlspecialchars((string) ($row['biller_name'] ?? '')); ?></td>
                                                    <td><?php echo htmlspecialchars((string) ($row['account_no'] ?? '')); ?></td>
                                                    <td><?php echo htmlspecialchars((string) ($row['name'] ?? '')); ?></td>
                                                    <td><?php echo htmlspecialchars((string) ($row['payment_branch_id'] ?? '')); ?></td>
                                                    <td><?php echo htmlspecialchars((string) ($row['payment_branch'] ?? '')); ?></td>
                                                    <td class="amount"><?php echo number_format((float) ($row['amount'] ?? 0), 2); ?></td>
                                                    <td><?php echo htmlspecialchars((string) ($row['type_of_request'] ?? '')); ?></td>
                                                    <?php if ($hasCorrect): ?>
                                                        <td><?php echo htmlspecialchars((string) ($row['correct_biller_id'] ?? '')); ?></td>
                                                        <td><?php echo htmlspecialchars((string) ($row['correct_biller_name'] ?? '')); ?></td>
                                                    <?php endif; ?>
                                                    <?php if ($hasReported || $hasActual || $hasDifference): ?>
                                                        <?php if ($hasReported): ?><td><?php echo isset($row['wrong_amount']) && $row['wrong_amount'] !== null ? number_format((float) $row['wrong_amount'], 2) : ''; ?></td><?php endif; ?>
                                                        <?php if ($hasActual): ?><td><?php echo isset($row['correct_amount']) && $row['correct_amount'] !== null ? number_format((float) $row['correct_amount'], 2) : ''; ?></td><?php endif; ?>
                                                        <?php if ($hasDifference): ?><td><?php echo isset($row['difference_value']) && $row['difference_value'] !== null ? number_format((float) $row['difference_value'], 2) : ''; ?></td><?php endif; ?>
                                                    <?php endif; ?>
                                                    <td><?php echo htmlspecialchars((string) ($row['reason'] ?? '')); ?></td>
                                                    <td><?php echo htmlspecialchars((string) ($row['remarks'] ?? '')); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                    <div class="trl-pagination" data-page="1" data-total="<?php echo count($grows); ?>">
                                        <button class="btn btn-sm btn-outline-secondary page-prev" type="button">Prev</button>
                                        <span class="page-info">Page 1</span>
                                        <button class="btn btn-sm btn-outline-secondary page-next" type="button">Next</button>
                                    </div>
                                </div>
                            </section>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <script>
        (function() {
            var rowsByNo = <?php echo json_encode($rowsByNo, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            var processedCount = <?php echo (int) $processedCount; ?>;
            var searchedRef = <?php echo json_encode($searchRef); ?>;

            function escapeHtml(str) {
                if (str == null) return '';
                return String(str)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/\"/g, '&quot;')
                    .replace(/'/g, '&#39;');
            }

            function formatMoney(v) {
                var n = parseFloat(v || 0);
                if (isNaN(n)) return '';
                return Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }

            function buildSummaryCardRows(items) {
                return items.map(function(item) {
                    if (item.value === '' || item.value == null) return '';
                    return '<div class="trl-summary-row">' +
                        '<div class="trl-summary-key">' + escapeHtml(item.label) + '</div>' +
                        '<div class="trl-summary-val">' + escapeHtml(item.value) + '</div>' +
                    '</div>';
                }).filter(Boolean).join('');
            }

            function buildRefundPreviewHtml(row) {
                if (!row) return '<div>No data available</div>';

                var txRows = buildSummaryCardRows([
                    { label: 'TRL NO.', value: row.trl_no },
                    { label: 'REFERENCE NO.', value: row.ref_no },
                    { label: 'TRANSACTION DATE/TIME', value: row.transfer_datetime },
                    { label: 'ACCOUNT NUMBER', value: row.account_no },
                    { label: 'ACCOUNT NAME', value: row.name },
                    { label: 'BRANCH ID', value: row.payment_branch_id },
                    { label: 'PAYMENT BRANCH', value: row.payment_branch },
                    { label: 'BILLER ID', value: row.wrong_biller_id },
                    { label: 'BILLER NAME', value: row.biller_name },
                    { label: 'AMOUNT', value: 'PHP ' + formatMoney(row.amount) }
                ]);

                var rqRows = buildSummaryCardRows([
                    { label: 'TYPE OF REQUEST', value: row.type_of_request },
                    { label: 'WRONG AMOUNT', value: row.wrong_amount == null || row.wrong_amount === '' ? '' : ('PHP ' + formatMoney(row.wrong_amount)) },
                    { label: 'CORRECT AMOUNT', value: row.correct_amount == null || row.correct_amount === '' ? '' : ('PHP ' + formatMoney(row.correct_amount)) },
                    { label: 'DIFFERENCE', value: row.difference_value == null || row.difference_value === '' ? '' : ('PHP ' + formatMoney(row.difference_value)) },
                    { label: 'CORRECT BILLER ID', value: row.correct_biller_id },
                    { label: 'CORRECT BILLER NAME', value: row.correct_biller_name },
                    { label: 'REASON', value: row.reason },
                    { label: 'REMARKS', value: row.remarks }
                ]);

                return '' +
                    '<div class="trl-summary-wrap">' +
                        '<div class="trl-summary-grid-modal">' +
                            '<section class="trl-summary-card trl-transaction-card">' +
                                '<div class="trl-summary-head trl-transaction-head">TRANSACTION DETAILS</div>' +
                                '<div class="trl-summary-body">' + txRows + '</div>' +
                            '</section>' +
                            '<section class="trl-summary-card trl-request-card">' +
                                '<div class="trl-summary-head trl-request-head">REQUEST DETAILS</div>' +
                                '<div class="trl-summary-body">' + rqRows + '</div>' +
                            '</section>' +
                        '</div>' +
                    '</div>';
            }

            function confirmRefund(row) {
                var body = new URLSearchParams();
                body.set('action', 'confirm_refund');
                body.set('trl_no', String(row.trl_no));

                Swal.fire({
                    title: 'Submitting refund...',
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    didOpen: function() { Swal.showLoading(); }
                });

                fetch('controllers/trl-review-insert.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body: body.toString()
                })
                .then(function(res) {
                    if (!res.ok) throw new Error('Network error');
                    return res.json();
                })
                .then(function(data) {
                    Swal.close();
                    if (data && data.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Refund Confirmed',
                            text: data.message || 'The selected row has been marked as refunded.',
                            confirmButtonColor: '#4caf50'
                        }).then(function() {
                            try {
                                // Reload the page but remove search_ref so we don't auto-trigger the processed warning
                                var u = new URL(window.location.href);
                                u.searchParams.delete('search_ref');
                                window.location.href = u.toString();
                            } catch (e) {
                                // fallback
                                window.location.href = 'trl-review.php';
                            }
                        });
                        return;
                    }

                    Swal.fire({
                        icon: 'warning',
                        title: 'Refund Not Applied',
                        text: (data && data.message) ? data.message : 'Unable to complete the refund request.'
                    });
                })
                .catch(function() {
                    Swal.close();
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'An error occurred while confirming refund. Please try again.'
                    });
                });
            }

            function openReviewModal(row) {
                var html = '<p>Please verify this refund preview before confirming refund.</p>' + buildRefundPreviewHtml(row);

                Swal.fire({
                    title: 'Review Refund Request',
                    html: html,
                    width: '980px',
                    customClass: { popup: 'trl-confirm-popup' },
                    showCancelButton: true,
                    confirmButtonText: 'Confirm Refund',
                    cancelButtonText: 'Close',
                    focusConfirm: false
                }).then(function(result) {
                    if (!result.isConfirmed) return;

                    Swal.fire({
                        icon: 'question',
                        title: 'Finalize Refund?',
                        html: 'You are about to mark this record as <b>REFUNDED</b>.<br>Do you want to continue?',
                        showCancelButton: true,
                        confirmButtonText: 'Yes, Confirm Refund',
                        cancelButtonText: 'No, Go Back'
                    }).then(function(next) {
                        if (next.isConfirmed) {
                            confirmRefund(row);
                        }
                    });
                });
            }

            function attachRowClicks() {
                document.querySelectorAll('.trl-row-clickable').forEach(function(rowEl) {
                    rowEl.addEventListener('click', function() {
                        var trlNo = rowEl.getAttribute('data-trl-no');
                        if (!trlNo || !rowsByNo[trlNo]) return;
                        openReviewModal(rowsByNo[trlNo]);
                    });
                });
            }

            function attachTypeFilterAndCollapse() {
                var typeFilter = document.getElementById('typeFilter');
                var sections = document.querySelectorAll('.trl-type-section');
                var toggleAllBtn = document.getElementById('toggleExpandAll');

                function setFilter(value) {
                    sections.forEach(function(sec) {
                        var t = sec.getAttribute('data-type') || '';
                        sec.style.display = (!value || t === value) ? '' : 'none';
                    });
                    // After filtering, update the global toggle label to reflect visible sections
                    updateGlobalToggleLabel();
                }

                if (typeFilter) {
                    typeFilter.addEventListener('change', function(e) {
                        setFilter(e.target.value || '');
                    });
                }

                function updateGlobalToggleLabel() {
                    if (!toggleAllBtn) return;
                    var anyHidden = false;
                    document.querySelectorAll('.trl-type-section .trl-table-wrap').forEach(function(w) {
                        if (getComputedStyle(w).display === 'none') anyHidden = true;
                    });
                    toggleAllBtn.textContent = anyHidden ? 'Expand All' : 'Collapse All';
                }

                // Initialize per-section toggle labels and attach click handlers
                document.querySelectorAll('.toggle-section').forEach(function(btn) {
                    var sec = btn.closest('.trl-type-section');
                    var wrap = sec ? sec.querySelector('.trl-table-wrap') : null;
                    var isHidden = wrap && (wrap.style.display === 'none' || getComputedStyle(wrap).display === 'none');
                    btn.textContent = isHidden ? 'Expand' : 'Collapse';

                    btn.addEventListener('click', function() {
                        if (!sec || !wrap) return;
                        var nowHidden = wrap.style.display === 'none' || getComputedStyle(wrap).display === 'none';
                        wrap.style.display = nowHidden ? '' : 'none';
                        btn.textContent = nowHidden ? 'Collapse' : 'Expand';
                        updateGlobalToggleLabel();
                    });
                });

                // Single toggle button to expand/collapse all sections
                if (toggleAllBtn) {
                    toggleAllBtn.addEventListener('click', function(e) {
                        e.preventDefault();
                        var anyHidden = false;
                        var wraps = document.querySelectorAll('.trl-type-section .trl-table-wrap');
                        wraps.forEach(function(w) { if (getComputedStyle(w).display === 'none') anyHidden = true; });

                        if (anyHidden) {
                            // expand all
                            wraps.forEach(function(w) { w.style.display = ''; });
                            document.querySelectorAll('.toggle-section').forEach(function(b) { b.textContent = 'Collapse'; });
                        } else {
                            // collapse all
                            wraps.forEach(function(w) { w.style.display = 'none'; });
                            document.querySelectorAll('.toggle-section').forEach(function(b) { b.textContent = 'Expand'; });
                        }

                        updateGlobalToggleLabel();
                    });
                }

                // Set initial global toggle label
                updateGlobalToggleLabel();
            }

            function attachPagination() {
                var PAGE_SIZE = 100;

                document.querySelectorAll('.trl-type-section').forEach(function(section) {
                    var tbody = section.querySelector('tbody');
                    var pager = section.querySelector('.trl-pagination');
                    if (!tbody || !pager) return;

                    var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
                    var pageInfo = pager.querySelector('.page-info');
                    var prevBtn = pager.querySelector('.page-prev');
                    var nextBtn = pager.querySelector('.page-next');
                    var currentPage = 1;

                    var refFilterEl = document.getElementById('refFilterBottom');
                    var typeFilterEl = document.getElementById('typeFilter');

                    function getFilteredRows() {
                        var rf = refFilterEl ? (refFilterEl.value || '') : '';
                        return rows.filter(function(r) {
                            var refCell = r.querySelector('td:nth-child(3)');
                            var refText = refCell ? refCell.textContent.trim() : '';
                            if (rf === 'empty') return refText === '';
                            return true;
                        });
                    }

                    function renderPage() {
                        var filtered = getFilteredRows();
                        var total = filtered.length;
                        var totalPages = Math.max(1, Math.ceil(total / PAGE_SIZE));
                        var start = (currentPage - 1) * PAGE_SIZE;
                        var end = start + PAGE_SIZE;

                        // hide all rows then show only the page slice of filtered rows
                        rows.forEach(function(r) { r.style.display = 'none'; });
                        for (var i = start; i < end && i < filtered.length; i++) {
                            filtered[i].style.display = '';
                        }

                        if (pageInfo) {
                            pageInfo.textContent = 'Page ' + currentPage + ' of ' + totalPages + ' (' + total + ' rows)';
                        }
                        if (prevBtn) prevBtn.disabled = currentPage <= 1;
                        if (nextBtn) nextBtn.disabled = currentPage >= totalPages;

                        // hide pager when not needed
                        pager.style.display = (total <= PAGE_SIZE) ? 'none' : '';
                    }

                    // prev/next handlers
                    if (prevBtn) {
                        prevBtn.addEventListener('click', function() {
                            if (currentPage > 1) {
                                currentPage--;
                                renderPage();
                            }
                        });
                    }
                    if (nextBtn) {
                        nextBtn.addEventListener('click', function() {
                            var filtered = getFilteredRows();
                            var total = filtered.length;
                            var totalPages = Math.max(1, Math.ceil(total / PAGE_SIZE));
                            if (currentPage < totalPages) {
                                currentPage++;
                                renderPage();
                            }
                        });
                    }

                    // re-render when reference filter changes
                    if (refFilterEl) {
                        refFilterEl.addEventListener('change', function() {
                            currentPage = 1;
                            renderPage();
                        });
                    }

                    // re-render when type filter changes (so counts/pagers update)
                    if (typeFilterEl) {
                        typeFilterEl.addEventListener('change', function() {
                            currentPage = 1;
                            renderPage();
                        });
                    }

                    renderPage();
                });
            }

            document.addEventListener('DOMContentLoaded', function() {
                attachRowClicks();
                attachTypeFilterAndCollapse();
                attachPagination();

                if (searchedRef && processedCount > 0) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Reference No# ' + escapeHtml(searchedRef),
                        html: 'Has already been refunded.<br>Please contact admin if data was accidentally changed.'
                    });
                }
            });
        })();
        </script>

        <?php include '../../../templates/footer.php'; ?>
    </div>
</body>
</html>
