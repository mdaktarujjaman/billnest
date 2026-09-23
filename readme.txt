=== BillNest ===
Contributors: itzone360
Tags: pos, invoicing, inventory, billing, accounting
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Universal POS, invoicing, inventory and basic accounting for WordPress. Standalone, self-contained, no external ERP dependency.

== Description ==

BillNest is a standalone Point-of-Sale, invoicing, and inventory management plugin for WordPress. Built for small-to-mid retail and service businesses.

= Features =

* Product management with categories and stock tracking
* Customer management with due/balance tracking
* POS billing screen with auto-calculating invoices
* Sales list, returns, and due collection
* Cashbook and payment tracking
* REST API for external integrations

= Requirements =

* PHP 8.0 or higher
* WordPress 6.0 or higher
* MySQL with InnoDB support (required for transaction-safe invoice creation)

== Installation ==

1. Upload the `billnest` folder to `/wp-content/plugins/`
2. Run `composer install` inside the plugin folder if `vendor/` is not already present
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Navigate to BillNest in the admin sidebar to get started

== Changelog ==

= 1.0.0 =
* Initial development build — plugin bootstrap, activation/deactivation hooks, products table, admin menu foundation