// ============================================================
// ADMIN PANEL
// ============================================================

// ── DRAWER ───────────────────────────────────────────────────────────────────

window.openDrawer = function(title, bodyHtml, footerHtml) {
    document.getElementById('admin-drawer-title').textContent = title;
    document.getElementById('admin-drawer-body').innerHTML   = bodyHtml;
    document.getElementById('admin-drawer-footer').innerHTML = footerHtml;
    document.getElementById('admin-drawer').classList.add('open');
    document.getElementById('admin-drawer-overlay').classList.add('open');
    document.body.style.overflow = 'hidden';
};

window.closeDrawer = function() {
    document.getElementById('admin-drawer').classList.remove('open');
    document.getElementById('admin-drawer-overlay').classList.remove('open');
    document.body.style.overflow = '';
};

// Close on Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') window.closeDrawer();
});

// ── FETCH HELPERS ─────────────────────────────────────────────────────────────

/**
 * POST to a JSON endpoint from inside the drawer.
 * Shows loading state on the primary button, surfaces errors inline.
 */
window.adminFetch = async function(url, body, onSuccess) {
    const btn = document.querySelector('#admin-drawer-footer .btn-primary');
    const err = document.getElementById('drawer-error');

    if (btn) { btn.disabled = true; btn.textContent = 'Saving…'; }
    if (err)   err.classList.remove('visible');

    try {
        const res  = await fetch(url, {
            method:  'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body:    new URLSearchParams(body).toString(),
        });
        const data = await res.json();

        if (data.success) {
            window.closeDrawer();
            onSuccess(data);
        } else {
            if (err) { err.textContent = data.message; err.classList.add('visible'); }
        }
    } catch {
        if (err) { err.textContent = 'An unexpected error occurred. Please try again.'; err.classList.add('visible'); }
    } finally {
        if (btn) { btn.disabled = false; btn.textContent = 'Save'; }
    }
};

/**
 * POST to a delete endpoint — confirms first, removes row on success.
 */
window.adminDelete = async function(url, confirmMsg, onSuccess) {
    if (!confirm(confirmMsg)) return;

    try {
        const res  = await fetch(url, { method: 'POST' });
        const data = await res.json();

        if (data.success) {
            onSuccess(data);
        } else {
            alert(data.message);
        }
    } catch {
        alert('An unexpected error occurred.');
    }
};

// ── LIVE FILTER ───────────────────────────────────────────────────────────────

/**
 * Filters table rows by text content as the user types.
 * liveFilter('user-search', 'users-table')
 */
window.liveFilter = function(inputId, tableId) {
    const input = document.getElementById(inputId);
    if (!input) return;

    input.addEventListener('input', function() {
        const q = this.value.toLowerCase();
        document.querySelectorAll('#' + tableId + ' tbody tr').forEach(row => {
            row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
        });
    });
};
