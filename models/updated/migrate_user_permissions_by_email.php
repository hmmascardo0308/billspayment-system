<?php
/**
 * Migration helper: map non-numeric keys in user-permissions.json to users by email
 * and write their permissions into mldb.user_form.permissions.
 * Run from CLI or web once (requires DB write access).
 */
include '../../config/config.php';

$mapPath = __DIR__ . '/../../assets/js/user-permissions.json';
$report = ['status' => 'ok', 'actions' => []];

try {
    if (!file_exists($mapPath)) {
        $report['actions'][] = 'no user-permissions.json found';
        echo json_encode($report, JSON_PRETTY_PRINT);
        exit;
    }

    $raw = @file_get_contents($mapPath);
    $dec = json_decode($raw, true);
    if (!is_array($dec)) {
        $report['actions'][] = 'invalid JSON in user-permissions.json';
        echo json_encode($report, JSON_PRETTY_PRINT);
        exit;
    }

    $migrated = 0;
    $notFound = 0;

    foreach ($dec as $key => $perms) {
        if (!is_array($perms) || trim((string)$key) === '') continue;

        // skip keys that look numeric (these were handled already)
        if (ctype_digit((string)$key)) continue;

        // try match by email (case-insensitive)
        $email = trim((string)$key);
        $stmt = mysqli_prepare($conn, "SELECT id_number FROM mldb.user_form WHERE LOWER(email) = LOWER(?) LIMIT 1");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 's', $email);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            $row = $res ? mysqli_fetch_assoc($res) : null;
            mysqli_stmt_close($stmt);
        } else {
            $row = null;
        }

        if ($row && isset($row['id_number'])) {
            $id = $row['id_number'];
            $json = json_encode($perms, JSON_UNESCAPED_SLASHES);
            $upd = mysqli_prepare($conn, "UPDATE mldb.user_form SET permissions = ? WHERE id_number = ?");
            if ($upd) {
                mysqli_stmt_bind_param($upd, 'ss', $json, $id);
                mysqli_stmt_execute($upd);
                $affected = mysqli_stmt_affected_rows($upd);
                mysqli_stmt_close($upd);
                if ($affected > 0) $migrated++;
            }
        } else {
            $notFound++;
        }
    }

    $report['actions'][] = 'migrated_by_email ' . $migrated;
    $report['actions'][] = 'not_found_by_email ' . $notFound;

} catch (Exception $e) {
    $report['status'] = 'error';
    $report['error'] = $e->getMessage();
}

header('Content-Type: application/json');
echo json_encode($report, JSON_PRETTY_PRINT);

mysqli_close($conn);
