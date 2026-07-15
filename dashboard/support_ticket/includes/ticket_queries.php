<?php
include_once __DIR__ . '/bootstrap.php';

if (!function_exists('st_fetch_tickets')) {
    function st_fetch_tickets($conn, $whereSql, $params = [], $paramTypes = '')
    {
        $schema = st_schema();
        $sql = "SELECT
                    t.id,
                    t.ticket_number,
                    t.reference_number,
                    t.source,
                    t.partner_ext_id,
                    t.status,
                    t.current_handler_role,
                    t.assigned_to,
                    t.vpo_owner,
                    t.cad_owner,
                    t.auto_close_at,
                        t.closed_at,
                        t.created_at,
                        t.created_by,
                    ti.ticket_type_id,
                    tt.label AS ticket_type_label,
                    ti.reason,
                    ti.type_of_request,
                    ti.wrong_biller_id,
                    ti.biller_name,
                    ti.transfer_datetime,
                    ti.ref_no,
                    ti.account_no,
                    ti.account_name,
                    ti.payment_branch_id,
                    ti.payment_branch_name,
                    ti.amount,
                    sb.partner_ext_id AS sb_partner_ext_id,
                    p.partner_name
                FROM {$schema}.tickets t
                LEFT JOIN {$schema}.ticket_info ti ON ti.ticket_number = t.ticket_number
                LEFT JOIN {$schema}.ticket_types tt ON tt.id = ti.ticket_type_id
                LEFT JOIN {$schema}.vw_mldb_subbillers sb
                    ON sb.subbiller_ext_id COLLATE utf8mb4_unicode_ci = ti.wrong_biller_id COLLATE utf8mb4_unicode_ci
                LEFT JOIN {$schema}.vw_mldb_partners p
                    ON p.partner_ext_id COLLATE utf8mb4_unicode_ci = COALESCE(
                        t.partner_ext_id COLLATE utf8mb4_unicode_ci,
                        sb.partner_ext_id COLLATE utf8mb4_unicode_ci
                    )
                {$whereSql}
                ORDER BY t.created_at DESC";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }

        if (!empty($params) && $paramTypes !== '') {
            $stmt->bind_param($paramTypes, ...$params);
        }

        if (!$stmt->execute()) {
            $stmt->close();
            return [];
        }

        $res = $stmt->get_result();
        $rows = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
        }

        $stmt->close();
        return $rows;
    }
}

if (!function_exists('st_get_ticket_wrongbiller_by_ticket_number')) {
    function st_get_ticket_wrongbiller_by_ticket_number($conn, $ticketNumber)
    {
        $schema = st_schema();
        $sql = "SELECT correct_biller_id, correct_biller_name FROM {$schema}.ticket_info_wrongbiller WHERE ticket_number = ? ORDER BY id DESC LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('s', $ticketNumber);
        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }

        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('st_get_ticket_overstatedamount_by_ticket_number')) {
    function st_get_ticket_overstatedamount_by_ticket_number($conn, $ticketNumber)
    {
        $schema = st_schema();
        $sql = "SELECT wrong_amount, correct_amount, difference FROM {$schema}.ticket_info_overstatedamount WHERE ticket_number = ? ORDER BY id DESC LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('s', $ticketNumber);
        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }

        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('st_get_ticket_cancelledtransaction_by_ticket_number')) {
    function st_get_ticket_cancelledtransaction_by_ticket_number($conn, $ticketNumber)
    {
        $schema = st_schema();
        $sql = "SELECT wrong_amount, correct_amount FROM {$schema}.ticket_info_cancelledtransaction WHERE ticket_number = ? ORDER BY id DESC LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('s', $ticketNumber);
        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }

        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('st_get_user_names_by_id_numbers')) {
    function st_get_user_names_by_id_numbers($conn, $idNumbers)
    {
        $ids = [];
        foreach ((array) $idNumbers as $id) {
            if ($id === null || $id === '') {
                continue;
            }
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        $ids = array_values(array_unique($ids));
        if (empty($ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));
        $sql = "SELECT id_number, first_name, last_name
                FROM mldb.user_form
                WHERE id_number IN ({$placeholders})";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param($types, ...$ids);
        if (!$stmt->execute()) {
            $stmt->close();
            return [];
        }

        $res = $stmt->get_result();
        $map = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $id = (int) ($row['id_number'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $first = trim((string) ($row['first_name'] ?? ''));
                $last = trim((string) ($row['last_name'] ?? ''));
                $fullName = trim($first . ' ' . $last);
                $map[$id] = $fullName !== '' ? $fullName : ('ID ' . $id);
            }
        }

        $stmt->close();
        return $map;
    }
}

if (!function_exists('st_get_user_emails_by_id_numbers')) {
    function st_get_user_emails_by_id_numbers($conn, $idNumbers)
    {
        $ids = [];
        foreach ((array) $idNumbers as $id) {
            if ($id === null || $id === '') {
                continue;
            }
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        $ids = array_values(array_unique($ids));
        if (empty($ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));
        $sql = "SELECT id_number, email FROM mldb.user_form WHERE id_number IN ({$placeholders})";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param($types, ...$ids);
        if (!$stmt->execute()) {
            $stmt->close();
            return [];
        }

        $res = $stmt->get_result();
        $map = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $id = (int) ($row['id_number'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $email = trim((string) ($row['email'] ?? ''));
                if ($email !== '') {
                    $map[$id] = $email;
                }
            }
        }

        $stmt->close();
        return $map;
    }
}

if (!function_exists('st_get_vpo_open_tickets')) {
    function st_get_vpo_open_tickets($conn)
    {
        return st_fetch_tickets(
            $conn,
            "WHERE t.current_handler_role = 'VPO' AND t.assigned_to IS NULL AND t.status NOT IN ('closed', 'resolved')"
        );
    }
}

if (!function_exists('st_get_vpo_active_tickets')) {
    function st_get_vpo_active_tickets($conn, $userId)
    {
        return st_fetch_tickets(
            $conn,
            "WHERE t.status NOT IN ('closed', 'resolved') AND ((t.assigned_to = ? AND t.current_handler_role = 'VPO') OR (t.vpo_owner = ? AND t.current_handler_role = 'CAD'))",
            [(int) $userId, (int) $userId],
            'ii'
        );
    }
}

if (!function_exists('st_get_vpo_closed_tickets')) {
    function st_get_vpo_closed_tickets($conn, $userId)
    {
        return st_fetch_tickets(
            $conn,
            "WHERE t.status IN ('resolved', 'closed') AND t.vpo_owner = ?",
            [(int) $userId],
            'i'
        );
    }
}

if (!function_exists('st_get_cad_open_tickets')) {
    function st_get_cad_open_tickets($conn)
    {
        return st_fetch_tickets(
            $conn,
            "WHERE t.current_handler_role = 'CAD' AND t.assigned_to IS NULL AND t.status IN ('open', 'transferred')"
        );
    }
}

if (!function_exists('st_get_cad_active_tickets')) {
    function st_get_cad_active_tickets($conn, $userId)
    {
        return st_fetch_tickets(
            $conn,
            "WHERE t.status NOT IN ('closed', 'resolved') AND ((t.assigned_to = ? AND t.current_handler_role = 'CAD') OR (t.cad_owner = ? AND t.current_handler_role = 'VPO'))",
            [(int) $userId, (int) $userId],
            'ii'
        );
    }
}

if (!function_exists('st_get_cad_closed_tickets')) {
    function st_get_cad_closed_tickets($conn, $userId)
    {
        return st_fetch_tickets(
            $conn,
            "WHERE t.status IN ('resolved', 'closed') AND t.cad_owner = ?",
            [(int) $userId],
            'i'
        );
    }
}

if (!function_exists('st_get_branch_tickets')) {
    function st_get_branch_tickets($conn, $userId)
    {
        return st_fetch_tickets(
            $conn,
            "WHERE t.created_by = ?",
            [(int) $userId],
            'i'
        );
    }
}

if (!function_exists('st_get_ticket_by_id')) {
    function st_get_ticket_by_id($conn, $ticketId)
    {
        $rows = st_fetch_tickets(
            $conn,
            "WHERE t.id = ? LIMIT 1",
            [(int) $ticketId],
            'i'
        );

        return empty($rows) ? null : $rows[0];
    }
}

if (!function_exists('st_get_ticket_trails')) {
    function st_get_ticket_trails($conn, $ticketId)
    {
        $schema = st_schema();
        $sql = "SELECT id, ticket_id, type, sender_id, sender_role, target_role, message, meta, created_at
                FROM {$schema}.ticket_trails
                WHERE ticket_id = ?
                ORDER BY created_at ASC, id ASC";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param('i', $ticketId);
        if (!$stmt->execute()) {
            $stmt->close();
            return [];
        }

        $res = $stmt->get_result();
        $rows = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $row['meta_decoded'] = null;
                if (!empty($row['meta'])) {
                    $dec = json_decode($row['meta'], true);
                    if (is_array($dec)) {
                        $row['meta_decoded'] = $dec;
                    }
                }
                $rows[] = $row;
            }
        }

        $stmt->close();
        return $rows;
    }
}
