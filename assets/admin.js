// Admin panel JS

// ── TURBO-SAFE RELOAD ─────────────────────────────────────────────────────────
// Use instead of location.reload() everywhere — keeps navigation inside Turbo.
window.turboReload = function() {
    Turbo.visit(window.location.pathname, { action: 'replace' });
};

// ── DRAWER ────────────────────────────────────────────────────────────────────

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

// Admin modal helpers
let adminModalConfirmHandler = null;

function adminModalOpen() {
    const backdrop = document.getElementById('admin-modal-backdrop');
    const card = document.getElementById('admin-modal-card');
    if (!backdrop || !card) return;

    backdrop.classList.remove('hidden');
    backdrop.classList.add('flex');
    document.body.style.overflow = 'hidden';

    requestAnimationFrame(() => {
        card.classList.remove('scale-95', 'opacity-0');
        card.classList.add('scale-100', 'opacity-100');
    });
}

window.closeAdminModal = function() {
    const backdrop = document.getElementById('admin-modal-backdrop');
    const card = document.getElementById('admin-modal-card');
    if (!backdrop || !card) return;

    card.classList.remove('scale-100', 'opacity-100');
    card.classList.add('scale-95', 'opacity-0');
    setTimeout(() => {
        backdrop.classList.add('hidden');
        backdrop.classList.remove('flex');
        document.body.style.overflow = '';
        adminModalConfirmHandler = null;
    }, 180);
};

window.adminConfirm = function({
    title = 'Are you sure?',
    message = '',
    confirmText = 'Confirm',
    cancelText = 'Cancel',
    danger = true,
    onConfirm = null,
}) {
    const iconWrap = document.getElementById('admin-modal-icon-wrap');
    const icon = document.getElementById('admin-modal-icon');
    const titleEl = document.getElementById('admin-modal-title');
    const messageEl = document.getElementById('admin-modal-message');
    const cancelBtn = document.getElementById('admin-modal-cancel');
    const confirmBtn = document.getElementById('admin-modal-confirm');

    if (!iconWrap || !icon || !titleEl || !messageEl || !cancelBtn || !confirmBtn) return;

    adminModalConfirmHandler = onConfirm;
    iconWrap.className = `mx-auto flex h-12 w-12 items-center justify-center rounded-full ${danger ? 'bg-red-50' : 'bg-indigo-50'}`;
    icon.innerHTML = danger
        ? '<svg width="22" height="22" fill="none" stroke="#dc2626" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>'
        : '<svg width="22" height="22" fill="none" stroke="#4f46e5" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path stroke-linecap="round" d="M12 8v4m0 4h.01"/></svg>';

    titleEl.textContent = title;
    messageEl.textContent = message;
    cancelBtn.textContent = cancelText;
    cancelBtn.classList.remove('hidden');
    confirmBtn.textContent = confirmText;
    confirmBtn.className = `flex-1 rounded-xl px-4 py-2.5 text-sm font-semibold text-white transition ${danger ? 'bg-red-600 hover:bg-red-700' : 'bg-indigo-600 hover:bg-indigo-700'}`;
    confirmBtn.onclick = () => {
        const handler = adminModalConfirmHandler;
        window.closeAdminModal();
        if (typeof handler === 'function') handler();
    };
    cancelBtn.onclick = () => window.closeAdminModal();

    adminModalOpen();
};

window.adminAlert = function({
    title = 'Notice',
    message = '',
    type = 'error',
}) {
    const palette = {
        error: {
            wrap: 'bg-red-50',
            button: 'bg-red-600 hover:bg-red-700',
            icon: '<svg width="22" height="22" fill="none" stroke="#dc2626" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path stroke-linecap="round" d="M12 8v4m0 4h.01"/></svg>',
        },
        success: {
            wrap: 'bg-emerald-50',
            button: 'bg-emerald-600 hover:bg-emerald-700',
            icon: '<svg width="22" height="22" fill="none" stroke="#059669" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4"/></svg>',
        },
        info: {
            wrap: 'bg-indigo-50',
            button: 'bg-indigo-600 hover:bg-indigo-700',
            icon: '<svg width="22" height="22" fill="none" stroke="#4f46e5" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path stroke-linecap="round" d="M12 8v4m0 4h.01"/></svg>',
        },
    };

    const current = palette[type] || palette.error;
    const iconWrap = document.getElementById('admin-modal-icon-wrap');
    const icon = document.getElementById('admin-modal-icon');
    const titleEl = document.getElementById('admin-modal-title');
    const messageEl = document.getElementById('admin-modal-message');
    const cancelBtn = document.getElementById('admin-modal-cancel');
    const confirmBtn = document.getElementById('admin-modal-confirm');

    if (!iconWrap || !icon || !titleEl || !messageEl || !cancelBtn || !confirmBtn) return;

    iconWrap.className = `mx-auto flex h-12 w-12 items-center justify-center rounded-full ${current.wrap}`;
    icon.innerHTML = current.icon;
    titleEl.textContent = title;
    messageEl.textContent = message;
    cancelBtn.classList.add('hidden');
    confirmBtn.textContent = 'OK';
    confirmBtn.className = `flex-1 rounded-xl px-4 py-2.5 text-sm font-semibold text-white transition ${current.button}`;
    confirmBtn.onclick = () => window.closeAdminModal();

    adminModalOpen();
};

document.addEventListener('click', e => {
    const backdrop = document.getElementById('admin-modal-backdrop');
    if (backdrop && e.target === backdrop) {
        window.closeAdminModal();
    }
});

// ── FETCH HELPER ──────────────────────────────────────────────────────────────

window.adminFetch = async function(url, body, onSuccess) {
    const btn = document.querySelector('#admin-drawer-footer button[data-primary]');
    const err = document.getElementById('drawer-error');
    if (btn) { btn.disabled = true; btn.textContent = 'Saving…'; }
    if (err) { err.textContent = ''; err.classList.add('hidden'); }
    try {
        const res  = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body instanceof URLSearchParams ? body.toString() : new URLSearchParams(body).toString(),
        });
        const data = await res.json();
        if (data.success) { window.closeDrawer(); onSuccess(data); }
        else if (err) { err.textContent = data.message; err.classList.remove('hidden'); }
    } catch {
        if (err) { err.textContent = 'An unexpected error occurred.'; err.classList.remove('hidden'); }
    } finally {
        if (btn) { btn.disabled = false; btn.textContent = 'Save'; }
    }
};

// ── DELETE HELPER ─────────────────────────────────────────────────────────────

window.adminDelete = async function(url, confirmMsg, onSuccess) {
    window.adminConfirm({
        title: 'Confirm action',
        message: confirmMsg,
        confirmText: 'Continue',
        danger: true,
        onConfirm: async () => {
            try {
                const res  = await fetch(url, { method: 'POST' });
                const data = await res.json();
                if (data.success) onSuccess(data);
                else window.adminAlert({ title: 'Could not complete action', message: data.message, type: 'error' });
            } catch {
                window.adminAlert({ title: 'Unexpected error', message: 'An unexpected error occurred.', type: 'error' });
            }
        },
    });
};

// ── LIVE FILTER ───────────────────────────────────────────────────────────────

window.liveFilter = function(inputId, tableId) {
    const input = document.getElementById(inputId);
    if (!input) return;
    input.oninput = function() {
        const q = this.value.toLowerCase();
        document.querySelectorAll('#' + tableId + ' tbody tr').forEach(row => {
            row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
        });
    };
};

document.addEventListener('keydown', e => {
    const backdrop = document.getElementById('admin-modal-backdrop');
    const isOpen = backdrop && !backdrop.classList.contains('hidden');
    if (e.key === 'Escape' && isOpen) {
        window.closeAdminModal();
    }
}, true);
