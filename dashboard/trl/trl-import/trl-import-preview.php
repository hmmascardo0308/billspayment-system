<?php
include '../../../config/config.php';
session_start();
include '../../../templates/middleware.php';

$id = resolve_user_identifier();
if (empty($id)) {
    header('Location: ../../../login_form.php');
    exit;
}

if (!function_exists('has_any_permission') || !has_any_permission(['TRL Import', 'Bills Payment'])) {
    header('Location: ../../home.php');
    exit;
}

$rows = $_SESSION['trl_import_rows'] ?? [];
$summary = $_SESSION['trl_import_summary'] ?? ['total_rows' => 0, 'duplicate_rows' => 0, 'unique_rows' => 0];
$flash = $_SESSION['trl_import_flash'] ?? null;
$duplicateList = $_SESSION['trl_import_duplicate_result']['duplicates'] ?? [];
$importedRows = (int) (($flash['rows'] ?? 0));
unset($_SESSION['trl_import_flash']);

function trl_preview_normalize_type($value) {
    $type = strtoupper(trim((string) $value));
    return $type !== '' ? $type : 'UNSPECIFIED';
}

// Group rows by normalized request type for preview sections
$grouped = [];
$typeLabels = [];
if (!empty($rows) && is_array($rows)) {
    foreach ($rows as $r) {
        $norm = trl_preview_normalize_type($r['type_of_request'] ?? '');
        if (!isset($grouped[$norm])) $grouped[$norm] = [];
        $grouped[$norm][] = $r;
        if (!isset($typeLabels[$norm])) $typeLabels[$norm] = $norm;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>TRL Import Preview</title>
    <link rel="icon" href="../../../images/MLW%20logo.png" type="image/png">
    <link rel="stylesheet" href="../../../assets/css/templates/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="trl-import-preview.css?v=<?php echo time(); ?>">
</head>
<body>
    <div class="main-container trl-preview-page">
        <?php include '../../../templates/header_ui.php'; ?>

        <main class="trl-preview-container">
            <div class="trl-preview-header">
                <div>
                    <h1>TRL Import Preview</h1>
                    <p>Review fetched rows before importing to mldb.trl.</p>
                </div>
                <div class="trl-preview-actions">
                    <a id="backToImport" class="btn btn-outline-secondary" href="trl-import.php">Back to Import</a>
                    <?php if (!empty($rows)): ?>
                    <form method="post" action="controllers/trl-import-insert.php" style="display:inline;">
                        <button type="submit" class="btn btn-danger">Import All</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($flash && (($flash['type'] ?? '') !== 'success')): ?>
            <div class="trl-alert <?php echo htmlspecialchars($flash['type'] ?? 'info'); ?>">
                <?php echo htmlspecialchars($flash['message'] ?? ''); ?>
            </div>
            <?php endif; ?>

            <section class="trl-summary-grid">
                <div class="summary-card">
                    <span>Total Rows</span>
                    <strong><?php echo (int) ($summary['total_rows'] ?? 0); ?></strong>
                </div>
                <div class="summary-card">
                    <span>Duplicate Rows</span>
                    <strong><?php echo (int) ($summary['duplicate_rows'] ?? 0); ?></strong>
                </div>
                <div class="summary-card">
                    <span>Unique Rows</span>
                    <strong><?php echo (int) ($summary['unique_rows'] ?? 0); ?></strong>
                </div>
            </section>

            <section class="trl-table-section">
                <?php if (empty($rows)): ?>
                    <div class="trl-empty">No fetched rows found. Upload a file in TRL Import first.</div>
                <?php else: ?>
                    <div class="trl-filter-bar" style="margin:12px 0 18px;display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
                        <label for="typeFilter" style="font-weight:600;margin-right:6px;">Filter by Request Type</label>
                        <select id="typeFilter" style="min-width:220px;padding:6px;border:1px solid #d1d5db;border-radius:4px;">
                            <option value="">All types</option>
                            <?php foreach ($typeLabels as $norm => $lbl): ?>
                                <option value="<?php echo htmlspecialchars($norm); ?>"><?php echo htmlspecialchars($lbl); ?> (<?php echo count($grouped[$norm]); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <div style="margin-left:auto;display:flex;gap:8px;">
                            <button id="expandAll" class="btn btn-outline-secondary">Expand All</button>
                            <button id="collapseAll" class="btn btn-outline-secondary">Collapse All</button>
                        </div>
                    </div>

                    <div id="typeSections">
                        <?php foreach ($grouped as $norm => $grows): ?>
                            <?php
                                // Supplemental columns are determined by request type ownership.
                                $hasCorrect = ($norm === 'WRONG BILLER');
                                $hasReported = ($norm === 'OVERSTATED AMOUNT' || $norm === 'CANCELLED TRANSACTION');
                                $hasActual = ($norm === 'OVERSTATED AMOUNT' || $norm === 'CANCELLED TRANSACTION');
                                $hasDifference = ($norm === 'OVERSTATED AMOUNT');

                                $sectionId = 'section-' . md5($norm);
                            ?>
                            <section class="trl-type-section" data-type="<?php echo htmlspecialchars($norm); ?>" id="<?php echo $sectionId; ?>">
                                <header class="trl-type-header" style="display:flex;align-items:center;justify-content:space-between;padding:8px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;margin-bottom:8px;">
                                    <div style="font-weight:700;">
                                        <?php echo htmlspecialchars($typeLabels[$norm]); ?> <small style="font-weight:600;color:#475569;margin-left:8px;">(<?php echo count($grows); ?>)</small>
                                    </div>
                                    <div style="display:flex;gap:8px;align-items:center;">
                                        <button class="toggle-section btn btn-sm">Collapse</button>
                                    </div>
                                </header>
                                <div class="trl-table-wrap">
                                    <table class="trl-table" data-section-type="<?php echo htmlspecialchars($norm); ?>" style="width:100%;border-collapse:collapse;">
                                        <thead>
                                            <tr>
                                                <th>TRANS. DATE/TIME</th>
                                                <th>REF. NO.</th>
                                                <th>DUPLICATE</th>
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
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($grows as $row): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars((string) ($row['transfer_datetime'] ?? '')); ?></td>
                                                <td><?php echo htmlspecialchars((string) ($row['ref_no'] ?? '')); ?></td>
                                                <td><?php
                                                    $ref = (string) ($row['ref_no'] ?? '');
                                                    $isDup = false;
                                                    if (isset($row['duplicate_ok']) && $row['duplicate_ok'] === false) {
                                                        $isDup = true;
                                                    } elseif ($ref !== '' && in_array($ref, $duplicateList, true)) {
                                                        $isDup = true;
                                                    }
                                                    echo $isDup ? '<span class="dup-badge">DUPLICATE</span>' : '';
                                                ?></td>
                                                <td><?php echo htmlspecialchars((string) ($row['wrong_biller_id'] ?? '')); ?></td>
                                                <td><?php echo htmlspecialchars((string) ($row['biller_name'] ?? '')); ?></td>
                                                <td><?php echo htmlspecialchars((string) ($row['account_no'] ?? '')); ?></td>
                                                <td><?php echo htmlspecialchars((string) ($row['name'] ?? '')); ?></td>
                                                <td><?php echo htmlspecialchars((string) ($row['payment_branch_id'] ?? '')); ?></td>
                                                <td><?php echo htmlspecialchars((string) (($row['payment_branch'] ?? ($row['payment_branch_name'] ?? '')))); ?></td>
                                                <td class="amount"><?php echo number_format((float) ($row['amount'] ?? 0), 2); ?></td>
                                                <td><?php echo htmlspecialchars((string) trl_preview_normalize_type($row['type_of_request'] ?? '')); ?></td>
                                                <?php if ($hasCorrect): ?>
                                                    <td><?php echo htmlspecialchars((string) ($row['correct_biller_id'] ?? '')); ?></td>
                                                    <td><?php echo htmlspecialchars((string) ($row['correct_biller_name'] ?? '')); ?></td>
                                                <?php endif; ?>
                                                <?php if ($hasReported || $hasActual || $hasDifference): ?>
                                                    <?php if ($hasReported): ?><td><?php echo isset($row['wrong_amount']) ? number_format((float)$row['wrong_amount'],2) : ''; ?></td><?php endif; ?>
                                                    <?php if ($hasActual): ?><td><?php echo isset($row['correct_amount']) ? number_format((float)$row['correct_amount'],2) : ''; ?></td><?php endif; ?>
                                                    <?php if ($hasDifference): ?><td><?php echo isset($row['difference_value']) ? number_format((float)$row['difference_value'],2) : ''; ?></td><?php endif; ?>
                                                <?php endif; ?>
                                                <td><?php echo htmlspecialchars((string) ($row['reason'] ?? '')); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </section>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Collapse/expand per-type sections and filter by dropdown
            var typeFilter = document.getElementById('typeFilter');
            var sections = document.querySelectorAll('.trl-type-section');

            function setFilter(value) {
                sections.forEach(function(sec) {
                    var t = sec.getAttribute('data-type') || '';
                    if (!value) {
                        sec.style.display = '';
                    } else {
                        sec.style.display = (t === value) ? '' : 'none';
                    }
                });
            }

            if (typeFilter) {
                typeFilter.addEventListener('change', function(e) {
                    setFilter(e.target.value || '');
                });
            }

            // Toggle individual sections
            document.querySelectorAll('.toggle-section').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var sec = btn.closest('.trl-type-section');
                    if (!sec) return;
                    var wrap = sec.querySelector('.trl-table-wrap');
                    if (!wrap) return;
                    var isHidden = wrap.style.display === 'none' || getComputedStyle(wrap).display === 'none';
                    wrap.style.display = isHidden ? '' : 'none';
                    btn.textContent = isHidden ? 'Collapse' : 'Expand';
                });
            });

            var expandAll = document.getElementById('expandAll');
            var collapseAll = document.getElementById('collapseAll');
            if (expandAll) {
                expandAll.addEventListener('click', function() {
                    document.querySelectorAll('.trl-type-section .trl-table-wrap').forEach(function(w){ w.style.display = ''; });
                    document.querySelectorAll('.toggle-section').forEach(function(b){ b.textContent = 'Collapse'; });
                });
            }
            if (collapseAll) {
                collapseAll.addEventListener('click', function() {
                    document.querySelectorAll('.trl-type-section .trl-table-wrap').forEach(function(w){ w.style.display = 'none'; });
                    document.querySelectorAll('.toggle-section').forEach(function(b){ b.textContent = 'Expand'; });
                });
            }
        });
        </script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var back = document.getElementById('backToImport');
        if (back) {
            back.addEventListener('click', function(e) {
                e.preventDefault();
                Swal.fire({
                    title: 'Are you sure?',
                    html: 'Going back will cancel the current import. Do you want to continue?',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, go back',
                    cancelButtonText: 'No, stay'
                }).then(function(result) {
                    if (result.isConfirmed) {
                        window.location.href = back.getAttribute('href');
                    }
                });
            });
        }

        <?php if ($flash && (($flash['type'] ?? '') === 'success')): ?>
        Swal.fire({
            title: 'Successfully imported',
            html: '<?php echo (int) $importedRows; ?> rows',
            icon: 'success',
            allowOutsideClick: false,
            allowEscapeKey: false,
            confirmButtonText: 'OK'
        }).then(function() {
            window.location.href = 'trl-import.php';
        });
        <?php endif; ?>
    });
    </script>
</body>
</html>
