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
}

document.addEventListener('turbo:load', init);