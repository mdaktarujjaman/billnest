# BillNest

Universal WordPress POS, invoicing, inventory & basic accounting plugin. Standalone, self-contained, no external ERP dependency. Built for small-to-mid retail/service businesses, marketplace-resale-ready.

**Brand:** ITzone360
**Author:** Md Aktarujjaman

---

## Current Status

Working and tested locally:

- Products (code, model, unit, purchase/sale price, reorder level, image, low-stock badge), Categories, Customers, with soft delete
- Stock changes only through `StockService`, with an append-only `stock_log`
- POS: multi-item sales, live change/due display, flat and percent discount (limits enforced on the server)
- Atomic invoice numbering and transaction-safe invoice save
- Sales List, Invoice Detail with business header and Print
- Sales Return (whole invoice): restock, due adjustment, cashbook entry
- Due Collection (customer level, FIFO against oldest invoices)
- Cashbook with automatic entries from sales, returns and collections, plus manual entries
- Settings (business info, currency symbol and position)

Not built yet: split payments, card offers, purchases, reports, PDF, REST API. See the roadmap below.

---

## Tech Stack

| Layer | Choice |
|---|---|
| Backend | PHP 8+, WordPress Plugin API, `$wpdb` custom tables |
| Architecture | PSR-4 autoloading (Composer), namespaced OOP, interface-driven modules |
| API | WP REST API under `billnest/v1` (planned) |
| Admin UI | Vanilla JS, small jQuery only where the WordPress media library needs it |
| Styling | Plain CSS in `assets/admin/css`, no CDN assets |
| PDF | dompdf via Composer (planned) |
| Charts | Chart.js, local copy (planned) |
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

- **No Custom Post Types.** Own DB tables for performance at scale.
- **Interface-driven modules.** Modules implement `BillNest\Module` and are registered through `BillNest\Loader`. A failing module logs its error and is skipped; it never takes down the rest of the plugin.
- **Abstract admin page base.** Every admin screen extends `BillNest\Admin\AdminPage`, keeping menu registration and capability checks consistent.
- **One admin page class + one repository (+ one service when there is business logic) per feature.** No large file sprawl.
- **Service layer separates business logic from data access.** Repositories only run queries; services hold business rules (stock, invoice totals, numbering, returns, payments); admin pages handle input and output.
- **Server is the source of truth.** Prices, discount limits and payment limits are validated on the server. Browser checks are only a convenience.
- **Transaction-safe writes.** Every multi-table write (invoice creation, return, due collection) runs inside `START TRANSACTION` / `COMMIT` / `ROLLBACK`.
- **Atomic invoice numbering.** A counter table with row-level locking (`SELECT ... FOR UPDATE`) avoids race conditions on concurrent terminals.
- **Soft deletes.** Status flags over hard deletes, to preserve the audit trail.
- **Security basics on every page.** Capability check, nonces on writes, sanitized input, escaped output, `$wpdb->prepare()` for user data.

---

## Folder Structure (current)

```
billnest/
├── billnest.php                 # bootstrap: constants, hooks, autoload
├── composer.json                # PSR-4 map: BillNest\ => includes/
├── readme.txt                   # WordPress.org readme (to be finalized)
├── assets/admin/
│   ├── css/                     # pos.css, invoice.css, products.css
│   └── js/                      # products.js (media library picker)
└── includes/
    ├── Module.php               # interface every module implements
    ├── Loader.php               # runs modules, isolates failures
    ├── Activator.php            # dbDelta tables, capability, db version
    ├── Deactivator.php
    ├── Modules/
    │   └── ProductsModule.php   # registers all admin pages (to be renamed AdminModule)
    ├── Admin/
    │   ├── AdminPage.php        # abstract base
    │   ├── AdminMenu.php
    │   └── ProductsPage, CategoriesPage, CustomersPage, PosPage,
    │       SalesListPage, DueCollectionPage, CashbookPage, SettingsPage
    ├── DB/                      # $wpdb query layer
    │   └── Product, Category, Customer, Invoice, Cashbook, Payment repositories
    └── Services/                # business logic
        └── NumberingService, StockService, InvoiceService,
            ReturnService, PaymentService, SettingsService
```

New folders and files are added only when the feature that needs them is being built.

---

## Database Schema

All tables prefixed `{$wpdb->prefix}billnest_`, InnoDB. Integrity is enforced in PHP, not with foreign keys. Status columns are VARCHAR validated in PHP.

| Table | Purpose |
|---|---|
| `products` | code (unique), name, category, model, unit, purchase/sale price, stock_qty, reorder_level, image_url, status |
| `categories` | product categories with parent_id |
| `customers` | contact, opening_balance, current_due, status |
| `counters` | atomic sequence generator for invoice numbering |
| `invoices` | invoice_no, customer, subtotal, discount type/value/amount, total, received, due, status |
| `invoice_items` | line items per invoice |
| `stock_log` | append-only ledger of every stock change (sale, sales_return, adjustment) |
| `cashbook` | cash in/out ledger (sale, sales_return, payment, manual) |
| `payments` | customer-level due collections |

Options: `billnest_settings` (business info and currency) and `billnest_db_version`.

Planned tables: `invoice_payments` (split payments), `suppliers`, `purchases`, `purchase_items`, `card_offers`.

`dbDelta()` runs on activation only, so after a schema change deactivate and activate the plugin.

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

All write routes require `current_user_can('manage_billnest')`, a nonce, and a `permission_callback`. List endpoints support `page` and `per_page`.

---

## Feature Roadmap

### Phase 1: Foundation (done)
- [x] Bootstrap, constants, activation/deactivation hooks, Composer PSR-4
- [x] Module/Loader architecture, admin menu
- [x] All core tables, `manage_billnest` capability
- [x] Settings page and currency formatting

### Phase 2: Master Data (done)
- [x] Product CRUD with categories, extended product fields, low-stock badge
- [x] Customer CRUD, opening balance, due tracking

### Phase 3: Core POS (mostly done)
- [x] POS with multi-item sales and live totals
- [x] Flat and percent discount
- [x] Transaction-safe invoice save, atomic numbering
- [x] Sales list, invoice detail, print
- [ ] Discount line on the invoice detail page
- [ ] Sales list filters and pagination
- [ ] Customer and product search
- [ ] "Allow negative stock" setting and stock check at sale

### Phase 4: Money Flow (done)
- [x] Due collection with FIFO allocation
- [x] Cashbook with automatic and manual entries
- [ ] Split payments (cash, card, mobile) with `invoice_payments`
- [ ] Non-cash receipts kept separate from the cash drawer

### Phase 5: Returns and Purchases
- [x] Sales return, whole invoice
- [ ] Partial (item-wise) return
- [ ] Purchase module: suppliers, purchase invoices, stock-in
- [ ] Card offers (discount rules per bank/card)

### Phase 6: Reports and Polish
- [ ] Dashboard with Chart.js
- [ ] PDF invoice (dompdf)
- [ ] Sales, profit, stock and due reports
- [ ] Roles (cashier vs admin)
- [ ] REST API

### Phase 7: Resale Readiness
- [ ] `readme.txt` finalized, all strings translation-ready, `.pot` file
- [ ] `uninstall.php` with confirmed table drop
- [ ] No hardcoded business references
- [ ] License/activation system if sold externally
- [ ] PHP/WP version testing, bundled `vendor/` for release builds

---

## Known Gaps

- A walk-in sale with a due has no customer to collect from.
- Stock can currently go negative (no check at sale yet).
- Opening-balance payments are not tracked separately from invoice dues.
- No invoice edit or cancel, and no pagination on lists.
- Returned invoices still show their original Received and Due figures.

---

## Development Process

The plugin is built incrementally, one function or page at a time. Each piece is written, tested locally and confirmed before the next one is added. This keeps the codebase lean.

Local testing: WAMP, deactivate and activate after structural changes, and check `wp-content/debug.log` for errors the `Loader` has caught per module.

---

## Setup

```bash
git clone <repo-url> billnest
cd billnest
composer install
```

Activate through WP Admin, Plugins, BillNest.

---

## Changelog

### 1.0.0 (in development)
- Plugin bootstrap, PSR-4 architecture, admin menu
- Products, categories, customers
- POS with discount, invoices, sales list, print
- Sales return, due collection, cashbook, settings