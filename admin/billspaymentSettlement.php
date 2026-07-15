<?php

session_start();

if (!isset($_SESSION['admin_name'])) {
    header('location:../login_form.php');
}

require_once __DIR__ . '/../config/config.php';
require '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

if (isset($_POST['download'])) {
   
    $settlement_type = $_SESSION['settlement_type'] ?? '';
    $bank = $_SESSION['bank'] ?? '';
    $from = $_SESSION['from'] ?? '';
    $to = $_SESSION['to'] ?? '';


    $cadNo = $_POST['cad_no'];
    $rfpNo = $_POST['rfp_no'];

    generateDownload($conn, $settlement_type, $bank, $from, $to, $cadNo, $rfpNo);
}

function generateDownload($conn, $settlement_type, $bank, $from, $to, $cadNo, $rfpNo) {

    // Start output buffering to ensure nothing gets sent before the headers
    ob_start();
    
    $dlsql = "SELECT pm.partner_name, pm.transaction_range, pm.charge_to, pm.serviceCharge, pm.partner_accName, 
              COUNT(bt.id) AS total_count, SUM(bt.amount_paid) AS principal_amount, 
              SUM(bt.charge_to_customer) AS total_charge_to_customer, 
              SUM(bt.charge_to_partner) AS total_charge_to_partner, 
              GROUP_CONCAT(DISTINCT pb.bank_accNumber ORDER BY pb.id SEPARATOR ', ') AS all_bank_accNumbers, 
              GROUP_CONCAT(DISTINCT ct.charge_amount ORDER BY ct.id SEPARATOR ', ') AS all_charge_amount 
              FROM mldb.billspayment_transaction bt 
              INNER JOIN mldb.partner_masterfile pm ON bt.partner_id = pm.partner_id 
              INNER JOIN mldb.partner_bank pb ON pb.partner_id = pm.partner_id 
              INNER JOIN mldb.charge_table ct ON ct.partner_id = pm.partner_id
              WHERE pm.settled_online_check = '$settlement_type' 
              AND pb.bank = '$bank' 
              AND bt.datetime BETWEEN '$from 00:00:00' AND '$to 23:59:59'
              GROUP BY bt.partner_id, pm.partner_name, pm.partner_accName, pm.charge_to, pb.bank_accNumber, pm.serviceCharge, pm.transaction_range
              ORDER BY pm.partner_name;";

    $dlresult = mysqli_query($conn, $dlsql);
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet(); 

    if (mysqli_num_rows($dlresult) > 0) {
        // Set header rows for the spreadsheet
        $headers = [
            ['', 'REQUEST FOR PAYMENT FORM', '', '', '', '', '', '', ''],
            ['', 'M. LHUILLIER PHILIPPINES, INC.', '', '', '', '', '', '', 'DATE : ' . date('F d, Y')],
            ['', 'BILLS PAYMENT SETTLEMENT', '', '', '', '', '', '', 'CAD NO. : ' . $cadNo],
            ['', '', '', '', '', '', '', '', 'RFP NO. : ' . $rfpNo],
            ['', '', '', '', '', '', '', '', ''],
            ['', 'BANK NAME : ' . $bank . ' ' . $settlement_type, '', '', '', '', '', '', ''],
            ['', 'DATE OF TRANSACTION : ' . date('F d, Y', strtotime($from)) . ' to ' . date('F d, Y', strtotime($to)), '', '', '', '', '', '', ''],
            ['', 'MODE OF PAYMENT', '', '', '', '', '', '', ''],
            ['', '', '', '', '', '', '', '', ''],
            ['', 'LIST OF BILLS PAYMENT PARTNER', 'ACCOUNT NAME', 'ACCOUNT NUMBER', 'TXN COUNT', 'PRINCIPAL', 'CHARGE', 'ADJUSTMENT (add/less)', 'AMOUNT FOR SETTLEMENT']
        ];
    
        $sheet->fromArray($headers, null, 'A1');
        $sheet->mergeCells('B1:I1');
        $sheet->getStyle('B1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('B1:I10')->getFont()->setBold(true);
    
        foreach (range('A', 'I') as $columnID) {
            $sheet->getColumnDimension($columnID)->setAutoSize(true);
        }
    
        $rowIndex = 12;
    
        while ($excelRow = mysqli_fetch_assoc($dlresult)) {
            // Fill data in rows
            $sheet->setCellValue('B' . $rowIndex, $excelRow['partner_name']);
            $sheet->setCellValue('C' . $rowIndex, $excelRow['partner_accName']);
            $sheet->setCellValueExplicit('D' . $rowIndex, $excelRow['all_bank_accNumbers'], DataType::TYPE_STRING);
            $sheet->setCellValue('E' . $rowIndex, $excelRow['total_count']);
            $sheet->getStyle('E' . $rowIndex)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER);
            $sheet->setCellValue('F' . $rowIndex, $excelRow['principal_amount']);
            $sheet->getStyle('F' . $rowIndex)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_00);
            $sheet->setCellValue('G' . $rowIndex, $excelRow['total_charge_to_customer']);
            $sheet->getStyle('G' . $rowIndex)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_00);
            $sheet->setCellValue('H' . $rowIndex, $excelRow['total_charge_to_partner']);
            $sheet->getStyle('H' . $rowIndex)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_00);
    
            $rowIndex++;
        }
    
        $sheet->setCellValue('F' . $rowIndex, '=SUM(F12:F' . ($rowIndex - 1) . ')'); // Summing up principal amount
        $sheet->getStyle('F' . $rowIndex)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_00);
    
        $sheet->setCellValue('B' . ($rowIndex + 3), 'Prepared by :');
        $sheet->setCellValue('B' . ($rowIndex + 5), 'ANGEL BATAIN');
        $sheet->getStyle('B' . ($rowIndex + 5))->getFont()->setBold(true);
        $sheet->setCellValue('B' . ($rowIndex + 6), 'Accounting Staff');
        $sheet->setCellValue('B' . ($rowIndex + 8), 'Reviewed by :');
        $sheet->setCellValue('B' . ($rowIndex + 9), 'CHERRY ROSE CULPA');
        $sheet->getStyle('B' . ($rowIndex + 9))->getFont()->setBold(true);
        $sheet->setCellValue('B' . ($rowIndex + 10), 'Department Manager');
    
        $sheet->setCellValue('D' . ($rowIndex + 3), 'Checked by :');
        $sheet->setCellValue('D' . ($rowIndex + 5), 'ELVIE CILLO');
        $sheet->getStyle('D' . ($rowIndex + 5))->getFont()->setBold(true);
        $sheet->setCellValue('D' . ($rowIndex + 6), 'Accounting Staff');
        $sheet->setCellValue('D' . ($rowIndex + 8), 'Noted by :');
        $sheet->setCellValue('D' . ($rowIndex + 9), 'LUELLA PERALTA');
        $sheet->getStyle('D' . ($rowIndex + 9))->getFont()->setBold(true);
        $sheet->setCellValue('D' . ($rowIndex + 10), 'Division Manager');
    }
    
    
    // Clean the output buffer before sending the file
    ob_end_clean();

    // Prepare the writer for download
    $writer = new Xls($spreadsheet);

    // Set the headers for file download
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="report ' . $from . ' and ' .$to. ' .xls"');
    header('Cache-Control: max-age=0');

    // Write file to output
    $writer->save('php://output');
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
   <meta charset="UTF-8">
   <meta http-equiv="X-UA-Compatible" content="IE=edge">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title>Settlement</title>
   <!-- custom CSS file link  -->
   <link rel="stylesheet" href="../assets/css/billspaymentSettlement.css?v=<?php echo time(); ?>">
   <link rel="icon" href="../images/MLW logo.png" type="image/png">
   <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
</head>

<body>
    <div class="container">
        <div class="top-content">
            <div class="usernav">
                <h4><?php echo $_SESSION['admin_name'] ?></h4>
                <h5 style="margin-left:5px;"><?php echo " - ".$_SESSION['admin_email']."" ?></h5>
            </div>
            <div class="btn-nav">
               <ul class="nav-list">
                  <li><a href="admin_page.php">HOME</a></li>
                  <li class="dropdown">
                        <button class="dropdown-btn">Import File</button>
                        <div class="dropdown-content">
                        <a id="user" href="billspaymentImportFile.php">BILLSPAYMENT TRANSACTION</a>
                        </div>
                  </li>
                  <li class="dropdown">
                        <button class="dropdown-btn">Transaction</button>
                        <div class="dropdown-content">
                        <a id="user" href="billspaymentSettlement.php">SETTLEMENT</a>
                        </div>
                  </li>
                  <li class="dropdown">
                     <button class="dropdown-btn">Report</button>
                     <div class="dropdown-content">
                        <a id="user" href="billspaymentReport.php">BILLS PAYMENT</a>
                        <a id="user" href="dailyVolume.php">DAILY VOLUME</a>

                     </div>
                  </li>
                  <li class="dropdown">
                        <button class="dropdown-btn">MAINTENANCE</button>
                        <div class="dropdown-content">
                        <a id="user" href="userLog.php">USER</a>
                        <a id="user" href="partnerLog.php">PARTNER</a>
                        <a id="user" href="natureOfBusinessLog.php">NATURE OF BUSINESS</a>
                        <a id="user" href="bankLog.php">BANK</a>
                        </div>
                  </li>
                  <li><a href="../logout.php">LOGOUT</a></li>
               </ul>
            </div>
        </div>
    </div>
    <center><h2>TRANSACTION<span style="font-size: 22px; color: red;">[SETTLEMENT]</span></h2></center>
    <div class="filter-data">
        <form action="" method="post">

            <div class="custom-select-wrapper">
                <label for="settlement_type">Settlement Type</label>
                <select name="settlement_type" id="settlement_type" autocomplete="off" required>
                    <option value="">Select Settlement</option>
                    <option value="ONLINE" <?php echo (isset($_POST['settlement_type']) && $_POST['settlement_type'] == 'ONLINE') ? 'selected' : ''; ?>>ONLINE</option>
                    <option value="CHECK" <?php echo (isset($_POST['settlement_type']) && $_POST['settlement_type'] == 'CHECK') ? 'selected' : ''; ?>>CHECK</option>
                </select>
                <div class="custom-arrow"></div>
            </div>
            <div class="custom-select-wrapper">

                <?php
                    $bank_sql = "SELECT * FROM mldb.bank_table;";
                    $bank_result = mysqli_query($conn, $bank_sql);
                ?>

                <label for="bank">Bank</label>
                <select name="bank" id="bank" autocomplete="off" required>
                    <option value="">Select Bank</option>

                    <?php
                    while ($bank_row = mysqli_fetch_assoc($bank_result)) {
                        $selected = (isset($_POST['bank']) && $_POST['bank'] == $bank_row['bank_name']) ? 'selected' : '';
                    ?>

                    <option value="<?php echo $bank_row['bank_name']; ?>" <?php echo $selected; ?>>
                        <?php echo htmlspecialchars($bank_row['bank_name']); ?>
                    </option>

                    <?php
                    }
                    ?>

                </select>
                <div class="custom-arrow"></div>
            </div>
            <div class="custom-select-wrapper">
                <label for="from">From</label>
                <input type="date" id="from" name="from" value="<?php echo isset($_POST['from']) ? $_POST['from'] : '';?>">
            </div>
            <div class="custom-select-wrapper">
                <label for="to">To</label>
                <input type="date" id="to" name="to" value="<?php echo isset($_POST['to']) ? $_POST['to'] : '';?>">
            </div>
            <input type="submit" class="generate-btn" name="generate" value="Generate">

        </form>

        <div id="showdl" style="display: none">
            <form action="" method="post">
                <input type="hidden" id="cadNoForHidden" name="cad_no">
                <input type="hidden" id="rfpNoForHidden" name="rfp_no">
                <input type="submit" class="download-btn" name="download" value="Export to Excel">
            </form>
        </div>

    </div>

    <div id="showpost" style="display: none">
        <button onclick="postData()" class="post-btn">POST</button>
    </div>

    <!-- Modal for input RFP No. -->
    <div id="showInputFRPNo" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeInputRFPNo()">&times;</span>
            <input type="hidden" id="getBank" name="getBank" value="<?php echo isset($_POST['bank']) ? $_POST['bank'] : '';?>">
            <input type="hidden" id="getSettlementType" name="getSettlementType" value="<?php echo isset($_POST['settlement_type']) ? $_POST['settlement_type'] : '';?>">
            <input type="text" class="RFPInputField" id="rfpnoInput" name="rfpno" placeholder="Enter RFP No" required>
            <button onclick="submitRFPNo()" class="go-btn">GO</button>
        </div>
    </div>               

</body>

</html>

<?php

    if (isset($_POST['generate'])) {

        $settlement_type = $_POST['settlement_type'];
        $bank = $_POST['bank'];
        $from = $_POST['from'];
        $to = $_POST['to'];

        $_SESSION['settlement_type'] = $settlement_type;
        $_SESSION['bank'] = $bank;
        $_SESSION['from'] = $from;
        $_SESSION['to'] = $to;

        // Convert the dates to timestamps for comparison
        $from_date = strtotime($from);
        $to_date = strtotime($to);

        // Check if 'from' date is greater than 'to' date, and pop-up error message
        if ($from_date > $to_date) {
            echo "<script>
            window.onload = function() {
                Swal.fire({
                    title: 'Error!',
                    text: 'Invalid Date',
                    icon: 'error',
                    confirmButtonText: 'Ok'
                });
                }
            </script>";
            exit;
        }

        $sql = "SELECT bt.partner_id, pm.partner_name, pm.transaction_range, pm.charge_to, pm.serviceCharge, pm.partner_accName, MIN(bt.datetime) as from_datetime, MAX(bt.datetime) as to_datetime, COUNT(bt.id) AS total_count, SUM(bt.amount_paid) AS principal_amount, SUM(bt.charge_to_customer) AS total_charge_to_customer, 
                SUM(bt.charge_to_partner) AS total_charge_to_partner, GROUP_CONCAT(DISTINCT pb.bank_accNumber ORDER BY pb.id SEPARATOR ', ') AS all_bank_accNumbers, 
                GROUP_CONCAT(DISTINCT ct.charge_amount ORDER BY ct.id SEPARATOR ', ') AS all_charge_amount 
                FROM mldb.billspayment_transaction bt 
                INNER JOIN mldb.partner_masterfile pm ON bt.partner_id = pm.partner_id 
                INNER JOIN mldb.partner_bank pb ON pb.partner_id = pm.partner_id 
                INNER JOIN mldb.charge_table ct ON ct.partner_id = pm.partner_id
                WHERE pm.settled_online_check = '$settlement_type' 
                AND pb.bank = '$bank' 
                AND bt.datetime BETWEEN '$from 00:00:00' AND '$to 23:59:59'
                AND bt.settle_unsettle = 'UNSETTLED'
                AND bt.hold_status IS NULL
                GROUP BY bt.partner_id, pm.partner_name, pm.partner_accName, pm.charge_to, pb.bank_accNumber, pm.serviceCharge, pm.transaction_range
                ORDER BY pm.charge_to;";
        
        //echo $sql;
        $result = mysqli_query($conn, $sql);

        if (mysqli_num_rows($result) > 0) {

            $headerRow = mysqli_fetch_assoc($result);

            echo "<div class='table-container'>";
            echo "<table>";
            echo "<thead>";

            //  first row
            echo "<tr>";
            echo "<th colspan='9'>REQUEST FOR PAYMENT FORM</th>";
            echo "</tr>";
            // second row
            echo "<tr>";
            echo "<th colspan='2' class='left-th'>M. LHUILLIER PHILIPPINES, INC.</th>";
            echo "<th></th>";
            echo "<th></th>";
            echo "<th></th>";
            echo "<th></th>";
            echo "<th></th>";
            echo "<th></th>";

            // Set the time zone to Philippines time.
            date_default_timezone_set('Asia/Manila');
            $dateToday = date('F d, Y');

            echo "<th class='left-th'>DATE : $dateToday</th>";
            echo "</tr>";
            //third row
            echo "<tr>";
            echo "<th colspan='2' class='left-th'>BILLS PAYMENT SETTLEMENT</th>";
            echo "<th></th>";
            echo "<th></th>";
            echo "<th></th>";
            echo "<th></th>";
            echo "<th></th>";
            echo "<th></th>";
            echo "<th class='left-th'>CAD NO. : <span id='cadNoDisplay'></span></th>";
            echo "</tr>";
            // fourth row
            echo "<tr>";
            echo "<th colspan='2' class='left-th'>BANK NAME : $bank $settlement_type</th>";
            echo "<th></th>";
            echo "<th></th>";
            echo "<th></th>";
            echo "<th></th>";
            echo "<th></th>";
            echo "<th></th>";
            echo "<th class='left-th'>RFP NO. : <span id='rfpNoDisplay'></span></th>";
            echo "</tr>";
            //fifth row
            echo "<tr>";
            echo "<th colspan='2' class='left-th'>DATE OF TRANSACTION : " . (date('F d, Y', strtotime($headerRow['from_datetime']))) ." to ". (date('F d, Y', strtotime($headerRow['to_datetime']))). "</th>";
            echo "<th></th>";
            echo "<th></th>";
            echo "<th></th>";
            echo "<th></th>";
            echo "<th></th>";
            echo "<th></th>";
            echo "<th></th>";
            echo "</tr>";
            //sixth row
            echo "<tr>";
            echo "<th colspan='2' class='left-th'>MODE OF PAYMENT : </th>";
            echo "<th></th>";
            echo "<th></th>";
            echo "<th></th>";
            echo "<th></th>";
            echo "<th></th>";
            echo "<th></th>";
            echo "<th></th>";
            echo "</tr>";
             
            //seventh row
            echo "<tr>";
            echo "<th>LIST OF BILLS PAYMENT PARTNER</th>";
            echo "<th>ACCOUNT NAME</th>";
            echo "<th>ACCOUNT NUMBER</th>";
            echo "<th>TXN COUNT</th>";
            echo "<th>PRINCIPAL</th>";
            echo "<th>CHARGE TO CUSTOMER(KPX)</th>";
            echo "<th>CHARGE TO PARTNER(KPX)</th>";
            echo "<th>ADJUSTMENT (add/less)</th>";
            echo "<th>AMOUNT FOR SETTLEMENT</th>";
            echo "</tr>";

            echo "</thead>";
            echo "<tbody>";

            // Array to store messages
            $messages = [];

            function displayMessages($messages) {

                echo "<div class='table-container-error'>";
                echo "<table>";

                echo "<tr>";
                echo "<th>Status</th>";
                echo "<th>Partner Name</th>";
                echo "<th>Total TXN</th>";
                echo "<th>Total Charge to Partner</th>";
                echo "<th>Total Charge to Customer</th>";
                echo "<th>Message</th>";
                echo "</tr>";

                foreach ($messages as $msg) {
                    if ($msg['type'] === 'error') {
                        $class = $msg['type'] === 'success' ? 'success' : 'error';
                        echo "<tr class='$class'>

                            <td>" . ucfirst($msg['type']) . "</td>
                            <td>{$msg['partnerName']}</td>
                            <td>{$msg['totalTXN']}</td>
                            <td>{$msg['totalChargeToPartner']}</td>
                            <td>{$msg['totalChargeToCustomer']}</td>
                            <td>{$msg['message']}</td>";
                
                        echo "</tr>";
                    }
                }

                echo "</table>";
                echo "</div>";

            }

            $all_total_txn_count = 0;
            $all_total_principal_amount = 0;
            $all_total_charge_to_customer = 0;
            $all_total_charge_to_partner = 0;
            $get_total_charge_to_partner_daily = 0;
            $total_charge_to_partner_daily = 0;
            $calculate1 = 0;
            $calculate2 = 0;
            $previous_charge_to = null;
            
            // Reset result pointer to the first row
            mysqli_data_seek($result, 0);
            while ($row = mysqli_fetch_assoc($result)) {

                $all_total_txn_count = $all_total_txn_count + htmlspecialchars($row['total_count']);
                $all_total_principal_amount = $all_total_principal_amount + floatval($row['principal_amount']);
                $all_total_charge_to_customer = $all_total_charge_to_customer + floatval($row['total_charge_to_customer']);
                $all_total_charge_to_partner = $all_total_charge_to_partner + floatval($row['total_charge_to_partner']);

                if ($row['transaction_range'] === 'PER TRANSACTION' && $row['serviceCharge'] === 'DAILY' && $row['charge_to'] === 'PARTNER') {

                    $calculate1 = $row['total_charge_to_partner'] / $row['total_count'];

                    if ($calculate1 < $row['all_charge_amount']) {

                        $messages[] = [
                            'type' => 'error',
                            'partnerName' => $row['partner_name'],
                            'totalTXN' => $row['total_count'],
                            'totalChargeToPartner' => $row['total_charge_to_partner'],
                            'totalChargeToCustomer' => $row['total_charge_to_customer'],
                            'message' => "Charge/s(KPX) is less than to charge/s set to Partner"
                        ];

                    }else if ($calculate1 > $row['all_charge_amount']) {
                        $messages[] = [
                            'type' => 'error',
                            'partnerName' => $row['partner_name'],
                            'totalTXN' => $row['total_count'],
                            'totalChargeToPartner' => $row['total_charge_to_partner'],
                            'totalChargeToCustomer' => $row['total_charge_to_customer'],
                            'message' => "Charge/s(KPX) is greater than to charge/s set to Partner"
                        ];
                    }

                }elseif ($row['transaction_range'] === 'PER TRANSACTION' && $row['serviceCharge'] === 'DAILY' && $row['charge_to'] === 'CUSTOMER&PARTNER') {

                    $calculate1 = $row['total_charge_to_partner'] / $row['total_count'];
                    $calculate2 = $row['total_charge_to_customer'] / $row['total_count'];
                    
                    if ($calculate1 != $row['all_charge_amount'] || $calculate2 != $row['all_charge_amount']) {

                        $messages[] = [
                            'type' => 'error',
                            'partnerName' => $row['partner_name'],
                            'totalTXN' => $row['total_count'],
                            'totalChargeToPartner' => $row['total_charge_to_partner'],
                            'totalChargeToCustomer' => $row['total_charge_to_customer'],
                            'message' => "Charge/s(KPX) is not equal to charge/s set to Partner"
                        ];

                    }

                }

                // Display "Charge by" only if the charge type has changed
                if ($row['charge_to'] !== $previous_charge_to) {
                    echo "<tr><td><b> NOTE : CHARGE BY " . htmlspecialchars($row['charge_to']) . "</b></td></tr>";
                    $previous_charge_to = $row['charge_to'];
                }
 
                echo "<tr onclick='selectRow(this, " . json_encode($row['partner_id']) . ", " . json_encode($row['from_datetime']) . ", " . json_encode($row['to_datetime']) . ")'>";
                echo "<td class='left-td' style='white-space: nowrap;'>" . htmlspecialchars($row['partner_name']) . "</td>";
                echo "<td class='left-td' style='white-space: nowrap;'>" . htmlspecialchars($row['partner_accName']) . "</td>";
                echo "<td class='right-td'>" . htmlspecialchars($row['all_bank_accNumbers']) . "</td>";
                echo "<td class='right-td'>" . htmlspecialchars($row['total_count']) . "</td>";
                echo "<td class='right-td'>" . number_format($row['principal_amount'], 2) . "</td>";
                echo "<td class='right-td'>" . htmlspecialchars($row['total_charge_to_customer']) . "</td>";
                echo "<td class='right-td'>" . htmlspecialchars($row['total_charge_to_partner']) . "</td>";
                echo "<td></td>";
                if ($row['charge_to'] === 'PARTNER' && $row['serviceCharge'] === 'DAILY') {
                    $get_total_charge_to_partner_daily = $row['principal_amount'] - $row['total_charge_to_partner'];
                    $total_charge_to_partner_daily = $get_total_charge_to_partner_daily + $total_charge_to_partner_daily;
                    echo "<td class='right-td'>" . number_format($row['principal_amount'] - $row['total_charge_to_partner'], 2) . "</td>";
                }else{
                    $total_charge_to_partner_daily = $total_charge_to_partner_daily + $row['principal_amount'];
                    echo "<td class='right-td'>" . number_format($row['principal_amount'], 2) . "</td>";
                }
                echo "</tr>";
            }
                echo "<tr>";
                echo "<td></td>";
                echo "<td></td>";
                echo "<td></td>";
                echo "<td class='right-td'><b>" . $all_total_txn_count . "</b></td>";
                echo "<td class='right-td'><b>" . number_format($all_total_principal_amount, 2) . "</b></td>";
                echo "<td class='right-td'><b>" . number_format($all_total_charge_to_customer, 2) . "</b></td>";
                echo "<td class='right-td'><b>" . number_format($all_total_charge_to_partner, 2) . "</b></td>";
                echo "<td></td>";
                echo "<td class='right-td'><b>" . number_format($total_charge_to_partner_daily, 2) . "</b></td>";
                echo "</tr>";

            echo "</tbody>";
            echo "</table>";
            echo "</div>";
            
            if (!empty($messages)) {

                echo "<script>
                        window.onload = function() {
                            Swal.fire({
                                title: 'Warning!',
                                text: 'Check the Log message/s',
                                icon: 'warning',
                                confirmButtonText: 'Ok'
                            });
                        }
                    </script>";
                echo "<div class='holdBtn'>
                        <button class='hold-btn' onclick='holdPartner()'>Hold Partner</button>
                    </div>";
                displayMessages($messages);
                
            }else{
                echo "<div class='processAndHold' id='processAndHold'>
                        <button class='process-btn' onclick='inputRFPNo()'>Process</button>
                        <button class='hold-btn' onclick='holdPartner()'>Hold Partner</button>
                    </div>";
            }

        } else {
            echo "No results found.";
        }

    }
?>

<script>

    function inputRFPNo() {
        const inputRFPNo = document.getElementById("showInputFRPNo");
        inputRFPNo.style.display = 'block';
    }

    function closeInputRFPNo() {
        const closeRFPNo = document.getElementById("showInputFRPNo");
        closeRFPNo.style.display = 'none';
    }
    
    function submitRFPNo() {
        const rfpno = document.getElementById('rfpnoInput').value; // Get the input value
        const bank = document.getElementById('getBank').value;
        const settlementType = document.getElementById('getSettlementType').value;
        const postButton = document.getElementById('showpost');

        if (!rfpno) {
            Swal.fire({
                title: 'Error!',
                text: 'Please Input RFP No.',
                icon: 'error',
                confirmButtonText: 'Ok'
            });
            return;
        }

        // AJAX request to send the data to the server
        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'process_rfp.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

        xhr.onreadystatechange = function () {
            if (xhr.readyState === 4 && xhr.status === 200) {
                // Response from the server
                const response = JSON.parse(xhr.responseText);

                if (response.success) {
                    // Update the display for RFP and CAD No
                    document.getElementById('rfpNoDisplay').innerText = response.rfpno;
                    document.getElementById('cadNoDisplay').innerText = response.cadno;

                    // Update the hidden fields for the download 
                    document.getElementById('rfpNoForHidden').value = response.rfpno;
                    document.getElementById('cadNoForHidden').value = response.cadno; // Use .value here for hidden input

                    // Close the modal/input form
                    closeInputRFPNo();

                    postButton.style.display = 'flex';
                }
            }
        };

        // Send the RFP NO, bank, and settlementType to the server
        const data = 'rfpno=' + encodeURIComponent(rfpno) + 
                    '&bank=' + encodeURIComponent(bank) + 
                    '&settlementType=' + encodeURIComponent(settlementType);

        xhr.send(data);
    }
    
    let selectedPartnerId = null;
    let selectedFromDate = null;
    let selectedToDate = null;

    function selectRow(row, partnerId, fromDate, toDate) {
        var selectedRow = document.querySelector('.selected');

        if (selectedRow) {
            selectedRow.classList.remove('selected');
            selectedRow.style.backgroundColor = '';
            selectedRow.style.color = '';
        }

        if (selectedRow !== row) {
            row.classList.add('selected');
            row.style.backgroundColor = 'red';
            row.style.color = 'white';

            // Set the selected partner ID and date range
            selectedPartnerId = partnerId;
            selectedFromDate = fromDate;
            selectedToDate = toDate;
        } else {
            // Reset if deselected
            selectedPartnerId = null;
            selectedFromDate = null;
            selectedToDate = null;
        }
    }

    function holdPartner() {
        const bank = document.getElementById('bank').value;
        const settlementType = document.getElementById('settlement_type').value;
        const fromDate = document.getElementById('from').value;
        const toDate = document.getElementById('to').value;

        if (selectedPartnerId && selectedFromDate && selectedToDate) {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', 'hold_partner_status.php', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

            xhr.onload = function () {
                if (xhr.status === 200) {
                    const response = JSON.parse(xhr.responseText);
                    if (response.success) {

                        alert('Partner held successfully.');

                        localStorage.setItem('bank', bank);
                        localStorage.setItem('settlement_type', settlementType);
                        localStorage.setItem('from', fromDate);
                        localStorage.setItem('to', toDate);
                        
                        const selectedRow = document.querySelector('.selected');
                        if (selectedRow) {
                            selectedRow.remove(); // Remove the selected row
                        }

                        location.reload(); // Refresh the page to reflect changes
                    } else {
                        alert('Failed to hold partner: ' + response.message);
                    }
                } else {
                    alert('An error occurred while trying to hold the partner.');
                }
            };

            xhr.send(
                'partner_id=' + encodeURIComponent(selectedPartnerId) +
                '&from_date=' + encodeURIComponent(selectedFromDate) +
                '&to_date=' + encodeURIComponent(selectedToDate)
            );
        } else {
            alert('Please select a partner row first.');
        }
    }

    function postData() {
        const bank = document.getElementById('bank').value;
        const settlementType = document.getElementById('settlement_type').value;
        const fromDate = document.getElementById('from').value;
        const toDate = document.getElementById('to').value;
        const rfpNo = document.getElementById('rfpNoForHidden').value;
        const cadNo = document.getElementById('cadNoForHidden').value;
        const downloadButton = document.getElementById('showdl');
        const postButton = document.getElementById('showpost');
        const processAndHoldButton = document.getElementById('processAndHold');

        // AJAX request to send the data to the server
        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'post_data.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

        xhr.onreadystatechange = function () {
            if (xhr.readyState === 4 && xhr.status === 200) {
                // Response from the server
                const response = JSON.parse(xhr.responseText);

                if (response.success) {
                    Swal.fire({
                        title: 'Success!',
                        text: 'Successfully Posted',
                        icon: 'success',
                        confirmButtonText: 'Ok'
                    });

                    downloadButton.style.display = 'inline-block';
                    postButton.style.display = 'none';
                    processAndHoldButton.style.display = 'none';
                } else if (response.isError) {
                    Swal.fire({
                        title: 'Error!',
                        text: response.error,
                        icon: 'error',
                        confirmButtonText: 'Ok'
                    });
                }
            }
        };

        // Send the RFP NO, bank, and settlementType to the server
        const data = 'bank=' + encodeURIComponent(bank) + 
                    '&settlement_type=' + encodeURIComponent(settlementType) +
                    '&from_date=' + encodeURIComponent(fromDate) +
                    '&to_date=' + encodeURIComponent(toDate) +
                    '&cad_no=' + encodeURIComponent(cadNo) +
                    '&rfp_no=' + encodeURIComponent(rfpNo); 

        xhr.send(data);
    }

    document.addEventListener('DOMContentLoaded', function () {
        // Restore values from local storage
        if (localStorage.getItem('bank')) {
            document.getElementById('bank').value = localStorage.getItem('bank');
        }
        if (localStorage.getItem('settlement_type')) {
            document.getElementById('settlement_type').value = localStorage.getItem('settlement_type');
        }
        if (localStorage.getItem('from')) {
            document.getElementById('from').value = localStorage.getItem('from');
        }
        if (localStorage.getItem('to')) {
            document.getElementById('to').value = localStorage.getItem('to');
        }
    });
</script>