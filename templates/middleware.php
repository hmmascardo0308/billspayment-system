<?php
// Middleware helpers for access-level -> permissions checks
if (defined('__BP_MIDDLEWARE_LOADED__')) return;
define('__BP_MIDDLEWARE_LOADED__', true);
if (session_status() === PHP_SESSION_NONE) @session_start();

// Ensure DB connection is available
if (!isset($conn)) {
    $possible = __DIR__ . '/../config/config.php';
    if (file_exists($possible)) include_once $possible;
}

if (!function_exists('load_access_map')) {
function load_access_map()
{
    static $map = null;
    if ($map !== null) return $map;

    $path = __DIR__ . '/../assets/js/accesslevel-map.json';
    if (!file_exists($path)) { $map = []; return $map; }

    $raw = @file_get_contents($path);
    $dec = @json_decode($raw, true);
    if (!is_array($dec)) {
        // attempt simple cleanup then re-decode
        $clean = preg_replace('!/\*.*?\*/!s', '', $raw);
        $clean = preg_replace('/\/\/.*(?=[\r\n])/', '', $clean);
        $clean = preg_replace('/,\s*([\]}])/', '$1', $clean);
        $dec = @json_decode($clean, true);
    }

    $out = [];
    if (is_array($dec)) {
        $levels = [];
        if (isset($dec['access_levels']) && is_array($dec['access_levels'])) $levels = $dec['access_levels'];
        elseif (array_keys($dec) === range(0, count($dec) - 1)) $levels = $dec;
        foreach ($levels as $it) {
            $lvl = isset($it['access_level']) ? intval($it['access_level']) : 0;
            if ($lvl) $out[$lvl] = isset($it['permissions']) && is_array($it['permissions']) ? $it['permissions'] : [];
        }
    }

    // fallback regex parse
    if (empty($out) && is_string($raw) && $raw !== '') {
        if (@preg_match_all('/"access_level"\s*:\s*(\d+)\s*,[\s\S]*?"permissions"\s*:\s*\[([^\]]*)\]/is', $raw, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $lvl = intval($row[1]);
                $permBlock = $row[2];
                $perms = [];
                if (@preg_match_all('/"([^\"]*)"/s', $permBlock, $pm)) {
                    foreach ($pm[1] as $p) $perms[] = stripcslashes($p);
                }
                if ($lvl) $out[$lvl] = $perms;
            }
        }
    }

    $map = $out;
    $GLOBALS['__access_map_last_debug'] = ['file_exists'=>file_exists($path),'raw_len'=>is_string($raw)?strlen($raw):0,'keys'=>array_values(array_keys($out))];
    $GLOBALS['__access_map_file_mtime'] = file_exists($path) ? @filemtime($path) : 0;
    return $map;
}
}

// Small compatibility helpers used by templates/menu.php debug block
if (!function_exists('get_user_access_level')) {
function get_user_access_level()
{
    if (isset($_SESSION['user_access_level'])) return $_SESSION['user_access_level'];
    if (isset($_SESSION['access_level'])) return $_SESSION['access_level'];
    $id = resolve_user_identifier();
    if (empty($id)) return null;
    $row = get_user_row($id);
    $access_col = resolve_access_level_column();
    if ($row && isset($row[$access_col])) return intval($row[$access_col]);
    return null;
}
}

if (!function_exists('access_map_debug')) {
function access_map_debug()
{
    $path = __DIR__ . '/../assets/js/accesslevel-map.json';
    $raw = @file_get_contents($path);
    $dec = @json_decode($raw, true);
    $json_err = json_last_error() === JSON_ERROR_NONE ? null : json_last_error_msg();
    $keys = array_keys(load_access_map());
    $file_mtime = isset($GLOBALS['__access_map_file_mtime']) ? $GLOBALS['__access_map_file_mtime'] : (file_exists($path) ? @filemtime($path) : 0);
    return [
        'file_exists' => file_exists($path),
        'raw_len' => is_string($raw) ? strlen($raw) : 0,
        'file_mtime' => $file_mtime,
        'json_err' => $json_err,
        'keys' => $keys,
    ];
}
}

if (!function_exists('resolve_user_identifier')) {
function resolve_user_identifier()
{
    if (!empty($_SESSION['id_number'])) return $_SESSION['id_number'];
    if (!empty($_SESSION['idnum'])) return $_SESSION['idnum'];
    if (!empty($_SESSION['user_id'])) return $_SESSION['user_id'];
    if (!empty($_SESSION['user'])) return $_SESSION['user'];
    return null;
}
}

if (!function_exists('get_user_row')) {
function get_user_row($id_number)
{
    global $conn;
    if (empty($id_number)) return null;
    $id = $conn->real_escape_string($id_number);
    $sql = "SELECT * FROM mldb.user_form WHERE id_number='".$id."' LIMIT 1";
    $res = $conn->query($sql);
    if ($res && $res->num_rows) return $res->fetch_assoc();
    return null;
}
}

if (!function_exists('resolve_permissions_column')) {
function resolve_permissions_column()
{
    global $conn;
    if (!isset($conn)) return 'permissions';
    $possible = ['permissions','permission_json','user_permissions'];
    $res = $conn->query("SHOW COLUMNS FROM mldb.user_form");
    if ($res) {
        $cols = [];
        while ($r = $res->fetch_assoc()) $cols[] = $r['Field'];
        foreach ($possible as $p) if (in_array($p, $cols)) return $p;
    }
    return 'permissions';
}
}

if (!function_exists('resolve_access_level_column')) {
function resolve_access_level_column()
{
    global $conn;
    if (!isset($conn)) return 'access_level';
    $possible = ['access_level','accesslevel','accessLevel'];
    $res = $conn->query("SHOW COLUMNS FROM mldb.user_form");
    if ($res) {
        $cols = [];
        while ($r = $res->fetch_assoc()) $cols[] = $r['Field'];
        foreach ($possible as $p) if (in_array($p, $cols)) return $p;
    }
    return 'access_level';
}
}

if (!function_exists('load_permissions_for_level')) {
function load_permissions_for_level($level)
{
    $map = load_access_map();
    if (isset($map[$level]) && is_array($map[$level])) return $map[$level];
    return [];
}
}

if (!function_exists('normalize_permission_list')) {
function normalize_permission_list($list)
{
    if (!is_array($list)) return [];
    $out = [];
    foreach ($list as $p) {
        if (!is_string($p)) continue;
        $t = trim($p);
        if ($t === '') continue;
        $out[$t] = true;
    }
    return array_keys($out);
}
}

if (!function_exists('permission_aliases')) {
function permission_aliases($perm)
{
    $aliases = [
        'Access Levels' => ['Maintenance Accounts Access Levels'],
        'Adjustment Entry Per Branch' => ['BP Settlement Adjustment Entry'],
        'Balance Sheet Report' => ['BP Report Balance Sheet'],
        'Billing Invoice Service Charge' => ['BI Create Automated'],
        'Billing Service Charge' => ['BI Create Manual'],
        'Cancellation Report' => ['BP Report Cancellation'],
        'Duplicate Transaction' => ['Maintenance Duplicate Transaction'],
        'EDI Report' => ['BP Report EDI'],
        'Import Cancellation' => ['BP Import Cancellation'],
        'Import Transaction' => ['BP Import Transaction'],
        'Masterfile Partner List' => ['Maintenance Masterfiles Partner List'],
        'Post Transaction' => ['BP Post Transaction'],
        'Settlement Per Bank' => ['BP Settlement Per Bank'],
        'SOA Report' => ['BI Report Billing Invoice'],
        'Transaction Report' => ['BP Report Transaction Details'],
        'Transaction Summary' => ['BP Report Transaction Summary'],
        'View Bank List' => ['Masterfiles View Bank List'],
        'View Partner List' => ['Masterfiles View Partner List'],
        'Volume Report' => ['BP Report Volume'],
    ];

    $keys = [$perm];
    if (isset($aliases[$perm])) {
        $keys = array_merge($keys, $aliases[$perm]);
    }

    return array_values(array_unique($keys));
}
}

if (!function_exists('get_current_user_permissions')) {
function get_current_user_permissions()
{
    global $conn;
    // Invalidate cached session permissions if the access map file changed
    $map = load_access_map();
    $map_mtime = isset($GLOBALS['__access_map_file_mtime']) ? $GLOBALS['__access_map_file_mtime'] : 0;
    if (!isset($_SESSION['access_map_mtime']) || $_SESSION['access_map_mtime'] !== $map_mtime) {
        unset($_SESSION['user_permissions']);
        unset($_SESSION['user_access_level']);
        unset($_SESSION['access_level']);
        unset($_SESSION['user_permissions_raw']);
    }

    // If we have cached permissions, perform a lightweight DB check to ensure they are still current.
    if (!empty($_SESSION['user_permissions']) && is_array($_SESSION['user_permissions'])) {
        $perm_col = resolve_permissions_column();
        $access_col = resolve_access_level_column();
        $id = resolve_user_identifier();
        if (!empty($id)) {
            $row = get_user_row($id);
            if ($row) {
                $db_level = isset($row[$access_col]) ? intval($row[$access_col]) : null;
                $db_raw_perms = isset($row[$perm_col]) ? $row[$perm_col] : null;
                $session_level = isset($_SESSION['user_access_level']) ? intval($_SESSION['user_access_level']) : null;
                // If access level changed, invalidate cache
                if ($db_level !== null && $session_level !== null && $db_level !== $session_level) {
                    unset($_SESSION['user_permissions']);
                    unset($_SESSION['user_access_level']);
                    unset($_SESSION['access_level']);
                    unset($_SESSION['user_permissions_raw']);
                } else {
                    // Compare normalized permissions if DB has explicit perms
                    if (!empty($db_raw_perms)) {
                        $dec = @json_decode($db_raw_perms, true);
                        $db_perms = is_array($dec) ? normalize_permission_list($dec) : [];
                        $sess_perms = is_array($_SESSION['user_permissions']) ? normalize_permission_list($_SESSION['user_permissions']) : [];
                        sort($db_perms, SORT_STRING);
                        sort($sess_perms, SORT_STRING);
                        if ($db_perms !== $sess_perms) {
                            unset($_SESSION['user_permissions']);
                            unset($_SESSION['user_access_level']);
                            unset($_SESSION['access_level']);
                            unset($_SESSION['user_permissions_raw']);
                        }
                    }
                }
            } else {
                // user row missing -> clear session permissions
                unset($_SESSION['user_permissions']);
                unset($_SESSION['user_access_level']);
                unset($_SESSION['access_level']);
                unset($_SESSION['user_permissions_raw']);
            }
        }
    }
    if (!empty($_SESSION['user_permissions']) && is_array($_SESSION['user_permissions'])) return $_SESSION['user_permissions'];

    $id = resolve_user_identifier();
    if (empty($id)) return [];

    $row = get_user_row($id);
    $perm_col = resolve_permissions_column();
    $access_col = resolve_access_level_column();

    if ($row) {
        $raw = isset($row[$perm_col]) ? $row[$perm_col] : null;
        $level = isset($row[$access_col]) ? intval($row[$access_col]) : null;

        if (!empty($raw)) {
            $dec = json_decode($raw, true);
            if (is_array($dec)) {
                $perms = normalize_permission_list($dec);
                $_SESSION['user_permissions'] = $perms;
                if (!empty($level)) {
                    $_SESSION['user_access_level'] = $level;
                    $_SESSION['access_level'] = $level;
                }
                $_SESSION['access_map_mtime'] = isset($GLOBALS['__access_map_file_mtime']) ? $GLOBALS['__access_map_file_mtime'] : 0;
                return $perms;
            }
        }

        // If permissions missing and user is admin (-1), auto-populate full map
        if (empty($raw) && intval($level) === -1) {
            $all = [];
            $map = load_access_map();
            foreach ($map as $lvlPerms) {
                foreach ($lvlPerms as $p) $all[$p] = true;
            }
            $perms = array_keys($all);
            // persist to DB
            if (isset($conn)) {
                $perm_col_sql = $conn->real_escape_string($perm_col);
                $encoded = $conn->real_escape_string(json_encode($perms));
                $id_sql = $conn->real_escape_string($id);
                $sql = "UPDATE mldb.user_form SET `".$perm_col_sql."`='".$encoded."' WHERE id_number='".$id_sql."' LIMIT 1";
                @$conn->query($sql);
            }
            $_SESSION['user_permissions'] = $perms;
            $_SESSION['user_access_level'] = intval($level);
            $_SESSION['access_level'] = intval($level);
            $_SESSION['access_map_mtime'] = isset($GLOBALS['__access_map_file_mtime']) ? $GLOBALS['__access_map_file_mtime'] : 0;
            return $perms;
        }

        // fallback to access level map
        if (!empty($level)) {
            $perms = load_permissions_for_level(intval($level));
            $_SESSION['user_permissions'] = $perms;
            $_SESSION['user_access_level'] = intval($level);
            $_SESSION['access_level'] = intval($level);
            $_SESSION['access_map_mtime'] = isset($GLOBALS['__access_map_file_mtime']) ? $GLOBALS['__access_map_file_mtime'] : 0;
            return $perms;
        }
    }

    return [];
}
}

if (!function_exists('has_permission')) {
function has_permission($perm)
{
    $level = get_user_access_level();
    if ($level !== null && intval($level) === -1) return true;

    $perms = get_current_user_permissions();
    foreach (permission_aliases($perm) as $key) {
        if (in_array($key, $perms, true)) return true;
    }
    return false;
}
}

if (!function_exists('has_any_permission')) {
function has_any_permission($needed)
{
    foreach ($needed as $n) if (has_permission($n)) return true;
    return false;
}
}

// Expose a small debug helper
if (!function_exists('middleware_debug_info')) {
function middleware_debug_info()
{
    return [
        'session_id' => session_id(),
        'session_user' => resolve_user_identifier(),
        'session_access_level' => isset($_SESSION['user_access_level']) ? $_SESSION['user_access_level'] : null,
        'session_permissions_count' => isset($_SESSION['user_permissions']) ? count($_SESSION['user_permissions']) : 0,
        'access_map_keys' => array_keys(load_access_map()),
        'last_map_debug' => isset($GLOBALS['__access_map_last_debug']) ? $GLOBALS['__access_map_last_debug'] : null
    ];
}
}
