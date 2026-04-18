# Loyalty Module — Intelligence Upgrade Plan

---

## The honest diagnosis

What we built is a **points ledger with a nice UI**. It is technically correct. It is not strategically useful.

A manager opens the loyalty overview right now and sees: 10 members, 120 points this month, 4 tiers. Then what? There is nothing to act on. No signal. No urgency. No direction. They close the tab and go back to running their business as if loyalty doesn't exist.

That is the failure. Not a bug. A design gap.

The four pillars expose it immediately:

| Pillar | Current state | Verdict |
|---|---|---|
| **Capture** | Points earned and redeemed at POS/M-Pesa are recorded. Enrollment happens. The ledger is immutable. | ✅ Solid foundation |
| **Understand** | Counts. Totals. A bar chart. No lifecycle. No segmentation. No signals. | ❌ Absent |
| **Act** | Zero. No triggers. No nudges. No manager actions. No member communication. | ❌ Absent |
| **Measure** | Period-over-period enrollment trend. Nothing tied to outcomes. | ❌ Almost nothing |

Capture is the one thing we did right — because it was already built before the admin surface. The admin surface added visibility but no intelligence, no action, no loop closure.

---

## What intelligence actually looks like here

A loyalty programme without intelligence is a stamp card. A stamp card you can query.

Intelligence means: **the system knows things the manager doesn't have time to figure out themselves**. It surfaces those things at the right moment, in the right place, with a clear next action.

Here is what that looks like concretely for Patronr loyalty:

**"You have 14 members who haven't transacted in 60 days. Three of them are Gold tier."**

That sentence contains: a signal (inactivity), a count (14), a severity escalation (Gold tier at risk), and an implied action (reach out before they lapse further). The manager didn't have to run a query. They didn't have to look at a list. The system told them something they need to know.

**"Sarah Kamau is 80 points away from Gold. She visited twice last month."**

That sentence contains: a proximity signal (almost there), a behavioral confirmation (she's active), and an implied action (a small nudge now converts her to a higher tier, which increases retention).

**"You redeemed KES 3,200 in points this month. 6 of those 8 members came back within 14 days."**

That sentence contains: a cost figure, a return rate, and a closed loop. Redemption is working. That's a measurement.

This is what the module needs to become.

---

## Weaknesses — current module

### 1. No lifecycle awareness
`loyalty_accounts` has no `last_transaction_at`, no `visit_count`, no `lifecycle_stage`. Every member looks the same in the system regardless of whether they visited yesterday or fourteen months ago. A churned member and an active member are indistinguishable at a glance. This makes the member list useless as a management tool.

### 2. No segmentation
Members are filtered by tier. That's it. Tier is a points threshold — it tells you nothing about behaviour. Two members can both be Bronze: one visits weekly and earns steadily, one enrolled six months ago and never came back. They're treated identically. That is a missed opportunity on both ends.

### 3. The overview is a dashboard, not a command centre
Four stat cards and a bar chart. This format communicates state — not direction. A manager needs to know **what to do next**, not just what happened. The overview page as built gives no answer to "so what?".

### 4. Nothing closes the loop
We can see that redemption happened. We cannot see whether the member who redeemed came back. We cannot see if a tier upgrade changed spend behaviour. We cannot see if the programme is growing or slowly dying. There is no measurement of whether any of this is working.

### 5. No action surface at all
No SMS. No in-app notification. No trigger configuration. No way for a manager to say "send a win-back message to lapsed members." The intelligence layer (once built) has nowhere to go.

---

## Strengths — what to build on

### 1. The ledger is immutable and complete
Every earn, redeem, adjust, enroll_bonus and expiry event is in `loyalty_ledger` with timestamps. This is the raw material for all intelligence. We can compute lifecycle stages, visit frequency, recency, and redemption behaviour entirely from the ledger — we just haven't.

### 2. The tier system has real potential
Tiers with colour, min_points, and sort_order are a genuine engagement mechanic. The progress bar in member profile is a start. The upgrade proximity signal we need to add is a natural extension.

### 3. Multipliers are a genuine Act lever
A happy hour multiplier is an action — "earn 2x points on Fridays between 4pm–7pm" directly drives behaviour. That feature is under-leveraged right now because managers can't see whether it changed anything.

### 4. The terminal flow already works
Customers interact with the loyalty programme at the terminal — checking balance, redeeming, enrolling. That touch point is live. All we're missing is the intelligence and action layer on top of it.

---

## What needs to be built

This is broken into three layers in dependency order: schema first, then intelligence, then action, then measurement.

---

### Layer 0 — Schema additions (enablers for everything else)

These columns need to exist before any intelligence query becomes efficient.

**Segment 44 — Enrich `loyalty_accounts`**

```sql
ALTER TABLE loyalty_accounts
  ADD COLUMN last_transaction_at TIMESTAMP NULL DEFAULT NULL
    COMMENT 'Timestamp of most recent earn or redeem ledger entry. Updated by trigger or service.',
  ADD COLUMN visit_count INT UNSIGNED NOT NULL DEFAULT 0
    COMMENT 'Total number of earn-type ledger entries. Proxy for visit count.',
  ADD COLUMN lifecycle_stage ENUM('new','active','at_risk','lapsing','churned') NOT NULL DEFAULT 'new'
    COMMENT 'Computed by nightly job or updated inline on each transaction.',
  ADD COLUMN avg_transaction_value DECIMAL(10,2) NULL DEFAULT NULL
    COMMENT 'Rolling average of pos_transaction amounts linked to this account. Updated by award().',
  ADD INDEX idx_la_lifecycle (company_id, lifecycle_stage),
  ADD INDEX idx_la_last_txn (company_id, last_transaction_at);
```

`last_transaction_at` and `visit_count` must be maintained by `LoyaltyService::award()` and `redeemPoints()` — updated atomically alongside the balance update. `lifecycle_stage` is set by a method that runs after every award/redeem and by the nightly expiry job.

**Segment 45 — `loyalty_notifications` table**

Tracks every notification sent to a member — so we can measure outcomes and avoid duplicate sends.

```sql
CREATE TABLE loyalty_notifications (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id      INT(11)         NOT NULL,
  loyalty_account_id INT(11)      NOT NULL,
  trigger_type    ENUM(
                    'win_back',
                    'tier_upgrade',
                    'almost_tier',
                    'expiry_warning',
                    'birthday_bonus',
                    'manual_campaign'
                  ) NOT NULL,
  channel         ENUM('sms','push','in_app') NOT NULL DEFAULT 'sms',
  message_text    TEXT            NOT NULL,
  sent_at         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  returned_at     TIMESTAMP       NULL DEFAULT NULL
    COMMENT 'Set when member transacts after this notification. Measures whether it worked.',
  campaign_id     INT(11)         NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_ln_account  (loyalty_account_id),
  KEY idx_ln_company  (company_id, trigger_type),
  KEY idx_ln_campaign (campaign_id),
  CONSTRAINT fk_ln_account FOREIGN KEY (loyalty_account_id)
    REFERENCES loyalty_accounts (id) ON DELETE CASCADE,
  CONSTRAINT fk_ln_company FOREIGN KEY (company_id)
    REFERENCES companies (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Segment 46 — `loyalty_automations` table**

Configurable triggers per loyalty programme.

```sql
CREATE TABLE loyalty_automations (
  id                  INT(11)       NOT NULL AUTO_INCREMENT,
  company_id          INT(11)       NOT NULL,
  loyalty_program_id  INT(11)       NOT NULL,
  trigger_type        ENUM(
                        'win_back',
                        'tier_upgrade',
                        'almost_tier',
                        'expiry_warning',
                        'birthday_bonus'
                      ) NOT NULL,
  is_active           TINYINT(1)    NOT NULL DEFAULT 0,
  threshold_days      INT(11)       NULL DEFAULT NULL
    COMMENT 'For win_back: inactivity days. For expiry_warning: days before expiry.',
  threshold_points    INT(11)       NULL DEFAULT NULL
    COMMENT 'For almost_tier: points gap to trigger notification.',
  message_template    TEXT          NOT NULL,
  last_run_at         TIMESTAMP     NULL DEFAULT NULL,
  created_at          TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_auto_program_type (loyalty_program_id, trigger_type),
  KEY idx_auto_company (company_id),
  CONSTRAINT fk_auto_program FOREIGN KEY (loyalty_program_id)
    REFERENCES loyalty_programs (id) ON DELETE CASCADE,
  CONSTRAINT fk_auto_company FOREIGN KEY (company_id)
    REFERENCES companies (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

### Layer 1 — Understand: Intelligence surface

#### 1a. Lifecycle computation

`LoyaltyService` needs a `computeLifecycleStage()` method. The rules are intentionally simple — based entirely on `last_transaction_at`:

| Stage | Rule |
|---|---|
| `new` | No earn/redeem transaction yet, OR enrolled within last 14 days |
| `active` | Last transaction within 60 days |
| `at_risk` | Last transaction 61–90 days ago |
| `lapsing` | Last transaction 91–180 days ago |
| `churned` | Last transaction 180+ days ago, or no transaction ever and enrolled > 14 days |

This is called from `award()`, `redeemPoints()`, and a nightly console command that sweeps all accounts.

#### 1b. Segment cards on the Overview page

Replace the four static stat cards on the Overview with **six intelligence cards** that each carry a number and an action:

```
┌────────────────────────┐  ┌────────────────────────┐  ┌────────────────────────┐
│ 14                     │  │ 8                      │  │ 3                      │
│ At-Risk Members        │  │ Close to Tier Upgrade  │  │ High-Value Members     │
│ No visit in 60+ days   │  │ Within 100 pts of next │  │ Top 10% by spend       │
│ [View & Act]           │  │ tier — nudge now       │  │ [View Profiles]        │
└────────────────────────┘  └────────────────────────┘  └────────────────────────┘

┌────────────────────────┐  ┌────────────────────────┐  ┌────────────────────────┐
│ KES 18,400             │  │ 67%                    │  │ 2.4×                   │
│ Points Expiring Soon   │  │ Active Member Rate     │  │ Loyalty vs Non-Loyalty │
│ Within next 30 days    │  │ Transacted last 60 days│  │ Avg spend multiplier   │
│ [Send Warnings]        │  │                        │  │                        │
└────────────────────────┘  └────────────────────────┘  └────────────────────────┘
```

Every card with a `[CTA]` button is an action, not just a number.

#### 1c. Segments page — `admin_loyalty_segments`

A dedicated page listing all auto-computed segments. Each segment is a live query, not a stored list.

| Segment | Definition | Actions available |
|---|---|---|
| **New** | Enrolled last 14 days, no earn transaction | Welcome message, verify enrollment |
| **Active** | Transacted in last 60 days | Export, view |
| **At-Risk** | 61–90 days inactive | Win-back SMS |
| **Lapsing** | 91–180 days inactive | Win-back SMS, bonus points offer |
| **Churned** | 180+ days inactive | Re-engagement campaign or archive |
| **Upgrade Candidates** | Within programme-configured threshold of next tier | "Almost there" nudge |
| **Top Spenders** | Top 10% by `total_points_earned` | VIP treatment, exclusive offers |
| **Never Redeemed** | Enrolled 60+ days, balance > 0, zero redemptions | Redemption awareness nudge |
| **Expiring Points** | Points expiry within 30 days (when expiry is enabled) | Expiry warning |

Each segment row shows: count, trend vs last period (up/down), and action buttons.

Route: `GET /{branch}/dashboard/loyalty/segments`
Permission: `VIEW_LOYALTY_MEMBERS` (view), `ENROLL_LOYALTY_MEMBER` (act)

#### 1d. Enriched member list

The Members page already exists. It needs two additions:

1. **Lifecycle badge** on each row — colour-coded dot: Active (green), At-Risk (amber), Lapsing (orange), Churned (red)
2. **Last activity** column — already queried, needs to surface more prominently with "X days ago" formatting

These two additions alone transform the member list from a directory into a management tool.

---

### Layer 2 — Act: Action surface

#### 2a. Automations settings page — `admin_loyalty_automations`

A settings-style page (not a modal) where managers configure which automated triggers are active.

Each automation is a toggle + configuration:

**Win-back trigger**
- Toggle on/off
- Inactivity threshold: `[ 60 ] days`
- Message template: `Hi {first_name}, we miss you at {business_name}! Your {balance} {points_name} are waiting. Visit us today.`
- Preview: shows how many members would receive this if run now

**Almost-there nudge**
- Toggle on/off
- Points gap: send when member is within `[ 100 ]` points of next tier
- Message template: `Hi {first_name}, you're only {gap} {points_name} away from {tier_name}! Come in and earn the rest.`

**Expiry warning**
- Toggle on/off (only visible when `points_expiry_enabled = 1`)
- Days before expiry: `[ 14 ]` days
- Message template

**Tier upgrade notification**
- Toggle on/off
- Fires automatically when `resolveAndUpdateTier()` upgrades a member
- Message template: `Congratulations {first_name}! You've reached {tier_name} status. {perks_description}`

**Birthday bonus**
- Toggle on/off (requires `customers.birth_month` to be populated)
- Bonus points: `[ 50 ]`
- Fires on first transaction in the member's birth month

Route: `GET/POST /{branch}/dashboard/loyalty/automations`
Permission: `MANAGE_LOYALTY_MULTIPLIERS` (reuse — same admin capability level, or add `MANAGE_LOYALTY_AUTOMATIONS` as perm 80)

#### 2b. Segment action — one-click send

From the Segments page, each at-risk/lapsing/churned segment has a **"Send message"** button. This opens a confirmation drawer:

- Shows: segment name, member count, message preview (from the configured template)
- Estimated reach: X members via SMS
- Confirm button → queues SMS sends → records in `loyalty_notifications`

This is not a campaign builder. It is a single-click action with a confirmation step. Fast, direct, measurable.

#### 2c. Manager action cards on Overview (the "Act Now" panel)

The overview page should have a panel — visible only when there are pending actions — titled **"Needs your attention"**:

```
⚠ 14 members haven't visited in 60+ days.
  [View At-Risk Members]  [Send Win-back Message]

⭐ 8 members are close to a tier upgrade.
  [View Upgrade Candidates]  [Send Nudge]

🕐 KES 18,400 in points expires within 30 days.
  [View Members]  [Send Expiry Warning]
```

Each item only appears when the count is > 0. If all is healthy, the panel doesn't render. This is not noise — it is a signal that requires action.

---

### Layer 3 — Measure: Closed-loop reporting

#### 3a. Notification outcomes on the Segments page

Each segment shows: "Last campaign: 14 days ago — 6 of 12 members returned within 14 days (50%)."

This is powered by `loyalty_notifications.returned_at` — set by `LoyaltyService::award()` when a member transacts after receiving a notification. The service checks if an unresolved notification exists within the last 30 days and closes it.

#### 3b. Enriched reports page

The current reports page shows enrollment trend, tier distribution, top earners, redemption rate. Add:

**Retention by lifecycle cohort**
```
Enrolled month       Active now   At-risk   Lapsed   Churned
Jan 2025 (32 members)  18 (56%)     4 (13%)   6 (19%)   4 (13%)
Feb 2025 (28 members)  21 (75%)     4 (14%)   3 (11%)   0
Mar 2025 (19 members)  17 (89%)     2 (11%)   0         0
```
This is the most powerful single view in the module. It shows whether the programme retains people over time. If the 3-month-old cohort is at 40% active and the 1-month-old cohort is at 89%, that is a churn signal. The manager sees it without asking for it.

**Loyalty vs non-loyalty spend comparison**
```
Loyalty members:     avg KES 2,840/month
Non-loyalty customers: avg KES 1,190/month
Multiplier: 2.4×
```
This requires joining `loyalty_accounts` with `pos_transactions` via `customer_id`. It's the single number that justifies the entire programme's existence to ownership.

**Redemption return rate**
```
Members who redeemed this period: 23
Returned within 14 days: 18 (78%)
Returned within 30 days: 21 (91%)
```

**Automation performance**
```
Win-back (last 30 days): 12 sent → 5 returned (42%)
Almost-tier nudge: 8 sent → 6 upgraded (75%)
Expiry warning: 31 sent → 19 redeemed before expiry (61%)
```

---

### Layer 4 — Service additions

These changes must happen in `LoyaltyService`:

1. **`award()` and `redeemPoints()`** — after each call, update `last_transaction_at`, increment `visit_count`, call `computeLifecycleStage()`

2. **`computeLifecycleStage(int $accountId, int $companyId): string`** — pure logic, updates `lifecycle_stage` on the account

3. **`resolveAndUpdateTier()`** — when tier ID changes (upgrade), check if `loyalty_notifications` automation is configured and queue the upgrade notification

4. **`checkAndQueueBirthdayBonus()`** — called by `award()`, checks if this is the member's birth month and no bonus has been given this month yet

5. **`markNotificationReturned(int $accountId, int $companyId)`** — called by `award()`, closes any open notification for this account within the last 30 days

---

### New permissions needed (Segment 47)

```sql
INSERT INTO permissions (name, category, scope, description, action_key) VALUES
('View Loyalty Segments',       'loyalty', 'any', 'View computed member segments',               'VIEW_LOYALTY_SEGMENTS'),
('Manage Loyalty Automations',  'loyalty', 'any', 'Configure and trigger automated messages',    'MANAGE_LOYALTY_AUTOMATIONS'),
('Send Loyalty Messages',       'loyalty', 'any', 'Send SMS or notifications to members',        'SEND_LOYALTY_MESSAGES');
```

Role assignment:
- `VIEW_LOYALTY_SEGMENTS`: Owner, Director, Overall Manager, Branch Manager, Regional Manager
- `MANAGE_LOYALTY_AUTOMATIONS`: Owner, Director, Overall Manager
- `SEND_LOYALTY_MESSAGES`: Owner, Director, Overall Manager, Branch Manager

---

## Build order

This is sequenced by dependency. Each step must be complete before the next.

1. **Segment 44** — add schema columns to `loyalty_accounts` (no code works without `last_transaction_at`)
2. **`LoyaltyService` updates** — `award()` and `redeemPoints()` start maintaining `last_transaction_at`, `visit_count`, `lifecycle_stage`
3. **Backfill script** — one-time console command to populate those columns for all existing accounts from ledger history
4. **Lifecycle badges on Members page** — immediate visible win, zero new pages
5. **Overview intelligence cards** — replace static stats with the six signal cards
6. **"Needs your attention" panel on Overview** — the action surface managers will actually use
7. **Segments 45 + 46** — create schema tables
8. **Segments page** — live computed segments with counts
9. **Automations settings page** — configuration of triggers
10. **One-click send from Segments** — wires the Act layer to the notification tables
11. **Segment 47** — new permissions
12. **`markNotificationReturned()`** in `award()`** — starts closing loops
13. **Retention cohort table on Reports** — the most powerful measure view
14. **Loyalty vs non-loyalty spend metric** — joins to POS, highest-impact single number
15. **Automation performance table on Reports** — closes the Measure loop on Act

---

## What the module feels like when this is done

A manager opens the loyalty dashboard at 9am. They see:

> "14 members haven't visited in 60 days — 3 of them are Gold tier. You have a win-back automation configured. Last time you ran it, 6 of 11 members came back within 2 weeks."

They click "Send Win-back Message." A confirmation drawer shows 14 names and the SMS preview. They confirm.

Two weeks later they come back and see:

> "Win-back campaign (sent 14 days ago): 9 of 14 members returned. KES 34,200 in transactions recovered."

That is Capture → Understand → Act → Measure. In one flow. In one session. Without a data analyst, without a spreadsheet, without asking anyone anything.

That is what this module needs to become.
