(function (window, $) {
    'use strict';

    const DEFAULT_PERMISSION_TREE = [
        {
            key: 'Bills Payment',
            label: 'Bills Payment',
            icon: 'payments',
            children: [
                { key: 'BP Import Transaction', label: 'Import > Transaction', icon: 'receipt' },
                { key: 'BP Import Cancellation', label: 'Import > Cancellation', icon: 'block' },
                { key: 'BP Post Transaction', label: 'Post > Transaction', icon: 'send' },
                { key: 'BP Settlement Adjustment Entry', label: 'Settlement > Adjustment Entry', icon: 'account_tree' },
                { key: 'BP Settlement Per Bank', label: 'Settlement > Per Bank', icon: 'account_balance' },
                { key: 'BP Report Volume', label: 'Report > Volume Report', icon: 'bar_chart' },
                { key: 'BP Report EDI', label: 'Report > EDI Report', icon: 'description' },
                { key: 'BP Report Transaction Details', label: 'Report > Transaction Details', icon: 'list_alt' },
                { key: 'BP Report Transaction Summary', label: 'Report > Transaction Summary', icon: 'table_chart' },
                { key: 'BP Report Cancellation', label: 'Report > Cancellation Report', icon: 'cancel' },
                { key: 'BP Report Balance Sheet', label: 'Report > Balance Sheet', icon: 'analytics' }
            ]
        },
        {
            key: 'Billing Invoice',
            label: 'Billing Invoice',
            icon: 'receipt_long',
            children: [
                { key: 'BI Create Manual', label: 'Create > Service Charge (MANUAL)', icon: 'edit_note' },
                { key: 'BI Create Automated', label: 'Create > Service Charge (AUTOMATED)', icon: 'auto_mode' },
                { key: 'Invoice Review', label: 'Review > For Checking / Review', icon: 'rate_review' },
                { key: 'Invoice Approval', label: 'Approval > Billing Invoice Approval', icon: 'fact_check' },
                { key: 'BI Report Billing Invoice', label: 'Report > Billing Invoice Report', icon: 'summarize' }
            ]
        },
        {
            key: 'Masterfiles',
            label: 'Masterfiles',
            icon: 'folder',
            children: [
                { key: 'Masterfiles View Bank List', label: 'View > Bank List', icon: 'account_balance_wallet' }
            ]
        },
        {
            key: 'Maintenance',
            label: 'Maintenance',
            icon: 'build',
            children: [
                {
                    key: 'Accounts',
                    label: 'Accounts',
                    icon: 'manage_accounts',
                    children: [
                        { key: 'Maintenance Accounts User Management', label: 'Accounts > User Management', icon: 'group' },
                        { key: 'Maintenance Accounts Access Levels', label: 'Accounts > Access Levels', icon: 'vpn_key' }
                    ]
                },
                { key: 'Maintenance Duplicate Transaction', label: 'Duplicate > Transaction', icon: 'content_copy' },
                { key: 'Maintenance Masterfiles Partner List', label: 'Masterfiles > Partner List', icon: 'groups' },
                { key: 'Maintenance Masterfiles Bank List', label: 'Masterfiles > Bank List', icon: 'savings' }
            ]
        },
        {
            key: 'Tools',
            label: 'Tools',
            icon: 'handyman',
            children: [
                { key: 'Tools KPX Generator', label: 'KPX/KP7 Generator', icon: 'memory' },
                { key: 'Tools Branch Maker', label: 'Branch Maker', icon: 'alt_route' },
                { key: 'Tools File Fetch', label: 'File Fetch', icon: 'cloud_download' }
            ]
        }
    ];

    const LEGACY_ALIAS_CHILDREN = {
        'Bills Payment': [
            'BP Import Transaction',
            'BP Import Cancellation',
            'BP Post Transaction',
            'BP Settlement Adjustment Entry',
            'BP Settlement Per Bank',
            'BP Report Volume',
            'BP Report EDI',
            'BP Report Transaction Details',
            'BP Report Transaction Summary',
            'BP Report Cancellation',
            'BP Report Balance Sheet'
        ],
        'Billing Invoice': [
            'BI Create Manual',
            'BI Create Automated',
            'Invoice Review',
            'Invoice Approval',
            'BI Report Billing Invoice'
        ],
        'Masterfiles': ['Masterfiles View Bank List'],
        'Maintenance': [
            'Accounts',
            'Maintenance Accounts User Management',
            'Maintenance Accounts Access Levels',
            'Maintenance Duplicate Transaction',
            'Maintenance Masterfiles Partner List',
            'Maintenance Masterfiles Bank List'
        ],
        'Accounts': ['Maintenance Accounts User Management', 'Maintenance Accounts Access Levels'],
        'Tools': ['Tools KPX Generator', 'Tools Branch Maker', 'Tools File Fetch']
    };

    let ACCESS_LEVEL_MAP = {};
    let ACCESS_LEVELS_ARRAY = [];
    let PERMISSION_TREE = JSON.parse(JSON.stringify(DEFAULT_PERMISSION_TREE));
    let isMapReady = false;

    initializeAccessLevelMap();

    function initializeAccessLevelMap() {
        $.ajax({
            url: '../../../assets/js/accesslevel-map.json?v=' + Date.now(),
            type: 'GET',
            dataType: 'json',
            cache: false,
            success: function (response) {
                parseMapResponse(response);
                isMapReady = true;
            },
            error: function () {
                useGeneratedFallback();
            }
        });
    }

    function parseMapResponse(response) {
        if (Array.isArray(response)) {
            ACCESS_LEVELS_ARRAY = normalizeLevelsArray(response);
            ACCESS_LEVEL_MAP = convertArrayToMap(ACCESS_LEVELS_ARRAY);
            PERMISSION_TREE = JSON.parse(JSON.stringify(DEFAULT_PERMISSION_TREE));
            return;
        }

        if (response && typeof response === 'object') {
            const levels = Array.isArray(response.access_levels) ? response.access_levels : [];
            const catalog = Array.isArray(response.permission_catalog) ? response.permission_catalog : [];
            ACCESS_LEVELS_ARRAY = normalizeLevelsArray(levels);
            PERMISSION_TREE = catalog.length ? normalizeTree(catalog) : JSON.parse(JSON.stringify(DEFAULT_PERMISSION_TREE));

            // If the provided mapping is very small (legacy or minimal), generate
            // a deterministic set of access level combinations (singles + pairs)
            // from the permission catalog so the find/match logic works for
            // menu+submenu+child combinations without requiring a massive file.
            (function ensureComprehensiveMapping() {
                // use only leaf keys (submenu/child) to generate combinations
                function collectLeafKeys(nodes) {
                    const out = [];
                    (nodes || []).forEach(function walk(node) {
                        if (!node) return;
                        if (!node.children || !node.children.length) {
                            out.push(node.key);
                            return;
                        }
                        (node.children || []).forEach(walk);
                    });
                    return out;
                }

                const flat = collectLeafKeys(PERMISSION_TREE).slice();
                const uniqueKeys = Array.from(new Set(flat)).sort();

                // If levels provided are fewer than the number of single permissions,
                // generate additional combinations but DO NOT overwrite existing
                // access level numbers. Append generated entries starting after
                // the current maximum access_level so explicit mappings keep
                // their intended values (e.g., powers-of-two roots).
                if ((ACCESS_LEVELS_ARRAY || []).length < uniqueKeys.length) {
                    const generated = [];
                    const seen = new Set();

                    var maxExisting = 0;
                    (ACCESS_LEVELS_ARRAY || []).forEach(function (row) {
                        maxExisting = Math.max(maxExisting, parseInt(row.access_level, 10) || 0);
                        seen.add(createPermissionsKey(row.permissions || []));
                    });

                    let nextLevel = maxExisting + 1;

                    function pushPerms(perms) {
                        const key = createPermissionsKey(perms);
                        if (seen.has(key)) return;
                        seen.add(key);
                        generated.push({ access_level: nextLevel++, permissions: normalizePermissions(perms) });
                    }

                    // singles
                    for (let i = 0; i < uniqueKeys.length; i++) {
                        pushPerms([uniqueKeys[i]]);
                    }

                    // pairs (unordered)
                    for (let i = 0; i < uniqueKeys.length; i++) {
                        for (let j = i + 1; j < uniqueKeys.length; j++) {
                            pushPerms([uniqueKeys[i], uniqueKeys[j]]);
                        }
                    }

                    // Append generated entries to the existing mapping
                    ACCESS_LEVELS_ARRAY = (ACCESS_LEVELS_ARRAY || []).concat(generated.slice());
                }
            })();

            ACCESS_LEVEL_MAP = convertArrayToMap(ACCESS_LEVELS_ARRAY);
            return;
        }

        useGeneratedFallback();
    }

    function useGeneratedFallback() {
        ACCESS_LEVEL_MAP = {};
        ACCESS_LEVELS_ARRAY = [];
        PERMISSION_TREE = JSON.parse(JSON.stringify(DEFAULT_PERMISSION_TREE));
        isMapReady = true;
    }

    function normalizeLevelsArray(levelArray) {
        return levelArray
            .map(function (item) {
                const level = parseInt(item.access_level, 10);
                if (!level) return null;
                return {
                    access_level: level,
                    permissions: normalizePermissions(item.permissions || [])
                };
            })
            .filter(Boolean)
            .sort(function (a, b) { return a.access_level - b.access_level; });
    }

    function normalizeTree(tree) {
        return tree
            .map(function (node) {
                if (!node || typeof node !== 'object' || !node.key) return null;
                const normalized = {
                    key: String(node.key),
                    label: node.label ? String(node.label) : String(node.key),
                    icon: node.icon ? String(node.icon) : 'check_circle'
                };
                if (Array.isArray(node.children) && node.children.length) {
                    normalized.children = normalizeTree(node.children);
                }
                return normalized;
            })
            .filter(Boolean);
    }

    function convertArrayToMap(levelArray) {
        const map = {};
        levelArray.forEach(function (item) {
            map[item.access_level] = {
                access_level: item.access_level,
                permissions: normalizePermissions(item.permissions)
            };
        });
        return map;
    }

    function normalizePermissions(permissions) {
        if (!Array.isArray(permissions)) return [];
        const set = new Set();
        permissions.forEach(function (permission) {
            if (typeof permission === 'string' && permission.trim() !== '') {
                set.add(permission.trim());
            }
        });
        return Array.from(set).sort();
    }

    function flattenTree(tree) {
        const out = [];
        (tree || []).forEach(function walk(node) {
            out.push(node.key);
            (node.children || []).forEach(walk);
        });
        return out;
    }

    function descendantsOf(key) {
        let descendants = [];
        (PERMISSION_TREE || []).forEach(function walk(node) {
            if (node.key === key) {
                descendants = flattenTree(node.children || []);
                return;
            }
            (node.children || []).forEach(walk);
        });
        return descendants;
    }

    function ancestorsOf(key) {
        const trail = [];

        function walk(nodes, parents) {
            for (let index = 0; index < (nodes || []).length; index++) {
                const node = nodes[index];
                if (!node) continue;

                if (node.key === key) {
                    trail.push.apply(trail, parents);
                    return true;
                }

                if (Array.isArray(node.children) && node.children.length) {
                    const nextParents = parents.concat(node.key);
                    if (walk(node.children, nextParents)) {
                        return true;
                    }
                }
            }
            return false;
        }

        walk(PERMISSION_TREE || [], []);
        return Array.from(new Set(trail));
    }

    function expandLegacyAliases(permissions) {
        const set = new Set(normalizePermissions(permissions));

        Object.keys(LEGACY_ALIAS_CHILDREN).forEach(function (legacyKey) {
            if (!set.has(legacyKey)) {
                return;
            }

            const children = LEGACY_ALIAS_CHILDREN[legacyKey] || [];
            const hasExplicitChild = children.some(function (childKey) {
                return set.has(childKey);
            });

            if (!hasExplicitChild) {
                children.forEach(function (child) {
                    set.add(child);
                });
            }
        });

        return Array.from(set).sort();
    }

    function getPermissionsByLevel(level) {
        const normalizedLevel = parseInt(level, 10);
        if (!normalizedLevel) return [];

        // If there is an explicit mapping for this numeric level, use it.
        if (ACCESS_LEVEL_MAP[normalizedLevel]) {
            return expandLegacyAliases(ACCESS_LEVEL_MAP[normalizedLevel].permissions || []);
        }

        // Fallback: treat the numeric level as a bitmask of root menus.
        // This implements the powers-of-two menu combination scheme where
        // root 0 -> bit 1, root 1 -> bit 2, root 2 -> bit 4, etc.
        try {
            const out = [];
            const roots = Array.isArray(PERMISSION_TREE) ? PERMISSION_TREE : [];
            for (let i = 0; i < roots.length; i++) {
                const bit = 1 << i; // 1,2,4,8,...
                if ((normalizedLevel & bit) === bit) {
                    // collect leaf keys under this root
                    (function collectLeaf(nodes) {
                        (nodes || []).forEach(function walk(n) {
                            if (!n) return;
                            if (!n.children || !n.children.length) {
                                out.push(n.key);
                                return;
                            }
                            (n.children || []).forEach(walk);
                        });
                    })(roots[i].children || []);
                }
            }
            return expandLegacyAliases(Array.from(new Set(out)).sort());
        } catch (e) {
            return [];
        }
    }

    function createPermissionsKey(permissions) {
        return JSON.stringify(normalizePermissions(permissions));
    }

    function getAllLevelsArray() {
        return ACCESS_LEVELS_ARRAY.slice();
    }

    function getNextAccessLevel() {
        let maxLevel = 0;
        ACCESS_LEVELS_ARRAY.forEach(function (row) {
            maxLevel = Math.max(maxLevel, parseInt(row.access_level, 10) || 0);
        });
        return maxLevel + 1;
    }

    function findAccessLevelByPermissions(permissions) {
        const normalizedTargetPermissions = normalizePermissions(permissions);
        const target = createPermissionsKey(normalizedTargetPermissions);
        for (let index = 0; index < ACCESS_LEVELS_ARRAY.length; index++) {
            const row = ACCESS_LEVELS_ARRAY[index];
            if (createPermissionsKey(row.permissions || []) === target) {
                return parseInt(row.access_level, 10) || 0;
            }
        }

        // If there is no exact match, prefer the closest explicit mapping
        // that is a superset of the selected permissions. This keeps
        // root-level mappings stable (e.g., selecting only "TRL Entry"
        // should still resolve to the explicit TRL level instead of
        // a computed root index).
        if (normalizedTargetPermissions.length) {
            let bestLevel = 0;
            let bestPermissionCount = Number.POSITIVE_INFINITY;

            for (let index = 0; index < ACCESS_LEVELS_ARRAY.length; index++) {
                const row = ACCESS_LEVELS_ARRAY[index];
                const rowLevel = parseInt(row.access_level, 10) || 0;
                const rowPermissions = normalizePermissions(row.permissions || []);
                if (!rowLevel || rowLevel < 0 || rowPermissions.length === 0) {
                    continue;
                }

                const rowSet = new Set(rowPermissions);
                const isSuperset = normalizedTargetPermissions.every(function (permissionKey) {
                    return rowSet.has(permissionKey);
                });
                if (!isSuperset) {
                    continue;
                }

                // Prefer the smallest superset; on ties use lower level number.
                if (rowPermissions.length < bestPermissionCount || (
                    rowPermissions.length === bestPermissionCount && (bestLevel === 0 || rowLevel < bestLevel)
                )) {
                    bestLevel = rowLevel;
                    bestPermissionCount = rowPermissions.length;
                }
            }

            if (bestLevel > 0) {
                return bestLevel;
            }
        }

        // No exact match found in explicit map. Compute a deterministic
        // combination index based on root catalogs (singles 1..N, then
        // pairs starting at N+1, then triples, etc.) so combinations
        // like [root0] => 1, [root1] => 2, [root0,root1] => N+1, etc.
        try {
            const roots = Array.isArray(PERMISSION_TREE) ? PERMISSION_TREE : [];
            const n = roots.length;
            if (!n) return 0;

            // Determine which roots are selected by checking if any leaf
            // permission from that root appears in `permissions`.
            const sel = [];
            const permSet = new Set(normalizePermissions(permissions));
            for (let i = 0; i < n; i++) {
                const root = roots[i];
                const leaves = [];
                (function collect(node) {
                    if (!node) return;
                    if (!node.children || !node.children.length) {
                        leaves.push(node.key);
                        return;
                    }
                    leaves.push(node.key);
                    (node.children || []).forEach(collect);
                })(root);

                const has = leaves.some(function (k) { return permSet.has(k); });
                if (has) sel.push(i + 1); // 1-based indices
            }

            if (!sel.length) return 0;

            // If only one root selected, return its 1-based index
            if (sel.length === 1) return sel[0];

            // Otherwise compute lexicographic rank among combinations of the same size
            function combinations(n, k) {
                const out = [];
                const combo = [];
                function back(start) {
                    if (combo.length === k) {
                        out.push(combo.slice());
                        return;
                    }
                    for (let i = start; i <= n; i++) {
                        combo.push(i);
                        back(i + 1);
                        combo.pop();
                    }
                }
                back(1);
                return out;
            }

            // compute offset: total subsets of sizes < sel.length among n
            let offset = 0;
            for (let s = 1; s < sel.length; s++) {
                // compute C(n, s)
                let c = 1;
                for (let i = 0; i < s; i++) {
                    c = c * (n - i) / (i + 1);
                }
                offset += c;
            }

            const combos = combinations(n, sel.length);
            // find lex index
            let lex = 0;
            for (let i = 0; i < combos.length; i++) {
                const a = combos[i];
                let match = true;
                for (let j = 0; j < a.length; j++) {
                    if (a[j] !== sel[j]) { match = false; break; }
                }
                if (match) { lex = i; break; }
            }

            return offset + lex + 1;
        } catch (e) {
            return 0;
        }
    }

    function computeCombinationIndex(permissions) {
        // Returns the deterministic combination index as used for UI display
        try {
            const roots = Array.isArray(PERMISSION_TREE) ? PERMISSION_TREE : [];
            const n = roots.length;
            if (!n) return 0;

            const permSet = new Set(normalizePermissions(permissions));
            const sel = [];
            for (let i = 0; i < n; i++) {
                const root = roots[i];
                const leaves = [];
                (function collect(node) {
                    if (!node) return;
                    if (!node.children || !node.children.length) {
                        leaves.push(node.key);
                        return;
                    }
                    leaves.push(node.key);
                    (node.children || []).forEach(collect);
                })(root);
                const has = leaves.some(function (k) { return permSet.has(k); });
                if (has) sel.push(i + 1);
            }
            if (!sel.length) return 0;
            if (sel.length === 1) return sel[0];

            function combinations(n, k) {
                const out = [];
                const combo = [];
                function back(start) {
                    if (combo.length === k) {
                        out.push(combo.slice());
                        return;
                    }
                    for (let i = start; i <= n; i++) {
                        combo.push(i);
                        back(i + 1);
                        combo.pop();
                    }
                }
                back(1);
                return out;
            }

            let offset = 0;
            for (let s = 1; s < sel.length; s++) {
                let c = 1;
                for (let i = 0; i < s; i++) {
                    c = c * (n - i) / (i + 1);
                }
                offset += c;
            }

            const combos = combinations(n, sel.length);
            let lex = 0;
            for (let i = 0; i < combos.length; i++) {
                const a = combos[i];
                let match = true;
                for (let j = 0; j < a.length; j++) {
                    if (a[j] !== sel[j]) { match = false; break; }
                }
                if (match) { lex = i; break; }
            }
            return offset + lex + 1;
        } catch (e) {
            return 0;
        }
    }

    function updateUserAccessLevel(idNumber, newAccessLevel, permissions, onSuccess, onError) {
        const payload = {
            id_number: idNumber,
            access_level: newAccessLevel || 0,
            permissions: normalizePermissions(permissions || [])
        };

        $.ajax({
            url: '../../../models/updated/update-access-level.php',
            type: 'POST',
            data: JSON.stringify(payload),
            contentType: 'application/json',
            dataType: 'json',
            success: function (response) {
                if (typeof onSuccess === 'function') {
                    onSuccess(response);
                }
            },
            error: function (xhr) {
                if (typeof onError === 'function') {
                    onError(xhr);
                }
            }
        });
    }

    function getPermissionTree() {
        return JSON.parse(JSON.stringify(PERMISSION_TREE));
    }

    window.AccessLevelManager = {
        isReady: function () { return isMapReady; },
        getPermissionTree: getPermissionTree,
        getPermissionsByLevel: getPermissionsByLevel,
        getAllLevelsArray: getAllLevelsArray,
        getNextAccessLevel: getNextAccessLevel,
        createPermissionsKey: createPermissionsKey,
        findAccessLevelByPermissions: findAccessLevelByPermissions,
        computeCombinationIndex: computeCombinationIndex,
        normalizePermissions: normalizePermissions,
        ancestorsOf: ancestorsOf,
        descendantsOf: descendantsOf,
        expandLegacyAliases: expandLegacyAliases,
        updateUserAccessLevel: updateUserAccessLevel
    };
})(window, jQuery);
