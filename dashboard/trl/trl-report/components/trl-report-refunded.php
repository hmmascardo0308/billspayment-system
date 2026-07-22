<?php
$masterfileIdSql = "CASE WHEN COALESCE(TRIM(partner_id_kpx), '') <> '' THEN CONVERT(TRIM(partner_id_kpx) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci ELSE CONVERT(TRIM(COALESCE(partner_id, '')) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci END";
$masterfileIdDSql = "CASE WHEN COALESCE(TRIM(d.partner_id_kpx), '') <> '' THEN CONVERT(TRIM(d.partner_id_kpx) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci ELSE CONVERT(TRIM(COALESCE(d.partner_id, '')) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci END";
$masterfileIdSSql = "CASE WHEN COALESCE(TRIM(s.partner_id_kpx), '') <> '' THEN CONVERT(TRIM(s.partner_id_kpx) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci ELSE CONVERT(TRIM(COALESCE(s.partner_id, '')) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci END";
$partners = [
    'all' => ['id' => '', 'name' => 'All', 'source' => 'all', 'label' => 'All']
];
$partnersSql = "
    SELECT DISTINCT
        CONVERT(CONCAT('subbiller:', TRIM(COALESCE(partner_id_kpx, ''))) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS partner_key,
        CONVERT(TRIM(COALESCE(partner_id_kpx, '')) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS partner_identifier,
        CONVERT(TRIM(COALESCE(partner_name, '')) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS partner_name,
        'subbiller' AS partner_source
    FROM mldb.subbiller
    WHERE COALESCE(TRIM(partner_id_kpx), '') <> ''
      AND COALESCE(TRIM(partner_name), '') <> ''
    UNION
    SELECT DISTINCT
        CONCAT('masterfile:', " . $masterfileIdSql . ") AS partner_key,
        " . $masterfileIdSql . " AS partner_identifier,
        CONVERT(TRIM(COALESCE(partner_name, '')) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS partner_name,
        'masterfile' AS partner_source
    FROM masterdata.partner_masterfile d
    WHERE " . $masterfileIdSql . " <> ''
      AND COALESCE(TRIM(partner_name), '') <> ''
      AND NOT EXISTS (
          SELECT 1
          FROM mldb.subbiller sb
          WHERE COALESCE(TRIM(sb.sub_billers_id), '') <> ''
            AND (
                CONVERT(TRIM(COALESCE(sb.partner_id_kpx, '')) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci = " . $masterfileIdDSql . "
                OR CONVERT(UPPER(TRIM(COALESCE(sb.partner_name, ''))) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci = CONVERT(UPPER(TRIM(COALESCE(d.partner_name, ''))) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci
            )
      )
    ORDER BY partner_name ASC
";

$partnersRes = mysqli_query($conn, $partnersSql);
if ($partnersRes) {
    while ($p = mysqli_fetch_assoc($partnersRes)) {
        $key = (string) ($p['partner_key'] ?? '');
        $pid = (string) ($p['partner_identifier'] ?? '');
        $pname = (string) ($p['partner_name'] ?? '');
        $source = (string) ($p['partner_source'] ?? 'subbiller');
        if (strcasecmp($pname, 'METROBANK RTA') === 0) {
            $pname = 'METROBANK REMIT TO ACCOUNT';
        }
        if ($key !== '' && $pid !== '' && $pname !== '') {
            $partners[$key] = [
                'id' => $pid,
                'name' => $pname,
                'source' => $source,
                'label' => $pname
            ];
        }
    }
}

$selectedPartnerId = isset($_GET['partner_id']) ? trim((string) $_GET['partner_id']) : '';
// Preserve compatibility with older links that used an unprefixed subbiller partner ID.
if ($selectedPartnerId !== '' && !isset($partners[$selectedPartnerId]) && isset($partners['subbiller:' . $selectedPartnerId])) {
    $selectedPartnerId = 'subbiller:' . $selectedPartnerId;
}
if ($selectedPartnerId !== '' && !isset($partners[$selectedPartnerId])) {
    $selectedPartnerId = '';
}

$selectedPartner = $selectedPartnerId !== '' ? $partners[$selectedPartnerId] : null;
$selectedPartnerName = $selectedPartner ? (string) $selectedPartner['label'] : '';

$rows = [];

// Determine which payment branch column exists
$branchColumn = 'payment_branch';
$colCheck = mysqli_query($conn, "SHOW COLUMNS FROM mldb.trl LIKE 'payment_branch'");
if (!$colCheck || mysqli_num_rows($colCheck) === 0) {
    $colCheck2 = mysqli_query($conn, "SHOW COLUMNS FROM mldb.trl LIKE 'payment_branch_name'");
    if ($colCheck2 && mysqli_num_rows($colCheck2) > 0) {
        $branchColumn = 'payment_branch_name';
    } else {
        $branchColumn = null;
    }
}

if ($branchColumn !== null) {
    $branchSelect = "t.{$branchColumn} AS payment_branch";
} else {
    $branchSelect = "'' AS payment_branch";
}

if ($selectedPartnerId !== '') {
    $selectedPartnerValue = (string) ($selectedPartner['id'] ?? '');
    $isAllPartners = (($selectedPartner['source'] ?? '') === 'all');
    $isPartnerMasterfile = (($selectedPartner['source'] ?? '') === 'masterfile');
    $partnerJoin = $isAllPartners
        ? ''
        : ($isPartnerMasterfile
        ? "INNER JOIN masterdata.partner_masterfile s
                ON CONVERT(TRIM(CAST(t.wrong_biller_id AS CHAR)) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci = " . $masterfileIdSSql . "
                OR (TRIM(COALESCE(t.biller_name, '')) <> '' AND CONVERT(UPPER(TRIM(t.biller_name)) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci = CONVERT(UPPER(TRIM(s.partner_name)) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci)"
        : "INNER JOIN mldb.subbiller s
                ON CAST(t.wrong_biller_id AS CHAR) = CAST(s.sub_billers_id AS CHAR)");
    $partnerWhere = $isAllPartners ? '' : ($isPartnerMasterfile
        ? $masterfileIdSSql . " = CONVERT(? USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AND"
        : 's.partner_id_kpx = ? AND');

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
                t.status,
                wb.correct_biller_id,
                wb.correct_biller_name,
                oa.wrong_amount AS oa_wrong_amount,
                oa.correct_amount AS oa_correct_amount,
                oa.difference AS oa_difference,
                ct.wrong_amount AS ct_wrong_amount,
                ct.correct_amount AS ct_correct_amount
            FROM mldb.trl t
            " . $partnerJoin . "
            LEFT JOIN mldb.trl_wrongbiller wb ON wb.trl_no = t.trl_no
            LEFT JOIN mldb.trl_overstatedamount oa ON oa.trl_no = t.trl_no
            LEFT JOIN mldb.trl_cancelledtransaction ct ON ct.trl_no = t.trl_no
            WHERE " . $partnerWhere . " t.status = 'REFUNDED'
            ORDER BY t.transfer_datetime DESC, t.trl_no DESC";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if (!$isAllPartners) {
            $stmt->bind_param('s', $selectedPartnerValue);
        }
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            while ($r = $result->fetch_assoc()) {
                $typeNorm = strtoupper(trim((string) ($r['type_of_request'] ?? '')));

                $wrongAmount = null; $correctAmount = null; $difference = null;
                if ($typeNorm === 'OVERSTATED AMOUNT') {
                    $wrongAmount = $r['oa_wrong_amount'];
                    $correctAmount = $r['oa_correct_amount'];
                    $difference = $r['oa_difference'];
                } elseif ($typeNorm === 'CANCELLED TRANSACTION') {
                    $wrongAmount = $r['ct_wrong_amount'];
                    $correctAmount = $r['ct_correct_amount'];
                }

                $rows[] = [
                    'trl_no' => (int) ($r['trl_no'] ?? 0),
                    'transfer_datetime' => (string) ($r['transfer_datetime'] ?? ''),
                    'ref_no' => (string) ($r['ref_no'] ?? ''),
                    'wrong_biller_id' => (string) ($r['wrong_biller_id'] ?? ''),
                    'biller_name' => (string) ($r['biller_name'] ?? ''),
                    'account_no' => (string) ($r['account_no'] ?? ''),
                    'name' => (string) ($r['name'] ?? ''),
                    'payment_branch_id' => (string) ($r['payment_branch_id'] ?? ''),
                    'payment_branch' => (string) ($r['payment_branch'] ?? ''),
                    'amount' => (float) ($r['amount'] ?? 0),
                    'type_of_request' => $typeNorm,
                    'correct_biller_id' => (string) ($r['correct_biller_id'] ?? ''),
                    'correct_biller_name' => (string) ($r['correct_biller_name'] ?? ''),
                    'wrong_amount' => $wrongAmount,
                    'correct_amount' => $correctAmount,
                    'difference_value' => $difference,
                    'reason' => (string) ($r['reason'] ?? ''),
                    'status' => (string) ($r['status'] ?? '')
                ];
            }
        }
        $stmt->close();
    }
}
?>

<div class="trl-refunded-card">
    <div class="trl-refunded-head">
        <h3>Refunded Transaction</h3>
        <p>List of refunded transactions for the selected partner.</p>
    </div>

    <form method="get" class="trl-summary-filters" id="refundedFilterForm">
        <input type="hidden" name="mode" value="refunded">
        <label for="partner_id_refunded">Partner</label>
        <div class="subbiller-dropdown partner-dropdown" id="partnerDropdownRefunded">
            <button type="button" id="partnerToggleRefunded" class="subbiller-toggle partner-toggle"><?php echo $selectedPartnerName !== '' ? htmlspecialchars($selectedPartnerName) : 'Select Partner'; ?> <i class="fa-solid fa-caret-down" aria-hidden="true"></i></button>
            <div class="subbiller-list partner-list" id="partnerListRefunded" aria-hidden="true">
                <div class="partner-search-wrap">
                    <input type="search" id="partnerSearchRefunded" class="partner-search" placeholder="Search partner..." aria-label="Search partners" autocomplete="off">
                </div>
                <?php foreach ($partners as $partnerKey => $partner): ?>
                    <button type="button" class="partner-item" data-value="<?php echo htmlspecialchars($partnerKey); ?>"><?php echo htmlspecialchars((string) $partner['label']); ?></button>
                <?php endforeach; ?>
            </div>
        </div>
        <input type="hidden" id="partner_id_refunded" name="partner_id" value="<?php echo htmlspecialchars($selectedPartnerId); ?>">
    </form>

    <?php if ($selectedPartnerId === ''): ?>
        <div class="trl-refunded-empty">Choose a partner to view refunded transactions.</div>
    <?php elseif (empty($rows)): ?>
        <div class="trl-refunded-empty">No refunded TRL rows found for the selected partner.</div>
    <?php else: ?>

        <div class="trl-summary-table-wrap">
            <table class="trl-summary-table">
                <thead>
                    <tr>
                        <th>TRL NO</th>
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
                        <th>CORRECT BILLER ID</th>
                        <th>CORRECT BILLER NAME</th>
                        <th>WRONG AMOUNT</th>
                        <th>CORRECT AMOUNT</th>
                        <th>DIFFERENCE</th>
                        <th>REASON</th>
                        <th>STATUS</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?php echo (int) $r['trl_no']; ?></td>
                            <td><?php echo htmlspecialchars((string) $r['transfer_datetime']); ?></td>
                            <td><?php echo htmlspecialchars((string) $r['ref_no']); ?></td>
                            <td><?php echo htmlspecialchars((string) $r['wrong_biller_id']); ?></td>
                            <td><?php echo htmlspecialchars((string) $r['biller_name']); ?></td>
                            <td><?php echo htmlspecialchars((string) $r['account_no']); ?></td>
                            <td><?php echo htmlspecialchars((string) $r['name']); ?></td>
                            <td><?php echo htmlspecialchars((string) $r['payment_branch_id']); ?></td>
                            <td><?php echo htmlspecialchars((string) $r['payment_branch']); ?></td>
                            <td class="amt"><?php echo number_format((float) $r['amount'], 2); ?></td>
                            <td><?php echo htmlspecialchars((string) $r['type_of_request']); ?></td>
                            <td><?php echo htmlspecialchars((string) $r['correct_biller_id']); ?></td>
                            <td><?php echo htmlspecialchars((string) $r['correct_biller_name']); ?></td>
                            <td class="amt"><?php echo $r['wrong_amount'] !== null ? number_format((float) $r['wrong_amount'], 2) : '-'; ?></td>
                            <td class="amt"><?php echo $r['correct_amount'] !== null ? number_format((float) $r['correct_amount'], 2) : '-'; ?></td>
                            <td class="amt"><?php echo $r['difference_value'] !== null ? number_format((float) $r['difference_value'], 2) : '-'; ?></td>
                            <td><?php echo htmlspecialchars((string) $r['reason']); ?></td>
                            <td><?php echo htmlspecialchars((string) $r['status']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script>
(function() {
    // Partner dropdown for Refunded (custom UI)
    var pToggle = document.getElementById('partnerToggleRefunded');
    var pList = document.getElementById('partnerListRefunded');
    var pInput = document.getElementById('partner_id_refunded');
    var pForm = document.getElementById('refundedFilterForm');
    var pSearch = document.getElementById('partnerSearchRefunded');

    if (pToggle && pList && pInput) {
        pToggle.addEventListener('click', function(e) {
            pList.classList.toggle('open');
            if (pList.classList.contains('open') && pSearch) {
                pSearch.value = '';
                pList.querySelectorAll('.partner-item').forEach(function(item) { item.hidden = false; });
                window.setTimeout(function() { pSearch.focus(); }, 0);
            }
            e.stopPropagation();
        });
        document.addEventListener('click', function(ev) {
            if (pList.classList.contains('open') && !pList.contains(ev.target) && !pToggle.contains(ev.target)) {
                pList.classList.remove('open');
            }
        });
        var pItems = pList.querySelectorAll('.partner-item');
        if (pSearch) {
            pSearch.addEventListener('click', function(e) { e.stopPropagation(); });
            pSearch.addEventListener('input', function() {
                var term = pSearch.value.trim().toLowerCase();
                pItems.forEach(function(item) {
                    item.hidden = item.textContent.toLowerCase().indexOf(term) === -1;
                });
            });
            pSearch.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') e.preventDefault();
                if (e.key === 'Escape') { pList.classList.remove('open'); pToggle.focus(); }
            });
        }
        pItems.forEach(function(it) {
            it.addEventListener('click', function() {
                var val = it.getAttribute('data-value') || '';
                pInput.value = val;
                pToggle.innerHTML = it.textContent + ' <i class="fa-solid fa-caret-down" aria-hidden="true"></i>';
                if (pForm) pForm.submit();
            });
        });
    }
})();
</script>

