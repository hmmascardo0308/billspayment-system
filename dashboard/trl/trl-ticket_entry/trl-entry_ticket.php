<?php
include '../../../config/config.php';
session_start();
include '../../../templates/middleware.php';

$id = resolve_user_identifier();
if (empty($id)) {
    header('Location: ../../../login_form.php');
    exit;
}

if (!function_exists('has_permission') || !has_permission('TRL Ticket Entry')) {
    header('Location: ../../home.php');
    exit;
}

$entryFlash = $_SESSION['trl_entry_flash'] ?? null;
unset($_SESSION['trl_entry_flash']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>TRL - Ticket Entry</title>
    <link rel="icon" href="../../../images/MLW%20logo.png" type="image/png">
    <link rel="stylesheet" href="../../../assets/css/templates/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="trl-entry_ticket.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="components/trl-entry-auto.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="components/trl-entry-ticket.css?v=<?php echo time(); ?>">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://kit.fontawesome.com/30b908cc5a.js" crossorigin="anonymous"></script>
</head>
<body>
    <div class="main-container">
        <?php include '../../../templates/header_ui.php'; ?>
        <?php include '../../../templates/sidebar.php'; ?>

        <?php bp_section_header_html('fa-solid fa-ticket', 'TRL - Ticket Entry', 'Transaction Request Log - Ticket Entry'); ?>

        <div class="bp-card container-fluid mt-3 p-4">
            <div class="entry-toolbar">
                <div></div>
                <button id="entrySubmitBtn" class="btn btn-danger" type="submit" style="display:none;">Submit</button>
            </div>

            <?php if ($entryFlash): ?>
                <div class="entry-alert <?php echo htmlspecialchars($entryFlash['type'] ?? 'info'); ?>">
                    <?php echo htmlspecialchars($entryFlash['message'] ?? ''); ?>
                </div>
            <?php endif; ?>

            <div id="ticketPanel" class="mode-panel">
                <?php require __DIR__ . '/components/trl-entry-ticket.php'; ?>
            </div>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.js"></script>
        <script>
        (function() {
            var ticketForm = document.getElementById('ticketEntryForm');
            var submitBtn = document.getElementById('entrySubmitBtn');

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
                var form = document.getElementById('ticketEntryForm');
                if (!form) {
                    submitBtn.style.display = 'none';
                    submitBtn.removeAttribute('form');
                    submitBtn.disabled = true;
                    return;
                }

                submitBtn.setAttribute('form', form.id);
                var show = allRequiredFilled(form);
                submitBtn.style.display = show ? 'inline-flex' : 'none';
                submitBtn.disabled = !show;
            }

            function escapeHtml(str) {
                if (str == null) return '';
                return String(str)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/\"/g, '&quot;')
                    .replace(/'/g, '&#39;');
            }

            function parseCurrencyToNumber(str) {
                if (str == null) return NaN;
                var s = String(str).replace(/,/g, '').trim();
                if (s === '') return NaN;
                return parseFloat(s);
            }

            function formatCurrencyNumber(num) {
                if (num == null || isNaN(num)) return '';
                return Number(num).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }

            function getFieldDisplayValue(form, fieldName) {
                var el = form.querySelector('[name="' + fieldName + '"]');
                if (!el) return '';
                if (fieldName === 'amount') {
                    var num = parseCurrencyToNumber(el.value || '0');
                    if (!isNaN(num)) {
                        return 'P ' + formatCurrencyNumber(num);
                    }
                }
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
                    var el = form.querySelector('[name="' + item.name + '"]');
                    if (!el) return '';

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
                    { name: 'correct_biller_name', label: 'CORRECT BILLER NAME' },
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
                    } else if (data && data.code === 'DUPLICATE_REF_NO') {
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
                })
                .catch(function() {
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

            document.addEventListener('input', updateSubmitVisibility);
            document.addEventListener('change', updateSubmitVisibility);
            setupFormSubmission(ticketForm);
            updateSubmitVisibility();
        })();
        </script>

        <?php include '../../../templates/footer.php'; ?>
    </div>
</body>
</html>
