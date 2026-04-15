// Admin — Catalog page
// BASE URL is passed via data-catalog-base on the page container.

const BASE = document.querySelector('[data-catalog-base]')?.dataset.catalogBase ?? '';

// ── Type filter ───────────────────────────────────────────────────────────────
let activeType = '';

window.filterType = function (type) {
    activeType = type;
    document.querySelectorAll('.catalog-tab').forEach(t => {
        const on = (type === '' && t.id === 'tab-all') || t.id === 'tab-' + type;
        t.className = t.className
            .replace(/bg-indigo-600 text-white|bg-white border border-gray-200 text-gray-600 hover:bg-gray-50/g, '')
            .trim();
        t.className += on
            ? ' bg-indigo-600 text-white'
            : ' bg-white border border-gray-200 text-gray-600 hover:bg-gray-50';
    });
    applyFilters();
};

function applyFilters() {
    const q = (document.getElementById('catalog-search')?.value || '').toLowerCase();
    let count = 0;
    document.querySelectorAll('#catalog-table tbody tr[id]').forEach(row => {
        const typeOk = !activeType || row.dataset.type === activeType;
        const textOk = !q || row.textContent.toLowerCase().includes(q);
        row.style.display = (typeOk && textOk) ? '' : 'none';
        if (typeOk && textOk) count++;
    });
    const el = document.getElementById('catalog-count');
    if (el) el.textContent = count + ' item' + (count !== 1 ? 's' : '');
}

document.getElementById('catalog-search')?.addEventListener('input', applyFilters);

// ── Form body ─────────────────────────────────────────────────────────────────
const F = (id, type, label, val = '', extra = '') =>
    `<div>
       <label class="block text-xs font-semibold text-gray-700 mb-1">${label}</label>
       <input id="${id}" type="${type}" ${extra} value="${String(val ?? '').replace(/"/g, '&quot;')}"
              class="w-full h-9 px-3 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400">
     </div>`;

function catalogFormBody(item = {}) {
    return `<div class="space-y-4">
      ${F('ci-name', 'text', 'Name <span class="text-red-500">*</span>', item.name ?? '', 'maxlength="120" required placeholder="e.g. Full Body Massage, Craft Beer"')}
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-xs font-semibold text-gray-700 mb-1">Type <span class="text-red-500">*</span></label>
          <select id="ci-type" class="w-full h-9 px-3 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 bg-white">
            <option value="service" ${(item.type || 'service') === 'service' ? 'selected' : ''}>Service</option>
            <option value="product" ${item.type === 'product' ? 'selected' : ''}>Product</option>
          </select>
        </div>
        ${F('ci-price', 'number', 'Default Price (KES, optional)', item.price ?? '', 'min="0" step="0.01" placeholder="e.g. 2500"')}
      </div>
      ${F('ci-cat', 'text', 'Category <span class="text-gray-400 font-normal">(optional)</span>', item.category ?? '', 'maxlength="80" placeholder="e.g. Food, Beverages, Wellness"')}
      <div id="drawer-error" class="hidden text-xs text-red-600 bg-red-50 border border-red-200 rounded-lg px-3 py-2"></div>
    </div>`;
}

// ── Create ────────────────────────────────────────────────────────────────────
window.openCreate = function () {
    openDrawer(
        'Add Catalog Item',
        catalogFormBody(),
        `<button onclick="closeDrawer()" class="px-4 h-9 border border-gray-200 text-sm text-gray-600 rounded-lg hover:bg-gray-50 transition-colors">Cancel</button>
         <button data-primary onclick="submitCatalog(null)" class="px-4 h-9 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition-colors">Create Item</button>`,
    );
    document.getElementById('ci-name')?.focus();
};

// ── Edit ──────────────────────────────────────────────────────────────────────
window.openEdit = function (item) {
    openDrawer(
        'Edit Catalog Item',
        catalogFormBody(item),
        `<button onclick="closeDrawer()" class="px-4 h-9 border border-gray-200 text-sm text-gray-600 rounded-lg hover:bg-gray-50 transition-colors">Cancel</button>
         <button data-primary onclick="submitCatalog(${item.id})" class="px-4 h-9 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition-colors">Save Changes</button>`,
    );
    document.getElementById('ci-name')?.focus();
};

// ── Submit ────────────────────────────────────────────────────────────────────
window.submitCatalog = async function (id) {
    const params = new URLSearchParams({
        name:     document.getElementById('ci-name')?.value ?? '',
        type:     document.getElementById('ci-type')?.value ?? 'service',
        price:    document.getElementById('ci-price')?.value ?? '',
        category: document.getElementById('ci-cat')?.value ?? '',
    });
    const url = id ? `${BASE}/${id}/update` : `${BASE}/create`;
    await adminFetch(url, params, () => turboReload());
};

// ── Toggle Status ─────────────────────────────────────────────────────────────
window.toggleStatus = function (id, currentStatus, name) {
    const label = currentStatus === 'active' ? 'Deactivate' : 'Activate';
    adminConfirm({
        title:       label + ' item',
        message:     `${label} "${name}"?`,
        confirmText: label,
        danger:      currentStatus === 'active',
        onConfirm: async () => {
            const res  = await fetch(`${BASE}/${id}/toggle-status`, { method: 'POST' });
            const data = await res.json();
            if (data.success) turboReload();
            else adminAlert({ title: 'Could not update status', message: data.message, type: 'error' });
        },
    });
};

// ── Delete ────────────────────────────────────────────────────────────────────
window.deleteItem = function (id, name) {
    adminConfirm({
        title:       'Delete item',
        message:     `Delete "${name}"? This cannot be undone.`,
        confirmText: 'Delete',
        danger:      true,
        onConfirm: async () => {
            const res  = await fetch(`${BASE}/${id}/delete`, { method: 'POST' });
            const data = await res.json();
            if (data.success) document.getElementById(`catalog-row-${id}`)?.remove();
            else adminAlert({ title: 'Could not delete item', message: data.message, type: 'error' });
        },
    });
};
