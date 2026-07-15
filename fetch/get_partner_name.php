<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/config.php';


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Support both JSON and form data
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Try POST form data first, fall back to JSON
    $partnerID = isset($_POST['partner_id']) ? $_POST['partner_id'] : 
                 (isset($input['partnerID']) ? $input['partnerID'] : '');
    $partnerID_kpx = isset($_POST['partner_id_kpx']) ? $_POST['partner_id_kpx'] :
                     (isset($input['partnerID_kpx']) ? $input['partnerID_kpx'] : '');
    $partnerName = isset($_POST['partner_name']) ? trim($_POST['partner_name']) :
                   (isset($input['partner_name']) ? trim($input['partner_name']) : '');
    $filterPartnerIDKpx = isset($_POST['filter_partner_id_kpx']) ? trim($_POST['filter_partner_id_kpx']) :
                         (isset($input['filter_partner_id_kpx']) ? trim($input['filter_partner_id_kpx']) : '');
    
    $response = ['success' => false, 'partner_name' => null];
    
    if ($partnerName !== '') {
        // Exact partner name lookup, optionally constrained by KPX partner id.
        if ($filterPartnerIDKpx !== '') {
            $sql = "SELECT partner_name FROM masterdata.partner_masterfile WHERE partner_name = ? AND partner_id_kpx = ? LIMIT 1";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ss", $partnerName, $filterPartnerIDKpx);
        } else {
            $sql = "SELECT partner_name FROM masterdata.partner_masterfile WHERE partner_name = ? LIMIT 1";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("s", $partnerName);
        }

        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $response['success'] = true;
            $response['partner_name'] = $row['partner_name'];
        }
    } elseif ($partnerID !== '' && $partnerID !== 'All') {
        // Try to find by partner_id or partner_id_kpx
        $sql = "SELECT partner_name FROM masterdata.partner_masterfile 
                WHERE partner_id = ? OR partner_id_kpx = ? LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ss", $partnerID, $partnerID);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $response['success'] = true;
            $response['partner_name'] = $row['partner_name'];
        }
    } elseif ($partnerID_kpx !== '' && $partnerID_kpx !== 'All') {
        $sql = "SELECT partner_name FROM masterdata.partner_masterfile WHERE partner_id_kpx = ? LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $partnerID_kpx);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $response['success'] = true;
            $response['partner_name'] = $row['partner_name'];
        }
    } elseif ($partnerID === 'All' || $partnerID_kpx === 'All') {
        $response['success'] = true;
        $response['partner_name'] = 'All';
    }
    
    echo json_encode($response);
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
}
?>