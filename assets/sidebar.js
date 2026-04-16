// ============================================================
// SIDEBAR
// ============================================================

const isMobile  = () => window.innerWidth <= 640;
const isTablet  = () => window.innerWidth > 640 && window.innerWidth <= 1024;
const isDesktop = () => window.innerWidth > 1024;

// ── DESKTOP COLLAPSE ─────────────────────────────────────────────────────────

function collapse() {
    const sidebar = document.getElementById('sidebar');
    const main    = document.getElementById('main-content');
    if (!sidebar) return;
    sidebar.classList.add('collapsed');
    if (main) main.classList.add('collapsed');
    localStorage.setItem('sidebar_collapsed', '1');
}

function expand() {
    const sidebar = document.getElementById('sidebar');
    const main    = document.getElementById('main-content');
    if (!sidebar) return;
    sidebar.classList.remove('collapsed');
    if (main) main.classList.remove('collapsed');
    localStorage.setItem('sidebar_collapsed', '0');
}

// ── TABLET / MOBILE OVERLAY ───────────────────────────────────────────────────

function openOverlay() {
    const sidebar  = document.getElementById('sidebar');
    const overlay  = document.getElementById('sidebar-overlay');
    if (!sidebar || !overlay) return;
    sidebar.classList.add('mobile-open');
    overlay.classList.add('visible');
}

function closeOverlay() {
    const sidebar  = document.getElementById('sidebar');
    const overlay  = document.getElementById('sidebar-overlay');
    if (!sidebar || !overlay) return;
    sidebar.classList.remove('mobile-open');
    overlay.classList.remove('visible');
}

// ── PUBLIC: TOGGLE BUTTON (collapse-btn in header) ───────────────────────────

window.toggleSidebar = function() {
    const sidebar = document.getElementById('sidebar');
    if (!sidebar) return;
    if (isMobile() || isTablet()) {
        closeOverlay();
        return;
    }
    // Desktop
    if (sidebar.classList.contains('collapsed')) {
        expand();
    } else {
        collapse();
    }
};

// ── PUBLIC: LOGO ICON CLICK ───────────────────────────────────────────────────

window.logoIconClick = function() {
    const sidebar = document.getElementById('sidebar');
    if (!sidebar) return;
    if (isMobile() || isTablet()) {
        openOverlay();
        return;
    }
    // Desktop: expand if collapsed
    if (sidebar.classList.contains('collapsed')) {
        expand();
    }
};

// ── PUBLIC: MOBILE HAMBURGER (in navbar) ─────────────────────────────────────

window.openMobileSidebar  = openOverlay;
window.closeMobileSidebar = closeOverlay;

// ── SIDEBAR LOGOUT ────────────────────────────────────────────────────────────

window.sidebarLogout = async function(logoutUrl, loginUrl) {
    try { await fetch(logoutUrl, { method: 'POST' }); } catch {}
    window.location.href = loginUrl;
};

// ── RESIZE ────────────────────────────────────────────────────────────────────

window.addEventListener('resize', () => {
    const sidebar = document.getElementById('sidebar');
    const main    = document.getElementById('main-content');
    const overlay = document.getElementById('sidebar-overlay');
    if (!sidebar) return;
    if (isMobile()) {
        sidebar.classList.remove('collapsed', 'mobile-open');
        if (overlay) overlay.classList.remove('visible');
        if (main) main.classList.remove('collapsed');
    } else if (isTablet()) {
        sidebar.classList.remove('collapsed');
        if (overlay) overlay.classList.remove('visible');
        if (main) main.classList.remove('collapsed');
    }
});

// ── COLLAPSIBLE GROUPS ────────────────────────────────────────────────────────

window.toggleGroup = function(id) {
    const btn     = document.querySelector(`.sidebar-group-btn[data-group="${id}"]`);
    const content = document.getElementById('sg-' + id);
    if (!btn || !content) return;

    const isOpen = content.classList.contains('open');
    if (isOpen) {
        content.classList.remove('open');
        btn.classList.remove('open');
        localStorage.setItem('sg_' + id, '0');
    } else {
        content.classList.add('open');
        btn.classList.add('open');
        localStorage.setItem('sg_' + id, '1');
    }
};

function initGroups() {
    document.querySelectorAll('.sidebar-group-btn[data-group]').forEach(btn => {
        const id      = btn.dataset.group;
        const content = document.getElementById('sg-' + id);
        if (!id || !content) return;

        const hasActive = !!content.querySelector('.sidebar-sublink.active, .sidebar-link.active');
        const stored    = localStorage.getItem('sg_' + id);

        if (hasActive || stored === '1') {
            content.classList.add('open');
            btn.classList.add('open');
        } else {
            content.classList.remove('open');
            btn.classList.remove('open');
        }

        // Mark group header when a child is active
        if (hasActive) {
            btn.classList.add('has-active');
        }
    });
}

// ── SIDEBAR NAV SCROLL — scroll active link into view ────────────────────────
//
// After the group expand transition completes (220 ms), getBoundingClientRect
// returns accurate positions. We then smooth-scroll #sidebar-nav to centre
// the active link. The delay is invisible on a hard refresh (the page is still
// settling) and feels like a gentle, intentional glide on Turbo navigation.

function restoreNavScroll() {
    const nav = document.getElementById('sidebar-nav');
    if (!nav) return;

    const active = nav.querySelector('.sidebar-link.active, .sidebar-sublink.active');
    if (!active) return;

    setTimeout(() => {
        const navRect  = nav.getBoundingClientRect();
        const linkRect = active.getBoundingClientRect();
        const target   = nav.scrollTop + (linkRect.top - navRect.top)
                         - nav.clientHeight / 2 + active.clientHeight / 2;
        nav.scrollTo({ top: Math.max(0, target), behavior: 'smooth' });
    }, 240);
}

// ── INIT — runs on every Turbo navigation AND the first page load ─────────────
//
// Turbo does not re-execute cached JS modules on navigation, so a bare init()
// call at the bottom of the file only runs once (the very first load).
// turbo:load fires on every visit — initial load and every subsequent navigation.

function init() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebar-overlay');
    const main    = document.getElementById('main-content');

    if (!sidebar) return;

    // Re-attach overlay click listener each navigation (element is re-rendered)
    if (overlay) {
        overlay.addEventListener('click', closeOverlay);
    }

    if (isMobile() || isTablet()) {
        sidebar.classList.remove('collapsed', 'mobile-open');
        if (overlay) overlay.classList.remove('visible');
        if (main) main.classList.remove('collapsed');
        initGroups();
        restoreNavScroll();
        return;
    }

    // Desktop: restore saved collapsed state from localStorage
    if (localStorage.getItem('sidebar_collapsed') === '1') {
        sidebar.classList.add('collapsed');
        if (main) main.classList.add('collapsed');
    } else {
        sidebar.classList.remove('collapsed');
        if (main) main.classList.remove('collapsed');
    }

    initGroups();
    restoreNavScroll();
}

document.addEventListener('turbo:load', init);

// ── BRANCH SWITCHER — outside-click to close ─────────────────────────────────

document.addEventListener('click', function (e) {
    const btn      = document.getElementById('branch-switcher-btn');
    const dropdown = document.getElementById('branch-switcher-dropdown');
    if (!dropdown) return;
    if (btn && btn.contains(e.target)) return; // handled by toggleDropdown
    if (dropdown.contains(e.target)) return;   // click inside the dropdown
    dropdown.classList.remove('open');
});