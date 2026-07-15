<?php
session_start(); 
$conn = mysqli_connect('localhost', 'root', 'Password1','mldb');
@include 'fetch-partner-data.php';
@include '../export/export-single-reference.php';

if(!isset($_SESSION['user_name'])){
   header('location:login_form.php');
}

if (isset($_POST["export-allPartner-transactions"])) {
    $query = "SELECT * FROM actionLog ORDER BY logged_date ASC";
    $results = mysqli_query($conn, $query) or die("database error:" . mysqli_error($conn));
    $allRecord = array();
    while ($records = mysqli_fetch_assoc($results)) {
        $allRecord[] = $records;
    }
    $startDateMessage = '';
    $endDate = '';
    $noResult = '';
        if (isset($_POST["all"]) && isset($_POST['allPartner'])) {
            $recordQuery = "SELECT * FROM actionLog WHERE partner_id IN (SELECT partner_id FROM partner_masterfile) AND logged_date BETWEEN '".$_POST["fromDate"]."' AND '".$_POST["toDate"]."'";
            
            $recordResult = mysqli_query($conn, $recordQuery) or die("database error:" . mysqli_error($conn));
            $filterRecord = array();
            while ($records = mysqli_fetch_assoc($recordResult)) {
                $filterRecord[] = $records;
            }
            
            if (count($filterRecord)) {
                $fileName = "Transactions_" . date('Ymd') . ".csv";
                header("Content-Description: File Transfer");
                header("Content-Disposition: attachment; filename=$fileName");
                header("Content-Type: application/csv;");
                $file = fopen('php://output', 'w');
                
                $header = array(
                    'LOGGED DATE', 'LOG STATUS', 'STATUS', 'BLANK', 'DATE/TIME', 'CONTROL NUMBER', 'REFERENCE NUMBER', 'PAYOR', 'ADDRESS', 'ACCOUNT NUMBER',
                    'ACCOUNT NAME', 'AMOUNT PAID', 'CHARGE TO PARTNER', 'CHARGE TO CUSTOMER', 'CONTACT NUMBER', 'OTHER DETAILS',
                    'ML OUTLET', 'REGION', 'OPERATOR', 'PARTNER NAME', 'PARTNER ID', 'IMPORTED DATE', 'LOGGED BY'
                );
                fputcsv($file, $header);
                
                foreach ($filterRecord as $records) {
                    $recordData = array(
                        $records["logged_date"], $records["log_status"], $records["status"], $records["blank"], $records["date_time"], $records["control_number"],
                        $records["reference_number"], $records["payor"], $records["address"], $records["account_number"],
                        $records["account_name"], $records["amount_paid"], $records["charge_to_partner"],
                        $records["charge_to_customer"], $records["contact_number"], $records["other_details"],
                        $records["ml_outlet"], $records["region"], $records["operator"], $records["partner_name"],
                        $records["partner_id"], $records["imported_date"], $records["logged_by"]
                    );
                    fputcsv($file, $recordData);
                }
                
                fclose($file);
                exit;
            } else {
                $noResult = '<label class="text-danger">There are no records to export.</label>';
            }
        } else {
            // Handle other export options (e.g., specific transactions, filtered by date, etc.)
            // Add your code here to handle different export scenarios
        }
    }

    if (isset($_POST["export-partner-transactions"])) {
        $query = "SELECT * FROM actionLog ORDER BY logged_date ASC";
        $results = mysqli_query($conn, $query) or die("database error:" . mysqli_error($conn));
        $allRecord = array();
        while ($records = mysqli_fetch_assoc($results)) {
            $allRecord[] = $records;
        }
        $startDateMessage = '';
        $endDate = '';
        $noResult = '';
            if (isset($_POST["all"]) && !empty($_POST['partnerID'])) {
                $partnerID = $_POST['partnerID'];
                $recordQuery = "SELECT * FROM actionLog WHERE partner_id = '$partnerID' AND logged_date BETWEEN '".$_POST["fromDate"]."' AND '".$_POST["toDate"]."'";
                
                $recordResult = mysqli_query($conn, $recordQuery) or die("database error:" . mysqli_error($conn));
                $filterRecord = array();
                while ($records = mysqli_fetch_assoc($recordResult)) {
                    $filterRecord[] = $records;
                }
                
                if (count($filterRecord)) {
                    $fileName = "Transactions_" . date('Ymd') . ".csv";
                    header("Content-Description: File Transfer");
                    header("Content-Disposition: attachment; filename=$fileName");
                    header("Content-Type: application/csv;");
                    $file = fopen('php://output', 'w');
                    
                    $header = array(
                        'LOGGED DATE', 'LOG STATUS', 'STATUS', 'BLANK', 'DATE/TIME', 'CONTROL NUMBER', 'REFERENCE NUMBER', 'PAYOR', 'ADDRESS', 'ACCOUNT NUMBER',
                        'ACCOUNT NAME', 'AMOUNT PAID', 'CHARGE TO PARTNER', 'CHARGE TO CUSTOMER', 'CONTACT NUMBER', 'OTHER DETAILS',
                        'ML OUTLET', 'REGION', 'OPERATOR', 'PARTNER NAME', 'PARTNER ID', 'IMPORTED DATE', 'LOGGED BY'
                    );
                    fputcsv($file, $header);
                    
                    foreach ($filterRecord as $records) {
                        $recordData = array(
                            $records["logged_date"], $records["log_status"], $records["status"], $records["blank"], $records["date_time"], $records["control_number"],
                            $records["reference_number"], $records["payor"], $records["address"], $records["account_number"],
                            $records["account_name"], $records["amount_paid"], $records["charge_to_partner"],
                            $records["charge_to_customer"], $records["contact_number"], $records["other_details"],
                            $records["ml_outlet"], $records["region"], $records["operator"], $records["partner_name"],
                            $records["partner_id"], $records["imported_date"], $records["logged_by"]
                        );
                        fputcsv($file, $recordData);
                    }
                    
                    fclose($file);
                    exit;
                } else {
                    $noResult = '<label class="text-danger">There are no records to export.</label>';
                }
            } else {
                // Handle other export options (e.g., specific transactions, filtered by date, etc.)
                // Add your code here to handle different export scenarios
            }
        }
    

    if (isset($_POST["export-pending-allPartnerTransactions"])) {
        $query = "SELECT * FROM actionLog ORDER BY logged_date ASC";
        $results = mysqli_query($conn, $query) or die("database error:" . mysqli_error($conn));
        $allRecord = array();
        while ($records = mysqli_fetch_assoc($results)) {
            $allRecord[] = $records;
        }
        $startDateMessage = '';
        $endDate = '';
        $noResult = '';
            if (isset($_POST["pending"]) && isset($_POST['allPartner'])) {
                $recordQuery = "SELECT * FROM actionLog WHERE partner_id IN (SELECT partner_id FROM partner_masterfile) AND logged_date BETWEEN '".$_POST["fromDate"]."' AND '".$_POST["toDate"]."' AND log_status = 'Pending' ";
                
                $recordResult = mysqli_query($conn, $recordQuery) or die("database error:" . mysqli_error($conn));
                $filterRecord = array();
                while ($records = mysqli_fetch_assoc($recordResult)) {
                    $filterRecord[] = $records;
                }
                
                if (count($filterRecord)) {
                    $fileName = "Transactions_" . date('Ymd') . ".csv";
                    header("Content-Description: File Transfer");
                    header("Content-Disposition: attachment; filename=$fileName");
                    header("Content-Type: application/csv;");
                    $file = fopen('php://output', 'w');
                    
                    $header = array(
                        'LOGGED DATE', 'LOG STATUS', 'STATUS', 'BLANK', 'DATE/TIME', 'CONTROL NUMBER', 'REFERENCE NUMBER', 'PAYOR', 'ADDRESS', 'ACCOUNT NUMBER',
                        'ACCOUNT NAME', 'AMOUNT PAID', 'CHARGE TO PARTNER', 'CHARGE TO CUSTOMER', 'CONTACT NUMBER', 'OTHER DETAILS',
                        'ML OUTLET', 'REGION', 'OPERATOR', 'PARTNER NAME', 'PARTNER ID', 'IMPORTED DATE', 'LOGGED BY'
                    );
                    fputcsv($file, $header);
                    
                    foreach ($filterRecord as $records) {
                        $recordData = array(
                            $records["logged_date"], $records["log_status"], $records["status"], $records["blank"], $records["date_time"], $records["control_number"],
                            $records["reference_number"], $records["payor"], $records["address"], $records["account_number"],
                            $records["account_name"], $records["amount_paid"], $records["charge_to_partner"],
                            $records["charge_to_customer"], $records["contact_number"], $records["other_details"],
                            $records["ml_outlet"], $records["region"], $records["operator"], $records["partner_name"],
                            $records["partner_id"], $records["imported_date"], $records["logged_by"]
                        );
                        fputcsv($file, $recordData);
                    }
                    
                    fclose($file);
                    exit;
                } else {
                    $noResult = '<label class="text-danger">There are no records to export.</label>';
                }
            } else {
                // Handle other export options (e.g., specific transactions, filtered by date, etc.)
                // Add your code here to handle different export scenarios
            }
    }

    if (isset($_POST["export-pending-partnerTransactions"])) {
        $query = "SELECT * FROM actionLog ORDER BY logged_date ASC";
        $results = mysqli_query($conn, $query) or die("database error:" . mysqli_error($conn));
        $allRecord = array();
        while ($records = mysqli_fetch_assoc($results)) {
            $allRecord[] = $records;
        }
        $startDateMessage = '';
        $endDate = '';
        $noResult = '';
            if (isset($_POST["pending"]) && !empty($_POST['partnerID'])) {
                $partnerID = $_POST['partnerID'];
                $recordQuery = "SELECT * FROM actionLog WHERE partner_id = '$partnerID' AND logged_date BETWEEN '".$_POST["fromDate"]."' AND '".$_POST["toDate"]."' AND log_status = 'Pending' ";
                
                $recordResult = mysqli_query($conn, $recordQuery) or die("database error:" . mysqli_error($conn));
                $filterRecord = array();
                while ($records = mysqli_fetch_assoc($recordResult)) {
                    $filterRecord[] = $records;
                }
                
                if (count($filterRecord)) {
                    $fileName = "Transactions_" . date('Ymd') . ".csv";
                    header("Content-Description: File Transfer");
                    header("Content-Disposition: attachment; filename=$fileName");
                    header("Content-Type: application/csv;");
                    $file = fopen('php://output', 'w');
                    
                    $header = array(
                        'LOGGED DATE', 'LOG STATUS', 'STATUS', 'BLANK', 'DATE/TIME', 'CONTROL NUMBER', 'REFERENCE NUMBER', 'PAYOR', 'ADDRESS', 'ACCOUNT NUMBER',
                        'ACCOUNT NAME', 'AMOUNT PAID', 'CHARGE TO PARTNER', 'CHARGE TO CUSTOMER', 'CONTACT NUMBER', 'OTHER DETAILS',
                        'ML OUTLET', 'REGION', 'OPERATOR', 'PARTNER NAME', 'PARTNER ID', 'IMPORTED DATE', 'LOGGED BY'
                    );
                    fputcsv($file, $header);
                    
                    foreach ($filterRecord as $records) {
                        $recordData = array(
                            $records["logged_date"], $records["log_status"], $records["status"], $records["blank"], $records["date_time"], $records["control_number"],
                            $records["reference_number"], $records["payor"], $records["address"], $records["account_number"],
                            $records["account_name"], $records["amount_paid"], $records["charge_to_partner"],
                            $records["charge_to_customer"], $records["contact_number"], $records["other_details"],
                            $records["ml_outlet"], $records["region"], $records["operator"], $records["partner_name"],
                            $records["partner_id"], $records["imported_date"], $records["logged_by"]
                        );
                        fputcsv($file, $recordData);
                    }
                    
                    fclose($file);
                    exit;
                } else {
                    $noResult = '<label class="text-danger">There are no records to export.</label>';
                }
            } else {
                // Handle other export options (e.g., specific transactions, filtered by date, etc.)
                // Add your code here to handle different export scenarios
            }
    }


if (isset($_POST["export-closed-allPartnerTransactions"])) {
    $query = "SELECT * FROM actionLog ORDER BY logged_date ASC";
        $results = mysqli_query($conn, $query) or die("database error:" . mysqli_error($conn));
        $allRecord = array();
        while ($records = mysqli_fetch_assoc($results)) {
            $allRecord[] = $records;
        }
        $startDateMessage = '';
        $endDate = '';
        $noResult = '';
            if (isset($_POST["closed-remark"]) && isset($_POST['allPartner'])){
                $recordQuery = "SELECT * FROM actionLog WHERE partner_id IN (SELECT partner_id FROM partner_masterfile) AND logged_date BETWEEN '".$_POST["fromDate"]."' AND '".$_POST["toDate"]."' AND log_status = 'Closed' ";
                
                $recordResult = mysqli_query($conn, $recordQuery) or die("database error:" . mysqli_error($conn));
                $filterRecord = array();
                while ($records = mysqli_fetch_assoc($recordResult)) {
                    $filterRecord[] = $records;
                }
                
                if (count($filterRecord)) {
                    $fileName = "Transactions_" . date('Ymd') . ".csv";
                    header("Content-Description: File Transfer");
                    header("Content-Disposition: attachment; filename=$fileName");
                    header("Content-Type: application/csv;");
                    $file = fopen('php://output', 'w');
                    
                    $header = array(
                        'LOGGED DATE', 'LOG STATUS', 'STATUS', 'BLANK', 'DATE/TIME', 'CONTROL NUMBER', 'REFERENCE NUMBER', 'PAYOR', 'ADDRESS', 'ACCOUNT NUMBER',
                        'ACCOUNT NAME', 'AMOUNT PAID', 'CHARGE TO PARTNER', 'CHARGE TO CUSTOMER', 'CONTACT NUMBER', 'OTHER DETAILS',
                        'ML OUTLET', 'REGION', 'OPERATOR', 'PARTNER NAME', 'PARTNER ID', 'IMPORTED DATE', 'LOGGED BY'
                    );
                    fputcsv($file, $header);
                    
                    foreach ($filterRecord as $records) {
                        $recordData = array(
                            $records["logged_date"], $records["log_status"], $records["status"], $records["blank"], $records["date_time"], $records["control_number"],
                            $records["reference_number"], $records["payor"], $records["address"], $records["account_number"],
                            $records["account_name"], $records["amount_paid"], $records["charge_to_partner"],
                            $records["charge_to_customer"], $records["contact_number"], $records["other_details"],
                            $records["ml_outlet"], $records["region"], $records["operator"], $records["partner_name"],
                            $records["partner_id"], $records["imported_date"], $records["logged_by"]
                        );
                        fputcsv($file, $recordData);
                    }
                    
                    fclose($file);
                    exit;
                } else {
                    $noResult = '<label class="text-danger">There are no records to export.</label>';
                }
            } else {
                // Handle other export options (e.g., specific transactions, filtered by date, etc.)
                // Add your code here to handle different export scenarios
    }
}

if (isset($_POST["export-closed-partnerTransactions"])) {
    $query = "SELECT * FROM actionLog ORDER BY logged_date ASC";
        $results = mysqli_query($conn, $query) or die("database error:" . mysqli_error($conn));
        $allRecord = array();
        while ($records = mysqli_fetch_assoc($results)) {
            $allRecord[] = $records;
        }
        $startDateMessage = '';
        $endDate = '';
        $noResult = '';
            if (isset($_POST["closed-remark"]) && !empty($_POST['partnerID'])){
                $partnerID = $_POST['partnerID'];
                $recordQuery = "SELECT * FROM actionLog WHERE partner_id = '$partnerID' AND logged_date BETWEEN '".$_POST["fromDate"]."' AND '".$_POST["toDate"]."' AND log_status = 'Closed'  ";
                
                $recordResult = mysqli_query($conn, $recordQuery) or die("database error:" . mysqli_error($conn));
                $filterRecord = array();
                while ($records = mysqli_fetch_assoc($recordResult)) {
                    $filterRecord[] = $records;
                }
                
                if (count($filterRecord)) {
                    $fileName = "Transactions_" . date('Ymd') . ".csv";
                    header("Content-Description: File Transfer");
                    header("Content-Disposition: attachment; filename=$fileName");
                    header("Content-Type: application/csv;");
                    $file = fopen('php://output', 'w');
                    
                    $header = array(
                        'LOGGED DATE', 'LOG STATUS', 'STATUS', 'BLANK', 'DATE/TIME', 'CONTROL NUMBER', 'REFERENCE NUMBER', 'PAYOR', 'ADDRESS', 'ACCOUNT NUMBER',
                        'ACCOUNT NAME', 'AMOUNT PAID', 'CHARGE TO PARTNER', 'CHARGE TO CUSTOMER', 'CONTACT NUMBER', 'OTHER DETAILS',
                        'ML OUTLET', 'REGION', 'OPERATOR', 'PARTNER NAME', 'PARTNER ID', 'IMPORTED DATE', 'LOGGED BY'
                    );
                    fputcsv($file, $header);
                    
                    foreach ($filterRecord as $records) {
                        $recordData = array(
                            $records["logged_date"], $records["log_status"], $records["status"], $records["blank"], $records["date_time"], $records["control_number"],
                            $records["reference_number"], $records["payor"], $records["address"], $records["account_number"],
                            $records["account_name"], $records["amount_paid"], $records["charge_to_partner"],
                            $records["charge_to_customer"], $records["contact_number"], $records["other_details"],
                            $records["ml_outlet"], $records["region"], $records["operator"], $records["partner_name"],
                            $records["partner_id"], $records["imported_date"], $records["logged_by"]
                        );
                        fputcsv($file, $recordData);
                    }
                    
                    fclose($file);
                    exit;
                } else {
                    $noResult = '<label class="text-danger">There are no records to export.</label>';
                }
            } else {
                // Handle other export options (e.g., specific transactions, filtered by date, etc.)
                // Add your code here to handle different export scenarios
    }
}
if (isset($_POST["export-search-ref"])) {
    $query = "SELECT * FROM actionLog ORDER BY logged_date ASC";
        $results = mysqli_query($conn, $query) or die("database error:" . mysqli_error($conn));
        $allRecord = array();
        while ($records = mysqli_fetch_assoc($results)) {
            $allRecord[] = $records;
        }
        $startDateMessage = '';
        $endDate = '';
        $noResult = '';
            if (isset($_POST["search-ref"])) {
                $searchRef = $_POST['search-ref'];
                $recordQuery = "SELECT * FROM actionLog WHERE reference_number = '$searchRef' ";
                
                $recordResult = mysqli_query($conn, $recordQuery) or die("database error:" . mysqli_error($conn));
                $filterRecord = array();
                while ($records = mysqli_fetch_assoc($recordResult)) {
                    $filterRecord[] = $records;
                }
                
                if (count($filterRecord)) {
                    $fileName = "Transactions_" . date('Ymd') . ".csv";
                    header("Content-Description: File Transfer");
                    header("Content-Disposition: attachment; filename=$fileName");
                    header("Content-Type: application/csv;");
                    $file = fopen('php://output', 'w');
                    
                    $header = array(
                        'LOGGED DATE', 'LOG STATUS', 'STATUS', 'BLANK', 'DATE/TIME', 'CONTROL NUMBER', 'REFERENCE NUMBER', 'PAYOR', 'ADDRESS', 'ACCOUNT NUMBER',
                        'ACCOUNT NAME', 'AMOUNT PAID', 'CHARGE TO PARTNER', 'CHARGE TO CUSTOMER', 'CONTACT NUMBER', 'OTHER DETAILS',
                        'ML OUTLET', 'REGION', 'OPERATOR', 'PARTNER NAME', 'PARTNER ID', 'IMPORTED DATE', 'LOGGED BY'
                    );
                    fputcsv($file, $header);
                    
                    foreach ($filterRecord as $records) {
                        $recordData = array(
                            $records["logged_date"], $records["log_status"], $records["status"], $records["blank"], $records["date_time"], $records["control_number"],
                            $records["reference_number"], $records["payor"], $records["address"], $records["account_number"],
                            $records["account_name"], $records["amount_paid"], $records["charge_to_partner"],
                            $records["charge_to_customer"], $records["contact_number"], $records["other_details"],
                            $records["ml_outlet"], $records["region"], $records["operator"], $records["partner_name"],
                            $records["partner_id"], $records["imported_date"], $records["logged_by"]
                        );
                        fputcsv($file, $recordData);
                    }
                    
                    fclose($file);
                    exit;
                } else {
                    $noResult = '<label class="text-danger">There are no records to export.</label>';
                }
            } else {
                // Handle other export options (e.g., specific transactions, filtered by date, etc.)
                // Add your code here to handle different export scenarios
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Action Log Report</title>
    <link href="../css/actionLog.css?v=<?php echo time(); ?>" rel="stylesheet"> 
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
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

        <h3>Action Log Reports</h3>
    <form class="report-check" action="" method="POST">
        <div class="search-inp">
            <input type="text" onkeyup="checkButtonState()" title="Search Reference Number" placeholder="Search Reference Number" id="search-ref" name="search-ref" class="search-ref" value="<?php if(isset($_POST['search-ref'])) echo $_POST['search-ref'];?>">
            <input type="submit" title="Search" id="search" name="search" class="search" value="Search">
        </div>
        <div class="transactions-div">
            <div class="all-reports">
                <div class="transaction-head">
                    <h4>Logged Status</h4>
                </div>
                <div class="checkbox">
                    <div class="all">
                        <input type="checkbox" onchange="checkButtonState()" class="checkmark" placeholder="checkmark" title="checkmark" id="all" name="all" value="all" <?php if(isset($_POST['all'])) echo 'checked = "checked"'; ?> />
                        <label for="all">All</label>
                    </div>
                    <div class="pending">
                        <input type="checkbox" onchange="checkButtonState()" class="checkmark" placeholder="checkmark" title="checkmark" id="pending" name="pending" value="pending" <?php if(isset($_POST['pending'])) echo 'checked = "checked"'; ?>/>
                        <label for="pending">Pending</label>
                    </div>
                    <div class="closed">
                        <input type="checkbox" onchange="checkButtonState()" class="checkmark" placeholder="checkmark" title="checkmark" id="closed-remark" name="closed-remark" value="closed-remark" <?php if(isset($_POST['closed-remark'])) echo 'checked = "checked"'; ?>/>
                        <label for="closed-remark">Closed</label>
                    </div>
                </div>
            </div>

            <div class="all-partners">
                <div class="partner-head">
                    <h4>Partners Transactions</h4>
                </div>
                <div class="partner-transactions">
                    <div class="all-partnerCheck">
                        <input type="checkbox" onchange="checkButtonState()" class="checkmark" placeholder="checkmark" title="checkmark" id="allPartner" name="allPartner" value="All Partner" <?php if(isset($_POST['allPartner'])) echo 'checked="checked"'; ?>/>
                        <label for="allPartner">All Partners</label>
                    </div>
                    <select autofocus class="form-control" onchange="checkButtonState()" id="partner-select" name="partnerName">
                        <option value="Select Partner Name" disabled selected>-- Select Partner Name --</option>
                        <?php
                        foreach ($options as $option) {
                            $selected = '';
                            if (isset($_POST['partnerName']) && $_POST['partnerName'] == $option['partner_name']) {
                                $selected = 'selected="selected"';
                            }
                            echo '<option data-partner-code="' . $option['partner_id'] . '" ' . $selected . '>' . $option['partner_name'] . '</option>';
                        }
                        ?>
                    </select>
                    <input style="display:none;" type="text" id="partnerID" name="partnerID" value="<?php echo isset($_POST['partnerID']) ? $_POST['partnerID'] : ''; ?>" readonly required>
                    <div class="filter-date">
                        <div class="form-group">
                            <label>From Date:</label>
                            <input autofocus type="date" id="fromDate" name="fromDate" value="<?php if(isset($_POST['fromDate'])){ echo $_POST['fromDate']; } ?>" class="from-date">
                        </div>
                        <div class="form-group">
                            <label>To Date:</label>
                            <input autofocus type="date" id="toDate" name="toDate" value="<?php if(isset($_POST['toDate'])){ echo $_POST['toDate']; } ?>" class="from-date">
                        </div>
                    </div>   
                </div>   
            </div>
            <div class="div-container">
                <div class="new">
                    <input type="button" id="new" class="new" name="new" value="New">
                </div>
                <div class="proceed">
                    
                    <input type="submit" id="display" class="disaplay" name="display" value="Display">
                </div>
            </div>
        </div>

        <?php if (isset($_POST["all"]) || isset($_POST["pending"]) || isset($_POST["closed-remark"]) || isset($_POST['allPartner']) || isset($_POST['search-ref']) || isset($_POST['partnerID'])): ?>
        <div class="btn-export">
            <?php if (isset($_POST["all"]) && isset($_POST['allPartner']) && empty($_POST['partnerID'])): ?>
                <input type="submit" name="export-allPartner-transactions" id="export-all" value="Export to CSV" class="btn btn-info" />
            <?php endif; ?>

            <?php if (isset($_POST["all"]) && !empty($_POST['partnerID'])): ?>
                <input type="submit" name="export-partner-transactions" id="export-all" value="Export to CSV" class="btn btn-info" />
            <?php endif; ?>
            
            <?php if (isset($_POST["pending"]) && isset($_POST['allPartner'])): ?>
                <input type="submit" name="export-pending-allPartnerTransactions" id="export-pending" value="Export to CSV" class="btn btn-info" />
            <?php endif; ?>

            <?php if (isset($_POST["pending"]) && !empty($_POST['partnerID'])): ?>
                <input type="submit" name="export-pending-partnerTransactions" id="export-pending" value="Export to CSV" class="btn btn-info" />
            <?php endif; ?>
            
            <?php if (isset($_POST["closed-remark"]) && isset($_POST['allPartner'])): ?>
                <input type="submit" name="export-closed-allPartnerTransactions" id="export-closed" value="Export to CSV" class="btn btn-info" />
            <?php endif; ?>

            <?php if (isset($_POST["closed-remark"]) && !empty($_POST['partnerID'])): ?>
                <input type="submit" name="export-closed-partnerTransactions" id="export-closed" value="Export to CSV" class="btn btn-info" />
            <?php endif; ?>

            <?php if (isset($_POST["search-ref"]) && !empty($_POST["search-ref"])): ?>
                <input type="submit" name="export-search-ref" id="export-search-ref" value="Export to CSV" class="btn btn-info" />
            <?php endif; ?>

        </div>
    <?php endif; ?>
    </form>
</div>


<div class="table-container">
    <!-- The Table -->
    <table id="myTable" class="table" name="table">
        <thead>
            <tr>
                <th>Logged Date</th>
                <th>Log Status</th>
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
                <th>Logged By</th>
            </tr>
        </thead>
        <tbody>
            
        <?php
            $totalAmount = 0;
            if (isset($_POST['display'])) { // Button display
                if (isset($_POST['all']) && isset($_POST['allPartner'])) {
                    $partnerID = $_POST['partnerID'];
                    $select = "SELECT * FROM actionLog WHERE partner_id IN (SELECT partner_id FROM partner_masterfile) AND logged_date BETWEEN '" . $_POST["fromDate"] . "' AND '" . $_POST["toDate"] . "' ";

                    $result = mysqli_query($conn, $select); // Execute query in the database

                    if ($result->num_rows > 0) { // Display if there are transactions available in the actionLog table
                        while ($row = $result->fetch_assoc()) {
                            $totalAmount += (float)$row['amount_paid'];
                        }

                        // Format total amount with commas
                        $formattedTotalAmount = number_format($totalAmount, 2);

                        // Reset the result pointer to the beginning
                        mysqli_data_seek($result, 0);

                        // Display the total row
                        ?>
                        <tr>
                            <td colspan="10" style="text-align:right;"><strong>Total:</strong></td>
                            <td style="text-align:right;background-color: red; color: white; font-size:12px;"><strong><?php echo $formattedTotalAmount; ?></strong></td>
                            <td colspan="11"></td>
                        </tr>
                        <?php

                        while ($row = $result->fetch_assoc()) { // Display the records from the database to the page
                            $formattedTotalAmount = number_format((float)$row['amount_paid'], 2);
                            ?>
                            <tr>
                                <td style="text-align:left;"><?php echo $row['logged_date']; ?></td>
                                <td style="text-align:left;"><?php echo $row['log_status']; ?></td>
                                <td style="text-align:left;"><?php echo $row['status']; ?></td>
                                <td style="text-align:left;"><?php echo $row['date_time']; ?></td>
                                <td style="text-align:left;"><?php echo $row['control_number']; ?></td>
                                <td style="text-align:left;"><?php echo $row['reference_number']; ?></td>
                                <td style="text-align:left;"><?php echo $row['payor']; ?></td>
                                <td style="text-align:left;"><?php echo $row['address']; ?></td>
                                <td style="text-align:left;"><?php echo $row['account_number']; ?></td>
                                <td style="text-align:left;"><?php echo $row['account_name']; ?></td>
                                <td style="text-align:right;"><?php if ($formattedTotalAmount != 0) {
                                                                        echo $formattedTotalAmount;
                                                                    } ?></td>
                                <td style="text-align:right;"><?php echo $row['charge_to_partner']; ?></td>
                                <td style="text-align:right;"><?php echo $row['charge_to_customer']; ?></td>
                                <td style="text-align:left;"><?php echo $row['contact_number']; ?></td>
                                <td style="text-align:left;"><?php echo $row['other_details']; ?></td>
                                <td style="text-align:left;"><?php echo $row['ml_outlet']; ?></td>
                                <td style="text-align:left;"><?php echo $row['region']; ?></td>
                                <td style="text-align:left;"><?php echo $row['operator']; ?></td>
                                <td style="text-align:left;"><?php echo $row['partner_name']; ?></td>
                                <td style="text-align:left;"><?php echo $row['partner_id']; ?></td>
                                <td style="text-align:center;"><?php echo $row['imported_date']; ?></td>
                                <td style="text-align:center;"><?php echo $row['imported_by']; ?></td>
                                <td style="text-align:center;"><?php echo $row['remark_by1']; ?></td>
                            </tr>
                        <?php
                        }
                    } else {
                        ?>
                        <tr>
                            <td style="font-weight:bold;font-size:16px;text-align:center; background-color:transparent;color:#d70c0c;" colspan="23" id="no_data">No transactions found in the Action Log file</td>
                        </tr>
                    <?php
                    }
                }
            }
            $totalAmount = 0;

            if (isset($_POST['display'])) { // Button display
                if (isset($_POST['all']) && !empty($_POST['partnerID'])) {
                    $partnerID = $_POST['partnerID'];
                    $select = "SELECT * FROM actionLog WHERE partner_id = '$partnerID' AND logged_date BETWEEN '" . $_POST["fromDate"] . "' AND '" . $_POST["toDate"] . "'";
                    $res = mysqli_query($conn, $select);
            
                    if ($res->num_rows > 0) {
                        while ($row = $res->fetch_assoc()) {
                            $totalAmount += (float)$row['amount_paid'];
                        }
            
                        // Format total amount with commas
                        $formattedTotalAmount = number_format($totalAmount, 2);
            
                        // Reset the result pointer to the beginning
                        $res->data_seek(0);
            
                        // Display the total row
                        ?>
                        <tr>
                            <td colspan="10" style="text-align:right;"><strong>Total:</strong></td>
                            <td style="text-align:right;background-color: red; color: white; font-size:12px;"><strong><?php echo $formattedTotalAmount; ?></strong></td>
                            <td colspan="11"></td>
                        </tr>
                        <?php
            
                        while ($row = $res->fetch_assoc()) {
                            $formattedTotalAmount = number_format((float)$row['amount_paid'], 2);
                            ?>
                            <tr>
                                <td style="text-align:left;"><?php echo $row['logged_date']; ?></td>
                                <td style="text-align:left;"><?php echo $row['log_status']; ?></td>
                                <td style="text-align:left;"><?php echo $row['status']; ?></td>
                                <td style="text-align:left;"><?php echo $row['date_time']; ?></td>
                                <td style="text-align:left;"><?php echo $row['control_number']; ?></td>
                                <td style="text-align:left;"><?php echo $row['reference_number']; ?></td>
                                <td style="text-align:left;"><?php echo $row['payor']; ?></td>
                                <td style="text-align:left;"><?php echo $row['address']; ?></td>
                                <td style="text-align:left;"><?php echo $row['account_number']; ?></td>
                                <td style="text-align:left;"><?php echo $row['account_name']; ?></td>
                                <td style="text-align:right;"><?php if ($formattedTotalAmount != 0) {
                                                                        echo $formattedTotalAmount;
                                                                    } ?></td>
                                <td style="text-align:right;"><?php echo $row['charge_to_partner']; ?></td>
                                <td style="text-align:right;"><?php echo $row['charge_to_customer']; ?></td>
                                <td style="text-align:left;"><?php echo $row['contact_number']; ?></td>
                                <td style="text-align:left;"><?php echo $row['other_details']; ?></td>
                                <td style="text-align:left;"><?php echo $row['ml_outlet']; ?></td>
                                <td style="text-align:left;"><?php echo $row['region']; ?></td>
                                <td style="text-align:left;"><?php echo $row['operator']; ?></td>
                                <td style="text-align:left;"><?php echo $row['partner_name']; ?></td>
                                <td style="text-align:left;"><?php echo $row['partner_id']; ?></td>
                                <td style="text-align:center;"><?php echo $row['imported_date']; ?></td>
                                <td style="text-align:center;"><?php echo $row['imported_by']; ?></td>
                                <td style="text-align:center;"><?php echo $row['remark_by1']; ?></td>
                            </tr>
                        <?php
                        }
                    } else { ?>
                        <tr>
                            <td style="font-weight:bold;font-size:16px;text-align:center; background-color:transparent;color:#d70c0c;" colspan="23" id="no_data">No transactions found in the Action Log file</td>
                        </tr>
                <?php }
                }
            }

            $totalAmount = 0;
            
            if (isset($_POST['display'])) { // Button display
                if (isset($_POST['pending']) && isset($_POST['allPartner'])) {
                    $partnerID = $_POST['partnerID'];
                    $select = "SELECT * FROM actionLog WHERE partner_id IN (SELECT partner_id FROM partner_masterfile) AND logged_date BETWEEN '" . $_POST["fromDate"] . "' AND '" . $_POST["toDate"] . "' AND log_status = 'Pending' ";
            
                    $res = mysqli_query($conn, $select); // Execute query in the database
                    if ($res->num_rows > 0) { // Display if there are transactions available in the actionLog table
                        while ($row = $res->fetch_assoc()) {
                            $totalAmount += (float)$row['amount_paid'];
                        }
            
                        // Format total amount with commas
                        $formattedTotalAmount = number_format($totalAmount, 2);
            
                        // Reset the result pointer to the beginning
                        $res->data_seek(0);
            
                        // Display the total row
                        ?>
                        <tr>
                            <td colspan="10" style="text-align:right;"><strong>Total:</strong></td>
                            <td style="text-align:right;background-color: red; color: white; font-size:12px;"><strong><?php echo $formattedTotalAmount; ?></strong></td>
                            <td colspan="11"></td>
                        </tr>
                        <?php
                        while ($row = $res->fetch_assoc()) { // Display the records from the database to the page
                            $formattedTotalAmount = number_format((float)$row['amount_paid'], 2);
                            ?>
                            <tr>
                                <td style="text-align:left;"><?php echo $row['logged_date']; ?></td>
                                <td style="text-align:left;"><?php echo $row['log_status']; ?></td>
                                <td style="text-align:left;"><?php echo $row['status']; ?></td>
                                <td style="text-align:left;"><?php echo $row['date_time']; ?></td>
                                <td style="text-align:left;"><?php echo $row['control_number']; ?></td>
                                <td style="text-align:left;"><?php echo $row['reference_number']; ?></td>
                                <td style="text-align:left;"><?php echo $row['payor']; ?></td>
                                <td style="text-align:left;"><?php echo $row['address']; ?></td>
                                <td style="text-align:left;"><?php echo $row['account_number']; ?></td>
                                <td style="text-align:left;"><?php echo $row['account_name']; ?></td>
                                <td style="text-align:right;"><?php if ($formattedTotalAmount != 0) {
                                                                        echo $formattedTotalAmount;
                                                                    } ?></td>
                                <td style="text-align:right;"><?php echo $row['charge_to_partner']; ?></td>
                                <td style="text-align:right;"><?php echo $row['charge_to_customer']; ?></td>
                                <td style="text-align:left;"><?php echo $row['contact_number']; ?></td>
                                <td style="text-align:left;"><?php echo $row['other_details']; ?></td>
                                <td style="text-align:left;"><?php echo $row['ml_outlet']; ?></td>
                                <td style="text-align:left;"><?php echo $row['region']; ?></td>
                                <td style="text-align:left;"><?php echo $row['operator']; ?></td>
                                <td style="text-align:left;"><?php echo $row['partner_name']; ?></td>
                                <td style="text-align:left;"><?php echo $row['partner_id']; ?></td>
                                <td style="text-align:center;"><?php echo $row['imported_date']; ?></td>
                                <td style="text-align:center;"><?php echo $row['imported_by']; ?></td>
                                <td style="text-align:center;"><?php echo $row['remark_by1']; ?></td>
                            </tr>
                        <?php
                        }
                    } else {
                        ?>
                        <tr>
                            <td style="font-weight:bold;font-size:16px;text-align:center; background-color:transparent;color:#d70c0c;" colspan="23" id="no_data">No transactions found in the Action Log file</td>
                        </tr>
                        <?php
                    }
                }
            }
            $totalAmount = 0;
            
            if (isset($_POST['display'])) { // Button display
                    if(isset($_POST['pending']) && !empty($_POST['partnerID'])){
                        $partnerID = $_POST['partnerID'];
                        $select = "SELECT * FROM actionLog WHERE partner_id = '$partnerID' AND logged_date BETWEEN '".$_POST["fromDate"]."' AND '".$_POST["toDate"]."' AND log_status = 'Pending' ";

                    $res = mysqli_query($conn, $select); // Execute query in the database
                    if ($res->num_rows > 0) { // Display if there are transactions available in the actionLog table
                        while ($row = $res->fetch_assoc()) {
                            $totalAmount += (float)$row['amount_paid'];
                        }
            
                        // Format total amount with commas
                        $formattedTotalAmount = number_format($totalAmount, 2);
            
                        // Reset the result pointer to the beginning
                        $res->data_seek(0);
            
                        // Display the total row
                        ?>
                        <tr>
                            <td colspan="10" style="text-align:right;"><strong>Total:</strong></td>
                            <td style="text-align:right;background-color: red; color: white; font-size:12px;"><strong><?php echo $formattedTotalAmount; ?></strong></td>
                            <td colspan="11"></td>
                        </tr>
                        <?php
                        while ($row = $res->fetch_assoc()) { // Display the records from the database to the page
                            $formattedTotalAmount = number_format((float)$row['amount_paid'], 2);

                            ?>
                            <tr>
                                <td style="text-align:left;"><?php echo $row['logged_date']; ?></td>
                                <td style="text-align:left;"><?php echo $row['log_status']; ?></td>
                                <td style="text-align:left;"><?php echo $row['status']; ?></td>
                                <td style="text-align:left;"><?php echo $row['date_time']; ?></td>
                                <td style="text-align:left;"><?php echo $row['control_number']; ?></td>
                                <td style="text-align:left;"><?php echo $row['reference_number']; ?></td>
                                <td style="text-align:left;"><?php echo $row['payor']; ?></td>
                                <td style="text-align:left;"><?php echo $row['address']; ?></td>
                                <td style="text-align:left;"><?php echo $row['account_number']; ?></td>
                                <td style="text-align:left;"><?php echo $row['account_name']; ?></td>
                                <td style="text-align:right;"><?php if ($formattedTotalAmount != 0) {
                                                                        echo $formattedTotalAmount;
                                                                    } ?></td>
                                <td style="text-align:right;"><?php echo $row['charge_to_partner']; ?></td>
                                <td style="text-align:right;"><?php echo $row['charge_to_customer']; ?></td>
                                <td style="text-align:left;"><?php echo $row['contact_number']; ?></td>
                                <td style="text-align:left;"><?php echo $row['other_details']; ?></td>
                                <td style="text-align:left;"><?php echo $row['ml_outlet']; ?></td>
                                <td style="text-align:left;"><?php echo $row['region']; ?></td>
                                <td style="text-align:left;"><?php echo $row['operator']; ?></td>
                                <td style="text-align:left;"><?php echo $row['partner_name']; ?></td>
                                <td style="text-align:left;"><?php echo $row['partner_id']; ?></td>
                                <td style="text-align:center;"><?php echo $row['imported_date']; ?></td>
                                <td style="text-align:center;"><?php echo $row['imported_by']; ?></td>
                                <td style="text-align:center;"><?php echo $row['remark_by1']; ?></td>
                            </tr>
                        <?php
                        }
                    } else {
                        ?>
                        <tr>
                            <td style="font-weight:bold;font-size:16px;text-align:center; background-color:transparent;color:#d70c0c;" colspan="23" id="no_data">No transactions found in the Action Log file</td>
                        </tr>
                        <?php
                    }
                }
            }
            $totalAmount = 0;

            if (isset($_POST['display'])) { // Button display
                    if(isset($_POST['closed-remark']) && isset($_POST['allPartner'])){
                        $partnerID = $_POST['partnerID'];
                        $select = "SELECT * FROM actionLog WHERE partner_id IN (SELECT partner_id FROM partner_masterfile) AND logged_date BETWEEN '".$_POST["fromDate"]."' AND '".$_POST["toDate"]."' AND log_status = 'Closed' ";
                        $res = mysqli_query($conn, $select); // Execute query in the database   
                    if ($res->num_rows > 0) { // Display if there are transactions available in the actionLog table
                        $res = mysqli_query($conn, $select); // Execute query in the database
                        while ($row = $res->fetch_assoc()) {
                            $totalAmount += (float)$row['amount_paid'];
                        }
            
                        // Format total amount with commas
                        $formattedTotalAmount = number_format($totalAmount, 2);
            
                        // Reset the result pointer to the beginning
                        $res->data_seek(0);
            
                        // Display the total row
                        ?>
                        <tr>
                            <td colspan="10" style="text-align:right;"><strong>Total:</strong></td>
                            <td style="text-align:right;background-color: red; color: white; font-size:12px;"><strong><?php echo $formattedTotalAmount; ?></strong></td>
                            <td colspan="11"></td>
                        </tr>
                        <?php
                        while ($row = $res->fetch_assoc()) { // Display the records from the database to the page
                            $formattedTotalAmount = number_format((float)$row['amount_paid'], 2);
                            ?>
                            <tr>
                                <td style="text-align:left;"><?php echo $row['logged_date']; ?></td>
                                <td style="text-align:left;"><?php echo $row['log_status']; ?></td>
                                <td style="text-align:left;"><?php echo $row['status']; ?></td>
                                <td style="text-align:left;"><?php echo $row['date_time']; ?></td>
                                <td style="text-align:left;"><?php echo $row['control_number']; ?></td>
                                <td style="text-align:left;"><?php echo $row['reference_number']; ?></td>
                                <td style="text-align:left;"><?php echo $row['payor']; ?></td>
                                <td style="text-align:left;"><?php echo $row['address']; ?></td>
                                <td style="text-align:left;"><?php echo $row['account_number']; ?></td>
                                <td style="text-align:left;"><?php echo $row['account_name']; ?></td>
                                <td style="text-align:right;"><?php if ($formattedTotalAmount != 0) {
                                                                        echo $formattedTotalAmount;
                                                                    } ?></td>
                                <td style="text-align:right;"><?php echo $row['charge_to_partner']; ?></td>
                                <td style="text-align:right;"><?php echo $row['charge_to_customer']; ?></td>
                                <td style="text-align:left;"><?php echo $row['contact_number']; ?></td>
                                <td style="text-align:left;"><?php echo $row['other_details']; ?></td>
                                <td style="text-align:left;"><?php echo $row['ml_outlet']; ?></td>
                                <td style="text-align:left;"><?php echo $row['region']; ?></td>
                                <td style="text-align:left;"><?php echo $row['operator']; ?></td>
                                <td style="text-align:left;"><?php echo $row['partner_name']; ?></td>
                                <td style="text-align:left;"><?php echo $row['partner_id']; ?></td>
                                <td style="text-align:center;"><?php echo $row['imported_date']; ?></td>
                                <td style="text-align:center;"><?php echo $row['imported_by']; ?></td>
                                <td style="text-align:center;"><?php echo $row['remark_by1']; ?></td>
                            </tr>
                        <?php
                        }
                    } else {
                        ?>
                        <tr>
                            <td style="font-weight:bold;font-size:16px;text-align:center; background-color:transparent;color:#d70c0c;" colspan="23" id="no_data">No transactions found in the Action Log file</td>
                        </tr>
                        <?php
                    }
                }
            }
            $totalAmount = 0;
            
            if (isset($_POST['display'])) { // Button display
                    if(isset($_POST['closed-remark']) && !empty($_POST['partnerID'])){
                        $partnerID = $_POST['partnerID'];
                        $select = "SELECT * FROM actionLog WHERE partner_id = '$partnerID' AND logged_date BETWEEN '".$_POST["fromDate"]."' AND '".$_POST["toDate"]."' AND log_status = 'Closed' ";

                        $res = mysqli_query($conn, $select); // Execute query in the database
                        if ($res->num_rows > 0) { // Display if there are transactions available in the actionLog table
                            $res = mysqli_query($conn, $select); // Execute query in the database
                        while ($row = $res->fetch_assoc()) {
                            $totalAmount += (float)$row['amount_paid'];
                        }
            
                        // Format total amount with commas
                        $formattedTotalAmount = number_format($totalAmount, 2);
            
                        // Reset the result pointer to the beginning
                        $res->data_seek(0);
            
                        // Display the total row
                        ?>
                        <tr>
                            <td colspan="10" style="text-align:right;"><strong>Total:</strong></td>
                            <td style="text-align:right;background-color: red; color: white; font-size:12px;"><strong><?php echo $formattedTotalAmount; ?></strong></td>
                            <td colspan="11"></td>
                        </tr>
                        <?php
                        while ($row = $res->fetch_assoc()) { // Display the records from the database to the page
                            $formattedTotalAmount = number_format((float)$row['amount_paid'], 2);
                            ?>
                            <tr>
                                <td style="text-align:left;"><?php echo $row['logged_date']; ?></td>
                                <td style="text-align:left;"><?php echo $row['log_status']; ?></td>
                                <td style="text-align:left;"><?php echo $row['status']; ?></td>
                                <td style="text-align:left;"><?php echo $row['date_time']; ?></td>
                                <td style="text-align:left;"><?php echo $row['control_number']; ?></td>
                                <td style="text-align:left;"><?php echo $row['reference_number']; ?></td>
                                <td style="text-align:left;"><?php echo $row['payor']; ?></td>
                                <td style="text-align:left;"><?php echo $row['address']; ?></td>
                                <td style="text-align:left;"><?php echo $row['account_number']; ?></td>
                                <td style="text-align:left;"><?php echo $row['account_name']; ?></td>
                                <td style="text-align:right;"><?php if ($formattedTotalAmount != 0) {
                                                                        echo $formattedTotalAmount;
                                                                    } ?></td>
                                <td style="text-align:right;"><?php echo $row['charge_to_partner']; ?></td>
                                <td style="text-align:right;"><?php echo $row['charge_to_customer']; ?></td>
                                <td style="text-align:left;"><?php echo $row['contact_number']; ?></td>
                                <td style="text-align:left;"><?php echo $row['other_details']; ?></td>
                                <td style="text-align:left;"><?php echo $row['ml_outlet']; ?></td>
                                <td style="text-align:left;"><?php echo $row['region']; ?></td>
                                <td style="text-align:left;"><?php echo $row['operator']; ?></td>
                                <td style="text-align:left;"><?php echo $row['partner_name']; ?></td>
                                <td style="text-align:left;"><?php echo $row['partner_id']; ?></td>
                                <td style="text-align:center;"><?php echo $row['imported_date']; ?></td>
                                <td style="text-align:center;"><?php echo $row['imported_by']; ?></td>
                                <td style="text-align:center;"><?php echo $row['remark_by1']; ?></td>
                            </tr>
                        <?php
                        }
                    } else {
                        ?>
                        <tr>
                            <td style="font-weight:bold;font-size:16px;text-align:center; background-color:transparent;color:#d70c0c;" colspan="23" id="no_data">No transactions found in the Action Log file</td>
                        </tr>
                        <?php
                    }
                }
            }

            if(isset($_POST['search'])){
                if(!empty($_POST['search-ref'])){
                        $reference_num = $_POST['search-ref'];
                        $select = "SELECT * FROM actionLog WHERE reference_number = '$reference_num'";
                        $search_res = mysqli_query($conn, $select);
                        if($search_res->num_rows > 0){
                            while($row = $search_res->fetch_assoc()){
                                ?>
                                <tr>
                                    <td style="text-align:left;"><?php echo $row['logged_date']; ?></td>
                                    <td style="text-align:left;"><?php echo $row['log_status']; ?></td>
                                    <td style="text-align:left;"><?php echo $row['status']; ?></td>
                                    <td style="text-align:left;"><?php echo $row['date_time']; ?></td>
                                    <td style="text-align:left;"><?php echo $row['control_number']; ?></td>
                                    <td style="text-align:left;"><?php echo $row['reference_number']; ?></td>
                                    <td style="text-align:left;"><?php echo $row['payor']; ?></td>
                                    <td style="text-align:left;"><?php echo $row['address']; ?></td>
                                    <td style="text-align:left;"><?php echo $row['account_number']; ?></td>
                                    <td style="text-align:left;"><?php echo $row['account_name']; ?></td>
                                    <td style="text-align:right;"><?php echo $row['amount_paid']; ?></td>
                                    <td style="text-align:right;"><?php echo $row['charge_to_partner']; ?></td>
                                    <td style="text-align:right;"><?php echo $row['charge_to_customer']; ?></td>
                                    <td style="text-align:left;"><?php echo $row['contact_number']; ?></td>
                                    <td style="text-align:left;"><?php echo $row['other_details']; ?></td>
                                    <td style="text-align:left;"><?php echo $row['ml_outlet']; ?></td>
                                    <td style="text-align:left;"><?php echo $row['region']; ?></td>
                                    <td style="text-align:left;"><?php echo $row['operator']; ?></td>
                                    <td style="text-align:left;"><?php echo $row['partner_name']; ?></td>
                                    <td style="text-align:left;"><?php echo $row['partner_id']; ?></td>
                                    <td style="text-align:center;"><?php echo $row['imported_date']; ?></td>
                                    <td style="text-align:center;"><?php echo $row['imported_by']; ?></td>
                                    <td style="text-align:center;"><?php echo $row['remark_by1']; ?></td>
                                </tr>
                            <?php
                            }
                        } else { ?>
                            <tr>
                                <td style="font-weight:bold;font-size:16px;text-align:center; background-color:transparent;color:#d70c0c;" colspan="23" id="no_data">No transactions found in the Action Log file</td>
                            </tr>
                        <?php }
                    }
            }
            ?>
        
        </tbody>
    </table>
    </div>
</tbody>
</table>
</div>
<script>
    function checkButtonState() {
        var displayButton = document.getElementById("display");
        var allCheckbox = document.getElementById("all");
        var pendingCheckbox = document.getElementById("pending");
        var closedCheckbox = document.getElementById("closed-remark");

        var searchReference = document.getElementById('search-ref');
        var allPartner = document.getElementById('allPartner');
        var selectPartner = document.getElementById('partner-select');
        var fromDate = document.getElementById('fromDate');
        var toDate = document.getElementById('toDate');
        var searchbtn = document.getElementById('search');
        var displaySearchbtn = document.getElementById('display-search');
        var partnerID = document.getElementById('partnerID');

        if(allCheckbox.checked && pendingCheckbox.checked){
            alert("INVALID OPTION SELECTED!");
            pendingCheckbox.checked=false;
            allCheckbox.checked =false;
        }
        if(allCheckbox.checked && closedCheckbox.checked){
            alert("INVALID OPTION SELECTED!");
            closedCheckbox.checked=false;
            allCheckbox.checked =false;
        }
        if(closedCheckbox.checked && pendingCheckbox.checked){
            alert("INVALID OPTION SELECTED!");
            closedCheckbox.checked=false;
            pendingCheckbox.checked=false;
        }

        if(searchReference.value !== ""){
            displayButton.disabled=true;
        }else{
            displayButton.disabled=false;
        }
        if(allCheckbox.checked){
            searchReference.value = "";
            fromDate.setAttribute('required', 'required'); 
            toDate.setAttribute('required', 'required');  
        }else{
            fromDate.removeAttribute('required'); 
            toDate.removeAttribute('required'); 
        }
        if(pendingCheckbox.checked){
            searchReference.value = "";
            fromDate.setAttribute('required', 'required'); 
            toDate.setAttribute('required', 'required');  
        }
        if(closedCheckbox.checked){
            searchReference.value = "";
            fromDate.setAttribute('required', 'required'); 
            toDate.setAttribute('required', 'required');  
        }
        if(allPartner.checked){
            searchReference.value = "";
            partnerID.value = "";
            selectPartner.value = "";
            fromDate.value = "";
            toDate.value = "";
        }
    

        var entitySelect = document.getElementById('partner-select');
        var entityCodeInput = document.getElementById('partnerID');
        var selectedOption = entitySelect.options[entitySelect.selectedIndex];
        entityCodeInput.value = selectedOption.getAttribute('data-partner-code');
    }
        var displayButton = document.getElementById("display");
        var allCheckbox = document.getElementById("all");
        var pendingCheckbox = document.getElementById("pending");
        var closedCheckbox = document.getElementById("closed-remark");

        var searchReference = document.getElementById('search-ref');
        var allPartner = document.getElementById('allPartner');
        var selectPartner = document.getElementById('partner-select');
        var fromDate = document.getElementById('fromDate');
        var toDate = document.getElementById('toDate');
        var searchbtn = document.getElementById('search');
        var displaySearchbtn = document.getElementById('display-search');
        var partnerID = document.getElementById('partnerID');
        var selectPartner = document.getElementById('partner-select');
        var newbtn =document.getElementById('new');

        newbtn.onclick =function(){
            allCheckbox.checked = false;
            pendingCheckbox.checked = false;
            closedCheckbox.checked = false;
            allPartner.checked = false;
            allCheckbox.disabled = false;
            pendingCheckbox.disabled = false;
            closedCheckbox.disabled = false;
            allPartner.disabled = false;
            fromDate.value = "";
            toDate.value = "";
            selectPartner.value = "";
            searchReference.value = "";
        }
        displayButton.onclick = function(){
            allCheckbox.disabled=false;
            pendingCheckbox.disabled = false;
            closedCheckbox.disabled =false;
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



</body>
</html>