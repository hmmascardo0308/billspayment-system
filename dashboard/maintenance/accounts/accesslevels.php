<?php
require_once __DIR__ . '/../../../config/config.php';

session_start();

// include permission helpers (provides has_permission(), etc.)
include_once __DIR__ . '/../../../templates/middleware.php';
$id = resolve_user_identifier();
if (empty($id)) { header('Location: ../../../login_form.php'); exit; }

// Allow access if the permission helper is present and the user
// has either the generic 'Access Levels' permission, the
// 'Maintenance Accounts Access Levels' permission, or the
// superuser sentinel access level (-1). This avoids denying
// access when permission key names differ between the map
// and legacy entries.
if (!function_exists('has_permission') || (
    !has_permission('Access Levels')
    && !has_permission('Maintenance Accounts Access Levels')
    && !(isset($_SESSION['access_level']) && intval($_SESSION['access_level']) === -1)
)) {
    header('Location: ../../home.php');
    exit;
}

// prefer explicit session values for current user email; do not gate on role
$current_user_email = $_SESSION['admin_email'] ?? $_SESSION['user_email'] ?? '';

// Allow access for admins, or users with the admin sentinel (-1),
// or users who have the specific maintenance permission.
$allowed = false;
// Allow access for users with superuser access level (-1) or
// users who have the maintenance permission. Do not base access
// solely on the `user_type` role value.
if (isset($_SESSION['access_level']) && intval($_SESSION['access_level']) === -1) {
    $allowed = true;
}
if (function_exists('has_permission') && has_permission('Maintenance Accounts Access Levels')) {
    $allowed = true;
}

if (!$allowed) {
    header('Location: ../../../index.php');
    exit;
}

$users = [];
$columns = [];

try {
    $query = "SELECT * FROM mldb.user_form ORDER BY date_created DESC";
    $result = mysqli_query($conn, $query);

    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            if (array_key_exists('access_level', $row) && $row['access_level'] === null) {
                $row['access_level'] = 0;
            }
            $users[] = $row;
        }
        mysqli_free_result($result);
    }

    if (!empty($users)) {
        $columns = array_keys($users[0]);
    } else {
        $columnResult = mysqli_query($conn, "SHOW COLUMNS FROM mldb.user_form");
        if ($columnResult) {
            while ($columnRow = mysqli_fetch_assoc($columnResult)) {
                $columns[] = $columnRow['Field'];
            }
            mysqli_free_result($columnResult);
        }
    }
} catch (Exception $e) {
    error_log('Access levels page error: ' . $e->getMessage());
}

// Force table columns to the desired display order and set
$columns = ['id', 'id_number', 'first_name', 'middle_name', 'last_name', 'email', 'access_level'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Levels | <?php if($_SESSION['user_type'] === 'admin' || $_SESSION['user_type'] === 'user') echo ucfirst($_SESSION['user_type']); else echo "Guest";?></title>
    <link rel="stylesheet" href="../../../assets/css/templates/style.css?v=<?php echo time(); ?>">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <script src="https://kit.fontawesome.com/30b908cc5a.js" crossorigin="anonymous"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../../assets/js/sweetalert2.all.min.js"></script>
    <link rel="icon" href="../../../images/MLW logo.png" type="image/png">
    <style>
        :root { --brand: #C62828; }
        #searchInput:focus {
            border-color: #dc3545;
            box-shadow: 0 0 0 0.2rem rgba(220,53,69,0.15);
        }

        /* Table styling - red & white theme */
        #users-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
            .bp-section-title { display:flex; gap:12px; align-items:center; }
            .bp-section-sub { font-size:13px; color:#6b7280; margin-top:4px; }

        #users-table thead th {
            background-color: #dc3545;
            color: #ffffff;
            border: 1px solid #f8d7da;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            padding: 10px;
            vertical-align: middle;
        }

        #users-table tbody td {
            background: #ffffff;
            border: 1px solid #f1f1f1;
            padding: 10px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            vertical-align: middle;
        }

        #users-table tbody tr:hover {
            background-color: #fff5f5;
            cursor: pointer;
        }

        #users-table tbody tr.selected {
            background-color: #fde8ea !important;
        }

        .table-responsive { overflow-x: auto; }

        .permissions-box {
            border: 1px solid #eee;
            border-radius: 8px;
            padding: 12px 16px;
            background: #fff;
        }

        .nested-perm {
            margin-left: 26px;
        }

        .permission-card {
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 10px;
            background: #fff;
            transition: all .2s ease;
            cursor: pointer;
            height: 100%;
        }

        .permission-card:hover {
            border-color: #dc3545;
            box-shadow: 0 0 0 0.15rem rgba(220,53,69,.15);
            transform: translateY(-1px);
        }

        .permission-card.selected {
            border-color: #dc3545;
            background: #fff5f5;
            box-shadow: 0 0 0 0.2rem rgba(220,53,69,.2);
        }

        .permission-card .card-title {
            font-weight: 700;
            color: #dc3545;
            margin-bottom: 4px;
        }

        .permission-card .card-perms {
            font-size: 12px;
            color: #374151;
            line-height: 1.25;
        }

        /* Group header and color accents */
        .perm-group {
            margin-bottom: 8px;
            border-radius: 8px;
            overflow: hidden;
            border: 1px solid #eef2f7;
            background: #ffffff;
        }

        .perm-group-header {
            display:flex;
            align-items:center;
            justify-content:space-between;
            padding:8px 12px;
            cursor:pointer;
            user-select:none;
            gap:12px;
        }

        .perm-group-title { font-weight:700; color:#111827; }
        .perm-group-body { padding:10px; display:block; }

        .perm-toggle-icon { transition: transform .18s ease; }

        /* Accent palette - used for group headers / titles */
        /* Use darker reds only (brand palette) to avoid pale/invisible accents */
        .accent-0 { --accent: #7f1d1d; }
        .accent-1 { --accent: #b91c1c; }
        .accent-2 { --accent: #c62828; }
        .accent-3 { --accent: #ef4444; }
        .accent-4 { --accent: #f87171; }

        .perm-group-header .perm-group-title { color: var(--accent); }
        .permission-card .card-title { color: rgba(0,0,0,0.8); }
        .permission-card.selected { box-shadow: 0 0 0 0.18rem rgba(198,40,40,0.14); }

        #permissionCards {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 10px;
            background: #fafafa;
        }

        .selected-level-chip {
            display: inline-block;
            margin: 3px 6px 3px 0;
            padding: 4px 8px;
            border-radius: 999px;
            background: #fee2e2;
            border: 1px solid #fecaca;
            color: #b91c1c;
            font-size: 12px;
            font-weight: 600;
        }

        /* Permission preview styles */
        #permissionPreview .preview-panel {
            box-shadow: 0 10px 24px rgba(15,23,42,0.12);
            border-radius: 10px;
            border: 1px solid #e5e7eb;
            padding: 10px;
            background: #ffffff;
            width: 340px;
            overflow: hidden;
            /* left-side compact accordion; avoid forcing overflow by collapsing groups */
        }

        #permissionPreview {
            z-index: 1080;
            pointer-events: auto;
        }

        #permissionPreview * {
            pointer-events: auto;
        }

        #permissionPreview .preview-header {
            display:flex;
            justify-content:space-between;
            align-items:center;
            margin-bottom: 6px;
        }

        #permissionPreview .preview-grid {
            display: block;
            gap: 8px;
            margin-top: 6px;
            overflow-y: auto;
            overscroll-behavior: contain;
        }

        #permissionPreview .preview-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 7px 10px;
            border-radius: 6px;
            border: 1px solid #eef2f7;
            background: #ffffff;
            font-size: 13px;
            color: #374151;
            transition: all .15s ease;
        }

        #permissionPreview .preview-item:hover {
            background: #f8fafc;
            border-color: #dbe3ee;
        }

        /* Swap Reset All button default and hover styles: filled by default, outline on hover */
        #resetAllBtn {
            transition: all .12s ease;
        }
        #resetAllBtn:hover {
            background: #ffffff !important;
            color: #dc3545 !important;
            border: 1px solid #dc3545 !important;
            box-shadow: none !important;
        }

        #permissionPreview .preview-item .label {
            display: inline-block;
            width: calc(100% - 24px);
        }

        #permissionPreview .preview-item .check {
            width: 18px;
            text-align: center;
            color: #dc3545;
            float: right;
        }

        #permissionPreview .preview-item.active {
            background: #fff5f5;
            border-color: #f1b8be;
            box-shadow: 0 0 0 0.12rem rgba(220,53,69,.10);
        }

        #permissionPreview .preview-item.depth-1 .label { padding-left: 10px; font-weight:600; }
        #permissionPreview .preview-item.depth-2 .label { padding-left: 22px; }
        #permissionPreview .preview-item.depth-3 .label { padding-left: 32px; font-size:13px; }

        /* Preview group accordion */
        .preview-group {
            border: 1px solid #f1f5f9;
            background: #fcfcfd;
            border-radius: 8px;
            padding: 4px 6px;
            margin-bottom: 8px;
        }

        .preview-group-header {
            display:flex;
            justify-content:space-between;
            align-items:center;
            padding:6px 6px;
            cursor:pointer;
            border-radius: 6px;
        }

        .preview-group-header .title { font-weight:700; color:var(--accent); }
        .preview-group-body { display:none; padding:4px 0 6px 0; }
        .preview-toggle { color:#6b7280; }
    </style>
</head>

<body>
    <div class="main-container">
        <?php include '../../../templates/header_ui.php'; ?>
        <?php include '../../../templates/sidebar.php'; ?>

        <!-- Permission preview (visually left of modal) -->
        <div id="permissionPreview" style="display:none; position:fixed;">
            <div class="preview-panel">
                <div class="preview-header">
                    <strong>Permissions</strong>
                </div>
                <div id="permissionPreviewList" class="preview-grid" aria-live="polite">
                    <!-- populated by page -->
                </div>
            </div>
        </div>

        <div class="bp-section-header" role="region" aria-label="Page title">
            <div class="bp-section-title">
                <i class="fa-solid fa-key micon-hdr" aria-hidden="true" style="color:var(--brand);"></i>
                <div>
                    <h3 class="mb-0">Access Levels</h3>
                    <div class="bp-section-sub">Manage user access levels, saved per-user permissions, and role defaults</div>
                </div>
            </div>
        </div>

        <div class="bp-card container-fluid mt-3 p-4">
            <div class="row mb-3 align-items-center">
                <div class="col-md-8 col-lg-6">
                    <div class="input-group">
                        <span class="input-group-text bg-white" id="searchIcon" style="border-right:0;">
                            <i class="fa-solid fa-magnifying-glass" aria-hidden="true" style="color:#6b7280;"></i>
                        </span>
                        <input
                            type="search"
                            id="searchInput"
                            class="form-control"
                            placeholder="Search by ID Number / First Name / Last Name / Email"
                            aria-describedby="searchIcon"
                        />
                        <button class="btn btn-outline-secondary" type="button" id="searchBtn" title="Search">
                            <i class="fa-solid fa-magnifying-glass"></i>
                        </button>
                    </div>
                </div>
                <div class="col-md-4 col-lg-6 text-end">
                    <button class="btn btn-danger" type="button" id="resetAllBtn" title="Reset All">
                        Reset All
                    </button>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle" id="users-table">
                    <thead class="table-light">
                        <tr>
                            <?php foreach ($columns as $column): ?>
                                <?php if ($column === 'email'): ?>
                                    <th>Username</th>
                                <?php elseif ($column === 'id_number'): ?>
                                    <th>ID Number</th>
                                <?php elseif ($column === 'id'): ?>
                                    <th>ID</th>
                                <?php else: ?>
                                    <th><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $column))); ?></th>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($users)): ?>
                            <tr>
                                <td colspan="<?php echo count($columns); ?>" class="text-center text-muted">No users found.</td>
                            </tr>
                        <?php else: ?>
                            <?php $index = 1; ?>
                            <?php foreach ($users as $user): ?>
                                <?php
                                    $emailValue = isset($user['email']) ? (string)$user['email'] : '';
                                    $idNumberValue = isset($user['id_number']) ? (string)$user['id_number'] : '';
                                    $firstNameValue = isset($user['first_name']) ? (string)$user['first_name'] : '';
                                    $lastNameValue = isset($user['last_name']) ? (string)$user['last_name'] : '';
                                    $accessLevelValue = isset($user['access_level']) ? (int)$user['access_level'] : 0;
                                    $user['access_level'] = $accessLevelValue;
                                ?>
                                <tr
                                    data-user='<?php echo htmlspecialchars(json_encode($user), ENT_QUOTES, 'UTF-8'); ?>'
                                    data-id-number="<?php echo htmlspecialchars(strtolower($idNumberValue)); ?>"
                                    data-first-name="<?php echo htmlspecialchars(strtolower($firstNameValue)); ?>"
                                    data-last-name="<?php echo htmlspecialchars(strtolower($lastNameValue)); ?>"
                                    data-email="<?php echo htmlspecialchars(strtolower($emailValue)); ?>"
                                >
                                    
                                    <?php foreach ($columns as $column): ?>
                                        <?php if ($column === 'access_level'): ?>
                                            <td class="access-level-cell"><?php echo htmlspecialchars((string)$accessLevelValue); ?></td>
                                        <?php else: ?>
                                            <td><?php echo htmlspecialchars((string)($user[$column] ?? '')); ?></td>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="modal fade" id="accessLevelModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">Access Level</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p><strong>Username:</strong> <span id="modalUsername">-</span></p>
                    <p><strong>Acces Level:</strong> <span id="modalCurrentLevel">0</span></p>
                    <p><strong>Active Access Level:</strong> <span id="modalActiveSummary">None</span></p>
                    <p><strong>Selected Permissions:</strong> <span id="selectedCardsSummary">None</span></p>

                    <div class="mb-3 d-flex align-items-center" style="justify-content:space-between; gap:12px;">
                        <div>
                            <label class="form-label"><strong>Access Level:</strong></label>
                        </div>
                        <div style="display:flex; align-items:center; gap:8px;">
                            <input type="checkbox" id="selectAllPerms"> <label for="selectAllPerms" style="font-size:13px; margin:0;">Select All</label>
                        </div>
                    </div>
                    <div class="mb-3">
                        <div id="permissionCards" class="row g-2"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-danger" id="saveAccessLevelBtn">Save Access Level</button>
                </div>
            </div>
        </div>
    </div>

    <script src="../../../assets/js/accesslevel.js?v=<?php echo time(); ?>"></script>
    <script>
        $(document).ready(function() {
            const accessLevelModal = new bootstrap.Modal(document.getElementById('accessLevelModal'));
            let selectedUser = null;
            let selectedRow = null;
            let selectedPermissions = [];
            let computedLevelFromCards = 0;

            function getCurrentTree() {
                return window.AccessLevelManager.getPermissionTree() || [];
            }

            function getAllPermissionKeys() {
                return flattenTree(getCurrentTree());
            }

            function renderSelectedCardsSummary() {
                if (!selectedPermissions.length) {
                    $('#selectedCardsSummary').text('None');
                    return;
                }

                const chips = selectedPermissions
                    .slice()
                    .map(function(permission) {
                        return { label: permission };
                    })
                    .map(function(item) {
                        return '<span class="selected-level-chip">' + item.label + '</span>';
                    })
                    .join('');

                $('#selectedCardsSummary').html(chips);
            }

            function flattenTree(nodes) {
                const out = [];
                (nodes || []).forEach(function walk(node) {
                    out.push(node.key);
                    (node.children || []).forEach(walk);
                });
                return out;
            }

            function positionPermissionPreview() {
                const preview = $('#permissionPreview');
                if (!preview.length || !preview.is(':visible')) {
                    return;
                }

                const modalDialog = $('#accessLevelModal .modal-dialog:visible').first();
                if (!modalDialog.length) {
                    return;
                }

                const rect = modalDialog.get(0).getBoundingClientRect();
                const panel = preview.find('.preview-panel');
                const previewList = $('#permissionPreviewList');
                const viewportWidth = window.innerWidth || document.documentElement.clientWidth;
                const viewportHeight = window.innerHeight || document.documentElement.clientHeight;
                const panelWidth = panel.outerWidth() || 340;
                const gap = 12;

                let left = rect.left - panelWidth - gap;
                if (left < 8) {
                    left = Math.min(rect.right + gap, viewportWidth - panelWidth - 8);
                }
                left = Math.max(8, left);

                const top = Math.max(8, rect.top);
                const maxPanelHeight = Math.max(240, Math.min(rect.height, viewportHeight - top - 8));
                const headerHeight = preview.find('.preview-header').outerHeight(true) || 32;
                const listMaxHeight = Math.max(160, maxPanelHeight - headerHeight - 14);

                preview.css({
                    left: left + 'px',
                    top: top + 'px'
                });

                panel.css('max-height', maxPanelHeight + 'px');
                previewList.css('max-height', listMaxHeight + 'px');
            }

            function renderPermissionCards() {
                const tree = getCurrentTree();
                const cardsContainer = $('#permissionCards');
                const previewList = $('#permissionPreviewList');
                // clear previous render to avoid duplicate nodes when modal reopened
                cardsContainer.empty();
                previewList.empty();

                let contentHtml = '';
                let previewHtml = '';
                // Render groups (root catalog) with collapse toggles and color accents
                function renderNode(node, depth, idx) {
                    const safeNodeKey = String(node.key || '').replace(/"/g, '&quot;');
                    const hasChildren = Array.isArray(node.children) && node.children.length > 0;
                    const depthPadding = depth > 0 ? ' style="margin-left:' + (depth * 16) + 'px;"' : '';
                    const treePrefix = depth === 0 ? '' : Array(depth + 1).join('>');

                    contentHtml += '<div class="col-12">'
                        + '<div class="permission-card" data-permission="' + safeNodeKey + '" data-parent="' + (hasChildren ? '1' : '0') + '"' + depthPadding + '>'
                        + '<div class="card-title">'
                        + '<span class="material-icons" style="font-size:18px; vertical-align:middle; margin-right:6px; color:var(--accent);">' + (node.icon || 'check_circle') + '</span>'
                        + (treePrefix ? '<span style="font-weight:600; margin-right:5px; color:#6b7280;">' + treePrefix + '</span>' : '')
                        + (node.label || node.key)
                        + '</div></div></div>';

                    if (node.key !== 'Home' && node.key !== 'Logout') {
                        previewHtml += '<div class="preview-item depth-' + Math.min(depth, 3) + '" data-permission="' + safeNodeKey + '">'
                            + '<span class="label">' + (treePrefix ? treePrefix + ' ' : '') + (node.label || node.key) + '</span>'
                            + '<span class="check"><i class="fa-solid fa-check-circle" style="display:none;"></i></span>'
                            + '</div>';
                    }
                    (node.children || []).forEach(function(child) {
                        renderNode(child, depth + 1, idx);
                    });
                }

                // build permission cards grouped by root catalog
                const palette = ['accent-0','accent-1','accent-2','accent-3','accent-4'];

                // render catalog cards into groups (left side remains preview groups)
                tree.forEach(function(rootNode, idx) {
                    const accentClass = palette[idx % palette.length];
                    let groupHtml = '';
                    groupHtml += '<div class="perm-group ' + accentClass + '" data-group-index="' + idx + '">';
                    groupHtml += '<div class="perm-group-header">'
                        + '<div class="perm-group-title">' + (rootNode.label || rootNode.key) + '</div>'
                        + '<div class="perm-toggle-icon">' + '<i class="fa-solid fa-chevron-down" style="transform:rotate(-90deg);"></i>' + '</div>'
                        + '</div>';
                    groupHtml += '<div class="perm-group-body" style="display:none;">';

                    // render nodes inside group
                    contentHtml = '';
                    renderNode(rootNode, 0, idx);
                    groupHtml += '<div class="row">' + contentHtml + '</div>';
                    groupHtml += '</div></div>';
                    cardsContainer.append(groupHtml);
                });

                // Build a compact left-side preview as an accordion showing Menu > Submenu > Child
                const previewGroups = [];
                function renderPreviewNode(node, depth) {
                    const safeNodeKey = String(node.key || '').replace(/"/g, '&quot;');
                    let html = '';
                    if (node.key !== 'Home' && node.key !== 'Logout') {
                        html += '<div class="preview-item depth-' + Math.min(depth,3) + '" data-permission="' + safeNodeKey + '">'
                            + '<span class="label">' + (depth > 0 ? Array(depth+1).join('>') + ' ' : '') + (node.label || node.key) + '</span>'
                            + '<span class="check"><i class="fa-solid fa-check-circle" style="display:none;"></i></span>'
                            + '</div>';
                    }
                    (node.children || []).forEach(function(child) {
                        html += renderPreviewNode(child, depth + 1);
                    });
                    return html;
                }

                tree.forEach(function(rootNode, idx) {
                    const accentClass = palette[idx % palette.length];
                    let groupHtml = '';
                    groupHtml += '<div class="preview-group ' + accentClass + '" data-group-index="' + idx + '">';
                    groupHtml += '<div class="preview-group-header">'
                        + '<div class="title">' + (rootNode.label || rootNode.key) + '</div>'
                        + '<div class="preview-toggle"><i class="fa-solid fa-chevron-down" style="transform:rotate(-90deg);"></i></div>'
                        + '</div>';
                    groupHtml += '<div class="preview-group-body">';
                    groupHtml += renderPreviewNode(rootNode, 0);
                    groupHtml += '</div></div>';
                    previewGroups.push(groupHtml);
                });

                previewList.html(previewGroups.join('\n'));

                // attach toggle handlers for groups (collapsed by default)
                $('.perm-group-header').off('click').on('click', function() {
                    const body = $(this).next('.perm-group-body');
                    const icon = $(this).find('.perm-toggle-icon i');
                    if (body.is(':visible')) {
                        body.slideUp(120);
                        icon.css('transform','rotate(-90deg)');
                    } else {
                        body.slideDown(120);
                        icon.css('transform','rotate(0deg)');
                    }
                });
                $('.preview-group-header').off('click').on('click', function() {
                    const body = $(this).next('.preview-group-body');
                    const icon = $(this).find('.preview-toggle i');
                    if (body.is(':visible')) {
                        body.slideUp(100);
                        icon.css('transform','rotate(-90deg)');
                    } else {
                        body.slideDown(100);
                        icon.css('transform','rotate(0deg)');
                    }
                });

                // ensure preview UI updated and initial selection state applied
                updatePreviewUI();
            }

            function getDescendants(parentKey) {
                return window.AccessLevelManager.descendantsOf(parentKey) || [];
            }

            function collectLeafKeys(nodes) {
                const out = [];
                (nodes || []).forEach(function walk(node) {
                    if (!node) return;
                    if (!node.children || !node.children.length) {
                        out.push(node.key);
                    } else {
                        (node.children || []).forEach(walk);
                    }
                });
                return out.sort();
            }

            function normalizePermissionList(items) {
                return Array.from(new Set(Array.isArray(items) ? items : [])).sort();
            }

            function isAllLeafSelected(leafKeys) {
                const selected = normalizePermissionList(leafKeys);
                const currentAllLeafKeys = normalizePermissionList(collectLeafKeys(window.AccessLevelManager.getPermissionTree()));
                return selected.length > 0
                    && selected.length === currentAllLeafKeys.length
                    && selected.every(function(k, i) { return k === currentAllLeafKeys[i]; });
            }

            function syncCardSelectionUI() {
                const selectedSet = new Set(selectedPermissions);
                $('#permissionCards .permission-card').each(function() {
                    const permission = $(this).attr('data-permission');
                    $(this).toggleClass('selected', selectedSet.has(permission));
                });
            }

            function updatePreviewUI() {
                const selectedSet = new Set(selectedPermissions);
                $('#permissionPreviewList .preview-item').each(function() {
                    const permission = $(this).attr('data-permission');
                    if (!permission || permission === 'Home' || permission === 'Logout') return;
                    const isActive = selectedSet.has(permission);
                    $(this).toggleClass('active', isActive);
                    $(this).find('.check i').css('display', isActive ? 'inline-block' : 'none');
                });

                $('#permissionPreviewList .preview-group').each(function() {
                    const group = $(this);
                    const body = group.find('.preview-group-body').first();
                    const icon = group.find('.preview-toggle i').first();
                    const hasActive = group.find('.preview-item.active').length > 0;

                    if (hasActive) {
                        body.stop(true, true).slideDown(100);
                        icon.css('transform', 'rotate(0deg)');
                    } else {
                        body.stop(true, true).slideUp(100);
                        icon.css('transform', 'rotate(-90deg)');
                    }
                });

                positionPermissionPreview();
            }

            function updateComputedSelectionState() {
                if (!selectedPermissions.length) {
                    computedLevelFromCards = 0;
                    applyPermissionPreview();
                    $('#modalCurrentLevel').text('0');
                    renderSelectedCardsSummary();
                    return;
                }

                // Use only leaf (child/submenu) keys when resolving numeric access levels.
                // UI `selectedPermissions` may contain ancestor menu keys for visual state,
                // but the persisted mapping and generated map are based on leaf keys only.
                const leafSelected = selectedPermissions.filter(function(p) {
                    return (getDescendants(p) || []).length === 0;
                });
                const normalizedLeafSelected = normalizePermissionList(leafSelected);

                // Robust full-selection detection: if the normalized leaf
                // selection exactly matches the set of all leaf keys from
                // the current permission tree, treat it as the sentinel -1.
                // This handles timing or map-generation differences where
                // an exact key-by-key match may fail.
                try {
                    const allLeafKeys = collectLeafKeys(window.AccessLevelManager.getPermissionTree());
                    if (normalizedLeafSelected.length > 0 && normalizedLeafSelected.length === allLeafKeys.length) {
                        computedLevelFromCards = -1;
                        applyPermissionPreview();
                        $('#modalCurrentLevel').text('-1');
                        renderSelectedCardsSummary();
                        return;
                    }
                } catch (e) {
                    // fall through to existing logic on error
                }

                if (isAllLeafSelected(normalizedLeafSelected)) {
                    computedLevelFromCards = -1;
                    applyPermissionPreview();
                    $('#modalCurrentLevel').text('-1');
                    renderSelectedCardsSummary();
                    return;
                }

                const matchedLevel = window.AccessLevelManager.findAccessLevelByPermissions(normalizedLeafSelected);

                // If no exact match found in the explicit map, compute a
                // deterministic combination index based on root menus.
                let computedCombo = 0;
                if (!matchedLevel) {
                    computedCombo = window.AccessLevelManager.computeCombinationIndex(normalizedLeafSelected) || 0;
                }

                const nextLevel = window.AccessLevelManager.getNextAccessLevel();

                // Priority: explicit match > computed combination index > next generated level
                computedLevelFromCards = matchedLevel || computedCombo || nextLevel;
                applyPermissionPreview();
                $('#modalCurrentLevel').text(String(computedLevelFromCards));
                renderSelectedCardsSummary();
            }

            function applyPermissionPreview() {
                $('#modalActiveSummary').text(selectedPermissions.length ? selectedPermissions.join(', ') : 'None');
                updatePreviewUI();
            }

            function performSearch() {
                const query = String($('#searchInput').val() || '').toLowerCase().trim();
                $('#users-table tbody tr').each(function() {
                    const idNumber = String($(this).attr('data-id-number') || '').toLowerCase();
                    const firstName = String($(this).attr('data-first-name') || '').toLowerCase();
                    const lastName = String($(this).attr('data-last-name') || '').toLowerCase();
                    const email = String($(this).attr('data-email') || '').toLowerCase();

                    const isMatch = (
                        idNumber.indexOf(query) !== -1 ||
                        firstName.indexOf(query) !== -1 ||
                        lastName.indexOf(query) !== -1 ||
                        email.indexOf(query) !== -1
                    );

                    $(this).toggle(isMatch);
                });
            }

            // live search as user types
            $('#searchInput').on('input', performSearch);

            // support clicking the search button and Enter key to run the same search
            $('#searchBtn').on('click', function() {
                performSearch();
                $('#searchInput').focus();
            });

            $('#searchInput').on('keypress', function(e) {
                if (e.which === 13) {
                    e.preventDefault();
                    performSearch();
                }
            });

            // Reset All button: set all non-sentinel users to level 1; re-apply -1 users
            $('#resetAllBtn').on('click', function() {
                Swal.fire({
                    title: 'Reset all users Default Access Level and Re apply Admin Access Level',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, reset all',
                    cancelButtonText: 'Cancel'
                }).then(function(result) {
                    if (!result || !result.isConfirmed) return;

                    $.ajax({
                        url: '../../../models/updated/reset-all-access-levels.php',
                        method: 'POST',
                        dataType: 'json'
                    }).done(function(resp) {
                        if (!resp || !resp.success) {
                            Swal.fire({ icon: 'error', title: 'Failed', text: resp && resp.message ? resp.message : 'Reset failed.' });
                            return;
                        }

                        // Update table rows if details returned, otherwise reload
                        if (Array.isArray(resp.updated) && resp.updated.length) {
                            resp.updated.forEach(function(u) {
                                var idn = (u.id_number || '').toString().toLowerCase();
                                var row = $('#users-table tbody tr').filter(function() {
                                    return String($(this).attr('data-id-number') || '').toLowerCase() === idn;
                                }).first();

                                if (!row || !row.length) return;

                                try {
                                    var du = row.attr('data-user');
                                    var obj = du ? JSON.parse(du) : {};
                                    obj.access_level = parseInt(u.access_level, 10) || 0;
                                    row.attr('data-user', JSON.stringify(obj));
                                    row.find('.access-level-cell').text(String(obj.access_level));
                                } catch (e) {
                                    // ignore
                                }
                            });
                        }

                        Swal.fire({ icon: 'success', title: 'Reset complete', text: resp.message || 'All users updated.' });

                        // If current session was affected, force reload to refresh menus
                        if (resp.current_user_changed) {
                            window.location.reload(true);
                        }
                    }).fail(function() {
                        Swal.fire({ icon: 'error', title: 'Server error', text: 'Failed to perform reset.' });
                    });
                });
            });

            $('#users-table tbody').on('click', 'tr', function() {
                if ($(this).find('td').length === 0) {
                    return;
                }

                $('#users-table tbody tr').removeClass('selected');
                $(this).addClass('selected');

                const rawUser = $(this).attr('data-user');

                try {
                    selectedUser = rawUser ? JSON.parse(rawUser) : null;
                } catch (error) {
                    selectedUser = null;
                }

                if (!selectedUser || !selectedUser.id_number) {
                    Swal.fire({ icon: 'error', title: 'Invalid user data' });
                    return;
                }

                selectedRow = $(this);
                selectedRow = $(this);
                const currentLevel = parseInt(selectedUser.access_level, 10) || 0;
                computedLevelFromCards = currentLevel;

                $('#modalUsername').text(selectedUser.email || '');
                $('#modalCurrentLevel').text(currentLevel);

                // Try to load explicit saved permissions for this user. If none
                // found, fall back to mapping-based permissions by access level.
                $.ajax({
                    url: '../../../models/updated/get-user-permissions.php',
                    method: 'GET',
                    data: { id_number: selectedUser.id_number },
                    dataType: 'json'
                }).done(function(resp) {
                    if (resp && resp.success && Array.isArray(resp.permissions) && resp.permissions.length) {
                        // use saved (leaf) permissions
                        selectedPermissions = normalizePermissionList(resp.permissions);
                    } else {
                        // fallback to legacy mapping by numeric access level
                        selectedPermissions = normalizePermissionList(window.AccessLevelManager.getPermissionsByLevel(currentLevel));
                    }
                }).fail(function() {
                    selectedPermissions = normalizePermissionList(window.AccessLevelManager.getPermissionsByLevel(currentLevel));
                }).always(function() {
                    // keep Select All checkbox aligned with current loaded permissions
                    const currentLeafKeys = collectLeafKeys(window.AccessLevelManager.getPermissionTree());
                    const selectedLeafKeys = normalizePermissionList(selectedPermissions.filter(function(p) {
                        return (getDescendants(p) || []).length === 0;
                    }));
                    const isFull = selectedLeafKeys.length > 0
                        && selectedLeafKeys.length === currentLeafKeys.length
                        && selectedLeafKeys.every(function(k, i) { return k === currentLeafKeys[i]; });
                    $('#selectAllPerms').prop('checked', isFull);

                    renderPermissionCards();
                    syncCardSelectionUI();
                    renderSelectedCardsSummary();
                    applyPermissionPreview();
                    accessLevelModal.show();
                });
            });

            function togglePermissionFromCard(clickedPermission) {
                if (!clickedPermission) {
                    return;
                }

                const selectedSet = new Set(selectedPermissions);
                const isSelected = selectedSet.has(clickedPermission);

                if (!isSelected) {
                    selectedSet.add(clickedPermission);
                    const ancestors = window.AccessLevelManager.ancestorsOf(clickedPermission) || [];
                    ancestors.forEach(function (parentPermission) {
                        selectedSet.add(parentPermission);
                    });
                    getDescendants(clickedPermission).forEach(function (childPermission) {
                        selectedSet.add(childPermission);
                    });
                } else {
                    selectedSet.delete(clickedPermission);
                    getDescendants(clickedPermission).forEach(function (childPermission) {
                        selectedSet.delete(childPermission);
                    });
                }

                let hasOrphanParent = true;
                while (hasOrphanParent) {
                    hasOrphanParent = false;
                    Array.from(selectedSet).forEach(function(permission) {
                        const descendants = getDescendants(permission);
                        if (!descendants.length) {
                            return;
                        }

                        const hasSelectedDescendant = descendants.some(function(descendantPermission) {
                            return selectedSet.has(descendantPermission);
                        });

                        if (!hasSelectedDescendant) {
                            selectedSet.delete(permission);
                            hasOrphanParent = true;
                        }
                    });
                }

                selectedPermissions = Array.from(selectedSet).filter(function (permission) {
                    return getAllPermissionKeys().indexOf(permission) !== -1;
                });
                selectedPermissions = normalizePermissionList(selectedPermissions);

                syncCardSelectionUI();
                updateComputedSelectionState();
            }

            $(document).on('click', '#permissionCards .permission-card', function(e) {
                const clickedPermission = $(this).attr('data-permission');
                togglePermissionFromCard(clickedPermission);
            });

            $(document).on('click', '#permissionPreviewList .preview-item', function(e) {
                const pid = $(this).attr('data-permission');
                if (!pid) return;
                togglePermissionFromCard(pid);
            });

            $(document).on('change', '#selectAllPerms', function() {
                if ($(this).is(':checked')) {
                    selectedPermissions = normalizePermissionList(getAllPermissionKeys());
                } else {
                    selectedPermissions = [];
                }
                syncCardSelectionUI();
                updateComputedSelectionState();
            });

            $('#accessLevelModal').on('hidden.bs.modal', function () {
                $('#permissionPreview').hide();
            });

            $('#accessLevelModal').on('shown.bs.modal', function () {
                $('#permissionPreview').show();
                positionPermissionPreview();
                updatePreviewUI();
            });

            $(window).on('resize', function() {
                positionPermissionPreview();
            });

            // Use a non-passive wheel listener on the preview list so we can
            // call preventDefault() to stop the page from scrolling when
            // the user scrolls inside the permission preview panel.
            (function attachPreviewWheel() {
                function addWheel() {
                    const el = document.getElementById('permissionPreviewList');
                    if (!el) return;
                    if (el._wheelAttached) return;
                    el._wheelAttached = true;
                    el.addEventListener('wheel', function(evt) {
                        const deltaY = typeof evt.deltaY === 'number' ? evt.deltaY : 0;
                        this.scrollTop += deltaY;
                        evt.preventDefault();
                        evt.stopPropagation();
                    }, { passive: false });
                }

                addWheel();
                $(document).on('shown.bs.modal', function() { addWheel(); });
            })();

            $('#saveAccessLevelBtn').on('click', function() {
                if (!selectedUser || !selectedRow) {
                    Swal.fire({ icon: 'warning', title: 'Select a user first' });
                    return;
                }

                if (!selectedPermissions.length) {
                    Swal.fire({ icon: 'warning', title: 'Select at least one permission card' });
                    return;
                }

                // send only leaf keys to server so mapping remains leaf-based
                const leafSelectedToSave = selectedPermissions.filter(function(p) {
                    return (getDescendants(p) || []).length === 0;
                });
                const normalizedLeafToSave = normalizePermissionList(leafSelectedToSave);

                // Recompute current leaf keys from the live permission tree (map may load async)
                // If all leaf permissions are selected, use sentinel -1
                let levelToSave = computedLevelFromCards;
                if ($('#selectAllPerms').is(':checked') || isAllLeafSelected(normalizedLeafToSave)) {
                    levelToSave = -1;
                }

                // Debug: log counts and final level sent
                try {
                    const currentAllLeafKeys = collectLeafKeys(window.AccessLevelManager.getPermissionTree());
                    console.log('saveAccessLevel: leafSelected=', normalizedLeafToSave.length, 'allLeaf=', (currentAllLeafKeys||[]).length, 'levelToSave=', levelToSave);
                } catch (e) {}

                window.AccessLevelManager.updateUserAccessLevel(
                    selectedUser.id_number,
                    levelToSave,
                    normalizedLeafToSave,
                    function(response) {
                        if (!response || !response.success) {
                            Swal.fire({ icon: 'error', title: 'Failed', text: response && response.message ? response.message : 'Failed to update access level.' });
                            return;
                        }

                        const updatedLevel = parseInt(response.updated.access_level, 10) || 0;
                        selectedUser.access_level = updatedLevel;
                        currentModalLevel = updatedLevel;
                        selectedRow.attr('data-user', JSON.stringify(selectedUser));
                        selectedRow.find('.access-level-cell').text(String(updatedLevel));

                        // If the saved user is the current logged-in user, reload
                        // so server-rendered menus and session-derived permissions
                        // are refreshed immediately without requiring logout.
                        const currentSessionEmail = '<?php echo isset($current_user_email) ? addslashes($current_user_email) : ''; ?>'.toLowerCase();
                        const updatedEmail = (response.updated.email || '').toLowerCase();

                        accessLevelModal.hide();
                        Swal.fire({ icon: 'success', title: 'Success', text: 'Access level updated successfully.' });

                        if (currentSessionEmail && updatedEmail && currentSessionEmail === updatedEmail) {
                            // force reload to pick up refreshed session/menu
                            window.location.reload(true);
                        }
                    },
                    function(xhr) {
                        let errorMessage = 'Server error while updating access level.';
                        if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
                            errorMessage = xhr.responseJSON.message;
                        }
                        Swal.fire({ icon: 'error', title: 'Error', text: errorMessage });
                    }
                );
            });
        });
    </script>

    <?php include '../../../templates/footer.php'; ?>
</body>
</html>
