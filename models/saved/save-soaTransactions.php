<?php
// error_reporting(E_ALL);
// $conn = new mysqli('localhost', 'root', 'Password1', 'mldb');
// if ($conn->connect_error) {
//     die("Connection failed: " . $conn->connect_error);
// }
require_once __DIR__ . '/../../config/config.php';

session_start();
 
// Ensure we can resolve current user id
include '../../templates/middleware.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirmBtn'])) {
    $partnerID = $_POST['partnerID'];

    // Update the database by incrementing the series_number for the selected partner_id
    $sql = "UPDATE masterdata.partner_masterfile SET series_number = series_number + 1 WHERE partner_id = '$partnerID'";
    $result = $conn->query($sql);

    if (!$result) {
        $errorMessage = "Error updating data: " . $conn->error;
        error_log($errorMessage);
        redirectToErrorPage($errorMessage);
    }

    // Retrieve the form data
    $date = $_POST['transaction-date'];
    $referenceNumber = $_POST['reference_number'];
    $partnerName = $_POST['partnerName'];
    $partnerTin = $_POST['customerTin'];
    $address = $_POST['address'];
    $businessStyle = $_POST['businessStyle'];
    $serviceCharge = $_POST['serviceCharge'];
    $fromDate = $_POST['fromDate'];
    $toDate = $_POST['toDate'];
    $poNumber = $_POST['poNumber'];
    $numberOfTransactions = $_POST['numberOfTransaction'];
    $amount = $_POST['amount'];
    $addAmount = $_POST['addAmount-amount'];
    $add = $_POST['amount_add'];
    $numOfDays = $_POST['multiplyAmount'];
    $formula = $_POST['formula'];
    if ($formula === 'INCLUSIVE') {
        $formulaInc_Exc = $_POST['formulaIncDisplay'];
    } else if ($formula === 'EXCLUSIVE') {
        $formulaInc_Exc = $_POST['formulaExcDisplay'];
    } else {
        $formulaInc_Exc = $_POST['formulaNonVat'];
    }
    $formula_withheld = $_POST['withheld'];
    if($formula_withheld === 'No'){
        $formulaInc_Exc = $_POST['formulaWithheldDisplay'];
    }
    $vatAmount = $_POST['vat-amount'];
    $netOfVat = $_POST['net-amount'];
    $withholdingTax = $_POST['wtax-amount'];
    $totalAmountDue = $_POST['totalAmount'];
    $netAmountDue = $_POST['netAmount-amount'];
    $preparedBy = $_POST['preparedInput'];
    $preparedDate_signature = $_POST['preparedDateSignature'];
    $cancelledBy = '';
    $reviewedBy = $_POST['reviewdbyInput'];
    $reviewedDate_signature = $_POST['reviewedDateSignature'];
    $notedBy = $_POST['noteInput'];
    $notedDate_signature = $_POST['notedDateSignature'];
    $status = 'Prepared';

    // Use the current user's id as a reference to their stored signature
    $current_user_id = resolve_user_identifier();
    // Store the id_number in prepared_signature so reports can resolve the actual signature image
    $prepared_signature = $current_user_id ?: 'electronically signed';
    $reviewed_signature = '';
    $noted_signature = '';
    $reviewedFix_signature = '';
    $notedFix_signature = '';

    // Prepare the insert statement
    $stmt = $conn->prepare("
        INSERT INTO mldb.soa_transaction (date, reference_number, partner_Name, partner_Tin, address, business_style,
        service_charge, from_date, to_date, po_number, number_of_transactions, amount, amount_add ,add_amount, numberOf_days,
        formula,formula_withheld, formulaInc_Exc, vat_amount, net_of_vat, withholding_tax, totalAmountDue, net_amount_due, prepared_by,
        cancelled_by, reviewed_by, noted_by, preparedDate_signature, prepared_signature, reviewed_signature,
        reviewedDate_signature, noted_signature, notedDate_signature, reviewedFix_signature, notedFix_signature, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    if ($stmt) {
        $stmt->bind_param("ssssssssssssssssssssssssssssssssssss", $date, $referenceNumber, $partnerName, $partnerTin, $address,
            $businessStyle, $serviceCharge, $fromDate, $toDate, $poNumber, $numberOfTransactions, $amount, $add ,$addAmount,
            $numOfDays, $formula,$formula_withheld, $formulaInc_Exc, $vatAmount, $netOfVat, $withholdingTax, $totalAmountDue,
            $netAmountDue, $preparedBy, $cancelledBy, $reviewedBy, $notedBy, $preparedDate_signature,
            $prepared_signature, $reviewed_signature, $reviewedDate_signature, $noted_signature,
            $notedDate_signature, $reviewedFix_signature, $notedFix_signature, $status);

        if ($stmt->execute()) {
            // Insert successful
            $stmt->close(); // Close the statement
            $conn->close(); // Close the connection
            echo '<script>alert("Successfully saved transaction!"); window.location.href = "../../dashboard/billspayment-soa/create/billing-service-charge.php";</script>';
            exit;
        } else {
            // Insert failed
            $errorMessage = "Error executing prepared statement: " . $stmt->error;
            error_log($errorMessage);
            $stmt->close(); // Close the statement
            $conn->close(); // Close the connection
            redirectToErrorPage($errorMessage);
        }
    } else {
        // Prepare statement failed
        $errorMessage = "Error preparing statement: " . $conn->error;
        error_log($errorMessage);
        $conn->close(); // Close the connection
        redirectToErrorPage($errorMessage);
    }
} else {
    $conn->close(); // Close the connection
}

// Function to redirect to an error page with the error message
function redirectToErrorPage($errorMessage)
{
    $encodedErrorMessage = urlencode($errorMessage);
    header("Location: save-soaTransactions.php?error=$encodedErrorMessage");
    exit;
}
?>
