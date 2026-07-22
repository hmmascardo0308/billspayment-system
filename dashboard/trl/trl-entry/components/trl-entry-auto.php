<?php
$searchRef = trim((string) ($_GET['search_ref'] ?? ''));
$autoFound = null;
$autoError = '';
$subbillers = [];
$correctBillers = [];

if (isset($conn)) {
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
        <form id="autoEntryForm" method="post" action="controllers/trl-entry-insert.php" class="entry-form auto-entry-form" enctype="multipart/form-data" novalidate>
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
                                <option>Adjustment</option>
                                <option>Change Details</option>
                            </select>
                        </div>

                        <div class="field-group adjustment-type-group" style="display:none;">
                            <label for="autoAdjustmentType"><span class="material-icons">tune</span> Adjustment Type</label>
                            <select id="autoAdjustmentType" name="adjustment_type" class="field-input">
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
                            <label for="autoChangeDetailsType"><span class="material-icons">manage_accounts</span> Change Details Type</label>
                            <select id="autoChangeDetailsType" name="change_details_type" class="field-input">
                                <option value="">Select detail to change</option>
                                <option>WRONG ACCOUNT NAME</option>
                                <option>WRONG ACCOUNT NUMBER</option>
                                <option>WRONG PAYMENT TYPE</option>
                            </select>
                        </div>

                        <div class="change-detail-values" style="display:none;">
                            <div class="field-group">
                                <label for="autoWrongDetail"><span class="material-icons">edit_off</span> <span class="change-wrong-label">Wrong Detail</span></label>
                                <input id="autoWrongDetail" name="wrong_detail" class="field-input" type="text" placeholder="Enter current value">
                            </div>
                            <div class="field-group">
                                <label for="autoCorrectDetail"><span class="material-icons">edit_note</span> <span class="change-correct-label">Correct Detail</span></label>
                                <input id="autoCorrectDetail" name="correct_detail" class="field-input" type="text" placeholder="Enter correct value">
                            </div>
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
                            <input id="autoCorrectBillerName" name="correct_biller_name" class="field-input required-field" type="text" list="autoCorrectBillerDatalist" placeholder="Search biller or select..." required>
                            <datalist id="autoCorrectBillerDatalist">
                                <?php foreach ($correctBillers as $biller): ?>
                                    <option value="<?php echo htmlspecialchars((string) $biller['biller_name']); ?>"></option>
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

            <div class="trl-attachments-section">
                <h3>Attachments</h3>
                <div id="trlFileUploadArea" class="trl-file-upload-area" tabindex="0" role="button" aria-label="Select attachments">
                    <div class="trl-file-upload-icon"><i class="fa-solid fa-paperclip" aria-hidden="true"></i></div>
                    <div><strong>Drag &amp; drop files here</strong></div>
                    <div class="trl-file-upload-help">or click to browse</div>
                    <div class="trl-file-upload-help"><small>Supported: PNG, JPEG, JPG, GIF, WEBP, PDF, DOCX, TXT, XLSX, CSV, ODS</small></div>
                    <input type="file" id="trlAttachments" name="attachments[]" accept=".png,.jpeg,.jpg,.gif,.webp,.pdf,.docx,.txt,.xlsx,.csv,.ods" multiple hidden>
                </div>
                <div id="trlFilesContainer" class="trl-files-container" aria-live="polite">
                    <div class="trl-files-empty">No files selected.</div>
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
        foreach ($correctBillers as $biller) {
            $name = strtolower((string) ($biller['biller_name'] ?? ''));
            if ($name === '') continue;
            $bm[$name] = [
                'id' => (string) ($biller['biller_id'] ?? '')
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

<script>
(function () {
    var area = document.getElementById('trlFileUploadArea');
    var input = document.getElementById('trlAttachments');
    var container = document.getElementById('trlFilesContainer');
    if (!area || !input || !container) return;

    var selectedFiles = [];
    var allowedExtensions = ['png', 'jpeg', 'jpg', 'gif', 'webp', 'pdf', 'docx', 'txt', 'xlsx', 'csv', 'ods'];
    var maximumFiles = 10;
    var maximumFileSize = 10 * 1024 * 1024;

    function formatBytes(bytes) {
        if (!bytes) return '0 B';
        var units = ['B', 'KB', 'MB', 'GB'];
        var unitIndex = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
        return (bytes / Math.pow(1024, unitIndex)).toFixed(unitIndex === 0 ? 0 : 2) + ' ' + units[unitIndex];
    }

    function extensionOf(fileName) {
        var parts = String(fileName || '').toLowerCase().split('.');
        return parts.length > 1 ? parts.pop() : '';
    }

    function syncInputFiles() {
        var transfer = new DataTransfer();
        selectedFiles.forEach(function (file) { transfer.items.add(file); });
        input.files = transfer.files;
    }

    function renderFiles() {
        container.innerHTML = '';
        if (!selectedFiles.length) {
            var empty = document.createElement('div');
            empty.className = 'trl-files-empty';
            empty.textContent = 'No files selected.';
            container.appendChild(empty);
            return;
        }

        selectedFiles.forEach(function (file, index) {
            var card = document.createElement('div');
            card.className = 'trl-attachment-card';

            var details = document.createElement('div');
            details.className = 'trl-attachment-details';
            var name = document.createElement('strong');
            name.textContent = file.name;
            var size = document.createElement('small');
            size.textContent = formatBytes(file.size);
            details.appendChild(name);
            details.appendChild(size);

            var remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'trl-attachment-remove';
            remove.setAttribute('aria-label', 'Remove ' + file.name);
            remove.innerHTML = '<i class="fa-solid fa-trash" aria-hidden="true"></i>';
            remove.addEventListener('click', function () {
                selectedFiles.splice(index, 1);
                syncInputFiles();
                renderFiles();
            });

            card.appendChild(details);
            card.appendChild(remove);
            container.appendChild(card);
        });
    }

    function showAttachmentWarning(message) {
        if (typeof Swal !== 'undefined') {
            Swal.fire({ icon: 'warning', title: 'Attachment not added', text: message });
        }
    }

    function addFiles(fileList) {
        var incoming = Array.prototype.slice.call(fileList || []);
        for (var index = 0; index < incoming.length; index++) {
            var file = incoming[index];
            if (selectedFiles.length >= maximumFiles) {
                showAttachmentWarning('A maximum of 10 attachments is allowed.');
                break;
            }
            if (allowedExtensions.indexOf(extensionOf(file.name)) === -1) {
                showAttachmentWarning(file.name + ' is not a supported file type.');
                continue;
            }
            if (file.size > maximumFileSize) {
                showAttachmentWarning(file.name + ' exceeds the 10 MB file-size limit.');
                continue;
            }
            var duplicate = selectedFiles.some(function (selected) {
                return selected.name === file.name && selected.size === file.size && selected.lastModified === file.lastModified;
            });
            if (!duplicate) selectedFiles.push(file);
        }
        syncInputFiles();
        renderFiles();
    }

    area.addEventListener('click', function () { input.click(); });
    area.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            input.click();
        }
    });
    input.addEventListener('change', function () { addFiles(input.files); });
    area.addEventListener('dragover', function (event) {
        event.preventDefault();
        area.classList.add('drag-over');
    });
    area.addEventListener('dragleave', function () { area.classList.remove('drag-over'); });
    area.addEventListener('drop', function (event) {
        event.preventDefault();
        area.classList.remove('drag-over');
        addFiles(event.dataTransfer.files);
    });
})();
</script>
