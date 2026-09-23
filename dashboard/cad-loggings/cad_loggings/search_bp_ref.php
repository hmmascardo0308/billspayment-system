<?php
// search_bp_ref.php
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../vendor/autoload.php';

session_start();
@include_once __DIR__ . '/../../../templates/middleware.php';
$id = resolve_user_identifier();
if (empty($id)) { http_response_code(401); echo json_encode(['results' => []]); exit; }
if (!function_exists('has_any_permission') || !has_any_permission(['CAD Loggings','VPO'])) {
    http_response_code(403); echo json_encode(['results' => []]); exit;
}

header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');
if ($q === '' || strlen($q) < 2) {
    echo json_encode(['results' => []]);
    exit;
}

$results = [];
try {
    // Prefix search only – can use index_reference_no
    // Removed account_no / account_name search (they forced full table scan)
    $sql = "SELECT bt.reference_no
            FROM mldb.billspayment_transaction bt
            WHERE bt.reference_no LIKE ?
            GROUP BY bt.reference_no
            ORDER BY bt.reference_no DESC
            LIMIT 20";

    if ($stmt = $conn->prepare($sql)) {
        $like = $q . '%';               // ← no leading %
        $stmt->bind_param('s', $like);
        $stmt->execute();
        $res = $stmt->get_result();

        while ($row = $res->fetch_assoc()) {
            $results[] = [
                'id'   => $row['reference_no'],
                'text' => $row['reference_no'],
            ];
        }
        $stmt->close();
    }
} catch (Exception $e) {
    error_log('search_bp_ref error: ' . $e->getMessage());
}

echo json_encode(['results' => $results]);