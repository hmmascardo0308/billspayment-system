<?php
if (!defined('SUPPORT_TICKET_BOOTSTRAP_LOADED')) {
    define('SUPPORT_TICKET_BOOTSTRAP_LOADED', true);

    include_once __DIR__ . '/../../../config/config.php';
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    include_once __DIR__ . '/../../../templates/middleware.php';

    function st_schema()
    {
        return 'support_ticket';
    }

    function st_user_identifier()
    {
        if (function_exists('resolve_user_identifier')) {
            return resolve_user_identifier();
        }

        if (!empty($_SESSION['id_number'])) {
            return $_SESSION['id_number'];
        }
        if (!empty($_SESSION['idnum'])) {
            return $_SESSION['idnum'];
        }
        if (!empty($_SESSION['user_id'])) {
            return $_SESSION['user_id'];
        }
        return null;
    }

    function st_user_id_or_null()
    {
        $id = st_user_identifier();
        if ($id === null || $id === '') {
            return null;
        }
        return is_numeric($id) ? (int) $id : null;
    }

    function st_require_login($redirectTo = '../../login_form.php')
    {
        $id = st_user_identifier();
        if (empty($id)) {
            header('Location: ' . $redirectTo);
            exit;
        }
    }

    function st_require_permission_page($permissions, $redirectTo = '../home.php')
    {
        if (!function_exists('has_any_permission') || !has_any_permission($permissions)) {
            header('Location: ' . $redirectTo);
            exit;
        }
    }

    function st_require_permission_api($permissions)
    {
        if (!function_exists('has_any_permission') || !has_any_permission($permissions)) {
            st_json(false, 'You do not have permission to perform this action.', [], 403);
        }
    }

    function st_flash_set($key, $type, $message)
    {
        if (!isset($_SESSION['support_ticket_flash'])) {
            $_SESSION['support_ticket_flash'] = [];
        }
        $_SESSION['support_ticket_flash'][$key] = [
            'type' => $type,
            'message' => $message,
        ];
    }

    function st_flash_get($key)
    {
        if (!isset($_SESSION['support_ticket_flash'][$key])) {
            return null;
        }

        $flash = $_SESSION['support_ticket_flash'][$key];
        unset($_SESSION['support_ticket_flash'][$key]);
        return $flash;
    }

    function st_redirect_with_flash($flashKey, $type, $message, $redirectUrl)
    {
        st_flash_set($flashKey, $type, $message);
        header('Location: ' . $redirectUrl);
        exit;
    }

    function st_is_ajax_request()
    {
        $xrw = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        if ($xrw === 'xmlhttprequest') {
            return true;
        }

        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        return strpos($accept, 'application/json') !== false;
    }

    function st_json($success, $message, $data = [], $status = 200)
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => (bool) $success,
            'message' => $message,
            'data' => $data,
        ]);
        exit;
    }

    function st_to_decimal($value)
    {
        if ($value === null) {
            return null;
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $clean = str_replace(',', '', $raw);
        if (!is_numeric($clean)) {
            return null;
        }

        return (float) $clean;
    }

    function st_upper($value)
    {
        return strtoupper(trim((string) $value));
    }

    function st_generate_ticket_number($conn)
    {
        $schema = st_schema();

        $seedSql = "INSERT IGNORE INTO {$schema}.ticket_number_seq (seq_date, last_seq) VALUES (CURDATE(), 0)";
        if (!$conn->query($seedSql)) {
            throw new Exception('Unable to initialize ticket sequence.');
        }

        $lockSql = "SELECT last_seq FROM {$schema}.ticket_number_seq WHERE seq_date = CURDATE() FOR UPDATE";
        $res = $conn->query($lockSql);
        if (!$res || $res->num_rows === 0) {
            throw new Exception('Unable to lock ticket sequence row.');
        }

        $row = $res->fetch_assoc();
        $next = (int) $row['last_seq'] + 1;

        $updSql = "UPDATE {$schema}.ticket_number_seq SET last_seq = ? WHERE seq_date = CURDATE()";
        $stmt = $conn->prepare($updSql);
        if (!$stmt) {
            throw new Exception('Unable to prepare ticket sequence update.');
        }

        $stmt->bind_param('i', $next);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new Exception('Unable to increment ticket sequence.');
        }
        $stmt->close();

        return 'TKT-' . date('Ymd') . '-' . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    function st_get_ticket_types($conn)
    {
        $schema = st_schema();
        $list = [];

        $sql = "SELECT id, label FROM {$schema}.ticket_types ORDER BY label ASC";
        $res = $conn->query($sql);
        if (!$res) {
            return $list;
        }

        while ($row = $res->fetch_assoc()) {
            $list[] = $row;
        }

        return $list;
    }

    function st_get_subbiller_by_ext_id($conn, $subbillerExtId)
    {
        $schema = st_schema();
        $sql = "SELECT subbiller_ext_id, subbiller_name, partner_ext_id FROM {$schema}.vw_mldb_subbillers WHERE subbiller_ext_id = ? LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('s', $subbillerExtId);
        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }

        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        return $row ?: null;
    }

    function st_get_subbillers($conn, $limit = 2000)
    {
        $schema = st_schema();
        $limit = max(1, min(5000, (int) $limit));
        $sql = "SELECT subbiller_ext_id, subbiller_name, partner_ext_id FROM {$schema}.vw_mldb_subbillers ORDER BY subbiller_name ASC LIMIT {$limit}";

        $list = [];
        $res = $conn->query($sql);
        if (!$res) {
            return $list;
        }

        while ($row = $res->fetch_assoc()) {
            $list[] = $row;
        }

        return $list;
    }

    function st_insert_trail($conn, $ticketId, $type, $senderId, $senderRole, $targetRole, $message, $meta = null)
    {
        $schema = st_schema();
        $metaJson = $meta === null ? null : json_encode($meta, JSON_UNESCAPED_SLASHES);

        $sql = "INSERT INTO {$schema}.ticket_trails (ticket_id, type, sender_id, sender_role, target_role, message, meta) VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception('Unable to prepare trail insert.');
        }

        $senderIdParam = $senderId === null ? null : (int) $senderId;
        $targetRoleParam = $targetRole === null || $targetRole === '' ? null : $targetRole;
        $messageParam = $message === null || $message === '' ? null : $message;

        $stmt->bind_param(
            'isissss',
            $ticketId,
            $type,
            $senderIdParam,
            $senderRole,
            $targetRoleParam,
            $messageParam,
            $metaJson
        );

        if (!$stmt->execute()) {
            $stmt->close();
            throw new Exception('Unable to insert ticket trail.');
        }

        $trailId = (int) $conn->insert_id;
        $stmt->close();

        // Update unread counters whenever a trail entry is added.
        st_ticket_badge_on_new_trail($conn, (int) $ticketId, (string) $senderRole);

        return $trailId;
    }

    function st_insert_attachment($conn, $ticketId, $trailId, $createdBy, $file)
    {
        $schema = st_schema();

        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return;
        }

        $binary = file_get_contents($file['tmp_name']);
        if ($binary === false) {
            throw new Exception('Unable to read uploaded attachment.');
        }

        $name = (string) ($file['name'] ?? 'attachment');
        $mime = (string) ($file['type'] ?? 'application/octet-stream');
        $size = (int) ($file['size'] ?? strlen($binary));

        $sql = "INSERT INTO {$schema}.ticket_attachments (ticket_trail_id, ticket_id, file_name, mime_type, file_size, file_data, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception('Unable to prepare attachment insert.');
        }

        $createdByParam = $createdBy === null ? null : (int) $createdBy;

        $null = null;
        $stmt->bind_param('iissibi', $trailId, $ticketId, $name, $mime, $size, $null, $createdByParam);
        $stmt->send_long_data(5, $binary);

        if (!$stmt->execute()) {
            $stmt->close();
            throw new Exception('Unable to save attachment.');
        }

        $stmt->close();
    }

    function st_uploads_to_array($fieldName)
    {
        if (!isset($_FILES[$fieldName])) {
            return [];
        }

        $file = $_FILES[$fieldName];

        if (!is_array($file['name'])) {
            return [$file];
        }

        $list = [];
        $count = count($file['name']);
        for ($i = 0; $i < $count; $i++) {
            if ((int) $file['error'][$i] !== UPLOAD_ERR_OK) {
                continue;
            }

            $list[] = [
                'name' => $file['name'][$i],
                'type' => $file['type'][$i],
                'tmp_name' => $file['tmp_name'][$i],
                'error' => $file['error'][$i],
                'size' => $file['size'][$i],
            ];
        }

        return $list;
    }

    function st_table_exists($conn, $tableName)
    {
        static $cache = [];
        $schema = st_schema();
        $key = $schema . '.' . $tableName;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $sql = 'SELECT 1 FROM information_schema.tables WHERE table_schema = ? AND table_name = ? LIMIT 1';
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $cache[$key] = false;
            return false;
        }

        $stmt->bind_param('ss', $schema, $tableName);
        if (!$stmt->execute()) {
            $stmt->close();
            $cache[$key] = false;
            return false;
        }

        $res = $stmt->get_result();
        $exists = ($res && $res->num_rows > 0);
        $stmt->close();

        $cache[$key] = $exists;
        return $exists;
    }

    function st_ticket_badge_tables_ready($conn)
    {
        return st_table_exists($conn, 'ticket_badge') && st_table_exists($conn, 'ticket_active');
    }

    function st_ensure_ticket_badge_row($conn, $ticketNumber)
    {
        if (!st_table_exists($conn, 'ticket_badge')) {
            return;
        }

        $schema = st_schema();
        $sql = "INSERT INTO {$schema}.ticket_badge
                (ticket_number, branch_count, branch_seen, vpo_count, vpo_seen, cad_count, cad_seen)
                VALUES (?, NULL, 1, NULL, 1, NULL, 1)
                ON DUPLICATE KEY UPDATE ticket_number = ticket_number";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return;
        }

        $stmt->bind_param('s', $ticketNumber);
        $stmt->execute();
        $stmt->close();
    }

    function st_ticket_badge_increment_role($conn, $ticketNumber, $role)
    {
        if (!st_table_exists($conn, 'ticket_badge')) {
            return;
        }

        $r = strtoupper(trim((string) $role));
        $schema = st_schema();
        $sql = null;
        if ($r === 'BRANCH') {
            $sql = "UPDATE {$schema}.ticket_badge
                    SET branch_count = COALESCE(branch_count, 0) + 1,
                        branch_seen = 0
                    WHERE ticket_number = ?";
        } elseif ($r === 'VPO') {
            $sql = "UPDATE {$schema}.ticket_badge
                    SET vpo_count = COALESCE(vpo_count, 0) + 1,
                        vpo_seen = 0
                    WHERE ticket_number = ?";
        } elseif ($r === 'CAD') {
            $sql = "UPDATE {$schema}.ticket_badge
                    SET cad_count = COALESCE(cad_count, 0) + 1,
                        cad_seen = 0
                    WHERE ticket_number = ?";
        }

        if ($sql === null) {
            return;
        }

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return;
        }

        $stmt->bind_param('s', $ticketNumber);
        $stmt->execute();
        $stmt->close();
    }

    function st_ticket_badge_mark_seen($conn, $ticketNumber, $role)
    {
        if (!st_table_exists($conn, 'ticket_badge')) {
            return;
        }

        $r = strtoupper(trim((string) $role));
        $schema = st_schema();
        $sql = null;
        if ($r === 'BRANCH') {
            $sql = "UPDATE {$schema}.ticket_badge
                    SET branch_count = NULL,
                        branch_seen = 1
                    WHERE ticket_number = ?";
        } elseif ($r === 'VPO') {
            $sql = "UPDATE {$schema}.ticket_badge
                    SET vpo_count = NULL,
                        vpo_seen = 1
                    WHERE ticket_number = ?";
        } elseif ($r === 'CAD') {
            $sql = "UPDATE {$schema}.ticket_badge
                    SET cad_count = NULL,
                        cad_seen = 1
                    WHERE ticket_number = ?";
        }

        if ($sql === null) {
            return;
        }

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return;
        }

        $stmt->bind_param('s', $ticketNumber);
        $stmt->execute();
        $stmt->close();
    }

    function st_sync_ticket_active_counts($conn, $userId)
    {
        if (!st_table_exists($conn, 'ticket_active') || $userId === null) {
            return;
        }

        $schema = st_schema();
        $vpoCount = 0;
        $cadCount = 0;

        $vpoSql = "SELECT COUNT(*) AS c
                   FROM {$schema}.tickets
                                     WHERE status NOT IN ('closed', 'resolved')
                                         AND ((assigned_to = ? AND current_handler_role = 'VPO')
                                                    OR (vpo_owner = ? AND current_handler_role = 'CAD'))";
        $vpoStmt = $conn->prepare($vpoSql);
        if ($vpoStmt) {
                        $vpoStmt->bind_param('ii', $userId, $userId);
            if ($vpoStmt->execute()) {
                $res = $vpoStmt->get_result();
                $row = $res ? $res->fetch_assoc() : null;
                $vpoCount = (int) ($row['c'] ?? 0);
            }
            $vpoStmt->close();
        }

                $cadSql = "SELECT COUNT(*) AS c
                                     FROM {$schema}.tickets
                                     WHERE assigned_to = ?
                                         AND current_handler_role = 'CAD'
                                         AND status NOT IN ('closed', 'resolved')";
        $cadStmt = $conn->prepare($cadSql);
        if ($cadStmt) {
            $cadStmt->bind_param('i', $userId);
            if ($cadStmt->execute()) {
                $res = $cadStmt->get_result();
                $row = $res ? $res->fetch_assoc() : null;
                $cadCount = (int) ($row['c'] ?? 0);
            }
            $cadStmt->close();
        }

        $vpoCountParam = $vpoCount > 0 ? $vpoCount : null;
        $cadCountParam = $cadCount > 0 ? $cadCount : null;

        $upsertSql = "INSERT INTO {$schema}.ticket_active (id_number, vpo_count, vpo_seen, cad_count, cad_seen)
                      VALUES (?, ?, 1, ?, 1)
                      ON DUPLICATE KEY UPDATE vpo_count = VALUES(vpo_count), cad_count = VALUES(cad_count)";
        $upStmt = $conn->prepare($upsertSql);
        if (!$upStmt) {
            return;
        }

        $upStmt->bind_param('iii', $userId, $vpoCountParam, $cadCountParam);
        $upStmt->execute();
        $upStmt->close();
    }

    function st_get_ticket_active_row($conn, $userId)
    {
        if (!st_table_exists($conn, 'ticket_active') || $userId === null) {
            return ['vpo_count' => null, 'vpo_seen' => 1, 'cad_count' => null, 'cad_seen' => 1];
        }

        $schema = st_schema();
        $sql = "SELECT vpo_count, vpo_seen, cad_count, cad_seen
                FROM {$schema}.ticket_active
                WHERE id_number = ?
                LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return ['vpo_count' => null, 'vpo_seen' => 1, 'cad_count' => null, 'cad_seen' => 1];
        }

        $stmt->bind_param('i', $userId);
        if (!$stmt->execute()) {
            $stmt->close();
            return ['vpo_count' => null, 'vpo_seen' => 1, 'cad_count' => null, 'cad_seen' => 1];
        }

        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        return $row ?: ['vpo_count' => null, 'vpo_seen' => 1, 'cad_count' => null, 'cad_seen' => 1];
    }

    function st_ticket_active_mark_seen($conn, $userId, $role)
    {
        if (!st_table_exists($conn, 'ticket_active') || $userId === null) {
            return;
        }

        $schema = st_schema();
        $r = strtoupper(trim((string) $role));
        $sql = null;
        if ($r === 'VPO') {
            $sql = "UPDATE {$schema}.ticket_active SET vpo_seen = 1 WHERE id_number = ?";
        } elseif ($r === 'CAD') {
            $sql = "UPDATE {$schema}.ticket_active SET cad_seen = 1 WHERE id_number = ?";
        }
        if ($sql === null) {
            return;
        }

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    }

    function st_ticket_active_mark_unseen($conn, $userId, $role)
    {
        if (!st_table_exists($conn, 'ticket_active') || $userId === null) {
            return;
        }

        $schema = st_schema();
        $r = strtoupper(trim((string) $role));
        $sql = null;
        if ($r === 'VPO') {
            $sql = "UPDATE {$schema}.ticket_active SET vpo_seen = 0 WHERE id_number = ?";
        } elseif ($r === 'CAD') {
            $sql = "UPDATE {$schema}.ticket_active SET cad_seen = 0 WHERE id_number = ?";
        }
        if ($sql === null) {
            return;
        }

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    }

    function st_get_ticket_badge_counts($conn, $ticketNumbers, $role)
    {
        if (!st_table_exists($conn, 'ticket_badge')) {
            return [];
        }

        $col = null;
        $r = strtoupper(trim((string) $role));
        if ($r === 'BRANCH') {
            $col = 'branch_count';
        } elseif ($r === 'VPO') {
            $col = 'vpo_count';
        } elseif ($r === 'CAD') {
            $col = 'cad_count';
        }
        if ($col === null) {
            return [];
        }

        $numbers = [];
        foreach ((array) $ticketNumbers as $n) {
            $t = trim((string) $n);
            if ($t !== '') {
                $numbers[] = $t;
            }
        }
        $numbers = array_values(array_unique($numbers));
        if (empty($numbers)) {
            return [];
        }

        $schema = st_schema();
        $placeholders = implode(',', array_fill(0, count($numbers), '?'));
        $types = str_repeat('s', count($numbers));
        $sql = "SELECT ticket_number, {$col} AS role_count
                FROM {$schema}.ticket_badge
                WHERE ticket_number IN ({$placeholders})";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param($types, ...$numbers);
        if (!$stmt->execute()) {
            $stmt->close();
            return [];
        }

        $res = $stmt->get_result();
        $map = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $ticketNumber = (string) ($row['ticket_number'] ?? '');
                if ($ticketNumber === '') {
                    continue;
                }
                $countVal = $row['role_count'];
                $map[$ticketNumber] = $countVal === null ? null : (int) $countVal;
            }
        }

        $stmt->close();
        return $map;
    }

    function st_ticket_badge_on_new_trail($conn, $ticketId, $senderRole)
    {
        if (!st_ticket_badge_tables_ready($conn)) {
            return;
        }

        $schema = st_schema();
        $sql = "SELECT ticket_number, created_by, vpo_owner, cad_owner
                FROM {$schema}.tickets
                WHERE id = ?
                LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return;
        }

        $stmt->bind_param('i', $ticketId);
        if (!$stmt->execute()) {
            $stmt->close();
            return;
        }

        $res = $stmt->get_result();
        $ticket = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if (!$ticket) {
            return;
        }

        $ticketNumber = trim((string) ($ticket['ticket_number'] ?? ''));
        if ($ticketNumber === '') {
            return;
        }

        st_ensure_ticket_badge_row($conn, $ticketNumber);

        $sender = strtoupper(trim((string) $senderRole));
        $participants = [
            'BRANCH' => (int) ($ticket['created_by'] ?? 0),
            'VPO' => (int) ($ticket['vpo_owner'] ?? 0),
            'CAD' => (int) ($ticket['cad_owner'] ?? 0),
        ];

        foreach ($participants as $role => $ownerId) {
            if ($ownerId <= 0) {
                continue;
            }
            if ($sender !== 'SYSTEM' && $sender === $role) {
                continue;
            }
            st_ticket_badge_increment_role($conn, $ticketNumber, $role);
            if ($role === 'VPO' || $role === 'CAD') {
                st_sync_ticket_active_counts($conn, $ownerId);
                st_ticket_active_mark_unseen($conn, $ownerId, $role);
            }
        }
    }
}
