<?php 
// Connect to the database
include '../../../config/config.php';
require '../../../vendor/autoload.php';

// Start the session
session_start();
@include_once __DIR__ . '/../../../templates/middleware.php';
$id = resolve_user_identifier();
if (empty($id)) { header('Location: ../../../login_form.php'); exit; }


// prefer explicit session values for current user email; do not gate on role
$current_user_email = $_SESSION['admin_email'] ?? $_SESSION['user_email'] ?? '';

// page-level permission enforcement: require Balance Sheet Report or Bills Payment
if (!function_exists('has_any_permission') || !has_any_permission(['Balance Sheet Report','Bills Payment'])) { header('Location: ../../home.php'); exit; }

// get display dropdown menu for partners
if (isset($_POST['action']) && $_POST['action'] === 'get_partner_list') {
    try {
        $partnersQuery = "SELECT partner_name FROM masterdata.partner_masterfile WHERE status = 'ACTIVE' ORDER BY partner_name";
        $partnersResult = $conn->query($partnersQuery);
        
        $partners = array();
        if ($partnersResult && $partnersResult->num_rows > 0) {
            while ($row = $partnersResult->fetch_assoc()) {
                $partners[] = $row;
            }
        }
        
        echo json_encode([
            'status' => 'success',
            'data' => $partners
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

if (isset($_POST['action']) && $_POST['action'] === 'generate_report') {
    $partner = $_POST['partner'] ?? '';
    $filterType = $_POST['filterType'] ?? '';
    $startDate = $_POST['startDate'] ?? '';
    $endDate = $_POST['endDate'] ?? '';

    $whereConditions = [];
    $params = [];
    $types = '';
    $dateCondition = '';
    $dateParams = [];

    if (empty($filterType) || empty($startDate)) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Missing required filters.',
            'data' => []
        ]);
        exit();
    }

    if ($filterType === 'daily') {
        $dateCondition = "(DATE(bt.datetime) = ? OR DATE(bt.cancellation_date) = ?)";
        $dateParams = [$startDate, $startDate, $startDate, $startDate];
        $types .= 'ssss';
    } elseif ($filterType === 'date-range') {
        $rangeEnd = $endDate !== '' ? $endDate : $startDate;
        $dateCondition = "(DATE(bt.datetime) BETWEEN ? AND ? OR DATE(bt.cancellation_date) BETWEEN ? AND ?)";
        $dateParams = [$startDate, $rangeEnd, $startDate, $rangeEnd, $startDate, $rangeEnd, $startDate, $rangeEnd];
        $types .= 'ssssssss';
    } elseif ($filterType === 'monthly') {
        $startMonth = $startDate . '-01';
        $endMonth = date('Y-m-t', strtotime($startDate . '-01'));
        $dateCondition = "(DATE(bt.datetime) BETWEEN ? AND ? OR DATE(bt.cancellation_date) BETWEEN ? AND ?)";
        $dateParams = [$startMonth, $endMonth, $startMonth, $endMonth, $startMonth, $endMonth, $startMonth, $endMonth];
        $types .= 'ssssssss';
    } elseif ($filterType === 'monthly-range') {
        $rangeEndMonth = $endDate !== '' ? $endDate : $startDate;
        $startMonth = $startDate . '-01';
        $endMonth = date('Y-m-t', strtotime($rangeEndMonth . '-01'));
        $dateCondition = "(DATE(bt.datetime) BETWEEN ? AND ? OR DATE(bt.cancellation_date) BETWEEN ? AND ?)";
        $dateParams = [$startMonth, $endMonth, $startMonth, $endMonth, $startMonth, $endMonth, $startMonth, $endMonth];
        $types .= 'ssssssss';
    } elseif ($filterType === 'yearly') {
        $startYear = $startDate . '-01-01';
        $endYear = $startDate . '-12-31';
        $dateCondition = "(DATE(bt.datetime) BETWEEN ? AND ? OR DATE(bt.cancellation_date) BETWEEN ? AND ?)";
        $dateParams = [$startYear, $endYear, $startYear, $endYear, $startYear, $endYear, $startYear, $endYear];
        $types .= 'ssssssss';
    } elseif ($filterType === 'yearly-range') {
        $rangeEndYear = $endDate !== '' ? $endDate : $startDate;
        $startYear = $startDate . '-01-01';
        $endYear = $rangeEndYear . '-12-31';
        $dateCondition = "(DATE(bt.datetime) BETWEEN ? AND ? OR DATE(bt.cancellation_date) BETWEEN ? AND ?)";
        $dateParams = [$startYear, $endYear, $startYear, $endYear, $startYear, $endYear, $startYear, $endYear];
        $types .= 'ssssssss';
    } else {
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid time frame selected.',
            'data' => []
        ]);
        exit();
    }

    $params = array_merge($params, $dateParams);

    // Build transaction-level owner filter based on sub_billers_name rules
    $ownerFilterCondition = '';
    $selectedOwnerCondition = '';
    if (!empty($partner) && $partner !== 'All') {
        $partnerEsc = mysqli_real_escape_string($conn, $partner);
        if ($partner === 'SECURITY BANK') {
            $ownerFilterCondition = " AND bt.partner_name = '{$partnerEsc}' AND (bt.sub_billers_name IS NULL OR bt.sub_billers_name = '')";
        } elseif ($partner === 'MYLORA CORPORATION' || $partner === 'JUNANS MARKETING') {
            $ownerFilterCondition = " AND bt.sub_billers_name = '{$partnerEsc}'";
        } else {
            $ownerFilterCondition = " AND bt.partner_name = '{$partnerEsc}'";
        }

        // Keep final output to the selected owner only
        $selectedOwnerCondition = " AND ap.owner_name = '{$partnerEsc}'";
    }

    $DataQuery = "WITH summary_vol AS (
                        SELECT
                            CASE
                                WHEN bt.sub_billers_name IN ('MYLORA CORPORATION', 'JUNANS MARKETING') THEN bt.sub_billers_name
                                WHEN bt.partner_name = 'SECURITY BANK' AND (bt.sub_billers_name IS NULL OR bt.sub_billers_name = '') THEN bt.partner_name
                                ELSE bt.partner_name
                            END AS owner_name,
                            MAX(bt.sub_billers_name) AS sub_billers_name,
                            COUNT(*) AS vol1,
                            SUM(bt.amount_paid) AS principal1,
                            SUM(bt.charge_to_partner + bt.charge_to_customer) AS charge1
                        FROM mldb.billspayment_transaction AS bt
                        WHERE $dateCondition
                          AND bt.status IS NULL
                          AND bt.branch_id NOT IN ('1', '2', '4937', '4938', '4962', '4987', '4993', '4944')
                          $ownerFilterCondition
                        GROUP BY
                            CASE
                                WHEN bt.sub_billers_name IN ('MYLORA CORPORATION', 'JUNANS MARKETING') THEN bt.sub_billers_name
                                WHEN bt.partner_name = 'SECURITY BANK' AND (bt.sub_billers_name IS NULL OR bt.sub_billers_name = '') THEN bt.partner_name
                                ELSE bt.partner_name
                            END
                ),
                adjustment_vol AS (
                    SELECT
                        CASE
                            WHEN bt.sub_billers_name IN ('MYLORA CORPORATION', 'JUNANS MARKETING') THEN bt.sub_billers_name
                            WHEN bt.partner_name = 'SECURITY BANK' AND (bt.sub_billers_name IS NULL OR bt.sub_billers_name = '') THEN bt.partner_name
                            ELSE bt.partner_name
                        END AS owner_name,
                        MAX(bt.sub_billers_name) AS sub_billers_name,
                        COUNT(*) AS vol2,
                        SUM(bt.amount_paid) AS principal2,
                        SUM(bt.charge_to_partner + bt.charge_to_customer) AS charge2
                    FROM mldb.billspayment_transaction AS bt
                    WHERE $dateCondition
                      AND bt.status = '*'
                      AND bt.branch_id NOT IN ('1', '2', '4937', '4938', '4962', '4987', '4993', '4944')
                      $ownerFilterCondition
                    GROUP BY
                        CASE
                            WHEN bt.sub_billers_name IN ('MYLORA CORPORATION', 'JUNANS MARKETING') THEN bt.sub_billers_name
                            WHEN bt.partner_name = 'SECURITY BANK' AND (bt.sub_billers_name IS NULL OR bt.sub_billers_name = '') THEN bt.partner_name
                            ELSE bt.partner_name
                        END
                ),
                all_partners AS (
                    SELECT mpm.partner_name AS owner_name
                    FROM masterdata.partner_masterfile AS mpm
                    WHERE mpm.status = 'ACTIVE'

                    UNION

                    SELECT owner_name FROM summary_vol

                    UNION

                    SELECT owner_name FROM adjustment_vol
                )
                SELECT
                    ap.owner_name AS partner_name,
                    COALESCE(MAX(sv.sub_billers_name), MAX(av.sub_billers_name)) AS sub_billers_name,
                    (SUM(COALESCE(sv.vol1, 0)) - SUM(COALESCE(av.vol2, 0))) AS net_vol,
                    (SUM(COALESCE(sv.principal1, 0)) - SUM(COALESCE(ABS(av.principal2), 0))) AS net_principal,
                    (SUM(COALESCE(sv.charge1, 0)) - SUM(COALESCE(ABS(av.charge2), 0))) AS net_charges
                FROM all_partners AS ap
                LEFT JOIN summary_vol AS sv ON ap.owner_name = sv.owner_name
                LEFT JOIN adjustment_vol AS av ON ap.owner_name = av.owner_name
                LEFT JOIN masterdata.partner_masterfile AS mpm ON ap.owner_name = mpm.partner_name
                WHERE (mpm.status = 'ACTIVE' OR mpm.status IS NULL)
                  $selectedOwnerCondition
                GROUP BY ap.owner_name
                HAVING ap.owner_name IS NOT NULL
                ORDER BY ap.owner_name";

    try {
        $stmt = $conn->prepare($DataQuery);
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $conn->error);
        }

        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();

        echo json_encode([
            'status' => 'success',
            'data' => $rows
        ]);
    } catch (Exception $e) {
        error_log('Balance sheet generate_report error: ' . $e->getMessage());
        echo json_encode([
            'status' => 'error',
            'message' => 'Database error: ' . $e->getMessage(),
            'data' => []
        ]);
    }
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Balance Sheet Report | <?php if($_SESSION['user_type'] === 'admin' || $_SESSION['user_type'] === 'user') echo ucfirst($_SESSION['user_type']); else echo "Guest";?></title>
    <!-- custom CSS file link  -->
    <link rel="stylesheet" href="../../../assets/css/templates/style.css?v=<?php echo time(); ?>">
    <script src="https://kit.fontawesome.com/30b908cc5a.js" crossorigin="anonymous"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="../../../assets/js/sweetalert2.all.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>

    <link rel="icon" href="../../../images/MLW logo.png" type="image/png">
    <style>
        /* Day Shortcut Buttons Styling */
        .day-shortcut-container {
            padding: 10px 5px;
            border-radius: 5px;
            /* margin-bottom: 15px; */
            display: flex;
            align-items: center;
            background-color: #f8f9fa;
            border: 1px solid #e0e0e0;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .day-buttons-label {
            font-weight: bold;
            margin-right: 15px;
            color: #666;
            white-space: nowrap;
            padding-left: 10px;
        }

        .day-buttons-wrapper {
            display: flex;
            flex-wrap: wrap;
            gap: 2px;
            max-width: 100%;
            overflow-x: auto;
            padding: 5px;
            align-items: center;
        }

        .day-button {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            border: 1px solid #dc3545;
            background-color: white;
            color: #dc3545;
            font-weight: bold;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        /* Pill shape for month buttons */
        .day-button.month-button {
            width: auto !important;
            min-width: 120px !important;
            padding: 8px 16px !important;
            border-radius: 25px !important;
            font-size: 12px !important;
            white-space: nowrap !important;
        }

        /* Pill shape for year buttons */
        .day-button.year-button {
            width: auto !important;
            min-width: 70px !important;
            padding: 8px 16px !important;
            border-radius: 25px !important;
            font-size: 12px !important;
        }

        /* Ensure day buttons remain circular */
        .day-button:not(.month-button):not(.year-button):not(.day-button-all) {
            width: 35px;
            height: 35px;
            border-radius: 50%;
        }

        .day-button:hover {
            background-color: rgba(220,53,69,0.8);
            color: white;
            transform: scale(1.05);
            box-shadow: 0 2px 4px rgba(220,53,69,0.3);
            cursor: pointer;
            z-index: 4;
        }

        .day-button-active {
            background-color: #dc3545;
            color: white;
            transform: scale(1.1);
            box-shadow: 0 2px 5px rgba(220,53,69,0.4);
            position: relative;
            z-index: 5;
        }

        .day-button-active:after {
            content: "";
            position: absolute;
            bottom: -3px;
            left: 50%;
            transform: translateX(-50%);
            width: 6px;
            height: 6px;
            background-color: #ff8a04ff;
            border-radius: 50%;
        }

        .day-button-all {
            width: auto;
            padding: 0 15px;
            border-radius: 20px;
            background-color: #6c757d;
            color: white;
            border-color: #6c757d;
        }

        .day-button-all:hover {
            background-color: #5a6268;
            border-color: #545b62;
        }

        /* Export Modal Styling */
        .export-options {
            text-align: center;
            padding: 20px 0;
        }

        .export-buttons-container {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-top: 20px;
        }

        .export-btn {
            padding: 12px 24px;
            font-size: 16px;
            font-weight: bold;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            min-width: 150px;
        }

        .export-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }

        .export-btn i {
            margin-right: 8px;
            font-size: 18px;
        }

        /* Custom SweetAlert styling */
        .swal2-popup {
            border-radius: 15px !important;
        }

        .swal2-title {
            color: #333 !important;
            font-weight: bold !important;
        }

        .swal2-html-container {
            margin: 0 !important;
        }

        .month-separator {
            width: 100%;
            text-align: center;
            font-size: 10px;
            color: #666;
            margin: 5px 0;
            font-weight: bold;
            border-bottom: 1px solid #ddd;
            padding-bottom: 2px;
        }

        /* Ensure day number buttons remain circular */
        .day-button.day-number-button {
            width: 35px !important;
            height: 35px !important;
            border-radius: 50% !important;
            font-size: 12px !important;
            margin: 2px !important;
        }

        /* Responsive adjustments for smaller screens */
        @media (max-width: 768px) {
            .day-buttons-wrapper {
                gap: 1px;
            }
            
            .day-button.day-number-button {
                width: 30px !important;
                height: 30px !important;
                font-size: 11px !important;
                margin: 1px !important;
            }
        }
    </style>
    <style>
        /* Loading overlay and spinner */
        #loading-overlay {
            position: fixed;
            inset: 0;
            display: none;
            align-items: center;
            justify-content: center;
            background: rgba(0,0,0,0.45);
            z-index: 99999;
            backdrop-filter: blur(2px);
        }

       #loading-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.7);
            z-index: 9999;
            justify-content: center;
            align-items: center;
        }

        .loading-spinner {
            border: 5px solid #f3f3f3;
            border-top: 5px solid #dc3545;
            border-radius: 50%;
            width: 60px;
            height: 60px;
            animation: spin 1s linear infinite;
        }
          @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
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
                    <h2>Balance Sheet Report</h2>
                    <!-- <p class="bp-section-sub">Summary of transaction volumes by partner and period</p> -->
                </div>
            </div>
        </div>
        <div class="container-fluid">
            <div class="row">
                <div class="col-md-18">
                    <div class="card">
                        <div class="card-header">
                            <div class="row g-2 align-items-end">
                                <!-- Partner List -->
                                <div class="col-md-2 col-sm-6">
                                    <label class="form-label">Partners Name:</label>
                                    <select id="partnerlistDropdown" class="form-select select2" aria-label="Select Partner" name="partnerlist" data-placeholder="Search or select a Partner..." required>
                                        <option value="">Select Partner</option>
                                        <option value="All">All</option>
                                        <!-- options will be populated by JS -->
                                    </select>
                                </div>

                                <!-- Time Frame -->
                                <div class="col-md-2 col-sm-6">
                                    <label class="form-label">Time Frame:</label>
                                    <select class="form-select" name="filterType" required>
                                        <option value="">Select Time Frame</option>
                                        <option value="daily">Per Day</option>
                                        <option value="date-range">Date Range</option>
                                        <!-- <option value="monthly">Per Month</option>
                                        <option value="monthly-range">Monthly Range</option>
                                        <option value="yearly">Per Year</option>
                                        <option value="yearly-range">Yearly Range</option> -->
                                    </select>
                                </div>

                                <!-- Date Range based on selected Time Frame -->
                                <div class="col-md-2" style="display: none;">
                                    <label class="form-label">Start Date:</label>
                                    <input type="date" class="form-control" name="startDate" required>
                                </div>
                                <div class="col-md-2" style="display: none;">
                                    <label class="form-label">End Date:</label>
                                    <input type="date" class="form-control" name="endDate" required>
                                </div>

                                <!-- Action Button -->
                                <div class="col-md-1 col-sm-6">
                                    <div class="col-md-3 d-flex align-items-end">
                                        <button type="button" class="btn btn-secondary" id="generateReport" disabled>Generate</button>
                                    </div>
                                    
                                </div>

                                <!-- Export + Debug Buttons (inline) -->
                                <div class="col-md-1 col-sm-6">
                                    <div class="d-flex align-items-end" style="gap:8px; white-space:nowrap;">
                                        <button class="btn btn-danger" id="exportButton" type="button" style="display:none;">Export to</button>
                                        <button class="btn btn-warning" id="debugButton" type="button" style="display:none;">Debug Report</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="container-fluid">
                            <div class="day-shortcut-container mt-2" id="dayFilterContainer" style="display: none;">
                                <div class="day-buttons-label">Filter by Day:</div>
                                <div class="day-buttons-wrapper" id="dayButtonsWrapper">
                                    <button class="day-button day-button-all day-button-active" id="allDaysButton">All</button>
                                </div>
                            </div>
                            <div class="day-shortcut-container mt-2"  id="monthFilterContainer" style="display: none;">
                                <div class="day-buttons-label">Filter by Month:</div>
                                <div class="day-buttons-wrapper" id="dayButtonsWrapper">
                                    <button class="day-button day-button-all day-button-active" id="allDaysButton">All</button>
                                </div>
                            </div>
                            <div class="day-shortcut-container mt-2"  id="yearFilterContainer" style="display: none;">
                                <div class="day-buttons-label">Filter by Year:</div>
                                <div class="day-buttons-wrapper" id="dayButtonsWrapper">
                                    <button class="day-button day-button-all day-button-active" id="allDaysButton">All</button>
                                </div>
                            </div>
                        </div>
                        
                        <div class="card-body">
                            <div class="table-responsive" id="tableContainer" style="overflow-y: auto;">
                                <table id="transactionReportTable" class="table table-bordered table-hover table-striped">
                                    <thead class="table-light sticky-top">
                                        <tr>
                                            <th rowspan="2" class='text-truncate text-center align-middle'>No.</th>
                                            <th rowspan="2" class='text-truncate text-center align-middle'>Partner Name</th>
                                            <th rowspan="2" class='text-truncate text-center align-middle'>Biller's Name</th>
                                            <th rowspan="2" class='text-truncate text-center align-middle'>Bank</th>
                                            <th colspan="3" class='text-truncate text-center align-middle'>Net Total Transaction</th>
                                            <th rowspan="2" class='text-truncate text-center align-middle'>Paid Service Charge</th>
                                            <th rowspan="2" class='text-truncate text-center align-middle'>Accounts Payable to Partner</th>
                                            <th rowspan="2" class='text-truncate text-center align-middle'>Service Charge</th>
                                            <th rowspan="2" class='text-truncate text-center align-middle'>BPW undeducted</th>
                                            <th rowspan="2" class='text-truncate text-center align-middle'>BPX undeducted</th>
                                            <th rowspan="2" class='text-truncate text-center align-middle'>Audit findings</th>
                                            <th rowspan="2" class='text-truncate text-center align-middle'>Banks</th>
                                            <th rowspan="2" class='text-truncate text-center align-middle'>Others</th>
                                            <th rowspan="2" class='text-truncate text-center align-middle'>Accounts Receivable from Partner</th>
                                            <th rowspan="2" class='text-truncate text-center align-middle'>Balances</th>
                                        </tr>
                                        <tr>
                                            <!-- Column header for Net -->
                                            <th class='text-center'>Vol.</th>
                                            <th class='text-center'>Principal</th>
                                            <th class='text-center'>Charge</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <!-- Data will be populated via JavaScript -->
                                        <tr>
                                            <td></td>
                                            <td></td>
                                            <td></td>
                                            <td></td>
                                            <td></td>
                                            <td></td>
                                            <td></td>
                                            <td></td>
                                            <td></td>
                                            <td></td>
                                            <td></td>
                                            <td></td>
                                            <td></td>
                                            <td></td>
                                            <td></td>
                                            <td></td>
                                            <td></td>
                                        </tr>
                                    </tbody>
                                    <tfoot class="sticky-bottom table-dark">
                                        <tr>
                                            <th colspan="4" class="text-end">Total : </th>
                                            <th class="text-center" id="totalnetvolume">0</th>
                                            <th class="text-end" id="totalnetprincipal">0.00</th>
                                            <th class="text-end" id="totalnetcharge">0.00</th>
                                            <th class="text-end">0.00</th>
                                            <th class="text-end">0.00</th>
                                            <th class="text-end">0.00</th>
                                            <th class="text-end">0.00</th>
                                            <th class="text-end">0.00</th>
                                            <th class="text-end">0.00</th>
                                            <th class="text-end">0.00</th>
                                            <th class="text-end">0.00</th>
                                            <th class="text-end">0.00</th>
                                            <th class="text-end">0.00</th>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
<?php include '../../../templates/footer.php'; ?>
<script>
$(document).ready(function() {
    $('#partnerlistDropdown').select2({
        placeholder: 'Search or select a Partner...',
        allowClear: true
    });

    const $filterType = $('select[name="filterType"]');
    const $partner = $('#partnerlistDropdown');
    const $startDate = $('input[name="startDate"]');
    const $endDate = $('input[name="endDate"]');
    const $startWrap = $startDate.closest('.col-md-2');
    const $endWrap = $endDate.closest('.col-md-2');

    $('.day-shortcut-container').hide();
    $startWrap.hide();
    $endWrap.hide();

    loadPartners();

    function loadPartners() {
        $.ajax({
            url: '',
            type: 'POST',
            data: { action: 'get_partner_list' },
            success: function(response) {
                try {
                    const result = JSON.parse(response);
                    if (result.status === 'success') {
                        const select = $('#partnerlistDropdown');
                        result.data.forEach(partner => {
                            select.append(new Option(partner.partner_name, partner.partner_name));
                        });
                    }
                } catch (e) {
                    console.error('Error loading partners:', e);
                }
            }
        });
    }

    function configureInputsForFilterType(filterType) {
        const startLabel = $startWrap.find('label');
        const endLabel = $endWrap.find('label');

        $startDate.attr({ min: null, max: null, placeholder: '' });
        $endDate.attr({ min: null, max: null, placeholder: '' });

        if (filterType === 'date-range') {
            $startDate.attr('type', 'date');
            $endDate.attr('type', 'date');
            startLabel.text('Start Date:');
            endLabel.text('End Date:');
            $startWrap.show();
            $endWrap.show();
            return;
        }

        if (filterType === 'daily') {
            $startDate.attr('type', 'date');
            startLabel.text('Select Date:');
            $startWrap.show();
            $endWrap.hide();
            return;
        }

        if (filterType === 'monthly' || filterType === 'monthly-range') {
            $startDate.attr('type', 'month');
            $endDate.attr('type', 'month');
            startLabel.text(filterType === 'monthly' ? 'Select Month:' : 'Start Month:');
            endLabel.text('End Month:');
            $startWrap.show();
            $endWrap.toggle(filterType === 'monthly-range');
            return;
        }

        if (filterType === 'yearly' || filterType === 'yearly-range') {
            $startDate.attr('type', 'number');
            $endDate.attr('type', 'number');
            $startDate.attr({ min: '2020', max: '2035', placeholder: 'YYYY' });
            $endDate.attr({ min: '2020', max: '2035', placeholder: 'YYYY' });
            startLabel.text(filterType === 'yearly' ? 'Select Year:' : 'Start Year:');
            endLabel.text('End Year:');
            $startWrap.show();
            $endWrap.toggle(filterType === 'yearly-range');
            return;
        }

        $startWrap.hide();
        $endWrap.hide();
    }

    function toggleGenerateButton() {
        const filterType = $filterType.val();
        const partner = $partner.val();
        const startDate = $startDate.val();
        const endDate = $endDate.val();

        let datesValid = false;
        if (filterType === 'daily' || filterType === 'monthly' || filterType === 'yearly') {
            datesValid = startDate !== '';
        } else if (filterType === 'date-range' || filterType === 'monthly-range' || filterType === 'yearly-range') {
            datesValid = startDate !== '' && endDate !== '';
        }

        const enable = !!(filterType && partner && datesValid);
        if (enable) {
            $('#generateReport').prop('disabled', false).removeClass('btn-secondary').addClass('btn-danger');
        } else {
            $('#generateReport').prop('disabled', true).removeClass('btn-danger').addClass('btn-secondary');
        }
    }

    function adjustTableHeight() {
        const dayFilter = $('#dayFilterContainer');
        const monthFilter = $('#monthFilterContainer');
        const yearFilter = $('#yearFilterContainer');
        const tableContainer = $('#tableContainer');

        const hasVisibleFilters = dayFilter.is(':visible') || monthFilter.is(':visible') || yearFilter.is(':visible');

        if (hasVisibleFilters) {
            tableContainer.css('max-height', '700px');
        } else {
            tableContainer.css('max-height', '745px');
        }
    }

    function resetFilterContainers() {
        $('.day-shortcut-container').each(function() {
            const wrapper = $(this).find('.day-buttons-wrapper');
            wrapper.find('.day-button:not(.day-button-all)').remove();
            wrapper.find('.month-separator').remove();
            wrapper.find('div[style*="height: 5px"]').remove();
            wrapper.find('.day-button-all').removeClass('day-button-active').addClass('day-button-active');
        });
    }

    function clearReportTable() {
        const tbody = $('#transactionReportTable tbody');
        tbody.empty();
        tbody.append('<tr><td colspan="17" class="text-center"></td></tr>');

        $('#totalnetvolume').text('0');
        $('#totalnetprincipal').text('0.00');
        $('#totalnetcharge').text('0.00');
        $('#exportButton').hide();
        adjustTableHeight();
    }

    function populateReportTable(data) {
        const tbody = $('#transactionReportTable tbody');
        tbody.empty();

        let totals = {
            netVol: 0,
            netPrincipal: 0,
            netCharge: 0
        };

        if (!Array.isArray(data)) {
            data = [];
        }

        if (data.length === 0) {
            tbody.append('<tr><td colspan="17" class="text-center">No data found for the selected criteria</td></tr>');
        } else {
            data.forEach((row, index) => {
                const subBillersName = (row.sub_billers_name || '').toString().trim();
                const partnerName = (row.partner_name || '').toString().trim();
                let partner_name_raw = partnerName;

                if (subBillersName === 'MYLORA CORPORATION' || subBillersName === 'JUNANS MARKETING') {
                    partner_name_raw = subBillersName;
                } else if (subBillersName === '' && partnerName === 'SECURITY BANK') {
                    partner_name_raw = partnerName;
                }

                const netVol = parseInt(row.net_vol || 0);
                const netPrincipal = parseFloat(row.net_principal || 0);
                const netCharges = parseFloat(row.net_charges || 0);

                totals.netVol += netVol;
                totals.netPrincipal += netPrincipal;
                totals.netCharge += netCharges;

                const tr = $(`
                    <tr>
                        <td>${index + 1}</td>
                        <td>${partner_name_raw}</td>
                        <td></td>
                        <td></td>
                        <td class="text-end">${netVol.toLocaleString()}</td>
                        <td class="text-end">${netPrincipal.toLocaleString('en-US', { minimumFractionDigits: 2 })}</td>
                        <td class="text-end">${netCharges.toLocaleString('en-US', { minimumFractionDigits: 2 })}</td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                    </tr>
                `);
                tbody.append(tr);
            });
        }

        $('#totalnetvolume').text(totals.netVol.toLocaleString());
        $('#totalnetprincipal').text(totals.netPrincipal.toLocaleString('en-US', { minimumFractionDigits: 2 }));
        $('#totalnetcharge').text(totals.netCharge.toLocaleString('en-US', { minimumFractionDigits: 2 }));

        const hasData = Array.isArray(data) && data.length > 0;
        if (hasData) {
            $('#exportButton').show();
        } else {
            $('#exportButton').hide();
        }
    }

    function formatDate(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    }

    function generateDayButtons(startDate, endDate) {
        const $container = $('#dayFilterContainer');
        const $wrapper = $container.find('.day-buttons-wrapper');

        $wrapper.find('.day-button:not(.day-button-all)').remove();
        $wrapper.find('.month-separator').remove();
        $wrapper.find('div[style*="height: 5px"]').remove();

        const start = new Date(startDate);
        const end = new Date(endDate);
        const dateButtons = [];

        for (let d = new Date(start); d <= end; d.setDate(d.getDate() + 1)) {
            dateButtons.push({
                date: formatDate(d),
                day: d.getDate(),
                fullDate: new Date(d)
            });
        }

        const monthGroups = {};
        dateButtons.forEach(btn => {
            const monthYear = btn.fullDate.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
            if (!monthGroups[monthYear]) monthGroups[monthYear] = [];
            monthGroups[monthYear].push(btn);
        });

        const groupKeys = Object.keys(monthGroups);
        groupKeys.forEach((monthYear, index) => {
            if (groupKeys.length > 1) {
                const monthSeparator = $(`<div class="month-separator">${monthYear}</div>`);
                $wrapper.append(monthSeparator);
            }

            monthGroups[monthYear].forEach(btnData => {
                const button = $(`<button type="button" class="day-button day-number-button" data-date="${btnData.date}" title="${btnData.date}">${btnData.day}</button>`);
                button.on('click', function() {
                    filterBySpecificDateRange($(this), btnData.date, btnData.date);
                });
                $wrapper.append(button);
            });

            if (groupKeys.length > 1 && index < groupKeys.length - 1) {
                $wrapper.append('<div style="width:100%;height:5px;"></div>');
            }
        });

        $container.show();
        adjustTableHeight();
    }

    function generateMonthButtons(startDate, endDate) {
        const $container = $('#monthFilterContainer');
        const $wrapper = $container.find('.day-buttons-wrapper');

        $wrapper.find('.day-button:not(.day-button-all)').remove();
        $wrapper.find('.month-separator').remove();
        $wrapper.find('div[style*="height: 5px"]').remove();

        const start = new Date(startDate + '-01');
        const end = new Date(endDate + '-01');

        for (let d = new Date(start); d <= end; d.setMonth(d.getMonth() + 1)) {
            const yearMonth = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
            const monthName = d.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });

            const button = $(`<button type="button" class="day-button month-button" data-date="${yearMonth}">${monthName}</button>`);
            button.on('click', function() {
                filterBySpecificMonth($(this));
            });
            $wrapper.append(button);
        }

        $container.show();
        adjustTableHeight();
    }

    function generateYearButtons(startDate, endDate) {
        const $container = $('#yearFilterContainer');
        const $wrapper = $container.find('.day-buttons-wrapper');

        $wrapper.find('.day-button:not(.day-button-all)').remove();
        $wrapper.find('.month-separator').remove();
        $wrapper.find('div[style*="height: 5px"]').remove();

        const startYear = parseInt(startDate, 10);
        const endYear = parseInt(endDate, 10);

        for (let year = startYear; year <= endYear; year++) {
            const button = $(`<button type="button" class="day-button year-button" data-date="${year}">${year}</button>`);
            button.on('click', function() {
                filterBySpecificYear($(this));
            });
            $wrapper.append(button);
        }

        $container.show();
        adjustTableHeight();
    }

    function highlightMatchingDayButtons(startDate, endDate) {
        const $container = $('#dayFilterContainer');
        if (startDate === endDate) {
            const $dayButton = $container.find(`[data-date="${startDate}"]`);
            if ($dayButton.length) {
                $dayButton.addClass('day-button-active');
            } else {
                $container.find('.day-button-all').addClass('day-button-active');
            }
        } else {
            $container.find('.day-button-all').addClass('day-button-active');
        }
    }

    function highlightMatchingMonthButtons(startDate, endDate) {
        const $container = $('#monthFilterContainer');
        if (startDate === endDate) {
            const $monthButton = $container.find(`[data-date="${startDate}"]`);
            if ($monthButton.length) {
                $monthButton.addClass('day-button-active');
            } else {
                $container.find('.day-button-all').addClass('day-button-active');
            }
        } else {
            $container.find('.day-button-all').addClass('day-button-active');
        }
    }

    function highlightMatchingYearButtons(startDate, endDate) {
        const $container = $('#yearFilterContainer');
        if (startDate === endDate) {
            const $yearButton = $container.find(`[data-date="${startDate}"]`);
            if ($yearButton.length) {
                $yearButton.addClass('day-button-active');
            } else {
                $container.find('.day-button-all').addClass('day-button-active');
            }
        } else {
            $container.find('.day-button-all').addClass('day-button-active');
        }
    }

    function requestReport(startDate, endDate) {
        const partner = $partner.val();
        const filterType = $filterType.val();

        $('#loading-overlay').show();
        $.ajax({
            url: '',
            type: 'POST',
            data: {
                action: 'generate_report',
                partner: partner,
                filterType: filterType,
                startDate: startDate,
                endDate: endDate
            },
            complete: function() {
                $('#loading-overlay').hide();
            },
            success: function(response) {
                try {
                    const result = JSON.parse(response);
                    if (result.status === 'success') {
                        populateReportTable(result.data || []);
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Server Error',
                            text: result.message || 'Unable to generate report.'
                        });
                    }
                } catch (e) {
                    console.error('Balance sheet response parse error:', e, response);
                    Swal.fire({
                        icon: 'error',
                        title: 'Invalid Response',
                        text: 'Failed to process report response.'
                    });
                }
            },
            error: function(xhr, status, error) {
                console.error('Balance sheet report request error:', { xhr: xhr, status: status, error: error });
                Swal.fire({
                    icon: 'error',
                    title: 'Connection Error',
                    text: 'Failed to generate report. Please try again.'
                });
            }
        });
    }

    function filterBySpecificDateRange($button, specificStartDate, specificEndDate) {
        const container = $button.closest('.day-shortcut-container');
        container.find('.day-button').removeClass('day-button-active');
        $button.addClass('day-button-active');
        requestReport(specificStartDate, specificEndDate);
    }

    function filterBySpecificMonth($button) {
        const container = $button.closest('.day-shortcut-container');
        container.find('.day-button').removeClass('day-button-active');
        $button.addClass('day-button-active');

        const monthValue = $button.data('date');
        requestReport(monthValue, monthValue);
    }

    function filterBySpecificYear($button) {
        const container = $button.closest('.day-shortcut-container');
        container.find('.day-button').removeClass('day-button-active');
        $button.addClass('day-button-active');

        const yearValue = $button.data('date');
        requestReport(yearValue, yearValue);
    }

    function getEffectiveExportParams() {
        const partner = $partner.val();
        const filterType = $filterType.val();

        let startDate = $startDate.val();
        let endDate = $endDate.val() || startDate;

        const activeBtn = $('.day-shortcut-container').find('.day-button-active').not('.day-button-all').first();
        if (activeBtn && activeBtn.length) {
            const dataDate = activeBtn.data('date');
            const container = activeBtn.closest('.day-shortcut-container');
            const containerId = container.attr('id');

            if (containerId === 'dayFilterContainer' && dataDate) {
                startDate = dataDate;
                endDate = dataDate;
            } else if (containerId === 'monthFilterContainer' && dataDate) {
                startDate = dataDate;
                endDate = dataDate;
            } else if (containerId === 'yearFilterContainer' && dataDate) {
                startDate = dataDate;
                endDate = dataDate;
            }
        }

        return {
            partner: partner,
            filterType: filterType,
            startDate: startDate,
            endDate: endDate
        };
    }

    function exportToPDF() {
        Swal.fire({
            icon: 'info',
            title: 'PDF Export',
            text: 'PDF export for Balance Sheet is not yet available.'
        });
    }

    function exportToXLS() {
        const params = getEffectiveExportParams();

        const form = $('<form>', {
            method: 'POST',
            action: '../../../models/generate/excel/generate-balance-sheet-report.php',
            target: '_blank'
        });

        form.append($('<input>', { type: 'hidden', name: 'action', value: 'export_excel' }));
        form.append($('<input>', { type: 'hidden', name: 'partner', value: params.partner }));
        form.append($('<input>', { type: 'hidden', name: 'filterType', value: params.filterType }));
        form.append($('<input>', { type: 'hidden', name: 'startDate', value: params.startDate }));
        form.append($('<input>', { type: 'hidden', name: 'endDate', value: params.endDate }));

        $('body').append(form);
        form.submit();
        form.remove();

        setTimeout(() => {
            Swal.fire({
                title: 'Export started',
                text: 'Excel generation has started. The file will download shortly.',
                icon: 'info',
                confirmButtonText: 'OK',
                confirmButtonColor: '#dc3545'
            });
        }, 700);
    }

    $('#exportButton').on('click', function() {
        Swal.fire({
            title: 'Export Report',
            text: 'Choose your preferred export format:',
            icon: 'question',
            buttonsStyling: false,
            allowOutsideClick: true,
            allowEscapeKey: true,
            reverseButtons: true,
            html: `
                <div class="export-options">
                    <p>Select the format you would like to export the Balance Sheet Report:</p>
                    <div class="export-buttons-container">
                        <button type="button" class="btn btn-danger export-btn" id="exportPDF">
                            <i class="fas fa-file-pdf"></i> PDF Format
                        </button>
                        <button type="button" class="btn btn-success export-btn" id="exportXLS">
                            <i class="fas fa-file-excel"></i> XLS Format
                        </button>
                    </div>
                </div>
            `,
            showConfirmButton: false,
            showCancelButton: false,
            didOpen: () => {
                document.getElementById('exportPDF').addEventListener('click', function() {
                    Swal.close();
                    exportToPDF();
                });

                document.getElementById('exportXLS').addEventListener('click', function() {
                    Swal.close();
                    exportToXLS();
                });
            }
        });
    });

    $filterType.on('change', function() {
        const filterType = $(this).val();

        $startDate.val('');
        $endDate.val('');
        $('.day-shortcut-container').hide();
        resetFilterContainers();
        clearReportTable();

        configureInputsForFilterType(filterType);
        toggleGenerateButton();
        adjustTableHeight();
    });

    $partner.on('change', function() {
        $('.day-shortcut-container').hide();
        resetFilterContainers();
        $('#exportButton').hide();
        clearReportTable();
        toggleGenerateButton();
        adjustTableHeight();
    });

    $startDate.add($endDate).on('change', function() {
        $('.day-shortcut-container').hide();
        resetFilterContainers();
        clearReportTable();
        toggleGenerateButton();
        adjustTableHeight();
    });

    $('#generateReport').on('click', function() {
        const filterType = $filterType.val();
        const partner = $partner.val();
        let startDate = $startDate.val();
        let endDate = $endDate.val();

        if (!filterType || !partner) {
            Swal.fire({ icon: 'warning', title: 'Missing Information', text: 'Please select both Time Frame and Partner.' });
            return;
        }

        if (!startDate) {
            Swal.fire({ icon: 'warning', title: 'Missing Date', text: 'Please provide Start Date.' });
            return;
        }

        if (filterType === 'daily') {
            endDate = startDate;
        }

        if (filterType === 'monthly') {
            endDate = startDate;
        }

        if (filterType === 'yearly') {
            endDate = startDate;
        }

        const requiresEndDate = (filterType === 'date-range' || filterType === 'monthly-range' || filterType === 'yearly-range');
        if (requiresEndDate && !endDate) {
            Swal.fire({ icon: 'warning', title: 'Missing End Date', text: 'Please provide End Date.' });
            return;
        }

        $('.day-shortcut-container').hide();
        resetFilterContainers();

        if (filterType === 'date-range') {
            generateDayButtons(startDate, endDate);
            highlightMatchingDayButtons(startDate, endDate);
        } else if (filterType === 'monthly-range') {
            generateMonthButtons(startDate, endDate);
            highlightMatchingMonthButtons(startDate, endDate);
        } else if (filterType === 'yearly-range') {
            generateYearButtons(startDate, endDate);
            highlightMatchingYearButtons(startDate, endDate);
        } else {
            adjustTableHeight();
        }

        requestReport(startDate, endDate);
    });

    $(document).on('click', '.day-button-all', function() {
        const $container = $(this).closest('.day-shortcut-container');
        $container.find('.day-button').removeClass('day-button-active');
        $(this).addClass('day-button-active');

        const startDate = $startDate.val();
        const endDate = $endDate.val() || startDate;
        if (startDate) {
            requestReport(startDate, endDate);
        }

        adjustTableHeight();
    });

    toggleGenerateButton();
    adjustTableHeight();
});
</script>
</html>