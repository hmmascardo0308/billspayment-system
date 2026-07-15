<?php
session_start();
   require_once __DIR__ . '/../config/config.php';

   if (!isset($_SESSION['admin_name'])) {
      header('location:../login_form.php');
   }

// Initialize variables
$reference = "";
$message = "";
$fileData = null;

// Function to sanitize input
function sanitize_input($data) {
    global $conn;
    return mysqli_real_escape_string($conn, trim($data));
}

// Check if search form submitted
if (isset($_POST['search_reference'])) {
    $reference = sanitize_input($_POST['reference']);
    
    // Query to check if reference exists - use the correct column name for reference
    $query = "SELECT * FROM mldb.billspayment_transaction WHERE reference_no = '$reference'";
    $result = mysqli_query($conn, $query);
    
    if (mysqli_num_rows($result) > 0) {
        $fileData = mysqli_fetch_assoc($result);
    } else {
        $message = "No record found with reference number: $reference";
    }
}

// Check if update form submitted
if (isset($_POST['update_file'])) {
    $reference = sanitize_input($_POST['reference']);
    $reason_note = sanitize_input($_POST['reason_note']);
    $payor = sanitize_input($_POST['payor']);
    $address = sanitize_input($_POST['address']);
    $account_no = sanitize_input($_POST['account_no']);
    $account_name = sanitize_input($_POST['account_name']);
    $amount_paid = sanitize_input($_POST['amount_paid']);
    $charge_to_customer = sanitize_input($_POST['charge_to_customer']);
    $charge_to_partner = sanitize_input($_POST['charge_to_partner']);
    $contact_no = sanitize_input($_POST['contact_no']);
    $other_details = sanitize_input($_POST['other_details']);
    $ml_outlet = sanitize_input($_POST['ml_outlet']); // Same field name in form
    $region = sanitize_input($_POST['region']);
    $operator = sanitize_input($_POST['operator']);
    
    // Update query - change ml_outlet to outlet in the database column reference
    $update_query = "UPDATE mldb.billspayment_transaction SET 
        reason_note = '$reason_note',
        payor = '$payor',
        address = '$address',
        account_no = '$account_no',
        account_name = '$account_name',
        amount_paid = '$amount_paid',
        charge_to_customer = '$charge_to_customer',
        charge_to_partner = '$charge_to_partner',
        contact_no = '$contact_no',
        other_details = '$other_details',
        outlet = '$ml_outlet', // Changed column name here
        region = '$region',
        operator = '$operator'
        WHERE ref_no = '$reference'";
    
    if (mysqli_query($conn, $update_query)) {
        $message = "Record updated successfully!";
        
        // Refresh the file data - use the correct column name for reference
        $query = "SELECT * FROM mldb.billspayment_transaction WHERE reference_no = '$reference'";
        $result = mysqli_query($conn, $query);
        $fileData = mysqli_fetch_assoc($result);
    } else {
        $message = "Error updating record: " . mysqli_error($conn);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transaction Verification Record</title>
    <link rel="stylesheet" href="../assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/css/edit_file.css">
</head>
<body>
    <div class="container">
        <h2 class="page-header">Transaction Verification Record</h2>
        
        <?php if (!empty($message)): ?>
            <div class="alert <?php echo strpos($message, "successfully") !== false ? "alert-success" : "alert-danger"; ?> alert-dismissible fade show">
                <?php echo $message; ?>
                <button type="button" class="close" data-dismiss="alert">&times;</button>
            </div>
        <?php endif; ?>
        
        <!-- Search form -->
        <div class="form-wrapper search-section">
            <form method="POST">
                <div class="form-group">
                    <label for="reference">Enter Reference Number:</label>
                    <input type="text" class="form-control" id="reference" name="reference" 
                           value="<?php echo htmlspecialchars($reference); ?>" required>
                </div>
                <button type="submit" name="search_reference" class="btn btn-primary">Search</button>
            </form>
        </div>
        
        <!-- Edit form (displayed only if a record is found) -->
        <?php if ($fileData): ?>
        <div class="form-wrapper edit-section">
            <h4 class="mb-4">Edit Transaction Details</h4>
            <form method="POST">
                <input type="hidden" name="reference" value="<?php echo htmlspecialchars($fileData['reference_no'] ?? $reference); ?>">
                
                <!-- Display reference number (read-only) -->
                <div class="form-group">
                    <label>Reference Number:</label>
                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($fileData['reference_no'] ?? $reference); ?>" readonly>
                </div>
                
                <!-- Editable reason note dropdown -->
                <div class="form-group reason-note-section">
                    <label for="reason_note">REASON NOTE: Not yet settled due to:</label>
                    <select class="form-control" id="reason_note" name="reason_note" required>
                        <option value="">Select a reason</option>
                        <option value="wrong biller" <?php if(isset($fileData['reason_note']) && $fileData['reason_note']=='wrong biller') echo 'selected'; ?>>Wrong biller</option>
                        <option value="no payment" <?php if(isset($fileData['reason_note']) && $fileData['reason_note']=='no payment') echo 'selected'; ?>>No payment</option>
                        <option value="wrong account" <?php if(isset($fileData['reason_note']) && $fileData['reason_note']=='wrong account') echo 'selected'; ?>>Wrong account</option>
                        <option value="wrong amount" <?php if(isset($fileData['reason_note']) && $fileData['reason_note']=='wrong amount') echo 'selected'; ?>>Wrong amount</option>
                    </select>
                </div>
                
                <!-- Editable transaction fields -->
                <div class="row field-row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="payor">Payor:</label>
                            <input type="text" class="form-control" id="payor" name="payor" value="<?php echo htmlspecialchars($fileData['payor'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="address">Address:</label>
                            <input type="text" class="form-control" id="address" name="address" value="<?php echo htmlspecialchars($fileData['address'] ?? ''); ?>">
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="account_no">Account No:</label>
                            <input type="text" class="form-control" id="account_no" name="account_no" value="<?php echo htmlspecialchars($fileData['account_no'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="account_name">Account Name:</label>
                            <input type="text" class="form-control" id="account_name" name="account_name" value="<?php echo htmlspecialchars($fileData['account_name'] ?? ''); ?>">
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label for="amount_paid">Amount Paid:</label>
                            <input type="text" class="form-control" id="amount_paid" name="amount_paid" value="<?php echo htmlspecialchars($fileData['amount_paid'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label for="charge_to_customer">Charge to Customer:</label>
                            <input type="text" class="form-control" id="charge_to_customer" name="charge_to_customer" value="<?php echo htmlspecialchars($fileData['charge_to_customer'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label for="charge_to_partner">Charge to Partner:</label>
                            <input type="text" class="form-control" id="charge_to_partner" name="charge_to_partner" value="<?php echo htmlspecialchars($fileData['charge_to_partner'] ?? ''); ?>">
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="contact_no">Contact No:</label>
                            <input type="text" class="form-control" id="contact_no" name="contact_no" value="<?php echo htmlspecialchars($fileData['contact_no'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="other_details">Other Details:</label>
                            <input type="text" class="form-control" id="other_details" name="other_details" value="<?php echo htmlspecialchars($fileData['other_details'] ?? ''); ?>">
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label for="ml_outlet">ML Outlet:</label>
                            <input type="text" class="form-control editable-field" id="ml_outlet" name="ml_outlet" 
                                   value="<?php echo htmlspecialchars($fileData['outlet'] ?? ''); ?>"
                                   <?php echo empty($fileData['reason_note']) ? 'disabled' : ''; ?>>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label for="region">Region:</label>
                            <input type="text" class="form-control" id="region" name="region" value="<?php echo htmlspecialchars($fileData['region'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label for="operator">Operator:</label>
                            <input type="text" class="form-control" id="operator" name="operator" value="<?php echo htmlspecialchars($fileData['operator'] ?? ''); ?>">
                        </div>
                    </div>
                </div>
                
                <div class="mt-4">
                    <button type="submit" name="update_file" class="btn btn-success">Update Record</button>
                    <a href="dashboard.php" class="btn btn-secondary ml-2">Back to Dashboard</a>
                </div>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <script src="../assets/js/jquery.min.js"></script>
    <script src="../assets/js/bootstrap.min.js"></script>
</body>
</html>