<?php
// Connect to the database
include '../../../config/config.php';
require '../../../vendor/autoload.php';

// Start the session
session_start();


if (isset($_SESSION['user_type'])) {
    $current_user_email = '';
    if ($_SESSION['user_type'] === 'admin' && isset($_SESSION['admin_email'])) {
        $current_user_email = $_SESSION['admin_email'];
    } elseif ($_SESSION['user_type'] === 'user' && isset($_SESSION['user_email'])) {
        $current_user_email = $_SESSION['user_email'];
    }
}

$debug_status_values = []; // Array to store status values for debugging
$show_debug = false; // Add this flag to control debug display

if (isset($_POST['generate'])) { 

    $partnerID = $_POST['partnerName']; // This will now contain partner ID
    $fromDate = $_POST['fromDate'];
    $toDate = $_POST['toDate'];

    // First, let's check what status values exist in the database - for debugging purposes
    $debug_query = "SELECT DISTINCT status FROM billspayment_transaction 
                    WHERE datetime BETWEEN '$fromDate 00:00:00' AND '$toDate 23:59:59' 
                    AND status IS NOT NULL AND status != ''";
    $debug_result = $conn->query($debug_query);
    
    $debug_status_values = []; // Ensure this is always initialized as an array
    if ($debug_result && $debug_result->num_rows > 0) {
        while ($debug_row = $debug_result->fetch_assoc()) {
            $debug_status_values[] = $debug_row['status'];
        }
    }
    
    // Modified query to use partner_id for filtering
    $total_query = "SELECT bt.partner_name,
                    bt.partner_id,
                    COUNT(DISTINCT bt.reference_no) AS total_volume,
                    SUM(bt.amount_paid) AS total_principal,
                    SUM(bt.charge_to_customer + bt.charge_to_partner) AS total_charge
                    FROM billspayment_transaction bt
                    WHERE bt.datetime BETWEEN '$fromDate 00:00:00' AND '$toDate 23:59:59'
                    AND (bt.status IS NULL OR bt.status = '' OR bt.status NOT LIKE '%*%')";

    if ($partnerID !== 'All') {
        $total_query .= " AND bt.partner_id = '$partnerID'";
    }

    $total_query .= " GROUP BY bt.partner_name, bt.partner_id";
    
    // Apply same logic to adjustment query
    $adj_query = "SELECT 
                    bt.partner_name,
                    bt.partner_id,
                    COUNT(DISTINCT bt.reference_no) AS adjustment_volume,
                    SUM(bt.amount_paid) AS adjustment_principal,
                    SUM(bt.charge_to_customer + bt.charge_to_partner) AS adjustment_charge
                  FROM billspayment_transaction bt
                  WHERE bt.cancellation_date BETWEEN '$fromDate 00:00:00' AND '$toDate 23:59:59'
                  AND (bt.status = '*' OR bt.status LIKE '%*%')";

    if ($partnerID !== 'All') {
        $adj_query .= " AND bt.partner_id = '$partnerID'";
    }

    $adj_query .= " GROUP BY bt.partner_name, bt.partner_id";
    
    // Remove manual override data
    $direct_adj_data = [];
    
    // Enhanced debug info to check actual characters
    $debug_chars_query = "SELECT 
                        reference_no, 
                        partner_name,
                        status,
                        HEX(status) as status_hex,
                        CHAR_LENGTH(status) as status_length,
                        ASCII(status) as status_ascii,
                        amount_paid, 
                        charge_to_customer
                      FROM billspayment_transaction 
                      WHERE datetime BETWEEN '$fromDate 00:00:00' AND '$toDate 23:59:59'
                        AND (status = '*' OR status LIKE '%*%')
                      ORDER BY status IS NOT NULL DESC, status != '' DESC
                      LIMIT 20";
    
    $debug_chars_result = $conn->query($debug_chars_query);
    $debug_chars_data = [];
    
    if ($debug_chars_result) {
        while ($debug_row = $debug_chars_result->fetch_assoc()) {
            $debug_chars_data[] = $debug_row;
        }
    }
    
    // Rest of status check query remains the same
    $status_check_query = "SELECT 
                        COUNT(*) as adj_count,
                        SUM(amount_paid) as total_amount,
                        SUM(charge_to_customer + charge_to_partner) as total_charge
                        FROM billspayment_transaction 
                        WHERE datetime BETWEEN '$fromDate 00:00:00' AND '$toDate 23:59:59'
                        AND (status = '*' OR status LIKE '%*%')";
    
    $status_result = $conn->query($status_check_query);
    $status_adjustment = [
        'count' => 0,
        'amount' => 0,
        'charge' => 0
    ];
    
    if ($status_result && $status_row = $status_result->fetch_assoc()) {
        $status_adjustment['count'] = $status_row['adj_count'];
        $status_adjustment['amount'] = $status_row['total_amount']; 
        $status_adjustment['charge'] = $status_row['total_charge'];
    }
    
    // Add comprehensive debugging query
    $debug_adj_query = "SELECT 
                        COUNT(*) as count_adj,
                        GROUP_CONCAT(DISTINCT reference_no SEPARATOR ', ') as reference_numbers,
                        GROUP_CONCAT(DISTINCT CONCAT(reference_no, ':', HEX(status)) SEPARATOR ', ') as hex_status_details,
                        HEX('*') as asterisk_hex
                        FROM billspayment_transaction bt
                        WHERE bt.datetime BETWEEN '$fromDate 00:00:00' AND '$toDate 23:59:59'";

    $debug_adj_result = $conn->query($debug_adj_query);
    $debug_adj_count = 0;
    $debug_adj_details = "";
    $debug_reference_numbers = "";
    $debug_status_values_str = ""; // Renamed to avoid conflict with the array
    if ($debug_adj_result && $debug_adj_row = $debug_adj_result->fetch_assoc()) {
        $debug_adj_count = $debug_adj_row['count_adj'];
        $debug_reference_numbers = $debug_adj_row['reference_numbers'] ?: "none";
        $debug_status_values_str = $debug_adj_row['hex_status_details'] ?: "none"; // Store in renamed variable
    }
    
    // Original query execution
    $total_result = $conn->query($total_query);
    $adj_result = $conn->query($adj_query);
    
    // Debug the raw adjustment query
    $adj_debug_raw = [];
    if ($adj_result && $adj_result->num_rows > 0) {
        while ($raw_adj = $adj_result->fetch_assoc()) {
            $adj_debug_raw[] = $raw_adj;
        }
        // Reset the result pointer
        mysqli_data_seek($adj_result, 0);
    }
    
    if ($total_result && $total_result->num_rows > 0) {
        $result_data = [];
        
        // Create a lookup array for adjustment data 
        $adj_data = [];
        if ($adj_result && $adj_result->num_rows > 0) {
            while ($adj_row = $adj_result->fetch_assoc()) {
                $key = $adj_row['partner_name'] . '-' . $adj_row['partner_id'];
                $adj_data[$key] = $adj_row;
            }
        }
        
        while ($row = $total_result->fetch_assoc()) {
            $partner_id = $row['partner_id'];
            $partner_name = $row['partner_name'];
            $key = $partner_name . '-' . $partner_id;
            
            // Get bank names for this partner
            $biller_query = "SELECT GROUP_CONCAT(DISTINCT bank ORDER BY bank SEPARATOR ', ') AS bank 
                             FROM partner_bank 
                             WHERE partner_id = '$partner_id'";
            $biller_result = $conn->query($biller_query);
            
            if ($biller_result && $biller_row = $biller_result->fetch_assoc()) {
                $row['bank'] = $biller_row['bank'];
            } else {
                $row['bank'] = '';
            }
            
            // Add adjustment data if it exists
            if (isset($adj_data[$key])) {
                $row['adjustment_volume'] = abs($adj_data[$key]['adjustment_volume']);
                $row['adjustment_principal'] = abs($adj_data[$key]['adjustment_principal']);
                $row['adjustment_charge'] = abs($adj_data[$key]['adjustment_charge']);
            } else {
                $row['adjustment_volume'] = 0;
                $row['adjustment_principal'] = 0;
                $row['adjustment_charge'] = 0;
            }
            
    // Remove manual override adjustment data logic
    // Calculate net totals
    $row['net_volume'] = $row['total_volume'] - $row['adjustment_volume'];
    $row['net_principal'] = $row['total_principal'] - $row['adjustment_principal'];
    $row['net_charge'] = $row['total_charge'] - $row['adjustment_charge'];
    
    $result_data[] = $row;
        }
    }
}

// Format date range for display in the header
$formattedDateRange = '';
if (isset($_POST['fromDate']) && isset($_POST['toDate'])) {
    $fromDateObj = new DateTime($_POST['fromDate']);
    $toDateObj = new DateTime($_POST['toDate']);
    
    if ($fromDateObj->format('Y-m-d') == $toDateObj->format('Y-m-d')) {
        // Same day, format as: MARCH 03, 2025
        $formattedDateRange = $fromDateObj->format('F d, Y');
    } else if ($fromDateObj->format('Y-m') == $toDateObj->format('Y-m')) {
        // Same month, format as: JANUARY 01-31, 2023
        $formattedDateRange = $fromDateObj->format('F d') . '-' . $toDateObj->format('d, Y');
    } else {
        // Different months, format as: JANUARY 01-FEBRUARY 28, 2023
        $formattedDateRange = $fromDateObj->format('F d') . '-' . $toDateObj->format('F d, Y');
    }
    $formattedDateRange = strtoupper($formattedDateRange);
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report Monthly Volume | <?php if($_SESSION['user_type'] === 'admin' || $_SESSION['user_type'] === 'user') echo ucfirst($_SESSION['user_type']); else echo "Guest";?></title>
    <!-- custom CSS file link  -->
    <link rel="stylesheet" href="../../../assets/css/user_page.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../../../assets/css/edi_styles.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../../../assets/css/dailyvolume.css?v=<?php echo time(); ?>">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet" />
    <!-- Font Awesome for icons -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- SweetAlert2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.min.css" rel="stylesheet">
    <script src="https://kit.fontawesome.com/30b908cc5a.js" crossorigin="anonymous"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="../../../assets/js/sweetalert2.all.min.js"></script>

    <link rel="icon" href="../../../images/MLW logo.png" type="image/png">
    <style>
        .filter-data form {
            justify-content: center;
            gap: 20px;
            flex-wrap: wrap;
        }
        
        .custom-select-wrapper {
            text-align: center;
        }
        
        .custom-select-wrapper label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        
        .custom-select-wrapper input[type="date"] {
            border-radius: 25px;
            padding: 8px 12px;
            border: 1px solid #ccc;
            text-align: center;
        }
        
        .autocomplete-container {
            text-align: center;
        }
        
        #partnerNameInput {
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="main-container">
        <div class="top-content">
            <div class="nav-container">
                <i id="menu-btn" class="fa-solid fa-bars"></i>
                <div class="usernav">
                <h6><?php 
                        if($_SESSION['user_type'] === 'admin'){
                            echo $_SESSION['admin_name'];
                        }elseif($_SESSION['user_type'] === 'user'){
                            echo $_SESSION['user_name']; 
                        }else{
                            echo "GUEST";
                        }
                ?></h6>
                <h6 style="margin-left:5px;"><?php 
                    if($_SESSION['user_type'] === 'admin'){
                        echo "(".$_SESSION['admin_email'].")";
                    }elseif($_SESSION['user_type'] === 'user'){
                        echo "(".$_SESSION['user_email'].")";
                    }else{
                        echo "GUEST";
                    }
                    ?></h6>
                </div>
            </div>
        </div>
        <!-- Show and Hide Side Nav Menu -->
        <?php include '../../../templates/sidebar.php'; ?>
        <div id="loading-overlay">
            <div class="loading-spinner"></div>
        </div>
        <center><h1>Report Monthly Volume</h1></center>
        <div class="container-fluid">
            <div class="filter-data">
                <form action="" method="post" style="display: flex; align-items: flex-end;">
                    <div class="custom-select-wrapper autocomplete-container" style="width: 400px;">
                        <label for="partnerName">Partners Name</label>
                        <input type="text" id="partnerNameInput" placeholder="Type to search partners..." autocomplete="off" 
                            style="border-radius: 25px; padding: 8px 12px; border: 1px solid #ccc; box-shadow: none; outline: none; text-decoration: none; border-bottom: none; width: 100%;">
                        <input type="hidden" id="partnerName" name="partnerName" required>
                        <div id="autocomplete-list" class="autocomplete-items"></div>
                    </div>
                    <div class="custom-select-wrapper" style="display:flex; flex-direction:column;">
                        <label for="fromDate">From</label>
                        <input type="date" id="fromDate" name="fromDate" value="<?php echo isset($_POST['fromDate']) ? $_POST['fromDate'] : ''; ?>" required>
                    </div>
                    <div class="custom-select-wrapper" style="display:flex; flex-direction:column;">
                        <label for="toDate">To</label>
                        <input type="date" id="toDate" name="toDate" value="<?php echo isset($_POST['toDate']) ? $_POST['toDate'] : ''; ?>" required>
                    </div>
                    <div style="display:flex; gap:18px; align-items:flex-end;">
                        <input type="submit" class="generate-btn" name="generate" value="Generate">
                        <button type="button" id="ediButton" class="generate-btn">EDI</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>
<?php include '../../../templates/footer.php'; ?>
</html>