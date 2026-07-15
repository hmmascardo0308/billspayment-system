<?php session_start(); 
$conn = mysqli_connect('localhost', 'root', 'Password1','mldb');
@include '../fetch-partner-data.php';
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
    <title>MLhuillier Import Partners Record</title>
    <link href="../../css/import_billsPayment.css?v=<?php echo time(); ?>" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.6.4/css/bootstrap-datepicker.css" />
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.6.4/js/bootstrap-datepicker.js"></script>
    <script src="js/datepickers.js"></script>
</head> 
<body>
    <?php 
        if(isset($_SESSION['message']))
        {
            echo "<h6 class='msg'>".$_SESSION['message']."</h5>";
            unset($_SESSION['message']);
        }
    ?>
    <div class="container">
        <div class="card">
            <div class="card-header">
            <?php
                    if(isset($_POST['btn_back'])){
                        $result = $conn->query("DELETE FROM temp_billsPayment_others");
                        header("Location: ../billspayment-menu.php");
                        exit();
                    }
                ?>

                <div class="btn-back">
                    <form method="post">
                        <input type="submit" name="btn_back" id="back" value="Back">
                    </form>
                    <br>

                    <div class="header">
                        <input type="text" name="header-t" id="header-t" readonly>
                    </div>
                   
                </div>
            </div>
            <div class="card-body">
                <form action="import-bank-code.php" method="POST" enctype="multipart/form-data">
                <select class="form-control" onchange="s()" id="partner-select" name="partnerName" required>
                        <option value="Select Partner Name" disabled selected><center>-- Select Partner Name --</center></option>
                        <?php
                            foreach ($options as $option) {
                            ?>
                                <option data-partner-code="<?php echo $option['partner_id']; ?>"><?php echo $option['partner_name']; ?></option>
                            <?php 
                            }
                        ?>
                    </select>
                    <!-- Add the input field for entityCode -->
                    <input style="display:none;" type="text" id="partnerID" name="partnerID" readonly required>
                    <div class="choose-file">
                        <input style="display:none;" type="text" name="importedby" value="<?php echo $_SESSION['user_name']?>" readonly>
                        <i style="padding-left:8px; font-size:12px;">Import .csv, .xls, and .xlsx file <b style="color:red;">ONLY</b>!</i>
                        <div class="import-file">
                            <input type="file" id="import_file" name="import_file" class="form-control" required/>
                            <input type="submit" id="import" name="save_excel_data" class="btn" value="Import" disabled>    
                        </div>
                    </div>
                    <div class="message">
                        <?php 
                            if(isset($_SESSION['alert-message']))
                            {
                                echo "<h6 class='error-msg'>".$_SESSION['alert-message']."</h6>";
                                unset($_SESSION['alert-message']);
                            }elseif(isset($_SESSION['succ-message'])){
                                echo "<h6 class='success-msg'>".$_SESSION['succ-message']."</h6>";
                                unset($_SESSION['succ-message']);
                            }
                        ?>  
                   </div>
                </form>
                
                <div class="display-data">
                    <a class="display-btn" onclick="formToggle('tbl');">Display/Hide</a>
                    <a href="import-bank-duplicate.php" class="duplicate-btn">Duplicate/Split Transaction</a>
                </div>
            </div>       
        </div>
       
        <!-- Data list table --> 
        <div class="tbl2-container">
            <table class="table" id="tbl">
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
                            <th>Imported Date</th>
                            <th>Imported By</th>
                        </tr>
                    </thead>
                <tbody>
                <?php
                            // Get member rows
                            $result = $conn->query("SELECT * FROM temp_billsPayment_others");
                            $rowCount = $result->num_rows;

                            if ($rowCount > 0) {
                                $transactions = array(); // Array to store transactions
                                while ($row = $result->fetch_assoc()) {
                                    // Check for duplicate control number
                                    $background = "";

                                    if ($row['control_number'] != "") {
                                        $checkDuplicateResult = $conn->query("SELECT COUNT(*) as duplicate_count FROM temp_billsPayment_others WHERE control_number = '".$row['control_number']."'");
                                        $checkDuplicateRow = $checkDuplicateResult->fetch_assoc();
                                        $duplicateCount = $checkDuplicateRow['duplicate_count'];

                                        if ($duplicateCount > 1) {
                                            $background = "background-color: red; color: white;";
                                        }
                                    }

                                    // Check for duplicate payor
                                    if ($row['payor'] != "") {
                                        $checkPayorDuplicateResult = $conn->query("SELECT COUNT(*) as payor_duplicate_count FROM temp_billsPayment_others WHERE payor = '".$row['payor']."'");
                                        $checkPayorDuplicateRow = $checkPayorDuplicateResult->fetch_assoc();
                                        $payorDuplicateCount = $checkPayorDuplicateRow['payor_duplicate_count'];

                                        if ($payorDuplicateCount > 1) {
                                            $background = "background-color: red; color: white;";
                                        }
                                    }

                                    // Add transaction to the array
                                    $row['background'] = $background;
                                    $transactions[] = $row;
                                }
                                ?>
                                <div class="total-rows" style="padding:5px;">
                                    <h4>Total rows imported: <?php echo $rowCount; ?></h4>
                                </div>
                            <?php
                                foreach ($transactions as $row) {
                                    ?>
                                    <tr style="<?php echo $row['background']; ?>">
                                        <td style="text-align:left; color: <?php echo ($row['background'] === "background-color: red; color: white;") ? "white" : "black"; ?>;"><?php echo $row['status']; ?></td>
                                        <td style="text-align:left; color: <?php echo ($row['background'] === "background-color: red; color: white;") ? "white" : "black"; ?>;"><?php echo $row['date_time']; ?></td>
                                        <td style="text-align:left; color: <?php echo ($row['background'] === "background-color: red; color: white;") ? "white" : "black"; ?>;"><?php echo $row['control_number']; ?></td>
                                        <td style="text-align:left; color: <?php echo ($row['background'] === "background-color: red; color: white;") ? "white" : "black"; ?>;"><?php echo $row['reference_number']; ?></td>
                                        <td style="text-align:left; color: <?php echo ($row['background'] === "background-color: red; color: white;") ? "white" : "black"; ?>;"><?php echo $row['payor']; ?></td>
                                        <td style="text-align:left; color: <?php echo ($row['background'] === "background-color: red; color: white;") ? "white" : "black"; ?>;"><?php echo $row['address']; ?></td>
                                        <td style="text-align:left; color: <?php echo ($row['background'] === "background-color: red; color: white;") ? "white" : "black"; ?>;"><?php echo $row['account_number']; ?></td>
                                        <td style="text-align:left; color: <?php echo ($row['background'] === "background-color: red; color: white;") ? "white" : "black"; ?>;"><?php echo $row['account_name']; ?></td>
                                        <td style="text-align:right; color: <?php echo ($row['background'] === "background-color: red; color: white;") ? "white" : "black"; ?>;"><?php echo $row['amount_paid']; ?></td>
                                        <td style="text-align:right; color: <?php echo ($row['background'] === "background-color: red; color: white;") ? "white" : "black"; ?>;"><?php echo $row['charge_to_partner']; ?></td>
                                        <td style="text-align:right; color: <?php echo ($row['background'] === "background-color: red; color: white;") ? "white" : "black"; ?>;"><?php echo $row['charge_to_customer']; ?></td>
                                        <td style="text-align:left; color: <?php echo ($row['background'] === "background-color: red; color: white;") ? "white" : "black"; ?>;"><?php echo $row['contact_number']; ?></td>
                                        <td style="text-align:left; color: <?php echo ($row['background'] === "background-color: red; color: white;") ? "white" : "black"; ?>;"><?php echo $row['other_details']; ?></td>
                                        <td style="text-align:left; color: <?php echo ($row['background'] === "background-color: red; color: white;") ? "white" : "black"; ?>;"><?php echo $row['ml_outlet']; ?></td>
                                        <td style="text-align:left; color: <?php echo ($row['background'] === "background-color: red; color: white;") ? "white" : "black"; ?>;"><?php echo $row['region']; ?></td>
                                        <td style="text-align:left; color: <?php echo ($row['background'] === "background-color: red; color: white;") ? "white" : "black"; ?>;"><?php echo $row['operator']; ?></td>
                                        <td style="text-align:left; color: <?php echo ($row['background'] === "background-color: red; color: white;") ? "white" : "black"; ?>;"><?php echo $row['partner_name']; ?></td>
                                        <td style="text-align:left; color: <?php echo ($row['background'] === "background-color: red; color: white;") ? "white" : "black"; ?>;"><?php echo $row['partner_id']; ?></td>
                                        <td style="text-align:center; color: <?php echo ($row['background'] === "background-color: red; color: white;") ? "white" : "black"; ?>;"><?php echo $row['imported_date']; ?></td>
                                        <td style="text-align:center; color: <?php echo ($row['background'] === "background-color: red; color: white;") ? "white" : "black"; ?>;"><?php echo $row['imported_by']; ?></td>
                                    </tr>
                                    <?php
                                }

                                
                            } else {
                                ?>
                                <tr>
                                    <td style="text-align:center;" colspan="22" id="no_data">No data(s) found...</td>
                                </tr>
                                <?php
                            }
                            ?>
                </tbody>
            </table>      
    
<script type="text/javascript">
    function formToggle(ID){
        var element = document.getElementById(ID);
        if(element.style.display === "none"){
            element.style.display = "block";
        }else{
            element.style.display = "none";
        }
    }
</script>

<script type="text/javascript">
function s(){
var i=document.getElementById("import_file");
    if(i=="")
    {
        document.getElementById("import").disabled=true;
    }
    else{
        document.getElementById("import").disabled=false;
    }
    var entitySelect = document.getElementById('partner-select');
    var entityCodeInput = document.getElementById('partnerID');
    var selectedOption = entitySelect.options[entitySelect.selectedIndex];
    entityCodeInput.value = selectedOption.getAttribute('data-partner-code');    
}
</script>
</body>
</html>