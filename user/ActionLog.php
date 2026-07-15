<?php
session_start(); 
$conn = mysqli_connect('localhost', 'root', 'Password1','mldb');
if(!isset($_SESSION['user_name'])){
   header('location:login_form.php');
}
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Action Log Files</title>
    <link href="../css/actionLog.css?v=<?php echo time(); ?>" rel="stylesheet"> 
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
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
            <div class="sub" onclick="parent.location='date/date-filter-billsPayment.php'">
               <a href="date/date-filter-billsPayment.php">BP Transaction (Cancelled and Good)</a>
            </div>
            <div class="sub" onclick="parent.location='date/date-good-only.php'">
               <a href="date/date-good-only.php">BP Transaction (Good Only)</a>
            </div>
            <div class="sub" onclick="parent.location='date/date-cancelled-only.php'">
               <a href="date/date-cancelled-only.php">BP Transaction (Cancelled Only)</a>
            </div>
            <div class="sub" onclick="parent.location='date/date-duplicate-report.php'">
               <a href="date/date-duplicate-report.php">BP Transaction (Duplicate/Split Transaction)</a>
            </div>
         </div>

         <div class="tabcat" id="action-report-btn" style="display: none;">
               <i class="fa-solid fa-chevron-right" id="closed-action-report" style="display: block"></i>
               <i class="fa-solid fa-chevron-down" id="open-action-report" style="display: none"></i>
               <h4>Action Taken / Log Files</button>
         </div>

         <div class="onetab-sub" id="action-report-nav" style="display: none;">
            <div class="sub" onclick="parent.location='ActionLog.php'">
               <a href="ActionLog.php">Add Logs</a>
            </div>
            <div class="sub" onclick="parent.location='actionLogReport.php'">
               <a href="actionLogReport.php">Action Log Reports</a>
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
            <div class="head-title">
                <h2>Action Log Files</h2>
            </div>
        <form class="inputs" method="POST" action="" id="my_form" >
             <!-- <input style="display:none;" type="checkbox" id="default" name="default" value="default" checked>
            <label style="display:none;" for="default"> Action Log</label><br> -->
            <input type="checkbox" class="checkmark" placeholder="checkmark" title="checkmark" id="check" name="billspayment" value="billspayment" <?php if(isset($_POST['billspayment'])) echo 'checked = "checked"'; ?> />
            <label for="billspayment"> Bills Payment</label><br>
            <input type="checkbox" class="checkmark" placeholder="checkmark" title="checkmark" id="check" name="billspayment_others" value="billspayment_others" <?php if(isset($_POST['billspayment_others'])) echo 'checked = "checked"'; ?>/>
            <label for="billspayment_others"> Bills Payment others</label><br>
            <div class="button-container">
                <div class="display-refNumber">
                    <input type="text" title="Enter Reference Number" id="ref_number" name="refer_number" onkeyup="s()" placeholder="Reference Number" value="<?php if(isset($_POST['refer_number'])){echo $_POST['refer_number']; }?>">
                    <input type="submit" title="Display the transaction" name="submit" id="proceed-btn" value="Display">
                    <input type="submit" class="btn-close" title="Close the Transaction" id="closed" name="closed" value="Closed">
                </div>
                </div>
            </div>
    </div>
    <?php
if (isset($_POST['closed'])) {
    $refer_number = $_POST['refer_number'];

    if (!empty($refer_number)) {
        $select_query = "SELECT * FROM actionLog WHERE reference_number = '$refer_number' AND log_status = 'Pending'";
        $select_result = mysqli_query($conn, $select_query);

        if ($select_result) {
            if ($select_result->num_rows > 0) {
                $update_query = "UPDATE actionLog SET log_status = 'Closed' WHERE reference_number = '$refer_number' AND log_status = 'Pending'";
                $update_result = mysqli_query($conn, $update_query);

                if ($update_result) {
                    echo "Log status updated to 'Closed' for reference number: $refer_number";
                } else {
                    echo "Failed to update log status.";
                }
            } else {
                echo "No records found in actionLog for reference number: $refer_number";
            }
        } else {
            echo "Error executing select query: " . mysqli_error($conn);
        }
    } else {
        echo "Please enter a reference number.";
    }
}
?>
<?php
if (isset($_POST['save1'])) {
    if (!empty($_POST['refer_number'])) {
        $refer_number = mysqli_real_escape_string($conn, $_POST['refer_number']);

        // Fetch the date_time from the billsPayment table
        $select_datetime = "SELECT date_time FROM billsPayment WHERE reference_number = '$refer_number'";
        $select_datetime_res = mysqli_query($conn, $select_datetime);
        
        if (!$select_datetime_res) {
            // Error handling for select query.
            echo "Select Query Error: " . mysqli_error($conn);
            // Handle the error appropriately (e.g., log it or display an error message)
        } else {
            // Fetch the result row from the query
            $row = mysqli_fetch_assoc($select_datetime_res);
            $date_time_str = $row['date_time'];

            // Convert $date_time_str to a DateTime object
            $date_time = DateTime::createFromFormat('Y-m-d h:i:s A', $date_time_str);

            if ($date_time === false) {
                // Error handling for invalid datetime format
                echo "Invalid datetime format: $date_time_str";
                // Handle the error appropriately (e.g., log it or display an error message)
            } else {
                // Format the DateTime object into a valid MySQL datetime string
                $formatted_date_time = $date_time->format('Y-m-d H:i:s');

                // Insert data from billsPayment_others table
                $insert1 = "INSERT INTO actionLog (status, date_time, control_number, reference_number, payor, address, account_number, account_name, amount_paid, charge_to_partner, charge_to_customer, contact_number, other_details, ml_outlet, region, operator, partner_name, partner_id, imported_date, imported_by) 
                            SELECT status, '$formatted_date_time', control_number, reference_number, payor, address, account_number, account_name, amount_paid, charge_to_partner, charge_to_customer, contact_number, other_details, ml_outlet, region, operator, partner_name, partner_id, imported_date, imported_by
                            FROM billsPayment
                            WHERE reference_number = '$refer_number'";
                $insert1_res = mysqli_query($conn, $insert1);

                if (!$insert1_res) {
                    // Error handling for insert query.
                    echo "Insert Query Error: " . mysqli_error($conn);
                    // Handle the error appropriately (e.g., log it or display an error message)
                } else {
                    $remark1 = mysqli_real_escape_string($conn, $_POST['remark1']);
                    $date1 = mysqli_real_escape_string($conn, $_POST['dateNow']);
                    $logged_date = mysqli_real_escape_string($conn, $_POST['dateNow']);
                    $remarkby = mysqli_real_escape_string($conn, $_POST['loggedby']);

                    // Update actionLog table with remarks, dates, and logged information
                    $update1 = "UPDATE actionLog SET remark1 = '$remark1', date1 = '$date1', remark_by1 = '$remarkby', log_status = 'Pending', logged_date = '$logged_date', logged_by = '$remarkby'
                                WHERE reference_number = '$refer_number'";
                    $update1_res = mysqli_query($conn, $update1);

                    if (!$update1_res) {
                        // Error handling for update query
                        echo "Update Query Error: " . mysqli_error($conn);
                        // Handle the error appropriately (e.g., log it or display an error message)
                    } else {
                        // Success message or further actions
                        echo "Successfully Saved!";
                    }
                }
            }
        }
    }
}
?>


    <?php
        if(isset($_POST['save2'])){
            if(!empty($_POST['refer_number'])){ 
                    $refer_number = $_POST['refer_number'];
                    $select = "SELECT * FROM billsPayment_others";
                    $select_result = mysqli_query($conn, $select);
                    if($select_result->num_rows > 0){

                        $remark2 =  mysqli_real_escape_string($conn, $_POST['remark2']);
                        $date2 =  mysqli_real_escape_string($conn, $_POST['dateNow']);
                        $remarkby =  mysqli_real_escape_string($conn, $_POST['loggedby']);

                        $update3 = "UPDATE actionLog SET remark2 = '$remark2' WHERE reference_number = '$refer_number'";
                        $update3_res = mysqli_query($conn, $update3);
                       
                        $update4 = "UPDATE actionLog SET date2 = '$date2' WHERE reference_number = '$refer_number'";
                        $update4_res = mysqli_query($conn, $update4);
                        
                        $update = "UPDATE actionLog SET remark_by2 = '$remarkby' WHERE reference_number = '$refer_number'";
                        $update_res = mysqli_query($conn, $update);

                        $update = "UPDATE actionLog SET log_status = 'Pending' WHERE reference_number = '$refer_number'";
                        $update_res = mysqli_query($conn, $update);

                    } 
                }
            }
    ?>
    <?php
        if(isset($_POST['save2'])){
            if(!empty($_POST['refer_number'])){ 
                    $refer_number = $_POST['refer_number'];
                    $select = "SELECT * FROM billsPayment";
                    $select_result = mysqli_query($conn, $select);
                    if($select_result->num_rows > 0){

                        $remark2 =  mysqli_real_escape_string($conn, $_POST['remark2']);
                        $date2 =  mysqli_real_escape_string($conn, $_POST['dateNow']);
                        $remarkby =  mysqli_real_escape_string($conn, $_POST['loggedby']);
                       
                        $update3 = "UPDATE actionLog SET remark2 = '$remark2' WHERE reference_number = '$refer_number'";
                        $update3_res = mysqli_query($conn, $update3);
                       
                        $update4 = "UPDATE actionLog SET date2 = '$date2' WHERE reference_number = '$refer_number'";
                        $update4_res = mysqli_query($conn, $update4);

                        $update = "UPDATE actionLog SET remark_by2 = '$remarkby' WHERE reference_number = '$refer_number'";
                        $update_res = mysqli_query($conn, $update);

                        $update = "UPDATE actionLog SET log_status = 'Pending' WHERE reference_number = '$refer_number'";
                        $update_res = mysqli_query($conn, $update);
                    } 
                }
            }
    ?>
     <?php
        if(isset($_POST['save3'])){
            if(!empty($_POST['refer_number'])){ 
                    $refer_number = $_POST['refer_number'];
                    $select = "SELECT * FROM billsPayment_others";
                    $select_result = mysqli_query($conn, $select);
                    if($select_result->num_rows > 0){

                        $remark3 =  mysqli_real_escape_string($conn, $_POST['remark3']);
                        $date3 = mysqli_real_escape_string($conn, $_POST['dateNow']);
                        $remarkby =  mysqli_real_escape_string($conn, $_POST['loggedby']);
                       
                        $update5 = "UPDATE actionLog SET remark3 = '$remark3' WHERE reference_number = '$refer_number'";
                        $update5_res = mysqli_query($conn, $update5);
                       
                        $update6 = "UPDATE actionLog SET date3 = '$date3' WHERE reference_number = '$refer_number'";
                        $update6_res = mysqli_query($conn, $update6);

                        $update = "UPDATE actionLog SET remark_by3 = '$remarkby' WHERE reference_number = '$refer_number'";
                        $update_res = mysqli_query($conn, $update);

                        $update = "UPDATE actionLog SET log_status = 'Pending' WHERE reference_number = '$refer_number'";
                        $update_res = mysqli_query($conn, $update);
                    } 
                }
            }
    ?>
    <?php
        if(isset($_POST['save3'])){
            if(!empty($_POST['refer_number'])){ 
                    $refer_number = $_POST['refer_number'];
                    $select = "SELECT * FROM billsPayment";
                    $select_result = mysqli_query($conn, $select);
                    if($select_result->num_rows > 0){

                        $remark3 =  mysqli_real_escape_string($conn, $_POST['remark3']);
                        $date3 = mysqli_real_escape_string($conn, $_POST['dateNow']);
                        $remarkby =  mysqli_real_escape_string($conn, $_POST['loggedby']);
                       
                        $update5 = "UPDATE actionLog SET remark3 = '$remark3' WHERE reference_number = '$refer_number'";
                        $update5_res = mysqli_query($conn, $update5);
                       
                        $update6 = "UPDATE actionLog SET date3 = '$date3' WHERE reference_number = '$refer_number'";
                        $update6_res = mysqli_query($conn, $update6);

                        $update = "UPDATE actionLog SET remark_by3 = '$remarkby' WHERE reference_number = '$refer_number'";
                        $update_res = mysqli_query($conn, $update);

                        $update = "UPDATE actionLog SET log_status = 'Pending' WHERE reference_number = '$refer_number'";
                        $update_res = mysqli_query($conn, $update);
                    } 
                }
            }
    ?>
     <?php
        if(isset($_POST['save4'])){
            if(!empty($_POST['refer_number'])){ 
                    $refer_number = $_POST['refer_number'];
                    $select = "SELECT * FROM billsPayment_others";
                    $select_result = mysqli_query($conn, $select);
                    if($select_result->num_rows > 0){

                        $remark4 =  mysqli_real_escape_string($conn, $_POST['remark4']);
                        $date4 = mysqli_real_escape_string($conn, $_POST['dateNow']);
                        $remarkby =  mysqli_real_escape_string($conn, $_POST['loggedby']);
                       
                        $update7 = "UPDATE actionLog SET remark4 = '$remark4' WHERE reference_number = '$refer_number'";
                        $update7_res = mysqli_query($conn, $update7);
                       
                        $update8 = "UPDATE actionLog SET date4 = '$date4' WHERE reference_number = '$refer_number'";
                        $update8_res = mysqli_query($conn, $update8);

                        $update = "UPDATE actionLog SET remark_by4= '$remarkby' WHERE reference_number = '$refer_number'";
                        $update_res = mysqli_query($conn, $update);

                        $update = "UPDATE actionLog SET log_status = 'Pending' WHERE reference_number = '$refer_number'";
                        $update_res = mysqli_query($conn, $update);
                    } 
                }
            }
    ?>
    <?php
        if(isset($_POST['save4'])){
            if(!empty($_POST['refer_number'])){ 
                    $refer_number = $_POST['refer_number'];
                    $select = "SELECT * FROM billsPayment";
                    $select_result = mysqli_query($conn, $select);
                    if($select_result->num_rows > 0){

                        $remark4 =  mysqli_real_escape_string($conn, $_POST['remark4']);
                        $date4 = mysqli_real_escape_string($conn, $_POST['dateNow']);
                        $remarkby =  mysqli_real_escape_string($conn, $_POST['loggedby']);
                       
                        $update7 = "UPDATE actionLog SET remark4 = '$remark4' WHERE reference_number = '$refer_number'";
                        $update7_res = mysqli_query($conn, $update7);
                       
                        $update8 = "UPDATE actionLog SET date4 = '$date4' WHERE reference_number = '$refer_number'";
                        $update8_res = mysqli_query($conn, $update8);

                        $update = "UPDATE actionLog SET remark_by4= '$remarkby' WHERE reference_number = '$refer_number'";
                        $update_res = mysqli_query($conn, $update);

                        $update = "UPDATE actionLog SET log_status = 'Pending' WHERE reference_number = '$refer_number'";
                        $update_res = mysqli_query($conn, $update);
                    } 
                }
            }
    ?>
     <?php
        if(isset($_POST['save5'])){
            if(!empty($_POST['refer_number'])){ 
                    $refer_number = $_POST['refer_number'];
                    $select = "SELECT * FROM billsPayment_others";
                    $select_result = mysqli_query($conn, $select);
                    if($select_result->num_rows > 0){

                        $remark5 =  mysqli_real_escape_string($conn, $_POST['remark5']);
                        $date5 = mysqli_real_escape_string($conn, $_POST['dateNow']);
                        $remarkby =  mysqli_real_escape_string($conn, $_POST['loggedby']);
                       
                        $update9 = "UPDATE actionLog SET remark5 = '$remark5' WHERE reference_number = '$refer_number'";
                        $update9_res = mysqli_query($conn, $update9);
                       
                        $update10 = "UPDATE actionLog SET date5 = '$date5' WHERE reference_number = '$refer_number'";
                        $update10_res = mysqli_query($conn, $update10);

                        $update = "UPDATE actionLog SET remark_by5= '$remarkby' WHERE reference_number = '$refer_number'";
                        $update_res = mysqli_query($conn, $update);

                        $update = "UPDATE actionLog SET log_status = 'Pending' WHERE reference_number = '$refer_number'";
                        $update_res = mysqli_query($conn, $update);
                    } 
                }
            }
    ?>
    <?php
        if(isset($_POST['save5'])){
            if(!empty($_POST['refer_number'])){ 
                    $refer_number = $_POST['refer_number'];
                    $select = "SELECT * FROM billsPayment";
                    $select_result = mysqli_query($conn, $select);
                    if($select_result->num_rows > 0){

                        $remark5 =  mysqli_real_escape_string($conn, $_POST['remark5']);
                        $date5 = mysqli_real_escape_string($conn, $_POST['dateNow']);
                        $remarkby =  mysqli_real_escape_string($conn, $_POST['loggedby']);
                       
                        $update9 = "UPDATE actionLog SET remark5 = '$remark5' WHERE reference_number = '$refer_number'";
                        $update9_res = mysqli_query($conn, $update9);
                       
                        $update10 = "UPDATE actionLog SET date5 = '$date5' WHERE reference_number = '$refer_number'";
                        $update10_res = mysqli_query($conn, $update10);

                        $update = "UPDATE actionLog SET remark_by5= '$remarkby' WHERE reference_number = '$refer_number'";
                        $update_res = mysqli_query($conn, $update);

                        $update = "UPDATE actionLog SET log_status = 'Pending' WHERE reference_number = '$refer_number'";
                        $update_res = mysqli_query($conn, $update);
                    } 
                }
            }
    ?>
    <?php
        if(isset($_POST['save6'])){
            if(!empty($_POST['refer_number'])){ 
                    $refer_number = $_POST['refer_number'];
                    $select = "SELECT * FROM billsPayment_others";
                    $select_result = mysqli_query($conn, $select);
                    if($select_result->num_rows > 0){

                        $remark6 =  mysqli_real_escape_string($conn, $_POST['remark6']);
                        $date6 = mysqli_real_escape_string($conn, $_POST['dateNow']);
                        $remarkby =  mysqli_real_escape_string($conn, $_POST['loggedby']);
                       
                        $update11 = "UPDATE actionLog SET remark6 = '$remark6' WHERE reference_number = '$refer_number'";
                        $update11_res = mysqli_query($conn, $update11);
                       
                        $update12 = "UPDATE actionLog SET date6 = '$date6' WHERE reference_number = '$refer_number'";
                        $update12_res = mysqli_query($conn, $update12);

                        $update = "UPDATE actionLog SET remark_by6= '$remarkby' WHERE reference_number = '$refer_number'";
                        $update_res = mysqli_query($conn, $update);

                        $update = "UPDATE actionLog SET log_status = 'Pending' WHERE reference_number = '$refer_number'";
                        $update_res = mysqli_query($conn, $update);
                    } 
                }
            }
    ?>
    <?php
        if(isset($_POST['save6'])){
            if(!empty($_POST['refer_number'])){ 
                    $refer_number = $_POST['refer_number'];
                    $select = "SELECT * FROM billsPayment";
                    $select_result = mysqli_query($conn, $select);
                    if($select_result->num_rows > 0){

                        $remark6 =  mysqli_real_escape_string($conn, $_POST['remark6']);
                        $date6 = mysqli_real_escape_string($conn, $_POST['dateNow']);
                        $remarkby =  mysqli_real_escape_string($conn, $_POST['loggedby']);
                       
                        $update11 = "UPDATE actionLog SET remark6 = '$remark6' WHERE reference_number = '$refer_number'";
                        $update11_res = mysqli_query($conn, $update11);
                       
                        $update12 = "UPDATE actionLog SET date6 = '$date6' WHERE reference_number = '$refer_number'";
                        $update12_res = mysqli_query($conn, $update12);

                        $update = "UPDATE actionLog SET remark_by6= '$remarkby' WHERE reference_number = '$refer_number'";
                        $update_res = mysqli_query($conn, $update);

                        $update = "UPDATE actionLog SET log_status = 'Pending' WHERE reference_number = '$refer_number'";
                        $update_res = mysqli_query($conn, $update);
                    } 
                }
            }
    ?>
    <?php
        if(isset($_POST['save7'])){
            if(!empty($_POST['refer_number'])){ 
                    $refer_number = $_POST['refer_number'];
                    $select = "SELECT * FROM billsPayment_others";
                    $select_result = mysqli_query($conn, $select);
                    if($select_result->num_rows > 0){

                        $remark7 =  mysqli_real_escape_string($conn, $_POST['remark7']);
                        $date7 = mysqli_real_escape_string($conn, $_POST['dateNow']);
                        $remarkby =  mysqli_real_escape_string($conn, $_POST['loggedby']);
                       
                        $update13 = "UPDATE actionLog SET remark7 = '$remark7' WHERE reference_number = '$refer_number'";
                        $update13_res = mysqli_query($conn, $update13);
                       
                        $update14 = "UPDATE actionLog SET date7 = '$date7' WHERE reference_number = '$refer_number'";
                        $update14_res = mysqli_query($conn, $update14);

                        $update = "UPDATE actionLog SET remark_by7= '$remarkby' WHERE reference_number = '$refer_number'";
                        $update_res = mysqli_query($conn, $update);

                        $update = "UPDATE actionLog SET log_status = 'Pending' WHERE reference_number = '$refer_number'";
                        $update_res = mysqli_query($conn, $update);
                    } 
                }
            }
    ?>
    <?php
        if(isset($_POST['save7'])){
            if(!empty($_POST['refer_number'])){ 
                    $refer_number = $_POST['refer_number'];
                    $select = "SELECT * FROM billsPayment";
                    $select_result = mysqli_query($conn, $select);
                    if($select_result->num_rows > 0){

                        $remark7 =  mysqli_real_escape_string($conn, $_POST['remark7']);
                        $date7 = mysqli_real_escape_string($conn, $_POST['dateNow']);
                        $remarkby =  mysqli_real_escape_string($conn, $_POST['loggedby']);
                       
                        $update13 = "UPDATE actionLog SET remark7 = '$remark7' WHERE reference_number = '$refer_number'";
                        $update13_res = mysqli_query($conn, $update13);
                       
                        $update14 = "UPDATE actionLog SET date7 = '$date7' WHERE reference_number = '$refer_number'";
                        $update14_res = mysqli_query($conn, $update14);

                        $update = "UPDATE actionLog SET remark_by7= '$remarkby' WHERE reference_number = '$refer_number'";
                        $update_res = mysqli_query($conn, $update);

                        $update = "UPDATE actionLog SET log_status = 'Pending' WHERE reference_number = '$refer_number'";
                        $update_res = mysqli_query($conn, $update);
                    } 
                }
            }
    ?>
     <?php
        if(isset($_POST['save8'])){
            if(!empty($_POST['refer_number'])){ 
                    $refer_number = $_POST['refer_number'];
                    $select = "SELECT * FROM billsPayment_others";
                    $select_result = mysqli_query($conn, $select);
                    if($select_result->num_rows > 0){

                        $remark8 =  mysqli_real_escape_string($conn, $_POST['remark8']);
                        $date8 = mysqli_real_escape_string($conn, $_POST['dateNow']);
                        $remarkby =  mysqli_real_escape_string($conn, $_POST['loggedby']);
                       
                        $update15 = "UPDATE actionLog SET remark8 = '$remark8' WHERE reference_number = '$refer_number'";
                        $update15_res = mysqli_query($conn, $update15);
                       
                        $update16 = "UPDATE actionLog SET date8 = '$date8' WHERE reference_number = '$refer_number'";
                        $update16_res = mysqli_query($conn, $update16);

                        $update = "UPDATE actionLog SET remark_by8= '$remarkby' WHERE reference_number = '$refer_number'";
                        $update_res = mysqli_query($conn, $update);

                        $update = "UPDATE actionLog SET log_status = 'Pending' WHERE reference_number = '$refer_number'";
                        $update_res = mysqli_query($conn, $update);
                    } 
                }
            }
    ?>
     <?php
        if(isset($_POST['save8'])){
            if(!empty($_POST['refer_number'])){ 
                    $refer_number = $_POST['refer_number'];
                    $select = "SELECT * FROM billsPayment";
                    $select_result = mysqli_query($conn, $select);
                    if($select_result->num_rows > 0){

                        $remark8 =  mysqli_real_escape_string($conn, $_POST['remark8']);
                        $date8 = mysqli_real_escape_string($conn, $_POST['dateNow']);
                        $remarkby =  mysqli_real_escape_string($conn, $_POST['loggedby']);
                       
                        $update15 = "UPDATE actionLog SET remark8 = '$remark8' WHERE reference_number = '$refer_number'";
                        $update15_res = mysqli_query($conn, $update15);
                       
                        $update16 = "UPDATE actionLog SET date8 = '$date8' WHERE reference_number = '$refer_number'";
                        $update16_res = mysqli_query($conn, $update16);

                        $update = "UPDATE actionLog SET remark_by8= '$remarkby' WHERE reference_number = '$refer_number'";
                        $update_res = mysqli_query($conn, $update);

                        $update = "UPDATE actionLog SET log_status = 'Pending' WHERE reference_number = '$refer_number'";
                        $update_res = mysqli_query($conn, $update);
                    } 
                }
            }
    ?>
     <?php
        if(isset($_POST['save9'])){
            if(!empty($_POST['refer_number'])){ 
                    $refer_number = $_POST['refer_number'];
                    $select = "SELECT * FROM billsPayment_others";
                    $select_result = mysqli_query($conn, $select);
                    if($select_result->num_rows > 0){

                        $remark9 =  mysqli_real_escape_string($conn, $_POST['remark9']);
                        $date9 = mysqli_real_escape_string($conn, $_POST['dateNow']);
                        $remarkby =  mysqli_real_escape_string($conn, $_POST['loggedby']);
                       
                        $update17 = "UPDATE actionLog SET remark9 = '$remark9' WHERE reference_number = '$refer_number'";
                        $update17_res = mysqli_query($conn, $update17);
                       
                        $update18 = "UPDATE actionLog SET date9 = '$date9' WHERE reference_number = '$refer_number'";
                        $update18_res = mysqli_query($conn, $update18);

                        $update = "UPDATE actionLog SET remark_by9= '$remarkby' WHERE reference_number = '$refer_number'";
                        $update_res = mysqli_query($conn, $update);

                        $update = "UPDATE actionLog SET log_status = 'Pending' WHERE reference_number = '$refer_number'";
                        $update_res = mysqli_query($conn, $update);
                    } 
                }
            }
    ?>
    <?php
        if(isset($_POST['save9'])){
            if(!empty($_POST['refer_number'])){ 
                    $refer_number = $_POST['refer_number'];
                    $select = "SELECT * FROM billsPayment";
                    $select_result = mysqli_query($conn, $select);
                    if($select_result->num_rows > 0){

                        $remark9 =  mysqli_real_escape_string($conn, $_POST['remark9']);
                        $date9 = mysqli_real_escape_string($conn, $_POST['dateNow']);
                        $remarkby =  mysqli_real_escape_string($conn, $_POST['loggedby']);
                       
                        $update17 = "UPDATE actionLog SET remark9 = '$remark9' WHERE reference_number = '$refer_number'";
                        $update17_res = mysqli_query($conn, $update17);
                       
                        $update18 = "UPDATE actionLog SET date9 = '$date9' WHERE reference_number = '$refer_number'";
                        $update18_res = mysqli_query($conn, $update18);

                        $update = "UPDATE actionLog SET remark_by9= '$remarkby' WHERE reference_number = '$refer_number'";
                        $update_res = mysqli_query($conn, $update);

                        $update = "UPDATE actionLog SET log_status = 'Pending' WHERE reference_number = '$refer_number'";
                        $update_res = mysqli_query($conn, $update);
                    } 
                }
            }
    ?>
    <?php
        if(isset($_POST['save10'])){
            if(!empty($_POST['refer_number'])){ 
                    $refer_number = $_POST['refer_number'];
                    $select = "SELECT * FROM billsPayment_others";
                    $select_result = mysqli_query($conn, $select);
                    if($select_result->num_rows > 0){

                        $remark10 =  mysqli_real_escape_string($conn, $_POST['remark10']);
                        $date10 = mysqli_real_escape_string($conn, $_POST['dateNow']);
                        $remarkby =  mysqli_real_escape_string($conn, $_POST['loggedby']);
                       
                        $update19 = "UPDATE actionLog SET remark10 = '$remark10' WHERE reference_number = '$refer_number'";
                        $update19_res = mysqli_query($conn, $update19);
                       
                        $update20 = "UPDATE actionLog SET date10 = '$date10' WHERE reference_number = '$refer_number'";
                        $update20_res = mysqli_query($conn, $update20);

                        $update = "UPDATE actionLog SET remark_by10= '$remarkby' WHERE reference_number = '$refer_number'";
                        $update_res = mysqli_query($conn, $update);

                        $update = "UPDATE actionLog SET log_status = 'Pending' WHERE reference_number = '$refer_number'";
                        $update_res = mysqli_query($conn, $update);
                    } 
                }
            }
    ?>
    <?php
        if(isset($_POST['save10'])){
            if(!empty($_POST['refer_number'])){ 
                    $refer_number = $_POST['refer_number'];
                    $select = "SELECT * FROM billsPayment";
                    $select_result = mysqli_query($conn, $select);
                    if($select_result->num_rows > 0){

                        $remark10 =  mysqli_real_escape_string($conn, $_POST['remark10']);
                        $date10 = mysqli_real_escape_string($conn, $_POST['dateNow']);
                        $remarkby =  mysqli_real_escape_string($conn, $_POST['loggedby']);
                       
                        $update19 = "UPDATE actionLog SET remark10 = '$remark10' WHERE reference_number = '$refer_number'";
                        $update19_res = mysqli_query($conn, $update19);
                       
                        $update20 = "UPDATE actionLog SET date10 = '$date10' WHERE reference_number = '$refer_number'";
                        $update20_res = mysqli_query($conn, $update20);

                        $update = "UPDATE actionLog SET remark_by10= '$remarkby' WHERE reference_number = '$refer_number'";
                        $update_res = mysqli_query($conn, $update);

                        $update = "UPDATE actionLog SET log_status = 'Pending' WHERE reference_number = '$refer_number'";
                        $update_res = mysqli_query($conn, $update);
                    } 
                }
            }
    ?>
    <div id="view-modal1" class="view-modal1">
        <!-- view Remark billsPayment_other Modal content -->
        <div class="view-modal-content">
        <div class="view-close-div">
            <span class="view-close1">&times;</span>
        </div>
        <center> <h3>View Remark</h3> </center>
            <textarea readonly class="view-remark" name="view-remark1" id="view-remark1"><?php if(!empty($_POST['refer_number'])){ $refer_number = $_POST['refer_number']; $query="SELECT * FROM actionLog WHERE reference_number = '$refer_number'";$result = mysqli_query($conn,$query);if($result){while($row = mysqli_fetch_assoc($result)){echo $row['remark1'];}}}?></textarea>
        </div>
    </div>

    <div id="view-modal2" class="view-modal2">
        <!-- view Remark billsPayment_other Modal content -->
        <div class="view-modal-content">
        <div class="view-close-div">
            <span class="view-close2">&times;</span>
        </div>
        <center> <h3>View Remark</h3> </center>
            <textarea readonly class="view-remark" name="view-remark2" id="view-remark2"><?php if(!empty($_POST['refer_number'])){ $refer_number = $_POST['refer_number']; $query="SELECT * FROM actionLog WHERE reference_number = '$refer_number'";$result = mysqli_query($conn,$query);if($result){while($row = mysqli_fetch_assoc($result)){echo $row['remark2'];}}}?></textarea>
        </div>
    </div>

    
    <div id="view-modal3" class="view-modal3">
        <!-- view Remark billsPayment_other Modal content -->
        <div class="view-modal-content">
        <div class="view-close-div">
            <span class="view-close3">&times;</span>
        </div>
        <center> <h3>View Remark</h3> </center>
            <textarea readonly class="view-remark" name="view-remark3" id="view-remark3"><?php if(!empty($_POST['refer_number'])){ $refer_number = $_POST['refer_number']; $query="SELECT * FROM actionLog WHERE reference_number = '$refer_number'";$result = mysqli_query($conn,$query);if($result){while($row = mysqli_fetch_assoc($result)){echo $row['remark3'];}}}?></textarea>
        </div>
    </div>

    <div id="view-modal4" class="view-modal4">
        <!-- view Remark billsPayment_other Modal content -->
        <div class="view-modal-content">
        <div class="view-close-div">
            <span class="view-close4">&times;</span>
        </div>
        <center> <h3>View Remark</h3> </center>
            <textarea readonly class="view-remark" name="view-remark4" id="view-remark4"><?php if(!empty($_POST['refer_number'])){ $refer_number = $_POST['refer_number']; $query="SELECT * FROM actionLog WHERE reference_number = '$refer_number'";$result = mysqli_query($conn,$query);if($result){while($row = mysqli_fetch_assoc($result)){echo $row['remark4'];}}}?></textarea>
        </div>
    </div>
    
    <div id="view-modal5" class="view-modal5">
        <!-- view Remark billsPayment_other Modal content -->
        <div class="view-modal-content">
        <div class="view-close-div">
            <span class="view-close5">&times;</span>
        </div>
        <center> <h3>View Remark</h3> </center>
            <textarea readonly class="view-remark" name="view-remark5" id="view-remark5"><?php if(!empty($_POST['refer_number'])){ $refer_number = $_POST['refer_number']; $query="SELECT * FROM actionLog WHERE reference_number = '$refer_number'";$result = mysqli_query($conn,$query);if($result){while($row = mysqli_fetch_assoc($result)){echo $row['remark5'];}}}?></textarea>
        </div>
    </div>

    
    <div id="view-modal6" class="view-modal6">
        <!-- view Remark billsPayment_other Modal content -->
        <div class="view-modal-content">
        <div class="view-close-div">
            <span class="view-close6">&times;</span>
        </div>
        <center> <h3>View Remark</h3> </center>
            <textarea readonly class="view-remark" name="view-remark6" id="view-remark6"><?php if(!empty($_POST['refer_number'])){ $refer_number = $_POST['refer_number']; $query="SELECT * FROM actionLog WHERE reference_number = '$refer_number'";$result = mysqli_query($conn,$query);if($result){while($row = mysqli_fetch_assoc($result)){echo $row['remark6'];}}}?></textarea>
        </div>
    </div>

    <div id="view-modal7" class="view-modal7">
        <!-- view Remark billsPayment_other Modal content -->
        <div class="view-modal-content">
        <div class="view-close-div">
            <span class="view-close7">&times;</span>
        </div>
        <center> <h3>View Remark</h3> </center>
            <textarea readonly class="view-remark" name="view-remark7" id="view-remark7"><?php if(!empty($_POST['refer_number'])){ $refer_number = $_POST['refer_number']; $query="SELECT * FROM actionLog WHERE reference_number = '$refer_number'";$result = mysqli_query($conn,$query);if($result){while($row = mysqli_fetch_assoc($result)){echo $row['remark7'];}}}?></textarea>
        </div>
    </div>
    
    <div id="view-modal8" class="view-modal8">
        <!-- view Remark billsPayment_other Modal content -->
        <div class="view-modal-content">
        <div class="view-close-div">
            <span class="view-close8">&times;</span>
        </div>
        <center> <h3>View Remark</h3> </center>
            <textarea readonly class="view-remark" name="view-remark8" id="view-remark8"><?php if(!empty($_POST['refer_number'])){ $refer_number = $_POST['refer_number']; $query="SELECT * FROM actionLog WHERE reference_number = '$refer_number'";$result = mysqli_query($conn,$query);if($result){while($row = mysqli_fetch_assoc($result)){echo $row['remark8'];}}}?></textarea>
        </div>
    </div>

    <div id="view-modal9" class="view-modal9">
        <!-- view Remark billsPayment_other Modal content -->
        <div class="view-modal-content">
        <div class="view-close-div">
            <span class="view-close9">&times;</span>
        </div>
        <center> <h3>View Remark</h3> </center>
            <textarea readonly class="view-remark" name="view-remark9" id="view-remark9"><?php if(!empty($_POST['refer_number'])){ $refer_number = $_POST['refer_number']; $query="SELECT * FROM actionLog WHERE reference_number = '$refer_number'";$result = mysqli_query($conn,$query);if($result){while($row = mysqli_fetch_assoc($result)){echo $row['remark9'];}}}?></textarea>
        </div>
    </div>

    <div id="view-modal10" class="view-modal10">
        <!-- view Remark billsPayment_other Modal content -->
        <div class="view-modal-content">
        <div class="view-close-div">
            <span class="view-close10">&times;</span>
        </div>
        <center> <h3>View Remark</h3> </center>
            <textarea readonly class="view-remark" name="view-remark10" id="view-remark10"><?php if(!empty($_POST['refer_number'])){ $refer_number = $_POST['refer_number']; $query="SELECT * FROM actionLog WHERE reference_number = '$refer_number'";$result = mysqli_query($conn,$query);if($result){while($row = mysqli_fetch_assoc($result)){echo $row['remark10'];}}}?></textarea>
        </div>
    </div>
<!-- The Add Remark billsPayment_other Modal -->
 <div id="add-modal" class="add-modal">
        <!-- Add Remark billsPayment_other Modal content -->
        <div class="add-modal-content">
        <input style="display:none;" type="text" name="loggedby" value="<?php echo $_SESSION['user_name']?>" readonly required>
        <input style="display:none;" type="date" class="text" id="date" name="dateNow" value="<?php echo date('Y-m-d'); ?>" readonly>

            <div class="add-close-div">
                <span onclick="clearRemarks()" class="add-close">&times;</span>
            </div>
            <center><h2>Remarks</h2><h3 style="color:#d70c0c;"><?php if(isset($_POST['refer_number'])) echo $_POST['refer_number']; ?></h3></center>
            <input type="button" name="add-remark" id="addRemark" value="Add Remarks">
            <div class="add-remarks-content">
                <div class="add-remarks">
                    <textarea maxlength="500" autofocus disabled onkeyup="enabled()" onkeyup="enabled()" name="remark1" class="remark" id="remark1" placeholder="Type here... (Maximum of 500 characters)" value=""><?php if(!empty($_POST['refer_number'])){ $refer_number = $_POST['refer_number']; $query="SELECT * FROM actionLog WHERE reference_number = '$refer_number'";$result = mysqli_query($conn,$query);if($result){while($row = mysqli_fetch_assoc($result)){echo $row['remark1'];}}}?></textarea>
                </div>
                <div class="add-div">
                    <input type="text" class="date" id="date1" name="date1" placeholder="<?php echo date('Y-m-d'); ?>" value="<?php if(!empty($_POST['refer_number'])){ $refer_number = $_POST['refer_number']; $query="SELECT * FROM actionLog WHERE reference_number = '$refer_number'";$result = mysqli_query($conn,$query);if($result){while($row = mysqli_fetch_assoc($result)){echo $row['date1'];}}}?>" disabled readonly>
                    <input type="text" class="name" id="name1" name="name1" value="<?php if(!empty($_POST['refer_number'])){ $refer_number = $_POST['refer_number']; $query="SELECT * FROM actionLog WHERE reference_number = '$refer_number'";$result = mysqli_query($conn,$query);if($result){while($row = mysqli_fetch_assoc($result)){echo $row['remark_by1'];}}}?>" placeholder="Remark by" disabled readonly>
                </div>
                <div class="modal-button-view">
                    <input type="button" class="clear" name="clear1" id="clear1" value="Clear" disabled>
                    <input type="button" class="view" name="view1" id="view1" value="View">
                    <input type="submit" class="save" name="save1" id="save1" value="Save" disabled>
                </div>
            </div>
                     
            <div class="add-remarks-content">
                <div class="add-remarks">
                    <textarea maxlength="500" autofocus disabled onkeyup="enabled()" name="remark2" class="remark" id="remark2" placeholder="Type here... (Maximum of 500 characters)"><?php if(!empty($_POST['refer_number'])){ $refer_number = $_POST['refer_number']; $query="SELECT * FROM actionLog WHERE reference_number = '$refer_number'";$result = mysqli_query($conn,$query);if($result){while($row = mysqli_fetch_assoc($result)){echo $row['remark2'];}}}?></textarea>
                </div>
                <div class="add-div">
                    <input type="text" class="date" id="date2" name="date2" placeholder="<?php echo date('Y-m-d'); ?>" value="<?php if(!empty($_POST['refer_number'])){ $refer_number = $_POST['refer_number']; $query="SELECT * FROM actionLog WHERE reference_number = '$refer_number'";$result = mysqli_query($conn,$query);if($result){while($row = mysqli_fetch_assoc($result)){echo $row['date2'];}}}?>" disabled readonly>
                    <input type="text" class="name" id="name2" name="name2" value="<?php if(!empty($_POST['refer_number'])){ $refer_number = $_POST['refer_number']; $query="SELECT * FROM actionLog WHERE reference_number = '$refer_number'";$result = mysqli_query($conn,$query);if($result){while($row = mysqli_fetch_assoc($result)){echo $row['remark_by2'];}}}?>" placeholder="Remark by" disabled readonly>
                </div>
                <div class="modal-button-view">
                    <input type="button" class="clear" name="clear2" id="clear2" value="Clear" disabled>
                    <input type="button" class="view" name="view2" id="view2" value="View">
                    <input type="submit" class="save" name="save2" id="save2" value="Save" disabled>
                </div>
            </div>
              
            <div class="add-remarks-content">
                <div class="add-remarks">
                    <textarea maxlength="500" autofocus disabled onkeyup="enabled()" name="remark3" class="remark" id="remark3" placeholder="Type here... (Maximum of 500 characters)"><?php if(!empty($_POST['refer_number'])){ $refer_number = $_POST['refer_number']; $query="SELECT * FROM actionLog WHERE reference_number = '$refer_number'";$result = mysqli_query($conn,$query);if($result){while($row = mysqli_fetch_assoc($result)){echo $row['remark3'];}}}?></textarea>
                </div>
                <div class="add-div">
                    <input type="text" class="date" id="date3" name="date3" placeholder="<?php echo date('Y-m-d'); ?>" value="<?php if(!empty($_POST['refer_number'])){ $refer_number = $_POST['refer_number']; $query="SELECT * FROM actionLog WHERE reference_number = '$refer_number'";$result = mysqli_query($conn,$query);if($result){while($row = mysqli_fetch_assoc($result)){echo $row['date3'];}}}?>" disabled readonly>
                    <input type="text" class="name" id="name3" name="name3" value="<?php if(!empty($_POST['refer_number'])){ $refer_number = $_POST['refer_number']; $query="SELECT * FROM actionLog WHERE reference_number = '$refer_number'";$result = mysqli_query($conn,$query);if($result){while($row = mysqli_fetch_assoc($result)){echo $row['remark_by3'];}}}?>" placeholder="Remark by" disabled readonly>
                </div>
                <div class="modal-button-view">
                    <input type="button" class="clear" name="clear3" id="clear3" value="Clear" disabled>
                    <input type="button" class="view" name="view3" id="view3" value="View">                  
                    <input type="submit" class="save" name="save3" id="save3" value="Save" disabled>
                </div>
            </div>
              
            <div class="add-remarks-content">
                <div class="add-remarks">
                    <textarea maxlength="500" autofocus disabled onkeyup="enabled()" name="remark4" class="remark" id="remark4" placeholder="Type here... (Maximum of 500 characters)"><?php if(!empty($_POST['refer_number'])){ $refer_number = $_POST['refer_number']; $query="SELECT * FROM actionLog WHERE reference_number = '$refer_number'";$result = mysqli_query($conn,$query);if($result){while($row = mysqli_fetch_assoc($result)){echo $row['remark4'];}}}?></textarea>
                </div>
                <div class="add-div">
                    <input type="text" class="date" id="date4" name="date4" placeholder="<?php echo date('Y-m-d'); ?>" value="<?php if(!empty($_POST['refer_number'])){ $refer_number = $_POST['refer_number']; $query="SELECT * FROM actionLog WHERE reference_number = '$refer_number'";$result = mysqli_query($conn,$query);if($result){while($row = mysqli_fetch_assoc($result)){echo $row['date4'];}}}?>" disabled readonly>
                    <input type="text" class="name" id="name4" name="name4" value="<?php if(!empty($_POST['refer_number'])){ $refer_number = $_POST['refer_number']; $query="SELECT * FROM actionLog WHERE reference_number = '$refer_number'";$result = mysqli_query($conn,$query);if($result){while($row = mysqli_fetch_assoc($result)){echo $row['remark_by4'];}}}?>" placeholder="Remark by" disabled readonly>
                </div>
                <div class="modal-button-view">
                    <input type="button" class="clear" name="clear4" id="clear4" value="Clear" disabled>
                    <input type="button" class="view" name="view4" id="view4" value="View">
                    <input type="submit" class="save" name="save4" id="save4" value="Save" disabled>
                </div>
            </div>
              
            <div class="add-remarks-content">
                <div class="add-remarks">
                    <textarea maxlength="500" autofocus disabled onkeyup="enabled()" name="remark5" class="remark" id="remark5" placeholder="Type here... (Maximum of 500 characters)"><?php if(!empty($_POST['refer_number'])){ $refer_number = $_POST['refer_number']; $query="SELECT * FROM actionLog WHERE reference_number = '$refer_number'";$result = mysqli_query($conn,$query);if($result){while($row = mysqli_fetch_assoc($result)){echo $row['remark5'];}}}?></textarea>
                </div>
                <div class="add-div">
                    <input type="text" class="date" id="date5" name="date5" placeholder="<?php echo date('Y-m-d'); ?>" value="<?php if(!empty($_POST['refer_number'])){ $refer_number = $_POST['refer_number']; $query="SELECT * FROM actionLog WHERE reference_number = '$refer_number'";$result = mysqli_query($conn,$query);if($result){while($row = mysqli_fetch_assoc($result)){echo $row['date5'];}}}?>" disabled readonly>
                    <input type="text" class="name" id="name5" name="name5" value="<?php if(!empty($_POST['refer_number'])){ $refer_number = $_POST['refer_number']; $query="SELECT * FROM actionLog WHERE reference_number = '$refer_number'";$result = mysqli_query($conn,$query);if($result){while($row = mysqli_fetch_assoc($result)){echo $row['remark_by5'];}}}?>" placeholder="Remark by" disabled readonly>
                </div>
                <div class="modal-button-view">
                    <input type="button" class="clear" name="clear5" id="clear5" value="Clear" disabled>
                    <input type="button" class="view" name="view5" id="view5" value="View">
                    <input type="submit" class="save" name="save5" id="save5" value="Save" disabled>
                </div>
            </div>
              
            <div class="add-remarks-content">
                <div class="add-remarks">
                    <textarea maxlength="500" autofocus disabled onkeyup="enabled()" name="remark6" class="remark" id="remark6" placeholder="Type here... (Maximum of 500 characters)"><?php if(!empty($_POST['refer_number'])){ $refer_number = $_POST['refer_number']; $query="SELECT * FROM actionLog WHERE reference_number = '$refer_number'";$result = mysqli_query($conn,$query);if($result){while($row = mysqli_fetch_assoc($result)){echo $row['remark6'];}}}?></textarea>
                </div>
                <div class="add-div">
                    <input type="text" class="date" id="date6" name="date6" placeholder="<?php echo date('Y-m-d'); ?>" value="<?php if(!empty($_POST['refer_number'])){ $refer_number = $_POST['refer_number']; $query="SELECT * FROM actionLog WHERE reference_number = '$refer_number'";$result = mysqli_query($conn,$query);if($result){while($row = mysqli_fetch_assoc($result)){echo $row['date6'];}}}?>" disabled readonly>
                    <input type="text" class="name" id="name6" name="name6" value="<?php if(!empty($_POST['refer_number'])){ $refer_number = $_POST['refer_number']; $query="SELECT * FROM actionLog WHERE reference_number = '$refer_number'";$result = mysqli_query($conn,$query);if($result){while($row = mysqli_fetch_assoc($result)){echo $row['remark_by6'];}}}?>" placeholder="Remark by" disabled readonly>
                </div>
                <div class="modal-button-view">
                    <input type="button" class="clear" name="clear6" id="clear6" value="Clear" disabled>
                    <input type="button" class="view" name="view6" id="view6" value="View">
                    <input type="submit" class="save" name="save6" id="save6" value="Save" disabled>
                </div>
            </div>
              
            <div class="add-remarks-content">
                <div class="add-remarks">
                    <textarea maxlength="500" autofocus disabled onkeyup="enabled()" name="remark7" class="remark" id="remark7" placeholder="Type here... (Maximum of 500 characters)"><?php if(!empty($_POST['refer_number'])){ $refer_number = $_POST['refer_number']; $query="SELECT * FROM actionLog WHERE reference_number = '$refer_number'";$result = mysqli_query($conn,$query);if($result){while($row = mysqli_fetch_assoc($result)){echo $row['remark7'];}}}?></textarea>
                </div>
                <div class="add-div">
                    <input type="text" class="date" id="date7" name="date7" placeholder="<?php echo date('Y-m-d'); ?>" value="<?php if(!empty($_POST['refer_number'])){ $refer_number = $_POST['refer_number']; $query="SELECT * FROM actionLog WHERE reference_number = '$refer_number'";$result = mysqli_query($conn,$query);if($result){while($row = mysqli_fetch_assoc($result)){echo $row['date7'];}}}?>" disabled readonly>
                    <input type="text" class="name" id="name7" name="name7" value="<?php if(!empty($_POST['refer_number'])){ $refer_number = $_POST['refer_number']; $query="SELECT * FROM actionLog WHERE reference_number = '$refer_number'";$result = mysqli_query($conn,$query);if($result){while($row = mysqli_fetch_assoc($result)){echo $row['remark_by7'];}}}?>" placeholder="Remark by" disabled readonly>
                </div>
                <div class="modal-button-view">
                    <input type="button" class="clear" name="clear7" id="clear7" value="Clear" disabled>
                    <input type="button" class="view" name="view7" id="view7" value="View">   
                    <input type="submit" class="save" name="save7" id="save7" value="Save" disabled>
                </div>
            </div>
              
            <div class="add-remarks-content">
                <div class="add-remarks">
                    <textarea maxlength="500" autofocus disabled onkeyup="enabled()" name="remark8" class="remark" id="remark8" placeholder="Type here... (Maximum of 500 characters)"><?php if(!empty($_POST['refer_number'])){ $refer_number = $_POST['refer_number']; $query="SELECT * FROM actionLog WHERE reference_number = '$refer_number'";$result = mysqli_query($conn,$query);if($result){while($row = mysqli_fetch_assoc($result)){echo $row['remark8'];}}}?></textarea>
                </div>
                <div class="add-div">
                    <input type="text" class="date" id="date8" name="date8" placeholder="<?php echo date('Y-m-d'); ?>" value="<?php if(!empty($_POST['refer_number'])){ $refer_number = $_POST['refer_number']; $query="SELECT * FROM actionLog WHERE reference_number = '$refer_number'";$result = mysqli_query($conn,$query);if($result){while($row = mysqli_fetch_assoc($result)){echo $row['date8'];}}}?>" disabled readonly>
                    <input type="text" class="name" id="name8" name="name8" value="<?php if(!empty($_POST['refer_number'])){ $refer_number = $_POST['refer_number']; $query="SELECT * FROM actionLog WHERE reference_number = '$refer_number'";$result = mysqli_query($conn,$query);if($result){while($row = mysqli_fetch_assoc($result)){echo $row['remark_by8'];}}}?>" placeholder="Remark by" disabled readonly>
                </div>
                <div class="modal-button-view">
                    <input type="button" class="clear" name="clear8" id="clear8" value="Clear" disabled>
                    <input type="button" class="view" name="view8" id="view8" value="View">
                    <input type="submit" class="save" name="save8" id="save8" value="Save" disabled>
                </div>
            </div>
              
            <div class="add-remarks-content">
                <div class="add-remarks">
                    <textarea maxlength="500" autofocus disabled onkeyup="enabled()" name="remark9" class="remark" id="remark9" placeholder="Type here... (Maximum of 500 characters)"><?php if(!empty($_POST['refer_number'])){ $refer_number = $_POST['refer_number']; $query="SELECT * FROM actionLog WHERE reference_number = '$refer_number'";$result = mysqli_query($conn,$query);if($result){while($row = mysqli_fetch_assoc($result)){echo $row['remark9'];}}}?></textarea>
                </div>
                <div class="add-div">
                    <input type="text" class="date" id="date9" name="date9" placeholder="<?php echo date('Y-m-d'); ?>" value="<?php if(!empty($_POST['refer_number'])){ $refer_number = $_POST['refer_number']; $query="SELECT * FROM actionLog WHERE reference_number = '$refer_number'";$result = mysqli_query($conn,$query);if($result){while($row = mysqli_fetch_assoc($result)){echo $row['date9'];}}}?>" disabled readonly>
                    <input type="text" class="name" id="name9" name="name9" value="<?php if(!empty($_POST['refer_number'])){ $refer_number = $_POST['refer_number']; $query="SELECT * FROM actionLog WHERE reference_number = '$refer_number'";$result = mysqli_query($conn,$query);if($result){while($row = mysqli_fetch_assoc($result)){echo $row['remark_by9'];}}}?>" placeholder="Remark by" disabled readonly>
                </div>
                <div class="modal-button-view">
                    <input type="button" class="clear" name="clear9" id="clear9" value="Clear" disabled>
                    <input type="button" class="view" name="view9" id="view9" value="View">
                    <input type="submit" class="save" name="save9" id="save9" value="Save" disabled>
                </div>
            </div>
              
            <div class="add-remarks-content">
                <div class="add-remarks">
                    <textarea maxlength="500" autofocus disabled onkeyup="enabled()" name="remark10" class="remark" id="remark10" placeholder="Type here... (Maximum of 500 characters)"><?php if(!empty($_POST['refer_number'])){ $refer_number = $_POST['refer_number']; $query="SELECT * FROM actionLog WHERE reference_number = '$refer_number'";$result = mysqli_query($conn,$query);if($result){while($row = mysqli_fetch_assoc($result)){echo $row['remark10'];}}}?></textarea>
                </div>
                <div class="add-div">
                    <input type="text" class="date" id="date10" name="date10" placeholder="<?php echo date('Y-m-d'); ?>" value="<?php if(!empty($_POST['refer_number'])){ $refer_number = $_POST['refer_number']; $query="SELECT * FROM actionLog WHERE reference_number = '$refer_number'";$result = mysqli_query($conn,$query);if($result){while($row = mysqli_fetch_assoc($result)){echo $row['date10'];}}}?>" disabled readonly>
                    <input type="text" class="name" id="name10" name="name10" value="<?php if(!empty($_POST['refer_number'])){ $refer_number = $_POST['refer_number']; $query="SELECT * FROM actionLog WHERE reference_number = '$refer_number'";$result = mysqli_query($conn,$query);if($result){while($row = mysqli_fetch_assoc($result)){echo $row['remark_by10'];}}}?>" placeholder="Remark by" disabled readonly>
                </div>
                <div class="modal-button-view">
                    <input type="button" class="clear" name="clear10" id="clear10" value="Clear" disabled>
                    <input type="button" class="view" name="view10" id="view10" value="View">
                    <input type="submit" class="save" name="save10" id="save10" value="Save" disabled>
                </div>
            </div>
        </div>
    </div>
</form>
    
<div class="transactions">
    <br>
    <h3>Records of Reference Number: <label style="color: #d70c0c;"> <?php if(isset($_POST['refer_number'])) echo $_POST['refer_number']; ?></label></h3>
    <div class="row-container">
        <div class="btn">
            <input type="button" class="add" id="add" name="add-remark" value="Add to Log">&nbsp;&nbsp;<i style="color:red;font-size:12px;">(Click here to add/view remarks)</i>
        </div>
        <div class="row">
        <div class="label">
            <label for="">Logged Date</label>
        </div>
        <div class="input">
        <input type="text" disabled readonly value="<?php
            if(!isset($_POST['billspayment']) && !isset($_POST['billspayment_others'])){
                if(!empty($_POST['refer_number'])){
                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                    $select = "SELECT * FROM actionLog where reference_number like '%$search%'";// search reference number inputted
                    $result = mysqli_query($conn,$select);//execute query in the database
                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                            echo $row['logged_date'];
                        }
                    }else{
                        echo 'NO RECORDS FOUND!';
                    }
                }
            }
        ?>">
        </div>
    </div>
    <div class="row">
        <div class="label">
            <label for="">Log Status</label>
        </div>
        <div class="input">
        <input type="text" disabled readonly value="<?php
            if(!isset($_POST['billspayment']) && !isset($_POST['billspayment_others'])){
                if(!empty($_POST['refer_number'])){
                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                    $select = "SELECT * FROM actionLog where reference_number like '%$search%'";// search reference number inputted
                    $result = mysqli_query($conn,$select);//execute query in the database
                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                            echo $row['log_status'];
                        }
                    }else{
                        echo 'NO RECORDS FOUND!';
                    }
                }
            }
        ?>">
        </div>
    </div>
        <div class="row">
            <div class="label">
                <label for="">Billed / Not Billed</label>
            </div>
            <div class="input">
                <input type="text" disabled readonly value="YES / NO">
           </div>
        </div>
        <div class="row">
            <div class="label">
                <label for="">Status</label>
            </div>
            <div class="input">
                <input type="text" disabled readonly 
                value="<?php
                        if(isset($_POST['submit'])){//button display
                            if(!isset($_POST['billspayment']) && !isset($_POST['billspayment_others'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM actionLog where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['status'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                        if(isset($_POST['submit'])){//button display
                            if(isset($_POST['billspayment_others'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM billsPayment_others where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['status'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                        if(isset($_POST['submit'])){//button display
                            if(isset($_POST['billspayment'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM billsPayment where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['status'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                    ?>
                ">
            </div>
        </div>
        <div class="row">
            <div class="label">
                    <label for="">Date/ Time (YYYY-MM-DD)</label>
                </div>
            <div class="input">
                    <input type="text" disabled readonly 
                    value="<?php
                        if(isset($_POST['submit'])){//button display
                            if(!isset($_POST['billspayment']) && !isset($_POST['billspayment_others'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM actionLog where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['date_time'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                        if(isset($_POST['submit'])){//button display
                            if(isset($_POST['billspayment_others'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM billsPayment_others where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['date_time'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                        if(isset($_POST['submit'])){//button display
                            if(isset($_POST['billspayment'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM billsPayment where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['date_time'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                    ?>
                    ">
            </div>
        </div>
        <div class="row">
            <div class="label">
                    <label for="">Control Number</label>
                </div>
            <div class="input">
                    <input type="text" disabled readonly value="<?php
                        if(isset($_POST['submit'])){//button display
                            if(!isset($_POST['billspayment']) && !isset($_POST['billspayment_others'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM actionLog where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['control_number'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                        if(isset($_POST['submit'])){//button display
                            if(isset($_POST['billspayment_others'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM billsPayment_others where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['control_number'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                        if(isset($_POST['submit'])){//button display
                            if(isset($_POST['billspayment'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM billsPayment where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['control_number'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                    ?>
                    
                    ">
            </div>
        </div>
        <div class="row">
            <div class="label">
                    <label for="">Reference Number</label>
                </div>
            <div class="input">
                    <input type="text" disabled readonly value="<?php
                        if(isset($_POST['submit'])){//button display
                            if(!isset($_POST['billspayment']) && !isset($_POST['billspayment_others'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM actionLog where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['reference_number'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                        if(isset($_POST['submit'])){//button display
                            if(isset($_POST['billspayment_others'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM billsPayment_others where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['reference_number'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                        if(isset($_POST['submit'])){//button display
                            if(isset($_POST['billspayment'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM billsPayment where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['reference_number'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                    ?>
                    
                    ">
            </div>
        </div>
        <div class="row">
            <div class="label">
                    <label for="">Payor</label>
                </div>
            <div class="input">
                    <input type="text" disabled readonly value="<?php
                        if(isset($_POST['submit'])){//button display
                            if(!isset($_POST['billspayment']) && !isset($_POST['billspayment_others'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM actionLog where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['payor'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                        if(isset($_POST['submit'])){//button display
                            if(isset($_POST['billspayment_others'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM billsPayment_others where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['payor'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                        if(isset($_POST['submit'])){//button display
                            if(isset($_POST['billspayment'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM billsPayment where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['payor'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                    ?>
                    
                    ">
            </div>
        </div> 
        <div class="row">
            <div class="label">
                    <label for="">Address</label>
                </div>
            <div class="input">
                    <input type="text" disabled readonly value="<?php
                        if(isset($_POST['submit'])){//button display
                            if(!isset($_POST['billspayment']) && !isset($_POST['billspayment_others'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM actionLog where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['address'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                        if(isset($_POST['submit'])){//button display
                            if(isset($_POST['billspayment_others'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM billsPayment_others where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['address'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                        if(isset($_POST['submit'])){//button display
                            if(isset($_POST['billspayment'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM billsPayment where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['address'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                    ?>
                    ">
            </div>
        </div>
        <div class="row">
            <div class="label">
                    <label for="">Account Number</label>
                </div>
            <div class="input">
                    <input type="text" disabled readonly value="<?php
                        if(isset($_POST['submit'])){//button display
                            if(!isset($_POST['billspayment']) && !isset($_POST['billspayment_others'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM actionLog where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['account_number'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                        if(isset($_POST['submit'])){//button display
                            if(isset($_POST['billspayment_others'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM billsPayment_others where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['account_number'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                        if(isset($_POST['submit'])){//button display
                            if(isset($_POST['billspayment'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM billsPayment where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['account_number'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                    ?>
                    
                    ">
            </div>
        </div> 
        <div class="row">
            <div class="label">
                    <label for="">Account Name</label>
                </div>
            <div class="input">
                    <input type="text" disabled readonly value="<?php
                        if(isset($_POST['submit'])){//button display
                            if(!isset($_POST['billspayment']) && !isset($_POST['billspayment_others'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM actionLog where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['account_name'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                        if(isset($_POST['submit'])){//button display
                            if(isset($_POST['billspayment_others'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM billsPayment_others where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['account_name'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                        if(isset($_POST['submit'])){//button display
                            if(isset($_POST['billspayment'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM billsPayment where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['account_name'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                    ?>
                    ">
            </div>
        </div> 
        <div class="row">
            <div class="label">
                    <label for="">Amount Paid</label>
                </div>
            <div class="input">
                    <input type="text" disabled readonly value="<?php
                        if(isset($_POST['submit'])){//button display
                            if(!isset($_POST['billspayment']) && !isset($_POST['billspayment_others'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM actionLog where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['amount_paid'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                        if(isset($_POST['submit'])){//button display
                            if(isset($_POST['billspayment_others'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM billsPayment_others where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['amount_paid'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                        if(isset($_POST['submit'])){//button display
                            if(isset($_POST['billspayment'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM billsPayment where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['amount_paid'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                    ?>
                    ">
            </div>
        </div> 
        <div class="row">
            <div class="label">
                <label for="">Charge to Partner</label>
            </div>
            <div class="input">
                    <input type="text" disabled readonly value="<?php
                        if(isset($_POST['submit'])){//button display
                            if(!isset($_POST['billspayment']) && !isset($_POST['billspayment_others'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM actionLog where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['charge_to_partner'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                        if(isset($_POST['submit'])){//button display
                            if(isset($_POST['billspayment_others'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM billsPayment_others where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['charge_to_partner'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                        if(isset($_POST['submit'])){//button display
                            if(isset($_POST['billspayment'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM billsPayment where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['charge_to_partner'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                    ?>
                    ">
            </div>
        </div> 
        <div class="row">
            <div class="label">
                    <label for="">Charge to Customer</label>
                </div>
            <div class="input">
                    <input type="text" disabled readonly value="<?php
                        if(isset($_POST['submit'])){//button display
                            if(!isset($_POST['billspayment']) && !isset($_POST['billspayment_others'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM actionLog where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['charge_to_customer'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                        if(isset($_POST['submit'])){//button display
                            if(isset($_POST['billspayment_others'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM billsPayment_others where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['charge_to_customer'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                        if(isset($_POST['submit'])){//button display
                            if(isset($_POST['billspayment'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM billsPayment where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['charge_to_customer'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                    ?>
                    ">
            </div>
        </div> 
        <div class="row">
            <div class="label">
                    <label for="">Contact Number</label>
                </div>
            <div class="input">
                    <input type="text" disabled readonly value="<?php
                        if(isset($_POST['submit'])){//button display
                            if(!isset($_POST['billspayment']) && !isset($_POST['billspayment_others'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM actionLog where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['contact_number'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                        if(isset($_POST['submit'])){//button display
                            if(isset($_POST['billspayment_others'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM billsPayment_others where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['contact_number'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                        if(isset($_POST['submit'])){//button display
                            if(isset($_POST['billspayment'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM billsPayment where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['contact_number'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                    ?>
                    ">
            </div>
        </div> 
        <div class="row">
            <div class="label">
                    <label for="">Other Details</label>
                </div>
            <div class="input">
                    <input type="text" disabled readonly value="<?php
                        if(isset($_POST['submit'])){//button display
                            if(!isset($_POST['billspayment']) && !isset($_POST['billspayment_others'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM actionLog where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['other_details'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                        if(isset($_POST['submit'])){//button display
                            if(isset($_POST['billspayment_others'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM billsPayment_others where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['other_details'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                        if(isset($_POST['submit'])){//button display
                            if(isset($_POST['billspayment'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM billsPayment where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['other_details'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                    ?>
                    ">
            </div>
        </div> 
        <div class="row">
            <div class="label">
                    <label for="">ML Outlet</label>
                </div>
            <div class="input">
                    <input type="text" disabled readonly value="<?php
                        if(isset($_POST['submit'])){//button display
                            if(!isset($_POST['billspayment']) && !isset($_POST['billspayment_others'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM actionLog where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['ml_outlet'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                        if(isset($_POST['submit'])){//button display
                            if(isset($_POST['billspayment_others'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM billsPayment_others where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['ml_outlet'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                        if(isset($_POST['submit'])){//button display
                            if(isset($_POST['billspayment'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM billsPayment where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['ml_outlet'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                    ?>
                    ">
            </div>
        </div> 
        <div class="row">
            <div class="label">
                    <label for="">Region</label>
                </div>
            <div class="input">
                    <input type="text" disabled readonly value="<?php
                        if(isset($_POST['submit'])){//button display
                            if(!isset($_POST['billspayment']) && !isset($_POST['billspayment_others'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM actionLog where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['region'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                        if(isset($_POST['submit'])){//button display
                            if(isset($_POST['billspayment_others'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM billsPayment_others where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['region'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                        if(isset($_POST['submit'])){//button display
                            if(isset($_POST['billspayment'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM billsPayment where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['region'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                    ?>
                    ">
            </div>
        </div> 
        <div class="row">
            <div class="label">
                    <label for="">Operator</label>
                </div>
            <div class="input">
                    <input type="text" disabled readonly value="<?php
                        if(isset($_POST['submit'])){//button display
                            if(!isset($_POST['billspayment']) && !isset($_POST['billspayment_others'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM actionLog where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['operator'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                        if(isset($_POST['submit'])){//button display
                            if(isset($_POST['billspayment_others'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM billsPayment_others where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['operator'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                        if(isset($_POST['submit'])){//button display
                            if(isset($_POST['billspayment'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM billsPayment where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['operator'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                    ?>
                    ">
            </div>
        </div> 
        <div class="row">
            <div class="label">
                    <label for="">Partner Name</label>
                </div>
            <div class="input">
                    <input type="text" disabled readonly value="<?php
                        if(isset($_POST['submit'])){//button display
                            if(!isset($_POST['billspayment']) && !isset($_POST['billspayment_others'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM actionLog where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['partner_name'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                        if(isset($_POST['submit'])){//button display
                            if(isset($_POST['billspayment_others'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM billsPayment_others where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['partner_name'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                        if(isset($_POST['submit'])){//button display
                            if(isset($_POST['billspayment'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM billsPayment where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['partner_name'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                    ?>
                    ">
            </div>
        </div> 
        <div class="row">
            <div class="label">
                    <label for="">Partner ID</label>
                </div>
            <div class="input">
                    <input type="text" disabled readonly value="<?php
                        if(isset($_POST['submit'])){//button display
                            if(!isset($_POST['billspayment']) && !isset($_POST['billspayment_others'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM actionLog where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['partner_id'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                        if(isset($_POST['submit'])){//button display
                            if(isset($_POST['billspayment_others'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM billsPayment_others where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['partner_id'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                        if(isset($_POST['submit'])){//button display
                            if(isset($_POST['billspayment'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM billsPayment where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['partner_id'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                    ?>
                    ">
            </div>
        </div> 
        <div class="row">
            <div class="label">
                    <label for="">Imported Date</label>
                </div>
            <div class="input">
                    <input type="text" disabled readonly value="<?php
                        if(isset($_POST['submit'])){//button display
                            if(!isset($_POST['billspayment']) && !isset($_POST['billspayment_others'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM actionLog where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['imported_date'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                        if(isset($_POST['submit'])){//button display
                            if(isset($_POST['billspayment_others'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM billsPayment_others where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['imported_date'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                        if(isset($_POST['submit'])){//button display
                            if(isset($_POST['billspayment'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM billsPayment where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['imported_date'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                    ?>
                    ">
            </div>
        </div> 
        <div class="row">
            <div class="label">
                    <label for="">Imported By</label>
                </div>
            <div class="input">
                    <input type="text" disabled readonly value="<?php
                        if(isset($_POST['submit'])){//button display
                            if(!isset($_POST['billspayment']) && !isset($_POST['billspayment_others'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM actionLog where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['imported_by'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                        if(isset($_POST['submit'])){//button display
                            if(isset($_POST['billspayment_others'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM billsPayment_others where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['imported_by'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                        if(isset($_POST['submit'])){//button display
                            if(isset($_POST['billspayment'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM billsPayment where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['imported_by'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                    ?>
                    ">
            </div>
        </div>
        <div class="row">
            <div class="label">
                    <label for="">Logged By</label>
                </div>
            <div class="input">
                    <input type="text" disabled readonly value="<?php
                        if(isset($_POST['submit'])){//button display
                            if(!isset($_POST['billspayment']) && !isset($_POST['billspayment_others'])){
                                if(!empty($_POST['refer_number'])){
                                    $search = $_POST['refer_number'];// inputted reference number stored in $search variable
                                    $select = "SELECT * FROM actionLog where reference_number like '%$search%'";// search reference number inputted
                                    $result = mysqli_query($conn,$select);//execute query in the database
                                    if($result->num_rows > 0){//display if the reference number that is available to the database billspayment   
                                        while($row = $result->fetch_assoc()){//display the records from the database to the page
                                            echo $row['remark_by1'];
                                        }
                                    }else{
                                        echo 'NO RECORDS FOUND!';
                                    }
                                }
                            }
                        }
                        ?>
                    ">
            </div>
        </div>

    </div>
</div> 
<div id="max-modal" class="max-modal">
  <div class="max-modal-content">
        <div class="info">
            <i style="color:#d70c0c; font-size:64px;" class='fa fa-exclamation-circle'></i>
            <i style="text-align:center;color:#000;"><h4>You have reached the maximum number of remarks!</h4></i>
        </div>
        <div class="close-div">
            <input type="button" class="close" name="close" id="close" value="Close">
        </div>
    </div>
</div>

<script>
        var maxModal = document.getElementById("max-modal");
        var span = document.getElementsByClassName("close")[0];
    
       var span1 = document.getElementsByClassName("view-close1")[0];
       var span2 = document.getElementsByClassName("view-close2")[0];
       var span3 = document.getElementsByClassName("view-close3")[0];
       var span4 = document.getElementsByClassName("view-close4")[0];
       var span5 = document.getElementsByClassName("view-close5")[0];
       var span6 = document.getElementsByClassName("view-close6")[0];
       var span7 = document.getElementsByClassName("view-close7")[0];
       var span8 = document.getElementsByClassName("view-close8")[0];
       var span9 = document.getElementsByClassName("view-close9")[0];
       var span10 = document.getElementsByClassName("view-close10")[0];
        
        var viewModal1 = document.getElementById("view-modal1");
        var viewModal2 = document.getElementById("view-modal2");
        var viewModal3 = document.getElementById("view-modal3");
        var viewModal4 = document.getElementById("view-modal4");
        var viewModal5 = document.getElementById("view-modal5");
        var viewModal6 = document.getElementById("view-modal6");
        var viewModal7 = document.getElementById("view-modal7");
        var viewModal8 = document.getElementById("view-modal8");
        var viewModal9 = document.getElementById("view-modal9");
        var viewModal10 = document.getElementById("view-modal10");

        var view1 = document.getElementById("view1");
        var view2 = document.getElementById("view2");
        var view3 = document.getElementById("view3");
        var view4 = document.getElementById("view4");
        var view5 = document.getElementById("view5");
        var view6 = document.getElementById("view6");
        var view7 = document.getElementById("view7");
        var view8 = document.getElementById("view8");
        var view9 = document.getElementById("view9");
        var view10 = document.getElementById("view10");

        view1.onclick = function(){
            viewModal1.style.display = "block";
        }
        view2.onclick = function(){
            viewModal2.style.display = "block";
        }
        view3.onclick = function(){
            viewModal3.style.display = "block";
        }
        view4.onclick = function(){
            viewModal4.style.display = "block";
        }
        view5.onclick = function(){
            viewModal5.style.display = "block";
        }
        view6.onclick = function(){
            viewModal6.style.display = "block";
        }
        view7.onclick = function(){
            viewModal7.style.display = "block";
        }
        view8.onclick = function(){
            viewModal8.style.display = "block";
        }
        view9.onclick = function(){
            viewModal9.style.display = "block";
        }
        view10.onclick = function(){
            viewModal10.style.display = "block";
        }

        span1.onclick = function() {
            viewModal1.style.display = "none";
        }
        span2.onclick = function() {
            viewModal2.style.display = "none";
        }
        span3.onclick = function() {
            viewModal3.style.display = "none";
        }
        span4.onclick = function() {
            viewModal4.style.display = "none";
        }
        span5.onclick = function() {
            viewModal5.style.display = "none";
        }
        span6.onclick = function() {
            viewModal6.style.display = "none";
        }
        span7.onclick = function() {
            viewModal7.style.display = "none";
        }
        span8.onclick = function() {
            viewModal8.style.display = "none";
        }
        span9.onclick = function() {
            viewModal9.style.display = "none";
        }
        span10.onclick = function() {
            viewModal10.style.display = "none";
        }
        span.onclick = function() {
            maxModal.style.display = "none";
        }
</script>
<script type="text/javascript"> 
        var modal = document.getElementById("add-modal");
        var btn = document.getElementById("add");
        var span = document.getElementsByClassName("add-close")[0];

        btn.onclick = function() {
            modal.style.display = "block";
        }
        // When the user clicks on <span> (x), close the modal
        span.onclick = function() {
        modal.style.display = "none";
        clearRemarks();
        }
        var addRemark = document.getElementById("addRemark");
        var remark1 = document.getElementById("remark1");
        var date1 = document.getElementById("date1");
        var save1 = document.getElementById("save1");
        var clear1 = document.getElementById("clear1");
        var view1 = document.getElementById("view1");

        var remark2 = document.getElementById("remark2");
        var date2 = document.getElementById("date2");
        var save2 = document.getElementById("save2");
        var clear2 = document.getElementById("clear2");
        var view2 = document.getElementById("view2");

        var remark3 = document.getElementById("remark3");
        var date3 = document.getElementById("date3");
        var save3 = document.getElementById("save3");
        var clear3 = document.getElementById("clear3");
        var view3 = document.getElementById("view3");

        var remark4 = document.getElementById("remark4");
        var date4 = document.getElementById("date4");
        var save4 = document.getElementById("save4");
        var clear4 = document.getElementById("clear4");
        var view4 = document.getElementById("view4");


        var remark5 = document.getElementById("remark5");
        var date5 = document.getElementById("date5");
        var save5 = document.getElementById("save5");
        var clear5 = document.getElementById("clear5");
        var view5 = document.getElementById("view5");

        var remark6 = document.getElementById("remark6");
        var date6 = document.getElementById("date6");
        var save6 = document.getElementById("save6");
        var clear6 = document.getElementById("clear6");
        var view6 = document.getElementById("view6");


        var remark7 = document.getElementById("remark7");
        var date7 = document.getElementById("date7");
        var save7 = document.getElementById("save7");
        var clear7 = document.getElementById("clear7");
        var view7 = document.getElementById("view7");

        var remark8 = document.getElementById("remark8");
        var date8 = document.getElementById("date8");
        var save8 = document.getElementById("save8");
        var clear8 = document.getElementById("clear8");
        var view8 = document.getElementById("view8");

        var remark9 = document.getElementById("remark9");
        var date9 = document.getElementById("date9");
        var save9 = document.getElementById("save9");
        var clear9 = document.getElementById("clear9");
        var view9 = document.getElementById("view9");

        var remark10 = document.getElementById("remark10");
        var date10 = document.getElementById("date10");
        var save10 = document.getElementById("save10");
        var view10 = document.getElementById("view10");
        var name1 =document.getElementById("name1");
    
        function enabled() {
            if (remark1.value !== "") {
                date1.disabled = false;
                save1.disabled = false;
                clear1.disabled = false;
            }else{
                date1.disabled = true;
                save1.disabled = true;
                clear1.disabled = true;
            }
            if(remark1.disabled == true){
                date1.disabled=true;
                save1.disabled=true;
                clear1.disabled=true;
            }
            if(remark2.value !== ""){
                date1.disabled=true;
                save1.disabled=true;
                clear1.disabled=true;
                date2.disabled = false;
                save2.disabled =false;
                clear2.disabled= false;
            }else{
                date2.disabled = true;
                save2.disabled =true;
                clear2.disabled=true;
            }
            if(remark2.disabled == true){
                date2.disabled=true;
                save2.disabled=true;
                clear2.disabled=true;
            }
            if(remark3.value !== ""){
                date2.disabled=true;
                save2.disabled=true;
                clear2.disabled=true;
                date3.disabled=false;
                save3.disabled=false;
                clear3.disabled=false;
            }else{      
                date3.disabled=true;
                save3.disabled=true;
                clear3.disabled=true;
            }
            if(remark3.disabled == true){
                date3.disabled=true;
                save3.disabled=true;
                clear3.disabled=true;
            }
            if(remark4.value !== ""){
                date3.disabled=true;
                save3.disabled=true;
                clear3.disabled=true;
                date4.disabled=false;
                save4.disabled=false;
                clear4.disabled=false;
            }else{ 
                date4.disabled=true;
                save4.disabled=true;
                clear4.disabled=true;
            }
            if(remark4.disabled == true){
                date4.disabled=true;
                save4.disabled=true;
                clear4.disabled=true;
            }
            if(remark5.value !== ""){
                date4.disabled=true;
                save4.disabled=true;
                clear4.disabled=true;

                date5.disabled=false;
                save5.disabled=false;
                clear5.disabled=false;
            }else{
                date5.disabled=true;
                save5.disabled=true;
                clear5.disabled=true;
            }
            if(remark5.disabled == true){
                date5.disabled=true;
                save5.disabled=true;
                clear5.disabled=true;
            }
            if(remark6.value !== ""){
                date5.disabled=true;
                save5.disabled=true;
                clear5.disabled=true;
                view5.disabled=true;
                date6.disabled=false;
                save6.disabled=false;
                clear6.disabled=false;
            }else{     
                date6.disabled=true;
                save6.disabled=true;
                clear6.disabled=true;
            }
            if(remark6.disabled == true){
                date6.disabled=true;
                save6.disabled=true;
                clear6.disabled=true;
            }
            if(remark7.value !== ""){
                date6.disabled=true;
                save6.disabled =true;
                clear6.disabled=true;
                view6.disabled=true;
                date7.disabled=false;
                save7.disabled=false;
                clear7.disabled=false;
            }else{
                date7.disabled=true;
                save7.disabled=true;
                clear7.disabled=true;
            }
            if(remark7.disabled == true){
                date7.disabled=true;
                save7.disabled=true;
                clear7.disabled=true;
            }
            if(remark8.value !== ""){
                date7.disabled=true;
                save7.disabled=true;
                clear7.disabled=true;
                date8.disabled=false;
                save8.disabled=false;
                clear8.disabled=false;
                view8.disabled=false;
            }else{          
                date8.disabled=true;
                save8.disabled=true;
                clear8.disabled=true;
            }
            if(remark8.disabled == true){
                date8.disabled=true;
                save8.disabled=true;
                clear8.disabled=true;
            }
            if(remark9.value !== ""){
                date8.disabled=true;
                save8.disabled=true;
                clear8.disabled=true;
                date9.disabled=false;
                save9.disabled=false;
                clear9.disabled=false;
            }else{ 
                date9.disabled=true;
                save9.disabled=true;
                clear9.disabled=true;
            }
            if(remark9.disabled == true){
                date9.disabled=true;
                save9.disabled=true;
                clear9.disabled=true;
            }
            if(remark10.value !== ""){
                date9.disabled=true;
                save9.disabled=true;
                clear9.disabled=true
                date10.disabled=false;
                save10.disabled=false;
                clear10.disabled=false;
            }else{
                date10.disabled=true;
                save10.disabled=true;
                clear10.disabled=true;
            }
            if(date10.disabled == true){
                remark10.disabled=true;
                date10.disabled=true;
                save10.disabled=true;
                clear10.disabled=true;
            }
            
        }

        addRemark.onclick = function() {
            if(remark1.disabled == true){
                remark1.disabled=false;
            }else{
                remark1.disabled=true;
            }if(remark1.value !== ""){
                remark1.disabled=true;
                remark2.disabled=false;
            }if(remark2.value !== ""){
                remark2.disabled=true;
                remark3.disabled=false;
            }if(remark3.value !== ""){
                remark3.disabled=true;
                remark4.disabled=false;
            }if(remark4.value !== ""){
                remark4.disabled=true;
                remark5.disabled=false;
            }if(remark5.value !== ""){
                remark5.disabled = true;
                remark6.disabled=false;
            }if(remark6.value !== ""){
                remark6.disabled=true;
                remark7.disabled=false;
            }if(remark7.value !== ""){
                remark7.disabled=true;
                remark8.disabled=false;
            }if(remark8.value !== ""){
                remark8.disabled=true;
                remark9.disabled=false;
            }if(remark9.value !== ""){
                remark9.disabled =true;
                remark10.disabled=false;
            }if(remark10.value !== ""){
                remark10.disabled=true;
                maxModal.style.display = "block";
        
            }
        };
        
        clear1.onclick = function(){
            remark1.value = "";
            date1.disabled=true;
            save1.disabled=true;
            clear1.disabled=true;
        }
        clear2.onclick = function(){
            remark2.value = "";
            date2.disabled=true;
            save2.disabled=true;
            clear2.disabled=true;
        }
        clear3.onclick = function(){
            remark3.value = "";
            date3.disabled=true;
            save3.disabled=true;
            clear3.disabled=true;
        }
        clear4.onclick = function(){
            remark4.value = "";
            date4.disabled=true;
            save3.disabled=true;
            clear4.disabled=true;
        }
        clear5.onclick = function(){
            remark5.value = "";
            date5.disabled=true;
            save5.disabled=true;
            clear5.disabled=true;
        }
        clear6.onclick = function(){
            remark6.value = "";
            date6.disabled=true;
            save6.disabled=true;
            clear6.disabled=true;
        }
        clear7.onclick = function(){
            remark7.value = "";
            date7.disabled=true;
            save7.disabled=true;
            clear7.disabled=true;
        }
        clear8.onclick = function(){
            remark8.value = "";
            date8.disabled=true;
            save8.disabled=true;
            clear8.disabled=true;
        }
        clear9.onclick = function(){
            remark9.value = "";
            date9.disabled=true;
            save9.disabled=true;
            clear9.disabled=true;
        }
        clear10.onclick = function(){
            remark10.value = "";
            date10.disabled=true;
            save10.disabled=true;
            clear10.disabled=true;
        }

        function clearRemarks() {
            if (!save1.disabled) {
                remark1.value = "";
                remark1.disabled=true;
                date1.disabled=true;
                save1.disabled=true;
                clear1.disabled=true;
            }
            if (!save2.disabled) {
                remark2.value = "";
                remark2.disabled=true;
                date2.disabled=true;
                save2.disabled=true;
                clear2.disabled=true;

            }

            if (!save3.disabled) {
                remark3.value = "";
                remark3.disabled=true;
                date3.disabled=true;
                save3.disabled=true;
                clear3.disabled=true;
            }
            if (!save4.disabled) {
                remark4.value = "";
                remark4.disabled=true;
                date4.disabled=true;
                save3.disabled=true;
                clear4.disabled=true;
            }
            if (!save5.disabled) {
                remark5.value = "";
                remark5.disabled=true;
                date5.disabled=true;
                save5.disabled=true;
                clear5.disabled=true;
            }
            if (!save6.disabled) {
                remark6.value = "";
                remark6.disabled=true;
                date6.disabled=true;
                save6.disabled=true;
                clear6.disabled=true;
            }
            if (!save7.disabled) {
                remark7.value = "";
                remark7.disabled=true;
                date7.disabled=true;
                save7.disabled=true;
                clear7.disabled=true;
            }
            if (!save8.disabled) {
                remark8.value = "";
                remark8.disabled=true;
                date8.disabled=true;
                save8.disabled=true;
                clear8.disabled=true;
            }
            if (!save9.disabled) {
                remark9.value = "";
                remark9.disabled=true;
                date9.disabled=true;
                save9.disabled=true;
                clear9.disabled=true;
            }
            if (!save10.disabled) {
                remark10.value = "";
                remark10.disabled=true;
                date10.disabled=true;
                save10.disabled=true;
                clear10.disabled=true;
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
   var actionreportbtn = document.getElementById("action-report-btn"); // Para Report Btn
   var actionreportnav = document.getElementById("action-report-nav"); // Para Report Div
   var actionopenreport = document.getElementById("open-action-report"); // Para Report Div Down Arrow or Expanded
   var actionclosedreport = document.getElementById("closed-action-report"); // Para Report Div Right Arrow or Minimized

   var paraopenimport = document.getElementById("open-para-import"); // Para Import Div Down Arrow or Expanded
   var paraclosedimport = document.getElementById("closed-para-import"); // Para Import Div Right Arrow or Minimized
   var paraopenreport = document.getElementById("open-para-report"); // Para Report Div Down Arrow or Expanded
   var paraclosedreport = document.getElementById("closed-para-report"); // Para Report Div Right Arrow or Minimized

   parabtn.addEventListener("click", function(){ // If parabtn is clicked
      if(paraimportbtn.style.display == "none"){ // and paraimportbtn is not visible
         paraimportbtn.style.animation = "slide-in-from-top 0.8s ease";
         parareportbtn.style.animation = "slide-in-from-top 0.8s ease";
         actionreportbtn.style.animation = "slide-in-from-top 0.8s ease";
         paraopen.style.display = "block";       
         paraclosed.style.display = "none";
         paraimportbtn.style.display = "flex";
         parareportbtn.style.display = "flex";
         actionreportbtn.style.display = "flex";

      }else{
         paraopen.style.display = "none";
         paraclosed.style.display = "block";
         paraimportnav.style.display = "none";
         paraopenimport.style.display = "none";
         paraclosedimport.style.display = "block";
         parareportnav.style.display = "none";
         actionreportnav.style.display = "none";

         paraopenreport.style.display = "none";
         actionopenreport.style.display = "none";
         paraclosedreport.style.display = "block";
         paraimportbtn.style.animation = "slide-out-to-top 0.5s ease";
         parareportbtn.style.animation = "slide-out-to-top 0.5s ease";
         actionreportbtn.style.animation = "slide-out-to-top 0.5s ease";
         setTimeout(function() {
            paraimportbtn.style.display = "none";
            parareportbtn.style.display = "none";
            actionreportbtn.style.display = "none";
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

   actionreportbtn.addEventListener("click", function(){ // For action report side
      if(actionopenreport.style.display == "none"){
         actionreportnav.style.animation = "slide-in-from-top 0.8s ease";
         actionreportnav.style.display = "block";
         actionopenreport.style.display = "block";
         actionclosedreport.style.display = "none";
      }else{
        actionreportnav.style.animation = "slide-out-to-top 0.5s ease";
         setTimeout(function() {
            actionreportnav.style.display = "none";
         }, 450);
         actionopenreport.style.display = "none";
         actionclosedreport.style.display = "block";
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
<!-- <script type="text/javascript">
    function s(){
        var i=document.getElementById("ref_number");
        if(i.value=="")
        {
            document.getElementById("proceed-btn").disabled=true;
        }
        else{
            document.getElementById("proceed-btn").disabled=false;
        }
 
        }
       
            
</script> -->
</body>
</html>