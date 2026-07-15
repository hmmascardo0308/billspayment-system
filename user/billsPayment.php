<?php
// Start the session
session_start();

// Connect to the database
include '../config/config.php';

// Redirect to login page if the user is not logged in
if (!isset($_SESSION['user_name'])) {
    header('location: login_form.php');
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>M Lhuillier Bills Payment Import</title>
    <!-- Link CSS and JS libraries -->
    <link href="../assets/css/import_billsPayment.css?v=<?php echo time(); ?>" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.10.22/css/jquery.dataTables.min.css" />
    <script type="text/javascript" src="https://code.jquery.com/jquery-3.5.1.js"></script>
    <script src="https://kit.fontawesome.com/30b908cc5a.js" crossorigin="anonymous"></script>
    <script type="text/javascript" src="https://cdn.datatables.net/scroller/2.1.1/js/dataTables.scroller.min.js"></script>
    <script type="text/javascript" src="https://cdn.datatables.net/1.10.22/js/jquery.dataTables.min.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="icon" href="../images/MLW logo.png" type="image/png">
</head>

<body>
<div class="container">
    <div class="top-content">
        <div class="nav-container">
        <i id="menu-btn" class="fa-solid fa-bars"></i>
        <div class="usernav">
            <h4><?php echo $_SESSION['user_name'] ?></h4>
            <h4 style="margin-left:5px;"><?php echo "(".$_SESSION['user_email'].")" ?></h4>
        </div>
        </div>
    </div>
    <!-- Show and Hide Side Nav Menu -->
    <?php include '../templates/user/sidebar.php' ?>
    <div id="loading-overlay">
        <div class="loading-spinner"></div>
    </div>
    <!-- Popup Modal HTML -->
    <div id="messageModal" class="messageModal">
        <div class="messageModal-content">
            <span class="message_close">&times;</span>
            <p id="modalMessage">
                <?php
                // Show alert message from session and remove it
                if (isset($_SESSION['alert-message'])) {
                    echo "<h2 class='error-msg'>" . $_SESSION['alert-message'] . "</h2>";
                    unset($_SESSION['alert-message']);
                } elseif (isset($_SESSION['succ-message'])) {
                    echo "<h2 class='success-msg'>" . $_SESSION['succ-message'] . "</h2>";
                    unset($_SESSION['succ-message']);
                }
                ?>
            </p>
        </div>
    </div>
        <div class="card">
            <div class="card-body">
                <form action="billsCode.php" method="POST" enctype="multipart/form-data" class="form">
                    <div class="progress_modal-dialog" role="document">
                        <div class="progress_modal-content">
                            <div class="progress_modal-body">
                                <div class="progress">
                                    <i class="loading-icon"></i>
                                </div>
                            </div>
                            <div class="progress_modal-header">
                                <h5 class="progress_modal-title">Import in Progress...</h5>
                            </div>
                        </div>
                    </div>
                    <!-- <div class="cancel_date">
                        <label for="">Select Cancellation Date:</label>
                        <input type="date" name="cdate" class="cdate" id="cdate" value="" required>
                    </div> -->
                    <!-- Partners Name Dropdown -->
                    <?php
                        // Fetch partners from the database
                        $partners = [];
                        $sql = "SELECT partner_name FROM masterdata.partner_masterfile ORDER BY partner_name ASC";
                        $result = $conn->query($sql);
                        if ($result && $result->num_rows > 0) {
                            while ($row = $result->fetch_assoc()) {
                                $partners[] = $row['partner_name'];
                            }
                        }
                    ?>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="companyDropdown" class="form-label">Partners Name:</label>
                            <div class="custom-select-wrapper">
                                <select id="companyDropdown" class="form-control select2-dropdown" name="company" required>
                                    <option value="" disabled selected>Search or select a company...</option>
                                    <option value="All">All</option>
                                    <?php foreach ($partners as $partner): ?>
                                        <option value="<?php echo htmlspecialchars($partner); ?>" <?php echo (isset($_SESSION['selected_partner']) && $_SESSION['selected_partner'] === $partner) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($partner); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <!-- Source File Type Dropdown -->
                        <div class="form-group">
                            <label for="fileType" class="form-label">Source File Type:</label>
                            <div class="custom-select-wrapper">
                                <select id="fileType" class="custom-select" name="fileType" required>
                                    <option value="" disabled selected>Select Source File Type</option>
                                    <option value="KPX">KPX</option>
                                    <option value="KP7">KP7</option>
                                </select>
                                <div class="select-arrow">
                                    <i class="fa-solid fa-chevron-down"></i>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Import File Section -->
                        <div class="form-group">
                            <label for="import_file" class="form-label">Import File:</label>
                            <div class="file-input-wrapper">
                                <input type="file" name="import_file" id="import_file" class="file-input" required />
                                <label for="import_file" class="file-input-label">
                                    <i class="fa-solid fa-upload"></i>
                                    <span class="file-text">Choose File</span>
                                </label>
                                <div class="file-name-container">
                                    <span class="file-name-text file-name">No file chosen</span>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Import Button -->
                        <div class="form-group">
                            <button id="save_excel_data" type="submit" name="save_excel_data" class="import-btn">
                                <i class="fa-solid fa-file-import"></i>
                                Import
                            </button>
                        </div>
                    </div>
                    
                    <input style="display:none;" type="text" name="importedby" value="<?php echo $_SESSION['user_name'] ?>" readonly required>
                    <!-- <div class="display-data"> -->
                        <!-- <a class="display-btn" onclick="formToggle('tableID');">Display/Hide</a> -->
                        <!-- <a href="duplicate.php" class="duplicate-btn">Duplicate Transaction</a>
                        <a href="multiple.php" class="duplicate-btn">Multiple Transaction</a>
                        <a href="split.php" class="duplicate-btn">Split Transaction</a> -->
                    <!-- </div> -->
                </form>
            </div>
        </div>
    </div>

    <script>
        /* Initialization of datatable */
        $(document).ready(function() {
            $('#tableID').DataTable({"ordering": false });
        });
    </script>
    
    

    <div class="leg_wrap">
        <!-- DYNAMIC DATA TABLE SECTION -->
        <div class="table-wrapper">
            <div class="table-container">
                <div class="table-header">
                    <div class="table-controls">
                        <div class="search-container">
                            <input type="text" id="tableSearch" placeholder="Search transactions..." class="search-input">
                            <i class="fa-solid fa-search search-icon"></i>
                        </div>
                        <div class="filter-container">
                            <select id="statusFilter" class="filter-select">
                                <option value="">All Status</option>
                                <option value="*">Cancelled</option>
                                <option value="regular">Regular</option>
                            </select>
                            
                            <select id="partnerFilter" class="filter-select">
                                <option value="">All Partners</option>
                                <?php foreach ($partners as $partner): ?>
                                    <option value="<?php echo htmlspecialchars($partner); ?>">
                                        <?php echo htmlspecialchars($partner); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="table-actions">
                        <button class="action-btn refresh-btn" onclick="debugInfo()" title="Import Details">
                            <i class="fas fa-info-circle"></i>
                        </button>
                        <button class="action-btn export-btn" onclick="exportTable()" title="Export Data">
                            <i class="fa-solid fa-download"></i>
                        </button>
                    </div>
                </div>
                
                <div class="table-content">
                    <table id="billsPaymentTable" class="data-table">
                        <thead>
                            <tr>
                                <th>Row in Excel</th>
                                <th>Transaction Date</th>
                                <th>Reference Number</th>
                                <th>Partner</th>
                                <th>Amount Paid</th>
                                <th>Charge to Partner</th>
                                <th>Charge to Customer</th>
                                <th>Status</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody id="tableBody">
                            <?php
                            $sql = "SELECT * FROM temp_billsPayment ORDER BY id DESC LIMIT 50";
                            $result = $conn->query($sql);
                            
                            if ($result && $result->num_rows > 0) {
                                while ($row = $result->fetch_assoc()) {
                                    $statusClass = '';
                                    $statusText = '';
                                    
                                    switch ($row['status']) {
                                        case '*':
                                            $statusClass = 'status-cancelled';
                                            $statusText = 'Cancelled';
                                            break;
                                        case '**':
                                            $statusClass = 'status-walletdb';
                                            $statusText = 'WalletDB Only';
                                            break;
                                        case '***':
                                            $statusClass = 'status-billspaydb';
                                            $statusText = 'BillsPayDB Only';
                                            break;
                                        default:
                                            $statusClass = 'status-normal';
                                            $statusText = 'Normal';
                                    }
                                    
                                    echo "<tr data-id='{$row['id']}' data-status='{$row['status']}' data-partner='{$row['partner_name']}'>";
                                    echo "<td>{$row['id']}</td>";
                                    echo "<td>" . date('Y-m-d H:i:s', strtotime($row['transaction_date'])) . "</td>";
                                    echo "<td>{$row['reference_number']}</td>";
                                    echo "<td>{$row['partner_name']}</td>";
                                    echo "<td class='amount'>" . number_format($row['amount_paid'], 2) . "</td>";
                                    echo "<td class='amount'>" . number_format($row['charge_to_partner'], 2) . "</td>";
                                    echo "<td class='amount'>" . number_format($row['charge_to_customer'], 2) . "</td>";
                                    echo "<td><span class='status-badge {$statusClass}'>{$statusText}</span></td>";
                                    echo "<td class='actions'>
                                            <button class='action-btn view-btn' onclick='viewTransaction({$row['id']})' title='View Details'>
                                                <i class='fa-solid fa-eye'></i>
                                            </button>
                                            <button class='action-btn edit-btn' onclick='editTransaction({$row['id']})' title='Edit'>
                                                <i class='fa-solid fa-edit'></i>
                                            </button>
                                        </td>";
                                    echo "</tr>";
                                }
                            } else {
                                echo "<tr><td colspan='9' class='no-data'>No data available</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="table-footer">
                    <div class="pagination-info">
                        <span id="paginationInfo">Showing 1-50 of <?php echo $rowcount; ?> entries</span>
                    </div>
                    <div class="pagination-controls">
                        <button class="pagination-btn" id="prevBtn" onclick="changePage(-1)" disabled>
                            <i class="fa-solid fa-chevron-left"></i>
                        </button>
                        <span id="pageNumbers"></span>
                        <button class="pagination-btn" id="nextBtn" onclick="changePage(1)">
                            <i class="fa-solid fa-chevron-right"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <!-- LEGEND -->
        <div class="lg-div">
            <div class="lg-container">
                <div class="legend">
                    <h3 class="legend-title"><i class="fas fa-chart-line"></i>TRANSACTION SUMMARY</h3>
                </div>
            </div>

            <!-- Enhanced Summary Section with Horizontal Layout -->
            <div class="summary-section">
                <!-- Summary Header with Dropdown -->
                <div class="summary-header">
                    <div class="summary-title">
                        <span class="summary-label">SUMMARY</span>
                        <button class="summary-toggle" onclick="toggleSection('summary')">
                            <i class="fas fa-chevron-down" id="summary-icon"></i>
                        </button>
                    </div>
                </div>
                
                <div class="summary-content" id="summary-content">
                    <div class="summary-grid">
                        <!-- Total Count -->
                        <div class="summary-item">
                            <div class="summary-item-label">Total Count</div>
                            <div class="summary-item-value">
                                <?php 
                                    $sql = "SELECT COUNT(*) as total_count FROM temp_billsPayment";
                                    $result = mysqli_query($conn, $sql);
                                    $row = mysqli_fetch_assoc($result);
                                    echo number_format($row['total_count']);
                                ?>
                            </div>
                        </div>

                        <!-- Total Principal -->
                        <div class="summary-item">
                            <div class="summary-item-label">Total Principal</div>
                            <div class="summary-item-value">
                                <span class="currency">&#8369;</span>
                                <?php 
                                    $positiveSum = mysqli_query($conn, 'SELECT ROUND(Sum( ( Sign( amount_paid ) + 1 ) / 2 * amount_paid ), 2) as PositiveSum FROM temp_billspayment'); 
                                    $row = mysqli_fetch_assoc($positiveSum);
                                    $negativeSum = mysqli_query($conn, 'SELECT ROUND(Sum( -( Sign( amount_paid ) - 1 ) / 2 * amount_paid ), 2) as NegativeSum FROM temp_billspayment'); 
                                    $rows = mysqli_fetch_assoc($negativeSum);
                                    echo number_format($row['PositiveSum'] - $rows['NegativeSum'], 2);
                                ?>
                            </div>
                        </div>

                        <!-- Total Charge -->
                        <div class="summary-item">
                            <div class="summary-item-label">Total Charge</div>
                            <div class="summary-item-value">
                                <span class="currency">&#8369;</span>
                                <?php 
                                    $result = mysqli_query($conn, 'SELECT SUM(charge_to_partner) AS charge_to_partner FROM temp_billsPayment'); 
                                    $row = mysqli_fetch_assoc($result); 
                                    echo number_format($row['charge_to_partner'], 2);
                                ?>
                            </div>
                        </div>

                        <!-- Charge to Partner -->
                        <div class="summary-item">
                            <div class="summary-item-label">Charge to Partner</div>
                            <div class="summary-item-value">
                                <span class="currency">&#8369;</span>
                                <?php 
                                    $result = mysqli_query($conn, 'SELECT SUM(charge_to_customer) AS charge_to_customer FROM temp_billsPayment'); 
                                    $row = mysqli_fetch_assoc($result); 
                                    echo number_format($row['charge_to_customer'], 2);
                                ?>
                            </div>
                        </div>

                        <!-- Charge to Customer -->
                        <div class="summary-item">
                            <div class="summary-item-label">Charge to Customer</div>
                            <div class="summary-item-value">
                                <span class="currency">&#8369;</span>
                                <?php 
                                    $result = mysqli_query($conn, 'SELECT SUM(charge_to_customer) AS charge_to_customer FROM temp_billsPayment'); 
                                    $row = mysqli_fetch_assoc($result); 
                                    echo number_format($row['charge_to_customer'], 2);
                                ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Adjustment Section with Dropdown -->
            <div class="summary-section">
                <div class="summary-header">
                    <div class="summary-title">
                        <span class="summary-label">ADJUSTMENT</span>
                        <button class="summary-toggle" onclick="toggleSection('adjustment')">
                            <i class="fas fa-chevron-down" id="adjustment-icon"></i>
                        </button>
                    </div>
                </div>
                
                <div class="summary-content" id="adjustment-content">
                    <div class="summary-grid">
                        <!-- Adjustment Count -->
                        <div class="summary-item">
                            <div class="summary-item-label">Total Count</div>
                            <div class="summary-item-value">
                                <?php 
                                    $sql = "SELECT COUNT(*) as total_count FROM temp_billsPayment";
                                    $result = mysqli_query($conn, $sql);
                                    $row = mysqli_fetch_assoc($result);
                                    echo number_format($row['total_count']);
                                ?>
                            </div>
                        </div>

                        <!-- Adjustment Principal -->
                        <div class="summary-item">
                            <div class="summary-item-label">Total Principal</div>
                            <div class="summary-item-value">
                                <span class="currency">&#8369;</span>
                                <?php 
                                    $positiveSum = mysqli_query($conn, 'SELECT ROUND(Sum( ( Sign( amount_paid ) + 1 ) / 2 * amount_paid ), 2) as PositiveSum FROM temp_billspayment'); 
                                    $row = mysqli_fetch_assoc($positiveSum);
                                    $negativeSum = mysqli_query($conn, 'SELECT ROUND(Sum( -( Sign( amount_paid ) - 1 ) / 2 * amount_paid ), 2) as NegativeSum FROM temp_billspayment'); 
                                    $rows = mysqli_fetch_assoc($negativeSum);
                                    echo number_format($row['PositiveSum'] - $rows['NegativeSum'], 2);
                                ?>
                            </div>
                        </div>

                        <!-- Adjustment Charge -->
                        <div class="summary-item">
                            <div class="summary-item-label">Total Charge</div>
                            <div class="summary-item-value">
                                <span class="currency">&#8369;</span>
                                <?php 
                                    $result = mysqli_query($conn, 'SELECT SUM(charge_to_partner) AS charge_to_partner FROM temp_billsPayment'); 
                                    $row = mysqli_fetch_assoc($result); 
                                    echo number_format($row['charge_to_partner'], 2);
                                ?>
                            </div>
                        </div>

                        <!-- Adjustment Charge to Partner -->
                        <div class="summary-item">
                            <div class="summary-item-label">Charge to Partner</div>
                            <div class="summary-item-value">
                                <span class="currency">&#8369;</span>
                                <?php 
                                    $result = mysqli_query($conn, 'SELECT SUM(charge_to_customer) AS charge_to_customer FROM temp_billsPayment'); 
                                    $row = mysqli_fetch_assoc($result); 
                                    echo number_format($row['charge_to_customer'], 2);
                                ?>
                            </div>
                        </div>

                        <!-- Adjustment Charge to Customer -->
                        <div class="summary-item">
                            <div class="summary-item-label">Charge to Customer</div>
                            <div class="summary-item-value">
                                <span class="currency">&#8369;</span>
                                <?php 
                                    $result = mysqli_query($conn, 'SELECT SUM(charge_to_customer) AS charge_to_customer FROM temp_billsPayment'); 
                                    $row = mysqli_fetch_assoc($result); 
                                    echo number_format($row['charge_to_customer'], 2);
                                ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Net Section with Dropdown -->
            <div class="summary-section">
                <div class="summary-header">
                    <div class="summary-title">
                        <span class="summary-label">NET</span>
                        <button class="summary-toggle" onclick="toggleSection('net')">
                            <i class="fas fa-chevron-down" id="net-icon"></i>
                        </button>
                    </div }
                </div>
                
                <div class="summary-content" id="net-content">
                    <div class="summary-grid">
                        <!-- Net Count -->
                        <div class="summary-item">
                            <div class="summary-item-label">Total Count</div>
                            <div class="summary-item-value">
                                <?php 
                                    $sql = "SELECT COUNT(*) as total_count FROM temp_billsPayment";
                                    $result = mysqli_query($conn, $sql);
                                    $row = mysqli_fetch_assoc($result);
                                    echo number_format($row['total_count']);
                                ?>
                            </div>
                        </div>

                        <!-- Net Principal -->
                        <div class="summary-item">
                            <div class="summary-item-label">Total Principal</div>
                            <div class="summary-item-value">
                                <span class="currency">&#8369;</span>
                                <?php 
                                    $positiveSum = mysqli_query($conn, 'SELECT ROUND(Sum( ( Sign( amount_paid ) + 1 ) / 2 * amount_paid ), 2) as PositiveSum FROM temp_billspayment'); 
                                    $row = mysqli_fetch_assoc($positiveSum);
                                    $negativeSum = mysqli_query($conn, 'SELECT ROUND(Sum( -( Sign( amount_paid ) - 1 ) / 2 * amount_paid ), 2) as NegativeSum FROM temp_billspayment'); 
                                    $rows = mysqli_fetch_assoc($negativeSum);
                                    echo number_format($row['PositiveSum'] - $rows['NegativeSum'], 2);
                                ?>
                            </div>
                        </div>

                        <!-- Net Charge -->
                        <div class="summary-item">
                            <div class="summary-item-label">Total Charge</div>
                            <div class="summary-item-value">
                                <span class="currency">&#8369;</span>
                                <?php 
                                    $result = mysqli_query($conn, 'SELECT SUM(charge_to_partner) AS charge_to_partner FROM temp_billsPayment'); 
                                    $row = mysqli_fetch_assoc($result); 
                                    echo number_format($row['charge_to_partner'], 2);
                                ?>
                            </div>
                        </div>

                        <!-- Net Charge to Partner -->
                        <div class="summary-item">
                            <div class="summary-item-label">Charge to Partner</div>
                            <div class="summary-item-value">
                                <span class="currency">&#8369;</span>
                                <?php 
                                    $result = mysqli_query($conn, 'SELECT SUM(charge_to_customer) AS charge_to_customer FROM temp_billsPayment'); 
                                    $row = mysqli_fetch_assoc($result); 
                                    echo number_format($row['charge_to_customer'], 2);
                                ?>
                            </div>
                        </div>

                        <!-- Net Charge to Customer -->
                        <div class="summary-item">
                            <div class="summary-item-label">Charge to Customer</div>
                            <div class="summary-item-value">
                                <span class="currency">&#8369;</span>
                                <?php 
                                    $result = mysqli_query($conn, 'SELECT SUM(charge_to_customer) AS charge_to_customer FROM temp_billsPayment'); 
                                    $row = mysqli_fetch_assoc($result); 
                                    echo number_format($row['charge_to_customer'], 2);
                                ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Net Principal -->
            <div class="summary-item">
                <div class="summary-item-label">Total Amount (PHP)</div>
                <div class="summary-item-value">
                    <span class="currency">&#8369;</span>
                    <?php 
                        $positiveSum = mysqli_query($conn, 'SELECT ROUND(Sum( ( Sign( amount_paid ) + 1 ) / 2 * amount_paid ), 2) as PositiveSum FROM temp_billspayment'); 
                        $row = mysqli_fetch_assoc($positiveSum);
                        $negativeSum = mysqli_query($conn, 'SELECT ROUND(Sum( -( Sign( amount_paid ) - 1 ) / 2 * amount_paid ), 2) as NegativeSum FROM temp_billspayment'); 
                        $rows = mysqli_fetch_assoc($negativeSum);
                        echo number_format($row['PositiveSum'] - $rows['NegativeSum'], 2);
                    ?>
                </div>
            </div>
        </div>
    </div>
</div>

</body>

<?php include '../templates/user/footer.php'; ?>

</html>

<script>
// Function to toggle summary sections
function toggleSection(sectionId) {
    const content = document.getElementById(sectionId + '-content');
    const icon = document.getElementById(sectionId + '-icon');
    
    if (content.style.display === 'none' || content.style.display === '') {
        content.style.display = 'block';
        icon.classList.remove('fa-chevron-down');
        icon.classList.add('fa-chevron-up');
    } else {
        content.style.display = 'none';
        icon.classList.remove('fa-chevron-up');
        icon.classList.add('fa-chevron-down');
    }
}

// Initialize all sections as expanded
document.addEventListener('DOMContentLoaded', function() {
    const sections = ['summary', 'adjustment', 'net'];
    sections.forEach(sectionId => {
        document.getElementById(sectionId + '-content').style.display = 'block';
    });
});
</script>
