<?php
@include '../export/export-bank-rta.php';
$conn = mysqli_connect('localhost', 'root', 'Password1','mldb');
?>
<title>Security Bank RTA Records</title> 
<link href="../../css/billdpayment_others.css?v=<?php echo time(); ?>" rel="stylesheet">
<div class="container">
        <div class="btn-back">
           <div class="back-div">
                <a href="../billsPayment-menu.php" id="back">Back</a>
               
           </div>
           <div class="head-title">
                <center><h2>Generate Security Bank RTA Records</h2></center>
            </div>
        </div>
    
<div class="row">
    <form action="" method="post"  enctype="multipart/form-data">
        <div class="input-daterange">
            <div class="col">
                <label>From</label>
                <input type="date" name="fromDate" class="form-control" value="<?php if(isset($_POST['fromDate'])){ echo $_POST['fromDate']; } ?>" required/>
	            <?php echo $startDateMessage; ?>
            </div>
            <div class="col">
                <label>To &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</label>
	            <input type="date" name="toDate" class="form-control" value="<?php if(isset($_POST['toDate'])){ echo $_POST['toDate']; } ?>" required />
	            <?php echo $endDate; ?>
            </div>
        </div>
        <div class="btn-proceed"><div>&nbsp;</div>
            <input type="submit" name="proceed" id="proceed" value="Proceed" />
        </div>
        <div class="btn-export"><div>&nbsp;</div>
            <input type="submit" name="export" id="export" value="Export to CSV" class="btn btn-info" />
        </div>
    </form>
</div>
<div class="row">
	<div class="col">
		<?php echo $noResult;?>
	</div>
</div>
<div class="s-container">
<table class="table">
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

    if(isset($_POST['fromDate'])&&($_POST['toDate'])){
        $from_date = $_POST['fromDate'];
        $to_date = $_POST['toDate'];
        $query = "SELECT * FROM billsPayment_others WHERE account_number LIKE '%0%0%6%1%0%0%0%1%2%8%0%5%3%' AND account_number != '0061000128053' AND partner_id = 'MLBPP180463' AND date_time BETWEEN '".$_POST["fromDate"]."' AND '".$_POST["toDate"]."'";
        $query_run = mysqli_query($conn, $query);
        foreach($query_run as $records)
        {
          ?>
          <tr>
              <td style="text-align:left;"><?= $records['status']; ?></td>
              <td style="text-align:left;"><?= $records['date_time']; ?></td>
              <td style="text-align:left;"><?= $records['control_number']; ?></td>
              <td style="text-align:left;"><?= $records['reference_number']; ?></td>
              <td style="text-align:left;"><?= $records['payor']; ?></td>
              <td style="text-align:left;"><?= $records['address']; ?></td>
              <td style="text-align:left;"><?= $records['account_number']; ?></td>
              <td style="text-align:left;"><?= $records['account_name']; ?></td>
              <td style="text-align:right;"><?= $records['amount_paid']; ?></td>
              <td style="text-align:right;"><?= $records['charge_to_partner']; ?></td>
              <td style="text-align:right;"><?= $records['charge_to_customer']; ?></td>
              <td style="text-align:left;"><?= $records['contact_number']; ?></td>
              <td style="text-align:left;"><?= $records['other_details']; ?></td>
              <td style="text-align:left;"><?= $records['ml_outlet']; ?></td>
              <td style="text-align:left;"><?= $records['region']; ?></td>
              <td style="text-align:left;"><?= $records['operator']; ?></td>
              <td style="text-align:left;"><?= $records['partner_name']; ?></td>
              <td style="text-align:left;"><?= $records['partner_id']; ?></td>
              <td style="text-align:left;"><?= $records['imported_date']; ?></td>
              <td style="text-align:left;"><?= $records['imported_by']; ?></td>
          </tr>
      
        <?php
        }
        if($from_date > $to_date){
            echo "<p style='background-color:#d70c0c;color:white;padding:5px;width:auto;'>Invalid Date!</p>";
        }
    }
?>
   <script src="https://code.jquery.com/jquery-3.5.1.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.0-beta1/dist/js/bootstrap.bundle.min.js"></script>
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
 </tbody>
</table>
</div>
</div>
