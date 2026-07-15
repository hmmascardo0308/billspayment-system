<?php
include '../../config/config.php';

session_start();
header('Content-Type: application/json');

// include permission helper so non-admin users with the sentinel (-1)
// or explicit permission can also perform updates
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

function default_permission_catalog()
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
            'permission_catalog' => default_permission_catalog(),
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
            'permission_catalog' => default_permission_catalog(),
            'access_levels' => $levels,
            'needs_migration' => true
        ];
    }

    if (!is_array($decoded)) {
        return [
            'version' => 2,
            'permission_catalog' => default_permission_catalog(),
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
            : default_permission_catalog(),
        'access_levels' => $levels,
        'needs_migration' => false
    ];
}

function write_access_map_file($path, $data)
{
    $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        return false;
    }
    return @file_put_contents($path, $encoded, LOCK_EX) !== false;
}

function permissions_key($permissions)
{
    return json_encode(normalize_permissions($permissions));
}

function flatten_catalog_keys($nodes)
{
    $keys = [];
    if (!is_array($nodes)) {
        return $keys;
    }

    foreach ($nodes as $node) {
        if (!is_array($node)) {
            continue;
        }

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
        if (!is_array($node)) {
            continue;
        }

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
    $input = json_decode(file_get_contents('php://input'), true);

    if (!is_array($input)) {
        echo json_encode(['success' => false, 'message' => 'Invalid JSON payload']);
        exit;
    }

    $idNumber = isset($input['id_number']) ? trim((string)$input['id_number']) : '';
    $inputAccessLevel = isset($input['access_level']) ? (int)$input['access_level'] : 0;
    $inputPermissions = isset($input['permissions']) ? normalize_permissions($input['permissions']) : [];

    if ($idNumber === '') {
        echo json_encode(['success' => false, 'message' => 'id_number is required']);
        exit;
    }

    $mapPath = __DIR__ . '/../../assets/js/accesslevel-map.json';
    $accessMap = read_access_map_file($mapPath);

    if (!empty($accessMap['needs_migration'])) {
        $toWrite = $accessMap;
        unset($toWrite['needs_migration']);
        if (!write_access_map_file($mapPath, $toWrite)) {
            echo json_encode(['success' => false, 'message' => 'Failed to migrate access map format']);
            exit;
        }
    }

    $allowedPermissions = flatten_catalog_keys(isset($accessMap['permission_catalog']) ? $accessMap['permission_catalog'] : []);
    $inputPermissions = array_values(array_filter($inputPermissions, function ($permission) use ($allowedPermissions) {
        return in_array($permission, $allowedPermissions, true);
    }));

    // Keep admin sentinel as-is when explicitly provided.
    $resolvedAccessLevel = $inputAccessLevel;

    // Determine if all available leaf permissions are selected; this should map to sentinel -1.
    $allowedLeafPermissions = flatten_catalog_leaf_keys(isset($accessMap['permission_catalog']) ? $accessMap['permission_catalog'] : []);
    $allPermissionsSelected = !empty($allowedLeafPermissions)
        && count($inputPermissions) === count($allowedLeafPermissions)
        && count(array_diff($allowedLeafPermissions, $inputPermissions)) === 0;

    if ($inputAccessLevel === -1 || $allPermissionsSelected) {
        $resolvedAccessLevel = -1;
    } elseif (!empty($inputPermissions)) {
        // Resolve using explicit map combinations first (leaf-permissions based).
        $targetKey = permissions_key($inputPermissions);
        $existingLevel = 0;

        foreach ($accessMap['access_levels'] as $row) {
            if (permissions_key($row['permissions']) === $targetKey) {
                $existingLevel = (int)$row['access_level'];
                break;
            }
        }

        // If map has the combo, use it. Otherwise keep the computed value from UI.
        if ($existingLevel > 0) {
            $resolvedAccessLevel = $existingLevel;
        }
    }

    if ($resolvedAccessLevel === 0) {
        echo json_encode(['success' => false, 'message' => 'Unable to resolve access level from permissions']);
        exit;
    }

    $modifiedBy = 'System';
    if ($_SESSION['user_type'] === 'admin' && isset($_SESSION['admin_name'])) {
        $modifiedBy = $_SESSION['admin_name'];
    } elseif ($_SESSION['user_type'] === 'user' && isset($_SESSION['user_name'])) {
        $modifiedBy = $_SESSION['user_name'];
    }

    $modifiedDate = date('Y-m-d H:i:s');

    $updateQuery = "UPDATE mldb.user_form SET access_level = ?, modified_date = ?, modified_by = ? WHERE id_number = ?";
    $updateStmt = mysqli_prepare($conn, $updateQuery);

    if (!$updateStmt) {
        echo json_encode(['success' => false, 'message' => 'Unable to prepare update query']);
        exit;
    }

    mysqli_stmt_bind_param($updateStmt, 'isss', $resolvedAccessLevel, $modifiedDate, $modifiedBy, $idNumber);
    $updated = mysqli_stmt_execute($updateStmt);

    if (!$updated) {
        echo json_encode(['success' => false, 'message' => 'Database update failed: ' . mysqli_stmt_error($updateStmt)]);
        exit;
    }

    $fetchQuery = "SELECT id_number, email, access_level, modified_date, modified_by FROM mldb.user_form WHERE id_number = ? LIMIT 1";
    $fetchStmt = mysqli_prepare($conn, $fetchQuery);

    if (!$fetchStmt) {
        echo json_encode(['success' => false, 'message' => 'Unable to prepare fetch query']);
        exit;
    }

    mysqli_stmt_bind_param($fetchStmt, 's', $idNumber);
    mysqli_stmt_execute($fetchStmt);
    $result = mysqli_stmt_get_result($fetchStmt);
    $updatedRow = mysqli_fetch_assoc($result);

    if (!$updatedRow) {
        echo json_encode(['success' => false, 'message' => 'Updated user not found']);
        exit;
    }

    // Normalize session identities and updated row values for robust comparison
    $sessionEmails = [];
    if (!empty($_SESSION['admin_email'])) $sessionEmails[] = trim(strtolower((string)$_SESSION['admin_email']));
    if (!empty($_SESSION['user_email'])) $sessionEmails[] = trim(strtolower((string)$_SESSION['user_email']));
    $sessionIds = [];
    if (!empty($_SESSION['id_number'])) $sessionIds[] = trim((string)$_SESSION['id_number']);

    $updatedEmail = isset($updatedRow['email']) ? trim(strtolower((string)$updatedRow['email'])) : '';
    $updatedId = isset($updatedRow['id_number']) ? trim((string)$updatedRow['id_number']) : '';

    $shouldUpdateSession = false;
    if ($updatedEmail !== '' && in_array($updatedEmail, $sessionEmails, true)) {
        $shouldUpdateSession = true;
    }
    if ($updatedId !== '' && in_array($updatedId, $sessionIds, true)) {
        $shouldUpdateSession = true;
    }

    if ($shouldUpdateSession) {
        $_SESSION['user_access_level'] = (int)$updatedRow['access_level'];
        $_SESSION['access_level'] = (int)$updatedRow['access_level'];

        // Normalize and expand permissions for server-side checks so that
        // ancestor/catalog keys (e.g., 'Bills Payment') are present when a
        // leaf permission (e.g., 'BP Import Transaction') is granted.
        $normalized = normalize_permissions($inputPermissions);

        // build ancestor map from permission_catalog
        $ancestorMap = [];
        $catalog = isset($accessMap['permission_catalog']) && is_array($accessMap['permission_catalog']) ? $accessMap['permission_catalog'] : default_permission_catalog();
        $stack = [];
        $walk = function($nodes, $parents = []) use (&$walk, &$ancestorMap) {
            foreach ($nodes as $n) {
                if (!is_array($n) || empty($n['key'])) continue;
                $key = trim($n['key']);
                foreach ($parents as $p) {
                    $ancestorMap[$key][] = $p;
                }
                $nextParents = $parents;
                $nextParents[] = $key;
                if (isset($n['children']) && is_array($n['children'])) {
                    $walk($n['children'], $nextParents);
                }
            }
        };
        $walk($catalog, []);

        $expanded = [];
        foreach ($normalized as $p) {
            $expanded[$p] = true;
            if (isset($ancestorMap[$p]) && is_array($ancestorMap[$p])) {
                foreach ($ancestorMap[$p] as $anc) $expanded[$anc] = true;
            }
        }

        // store sorted normalized expanded permissions
        $sessionPerms = array_values(array_unique(array_map('strval', array_keys($expanded))));
        sort($sessionPerms, SORT_STRING);
        $_SESSION['user_permissions'] = $sessionPerms;

        // Keep session in sync with current access map file mtime so middleware knows it's fresh
        $mapMtime = 0;
        if (file_exists($mapPath)) $mapMtime = @filemtime($mapPath);
        $_SESSION['access_map_mtime'] = $mapMtime;
    }
    // Persist explicit per-user permissions into DB column `permissions` (JSON),
    // but store an expanded set that includes ancestor/catalog keys so
    // server-side middleware and templates see the same effective permissions
    // as the client UI.
    try {
        // Normalize input perms
        $normalized_input_perms = normalize_permissions($inputPermissions);

        // Build ancestor map from permission catalog so we can expand leaves
        $ancestorMap = [];
        $catalog = isset($accessMap['permission_catalog']) && is_array($accessMap['permission_catalog']) ? $accessMap['permission_catalog'] : default_permission_catalog();
        $walk = function($nodes, $parents = []) use (&$walk, &$ancestorMap) {
            foreach ($nodes as $n) {
                if (!is_array($n) || empty($n['key'])) continue;
                $key = trim($n['key']);
                foreach ($parents as $p) {
                    $ancestorMap[$key][] = $p;
                }
                $nextParents = $parents;
                $nextParents[] = $key;
                if (isset($n['children']) && is_array($n['children'])) {
                    $walk($n['children'], $nextParents);
                }
            }
        };
        $walk($catalog, []);

        $expanded_for_persist = [];
        foreach ($normalized_input_perms as $p) {
            $expanded_for_persist[$p] = true;
            if (isset($ancestorMap[$p]) && is_array($ancestorMap[$p])) {
                foreach ($ancestorMap[$p] as $anc) $expanded_for_persist[$anc] = true;
            }
        }

        $persistPerms = array_values(array_unique(array_map('strval', array_keys($expanded_for_persist))));
        sort($persistPerms, SORT_STRING);

        $jsonPerms = json_encode($persistPerms, JSON_UNESCAPED_SLASHES);
        if ($jsonPerms === false) $jsonPerms = json_encode([]);

        // update DB permissions column if present
        $updPermQuery = "UPDATE mldb.user_form SET permissions = ? WHERE id_number = ?";
        $updPermStmt = mysqli_prepare($conn, $updPermQuery);
        if ($updPermStmt) {
            mysqli_stmt_bind_param($updPermStmt, 'ss', $jsonPerms, $idNumber);
            @mysqli_stmt_execute($updPermStmt);
            @mysqli_stmt_close($updPermStmt);
        }

        // legacy file-backed store (non-fatal)
        $userPermPath = __DIR__ . '/../../assets/js/user-permissions.json';
        $userPermData = [];
        if (file_exists($userPermPath)) {
            $rawUp = @file_get_contents($userPermPath);
            $decUp = json_decode($rawUp, true);
            if (is_array($decUp)) $userPermData = $decUp;
        }
        $userPermData[$idNumber] = $inputPermissions;
        @file_put_contents($userPermPath, json_encode($userPermData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    } catch (Exception $e) {
        // non-fatal, continue
    }

    echo json_encode([
        'success' => true,
        'message' => 'Access level updated successfully',
        'updated' => [
            'id_number' => $updatedRow['id_number'],
            'email' => $updatedRow['email'],
            'access_level' => (int)$updatedRow['access_level'],
            'modified_date' => $updatedRow['modified_date'],
            'modified_by' => $updatedRow['modified_by']
        ]
    ]);
} catch (Exception $exception) {
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $exception->getMessage()]);
}

mysqli_close($conn);
