import $ from "jquery";
import { allTransactions, setAllTransactions } from "./state.js";
import { resetTransactionSummary } from "./summary.js";

export const createCard = t => {
    const fullName = [t.first_name, t.middle_name, t.last_name].filter(Boolean).join(' ') || 'Unknown Customer';
    const phone = t.msisdn || 'N/A';
    return `<div class="card-enter bg-white rounded-xl shadow p-4 w-full max-w-full">
        <h3 class="font-bold text-lg mb-1">${fullName}</h3>
        <p><strong>Phone:</strong> ${phone}</p>
        <p><strong>Amount:</strong> KES ${t.amount}</p>
        <p><strong>Method:</strong> ${t.method}</p>
        <p><strong>Transaction ID:</strong> ${t.transaction_id}</p>
        <p><strong>Reference:</strong> ${t.reference}</p>
        <p class="text-sm text-gray-600 mt-1">${t.created_at}</p>
    </div>`;
};

export const refreshTransactions = (search = '') => {
    resetTransactionSummary();
    $('.loader').show();

    const params = {};
    if (search) params.q = search;

    $.ajax({
        url: '/mpesa/transactions',
        data: params,
        dataType: 'json',
        success: function(data) {
            $('.loader').hide();
            setAllTransactions(data);

            let html = '';
            allTransactions.forEach(t => html += createCard(t));
            $('#transactions').html(html);

            setTimeout(() => $('.card-enter').addClass('card-enter-active'), 10);
        },
        error: function(xhr) {
            $('.loader').hide();
            if (xhr.status === 401) {
                // Session expired — reload to show login
                window.location.href = '/mpesa/login';
            }
        }
    });
};
