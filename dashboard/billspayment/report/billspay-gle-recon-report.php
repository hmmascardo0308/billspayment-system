<?php
// Connect to the databases (both $conn and $conn_exact are defined in config.php)
// billspay-gle-recon-report.php
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../vendor/autoload.php';

// Start the session
session_start();

if (isset($_SESSION['user_type'])) {
    $current_user_email = '';
    if ($_SESSION['user_type'] === 'admin' && isset($_SESSION['admin_email'])) {
        $current_user_email = $_SESSION['admin_email'];
    } elseif ($_SESSION['user_type'] === 'user' && isset($_SESSION['user_email'])) {
        $current_user_email = $_SESSION['user_email'];
    }
}

// Normalize partner names
function normalizePartnerName($value) {
    $value = strtoupper(trim((string)$value));
    $value = str_replace(["\\'", "'"], '', $value);
    return preg_replace('/[^A-Z0-9]/', '', $value);
}

// ------------------------------------------------------------
// 0. Detect reference column (fallback safe)
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
// 1. FETCH PARTNERS FOR DROPDOWN
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
// 2. FETCH + RECONCILE TRANSACTIONS (BIDIRECTIONAL)
// ------------------------------------------------------------
$transactions   = [];
$filter_applied = false;
$debug_message  = '';

// Totals
$total_amount_paid     = 0;
$total_charge_customer = 0;
$total_charge_partner  = 0;
$total_debit           = 0;
$total_credit          = 0;
$matched_count         = 0;
$variance_count        = 0;
$unmatched_local       = 0;   // BP only
$unmatched_gle         = 0;   // GLE only

// Display mode
$display_mode = isset($_GET['display_mode']) ? trim($_GET['display_mode']) : 'variance';
if (!in_array($display_mode, ['variance', 'all', 'matched'], true)) {
    $display_mode = 'variance';
}

// Pagination
const RECON_PER_PAGE = 100;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($current_page < 1) $current_page = 1;

$total_refs          = 0;
$total_pages         = 1;
$page_offset         = 0;
$page_ref_keys       = [];
$pager_html          = '';

if (isset($_GET['filter'])) {
    $partner_id   = isset($_GET['partner']) ? trim($_GET['partner']) : '';
    $date_from    = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
    $date_to      = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
    $reference_no = isset($_GET['reference_no']) ? trim($_GET['reference_no']) : '';

    // Resolve partner name
    $partner_name = '';
    $is_all_partners = ($partner_id === 'ALL');
    
    if ($partner_id !== '' && !$is_all_partners) {
        foreach ($partners as $p) {
            if ((string)$p['partner_id_kpx'] === (string)$partner_id) {
                $partner_name = $p['partner_name'];
                break;
            }
        }
    }

    // ---------- Validation ----------
    if ($reference_no === '' && $partner_id === '' && ($date_from === '' || $date_to === '')) {
        $debug_message = "Enter a Reference No, select a Partner, or provide both Date From and Date To.";
    } elseif ($partner_id !== '' && !$is_all_partners && ($date_from === '' || $date_to === '')) {
        $debug_message = "When filtering by Partner, both Date From and Date To are required.";
    } elseif (($date_from === '') !== ($date_to === '') && $reference_no === '') {
        $debug_message = "Please provide both Date From and Date To when filtering by date.";
    } elseif ($date_from !== '' && $date_to !== '' && $date_from > $date_to) {
        $debug_message = "Date From cannot be later than Date To.";
    } else {
        $filter_applied = true;

        // ============================================================
        // LOCAL (Bills Payment) QUERY
        // ============================================================
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

        // Only apply specific partner filter if not 'ALL'
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

        $date_only_search = (
            $reference_no === '' &&
            ($partner_id === '' || $is_all_partners) &&
            $date_from !== '' &&
            $date_to !== ''
        );
        
        if ($date_only_search) {
            $sql .= " AND (t.`{$reference_column}` LIKE 'APB%' OR t.`{$reference_column}` LIKE 'EBP%' OR t.`{$reference_column}` LIKE 'KBP%' OR t.`{$reference_column}` LIKE 'WQP%')";
        }

        $sql .= " ORDER BY t.datetime DESC";
        if ($partner_id === '' && !$date_only_search && !$is_all_partners) {
            $sql .= " LIMIT 500";
        } elseif ($date_only_search || $is_all_partners) {
            $sql .= " LIMIT 3000"; // safety for "ALL" searches
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
        } else {
            $debug_message = "Local query error: " . $conn->error;
        }

        // Collect unique references
        $refs = [];
        foreach ($local_rows as $lr) {
            $r = trim($lr['reference_no'] ?? '');
            if ($r !== '') {
                $refs[$r] = true;
            }
        }
        $refs = array_keys($refs);

        // ============================================================
        // EXACTDB (GLE) QUERY
        // ============================================================
        $gle_by_ref = [];
        $should_query_gle = ($reference_no !== '' || !empty($refs) || ($partner_id !== '' && !$is_all_partners && $partner_name !== '') || $date_only_search || $is_all_partners);

        if ($should_query_gle) {

            $date_from_val = ($date_from !== '') ? $date_from . ' 00:00:00' : null;
            $date_to_val   = ($date_to   !== '') ? $date_to   . ' 23:59:59' : null;

            // Helper: pull GLE rows for a set of references
            $fetch_gle_by_refs = function (array $refs_in) use ($conn_exact, $date_from_val, $date_to_val, &$debug_message) {
                $out = [];
                if (empty($refs_in)) return $out;

                $date_sql    = '';
                $date_params = [];
                $date_types  = '';
                if ($date_from_val !== null) { $date_sql .= " AND datetime >= ?"; $date_params[] = $date_from_val; $date_types .= 's'; }
                if ($date_to_val   !== null) { $date_sql .= " AND datetime <= ?"; $date_params[] = $date_to_val;   $date_types .= 's'; }

                $chunks = array_chunk($refs_in, 500);
                foreach ($chunks as $chunk) {
                    $ph = implode(',', array_fill(0, count($chunk), '?'));

                    $sql = "
                        SELECT 
                           no, datetime, gl_code, gl_desc, partner_id, `desc`, reference,
                            entry_number, currency, debit, credit, transaction_type,
                            branch_id, branch_code, item, main_zone, zone, region
                        FROM gle
                        FORCE INDEX (idx_gle_reference_datetime)
                        WHERE reference IN ($ph)" . $date_sql . "
                    ";

                    $params = array_merge($chunk, $date_params);
                    $types  = str_repeat('s', count($chunk)) . $date_types;

                    $stmt = $conn_exact->prepare($sql);
                    if (!$stmt) {
                        $debug_message .= ($debug_message ? ' | ' : '') . "GLE ref lookup error: " . $conn_exact->error;
                        continue;
                    }
                    $stmt->bind_param($types, ...$params);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    while ($row = $res->fetch_assoc()) {
                        $ref = trim($row['reference'] ?? '');
                        if ($ref !== '') {
                            $out[$ref][] = $row;
                        }
                    }
                    $stmt->close();
                }
                return $out;
            };

            // Helper: pull GLE 428101 rows matching partner desc
            $fetch_gle_by_partner = function ($partner_name_in, $date_from_in, $date_to_in) use ($conn_exact, &$debug_message) {
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
                if (!$stmt) {
                    $debug_message .= ($debug_message ? ' | ' : '') . "GLE partner lookup error: " . $conn_exact->error;
                    return $out;
                }
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $ref = trim($row['reference'] ?? '');
                    if ($ref !== '') {
                        $out[$ref][] = $row;
                    }
                }
                $stmt->close();
                return $out;
            };

            // Helper: date-only lookup
            $fetch_gle_date_only = function ($date_from_in, $date_to_in) use ($conn_exact, &$debug_message) {
                $out = [];

                if ($date_from_in === null || $date_to_in === null) {
                    return $out;
                }

                $prefixes = ['APB', 'EBP', 'KBP', 'WQP'];

                $branch_sql = [];
                $branch_params = [];
                $branch_types = '';
                foreach ($prefixes as $pfx) {
                    $branch_sql[]   = "(SELECT no, datetime, gl_code, gl_desc, partner_id, `desc`,
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
                if (!$stmt) {
                    $debug_message .= ($debug_message ? ' | ' : '') . "GLE date-only lookup error: " . $conn_exact->error;
                    return $out;
                }
                $stmt->bind_param($branch_types, ...$branch_params);
                $stmt->execute();
                $res = $stmt->get_result();

                $count = 0;
                while ($row = $res->fetch_assoc()) {
                    if (++$count > $max_rows) {
                        $debug_message .= ($debug_message ? ' | ' : '') .
                            "Date-only search capped at {$max_rows} GLE rows. Please narrow the date range.";
                        break;
                    }
                    $ref = trim($row['reference'] ?? '');
                    if ($ref !== '') {
                        $out[$ref][] = $row;
                    }
                }
                $stmt->close();

                return $out;
            };

            // 1) Reference-based pull
            $refs_to_lookup = [];
            if ($reference_no !== '') {
                $refs_to_lookup[$reference_no] = true;
            }
            foreach ($refs as $r) {
                if ($r !== '') $refs_to_lookup[$r] = true;
            }
            $refs_to_lookup = array_keys($refs_to_lookup);

            if (!empty($refs_to_lookup)) {
                $gle_by_ref += $fetch_gle_by_refs($refs_to_lookup);
            }

            // 2) Partner-based pull
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

            // 3) Date-only / All partners pull
            if (
                $reference_no === '' &&
                ($partner_id === '' || $is_all_partners) &&
                $date_from !== '' &&
                $date_to !== ''
            ) {
                $date_hits = $fetch_gle_date_only($date_from_val, $date_to_val);
                foreach ($date_hits as $ref => $rows) {
                    foreach ($rows as $row) {
                        $gle_by_ref[$ref][] = $row;
                    }
                }
            }

            // Deduplicate GLE rows
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

        // ============================================================
        // RECONCILIATION
        // ============================================================
        $local_by_ref = [];
        foreach ($local_rows as $lr) {
            $r = trim($lr['reference_no'] ?? '');
            if ($r !== '') {
                $local_by_ref[$r][] = $lr;
            }
        }

        $all_refs = [];
        foreach ($local_by_ref as $ref => $_) { $all_refs[$ref] = true; }
        foreach ($gle_by_ref as $ref => $_) {
            if ($ref === '__DESC_MATCH__') continue;
            if ($ref !== '') { $all_refs[$ref] = true; }
        }

        $all_ref_keys = array_keys($all_refs);
        sort($all_ref_keys, SORT_NATURAL | SORT_FLAG_CASE);

        $total_refs  = count($all_ref_keys);
        $total_pages = $total_refs > 0 ? (int)ceil($total_refs / RECON_PER_PAGE) : 1;
        if ($current_page > $total_pages) $current_page = $total_pages;

        $page_offset   = ($current_page - 1) * RECON_PER_PAGE;
        $page_ref_keys = array_slice($all_ref_keys, $page_offset, RECON_PER_PAGE);

        foreach ($page_ref_keys as $ref) {
            $local_group = $local_by_ref[$ref] ?? [];
            $gle_rows    = $gle_by_ref[$ref]   ?? [];

            $has_local = !empty($local_group);
            $has_gle   = !empty($gle_rows);

            if ($has_local && $has_gle) {
                $match_status = 'MATCHED';
            } elseif ($has_local && !$has_gle) {
                $match_status = 'UNMATCHED (BP only)';
            } else {
                $match_status = 'UNMATCHED (GLE only)';
            }

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

            $bp_reference_total = $amt + $chg_c + $chg_p;
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
                'gle_partner_id'     => $first_gle['partner_id'] ?? null,
                'gle_desc'           => $first_gle['desc']       ?? null,
                'gle_datetime'       => $first_gle['datetime']   ?? null,
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

        // Build the pager HTML
        if ($total_pages > 1) {
            $shown_from = $page_offset + 1;
            $shown_to   = min($page_offset + RECON_PER_PAGE, $total_refs);

            $base_params = $_GET;
            unset($base_params['page']);
            $base_qs = http_build_query($base_params);

            $prev_url = '?' . $base_qs . '&page=' . max(1, $current_page - 1);
            $next_url = '?' . $base_qs . '&page=' . min($total_pages, $current_page + 1);

            $prev_disabled = ($current_page <= 1);
            $next_disabled = ($current_page >= $total_pages);

            $pager_html .= '<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">';
            $pager_html .=   '<span style="color:#555;">Showing <strong>' . $shown_from . '</strong>–<strong>' . $shown_to . '</strong> of <strong>' . $total_refs . '</strong> reference(s) &middot; Page ' . $current_page . ' of ' . $total_pages . '</span>';

            if ($prev_disabled) {
                $pager_html .= '<button type="button" disabled style="background:#f0f0f0;color:#aaa;border:1px solid #ccc;border-radius:4px;padding:3px 10px;cursor:not-allowed;">&laquo; Prev</button>';
            } else {
                $pager_html .= '<a href="' . htmlspecialchars($prev_url) . '" style="background:#f0f0f0;color:#333;border:1px solid #ccc;border-radius:4px;padding:3px 10px;text-decoration:none;">&laquo; Prev</a>';
            }

            if ($next_disabled) {
                $pager_html .= '<button type="button" disabled style="background:#f0f0f0;color:#aaa;border:1px solid #ccc;border-radius:4px;padding:3px 10px;cursor:not-allowed;">Next &raquo;</button>';
            } else {
                $pager_html .= '<a href="' . htmlspecialchars($next_url) . '" style="background:#b30000;color:#fff;border:1px solid #8b0000;border-radius:4px;padding:3px 10px;text-decoration:none;">Next &raquo;</a>';
            }

            $pager_html .= '</div>';
        }
    }
}

// Combined total for Bills Payment
$total_bp_combined = $total_amount_paid + $total_charge_customer + $total_charge_partner;

// Display filtering (page-local set)
$display_transactions = $transactions;
if (isset($_GET['filter'])) {
    if ($display_mode === 'variance') {
        $display_transactions = array_values(array_filter($transactions, function ($row) {
            return $row['match_status'] !== 'MATCHED';
        }));
    } elseif ($display_mode === 'matched') {
        $display_transactions = array_values(array_filter($transactions, function ($row) {
            return $row['match_status'] === 'MATCHED';
        }));
    }
}

// Variances (page-local)
$variance_vs_debit  = $total_bp_combined - $total_debit;
$variance_vs_credit = $total_bp_combined - $total_credit;

// Build export query string
$export_qs = http_build_query([
    'reference_no' => $_GET['reference_no'] ?? '',
    'partner'      => $_GET['partner']      ?? '',
    'date_from'    => $_GET['date_from']    ?? '',
    'date_to'      => $_GET['date_to']      ?? '',
    'display_mode' => $_GET['display_mode'] ?? 'variance',
    'page'         => $current_page,
]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BP vs GLE Recon Report | <?php echo isset($_SESSION['user_type']) ? ucfirst($_SESSION['user_type']) : "Guest"; ?></title>
    <link rel="stylesheet" href="../../../assets/css/templates/style.css?v=<?php echo time(); ?>">
    <script src="https://kit.fontawesome.com/30b908cc5a.js" crossorigin="anonymous"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="../../../assets/js/sweetalert2.all.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
    <link rel="stylesheet" href="css/billsgle.css?v=<?= time(); ?>">
    <link rel="icon" href="../../../images/MLW logo.png" type="image/png">
</head>
<body>
    <div class="main-container">
        <?php include '../../../templates/header_ui.php'; ?>
        <?php include '../../../templates/sidebar.php'; ?>

        <div id="loading-overlay" style="display:none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(255,255,255,0.7); z-index: 9999; justify-content: center; align-items: center;">
            <div class="loading-spinner" style="border: 6px solid #f3f3f3; border-top: 6px solid #bd1a1a; border-radius: 50%; width: 50px; height: 50px; animation: spin 1s linear infinite;"></div>
        </div>
        <style>
            @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        </style>

        <div class="bp-section-header" role="region" aria-label="Page title">
            <div class="bp-section-title">
                <i class="fa-solid fa-layer-group" aria-hidden="true"></i>
                <div>
                    <h2>Recon Report</h2>
                    <p class="bp-section-sub">Bills Payment vs GLE Records</p>
                </div>
            </div>
        </div>

        <!-- Filter card -->
        <div class="bp-card" style="margin: 1rem; padding: .5rem; background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">
            <form id="filter-form" method="GET" action="">
                <input type="hidden" name="filter" value="1">

                <div class="filter-row" style="display: flex; flex-wrap: wrap; gap: 1.5rem; align-items: flex-end;">

                    <div style="flex: 1 1 320px; min-width: 260px;">
                        <label for="reference_no" style="display: block; font-weight: 600; margin-bottom: 0.3rem; font-size: 15px; color: #333;">
                            <i class="fa-solid fa-hashtag"></i> Reference No
                            <small style="color:#888; font-weight:400;"> (or select Partner + dates, or dates only)</small>
                        </label>
                        <input type="text" name="reference_no" id="reference_no" class="bp-input"
                               placeholder="Enter reference number..."
                               value="<?php echo isset($_GET['reference_no']) ? htmlspecialchars($_GET['reference_no']) : ''; ?>"
                               autocomplete="off"
                               style="width: 100%; padding: 0.5rem; border: 1px solid #ccc; border-radius: 4px;">
                    </div>

                    <div style="flex: 1 1 260px; min-width: 220px;">
                        <label for="partner" style="display: block; font-weight: 600; margin-bottom: 0.3rem; font-size: 15px; color: #333;">
                            <i class="fa-solid fa-handshake"></i> Partner
                        </label>
                        <select name="partner" id="partner" class="bp-select2" style="width: 100%;">
                            <option value="">— Any Partner (Partner ID - Partner Name) —</option>
                            <option value="ALL" <?php echo (isset($_GET['partner']) && $_GET['partner'] === 'ALL') ? 'selected' : ''; ?>>— ALL PARTNERS —</option>
                            <?php foreach ($partners as $p): ?>
                                <option value="<?php echo htmlspecialchars($p['partner_id_kpx']); ?>"
                                    <?php echo (isset($_GET['partner']) && $_GET['partner'] == $p['partner_id_kpx']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($p['partner_id_kpx'] . ' — ' . $p['partner_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="flex: 0 1 170px;">
                        <label for="date_from" style="display: block; font-weight: 600; margin-bottom: 0.3rem; font-size: 15px; color: #333;">
                            <i class="fa-regular fa-calendar"></i> Date From
                            <small style="color:#888; font-weight:400;"></small>
                        </label>
                        <input type="date" name="date_from" id="date_from" class="bp-input"
                               value="<?php echo isset($_GET['date_from']) ? htmlspecialchars($_GET['date_from']) : ''; ?>"
                               style="width: 100%; padding: 0.5rem; border: 1px solid #ccc; border-radius: 4px; font-size: 14px;">
                    </div>

                    <div style="flex: 0 1 170px;">
                        <label for="date_to" style="display: block; font-weight: 600; margin-bottom: 0.3rem; font-size: 15px; color: #333;">
                            <i class="fa-regular fa-calendar"></i> Date To
                            <small style="color:#888; font-weight:400;"></small>
                        </label>
                        <input type="date" name="date_to" id="date_to" class="bp-input"
                               value="<?php echo isset($_GET['date_to']) ? htmlspecialchars($_GET['date_to']) : ''; ?>"
                               style="width: 100%; padding: 0.5rem; border: 1px solid #ccc; border-radius: 4px; font-size: 14px;">
                    </div>

                    <div style="flex: 0 1 210px; min-width: 190px;">
                        <label for="display_mode" style="display:block;font-weight:600;margin-bottom:.3rem;font-size:15px;color:#333;">
                            <i class="fa-solid fa-filter-circle-dollar"></i> Display
                        </label>
                        <select name="display_mode" id="display_mode" class="bp-input" style="width:100%;padding:.5rem;border:1px solid #ccc;border-radius:4px;">
                            <option value="variance" <?php echo (($_GET['display_mode'] ?? 'variance') === 'variance') ? 'selected' : ''; ?>>Variance / Unmatched first</option>
                            <option value="all" <?php echo (($_GET['display_mode'] ?? '') === 'all') ? 'selected' : ''; ?>>All transactions (including matched)</option>
                            <option value="matched" <?php echo (($_GET['display_mode'] ?? '') === 'matched') ? 'selected' : ''; ?>>Matched only (no variance)</option>
                        </select>
                    </div>

                    <div style="flex: 0 0 auto; display: flex; gap: 0.5rem;">
                        <button type="submit" class="bp-btn bp-btn-primary"
                                style="padding: 0.5rem 1.2rem; background: #b30000; color: #fff; border: none; border-radius: 4px; cursor: pointer;">
                            <i class="fa-solid fa-filter"></i> Filter & Recon
                        </button>
                        <a href="?" id="reset-btn" class="bp-btn bp-btn-secondary"
                           style="padding: 0.5rem 1.2rem; background: #e0e0e0; color: #333; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center;">
                            <i class="fa-solid fa-rotate-left"></i> Reset
                        </a>
                    </div>
                </div>
            </form>
        </div>

        <!-- Debug info -->
        <?php if (!empty($debug_message)): ?>
            <div class="debug-box">
                <strong><i class="fa-solid fa-bug"></i> Info:</strong><br>
                <?php echo htmlspecialchars($debug_message); ?>
            </div>
        <?php endif; ?>

        <!-- Summary cards -->
        <?php if ($filter_applied && count($transactions) > 0): ?>
            <div style="display: flex; flex-wrap: wrap; gap: 1rem; margin: 1rem;">
                <div style="flex: 1 1 180px; background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); padding: .5rem 1.2rem; border-left: 5px solid #1a3c5e;">
                    <div style="font-size: 0.75rem; color: #666; text-transform: uppercase;">Total Amount Paid</div>
                    <div style="font-size: 1rem; font-weight: 700; color: #1a3c5e; margin-top: 0.2rem;">
                        <?php echo number_format($total_amount_paid, 2); ?>
                    </div>
                </div>
                <div style="flex: 1 1 180px; background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); padding: .5rem 1.2rem; border-left: 5px solid #b30000;">
                    <div style="font-size: 0.75rem; color: #666; text-transform: uppercase;">Chg Cust</div>
                    <div style="font-size: 1rem; font-weight: 700; color: #b30000; margin-top: 0.2rem;">
                        <?php echo number_format($total_charge_customer, 2); ?>
                    </div>
                </div>
                <div style="flex: 1 1 180px; background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); padding: .5rem 1.2rem; border-left: 5px solid #2e7d32;">
                    <div style="font-size: 0.75rem; color: #666; text-transform: uppercase;">Chg Partr</div>
                    <div style="font-size: 1rem; font-weight: 700; color: #2e7d32; margin-top: 0.2rem;">
                        <?php echo number_format($total_charge_partner, 2); ?>
                    </div>
                </div>
                <div style="flex: 1 1 180px; background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); padding: .5rem 1.2rem; border-left: 5px solid #0d47a1;">
                    <div style="font-size: 0.75rem; color: #666; text-transform: uppercase;">BP Total</div>
                    <div style="font-size: 1rem; font-weight: 700; color: #0d47a1; margin-top: 0.2rem;">
                        <?php echo number_format($total_bp_combined, 2); ?>
                    </div>
                </div>
                <div style="flex: 1 1 180px; background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); padding: .5rem 1.2rem; border-left: 5px solid #1565c0;">
                    <div style="font-size: 0.75rem; color: #666; text-transform: uppercase;">GLE Debit</div>
                    <div style="font-size: 1rem; font-weight: 700; color: #1565c0; margin-top: 0.2rem;">
                        <?php echo number_format($total_debit, 2); ?>
                    </div>
                </div>
                <div style="flex: 1 1 180px; background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); padding: .5rem 1.2rem; border-left: 5px solid #6a1b9a;">
                    <div style="font-size: 0.75rem; color: #666; text-transform: uppercase;">GLE Credit</div>
                    <div style="font-size: 1rem; font-weight: 700; color: #6a1b9a; margin-top: 0.2rem;">
                        <?php echo number_format($total_credit, 2); ?>
                    </div>
                </div>
                <div style="flex: 1 1 180px; background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); padding: .5rem 1.2rem; border-left: 5px solid #ef6c00;">
                    <div style="font-size: 0.75rem; color: #666; text-transform: uppercase;">Matched / Variance / BP / GLE</div>
                    <div style="font-size: 1rem; font-weight: 700; color: #ef6c00; margin-top: 0.2rem;">
                        <?php echo $matched_count; ?> / <?php echo $variance_count; ?> / <?php echo $unmatched_local; ?> / <?php echo $unmatched_gle; ?>
                    </div>
                </div>
                <div style="flex: 1 1 180px; background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); padding: .5rem 1.2rem; border-left: 5px solid #c62828;">
                    <div style="font-size: 0.75rem; color: #666; text-transform: uppercase;">BP Total - GLE Debit</div>
                    <div style="font-size: 1rem; font-weight: 700; color: <?php echo $variance_vs_debit == 0 ? '#2e7d32' : '#c62828'; ?>; margin-top: 0.2rem;">
                        <?php if ($variance_vs_debit == 0): ?>
                            <i class="fa-solid fa-circle-check" style="font-size:0.8rem;"></i>
                        <?php else: ?>
                            <i class="fa-solid fa-triangle-exclamation" style="font-size:0.8rem;"></i>
                        <?php endif; ?>
                        <?php echo number_format($variance_vs_debit, 2); ?>
                    </div>
                </div>
                <div style="flex: 1 1 180px; background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); padding: .5rem 1.2rem; border-left: 5px solid #ad1457;">
                    <div style="font-size: 0.75rem; color: #666; text-transform: uppercase;">BP Total - GLE Credit</div>
                    <div style="font-size: 1rem; font-weight: 700; color: <?php echo $variance_vs_credit == 0 ? '#2e7d32' : '#ad1457'; ?>; margin-top: 0.2rem;">
                        <?php if ($variance_vs_credit == 0): ?>
                            <i class="fa-solid fa-circle-check" style="font-size:0.8rem;"></i>
                        <?php else: ?>
                            <i class="fa-solid fa-triangle-exclamation" style="font-size:0.8rem;"></i>
                        <?php endif; ?>
                        <?php echo number_format($variance_vs_credit, 2); ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Results table -->
        <div class="bp-card" style="margin: .5rem 1rem; background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); overflow: hidden;">
            <div style="padding: .3rem 1rem; background: #f8f9fa; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: .5rem;">
                <h3 style="margin: 0; font-size: 1rem; color: #1a3c5e;"><i class="fa-solid fa-table"></i> Reconciliation Results</h3>
                <div style="font-size: 0.85rem; color: #666; display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;">
                    <?php if ($filter_applied && count($transactions) > 0): ?>
                        <!-- ✅ EXPORT PDF BUTTON -->
                        <a id="export-pdf-btn"
                           href="export_recon.php?<?php echo htmlspecialchars($export_qs); ?>"
                           target="_blank"
                           style="background: #b30000; color: #fff; border: 1px solid #8b0000; border-radius: 4px; padding: 3px 10px; cursor: pointer; font-size: 0.8rem; text-decoration: none; display: inline-flex; align-items: center; gap: 5px;">
                            <i class="fa-solid fa-file-pdf"></i> Export PDF
                        </a>
                    <?php endif; ?>
                    <span>
                        <?php if ($filter_applied): ?>
                            <i class="fa-solid fa-circle-info"></i> <?php echo count($display_transactions); ?> displayed / <?php echo count($transactions); ?> on this page
                        <?php else: ?>
                            <i class="fa-solid fa-circle-info"></i> Enter a Reference No, select Partner + dates, or select a date range only to view results
                        <?php endif; ?>
                    </span>
                </div>
            </div>

            <?php if ($pager_html !== ''): ?>
                <div style="padding: .4rem 1rem; background: #fff; border-bottom: 1px solid #eee;">
                    <?php echo $pager_html; ?>
                </div>
            <?php endif; ?>

            <!-- Scrollable area – max-height + sticky headers -->
            <div style="overflow-x: auto; max-height: 48vh; overflow-y: auto;">
                <table class="bp-table" style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
                    <thead>
                        <tr class="group-header">
                            <th colspan="7" class="group-local" style="text-align: center;">
                                <i class="fa-solid fa-receipt"></i> Bills Payment Transaction
                            </th>
                            <th colspan="8" class="group-gle" style="text-align: center;">
                                <i class="fa-solid fa-book"></i> GLE Records
                            </th>
                        </tr>
                        <tr class="col-header" style="color: #fff;">
                            <th class="col-local col-status" style="padding: 0.35rem 0.5rem; text-align: center;">Status</th>
                            <th class="col-local col-partner" style="padding: 0.35rem 0.5rem; text-align: center;">Partner</th>
                            <th class="col-local col-reference" style="padding: 0.35rem 0.5rem; text-align: center;">Reference</th>
                            <th class="col-local col-datetime" style="padding: 0.35rem 0.5rem; text-align: center;">Datetime</th>
                            <th class="col-local col-amount" style="padding: 0.35rem 0.5rem; text-align: center;">Amt Paid</th>
                            <th class="col-local col-charge" style="padding: 0.35rem 0.5rem; text-align: center;">Chg Cust</th>
                            <th class="col-local col-charge divider-right" style="padding: 0.35rem 0.5rem; text-align: center;">Chg Partr</th>
                            <th class="col-gle col-glcode" style="padding: 0.35rem 0.5rem; text-align: center;">GL Code</th>
                            <th class="col-gle col-gldesc" style="padding: 0.35rem 0.5rem; text-align: center;">GL Desc</th>
                            <th class="col-gle col-desc" style="padding: 0.35rem 0.5rem; text-align: center;">Partner</th>
                            <th class="col-gle col-reference" style="padding: 0.35rem 0.5rem; text-align: center;">Reference</th>
                            <th class="col-gle col-datetime" style="padding: 0.35rem 0.5rem; text-align: center;">Datetime</th>
                            <th class="col-gle col-debit" style="padding: 0.35rem 0.5rem; text-align: center;">Debit</th>
                            <th class="col-gle col-credit" style="padding: 0.35rem 0.5rem; text-align: center;">Credit</th>
                            <th class="col-gle col-entry" style="padding: 0.35rem 0.5rem; text-align: center;">Entry No.</th>
                        </tr>
                    </thead>
                    <tbody id="results-body">
                        <?php if ($filter_applied && count($transactions) > 0): ?>
                            <?php foreach ($display_transactions as $idx => $row): ?>
                                <?php
                                    $child_rows  = $row['gle_rows'] ?? [];
                                    $has_match   = ($row['match_status'] === 'MATCHED');
                                    $is_bp_only  = ($row['match_status'] === 'UNMATCHED (BP only)');
                                    $is_gle_only = ($row['match_status'] === 'UNMATCHED (GLE only)');
                                    $is_variance = ($row['match_status'] === 'VARIANCE');
                                    $is_multi    = (($row['gle_row_count'] ?? 0) > 1);

                                    if (!empty($row['partner_id_kpx']) || !empty($row['partner_name'])) {
                                        $partner_display = trim(($row['partner_id_kpx'] ?? '') . ' - ' . ($row['partner_name'] ?? ''), ' -');
                                    } elseif (!empty($row['gle_desc'])) {
                                        $partner_display = $row['gle_desc'];
                                    } else {
                                        $partner_display = '—';
                                    }

                                    // Build Status HTML once so we can reuse it
                                    ob_start();
                                    ?>
                                    <?php if ($has_match): ?>
                                        <span class="status-matched<?php echo $is_multi ? ' multi' : ''; ?>">
                                            MATCHED<?php echo $is_multi ? ' (' . $row['gle_row_count'] . ' rows)' : ''; ?>
                                        </span>
                                    <?php elseif ($is_variance): ?>
                                        <span style="display:inline-block;background:#fff3e0;color:#e65100;border:1px solid #ffcc80;border-radius:4px;padding:2px 5px;font-weight:700;">
                                            <i class="fa-solid fa-triangle-exclamation"></i> VARIANCE
                                        </span>
                                    <?php elseif ($is_gle_only): ?>
                                        <span class="status-unmatched-gle">
                                            UNMATCHED (GLE only)
                                        </span>
                                    <?php else: ?>
                                        <span class="status-unmatched"><?php echo htmlspecialchars($row['match_status']); ?></span>
                                    <?php endif; ?>
                                    <?php if ($row['has_local'] && $row['has_gle']): ?>
                                        <div style="font-size:10px;line-height:1.4;margin-top:3px;color:#555;white-space:nowrap;">
                                            <span title="BP Total minus GLE Debit">D: <strong style="color:<?php echo abs((float)$row['variance_debit']) < 0.01 ? '#2e7d32' : '#c62828'; ?>;"><?php echo number_format((float)$row['variance_debit'], 2); ?></strong></span>
                                            <span title="BP Total minus GLE Credit" style="margin-left:4px;">C: <strong style="color:<?php echo abs((float)$row['variance_credit']) < 0.01 ? '#2e7d32' : '#c62828'; ?>;"><?php echo number_format((float)$row['variance_credit'], 2); ?></strong></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php 
                                    $status_html = ob_get_clean();
                                ?>

                                <?php if ($is_multi): ?>
                                    <!-- MULTIPLE GLE ROWS: Display BP details on the first row, others blank -->
                                    <?php foreach ($child_rows as $g_index => $g): ?>
                                        <tr>
                                            <?php if ($g_index === 0): ?>
                                                <!-- First GLE Row (Contains BP Data + Status) -->
                                                <td class="col-status" style="padding: 0.35rem 0.5rem; border-bottom: none;">
                                                    <?php echo $status_html; ?>
                                                </td>
                                                <td class="col-partner wrap-cell" style="padding: 0.35rem 0.5rem; border-bottom: none;">
                                                    <?php echo htmlspecialchars($partner_display); ?>
                                                </td>
                                                <td class="col-reference" style="padding: 0.35rem 0.5rem; border-bottom: none;"><?php echo htmlspecialchars($row['reference_no'] ?? '—'); ?></td>
                                                <td class="col-datetime" style="padding: 0.35rem 0.5rem; border-bottom: none;">
                                                    <?php echo $row['has_local'] ? htmlspecialchars($row['datetime'] ?? '—') : '—'; ?>
                                                </td>
                                                <td class="col-amount" style="padding: 0.35rem 0.5rem; text-align: right; border-bottom: none;">
                                                    <?php echo $row['has_local'] && $row['amount_paid'] !== null ? number_format((float)$row['amount_paid'], 2) : '—'; ?>
                                                </td>
                                                <td class="col-charge" style="padding: 0.35rem 0.5rem; text-align: right; border-bottom: none;">
                                                    <?php echo $row['has_local'] && $row['charge_to_customer'] !== null ? number_format((float)$row['charge_to_customer'], 2) : '—'; ?>
                                                </td>
                                                <td class="col-charge divider-right" style="padding: 0.35rem 0.5rem; text-align: right; border-bottom: none;">
                                                    <?php echo $row['has_local'] && $row['charge_to_partner'] !== null ? number_format((float)$row['charge_to_partner'], 2) : '—'; ?>
                                                </td>
                                            <?php else: ?>
                                                <!-- Subsequent GLE Rows (Blank BP Data) -->
                                                <td class="col-status" style="padding: 0.35rem 0.5rem; border-top: none; border-bottom: none; text-align: center;">
                                                    <i class="fa-solid fa-turn-up fa-rotate-90" style="opacity:.3; margin-right:6px;"></i>
                                                    <small style="color:#999;">GLE</small>
                                                </td>
                                                <td class="col-partner" style="border-top: none; border-bottom: none;"></td>
                                                <td class="col-reference" style="border-top: none; border-bottom: none;"></td>
                                                <td class="col-datetime" style="border-top: none; border-bottom: none;"></td>
                                                <td class="col-amount" style="border-top: none; border-bottom: none;"></td>
                                                <td class="col-charge" style="border-top: none; border-bottom: none;"></td>
                                                <td class="col-charge divider-right" style="border-top: none; border-bottom: none;"></td>
                                            <?php endif; ?>

                                            <!-- GLE Data Columns -->
                                            <td class="col-glcode" style="padding: 0.35rem 0.5rem; font-weight:600; color:#1a3c5e; text-align: right; <?php echo $g_index === 0 ? 'border-bottom: none;' : 'border-top: none; border-bottom: none;'; ?>">
                                                <?php echo htmlspecialchars($g['gl_code'] ?? '—'); ?>
                                            </td>
                                            <td class="col-gldesc wrap-cell" style="padding: 0.35rem 0.5rem; <?php echo $g_index === 0 ? 'border-bottom: none;' : 'border-top: none; border-bottom: none;'; ?>">
                                                <?php echo htmlspecialchars($g['gl_desc'] ?? '—'); ?>
                                            </td>
                                            <td class="col-desc wrap-cell" style="padding: 0.35rem 0.5rem; <?php echo $g_index === 0 ? 'border-bottom: none;' : 'border-top: none; border-bottom: none;'; ?>">
                                                <?php echo htmlspecialchars($g['desc'] ?? '—'); ?>
                                            </td>
                                            <td class="col-reference" style="padding: 0.35rem 0.5rem; <?php echo $g_index === 0 ? 'border-bottom: none;' : 'border-top: none; border-bottom: none;'; ?>">
                                                <?php echo htmlspecialchars($g['reference'] ?? '—'); ?>
                                            </td>
                                            <td class="col-datetime" style="padding: 0.35rem 0.5rem; color:#000000; <?php echo $g_index === 0 ? 'border-bottom: none;' : 'border-top: none; border-bottom: none;'; ?>">
                                                <?php echo htmlspecialchars($g['datetime'] ?? '—'); ?>
                                            </td>
                                            <td class="col-debit" style="padding: 0.35rem 0.5rem; text-align: right; color:#1565c0; font-weight:600; <?php echo $g_index === 0 ? 'border-bottom: none;' : 'border-top: none; border-bottom: none;'; ?>">
                                                <?php echo number_format((float)($g['debit'] ?? 0), 2); ?>
                                            </td>
                                            <td class="col-credit" style="padding: 0.35rem 0.5rem; text-align: right; color:#6a1b9a; font-weight:600; <?php echo $g_index === 0 ? 'border-bottom: none;' : 'border-top: none; border-bottom: none;'; ?>">
                                                <?php echo number_format((float)($g['credit'] ?? 0), 2); ?>
                                            </td>
                                            <td class="col-entry" style="padding: 0.35rem 0.5rem; <?php echo $g_index === 0 ? 'border-bottom: none;' : 'border-top: none; border-bottom: none;'; ?>">
                                                <?php echo htmlspecialchars($g['entry_number'] ?? '—'); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>

                                <?php else: ?>
                                    <!-- SINGLE ROW OR NO GLE ROWS: Standard display -->
                                    <tr>
                                        <td class="col-status" style="padding: 0.35rem 0.5rem;"><?php echo $status_html; ?></td>
                                        <td class="col-partner wrap-cell" style="padding: 0.35rem 0.5rem;"><?php echo htmlspecialchars($partner_display); ?></td>
                                        <td class="col-reference" style="padding: 0.35rem 0.5rem;"><?php echo htmlspecialchars($row['reference_no'] ?? '—'); ?></td>
                                        <td class="col-datetime" style="padding: 0.35rem 0.5rem;">
                                            <?php echo $row['has_local'] ? htmlspecialchars($row['datetime'] ?? '—') : '—'; ?>
                                        </td>
                                        <td class="col-amount" style="padding: 0.35rem 0.5rem; text-align: right;">
                                            <?php echo $row['has_local'] && $row['amount_paid'] !== null ? number_format((float)$row['amount_paid'], 2) : '—'; ?>
                                        </td>
                                        <td class="col-charge" style="padding: 0.35rem 0.5rem; text-align: right;">
                                            <?php echo $row['has_local'] && $row['charge_to_customer'] !== null ? number_format((float)$row['charge_to_customer'], 2) : '—'; ?>
                                        </td>
                                        <td class="col-charge divider-right" style="padding: 0.35rem 0.5rem; text-align: right;">
                                            <?php echo $row['has_local'] && $row['charge_to_partner'] !== null ? number_format((float)$row['charge_to_partner'], 2) : '—'; ?>
                                        </td>

                                        <?php if (!empty($child_rows)): ?>
                                            <?php $single = $child_rows[0]; ?>
                                            <td class="col-glcode" style="padding: 0.35rem 0.5rem; text-align: right; font-weight:600; color:#1a3c5e;">
                                                <?php echo htmlspecialchars($single['gl_code'] ?? '—'); ?>
                                            </td>
                                            <td class="col-gldesc wrap-cell" style="padding: 0.35rem 0.5rem;">
                                                <?php echo htmlspecialchars($single['gl_desc'] ?? '—'); ?>
                                            </td>
                                            <td class="col-desc wrap-cell" style="padding: 0.35rem 0.5rem;">
                                                <?php echo htmlspecialchars($single['desc'] ?? '—'); ?>
                                            </td>
                                            <td class="col-reference" style="padding: 0.35rem 0.5rem;">
                                                <?php echo htmlspecialchars($single['reference'] ?? '—'); ?>
                                            </td>
                                            <td class="col-datetime" style="padding: 0.35rem 0.5rem; color:#000;">
                                                <?php echo htmlspecialchars($single['datetime'] ?? '—'); ?>
                                            </td>
                                            <td class="col-debit" style="padding: 0.35rem 0.5rem; text-align: right; color:#1565c0; font-weight:600;">
                                                <?php echo number_format((float)($single['debit'] ?? 0), 2); ?>
                                            </td>
                                            <td class="col-credit" style="padding: 0.35rem 0.5rem; text-align: right; color:#6a1b9a; font-weight:600;">
                                                <?php echo number_format((float)($single['credit'] ?? 0), 2); ?>
                                            </td>
                                            <td class="col-entry" style="padding: 0.35rem 0.5rem;">
                                                <?php echo htmlspecialchars($single['entry_number'] ?? '—'); ?>
                                            </td>
                                        <?php else: ?>
                                            <td class="col-glcode" style="padding: 0.35rem 0.5rem; text-align: right;">—</td>
                                            <td class="col-gldesc" style="padding: 0.35rem 0.5rem;">—</td>
                                            <td class="col-desc" style="padding: 0.35rem 0.5rem;">—</td>
                                            <td class="col-reference" style="padding: 0.35rem 0.5rem;"><?php echo htmlspecialchars($row['reference_no'] ?? '—'); ?></td>
                                            <td class="col-datetime" style="padding: 0.35rem 0.5rem; text-align: right; font-weight: 600; color:#1565c0;">—</td>
                                            <td class="col-debit" style="padding: 0.35rem 0.5rem; text-align: right; font-weight: 600; color:#6a1b9a;">—</td>
                                            <td class="col-credit" style="padding: 0.35rem 0.5rem; text-align: right;">—</td>
                                            <td class="col-entry" style="padding: 0.35rem 0.5rem;">—</td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php elseif ($filter_applied && count($display_transactions) === 0): ?>
                            <tr>
                                <td colspan="15" style="padding: 2rem; text-align: center; color: #888;">
                                    <i class="fa-regular fa-folder-open" style="font-size: 1.5rem; display: block; margin-bottom: 0.5rem;"></i>
                                    No transactions match the selected display option and filters.
                                </td>
                            </tr>
                        <?php else: ?>
                            <tr>
                                <td colspan="15" style="padding: 2rem; text-align: center; color: #888;">
                                    <i class="fa-regular fa-folder-open" style="font-size: 1.5rem; display: block; margin-bottom: 0.5rem;"></i>
                                    Enter a Reference No, select Partner + dates, or select a date range only, then click Filter &amp; Recon.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                    <?php if ($filter_applied && count($transactions) > 0): ?>
                        <tfoot>
                            <tr class="total-row">
                                <td colspan="4" style="text-align: right;">
                                    <i class="fa-solid fa-calculator"></i> PAGE TOTAL
                                </td>
                                <td style="text-align: right;"><?php echo number_format($total_amount_paid, 2); ?></td>
                                <td style="text-align: right;"><?php echo number_format($total_charge_customer, 2); ?></td>
                                <td class="divider-right" style="text-align: right;"><?php echo number_format($total_charge_partner, 2); ?></td>
                                <td colspan="5"></td>
                                <td style="text-align: right;"><?php echo number_format($total_debit, 2); ?></td>
                                <td style="text-align: right;"><?php echo number_format($total_credit, 2); ?></td>
                                <td></td>
                            </tr>
                            <tr class="total-row">
                                <td colspan="4" style="text-align: right;"><i class="fa-solid fa-calculator"></i> Page Total (Amount + Charges) </td>
                                <td colspan="2" style="text-align: center;"><strong><?php echo number_format($total_bp_combined, 2); ?></strong></td>
                                <td class="divider-right" style="text-align: right;"></td>
                                <td colspan="8"></td>
                            </tr>
                        </tfoot>
                    <?php endif; ?>
                </table>
            </div>

          
        </div>
    </div>

    <script>
    $(document).ready(function() {
        $('#partner').select2({
            placeholder: '— Any Partner (Partner ID - Partner Name) —',
            allowClear: true,
            width: '100%'
        });

        // ---------- Form submit validation ----------
        $('#filter-form').on('submit', function(e) {
            var from    = $('#date_from').val();
            var to      = $('#date_to').val();
            var refno   = $('#reference_no').val().trim();
            var partner = $('#partner').val();

            if (!refno && !partner && (!from || !to)) {
                e.preventDefault();
                Swal.fire('Missing Filter', 'Please enter a Reference No, select a Partner with dates, or provide both Date From and Date To.', 'warning');
                return false;
            }

            if (partner && partner !== 'ALL' && (!from || !to)) {
                e.preventDefault();
                Swal.fire('Dates Required', 'When filtering by Partner, both Date From and Date To are required.', 'warning');
                return false;
            }

            if (from && to && from > to) {
                e.preventDefault();
                Swal.fire('Invalid Date Range', '"Date From" cannot be later than "Date To".', 'warning');
                return false;
            }

            // Date-only or ALL partner filter warning
            var isDateOnly = !refno && (!partner || partner === 'ALL') && from && to;
            if (isDateOnly) {
                e.preventDefault();

                Swal.fire({
                    icon: 'info',
                    title: 'Large Date Range Detected',
                    html: `
                        You are filtering by <strong>dates only</strong> or <strong>All Partners</strong>.<br><br>
                        <span style="color:#b30000; font-weight:600;">Results may take longer to load</span>.
                    `,
                    confirmButtonText: 'Continue',
                    confirmButtonColor: '#b30000',
                    showCancelButton: true,
                    cancelButtonText: 'Cancel',
                    allowOutsideClick: false
                }).then((result) => {
                    if (result.isConfirmed) {
                        $('#loading-overlay').css('display', 'flex');
                        // Re-submit the form after confirmation
                        $('#filter-form')[0].submit();
                    }
                });
                return false;
            }

            $('#loading-overlay').css('display', 'flex');
        });

        // ---------- Export PDF validation ----------
        $('#export-pdf-btn').on('click', function(e) {
            var from    = $('#date_from').val();
            var to      = $('#date_to').val();
            var refno   = $('#reference_no').val().trim();
            var partner = $('#partner').val();

            if (!refno && !partner && (!from || !to)) {
                e.preventDefault();
                Swal.fire('Missing Filter', 'Please enter a Reference No, select a Partner with dates, or provide both Date From and Date To before exporting.', 'warning');
                return false;
            }

            if (partner && partner !== 'ALL' && (!from || !to)) {
                e.preventDefault();
                Swal.fire('Dates Required', 'When filtering by Partner, both Date From and Date To are required.', 'warning');
                return false;
            }

            if (from && to && from > to) {
                e.preventDefault();
                Swal.fire('Invalid Date Range', '"Date From" cannot be later than "Date To".', 'warning');
                return false;
            }

            $('#loading-overlay').css('display', 'flex');
            setTimeout(function() {
                $('#loading-overlay').css('display', 'none');
            }, 1500);
        });

        // ---------- Enter key on reference input ----------
        $('#reference_no').on('keypress', function(e) {
            if (e.which === 13) {
                e.preventDefault();
                $('#filter-form').trigger('submit');
            }
        });

        // Clear error highlight while typing
        $('#reference_no').on('input', function() {
            if ($(this).val().trim() !== '') {
                $(this).removeClass('input-required-error');
            }
        });
    });
    </script>

    <?php include '../../../templates/footer.php'; ?>
</body>
</html>