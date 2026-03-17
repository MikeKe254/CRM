import $ from "jquery";

export function loadTransactionSummary(filters) {

    const $summary = $('#transaction-summary');
    const $loader = $('#summary-loader');
    const $content = $('#summary-content');

    // Reset UI
    $summary.removeClass('hidden');
    $loader.show();
    $content.addClass('hidden').html('');

    // ✅ URL updated — only change from original
    $.getJSON('/mpesa/search/summary', filters, function(res) {

        $loader.hide();

        if (!res.success || !res.data) {
            $summary.addClass('hidden');
            return;
        }

        const d = res.data;

        const html = `
            <!-- TOTAL AMOUNT -->
            <div class="p-4 rounded bg-gray-50">
                <div class="flex justify-between items-start">
                    <span class="text-sm text-gray-500">Total Amount</span>
                    <i class="fa-solid fa-circle-question text-gray-400 cursor-pointer info-trigger"
                       data-label="Total Amount"
                       data-info="Sum of all successful transaction amounts that match the current search filters and selected date/time period."></i>
                </div>

                <div class="text-2xl font-bold text-green-600 mt-1">
                    KES ${Number(d.total_amount || 0).toLocaleString()}
                </div>

                <div class="text-xs text-gray-400 mt-2">
                    ${d.date_from || '-'} → ${d.date_to || '-'}
                </div>
            </div>

            <!-- TOTAL TRANSACTIONS -->
            <div class="p-4 rounded bg-gray-50">
                <div class="flex justify-between items-start">
                    <span class="text-sm text-gray-500">Total Transactions</span>
                    <i class="fa-solid fa-circle-question text-gray-400 cursor-pointer info-trigger"
                       data-label="Total Transactions"
                       data-info="Total count of successful transactions that fall within the current search filters and selected period."></i>
                </div>

                <div class="text-xl font-semibold mt-2">
                    ${d.total_transactions || 0}
                </div>

                <div class="text-xs text-gray-400 mt-1">
                    From ${d.total_customers || 0} distinct customers
                </div>
            </div>

            <!-- NEW CUSTOMERS -->
            <div class="p-4 rounded bg-gray-50">
                <div class="flex justify-between items-start">
                    <span class="text-sm text-gray-500">New Customers</span>
                    <i class="fa-solid fa-circle-question text-gray-400 cursor-pointer info-trigger"
                       data-label="New Customers"
                       data-info="Customers whose first-ever successful payment occurred within the selected period."></i>
                </div>

                <div class="text-xl font-semibold mt-2">
                    ${d.new_customers || 0}
                </div>
            </div>

            <!-- RETURNING CUSTOMERS -->
            <div class="p-4 rounded bg-gray-50">
                <div class="flex justify-between items-start">
                    <span class="text-sm text-gray-500">Returning Customers</span>
                    <i class="fa-solid fa-circle-question text-gray-400 cursor-pointer info-trigger"
                       data-label="Returning Customers"
                       data-info="Customers who made payments both before and during the selected period."></i>
                </div>

                <div class="text-xl font-semibold mt-2">
                    ${d.returning_customers || 0}
                </div>
            </div>

            <!-- GENDER BREAKDOWN -->
            <div class="p-4 rounded bg-gray-50">
                <div class="flex justify-between items-start">
                    <span class="text-sm text-gray-500">Gender Breakdown</span>
                    <i class="fa-solid fa-circle-question text-gray-400 cursor-pointer info-trigger"
                       data-label="Gender Breakdown"
                       data-info="Customer gender distribution based on distinct phone numbers. Percentages are calculated using only verified genders (male and female)."></i>
                </div>

                <div class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span>👨 Male</span>
                        <span>
                            <strong>${d.total_males || 0}</strong>
                            <span class="text-gray-400 text-xs ml-1">(${d.male_percentage_known || 0}%)</span>
                        </span>
                    </div>

                    <div class="flex justify-between">
                        <span>👩 Female</span>
                        <span>
                            <strong>${d.total_females || 0}</strong>
                            <span class="text-gray-400 text-xs ml-1">(${d.female_percentage_known || 0}%)</span>
                        </span>
                    </div>
                </div>
            </div>

            <!-- UNCHECKED GENDER -->
            <div class="p-4 rounded bg-gray-50">
                <div class="flex justify-between items-start">
                    <span class="text-sm text-gray-500">Unchecked Gender</span>
                    <i class="fa-solid fa-circle-question text-gray-400 cursor-pointer info-trigger"
                       data-label="Unchecked Gender"
                       data-info="These customers have a gender value that is currently being checked or normalized in the background by the system. Their gender may update automatically once verification is complete."></i>
                </div>

                <div class="text-2xl font-semibold mt-3">
                    ${d.unchecked_genders || 0}
                </div>

                <div class="text-xs text-gray-400 mt-1">
                    Customers pending system gender check.
                </div>
            </div>
        `;

        $content.html(html).removeClass('hidden');
    })
    .fail(function() {
        $summary.addClass('hidden');
    });
}

export function resetTransactionSummary() {
    $('#summary-content').html('').addClass('hidden');
    $('#transaction-summary').addClass('hidden');
}
