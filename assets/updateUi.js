// ============================================================
// updateUi.js
// Reactive UI helpers — attached to window so any page script
// can call them directly after a fetch, same pattern as
// openDrawer, adminFetch, turboReload etc.
//
// Imported once in app.js — no per-page import needed.
// ============================================================


// ── USERS — ACCESS BADGE ──────────────────────────────────────────────────────
// Recalculates and re-renders the ACCESS badge in a user row after either
// dashboard or POS toggle fires. Reads live checkbox state — no extra fetch.
//
// Template requirements:
//   - Row:   <tr id="user-row-{{ user.id }}">
//   - Dash:  <input data-access-toggle="dashboard" type="checkbox" ...>
//   - POS:   <input data-access-toggle="pos"       type="checkbox" ...>
//   - Badge: <span  data-access-badge>...</span>
//
// Call after a successful toggleUserAccess():
//   updateUserAccessBadge(userId);

window.updateUserAccessBadge = function(userId) {
    const row = document.getElementById(`user-row-${userId}`);
    if (!row) return;

    const dashCb = row.querySelector('[data-access-toggle="dashboard"]');
    const posCb  = row.querySelector('[data-access-toggle="pos"]');
    const badge  = row.querySelector('[data-access-badge]');

    if (!dashCb || !posCb || !badge) return;

    const dashOn = dashCb.checked;
    const posOn  = posCb.checked;

    if (dashOn && posOn) {
        badge.className   = 'text-xs bg-green-50 text-green-700 px-2 py-0.5 rounded font-semibold';
        badge.textContent = 'Full';
    } else if (dashOn) {
        badge.className   = 'text-xs bg-blue-50 text-blue-700 px-2 py-0.5 rounded font-semibold';
        badge.textContent = 'Dashboard';
    } else if (posOn) {
        badge.className   = 'text-xs bg-amber-50 text-amber-700 px-2 py-0.5 rounded font-semibold';
        badge.textContent = 'POS only';
    } else {
        badge.className   = 'text-xs bg-gray-100 text-gray-500 px-2 py-0.5 rounded font-semibold';
        badge.textContent = 'None';
    }
};


// ── ROLES SHOW — ASSIGNED COUNT ───────────────────────────────────────────────
// Updates the "X permissions assigned" count in the role summary header
// and the "X of Y assigned" counter in the search bar.
//
// Template requirements:
//   - <span id="assigned-count">       in the role summary
//   - <span id="assigned-count-label"> in the search bar (optional)
//
// Call after a successful togglePermission():
//   updatePermissionAssignedCount(assignedCount);

window.updatePermissionAssignedCount = function(count) {
    const summary = document.getElementById('assigned-count');
    if (summary) summary.textContent = count;

    const label = document.getElementById('assigned-count-label');
    if (label) label.textContent = count;
};


// ── ROLES SHOW — CATEGORY COUNT BADGE ────────────────────────────────────────
// Updates the small count badge in a permission category section header to
// reflect how many permissions in that category are currently toggled on.
//
// Template requirements:
//   - Permission row: <div id="perm-row-{{ p.id }}" ...>
//   - Section:        <div class="perm-section" ...>
//   - Badge:          <span data-category-count>...</span> in the section header
//
// Call after a successful togglePermission():
//   updateCategoryCount(permId);

window.updateCategoryCount = function(permId) {
    const permRow = document.getElementById(`perm-row-${permId}`);
    if (!permRow) return;

    const section = permRow.closest('.perm-section');
    if (!section) return;

    const badge = section.querySelector('[data-category-count]');
    if (!badge) return;

    const assigned = [...section.querySelectorAll('input[type="checkbox"]')]
        .filter(cb => cb.checked).length;

    badge.textContent = assigned;
};