<?php

session_start();

if (!isset($_SESSION['admin_name'])) {
   header('location:../login_form.php');
}

require_once __DIR__ . '/../config/config.php';

// Handle registration form submission
if (isset($_POST['submit'])) {
    // Start transaction
    $conn->begin_transaction();

    try {
        // Non-array type
        $partnerID = !empty($_POST['partnerID']) ? mysqli_real_escape_string($conn, $_POST['partnerID']) : null;
        $partner_name = !empty($_POST['partner-name']) ? mysqli_real_escape_string($conn, $_POST['partner-name']) : null;
        $partner_tin = !empty($_POST['partner-tin']) ? mysqli_real_escape_string($conn, $_POST['partner-tin']) : null;
        $address = !empty($_POST['address']) ? mysqli_real_escape_string($conn, $_POST['address']) : null;
        $abbreviation = !empty($_POST['abbreviation']) ? mysqli_real_escape_string($conn, $_POST['abbreviation']) : null;
        $business_style = !empty($_POST['business-style']) ? mysqli_real_escape_string($conn, $_POST['business-style']) : null;
        $charge_to = !empty($_POST['charge-to']) ? mysqli_real_escape_string($conn, $_POST['charge-to']) : null;
        $charge = !empty($_POST['charge']) ? mysqli_real_escape_string($conn, $_POST['charge']) : null;
        $settlement = !empty($_POST['settlement']) ? mysqli_real_escape_string($conn, $_POST['settlement']) : null;
        $with_held = !empty($_POST['with-held']) ? mysqli_real_escape_string($conn, $_POST['with-held']) : null;
        $option = !empty($_POST['option']) ? mysqli_real_escape_string($conn, $_POST['option']) : null;
        $series_num = !empty($_POST['series-num']) ? mysqli_real_escape_string($conn, $_POST['series-num']) : 0;
        $partners_acc_name = !empty($_POST['partners-acc-name']) ? mysqli_real_escape_string($conn, $_POST['partners-acc-name']) : null;
        $payment_option = !empty($_POST['payment_option']) ? mysqli_real_escape_string($conn, $_POST['payment_option']) : null;
        $transaction_type = !empty($_POST['transaction_type']) ? mysqli_real_escape_string($conn, $_POST['transaction_type']) : null;
        $partnerStatus = 'ACTIVE';

        $charge_amount = !empty($_POST['charge_amount']) ? mysqli_real_escape_string($conn, $_POST['charge_amount']) : '';
        //array type
        $min_amount = $_POST['min_amount'];
        $max_amount = $_POST['max_amount'];
        $range_amount = $_POST['range_amount'];
        $bank_acc_nums = $_POST['bank_acc_num'];
        $banks = $_POST['bank'];
        $account_types = isset($_POST['account_type']) ? intval($_POST['account_type']) : null;
        
        // Prepare and execute insert into partner_masterfile
        $insert = "INSERT INTO mldb.partner_masterfile (partner_id, partner_name, inc_exc, withheld, partnerTin, address, businessStyle, abbreviation, 
                    series_number, partner_accName, settled_online_check, charge_to, serviceCharge, payment_option, transaction_range, status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($insert);
        $stmt->bind_param('ssssssssisssssss', $partnerID, $partner_name, $option, $with_held, $partner_tin, $address, $business_style, 
                $abbreviation, $series_num, $partners_acc_name, $settlement, $charge_to, $charge, $payment_option, $transaction_type, $partnerStatus);
        if ($stmt === false) {
            throw new Exception('Prepare failed: ' . $conn->error);
        }
        $stmt->execute();

        // Prepare statement for partner_bank
        $stmt1 = $conn->prepare("INSERT INTO mldb.partner_bank (partner_id, bank_accNumber, bank, account_type) VALUES (?, ?, ?, ?)");
        $stmt1->bind_param("ssss", $bank_id, $account_number, $bank_name, $type);
        if ($stmt1 === false) {
            throw new Exception('Prepare failed: ' . $conn->error);
        }
        //Loop through the arrays and insert each record for partners bank
        for ($i = 0; $i < count($bank_acc_nums); $i++) {
            $account_number = mysqli_real_escape_string($conn, $bank_acc_nums[$i]);
            $bank_name = mysqli_real_escape_string($conn, $banks[$i]);
    
            // Set the type to "PRIMARY" only for the selected index
            $type = ($i == $account_types) ? "PRIMARY" : '-';
    
            $bank_id = $partnerID;
    
            if (!empty($account_number) && !empty($bank_name)) {
                $stmt1->execute();
            }
        }

        // Prepare statement for charge_table
        $stmt2 = $conn->prepare("INSERT INTO mldb.charge_table (partner_id, charge_amount, min_amount, max_amount) VALUES (?, ?, ?, ?)");
        $stmt2->bind_param("sdii", $bank_id, $amount, $min, $max);
        if ($stmt2 === false) {
            throw new Exception('Prepare failed: ' . $conn->error);
        }
        if (!empty($charge_amount)) {
            // Handle Per Transaction case
            $amount = $charge_amount;  // No need to escape because of prepared statement
            $min = 0;
            $max = 0;
                        
            $bank_id = $partnerID;
                    
            if (!$stmt2->execute()) {
                $errors[] = 'Error inserting per transaction charge.';
            }
        } else {
            // Handle Range case
            for ($i = 0; $i < count($min_amount); $i++) {
                if (!empty($min_amount[$i]) && !empty($max_amount[$i])) {
                    $amount = $range_amount[$i];
                    $min = $min_amount[$i];
                    $max = $max_amount[$i];
                                    
                    $bank_id = $partnerID;
                                    
                    if (!$stmt2->execute()) {
                        $errors[] = 'Error inserting range charge.';
                    }
                }
            }
        }

        // If all insertions are successful, commit the transaction
        $conn->commit();

        echo '<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>';
        echo "<script>
            window.onload = function() {
                Swal.fire({
                    title: 'Success!',
                    text: 'Successfully Added!',
                    icon: 'success',
                    confirmButtonText: 'Ok',
                }).then((result) => {
                    if (result.value) {
                        window.location.href = 'partnerLog.php';
                    }
                });
            }
        </script>";
    } catch (Exception $e) {
        // Rollback the transaction on error
        $conn->rollback();

        $errors[] = $e->getMessage();
        echo '<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>';
        echo "<script>
            window.onload = function() {
                Swal.fire({
                    title: 'Error!',
                    text: '" . implode("<br>", $errors) . "',
                    icon: 'error',
                    confirmButtonText: 'Ok'
                });
            }
        </script>";
    }
}
// Handle search form submission
if (isset($_POST['search'])) {
    $search = $_POST['search-input'];

    if (!empty($search)) { // Check if search input is not empty
        $query = "SELECT pm.*, 
                    GROUP_CONCAT(DISTINCT pb.bank ORDER BY pb.id SEPARATOR ', ' ) AS all_banks, 
                    GROUP_CONCAT(DISTINCT pb.bank_accNumber ORDER BY pb.id SEPARATOR ', ') AS all_bank_accNumbers, 
                    GROUP_CONCAT(DISTINCT pb.id SEPARATOR ', ') AS all_bank_id,
                    GROUP_CONCAT(DISTINCT pb.account_type ORDER BY pb.id SEPARATOR ', ') AS all_account_type,
                    GROUP_CONCAT(DISTINCT ct.id SEPARATOR ', ') AS all_charge_id,
                    GROUP_CONCAT(DISTINCT ct.charge_amount ORDER BY ct.id SEPARATOR ', ') AS all_charge_amount,
                    GROUP_CONCAT(DISTINCT ct.min_amount ORDER BY ct.id SEPARATOR ', ') AS all_min_amount,
                    GROUP_CONCAT(DISTINCT ct.max_amount ORDER BY ct.id SEPARATOR ', ') AS all_max_amount 
                FROM mldb.partner_masterfile pm
                LEFT JOIN mldb.partner_bank pb ON pm.partner_id = pb.partner_id
                LEFT JOIN mldb.charge_table ct ON pm.partner_id = ct.partner_id
                WHERE pm.partner_id LIKE '%$search%' 
                OR pm.partner_name LIKE '%$search%' 
                OR pm.inc_exc LIKE '%$search%' 
                OR pm.address LIKE '%$search%' 
                OR pm.businessStyle LIKE '%$search%' 
                OR pm.abbreviation LIKE '%$search%' 
                OR pm.partner_accName LIKE '%$search%' 
                OR pm.settled_online_check LIKE '%$search%' 
                OR pm.charge_to LIKE '%$search%' 
                OR pm.serviceCharge LIKE '%$search%' 
                OR pm.status LIKE '%$search%'
                GROUP BY pm.partner_id, pm.id, pm.partner_name, pm.partner_accName, pm.inc_exc, 
                        pm.withheld, pm.partnerTin, pm.address, pm.businessStyle, pm.abbreviation, pm.series_number, 
                        pm.serviceCharge, pm.charge_to, pm.settled_online_check, pm.status;";

        $result = mysqli_query($conn, $query);

        if ($result) {
            $searchResults = mysqli_fetch_all($result, MYSQLI_ASSOC);
        } else {
            $searchResults = array(); // Empty array if there is an error
            echo "Error: " . mysqli_error($conn); // Display the SQL error message
        }
    } else {
        $searchResults = array(); // Empty array if search input is empty
    }
} else {
    $searchResults = array(); // Empty array if no search is performed
}

// Check if the form is submitted
if (isset($_POST['update-partner'])) {

    // Single values (non-array)
    $edit_id = !empty($_POST['edit_id']) ? $_POST['edit_id'] : null;
    $edit_partnerID = !empty($_POST['edit_partnerID']) ? $_POST['edit_partnerID'] : null;
    $edit_partner_name = !empty($_POST['edit_partner_name']) ? $_POST['edit_partner_name'] : null;
    $edit_partner_tin = !empty($_POST['edit_partner_tin']) ? $_POST['edit_partner_tin'] : null;
    $edit_address = !empty($_POST['edit_address']) ? $_POST['edit_address'] : null;
    $edit_abbreviation = !empty($_POST['edit_abbreviation']) ? $_POST['edit_abbreviation'] : null;
    $edit_business_style = !empty($_POST['edit_business_style']) ? $_POST['edit_business_style'] : null;
    $edit_charge_to = !empty($_POST['edit_charge_to']) ? $_POST['edit_charge_to'] : null;
    $edit_charge = !empty($_POST['edit_charge']) ? $_POST['edit_charge'] : null;
    $edit_charge_amount = $_POST['edit_charge_amount'];
    $edit_transaction_type = !empty($_POST['edit_transaction_type']) ? $_POST['edit_transaction_type'] : null;
    $edit_with_held = !empty($_POST['edit_with_held']) ? $_POST['edit_with_held'] : null;
    $edit_option = !empty($_POST['edit_option']) ? $_POST['edit_option'] : null;
    $edit_series_num = $_POST['edit_series_num'];
    $edit_partners_acc_name = !empty($_POST['edit_partners_acc_name']) ? $_POST['edit_partners_acc_name'] : null;
    $account_types = isset($_POST['account_type']) ? $_POST['account_type'] : []; 

    // Array values
    $edit_id1 = $_POST['edit_id1'];
    $edit_bank = $_POST['edit_bank'];
    $edit_bank_acc_num = $_POST['edit_bank_acc_num'];

    // First Update Statement
    $sql = "UPDATE mldb.partner_masterfile 
            SET partner_id = ?, partner_name = ?, inc_exc = ?, withheld = ?, 
                partnerTin = ?, address = ?, businessStyle = ?, abbreviation = ?, 
                series_number = ?, partner_accName = ?, settled_online_check = ?, 
                charge_to = ?, charge_amount = ?, serviceCharge = ?, status = ? 
            WHERE id = ?";

    // Prepare the first statement
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssssssssissssssi", $edit_partnerID, $edit_partner_name, $edit_option, 
                                 $edit_with_held, $edit_partner_tin, $edit_address, 
                                 $edit_business_style, $edit_abbreviation, 
                                 $edit_series_num, $edit_partners_acc_name, 
                                 $edit_transaction_type, $edit_charge_to, $edit_charge_amount,
                                 $edit_charge, $edit_status, $edit_id);

    // Execute the first statement
    if ($stmt->execute()) {
        // Loop through the arrays and update each record in the partner_bank table
        $sql1 = "UPDATE mldb.partner_bank 
                 SET partner_id = ?, bank_accNumber = ?, bank = ? 
                 WHERE id = ?";
        $stmt1 = $conn->prepare($sql1);

        for ($i = 0; $i < count($edit_bank_acc_num); $i++) {
            $current_bank_acc_num = $edit_bank_acc_num[$i];
            $current_bank = $edit_bank[$i];
            $current_id1 = $edit_id1[$i];

            // Only update non-empty fields
            if (!empty($current_bank_acc_num) && !empty($current_bank)) {
                $stmt1->bind_param("sssi", $edit_partnerID, $current_bank_acc_num, $current_bank, $current_id1);
                $stmt1->execute();
            }
        }

        echo '<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>';
        echo "<script>
            window.onload = function() {
                Swal.fire({
                    title: 'Success!',
                    text: 'Updated Successfully',
                    icon: 'success',
                    confirmButtonText: 'Ok',
                }).then((result) => {
                    if (result.value) {
                        window.location.href = 'partnerLog.php';
                    }
                });
            }
        </script>";
    } else {
        echo "Error: " . $conn->error;
    }

    // Close the statements
    $stmt->close();
    $stmt1->close();
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Partners</title>
    <!-- custom CSS file link  -->
    <link rel="stylesheet" href="../assets/css/partnerLog.css?v=<?php echo time(); ?>">
    <link rel="icon" href="../images/MLW logo.png" type="image/png">
    
</head>

<body>
    <div class="container">
        <div class="top-content">
        <div class="usernav">
                    <h4><?php echo $_SESSION['admin_name'] ?></h4>
                    <h5 style="margin-left:5px;"><?php echo "(".$_SESSION['admin_email'].")" ?></h5>
                </div>
            <div class="btn-nav">
                <ul class="nav-list">
                    <li><a href="admin_page.php">HOME</a></li>
                    <li class="dropdown">
                        <button class="dropdown-btn">Import File</button>
                        <div class="dropdown-content">
                        <a id="user" href="billspaymentImportFile.php">BILLSPAYMENT TRANSACTION</a>
                        </div>
                    </li>
                    <li class="dropdown">
                        <button class="dropdown-btn">Transaction</button>
                        <div class="dropdown-content">
                        <a id="user" href="billspaymentSettlement.php">SETTLEMENT</a>
                        </div>
                    </li>
                    <li class="dropdown">
                     <button class="dropdown-btn">Report</button>
                     <div class="dropdown-content">
                        <a id="user" href="billspaymentReport.php">BILLS PAYMENT</a>
                        <a id="user" href="dailyVolume.php">DAILY VOLUME</a>

                     </div>
                    </li>
                    <li class="dropdown">
                        <button class="dropdown-btn">MAINTENANCE</button>
                        <div class="dropdown-content">
                        <a id="user" href="userLog.php">USER</a>
                        <a id="user" href="partnerLog.php">PARTNER</a>
                        <a id="user" href="natureOfBusinessLog.php">NATURE OF BUSINESS</a>
                        <a id="user" href="bankLog.php">BANK</a>
                        </div>
                    </li>
                    <li><a href="../logout.php">LOGOUT</a></li>
                </ul>
            </div>
        </div>
        <div class="s-div">
            <div id="search-div">
                <form method="POST" class="form-group">
                    <div class="left-div">
                        <input type="text" id="search-input" name="search-input" value="<?php if (isset($_POST['search'])) echo $_POST['search']; ?>" placeholder="Search...">
                        <button type="submit" id="search" name="search">Search</button>
                    </div>
                    <div class="right-div">
                        <button type="button" id="add" name="add" onclick="showModal('register-modal')">Add</button>
                        <button type="button" id="edit" name="edit" onclick="showEditModal()">Update</button>
                    </div>
                </form>

                <?php if (!empty($searchResults) && isset($_POST['search']) && !empty($search)) : ?>
                    <div id="search-results">
                        <h3>SEARCH RESULT</h3>
                        <table>
                            <thead>
                                <tr>
                                    <th>Address</th>
                                    <th>Nature of Business</th>
                                    <th>Partner Name</th>
                                    <th>Account Name</th>
                                    <th>Charge To</th>
                                    <th>Transaction Type</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <?php $rowIndex1 = 1; ?>
                            <tbody>
                                <?php foreach ($searchResults as $result) : ?>
                                    <tr onclick="selectRow(this)" ondblclick="displayAllModal('search-results', <?php echo $rowIndex1; ?>)">
                                        <td style="text-align:left; padding-left:10px; display:none;"><?php echo $result['id']; ?></td>
                                        <td style="text-align:left; padding-left:10px; display:none;"><?php echo $result['partner_id']; ?></td>
                                        <td style="text-align:left; padding-left:10px; display:none;"><?php echo $result['inc_exc']; ?></td>
                                        <td style="text-align:left; padding-left:10px; display:none;"><?php echo $result['withheld']; ?></td>
                                        <td style="text-align:left; padding-left:10px; display:none;"><?php echo $result['partnerTin']; ?></td>
                                        <td style="text-align:left; padding-left:10px;"><?php echo $result['address']; ?></td>
                                        <td style="text-align:left; padding-left:10px;"><?php echo $result['businessStyle']; ?></td>
                                        <td style="text-align:left; padding-left:10px; display:none;"><?php echo $result['abbreviation']; ?></td>
                                        <td style="text-align:left; padding-left:10px; display:none;"><?php echo $result['series_number']; ?></td>
                                        <td style="text-align:left; padding-left:10px; display:none;"><?php echo $result['serviceCharge']; ?></td>
                                        <td style="text-align:left; padding-left:10px;"><?php echo $result['partner_name']; ?></td>
                                        <td style="text-align:left; padding-left:10px;"><?php echo $result['partner_accName']; ?></td>
                                        <td style="text-align:left; padding-left:10px; display:none;"><?php echo $result['all_bank_accNumbers']; ?></td>
                                        <td style="text-align:left; padding-left:10px; display:none;"><?php echo $result['all_banks']; ?></td>
                                        <td style="text-align:left; padding-left:10px; "><?php echo $result['charge_to']; ?></td>
                                        <td style="text-align:left; padding-left:10px; "><?php echo $result['settled_online_check']; ?></td>
                                        <td style="text-align:left; padding-left:10px; padding-right:10px; "><?php echo $result['status']; ?></td>
                                        <td style="text-align:left; padding-left:10px; display:none;"><?php echo $result['all_bank_id']; ?></td>
                                        <td style="text-align:left; padding-left:10px; display:none;"><?php echo $result['all_account_type']; ?></td>
                                        <td style="text-align:left; padding-left:10px; display:none;"><?php echo $result['all_charge_id']; ?></td>
                                        <td style="text-align:left; padding-left:10px; display:none;"><?php echo $result['all_charge_amount']; ?></td>
                                        <td style="text-align:left; padding-left:10px; display:none;"><?php echo $result['all_min_amount']; ?></td>
                                        <td style="text-align:left; padding-left:10px; display:none;"><?php echo $result['all_max_amount']; ?></td>
                                        
                                    </tr>
                                    <?php $rowIndex1++; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
                <div id="users-table">
                    <h3>BILLSPAYMENT PARTNERS</h3>
                    <table>
                        <thead>
                            <tr>
                                <th>Partner ID</th>
                                <th>Partner Name</th>
                                <th>Partner Account Name</th>
                                <th>Charge To</th>
                                <th>Settlement Type</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php

                            $query = "SELECT pm.*, 
                                GROUP_CONCAT(DISTINCT pb.bank ORDER BY pb.id SEPARATOR ', ' ) AS all_banks, 
                                GROUP_CONCAT(DISTINCT pb.bank_accNumber ORDER BY pb.id SEPARATOR ', ') AS all_bank_accNumbers, 
                                GROUP_CONCAT(DISTINCT pb.id SEPARATOR ', ') AS all_bank_id,
                                GROUP_CONCAT(DISTINCT pb.account_type ORDER BY pb.id SEPARATOR ', ') AS all_account_type,
                                GROUP_CONCAT(DISTINCT ct.id SEPARATOR ', ') AS all_charge_id,
                                GROUP_CONCAT(DISTINCT ct.charge_amount ORDER BY ct.id SEPARATOR ', ') AS all_charge_amount,
                                GROUP_CONCAT(DISTINCT ct.min_amount ORDER BY ct.id SEPARATOR ', ') AS all_min_amount,
                                GROUP_CONCAT(DISTINCT ct.max_amount ORDER BY ct.id SEPARATOR ', ') AS all_max_amount
                            FROM mldb.partner_masterfile pm
                            LEFT JOIN mldb.partner_bank pb ON pm.partner_id = pb.partner_id
                            LEFT JOIN mldb.charge_table ct ON pm.partner_id = ct.partner_id
                            GROUP BY pm.partner_id, pm.id, pm.partner_name, pm.partner_accName, pm.inc_exc, 
                            pm.withheld, pm.partnerTin, pm.address, pm.businessStyle, pm.abbreviation, pm.series_number, 
                            pm.serviceCharge, pm.charge_to, pm.settled_online_check, pm.status
                            ORDER BY pm.partner_name;";

                            $result = mysqli_query($conn, $query);
                            $rowIndex = 1;
                            if ($result->num_rows > 0) {
                                while ($row = $result->fetch_assoc()) {
                                    ?>
                                    <tr onclick="selectRow(this)" ondblclick="displayAllModal('users-table', <?php echo $rowIndex; ?>)">
                                        <td style="text-align:left; padding-left:10px; display:none;"><?php echo $row['id']; ?></td>
                                        <td style="text-align:left; padding-left:10px;"><?php echo $row['partner_id']; ?></td>
                                        <td style="text-align:left; padding-left:10px; display:none;"><?php echo $row['inc_exc']; ?></td>
                                        <td style="text-align:left; padding-left:10px; display:none;"><?php echo $row['withheld']; ?></td>
                                        <td style="text-align:left; padding-left:10px; display:none;"><?php echo $row['partnerTin']; ?></td>
                                        <td style="text-align:left; padding-left:10px; display:none;"><?php echo $row['address']; ?></td>
                                        <td style="text-align:left; padding-left:10px; display:none;"><?php echo $row['businessStyle']; ?></td>
                                        <td style="text-align:left; padding-left:10px; display:none;"><?php echo $row['abbreviation']; ?></td>
                                        <td style="text-align:left; padding-left:10px; display:none;"><?php echo $row['series_number']; ?></td>
                                        <td style="text-align:left; padding-left:10px; display:none;"><?php echo $row['serviceCharge']; ?></td>
                                        <td style="text-align:left; padding-left:10px;"><?php echo $row['partner_name']; ?></td>
                                        <td style="text-align:left; padding-left:10px;"><?php echo $row['partner_accName']; ?></td>
                                        <td style="text-align:left; padding-left:10px; display:none;"><?php echo $row['all_bank_accNumbers']; ?></td>
                                        <td style="text-align:left; padding-left:10px; display:none;"><?php echo $row['all_banks']; ?></td>
                                        <td style="text-align:left; padding-left:10px; "><?php echo $row['charge_to']; ?></td>
                                        <td style="text-align:left; padding-left:10px; "><?php echo $row['settled_online_check']; ?></td>
                                        <td style="text-align:left; padding-left:10px; padding-right:10px; "><?php echo $row['status']; ?></td>
                                        <td style="text-align:left; padding-left:10px; display:none;"><?php echo $row['all_bank_id']; ?></td>
                                        <td style="text-align:left; padding-left:10px; display:none;"><?php echo $row['all_account_type']; ?></td>
                                        <td style="text-align:left; padding-left:10px; display:none;"><?php echo $row['all_charge_id']; ?></td>
                                        <td style="text-align:left; padding-left:10px; display:none;"><?php echo $row['all_charge_amount']; ?></td>
                                        <td style="text-align:left; padding-left:10px; display:none;"><?php echo $row['all_min_amount']; ?></td>
                                        <td style="text-align:left; padding-left:10px; display:none;"><?php echo $row['all_max_amount']; ?></td>
                                    </tr>
                            <?php
                                     $rowIndex++; // Increment the rowIndex for the next row
                                }
                               
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
         </div>
<!-- Add partner Modal -->
<div id="register-modal" class="modal">
   <div class="register_modal-content">
      <span class="close" onclick="hideModal('register-modal')">&times;</span>
      <form action="" method="post">
         <div class="logo">
            <img src="../images/MLW Logo.png" alt="logo">
         </div>
         <h3>Add Billspayment Partner</h3>
         <div class="inputs-div">
            <div class="inputs">

               <div class="input-container">
               <label for="partnerID">Partner ID</label>
               <input type="text" name="partnerID" id="partnerID" required autocomplete="off">
               </div>

               <div class="input-container">
               <label for="partner-name">Partner Name</label>
               <input type="text" name="partner-name" id="partner-name" required autocomplete="off">
               </div>

               <div class="input-container">
               <label for="partner-tin">Partner TIN</label>
               <input type="text" name="partner-tin" id="partner-tin" autocomplete="off">
               </div>

               <div class="input-container">
               <label for="address">Address</label>
               <input type="text" name="address" id="address" autocomplete="off">
               </div>

               <div class="input-container">
               <label for="abbreviation">Abbreviation</label>
               <input id="abbreviation" type="text" name="abbreviation" autocomplete="off">
               </div>
                
               <?php
                    $nature_of_business_sql = "SELECT * FROM mldb.nature_of_business;";
                    $nature_of_business_result = mysqli_query($conn, $nature_of_business_sql);
                ?>
                <div class="input-container">
                    <label for="business-style">Nature of Business</label>
                    <select name="business-style" id="business-style" required>
                        <option value="" disabled selected></option>
                        <?php
                            
                            while ($nature_of_business_row = mysqli_fetch_assoc($nature_of_business_result)) {
                                
                                ?>
                                <option value="<?php echo $nature_of_business_row['description']; ?>">
                                    <?php echo htmlspecialchars($nature_of_business_row['description']); ?>
                                </option>
                                <?php
                            }
                        ?>
                    </select>
                </div>

                <div class="input-container">
                <label for="charge-to">Charge To</label>
                <select name="charge-to" id="charge-to" required onchange="handleChargeSelection()">
                    <option value="" disabled selected></option>
                    <option value="CUSTOMER">Customer</option>
                    <option value="PARTNER">Partner</option>
                    <option value="CUSTOMER&PARTNER">Customer & Partner</option>
                    <option value="FREEOFCHARGE">Free of Charge</option>
                </select>
                </div>

                <div class="input-container" id="transaction-type-container" style="display: none;">
                <label for="transaction_type">Transaction</label>
                <select id="transaction_type" name="transaction_type" required onchange="handleTransactionType()">
                    <option value="" disabled selected></option>
                    <option value="PER TRANSACTION">Per Transaction</option>
                    <option value="RANGE">Range</option>
                </select>
                </div>

                <!-- Per Transaction input -->
                <div class="input-container" id="per-transaction-container" style="display: none;">
                <label for="charge_amount">Charge Amount</label>
                <input id="charge_amount" type="text" name="charge_amount" autocomplete="off" placeholder="₱0.00">
                </div>

                <!-- Range input -->
                <div id="range-container">
                    <!-- First input group will be static -->
                    <div class="input-group" id="input-group-1" style="display: none;">
                        <div class="input-container">
                        <label for="min_amount_1">FROM</label>
                        <input id="min_amount_1" type="number" name="min_amount[]" autocomplete="off">
                        </div>
                        <div class="input-container">
                        <label for="max_amount_1">TO</label>
                        <input id="max_amount_1" type="number" name="max_amount[]" autocomplete="off">
                        </div>
                        <div class="input-container">
                        <label for="range_amount_1">Charge Amount</label>
                        <input id="range_amount_1" type="number" name="range_amount[]" autocomplete="off" placeholder="₱0.00">
                        </div>
                        <!-- Button to add new input groups -->
                        <button type="button" id="add-more-btn" onclick="addInputGroup()">+</button>
                    </div>
                </div>

                <!-- This div will hold dynamically added input groups -->
                <div id="additional-fields-for-range-input"></div>

                <div class="input-container">
                <label for="payment_option">Payment Option</label>
                <select id="payment_option" name="payment_option" required>
                    <option value="" disabled selected></option>
                    <option value="DEDUCT TO PRINCIPAL">Deduct to Principal</option>
                    <option value="FOR BILLING">For Billing</option>
                </select>
                </div>

               <div class="input-container">
               <label for="charge">Service Charge</label>
               <select name="charge" id="charge" required>
                  <option value="" disabled selected></option>
                  <option value="DAILY">Daily</option>
                  <option value="WEEKLY">Weekly</option>
                  <option value="SEMI-MONTHLY">Semi Monthly</option>
                  <option value="MONTHLY">Monthly</option>
               </select>
               </div>

               <div class="input-container">
               <label for="settlement">Settlement</label>
               <select name="settlement" id="settlement" required>
                  <option value="" disabled selected></option>
                  <option value="ONLINE">Online</option>
                  <option value="CHECK">Check</option>
               </select>
               </div>

               <div class="input-container">
               <label for="with-held">With Held</label>
               <select name="with-held" id="with-held">
                  <option value="" disabled selected></option>
                  <option value="Yes">Yes</option>
                  <option value="No">No</option>
               </select>
               </div>

               <div class="input-container">
               <label for="option">Data Inclusion</label>
               <select name="option" id="option">
                  <option value="" disabled selected></option>
                  <option value="INCLUSIVE">INCLUSIVE</option>
                  <option value="EXCLUSIVE">EXCLUSIVE</option>
               </select>
               </div>
               

            <div class="input-container">
               <label for="series-num">Series Number</label>
               <input type="number" name="series-num" id="series-num" autocomplete="off">
            </div>

            <div class="input-container">
               <label for="partners-acc-name">Partners Accoount Name</label>
               <input type="text" name="partners-acc-name" id="partners-acc-name" autocomplete="off">
            </div>   

            </div>
            
         </div>
         <p>Primary Bank</p>                  
         <div class="bank-div">
                
                <div class="input-container">
                    <input type="radio" name="account_type" value="0" class="account-type-radio" required>
                </div>           
                <div class="input-container">
                    <label for="bank-acc-num">Bank Account Number</label>
                    <input type="text" name="bank_acc_num[]" id="bank-acc-num" autocomplete="off" required>
                </div>
                
                <?php
                    $bank_sql = "SELECT * FROM mldb.bank_table;";
                    $bank_result = mysqli_query($conn, $bank_sql);
                ?>
                <div class="input-container">
                    <label for="bank">Bank</label>
                    <select name="bank[]" id="bank" required>
                        <option value="" disabled selected></option>
                        <?php
                            
                            while ($bank_row = mysqli_fetch_assoc($bank_result)) {
                                
                                ?>
                                <option value="<?php echo $bank_row['bank_name']; ?>">
                                    <?php echo htmlspecialchars($bank_row['bank_name']); ?>
                                </option>
                                <?php
                            }
                        ?>
                    </select>
                </div>

                <button type="button" id="add-partner-button" class="add-button">+</button>
                           
         </div>

         <div id="additional-fields"></div>

         <center><button type="submit" id="register" name="submit" class="form-btn">SAVE PARTNER</button></center>

      </form>
   </div>
</div>
<!-- Edit partner Modal -->
<div id="edit-modal" class="modal">
   <div class="edit_modal-content">
      <span class="close" onclick="hideModal('edit-modal')">&times;</span>
      
      <form method="POST" action="">
         <div class="logo">
            <img src="../images/MLW Logo.png" alt="logo">
         </div>
         <center>
      <h3>Edit Partner</h3>
      </center>
         <div class="inputs-div">
            <div class="inputs">
               
               <div class="input-container">
               <input type="hidden" id="edit_id" name="edit_id">
               </div>

               <div class="input-container">
               <label for="edit_partnerID">Partner ID</label>
               <input type="text" name="edit_partnerID" id="edit_partnerID" autocomplete="off">
               </div>

               <div class="input-container">
               <label for="edit_partner_name">Partner Name</label>
               <input type="text" name="edit_partner_name" id="edit_partner_name" autocomplete="off">
               </div>
                
               <div class="input-container">
               <label for="edit_partner_tin">Partner TIN</label>
               <input type="text" name="edit_partner_tin" id="edit_partner_tin" autocomplete="off">
               </div>
               
               <div class="input-container">
               <label for="edit_address">Address</label>
               <input type="text" name="edit_address" id="edit_address" autocomplete="off">
               </div>

               <div class="input-container">
               <label for="edit_abbreviation">Abbreviation</label>
               <input id="edit_abbreviation" type="text" name="edit_abbreviation" autocomplete="off">
               </div>
                
               <?php
                    $nature_of_business_sql = "SELECT * FROM mldb.nature_of_business;";
                    $nature_of_business_result = mysqli_query($conn, $nature_of_business_sql);
                ?>
               <div class="input-container">
                    <label for="edit_business_style">Nature of Business</label>
                    <select name="edit_business_style" id="edit_business_style">
                        <option value="" disabled selected></option>
                        <?php
                            
                            while ($nature_of_business_row = mysqli_fetch_assoc($nature_of_business_result)) {
                                
                                ?>
                                <option value="<?php echo $nature_of_business_row['description']; ?>">
                                    <?php echo htmlspecialchars($nature_of_business_row['description']); ?>
                                </option>
                                <?php
                            }
                        ?>
                    </select>
                </div>
                
                <div class="input-container">
               <label for="edit_charge_to">Charge To</label>
               <select name="edit_charge_to" id="edit_charge_to" required>
                  <option value="" disabled selected></option>
                  <option value="CUSTOMER">Customer</option>
                  <option value="PARTNER">Partner</option>
                  <option value="CUSTOMER&PARTNER">Customer & Partner</option>
                  <option value="FREEOFCHARGE">Free of Charge</option>
               </select>
               </div>

               <div class="input-container">
               <label for="edit_charge_amount">Charge Amount</label>
               <input id="edit_charge_amount" type="text" name="edit_charge_amount" autocomplete="off" placeholder="1234567890.00">
               </div>

               <div class="input-container">
               <label for="edit_charge">Schedule</label>
               <select name="edit_charge" id="edit_charge">
                  <option value="" disabled selected></option>
                  <option value="DAILY">Daily</option>
                  <option value="WEEKLY">Weekly</option>
                  <option value="SEMI-MONTHLY">Semi Monthly</option>
                  <option value="MONTHLY">Monthly</option>
               </select>
               </div>

               <div class="input-container">
               <label for="edit_transaction_type">Settlement Type</label>
               <select name="edit_transaction_type" id="edit_transaction_type">
                  <option value="" disabled selected></option>
                  <option value="ONLINE">Online</option>
                  <option value="CHECK">Check</option>
               </select>
               </div>

               <div class="input-container">
               <label for="edit_with_held">With Held</label>
               <select name="edit_with_held" id="edit_with_held">
                  <option value="" disabled selected></option>
                  <option value="Yes">Yes</option>
                  <option value="No">No</option>
               </select>
               </div>

               <div class="input-container">
               <label for="edit_option">Data Inclusion</label>
               <select name="edit_option" id="edit_option">
                  <option value="" disabled selected></option>
                  <option value="INCLUSIVE">INCLUSIVE</option>
                  <option value="EXCLUSIVE">EXCLUSIVE</option>
               </select>
               </div>
               

            <div class="input-container">
               <label for="edit_series_num">Transaction Number</label>
               <input type="number" name="edit_series_num" id="edit_series_num" autocomplete="off">
            </div>

            <div class="input-container">
               <label for="edit_partners_acc_name">Partners Bank Account Name</label>
               <input type="text" name="edit_partners_acc_name" id="edit_partners_acc_name" autocomplete="off">
            </div>

            <div class="input-container">
               <label for="edit_status">Status</label>
               <select name="edit_status" id="edit_status">
                  <option value="" disabled selected></option>
                  <option value="ACTIVE">ACTIVE</option>
                  <option value="INACTIVE">INACTIVE</option>
                  <option value="TEMPORARY CLOSED">TEMPORARY CLOSED</option>
                  <option value="CLOSED">CLOSED</option>
               </select>
            </div>
               
            </div>
         </div>

         <div id="edit-additional-fields"></div>
                         
         <center><button type="submit" id="update-partner" name="update-partner">Update Partner</button></center>

      </form>
   </div>
</div>

<!-- Display partner when double click -->
<div id="displayAllModal">
    <div class="displayAllModal-content">
        <span class="close" onclick="hideDisplayAllModal('displayAllModal')">&times;</span>
            <h2>Partner Details</h2> 
            <table style="border: none;">
                <tr>
                    <td class="partnerData"><b>Partner ID</b></td>
                    <td id="displayPartnerID" class="partnerData"></td>
                    
                </tr>
                <tr>
                    <td class="partnerData"><b>Partner Name</b></td>
                    <td id="displayPartnerName" class="partnerData"></td>   
                </tr>
                <tr>
                    <td class="partnerData"><b>Partner TIN</b></td>
                    <td id="displayPartnerTin" class="partnerData"></td>   
                </tr>
                <tr>
                    <td class="partnerData"><b>Address</b></td>
                    <td id="displayPartnerAddress" class="partnerData"></td>   
                </tr>
                <tr>
                    <td class="partnerData"><b>Abbreviation</b></td>
                    <td id="displayPartnerAbbreviation" class="partnerData"></td>   
                </tr>
                <tr>
                    <td class="partnerData"><b>Nature of Business</b></td>
                    <td id="displayPartnerNatureOfBusiness" class="partnerData"></td>   
                </tr>
                <tr>
                    <td class="partnerData"><b>Charge To</b></td>
                    <td id="displayPartnerChargeTo" class="partnerData"></td>   
                </tr>
                <tr>
                    <td class="partnerData"><b>Charge Amount</b></td>
                    <td id="displayPartnerChargeAmount" class="partnerData"></td>   
                </tr>
                <tr>
                    <td class="partnerData"><b>Service Charge</b></td>
                    <td id="displayPartnerServiceCharge" class="partnerData"></td>   
                </tr>
                <tr>
                    <td class="partnerData"><b>Transaction</b></td>
                    <td id="displayPartnerTransaction" class="partnerData"></td>   
                </tr>
                <tr>
                    <td class="partnerData"><b>With Held</b></td>
                    <td id="displayPartnerWithHeld" class="partnerData"></td>   
                </tr>
                <tr>
                    <td class="partnerData"><b>Data Inclusion</b></td>
                    <td id="displayPartnerOption" class="partnerData"></td>   
                </tr>
                <tr>
                    <td class="partnerData"><b>Series Number</b></td>
                    <td id="displayPartnerSeriesNumber" class="partnerData"></td>   
                </tr>
                <tr>
                    <td class="partnerData"><b>Account Name</b></td>
                    <td id="displayPartnersAccountName" class="partnerData"></td>   
                </tr>
                <tr>
                    <td class="partnerData"><b>Status</b></td>
                    <td id="displayPartnerStatus" class="partnerData"></td>   
                </tr>
            </table>
            <center><h2>Bank Details</h2></center>
            <table>
                <thead>
                    <tr>
                        <th>Bank Account Number</th>
                        <th>Bank</th>
                        <th>Account</th>
                    </tr>
                </thead>
                <tbody id="bankTableBodyBankDetails">
                    <!-- Rows will be added here dynamically by JavaScript -->
                </tbody>

            </table>
            <center><h2>Charge Details</h2></center>
            <table>
                <thead>
                    <tr>
                        <th>Amount</th>
                        <th>MIN</th>
                        <th>MAX</th>
                    </tr>
                </thead>
                <tbody id="bankTableBodyChargeDetails">
                    <!-- Rows will be added here dynamically by JavaScript -->
                </tbody>

            </table>
    </div>                    
</div>

<script>

// For the Add Partner Modal to add partner bank
document.getElementById('add-partner-button').addEventListener('click', function () {
    const additionalFields = document.getElementById('additional-fields');

    // Count the number of existing .bank-div elements
    const totalBankDivCount = document.querySelectorAll('.bank-div').length;

    const newFieldSet = document.createElement('div');
    newFieldSet.className = 'bank-div';

    newFieldSet.innerHTML = `
        <div class="input-container">
            <input type="radio" name="account_type" value="${totalBankDivCount}" class="account-type-radio">
        </div>
        <div class="input-container">
            <label for="bank-acc-num">Bank Account Number</label>
            <input type="text" name="bank_acc_num[]" autocomplete="off" required>
        </div>
        <div class="input-container">
            <label for="bank">Bank</label>
            <select name="bank[]" required>
                <option value="" disabled selected></option>
                <?php
                    $bank_sql = "SELECT * FROM mldb.bank_table;";
                    $bank_result = mysqli_query($conn, $bank_sql);
                    while ($bank_row = mysqli_fetch_assoc($bank_result)) {
                ?>
                <option value="<?php echo htmlspecialchars($bank_row['bank_name']); ?>">
                    <?php echo htmlspecialchars($bank_row['bank_name']); ?>
                </option>
                <?php
                    }
                ?>
            </select>
        </div>
        <button type="button" class="remove-button">-</button>
    `;

    additionalFields.appendChild(newFieldSet);

    newFieldSet.querySelector('.remove-button').addEventListener('click', function () {
        additionalFields.removeChild(newFieldSet);
    });
});

function showModal(modalId) {
      var modal = document.getElementById(modalId);
      modal.style.display = 'block';
}

function hideModal(modalId) {
      var modal = document.getElementById(modalId);
      modal.style.display = 'none';
}

function selectRow(row) {
      var selectedRow = document.querySelector('.selected');

      if (selectedRow) {
         selectedRow.classList.remove('selected');
         selectedRow.style.backgroundColor = '';
         selectedRow.style.color = '';
      }

      if (selectedRow !== row) {
         row.classList.add('selected');
         row.style.backgroundColor = 'red';
         row.style.color = 'white';
      }
}

function showEditModal() {
    var selectedRow = document.querySelector('#users-table tr.selected');
    var selectedRow2 = document.querySelector('#search-results tr.selected');
    var cells;

    if (selectedRow) {
        cells = selectedRow.getElementsByTagName('td');
    } else if (selectedRow2) {
        cells = selectedRow2.getElementsByTagName('td');
    } else {
        alert('Please select a row to edit.');
        return;
    }

    document.getElementById('edit_id').value = cells[0].innerText;
    document.getElementById('edit_partnerID').value = cells[1].innerText;
    document.getElementById('edit_option').value = cells[2].innerText;
    document.getElementById('edit_with_held').value = cells[3].innerText;
    document.getElementById('edit_partner_tin').value = cells[4].innerText;
    document.getElementById('edit_address').value = cells[5].innerText;
    document.getElementById('edit_business_style').value = cells[6].innerText;
    document.getElementById('edit_abbreviation').value = cells[7].innerText;
    document.getElementById('edit_series_num').value = cells[8].innerText;
    document.getElementById('edit_charge').value = cells[9].innerText;
    document.getElementById('edit_partner_name').value = cells[10].innerText;
    document.getElementById('edit_partners_acc_name').value = cells[11].innerText;

    // Clear existing additional bank fields before appending new ones
    const additionalFields = document.getElementById('edit-additional-fields');
    additionalFields.innerHTML = '';

    // Retrieve and split bank account numbers and banks if they are comma-separated
    var bankAccNums = cells[12].innerText.split(','); // Assuming comma-separated values
    var banks = cells[13].innerText.split(',');
    var ids = cells[17].innerText.split(',');
    var accountTypes = cells[19].innerText.split(',');

    // Function to create new bank field sets
    function createBankFieldSet(id = '', bankAccNum = '', bank = '', accountType = '') {
        const newFieldSet = document.createElement('div');
        newFieldSet.className = 'bank-div';
        newFieldSet.innerHTML = `

            <div class="input-container">
                <input type="hidden" name="edit_id1[]" value="${id.trim()}"> 
            </div>
            <div class="input-container">
                <input type="radio" name="edit_account_type" value="PRIMARY">
            </div>

            <div class="input-container">
                <label for="edit_bank_acc_num">Bank Account Number</label>
                <input type="text" name="edit_bank_acc_num[]" value="${bankAccNum.trim()}" autocomplete="off">
            </div>

            <?php
            $bank_sql = "SELECT * FROM mldb.bank_table;";
            $bank_result = mysqli_query($conn, $bank_sql);
            ?>
            <div class="input-container">
            <label for="edit_bank">Bank</label>
                <select name="edit_bank[]" required>
                    <option value="" disabled selected></option>
                        <?php
                            while ($bank_row = mysqli_fetch_assoc($bank_result)) {
                        ?>
                                <option value="<?php echo $bank_row['bank_name']; ?>">
                                    <?php echo htmlspecialchars($bank_row['bank_name']); ?>
                                </option>
                        <?php
                            }
                        ?>
                </select>
            </div>

            
            <button type="button" class="edit-remove-button">-</button>
            <button type="button" class="edit-add-button">+</button>

        `;

        additionalFields.appendChild(newFieldSet);

        const selectElement = newFieldSet.querySelector('select');
        selectElement.value = bank.trim();

        if (accountType.trim() === 'PRIMARY') {
            const radioButton = newFieldSet.querySelector('input[type="radio"]');
            radioButton.checked = true;
        }

        // Add event listener for remove button
        newFieldSet.querySelector('.edit-remove-button').addEventListener('click', function () {
            additionalFields.removeChild(newFieldSet);
        });

        // Add event listener for add button
        newFieldSet.querySelector('.edit-add-button').addEventListener('click', function () {
            createBankFieldSet();  // Create a new empty field set
        });
    }

    // Loop through bank account numbers and banks to create initial field sets
    for (var i = 0; i < bankAccNums.length; i++) {
        createBankFieldSet(ids[i],bankAccNums[i], banks[i], accountTypes[i]);
    }

    document.getElementById('edit_charge_to').value = cells[14].innerText;
    document.getElementById('edit_transaction_type').value = cells[15].innerText;
    document.getElementById('edit_status').value = cells[16].innerText;
    document.getElementById('edit_charge_amount').value = cells[18].innerText;

    showModal('edit-modal');
}

function displayAllModal(tableId, rowIndex) {
        // Select all rows in the specified table
        const rows = document.querySelectorAll(`#${tableId} tr`);

        // Check if the rowIndex is valid
        if (rowIndex < rows.length) {
            const row = rows[rowIndex];
            const rowData = Array.from(row.cells).map(cell => cell.textContent.trim());

            const partnerID = rowData[1] || ''; 
            const partnerOption = rowData[2] || ''; 
            const partnerWithHeld = rowData[3] || ''; 
            const partnerTin = rowData[4] || ''; 
            const partnerAddress = rowData[5] || ''; 
            const partnerNatureOfBusiness = rowData[6] || ''; 
            const partnerAbbreviation = rowData[7] || ''; 
            const partnerSeriesNumber = rowData[8] || ''; 
            const partnerServiceCharge = rowData[9] || ''; 
            const partnerName = rowData[10] || ''; 
            const partnersAccountName = rowData[11] || ''; 
            const partnerChargeTo = rowData[14] || ''; 
            const partnerTransaction = rowData[15] || ''; 
            const partnerStatus = rowData[16] || '';

            document.querySelector("#displayPartnerID").textContent = partnerID;
            document.querySelector("#displayPartnerOption").textContent = partnerOption;
            document.querySelector("#displayPartnerWithHeld").textContent = partnerWithHeld;
            document.querySelector("#displayPartnerTin").textContent = partnerTin;
            document.querySelector("#displayPartnerAddress").textContent = partnerAddress;
            document.querySelector("#displayPartnerNatureOfBusiness").textContent = partnerNatureOfBusiness;
            document.querySelector("#displayPartnerAbbreviation").textContent = partnerAbbreviation;
            document.querySelector("#displayPartnerSeriesNumber").textContent = partnerSeriesNumber;
            document.querySelector("#displayPartnerServiceCharge").textContent = partnerServiceCharge;
            document.querySelector("#displayPartnerName").textContent = partnerName;
            document.querySelector("#displayPartnersAccountName").textContent = partnersAccountName;
            document.querySelector("#displayPartnerChargeTo").textContent = partnerChargeTo;
            document.querySelector("#displayPartnerTransaction").textContent = partnerTransaction;
            document.querySelector("#displayPartnerStatus").textContent = partnerStatus;

            // Split the strings into arrays
            const allBanks = rowData[12] || ''; 
            const allBankAccNumbers = rowData[13] || '';
            const allBankAccTypes = rowData[18] || '';
            const allChargeAmount = rowData[20] || '';
            const allMinAmount = rowData[21] || ''; 
            const allMaxAmount = rowData[22] || '';

            const bankArray = allBanks.split(', ');
            const bankAccNumberArray = allBankAccNumbers.split(', ');
            const bankAccTypeArray = allBankAccTypes.split(', ');
            const chargeAmountArray = allChargeAmount.split(', ');
            const minAmountArray = allMinAmount.split(', ');
            const maxAmountArray = allMaxAmount.split(', ');

            // Clear existing rows in the bank table and charge table
            const bankTableBody = document.querySelector("#bankTableBodyBankDetails");
            if (bankTableBody) {
                bankTableBody.innerHTML = ''; // Clear the table body

                // Loop through the arrays and create rows
                for (let i = 0; i < bankArray.length; i++) {
                    const newRow = document.createElement('tr');

                    const bankCell = document.createElement('td');
                    bankCell.textContent = bankArray[i];
                    newRow.appendChild(bankCell);

                    const bankAccNumberCell = document.createElement('td');
                    bankAccNumberCell.textContent = bankAccNumberArray[i];
                    newRow.appendChild(bankAccNumberCell);

                    const bankAccTypeCell = document.createElement('td');
                    bankAccTypeCell.textContent = bankAccTypeArray[i];
                    newRow.appendChild(bankAccTypeCell);

                    bankTableBody.appendChild(newRow);
                }
            } else {
                console.error("Bank table body element not found");
            }
            const chargeTableBody = document.querySelector("#bankTableBodyChargeDetails");
            if (chargeTableBody) {
                chargeTableBody.innerHTML = ''; // Clear the table body

                // Loop through the arrays and create rows
                for (let i = 0; i < chargeAmountArray.length; i++) {
                    const newRow = document.createElement('tr');

                    const chargeAmountCell = document.createElement('td');
                    chargeAmountCell.textContent = chargeAmountArray[i];
                    newRow.appendChild(chargeAmountCell);

                    const minAmountCell = document.createElement('td');
                    minAmountCell.textContent = minAmountArray[i];
                    newRow.appendChild(minAmountCell);

                    const maxAmountCell = document.createElement('td');
                    maxAmountCell.textContent = maxAmountArray[i];
                    newRow.appendChild(maxAmountCell);

                    chargeTableBody.appendChild(newRow);
                }
            } else {
                console.error("Charge table body element not found");
            }

            // Display the modal
            const modal = document.querySelector("#displayAllModal");
            if (modal) {
                modal.style.display = "block";
            } else {
                console.error("Modal element not found");
            }
        } else {
            console.error("Invalid row index:", rowIndex);
        }
}

function hideDisplayAllModal(modalId) {
      var modal = document.getElementById(modalId);
      modal.style.display = 'none';
}

function handleChargeSelection() {
    const chargeTo = document.getElementById('charge-to').value;
    const transactionTypeContainer = document.getElementById('transaction-type-container');
    
    if (chargeTo !== 'FREEOFCHARGE' && chargeTo !== '') {
      transactionTypeContainer.style.display = 'block';
    } else {
      transactionTypeContainer.style.display = 'none';
      document.getElementById('per-transaction-container').style.display = 'none';
      document.getElementById('range-container').style.display = 'none';
    }
  }

  function handleTransactionType() {
    const transactionType = document.getElementById('transaction_type').value;
    const perTransactionContainer = document.getElementById('per-transaction-container');
    const rangeContainer = document.getElementById('range-container');
    
    // Select all input groups
    const inputGroups = document.querySelectorAll('.input-group');

    if (transactionType === 'PER TRANSACTION') {
        perTransactionContainer.style.display = 'block';
        rangeContainer.style.display = 'none';

        // Set all input groups to display none
        inputGroups.forEach(group => {
        group.style.display = 'none';
        });
    } else if (transactionType === 'RANGE') {
        perTransactionContainer.style.display = 'none';
        rangeContainer.style.display = 'flex';

        // Set all input groups to display flex
        inputGroups.forEach(group => {
        group.style.display = 'flex';
        });
    } else {
        perTransactionContainer.style.display = 'none';
        rangeContainer.style.display = 'none';

        // Set all input groups to display none
        inputGroups.forEach(group => {
        group.style.display = 'none';
        });
    }
  }

  let inputGroupCount = 1; // Counter to track the number of input groups

  function addInputGroup() {
    inputGroupCount++;
    
    // Create a new div for the input group
    const newInputGroup = document.createElement('div');
    newInputGroup.classList.add('input-group');
    newInputGroup.id = `input-group-${inputGroupCount}`;

    newInputGroup.style.display = 'flex';

    // Add the new inputs (FROM, TO, Charge Amount) with incremented IDs
    newInputGroup.innerHTML = `
        <div class="input-container">
        <label for="min_amount_${inputGroupCount}">FROM</label>
        <input id="min_amount_${inputGroupCount}" type="number" name="min_amount[]" autocomplete="off">
        </div>
        <div class="input-container">
        <label for="max_amount_${inputGroupCount}">TO</label>
        <input id="max_amount_${inputGroupCount}" type="number" name="max_amount[]" autocomplete="off">
        </div>
        <div class="input-container">
        <label for="range_amount_${inputGroupCount}">Charge Amount</label>
        <input id="range_amount_${inputGroupCount}" type="number" name="range_amount[]" autocomplete="off" placeholder="₱0.00">
        </div>
        <button type="button" class="remove-btn" onclick="removeInputGroup(this)">-</button>
    `;

    // Append the new input group to the #additional-fields-for-range-input div
    const additionalFieldsContainer = document.getElementById('additional-fields-for-range-input');
    additionalFieldsContainer.appendChild(newInputGroup);
  }

  function removeInputGroup(button) {
    // Remove the div that contains the input group
    button.parentElement.remove();
  }
</script>
</body>
</html>