<?php
include_once __DIR__ . '/../../includes/bootstrap.php';
global $conn;
$runByCli = php_sapi_name() === 'cli';
if (!$runByCli) {
    st_require_login('../../../../login_form.php');
    if (!function_exists('has_any_permission') || !has_any_permission(['Support Ticket CAD'])) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

$schema = st_schema();

$conn->autocommit(false);

try {
    $sql = "SELECT id FROM {$schema}.tickets WHERE status = 'resolved' AND auto_close_at IS NOT NULL AND auto_close_at <= NOW() FOR UPDATE";
    $res = $conn->query($sql);
    if (!$res) {
        throw new Exception('Unable to query auto-close candidates.');
    }

    $ticketIds = [];
    while ($row = $res->fetch_assoc()) {
        $ticketIds[] = (int) $row['id'];
    }

    if (empty($ticketIds)) {
        $conn->commit();
        $conn->autocommit(true);
        $msg = 'Auto-close completed. No resolved tickets due.';
        if ($runByCli) {
            echo $msg . PHP_EOL;
        } else {
            echo $msg;
        }
        exit;
    }

    $updSql = "UPDATE {$schema}.tickets
               SET status = 'closed',
                   closed_at = NOW(),
                   updated_at = NOW()
               WHERE id = ? AND status = 'resolved'";
    $updStmt = $conn->prepare($updSql);
    if (!$updStmt) {
        throw new Exception('Unable to prepare auto-close update.');
    }

    $closedCount = 0;
    foreach ($ticketIds as $ticketId) {
        $updStmt->bind_param('i', $ticketId);
        if (!$updStmt->execute()) {
            continue;
        }

        if ($updStmt->affected_rows > 0) {
            $closedCount++;
            st_insert_trail(
                $conn,
                $ticketId,
                'auto_close',
                null,
                'SYSTEM',
                null,
                'Ticket automatically closed after resolution period expired.',
                ['automation' => true]
            );
        }
    }
    $updStmt->close();

    $conn->commit();
    $conn->autocommit(true);

    $msg = 'Auto-close completed. Closed tickets: ' . $closedCount;
    if ($runByCli) {
        echo $msg . PHP_EOL;
    } else {
        echo $msg;
    }
    exit;
} catch (Exception $e) {
    $conn->rollback();
    $conn->autocommit(true);
    http_response_code(500);
    echo 'Auto-close failed: ' . $e->getMessage();
    exit;
}
