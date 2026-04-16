// Admin — Departments page

const deptsBase = () => document.querySelector('[data-depts-base]')?.dataset.deptsBase ?? '';

document.getElementById('dept-search')?.addEventListener('input', function () {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#dept-table tbody tr[id]').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
});

// ── Form body ─────────────────────────────────────────────────────────────────
function deptFormBody(dept = {}) {
    const name = String(dept.name        ?? '').replace(/"/g, '&quot;');
    const desc = String(dept.description ?? '').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    return `<div class="space-y-4">
      <div>
        <label class="block text-xs font-semibold text-gray-700 mb-1">Name <span class="text-red-500">*</span></label>
        <input id="dp-name" type="text" maxlength="120" required value="${name}"
               placeholder="e.g. Kitchen, Security, Finance"
               class="w-full h-9 px-3 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400">
      </div>
      <div>
        <label class="block text-xs font-semibold text-gray-700 mb-1">Description <span class="text-gray-400 font-normal">(optional)</span></label>
        <textarea id="dp-desc" rows="2" maxlength="255"
                  placeholder="Brief description of this department's function"
                  class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm resize-none focus:outline-none focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400">${desc}</textarea>
      </div>
      <div id="drawer-error" class="hidden text-xs text-red-600 bg-red-50 border border-red-200 rounded-lg px-3 py-2"></div>
    </div>`;
}

// ── Create ────────────────────────────────────────────────────────────────────
window.openCreateDept = function () {
    openDrawer(
        'Add Department',
        deptFormBody(),
        `<button onclick="closeDrawer()" class="px-4 h-9 border border-gray-200 text-sm text-gray-600 rounded-lg hover:bg-gray-50 transition-colors">Cancel</button>
         <button data-primary onclick="submitDept(null)" class="px-4 h-9 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition-colors">Create Department</button>`,
    );
    document.getElementById('dp-name')?.focus();
};

// ── Edit ──────────────────────────────────────────────────────────────────────
window.openEditDept = function (dept) {
    openDrawer(
        'Edit Department',
        deptFormBody(dept),
        `<button onclick="closeDrawer()" class="px-4 h-9 border border-gray-200 text-sm text-gray-600 rounded-lg hover:bg-gray-50 transition-colors">Cancel</button>
         <button data-primary onclick="submitDept(${dept.id})" class="px-4 h-9 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition-colors">Save Changes</button>`,
    );
    document.getElementById('dp-name')?.focus();
};

// ── Submit ────────────────────────────────────────────────────────────────────
window.submitDept = async function (id) {
    const params = new URLSearchParams({
        name:        document.getElementById('dp-name')?.value ?? '',
        description: document.getElementById('dp-desc')?.value ?? '',
    });
    const url = id ? `${deptsBase()}/${id}/update` : `${deptsBase()}/create`;
    await adminFetch(url, params, () => turboReload());
};

// ── Toggle Status ─────────────────────────────────────────────────────────────
window.toggleDeptStatus = function (id, currentStatus, name) {
    const label = currentStatus === 'active' ? 'Deactivate' : 'Activate';
    adminConfirm({
        title:       label + ' department',
        message:     `${label} "${name}"?`,
        confirmText: label,
        danger:      currentStatus === 'active',
        onConfirm: async () => {
            const res  = await fetch(`${deptsBase()}/${id}/toggle-status`, { method: 'POST' });
            const data = await res.json();
            if (data.success) turboReload();
            else adminAlert({ title: 'Could not update status', message: data.message, type: 'error' });
        },
    });
};

// ── Delete ────────────────────────────────────────────────────────────────────
window.deleteDept = function (id, name) {
    adminConfirm({
        title:       'Delete department',
        message:     `Delete "${name}"? Staff currently assigned to this department will be unassigned.`,
        confirmText: 'Delete',
        danger:      true,
        onConfirm: async () => {
            const res  = await fetch(`${deptsBase()}/${id}/delete`, { method: 'POST' });
            const data = await res.json();
            if (data.success) document.getElementById(`dept-row-${id}`)?.remove();
            else adminAlert({ title: 'Could not delete department', message: data.message, type: 'error' });
        },
    });
};
