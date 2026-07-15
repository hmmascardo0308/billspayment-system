<?php session_start(); 
$conn = mysqli_connect('localhost', 'root', 'Password1','mldb');
if(!isset($_SESSION['user_name'])){
   header('location:login_form.php');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Duplicate Data</title>
    <link rel="stylesheet" href="../../css/billsPayment.css?v=<?php echo time(); ?>">
</head>
<body>
    <?php
        if(isset($_POST['btn_back'])){
            $result = $conn->query("DELETE FROM temp_billsPayment_others");
            header("Location: ../billspayment-menu.php");
            exit();
        }
    ?>
    <div class="btn-back">
        <a href="import-bank-special.php" id="back">Back</a>
    </div>
    
    <div class="btn-addlog">
        <input type="button" id="addtoLog" name="addtoLog" value="Add to Log">
    </div>
    <!-- The modal -->
    <div id="myModal" class="modal">
        <div class="modal-content">
            <form action="" method="POST">
                <div class="form-group">
                    <label for="loggedby">Logged By:</label>
                    <input type="text" name="loggedby" id="loggedby" value="<?php echo $_SESSION['user_name']?>" readonly required>
                </div>
                <div class="form-group">
                    <label for="forVerification">Remark 1:</label>
                    <input type="text" name="forVerification" id="forVerification" value="FOR VERIFICATION" readonly>
                </div>
                <div class="form-group">
                    <label for="date">Date:</label>
                    <input type="text" class="date" id="date" name="dateNow" value="<?php echo date('Y-m-d'); ?>" readonly>
                </div>
                <div class="form-group buttons">
                    <button class="close" type="button">Close</button>
                    <input type="submit" id="savebtn" name="savebtn" value="Save">
                </div>
            </form>
        </div>
    </div>
    </div>

    <div class="s-container">
        <table class="table2" id="tableID">
            <thead>
                <tr>
                    <th>Status</th>
                    <th>Date/Time <br> YYYY-MM-DD</th>
                    <th>Control Number</th>
                    <th>Reference Number</th>
                    <th>Payor</th>
                    <th>Address</th>
                    <th>Account Number</th>
                    <th>Account Name</th>
                    <th>Amount Paid</th>
                    <th>Charge to Partner</th>
                    <th>Charge to Customer</th>
                    <th>Contact Number</th>
                    <th>Other Details</th>
                    <th>ML Outlet</th>
                    <th>Region</th>
                    <th>Operator</th>
                    <th>Partner Name</th>
                    <th>Partner ID</th>
                </tr>
            </thead>
            <tbody>
            <tbody>
            <tbody>
            <?php
            
            // Get duplicate control numbers and payors
            $query = "SELECT * FROM temp_billsPayment_others WHERE control_number IN (
                SELECT control_number FROM temp_billsPayment_others GROUP BY control_number HAVING COUNT(*) > 1
            ) OR payor IN (
                SELECT payor FROM temp_billsPayment_others GROUP BY payor HAVING COUNT(*) > 1
            ) ORDER BY payor ASC, payor ASC;";
            $result = $conn->query($query);

            if ($result->num_rows > 0) {
                $rowCount = $result->num_rows;
                $columnCount = 18;
                ?>
                <div class="total-rows" style="padding:5px;">
                    <h4>Total Rows: <?php echo $rowCount; ?></h4>
                </div>
                <?php
                // Output table data
                while ($row = $result->fetch_assoc()) {
                    echo "<tr>
                            <td style=\"text-align:left;\">{$row['status']}</td>
                            <td style=\"text-align:center;\">{$row['date_time']}</td>
                            <td style=\"text-align:left;\">{$row['control_number']}</td>
                            <td style=\"text-align:left;\">{$row['reference_number']}</td>
                            <td style=\"text-align:left;\">{$row['payor']}</td>
                            <td style=\"text-align:left;\">{$row['address']}</td>
                            <td style=\"text-align:left;\">{$row['account_number']}</td>
                            <td style=\"text-align:left;\">{$row['account_name']}</td>
                            <td style=\"text-align:right;\">{$row['amount_paid']}</td>
                            <td style=\"text-align:right;\">{$row['charge_to_partner']}</td>
                            <td style=\"text-align:right;\">{$row['charge_to_customer']}</td>
                            <td style=\"text-align:left;\">{$row['contact_number']}</td>
                            <td style=\"text-align:left;\">{$row['other_details']}</td>
                            <td style=\"text-align:left;\">{$row['ml_outlet']}</td>
                            <td style=\"text-align:left;\">{$row['region']}</td>
                            <td style=\"text-align:left;\">{$row['operator']}</td>
                            <td style=\"text-align:left;\">{$row['partner_name']}</td>
                            <td style=\"text-align:center;\">{$row['partner_id']}</td>
                        </tr>";
                }
            } else {
                // No duplicate records found
                echo "<tr><td colspan=\"18\" id=\"no_data\">No duplicate records found...</td></tr>";
            }
            ?>    
<?php
if(isset($_POST['savebtn'])) {
    $query = "INSERT INTO actionLog (status, blank, date_time, control_number, reference_number, payor, address, account_number, account_name, amount_paid, charge_to_partner, charge_to_customer, contact_number, other_details, ml_outlet, region, operator, partner_name, partner_id, imported_date, imported_by) 
        SELECT status, blank, date_time, control_number, reference_number, payor, address, account_number, account_name, amount_paid, charge_to_partner, charge_to_customer, contact_number, other_details, ml_outlet, region, operator, partner_name, partner_id, imported_date, imported_by 
        FROM temp_billsPayment_others 
        WHERE control_number IN (
            SELECT control_number FROM temp_billsPayment_others GROUP BY control_number HAVING COUNT(*) > 1
        ) OR payor IN (
            SELECT payor FROM temp_billsPayment_others GROUP BY payor HAVING COUNT(*) > 1
        ) 
        ORDER BY payor ASC, payor ASC";

    $insert_res = mysqli_query($conn, $query);
    
    if(!empty($_POST['forVerification'])) {
        $remark1 = mysqli_real_escape_string($conn, $_POST['forVerification']);
        $date1 = mysqli_real_escape_string($conn, $_POST['dateNow']);
        $remarkby =  mysqli_real_escape_string($conn, $_POST['loggedby']);
        
        $update1 = "UPDATE actionLog SET remark1 = IF(remark1 IS NULL OR remark1 = '', '$remark1', remark1)";
        $update1_res = mysqli_query($conn, $update1);

        $update2 = "UPDATE actionLog SET date1 = IF(date1 IS NULL OR date1 = '', '$date1', date1), logged_date = IF(logged_date IS NULL OR logged_date = '', '$date1', logged_date)";
        $update2_res = mysqli_query($conn, $update2);

        $update3 = "UPDATE actionLog SET remark_by1 = IF(remark_by1 IS NULL OR remark_by1 = '', '$remarkby', remark_by1)";
        $update3_res = mysqli_query($conn, $update3);

        $update5 = "UPDATE actionLog SET logged_by = IF(logged_by IS NULL OR logged_by = '', '$remarkby', logged_by)";
        $update5_res = mysqli_query($conn, $update5);
        
        $update4 = "UPDATE actionLog SET log_status = IF(log_status IS NULL OR log_status = '', 'Pending', log_status)";
        $update4_res = mysqli_query($conn, $update4);
    }
    
    if($insert_res) {
        // Insertion successful
    } else {
        // Insertion failed
    }
}
?>


            </tbody>
        </table>     
    </div>
</body>
</html>

<script>
    // JavaScript to open and close the modal
var modal = document.getElementById("myModal");
var btn = document.getElementById("addtoLog");
var span = document.getElementsByClassName("close")[0];
var savebtn = document.getElementById("savebtn");

btn.onclick = function() {
  modal.style.display = "block";
};

span.onclick = function() {
  modal.style.display = "none";
};

savebtn.onclick = function() {
  // Add your logic here to perform the action when the user confirms
  // For example, you can submit a form or make an AJAX request
  modal.style.display = "none";
};

window.onclick = function(event) {
  if (event.target == modal) {
    modal.style.display = "none";
  }
};

</script>