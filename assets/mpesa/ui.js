import $ from "jquery";
import Toastify from "toastify-js";

// ================= TOAST =================
export const showToast = (message, success = true) => {
    Toastify({
        text: message,
        duration: 3000,
        gravity: "top",
        position: "right",
        style: {
            background: success
                ? "linear-gradient(to right, #16a34a, #22c55e)"
                : "linear-gradient(to right, #dc2626, #ef4444)",
        },
        stopOnFocus: true
    }).showToast();
};

// ================= INFO MODAL =================
(function() {
    const $modal = $('#info-modal');
    const $text = $('#info-modal-text');
    const $title = $('#info-modal-title');

    // Open modal
    $(document).on('click', '.info-trigger', function() {
        const info = $(this).data('info');
        const label = $(this).data('label') || 'Info';

        if (!info) return;

        $title.text(label);
        $text.text(info);
        $modal.removeClass('hidden');
    });

    // Close modal
    $('#info-modal-close').on('click', function() {
        $modal.addClass('hidden');
    });

    // Close when clicking backdrop
    $modal.on('click', function(e) {
        if (e.target === this) {
            $modal.addClass('hidden');
        }
    });
})();

// ================= CUSTOMER PROFILE MODAL =================
window.loadCustomerProfile = function(phone) {

    const modal   = document.getElementById('customerProfileModal');
    const content = document.getElementById('customerProfileContent');
    const title   = document.getElementById('customerProfileTitle');

    title.innerText = `Customer Profile - ${phone}`;

    modal.classList.remove('hidden');
    modal.classList.add('flex');

    // prevent background scroll
    document.body.classList.add('overflow-hidden');

    // show loader
    content.innerHTML = `
        <div class="flex justify-center items-center py-10">
            <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-gray-900"></div>
        </div>
    `;

    // ✅ URL updated to new Symfony route
    fetch('/mpesa/customer/profile', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: 'msisdn=' + encodeURIComponent(phone)
    })
    .then(res => res.json())
    .then(data => {

        if (data.success) {
            content.innerHTML = data.data;
        } else {
            content.innerHTML = data.data || `<p class="text-red-500">Customer profile could not be loaded.</p>`;
        }

    })
    .catch(() => {
        content.innerHTML = `<p class="text-red-500">Connection error. Please try again.</p>`;
    });

};

window.closeCustomerProfileModal = function() {

    const modal = document.getElementById('customerProfileModal');

    modal.classList.add('hidden');
    modal.classList.remove('flex');

    document.body.classList.remove('overflow-hidden');

};