import $ from "jquery";
import { advancedSearchActive } from "./state.js";
import { refreshTransactions } from "./transactions.js";
import { resetInactivity } from "./inactivity.js";

const lockUser = () => {
    if (advancedSearchActive) {
        $('#advanced-back-btn').trigger('click');
    }

    $.post('/mpesa/session/lock', {}, () => {
        $('#dashboard').addClass('hidden');
        $('#lock-screen').removeClass('hidden');
    }, 'json');
};

const unlockUser = () => {
    const pin = $('#user_pin').val();

    // terminal_identifier is read server-side from HttpOnly cookie
    // set during dashboard login — we never send it from JS
    $.post('/mpesa/session/unlock', {
        pin: pin,
    }, (res) => {
        if (res.success) {
            $('#lock-screen').addClass('hidden');
            $('#dashboard').removeClass('hidden');
            $('#user_pin').val('');
            $('#pin_error').text('');

            // Clear search on unlock
            $('#transaction-search').val('');

            refreshTransactions();
            resetInactivity();
        } else {
            $('#pin_error').text(res.message ?? 'Invalid PIN');
        }
    }, 'json').fail(function(xhr) {
        const msg = xhr.responseJSON?.message ?? 'Error. Please try again.';
        $('#pin_error').text(msg);
    });
};

export function initLock() {
    window._lockUser = lockUser;

    $('.pin-key').on('click', function() {
        const current = $('#user_pin').val();
        if (current.length < 6) $('#user_pin').val(current + $(this).text());
    });

    $('#pin-clear').on('click', () => $('#user_pin').val(''));
    $('#pin-back').on('click', () => $('#user_pin').val($('#user_pin').val().slice(0, -1)));
    $('#pin_login').on('click', unlockUser);
    $('#lock').on('click', lockUser);
}

export { lockUser };