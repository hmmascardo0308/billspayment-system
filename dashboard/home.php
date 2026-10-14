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
    <link rel="stylesheet" href="home.css?v=<?= time(); ?>">

    <!-- ===================== STICKY NOTE STYLES ===================== -->
    <style>
        /* Shared sticky note base */
        .sticky-note {
            position: absolute;
            top: 110px;
            width: 450px;
            min-width: 220px;
            min-height: 100px;
            max-width: 90vw;
            max-height: 50vh;
            background: #fff9c4;
            border-radius: 2px 12px 12px 12px;
            box-shadow: 3px 6px 18px rgba(0,0,0,0.22),
                        0 0 0 1px rgba(0,0,0,0.05);
            z-index: 1050;
            cursor: grab;
            user-select: none;
            -webkit-user-select: none;
            opacity: .9;
            transition: box-shadow 0.2s,
                        transform 0.15s,
                        opacity 0.25s ease;
            font-family: 'Segoe UI', system-ui, sans-serif;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            resize: both; /* native resize handle (bottom-right) */
        }

        /* Distinct colors / default positions for stacking */
        .sticky-note[data-note-id="1"] {
            right: 40px;
            top: 110px;
            background: #fff9c4; /* classic yellow */
            z-index: 1052;
        }
        .sticky-note[data-note-id="2"] {
            right: 60px;
            top: 140px;
            background: #c8e6c9; /* soft green */
            z-index: 1051;
        }
        .sticky-note[data-note-id="3"] {
            right: 80px;
            top: 170px;
            background: #bbdefb; /* soft blue */
            z-index: 1050;
        }

        .sticky-note:hover {
            opacity: 1;
        }

        .sticky-note.dragging {
            opacity: 1;
            box-shadow: 6px 12px 28px rgba(0,0,0,0.3);
            transform: scale(1.02) rotate(0.8deg);
            z-index: 1100 !important;
        }

        .sticky-note:active {
            cursor: grabbing;
        }

        /* Tape / pin effect at the top */
        .sticky-note::before {
            content: '';
            position: absolute;
            top: -8px;
            left: 50%;
            transform: translateX(-50%) rotate(-2deg);
            width: 70px;
            height: 18px;
            background: rgba(255, 255, 255, 0.55);
            border: 1px solid rgba(0,0,0,0.08);
            border-radius: 2px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            pointer-events: none;
        }

        .sticky-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 12px 4px;
            background: rgba(0, 0, 0, 0.06);
            border-bottom: 1px dashed rgba(0,0,0,0.08);
            flex-shrink: 0;
            cursor: grab;
        }

        .sticky-title {
            font-size: 0.78rem;
            font-weight: 700;
            color: #5d4037;
            letter-spacing: 0.3px;
            text-transform: uppercase;
        }

        .sticky-controls button {
            background: none;
            border: none;
            color: #8d6e63;
            font-size: 0.85rem;
            padding: 0 4px;
            line-height: 1;
            opacity: 0.7;
            transition: opacity 0.15s, color 0.15s;
            cursor: pointer;
        }
        .sticky-controls button:hover {
            opacity: 1;
            color: #bf360c;
        }

        .sticky-body {
            padding: 8px 14px 16px;
            font-size: 0.92rem;
            line-height: 1.45;
            color: #3e2723;
            white-space: pre-wrap;
            overflow: auto;
            flex: 1 1 auto;
            min-height: 40px;
        }

        /* Allow selecting text and clicking links inside the body */
        .sticky-body,
        .sticky-body * {
            user-select: text;
            -webkit-user-select: text;
        }

        .sticky-body a {
            cursor: pointer;
            pointer-events: auto;
            position: relative;
            z-index: 10;
        }

        .sticky-body a:hover {
            color: #003d99 !important;
            text-decoration: underline !important;
        }

        /* Resize handle visual cue (bottom-right corner) */
        .sticky-resize-handle {
            position: absolute;
            right: 2px;
            bottom: 2px;
            width: 16px;
            height: 16px;
            cursor: nwse-resize;
            opacity: 0.45;
            z-index: 5;
            pointer-events: none; /* native resize handles the interaction */
        }
        .sticky-resize-handle::after {
            content: '';
            position: absolute;
            right: 3px;
            bottom: 3px;
            width: 8px;
            height: 8px;
            border-right: 2px solid #5d4037;
            border-bottom: 2px solid #5d4037;
        }

        /* Minimized state – just a small tab */
        .sticky-note.minimized {
            width: 52px !important;
            height: 52px !important;
            min-width: 52px !important;
            min-height: 52px !important;
            max-width: 52px !important;
            max-height: 52px !important;
            border-radius: 50%;
            overflow: hidden;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 2px 4px 12px rgba(0,0,0,0.25);
            opacity: 0.65;
            resize: none;
        }
        .sticky-note.minimized:hover {
            opacity: 1;
            transform: scale(1.08);
        }
        .sticky-note.minimized .sticky-header,
        .sticky-note.minimized .sticky-body,
        .sticky-note.minimized .sticky-resize-handle {
            display: none !important;
        }
        .sticky-note.minimized::after {
            content: '\f0f3'; /* fa-bell */
            font-family: 'Font Awesome 5 Free';
            font-weight: 900;
            font-size: 1.35rem;
            color: #5d4037;
        }
        .sticky-note.minimized::before {
            display: none;
        }

        /* Color accents for minimized dots */
        .sticky-note[data-note-id="1"].minimized { background: #ffeb3b; }
        .sticky-note[data-note-id="2"].minimized { background: #81c784; }
        .sticky-note[data-note-id="3"].minimized { background: #64b5f6; }
    </style>
</head>

<body>
<div class="main-container">

    <?php include '../templates/header_ui.php'; ?>
    <?php include '../templates/sidebar.php'; ?>

    <div class="container-fluid">
        <div class="row">
            <div class="col-md-12" style="position: relative;">

                <!-- ================= STICKY NOTES (3 stackable + resizable) ================= -->
                <!-- 
                    Edit the content of each .sticky-body below.
                    Notes can be dragged, resized (bottom-right corner), minimized, and stacked.
                -->

                <!-- Note 1 – Main reminders (yellow) -->
                <div class="sticky-note" data-note-id="1" id="stickyNote1">
                    <div class="sticky-header">
                        <span class="sticky-title">Reminder</span>
                        <div class="sticky-controls">
                            <button type="button" class="sticky-minimize" title="Minimize / Flip aside">
                                <i class="fas fa-minus"></i>
                            </button>
                        </div>
                    </div>
                    <div class="sticky-body">
📌 Functioning Features
• Import <b>daily</b> transactions.
• Allow <b>importing again of same report date</b> to see any late posting (will be tagged as = <b>Late Posting</b>).
• Settlement for current day <b>will include</b> the unsettled from other day <b>(e.g late posted transactions)</b>.
• Added modules: <b>CAD Loggings</b> and <b>BP vs GLE Transactions</b> (for user testing, please ask for access).
• For <b>CAD Loggings</b>, <b>new reasons are added</b> and logging of <b>cancelled transactions are already allowed</b>.

                    </div>
                    <div class="sticky-resize-handle" aria-hidden="true"></div>
                </div>

                <!-- Note 2 – Quick checklist (green) -->
                <div class="sticky-note" data-note-id="2" id="stickyNote2">
                    <div class="sticky-header">
                        <span class="sticky-title">Checklist</span>
                        <div class="sticky-controls">
                            <button type="button" class="sticky-minimize" title="Minimize / Flip aside">
                                <i class="fas fa-minus"></i>
                            </button>
                        </div>
                    </div>
                    <div class="sticky-body">
📌
<span style="text-decoration: line-through;"> • Please send sample regenerated data (e.g <b>late posting</b> as discussed) </span>
• Check if system generated outputs are the <b>same/equal</b> to your manual works <b>(e.g volume report, settlement, SOA/invoice)</b>.
• Update partner details if necessary (e.g <b>bank details</b>). Contact Masterdata administrator (<b>Joshua Ricablanca</b> for assistance in <b>adding/editing</b> partner details). Settlement <b>may not matched </b>due to bank and partner details not updated.
• <a href="http://ho-cad-exactdb:8080/masterdata/HomeLogin/index.php" target="_blank" rel="noopener noreferrer" style="color: #0066cc; text-decoration: underline; font-weight: bold;"><i class="fa-solid fa-link"></i> Masterdata System</a>
                    </div>
                    <div class="sticky-resize-handle" aria-hidden="true"></div>
                </div>

                <!-- Note 3 – Notes / scratch pad (blue) -->
                <div class="sticky-note" data-note-id="3" id="stickyNote3">
                    <div class="sticky-header">
                        <span class="sticky-title">Scratch Pad</span>
                        <div class="sticky-controls">
                            <button type="button" class="sticky-minimize" title="Minimize / Flip aside">
                                <i class="fas fa-minus"></i>
                            </button>
                        </div>
                    </div>
                    <div class="sticky-body">
📌
• Contact admin for issues found while testing.
• Feedbacks are appreciated.
                    </div>
                    <div class="sticky-resize-handle" aria-hidden="true"></div>
                </div>
                <!-- ============== /STICKY NOTES ============== -->

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
       STICKY NOTES – DRAGGABLE + RESIZABLE + STACKABLE (3 notes)
       ============================================================ */
    (function initStickyNotes() {
        const notes = document.querySelectorAll('.sticky-note');
        if (!notes.length) return;

        let topZ = 1060;

        function bringToFront(note) {
            topZ += 1;
            note.style.zIndex = topZ;
        }

        notes.forEach(function(note) {
            const noteId = note.getAttribute('data-note-id') || '1';
            const storageKey = 'stickyNoteState_' + noteId;

            // Restore position / size / minimized from localStorage
            try {
                const saved = localStorage.getItem(storageKey);
                if (saved) {
                    const state = JSON.parse(saved);
                    if (state.left)  { note.style.left  = state.left;  note.style.right = 'auto'; }
                    if (state.top)   { note.style.top   = state.top; }
                    if (state.width) note.style.width  = state.width;
                    if (state.height) note.style.height = state.height;
                    if (state.minimized) {
                        note.classList.add('minimized');
                        const icon = note.querySelector('.sticky-minimize i');
                        if (icon) {
                            icon.classList.remove('fa-minus');
                            icon.classList.add('fa-expand');
                        }
                        const btn = note.querySelector('.sticky-minimize');
                        if (btn) btn.setAttribute('title', 'Expand note');
                    }
                }
            } catch (e) {}

            function saveState() {
                try {
                    localStorage.setItem(storageKey, JSON.stringify({
                        left: note.style.left || null,
                        top: note.style.top || null,
                        width: note.style.width || null,
                        height: note.style.height || null,
                        minimized: note.classList.contains('minimized')
                    }));
                } catch (e) {}
            }

            // Persist size when user finishes resizing (native resize)
            note.addEventListener('mouseup', function() {
                // Capture current computed size after native resize
                if (!note.classList.contains('minimized')) {
                    const rect = note.getBoundingClientRect();
                    note.style.width  = rect.width + 'px';
                    note.style.height = rect.height + 'px';
                    saveState();
                }
            });

            // Drag
            let isDragging = false;
            let offsetX = 0, offsetY = 0;

            note.addEventListener('mousedown', function(e) {
                // Don't start drag on controls, links, or while resizing near bottom-right
                if (e.target.closest('.sticky-controls') || e.target.closest('a')) return;
                if (note.classList.contains('minimized')) return;

                // Avoid starting drag if click is near the native resize corner
                const rect = note.getBoundingClientRect();
                const nearResize = (e.clientX > rect.right - 18) && (e.clientY > rect.bottom - 18);
                if (nearResize) return;

                isDragging = true;
                note.classList.add('dragging');
                bringToFront(note);

                offsetX = e.clientX - rect.left;
                offsetY = e.clientY - rect.top;

                note.style.right = 'auto';
                note.style.left  = rect.left + 'px';
                note.style.top   = rect.top  + 'px';

                e.preventDefault();
            });

            document.addEventListener('mousemove', function(e) {
                if (!isDragging) return;
                const x = e.clientX - offsetX;
                const y = e.clientY - offsetY;
                const maxX = window.innerWidth  - note.offsetWidth  - 8;
                const maxY = window.innerHeight - note.offsetHeight - 8;
                note.style.left = Math.max(8, Math.min(x, maxX)) + 'px';
                note.style.top  = Math.max(8, Math.min(y, maxY)) + 'px';
            });

            document.addEventListener('mouseup', function() {
                if (!isDragging) return;
                isDragging = false;
                note.classList.remove('dragging');
                saveState();
            });

            // Bring to front on any click
            note.addEventListener('mousedown', function() {
                bringToFront(note);
            });

            // Minimize / Expand
            const minBtn = note.querySelector('.sticky-minimize');
            if (minBtn) {
                minBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    note.classList.toggle('minimized');
                    const icon = minBtn.querySelector('i');
                    if (note.classList.contains('minimized')) {
                        if (icon) { icon.classList.remove('fa-minus'); icon.classList.add('fa-expand'); }
                        minBtn.setAttribute('title', 'Expand note');
                    } else {
                        if (icon) { icon.classList.remove('fa-expand'); icon.classList.add('fa-minus'); }
                        minBtn.setAttribute('title', 'Minimize / Flip aside');
                    }
                    saveState();
                });
            }

            // Click minimized circle to expand
            note.addEventListener('click', function(e) {
                if (note.classList.contains('minimized') && !e.target.closest('.sticky-controls')) {
                    note.classList.remove('minimized');
                    const icon = note.querySelector('.sticky-minimize i');
                    if (icon) { icon.classList.remove('fa-expand'); icon.classList.add('fa-minus'); }
                    const btn = note.querySelector('.sticky-minimize');
                    if (btn) btn.setAttribute('title', 'Minimize / Flip aside');
                    bringToFront(note);
                    saveState();
                }
            });

            // Open links safely
            note.querySelectorAll('.sticky-body a').forEach(function(link) {
                link.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const url = this.getAttribute('href');
                    const target = this.getAttribute('target') || '_self';
                    if (url && url !== '#') {
                        window.open(url, target);
                        e.preventDefault();
                    }
                });
            });
        });
    })();

    /* ============================================================
       INIT
       ============================================================ */    /* ============================================================
       INIT
       ============================================================ */
    renderCalendar();
});
</script>
</html>