<?php
// Include database connection
require_once __DIR__ . '/../config/config.php';


header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle both JSON input and form POST data
    $partnerName = '';
    if (isset($_POST['partner_name'])) {
        $partnerName = $_POST['partner_name'];
    } else {
        $input = json_decode(file_get_contents('php://input'), true);
        $partnerName = $input['partnerName'] ?? '';
    }
    
    if (!empty($partnerName) && $partnerName !== 'All') {
        $stmt = $conn->prepare("SELECT partner_id, partner_id_kpx FROM masterdata.partner_masterfile WHERE partner_name = ? LIMIT 1");
        $stmt->bind_param("s", $partnerName);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            echo json_encode([
                'success' => true,
                'partner_id' => $row['partner_id'],
                'partner_id_kpx' => $row['partner_id_kpx']
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'partner_id' => null,
                'partner_id_kpx' => null
            ]);
        }
        $stmt->close();
    } else {
        echo json_encode([
            'success' => false,
            'partner_id' => null,
            'partner_id_kpx' => null
        ]);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
}
?>