import { Controller } from "@hotwired/stimulus"

/**
 * Manages the STK push dispatch + polling loop on checkout step 3.
 *
 * Values wired from the template:
 *   data-checkout-stk-url-value         POST: trigger STK push
 *   data-checkout-status-url-value      GET:  poll status
 *   data-checkout-next-url-value        redirect on success (step 4)
 *   data-checkout-csrf-value            CSRF token for POST
 */
export default class extends Controller {
    static targets = ["form", "waiting", "error", "sendBtn", "waitMsg", "receipt"]
    static values  = {
        stkUrl:    String,
        statusUrl: String,
        nextUrl:   String,
        csrf:      String,
        checkoutId: { type: String, default: "" },
    }

    #interval = null
    #polling  = false
    #attempts = 0
    static MAX_POLLS = 40  // 40 × 3s = 2 minutes

    disconnect() { this.#stopPolling() }

    // ── Send STK Push ─────────────────────────────────────────────────────────

    async send(event) {
        event.preventDefault()

        const phone = this.element.querySelector('[data-keypad-target="input"]')?.value ?? ""
        if (!phone || phone.length < 9) {
            this.#setError("Enter a valid phone number (e.g. 0712 345 678)")
            return
        }

        this.#clearError()
        this.sendBtnTarget.disabled = true
        this.sendBtnTarget.textContent = "Sending…"

        try {
            const body = new URLSearchParams({ phone, _token: this.csrfValue })
            const res  = await fetch(this.stkUrlValue, {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body,
            })
            const data = await res.json()

            if (data.success) {
                this.checkoutIdValue = data.checkout_request_id
                this.#showWaiting("Waiting for M-Pesa confirmation…")
                this.#startPolling()
            } else {
                this.sendBtnTarget.disabled = false
                this.sendBtnTarget.textContent = "Send STK Push"
                this.#setError(data.message || "STK push failed. Try again.")
            }
        } catch {
            this.sendBtnTarget.disabled = false
            this.sendBtnTarget.textContent = "Send STK Push"
            this.#setError("Network error. Check connection and retry.")
        }
    }

    // ── Manual Confirm Fallback ───────────────────────────────────────────────

    confirmManual() {
        this.#stopPolling()
        // Navigate to the confirm URL (step 3 confirm POST via a form submit)
        this.element.closest("form")?.setAttribute("action",
            this.element.dataset.checkoutConfirmUrl)
        this.element.closest("form")?.submit()
    }

    // ── Cancel / retry ────────────────────────────────────────────────────────

    retry() {
        this.#stopPolling()
        this.#showForm()
        this.sendBtnTarget.disabled = false
        this.sendBtnTarget.textContent = "Send STK Push"
        this.#clearError()
    }

    // ── Private ───────────────────────────────────────────────────────────────

    #startPolling() {
        this.#attempts = 0
        this.#interval = setInterval(() => this.#poll(), 3000)
    }

    #stopPolling() {
        if (this.#interval) { clearInterval(this.#interval); this.#interval = null }
    }

    async #poll() {
        if (this.#polling) return
        this.#polling = true
        this.#attempts++

        try {
            const url = `${this.statusUrlValue}?checkout_id=${encodeURIComponent(this.checkoutIdValue)}`
            const res  = await fetch(url)
            const data = await res.json()

            if (data.status === "complete") {
                this.#stopPolling()
                window.Turbo.visit(this.nextUrlValue)

            } else if (data.status === "failed") {
                this.#stopPolling()
                this.#showForm()
                this.sendBtnTarget.disabled = false
                this.sendBtnTarget.textContent = "Retry STK Push"
                this.#setError(data.message || "Payment was declined or timed out.")

            } else if (this.#attempts >= this.constructor.MAX_POLLS) {
                this.#stopPolling()
                this.#showForm()
                this.sendBtnTarget.disabled = false
                this.sendBtnTarget.textContent = "Retry STK Push"
                this.#setError("No response from M-Pesa. Ask customer to confirm and retry, or confirm manually.")
            } else {
                // Still pending — update wait message
                const secs = this.#attempts * 3
                if (this.hasWaitMsgTarget) {
                    this.waitMsgTarget.textContent = `Waiting for M-Pesa confirmation… (${secs}s)`
                }
            }
        } catch {
            // Network blip — keep polling silently
        } finally {
            this.#polling = false
        }
    }

    #showWaiting(msg) {
        if (this.hasFormTarget)    this.formTarget.classList.add("hidden")
        if (this.hasWaitingTarget) this.waitingTarget.classList.remove("hidden")
        if (this.hasWaitMsgTarget) this.waitMsgTarget.textContent = msg
    }

    #showForm() {
        if (this.hasWaitingTarget) this.waitingTarget.classList.add("hidden")
        if (this.hasFormTarget)    this.formTarget.classList.remove("hidden")
    }

    #setError(msg) {
        if (this.hasErrorTarget) { this.errorTarget.textContent = msg; this.errorTarget.classList.remove("hidden") }
    }

    #clearError() {
        if (this.hasErrorTarget) { this.errorTarget.textContent = ""; this.errorTarget.classList.add("hidden") }
    }
}
