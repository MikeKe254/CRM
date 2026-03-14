import $ from "jquery";
import { advancedSearchActive } from "./state.js";

window.inactivityTimer = null;

export const startInactivityTimer = () => window.inactivityTimer = setTimeout(() => window._lockUser && window._lockUser(), 30000);

export const resetInactivity = () => {
    // 🚫 Pause inactivity during protected flows
    if (advancedSearchActive) return;
    if ($('#stk-section').is(':visible')) return;
    if ($('#lock-screen').is(':visible')) return;

    clearTimeout(window.inactivityTimer);

    if ($('#dashboard').is(':visible')) {
        startInactivityTimer();
    }
};

$(document).on('mousemove keydown click touchstart', resetInactivity);
