<?php
// Connect to the database
include '../../../config/config.php';
require '../../../vendor/autoload.php';

// Start the session
session_start();
// include shared permission helpers and resolve current user
@include_once __DIR__ . '/../../../templates/middleware.php';

if (!isset($_SESSION['user_type']) || !in_array($_SESSION['user_type'], ['admin', 'user'], true)) {
    header("Location:../../../index.php");
    session_destroy();
    exit();
}

// ensure a user display name exists
if ($_SESSION['user_type'] === 'admin' && !empty($_SESSION['admin_name'])) {
    $_SESSION['user_name'] = $_SESSION['admin_name'];
} elseif (empty($_SESSION['user_name'])) {
    $_SESSION['user_name'] = $_SESSION['user_email'] ?? $_SESSION['admin_email'] ?? '';
}

// require the Invoice Review permission
if (!function_exists('has_permission') || !has_permission('Invoice Review')) {
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


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['confirmBtn'])) {
        $referenceNumber = $_POST['reference'];
        $reviewedBy = $_SESSION['user_name'];
        // try to fetch the actual signature blob for the reviewer
        $reviewerId = function_exists('resolve_user_identifier') ? resolve_user_identifier() : null;
        $reviewedSignatureBlob = null;
        if (!empty($reviewerId)) {
            $sigStmt = $conn->prepare("SELECT signature FROM mldb.user_sig WHERE id_number = ? LIMIT 1");
            if ($sigStmt) {
                $sigStmt->bind_param('s', $reviewerId);
                $sigStmt->execute();
                $sigStmt->bind_result($sig_blob);
                if ($sigStmt->fetch()) {
                    $reviewedSignatureBlob = $sig_blob;
                }
                $sigStmt->close();
            }
        }
        $currentDate = date("m-d-Y"); // Get the current date
        $reviewedFix_signature = 'ELVIE CILLO';
        // store the reviewer id (if available) instead of the large base64 string
        $reviewedSignature = $reviewerId ? $reviewerId : ($reviewedSignatureBlob ? 'data:image/png;base64,' . base64_encode($reviewedSignatureBlob) : 'electronically signed');

        // Retrieve prepared_by value from the database
        $preparedByQuery = "SELECT prepared_by FROM soa_transaction WHERE reference_number = '$referenceNumber'";
        $preparedByResult = mysqli_query($conn, $preparedByQuery);
        $preparedByRow = mysqli_fetch_assoc($preparedByResult);
        $preparedBy = $preparedByRow['prepared_by'];

        // Check if the prepared_by and reviewed_by have the same value
        if ($preparedBy === $_SESSION['user_name']) {
            $errorMessage = "Please assign another person to review.";
            displayModal($errorMessage, true);
        } else {
            $stmt = $conn->prepare("UPDATE soa_transaction SET status = 'Reviewed', reviewed_signature = ?, reviewed_by = ?, reviewedDate_signature = ?, reviewedFix_signature = ? WHERE reference_number = ?");
            if ($stmt) {
                $stmt->bind_param('sssss', $reviewedSignature, $reviewedBy, $currentDate, $reviewedFix_signature, $referenceNumber);
                if ($stmt->execute()) {
                    $successMessage = "Selected row(s) updated to 'Reviewed'.";
                    displayModal($successMessage);
                } else {
                    $errorMessage = "Error updating transaction: " . $stmt->error;
                    displayModal($errorMessage, true);
                }
                $stmt->close();
            } else {
                $errorMessage = "Failed to prepare update statement: " . mysqli_error($conn);
                displayModal($errorMessage, true);
            }
        }
    } elseif (isset($_POST['reviewed'])) {
        // Check if any rows are selected
        if (isset($_POST['selectedRows'])) {
            // Process the selected rows
            $selectedRows = $_POST['selectedRows'];
            $reviewedBy = $_SESSION['user_name'];
            // attempt to get reviewer signature blob (reuse logic)
            $reviewerId = function_exists('resolve_user_identifier') ? resolve_user_identifier() : null;
            $reviewedSignatureBlob = null;
            if (!empty($reviewerId)) {
                $sigStmt = $conn->prepare("SELECT signature FROM mldb.user_sig WHERE id_number = ? LIMIT 1");
                if ($sigStmt) {
                    $sigStmt->bind_param('s', $reviewerId);
                    $sigStmt->execute();
                    $sigStmt->bind_result($sig_blob);
                    if ($sigStmt->fetch()) {
                        $reviewedSignatureBlob = $sig_blob;
                    }
                    $sigStmt->close();
                }
            }
            $currentDate = date("m-d-Y"); // Get the current date
            $reviewedFix_signature = 'ELVIE CILLO';
            // store reviewer id when possible to avoid oversized data in the signature column
            $reviewedSignature = $reviewerId ? $reviewerId : ($reviewedSignatureBlob ? 'data:image/png;base64,' . base64_encode($reviewedSignatureBlob) : 'electronically signed');

            // Check if the prepared_by and reviewed_by have the same value for any selected row
            $selectQuery = "SELECT prepared_by FROM soa_transaction WHERE reference_number IN ('" . implode("','", $selectedRows) . "') AND prepared_by = '$reviewedBy'";
            $result = mysqli_query($conn, $selectQuery);
            if ($result && mysqli_num_rows($result) > 0) {
                $errorMessage = "Please assign another person to review.";
                displayModal($errorMessage, true);
            } else {
                // Update the status of selected rows to "Reviewed"
                // update each selected row using prepared statement to safely store signature
                $stmt = $conn->prepare("UPDATE soa_transaction SET status = 'Reviewed', reviewed_signature = ?, reviewed_by = ?, reviewedDate_signature = ?, reviewedFix_signature = ? WHERE reference_number = ?");
                if ($stmt) {
                    $failed = false;
                    foreach ($selectedRows as $ref) {
                        $stmt->bind_param('sssss', $reviewedSignature, $reviewedBy, $currentDate, $reviewedFix_signature, $ref);
                        if (!$stmt->execute()) {
                            $failed = true;
                            break;
                        }
                    }
                    $stmt->close();
                    if (!$failed) {
                        $successMessage = "Selected row(s) updated to 'Reviewed'.";
                        displayModal($successMessage);
                    } else {
                        $errorMessage = "Error updating selected row(s).";
                        displayModal($errorMessage, true);
                    }
                } else {
                    $errorMessage = "Failed to prepare update statement: " . mysqli_error($conn);
                    displayModal($errorMessage, true);
                }
            }
        }
    } elseif (isset($_POST['multipleCancelConfirmBtn']) && !empty($_POST['cancelledBy'])) {
        // Check if any rows are selected
        if (isset($_POST['selectedRows'])) {
            // Process the selected rows
            $selectedRows = $_POST['selectedRows'];
            $cancelledBy = $_POST['cancelledBy'];
            $reasonOf_cancellation = $_POST['cancellationReason'];
            $cancelled_date = $_POST['cancel_date'];

            // Update the status of selected rows to "Cancelled" and set cancelled_by value
            $updateQuery = "UPDATE soa_transaction SET status = 'Cancelled', reasonOf_cancellation = '$reasonOf_cancellation', cancelled_by = '$cancelledBy' , cancelled_date = '$cancelled_date' WHERE reference_number IN ('" . implode("','", $selectedRows) . "')";
            if (mysqli_query($conn, $updateQuery)) {
                $successMessage = "Selected row(s) updated to 'Cancelled' \<br>'";
                $successMessage .= " Cancelled by: " . $cancelledBy;
                displayModal($successMessage);
            } else {
                $errorMessage = "Error updating selected row(s): " . mysqli_error($conn);
                displayModal($errorMessage, true);
            }
        }
    } elseif (isset($_POST['cancelConfirmBtn']) && !empty($_POST['cancelledBy'])) {
        $referenceNumber = $_POST['reference'];
        $cancelledBy = $_POST['cancelledBy'];
        $reasonOf_cancellation = $_POST['cancellationReason'];
        $cancelled_date = $_POST['cancel_date'];

        // Update the status of the selected row to "Cancelled" and set cancelled_by value
        $updateQuery = "UPDATE soa_transaction SET status = 'Cancelled', reasonOf_cancellation = '$reasonOf_cancellation', cancelled_by = '$cancelledBy', cancelled_date = '$cancelled_date' WHERE reference_number = '$referenceNumber'";
        if (mysqli_query($conn, $updateQuery)) {
            $successMessage = "Selected row(s) updated to 'Cancelled Status'\<br>";
            $successMessage .= " Cancelled by: " . $cancelledBy;
            displayModal($successMessage);
        } else {
            $errorMessage = "Error updating selected row(s): " . mysqli_error($conn);
            displayModal($errorMessage, true);
        }
    }
}

// Retrieve the updated data after the modifications
$query = "SELECT * FROM soa_transaction";
$result = mysqli_query($conn, $query);

if (isset($_POST['EditConfirmBtn'])) {
    // Retrieve the updated values from the form
    $referenceNumber = $_POST['reference'];
    $transactionFromDate = $_POST['transactionFromDate'];
    $transactionToDate = $_POST['transactionToDate'];
    $numOfTransaction = $_POST['numTransactions'];
    $amount = $_POST['amount-modal'];
    $addAmount = $_POST['e-addAmount'];
    $vatAmount = $_POST['e-vatAmount'];
    $netOfVat = $_POST['e-netOfVAT'];
    $wtax = $_POST['e-withholdingTax'];
    $netAmountDue = $_POST['e-netAmountDue'];

    // Prepare and execute the SQL update statement
    $sql = "UPDATE soa_transaction SET ";
    $updates = [];

    if (!empty($transactionFromDate)) {
        $updates[] = "from_date = '$transactionFromDate'";
    }

    if (!empty($transactionToDate)) {
        $updates[] = "to_date = '$transactionToDate'";
    }

    if (!empty($numOfTransaction)) {
        $updates[] = "number_of_transactions = '$numOfTransaction'";
    }

    if (!empty($amount)) {
        $updates[] = "amount = '$amount'";
    }

    if (!empty($addAmount)) {
        $updates[] = "add_amount = '$addAmount'";
    }

    if (!empty($vatAmount)) {
        $updates[] = "vat_amount = '$vatAmount'";
    }

    if (!empty($netOfVat)) {
        $updates[] = "net_of_vat = '$netOfVat'";
    }

    if (!empty($wtax)) {
        $updates[] = "withholding_tax = '$wtax'";
    }

    if (!empty($netAmountDue)) {
        $updates[] = "net_amount_due = '$netAmountDue'";
    }

    // Check if any field was edited
    if (count($updates) === 0) {
        displayModal("No field was edited.", true);
        exit; // Exit early to prevent executing the update statement
    }

    $sql .= implode(", ", $updates);
    $sql .= " WHERE reference_number = '$referenceNumber'";

    $stmt = $conn->prepare($sql);
    $stmt->execute();

    // Check if the update was successful
    if ($stmt->affected_rows > 0) {
        // Redirect or display a success message
        $successMessage = "Selected row(s) Successfully Updated";
        displayModal($successMessage);
    } else {
        // Handle the update failure
        $errorMessage = "Failed to update the record." . mysqli_error($conn);
        displayModal($errorMessage, true);
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>For Checking / Review | <?php if ($_SESSION['user_type'] === 'admin' || $_SESSION['user_type'] === 'user') echo ucfirst($_SESSION['user_type']);
                                    else echo "Guest"; ?></title>
    <!-- custom CSS file link  -->
    <link rel="stylesheet" href="../../../assets/css/templates/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../../../assets/css/user_review.css?v=<?php echo time(); ?>">
    <script src="https://kit.fontawesome.com/30b908cc5a.js" crossorigin="anonymous"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="../../../assets/js/sweetalert2.all.min.js"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">

    <link rel="icon" href="../../../images/MLW logo.png" type="image/png">

    <style>
        /* ===== DESIGN TOKENS ===== */
        :root {
            --brand:         #C62828;
            --brand-hover:   #B71C1C;
            --brand-light:   #FFEBEE;
            --brand-dark:    #8E0000;
            --success:       #2E7D32;
            --success-lt:    #E8F5E9;
            --warning:       #E65100;
            --n-50:    #FAFAFA;
            --n-100:   #F5F5F5;
            --n-200:   #EEEEEE;
            --n-300:   #E0E0E0;
            --n-400:   #BDBDBD;
            --n-500:   #9E9E9E;
            --n-600:   #757575;
            --n-700:   #616161;
            --n-800:   #424242;
            --n-900:   #212121;
            --shadow-sm: 0 1px 3px rgba(0,0,0,.10), 0 1px 2px rgba(0,0,0,.06);
            --shadow:    0 4px 6px rgba(0,0,0,.08), 0 2px 4px rgba(0,0,0,.06);
            --shadow-lg: 0 10px 20px rgba(0,0,0,.10), 0 4px 8px rgba(0,0,0,.06);
            --shadow-xl: 0 25px 50px rgba(0,0,0,.15);
            --radius-sm: 6px;
            --radius:    10px;
            --radius-lg: 14px;
            --ease: cubic-bezier(.4,0,.2,1);
            --row-h: 40px;
            --hdr-h: 44px;
        }

        /* ===== MICONS helper ===== */
        .micon,
        .material-icons-round { font-family: 'Material Icons Round'; font-style: normal; font-size: 18px;
                 line-height: 1; vertical-align: middle; }
        .micon-sm  { font-size: 16px; }
        .micon-hdr { font-size: 22px; vertical-align: middle; }

        /* ===== TOOLBAR ===== */
        .tbl-toolbar {
            display: flex; align-items: center; gap: 10px;
            margin-bottom: 14px; flex-wrap: wrap;
        }
        .btn-action {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 8px 18px; border: none; border-radius: var(--radius-sm);
            font-weight: 600; font-size: 13.5px; cursor: pointer;
            transition: background .18s var(--ease), transform .1s var(--ease), box-shadow .18s var(--ease);
            box-shadow: var(--shadow-sm); letter-spacing: .01em;
        }
        .btn-action:hover  { transform: translateY(-1px); box-shadow: var(--shadow); }
        .btn-action:active { transform: translateY(0); }

        /* Bulk Review: disabled/grey until selection */
        #forreviewed {
            background: var(--n-400); color: #fff;
            pointer-events: none; opacity: .7; cursor: not-allowed;
        }
        #forreviewed.enabled {
            background: var(--success); pointer-events: auto; opacity: 1; cursor: pointer;
        }
        #forreviewed.enabled:hover { background: #1b5e20; }

        #forcancelled { background: var(--brand); color: #fff; }
        #forcancelled:hover { background: var(--brand-hover); }

        /* ===== PAGINATION ===== */
        .pagination-bar {
            display: flex; align-items: center; justify-content: space-between;
            margin-top: 12px; flex-wrap: wrap; gap: 8px;
        }
        .pagination-info { font-size: 12.5px; color: var(--n-600); }
        .pagination-btns { display: flex; gap: 4px; }
        .pg-btn {
            display: inline-flex; align-items: center; justify-content: center;
            width: 32px; height: 32px; border: 1px solid var(--n-300);
            border-radius: var(--radius-sm); background: #fff;
            cursor: pointer; font-size: 13px; color: var(--n-700);
            transition: background .14s, border-color .14s, color .14s;
            user-select: none;
        }
        .pg-btn:hover { background: var(--brand-light); border-color: var(--brand); color: var(--brand); }
        .pg-btn.active { background: var(--brand); border-color: var(--brand); color: #fff; font-weight: 700; }
        .pg-btn[disabled] { opacity: .4; pointer-events: none; }

        /* ===== CUSTOM CHECKBOX (grid trick for perfect centring) ===== */
        .cbx {
            appearance: none;
            -webkit-appearance: none;
            display: inline-grid;
            place-items: center;
            width: 16px; height: 16px;
            border: 2px solid var(--n-400);
            border-radius: 3px;
            background: #fff;
            cursor: pointer;
            flex-shrink: 0;
            vertical-align: middle;
            transition: background .12s, border-color .12s;
        }
        .cbx::before {
            content: "";
            width: 9px; height: 9px;
            clip-path: polygon(14% 44%, 0 65%, 50% 100%, 100% 16%, 80% 0%, 43% 62%);
            transform: scale(0);
            background: #fff;
            transition: transform .1s var(--ease);
        }
        .cbx:hover    { border-color: var(--brand); }
        .cbx:checked  { background: var(--brand); border-color: var(--brand); }
        .cbx:checked::before { transform: scale(1); }
        .cbx:focus-visible { outline: 3px solid var(--brand-light); outline-offset: 2px; }

        /* slightly bigger for header */
        .cbx-lg { width: 17px; height: 17px; }
        .cbx-lg::before { width: 10px; height: 10px; }

        .select-all-wrap {
            display: flex; flex-direction: column; align-items: center; gap: 4px;
            cursor: pointer; user-select: none;
        }
        .select-all-label {
            font-size: 9px; font-weight: 700; color: var(--n-600);
            text-transform: uppercase; letter-spacing: .05em; line-height: 1;
        }

        /* ===== TABLE ===== */
        .tbl-scroll-wrapper {
            overflow: auto;
            border: 1px solid var(--n-200);
            border-radius: var(--radius);
            box-shadow: var(--shadow-sm);
            max-height: calc(10 * var(--row-h) + var(--hdr-h) + 16px);
        }
        table.soa-table {
            width: 100%;
            min-width: max-content;
            border-collapse: collapse;
            white-space: nowrap;
            table-layout: auto !important;
        }
        table.soa-table th,
        table.soa-table td {
            width: auto !important;
            max-width: none !important;
            min-width: 0;
            white-space: nowrap !important;
            overflow: visible !important;
        }
        table.soa-table thead th {
            position: sticky; top: 0; z-index: 2;
            background: var(--n-50);
            padding: 0 14px;
            height: var(--hdr-h);
            font-size: 12px; font-weight: 700;
            text-transform: uppercase; letter-spacing: .05em;
            color: var(--n-700);
            border-bottom: 2px solid var(--n-200);
            border-right: 1px solid var(--n-200);
            text-align: center;
            display: table-cell !important;
            visibility: visible !important;
            opacity: 1 !important;
            line-height: 1.2 !important;
            -webkit-text-fill-color: currentColor;
        }
        table.soa-table thead th:last-child { border-right: none; }
        table.soa-table thead th.th-check {
            padding: 0 8px; min-width: 60px; width: 60px;
        }
        table.soa-table tbody tr {
            height: var(--row-h);
            cursor: pointer;
            transition: background .12s;
        }
        table.soa-table tbody tr:hover         { background: var(--brand-light); }
        table.soa-table tbody tr.selected-row  { background: #FFCDD2; }
        table.soa-table tbody tr.selected-row td { color: var(--brand-dark); }
        table.soa-table tbody td {
            padding: 0 14px; font-size: 13px;
            color: var(--n-800);
            border-bottom: 1px solid var(--n-100);
            border-right: 1px solid var(--n-100);
            overflow: visible;
            vertical-align: middle;
            display: table-cell !important;
            visibility: visible !important;
            opacity: 1 !important;
            line-height: 1.25 !important;
            -webkit-text-fill-color: currentColor;
        }
        table.soa-table tbody td:last-child { border-right: none; }
        table.soa-table tbody td.td-check { text-align: center; }
        table.soa-table td.soa-ta-num,
        table.soa-table th.soa-ta-num { text-align: right; }
        table.soa-table td.soa-ta-center,
        table.soa-table th.soa-ta-center { text-align: center; }
        table.soa-table td.soa-ta-left,
        table.soa-table th.soa-ta-left { text-align: left; }

        /* hard override against legacy table skins from included stylesheets */
        .bp-card table.soa-table thead th,
        .bp-card table.soa-table tbody td {
            color: #424242 !important;
            background-clip: padding-box;
            text-indent: 0 !important;
            font-size: inherit !important;
        }

        .col-ref { min-width: 140px; }
        .col-partner { min-width: 250px; }
        .col-tin { min-width: 150px; }
        .col-address { min-width: 260px; }
        .col-business { min-width: 190px; }
        .col-service { min-width: 120px; }
        .col-date { min-width: 120px; }
        .col-po { min-width: 140px; }
        .col-count { min-width: 110px; }
        .col-amount { min-width: 130px; }
        .col-user { min-width: 150px; }

        /* ===== CONTAINER ===== */
        .container-fluid { padding: 12px; margin: 0; }

        /* ===== MODAL OVERLAY ===== */
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

        /* Red header */
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
        .modal-hdr-icon {
            width: 30px; height: 30px; border-radius: 50%;
            background: rgba(255,255,255,.2);
            display: flex; align-items: center; justify-content: center;
            font-size: 16px; flex-shrink: 0;
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

        /* ===== REVIEW MODAL – 2-COLUMN BODY ===== */
        .rv-body {
            display: grid; grid-template-columns: 1fr 1fr;
            gap: 0;
        }
        .rv-col { padding: 18px 22px; }
        .rv-col + .rv-col { border-left: 1px solid var(--n-200); background: var(--n-50); }
        .rv-section-label {
            font-size: 10.5px; font-weight: 700; text-transform: uppercase;
            letter-spacing: .06em; color: var(--brand); margin-bottom: 12px;
            padding-bottom: 6px; border-bottom: 2px solid var(--brand-light);
        }
        .rv-row {
            display: flex; align-items: baseline; gap: 8px;
            margin-bottom: 10px; font-size: 13px;
        }
        .rv-lbl {
            min-width: 110px; font-size: 11px; font-weight: 600;
            color: var(--n-600); text-transform: uppercase; letter-spacing: .03em;
            flex-shrink: 0; padding-top: 1px;
        }
        .rv-val {
            flex: 1; color: var(--n-900); font-weight: 500; word-break: break-word;
        }
        .rv-amt { font-variant-numeric: tabular-nums; }
        hr.rv-hr { border: none; border-top: 1px dashed var(--n-200); margin: 10px 0; }

        .peso-inline {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-variant-numeric: tabular-nums;
        }

        /* ===== MODAL BUTTONS ===== */
        .btn-modal {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 8px 18px; border: none; border-radius: var(--radius-sm);
            font-size: 13px; font-weight: 600; cursor: pointer;
            transition: background .14s var(--ease), transform .1s, box-shadow .14s;
            box-shadow: var(--shadow-sm);
        }
        .btn-modal:hover  { transform: translateY(-1px); box-shadow: var(--shadow); }
        .btn-modal:active { transform: translateY(0); }

        .btn-red     { background: var(--brand); color: #fff; }
        .btn-red:hover     { background: var(--brand-hover); }
        .btn-green   { background: var(--success); color: #fff; }
        .btn-green:hover   { background: #1b5e20; }
        .btn-edit    { background: #1565C0; color: #fff; }
        .btn-edit:hover    { background: #0D47A1; }
        .btn-ghost   { background: var(--n-100); color: var(--n-800); border: 1px solid var(--n-300); }
        .btn-ghost:hover   { background: var(--n-200); }
        .btn-outline-red   { background: #fff; color: var(--brand); border: 1.5px solid var(--brand); }
        .btn-outline-red:hover { background: var(--brand-light); }

        /* ===== EDIT MODAL FIELDS ===== */
        .edit-field-row { display: flex; align-items: center; gap: 10px; margin-bottom: 11px; }
        .edit-field-row label {
            min-width: 130px; font-size: 11px; font-weight: 700;
            color: var(--n-600); text-transform: uppercase; letter-spacing: .03em; flex-shrink: 0;
        }
        .edit-input-wrap { flex: 1; display: flex; align-items: center; gap: 6px; }
        .edit-peso { font-size: 13px; color: var(--n-600); flex-shrink: 0; }
        .edit-inp {
            flex: 1; border: 1.5px solid var(--n-200); border-radius: var(--radius-sm);
            padding: 7px 10px; font-size: 13px; color: var(--n-900);
            background: #fff; outline: none;
            transition: border-color .14s;
        }
        .edit-inp:focus { border-color: var(--brand); box-shadow: 0 0 0 3px var(--brand-light); }
        .edit-inp[readonly] { background: var(--n-50); color: var(--n-600); }
        .field-divider { border: none; border-top: 1px dashed var(--n-200); margin: 12px 0; }

        .edit-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0;
        }
        .edit-col { padding: 18px 22px; }
        .edit-col + .edit-col {
            border-left: 1px solid var(--n-200);
            background: var(--n-50);
        }
        .edit-section-label {
            font-size: 10.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: var(--brand);
            margin-bottom: 12px;
            padding-bottom: 6px;
            border-bottom: 2px solid var(--brand-light);
        }
        .edit-col .edit-field-row label { min-width: 125px; }

        /* ===== CANCEL TEXTAREA ===== */
        .cancel-textarea {
            width: 100%; min-height: 100px;
            border: 1.5px solid var(--n-200); border-radius: var(--radius-sm);
            padding: 10px 12px; font-size: 13.5px; color: var(--n-900);
            resize: vertical; outline: none; font-family: inherit; line-height: 1.5;
            transition: border-color .14s, box-shadow .14s;
            box-sizing: border-box;
        }
        .cancel-textarea:focus { border-color: var(--brand); box-shadow: 0 0 0 3px var(--brand-light); }
        .cancel-textarea.error { border-color: var(--brand); }
        .char-count { font-size: 11px; color: var(--n-500); text-align: right; margin-top: 4px; }

        /* ===== CONFIRM MINI-MODAL ===== */
        .confirm-body { padding: 24px 24px 16px; text-align: center; }
        .confirm-icon-wrap {
            width: 60px; height: 60px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 14px;
        }
        .confirm-body h4 { margin: 0 0 8px; font-size: 17px; font-weight: 700; color: var(--n-900); }
        .confirm-body p  { margin: 0; font-size: 13px; color: var(--n-600); line-height: 1.6; }

        @media (max-width: 960px) {
            .rv-body,
            .edit-grid { grid-template-columns: 1fr; }
            .rv-col + .rv-col,
            .edit-col + .edit-col { border-left: none; border-top: 1px solid var(--n-200); }
        }

        /* ===== MESSAGE MODAL ===== */
        .message-modal {
            display: none; position: fixed; inset: 0;
            background: rgba(10,10,10,.5);
            backdrop-filter: blur(3px);
            z-index: 1100; align-items: center; justify-content: center;
        }
        .message-modal.active { display: flex; }
        .message-modal-content {
            background: #fff; border-radius: var(--radius-lg);
            padding: 30px 36px; text-align: center;
            max-width: 380px; width: 90%;
            box-shadow: var(--shadow-xl);
            animation: mcSlide .2s var(--ease);
        }
        #modalMessage { font-size: 14px; color: var(--n-700); line-height: 1.6; display: block; margin-bottom: 16px; }
        .icon-container { margin-bottom: 10px; }
        .icon     { font-size: 40px; color: var(--success); }
        .err-icon { font-size: 40px; color: var(--brand); }
        .close-button {
            background: var(--brand); color: #fff; border: none;
            padding: 8px 28px; border-radius: var(--radius-sm);
            font-size: 13px; font-weight: 600; cursor: pointer;
            transition: background .14s;
        }
        .close-button:hover { background: var(--brand-hover); }
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
                <i class="fa-solid fa-magnifying-glass-check" aria-hidden="true"></i>
                <div>
                    <h2>SOA Review</h2>
                    <div class="bp-section-sub">List of Transaction(s)</div>
                </div>
            </div>
        </div>
        <form action="" method="POST">
            <div class="bp-card container-fluid mt-3 p-4">
                <div class="tbl-toolbar" style="display:flex;gap:0.5rem;align-items:center;">
                    <button type="submit" id="forreviewed" class="btn-action" name="reviewed" disabled>
                        <span class="micon micon-sm">done_all</span> Bulk Review
                    </button>
                    <button type="button" id="forcancelled" class="btn-action" name="cancelled">
                        <span class="micon micon-sm">block</span> Cancel Selected
                    </button>
                    <input type="text" id="search_input" name="search" class="form-control form-control-sm" placeholder="Search by any field..." style="margin-left:auto;max-width:360px;">
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
                            <?php if ($row['status'] === 'Prepared') { ?>
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
                                    title="Click to review">
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
                <!-- Pagination -->
                <div class="pagination-bar" id="paginationBar">
                    <span class="pagination-info" id="paginationInfo"></span>
                    <div class="pagination-btns" id="paginationBtns"></div>
                </div>
            </div>
    <!-- Review Transaction Modal -->
    <div id="confirmModal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="reviewModalTitle">
        <div class="modal-card modal-card-wide">
            <div class="modal-card-header">
                <h3 id="reviewModalTitle">
                    <span class="material-icons-round micon-hdr" aria-hidden="true">fact_check</span>
                    Review Transaction
                </h3>
                <button type="button" class="modal-close-btn" onclick="closeReviewModal()" aria-label="Close">&times;</button>
            </div>
            <div class="rv-body">
                <input type="hidden" id="cancelledBy" name="cancelledBy" value="<?php echo htmlspecialchars($_SESSION['user_name'] ?? $_SESSION['admin_name'] ?? ''); ?>">
                <!-- LEFT COLUMN: Transaction Details -->
                <div class="rv-col">
                    <div class="rv-section-label">
                        <span class="material-icons-round micon-sm" aria-hidden="true">description</span> Transaction Details
                    </div>
                    <div class="rv-row"><span class="rv-lbl">Date</span><span class="rv-val" id="fv-date"></span></div>
                    <div class="rv-row">
                        <span class="rv-lbl">Reference #</span>
                        <input type="hidden" id="reference" name="reference" value="">
                        <span class="rv-val" id="fv-reference"></span>
                    </div>
                    <div class="rv-row">
                        <span class="rv-lbl">Partner Name</span>
                        <input type="hidden" id="customerName" name="customerName" value="">
                        <span class="rv-val" id="fv-partnerName"></span>
                    </div>
                    <div class="rv-row">
                        <span class="rv-lbl">Partner TIN</span>
                        <input type="hidden" id="customerTIN" name="customerTIN" value="">
                        <span class="rv-val" id="fv-tin"></span>
                    </div>
                    <div class="rv-row">
                        <span class="rv-lbl">Service Charge</span>
                        <input type="hidden" id="serviceCharge" name="serviceCharge" value="">
                        <span class="rv-val" id="fv-serviceCharge"></span>
                    </div>
                    <div class="rv-row">
                        <span class="rv-lbl">From Date</span>
                        <input type="hidden" id="transactionFromDate" name="transactionFromDate" value="">
                        <span class="rv-val" id="fv-fromDate"></span>
                    </div>
                    <div class="rv-row">
                        <span class="rv-lbl">To Date</span>
                        <input type="hidden" id="transactionToDate" name="transactionToDate" value="">
                        <span class="rv-val" id="fv-toDate"></span>
                    </div>
                    <div class="rv-row">
                        <span class="rv-lbl">No. of Transactions</span>
                        <input type="hidden" id="numTransactions" name="numTransactions" value="">
                        <span class="rv-val" id="fv-numTxn"></span>
                    </div>
                </div>
                <!-- RIGHT COLUMN: Financial Summary -->
                <div class="rv-col">
                    <div class="rv-section-label">
                        <span class="material-icons-round micon-sm" aria-hidden="true">payments</span> Financial Summary
                    </div>
                    <div class="rv-row" id="addAmountDue-div" style="display:none;">
                        <span class="rv-lbl">Add Amount</span>
                        <span class="rv-val rv-amt" id="fv-addAmount">
                            <span class="peso-inline"><span>₱</span><span id="fv-addAmountVal"></span></span>
                            <input type="hidden" id="addAmountInp" name="addAmount" value="">
                        </span>
                    </div>
                    <div class="rv-row">
                        <span class="rv-lbl">Amount</span>
                        <span class="rv-val rv-amt">
                            <span class="peso-inline"><span>₱</span><span id="fv-amount"></span></span>
                            <input type="hidden" id="amount-modal" name="amount-modal" value="">
                        </span>
                    </div>
                    <div class="rv-row">
                        <span class="rv-lbl">VAT Amount</span>
                        <span class="rv-val rv-amt">
                            <span class="peso-inline"><span>₱</span><span id="fv-vat"></span></span>
                            <input type="hidden" id="vatAmount" name="vatAmount" value="">
                        </span>
                    </div>
                    <div class="rv-row">
                        <span class="rv-lbl">Net of VAT</span>
                        <span class="rv-val rv-amt">
                            <span class="peso-inline"><span>₱</span><span id="fv-netVat"></span></span>
                            <input type="hidden" id="netOfVAT" name="netOfVAT" value="">
                        </span>
                    </div>
                    <div class="rv-row">
                        <span class="rv-lbl">Withholding Tax</span>
                        <span class="rv-val rv-amt">
                            <span class="peso-inline"><span>₱</span><span id="fv-wtax"></span></span>
                            <input type="hidden" id="withholdingTax" name="withholdingTax" value="">
                        </span>
                    </div>
                    <div class="rv-row">
                        <span class="rv-lbl">Net Amount Due</span>
                        <span class="rv-val rv-amt">
                            <span class="peso-inline"><span>₱</span><span id="fv-netAmtDue"></span></span>
                            <input type="hidden" id="netAmountDue" name="netAmountDue" value="">
                        </span>
                    </div>
                </div>
            </div>
            <div class="modal-card-footer">
                <button type="button" id="triggerReviewConfirm" class="btn-modal btn-green">
                    <span class="micon micon-sm">verified</span> Review
                </button>
                <button type="button" id="edit-one" class="btn-modal btn-edit">
                    <span class="micon micon-sm">edit</span> Edit
                </button>
                <button type="button" id="cancelled-one" class="btn-modal btn-outline-red">
                    <span class="micon micon-sm">block</span> Cancel Txn
                </button>
                <button type="button" class="btn-modal btn-ghost" onclick="closeReviewModal()">
                    Close
                </button>
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
    <!-- Edit Modal -->
    <div id="EditModal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="editModalTitle">
        <div class="modal-card modal-card-wide">
            <div class="modal-card-header">
                <h3 id="editModalTitle">
                    <span class="micon micon-hdr">edit_note</span>
                    Edit Transaction
                </h3>
                <button type="button" class="modal-close-btn" onclick="closeEditModal()" aria-label="Close">&times;</button>
            </div>
            <div class="modal-card-body">
                <input type="hidden" id="formulaInp" name="formula" value="">
                <input type="hidden" id="formula_withheld" name="formula_withheld" value="<?php echo $formula_withheld ?? ''; ?>">

                <div class="edit-grid">
                    <div class="edit-col">
                        <div class="edit-section-label">Transaction Details</div>
                        <div class="edit-field-row">
                            <label>Date</label>
                            <div class="edit-input-wrap">
                                <input class="edit-inp" type="text" id="e-date" name="date" value="" readonly>
                            </div>
                        </div>
                        <div class="edit-field-row">
                            <label>Reference No</label>
                            <div class="edit-input-wrap">
                                <input class="edit-inp" type="text" id="e-reference" name="reference" value="<?php if (isset($_POST['reference_number'])) echo htmlspecialchars($_POST['reference_number']); ?>" readonly>
                            </div>
                        </div>
                        <div class="edit-field-row">
                            <label>Partner Name</label>
                            <div class="edit-input-wrap">
                                <input class="edit-inp" type="text" id="e-customerName" name="customerName" value="<?php if (isset($_POST['partnerName'])) echo htmlspecialchars($_POST['partnerName']); ?>" readonly>
                            </div>
                        </div>
                        <div class="edit-field-row">
                            <label>Partner TIN</label>
                            <div class="edit-input-wrap">
                                <input class="edit-inp" type="text" id="e-customerTIN" name="customerTIN" value="" readonly>
                            </div>
                        </div>
                        <div class="edit-field-row">
                            <label>Service Charge</label>
                            <div class="edit-input-wrap">
                                <input class="edit-inp" type="text" id="e-serviceCharge" name="serviceCharge" value="<?php echo isset($_POST['service_Type']) ? htmlspecialchars($_POST['service_Type']) : ''; ?>" readonly>
                            </div>
                        </div>
                        <div class="edit-field-row">
                            <label>From Date</label>
                            <div class="edit-input-wrap">
                                <input class="edit-inp" type="date" id="e-transactionFromDate" name="transactionFromDate" value="">
                            </div>
                        </div>
                        <div class="edit-field-row">
                            <label>To Date</label>
                            <div class="edit-input-wrap">
                                <input class="edit-inp" type="date" id="e-transactionToDate" name="transactionToDate" value="">
                            </div>
                        </div>
                        <div class="edit-field-row">
                            <label>No. of Transactions</label>
                            <div class="edit-input-wrap">
                                <input class="edit-inp" type="text" id="e-numTransactions" name="numTransactions" value="">
                            </div>
                        </div>
                    </div>

                    <div class="edit-col">
                        <div class="edit-section-label">Financial Summary</div>
                        <div class="edit-field-row">
                            <label>Amount</label>
                            <div class="edit-input-wrap">
                                <span class="edit-peso">₱</span>
                                <input class="edit-inp" type="text" id="e-amount-modal" name="amount-modal" onkeyup="formulaComputation()" value="">
                            </div>
                        </div>
                        <div class="edit-field-row" id="edit_addAmount" style="display:none;">
                            <label>Add Amount</label>
                            <div class="edit-input-wrap">
                                <span class="edit-peso">₱</span>
                                <input class="edit-inp" type="text" id="e-addAmount" name="e-addAmount" value="" readonly>
                            </div>
                        </div>
                        <div class="edit-field-row">
                            <label>VAT Amount</label>
                            <div class="edit-input-wrap">
                                <span class="edit-peso">₱</span>
                                <input class="edit-inp" type="text" id="e-vatAmount" name="e-vatAmount" value="" readonly>
                            </div>
                        </div>
                        <div class="edit-field-row">
                            <label>Net of VAT</label>
                            <div class="edit-input-wrap">
                                <span class="edit-peso">₱</span>
                                <input class="edit-inp" type="text" id="e-netOfVAT" name="e-netOfVAT" value="" readonly>
                            </div>
                        </div>
                        <div class="edit-field-row">
                            <label>Withholding Tax</label>
                            <div class="edit-input-wrap">
                                <span class="edit-peso">₱</span>
                                <input class="edit-inp" type="text" id="e-withholdingTax" name="e-withholdingTax" value="" readonly>
                            </div>
                        </div>
                        <div class="edit-field-row">
                            <label>Net Amount Due</label>
                            <div class="edit-input-wrap">
                                <span class="edit-peso">₱</span>
                                <input class="edit-inp" type="text" id="e-netAmountDue" name="e-netAmountDue" value="" readonly>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-card-footer">
                <button type="button" id="triggerUpdateConfirm" class="btn-modal btn-edit">
                    <span class="micon micon-sm">save</span> Update
                </button>
                <button type="button" class="btn-modal btn-ghost" onclick="closeEditModal()">Cancel</button>
            </div>
        </div>
    </div>
    </form>
    </div>

    <!-- ===== CONFIRMATION: Review ===== -->
    <div id="reviewConfirmModal" class="modal-overlay" role="dialog" aria-modal="true" style="z-index:1060;">
        <div class="modal-card confirm-card">
            <div class="confirm-body">
                <div class="confirm-icon">
                    <span class="material-icons-round" style="color:var(--success);font-size:36px;">verified</span>
                </div>
                <h4>Review This Transaction?</h4>
                <p>This action will mark the transaction as <strong>Reviewed</strong>. It cannot be undone.</p>
            </div>
            <div class="modal-card-footer" style="justify-content:center;gap:12px;">
                <button type="button" id="confirmReviewYes" class="btn-modal btn-green">
                    <span class="micon micon-sm">check_circle</span> Confirm
                </button>
                <button type="button" class="btn-modal btn-ghost" onclick="closeConfirmModal('reviewConfirmModal')">
                    Cancel
                </button>
            </div>
        </div>
    </div>

    <!-- ===== CONFIRMATION: Update ===== -->
    <div id="updateConfirmModal" class="modal-overlay" role="dialog" aria-modal="true" style="z-index:1060;">
        <div class="modal-card confirm-card">
            <div class="confirm-body">
                <div class="confirm-icon">
                    <span class="material-icons-round" style="color:var(--brand);font-size:36px;">save</span>
                </div>
                <h4>Confirm Update?</h4>
                <p>You are about to save changes to this transaction record. Please verify the details before continuing.</p>
            </div>
            <div class="modal-card-footer" style="justify-content:center;gap:12px;">
                <button type="button" id="confirmUpdateYes" class="btn-modal btn-edit">
                    <span class="micon micon-sm">save</span> Confirm
                </button>
                <button type="button" class="btn-modal btn-ghost" onclick="closeConfirmModal('updateConfirmModal')">
                    Cancel
                </button>
            </div>
        </div>
    </div>

    <!-- Message Modal -->
    <div id="messageModal" class="message-modal" role="alertdialog">
        <div class="message-modal-content">
            <span id="modalMessage"></span>
            <button class="close-button" id="msgCloseBtn">CLOSE</button>
        </div>
    </div>
    <script>
        /* ================================================================
           PAGINATION
        ================================================================ */
        var ROWS_PER_PAGE = 10;
        var currentPage   = 1;

        function getAllRows() {
            return Array.from(document.querySelectorAll('#tableBody .table-row'));
        }

        function renderPage(page) {
            var rows  = getAllRows();
            var total = rows.length;
            var pages = Math.max(1, Math.ceil(total / ROWS_PER_PAGE));
            currentPage = Math.min(Math.max(1, page), pages);

            rows.forEach(function (r, i) {
                r.style.display = (i >= (currentPage - 1) * ROWS_PER_PAGE && i < currentPage * ROWS_PER_PAGE) ? '' : 'none';
            });

            var from = total === 0 ? 0 : (currentPage - 1) * ROWS_PER_PAGE + 1;
            var to   = Math.min(currentPage * ROWS_PER_PAGE, total);
            var info = document.getElementById('paginationInfo');
            if (info) info.textContent = total === 0
                ? 'No records found'
                : 'Showing ' + from + '\u2013' + to + ' of ' + total + ' records';

            var btns = document.getElementById('paginationBtns');
            if (!btns) return;
            btns.innerHTML = '';
            if (pages <= 1) return;

            function mkBtn(label, p, active, disabled) {
                var b = document.createElement('button');
                b.type = 'button';
                b.textContent = label;
                b.className = 'pg-btn' + (active ? ' active' : '');
                b.disabled = disabled;
                b.addEventListener('click', function () { renderPage(p); });
                btns.appendChild(b);
            }

            mkBtn('\u00AB', 1, false, currentPage === 1);
            mkBtn('\u2039', currentPage - 1, false, currentPage === 1);
            var printed = {};
            for (var p = 1; p <= pages; p++) {
                var show = (p === 1 || p === pages || Math.abs(p - currentPage) <= 1);
                if (!show) {
                    if (!printed['ellipsis-' + (p < currentPage ? 'L' : 'R')]) {
                        printed['ellipsis-' + (p < currentPage ? 'L' : 'R')] = true;
                        var sep = document.createElement('span');
                        sep.textContent = '\u2026';
                        sep.className = 'pg-sep';
                        btns.appendChild(sep);
                    }
                    continue;
                }
                mkBtn(p, p, p === currentPage, false);
            }
            mkBtn('\u203A', currentPage + 1, false, currentPage === pages);
            mkBtn('\u00BB', pages, false, currentPage === pages);
        }

        /* ================================================================
           BULK REVIEW BUTTON STATE
        ================================================================ */
        function updateBulkReview() {
            var checked = document.querySelectorAll('.row-checkbox:checked').length;
            var btn = document.getElementById('forreviewed');
            if (checked > 0) {
                btn.classList.add('enabled');
                btn.disabled = false;
            } else {
                btn.classList.remove('enabled');
                btn.disabled = true;
            }
        }

        /* ================================================================
           CORE HELPERS
        ================================================================ */
        function openOverlay(id) {
            var el = document.getElementById(id);
            if (el) { el.classList.add('active'); document.body.style.overflow = 'hidden'; }
        }
        function closeOverlay(id) {
            var el = document.getElementById(id);
            if (el) { el.classList.remove('active'); }
            if (!document.querySelector('.modal-overlay.active')) {
                document.body.style.overflow = '';
            }
        }

        /* ================================================================
           SELECT-ALL CHECKBOX
        ================================================================ */
        document.getElementById('selectAllCheckbox').addEventListener('change', function () {
            var checkboxes = document.querySelectorAll('.row-checkbox');
            checkboxes.forEach(function (cb) {
                cb.checked = this.checked;
                var row = cb.closest('.table-row');
                if (this.checked) row.classList.add('selected-row');
                else              row.classList.remove('selected-row');
            }, this);
            updateBulkReview();
        });

        /* keep Select-All in sync when individual checkboxes change */
        document.querySelectorAll('.row-checkbox').forEach(function (cb) {
            cb.addEventListener('change', function () {
                var all  = document.querySelectorAll('.row-checkbox');
                var chkd = document.querySelectorAll('.row-checkbox:checked');
                document.getElementById('selectAllCheckbox').checked = (all.length === chkd.length);
                var row = this.closest('.table-row');
                if (this.checked) row.classList.add('selected-row');
                else              row.classList.remove('selected-row');
                updateBulkReview();
            });
        });

        /* ================================================================
           ROW CLICK → opens Review Modal immediately
        ================================================================ */
        var tableRows = document.getElementsByClassName('table-row');

        function handleRowClick(event, row, referenceNumber) {
            if (event.target.classList.contains('row-checkbox') ||
                (event.target.closest && event.target.closest('td') === row.querySelector('td:first-child'))) {
                return;
            }
            openModal(referenceNumber);
        }

        /* Init on DOM ready */
        document.addEventListener('DOMContentLoaded', function () {
            renderPage(1);
            updateBulkReview();
        });

        function openModal(referenceNumber) {
            var rowData = Array.from(tableRows).find(function (row) {
                return row.querySelector('td:nth-child(3)').textContent.trim() === referenceNumber.trim();
            });
            if (!rowData) return;

            var td = function (n) { return rowData.querySelector('td:nth-child(' + n + ')').textContent.trim(); };

            var date            = td(2);
            var refNum          = td(3);
            var partnerName     = td(4);
            var partnerTIN      = td(5);
            var serviceCharge   = td(8);
            var fromDate        = td(9);
            var toDate          = td(10);
            var numTxn          = td(12);
            var amount          = td(13);
            var vatAmount       = td(14);
            var netOfVAT        = td(15);
            var wtax            = td(16);
            var netAmtDue       = td(17);
            var addAmount       = rowData.dataset.addAmount || '';
            var formula         = rowData.dataset.formula || '';
            var formulaWithheld = rowData.dataset.formulaWithheld || '';

            /* ---- display spans ---- */
            document.getElementById('fv-date').textContent        = date;
            document.getElementById('fv-reference').textContent   = refNum;
            document.getElementById('fv-partnerName').textContent = partnerName;
            document.getElementById('fv-tin').textContent         = partnerTIN;
            document.getElementById('fv-serviceCharge').textContent = serviceCharge;
            document.getElementById('fv-fromDate').textContent    = fromDate;
            document.getElementById('fv-toDate').textContent      = toDate;
            document.getElementById('fv-numTxn').textContent      = numTxn;
            document.getElementById('fv-amount').textContent      = amount;
            document.getElementById('fv-addAmountVal').textContent = addAmount;
            document.getElementById('fv-vat').textContent         = vatAmount;
            document.getElementById('fv-netVat').textContent      = netOfVAT;
            document.getElementById('fv-wtax').textContent        = wtax;
            if (formula === 'NON-VAT') {
                document.getElementById('fv-netAmtDue').textContent = amount;
            } else {
                document.getElementById('fv-netAmtDue').textContent = netAmtDue;
            }

            /* ---- hidden inputs (for form submit) ---- */
            document.getElementById('reference').value          = refNum;
            document.getElementById('customerName').value       = partnerName;
            document.getElementById('customerTIN').value        = partnerTIN;
            document.getElementById('serviceCharge').value      = serviceCharge;
            document.getElementById('transactionFromDate').value = fromDate;
            document.getElementById('transactionToDate').value  = toDate;
            document.getElementById('numTransactions').value    = numTxn;
            document.getElementById('amount-modal').value       = amount;
            document.getElementById('addAmountInp').value       = addAmount;
            document.getElementById('vatAmount').value          = vatAmount;
            document.getElementById('netOfVAT').value           = netOfVAT;
            document.getElementById('withholdingTax').value     = wtax;
            document.getElementById('netAmountDue').value       = (formula === 'NON-VAT') ? amount : netAmtDue;

            /* ---- wire confirmBtn value with the reference number ---- */
            document.getElementById('triggerReviewConfirm').dataset.ref = refNum;

            /* ---- edit modal mirror ---- */
            document.getElementById('e-date').value             = date;
            document.getElementById('e-reference').value        = refNum;
            document.getElementById('e-customerName').value     = partnerName;
            document.getElementById('e-customerTIN').value      = partnerTIN;
            document.getElementById('e-serviceCharge').value    = serviceCharge;
            document.getElementById('e-transactionFromDate').value = fromDate;
            document.getElementById('e-transactionToDate').value   = toDate;
            document.getElementById('e-numTransactions').value  = numTxn;
            document.getElementById('e-amount-modal').value     = amount;
            document.getElementById('e-addAmount').value        = addAmount;
            document.getElementById('e-vatAmount').value        = vatAmount;
            document.getElementById('e-netOfVAT').value         = netOfVAT;
            document.getElementById('e-withholdingTax').value   = wtax;
            document.getElementById('formulaInp').value         = formula;
            document.getElementById('formula_withheld').value   = formulaWithheld;
            if (formula === 'NON-VAT') document.getElementById('e-netAmountDue').value = amount;
            else                        document.getElementById('e-netAmountDue').value = netAmtDue;

            /* ---- add-amount row visibility ---- */
            var showAdd = (partnerTIN === '005-519-158-000');
            document.getElementById('addAmountDue-div').style.display  = showAdd ? 'flex' : 'none';
            document.getElementById('edit_addAmount').style.display     = showAdd ? 'flex' : 'none';

            openOverlay('confirmModal');
        }

        /* ================================================================
           REVIEW MODAL CLOSE
        ================================================================ */
        function closeReviewModal() {
            closeOverlay('confirmModal');
        }

        /* close on backdrop click */
        document.getElementById('confirmModal').addEventListener('click', function (e) {
            if (e.target === this) closeReviewModal();
        });

        /* ================================================================
           REVIEW CONFIRMATION FLOW
        ================================================================ */
        document.getElementById('triggerReviewConfirm').addEventListener('click', function () {
            closeOverlay('confirmModal');
            openOverlay('reviewConfirmModal');
        });

        document.getElementById('reviewConfirmModal').addEventListener('click', function (e) {
            if (e.target === this) closeConfirmModal('reviewConfirmModal');
        });

        document.getElementById('confirmReviewYes').addEventListener('click', function () {
            /* hidden submit: create a temp form and submit with confirmBtn */
            var refVal = document.getElementById('reference').value;
            var btn = document.createElement('input');
            btn.type  = 'hidden';
            btn.name  = 'confirmBtn';
            btn.value = refVal;
            var form = document.querySelector('form');
            form.appendChild(btn);
            form.submit();
        });

        /* ================================================================
           EDIT MODAL  
        ================================================================ */
        document.getElementById('edit-one').addEventListener('click', function () {
            closeOverlay('confirmModal');
            openOverlay('EditModal');
        });

        function closeEditModal() { closeOverlay('EditModal'); }

        document.getElementById('EditModal').addEventListener('click', function (e) {
            if (e.target === this) closeEditModal();
        });

        /* Update Confirmation Flow */
        document.getElementById('triggerUpdateConfirm').addEventListener('click', function () {
            closeOverlay('EditModal');
            openOverlay('updateConfirmModal');
        });

        document.getElementById('updateConfirmModal').addEventListener('click', function (e) {
            if (e.target === this) closeConfirmModal('updateConfirmModal');
        });

        document.getElementById('confirmUpdateYes').addEventListener('click', function () {
            var btn = document.createElement('input');
            btn.type  = 'hidden';
            btn.name  = 'EditConfirmBtn';
            btn.value = '1';
            var form = document.querySelector('form');
            form.appendChild(btn);
            form.submit();
        });

        function closeConfirmModal(id) {
            closeOverlay(id);
            /* re-open parent if needed */
            if (id === 'reviewConfirmModal') openOverlay('confirmModal');
            if (id === 'updateConfirmModal') openOverlay('EditModal');
        }

        /* ================================================================
           CANCELLATION FLOW
        ================================================================ */
        /* Single transaction cancel */
        document.getElementById('cancelled-one').addEventListener('click', function () {
            closeOverlay('confirmModal');
            openOverlay('cancellationModal');
        });

        document.getElementById('cancellationModal').addEventListener('click', function (e) {
            if (e.target === this) closeCancelModal('cancellationModal');
        });

        /* Bulk cancel */
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

        /* Character counters for textareas */
        document.getElementById('cancellationReason').addEventListener('input', function () {
            document.getElementById('charCount1').textContent = this.value.length;
        });
        document.getElementById('multipleCancellationReason').addEventListener('input', function () {
            document.getElementById('charCount2').textContent = this.value.length;
        });

        /* Basic validation before cancel submit */
        document.getElementById('cancelConfirmBtn').addEventListener('click', function (e) {
            var ta = document.getElementById('cancellationReason');
            if (!ta.value.trim()) {
                ta.classList.add('error');
                ta.focus();
                e.preventDefault();
                return false;
            }
            ta.classList.remove('error');
        });
        document.getElementById('multipleCancelConfirmBtn').addEventListener('click', function (e) {
            var ta = document.getElementById('multipleCancellationReason');
            if (!ta.value.trim()) {
                ta.classList.add('error');
                ta.focus();
                e.preventDefault();
                return false;
            }
            ta.classList.remove('error');
        });

        /* ================================================================
           MESSAGE MODAL
        ================================================================ */
        function openMsgModal(msg, isErr) {
            var modal = document.getElementById('messageModal');
            var span  = document.getElementById('modalMessage');
            if (isErr) {
                span.innerHTML = '<div class="icon-container"><div class="err-icon">&#10060;</div></div>' + msg;
            } else {
                span.innerHTML = '<div class="icon-container"><div class="icon">&#10003;</div></div>' + msg;
            }
            modal.classList.add('active');
        }

        document.getElementById('msgCloseBtn').addEventListener('click', function () {
            document.getElementById('messageModal').classList.remove('active');
            try { location.replace(window.location.pathname); } catch (err) { console.warn('Navigation failed', err); }
        });

        document.getElementById('messageModal').addEventListener('click', function (e) {
            if (e.target === this) {
                this.classList.remove('active');
                try { location.replace(window.location.pathname); } catch (err) { console.warn('Navigation failed', err); }
            }
        });

        /* ================================================================
           FORMULA COMPUTATION (Edit Modal)
        ================================================================ */
        function formulaComputation() {
            var amount   = parseFloat(document.getElementById('e-amount-modal').value.replace(/,/g, '')) || 0;
            var formula  = document.getElementById('formulaInp').value;
            var fWithheld = document.getElementById('formula_withheld').value;
            var tin      = document.getElementById('e-customerTIN').value;
            var addAmt   = parseFloat(document.getElementById('e-addAmount').value.replace(/,/g, '')) || 0;

            var vatAmount, netOfVAT, wtax, netAmtDue;

            if (formula === 'INCLUSIVE' && fWithheld === 'No') {
                vatAmount  = amount / 1.12;
                netOfVAT   = amount - vatAmount;
                wtax       = 0;
                netAmtDue  = vatAmount;
            } else if (formula === 'INCLUSIVE') {
                vatAmount  = (amount * 0.12) / 1.12;
                netOfVAT   = amount - vatAmount;
                wtax       = netOfVAT * 0.02;
                netAmtDue  = amount - wtax;
            } else if (formula === 'EXCLUSIVE') {
                vatAmount  = amount * 0.12;
                wtax       = amount * 0.02;
                netOfVAT   = null;
                netAmtDue  = (amount + vatAmount) - wtax;
            } else { // NON-VAT
                vatAmount  = 0; netOfVAT = 0; wtax = 0; netAmtDue = amount;
            }

            if (tin === '005-519-158-000') netAmtDue += addAmt;

            document.getElementById('e-vatAmount').value     = fmt(vatAmount);
            document.getElementById('e-netOfVAT').value      = (netOfVAT !== null) ? fmt(netOfVAT) : '';
            document.getElementById('e-withholdingTax').value = fmt(wtax);
            document.getElementById('e-netAmountDue').value  = fmt(netAmtDue);
        }

        function fmt(n) {
            if (n === null || isNaN(n)) return '';
            return n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        }
    </script>
</body>
<?php include '../../../templates/footer.php'; ?>
<?php include '../no-signature-modal.php'; ?>

</html>