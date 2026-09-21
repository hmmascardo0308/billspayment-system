<?php
// cad_loggings.php
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../vendor/autoload.php';

date_default_timezone_set('Asia/Manila');

session_start();
@include_once __DIR__ . '/../../../templates/middleware.php';
$id = resolve_user_identifier();
if (empty($id)) { header('Location: ../../../login_form.php'); exit; }
if (!function_exists('has_any_permission') || !has_any_permission(['CAD Loggings'])) { header('Location: ../../home.php'); exit; }

$current_user_email = $_SESSION['admin_email'] ?? $_SESSION['user_email'] ?? '';

// ---- Handle form POST ----
$flash_success = $_SESSION['cad_flash_success'] ?? '';
$flash_error   = $_SESSION['cad_flash_error']   ?? '';
unset($_SESSION['cad_flash_success'], $_SESSION['cad_flash_error']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bp_ref_no'])) {
    // Collect + normalize
    $payment_date      = trim($_POST['date_of_payment']        ?? '');
    $cancelled_date    = trim($_POST['date_cancelled']         ?? '');
    $region            = strtoupper(trim($_POST['regionname']          ?? ''));
    $branch_id         = trim($_POST['branchname']                     ?? '');  // raw ID
    $wrong_biller_id   = trim($_POST['wrong_biller']                   ?? '');  // raw partner_id_kpx
    $correct_biller_id = trim($_POST['correct_biller']                 ?? '');  // raw partner_id_kpx
    $bp_reference      = strtoupper(trim($_POST['bp_ref_no']           ?? ''));
    $account_no        = strtoupper(trim($_POST['account_no']          ?? ''));
    $account_name      = strtoupper(trim($_POST['account_name']        ?? ''));
    $amount_paid       = $_POST['amount'] ?? '';
    $ir_no             = strtoupper(trim($_POST['irno']                ?? ''));
    $reason            = strtoupper(trim($_POST['reason']              ?? ''));
    $vpo_remarks       = strtoupper(trim($_POST['vpo_remarks']         ?? ''));
    $cad_remarks       = strtoupper(trim($_POST['cad_status_remarks']  ?? ''));
    $req_id_number     = strtoupper(trim($_POST['requester_id']        ?? ''));
    $req_username      = strtoupper(trim($_POST['requester_username']  ?? ''));
    $req_fullname      = strtoupper(trim($_POST['requester_fullname']  ?? ''));
    $requested_date    = date('Y-m-d H:i:s');

    // Who performed the transaction (current logged-in user)
    $transacted_by     = strtoupper(trim(
        $_SESSION['admin_name'] ?? $_SESSION['user_name'] ?? 'SYSTEM'
    ));

    // Basic validation
    if ($payment_date === '' || $bp_reference === '' || $account_no === '' ||
        $account_name === '' || $amount_paid === '' || $req_id_number === '' ||
        $req_username === '' || $req_fullname === '') {
        $_SESSION['cad_flash_error'] = 'Please fill in all required fields.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    // ---- Duplicate check: block if bp_ref_no already logged ----
    $sqlDup = "SELECT id FROM cad_loggings.cad_loggings_requests WHERE bp_reference_no = ? LIMIT 1";
    if ($stmtDup = $conn->prepare($sqlDup)) {
        $stmtDup->bind_param('s', $bp_reference);
        $stmtDup->execute();
        $resDup = $stmtDup->get_result();
        $isDup  = ($resDup->num_rows > 0);
        $stmtDup->close();

        if ($isDup) {
            $_SESSION['cad_flash_error'] = 'This BP Ref. No. (' . $bp_reference . ') has already been logged.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
    }

    // ---- Map branch_id -> ml_matic_branch_name (outlet) ----
    $branch_name = $branch_id; // fallback to ID
    if ($branch_id !== '') {
        $sqlBranch = "SELECT ml_matic_branch_name
                      FROM masterdata.branch_profile
                      WHERE branch_id = ?
                      LIMIT 1";
        if ($stmtB = $conn->prepare($sqlBranch)) {
            $stmtB->bind_param('s', $branch_id);
            $stmtB->execute();
            $resB = $stmtB->get_result();
            if ($rowB = $resB->fetch_assoc()) {
                $branch_name = strtoupper(trim($rowB['ml_matic_branch_name']));
            }
            $stmtB->close();
        }
    }

    // ---- Map partner_id_kpx -> partner_name (wrong biller) ----
    $wrong_biller = $wrong_biller_id;
    if ($wrong_biller_id !== '') {
        $sqlP = "SELECT partner_name
                 FROM masterdata.partner_masterfile
                 WHERE partner_id_kpx = ?
                 LIMIT 1";
        if ($stmtP = $conn->prepare($sqlP)) {
            $stmtP->bind_param('s', $wrong_biller_id);
            $stmtP->execute();
            $resP = $stmtP->get_result();
            if ($rowP = $resP->fetch_assoc()) {
                $wrong_biller = strtoupper(trim($rowP['partner_name']));
            }
            $stmtP->close();
        }
    }

    // ---- Map partner_id_kpx -> partner_name (correct biller) ----
    $correct_biller = $correct_biller_id;
    if ($correct_biller_id !== '') {
        $sqlP2 = "SELECT partner_name
                  FROM masterdata.partner_masterfile
                  WHERE partner_id_kpx = ?
                  LIMIT 1";
        if ($stmtP2 = $conn->prepare($sqlP2)) {
            $stmtP2->bind_param('s', $correct_biller_id);
            $stmtP2->execute();
            $resP2 = $stmtP2->get_result();
            if ($rowP2 = $resP2->fetch_assoc()) {
                $correct_biller = strtoupper(trim($rowP2['partner_name']));
            }
            $stmtP2->close();
        }
    }

    // amount sanitization — always stored as positive decimal
    $amount_paid = abs((float)$amount_paid);

    // Empty date -> NULL
    $cancelled_date_val = ($cancelled_date === '') ? null : $cancelled_date;

    // Insert (now with *both* IDs and names)
    $sql = "INSERT INTO cad_loggings.cad_loggings_requests (
                payment_date,
                cancelled_date,
                region,
                branch_id,
                branch_name,
                wrong_biller_id,
                wrong_biller,
                correct_biller_id,
                correct_biller,
                bp_reference_no,
                account_no,
                account_name,
                amount_paid,
                ir_no,
                reason,
                vpo_remarks,
                cad_remarks,
                requested_by_id_number,
                requested_by_username,
                requested_by_name,
                requested_date,
                transacted_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    try {
        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param(
                'ssssssssssssdsssssssss',
                $payment_date,
                $cancelled_date_val,
                $region,
                $branch_id,
                $branch_name,
                $wrong_biller_id,
                $wrong_biller,
                $correct_biller_id,
                $correct_biller,
                $bp_reference,
                $account_no,
                $account_name,
                $amount_paid,
                $ir_no,
                $reason,
                $vpo_remarks,
                $cad_remarks,
                $req_id_number,
                $req_username,
                $req_fullname,
                $requested_date,
                $transacted_by
            );

            if ($stmt->execute()) {
                $_SESSION['cad_flash_success'] = 'CAD log saved successfully.';
            } else {
                $_SESSION['cad_flash_error'] = 'Execute failed: ' . $stmt->error;
            }
            $stmt->close();
        } else {
            $_SESSION['cad_flash_error'] = 'Failed to prepare statement: ' . $conn->error;
        }
    } catch (Exception $e) {
        error_log('cad_loggings insert error: ' . $e->getMessage());
        $_SESSION['cad_flash_error'] = 'INSERT error: ' . $e->getMessage();
    }

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// ---- Fetch dropdown data ----
$regions = [];
$branches = [];
$billers = [];

try {
    $sql = "SELECT DISTINCT gl_region FROM masterdata.branch_profile WHERE gl_region IS NOT NULL AND gl_region <> '' ORDER BY gl_region ASC";
    if ($result = $conn->query($sql)) {
        while ($row = $result->fetch_assoc()) { $regions[] = $row['gl_region']; }
        $result->free();
    }

    $sql = "SELECT DISTINCT branch_id, ml_matic_branch_name, branch_type, gl_region FROM masterdata.branch_profile WHERE branch_type in ('Showroom', 'Branch') ORDER BY ml_matic_branch_name ASC";
    if ($result = $conn->query($sql)) {
        while ($row = $result->fetch_assoc()) { $branches[] = $row; }
        $result->free();
    }

    $sql = "SELECT DISTINCT partner_id_kpx, partner_name FROM masterdata.partner_masterfile WHERE partner_id_kpx IS NOT NULL AND partner_id_kpx <> '' ORDER BY partner_name ASC";
    if ($result = $conn->query($sql)) {
        while ($row = $result->fetch_assoc()) { $billers[] = $row; }
        $result->free();
    }
} catch (Exception $e) {
    error_log('CAD Loggings dropdown fetch error: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CAD Loggings | <?php if($_SESSION['user_type'] === 'admin' || $_SESSION['user_type'] === 'user') echo ucfirst($_SESSION['user_type']); else echo "Guest";?></title>
    <link rel="stylesheet" href="../../../assets/css/templates/style.css?v=<?php echo time(); ?>">
    <script src="https://kit.fontawesome.com/30b908cc5a.js" crossorigin="anonymous"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="../../../assets/js/sweetalert2.all.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet" />
    <link rel="icon" href="../../../images/MLW logo.png" type="image/png">
    <link rel="stylesheet" href="css/cad.css?v=<?= time(); ?>">
   
</head>
<body>
    <div class="main-container">
        <?php include '../../../templates/header_ui.php'; ?>
        <?php include '../../../templates/sidebar.php'; ?>

        <div class="bp-section-header" role="region" aria-label="Page title">
            <div class="bp-section-title">
                <i class="fa-solid fa-layer-group" aria-hidden="true"></i>
                <div><h2>CAD Loggings</h2></div>
            </div>
        </div>

        <div class="cad-form-container">
            <div class="cad-form-card">
                <form id="cadLoggingForm" method="POST" action="">
                    <div class="cad-form-grid">

                        <div class="cad-form-group bp-ref-wrap">
                            <label for="bp_ref_no">BP Ref. No.:</label>
                            <select id="bp_ref_no" name="bp_ref_no" class="bp-ref-select" required
                                    data-placeholder="Type to search or enter BP Ref. No..." style="width:100%;"></select>
                            <i class="fa-solid fa-spinner fa-spin bp-ref-spinner" id="bpRefSpinner"></i>
                        </div>

                        <div class="cad-form-group">
                            <label for="wrong_biller">Wrong Biller:</label>
                            <select id="wrong_biller" name="wrong_biller" class="cad-select"
                                    data-placeholder="-- Select Partner (Partner ID / Partner Name) --">
                                <option value=""></option>
                                <?php foreach ($billers as $biller): ?>
                                    <option value="<?= htmlspecialchars($biller['partner_id_kpx'], ENT_QUOTES) ?>">
                                        <?= htmlspecialchars($biller['partner_id_kpx'] . ' - ' . $biller['partner_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="cad-form-group">
                            <label for="date_of_payment">Date of Payment:</label>
                            <input type="date" id="date_of_payment" name="date_of_payment" required>
                        </div>

                        <div class="cad-form-group">
                            <label for="date_cancelled">Date Cancelled:</label>
                            <input type="date" id="date_cancelled" name="date_cancelled">
                        </div>

                        <div class="cad-form-group">
                            <label for="regionname">Region Name:</label>
                            <select id="regionname" name="regionname" class="cad-select" required
                                    data-placeholder="-- Select Region --">
                                <option value=""></option>
                                <?php foreach ($regions as $region): ?>
                                    <option value="<?= htmlspecialchars($region, ENT_QUOTES) ?>">
                                        <?= htmlspecialchars($region) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="cad-form-group">
                            <label for="branchname">Branch Name:</label>
                            <select id="branchname" name="branchname" class="cad-select" required
                                    data-placeholder="-- Select Branch (Branch ID / Branch Name) --">
                                <option value=""></option>
                                <?php foreach ($branches as $branch): ?>
                                    <option value="<?= htmlspecialchars($branch['branch_id'], ENT_QUOTES) ?>"
                                            data-region="<?= htmlspecialchars($branch['gl_region'] ?? '', ENT_QUOTES) ?>">
                                        <?= htmlspecialchars($branch['branch_id'] . ' - ' . $branch['ml_matic_branch_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="cad-form-group">
                            <label for="amount">Amount:</label>
                            <input type="number" id="amount" name="amount" step="0.01" min="0" placeholder="0.00" required>
                        </div>

                        <div class="cad-form-group">
                            <label for="account_no">Account No.:</label>
                            <input type="text" id="account_no" name="account_no" placeholder="Enter account number" required>
                        </div>

                        <div class="cad-form-group">
                            <label for="account_name">Account Name:</label>
                            <input type="text" id="account_name" name="account_name" placeholder="Enter account name" required>
                        </div>

                        <div class="cad-form-group">
                            <label for="correct_biller">Correct Biller:</label>
                            <select id="correct_biller" name="correct_biller" class="cad-select"
                                    data-placeholder="-- Select Partner (Partner ID / Partner Name) --">
                                <option value=""></option>
                                <?php foreach ($billers as $biller): ?>
                                    <option value="<?= htmlspecialchars($biller['partner_id_kpx'], ENT_QUOTES) ?>">
                                        <?= htmlspecialchars($biller['partner_id_kpx'] . ' - ' . $biller['partner_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="cad-form-group">
                            <label for="irno">IR No.:</label>
                            <input type="text" id="irno" name="irno" placeholder="Enter IR Number">
                        </div>

                        <div class="cad-form-group full-width">
                            <label for="reason">Reason:</label>
                            <textarea id="reason" name="reason" placeholder="Enter reason for cancellation / adjustment"></textarea>
                        </div>

                        <div class="cad-form-group full-width">
                            <label for="vpo_remarks">VPO Remarks:</label>
                            <textarea id="vpo_remarks" name="vpo_remarks" placeholder="Enter VPO remarks"></textarea>
                        </div>

                        <div class="cad-form-group full-width">
                            <label for="cad_status_remarks">CAD Status/Remarks:</label>
                            <textarea id="cad_status_remarks" name="cad_status_remarks" placeholder="Enter CAD status or remarks"></textarea>
                        </div>

                        <!-- Requester — separate manually-typed fields, auto-uppercased -->
                        <div class="cad-form-group">
                            <label for="requester_id">Requester ID No.:</label>
                            <input type="text" id="requester_id" name="requester_id"
                                   placeholder="Enter ID Number"
                                   style="text-transform: uppercase;"
                                   oninput="this.value = this.value.toUpperCase();"
                                   required>
                        </div>

                        <div class="cad-form-group">
                            <label for="requester_username">Requester Username:</label>
                            <input type="text" id="requester_username" name="requester_username"
                                   placeholder="Enter Username"
                                   style="text-transform: uppercase;"
                                   oninput="this.value = this.value.toUpperCase();"
                                   required>
                        </div>

                        <div class="cad-form-group full-width">
                            <label for="requester_fullname">Requester Full Name:</label>
                            <input type="text" id="requester_fullname" name="requester_fullname"
                                   placeholder="Enter Full Name"
                                   style="text-transform: uppercase;"
                                   oninput="this.value = this.value.toUpperCase();"
                                   required>
                        </div>

                    </div>

                    <div class="cad-form-actions">
                        <button type="reset" class="cad-btn cad-btn-secondary">Clear</button>
                        <button type="submit" class="cad-btn cad-btn-primary">
                            <i class="fa-solid fa-save"></i> Save Log
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php include '../../../templates/footer.php'; ?>

    <script>
        $(document).ready(function() {
            // ---- Init Select2 on regular dropdowns ----
            $('.cad-select').each(function() {
                var $el = $(this);
                $el.select2({
                    width: '100%',
                    placeholder: $el.data('placeholder') || '-- Select --',
                    allowClear: true
                });
            });

            // ---- Region -> Branch filtering ----
            var $regionSelect = $('#regionname');
            var $branchSelect = $('#branchname');

            var allBranchOptions = [];
            $branchSelect.find('option').each(function() {
                allBranchOptions.push({
                    value: $(this).val(),
                    text: $(this).text(),
                    region: $(this).data('region')
                });
            });

            function filterBranches() {
                var selectedRegion = $regionSelect.val();
                $branchSelect.select2('destroy');
                $branchSelect.empty();
                $branchSelect.append('<option value=""></option>');

                $.each(allBranchOptions, function(i, opt) {
                    if (opt.value === '') return;
                    if (!selectedRegion || opt.region === selectedRegion) {
                        $branchSelect.append(
                            $('<option>', { value: opt.value, text: opt.text })
                                .attr('data-region', opt.region)
                        );
                    }
                });

                $branchSelect.select2({
                    width: '100%',
                    placeholder: $branchSelect.data('placeholder') || '-- Select Branch --',
                    allowClear: true
                });
            }

            $regionSelect.on('change', filterBranches);
            filterBranches();

            // ---- BP Ref No. — AJAX autocomplete + free text + duplicate check ----
            var $bpRef   = $('#bp_ref_no');
            var $spinner = $('#bpRefSpinner');

            $bpRef.select2({
                width: '100%',
                placeholder: $bpRef.data('placeholder') || 'Type to search or enter BP Ref. No...',
                allowClear: true,
                minimumInputLength: 2,
                tags: true,
                createTag: function(params) {
                    var term = $.trim(params.term);
                    if (term === '') return null;
                    return { id: term, text: term, newTag: true };
                },
                ajax: {
                    url: 'search_bp_ref.php',
                    dataType: 'json',
                    delay: 300,
                    data: function(params) { return { q: params.term }; },
                    processResults: function(data) { return { results: data.results || [] }; },
                    cache: true
                }
            });

            var lastRef = '';

            function checkDuplicateThenLookup(refNo) {
                if (!refNo) return;
                $spinner.show();

                // 1) Duplicate check against cad_loggings_requests
                $.ajax({
                    url: 'fetch_bp_ref.php',
                    type: 'GET',
                    dataType: 'json',
                    data: { ref_no: refNo, mode: 'check' },
                    success: function(chk) {
                        if (chk && chk.duplicate) {
                            $spinner.hide();
                            Swal.fire({
                                icon: 'warning',
                                title: 'Already Logged',
                                text: chk.message || 'This BP Ref. No. has already been logged.'
                            });
                            $bpRef.val(null).trigger('change.select2');
                            lastRef = '';
                            return;
                        }

                        // 2) Auto-fill from billspayment_transaction
                        $.ajax({
                            url: 'fetch_bp_ref.php',
                            type: 'GET',
                            dataType: 'json',
                            data: { ref_no: refNo },
                            success: function(res) {
                                $spinner.hide();

                                if (!res || !res.found) {
                                    Swal.fire({
                                        icon: 'info',
                                        title: 'Not Found',
                                        html: 'BP Ref. No. <b>' + refNo + '</b> was not found in the Billspayment Transaction table.<br><br>' +
                                              'You may still continue and encode the remaining details manually.',
                                        confirmButtonText: 'Continue Manually'
                                    });
                                    return;
                                }

                                var d = res.data;

                                if (d.date_of_payment) {
                                    $('#date_of_payment').val(String(d.date_of_payment).substring(0, 10));
                                }
                                if (d.date_cancelled) {
                                    $('#date_cancelled').val(String(d.date_cancelled).substring(0, 10));
                                }
                                if (d.wrong_biller) {
                                    setSelectValue($('#wrong_biller'), d.wrong_biller);
                                }
                                if (d.regionname) {
                                    setSelectValue($('#regionname'), d.regionname);
                                    filterBranches();
                                }
                                if (d.branchname) {
                                    setSelectValue($('#branchname'), d.branchname);
                                }
                                if (d.amount !== null && d.amount !== undefined) {
                                    $('#amount').val(d.amount);
                                }
                                if (d.account_no)   $('#account_no').val(d.account_no);
                                if (d.account_name) $('#account_name').val(d.account_name);

                                Swal.fire({
                                    icon: 'success',
                                    title: 'Auto-filled',
                                    text: 'Transaction details loaded.',
                                    timer: 1300,
                                    showConfirmButton: false
                                });
                            },
                            error: function(xhr) {
                                $spinner.hide();
                                var msg = 'Lookup failed.';
                                try { msg = JSON.parse(xhr.responseText).message || msg; } catch(e) {}
                                Swal.fire({ icon: 'error', title: 'Error', text: msg });
                            }
                        });
                    },
                    error: function(xhr) {
                        $spinner.hide();
                        Swal.fire({ icon: 'error', title: 'Error', text: 'Duplicate check failed.' });
                    }
                });
            }

            function setSelectValue($select, value) {
                if (value === null || value === undefined || value === '') return;
                if ($select.find('option[value="' + value + '"]').length === 0) {
                    $select.append($('<option>', { value: value, text: value }));
                }
                $select.val(value).trigger('change.select2');
            }

            // Fires once a suggestion is picked OR a new tag is created
            $bpRef.on('select2:select', function(e) {
                var refNo = e.params.data.id;
                if (!refNo) return;
                if (refNo === lastRef) return;
                lastRef = refNo;
                checkDuplicateThenLookup(refNo);
            });

            // Flash messages
            <?php if ($flash_success): ?>
            Swal.fire({
                icon: 'success',
                title: 'Saved',
                text: <?= json_encode($flash_success) ?>,
                timer: 1800,
                showConfirmButton: false
            });
            <?php endif; ?>

            <?php if ($flash_error): ?>
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: <?= json_encode($flash_error) ?>
            });
            <?php endif; ?>

            document.getElementById('cadLoggingForm').addEventListener('submit', function(e) {
                // Let it submit normally (POST to self)
            });
        });
    </script>
</body>
</html>