<?php
$conn = mysqli_connect('localhost', 'root', 'Password1','mldb');
session_start();
if(!isset($_SESSION['user_name'])){
   header('location:login_form.php');
}
require '../vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;

if (isset($_POST['generateBtn'])) {
   // Validate and sanitize input dates
   $from_date = validateDateInput($_POST['from_date']);
   $to_date = validateDateInput($_POST['to_date']);

   // Construct base query with partner name, count, and bank
   $selectQuery = "SELECT bp.import_batch, bp.partner_id, bp.partner_name, pm.bank, pm.charge_to, COUNT(bp.partner_name) AS quantity, SUM(bp.amount_paid) AS total_amount_paid, SUM(IF(bp.amount_paid < 0, 1, 0)) AS cancelled, SUM(IF(bp.amount_paid < 0, bp.amount_paid, 0)) AS total_negative_amount_paid";
   $selectQuery .= 
   ", SUM(CASE WHEN pm.charge_to = 'CUSTOMER' THEN bp.charge_to_customer * IF(bp.amount_paid < 0, 1, 0) ELSE 0 END +
   CASE WHEN pm.charge_to = 'PARTNER' THEN bp.charge_to_partner * IF(bp.amount_paid < 0, 1, 0) ELSE 0 END +
   CASE WHEN pm.charge_to = 'EPAY PARTNER' THEN bp.charge_to_partner * IF(bp.amount_paid < 0, 1, 0) ELSE 0 END) AS total_negative_charge";
   // Calculate total charge
   $selectQuery .= ", SUM(CASE WHEN pm.charge_to = 'CUSTOMER' THEN bp.charge_to_customer ELSE 0 END + CASE WHEN pm.charge_to = 'PARTNER' THEN bp.charge_to_partner ELSE 0 END + CASE WHEN pm.charge_to = 'EPAY PARTNER' THEN bp.charge_to_partner ELSE 0 END) AS total_charge";

   // Combine both tables
   $fromQuery = " FROM billspayment bp INNER JOIN partner_masterfile pm ON bp.partner_id = pm.partner_id";

   // Build WHERE clause based on dates
   $whereClause = "";
   if (!empty($from_date) && !empty($to_date)) {
       $whereClause = " WHERE bp.cancellation_date >= '$from_date' AND bp.cancellation_date <= '$to_date'";
   }

   // Group data by partner and bank
   $groupByClause = " GROUP BY bp.partner_id, bp.partner_name, bp.import_batch, pm.bank, pm.charge_to";

   // Add sorting by partner name
   $orderByClause = " ORDER BY bp.partner_name ASC";

   // Combine all query parts
   $fullQuery = $selectQuery . $fromQuery . $whereClause . $groupByClause . $orderByClause;

   // Execute the query
   $result = mysqli_query($conn, $fullQuery);
   if (!$result) {
       echo "Error retrieving partner information: " . mysqli_error($conn);
       exit;
   }

   // Create the Excel spreadsheet
   $spreadsheet = new Spreadsheet();
   $sheet = $spreadsheet->getActiveSheet();

   // Set header row
   $headerRow = [
      'Partners ID',
      'Partners Name',
      'Banks',
      'Charge to',
      'Vol',
      'Principal',
      'Charge',
      'Adj Vol',
      'Adj Principal',
      'Adj Charge',
      'Net Vol',
      'Net Principal',
      'Net Charge'
   ];
   $sheet->fromArray([$headerRow], NULL, 'A1');

   // Set row index for data insertion
   $rowIndex = 2;
   $sheet->getStyle('E:M')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
  // Loop through each row of data and populate the spreadsheet
while ($row = mysqli_fetch_assoc($result)) {
   
    $netVol = $row['quantity'] - $row['cancelled'];
    $netPrincipal = abs($row['total_amount_paid']) + $row['total_negative_amount_paid'];
    $netCharge = abs($row['total_charge']) + $row['total_negative_charge'];
    
    $sheet->setCellValue('A' . $rowIndex, $row['partner_id']);
    $sheet->setCellValue('B' . $rowIndex, $row['partner_name']);
    $sheet->setCellValue('C' . $rowIndex, $row['bank']);
    $sheet->setCellValue('D' . $rowIndex, $row['charge_to']);
    $sheet->setCellValue('E' . $rowIndex, number_format($row['quantity']));
    $sheet->setCellValue('F' . $rowIndex, number_format($row['total_amount_paid'], 2));
    $sheet->setCellValue('G' . $rowIndex, number_format($row['total_charge'], 2));
    $sheet->setCellValue('H' . $rowIndex, number_format(-1 * $row['cancelled']));
    $sheet->setCellValue('I' . $rowIndex, number_format($row['total_negative_amount_paid'], 2));
    $sheet->setCellValue('J' . $rowIndex, number_format($row['total_negative_charge']));
    $sheet->setCellValue('K' . $rowIndex, number_format($netVol));
    $sheet->setCellValue('L' . $rowIndex, number_format($netPrincipal, 2));
    $sheet->setCellValue('M' . $rowIndex, number_format($netCharge, 2));
    $rowIndex++;
}
   // Create Excel writer object and set headers
   $writer = new Xlsx($spreadsheet);
   header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

   // Dynamic filename based on date range
   $filename = "Partner_Report";
   if (!empty($from_date) && !empty($to_date)) {
       $filename .= "_" . $from_date . "_" . $to_date;
   }
   $filename .= ".xlsx";

   // Set filename and export the spreadsheet
   header('Content-Disposition: attachment; filename="' . $filename . '"');
   $writer->save('php://output');
   exit;
}

function validateDateInput($dateStr) {
   if (empty($dateStr)) {
       return null;
   }
   $date = DateTime::createFromFormat('Y-m-d', $dateStr);
   if (!$date) {
       // Invalid date format
       echo "Invalid date format for '$dateStr'";
       exit;
   }
   return $date->format('Y-m-d');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
   <meta charset="UTF-8">
   <meta http-equiv="X-UA-Compatible" content="IE=edge">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title>Bills Payment Daily Report</title>
   <!-- custom css file link  -->
   <link rel="stylesheet" href="../css/daily_report.css?v=<?php echo time(); ?>">
   <script src="https://kit.fontawesome.com/30b908cc5a.js" crossorigin="anonymous"></script>
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
   <div class="onetab" onclick="parent.location='user_page.php'">
      <a href="user_page.php">Home</a>
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
      <div class="sub" onclick="parent.location='billsPayment.php'">
         <a href="billsPayment.php">Import</a>
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
      <div class="sub" onclick="parent.location='daily_report.php'">
         <a href="daily_report.php">Daily Report</a>
      </div>
      <div class="sub" onclick="parent.location='#'">
         <a href="#">Monthly Report</a>
      </div>
   </div>

   <!-- Show/Hide MAA -->
   <div class="onetab" id="maa-btn">
   <i class="fa-solid fa-caret-right" id="closed-maa" style="display: block"></i>
   <i class="fa-solid fa-caret-down" id="open-maa" style="display: none"></i>
   <h4>Book keeper</h4>
   </div>

   <div class="onetab-sub" id="maa-nav" style="display: none;">
      <div class="sub" onclick="parent.location='#'">
         <a href="#">Book keeper Import</a>
      </div>
      <div class="sub" onclick="parent.location='#'">
         <a href="#">Book keeper Report</a>
      </div>
   </div>

   <div class="onetab" onclick="parent.location='../logout.php'">
      <a href="../logout.php">Logout</a>
   </div>
</div>
<?php 
$generateSql = "";
if(isset($_POST['generateBtn'])){
  $from_date = $_POST['from_date'];
  $to_date = $_POST['to_date'];
  $generateSql = "SELECT DISTINCT partner_name FROM billspayment WHERE date_time >= '$from_date' AND date_time <= '$to_date'";
  }
?>
<div class="generate_container">
  <div class="filter_report">
    <form action="" method="POST">
      <input type="date" name="from_date" id="from_date" value="" onchange="syncDates()"><br>
      <input type="date" name="to_date" id="to_date" value=""><br>
      <button type="submit" name="generateBtn" id="generateBtn">Generate Report</button>
    </form>
  </div>
</div>

<script>
  function syncDates() {
    var fromDate = document.getElementById('from_date').value;
    document.getElementById('to_date').value = fromDate;
  }
</script>

</div>

<script>
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