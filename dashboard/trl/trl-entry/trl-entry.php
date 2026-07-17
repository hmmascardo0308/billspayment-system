<?php
include '../../../config/config.php';
session_start();
include '../../../templates/middleware.php';
// canonical auth guard
$id = resolve_user_identifier();
if (empty($id)) { header('Location: ../../../login_form.php'); exit; }
// page-level permission enforcement (allow existing 'Bills Payment' holders too)
if (!function_exists('has_any_permission') || !has_any_permission(['TRL Entry','Bills Payment'])) { header('Location: ../../home.php'); exit; }

$mode = strtolower(trim((string) ($_GET['mode'] ?? 'auto')));
if (!in_array($mode, ['auto', 'manual', 'ticket'], true)) {
    $mode = 'auto';
}

$entryFlash = $_SESSION['trl_entry_flash'] ?? null;
unset($_SESSION['trl_entry_flash']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Transaction Request Log - Entry</title>
    <link rel="icon" href="../../../images/MLW%20logo.png" type="image/png">
    <link rel="stylesheet" href="../../../assets/css/templates/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="trl-entry.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="components/trl-entry-auto.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="components/trl-entry-manual.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="components/trl-entry-ticket.css?v=<?php echo time(); ?>">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://kit.fontawesome.com/30b908cc5a.js" crossorigin="anonymous"></script>
</head>
<body>
    <div class="main-container">
        <?php include '../../../templates/header_ui.php'; ?>
        <?php include '../../../templates/sidebar.php'; ?>

        <?php bp_section_header_html('fa-solid fa-pen-to-square', 'Transaction Request Log - Entry'); ?>

        <div class="bp-card container-fluid mt-3 p-4">
            <div class="entry-toolbar">
                <div class="mode-cards" id="modeCards">
                    <label class="mode-card <?php echo $mode === 'auto' ? 'selected' : ''; ?>" data-mode="auto">
                        <input type="radio" name="entryMode" value="auto" <?php echo $mode === 'auto' ? 'checked' : ''; ?>>
                        <div class="mode-icon"><i class="fa-solid fa-wand-magic-sparkles"></i></div>
                        <div class="mode-text">
                            <p class="mode-label">Search by Reference No.</p>
                        </div>
                    </label>
                    <label class="mode-card <?php echo $mode === 'manual' ? 'selected' : ''; ?>" data-mode="manual">
                        <input type="radio" name="entryMode" value="manual" <?php echo $mode === 'manual' ? 'checked' : ''; ?>>
                        <div class="mode-icon"><i class="fa-solid fa-keyboard"></i></div>
                        <div class="mode-text">
                            <p class="mode-label">Manual Input all fields directly</p>
                        </div>
                    </label>
                    <label class="mode-card <?php echo $mode === 'ticket' ? 'selected' : ''; ?>" data-mode="ticket">
                        <input type="radio" name="entryMode" value="ticket" <?php echo $mode === 'ticket' ? 'checked' : ''; ?>>
                        <div class="mode-icon"><i class="fa-solid fa-ticket"></i></div>
                        <div class="mode-text">
                            <p class="mode-label">Load from Support Ticket</p>
                        </div>
                    </label>
                </div>

                <button id="entrySubmitBtn" class="btn btn-danger" type="submit" style="display:none;">Submit</button>
            </div>

            <?php if ($entryFlash): ?>
                <div class="entry-alert <?php echo htmlspecialchars($entryFlash['type'] ?? 'info'); ?>">
                    <?php echo htmlspecialchars($entryFlash['message'] ?? ''); ?>
                </div>
            <?php endif; ?>

            <div id="autoPanel" class="mode-panel <?php echo $mode === 'auto' ? '' : 'hidden'; ?>">
                <?php require __DIR__ . '/components/trl-entry-auto.php'; ?>
            </div>

            <div id="manualPanel" class="mode-panel <?php echo $mode === 'manual' ? '' : 'hidden'; ?>">
                <?php require __DIR__ . '/components/trl-entry-manual.php'; ?>
            </div>

            <div id="ticketPanel" class="mode-panel <?php echo $mode === 'ticket' ? '' : 'hidden'; ?>">
                <?php require __DIR__ . '/components/trl-entry-ticket.php'; ?>
            </div>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.js"></script>
        <script>
        (function() {
            var modeInputs = document.querySelectorAll('input[name="entryMode"]');
            var modeCards = document.querySelectorAll('.mode-card');
            var autoPanel = document.getElementById('autoPanel');
            var manualPanel = document.getElementById('manualPanel');
            var ticketPanel = document.getElementById('ticketPanel');
            var submitBtn = document.getElementById('entrySubmitBtn');

            function activeMode() {
                var checked = document.querySelector('input[name="entryMode"]:checked');
                return checked ? checked.value : 'auto';
            }

            function getActiveForm() {
                var mode = activeMode();
                if (mode === 'auto') {
                    return document.getElementById('autoEntryForm');
                }
                if (mode === 'ticket') {
                    return document.getElementById('ticketEntryForm');
                }
                return document.getElementById('manualEntryForm');
            }

            function allRequiredFilled(form) {
                if (!form) return false;
                var fields = form.querySelectorAll('.required-field[required]');
                if (!fields.length) return false;
                for (var i = 0; i < fields.length; i++) {
                    var f = fields[i];
                    var val = (f.value || '').trim();
                    if (val === '') return false;
                }
                return true;
            }

            function updateSubmitVisibility() {
                var form = getActiveForm();
                var show = allRequiredFilled(form);
                if (!form) {
                    submitBtn.style.display = 'none';
                    submitBtn.removeAttribute('form');
                    submitBtn.disabled = true;
                    return;
                }

                submitBtn.setAttribute('form', form.id);
                submitBtn.style.display = show ? 'inline-flex' : 'none';
                submitBtn.disabled = !show;
            }

            function setMode(mode) {
                modeCards.forEach(function(card) {
                    card.classList.toggle('selected', card.getAttribute('data-mode') === mode);
                });
                autoPanel.classList.toggle('hidden', mode !== 'auto');
                manualPanel.classList.toggle('hidden', mode !== 'manual');
                ticketPanel.classList.toggle('hidden', mode !== 'ticket');
                updateSubmitVisibility();
            }

            // Build a simple summary HTML for the form (only visible fields)
            function escapeHtml(str) {
                if (str == null) return '';
                return String(str)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/\"/g, '&quot;')
                    .replace(/'/g, '&#39;');
            }

            // Parse a currency-formatted string (remove commas) and return a number
            function parseCurrencyToNumber(str) {
                if (str == null) return NaN;
                var s = String(str).replace(/,/g, '').trim();
                if (s === '') return NaN;
                return parseFloat(s);
            }

            // Format a number into currency with commas and two decimals (e.g. 10,123.00)
            function formatCurrencyNumber(num) {
                if (num == null || isNaN(num)) return '';
                return Number(num).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }

            // Format an input's value into currency form while typing/blur
            function formatInputCurrency(el) {
                if (!el) return;
                var raw = el.value || '';
                var cleaned = String(raw).replace(/[^\d\.\-]/g, '');
                if (cleaned === '' || cleaned === '-' || cleaned === '.' || cleaned === '-.') {
                    el.value = '';
                    return;
                }
                var n = parseFloat(cleaned);
                if (isNaN(n)) { el.value = ''; return; }
                el.value = formatCurrencyNumber(n);
            }

            // On focus, remove formatting so the user can edit the raw numeric value
            function unformatInputCurrency(el) {
                if (!el) return;
                var v = el.value || '';
                var cleaned = String(v).replace(/,/g, '').trim();
                if (cleaned === '' || cleaned === '-' || cleaned === '.' || cleaned === '-.') {
                    el.value = '';
                    return;
                }
                // Keep numeric string with decimal point (no grouping separators)
                // If it's a formatted currency like "10,123.00" this becomes "10123.00"
                el.value = cleaned;
                // move caret to end for convenient editing
                try {
                    var len = el.value.length;
                    el.setSelectionRange(len, len);
                } catch (e) {
                    // ignore if setSelectionRange is not supported
                }
            }

            function getFieldDisplayValue(form, fieldName) {
                var el = form.querySelector('[name="' + fieldName + '"]');
                if (!el) return '';
                if (el.tagName === 'SELECT') {
                    var option = el.options[el.selectedIndex];
                    return option ? option.text : '';
                }
                if (el.type === 'checkbox') {
                    return el.checked ? 'Yes' : 'No';
                }
                if (el.type === 'radio') {
                    var selected = form.querySelector('input[name="' + fieldName + '"]:checked');
                    return selected ? selected.value : '';
                }
                if (fieldName === 'amount') {
                    var num = parseCurrencyToNumber(el.value || '0');
                    if (!isNaN(num)) {
                        return 'P ' + formatCurrencyNumber(num);
                    }
                }

                // Format wrong/correct/difference for display in the summary
                if (fieldName === 'wrong_amount' || fieldName === 'correct_amount' || fieldName === 'difference_value') {
                    var n = parseCurrencyToNumber(el.value || '0');
                    if (!isNaN(n)) {
                        return 'PHP ' + formatCurrencyNumber(n);
                    }
                    return el.value || '';
                }

                return el.value || '';
            }

            function buildSummaryRows(form, items) {
                var rows = items.map(function(item) {
                    // Find the form element for this item
                    var el = form.querySelector('[name="' + item.name + '"]');

                    // If the element exists inside a .field-group that is hidden, skip it
                    if (el) {
                        var group = el.closest && el.closest('.field-group');
                        if (group) {
                            var cs = window.getComputedStyle(group);
                            if (!cs || cs.display === 'none' || cs.visibility === 'hidden' || group.offsetParent === null) {
                                return '';
                            }
                        }
                    } else {
                        // No element for this field in the form — skip
                        return '';
                    }

                    var value = getFieldDisplayValue(form, item.name);
                    if (value === '' || value == null) return '';

                    return '<div class="trl-summary-row">' +
                        '<div class="trl-summary-key">' + escapeHtml(item.label) + '</div>' +
                        '<div class="trl-summary-val">' + escapeHtml(value) + '</div>' +
                        '</div>';
                }).filter(function(r) { return r && r !== ''; });

                return rows.join('');
            }

            function buildSummaryHtml(form) {
                if (!form) return '<div>No data</div>';

                var transactionFields = [
                    { name: 'ref_no', label: 'REFERENCE NO.' },
                    { name: 'transfer_datetime', label: 'TRANSACTION DATE/TIME' },
                    { name: 'account_no', label: 'ACCOUNT NUMBER' },
                    { name: 'name', label: 'ACCOUNT NAME' },
                    { name: 'payment_branch_id', label: 'BRANCH ID' },
                    { name: 'payment_branch_name', label: 'PAYMENT BRANCH' },
                    { name: 'wrong_biller_id', label: 'BILLER ID' },
                    { name: 'biller_name', label: 'BILLER NAME' },
                    { name: 'amount', label: 'AMOUNT' }
                ];

                var requestFields = [
                    { name: 'type_of_request', label: 'TYPE OF REQUEST' },
                    { name: 'wrong_amount', label: 'WRONG AMOUNT' },
                    { name: 'correct_amount', label: 'CORRECT AMOUNT' },
                    { name: 'difference_value', label: 'DIFFERENCE' },
                    { name: 'correct_biller_id', label: 'CORRECT BILLER ID' },
                    { name: 'correct_biller_name', label: 'CORRECT BILER NAME' },
                    { name: 'reason', label: 'REASON' }
                ];

                var style = '<style>' +
                    '.trl-summary-wrap{padding-top:8px}' +
                    '.trl-summary-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}' +
                    '.trl-summary-card{border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;background:#fff}' +
                    '.trl-transaction-card{border-left:6px solid #2196f3}' +
                    '.trl-request-card{border-left:6px solid #ffb300}' +
                    '.trl-summary-head{padding:10px 12px;font-size:12px;letter-spacing:.6px;font-weight:700;color:#334155}' +
                    '.trl-transaction-head{background:#e8f3ff;color:#0b5394}' +
                    '.trl-request-head{background:#fff9e6;color:#8a5a00}' +
                    '.trl-summary-body{padding:12px 20px 12px 16px}' +
                    '.trl-summary-row{display:grid;grid-template-columns:43% 57%;gap:10px;padding:10px 0;border-bottom:1px solid #f1f5f9;align-items:start}' +
                    '.trl-summary-row:last-child{border-bottom:none}' +
                    '.trl-summary-key{font-size:12px;font-weight:700;color:#475569;line-height:1.35;justify-self:start;text-align:left;padding-left:6px}' +
                    '.trl-summary-val{font-size:13px;color:#0f172a;word-break:break-word;white-space:pre-wrap;line-height:1.4;justify-self:end;text-align:right;max-width:100%;padding-right:14px;box-sizing:border-box}' +
                    '@media (max-width:900px){.trl-summary-grid{grid-template-columns:1fr}.trl-summary-row{grid-template-columns:1fr}.trl-summary-key{margin-bottom:6px;justify-self:start;text-align:left}.trl-summary-val{justify-self:start;text-align:left;padding-right:0}}' +
                    '</style>';

                var html = '' +
                    '<div class="trl-summary-wrap">' +
                        '<div class="trl-summary-grid">' +
                            '<section class="trl-summary-card trl-transaction-card">' +
                                '<div class="trl-summary-head trl-transaction-head">TRANSACTION DETAILS</div>' +
                                '<div class="trl-summary-body">' + buildSummaryRows(form, transactionFields) + '</div>' +
                            '</section>' +
                            '<section class="trl-summary-card trl-request-card">' +
                                '<div class="trl-summary-head trl-request-head">REQUEST DETAILS</div>' +
                                '<div class="trl-summary-body">' + buildSummaryRows(form, requestFields) + '</div>' +
                            '</section>' +
                        '</div>' +
                    '</div>';

                return style + html;
            }

            // Send form via fetch and show result modals
            function sendForm(form) {
                var formData = new FormData(form);
                var actionUrl = form.getAttribute('action');
                var submittedRef = (form.querySelector('[name="ref_no"]') ? form.querySelector('[name="ref_no"]').value : '');

                Swal.fire({
                    title: 'Submitting...',
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    didOpen: function() {
                        Swal.showLoading();
                    }
                });

                fetch(actionUrl, {
                    method: 'POST',
                    body: formData
                })
                .then(function(response) {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(function(data) {
                    Swal.close();
                    if (data.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Transaction Request Log',
                            text: 'Your submission has been recorded successfully!',
                            confirmButtonColor: '#4caf50',
                            confirmButtonText: 'Acknowledged',
                            allowOutsideClick: false,
                            allowEscapeKey: false,
                            didOpen: function(modal) {
                                modal.classList.add('trl-success-modal');
                            }
                        }).then(function(result) {
                            if (result.isConfirmed) {
                                window.location.reload();
                            }
                        });
                    } else {
                        // Handle duplicate reference specially
                        if (data && data.code === 'DUPLICATE_REF_NO') {
                            var dupRef = data.ref_no || submittedRef || '';
                            Swal.fire({
                                icon: 'error',
                                title: 'Error!!!',
                                html: '<div style="font-weight:700">REFERENCE NO: ' + escapeHtml(dupRef) + '</div><div>is already written</div>',
                                confirmButtonColor: '#f44336',
                                confirmButtonText: 'Acknowledge'
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Submission Failed',
                                text: data.message || 'An error occurred while submitting the form. Please try again.',
                                confirmButtonColor: '#f44336',
                                confirmButtonText: 'Try Again'
                            });
                        }
                    }
                })
                .catch(function(error) {
                    console.error('Error:', error);
                    Swal.close();
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'An error occurred. Please check your connection and try again.',
                        confirmButtonColor: '#f44336',
                        confirmButtonText: 'Try Again'
                    });
                });
            }

            // Form submission with confirmation modal
            function setupFormSubmission(form) {
                if (!form) return;

                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    var summaryHtml = buildSummaryHtml(form);

                    Swal.fire({
                        title: 'Please review and confirm',
                        html: '<p>Please verify the information below before confirming submission:</p>' + summaryHtml,
                        width: '980px',
                        customClass: {
                            popup: 'trl-confirm-popup'
                        },
                        showCancelButton: true,
                        confirmButtonText: 'Confirm & Submit',
                        cancelButtonText: 'Edit',
                        focusConfirm: false
                    }).then(function(result) {
                        if (result.isConfirmed) {
                            sendForm(form);
                        }
                    });
                });
            }

            modeInputs.forEach(function(input) {
                input.addEventListener('change', function() {
                    setMode(input.value);
                    var params = new URLSearchParams(window.location.search);
                    params.set('mode', input.value);
                    if (input.value !== 'auto') {
                        params.delete('search_ref');
                    }
                    if (input.value !== 'ticket') {
                        params.delete('search_ticket');
                    }
                    history.replaceState(null, '', window.location.pathname + '?' + params.toString());
                });
            });

            document.addEventListener('input', updateSubmitVisibility);
            document.addEventListener('change', updateSubmitVisibility);

            // Manage conditional visibility for correct biller fields
            function manageCorrectBillerFields(form) {
                if (!form) return;
                var typeSelect = form.querySelector('[name="type_of_request"]');

                // Correct biller fields (only shown when WRONG BILLER is selected)
                var correctId = form.querySelector('[name="correct_biller_id"]');
                var correctName = form.querySelector('[name="correct_biller_name"]');
                var correctIdGroup = correctId ? correctId.closest('.field-group') : null;
                var correctNameGroup = correctName ? correctName.closest('.field-group') : null;
                var showCorrect = typeSelect && typeSelect.value === 'WRONG BILLER';
                if (correctIdGroup) correctIdGroup.style.display = showCorrect ? '' : 'none';
                if (correctNameGroup) correctNameGroup.style.display = showCorrect ? '' : 'none';
                if (correctId) { correctId.required = showCorrect; if (!showCorrect) correctId.value = ''; }
                if (correctName) { correctName.required = showCorrect; if (!showCorrect) correctName.value = ''; }

                // Fields to hide when the select is the default (empty) — only manage the reason field here
                var reasonField = form.querySelector('[name="reason"]');
                var reasonGroup = reasonField ? reasonField.closest('.field-group') : null;

                var isEmptyType = !typeSelect || (typeSelect.value === '' || typeSelect.value === null);

                if (reasonGroup) reasonGroup.style.display = isEmptyType ? 'none' : '';
                if (reasonField) { reasonField.required = !isEmptyType; if (isEmptyType) reasonField.value = ''; }

                // OVERSTATED AMOUNT: show reported/actual/difference only when selected
                var reportedEl = form.querySelector('[name="wrong_amount"]');
                var actualEl = form.querySelector('[name="correct_amount"]');
                var diffEl = form.querySelector('[name="difference_value"]');
                var reportedGroup = reportedEl ? reportedEl.closest('.field-group') : null;
                var actualGroup = actualEl ? actualEl.closest('.field-group') : null;
                var diffGroup = diffEl ? diffEl.closest('.field-group') : null;
                var isOverstated = typeSelect && typeSelect.value === 'OVERSTATED AMOUNT';
                var isCancelled = typeSelect && typeSelect.value === 'CANCELLED TRANSACTION';
                var showReportedActual = isOverstated || isCancelled;
                if (reportedGroup) reportedGroup.style.display = showReportedActual ? '' : 'none';
                if (actualGroup) actualGroup.style.display = showReportedActual ? '' : 'none';
                if (diffGroup) diffGroup.style.display = isOverstated ? '' : 'none';
                if (reportedEl) { reportedEl.required = showReportedActual; if (!showReportedActual) reportedEl.value = ''; }
                if (actualEl) { actualEl.required = showReportedActual; if (!showReportedActual) actualEl.value = ''; }
                if (diffEl) { if (!isOverstated) diffEl.value = ''; }

                // Compute values and autofill reason when either overstated or cancelled
                if (showReportedActual) {
                    computeOverstatedFields(form);
                }
            }

            // Compute difference and auto-fill reason for OVERSTATED AMOUNT and CANCELLED TRANSACTION
            function computeOverstatedFields(form) {
                if (!form) return;
                var repEl = form.querySelector('[name="wrong_amount"]');
                var actEl = form.querySelector('[name="correct_amount"]');
                var diffEl = form.querySelector('[name="difference_value"]');
                var reasonEl = form.querySelector('[name="reason"]');
                if (!repEl || !actEl) return;

                var r = parseCurrencyToNumber(repEl.value) || 0;
                var a = parseCurrencyToNumber(actEl.value) || 0;
                var d = r - a;

                var repFmt = formatCurrencyNumber(r);
                var actFmt = formatCurrencyNumber(a);
                var diffFmt = formatCurrencyNumber(d);

                // Determine request type
                var typeSelect = form.querySelector('[name="type_of_request"]');
                var typeVal = typeSelect ? typeSelect.value : '';

                if (typeVal === 'OVERSTATED AMOUNT') {
                    if (diffEl) diffEl.value = formatCurrencyNumber(d);
                    var autoReason = 'OVERSTATED AMOUNT PHP ' + repFmt + ' INSTEAD OF PHP ' + actFmt + ' WITH THE DIFFERENCE OF PHP ' + diffFmt;
                    if (reasonEl) {
                        var lastAuto = reasonEl.dataset.lastAuto || '';
                        var current = (reasonEl.value || '').trim();
                        if (current === '' || current === lastAuto) {
                            reasonEl.value = autoReason;
                            reasonEl.dataset.lastAuto = autoReason;
                        }
                    }
                } else if (typeVal === 'CANCELLED TRANSACTION') {
                    // For cancelled, do not set difference; only auto-fill reason
                    if (diffEl) diffEl.value = '';
                    var autoReason = 'Wrong amount posted PHP ' + repFmt + ' instead of PHP ' + actFmt;
                    if (reasonEl) {
                        var lastAuto2 = reasonEl.dataset.lastAuto || '';
                        var current2 = (reasonEl.value || '').trim();
                        if (current2 === '' || current2 === lastAuto2) {
                            reasonEl.value = autoReason;
                            reasonEl.dataset.lastAuto = autoReason;
                        }
                    }
                }
            }

            // Initialize and bind change handlers for both forms
            [document.getElementById('autoEntryForm'), document.getElementById('manualEntryForm')].forEach(function(frm) {
                if (!frm) return;
                var sel = frm.querySelector('[name="type_of_request"]');
                var reasonEl = frm.querySelector('[name="reason"]');
                // initialize per-type storage on the reason field
                if (reasonEl) {
                    if (!reasonEl._perType) reasonEl._perType = {};
                }

                if (sel) {
                    // remember initial type
                    sel._prevType = sel.value || '';
                    sel.addEventListener('change', function() {
                        try {
                            var prev = sel._prevType || '';
                            // store current reason value for previous type
                            if (reasonEl) {
                                reasonEl._perType = reasonEl._perType || {};
                                reasonEl._perType[prev] = reasonEl.value || '';
                            }
                        } catch (e) {
                            // ignore
                        }

                        // update visibility and compute fields for the new type
                        manageCorrectBillerFields(frm);
                        computeOverstatedFields(frm);

                        // restore stored reason for this newly selected type if present
                        try {
                            var cur = sel.value || '';
                            // request types that auto-generate a reason
                            var autoTypes = {
                                'OVERSTATED AMOUNT': 1,
                                'CANCELLED TRANSACTION': 1
                            };

                            if (reasonEl && reasonEl._perType && Object.prototype.hasOwnProperty.call(reasonEl._perType, cur)) {
                                // restore previously stored value for this type
                                reasonEl.value = reasonEl._perType[cur] || '';
                            } else {
                                // If this is an auto-generated type, let computeOverstatedFields handle it.
                                // Otherwise clear the reason so it doesn't carry over from the previous selection.
                                if (!(cur in autoTypes)) {
                                    if (reasonEl) {
                                        reasonEl.value = '';
                                        try { reasonEl.removeAttribute('data-last-auto'); } catch (e) {}
                                    }
                                }
                            }
                        } catch (e) {
                            // ignore
                        }

                        sel._prevType = sel.value || '';
                        updateSubmitVisibility();
                    });
                }
                // set initial visibility
                manageCorrectBillerFields(frm);
                // Attach input listeners for overstated value calculation
                var repField = frm.querySelector('[name="wrong_amount"]');
                var actField = frm.querySelector('[name="correct_amount"]');
                if (repField) {
                    // Compute on input, but format only on blur to avoid blocking typing
                    repField.addEventListener('input', function() { computeOverstatedFields(frm); updateSubmitVisibility(); });
                    repField.addEventListener('blur', function() { formatInputCurrency(repField); computeOverstatedFields(frm); updateSubmitVisibility(); });
                    repField.addEventListener('focus', function() { unformatInputCurrency(repField); });
                    if (repField.value) formatInputCurrency(repField);
                }
                if (actField) {
                    actField.addEventListener('input', function() { computeOverstatedFields(frm); updateSubmitVisibility(); });
                    actField.addEventListener('blur', function() { formatInputCurrency(actField); computeOverstatedFields(frm); updateSubmitVisibility(); });
                    actField.addEventListener('focus', function() { unformatInputCurrency(actField); });
                    if (actField.value) formatInputCurrency(actField);
                }
                // Manual form: handle Reference No toggle (hide by default)
                if (frm.id === 'manualEntryForm') {
                    var refToggle = frm.querySelector('#mRefToggle');
                    var refGroup = frm.querySelector('[data-ref-group]');
                    var refInput = frm.querySelector('[name="ref_no"]');

                    function setRefVisibility(show) {
                        if (refGroup) refGroup.style.display = show ? '' : 'none';
                        if (refInput) {
                            refInput.required = !!show;
                            if (!show) refInput.value = '';
                        }
                    }

                    if (refToggle) {
                        refToggle.addEventListener('change', function() {
                            setRefVisibility(refToggle.checked);
                            updateSubmitVisibility();
                        });
                        // initialize hidden (default unchecked)
                        setRefVisibility(!!refToggle.checked);
                    } else {
                        setRefVisibility(false);
                    }
                }
                // initial compute if fields present
                computeOverstatedFields(frm);
            });

            // Setup form submission handlers
            setupFormSubmission(document.getElementById('autoEntryForm'));
            setupFormSubmission(document.getElementById('manualEntryForm'));
            setupFormSubmission(document.getElementById('ticketEntryForm'));

            setMode(activeMode());
        })();
        </script>

        <?php include '../../../templates/footer.php'; ?>
    </div>
</body>
</html>
