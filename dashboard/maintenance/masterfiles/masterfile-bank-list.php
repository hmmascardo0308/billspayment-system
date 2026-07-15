<?php
// Connect to the database
require_once __DIR__ . '/../../../config/config.php';

require '../../../vendor/autoload.php';

// Start the session
session_start();

$current_user_email = '';

if (isset($_SESSION['user_type'])) {
    if ($_SESSION['user_type'] === 'admin' && isset($_SESSION['admin_email'])) {
        $current_user_email = $_SESSION['admin_email'];
    } elseif ($_SESSION['user_type'] === 'user' && isset($_SESSION['user_email'])) {
        $current_user_email = $_SESSION['user_email'];
    }
}

// get table display for banks
if (isset($_POST['action']) && $_POST['action'] === 'generate_bank_list') {
    header('Content-Type: application/json');

    try {
        $bankQuery = 'SELECT * FROM mldb.bank_table ORDER BY bank_name';
        $stmt = $conn->prepare($bankQuery);

        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $conn->error);
        }

        $stmt->execute();
        $result = $stmt->get_result();

        $banks = [];
        while ($row = $result->fetch_assoc()) {
            $banks[] = $row;
        }

        $stmt->close();

        echo json_encode([
            'status' => 'success',
            'data' => $banks
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

// saved bank data
if (isset($_POST['action']) && $_POST['action'] === 'saved_bank_data') {
    header('Content-Type: application/json');

    try {
        $bankName = trim($_POST['bank_name'] ?? '');
        $bankAbbreviation = trim($_POST['bank_abbreviation'] ?? '');
        $settlementType = trim($_POST['settled_online_check'] ?? '');
        $dateCreated = date('Y-m-d');
        $createdBy = $current_user_email !== '' ? $current_user_email : 'system';

        if ($bankName === '' || $bankAbbreviation === '' || $settlementType === '') {
            throw new Exception('All fields are required.');
        }

        $insertQuery = "INSERT INTO mldb.bank_table SET bank_name=?, bank_abbreviation=?, settled_online_check=?, used_unused='used', date_created=?, created_by=?";
        $stmt = $conn->prepare($insertQuery);

        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $conn->error);
        }

        $stmt->bind_param('sssss', $bankName, $bankAbbreviation, $settlementType, $dateCreated, $createdBy);

        if (!$stmt->execute()) {
            throw new Exception('Execute failed: ' . $stmt->error);
        }

        $stmt->close();

        echo json_encode([
            'status' => 'success',
            'message' => 'Inserted Successfully'
        ]);
        exit();
    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Save failed: ' . $e->getMessage()
        ]);
        exit();
    }

}

// update bank data
if (isset($_POST['action']) && $_POST['action'] === 'update_bank_data') {
    header('Content-Type: application/json');

    try {
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $originalBankName = trim($_POST['original_bank_name'] ?? '');
        $updatedBankName = trim($_POST['updated_bank_name'] ?? '');
        $updatedBankAbbreviation = trim($_POST['updated_bank_abbreviation'] ?? '');
        $updatedSettlementType = trim($_POST['updated_settled_online_check'] ?? '');
        $updatedAvailableService = trim($_POST['updated_used_unused'] ?? '');
        $modifiedDate = date('Y-m-d');
        $modifiedBy = $current_user_email !== '' ? $current_user_email : 'system';

        if (
            $id <= 0 ||
            $originalBankName === '' ||
            $updatedBankName === '' ||
            $updatedBankAbbreviation === '' ||
            $updatedSettlementType === '' ||
            $updatedAvailableService === ''
        ) {
            throw new Exception('All fields are required.');
        }

        $conn->begin_transaction();

        if ($updatedBankName !== $originalBankName) {
            $updateBankNameQuery = 'UPDATE mldb.bank_table SET bank_name = ?, date_modified=?, modified_by=? WHERE bank_name = ?';
            $stmtBankName = $conn->prepare($updateBankNameQuery);

            if (!$stmtBankName) {
                throw new Exception('Prepare failed: ' . $conn->error);
            }

            $stmtBankName->bind_param('ssss', $updatedBankName, $modifiedDate, $modifiedBy, $originalBankName);

            if (!$stmtBankName->execute()) {
                throw new Exception('Execute failed: ' . $stmtBankName->error);
            }

            $stmtBankName->close();

            $updatePartnerBankQuery = 'UPDATE masterdata.partner_masterfile SET bank = ? WHERE bank = ?';
            $stmtPartnerBank = $conn->prepare($updatePartnerBankQuery);

            if (!$stmtPartnerBank) {
                throw new Exception('Prepare failed: ' . $conn->error);
            }

            $stmtPartnerBank->bind_param('ss', $updatedBankName, $originalBankName);

            if (!$stmtPartnerBank->execute()) {
                throw new Exception('Execute failed: ' . $stmtPartnerBank->error);
            }

            $stmtPartnerBank->close();
        }

        $updateOtherFieldsQuery = 'UPDATE mldb.bank_table SET bank_abbreviation = ?, settled_online_check = ?, used_unused = ?, date_modified = ?, modified_by = ? WHERE id = ?';
        $stmtOtherFields = $conn->prepare($updateOtherFieldsQuery);

        if (!$stmtOtherFields) {
            throw new Exception('Prepare failed: ' . $conn->error);
        }

        $stmtOtherFields->bind_param('sssssi', $updatedBankAbbreviation, $updatedSettlementType, $updatedAvailableService, $modifiedDate, $modifiedBy, $id);

        if (!$stmtOtherFields->execute()) {
            throw new Exception('Execute failed: ' . $stmtOtherFields->error);
        }

        $stmtOtherFields->close();

        $conn->commit();

        echo json_encode([
            'status' => 'success',
            'message' => 'Updated Successfully'
        ]);
        exit();
    } catch (Exception $e) {
        $conn->rollback();

        echo json_encode([
            'status' => 'error',
            'message' => 'Update failed: ' . $e->getMessage()
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
    <title>Bank List | <?php if($_SESSION['user_type'] === 'admin' || $_SESSION['user_type'] === 'user') echo ucfirst($_SESSION['user_type']); else echo "Guest";?></title>
    <!-- custom CSS file link  -->
    <link rel="stylesheet" href="../../../assets/css/templates/style.css?v=<?php echo time(); ?>">
    <script src="https://kit.fontawesome.com/30b908cc5a.js" crossorigin="anonymous"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../../assets/js/sweetalert2.all.min.js"></script>

    <link rel="icon" href="../../../images/MLW logo.png" type="image/png">
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
                    <h2>Bank List</h2>
                    <!-- <p class="bp-section-sub">Sample Description</p> -->
                </div>
            </div>
        </div>
        <div class="container-fluid">
            <div class="row">
                <div class="col-md-18">
                    <div class="card">
                        <div class="card-header">
                            <div class="row g-2 align-items-end justify-content-between">
                                <div class="col-auto d-flex gap-2">
                                    <button type="button" id="addBankButton" class="btn btn-danger" onclick="AddRow()">Add</button>
                                    <button type="button" id="editBankButton" class="btn btn-secondary" onclick="EditRow()" disabled>Edit</button>
                                </div>
                                <div class="col-md-6 ms-auto">
                                    <label for="searchInput" class="form-label mb-1">Search Bank</label>
                                    <input
                                        type="text"
                                        id="searchInput"
                                        class="form-control"
                                        placeholder="Search by any field..."
                                        list="searchSuggestions"
                                    >
                                    <datalist id="searchSuggestions">
                                        <!-- Search suggestions will be populated dynamically at javascript -->
                                    </datalist>
                                </div>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-striped table-hover" id="bankTable">
                                    <thead>
                                        <tr>
                                            <th scope="col">Bank Legal Name</th>
                                            <th scope="col">Bank Abbreviation</th>
                                            <th scope="col">No. of Partner has Registered</th>
                                            <th scope="col">Settlement Type</th>
                                            <th scope="col">Date Created</th>
                                            <th scope="col">Modified Date</th>
                                            <th scope="col">Available Service</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <!-- Table rows will be populated dynamically at javascript -->
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="addBankModal" tabindex="-1" aria-labelledby="addBankModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addBankModalLabel">Add Bank</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="addBankForm" autocomplete="off">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="bankLegalName" class="form-label">Bank Legal Name</label>
                            <input type="text" id="bankLegalName" name="bank_legal_name" class="form-control" placeholder="Enter bank legal name" required>
                        </div>
                        <div class="mb-3">
                            <label for="bankAbbreviation" class="form-label">Bank Abbreviation</label>
                            <input type="text" id="bankAbbreviation" name="bank_abbreviation" class="form-control" placeholder="Enter bank abbreviation" required>
                        </div>
                        <div class="mb-0">
                            <label for="settlementType" class="form-label">Settlement Type</label>
                            <select id="settlementType" name="settlement_type" class="form-select" required>
                                <option value="" selected disabled>Select settlement type</option>
                                <option value="ONLINE">ONLINE</option>
                                <option value="CHECK">CHECK</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-danger">Save</button>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="editBankModal" tabindex="-1" aria-labelledby="editBankModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editBankModalLabel">Edit Bank</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="editBankForm" autocomplete="off">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="editBankLegalName" class="form-label">Bank Legal Name</label>
                            <input type="text" id="editBankLegalName" name="edit_bank_legal_name" class="form-control" placeholder="Enter bank legal name" required>
                        </div>
                        <div class="mb-3">
                            <label for="editBankAbbreviation" class="form-label">Bank Abbreviation</label>
                            <input type="text" id="editBankAbbreviation" name="edit_bank_abbreviation" class="form-control" placeholder="Enter bank abbreviation" required>
                        </div>
                        <div class="mb-3">
                            <label for="editSettlementType" class="form-label">Settlement Type</label>
                            <select id="editSettlementType" name="edit_settlement_type" class="form-select" required>
                                <option value="" selected disabled>Select settlement type</option>
                                <option value="ONLINE">ONLINE</option>
                                <option value="CHECK">CHECK</option>
                            </select>
                        </div>
                        <div class="mb-0">
                            <label for="editAvailableService" class="form-label">Available Service</label>
                            <select id="editAvailableService" name="edit_available_service" class="form-select" required>
                                <option value="" selected disabled>Select available service</option>
                                <option value="used">used</option>
                                <option value="unused">unused</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-danger">Save</button>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    </script>
</body>
<?php include '../../../templates/footer.php'; ?>

<!-- BANK LIST -->
<script>
$(function () {
    const $tableBody = $('#bankTable tbody');
    const $searchInput = $('#searchInput');
    const $searchSuggestions = $('#searchSuggestions');
    const $loadingOverlay = $('#loading-overlay');

    let allBanks = [];

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

    function formatDate(dateValue) {
        if (!dateValue || dateValue === '-') {
            return '-';
        }

        const parsedDate = new Date(dateValue);
        if (Number.isNaN(parsedDate.getTime())) {
            return dateValue;
        }

        return parsedDate.toLocaleDateString('en-US', {
            month: 'long',
            day: '2-digit',
            year: 'numeric'
        });
    }

    function renderTableRows(rows) {
        if (!rows.length) {
            $tableBody.html('<tr><td colspan="7" class="text-center">No bank records found.</td></tr>');
            if (typeof window.clearBankRowSelection === 'function') {
                window.clearBankRowSelection();
            }
            return;
        }

        const html = rows.map((row) => {
            const bankLegalName = getField(row, ['bank_name', 'bank_legal_name', 'name']);
            const bankAbbreviation = getField(row, ['abbreviation', 'bank_abbreviation', 'abbr']);
            const partnerCount = getField(row, ['partner_count', 'no_of_partner_registered', 'registered_partner_count', 'series_number']);
            const settlementType = getField(row, ['settled_online_check', 'settlement_type']);
            const dateCreated = formatDate(getField(row, ['date_created', 'created_at', 'created_date']));
            const modifiedDate = formatDate(getField(row, ['modified_date', 'updated_at', 'date_modified']));
            const availableService = getField(row, ['used_unused', 'available_service', 'service_type', 'service']);

            return `
                <tr
                    data-id="${escapeHtml(getField(row, ['id'], ''))}"
                    data-bank-legal-name="${escapeHtml(bankLegalName)}"
                    data-bank-abbreviation="${escapeHtml(bankAbbreviation)}"
                    data-settlement-type="${escapeHtml(settlementType)}"
                    data-available-service="${escapeHtml(availableService)}"
                >
                    <td>${escapeHtml(bankLegalName)}</td>
                    <td>${escapeHtml(bankAbbreviation)}</td>
                    <td>${escapeHtml(partnerCount)}</td>
                    <td>${escapeHtml(settlementType)}</td>
                    <td>${escapeHtml(dateCreated)}</td>
                    <td>${escapeHtml(modifiedDate)}</td>
                    <td>${escapeHtml(availableService)}</td>
                </tr>
            `;
        }).join('');

        $tableBody.html(html);

        if (typeof window.clearBankRowSelection === 'function') {
            window.clearBankRowSelection();
        }
    }

    function updateSuggestions(rows) {
        const uniqueNames = [...new Set(rows.map((row) => getField(row, ['bank_name', 'bank_legal_name', 'name'], '')))]
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
            renderTableRows(allBanks);
            return;
        }

        const isExactServiceSearch = keyword === 'used' || keyword === 'unused';

        const filteredRows = allBanks.filter((row) => {
            const bankLegalName = getField(row, ['bank_name', 'bank_legal_name', 'name'], '');
            const bankAbbreviation = getField(row, ['abbreviation', 'bank_abbreviation', 'abbr'], '');
            const partnerCount = getField(row, ['partner_count', 'no_of_partner_registered', 'registered_partner_count', 'series_number'], '');
            const settlementType = getField(row, ['settled_online_check', 'settlement_type'], '');
            const dateCreated = formatDate(getField(row, ['date_created', 'created_at', 'created_date'], ''));
            const modifiedDate = formatDate(getField(row, ['modified_date', 'updated_at', 'date_modified'], ''));
            const availableService = getField(row, ['used_unused', 'available_service', 'service_type', 'service'], '');

            const availableServiceNormalized = String(availableService).toLowerCase().trim();

            if (isExactServiceSearch) {
                return availableServiceNormalized === keyword;
            }

            const searchableText = [
                bankLegalName,
                bankAbbreviation,
                partnerCount,
                settlementType,
                dateCreated,
                modifiedDate,
                availableService
            ].join(' ').toLowerCase();

            return searchableText.includes(keyword);
        });

        renderTableRows(filteredRows);
    }

    function loadBankTableData() {
        $.ajax({
            url: 'masterfile-bank-list.php',
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'generate_bank_list'
            },
            beforeSend: function () {
                $loadingOverlay.show();
            },
            success: function (response) {
                if (response && response.status === 'success' && Array.isArray(response.data)) {
                    allBanks = response.data;
                    updateSuggestions(allBanks);
                    renderTableRows(allBanks);
                } else {
                    allBanks = [];
                    updateSuggestions(allBanks);
                    renderTableRows(allBanks);
                }
            },
            error: function () {
                allBanks = [];
                updateSuggestions(allBanks);
                renderTableRows(allBanks);

                Swal.fire({
                    icon: 'error',
                    title: 'Load Failed',
                    text: 'Unable to load bank list. Please try again.'
                });
            },
            complete: function () {
                $loadingOverlay.hide();
            }
        });
    }

    $searchInput.on('input', filterAndRender);

    window.loadBankTableData = loadBankTableData;
    loadBankTableData();
});

</script>

<!-- ADD MODAL FIELD LIST-->
<script>
$(function () {
    const $loadingOverlay = $('#loading-overlay');
    const $addBankForm = $('#addBankForm');
    const modalElement = document.getElementById('addBankModal');
    const $bankLegalName = $('#bankLegalName');
    const $bankAbbreviation = $('#bankAbbreviation');
    const $settlementType = $('#settlementType');

    function openModalFallback() {
        if (!modalElement) {
            return;
        }

        modalElement.style.display = 'block';
        modalElement.classList.add('show');
        modalElement.setAttribute('aria-modal', 'true');
        modalElement.removeAttribute('aria-hidden');

        if (!document.querySelector('.modal-backdrop')) {
            const backdrop = document.createElement('div');
            backdrop.className = 'modal-backdrop fade show';
            backdrop.setAttribute('data-fallback-backdrop', 'true');
            document.body.appendChild(backdrop);
        }

        document.body.classList.add('modal-open');
        document.body.style.overflow = 'hidden';
    }

    function closeModalFallback() {
        if (!modalElement) {
            return;
        }

        modalElement.classList.remove('show');
        modalElement.style.display = 'none';
        modalElement.setAttribute('aria-hidden', 'true');
        modalElement.removeAttribute('aria-modal');

        document.querySelectorAll('.modal-backdrop[data-fallback-backdrop="true"]').forEach((backdrop) => {
            backdrop.remove();
        });

        document.body.classList.remove('modal-open');
        document.body.style.overflow = '';
    }

    function closeAddModal() {
        if (!modalElement) {
            return;
        }

        if (window.bootstrap && typeof window.bootstrap.Modal === 'function') {
            window.bootstrap.Modal.getOrCreateInstance(modalElement).hide();
            return;
        }

        if (window.jQuery && typeof window.jQuery(modalElement).modal === 'function') {
            window.jQuery(modalElement).modal('hide');
            return;
        }

        closeModalFallback();
    }

    if (modalElement) {
        modalElement.querySelectorAll('[data-bs-dismiss="modal"]').forEach((button) => {
            button.addEventListener('click', function () {
                if (window.bootstrap && typeof window.bootstrap.Modal === 'function') {
                    return;
                }
                closeModalFallback();
            });
        });

        modalElement.addEventListener('click', function (event) {
            if (event.target === modalElement && !(window.bootstrap && typeof window.bootstrap.Modal === 'function')) {
                closeModalFallback();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && modalElement.classList.contains('show') && !(window.bootstrap && typeof window.bootstrap.Modal === 'function')) {
                closeModalFallback();
            }
        });
    }

    $addBankForm.on('submit', function (event) {
        event.preventDefault();

        const bankName = $bankLegalName.val().trim();
        const bankAbbreviation = $bankAbbreviation.val().trim();
        const settlementType = $settlementType.val();

        if (!bankName || !bankAbbreviation || !settlementType) {
            Swal.fire({
                icon: 'warning',
                title: 'Missing Required Fields',
                text: 'Please complete all required fields before saving.'
            });
            return;
        }

        $.ajax({
            url: 'masterfile-bank-list.php',
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'saved_bank_data',
                bank_name: bankName,
                bank_abbreviation: bankAbbreviation,
                settled_online_check: settlementType
            },
            beforeSend: function () {
                $loadingOverlay.show();
            },
            success: function (response) {
                if (response && response.status === 'success') {
                    Swal.fire({
                        icon: 'success',
                        title: 'Inserted Successfully',
                        timer: 1500,
                        showConfirmButton: false
                    });

                    $addBankForm[0].reset();
                    closeAddModal();

                    if (typeof loadBankTableData === 'function') {
                        loadBankTableData();
                    } else {
                        window.location.reload();
                    }
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Save Failed',
                        text: (response && response.message) ? response.message : 'Unable to save bank data.'
                    });
                }
            },
            error: function () {
                Swal.fire({
                    icon: 'error',
                    title: 'Save Failed',
                    text: 'Unable to save bank data. Please try again.'
                });
            },
            complete: function () {
                $loadingOverlay.hide();
            }
        });
    });
});

function AddRow() {
    const modalElement = document.getElementById('addBankModal');
    const bankLegalNameInput = document.getElementById('bankLegalName');

    if (!modalElement) {
        return;
    }

    if (window.bootstrap && typeof window.bootstrap.Modal === 'function') {
        window.bootstrap.Modal.getOrCreateInstance(modalElement).show();
        if (bankLegalNameInput) {
            setTimeout(function () {
                bankLegalNameInput.focus();
            }, 200);
        }
        return;
    }

    if (window.jQuery && typeof window.jQuery(modalElement).modal === 'function') {
        window.jQuery(modalElement).modal('show');
        if (bankLegalNameInput) {
            setTimeout(function () {
                bankLegalNameInput.focus();
            }, 200);
        }
        return;
    }

    modalElement.style.display = 'block';
    modalElement.classList.add('show');
    modalElement.setAttribute('aria-modal', 'true');
    modalElement.removeAttribute('aria-hidden');

    if (!document.querySelector('.modal-backdrop')) {
        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop fade show';
        backdrop.setAttribute('data-fallback-backdrop', 'true');
        document.body.appendChild(backdrop);
    }

    document.body.classList.add('modal-open');
    document.body.style.overflow = 'hidden';

    if (bankLegalNameInput) {
        setTimeout(function () {
            bankLegalNameInput.focus();
        }, 100);
    }
}

</script>

<!-- EDIT MODAL FIELD LIST -->
<script>
$(function () {
    const $loadingOverlay = $('#loading-overlay');
    const $tableBody = $('#bankTable tbody');
    const $addBankButton = $('#addBankButton');
    const $editBankButton = $('#editBankButton');
    const $editBankForm = $('#editBankForm');
    const editModalElement = document.getElementById('editBankModal');

    const $editBankLegalName = $('#editBankLegalName');
    const $editBankAbbreviation = $('#editBankAbbreviation');
    const $editSettlementType = $('#editSettlementType');
    const $editAvailableService = $('#editAvailableService');

    let selectedRowElement = null;
    let selectedRowData = null;

    function setButtonsDefaultState() {
        $editBankButton.prop('disabled', true).removeClass('btn-danger').addClass('btn-secondary');
        $addBankButton.prop('disabled', false).removeClass('btn-secondary').addClass('btn-danger');
    }

    function normalizeValue(value) {
        const normalized = String(value || '').trim();
        return normalized === '-' ? '' : normalized;
    }

    window.clearBankRowSelection = function () {
        selectedRowElement = null;
        selectedRowData = null;
        $tableBody.find('tr').removeClass('table-danger');
        setButtonsDefaultState();
    };

    function openEditModal() {
        if (!editModalElement || !selectedRowData) {
            return;
        }

        $editBankLegalName.val(normalizeValue(selectedRowData.bankLegalName));
        $editBankAbbreviation.val(normalizeValue(selectedRowData.bankAbbreviation));

        const settlementTypeValue = normalizeValue(selectedRowData.settlementType).toUpperCase();
        if (settlementTypeValue === 'ONLINE' || settlementTypeValue === 'CHECK') {
            $editSettlementType.val(settlementTypeValue);
        } else {
            $editSettlementType.val('');
        }

        const availableServiceValue = normalizeValue(selectedRowData.availableService).toLowerCase();
        if (availableServiceValue === 'used' || availableServiceValue === 'unused') {
            $editAvailableService.val(availableServiceValue);
        } else {
            $editAvailableService.val('');
        }

        if (window.bootstrap && typeof window.bootstrap.Modal === 'function') {
            window.bootstrap.Modal.getOrCreateInstance(editModalElement).show();
            setTimeout(function () {
                $editBankLegalName.trigger('focus');
            }, 200);
            return;
        }

        editModalElement.style.display = 'block';
        editModalElement.classList.add('show');
        editModalElement.setAttribute('aria-modal', 'true');
        editModalElement.removeAttribute('aria-hidden');
    }

    function closeEditModal() {
        if (!editModalElement) {
            return;
        }

        if (window.bootstrap && typeof window.bootstrap.Modal === 'function') {
            window.bootstrap.Modal.getOrCreateInstance(editModalElement).hide();
            return;
        }

        editModalElement.classList.remove('show');
        editModalElement.style.display = 'none';
        editModalElement.setAttribute('aria-hidden', 'true');
        editModalElement.removeAttribute('aria-modal');
    }

    $tableBody.on('click', 'tr', function () {
        const $row = $(this);

        if ($row.find('td').length === 1) {
            return;
        }

        if (selectedRowElement === this) {
            window.clearBankRowSelection();
            return;
        }

        $tableBody.find('tr').removeClass('table-danger');
        $row.addClass('table-danger');

        selectedRowElement = this;
        selectedRowData = {
            id: $row.data('id'),
            bankLegalName: $row.data('bankLegalName'),
            bankAbbreviation: $row.data('bankAbbreviation'),
            settlementType: $row.data('settlementType'),
            availableService: $row.data('availableService')
        };

        $editBankButton.prop('disabled', false).removeClass('btn-secondary').addClass('btn-danger');
        $addBankButton.prop('disabled', true).removeClass('btn-danger').addClass('btn-secondary');
    });

    $editBankForm.on('submit', function (event) {
        event.preventDefault();

        if (!selectedRowData) {
            Swal.fire({
                icon: 'warning',
                title: 'No Row Selected',
                text: 'Please select a bank row first.'
            });
            return;
        }

        const updatedBankName = $editBankLegalName.val().trim();
        const updatedBankAbbreviation = $editBankAbbreviation.val().trim();
        const updatedSettlementType = $editSettlementType.val();
        const updatedAvailableService = $editAvailableService.val();

        if (!updatedBankName || !updatedBankAbbreviation || !updatedSettlementType || !updatedAvailableService) {
            Swal.fire({
                icon: 'warning',
                title: 'Missing Required Fields',
                text: 'Please complete all required fields before saving.'
            });
            return;
        }

        $.ajax({
            url: 'masterfile-bank-list.php',
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'update_bank_data',
                id: Number(selectedRowData.id || 0),
                original_bank_name: String(selectedRowData.bankLegalName || '').trim(),
                updated_bank_name: updatedBankName,
                updated_bank_abbreviation: updatedBankAbbreviation,
                updated_settled_online_check: updatedSettlementType,
                updated_used_unused: updatedAvailableService
            },
            beforeSend: function () {
                $loadingOverlay.show();
            },
            success: function (response) {
                if (response && response.status === 'success') {
                    Swal.fire({
                        icon: 'success',
                        title: 'Updated Successfully',
                        timer: 1500,
                        showConfirmButton: false
                    });

                    closeEditModal();

                    if (typeof window.loadBankTableData === 'function') {
                        window.loadBankTableData();
                    } else {
                        window.location.reload();
                    }
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Update Failed',
                        text: (response && response.message) ? response.message : 'Unable to update bank data.'
                    });
                }
            },
            error: function () {
                Swal.fire({
                    icon: 'error',
                    title: 'Update Failed',
                    text: 'Unable to update bank data. Please try again.'
                });
            },
            complete: function () {
                $loadingOverlay.hide();
            }
        });
    });

    window.EditRow = function () {
        if (!selectedRowData) {
            Swal.fire({
                icon: 'warning',
                title: 'No Row Selected',
                text: 'Please select a bank row first.'
            });
            return;
        }

        openEditModal();
    };

    setButtonsDefaultState();
});

</script>
</html>