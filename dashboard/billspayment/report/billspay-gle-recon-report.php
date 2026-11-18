<?php
// billspay-gle-recon-report.php
// Connect to the databases (both $conn and $conn_exact are defined in config.php)
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../vendor/autoload.php';
// ===== BEGIN inlined recon library (identical in both files; do not edit one without the other) =====
// ============================================================
// recon_lib.php
// Shared by billspay-gle-recon-report.php and export_recon.php
// (put this file in the same folder as those two)
//
// Strategy (why this is faster):
//   Phase 1 - AGGREGATES ONLY: one row per reference from SQL
//             (SUM/COUNT/GROUP BY) for both BP and GLE. Cheap, light on memory.
//   Phase 2 - DETAILS: full GLE rows are fetched ONLY for the references that
//             are actually displayed (100 on the page, or the filtered set in the export).
//   Result of phase 1 is cached in a temp file so pager clicks and
//   the export don't recompute everything.
// ============================================================

const RECON_GLE_TO_BP_PREFIXES = ['KBP'];                    // GLE refs expected to exist in BP
const RECON_DATE_ONLY_PREFIXES = ['APB', 'EBP', 'KBP', 'WQP']; // BP refs scanned in date-only mode
const RECON_CACHE_VERSION      = 'v3';
const RECON_CACHE_TTL          = 900;      // seconds
const RECON_BP_GROUP_CAP       = 200000;   // safety cap on number of BP references
const RECON_REF_CHUNK          = 1000;     // refs per IN (...) query
const RECON_PDF_MAX_REFS       = 3000;     // above this, PDF is refused -> use CSV

// ------------------------------------------------------------
// Small helpers
// ------------------------------------------------------------
function recon_is_gle_to_bp_ref($ref) {
    $ref = (string)$ref;
    foreach (RECON_GLE_TO_BP_PREFIXES as $pfx) {
        if ($pfx !== '' && stripos($ref, $pfx) === 0) return true;
    }
    return false;
}

function recon_normalize_partner_name($value) {
    $value = strtoupper(trim((string)$value));
    $value = str_replace(["\\'", "'"], '', $value);
    return preg_replace('/[^A-Z0-9]/', '', $value);
}

function recon_prefix_sql(array $prefixes, $column, &$types, array &$params) {
    $parts = [];
    foreach ($prefixes as $pfx) {
        $parts[]  = "$column LIKE ?";
        $params[] = $pfx . '%';
        $types   .= 's';
    }
    return '(' . implode(' OR ', $parts) . ')';
}

function recon_fetch_all(mysqli $db, $sql, $types, array $params, &$err) {
    $err = '';
    $out = [];
    $stmt = $db->prepare($sql);
    if (!$stmt) { $err = $db->error; return $out; }
    if ($types !== '') $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) { $err = $stmt->error; $stmt->close(); return $out; }
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $out[] = $row;
    $stmt->close();
    return $out;
}

// ------------------------------------------------------------
// File cache (NOT the session: big arrays in $_SESSION slow every request)
// ------------------------------------------------------------
function recon_cache_path($key) {
    return sys_get_temp_dir() . '/recon_' . preg_replace('/[^a-z0-9_]/i', '', $key) . '.cache';
}

function recon_cache_get($key, $ttl = RECON_CACHE_TTL) {
    $f = recon_cache_path($key);
    if (!is_file($f) || (time() - filemtime($f)) > $ttl) return null;
    $raw = @file_get_contents($f);
    if ($raw === false) return null;
    $data = @unserialize($raw, ['allowed_classes' => false]);
    return is_array($data) ? $data : null;
}

function recon_cache_put($key, array $data) {
    $f   = recon_cache_path($key);
    $tmp = $f . '.' . getmypid();
    if (@file_put_contents($tmp, serialize($data)) !== false) {
        @rename($tmp, $f); // atomic
    }
    // light garbage collection (~2% of writes): delete files older than 1 hour
    if (mt_rand(1, 50) === 1) {
        foreach (glob(sys_get_temp_dir() . '/recon_*.cache') ?: [] as $old) {
            if (time() - @filemtime($old) > 3600) @unlink($old);
        }
    }
}

function recon_cache_key(array $f) {
    return 'r_' . md5(RECON_CACHE_VERSION . '|' . implode(',', RECON_GLE_TO_BP_PREFIXES) . '|'
        . $f['partner_id'] . '|' . $f['date_from'] . '|' . $f['date_to'] . '|' . $f['reference_no']);
}

// ------------------------------------------------------------
// Metadata
// ------------------------------------------------------------
function recon_detect_reference_column(mysqli $conn) {
    $cached = recon_cache_get('refcol', 86400);
    if ($cached && !empty($cached['col'])) return $cached['col'];

    $col = 'reference_no';
    $q = $conn->query("
        SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = 'mldb'
          AND TABLE_NAME = 'billspayment_transaction'
          AND (COLUMN_NAME LIKE '%ref%' OR COLUMN_NAME LIKE '%reference%')
        ORDER BY (COLUMN_NAME = 'reference_no') DESC
        LIMIT 1
    ");
    if ($q && $q->num_rows > 0) {
        $c = $q->fetch_assoc();
        if (preg_match('/^[A-Za-z0-9_]+$/', (string)$c['COLUMN_NAME'])) {
            $col = $c['COLUMN_NAME'];
        }
        $q->free();
    }
    recon_cache_put('refcol', ['col' => $col]);
    return $col;
}

function recon_load_partners(mysqli $conn) {
    $partners = [];
    $res = $conn->query("
        SELECT partner_id_kpx, partner_name
        FROM masterdata.partner_masterfile
        WHERE partner_id_kpx IS NOT NULL
          AND partner_name IS NOT NULL
        ORDER BY partner_name ASC
    ");
    if ($res) {
        while ($row = $res->fetch_assoc()) $partners[] = $row;
        $res->free();
    }
    return $partners;
}

function recon_partner_name(array $partners, $partner_id) {
    foreach ($partners as $p) {
        if ((string)$p['partner_id_kpx'] === (string)$partner_id) return $p['partner_name'];
    }
    return '';
}

// ------------------------------------------------------------
// Filters
// ------------------------------------------------------------
function recon_parse_filters(array $src) {
    $g = function ($k) use ($src) {
        return (isset($src[$k]) && is_string($src[$k])) ? trim($src[$k]) : '';
    };
    $f = [
        'partner_id'   => $g('partner'),
        'date_from'    => $g('date_from'),
        'date_to'      => $g('date_to'),
        'reference_no' => $g('reference_no'),
        'display_mode' => $g('display_mode') ?: 'variance',
    ];
    if (!in_array($f['display_mode'], ['variance', 'bp_only', 'gle_only', 'matched', 'all'], true)) {
        $f['display_mode'] = 'variance';
    }
    $f['is_all']    = ($f['partner_id'] === 'ALL');
    $f['date_only'] = (
        $f['reference_no'] === '' &&
        ($f['partner_id'] === '' || $f['is_all']) &&
        $f['date_from'] !== '' && $f['date_to'] !== ''
    );
    return $f;
}

// returns '' when valid, else an error message
function recon_validate_filters(array $f) {
    if ($f['reference_no'] === '' && $f['partner_id'] === '' && ($f['date_from'] === '' || $f['date_to'] === '')) {
        return 'Enter a Reference No, select a Partner, or provide both Date From and Date To.';
    }
    if ($f['partner_id'] !== '' && !$f['is_all'] && ($f['date_from'] === '' || $f['date_to'] === '')) {
        return 'When filtering by Partner, both Date From and Date To are required.';
    }
    if (($f['date_from'] === '') !== ($f['date_to'] === '') && $f['reference_no'] === '') {
        return 'Please provide both Date From and Date To when filtering by date.';
    }
    if ($f['date_from'] !== '' && $f['date_to'] !== '' && $f['date_from'] > $f['date_to']) {
        return 'Date From cannot be later than Date To.';
    }
    return '';
}

function recon_date_bounds(array $f) {
    return [
        $f['date_from'] !== '' ? $f['date_from'] . ' 00:00:00' : null,
        $f['date_to']   !== '' ? $f['date_to']   . ' 23:59:59' : null,
    ];
}

function recon_filter_rows(array $rows, $mode) {
    $map = [
        'variance' => 'VARIANCE',
        'bp_only'  => 'UNMATCHED (BP only)',
        'gle_only' => 'UNMATCHED (GLE only)',
        'matched'  => 'MATCHED',
    ];
    if (!isset($map[$mode])) return $rows; // 'all'
    $want = $map[$mode];
    return array_values(array_filter($rows, function ($r) use ($want) {
        return $r['match_status'] === $want;
    }));
}

function recon_sum_rows(array $rows) {
    $t = ['amount_paid' => 0, 'charge_customer' => 0, 'charge_partner' => 0, 'debit' => 0, 'credit' => 0,
          'matched' => 0, 'variance' => 0, 'unmatched_local' => 0, 'unmatched_gle' => 0];
    foreach ($rows as $r) {
        switch ($r['match_status']) {
            case 'MATCHED':             $t['matched']++;         break;
            case 'VARIANCE':            $t['variance']++;        break;
            case 'UNMATCHED (BP only)': $t['unmatched_local']++; break;
            default:                    $t['unmatched_gle']++;
        }
        if ($r['has_local']) {
            $t['amount_paid']     += (float)$r['amount_paid'];
            $t['charge_customer'] += (float)$r['charge_to_customer'];
            $t['charge_partner']  += (float)$r['charge_to_partner'];
        }
        if ($r['has_gle']) {
            $t['debit']  += (float)$r['gle_debit_total'];
            $t['credit'] += (float)$r['gle_credit_total'];
        }
    }
    return $t;
}

// ------------------------------------------------------------
// PHASE 1: aggregates only
// ------------------------------------------------------------
function recon_gle_agg_map(array $rows) {
    $map = [];
    foreach ($rows as $r) {
        $ref = trim((string)$r['reference']);
        if ($ref === '') continue;
        if (!isset($map[$ref])) {
            $map[$ref] = ['n' => 0, 'd' => 0.0, 'c' => 0.0];
        }
        $map[$ref]['n'] += (int)$r['n'];
        $map[$ref]['d'] += (float)$r['d'];
        $map[$ref]['c'] += (float)$r['c'];
    }
    return $map;
}

function recon_gle_agg_by_refs(mysqli $db, array $refs, $df, $dt, array &$warnings) {
    $out = [];
    if (!$refs) return $out;

    $date_sql = ''; $date_params = []; $date_types = '';
    if ($df !== null) { $date_sql .= ' AND datetime >= ?'; $date_params[] = $df; $date_types .= 's'; }
    if ($dt !== null) { $date_sql .= ' AND datetime <= ?'; $date_params[] = $dt; $date_types .= 's'; }

    foreach (array_chunk($refs, RECON_REF_CHUNK) as $chunk) {
        $ph  = implode(',', array_fill(0, count($chunk), '?'));
        $sql = "SELECT reference, COUNT(*) AS n, SUM(debit) AS d, SUM(credit) AS c
                FROM gle
                WHERE reference IN ($ph)$date_sql
                GROUP BY reference";
        $err  = '';
        $rows = recon_fetch_all($db, $sql, str_repeat('s', count($chunk)) . $date_types,
                                array_merge($chunk, $date_params), $err);
        if ($err !== '') $warnings[] = 'GLE aggregate error: ' . $err;
        $out += recon_gle_agg_map($rows);
    }
    return $out;
}

// KBP (GLE -> BP scope) references in a date range, aggregated. Covered by
// idx_reference_datetime_covering (reference, datetime, ..., debit, credit).
function recon_gle_agg_scoped_by_date(mysqli $db, $df, $dt, array &$warnings) {
    if ($df === null || $dt === null) return [];
    $types = ''; $params = [];
    $pfx_sql = recon_prefix_sql(RECON_GLE_TO_BP_PREFIXES, 'reference', $types, $params);
    $sql = "SELECT reference, COUNT(*) AS n, SUM(debit) AS d, SUM(credit) AS c
            FROM gle
            WHERE $pfx_sql AND datetime >= ? AND datetime <= ?
            GROUP BY reference";
    $params[] = $df; $params[] = $dt; $types .= 'ss';
    $err  = '';
    $rows = recon_fetch_all($db, $sql, $types, $params, $err);
    if ($err !== '') $warnings[] = 'GLE date lookup error: ' . $err;
    return recon_gle_agg_map($rows);
}

// KBP references whose 428101 line matches the partner name (candidates for GLE-only)
function recon_gle_partner_candidate_refs(mysqli $db, $partner_name, $df, $dt, array &$warnings) {
    $refs = [];
    if ($partner_name === '') return $refs;

    $types = ''; $params = [];
    $pfx_sql = recon_prefix_sql(RECON_GLE_TO_BP_PREFIXES, 'reference', $types, $params);

    $date_sql = '';
    if ($df !== null) { $date_sql .= ' AND datetime >= ?'; $params[] = $df; $types .= 's'; }
    if ($dt !== null) { $date_sql .= ' AND datetime <= ?'; $params[] = $dt; $types .= 's'; }

    $params[] = '%' . addcslashes($partner_name, '%_\\') . '%';
    $types   .= 's';

    // idx_gle_reference_details (gl_code, desc, reference, datetime, no) covers this
    $sql = "SELECT DISTINCT reference, `desc`
            FROM gle
            WHERE gl_code = '428101' AND $pfx_sql$date_sql AND `desc` LIKE ?";
    $err  = '';
    $rows = recon_fetch_all($db, $sql, $types, $params, $err);
    if ($err !== '') { $warnings[] = 'GLE partner lookup error: ' . $err; return $refs; }

    $normalized_partner = recon_normalize_partner_name($partner_name);
    $memo = []; // similar_text is expensive; many rows share the same desc
    foreach ($rows as $r) {
        $rdesc = (string)($r['desc'] ?? '');
        $ref   = trim((string)$r['reference']);
        if ($rdesc === '' || $ref === '') continue;
        if (!isset($memo[$rdesc])) {
            $norm = recon_normalize_partner_name($rdesc);
            $pct  = 0.0;
            similar_text($normalized_partner, $norm, $pct);
            $memo[$rdesc] = ($norm === $normalized_partner || $pct >= 95.0);
        }
        if ($memo[$rdesc]) $refs[$ref] = true;
    }
    return array_keys($refs);
}

/**
 * Runs the whole reconciliation at the AGGREGATE level.
 * Returns ['rows' => [...one light row per reference...], 'totals' => [...], 'warnings' => [...]]
 * Row keys match what the page/export templates already use.
 */
function recon_run(mysqli $conn, mysqli $conn_exact, array $f, array $partners, $reference_column) {
    $warnings     = [];
    $partner_name = ($f['partner_id'] !== '' && !$f['is_all']) ? recon_partner_name($partners, $f['partner_id']) : '';
    $partner_map  = [];
    foreach ($partners as $p) $partner_map[(string)$p['partner_id_kpx']] = $p['partner_name'];
    list($df, $dt) = recon_date_bounds($f);

    // ---------- BP aggregate (no ORDER BY, no join; names come from $partners) ----------
    $col = $reference_column;
    $sql = "
        SELECT t.`{$col}` AS reference_no,
               MIN(t.partner_id_kpx)        AS partner_id_kpx,
               MAX(t.datetime)              AS datetime,
               SUM(t.amount_paid)           AS amt,
               SUM(t.charge_to_customer)    AS chg_c,
               SUM(t.charge_to_partner)     AS chg_p
        FROM mldb.billspayment_transaction t
        WHERE t.outlet <> 'ML CEBU HEAD OFFICE'
          AND t.`{$col}` IS NOT NULL AND t.`{$col}` <> ''
    ";
    $params = []; $types = '';
    if ($f['reference_no'] !== '') {
        $sql .= " AND t.`{$col}` = ?"; $params[] = $f['reference_no']; $types .= 's';
    }
    if ($f['partner_id'] !== '' && !$f['is_all']) {
        $sql .= ' AND t.partner_id_kpx = ?'; $params[] = $f['partner_id']; $types .= 's';
    }
    if ($df !== null) { $sql .= ' AND t.datetime >= ?'; $params[] = $df; $types .= 's'; }
    if ($dt !== null) { $sql .= ' AND t.datetime <= ?'; $params[] = $dt; $types .= 's'; }
    if ($f['date_only']) {
        $sql .= ' AND ' . recon_prefix_sql(RECON_DATE_ONLY_PREFIXES, "t.`{$col}`", $types, $params);
    }
    $sql .= " GROUP BY t.`{$col}` ORDER BY NULL LIMIT " . (RECON_BP_GROUP_CAP + 1);

    $err = '';
    $bp_rows = recon_fetch_all($conn, $sql, $types, $params, $err);
    if ($err !== '') $warnings[] = 'Local query error: ' . $err;
    if (count($bp_rows) > RECON_BP_GROUP_CAP) {
        array_pop($bp_rows);
        $warnings[] = 'Result capped at ' . RECON_BP_GROUP_CAP . ' BP references. Please narrow the filters.';
    }

    $bp = [];
    foreach ($bp_rows as $r) {
        $ref = trim((string)$r['reference_no']);
        if ($ref === '') continue;
        if (!isset($bp[$ref])) {
            $bp[$ref] = [
                'pid' => $r['partner_id_kpx'], 'dt' => $r['datetime'],
                'amt' => (float)$r['amt'], 'chg_c' => (float)$r['chg_c'], 'chg_p' => (float)$r['chg_p'],
            ];
        } else { // refs that only differed by whitespace
            $bp[$ref]['amt']   += (float)$r['amt'];
            $bp[$ref]['chg_c'] += (float)$r['chg_c'];
            $bp[$ref]['chg_p'] += (float)$r['chg_p'];
            if ((string)$r['datetime'] > (string)$bp[$ref]['dt']) $bp[$ref]['dt'] = $r['datetime'];
        }
    }
    unset($bp_rows);

    // ---------- GLE aggregates ----------
    // (a) every BP reference (+ the typed reference) -> BP -> GLE direction
    $lookup = array_map('strval', array_keys($bp));
    if ($f['reference_no'] !== '') $lookup[] = $f['reference_no'];

    // (b) partner mode: KBP references found via the partner's 428101 line (GLE -> BP direction)
    if ($partner_name !== '') {
        foreach (recon_gle_partner_candidate_refs($conn_exact, $partner_name, $df, $dt, $warnings) as $r) {
            if (!isset($bp[$r])) $lookup[] = (string)$r;
        }
    }
    $lookup = array_values(array_unique($lookup));

    $gle = recon_gle_agg_by_refs($conn_exact, $lookup, $df, $dt, $warnings);

    // (c) date-only / ALL partners: KBP references in the range (only KBP can be GLE-only)
    if ($f['date_only']) {
        foreach (recon_gle_agg_scoped_by_date($conn_exact, $df, $dt, $warnings) as $ref => $v) {
            if (!isset($gle[$ref])) $gle[$ref] = $v;
        }
    }

    // ---------- Scope ----------
    $keys = [];
    foreach ($bp as $ref => $_) $keys[(string)$ref] = true;
    foreach ($gle as $ref => $_) {
        $ref = (string)$ref;
        if ($ref === '') continue;
        if (isset($bp[$ref]) || recon_is_gle_to_bp_ref($ref)) $keys[$ref] = true;
    }
    $keys = array_map('strval', array_keys($keys));
    sort($keys, SORT_NATURAL | SORT_FLAG_CASE);

    // ---------- Reconcile ----------
    $rows = [];
    foreach ($keys as $ref) {
        $b = $bp[$ref]  ?? null;
        $g = $gle[$ref] ?? null;
        $has_local = ($b !== null);
        $has_gle   = ($g !== null);
        if (!$has_local && !$has_gle) continue;
        if (!$has_local && !recon_is_gle_to_bp_ref($ref)) continue;

        $bp_total = $has_local ? ($b['amt'] + $b['chg_c'] + $b['chg_p']) : null;
        $d = $has_gle ? $g['d'] : 0.0;
        $c = $has_gle ? $g['c'] : 0.0;

        $var_d = $var_c = null;
        if ($has_local && $has_gle) {
            $var_d = $bp_total - $d;
            $var_c = $bp_total - $c;
            $status = (abs($var_d) < 0.01 || abs($var_c) < 0.01) ? 'MATCHED' : 'VARIANCE';
        } elseif ($has_local) {
            $status = 'UNMATCHED (BP only)';
        } else {
            $status = 'UNMATCHED (GLE only)';
        }

        $pid = $has_local ? $b['pid'] : null;
        $rows[] = [
            'partner_id_kpx'     => $pid,
            'partner_name'       => $pid !== null ? ($partner_map[(string)$pid] ?? null) : null,
            'reference_no'       => $ref,
            'datetime'           => $has_local ? $b['dt'] : null,
            'amount_paid'        => $has_local ? $b['amt']   : null,
            'charge_to_customer' => $has_local ? $b['chg_c'] : null,
            'charge_to_partner'  => $has_local ? $b['chg_p'] : null,
            'gle_row_count'      => $has_gle ? $g['n'] : 0,
            'gle_debit_total'    => $has_gle ? $d : null,
            'gle_credit_total'   => $has_gle ? $c : null,
            'gle_rows'           => [],      // filled by recon_attach_details()
            'gle_desc'           => null,    // filled by recon_attach_details()
            'match_status'       => $status,
            'bp_total'           => $bp_total,
            'variance_debit'     => $var_d,
            'variance_credit'    => $var_c,
            'has_local'          => $has_local,
            'has_gle'            => $has_gle,
        ];
    }

    return [
        'rows'     => $rows,
        'totals'   => recon_sum_rows($rows),
        'warnings' => array_values(array_unique($warnings)),
        'created'  => time(),
    ];
}

// ------------------------------------------------------------
// PHASE 2: full GLE rows, only for the rows passed in
// ------------------------------------------------------------
function recon_attach_details(mysqli $conn_exact, array $rows, $df, $dt, array &$warnings) {
    $refs = [];
    foreach ($rows as $r) {
        if ($r['has_gle']) $refs[] = (string)$r['reference_no'];
    }
    if (!$refs) return $rows;

    $date_sql = ''; $date_params = []; $date_types = '';
    if ($df !== null) { $date_sql .= ' AND datetime >= ?'; $date_params[] = $df; $date_types .= 's'; }
    if ($dt !== null) { $date_sql .= ' AND datetime <= ?'; $date_params[] = $dt; $date_types .= 's'; }

    $by_ref = [];
    foreach (array_chunk(array_values(array_unique($refs)), RECON_REF_CHUNK) as $chunk) {
        $ph  = implode(',', array_fill(0, count($chunk), '?'));
        $sql = "SELECT `no`, datetime, gl_code, gl_desc, `desc`, reference, entry_number, debit, credit
                FROM gle
                WHERE reference IN ($ph)$date_sql";
        $err = '';
        $res = recon_fetch_all($conn_exact, $sql, str_repeat('s', count($chunk)) . $date_types,
                               array_merge($chunk, $date_params), $err);
        if ($err !== '') $warnings[] = 'GLE detail error: ' . $err;
        foreach ($res as $g) {
            $ref = trim((string)$g['reference']);
            if ($ref !== '') $by_ref[$ref][] = $g;
        }
    }

    foreach ($rows as &$r) {
        $ref = (string)$r['reference_no'];
        $g   = $by_ref[$ref] ?? [];
        if ($g) {
            usort($g, function ($a, $b) {
                $cmp = strcmp((string)($a['entry_number'] ?? ''), (string)($b['entry_number'] ?? ''));
                if ($cmp !== 0) return $cmp;
                $cmp = strcmp((string)($a['gl_code'] ?? ''), (string)($b['gl_code'] ?? ''));
                if ($cmp !== 0) return $cmp;
                return strcmp((string)($a['no'] ?? ''), (string)($b['no'] ?? ''));
            });
            $r['gle_rows']      = $g;
            $r['gle_row_count'] = count($g);
            $r['gle_desc']      = $g[0]['desc'] ?? null;
            if ($r['datetime'] === null) $r['datetime'] = $g[0]['datetime'] ?? null;
        }
    }
    unset($r);
    return $rows;
}

// ===== END inlined recon library =====


// Start the session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$current_user_email = '';
if (isset($_SESSION['user_type'])) {
    if ($_SESSION['user_type'] === 'admin' && isset($_SESSION['admin_email'])) {
        $current_user_email = $_SESSION['admin_email'];
    } elseif ($_SESSION['user_type'] === 'user' && isset($_SESSION['user_email'])) {
        $current_user_email = $_SESSION['user_email'];
    }
}

// ------------------------------------------------------------
// 0/1. Reference column + partners for the dropdown
// ------------------------------------------------------------
$reference_column = recon_detect_reference_column($conn);
$partners         = recon_load_partners($conn);

// ------------------------------------------------------------
// 2. Defaults
// ------------------------------------------------------------
$transactions   = [];
$filter_applied = false;
$debug_message  = '';

// --- OVERALL TOTALS (For Summary Cards) ---
$total_amount_paid     = 0;
$total_charge_customer = 0;
$total_charge_partner  = 0;
$total_debit           = 0;
$total_credit          = 0;
$matched_count         = 0;
$variance_count        = 0;
$unmatched_local       = 0;
$unmatched_gle         = 0;

// --- PAGE TOTALS (For Table Footer) ---
$page_amount_paid     = 0;
$page_charge_customer = 0;
$page_charge_partner  = 0;
$page_debit           = 0;
$page_credit          = 0;

// --- PAGE SUMMARY COUNTS ---
$page_matched_count   = 0;
$page_variance_count  = 0;
$page_unmatched_local = 0;
$page_unmatched_gle   = 0;

const RECON_PER_PAGE = 100;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($current_page < 1) $current_page = 1;

$total_refs  = 0;
$total_pages = 1;
$page_offset = 0;
$pager_html  = '';

$f            = recon_parse_filters($_GET);
$display_mode = $f['display_mode'];

if (isset($_GET['filter'])) {

    $debug_message = recon_validate_filters($f);

    if ($debug_message === '') {
        $filter_applied = true;

        // Release the session lock before the heavy work so other tabs/requests aren't blocked.
        // ($_SESSION stays readable. If header/sidebar templates WRITE to the session, remove this line.)
        session_write_close();

        // ---------------- Phase 1: aggregates (cached) ----------------
        // "Filter & Recon" button (no &page=) always recomputes fresh data.
        // Pager links (&page=N) reuse the cache so paging is instant.
        $cache_key = recon_cache_key($f);
        $result    = isset($_GET['page']) ? recon_cache_get($cache_key) : null;
        if ($result === null) {
            $result = recon_run($conn, $conn_exact, $f, $partners, $reference_column);
            recon_cache_put($cache_key, $result);
        }

        if (!empty($result['warnings'])) {
            $debug_message = implode(' | ', $result['warnings']);
        }

        $T = $result['totals'];
        $total_amount_paid     = $T['amount_paid'];
        $total_charge_customer = $T['charge_customer'];
        $total_charge_partner  = $T['charge_partner'];
        $total_debit           = $T['debit'];
        $total_credit          = $T['credit'];
        $matched_count         = $T['matched'];
        $variance_count        = $T['variance'];
        $unmatched_local       = $T['unmatched_local'];
        $unmatched_gle         = $T['unmatched_gle'];

        // ---------------- Display filter + pagination ----------------
        $filtered_transactions = recon_filter_rows($result['rows'], $display_mode);

        $total_refs  = count($filtered_transactions);
        $total_pages = $total_refs > 0 ? (int)ceil($total_refs / RECON_PER_PAGE) : 1;
        if ($current_page > $total_pages) $current_page = $total_pages;
        $page_offset = ($current_page - 1) * RECON_PER_PAGE;

        $transactions = array_slice($filtered_transactions, $page_offset, RECON_PER_PAGE);
        unset($filtered_transactions, $result);

        // ---------------- Page totals & counts (from aggregates) ----------------
        $PT = recon_sum_rows($transactions);
        $page_amount_paid     = $PT['amount_paid'];
        $page_charge_customer = $PT['charge_customer'];
        $page_charge_partner  = $PT['charge_partner'];
        $page_debit           = $PT['debit'];
        $page_credit          = $PT['credit'];
        $page_matched_count   = $PT['matched'];
        $page_variance_count  = $PT['variance'];
        $page_unmatched_local = $PT['unmatched_local'];
        $page_unmatched_gle   = $PT['unmatched_gle'];

        // ---------------- Phase 2: GLE detail rows for THIS PAGE ONLY ----------------
        list($df_val, $dt_val) = recon_date_bounds($f);
        $detail_warnings = [];
        $transactions = recon_attach_details($conn_exact, $transactions, $df_val, $dt_val, $detail_warnings);
        if ($detail_warnings) {
            $debug_message .= ($debug_message ? ' | ' : '') . implode(' | ', $detail_warnings);
        }

        // ---------------- Pager HTML ----------------
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

// Combined totals for Overall Summary Cards
$total_bp_combined = $total_amount_paid + $total_charge_customer + $total_charge_partner;

// Combined totals for Page Footer and Page Summary Cards
$page_bp_combined = $page_amount_paid + $page_charge_customer + $page_charge_partner;

$display_transactions = $transactions;

// Variances (OVERALL)
$variance_vs_debit  = $total_bp_combined - $total_debit;
$variance_vs_credit = $total_bp_combined - $total_credit;

// Variances (PAGE)
$page_variance_vs_debit  = $page_bp_combined - $page_debit;
$page_variance_vs_credit = $page_bp_combined - $page_credit;

// Build export query string
$export_params = [
    'reference_no' => $_GET['reference_no'] ?? '',
    'partner'      => $_GET['partner']      ?? '',
    'date_from'    => $_GET['date_from']    ?? '',
    'date_to'      => $_GET['date_to']      ?? '',
    'display_mode' => $_GET['display_mode'] ?? 'variance',
    'page'         => $current_page,
];
$export_qs     = http_build_query($export_params);
$export_csv_qs = http_build_query($export_params + ['format' => 'csv']);

// ------------------------------------------------------------
// Render helpers (same markup as before, just not repeated 5 times)
// ------------------------------------------------------------
function recon_render_gle_cells(array $g, $extra = '') {
    ?>
    <td class="col-glcode" style="padding: 0.35rem 0.5rem; font-weight:600; color:#1a3c5e; text-align: right;<?php echo $extra; ?>">
        <?php echo htmlspecialchars($g['gl_code'] ?? '—'); ?>
    </td>
    <td class="col-gldesc wrap-cell" style="padding: 0.35rem 0.5rem;<?php echo $extra; ?>">
        <?php echo htmlspecialchars($g['gl_desc'] ?? '—'); ?>
    </td>
    <td class="col-desc wrap-cell" style="padding: 0.35rem 0.5rem;<?php echo $extra; ?>">
        <?php echo htmlspecialchars($g['desc'] ?? '—'); ?>
    </td>
    <td class="col-reference" style="padding: 0.35rem 0.5rem;<?php echo $extra; ?>">
        <?php echo htmlspecialchars($g['reference'] ?? '—'); ?>
    </td>
    <td class="col-datetime" style="padding: 0.35rem 0.5rem; color:#000000;<?php echo $extra; ?>">
        <?php echo htmlspecialchars($g['datetime'] ?? '—'); ?>
    </td>
    <td class="col-debit" style="padding: 0.35rem 0.5rem; text-align: right; color:#1565c0; font-weight:600;<?php echo $extra; ?>">
        <?php echo number_format((float)($g['debit'] ?? 0), 2); ?>
    </td>
    <td class="col-credit" style="padding: 0.35rem 0.5rem; text-align: right; color:#6a1b9a; font-weight:600;<?php echo $extra; ?>">
        <?php echo number_format((float)($g['credit'] ?? 0), 2); ?>
    </td>
    <td class="col-entry" style="padding: 0.35rem 0.5rem;<?php echo $extra; ?>">
        <?php echo htmlspecialchars($g['entry_number'] ?? '—'); ?>
    </td>
    <?php
}

function recon_render_bp_subrow(array $row) {
    $has = $row['has_local'];
    ?>
    <tr>
        <td colspan="4" class="divider-right" style="padding: 0.25rem 0.5rem 0.35rem 0.5rem; text-align: right; border-top: none; background: #fcfafa;">
            <span style="font-size: 10px; color: #666; text-transform: uppercase; margin-right: 4px;">Amt Paid:</span> <strong style="color: #0d47a1; margin-right: 12px;"><?php echo $has && $row['amount_paid'] !== null ? number_format((float)$row['amount_paid'], 2) : '—'; ?></strong>

            <span style="font-size: 10px; color: #666; text-transform: uppercase; margin-right: 4px;">Chg Cust:</span> <strong style="color: #b30000; margin-right: 12px;"><?php echo $has && $row['charge_to_customer'] !== null ? number_format((float)$row['charge_to_customer'], 2) : '—'; ?></strong>

            <span style="font-size: 10px; color: #666; text-transform: uppercase; margin-right: 4px;">Chg Partr:</span> <strong style="color: #2e7d32;"><?php echo $has && $row['charge_to_partner'] !== null ? number_format((float)$row['charge_to_partner'], 2) : '—'; ?></strong>
        </td>
        <td colspan="8" style="border-top: none; background: #fafbfc;"></td>
    </tr>
    <?php
}
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
                            <option value="variance" <?php echo ($display_mode === 'variance') ? 'selected' : ''; ?>>Variance</option>
                            <option value="bp_only" <?php echo ($display_mode === 'bp_only') ? 'selected' : ''; ?>>BP Only (Unmatched)</option>
                            <option value="gle_only" <?php echo ($display_mode === 'gle_only') ? 'selected' : ''; ?>>GLE Only (Unmatched)</option>
                            <option value="matched" <?php echo ($display_mode === 'matched') ? 'selected' : ''; ?>>Matched Only</option>
                            <option value="all" <?php echo ($display_mode === 'all') ? 'selected' : ''; ?>>All Transactions</option>
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
        <?php if ($filter_applied && ($total_refs > 0)): ?>
            <div style="margin: 1rem; display: flex; flex-direction: column; gap: 1rem;">

                <!-- ROW 1: Overall Financial Totals -->
                <div style="display: flex; flex-wrap: wrap; gap: 1rem;">
                    <div style="flex: 1 1 0; min-width: 140px; background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); padding: .5rem 1rem; border-left: 5px solid #1976d2;">
                        <div style="font-size: 0.7rem; color: #666; text-transform: uppercase;">Overall Amount Paid</div>
                        <div style="font-size: 0.95rem; font-weight: 700; color: #1976d2; margin-top: 0.2rem; white-space: nowrap;">
                            <?php echo number_format($total_amount_paid, 2); ?>
                        </div>
                    </div>

                    <div style="flex: 1 1 0; min-width: 140px; background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); padding: .5rem 1rem; border-left: 5px solid #e65100;">
                        <div style="font-size: 0.7rem; color: #666; text-transform: uppercase;">Overall Chg to Cust</div>
                        <div style="font-size: 0.95rem; font-weight: 700; color: #e65100; margin-top: 0.2rem; white-space: nowrap;">
                            <?php echo number_format($total_charge_customer, 2); ?>
                        </div>
                    </div>

                    <div style="flex: 1 1 0; min-width: 140px; background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); padding: .5rem 1rem; border-left: 5px solid #2e7d32;">
                        <div style="font-size: 0.7rem; color: #666; text-transform: uppercase;">Overall Chg to Partr</div>
                        <div style="font-size: 0.95rem; font-weight: 700; color: #2e7d32; margin-top: 0.2rem; white-space: nowrap;">
                            <?php echo number_format($total_charge_partner, 2); ?>
                        </div>
                    </div>

                    <div style="flex: 1 1 0; min-width: 140px; background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); padding: .5rem 1rem; border-left: 5px solid #6a1b9a;">
                        <div style="font-size: 0.7rem; color: #666; text-transform: uppercase;">Overall BP Total</div>
                        <div style="font-size: 0.95rem; font-weight: 700; color: #6a1b9a; margin-top: 0.2rem; white-space: nowrap;">
                            <?php echo number_format($total_bp_combined, 2); ?>
                        </div>
                    </div>

                    <div style="flex: 1 1 0; min-width: 140px; background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); padding: .5rem 1rem; border-left: 5px solid #1565c0;">
                        <div style="font-size: 0.7rem; color: #666; text-transform: uppercase;">Overall Debit</div>
                        <div style="font-size: 0.95rem; font-weight: 700; color: #1565c0; margin-top: 0.2rem; white-space: nowrap;">
                            <?php echo number_format($total_debit, 2); ?>
                        </div>
                    </div>

                    <div style="flex: 1 1 0; min-width: 140px; background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); padding: .5rem 1rem; border-left: 5px solid #c62828;">
                        <div style="font-size: 0.7rem; color: #666; text-transform: uppercase;">Overall Credit</div>
                        <div style="font-size: 0.95rem; font-weight: 700; color: #c62828; margin-top: 0.2rem; white-space: nowrap;">
                            <?php echo number_format($total_credit, 2); ?>
                        </div>
                    </div>
                </div>

                <!-- ROW 2: Variance & Count Cards -->
                <div style="display: flex; flex-wrap: wrap; gap: 1rem;">
                    <div style="flex: 1 1 0; min-width: 140px; background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); padding: .5rem 1rem; border-left: 5px solid #ef6c00;">
                        <div style="font-size: 0.7rem; color: #666; text-transform: uppercase;">Overall Matched / Var / BP / GLE</div>
                        <div style="font-size: 0.95rem; font-weight: 700; color: #ef6c00; margin-top: 0.2rem; white-space: nowrap;">
                            <?php echo $matched_count; ?> / <?php echo $variance_count; ?> / <?php echo $unmatched_local; ?> / <?php echo $unmatched_gle; ?>
                        </div>
                    </div>

                    <div style="flex: 1 1 0; min-width: 140px; background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); padding: .5rem 1rem; border-left: 5px solid #c62828;">
                        <div style="font-size: 0.7rem; color: #666; text-transform: uppercase;">Overall BP Total - GLE Debit</div>
                        <div style="font-size: 0.95rem; font-weight: 700; color: <?php echo $variance_vs_debit == 0 ? '#2e7d32' : '#c62828'; ?>; margin-top: 0.2rem; white-space: nowrap;">
                            <?php if ($variance_vs_debit == 0): ?><i class="fa-solid fa-circle-check" style="font-size:0.8rem;"></i><?php else: ?><i class="fa-solid fa-triangle-exclamation" style="font-size:0.8rem;"></i><?php endif; ?>
                            <?php echo number_format($variance_vs_debit, 2); ?>
                        </div>
                    </div>

                    <div style="flex: 1 1 0; min-width: 140px; background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); padding: .5rem 1rem; border-left: 5px solid #ad1457;">
                        <div style="font-size: 0.7rem; color: #666; text-transform: uppercase;">Overall BP Total - GLE Credit</div>
                        <div style="font-size: 0.95rem; font-weight: 700; color: <?php echo $variance_vs_credit == 0 ? '#2e7d32' : '#ad1457'; ?>; margin-top: 0.2rem; white-space: nowrap;">
                            <?php if ($variance_vs_credit == 0): ?><i class="fa-solid fa-circle-check" style="font-size:0.8rem;"></i><?php else: ?><i class="fa-solid fa-triangle-exclamation" style="font-size:0.8rem;"></i><?php endif; ?>
                            <?php echo number_format($variance_vs_credit, 2); ?>
                        </div>
                    </div>

                    <div style="flex: 1 1 0; min-width: 140px; background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); padding: .5rem 1rem; border-left: 5px solid #ef6c00;">
                        <div style="font-size: 0.7rem; color: #666; text-transform: uppercase;">Page Matched / Var / BP / GLE</div>
                        <div style="font-size: 0.95rem; font-weight: 700; color: #ef6c00; margin-top: 0.2rem; white-space: nowrap;">
                            <?php echo $page_matched_count; ?> / <?php echo $page_variance_count; ?> / <?php echo $page_unmatched_local; ?> / <?php echo $page_unmatched_gle; ?>
                        </div>
                    </div>

                    <div style="flex: 1 1 0; min-width: 140px; background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); padding: .5rem 1rem; border-left: 5px solid #c62828;">
                        <div style="font-size: 0.7rem; color: #666; text-transform: uppercase;">Page BP Total - GLE Debit</div>
                        <div style="font-size: 0.95rem; font-weight: 700; color: <?php echo $page_variance_vs_debit == 0 ? '#2e7d32' : '#c62828'; ?>; margin-top: 0.2rem; white-space: nowrap;">
                            <?php if ($page_variance_vs_debit == 0): ?><i class="fa-solid fa-circle-check" style="font-size:0.8rem;"></i><?php else: ?><i class="fa-solid fa-triangle-exclamation" style="font-size:0.8rem;"></i><?php endif; ?>
                            <?php echo number_format($page_variance_vs_debit, 2); ?>
                        </div>
                    </div>

                    <div style="flex: 1 1 0; min-width: 140px; background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); padding: .5rem 1rem; border-left: 5px solid #ad1457;">
                        <div style="font-size: 0.7rem; color: #666; text-transform: uppercase;">Page BP Total - GLE Credit</div>
                        <div style="font-size: 0.95rem; font-weight: 700; color: <?php echo $page_variance_vs_credit == 0 ? '#2e7d32' : '#ad1457'; ?>; margin-top: 0.2rem; white-space: nowrap;">
                            <?php if ($page_variance_vs_credit == 0): ?><i class="fa-solid fa-circle-check" style="font-size:0.8rem;"></i><?php else: ?><i class="fa-solid fa-triangle-exclamation" style="font-size:0.8rem;"></i><?php endif; ?>
                            <?php echo number_format($page_variance_vs_credit, 2); ?>
                        </div>
                    </div>
                </div>

            </div>
        <?php endif; ?>

        <!-- Results table -->
        <div class="bp-card" style="margin: .5rem 1rem; background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); overflow: hidden;">
            <div style="padding: .2rem 1rem; background: #f8f9fa; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: .5rem;">
                <h3 style="margin: 0; font-size: 1rem; color: #1a3c5e;"><i class="fa-solid fa-table"></i> Reconciliation Results</h3>
                <?php if ($pager_html !== ''): ?>
                <div style="padding: .4rem 1rem; background: #fff; border-bottom: 1px solid #eee;">
                    <?php echo $pager_html; ?>
                </div>
                <?php endif; ?>
                <div style="font-size: 0.85rem; color: #666; display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;">
                    <?php if ($filter_applied && count($transactions) > 0): ?>
                        <!-- EXPORT PDF (capped for large result sets) -->
                        <a id="export-pdf-btn" class="export-btn"
                           href="export_recon.php?<?php echo htmlspecialchars($export_qs); ?>"
                           target="_blank"
                           title="PDF is limited to <?php echo RECON_PDF_MAX_REFS; ?> references; use CSV for larger sets"
                           style="background: #b30000; color: #fff; border: 1px solid #8b0000; border-radius: 4px; padding: 3px 10px; cursor: pointer; font-size: 0.8rem; text-decoration: none; display: inline-flex; align-items: center; gap: 5px;">
                            <i class="fa-solid fa-file-pdf"></i> Export PDF
                        </a>
                        <!-- EXPORT CSV (fast, no row cap) -->
                        <a id="export-csv-btn" class="export-btn"
                           href="export_recon.php?<?php echo htmlspecialchars($export_csv_qs); ?>"
                           style="background: #2e7d32; color: #fff; border: 1px solid #1b5e20; border-radius: 4px; padding: 3px 10px; cursor: pointer; font-size: 0.8rem; text-decoration: none; display: inline-flex; align-items: center; gap: 5px;">
                            <i class="fa-solid fa-file-csv"></i> Export CSV
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

            <!-- Scrollable area – max-height + sticky headers -->
            <div style="overflow-x: auto; max-height: 48vh; overflow-y: auto;">
                <table class="bp-table" style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
                    <thead>
                        <tr class="group-header">
                            <th colspan="4" class="group-local" style="text-align: center;">
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
                            <th class="col-local col-datetime divider-right" style="padding: 0.35rem 0.5rem; text-align: center;">Datetime</th>
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

                                <?php if (!empty($child_rows)): ?>
                                    <?php foreach ($child_rows as $g_index => $g): ?>
                                        <?php if ($g_index === 0): ?>
                                            <!-- First GLE row carries the BP info -->
                                            <tr>
                                                <td class="col-status" style="padding: 0.35rem 0.5rem;"><?php echo $status_html; ?></td>
                                                <td class="col-partner wrap-cell" style="padding: 0.35rem 0.5rem;"><?php echo htmlspecialchars($partner_display); ?></td>
                                                <td class="col-reference" style="padding: 0.35rem 0.5rem;"><?php echo htmlspecialchars($row['reference_no'] ?? '—'); ?></td>
                                                <td class="col-datetime divider-right" style="padding: 0.35rem 0.5rem;"><?php echo $row['has_local'] ? htmlspecialchars($row['datetime'] ?? '—') : '—'; ?></td>
                                                <?php recon_render_gle_cells($g); ?>
                                            </tr>
                                            <?php recon_render_bp_subrow($row); ?>
                                        <?php else: ?>
                                            <!-- Subsequent GLE rows -->
                                            <tr>
                                                <td colspan="4" class="divider-right" style="border-top: none;"></td>
                                                <?php recon_render_gle_cells($g, ' border-top: none;'); ?>
                                            </tr>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <!-- NO GLE ROWS -->
                                    <tr>
                                        <td class="col-status" style="padding: 0.35rem 0.5rem;"><?php echo $status_html; ?></td>
                                        <td class="col-partner wrap-cell" style="padding: 0.35rem 0.5rem;"><?php echo htmlspecialchars($partner_display); ?></td>
                                        <td class="col-reference" style="padding: 0.35rem 0.5rem;"><?php echo htmlspecialchars($row['reference_no'] ?? '—'); ?></td>
                                        <td class="col-datetime divider-right" style="padding: 0.35rem 0.5rem;"><?php echo $row['has_local'] ? htmlspecialchars($row['datetime'] ?? '—') : '—'; ?></td>
                                        <td class="col-glcode" style="padding: 0.35rem 0.5rem; text-align: right;">—</td>
                                        <td class="col-gldesc" style="padding: 0.35rem 0.5rem;">—</td>
                                        <td class="col-desc" style="padding: 0.35rem 0.5rem;">—</td>
                                        <td class="col-reference" style="padding: 0.35rem 0.5rem;"><?php echo htmlspecialchars($row['reference_no'] ?? '—'); ?></td>
                                        <td class="col-datetime" style="padding: 0.35rem 0.5rem; text-align: right; font-weight: 600; color:#1565c0;">—</td>
                                        <td class="col-debit" style="padding: 0.35rem 0.5rem; text-align: right; font-weight: 600; color:#6a1b9a;">—</td>
                                        <td class="col-credit" style="padding: 0.35rem 0.5rem; text-align: right;">—</td>
                                        <td class="col-entry" style="padding: 0.35rem 0.5rem;">—</td>
                                    </tr>
                                    <?php recon_render_bp_subrow($row); ?>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php elseif ($filter_applied && count($display_transactions) === 0): ?>
                            <tr>
                                <td colspan="12" style="padding: 2rem; text-align: center; color: #888;">
                                    <i class="fa-regular fa-folder-open" style="font-size: 1.5rem; display: block; margin-bottom: 0.5rem;"></i>
                                    No transactions match the selected display option and filters.
                                </td>
                            </tr>
                        <?php else: ?>
                            <tr>
                                <td colspan="12" style="padding: 2rem; text-align: center; color: #888;">
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
                                </td>
                                <td colspan="5"> <i class="fa-solid fa-calculator"></i> PAGE TOTAL</td>
                                <td style="text-align: right;"><?php echo number_format($page_debit, 2); ?></td>
                                <td style="text-align: right;"><?php echo number_format($page_credit, 2); ?></td>
                                <td></td>
                            </tr>
                            <tr class="total-row">
                                <td colspan="4" style="text-align: right; border-top: none; background: #910000;">
                                    <span style="font-size: 10px; color: #ffffff; text-transform: uppercase; margin-right: 4px;">Amt Paid:</span> <strong style="color: #ffffff; margin-right: 12px;"><?php echo number_format($page_amount_paid, 2); ?></strong>

                                    <span style="font-size: 10px; color: #ffffff; text-transform: uppercase; margin-right: 4px;">Chg Cust:</span> <strong style="color: #ffffff; margin-right: 12px;"><?php echo number_format($page_charge_customer, 2); ?></strong>

                                    <span style="font-size: 10px; color: #ffffff; text-transform: uppercase; margin-right: 4px;">Chg Partr:</span> <strong style="color: #ffffff;"><?php echo number_format($page_charge_partner, 2); ?></strong>
                                    <span style="font-size: 10px; color: #ffffff; text-transform: uppercase; margin-right: 4px;">BP Total:</span> <strong style="color: #ffffff;"><?php echo number_format($page_bp_combined, 2); ?></strong>
                                </td>
                                <td colspan="8" style="border-top: none; background: #910000; vertical-align: middle;">

                                </td>
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
                        $('#filter-form')[0].submit();
                    }
                });
                return false;
            }

            $('#loading-overlay').css('display', 'flex');
        });

        // ---------- Export validation (PDF + CSV) ----------
        $('.export-btn').on('click', function(e) {
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
