// ============================================================
// NAVBAR
// ============================================================

// ── DROPDOWNS ────────────────────────────────────────────────────────────────

window.toggleDropdown = function(id) {
    const dropdown = document.getElementById(id);
    if (!dropdown) return;
    document.querySelectorAll('.navbar-dropdown').forEach(d => {
        if (d.id !== id) d.classList.remove('open');
    });
    dropdown.classList.toggle('open');
};

document.addEventListener('click', function(e) {
    if (!e.target.closest('#notif-btn') && !e.target.closest('#notifications-dropdown')) {
        document.getElementById('notifications-dropdown')?.classList.remove('open');
    }
    if (!e.target.closest('#profile-btn') && !e.target.closest('#profile-dropdown')) {
        document.getElementById('profile-dropdown')?.classList.remove('open');
    }
});

// ── LOGOUT ────────────────────────────────────────────────────────────────────

window.navbarLogout = async function(logoutUrl, loginUrl) {
    try { await fetch(logoutUrl, { method: 'POST' }); } catch {}
    window.location.href = loginUrl;
};

// ── SEARCH ────────────────────────────────────────────────────────────────────

const searchInput = document.getElementById('navbar-search-input');

if (searchInput) {
    document.addEventListener('keydown', function(e) {
        if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
            e.preventDefault();
            searchInput.focus();
            searchInput.select();
        }
        if (e.key === 'Escape' && document.activeElement === searchInput) {
            searchInput.blur();
        }
    });

    // On mobile tap on the search icon area to focus
    searchInput.addEventListener('click', function() {
        this.focus();
    });
}
