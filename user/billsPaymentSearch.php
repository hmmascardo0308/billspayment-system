<?php
session_start(); 
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
    <title>Data Results</title>
    <link rel="stylesheet" href="../css/billsPayment.css?v=<?php echo time(); ?>" >
</head>
<body>
    <div class="btn-back">
        <a href="billsPayment.php" id="back">Back</a>
    </div>
    <div class="s-container">
        <table class="table2" id="tbl2">
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
                    $search = $_POST['search'];
                    if ($conn->connect_error){
                        die("Connection failed: ". $conn->connect_error);
                    }
                    $sql = "select * from billsPayment where status like '%$search%'
                            OR date_time like '%$search%'
                            OR control_number like '%$search%'
                            OR reference_number like '%$search%'
                            OR payor like '%$search%'
                            OR address like '%$search%'
                            OR account_number like '%$search%'
                            OR account_name like '%$search%'
                            OR amount_paid like '%$search%'
                            OR charge_to_partner like '%$search%'
                            OR charge_to_customer like '%$search%'
                            OR contact_number like '%$search%'
                            OR other_details like '%$search%'
                            OR ml_outlet like '%$search%'
                            OR region like '%$search%'
                            OR operator like '%$search%'
                            OR partner_name like '%$search%'
                            OR partner_id like '%$search%'
                            OR imported_date like '%$search%'
                            OR imported_by like '%$search%'";
                    $result = $conn->query($sql);
                    if($result){
                        if ($result->num_rows > 0){
                            while($row = $result->fetch_assoc() ){
        
                ?>
                    <tr>
                        <td style="text-align:left;"><?php echo $row['status']; ?></td>
                        <td style="text-align:left;"><?php echo $row['date_time']; ?></td>
                        <td style="text-align:left;"><?php echo $row['control_number']; ?></td>
                        <td style="text-align:left;"><?php echo $row['reference_number']; ?></td>
                        <td style="text-align:left;"><?php echo $row['payor']; ?></td>
                        <td style="text-align:left;"><?php echo $row['address']; ?></td>
                        <td style="text-align:left;"><?php echo $row['account_number']; ?></td>
                        <td style="text-align:left;"><?php echo $row['account_name']; ?></td>
                        <td style="text-align:left;"><?php echo $row['amount_paid']; ?></td>
                        <td style="text-align:left;"><?php echo $row['charge_to_partner']; ?></td>
                        <td style="text-align:left;"><?php echo $row['charge_to_customer']; ?></td>
                        <td style="text-align:left;"><?php echo $row['contact_number']; ?></td>
                        <td style="text-align:left;"><?php echo $row['other_details']; ?></td>
                        <td style="text-align:left;"><?php echo $row['ml_outlet']; ?></td>
                        <td style="text-align:left;"><?php echo $row['region']; ?></td>
                        <td style="text-align:left;"><?php echo $row['operator']; ?></td>
                        <td style="text-align:left;"><?php echo $row['partner_name']; ?></td>
                        <td style="text-align:left;"><?php echo $row['partner_id']; ?></td>
                        <td style="text-align:left;"><?php echo $row['imported_date']; ?></td>
                        <td style="text-align:left;"><?php echo $row['imported_by']; ?></td>
                    </tr>
                    <br>
                <?php }} }else{ ?>
                    <tr><td colspan="19" id="no_data">No data(s) found...</td></tr>
                <?php } ?>
            </tbody>
        </table>    
    </div>
</body>
</html>