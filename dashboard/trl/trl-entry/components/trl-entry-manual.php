<?php
// Load branches and subbillers for dropdowns when available
$branches = [];
$subbillers = [];
$correctBillers = [];
if (isset($conn)) {
    $branchSql = "SELECT branch_id, branch_name FROM masterdata.branch_profile WHERE branch_name IS NOT NULL AND TRIM(branch_name) <> '' ORDER BY branch_name ASC";
    $branchRes = $conn->query($branchSql);
    if ($branchRes) {
        while ($br = $branchRes->fetch_assoc()) {
            $branches[] = $br;
        }
    }

    $subSql = "SELECT subbiller_ext_id, subbiller_name, partner_ext_id FROM support_ticket.vw_mldb_subbillers ORDER BY subbiller_name ASC";
    $subRes = $conn->query($subSql);
    if ($subRes) {
        while ($sb = $subRes->fetch_assoc()) {
            $subbillers[] = $sb;
            $name = trim((string) ($sb['subbiller_name'] ?? ''));
            if ($name !== '') {
                $correctBillers[strtolower($name)] = [
                    'biller_id' => (string) ($sb['subbiller_ext_id'] ?? ''),
                    'biller_name' => $name
                ];
            }
        }
    }

    $directSql = "SELECT partner_id_kpx, partner_name FROM mldb.directbiller WHERE TRIM(COALESCE(partner_name, '')) <> '' ORDER BY partner_name ASC";
    $directRes = $conn->query($directSql);
    if ($directRes) {
        while ($direct = $directRes->fetch_assoc()) {
            $name = trim((string) ($direct['partner_name'] ?? ''));
            $key = strtolower($name);
            // Keep the subbiller match when the same name exists in both tables.
            if ($name !== '' && !isset($correctBillers[$key])) {
                $correctBillers[$key] = [
                    'biller_id' => (string) ($direct['partner_id_kpx'] ?? ''),
                    'biller_name' => $name
                ];
            }
        }
    }

    uasort($correctBillers, static function ($left, $right) {
        return strcasecmp((string) $left['biller_name'], (string) $right['biller_name']);
    });
}
?>
<section class="entry-block" id="manualModeBlock">
    <form id="manualEntryForm" method="post" action="controllers/trl-entry-insert.php" class="entry-form auto-entry-form manual-entry-form" novalidate>
        <input type="hidden" name="source_mode" value="manual">

        <div class="auto-content-grid">
            <!-- Left Column: Editable Transaction Details -->
            <div class="auto-data-column">
                <div class="auto-data-header">
                    <span class="material-icons">folder_open</span>
                    <h3>Transaction Details (Manual)</h3>
                    <div class="manual-ref-toggle" style="margin-left:12px;">
                        <div class="toggle-wrapper" style="display:flex;align-items:center;gap:8px;font-weight:600;">
                            <span style="font-size:13px;color:#334155">Include Reference No.</span>
                            <label class="switch" aria-label="Include Reference No.">
                                <input id="mRefToggle" name="include_ref_no" type="checkbox" value="1">
                                <span class="slider"></span>
                            </label>
                        </div>
                    </div>
                </div>
                <div class="auto-data-card">
                    <div class="data-item" data-ref-group style="display:none;">
                        <div class="data-icon"><span class="material-icons">confirmation_number</span></div>
                        <div class="data-content">
                            <span class="data-label">Reference No.</span>
                            <input id="mRefNo" name="ref_no" class="data-value field-input required-field" type="text" placeholder="Enter reference number">
                        </div>
                    </div>

                    <div class="data-item">
                        <div class="data-icon"><span class="material-icons">schedule</span></div>
                        <div class="data-content">
                            <span class="data-label">Transaction Date/Time</span>
                            <input id="mTransDate" name="transfer_datetime" class="data-value field-input required-field" type="datetime-local" required>
                        </div>
                    </div>

                    <div class="data-item">
                        <div class="data-icon"><span class="material-icons">account_balance</span></div>
                        <div class="data-content">
                            <span class="data-label">Account Number</span>
                            <input id="mAccountNo" name="account_no" class="data-value field-input required-field" type="text" placeholder="Enter account number" required>
                        </div>
                    </div>

                    <div class="data-item">
                        <div class="data-icon"><span class="material-icons">person</span></div>
                        <div class="data-content">
                            <span class="data-label">Account Name</span>
                            <input id="mName" name="name" class="data-value field-input required-field" type="text" placeholder="Enter account name" required>
                        </div>
                    </div>

                    <div class="data-group group-2">
                        <div class="data-item">
                            <div class="data-icon"><span class="material-icons">store</span></div>
                            <div class="data-content">
                                <span class="data-label">Payment Branch</span>
                                <input id="mBranchInput" name="payment_branch_name" class="data-value field-input required-field" list="mBranchDatalist" placeholder="Search branch or select...">
                                <datalist id="mBranchDatalist">
                                    <?php foreach ($branches as $b): ?>
                                        <option value="<?php echo htmlspecialchars((string) $b['branch_name']); ?>"></option>
                                    <?php endforeach; ?>
                                </datalist>
                            </div>
                        </div>

                        <div class="data-item">
                            <div class="data-icon"><span class="material-icons">business</span></div>
                            <div class="data-content">
                                <span class="data-label">Branch ID</span>
                                <input id="mBranchId" name="payment_branch_id" class="data-value field-input required-field" type="text" placeholder="Branch ID" readonly>
                            </div>
                        </div>

                        <div class="data-item">
                            <div class="data-icon"><span class="material-icons">warning</span></div>
                            <div class="data-content">
                                <span class="data-label">Biller Name</span>
                                <input id="mBillerInput" name="biller_name_display" class="data-value field-input required-field" list="mBillerDatalist" placeholder="Search subbiller or select...">
                                <datalist id="mBillerDatalist">
                                    <?php foreach ($subbillers as $sb): ?>
                                        <option value="<?php echo htmlspecialchars((string) $sb['subbiller_name']); ?>"></option>
                                    <?php endforeach; ?>
                                </datalist>
                            </div>
                        </div>

                        <div class="data-item">
                            <div class="data-icon"><span class="material-icons">business</span></div>
                            <div class="data-content">
                                <span class="data-label">Biller ID</span>
                                <input id="mBillerId" class="data-value field-input" type="text" placeholder="Biller ID" readonly>
                                <input type="hidden" id="mWrongBillerId" name="wrong_biller_id">
                                <input type="hidden" name="partner_ext_id" id="mPartnerExtId">
                                <input type="hidden" name="biller_name" id="mBillerName">
                            </div>
                        </div>
                    </div>

                    <div class="data-item">
                        <div class="data-icon"><span class="material-icons">attach_money</span></div>
                        <div class="data-content">
                            <span class="data-label">Amount</span>
                            <input id="mAmount" name="amount" class="data-value field-input required-field" type="number" min="0" step="0.01" placeholder="0.00" required>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column: Request Information -->
            <div class="auto-input-column">
                <div class="auto-input-header">
                    <span class="material-icons">edit_note</span>
                    <h3>Request Information</h3>
                </div>
                <div class="auto-input-card">
                    <div class="field-group">
                        <label for="mTypeRequest"><span class="material-icons">category</span> Type of Request</label>
                        <select id="mTypeRequest" name="type_of_request" class="field-input required-field" required>
                            <option value="">Select request type</option>
                            <option>Adjustment</option>
                            <option>Change Details</option>
                        </select>
                    </div>

                    <div class="field-group adjustment-type-group" style="display:none;">
                        <label for="mAdjustmentType"><span class="material-icons">tune</span> Adjustment Type</label>
                        <select id="mAdjustmentType" name="adjustment_type" class="field-input">
                            <option value="">Select adjustment type</option>
                            <option>NO PAYMENT RECEIVED</option>
                            <option>DOUBLE POSTING</option>
                            <option>MULTI POSTING</option>
                            <option>TRIPLE POSTING</option>
                            <option>WRONG BILLER</option>
                            <option>OVERSTATED AMOUNT</option>
                            <option>CANCELLED TRANSACTION</option>
                            <option>UNREFLECTED TRXN</option>
                        </select>
                    </div>

                    <div class="field-group change-details-type-group" style="display:none;">
                        <label for="mChangeDetailsType"><span class="material-icons">manage_accounts</span> Change Details Type</label>
                        <select id="mChangeDetailsType" name="change_details_type" class="field-input">
                            <option value="">Select detail to change</option>
                            <option>WRONG ACCOUNT NAME</option>
                            <option>WRONG ACCOUNT NUMBER</option>
                            <option>WRONG PAYMENT TYPE</option>
                        </select>
                    </div>

                    <div class="change-detail-values" style="display:none;">
                        <div class="field-group">
                            <label for="mWrongDetail"><span class="material-icons">edit_off</span> <span class="change-wrong-label">Wrong Detail</span></label>
                            <input id="mWrongDetail" name="wrong_detail" class="field-input" type="text" placeholder="Enter current value">
                        </div>
                        <div class="field-group">
                            <label for="mCorrectDetail"><span class="material-icons">edit_note</span> <span class="change-correct-label">Correct Detail</span></label>
                            <input id="mCorrectDetail" name="correct_detail" class="field-input" type="text" placeholder="Enter correct value">
                        </div>
                    </div>

                    <!-- Biller info moved to Transaction Details (manual) -->

                    <!-- OVERSTATED AMOUNT supplemental inputs -->
                    <div class="field-group overstated-group" style="display:none;">
                        <label for="mWrongAmount"><span class="material-icons">payments</span> Wrong Amount</label>
                        <input id="mWrongAmount" name="wrong_amount" class="field-input currency-input" type="text" inputmode="decimal" pattern="[0-9,\.\-]*" placeholder="0.00">
                    </div>

                    <div class="field-group overstated-group" style="display:none;">
                        <label for="mCorrectAmount"><span class="material-icons">payments</span> Correct Amount</label>
                        <input id="mCorrectAmount" name="correct_amount" class="field-input currency-input" type="text" inputmode="decimal" pattern="[0-9,\.\-]*" placeholder="0.00">
                    </div>

                    <div class="field-group overstated-group" style="display:none;">
                        <label for="mDifferenceValue"><span class="material-icons">calculate</span> Difference</label>
                        <input id="mDifferenceValue" name="difference_value" class="field-input currency-input" type="text" readonly placeholder="0.00">
                    </div>

                    <div class="field-group">
                        <label for="mCorrectBillerName"><span class="material-icons">business</span> Correct Biller Name</label>
                        <input id="mCorrectBillerName" name="correct_biller_name" class="field-input required-field" type="text" list="mCorrectBillerDatalist" placeholder="Search biller or select..." required>
                        <datalist id="mCorrectBillerDatalist">
                            <?php foreach ($correctBillers as $biller): ?>
                                <option value="<?php echo htmlspecialchars((string) $biller['biller_name']); ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>

                    <div class="field-group">
                        <label for="mCorrectBillerId"><span class="material-icons">check_circle</span> Correct Biller ID</label>
                        <input id="mCorrectBillerId" name="correct_biller_id" class="field-input required-field" type="text" placeholder="Auto-filled from biller name" readonly required>
                    </div>

                    <div class="field-group field-fullwidth">
                        <label for="mReason"><span class="material-icons">description</span> Reason for Request</label>
                        <textarea id="mReason" name="reason" class="field-input required-field" rows="4" placeholder="Provide detailed reason for this transaction request log entry" required></textarea>
                    </div>

                </div>
            </div>
        </div>
    </form>
</section>
    <script>
    (function () {
        function byId(id) { return document.getElementById(id); }

    // Maps for fast lookup (lowercased keys)
    var trlBranchMap = <?php
        $bmap = [];
        foreach ($branches as $b) {
            $name = strtolower((string) ($b['branch_name'] ?? ''));
            if ($name !== '') $bmap[$name] = (string) ($b['branch_id'] ?? '');
        }
        echo json_encode($bmap);
    ?>;

    var trlBillerMap = <?php
        $bm = [];
        foreach ($subbillers as $sb) {
            $name = strtolower((string) ($sb['subbiller_name'] ?? ''));
            if ($name === '') continue;
            $bm[$name] = [
                'id' => (string) ($sb['subbiller_ext_id'] ?? ''),
                'partner_ext_id' => (string) ($sb['partner_ext_id'] ?? '')
            ];
        }
        echo json_encode($bm);
    ?>;

    var trlCorrectBillerMap = <?php
        $correctBillerMap = [];
        foreach ($correctBillers as $biller) {
            $name = strtolower((string) ($biller['biller_name'] ?? ''));
            if ($name === '') continue;
            $correctBillerMap[$name] = [
                'id' => (string) ($biller['biller_id'] ?? '')
            ];
        }
        echo json_encode($correctBillerMap);
    ?>;

    // Branch input -> populate branch id
    var branchInput = byId('mBranchInput');
    var branchId = byId('mBranchId');
    if (branchInput && branchId) {
        function syncBranch() {
            var key = (branchInput.value || '').trim().toLowerCase();
            if (!key) { branchId.value = ''; return; }
            if (Object.prototype.hasOwnProperty.call(trlBranchMap, key)) {
                branchId.value = trlBranchMap[key] || '';
            } else {
                branchId.value = '';
            }
        }
        branchInput.addEventListener('input', syncBranch);
        branchInput.addEventListener('change', syncBranch);
        syncBranch();
    }

    // Biller input -> populate hidden wrong_biller_id, visible ID and partner_ext_id
    var billerInput = byId('mBillerInput');
    var billerIdHidden = byId('mWrongBillerId');
    var billerIdDisplay = byId('mBillerId');
    var partnerExt = byId('mPartnerExtId');
    var billerNameField = byId('mBillerName');

    if (billerInput) {
        function syncBiller() {
            var key = (billerInput.value || '').trim().toLowerCase();
            if (!key) {
                if (billerIdHidden) billerIdHidden.value = '';
                if (billerIdDisplay) billerIdDisplay.value = '';
                if (partnerExt) partnerExt.value = '';
                if (billerNameField) billerNameField.value = '';
                return;
            }
            var info = trlBillerMap[key] || null;
            if (info) {
                if (billerIdHidden) billerIdHidden.value = info.id || '';
                if (billerIdDisplay) billerIdDisplay.value = info.id || '';
                if (partnerExt) partnerExt.value = info.partner_ext_id || '';
                if (billerNameField) billerNameField.value = billerInput.value || '';
            } else {
                if (billerIdHidden) billerIdHidden.value = '';
                if (billerIdDisplay) billerIdDisplay.value = '';
                if (partnerExt) partnerExt.value = '';
                if (billerNameField) billerNameField.value = '';
            }
        }
        billerInput.addEventListener('input', syncBiller);
        billerInput.addEventListener('change', syncBiller);
        syncBiller();
    }

    // Correct biller name (wrong biller request) -> auto-fill correct biller id
    var correctBillerName = byId('mCorrectBillerName');
    var correctBillerId = byId('mCorrectBillerId');
    if (correctBillerName && correctBillerId) {
        function syncCorrectBiller() {
            var key = (correctBillerName.value || '').trim().toLowerCase();
            var info = trlCorrectBillerMap[key] || null;
            correctBillerId.value = info ? (info.id || '') : '';
        }

        correctBillerName.addEventListener('input', syncCorrectBiller);
        correctBillerName.addEventListener('change', syncCorrectBiller);
        syncCorrectBiller();
    }
})();
</script>
