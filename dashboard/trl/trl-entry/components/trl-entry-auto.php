<?php
$searchRef = trim((string) ($_GET['search_ref'] ?? ''));
$autoFound = null;
$autoError = '';
$subbillers = [];

if (isset($conn)) {
    $subSql = "SELECT subbiller_ext_id, subbiller_name, partner_ext_id FROM support_ticket.vw_mldb_subbillers ORDER BY subbiller_name ASC";
    $subRes = $conn->query($subSql);
    if ($subRes) {
        while ($sb = $subRes->fetch_assoc()) {
            $subbillers[] = $sb;
        }
    }
}

if ($searchRef !== '') {
    $escapedRef = mysqli_real_escape_string($conn, $searchRef);
    $sql = "SELECT reference_no, datetime, account_no, account_name, branch_id, outlet, amount_paid, sub_billers_name, sub_billers_id FROM mldb.billspayment_transaction WHERE reference_no = '{$escapedRef}' LIMIT 1";
    $res = mysqli_query($conn, $sql);
    if ($res && mysqli_num_rows($res) > 0) {
        $autoFound = mysqli_fetch_assoc($res);
    } else {
        $autoError = 'Reference number not found';
    }
}
?>

<section class="entry-block" id="autoModeBlock">
    <div class="auto-search-section">
        <form method="get" class="auto-search-form" autocomplete="off">
            <input type="hidden" name="mode" value="auto">
            <div class="search-input-wrapper">
                <span class="material-icons search-icon">search</span>
                <input id="searchRefNo" name="search_ref" class="auto-search-input" type="text" placeholder="Enter reference number" value="<?php echo htmlspecialchars($searchRef); ?>" required>
            </div>
            <button type="submit" class="btn btn-search">
                <span class="material-icons">check_circle</span>
                Search
            </button>
        </form>
    </div>

    <?php if ($autoError !== ''): ?>
        <div class="entry-alert alert-error">
            <span class="material-icons">error</span>
            <div class="alert-content">
                <strong>Not Found</strong>
                <p><?php echo htmlspecialchars($autoError); ?></p>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($autoFound): ?>
        <form id="autoEntryForm" method="post" action="controllers/trl-entry-insert.php" class="entry-form auto-entry-form" novalidate>
            <input type="hidden" name="source_mode" value="auto">
            <input type="hidden" name="ref_no" value="<?php echo htmlspecialchars((string) ($autoFound['reference_no'] ?? '')); ?>">
            <input type="hidden" name="transfer_datetime" value="<?php echo htmlspecialchars((string) ($autoFound['datetime'] ?? '')); ?>">
            <input type="hidden" name="account_no" value="<?php echo htmlspecialchars((string) ($autoFound['account_no'] ?? '')); ?>">
            <input type="hidden" name="name" value="<?php echo htmlspecialchars((string) ($autoFound['account_name'] ?? '')); ?>">
            <input type="hidden" name="payment_branch_id" value="<?php echo htmlspecialchars((string) ($autoFound['branch_id'] ?? '')); ?>">
            <input type="hidden" name="payment_branch_name" value="<?php echo htmlspecialchars((string) ($autoFound['outlet'] ?? '')); ?>">
            <input type="hidden" name="amount" value="<?php echo htmlspecialchars((string) ($autoFound['amount_paid'] ?? '0')); ?>">
            <input type="hidden" name="wrong_biller_id" value="<?php echo htmlspecialchars((string) ($autoFound['sub_billers_id'] ?? '')); ?>">
            <input type="hidden" name="biller_name" value="<?php echo htmlspecialchars((string) ($autoFound['sub_billers_name'] ?? '')); ?>">

            <div class="auto-content-grid">
                <div class="auto-data-column">
                    <div class="auto-data-header">
                        <span class="material-icons">folder_open</span>
                        <h3>Transaction Details</h3>
                    </div>
                    <div class="auto-data-card">
                        <div class="data-item">
                            <div class="data-icon"><span class="material-icons">confirmation_number</span></div>
                            <div class="data-content">
                                <span class="data-label">Reference No.</span>
                                <span class="data-value"><?php echo htmlspecialchars((string) ($autoFound['reference_no'] ?? '')); ?></span>
                            </div>
                        </div>

                        <div class="data-item">
                            <div class="data-icon"><span class="material-icons">schedule</span></div>
                            <div class="data-content">
                                <span class="data-label">Transaction Date/Time</span>
                                <span class="data-value"><?php echo htmlspecialchars((string) ($autoFound['datetime'] ?? '')); ?></span>
                            </div>
                        </div>

                        <div class="data-item">
                            <div class="data-icon"><span class="material-icons">account_balance</span></div>
                            <div class="data-content">
                                <span class="data-label">Account Number</span>
                                <span class="data-value"><?php echo htmlspecialchars((string) ($autoFound['account_no'] ?? '')); ?></span>
                            </div>
                        </div>

                        <div class="data-item">
                            <div class="data-icon"><span class="material-icons">person</span></div>
                            <div class="data-content">
                                <span class="data-label">Account Name</span>
                                <span class="data-value"><?php echo htmlspecialchars((string) ($autoFound['account_name'] ?? '')); ?></span>
                            </div>
                        </div>

                        <div class="data-item">
                            <div class="data-icon"><span class="material-icons">business</span></div>
                            <div class="data-content">
                                <span class="data-label">Branch ID</span>
                                <span class="data-value"><?php echo htmlspecialchars((string) ($autoFound['branch_id'] ?? '')); ?></span>
                            </div>
                        </div>

                        <div class="data-item">
                            <div class="data-icon"><span class="material-icons">store</span></div>
                            <div class="data-content">
                                <span class="data-label">Payment Branch</span>
                                <span class="data-value"><?php echo htmlspecialchars((string) ($autoFound['outlet'] ?? '')); ?></span>
                            </div>
                        </div>

                        <div class="data-item">
                            <div class="data-icon"><span class="material-icons">warning</span></div>
                            <div class="data-content">
                                <span class="data-label">Biller ID</span>
                                <span class="data-value"><?php echo htmlspecialchars((string) ($autoFound['sub_billers_id'] ?? '')); ?></span>
                            </div>
                        </div>

                        <div class="data-item">
                            <div class="data-icon"><span class="material-icons">business</span></div>
                            <div class="data-content">
                                <span class="data-label">Biller Name</span>
                                <span class="data-value"><?php echo htmlspecialchars((string) ($autoFound['sub_billers_name'] ?? '')); ?></span>
                            </div>
                        </div>

                        <div class="data-item">
                            <div class="data-icon"><span class="material-icons">attach_money</span></div>
                            <div class="data-content">
                                <span class="data-label">Amount</span>
                                <span class="data-value">₱ <?php echo number_format((float) ($autoFound['amount_paid'] ?? 0), 2); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="auto-input-column">
                    <div class="auto-input-header">
                        <span class="material-icons">edit_note</span>
                        <h3>Request Information</h3>
                    </div>
                    <div class="auto-input-card">
                        <div class="field-group">
                            <label for="autoTypeRequest"><span class="material-icons">category</span> Type of Request</label>
                            <select id="autoTypeRequest" name="type_of_request" class="field-input required-field" required>
                                <option value="">Select request type</option>
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

                        <!-- Biller info will be shown in Transaction Details (populated from the source transaction) -->

                        <!-- OVERSTATED AMOUNT supplemental inputs -->
                        <div class="field-group overstated-group" style="display:none;">
                            <label for="autoWrongAmount"><span class="material-icons">payments</span> Wrong Amount</label>
                            <input id="autoWrongAmount" name="wrong_amount" class="field-input currency-input" type="text" inputmode="decimal" pattern="[0-9,\.\-]*" placeholder="0.00">
                        </div>

                        <div class="field-group overstated-group" style="display:none;">
                            <label for="autoCorrectAmount"><span class="material-icons">payments</span> Correct Amount</label>
                            <input id="autoCorrectAmount" name="correct_amount" class="field-input currency-input" type="text" inputmode="decimal" pattern="[0-9,\.\-]*" placeholder="0.00">
                        </div>

                        <div class="field-group overstated-group" style="display:none;">
                            <label for="autoDifferenceValue"><span class="material-icons">calculate</span> Difference</label>
                            <input id="autoDifferenceValue" name="difference_value" class="field-input currency-input" type="text" readonly placeholder="0.00">
                        </div>

                        <div class="field-group">
                            <label for="autoCorrectBillerName"><span class="material-icons">business</span> Correct Biller Name</label>
                            <input id="autoCorrectBillerName" name="correct_biller_name" class="field-input required-field" type="text" list="autoCorrectBillerDatalist" placeholder="Search subbiller or select..." required>
                            <datalist id="autoCorrectBillerDatalist">
                                <?php foreach ($subbillers as $sb): ?>
                                    <option value="<?php echo htmlspecialchars((string) $sb['subbiller_name']); ?>"></option>
                                <?php endforeach; ?>
                            </datalist>
                        </div>

                        <div class="field-group">
                            <label for="autoCorrectBillerId"><span class="material-icons">check_circle</span> Correct Biller ID</label>
                            <input id="autoCorrectBillerId" name="correct_biller_id" class="field-input required-field" type="text" placeholder="Auto-filled from biller name" readonly required>
                        </div>

                        <div class="field-group field-fullwidth">
                            <label for="autoReason"><span class="material-icons">description</span> Reason for Request</label>
                            <textarea id="autoReason" name="reason" class="field-input required-field" rows="4" placeholder="Provide detailed reason for this transaction request log entry" required></textarea>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    <?php endif; ?>
</section>

<script>
(function () {
    function byId(id) { return document.getElementById(id); }

    var autoBillerMap = <?php
        $bm = [];
        foreach ($subbillers as $sb) {
            $name = strtolower((string) ($sb['subbiller_name'] ?? ''));
            if ($name === '') continue;
            $bm[$name] = [
                'id' => (string) ($sb['subbiller_ext_id'] ?? '')
            ];
        }
        echo json_encode($bm);
    ?>;

    var correctName = byId('autoCorrectBillerName');
    var correctId = byId('autoCorrectBillerId');
    if (!correctName || !correctId) return;

    function syncCorrectBiller() {
        var key = (correctName.value || '').trim().toLowerCase();
        var info = autoBillerMap[key] || null;
        correctId.value = info ? (info.id || '') : '';
    }

    correctName.addEventListener('input', syncCorrectBiller);
    correctName.addEventListener('change', syncCorrectBiller);
    syncCorrectBiller();
})();
</script>
