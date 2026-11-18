<?php
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
