<?php
session_start();
$conn = mysqli_connect('localhost', 'root', 'Password1', 'mldb');

if (!isset($_SESSION['user_name'])) {
    header('location:login_form.php');
}

if (isset($_POST['export-good-cancelled'])) {
    $from_date = $_POST['fromDate'];
    $to_date = $_POST['toDate'];
    $partner_id = ($_POST['partner'] != '') ? $_POST['partner'] : '%'; // '%' will select all partners if not specified

    $query = "SELECT * FROM billsPayment WHERE date_time >= '$from_date' OR cancellation_date >= '$from_date' AND date_time <= '$to_date' OR cancellation_date >= '$to_date' AND partner_id LIKE '$partner_id'";
    $query_run = mysqli_query($conn, $query);

    // Export the data to CSV
    $filename = 'Bills Payment Good and Cancelled Transactions_' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename=' . $filename);
    $output = fopen('php://output', 'w');

    // Write CSV header
    fputcsv($output, array(
        'Status','Cancellation Date', 'Date/Time YYYY-MM-DD', 'Control Number', 'Reference Number', 'Payor', 'Address',
        'Account Number', 'Account Name', 'Amount Paid', 'Charge to Partner', 'Charge to Customer',
        'Contact Number', 'Other Details', 'ML Outlet', 'Region', 'Operator', 'Partner Name', 'Partner ID',
        'Imported Date', 'Imported By'
    ));

    // Write CSV data without the 'id' column
    while ($records = mysqli_fetch_assoc($query_run)) {
        unset($records['id']); // Remove the 'id' column from the records before exporting
        fputcsv($output, $records);
    }

    fclose($output);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BillsPayment Transaction Records</title>
    <link href="../../css/billsPayment.css?v=<?php echo time(); ?>" rel="stylesheet">
       <script src="https://kit.fontawesome.com/30b908cc5a.js" crossorigin="anonymous"></script>
   <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
   <link rel="icon" href="../images/MLW logo.png" type="image/png">
</head>
<body> 
    <div class="container">
         <div class="top-content">
         <div class="nav-container">
            <i id="menu-btn" class="fa-solid fa-bars"></i>
            <div class="usernav">
               <h4><?php echo $_SESSION['user_name'] ?></h4>
               <h4 style="margin-left:5px;"><?php echo "(".$_SESSION['user_email'].")" ?></h5>
            </div>
         </div>
      </div>
      
      <!-- Show and Hide Side Nav Menu -->
      <div id="sidemenu" class="sidemenu" style="display: none;">

         <!-- Home Button -->
         <div class="onetab" onclick="parent.location='../user_page.php'">
            <a href="../user_page.php">Home</a>
         </div>

         <!-- Show/Hide Paramount -->
         <div class="onetab" id="para-btn">
            <i class="fa-solid fa-caret-right" id="closed-para" style="display: block"></i>
            <i class="fa-solid fa-caret-down" id="open-para" style="display: none"></i>
            <h4>Billspayment</h4>
         </div>

         <!-- Show/Hide Paramount Import -->
         <div class="tabcat" id="para-import-btn" style="display: none;">
               <i class="fa-solid fa-chevron-right" id="closed-para-import" style="display: block"></i>
               <i class="fa-solid fa-chevron-down" id="open-para-import" style="display: none"></i>
               <h4>Billspayment Import</h4>
         </div>

         <!-- Paramount Import Buttons -->
         <div class="onetab-sub" id="para-import-nav" style="display: none;">
            <div class="sub" onclick="parent.location='../billsPayment.php'">
               <a href="../billsPayment.php">Import</a>
            </div>
         </div>

         <!-- Show/Hide Paramount Report -->
         <div class="tabcat" id="para-report-btn" style="display: none;">
               <i class="fa-solid fa-chevron-right" id="closed-para-report" style="display: block"></i>
               <i class="fa-solid fa-chevron-down" id="open-para-report" style="display: none"></i>
               <h4>Billspayment Report</button>
         </div>

         <!-- Paramount Report Buttons -->
         <div class="onetab-sub" id="para-report-nav" style="display: none;">
            <div class="sub" onclick="parent.location='../daily_report.php'">
               <a href="../daily_report.php">Daily Report</a>
            </div>
            <div class="sub" onclick="parent.location='#'">
               <a href="#">Monthly Report</a>
            </div>
            <div class="sub" onclick="parent.location='date-filter-billsPayment.php'">
               <a href="date-filter-billsPayment.php">BP Transaction (Cancelled and Good)</a>
            </div>
            <div class="sub" onclick="parent.location='date-good-only.php'">
               <a href="date-good-only.php">BP Transaction (Good Only)</a>
            </div>
            <div class="sub" onclick="parent.location='date-cancelled-only.php'">
               <a href="date-cancelled-only.php">BP Transaction (Cancelled Only)</a>
            </div>
            <div class="sub" onclick="parent.location='date-duplicate-report.php'">
               <a href="date-duplicate-report.php">BP Transaction (Duplicate/Split Transaction)</a>
            </div>
         </div>

         <!-- Show/Hide MAA -->
         <div class="onetab" id="maa-btn">
         <i class="fa-solid fa-caret-right" id="closed-maa" style="display: block"></i>
         <i class="fa-solid fa-caret-down" id="open-maa" style="display: none"></i>
         <h4>Bookkeeper</h4>
         </div>

         <div class="onetab-sub" id="maa-nav" style="display: none;">
            <div class="sub" onclick="parent.location='#'">
               <a href="#">Bookkeeper Import</a>
            </div>
            <div class="sub" onclick="parent.location='#'">
               <a href="#">Book keeper Report</a>
            </div>
         </div>

         <div class="onetab" onclick="parent.location='../logout.php'">
            <a href="../logout.php">Logout</a>
         </div>
      </div>
        <div class="row justify-content-center">
            <div class="col-md-12">
                <div class="card mt-5">
                    <div class="card-header">
                        <center><h4>Bills Payment Transaction Records</h4></center>
                    </div>
                    <div class="card-body">
                        <div class="form-container">
                            <form action="" method="POST">
                                <div class="row">
                                    <div class="col">
                                        <div class="form-select">
                                            <label>Select Partner:</label>
                                            <select name="partner" class="form-control">
                                                <option value="">All Partners</option>
                                                <?php
                                                $query = "SELECT * FROM partner_masterfile";
                                                $query_run = mysqli_query($conn, $query);
                                                while ($partner = mysqli_fetch_assoc($query_run)) {
                                                    $selected = (isset($_POST['partner']) && $_POST['partner'] == $partner['partner_id']) ? 'selected' : '';
                                                    echo "<option value='{$partner['partner_id']}' $selected>{$partner['partner_name']}</option>";
                                                }
                                                ?>
                                            </select>
                                        </div>
                                    </div>
                                <div class="col">
                                        <div class="date-wrap">
                                            <div class="form-from_date">
                                                <label>From Date:</label>
                                                <input type="date" name="fromDate" value="<?php if(isset($_POST['fromDate'])){ echo $_POST['fromDate']; } ?>" class="form-control" required>
                                            </div>
                                            <div class="form-to_date">
                                                <label>To Date:</label>
                                                <input type="date" name="toDate" value="<?php if(isset($_POST['toDate'])){ echo $_POST['toDate']; } ?>" class="form-control" required>
                                            </div>
                                        </div>
                                    </div>
                                <div class="col-md-4">
                                    <div class="form-btn">
                                        <button type="submit" class="filter-btn">Proceed</button>
                                        <input type="submit" name="export-good-cancelled" id="export" value="Export to CSV" class="btn btn-info" onclick="openFileExplorer(event)" />
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                    <?php 
                        $total_amount_paid = 0; // Initialize the total amount paid variable

                        if(isset($_POST['fromDate']) && ($_POST['toDate'])) {
                            $from_date = $_POST['fromDate'];
                            $to_date = $_POST['toDate'];
                            $partner_id = ($_POST['partner'] != '') ? $_POST['partner'] : '%'; // '%' will select all partners if not specified
                            $query = "SELECT * FROM billsPayment WHERE date_time >= '$from_date' OR cancellation_date >= '$from_date' AND date_time <= '$to_date' OR cancellation_date >= '$to_date' AND partner_id LIKE '$partner_id'";
                            $query_run = mysqli_query($conn, $query);

                            // Calculate the total amount paid
                            foreach($query_run as $records) {
                                $total_amount_paid += $records['amount_paid'];
                            }
                            ?>

                            <div class="date-card">
                                <div class="table-body">
                                <div class="total-amount">
                                    <p>Total Amount Paid: <strong> <?= number_format($total_amount_paid, 2); ?></strong></p>
                                </div>
                                    <table class="date-table" id="tbl">
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
                                            foreach($query_run as $records) {
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
                                                    <td style="text-align:center;"><?= $records['partner_id']; ?></td>
                                                    <td style="text-align:center;"><?= $records['imported_date']; ?></td>
                                                    <td style="text-align:center;"><?= $records['imported_by']; ?></td>
                                                </tr>
                                                <?php
                                            }
                                            ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <?php
                            if($from_date > $to_date) {
                                echo "<p style='background-color:#d70c0c;color:white;padding:5px;width:auto;'>Invalid Date!</p>";
                            }
                        }
                        ?>

                    </div>
                </div>
            </div>
        </div>
    </div>
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

     // Hide and Show Side Menu

   var menubtn = document.getElementById("menu-btn"); // Menu Button
   var sidemenu = document.getElementById("sidemenu"); // Side Menu Div

   // Add a click event listener to the document object
   document.addEventListener("click", function(event) {
   // Check if the clicked element is outside of the sidemenu and is not the button
   if (!sidemenu.contains(event.target) && event.target !== menubtn) {
      // Hide the sidemenu
      sidemenu.style.animation = "slide-out-to-left 0.5s ease";
      setTimeout(function() {
         sidemenu.style.display = "none";
      }, 450);
   }
   });

   menubtn.addEventListener("click", function(){
      if(sidemenu.style.display == "none"){
         sidemenu.style.animation = "slide-in-from-left 0.5s ease";
         sidemenu.style.display = "block";
      }else{
         sidemenu.style.animation = "slide-out-to-left 0.5s ease";
         setTimeout(function() {
            sidemenu.style.display = "none";
         }, 450);
      }
   });

   var parabtn = document.getElementById("para-btn"); // Main Para Button
   var paraopen = document.getElementById("open-para"); // Para Div Down Arrow or Expanded
   var paraclosed = document.getElementById("closed-para"); // Para Div Right Arrow or Minimized
   var paraimportnav = document.getElementById("para-import-nav"); // Para Import Div
   var parareportnav = document.getElementById("para-report-nav"); // Para Report Div
   var paraimportbtn = document.getElementById("para-import-btn"); // Para Import Btn
   var parareportbtn = document.getElementById("para-report-btn"); // Para Report Btn
   var paraopenimport = document.getElementById("open-para-import"); // Para Import Div Down Arrow or Expanded
   var paraclosedimport = document.getElementById("closed-para-import"); // Para Import Div Right Arrow or Minimized
   var paraopenreport = document.getElementById("open-para-report"); // Para Report Div Down Arrow or Expanded
   var paraclosedreport = document.getElementById("closed-para-report"); // Para Report Div Right Arrow or Minimized

   parabtn.addEventListener("click", function(){ // If parabtn is clicked
      if(paraimportbtn.style.display == "none"){ // and paraimportbtn is not visible
         paraimportbtn.style.animation = "slide-in-from-top 0.8s ease";
         parareportbtn.style.animation = "slide-in-from-top 0.8s ease";
         paraopen.style.display = "block";       
         paraclosed.style.display = "none";
         paraimportbtn.style.display = "flex";
         parareportbtn.style.display = "flex";

      }else{
         paraopen.style.display = "none";
         paraclosed.style.display = "block";
         paraimportnav.style.display = "none";
         paraopenimport.style.display = "none";
         paraclosedimport.style.display = "block";
         parareportnav.style.display = "none";
         paraopenreport.style.display = "none";
         paraclosedreport.style.display = "block";
         paraimportbtn.style.animation = "slide-out-to-top 0.5s ease";
         parareportbtn.style.animation = "slide-out-to-top 0.5s ease";
         setTimeout(function() {
            paraimportbtn.style.display = "none";
            parareportbtn.style.display = "none";
         }, 450);
      }
   });

   paraimportbtn.addEventListener("click", function(){ // For para import side
      if(paraopenimport.style.display == "none"){
         paraimportnav.style.animation = "slide-in-from-top 0.8s ease";
         paraimportnav.style.display = "block";
         paraopenimport.style.display = "block";
         paraclosedimport.style.display = "none";
      }else{
         paraopenimport.style.display = "none";
         paraclosedimport.style.display = "block";
         paraimportnav.style.animation = "slide-out-to-top 0.5s ease";
         setTimeout(function() {
            paraimportnav.style.display = "none";
         }, 450);
      }
   });

   parareportbtn.addEventListener("click", function(){ // For para report side
      if(paraopenreport.style.display == "none"){
         parareportnav.style.animation = "slide-in-from-top 0.8s ease";
         parareportnav.style.display = "block";
         paraopenreport.style.display = "block";
         paraclosedreport.style.display = "none";
      }else{
         parareportnav.style.animation = "slide-out-to-top 0.5s ease";
         setTimeout(function() {
            parareportnav.style.display = "none";
         }, 450);
         paraopenreport.style.display = "none";
         paraclosedreport.style.display = "block";
      }
   });

   var maabtn = document.getElementById("maa-btn");
   var maaopen = document.getElementById("open-maa");
   var maaclosed = document.getElementById("closed-maa");
   var maanav = document.getElementById("maa-nav");
   // var maaimportnav = document.getElementById("maa-import-nav");
   // var maareportnav = document.getElementById("maa-report-nav");
   // var maaimportbtn = document.getElementById("maa-import-btn");
   // var maareportbtn = document.getElementById("maa-report-btn");
   // var maaopenimport = document.getElementById("open-maa-import");
   // var maaclosedimport = document.getElementById("closed-maa-import");
   // var maaopenreport = document.getElementById("open-maa-report");
   // var maaclosedreport = document.getElementById("closed-maa-report");

   maabtn.addEventListener("click", function(){
      if(maanav.style.display == "none"){
         maaopen.style.display = "block";
         maaclosed.style.display = "none";
         maanav.style.display = "block";
         maanav.style.animation = "slide-in-from-top 0.8s ease";
      }else{
         maanav.style.animation = "slide-out-to-top 0.5s ease";
         setTimeout(function() {
            maanav.style.display = "none";
         }, 450);
         maaopen.style.display = "none";
         maaclosed.style.display = "block";
      }
      // if(maaimportbtn.style.display == "none"){
      //    maaopen.style.display = "block";
      //    maaclosed.style.display = "none";
      //    maaimportbtn.style.display = "flex";
      //    maareportbtn.style.display = "flex";
      // }else{
      //    maaimportbtn.style.display = "none";
      //    maareportbtn.style.display = "none";
      //    maaopen.style.display = "none";
      //    maaclosed.style.display = "block";
      //    maaimportnav.style.display = "none";
      //    maaopenimport.style.display = "none";
      //    maaclosedimport.style.display = "block";
      //    maareportnav.style.display = "none";
      //    maaopenreport.style.display = "none";
      //    maaclosedreport.style.display = "block";
      // }
   });

   // maaimportbtn.addEventListener("click", function(){
   //    if(maaopenimport.style.display == "none"){
   //       maaimportnav.style.display = "block";
   //       maaopenimport.style.display = "block";
   //       maaclosedimport.style.display = "none";
   //    }else{
   //       maaimportnav.style.display = "none";
   //       maaopenimport.style.display = "none";
   //       maaclosedimport.style.display = "block";
   //    }
   // });

   // maareportbtn.addEventListener("click", function(){
   //    if(maaopenreport.style.display == "none"){
   //       maareportnav.style.display = "block";
   //       maaopenreport.style.display = "block";
   //       maaclosedreport.style.display = "none";
   //    }else{
   //       maareportnav.style.display = "none";
   //       maaopenreport.style.display = "none";
   //       maaclosedreport.style.display = "block";
   //    }
   // });

   var glebtn = document.getElementById("gle-btn");
   var gleopen = document.getElementById("open-gle");
   var gleclosed = document.getElementById("closed-gle");
   var glenav = document.getElementById("gle-nav");

   glebtn.addEventListener("click", function(){
      if(glenav.style.display == "none"){
         glenav.style.animation = "slide-in-from-top 0.8s ease";
         gleopen.style.display = "block";
         gleclosed.style.display = "none";
         glenav.style.display = "block";
      }else{
         gleopen.style.display = "none";
         gleclosed.style.display = "block";
         glenav.style.animation = "slide-out-to-top 0.5s ease";
         setTimeout(function() {
            glenav.style.display = "none";
         }, 450);
      }
   });

   var mstrfl = document.getElementById("mstrfl-btn");
   var mstrflopen = document.getElementById("open-mstrfl");
   var mstrflclosed = document.getElementById("closed-mstrfl");
   var mstrflnav = document.getElementById("mstrfl-nav");

   mstrfl.addEventListener("click", function(){
      if(mstrflnav.style.display == "none"){
         mstrflnav.style.animation = "slide-in-from-top 0.8s ease";
         mstrflopen.style.display = "block";
         mstrflclosed.style.display = "none";
         mstrflnav.style.display = "block";
      }else{
         mstrflnav.style.animation = "slide-out-to-top 0.5s ease";
         setTimeout(function() {
            mstrflnav.style.display = "none";
         }, 450);
         mstrflopen.style.display = "none";
         mstrflclosed.style.display = "block";
      }
   });

   var recon = document.getElementById("recon-btn");
   var reconopen = document.getElementById("open-recon");
   var reconclosed = document.getElementById("closed-recon");
   var reconnav = document.getElementById("recon-nav");

   recon.addEventListener("click", function(){
      if(reconnav.style.display == "none"){
         reconnav.style.animation = "slide-in-from-top 0.8s ease";
         reconopen.style.display = "block";
         reconclosed.style.display = "none";
         reconnav.style.display = "block";
      }else{
         reconnav.style.animation = "slide-out-to-top 0.5s ease";
         setTimeout(function() {
            reconnav.style.display = "none";
         }, 450);
         reconopen.style.display = "none";
         reconclosed.style.display = "block";
      }
   });
    </script>
</body>
</html>