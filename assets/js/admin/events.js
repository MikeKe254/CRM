// Admin — Events & Offers page

const EVENTS_BASE = document.querySelector('[data-events-base]')?.dataset.eventsBase ?? '';

// ── Page-level tab filter (All / Events / Offers) ─────────────────────────────
let activeEntryType = '';

window.filterEntryType = function (type) {
    activeEntryType = type;
    document.querySelectorAll('.ev-entry-tab').forEach(btn => {
        const on = btn.dataset.tab === type;
        btn.style.cssText = on
            ? 'background:#4f46e5;color:#fff;border-color:#4f46e5;'
            : 'background:#fff;color:#4b5563;border-color:#e5e7eb;';
    });
    let count = 0;
    document.querySelectorAll('#events-table tbody tr[id]').forEach(row => {
        const q = (document.getElementById('events-search')?.value || '').toLowerCase();
        const typeOk = !type || row.dataset.entryType === type;
        const textOk = !q    || row.textContent.toLowerCase().includes(q);
        row.style.display = (typeOk && textOk) ? '' : 'none';
        if (typeOk && textOk) count++;
    });
    const el = document.getElementById('events-count');
    if (el) el.textContent = count + ' item' + (count !== 1 ? 's' : '');
};

document.getElementById('events-search')?.addEventListener('input', () => filterEntryType(activeEntryType));

// ── Helpers ───────────────────────────────────────────────────────────────────
const esc = s => (s ?? '').toString()
    .replace(/&/g, '&amp;').replace(/"/g, '&quot;')
    .replace(/</g, '&lt;').replace(/>/g, '&gt;');

const DOW_NAMES = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

function dowPills(cls) {
    return DOW_NAMES.map((d, i) =>
        `<button type="button" data-dow="${i}" onclick="evToggleDow(this)"
                 style="width:40px;height:32px;border-radius:8px;border:1px solid #e5e7eb;background:#fff;color:#374151;font-size:11px;font-weight:600;cursor:pointer;transition:all .15s;"
                 class="${cls}-btn">${d}</button>`,
    ).join('');
}

const DAY_OPTIONS = Array.from({ length: 31 }, (_, i) => {
    const n = i + 1;
    const sfx = [, 'st', 'nd', 'rd'][n] ?? 'th';
    return `<option value="${n}">${n}${sfx}</option>`;
}).join('');

// ── Build form HTML ───────────────────────────────────────────────────────────
function buildFormHtml() {
    return `
    <div class="space-y-5">

      <!-- Entry type toggle -->
      <div>
        <label class="block text-xs font-semibold text-gray-700 mb-2">Type</label>
        <div class="flex gap-2" id="ev-entry-type-btns">
          <button type="button" data-etype="event"  onclick="evSetEntryType('event')"
                  class="ev-etype-btn flex-1 h-9 rounded-lg border text-sm font-semibold transition-colors"
                  style="background:#4f46e5;color:#fff;border-color:#4f46e5;">Event</button>
          <button type="button" data-etype="offer"  onclick="evSetEntryType('offer')"
                  class="ev-etype-btn flex-1 h-9 rounded-lg border text-sm font-semibold transition-colors"
                  style="background:#fff;color:#4b5563;border-color:#e5e7eb;">Offer</button>
        </div>
        <input type="hidden" id="ev-entry-type" value="event">
      </div>

      <!-- Name -->
      <div>
        <label class="block text-xs font-semibold text-gray-700 mb-1" id="ev-name-label">Event Name <span class="text-red-500">*</span></label>
        <input id="ev-name" type="text" maxlength="120" required
               class="w-full h-9 px-3 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400">
      </div>

      <!-- Description -->
      <div>
        <label class="block text-xs font-semibold text-gray-700 mb-1">Description <span class="text-gray-400 font-normal">(optional)</span></label>
        <textarea id="ev-desc" rows="2" maxlength="500"
                  class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm resize-none focus:outline-none focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400"></textarea>
      </div>

      <!-- Offer discount fields (hidden unless type=offer) -->
      <div id="ev-offer-section" class="hidden space-y-3 p-3 bg-amber-50 border border-amber-200 rounded-lg">
        <p class="text-xs font-semibold text-amber-800">Discount details</p>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-xs font-semibold text-gray-700 mb-1">Discount type <span class="text-red-500">*</span></label>
            <div class="flex gap-1.5">
              <button type="button" data-dtype="percent" onclick="evSetDiscountType('percent')"
                      class="ev-dtype-btn flex-1 h-8 rounded-lg border text-xs font-semibold transition-colors"
                      style="background:#4f46e5;color:#fff;border-color:#4f46e5;">% Off</button>
              <button type="button" data-dtype="fixed" onclick="evSetDiscountType('fixed')"
                      class="ev-dtype-btn flex-1 h-8 rounded-lg border text-xs font-semibold transition-colors"
                      style="background:#fff;color:#4b5563;border-color:#e5e7eb;">KES Off</button>
            </div>
            <input type="hidden" id="ev-discount-type" value="percent">
          </div>
          <div>
            <label class="block text-xs font-semibold text-gray-700 mb-1" id="ev-disc-val-label">Amount (%) <span class="text-red-500">*</span></label>
            <input id="ev-discount-value" type="number" min="0" step="0.01" placeholder="e.g. 20"
                   class="w-full h-9 px-3 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400">
          </div>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-700 mb-1">Applies to</label>
          <select id="ev-applies-to" class="w-full h-9 px-3 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 bg-white">
            <option value="all">All items</option>
            <option value="category">Specific category</option>
            <option value="item">Specific item</option>
          </select>
        </div>
      </div>

      <!-- Recurrence -->
      <div>
        <label class="block text-xs font-semibold text-gray-700 mb-2">Recurrence</label>
        <div class="grid grid-cols-5 gap-1.5" id="ev-rec-tabs">
          ${[['none','One-off'],['daily','Daily'],['weekly','Weekly'],['biweekly','Bi-weekly'],['monthly','Monthly']].map(([v, l]) =>
              `<button type="button" data-rec="${v}" onclick="evSetRec('${v}')"
                       class="ev-rec-tab h-9 rounded-lg border text-xs font-semibold transition-colors bg-white text-gray-600 border-gray-200 hover:bg-gray-50">${l}</button>`
          ).join('')}
        </div>
        <input type="hidden" id="ev-rec-type" value="none">
        <input type="hidden" id="ev-monthly-mode-input" value="date">
      </div>

      <!-- ONE-OFF fields -->
      <div id="ev-oneoff" class="space-y-3">
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-xs font-semibold text-gray-700 mb-1">Start date/time <span class="text-gray-400 font-normal">(optional)</span></label>
            <input id="ev-starts-at" type="datetime-local"
                   class="w-full h-9 px-3 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400">
          </div>
          <div>
            <label class="block text-xs font-semibold text-gray-700 mb-1">End date/time <span class="text-gray-400 font-normal">(optional)</span></label>
            <input id="ev-ends-at" type="datetime-local"
                   class="w-full h-9 px-3 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400">
          </div>
        </div>
        <p class="text-xs text-gray-400">Overnight supported — e.g. start 8pm, end 3am next day. Leave both blank for a manually-controlled open event.</p>
      </div>

      <!-- RECURRING fields -->
      <div id="ev-recurring" class="hidden space-y-4">

        <!-- DOW row (weekly / biweekly / monthly-weekday) -->
        <div id="ev-dow-row" class="hidden">
          <label class="block text-xs font-semibold text-gray-700 mb-2">Active days <span class="text-red-500">*</span></label>
          <div class="flex gap-1.5 flex-wrap">${dowPills('ev-dow-cb')}</div>
        </div>

        <!-- Monthly controls -->
        <div id="ev-monthly-row" class="hidden space-y-3">
          <div>
            <label class="block text-xs font-semibold text-gray-700 mb-2">Monthly mode</label>
            <div class="flex gap-2">
              <button type="button" id="ev-mt-date" data-mode="date" onclick="evSetMonthlyMode('date')"
                      class="ev-mt-btn flex-1 h-8 rounded-lg border text-xs font-semibold transition-colors"
                      style="background:#4f46e5;color:#fff;border-color:#4f46e5;">By date</button>
              <button type="button" id="ev-mt-wd" data-mode="weekday" onclick="evSetMonthlyMode('weekday')"
                      class="ev-mt-btn flex-1 h-8 rounded-lg border text-xs font-semibold transition-colors"
                      style="background:#fff;color:#4b5563;border-color:#e5e7eb;">By weekday</button>
            </div>
          </div>
          <div id="ev-monthly-date-fields">
            <label class="block text-xs font-semibold text-gray-700 mb-1">Day of month <span class="text-red-500">*</span></label>
            <select id="ev-monthly-day-sel"
                    class="w-32 h-9 px-3 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 bg-white">
              <option value="">— select —</option>${DAY_OPTIONS}
            </select>
          </div>
          <div id="ev-monthly-wd-fields" class="hidden space-y-2">
            <label class="block text-xs font-semibold text-gray-700 mb-1">Which occurrence <span class="text-red-500">*</span></label>
            <div class="flex gap-1.5">
              ${[['1','1st'],['2','2nd'],['3','3rd'],['4','4th'],['5','Last']].map(([v, l]) =>
                  `<button type="button" data-n="${v}" onclick="evSetMonthlyN('${v}')"
                           class="ev-mn-btn flex-1 h-8 rounded-lg border text-xs font-semibold transition-colors"
                           style="background:#fff;color:#4b5563;border-color:#e5e7eb;">${l}</button>`
              ).join('')}
            </div>
            <input type="hidden" id="ev-monthly-n-val" value="">
          </div>
        </div>

        <!-- Time window -->
        <div>
          <label class="block text-xs font-semibold text-gray-700 mb-2">Time window <span class="text-red-500">*</span></label>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs text-gray-500 mb-1">Opens at</label>
              <input id="ev-time-start" type="time"
                     class="w-full h-9 px-3 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400">
            </div>
            <div>
              <label class="block text-xs text-gray-500 mb-1">Closes at</label>
              <input id="ev-time-end" type="time"
                     class="w-full h-9 px-3 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400">
            </div>
          </div>
          <p class="text-xs text-gray-400 mt-1">Overnight is fine — e.g. 20:00 → 03:00. Handled automatically.</p>
        </div>

        <!-- Active date range -->
        <div>
          <label class="block text-xs font-semibold text-gray-700 mb-2">Active date range <span class="text-gray-400 font-normal">(optional)</span></label>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs text-gray-500 mb-1">From</label>
              <input id="ev-valid-from" type="date"
                     class="w-full h-9 px-3 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400">
            </div>
            <div>
              <label class="block text-xs text-gray-500 mb-1">Until (blank = forever)</label>
              <input id="ev-valid-until" type="date"
                     class="w-full h-9 px-3 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400">
            </div>
          </div>
          <p class="text-xs text-gray-400 mt-1">Leave blank to recur indefinitely. All transactions from every occurrence tag back to this one record.</p>
        </div>

      </div>

      <div id="drawer-error" class="hidden text-xs text-red-600 bg-red-50 border border-red-200 rounded-lg px-3 py-2"></div>
    </div>`;
}

// ── Entry type toggle ─────────────────────────────────────────────────────────
window.evSetEntryType = function (type) {
    document.getElementById('ev-entry-type').value = type;
    document.querySelectorAll('.ev-etype-btn').forEach(b => {
        const on = b.dataset.etype === type;
        b.style.cssText = on
            ? 'background:#4f46e5;color:#fff;border-color:#4f46e5;'
            : 'background:#fff;color:#4b5563;border-color:#e5e7eb;';
    });
    document.getElementById('ev-offer-section').classList.toggle('hidden', type !== 'offer');
    const label = document.getElementById('ev-name-label');
    if (label) label.innerHTML = (type === 'offer' ? 'Offer Name' : 'Event Name') + ' <span class="text-red-500">*</span>';
};

// ── Discount type toggle ──────────────────────────────────────────────────────
window.evSetDiscountType = function (dtype) {
    document.getElementById('ev-discount-type').value = dtype;
    document.querySelectorAll('.ev-dtype-btn').forEach(b => {
        const on = b.dataset.dtype === dtype;
        b.style.cssText = on
            ? 'background:#4f46e5;color:#fff;border-color:#4f46e5;'
            : 'background:#fff;color:#4b5563;border-color:#e5e7eb;';
    });
    const lbl = document.getElementById('ev-disc-val-label');
    if (lbl) lbl.innerHTML = (dtype === 'percent' ? 'Amount (%)' : 'Amount (KES)') + ' <span class="text-red-500">*</span>';
};

// ── DOW toggle ────────────────────────────────────────────────────────────────
window.evToggleDow = function (btn) {
    const on = btn.dataset.active === '1';
    btn.dataset.active    = on ? '0' : '1';
    btn.style.background  = on ? '#fff'    : '#4f46e5';
    btn.style.color       = on ? '#374151' : '#fff';
    btn.style.borderColor = on ? '#e5e7eb' : '#4f46e5';
};

function evGetActiveDows(cls) {
    const days = [];
    document.querySelectorAll('.' + cls + '-btn').forEach(b => {
        if (b.dataset.active === '1') days.push(parseInt(b.dataset.dow));
    });
    return days;
}

// ── Recurrence tab ────────────────────────────────────────────────────────────
window.evSetRec = function (type) {
    document.getElementById('ev-rec-type').value = type;
    document.querySelectorAll('.ev-rec-tab').forEach(b => {
        const on = b.dataset.rec === type;
        b.style.cssText = on
            ? 'background:#4f46e5;color:#fff;border-color:#4f46e5;'
            : 'background:#fff;color:#4b5563;border-color:#e5e7eb;';
    });
    const isRec = type !== 'none';
    document.getElementById('ev-oneoff').classList.toggle('hidden', isRec);
    document.getElementById('ev-recurring').classList.toggle('hidden', !isRec);
    if (isRec) {
        const monthlyMode = document.getElementById('ev-monthly-mode-input')?.value ?? 'date';
        const showDow     = ['weekly', 'biweekly'].includes(type) || (type === 'monthly' && monthlyMode === 'weekday');
        document.getElementById('ev-dow-row').classList.toggle('hidden', !showDow);
        document.getElementById('ev-monthly-row').classList.toggle('hidden', type !== 'monthly');
    }
};

window.evSetMonthlyMode = function (mode) {
    document.getElementById('ev-monthly-mode-input').value = mode;
    document.getElementById('ev-mt-date').style.cssText = mode === 'date'
        ? 'background:#4f46e5;color:#fff;border-color:#4f46e5;'
        : 'background:#fff;color:#4b5563;border-color:#e5e7eb;';
    document.getElementById('ev-mt-wd').style.cssText = mode === 'weekday'
        ? 'background:#4f46e5;color:#fff;border-color:#4f46e5;'
        : 'background:#fff;color:#4b5563;border-color:#e5e7eb;';
    document.getElementById('ev-monthly-date-fields').classList.toggle('hidden', mode !== 'date');
    document.getElementById('ev-monthly-wd-fields').classList.toggle('hidden', mode !== 'weekday');
    document.getElementById('ev-dow-row').classList.toggle('hidden', mode !== 'weekday');
};

window.evSetMonthlyN = function (n) {
    document.getElementById('ev-monthly-n-val').value = n;
    document.querySelectorAll('.ev-mn-btn').forEach(b => {
        const on = b.dataset.n === n;
        b.style.cssText = on
            ? 'background:#4f46e5;color:#fff;border-color:#4f46e5;'
            : 'background:#fff;color:#4b5563;border-color:#e5e7eb;';
    });
};

// ── Open Create ───────────────────────────────────────────────────────────────
window.openCreate = function (defaultType = 'event') {
    openDrawer(
        defaultType === 'offer' ? 'Add Offer' : 'Add Event',
        buildFormHtml(),
        `<button onclick="closeDrawer()" class="px-4 h-9 border border-gray-200 text-sm text-gray-600 rounded-lg hover:bg-gray-50 transition-colors">Cancel</button>
         <button data-primary onclick="submitEventForm('create', null)" class="px-4 h-9 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition-colors">Save</button>`,
    );
    evSetRec('none');
    evSetEntryType(defaultType);
    document.getElementById('ev-name')?.focus();
};

// ── Open Edit ─────────────────────────────────────────────────────────────────
window.openEditEvent = function (ev) {
    const entryType = ev.entry_type ?? 'event';
    openDrawer(
        entryType === 'offer' ? 'Edit Offer' : 'Edit Event',
        buildFormHtml(),
        `<button onclick="closeDrawer()" class="px-4 h-9 border border-gray-200 text-sm text-gray-600 rounded-lg hover:bg-gray-50 transition-colors">Cancel</button>
         <button data-primary onclick="submitEventForm('edit', ${ev.id})" class="px-4 h-9 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition-colors">Save Changes</button>`,
    );

    evSetEntryType(entryType);

    document.getElementById('ev-name').value        = ev.name        ?? '';
    document.getElementById('ev-desc').value        = ev.description ?? '';
    document.getElementById('ev-time-start').value  = ev.recurrence_time_start ? ev.recurrence_time_start.substring(0, 5) : '';
    document.getElementById('ev-time-end').value    = ev.recurrence_time_end   ? ev.recurrence_time_end.substring(0, 5)   : '';
    document.getElementById('ev-valid-from').value  = ev.recurrence_valid_from  ?? '';
    document.getElementById('ev-valid-until').value = ev.recurrence_valid_until ?? '';

    // Offer fields
    if (entryType === 'offer') {
        const dtype = ev.offer_discount_type ?? 'percent';
        evSetDiscountType(dtype);
        document.getElementById('ev-discount-value').value = ev.offer_discount_value ?? '';
        const appEl = document.getElementById('ev-applies-to');
        if (appEl) appEl.value = ev.offer_applies_to ?? 'all';
    }

    const type = ev.recurrence_type || 'none';
    evSetRec(type);

    if (type === 'none') {
        document.getElementById('ev-starts-at').value = ev.starts_at ? ev.starts_at.substring(0, 16).replace(' ', 'T') : '';
        document.getElementById('ev-ends-at').value   = ev.ends_at   ? ev.ends_at.substring(0, 16).replace(' ', 'T')   : '';
    }

    // Restore DOW buttons
    const activeDays = JSON.parse(ev.recurrence_days || '[]');
    document.querySelectorAll('.ev-dow-cb-btn').forEach(btn => {
        const on = activeDays.includes(parseInt(btn.dataset.dow));
        btn.dataset.active    = on ? '1' : '0';
        btn.style.background  = on ? '#4f46e5' : '#fff';
        btn.style.color       = on ? '#fff'    : '#374151';
        btn.style.borderColor = on ? '#4f46e5' : '#e5e7eb';
    });

    // Restore monthly fields
    if (type === 'monthly') {
        const monthlyDay = parseInt(ev.recurrence_monthly_day) || 0;
        if (activeDays.length > 0) {
            evSetMonthlyMode('weekday');
            if (monthlyDay >= 1 && monthlyDay <= 5) evSetMonthlyN(String(monthlyDay));
        } else {
            evSetMonthlyMode('date');
            const sel = document.getElementById('ev-monthly-day-sel');
            if (sel && monthlyDay > 0) sel.value = monthlyDay;
        }
    }
};

// ── Submit ────────────────────────────────────────────────────────────────────
window.submitEventForm = async function (mode, id) {
    const name = document.getElementById('ev-name')?.value?.trim();
    if (!name) {
        const e = document.getElementById('drawer-error');
        if (e) { e.textContent = 'Name is required.'; e.classList.remove('hidden'); }
        return;
    }

    const entryType = document.getElementById('ev-entry-type')?.value || 'event';
    const type      = document.getElementById('ev-rec-type')?.value   || 'none';
    const params    = new URLSearchParams();

    params.append('entry_type',       entryType);
    params.append('name',             name);
    params.append('description',      document.getElementById('ev-desc')?.value ?? '');
    params.append('recurrence_type',  type);

    // Offer-specific
    if (entryType === 'offer') {
        params.append('offer_discount_type',  document.getElementById('ev-discount-type')?.value  ?? 'percent');
        params.append('offer_discount_value', document.getElementById('ev-discount-value')?.value ?? '');
        params.append('offer_applies_to',     document.getElementById('ev-applies-to')?.value     ?? 'all');
    }

    // Schedule fields
    if (type === 'none') {
        const sa = document.getElementById('ev-starts-at')?.value;
        const ea = document.getElementById('ev-ends-at')?.value;
        if (sa) params.append('starts_at', sa.replace('T', ' ') + ':00');
        if (ea) params.append('ends_at',   ea.replace('T', ' ') + ':00');
    } else {
        params.append('recurrence_time_start',  document.getElementById('ev-time-start')?.value  ?? '');
        params.append('recurrence_time_end',    document.getElementById('ev-time-end')?.value    ?? '');
        params.append('recurrence_valid_from',  document.getElementById('ev-valid-from')?.value  ?? '');
        params.append('recurrence_valid_until', document.getElementById('ev-valid-until')?.value ?? '');
    }

    if (['weekly', 'biweekly'].includes(type)) {
        evGetActiveDows('ev-dow-cb').forEach(d => params.append('recurrence_days[]', d));
    }

    if (type === 'monthly') {
        const monthlyMode = document.getElementById('ev-monthly-mode-input')?.value || 'date';
        params.append('monthly_mode', monthlyMode);
        if (monthlyMode === 'weekday') {
            evGetActiveDows('ev-dow-cb').forEach(d => params.append('recurrence_days[]', d));
            params.append('recurrence_monthly_day', document.getElementById('ev-monthly-n-val')?.value ?? '');
        } else {
            params.append('recurrence_monthly_day', document.getElementById('ev-monthly-day-sel')?.value ?? '');
        }
    }

    const url = mode === 'create' ? `${EVENTS_BASE}/create` : `${EVENTS_BASE}/${id}/update`;
    await adminFetch(url, params, () => turboReload());
};

// ── Set Status ────────────────────────────────────────────────────────────────
window.setStatus = function (id, newStatus, name) {
    const cancelling = newStatus === 'cancelled';
    adminConfirm({
        title:       cancelling ? 'Cancel' : 'Restore',
        message:     cancelling
            ? `Cancel "${name}"? It will stop appearing at checkout immediately.`
            : `Restore "${name}"? It will appear at checkout again during its scheduled window.`,
        confirmText: cancelling ? 'Cancel' : 'Restore',
        danger:      cancelling,
        onConfirm: async () => {
            const body = new URLSearchParams({ status: newStatus });
            const res  = await fetch(`${EVENTS_BASE}/${id}/set-status`, { method: 'POST', body });
            const data = await res.json();
            if (data.success) turboReload();
            else adminAlert({ title: 'Could not update status', message: data.message, type: 'error' });
        },
    });
};

// ── Delete ────────────────────────────────────────────────────────────────────
window.deleteEvent = function (id, name) {
    adminConfirm({
        title:       'Delete',
        message:     `Delete "${name}"? Historical transaction tags are preserved but the record will be gone.`,
        confirmText: 'Delete',
        danger:      true,
        onConfirm: async () => {
            const res  = await fetch(`${EVENTS_BASE}/${id}/delete`, { method: 'POST' });
            const data = await res.json();
            if (data.success) document.getElementById(`event-row-${id}`)?.remove();
            else adminAlert({ title: 'Could not delete', message: data.message, type: 'error' });
        },
    });
};
