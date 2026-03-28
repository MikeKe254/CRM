import { Controller } from "@hotwired/stimulus"

/**
 * Reusable on-screen numeric keypad.
 *
 * Usage:
 *   <div data-controller="keypad"
 *        data-keypad-mode-value="amount"     (amount | phone)
 *        data-keypad-max-value="8"
 *        data-keypad-prefix-value="KES ">
 *
 *     <div data-keypad-target="display">KES 0</div>
 *     <input type="hidden" name="amount" data-keypad-target="input">
 *
 *     <button type="button" data-action="keypad#press" data-keypad-key-param="1">1</button>
 *     ...
 *     <button type="button" data-action="keypad#clear">C</button>
 *     <button type="button" data-action="keypad#backspace">⌫</button>
 *     <button type="button" data-action="keypad#quickAdd" data-keypad-amount-param="500">+500</button>
 *   </div>
 */
export default class extends Controller {
    static targets = ["display", "input"]
    static values  = {
        mode:   { type: String, default: "amount" },  // amount | phone
        max:    { type: Number, default: 8 },
        prefix: { type: String, default: "" },
    }

    #raw = ""

    connect() {
        // Pre-fill from existing hidden input value (draft resume)
        this.#raw = String(this.inputTarget.value || "")
        this.#render()
    }

    press({ params: { key } }) {
        const k = String(key)

        // Phone mode: allow leading 0, limit to 10 digits
        if (this.modeValue === "phone") {
            if (this.#raw.length >= 10) return
            this.#raw += k
        } else {
            // Amount mode: no leading zeros unless clearing
            if (this.#raw === "0" && k !== ".") return
            if (this.#raw.length >= this.maxValue) return
            this.#raw += k
        }

        this.#render()
    }

    backspace() {
        this.#raw = this.#raw.slice(0, -1)
        this.#render()
    }

    clear() {
        this.#raw = ""
        this.#render()
    }

    quickAdd({ params: { amount } }) {
        const current = parseInt(this.#raw || "0", 10)
        const next    = current + parseInt(amount, 10)
        this.#raw = String(next)
        this.#render()
    }

    /** Set value programmatically (e.g. from another controller) */
    setValue(v) {
        this.#raw = String(v)
        this.#render()
    }

    get currentValue() {
        return this.#raw
    }

    // ── Private ──────────────────────────────────────────────────────────────

    #render() {
        const raw = this.#raw

        if (this.modeValue === "amount") {
            const num = parseInt(raw || "0", 10)
            const formatted = num.toLocaleString("en-KE")
            this.displayTarget.textContent = this.prefixValue + formatted

            // Dim if zero
            this.displayTarget.classList.toggle("text-gray-500", num === 0)
            this.displayTarget.classList.toggle("text-white",    num !== 0)
        } else {
            // Phone — show with spaces: 0712 345 678
            this.displayTarget.textContent = raw
                ? raw.replace(/(\d{4})(\d{0,3})(\d{0,3})/, (_, a, b, c) =>
                    [a, b, c].filter(Boolean).join(" "))
                : "—"
            this.displayTarget.classList.toggle("text-gray-500", raw === "")
            this.displayTarget.classList.toggle("text-white",    raw !== "")
        }

        // Sync hidden input
        this.inputTarget.value = raw

        // Let other code react
        this.dispatch("change", { detail: { value: raw } })
        this.inputTarget.dispatchEvent(new Event("input", { bubbles: true }))
    }
}
