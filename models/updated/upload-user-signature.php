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

if (!isset($_FILES['signature']) || $_FILES['signature']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success'=>false,'message'=>'No file uploaded']);
    exit;
}

$f = $_FILES['signature'];
if ($f['type'] !== 'image/png') {
    echo json_encode(['success'=>false,'message'=>'Only PNG allowed']);
    exit;
}

$data = @file_get_contents($f['tmp_name']);
if ($data === false) {
    echo json_encode(['success'=>false,'message'=>'Failed to read uploaded file']);
    exit;
}

// store as blob (upsert). Use send_long_data for large blobs and auto-upgrade column if needed.
$sql = "INSERT INTO mldb.user_sig (id_number, signature) VALUES (?, ?) ON DUPLICATE KEY UPDATE signature = VALUES(signature)";

function do_blob_upsert($conn, $sql, $id, $data) {
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return ['ok'=>false,'error'=>'DB prepare failed'];

    // bind id and a placeholder for the blob
    $null = null;
    if (!mysqli_stmt_bind_param($stmt, 'sb', $id, $null)) {
        mysqli_stmt_close($stmt);
        return ['ok'=>false,'error'=>'DB bind failed'];
    }

    // send blob data (param index 1 for second parameter, zero-based)
    mysqli_stmt_send_long_data($stmt, 1, $data);

    $ok = mysqli_stmt_execute($stmt);
    $err = null;
    if (!$ok) $err = mysqli_stmt_error($stmt);
    mysqli_stmt_close($stmt);
    return ['ok'=>$ok,'error'=>$err];
}

$res = do_blob_upsert($conn, $sql, $id, $data);
if (!$res['ok']) {
    // If column too small, try altering to LONGBLOB and retry once
    if (is_string($res['error']) && stripos($res['error'], 'Data too long') !== false) {
        $alter = "ALTER TABLE mldb.user_sig MODIFY signature LONGBLOB";
        @mysqli_query($conn, $alter);

        // retry
        $res2 = do_blob_upsert($conn, $sql, $id, $data);
        if ($res2['ok']) {
            echo json_encode(['success'=>true,'message'=>'Uploaded','sig_b64'=>base64_encode($data)]);
            exit;
        } else {
            echo json_encode(['success'=>false,'message'=>'DB error after alter: '.($res2['error']?:'unknown')]);
            exit;
        }
    }

    echo json_encode(['success'=>false,'message'=>'DB error: '.($res['error']?:'unknown')]);
    exit;
}

echo json_encode(['success'=>true,'message'=>'Uploaded','sig_b64'=>base64_encode($data)]);
exit;
