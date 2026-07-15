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
    }
}

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

if(isset($_POST['action']) && $_POST['action'] === 'generate_report'){
    $partner = $_POST['partner'] ?? '';
    $filterType = $_POST['filterType'] ?? '';
    $startDate = $_POST['startDate'] ?? '';
    $endDate = $_POST['endDate'] ?? '';

    if ($filterType === 'daily' && empty($endDate)) {
        $endDate = $startDate;
    }

    // If client sent 'daily' but provided a range (start != end), treat as a range
    if ($filterType === 'daily' && !empty($startDate) && !empty($endDate) && $startDate !== $endDate) {
        $filterType = 'weekly';
    }

    $queryStartDate = $startDate;
    $queryEndDate = $endDate;
    $dateParams = [];
    $dateTypes = '';
    $dateCondition = '(1=1)';
    $adjustmentDateCondition = '(1=1)';

    if (!empty($filterType)) {
        if ($filterType === 'daily') {
            $dateCondition = "(DATE(bt.datetime) = ? OR DATE(bt.cancellation_date) = ?)";
            $adjustmentDateCondition = 'DATE(msabt.posting_date) = ?';
            $dateParams = [$queryStartDate, $queryStartDate, $queryStartDate, $queryStartDate, $queryStartDate];
            $dateTypes = 'sssss';
        } elseif ($filterType === 'weekly') {
            $dateCondition = "(DATE(bt.datetime) BETWEEN ? AND ? OR DATE(bt.cancellation_date) BETWEEN ? AND ?)";
            $adjustmentDateCondition = 'DATE(msabt.posting_date) BETWEEN ? AND ?';
            $dateParams = [
                $queryStartDate, $queryEndDate, $queryStartDate, $queryEndDate,
                $queryStartDate, $queryEndDate, $queryStartDate, $queryEndDate,
                $queryStartDate, $queryEndDate
            ];
            $dateTypes = 'ssssssssss';
        } elseif ($filterType === 'monthly') {
            $queryStartDate = $startDate . '-01';
            $queryEndDate = date('Y-m-t', strtotime($endDate . '-01'));
            $dateCondition = "(DATE(bt.datetime) BETWEEN ? AND ? OR DATE(bt.cancellation_date) BETWEEN ? AND ?)";
            $adjustmentDateCondition = 'DATE(msabt.posting_date) BETWEEN ? AND ?';
            $dateParams = [
                $queryStartDate, $queryEndDate, $queryStartDate, $queryEndDate,
                $queryStartDate, $queryEndDate, $queryStartDate, $queryEndDate,
                $queryStartDate, $queryEndDate
            ];
            $dateTypes = 'ssssssssss';
        }
    }

    $whereConditions = [];
    $params = $dateParams;
    $types = $dateTypes;

    if (!empty($partner) && $partner !== 'All') {
        $whereConditions[] = 'bt.partner_name = ?';
        $params[] = $partner;
        $types .= 's';
    }

    $mainWhereClause = '';
    if (!empty($whereConditions)) {
        $mainWhereClause = 'AND ' . implode(' AND ', $whereConditions);
    }

    $dataQuery = "WITH partner_name_list AS (
        SELECT
            COALESCE(mpm.partner_id, mpm.partner_id_kpx, CONCAT('temp_', mpm.partner_name)) AS partner_key,
            mpm.partner_id,
            mpm.partner_id_kpx,
            mpm.partner_name,
            mpm.partner_accName,
            mpm.bank_accNumber,
            mpm.bank AS bank_name,
            mpm.settled_online_check,
            mpm.charge_to,
            mpm.charge_sched
        FROM masterdata.partner_masterfile mpm
        WHERE mpm.status = 'ACTIVE'
    ),
    summary_vol AS (
        SELECT
            CASE
                WHEN bt.partner_id IS NOT NULL THEN bt.partner_id
                WHEN bt.partner_id_kpx IS NOT NULL THEN bt.partner_id_kpx
                ELSE CONCAT(
                    'temp_',
                    CASE
                        WHEN bt.sub_billers_name IN ('MYLORA CORPORATION', 'JUNANS MARKETING') THEN bt.sub_billers_name
                        ELSE bt.partner_name
                    END
                )
            END COLLATE utf8mb4_general_ci AS partner_key,
            CASE
                WHEN bt.sub_billers_name IN ('MYLORA CORPORATION', 'JUNANS MARKETING') THEN bt.sub_billers_name
                ELSE bt.partner_name
            END AS partner_name,
            MAX(CASE WHEN LOWER(TRIM(COALESCE(bt.settle_unsettle, ''))) = 'settle' THEN 1 ELSE 0 END) AS has_settlement,
            COUNT(*) AS vol1,
            SUM(bt.amount_paid) AS principal1,
            SUM(bt.charge_to_partner + bt.charge_to_customer) AS charge1,
            -- settled-only aggregates
            SUM(CASE WHEN LOWER(TRIM(COALESCE(bt.settle_unsettle, ''))) = 'settle' THEN 1 ELSE 0 END) AS settled_vol1,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(bt.settle_unsettle, ''))) = 'settle' THEN bt.amount_paid ELSE 0 END) AS settled_principal1,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(bt.settle_unsettle, ''))) = 'settle' THEN (bt.charge_to_partner + bt.charge_to_customer) ELSE 0 END) AS settled_charge1
        FROM mldb.billspayment_transaction bt
        WHERE
            $dateCondition
            AND bt.status IS NULL
            AND NOT bt.branch_id IN ('1','2','4937','4938','4962','4987','4993','4944')
        GROUP BY
            CASE
                WHEN bt.partner_id IS NOT NULL THEN bt.partner_id
                WHEN bt.partner_id_kpx IS NOT NULL THEN bt.partner_id_kpx
                ELSE CONCAT(
                    'temp_',
                    CASE
                        WHEN bt.sub_billers_name IN ('MYLORA CORPORATION', 'JUNANS MARKETING') THEN bt.sub_billers_name
                        ELSE bt.partner_name
                    END
                )
            END,
            CASE
                WHEN bt.sub_billers_name IN ('MYLORA CORPORATION', 'JUNANS MARKETING') THEN bt.sub_billers_name
                ELSE bt.partner_name
            END,
            bt.partner_id, bt.partner_id_kpx, bt.sub_billers_name, bt.partner_name
    ),
    adjustment_vol AS (
        SELECT
            CASE
                WHEN bt.partner_id IS NOT NULL THEN bt.partner_id
                WHEN bt.partner_id_kpx IS NOT NULL THEN bt.partner_id_kpx
                ELSE CONCAT(
                    'temp_',
                    CASE
                        WHEN bt.sub_billers_name IN ('MYLORA CORPORATION', 'JUNANS MARKETING') THEN bt.sub_billers_name
                        ELSE bt.partner_name
                    END
                )
            END COLLATE utf8mb4_general_ci AS partner_key,
            CASE
                WHEN bt.sub_billers_name IN ('MYLORA CORPORATION', 'JUNANS MARKETING') THEN bt.sub_billers_name
                ELSE bt.partner_name
            END AS partner_name,
            MAX(CASE WHEN LOWER(TRIM(COALESCE(bt.settle_unsettle, ''))) = 'settle' THEN 1 ELSE 0 END) AS has_settlement,
            COUNT(*) AS vol2,
            SUM(bt.amount_paid) AS principal2,
            SUM(bt.charge_to_partner + bt.charge_to_customer) AS charge2,
            -- settled-only aggregates for adjustments
            SUM(CASE WHEN LOWER(TRIM(COALESCE(bt.settle_unsettle, ''))) = 'settle' THEN 1 ELSE 0 END) AS settled_vol2,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(bt.settle_unsettle, ''))) = 'settle' THEN bt.amount_paid ELSE 0 END) AS settled_principal2,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(bt.settle_unsettle, ''))) = 'settle' THEN (bt.charge_to_partner + bt.charge_to_customer) ELSE 0 END) AS settled_charge2
        FROM mldb.billspayment_transaction bt
        WHERE
            $dateCondition
            AND bt.status = '*'
            AND NOT bt.branch_id IN ('1','2','4937','4938','4962','4987','4993','4944')
        GROUP BY
            CASE
                WHEN bt.partner_id IS NOT NULL THEN bt.partner_id
                WHEN bt.partner_id_kpx IS NOT NULL THEN bt.partner_id_kpx
                ELSE CONCAT(
                    'temp_',
                    CASE
                        WHEN bt.sub_billers_name IN ('MYLORA CORPORATION', 'JUNANS MARKETING') THEN bt.sub_billers_name
                        ELSE bt.partner_name
                    END
                )
            END,
            CASE
                WHEN bt.sub_billers_name IN ('MYLORA CORPORATION', 'JUNANS MARKETING') THEN bt.sub_billers_name
                ELSE bt.partner_name
            END,
            bt.partner_id, bt.partner_id_kpx, bt.sub_billers_name, bt.partner_name
    ),
    principal_adjustment_data AS (
        SELECT
            msabt.partner_name AS owner_name,
            SUM(
                CASE
                    WHEN LOWER(TRIM(msabt.reason_note)) = 'late-posting' THEN COALESCE(msabt.prev_amount_paid, 0)
                    WHEN LOWER(TRIM(msabt.reason_note)) = 'wrong-amount' THEN COALESCE(msabt.edited_amount_paid, 0)
                    ELSE 0
                END
            ) AS principal_adjustment_raw,
            SUM(
                CASE
                    WHEN LOWER(TRIM(msabt.reason_note)) = 'late-posting'
                        THEN COALESCE(msabt.prev_charge_to_customer, 0) + COALESCE(msabt.prev_charge_to_partner, 0)
                    WHEN LOWER(TRIM(msabt.reason_note)) = 'wrong-amount'
                        THEN COALESCE(msabt.edited_charge_to_customer, 0) + COALESCE(msabt.edited_charge_to_partner, 0)
                    ELSE 0
                END
            ) AS charges,
            SUM(
                CASE
                    WHEN LOWER(TRIM(msabt.reason_note)) = 'late-posting' THEN COALESCE(msabt.prev_amount_paid, 0) - (COALESCE(msabt.prev_charge_to_customer, 0) + COALESCE(msabt.prev_charge_to_partner, 0))
                    WHEN LOWER(TRIM(msabt.reason_note)) = 'wrong-amount' THEN COALESCE(msabt.edited_amount_paid, 0) - (COALESCE(msabt.edited_charge_to_customer, 0) + COALESCE(msabt.edited_charge_to_partner, 0))
                    ELSE 0
                END
            ) AS principal_adjustment
        FROM mldb.settle_adjustment_branch_transaction msabt
        WHERE $adjustmentDateCondition
        GROUP BY msabt.partner_name
    ),
    all_partners AS (
        SELECT partner_key, partner_name FROM partner_name_list
        UNION
        SELECT partner_key, partner_name FROM summary_vol
        UNION
        SELECT partner_key, partner_name FROM adjustment_vol
    ),
    base_totals AS (
        SELECT
            ap.partner_name,
            MAX(pml.partner_accName) AS partner_accName,
            MAX(pml.bank_accNumber) AS bank_accNumber,
            MAX(pml.bank_name) AS bank_name,
            MAX(pml.settled_online_check) AS settled_online_check,
            MAX(pml.charge_to) AS charge_to,
            MAX(pml.charge_sched) AS charge_sched,
            MAX(COALESCE(sv.has_settlement, 0)) AS has_settlement,
            SUM(COALESCE(sv.vol1, 0)) AS summary_vol,
            SUM(COALESCE(sv.principal1, 0)) AS summary_principal,
            SUM(COALESCE(sv.charge1, 0)) AS summary_charges,
            SUM(COALESCE(sv.settled_vol1, 0)) AS summary_settled_vol,
            SUM(COALESCE(sv.settled_principal1, 0)) AS summary_settled_principal,
            SUM(COALESCE(sv.settled_charge1, 0)) AS summary_settled_charges,
            SUM(COALESCE(av.vol2, 0)) AS adjustment_vol,
            SUM(COALESCE(av.principal2, 0)) AS adjustment_principal,
            SUM(COALESCE(av.charge2, 0)) AS adjustment_charges,
            SUM(COALESCE(av.settled_vol2, 0)) AS adjustment_settled_vol,
            SUM(COALESCE(av.settled_principal2, 0)) AS adjustment_settled_principal,
            SUM(COALESCE(av.settled_charge2, 0)) AS adjustment_settled_charges,
            (SUM(COALESCE(pad.principal_adjustment, 0)) + SUM(CASE WHEN COALESCE(av.has_settlement, 0) = 1 THEN COALESCE(av.settled_principal2, 0) - COALESCE(av.settled_charge2, 0) ELSE 0.00 END)) AS principal_adjustment,
            SUM(COALESCE(pad.charges, 0)) AS settle_adjustment_charges
        FROM all_partners ap
        LEFT JOIN partner_name_list pml ON pml.partner_name = ap.partner_name
        LEFT JOIN summary_vol sv ON sv.partner_name = ap.partner_name
        LEFT JOIN adjustment_vol av ON av.partner_name = ap.partner_name
        LEFT JOIN principal_adjustment_data pad ON pad.owner_name = ap.partner_name
        GROUP BY ap.partner_name
    )
    SELECT
        bt.partner_name,
        bt.partner_accName,
        bt.bank_accNumber,
        bt.bank_name,
        bt.settled_online_check,
        bt.charge_to,
        bt.charge_sched,
        bt.has_settlement,
        (COALESCE(bt.summary_vol, 0) - COALESCE(bt.adjustment_vol, 0)) AS net_volume_count,
        (COALESCE(bt.summary_principal, 0) - COALESCE(ABS(bt.adjustment_principal), 0)) AS net_principal,
        (COALESCE(bt.summary_charges, 0) - COALESCE(ABS(bt.adjustment_charges), 0)) AS net_charges,
        CASE
            WHEN bt.has_settlement = 1 THEN bt.summary_settled_vol
            ELSE 0
        END AS gross_volume_count,
        CASE
            WHEN bt.has_settlement = 1 THEN bt.summary_settled_principal
            ELSE 0.00
        END AS gross_principal_amount_paid,
        CASE
            WHEN bt.has_settlement = 1 THEN bt.summary_settled_charges
            ELSE 0.00
        END AS gross_charges,
        CASE
            WHEN bt.has_settlement = 1 THEN ABS(bt.principal_adjustment)
            ELSE 0.00
        END AS principal_adjustment,
        CASE
            WHEN bt.has_settlement = 1 THEN
                CASE
                    WHEN bt.charge_to = 'CUSTOMER' THEN
                        (bt.summary_principal - bt.summary_charges) - ABS(bt.principal_adjustment)
                    WHEN bt.charge_to = 'PARTNER'
                        AND bt.charge_sched IN ('DAILY','WEEKLY','SEMI-MONTHLY','MONTHLY') THEN
                        ((bt.summary_settled_principal - bt.adjustment_settled_principal) - (bt.summary_settled_charges - bt.adjustment_settled_charges))
                        - (bt.principal_adjustment - bt.settle_adjustment_charges)
                    ELSE
                        (bt.summary_settled_principal - bt.adjustment_settled_principal)
                END
            ELSE 0.00
        END AS amount_for_settlement,
        ((CASE WHEN bt.has_settlement = 1 THEN (bt.summary_settled_vol - bt.adjustment_settled_vol) ELSE 0 END) - (bt.summary_vol - bt.adjustment_vol)) AS variance_vol,
        (
            (CASE
                WHEN bt.has_settlement = 1 THEN
                    CASE
                        WHEN bt.charge_to = 'CUSTOMER' THEN
                            (bt.summary_settled_principal - bt.summary_settled_charges) - ABS(bt.principal_adjustment)
                        WHEN bt.charge_to = 'PARTNER'
                            AND bt.charge_sched IN ('DAILY','WEEKLY','SEMI-MONTHLY','MONTHLY') THEN
                            ((bt.summary_settled_principal - bt.adjustment_settled_principal) - (bt.summary_settled_charges - bt.adjustment_settled_charges))
                            - (bt.principal_adjustment - bt.settle_adjustment_charges)
                        ELSE
                            (bt.summary_settled_principal - bt.adjustment_settled_principal)
                    END
                ELSE 0.00
            END)
            - (
                (COALESCE(bt.summary_principal, 0) - COALESCE(ABS(bt.adjustment_principal), 0))
                - (COALESCE(bt.summary_charges, 0) - COALESCE(ABS(bt.adjustment_charges), 0))
            )
        ) AS variance_principal,
        ((CASE WHEN bt.has_settlement = 1 THEN (bt.summary_settled_charges - bt.adjustment_settled_charges) ELSE 0.00 END)
         - (bt.summary_charges - CASE WHEN bt.has_settlement = 1 THEN bt.adjustment_charges ELSE 0.00 END)) AS variance_charge
    FROM base_totals bt
    WHERE bt.partner_name IS NOT NULL
    $mainWhereClause
    ORDER BY bt.partner_name";

    try {
        $stmt = $conn->prepare($dataQuery);

        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $conn->error);
        }

        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

        echo json_encode([
            'status' => 'success',
            'data' => $data,
            'debug' => [
                'filterType' => $filterType,
                'startDate' => $startDate,
                'endDate' => $endDate,
                'partner' => $partner,
                'params' => $params,
                'types' => $types,
                'paramCount' => count($params),
                'placeholderCount' => substr_count($dataQuery, '?')
            ]
        ]);

        $stmt->close();
    } catch (Exception $e) {
        error_log('Recon report database error: ' . $e->getMessage());
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
    <title>Recon Report | <?php if($_SESSION['user_type'] === 'admin' || $_SESSION['user_type'] === 'user') echo ucfirst($_SESSION['user_type']); else echo "Guest";?></title>
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

        #tableContainer {
            max-height: 70vh;
            overflow-y: auto;
        }

        #transactionReportTable {
            border-collapse: separate;
            border-spacing: 0;
        }

        #transactionReportTable thead th {
            position: sticky;
            top: 0;
            z-index: 2;
            background: #f8f9fa;
            box-shadow: inset 0 -1px 0 #dee2e6;
            vertical-align: middle;
        }

        #transactionReportTable thead tr:nth-child(1) th {
            top: 0;
        }

        #transactionReportTable thead tr:nth-child(2) th {
            top: 39px;
        }

        #transactionReportTable thead tr:nth-child(3) th {
            top: 78px;
        }

        #transactionReportTable thead th[rowspan] {
            z-index: 3;
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
                    <h2>Recon Report - (UNDER CONSTRUCTION)</h2>
                                
                    <!-- <p class="bp-section-sub">Sample Description</p> -->
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
                                        <option value="weekly">Date Range</option>
                                        <option value="monthly">Monthly</option>
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
                                        <button class="btn btn-danger" id="reconButton" type="button" style="display:none;">Recon</button>
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
                        </div>
                        
                        <div class="card-body">
                            <div class="table-responsive" id="tableContainer" style="overflow-y: auto;">
                                <table id="transactionReportTable" class="table table-bordered table-hover table-striped">
                                    <thead class="table-light">
                                        <tr>
                                            <th rowspan="3" class='text-truncate text-center align-middle'>No.</th>
                                            <th rowspan="3" class='text-truncate text-center align-middle'>Partner Name</th>
                                            <th colspan="3" class='text-truncate text-center align-middle'>Volume Report</th>
                                            <th colspan="5" class='text-truncate text-center align-middle'>Settlement Per Bank Report</th>
                                            <th colspan="3" class='text-truncate text-center align-middle'>Variance (Volume VS Settlement)</th>
                                        </tr>
                                        <tr>
                                            <th colspan="3" class='text-truncate text-center align-middle'>Net Total Transaction</th>
                                            <th colspan="5" class='text-truncate text-center align-middle'>Gross Total Transaction</th>
                                            <th colspan="3" class='text-truncate text-center align-middle'>Variance Values</th>
                                        </tr>
                                        <tr>
                                            <th class='text-center'>Vol.</th>
                                            <th class='text-center'>Principal</th>
                                            <th class='text-center'>Charge</th>
                                            <th class='text-center'>Vol.</th>
                                            <th class='text-center'>Principal</th>
                                            <th class='text-center'>Charge</th>
                                            <th class='text-center'>Principal Adjustment</th>
                                            <th class='text-center'>Amount for Settlement</th>
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
                                        </tr>
                                    </tbody>
                                    <tfoot class="sticky-bottom table-dark">
                                        <tr>
                                            <th colspan="2" class="text-end">Total : </th>
                                            <!-- Column header for Net -->
                                            <th class="text-center" id="totalnetvolume">0</th>
                                            <th class="text-end" id="totalnetprincipal">0.00</th>
                                            <th class="text-end" id="totalnetcharge">0.00</th>
                                            <!-- Column header for Gross -->
                                            <th class="text-center" id="totalgrossvolume">0</th>
                                            <th class="text-end" id="totalgrossprincipal">0.00</th>
                                            <th class="text-end" id="totalgrosscharge">0.00</th>
                                            <th class="text-end" id="totalprincipaladjustment">0.00</th>
                                            <th class="text-end" id="totalsettlementamount">0.00</th>
                                            <!-- Column header for Variance -->
                                            <th class="text-center" id="totalvariancevolume">0</th>
                                            <th class="text-end" id="totalvarianceprincipal">0.00</th>
                                            <th class="text-end" id="totalvariancecharge">0.00</th>
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
$(document).ready(function(){
    // initialize select2 if available
    if ($.fn.select2) {
        $('#partnerlistDropdown').select2({ placeholder: 'Search or select a Partner...', allowClear: true });
    }

    // cache selectors
    const $filterType = $('select[name="filterType"]');
    const $startCol = $('input[name="startDate"]').closest('.col-md-2');
    const $endCol = $('input[name="endDate"]').closest('.col-md-2');
    const $startInput = $('input[name="startDate"]');
    const $endInput = $('input[name="endDate"]');
    const $partner = $('#partnerlistDropdown');
    const $generate = $('#generateReport');
    const $dayContainer = $('#dayFilterContainer');
    const $monthContainer = $('#monthFilterContainer');

    function requestReport(rangeStartDate, rangeEndDate, requestFilterType) {
        const filterType = requestFilterType || $filterType.val();

        $('#loading-overlay').css('display', 'flex');

        $.ajax({
            url: window.location.pathname,
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'generate_report',
                partner: $partner.val(),
                filterType: filterType,
                startDate: rangeStartDate,
                endDate: rangeEndDate
            }
        }).done(function(resp) {
            if (resp && resp.status === 'success') {
                populateReportTable(resp.data || []);
            } else {
                clearReportTable();
                Swal.fire('Error', (resp && resp.message) ? resp.message : 'Failed to generate report.', 'error');
            }
        }).fail(function(xhr) {
            clearReportTable();
            Swal.fire('Error', 'Failed to generate report.', 'error');
            console.error(xhr.responseText || 'generate_report request failed');
        }).always(function() {
            $('#loading-overlay').hide();
        });
    }

    function resetDateInputs(){
        $startInput.val('');
        $endInput.val('');
    }

    function updateVisibility(){
        const v = $filterType.val();
        // hide by default
        $startCol.hide(); $endCol.hide(); $dayContainer.hide(); $monthContainer.hide();

        if (v === 'daily') {
            $startCol.show(); // only start date (single day)
            // day/month shortcut containers remain hidden until Generate is clicked
        } else if (v === 'weekly') {
            $startCol.show(); $endCol.show();
        } else if (v === 'monthly') {
            // show start and end for month selection; you can customize to use month picker
            $startCol.show(); $endCol.show();
            // month shortcuts remain hidden until Generate is clicked
        }
        toggleGenerateButton();
    }

    function toggleGenerateButton(){
        const partnerVal = $partner.val();
        const filter = $filterType.val();

        if (!filter) { $generate.prop('disabled', true); return; }
        if (!partnerVal) { $generate.prop('disabled', true); return; }

        if (filter === 'daily'){
            $generate.prop('disabled', !$startInput.val());
        } else if (filter === 'weekly' || filter === 'monthly'){
            $generate.prop('disabled', !($startInput.val() && $endInput.val()));
        } else {
            $generate.prop('disabled', false);
        }
    }

    function toNumber(value) {
        const numeric = parseFloat(value);
        return Number.isNaN(numeric) ? 0 : numeric;
    }

    function formatInt(value) {
        return Math.round(toNumber(value)).toLocaleString('en-US');
    }

    function formatMoney(value) {
        return toNumber(value).toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function clearReportTable() {
        const emptyRow = `
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
            </tr>`;

        $('#transactionReportTable tbody').html(emptyRow);
        $('#totalnetvolume').text('0');
        $('#totalnetprincipal').text('0.00');
        $('#totalnetcharge').text('0.00');
        $('#totalgrossvolume').text('0');
        $('#totalgrossprincipal').text('0.00');
        $('#totalgrosscharge').text('0.00');
        $('#totalprincipaladjustment').text('0.00');
        $('#totalsettlementamount').text('0.00');
        $('#totalvariancevolume').text('0');
        $('#totalvarianceprincipal').text('0.00');
        $('#totalvariancecharge').text('0.00');
    }

    function setActiveFilterButton($button) {
        const $container = $button.closest('.day-shortcut-container');
        $container.find('.day-button').removeClass('day-button-active');
        $button.addClass('day-button-active');
    }

    function populateReportTable(data) {
        const tbody = $('#transactionReportTable tbody');
        tbody.empty();

        const totals = {
            netVolume: 0,
            netPrincipal: 0,
            netCharge: 0,
            grossVolume: 0,
            grossPrincipal: 0,
            grossCharge: 0,
            principalAdjustment: 0,
            settlementAmount: 0,
            varianceVolume: 0,
            variancePrincipal: 0,
            varianceCharge: 0
        };

        if (!Array.isArray(data) || !data.length) {
            tbody.append('<tr><td colspan="13" class="text-center">No data found for the selected criteria</td></tr>');
            return;
        }

        data.forEach(function(row, index) {
            totals.netVolume += toNumber(row.net_volume_count);
            totals.netPrincipal += toNumber(row.net_principal);
            totals.netCharge += toNumber(row.net_charges);
            totals.grossVolume += toNumber(row.gross_volume_count);
            totals.grossPrincipal += toNumber(row.gross_principal_amount_paid);
            totals.grossCharge += toNumber(row.gross_charges);
            totals.principalAdjustment += toNumber(row.principal_adjustment);
            totals.settlementAmount += toNumber(row.amount_for_settlement);
            totals.varianceVolume += toNumber(row.variance_vol);
            totals.variancePrincipal += toNumber(row.variance_principal);
            totals.varianceCharge += toNumber(row.variance_charge);

            tbody.append(`
                <tr>
                    <td class="text-center">${index + 1}</td>
                    <td>${row.partner_name || ''}</td>
                    <td class="text-center">${formatInt(row.net_volume_count)}</td>
                    <td class="text-end">${formatMoney(row.net_principal)}</td>
                    <td class="text-end">${formatMoney(row.net_charges)}</td>
                    <td class="text-center">${formatInt(row.gross_volume_count)}</td>
                    <td class="text-end">${formatMoney(row.gross_principal_amount_paid)}</td>
                    <td class="text-end">${formatMoney(row.gross_charges)}</td>
                    <td class="text-end">${formatMoney(row.principal_adjustment)}</td>
                    <td class="text-end">${formatMoney(row.amount_for_settlement)}</td>
                    <td class="text-center">${formatInt(row.variance_vol)}</td>
                    <td class="text-end">${formatMoney(row.variance_principal)}</td>
                    <td class="text-end">${formatMoney(row.variance_charge)}</td>
                </tr>
            `);
        });

        $('#totalnetvolume').text(formatInt(totals.netVolume));
        $('#totalnetprincipal').text(formatMoney(totals.netPrincipal));
        $('#totalnetcharge').text(formatMoney(totals.netCharge));
        $('#totalgrossvolume').text(formatInt(totals.grossVolume));
        $('#totalgrossprincipal').text(formatMoney(totals.grossPrincipal));
        $('#totalgrosscharge').text(formatMoney(totals.grossCharge));
        $('#totalprincipaladjustment').text(formatMoney(totals.principalAdjustment));
        $('#totalsettlementamount').text(formatMoney(totals.settlementAmount));
        $('#totalvariancevolume').text(formatInt(totals.varianceVolume));
        $('#totalvarianceprincipal').text(formatMoney(totals.variancePrincipal));
        $('#totalvariancecharge').text(formatMoney(totals.varianceCharge));
    }

    // events
    $filterType.on('change', function(){
        resetDateInputs();
        // switch input types when monthly selected
        if ($(this).val() === 'monthly'){
            $startInput.attr('type','month');
            $endInput.attr('type','month');
        } else {
            $startInput.attr('type','date');
            $endInput.attr('type','date');
        }
        updateVisibility();
    });

    $startInput.on('change', toggleGenerateButton);
    $endInput.on('change', toggleGenerateButton);
    $partner.on('change', toggleGenerateButton);

    // When Generate is clicked, reveal the appropriate shortcut container (day/month) if applicable
    $generate.on('click', function(){
        const v = $filterType.val();
        let startDate = $startInput.val();
        let endDate = $endInput.val();

        if (!v || !$partner.val()) {
            return;
        }

        if (v === 'daily') {
            endDate = startDate;
        }

        $('#dayFilterContainer .day-button-all, #monthFilterContainer .day-button-all').addClass('day-button-active');
        $('#dayFilterContainer .day-button:not(.day-button-all), #monthFilterContainer .day-button:not(.day-button-all)').removeClass('day-button-active');

        // hide both first
        $dayContainer.hide(); $monthContainer.hide();
        if (v === 'weekly') {
            $dayContainer.show();
            // generate day buttons for single day (just that day)
            generateDayButtons(startDate, endDate || startDate);
        } else if (v === 'monthly') {
            $monthContainer.show();
            generateMonthButtons(startDate, endDate);
        }

        requestReport(startDate, endDate, v);
    });

    $(document).on('click', '#dayFilterContainer .day-button.day-number-button', function(){
        const $button = $(this);
        const selectedDate = $button.attr('data-date');

        if (!selectedDate) {
            return;
        }

        setActiveFilterButton($button);
        requestReport(selectedDate, selectedDate, 'daily');
    });

    $(document).on('click', '#monthFilterContainer .day-button.month-button', function(){
        const $button = $(this);
        const selectedMonth = $button.attr('data-date');

        if (!selectedMonth) {
            return;
        }

        setActiveFilterButton($button);
        requestReport(selectedMonth, selectedMonth, 'monthly');
    });

    $(document).on('click', '#dayFilterContainer .day-button-all', function(){
        const startDate = $startInput.val();
        const endDate = $endInput.val() || $startInput.val();

        if (!startDate) {
            return;
        }

        setActiveFilterButton($(this));
        // if start and end differ, ask server to treat this as a range (weekly)
        const requestedType = (startDate !== endDate) ? 'weekly' : 'daily';
        requestReport(startDate, endDate, requestedType);
    });

    $(document).on('click', '#monthFilterContainer .day-button-all', function(){
        const startDate = $startInput.val();
        const endDate = $endInput.val() || $startInput.val();

        if (!startDate) {
            return;
        }

        setActiveFilterButton($(this));
        requestReport(startDate, endDate, 'monthly');
    });

    // initial state
    clearReportTable();
    $startCol.hide(); $endCol.hide(); $generate.prop('disabled', true);
});

// Functions to generate day/month buttons
function generateDayButtons(startDate, endDate){
    if (!startDate) return;
    const start = new Date(startDate);
    const end = new Date(endDate || startDate);
    const wrapper = $('#dayFilterContainer').find('.day-buttons-wrapper');
    wrapper.find('.day-button:not(.day-button-all)').remove();
    for (let d = new Date(start); d <= end; d.setDate(d.getDate()+1)){
        const yyyy = d.getFullYear();
        const mm = String(d.getMonth()+1).padStart(2,'0');
        const dd = String(d.getDate()).padStart(2,'0');
        const label = dd;
        const btn = $('<button>').addClass('day-button day-number-button').text(label).attr('data-date', `${yyyy}-${mm}-${dd}`);
        wrapper.append(btn);
    }
        // decide active state: if more than one day in range, activate "All" pill; otherwise activate the single day
        const $dayButtons = wrapper.find('.day-button:not(.day-button-all)');
        if ($dayButtons.length > 1) {
            wrapper.find('.day-button-all').addClass('day-button-active');
        } else {
            $dayButtons.first().addClass('day-button-active');
        }
    // show container
    $('#dayFilterContainer').show();
}

function generateMonthButtons(startMonth, endMonth){
    if (!startMonth) return;
    // startMonth/endMonth expected as YYYY-MM
    const s = new Date(startMonth + '-01');
    const e = new Date((endMonth || startMonth) + '-01');
    const wrapper = $('#monthFilterContainer').find('.day-buttons-wrapper');
    wrapper.find('.day-button:not(.day-button-all)').remove();
    for (let d = new Date(s); d <= e; d.setMonth(d.getMonth()+1)){
        const yyyy = d.getFullYear();
        const monthName = d.toLocaleString('default', { month: 'short' });
        const val = `${yyyy}-${String(d.getMonth()+1).padStart(2,'0')}`;
        const btn = $('<button>').addClass('day-button month-button').text(`${monthName} ${yyyy}`).attr('data-date', val);
        wrapper.append(btn);
    }
        // decide active state: if more than one month in range, activate "All" pill; otherwise activate the single month
        const $monthButtons = wrapper.find('.day-button:not(.day-button-all)');
        if ($monthButtons.length > 1) {
            wrapper.find('.day-button-all').addClass('day-button-active');
        } else {
            $monthButtons.first().addClass('day-button-active');
        }
    $('#monthFilterContainer').show();
}
</script>
<script>
$(document).ready(function(){
    function loadPartners(){
        $.ajax({
            url: window.location.pathname,
            method: 'POST',
            data: { action: 'get_partner_list' },
            dataType: 'json'
        }).done(function(resp){
            const $ddl = $('#partnerlistDropdown');
            $ddl.empty();
            $ddl.append($('<option>').val('').text('Select Partner'));
            $ddl.append($('<option>').val('All').text('All'));
            if (resp && resp.data && Array.isArray(resp.data)){
                resp.data.forEach(function(p){
                    const name = (typeof p === 'object') ? (p.partner_name || '') : p;
                    if (name) $ddl.append($('<option>').val(name).text(name));
                });
            }
            if ($.fn.select2) $ddl.trigger('change.select2');
            // ensure generate button state updates after load
            $('#partnerlistDropdown').trigger('change');
        }).fail(function(){
            console.error('Failed to load partners');
        });
    }

    loadPartners();
});
</script>
<script>
// Toggle Generate button style based on enabled state
(function(){
    const $gen = $('#generateReport');
    function updateGenerateStyle(){
        if ($gen.prop('disabled')){
            $gen.removeClass('btn-danger').addClass('btn-secondary');
        } else {
            $gen.removeClass('btn-secondary').addClass('btn-danger');
        }
    }
    // initial
    updateGenerateStyle();
    // observe attribute changes
    const mo = new MutationObserver(updateGenerateStyle);
    const btn = document.getElementById('generateReport');
    if (btn) mo.observe(btn, { attributes: true, attributeFilter: ['disabled', 'class'] });
    // also update on input changes
    $(document).on('change input', 'select[name="filterType"], #partnerlistDropdown, input[name="startDate"], input[name="endDate"]', function(){
        setTimeout(updateGenerateStyle, 10);
    });
})();
</script>
</html>