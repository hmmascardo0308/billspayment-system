<?php
/**
 * Migration helper: adds `permissions` column to `mldb.user_form` if missing
 * and migrates entries from assets/js/user-permissions.json into the DB.
 * Run from CLI or web once (requires DB write access).
 */
include '../../config/config.php';

$mapPath = __DIR__ . '/../../assets/js/user-permissions.json';
$report = ['status' => 'ok', 'actions' => []];

try {
    // check if column exists
    $colRes = mysqli_query($conn, "SHOW COLUMNS FROM mldb.user_form LIKE 'permissions'");
    if ($colRes && mysqli_num_rows($colRes) > 0) {
        $report['actions'][] = 'permissions column already exists';
    } else {
        $alter = "ALTER TABLE mldb.user_form ADD COLUMN permissions TEXT NULL";
        if (mysqli_query($conn, $alter)) {
            $report['actions'][] = 'added permissions column';
        } else {
            $report['actions'][] = 'failed to add column: ' . mysqli_error($conn);
        }
    }

    // migrate file data if exists
    if (file_exists($mapPath)) {
        $raw = @file_get_contents($mapPath);
        $dec = json_decode($raw, true);
        if (is_array($dec)) {
            $migrated = 0;
            $skipped = 0;

            // detect whether id_number column is numeric to avoid SQL warnings
            $idColRes = mysqli_query($conn, "SHOW COLUMNS FROM mldb.user_form LIKE 'id_number'");
            $idNumberIsInt = false;
            if ($idColRes && ($colInfo = mysqli_fetch_assoc($idColRes))) {
                $type = isset($colInfo['Type']) ? $colInfo['Type'] : '';
                if (preg_match('/int/i', $type)) {
                    $idNumberIsInt = true;
                }
            }

            foreach ($dec as $id => $perms) {
                if (!is_array($perms)) continue;

                // skip entries that cannot match numeric id_number columns
                if ($idNumberIsInt && !ctype_digit((string)$id)) {
                    $skipped++;
                    continue;
                }

                $json = json_encode($perms, JSON_UNESCAPED_SLASHES);
                $stmt = mysqli_prepare($conn, "UPDATE mldb.user_form SET permissions = ? WHERE id_number = ?");
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, 'ss', $json, $id);
                    mysqli_stmt_execute($stmt);
                    $affected = mysqli_stmt_affected_rows($stmt);
                    if ($affected > 0) $migrated++;
                    mysqli_stmt_close($stmt);
                }
            }

            $report['actions'][] = 'migrated ' . $migrated . ' rows from file';
            if ($skipped > 0) $report['actions'][] = 'skipped ' . $skipped . ' file entries (id_number not numeric)';
        } else {
            $report['actions'][] = 'no valid JSON in user-permissions.json';
        }
    } else {
        $report['actions'][] = 'no user-permissions.json found';
    }
} catch (Exception $e) {
    $report['status'] = 'error';
    $report['error'] = $e->getMessage();
}

header('Content-Type: application/json');
echo json_encode($report, JSON_PRETTY_PRINT);

mysqli_close($conn);
