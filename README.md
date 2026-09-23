# BillNest

Universal WordPress POS, invoicing, inventory & basic accounting plugin. Standalone, self-contained, no external ERP dependency. Built for small-to-mid retail/service businesses, marketplace-resale-ready.

**Brand:** ITzone360
**Author:** Md Aktarujjaman

---

## Tech Stack

| Layer | Choice |
|---|---|
| Backend | PHP 8+, WordPress Plugin API, `$wpdb` custom tables |
| Architecture | PSR-4 autoloading (Composer), namespaced OOP, interface-driven modules |
| API | WP REST API under `billnest/v1` |
| Admin UI | Vanilla JS / Alpine.js (no heavy JS framework) |
| Styling | Plain CSS or compiled Tailwind (no CDN, for marketplace compliance) |
| PDF | dompdf (via Composer) |
| Charts | Chart.js (local copy) |
| DB | Custom tables via `dbDelta()`, InnoDB only (transaction support) |

---

## Naming Conventions

| Item | Value |
|---|---|
| Plugin Slug | `billnest` |
| Namespace | `BillNest\` (PSR-4, root `includes/`) |
| Text Domain | `billnest` |
| DB Table Prefix | `{$wpdb->prefix}billnest_` |
| Constant Prefix | `BILLNEST_` |
| REST Namespace | `billnest/v1` |
| Capability | `manage_billnest` |

---

## Architecture Principles

- **No Custom Post Types** — own DB tables for performance at scale.
- **Interface-driven modules** — every feature (`Products`, `Customers`, `Invoices`...) implements `BillNest\Module`, registered through `BillNest\Loader`. A failing module logs its error and is skipped; it never takes down the rest of the plugin.
- **Abstract admin page base** — every admin screen extends `BillNest\Admin\AdminPage`, keeping menu registration and capability checks consistent across pages.
- **Service layer separates business logic from data access** — DB/repository classes only run queries; service classes hold business rules (stock checks, invoice totals, numbering); admin/REST classes only handle input/output.
- **Views hold no logic** — template files only render markup.
- **Transaction-safe writes** — every multi-table write (e.g. invoice creation) wraps in `START TRANSACTION` / `COMMIT` / `ROLLBACK`. Requires InnoDB on every table.
- **Atomic invoice numbering** — dedicated counter table with row-level locking (`SELECT ... FOR UPDATE`), avoiding race conditions under concurrent POS terminals.
- **Soft deletes** — status flags over hard deletes, to preserve audit trail on invoices, products, and customers.

---

## Folder Structure (target)

```
billnest/
├── billnest.php                 # bootstrap: constants, hooks, autoload
├── composer.json                # PSR-4 autoload map
├── readme.txt                   # WordPress.org / marketplace readme
├── uninstall.php                # confirmed, destructive cleanup only
├── vendor/                      # composer dependencies
├── languages/                   # .pot translation file
└── includes/
    ├── Module.php                # interface every module implements
    ├── Loader.php                # registers + runs modules, isolates failures
    ├── Activator.php             # dbDelta table creation, capabilities
    ├── Deactivator.php
    ├── Admin/
    │   ├── AdminPage.php         # abstract base for all admin screens
    │   ├── AdminMenu.php         # central menu registry
    │   ├── ProductsPage.php
    │   ├── CustomersPage.php
    │   ├── PosPage.php
    │   ├── SalesListPage.php
    │   ├── DueCollectionPage.php
    │   ├── ReportsPage.php
    │   ├── SettingsPage.php
    │   └── views/                # markup only, no logic
    ├── Modules/
    │   ├── ProductsModule.php
    │   ├── CustomersModule.php
    │   ├── InvoicesModule.php
    │   ├── PaymentsModule.php
    │   └── ReportsModule.php
    ├── DB/                       # $wpdb query layer, one class per table
    │   ├── ProductRepository.php
    │   ├── CustomerRepository.php
    │   ├── InvoiceRepository.php
    │   ├── PaymentRepository.php
    │   └── StockLogRepository.php
    ├── Services/                 # business logic
    │   ├── InvoiceService.php    # create invoice, recalc totals, update stock
    │   ├── StockService.php
    │   ├── NumberingService.php  # atomic invoice numbering
    │   └── PdfService.php        # dompdf wrapper
    └── Api/                      # REST controllers
        ├── ProductsController.php
        ├── CustomersController.php
        ├── InvoicesController.php
        ├── PaymentsController.php
        └── ReportsController.php
```

New folders/files are added only when the feature that needs them is actually being built — no empty scaffolding ahead of time.

---

## Database Schema

All tables prefixed `{$wpdb->prefix}billnest_`. InnoDB required on every table (transaction support).

| Table | Purpose |
|---|---|
| `products` | code, name, category, unit, prices, stock_qty, reorder_level, status |
| `categories` | product categories, self-referencing parent_id |
| `customers` | name, contact, opening_balance, current_due |
| `invoices` | invoice_no, customer_id, totals, discount, status |
| `invoice_items` | line items per invoice |
| `payments` | payments against invoices/customers, for due collection history |
| `stock_log` | append-only ledger of every stock change — source of truth for `products.stock_qty` |
| `purchases` / `purchase_items` | supplier stock-in, mirrors invoices structure |
| `suppliers` | supplier records |
| `cashbook` | auto-populated cash in/out ledger |
| `counters` | atomic sequence generator for invoice numbering |
| `settings` | business info, currency, invoice format, tax — stored as one serialized option |

---

## REST API (planned)

Base: `/wp-json/billnest/v1/`

| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/products?search=` | Search/list products |
| POST | `/products` | Create product |
| GET | `/customers?search=` | Search customers |
| POST | `/invoices` | Create invoice |
| GET | `/invoices/{id}` | Get invoice detail |
| GET | `/invoices?date_from=&date_to=&customer_id=` | List/filter |
| POST | `/invoices/{id}/return` | Process return |
| POST | `/payments` | Record due collection payment |
| GET | `/reports/sales-summary?range=` | Dashboard data |

All write routes require `current_user_can('manage_billnest')` and nonce verification.

---

## Feature Roadmap

### Phase 1 — Foundation ✅ (in progress)
- [x] Plugin bootstrap, constants, activation/deactivation hooks
- [x] Composer PSR-4 autoloading
- [x] `products` table via `dbDelta()`
- [x] `manage_billnest` capability
- [x] Module/Loader architecture
- [x] Admin menu skeleton (BillNest → Products)
- [ ] Settings page (business info, currency, invoice format)
- [ ] Remaining core tables (categories, customers, invoices, invoice_items, payments, stock_log, cashbook, counters)

### Phase 2 — Master Data
- [ ] Product CRUD + categories (repository → service → admin page → REST)
- [ ] Customer CRUD, opening balance, due tracking

### Phase 3 — Core POS
- [ ] POS/Sales Invoice screen — customer search, product quick-add, live totals
- [ ] Transaction-safe invoice save (invoice + items + stock_log + cashbook, atomic)
- [ ] Atomic invoice numbering service
- [ ] Sales list — filter, view, print

### Phase 4 — Money Flow
- [ ] Due collection screen
- [ ] Cashbook view
- [ ] Payment recording

### Phase 5 — Returns & Purchases
- [ ] Sales return (reverse stock, adjust due)
- [ ] Purchase module — supplier, purchase invoice, stock-in

### Phase 6 — Reports & Polish
- [ ] Dashboard with Chart.js
- [ ] PDF invoice export (dompdf)
- [ ] Sales / profit / stock / due reports

### Phase 7 — Resale Readiness
- [ ] `readme.txt` finalized, all strings translation-ready
- [ ] No hardcoded business references
- [ ] License/activation system (Freemius or custom) if sold externally
- [ ] Cross-version testing (PHP/WP)
- [ ] `uninstall.php` — confirmed table drop

---

## Development Process

This plugin is being built incrementally, one function/page at a time, rather than scaffolded all at once — each piece is written, tested locally, and confirmed working before the next is added. This keeps the codebase lean and avoids the loose, hard-to-maintain sprawl that comes from generating a large file/folder structure upfront.

Local testing: WAMP, activate/deactivate cycle after every structural change, `wp-content/debug.log` checked for silently-caught module errors (the `Loader` catches and logs `Throwable` per module rather than crashing the whole plugin).

---

## Setup

```bash
git clone <repo-url> billnest
cd billnest
composer install
```

Activate through WP Admin → Plugins → BillNest.

---

## Changelog

### 1.0.0 (in development)
- Plugin bootstrap, PSR-4 architecture, products table, admin menu foundation