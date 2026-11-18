<?php
// export_recon.php
// ?format=pdf (default, capped at RECON_PDF_MAX_REFS references)  |  ?format=csv (streamed, no cap)
require_once __DIR__ . '/../../../config/config.php';
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

date_default_timezone_set('Asia/Manila');

use Dompdf\Dompdf;
use Dompdf\Options;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$generated_by = $_SESSION['admin_email'] ?? ($_SESSION['user_email'] ?? 'Guest');
// Release the session lock immediately: a long export must not block the user's other tabs.
session_write_close();

set_time_limit(3600);
ini_set('memory_limit', '1024M');

// ------------------------------------------------------------
// 1. Filters (same parser/validation as the main page)
// ------------------------------------------------------------
$f      = recon_parse_filters($_GET);
$format = (isset($_GET['format']) && $_GET['format'] === 'csv') ? 'csv' : 'pdf';

$err = recon_validate_filters($f);
if ($err !== '') {
    die(htmlspecialchars($err));
}

$reference_column = recon_detect_reference_column($conn);
$partners         = recon_load_partners($conn);
$partner_name     = ($f['partner_id'] !== '' && !$f['is_all']) ? recon_partner_name($partners, $f['partner_id']) : '';
list($df_val, $dt_val) = recon_date_bounds($f);

// ------------------------------------------------------------
// 2. Phase 1: aggregates (reuse the cache written by the main page)
// ------------------------------------------------------------
$cache_key = recon_cache_key($f);
$result    = recon_cache_get($cache_key);
if ($result === null) {
    $result = recon_run($conn, $conn_exact, $f, $partners, $reference_column);
    recon_cache_put($cache_key, $result);
}

$recon_warnings = $result['warnings'] ?? [];
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

$total_bp_combined  = $total_amount_paid + $total_charge_customer + $total_charge_partner;
$variance_vs_debit  = $total_bp_combined - $total_debit;
$variance_vs_credit = $total_bp_combined - $total_credit;

// Rows to export (light aggregate rows). Details are loaded in chunks below.
$display_transactions = recon_filter_rows($result['rows'], $f['display_mode']);
unset($result);

$DT = recon_sum_rows($display_transactions); // totals of what is actually exported
$disp_bp_combined = $DT['amount_paid'] + $DT['charge_customer'] + $DT['charge_partner'];

$generated_at = date('Y-m-d h:i:s A');

$filter_parts = [];
if ($f['reference_no'] !== '') $filter_parts[] = 'Reference: ' . $f['reference_no'];
if ($f['partner_id'] !== '')   $filter_parts[] = 'Partner: ' . ($f['is_all'] ? 'ALL PARTNERS' : $f['partner_id'] . ($partner_name ? " - $partner_name" : ''));
if ($f['date_from'] !== '')    $filter_parts[] = 'From: ' . $f['date_from'];
if ($f['date_to'] !== '')      $filter_parts[] = 'To: ' . $f['date_to'];
if ($f['display_mode'] !== 'all') $filter_parts[] = 'Display: ' . ucfirst(str_replace('_', ' ', $f['display_mode']));
$filter_summary = implode(' | ', $filter_parts);

function recon_partner_display(array $row) {
    if (!empty($row['partner_id_kpx']) || !empty($row['partner_name'])) {
        return trim(($row['partner_id_kpx'] ?? '') . ' - ' . ($row['partner_name'] ?? ''), ' -');
    }
    if (!empty($row['gle_desc'])) return $row['gle_desc'];
    return '—';
}

// ============================================================
// CSV (streamed in chunks - fast, no row cap)
// ============================================================
if ($format === 'csv') {
    $filename = 'Recon_Report_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads it correctly

    fputcsv($out, ['Bills Payment vs GLE Reconciliation Report']);
    fputcsv($out, ['Filters', $filter_summary ?: 'None']);
    fputcsv($out, ['Generated by', $generated_by, 'Generated at', $generated_at]);
    fputcsv($out, ['Overall Amount Paid', number_format($total_amount_paid, 2),
                   'Overall Chg Cust', number_format($total_charge_customer, 2),
                   'Overall Chg Partr', number_format($total_charge_partner, 2),
                   'Overall BP Total', number_format($total_bp_combined, 2),
                   'Overall GLE Debit', number_format($total_debit, 2),
                   'Overall GLE Credit', number_format($total_credit, 2)]);
    fputcsv($out, ['Matched', $matched_count, 'Variance', $variance_count,
                   'BP only', $unmatched_local, 'GLE only', $unmatched_gle]);
    if (!empty($recon_warnings)) fputcsv($out, ['Warnings', implode(' | ', $recon_warnings)]);
    fputcsv($out, []);

    // BP amounts appear only on the FIRST line of each reference so pivots/sums don't double count.
    fputcsv($out, ['Status', 'Reference', 'Partner', 'BP Datetime',
                   'BP Amt Paid', 'BP Chg Cust', 'BP Chg Partr', 'BP Total', 'Var (BP-GLE Debit)', 'Var (BP-GLE Credit)',
                   'GL Code', 'GL Desc', 'GLE Partner', 'GLE Reference', 'GLE Datetime', 'Debit', 'Credit', 'Entry No.']);

    $detail_warnings = [];
    foreach (array_chunk($display_transactions, 500) as $chunk) {
        $chunk = recon_attach_details($conn_exact, $chunk, $df_val, $dt_val, $detail_warnings);
        foreach ($chunk as $row) {
            $partner_display = recon_partner_display($row);
            $bp_cells = [
                $row['has_local'] ? $row['datetime'] : '',
                $row['has_local'] ? number_format((float)$row['amount_paid'], 2) : '',
                $row['has_local'] ? number_format((float)$row['charge_to_customer'], 2) : '',
                $row['has_local'] ? number_format((float)$row['charge_to_partner'], 2) : '',
                $row['has_local'] ? number_format((float)$row['bp_total'], 2) : '',
                ($row['has_local'] && $row['has_gle']) ? number_format((float)$row['variance_debit'], 2) : '',
                ($row['has_local'] && $row['has_gle']) ? number_format((float)$row['variance_credit'], 2) : '',
            ];
            $blank_bp = ['', '', '', '', '', '', ''];

            if (empty($row['gle_rows'])) {
                fputcsv($out, array_merge([$row['match_status'], $row['reference_no'], $partner_display],
                                          $bp_cells, ['', '', '', '', '', '', '', '']));
                continue;
            }
            foreach ($row['gle_rows'] as $i => $g) {
                fputcsv($out, array_merge(
                    [$row['match_status'], $row['reference_no'], $partner_display],
                    $i === 0 ? $bp_cells : $blank_bp,
                    [
                        $g['gl_code'] ?? '', $g['gl_desc'] ?? '', $g['desc'] ?? '', $g['reference'] ?? '', $g['datetime'] ?? '',
                        number_format((float)($g['debit'] ?? 0), 2),
                        number_format((float)($g['credit'] ?? 0), 2),
                        $g['entry_number'] ?? '',
                    ]
                ));
            }
        }
        unset($chunk);
        flush();
    }

    fputcsv($out, []);
    fputcsv($out, ['TOTAL (exported rows)', '', '', '',
                   number_format($DT['amount_paid'], 2),
                   number_format($DT['charge_customer'], 2),
                   number_format($DT['charge_partner'], 2),
                   number_format($disp_bp_combined, 2), '', '',
                   '', '', '', '', '',
                   number_format($DT['debit'], 2),
                   number_format($DT['credit'], 2), '']);
    fclose($out);
    exit;
}

// ============================================================
// PDF
// ============================================================
require_once __DIR__ . '/../../../vendor/autoload.php';

if (count($display_transactions) > RECON_PDF_MAX_REFS) {
    $csv_qs = http_build_query(array_merge($_GET, ['format' => 'csv']));
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Too many rows for PDF</title></head>'
       . '<body style="font-family:Arial,sans-serif;padding:2rem;">'
       . '<h3>This export has ' . number_format(count($display_transactions)) . ' references.</h3>'
       . '<p>PDF export is limited to ' . number_format(RECON_PDF_MAX_REFS) . ' references to keep it fast and reliable. '
       . 'Either narrow the filters (e.g. a partner, a shorter date range, or a different Display option), '
       . 'or download the full data as CSV.</p>'
       . '<p><a href="export_recon.php?' . htmlspecialchars($csv_qs) . '" '
       . 'style="background:#2e7d32;color:#fff;padding:8px 16px;border-radius:4px;text-decoration:none;">Download CSV</a></p>'
       . '</body></html>';
    exit;
}

function recon_pdf_bp_subrow(array $row) {
    $has = $row['has_local'];
    $h  = '<tr class="bp-subrow">';
    $h .= '<td colspan="4" class="num" style="border-top: none;">';
    $h .= '<span style="text-transform: uppercase; font-size: 6px; color: #666;">Amt Paid:</span> <strong>' . ($has && $row['amount_paid'] !== null ? number_format((float)$row['amount_paid'], 2) : '—') . '</strong> &nbsp;&nbsp;';
    $h .= '<span style="text-transform: uppercase; font-size: 6px; color: #666;">Chg Cust:</span> <strong>' . ($has && $row['charge_to_customer'] !== null ? number_format((float)$row['charge_to_customer'], 2) : '—') . '</strong> &nbsp;&nbsp;';
    $h .= '<span style="text-transform: uppercase; font-size: 6px; color: #666;">Chg Partr:</span> <strong>' . ($has && $row['charge_to_partner'] !== null ? number_format((float)$row['charge_to_partner'], 2) : '—') . '</strong>';
    $h .= '</td>';
    $h .= '<td colspan="8" style="border-top: none;"></td>';
    $h .= '</tr>';
    return $h;
}

function recon_pdf_gle_cells(array $g) {
    return '<td class="num">' . htmlspecialchars($g['gl_code'] ?? '—') . '</td>'
         . '<td>' . htmlspecialchars($g['gl_desc'] ?? '—') . '</td>'
         . '<td>' . htmlspecialchars($g['desc'] ?? '—') . '</td>'
         . '<td>' . htmlspecialchars($g['reference'] ?? '—') . '</td>'
         . '<td>' . htmlspecialchars($g['datetime'] ?? '—') . '</td>'
         . '<td class="num">' . number_format((float)($g['debit'] ?? 0), 2) . '</td>'
         . '<td class="num">' . number_format((float)($g['credit'] ?? 0), 2) . '</td>'
         . '<td>' . htmlspecialchars($g['entry_number'] ?? '—') . '</td>';
}

function recon_pdf_row_html(array $row) {
    $child_rows = $row['gle_rows'] ?? [];
    switch ($row['match_status']) {
        case 'MATCHED':             $status_class = 'status-matched';  break;
        case 'VARIANCE':            $status_class = 'status-variance'; break;
        case 'UNMATCHED (BP only)': $status_class = 'status-bp-only';  break;
        default:                    $status_class = 'status-gle-only';
    }
    $partner_display = recon_partner_display($row);
    $dt = htmlspecialchars($row['has_local'] ? ($row['datetime'] ?? '—') : '—');

    $head = '<td class="center ' . $status_class . '">' . htmlspecialchars($row['match_status']) . '</td>'
          . '<td>' . htmlspecialchars($partner_display) . '</td>'
          . '<td>' . htmlspecialchars($row['reference_no'] ?? '—') . '</td>'
          . '<td>' . $dt . '</td>';

    $html = '';
    if (empty($child_rows)) {
        $html .= '<tr>' . $head . '<td class="center">—</td><td>—</td><td>—</td><td>—</td><td>—</td><td>—</td><td>—</td><td>—</td></tr>';
        $html .= recon_pdf_bp_subrow($row);
        return $html;
    }
    foreach ($child_rows as $i => $g) {
        $html .= '<tr>' . ($i === 0 ? $head : '<td class="center"></td><td></td><td></td><td></td>')
               . recon_pdf_gle_cells($g) . '</tr>';
        if ($i === 0) $html .= recon_pdf_bp_subrow($row);
    }
    return $html;
}

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
        <strong>Filters:</strong> ' . htmlspecialchars($filter_summary ?: 'None') . '<br>
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

    // Phase 2: GLE detail rows loaded in chunks, only for exported references
    $detail_warnings = [];
    foreach (array_chunk($display_transactions, 500) as $chunk) {
        $chunk = recon_attach_details($conn_exact, $chunk, $df_val, $dt_val, $detail_warnings);
        foreach ($chunk as $row) {
            $html .= recon_pdf_row_html($row);
        }
        unset($chunk);
    }

    // Footer = totals of the rows shown in this report (overall totals are in the summary at the top)
    $html .= '
        </tbody>
        <tfoot>
            <tr>
                <td colspan="4" class="num"></td>
                <td colspan="5">TOTAL (displayed rows)</td>
                <td class="num">' . number_format($DT['debit'], 2) . '</td>
                <td class="num">' . number_format($DT['credit'], 2) . '</td>
                <td></td>
            </tr>
            <tr>
                <td colspan="4" class="num" style="border-top: none;">
                    <span style="text-transform: uppercase; font-size: 6px; color: #666;">Amt Paid:</span> <strong>' . number_format($DT['amount_paid'], 2) . '</strong> &nbsp;&nbsp;
                    <span style="text-transform: uppercase; font-size: 6px; color: #666;">Chg Cust:</span> <strong>' . number_format($DT['charge_customer'], 2) . '</strong> &nbsp;&nbsp;
                    <span style="text-transform: uppercase; font-size: 6px; color: #666;">Chg Partr:</span> <strong>' . number_format($DT['charge_partner'], 2) . '</strong> &nbsp;&nbsp;
                    <span style="text-transform: uppercase; font-size: 6px; color: #666;">BP Total:</span> <strong>' . number_format($disp_bp_combined, 2) . '</strong>
                </td>
                <td colspan="8"></td>
            </tr>
        </tfoot>
    </table>
    ';
}

$html .= '</body></html>';

$options = new Options();
$options->set('isRemoteEnabled', false);      // no remote assets are used -> faster, safer
$options->set('isHtml5ParserEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
unset($html);
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();

$filename = 'Recon_Report_' . date('Ymd_His') . '.pdf';
$dompdf->stream($filename, ['Attachment' => false]);
exit;
