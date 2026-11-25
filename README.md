# BillNest

Universal WordPress POS, invoicing, inventory & basic accounting plugin. Standalone, self-contained, no external ERP dependency. Built for small-to-mid retail/service businesses, marketplace-resale-ready.

**Brand:** ITzone360  
**Author:** Md Aktarujjaman

---

## Contents

- [Current Status](#current-status)
- [Admin Pages](#admin-pages)
- [Point of Sale in Detail](#point-of-sale-in-detail)
- [Sales List in Detail](#sales-list-in-detail)
- [Suppliers, Purchases and Supplier Payments in Detail](#suppliers-purchases-and-supplier-payments-in-detail)
- [Settings](#settings)
- [Tech Stack](#tech-stack)
- [Naming Conventions](#naming-conventions)
- [Architecture Principles](#architecture-principles)
- [Folder Structure](#folder-structure-current)
- [Database Schema](#database-schema)
- [Business Rules](#business-rules)
- [REST API (planned)](#rest-api-planned)
- [Feature Roadmap](#feature-roadmap)
- [Known Gaps](#known-gaps)
- [Development Process](#development-process)
- [Setup](#setup)
- [Changelog](#changelog)

---

## Current Status

Working and tested locally:

- Products (code, model, unit, purchase/sale price, reorder level, image, low-stock badge), Categories, Customers, all with soft delete
- Stock changes only through `StockService`, with an append-only `stock_log`
- **Stock check at sale:** with *Allow negative stock* turned off (the default), a sale cannot take a product below zero stock. The check runs inside the invoice transaction with a row lock, and lines for the same product are added together before checking. Turning the setting on allows selling beyond the stock
- POS: multi-item sales, flat and percent discount (limits enforced on the server), live totals, tendered amount with change or due
- **POS product search:** type a code, name or model instead of scrolling a dropdown. Results show price and stock, and can be picked with the keyboard
- **POS customer search:** find a customer by name, phone or email. Results show the current due, and the picked customer's due is shown under the box. A walk-in sale needs no customer
- **Live stock warning in the POS:** the available stock is shown under each selected product, and a warning appears when the requested quantity (all lines of the same product added together) is more than the stock. The server check stays the final word
- **The POS keeps the form after a server error:** if the server rejects a sale (for example not enough stock), the customer, products, quantities, discount and payment lines come back filled in. A saved sale empties the form
- **Split payments:** one bill can be paid with several methods (cash, card, bKash, Nagad, Rocket). Card and mobile payments require a reference. Non-cash payments can never exceed the bill, and the server enforces it
- **Online payment discount rules:** an admin defines rules per payment method (flat or percent, optional maximum, minimum order, start and end date). When the whole bill is paid with one online method, the POS applies the best matching rule automatically and the server recalculates it independently. A manual discount always wins over a rule
- Atomic invoice numbering and a transaction-safe invoice save. A failed save rolls back the invoice rows. Whether the invoice number counter rolls back with them is not confirmed yet, see *Verification status*
- Sales List with filters (invoice number or customer, date range, status), pagination and a summary line. Invoice Detail with business header, payment lines and Print
- **Sales Return (whole invoice):** restock, due adjustment, refund per original method, cash-only cashbook entry. Every return is recorded in `invoice_returns` and `invoice_return_items`, so a return has its own history, shown in a Returns table on the invoice detail page
- Due Collection (customer level, FIFO against oldest invoices). It uses the same payment methods as the POS (cash, card, bKash, Nagad, Rocket), card and mobile need a reference, and every invoice a collection lands on gets its own `invoice_payments` row. Only cash enters the cashbook
- Cashbook with automatic entries from sales, returns, collections, purchases and supplier payments, plus manual entries. Only cash enters the cashbook, card and mobile payments stay in their payment tables. A failed cashbook insert stops the whole operation instead of passing silently
- Settings (business info, currency symbol and position, negative stock)

Built but only partly checked:

- **Partial (item-wise) return.** The invoice detail page has a return quantity per line, a Returned column and a Returns history table, backed by `ReturnService::process_partial_return`. It has never been run
- **Suppliers, purchases and supplier payments.** Suppliers (with an opening balance), a purchase entry page with product search, live totals and split payments that adds stock, and a Pay form that settles what is owed to a supplier oldest purchase first. Checked against the database so far: a purchase without a supplier paid in full in cash, a purchase with a supplier paid partly in cash and bKash, and one cash payment to a supplier. The rejection cases, a mobile payment to a supplier and the opening balance part of a payment are still with QA. `StockService` was changed in the same work (it now throws when a write fails), and the sale and the whole-invoice return were checked against the database after that change
- **Purchase List and purchase detail.** A list with filters, a summary line and pagination, and a detail page with the items, totals, payments and Print. The list, its summary figures and one detail page were checked against the database. The filters, pagination and printing are still with QA

See *Verification status* for exactly what has and has not been seen.

Not built yet: purchase return, reports, PDF invoices, REST API, roles. See the roadmap below.

### Verification status

A short list of what is still to be confirmed (some of it is handed to the QA team), so nothing is assumed to work before it is seen working:

- Discount Rules page: add is verified. Edit, validation errors (percent above 100, end date before start date, the `cash` method being rejected) and Deactivate are still to be confirmed
- Percent discount above 100 in the POS (a flat discount above the subtotal is verified)
- Stock check: two lines of the same product going over the stock are flagged live in the POS, but the matching server-side rejection on save has not been seen yet (a single line going over the stock is rejected and saves nothing)
- POS product search: the "No products found" message, and clearing a selection by editing the search text
- POS customer search: picking a customer in the browser, the due note, the rule that disables Save while text is typed without picking a customer, and a sale saved with a customer
- Form kept after a server error: the refill of customer, products, discount and payment lines, and the empty form after a saved sale with a customer
- The POS script moved to `assets/admin/js/pos.js`: page load, the data handed over in `window.billnestPos` and a sale with an online payment discount were checked by the author after the move. The rest of the POS checks above still apply to the new file
- Sales List: the filter bar and summary line are seen working. Pagination, each filter (invoice number or customer, date range, status), the summary figures against the database, and filters surviving a page change are still to be confirmed
- Server-side limits on the Due Collection and Cashbook forms
- Returns, seen working: a whole-invoice return of a sale paid with cash and bKash restocks both items, refunds each method in full, puts only the cash part in the cashbook and marks the invoice Returned. Its return row is in `invoice_returns` and the invoice detail page shows the Returned column and the Returns table for it
- Returns, still to be confirmed: a whole-invoice return of a sale with a customer due (the due must be reduced first, then only the rest refunded), a return of an invoice that had a discount, returning an already returned invoice, returning old invoices created before split payments existed, and returning an invoice whose due was paid later through Due Collection (such a return stops with an error and saves nothing when the recorded payments cannot cover the refund)
- Partial return: the screen and the logic are written but nothing has been run in the browser. The return quantity fields and button on a completed invoice (they do show on a completed invoice, but nothing was submitted), the proportional discount, the quantity limits and their error messages, the refund split across methods, finishing the remaining items after a partial return, the Partially returned label, and hiding the return column when printing are all unverified
- Due Collection: the page loads, the payment method list and the Reference field show up, a card or mobile payment without a reference is rejected and saves nothing, and a cash collection of 100 was seen reducing the customer due, adding an invoice payment row and a cashbook entry. A bKash (or other non-cash) collection with a reference, which must not touch the cashbook, has not been run
- Card, Nagad and Rocket methods have not been saved yet (only cash and bKash)
- **`StockService` on the sales path:** after `StockService` was changed to throw on failed writes, a POS sale of one Hoodie paid in cash (invoice `26110028`) and its whole-invoice return were checked against the database. The sale lowered the stock by one and wrote a `sale` row in `stock_log`, a cash `in` row in the cashbook and a cash payment row on the invoice. The return raised the stock by one, wrote a negative `Refund` payment row, a cash `out` row in the cashbook and an `invoice_returns` row, and the invoice became Returned. The failure path (a stock write that fails and rolls the sale back) has not been run
- **Invoice number rollback:** `NumberingService::get_next_invoice_number()` starts and commits its own transaction. If `InvoiceService` calls it inside its own transaction, the invoice number counter may not roll back with a failed sale. `InvoiceService.php` has not been read for this, so the statement that a failed save also rolls back the number is not confirmed
- Suppliers, seen: the Suppliers page loads, its menu item sits after Customers, adding a supplier with an opening balance of 500 shows ৳500.00 in the list and in `current_payable`, and the Pay link appears while something is owed. Still to be confirmed: no Opening Balance field when editing, delete blocked while a balance is owed and soft delete at zero, an empty name, a negative opening balance
- Purchases, seen: the Purchases page loads, picking a product fills the unit cost from its purchase price, line totals, Subtotal, Paid and Owed update live, the Reference field is disabled for cash and enabled for bKash, rows are added with **+ Add Item** and **+ Add Payment**, and a saved purchase shows its number and empties the form. Still to be confirmed: the stock figure in the dropdown, the yellow warnings, removing rows with ×, and the form coming back after an error (`Line 1: choose a product from the list.`)
- Purchase save, seen in the database: a purchase without a supplier paid in full in cash (`P26110001`) and a purchase with a supplier, part paid in cash and bKash (`P26110002`: total 7000, paid 3000, due 4000). Stock went up for every line, `stock_log` got `purchase` rows, each product's `purchase_price` became the line's unit cost, one cashbook `out` row was written for the cash part only, the supplier's payable rose by the unpaid part and the purchase counter went up. Still to be confirmed: a part payment without a supplier (rejected), a non-cash payment without a reference, payments above the total, and an unchanged database after every rejection
- Supplier payments, seen in the database: a cash payment of 1500 to a supplier owing 3000 (2500 on a purchase plus a 500 opening balance). The payment went to the open purchase (paid 4500, due 2500) as a `purchase_payments` row carrying `supplier_payment_id`, a `supplier_payments` row and a cashbook `out` row with source `supplier_payment` were written, and the payable fell to 3000. The Pay page shows the payment history. Still to be confirmed: a mobile payment (cashbook unchanged), a payment that runs past the purchase into the opening balance (no `purchase_payments` row for that part), a missing reference, an amount above the balance, zero and negative amounts, and the Pay link going away at a zero balance
- Purchase List, seen: the page loads and its menu item sits after Purchases, the summary line (2 purchases, 43500 purchased, 41000 paid, 2500 owed) matches a query on the `purchases` table, the rows match the database, and the detail page of `P26110002` shows both item lines, the totals and all three payment rows, the later supplier payment marked as such. Still to be confirmed: each filter, the Owing only filter, pagination and keeping the filters across pages, the detail page of a purchase without a supplier, and Print and the print layout

---

## Admin Pages

All pages live under the **BillNest** menu and need the `manage_billnest` capability.

| Page | Purpose |
|---|---|
| BillNest | Plugin landing page |
| Products | Create, edit and soft delete products. Low-stock badge when stock reaches the reorder level |
| Categories | Product categories with a parent category |
| Customers | Customer records with opening balance and current due |
| Suppliers | Supplier records with an opening balance and the current payable. A Pay form settles what is owed, with a payment history |
| Purchases | Purchase entry: product search, quantities and unit costs, split payments, live totals. Adds stock |
| Purchase List | All purchases with filters, pagination and a summary, plus a detail view with items, payments and print |
| New Sale | The POS: product and customer search, quantities, discount, split payments, live totals |
| Sales List | All invoices with filters, pagination and a summary, plus a detail view, print, whole-invoice return and item-wise return |
| Due Collection | Customer-level payments (cash, card, bKash, Nagad, Rocket) allocated first-in-first-out against the oldest open invoices |
| Cashbook | Cash in and out ledger, automatic and manual entries |
| Discount Rules | Online payment discount rules |
| Settings | Business info, currency, negative stock |

---

## Point of Sale in Detail

**Choosing a customer**
- The customer box searches the name, phone and email. With several words, every word has to match. Names starting with the typed text come first, then phone numbers starting with it
- Each result shows the phone, email and the current due (in red when the customer owes money). After picking a customer, a yellow note under the box shows the current due
- Leaving the box empty, or pressing the clear button, means a walk-in sale
- If text is typed but no customer is picked from the list, **Save Invoice** stays disabled and a note explains why, so a sale is never saved as walk-in by mistake
- The customers are filtered in the browser from the list loaded with the page

**Choosing products**
- Each line has a search box. It matches the product code, name and model. With several words, every word has to match (for example `hood 680`)
- An exact code match comes first, then codes starting with the typed text, then names starting with it
- Up to 10 results are shown, each with name, price, code, model and stock. A product with no stock is marked in red
- Keyboard: arrow keys move through the results, Enter picks the highlighted one, Escape closes the list. After a product is picked the cursor jumps to the quantity field
- Editing the text of an already picked product clears the choice until a new one is picked
- **Save Invoice** stays disabled until at least one product is picked
- The products are filtered in the browser from the list loaded with the page. Stock and prices are a snapshot taken when the page loads

**Stock warnings**
- A selected product shows `Available: N` under it
- If the quantity (all lines of the same product added together) is more than the stock, a yellow warning shows `Only N in stock, M requested`
- With *Allow negative stock* on, no warning is shown, only the stock figure
- The warning does not block saving. The server check decides

**Totals and payments**
- Subtotal, discount, total, paid and change or due update live
- A manual flat or percent discount can be entered. When the whole bill is paid with one online method and no manual discount is set, the best matching online payment discount rule is previewed as "Online payment discount"
- Card and mobile lines need a reference and cannot exceed the bill. The POS shows how much they exceed it by and disables Save. The server enforces the same rule
- The figures in the browser are only a preview. The server calculates everything again

**After a save**
- A saved sale shows the invoice number and the form is emptied
- If the server rejects the sale, an error shows and the form is refilled with what was entered. Prices, stock and the customer's due are taken from the freshly loaded data, not from the earlier page. A product or customer that is no longer active is left out

**How the page is built**
- `PosPage` renders the form and hands the products, customers, payment methods, discount rules, the form to restore and the currency settings to the browser through `wp_add_inline_script` as `window.billnestPos`
- The behaviour lives in `assets/admin/js/pos.js`, loaded in the footer with its file modification time as the version. If the form is not on the page the script stops quietly

---

## Sales List in Detail

**Filters** (all optional, combined with AND)
- **Invoice no or customer:** matches part of the invoice number or the customer name
- **From / To:** invoice date range. If From is later than To, the two are swapped
- **Status:** All, Completed or Returned
- **Reset** clears every filter

**Pagination**
- 20 invoices per page, newest first. The page links keep the active filters
- The filtering, counting and paging all run in SQL (`LIMIT` / `OFFSET`), the whole list is never loaded into PHP

**Summary line**
- Number of invoices matching the filters, then the sales total and the due
- The sales total and the due count **completed invoices only**. A returned invoice still shows its original total (see Known Gaps), so counting it would overstate the figure
- The due includes sales to walk-in customers. A walk-in due has no customer to collect from, so this figure can be higher than the total due shown for customers

**Invoice detail**
- Business header, customer, date, line items, totals, the payments table (sale payments, later due collections and refunds) and print
- On a completed invoice each line shows how much was already returned and a return quantity field (up to what is left, two decimals). **Return selected items** returns those quantities, **Process Return** returns everything that is left
- A Returns table lists every return with its items total, discount share, return total, due reduced and refund. A completed invoice that was partly returned is labelled Partially returned
- The return fields and buttons are hidden when printing

---

## Suppliers, Purchases and Supplier Payments in Detail

**Suppliers**
- Name, phone, email, address and an **Opening Balance** (the amount already owed to the supplier, zero or more). The opening balance is set only when the supplier is added, it cannot be edited later. `current_payable` starts equal to it
- The list shows the current payable. A **Pay** link appears only while something is owed
- A supplier cannot be deleted while `current_payable` is not zero. Delete is a soft delete (`inactive`)

**Purchase entry**
- Pick a supplier (optional) and a date, then add product rows. Each row has a search box (code, name or model, up to 10 results with the stock), a quantity and a unit cost. The unit cost starts from the product's last purchase price and can be changed. Picking a product fills the quantity with 1 when it is empty
- Payment rows (method, amount, reference) work as in the POS: card and mobile need a reference, the Reference field is disabled for cash
- The page shows the line totals, Subtotal, Paid and **Owed to supplier** live, and a yellow warning when the payments exceed the total or when a balance is left without a supplier. The warnings are previews, the server decides
- Rows are added and removed with **+ Add Item**, **+ Add Payment** and the × buttons. A payment row with an empty amount is ignored
- If the server rejects the purchase, the error shows and the form comes back filled in. A saved purchase shows its number (`Purchase P26110001 saved...`)
- The page needs JavaScript to build its rows. `PurchasePage` hands the products, payment methods, the form to restore and the currency settings to the browser through `wp_add_inline_script` as `window.billnestPurchase`, and the behaviour lives in `assets/admin/js/purchase.js`

**Paying a supplier**
- From the Suppliers list, **Pay** opens a form (amount, method, reference, remarks) with the balance owed and the payment history of that supplier. The amount starts at the full balance
- The payment settles the oldest open purchases first. Whatever is left after the open purchases is taken from the opening balance part of the payable

**Purchase List and detail**
- **Filters** (all optional, combined with AND): purchase number or supplier name, From / To purchase date (swapped if From is later than To), and Balance (All or Owing only, meaning purchases that still have a balance owed). **Reset** clears them
- **Pagination:** 20 purchases per page, newest first, the page links keep the active filters. Filtering, counting and paging all run in SQL
- **Summary line:** number of purchases matching the filters, total purchased, total paid and total owed to suppliers
- **Detail:** business header, supplier (or No supplier), date, the item lines (product code and name, quantity, unit cost, total), Total, Paid and Owed to supplier, a Payments table and Print. Each payment shows its date, method, reference, whether it was made **with the purchase** or later as a **supplier payment**, and the amount
- The part of a supplier payment that was taken from the opening balance has no `purchase_payments` row, so it does not show here. It shows in the supplier's Pay history
- The pages only read data. They do not change stock, balances or the cashbook

---

## Settings

| Setting | Default | Notes |
|---|---|---|
| Business Name, Phone, Address | Site name, empty, empty | Shown in the invoice header |
| Currency Symbol | `$` | Used everywhere amounts are shown |
| Symbol Position | Before amount | Before or after the amount |
| Allow negative stock | Off | When off, a sale that needs more than the available stock is rejected and nothing is saved |

Settings are stored in the `billnest_settings` option. A setting missing from an older saved option falls back to its default.

---

## Tech Stack

| Layer | Choice |
|---|---|
| Backend | PHP 8.0+ (8.1+ recommended), WordPress Plugin API, `$wpdb` custom tables |
| Architecture | PSR-4 autoloading (Composer), namespaced OOP, interface-driven modules |
| API | WP REST API under `billnest/v1` (planned) |
| Admin UI | Vanilla JS, small jQuery only where the WordPress media library needs it |
| Styling | Plain CSS in `assets/admin/css`, no CDN assets |
| PDF | dompdf via Composer (planned) |
| Charts | Chart.js, local copy (planned) |
| DB | Custom tables via `dbDelta()`, InnoDB only (transaction support) |

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

## Architecture Principles

- **No Custom Post Types.** Own DB tables for performance at scale.
- **Interface-driven modules.** Modules implement `BillNest\Module` and are registered through `BillNest\Loader`. A failing module logs its error and is skipped; it never takes down the rest of the plugin.
- **Abstract admin page base.** Every admin screen extends `BillNest\Admin\AdminPage`, keeping menu registration and capability checks consistent.
- **One admin page class + one repository (+ one service when there is business logic) per feature.** No large file sprawl.
- **Service layer separates business logic from data access.** Repositories only run queries; services hold business rules (stock, invoice totals, numbering, returns, payments, purchases, discount rules); admin pages handle input and output.
- **Server is the source of truth.** Prices, discounts, discount rules, stock, payment limits, return amounts, purchase totals and supplier balances are validated and calculated on the server. The browser only previews them.
- **Transaction-safe writes.** Every multi-table write (invoice creation, return, due collection, purchase, supplier payment) runs inside `START TRANSACTION` / `COMMIT` / `ROLLBACK`. A repository write that fails throws, so the transaction rolls back instead of committing half of the work. `StockService` throws too when a stock update or its log row fails.
- **Atomic numbering.** A counter table with row-level locking (`SELECT ... FOR UPDATE`) avoids race conditions on concurrent terminals. The invoice number method runs its own transaction, the purchase number method joins the caller's transaction.
- **Soft deletes.** Status flags over hard deletes, to preserve the audit trail.
- **Security basics on every page.** Capability check, nonces on writes, sanitized input, escaped output, `$wpdb->prepare()` for user data. Read-only filters (like the Sales List and the Purchase List) use sanitized GET parameters and prepared queries, no nonce is needed.

## Folder Structure (current)

```
billnest/
├── billnest.php                 # bootstrap: constants, hooks, autoload
├── composer.json                # PSR-4 map: BillNest\ => includes/
├── readme.txt                   # WordPress.org readme (to be finalized)
├── assets/admin/
│   ├── css/                     # pos.css, invoice.css, products.css, purchase.css
│   └── js/                      # products.js (media library picker), pos.js (POS behaviour), purchase.js (purchase page behaviour)
└── includes/
    ├── Module.php               # interface every module implements
    ├── Loader.php               # runs modules, isolates failures
    ├── Activator.php            # dbDelta tables, capability, db version
    ├── Deactivator.php
    ├── Modules/
    │   └── AdminModule.php      # registers all admin pages
    ├── Admin/
    │   ├── AdminPage.php        # abstract base
    │   ├── AdminMenu.php
    │   └── ProductsPage, CategoriesPage, CustomersPage, SuppliersPage,
    │       PurchasePage, PurchaseListPage, PosPage, SalesListPage,
    │       DueCollectionPage, CashbookPage, DiscountRulesPage, SettingsPage
    ├── DB/                      # $wpdb query layer
    │   └── Product, Category, Customer, Supplier, SupplierPayment,
    │       Purchase (entry, list search, summary, detail), Invoice (list search, summary, row lock),
    │       InvoicePayment, InvoiceReturn, Cashbook, Payment,
    │       DiscountRule repositories
    └── Services/                # business logic
        └── NumberingService, StockService (stock changes and stock check),
            InvoiceService, ReturnService, PaymentService, PaymentMethods,
            PurchaseService, SupplierPaymentService,
            DiscountRuleService, SettingsService
```

New folders and files are added only when the feature that needs them is being built.

## Database Schema

All tables prefixed `{$wpdb->prefix}billnest_`, InnoDB. Integrity is enforced in PHP, not with foreign keys. Status columns are VARCHAR validated in PHP.

| Table | Purpose |
|---|---|
| `products` | code (unique), name, category, model, unit, purchase/sale price, stock_qty, reorder_level, image_url, status |
| `categories` | product categories with parent_id |
| `customers` | name, phone, email, address, opening_balance, current_due, status |
| `counters` | atomic sequence generator: rows `invoice` and `purchase` (the `purchase` row is created by the first purchase) |
| `invoices` | invoice_no, customer, subtotal, discount type (`flat`, `percent`, `rule`) / value / amount, `discount_rule_id`, total, received, due, status (`completed`, `returned`) |
| `invoice_items` | line items per invoice |
| `invoice_payments` | one row per payment on an invoice (method, amount, reference): the sale payments, later due collections (reference `Due collection`) and negative `Refund` rows from returns |
| `invoice_returns` | one row per return event: items_total, discount_share, return_total, due_reduced, refund_total, created_by |
| `invoice_return_items` | one row per returned line, pointing at the original `invoice_item_id`, with qty, unit_price and amount |
| `discount_rules` | online payment discount rules: method, type (`flat` / `percent`), value, max_discount, min_order, start_date, end_date, status |
| `suppliers` | name, phone, email, address, opening_balance, current_payable, status |
| `purchases` | purchase_no (unique), supplier_id (empty when no supplier), purchase_date, subtotal, total, paid_amount, due_amount, remarks, status (`completed`), created_by |
| `purchase_items` | one row per purchased line: product, qty, unit_cost, total |
| `purchase_payments` | one row per payment on a purchase (method, amount, reference). `supplier_payment_id` is set when the row comes from a later supplier payment, and empty for payments made at purchase time |
| `supplier_payments` | one row per payment to a supplier: supplier_id, amount, method, reference, remarks, payment_date, created_by |
| `stock_log` | append-only ledger of every stock change (types `sale`, `sales_return`, `purchase`, `adjustment`) |
| `cashbook` | cash in/out ledger (sources `sale`, `sales_return`, `payment`, `manual`, `purchase`, `supplier_payment`) |
| `payments` | customer-level due collections |

Options: `billnest_settings` (business info, currency and negative stock) and `billnest_db_version`.

`dbDelta()` runs on activation only, so after a schema change deactivate and activate the plugin.

Invoices created before split payments existed have no `invoice_payments` rows. The code treats their received amount as cash. Invoices returned before `invoice_returns` existed have status `returned` but no return row.

## Business Rules

**Stock**
- With *Allow negative stock* off, a sale that needs more than the available stock is rejected with `Not enough stock for "<product>". Available: X, requested: Y.` and nothing is saved
- The check runs inside the invoice transaction. The product rows are locked with `SELECT ... FOR UPDATE`, in product id order, so two terminals cannot sell the same last piece, and a deadlock between them is avoided
- Lines for the same product are added together before the check
- With the setting on, the check is skipped and stock can go below zero
- A product that is already below zero cannot be sold while the setting is off
- The server is the source of truth. The POS warning is only a convenience, and its figures are a snapshot taken when the page loaded
- Every stock increase or decrease goes through `StockService`, which writes the new stock and a `stock_log` row. If the update, the product lookup or the log insert fails it throws, so the surrounding transaction rolls back

**Payments**
- Supported methods: cash, card, bKash, Nagad, Rocket (one list in `PaymentMethods`, easy to extend)
- Card and mobile payments need a reference (transaction ID or approval code), cash does not
- Card and mobile payments cannot exceed the invoice total. Extra cash is returned as change, and only the part that stays in the drawer is recorded as received and entered in the cashbook

**Online payment discount**
- A rule applies only when the whole bill is paid with one online method. Partial payment or mixing methods gets no rule discount
- A manual flat or percent discount always wins. Only one discount applies to an invoice
- If several rules match, the one giving the largest discount is used
- The server calculates the discount itself. If the cashier enters the full bill amount in an online line, the server lowers that line to the discounted bill. An online amount above the original subtotal is rejected
- The invoice stores which rule was used (`discount_rule_id`) for later reporting
- A manual discount is capped on the server: a flat amount cannot be more than the subtotal and a percent cannot be more than 100. The stored value is the entered one, the stored discount amount is the capped one

**Returns**
- Every return runs in one transaction and locks the invoice row first, so two returns of the same invoice cannot run at the same time. Only a `completed` invoice can be returned
- A return can take back only what is left of each line: the original quantity minus what earlier returns already took. Quantities may have two decimals
- The money for a return is the price of the returned lines minus their proportional share of the invoice discount (`discount_amount` divided by `subtotal`). When the last remaining lines are returned, the return takes whatever is left of the invoice total, so rounding never loses or invents a cent
- The return amount first reduces the invoice's due (and the customer's due). Only the rest is refunded. A bill of 680 with 380 still due returns 380 as due and 300 as a refund
- The refund is split across the original payment methods in proportion to what each can still give back (paid minus already refunded). It can never exceed what the method received. If the recorded payments cannot cover the refund, the return stops with an error and saves nothing
- Only the cash part goes into the cashbook as `out`. Card and mobile refunds are recorded as negative `Refund` rows but must be paid back outside the software
- Stock goes back through `StockService` with the type `sales_return`
- The invoice becomes `returned` only when every line is fully returned. After a whole-invoice return its due is 0, while Received still shows the original amount
- The whole-invoice return uses the same logic as a partial one, taking every remaining quantity
- On the invoice detail page each line shows how much was already returned and takes a return quantity (up to what is left, two decimals). The Returns table lists every return with its items total, discount share, return total, due reduced and refund. A partly returned invoice is labelled Partially returned. The browser limit on the quantity is only a convenience, the server checks every quantity again

**Due collection**
- Customer level, amount must be above zero and not above the current due, allocated first-in-first-out against the oldest open invoices. Any part that is not tied to an invoice (the opening balance) gets no invoice payment row
- The method list is the same as in the POS. Card and mobile payments need a reference (up to 60 characters), checked on the server
- Each invoice a collection lands on gets an `invoice_payments` row with the method and the amount applied there, so a later return refunds that money by the method it came in. Only cash goes into the cashbook
- Collections made before this change left no `invoice_payments` rows, so returning such an invoice stops with an error instead of guessing the method

**Suppliers**
- The opening balance is entered only when the supplier is added and must be zero or more. `current_payable` starts equal to it. Editing a supplier never changes the opening balance
- A supplier whose `current_payable` is not zero cannot be deleted (`Pay the balance owed to this supplier before deleting it.`). Delete is a soft delete

**Purchases** (`PurchaseService::create_purchase`)
- Everything below is checked before the transaction starts: the date is valid, a chosen supplier is active, every line has an active product, a quantity above zero and a unit cost above zero (both rounded to two decimals), every payment has a valid method, an amount above zero and, for card and mobile, a reference of up to 60 characters. Payments above the purchase total are rejected
- If part of the total is left unpaid, a supplier must be chosen. Without a supplier the full amount has to be paid (`Choose a supplier to leave a balance, or pay the full amount.`)
- One transaction does the rest: it takes the next purchase number (a separate counter, `P` + year and month + four digits, for example `P26110001`), saves the purchase, and for every line saves the item row, adds the stock through `StockService` (type `purchase`) and sets the product's `purchase_price` to that line's unit cost. It then saves the payment rows, writes **one** cashbook `out` entry (source `purchase`) for the cash part only, and adds the unpaid part to the supplier's `current_payable`. If any write fails everything rolls back, including the purchase number
- The product's `purchase_price` becomes the **latest** purchase cost, not an average. If the same product is on two lines, the last line's cost wins
- Only cash enters the cashbook. Card and mobile payments are kept in `purchase_payments` only
- There is no purchase return yet

**Supplier payments** (`SupplierPaymentService::pay`)
- The amount must be above zero and not more than the supplier's `current_payable`. The method must be valid, and card and mobile need a reference (up to 60 characters)
- One transaction: it locks the supplier row (`FOR UPDATE`), re-checks the amount against the locked balance, saves a `supplier_payments` row, and settles the supplier's open purchases oldest first (by purchase date, then id). For each purchase it raises `paid_amount`, lowers `due_amount` and adds a `purchase_payments` row carrying the `supplier_payment_id`. Whatever is left after the open purchases is taken from the opening balance part and gets no `purchase_payments` row. Then `current_payable` goes down by the full amount. A cash payment adds one cashbook `out` entry (source `supplier_payment`, `ref_id` = the `supplier_payments` id). Any failure rolls everything back

**Cashbook**
- Only cash enters the cashbook: sales, returns, collections, purchases, supplier payments and manual entries

**Sales List figures**
- The summary counts every invoice matching the filters, but adds up the total and the due of completed invoices only, because returned invoices keep their original total for now

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

## Feature Roadmap

### Phase 1: Foundation (done)
- [x] Bootstrap, constants, activation/deactivation hooks, Composer PSR-4
- [x] Module/Loader architecture, admin menu
- [x] All core tables, `manage_billnest` capability
- [x] Settings page and currency formatting

### Phase 2: Master Data (done)
- [x] Product CRUD with categories, extended product fields, low-stock badge
- [x] Customer CRUD, opening balance, due tracking

### Phase 3: Core POS (done)
- [x] POS with multi-item sales and live totals
- [x] Flat and percent discount
- [x] Transaction-safe invoice save, atomic numbering
- [x] Sales list, invoice detail, print
- [x] Discount line on the invoice detail page
- [x] "Allow negative stock" setting and stock check at sale
- [x] Product search in the POS (code, name, model)
- [x] Live stock warning in the POS
- [x] Customer search in the POS (name, phone, email)
- [x] Keep the POS form filled after a server error
- [x] Sales list filters, pagination and summary

### Phase 4: Money Flow (done)
- [x] Due collection with FIFO allocation
- [x] Cashbook with automatic and manual entries
- [x] Split payments (cash, card, mobile) with `invoice_payments`
- [x] Non-cash receipts kept separate from the cash drawer
- [x] Due collection with the shared payment methods, references and per-invoice payment rows

### Phase 5: Offers, Returns and Purchases
- [x] Sales return, whole invoice
- [x] Online payment discount rules (admin page, automatic POS discount, server-side calculation)
- [x] Return history tables (`invoice_returns`, `invoice_return_items`) and refund split across the original methods
- [ ] Partial (item-wise) return: tables, service logic and the invoice detail screen are written, all of it still needs to be run and checked
- [ ] Suppliers: adding with an opening balance is checked, editing, deleting and the validation errors are with QA
- [x] Purchase entry with stock-in (checked in the database for a purchase without a supplier and one with a supplier and a part payment, the rejection cases are with QA)
- [ ] Paying suppliers (FIFO over open purchases, opening balance last): a cash payment is checked in the database, the mobile payment, the opening balance part and the rejection cases are with QA
- [x] Purchases list and detail page (list, summary and one detail page checked, the filters, pagination and print are with QA)
- [ ] Purchase return (later)

### Phase 6: Reports and Polish
- [ ] Dashboard with Chart.js
- [ ] PDF invoice (dompdf)
- [ ] Sales, profit, stock and due reports (including discount rule usage)
- [ ] Roles (cashier vs admin)
- [ ] REST API

### Phase 7: Resale Readiness
- [ ] `readme.txt` finalized, all strings translation-ready, `.pot` file
- [ ] `uninstall.php` with confirmed table drop
- [ ] No hardcoded business references
- [ ] License/activation system if sold externally
- [ ] PHP/WP version testing, bundled `vendor/` for release builds

### Later
- [ ] Sales channel (shop / online), delivery status and charges, courier COD tracking
- [ ] WooCommerce order import and stock sync (imported orders keep their final amounts as they are)

### Cleanup
- [x] Rename `ProductsModule` to `AdminModule`
- [x] Move the POS inline JavaScript to its own file
- [ ] Read `InvoiceService.php` and confirm whether a failed sale also rolls back the invoice number counter
- [ ] Post/Redirect/Get on the POS form (the inputs are already kept after an error)
- [ ] Fix the POS line total column on very narrow screens
- [ ] Show the original Received and Due figures on returned invoices (Due is now 0 after a return, Received stays as it was)
- [ ] DB upgrade routine based on `billnest_db_version`
- [ ] Rebuild `stock_qty` from `stock_log` on a schedule
- [ ] Load POS products and customers through the REST API (`/products?search=`, `/customers?search=`) instead of embedding them all in the page
- [ ] Keep the Sales List filters when coming back from an invoice detail page
- [ ] Keep the entered values in the Due Collection form after an error
- [ ] Show Partially returned in the Sales List status column
- [ ] Customers page: it still uses the older style (a customer with a due can be deleted, `current_due` is shown as a raw number)
- [ ] Purchase List: show the Due column in red when a balance is owed (the red style is used on the detail page but the list column does not look red)

## Known Gaps

- A walk-in sale with a due has no customer to collect from
- Opening-balance payments are not tracked separately from invoice dues
- No invoice edit or cancel
- Only the Sales List and the Purchase List are paginated, the other lists are not yet
- A returned invoice still shows its original Total and Received, and its Due is 0, so the invoice alone does not show what was refunded. The Payments table on the detail page shows the refund rows
- The Sales List sales total counts completed invoices only, so a partial return will not lower it until returns are netted off
- The Sales List status column shows Completed for a partly returned invoice, the Partially returned label only appears on the invoice detail page
- Card and mobile refunds are only recorded, the software cannot send money back
- `payments.invoice_id` is intentionally unused (due collection is customer level)
- Invoices whose due was paid through Due Collection before the payment rows were added have no `invoice_payments` row for that money, so returning them stops with an error
- The POS loads every active product and customer into the page. This is fine for a few thousand records, a very large catalogue needs the REST search
- Stock figures in the POS are a snapshot from page load and do not refresh when another terminal sells. The server check is always applied at save
- The Sales List filters are lost when going back from an invoice detail page
- The Sales List due figure includes walk-in dues, which cannot be collected
- There is no purchase return, and no purchase edit or cancel
- Supplier payments are made at supplier level. A payment taken from the opening balance part has no purchase to point at, so it appears only in `supplier_payments`
- The purchase page cannot be used without JavaScript, because its rows are built by `purchase.js`
- The invoice number counter may not roll back with a failed sale, see *Verification status*. The purchase number does join the surrounding transaction

## Development Process

The plugin is built incrementally, one function or page at a time. Each piece is written, tested locally and confirmed before the next one is added. This keeps the codebase lean.

Local testing: WAMP, deactivate and activate after structural changes, and check `wp-content/debug.log` for errors the Loader has caught per module. Money-related changes are checked against the database (invoice, payment, stock and cashbook rows) before and after each test, and a failed save must leave those rows unchanged.

Checks that are not done during development are listed under *Verification status* and handed to the QA team.

If the browser shows an old look after a CSS change, do a hard refresh. The POS and purchase stylesheets and scripts use their file modification time as the version, so they reload on their own after a change.

## Setup

```bash
git clone <repo-url> billnest
cd billnest
composer install
```

Activate through WP Admin, Plugins, BillNest.

## Changelog

### 1.0.0 (in development)
- Plugin bootstrap, PSR-4 architecture, admin menu
- Products, categories, customers
- POS with discount, invoices, sales list, print
- Sales return, due collection, cashbook, settings
- Split payments with per-method references and refunds on the original method
- Online payment discount rules, applied automatically in the POS and recalculated on the server, with the applied rule saved on the invoice
- Allow negative stock setting and a locked, server-side stock check at sale
- POS product search by code, name and model
- Live stock warning in the POS
- POS customer search by name, phone and email, with the customer's due shown
- The POS form is kept filled after a server error
- Sales List filters (invoice number or customer, date range, status), pagination and a summary line
- The POS script moved to its own file, `assets/admin/js/pos.js`
- `ProductsModule` renamed to `AdminModule`
- Returns are recorded in `invoice_returns` and `invoice_return_items`. The refund is split across the original payment methods, the due is reduced before anything is refunded, and the invoice row is locked during a return
- A failed cashbook insert now rolls back the whole operation
- Item-wise returns: a return quantity per line on the invoice detail page, a Returned column, a Returns history table and a Partially returned label (not yet checked in the browser)
- Due Collection uses the shared payment methods, needs a reference for card and mobile, records an `invoice_payments` row per invoice and keeps non-cash out of the cashbook
- Suppliers with an opening balance and a current payable, and a delete that is blocked while something is owed
- Purchases: a purchase entry page with product search, live totals, split payments and a unit cost that starts from the last purchase price. A purchase adds stock, sets the product's purchase price to the latest cost, writes cash-only cashbook entries and raises the supplier's payable by the unpaid part. Own purchase number counter (`P` + year and month + four digits)
- `StockService` now throws when a stock update, a product lookup or a stock log insert fails, so a failed write rolls the whole operation back (this also affects sales and returns). A sale and a whole-invoice return were checked against the database after this change
- Paying suppliers: a Pay form on the Suppliers page, settling the oldest open purchases first and then the opening balance, with a payment history, a new `supplier_payments` table, a nullable `purchase_payments.supplier_payment_id` and cash-only cashbook entries (source `supplier_payment`)
- Purchase List and purchase detail pages: filters (purchase number or supplier, date range, balance), pagination, a summary line, and a detail view with items, totals, payments (with the later supplier payments marked) and print
- The purchase and supplier payment work is checked in the database for the main success cases only, the rejection cases are with QA, see *Verification status*