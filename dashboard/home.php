<?php
// Connect to the database
require_once __DIR__ . '/../config/config.php';

// Start the session
session_start();

if (isset($_SESSION['user_type'])) {
    $current_user_email = '';
    if ($_SESSION['user_type'] === 'admin' && isset($_SESSION['admin_email'])) {
        $current_user_email = $_SESSION['admin_email'];
    } elseif ($_SESSION['user_type'] === 'user' && isset($_SESSION['user_email'])) {
        $current_user_email = $_SESSION['user_email'];
    } else {
        header("Location: ../../../index.php");
        session_abort();
        session_destroy();
        exit();
    }
} else {
    header("Location: ../../../index.php");
    session_abort();
    session_destroy();
    exit();
}

/* ============================================================
   AJAX HANDLERS
   ============================================================ */

// --- 1) Calendar counts for the current month (read-only) ---
if (isset($_POST['action']) && $_POST['action'] === 'get_calendar_counts') {
    ob_clean();
    header('Content-Type: application/json');

    $year  = isset($_POST['year'])  ? (int)$_POST['year']  : (int)date('Y');
    $month = isset($_POST['month']) ? (int)$_POST['month'] : (int)date('n');

    try {
        // report_date is DATE-typed, so we can compare directly without time.
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end   = date('Y-m-t', strtotime($start));

        // Fetch count AND the latest imported_date for each report_date
        $sql = "SELECT report_date AS tx_date, 
                       COUNT(*) AS total,
                       MAX(imported_date) AS latest_imported_date
                FROM billspayment_transaction
                WHERE report_date >= ? AND report_date <= ?
                GROUP BY report_date
                ORDER BY report_date ASC";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        $stmt->bind_param('ss', $start, $end);
        $stmt->execute();
        $res = $stmt->get_result();

        $counts = [];
        while ($row = $res->fetch_assoc()) {
            $counts[$row['tx_date']] = [
                'count'         => (int)$row['total'],
                'imported_date' => $row['latest_imported_date'] ? date('F j, Y', strtotime($row['latest_imported_date'])) : ''
            ];
        }
        $stmt->close();

        echo json_encode([
            'success' => true,
            'year'    => $year,
            'month'   => $month,
            'counts'  => $counts
        ]);
        exit;

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ]);
        exit;
    }
}

// --- 2) Reference number search ---
if (isset($_POST['action']) && $_POST['action'] === 'get_transaction_data') {
    ob_clean();
    header('Content-Type: application/json');

    $search = isset($_POST['search']) ? trim($_POST['search']) : '';

    try {
        if ($search === '') {
            echo json_encode([
                'success' => true,
                'data' => [],
                'totals' => [
                    'total_principal' => 0,
                    'total_charge_partner' => 0,
                    'total_charge_customer' => 0
                ]
            ]);
            exit;
        }

        $whereClause = "WHERE reference_no LIKE ?";
        $searchParam = $search . '%';

        $dataQuery = "SELECT * FROM billspayment_transaction $whereClause ORDER BY id DESC";
        $dataStmt = $conn->prepare($dataQuery);
        if (!$dataStmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        $dataStmt->bind_param('s', $searchParam);
        $dataStmt->execute();
        $dataResult = $dataStmt->get_result();

        $data = [];
        while ($row = $dataResult->fetch_assoc()) {
            $data[] = $row;
        }
        $dataStmt->close();

        $total_principal       = 0;
        $total_charge_partner  = 0;
        $total_charge_customer = 0;
        foreach ($data as $row) {
            $total_principal       += (float)($row['amount_paid'] ?? 0);
            $total_charge_partner  += (float)($row['charge_to_partner'] ?? 0);
            $total_charge_customer += (float)($row['charge_to_customer'] ?? 0);
        }

        echo json_encode([
            'success' => true,
            'data'    => $data,
            'totals'  => [
                'total_principal'       => $total_principal,
                'total_charge_partner'  => $total_charge_partner,
                'total_charge_customer' => $total_charge_customer
            ]
        ]);
        exit;

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ]);
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | <?php echo ucfirst($_SESSION['user_type'] ?? 'Guest'); ?></title>
    <link rel="stylesheet" href="../assets/css/templates/style.css?v=<?php echo time(); ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2-bootstrap-5-theme/1.3.0/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    <script src="https://kit.fontawesome.com/30b908cc5a.js" crossorigin="anonymous"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/sweetalert2.all.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
    <link rel="icon" href="../images/MLW logo.png" type="image/png">

    <style>
        .modal-body .card {
            border: none !important;
            box-shadow: none;
        }

        /* ============================================================
           PROFESSIONAL RED-THEMED CALENDAR (VIEW-ONLY)
           ============================================================ */
        .calendar-wrapper {
            background: #ffffff;
            border-radius: 14px;
            padding: 0;
            box-shadow: 0 6px 24px rgba(220, 53, 69, .10);
            border: 1px solid #f1e6e6;
            overflow: hidden;
        }

        .calendar-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 18px 24px;
            background: linear-gradient(135deg, #b81c2b 0%, #dc3545 55%, #e4606d 100%);
            color: #fff;
        }

        .calendar-title {
            font-weight: 700;
            font-size: 1.25rem;
            letter-spacing: .3px;
            color: #fff;
            margin: 0;
            text-shadow: 0 1px 2px rgba(0,0,0,.15);
        }

        .calendar-title small {
            display: block;
            font-size: .72rem;
            font-weight: 400;
            opacity: .85;
            letter-spacing: 1.2px;
            text-transform: uppercase;
            margin-top: 2px;
        }

        .calendar-nav-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            background: rgba(255,255,255,.15);
            color: #fff;
            border: 1px solid rgba(255,255,255,.35);
            border-radius: 8px;
            padding: 6px 14px;
            font-size: .85rem;
            font-weight: 500;
            transition: all .15s ease;
            backdrop-filter: blur(4px);
        }

        .calendar-nav-btn:hover {
            background: #fff;
            color: #dc3545;
            border-color: #fff;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0,0,0,.15);
        }

        .calendar-today-btn {
            background: #fff;
            color: #dc3545;
            border-color: #fff;
            font-weight: 600;
        }

        .calendar-today-btn:hover {
            background: #f8d7da;
            color: #b81c2b;
        }

        .calendar-grid-wrap {
            padding: 16px 20px 20px;
        }

        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 8px;
        }

        .calendar-dow {
            text-align: center;
            font-weight: 700;
            font-size: .72rem;
            color: #b81c2b;
            padding: 10px 0 6px;
            text-transform: uppercase;
            letter-spacing: 1px;
            background: #fdf3f4;
            border-radius: 8px;
        }

        .calendar-dow.weekend {
            color: #dc3545;
            background: #fbe6e9;
        }

        .calendar-day {
            position: relative;
            min-height: 86px;
            border: 1px solid #ececec;
            border-radius: 10px;
            padding: 8px 10px;
            background: #fff;
            cursor: default;
            transition: border-color .15s ease, box-shadow .15s ease;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            overflow: visible;
        }

        .calendar-day::before {
            content: "";
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 3px;
            background: transparent;
            border-radius: 10px 10px 0 0;
        }

        .calendar-day.has-tx {
            border-color: #f1d0d4;
        }

        .calendar-day.has-tx::before {
            background: linear-gradient(90deg, #dc3545 0%, #e4606d 100%);
        }

        .calendar-day.empty {
            background: #fafafa;
            border: 1px dashed #eee;
            min-height: 86px;
        }

        .calendar-day.empty::before { display: none; }

        .calendar-day .day-number {
            font-weight: 700;
            font-size: 1rem;
            color: #2b2b2b;
            line-height: 1;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .calendar-day.today {
            background: linear-gradient(180deg, #fff5f5 0%, #ffffff 100%);
            border-color: #dc3545;
            box-shadow: 0 0 0 1px #dc3545 inset;
        }

        .calendar-day.today::before {
            background: #dc3545;
        }

        .calendar-day.today .day-number {
            color: #dc3545;
        }

        .tx-count-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            align-self: flex-start;
            font-size: .7rem;
            font-weight: 700;
            padding: 3px 9px;
            border-radius: 20px;
            background: #dc3545;
            color: #fff;
            letter-spacing: .3px;
            line-height: 1;
        }

        .tx-count-badge i {
            font-size: .6rem;
            opacity: .9;
        }

        .tx-count-badge.zero {
            background: #ffe0e0;
            color: #b0b0b0;
            font-weight: 600;
        }

        /* ============================================================
           HOVER TOOLTIP FOR IMPORTED DATE
           ============================================================ */
        .calendar-day[data-imported-date]:hover::after {
            content: attr(data-imported-date);
            position: absolute;
            bottom: calc(100% + 8px);
            left: 50%;
            transform: translateX(-50%);
            background: #2b2b2b;
            color: #fff;
            font-size: 0.72rem;
            font-weight: 500;
            padding: 6px 14px;
            border-radius: 8px;
            white-space: nowrap;
            z-index: 100;
            box-shadow: 0 6px 20px rgba(0,0,0,.3);
            pointer-events: none;
            letter-spacing: .3px;
            animation: tooltipFadeIn .15s ease;
        }

        .calendar-day[data-imported-date]:hover::before {
            content: "";
            position: absolute;
            bottom: calc(100% + 3px);
            left: 50%;
            transform: translateX(-50%);
            border: 5px solid transparent;
            border-top-color: #2b2b2b;
            z-index: 100;
            pointer-events: none;
        }

        @keyframes tooltipFadeIn {
            from { opacity: 0; transform: translateX(-50%) translateY(4px); }
            to   { opacity: 1; transform: translateX(-50%) translateY(0); }
        }

        /* Edge tooltip adjustments for first/last columns */
        .calendar-grid .calendar-day:nth-child(7n+1)[data-imported-date]:hover::after,
        .calendar-grid .calendar-day:nth-child(7n+1)[data-imported-date]:hover::before {
            left: 0;
            transform: translateX(0);
        }
        .calendar-grid .calendar-day:nth-child(7n)[data-imported-date]:hover::after,
        .calendar-grid .calendar-day:nth-child(7n)[data-imported-date]:hover::before {
            left: auto;
            right: 0;
            transform: translateX(0);
        }

        .calendar-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            font-size: .78rem;
            color: #6c6c6c;
            padding: 14px 24px 18px;
            border-top: 1px solid #f5eeee;
            margin-top: 8px;
        }

        .calendar-legend .legend-item {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .calendar-legend .dot {
            display: inline-block;
            width: 11px;
            height: 11px;
            border-radius: 50%;
        }

        .dot-red   { background: #dc3545; }
        .dot-gray  { background: #e2e2e2; }
        .dot-today { background: #fff; border: 2px solid #dc3545; }

        @media (max-width: 576px) {
            .calendar-day { min-height: 68px; padding: 6px; }
            .calendar-day .day-number { font-size: .85rem; }
            .tx-count-badge { font-size: .62rem; padding: 2px 7px; }
            .calendar-title { font-size: 1rem; }
        }
    </style>
</head>

<body>
<div class="main-container">

    <?php include '../templates/header_ui.php'; ?>
    <?php include '../templates/sidebar.php'; ?>

    <div class="container-fluid">
        <div class="row">
            <div class="col-md-12">

                <!-- ================= CALENDAR CARD (READ-ONLY) ================= -->
                <div class="card mb-3" style="margin-top: 20px;">
                    <div class="card-body">
                        <div class="calendar-wrapper">
                            <div class="calendar-header">
                                <button type="button" class="calendar-nav-btn" id="prevMonth">
                                    <i class="fas fa-chevron-left"></i>
                                    <span class="d-none d-sm-inline">Prev</span>
                                </button>

                                <h4 class="calendar-title text-center" id="calendarTitle">
                                    —
                                    <small>Transaction Overview</small>
                                </h4>

                                <div class="d-flex gap-2">
                                    <button type="button" class="calendar-nav-btn calendar-today-btn" id="todayBtn">
                                        <i class="fas fa-calendar-day"></i>
                                        <span class="d-none d-sm-inline">Today</span>
                                    </button>
                                    <button type="button" class="calendar-nav-btn" id="nextMonth">
                                        <span class="d-none d-sm-inline">Next</span>
                                        <i class="fas fa-chevron-right"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="calendar-grid-wrap">
                                <div class="calendar-grid" id="calendarGrid">
                                    <!-- Populated by JS -->
                                </div>
                            </div>

                            <div class="calendar-legend">
                                <span class="legend-item"><span class="dot dot-red"></span> Has transactions</span>
                                <span class="legend-item"><span class="dot dot-gray"></span> No transactions</span>
                                <span class="legend-item"><span class="dot dot-today"></span> Today</span>
                                <span class="legend-item"><i class="fas fa-info-circle text-muted"></i> Hover a date to see imported date</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ================= TABLE CARD ================= -->
                <div class="card">
                    <div class="card-header">
                        <div class="row g-3 align-items-end justify-content-between">
                            <div class="col-md-3">
                                <label id="searchInstruction" class="h5 text-muted" style="display:none;">
                                    INSTRUCTION: <i>Click the row to view details</i>
                                </label>
                            </div>

                            <div class="col-md-6">
                                <div class="d-flex justify-content-end align-items-end gap-2">
                                    <div class="flex-grow-1" style="max-width: 300px;">
                                        <label for="search_input" class="form-label small text-muted">Search Reference Number:</label>
                                        <input type="text"
                                               id="search_input"
                                               name="search"
                                               class="form-control"
                                               placeholder="Search by Reference Number...">
                                    </div>
                                    <div class="flex-shrink-0">
                                        <button type="button" id="searchButton" class="btn btn-danger">
                                            <i class="fas fa-search"></i> Search
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card-body" style="display: none;">
                        <div id="tableContextLabel" class="mb-2 text-muted small"></div>
                        <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                            <table id="transactionReportTable" class="table table-bordered table-hover table-striped">
                                <thead class="table-light sticky-top">
                                    <tr>
                                        <th rowspan="2" class='text-truncate text-center align-middle'>CAD Status</th>
                                        <th rowspan="2" class='text-truncate text-center align-middle'>Billing Invoice</th>
                                        <th rowspan="2" class='text-truncate text-center align-middle'>Transaction Status</th>
                                        <th rowspan="2" class='text-truncate text-center align-middle'>Transaction Date</th>
                                        <th rowspan="2" class='text-truncate text-center align-middle'>Cancelled Date</th>
                                        <th rowspan="2" class='text-truncate text-center align-middle'>Reference Number</th>
                                        <th rowspan="2" class='text-truncate text-center align-middle'>Branch ID</th>
                                        <th rowspan="2" class='text-truncate text-center align-middle'>Branch Name</th>
                                        <th rowspan="2" class='text-center align-middle'>Source</th>
                                        <th rowspan="2" class='text-truncate text-center align-middle'>Partner Name</th>
                                        <th colspan="2" class='text-truncate text-center align-middle'>Partner ID</th>
                                        <th rowspan="2" class='text-truncate text-center align-middle'>GL Code</th>
                                        <th rowspan="2" class='text-truncate text-center align-middle'>GL Description</th>
                                        <th rowspan="2" class='text-truncate text-center align-middle'>Principal Amount</th>
                                        <th colspan="2" class='text-truncate text-center align-middle'>Charge to</th>
                                    </tr>
                                    <tr>
                                        <th class='text-center'>KP7</th>
                                        <th class='text-center'>KPX</th>
                                        <th class='text-center'>Partner</th>
                                        <th class='text-center'>Customer</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <!-- Data will be populated via JavaScript -->
                                </tbody>
                                <tfoot class="sticky-bottom table-dark">
                                    <tr>
                                        <th colspan="14" style="text-align:right">Total : </th>
                                        <th id="totalPrincipalAmount" class="text-end">0.00</th>
                                        <th id="totalChargetoPartner" class="text-end">0.00</th>
                                        <th id="totalChargetoCustomer" class="text-end">0.00</th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>

                        <!-- ================== MODAL ================== -->
                        <div class="modal fade" id="transactionModal" tabindex="-1" aria-labelledby="transactionModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
                            <div class="modal-dialog modal-xl">
                                <div class="modal-content">
                                    <div class="modal-header bg-danger text-white">
                                        <h5 class="modal-title" id="transactionModalLabel">
                                            <i class="fas fa-receipt"></i> Transaction Details
                                        </h5>
                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="container-fluid">
                                            <div class="row">
                                                <div class="col-md-6 pb-3">
                                                    <h6 class="border-bottom pb-2">
                                                        <i class="fas fa-info-circle text-danger"></i> Transaction Information
                                                    </h6>
                                                    <table>
                                                        <tbody>
                                                            <tr><td style="width:180px;"><strong>CAD Status:</strong></td><td><span id="modal-cad-status" class="text-muted">Posted</span></td></tr>
                                                            <tr><td><strong>Source:</strong></td><td><span id="modal-source-file" class="text-muted"></span></td></tr>
                                                            <tr><td><strong>Transaction Date:</strong></td><td><span id="modal-datetime" class="text-muted"></span></td></tr>
                                                            <tr><td><strong>Report Date:</strong></td><td><span id="modal-report-date" class="text-muted"></span></td></tr>
                                                            <tr><td><strong>Cancelled Date:</strong></td><td><span id="modal-cancelled-date" class="text-muted"></span></td></tr>
                                                            <tr><td><strong>Reference Number:</strong></td><td><mark><span id="modal-reference-no" class="text-muted"></span></mark></td></tr>
                                                            <tr><td><strong>Control Number:</strong></td><td><mark><span id="modal-control-number" class="text-muted"></span></mark></td></tr>
                                                            <tr><td><strong>Billing Invoice:</strong></td><td><span id="modal-billing-invoice" class="text-muted"></span></td></tr>
                                                            <tr><td><strong>Transaction Status:</strong></td><td><span id="modal-status" class="text-muted"></span></td></tr>
                                                        </tbody>
                                                    </table>
                                                </div>
                                                <div class="col-md-6 pb-3">
                                                    <h6 class="border-bottom pb-2">
                                                        <i class="fas fa-university text-danger"></i> Branch Information
                                                    </h6>
                                                    <table>
                                                        <tbody>
                                                            <tr><td style="width:130px;"><strong>Mainzone:</strong></td><td><span id="modal-mainzone" class="text-muted"></span></td></tr>
                                                            <tr><td><strong>Zone:</strong></td><td><span id="modal-zone-code" class="text-muted"></span></td></tr>
                                                            <tr><td><strong>Region Code:</strong></td><td><span id="modal-region-code" class="text-muted"></span></td></tr>
                                                            <tr><td><strong>Region Name:</strong></td><td><span id="modal-region-name" class="text-muted"></span></td></tr>
                                                            <tr><td><strong>Branch Name:</strong></td><td><span id="modal-outlet" class="text-muted"></span></td></tr>
                                                            <tr><td><strong>Branch ID:</strong></td><td><span id="modal-branch-id" class="text-muted"></span></td></tr>
                                                            <tr><td><strong>Branch Code:</strong></td><td><span id="modal-branch-code" class="text-muted"></span></td></tr>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-12 pb-3">
                                                    <table>
                                                        <tbody>
                                                            <tr><td style="width:180px;"><strong>Partner Name:</strong></td><td><span id="modal-partner-name" class="text-muted"></span></td></tr>
                                                            <tr><td><strong>Partner ID (KP7):</strong></td><td><span id="modal-partner-id" class="text-muted"></span></td></tr>
                                                            <tr><td><strong>Partner ID (KPX):</strong></td><td><span id="modal-partner-id-kpx" class="text-muted"></span></td></tr>
                                                            <tr><td><strong>GL Code:</strong></td><td><span id="modal-gl-code" class="text-muted"></span></td></tr>
                                                            <tr><td><strong>GL Description:</strong></td><td><span id="modal-gl-description" class="text-muted"></span></td></tr>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-12 pb-3">
                                                    <table>
                                                        <tbody>
                                                            <tr><td style="width:180px;"><strong>Payor Name:</strong></td><td><span id="modal-payor-name" class="text-muted"></span></td></tr>
                                                            <tr><td><strong>Account Number:</strong></td><td><span id="modal-account-number" class="text-muted"></span></td></tr>
                                                            <tr><td><strong>Account Name:</strong></td><td><span id="modal-account-name" class="text-muted"></span></td></tr>
                                                            <tr><td><strong>Address:</strong></td><td><span id="modal-address" class="text-muted"></span></td></tr>
                                                            <tr><td><strong>Contact Number:</strong></td><td><span id="modal-contact-number" class="text-muted"></span></td></tr>
                                                            <tr><td><strong>Operator:</strong></td><td><span id="modal-operator" class="text-muted"></span></td></tr>
                                                            <tr><td><strong>Remote Branch:</strong></td><td><span id="modal-remote-branch" class="text-muted"></span></td></tr>
                                                            <tr><td><strong>Remote Operator:</strong></td><td><span id="modal-remote-operator" class="text-muted"></span></td></tr>
                                                            <tr><td><strong>Second Approver:</strong></td><td><span id="modal-second-approver" class="text-muted"></span></td></tr>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-12 pb-3">
                                                    <table>
                                                        <tbody>
                                                            <tr><td style="width:180px;"><strong>Uploaded By:</strong></td><td><span id="modal-uploaded-by" class="text-muted">Test</span></td></tr>
                                                            <tr><td><strong>Uploaded Date:</strong></td><td><span id="modal-uploaded-date" class="text-muted">01-01-2026</span></td></tr>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-12">
                                                    <div class="row">
                                                        <div class="col-md-4">
                                                            <div class="card">
                                                                <div class="card-body text-center">
                                                                    <h6 class="card-title">Principal Amount</h6>
                                                                    <h4 id="modal-amount-paid" class="card-text text-danger fw-bold">₱0.00</h4>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-4">
                                                            <div class="card">
                                                                <div class="card-body text-center">
                                                                    <h6 class="card-title">Charge to Partner</h6>
                                                                    <h4 id="modal-charge-partner" class="card-text text-danger fw-bold">₱0.00</h4>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-4">
                                                            <div class="card">
                                                                <div class="card-body text-center">
                                                                    <h6 class="card-title">Charge to Customer</h6>
                                                                    <h4 id="modal-charge-customer" class="card-text text-danger fw-bold">₱0.00</h4>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <!-- ============== /MODAL ============== -->
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>
</body>
<?php include '../templates/footer.php'; ?>

<script>
$(document).ready(function() {

    /* ============================================================
       GLOBAL STATE
       ============================================================ */
    const today = new Date();
    let calendarYear  = today.getFullYear();
    let calendarMonth = today.getMonth() + 1;

    /* ============================================================
       HELPERS
       ============================================================ */
    function escapeRegex(str) {
        return str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    function pad2(n) { return n < 10 ? '0' + n : '' + n; }

    function ymd(date) {
        return date.getFullYear() + '-' + pad2(date.getMonth() + 1) + '-' + pad2(date.getDate());
    }

    const MONTH_NAMES = [
        'January','February','March','April','May','June',
        'July','August','September','October','November','December'
    ];

    const DOW_ABBR = ['SUN','MON','TUE','WED','THU','FRI','SAT'];

    /* ============================================================
       CALENDAR RENDERING (VIEW-ONLY)
       ============================================================ */
    function renderCalendar() {
        const title = MONTH_NAMES[calendarMonth - 1] + ' ' + calendarYear;
        $('#calendarTitle').html(title + '<small>Transaction Overview Based on Report Date</small>');

        $.ajax({
            url: 'home.php',
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'get_calendar_counts',
                year: calendarYear,
                month: calendarMonth
            },
            success: function(response) {
                const counts = (response && response.success) ? response.counts : {};
                buildCalendarGrid(counts);
            },
            error: function() {
                buildCalendarGrid({});
            }
        });
    }

    /**
     * Build calendar grid.
     * counts = { 'YYYY-MM-DD': { count: N, imported_date: 'Month D, YYYY' } }
     */
    function buildCalendarGrid(counts) {
        const grid = $('#calendarGrid');
        grid.empty();

        // Day-of-week headers
        DOW_ABBR.forEach(function(d, idx) {
            const cls = 'calendar-dow' + ((idx === 0 || idx === 6) ? ' weekend' : '');
            grid.append($('<div>').addClass(cls).text(d));
        });

        const firstDay    = new Date(calendarYear, calendarMonth - 1, 1);
        const startDow    = firstDay.getDay();
        const daysInMonth = new Date(calendarYear, calendarMonth, 0).getDate();
        const todayStr    = ymd(new Date());

        // Leading blanks
        for (let i = 0; i < startDow; i++) {
            grid.append($('<div>').addClass('calendar-day empty'));
        }

        // Day cells
        for (let d = 1; d <= daysInMonth; d++) {
            const dateStr = calendarYear + '-' + pad2(calendarMonth) + '-' + pad2(d);
            const entry   = counts[dateStr];

            // Support both old (number) and new (object) formats
            let count = 0;
            let importedDate = '';
            if (entry !== undefined && entry !== null) {
                if (typeof entry === 'object') {
                    count = entry.count || 0;
                    importedDate = entry.imported_date || '';
                } else {
                    count = entry;
                }
            }

            const cell = $('<div>').addClass('calendar-day');

            if (count > 0)            cell.addClass('has-tx');
            if (dateStr === todayStr) cell.addClass('today');

            // Attach imported date for tooltip
            if (importedDate) {
                cell.attr('data-imported-date', 'Imported: ' + importedDate);
            }

            const badgeClass = 'tx-count-badge' + (count === 0 ? ' zero' : '');
            const badgeHtml  = count === 0
                ? '0'
                : '<i class="fas fa-receipt"></i>' + count.toLocaleString();

            cell.append(
                $('<div>').addClass('day-number').text(d),
                $('<div>').addClass(badgeClass).html(badgeHtml)
            );

            grid.append(cell);
        }
    }

    /* ============================================================
       CALENDAR NAV
       ============================================================ */
    $('#prevMonth').on('click', function() {
        calendarMonth--;
        if (calendarMonth < 1) { calendarMonth = 12; calendarYear--; }
        renderCalendar();
    });

    $('#nextMonth').on('click', function() {
        calendarMonth++;
        if (calendarMonth > 12) { calendarMonth = 1; calendarYear++; }
        renderCalendar();
    });

    $('#todayBtn').on('click', function() {
        const now = new Date();
        calendarYear  = now.getFullYear();
        calendarMonth = now.getMonth() + 1;
        renderCalendar();
    });

    /* ============================================================
       REFERENCE SEARCH
       ============================================================ */
    $('#searchButton').on('click', function() {
        const searchValue = $('#search_input').val().trim();
        if (searchValue === '') {
            Swal.fire({ icon:'warning', title:'Search Required',
                        text:'Please enter a reference number to search.',
                        confirmButtonText:'OK' });
            return;
        }
        $('#tableContextLabel').html(
            '<i class="fas fa-search text-danger"></i> Search results for reference number: <strong>' +
            $('<div>').text(searchValue).html() + '</strong>'
        );
        loadTransactionByReference(searchValue);
    });

    $('#search_input').on('keypress', function(e) {
        if (e.which === 13) {
            $('#searchButton').trigger('click');
        }
    });

    $('#search_input').on('input', function() {
        if ($(this).val().trim() === '') {
            $('.card-body').hide();
            $('#transactionReportTable tbody').empty();
            $('#tableContextLabel').empty();
            updateTotals({ total_principal: 0, total_charge_partner: 0, total_charge_customer: 0 });
            $('#searchInstruction').hide();
        }
    });

    function loadTransactionByReference(searchValue) {
        $('.card-body').show();
        $('#transactionReportTable tbody').html(
            '<tr><td colspan="17" class="text-center"><i class="fas fa-spinner fa-spin"></i> Searching...</td></tr>'
        );

        $.ajax({
            url: 'home.php',
            type: 'POST',
            dataType: 'json',
            data: { action: 'get_transaction_data', search: searchValue },
            success: function(response) {
                if (response && response.success) {
                    if (response.data && response.data.length > 0) {
                        populateTable(response.data, searchValue);
                        updateTotals(response.totals);
                        showSearchResults(response.data.length, searchValue);
                        $('#searchInstruction').show();
                    } else {
                        $('#transactionReportTable tbody').html(
                            '<tr><td colspan="17" class="text-center text-muted">' +
                            '<i class="fas fa-search"></i><br>No transactions found for reference number: <strong>' +
                            $('<div>').text(searchValue).html() + '</strong></td></tr>'
                        );
                        updateTotals({ total_principal: 0, total_charge_partner: 0, total_charge_customer: 0 });
                        $('#searchInstruction').hide();
                        Swal.fire({ icon:'info', title:'No Results Found',
                                    text:'No transactions found for reference number: ' + searchValue,
                                    confirmButtonText:'OK' });
                    }
                } else {
                    showError(response && response.message);
                }
            },
            error: function() {
                showError('Failed to connect to the server.');
            }
        });
    }

    function showError(msg) {
        $('#transactionReportTable tbody').html(
            '<tr><td colspan="17" class="text-center text-danger">' +
            '<i class="fas fa-exclamation-triangle"></i> Error: ' + (msg || 'Unknown error') +
            '</td></tr>'
        );
        Swal.fire({ icon:'error', title:'Error',
                    text: msg || 'An error occurred.', confirmButtonText:'OK' });
    }

    function showSearchResults(totalRows, searchValue) {
        const Toast = Swal.mixin({
            toast: true, position: 'top-end',
            showConfirmButton: false, timer: 3000, timerProgressBar: true
        });
        Toast.fire({ icon: 'success',
                     title: `Found ${totalRows} result(s) for "${searchValue}"` });
    }

    /* ============================================================
       TABLE RENDER
       ============================================================ */
    function populateTable(data, highlightSearch) {
        const tbody = $('#transactionReportTable tbody');
        tbody.empty();

        if (!data || data.length === 0) {
            tbody.html('<tr><td colspan="17" class="text-center text-muted">No records found</td></tr>');
            return;
        }

        let highlightRegex = null;
        const rawSearch = (highlightSearch || '').trim();
        if (rawSearch) {
            const escapedSearch = escapeRegex(rawSearch);
            highlightRegex = new RegExp('^' + escapedSearch, 'i');
        }

        data.forEach(function(row) {
            const refNumber = row.reference_no || '';

            let highlightedRef = refNumber;
            if (highlightRegex && refNumber.toLowerCase().startsWith(rawSearch.toLowerCase())) {
                highlightedRef = refNumber.replace(highlightRegex, '<mark>$&</mark>');
            }

            const tr = $('<tr>').append(
                $('<td>').html(getCadStatusBadge(row.post_transaction || '-')),
                $('<td>').text(row.billing_invoice || '-'),
                $('<td>').html(getStatusBadge(row.status || '')),
                $('<td>').text(formatDate(row.datetime) || '-'),
                $('<td>').text(formatDate(row.cancellation_date) || '-'),
                $('<td>').html(highlightedRef),
                $('<td>').text(row.branch_id || '-'),
                $('<td>').text(row.outlet || '-'),
                $('<td>').text(row.source_file || '-'),
                $('<td class="text-truncate">').text(row.partner_name || '-'),
                $('<td>').text(row.partner_id || '-'),
                $('<td>').text(row.partner_id_kpx || '-'),
                $('<td>').text(row.mpm_gl_code || '-'),
                $('<td>').text(row.mpm_gl_description || '-'),
                $('<td>').addClass('text-end').text(formatCurrency(row.amount_paid)),
                $('<td>').addClass('text-end').text(formatCurrency(row.charge_to_partner)),
                $('<td>').addClass('text-end').text(formatCurrency(row.charge_to_customer))
            );

            tr.hover(
                function() { $(this).addClass('table-active'); },
                function() { $(this).removeClass('table-active'); }
            );

            tr.on('click', function() { openTransactionModal(row); });

            tbody.append(tr);
        });

        $('#searchInstruction').show();
    }

    /* ============================================================
       BADGES / FORMATTERS
       ============================================================ */
    function getCadStatusBadge(cadStatus) {
        let displayStatus, badgeClass;
        if (cadStatus === null || cadStatus === '' || cadStatus === undefined) {
            displayStatus = 'Unknown'; badgeClass = 'badge bg-secondary';
        } else if (cadStatus.toLowerCase() === 'unposted') {
            displayStatus = 'Unposted'; badgeClass = 'badge bg-warning text-dark';
        } else if (cadStatus.toLowerCase() === 'posted') {
            displayStatus = 'Posted'; badgeClass = 'badge bg-success text-white';
        } else {
            displayStatus = cadStatus; badgeClass = 'badge bg-info text-white';
        }
        return `<span class="${badgeClass}">${displayStatus}</span>`;
    }

    function getStatusBadge(status) {
        let displayStatus, badgeClass;
        if (status === '*') {
            displayStatus = 'Cancelled'; badgeClass = 'badge bg-danger text-white';
        } else if (status === null || status === '' || status === undefined) {
            displayStatus = 'Active'; badgeClass = 'badge bg-success text-white';
        } else {
            displayStatus = status.toString();
            const statusClasses = {
                'SUCCESS':   'badge bg-success text-white',
                'PENDING':   'badge bg-warning text-dark',
                'FAILED':    'badge bg-danger text-white',
                'CANCELLED': 'badge bg-danger text-white',
                'ACTIVE':    'badge bg-success text-white',
                'COMPLETED': 'badge bg-info text-white',
                'PROCESSING':'badge bg-primary text-white'
            };
            badgeClass = statusClasses[status.toUpperCase()] || 'badge bg-secondary text-white';
        }
        return `<span class="${badgeClass}">${displayStatus}</span>`;
    }

    function updateTotals(totals) {
        $('#totalPrincipalAmount').text(formatCurrency(totals.total_principal || 0));
        $('#totalChargetoPartner').text(formatCurrency(totals.total_charge_partner || 0));
        $('#totalChargetoCustomer').text(formatCurrency(totals.total_charge_customer || 0));
    }

    function formatCurrency(amount) {
        if (amount === null || amount === undefined || amount === '') return '0.00';
        return parseFloat(amount).toLocaleString('en-US', {
            minimumFractionDigits: 2, maximumFractionDigits: 2
        });
    }

    function formatDate(dateString) {
        if (!dateString) return '';
        const date = new Date(dateString);
        if (isNaN(date.getTime())) return dateString;
        return date.toLocaleDateString('en-US', {
            year:'numeric', month:'long', day:'numeric'
        });
    }

    /* ============================================================
       MODAL
       ============================================================ */
    function openTransactionModal(transaction) {
        $('#modal-reference-no').text(transaction.reference_no || '-');
        $('#modal-status').html(getStatusBadge(transaction.status || ''));
        $('#modal-cad-status').html(getCadStatusBadge(transaction.post_transaction || '-'));
        $('#modal-datetime').text(formatDate(transaction.datetime) || '-');
        $('#modal-report-date').text(formatDate(transaction.report_date) || '-');
        $('#modal-cancelled-date').text(formatDate(transaction.cancellation_date) || '-');
        $('#modal-billing-invoice').text(transaction.billing_invoice || '-');

        if (transaction.zone_code === 'VIS' || transaction.zone_code === 'MIN') {
            $('#modal-mainzone').text('VISMIN');
        } else if (transaction.zone_code === 'LZN' || transaction.zone_code === 'NCR') {
            $('#modal-mainzone').text('LNCR');
        } else {
            $('#modal-mainzone').text('-');
        }

        $('#modal-zone-code').text(transaction.zone_code || '-');
        $('#modal-region-code').text(transaction.region_code || '-');
        $('#modal-region-name').text(transaction.region || '-');
        $('#modal-branch-code').text(transaction.branch_code || '-');
        $('#modal-branch-id').text(transaction.branch_id || '-');
        $('#modal-outlet').text(transaction.outlet || '-');
        $('#modal-source-file').text(transaction.source_file || '-');
        $('#modal-partner-name').text(transaction.partner_name || '-');
        $('#modal-partner-id').text(transaction.partner_id || '-');
        $('#modal-partner-id-kpx').text(transaction.partner_id_kpx || '-');
        $('#modal-gl-code').text(transaction.mpm_gl_code || '-');
        $('#modal-gl-description').text(transaction.mpm_gl_description || '-');
        $('#modal-control-number').text(transaction.control_no || '-');
        $('#modal-payor-name').text(transaction.payor || '-');
        $('#modal-account-number').text(transaction.account_no || '-');
        $('#modal-account-name').text(transaction.account_name || '-');
        $('#modal-address').text(transaction.address || '-');
        $('#modal-contact-number').text(transaction.contact_no || '-');
        $('#modal-operator').text(transaction.operator || '-');
        $('#modal-remote-branch').text(transaction.remote_branch || '-');
        $('#modal-remote-operator').text(transaction.remote_operator || '-');
        $('#modal-second-approver').text(transaction['2nd_approver'] || '-');
        $('#modal-uploaded-by').text(transaction.imported_by || '-');
        $('#modal-uploaded-date').text(formatDate(transaction.imported_date) || '-');
        $('#modal-amount-paid').text(formatCurrency(transaction.amount_paid));
        $('#modal-charge-partner').text(formatCurrency(transaction.charge_to_partner));
        $('#modal-charge-customer').text(formatCurrency(transaction.charge_to_customer));

        $('#transactionModal').show();
    }

    /* ============================================================
       INIT
       ============================================================ */
    renderCalendar();
});
</script>
</html>