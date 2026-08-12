# HRIS Agent Guide

> Last updated: 2026-02-09. App name: **HRIS** (defined in `includes/config.php` as `APP_NAME`).

---

## 1. Stack & Architecture

| Layer | Technology |
|---|---|
| Backend | Plain PHP 8.x (no framework), PDO |
| Database | PostgreSQL (primary & only supported driver) |
| Frontend | Tailwind CSS 3 (**precompiled**, not CDN — see §6) + `assets/css/app.css`, vanilla JS (`assets/js/app.js`), Chart.js 4.x |
| Typography | Inter font family (Google Fonts) |
| PDFs | FPDF 1.86 bundled at `assets/fpdf186/`, loaded via `includes/pdf.php` |
| Hosting | Heroku-ready (parses `DATABASE_URL`), also XAMPP/local compatible |
| Timezone | `Asia/Manila` — all datetime display uses 12-hour format (`M d, Y h:i A`) |

### Directory Layout

```
├── includes/            # Shared PHP: auth, DB, config, session, payroll, permissions, utils, UI chrome
├── modules/             # Feature modules (one folder per domain area)
│   ├── account/         # User account management
│   ├── admin/           # System admin pages (branches, config, tools, compensation)
│   ├── attendance/      # Attendance tracking & DTR
│   ├── audit/           # Audit trail viewer
│   ├── auth/            # Login, logout, keepalive, password reset
│   ├── departments/     # Department CRUD
│   ├── documents/       # Employee personal documents
│   ├── employees/       # Employee records CRUD
│   ├── inventory/       # Inventory & POS management
│   ├── leave/           # Leave filing, approval, balances
│   ├── memos/           # Company memos & announcements
│   ├── notifications/   # Notification center
│   ├── overtime/        # Overtime requests & tracking
│   ├── payroll/         # Payroll runs, batches, payslips, complaints
│   ├── performance/     # Performance reviews & KPIs
│   ├── positions/       # Position CRUD + permission assignment
│   └── recruitment/     # Recruitment pipeline
├── assets/
│   ├── css/app.css      # Custom design tokens, sidebar, buttons, cards, tables, forms
│   ├── js/app.js        # Global JS: SPA nav, modals, confirm flows, auth override, loaders, sidebar
│   ├── fpdf186/         # Bundled FPDF library
│   ├── resources/       # Static images (logo, branding)
│   └── uploads/         # User-uploaded files (sanitized names)
├── database/
│   ├── schema_postgre.sql        # Base schema
│   ├── migrations/*.sql          # Incremental SQL (date-prefixed, idempotent via tools/migrate.php)
│   └── Dummydata/                # Seed scripts (never auto-run in production)
├── tools/               # CLI/web utilities: migrate.php, reset_admin.php, debug tools
└── handoff/             # Process notes, feature docs, session logs
```

### Key Include Files

| File | Purpose |
|---|---|
| `includes/config.php` | App constants (`APP_NAME`, `BASE_URL`, `UPLOAD_DIR`, `LEAVE_DEFAULT_ENTITLEMENTS`, timezone) |
| `includes/db.php` | `get_db_conn()` — singleton PDO connection, env-aware |
| `includes/auth.php` | Authentication, remember-me tokens, `current_user()`, `audit()`, `action_log()`, `ensure_action_authorized()`, superadmin guard |
| `includes/session.php` | Session bootstrap, fingerprinting, idle/absolute timeouts (3h/24h), rotation |
| `includes/permissions.php` | Position-based access control engine: `get_user_effective_access()`, `user_has_access()`, `user_can()` |
| `includes/permissions_catalog.php` | Full permissions catalog by domain → resource (defines all access-controlled endpoints) |
| `includes/utils.php` | CSRF, flash messages, file uploads, pagination, `sys_log()`, `backup_then_delete()`, branches, notifications |
| `includes/payroll.php` | Payroll engine: batches, approvals, complaints, payslips, statutory computations |
| `includes/header.php` | Full page layout — sidebar, top bar, notification dropdown, modals, Tailwind config, SPA wiring |
| `includes/footer.php` | Layout close, authorization modal, confirm modal, JS bundle load |
| `includes/pdf.php` | PDF rendering helpers |
| `includes/work_schedules.php` | Work schedule management helpers |

---

## 2. Page Pattern (Critical)

Every page **must** follow this order:

```php
<?php
require_once __DIR__ . '/../../includes/auth.php';   // 1. Auth bootstrap (sessions, permissions)
require_login();                                       // 2. Guard: must be logged in
// require_module_access('domain', 'resource', 'read'); // 3. Permission check

// 4. Handle POST actions + redirects BEFORE any HTML output
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    // ... process, flash, redirect ...
    header('Location: ' . BASE_URL . '/modules/...');
    exit;
}

$pageTitle = 'Page Title';
require_once __DIR__ . '/../../includes/header.php';   // 5. THEN render layout
?>
<!-- 6. Page HTML content -->
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
```

**Never** include `header.php` before POST handling — it outputs HTML and breaks `header()` redirects.

---

## 3. Access Control (Position-Based Permissions)

The system uses **position-based access control** (not simple role-based). Permissions are assigned to **positions**, and users inherit access through their employee → position link.

### Permission Model

| Concept | Description |
|---|---|
| **Domain** | Top-level category: `system`, `hr_core`, `payroll`, `leave`, `attendance`, `documents`, `performance`, `notifications`, `user_management`, `inventory`, `reports` |
| **Resource** | Specific feature within a domain (e.g., `payroll_runs`, `employees`, `pos_transactions`) |
| **Access Level** | `none` → `read` → `write` → `manage` (hierarchical, each level includes all below) |
| **Self-service** | Some resources (e.g., `leave_requests`, `self_profile`, `view_notifications`) are available to all authenticated users |

### Access Check Functions

```php
// Preferred — check domain.resource with specific level
user_has_access($userId, 'hr_core', 'employees', 'write');  // returns bool
get_user_effective_access($userId, 'payroll', 'payroll_runs'); // returns 'none'|'read'|'write'|'manage'

// Quick boolean shorthand
user_can('hr_core', 'employees', 'manage');  // checks current user

// Page-level guard (redirects to unauthorized page on failure)
require_module_access('inventory', 'inventory_items', 'read');

// Action-level with override support
ensure_action_authorized('hr_core.employees', 'delete_employee', 'manage');
```

### Special Accounts

- **Superadmin** (`admin@hrms.local`, User ID 1): Has unlimited `manage` access to everything. **Cannot** be edited or deleted by anyone.
- **System Admin flag** (`users.is_system_admin = true`): Bypasses all permission checks, gets `manage` on everything.

### Sidebar Visibility

Navigation items in `includes/header.php` are conditionally rendered based on `get_user_effective_access()`. When adding new modules, add corresponding sidebar entries gated by the correct domain/resource permission.

---

## 4. Security Conventions

| Concern | Implementation |
|---|---|
| **CSRF** | Every `<form>` includes `<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">`. Validate on POST with `csrf_verify($_POST['csrf_token'] ?? '')`. |
| **SQL Injection** | All queries use PDO prepared statements with named parameters (`:param`). Never concatenate user input into SQL. |
| **Session Hardening** | Handled by `includes/session.php`: strict mode, HTTP-only cookies, SameSite=Lax, fingerprinting (UA + IP), automatic rotation every 5 mins, idle timeout (3h), absolute timeout (24h). |
| **Remember Me** | Selector:token pattern with SHA-256 hashing, stored in `user_remember_tokens`, 30-day lifetime. |
| **Authorization Override** | `ensure_action_authorized()` supports elevation: if user lacks required level, a modal collects authorized-user credentials. Audited via `audit()` with override attribution. |
| **Flash + PRG** | Always use `flash_success()` / `flash_error()` then `header('Location: ...')` + `exit` after POSTs. Never echo success/error inline after a mutation. |

---

## 5. Action Lifecycle

Every user-facing action (create, update, delete, approve, etc.) **must**:

1. **Confirm before execution** — Use `data-confirm="Are you sure?"` attribute on forms/buttons (handled by `assets/js/app.js` confirm modal).
2. **Check authorization** — Call `ensure_action_authorized('domain.resource', 'action_name', 'required_level')` for sensitive operations. The JS-side auth modal (`#authzModal`) is wired in `assets/js/app.js`.
3. **Log the action** — Call `action_log($module, $actionType, $status, $meta)` for user-facing CRUD. Call `audit()` directly for auth events or when structured old/new value tracking is needed.
4. **Support reversal** — Destructive changes must use `backup_then_delete($pdo, $table, $pkCol, $id)` or equivalent rollback logic.

---

## 6. UI & Design System

### Design Philosophy

The UI follows a **modern, minimal, flat, indigo-accented design language** with a **light/dark theme system**. No gradients, subtle shadows only (hairline borders do most of the visual separation, not drop shadows). All new pages and components must be visually consistent with the existing system — see the token table below before hand-picking colors.

### Tailwind Build (precompiled, not CDN)

Despite older docs/comments referencing a CDN, this repo **precompiles Tailwind** via `tailwind.config.js` + PostCSS:
```
npm run build:css   # one-shot build → assets/css/tailwind.css
npm run watch:css    # rebuilds on file change, for local dev
```
**Any time you add a new Tailwind utility class (including `dark:` variants) to a `.php` or `.js` file, you must rerun `npm run build:css`** — the compiled `assets/css/tailwind.css` only contains classes that existed in the source files at build time. `tailwind.config.js` sets `darkMode: 'class'`, so dark mode is controlled by an `html.dark` class, not OS preference alone.

`tailwind.config.js`:
```js
module.exports = {
  darkMode: 'class',
  content: ['./*.php', './includes/**/*.php', './modules/**/*.php', './assets/js/**/*.js'],
  theme: {
    extend: {
      fontFamily: { sans: ['Inter', 'system-ui', '-apple-system', 'sans-serif'] },
      colors: { sidebar: { DEFAULT: '#0f172a', light: '#1e293b' } }
    }
  }
}
```

### Dark mode

- **Toggle**: sun/moon button in the top bar (`#btnThemeToggle`, `includes/header.php`), handled by a small IIFE in `assets/js/app.js` that toggles `document.documentElement.classList` and persists the choice to `localStorage.theme`.
- **Anti-flash**: a blocking inline `<script>` at the very top of `<head>` in `includes/header.php` (before any stylesheet) reads `localStorage.theme` (falling back to `prefers-color-scheme`) and sets `html.dark` synchronously, so there's no flash of the wrong theme on load.
- **Icon swap** for the toggle button is pure CSS (`html.dark #themeIconDark`, etc. in `app.css`) — don't add JS logic for it.
- **Coverage today**: the app shell (sidebar, top bar, `.card`, `.btn*`, `.table-basic`, dropdowns, mobile nav) and `modules/employees/index.php` (the reference pilot page) are fully dark-mode correct. Other pages that stick to `.card`/`.btn`/`.table-basic` inherit correct theming automatically (see tokens below). Pages with raw inline Tailwind color utilities (`bg-white`, `text-slate-900`, gradient hero banners, etc. written directly in markup) do **not** yet have `dark:` variants and will render light-only until updated — when you touch such a page, add `dark:` variants following the pattern in `modules/employees/index.php`, then rebuild CSS.

### Design Tokens (from `assets/css/app.css`)

Custom CSS (sidebar, buttons, cards, tables, dropdowns, forms, mobile nav — everything **not** a raw Tailwind utility class) is driven by CSS custom properties defined once on `:root` (light) and overridden under `html.dark` (dark), so the whole shell reacts to the toggle from one place:

| Token | Light | Dark | Used for |
|---|---|---|---|
| `--bg-page` | `#f8fafc` | `#0b1220` | `<body>` background |
| `--bg-surface` | `#ffffff` | `#0f172a` | `.card`, `.top-bar`, `.sidebar`, `.input-text` |
| `--bg-surface-muted` / `--bg-surface-hover` | `#f8fafc` / `#f1f5f9` | `#1e293b` | table header bg, hover states |
| `--border-subtle` / `--border-default` | `#f1f5f9` / `#e5e7eb` | `#1e293b` / `#334155` | card/table borders, dividers |
| `--text-primary` / `--text-secondary` / `--text-muted` | `#0f172a` / `#475569` / `#94a3b8` | `#f1f5f9` / `#94a3b8` / `#64748b` | body copy, secondary labels, muted text |
| `--accent` / `--accent-hover` | `#4f46e5` / `#4338ca` | `#6366f1` / `#818cf8` | primary buttons, links, active nav |
| `--accent-soft-bg` / `--accent-soft-text` | `#eef2ff` / `#4338ca` | translucent indigo / `#a5b4fc` | active sidebar nav pill |

When adding new custom CSS, reference `var(--token)` — never a literal hex — so it works in both themes automatically. When writing raw Tailwind utility classes directly in a page (unavoidable for one-off content), pair every color class with a `dark:` variant (e.g. `text-slate-900 dark:text-slate-100`).

| Element | Standard |
|---|---|
| **Page background** | token-driven via `body { background: var(--bg-page) }` — don't put `bg-slate-50` back on `<body>` |
| **Cards** | `.card` class — token bg, `rounded-xl`, subtle shadow (`--shadow-card`) + token border, `.card-header` + `.card-body` |
| **Buttons** | `.btn` base + variants: `.btn-primary` / `.btn-accent` (flat `var(--accent)`, **no gradient**), `.btn-secondary` (gray-700), `.btn-warning` (amber), `.btn-danger` (red), `.btn-outline` (token surface + border) |
| **Button icons** | `.btn-icon` — 2.25rem square, centered |
| **Form inputs** | `.input-text` (token bg/border/text) or Tailwind classes + indigo focus ring via CSS. Add `.input-error` for validation errors, `.field-error` for error text. |
| **Required fields** | Use `<label class="required">` — appends red asterisk via CSS `::after` |
| **Tables** | `.table-basic` — token header bg, hover rows, `0.875rem` font size |
| **Action links** | `.action-links` for inline edit/delete/view groups with subtle pipe dividers |
| **Dropdowns** | `.dropdown` + `.dropdown-menu` — token bg, rounded-xl, `--shadow-popover` |
| **Badges/pills** | Tailwind inline: `px-2 py-0.5 text-xs font-medium rounded-full bg-{color}-100 text-{color}-700` — add a `dark:bg-{color}-900/40 dark:text-{color}-300` pair for dark mode |
| **Loaders** | `.loader-spinner` (large, 48px), `.spinner-mini` (inline, 16px) — indigo themed |
| **User avatar** | `.user-avatar` — 2rem square, rounded-lg, flat `var(--accent)`, white initials (no gradient) |
| **Page intro** | `.page-intro` wrapper (`<h1>` + `.hint-text` subtitle) — every page should have a one-line plain-language description under its title |
| **Hint text** | `.hint-text` — small muted helper text for explaining jargon/sections in plain language |
| **Empty states** | `.empty-state` (icon + `.empty-state-title` + `.empty-state-desc`) — use instead of a bare "No records found" table row |
| **Collapsible sections** | `.section-collapsible-header` (button, `data-section-toggle="id"`, `aria-expanded`) + `.section-collapsible-body` (`id` matching the toggle, `.is-collapsed` when closed) — for pages with several secondary sections that shouldn't all be forced onto the initial scroll. JS handler already wired in `app.js`; persists per-section state to `localStorage`. |

### Layout Structure

```
┌─────────────────────────────────────────────┐
│ <body class="font-sans"> (bg via CSS token) │
│ ┌──────┬──────────────────────────────────┐  │
│ │Sidebar│  ┌─ Top Bar (sticky) ─────────┐ │  │
│ │(sticky│  │ Page title     User/Notifs  │ │  │
│ │ 264px │  ├─────────────────────────────┤ │  │
│ │ dark  │  │                             │ │  │
│ │ navy) │  │   <main id="appMain">       │ │  │
│ │       │  │   (scrollable content)      │ │  │
│ │       │  │                             │ │  │
│ │       │  ├─────────────────────────────┤ │  │
│ │       │  │ Footer                      │ │  │
│ └───────┘  └─────────────────────────────┘ │  │
└─────────────────────────────────────────────┘
```

- **Sidebar**: `aside#sidebar`, 256px (w-64), collapsible to 68px (w-[4.25rem]). Token-driven bg (`--sidebar-bg`): white in light mode, `#0f172a` (the navy also used for `tailwind.config.js`'s `sidebar.DEFAULT`) in dark mode — so light and dark sidebars intentionally look different, not a fixed dark-navy chrome. Grouped nav items with collapsible sections (only the group containing the active page starts expanded; state persists per-user via `localStorage`).
- **Top bar**: `.top-bar`, sticky, token bg, page title + user controls + theme toggle.
- **Content**: `<main id="appMain">`, scrollable, padded.
- **Mobile**: Sidebar hidden on `<md`, replaced by hamburger → overlay mobile nav (`#mnav`).

### Page Content Patterns

Use these consistent patterns for page layouts:

```html
<!-- Page header with title and actions -->
<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
  <div>
    <h1 class="text-xl font-bold text-slate-900">Page Title</h1>
    <p class="text-sm text-slate-500 mt-0.5">Description text</p>
  </div>
  <div class="flex items-center gap-2">
    <a href="..." class="btn btn-primary">+ Add New</a>
  </div>
</div>

<!-- Card wrapper for content -->
<div class="card">
  <div class="card-header flex items-center justify-between">
    <span>Section Title</span>
    <!-- Optional: filters, search, export -->
  </div>
  <div class="card-body">
    <table class="table-basic">...</table>
  </div>
</div>

<!-- Stat cards grid (dashboard style) -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
  <div class="card card-body flex items-center gap-4">
    <div class="w-10 h-10 rounded-lg bg-indigo-100 flex items-center justify-center">
      <svg class="w-5 h-5 text-indigo-600">...</svg>
    </div>
    <div>
      <div class="text-2xl font-bold text-slate-900">42</div>
      <div class="text-xs text-slate-500">Metric Label</div>
    </div>
  </div>
</div>
```

### Color Palette (Consistent Usage)

| Purpose | Color | Tailwind Class |
|---|---|---|
| Primary actions, active states | Indigo 600 | `bg-indigo-600`, `text-indigo-600` |
| Success, approvals | Emerald 600 | `bg-emerald-600`, `text-emerald-600` |
| Warnings, pending | Amber 600 | `bg-amber-600`, `text-amber-600` |
| Errors, destructive | Red 600 | `bg-red-600`, `text-red-600` |
| Info, neutral highlights | Blue 600 | `bg-blue-600`, `text-blue-600` |
| Body text | Slate 900 | `text-slate-900` |
| Secondary text | Slate 500 | `text-slate-500` |
| Muted/disabled | Slate 400 | `text-slate-400` |
| Borders | Slate 200 / F1F5F9 | `border-slate-200` |
| Hover backgrounds | Slate 50 | `bg-slate-50` |

---

## 7. JavaScript Patterns (`assets/js/app.js`)

### Available Global Functions

| Function | Usage |
|---|---|
| `openModal(id)` / `closeModal(id)` | Toggle modal visibility by element ID |
| `showLoader()` / `hideLoader()` | Global full-page loading overlay (`#appLoader`) |
| `showContentLoader()` / `hideContentLoader()` | In-content area loader (`#contentLoader`) |
| `navigateSpa(url)` | Programmatic SPA navigation (fetches page, swaps `#appMain`) |
| `escapeHtml(str)` | Sanitize strings for safe HTML insertion |

### SPA Navigation

Links with class `spa` are intercepted for in-page navigation without full reload:
```html
<a href="<?= BASE_URL ?>/modules/employees/index" class="nav-item spa">Employees</a>
```
After SPA load, `document.dispatchEvent(new CustomEvent('spa:loaded', { detail: { url } }))` fires — use this to reinitialize page-specific JS.

### Data Attributes for Behavior

| Attribute | Element | Effect |
|---|---|---|
| `data-confirm="message"` | `<form>`, `<a>`, `<button>` | Shows confirm modal before proceeding |
| `data-authz-action="label"` | `<form>` | Triggers authorization override flow |
| `data-authz-module="domain.resource"` | `<form>` | Specifies permission domain for override check |
| `data-authz-level="manage"` | `<form>` | Required permission level for the action |
| `data-dd-toggle="menuId"` | `<button>` | Toggles dropdown menu visibility |
| `data-card-link="/url"` | Any element | Makes entire card clickable |
| `data-no-loader` | `<a>`, `<button>` | Suppresses global loader on click (e.g., CSV downloads) |

### Adding Page-Specific JS

Prefer extending `assets/js/app.js` or listen for `spa:loaded` events over inline `<script>` blocks. If inline JS is necessary, keep it minimal:
```html
<script>
document.addEventListener('spa:loaded', function() {
  // Reinitialize page-specific behavior after SPA navigation
});
</script>
```

---

## 8. Payroll Domain

Payroll logic lives in `includes/payroll.php` (5000+ lines) and `modules/payroll/`.

### Key Concepts

- **Payroll Runs** → contain **Batches** (per-branch) → contain individual **Payslips** (per-employee)
- Sequential **approval chain** stored as JSON in `payroll_batches.approvers_chain`
- Approval decisions logged in `payroll_batches.approvals_log`
- `payroll_initialize_approvals()` must be called when creating new batches
- Respect sequential approval order — step N must be approved before step N+1

### Related Config

- Cutoff periods: `modules/admin/` config pages, stored in `cutoff_periods` table
- Statutory contributions: SSS, PhilHealth, Pag-IBIG tables in corresponding DB tables
- Compensation templates: `compensation_templates` table
- DTR uploads feed into payroll computation

---

## 9. Leave Management

| File | Purpose |
|---|---|
| `modules/leave/create.php` | Employee leave filing with balance display |
| `modules/leave/admin.php` | Admin approval/rejection dashboard |
| `modules/leave/index.php` | Employee's own leave history |
| `includes/config.php` | `LEAVE_DEFAULT_ENTITLEMENTS` array (sick:10, vacation:12, emergency:5, etc.) |

- Balances computed by `leave_calculate_balances()` — accounts for approved/pending requests against annual entitlements.
- New leave features should hook into this helper and support per-employee overrides.
- Leave filing policies configured via `leave_filing_policies` table.

---

## 10. Inventory & POS

Lives in `modules/inventory/`. Permission domain: `inventory` with resources: `inventory_items`, `pos_transactions`, `inventory_reports`.

Key pages: `inventory.php` (item list), `pos.php` (point-of-sale), `transactions.php`, `reports.php`, `purchase_orders.php`, `bulk_import.php`, `receipt_settings.php`.

---

## 11. Migrations & Schema

- **Base schema**: `database/schema_postgre.sql`
- **Incremental migrations**: `database/migrations/YYYY-MM-DD_description.sql` — applied via `tools/migrate.php`
- **Migration runner**: Idempotent; tracks applied files in `schema_migrations` table (by filename + SHA-256 checksum). Safe to re-run.
- **Naming convention**: `YYYY-MM-DD_descriptive_name.sql` (natural sort order)
- **Seed data**: `database/Dummydata/` — development only, never auto-run in production

When adding schema changes:
1. Create a new migration file in `database/migrations/` with today's date prefix
2. Write idempotent SQL (use `IF NOT EXISTS`, `DO $$ ... $$` blocks)
3. Test via `tools/migrate.php` (web or CLI)

---

## 12. Logging & Auditing

### Two logging systems

| Function | Purpose | Table | When to Use |
|---|---|---|---|
| `action_log($module, $actionType, $status, $meta)` | User-facing actions (CRUD, approvals) | `audit_logs` (via `audit()`) + `system_logs` | Every user action: create, update, delete, approve, reject |
| `audit($action, $details, $context)` | Structured audit trail (supports old/new values, target tracking) | `audit_logs` | Auth events, override flows, or when structured change tracking is needed |
| `sys_log($code, $message, $meta)` | System/technical errors | `system_logs` | Database errors, permission failures, unexpected exceptions |

### Log Codes Convention

- `DB*` — Database errors
- `AUTH*` — Authentication/authorization events
- `PAY*` — Payroll operations
- `GEN*` — General system errors
- `ACTION-MODULE` — Action log entries

Never suppress or bypass logging calls. They feed admin reports, audit trail UI (`modules/audit/`), and system log viewer.

---

## 13. Routing & URLs

- **`BASE_URL`**: Always prefix all internal `href`, `action`, and redirect URLs with `BASE_URL`. Defined as empty string for production (app at domain root).
- **`.htaccess`**: Provides extensionless routes (e.g., `/modules/employees/index` maps to `modules/employees/index.php`).
- **Never** hardcode `.php` extensions in navigation links or redirects.
- **API-style endpoints**: Some modules expose `api_*.php` files for AJAX calls — these return JSON and skip header/footer.

---

## 14. File Uploads

- Store under `assets/uploads/` (constant `UPLOAD_DIR`)
- Use `sanitize_file_name()` to clean filenames before storage
- Use `handle_upload($file, $destDir)` helper for standard uploads (whitelists: jpg, jpeg, png, gif, webp, pdf, doc, docx, xls, xlsx, csv, txt, zip)
- Uploaded files are renamed with timestamp suffix to prevent collisions

---

## 15. Tools & Utilities

Utilities in `tools/` are web/CLI accessible:

| Tool | Purpose |
|---|---|
| `tools/migrate.php` | Database migration runner |
| `tools/reset_admin.php` | Reset admin account |
| `tools/clear_cache.php` | Cache cleanup |
| `tools/env_check.php` | Environment diagnostic |
| `tools/debug_logs_page.php` | System log viewer |
| `tools/delete_payroll_run.php` | Payroll run cleanup |

Keep tools updated when schema or auth flows change. They share the same include conventions.

---

## 16. Database Access (Development)

You may use the PostgreSQL extension to connect to the database for schema verification and debugging. Connection details are stored in the `DATABASE_URL` environment variable — never commit credentials to source control.

---

## Quick Reference: New Feature Checklist

When building a new feature or page:

- [ ] Create module files under `modules/<area>/`
- [ ] Add permission resource to `includes/permissions_catalog.php` if needed
- [ ] Gate access with `require_module_access()` or `user_has_access()`
- [ ] Follow the POST-before-header page pattern
- [ ] Include CSRF token in all forms
- [ ] Use `flash_success()`/`flash_error()` + redirect (PRG pattern)
- [ ] Log actions via `action_log()`, errors via `sys_log()`
- [ ] Add `data-confirm` for destructive actions
- [ ] Use `ensure_action_authorized()` for sensitive operations
- [ ] Add sidebar nav entry in `includes/header.php` (gated by permission check)
- [ ] Use consistent UI: `.card`, `.btn-*`, `.table-basic`, stat cards grid, color palette
- [ ] Write migration SQL if schema changes are needed
- [ ] Keep layouts responsive (mobile-first: `grid-cols-1 sm:grid-cols-2 lg:grid-cols-4`)

When unsure about a workflow, check `handoff/*.md` for the latest process notes.
