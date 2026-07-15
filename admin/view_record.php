<?php

    require_once __DIR__ . '/../config/config.php';

    if (isset($_POST['settlement_type'], $_POST['bank'], $_POST['cad_no'], $_POST['rfp_no'])) {
        $settlement_type = urldecode($_POST['settlement_type']);  
        $bank = urldecode($_POST['bank']);
        $cad_no = urldecode($_POST['cad_no']);
        $rfp_no = urldecode($_POST['rfp_no']);
        
        // Query the database based on the received data
        $query = "SELECT bt.cad_no, bt.rfp_no, MIN(bt.datetime) as from_datetime, MAX(bt.datetime) as to_datetime, pm.partner_name, pm.transaction_range, pm.charge_to, pm.serviceCharge, pm.partner_accName, COUNT(bt.id) AS total_count, SUM(bt.amount_paid) AS principal_amount, SUM(bt.charge_to_customer) AS total_charge_to_customer, 
                SUM(bt.charge_to_partner) AS total_charge_to_partner, GROUP_CONCAT(DISTINCT pb.bank_accNumber ORDER BY pb.id SEPARATOR ', ') AS all_bank_accNumbers, 
                GROUP_CONCAT(DISTINCT ct.charge_amount ORDER BY ct.id SEPARATOR ', ') AS all_charge_amount 
                FROM mldb.billspayment_transaction bt 
                INNER JOIN mldb.partner_masterfile pm ON bt.partner_id = pm.partner_id 
                INNER JOIN mldb.partner_bank pb ON pb.partner_id = pm.partner_id 
                INNER JOIN mldb.charge_table ct ON ct.partner_id = pm.partner_id
                WHERE pm.settled_online_check = '$settlement_type' 
                AND pb.bank = '$bank'
                AND bt.cad_no = '$cad_no'
                AND bt.rfp_no = '$rfp_no'
                GROUP BY bt.cad_no, bt.rfp_no, pm.partner_name, pm.transaction_range, pm.charge_to, pm.serviceCharge, pm.partner_accName
                ORDER By pm.charge_to;";
        // echo $query;
        $result = mysqli_query($conn, $query);
        
        if (mysqli_num_rows($result) > 0) {
            
            $headerRow = mysqli_fetch_assoc($result);

            echo "<div class='table-container'>";
            echo "<table>";
            echo "<thead>";
        
            echo "<tr><th colspan='9'>REQUEST FOR PAYMENT FORM</th></tr>";
            echo "<tr><th colspan='2' class='left-th'>M. LHUILLIER PHILIPPINES, INC.</th><th></th><th></th><th></th><th></th><th></th><th></th><th class='left-th'>DATE: " . date('F d, Y') . "</th></tr>";
            echo "<tr><th colspan='2' class='left-th'>BILLS PAYMENT SETTLEMENT</th><th></th><th></th><th></th><th></th><th></th><th></th><th class='left-th'>CAD NO. : " . $headerRow['cad_no'] . "</th></tr>";
            echo "<tr><th colspan='2' class='left-th'>BANK NAME: " . $bank . " " . $settlement_type . "</th><th></th><th></th><th></th><th></th><th></th><th></th><th class='left-th'>RFP NO. : " . $headerRow['rfp_no'] . "</th></tr>";
            echo "<tr><th colspan='2' class='left-th'>DATE OF TRANSACTION : " . date('F d, Y', strtotime($headerRow['from_datetime'])) . " to " . date('F d, Y', strtotime($headerRow['to_datetime'])) . "</th><th></th><th></th><th></th><th></th><th></th><th></th><th></th></tr>";
            echo "<tr><th colspan='2' class='left-th'>MODE OF PAYMENT : </th><th></th><th></th><th></th><th></th><th></th><th></th><th></th></tr>";
            echo "<tr><th>LIST OF BILLS PAYMENT PARTNER</th><th>ACCOUNT NAME</th><th>ACCOUNT NUMBER</th><th>TXN COUNT</th><th>PRINCIPAL</th><th>CHARGE TO CUSTOMER(KPX)</th><th>CHARGE TO PARTNER(KPX)</th><th>ADJUSTMENT (add/less)</th><th>AMOUNT FOR SETTLEMENT</th></tr>";
        
            echo "</thead><tbody>";

            $total_principal_amount = 0;
            $total_charge_to_customer = 0;
            $total_charge_to_partner = 0;
            $total_amount_for_settlement = 0;
            $previous_charge_to = null;

            // Reset result pointer to the first row
            mysqli_data_seek($result, 0);
            while ($row = mysqli_fetch_assoc($result)) {

                $total_principal_amount += $row['principal_amount'];
                $total_charge_to_customer += $row['total_charge_to_customer'];
                $total_charge_to_partner += $row['total_charge_to_partner'];

                $settlement_amount = ($row['charge_to'] === 'PARTNER' && $row['serviceCharge'] === 'DAILY') ? 
                ($row['principal_amount'] - $row['total_charge_to_partner']) : 
                $row['principal_amount'];

                $total_amount_for_settlement += $settlement_amount;

                // Display "Charge by" only if the charge type has changed
                if ($row['charge_to'] !== $previous_charge_to) {
                    echo "<tr><td><b> NOTE : CHARGE BY " . htmlspecialchars($row['charge_to']) . "</b></td></tr>";
                    $previous_charge_to = $row['charge_to'];
                }

                echo "<tr>";
                echo "<td class='left-td'>" . htmlspecialchars($row['partner_name']) . "</td>";
                echo "<td class='left-td'>" . htmlspecialchars($row['partner_accName']) . "</td>";
                echo "<td class='right-td'>" . htmlspecialchars($row['all_bank_accNumbers']) . "</td>";
                echo "<td class='right-td'>" . htmlspecialchars($row['total_count']) . "</td>";
                echo "<td class='right-td'>" . number_format($row['principal_amount'], 2) . "</td>";
                echo "<td class='right-td'>" . htmlspecialchars($row['total_charge_to_customer']) . "</td>";
                echo "<td class='right-td'>" . htmlspecialchars($row['total_charge_to_partner']) . "</td>";
                echo "<td></td>";
                echo "<td class='right-td'>" . number_format($settlement_amount, 2) . "</td>";
                echo "</tr>";
            }

            echo "<tr>";
            echo "<td colspan='4' class='right-td'><b>Total</b></td>";
            echo "<td class='right-td'><b>" . number_format($total_principal_amount, 2) . "</b></td>";
            echo "<td class='right-td'><b>" . number_format($total_charge_to_customer, 2) . "</b></td>";
            echo "<td class='right-td'><b>" . number_format($total_charge_to_partner, 2) . "</b></td>";
            echo "<td></td>";
            echo "<td class='right-td'><b>" . number_format($total_amount_for_settlement, 2) . "</b></td>";
            echo "</tr>";

            echo "</tbody></table>";

        } else {
            echo "No records found!";
            //echo $query;
        }
    }

?>