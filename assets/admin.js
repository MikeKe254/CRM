// Admin panel JS

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

document.addEventListener('keydown', e => { if (e.key === 'Escape') window.closeDrawer(); });

window.adminFetch = async function(url, body, onSuccess) {
    const btn = document.querySelector('#admin-drawer-footer button[data-primary]');
    const err = document.getElementById('drawer-error');
    if (btn) { btn.disabled = true; btn.textContent = 'Saving…'; }
    if (err) { err.textContent = ''; err.classList.add('hidden'); }
    try {
        const res  = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(body).toString() });
        const data = await res.json();
        if (data.success) { window.closeDrawer(); onSuccess(data); }
        else if (err) { err.textContent = data.message; err.classList.remove('hidden'); }
    } catch {
        if (err) { err.textContent = 'An unexpected error occurred.'; err.classList.remove('hidden'); }
    } finally {
        if (btn) { btn.disabled = false; btn.textContent = 'Save'; }
    }
};

window.adminDelete = async function(url, confirmMsg, onSuccess) {
    if (!confirm(confirmMsg)) return;
    try {
        const res  = await fetch(url, { method: 'POST' });
        const data = await res.json();
        if (data.success) onSuccess(data); else alert(data.message);
    } catch { alert('An unexpected error occurred.'); }
};

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