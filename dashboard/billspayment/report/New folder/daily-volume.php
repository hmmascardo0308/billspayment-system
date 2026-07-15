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

// Initialize variables
$dayButtons = [];
$formattedDateRange = '';
$result_data = [];
$grand_totals = [
    'total_volume' => 0,
    'total_principal' => 0,
    'total_charge' => 0,
    'adjustment_volume' => 0,
    'adjustment_principal' => 0,
    'adjustment_charge' => 0,
    'net_volume' => 0,
    'net_principal' => 0,
    'net_charge' => 0
];

if (isset($_POST['generate'])) { 
    $getback = isset($_GET['back']) ? $_GET['back'] : 0;
    $getpartnerID = isset($_GET['partnerID']) ? $_GET['partnerID'] : '';
    $getpartnerID_kpx = isset($_GET['partnerID_kpx']) ? $_GET['partnerID_kpx'] : '';
    $partnerIDS = $_POST['partnerName']; // This contains partner name, not ID
    $fromDate = $_POST['fromDate'];
    $toDate = $_POST['toDate'];
    
    if ($partnerIDS !== 'All') {
        if($getback !==0){
            if($getpartnerID !== null && $getpartnerID !== ''){
                $get_kp7_kpx_partner_id = "SELECT partner_id, partner_id_kpx, partner_name FROM masterdata.partner_masterfile WHERE partner_id='$partnerIDS' LIMIT 1";
            }elseif($getpartnerID_kpx !== null && $getpartnerID_kpx !== ''){
                $get_kp7_kpx_partner_id = "SELECT partner_id, partner_id_kpx, partner_name FROM masterdata.partner_masterfile WHERE partner_id_kpx='$partnerIDS' LIMIT 1";
            }
            
            // Execute the query and get the partner name for display
            if(isset($get_kp7_kpx_partner_id)){
                $kp7_kpx_result = $conn->query($get_kp7_kpx_partner_id);
                if ($kp7_kpx_result && $kp7_kpx_result->num_rows > 0) {
                    $kp7_kpx_row = $kp7_kpx_result->fetch_assoc();
                    $partnerID = $kp7_kpx_row['partner_id'];
                    $partnerID_kpx = $kp7_kpx_row['partner_id_kpx'];
                    $partnerIDS = $kp7_kpx_row['partner_name']; // Use partner name instead of ID
                }
            }
        }
        else{
            $get_kp7_kpx_partner_id = "SELECT partner_id, partner_id_kpx FROM masterdata.partner_masterfile WHERE partner_name = '$partnerIDS' OR partner_id='$partnerIDS' OR partner_id_kpx='$partnerIDS' LIMIT 1";
        }
        $kp7_kpx_result = $conn->query($get_kp7_kpx_partner_id);
        if ($kp7_kpx_result && $kp7_kpx_result->num_rows > 0) {
            $kp7_kpx_row = $kp7_kpx_result->fetch_assoc();
            $partnerID = $kp7_kpx_row['partner_id'];
            $partnerID_kpx = $kp7_kpx_row['partner_id_kpx'];
        }
    }
    
    // Store original dates for day filtering
    $originalFromDate = isset($_POST['origFromDate']) ? $_POST['origFromDate'] : $fromDate;
    $originalToDate = isset($_POST['origToDate']) ? $_POST['origToDate'] : $toDate;

    // Generate day buttons based on original date range
    if ($originalFromDate && $originalToDate) {
        $startDate = new DateTime($originalFromDate);
        $endDate = new DateTime($originalToDate);
        $interval = new DateInterval('P1D');
        $dateRange = new DatePeriod($startDate, $interval, $endDate->add($interval));
        
        foreach ($dateRange as $date) {
            $dayButtons[] = [
                'day' => $date->format('d'),
                'date' => $date->format('Y-m-d')
            ];
        }
    }

    // Format date range for display
    if ($fromDate && $toDate) {
        $fromDateObj = new DateTime($fromDate);
        $toDateObj = new DateTime($toDate);
        
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

    // Debug query remains the same
    $debug_query = "SELECT DISTINCT status FROM billspayment_transaction 
                    WHERE datetime BETWEEN '" . mysqli_real_escape_string($conn, $fromDate) . " 00:00:00' AND '" . mysqli_real_escape_string($conn, $toDate) . " 23:59:59' 
                    AND status IS NOT NULL AND status != ''";
    $debug_result = $conn->query($debug_query);
    
    $debug_status_values = [];
    if ($debug_result && $debug_result->num_rows > 0) {
        while ($debug_row = $debug_result->fetch_assoc()) {
            $debug_status_values[] = $debug_row['status'];
        }
    }
    
    // Fixed query - Build the base query first
    $total_query = "SELECT bt.partner_name,
                    bt.partner_id,
                    bt.partner_id_kpx,
                    COUNT(DISTINCT bt.reference_no) AS total_volume,
                    SUM(bt.amount_paid) AS total_principal,
                    SUM(bt.charge_to_customer + bt.charge_to_partner) AS total_charge
                    FROM billspayment_transaction bt
                    WHERE bt.datetime BETWEEN '" . mysqli_real_escape_string($conn, $fromDate) . " 00:00:00' AND '" . mysqli_real_escape_string($conn, $toDate) . " 23:59:59'
                    AND (bt.status IS NULL OR bt.status = '' OR bt.status NOT LIKE '%*%')";

    // Add partner filtering - Fixed logic
    if ($partnerIDS !== 'All') {
        // Use partner_name for filtering since that's what we have
        if (!empty($partnerID)) {
            $total_query .= " AND bt.partner_id = '" . mysqli_real_escape_string($conn, $partnerID) . "'";
        }elseif(!empty($partnerID_kpx)){
            $total_query .= " AND bt.partner_id_kpx = '" . mysqli_real_escape_string($conn, $partnerID_kpx) . "'";
        }
    }

    $total_query .= " GROUP BY bt.partner_name, bt.partner_id, bt.partner_id_kpx ORDER BY bt.partner_name";
    
    // Apply same logic to adjustment query - Fixed
    $adj_query = "SELECT 
                    bt.partner_name,
                    bt.partner_id,
                    bt.partner_id_kpx,
                    COUNT(DISTINCT bt.reference_no) AS adjustment_volume,
                    SUM(bt.amount_paid) AS adjustment_principal,
                    SUM(bt.charge_to_customer + bt.charge_to_partner) AS adjustment_charge
                  FROM billspayment_transaction bt
                  WHERE bt.cancellation_date BETWEEN '" . mysqli_real_escape_string($conn, $fromDate) . " 00:00:00' AND '" . mysqli_real_escape_string($conn, $toDate) . " 23:59:59'
                  AND (bt.status = '*' OR bt.status LIKE '%*%')";

    // Add partner filtering for adjustments - Fixed
    if ($partnerIDS !== 'All') {
        if (!empty($partnerID)) {
            $adj_query .= " AND bt.partner_id = '" . mysqli_real_escape_string($conn, $partnerID) . "'";
        }elseif(!empty($partnerID_kpx)){
            $adj_query .= " AND bt.partner_id_kpx = '" . mysqli_real_escape_string($conn, $partnerID_kpx) . "'";
        }
    }

    $adj_query .= " GROUP BY bt.partner_name, bt.partner_id, bt.partner_id_kpx";

    // Execute queries
    $total_result = $conn->query($total_query);
    $adj_result = $conn->query($adj_query);
    
    // Initialize result data and totals
    $result_data = [];
    $grand_totals = [
        'total_volume' => 0,
        'total_principal' => 0,
        'total_charge' => 0,
        'adjustment_volume' => 0,
        'adjustment_principal' => 0,
        'adjustment_charge' => 0,
        'net_volume' => 0,
        'net_principal' => 0,
        'net_charge' => 0
    ];
    
    if ($total_result && $total_result->num_rows > 0) {
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
                            WHERE partner_id = '" . mysqli_real_escape_string($conn, $partner_id) . "'";
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
            
            // Calculate net totals
            $row['net_volume'] = $row['total_volume'] - $row['adjustment_volume'];
            $row['net_principal'] = $row['total_principal'] - $row['adjustment_principal'];
            $row['net_charge'] = $row['total_charge'] - $row['adjustment_charge'];
            
            // Add to grand totals
            $grand_totals['total_volume'] += $row['total_volume'];
            $grand_totals['total_principal'] += $row['total_principal'];
            $grand_totals['total_charge'] += $row['total_charge'];
            $grand_totals['adjustment_volume'] += $row['adjustment_volume'];
            $grand_totals['adjustment_principal'] += $row['adjustment_principal'];
            $grand_totals['adjustment_charge'] += $row['adjustment_charge'];
            $grand_totals['net_volume'] += $row['net_volume'];
            $grand_totals['net_principal'] += $row['net_principal'];
            $grand_totals['net_charge'] += $row['net_charge'];
            
            $result_data[] = $row;
        }
    }

    // Add debug output for troubleshooting
    if ($show_debug) {
        echo "<!-- DEBUG INFO -->";
        echo "<!-- Partner ID: " . htmlspecialchars($partnerID) . " -->";
        echo "<!-- Date Range: " . htmlspecialchars($fromDate) . " to " . htmlspecialchars($toDate) . " -->";
        echo "<!-- Total Query: " . htmlspecialchars($total_query) . " -->";
        echo "<!-- Adjustment Query: " . htmlspecialchars($adj_query) . " -->";
        echo "<!-- Total Result Rows: " . ($total_result ? $total_result->num_rows : 0) . " -->";
        echo "<!-- Adjustment Result Rows: " . ($adj_result ? $adj_result->num_rows : 0) . " -->";
        echo "<!-- END DEBUG -->";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Volume | <?php if($_SESSION['user_type'] === 'admin' || $_SESSION['user_type'] === 'user') echo ucfirst($_SESSION['user_type']); else echo "Guest";?></title>
    <!-- custom CSS file link  -->
    <!-- <link rel="stylesheet" href="../../../assets/css/user_page.css?v=<?php //echo time(); ?>"> -->
    <link rel="stylesheet" href="../../../assets/css/templates/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../../../assets/css/edi_styles.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../../../assets/css/dailyvolume.css?v=<?php echo time(); ?>">
    
    <!-- <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet" /> -->
    <!-- Font Awesome for icons -->
    <!-- <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet"> -->
    <!-- SweetAlert2 CSS -->
    <!-- <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.min.css" rel="stylesheet"> -->
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
        <center><h1>Daily Volume</h1></center>
        <div class="container-fluid">
            <div class="filter-data">
                <form action="" method="post" style="display: flex; align-items: flex-end;">
                    <div class="custom-select-wrapper autocomplete-container" style="width: 400px;">
                        <label for="partnerName">Bills Payment Partner</label>
                        <input type="text" id="partnerNameInput" placeholder="Type to search partners..." autocomplete="off" 
                            style="border-radius: 25px; padding: 8px 12px; border: 1px solid #ccc; box-shadow: none; outline: none; text-decoration: none; border-bottom: none; width: 100%;">
                        <input type="hidden" id="partnerName" name="partnerName" required>
                        <input type="hidden" id="partnerID" name="partnerID" value="">
                        <input type="hidden" id="partnerID_kpx" name="partnerID_kpx" value="">
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
                    </div>
                </form>
            </div>
        </div>
        <div class="container mt-4">
            <?php if (!empty($debug_status_values) && $show_debug): ?>
            <div class="alert alert-info">
                <strong>Debug - Status values found:</strong> 
                <?php echo implode(', ', array_map('htmlspecialchars', $debug_status_values)); ?>
            </div>
            <?php endif; ?>
            
            <?php if (isset($_POST['generate'])): ?>
                <!-- Hidden fields to store original values for day filtering -->
                <input type="hidden" id="originalFromDate" value="<?php echo htmlspecialchars($originalFromDate ?? ''); ?>">
                <input type="hidden" id="originalToDate" value="<?php echo htmlspecialchars($originalToDate ?? ''); ?>">
                <input type="hidden" id="originalPartner" value="<?php echo htmlspecialchars($_POST['partnerName'] ?? 'All'); ?>">
                <input type="hidden" id="selectedDay" value="<?php echo htmlspecialchars($_POST['filterDay'] ?? ''); ?>">

                <!-- Export button -->
                <div class="mb-2" style="text-align: center; padding-top: 18px;">
                    <form action="../../../models/exports/excel/export_dailyvolume.php" method="post" style="display: inline;">
                        <input type="hidden" name="partnerName" value="<?php echo htmlspecialchars($partnerIDS); ?>">
                        <input type="hidden" name="fromDate" value="<?php echo htmlspecialchars($fromDate); ?>">
                        <input type="hidden" name="toDate" value="<?php echo htmlspecialchars($toDate); ?>">
                        <button type="submit" class="generate-btn" style="margin-top:8px; margin-right: 10px;">Export to Excel</button>
                    </form>
                    <button type="button" id="ediButton" class="generate-btn" style="margin-top:8px;">EDI</button>
                </div>
                    
                <!-- Report Header -->
                <div class="report-header mb-4" style="display: block;">
                    <img src="../../../assets/images/png/ml.png" alt="MLHUILLIER Logo" style="height:35px; width:20%; margin:0 0 5px 0; display:block;">
                    <h4 style="margin-bottom: 5px; font-weight:bold; text-align:left;">BILLS PAYMENT</h4>
                    <p style="color:rgb(173, 18, 204); font-weight: bold; margin-bottom:0; text-align:left;">Report Date: <?php echo $formattedDateRange; ?></p>
                </div>

                <!-- Day Shortcut Buttons -->
                <?php if (!empty($dayButtons)): ?>
                <div class="day-shortcut-container">
                    <div class="day-buttons-label">Filter by Day:</div>
                    <div class="day-buttons-wrapper" id="dayButtonsWrapper">
                        <button class="day-button day-button-all <?php echo !isset($_POST['filterDay']) ? 'day-button-active' : ''; ?>" id="allDaysButton">All</button>
                        <?php foreach ($dayButtons as $dayBtn): ?>
                            <button type="button" class="day-button <?php echo (isset($_POST['filterDay']) && $_POST['filterDay'] == $dayBtn['date']) ? 'day-button-active' : ''; ?>" 
                                    data-date="<?php echo $dayBtn['date']; ?>"><?php echo $dayBtn['day']; ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="table-responsive">
                    <table class="table table-bordered">
                        <thead class="table-danger">
                            <tr>
                                <th>No.</th>
                                <th>Partner's Name</th>
                                <th>Bank</th>
                                <th>Biller's Name</th>
                                <th colspan="3" style="text-align: center;">KP7 / KPX</th>
                                <th colspan="3" style="text-align: center;">Adjustments</th>
                                <th colspan="3" style="text-align: center;">Net</th>
                            </tr>
                            <tr>
                                <th></th>
                                <th></th>
                                <th></th>
                                <th></th>
                                <th>Vol</th>
                                <th>Principal</th>
                                <th>Charge</th>
                                <th>Vol</th>
                                <th>Principal</th>
                                <th>Charge</th>
                                <th>Vol</th>
                                <th>Principal</th>
                                <th>Charge</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($result_data)): ?>
                                <?php $counter = 1; ?>
                                <?php foreach ($result_data as $row): ?>
                                    <tr>
                                        <td><?php echo $counter++; ?></td>
                                        <td><?php echo htmlspecialchars($row['partner_name']); ?></td>
                                        <td><?php echo htmlspecialchars($row['bank']); ?></td>
                                        <td></td> <!-- Biller's Name column empty as per requirement -->
                                        <td style="text-align: right;"><?php echo number_format($row['total_volume']); ?></td>
                                        <td style="text-align: right;"><?php echo number_format($row['total_principal'], 2); ?></td>
                                        <td style="text-align: right;"><?php echo number_format($row['total_charge'], 2); ?></td>
                                        <td style="text-align: right;"><?php echo number_format($row['adjustment_volume']); ?></td>
                                        <td style="text-align: right;"><?php echo number_format($row['adjustment_principal'], 2); ?></td>
                                        <td style="text-align: right;"><?php echo number_format($row['adjustment_charge'], 2); ?></td>
                                        <td style="text-align: right;"><?php echo number_format($row['net_volume']); ?></td>
                                        <td style="text-align: right;"><?php echo number_format($row['net_principal'], 2); ?></td>
                                        <td style="text-align: right;"><?php echo number_format($row['net_charge'], 2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td class="alert alert-warning" colspan="13" style="text-align: center; font-weight: bold; padding: 20px;">
                                        No records found for the selected filters.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                        <?php if (!empty($result_data)): ?>
                        <tfoot>
                            <tr>
                                <th colspan="4">TOTAL</th>
                                <th style="text-align: right;"><?php echo number_format($grand_totals['total_volume']); ?></th>
                                <th style="text-align: right;"><?php echo number_format($grand_totals['total_principal'], 2); ?></th>
                                <th style="text-align: right;"><?php echo number_format($grand_totals['total_charge'], 2); ?></th>
                                <th style="text-align: right;"><?php echo number_format($grand_totals['adjustment_volume']); ?></th>
                                <th style="text-align: right;"><?php echo number_format($grand_totals['adjustment_principal'], 2); ?></th>
                                <th style="text-align: right;"><?php echo number_format($grand_totals['adjustment_charge'], 2); ?></th>
                                <th style="text-align: right;"><?php echo number_format($grand_totals['net_volume']); ?></th>
                                <th style="text-align: right;"><?php echo number_format($grand_totals['net_principal'], 2); ?></th>
                                <th style="text-align: right;"><?php echo number_format($grand_totals['net_charge'], 2); ?></th>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <script>
            document.addEventListener("DOMContentLoaded", function() {
                // Check if we're coming back from EDI page
                const urlParams = new URLSearchParams(window.location.search);
                if (urlParams.get('back') === '1') {
                    const partnerID = urlParams.get('partnerID');
                    const partnerID_kpx = urlParams.get('partnerID_kpx');
                    const fromDate = urlParams.get('fromDate');
                    const toDate = urlParams.get('toDate');
                    
                    if ((partnerID || partnerID_kpx) && fromDate && toDate) {
                        // If it's not 'All', we need to fetch the partner name from the server
                        if (partnerID !== 'All' && partnerID_kpx !== 'All') {
                            // Make AJAX call to get partner name
                            fetch('../../../fetch/get_partner_name.php', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                },
                                body: JSON.stringify({
                                    partnerID: partnerID,
                                    partnerID_kpx: partnerID_kpx
                                })
                            })
                            .then(response => response.json())
                            .then(data => {
                                if (data.partner_name) {
                                    document.getElementById('partnerNameInput').value = data.partner_name;
                                    document.getElementById('partnerName').value = data.partner_name;
                                }
                                document.getElementById('fromDate').value = fromDate;
                                document.getElementById('toDate').value = toDate;
                                
                                // Clean the URL and auto-submit
                                const newUrl = window.location.pathname;
                                window.history.replaceState({}, document.title, newUrl);
                                
                                setTimeout(() => {
                                    const form = document.querySelector('form[method="post"]');
                                    const generateInput = document.createElement('input');
                                    generateInput.type = 'hidden';
                                    generateInput.name = 'generate';
                                    generateInput.value = 'Generate';
                                    form.appendChild(generateInput);
                                    form.submit();
                                }, 100);
                            })
                            .catch(error => {
                                console.error('Error fetching partner name:', error);
                            });
                        } else {
                            // Handle 'All' case
                            document.getElementById('partnerNameInput').value = 'All';
                            document.getElementById('partnerName').value = 'All';
                            document.getElementById('fromDate').value = fromDate;
                            document.getElementById('toDate').value = toDate;
                            
                            const newUrl = window.location.pathname;
                            window.history.replaceState({}, document.title, newUrl);
                            
                            setTimeout(() => {
                                const form = document.querySelector('form[method="post"]');
                                const generateInput = document.createElement('input');
                                generateInput.type = 'hidden';
                                generateInput.name = 'generate';
                                generateInput.value = 'Generate';
                                form.appendChild(generateInput);
                                form.submit();
                            }, 100);
                        }
                    }
                }
    
                // Store partners with both ID and name
                let partners = [{id: "All", name: "All"}]; // Start with "All" option
                
                <?php
                include '../../../config/config.php';
                
                // Get count of partners first
                $count_sql = "SELECT COUNT(*) as partner_count FROM partner_masterfile";
                $count_result = $conn->query($count_sql);
                $partner_count = 0;
                
                if ($count_result && $count_result->num_rows > 0) {
                    $count_row = $count_result->fetch_assoc();
                    $partner_count = $count_row['partner_count'];
                }
                
                // Output the count to JavaScript
                echo "const totalPartnerCount = " . $partner_count . ";\n";
                
                // Fetch partners (include partner_id)
                $sql = "SELECT partner_name FROM masterdata.partner_masterfile ORDER BY partner_name ASC";
                $result = $conn->query($sql);
                if ($result && $result->num_rows > 0) {
                    echo "// Add partners from database\n";
                    while ($row = $result->fetch_assoc()) {
                        // use partner_name for both id and display name since we're filtering by partner_name
                        echo 'partners.push({id: "' . addslashes($row['partner_name']) . '", name: "' . addslashes($row['partner_name']) . '"});' . "\n";
                    }
                }
                ?>
                
                const inputField = document.getElementById("partnerNameInput");
                const hiddenField = document.getElementById("partnerName");
                const autocompleteList = document.getElementById("autocomplete-list");
                const maxDisplayLimit = Math.min(totalPartnerCount + 1, 20); // Limit to 20 for better performance
                
                // Set the hidden field default to 'All' to avoid empty filter on submit
                hiddenField.value = 'All';
                
                // Set the initial value if it exists in POST data
                <?php if (isset($_POST['partnerName'])): ?>
                const selectedPartnerID = "<?php echo addslashes($_POST['partnerName']); ?>";
                const selectedPartner = partners.find(p => p.id === selectedPartnerID || p.name === selectedPartnerID);
                if (selectedPartner) {
                    inputField.value = selectedPartner.name;
                    hiddenField.value = selectedPartner.id;
                } else {
                    // If partner not found in list, still set the value
                    inputField.value = selectedPartnerID;
                    hiddenField.value = selectedPartnerID;
                }
                <?php endif; ?>
                
                // Function to show autocomplete options based on input
                function showAutocompleteOptions(input) {
                    autocompleteList.innerHTML = "";
                    
                    if (!input) {
                        // Show all partners up to the calculated limit
                        for (let i = 0; i < partners.length && i < maxDisplayLimit; i++) {
                            const item = document.createElement("div");
                            item.innerHTML = partners[i].name;
                            item.setAttribute('data-id', partners[i].id);
                            item.addEventListener("click", function() {
                                inputField.value = this.innerHTML;
                                hiddenField.value = this.getAttribute('data-id');
                                autocompleteList.innerHTML = "";
                            });
                            autocompleteList.appendChild(item);
                        }
                        return;
                    }
                    
                    const inputValue = input.toLowerCase();
                    let matches = 0;
                    
                    for (let i = 0; i < partners.length; i++) {
                        const partnerName = partners[i].name.toLowerCase();
                        
                        if (partnerName.startsWith(inputValue) || 
                            partnerName.includes(' ' + inputValue) || 
                            partnerName.includes('-' + inputValue) ||
                            partnerName.includes(inputValue)) {
                            
                            const item = document.createElement("div");
                            item.innerHTML = partners[i].name;
                            item.setAttribute('data-id', partners[i].id);
                            item.addEventListener("click", function() {
                                inputField.value = this.innerHTML;
                                hiddenField.value = this.getAttribute('data-id');
                                autocompleteList.innerHTML = "";
                            });
                            autocompleteList.appendChild(item);
                            matches++;
                            
                            // Use the calculated limit instead of hardcoded 15
                            if (matches >= maxDisplayLimit) break;
                        }
                    }
                }
                
                // Setup event listeners
                inputField.addEventListener("input", function() {
                    showAutocompleteOptions(this.value);
                    // Find matching partner by name (exact match) and set ID
                    const matchedPartner = partners.find(p => p.name.toLowerCase() === this.value.toLowerCase());
                    if (matchedPartner) {
                        hiddenField.value = matchedPartner.id;
                    } else if (this.value.trim() !== '') {
                        // If no exact match but has value, use the input value
                        hiddenField.value = this.value;
                    } else {
                        // if empty, keep default 'All'
                        hiddenField.value = 'All';
                    }
                });
                
                // Close dropdown when clicking elsewhere
                document.addEventListener("click", function(e) {
                    if (e.target !== inputField) {
                        autocompleteList.innerHTML = "";
                    }
                });
                
                // Handle keyboard navigation
                inputField.addEventListener("keydown", function(e) {
                    const items = autocompleteList.getElementsByTagName("div");
                    
                    if (items.length > 0) {
                        if (e.keyCode === 40) { // Down arrow
                            currentFocus = currentFocus === undefined || currentFocus >= items.length - 1 ? 0 : currentFocus + 1;
                            setActive(items, currentFocus);
                            e.preventDefault();
                        } else if (e.keyCode === 38) { // Up arrow
                            currentFocus = currentFocus === undefined || currentFocus <= 0 ? items.length - 1 : currentFocus - 1;
                            setActive(items, currentFocus);
                            e.preventDefault();
                        } else if (e.keyCode === 13) { // Enter
                            e.preventDefault();
                            if (currentFocus > -1 && items[currentFocus]) {
                                items[currentFocus].click();
                            }
                        }
                    }
                });
                
                let currentFocus;
                
                // Function to set active class
                function setActive(items, index) {
                    if (!items) return false;
                    
                    // Remove active from all items
                    for (let i = 0; i < items.length; i++) {
                        items[i].classList.remove("autocomplete-active");
                    }
                    
                    // Add active class to selected item
                    if (index >= 0 && index < items.length) {
                        items[index].classList.add("autocomplete-active");
                        // Scroll if needed
                        items[index].scrollIntoView({ block: 'nearest' });
                    }
                }
                
                // Show all options when clicking on the input field
                inputField.addEventListener("click", function() {
                    showAutocompleteOptions("");
                });

                // Replace EDI button logic with this enhanced version
                const ediButton = document.getElementById('ediButton');
                if (ediButton) {
                    ediButton.addEventListener('click', function() {
                        let partnerName = hiddenField.value || 'All';
                        let fromDate = document.getElementById("fromDate").value || '';
                        let toDate = document.getElementById("toDate").value || '';
                        
                        // If a specific partner is selected, get both partner IDs
                        if (partnerName !== 'All') {
                            // Make an AJAX call to get partner IDs
                            fetch('../../../fetch/get_partner_ids.php', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                },
                                body: JSON.stringify({
                                    partnerName: partnerName
                                })
                            })
                            .then(response => response.json())
                            .then(data => {
                                const params = new URLSearchParams({
                                    fromDate: fromDate,
                                    toDate: toDate
                                });
                                
                                // Add partner IDs if they exist
                                if (data.partner_id) {
                                    params.append('partnerID', data.partner_id);
                                }
                                if (data.partner_id_kpx) {
                                    params.append('partnerID_kpx', data.partner_id_kpx);
                                }
                                if (!data.partner_id && !data.partner_id_kpx) {
                                    params.append('partnerID', partnerName);
                                }
                                
                                window.location.href = "dailyvol_edi.php?" + params.toString();
                            })
                            .catch(error => {
                                console.error('Error:', error);
                                // Fallback to using partner name
                                const params = new URLSearchParams({
                                    partnerID: partnerName,
                                    fromDate: fromDate,
                                    toDate: toDate
                                });
                                window.location.href = "dailyvol_edi.php?" + params.toString();
                            });
                        } else {
                            // For 'All' partners
                            const params = new URLSearchParams({
                                partnerID: 'All',
                                fromDate: fromDate,
                                toDate: toDate
                            });
                            window.location.href = "dailyvol_edi.php?" + params.toString();
                        }
                    });
                }
            });

            // Day shortcut buttons functionality - FIXED VERSION
            document.addEventListener("DOMContentLoaded", function() {
                const dayButtons = document.querySelectorAll('.day-button');
                const allDaysBtn = document.getElementById('allDaysButton');
                
                // Add event listeners to day buttons
                dayButtons.forEach(button => {
                    if (button.id !== 'allDaysButton') { // Skip the All button
                        button.addEventListener('click', function(e) {
                            e.preventDefault();
                            
                            // Remove active class from all buttons first
                            dayButtons.forEach(btn => btn.classList.remove('day-button-active'));
                            
                            // Add active class to clicked button
                            this.classList.add('day-button-active');
                            
                            const date = this.getAttribute('data-date');
                            if (date) {
                                // Show loading spinner
                                Swal.fire({
                                    title: 'Loading...',
                                    text: 'Filtering data for selected day',
                                    allowOutsideClick: false,
                                    didOpen: () => {
                                        Swal.showLoading();
                                    }
                                });
                                
                                // Create form for submission
                                const form = document.createElement('form');
                                form.method = 'post';
                                form.action = '';
                                form.style.display = 'none';
                                
                                // Use the original partner name instead of partner ID
                                const partnerInput = document.createElement('input');
                                partnerInput.type = 'hidden';
                                partnerInput.name = 'partnerName';
                                partnerInput.value = document.getElementById('originalPartner').value; // This should be partner name
                                
                                const fromDateInput = document.createElement('input');
                                fromDateInput.type = 'hidden';
                                fromDateInput.name = 'fromDate';
                                fromDateInput.value = date;
                                
                                const toDateInput = document.createElement('input');
                                toDateInput.type = 'hidden';
                                toDateInput.name = 'toDate';
                                toDateInput.value = date;
                                
                                const filterDayInput = document.createElement('input');
                                filterDayInput.type = 'hidden';
                                filterDayInput.name = 'filterDay';
                                filterDayInput.value = date;
                                
                                const origFromInput = document.createElement('input');
                                origFromInput.type = 'hidden';
                                origFromInput.name = 'origFromDate';
                                origFromInput.value = document.getElementById('originalFromDate').value;
                                
                                const origToInput = document.createElement('input');
                                origToInput.type = 'hidden';
                                origToInput.name = 'origToDate';
                                origToInput.value = document.getElementById('originalToDate').value;
                                
                                const generateInput = document.createElement('input');
                                generateInput.type = 'hidden';
                                generateInput.name = 'generate';
                                generateInput.value = 'Generate';
                                
                                form.appendChild(partnerInput);
                                form.appendChild(fromDateInput);
                                form.appendChild(toDateInput);
                                form.appendChild(filterDayInput);
                                form.appendChild(origFromInput);
                                form.appendChild(origToInput);
                                form.appendChild(generateInput);
                                
                                document.body.appendChild(form);
                                form.submit();
                            }
                        });
                    }
                });
                
                // All Days button handler - FIXED VERSION
                if (allDaysBtn) {
                    allDaysBtn.addEventListener('click', function(e) {
                        e.preventDefault();
                        
                        // Remove active class from all buttons
                        dayButtons.forEach(btn => btn.classList.remove('day-button-active'));
                        
                        // Add active class to All button
                        this.classList.add('day-button-active');
                        
                        // Show loading spinner
                        Swal.fire({
                            title: 'Loading...',
                            text: 'Showing all data',
                            allowOutsideClick: false,
                            didOpen: () => {
                                Swal.showLoading();
                            }
                        });
                        
                        // Create form to reset to original date range
                        const form = document.createElement('form');
                        form.method = 'post';
                        form.action = '';
                        form.style.display = 'none';
                        
                        // Use the original partner name instead of partner ID
                        const partnerInput = document.createElement('input');
                        partnerInput.type = 'hidden';
                        partnerInput.name = 'partnerName';
                        partnerInput.value = document.getElementById('originalPartner').value; // This should be partner name
                        
                        const fromDateInput = document.createElement('input');
                        fromDateInput.type = 'hidden';
                        fromDateInput.name = 'fromDate';
                        fromDateInput.value = document.getElementById('originalFromDate').value;
                        
                        const toDateInput = document.createElement('input');
                        toDateInput.type = 'hidden';
                        toDateInput.name = 'toDate';
                        toDateInput.value = document.getElementById('originalToDate').value;
                        
                        const generateInput = document.createElement('input');
                        generateInput.type = 'hidden';
                        generateInput.name = 'generate';
                        generateInput.value = 'Generate';
                        
                        form.appendChild(partnerInput);
                        form.appendChild(fromDateInput);
                        form.appendChild(toDateInput);
                        form.appendChild(generateInput);
                        
                        document.body.appendChild(form);
                        form.submit();
                    });
                }
            });
        </script>
    </div>
</body>
<?php include '../../../templates/footer.php'; ?>
</html>