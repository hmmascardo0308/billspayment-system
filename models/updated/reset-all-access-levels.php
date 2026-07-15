<?php
include '../../config/config.php';

session_start();
header('Content-Type: application/json');

// include permission helper
include_once __DIR__ . '/../../templates/middleware.php';

$allowed = false;
if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin') {
    $allowed = true;
}
if (isset($_SESSION['access_level']) && intval($_SESSION['access_level']) === -1) {
    $allowed = true;
}
if (function_exists('has_permission') && has_permission('Maintenance Accounts Access Levels')) {
    $allowed = true;
}

if (!$allowed) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

// Default permission catalog (fallback used when a map file is missing)
function default_permission_catalog()
{
    return _default_permission_catalog();
}

// Fallback: copy the same default catalog used in update-access-level.php
function _default_permission_catalog()
{
    return [
        [
            'key' => 'Bills Payment',
            'label' => 'Bills Payment',
            'icon' => 'payments',
            'children' => [
                ['key' => 'BP Import Transaction', 'label' => 'Import > Transaction', 'icon' => 'receipt'],
                ['key' => 'BP Import Cancellation', 'label' => 'Import > Cancellation', 'icon' => 'block'],
                ['key' => 'BP Import Partner Data', 'label' => 'Import > Partner Data', 'icon' => 'groups'],
                ['key' => 'BP Post Transaction', 'label' => 'Post > Transaction', 'icon' => 'send'],
                ['key' => 'BP Settlement Adjustment Entry', 'label' => 'Settlement > Adjustment Entry', 'icon' => 'account_tree'],
                ['key' => 'BP Settlement Per Bank', 'label' => 'Settlement > Per Bank', 'icon' => 'account_balance'],
                ['key' => 'BP Report Volume', 'label' => 'Report > Volume Report', 'icon' => 'bar_chart'],
                ['key' => 'BP Report EDI', 'label' => 'Report > EDI Report', 'icon' => 'description'],
                ['key' => 'BP Report Transaction Details', 'label' => 'Report > Transaction Details', 'icon' => 'list_alt'],
                ['key' => 'BP Report Transaction Summary', 'label' => 'Report > Transaction Summary', 'icon' => 'table_chart'],
                ['key' => 'BP Report Cancellation', 'label' => 'Report > Cancellation Report', 'icon' => 'cancel'],
                ['key' => 'BP Report Balance Sheet', 'label' => 'Report > Balance Sheet', 'icon' => 'analytics']
            ]
        ],
        [
            'key' => 'Billing Invoice',
            'label' => 'Billing Invoice',
            'icon' => 'receipt_long',
            'children' => [
                ['key' => 'BI Create Manual', 'label' => 'Create > Service Charge (MANUAL)', 'icon' => 'edit_note'],
                ['key' => 'BI Create Automated', 'label' => 'Create > Service Charge (AUTOMATED)', 'icon' => 'auto_mode'],
                ['key' => 'Invoice Review', 'label' => 'Review > For Checking / Review', 'icon' => 'rate_review'],
                ['key' => 'Invoice Approval', 'label' => 'Approval > Billing Invoice Approval', 'icon' => 'fact_check'],
                ['key' => 'BI Report Billing Invoice', 'label' => 'Report > Billing Invoice Report', 'icon' => 'summarize']
            ]
        ],
        [
            'key' => 'Masterfiles',
            'label' => 'Masterfiles',
            'icon' => 'folder',
            'children' => [
                ['key' => 'Masterfiles View Bank List', 'label' => 'View > Bank List', 'icon' => 'account_balance_wallet']
            ]
        ],
        [
            'key' => 'Maintenance',
            'label' => 'Maintenance',
            'icon' => 'build',
            'children' => [
                [
                    'key' => 'Accounts',
                    'label' => 'Accounts',
                    'icon' => 'manage_accounts',
                    'children' => [
                        ['key' => 'Maintenance Accounts User Management', 'label' => 'Accounts > User Management', 'icon' => 'group'],
                        ['key' => 'Maintenance Accounts Access Levels', 'label' => 'Accounts > Access Levels', 'icon' => 'vpn_key']
                    ]
                ],
                ['key' => 'Maintenance Duplicate Transaction', 'label' => 'Duplicate > Transaction', 'icon' => 'content_copy'],
                ['key' => 'Maintenance Masterfiles Partner List', 'label' => 'Masterfiles > Partner List', 'icon' => 'groups'],
                ['key' => 'Maintenance Masterfiles Bank List', 'label' => 'Masterfiles > Bank List', 'icon' => 'savings']
            ]
        ],
        [
            'key' => 'Tools',
            'label' => 'Tools',
            'icon' => 'handyman',
            'children' => [
                ['key' => 'Tools KPX Generator', 'label' => 'KPX/KP7 Generator', 'icon' => 'memory'],
                ['key' => 'Tools Branch Maker', 'label' => 'Branch Maker', 'icon' => 'alt_route'],
                ['key' => 'Tools File Fetch', 'label' => 'File Fetch', 'icon' => 'cloud_download']
            ]
        ]
    ];
}

function normalize_permissions($permissions)
{
    if (!is_array($permissions)) return [];
    $set = [];
    foreach ($permissions as $permission) {
        if (!is_string($permission)) continue;
        $trimmed = trim($permission);
        if ($trimmed === '') continue;
        $set[$trimmed] = true;
    }
    $keys = array_keys($set);
    sort($keys, SORT_STRING);
    return $keys;
}

function read_access_map_file($path)
{
    if (!file_exists($path)) {
        return [
            'version' => 2,
            'permission_catalog' => _default_permission_catalog(),
            'access_levels' => [],
            'needs_migration' => false
        ];
    }

    $raw = @file_get_contents($path);
    $decoded = json_decode($raw, true);

    if (is_array($decoded) && array_keys($decoded) === range(0, count($decoded) - 1)) {
        $levels = [];
        foreach ($decoded as $item) {
            $level = isset($item['access_level']) ? (int)$item['access_level'] : 0;
            if ($level === 0) continue;
            $levels[] = [
                'access_level' => $level,
                'permissions' => normalize_permissions(isset($item['permissions']) ? $item['permissions'] : [])
            ];
        }

        usort($levels, function ($a, $b) {
            return $a['access_level'] <=> $b['access_level'];
        });

        return [
            'version' => 2,
            'permission_catalog' => _default_permission_catalog(),
            'access_levels' => $levels,
            'needs_migration' => true
        ];
    }

    if (!is_array($decoded)) {
        return [
            'version' => 2,
            'permission_catalog' => _default_permission_catalog(),
            'access_levels' => [],
            'needs_migration' => false
        ];
    }

    $levels = [];
    if (isset($decoded['access_levels']) && is_array($decoded['access_levels'])) {
        foreach ($decoded['access_levels'] as $item) {
            $level = isset($item['access_level']) ? (int)$item['access_level'] : 0;
            if ($level === 0) continue;
            $levels[] = [
                'access_level' => $level,
                'permissions' => normalize_permissions(isset($item['permissions']) ? $item['permissions'] : [])
            ];
        }
    }

    usort($levels, function ($a, $b) {
        return $a['access_level'] <=> $b['access_level'];
    });

    return [
        'version' => 2,
        'permission_catalog' => (isset($decoded['permission_catalog']) && is_array($decoded['permission_catalog']))
            ? $decoded['permission_catalog']
            : _default_permission_catalog(),
        'access_levels' => $levels,
        'needs_migration' => false
    ];
}

function flatten_catalog_keys($nodes)
{
    $keys = [];
    if (!is_array($nodes)) {
        return $keys;
    }

    foreach ($nodes as $node) {
        if (!is_array($node)) continue;
        if (isset($node['key']) && is_string($node['key']) && trim($node['key']) !== '') {
            $keys[] = trim($node['key']);
        }
        if (isset($node['children']) && is_array($node['children'])) {
            $keys = array_merge($keys, flatten_catalog_keys($node['children']));
        }
    }

    $keys = array_values(array_unique($keys));
    sort($keys, SORT_STRING);
    return $keys;
}

function flatten_catalog_leaf_keys($nodes)
{
    $keys = [];
    if (!is_array($nodes)) {
        return $keys;
    }

    foreach ($nodes as $node) {
        if (!is_array($node)) continue;
        $children = isset($node['children']) && is_array($node['children']) ? $node['children'] : [];
        if (empty($children)) {
            if (isset($node['key']) && is_string($node['key']) && trim($node['key']) !== '') {
                $keys[] = trim($node['key']);
            }
            continue;
        }
        $keys = array_merge($keys, flatten_catalog_leaf_keys($children));
    }

    $keys = array_values(array_unique($keys));
    sort($keys, SORT_STRING);
    return $keys;
}

try {
    $mapPath = __DIR__ . '/../../assets/js/accesslevel-map.json';
    $accessMap = read_access_map_file($mapPath);

    if (!empty($accessMap['needs_migration'])) {
        $toWrite = $accessMap;
        unset($toWrite['needs_migration']);
        @file_put_contents($mapPath, json_encode($toWrite, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    $allowedPermissions = flatten_catalog_keys(isset($accessMap['permission_catalog']) ? $accessMap['permission_catalog'] : []);
    $allowedLeafPermissions = flatten_catalog_leaf_keys(isset($accessMap['permission_catalog']) ? $accessMap['permission_catalog'] : []);

    // Fetch all users
    $users = [];
    $res = mysqli_query($conn, "SELECT id_number, email, access_level FROM mldb.user_form");
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            $users[] = $r;
        }
        mysqli_free_result($res);
    }

    if (empty($users)) {
        echo json_encode(['success' => true, 'message' => 'No users to update', 'updated' => []]);
        exit;
    }

    $modifiedBy = 'System';
    if ($_SESSION['user_type'] === 'admin' && isset($_SESSION['admin_name'])) {
        $modifiedBy = $_SESSION['admin_name'];
    } elseif ($_SESSION['user_type'] === 'user' && isset($_SESSION['user_name'])) {
        $modifiedBy = $_SESSION['user_name'];
    }

    $modifiedDate = date('Y-m-d H:i:s');

    $updateQuery = "UPDATE mldb.user_form SET access_level = ?, permissions = ?, modified_date = ?, modified_by = ? WHERE id_number = ?";
    $updateStmt = mysqli_prepare($conn, $updateQuery);
    if (!$updateStmt) {
        echo json_encode(['success' => false, 'message' => 'Unable to prepare update query']);
        exit;
    }

    $updatedRows = [];
    $currentSessionEmails = [];
    if (!empty($_SESSION['admin_email'])) $currentSessionEmails[] = trim(strtolower((string)$_SESSION['admin_email']));
    if (!empty($_SESSION['user_email'])) $currentSessionEmails[] = trim(strtolower((string)$_SESSION['user_email']));
    $currentSessionIds = [];
    if (!empty($_SESSION['id_number'])) $currentSessionIds[] = trim((string)$_SESSION['id_number']);

    $currentUserChanged = false;

    foreach ($users as $u) {
        $idn = isset($u['id_number']) ? trim((string)$u['id_number']) : '';
        $email = isset($u['email']) ? trim((string)$u['email']) : '';
        $curLevel = isset($u['access_level']) ? (int)$u['access_level'] : 0;

        if ($curLevel === -1) {
            $resolved = -1;
            $perms = $allowedLeafPermissions;
        } else {
            // set to level 1 as requested
            $resolved = 1;
            // find mapping for level 1
            $perms = [];
            foreach ($accessMap['access_levels'] as $row) {
                if (isset($row['access_level']) && (int)$row['access_level'] === 1) {
                    $perms = normalize_permissions(isset($row['permissions']) ? $row['permissions'] : []);
                    break;
                }
            }

            if (empty($perms)) {
                // fallback: treat 1 as bitmask selecting root index 0
                $roots = isset($accessMap['permission_catalog']) && is_array($accessMap['permission_catalog']) ? $accessMap['permission_catalog'] : _default_permission_catalog();
                if (!empty($roots) && isset($roots[0]) && is_array($roots[0])) {
                    $perms = flatten_catalog_leaf_keys(isset($roots[0]['children']) ? $roots[0]['children'] : []);
                }
            }
        }

        // filter perms to allowed permissions
        $perms = array_values(array_filter($perms, function ($p) use ($allowedPermissions) {
            return in_array($p, $allowedPermissions, true);
        }));

        $jsonPerms = json_encode($perms, JSON_UNESCAPED_SLASHES);
        if ($jsonPerms === false) $jsonPerms = json_encode([]);

        mysqli_stmt_bind_param($updateStmt, 'issss', $resolved, $jsonPerms, $modifiedDate, $modifiedBy, $idn);
        @mysqli_stmt_execute($updateStmt);

        $updatedRows[] = [
            'id_number' => $idn,
            'email' => $email,
            'access_level' => $resolved
        ];

        // If this is the current session user, mark changed and sync session
        $cmpEmail = trim(strtolower((string)$email));
        if ($cmpEmail !== '' && in_array($cmpEmail, $currentSessionEmails, true)) {
            $currentUserChanged = true;
            $_SESSION['user_access_level'] = (int)$resolved;
            $_SESSION['user_permissions'] = $perms;
        }
        if ($idn !== '' && in_array($idn, $currentSessionIds, true)) {
            $currentUserChanged = true;
            $_SESSION['user_access_level'] = (int)$resolved;
            $_SESSION['user_permissions'] = $perms;
        }
    }

    @mysqli_stmt_close($updateStmt);

    // keep access map mtime in session for middleware freshness
    $mapMtime = 0;
    if (file_exists($mapPath)) $mapMtime = @filemtime($mapPath);
    $_SESSION['access_map_mtime'] = $mapMtime;

    echo json_encode([
        'success' => true,
        'message' => 'Users reset successfully',
        'updated' => $updatedRows,
        'current_user_changed' => $currentUserChanged
    ]);
} catch (Exception $ex) {
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $ex->getMessage()]);
}

mysqli_close($conn);
