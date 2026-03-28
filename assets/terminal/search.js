import $ from "jquery";
import { allTransactions } from "./state.js";
import { createCard } from "./transactions.js";

$('#transaction-search').on('input', function() {
    const q = $(this).val().trim().toLowerCase();

    // Helper: normalize phone numbers
    const normalizePhone = (val) => {
        if (!val) return '';
        // digits only
        let digits = val.replace(/\D/g, '');
        // remove leading 0 if present
        if (digits.startsWith('0')) {
            digits = digits.substring(1);
        }
        return digits;
    };

    // Helper: normalize amount (int / float)
    const normalizeAmount = (val) => {
        if (val === null || val === undefined || val === '') return '';
        return parseFloat(val).toString();
    };

    // Reset if empty
    if (!q) {
        let html = '';
        allTransactions.forEach(t => html += createCard(t));
        $('#transactions').html(html);
        setTimeout(() => $('.card-enter').addClass('card-enter-active'), 10);
        return;
    }

    const qDigits = normalizePhone(q);
    const qAmount = !isNaN(q) ? parseFloat(q).toString() : null;

    const filtered = allTransactions.filter(t => {

        // ---- Name ----
        if (
            (t.first_name && t.first_name.toLowerCase().includes(q)) ||
            (t.middle_name && t.middle_name.toLowerCase().includes(q)) ||
            (t.last_name && t.last_name.toLowerCase().includes(q))
        ) {
            return true;
        }

        // ---- Transaction ID ----
        if (t.transaction_id && t.transaction_id.toLowerCase().includes(q)) {
            return true;
        }

        // ---- Reference ----
        if (t.reference && t.reference.toLowerCase().includes(q)) {
            return true;
        }

        // ---- Phone (ignore leading 0) ----
        if (qDigits && t.msisdn) {
            const storedDigits = normalizePhone(t.msisdn);

            // simple, predictable match
            if (storedDigits.includes(qDigits)) {
                return true;
            }
        }

        // ---- Amount ----
        if (qAmount !== null && t.amount !== undefined) {
            const storedAmount = normalizeAmount(t.amount);
            if (storedAmount === qAmount || storedAmount.includes(qAmount)) {
                return true;
            }
        }

        return false;
    });

    let html = '';
    filtered.forEach(t => html += createCard(t));
    $('#transactions').html(html);
    setTimeout(() => $('.card-enter').addClass('card-enter-active'), 10);
});
