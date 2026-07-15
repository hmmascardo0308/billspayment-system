<?php

header('Content-Type: application/json');
require_once __DIR__ . '/../config/config.php';


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $branchId = isset($_POST['branch_id']) ? trim($_POST['branch_id']) : (isset($input['branch_id']) ? trim($input['branch_id']) : '');

    $response = ['success' => false, 'branch_name' => null];
    if ($branchId !== '') {
        $sql = "SELECT branch_name FROM masterdata.branch_profile WHERE branch_id = ? LIMIT 1";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('s', $branchId);
            if ($stmt->execute()) {
                $res = $stmt->get_result();
                if ($res && $res->num_rows > 0) {
                    $row = $res->fetch_assoc();
                    $response['success'] = true;
                    $response['branch_name'] = $row['branch_name'];
                }
            }
            $stmt->close();
        }
    }

    echo json_encode($response);
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
}

?>
