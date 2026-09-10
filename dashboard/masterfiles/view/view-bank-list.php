<?php
// Connect to the database
require_once __DIR__ . '/../../../config/config.php';
require '../../../vendor/autoload.php';

// Start the session
session_start();

@include_once __DIR__ . '/../../../templates/middleware.php';

$id = resolve_user_identifier();

if (empty($id)) {
    header('Location: ../../../login_form.php');
    exit;
}

if (
    !function_exists('has_any_permission') ||
    !has_any_permission(['Masterfiles View Bank List', 'View Bank List'])
) {
    header('Location: ../../home.php');
    exit;
}

// Prefer explicit session values for current user email
$current_user_email = $_SESSION['admin_email'] ?? $_SESSION['user_email'] ?? '';


// ============================================================
// GENERATE BANK LIST
// ============================================================
if (
    isset($_POST['action']) &&
    $_POST['action'] === 'generate_bank_list'
) {
    header('Content-Type: application/json');

    try {

        /*
         * Get the bank name and abbreviation from bank_table,
         * then count the DISTINCT partner_id_kpx using the
         * combination of:
         *
         *     partner_masterfile.bank
         *     partner_masterfile.settled_online_check
         *
         * Example:
         *
         * BDO | BDO | CHECK  | 12
         * BDO | BDO | ONLINE | 18
         *
         * COUNT(DISTINCT partner_id_kpx) is used so that
         * duplicate partner records are not double-counted.
         */

       $bankQuery = "
    SELECT
        bt.bank_name,
        bt.bank_abbreviation,
        COALESCE(pmf.settled_online_check, '-') AS settled_online_check,
        COUNT(DISTINCT pmf.partner_id_kpx) AS partner_count
    FROM mldb.bank_table bt

    LEFT JOIN masterdata.partner_masterfile pmf
        ON TRIM(UPPER(bt.bank_name)) = TRIM(UPPER(pmf.bank))

    GROUP BY
        bt.bank_name,
        bt.bank_abbreviation,
        pmf.settled_online_check

    ORDER BY
        bt.bank_name ASC,
        pmf.settled_online_check ASC
";

        $stmt = $conn->prepare($bankQuery);

        if (!$stmt) {
            throw new Exception(
                'Prepare failed: ' . $conn->error
            );
        }

        if (!$stmt->execute()) {
            throw new Exception(
                'Execute failed: ' . $stmt->error
            );
        }

        $result = $stmt->get_result();

        $banks = [];

        while ($row = $result->fetch_assoc()) {

            $banks[] = [
                'bank_name' => $row['bank_name'],
                'bank_abbreviation' => $row['bank_abbreviation'],
                'settled_online_check' => $row['settled_online_check'],
                'partner_count' => (int) $row['partner_count']
            ];
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

    <meta
        http-equiv="X-UA-Compatible"
        content="IE=edge"
    >

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Bank List |
        <?php
        if (
            isset($_SESSION['user_type']) &&
            (
                $_SESSION['user_type'] === 'admin' ||
                $_SESSION['user_type'] === 'user'
            )
        ) {
            echo ucfirst($_SESSION['user_type']);
        } else {
            echo 'Guest';
        }
        ?>
    </title>

    <!-- Custom CSS -->
    <link
        rel="stylesheet"
        href="../../../assets/css/templates/style.css?v=<?php echo time(); ?>"
    >

    <!-- Font Awesome -->
    <script
        src="https://kit.fontawesome.com/30b908cc5a.js"
        crossorigin="anonymous"
    ></script>

    <!-- jQuery -->
    <script
        src="https://code.jquery.com/jquery-3.6.0.min.js"
    ></script>

    <!-- SweetAlert -->
    <script
        src="../../../assets/js/sweetalert2.all.min.js"
    ></script>

    <!-- Favicon -->
    <link
        rel="icon"
        href="../../../images/MLW logo.png"
        type="image/png"
    >

</head>

<body>

<div class="main-container">

    <?php include '../../../templates/header_ui.php'; ?>

    <!-- Show and Hide Side Nav Menu -->
    <?php include '../../../templates/sidebar.php'; ?>

    <!-- Loading Overlay -->
    <div id="loading-overlay">
        <div class="loading-spinner"></div>
    </div>


    <!-- =====================================================
         PAGE HEADER
    ====================================================== -->

    <div
        class="bp-section-header"
        role="region"
        aria-label="Page title"
    >

        <div class="bp-section-title">

            <i
                class="fa-solid fa-layer-group"
                aria-hidden="true"
            ></i>

            <div>

                <h2>Bank List</h2>

            </div>

        </div>

    </div>


    <!-- =====================================================
         CONTENT
    ====================================================== -->

    <div class="container-fluid">

        <div class="row">

            <div class="col-md-18">

                <div class="card">

                    <!-- =================================================
                         SEARCH
                    ================================================== -->

                    <div class="card-header">

                        <div
                            class="row g-2 align-items-end justify-content-between"
                        >

                            <div class="col-md-6 ms-auto">

                                <label
                                    for="searchInput"
                                    class="form-label mb-1"
                                >
                                    Search Bank
                                </label>

                                <input
                                    type="text"
                                    id="searchInput"
                                    class="form-control"
                                    placeholder="Search by bank, abbreviation, settlement type..."
                                    list="searchSuggestions"
                                >

                                <datalist id="searchSuggestions"></datalist>

                            </div>

                        </div>

                    </div>


                    <!-- =================================================
                         TABLE
                    ================================================== -->

                    <div class="card-body">

                        <div class="table-responsive">

                            <table
                                class="table table-striped table-hover"
                                id="bankTable"
                            >

                                <thead>

                                    <tr>

                                        <th scope="col">
                                            Bank Name
                                        </th>

                                        <th scope="col">
                                            Bank Abbreviation
                                        </th>

                                        <th scope="col">
                                            Settlement Type
                                        </th>

                                        <th scope="col">
                                            No. of Partners
                                        </th>

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

$(function () {

    const $tableBody = $('#bankTable tbody');
    const $searchInput = $('#searchInput');
    const $searchSuggestions = $('#searchSuggestions');
    const $loadingOverlay = $('#loading-overlay');

    let allBanks = [];


    // ============================================================
    // ESCAPE HTML
    // ============================================================

    function escapeHtml(value) {

        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');

    }


    // ============================================================
    // GET FIELD
    // ============================================================

    function getField(
        row,
        keys,
        defaultValue = '-'
    ) {

        for (const key of keys) {

            if (
                row[key] !== undefined &&
                row[key] !== null &&
                row[key] !== ''
            ) {

                return row[key];

            }

        }

        return defaultValue;
    }


    // ============================================================
    // RENDER TABLE
    // ============================================================

    function renderTableRows(rows) {

        if (!rows.length) {

            $tableBody.html(`
                <tr>
                    <td
                        colspan="4"
                        class="text-center"
                    >
                        No bank records found.
                    </td>
                </tr>
            `);

            return;
        }


        const html = rows.map((row) => {

            const bankName = getField(
                row,
                ['bank_name'],
                '-'
            );

            const bankAbbreviation = getField(
                row,
                ['bank_abbreviation'],
                '-'
            );

            const settlementType = getField(
                row,
                ['settled_online_check'],
                '-'
            );

            const partnerCount = getField(
                row,
                ['partner_count'],
                '0'
            );


            return `
                <tr>

                    <td>
                        ${escapeHtml(bankName)}
                    </td>

                    <td>
                        ${escapeHtml(bankAbbreviation)}
                    </td>

                    <td>
                        ${escapeHtml(settlementType)}
                    </td>

                    <td>
                        ${escapeHtml(partnerCount)}
                    </td>

                </tr>
            `;

        }).join('');


        $tableBody.html(html);

    }


    // ============================================================
    // UPDATE SEARCH SUGGESTIONS
    // ============================================================

    function updateSuggestions(rows) {

        const uniqueNames = [
            ...new Set(
                rows
                    .map(row =>
                        getField(
                            row,
                            ['bank_name'],
                            ''
                        )
                    )
            )
        ]
        .filter(name => name !== '')
        .sort((a, b) =>
            String(a).localeCompare(String(b))
        );


        const optionsHtml = uniqueNames
            .map(name =>
                `<option value="${escapeHtml(name)}"></option>`
            )
            .join('');


        $searchSuggestions.html(optionsHtml);

    }


    // ============================================================
    // FILTER AND RENDER
    // ============================================================

    function filterAndRender() {

        const keyword = String(
            $searchInput.val() || ''
        )
        .toLowerCase()
        .trim();


        // No search
        if (!keyword) {

            renderTableRows(allBanks);

            return;
        }


        const filteredRows = allBanks.filter((row) => {

            const bankName = getField(
                row,
                ['bank_name'],
                ''
            );

            const bankAbbreviation = getField(
                row,
                ['bank_abbreviation'],
                ''
            );

            const settlementType = getField(
                row,
                ['settled_online_check'],
                ''
            );

            const partnerCount = getField(
                row,
                ['partner_count'],
                ''
            );


            const searchableText = [
                bankName,
                bankAbbreviation,
                settlementType,
                partnerCount
            ]
            .join(' ')
            .toLowerCase();


            return searchableText.includes(keyword);

        });


        renderTableRows(filteredRows);

    }


    // ============================================================
    // LOAD BANK DATA
    // ============================================================

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

                if (
                    response &&
                    response.status === 'success' &&
                    Array.isArray(response.data)
                ) {

                    allBanks = response.data;

                    updateSuggestions(allBanks);

                    renderTableRows(allBanks);

                } else {

                    allBanks = [];

                    updateSuggestions(allBanks);

                    renderTableRows(allBanks);

                }

            },


            error: function (xhr) {

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


    // ============================================================
    // SEARCH EVENT
    // ============================================================

    $searchInput.on(
        'input',
        filterAndRender
    );


    // ============================================================
    // INITIAL LOAD
    // ============================================================

    loadBankTableData();

});

</script>


<?php include '../../../templates/footer.php'; ?>

</body>

</html>