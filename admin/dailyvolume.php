<?php
session_start();

if (!isset($_SESSION['admin_name'])) {
    header('location:../login_form.php');
    exit;
}

$debug_status_values = []; // Array to store status values for debugging
$show_debug = false; // Add this flag to control debug display

if (isset($_POST['generate'])) { 
    include '../config/config.php';

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
    <title>Daily Volume</title>
    <!-- Custom CSS -->
    <link rel="stylesheet" href="../assets/css/billspaymentSettlement.css?v=<?php echo time(); ?>">
    <link rel="icon" href="../images/MLW logo.png" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <!-- Add link to edi_styles.css to reuse button styles -->
    <link rel="stylesheet" href="../assets/css/edi_styles.css?v="<?php echo time(); ?>">
    <!-- Add link to the new dailyvolume.css file -->
    <link rel="stylesheet" href="../assets/css/dailyvolume.css?v="<?php echo time(); ?>">
</head>
<body>
    <div class="container">
        <div class="top-content">
            <div class="usernav">
                <h4><?php echo $_SESSION['admin_name']; ?></h4>
                <h5 style="margin-left:5px;"><?php echo " - " . $_SESSION['admin_email']; ?></h5>
            </div>
            <?php include '../templates/admin/sidebar.php'; ?>
        </div>
    </div>
    <div class="row mb-4" style="background-color: #dc3545 !important; padding: 15px; border-top: 5px solid #dc3545;">
        <div class="col-12">
            <div class="filter-data" style="background-color:rgb(184, 164, 166) !important;">
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
    <div class="container mt-4">
        <?php if (!empty($debug_status_values) && $show_debug): ?>
        <div class="alert alert-info">
            <strong>Debug - Status values found:</strong> 
            <?php echo implode(', ', array_map('htmlspecialchars', $debug_status_values)); ?>
        </div>
        <?php endif; ?>
        
        <?php if (isset($result_data) && !empty($result_data)): ?>
            <div class="mb-2" style="text-align: right; padding-top: 18px;">
                <form action="export_dailyvolume.php" method="post" style="display: inline;">
                    <input type="hidden" name="partnerName" value="<?php echo htmlspecialchars($partnerID); ?>">
                    <input type="hidden" name="fromDate" value="<?php echo htmlspecialchars($fromDate); ?>">
                    <input type="hidden" name="toDate" value="<?php echo htmlspecialchars($toDate); ?>">
                    <button type="submit" class="generate-btn" style="margin-top:8px;">Export to Excel</button>
                </form>
            </div>
                
            <!-- Report Header -->
            <div class="report-header mb-4" style="display: block;">
                <img src="../images/ml.png" alt="MLHUILLIER Logo" style="height:35px; width:20%; margin:0 0 5px 0; display:block;">
                <h4 style="margin-bottom: 5px; font-weight:bold; text-align:left;">BILLS PAYMENT - DAILY VOLUME</h4>
                <p style="color:rgb(173, 18, 204); font-weight: bold; margin-bottom:0; text-align:left;">Report Date: <?php echo $formattedDateRange; ?></p>
            </div>
            <!-- Day Shortcut Buttons -->
            <div class="day-shortcut-container">
                <div class="day-buttons-label">Filter by Day:</div>
                <div class="day-buttons-wrapper" id="dayButtonsWrapper">
                    <!-- Day buttons will be styled to match the image design -->
                    <button class="day-button day-button-all day-button-active" id="allDaysButton">All</button>
                    <button type="button" class="day-button " data-date="2025-08-01">01</button>
                    <button type="button" class="day-button " data-date="2025-08-02">02</button>
                    <button type="button" class="day-button " data-date="2025-08-03">03</button>
                    <button type="button" class="day-button " data-date="2025-08-04">04</button>
                    <button type="button" class="day-button " data-date="2025-08-05">05</button>
                    <button type="button" class="day-button " data-date="2025-08-06">06</button>
                    <button type="button" class="day-button " data-date="2025-08-07">07</button>
                    <button type="button" class="day-button " data-date="2025-08-08">08</button>
                    <button type="button" class="day-button " data-date="2025-08-09">09</button>
                    <button type="button" class="day-button " data-date="2025-08-10">10</button>
                    <button type="button" class="day-button " data-date="2025-08-11">11</button>
                    <button type="button" class="day-button " data-date="2025-08-12">12</button>
                    <button type="button" class="day-button " data-date="2025-08-13">13</button>
                    <button type="button" class="day-button " data-date="2025-08-14">14</button>
                    <button type="button" class="day-button " data-date="2025-08-15">15</button>
                    <button type="button" class="day-button " data-date="2025-08-16">16</button>
                    <button type="button" class="day-button " data-date="2025-08-17">17</button>
                    <button type="button" class="day-button " data-date="2025-08-18">18</button>
                    <button type="button" class="day-button " data-date="2025-08-19">19</button>
                    <button type="button" class="day-button " data-date="2025-08-20">20</button>
                    <button type="button" class="day-button " data-date="2025-08-21">21</button>
                    <button type="button" class="day-button " data-date="2025-08-22">22</button>
                    <button type="button" class="day-button " data-date="2025-08-23">23</button>
                    <button type="button" class="day-button " data-date="2025-08-24">24</button>
                    <button type="button" class="day-button " data-date="2025-08-25">25</button>
                    <button type="button" class="day-button " data-date="2025-08-26">26</button>
                    <button type="button" class="day-button " data-date="2025-08-27">27</button>
                    <button type="button" class="day-button " data-date="2025-08-28">28</button>
                    <button type="button" class="day-button " data-date="2025-08-29">29</button>
                    <button type="button" class="day-button " data-date="2025-08-30">30</button>
                    <button type="button" class="day-button " data-date="2025-08-31">31</button>
                </div>
            </div>
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
                        <tr>
                            <td>1</td>
                            <td>123 LENDING CORPORATION</td>
                            <td>BDO</td>
                            <td></td> <!-- Leave Biller's Name column empty as in the image -->
                            <td style="text-align: right;">93</td>
                            <td style="text-align: right;">1,194,099.52</td>
                            <td style="text-align: right;">53,262.00</td>
                            <td style="text-align: right;">0</td>
                            <td style="text-align: right;">0.00</td>
                            <td style="text-align: right;">0.00</td>
                            <td style="text-align: right;">93</td>
                            <td style="text-align: right;">1,194,099.52</td>
                            <td style="text-align: right;">53,262.00</td>
                        </tr>
                        <tr>
                            <td>2</td>
                            <td>A-1 MILLING CORPORATION</td>
                            <td>METROBANK</td>
                            <td></td> <!-- Leave Biller's Name column empty as in the image -->
                            <td style="text-align: right;">56</td>
                            <td style="text-align: right;">5,982,860.00</td>
                            <td style="text-align: right;">135,100.00</td>
                            <td style="text-align: right;">0</td>
                            <td style="text-align: right;">0.00</td>
                            <td style="text-align: right;">0.00</td>
                            <td style="text-align: right;">56</td>
                            <td style="text-align: right;">5,982,860.00</td>
                            <td style="text-align: right;">135,100.00</td>
                        </tr>
                        <tr>
                            <td>3</td>
                            <td>A.L.L SALES</td>
                            <td>BDO</td>
                            <td></td> <!-- Leave Biller's Name column empty as in the image -->
                            <td style="text-align: right;">65</td>
                            <td style="text-align: right;">422,597.40</td>
                            <td style="text-align: right;">37,530.00</td>
                            <td style="text-align: right;">1</td>
                            <td style="text-align: right;">1,080.00</td>
                            <td style="text-align: right;">0.00</td>
                            <td style="text-align: right;">64</td>
                            <td style="text-align: right;">421,517.40</td>
                            <td style="text-align: right;">37,530.00</td>
                        </tr>
                        <tr>
                            <td>4</td>
                            <td>A2M GLOBAL DISTRIBUTION INC.</td>
                            <td>METROBANK</td>
                            <td></td> <!-- Leave Biller's Name column empty as in the image -->
                            <td style="text-align: right;">48</td>
                            <td style="text-align: right;">1,150,890.51</td>
                            <td style="text-align: right;">30,620.97</td>
                            <td style="text-align: right;">0</td>
                            <td style="text-align: right;">0.00</td>
                            <td style="text-align: right;">0.00</td>
                            <td style="text-align: right;">48</td>
                            <td style="text-align: right;">1,150,890.51</td>
                            <td style="text-align: right;">30,620.97</td>
                        </tr>
                        <tr>
                            <td>5</td>
                            <td>AEON CREDIT SERVICE PHIL INC. API</td>
                            <td>METROBANK</td>
                            <td></td> <!-- Leave Biller's Name column empty as in the image -->
                            <td style="text-align: right;">193</td>
                            <td style="text-align: right;">512,322.27</td>
                            <td style="text-align: right;">26,342.77</td>
                            <td style="text-align: right;">0</td>
                            <td style="text-align: right;">0.00</td>
                            <td style="text-align: right;">0.00</td>
                            <td style="text-align: right;">193</td>
                            <td style="text-align: right;">512,322.27</td>
                            <td style="text-align: right;">26,342.77</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th colspan="4">TOTAL</th>
                            <th style="text-align: right;">210,976</th>
                            <th style="text-align: right;">3,656,346,748.25</th>
                            <th style="text-align: right;">13,014,347.29</th>
                            <th style="text-align: right;">28</th>
                            <th style="text-align: right;">719,487.53</th>
                            <th style="text-align: right;">1,571.00</th>
                            <th style="text-align: right;">210,948</th>
                            <th style="text-align: right;">3,655,627,260.72</th>
                            <th style="text-align: right;">13,012,776.29</th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php else: ?>
            <!-- Report Header -->
            <div class="report-header mb-4" style="display: block;">
                <img src="../images/ml.png" alt="MLHUILLIER Logo" style="height:35px; width:20%; margin:0 0 5px 0; display:block;">
                <h4 style="margin-bottom: 5px; font-weight:bold; text-align:left;">BILLS PAYMENT - DAILY VOLUME</h4>
                <p style="color:rgb(173, 18, 204); font-weight: bold; margin-bottom:0; text-align:left;">Report Date: <?php echo $formattedDateRange; ?></p>
            </div>
            <!-- Day Shortcut Buttons -->
            <div class="day-shortcut-container">
                <div class="day-buttons-label">Filter by Day:</div>
                <div class="day-buttons-wrapper" id="dayButtonsWrapper">
                    <!-- Day buttons will be styled to match the image design -->
                    <button class="day-button day-button-all day-button-active" id="allDaysButton">All</button>
                    <button type="button" class="day-button " data-date="2025-08-01">01</button>
                    <button type="button" class="day-button " data-date="2025-08-02">02</button>
                    <button type="button" class="day-button " data-date="2025-08-03">03</button>
                    <button type="button" class="day-button " data-date="2025-08-04">04</button>
                    <button type="button" class="day-button " data-date="2025-08-05">05</button>
                    <button type="button" class="day-button " data-date="2025-08-06">06</button>
                    <button type="button" class="day-button " data-date="2025-08-07">07</button>
                    <button type="button" class="day-button " data-date="2025-08-08">08</button>
                    <button type="button" class="day-button " data-date="2025-08-09">09</button>
                    <button type="button" class="day-button " data-date="2025-08-10">10</button>
                    <button type="button" class="day-button " data-date="2025-08-11">11</button>
                    <button type="button" class="day-button " data-date="2025-08-12">12</button>
                    <button type="button" class="day-button " data-date="2025-08-13">13</button>
                    <button type="button" class="day-button " data-date="2025-08-14">14</button>
                    <button type="button" class="day-button " data-date="2025-08-15">15</button>
                    <button type="button" class="day-button " data-date="2025-08-16">16</button>
                    <button type="button" class="day-button " data-date="2025-08-17">17</button>
                    <button type="button" class="day-button " data-date="2025-08-18">18</button>
                    <button type="button" class="day-button " data-date="2025-08-19">19</button>
                    <button type="button" class="day-button " data-date="2025-08-20">20</button>
                    <button type="button" class="day-button " data-date="2025-08-21">21</button>
                    <button type="button" class="day-button " data-date="2025-08-22">22</button>
                    <button type="button" class="day-button " data-date="2025-08-23">23</button>
                    <button type="button" class="day-button " data-date="2025-08-24">24</button>
                    <button type="button" class="day-button " data-date="2025-08-25">25</button>
                    <button type="button" class="day-button " data-date="2025-08-26">26</button>
                    <button type="button" class="day-button " data-date="2025-08-27">27</button>
                    <button type="button" class="day-button " data-date="2025-08-28">28</button>
                    <button type="button" class="day-button " data-date="2025-08-29">29</button>
                    <button type="button" class="day-button " data-date="2025-08-30">30</button>
                    <button type="button" class="day-button " data-date="2025-08-31">31</button>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-bordered">
                    <tbody>
                        <tr>
                            <td class="alert alert-warning" colspan="13" style="text-align: center; font-weight: bold; padding: 20px;">
                                No records found for the selected filters.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            // Store partners with both ID and name
            let partners = [{id: "All", name: "All"}]; // Start with "All" option
            
            <?php
            include '../config/config.php';
            
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
            $sql = "SELECT partner_id, partner_id_kpx, partner_name FROM partner_masterfile ORDER BY partner_name ASC";
            $result = $conn->query($sql);
            if ($result && $result->num_rows > 0) {
                echo "// Add partners from database\n";
                while ($row = $result->fetch_assoc()) {
                    // use partner_id for id and partner_name for display
                    echo 'partners.push({id: "' . addslashes($row['partner_id']) . '", name: "' . addslashes($row['partner_name']) . '"});' . "\n";
                }
            }
            ?>
            
            const inputField = document.getElementById("partnerNameInput");
            const hiddenField = document.getElementById("partnerName");
            const autocompleteList = document.getElementById("autocomplete-list");
            const maxDisplayLimit = Math.min(totalPartnerCount + 1);
            
            // Set the hidden field default to 'All' to avoid empty filter on submit
            hiddenField.value = 'All';
            
            // Set the initial value if it exists in POST data
            <?php if (isset($_POST['partnerName'])): ?>
            const selectedPartnerID = "<?php echo addslashes($_POST['partnerName']); ?>";
            const selectedPartner = partners.find(p => p.id === selectedPartnerID || p.name === selectedPartnerID);
            if (selectedPartner) {
                inputField.value = selectedPartner.name;
                hiddenField.value = selectedPartner.id;
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
                        partnerName.includes('-' + inputValue)) {
                        
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
                const matchedPartner = partners.find(p => p.name === this.value);
                if (matchedPartner) {
                    hiddenField.value = matchedPartner.id;
                } else {
                    // if no exact match, keep default 'All' to avoid sending empty id that yields no rows
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

            // Replace EDI button logic with redirect
            const ediButton = document.getElementById('ediButton');
            if (ediButton) {
                ediButton.addEventListener('click', function() {
                    // Always allow EDI button to redirect, even if no report is generated
                    let partnerID = hiddenField.value || 'All';
                    let fromDate = document.getElementById("fromDate").value || '';
                    let toDate = document.getElementById("toDate").value || '';
                    const params = new URLSearchParams({
                        partnerID: partnerID,
                        fromDate: fromDate,
                        toDate: toDate
                    });
                    window.location.href = "dailyvol_edi.php?" + params.toString();
                });
            }
        });

        // Day shortcut buttons functionality
        document.addEventListener("DOMContentLoaded", function() {
            const dayButtons = document.querySelectorAll('.day-button');
            const allDaysBtn = document.getElementById('allDaysButton');
            const selectedDayInput = document.getElementById('selectedDay');
            const originalFromDate = document.getElementById('originalFromDate');
            const originalToDate = document.getElementById('originalToDate');
            const originalPartner = document.getElementById('originalPartner');
            
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
                            
                            // Keep original partner ID (not name)
                            const partnerInput = document.createElement('input');
                            partnerInput.type = 'hidden';
                            partnerInput.name = 'partnerName'; // Keep as partnerName for POST form compatibility
                            partnerInput.value = originalPartner.value; // This should contain partner ID
                            
                            // Use the selected day for data filtering
                            const fromDateInput = document.createElement('input');
                            fromDateInput.type = 'hidden';
                            fromDateInput.name = 'fromDate';
                            fromDateInput.value = date;
                            
                            const toDateInput = document.createElement('input');
                            toDateInput.type = 'hidden';
                            toDateInput.name = 'toDate';
                            toDateInput.value = date;
                            
                            // Mark this as a day filter to preserve buttons
                            const filterDayInput = document.createElement('input');
                            filterDayInput.type = 'hidden';
                            filterDayInput.name = 'filterDay';
                            filterDayInput.value = date;
                            
                            // Also include the original date range for reference
                            const origFromInput = document.createElement('input');
                            origFromInput.type = 'hidden';
                            origFromInput.name = 'origFromDate';
                            origFromInput.value = originalFromDate.value;
                            
                            const origToInput = document.createElement('input');
                            origToInput.type = 'hidden';
                            origToInput.name = 'origToDate';
                            origToInput.value = originalToDate.value;
                            
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
            
            // All Days button handler
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
                    
                    const partnerInput = document.createElement('input');
                    partnerInput.type = 'hidden';
                    partnerInput.name = 'partnerName';
                    partnerInput.value = originalPartner.value;
                    
                    const fromDateInput = document.createElement('input');
                    fromDateInput.type = 'hidden';
                    fromDateInput.name = 'fromDate';
                    fromDateInput.value = originalFromDate.value;
                    
                    const toDateInput = document.createElement('input');
                    toDateInput.type = 'hidden';
                    toDateInput.name = 'toDate';
                    toDateInput.value = originalToDate.value;
                    
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
</body>
</html>