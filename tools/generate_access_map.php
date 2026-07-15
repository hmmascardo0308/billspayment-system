<?php
// generate_access_map.php
// Usage: php tools/generate_access_map.php
// Regenerates `accesslevel-map.json` using a menu-based access-level scheme:
// - one access level per root menu (levels start at 1)
// - admin access level -1 contains all permissions (sentinel)

$mapPath = __DIR__ . '/../assets/js/accesslevel-map.json';
$backupPath = $mapPath . '.bak.' . date('Ymd_His');

// If the map file doesn't exist, create a minimal base map so the
// generator can run and auto-discover permission keys from source files.
if (!file_exists($mapPath)) {
    $base = [
        'version' => 2,
        'permission_catalog' => [],
        'access_levels' => [],
        'needs_migration' => false
    ];
    $w = @file_put_contents($mapPath, json_encode($base, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    if ($w === false) {
        echo "Failed to create base accesslevel-map.json at: $mapPath\n";
        exit(1);
    }
    echo "Created base access map at: $mapPath\n";
}

$raw = @file_get_contents($mapPath);
$decoded = json_decode($raw, true);
if (!is_array($decoded)) {
    // Diagnostic info to help when the map file is present but empty or unwritable
    $exists = file_exists($mapPath) ? 'yes' : 'no';
    $filesize = @filesize($mapPath);
    $writable = is_writable($mapPath) ? 'yes' : 'no';
    $dirWritable = is_writable(dirname($mapPath)) ? 'yes' : 'no';
    echo "Detected invalid or empty JSON at $mapPath\n";
    echo "File exists: $exists, filesize: " . ($filesize === false ? 'unknown' : $filesize) . ", file writable: $writable, dir writable: $dirWritable\n";
    // Backup the invalid file before overwriting
    $corruptBackup = $mapPath . '.corrupt.bak.' . date('Ymd_His');
    if (@copy($mapPath, $corruptBackup)) {
        echo "Backed up invalid access map to: $corruptBackup\n";
    }
    echo "Invalid or empty JSON found at $mapPath — replacing with minimal base map.\n";
    // attempt to ensure path is writable; try a permissive chmod where possible
    if (file_exists($mapPath) && !is_writable($mapPath)) {
        @chmod($mapPath, 0666);
        clearstatcache(true, $mapPath);
        $writable = is_writable($mapPath) ? 'yes' : 'no';
        echo "After chmod attempt, file writable: $writable\n";
    }

    $base = [
        'version' => 2,
        'permission_catalog' => [],
        'access_levels' => [],
        'needs_migration' => false
    ];
    $written = @file_put_contents($mapPath, json_encode($base, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    if ($written === false) {
        $err = error_get_last();
        echo "Failed to write base access map to: $mapPath\n";
        if ($err) echo "Error: " . ($err['message'] ?? json_encode($err)) . "\n";
        echo "Try running PHP with sufficient permissions or remove the empty file and re-run.\n";
        exit(1);
    }
    $decoded = $base;
}

$permissionCatalog = [];
if (isset($decoded['permission_catalog']) && is_array($decoded['permission_catalog'])) {
    $permissionCatalog = $decoded['permission_catalog'];
} else {
    echo "permission_catalog missing or invalid in map file\n";
    exit(1);
}

// Exempt common/default menu items that should not require permissions
function is_exempt_permission($key) {
    if (!is_string($key) || trim($key) === '') return false;
    $k = strtolower($key);
    $exemptWords = ['home', 'logout'];
    foreach ($exemptWords as $w) {
        if (strpos($k, $w) !== false) return true;
    }
    return false;
}

// Helper: scan project PHP files for has_permission / has_any_permission usages
function scan_permission_keys_from_sources($paths) {
    $found = [];
    $iterFiles = [];
    foreach ($paths as $p) {
        if (is_dir($p)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($p));
            foreach ($it as $f) {
                if ($f->isFile() && preg_match('/\.php$/i', $f->getFilename())) {
                    $iterFiles[] = $f->getPathname();
                }
            }
        } elseif (is_file($p)) {
            $iterFiles[] = $p;
        }
    }

    foreach ($iterFiles as $file) {
        $content = @file_get_contents($file);
        if ($content === false) continue;

        // has_permission('KEY') and has_permission("KEY")
        if (preg_match_all('/has_permission\s*\(\s*["\']([^"\']+)["\']\s*\)/i', $content, $m)) {
            foreach ($m[1] as $k) $found[] = trim($k);
        }

        // has_any_permission(['A','B']) - extract inner quoted strings
        if (preg_match_all('/has_any_permission\s*\(\s*\[([^\]]+)\]/i', $content, $m2)) {
            foreach ($m2[1] as $inner) {
                if (preg_match_all('/["\']([^"\']+)["\']/', $inner, $m3)) {
                    foreach ($m3[1] as $k) $found[] = trim($k);
                }
            }
        }
    }

    $found = array_values(array_unique(array_filter($found, 'is_string')));
    sort($found, SORT_STRING);
    return $found;
}

// Scan templates/menu.php and all PHP files under templates and dashboard for permission keys
$projectPaths = [__DIR__ . '/../templates/menu.php', __DIR__ . '/../templates', __DIR__ . '/../dashboard'];
$detectedKeys = scan_permission_keys_from_sources($projectPaths);

// Normalize known legacy permission labels to canonical keys used by menu/middleware.
// This prevents accidental creation of fake root menus like "Access" or "User"
// when older pages still reference shortened labels.
function normalize_detected_permission_key($key) {
    if (!is_string($key)) return '';
    $trimmed = trim($key);
    if ($trimmed === '') return '';

    $legacy = [
        'Access Levels' => 'Maintenance Accounts Access Levels',
        'User Management' => 'Maintenance Accounts User Management',
        'Adjustment Entry' => 'BP Settlement Adjustment Entry',
        'Adjustment Entry Per Branch' => 'BP Settlement Adjustment Entry',
        'Settlement Per Bank' => 'BP Settlement Per Bank',
        'Import Transaction' => 'BP Import Transaction',
        'Import Cancellation' => 'BP Import Cancellation',
        'Post Transaction' => 'BP Post Transaction',
        'Volume Report' => 'BP Report Volume',
        'EDI Report' => 'BP Report EDI',
        'Transaction Report' => 'BP Report Transaction Details',
        'Transaction Summary' => 'BP Report Transaction Summary',
        'Cancellation Report' => 'BP Report Cancellation',
        'Balance Sheet Report' => 'BP Report Balance Sheet',
        'Billing Service Charge' => 'BI Create Manual',
        'Billing Invoice Service Charge' => 'BI Create Automated',
        'For Checking Review' => 'Invoice Review',
        'SOA Report' => 'BI Report Billing Invoice',
        'Duplicate Transaction' => 'Maintenance Duplicate Transaction',
        'Masterfile Partner List' => 'Masterfiles View Partner List',
        'View Partner List' => 'Masterfiles View Partner List',
        'View Bank List' => 'Masterfiles View Bank List',
    ];

    foreach ($legacy as $old => $canonical) {
        if (strcasecmp($trimmed, $old) === 0) {
            return $canonical;
        }
    }

    return $trimmed;
}

$detectedKeys = array_values(array_filter(array_map('normalize_detected_permission_key', $detectedKeys), function($v){ return is_string($v) && trim($v) !== ''; }));

// Remove exempt/default keys discovered from sources (e.g., Home, Logout)
$detectedKeys = array_values(array_filter($detectedKeys, function($k){ return !is_exempt_permission($k); }));

// Parse root menu labels and dynamic grouping hints from menu.php.
// This remains automatic for newly added menus/submenus.
function parse_menu_structure_with_hints($menuPath)
{
    $result = ['catalog' => [], 'hints' => []];
    if (!is_file($menuPath)) return $result;
    $raw = @file_get_contents($menuPath);
    if ($raw === false || trim($raw) === '') return $result;

    // strip PHP blocks for stable HTML label parsing
    $clean = preg_replace('/<\?(?:php)?[\s\S]*?\?>/i', '', $raw);

    $prefixToIndex = [];

    // Root menus: .onetab with id=*-btn and an <h6> label
    if (preg_match_all('/<div[^>]*class=["\']onetab["\'][^>]*id=["\']([^"\']+-btn)["\'][^>]*>[\s\S]*?<h6[^>]*>([\s\S]*?)<\/h6>/i', $clean, $m, PREG_SET_ORDER)) {
        foreach ($m as $row) {
            $id = trim((string)($row[1] ?? ''));
            $label = trim(preg_replace('/\s+/', ' ', strip_tags((string)($row[2] ?? ''))));
            if ($id === '' || $label === '' || is_exempt_permission($label)) continue;

            $prefix = strtolower((string)preg_replace('/-btn$/i', '', $id));
            if ($prefix === '') continue;
            if (isset($prefixToIndex[$prefix])) continue;

            $result['catalog'][] = [
                'key' => $label,
                'label' => $label,
                'icon' => 'check_circle',
                'children' => []
            ];
            $idx = count($result['catalog']) - 1;
            $prefixToIndex[$prefix] = $idx;
            $result['hints'][$idx] = [$label];
        }
    }

    // Subgroup labels: .tabcat id=prefix-...-btn with h6 label; use as grouping hints.
    if (preg_match_all('/<div[^>]*class=["\']tabcat["\'][^>]*id=["\']([^"\']+-btn)["\'][^>]*>[\s\S]*?<h6[^>]*>([\s\S]*?)<\/h6>/i', $clean, $tm, PREG_SET_ORDER)) {
        foreach ($tm as $row) {
            $id = trim((string)($row[1] ?? ''));
            $label = trim(preg_replace('/\s+/', ' ', strip_tags((string)($row[2] ?? ''))));
            if ($id === '' || $label === '' || is_exempt_permission($label)) continue;

            $parts = explode('-', strtolower($id));
            $prefix = $parts[0] ?? '';
            if ($prefix === '' || !isset($prefixToIndex[$prefix])) continue;

            $idx = $prefixToIndex[$prefix];
            if (!isset($result['hints'][$idx])) $result['hints'][$idx] = [];
            $result['hints'][$idx][] = $label;
        }
    }

    // normalize hints
    foreach ($result['hints'] as $idx => $labels) {
        $uniq = array_values(array_unique(array_filter(array_map('trim', $labels), function($v){ return $v !== ''; })));
        $result['hints'][$idx] = $uniq;
    }

    return $result;
}

$rootHints = [];
$parsedMenu = parse_menu_structure_with_hints(__DIR__ . '/../templates/menu.php');
if (!empty($parsedMenu['catalog'])) {
    $permissionCatalog = $parsedMenu['catalog'];
    $rootHints = isset($parsedMenu['hints']) && is_array($parsedMenu['hints']) ? $parsedMenu['hints'] : [];
}

// Recursively remove exempt/default nodes (like Home, Logout) from the
// permission catalog so they do not appear as permissions or roots.
function filter_catalog_nodes($nodes) {
    $out = [];
    foreach ($nodes as $node) {
        if (!is_array($node)) continue;
        $key = isset($node['key']) ? $node['key'] : '';
        if (is_exempt_permission($key)) continue;

        $newNode = $node;
        if (isset($node['children']) && is_array($node['children'])) {
            $filteredChildren = filter_catalog_nodes($node['children']);
            if (!empty($filteredChildren)) {
                $newNode['children'] = $filteredChildren;
            } else {
                unset($newNode['children']);
            }
        }
        $out[] = $newNode;
    }
    return $out;
}

$permissionCatalog = filter_catalog_nodes($permissionCatalog);

function merge_root_aliases($catalog)
{
    $out = [];
    $idxByCanon = [];

    foreach ($catalog as $root) {
        if (!is_array($root)) continue;
        $rawKey = isset($root['key']) ? $root['key'] : '';
        $canonLower = strtolower(trim((string)$rawKey));
        if ($canonLower === '') continue;

        if (!isset($idxByCanon[$canonLower])) {
            $newRoot = $root;
            $newRoot['key'] = trim((string)$rawKey);
            if (!isset($newRoot['label']) || trim((string)$newRoot['label']) === '') {
                $newRoot['label'] = $newRoot['key'];
            }
            if (!isset($newRoot['children']) || !is_array($newRoot['children'])) {
                $newRoot['children'] = [];
            }
            $out[] = $newRoot;
            $idxByCanon[$canonLower] = count($out) - 1;
            continue;
        }

        $idx = $idxByCanon[$canonLower];
        if (!isset($out[$idx]['children']) || !is_array($out[$idx]['children'])) {
            $out[$idx]['children'] = [];
        }

        $existingChildKeys = [];
        foreach ($out[$idx]['children'] as $c) {
            $ck = isset($c['key']) ? strtolower((string)$c['key']) : '';
            if ($ck !== '') $existingChildKeys[$ck] = true;
        }

        $incomingChildren = isset($root['children']) && is_array($root['children']) ? $root['children'] : [];
        foreach ($incomingChildren as $child) {
            $ck = isset($child['key']) ? strtolower((string)$child['key']) : '';
            if ($ck === '' || isset($existingChildKeys[$ck])) continue;
            $out[$idx]['children'][] = $child;
            $existingChildKeys[$ck] = true;
        }
    }

    return $out;
}

$permissionCatalog = merge_root_aliases($permissionCatalog);

function remove_self_named_children($nodes)
{
    $out = [];
    foreach ($nodes as $node) {
        if (!is_array($node)) continue;
        $parentKey = isset($node['key']) ? strtolower(trim((string)$node['key'])) : '';
        if (isset($node['children']) && is_array($node['children'])) {
            $filtered = [];
            foreach ($node['children'] as $child) {
                $childKey = isset($child['key']) ? strtolower(trim((string)$child['key'])) : '';
                if ($childKey !== '' && $childKey === $parentKey) {
                    continue;
                }
                $filtered[] = $child;
            }
            $node['children'] = remove_self_named_children($filtered);
        }
        $out[] = $node;
    }
    return $out;
}

$permissionCatalog = remove_self_named_children($permissionCatalog);

// Merge any detected keys not already present in the permission catalog
$allLeafKeysFromCatalog = [];
foreach ($permissionCatalog as $node) {
    // collect leaf keys present in catalog
    $stack = [$node];
    while ($stack) {
        $cur = array_pop($stack);
        if (isset($cur['children']) && is_array($cur['children'])) {
            foreach ($cur['children'] as $c) $stack[] = $c;
        } else {
            if (isset($cur['key']) && is_string($cur['key'])) $allLeafKeysFromCatalog[] = $cur['key'];
        }
    }
}
$allLeafKeysFromCatalog = array_values(array_unique($allLeafKeysFromCatalog));

$missing = array_diff($detectedKeys, $allLeafKeysFromCatalog);
// remove exempt keys from missing list
$missing = array_values(array_filter($missing, function($k){ return !is_exempt_permission($k); }));

if (!empty($missing)) {
    // Try to attach each missing permission to the best-matching existing root
    // build acronym map from root labels + subgroup hints (dynamic, no hardcoded roots)
    $menuAcronyms = [];
    foreach ($permissionCatalog as $idx => $root) {
        $labels = [];
        $rlabel = isset($root['label']) ? $root['label'] : (isset($root['key']) ? $root['key'] : '');
        if (is_string($rlabel) && trim($rlabel) !== '') $labels[] = trim($rlabel);
        if (isset($rootHints[$idx]) && is_array($rootHints[$idx])) {
            foreach ($rootHints[$idx] as $h) {
                if (is_string($h) && trim($h) !== '') $labels[] = trim($h);
            }
        }

        foreach (array_values(array_unique($labels)) as $lbl) {
            $words = preg_split('/\s+/', trim($lbl));
            $words = array_values(array_filter($words, function($w){ return $w !== ''; }));
            if (empty($words)) continue;

            // Generate acronym candidates from first N words: B, BP, BPT...
            $acr = '';
            for ($n = 0; $n < count($words); $n++) {
                $acr .= strtoupper(substr($words[$n], 0, 1));
                if ($acr !== '') $menuAcronyms[strtolower($acr)] = $idx;
            }
        }
    }

    foreach ($missing as $perm) {
        if (is_exempt_permission($perm)) continue;

        // Skip permissions that are exactly a root key label.
        $permLower = strtolower(trim((string)$perm));
        $isRootKey = false;
        foreach ($permissionCatalog as $r) {
            $rk = isset($r['key']) ? strtolower(trim((string)$r['key'])) : '';
            if ($rk !== '' && $rk === $permLower) {
                $isRootKey = true;
                break;
            }
        }
        if ($isRootKey) continue;

        $permTokens = preg_split('/\s+/', strtolower($perm));
        $bestRootIdx = -1;
        $bestScore = 0;

        // Direct token-to-root match (stable and fully dynamic).
        $firstToken = isset($permTokens[0]) ? strtolower($permTokens[0]) : '';
        if ($firstToken !== '') {
            foreach ($permissionCatalog as $idx => $root) {
                $tokenSpace = strtolower(
                    (isset($root['key']) ? $root['key'] : '') . ' ' .
                    (isset($root['label']) ? $root['label'] : '') . ' ' .
                    ((isset($rootHints[$idx]) && is_array($rootHints[$idx])) ? implode(' ', $rootHints[$idx]) : '')
                );
                $rootTokens = preg_split('/\s+/', trim($tokenSpace));
                $rootTokens = array_values(array_unique(array_filter($rootTokens, function($v){ return $v !== ''; })));
                if (in_array($firstToken, $rootTokens, true)) {
                    $bestRootIdx = $idx;
                    $bestScore = 1000;
                    break;
                }
            }
        }

        // quick acronym match: if the first token matches a menu acronym, attach there
        if ($bestScore < 1000 && $firstToken !== '' && isset($menuAcronyms[$firstToken])) {
            $bestRootIdx = $menuAcronyms[$firstToken];
            $bestScore = 100; // high score to prefer acronym mapping
        }

        foreach ($permissionCatalog as $idx => $root) {
            $score = 0;
            $rootKey = isset($root['key']) ? strtolower($root['key']) : '';
            $rootLabel = isset($root['label']) ? strtolower($root['label']) : '';

            $hintText = '';
            if (isset($rootHints[$idx]) && is_array($rootHints[$idx])) {
                $hintText = strtolower(implode(' ', $rootHints[$idx]));
            }

            $tokenSpace = trim($rootKey . ' ' . $rootLabel . ' ' . $hintText);
            $rootTokens = preg_split('/\s+/', $tokenSpace);
            $rootTokens = array_values(array_unique(array_filter($rootTokens, function($v){ return $v !== ''; })));

            // Strong boost when permission prefix token directly matches root/hint tokens.
            if ($firstToken !== '' && in_array($firstToken, $rootTokens, true)) {
                $score += 10;
            }

            // token match against root key/label
            foreach ($permTokens as $t) {
                if ($t === '') continue;
                if (strpos($rootKey, $t) !== false) $score += 2;
                if (strpos($rootLabel, $t) !== false) $score += 2;
                if ($hintText !== '' && strpos($hintText, $t) !== false) $score += 3;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestRootIdx = $idx;
            }
        }

        if ($bestScore > 0 && $bestRootIdx >= 0) {
            // attach to existing root
            if (!isset($permissionCatalog[$bestRootIdx]['children']) || !is_array($permissionCatalog[$bestRootIdx]['children'])) {
                $permissionCatalog[$bestRootIdx]['children'] = [];
            }
            // avoid duplicate child keys
            $exists = false;
            foreach ($permissionCatalog[$bestRootIdx]['children'] as $rc) {
                if (isset($rc['key']) && $rc['key'] === $perm) { $exists = true; break; }
            }
            if (!$exists) {
                $permissionCatalog[$bestRootIdx]['children'][] = [ 'key' => $perm, 'label' => $perm, 'icon' => 'check_circle' ];
            }
        } else {
            // Final fallback: attach unmatched permissions to an existing root instead
            // of creating a new root menu. Access levels are based on menus only.
            $permLower = strtolower($perm);
            $fallbackRootCandidates = [];

            if (strpos($permLower, 'maintenance') !== false || strpos($permLower, 'access') !== false || strpos($permLower, 'user') !== false || strpos($permLower, 'duplicate') !== false) {
                $fallbackRootCandidates[] = 'maintenance';
            }
            if (strpos($permLower, 'trl') !== false) {
                $fallbackRootCandidates[] = 'billspayment - trl';
            }
            if (strpos($permLower, 'bp ') !== false || strpos($permLower, 'billspayment') !== false || strpos($permLower, 'settlement') !== false || strpos($permLower, 'adjustment') !== false || strpos($permLower, 'cancellation') !== false || strpos($permLower, 'edi') !== false || strpos($permLower, 'balance sheet') !== false || strpos($permLower, 'volume') !== false || strpos($permLower, 'transaction') !== false) {
                $fallbackRootCandidates[] = 'bills payment transaction';
            }
            if (strpos($permLower, 'invoice') !== false || strpos($permLower, 'bi ') !== false) {
                $fallbackRootCandidates[] = 'billing invoice';
            }
            if (strpos($permLower, 'support') !== false || strpos($permLower, 'ticket') !== false) {
                $fallbackRootCandidates[] = 'support ticket';
            }
            if (strpos($permLower, 'masterfiles') !== false || strpos($permLower, 'bank') !== false || strpos($permLower, 'partner') !== false) {
                $fallbackRootCandidates[] = 'masterfiles';
            }
            if (strpos($permLower, 'tools') !== false || strpos($permLower, 'kpx') !== false || strpos($permLower, 'branch maker') !== false || strpos($permLower, 'file fetch') !== false) {
                $fallbackRootCandidates[] = 'tools';
            }
            if (strpos($permLower, 'profile') !== false) {
                $fallbackRootCandidates[] = 'profile';
            }

            $attached = false;
            foreach ($fallbackRootCandidates as $candidateLower) {
                foreach ($permissionCatalog as $idx => $root) {
                    $rootKeyLower = isset($root['key']) ? strtolower(trim((string)$root['key'])) : '';
                    if ($rootKeyLower !== $candidateLower) continue;

                    if (!isset($permissionCatalog[$idx]['children']) || !is_array($permissionCatalog[$idx]['children'])) {
                        $permissionCatalog[$idx]['children'] = [];
                    }

                    $exists = false;
                    foreach ($permissionCatalog[$idx]['children'] as $rc) {
                        if (isset($rc['key']) && strcasecmp((string)$rc['key'], $perm) === 0) { $exists = true; break; }
                    }
                    if (!$exists) {
                        $permissionCatalog[$idx]['children'][] = [ 'key' => $perm, 'label' => $perm, 'icon' => 'check_circle' ];
                    }
                    $attached = true;
                    break 2;
                }
            }

            // Last resort: attach to first existing root to preserve menu-only access-level model.
            if (!$attached && !empty($permissionCatalog)) {
                if (!isset($permissionCatalog[0]['children']) || !is_array($permissionCatalog[0]['children'])) {
                    $permissionCatalog[0]['children'] = [];
                }
                $exists = false;
                foreach ($permissionCatalog[0]['children'] as $rc) {
                    if (isset($rc['key']) && strcasecmp((string)$rc['key'], $perm) === 0) { $exists = true; break; }
                }
                if (!$exists) {
                    $permissionCatalog[0]['children'][] = [ 'key' => $perm, 'label' => $perm, 'icon' => 'check_circle' ];
                }
            }
        }
    }
    echo "Detected and merged " . count($missing) . " permission keys from source files.\n";
}

function flatten_catalog_leaf_keys($nodes) {
    $keys = [];
    foreach ($nodes as $node) {
        if (!is_array($node)) continue;
        $hasChildren = isset($node['children']) && is_array($node['children']) && count($node['children']) > 0;
        if (!$hasChildren && isset($node['key']) && is_string($node['key']) && trim($node['key']) !== '') {
            $nk = trim($node['key']);
            if (!is_exempt_permission($nk)) $keys[] = $nk;
        }
        if ($hasChildren) {
            $keys = array_merge($keys, flatten_catalog_leaf_keys($node['children']));
        }
    }
    $keys = array_values(array_unique($keys));
    sort($keys, SORT_STRING);
    return $keys;
}

$keysPerRoot = [];
$allLeafKeys = [];
foreach ($permissionCatalog as $root) {
    $rootLeaves = flatten_catalog_leaf_keys([$root]);
    $keysPerRoot[] = $rootLeaves;
    $allLeafKeys = array_merge($allLeafKeys, $rootLeaves);
}
$allLeafKeys = array_values(array_unique($allLeafKeys));
sort($allLeafKeys, SORT_STRING);

// remove any exempt/default permissions from the final keys list
$allLeafKeys = array_values(array_filter($allLeafKeys, function($k){ return !is_exempt_permission($k); }));

if (count($allLeafKeys) === 0) {
    echo "No permission keys found to generate mapping.\n";
    exit(1);
}

// backup
if (!copy($mapPath, $backupPath)) {
    echo "Failed to create backup at $backupPath\n";
    exit(1);
}
echo "Backup created: $backupPath\n";

$accessLevels = [];

// Build bitmask-based combos for all non-empty subsets of root menus.
$numRoots = count($keysPerRoot);
if ($numRoots > 0) {
    $maxMask = (1 << $numRoots) - 1;
    for ($mask = 1; $mask <= $maxMask; $mask++) {
        $combo = [];
        for ($i = 0; $i < $numRoots; $i++) {
            if (($mask >> $i) & 1) {
                $combo = array_merge($combo, $keysPerRoot[$i]);
            }
        }
        $combo = array_values(array_unique(array_filter($combo, 'is_string')));
        sort($combo, SORT_STRING);
        $accessLevels[] = [ 'access_level' => $mask, 'permissions' => $combo ];
    }
}

// admin sentinel remains -1 (full unrestricted access)
$accessLevels[] = [ 'access_level' => -1, 'permissions' => $allLeafKeys ];

$newMap = [
    'version' => 2,
    'permission_catalog' => $permissionCatalog,
    'access_levels' => $accessLevels,
    'needs_migration' => false
];

$encoded = json_encode($newMap, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($encoded === false) {
    echo "Failed to encode new map JSON\n";
    exit(1);
}
// Write atomically: write to a temp file then rename into place. This
// avoids partial/truncated files if another process touches the file.
$tmpPath = $mapPath . '.tmp.' . uniqid();
$written = @file_put_contents($tmpPath, $encoded, LOCK_EX);
if ($written === false) {
    $err = error_get_last();
    echo "Failed to write temp access map to: $tmpPath\n";
    if ($err) echo "Error: " . ($err['message'] ?? json_encode($err)) . "\n";
    exit(1);
}

clearstatcache(true, $tmpPath);
$tmpSize = @filesize($tmpPath);
if ($tmpSize === false || $tmpSize === 0) {
    echo "Temp file written but size is zero or unreadable: $tmpPath\n";
    exit(1);
}

// Attempt atomic rename into final path
if (!@rename($tmpPath, $mapPath)) {
    $err = error_get_last();
    echo "Failed to rename $tmpPath -> $mapPath\n";
    if ($err) echo "Error: " . ($err['message'] ?? json_encode($err)) . "\n";
    // attempt to copy as fallback
    if (@copy($tmpPath, $mapPath)) {
        echo "Fallback: copied temp file to final path.\n";
        @unlink($tmpPath);
    } else {
        echo "Fallback copy also failed.\n";
        exit(1);
    }
}

$finalSize = @filesize($mapPath);
// Print generated summary and a hierarchical listing of the permission catalog
echo "Generated new menu-based access level map with " . count($accessLevels) . " entries.\n";
echo "Wrote to: $mapPath (bytes: " . ($finalSize === false ? 'unknown' : $finalSize) . ")\n";

// Helper to print the catalog in a Menu >> Submenu >>> Child style
function print_catalog_hierarchy($catalog)
{
    if (!is_array($catalog) || empty($catalog)) {
        echo "(permission catalog is empty)\n";
        return;
    }

    foreach ($catalog as $root) {
        if (!is_array($root)) continue;
        $rootLabel = isset($root['label']) && trim($root['label']) !== '' ? $root['label'] : (isset($root['key']) ? $root['key'] : '(unnamed)');
        echo $rootLabel . "\n";

        if (isset($root['children']) && is_array($root['children']) && !empty($root['children'])) {
            foreach ($root['children'] as $child) {
                $childLabel = isset($child['label']) && trim($child['label']) !== '' ? $child['label'] : (isset($child['key']) ? $child['key'] : '(unnamed)');
                echo ">> " . $childLabel . "\n";

                // deeper level if present
                if (isset($child['children']) && is_array($child['children']) && !empty($child['children'])) {
                    foreach ($child['children'] as $sub) {
                        $subLabel = isset($sub['label']) && trim($sub['label']) !== '' ? $sub['label'] : (isset($sub['key']) ? $sub['key'] : '(unnamed)');
                        echo ">>> " . $subLabel . "\n";
                    }
                }
            }
        }
    }
}

echo "\nPermission catalog (Menu >> Submenu >>> Child):\n";
print_catalog_hierarchy($permissionCatalog);

exit(0);
