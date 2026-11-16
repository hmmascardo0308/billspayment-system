<?php
// export_recon.php
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../vendor/autoload.php';
date_default_timezone_set('Asia/Manila');

use Dompdf\Dompdf;
use Dompdf\Options;

session_start();
set_time_limit(120);

// ------------------------------------------------------------
// Normalize partner names (same as main page)
// ------------------------------------------------------------
function normalizePartnerName($value) {
    $value = strtoupper(trim((string)$value));
    $value = str_replace(["\\'", "'"], '', $value);
    return preg_replace('/[^A-Z0-9]/', '', $value);
}

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
$display_mode = isset($_GET['display_mode']) ? trim($_GET['display_mode']) : 'variance';
if (!in_array($display_mode, ['variance', 'all', 'matched'], true)) {
    $display_mode = 'variance';
}

// Validation (aligned with main page)
if ($reference_no === '' && $partner_id === '' && ($date_from === '' || $date_to === '')) {
    die('Enter a Reference No, select a Partner, or provide both Date From and Date To.');
}
if ($partner_id !== '' && $partner_id !== 'ALL' && ($date_from === '' || $date_to === '')) {
    die('When filtering by Partner, both Date From and Date To are required.');
}
if (($date_from === '') !== ($date_to === '') && $reference_no === '') {
    die('Please provide both Date From and Date To when filtering by date.');
}
if ($date_from !== '' && $date_to !== '' && $date_from > $date_to) {
    die('Date From cannot be later than Date To.');
}

$partner_name    = '';
$is_all_partners = ($partner_id === 'ALL');
if ($partner_id !== '' && !$is_all_partners) {
    foreach ($partners as $p) {
        if ((string)$p['partner_id_kpx'] === (string)$partner_id) {
            $partner_name = $p['partner_name'];
            break;
        }
    }
}

$date_only_search = (
    $reference_no === '' &&
    ($partner_id === '' || $is_all_partners) &&
    $date_from !== '' &&
    $date_to !== ''
);

// ------------------------------------------------------------
// 2b. SESSION CACHE — try to reuse recon results from main page
//     Main page must populate: $_SESSION['recon_export'][$key]
//     where $key = md5(partner|from|to|ref)  (see notes at end)
// ------------------------------------------------------------
$cache_key = md5($partner_id . '|' . $date_from . '|' . $date_to . '|' . $reference_no);
$transactions          = null;
$total_amount_paid     = 0;
$total_charge_customer = 0;
$total_charge_partner  = 0;
$total_debit           = 0;
$total_credit          = 0;
$matched_count         = 0;
$variance_count        = 0;
$unmatched_local       = 0;
$unmatched_gle         = 0;

if (isset($_SESSION['recon_export'][$cache_key]) && is_array($_SESSION['recon_export'][$cache_key])) {
    $cached = $_SESSION['recon_export'][$cache_key];
    $transactions          = $cached['transactions'];
    $total_amount_paid     = $cached['total_amount_paid'];
    $total_charge_customer = $cached['total_charge_customer'];
    $total_charge_partner  = $cached['total_charge_partner'];
    $total_debit           = $cached['total_debit'];
    $total_credit          = $cached['total_credit'];
    $matched_count         = $cached['matched_count'];
    $variance_count        = $cached['variance_count'];
    $unmatched_local       = $cached['unmatched_local'];
    $unmatched_gle         = $cached['unmatched_gle'];
}

// ------------------------------------------------------------
// 3. If not cached, run the (optimized) queries
// ------------------------------------------------------------
if ($transactions === null) {

    // ---------- LOCAL (Bills Payment) QUERY ----------
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
        WHERE t.outlet <> 'ML CEBU HEAD OFFICE'
    ";
    $params = [];
    $types  = '';

    if ($reference_no !== '') {
        $sql .= " AND t.`{$reference_column}` = ?";
        $params[] = $reference_no;
        $types   .= 's';
    }

    if ($partner_id !== '' && !$is_all_partners) {
        $sql .= " AND t.partner_id_kpx = ? AND p.partner_name = ?";
        $params[] = $partner_id;
        $params[] = $partner_name;
        $types   .= 'ss';
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

    if ($date_only_search) {
        $sql .= " AND (t.`{$reference_column}` LIKE 'APB%'
                    OR t.`{$reference_column}` LIKE 'EBP%'
                    OR t.`{$reference_column}` LIKE 'KBP%'
                    OR t.`{$reference_column}` LIKE 'WQP%')";
    }

    $sql .= " ORDER BY t.datetime DESC";

    if ($partner_id === '' && !$date_only_search && !$is_all_partners) {
        $sql .= " LIMIT 500";
    } elseif ($date_only_search || $is_all_partners) {
        $sql .= " LIMIT 3000";
    }

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

    $refs = [];
    foreach ($local_rows as $lr) {
        $r = trim($lr['reference_no'] ?? '');
        if ($r !== '') {
            $refs[$r] = true;
        }
    }
    $refs = array_keys($refs);

    // ---------- GLE QUERIES ----------
    $gle_by_ref = [];
    $should_query_gle = (
        $reference_no !== '' ||
        !empty($refs) ||
        ($partner_id !== '' && !$is_all_partners && $partner_name !== '') ||
        $date_only_search ||
        $is_all_partners
    );

    if ($should_query_gle) {

        $date_from_val = ($date_from !== '') ? $date_from . ' 00:00:00' : null;
        $date_to_val   = ($date_to   !== '') ? $date_to   . ' 23:59:59' : null;

        // ----------------------------------------------------------
        // 3a. Reference-based GLE lookup (indexed, chunked)
        // ----------------------------------------------------------
        $fetch_gle_by_refs = function (array $refs_in) use ($conn_exact, $date_from_val, $date_to_val) {
            $out = [];
            if (empty($refs_in)) return $out;

            $date_sql    = '';
            $date_params = [];
            $date_types  = '';
            if ($date_from_val !== null) { $date_sql .= " AND datetime >= ?"; $date_params[] = $date_from_val; $date_types .= 's'; }
            if ($date_to_val   !== null) { $date_sql .= " AND datetime <= ?"; $date_params[] = $date_to_val;   $date_types .= 's'; }

            foreach (array_chunk($refs_in, 500) as $chunk) {
                $ph = implode(',', array_fill(0, count($chunk), '?'));

                $sql = "
                    SELECT
                        no, datetime, gl_code, gl_desc, partner_id, `desc`, reference,
                        entry_number, currency, debit, credit, transaction_type,
                        branch_id, branch_code, item, main_zone, zone, region
                    FROM gle
                    FORCE INDEX (idx_gle_reference_datetime)
                    WHERE reference IN ($ph)" . $date_sql;

                $params = array_merge($chunk, $date_params);
                $types  = str_repeat('s', count($chunk)) . $date_types;

                $stmt = $conn_exact->prepare($sql);
                if (!$stmt) continue;
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $ref = trim($row['reference'] ?? '');
                    if ($ref !== '') $out[$ref][] = $row;
                }
                $stmt->close();
            }
            return $out;
        };

        // ----------------------------------------------------------
        // 3b. Partner-name GLE lookup (pushed into SQL, indexed)
        // ----------------------------------------------------------
        $fetch_gle_by_partner = function ($partner_name_in, $date_from_in, $date_to_in) use ($conn_exact) {
            $out = [];
            if ($partner_name_in === '') return $out;

            $date_sql    = '';
            $date_params = [];
            $date_types  = '';
            if ($date_from_in !== null) { $date_sql .= " AND datetime >= ?"; $date_params[] = $date_from_in; $date_types .= 's'; }
            if ($date_to_in   !== null) { $date_sql .= " AND datetime <= ?"; $date_params[] = $date_to_in;   $date_types .= 's'; }

            $sql = "
                SELECT
                    no, datetime, gl_code, gl_desc, partner_id, `desc`, reference,
                    entry_number, currency, debit, credit, transaction_type,
                    branch_id, branch_code, item, main_zone, zone, region
                FROM gle
                FORCE INDEX (idx_gle_gl_datetime_no)
                WHERE gl_code = '428101'" . $date_sql . "
                  AND (
                        `desc` = ?
                     OR `desc` LIKE ?
                     OR `desc` LIKE ?
                  )
            ";

            $params = array_merge($date_params, [
                $partner_name_in,
                $partner_name_in . '%',
                '%' . $partner_name_in . '%',
            ]);
            $types = $date_types . 'sss';

            $stmt = $conn_exact->prepare($sql);
            if (!$stmt) return $out;
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $ref = trim($row['reference'] ?? '');
                if ($ref !== '') $out[$ref][] = $row;
            }
            $stmt->close();
            return $out;
        };

        // ----------------------------------------------------------
        // 3c. Date-only GLE lookup (prefix UNION, indexed)
        // ----------------------------------------------------------
        $fetch_gle_date_only = function ($date_from_in, $date_to_in) use ($conn_exact) {
            $out = [];
            if ($date_from_in === null || $date_to_in === null) return $out;

            $prefixes = ['APB', 'EBP', 'KBP', 'WQP'];
            $branch_sql = [];
            $branch_params = [];
            $branch_types = '';

            foreach ($prefixes as $pfx) {
                $branch_sql[] = "(SELECT
                        no, datetime, gl_code, gl_desc, partner_id, `desc`,
                        reference, entry_number, currency, debit, credit,
                        transaction_type, branch_id, branch_code, item,
                        main_zone, zone, region
                    FROM gle FORCE INDEX (idx_ref_prefix_datetime)
                    WHERE reference >= ? AND reference < ?
                      AND datetime >= ? AND datetime <= ?)";
                $branch_params[] = $pfx;
                $branch_params[] = $pfx . '~';
                $branch_params[] = $date_from_in;
                $branch_params[] = $date_to_in;
                $branch_types   .= 'ssss';
            }

            $sql = implode(" UNION ALL ", $branch_sql) .
                   " ORDER BY reference ASC, entry_number ASC, gl_code ASC";

            $max_rows = 50000;

            $stmt = $conn_exact->prepare($sql);
            if (!$stmt) return $out;
            $stmt->bind_param($branch_types, ...$branch_params);
            $stmt->execute();
            $res = $stmt->get_result();

            $count = 0;
            while ($row = $res->fetch_assoc()) {
                if (++$count > $max_rows) break;
                $ref = trim($row['reference'] ?? '');
                if ($ref !== '') $out[$ref][] = $row;
            }
            $stmt->close();
            return $out;
        };

        // 1) Reference-based
        $refs_to_lookup = [];
        if ($reference_no !== '') $refs_to_lookup[$reference_no] = true;
        foreach ($refs as $r) if ($r !== '') $refs_to_lookup[$r] = true;
        $refs_to_lookup = array_keys($refs_to_lookup);

        if (!empty($refs_to_lookup)) {
            $gle_by_ref += $fetch_gle_by_refs($refs_to_lookup);
        }

        // 2) Partner-based (SQL now returns only close matches; similar_text is a safety net)
        if ($partner_id !== '' && !$is_all_partners && $partner_name !== '') {
            $partner_hits = $fetch_gle_by_partner($partner_name, $date_from_val, $date_to_val);

            if (!empty($partner_hits)) {
                $normalized_partner = normalizePartnerName($partner_name);
                $filtered = [];
                foreach ($partner_hits as $ref => $rows) {
                    foreach ($rows as $row) {
                        $rdesc = (string)($row['desc'] ?? '');
                        if ($rdesc === '') continue;
                        $norm = normalizePartnerName($rdesc);
                        $pct  = 0.0;
                        similar_text($normalized_partner, $norm, $pct);
                        if ($norm === $normalized_partner || $pct >= 95.0) {
                            $filtered[$ref][] = $row;
                        }
                    }
                }
                $partner_hits = $filtered;
            }

            foreach ($partner_hits as $ref => $rows) {
                foreach ($rows as $row) {
                    $gle_by_ref[$ref][] = $row;
                }
            }
        }

        // 3) Date-only / All partners
        if ($date_only_search || $is_all_partners) {
            $date_hits = $fetch_gle_date_only($date_from_val, $date_to_val);
            foreach ($date_hits as $ref => $rows) {
                foreach ($rows as $row) {
                    $gle_by_ref[$ref][] = $row;
                }
            }
        }

        // Dedup GLE rows (per reference, by `no`)
        foreach ($gle_by_ref as $ref => &$rows) {
            $seen = [];
            $deduped = [];
            foreach ($rows as $row) {
                $key = $row['no'] ?? '';
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $deduped[]  = $row;
                }
            }
            $rows = $deduped;
        }
        unset($rows);
    }

    // ---------- RECONCILE ----------
    $local_by_ref = [];
    foreach ($local_rows as $lr) {
        $r = trim($lr['reference_no'] ?? '');
        if ($r !== '') $local_by_ref[$r][] = $lr;
    }

    $all_refs = [];
    foreach ($local_by_ref as $ref => $_) $all_refs[$ref] = true;
    foreach ($gle_by_ref as $ref => $_) {
        if ($ref !== '' && $ref !== '__DESC_MATCH__') $all_refs[$ref] = true;
    }

    $all_ref_keys = array_keys($all_refs);
    sort($all_ref_keys, SORT_NATURAL | SORT_FLAG_CASE);

    $transactions = [];
    foreach ($all_ref_keys as $ref) {
        $local_group = $local_by_ref[$ref] ?? [];
        $gle_rows    = $gle_by_ref[$ref]   ?? [];

        $has_local = !empty($local_group);
        $has_gle   = !empty($gle_rows);

        $match_status = $has_local && $has_gle ? 'MATCHED'
                      : ($has_local ? 'UNMATCHED (BP only)' : 'UNMATCHED (GLE only)');

        $amt = 0; $chg_c = 0; $chg_p = 0;
        $local_meta = null;
        foreach ($local_group as $lg) {
            $amt   += (float)($lg['amount_paid']        ?? 0);
            $chg_c += (float)($lg['charge_to_customer'] ?? 0);
            $chg_p += (float)($lg['charge_to_partner']  ?? 0);
            if ($local_meta === null) $local_meta = $lg;
        }

        $gle_debit_total  = 0;
        $gle_credit_total = 0;
        foreach ($gle_rows as $g) {
            $gle_debit_total  += (float)($g['debit']  ?? 0);
            $gle_credit_total += (float)($g['credit'] ?? 0);
        }

        $bp_reference_total        = $amt + $chg_c + $chg_p;
        $reference_variance_debit  = $bp_reference_total - $gle_debit_total;
        $reference_variance_credit = $bp_reference_total - $gle_credit_total;

        if ($has_local && $has_gle) {
            if (abs($reference_variance_debit) < 0.01 || abs($reference_variance_credit) < 0.01) {
                $match_status = 'MATCHED';
                $matched_count++;
            } else {
                $match_status = 'VARIANCE';
                $variance_count++;
            }
        } elseif ($has_local && !$has_gle) {
            $unmatched_local++;
        } else {
            $unmatched_gle++;
        }

        usort($gle_rows, function ($a, $b) {
            $cmp = strcmp((string)($a['entry_number'] ?? ''), (string)($b['entry_number'] ?? ''));
            if ($cmp !== 0) return $cmp;
            $cmp = strcmp((string)($a['gl_code'] ?? ''), (string)($b['gl_code'] ?? ''));
            if ($cmp !== 0) return $cmp;
            return strcmp((string)($a['no'] ?? ''), (string)($b['no'] ?? ''));
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
            'bp_total'           => $has_local ? $bp_reference_total : null,
            'variance_debit'     => $has_local && $has_gle ? $reference_variance_debit : null,
            'variance_credit'    => $has_local && $has_gle ? $reference_variance_credit : null,
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

    // Store for reuse (bounded LRU: keep last 5 filter combinations)
    if (!isset($_SESSION['recon_export']) || !is_array($_SESSION['recon_export'])) {
        $_SESSION['recon_export'] = [];
    }
    $_SESSION['recon_export'][$cache_key] = [
        'transactions'          => $transactions,
        'total_amount_paid'     => $total_amount_paid,
        'total_charge_customer' => $total_charge_customer,
        'total_charge_partner'  => $total_charge_partner,
        'total_debit'           => $total_debit,
        'total_credit'          => $total_credit,
        'matched_count'         => $matched_count,
        'variance_count'        => $variance_count,
        'unmatched_local'       => $unmatched_local,
        'unmatched_gle'         => $unmatched_gle,
        'created_at'            => time(),
    ];
    if (count($_SESSION['recon_export']) > 5) {
        uasort($_SESSION['recon_export'], function ($a, $b) {
            return ($a['created_at'] ?? 0) <=> ($b['created_at'] ?? 0);
        });
        array_shift($_SESSION['recon_export']);
    }
}

// ------------------------------------------------------------
// 4. Apply display mode
// ------------------------------------------------------------
$display_transactions = $transactions;
if ($display_mode === 'variance') {
    $display_transactions = array_values(array_filter($transactions, function ($row) {
        return $row['match_status'] !== 'MATCHED';
    }));
} elseif ($display_mode === 'matched') {
    $display_transactions = array_values(array_filter($transactions, function ($row) {
        return $row['match_status'] === 'MATCHED';
    }));
}

$total_bp_combined  = $total_amount_paid + $total_charge_customer + $total_charge_partner;
$variance_vs_debit  = $total_bp_combined - $total_debit;
$variance_vs_credit = $total_bp_combined - $total_credit;

// ------------------------------------------------------------
// 5. Build HTML for PDF (unchanged markup, same fields)
// ------------------------------------------------------------
$generated_by = $_SESSION['admin_email'] ?? ($_SESSION['user_email'] ?? 'Guest');
$generated_at = date('Y-m-d h:i:s A');

$filter_parts = [];
if ($reference_no !== '') $filter_parts[] = "Reference: " . htmlspecialchars($reference_no);
if ($partner_id !== '')   $filter_parts[] = "Partner: " . htmlspecialchars($partner_id === 'ALL' ? 'ALL PARTNERS' : $partner_id . ($partner_name ? " - $partner_name" : ''));
if ($date_from !== '')    $filter_parts[] = "From: " . htmlspecialchars($date_from);
if ($date_to !== '')      $filter_parts[] = "To: " . htmlspecialchars($date_to);
if ($display_mode !== 'all') $filter_parts[] = "Display: " . htmlspecialchars(ucfirst($display_mode));
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
    table.data thead th { background: #ff4141; color: #fff; font-size: 7.5px; text-align: center; }
    table.data thead tr.group th { background: #ff0000; font-size: 8px; }
    table.data thead tr.group th.gle-group { background: #9f0000; }
    .status-matched  { color: #2e7d32; font-weight: bold; }
    .status-variance { color: #e65100; font-weight: bold; }
    .status-bp-only  { color: #c62828; font-weight: bold; }
    .status-gle-only { color: #ef6c00; font-weight: bold; }
    .num { text-align: right; }
    .center { text-align: center; }
    tfoot td { background: #f0f0f0; font-weight: bold; }
    .variance-ok  { color: #2e7d32; font-weight: bold; }
    .variance-bad { color: #c62828; font-weight: bold; }
    .bp-subrow { background: #fafbfc; font-size: 7px; color: #444; }
    .bp-subrow strong { color: #000; }
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
            <td class="label">Bills Payment Total</td>
            <td class="value">' . number_format($total_bp_combined, 2) . '</td>
        </tr>
        <tr>
            <td class="label">GLE Debit</td>
            <td class="value">' . number_format($total_debit, 2) . '</td>
            <td class="label">GLE Credit</td>
            <td class="value">' . number_format($total_credit, 2) . '</td>
            <td class="label">Matched / Variance</td>
            <td class="value">' . $matched_count . ' / ' . $variance_count . '</td>
            <td class="label">BP-only / GLE-only</td>
            <td class="value">' . $unmatched_local . ' / ' . $unmatched_gle . '</td>
        </tr>
        <tr>
            <td class="label">BP Total - GLE Debit</td>
            <td class="value ' . ($variance_vs_debit == 0 ? 'variance-ok' : 'variance-bad') . '">' . number_format($variance_vs_debit, 2) . '</td>
            <td class="label">BP Total - GLE Credit</td>
            <td class="value ' . ($variance_vs_credit == 0 ? 'variance-ok' : 'variance-bad') . '">' . number_format($variance_vs_credit, 2) . '</td>
            <td colspan="4"></td>
        </tr>
    </table>
';

if (empty($display_transactions)) {
    $html .= '<p style="text-align:center; color:#888;">No transactions found for the selected filters and display mode.</p>';
} else {
    $html .= '
    <table class="data">
        <thead>
            <tr class="group">
                <th colspan="4">Bills Payment Transaction</th>
                <th colspan="8" class="gle-group">GLE Records</th>
            </tr>
            <tr>
                <th>Status</th>
                <th>Partner</th>
                <th>Reference</th>
                <th>Transaction Date</th>
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

    foreach ($display_transactions as $row) {
        $child_rows  = $row['gle_rows'] ?? [];
        $has_match   = ($row['match_status'] === 'MATCHED');
        $is_variance = ($row['match_status'] === 'VARIANCE');
        $is_bp_only  = ($row['match_status'] === 'UNMATCHED (BP only)');
        $is_gle_only = ($row['match_status'] === 'UNMATCHED (GLE only)');
        $is_multi    = (($row['gle_row_count'] ?? 0) > 1);

        if (!empty($row['partner_id_kpx']) || !empty($row['partner_name'])) {
            $partner_display = trim(($row['partner_id_kpx'] ?? '') . ' - ' . ($row['partner_name'] ?? ''), ' -');
        } elseif (!empty($row['gle_desc'])) {
            $partner_display = $row['gle_desc'];
        } else {
            $partner_display = '—';
        }

        if ($has_match)        $status_class = 'status-matched';
        elseif ($is_variance)  $status_class = 'status-variance';
        elseif ($is_bp_only)   $status_class = 'status-bp-only';
        else                   $status_class = 'status-gle-only';

        if ($is_multi) {
            foreach ($child_rows as $g_index => $g) {
                $html .= '<tr>';
                if ($g_index === 0) {
                    $html .= '<td class="center ' . $status_class . '">' . htmlspecialchars($row['match_status']) . '</td>';
                    $html .= '<td>' . htmlspecialchars($partner_display) . '</td>';
                    $html .= '<td>' . htmlspecialchars($row['reference_no'] ?? '—') . '</td>';
                    $html .= '<td>' . htmlspecialchars($row['has_local'] ? ($row['datetime'] ?? '—') : '—') . '</td>';
                } else {
                    $html .= '<td class="center"></td><td></td><td></td><td></td>';
                }
                $html .= '<td class="num">' . htmlspecialchars($g['gl_code'] ?? '—') . '</td>';
                $html .= '<td>' . htmlspecialchars($g['gl_desc'] ?? '—') . '</td>';
                $html .= '<td>' . htmlspecialchars($g['desc'] ?? '—') . '</td>';
                $html .= '<td>' . htmlspecialchars($g['reference'] ?? '—') . '</td>';
                $html .= '<td>' . htmlspecialchars($g['datetime'] ?? '—') . '</td>';
                $html .= '<td class="num">' . number_format((float)($g['debit'] ?? 0), 2) . '</td>';
                $html .= '<td class="num">' . number_format((float)($g['credit'] ?? 0), 2) . '</td>';
                $html .= '<td>' . htmlspecialchars($g['entry_number'] ?? '—') . '</td>';
                $html .= '</tr>';

                if ($g_index === 0) {
                    $html .= '<tr class="bp-subrow">';
                    $html .= '<td colspan="4" class="num" style="border-top: none;">';
                    $html .= '<span style="text-transform: uppercase; font-size: 6px; color: #666;">Amt Paid:</span> <strong>' . ($row['has_local'] && $row['amount_paid'] !== null ? number_format((float)$row['amount_paid'], 2) : '—') . '</strong> &nbsp;&nbsp;';
                    $html .= '<span style="text-transform: uppercase; font-size: 6px; color: #666;">Chg Cust:</span> <strong>' . ($row['has_local'] && $row['charge_to_customer'] !== null ? number_format((float)$row['charge_to_customer'], 2) : '—') . '</strong> &nbsp;&nbsp;';
                    $html .= '<span style="text-transform: uppercase; font-size: 6px; color: #666;">Chg Partr:</span> <strong>' . ($row['has_local'] && $row['charge_to_partner'] !== null ? number_format((float)$row['charge_to_partner'], 2) : '—') . '</strong>';
                    $html .= '</td>';
                    $html .= '<td colspan="8" style="border-top: none;"></td>';
                    $html .= '</tr>';
                }
            }
        } else {
            $html .= '<tr>';
            $html .= '<td class="center ' . $status_class . '">' . htmlspecialchars($row['match_status']) . '</td>';
            $html .= '<td>' . htmlspecialchars($partner_display) . '</td>';
            $html .= '<td>' . htmlspecialchars($row['reference_no'] ?? '—') . '</td>';
            $html .= '<td>' . htmlspecialchars($row['has_local'] ? ($row['datetime'] ?? '—') : '—') . '</td>';

            if (!empty($child_rows)) {
                $single = $child_rows[0];
                $html .= '<td class="num">' . htmlspecialchars($single['gl_code'] ?? '—') . '</td>';
                $html .= '<td>' . htmlspecialchars($single['gl_desc'] ?? '—') . '</td>';
                $html .= '<td>' . htmlspecialchars($single['desc'] ?? '—') . '</td>';
                $html .= '<td>' . htmlspecialchars($single['reference'] ?? '—') . '</td>';
                $html .= '<td>' . htmlspecialchars($single['datetime'] ?? '—') . '</td>';
                $html .= '<td class="num">' . number_format((float)($single['debit'] ?? 0), 2) . '</td>';
                $html .= '<td class="num">' . number_format((float)($single['credit'] ?? 0), 2) . '</td>';
                $html .= '<td>' . htmlspecialchars($single['entry_number'] ?? '—') . '</td>';
            } else {
                $html .= '<td class="center">—</td><td>—</td><td>—</td><td>—</td><td>—</td><td>—</td><td>—</td><td>—</td>';
            }
            $html .= '</tr>';

            $html .= '<tr class="bp-subrow">';
            $html .= '<td colspan="4" class="num" style="border-top: none;">';
            $html .= '<span style="text-transform: uppercase; font-size: 6px; color: #666;">Amt Paid:</span> <strong>' . ($row['has_local'] && $row['amount_paid'] !== null ? number_format((float)$row['amount_paid'], 2) : '—') . '</strong> &nbsp;&nbsp;';
            $html .= '<span style="text-transform: uppercase; font-size: 6px; color: #666;">Chg Cust:</span> <strong>' . ($row['has_local'] && $row['charge_to_customer'] !== null ? number_format((float)$row['charge_to_customer'], 2) : '—') . '</strong> &nbsp;&nbsp;';
            $html .= '<span style="text-transform: uppercase; font-size: 6px; color: #666;">Chg Partr:</span> <strong>' . ($row['has_local'] && $row['charge_to_partner'] !== null ? number_format((float)$row['charge_to_partner'], 2) : '—') . '</strong>';
            $html .= '</td>';
            $html .= '<td colspan="8" style="border-top: none;"></td>';
            $html .= '</tr>';
        }
    }

    $html .= '
        </tbody>
        <tfoot>
            <tr>
                <td colspan="4" class="num"></td>
                <td colspan="5">TOTAL</td>
                <td class="num">' . number_format($total_debit, 2) . '</td>
                <td class="num">' . number_format($total_credit, 2) . '</td>
                <td></td>
            </tr>
            <tr>
                <td colspan="4" class="num" style="border-top: none;">';
    $html .= '<span style="text-transform: uppercase; font-size: 6px; color: #666;">Amt Paid:</span> <strong>' . number_format($total_amount_paid, 2) . '</strong> &nbsp;&nbsp;';
    $html .= '<span style="text-transform: uppercase; font-size: 6px; color: #666;">Chg Cust:</span> <strong>' . number_format($total_charge_customer, 2) . '</strong> &nbsp;&nbsp;';
    $html .= '<span style="text-transform: uppercase; font-size: 6px; color: #666;">Chg Partr:</span> <strong>' . number_format($total_charge_partner, 2) . '</strong> &nbsp;&nbsp;';
    $html .= '<span style="text-transform: uppercase; font-size: 6px; color: #666;">BP Total:</span> <strong>' . number_format($total_bp_combined, 2) . '</strong>';

    $html .= '      </td>
                <td colspan="8">
                </td>
            </tr>
        </tfoot>
    </table>
    ';
}

$html .= '</body></html>';

// ------------------------------------------------------------
// 6. Render PDF
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