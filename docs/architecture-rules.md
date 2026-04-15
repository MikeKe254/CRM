# Angavu Architecture Rules

This document captures the operating rules we have been converging on while building the system.

It is intentionally shorter than [permissions.md](/C:/xampp/htdocs/angavu/docs/permissions.md). The goal here is not to document every table or service, but to lock the product and architecture direction so future work stays consistent.

## 1. Core Separation

Angavu has two distinct authority layers:

- Platform layer: SaaS operators and platform admins.
- Tenant layer: company users working inside one tenant.

These are different identities with different powers.

Rules:

- A `platform_owner` is not the same thing as a tenant `Owner`.
- A platform `Director` is not the same thing as a tenant `Director`.
- Platform permissions must never be inferred from tenant roles.
- Tenant permissions must never be inferred from platform roles.
- Platform owner has explicit unrestricted platform authority.
- Tenant owner has highest authority only inside that tenant company.

## 2. Source Of Truth

The system should always have one clear source of truth per concern.

Use these sources:

- Role structure and authority flow: `role_hierarchy`
- Tenant permission checks: `CheckPermissionService` + `PermissionService`
- Platform permission checks: `PlatformCheckPermissionService`
- Branch access resolution: `BranchPermissionService`
- Feature entitlement: `TenantFeatureAccessService`
- Platform-wide release of modules/features: `PlatformFeatureService`
- Company context and session identity: `AuthResult`

Rules:

- UI must not invent its own access logic.
- Controllers must enforce the same rules the UI displays.
- If a page needs to know whether something is available, it should ask the appropriate service rather than duplicate SQL rules locally.

## 3. Authority Model

There are two kinds of authorization:

- Visibility: whether the actor is allowed to see a thing.
- Mutation: whether the actor is allowed to change a thing.

These must be treated separately.

Rules:

- Seeing a page does not automatically mean the actor can edit it.
- Lists, sidebars, drawers, buttons, and direct endpoints must agree.
- Platform admins must be checked against platform permissions.
- Tenant users must be checked against tenant permissions.
- Platform owner bypasses platform permission checks explicitly.
- No other platform admin gets implicit owner powers.

## 4. Tenant Role Hierarchy

The tenant organization must follow a real chain of command.

Current intended full chain:

- Owner
- Director
- Overall Manager
- Regional Manager
- Branch Manager
- Assistant Manager
- Department Manager
- Supervisor
- Operational roles

Current intended single-branch active chain:

- Owner
- Director
- Branch Manager
- Assistant Manager
- Department Manager
- Supervisor
- Operational roles

Operational roles currently include roles such as:

- Cashier
- Viewer
- Service Staff
- Retail Staff
- Housekeeping
- Car Wash Attendant

Support Functions is a branch support role under Assistant Manager by default.

Rules:

- Authority flows downward only.
- A user cannot manage a user or role at the same level or above.
- A user cannot grant, revoke, edit, replace, or delete at the same level or above.
- A user cannot toggle dashboard or POS access for self.
- A user cannot edit self through admin user-management flows.
- Owner cannot be removed directly; owner must be replaced.
- Director is multi-seat.
- Single-seat leadership roles should use replace instead of stacking.
- `Overall Manager` normally reports to `Director`, but some companies may explicitly place `Overall Manager` under `Owner`.
- `Support Functions` normally sits parallel to `Department Manager` under `Assistant Manager`, but some companies may explicitly place it under `Department Manager`.

## 5. Hierarchy Enforcement Principle

Hierarchy must be enforced everywhere, not only on org pages.

It applies to:

- Org chart assignment and replacement
- User create/edit/delete
- User role assignment
- Dashboard/POS toggles
- Role create/edit/delete
- Role hierarchy editing
- Role permission assignment and revocation
- Visibility of higher leadership users and roles

Rules:

- The same hierarchy rule should be reused in all these flows.
- Backend enforcement is required even if the UI hides the action.
- Effective hierarchy should resolve branch role copies back to their source/template role where needed.

## 6. Single-Branch vs Multi-Branch

Single-branch mode and multi-branch mode are different product states.

When multi-branch is disabled:

- `/head-office-branch/` is the operational tenant entry point.
- `/overall/` is not a valid operational context.
- `/hq/` is not used as a separate operational context.
- Branch picker should reflect only real branch access.
- Roles that only make sense in multi-branch operations should not appear as active operational roles.

Current single-branch visibility intention:

- Keep Owner and Director visible where appropriate.
- Hide Overall Manager and Regional Manager from normal tenant role/user/admin flows when multi-branch is disabled.
- Branch Manager remains the active branch leadership role.
- The effective visible leadership chain should read as `Owner -> Director -> Branch Manager`.
- Owner and Director should still appear to eligible viewers across tenant people/role/admin pages.

When multi-branch is enabled:

- HQ, overall, regional, and branch contexts may all exist.
- Region-scoped roles become operationally meaningful again.
- Branch access can expand through hierarchy and subtree rules.

## 7. Branch Scope Rules

Each role must have a meaningful scope:

- HQ
- Region
- Branch
- Any

Rules:

- `branch_id` is the primary operational context of the system.
- Operational records should be linked to a `branch_id` first.
- `company_id` is still important, but it is secondary and may be treated as optional where it can be derived through the branch relationship.
- New operational tables and flows should be designed branch-first, not company-first.
- URLs, sessions, permissions, terminal state, checkout state, payment configs, and activity logs should prefer branch-scoped context wherever possible.
- If a record belongs to real day-to-day tenant operations, it should normally carry a `branch_id`.
- A record that has only `company_id` but no `branch_id` should be treated as an exception that needs explicit justification.
- Scope must match the role’s place in the hierarchy.
- Users may only create or edit roles in scopes their own authority allows.
- UI selectors for scope must be filtered by what the actor is allowed to use.
- Branch-scoped screens should not silently leak HQ-only or region-only data.

## 8. Roles: System vs Custom

System roles are part of the platform’s organizational model.
Custom roles are tenant-defined extensions.

System roles should:

- Always exist when required by the model
- Be protected from tenant deletion
- Be protected from tenant renaming when they are structural roles
- Remain assignable when hierarchy and permissions allow

Structural system roles currently include at least:

- Owner
- Director
- Overall Manager
- Regional Manager
- Branch Manager
- Assistant Manager
- Department Manager
- Supervisor
- Support Functions

Rules:

- Structural system roles are part of the business model, not ad hoc tenant data.
- Custom roles may exist above Supervisor level, but must still obey scope and authority rules.
- Custom roles must not replace the meaning of the structural system roles.
- Custom management roles should sit under an existing structural parent rather than redefining the top chain.

Examples of acceptable custom roles above Supervisor level:

- `Operations Lead` under Branch Manager
- `Front Office Manager` under Assistant Manager or Department Manager
- `Kitchen Manager` under Assistant Manager or Department Manager
- `Inventory Manager` under Assistant Manager or Department Manager
- `Events Coordinator` under Branch Manager

Examples that should normally remain structural system roles instead of custom roles:

- Owner
- Director
- Overall Manager
- Regional Manager
- Branch Manager

## 9. Permissions Model

Permissions govern capability.
Hierarchy governs who may manage whom.
Both must pass for a mutating action.

Rules:

- Tenant user must hold the relevant tenant permission.
- Platform admin must hold the relevant platform permission.
- Platform owner bypasses platform permission checks explicitly.
- Tenant users may only grant or revoke a permission they personally hold.
- Being allowed to manage permissions does not override hierarchy.
- Dedicated permissions are preferred for dedicated surfaces.

Examples already split this way:

- `VIEW_SETTINGS` / `EDIT_SETTINGS`
- `VIEW_ROLES_HIERARCHY` / `EDIT_ROLES_HIERARCHY`
- `VIEW_COMPANY_ORG_CHART` / `MANAGE_COMPANY_ORG_CHART`

## 10. Feature Entitlement Model

Feature access has three layers:

1. Platform release
2. Plan entitlement
3. Tenant override

Effective availability should be resolved in this order:

- If the platform has not released the module/feature, it is unavailable.
- Otherwise, the company gets the plan default.
- An active tenant override may temporarily enable or disable that default.

Rules:

- The subscription plan remains the company’s real plan.
- Overrides do not automatically change the plan to `Custom`.
- Override is an exception, not a plan identity.
- Override must include:
  - reason
  - actor
- Override may be temporary or plan-long-lived:
  - temporary override uses an expiry
  - long-lived override may remain active until the current subscription ends
  - when that subscription ends, the override becomes inactive
  - if the subscription later restarts, the override should restore automatically unless it was explicitly removed
- Expired overrides should naturally fall back to plan behavior.
- Tenant configuration pages control how an enabled feature behaves, not whether the tenant owns it.
- The override system should support both:
  - feature availability overrides
  - plan limit overrides

## 11. Plan Limits

Quantitative limits are separate from feature availability.

Examples:

- `max_users`
- `max_branches`
- `max_products`

Rules:

- Limits come from `plan_limits` by default.
- A tenant override may change a plan limit without changing the tenant’s actual plan.
- Limit overrides should follow the same auditability standard as feature overrides:
  - reason
  - actor
  - optional expiry
- Long-lived limit overrides may remain active until the current subscription ends.
- When the subscription ends, long-lived limit overrides become inactive.
- If the subscription later restarts, those long-lived limit overrides should restore automatically unless they were explicitly removed.
- Limit overrides should be applied per `limit_key`.
- Limit overrides should be shown alongside feature overrides in the support/admin experience.
- The effective value should be:
  - override value if an active override exists
  - otherwise the plan value
- `-1` continues to mean unlimited unless we later formalize a different sentinel.

## 12. Settings Ownership

Tenant settings and support overrides are different concerns.

Tenant settings pages:

- Control company configuration
- Use tenant permissions `VIEW_SETTINGS` and `EDIT_SETTINGS`
- For platform admins, map through platform company-settings permissions

Modules page:

- Is a platform-admin support tool, not a normal tenant settings page
- Uses platform permission `MANAGE_TENANT_OVERRIDES`
- Applies time-bound per-tenant overrides
- Should remain plan-aware

Rules:

- Tenant users should not directly override subscription entitlement.
- Not all platform admins should be allowed to override plans.
- Platform owner still bypasses the platform permission check explicitly.

## 13. Visibility Rules For Sensitive Identities

Some identities should not be broadly visible across tenant pages.

Rules:

- Owner and Director tenant records should only be visible to:
  - themselves where relevant
  - Owner/Director level viewers
  - authorized platform viewers where intended
- Platform admin activity should not be visible on tenant pages without the specific platform permissions.
- Platform owner activity should not be visible without the explicit owner-log permission, except to the platform owner.
- Owner-only platform permissions should not be shown to non-owner platform admins.

## 14. Auditing Principle

Activity logging is paramount. Every controller action that mutates state must produce an activity log entry. This is not optional and not limited to sensitive actions — it applies universally across the system.

This includes at least:

- Permission changes
- Role changes
- Org chart changes
- User create/edit/delete/access toggles
- Terminal authorization/revocation/reactivation
- Tenant feature overrides
- Tenant plan limit overrides
- Settings changes (company, localisation, payment, loyalty, modules)
- Payment config changes (M-Pesa, cash, bank, Pesapal)
- Branch create/edit/delete/move
- Department create/edit/delete
- Product create/edit/delete/archive
- Customer create/edit/delete/merge
- Any financial or transactional action
- Any action that grants, revokes, or modifies access

Rules:

- Every mutating controller action must log before or after the mutation succeeds.
- Activity logs are non-negotiable — a controller without logging for a mutating action is considered incomplete.
- The audit trail must capture at minimum: actor (user id + name), target (entity type + id), action (what was done), `branch_id` as the primary operational context wherever applicable, `company_id` where needed, and timestamp.
- Old value / new value should be captured wherever practical for settings and configuration changes.
- Visibility of audit logs must respect both tenant and platform sensitivity rules.
- Override history should live in activity logs as the full source of truth.
- Support/admin override pages may surface only a small recent slice of override history, such as the latest five entries, while older history remains available in the platform admin console.
- Logging failures must not silently swallow the primary action — log errors should be recorded but not cause the main operation to fail.

## 15. UI Discipline

The UI should reflect the same reality the backend enforces.

Rules:

- Do not show actions that will definitely fail.
- Do not show hidden-ineligible scopes, roles, or parent options.
- Do not show dead-end features on terminal or dashboard surfaces.
- If a role/feature is disabled by current mode or entitlement, either hide it or present it clearly as unavailable.
- Support/admin override pages may expose module-level and feature-level controls, but the default experience should stay understandable.

## 16. Soft Delete Pattern

Records that carry operational history, audit value, or are referenced by transactions must never be hard-deleted.

Applies to: payment configs (mpesa_configs, bank_transfer_configs, pesapal_configs), branches, users, roles, and any entity that may be referenced by transactional data.

Rules:

- Soft delete is implemented via a `deleted_at DATETIME DEFAULT NULL` column.
- A record with `deleted_at IS NOT NULL` is considered deleted and must be excluded from all normal queries.
- Hard `DELETE` statements are not permitted on soft-deleteable tables.
- Where a unique constraint would block re-creation of the same identifier after deletion (e.g. M-Pesa `shortcode`), the deleted row's identifier must be mangled at deletion time: prefix with `_deleted_{id}_` to free the constraint.
- Deleted records remain in the database for audit and historical query purposes.
- The deletion actor and timestamp are the minimum audit fields required.

## 17. Loyalty Programme Scope

Loyalty programmes are branch-scoped, not company-wide.

Rules:

- `loyalty_programs` carries a `branch_id` and a `company_id`.
- A loyalty programme is active for one branch at a time, not globally.
- `LoyaltyService::getProgram($companyId, $branchId)` always prefers an exact branch match. A company-wide fallback (no `branch_id`) is only used when an explicit `branchId` is not supplied by the caller — never silently.
- Callback-side award logic in `patronrapis` must use `branch_id` from the `mpesa_payments` row. If `branch_id` is null, the callback award is skipped — a branch-less payment cannot be attributed to a programme.
- `loyalty_accounts`, `loyalty_ledger`, and all award state fields follow the same branch-first principle.
- If a company wishes to run a single programme across all branches, it should create one programme per branch with the same configuration rather than sharing a single row.
- Changing loyalty to company-wide would require revisiting `LoyaltyAutoAwardService` (patronrapis), `LoyaltyService` (angavu), `TransactionRecordService`, and all loyalty settings UI before rollout.

## 18. Current Open Questions

These are the areas where the architecture would benefit from your explicit intent.

There are no unresolved architecture questions captured here right now. New behavior decisions should be added to this document before they spread across multiple pages or services.

## 19. Working Principle Going Forward

The build style is valid if we keep turning discovered behavior into explicit rules.

That means:

- discover through usage
- consolidate into a written rule
- enforce in one backend path
- reflect it consistently in UI

If a new feature does not fit this document cleanly, the document should be updated before the rule spreads across multiple controllers and templates.
