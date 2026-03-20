# Angavu Permissions System — Complete Developer Reference

---

## Table of Contents

1. [Architecture Overview](#1-architecture-overview)
2. [Database Schema](#2-database-schema)
3. [Auth Session (AuthResult)](#3-auth-session-authresult)
4. [Tenant Permission System](#4-tenant-permission-system)
5. [Platform Permission System](#5-platform-permission-system)
6. [CheckPermissionService — Checking Access](#6-checkpermissionservice--checking-access)
7. [PermissionService — Managing Permissions](#7-permissionservice--managing-permissions)
8. [PlatformCheckPermissionService](#8-platformcheckpermissionservice)
9. [Controller Guards](#9-controller-guards)
10. [PermissionResult DTO](#10-permissionresult-dto)
11. [Twig — Gating UI Elements](#11-twig--gating-ui-elements)
12. [Constraints System](#12-constraints-system)
13. [Audit Logging](#13-audit-logging)
14. [Adding New Permissions](#14-adding-new-permissions)
15. [Common Patterns — Cookbook](#15-common-patterns--cookbook)
16. [Error Reference](#16-error-reference)
17. [File Map](#17-file-map)

---

## 1. Architecture Overview

Angavu has a **two-layer permission system**. The layers are completely separate — different tables, different services, different users.

```
┌─────────────────────────────────────────────────────────────┐
│                     PLATFORM LAYER                          │
│  platform_admins → platform_roles → platform_permissions    │
│  Managed via: PlatformCheckPermissionService                │
│  Controller guard: requirePlatform()                        │
│  Who: SaaS operators, system accounts                       │
└──────────────────────────┬──────────────────────────────────┘
                           │ platform admins can override
                           │ system roles inside tenants
┌──────────────────────────▼──────────────────────────────────┐
│                      TENANT LAYER                           │
│  users → roles (company-scoped) → permissions               │
│  Managed via: CheckPermissionService + PermissionService    │
│  Controller guard: requireAdmin()                           │
│  Who: company staff (dashboard + POS)                       │
└─────────────────────────────────────────────────────────────┘
```

**Key rule:** A person is either a `users` record (tenant user) OR a `platform_admins` record (platform admin). Never both. The `AuthResult` session object carries which layer a request belongs to, and every service branches on that.

**Super admin shortcut:** Platform admins always bypass tenant permission checks. If `isPlatformAdminSession($session)` returns true, `CheckPermissionService::check()` immediately delegates to the platform layer instead of querying tenant tables.

---

## 2. Database Schema

### Tenant Layer

#### `permissions` — the permission catalogue
```sql
id          INT AUTO_INCREMENT PRIMARY KEY
name        VARCHAR(120) NOT NULL UNIQUE   -- human label: 'view_transactions'
category    VARCHAR(100) NOT NULL          -- grouping slug: 'dashboard', 'stk', 'mpesa'
action_key  VARCHAR(120) NOT NULL UNIQUE   -- machine key: 'VIEW_TRANSACTIONS' (always UPPERCASE)
description VARCHAR(255)
created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
```
`action_key` is the stable machine identifier. Use it for code-level checks. `name` is the human label and may be edited. Both are accepted by `CheckPermissionService::check()`.

---

#### `roles` — tenant-scoped roles
```sql
id             INT AUTO_INCREMENT PRIMARY KEY
company_id     INT NOT NULL   -- FK → companies.id (scopes role to one tenant)
name           VARCHAR(120) NOT NULL
description    VARCHAR(255)
is_system_role TINYINT(1) DEFAULT 0   -- 1 = locked; only platform admins can modify
created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP

UNIQUE KEY (company_id, name)
```
`is_system_role = 1` means the role ships with the platform (e.g. "Owner", "Super Admin"). Tenant users cannot assign or revoke permissions on system roles — only platform admins can.

---

#### `role_permissions` — assigns permissions to roles
```sql
id            INT AUTO_INCREMENT PRIMARY KEY
role_id       INT NOT NULL   -- FK → roles.id
permission_id INT NOT NULL   -- FK → permissions.id
UNIQUE KEY (role_id, permission_id)
```

---

#### `user_roles` — assigns roles to users (within a tenant)
```sql
id         INT AUTO_INCREMENT PRIMARY KEY
user_id    INT NOT NULL   -- FK → users.id
role_id    INT NOT NULL   -- FK → roles.id
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
UNIQUE KEY (user_id, role_id)
```

---

#### `constraints` — defines available constraint types
```sql
id             INT AUTO_INCREMENT PRIMARY KEY
name           VARCHAR(120) NOT NULL
constraint_key VARCHAR(100) NOT NULL UNIQUE   -- e.g. 'max_hours_history'
constraint_type ENUM('text','number','currency','list','boolean','date','time','percentage')
description    VARCHAR(255)
```

---

#### `permission_constraints` — maps which constraints are available on which permissions
```sql
id            INT AUTO_INCREMENT PRIMARY KEY
permission_id INT NOT NULL   -- FK → permissions.id
constraint_id INT NOT NULL   -- FK → constraints.id
is_required   TINYINT(1) DEFAULT 0   -- 1 = must be set when assigning this permission
default_value VARCHAR(255)
```

---

#### `role_permission_constraints` — stores constraint values per role_permission
```sql
id                  INT AUTO_INCREMENT PRIMARY KEY
role_permission_id  INT NOT NULL      -- FK → role_permissions.id
constraint_id       INT NOT NULL      -- FK → constraints.id
constraint_value    VARCHAR(255) NOT NULL
```

---

#### `user_logs` — audit trail for all permission changes
```sql
id           INT AUTO_INCREMENT PRIMARY KEY
company_id   INT NOT NULL
user_id      INT NOT NULL
permission_id INT NULL
action       VARCHAR(100) NOT NULL    -- 'ASSIGN_PERMISSION', 'REVOKE_PERMISSION', etc.
target_table VARCHAR(100)
target_id    INT
description  TEXT
ip_address   VARCHAR(45) NULL
created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
```

---

### Platform Layer

#### `platform_permissions`
```sql
id          INT AUTO_INCREMENT PRIMARY KEY
name        VARCHAR(120) NOT NULL UNIQUE
category    VARCHAR(100) NOT NULL
action_key  VARCHAR(120) NOT NULL UNIQUE
description VARCHAR(255)
created_at  TIMESTAMP
```

#### `platform_roles`
```sql
id             INT AUTO_INCREMENT PRIMARY KEY
name           VARCHAR(120) NOT NULL UNIQUE
description    VARCHAR(255)
is_system_role TINYINT(1) DEFAULT 0
created_at     TIMESTAMP
```
No `company_id` — platform roles are global.

#### `platform_role_permissions`
```sql
id                    INT AUTO_INCREMENT PRIMARY KEY
platform_role_id      INT NOT NULL
platform_permission_id INT NOT NULL
UNIQUE KEY (platform_role_id, platform_permission_id)
```

#### `platform_admins`
```sql
id                INT AUTO_INCREMENT PRIMARY KEY
name              VARCHAR(120)
email             VARCHAR(191) UNIQUE
password_hash     VARCHAR(255)
is_platform_owner TINYINT(1) DEFAULT 0
is_system_account TINYINT(1) DEFAULT 0
created_at        TIMESTAMP
```

#### `platform_admin_roles`
```sql
id                INT AUTO_INCREMENT PRIMARY KEY
platform_admin_id INT NOT NULL
platform_role_id  INT NOT NULL
UNIQUE KEY (platform_admin_id, platform_role_id)
```

#### `platform_audit_logs`
```sql
id                     INT AUTO_INCREMENT PRIMARY KEY
platform_admin_id      INT NOT NULL
company_id             INT NULL
platform_permission_id INT NULL
action                 VARCHAR(100)
target_table           VARCHAR(100)
target_id              INT
description            TEXT
created_at             TIMESTAMP
```

---

## 3. Auth Session (AuthResult)

Every controller guard returns an `AuthResult` on success. This object is your session — pass it to services, pass it to Twig as `'session'`.

```
src/Services/Auth/DTO/AuthResult.php
src/Services/Auth/DTO/AuthUser.php
src/Services/Auth/DTO/AuthCompany.php
```

### AuthResult fields
```php
$session->token          // string — the raw session token
$session->expiresAt      // DateTimeImmutable
$session->deviceType     // 'dashboard' | 'pos'
$session->user           // AuthUser object (see below)
$session->company        // AuthCompany object (see below)
```

### AuthUser fields
```php
$session->user->id                  // int
$session->user->name                // string
$session->user->email               // ?string
$session->user->isSuperAdmin        // bool — true if this is a platform admin
$session->user->canDashboardLogin   // bool
$session->user->canPosLogin         // bool
$session->user->roles               // string[] e.g. ['Admin', 'Manager']
```

### AuthCompany fields
```php
$session->company->id         // int — tenant's company ID
$session->company->name       // string
$session->company->subdomain  // string — e.g. 'acme'
```

### Token resolution order
1. `Authorization: Bearer <token>` header
2. `angavu_token` cookie

Both are checked automatically by the base controller guards.

---

## 4. Tenant Permission System

### How it works end-to-end

```
Request comes in
    │
    ▼
requireAdmin($request, 'view_transactions')
    │
    ├─ No token / expired → redirect to login (401)
    ├─ POS session → deny (403)
    ├─ Platform admin session → delegate to PlatformCheckPermissionService
    └─ Dashboard session → CheckPermissionService::check()
           │
           ├─ Cache hit? → return cached result
           └─ DB query: user_roles → role_permissions → permissions
                  │
                  ├─ Not found → granted = false
                  └─ Found → fetch constraints → cache → granted = true
```

### Permission resolution query (simplified)
```sql
SELECT rp.id AS role_permission_id
FROM user_roles ur
JOIN role_permissions rp ON rp.role_id = ur.role_id
JOIN permissions p ON p.id = rp.permission_id
JOIN roles r ON r.id = rp.role_id
WHERE ur.user_id   = :user_id
  AND r.company_id = :company_id
  AND (p.name = :name OR p.action_key = :action_key)
LIMIT 1
```

A user can have multiple roles. If **any** of those roles has the permission, access is granted.

### Cache
`CheckPermissionService` maintains an in-memory array for the lifetime of one HTTP request. The cache key is `"{userId}:{companyId}:{permissionName}"`. Calling `check()` on the same permission 10 times in one request = 1 DB query. Call `clearCache()` after programmatically modifying permissions within the same request.

---

## 5. Platform Permission System

Platform admins use `platform_admins`, `platform_roles`, `platform_role_permissions`, and `platform_permissions`. None of these tables are scoped to a `company_id`.

### Hierarchy
```
platform_admins
    └── platform_admin_roles → platform_roles
            └── platform_role_permissions → platform_permissions
```

### is_platform_owner
The `platform_admins.is_platform_owner` flag gives unrestricted access to everything — no permission check needed. `PlatformCheckPermissionService::isPlatformOwner()` checks this combined with `isPlatformAdminSession()`.

---

## 6. CheckPermissionService — Checking Access

**Namespace:** `App\Services\Permission\CheckPermissionService`
**Inject as:** `private readonly CheckPermissionService $can`

### check() — simple yes/no
```php
$this->can->check($session, 'view_transactions')  // → bool
$this->can->check($session, 'VIEW_TRANSACTIONS')  // same — case insensitive
```
If `$session` belongs to a platform admin, delegates to `PlatformCheckPermissionService::check()` automatically.

---

### checkAll() — all must pass
```php
$this->can->checkAll($session, 'view_users', 'edit_users')  // → bool
// Returns true only if the user has BOTH permissions
```

---

### checkAny() — at least one must pass
```php
$this->can->checkAny($session, 'view_roles', 'edit_roles')  // → bool
// Returns true if the user has AT LEAST ONE of the listed permissions
```

---

### constraint() — read a single constraint value
```php
$hours = $this->can->constraint(
    $session,
    'view_transactions',    // permission name or action_key
    'max_hours_history',    // constraint key
    24                      // default if not set
);
// Returns the stored string value ('48') or the default (24)
// Always returns a string from DB — cast if you need int: (int) $hours
```
Returns `$default` immediately if:
- The session is a platform admin (no constraints apply to platform admins)
- The user does not have the permission

---

### constraints() — read all constraints as a map
```php
$limits = $this->can->constraints($session, 'view_transactions');
// Returns: ['max_hours_history' => '48', 'allowed_shortcodes' => '174379,123456']
// Returns [] if permission not granted or no constraints set
```

---

### getUserPermissionReport() — full permission summary
```php
$report = $this->can->getUserPermissionReport($session);
// Returns permissions grouped by category:
// [
//   'dashboard' => [
//     ['name' => 'view_transactions', 'action_key' => 'VIEW_TRANSACTIONS',
//      'role_permission_id' => 94, 'constraints' => ['max_hours_history' => '48']],
//     ...
//   ],
//   'stk' => [...],
// ]
// For platform admins returns: ['super_admin' => true, 'permissions' => []]
```
Useful for sending to the frontend on login so JS can gate UI elements without additional requests.

---

### clearCache()
```php
$this->can->clearCache();
```
Clears the per-request in-memory cache. Call this after modifying a user's permissions within the same HTTP request, otherwise `check()` will return the pre-modification result.

---

## 7. PermissionService — Managing Permissions

**Namespace:** `App\Services\Permission\PermissionService`
**Inject as:** `private readonly PermissionService $permissions`

All mutating methods require the actor to have `ASSIGN_PERMISSIONS` or be a platform admin. Every successful mutation is written to `user_logs`.

### listAll() — list every permission
```php
$all = $this->permissions->listAll();             // all permissions
$stk = $this->permissions->listAll('stk');        // filtered by category
```
No authorization required — read-only.

---

### listByRole() — permissions assigned to a role
```php
$assigned = $this->permissions->listByRole($roleId, $companyId);
// Returns array of rows, each with a 'constraints' key containing the constraint array
```

---

### listByRoleGrouped() — same but grouped by category
```php
$grouped = $this->permissions->listByRoleGrouped($roleId, $companyId);
// ['dashboard' => [...], 'stk' => [...]]
// Useful for building permission management UIs
```

---

### listCategories() — distinct category slugs
```php
$categories = $this->permissions->listCategories();
// ['dashboard', 'mpesa', 'pos_terminals', 'stk', ...]
```

---

### assignPermission()
```php
$result = $this->permissions->assignPermission(
    $session,       // AuthResult — the actor performing this action
    $roleId,        // int
    $permissionId,  // int
    $companyId,     // int
);
// Returns PermissionResult (see section 10)
```
**Guardrails enforced internally:**
- Actor must have `ASSIGN_PERMISSIONS` (or be platform admin)
- Role must belong to `$companyId`
- Permission must exist
- Actor cannot assign a permission they don't personally hold (unless platform admin)
- System roles (`is_system_role = 1`) cannot be modified by tenant users
- Duplicate assignments are silently accepted (returns ok, not an error)

---

### assignPermissions() — bulk assign
```php
$results = $this->permissions->assignPermissions(
    $session,
    $roleId,
    [1, 2, 3, 7],   // array of permission IDs
    $companyId,
);
// Returns ['1' => ['success' => true, ...], '2' => [...], ...]
// Each entry is PermissionResult::toArray()
```

---

### revokePermission()
```php
$result = $this->permissions->revokePermission(
    $session,
    $roleId,
    $permissionId,
    $companyId,
);
```
Automatically deletes any `role_permission_constraints` rows for this assignment before deleting the `role_permissions` row (FK safety).

---

### revokeAllPermissions()
```php
$result = $this->permissions->revokeAllPermissions($session, $roleId, $companyId);
// $result->data['revoked_count'] tells you how many were removed
```

---

### setConstraint() — upsert a constraint value
```php
$result = $this->permissions->setConstraint(
    $session,
    $rolePermissionId,    // the role_permissions.id (not role.id)
    'max_hours_history',  // constraint key
    '48',                 // value — always a string
    $companyId,
);
```
If the constraint already exists it is updated; otherwise it is inserted.

---

### removeConstraint()
```php
$result = $this->permissions->removeConstraint(
    $session,
    $rolePermissionId,
    'max_hours_history',
    $companyId,
);
```

---

### removeAllConstraints()
```php
$result = $this->permissions->removeAllConstraints($session, $rolePermissionId, $companyId);
// $result->data['removed_count']
```

---

### getConstraints()
```php
$rows = $this->permissions->getConstraints($rolePermissionId);
// [['constraint_id' => 3, 'constraint_key' => 'max_hours_history', 'constraint_value' => '48'], ...]
```

---

### getConstraintsMap()
```php
$map = $this->permissions->getConstraintsMap($rolePermissionId);
// ['max_hours_history' => '48', 'allowed_shortcodes' => '174379']
// Flat key=>value, much easier to work with
```

---

## 8. PlatformCheckPermissionService

**Namespace:** `App\Services\Permission\PlatformCheckPermissionService`
**Inject as:** `private readonly PlatformCheckPermissionService $platformCan`

This service is also injected into `CheckPermissionService` and `PermissionService` — you rarely need to call it directly from controllers, except in platform-specific controllers.

### isPlatformAdminSession()
```php
$this->platformCan->isPlatformAdminSession($session)  // → bool
// true if the user in the session is a platform admin
// This is the main gate for all platform-level operations
```

### isPlatformOwner()
```php
$this->platformCan->isPlatformOwner($session)  // → bool
// true if isPlatformAdminSession AND is_platform_owner = 1
// Platform owners bypass all platform permission checks
```

### check()
```php
$this->platformCan->check($session, 'edit_platform_core_settings')  // → bool
// Checks platform_admin_roles → platform_role_permissions → platform_permissions
// Platform owners always return true
```

---

## 9. Controller Guards

### Tenant controllers — AdminBaseController

All tenant admin controllers extend `AdminBaseController`. It injects `AuthService`, `CheckPermissionService`, and `PlatformCheckPermissionService` automatically via constructor.

```php
use App\Controller\Admin\AdminBaseController;

class MyController extends AdminBaseController
{
    // Additional dependencies go in your own constructor — call parent::__construct()
    public function __construct(
        AuthService $auth,
        CheckPermissionService $can,
        PlatformCheckPermissionService $platformCan,
        private readonly MyService $myService,
    ) {
        parent::__construct($auth, $can, $platformCan);
    }
}
```

#### requireAdmin()
```php
$session = $this->requireAdmin($request);
// No permission check — just validates session and rejects POS

$session = $this->requireAdmin($request, 'view_transactions');
// Validates session AND checks the permission

if ($session instanceof Response) {
    return $session;  // MUST do this — it's the deny response
}
// $session is now a guaranteed AuthResult
```

**What gets returned on failure:**

| Situation | Response type |
|---|---|
| No token / expired | Redirect to `app_login` (302) |
| POS session | Redirect to `app_login` (302) |
| Valid session, no permission | Renders `errors/403.html.twig` (403) |
| Fetch/AJAX request (any failure) | JSON `{'success': false, 'message': '...'}` |

#### requireSuperAdmin()
```php
$session = $this->requireSuperAdmin($request);
if ($session instanceof Response) return $session;
// Only passes if isPlatformAdminSession() returns true
```

#### success() / error() helpers
```php
return $this->success('Permission assigned.', ['role_permission_id' => 94]);
// → JSON: {'success': true, 'message': '...', 'data': {...}}

return $this->error('Role not found.', 404);
// → JSON: {'success': false, 'message': '...'}  (status 404)
```

---

### Platform controllers — PlatformBaseController

All platform admin controllers extend `PlatformBaseController`. It injects `AuthService` and `PlatformCheckPermissionService`.

```php
use App\Controller\Platform\PlatformBaseController;

class MyPlatformController extends PlatformBaseController
{
    public function __construct(
        AuthService $auth,
        PlatformCheckPermissionService $platformCan,
        private readonly Connection $db,
    ) {
        parent::__construct($auth, $platformCan);
    }
}
```

#### requirePlatform()
```php
$session = $this->requirePlatform($request);
// Validates session, rejects non-dashboard, rejects non-platform-admin

$session = $this->requirePlatform($request, 'edit_platform_core_settings');
// Additionally checks a platform permission

if ($session instanceof Response) {
    return $session;
}
```

**What gets returned on failure:**

| Situation | Response type |
|---|---|
| No token / expired | Redirect to `platform_login`, cookie cleared |
| Not a platform admin | Redirect to `platform_login`, cookie cleared |
| Valid session, no permission | Renders `platform/errors/403.html.twig` (403) |

---

## 10. PermissionResult DTO

Returned by every mutating method in `PermissionService`.

```php
$result->success     // bool
$result->reason      // string — human message (safe to show in UI)
$result->httpStatus  // int — 200, 403, 404, 400 etc.
$result->data        // array — extra payload (e.g. ['role_permission_id' => 94])
```

### Typical controller usage
```php
$result = $this->permissions->assignPermission($session, $roleId, $permId, $session->company->id);

if (!$result->success) {
    return $this->error($result->reason, $result->httpStatus);
}

return $this->success($result->reason, $result->data);
```

### toArray()
```php
$result->toArray();
// ['success' => true, 'reason' => '...', 'data' => [...]]
```

---

## 11. Twig — Gating UI Elements

**Extension:** `App\Twig\PermissionExtension`

Two functions are available in every Twig template.

### can()
```twig
{% if can('view_transactions') %}
    <a href="...">Transactions</a>
{% endif %}

{% if can('assign_permissions') %}
    <button>Edit Role Permissions</button>
{% endif %}
```
Reads the `angavu_token` cookie from the current request, validates the session, then calls `CheckPermissionService::check()`. Returns `false` safely if session is missing or invalid — no exception thrown.

Accepts both `name` and `action_key` format, same as the PHP service.

### isSuperAdmin()
```twig
{% if isSuperAdmin() %}
    <span class="badge">Platform Admin</span>
{% endif %}
```
Returns `true` if the current session is a platform admin session.

### Sidebar gating pattern
```twig
{% for section in sections %}
    {% for item in section.items %}
        {% if item.permission is not defined or item.permission is empty or can(item.permission) %}
            <a href="{{ path(item.route) }}">{{ item.label }}</a>
        {% endif %}
    {% endfor %}
{% endfor %}
```
If `item.permission` is null or empty the item always shows. If set, it is checked with `can()`.

---

## 12. Constraints System

Constraints let you apply limits to a permission within a specific role assignment. For example, a `view_transactions` permission can have a `max_hours_history` constraint that limits how far back a user can look.

### Data flow
```
constraints
    └── permission_constraints (which permissions accept which constraints)
            └── role_permission_constraints (the actual values per role_permission)
```

### Reading a constraint in a controller
```php
// Check permission first — always
if (!$this->can->check($session, 'view_transactions')) {
    return $this->error('Access denied.', 403);
}

// Read constraint with fallback default
$maxHours = $this->can->constraint($session, 'view_transactions', 'max_hours_history', 24);
// Returns string '48' from DB, or integer 24 if not set
// Cast as needed: (int) $maxHours

// Use it to limit the query
$since = new \DateTimeImmutable("-{$maxHours} hours");
```

### Reading all constraints at once
```php
$limits = $this->can->constraints($session, 'view_transactions');
// ['max_hours_history' => '48', 'allowed_shortcodes' => '174379,123456']

$shortcodes = explode(',', $limits['allowed_shortcodes'] ?? '');
```

### Constraint types
| type | example value | notes |
|---|---|---|
| `number` | `'48'` | stored as string, cast with `(int)` or `(float)` |
| `text` | `'read-only'` | plain string |
| `currency` | `'50000.00'` | stored as string, cast with `(float)` |
| `list` | `'174379,123456'` | comma-separated, explode before use |
| `boolean` | `'1'` or `'0'` | cast with `(bool)` |
| `date` | `'2026-01-01'` | ISO date string |
| `time` | `'08:00'` | HH:MM string |
| `percentage` | `'75.5'` | cast with `(float)` |

### Setting a constraint (from controller)
```php
$result = $this->permissions->setConstraint(
    $session,             // actor
    $rolePermissionId,    // role_permissions.id  (not role.id)
    'max_hours_history',
    '48',
    $session->company->id,
);
```

### Important: $rolePermissionId vs $roleId
`setConstraint()` and `removeConstraint()` take `role_permissions.id` — the join table row ID — not the role's primary key. Get it from `listByRole()` → `$row['role_permission_id']`.

---

## 13. Audit Logging

Every call to `assignPermission()`, `revokePermission()`, `setConstraint()`, `removeConstraint()`, and `revokeAllPermissions()` writes a row to `user_logs`.

### Log actions
| action | triggered by |
|---|---|
| `ASSIGN_PERMISSION` | `assignPermission()` |
| `REVOKE_PERMISSION` | `revokePermission()` |
| `REVOKE_ALL_PERMISSIONS` | `revokeAllPermissions()` |
| `SET_CONSTRAINT` | `setConstraint()` — new constraint |
| `UPDATE_CONSTRAINT` | `setConstraint()` — existing constraint updated |
| `REMOVE_CONSTRAINT` | `removeConstraint()` |
| `REMOVE_ALL_CONSTRAINTS` | `removeAllConstraints()` |

Logging never throws — it is wrapped in `try/catch(\Throwable)` so a logging failure never breaks the main operation.

### Querying audit logs
```sql
SELECT ul.*, u.name AS actor_name, p.name AS permission_name
FROM user_logs ul
LEFT JOIN users u ON u.id = ul.user_id
LEFT JOIN permissions p ON p.id = ul.permission_id
WHERE ul.company_id = :company_id
ORDER BY ul.created_at DESC
LIMIT 50;
```

---

## 14. Adding New Permissions

### Step 1 — Insert the permission into the database
```sql
INSERT INTO permissions (name, category, action_key, description)
VALUES ('export_transactions', 'dashboard', 'EXPORT_TRANSACTIONS', 'Export transaction data to CSV/Excel');
```

- `name` — lowercase, underscores, unique
- `category` — must match an existing category slug OR a new one (no FK)
- `action_key` — uppercase version of name, unique

### Step 2 — Use it in a controller
```php
$session = $this->requireAdmin($request, 'export_transactions');
if ($session instanceof Response) return $session;
```

### Step 3 — Gate the UI element in Twig
```twig
{% if can('export_transactions') %}
    <button>Export CSV</button>
{% endif %}
```

### Step 4 — Optionally add constraints
```sql
-- 1. Define the constraint type (if it doesn't exist)
INSERT INTO constraints (name, constraint_key, constraint_type, description)
VALUES ('Max Export Rows', 'max_export_rows', 'number', 'Maximum number of rows per export');

-- 2. Link it to the permission
INSERT INTO permission_constraints (permission_id, constraint_id, is_required, default_value)
VALUES (
    (SELECT id FROM permissions WHERE action_key = 'EXPORT_TRANSACTIONS'),
    (SELECT id FROM constraints WHERE constraint_key = 'max_export_rows'),
    0,      -- not required
    '5000'  -- default
);
```

Then read it in the controller:
```php
$maxRows = (int) $this->can->constraint($session, 'export_transactions', 'max_export_rows', 5000);
```

### Step 5 — Assign to a role
Via the admin UI (Roles → Edit → Permissions), or programmatically:
```php
$this->permissions->assignPermission($actorSession, $roleId, $permissionId, $companyId);
```

---

## 15. Common Patterns — Cookbook

### Pattern 1 — Simple protected page
```php
#[Route('/transactions', name: 'admin_transactions')]
public function index(Request $request): Response
{
    $session = $this->requireAdmin($request, 'view_transactions');
    if ($session instanceof Response) return $session;

    // $session is AuthResult — safe to use
    return $this->render('admin/transactions/index.html.twig', [
        'session' => $session,
    ]);
}
```

---

### Pattern 2 — Page visible to all, actions restricted
```php
#[Route('/dashboard', name: 'admin_dashboard')]
public function index(Request $request): Response
{
    $session = $this->requireAdmin($request);  // no permission check
    if ($session instanceof Response) return $session;

    return $this->render('admin/dashboard/index.html.twig', [
        'session' => $session,
        'can' => [
            'export'     => $this->can->check($session, 'export_transactions'),
            'manage'     => $this->can->check($session, 'edit_transactions'),
        ],
    ]);
}
```

In Twig:
```twig
{% if can.export %}
    <button>Export</button>
{% endif %}
```

---

### Pattern 3 — Fetch/JSON endpoint
```php
#[Route('/{id}/assign-permission', methods: ['POST'])]
public function assignPermission(int $id, Request $request): JsonResponse
{
    $session = $this->requireAdmin($request, 'assign_permissions');
    if ($session instanceof Response) return $this->error('Unauthorized.', 403);
    // Note: for fetch endpoints, denyAccess already returns JSON,
    // but the Response type check is still required to satisfy static analysis.

    $permissionId = (int) $request->request->get('permission_id');
    $result = $this->permissions->assignPermission($session, $id, $permissionId, $session->company->id);

    return $result->success
        ? $this->success($result->reason, $result->data)
        : $this->error($result->reason, $result->httpStatus);
}
```

---

### Pattern 4 — Constraint-aware query
```php
public function transactions(Request $request): Response
{
    $session = $this->requireAdmin($request, 'view_transactions');
    if ($session instanceof Response) return $session;

    $maxHours = (int) $this->can->constraint($session, 'view_transactions', 'max_hours_history', 72);
    $since    = (new \DateTimeImmutable())->modify("-{$maxHours} hours")->format('Y-m-d H:i:s');

    $rows = $this->db->fetchAllAssociative(
        'SELECT * FROM transactions WHERE company_id = :cid AND created_at >= :since ORDER BY created_at DESC',
        ['cid' => $session->company->id, 'since' => $since],
    );

    return $this->render('admin/transactions/index.html.twig', [
        'session'   => $session,
        'rows'      => $rows,
        'maxHours'  => $maxHours,
    ]);
}
```

---

### Pattern 5 — Checking permission without blocking
Sometimes you need to check a permission without denying the whole request (e.g. to conditionally include extra data):
```php
$session = $this->requireAdmin($request);
if ($session instanceof Response) return $session;

$canSeeRawData = $this->can->check($session, 'view_raw_api_logs');

return $this->render('admin/something.html.twig', [
    'session'        => $session,
    'showRawData'    => $canSeeRawData,
]);
```

---

### Pattern 6 — Passing permission state to Twig for JS use
```php
return $this->render('admin/roles/show.html.twig', [
    'session' => $session,
    'can'     => [
        'assign'  => $this->can->check($session, 'assign_permissions'),
        'delete'  => $this->can->check($session, 'delete_roles'),
    ],
]);
```

```twig
{# Expose to JS #}
<script>
const PAGE_CAPS = {
    assign: {{ can.assign ? 'true' : 'false' }},
    delete: {{ can.delete ? 'true' : 'false' }},
};
</script>

{# Gate a button #}
{% if can.assign %}
    <button onclick="openAssignDrawer()">Assign Permission</button>
{% endif %}
```

---

### Pattern 7 — Platform controller protecting owner-only pages
```php
#[Route('/platform/owner/permissions', name: 'platform_owner_permissions', host: 'admin.{domain}')]
public function permissions(Request $request): Response
{
    $session = $this->requirePlatform($request, 'edit_platform_core_settings');
    if ($session instanceof Response) return $session;

    $rows = $this->db->fetchAllAssociative('SELECT * FROM permissions ORDER BY category, name');

    return $this->render('platform/owner/permissions/index.html.twig', [
        'session' => $session,
        'rows'    => $rows,
    ]);
}
```

---

### Pattern 8 — Invalidating permission cache after a change in the same request
```php
$result = $this->permissions->assignPermission($session, $roleId, $permId, $session->company->id);

if ($result->success) {
    $this->can->clearCache(); // so subsequent check() calls in this request reflect the new state
}
```

---

## 16. Error Reference

### PermissionService failure reasons
| reason | httpStatus | cause |
|---|---|---|
| `You do not have permission to manage role permissions.` | 403 | Actor lacks `ASSIGN_PERMISSIONS` |
| `Role not found in this company.` | 404 | Role doesn't belong to `$companyId` |
| `System role 'X' cannot be modified by tenant users.` | 403 | Tried to modify `is_system_role = 1` |
| `Permission not found.` | 404 | `$permissionId` doesn't exist |
| `You cannot assign the 'X' permission because you don't have it yourself.` | 403 | Delegation violation |
| `This permission is not assigned to the role.` | 404 | Tried to revoke something not assigned |
| `Constraint 'X' not found.` | 404 | Tried to remove a constraint that doesn't exist |
| `Role permission not found in this company.` | 404 | `$rolePermissionId` doesn't belong to `$companyId` |
| `Cannot set constraints on system role 'X'.` | 403 | Tried to set constraint on system role |

### requireAdmin() / requirePlatform() behaviour summary
| condition | browser request | fetch/AJAX request |
|---|---|---|
| No token | Redirect to login | JSON 401 |
| Expired token | Redirect to login | JSON 401 |
| POS session on admin | Redirect to login | JSON 401 |
| Not a platform admin (platform routes) | Redirect + clear cookie | Redirect + clear cookie |
| Missing permission (admin) | Render 403 page | JSON 403 |
| Missing permission (platform) | Render platform/errors/403 | Render platform/errors/403 |

---

## 17. File Map

```
src/
├── Services/
│   ├── Auth/
│   │   ├── AuthService.php                         — session creation + validation
│   │   └── DTO/
│   │       ├── AuthResult.php                      — session object returned by guards
│   │       ├── AuthUser.php                        — user snapshot inside AuthResult
│   │       └── AuthCompany.php                     — tenant snapshot inside AuthResult
│   └── Permission/
│       ├── CheckPermissionService.php              — check if user CAN do X
│       ├── PermissionService.php                   — assign / revoke / constraints
│       ├── PlatformCheckPermissionService.php      — platform-level permission check
│       └── DTO/
│           └── PermissionResult.php                — result object from PermissionService
│
├── Controller/
│   ├── Admin/
│   │   ├── AdminBaseController.php                 — requireAdmin() guard + helpers
│   │   ├── PermissionController.php                — GET /permissions (list view)
│   │   └── RoleController.php                      — role CRUD + permission assignment
│   └── Platform/
│       ├── PlatformBaseController.php              — requirePlatform() guard
│       └── OwnerConfigController.php               — platform owner pages (permissions, roles, etc.)
│
└── Twig/
    └── PermissionExtension.php                     — can() and isSuperAdmin() for templates

templates/
├── admin/
│   ├── permissions/index.html.twig                 — tenant permission list (grouped by category)
│   ├── roles/
│   │   ├── index.html.twig                         — role list
│   │   └── show.html.twig                          — role detail + assign/revoke UI
│   └── components/sidebar.html.twig                — tenant sidebar with can() gating
└── platform/
    ├── owner/
    │   ├── permissions/index.html.twig             — platform: manage system permissions
    │   ├── platform-permissions/index.html.twig    — platform: manage platform permissions
    │   ├── payment-methods/index.html.twig         — platform: manage payment methods
    │   └── features/index.html.twig                — platform: feature flags (planned)
    └── partials/sidebar.html.twig                  — platform sidebar with can() gating

docs/
└── permissions.md                                  — this file
```

---

*Generated 2026-03-19. Always refer to the source files for the authoritative implementation — this document describes the code as it exists at that date.*
