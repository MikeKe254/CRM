# Patronr System Progress

This document captures where Patronr is right now, what has been intentionally prioritized, and what the next build sequence should be.

It exists to prevent two common mistakes:

- thinking the system is behind just because visible modules are not fully built yet
- building higher-level modules before the foundational systems underneath them are stable

This is a progress and sequencing document, not a marketing document.

---

## 1. Current Phase

Patronr is currently in:

**core systems foundation and architectural stabilization**

This means the priority has not yet been broad feature/module expansion.

Instead, the focus has been on building and correcting the load-bearing parts of the system that future modules will depend on.

This is intentional.

---

## 2. What Has Been Prioritized On Purpose

The work so far has focused more on structural correctness than visible product breadth.

That includes:

- permission services
- hierarchy and authority rules
- platform vs tenant separation
- branch-first operational context
- org and role structure
- feature entitlement and plan override design
- activity logging
- terminal architecture
- M-Pesa and STK/callback foundations
- loyalty execution rules
- system design and architectural direction

This means progress has largely been in:

- services
- controllers
- schema decisions
- system design
- runtime rules

not yet mainly in:

- complete polished modules
- broad page coverage
- surface-level product expansion

---

## 3. Why This Sequence Is Correct

Patronr is not a simple CRUD application.

It is being built as a system that combines:

- branch operations
- payment flows
- customer capture
- loyalty
- CRM
- communications
- intelligence
- SaaS platform controls

If higher-level modules are built before foundational rules are stable, the likely outcome is:

1. modules get built quickly
2. core assumptions later prove wrong
3. permissions, branch scope, or identity rules break those modules
4. major rewrites become necessary

The current approach is therefore:

- slower visually
- safer structurally
- better for long-term system integrity

This is the correct strategy for Patronr.

---

## 4. What Groundwork Is Already In Place

The following areas have already received significant foundational work.

### A. System direction

- product identity has been clarified
- Patronr is now framed as a CRM and Revenue Operating System
- system design direction has been documented
- architectural rules are being made explicit rather than left implicit

### B. Authority and access model

- platform and tenant authority are treated as different layers
- role hierarchy rules have been designed and enforced more consistently
- user and role operations increasingly respect real hierarchy
- feature gating and platform permissions are being formalized

### C. Branch-first operations

- branch context has been treated as the primary operational unit
- branch access logic has been tightened
- branch-first thinking is shaping terminals, logs, permissions, and settings

### D. Terminal and payment foundation

- terminal architecture is actively being shaped
- STK push and callback patterns are being designed as native operational flows
- payment-linked customer capture is being treated as the core wedge
- split payment and callback reconciliation groundwork exists

### E. Loyalty foundation

- loyalty is being tied directly to payment and customer identity
- manual and automatic award logic has been thought through
- settings and reward logic are being shaped with real-world operational conditions in mind

### F. Observability and traceability

- activity logging is being treated as a first-class system concern
- system actions, not only user actions, are being considered in audit design

These foundations are important because many future modules will rely on them.

---

## 5. What Is Not Yet Built

A lot of Patronr is still ahead.

That is expected.

Examples of work that is still early, partial, or not yet fully built:

- broad polished admin/page coverage
- complete CRM workflows
- mature intelligence/action surfaces
- campaign execution flows
- messaging management surfaces
- report orchestration
- deeper customer intelligence views
- fully developed communications modules
- many end-user operating pages

So the system should not be described as “mostly built.”

It should be described as:

**foundationally progressing, but still early in module expansion**

---

## 6. Current Intentional Build Order

The current sequence is:

1. Design the terminal and make it fully designed
2. Revenue classification layer (catalog items, areas, events, covers)
3. Promotions and discounts
4. SMS and WhatsApp foundational infrastructure
5. Reassess the next module direction using the ROS pillar framework

This is a valid sequence.

It reflects the current belief that the next most important step is not more random modules, but making the core operating loop stronger.

---

## 7. Why Terminal First Makes Sense

The terminal is the most important operational surface in Patronr.

It is where the product wedge becomes real:

- payment happens
- customer identity can be captured
- loyalty can be executed
- customer behavior becomes data

If the terminal experience is weak, slow, confusing, or visually unfinished:

- staff adoption drops
- payment-linked capture drops
- loyalty adoption drops
- CRM quality drops
- the rest of the system loses value

So making the terminal fully designed is not cosmetic work.

It is core product work.

The terminal should become:

- fast
- obvious
- confidence-building
- low-friction
- operationally elegant

---

## 8. Why SMS and Email Foundation Next Makes Sense

Patronr is not only about capturing data.

It must also act on that data.

SMS and email are the first practical action channels.

They are foundational because they enable:

- payment confirmations
- loyalty enrollment notifications
- loyalty earn/redeem notifications
- retention messages
- campaigns
- reports
- alerts
- future recommended actions

Without a real communications foundation, Patronr risks becoming:

- a system that understands what should happen
- but cannot easily make it happen

That would weaken the Revenue Operating System direction.

---

## 9. Recommended Scope For SMS/Email Foundation

The next communications groundwork should likely include:

### Core messaging model

- provider abstraction
- outbound message records
- delivery state tracking
- template or content structure
- branch/company context rules
- actor and audit tracing

### Operational use cases

- payment confirmation
- loyalty events
- report delivery
- admin test sends

### Architectural goals

- provider-agnostic by design
- usable from both Patronr APIs and Angavu
- auditable
- branch-aware where operationally necessary
- safe to expand later into campaigns and automation

The goal at this stage is not full marketing automation.

The goal is:

- reliable message infrastructure

---

## 10. Decision Guidance For What Comes After

After terminal design and communications foundation, the next step should not be chosen randomly.

It should be decided by asking:

1. What strengthens the payment-to-customer loop most?
2. What makes Patronr more actionable, not just more descriptive?
3. What builds on the new communications foundation naturally?
4. What benefits most from the groundwork already laid?

Likely candidates after that may include:

- campaign and segmentation workflows
- customer profile and customer intelligence surfaces
- reporting and scheduled summaries
- manager action dashboards
- intelligence-to-action recommendations

But the actual next step should be chosen after reassessing the state of the terminal and communications base.

---

## 11. Current Product Maturity Read

Right now Patronr should be understood as:

- strong in foundational architecture relative to its stage
- still early in visible module buildout
- intentionally focused on the parts that prevent future breakage

That means the current progress is:

- not shallow
- not visually broad
- structurally meaningful

This is not “just shell work.”

It is platform hardening and direction-setting work.

---

## 12. Current Strategic Summary

Patronr is still in groundwork mode.

The current mission is not to rush module count.

The current mission is:

- make the terminal strong
- make payment-linked capture strong
- make authority and branch rules stable
- make communications foundationally correct
- then build outward on stable ground

That is the correct sequencing for the system being built.

---

## 13. Immediate Next Steps

The current working sequence should be treated as:

1. Terminal design completion
2. Revenue classification layer — catalog items, areas, events, covers (schema + checkout UI + admin pages)
3. Promotions and discounts
4. SMS and WhatsApp foundation (co-primary channels for Kenya)
5. Email foundation alongside only if it does not dilute focus
6. Patronr API and Angavu integration for messaging
7. Re-evaluate next strategic module using the ROS pillar framework

### Guidance on channel priority

SMS and WhatsApp are the primary channels for Kenya.

WhatsApp is not a future-phase addition — it belongs in the communications foundation alongside SMS.

Email is useful for scheduled reports and formal communications but should not block SMS and WhatsApp delivery.

Priority: SMS and WhatsApp first, email in the same phase only if it does not slow down the core channels.

---

## 14. Full ROS Build Roadmap

This section captures the complete picture of what Patronr needs to become a genuine Revenue Operating System. It is a sequencing guide, not a delivery commitment.

Each item is mapped to one of the four ROS pillars: **Capture, Understand, Act, Measure**.

### Phase 1 — Terminal completion (current)

These are the active work items right now.

| Item | Pillar | Status |
|---|---|---|
| Terminal checkout flow | Capture | In progress |
| M-Pesa STK + callback | Capture | In progress |
| Loyalty at checkout | Capture | In progress |
| Permission enforcement across terminal | Capture | In progress |
| Activity logging across terminal | Measure | In progress |
| Branch-scoped terminal settings | Capture | Done |
| VIEW_TRANSACTIONS / SEND_STK_PUSH / VIEW_FULL_CUSTOMER_PHONE gates | Capture | Done |

### Phase 2 — Revenue classification layer

These add revenue context to every transaction. Low build cost, high intelligence value.

| Item | Pillar | Notes |
|---|---|---|
| Catalog items (services + products) | Capture | Lightweight — name, category, price, branch-scoped |
| Events table + branch management | Capture | Start/end time, status, auto-suggest at checkout |
| `revenue_source_type` + `revenue_source_id` on transactions | Capture | Links to catalog_items |
| `event_id` on transactions | Capture | Separate from revenue source — event is context, not a sale type |
| `covers` on transactions | Capture | Headcount — one field, unlocks per-cover metrics |
| Areas surfaced in revenue intelligence | Understand | Already captured on transactions — needs reporting layer |
| Catalog quick-pick at checkout | Capture | Optional, recent/frequent first, skippable |
| Event context suggestion at checkout | Capture | Auto-suggest if active event exists, cashier confirms or skips |
| Events admin page | Capture | Create, edit, activate, end events per branch |
| Catalog admin page | Capture | Manage services, products, categories per branch |

Tickets are deferred until events are stable.

### Phase 3 — Operational accountability

These make the system reliable for revenue accuracy and staff accountability.

| Item | Pillar | Notes |
|---|---|---|
| Promotions and discounts at checkout | Capture | Permission-gated, gross + net amounts stored, reason required |
| Vouchers and gift cards | Capture | Purchasable, redeemable at checkout, partial redemption |
| Discount permission and reporting | Measure | Who applied what discount and when |

### Phase 4 — Intelligence layer

These turn captured data into structured, useful understanding.

| Item | Pillar | Notes |
|---|---|---|
| Automatic customer segmentation | Understand | New, Returning, High Value, At Risk, Lost |
| Retention rate metric | Understand | Period-over-period repeat visit rate |
| Churn signals | Understand | Customers overdue for return based on their pattern |
| Staff performance reporting | Understand | Revenue, loyalty capture rate, discount usage per cashier |
| Event ROI reporting | Understand | Revenue during event vs comparable period |
| Revenue by classification | Understand | Slice by service/product/event/area/cashier/shift |

### Phase 5 — Communications foundation

These make it possible to act on what the intelligence layer surfaces.

| Item | Pillar | Notes |
|---|---|---|
| SMS provider abstraction | Act | Provider-agnostic, outbound message records |
| WhatsApp provider abstraction | Act | Primary channel alongside SMS |
| Delivery state tracking | Act | Sent, delivered, failed |
| Payment confirmation message | Act | Triggered on transaction complete |
| Loyalty event notifications | Act | Earn, redeem, enrollment welcome |
| Template management | Act | Tenant-configurable message content |
| Scheduled report delivery | Act | Daily/weekly briefing to owner via SMS or WhatsApp |

Email follows after SMS and WhatsApp are solid.

### Phase 6 — Action surfaces

These make the intelligence directly actionable.

| Item | Pillar | Notes |
|---|---|---|
| Automated trigger rules | Act | Visit lapse, birthday, loyalty milestone, event proximity |
| Campaign builder | Act | Segment → message → send |
| Manager action surface | Act | Daily answer to "what should I do today?" |
| Win-back campaigns | Act | Target at-risk and lost segments |
| Event-linked campaigns | Act | Notify past event attendees about upcoming events |

### Phase 7 — Measurement and loop closure

These close the loop so the system learns whether actions are working.

| Item | Pillar | Notes |
|---|---|---|
| Campaign outcome tracking | Measure | Return rate and revenue attributed to campaigns |
| Retention trend over time | Measure | Month-over-month retention by branch and company |
| Event comparison reporting | Measure | Event ROI compared across events and time |
| Discount impact reporting | Measure | Revenue effect of discounting patterns |

### What is deliberately deferred

These are valid eventually but not the right investment yet:

- full inventory and stock management — different product category
- table management and floor plan — heavy, build after shift management is solid
- reservations and bookings system — tickets (tied to events) come first
- accounting export and financial reporting — export clean data, do not become accounting software
- AI-driven predictions — data foundation must be solid first

---

## 15. Priority Sequence for What Comes After Terminal

When terminal design is complete, the recommended build order is:

1. **Revenue classification layer** (Phase 2) — because it enriches every transaction going forward and the build cost is low. Schema changes are easier to make now than after thousands of transactions have accumulated without this context.

2. **Promotions and discounts** (Phase 3) — revenue data is unreliable without it. Discounting outside the system creates dirty numbers that corrupt every downstream report.

3. **SMS + WhatsApp foundation** (Phase 5) — the first action channel. Everything in phases 6 and 7 depends on this being right. WhatsApp is co-primary with SMS for Kenya.

4. **Customer segmentation + churn signals** (Phase 4) — makes all the data already being captured actually useful. Feeds directly into messaging and the manager action surface.

5. **Scheduled reports** (Phase 5) — low build cost, high daily value. Owners get a morning briefing without logging in.

6. **Automated triggers** (Phase 6) — builds on the communications foundation. Works silently and compounds over time.

7. **Manager action surface** (Phase 6) — the interface that ties intelligence to action. Requires segmentation and communications to be in place.

8. **Campaign builder + outcome tracking** (Phases 6 and 7) — closes the ROS loop.

This is not a rigid delivery schedule.

It is the correct conceptual order.

If something in a later phase becomes urgently needed, it can be pulled forward — but the dependency chain should be understood before doing so.

---

## 16. The Four ROS Pillars as a Build Test

Every feature built from here should be tested against the four pillars:

- **Capture** — does this bring more revenue context or customer identity into the system?
- **Understand** — does this turn existing data into useful intelligence?
- **Act** — does this enable a direct, fast, measurable action?
- **Measure** — does this close a loop and show whether something worked?

A feature that serves none of these four pillars should be questioned before being built.

---

## 17. Progress Rule

The system should not be judged only by:

- how many pages exist
- how many modules are visible
- how much UI has been completed

It should also be judged by:

- how much future breakage has been prevented
- how much architectural confusion has been removed
- how much operational correctness has been locked in
- how much of the four-pillar ROS loop is functioning end to end

That is the stage Patronr is in now.
