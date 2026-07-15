# Access Permission Flow (Starter Prompt)

Use this as the **main idea only**. Keep it short and follow this exact flow.

## Goal

Start from the menu structure first, then enforce permissions, then generate the map used by access-level assignment.

## Flow to Follow

1. Go to `[MENU-FILE-NAME]` (example: `menu.php`, `sidebar.php`) and read the file.
2. Find menu and submenu structure.
   - Some projects use `menu-item` + `submenu` naming.
   - During active development, everything is usually open first.
   - Add permission checks only when the feature set is stable.
3. If missing, insert permission wrappers:
   - Use `has_permission()` for **menu/root** access.
   - Use `has_any_permission()` for **submenu/child** access.
4. Permission intent:
   - `has_permission(menu)` answers: can user access this menu group?
   - `has_any_permission(submenus)` answers: can user access at least one part inside this menu?
   - This allows users to have a menu but only limited submenu access.
5. If generator file does not exist in the project, create it first at `tools/generate_access_map.php` (or adjust to your project's preferred path).
6. After wrappers are in place, run the generator.
7. Generator creates/updates map file at `assets/js/accesslevel-map.json` (or your chosen path) from detected menu/submenu permissions.
8. Generator must print capture output so users can see exactly what was detected (menus/submenus and summary).
9. `accesslevels.php` uses this map to capture and assign user access level + permissions.

## Generator Creation Rule (Important)

Recommended default paths for this repository (overrideable per-project):

- Generator: `tools/generate_access_map.php`
- Access map: `assets/js/accesslevel-map.json`

If the target project has no generator yet, create it at the generator path above or update this prompt to point to your preferred location.

Use this repository sample as behavior reference:
- `tools/generate_access_map.php` (logic reference)

The created generator must:
- scan menu and submenu structure,
- scan `has_permission()` and `has_any_permission()` keys,
- merge discovered keys into `assets/js/accesslevel-map.json`,
- create target directory if missing before write (for example `assets/js/`),
- print console summary (backup path, number of generated entries, output file path/size),
- print hierarchy list in this style so user sees capture result:
	- `Menu`
	- `>> Submenu`
	- `>>> Child`

## Insert-if-Missing Helpers (Server Side)

```php
function get_current_user_permissions() {
	if (!isset($_SESSION['user_permissions'])) {
		return [];
	}
	return (array) $_SESSION['user_permissions'];
}

function has_permission(string $key): bool {
	$perms = get_current_user_permissions();
	return in_array($key, $perms, true) || (isset($_SESSION['user_access_level']) && $_SESSION['user_access_level'] === -1);
}

function has_any_permission(array $keys): bool {
	$perms = get_current_user_permissions();
	if (isset($_SESSION['user_access_level']) && $_SESSION['user_access_level'] === -1) {
		return true;
	}
	foreach ($keys as $k) {
		if (in_array($k, $perms, true)) return true;
	}
	return false;
}
```

Ensure helpers are loaded in middleware and available in menu templates.

## Runtime integrations (endpoints & client)

Document the runtime pieces the generator and UI interact with so other projects can implement compatible endpoints.

-- `models/updated/get-user-permissions.php` — server GET endpoint used by the UI to load a user's saved permissions. Preferred source is a server-side per-user permissions store (for example a DB column such as `permissions` containing JSON, or another persistent store). Falls back to `assets/js/user-permissions.json` if a persisted value is missing. Returns `{ success: true, id_number, permissions: [...] }`.

- `models/updated/update-access-level.php` — server POST endpoint that accepts `{ id_number, access_level, permissions }` (permissions = leaf keys). Responsibilities:
	- validate caller authorization (admin / sentinel / permission),
	- load `assets/js/accesslevel-map.json`, validate incoming permissions against catalog,
	- determine `resolvedAccessLevel` (use explicit map match, sentinel `-1` when all leaf permissions selected, or keep provided numeric),
	- update the user's stored `access_level` and persist expanded `permissions` (leaves + ancestors) into the server-side per-user permissions store (e.g., a DB `permissions` column or a JSON file),
	- update session for affected user when appropriate and return updated user info JSON.

- `assets/js/accesslevel.js` — client-side loader and helper that:
	- fetches `assets/js/accesslevel-map.json` and populates `PERMISSION_TREE`, `ACCESS_LEVELS_ARRAY`, `ACCESS_LEVEL_MAP`,
	- exposes `window.AccessLevelManager` with helpers used by the UI: `getPermissionTree()`, `getPermissionsByLevel(level)`, `findAccessLevelByPermissions(perms)`, `computeCombinationIndex(perms)`, `getNextAccessLevel()`, `ancestorsOf()`, `descendantsOf()`, `updateUserAccessLevel()` (which POSTs to `update-access-level.php`),
	- provides deterministic fallback generation (single + pair combos) when the map is missing or small,
	- expands legacy aliases for convenience (so root keys imply their children when appropriate).

Include these endpoints and the client helper in the prompt so consuming projects implement compatible behavior.

## Minimal Menu Usage Pattern

```php
<?php if (has_permission('Maintenance')): ?>
	<?php if (has_any_permission(['Maintenance Accounts Access Levels', 'Maintenance Duplicate Checker'])): ?>
		<!-- render submenu items -->
	<?php endif; ?>
<?php endif; ?>
```

## Final Reminder

Order is strict:
1) add/find permission checks in menu,
2) run map generator,
3) use generated map in `accesslevels.php` for assignment.

Ask the user: Do you want me to automatically patch this repository templates now to add missing `has_permission()` / `has_any_permission()` wrappers?

## Samples (what the generator writes and prints)

Sample (trimmed) map JSON written to `assets/js/accesslevel-map.json`:

```json
{
	"version": 2,
	"permission_catalog": [
		{
			"key": "Profile",
			"label": "Profile",
			"children": [
				{ "key": "Profile View", "label": "Profile View" },
				{ "key": "Profile Signature", "label": "Profile Signature" }
			]
		},
		{
			"key": "Bills Payment Transaction",
			"label": "Bills Payment Transaction",
			"children": [
				{ "key": "BP Import Transaction", "label": "BP Import Transaction" },
				{ "key": "BP Post Transaction", "label": "BP Post Transaction" }
			]
		},
		{
			"key": "Masterfiles",
			"label": "Masterfiles",
			"children": [
				{ "key": "Masterfiles View Bank List", "label": "Masterfiles View Bank List" }
			]
		}
	],
	"access_levels": [
		{ "access_level": 1, "permissions": ["Profile View","Profile Signature"] },
		{ "access_level": 2, "permissions": ["BP Import Transaction","BP Post Transaction"] },
		{ "access_level": 3, "permissions": ["Profile View","Profile Signature","BP Import Transaction","BP Post Transaction"] },
		{ "access_level": -1, "permissions": ["Profile View","Profile Signature","BP Import Transaction","BP Post Transaction","Masterfiles View Bank List"] }
	],
	"needs_migration": false
}
```

Sample console output from `tools/generate_access_map.php` (capture summary + hierarchy):

```

Created base access map at: assets/js/accesslevel-map.json
Detected and merged 2 permission keys from source files.
Backup created: assets/js/accesslevel-map.json.bak.20260317_123456
Generated new menu-based access level map with 4 entries.
Wrote to: assets/js/accesslevel-map.json (bytes: 2048)

Permission catalog (Menu >> Submenu >>> Child):
Profile
>> Profile View
>> Profile Signature
Bills Payment Transaction
>> BP Import Transaction
>> BP Post Transaction
Masterfiles
>> Masterfiles View Bank List
```

Access level bitmask example (how combinations are derived):

- Roots discovered (ordered): 0=`Profile`, 1=`Bills Payment Transaction`, 2=`Masterfiles`.
- For N roots generate masks 1..(2^N - 1) i.e. 1..7. Each mask's binary bits indicate which roots are included.
- Example: mask `5` (binary `101`) includes roots 0 and 2 -> permissions = all leaves under `Profile` + all leaves under `Masterfiles`.
- The generator will also append sentinel `access_level: -1` containing all leaf permissions (full access).

These exact sample outputs and the bitmask logic should be reflected by the generator. In this repository the generator is `tools/generate_access_map.php` and the map file is `assets/js/accesslevel-map.json`.

