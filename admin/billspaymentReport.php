<?php

session_start();

if (!isset($_SESSION['admin_name'])) {
    header('location:../login_form.php');
}

require_once __DIR__ . '/../config/config.php';

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
   <link rel="icon" href="../assets/images/MLW logo.png" type="image/png">
   <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
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

        $sql = "SELECT DISTINCT bt.cad_no, bt.rfp_no FROM mldb.billspayment_transaction bt 
                INNER JOIN mldb.partner_masterfile pm ON bt.partner_id = pm.partner_id 
                INNER JOIN mldb.partner_bank pb ON pb.partner_id = pm.partner_id 
                INNER JOIN mldb.charge_table ct ON ct.partner_id = pm.partner_id
                WHERE pm.settled_online_check = '$settlement_type' 
                AND pb.bank = '$bank'
                AND bt.datetime BETWEEN '$from 00:00:00' AND '$to 23:59:59'
                AND bt.settle_unsettle = 'SETTLED'";

        // echo $sql;
        $result = mysqli_query($conn, $sql);

        if (mysqli_num_rows($result) > 0) {
            
            echo "<div class='table-container-showcadno'>";
            echo "<table>";
            echo "<thead>";

            echo "<tr>";
            echo "<th>CAD No.</th>";
            echo "<th>RFP No.</th>";
            echo "<th>Action</th>";
            echo "</tr>";

            echo "</thead>";
            echo "<tbody>";
 
            while ($row = mysqli_fetch_assoc($result)) {
                echo "<tr>";
                echo "<td style='display: none;'>". $settlement_type ."</td>";
                echo "<td style='display: none;'>". $bank ."</td>";
                echo "<td>". $row['cad_no'] ."</td>";
                echo "<td>". $row['rfp_no'] ."</td>";
                echo "<td class='td-icon-btn'>
                    <button class='icon-btn' 
                            onclick='exportToExcel(this)' 
                            data-settlement-type='$settlement_type' 
                            data-bank='$bank' 
                            data-from-date='$from'
                            data-to-date='$to'
                            data-cad-no='". $row['cad_no'] ."' 
                            data-rfp-no='". $row['rfp_no'] ."'>
                        <i class='fa-solid fa-download'></i> Export to excel
                    </button>
                    <button class='icon-btn' 
                            onclick='exportToPdf(this)' 
                            data-settlement-type='$settlement_type' 
                            data-bank='$bank' 
                            data-from-date='$from'
                            data-to-date='$to'
                            data-cad-no='". $row['cad_no'] ."' 
                            data-rfp-no='". $row['rfp_no'] ."'>
                        <i class='fa-solid fa-download'></i> Export to PDF
                    </button>
                    <button class='icon-btn' 
                            onclick='viewData(this)' 
                            data-settlement-type='$settlement_type' 
                            data-bank='$bank' 
                            data-cad-no='". $row['cad_no'] ."' 
                            data-rfp-no='". $row['rfp_no'] ."'>
                        <i class='fa-solid fa-eye'></i> View
                    </button>
                </td>";
                echo "</tr>";
            }

            echo "</tbody>";
            echo "</table>";
            echo "</div>";

            echo "<div id='view-record-container'></div>";

        }else{
            echo "No result found";
        }        
        
    }
?>

<script>
    
    function exportToPdf(button) {

        const settlementType = encodeURIComponent(button.getAttribute('data-settlement-type'));
        const bank = encodeURIComponent(button.getAttribute('data-bank'));
        const fromDate = encodeURIComponent(button.getAttribute('data-from-date'));
        const toDate = encodeURIComponent(button.getAttribute('data-to-date'));
        const cadNo = encodeURIComponent(button.getAttribute('data-cad-no'));
        const rfpNo = encodeURIComponent(button.getAttribute('data-rfp-no'));

        // Create a form dynamically
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'export_to_pdf.php';
        // form.target = '_blank'; // Open the PDF in a new tab or window

        // Create hidden input fields for each parameter
        const settlementTypeInput = document.createElement('input');
        settlementTypeInput.type = 'hidden';
        settlementTypeInput.name = 'settlement_type';
        settlementTypeInput.value = decodeURIComponent(settlementType);

        const bankInput = document.createElement('input');
        bankInput.type = 'hidden';
        bankInput.name = 'bank';
        bankInput.value = decodeURIComponent(bank);

        const fromDateInput = document.createElement('input');
        fromDateInput.type = 'hidden';
        fromDateInput.name = 'from_date';
        fromDateInput.value = decodeURIComponent(fromDate);

        const toDateInput = document.createElement('input');
        toDateInput.type = 'hidden';
        toDateInput.name = 'to_date';
        toDateInput.value = decodeURIComponent(toDate);

        const cadNoInput = document.createElement('input');
        cadNoInput.type = 'hidden';
        cadNoInput.name = 'cad_no';
        cadNoInput.value = decodeURIComponent(cadNo);

        const rfpNoInput = document.createElement('input');
        rfpNoInput.type = 'hidden';
        rfpNoInput.name = 'rfp_no';
        rfpNoInput.value = decodeURIComponent(rfpNo);

        // Append inputs to the form
        form.appendChild(settlementTypeInput);
        form.appendChild(bankInput);
        form.appendChild(fromDateInput);
        form.appendChild(toDateInput);
        form.appendChild(cadNoInput);
        form.appendChild(rfpNoInput);

        // Append the form to the body and submit it
        document.body.appendChild(form);
        form.submit();

        // Remove the form after submission
        document.body.removeChild(form);
    }
    function exportToExcel(button) {

        const settlementType = encodeURIComponent(button.getAttribute('data-settlement-type'));
        const bank = encodeURIComponent(button.getAttribute('data-bank'));
        const fromDate = encodeURIComponent(button.getAttribute('data-from-date'));
        const toDate = encodeURIComponent(button.getAttribute('data-to-date'));
        const cadNo = encodeURIComponent(button.getAttribute('data-cad-no'));
        const rfpNo = encodeURIComponent(button.getAttribute('data-rfp-no'));

        // Create a new form element dynamically
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'export_to_excel.php';
        // form.target = '_blank'; // Open the download in a new tab or window

        // Create hidden input fields for each parameter
        const settlementTypeInput = document.createElement('input');
        settlementTypeInput.type = 'hidden';
        settlementTypeInput.name = 'settlement_type';
        settlementTypeInput.value = decodeURIComponent(settlementType);

        const bankInput = document.createElement('input');
        bankInput.type = 'hidden';
        bankInput.name = 'bank';
        bankInput.value = decodeURIComponent(bank);

        const cadNoInput = document.createElement('input');
        cadNoInput.type = 'hidden';
        cadNoInput.name = 'cad_no';
        cadNoInput.value = decodeURIComponent(cadNo);

        const rfpNoInput = document.createElement('input');
        rfpNoInput.type = 'hidden';
        rfpNoInput.name = 'rfp_no';
        rfpNoInput.value = decodeURIComponent(rfpNo);

        const fromDateInput = document.createElement('input');
        fromDateInput.type = 'hidden';
        fromDateInput.name = 'from';
        fromDateInput.value = decodeURIComponent(fromDate);

        const toDateInput = document.createElement('input');
        toDateInput.type = 'hidden';
        toDateInput.name = 'to';
        toDateInput.value = decodeURIComponent(toDate);

        // Append inputs to the form
        form.appendChild(settlementTypeInput);
        form.appendChild(bankInput);
        form.appendChild(cadNoInput);
        form.appendChild(rfpNoInput);
        form.appendChild(fromDateInput);
        form.appendChild(toDateInput);

        // Append the form to the body and submit it
        document.body.appendChild(form);
        form.submit();

        // Remove the form after submission
        document.body.removeChild(form);
    }

    function viewData(button) {
        
        const settlementType = encodeURIComponent(button.getAttribute('data-settlement-type'));
        const bank = encodeURIComponent(button.getAttribute('data-bank'));
        const cadNo = encodeURIComponent(button.getAttribute('data-cad-no'));
        const rfpNo = encodeURIComponent(button.getAttribute('data-rfp-no'));
        
        // Create an AJAX request to send the data to PHP
        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'view_record.php', true);
        xhr.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');
        
        // Send the encoded data
        xhr.send('settlement_type=' + settlementType + 
                '&bank=' + bank + 
                '&cad_no=' + cadNo + 
                '&rfp_no=' + rfpNo);

        // Handle the response from PHP
        xhr.onload = function() {
            if (this.status === 200) {
                // Update the table or any part of the page with the response
                document.getElementById('view-record-container').innerHTML = this.responseText;
            }
        };
    }

</script>