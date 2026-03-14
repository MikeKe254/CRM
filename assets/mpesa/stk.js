import $ from "jquery";
import { refreshTransactions } from "./transactions.js";
import { resetInactivity } from "./inactivity.js";

// ================= STK TYPE TOGGLE =================
let stkType = 'paybill'; // default Paybill
$('.stk-type').on('click', function() {
    if ($(this).is(':disabled')) return;
    $('.stk-type').removeClass('bg-green-600 text-white').addClass('bg-gray-300');
    $(this).removeClass('bg-gray-300').addClass('bg-green-600 text-white');
    stkType = $(this).data('type');
    if (stkType === 'paybill') $('#stk-account').removeClass('hidden');
    else $('#stk-account').addClass('hidden').val('');
});

// ================= STK PUSH =================
const $stkForm = $('#stk-push-form');
const $stkStatus = $('#stk-push-status');
const $loader = $('#stk-push-loader');
const $loaderText = $('#stk-loader-text');
const $backBtn = $('#back-to-transactions');

$loader.hide();
$backBtn.show(); // always visible

$('#stk_push_btn').on('click', function(e) {
    e.preventDefault();
    const phone = $('#stk_phone').val();
    const amount = $('#stk_amount').val();
    const account = $('#stk-account').val();

    if (!phone || !amount) {
        alert('Please enter phone and amount.');
        return;
    }

    $stkForm.hide();
    $stkStatus.html('');
    $loader.show();

    $.post('ajax/stk_push.php', {
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
    }, 'json').fail(function() {
        $loader.hide();
        $stkStatus.html('<p class="text-red-600">STK Push Error</p>');
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

        $.getJSON('ajax/stk_status_check.php', {
            checkout_id: checkoutID
        }, function(res) {
            if (res.found && res.result_code !== null) {
                clearInterval(interval);
                $loader.hide();
                $backBtn.show();

                if (res.result_code == 0) {
                    // Build success details
                    let details = `<p class="text-green-600 font-bold">Payment Received ✅</p>`;
                    details += `<p><strong>Amount:</strong> KES ${res.amount}</p>`;
                    details += `<p><strong>Mpesa Receipt:</strong> ${res.mpesa_receipt}</p>`;
                    details += `<p><strong>Transaction Date:</strong> ${res.transaction_date}</p>`;
                    $stkStatus.html(details);

                    // Reset form for next transaction
                    $stkForm.show().find('input').val('');
                    if (stkType === 'paybill') $('#stk-account').removeClass('hidden');
                    else $('#stk-account').addClass('hidden');

                    // Keep the correct button highlighted
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

// ================= VIEW TOGGLE =================
const showDashboard = () => {
    $('#stk-section').addClass('hidden');
    $('#dashboard').removeClass('hidden');
    resetInactivity();
    refreshTransactions();
};

$('#show-stk').on('click', function() {
    // Hide dashboard, show STK
    $('#dashboard').addClass('hidden');
    $('#stk-section').removeClass('hidden');

    // Stop inactivity timer
    clearTimeout(window.inactivityTimer);

    // 🔑 Push history so BACK works
    history.pushState({
        stkMode: true
    }, '');
});

$('#show-dashboard').on('click', showDashboard);
$('#back-to-transactions').on('click', function() {
    // Reset STK form inputs
    $('#stk_phone, #stk_amount, #stk-account').val('');
    if (stkType === 'paybill') $('#stk-account').removeClass('hidden');
    else $('#stk-account').addClass('hidden');

    // Clear status and loader
    $('#stk-push-status').html('');
    $('#stk-push-loader').hide();

    // Show the form again
    $('#stk-push-form').show();

    // Highlight active button
    $('.stk-type').removeClass('bg-green-600 text-white').addClass('bg-gray-300');
    $(`.stk-type[data-type="${stkType}"]`).removeClass('bg-gray-300').addClass('bg-green-600 text-white');

    // Go back to dashboard
    showDashboard();
}).show();
