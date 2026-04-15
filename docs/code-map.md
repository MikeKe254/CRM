# Angavu Code Map

This document is a practical map of the main application classes in `src/`.

It focuses on what each class is responsible for, so someone working in the codebase can quickly find the right place to change behavior.

It covers:

- Controllers
- Services
- Commands
- Twig helpers/extensions
- DTOs
- Domain exceptions

It does not try to replace detailed implementation reading.

## Controllers

### Root and public controllers

- `App\Controller\PublicController`
  Public marketing pages such as home, about, pricing, and features.

- `App\Controller\DashboardController`
  Main tenant dashboard entry and tenant-side routing decisions, especially around branch context and multi-branch behavior.

- `App\Controller\TenantEntryController`
  Handles the first entry into a tenant context and helps route a user into the correct dashboard/branch flow.

- `App\Controller\CustomersController`
  Tenant-facing customer pages outside the deeper admin structure.

- `App\Controller\LegacyMpesaController`
  Legacy terminal/dashboard controller that still powers part of the POS runtime and terminal landing experience.

- `App\Controller\MinimalController`
  Minimal/demo surface with simple routes used for lightweight pages or environment checks.

- `App\Controller\ErrorController`
  Explicit error pages such as 403, 404, and 500.

- `App\Controller\TestLoginController`
  Disposable test login controller used for temporary debugging or manual test flows.

- `App\Controller\SeedUsersController`
  Disposable seed helper used to create or seed users during development/testing.

### Auth controllers

- `App\Controller\Auth\LoginController`
  Tenant dashboard login flow, including branch/multi-branch checks and post-login routing.

- `App\Controller\Auth\PosLoginController`
  POS/terminal login and terminal authorization flow, including terminal-to-branch association.

- `App\Controller\Auth\BranchPickerController`
  Branch selection experience after login when a user has access to more than one branch/context.

### Admin base and tenant admin controllers

- `App\Controller\Admin\AdminBaseController`
  Base class for tenant admin controllers. Provides `requireAdmin()`, deny helpers, and shared tenant admin access rules.

- `App\Controller\Admin\ActivityLogController`
  Tenant activity log page and filters, with sensitivity rules for owner/director/platform activity visibility.

- `App\Controller\Admin\AreaController`
  CRUD and management actions for company areas.

- `App\Controller\Admin\BranchController`
  Branch and branch-structure management in the tenant admin area.

- `App\Controller\Admin\DepartmentController`
  CRUD and management actions for company departments.

- `App\Controller\Admin\OfficeUserController`
  Office-user specific management surface. Appears to be a narrower user-management controller for office-context users.

- `App\Controller\Admin\OrgController`
  Organization chart page and leadership assignment logic, including replace/remove rules and hierarchy enforcement.

- `App\Controller\Admin\PermissionController`
  Tenant permission catalogue/list views and related admin permission surfaces.

- `App\Controller\Admin\RoleController`
  Tenant role list, role CRUD, role detail pages, permission assignment, and permission-constraint editing.

- `App\Controller\Admin\RoleHierarchyController`
  Role hierarchy tree page and parent/level editing for tenant roles.

- `App\Controller\Admin\SettingsController`
  Tenant settings pages such as company, localisation, payment, loyalty, and platform-admin-only tenant override screens.

- `App\Controller\Admin\TerminalController`
  Tenant terminal administration, including listing terminals and revoking/reactivating them.

- `App\Controller\Admin\UserController`
  Main tenant user-management controller: list, add, edit, delete, role assignment, dashboard/POS access toggles.

### Terminal controllers

- `App\Controller\Terminal\CheckoutController`
  Multi-step POS checkout flow, payment step orchestration, and terminal checkout state handling.

- `App\Controller\Terminal\LoyaltyController`
  POS loyalty flows such as enrollments, loyalty lookups, and loyalty-driven terminal interactions.

- `App\Controller\Terminal\SessionController`
  Terminal session lifecycle, likely including opening/resuming/ending cashier sessions.

- `App\Controller\Terminal\TransactionController`
  Terminal transaction operations and transaction-facing runtime actions.

### Platform controllers

- `App\Controller\Platform\PlatformBaseController`
  Base class for platform admin controllers. Provides `requirePlatform()` and shared platform access/deny behavior.

- `App\Controller\Platform\AccessController`
  Platform access management, especially platform roles, permissions, and who can assign platform authority.

- `App\Controller\Platform\ActivityLogController`
  Platform activity log surfaces for platform-admin actions and sensitive platform audit views.

- `App\Controller\Platform\AuthController`
  Platform admin authentication flow.

- `App\Controller\Platform\CompanyController`
  Platform-side company management, including tenant access and company-level support/admin views.

- `App\Controller\Platform\DashboardController`
  Platform admin dashboard landing and high-level platform metrics/entry.

- `App\Controller\Platform\ModuleController`
  Platform module management and likely platform-level release/status views for modules/features.

- `App\Controller\Platform\OwnerConfigController`
  Platform-owner-only configuration surfaces such as core permissions/features/payment-method definitions.

- `App\Controller\Platform\PlanController`
  Subscription plan management, plan features, and plan quantitative limits.

- `App\Controller\Platform\PlatformAdminController`
  Platform admin user management.

### Dev controllers

- `App\Controller\Dev\MpesaSimulatorController`
  Developer-only M-Pesa simulation/testing surface.

## Services

### Authentication and identity

- `App\Services\Auth\AuthService`
  Main tenant auth/session service. Creates, validates, resolves, and persists tenant user sessions and related company/user snapshots.

- `App\Services\Auth\PlatformAuthService`
  Platform-admin authentication and session handling.

- `App\Services\User\UserTypeService`
  Resolves or normalizes user types such as office, branch, or mixed access.

### Permission and access control

- `App\Services\Permission\CheckPermissionService`
  Read-only tenant permission evaluation service. Answers “can this session do X?” and returns permission constraints where needed.

- `App\Services\Permission\PermissionService`
  Mutating tenant permission service. Assigns/revokes permissions, manages permission constraints, and enforces delegation rules.

- `App\Services\Permission\PlatformCheckPermissionService`
  Platform permission evaluation service, including platform-owner bypass behavior and app-to-platform permission mapping.

- `App\Services\Branch\BranchPermissionService`
  Single source of truth for tenant branch access, effective branch authority, and branch-aware permission resolution.

- `App\Services\Terminal\TerminalBranchAccessService`
  Validates that a terminal flow is operating in the correct branch and that the terminal/user/URL branch relationship is valid.

### Role and hierarchy

- `App\Services\Role\RoleHierarchyService`
  Reads and applies the explicit role hierarchy, including parent relationships, level checks, and authority comparisons.

- `App\Services\Role\RoleHierarchyValidator`
  Validates role hierarchy integrity such as bad parents, cycles, or scope mismatches.

### Feature and subscription entitlement

- `App\Services\Feature\PlatformFeatureService`
  Platform-wide release gate for modules and features. Answers whether the platform has globally released a module/feature at all.

- `App\Services\Feature\TenantFeatureAccessService`
  Resolves effective tenant feature availability using platform release, plan features, and tenant feature overrides.

### Branch, company, and setup

- `App\Services\Branch\BranchHierarchyService`
  Owns branch tree structure operations such as parent/child navigation and branch hierarchy calculations.

- `App\Services\Branch\BranchResolverService`
  Resolves a branch slug from the URL into a validated branch context and checks whether the current user may access it.

- `App\Services\Company\CompanySetupService`
  Bootstraps new companies with their required initial structure, including HQ/head-office setup and other defaults.

- `App\Services\Company\DepartmentService`
  CRUD/business logic for departments.

- `App\Services\Company\AreaService`
  CRUD/business logic for areas.

### Customers and loyalty

- `App\Services\Customer\CustomerService`
  Canonical customer service for the `customers` table and customer record operations.

- `App\Services\Customer\CustomerMetricsService`
  Aggregates customer metrics and customer-related reporting values.

- `App\Services\Loyalty\LoyaltyService`
  Core loyalty business logic: enrollments, balances, awarding points, redemption rules, and tier resolution.

### Payments, terminals, and checkout

- `App\Services\Payment\MpesaApiService`
  Safaricom M-Pesa integration service for API calls and request/response handling.

- `App\Services\Payment\PaymentConfigService`
  Loads, decrypts, and resolves company/branch payment method configuration into usable runtime objects.

- `App\Services\Patronr\CheckoutService`
  Manages the checkout draft lifecycle and checkout state transitions for POS sales.

- `App\Services\Patronr\TransactionRecordService`
  Creates and manages persisted POS transaction records.

### Logging, encryption, and support

- `App\Services\ActivityLog\UserActivityLogService`
  Tenant/business activity logging service used to record auditable user/admin actions.

- `App\Services\Encryption\CredentialEncryptionService`
  Encrypts and decrypts stored API credentials or other sensitive configuration secrets.

## Commands

- `App\Command\ValidatePermissionsCommand`
  Console command that validates the permission system, especially hierarchy consistency and permission configuration correctness.

## Twig extensions and helpers

- `App\Twig\PermissionExtension`
  Exposes permission checks to Twig, including tenant permission checks, platform permission checks, super-admin checks, and platform-owner checks.

- `App\Twig\DomainExtension`
  Twig helper for domain-aware rendering, likely used for tenant/platform host or URL convenience methods.

## DTOs

### Auth DTOs

- `App\Services\Auth\DTO\AuthResult`
  The resolved authenticated session object returned by auth guards.

- `App\Services\Auth\DTO\AuthUser`
  Lightweight authenticated user snapshot embedded inside `AuthResult`.

- `App\Services\Auth\DTO\AuthCompany`
  Lightweight company/tenant snapshot embedded inside `AuthResult`.

### Branch DTOs

- `App\Services\Branch\DTO\AuthorityScope`
  Represents the authority scope a user has in a branch context.

- `App\Services\Branch\DTO\BranchContext`
  Represents a resolved active branch context from the URL/session.

- `App\Services\Branch\DTO\BranchNode`
  Represents one node in the branch tree.

- `App\Services\Branch\DTO\BranchPickerResult`
  Represents the result payload used by the branch picker flow.

- `App\Services\Branch\DTO\EffectivePermissions`
  Represents a computed set of effective permissions for a branch-aware context.

### Payment, loyalty, and checkout DTOs

- `App\Services\Payment\DTO\PaymentConfig`
  Resolved runtime payment configuration ready for use by payment/checkout flows.

- `App\Services\Loyalty\DTO\LoyaltyAccount`
  Resolved loyalty account payload including account state, branding, and tier context.

- `App\Services\Patronr\DTO\CheckoutDraft`
  In-progress POS checkout state object used while a sale is being built.

### Permission DTOs

- `App\Services\Permission\DTO\PermissionResult`
  Structured result object returned by permission mutation methods.

## Exceptions

### Auth and feature exceptions

- `App\Services\Auth\Exception\AuthException`
  Raised for authentication failures during auth/session operations.

- `App\Services\Feature\Exception\FeatureNotAvailableException`
  Raised when a company tries to use a feature that its current entitlement does not allow.

### Branch exceptions

- `App\Services\Branch\Exception\BranchAccessDeniedException`
  Branch access was denied for the current user.

- `App\Services\Branch\Exception\BranchHasActiveUsersException`
  Branch operation failed because the branch still has active users or assignments.

- `App\Services\Branch\Exception\BranchInactiveException`
  Branch operation failed because the target branch is inactive.

- `App\Services\Branch\Exception\BranchSlugTakenException`
  Branch creation/update failed because the slug is already in use.

- `App\Services\Branch\Exception\NoBranchAssignmentException`
  User or session has no valid branch assignment for the requested operation.

## Suggested Reading Order

If you are onboarding into the codebase, this order will make the system easiest to understand:

1. `App\Services\Auth\AuthService`
2. `App\Controller\Admin\AdminBaseController`
3. `App\Services\Permission\CheckPermissionService`
4. `App\Services\Permission\PermissionService`
5. `App\Services\Branch\BranchPermissionService`
6. `App\Services\Role\RoleHierarchyService`
7. `App\Services\Feature\TenantFeatureAccessService`
8. `App\Controller\Admin\UserController`
9. `App\Controller\Admin\RoleController`
10. `App\Controller\Admin\SettingsController`

## Notes

- `TestLoginController` and `SeedUsersController` are clearly disposable and should not be treated as permanent architecture.
- The terminal flow currently spans both `LegacyMpesaController` and the `Terminal\*Controller` classes, so terminal behavior is not yet owned by a single clean surface.
- Platform and tenant concerns are deliberately separate; if a class appears to cross both, it deserves careful review.
