---
name: add-top-level-menu
description: "Use when adding a new top-level menu and submenus to templates/menu.php that must NOT be nested inside any existing permission/if block."
applyTo:
  - "templates/menu.php"
---

# Add Top-Level Menu Instruction

Purpose: When the user says "add a new menu and submenu", ensure the assistant creates a true top-level menu item that will not be nested inside any existing `if`/permission block, so users with only the new permission(s) see it in the sidebar.

Behavior rules (short):
- Treat the phrase "add a new menu and submenu" as a trigger to create a top-level item.
- Always insert the new `<li>` at the root menu level. If there are unclosed `if` blocks nearby, close them first or insert after the nearest confirmed top-level sibling.
- Wrap the new block with a single permission guard that references only the new permission keys (use `has_any_permission([...])`).
- Do not nest the new block inside any existing permission `if` block.
- Never use RBAC checks such as `$_SESSION['user_type']`, `role`, or `=== 'admin'` for visibility. Use access level and permission helpers only (`has_permission`, `has_any_permission`, access-map driven permissions).

Minimal ready-to-paste prompt (use exactly when requesting a top-level menu):

"Add a new menu and submenu"

When triggered, use the following template (replace placeholders):

Menu title: "<MENU_TITLE>" (icon: `<ICON_CLASS>`)
Submenus: provide an ordered list of "Label | HREF | PermissionKey"
Placement preference: (optional) "after <EXISTING_TOP_LEVEL_TITLE>" or "end of root menu"

Insertion rules to follow exactly:
- Ensure the added code is at top-level in `templates/menu.php` (not enclosed by any other `if`/`endif`).
- If the nearest code is inside an `if` block, close that block (insert `<?php endif; ?>`) before inserting the new top-level block, or insert after a known top-level sibling.
- Wrap the new block in `<?php if ( has_any_permission([<PERMS>]) ): ?>` ... `<?php endif; ?>`.
- Do not add role-based conditions (RBAC) anywhere in the menu logic.

Example prompt (exact text to send to the assistant):

Add a new menu and submenu

Menu title: My Feature (icon: fa-star)
Submenus:
  - My Feature — View | /dashboard/myfeature/view.php | MyFeature - View
  - My Feature — Manage | /dashboard/myfeature/manage.php | MyFeature - Manage
Placement: end of root menu

Required output:
- A unified diff patch for changes applied to `templates/menu.php` (and any other files changed).
- The list of files changed and a one-line verification (e.g., `php -l`).
- Any commands to run locally (regenerate map, hard refresh).

Notes for implementer:
- If permission keys are new, update `tools/generate_access_map.php` canonicalization or document added keys and regenerate `assets/js/accesslevel-map.json`.
- Ensure page-level guards exist on the target pages.
