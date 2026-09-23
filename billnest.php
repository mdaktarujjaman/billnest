<?php
/**
 * Plugin Name:       BillNest
 * Plugin URI:         https://itzone360.net/billnest
 * Description:        Universal POS, invoicing, inventory & basic accounting for WordPress. Standalone, self-contained, no external ERP dependency.
 * Version:            1.0.0
 * Requires at least:  6.0
 * Requires PHP:       8.0
 * Author:             ITzone360 / Md Aktarujjaman
 * Author URI:         https://itzone360.net
 * License:            GPL v2 or later
 * License URI:        https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:        billnest
 * Domain Path:        /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'BILLNEST_VERSION', '1.0.0' );
define( 'BILLNEST_PLUGIN_FILE', __FILE__ );
define( 'BILLNEST_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BILLNEST_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BILLNEST_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'BILLNEST_DB_VERSION', '1.0.0' );

require BILLNEST_PLUGIN_DIR . 'vendor/autoload.php';

register_activation_hook( __FILE__, 'billnest_activate' );
register_deactivation_hook( __FILE__, 'billnest_deactivate' );

function billnest_activate() {
    \BillNest\Activator::activate();
}

function billnest_deactivate() {
    \BillNest\Deactivator::deactivate();
}

add_action( 'plugins_loaded', 'billnest_init' );

function billnest_init() {
    $loader = new \BillNest\Loader();
    $loader->register( new \BillNest\Modules\ProductsModule() );
    $loader->run();
}