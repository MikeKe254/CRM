# Loyalty Module — Design Plan

This document records the full design of the Patronr loyalty module: what is already built, what still needs to be built, and in what order. It is a working reference, not a specification frozen in time. Update it as decisions are made.

---

## 1. Where We Are

The loyalty module is partially built.

The backend is well-established. The schema, service layer, and terminal-side execution are all in place. What is missing is the admin-facing management surface — the pages managers use to configure, monitor, and operate the programme.

Summary of current state:

- Database schema is present and correct (see section 2)
- `LoyaltyService` covers the full execution lifecycle: program retrieval, account enrollment, point calculation, award, redemption, tier resolution
- Terminal loyalty endpoints exist: phone check, enrollment page, account check
- Feature gating is wired (`TenantFeatureAccessService`)
- Company-level module toggle (`companies.loyalty_module_enabled`) is in place
- One settings template (`templates/admin/settings/loyalty.html.twig`) exists for program configuration
- No admin management pages (member directory, tier management, ledger, reports) have been built yet

---

## 2. Current Schema State

Everything below is already in the database. No changes needed to use the existing execution flow.

### loyalty_programs

| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| company_id | INT | FK → companies |
| branch_id | INT NULL | NULL = company-wide program |
| program_name | VARCHAR(100) | Branded name, e.g. "Koma Rewards" |
| points_name | VARCHAR(50) | e.g. "Stars", "Coins", "Points" |
| points_symbol | VARCHAR(10) | e.g. "pts", "⭐" |
| points_per_unit | INT | Points awarded per unit_amount spent |
| unit_amount | DECIMAL(10,2) | KES amount per points_per_unit. Default KES 100 = 1 pt |
| enroll_bonus_points | INT | Points awarded on first enrollment |
| is_active | TINYINT(1) | Pause/resume the programme |
| redemption_enabled | TINYINT(1) | Whether points can be used as payment |
| kes_per_point | DECIMAL(10,2) | KES value per 1 point when redeeming. Default 1.00 |
| max_redemption_pct | TINYINT UNSIGNED | Max % of bill payable by points (0–100). Default 100 |
| auto_award_enabled | TINYINT(1) | Auto-award on M-Pesa callback without cashier |
| auto_enroll_on_payment | TINYINT(1) | Auto-enroll customer when payment is received |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |

### loyalty_tiers

| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| loyalty_program_id | INT | FK → loyalty_programs |
| company_id | INT | |
| name | VARCHAR(50) | e.g. Bronze, Silver, Gold, Platinum |
| min_points | INT | Minimum balance to qualify |
| color | VARCHAR(20) | Tailwind key or hex |
| perks_description | TEXT | Free-text description of tier benefits |
| sort_order | INT | Ascending = lower tiers first |
| created_at | TIMESTAMP | |

### loyalty_accounts

| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| company_id | INT | |
| loyalty_program_id | INT | |
| customer_id | BIGINT UNSIGNED | FK → customers |
| msisdn | VARCHAR(20) | Denormalized for fast lookup |
| points_balance | DECIMAL(12,2) | Fractional points supported |
| total_points_earned | DECIMAL(12,2) | Lifetime total earned |
| total_points_redeemed | DECIMAL(12,2) | Lifetime total redeemed |
| loyalty_tier_id | INT NULL | Current tier |
| enrolled_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |

### loyalty_ledger

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | Immutable — never deleted |
| company_id | INT | |
| loyalty_account_id | INT | |
| pos_transaction_id | INT NULL | NULL for manual adjustments |
| mpesa_payment_id | INT NULL | Set when auto-awarded via M-Pesa callback |
| type | ENUM | `earn`, `redeem`, `adjust`, `enroll_bonus`, `expiry` |
| points | DECIMAL(12,2) | Positive = add, negative = remove |
| balance_after | DECIMAL(12,2) | Account balance at time of this entry |
| note | VARCHAR(255) | Reason for manual adjustments |
| created_by_user_id | INT NULL | Staff ID; NULL = system-generated |
| created_at | TIMESTAMP | |

### Related columns on other tables

**companies**
- `loyalty_module_enabled` TINYINT(1) DEFAULT 1 — master toggle per tenant

**mpesa_configs**
- `auto_award_loyalty` TINYINT(1) — opt-in at M-Pesa config level

**mpesa_payments**
- `loyalty_auto_awarded`, `loyalty_awarded_at`, `loyalty_points_awarded`, `loyalty_account_id`, `customer_id`

**pos_transactions**
- `loyalty_account_id`, `loyalty_points_awarded`, `loyalty_auto_awarded`, `loyalty_auto_awarded_amount`, `loyalty_awarded_at`, `loyalty_award_source`

---

## 3. Schema Additions Still Needed

These are not yet in the database. Create them as new segments before building the features that depend on them.

### Segment 41 — Points expiry support

Add expiry configuration to `loyalty_programs`:

```sql
ALTER TABLE `loyalty_programs`
  ADD COLUMN `points_expiry_enabled` TINYINT(1) NOT NULL DEFAULT 0
    AFTER `auto_enroll_on_payment`,
  ADD COLUMN `points_expiry_days` INT(11) NULL DEFAULT NULL
    COMMENT 'Days of inactivity before points expire. NULL = no expiry.'
    AFTER `points_expiry_enabled`;
```

Expiry processing will be a scheduled job. When a member has no earn/redeem activity for `points_expiry_days`, a ledger entry of type `expiry` is written zeroing their balance.

### Segment 42 — Point multipliers table

Allows configuring time-window or event-based point boosts:

```sql
CREATE TABLE `loyalty_point_multipliers` (
  `id`                  INT(11)       NOT NULL AUTO_INCREMENT,
  `company_id`          INT(11)       NOT NULL,
  `loyalty_program_id`  INT(11)       NOT NULL,
  `name`                VARCHAR(100)  NOT NULL COMMENT 'e.g. Happy Hour, Weekend Boost',
  `multiplier`          DECIMAL(5,2)  NOT NULL DEFAULT 2.00 COMMENT 'e.g. 2.00 = 2x points',
  `applies_on`          SET('mon','tue','wed','thu','fri','sat','sun') DEFAULT NULL,
  `time_from`           TIME          DEFAULT NULL,
  `time_to`             TIME          DEFAULT NULL,
  `valid_from`          DATE          DEFAULT NULL,
  `valid_to`            DATE          DEFAULT NULL,
  `is_active`           TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  KEY `idx_lpm_program` (`loyalty_program_id`),

  CONSTRAINT `fk_lpm_program`  FOREIGN KEY (`loyalty_program_id`) REFERENCES `loyalty_programs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lpm_company`  FOREIGN KEY (`company_id`)         REFERENCES `companies`        (`id`) ON DELETE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Segment 43 — Loyalty permissions

Insert 13 new rows into the `permissions` table (tenant layer) and 2 new rows into the `platform_permissions` table (platform layer). Full SQL with rationale is in **Section 4 — Permissions — Both Layers**.

---

## 4. Permissions — Both Layers

Patronr has two completely separate permission systems. Both apply to the loyalty module. Missing either one leaves the module incorrectly secured.

---

### Layer 1 — Tenant permissions (staff/managers of the tenant company)

These are rows in the `permissions` table and are checked via `CheckPermissionService`. They control what a company's own staff can do inside the loyalty module.

**Segment 43 SQL** — these 13 rows were applied 2026-04-17 (IDs 67–79; `permissions` was at 66 when applied).

```sql
INSERT INTO `permissions`
  (`id`, `name`, `category`, `scope`, `description`, `action_key`) VALUES
(67, 'View Loyalty Programme',    'loyalty', 'any', 'View loyalty program configuration and status',           'VIEW_LOYALTY_PROGRAM'),
(68, 'Edit Loyalty Programme',    'loyalty', 'any', 'Edit program settings: name, rate, redemption config',   'EDIT_LOYALTY_PROGRAM'),
(69, 'View Loyalty Tiers',        'loyalty', 'any', 'View tier definitions for the loyalty programme',        'VIEW_LOYALTY_TIERS'),
(70, 'Manage Loyalty Tiers',      'loyalty', 'any', 'Create, edit, and delete loyalty tiers',                'MANAGE_LOYALTY_TIERS'),
(71, 'View Loyalty Members',      'loyalty', 'any', 'View the enrolled member directory',                     'VIEW_LOYALTY_MEMBERS'),
(72, 'View Member Loyalty Detail','loyalty', 'any', 'View individual member account, balance, and history',   'VIEW_LOYALTY_MEMBER_DETAIL'),
(73, 'Enroll Loyalty Member',     'loyalty', 'any', 'Manually enroll a customer in the loyalty programme',   'ENROLL_LOYALTY_MEMBER'),
(74, 'Adjust Loyalty Points',     'loyalty', 'any', 'Add or deduct points from a member account manually',   'ADJUST_LOYALTY_POINTS'),
(75, 'View Loyalty Ledger',       'loyalty', 'any', 'View company-wide points movement history',              'VIEW_LOYALTY_LEDGER'),
(76, 'Manage Loyalty Multipliers','loyalty', 'any', 'Create, edit, and delete point multiplier rules',       'MANAGE_LOYALTY_MULTIPLIERS'),
(77, 'View Loyalty Reports',      'loyalty', 'any', 'View loyalty analytics and programme reports',           'VIEW_LOYALTY_REPORTS'),
(78, 'Export Loyalty Data',       'loyalty', 'any', 'Export member data and ledger entries to CSV',           'EXPORT_LOYALTY_DATA'),
(79, 'Toggle Loyalty Module',     'loyalty', 'hq',  'Enable or disable the loyalty module for the company',  'TOGGLE_LOYALTY_MODULE');
```

Tell the user what changed; they apply the SQL themselves.

---

### Layer 2 — Platform permissions (platform admins visiting a tenant's loyalty pages)

These are rows in the `platform_permissions` table and are checked via `PlatformCheckPermissionService`.

**How platform admins interact with the loyalty module:**

When a platform admin enters a tenant's dashboard, `AdminBaseController::requireAdmin()` does two things:

1. Verifies they hold `ACCESS_COMPANY_CONTEXT` (platform permission, always checked)
2. **Skips** the tenant permission check entirely — `$this->can->check()` is never called for platform admins at the route-guard level

This means a platform admin with `ACCESS_COMPANY_CONTEXT` can reach any loyalty page regardless of tenant permissions. That is correct and intentional — platform admins are not governed by tenant roles.

However, two things still require explicit platform-level permission checks inside the loyalty controller:

- **Adjusting points** — a sensitive write operation that should be restricted to platform admins holding `PERFORM_COMPANY_SUPPORT_ACTIONS` or the new `MANAGE_COMPANY_LOYALTY` permission. Support staff with read-only access should not be able to adjust a tenant customer's balance.
- **Toggling the module** — toggling `companies.loyalty_module_enabled` from within the tenant dashboard should require `EDIT_COMPANY_SETTINGS`.

For these two cases, the loyalty controller must explicitly call `$this->platformCan->check($session, '...')` instead of relying on the route guard bypass.

**New `platform_permissions` rows** — applied 2026-04-17 (IDs 84–85; `platform_permissions` was at 83 when applied):

```sql
INSERT INTO `platform_permissions`
  (`id`, `name`, `category`, `description`, `action_key`) VALUES
(84, 'view_company_loyalty',   'company_access', 'View loyalty programme data for any company.',                                    'VIEW_COMPANY_LOYALTY'),
(85, 'manage_company_loyalty', 'company_access', 'Manage loyalty settings and perform admin operations for any company.',           'MANAGE_COMPANY_LOYALTY');
```

Tell the user what changed; they apply the SQL themselves.

**New `APP_PERMISSION_MAP` entries** in `PlatformCheckPermissionService`:

These entries are used when Twig calls `can('view_loyalty_members')` with a platform admin session, or when the loyalty controller explicitly calls `$this->can->check($session, ...)` outside of a route guard. The map translates tenant permission keys to the platform action keys that satisfy them.

Add the following block to the `APP_PERMISSION_MAP` constant in `src/Services/Permission/PlatformCheckPermissionService.php`:

```php
// ── Loyalty (tenant-facing, platform admin resolution) ────────────────
'view_loyalty_program'         => ['VIEW_COMPANY_SETTINGS', 'VIEW_COMPANY_LOYALTY'],
'edit_loyalty_program'         => ['EDIT_COMPANY_SETTINGS', 'MANAGE_COMPANY_LOYALTY'],
'view_loyalty_tiers'           => ['VIEW_COMPANY_SETTINGS', 'VIEW_COMPANY_LOYALTY'],
'manage_loyalty_tiers'         => ['EDIT_COMPANY_SETTINGS', 'MANAGE_COMPANY_LOYALTY'],
'view_loyalty_members'         => ['VIEW_COMPANY_ANALYTICS', 'VIEW_COMPANY_LOYALTY'],
'view_loyalty_member_detail'   => ['VIEW_COMPANY_ANALYTICS', 'VIEW_COMPANY_LOYALTY'],
'enroll_loyalty_member'        => ['PERFORM_COMPANY_SUPPORT_ACTIONS', 'MANAGE_COMPANY_LOYALTY'],
'adjust_loyalty_points'        => ['PERFORM_COMPANY_SUPPORT_ACTIONS', 'MANAGE_COMPANY_LOYALTY'],
'view_loyalty_ledger'          => ['VIEW_COMPANY_ANALYTICS', 'VIEW_COMPANY_LOYALTY'],
'manage_loyalty_multipliers'   => ['EDIT_COMPANY_SETTINGS', 'MANAGE_COMPANY_LOYALTY'],
'view_loyalty_reports'         => ['VIEW_COMPANY_ANALYTICS', 'VIEW_COMPANY_LOYALTY'],
'export_loyalty_data'          => ['VIEW_COMPANY_ANALYTICS', 'MANAGE_COMPANY_LOYALTY'],
'toggle_loyalty_module'        => ['EDIT_COMPANY_SETTINGS'],
```

The `check()` method resolves each platform admin against ANY of the listed action keys — holding any one of them is sufficient.

---

### Where to wire explicit platform checks in the loyalty controller

The loyalty controller needs two explicit platform-level gates in addition to the standard `requireAdmin()` call. These are NOT route-guard checks — they are action-level checks placed inside the method body, after the route guard has already passed.

**Point adjustment action:**
```php
// After $session = $this->requireAdmin($request, 'ADJUST_LOYALTY_POINTS')
// and if ($session instanceof Response) return $session;

if ($session->user->isSuperAdmin) {
    if (!$this->platformCan->check($session, 'adjust_loyalty_points')) {
        return $this->error('You do not have permission to adjust loyalty points for this company.', 403);
    }
}
```

**Module toggle action:**
```php
// After $session = $this->requireAdmin($request, 'TOGGLE_LOYALTY_MODULE')
// and if ($session instanceof Response) return $session;

if ($session->user->isSuperAdmin) {
    if (!$this->platformCan->check($session, 'toggle_loyalty_module')) {
        return $this->error('You do not have permission to toggle the loyalty module.', 403);
    }
}
```

All other loyalty actions (view pages, manage tiers, view members, run reports) are accessible to any platform admin with `ACCESS_COMPANY_CONTEXT`. Only the two write actions above need the extra gate.

---

### Summary table

| What controls it | Layer | Service | Action keys |
|---|---|---|---|
| Tenant staff access to loyalty pages | Tenant | `CheckPermissionService` | `VIEW_LOYALTY_PROGRAM`, `EDIT_LOYALTY_PROGRAM`, etc. (IDs 67–79) |
| Platform admin entry to any tenant page | Platform | `PlatformCheckPermissionService` | `ACCESS_COMPANY_CONTEXT` (existing, id 15) |
| Platform admin adjusting points | Platform | `PlatformCheckPermissionService` | `PERFORM_COMPANY_SUPPORT_ACTIONS` or `MANAGE_COMPANY_LOYALTY` |
| Platform admin toggling module | Platform | `PlatformCheckPermissionService` | `EDIT_COMPANY_SETTINGS` |
| Platform admin viewing loyalty in Twig gates | Platform (via map) | `CheckPermissionService` → delegates | `VIEW_COMPANY_LOYALTY`, `VIEW_COMPANY_ANALYTICS` |

---

## 5. Admin Pages — Complete List

These are the pages that need to be built in the admin dashboard. All are under the `/{branch}/dashboard/loyalty` path prefix.

### Page 1 — Loyalty Overview (index)

**Route:** `GET /{branch}/dashboard/loyalty`
**Name:** `admin_loyalty_index`
**Permission:** `VIEW_LOYALTY_PROGRAM`

Summary panel showing programme health at a glance:

- Total enrolled members
- Points awarded this month vs last month
- Points redeemed this month
- Active / paused status badge
- Programme name and tier count
- Shortcut links to Tiers, Members, Reports
- If no programme is configured, prompt to go to Settings to create one

### Page 2 — Programme Settings

**Route:** `GET/POST /{branch}/dashboard/settings/loyalty`
**Name:** `admin_settings_loyalty` (already exists as settings controller action)
**Permission:** `EDIT_LOYALTY_PROGRAM`

Template already exists at `templates/admin/settings/loyalty.html.twig`. Needs wiring to a proper settings controller action if not already done.

Sections:

- **Branding** — programme name, points name, points symbol
- **Earning rule** — KES per point (unit_amount), points per unit
- **Enrollment bonus** — bonus points on first sign-up
- **Redemption** — enabled toggle, KES per point (redemption value), max % of bill
- **Auto-award** — auto-award on M-Pesa callback, auto-enroll on payment
- **Points expiry** — enabled toggle, inactivity days threshold (segment 41)
- **Module status** — pause/resume programme; platform admins can toggle the module toggle

### Page 3 — Tier Management

**Route:** `GET /{branch}/dashboard/loyalty/tiers`
**Name:** `admin_loyalty_tiers`
**Permission:** `VIEW_LOYALTY_TIERS`

Ranked list of tier definitions for the programme. Displayed in sort_order ascending.

Each tier row shows: name, minimum points, color swatch, perks description summary, member count.

Actions (require `MANAGE_LOYALTY_TIERS`):

- Add tier (modal: name, min_points, color, perks description)
- Edit tier (same modal)
- Delete tier — blocked if any members are currently on that tier; show count
- Drag/reorder sort_order (or manual position input)

The tier with `min_points = 0` is the entry tier and cannot be deleted if it is the only tier.

### Page 4 — Member Directory

**Route:** `GET /{branch}/dashboard/loyalty/members`
**Name:** `admin_loyalty_members`
**Permission:** `VIEW_LOYALTY_MEMBERS`

Paginated table of enrolled members.

Columns: customer name, phone (masked unless `VIEW_FULL_CUSTOMER_PHONE`), current tier badge, current balance, total earned, total redeemed, enrolled date, last activity date.

Filters: tier, enrollment date range, balance range, search by name/phone.

Sort: by balance (default desc), by enrollment date, by last activity.

Row click → Member Profile (page 5).

Export button (requires `EXPORT_LOYALTY_DATA`) → CSV with same columns.

### Page 5 — Member Profile

**Route:** `GET /{branch}/dashboard/loyalty/members/{id}`
**Name:** `admin_loyalty_member_profile`
**Permission:** `VIEW_LOYALTY_MEMBER_DETAIL`

Full view of a single loyalty account.

Sections:

- **Identity card** — customer name, phone, gender, enrolled date, customer since
- **Account summary** — current balance (large), tier badge and progress bar to next tier, total earned, total redeemed, total adjustments
- **Ledger** — all points movements in reverse chronological order. Columns: date, type (badge), points (+/-), balance after, source (transaction ref or "Manual"), cashier name, note. Paginated.
- **Actions panel** (permission-gated):
  - Adjust points button → opens adjustment modal (requires `ADJUST_LOYALTY_POINTS`)
  - Enroll manually button if not yet enrolled (requires `ENROLL_LOYALTY_MEMBER`)

### Page 6 — Manual Point Adjustment (modal on member profile)

Not a standalone page — a modal triggered from the Member Profile.

Fields:

- Adjustment type: Add / Deduct (radio or segmented control)
- Amount (decimal, validated against balance if deducting)
- Reason / note (required, max 255 chars)
- Confirmation button

On submit:
- Writes ledger entry of type `adjust`
- Updates `points_balance` atomically
- Re-evaluates tier
- Returns updated account state to refresh the profile view

Permission: `ADJUST_LOYALTY_POINTS`

### Page 7 — Company-Wide Ledger

**Route:** `GET /{branch}/dashboard/loyalty/ledger`
**Name:** `admin_loyalty_ledger`
**Permission:** `VIEW_LOYALTY_LEDGER`

All points movements across all members, reverse chronological.

Columns: date/time, member name, phone (masked), type (earn / redeem / adjust / enroll_bonus / expiry), points, balance after, source (transaction ID or "Manual"), cashier.

Filters: type, date range, member search, branch (if multi-branch enabled and overall context).

Export button (requires `EXPORT_LOYALTY_DATA`).

This page is branch-scoped by default. In overall context (multi-branch on) it shows all branches.

### Page 8 — Point Multipliers

**Route:** `GET /{branch}/dashboard/loyalty/multipliers`
**Name:** `admin_loyalty_multipliers`
**Permission:** `MANAGE_LOYALTY_MULTIPLIERS`

Only shown when segment 42 is applied.

List of active and inactive multiplier rules.

Each rule shows: name, multiplier value (e.g. "2×"), day range, time window, date range, active badge.

Actions:
- Create rule (modal: name, multiplier, days of week, time window, optional date range)
- Edit rule
- Toggle active/inactive
- Delete rule

When a multiplier is active during a transaction, `calculatePoints()` in `LoyaltyService` must apply the highest matching multiplier. This requires a `getActiveMultiplier()` method to be added to `LoyaltyService`.

### Page 9 — Loyalty Reports

**Route:** `GET /{branch}/dashboard/loyalty/reports`
**Name:** `admin_loyalty_reports`
**Permission:** `VIEW_LOYALTY_REPORTS`

Summary analytics for the loyalty programme.

Sections:

- **Enrollment trend** — new enrollments per week/month, chart
- **Tier distribution** — member count per tier, donut chart or bar
- **Top earners** — top 10 members by points earned this period
- **Redemption rate** — % of members who redeemed at least once, total KES value of redemptions
- **Point velocity** — average points per transaction; total awarded vs total redeemed over time
- **M-Pesa auto-award rate** — % of M-Pesa payments that auto-awarded points (when auto-award is on)

Period selector: last 7 days, 30 days, 3 months, custom range.

Branch scope: branch view shows that branch only; overall context shows cross-branch aggregation.

---

## 6. Role Assignment Matrix

Which roles should receive which permissions by default. This governs the seed SQL in segment 43 (role_permissions inserts).

| Permission | Owner | Director | Branch Manager | Cashier | Overall Manager | Regional Manager |
|---|---|---|---|---|---|---|
| VIEW_LOYALTY_PROGRAM | ✓ | ✓ | ✓ | — | ✓ | ✓ |
| EDIT_LOYALTY_PROGRAM | ✓ | ✓ | — | — | ✓ | — |
| VIEW_LOYALTY_TIERS | ✓ | ✓ | ✓ | — | ✓ | ✓ |
| MANAGE_LOYALTY_TIERS | ✓ | ✓ | — | — | ✓ | — |
| VIEW_LOYALTY_MEMBERS | ✓ | ✓ | ✓ | — | ✓ | ✓ |
| VIEW_LOYALTY_MEMBER_DETAIL | ✓ | ✓ | ✓ | — | ✓ | ✓ |
| ENROLL_LOYALTY_MEMBER | ✓ | ✓ | ✓ | — | ✓ | ✓ |
| ADJUST_LOYALTY_POINTS | ✓ | ✓ | — | — | ✓ | — |
| VIEW_LOYALTY_LEDGER | ✓ | ✓ | ✓ | — | ✓ | ✓ |
| MANAGE_LOYALTY_MULTIPLIERS | ✓ | ✓ | — | — | ✓ | — |
| VIEW_LOYALTY_REPORTS | ✓ | ✓ | ✓ | — | ✓ | ✓ |
| EXPORT_LOYALTY_DATA | ✓ | ✓ | — | — | ✓ | — |
| TOGGLE_LOYALTY_MODULE | ✓ | — | — | — | — | — |

These are sensible defaults. Roles can override them per deployment.

---

## 7. What Is Already Built (do not rebuild)

Before starting any page, check this list.

**Backend — fully built:**
- `LoyaltyService` — getProgram, getAccount, findOrEnroll, calculatePoints, award, redeemPoints, resolveAndUpdateTier, getRedemptionConfig, writeLedger (private)
- `LoyaltyAccount` DTO — `fromRow()`, exposes all account fields including tier and next-tier
- `TenantFeatureAccessService` — `FEATURE_EARN_POINTS`, `FEATURE_REDEEM_POINTS`, `FEATURE_REWARD_SETUP`, `FEATURE_LOYALTY_BALANCE` constants
- `LoyaltyController` (terminal) — check-phone JSON, check page, enroll page — these are terminal-facing, not admin-facing

**Frontend — partially built:**
- `templates/admin/settings/loyalty.html.twig` — programme settings form (branding, earning rule, redemption, auto-award toggles). May need the expiry fields from segment 41 added.

**Schema** — see section 2 above, everything listed there is already in place.

---

## 8. Division of Work — Claude and Codex

Two AI agents are working on this codebase together. Both are senior. Claude owns architecture and UI. Codex handles implementation of well-specified work.

The rule is: Claude designs the structure, Codex fills in the body. Nothing Codex produces merges without Claude reviewing it.

---

### What Claude owns

**Architecture and system design**
- All schema decisions — segment SQL, column choices, index decisions
- Service layer design — method signatures, business logic, edge case handling
- `LoyaltyService` additions: `getActiveMultiplier()`, multiplier-aware `calculatePoints()`, any new public methods
- `PlatformCheckPermissionService` — `APP_PERMISSION_MAP` additions, explicit platform check placement
- Permission wiring — deciding which permissions gate which actions, where explicit `platformCan->check()` calls go
- Controller class skeleton — route definitions, method signatures, `requireAdmin()` calls, permission flags
- Feature flag guard pattern — how the module toggle and `TenantFeatureAccessService` check compose

**All UI and templates**
- Every Twig template for every loyalty admin page
- Component design — cards, badges, tier colour display, points formatting, ledger entry type badges
- Form layout and field structure for settings, tier modal, adjustment modal, multiplier modal
- Sidebar integration — loyalty group, link order, permission gates in Twig
- Responsive layout decisions
- Empty state design for no-programme, no-members, no-tiers states

**Review**
- Code review of everything Codex produces before it is committed
- Any changes to existing services or controllers (existing code is Claude's territory)

---

### What Codex handles

Codex implements work that has been fully specified by Claude — clear method signatures, known inputs, known outputs, established patterns to follow.

**Controller query bodies**
Once Claude has defined the controller skeleton and method signatures, Codex implements:
- Member directory paginated query (filters: tier, date range, balance range, phone/name search)
- Company-wide ledger paginated query (filters: type, date range, member search, branch if multi-branch)
- Reports aggregation queries (enrollment trend, tier distribution, top earners, redemption rate)

**Form handlers (save/update)**
Once Claude has defined validation rules:
- Programme settings save handler (maps POST fields → DB update on `loyalty_programs`)
- Tier create, edit, reorder, delete handlers
- Manual point adjustment submit handler (calls `LoyaltyService` methods Claude has wired)
- Multiplier create, edit, activate/pause, delete handlers

**CSV export**
- Member directory export (same filter set as the list page)
- Ledger export (same filter set as the ledger page)

**Repetitive wiring work**
- Route parameter extraction boilerplate inside controller methods
- Standard `$session instanceof Response` guard stubs following the established pattern

---

### Handoff protocol

When Claude finishes a controller skeleton or service method design, the handoff to Codex should include:

1. The exact file path and class
2. The method signature already written
3. What the method must return (type and structure)
4. Which service methods to call (names already exist in `LoyaltyService`)
5. What validation is needed
6. What permission is already wired at the route guard level

Codex does not make architecture decisions. If something is ambiguous, it should flag it rather than guess.

---

### Loyalty module build assignment

| Build step | Owner |
|---|---|
| Segment 41 SQL (expiry columns) | Claude |
| Segment 42 SQL (multipliers table) | Claude |
| Segment 43 SQL (permissions inserts — both layers) | Claude |
| `PlatformCheckPermissionService` APP_PERMISSION_MAP additions | Claude |
| `LoyaltyService::getActiveMultiplier()` | Claude |
| `LoyaltyService::calculatePoints()` multiplier update | Claude |
| `AdminLoyaltyController` class skeleton + all route/permission definitions | Claude |
| Settings template expiry field additions | Claude |
| All page Twig templates (overview, tiers, members, profile, ledger, multipliers, reports) | Claude |
| Adjustment modal template | Claude |
| Sidebar loyalty group | Claude |
| Member directory paginated query body | Codex |
| Ledger paginated query body | Codex |
| Reports aggregation query bodies | Codex |
| Programme settings save handler body | Codex |
| Tier CRUD handler bodies | Codex |
| Manual adjustment submit handler body | Codex |
| Multiplier CRUD handler bodies | Codex |
| CSV export bodies (members + ledger) | Codex |

---

## 9. Build Order (in sequence)

When it is time to build the loyalty admin module, follow this order.

1. Apply segment 41 (expiry columns) — no code, just SQL
2. Apply segment 42 (multipliers table) — no code, just SQL
3. Apply segment 43 (permissions SQL) — no code, just SQL
4. Add expiry fields to the settings template (minor template update)
5. Wire the settings controller action if not already done — the template exists, confirm the route/action saves correctly
6. Build the Loyalty Overview page (page 1) — simple stats query, no dependencies
7. Build Tier Management (page 3) — CRUD on loyalty_tiers, no external dependencies
8. Build Member Directory (page 4) — paginated query, straightforward
9. Build Member Profile (page 5) including the adjustment modal (page 6) — these go together
10. Build Company-Wide Ledger (page 7) — single paginated query with filters
11. Build Point Multipliers (page 8) — requires segment 42 to be applied; also needs `getActiveMultiplier()` added to `LoyaltyService`
12. Build Loyalty Reports (page 9) — leave until last, most query-heavy

Multipliers also require an update to `calculatePoints()` in `LoyaltyService` to call `getActiveMultiplier()` and apply the highest matching boost. Do this at the same time as building page 8.

---

## 10. Controller and Namespace Plan

All loyalty admin pages live in `src/Controller/Admin/LoyaltyController.php` (to be created).

```
App\Controller\Admin\LoyaltyController
  extends AdminBaseController
  injects: LoyaltyService, Connection, TenantFeatureAccessService
```

Routes are registered under `/{branch}/dashboard/loyalty` with the standard `{subdomain}.{domain}` host requirement.

The existing `src/Controller/Terminal/LoyaltyController.php` handles terminal-side endpoints. These are separate and must not be modified when building the admin side.

Settings actions belong in the existing settings controller, not in `LoyaltyController`. The loyalty settings template already exists.

---

## 11. Feature Flag Dependency

The loyalty admin pages are only shown/accessible when:

1. `companies.loyalty_module_enabled = 1`
2. At least one of: `FEATURE_EARN_POINTS`, `FEATURE_REDEEM_POINTS`, `FEATURE_REWARD_SETUP`, or `FEATURE_LOYALTY_BALANCE` is accessible to the company via `TenantFeatureAccessService::canAny()`

If neither condition is met, the loyalty sidebar links should be hidden and direct route access should return a 403 or redirect to dashboard.

Check `LegacyMpesaController::dashboard()` — it already uses `$this->features->canAny(...)` to set `$loyaltyEnabled`. The same pattern should be used in the admin loyalty controller guard.

---

## 12. Sidebar Integration

Loyalty links belong in the sidebar under a "Loyalty" group. The group should only render when the feature is enabled.

Planned sidebar links:

- Overview (icon: award or star)
- Members
- Tiers
- Ledger
- Multipliers
- Reports

These go in the sidebar template under each branch context group (sg-br-*, sg-rg-*, sg-hq-*). The group is conditionally rendered with `{% if loyalty_enabled %}`.

Permissions gate individual links: e.g. Reports link is hidden unless the user has `VIEW_LOYALTY_REPORTS`.

---

## 13. Open Questions / Decisions Not Yet Made

These need a decision before building the relevant parts.

**Multi-program support:** The schema supports multiple programs per company (`branch_id` column allows branch-specific programs). For now, the service always resolves the single most relevant program. When multi-branch is supported at the loyalty level, this needs explicit design. For the first build, treat each company as having one programme.

**Expiry processing:** Segment 41 adds the config columns. The actual expiry job (scheduled or triggered) needs to be designed. Likely a Symfony console command or a scheduled task that runs nightly. Do not build expiry UI until the job is designed.

**Multiplier stacking:** When multiple multipliers match at the same time, the current design says "apply the highest." Confirm this is the intended behaviour before building the multipliers feature.

**Redemption at terminal vs admin:** The `redeemPoints()` method in `LoyaltyService` is already implemented. The admin adjustment modal is separate — it uses the `adjust` ledger type, not `redeem`. Manual admin adjustments should always be type `adjust` so they are distinguishable from genuine redemptions.

**Enrollment from admin:** Page 4 (Member Directory) has an "enroll" button. This triggers the same `findOrEnroll()` path as the terminal. The admin-side enrollment should accept phone + optional first name only. Full customer profile data is managed via the customer profile, not via the loyalty enrollment flow.
