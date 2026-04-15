# M-Pesa Loyalty Auto-Award Todo

This document breaks the feature into safe implementation steps across:

- [angavu](C:/xampp/htdocs/angavu)
- [patronrapis](C:/xampp/htdocs/patronrapis)

The goal is to support automatic loyalty awarding for qualifying M-Pesa payments:

- `STK Push`
- `Callback / wait mode`

while preventing double-awards when:

- the callback already awarded points
- the cashier later claims the payment
- checkout step 4 still exists for normal/manual flows

## Intended Outcome

When all configured conditions pass:

- loyalty points may be auto-awarded from M-Pesa payment completion
- checkout step 4 can be skipped
- callback-mode payments may remain claimable for cashier workflow
- the same payment must never award points twice

## Conditions For Auto-Award

Auto-award should happen only when all of these are true:

- loyalty module/feature is effectively enabled for the tenant
- the loyalty programme exists and is active
- earning points is enabled
- the relevant M-Pesa config explicitly allows auto-award
- the payment mode is one of:
  - `stk_push`
  - `callback`
- the customer is already enrolled

Optional extension:

- if auto-enroll is enabled, a non-enrolled customer may be enrolled and then awarded
- this should remain discouraged by UI copy and default to off

## Idempotency Principle

M-Pesa callback processing and cashier claim flow must both be idempotent.

We should treat:

- `mpesa_payments` as the callback-side award anchor
- `pos_transactions` as the checkout/sale reconciliation record

Rules:

- callback award happens at most once per `mpesa_payments` row
- checkout award happens at most once per `pos_transactions` row
- if one side already awarded, the other side must sync/link only, not award again

## Step-by-Step Todo

### 1. Finalize Schema Changes

- [x] Add loyalty programme toggles on `loyalty_programs`
  - `auto_award_enabled`
  - `auto_enroll_on_payment`
- [x] Add M-Pesa config toggle on `mpesa_configs`
  - `auto_award_loyalty`
- [x] Add callback award-state fields on `mpesa_payments`
  - `loyalty_auto_awarded`
  - `loyalty_awarded_at`
  - `loyalty_points_awarded`
  - `loyalty_account_id`
  - `customer_id`
- [x] Add checkout reconciliation award-state fields on `pos_transactions`
  - `loyalty_auto_awarded`
  - `loyalty_auto_awarded_amount`
  - `loyalty_awarded_at`
  - `loyalty_award_source`
- [x] Decide whether `pos_transactions.loyalty_points_awarded` continues as the final displayed total or only the transaction-side copy

Notes:

- `loyalty_award_source` should be explicit, not inferred
- recommended values:
  - `manual`
  - `mpesa_callback`
  - `mpesa_claim_sync`

### 2. Define One Awarding Rule

- [x] Write down the exact business rule for when payment completion itself is considered enough to award points
- [x] Confirm whether callback award is allowed before cashier claim for wait mode
- [x] Confirm whether auto-enroll should grant enroll bonus during payment-triggered enrollment
- [x] Confirm whether loyalty awarding should use full transaction amount or earnable amount only when split payments exist

Notes:

- callback award is allowed before cashier claim for callback / wait mode
- M-Pesa split legs can auto-award individually
- any remaining non-M-Pesa split leg continues through the normal step 4 flow
- payment-triggered auto-enroll currently applies the standard enrollment bonus when enabled
- manual step 4 now awards only the earnable remainder after loyalty redemption and already auto-awarded M-Pesa legs

### 3. Build Shared Award Guard Logic

- [x] Add a small shared decision layer in `angavu` for:
  - is loyalty auto-award allowed for this company/branch/config?
  - is this customer eligible?
  - should auto-enroll happen?
- [x] Mirror the minimum required logic in `patronrapis` for callback-time awarding
- [x] Make both sides use the same conditions and field names

Notes:

- avoid spreading the rules across controllers
- centralize the decision, even if both apps need a version of it
- angavu-side guard is implemented via `isTransactionAutoAwarded()` + `remainingEarnableAmount()` private helpers in CheckoutController — these are the effective decision points for skip and partial-award logic
- full award decision logic lives in `LoyaltyAutoAwardService` (patronrapis); angavu only needs to know whether to skip or deduct already-awarded amounts

### 4. Build Callback Award Service In Patronrapis

- [x] Add `LoyaltyAutoAwardService` in [src\Service](C:/xampp/htdocs/patronrapis/src/Service)
- [x] Service should:
  - load `mpesa_payments`
  - stop if already auto-awarded
  - resolve company and branch context
  - resolve active loyalty programme
  - resolve enrolled customer or auto-enroll if allowed
  - calculate points
  - write ledger/account changes
  - mark `mpesa_payments.loyalty_auto_awarded = 1`
  - store awarded metadata on `mpesa_payments`
- [x] Keep it transaction-wrapped so award + row update succeed/fail together

Notes:

- this service should not guess checkout state
- it should operate on payment callback state only

### 5. Wire STK Callback Auto-Award

- [x] Update [MpesaCallbackProcessor.php](C:/xampp/htdocs/patronrapis/src/Service/MpesaCallbackProcessor.php)
- [x] After successful STK callback:
  - continue marking `stk_push_logs`
  - continue marking `pos_transactions`
  - ensure a matching `mpesa_payments` row exists or is linked
  - call `LoyaltyAutoAwardService`
- [x] Make sure STK success cannot award twice if retries/repeated callbacks happen

Notes:

- STK is the cleaner path because callback already maps to a specific checkout request

### 6. Wire Callback / Wait Mode Auto-Award

- [x] Update C2B confirmation processing in [MpesaCallbackProcessor.php](C:/xampp/htdocs/patronrapis/src/Service/MpesaCallbackProcessor.php)
- [x] After inserting `mpesa_payments`, call `LoyaltyAutoAwardService`
- [x] Preserve current unclaimed behavior for cashier wait/claim flow
- [x] Do not require immediate `pos_transactions` link at callback time

Notes:

- this is intentional: callback award can happen before cashier claim
- claim flow must later sync, not re-award

### 7. Sync Awarded Callback Rows Into Pos Transactions

- [x] Update claim flow in [CheckoutController.php](C:/xampp/htdocs/angavu/src/Controller/Terminal/CheckoutController.php)
- [x] When cashier claims an `mpesa_payments` row:
  - link it to `pos_transactions`
  - copy/sync loyalty award metadata if `mpesa_payments.loyalty_auto_awarded = 1`
  - set `pos_transactions.loyalty_auto_awarded = 1`
  - set `pos_transactions.loyalty_award_source = 'mpesa_claim_sync'` or equivalent
  - do not call normal award logic again

Notes:

- this is the key double-award prevention point for callback mode

### 8. Guard Transaction Completion In Angavu

- [x] Update [TransactionRecordService.php](C:/xampp/htdocs/angavu/src/Services/Patronr/TransactionRecordService.php)
- [x] In `markComplete(...)`:
  - if transaction already has auto-award state, skip loyalty award write
  - still allow status completion and receipt updates
- [x] Ensure legacy manual step 4 continues to work for non-auto-awarded cases

Notes:

- this is the core protection against callback + step4 double-award

### 9. Update Checkout Step 4 UX

- [x] Update `GET /checkout/4` in [CheckoutController.php](C:/xampp/htdocs/angavu/src/Controller/Terminal/CheckoutController.php)
- [x] If transaction already has auto-award state:
  - skip manual form
  - advance to step 5
  - or show a very brief “Points already awarded” state before continuing
- [x] Update `POST /checkout/4` to no-op award logic when already auto-awarded

Notes:

- the UI should not confuse the cashier
- they should not feel forced to repeat a loyalty action already done by the system
- step 4 now remains available when non-M-Pesa value still needs manual awarding after one or more M-Pesa split legs were auto-awarded

### 10. Add Loyalty Settings UI

- [x] Update [loyalty.html.twig](C:/xampp/htdocs/angavu/templates/admin/settings/loyalty.html.twig)
- [x] Add toggle:
  - `Automatically award points for qualifying payments`
- [x] Add toggle:
  - `Enroll customers automatically on payment`
- [x] Add warning/help text for auto-enroll
- [x] Save both in [SettingsController.php](C:/xampp/htdocs/angavu/src/Controller/Admin/SettingsController.php)

Notes:

- auto-enroll should be visually discouraged
- default should remain off

### 11. Add M-Pesa Config UI

- [x] Update [payment.html.twig](C:/xampp/htdocs/angavu/templates/admin/settings/payment.html.twig)
- [x] Add M-Pesa drawer toggle:
  - `Auto-award loyalty points`
- [x] Show hint that it only matters when:
  - loyalty is enabled
  - integration mode includes `stk_push` or `callback`
- [x] Save it through [SettingsController.php](C:/xampp/htdocs/angavu/src/Controller/Admin/SettingsController.php)

Notes:

- do not allow this to silently imply auto-enroll
- keep those as separate choices

### 12. Reconcile Existing Loyalty Branch Scope

- [x] Decide whether this work should proceed with branch-scoped loyalty or company-wide loyalty
- [x] If staying branch-scoped, ensure callback award uses payment `branch_id`
- [x] If reverting loyalty to company-wide later, revisit all new logic before rollout

Notes:

- **Decision: loyalty stays branch-scoped** (architecture-rules.md §17)
- `LoyaltyAutoAwardService` uses `branch_id` from `mpesa_payments`; a null branch_id aborts the award
- If company-wide loyalty is ever adopted, `LoyaltyAutoAwardService`, `LoyaltyService`, `TransactionRecordService`, and loyalty settings UI all need revisiting

### 13. Add Audit Logging

- [x] Log automatic award events
- [x] Log automatic enroll events
- [x] Log claim-sync events where an already-awarded callback row is attached to a transaction
- [x] Log skipped duplicate-award attempts if useful for debugging

Notes:

- `LoyaltyAutoAwardService` (patronrapis) writes system-generated rows to `user_activity_logs` with `actor_type = 'system'`, `user_id = NULL`
- Requires segment28 schema migration to make `user_id` nullable and add `'system'` to actor_type enum
- `CheckoutController` (angavu) logs claim-sync and step-4 skip events via `UserActivityLogService` with the cashier's session

### 14. Verify Reporting / Terminal History

- [x] Check [TransactionController.php](C:/xampp/htdocs/angavu/src/Controller/Terminal/TransactionController.php)
- [x] Ensure terminal history shows:
  - receipt
  - points awarded
  - correct transaction status
- [x] Decide whether callback-awarded but not-yet-claimed payments should appear anywhere in terminal UI

Notes:

- Terminal history fetches `pt.*` so `loyalty_points_awarded` and `loyalty_auto_awarded` are always included
- `transactions.html.twig` now shows an "AUTO" badge next to points when `loyalty_auto_awarded = 1`
- C2B payments that haven't been claimed yet have no `pos_transaction` row — they correctly do not appear in terminal history; this is intentional (they belong to `mpesa_payments` until a cashier claims them)

### 15. Test Matrix

- [ ] Manual M-Pesa with loyalty enabled
- [ ] Manual M-Pesa with loyalty disabled
- [ ] STK push success with enrolled customer
- [ ] STK push success with non-enrolled customer and auto-enroll off
- [ ] STK push success with non-enrolled customer and auto-enroll on
- [ ] C2B callback with enrolled customer before cashier claim
- [ ] C2B callback with non-enrolled customer and auto-enroll off
- [ ] C2B callback with non-enrolled customer and auto-enroll on
- [ ] Cashier claims already auto-awarded callback payment
- [ ] Repeated callback payload does not double-award
- [ ] Step 4 skip works correctly
- [ ] Split payment with loyalty redemption only awards on earnable amount

## Recommended Order

Implement in this order:

1. schema
2. transaction guard in `angavu`
3. callback award service in `patronrapis`
4. STK callback wiring
5. C2B callback wiring
6. claim-sync in `angavu`
7. settings UI
8. test matrix

## Completion Notes

Use this section as we execute the work.

- [x] Step 1 complete
- [x] Step 2 complete
- [x] Step 3 complete
- [x] Step 4 complete
- [x] Step 5 complete
- [x] Step 6 complete
- [x] Step 7 complete
- [x] Step 8 complete
- [x] Step 9 complete
- [x] Step 10 complete
- [x] Step 11 complete
- [x] Step 12 complete
- [x] Step 13 complete
- [x] Step 14 complete
- [ ] Step 15 complete (test matrix — manual QA)
