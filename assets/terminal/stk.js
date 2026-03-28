import $ from "jquery";
import { refreshTransactions } from "./transactions.js";
import { resetInactivity } from "./inactivity.js";

const terminalBaseUrl = () => window.TERMINAL_BASE_URL || '/terminal';

let stkType = 'paybill';

$('.stk-type').on('click', function() {
    if ($(this).is(':disabled')) return;
    $('.stk-type').removeClass('bg-green-600 text-white').addClass('bg-gray-300');
    $(this).removeClass('bg-gray-300').addClass('bg-green-600 text-white');
    stkType = $(this).data('type');
    if (stkType === 'paybill') $('#stk-account').removeClass('hidden');
    else $('#stk-account').addClass('hidden').val('');
});

const $stkForm   = $('#stk-push-form');
const $stkStatus = $('#stk-push-status');
const $loader    = $('#stk-push-loader');
const $loaderText = $('#stk-loader-text');
const $backBtn   = $('#back-to-transactions');

$loader.hide();
$backBtn.show();

$('#stk_push_btn').on('click', function(e) {
    e.preventDefault();

    const phone   = $('#stk_phone').val();
    const amount  = $('#stk_amount').val();
    const account = $('#stk-account').val();

    if (!phone || !amount) {
        alert('Please enter phone and amount.');
        return;
    }

    $stkForm.hide();
    $stkStatus.html('');
    $loader.show();

    $.post(`${terminalBaseUrl()}/stk/push`, {
        type: stkType,
        phone,
        amount,
        account
    }, function(res) {
        if (res.ResponseCode === "0") {
            pollTransaction(res.CheckoutRequestID, 90000);
        } else {
            $loader.hide();
            $stkStatus.html('<p class="text-red-600">STK Push failed: ' + res.ResponseDescription + '</p>');
            $stkForm.show();
        }
    }, 'json').fail(function(xhr) {
        $loader.hide();
        const msg = xhr.responseJSON?.error ?? 'STK Push Error';
        $stkStatus.html(`<p class="text-red-600">${msg}</p>`);
        $stkForm.show();
    });
});

function pollTransaction(checkoutID, timeout = 90000) {
    const startTime = Date.now();
    let dots = 0;

    const interval = setInterval(function() {
        dots = (dots + 1) % 4;
        $loaderText.text('Waiting for payment' + '.'.repeat(dots));

        if (Date.now() - startTime > timeout) {
            clearInterval(interval);
            $loader.hide();
            $stkStatus.html('<p class="text-red-600 font-bold">Transaction timed out. Please try again.</p>');
            $stkForm.show();
            resetInactivity();
            return;
        }

        $.getJSON(`${terminalBaseUrl()}/transactions/check`, {
            checkout_id: checkoutID
        }, function(res) {
            if (res.found && res.result_code !== null) {
                clearInterval(interval);
                $loader.hide();
                $backBtn.show();

                if (res.result_code == 0) {
                    let details = `<p class="text-green-600 font-bold">Payment Received ✅</p>`;
                    details += `<p><strong>Amount:</strong> KES ${res.amount}</p>`;
                    details += `<p><strong>Mpesa Receipt:</strong> ${res.mpesa_receipt}</p>`;
                    details += `<p><strong>Transaction Date:</strong> ${res.transaction_date}</p>`;
                    $stkStatus.html(details);

                    $stkForm.show().find('input').val('');
                    if (stkType === 'paybill') $('#stk-account').removeClass('hidden');
                    else $('#stk-account').addClass('hidden');

                    $('.stk-type').removeClass('bg-green-600 text-white').addClass('bg-gray-300');
                    $(`.stk-type[data-type="${stkType}"]`).removeClass('bg-gray-300').addClass('bg-green-600 text-white');
                } else {
                    $stkStatus.html('<p class="text-red-600 font-bold">Payment Failed: ' + res.result_description + '</p>');
                    $stkForm.show();
                }
                resetInactivity();
            }
        }).fail(function() {
            clearInterval(interval);
            $loader.hide();
            $stkStatus.html('<p class="text-red-600">Error checking transaction</p>');
            $stkForm.show();
            resetInactivity();
        });
    }, 1000);
}

const showDashboard = () => {
    $('#stk-section').addClass('hidden');
    $('#dashboard').removeClass('hidden');
    resetInactivity();
    refreshTransactions();
};

$('#show-stk').on('click', function() {
    $('#dashboard').addClass('hidden');
    $('#stk-section').removeClass('hidden');
    clearTimeout(window.inactivityTimer);
    history.pushState({ stkMode: true }, '');
});

$('#show-dashboard').on('click', showDashboard);
$('#back-to-transactions').on('click', function() {
    $('#stk_phone, #stk_amount, #stk-account').val('');
    if (stkType === 'paybill') $('#stk-account').removeClass('hidden');
    else $('#stk-account').addClass('hidden');
    $('#stk-push-status').html('');
    $('#stk-push-loader').hide();
    $('#stk-push-form').show();
    $('.stk-type').removeClass('bg-green-600 text-white').addClass('bg-gray-300');
    $(`.stk-type[data-type="${stkType}"]`).removeClass('bg-gray-300').addClass('bg-green-600 text-white');
    showDashboard();
}).show();
