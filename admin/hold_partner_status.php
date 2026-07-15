<?php
    require_once __DIR__ . '/../config/config.php';

    if (isset($_POST['partner_id']) && isset($_POST['from_date']) && isset($_POST['to_date'])) {

        $partner_id = $_POST['partner_id'];
        $from_date = $_POST['from_date']; // Expected to be in full datetime format already
        $to_date = $_POST['to_date']; // Expected to be in full datetime format already

        // Prepare the SQL statement
        $sql = "UPDATE mldb.billspayment_transaction bt
                INNER JOIN mldb.partner_masterfile pm ON bt.partner_id = pm.partner_id
                INNER JOIN mldb.partner_bank pb ON pb.partner_id = pm.partner_id
                INNER JOIN mldb.charge_table ct ON ct.partner_id = pm.partner_id
                SET bt.hold_status = 'hold'
                WHERE bt.partner_id = ?
                AND bt.datetime BETWEEN ? AND ?";

        // Prepare the statement
        $stmt = $conn->prepare($sql);

        // Bind parameters
        $stmt->bind_param("sss", $partner_id, $from_date, $to_date);

        // Execute the statement
        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update the partner status: ' . $conn->error]);
        }

        // Close the statement
        $stmt->close();
    } else {
        echo json_encode(['success' => false, 'message' => 'No partner ID received.']);
    }

?>