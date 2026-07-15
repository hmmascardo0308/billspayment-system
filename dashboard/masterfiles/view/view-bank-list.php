<?php
// Connect to the database
require_once __DIR__ . '/../../../config/config.php';
require '../../../vendor/autoload.php';

// Start the session
session_start();
@include_once __DIR__ . '/../../../templates/middleware.php';
$id = resolve_user_identifier();
if (empty($id)) { header('Location: ../../../login_form.php'); exit; }
if (!function_exists('has_any_permission') || !has_any_permission(['Masterfiles View Bank List', 'View Bank List'])) { header('Location: ../../home.php'); exit; }


// prefer explicit session values for current user email
$current_user_email = $_SESSION['admin_email'] ?? $_SESSION['user_email'] ?? '';

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
                                        <option value="BDO Unibank"></option>
                                        <option value="BPI"></option>
                                        <option value="Metrobank"></option>
                                        <option value="LandBank"></option>
                                        <option value="UnionBank"></option>
                                        <option value="PNB"></option>
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
                                    </tbody>
                                </table>
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
                    <tr>
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
                url: 'view-bank-list.php',
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

        loadBankTableData();
    });

 </script>
</html>