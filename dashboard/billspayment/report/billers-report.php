<?php
// Connect to the database
include '../../../config/config.php';
require '../../../vendor/autoload.php';

// Start the session
session_start();


if (isset($_SESSION['user_type'])) {
    $current_user_email = '';
    if ($_SESSION['user_type'] === 'admin' && isset($_SESSION['admin_email'])) {
        $current_user_email = $_SESSION['admin_email'];
    } elseif ($_SESSION['user_type'] === 'user' && isset($_SESSION['user_email'])) {
        $current_user_email = $_SESSION['user_email'];
    }else{
        // Redirect to login page if user_type is not set
        header("Location: ../../../index.php");
        session_abort();
        session_destroy();
        exit();
    }
}else {
    // Redirect to login page if user_type is not set
    header("Location: ../../../index.php");
    session_abort();
    session_destroy();
    exit();
}


if (isset($_POST['action']) && $_POST['action'] === 'generate_report') {
    header('Content-Type: application/json');

    $billerlist = isset($_POST['billerlist']) ? trim($_POST['billerlist']) : '';
    $filterType = isset($_POST['filterType']) ? trim($_POST['filterType']) : '';
    $startDate = isset($_POST['startDate']) ? trim($_POST['startDate']) : '';
    $endDate = isset($_POST['endDate']) ? trim($_POST['endDate']) : '';

    if ($filterType === 'daily') {
        $endDate = $startDate;
    }

    if ($filterType !== 'daily' && $endDate === '') {
        echo json_encode(['status' => 'error', 'message' => 'End date is required.']);
        exit();
    }

    if ($startDate === '' || $filterType === '') {
        echo json_encode(['status' => 'error', 'message' => 'Missing required filters.']);
        exit();
    }

    if ($filterType === 'monthly') {
        $startDate = $startDate . '-01';
        $endDate = date('Y-m-t', strtotime($endDate . '-01'));
    } elseif ($filterType === 'yearly') {
        $startDate = $startDate . '-01-01';
        $endDate = $endDate . '-12-31';
    }

    $billerWhere = " AND mpm.biller_type IN ('main-biller', 'child-biller')";
    $billerParams = [];
    $billerTypes = '';

    if ($billerlist === 'main-biller' || $billerlist === 'child-biller') {
        $billerWhere = ' AND mpm.biller_type = ?';
        $billerParams[] = $billerlist;
        $billerTypes .= 's';
    }

    $query = "
        WITH partner_name_list AS (
            SELECT
                COALESCE(mpm.partner_id, mpm.partner_id_kpx, CONCAT('temp_', mpm.partner_name)) AS partner_key,
                mpm.partner_name,
                mpm.biller_name,
                mpm.biller_type
            FROM masterdata.partner_masterfile mpm
            WHERE mpm.status = 'ACTIVE' {$billerWhere}
        ),
        summary_vol AS (
            SELECT
                partner_key,
                partner_name,
                COUNT(*) AS vol1,
                SUM(amount_paid) AS principal1,
                SUM(charge_to_partner + charge_to_customer) AS charge1
            FROM (
                SELECT
                    CASE
                        WHEN bt.partner_id IS NOT NULL THEN bt.partner_id
                        WHEN bt.partner_id_kpx IS NOT NULL THEN bt.partner_id_kpx
                        ELSE CONCAT(
                            'temp_',
                            CASE
                                WHEN bt.sub_billers_name IN ('MYLORA CORPORATION', 'JUNANS MARKETING')
                                    THEN bt.sub_billers_name
                                ELSE bt.partner_name
                            END
                        )
                    END COLLATE utf8mb4_general_ci AS partner_key,
                    CASE
                        WHEN bt.sub_billers_name IN ('MYLORA CORPORATION', 'JUNANS MARKETING')
                            THEN bt.sub_billers_name
                        ELSE bt.partner_name
                    END AS partner_name,
                    bt.amount_paid,
                    bt.charge_to_partner,
                    bt.charge_to_customer
                FROM mldb.billspayment_transaction bt
                WHERE
                    (DATE(bt.datetime) BETWEEN ? AND ? OR DATE(bt.cancellation_date) BETWEEN ? AND ?)
                    AND bt.status IS NULL
                    AND bt.branch_id NOT IN ('1','2','4937','4938','4962','4987','4993','4944')
            ) x
            GROUP BY partner_key, partner_name
        ),
        adjustment_vol AS (
            SELECT
                partner_key,
                partner_name,
                COUNT(*) AS vol2,
                SUM(amount_paid) AS principal2,
                SUM(charge_to_partner + charge_to_customer) AS charge2
            FROM (
                SELECT
                    CASE
                        WHEN bt.partner_id IS NOT NULL THEN bt.partner_id
                        WHEN bt.partner_id_kpx IS NOT NULL THEN bt.partner_id_kpx
                        ELSE CONCAT(
                            'temp_',
                            CASE
                                WHEN bt.sub_billers_name IN ('MYLORA CORPORATION', 'JUNANS MARKETING')
                                    THEN bt.sub_billers_name
                                ELSE bt.partner_name
                            END
                        )
                    END COLLATE utf8mb4_general_ci AS partner_key,
                    CASE
                        WHEN bt.sub_billers_name IN ('MYLORA CORPORATION', 'JUNANS MARKETING')
                            THEN bt.sub_billers_name
                        ELSE bt.partner_name
                    END AS partner_name,
                    bt.amount_paid,
                    bt.charge_to_partner,
                    bt.charge_to_customer
                FROM mldb.billspayment_transaction bt
                WHERE
                    (DATE(bt.datetime) BETWEEN ? AND ? OR DATE(bt.cancellation_date) BETWEEN ? AND ?)
                    AND bt.status = '*'
                    AND bt.branch_id NOT IN ('1','2','4937','4938','4962','4987','4993','4944')
            ) x
            GROUP BY partner_key, partner_name
        )
        SELECT
            pml.partner_name,
            CASE WHEN pml.biller_type = 'child-biller' THEN pml.biller_name ELSE '' END AS sub_billers_name,
            pml.biller_name,
            pml.biller_type,
            COALESCE(sv.vol1, 0) AS summary_vol,
            COALESCE(sv.principal1, 0) AS summary_principal,
            COALESCE(sv.charge1, 0) AS summary_charges,
            COALESCE(av.vol2, 0) AS adjustment_vol,
            COALESCE(ABS(av.principal2), 0) AS adjustment_principal,
            COALESCE(ABS(av.charge2), 0) AS adjustment_charges,
            (COALESCE(sv.vol1, 0) - COALESCE(av.vol2, 0)) AS net_vol,
            (COALESCE(sv.principal1, 0) - COALESCE(ABS(av.principal2), 0)) AS net_principal,
            (COALESCE(sv.charge1, 0) - COALESCE(ABS(av.charge2), 0)) AS net_charges
        FROM partner_name_list pml
        LEFT JOIN summary_vol sv
            ON pml.partner_key = sv.partner_key
        LEFT JOIN adjustment_vol av
            ON pml.partner_key = av.partner_key
        ORDER BY pml.partner_name
    ";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        echo json_encode(['status' => 'error', 'message' => 'Failed to prepare query: ' . $conn->error]);
        exit();
    }

    $params = [];
    $types = '';

    if (!empty($billerParams)) {
        $params = array_merge($params, $billerParams);
        $types .= $billerTypes;
    }

    $dateParams = [$startDate, $endDate, $startDate, $endDate, $startDate, $endDate, $startDate, $endDate];
    $params = array_merge($params, $dateParams);
    $types .= 'ssssssss';

    $stmt->bind_param($types, ...$params);

    if (!$stmt->execute()) {
        echo json_encode(['status' => 'error', 'message' => 'Failed to execute query: ' . $stmt->error]);
        $stmt->close();
        exit();
    }

    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    $stmt->close();

    echo json_encode([
        'status' => 'success',
        'data' => $rows
    ]);
    exit();
}


?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Billers Report | <?php if($_SESSION['user_type'] === 'admin' || $_SESSION['user_type'] === 'user') echo ucfirst($_SESSION['user_type']); else echo "Guest";?></title>
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
                    <h2>Billers Report - (UNDER CONSTRUCTION)</h2>
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
                                <!-- Biller List -->
                                <div class="col-md-2 col-sm-6">
                                    <label class="form-label">Biller Type:</label>
                                    <select class="form-select" name="billerlist" required>
                                        <option value="">Select Biller Type</option>
                                        <option value="ALL">ALL</option>
                                        <option value="biller">Biller</option>
                                        <option value="sub-biller">Sub Biller</option>
                                    </select>
                                </div>

                                <!-- Time Frame -->
                                <div class="col-md-2 col-sm-6">
                                    <label class="form-label">Time Frame:</label>
                                    <select class="form-select" name="filterType" required>
                                        <option value="">Select Time Frame</option>
                                        <option value="daily">Per Day</option>
                                        <option value="weekly">Date Range</option>
                                        <option value="monthly">Monthly</option>
                                        <!-- <option value="yearly">Yearly</option> -->
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

                                <!-- Action Buttons -->
                                <div class="col-md-auto col-sm-12">
                                    <div class="d-flex align-items-end flex-wrap" style="gap:8px;">
                                        <button type="button" class="btn btn-secondary" id="generateReport" disabled>Generate</button>
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
                                            <th rowspan="2" class='text-truncate text-center align-middle'>Sub Biller Name</th>
                                            <th colspan="3" class='text-truncate text-center align-middle'>KP7 / KPX</th>
                                            <th colspan="3" class='text-truncate text-center align-middle'>Adjustment</th>
                                            <th colspan="3" class='text-truncate text-center align-middle'>Net</th>
                                        </tr>
                                        <tr>
                                            <!-- Column header for KP7 / KPX -->
                                            <th class='text-center'>Vol.</th>
                                            <th class='text-center'>Principal</th>
                                            <th class='text-center'>Charge</th>

                                            <!-- Column header for Adjustment -->
                                            <th class='text-center'>Vol.</th>
                                            <th class='text-center'>Principal</th>
                                            <th class='text-center'>Charge</th>

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
                                        </tr>
                                    </tbody>
                                    <tfoot class="sticky-bottom table-dark">
                                        <tr>
                                            <th colspan="3" class="text-end">Total : </th>
                                            <th class="text-center" id="totalsummaryvolume">0</th>
                                            <th class="text-end" id="totalsummaryprincipal">0.00</th>
                                            <th class="text-end" id="totalsummarycharge">0.00</th>
                                            <th class="text-center" id="totaladjustmentvolume">0</th>
                                            <th class="text-end" id="totaladjustmentprincipal">0.00</th>
                                            <th class="text-end" id="totaladjustmentcharge">0.00</th>
                                            <th class="text-center" id="totalnetvolume">0</th>
                                            <th class="text-end" id="totalnetprincipal">0.00</th>
                                            <th class="text-end" id="totalnetcharge">0.00</th>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!--<div class="container-fluid">
            <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">Partners Name:</label>
                    <select id="partnerlistDropdown" class="form-select select2" aria-label="Select Partner" name="partnerlist" data-placeholder="Search or select a Partner..." required>
                        <option value="">Select Partner</option>
                        <option value="All">All</option>
                        options will be populated by JS -->
                    <!-- </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Time Frame:</label>
                    <select class="form-select" name="filterType" required>
                        <option value="">Select Time Frame</option>
                        <option value="daily">Per Day</option>
                        <option value="weekly">Date Range</option>
                        <option value="monthly">Monthly</option> -->
                        <!-- <option value="yearly">Yearly</option> -->
                    <!-- </select>
                </div>
                <div class="col-md-2" style="display: none;">
                    <label class="form-label">Start Date:</label>
                    <input type="date" class="form-control" name="startDate" required>
                </div>
                <div class="col-md-2" style="display: none;">
                    <label class="form-label">End Date:</label>
                    <input type="date" class="form-control" name="endDate" required>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="button" class="btn btn-secondary" id="generateReport" disabled>Generate</button>
                    <button class="btn btn-danger" id="exportButton" type="button">Export to</button>
                </div>
            </div> -->

            <!-- <div class="container-fluid">
                <div class="text-center">
                    
                </div>
                <div class="day-shortcut-container mt-2">
                    <div class="day-buttons-label">Filter by Day:</div>
                    <div class="day-buttons-wrapper" id="dayButtonsWrapper">
                        <button class="day-button day-button-all day-button-active" id="allDaysButton">All</button>
                    </div>
                </div>
                <div class="day-shortcut-container mt-2">
                    <div class="day-buttons-label">Filter by Month:</div>
                    <div class="day-buttons-wrapper" id="dayButtonsWrapper">
                        <button class="day-button day-button-all day-button-active" id="allDaysButton">All</button>
                    </div>
                </div>
                <div class="day-shortcut-container mt-2">
                    <div class="day-buttons-label">Filter by Year:</div>
                    <div class="day-buttons-wrapper" id="dayButtonsWrapper">
                        <button class="day-button day-button-all day-button-active" id="allDaysButton">All</button>
                    </div>
                </div>
                <div class="table-responsive mt-2">
                    <table id="transactionReportTable" class="table table-bordered table-hover table-striped">
                        <thead class="table-light">
                            <tr>
                                <th rowspan="2">No.</th>
                                <th rowspan="2">Partner Name</th>
                                <th rowspan="2">Bank</th>
                                <th rowspan="2">Biller's Name</th>
                                <th colspan="3">KP7 / KPX</th>
                                <th colspan="3">Adjustment</th>
                                <th colspan="3">Net</th>
                            </tr>
                            <tr> -->
                                <!-- Column header for KP7 / KPX -->
                                <!-- <th>Vol.</th>
                                <th>Principal</th>
                                <th>Charge</th> -->

                                <!-- Column header for Adjustment -->
                                <!-- <th>Vol.</th>
                                <th>Principal</th>
                                <th>Charge</th> -->

                                <!-- Column header for Net -->
                                <!-- <th>Vol.</th>
                                <th>Principal</th>
                                <th>Charge</th> -->
                            <!-- </tr>
                        </thead>
                        <tbody> -->
                            <!-- Data will be populated via JavaScript -->
                            <!-- <tr>
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
                        <tfoot>
                            <tr>
                                <th colspan="4" class="text-end">Total : </th>
                                <th id="totalsummaryvolume">0</th>
                                <th id="totalsummaryprincipal">0.00</th>
                                <th id="totalsummarycharge">0.00</th>
                                <th id="totaladjustmentvolume">0</th>
                                <th id="totaladjustmentprincipal">0.00</th>
                                <th id="totaladjustmentcharge">0.00</th>
                                <th id="totalnetvolume">0</th>
                                <th id="totalnetprincipal">0.00</th>
                                <th id="totalnetcharge">0.00</th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div> -->
    </div>
</body>
<?php include '../../../templates/footer.php'; ?>
<script>
$(document).ready(function() {
    // Initialize Select2 for partner dropdown
    $('#partnerlistDropdown').select2({
        placeholder: 'Search or select a Partner...',
        allowClear: true
    });
    // Initialize filter containers as hidden
    $('.day-shortcut-container').hide();
    
    // Hide date inputs by default until filter type is selected
    $('input[name="startDate"]').closest('.col-md-2').hide();
    $('input[name="endDate"]').closest('.col-md-2').hide();
    
    // Hide export button initially
    $('#exportButton').hide();
    // Client-side cache for debug responses
    var debugCache = {};
    var currentReportData = [];

    const mockPartners = [
        'SECURITY BANK',
        'MYLORA CORPORATION',
        'JUNANS MARKETING',
        'MERCHANTS BANK',
        'EASTWEST FINANCE',
        'RURALNET PAYMENTS'
    ];

    const mockBaseData = [
        { partner_name: 'SECURITY BANK', sub_billers_name: '', summary_vol: 320, summary_principal: 1850000, summary_charges: 46500, adjustment_vol: 12, adjustment_principal: 55200, adjustment_charges: 1260 },
        { partner_name: 'MYLORA CORPORATION', sub_billers_name: 'MYLORA CORPORATION', summary_vol: 145, summary_principal: 734500, summary_charges: 22110, adjustment_vol: 5, adjustment_principal: 21300, adjustment_charges: 590 },
        { partner_name: 'JUNANS MARKETING', sub_billers_name: 'JUNANS MARKETING', summary_vol: 98, summary_principal: 402100, summary_charges: 11870, adjustment_vol: 4, adjustment_principal: 18150, adjustment_charges: 410 },
        { partner_name: 'MERCHANTS BANK', sub_billers_name: '', summary_vol: 210, summary_principal: 990300, summary_charges: 28640, adjustment_vol: 7, adjustment_principal: 28600, adjustment_charges: 700 },
        { partner_name: 'EASTWEST FINANCE', sub_billers_name: '', summary_vol: 188, summary_principal: 811200, summary_charges: 24360, adjustment_vol: 6, adjustment_principal: 24000, adjustment_charges: 620 },
        { partner_name: 'RURALNET PAYMENTS', sub_billers_name: '', summary_vol: 120, summary_principal: 521000, summary_charges: 16220, adjustment_vol: 3, adjustment_principal: 9800, adjustment_charges: 290 }
    ];

    function seededNumber(seed, min, max) {
        const x = Math.sin(seed) * 10000;
        const normalized = x - Math.floor(x);
        return min + (max - min) * normalized;
    }

    function buildMockReportData(partner, filterType, startDate, endDate) {
        const dateSeed = (`${filterType}|${startDate}|${endDate}`).split('').reduce((acc, ch) => acc + ch.charCodeAt(0), 0);
        let factor = 1;

        if (filterType === 'daily') {
            factor = 0.12;
        } else if (filterType === 'weekly') {
            factor = 0.6;
        } else if (filterType === 'monthly') {
            factor = 1;
        } else if (filterType === 'yearly') {
            factor = 4.2;
        }

        let rows = mockBaseData.map((row, idx) => {
            const variance = seededNumber(dateSeed + idx, 0.88, 1.18);
            const scaledSummaryVol = Math.max(0, Math.round(row.summary_vol * factor * variance));
            const scaledSummaryPrincipal = Math.max(0, row.summary_principal * factor * variance);
            const scaledSummaryCharges = Math.max(0, row.summary_charges * factor * variance);
            const scaledAdjustmentVol = Math.max(0, Math.round(row.adjustment_vol * factor * seededNumber(dateSeed + idx + 99, 0.75, 1.25)));
            const scaledAdjustmentPrincipal = Math.max(0, row.adjustment_principal * factor * seededNumber(dateSeed + idx + 199, 0.75, 1.25));
            const scaledAdjustmentCharges = Math.max(0, row.adjustment_charges * factor * seededNumber(dateSeed + idx + 299, 0.75, 1.25));

            return {
                partner_name: row.partner_name,
                sub_billers_name: row.sub_billers_name,
                summary_vol: scaledSummaryVol,
                summary_principal: scaledSummaryPrincipal,
                summary_charges: scaledSummaryCharges,
                adjustment_vol: scaledAdjustmentVol,
                adjustment_principal: scaledAdjustmentPrincipal,
                adjustment_charges: scaledAdjustmentCharges,
                net_vol: scaledSummaryVol - scaledAdjustmentVol,
                net_principal: scaledSummaryPrincipal - scaledAdjustmentPrincipal,
                net_charges: scaledSummaryCharges - scaledAdjustmentCharges
            };
        });

        if (partner && partner !== 'All') {
            rows = rows.filter((row) => row.partner_name === partner);
        }

        return rows;
    }

    function fetchReportData(billerlist, filterType, startDate, endDate) {
        return $.ajax({
            url: window.location.pathname,
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'generate_report',
                billerlist: billerlist,
                filterType: filterType,
                startDate: startDate,
                endDate: endDate
            }
        });
    }

    function buildMockDebugResponse(partner, filterType, startDate, endDate) {
        const reportRows = buildMockReportData(partner, filterType, startDate, endDate);
        const row = reportRows.length ? reportRows[0] : null;

        if (!row) {
            return { status: 'error', message: 'No debug data found for selected criteria.' };
        }

        return {
            status: 'success',
            partner_master: {
                partner_name: row.partner_name,
                partner_id: `MOCK-${row.partner_name.replace(/\s+/g, '-').toUpperCase()}`,
                partner_id_kpx: `KPX-${row.partner_name.replace(/\s+/g, '-').toUpperCase()}`,
                status: 'ACTIVE',
                source: 'FRONTEND-MOCK'
            },
            groups: [
                {
                    partner_id: `MOCK-${row.partner_name.replace(/\s+/g, '-').toUpperCase()}`,
                    partner_id_kpx: `KPX-${row.partner_name.replace(/\s+/g, '-').toUpperCase()}`,
                    vol: row.summary_vol,
                    principal: row.summary_principal,
                    charge: row.summary_charges
                }
            ],
            normalized: [
                {
                    partner_key: `MOCK-${row.partner_name.replace(/\s+/g, '-').toUpperCase()}`,
                    vol: row.net_vol,
                    principal: row.net_principal,
                    charge: row.net_charges
                }
            ],
            transactions: [
                {
                    id: 1,
                    datetime: `${startDate} 08:15:00`,
                    partner_id: `MOCK-${row.partner_name.replace(/\s+/g, '-').toUpperCase()}`,
                    partner_id_kpx: `KPX-${row.partner_name.replace(/\s+/g, '-').toUpperCase()}`,
                    amount_paid: Math.max(100, row.summary_principal / Math.max(1, row.summary_vol)),
                    charge_to_partner: Math.max(10, row.summary_charges / Math.max(1, row.summary_vol)),
                    charge_to_customer: 5,
                    status: null
                },
                {
                    id: 2,
                    datetime: `${endDate} 15:40:00`,
                    partner_id: `MOCK-${row.partner_name.replace(/\s+/g, '-').toUpperCase()}`,
                    partner_id_kpx: `KPX-${row.partner_name.replace(/\s+/g, '-').toUpperCase()}`,
                    amount_paid: Math.max(50, row.adjustment_principal / Math.max(1, row.adjustment_vol || 1)),
                    charge_to_partner: Math.max(5, row.adjustment_charges / Math.max(1, row.adjustment_vol || 1)),
                    charge_to_customer: 2,
                    status: '*'
                }
            ]
        };
    }
    
    // Handle date input changes
    $('input[name="startDate"], input[name="endDate"]').on('change', function() {
        toggleGenerateButton();
    });
    
    // Handle filter type change - show appropriate input fields
    $('select[name="filterType"]').on('change', function() {
        const filterType = $(this).val();
        
        // Hide all input containers first
        $('input[name="startDate"]').closest('.col-md-2').hide();
        $('input[name="endDate"]').closest('.col-md-2').hide();
        
        // Reset input values
        $('input[name="startDate"]').val('');
        $('input[name="endDate"]').val('');
        
        // Hide all filter containers and reset them to default state
        $('.day-shortcut-container').hide();
        resetFilterContainers();
        
        // Hide export button when filter type changes
        $('#exportButton').hide();
        
        // Clear the report table
        clearReportTable();
        
        if (filterType) {
            // Show and configure inputs based on filter type
            configureInputsForFilterType(filterType);
            const $startDateInput = $('input[name="startDate"]');
            const $endDateInput = $('input[name="endDate"]');
            
            // Show the appropriate filter containers
            switch(filterType) {
                case 'daily':
                    $startDateInput.closest('.col-md-2').show();
                    $endDateInput.closest('.col-md-2').hide();
                    break;
                case 'weekly':
                    // Show both input containers
                    $startDateInput.closest('.col-md-2').show();
                    $endDateInput.closest('.col-md-2').show();
                    break;
                case 'monthly':
                    // Show both input containers
                    $startDateInput.closest('.col-md-2').show();
                    $endDateInput.closest('.col-md-2').show();
                    break;
                case 'yearly':
                    // Show both input containers
                    $startDateInput.closest('.col-md-2').show();
                    $endDateInput.closest('.col-md-2').show();
                    break;
            }
        }
        
        toggleGenerateButton();
    });
    
    function configureInputsForFilterType(filterType) {
        const startDateInput = $('input[name="startDate"]');
        const endDateInput = $('input[name="endDate"]');
        const startLabel = startDateInput.closest('.col-md-2').find('label');
        const endLabel = endDateInput.closest('.col-md-2').find('label');
        
        switch(filterType) {
            case 'daily':
                // Date input for daily
                startDateInput.attr('type', 'date');
                startLabel.text('Select Date:');
                break;
            case 'weekly':
                // Date input for weekly
                startDateInput.attr('type', 'date');
                endDateInput.attr('type', 'date');
                startLabel.text('Start Date:');
                endLabel.text('End Date:');
                break;
                
            case 'monthly':
                // Month input for monthly
                startDateInput.attr('type', 'month');
                endDateInput.attr('type', 'month');
                startLabel.text('Start Month:');
                endLabel.text('End Month:');
                break;
                
            case 'yearly':
                // Year input for yearly
                startDateInput.attr('type', 'number');
                endDateInput.attr('type', 'number');
                startDateInput.attr('min', '2020');
                endDateInput.attr('min', '2020');
                startDateInput.attr('max', '2030');
                endDateInput.attr('max', '2030');
                startDateInput.attr('placeholder', 'YYYY');
                endDateInput.attr('placeholder', 'YYYY');
                startLabel.text('Start Year:');
                endLabel.text('End Year:');
                break;
        }
    }
    
    // Handle filter button clicks
    $('.day-button').on('click', function() {
        const container = $(this).closest('.day-shortcut-container');
        
        // Remove active class from all buttons in this container
        container.find('.day-button').removeClass('day-button-active');
        
        // Add active class to clicked button
        $(this).addClass('day-button-active');
        
        // Update date inputs based on selection
        updateDateInputsFromFilter($(this));
        
        // Enable generate button
        toggleGenerateButton();
    });
    
    // Generate button click handler
    $('#generateReport').on('click', function() {
        const filterType = $('select[name="filterType"]').val();
        const billerType = $('select[name="billerlist"]').val();
        let startDate = $('input[name="startDate"]').val();
        let endDate = $('input[name="endDate"]').val();
        
        if (!filterType || !billerType) {
            Swal.fire({
                icon: 'warning',
                title: 'Missing Information',
                text: 'Please select both Time Frame and Biller Type before generating the report.'
            });
            return;
        }
        
        if (!startDate) {
            Swal.fire({
                icon: 'warning',
                title: 'Missing Date',
                text: filterType === 'daily' ? 'Please select a date.' : 'Please fill in the Start Date.'
            });
            return;
        }
        
        // For daily selection, set endDate same as startDate
        if (filterType === 'daily') {
            endDate = startDate;
        } else {
            // For other filter types, check if endDate is provided
            if (!endDate) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Missing End Date',
                    text: 'Please fill in the End Date.'
                });
                return;
            }
        }
        
        console.log('Generating report with:', {
            filterType: filterType,
            billerType: billerType,
            startDate: startDate,
            endDate: endDate
        });
        
        // Determine and show appropriate filter containers based on date format
        showFilterContainersBasedOnDates(startDate, endDate);
        
        // Show loading
        $('#loading-overlay').css('display', 'flex');

        fetchReportData(billerType, filterType, startDate, endDate)
            .then(function(result) {
                if (!result || result.status !== 'success') {
                    throw new Error(result && result.message ? result.message : 'Failed to load report data.');
                }
                $('#loading-overlay').hide();
                debugCache = {};
                currentReportData = Array.isArray(result.data) ? result.data : [];
                requestAnimationFrame(function() {
                    populateReportTable(currentReportData);
                });
            })
            .catch(function(error) {
                $('#loading-overlay').hide();
                console.error('Report error:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: error && error.message ? error.message : 'Failed to generate report.'
                });
            });
    });

    function adjustTableHeight() {
        const dayFilter = $('#dayFilterContainer');
        const monthFilter = $('#monthFilterContainer');
        const yearFilter = $('#yearFilterContainer');
        const tableContainer = $('#tableContainer');
        
        // Check if any filter containers are visible
        const hasVisibleFilters = dayFilter.is(':visible') || monthFilter.is(':visible') || yearFilter.is(':visible');
        
        if (hasVisibleFilters) {
            tableContainer.css('max-height', '700px');
        } else {
            tableContainer.css('max-height', '745px');
        }
    }
    
    function showFilterContainersBasedOnDates(startDate, endDate) {
        // Hide all containers first
        $('.day-shortcut-container').hide();
        
        // Reset all filter buttons
        $('.day-button').removeClass('day-button-active');
        
        const filterType = $('select[name="filterType"]').val();
        
        // Show appropriate containers based on filter type
        switch(filterType) {
            case 'daily':
                $('#dayFilterContainer').hide();
                generateDayButtons(startDate, endDate);
                highlightMatchingDayButtons(startDate, endDate);
                break;
            case 'weekly':
                $('#dayFilterContainer').show();
                generateDayButtons(startDate, endDate);
                highlightMatchingDayButtons(startDate, endDate);
                break;
            case 'monthly':
                $('#monthFilterContainer').show();
                generateMonthButtons(startDate, endDate);
                highlightMatchingMonthButtons(startDate, endDate);
                break;
            case 'yearly':
                $('#yearFilterContainer').show();
                generateYearButtons(startDate, endDate);
                highlightMatchingYearButtons(startDate, endDate);
                break;
        }

        // Adjust table height after showing/hiding filters
        adjustTableHeight();
    }
    
    function generateDayButtons(startDate, endDate) {
        const container = $('.day-shortcut-container').eq(0);
        const wrapper = container.find('.day-buttons-wrapper');
        
        // Clear existing buttons except "All"
        wrapper.find('.day-button:not(.day-button-all)').remove();
        
        // Generate day range from startDate to endDate
        const start = new Date(startDate);
        const end = new Date(endDate);
        const dateButtons = [];
        
        // Create buttons for each date in the range
        for (let d = new Date(start); d <= end; d.setDate(d.getDate() + 1)) {
            const dateString = formatDate(d);
            const day = d.getDate();
            const month = d.toLocaleDateString('en-US', { month: 'short' });
            const displayText = `${day} ${month}`;
            
            dateButtons.push({
                date: dateString,
                day: day,
                display: displayText,
                fullDate: new Date(d)
            });
        }
        
        // Group by month for better organization
        const monthGroups = {};
        dateButtons.forEach(btn => {
            const monthYear = btn.fullDate.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
            if (!monthGroups[monthYear]) {
                monthGroups[monthYear] = [];
            }
            monthGroups[monthYear].push(btn);
        });
        
        // Create month separators and buttons
        Object.keys(monthGroups).forEach((monthYear, index) => {
            // Add month separator if more than one month
            if (Object.keys(monthGroups).length > 1) {
                const monthSeparator = $(`<div class="month-separator" style="width: 100%; text-align: center; font-size: 10px; color: #666; margin: 5px 0; font-weight: bold;">${monthYear}</div>`);
                wrapper.append(monthSeparator);
            }
            
            // Add day buttons for this month
            monthGroups[monthYear].forEach(btnData => {
                const button = $(`<button type="button" class="day-button day-number-button" data-date="${btnData.date}" data-day="${btnData.day}" title="${btnData.date}">${btnData.day}</button>`);
                
                // Apply circular styling for day buttons
                button.css({
                    'width': '35px',
                    'height': '35px',
                    'border-radius': '50%',
                    'font-size': '12px',
                    'margin': '2px'
                });
                
                // Add click handler for day filtering
                button.on('click', function() {
                    filterBySpecificDateRange($(this), btnData.date, btnData.date);
                });
                wrapper.append(button);
            });
            
            // Add a small gap between months if multiple months
            if (Object.keys(monthGroups).length > 1 && index < Object.keys(monthGroups).length - 1) {
                const gap = $('<div style="width: 100%; height: 5px;"></div>');
                wrapper.append(gap);
            }
        });
    }

    function generateMonthButtons(startDate, endDate) {
        const container = $('.day-shortcut-container').eq(1);
        const wrapper = container.find('.day-buttons-wrapper');
        
        // Clear existing buttons except "All"
        wrapper.find('.day-button:not(.day-button-all)').remove();
        
        const start = new Date(startDate + '-01');
        const end = new Date(endDate + '-01');
        const months = [];
        
        for (let d = new Date(start); d <= end; d.setMonth(d.getMonth() + 1)) {
            const yearMonth = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
            const monthName = d.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
            months.push({ value: yearMonth, label: monthName });
        }
        
        // Create buttons for each month
        months.forEach(month => {
            const button = $(`<button type="button" class="day-button month-button" data-date="${month.value}">${month.label}</button>`);
            
            // Apply pill shape styling for month buttons
            button.css({
                'width': 'auto',
                'min-width': '120px',
                'padding': '8px 16px',
                'border-radius': '25px',
                'font-size': '12px',
                'white-space': 'nowrap'
            });
            
            // Add click handler for month filtering
            button.on('click', function() {
                filterBySpecificMonth($(this));
            });
            wrapper.append(button);
        });
    }

    function generateYearButtons(startDate, endDate) {
        const container = $('.day-shortcut-container').eq(2);
        const wrapper = container.find('.day-buttons-wrapper');
        
        // Clear existing buttons except "All"
        wrapper.find('.day-button:not(.day-button-all)').remove();
        
        const startYear = parseInt(startDate);
        const endYear = parseInt(endDate);
        
        for (let year = startYear; year <= endYear; year++) {
            const button = $(`<button type="button" class="day-button year-button" data-date="${year}">${year}</button>`);
            
            // Apply pill shape styling for year buttons
            button.css({
                'width': 'auto',
                'min-width': '70px',
                'padding': '8px 16px',
                'border-radius': '25px',
                'font-size': '12px'
            });
            
            // Add click handler for year filtering
            button.on('click', function() {
                filterBySpecificYear($(this));
            });
            wrapper.append(button);
        }
    }

    // New function to handle date range filtering
    function filterBySpecificDateRange(button, specificStartDate, specificEndDate) {
        const container = button.closest('.day-shortcut-container');
        
        // Remove active class from all buttons in this container
        container.find('.day-button').removeClass('day-button-active');
        // Add active class to clicked button
        button.addClass('day-button-active');
        
        // Generate report for specific date range
        generateFilteredReport(specificStartDate, specificEndDate);
    }

    // Update the existing filterBySpecificDay function to use the new approach
    function filterBySpecificDay(button, originalStartDate, originalEndDate) {
        const container = button.closest('.day-shortcut-container');
        const buttonDate = button.data('date');
        
        // Remove active class from all buttons in this container
        container.find('.day-button').removeClass('day-button-active');
        // Add active class to clicked button
        button.addClass('day-button-active');
        
        // If the button has a full date, use it directly
        if (buttonDate && buttonDate.includes('-')) {
            generateFilteredReport(buttonDate, buttonDate);
        } else {
            // Legacy support for day number only
            const day = button.data('day') || buttonDate;
            const startDateParts = originalStartDate.split('-');
            const year = startDateParts[0];
            const month = startDateParts[1];
            const specificDate = `${year}-${month}-${String(day).padStart(2, '0')}`;
            generateFilteredReport(specificDate, specificDate);
        }
    }

    // New function to filter by specific month
    function filterBySpecificMonth(button) {
        const container = button.closest('.day-shortcut-container');
        const monthValue = button.data('date');
        
        // Remove active class from all buttons in this container
        container.find('.day-button').removeClass('day-button-active');
        // Add active class to clicked button
        button.addClass('day-button-active');
        
        // Generate report for specific month
        generateFilteredReport(monthValue, monthValue);
    }

    // New function to filter by specific year
    function filterBySpecificYear(button) {
        const container = button.closest('.day-shortcut-container');
        const year = button.data('date');
        
        // Remove active class from all buttons in this container
        container.find('.day-button').removeClass('day-button-active');
        // Add active class to clicked button
        button.addClass('day-button-active');
        
        // Generate report for specific year
        generateFilteredReport(year, year);
    }

    // Update the formatDate helper function if it doesn't exist
    function formatDate(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    }

    // Update the highlightMatchingDayButtons function to work with the new structure
    function highlightMatchingDayButtons(startDate, endDate) {
        const container = $('.day-shortcut-container').eq(0);
        
        if (startDate === endDate) {
            // Single day selected - find and highlight the specific date button
            const dayButton = container.find(`[data-date="${startDate}"]`);
            if (dayButton.length) {
                dayButton.addClass('day-button-active');
            } else {
                // Fallback to "All" if specific date not found
                container.find('.day-button-all').addClass('day-button-active');
            }
        } else {
            // Multiple days or range - highlight "All"
            container.find('.day-button-all').addClass('day-button-active');
        }
    }
    
    function highlightMatchingMonthButtons(startDate, endDate) {
        const container = $('.day-shortcut-container').eq(1);
        
        if (startDate === endDate) {
            // Single month selected
            const monthButton = container.find(`[data-date="${startDate}"]`);
            if (monthButton.length) {
                monthButton.addClass('day-button-active');
            } else {
                // Fallback to "All" if specific month not found
                container.find('.day-button-all').addClass('day-button-active');
            }
        } else {
            // Multiple months or range - highlight "All"
            container.find('.day-button-all').addClass('day-button-active');
        }
    }
    
    function highlightMatchingYearButtons(startDate, endDate) {
        const container = $('.day-shortcut-container').eq(2);
        
        if (startDate === endDate) {
            // Single year selected
            const yearButton = container.find(`[data-date="${startDate}"]`);
            if (yearButton.length) {
                yearButton.addClass('day-button-active');
            } else {
                // Fallback to "All" if specific year not found
                container.find('.day-button-all').addClass('day-button-active');
            }
        } else {
            // Multiple years or range - highlight "All"
            container.find('.day-button-all').addClass('day-button-active');
        }
    }
    
    function toggleGenerateButton() {
        const filterType = $('select[name="filterType"]').val();
        const billerType = $('select[name="billerlist"]').val();
        const startDate = $('input[name="startDate"]').val();
        const endDate = $('input[name="endDate"]').val();
        
        // For daily filter, only check startDate since endDate is hidden
        let datesValid = false;
        if (filterType === 'daily') {
            datesValid = startDate !== '';
        } else {
            datesValid = startDate !== '' && endDate !== '';
        }
        
        const enable = (filterType && billerType && datesValid);
        if (enable) {
            $('#generateReport').prop('disabled', false).removeClass('btn-secondary').addClass('btn-danger');
        } else {
            $('#generateReport').prop('disabled', true).removeClass('btn-danger').addClass('btn-secondary');
        }

        // debug visibility handled after report generation
    }
    
    function updateDateInputsFromFilter(button) {
        const buttonData = button.data('date');
        const currentDate = new Date();
        const currentYear = currentDate.getFullYear();
        const currentMonth = String(currentDate.getMonth() + 1).padStart(2, '0');
        const container = button.closest('.day-shortcut-container');
        const containerIndex = $('.day-shortcut-container').index(container);
        const filterType = $('select[name="filterType"]').val();
        
        if (button.hasClass('day-button-all')) {
            // Handle "All" selection - keep the original date range
            // Don't change the inputs when "All" is selected
            return;
        } else {
            // Handle specific selection
            if (containerIndex === 0 && buttonData) {
                // Day selection - set to specific day, but use the month/year from the original range
                const originalStartDate = $('input[name="startDate"]').val();
                const dateParts = originalStartDate.split('-');
                const year = dateParts[0];
                const month = dateParts[1];
                const day = String(buttonData).padStart(2, '0');
                
                $('input[name="startDate"]').val(`${year}-${month}-${day}`);
                $('input[name="endDate"]').val(`${year}-${month}-${day}`);
            } else if (containerIndex === 1 && buttonData) {
                // Month selection
                $('input[name="startDate"]').val(buttonData);
                $('input[name="endDate"]').val(buttonData);
            } else if (containerIndex === 2 && buttonData) {
                // Year selection
                $('input[name="startDate"]').val(buttonData);
                $('input[name="endDate"]').val(buttonData);
            }
        }
        
        // Update generate button state
        toggleGenerateButton();
    }
    
    function populateReportTable(data) {
        const tbody = $('#transactionReportTable tbody');
        tbody.empty();
        
        let totals = {
            summaryVol: 0,
            summaryPrincipal: 0,
            summaryCharge: 0,
            adjustmentVol: 0,
            adjustmentPrincipal: 0,
            adjustmentCharge: 0,
            netVol: 0,
            netPrincipal: 0,
            netCharge: 0
        };
        
        // Ensure data is an array
        if (!Array.isArray(data)) {
            console.error('Data is not an array:', data);
            data = [];
        }
        
        if (data.length === 0) {
            tbody.append('<tr><td colspan="12" class="text-center">No data found for the selected criteria</td></tr>');
        } else {
            // Limit rendered rows to keep UI snappy on large results.
            const displayData = data.slice(0, 15);

            data.forEach((row) => {
                totals.summaryVol += parseInt(row.summary_vol || 0);
                totals.summaryPrincipal += parseFloat(row.summary_principal || 0);
                totals.summaryCharge += parseFloat(row.summary_charges || 0);
                totals.adjustmentVol += parseInt(row.adjustment_vol || 0);
                totals.adjustmentPrincipal += parseFloat(row.adjustment_principal || 0);
                totals.adjustmentCharge += parseFloat(row.adjustment_charges || 0);
                totals.netVol += parseInt(row.net_vol || 0);
                totals.netPrincipal += parseFloat(row.net_principal || 0);
                totals.netCharge += parseFloat(row.net_charges || 0);
            });

            displayData.forEach((row, index) => {
                const sub_billers_name_raw = (row.sub_billers_name || '').toString().trim();
                const partner_name_value = (row.partner_name || '').toString().trim();
                let partner_name_raw = partner_name_value;

                if (sub_billers_name_raw === 'MYLORA CORPORATION' || sub_billers_name_raw === 'JUNANS MARKETING') {
                    partner_name_raw = sub_billers_name_raw;
                } else if (sub_billers_name_raw === '' && partner_name_value === 'SECURITY BANK') {
                    partner_name_raw = partner_name_value;
                }

                const tr = $(`
                <tr>
                    <td>${index + 1}</td>
                    <td>${partner_name_raw}</td>
                    <td>${sub_billers_name_raw}</td>
                    <td class="text-end">${parseInt(row.summary_vol || 0).toLocaleString()}</td>
                    <td class="text-end">${parseFloat(row.summary_principal || 0).toLocaleString('en-US', {minimumFractionDigits: 2})}</td>
                    <td class="text-end">${parseFloat(row.summary_charges || 0).toLocaleString('en-US', {minimumFractionDigits: 2})}</td>
                    <td class="text-end">${parseInt(row.adjustment_vol || 0).toLocaleString()}</td>
                    <td class="text-end">${parseFloat(row.adjustment_principal || 0).toLocaleString('en-US', {minimumFractionDigits: 2})}</td>
                    <td class="text-end">${parseFloat(row.adjustment_charges || 0).toLocaleString('en-US', {minimumFractionDigits: 2})}</td>
                    <td class="text-end">${parseInt(row.net_vol || 0).toLocaleString()}</td>
                    <td class="text-end">${parseFloat(row.net_principal || 0).toLocaleString('en-US', {minimumFractionDigits: 2})}</td>
                    <td class="text-end">${parseFloat(row.net_charges || 0).toLocaleString('en-US', {minimumFractionDigits: 2})}</td>
                </tr>
            `);
                tbody.append(tr);
            });
        }
        
        // Update totals
        $('#totalsummaryvolume').text(totals.summaryVol.toLocaleString());
        $('#totalsummaryprincipal').text(totals.summaryPrincipal.toLocaleString('en-US', {minimumFractionDigits: 2}));
        $('#totalsummarycharge').text(totals.summaryCharge.toLocaleString('en-US', {minimumFractionDigits: 2}));
        $('#totaladjustmentvolume').text(totals.adjustmentVol.toLocaleString());
        $('#totaladjustmentprincipal').text(totals.adjustmentPrincipal.toLocaleString('en-US', {minimumFractionDigits: 2}));
        $('#totaladjustmentcharge').text(totals.adjustmentCharge.toLocaleString('en-US', {minimumFractionDigits: 2}));
        $('#totalnetvolume').text(totals.netVol.toLocaleString());
        $('#totalnetprincipal').text(totals.netPrincipal.toLocaleString('en-US', {minimumFractionDigits: 2}));
        $('#totalnetcharge').text(totals.netCharge.toLocaleString('en-US', {minimumFractionDigits: 2}));
        
        // Check if all totals are zero and toggle export button visibility
        toggleExportButton(totals);

        // Show debug button only after a report is generated and when a specific partner + weekly (date range) is used
        const selectedPartner = $('#partnerlistDropdown').val();
        const selectedFilter = $('select[name="filterType"]').val();
        if (selectedPartner && selectedPartner !== 'All' && selectedFilter === 'weekly' && Array.isArray(data) && data.length > 0) {
            $('#debugButton').show();
        } else {
            $('#debugButton').hide();
        }
    }
    
    function toggleExportButton(totals) {
        const hasData = totals.summaryVol > 0 || 
            totals.summaryPrincipal > 0 || 
            totals.summaryCharge > 0 || 
            totals.adjustmentVol > 0 || 
            totals.adjustmentPrincipal > 0 || 
            totals.adjustmentCharge > 0 || 
            totals.netVol > 0 || 
            totals.netPrincipal > 0 || 
            totals.netCharge > 0;
        
        if (hasData) {
            $('#exportButton').show();
        } else {
            $('#exportButton').hide();
        }
    }
    
    // Load partners on page load
    loadPartners();
    
    function loadPartners() {
        const select = $('#partnerlistDropdown');
        mockPartners.forEach(function(partnerName) {
            select.append(new Option(partnerName, partnerName));
        });
    }
    
    // Partner selection change handler
    $('#partnerlistDropdown').on('change', function() {
        // Hide filter containers when partner changes
        $('.day-shortcut-container').hide();
        resetFilterContainers();
        
        // Hide export button when partner changes
        $('#exportButton').hide();
        
        // Clear the report table
        clearReportTable();
        
        toggleGenerateButton();
    });

    // Biller type selection change handler (active filter in current UI)
    $('select[name="billerlist"]').on('change', function() {
        $('.day-shortcut-container').hide();
        resetFilterContainers();
        $('#exportButton').hide();
        $('#debugButton').hide();
        clearReportTable();
        toggleGenerateButton();
    });

    // Add this export button click handler in your existing $(document).ready(function() {
    $('#exportButton').on('click', function() {
        Swal.fire({
            title: 'Export Report',
            text: 'Choose your preferred export format:',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-file-pdf"></i> PDF Format',
            cancelButtonText: '<i class="fas fa-file-excel"></i> XLS Format',
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#28a745',
            customClass: {
                confirmButton: 'btn-export-pdf',
                cancelButton: 'btn-export-xls'
            },
            buttonsStyling: false,
            allowOutsideClick: true,
            allowEscapeKey: true,
            reverseButtons: true,
            html: `
                <div class="export-options">
                    <p>Select the format you would like to export the Volume Report:</p>
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
                // Handle PDF export
                // document.getElementById('exportPDF').addEventListener('click', function() {
                //     Swal.fire({
                //         title: 'Exporting to PDF...',
                //         text: 'Please wait while we generate your PDF report.',
                //         icon: 'info',
                //         allowOutsideClick: false,
                //         allowEscapeKey: false,
                //         showConfirmButton: false,
                //         didOpen: () => {
                //             Swal.showLoading();
                //             // Add your PDF export logic here
                //             setTimeout(() => {
                //                 exportToPDF();
                //             }, 1000);
                //         }
                //     });
                // });

                document.getElementById('exportPDF').addEventListener('click', function() {
                    // Close chooser and start PDF export which will open in a new tab
                    Swal.close();
                    exportToPDF();
                });

                // Handle XLS export
                document.getElementById('exportXLS').addEventListener('click', function() {
                    // Close chooser and start Excel export (downloads file)
                    Swal.close();
                    exportToXLS();
                });
            }
        });
    });

    // Build and render debug modal from response
    function renderDebugModal(resp) {
        if (!resp || resp.status === 'error') {
            Swal.fire({ icon: 'error', title: 'Debug Error', text: resp ? (resp.message || 'Unknown error') : 'No debug data returned.' });
            return;
        }

        let html = '<div style="text-align:left; max-height:60vh; overflow:auto; font-size:13px;">';
        html += '<h4>Partner Masterfile</h4>';
        html += '<table class="table table-sm table-bordered"><tbody>';
        for (const k in resp.partner_master) {
            html += `<tr><th style="width:30%">${k}</th><td>${resp.partner_master[k]}</td></tr>`;
        }
        html += '</tbody></table>';

        html += '<h4>Aggregated groups (by partner_id / partner_id_kpx)</h4>';
        if (Array.isArray(resp.groups) && resp.groups.length) {
            html += '<table class="table table-sm table-bordered"><thead><tr><th>partner_id</th><th>partner_id_kpx</th><th>vol</th><th>principal</th><th>charge</th></tr></thead><tbody>';
            resp.groups.forEach(g => {
                html += `<tr><td>${g.partner_id}</td><td>${g.partner_id_kpx}</td><td>${g.vol}</td><td>${parseFloat(g.principal||0).toLocaleString()}</td><td>${parseFloat(g.charge||0).toLocaleString()}</td></tr>`;
            });
            html += '</tbody></table>';
        } else {
            html += '<p>No grouped matches found.</p>';
        }

        html += '<h4>Normalized grouping (COALESCE)</h4>';
        if (Array.isArray(resp.normalized) && resp.normalized.length) {
            html += '<table class="table table-sm table-bordered"><thead><tr><th>partner_key</th><th>vol</th><th>principal</th><th>charge</th></tr></thead><tbody>';
            resp.normalized.forEach(n => {
                html += `<tr><td>${n.partner_key}</td><td>${n.vol}</td><td>${parseFloat(n.principal||0).toLocaleString()}</td><td>${parseFloat(n.charge||0).toLocaleString()}</td></tr>`;
            });
            html += '</tbody></table>';
        } else {
            html += '<p>No normalized groups found.</p>';
        }

        html += '<h4>Sample Transactions (first 200)</h4>';
        if (Array.isArray(resp.transactions) && resp.transactions.length) {
            html += '<table class="table table-sm table-bordered"><thead><tr><th>id</th><th>datetime</th><th>partner_id</th><th>partner_id_kpx</th><th>amount_paid</th><th>charge_to_partner</th><th>charge_to_customer</th><th>status</th></tr></thead><tbody>';
            resp.transactions.slice(0,200).forEach(t => {
                html += `<tr><td>${t.id||''}</td><td>${t.datetime||''}</td><td>${t.partner_id||''}</td><td>${t.partner_id_kpx||''}</td><td>${parseFloat(t.amount_paid||0).toLocaleString()}</td><td>${parseFloat(t.charge_to_partner||0).toLocaleString()}</td><td>${parseFloat(t.charge_to_customer||0).toLocaleString()}</td><td>${t.status||''}</td></tr>`;
            });
            html += '</tbody></table>';
        } else {
            html += '<p>No transactions matched the selected partner and date range.</p>';
        }

        html += '</div>';

        Swal.fire({ title: 'Debug Details', html: html, width: 900, customClass: { popup: 'swal2-overflow' }, confirmButtonText: 'Close' });
    }

    // Debug button click handler with client-side caching
    $('#debugButton').on('click', function() {
        const filterType = $('select[name="filterType"]').val();
        const partner = $('#partnerlistDropdown').val();
        const startDate = $('input[name="startDate"]').val();
        const endDate = $('input[name="endDate"]').val();

        if (!partner || partner === 'All') {
            Swal.fire({ icon: 'warning', title: 'Select Partner', text: 'Please select a specific partner to debug.' });
            return;
        }
        if (filterType !== 'weekly') {
            Swal.fire({ icon: 'warning', title: 'Invalid Time Frame', text: 'Debug is available when Time Frame is Date Range.' });
            return;
        }

        const cacheKey = [filterType, partner, startDate, endDate].join('|');

        // Use cached response if available
        if (debugCache[cacheKey]) {
            renderDebugModal(debugCache[cacheKey]);
            return;
        }

        // Not cached: build from mock data and cache
        $('#loading-overlay').css('display', 'flex');

        setTimeout(function() {
            const resp = buildMockDebugResponse(partner, filterType, startDate, endDate);
            $('#loading-overlay').hide();

            if (!resp || resp.status !== 'success') {
                renderDebugModal(resp || null);
                return;
            }

            debugCache[cacheKey] = resp;
            renderDebugModal(resp);
        }, 200);
    });

    // Export functions
    function exportToPDF() {
        if (!currentReportData.length) {
            Swal.fire({ icon: 'warning', title: 'No Data', text: 'No data available to export.' });
            return;
        }

        const printWindow = window.open('', '_blank');
        if (!printWindow) {
            Swal.fire({ icon: 'error', title: 'Popup Blocked', text: 'Please allow popups to export the report.' });
            return;
        }

        const tableHtml = document.getElementById('transactionReportTable').outerHTML;
        printWindow.document.write('<html><head><title>Billers Report</title>');
        printWindow.document.write('<style>body{font-family:Arial,sans-serif;padding:16px;} table{width:100%;border-collapse:collapse;} th,td{border:1px solid #ddd;padding:6px;font-size:12px;} th{text-align:center;background:#f6f6f6;} td{text-align:right;} td:nth-child(1),td:nth-child(2),td:nth-child(3),td:nth-child(4){text-align:left;}</style>');
        printWindow.document.write('</head><body>');
        printWindow.document.write('<h2>Billers Report (Front-End Mock)</h2>');
        printWindow.document.write(tableHtml);
        printWindow.document.write('</body></html>');
        printWindow.document.close();
        printWindow.focus();
        printWindow.print();
    }

    function exportToXLS() {
        if (!currentReportData.length) {
            Swal.fire({ icon: 'warning', title: 'No Data', text: 'No data available to export.' });
            return;
        }

        const headers = [
            'Partner Name', 'Summary Vol', 'Summary Principal', 'Summary Charges',
            'Adjustment Vol', 'Adjustment Principal', 'Adjustment Charges',
            'Net Vol', 'Net Principal', 'Net Charges'
        ];

        const rows = currentReportData.map(function(row) {
            return [
                row.partner_name || '',
                row.summary_vol || 0,
                Number(row.summary_principal || 0).toFixed(2),
                Number(row.summary_charges || 0).toFixed(2),
                row.adjustment_vol || 0,
                Number(row.adjustment_principal || 0).toFixed(2),
                Number(row.adjustment_charges || 0).toFixed(2),
                row.net_vol || 0,
                Number(row.net_principal || 0).toFixed(2),
                Number(row.net_charges || 0).toFixed(2)
            ].join(',');
        });

        const csvContent = [headers.join(','), ...rows].join('\n');
        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = 'billers-report-mock.csv';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    }

    // Helper to determine the effective dates to send for export
    function getEffectiveExportParams() {
        const partner = $('#partnerlistDropdown').val();
        const filterType = $('select[name="filterType"]').val();

        // Default to current input values
        let startDate = $('input[name="startDate"]').val();
        let endDate = $('input[name="endDate"]').val();

        // Find any active filter button (day/month/year) that is not the "All" button
        const activeBtn = $('.day-shortcut-container').find('.day-button-active').not('.day-button-all').first();

        if (activeBtn && activeBtn.length) {
            const container = activeBtn.closest('.day-shortcut-container');
            const idx = $('.day-shortcut-container').index(container);
            const dataDate = activeBtn.data('date');

            if (idx === 0) {
                // Day buttons: data-date is YYYY-MM-DD
                if (dataDate && dataDate.toString().includes('-')) {
                    startDate = dataDate;
                    endDate = dataDate;
                }
            } else if (idx === 1) {
                // Month buttons: data-date is YYYY-MM
                if (dataDate) {
                    startDate = dataDate;
                    endDate = dataDate;
                }
            } else if (idx === 2) {
                // Year buttons: data-date is YYYY
                if (dataDate) {
                    startDate = dataDate;
                    endDate = dataDate;
                }
            }
        }

        return { partner: partner, filterType: filterType, startDate: startDate, endDate: endDate };
    }

    // Update the existing "All" button click handlers
    $(document).on('click', '.day-button-all', function() {
        const container = $(this).closest('.day-shortcut-container');
        
        // Remove active class from all buttons in this container
        container.find('.day-button').removeClass('day-button-active');
        // Add active class to "All" button
        $(this).addClass('day-button-active');
        
        // Generate report with original date range
        const originalStartDate = $('input[name="startDate"]').val();
        const originalEndDate = $('input[name="endDate"]').val();
        
        if (originalStartDate && originalEndDate) {
            generateFilteredReport(originalStartDate, originalEndDate);
        }
    });

    // Add new function to reset filter containers to default state
    function resetFilterContainers() {
        // Reset all filter containers to default state with only "All" button
        $('.day-shortcut-container').each(function() {
            const wrapper = $(this).find('.day-buttons-wrapper');
            
            // Remove all buttons except the "All" button
            wrapper.find('.day-button:not(.day-button-all)').remove();
            
            // Remove any month separators
            wrapper.find('.month-separator').remove();
            wrapper.find('div[style*="height: 5px"]').remove(); // Remove gap divs
            
            // Reset "All" button state
            const allButton = wrapper.find('.day-button-all');
            allButton.removeClass('day-button-active').addClass('day-button-active');
        });
    }

    // Add new function to clear the report table
    function clearReportTable() {
        const tbody = $('#transactionReportTable tbody');
        tbody.empty();
        
        // Add empty row
        tbody.append(`
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
            </tr>
        `);
        
        // Reset all totals to 0
        $('#totalsummaryvolume').text('0');
        $('#totalsummaryprincipal').text('0.00');
        $('#totalsummarycharge').text('0.00');
        $('#totaladjustmentvolume').text('0');
        $('#totaladjustmentprincipal').text('0.00');
        $('#totaladjustmentcharge').text('0.00');
        $('#totalnetvolume').text('0');
        $('#totalnetprincipal').text('0.00');
        $('#totalnetcharge').text('0.00');
    }

    // Add new function to handle when date inputs change
    $('input[name="startDate"], input[name="endDate"]').on('change', function() {
        // Hide filter containers when date inputs change
        $('.day-shortcut-container').hide();
        resetFilterContainers();
        
        // Hide export button when dates change
        $('#exportButton').hide();
        
        // Clear the report table
        clearReportTable();
        
        toggleGenerateButton();
    });

    // Add the missing generateFilteredReport function after the existing functions

    function generateFilteredReport(startDate, endDate) {
        const billerType = $('select[name="billerlist"]').val();
        const filterType = $('select[name="filterType"]').val();

        if (!billerType || !filterType) {
            return;
        }
        
        // Show loading
        $('#loading-overlay').show();

        fetchReportData(billerType, filterType, startDate, endDate)
            .then(function(result) {
                if (!result || result.status !== 'success') {
                    throw new Error(result && result.message ? result.message : 'Failed to load filtered report data.');
                }
                $('#loading-overlay').hide();
                currentReportData = Array.isArray(result.data) ? result.data : [];
                requestAnimationFrame(function() {
                    populateReportTable(currentReportData);
                });
            })
            .catch(function(error) {
                $('#loading-overlay').hide();
                console.error('Filtered report error:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: error && error.message ? error.message : 'Failed to generate filtered report.'
                });
            });
    }
}); // Final closing brace for $(document).ready
</script>
</html>