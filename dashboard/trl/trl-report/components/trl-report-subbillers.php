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

// fetch subbillers for partner
$subbillers = [];
if ($selectedPartnerId !== '') {
    $ss = $conn->prepare(
        "SELECT TRIM(COALESCE(sub_billers_id, '')) AS id, COALESCE(NULLIF(TRIM(sub_billers_name), ''), 'UNKNOWN BILLER') AS name
         FROM mldb.subbiller
         WHERE TRIM(COALESCE(partner_id_kpx, '')) = ?
         ORDER BY sub_billers_name ASC"
    );
    if ($ss) {
        $ss->bind_param('s', $selectedPartnerId);
        if ($ss->execute()) {
            $res = $ss->get_result();
            while ($r = $res->fetch_assoc()) {
                $sid = (string) ($r['id'] ?? '');
                if ($sid === '') continue;
                $subbillers[$sid] = (string) ($r['name'] ?? 'UNKNOWN BILLER');
            }
        }
        $ss->close();
    }
}

// selected subbillers from GET (array). If the user explicitly clicked Apply
// and provided no selection, respect the empty selection. Only default to
// "all" on initial load (when Apply wasn't clicked).
$selectedSub = [];
$applyClicked = isset($_GET['apply_subbiller']);
if (!empty($_GET['subbiller']) && is_array($_GET['subbiller'])) {
    foreach ($_GET['subbiller'] as $s) {
        $s = trim((string) $s);
        if ($s !== '') $selectedSub[] = $s;
    }
}

// Do not default to selecting all sub-billers. Let the user choose explicitly.
// (If the user submits the form with no selection, we respect the empty selection.)

// Build data (similar to summary) filtered by selected subbillers
$yearColumns = [];
$rowsBySubBiller = [];
$totalsByYear = [];
$grandTotal = 0.0;

if ($selectedPartnerId !== '') {
    $inClause = '';
    if (!empty($selectedSub)) {
        // sanitize and quote
        $escaped = array_map(function($v) use ($conn) {
            return "'" . $conn->real_escape_string((string) $v) . "'";
        }, $selectedSub);
        $inClause = ' AND s.sub_billers_id IN (' . implode(',', $escaped) . ') ';
    }

    $sql = "
        SELECT
            COALESCE(NULLIF(TRIM(s.sub_billers_name), ''), 'UNKNOWN BILLER') AS sub_biller_name,
            YEAR(t.transfer_datetime) AS report_year,
            SUM(COALESCE(t.amount, 0)) AS total_amount
        FROM mldb.trl t
        INNER JOIN mldb.subbiller s
            ON CAST(t.wrong_biller_id AS CHAR) = CAST(s.sub_billers_id AS CHAR)
        WHERE s.partner_id_kpx = ?
            AND t.transfer_datetime IS NOT NULL
            AND t.status IS NULL
            " . $inClause . "
        GROUP BY COALESCE(NULLIF(TRIM(s.sub_billers_name), ''), 'UNKNOWN BILLER'), YEAR(t.transfer_datetime)
        ORDER BY sub_biller_name ASC, report_year ASC
    ";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('s', $selectedPartnerId);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            while ($r = $result->fetch_assoc()) {
                $subBiller = (string) ($r['sub_biller_name'] ?? 'UNKNOWN BILLER');
                $year = (int) ($r['report_year'] ?? 0);
                $amount = (float) ($r['total_amount'] ?? 0);

                if ($year <= 0) continue;

                $yearColumns[$year] = true;

                if (!isset($rowsBySubBiller[$subBiller])) {
                    $rowsBySubBiller[$subBiller] = ['name' => $subBiller, 'years' => [], 'total' => 0.0];
                }

                $rowsBySubBiller[$subBiller]['years'][$year] = $amount;
                $rowsBySubBiller[$subBiller]['total'] += $amount;

                if (!isset($totalsByYear[$year])) $totalsByYear[$year] = 0.0;
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

// build export URL base (only when sub-billers are explicitly selected)
$exportBase = '';
if ($selectedPartnerId !== '' && !empty($selectedSub)) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $baseDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    $exportBase = $scheme . '://' . $_SERVER['HTTP_HOST'] . $baseDir . '/controllers/trl-report-excel-subbiler.php?partner_id=' . rawurlencode($selectedPartnerId);
    $exportBase .= ($exportBase === '' ? '?' : '&') . 'subbiller_ids=' . rawurlencode(implode(',', $selectedSub));
}

?>

<div class="trl-subbillers-card">
    <div class="trl-subbillers-head">
        <h3>Sub Biller Report</h3>
        <p>Choose a partner and select sub-biller(s) to generate specific sub-biller reports.</p>
    </div>

    <div class="trl-subbillers-filter-row">
        <form method="get" class="trl-subbillers-filters" id="subbillerFilterForm">
            <input type="hidden" name="mode" value="subbillers">
            <label for="partner_id_sub">Partner</label>
            <div class="subbiller-dropdown partner-dropdown" id="partnerDropdownSub">
                <button type="button" id="partnerToggleSub" class="subbiller-toggle partner-toggle"><?php echo $selectedPartnerName !== '' ? htmlspecialchars($selectedPartnerName) : 'Select Partner'; ?> <i class="fa-solid fa-caret-down" aria-hidden="true"></i></button>
                <div class="subbiller-list partner-list" id="partnerListSub" aria-hidden="true">
                    <?php foreach ($partners as $pid => $pname): ?>
                        <button type="button" class="partner-item" data-value="<?php echo htmlspecialchars($pid); ?>"><?php echo htmlspecialchars($pname); ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <input type="hidden" id="partner_id_sub" name="partner_id" value="<?php echo htmlspecialchars($selectedPartnerId); ?>">

            <?php if ($selectedPartnerId !== ''): ?>
                <div class="subbiller-dropdown" id="subbillerDropdown">
                    <button type="button" id="subbillerToggle" class="btn subbiller-toggle">Select Sub-billers <i class="fa-solid fa-caret-down" aria-hidden="true"></i></button>
                    <div class="subbiller-list" id="subbillerList">
                        <label><input type="checkbox" id="subbiller_all"> All</label>
                        <?php foreach ($subbillers as $sid => $sname): ?>
                            <label>
                                <input type="checkbox" name="subbiller[]" value="<?php echo htmlspecialchars($sid); ?>" <?php echo in_array($sid, $selectedSub, true) ? 'checked' : ''; ?>>
                                <?php echo htmlspecialchars($sname); ?>
                            </label>
                        <?php endforeach; ?>
                        <div class="subbiller-actions">
                            <button type="submit" name="apply_subbiller" value="1" class="btn btn-primary">Apply</button>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

        </form>

        <div class="trl-subbillers-actions">
            <a href="<?php echo htmlspecialchars($exportBase !== '' ? $exportBase : '#'); ?>" id="trlExportSubBtn" class="btn btn-danger <?php echo ($selectedPartnerId === '' || empty($selectedSub)) ? 'is-disabled' : ''; ?>" data-export-base="<?php echo htmlspecialchars($exportBase); ?>" data-partner-name="<?php echo htmlspecialchars($selectedPartnerName); ?>">Export Sub-biller(s)</a>
        </div>
    </div>

    <?php if ($selectedPartnerId === ''): ?>
        <div class="trl-summary-empty">Choose a partner to generate the Sub-biller report.</div>
    <?php elseif (empty($rowsBySubBiller)): ?>
        <div class="trl-summary-empty">No TRL rows found for the selected partner / sub-biller selection.</div>
    <?php else: ?>
        <div class="trl-summary-title">
            <?php echo htmlspecialchars(strtoupper($selectedPartnerName) . ' SUB BILLERS'); ?>
        </div>

        <div class="trl-summary-table-wrap">
            <table class="trl-summary-table">
                <colgroup>
                    <col class="col-name" />
                    <?php foreach ($yearColumns as $year): ?>
                        <col class="col-year" />
                    <?php endforeach; ?>
                    <col class="col-total" />
                </colgroup>
                <thead>
                    <tr>
                        <th class="partner-col-head"><?php echo htmlspecialchars(strtoupper((string) $selectedPartnerName)); ?><br><span>SUB BILLERS</span></th>
                        <?php foreach ($yearColumns as $year): ?>
                            <th><?php echo htmlspecialchars((string) $year); ?></th>
                        <?php endforeach; ?>
                        <th>Total Receivable</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rowsBySubBiller as $row): ?>
                        <tr>
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
    <?php endif; ?>
</div>

<script>
(function(){
    var toggle = document.getElementById('subbillerToggle');
    var list = document.getElementById('subbillerList');
    var all = document.getElementById('subbiller_all');
    var partnerInput = document.getElementById('partner_id_sub');
    var partnerId = partnerInput ? partnerInput.value : '';
    var storageKey = 'trl_subbiller_selected_' + partnerId;

    // Partner dropdown behavior (subbillers panel)
    var pToggle = document.getElementById('partnerToggleSub');
    var pList = document.getElementById('partnerListSub');
    var pForm = document.getElementById('subbillerFilterForm');
    if (pToggle && pList && partnerInput) {
        pToggle.addEventListener('click', function(e){ pList.classList.toggle('open'); e.stopPropagation(); });
        document.addEventListener('click', function(ev){ if (pList.classList.contains('open') && !pList.contains(ev.target) && !pToggle.contains(ev.target)) { pList.classList.remove('open'); } });
        var pItems = pList.querySelectorAll('.partner-item');
        pItems.forEach(function(it){
            it.addEventListener('click', function(){
                var val = it.getAttribute('data-value') || '';
                partnerInput.value = val;
                pToggle.innerHTML = it.textContent + ' <i class="fa-solid fa-caret-down" aria-hidden="true"></i>';
                if (pForm) pForm.submit();
            });
        });
    }

    function getStored(){ try { var s = localStorage.getItem(storageKey); return s ? JSON.parse(s) : null; } catch(e){ return null; } }
    function setStored(arr){ try { localStorage.setItem(storageKey, JSON.stringify(arr)); } catch(e){} }

    function updateToggleLabel(){
        if (!toggle || !list) return;
        var checks = Array.prototype.slice.call(list.querySelectorAll('input[type="checkbox"][name="subbiller[]"]'));
        var selected = checks.filter(function(c){ return c.checked; }).length;
        var total = checks.length;
        var label = 'Select Sub-billers';
        if (selected === total && total > 0) label = 'All Sub-billers';
        else if (selected > 0) label = selected + ' selected';
        toggle.innerHTML = label + ' <i class="fa-solid fa-caret-down" aria-hidden="true"></i>';
    }

    if (toggle && list) {
        toggle.addEventListener('click', function(e){ list.classList.toggle('open'); e.stopPropagation(); updateToggleLabel(); });
        // close when clicking outside
        document.addEventListener('click', function(ev){ if (list.classList.contains('open') && !list.contains(ev.target) && !toggle.contains(ev.target)) { list.classList.remove('open'); } });
    }

    if (list) {
        var checks = Array.prototype.slice.call(list.querySelectorAll('input[type="checkbox"][name="subbiller[]"]'));

        // Do NOT auto-restore selections from localStorage on initial load.
        // Only persist selections when the user actively changes them (below).

        // sync the 'All' checkbox state (based on actual checked inputs)
        if (all) {
            all.checked = checks.length > 0 && checks.every(function(c){ return c.checked; });
            all.addEventListener('change', function(){ checks.forEach(function(c){ c.checked = all.checked; }); setStored(checks.filter(function(c){return c.checked;}).map(function(x){return x.value;})); updateToggleLabel(); });
        }

        checks.forEach(function(c){
            c.addEventListener('change', function(){
                var sel = checks.filter(function(x){return x.checked;}).map(function(x){return x.value;});
                setStored(sel);
                if (all) all.checked = checks.length > 0 && checks.every(function(x){ return x.checked; });
                updateToggleLabel();
            });
        });

        // initial label
        updateToggleLabel();
    }

    var exp = document.getElementById('trlExportSubBtn');
    if (!exp) return;
    exp.addEventListener('click', function(e){
        var href = exp.getAttribute('href') || '#';
        var partnerName = exp.getAttribute('data-partner-name') || 'selected partner';
        if (!href || href === '#') { e.preventDefault(); return; }
        e.preventDefault();
        if (!window.Swal) { window.location.href = href; return; }

        Swal.fire({
            title: 'Export Sub-biller Report',
            html: '<p>Export for <b>' + String(partnerName).replace(/&/g,'&amp;') + '</b>?</p>' +
                  '<p><label><input type="checkbox" id="includeSummary"> Include Summary sheet</label></p>',
            showCancelButton: true,
            confirmButtonText: 'Export',
            preConfirm: function(){ return { include: document.getElementById('includeSummary') ? document.getElementById('includeSummary').checked : false }; }
        }).then(function(result){
            if (result.isConfirmed) {
                var include = result.value && result.value.include ? '1' : '0';
                var url = href + (href.indexOf('?') === -1 ? '?' : '&') + 'include_summary=' + include;
                window.location.href = url;
            }
        });
    });

})();
</script>
