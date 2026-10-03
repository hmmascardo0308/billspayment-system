<?php
// export_recon.php
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../vendor/autoload.php';
date_default_timezone_set('Asia/Manila');

use Dompdf\Dompdf;
use Dompdf\Options;

session_start();

// ------------------------------------------------------------
// 0. Detect reference column (same as main page)
// ------------------------------------------------------------
$reference_column = 'reference_no';
$check_col = $conn->query("
    SELECT COLUMN_NAME
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = 'mldb'
      AND TABLE_NAME = 'billspayment_transaction'
      AND (COLUMN_NAME LIKE '%ref%' OR COLUMN_NAME LIKE '%reference%')
");
if ($check_col && $check_col->num_rows > 0) {
    $c = $check_col->fetch_assoc();
    $reference_column = $c['COLUMN_NAME'];
    $check_col->free();
}

// ------------------------------------------------------------
// 1. Fetch partners (needed for name resolution)
// ------------------------------------------------------------
$partners = [];
$result_partners = $conn->query("
    SELECT partner_id_kpx, partner_name
    FROM masterdata.partner_masterfile
    WHERE partner_id_kpx IS NOT NULL
      AND partner_name IS NOT NULL
    ORDER BY partner_name ASC
");
if ($result_partners) {
    while ($row = $result_partners->fetch_assoc()) {
        $partners[] = $row;
    }
    $result_partners->free();
}

// ------------------------------------------------------------
// 2. Read filters (same as main page)
// ------------------------------------------------------------
$partner_id   = isset($_GET['partner']) ? trim($_GET['partner']) : '';
$date_from    = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to      = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$reference_no = isset($_GET['reference_no']) ? trim($_GET['reference_no']) : '';

if ($reference_no === '' && $partner_id === '') {
    die('Either Reference No or Partner is required for export.');
}
if ($partner_id !== '' && ($date_from === '' || $date_to === '')) {
    die('When filtering by Partner, both Date From and Date To are required.');
}
if ($date_from !== '' && $date_to !== '' && $date_from > $date_to) {
    die('Date From cannot be later than Date To.');
}

// Resolve partner name
$partner_name = '';
if ($partner_id !== '') {
    foreach ($partners as $p) {
        if ((string)$p['partner_id_kpx'] === (string)$partner_id) {
            $partner_name = $p['partner_name'];
            break;
        }
    }
}

// ------------------------------------------------------------
// 3. LOCAL (Bills Payment) QUERY
// ------------------------------------------------------------
$sql = "
    SELECT
        t.partner_id_kpx,
        p.partner_name,
        t.`{$reference_column}` AS reference_no,
        t.datetime,
        t.amount_paid,
        t.charge_to_customer,
        t.charge_to_partner,
        t.branch_id,
        t.outlet
    FROM mldb.billspayment_transaction t
    LEFT JOIN masterdata.partner_masterfile p
        ON t.partner_id_kpx = p.partner_id_kpx
    WHERE 1=1
";
$params = [];
$types  = '';

if ($reference_no !== '') {
    $sql .= " AND t.`{$reference_column}` = ?";
    $params[] = $reference_no;
    $types   .= 's';
}
if ($partner_id !== '') {
    $sql .= " AND t.partner_id_kpx = ?";
    $params[] = $partner_id;
    $types   .= 's';
}
if ($date_from !== '') {
    $sql .= " AND t.datetime >= ?";
    $params[] = $date_from . ' 00:00:00';
    $types   .= 's';
}
if ($date_to !== '') {
    $sql .= " AND t.datetime <= ?";
    $params[] = $date_to . ' 23:59:59';
    $types   .= 's';
}
$sql .= " ORDER BY t.datetime DESC LIMIT 500";

$local_rows = [];
$stmt = $conn->prepare($sql);
if ($stmt) {
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $local_rows[] = $row;
    }
    $stmt->close();
}

// Collect references
$refs = [];
foreach ($local_rows as $lr) {
    $r = trim($lr['reference_no'] ?? '');
    if ($r !== '') $refs[$r] = true;
}
$refs = array_keys($refs);

// ------------------------------------------------------------
// 4. EXACTDB (GLE) QUERY — two-pass
// ------------------------------------------------------------
$gle_by_ref = [];
$should_query_gle = ($reference_no !== '' || !empty($refs) || ($partner_id !== '' && $partner_name !== ''));

if ($should_query_gle) {
    // ------------------------------------------------------
    // PASS 1 — Find candidate references
    //
    // Split into TWO separate queries so MySQL can pick the
    // right index for each branch:
    //   Query A: reference-based lookup  -> idx_gle_reference_only
    //   Query B: commission 428101 lookup -> idx_gle_glcode_datetime_ref
    //
    // (Previously this was a single query with OR across columns,
    //  which prevented index selection.)
    // ------------------------------------------------------
    $candidate_refs = [];

    // Shared date-range fragments
    $date_where  = '';
    $date_params = [];
    $date_types  = '';
    if ($date_from !== '') {
        $date_where   .= " AND datetime >= ?";
        $date_params[] = $date_from . ' 00:00:00';
        $date_types   .= 's';
    }
    if ($date_to !== '') {
        $date_where   .= " AND datetime <= ?";
        $date_params[] = $date_to . ' 23:59:59';
        $date_types   .= 's';
    }

    // ------------------------------------------------------
    // PASS 1A — Reference-based lookup
    //   Covers: explicit reference from form + references
    //           discovered from local BP rows
    // ------------------------------------------------------
    $refs_to_lookup = [];
    if ($reference_no !== '') {
        $refs_to_lookup[$reference_no] = true;
    }
    foreach ($refs as $r) {
        if ($r !== '') $refs_to_lookup[$r] = true;
    }
    $refs_to_lookup = array_keys($refs_to_lookup);

    if (!empty($refs_to_lookup)) {
        $ph = implode(',', array_fill(0, count($refs_to_lookup), '?'));

        $sql1a = "SELECT DISTINCT reference FROM gle
                  WHERE reference IN ($ph)";

        $p1a_params = $refs_to_lookup;
        $p1a_types  = str_repeat('s', count($refs_to_lookup));

        if ($date_where !== '') {
            $sql1a      .= $date_where;
            $p1a_params  = array_merge($p1a_params, $date_params);
            $p1a_types  .= $date_types;
        }

        $stmt1a = $conn_exact->prepare($sql1a);
        if ($stmt1a) {
            $stmt1a->bind_param($p1a_types, ...$p1a_params);
            $stmt1a->execute();
            $res1a = $stmt1a->get_result();
            while ($r = $res1a->fetch_assoc()) {
                $rref = trim($r['reference'] ?? '');
                if ($rref !== '') {
                    $candidate_refs[$rref] = true;
                }
            }
            $stmt1a->close();
        }
    }

    // ------------------------------------------------------
    // PASS 1B — Commission (428101) lookup by partner name
    //   Finds GLE-only references (no BP row) via desc match.
    //   Uses idx_gle_glcode_datetime_ref (gl_code, datetime, reference).
    //
    //   NOTE: SQL does a coarse LIKE '%name%' (non-sargable but
    //   narrow because gl_code + datetime range prune the set).
    //   PHP then applies a strict word-boundary regex to avoid
    //   false positives like "ANECO" matching "ZANECO".
    // ------------------------------------------------------
    if ($partner_id !== '' && $partner_name !== '') {
        $sql1b = "SELECT DISTINCT reference, `desc` FROM gle
                  WHERE gl_code = '428101'
                    AND `desc` LIKE ?";
        $p1b_params = ['%' . $partner_name . '%'];
        $p1b_types  = 's';

        if ($date_where !== '') {
            $sql1b      .= $date_where;
            $p1b_params  = array_merge($p1b_params, $date_params);
            $p1b_types  .= $date_types;
        }

        $stmt1b = $conn_exact->prepare($sql1b);
        if ($stmt1b) {
            $stmt1b->bind_param($p1b_types, ...$p1b_params);
            $stmt1b->execute();
            $res1b = $stmt1b->get_result();

            // Strict word-boundary match: partner name must be
            // bounded by start/end or non-alphanumeric chars.
            // Prevents "ANECO" from matching "ZANECO".
            $word_boundary_pattern =
                '/(^|[^A-Za-z0-9])' . preg_quote($partner_name, '/') . '([^A-Za-z0-9]|$)/i';

            while ($r = $res1b->fetch_assoc()) {
                $rref  = trim($r['reference'] ?? '');
                $rdesc = (string)($r['desc'] ?? '');
                if ($rref !== '' && preg_match($word_boundary_pattern, $rdesc)) {
                    $candidate_refs[$rref] = true;
                }
            }
            $stmt1b->close();
        }
    }

    $candidate_refs = array_keys($candidate_refs);

    // ------------------------------------------------------
    // PASS 2 — Pull ALL GLE rows for every candidate reference
    // ------------------------------------------------------
    if (!empty($candidate_refs)) {
        $ph = implode(',', array_fill(0, count($candidate_refs), '?'));
        $pass2_sql = "
            SELECT datetime, gl_code, gl_desc, partner_id, `desc`, reference,
                   entry_number, currency, debit, credit, transaction_type,
                   branch_id, branch_code, item, main_zone, zone, region
            FROM gle
            WHERE reference IN ($ph)
        ";
        $p2_params = $candidate_refs;
        $p2_types  = str_repeat('s', count($candidate_refs));

        if ($date_from !== '') {
            $pass2_sql .= " AND datetime >= ?";
            $p2_params[] = $date_from . ' 00:00:00';
            $p2_types   .= 's';
        }
        if ($date_to !== '') {
            $pass2_sql .= " AND datetime <= ?";
            $p2_params[] = $date_to . ' 23:59:59';
            $p2_types   .= 's';
        }
        $pass2_sql .= " ORDER BY reference ASC, entry_number ASC, gl_code ASC LIMIT 20000";

        $stmt2 = $conn_exact->prepare($pass2_sql);
        if ($stmt2) {
            if ($p2_types !== '') $stmt2->bind_param($p2_types, ...$p2_params);
            $stmt2->execute();
            $res2 = $stmt2->get_result();
            while ($row = $res2->fetch_assoc()) {
                $ref = trim($row['reference'] ?? '');
                if ($ref !== '') {
                    $gle_by_ref[$ref][] = $row;
                }
            }
            $stmt2->close();
        }
    }
}

// ------------------------------------------------------------
// 5. Reconcile (same logic as main page)
// ------------------------------------------------------------
$transactions = [];
$total_amount_paid = 0;
$total_charge_customer = 0;
$total_charge_partner = 0;
$total_debit = 0;
$total_credit = 0;
$matched_count = 0;
$unmatched_local = 0;
$unmatched_gle = 0;

$local_by_ref = [];
foreach ($local_rows as $lr) {
    $r = trim($lr['reference_no'] ?? '');
    if ($r !== '') $local_by_ref[$r][] = $lr;
}

$all_refs = [];
foreach ($local_by_ref as $ref => $_) $all_refs[$ref] = true;
foreach ($gle_by_ref as $ref => $_) $all_refs[$ref] = true;

$all_ref_keys = array_keys($all_refs);
sort($all_ref_keys, SORT_NATURAL | SORT_FLAG_CASE);

foreach ($all_ref_keys as $ref) {
    $local_group = $local_by_ref[$ref] ?? [];
    $gle_rows    = $gle_by_ref[$ref]   ?? [];

    $has_local = !empty($local_group);
    $has_gle   = !empty($gle_rows);

    if ($has_local && $has_gle) {
        $match_status = 'MATCHED';
        $matched_count++;
    } elseif ($has_local && !$has_gle) {
        $match_status = 'UNMATCHED (BP only)';
        $unmatched_local++;
    } else {
        $match_status = 'UNMATCHED (GLE only)';
        $unmatched_gle++;
    }

    $amt = 0; $chg_c = 0; $chg_p = 0; $local_meta = null;
    foreach ($local_group as $lg) {
        $amt   += (float)($lg['amount_paid'] ?? 0);
        $chg_c += (float)($lg['charge_to_customer'] ?? 0);
        $chg_p += (float)($lg['charge_to_partner'] ?? 0);
        if ($local_meta === null) $local_meta = $lg;
    }

    $gle_debit_total = 0; $gle_credit_total = 0;
    foreach ($gle_rows as $g) {
        $gle_debit_total  += (float)($g['debit'] ?? 0);
        $gle_credit_total += (float)($g['credit'] ?? 0);
    }

    usort($gle_rows, function ($a, $b) {
        $cmp = strcmp((string)($a['entry_number'] ?? ''), (string)($b['entry_number'] ?? ''));
        if ($cmp !== 0) return $cmp;
        return strcmp((string)($a['gl_code'] ?? ''), (string)($b['gl_code'] ?? ''));
    });

    $first_gle = $gle_rows[0] ?? null;

    $transactions[] = [
        'partner_id_kpx'     => $local_meta['partner_id_kpx'] ?? null,
        'partner_name'       => $local_meta['partner_name']   ?? null,
        'reference_no'       => $ref,
        'datetime'           => $local_meta['datetime']       ?? ($first_gle['datetime'] ?? null),
        'amount_paid'        => $has_local ? $amt   : null,
        'charge_to_customer' => $has_local ? $chg_c : null,
        'charge_to_partner'  => $has_local ? $chg_p : null,
        'gle_row_count'      => count($gle_rows),
        'gle_debit_total'    => $has_gle ? $gle_debit_total  : null,
        'gle_credit_total'   => $has_gle ? $gle_credit_total : null,
        'gle_rows'           => $gle_rows,
        'gle_desc'           => $first_gle['desc'] ?? null,
        'match_status'       => $match_status,
        'has_local'          => $has_local,
        'has_gle'            => $has_gle,
    ];

    if ($has_local) {
        $total_amount_paid     += $amt;
        $total_charge_customer += $chg_c;
        $total_charge_partner  += $chg_p;
    }
    if ($has_gle) {
        $total_debit  += $gle_debit_total;
        $total_credit += $gle_credit_total;
    }
}

$total_bp_combined  = $total_amount_paid + $total_charge_customer + $total_charge_partner;
$variance_vs_debit  = $total_bp_combined - $total_debit;
$variance_vs_credit = $total_bp_combined - $total_credit;

// ------------------------------------------------------------
// 6. Build HTML for PDF
// ------------------------------------------------------------
$generated_by = $_SESSION['admin_email'] ?? ($_SESSION['user_email'] ?? 'Guest');
$generated_at = date('Y-m-d h:i:s A');

$filter_parts = [];
if ($reference_no !== '') $filter_parts[] = "Reference: " . htmlspecialchars($reference_no);
if ($partner_id !== '')   $filter_parts[] = "Partner: " . htmlspecialchars($partner_id . ($partner_name ? " - $partner_name" : ''));
if ($date_from !== '')    $filter_parts[] = "From: " . htmlspecialchars($date_from);
if ($date_to !== '')      $filter_parts[] = "To: " . htmlspecialchars($date_to);
$filter_summary = implode(' | ', $filter_parts);

$html = '
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
    @page { margin: 20px 15px; }
    body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 8px; color: #222; }
    h1 { font-size: 14px; margin: 0 0 4px 0; color: #1a3c5e; }
    .meta { font-size: 8px; color: #555; margin-bottom: 6px; }
    .meta strong { color: #000; }
    .summary-table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
    .summary-table td { border: 1px solid #ccc; padding: 3px 5px; font-size: 8px; }
    .summary-table .label { background: #f4f4f4; font-weight: bold; color: #333; width: 18%; }
    .summary-table .value { text-align: right; font-weight: bold; }
    table.data { width: 100%; border-collapse: collapse; }
    table.data th, table.data td { border: 1px solid #bbb; padding: 2px 3px; vertical-align: top; }
    table.data thead th {
        background: #ff4141; color: #fff; font-size: 7.5px; text-align: center;
    }
    table.data thead tr.group th { background: #ff0000; font-size: 8px; }
    table.data thead tr.group th.gle-group { background: #9f0000; }
    .status-matched    { color: #2e7d32; font-weight: bold; }
    .status-bp-only    { color: #c62828; font-weight: bold; }
    .status-gle-only   { color: #ef6c00; font-weight: bold; }
    .num { text-align: right; }
    .center { text-align: center; }
    .child td { background: #fafafa; font-size: 7px; }
    tfoot td { background: #f0f0f0; font-weight: bold; }
    .variance-ok   { color: #2e7d32; font-weight: bold; }
    .variance-bad  { color: #c62828; font-weight: bold; }
</style>
</head>
<body>
    <h1>Bills Payment vs GLE Reconciliation Report</h1>
    <div class="meta">
        <strong>Filters:</strong> ' . ($filter_summary ?: 'None') . '<br>
        <strong>Generated by:</strong> ' . htmlspecialchars($generated_by) . ' &nbsp;|&nbsp;
        <strong>Generated at:</strong> ' . htmlspecialchars($generated_at) . '
    </div>

    <table class="summary-table">
        <tr>
            <td class="label">Amount Paid</td>
            <td class="value">' . number_format($total_amount_paid, 2) . '</td>
            <td class="label">Charge to Customer</td>
            <td class="value">' . number_format($total_charge_customer, 2) . '</td>
            <td class="label">Charge to Partner</td>
            <td class="value">' . number_format($total_charge_partner, 2) . '</td>
            <td class="label">Bills Payment Total Amount</td>
            <td class="value">' . number_format($total_bp_combined, 2) . '</td>
        </tr>
        <tr>
            <td class="label">GLE Debit</td>
            <td class="value">' . number_format($total_debit, 2) . '</td>
            <td class="label">GLE Credit</td>
            <td class="value">' . number_format($total_credit, 2) . '</td>
            <td class="label">Matched</td>
            <td class="value">' . $matched_count . '</td>
            <td class="label">BP-only / GLE-only</td>
            <td class="value">' . $unmatched_local . ' / ' . $unmatched_gle . '</td>
        </tr>
        <tr>
            <td class="label">Bills Payment Total Amount - GLE Debit</td>
            <td class="value ' . ($variance_vs_debit == 0 ? 'variance-ok' : 'variance-bad') . '">' . number_format($variance_vs_debit, 2) . '</td>
            <td class="label">Bills Payment Total Amount - GLE Credit</td>
            <td class="value ' . ($variance_vs_credit == 0 ? 'variance-ok' : 'variance-bad') . '">' . number_format($variance_vs_credit, 2) . '</td>
            <td colspan="4"></td>
        </tr>
    </table>
';

if (empty($transactions)) {
    $html .= '<p style="text-align:center; color:#888;">No transactions found for the selected filters.</p>';
} else {
    $html .= '
    <table class="data">
        <thead>
            <tr class="group">
                <th colspan="7">Bills Payment Transaction</th>
                <th colspan="8" class="gle-group">GLE Records</th>
            </tr>
            <tr>
                <th>Status</th>
                <th>Partner</th>
                <th>Reference</th>
                <th>Transaction Date</th>
                <th>Amt Paid</th>
                <th>Chg Cust</th>
                <th>Chg Partr</th>
                <th>GL Code</th>
                <th>GL Desc</th>
                <th>Partner</th>
                <th>Reference</th>
                <th>Transaction Date</th>
                <th>Debit</th>
                <th>Credit</th>
                <th>Entry No.</th>
            </tr>
        </thead>
        <tbody>
    ';

    foreach ($transactions as $row) {
        $child_rows  = $row['gle_rows'] ?? [];
        $has_match   = ($row['match_status'] === 'MATCHED');
        $is_bp_only  = ($row['match_status'] === 'UNMATCHED (BP only)');
        $is_gle_only = ($row['match_status'] === 'UNMATCHED (GLE only)');

        if (!empty($row['partner_id_kpx']) || !empty($row['partner_name'])) {
            $partner_display = trim(($row['partner_id_kpx'] ?? '') . ' - ' . ($row['partner_name'] ?? ''), ' -');
        } elseif (!empty($row['gle_desc'])) {
            $partner_display = '(GLE) ' . $row['gle_desc'];
        } else {
            $partner_display = '—';
        }

        $status_class = $has_match ? 'status-matched' : ($is_bp_only ? 'status-bp-only' : 'status-gle-only');

        // Parent row (BP side)
        $html .= '<tr>';
        $html .= '<td class="center ' . $status_class . '">' . htmlspecialchars($row['match_status']) . '</td>';
        $html .= '<td>' . htmlspecialchars($partner_display) . '</td>';
        $html .= '<td>' . htmlspecialchars($row['reference_no'] ?? '—') . '</td>';
        $html .= '<td>' . htmlspecialchars($row['has_local'] ? ($row['datetime'] ?? '—') : '—') . '</td>';
        $html .= '<td class="num">' . ($row['has_local'] && $row['amount_paid'] !== null ? number_format((float)$row['amount_paid'], 2) : '—') . '</td>';
        $html .= '<td class="num">' . ($row['has_local'] && $row['charge_to_customer'] !== null ? number_format((float)$row['charge_to_customer'], 2) : '—') . '</td>';
        $html .= '<td class="num">' . ($row['has_local'] && $row['charge_to_partner'] !== null ? number_format((float)$row['charge_to_partner'], 2) : '—') . '</td>';

        if (!empty($child_rows) && count($child_rows) === 1) {
            $single = $child_rows[0];
            $html .= '<td class="num">' . htmlspecialchars($single['gl_code'] ?? '—') . '</td>';
            $html .= '<td>' . htmlspecialchars($single['gl_desc'] ?? '—') . '</td>';
            $html .= '<td>' . htmlspecialchars($single['desc'] ?? '—') . '</td>';
            $html .= '<td>' . htmlspecialchars($single['reference'] ?? '—') . '</td>';
            $html .= '<td>' . htmlspecialchars($single['datetime'] ?? '—') . '</td>';
            $html .= '<td class="num">' . number_format((float)($single['debit'] ?? 0), 2) . '</td>';
            $html .= '<td class="num">' . number_format((float)($single['credit'] ?? 0), 2) . '</td>';
            $html .= '<td>' . htmlspecialchars($single['entry_number'] ?? '—') . '</td>';
        } elseif (!empty($child_rows)) {
            // Multiple GLE rows – show summary on parent
            $html .= '<td class="num">(' . count($child_rows) . ' rows)</td>';
            $html .= '<td>—</td>';
            $html .= '<td>—</td>';
            $html .= '<td>' . htmlspecialchars($row['reference_no'] ?? '—') . '</td>';
            $html .= '<td>—</td>';
            $html .= '<td class="num">' . number_format((float)$row['gle_debit_total'], 2) . '</td>';
            $html .= '<td class="num">' . number_format((float)$row['gle_credit_total'], 2) . '</td>';
            $html .= '<td>—</td>';
        } else {
            $html .= '<td class="center">—</td><td>—</td><td>—</td><td>—</td><td>—</td><td>—</td><td>—</td><td>—</td>';
        }
        $html .= '</tr>';

        // Child rows (only when more than 1 GLE row)
        if (count($child_rows) > 1) {
            foreach ($child_rows as $g) {
                $html .= '<tr class="child">';
                $html .= '<td class="center">↳ GLE</td>';
                $html .= '<td></td><td></td><td></td><td></td><td></td><td></td>';
                $html .= '<td class="num">' . htmlspecialchars($g['gl_code'] ?? '—') . '</td>';
                $html .= '<td>' . htmlspecialchars($g['gl_desc'] ?? '—') . '</td>';
                $html .= '<td>' . htmlspecialchars($g['desc'] ?? '—') . '</td>';
                $html .= '<td>' . htmlspecialchars($g['reference'] ?? '—') . '</td>';
                $html .= '<td>' . htmlspecialchars($g['datetime'] ?? '—') . '</td>';
                $html .= '<td class="num">' . number_format((float)($g['debit'] ?? 0), 2) . '</td>';
                $html .= '<td class="num">' . number_format((float)($g['credit'] ?? 0), 2) . '</td>';
                $html .= '<td>' . htmlspecialchars($g['entry_number'] ?? '—') . '</td>';
                $html .= '</tr>';
            }
        }
    }

    $html .= '
        </tbody>
        <tfoot>
            <tr>
                <td colspan="4" class="num">TOTAL</td>
                <td class="num">' . number_format($total_amount_paid, 2) . '</td>
                <td class="num">' . number_format($total_charge_customer, 2) . '</td>
                <td class="num">' . number_format($total_charge_partner, 2) . '</td>
                <td colspan="5"></td>
                <td class="num">' . number_format($total_debit, 2) . '</td>
                <td class="num">' . number_format($total_credit, 2) . '</td>
                <td></td>
            </tr>
            <tr>
                <td colspan="4" class="num">Total (Amount + Charges)</td>
                <td colspan="3" style="text-align: center;">' . number_format($total_bp_combined, 2) . '</td>
                <td colspan="8"></td>
            </tr>
        </tfoot>
    </table>
    ';
}

$html .= '</body></html>';

// ------------------------------------------------------------
// 7. Render PDF
// ------------------------------------------------------------
$options = new Options();
$options->set('isRemoteEnabled', true);
$options->set('isHtml5ParserEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();

$filename = 'Recon_Report_' . date('Ymd_His') . '.pdf';
$dompdf->stream($filename, ['Attachment' => false]);
exit;