<?php
$conn = mysqli_connect('localhost', 'root', 'Password1','mldb');
session_start();
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
   <title>User Page</title>
   <!-- custom css file link  -->
   <link rel="stylesheet" href="../css/style.css?v=<?php echo time(); ?>">
</head>
<body> 
<div class="container">
   <div class="content">
      <div class="btn-back">
      <a href="user_page.php" id="back">Back</a>
      </div>
      <h1><span><?php echo $_SESSION['user_name'] ?></span></h1>
      <h5 style="padding-left:2%;"><span><?php echo $_SESSION['user_email'] ?></span></h5>
      <h3><span>User</span></h3>
      <div class="side-nav">
         <a href="billsPayment.php">Import Transaction</a>
         <a href="bank-special/import-bank-special.php">Import Transaction (Others)</a>
         <button class="dropdown-btn">Generate Report
            <i class="fa fa-caret-down"></i>
         </button>
         <div class="dropdown-container">
            <a class="dropdown-btn" href="billspayment_report.php">Bills Payment Transaction Report</a>
            <a class="dropdown-btn" href="date/date-filter-billsPayment.php">Bills Payment Transaction (Cancelled and Good)</a>
            <a class="dropdown-btn" href="date/date-good-only.php">Bills Payment Transaction (Good Only)</a>
            <a class="dropdown-btn" href="date/date-cancelled-only.php">Bills Payment Transaction (Cancelled Only)</a>
            <a class="dropdown-btn" href="date/date-duplicate-report.php">Bills Payment Transaction (Duplicate/Split Transaction)</a>
         </div>
         <button class="dropdown-btn">Other Report
            <i class="fa fa-caret-down"></i>
         </button>
         <div class="dropdown-container">
            <a href="bank-special/allPartner-transaction.php" class="dropdown-btn">All Partner Transactions</a>
            <button class="dropdown-btn">Security Bank Transaction
               <i class="fa fa-arrow-down"></i>
            </button>
            <div class="dropdown-container">
               <a href="bank-special/security-bank-rta.php">Security Bank Transaction (RTA)</a>
               <a href="bank-special/security-bank-lazada.php">Security Bank Transaction (LAZADA)</a>
            </div>
         </div>
      <!-- <a href="ActionLog.php"></a> -->
      <button class="dropdown-btn">Action Taken / Log Files</button>
      <div class="dropdown-container">
         <a class="dropdown-btn" href="ActionLog.php">Add Logs
            <i class="fa fa-arrow-down"></i>
         </a>
         <a class="dropdown-btn" href="actionLogReport.php">Action Log Report
            <i class="fa fa-arrow-down"></i>
         </a>
      </div>
      <button class="dropdown-btn">Maintenance</button>
      <div class="dropdown-container">
      <a class="dropdown-btn" href="create-partner.php">Create Partner
            <i class="fa fa-arrow-down"></i>
         </a>
         <a class="dropdown-btn" href="delete-process.php">Delete records
            <i class="fa fa-arrow-down"></i>
         </a>
         <a class="dropdown-btn" href="delete-process-others.php">Delete records (Others)
            <i class="fa fa-arrow-down"></i>
         </a>
      </div>
   </div>
</div>
<script>
/* Loop through all dropdown buttons to toggle between hiding and showing its dropdown content - This allows the user to have multiple dropdowns without any conflict */
var dropdown = document.getElementsByClassName("dropdown-btn");
var i;

for (i = 0; i < dropdown.length; i++) {
  dropdown[i].addEventListener("click", function() {
    this.classList.toggle("active");
    var dropdownContent = this.nextElementSibling;
    if (dropdownContent.style.display === "block") {
      dropdownContent.style.display = "none";
    } else {
      dropdownContent.style.display = "block";
    }
  });
}
</script>
</body>
</html>