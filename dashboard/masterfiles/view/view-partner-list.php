<?php
// Connect to the database
require_once __DIR__ . '/../../../config/config.php';
require '../../../vendor/autoload.php';

// Start the session
session_start();
@include_once __DIR__ . '/../../../templates/middleware.php';
$id = resolve_user_identifier();
if (empty($id)) { header('Location: ../../../login_form.php'); exit; }
if (!function_exists('has_any_permission') || !has_any_permission(['Masterfiles View Partner List', 'View Partner List'])) { header('Location: ../../home.php'); exit; }


// prefer explicit session values for current user email
$current_user_email = $_SESSION['admin_email'] ?? $_SESSION['user_email'] ?? '';

// get table display for partners
if (isset($_POST['action']) && $_POST['action'] === 'generate_partner_list') {
    header('Content-Type: application/json');

    try {
        $partnerQuery = "SELECT * FROM masterdata.partner_masterfile WHERE status = 'Active' ORDER BY partner_name";
        $stmt = $conn->prepare($partnerQuery);

        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $conn->error);
        }

        $stmt->execute();
        $result = $stmt->get_result();

        $partners = [];
        while ($row = $result->fetch_assoc()) {
            $partners[] = $row;
        }

        $stmt->close();

        echo json_encode([
            'status' => 'success',
            'data' => $partners
        ]);
        exit();
    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Database error: ' . $e->getMessage()
        ]);
        exit();
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Partner List | <?php if($_SESSION['user_type'] === 'admin' || $_SESSION['user_type'] === 'user') echo ucfirst($_SESSION['user_type']); else echo "Guest";?></title>
    <!-- custom CSS file link  -->
    <link rel="stylesheet" href="../../../assets/css/templates/style.css?v=<?php echo time(); ?>">
    <script src="https://kit.fontawesome.com/30b908cc5a.js" crossorigin="anonymous"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="../../../assets/js/sweetalert2.all.min.js"></script>

    <link rel="icon" href="../../../images/MLW logo.png" type="image/png">
    <style>
        /* Keep cells on a single line and allow full content horizontally.
           Table will expand horizontally and the container will scroll. */
        .table-responsive { overflow-x: auto; }
        /* Let table size to its content so columns show full values on one line */
        #partnerTable { table-layout: auto; width: auto; min-width: 100%; }
        #partnerTable thead th,
        #partnerTable tbody td {
            white-space: nowrap;
            word-break: normal;
            overflow: visible;
        }
        /* Ensure the responsive wrapper shows horizontal scrollbar when needed */
        .table-responsive .table { width: auto; }
    </style>
    <style>
        /* Row hover pointer */
        #partnerTable tbody tr:hover { cursor: pointer; background-color: #f8f9fa; }

        /* Revamped modal styles */
        #partnerModalOverlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.55); z-index:1200; backdrop-filter: blur(2px); }
        #partnerModal { display:none; position:fixed; left:50%; top:50%; transform:translate(-50%,-50%); background:#ffffff; z-index:1210; width:94%; max-width:980px; max-height:86vh; overflow:hidden; border-radius:10px; box-shadow:0 16px 40px rgba(2,6,23,0.32); font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial; }
        #partnerModal .modal-header { display:flex; gap:12px; align-items:center; padding:16px 20px; border-bottom:1px solid #f1f5f9; }
        #partnerModal .modal-title { margin:0; font-size:18px; font-weight:700; color:#0f172a; }
        #partnerModal .modal-sub { margin:0; font-size:13px; color:#475569; }
        .partner-status-badge { font-size:12px; padding:6px 10px; border-radius:999px; font-weight:600; display:inline-block; }
        .badge-active { background: #dcfce7; color: #166534; }
        .badge-inactive { background:#eef2ff; color:#3730a3; }
        .badge-other { background:#f1f5f9; color:#0f172a; }
        #partnerModal .modal-actions { margin-left:auto; display:flex; gap:8px; align-items:center; }
        #partnerModal .modal-close { background:transparent; border:0; font-size:20px; cursor:pointer; color:#0f172a; padding:6px; border-radius:6px; }
        #partnerModal .modal-close:hover { background:#f8fafc; }
        #partnerModal .modal-body { padding:14px 20px; overflow:auto; max-height:62vh; }
        /* Two-column responsive grid for details */
        .partner-details-grid { display:grid; grid-template-columns: 1fr 1fr; gap:12px 24px; }
        .partner-detail { background: #fff; padding:10px 12px; border-radius:8px; border:1px solid #f1f5f9; }
        .partner-detail-key { color:#475569; font-size:12px; font-weight:700; margin-bottom:6px; }
        .partner-detail-val { color:#0f172a; font-size:14px; word-break:break-word; }
        @media (max-width:700px) { .partner-details-grid { grid-template-columns: 1fr; } #partnerModal { width:96%; } }
        #partnerModal .modal-footer { padding:12px 20px; border-top:1px solid #f1f5f9; display:flex; justify-content:flex-end; gap:8px; background:#fff; }
        .btn-ghost { background:transparent; border:1px solid #e2e8f0; color:#0f172a; padding:8px 12px; border-radius:8px; cursor:pointer; }
        .btn-primary { background:#0ea5a4; border:0; color:#fff; padding:8px 12px; border-radius:8px; cursor:pointer; }
    </style>
</head>
<body>
    <div class="main-container">
        <?php include '../../../templates/header_ui.php'; ?>
        <!-- Show and Hide Side Nav Menu -->
        <?php include '../../../templates/sidebar.php'; ?>
        <div id="loading-overlay">
            <div class="loading-spinner"></div>
        </div>
        <div class="bp-section-header" role="region" aria-label="Page title">
            <div class="bp-section-title">
                <i class="fa-solid fa-layer-group" aria-hidden="true"></i>
                <div>
                    <h2>Partner List</h2>
                </div>
            </div>
        </div>
        <div class="container-fluid">
            <div class="row">
                <div class="col-md-18">
                    <div class="card">
                        <div class="card-header">
                            <div class="row g-2 align-items-end justify-content-between">
                                <div class="col-md-6 ms-auto">
                                    <label for="searchInput" class="form-label mb-1">Search Partner</label>
                                    <input
                                        type="text"
                                        id="searchInput"
                                        class="form-control"
                                        placeholder="Search by any field..."
                                        list="searchSuggestions"
                                    >
                                    <datalist id="searchSuggestions">
                                    </datalist>
                                </div>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-striped table-hover" id="partnerTable">
                                    <thead>
                                        <tr>
                                            <th>Partner Name</th>
                                            <th>Partner ID</th>
                                            <th>KPX ID</th>
                                            <th>GL Code</th>
                                            <th>Partner Account Name</th>
                                            <th>Bank Account Number</th>
                                            <th>Bank</th>
                                            <th>Payment Method</th>
                                                <th>Charge To</th>
                                                <th>Charge Schedule</th>
                                                <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    </tbody>
                                </table>
                                    <nav aria-label="Partner table pagination">
                                        <ul class="pagination" id="partnerPagination"></ul>
                                    </nav>
                                    <!-- Partner detail modal -->
                                    <div id="partnerModalOverlay" aria-hidden="true"></div>
                                    <div id="partnerModal" role="dialog" aria-modal="true" aria-labelledby="partnerModalTitle">
                                        <div class="modal-header">
                                            <div>
                                                <h5 id="partnerModalTitle" class="modal-title">Partner Details</h5>
                                                <div id="partnerModalSub" class="modal-sub">&nbsp;</div>
                                            </div>
                                            <div class="modal-actions">
                                                <span id="partnerStatusBadge" class="partner-status-badge badge-other">&nbsp;</span>
                                                <button type="button" class="modal-close" id="partnerModalClose" aria-label="Close">×</button>
                                            </div>
                                        </div>
                                        <div class="modal-body" id="partnerModalBody"></div>
                                        <div class="modal-footer">
                                            <button class="btn-ghost" id="partnerModalCopyBtn">Copy details</button>
                                            <button class="btn-primary" id="partnerModalCloseBtn">Close</button>
                                        </div>
                                    </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    </script>
</body>
<?php include '../../../templates/footer.php'; ?>

<!-- PARTNER LIST -->
 <script>
    $(function () {
    const $tableBody = $('#partnerTable tbody');
    const $searchInput = $('#searchInput');
    const $searchSuggestions = $('#searchSuggestions');
    const $loadingOverlay = $('#loading-overlay');
    const $pagination = $('#partnerPagination');

    let allPartners = [];
    let filteredPartners = [];
    let currentPage = 1;
    const rowsPerPage = 10;

        function escapeHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function getField(row, keys, defaultValue = '-') {
            for (const key of keys) {
                if (row[key] !== undefined && row[key] !== null && row[key] !== '') {
                    return row[key];
                }
            }
            return defaultValue;
        }

        function renderTableRows(rows) {
            if (!rows.length) {
                $tableBody.html('<tr><td colspan="11" class="text-center">No partner records found.</td></tr>');
                return;
            }

            const html = rows.map((row) => {
                const partnerName = getField(row, ['partner_name', 'name']);
                const partnerId = getField(row, ['partner_id']);
                const kpxId = getField(row, ['partner_id_kpx']);
                const glCode = getField(row, ['gl_code']);
                const accName = getField(row, ['partner_accName']);
                const bankAcc = getField(row, ['bank_accNumber']);
                const bank = getField(row, ['bank']);
                const paymentMethod = getField(row, ['settled_online_check', 'payment_method']);
                const chargeTo = getField(row, ['charge_to']);
                const chargeSched = getField(row, ['charge_sched']);
                const status = getField(row, ['status']);

                return `
                    <tr data-partner-id="${escapeHtml(partnerId)}">
                        <td>${escapeHtml(partnerName)}</td>
                        <td>${escapeHtml(partnerId)}</td>
                        <td>${escapeHtml(kpxId)}</td>
                        <td>${escapeHtml(glCode)}</td>
                        <td>${escapeHtml(accName)}</td>
                        <td>${escapeHtml(bankAcc)}</td>
                        <td>${escapeHtml(bank)}</td>
                        <td>${escapeHtml(paymentMethod)}</td>
                        <td>${escapeHtml(chargeTo)}</td>
                        <td>${escapeHtml(chargeSched)}</td>
                        <td>${escapeHtml(status)}</td>
                    </tr>
                `;
            }).join('');

            $tableBody.html(html);
        }

        function updateSuggestions(rows) {
            const uniqueNames = [...new Set(rows.map((row) => getField(row, ['partner_name'], '')))]
                .filter((name) => name !== '')
                .sort((a, b) => a.localeCompare(b));

            const optionsHtml = uniqueNames
                .map((name) => `<option value="${escapeHtml(name)}"></option>`)
                .join('');

            $searchSuggestions.html(optionsHtml);
        }

        function filterAndRender() {
            const keyword = $searchInput.val().toLowerCase().trim();

            if (!keyword) {
                filteredPartners = allPartners.slice();
                currentPage = 1;
                renderTableRowsPaged();
                return;
            }

            const filteredRows = allPartners.filter((row) => {
                const partnerName = getField(row, ['partner_name', 'name'], '').toString();
                const partnerId = getField(row, ['partner_id'], '').toString();
                const kpxId = getField(row, ['partner_id_kpx'], '').toString();
                const glCode = getField(row, ['gl_code'], '').toString();
                const accName = getField(row, ['partner_accName'], '').toString();
                const bankAcc = getField(row, ['bank_accNumber'], '').toString();
                const bank = getField(row, ['bank'], '').toString();
                const paymentMethod = getField(row, ['settled_online_check', 'payment_method'], '').toString();
                const chargeTo = getField(row, ['charge_to'], '').toString();
                const chargeSched = getField(row, ['charge_sched'], '').toString();
                const status = getField(row, ['status'], '').toString();

                const searchableText = [
                    partnerName,
                    partnerId,
                    kpxId,
                    glCode,
                    accName,
                    bankAcc,
                    bank,
                    paymentMethod,
                    chargeTo,
                    chargeSched
                ,
                    status
                ].join(' ').toLowerCase();

                return searchableText.includes(keyword);
            });

            filteredPartners = filteredRows;
            currentPage = 1;
            renderTableRowsPaged();
        }

        function renderTableRowsPaged() {
            const total = filteredPartners.length;
            const start = (currentPage - 1) * rowsPerPage;
            const end = start + rowsPerPage;
            const pageRows = filteredPartners.slice(start, end);
            renderTableRows(pageRows);
            renderPaginationControls(total);
        }

        function renderPaginationControls(totalItems) {
            const totalPages = Math.max(1, Math.ceil(totalItems / rowsPerPage));
            const maxVisible = 5; // show at most 5 page buttons
            let start = 1;
            let end = totalPages;

            if (totalPages > maxVisible) {
                const half = Math.floor(maxVisible / 2);
                start = currentPage - half;
                end = currentPage + half;
                if (start < 1) {
                    start = 1;
                    end = maxVisible;
                }
                if (end > totalPages) {
                    end = totalPages;
                    start = totalPages - maxVisible + 1;
                }
            }

            let html = '';
            // prev
            html += `<li class="page-item ${currentPage === 1 ? 'disabled' : ''}"><a class="page-link" href="#" data-page="${currentPage - 1}">Previous</a></li>`;

            if (start > 1) {
                html += `<li class="page-item"><a class="page-link" href="#" data-page="1">1</a></li>`;
                if (start > 2) html += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
            }

            for (let p = start; p <= end; p++) {
                html += `<li class="page-item ${p === currentPage ? 'active' : ''}"><a class="page-link" href="#" data-page="${p}">${p}</a></li>`;
            }

            if (end < totalPages) {
                if (end < totalPages - 1) html += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
                html += `<li class="page-item"><a class="page-link" href="#" data-page="${totalPages}">${totalPages}</a></li>`;
            }

            // next
            html += `<li class="page-item ${currentPage === totalPages ? 'disabled' : ''}"><a class="page-link" href="#" data-page="${currentPage + 1}">Next</a></li>`;
            $pagination.html(html);
        }

        // handle pagination clicks
        $(document).on('click', '#partnerPagination .page-link', function (e) {
            e.preventDefault();
            const tgt = $(this).data('page');
            if (!tgt || tgt < 1) return;
            const totalPages = Math.max(1, Math.ceil(filteredPartners.length / rowsPerPage));
            if (tgt > totalPages) return;
            currentPage = tgt;
            renderTableRowsPaged();
            // scroll to top of table after page change
            $('html, body').animate({ scrollTop: $('#partnerTable').offset().top - 80 }, 150);
        });

        // show partner modal with full details
        function showPartnerModal(partner) {
            if (!partner) return;

            // Header title and subtitle
            const title = partner.partner_name || partner.name || 'Partner Details';
            const subtitleParts = [];
            if (partner.partner_id) subtitleParts.push('ID: ' + partner.partner_id);
            if (partner.bank) subtitleParts.push(partner.bank);
            $('#partnerModalTitle').text(title);
            $('#partnerModalSub').text(subtitleParts.join(' • '));

            // Status badge
            const status = (partner.status || '').toString().toLowerCase();
            const $badge = $('#partnerStatusBadge');
            $badge.removeClass('badge-active badge-inactive badge-other');
            if (status === 'active') { $badge.addClass('badge-active').text('ACTIVE'); }
            else if (status === 'inactive') { $badge.addClass('badge-inactive').text('INACTIVE'); }
            else { $badge.addClass('badge-other').text((partner.status || '').toString().toUpperCase()); }

            // Map of fields to display (two-column grid)
            const map = [
                ['id', 'ID'],
                ['partner_id', 'Partner ID'],
                ['partner_id_kpx', 'KPX ID'],
                ['partner_type', 'Partner Type'],
                ['gl_code', 'GL Code'],
                ['partner_name', 'Partner Name'],
                ['inc_exc', 'Pricing Type'],
                ['withheld', 'Withheld'],
                ['partnerTin', 'Partner Tin'],
                ['address', 'Address'],
                ['businessStyle', 'Business Style'],
                ['abbreviation', 'Abbreviation'],
                ['series_number', 'Series Number'],
                ['partner_accName', 'Partner Account Name'],
                ['bank_accNumber', 'Bank Account Number'],
                ['bank', 'Bank'],
                ['settled_online_check', 'Settles Payment Method'],
                ['settled_sched', 'Settled Schedule'],
                ['charge_to', 'Charge To'],
                ['charge_sched', 'Charge Schedule'],
                ['serviceCharge', 'Service Charge'],
                ['payment_option', 'Payment Option'],
                ['transaction_range', 'Transaction Range'],
                ['transaction_path', 'Transaction Path'],
                ['status', 'Status']
            ];

            let html = '<div class="partner-details-grid">';
            map.forEach(function(pair) {
                const key = pair[0];
                const label = pair[1];
                const val = (partner[key] !== undefined && partner[key] !== null) ? partner[key] : '';
                html += `
                    <div class="partner-detail">
                        <div class="partner-detail-key">${escapeHtml(label)}</div>
                        <div class="partner-detail-val">${escapeHtml(val)}</div>
                    </div>
                `;
            });
            html += '</div>';

            $('#partnerModalBody').html(html);

            // Show modal
            $('#partnerModalOverlay').fadeIn(120);
            $('#partnerModal').fadeIn(160).attr('aria-hidden', 'false');

            // Wire copy button
            $('#partnerModalCopyBtn').off('click').on('click', function () { copyPartnerDetails(partner); });
        }

        function copyPartnerDetails(partner) {
            if (!partner) return;
            const fields = [
                'partner_name','partner_id','partner_id_kpx','gl_code','partner_accName','bank_accNumber','bank','settled_online_check','charge_to','charge_sched','status'
            ];
            const lines = [];
            fields.forEach(function(k) {
                const v = (partner[k] !== undefined && partner[k] !== null) ? partner[k] : '';
                lines.push(k + ': ' + v);
            });
            const text = lines.join('\n');
            try {
                navigator.clipboard.writeText(text).then(function() {
                    Swal.fire({ toast: true, position: 'top-end', timer: 1200, showConfirmButton: false, icon: 'success', title: 'Copied' });
                }, function() {
                    Swal.fire({ icon: 'info', title: 'Copy', text: 'Unable to access clipboard.' });
                });
            } catch (e) {
                // fallback
                const $tmp = $('<textarea>').val(text).appendTo('body').select();
                try { document.execCommand('copy'); Swal.fire({ toast: true, position: 'top-end', timer: 1200, showConfirmButton: false, icon: 'success', title: 'Copied' }); } catch (er) { Swal.fire({ icon: 'info', title: 'Copy', text: 'Unable to copy.' }); }
                $tmp.remove();
            }
        }

        function hidePartnerModal() {
            $('#partnerModalOverlay').fadeOut(120);
            $('#partnerModal').fadeOut(140);
            $('#partnerModal').attr('aria-hidden', 'true');
        }

        // click handlers to open/close modal
        $(document).on('click', '#partnerTable tbody tr', function () {
            const pid = $(this).attr('data-partner-id');
            if (!pid) return;
            const partner = allPartners.find(function(p) { return String(p.partner_id) === String(pid) || String(p.id) === String(pid); });
            if (partner) showPartnerModal(partner);
        });

        $('#partnerModalClose, #partnerModalCloseBtn, #partnerModalOverlay').on('click', function () { hidePartnerModal(); });

        function loadPartnerTableData() {
            $.ajax({
                url: 'view-partner-list.php',
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'generate_partner_list'
                },
                beforeSend: function () {
                    $loadingOverlay.show();
                },
                success: function (response) {
                    if (response && response.status === 'success' && Array.isArray(response.data)) {
                        allPartners = response.data;
                        filteredPartners = allPartners.slice();
                        currentPage = 1;
                        updateSuggestions(allPartners);
                        renderTableRowsPaged();
                    } else {
                        allPartners = [];
                        filteredPartners = [];
                        updateSuggestions(allPartners);
                        renderTableRowsPaged();
                    }
                },
                error: function () {
                    allPartners = [];
                    filteredPartners = [];
                    updateSuggestions(allPartners);
                    renderTableRowsPaged();

                    Swal.fire({
                        icon: 'error',
                        title: 'Load Failed',
                        text: 'Unable to load partner list. Please try again.'
                    });
                },
                complete: function () {
                    $loadingOverlay.hide();
                }
            });
        }

        $searchInput.on('input', filterAndRender);

        loadPartnerTableData();
    });

 </script>
</html>
