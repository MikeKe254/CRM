import $ from "jquery";
import { initLock } from "./lock.js";
import { refreshTransactions } from "./transactions.js";
import { resetInactivity } from "./inactivity.js";
import { advancedSearchActive } from "./state.js";
import "./search.js";
import "./advanced-search.js";
import "./summary.js";
import "./stk.js";
import "./ui.js";
import "./inactivity.js";

$(function() {
    // Skip entirely on Patronr terminal pages (v3.0)
    if (document.body.dataset.patronr) {
        return;
    }

    const hasMpesaUi = $('#dashboard').length || $('#lock-screen').length;

    if (!hasMpesaUi) {
        return;
    }

    initLock();

    // ================= AUTO-REFRESH =================
    setInterval(() => {
        if (
            !$('#dashboard').hasClass('hidden') &&
            !$('#transaction-search').val() &&
            !advancedSearchActive
        ) {
            refreshTransactions();
        }
    }, 10000);

    // ================= REFRESH BUTTON =================
    $('#refresh').on('click', refreshTransactions);

    // ================= POPSTATE (browser back button) =================
    window.addEventListener('popstate', function() {

        // Exit Advanced Search first
        if (advancedSearchActive) {
            $('#advanced-back-btn').trigger('click');
            return;
        }

        // Exit STK mode
        if ($('#stk-section').is(':visible')) {
            $('#back-to-transactions').trigger('click');
            return;
        }

    });

    // ================= INITIAL LOAD =================
    resetInactivity();

    if (!$('#dashboard').hasClass('hidden')) {
        refreshTransactions();
    }
});
