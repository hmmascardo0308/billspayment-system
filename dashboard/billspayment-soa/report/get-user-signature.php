<?php
require_once __DIR__ . '/../../../config/config.php';
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$full_name = $input['full_name'] ?? '';
$id_number = $input['id_number'] ?? '';

// basic validation
if (empty($full_name) && empty($id_number)) {
    echo json_encode(['error' => 'missing_full_name_or_id']);
    exit;
}

// ensure connection uses utf8mb4 so blobs and names are handled correctly
mysqli_set_charset($conn, 'utf8mb4');

$row = null;

if (!empty($id_number)) {
    // lookup by id_number (preferred when available)
    $sql = "SELECT muf.id_number, CONCAT_WS(' ', muf.first_name, muf.middle_name, muf.last_name) AS full_name, mus.signature
            FROM mldb.user_form muf
            LEFT JOIN mldb.user_sig mus ON muf.id_number = mus.id_number
            WHERE muf.id_number = ? LIMIT 1";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        echo json_encode(['error' => 'query_failed', 'db_error' => mysqli_error($conn)]);
        exit;
    }
    mysqli_stmt_bind_param($stmt, 's', $id_number);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);
} else {
    // normalize name (collapse spaces)
    $nameParam = preg_replace('/\s+/', ' ', trim($full_name));

    // exact full-name match first
    $sql = "SELECT muf.id_number, CONCAT_WS(' ', muf.first_name, muf.middle_name, muf.last_name) AS full_name, mus.signature
            FROM mldb.user_form muf
            LEFT JOIN mldb.user_sig mus ON muf.id_number = mus.id_number
            WHERE TRIM(CONCAT_WS(' ', muf.first_name, muf.middle_name, muf.last_name)) COLLATE utf8mb4_general_ci = ?
            LIMIT 1";
    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 's', $nameParam);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);
    } else {
        echo json_encode(['error' => 'query_failed', 'db_error' => mysqli_error($conn)]);
        exit;
    }

    // fallback: match by first and last name using LIKE
    if (!$row) {
        $parts = explode(' ', $nameParam);
        if (count($parts) >= 2) {
            $first = $parts[0];
            $last = $parts[count($parts) - 1];
            $likeSql = "SELECT muf.id_number, CONCAT_WS(' ', muf.first_name, muf.middle_name, muf.last_name) AS full_name, mus.signature
                        FROM mldb.user_form muf
                        LEFT JOIN mldb.user_sig mus ON muf.id_number = mus.id_number
                        WHERE muf.first_name LIKE ? AND muf.last_name LIKE ? LIMIT 1";
            $ls = mysqli_prepare($conn, $likeSql);
            if ($ls) {
                $fparam = $first . '%';
                $lparam = $last . '%';
                mysqli_stmt_bind_param($ls, 'ss', $fparam, $lparam);
                mysqli_stmt_execute($ls);
                $lres = mysqli_stmt_get_result($ls);
                $row = mysqli_fetch_assoc($lres);
                mysqli_stmt_close($ls);
            }
        }
    }
}

if ($row) {
    if (!empty($row['signature'])) {
        $b64 = base64_encode($row['signature']);
        $dataUrl = 'data:image/png;base64,' . $b64;
        echo json_encode(['id' => $row['id_number'], 'signature' => $dataUrl]);
    } else {
        // no blob found; return id only so UI can show friendly text
        echo json_encode(['id' => $row['id_number'], 'signature' => null]);
    }
} else {
    echo json_encode(['id' => null, 'signature' => null]);
}

?>