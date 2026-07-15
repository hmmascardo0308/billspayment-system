# Access Levels — Implementation & AI Prompt

## Summary
This document specifies the exact flow for assigning an access level and permissions using the UI in `accesslevels.php`. It includes the front-end save/update flow, the server API contract, acceptance tests, code snippets (client + server), and a ready-to-use AI prompt you can paste to reproduce this feature in other projects.

---

## Objectives
- Persist user access level and leaf permissions on the server.
- Immediately reflect changes in the table row DOM after Save.
- Append `?debug_access=1` to the page URL and perform a reload so server-side menus/session updates are visible on refresh.
- If the edited user is the current logged-in user, force reload immediately.

---

## Front-end flow (exact steps)
1. Row click -> open `Access Level` modal.
   - GET `/models/updated/get-user-permissions.php?id_number=...`
   - If server returns saved `permissions` (leaf keys), use them; otherwise use `AccessLevelManager.getPermissionsByLevel(level)`.

2. User edits permissions via permission cards. Maintain:
   - `selectedPermissions` (leaf & ancestor keys used for UI state)
   - `computedLevelFromCards` (numeric or -1 sentinel when all leafs selected)

3. User clicks Save:
   - Validate `selectedPermissions` contains at least one permission or allow sentinel -1.
   - Send payload to server containing: `id_number`, `access_level` (int or -1), `permissions` (leaf keys array).

4. On server success (see API contract below):
   - Update `selectedUser.access_level` in memory.
   - Update row `data-user` attribute and `.access-level-cell` text immediately.
   - Close modal and show success toast.
   - Append or set `debug_access=1` in the current URL and reload page to ensure server-rendered menus reflect the change. Use the `URL` API and `window.location.href = url.toString();`.
   - If `response.updated.email` equals the current session email, force immediate reload with the debug flag.

Notes:
- If you prefer not to force reload for non-current users, you may instead `history.replaceState(null, '', url.toString())` to persist the `debug_access` flag without navigating; but a full reload guarantees server-side state is refreshed.

---

## Server API contract
Endpoint: `/models/updated/update-access-level.php` (POST)

Request (form-encoded or JSON):
{
  id_number: string,
  access_level: number, // -1 allowed
  permissions: ["leaf.key.1", "leaf.key.2"]
}

Success response (HTTP 200 JSON):
{
  "success": true,
  "updated": {
    "id_number": "2022001",
    "email": "user@example.com",
    "access_level": -1,
    "permissions": ["a.b", "a.c"]
  },
  "message": "Saved"
}

Failure response:
{ "success": false, "message": "Reason for failure" }

Server requirements:
- Persist leaf permissions and numeric access_level (use -1 sentinel for "all").
- Validate the caller has permission to assign access levels.
- Return `updated` object echoing stored values (email and access_level required for client logic).

Example minimal PHP stub (conceptual):

```php
<?php
include '../../../config/config.php';
header('Content-Type: application/json');
try {
    $id_number = $_POST['id_number'] ?? null;
    $access_level = isset($_POST['access_level']) ? intval($_POST['access_level']) : 0;
    $permissions = isset($_POST['permissions']) ? json_decode($_POST['permissions'], true) : [];

    // TODO: permission checks (is admin / has permission)

    // Persist to DB (pseudo)
    // Save numeric access_level to user_form.access_level
    // Save permissions to user_permissions table (remove existing leafs then insert)

    echo json_encode([ 'success' => true, 'updated' => [
        'id_number' => $id_number,
        'email' => 'user@example.com',
        'access_level' => $access_level,
        'permissions' => $permissions
    ], 'message' => 'Saved' ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([ 'success' => false, 'message' => $e->getMessage() ]);
}
```

---

## Client snippet: append debug flag & reload
Use this exact snippet after a successful save response:

```js
// ensure debug_access=1 is present and force reload
const url = new URL(window.location.href);
url.searchParams.set('debug_access', '1');
window.location.href = url.toString();
```

If you only want to persist the parameter without reload:

```js
const url = new URL(window.location.href);
url.searchParams.set('debug_access', '1');
history.replaceState(null, '', url.toString());
```

---

## Acceptance tests (manual)
- Test A (non-current user):
  1. Open `accesslevels.php`.
  2. Edit user X permissions, Save.
  3. Modal closes, table `.access-level-cell` updates.
  4. Page reloads with `?debug_access=1` and shows persisted access level.

- Test B (current session user):
  1. Logged-in user edits own account.
  2. Save should force reload with `?debug_access=1`.
  3. Server-rendered menus reflect new permissions.

- Test C (all-permissions sentinel):
  1. Select all leaf permissions.
  2. Server saves `access_level = -1` and table shows `-1`.

---

## Example AJAX payload
```json
{
  "id_number": "2022001",
  "access_level": -1,
  "permissions": ["menu.dashboard.view", "payments.create"]
}
```

---

## Ready-to-use AI prompt

Use this prompt to instruct an AI agent (or a developer) to implement the feature across front-end and back-end files.

```
Task: Implement "Assign Access Level & Permissions" feature in the repository.

Target files:
- dashboard/maintenance/accounts/accesslevels.php
- assets/js/accesslevel.js (or inline JS if present)
- models/updated/get-user-permissions.php (read existing)
- models/updated/update-access-level.php (create or adapt)

Requirements:
1. When saving a user's access level and permissions, persist to DB and return JSON per contract.
2. On client success, immediately update the user's row (data-user and .access-level-cell).
3. Append or set `debug_access=1` in the current page URL and reload the page so server-rendered menus reflect changes.
4. If the saved user matches the current session email, force immediate reload.
5. Use only leaf permission keys for persistence; support `access_level = -1` for "all" sentinel.

Server contract:
- Success JSON includes `success: true`, `updated: { id_number, email, access_level, permissions }`.
- Failure JSON includes `success: false, message`.

Client hints:
- Use `URL` API and `window.location.href = url.toString();` to reload with `debug_access=1`.
- Update row DOM before reload so a quick UX confirmation is visible.

Deliverables:
- Patch files implementing server endpoint and client update logic.
- Tests steps in PR description showing the acceptance checklist.

Return only the files changed and a short summary of what was added.
```

---

## Next steps
- I created this document in `dashboard/maintenance/accounts/accesslevels-prompt.md`.
- If you want, I can now apply a minimal client-side patch to call `url.searchParams.set('debug_access','1')` and perform the reload after save; and/or scaffold `models/updated/update-access-level.php` with real DB queries.

