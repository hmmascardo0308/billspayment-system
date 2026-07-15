<?php
// Connect to the database
require_once __DIR__ . '/../../../config/config.php';
require '../../../vendor/autoload.php';

// Start the session
session_start();
// include shared permission helpers and resolve current user
@include_once __DIR__ . '/../../../templates/middleware.php';
$id = resolve_user_identifier();
if (empty($id)) { header('Location: ../../../login_form.php'); exit; }

// ensure a user display name exists (do not base on role)
if (!empty($_SESSION['admin_name'])) {
    $_SESSION['user_name'] = $_SESSION['admin_name'];
} elseif (empty($_SESSION['user_name'])) {
    $_SESSION['user_name'] = $_SESSION['user_email'] ?? $_SESSION['admin_email'] ?? '';
}

// require the Invoice Approval permission
if (!function_exists('has_any_permission') || !has_any_permission(['Invoice Approval','Bills Payment'])) {
    header('Location:../../home.php');
    exit();
}



// Function to display a modal with a message
function displayModal($message, $isError = false)
{
    echo '
    <script>
    window.onload = function() {
        var modal = document.getElementById("messageModal");
        var message = document.getElementById("modalMessage");
        
        if (' . ($isError ? 'true' : 'false') . ') {
            message.innerHTML = \'<div class="icon-container"><div class="err-icon">&#10060;</div></div> \' + "' . $message . '";
        } else {
            message.innerHTML = \'<div class="icon-container"><div class="icon">&#10003;</div></div> \' + "' . $message . '";
        }
        
        modal.classList.add("active");
    }
    </script>
    ';
}

// Normalize DB numeric values that may already contain commas (e.g., "67,147.20").
function formatMoneyForDisplay($value)
{
    if ($value === null || $value === '') {
        return number_format(0, 2);
    }

    $normalized = is_string($value) ? str_replace(',', '', trim($value)) : $value;
    return number_format((float)$normalized, 2);
}

$query = "SELECT * FROM soa_transaction";
$result = mysqli_query($conn, $query);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['confirmBtn'])) {
        $referenceNumber = $_POST['reference'] ?? '';
        $approvedby = $_SESSION['user_name'];
        // try to fetch the approver id/signature to avoid saving large base64 strings
        $approverId = function_exists('resolve_user_identifier') ? resolve_user_identifier() : null;
        $approverSigBlob = null;
        if (!empty($approverId)) {
            $sigStmt = $conn->prepare("SELECT signature FROM mldb.user_sig WHERE id_number = ? LIMIT 1");
            if ($sigStmt) {
                $sigStmt->bind_param('s', $approverId);
                $sigStmt->execute();
                $sigStmt->bind_result($sig_blob);
                if ($sigStmt->fetch()) {
                    $approverSigBlob = $sig_blob;
                }
                $sigStmt->close();
            }
        }
        $currentDate = date("m-d-Y");
        $notedFix_signature = 'LUELLA PERALTA';
        $approvedSignature = $approverId ? $approverId : ($approverSigBlob ? 'data:image/png;base64,' . base64_encode($approverSigBlob) : 'electronically signed');

        $stmt = $conn->prepare("UPDATE soa_transaction SET status = 'Approved', noted_signature = ?, noted_by = ?, notedDate_signature = ?, notedFix_signature = ? WHERE reference_number = ?");
        if ($stmt) {
            $stmt->bind_param('sssss', $approvedSignature, $approvedby, $currentDate, $notedFix_signature, $referenceNumber);
            if ($stmt->execute()) {
                displayModal("Selected row(s) updated to 'Approved'.");
            } else {
                displayModal("Error updating transaction: " . $stmt->error, true);
            }
            $stmt->close();
        } else {
            displayModal("Failed to prepare update statement: " . mysqli_error($conn), true);
        }
    } elseif (isset($_POST['approved'])) {
        if (isset($_POST['selectedRows'])) {
            $selectedRows = $_POST['selectedRows'];
            $approvedby = $_SESSION['user_name'];
            // attempt to resolve approver id for bulk
            $approverId = function_exists('resolve_user_identifier') ? resolve_user_identifier() : null;
            $approverSigBlob = null;
            if (!empty($approverId)) {
                $sigStmt = $conn->prepare("SELECT signature FROM mldb.user_sig WHERE id_number = ? LIMIT 1");
                if ($sigStmt) {
                    $sigStmt->bind_param('s', $approverId);
                    $sigStmt->execute();
                    $sigStmt->bind_result($sig_blob);
                    if ($sigStmt->fetch()) {
                        $approverSigBlob = $sig_blob;
                    }
                    $sigStmt->close();
                }
            }
            $currentDate = date("m-d-Y");
            $notedFix_signature = 'LUELLA PERALTA';
            $approvedSignature = $approverId ? $approverId : ($approverSigBlob ? 'data:image/png;base64,' . base64_encode($approverSigBlob) : 'electronically signed');

            $stmt = $conn->prepare("UPDATE soa_transaction SET status = 'Approved', noted_signature = ?, noted_by = ?, notedDate_signature = ?, notedFix_signature = ? WHERE reference_number = ?");
            if ($stmt) {
                $failed = false;
                foreach ($selectedRows as $ref) {
                    $stmt->bind_param('sssss', $approvedSignature, $approvedby, $currentDate, $notedFix_signature, $ref);
                    if (!$stmt->execute()) { $failed = true; break; }
                }
                $stmt->close();
                if (!$failed) {
                    displayModal("Selected row(s) updated to 'Approved'.");
                } else {
                    displayModal("Error updating selected row(s).", true);
                }
            } else {
                displayModal("Failed to prepare update statement: " . mysqli_error($conn), true);
            }
        }
    } elseif (isset($_POST['multipleCancelConfirmBtn']) && !empty($_POST['cancelledBy'])) {
        if (isset($_POST['selectedRows'])) {
            $selectedRows = $_POST['selectedRows'];
            $cancelledBy = $_POST['cancelledBy'];
            $reasonOf_cancellation = $_POST['cancellationReason'];
            $cancelled_date = $_POST['cancel_date'];

            $updateQuery = "UPDATE soa_transaction SET status = 'Cancelled', reasonOf_cancellation = '$reasonOf_cancellation', cancelled_by = '$cancelledBy', cancelled_date = '$cancelled_date' WHERE reference_number IN ('" . implode("','", $selectedRows) . "')";
            if (mysqli_query($conn, $updateQuery)) {
                $successMessage = "Selected row(s) updated to 'Cancelled'.<br>";
                $successMessage .= "Cancelled by: " . $cancelledBy;
                displayModal($successMessage);
            } else {
                displayModal("Error updating selected row(s): " . mysqli_error($conn), true);
            }
        }
    } elseif (isset($_POST['cancelConfirmBtn']) && !empty($_POST['cancelledBy'])) {
        $referenceNumber = $_POST['reference'] ?? '';
        $cancelledBy = $_POST['cancelledBy'];
        $reasonOf_cancellation = $_POST['cancellationReason'];
        $cancelled_date = $_POST['cancel_date'];

        $updateQuery = "UPDATE soa_transaction SET status = 'Cancelled', reasonOf_cancellation = '$reasonOf_cancellation', cancelled_by = '$cancelledBy', cancelled_date = '$cancelled_date' WHERE reference_number = '$referenceNumber'";
        if (mysqli_query($conn, $updateQuery)) {
            $successMessage = "Selected row(s) updated to 'Cancelled'.<br>";
            $successMessage .= "Cancelled by: " . $cancelledBy;
            displayModal($successMessage);
        } else {
            displayModal("Error updating selected row(s): " . mysqli_error($conn), true);
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SOA Approval | <?php if($_SESSION['user_type'] === 'admin' || $_SESSION['user_type'] === 'user') echo ucfirst($_SESSION['user_type']); else echo "Guest";?></title>
    <!-- custom CSS file link  -->
    <link rel="stylesheet" href="../../../assets/css/templates/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../../../assets/css/user_review.css?v=<?php echo time(); ?>">
    <script src="https://kit.fontawesome.com/30b908cc5a.js" crossorigin="anonymous"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="../../../assets/js/sweetalert2.all.min.js"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">

    <link rel="icon" href="../../../images/MLW logo.png" type="image/png">

    <style>
        :root {
            --brand: #C62828; --brand-hover: #B71C1C; --brand-light: #FFEBEE; --brand-dark: #8E0000;
            --success: #2E7D32; --n-50: #FAFAFA; --n-100: #F5F5F5; --n-200: #EEEEEE;
            --n-300: #E0E0E0; --n-400: #BDBDBD; --n-500: #9E9E9E; --n-600: #757575;
            --n-700: #616161; --n-800: #424242; --n-900: #212121;
            --shadow-sm: 0 1px 3px rgba(0,0,0,.10), 0 1px 2px rgba(0,0,0,.06);
            --shadow: 0 4px 6px rgba(0,0,0,.08), 0 2px 4px rgba(0,0,0,.06);
            --shadow-xl: 0 25px 50px rgba(0,0,0,.15);
            --radius-sm: 6px; --radius: 10px; --radius-lg: 14px;
            --ease: cubic-bezier(.4,0,.2,1); --row-h: 40px; --hdr-h: 44px;
        }

        .micon, .material-icons-round { font-family: 'Material Icons Round'; font-style: normal; font-size: 18px; line-height: 1; vertical-align: middle; }
        .micon-sm { font-size: 16px; }
        .micon-hdr { font-size: 22px; vertical-align: middle; }

        .tbl-toolbar { display: flex; align-items: center; gap: 10px; margin-bottom: 14px; flex-wrap: wrap; }
        .btn-action {
            display: inline-flex; align-items: center; gap: 6px; padding: 8px 18px; border: none;
            border-radius: var(--radius-sm); font-weight: 600; font-size: 13.5px; cursor: pointer;
            transition: background .18s var(--ease), transform .1s var(--ease), box-shadow .18s var(--ease);
            box-shadow: var(--shadow-sm); letter-spacing: .01em;
        }
        .btn-action:hover { transform: translateY(-1px); box-shadow: var(--shadow); }
        .btn-action:active { transform: translateY(0); }

        #forapproved { background: var(--n-400); color: #fff; pointer-events: none; opacity: .7; cursor: not-allowed; }
        #forapproved.enabled { background: var(--success); pointer-events: auto; opacity: 1; cursor: pointer; }
        #forapproved.enabled:hover { background: #1b5e20; }
        #forcancelled { background: var(--brand); color: #fff; }
        #forcancelled:hover { background: var(--brand-hover); }

        .pagination-bar { display: flex; align-items: center; justify-content: space-between; margin-top: 12px; flex-wrap: wrap; gap: 8px; }
        .pagination-info { font-size: 12.5px; color: var(--n-600); }
        .pagination-btns { display: flex; gap: 4px; }
        .pg-btn {
            display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px;
            border: 1px solid var(--n-300); border-radius: var(--radius-sm); background: #fff;
            cursor: pointer; font-size: 13px; color: var(--n-700);
        }
        .pg-btn:hover { background: var(--brand-light); border-color: var(--brand); color: var(--brand); }
        .pg-btn.active { background: var(--brand); border-color: var(--brand); color: #fff; font-weight: 700; }
        .pg-btn[disabled] { opacity: .4; pointer-events: none; }

        .cbx { appearance: none; -webkit-appearance: none; display: inline-grid; place-items: center; width: 16px; height: 16px;
            border: 2px solid var(--n-400); border-radius: 3px; background: #fff; cursor: pointer; transition: background .12s, border-color .12s; }
        .cbx::before { content: ""; width: 9px; height: 9px; clip-path: polygon(14% 44%, 0 65%, 50% 100%, 100% 16%, 80% 0%, 43% 62%); transform: scale(0); background: #fff; transition: transform .1s var(--ease); }
        .cbx:hover { border-color: var(--brand); }
        .cbx:checked { background: var(--brand); border-color: var(--brand); }
        .cbx:checked::before { transform: scale(1); }
        .cbx-lg { width: 17px; height: 17px; }
        .cbx-lg::before { width: 10px; height: 10px; }

        .select-all-wrap { display: flex; flex-direction: column; align-items: center; gap: 4px; cursor: pointer; user-select: none; }
        .select-all-label { font-size: 9px; font-weight: 700; color: var(--n-600); text-transform: uppercase; letter-spacing: .05em; line-height: 1; }

        .tbl-scroll-wrapper { overflow: auto; border: 1px solid var(--n-200); border-radius: var(--radius); box-shadow: var(--shadow-sm); max-height: calc(10 * var(--row-h) + var(--hdr-h) + 16px); }
        table.soa-table { width: 100%; min-width: max-content; border-collapse: collapse; white-space: nowrap; table-layout: auto !important; }
        table.soa-table th, table.soa-table td { width: auto !important; max-width: none !important; min-width: 0; white-space: nowrap !important; overflow: visible !important; }
        table.soa-table thead th {
            position: sticky; top: 0; z-index: 2; background: var(--n-50); padding: 0 14px; height: var(--hdr-h);
            font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--n-700);
            border-bottom: 2px solid var(--n-200); border-right: 1px solid var(--n-200); text-align: center;
            display: table-cell !important; visibility: visible !important; opacity: 1 !important; line-height: 1.2 !important;
        }
        table.soa-table thead th:last-child { border-right: none; }
        table.soa-table thead th.th-check { padding: 0 8px; min-width: 60px; width: 60px; }
        table.soa-table tbody tr { height: var(--row-h); cursor: pointer; transition: background .12s; }
        table.soa-table tbody tr:hover { background: var(--brand-light); }
        table.soa-table tbody tr.selected-row { background: #FFCDD2; }
        table.soa-table tbody tr.selected-row td { color: var(--brand-dark); }
        table.soa-table tbody td {
            padding: 0 14px; font-size: 13px; color: var(--n-800); border-bottom: 1px solid var(--n-100); border-right: 1px solid var(--n-100);
            vertical-align: middle; display: table-cell !important; visibility: visible !important; opacity: 1 !important; line-height: 1.25 !important;
        }
        table.soa-table tbody td:last-child { border-right: none; }
        table.soa-table tbody td.td-check { text-align: center; }
        table.soa-table td.soa-ta-num, table.soa-table th.soa-ta-num { text-align: right; }
        table.soa-table td.soa-ta-center, table.soa-table th.soa-ta-center { text-align: center; }
        table.soa-table td.soa-ta-left, table.soa-table th.soa-ta-left { text-align: left; }
        .bp-card table.soa-table thead th, .bp-card table.soa-table tbody td { color: #424242 !important; text-indent: 0 !important; font-size: inherit !important; }

        .col-ref { min-width: 140px; } .col-partner { min-width: 250px; } .col-tin { min-width: 150px; }
        .col-address { min-width: 260px; } .col-business { min-width: 190px; } .col-service { min-width: 120px; }
        .col-date { min-width: 120px; } .col-po { min-width: 140px; } .col-count { min-width: 110px; }
        .col-amount { min-width: 130px; } .col-user { min-width: 150px; }

        .container-fluid { padding: 12px; margin: 0; }

        .modal-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(10,10,10,.5);
            backdrop-filter: blur(3px); -webkit-backdrop-filter: blur(3px);
            z-index: 1050;
            align-items: center; justify-content: center; padding: 16px;
        }
        .modal-overlay.active { display: flex; }

        .modal-card {
            background: #fff; border-radius: var(--radius-lg);
            box-shadow: var(--shadow-xl);
            width: 100%; max-width: 520px;
            animation: mcSlide .2s var(--ease);
            overflow: hidden;
        }
        .modal-card-wide { max-width: 880px; }
        @keyframes mcSlide { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }

        .modal-card-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 14px 20px;
            background: var(--brand);
            color: #fff;
        }
        .modal-card-header h3 {
            margin: 0; font-size: 15px; font-weight: 600;
            color: #fff; display: flex; align-items: center; gap: 8px;
        }
        .modal-close-btn {
            background: none; border: none; cursor: pointer;
            color: rgba(255,255,255,.8); font-size: 22px; line-height: 1;
            padding: 2px 6px; border-radius: 4px;
            transition: color .12s, background .12s;
        }
        .modal-close-btn:hover { color: #fff; background: rgba(255,255,255,.15); }

        .modal-card-body  { padding: 20px 22px; }
        .modal-card-footer {
            padding: 12px 20px 16px;
            display: flex; align-items: center; justify-content: flex-end;
            gap: 8px; flex-wrap: wrap;
            border-top: 1px solid var(--n-200);
        }

        .rv-body { display: grid; grid-template-columns: 1fr 1fr; gap: 0; }
        .rv-col { padding: 18px 22px; }
        .rv-col + .rv-col { border-left: 1px solid var(--n-200); background: var(--n-50); }
        .rv-section-label { font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: var(--brand); margin-bottom: 12px; padding-bottom: 6px; border-bottom: 2px solid var(--brand-light); }
        .rv-row { display: flex; align-items: baseline; gap: 8px; margin-bottom: 10px; font-size: 13px; }
        .rv-lbl { min-width: 110px; font-size: 11px; font-weight: 600; color: var(--n-600); text-transform: uppercase; letter-spacing: .03em; flex-shrink: 0; padding-top: 1px; }
        .rv-val { flex: 1; color: var(--n-900); font-weight: 500; word-break: break-word; }
        .rv-amt, .peso-inline { font-variant-numeric: tabular-nums; }
        .peso-inline { display: inline-flex; align-items: center; gap: 4px; }

        .btn-modal {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 8px 18px; border: none; border-radius: var(--radius-sm);
            font-size: 13px; font-weight: 600; cursor: pointer;
            transition: background .14s var(--ease), transform .1s, box-shadow .14s;
            box-shadow: var(--shadow-sm);
        }
        .btn-modal:hover  { transform: translateY(-1px); box-shadow: var(--shadow); }
        .btn-modal:active { transform: translateY(0); }
        .btn-green { background: var(--success); color: #fff; }
        .btn-green:hover { background: #1b5e20; }
        .btn-red { background: var(--brand); color: #fff; }
        .btn-red:hover { background: var(--brand-hover); }
        .btn-outline-red { background: #fff; color: var(--brand); border: 1.5px solid var(--brand); }
        .btn-outline-red:hover { background: var(--brand-light); }
        .btn-ghost { background: var(--n-100); color: var(--n-800); border: 1px solid var(--n-300); }
        .btn-ghost:hover { background: var(--n-200); }

        .cancel-textarea { width: 100%; min-height: 100px; border: 1.5px solid var(--n-200); border-radius: var(--radius-sm); padding: 10px 12px; font-size: 13.5px; color: var(--n-900); resize: vertical; outline: none; font-family: inherit; line-height: 1.5; transition: border-color .14s, box-shadow .14s; box-sizing: border-box; }
        .cancel-textarea:focus { border-color: var(--brand); box-shadow: 0 0 0 3px var(--brand-light); }
        .cancel-textarea.error { border-color: var(--brand); }
        .char-count { font-size: 11px; color: var(--n-500); text-align: right; margin-top: 4px; }

        .confirm-body { padding: 24px 24px 16px; text-align: center; }
        .confirm-body h4 { margin: 0 0 8px; font-size: 17px; font-weight: 700; color: var(--n-900); }
        .confirm-body p { margin: 0; font-size: 13px; color: var(--n-600); line-height: 1.6; }

        .message-modal { display: none; position: fixed; inset: 0; background: rgba(10,10,10,.5); backdrop-filter: blur(3px); z-index: 1100; align-items: center; justify-content: center; }
        .message-modal.active { display: flex; }
        .message-modal-content { background: #fff; border-radius: var(--radius-lg); padding: 30px 36px; text-align: center; max-width: 380px; width: 90%; box-shadow: var(--shadow-xl); }
        #modalMessage { font-size: 14px; color: var(--n-700); line-height: 1.6; display: block; margin-bottom: 16px; }
        .icon-container { margin-bottom: 10px; }
        .icon { font-size: 40px; color: var(--success); }
        .err-icon { font-size: 40px; color: var(--brand); }
        .close-button { background: var(--brand); color: #fff; border: none; padding: 8px 28px; border-radius: var(--radius-sm); font-size: 13px; font-weight: 600; cursor: pointer; }

        @media (max-width: 960px) {
            .rv-body { grid-template-columns: 1fr; }
            .rv-col + .rv-col { border-left: none; border-top: 1px solid var(--n-200); }
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
                <i class="fa-solid fa-check-to-slot" aria-hidden="true"></i>
                <div>
                    <h2>SOA Approval</h2>
                    <div class="bp-section-sub">List of Transaction(s)</div>
                </div>
            </div>
        </div>
        <form action="" method="POST">
            <div class="bp-card container-fluid mt-3 p-4">
                <div class="tbl-toolbar">
                    <button type="submit" id="forapproved" class="btn-action" name="approved" disabled>
                        <span class="micon micon-sm">done_all</span> Bulk Approve
                    </button>
                    <button type="button" id="forcancelled" class="btn-action" name="cancelled">
                        <span class="micon micon-sm">block</span> Cancel Selected
                    </button>
                </div>

                <div class="tbl-scroll-wrapper">
                    <table class="soa-table">
                        <thead>
                            <tr>
                                <th class="th-check">
                                    <label class="select-all-wrap" title="Select all transactions">
                                        <input type="checkbox" id="selectAllCheckbox" class="cbx cbx-lg" aria-label="Select all">
                                        <span class="select-all-label">All</span>
                                    </label>
                                </th>
                                <th class="soa-ta-center col-date">Date</th>
                                <th class="soa-ta-left col-ref">Reference #</th>
                                <th class="soa-ta-left col-partner">Partner Name</th>
                                <th class="soa-ta-left col-tin">Partner TIN</th>
                                <th class="soa-ta-left col-address">Address</th>
                                <th class="soa-ta-left col-business">Business Style</th>
                                <th class="soa-ta-left col-service">Service Charge</th>
                                <th class="soa-ta-center col-date">From Date</th>
                                <th class="soa-ta-center col-date">To Date</th>
                                <th class="soa-ta-left col-po">PO Number</th>
                                <th class="soa-ta-num col-count">No. of Transactions</th>
                                <th class="soa-ta-num col-amount">Amount</th>
                                <th class="soa-ta-num col-amount">VAT Amount</th>
                                <th class="soa-ta-num col-amount">Net of VAT</th>
                                <th class="soa-ta-num col-amount">Withholding Tax</th>
                                <th class="soa-ta-num col-amount">Net Amount Due</th>
                                <th class="soa-ta-left col-user">Created By</th>
                                <th class="soa-ta-left col-user">Reviewed By</th>
                                <th class="soa-ta-left col-user">Approved By</th>
                            </tr>
                        </thead>
                        <tbody id="tableBody">
                            <?php while ($row = mysqli_fetch_assoc($result)): ?>
                                <?php if ($row['status'] === 'Reviewed') { ?>
                                    <?php
                                    $referenceNumber = $row['reference_number'];
                                    $isSelected = isset($_GET['reference_number']) && $_GET['reference_number'] === $referenceNumber;
                                    $rowClass = $isSelected ? "selected-row" : "";
                                    ?>
                                    <tr class="table-row <?php echo $rowClass; ?>"
                                        data-add-amount="<?php echo htmlspecialchars($row['add_amount'] ?? '', ENT_QUOTES); ?>"
                                        data-formula="<?php echo htmlspecialchars($row['formula'] ?? '', ENT_QUOTES); ?>"
                                        data-formula-withheld="<?php echo htmlspecialchars($row['formula_withheld'] ?? '', ENT_QUOTES); ?>"
                                        onclick="handleRowClick(event, this, '<?php echo htmlspecialchars($referenceNumber, ENT_QUOTES); ?>')"
                                        title="Click to approve">
                                        <td class="td-check" onclick="event.stopPropagation()">
                                            <input type="checkbox" class="cbx row-checkbox" name="selectedRows[]" value="<?php echo htmlspecialchars($referenceNumber, ENT_QUOTES); ?>">
                                        </td>
                                        <td class="soa-ta-center"><?php echo date('M j, Y', strtotime($row['date'])); ?></td>
                                        <td class="soa-ta-left"><?php echo htmlspecialchars($row['reference_number'] ?? ''); ?></td>
                                        <td class="soa-ta-left"><?php echo htmlspecialchars($row['partner_Name'] ?? ''); ?></td>
                                        <td class="soa-ta-left"><?php echo htmlspecialchars($row['partner_Tin'] ?? ''); ?></td>
                                        <td class="soa-ta-left"><?php echo htmlspecialchars($row['address'] ?? ''); ?></td>
                                        <td class="soa-ta-left"><?php echo htmlspecialchars($row['business_style'] ?? ''); ?></td>
                                        <td class="soa-ta-left"><?php echo htmlspecialchars($row['service_charge'] ?? ''); ?></td>
                                        <td class="soa-ta-center"><?php echo date('M j, Y', strtotime($row['from_date'])); ?></td>
                                        <td class="soa-ta-center"><?php echo date('M j, Y', strtotime($row['to_date'])); ?></td>
                                        <td class="soa-ta-left"><?php echo htmlspecialchars($row['po_number'] ?? ''); ?></td>
                                        <td class="soa-ta-num"><?php echo number_format($row['number_of_transactions']); ?></td>
                                        <td class="soa-ta-num"><?php echo formatMoneyForDisplay($row['amount'] ?? 0); ?></td>
                                        <td class="soa-ta-num"><?php echo formatMoneyForDisplay($row['vat_amount'] ?? 0); ?></td>
                                        <td class="soa-ta-num"><?php echo formatMoneyForDisplay($row['net_of_vat'] ?? 0); ?></td>
                                        <td class="soa-ta-num"><?php echo formatMoneyForDisplay($row['withholding_tax'] ?? 0); ?></td>
                                        <td class="soa-ta-num"><?php echo formatMoneyForDisplay($row['net_amount_due'] ?? 0); ?></td>
                                        <td class="soa-ta-left"><?php echo htmlspecialchars($row['prepared_by'] ?? ''); ?></td>
                                        <td class="soa-ta-left"><?php echo htmlspecialchars($row['reviewed_by'] ?? ''); ?></td>
                                        <td class="soa-ta-left"><?php echo htmlspecialchars($row['noted_by'] ?? ''); ?></td>
                                    </tr>
                            <?php }
                            endwhile; ?>
                        </tbody>
                    </table>
                </div>

                <div class="pagination-bar" id="paginationBar">
                    <span class="pagination-info" id="paginationInfo"></span>
                    <div class="pagination-btns" id="paginationBtns"></div>
                </div>
            </div>

            <div id="confirmModal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="approveModalTitle">
                <div class="modal-card modal-card-wide">
                    <div class="modal-card-header">
                        <h3 id="approveModalTitle">
                            <span class="material-icons-round micon-hdr" aria-hidden="true">fact_check</span>
                            Approve Transaction
                        </h3>
                        <button type="button" class="modal-close-btn" onclick="closeApprovalModal()" aria-label="Close">&times;</button>
                    </div>
                    <div class="rv-body">
                        <input type="hidden" id="cancelledBy" name="cancelledBy" value="<?php echo htmlspecialchars($_SESSION['user_name'] ?? $_SESSION['admin_name'] ?? ''); ?>">
                        <div class="rv-col">
                            <div class="rv-section-label">
                                <span class="material-icons-round micon-sm" aria-hidden="true">description</span> Transaction Details
                            </div>
                            <div class="rv-row"><span class="rv-lbl">Date</span><span class="rv-val" id="fv-date"></span></div>
                            <div class="rv-row"><span class="rv-lbl">Reference #</span><input type="hidden" id="reference" name="reference" value=""><span class="rv-val" id="fv-reference"></span></div>
                            <div class="rv-row"><span class="rv-lbl">Partner Name</span><span class="rv-val" id="fv-partnerName"></span></div>
                            <div class="rv-row"><span class="rv-lbl">Partner TIN</span><span class="rv-val" id="fv-tin"></span></div>
                            <div class="rv-row"><span class="rv-lbl">Service Charge</span><span class="rv-val" id="fv-serviceCharge"></span></div>
                            <div class="rv-row"><span class="rv-lbl">From Date</span><span class="rv-val" id="fv-fromDate"></span></div>
                            <div class="rv-row"><span class="rv-lbl">To Date</span><span class="rv-val" id="fv-toDate"></span></div>
                            <div class="rv-row"><span class="rv-lbl">No. of Transactions</span><span class="rv-val" id="fv-numTxn"></span></div>
                        </div>
                        <div class="rv-col">
                            <div class="rv-section-label">
                                <span class="material-icons-round micon-sm" aria-hidden="true">payments</span> Financial Summary
                            </div>
                            <div class="rv-row" id="addAmountDue-div" style="display:none;"><span class="rv-lbl">Add Amount</span><span class="rv-val rv-amt"><span class="peso-inline"><span>₱</span><span id="fv-addAmountVal"></span></span><input type="hidden" id="addAmountInp" name="addAmount" value=""></span></div>
                            <div class="rv-row"><span class="rv-lbl">Amount</span><span class="rv-val rv-amt"><span class="peso-inline"><span>₱</span><span id="fv-amount"></span></span><input type="hidden" id="amount-modal" name="amount-modal" value=""></span></div>
                            <div class="rv-row"><span class="rv-lbl">VAT Amount</span><span class="rv-val rv-amt"><span class="peso-inline"><span>₱</span><span id="fv-vat"></span></span><input type="hidden" id="vatAmount" name="vatAmount" value=""></span></div>
                            <div class="rv-row"><span class="rv-lbl">Net of VAT</span><span class="rv-val rv-amt"><span class="peso-inline"><span>₱</span><span id="fv-netVat"></span></span><input type="hidden" id="netOfVAT" name="netOfVAT" value=""></span></div>
                            <div class="rv-row"><span class="rv-lbl">Withholding Tax</span><span class="rv-val rv-amt"><span class="peso-inline"><span>₱</span><span id="fv-wtax"></span></span><input type="hidden" id="withholdingTax" name="withholdingTax" value=""></span></div>
                            <div class="rv-row"><span class="rv-lbl">Net Amount Due</span><span class="rv-val rv-amt"><span class="peso-inline"><span>₱</span><span id="fv-netAmtDue"></span></span><input type="hidden" id="netAmountDue" name="netAmountDue" value=""></span></div>
                        </div>
                    </div>
                    <div class="modal-card-footer">
                        <button type="button" id="triggerApproveConfirm" class="btn-modal btn-green"><span class="micon micon-sm">verified</span> Approve</button>
                        <button type="button" id="cancelled-one" class="btn-modal btn-outline-red"><span class="micon micon-sm">block</span> Cancel Txn</button>
                        <button type="button" class="btn-modal btn-ghost" onclick="closeApprovalModal()">Close</button>
                    </div>
                </div>
            </div>

            <!-- Single Cancellation Modal -->
            <div id="cancellationModal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="cancelTitle1">
                <div class="modal-card">
                    <div class="modal-card-header">
                        <h3 id="cancelTitle1">
                            <span class="micon micon-hdr">cancel</span>
                            Reason for Cancellation
                        </h3>
                        <button type="button" class="modal-close-btn" onclick="closeCancelModal('cancellationModal')" aria-label="Close">&times;</button>
                    </div>
                    <div class="modal-card-body">
                        <input type="hidden" name="cancel_date" id="cancel_date" value="<?php echo date('Y-m-d'); ?>">
                        <label style="display:block;font-size:13px;font-weight:600;color:var(--n-600);margin-bottom:8px;">
                            Please describe the reason for cancellation <span style="color:var(--brand);">*</span>
                        </label>
                        <textarea id="cancellationReason" name="cancellationReason" maxlength="500"
                            class="cancel-textarea" placeholder="Enter your reason here..."></textarea>
                        <div class="char-count"><span id="charCount1">0</span> / 500</div>
                    </div>
                    <div class="modal-card-footer">
                        <button type="submit" id="cancelConfirmBtn" name="cancelConfirmBtn" class="btn-modal btn-red">
                            <span class="micon micon-sm">check_circle</span> Confirm Cancellation
                        </button>
                        <button type="button" class="btn-modal btn-ghost" onclick="closeCancelModal('cancellationModal')">
                            Go Back
                        </button>
                    </div>
                </div>
            </div>

            <!-- Multiple Cancellation Modal -->
            <div id="multipleCancellationModal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="cancelTitle2">
                <div class="modal-card">
                    <div class="modal-card-header">
                        <h3 id="cancelTitle2">
                            <span class="micon micon-hdr">cancel</span>
                            Reason for Bulk Cancellation
                        </h3>
                        <button type="button" class="modal-close-btn" onclick="closeCancelModal('multipleCancellationModal')" aria-label="Close">&times;</button>
                    </div>
                    <div class="modal-card-body">
                        <input type="hidden" name="cancel_date" id="cancel_date_multi" value="<?php echo date('Y-m-d'); ?>">
                        <label style="display:block;font-size:13px;font-weight:600;color:var(--n-600);margin-bottom:8px;">
                            Please describe the reason for cancellation <span style="color:var(--brand);">*</span>
                        </label>
                        <textarea id="multipleCancellationReason" name="cancellationReason" maxlength="500"
                            class="cancel-textarea" placeholder="Enter your reason here..."></textarea>
                        <div class="char-count"><span id="charCount2">0</span> / 500</div>
                    </div>
                    <div class="modal-card-footer">
                        <button type="submit" id="multipleCancelConfirmBtn" name="multipleCancelConfirmBtn" class="btn-modal btn-red">
                            <span class="micon micon-sm">check_circle</span> Confirm Cancellation
                        </button>
                        <button type="button" class="btn-modal btn-ghost" onclick="closeCancelModal('multipleCancellationModal')">
                            Go Back
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <div id="approveConfirmModal" class="modal-overlay" role="dialog" aria-modal="true" style="z-index:1060;">
        <div class="modal-card">
            <div class="confirm-body">
                <div class="confirm-icon"><span class="material-icons-round" style="color:var(--success);font-size:36px;">verified</span></div>
                <h4>Approve This Transaction?</h4>
                <p>This action will mark the transaction as <strong>Approved</strong>. It cannot be undone.</p>
            </div>
            <div class="modal-card-footer" style="justify-content:center;gap:12px;">
                <button type="button" id="confirmApproveYes" class="btn-modal btn-green"><span class="micon micon-sm">check_circle</span> Confirm</button>
                <button type="button" class="btn-modal btn-ghost" onclick="closeConfirmModal('approveConfirmModal')">Cancel</button>
            </div>
        </div>
    </div>

    <div id="messageModal" class="message-modal" role="alertdialog">
        <div class="message-modal-content">
            <span id="modalMessage"></span>
            <button class="close-button" id="msgCloseBtn">CLOSE</button>
        </div>
    </div>
    <script>
        var ROWS_PER_PAGE = 10;
        var currentPage = 1;
        var tableRows = document.getElementsByClassName('table-row');

        function getAllRows() {
            return Array.from(document.querySelectorAll('#tableBody .table-row'));
        }

        function renderPage(page) {
            var rows = getAllRows();
            var total = rows.length;
            var pages = Math.max(1, Math.ceil(total / ROWS_PER_PAGE));
            currentPage = Math.min(Math.max(1, page), pages);

            rows.forEach(function (r, i) {
                r.style.display = (i >= (currentPage - 1) * ROWS_PER_PAGE && i < currentPage * ROWS_PER_PAGE) ? '' : 'none';
            });

            var from = total === 0 ? 0 : (currentPage - 1) * ROWS_PER_PAGE + 1;
            var to = Math.min(currentPage * ROWS_PER_PAGE, total);
            var info = document.getElementById('paginationInfo');
            if (info) info.textContent = total === 0 ? 'No records found' : 'Showing ' + from + '–' + to + ' of ' + total + ' records';

            var btns = document.getElementById('paginationBtns');
            if (!btns) return;
            btns.innerHTML = '';
            if (pages <= 1) return;

            function mkBtn(label, p, active, disabled) {
                var button = document.createElement('button');
                button.type = 'button';
                button.textContent = label;
                button.className = 'pg-btn' + (active ? ' active' : '');
                button.disabled = disabled;
                button.addEventListener('click', function () { renderPage(p); });
                btns.appendChild(button);
            }

            mkBtn('«', 1, false, currentPage === 1);
            mkBtn('‹', currentPage - 1, false, currentPage === 1);
            for (var p = 1; p <= pages; p++) mkBtn(p, p, p === currentPage, false);
            mkBtn('›', currentPage + 1, false, currentPage === pages);
            mkBtn('»', pages, false, currentPage === pages);
        }

        function updateBulkApprove() {
            var checked = document.querySelectorAll('.row-checkbox:checked').length;
            var btn = document.getElementById('forapproved');
            if (checked > 0) {
                btn.classList.add('enabled');
                btn.disabled = false;
            } else {
                btn.classList.remove('enabled');
                btn.disabled = true;
            }
        }

        function openOverlay(id) {
            var el = document.getElementById(id);
            if (el) { el.classList.add('active'); document.body.style.overflow = 'hidden'; }
        }

        function closeOverlay(id) {
            var el = document.getElementById(id);
            if (el) el.classList.remove('active');
            if (!document.querySelector('.modal-overlay.active')) document.body.style.overflow = '';
        }

        document.getElementById('selectAllCheckbox').addEventListener('change', function () {
            var checkboxes = document.querySelectorAll('.row-checkbox');
            checkboxes.forEach(function (cb) {
                cb.checked = this.checked;
                var row = cb.closest('.table-row');
                if (this.checked) row.classList.add('selected-row');
                else row.classList.remove('selected-row');
            }, this);
            updateBulkApprove();
        });

        document.querySelectorAll('.row-checkbox').forEach(function (cb) {
            cb.addEventListener('change', function () {
                var all = document.querySelectorAll('.row-checkbox');
                var chkd = document.querySelectorAll('.row-checkbox:checked');
                document.getElementById('selectAllCheckbox').checked = (all.length === chkd.length);
                var row = this.closest('.table-row');
                if (this.checked) row.classList.add('selected-row');
                else row.classList.remove('selected-row');
                updateBulkApprove();
            });
        });

        function handleRowClick(event, row, referenceNumber) {
            if (event.target.classList.contains('row-checkbox') ||
                (event.target.closest && event.target.closest('td') === row.querySelector('td:first-child'))) {
                return;
            }
            openModal(referenceNumber);
        }

        function openModal(referenceNumber) {
            var rowData = Array.from(tableRows).find(function (row) {
                return row.querySelector('td:nth-child(3)').textContent.trim() === referenceNumber.trim();
            });
            if (!rowData) return;

            var td = function (n) { return rowData.querySelector('td:nth-child(' + n + ')').textContent.trim(); };

            var date = td(2), refNum = td(3), partnerName = td(4), partnerTIN = td(5), serviceCharge = td(8),
                fromDate = td(9), toDate = td(10), numTxn = td(12), amount = td(13), vatAmount = td(14),
                netOfVAT = td(15), wtax = td(16), netAmtDue = td(17), addAmount = rowData.dataset.addAmount || '', formula = rowData.dataset.formula || '';

            document.getElementById('fv-date').textContent = date;
            document.getElementById('fv-reference').textContent = refNum;
            document.getElementById('fv-partnerName').textContent = partnerName;
            document.getElementById('fv-tin').textContent = partnerTIN;
            document.getElementById('fv-serviceCharge').textContent = serviceCharge;
            document.getElementById('fv-fromDate').textContent = fromDate;
            document.getElementById('fv-toDate').textContent = toDate;
            document.getElementById('fv-numTxn').textContent = numTxn;
            document.getElementById('fv-amount').textContent = amount;
            document.getElementById('fv-addAmountVal').textContent = addAmount;
            document.getElementById('fv-vat').textContent = vatAmount;
            document.getElementById('fv-netVat').textContent = netOfVAT;
            document.getElementById('fv-wtax').textContent = wtax;
            document.getElementById('fv-netAmtDue').textContent = (formula === 'NON-VAT') ? amount : netAmtDue;

            document.getElementById('reference').value = refNum;
            document.getElementById('addAmountInp').value = addAmount;
            document.getElementById('amount-modal').value = amount;
            document.getElementById('vatAmount').value = vatAmount;
            document.getElementById('netOfVAT').value = netOfVAT;
            document.getElementById('withholdingTax').value = wtax;
            document.getElementById('netAmountDue').value = (formula === 'NON-VAT') ? amount : netAmtDue;

            var showAdd = (partnerTIN === '005-519-158-000');
            document.getElementById('addAmountDue-div').style.display = showAdd ? 'flex' : 'none';

            openOverlay('confirmModal');
        }

        function closeApprovalModal() {
            closeOverlay('confirmModal');
        }

        document.getElementById('confirmModal').addEventListener('click', function (e) {
            if (e.target === this) closeApprovalModal();
        });

        document.getElementById('triggerApproveConfirm').addEventListener('click', function () {
            closeOverlay('confirmModal');
            openOverlay('approveConfirmModal');
        });

        document.getElementById('approveConfirmModal').addEventListener('click', function (e) {
            if (e.target === this) closeConfirmModal('approveConfirmModal');
        });

        document.getElementById('confirmApproveYes').addEventListener('click', function () {
            var refVal = document.getElementById('reference').value;
            var hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = 'confirmBtn';
            hiddenInput.value = refVal;
            var form = document.querySelector('form');
            form.appendChild(hiddenInput);
            form.submit();
        });

        function closeConfirmModal(id) {
            closeOverlay(id);
            if (id === 'approveConfirmModal') openOverlay('confirmModal');
        }

        document.getElementById('cancelled-one').addEventListener('click', function () {
            closeOverlay('confirmModal');
            openOverlay('cancellationModal');
        });

        document.getElementById('cancellationModal').addEventListener('click', function (e) {
            if (e.target === this) closeCancelModal('cancellationModal');
        });

        document.getElementById('forcancelled').addEventListener('click', function () {
            var selected = document.querySelectorAll('.row-checkbox:checked');
            if (selected.length === 0) {
                openMsgModal('Please select at least one transaction to cancel.', true);
                return;
            }
            openOverlay('multipleCancellationModal');
        });

        document.getElementById('multipleCancellationModal').addEventListener('click', function (e) {
            if (e.target === this) closeCancelModal('multipleCancellationModal');
        });

        function closeCancelModal(id) {
            closeOverlay(id);
        }

        document.getElementById('cancellationReason').addEventListener('input', function () {
            document.getElementById('charCount1').textContent = this.value.length;
        });
        document.getElementById('multipleCancellationReason').addEventListener('input', function () {
            document.getElementById('charCount2').textContent = this.value.length;
        });

        document.getElementById('cancelConfirmBtn').addEventListener('click', function (e) {
            var ta = document.getElementById('cancellationReason');
            if (!ta.value.trim()) {
                ta.classList.add('error');
                ta.focus();
                e.preventDefault();
            }
        });

        document.getElementById('multipleCancelConfirmBtn').addEventListener('click', function (e) {
            var ta = document.getElementById('multipleCancellationReason');
            if (!ta.value.trim()) {
                ta.classList.add('error');
                ta.focus();
                e.preventDefault();
            }
        });

        function openMsgModal(msg, isErr) {
            var modal = document.getElementById('messageModal');
            var span = document.getElementById('modalMessage');
            span.innerHTML = isErr
                ? '<div class="icon-container"><div class="err-icon">&#10060;</div></div>' + msg
                : '<div class="icon-container"><div class="icon">&#10003;</div></div>' + msg;
            modal.classList.add('active');
        }

        document.getElementById('msgCloseBtn').addEventListener('click', function () {
            document.getElementById('messageModal').classList.remove('active');
            // navigate to the same page (replace history) to avoid re-submitting POST
            try { location.replace(window.location.pathname); } catch (err) { console.warn('Navigation failed', err); }
        });

        document.getElementById('messageModal').addEventListener('click', function (e) {
            if (e.target === this) {
                this.classList.remove('active');
                try { location.replace(window.location.pathname); } catch (err) { console.warn('Navigation failed', err); }
            }
        });

        document.addEventListener('DOMContentLoaded', function () {
            renderPage(1);
            updateBulkApprove();
        });
    </script>
</body>
<?php include '../../../templates/footer.php'; ?>
<?php include '../no-signature-modal.php'; ?>
</html>