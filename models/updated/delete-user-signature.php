<?php
include '../../config/config.php';
session_start();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success'=>false,'message'=>'Invalid request method']);
    exit;
}

$id = isset($_POST['id_number']) ? trim($_POST['id_number']) : null;
if (empty($id)) {
    echo json_encode(['success'=>false,'message'=>'Missing id_number']);
    exit;
}

// Basic authorization: allow if current session matches id or user is admin
$allowed = false;
if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin') $allowed = true;
if (!$allowed) {
    $sessId = null;
    if (!empty($_SESSION['id_number'])) $sessId = $_SESSION['id_number'];
    if (!empty($_SESSION['user_id'])) $sessId = $_SESSION['user_id'];
    if (!empty($_SESSION['idnum'])) $sessId = $_SESSION['idnum'];
    if ($sessId && $sessId === $id) $allowed = true;
}
if (!$allowed) {
    echo json_encode(['success'=>false,'message'=>'Unauthorized']);
    exit;
}

$sql = "DELETE FROM mldb.user_sig WHERE id_number = ? LIMIT 1";
$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    echo json_encode(['success'=>false,'message'=>'DB prepare failed']);
    exit;
}

mysqli_stmt_bind_param($stmt, 's', $id);
$ok = mysqli_stmt_execute($stmt);
if (!$ok) {
    echo json_encode(['success'=>false,'message'=>'DB error: '.mysqli_stmt_error($stmt)]);
    exit;
}

mysqli_stmt_close($stmt);

echo json_encode(['success'=>true,'message'=>'Deleted']);
exit;
