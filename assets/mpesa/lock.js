import $ from "jquery";
import { advancedSearchActive } from "./state.js";
import { refreshTransactions } from "./transactions.js";
import { resetInactivity } from "./inactivity.js";

const lockUser = () => {
    // If advanced search is active, exit it first
    if (advancedSearchActive) {
        $('#advanced-back-btn').trigger('click');
    }

    $.post('ajax/lock_user.php', () => {
        $('#dashboard').addClass('hidden');
        $('#lock-screen').removeClass('hidden');
    }, 'json');
};

const unlockUser = () => {
    $.post('ajax/unlock_user.php', {
        pin: $('#user_pin').val()
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
            $('#pin_error').text('Invalid PIN');
        }
    }, 'json');
};

export function initLock() {
    window._lockUser = lockUser; // make available to inactivity timer
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
