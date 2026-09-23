<?php

namespace BillNest;

if (! defined('ABSPATH')) {
    exit;
}


// This class handles the activation of the plugin, including creating necessary database tables and setting up initial options.
class Activator
{

    public static function activate()
    {
        self::create_tables();
        self::add_capabilities();
        update_option('billnest_db_version', BILLNEST_DB_VERSION);
        flush_rewrite_rules();
    }

    private static function add_capabilities()
    {
        $admin_role = get_role('administrator');
        if ($admin_role) {
            $admin_role->add_cap('manage_billnest');
        }
    }
    private static function create_tables()
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();

        self::create_products_table($charset_collate);
    }

    // Create the products table with the specified schema
    private static function create_products_table($charset_collate)
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'billnest_products';

        $sql = "CREATE TABLE $table_name (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(50) NOT NULL,
            name VARCHAR(255) NOT NULL,
            category_id BIGINT UNSIGNED DEFAULT NULL,
            model VARCHAR(100) DEFAULT NULL,
            unit VARCHAR(20) DEFAULT NULL,
            purchase_price DECIMAL(12,2) NOT NULL DEFAULT 0,
            sale_price DECIMAL(12,2) NOT NULL DEFAULT 0,
            stock_qty DECIMAL(12,2) NOT NULL DEFAULT 0,
            reorder_level DECIMAL(12,2) NOT NULL DEFAULT 0,
            image_url VARCHAR(500) DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY code (code),
            KEY category_id (category_id),
            KEY status (status)
        ) $charset_collate ENGINE=InnoDB;";

        dbDelta($sql);
    }
}
