<?php
// Controller: fetch and optionally export tickets for maintenance UI
include_once __DIR__ . '/../../support_ticket/includes/bootstrap.php';
include_once __DIR__ . '/../../support_ticket/includes/ticket_queries.php';
require_once __DIR__ . '/../../../config/config.php';


// Require appropriate permission for API access
st_require_permission_api(['Support Ticket Report']);

$search = trim((string)($_REQUEST['search'] ?? ''));
$date_from = trim((string)($_REQUEST['date_from'] ?? ''));
$date_to = trim((string)($_REQUEST['date_to'] ?? ''));
$status = trim((string)($_REQUEST['status'] ?? ''));
$payment_branch = trim((string)($_REQUEST['payment_branch'] ?? ''));
$request_type = trim((string)($_REQUEST['request_type'] ?? ''));
$source = trim((string)($_REQUEST['source'] ?? ''));
$export = isset($_REQUEST['export']) && ($_REQUEST['export'] === '1' || $_REQUEST['export'] === 'true' || $_REQUEST['export'] === 'on');

$where = 'WHERE 1=1';
$params = [];
$types = '';

if ($search !== '') {
    $where .= " AND (t.ticket_number LIKE ? OR t.reference_number LIKE ? OR ti.ref_no LIKE ? OR ti.account_no LIKE ? OR p.partner_name LIKE ? OR ti.type_of_request LIKE ? )";
    $q = '%' . $search . '%';
    for ($i = 0; $i < 6; $i++) { $params[] = $q; $types .= 's'; }
}

if ($date_from !== '') { $where .= ' AND DATE(t.created_at) >= ?'; $params[] = $date_from; $types .= 's'; }
if ($date_to !== '') { $where .= ' AND DATE(t.created_at) <= ?'; $params[] = $date_to; $types .= 's'; }

if ($status !== '' && strtolower($status) !== 'all') {
    if (strpos($status, ',') !== false) {
        $parts = array_values(array_filter(array_map('trim', explode(',', $status))));
        if (count($parts) > 0) {
            $ph = implode(',', array_fill(0, count($parts), '?'));
            $where .= " AND t.status IN ({$ph})";
            foreach ($parts as $p) { $params[] = $p; $types .= 's'; }
        }
    } else {
        $where .= ' AND t.status = ?';
        $params[] = $status; $types .= 's';
    }
}

if ($payment_branch !== '') {
    $where .= ' AND (ti.payment_branch_id = ? OR ti.payment_branch_name LIKE ?)';
    $params[] = $payment_branch; $params[] = '%' . $payment_branch . '%'; $types .= 'ss';
}

if ($request_type !== '') {
    if (is_numeric($request_type)) {
        $where .= ' AND ti.ticket_type_id = ?';
        $params[] = (int)$request_type; $types .= 'i';
    } else {
        $where .= ' AND (tt.label LIKE ? OR ti.type_of_request LIKE ?)';
        $params[] = '%' . $request_type . '%'; $params[] = '%' . $request_type . '%'; $types .= 'ss';
    }
}

if ($source !== '') { $where .= ' AND t.source = ?'; $params[] = $source; $types .= 's'; }

$rows = st_fetch_tickets($conn, $where, $params, $types);

if ($export) {
    $filename = 'tickets_export_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    // Header row
    fputcsv($out, ['ID','Ticket Number','Reference','Source','Partner','Status','Ticket Type','Request Type','Payment Branch','Amount','Created At']);
    foreach ($rows as $r) {
        $line = [
            $r['id'] ?? '',
            $r['ticket_number'] ?? '',
            $r['reference_number'] ?? '',
            $r['source'] ?? '',
            $r['partner_name'] ?? ($r['partner_ext_id'] ?? ''),
            $r['status'] ?? '',
            $r['ticket_type_label'] ?? '',
            $r['type_of_request'] ?? '',
            $r['payment_branch_name'] ?? '',
            isset($r['amount']) ? $r['amount'] : '',
            $r['created_at'] ?? '',
        ];
        fputcsv($out, $line);
    }
    fclose($out);
    exit;
}

st_json(true, 'Fetched', $rows);
