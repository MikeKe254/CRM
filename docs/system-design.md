# Patronr System Design

This document captures the intended system design direction for Patronr so product, architecture, and positioning can evolve without losing the core of what is being built.

It is not a low-level technical spec.

It is the design anchor for:

- what Patronr is
- what problem it solves
- how the system should be shaped
- what must remain true even when messaging, features, or target segments evolve

---

## 1. Product Definition

**Patronr is a CRM and Revenue Operating System for hospitality businesses, designed to capture customer data at the point of payment, improve retention, and drive revenue growth.**

This means Patronr is not just:

- a CRM
- a POS
- a loyalty tool
- a reporting dashboard
- a messaging tool

It is a system that combines these into one operational loop.

The key product truth is:

**Patronr embeds customer capture, retention, and revenue action into the natural payment flow.**

---

## 2. Core Problem

Most hospitality businesses already process payments every day, but they do not consistently convert those transactions into:

- customer identity
- customer history
- repeat-visit insight
- retention action
- revenue decisions

Traditional CRM tools fail here because they are detached from the operational moment that matters most:

- payment

If customer capture depends on extra staff effort outside the payment flow, adoption drops and data quality collapses.

Patronr exists to solve that gap.

---

## 3. Product Thesis

Every payment should become an opportunity to:

- identify the customer
- connect the transaction to behavior
- reward the customer
- trigger the right communication
- improve future revenue decisions

The system should therefore be designed around this loop:

1. Payment happens
2. Customer is identified or created
3. Transaction is linked to profile
4. Loyalty/reward logic is applied
5. Customer behavior becomes structured data
6. Messaging, segmentation, and intelligence are updated
7. The business gets guidance on what to do next

That is the Patronr operating loop.

This loop must be strong in execution, not only correct in concept.

Rules:

- customer capture at payment must feel faster than skipping it
- staff should not need to think in CRM language to use it correctly
- intelligence must lead to action, not stop at insight
- recommended actions must be directly executable inside the system where possible
- the business should increasingly experience Patronr as a daily operating assistant, not only a historical record system

---

## 4. Domain Positioning

### Primary category

- CRM and Revenue Operating System

### Core business domain

- hospitality and service businesses

### Core operating environments

- restaurants
- resorts
- hotels
- lounges
- bars
- cafes
- spas
- salons
- car wash businesses
- other repeat-visit service businesses

The rule is:

- design the system for hospitality and service businesses from the start
- avoid category language that unnecessarily narrows the product to restaurants and resorts only
- keep the product flexible enough to support different service environments that share the same payment-linked customer capture and retention model

The positioning is now intentionally broader, not future-tense expansion language.

---

## 5. What Patronr Is Not

Patronr should not drift into being treated as:

- a generic contact manager
- a pure marketing automation tool
- a standalone POS replacement by default
- a dashboard-heavy analytics product with weak operational capture
- a loyalty add-on with no operational intelligence

The system can include parts of those categories, but the design center must remain:

- payment-linked customer capture
- retention
- branch operations
- revenue guidance

If a feature strengthens reporting but weakens payment-time capture, staff speed, or actionability, it is likely the wrong tradeoff.

---

## 6. System Design Principle

The design center of Patronr is:

**branch-first operational execution, with company-level intelligence and platform-level control.**

This creates three interacting layers:

1. Platform layer
2. Tenant operating layer
3. Customer intelligence layer

Each layer has a different responsibility.

The system should also be judged against five strength tests:

1. Is point-of-payment customer capture extremely strong?
2. Does the system convert intelligence into immediate action?
3. Is branch-first operation preserved without leakage?
4. Are platform, tenant, and customer concerns clearly separated?
5. Does the experience feel like an operating system rather than a collection of tools?

---

## 7. Platform Layer

The platform layer exists to operate Patronr as a SaaS business.

Responsibilities:

- tenant provisioning
- plan and subscription management
- feature release and entitlement
- temporary plan/feature overrides
- platform admin management
- platform audit visibility
- provider integrations and global controls

The platform layer must remain separate from tenant business authority.

Rules:

- platform owner is not tenant owner
- platform roles must never be inferred from tenant roles
- tenant roles must never be inferred from platform roles
- platform admin access into tenant context must still respect explicit platform permissions
- platform analytics, feature control, and support actions must not pollute tenant operational data models
- platform logic should coordinate tenant capability, not redefine tenant business behavior per company through ad hoc hacks

---

## 8. Tenant Operating Layer

The tenant operating layer is where a hospitality business runs daily operations.

Responsibilities:

- branch and org structure
- user management
- role hierarchy
- permission assignment
- payment processing
- terminal operations
- loyalty settings and execution
- communications setup
- reporting and operational settings

This layer must feel like a real operating system for the business, not a bundle of disconnected admin screens.

Rules:

- operational flows should begin from what staff are trying to do now, not from what the system wants to store
- daily work should be executable with minimal screen switching
- branch managers and operators should be able to answer "what should I do today?" from inside this layer
- settings should shape behavior, but not replace guided operational action

---

## 9. Customer Intelligence Layer

This layer converts operational activity into customer understanding and action.

Responsibilities:

- customer profiles
- transaction history
- visit patterns
- spend patterns
- loyalty state
- segmentation
- churn and return signals
- revenue opportunities
- messaging triggers
- operational guidance

This layer is where Patronr becomes more than CRM.

The system must eventually help answer:

- who came
- who spent
- who returned
- who is disappearing
- what is likely to happen next
- what the business should do about it

This layer must not stop at analysis.

It must increasingly produce:

- recommended actions
- suggested campaigns
- suggested follow-ups
- suggested preparation steps
- segment-specific next moves

The intelligence layer is only strong when action is close to one tap away.

---

## 10. Core Operating Flows

The following flows are core to Patronr and should shape architecture decisions.

### A. Payment-to-customer capture flow

1. Staff initiates payment
2. Payment is completed through terminal-supported method
3. Customer phone is captured or confirmed
4. Customer is matched or created
5. Transaction is linked to the customer
6. Loyalty logic is applied
7. Transaction becomes usable CRM data

This is the most important flow in the entire system.

Design requirements:

- the capture step must remain fast enough that staff do not bypass it under pressure
- customer identification should be supported by real-world signals such as phone number and payment context
- when possible, payment methods should help strengthen identity capture rather than bypass it
- if staff frequently skip capture in busy periods, the system design should be treated as failing

### B. Loyalty flow

1. Customer is enrolled or matched
2. Earn/redeem rules are applied
3. Balance and ledger are updated
4. Customer is notified where appropriate
5. Loyalty state becomes part of retention logic

### C. Intelligence-to-action flow

1. Historical data is aggregated
2. Trends and signals are surfaced
3. The system presents practical guidance
4. Messaging or operational action is triggered
5. Results feed back into future decisions

This flow should evolve from:

- insight only

to:

- insight
- recommended action
- one-tap execution
- measurable outcome
- learning loop

### D. SaaS control flow

1. Platform releases a feature
2. Plan decides default entitlement
3. Override can change effective availability
4. Tenant config decides operational behavior

This separation must remain clean.

---

## 11. Architectural Direction

Patronr should be designed as a modular operational platform with strong separation of concerns.

### Main system areas

- Auth and identity
- Branch and org structure
- Roles and permissions
- Feature entitlement and plan control
- Payments and terminal operations
- Loyalty
- CRM and customer profiles
- Messaging and communications
- Reporting and intelligence
- Audit and traceability

Each area should have:

- a clear source of truth
- service-level enforcement
- UI that reflects real backend rules
- explicit operational outcomes, not only administrative correctness

---

## 12. Operational Context Model

The system is branch-first.

Rules:

- `branch_id` is the primary operational context
- `company_id` is secondary for operational records
- business actions should generally happen within a branch context
- company-level intelligence is built from branch-linked data

Exceptions are allowed, but they must be deliberate and documented.

This matters for:

- terminal sessions
- transactions
- payment configs
- activity logs
- departments
- areas
- role assignments
- customer intelligence overlays

Branch-first does not mean branch-isolated by mistake.

Rules:

- branch-scoped operations should remain clear and local
- company-wide intelligence should be built intentionally from branch-linked activity
- cross-branch visibility should be explicit, permissioned, and explainable
- customer identity must not bleed incorrectly across branches, but intelligence should still be able to aggregate at company level when appropriate

---

## 13. Identity Model

### Staff identity

Staff identity is tenant-owned and permission-controlled through hierarchy and branch context.

### Customer identity

Customer identity should remain canonical at the company level.

That means:

- one customer identity per real customer per company
- not duplicated per branch by default

Branch-level intelligence should sit on top of that identity, not replace it.

Future direction:

- canonical `customers`
- optional branch intelligence layer such as `customer_branch_profiles`

This allows:

- one customer identity
- branch-local insight
- company-wide lifetime understanding

Rules:

- do not duplicate customer identity per branch by default
- do not let branch-local convenience corrupt canonical identity
- do not let company-wide identity erase useful branch-level behavior context

---

## 14. Payments Design Direction

Payments are not just settlement mechanisms in Patronr.

They are operating events.

Each payment method should be able to contribute to:

- checkout completion
- customer identification
- loyalty execution
- activity logging
- revenue attribution

Payment methods should support:

- explicit branch context
- method-level configuration
- provider integration abstraction
- terminal-friendly execution

M-Pesa is strategically important and should be treated as a first-class native flow, not a bolt-on.

Payments must strengthen the operating loop by:

- helping identity capture
- making loyalty execution timely
- producing auditable state transitions
- feeding customer and branch intelligence quickly enough to be operationally useful

---

## 15. Loyalty Design Direction

Loyalty should be tightly linked to payments and retention, not isolated as a separate product.

Current direction:

- loyalty is branch-scoped operationally
- loyalty can be awarded manually or automatically where rules allow
- loyalty is tied to customer behavior and future messaging

The point of loyalty is not only reward.

It is also:

- identity capture
- retention improvement
- repeat-visit reinforcement
- behavior segmentation

Loyalty should also support:

- faster customer recognition
- more consistent payment-time capture
- stronger daily retention actions
- measurable branch-level repeat behavior

If loyalty becomes a side feature rather than a retention mechanism, the design has drifted.

---

## 16. Messaging Design Direction

Messaging is a core operating capability, especially in markets like Kenya.

Channels should include:

- SMS
- email

Use cases include:

- payment confirmations
- loyalty enrollment
- points earned
- redemption confirmations
- targeted campaigns
- automated reports

Messaging must remain provider-agnostic where possible.

The system should choose recipients based on customer behavior, not only static lists.

Messaging should increasingly move from manual broadcast to guided action:

- who should be contacted
- why they should be contacted
- what message type fits them
- what outcome the business is trying to influence

---

## 17. Intelligence Design Direction

The intelligence layer should be practical, not theatrical.

It should not pretend to offer perfect prediction.

It should instead give useful operational guidance based on real historical patterns.

Examples:

- expected traffic by day/time
- expected customer mix
- likely demand windows
- segments at risk of churn
- high-value customers to re-engage
- campaign opportunities

The output should answer:

- what is likely happening
- why it matters
- what action the business should consider next

The strongest expression of this layer is not:

- "here is a chart"

It is:

- "here is what is happening"
- "here is why it matters"
- "here is what you can do now"
- "here is the expected business effect"

This is where Patronr becomes a Revenue Operating System rather than only a CRM.

---

## 18. UX Design Direction

The UX should feel like a calm, production-grade business product.

Principles:

- operational first
- low friction
- no unnecessary staff burden
- clear hierarchy
- clean branch context
- polished but restrained visuals
- fast comprehension over feature clutter
- actionability over dashboard decoration

In terminal flows especially:

- speed matters
- clarity matters
- success/failure states must be obvious
- customer capture should feel natural, not forced
- the system should reassure staff immediately when automation has already handled loyalty or other downstream work

---

## 19. Repositioning Guardrails

Patronr can evolve in category language over time, but must not lose its core design truth.

If repositioning happens, these must remain true:

- payment is still a primary customer capture moment
- the system still improves retention, not just reporting
- branch operations remain first-class
- actions matter as much as insights
- CRM remains embedded in operations, not detached from them
- revenue growth remains a central product outcome
- daily action guidance becomes stronger over time, not weaker

Good repositioning examples:

- CRM and Revenue Operating System for hospitality businesses
- Revenue operating platform for hospitality
- payment-linked CRM and retention platform

Bad repositioning examples:

- generic analytics platform
- messaging tool for restaurants
- pure loyalty app
- generic POS

---

## 20. Long-Term Product Shape

If Patronr matures fully, it should feel like:

- an operational system for branches
- a CRM system for customer memory
- a retention engine for repeat business
- a communication engine for engagement
- an intelligence layer for daily revenue decisions
- a SaaS platform with clean controls, plans, and overrides
- a daily action system that tells the business what matters now and makes execution easy

That is the intended product shape.

---

## 21. Practical Decision Test

When making future product or architecture decisions, ask:

1. Does this strengthen or weaken payment-linked customer capture?
2. Does this help retention and revenue action, or only add admin complexity?
3. Does this fit the branch-first operational model?
4. Does this preserve the separation between platform, tenant, and customer concerns?
5. Does this make Patronr more like an operating system, or more like a disconnected tool?
6. Does this help the business decide what to do today?

If the answer consistently weakens these principles, the direction is probably wrong.

---

## 22. Current Strategic Summary

Patronr is being built as:

- a CRM
- a loyalty and retention engine
- a branch operating system
- a communications and reporting platform
- an emerging intelligence layer
- a SaaS product with platform-grade controls

The central wedge is still:

**capture customer identity and behavior at the point of payment, then turn that into retention and revenue action.**

That is the direction the system should continue to follow.

The near-term product challenge is to make the following all very strong at once:

- point-of-payment customer capture
- branch-first execution
- clean separation of concerns
- decision-to-action workflows
- daily operational usefulness

The system should be designed and judged accordingly.

---

## 23. Revenue Classification

Patronr needs a lightweight classification layer so every transaction carries context about what revenue came from and under what conditions it was generated.

This is not a full inventory or product management system.

It is a revenue intelligence layer built into the checkout moment.

### Classification model

Every transaction can optionally carry:

- a **revenue source** — what was sold
- an **area** — where in the branch it happened
- an **event context** — what was happening when it was sold

These are independent dimensions and must not be collapsed into one field.

A cashier can sell a service (massage) in a specific area (spa suite) during an event (Valentine's special). All three pieces of context matter independently and together.

### Revenue source

Revenue source classifies what type of thing was sold.

Three valid types:

- `service` — something performed for the customer (spa treatment, salon service, car wash, room service)
- `product` — something sold to the customer (drinks, food package, retail item, add-on)
- `ticket` — entry access or participation in an event or experience

At checkout, the cashier can optionally select a service, product, or ticket from the catalog.

The catalog (`catalog_items`) is branch-scoped, lightweight, and does not require full inventory management to be useful. A name, a category, an optional price, and an active/inactive flag is enough to start.

Schema direction:

```
pos_transactions
  revenue_source_type  ENUM('service', 'product', 'ticket')  nullable
  revenue_source_id    INT  nullable  — FK to catalog_items
```

### Events

Events are a revenue attribution context, not a type of thing being sold.

An event is a named window of business activity that a transaction can be tagged to.

Examples:

- live band night
- Valentine's dinner
- pool party
- conference day
- brunch event
- holiday special
- promotional weekend

Why events matter:

- lets revenue be understood in context rather than as an undifferentiated stream
- answers which events drive the highest revenue
- answers which customer types attend which events
- answers whether event attendees become repeat visitors
- enables event ROI comparison over time
- feeds campaign targeting ("contact everyone who attended last year's event")

Events are branch-scoped and have a start and end time.

When an event is active during a transaction, the system can suggest it for tagging.

Cashiers can also tag a transaction to an event manually.

Schema direction:

```
events
  company_id, branch_id
  name
  description
  starts_at, ends_at
  status (active, ended, cancelled)

pos_transactions
  event_id  INT  nullable  — FK to events
```

Event is a separate column from revenue source. They coexist, not compete.

### Tickets

Tickets are deferred until the events foundation is stable.

Tickets are best understood as a sub-product of events — paid entry or reserved participation — and require their own checkout sub-flow.

Do not build tickets before events are solid.

### Covers / headcount

Every checkout can optionally record the number of covers (people served).

This is one of the most valuable intelligence fields in hospitality.

It immediately enables:

- revenue per cover (critical metric for restaurants, lounges, spas)
- spend-per-head comparisons across areas, times, and events
- capacity utilization signals
- event size vs event revenue comparisons

Schema direction:

```
pos_transactions
  covers  SMALLINT UNSIGNED  nullable
```

One field. Optional. Never required. High intelligence value.

### Areas

Areas are already a native classification dimension in Patronr.

`area_id` is already captured on transactions at checkout. Areas are branch-scoped spaces that reflect how a physical business is laid out.

Examples:

- restaurant floor, terrace, private dining
- pool bar, lounge, rooftop
- spa suite, treatment room
- VIP section, main hall

Why areas matter for revenue intelligence:

- revenue by area answers which parts of the business are generating the most
- spend patterns differ by area — rooftop vs ground floor, VIP vs standard
- area performance feeds staffing and operational decisions
- combined with events and covers, area becomes a powerful intelligence slice

No new schema is needed. Area is already captured. It needs to be surfaced properly in the intelligence and reporting layer.

### Design rules for checkout classification

- all classification fields are optional — cashiers must never be blocked by them
- recent and frequent selections should appear first
- only active items for the current branch should be shown
- one primary catalog selection is enough for v1 — do not allow multiple catalog item selections at checkout to start
- the system should suggest the active event if one exists — cashier confirms or skips
- area is already selected at checkout — no extra step needed
- classification enriches intelligence but must never slow the payment moment

---

## 24. Promotions and Discounts

Discounts applied outside Patronr make revenue data unreliable.

If a cashier gives a 10% discount verbally and records the post-discount amount, the system sees a smaller transaction but has no record of the discount, its reason, or whether it was authorized.

Patronr must own the discount moment.

### Discount model

Discounts are applied at checkout before payment is taken.

Types:

- **percentage discount** — e.g. 15% off total
- **fixed amount discount** — e.g. KES 200 off
- **category discount** — percentage or fixed applied to a specific revenue source type (e.g. 10% off all services)

Every discount must carry:

- who applied it
- what type it was
- what the original amount was
- what the discounted amount was
- an optional reason or authorization note

### Why this matters

- revenue reporting reflects real original values, not post-discount amounts
- discount usage becomes a visible metric
- unauthorized discounting becomes detectable
- promotional campaigns can be tracked properly
- margin intelligence becomes possible

### Design rules

- discounts are optional at checkout
- permission-gated — not every cashier should be able to apply discounts
- discount history is attached to the transaction record
- gross amount and net amount must both be stored

---

## 25. Vouchers and Gift Cards

Vouchers and gift cards are both a revenue capture tool and a retention mechanism.

When a customer buys a gift card, that is revenue recognized today.

When it is redeemed, the loop closes.

This means gift cards:

- generate upfront revenue
- bring customers back (or bring new customers in)
- are trackable through the full cycle

### Voucher model

Two sub-types:

- **gift card** — monetary value, purchasable, redeemable at checkout like a payment method
- **promotional voucher** — issued by the business as a discount or reward, redeemable for a fixed value or percentage

Both should be redeemable at the terminal alongside other payment methods.

### Design rules

- gift cards are linked to a code (physical or digital)
- balance is tracked as it is redeemed
- vouchers have an expiry date
- redemption is recorded against a transaction
- partial redemption must be supported (redeem KES 300 of a KES 500 card)

---

## 26. Customer Segmentation and Churn Signals

Patronr must derive customer segments automatically from transaction and visit behavior.

Segments should not require manual tagging by staff.

They should emerge from what the system already knows.

### Core automatic segments

- **New** — first transaction in the last N days
- **Returning** — two or more visits, last visit within the retention window
- **High value** — top X% of customers by total spend
- **Frequent** — visits more often than the branch average
- **At risk** — visited regularly but have gone quiet (no visit in X days despite historical pattern suggesting they should have returned)
- **Lost** — no visit in a significant window (configurable per business type)

### Why this matters

- campaigns and messaging become targeted rather than broadcast
- the business can act on churn signals before customers are fully lost
- high-value customers can be treated differently
- retention rate becomes measurable as a real metric

### Retention rate

Retention rate is one of the most important metrics in a revenue operating system.

It should answer: of all customers who visited in the previous period, what percentage returned in the current period?

This should be surfaced at branch level and company level.

### Design rules

- segments are computed, not stored as static flags
- segment membership updates as transaction data updates
- thresholds (days, spend percentiles) should be configurable per business
- segments feed messaging and action surfaces, not just reports

---

## 27. Staff Performance Layer

Revenue per cashier, transactions per shift, average transaction size, and cancellation rates should all be natively visible in Patronr.

This is not a surveillance tool.

It is visibility that makes accountability possible.

### What should be measurable per cashier

- total revenue processed
- transaction count and average transaction value
- discount frequency and total discount value applied
- cancellation count
- loyalty capture rate (what percentage of their transactions captured a customer phone)
- period-level breakdown (daily, weekly)

### Why loyalty capture rate matters

Loyalty capture rate per cashier is one of the most operationally useful metrics in the system.

If cashier A captures customer phones on 70% of transactions and cashier B captures on 20%, the branch manager should know.

This directly maps to CRM data quality and long-term retention capability.

### Design rules

- staff performance data is derived from existing transaction records — no separate input required
- visible to branch managers and owners, permission-gated for cashiers seeing their own data
- should feed into the manager action surface

---

## 28. Communications Foundation

Patronr must be able to reach customers directly.

Communications is not a bolt-on feature.

It is the action layer of the intelligence loop.

Without communications, Patronr can understand what is happening but cannot act on it.

### Channels

- **SMS** — primary channel for Kenya. High open rate, no app required.
- **WhatsApp** — more important than email for Kenya. Customers read and respond. Should be in the foundation alongside SMS.
- **Email** — useful for reports and formal communications. Lower urgency than SMS/WhatsApp.

Channel priority: SMS first, WhatsApp close behind, email where appropriate.

### Core use cases

- payment confirmation
- loyalty enrollment welcome
- loyalty earn notification ("You earned 50 points. Balance: 320.")
- loyalty redeem confirmation
- win-back message ("We miss you. Here's an offer.")
- birthday message
- event invitation or reminder
- scheduled report delivery to owner/manager
- campaign broadcast to a segment

### Provider abstraction

Communications must be provider-agnostic.

The system should support multiple SMS and WhatsApp providers behind a unified interface.

Provider configuration, credentials, and routing should be tenant-managed and platform-controlled.

### Automated triggers

Rule-based triggers should fire without manual intervention:

- customer reaches loyalty milestone → send congratulations
- customer has not visited in X days → send win-back
- customer's birthday month → send offer
- event is approaching → notify past event attendees
- transaction completed → send payment confirmation if enabled

These compound silently over time and represent the most reliable form of retention action in the system.

### Scheduled reports

Owners and managers should receive automatic summaries without needing to log in.

A daily or weekly briefing via SMS or WhatsApp should answer:

- yesterday's total revenue
- transaction count
- top payment method
- new customers captured
- loyalty activity
- any notable variance from recent average

This should feel like a morning briefing, not a dashboard they must visit.

### Design rules

- every outbound message is logged with delivery state
- all messages carry company and branch context
- provider failures must not break the transaction flow that triggered them
- templates should be configurable per tenant
- message history should be linked to the customer record where applicable

---

## 29. Manager Action Surface

The manager action surface is the interface where intelligence becomes decision.

It is not a report.

It is not a chart.

It is the answer to: **what should I do today?**

### What it surfaces

- segments that need attention (at-risk customers ready for a win-back campaign)
- upcoming events and revenue projections based on history
- staff performance flags (low loyalty capture rates, high discount usage)
- loyalty activity summary (points awarded, redemptions, new enrollments)
- recent customer milestones worth acting on

### Design principle

Every item surfaced should be directly actionable from the same screen where possible.

See the at-risk segment → tap to create a campaign → send.

See the low capture rate cashier → tap to review their transaction history.

The distance between insight and action must be as short as possible.

### Design rules

- branch-scoped by default, company-level roll-up for owners
- should update daily at minimum
- not a real-time feed — a daily prioritized task surface
- actions taken from here should be logged

---

## 30. Campaign Outcome Tracking

When a campaign is sent, the system must be able to answer whether it worked.

Without outcome tracking, campaigns are a cost center, not an investment.

### What outcome tracking answers

- how many recipients returned after the campaign was sent
- total revenue attributed to returning recipients within the attribution window
- comparison to baseline return rate for the same segment
- which message type or segment produced the best result

### Attribution model

When a customer who received a campaign makes a transaction within a defined attribution window (e.g. 14 days), that transaction is attributed to the campaign.

Attribution is approximate by design — it measures correlation, not causation.

But it is operationally useful: the business can see whether campaigns are moving customer behavior or not.

### Design rules

- every campaign send is recorded against the customer
- transaction attribution is automated based on the window
- outcome report is attached to the campaign record
- campaign ROI (revenue generated vs cost of sends) should be surfaced where applicable

---

## 31. What Is Deliberately Not Being Built Yet

Some capabilities belong in Patronr eventually but are not the right next investment.

These should not be built until the core ROS loop is strong.

### Shift management

Opening floats, cash counts, and variance reconciliation belong to POS cash drawer management — not to a Revenue Operating System.

Patronr already knows who processed each transaction through the session and user context on every transaction. That is enough for staff performance attribution and period-level revenue reporting without building a full POS shift system.

Adding shift management would pull Patronr away from being a ROS and toward being a POS replacement. That is not the right direction.

### Full inventory and stock management

Stock levels, purchase orders, supplier management, stock alerts — these are a different product category.

Patronr's catalog layer should remain a lightweight revenue classifier, not a full inventory system.

If stock management is needed, it should be a separate module built after the core ROS is solid.

### Table management and floor plan

Visual floor plans, table status boards, cover assignment at table level — useful for large restaurants but heavy to build well.

This should only be considered after events and the manager action surface are operational.

### Reservations and bookings

Full booking management, availability calendars, booking confirmations — this is a separate operational flow.

Tickets (tied to events) are the lighter version of this and should come first.

### Accounting export and full financial reporting

P&L, balance sheet, tax reporting — these belong in accounting software.

Patronr should make it easy to export clean revenue data to accounting tools, but should not try to become an accounting system.

### AI-driven predictions

Demand forecasting, AI-generated campaigns, predictive churn models — valid long-term direction.

But the data foundation must be solid first.

Build the capture and intelligence layers properly before adding prediction on top.

---

## 32. The Four ROS Pillars

Every feature in Patronr should serve at least one of these four pillars:

**1. Capture**
Getting revenue context, customer identity, and operational detail into the system at the moment it happens.

Covers, catalog items, areas, events, discount records — all capture.

**2. Understand**
Turning captured data into structured intelligence that reflects real business behavior.

Segments, retention rate, staff performance, event ROI, churn signals — all understand.

**3. Act**
Converting intelligence into direct, fast, measurable action.

Campaigns, automated triggers, scheduled reports, win-back messages, manager action surface — all act.

**4. Measure**
Closing the loop by tracking whether actions produced results.

Campaign outcomes, retention trend, event comparison, discount impact — all measure.

A feature that does not serve any of these four pillars should be questioned seriously before being built.

---

## 33. Updated Long-Term Product Shape

If Patronr matures fully across all four pillars, it should feel like:

- an operational system for branches — where daily work happens
- a CRM system for customer memory — where identity and behavior accumulate
- a retention engine — where repeat business is actively managed
- a communication engine — where the right message reaches the right customer at the right time
- an intelligence layer — where patterns become visible and actionable
- a revenue classification layer — where every transaction carries context about what was sold, where, and under what conditions
- a SaaS platform — with clean controls, plans, and overrides

The business should feel that Patronr:

- knows its customers
- understands its revenue
- surfaces what matters today
- makes the right action one step away
