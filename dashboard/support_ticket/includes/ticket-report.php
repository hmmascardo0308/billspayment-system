<?php
if (!function_exists('st_get_ticket_attachments_grouped_by_trail_report')) {
    function st_get_ticket_attachments_grouped_by_trail_report($conn, $ticketId)
    {
        $schema = st_schema();
        $sql = "SELECT id, ticket_trail_id, file_name, mime_type, file_size, created_at
                FROM {$schema}.ticket_attachments
                WHERE ticket_id = ?
                ORDER BY id ASC";
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
        $grouped = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $trailId = (int) ($row['ticket_trail_id'] ?? 0);
                if ($trailId <= 0) {
                    continue;
                }
                if (!isset($grouped[$trailId])) {
                    $grouped[$trailId] = [];
                }
                $grouped[$trailId][] = $row;
            }
        }

        $stmt->close();
        return $grouped;
    }
}

if (!function_exists('st_get_report_tickets')) {
    function st_get_report_tickets($conn)
    {
        return st_fetch_tickets($conn, 'WHERE 1=1');
    }
}

if (!function_exists('st_partition_report_tickets')) {
    function st_partition_report_tickets($tickets)
    {
        $open = [];
        $active = [];
        $closed = [];

        foreach ((array) $tickets as $ticket) {
            $status = strtolower(trim((string) ($ticket['status'] ?? '')));
            if (in_array($status, ['closed', 'resolved'], true)) {
                $closed[] = $ticket;
            } elseif (in_array($status, ['open', 'transferred'], true)) {
                $open[] = $ticket;
            } else {
                $active[] = $ticket;
            }
        }

        return [$open, $active, $closed];
    }
}

if (!function_exists('st_status_class_report')) {
    function st_status_class_report($status)
    {
        return 'st-status st-status-' . strtolower((string) $status);
    }
}

if (!function_exists('st_partner_name_report')) {
    function st_partner_name_report($ticket)
    {
        $partner = trim((string) ($ticket['partner_name'] ?? ''));
        if ($partner !== '') {
            return $partner;
        }

        $ext = trim((string) ($ticket['partner_ext_id'] ?? ''));
        return $ext !== '' ? $ext : 'N/A';
    }
}

if (!function_exists('st_trail_type_label_report')) {
    function st_trail_type_label_report($type)
    {
        $t = strtolower(trim((string) $type));
        if ($t === 'accept') return 'Accepted';
        if ($t === 'transfer') return 'Transferred';
        if ($t === 'resolve') return 'Resolved';
        if ($t === 'close') return 'Closed';
        if ($t === 'auto_close') return 'Auto Closed';
        return 'Message';
    }
}

if (!function_exists('st_trail_role_icon_report')) {
    function st_trail_role_icon_report($role)
    {
        $r = strtoupper(trim((string) $role));
        if ($r === 'BRANCH') return 'BR';
        if ($r === 'VPO') return 'VP';
        if ($r === 'CAD') return 'CD';
        return 'SY';
    }
}

if (!function_exists('st_build_report_stats')) {
    function st_build_report_stats($allTickets, $openTickets, $activeTickets, $closedTickets)
    {
        $total = count($allTickets);
        $openCount = count($openTickets);
        $activeCount = count($activeTickets);
        $closedCount = count($closedTickets);

        $closeRate = $total > 0 ? round(($closedCount / $total) * 100, 1) : 0.0;
        $openRate = $total > 0 ? round(($openCount / $total) * 100, 1) : 0.0;
        $activeRate = $total > 0 ? round(($activeCount / $total) * 100, 1) : 0.0;

        $totalReceivable = 0.0;
        $agingOver24h = 0;
        $agingTickets = [];
        $typeCounts = [];
        $handlerCounts = [
            'BRANCH' => 0,
            'VPO' => 0,
            'CAD' => 0,
            'OTHER' => 0,
        ];

        foreach ($allTickets as $ticket) {
            $status = strtolower(trim((string) ($ticket['status'] ?? '')));
            $isClosed = in_array($status, ['closed', 'resolved'], true);

            if (!$isClosed && isset($ticket['amount']) && $ticket['amount'] !== null && $ticket['amount'] !== '') {
                $totalReceivable += (float) $ticket['amount'];
            }

            $createdAt = strtotime((string) ($ticket['created_at'] ?? ''));
            if (!$isClosed && $createdAt !== false) {
                $hours = (time() - $createdAt) / 3600;
                if ($hours >= 24) {
                    $agingOver24h++;
                }

                $agingTickets[] = [
                    'ticket_number' => (string) ($ticket['ticket_number'] ?? ''),
                    'hours' => round(max(0, $hours), 1),
                    'status' => (string) ($ticket['status'] ?? ''),
                ];
            }

            $typeLabel = trim((string) ($ticket['ticket_type_label'] ?? $ticket['type_of_request'] ?? 'Unspecified'));
            if ($typeLabel === '') {
                $typeLabel = 'Unspecified';
            }
            if (!isset($typeCounts[$typeLabel])) {
                $typeCounts[$typeLabel] = 0;
            }
            $typeCounts[$typeLabel]++;

            $role = strtoupper(trim((string) ($ticket['current_handler_role'] ?? '')));
            if (!isset($handlerCounts[$role])) {
                $handlerCounts['OTHER']++;
            } else {
                $handlerCounts[$role]++;
            }
        }

        usort($agingTickets, function ($a, $b) {
            return ($b['hours'] <=> $a['hours']);
        });

        arsort($typeCounts);
        $topType = 'N/A';
        $topTypeCount = 0;
        if (!empty($typeCounts)) {
            $topType = (string) array_key_first($typeCounts);
            $topTypeCount = (int) $typeCounts[$topType];
        }

        return [
            'total_count' => $total,
            'open_count' => $openCount,
            'active_count' => $activeCount,
            'closed_count' => $closedCount,
            'close_rate' => $closeRate,
            'open_rate' => $openRate,
            'active_rate' => $activeRate,
            'total_receivable' => round($totalReceivable, 2),
            'aging_over_24h' => $agingOver24h,
            'aging_tickets' => $agingTickets,
            'top_type' => $topType,
            'top_type_count' => $topTypeCount,
            'type_counts' => $typeCounts,
            'handler_counts' => $handlerCounts,
        ];
    }
}
