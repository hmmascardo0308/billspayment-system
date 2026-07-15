<?php
$partners = [];
$partnersSql = "
    SELECT DISTINCT
        TRIM(COALESCE(partner_id_kpx, '')) AS partner_id_kpx,
        TRIM(COALESCE(partner_name, '')) AS partner_name
    FROM mldb.subbiller
    WHERE COALESCE(TRIM(partner_id_kpx), '') <> ''
      AND COALESCE(TRIM(partner_name), '') <> ''
    ORDER BY partner_name ASC
";

$partnersRes = mysqli_query($conn, $partnersSql);
if ($partnersRes) {
    while ($p = mysqli_fetch_assoc($partnersRes)) {
        $pid = (string) ($p['partner_id_kpx'] ?? '');
        $pname = (string) ($p['partner_name'] ?? '');
        if ($pid !== '' && $pname !== '') {
            $partners[$pid] = $pname;
        }
    }
}

$selectedPartnerId = isset($_GET['partner_id']) ? trim((string) $_GET['partner_id']) : '';
if ($selectedPartnerId !== '' && !isset($partners[$selectedPartnerId])) {
    $selectedPartnerId = '';
}

$selectedPartnerName = $selectedPartnerId !== '' ? (string) $partners[$selectedPartnerId] : '';
$yearColumns = [];
$rowsBySubBiller = [];
$totalsByYear = [];
$grandTotal = 0.0;
$exportUrl = '';
if ($selectedPartnerId !== '') {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $baseDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    $exportUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . $baseDir . '/controllers/trl-report-excel.php?partner_id=' . rawurlencode($selectedPartnerId);
}

if ($selectedPartnerId !== '') {
    $sql = "
        SELECT
            TRIM(COALESCE(s.sub_billers_id, '')) AS sub_biller_id,
            COALESCE(NULLIF(TRIM(s.sub_billers_name), ''), 'UNKNOWN BILLER') AS sub_biller_name,
            YEAR(t.transfer_datetime) AS report_year,
            SUM(COALESCE(t.amount, 0)) AS total_amount
        FROM mldb.trl t
        INNER JOIN mldb.subbiller s
            ON CAST(t.wrong_biller_id AS CHAR) = CAST(s.sub_billers_id AS CHAR)
                WHERE s.partner_id_kpx = ?
                    AND t.transfer_datetime IS NOT NULL
                    AND t.status IS NULL
        GROUP BY TRIM(COALESCE(s.sub_billers_id, '')), COALESCE(NULLIF(TRIM(s.sub_billers_name), ''), 'UNKNOWN BILLER'), YEAR(t.transfer_datetime)
        ORDER BY sub_biller_name ASC, report_year ASC
    ";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('s', $selectedPartnerId);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            while ($r = $result->fetch_assoc()) {
                $subBillerId = trim((string) ($r['sub_biller_id'] ?? ''));
                $subBiller = (string) ($r['sub_biller_name'] ?? 'UNKNOWN BILLER');
                $year = (int) ($r['report_year'] ?? 0);
                $amount = (float) ($r['total_amount'] ?? 0);

                if ($year <= 0) {
                    continue;
                }

                $yearColumns[$year] = true;

                $subBillerKey = $subBillerId !== '' ? $subBillerId : $subBiller;

                if (!isset($rowsBySubBiller[$subBillerKey])) {
                    $rowsBySubBiller[$subBillerKey] = [
                        'id' => $subBillerId,
                        'name' => $subBiller,
                        'years' => [],
                        'total' => 0.0
                    ];
                }

                $rowsBySubBiller[$subBillerKey]['years'][$year] = $amount;
                $rowsBySubBiller[$subBillerKey]['total'] += $amount;

                if (!isset($totalsByYear[$year])) {
                    $totalsByYear[$year] = 0.0;
                }
                $totalsByYear[$year] += $amount;
                $grandTotal += $amount;
            }
        }
        $stmt->close();
    }
}

$yearColumns = array_keys($yearColumns);
sort($yearColumns);
ksort($rowsBySubBiller, SORT_NATURAL | SORT_FLAG_CASE);
?>

<div class="trl-summary-card">
    <div class="trl-summary-head">
        <h3>Summary Details</h3>
        <p>Choose a partner to view yearly received amounts for each biller mapped to that partner.</p>
    </div>

    <div class="trl-summary-filter-row">
        <form method="get" class="trl-summary-filters" id="summaryFilterForm">
            <input type="hidden" name="mode" value="summary">
            <label for="partner_id_summary">Partner</label>
            <div class="subbiller-dropdown partner-dropdown" id="partnerDropdownSummary">
                <button type="button" id="partnerToggleSummary" class="subbiller-toggle partner-toggle"><?php echo $selectedPartnerName !== '' ? htmlspecialchars($selectedPartnerName) : 'Select Partner'; ?> <i class="fa-solid fa-caret-down" aria-hidden="true"></i></button>
                <div class="subbiller-list partner-list" id="partnerListSummary" aria-hidden="true">
                    <?php foreach ($partners as $pid => $pname): ?>
                        <button type="button" class="partner-item" data-value="<?php echo htmlspecialchars($pid); ?>"><?php echo htmlspecialchars($pname); ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <input type="hidden" id="partner_id_summary" name="partner_id" value="<?php echo htmlspecialchars($selectedPartnerId); ?>">
        </form>

        <div class="trl-summary-actions">
            <a
                href="<?php echo htmlspecialchars($exportUrl !== '' ? $exportUrl : '#'); ?>"
                id="trlExportBtn"
                class="btn btn-danger trl-export-btn <?php echo $selectedPartnerId === '' ? 'is-disabled' : ''; ?>"
                data-partner="<?php echo htmlspecialchars($selectedPartnerId); ?>"
                data-partner-name="<?php echo htmlspecialchars($selectedPartnerName); ?>"
            >Export Excel</a>
        </div>
    </div>

    <?php if ($selectedPartnerId === ''): ?>
        <div class="trl-summary-empty">Choose a partner to generate the Summary report table.</div>
    <?php elseif (empty($rowsBySubBiller)): ?>
        <div class="trl-summary-empty">No TRL rows found for the selected partner.</div>
    <?php else: ?>
        <div class="trl-summary-title">
            <?php echo htmlspecialchars(strtoupper($selectedPartnerName) . ' SUB BILLERS'); ?>
        </div>

        <div class="trl-summary-table-wrap">
            <table class="trl-summary-table">
                <?php // explicit colgroup to ensure header/data column alignment ?>
                <colgroup>
                    <col class="col-name" />
                    <?php foreach ($yearColumns as $year): ?>
                        <col class="col-year" />
                    <?php endforeach; ?>
                    <col class="col-total" />
                </colgroup>
                <thead>
                    <tr>
                        <th class="partner-col-head">
                            <?php echo htmlspecialchars(strtoupper((string) $selectedPartnerName)); ?><br>
                            <span>SUB BILLERS</span>
                        </th>
                        <?php foreach ($yearColumns as $year): ?>
                            <th><?php echo htmlspecialchars((string) $year); ?></th>
                        <?php endforeach; ?>
                        <th>Total Receivable</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rowsBySubBiller as $row): ?>
                        <tr
                            class="trl-summary-row-link"
                            role="button"
                            tabindex="0"
                            data-subbiller-id="<?php echo htmlspecialchars((string) ($row['id'] ?? '')); ?>"
                            data-subbiller-name="<?php echo htmlspecialchars((string) $row['name']); ?>"
                            title="View full subbiller details"
                        >
                            <td><?php echo htmlspecialchars((string) $row['name']); ?></td>
                            <?php foreach ($yearColumns as $year): ?>
                                <?php $val = isset($row['years'][$year]) ? (float) $row['years'][$year] : null; ?>
                                <td class="amt"><?php echo $val !== null ? number_format($val, 2) : '-'; ?></td>
                            <?php endforeach; ?>
                            <td class="amt total"><?php echo number_format((float) $row['total'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th></th>
                        <?php foreach ($yearColumns as $year): ?>
                            <th class="amt"><?php echo isset($totalsByYear[$year]) ? number_format((float) $totalsByYear[$year], 2) : '-'; ?></th>
                        <?php endforeach; ?>
                        <th class="amt overall-total"><?php echo number_format((float) $grandTotal, 2); ?></th>
                    </tr>
                    <tr class="spacer-row">
                        <th colspan="<?php echo 1 + count($yearColumns); ?>"></th>
                        <th></th>
                    </tr>
                    <tr>
                        <?php $blankCount = count($yearColumns); ?>
                        <?php for ($i = 0; $i < $blankCount; $i++): ?>
                            <th></th>
                        <?php endfor; ?>
                        <th class="grand-label">Grand Total</th>
                        <th class="amt grand-total"><?php echo number_format((float) $grandTotal, 2); ?></th>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="trl-subbiller-modal-overlay" id="trlSummarySubbillerModal" aria-hidden="true">
            <div class="trl-subbiller-modal" role="dialog" aria-modal="true" aria-label="Subbiller details modal">
                <div class="trl-subbiller-modal-head">
                    <div class="trl-subbiller-modal-title-wrap">
                        <h4 id="trlSubbillerModalTitle">Subbiller Details</h4>
                        <p id="trlSubbillerModalSubtitle">Loading data...</p>
                    </div>
                    <div class="trl-subbiller-modal-actions">
                        <a href="#" id="trlSubbillerDownloadBtn" class="btn btn-danger trl-sub-export-btn">
                            <i class="fa-solid fa-download" aria-hidden="true"></i>
                            <span>Download Excel</span>
                        </a>
                        <button type="button" class="trl-subbiller-modal-close" id="trlSubbillerModalClose" aria-label="Close">&times;</button>
                    </div>
                </div>

                <div class="trl-subbiller-modal-body" id="trlSubbillerModalBody">
                    <div class="trl-sub-loader">Select a subbiller row to view details.</div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
(function() {
    // Partner dropdown for Summary (custom UI)
    var pToggle = document.getElementById('partnerToggleSummary');
    var pList = document.getElementById('partnerListSummary');
    var pInput = document.getElementById('partner_id_summary');
    var pForm = document.getElementById('summaryFilterForm');

    if (pToggle && pList && pInput) {
        pToggle.addEventListener('click', function(e) {
            pList.classList.toggle('open');
            e.stopPropagation();
        });
        document.addEventListener('click', function(ev) {
            if (pList.classList.contains('open') && !pList.contains(ev.target) && !pToggle.contains(ev.target)) {
                pList.classList.remove('open');
            }
        });
        var pItems = pList.querySelectorAll('.partner-item');
        pItems.forEach(function(it) {
            it.addEventListener('click', function() {
                var val = it.getAttribute('data-value') || '';
                pInput.value = val;
                pToggle.innerHTML = it.textContent + ' <i class="fa-solid fa-caret-down" aria-hidden="true"></i>';
                if (pForm) pForm.submit();
            });
        });
    }

    var btn = document.getElementById('trlExportBtn');
    if (!btn) return;

    btn.addEventListener('click', function(e) {
        var partnerId = (btn.getAttribute('data-partner') || '').trim();
        var partnerName = (btn.getAttribute('data-partner-name') || 'selected partner').trim();
        var href = btn.getAttribute('href') || '#';

        if (!partnerId || href === '#') {
            e.preventDefault();
            if (window.Swal) {
                Swal.fire({
                    icon: 'info',
                    title: 'Select Partner First',
                    text: 'Please choose a partner before exporting the report.'
                });
            }
            return;
        }

        e.preventDefault();
        if (!window.Swal) {
            window.location.href = href;
            return;
        }

        Swal.fire({
            icon: 'question',
            title: 'Export Report?',
            html: 'Export Excel report for <b>' + String(partnerName)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;') + '</b>?',
            showCancelButton: true,
            confirmButtonText: 'Yes, Export',
            cancelButtonText: 'Cancel'
        }).then(function(result) {
            if (result.isConfirmed) {
                window.location.href = href;
            }
        });
    });

    var selectedPartnerId = <?php echo json_encode($selectedPartnerId); ?>;
    var selectedPartnerName = <?php echo json_encode($selectedPartnerName); ?>;
    var modalOverlay = document.getElementById('trlSummarySubbillerModal');
    var modalBody = document.getElementById('trlSubbillerModalBody');
    var modalTitle = document.getElementById('trlSubbillerModalTitle');
    var modalSubtitle = document.getElementById('trlSubbillerModalSubtitle');
    var modalClose = document.getElementById('trlSubbillerModalClose');
    var modalDownloadBtn = document.getElementById('trlSubbillerDownloadBtn');
    var clickableRows = document.querySelectorAll('.trl-summary-row-link');

    function applyTransactionViewportLimit() {
        if (!modalBody) return;

        var txnWrap = modalBody.querySelector('.trl-sub-table-wrap--transactions');
        if (!txnWrap) return;

        var dataRows = txnWrap.querySelectorAll('tbody tr:not(.trl-sub-total-row)');
        if (!dataRows.length || dataRows.length <= 12) {
            txnWrap.style.maxHeight = '';
            return;
        }

        var thead = txnWrap.querySelector('thead');
        var headerHeight = thead ? thead.offsetHeight : 0;
        var visibleRows = Array.prototype.slice.call(dataRows, 0, 12);
        var rowsHeight = visibleRows.reduce(function (sum, row) {
            return sum + row.offsetHeight;
        }, 0);

        txnWrap.style.maxHeight = (headerHeight + rowsHeight + 2) + 'px';
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function formatAmount(value) {
        var num = Number(value || 0);
        return num.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function openSubbillerModal() {
        if (!modalOverlay) return;
        modalOverlay.classList.add('open');
        modalOverlay.setAttribute('aria-hidden', 'false');
        document.body.classList.add('trl-submodal-open');
    }

    function closeSubbillerModal() {
        if (!modalOverlay) return;
        modalOverlay.classList.remove('open');
        modalOverlay.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('trl-submodal-open');
    }

    function buildSummaryTable(summaryYears, summaryByYear, summaryTotal) {
        if (!summaryYears.length) {
            return '<div class="trl-sub-empty">No yearly summary found for this subbiller.</div>';
        }

        var yearHead = summaryYears.map(function (year) {
            return '<th>' + escapeHtml(year) + '</th>';
        }).join('');

        var yearValues = summaryYears.map(function (year) {
            var amount = summaryByYear[year] || 0;
            return '<td class="amt">' + formatAmount(amount) + '</td>';
        }).join('');

        return '' +
            '<div class="trl-sub-section">' +
                '<div class="trl-sub-section-title">Yearly Summary</div>' +
                '<div class="trl-sub-table-wrap">' +
                    '<table class="trl-sub-table trl-sub-table--summary">' +
                        '<thead><tr><th>Subbiller</th>' + yearHead + '<th>Total Receivable</th></tr></thead>' +
                        '<tbody><tr><td>' + escapeHtml(modalTitle ? modalTitle.textContent : 'Subbiller') + '</td>' + yearValues + '<td class="amt total">' + formatAmount(summaryTotal) + '</td></tr></tbody>' +
                    '</table>' +
                '</div>' +
            '</div>';
    }

    function buildTransactionRows(rows) {
        if (!rows.length) {
            return '<tr><td colspan="16" class="trl-sub-empty-cell">No transactions found for this subbiller.</td></tr>';
        }

        return rows.map(function (row) {
            return '<tr>' +
                '<td>' + escapeHtml(row.trl_no) + '</td>' +
                '<td>' + escapeHtml(row.transfer_datetime) + '</td>' +
                '<td>' + escapeHtml(row.ref_no) + '</td>' +
                '<td>' + escapeHtml(row.wrong_biller_id) + '</td>' +
                '<td>' + escapeHtml(row.biller_name) + '</td>' +
                '<td>' + escapeHtml(row.account_no) + '</td>' +
                '<td>' + escapeHtml(row.customer_name) + '</td>' +
                '<td>' + escapeHtml(row.payment_branch_id) + '</td>' +
                '<td class="amt">' + formatAmount(row.amount) + '</td>' +
                '<td>' + escapeHtml(row.type_of_request) + '</td>' +
                '<td>' + escapeHtml(row.correct_biller_id) + '</td>' +
                '<td>' + escapeHtml(row.correct_biller_name) + '</td>' +
                '<td class="amt">' + escapeHtml(row.wrong_amount_display) + '</td>' +
                '<td class="amt">' + escapeHtml(row.correct_amount_display) + '</td>' +
                '<td class="amt">' + escapeHtml(row.difference_display) + '</td>' +
                '<td>' + escapeHtml(row.reason) + '</td>' +
            '</tr>';
        }).join('');
    }

    function buildTransactionTotalRow(rows) {
        if (!rows.length) {
            return '';
        }

        var total = rows.reduce(function (sum, row) {
            return sum + Number(row.amount || 0);
        }, 0);

        return '<tr class="trl-sub-total-row">' +
            '<td colspan="7"></td>' +
            '<td class="trl-sub-total-label">Total Amount</td>' +
            '<td class="trl-sub-total-value amt">' + formatAmount(total) + '</td>' +
            '<td colspan="7"></td>' +
        '</tr>';
    }

    function renderDetails(payload) {
        var summaryYears = payload.summary && payload.summary.years ? payload.summary.years : [];
        var summaryByYear = payload.summary && payload.summary.by_year ? payload.summary.by_year : {};
        var summaryTotal = payload.summary && payload.summary.total ? payload.summary.total : 0;
        var rows = payload.rows || [];

        var summarySection = buildSummaryTable(summaryYears, summaryByYear, summaryTotal);
        var transactionSection = '' +
            '<div class="trl-sub-section">' +
                '<div class="trl-sub-section-title">Transaction Rows</div>' +
                '<div class="trl-sub-table-wrap trl-sub-table-wrap--transactions">' +
                    '<table class="trl-sub-table">' +
                        '<thead>' +
                            '<tr>' +
                                '<th>TRL NO.</th>' +
                                '<th>TRANS. DATE/TIME</th>' +
                                '<th>REF. NO.</th>' +
                                '<th>WRONG BILLER ID</th>' +
                                '<th>BILLER NAME</th>' +
                                '<th>ACCOUNT NO.</th>' +
                                '<th>NAME</th>' +
                                '<th>PAYMENT BRANCH ID</th>' +
                                '<th>AMOUNT</th>' +
                                '<th>TYPE OF REQUEST</th>' +
                                '<th>CORRECT BILLER ID</th>' +
                                '<th>CORRECT BILLER NAME</th>' +
                                '<th>WRONG AMOUNT</th>' +
                                '<th>CORRECT AMOUNT</th>' +
                                '<th>DIFFERENCE</th>' +
                                '<th>REASON</th>' +
                            '</tr>' +
                        '</thead>' +
                        '<tbody>' + buildTransactionRows(rows) + buildTransactionTotalRow(rows) + '</tbody>' +
                    '</table>' +
                '</div>' +
            '</div>';

        modalBody.innerHTML = summarySection + transactionSection;
        applyTransactionViewportLimit();
    }

    function fetchSubbillerDetails(subbillerId, subbillerName) {
        if (!modalBody) return;

        modalTitle.textContent = subbillerName;
        modalSubtitle.textContent = (selectedPartnerName || 'Selected Partner') + ' | Loading details...';

        var detailsUrl = 'controllers/trl-report-subbiller-details.php?partner_id=' + encodeURIComponent(selectedPartnerId) + '&subbiller_id=' + encodeURIComponent(subbillerId);
        var exportUrl = 'controllers/trl-report-excel-subbiler.php?partner_id=' + encodeURIComponent(selectedPartnerId) + '&subbiller_ids=' + encodeURIComponent(subbillerId) + '&include_summary=1';
        if (modalDownloadBtn) {
            modalDownloadBtn.setAttribute('href', exportUrl);
        }

        modalBody.innerHTML = '<div class="trl-sub-loader">Loading subbiller data...</div>';
        openSubbillerModal();

        fetch(detailsUrl, {
            method: 'GET',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (res) {
            return res.json();
        })
        .then(function (payload) {
            if (!payload || payload.ok !== true) {
                throw new Error(payload && payload.message ? payload.message : 'Unable to load subbiller details.');
            }
            modalSubtitle.textContent = (payload.partner_name || selectedPartnerName || '') + ' | ' + (payload.rows ? payload.rows.length : 0) + ' row(s)';
            renderDetails(payload);
        })
        .catch(function (err) {
            modalSubtitle.textContent = (selectedPartnerName || 'Selected Partner') + ' | Error loading details';
            modalBody.innerHTML = '<div class="trl-sub-empty">' + escapeHtml(err && err.message ? err.message : 'Unable to load subbiller details.') + '</div>';
        });
    }

    if (modalOverlay && modalClose) {
        modalClose.addEventListener('click', closeSubbillerModal);
        modalOverlay.addEventListener('click', function (e) {
            if (e.target === modalOverlay) {
                closeSubbillerModal();
            }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modalOverlay.classList.contains('open')) {
                closeSubbillerModal();
            }
        });
    }

    window.addEventListener('resize', function () {
        if (modalOverlay && modalOverlay.classList.contains('open')) {
            applyTransactionViewportLimit();
        }
    });

    if (modalDownloadBtn) {
        modalDownloadBtn.addEventListener('click', function (e) {
            var href = modalDownloadBtn.getAttribute('href') || '#';
            if (href === '#') {
                e.preventDefault();
            }
        });
    }

    clickableRows.forEach(function (row) {
        function openRowDetails() {
            var subbillerId = (row.getAttribute('data-subbiller-id') || '').trim();
            var subbillerName = (row.getAttribute('data-subbiller-name') || 'Subbiller').trim();
            if (!selectedPartnerId || !subbillerId) {
                return;
            }
            fetchSubbillerDetails(subbillerId, subbillerName);
        }

        row.addEventListener('click', openRowDetails);
        row.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                openRowDetails();
            }
        });
    });
})();
</script>
