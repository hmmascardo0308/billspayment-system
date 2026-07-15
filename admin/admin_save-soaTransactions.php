<?php
$conn = mysqli_connect('localhost', 'root', 'Password1', 'mldb');
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

session_start();

// Check if the form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirmBtn'])) {
    $partnerID = $_POST['partnerID'];

    // Update the database by incrementing the series_number for the selected partner_id
    $sql = "UPDATE partner_masterfile SET series_number = series_number + 1 WHERE partner_id = '$partnerID'";
    // Execute the SQL query
    mysqli_query($conn, $sql);

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
    $numOfDays = $_POST['multiplyAmount'];
    $formula = $_POST['formula'];
    if ($formula === 'INCLUSIVE') {
        $formulaInc_Exc = $_POST['formulaIncDisplay'];
    } else if($formula === 'EXCLUSIVE'){
        $formulaInc_Exc = $_POST['formulaExcDisplay'];
    }else{
        $formulaInc_Exc = $_POST['formulaNonVat'];
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
    $status = '';

    // Prepare the insert statement
    $stmt = $conn->prepare("INSERT INTO soa_transaction (date, reference_number, partner_Name, partner_Tin, address, business_style, service_charge, from_date, to_date, po_number, number_of_transactions, amount, add_amount, numberOf_days, formula,formulaInc_Exc, vat_amount, net_of_vat, withholding_tax,totalAmountDue, net_amount_due, prepared_by, cancelled_by, reviewed_by, noted_by, prepared_signature, preparedDate_signature, reviewed_signature, reviewedDate_signature, noted_signature,notedDate_signature, reviewedFix_signature,notedFix_signature,  status) VALUES (?,?,?,?,?,?,?,?,?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    if (!$stmt) {
        // The prepare() function failed
        echo "Prepare failed: " . $conn->error;
        exit;
    }

    // Assign the signature value
    $prepared_signature = 'electronically signed';
    $reviewed_signature = '';
    $noted_signature = '';
    $reviewedFix_signature = '';
    $notedFix_signature = '';

    // Bind the parameters to the statement
    $stmt->bind_param('ssssssssssssssssssssssssssssssssss', $date, $referenceNumber, $partnerName, $partnerTin, $address, $businessStyle, $serviceCharge, $fromDate, $toDate, $poNumber, $numberOfTransactions, $amount, $addAmount, $numOfDays, $formula,$formulaInc_Exc, $vatAmount, $netOfVat, $withholdingTax, $totalAmountDue, $netAmountDue, $preparedBy, $cancelledBy, $reviewedBy, $notedBy, $prepared_signature, $preparedDate_signature, $reviewed_signature, $reviewedDate_signature, $noted_signature, $notedDate_signature, $reviewedFix_signature,$notedFix_signature, $status);

   // Execute the insert statement
    if ($stmt->execute()) {
        // Insert successful
        echo "<script>alert('Transaction Successfully Saved!'); window.location.href = 'admin_soa.php';</script>";
        exit;
    } else {
        // Insert failed
        $errorMessage = "Error saving data: " . $stmt->error;
        echo "<script>alert('$errorMessage'); window.location.href = 'admin_soa.php';</script>";
    }

    // Close the statement
    $stmt->close();
}
?>

