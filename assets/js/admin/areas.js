// Admin — Areas page

const AREAS_BASE = document.querySelector('[data-areas-base]')?.dataset.areasBase ?? '';

document.getElementById('area-search')?.addEventListener('input', function () {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#area-table tbody tr[id]').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
});

// ── Form body ─────────────────────────────────────────────────────────────────
function areaFormBody(area = {}) {
    const name  = String(area.name        ?? '').replace(/"/g, '&quot;');
    const desc  = String(area.description ?? '').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    const trans = parseInt(area.is_transactional) ? 'checked' : '';
    return `<div class="space-y-4">
      <div>
        <label class="block text-xs font-semibold text-gray-700 mb-1">Name <span class="text-red-500">*</span></label>
        <input id="ar-name" type="text" maxlength="120" required value="${name}"
               placeholder="e.g. Kitchen Area, Bar Area, Poolside / Deck"
               class="w-full h-9 px-3 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400">
      </div>
      <div>
        <label class="block text-xs font-semibold text-gray-700 mb-1">Description <span class="text-gray-400 font-normal">(optional)</span></label>
        <textarea id="ar-desc" rows="2" maxlength="255"
                  placeholder="Brief description of this area"
                  class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm resize-none focus:outline-none focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400">${desc}</textarea>
      </div>
      <label class="flex items-center gap-3 p-3 border border-gray-200 rounded-lg bg-gray-50 hover:border-indigo-300 hover:bg-white cursor-pointer transition-colors">
        <input id="ar-trans" type="checkbox" ${trans} class="w-4 h-4 accent-indigo-600">
        <div>
          <div class="text-sm font-medium text-gray-800">Transactional area</div>
          <div class="text-xs text-gray-400">Customers make payments in this area</div>
        </div>
      </label>
      <div id="drawer-error" class="hidden text-xs text-red-600 bg-red-50 border border-red-200 rounded-lg px-3 py-2"></div>
    </div>`;
}

// ── Create ────────────────────────────────────────────────────────────────────
window.openCreate = function () {
    openDrawer(
        'Add Area',
        areaFormBody(),
        `<button onclick="closeDrawer()" class="px-4 h-9 border border-gray-200 text-sm text-gray-600 rounded-lg hover:bg-gray-50 transition-colors">Cancel</button>
         <button data-primary onclick="submitArea(null)" class="px-4 h-9 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition-colors">Create Area</button>`,
    );
    document.getElementById('ar-name')?.focus();
};

// ── Edit ──────────────────────────────────────────────────────────────────────
window.openEdit = function (area) {
    openDrawer(
        'Edit Area',
        areaFormBody(area),
        `<button onclick="closeDrawer()" class="px-4 h-9 border border-gray-200 text-sm text-gray-600 rounded-lg hover:bg-gray-50 transition-colors">Cancel</button>
         <button data-primary onclick="submitArea(${area.id})" class="px-4 h-9 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition-colors">Save Changes</button>`,
    );
    document.getElementById('ar-name')?.focus();
};

// ── Submit ────────────────────────────────────────────────────────────────────
window.submitArea = async function (id) {
    const params = new URLSearchParams({
        name:             document.getElementById('ar-name')?.value ?? '',
        description:      document.getElementById('ar-desc')?.value ?? '',
        is_transactional: document.getElementById('ar-trans')?.checked ? '1' : '0',
    });
    const url = id ? `${AREAS_BASE}/${id}/update` : `${AREAS_BASE}/create`;
    await adminFetch(url, params, () => turboReload());
};

// ── Toggle Status ─────────────────────────────────────────────────────────────
window.toggleStatus = function (id, currentStatus, name) {
    const label = currentStatus === 'active' ? 'Deactivate' : 'Activate';
    adminConfirm({
        title:       label + ' area',
        message:     `${label} "${name}"?`,
        confirmText: label,
        danger:      currentStatus === 'active',
        onConfirm: async () => {
            const res  = await fetch(`${AREAS_BASE}/${id}/toggle-status`, { method: 'POST' });
            const data = await res.json();
            if (data.success) turboReload();
            else adminAlert({ title: 'Could not update status', message: data.message, type: 'error' });
        },
    });
};

// ── Delete ────────────────────────────────────────────────────────────────────
window.deleteArea = function (id, name) {
    adminConfirm({
        title:       'Delete area',
        message:     `Delete "${name}"? Staff currently assigned to this area will be unassigned.`,
        confirmText: 'Delete',
        danger:      true,
        onConfirm: async () => {
            const res  = await fetch(`${AREAS_BASE}/${id}/delete`, { method: 'POST' });
            const data = await res.json();
            if (data.success) document.getElementById(`area-row-${id}`)?.remove();
            else adminAlert({ title: 'Could not delete area', message: data.message, type: 'error' });
        },
    });
};
