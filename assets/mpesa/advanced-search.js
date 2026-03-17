import $ from "jquery";
import { advancedSearchActive, setAdvancedSearchActive } from "./state.js";
import { showToast } from "./ui.js";
import { loadTransactionSummary, resetTransactionSummary } from "./summary.js";
import { refreshTransactions } from "./transactions.js";
import { resetInactivity } from "./inactivity.js";

export const createAdvancedCard = t => {

    const fullName = [t.first_name, t.middle_name, t.last_name]
        .filter(Boolean)
        .join(' ') || 'Unknown Customer';

    const phone = t.msisdn || 'N/A';

    const status = t.customer_status ?? 'Unknown';

    const statusColor =
        status === 'NEW'
            ? 'bg-green-100 text-green-700'
            : status === 'RETURNING'
            ? 'bg-blue-100 text-blue-700'
            : 'bg-gray-100 text-gray-600';

    return `
    <div class="card-enter bg-white rounded-xl shadow p-4 w-full max-w-full">

        <div class="flex gap-6">

            <!-- LEFT SIDE -->
            <div class="flex-1">
                <h3 class="font-bold text-lg mb-1">${fullName}</h3>

                <p><strong>Phone:</strong> ${phone}</p>

                <p>
                    <strong>Status:</strong> 
                    <span class="ml-1 px-2 py-0.5 text-xs font-semibold rounded ${statusColor}">
                        ${status}
                    </span>
                </p>

                <p><strong>Amount:</strong> KES ${Number(t.amount).toLocaleString()}</p>
                <p><strong>Method:</strong> ${t.method}</p>
                <p><strong>Transaction ID:</strong> ${t.transaction_id}</p>
                <p><strong>Reference:</strong> ${t.reference}</p>

                <p class="text-sm text-gray-600 mt-2">${t.created_at}</p>
            </div>

            <!-- DIVIDER -->
            <div class="w-px bg-gray-300"></div>

            <!-- RIGHT SIDE -->
            <div class="flex flex-col flex-1">

                <div>
                    <h4 class="font-semibold mb-2">Customer Profile</h4>

                    <p><strong>Gender:</strong> ${t.gender ?? 'Unknown'}</p>

                    <p>
                        <strong>Total Transactions:</strong> 
                        ${t.total_visits ? Number(t.total_visits).toLocaleString() : '0'}
                    </p>

                    <p>
                        <strong>Total All Time Spend:</strong> 
                        KES ${t.all_time_spend ? Number(t.all_time_spend).toLocaleString() : '0'}
                    </p>

                    <p>
                        <strong>All Time Avg Spend:</strong> 
                        KES ${t.average_spend ? Number(t.average_spend).toLocaleString() : '0'}
                    </p>

                    <p>
                        <strong>Segment:</strong> 
                        <span class="font-semibold text-purple-600">
                            ${t.spending_segment ?? 'Unknown'}
                        </span>
                    </p>

                    <p>
                        <strong>Loyalty Tier:</strong> 
                        <span class="font-semibold text-green-600">
                            ${t.loyalty_tier ?? 'None'}
                        </span>
                    </p>
                </div>

                <!-- NOTE + BOTTOM SECTION -->
                <div class="mt-auto">

                    <p class="text-xs text-gray-500 mt-3">
                        Customer profiles are updated once daily at 2:00 AM.
                    </p>

                    <div class="pt-2 border-t border-gray-200 mt-1">
                        <a 
                            href="#" 
                            onclick="loadCustomerProfile('${phone}'); return false;"
                            class="text-sm text-blue-600 hover:text-blue-800 hover:underline"
                        >
                            View Customer Profile
                        </a>
                    </div>

                </div>

            </div>

        </div>

    </div>
    `;
};

$('#advanced-search-btn').on('click', function() {
    setAdvancedSearchActive(true);

    $('.loader').show();

    const params = {
        date_from:      $('#adv-date-from').val(),
        date_to:        $('#adv-date-to').val(),
        time_from:      $('#adv-time-from').val(),
        time_to:        $('#adv-time-to').val(),
        type:           $('#adv-type').val(),
        msisdn:         $('#adv-msisdn').val(),
        transaction_id: $('#adv-transaction-id').val(),
        reference:      $('#adv-reference').val(),
        amount:         $('#adv-amount').val(),
        name:           $('#adv-name').val()
    };

    // ✅ URL updated — only change from original
    $.getJSON('/mpesa/search', params, function(res) {
        $('.loader').hide();

        // 🔔 TOAST
        showToast(res.message, res.success);

        let html = '';

        if (res.success && res.data.length) {
            res.data.forEach(t => html += createAdvancedCard(t));

            // Load summary
            loadTransactionSummary(params);

            // ✅ Auto-scroll to summary, accounting for sticky header
            const $summary = $('#transaction-summary');
            if ($summary.length) {
                const $header = $('header:visible, .sticky:visible, .fixed:visible').first();
                const headerHeight = $header.length ?
                    $header.outerHeight() : 0;

                $('html, body').animate({
                    scrollTop: $summary.offset().top - headerHeight - 10
                }, 500);
            }

        } else {
            html = `
                <div class="col-span-full text-center text-gray-600 font-semibold">
                    ${res.message}
                </div>
            `;
        }

        $('#transactions').html(html);

        setTimeout(() => {
            $('.card-enter').addClass('card-enter-active');
        }, 10);

    }).fail(function(xhr) {
        $('.loader').hide();
        if (xhr.status === 401) {
            window.location.href = '/mpesa/login';
            return;
        }
        showToast('Advanced search failed. Please try again.', false);
    });
});

$('#advanced-back-btn').on('click', function() {
    setAdvancedSearchActive(false);

    // Clear advanced search inputs
    $('#advanced-search-panel')
        .find('input, select')
        .val('');

    // Hide advanced panel
    $('#advanced-search-panel').addClass('hidden');

    // Restore normal UI
    $('#quick-search-row').removeClass('hidden');
    $('#top-nav').removeClass('hidden');

    // Clear quick search
    $('#transaction-search').val('');

    refreshTransactions();
    resetInactivity();
});

$('#advanced-clear-btn').on('click', function() {
    $('#advanced-search-panel')
        .find('input, select')
        .val('');
});

$('#advanced-search-link').on('click', function(e) {
    e.preventDefault();

    if (advancedSearchActive) return;

    setAdvancedSearchActive(true);

    // Stop inactivity timer
    if (window.inactivityTimer) {
        clearTimeout(window.inactivityTimer);
        window.inactivityTimer = null;
    }

    // Hide normal UI
    $('#top-nav').addClass('hidden');
    $('#quick-search-row').addClass('hidden');

    // Clear current transaction cards
    $('#transactions').html('');

    // Show advanced search panel
    $('#advanced-search-panel').removeClass('hidden');

    // Push history state
    history.pushState({ advancedSearch: true }, '');
});
