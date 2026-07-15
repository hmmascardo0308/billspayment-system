<?php 

    require_once __DIR__ . '/../config/config.php';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $bank = $_POST['bank'] ?? '';
        $settlementType = $_POST['settlement_type'] ?? '';
        $from = $_POST['from_date'] ?? '';
        $to = $_POST['to_date'] ?? '';
        $cadNo = $_POST['cad_no'] ?? '';
        $rfpNo = $_POST['rfp_no'] ?? '';
    
        if (!empty($bank) && !empty($settlementType) && !empty($from) && !empty($to)) {

            $rfpNo = $conn->real_escape_string($rfpNo);
            $cadNo = $conn->real_escape_string($cadNo);
            
            $updateSql = "UPDATE mldb.bank_table SET series_number = series_number + 1 WHERE bank_name = '$bank';";
            $updateSql1 = "UPDATE mldb.billspayment_transaction bt
                        INNER JOIN mldb.partner_masterfile pm ON bt.partner_id = pm.partner_id
                        INNER JOIN mldb.partner_bank pb ON pb.partner_id = pm.partner_id
                        INNER JOIN mldb.charge_table ct ON ct.partner_id = pm.partner_id
                        SET bt.settle_unsettle = 'SETTLED',
                            bt.rfp_no = '$rfpNo',
                            bt.cad_no = '$cadNo'
                        WHERE pm.settled_online_check = '$settlementType'
                        AND pb.bank = '$bank'
                        AND bt.settle_unsettle = 'UNSETTLED'
                        AND bt.datetime BETWEEN '$from 00:00:00' AND '$to 23:59:59';";

            if (mysqli_query($conn, $updateSql) && mysqli_query($conn, $updateSql1)) {
                // Send a JSON response back
                echo json_encode([
                    'success' => true,
                ]);
            }else{
                // Send a JSON response back on failure with the error message
                echo json_encode([
                    'isError' => true,
                    'error' => mysqli_error($conn),
                ]);
            }
            
            
        } else {
            // Return an error if validation fails
            echo json_encode([
                'success' => false,
                'message' => 'Required fields are missing',
            ]);
        }
    }

?>