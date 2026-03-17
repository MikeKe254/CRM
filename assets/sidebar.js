// ============================================================
// SIDEBAR
// ============================================================

const sidebar     = document.getElementById('sidebar');
const overlay     = document.getElementById('sidebar-overlay');
const main        = document.getElementById('main-content');

if (!sidebar || !overlay) throw new Error('Sidebar elements missing');

const isMobile  = () => window.innerWidth <= 640;
const isTablet  = () => window.innerWidth > 640 && window.innerWidth <= 1024;
const isDesktop = () => window.innerWidth > 1024;

// ── DESKTOP COLLAPSE ─────────────────────────────────────────────────────────

function collapse() {
    sidebar.classList.add('collapsed');
    if (main) main.classList.add('collapsed');
    localStorage.setItem('sidebar_collapsed', '1');
}

function expand() {
    sidebar.classList.remove('collapsed');
    if (main) main.classList.remove('collapsed');
    localStorage.setItem('sidebar_collapsed', '0');
}

// ── TABLET / MOBILE OVERLAY ───────────────────────────────────────────────────

function openOverlay() {
    sidebar.classList.add('mobile-open');
    overlay.classList.add('visible');
}

function closeOverlay() {
    sidebar.classList.remove('mobile-open');
    overlay.classList.remove('visible');
}

// ── PUBLIC: TOGGLE BUTTON (collapse-btn in header) ───────────────────────────
// Called by onclick="toggleSidebar()" on the ☰ button

window.toggleSidebar = function() {
    if (isMobile()) {
        closeOverlay();   // ☰ on mobile acts as close inside the open sidebar
        return;
    }
    if (isTablet()) {
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
// When collapsed, clicking the A icon expands the sidebar

window.logoIconClick = function() {
    if (isMobile()) {
        openOverlay();
        return;
    }
    if (isTablet()) {
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

// ── OVERLAY CLICK → CLOSE ────────────────────────────────────────────────────

overlay.addEventListener('click', closeOverlay);

// ── INIT ─────────────────────────────────────────────────────────────────────

function init() {
    if (isMobile() || isTablet()) {
        sidebar.classList.remove('collapsed', 'mobile-open');
        overlay.classList.remove('visible');
        if (main) main.classList.remove('collapsed');
        return;
    }
    // Desktop: restore saved state
    if (localStorage.getItem('sidebar_collapsed') === '1') {
        collapse();
    }
}

window.addEventListener('resize', () => {
    if (isMobile()) {
        sidebar.classList.remove('collapsed', 'mobile-open');
        overlay.classList.remove('visible');
        if (main) main.classList.remove('collapsed');
    } else if (isTablet()) {
        sidebar.classList.remove('collapsed');
        if (main) main.classList.remove('collapsed');
    }
});

// ── SIDEBAR LOGOUT ────────────────────────────────────────────────────────────

window.sidebarLogout = async function(logoutUrl, loginUrl) {
    try { await fetch(logoutUrl, { method: 'POST' }); } catch {}
    window.location.href = loginUrl;
};

init();
