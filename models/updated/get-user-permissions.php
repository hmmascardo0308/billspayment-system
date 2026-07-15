<?php
include '../../config/config.php';

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

try {
    $idNumber = isset($_REQUEST['id_number']) ? trim((string)$_REQUEST['id_number']) : '';
    if ($idNumber === '') {
        echo json_encode(['success' => false, 'message' => 'id_number is required']);
        exit;
    }

    // First prefer database-stored per-user permissions (JSON in `permissions` column)
    $perms = [];
    $stmt = mysqli_prepare($conn, "SELECT permissions FROM mldb.user_form WHERE id_number = ? LIMIT 1");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 's', $idNumber);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        if ($res) {
            $row = mysqli_fetch_assoc($res);
            if ($row && isset($row['permissions']) && is_string($row['permissions']) && trim($row['permissions']) !== '') {
                $decoded = json_decode($row['permissions'], true);
                if (is_array($decoded)) {
                    $perms = $decoded;
                }
            }
        }
        mysqli_stmt_close($stmt);
    }

    // fallback to legacy file-based store
    if (empty($perms)) {
        $userPermPath = __DIR__ . '/../../assets/js/user-permissions.json';
        $userPermData = [];
        if (file_exists($userPermPath)) {
            $raw = @file_get_contents($userPermPath);
            $dec = json_decode($raw, true);
            if (is_array($dec)) $userPermData = $dec;
        }

        if (isset($userPermData[$idNumber]) && is_array($userPermData[$idNumber])) {
            $perms = $userPermData[$idNumber];
        }
    }

    echo json_encode(['success' => true, 'id_number' => $idNumber, 'permissions' => $perms]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}

mysqli_close($conn);
