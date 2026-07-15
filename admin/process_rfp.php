<?php

    require_once __DIR__ . '/../config/config.php';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $rfpno = $_POST['rfpno'] ?? '';
        $bank = $_POST['bank'] ?? '';
        $settlementType = $_POST['settlementType'] ?? '';
        $date = date('Y-m');

        //fetch bank series number
        $seriesNumberSql = "SELECT * FROM mldb.bank_table bt WHERE bt.bank_name = '$bank';";
        $seriesNumberResult = mysqli_query($conn, $seriesNumberSql);
        $seriesNumber = 0;
        while ($seriesNumberRow = mysqli_fetch_assoc($seriesNumberResult)) {
            $seriesNumber = $seriesNumberRow['series_number'];
        }

        $seriesNumberFormatted = sprintf('%05d', $seriesNumber);
        $cadNo = "$bank-$settlementType-$date-$seriesNumberFormatted";
    
        // Validate the inputs and process accordingly
        if (!empty($rfpno) && !empty($bank) && !empty($settlementType)) {
            // Process the data (e.g., save to database, etc.)
            
            // Send a JSON response back
            echo json_encode([
                'success' => true,
                'rfpno' => $rfpno,
                'cadno' => $cadNo
            ]);
        } else {
            // Return an error if validation fails
            echo json_encode([
                'success' => false,
                'message' => 'Required fields are missing.'
            ]);
        }
    }

?>