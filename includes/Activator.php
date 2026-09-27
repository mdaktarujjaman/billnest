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

        self::create_categories_table($charset_collate);
        self::create_products_table($charset_collate);
        self::create_customers_table($charset_collate);
        self::create_counters_table($charset_collate);
        self::create_invoices_table($charset_collate);
        self::create_invoice_items_table($charset_collate);
        self::create_stock_log_table($charset_collate);
    }

    private static function create_customers_table($charset_collate)
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'billnest_customers';

        $sql = "CREATE TABLE $table_name (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(255) NOT NULL,
        phone VARCHAR(30) DEFAULT NULL,
        email VARCHAR(255) DEFAULT NULL,
        address TEXT DEFAULT NULL,
        opening_balance DECIMAL(12,2) NOT NULL DEFAULT 0,
        current_due DECIMAL(12,2) NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY phone (phone),
        KEY status (status)
    ) $charset_collate ENGINE=InnoDB;";

        dbDelta($sql);
    }

    // Create the categories table with the specified schema
    private static function create_categories_table($charset_collate)
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'billnest_categories';

        $sql = "CREATE TABLE $table_name (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(255) NOT NULL,
        parent_id BIGINT UNSIGNED DEFAULT NULL,
        PRIMARY KEY  (id),
        KEY parent_id (parent_id)
    ) $charset_collate ENGINE=InnoDB;";

        dbDelta($sql);
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

    private static function create_counters_table($charset_collate)
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'billnest_counters';

        $sql = "CREATE TABLE $table_name (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        counter_key VARCHAR(50) NOT NULL,
        current_value BIGINT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY  (id),
        UNIQUE KEY counter_key (counter_key)
    ) $charset_collate ENGINE=InnoDB;";

        dbDelta($sql);

        if (! $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_name WHERE counter_key = %s", 'invoice'))) {
            $wpdb->insert($table_name, ['counter_key' => 'invoice', 'current_value' => 0]);
        }
    }

    private static function create_invoices_table($charset_collate)
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'billnest_invoices';

        $sql = "CREATE TABLE $table_name (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        invoice_no VARCHAR(50) NOT NULL,
        customer_id BIGINT UNSIGNED DEFAULT NULL,
        invoice_date DATE NOT NULL,
        subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
        discount_type VARCHAR(10) DEFAULT NULL,
        discount_value DECIMAL(12,2) NOT NULL DEFAULT 0,
        discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        total DECIMAL(12,2) NOT NULL DEFAULT 0,
        received_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        due_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        remarks TEXT DEFAULT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'completed',
        created_by BIGINT UNSIGNED DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY invoice_no (invoice_no),
        KEY customer_id (customer_id),
        KEY status (status)
    ) $charset_collate ENGINE=InnoDB;";

        dbDelta($sql);
    }

    private static function create_invoice_items_table($charset_collate)
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'billnest_invoice_items';

        $sql = "CREATE TABLE $table_name (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        invoice_id BIGINT UNSIGNED NOT NULL,
        product_id BIGINT UNSIGNED NOT NULL,
        qty DECIMAL(12,2) NOT NULL,
        unit_price DECIMAL(12,2) NOT NULL,
        total DECIMAL(12,2) NOT NULL,
        PRIMARY KEY  (id),
        KEY invoice_id (invoice_id),
        KEY product_id (product_id)
    ) $charset_collate ENGINE=InnoDB;";

        dbDelta($sql);
    }

    private static function create_stock_log_table($charset_collate)
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'billnest_stock_log';

        $sql = "CREATE TABLE $table_name (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        product_id BIGINT UNSIGNED NOT NULL,
        change_qty DECIMAL(12,2) NOT NULL,
        type VARCHAR(20) NOT NULL,
        ref_id BIGINT UNSIGNED DEFAULT NULL,
        ref_type VARCHAR(20) DEFAULT NULL,
        balance_after DECIMAL(12,2) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY product_id (product_id),
        KEY ref (ref_id, ref_type)
    ) $charset_collate ENGINE=InnoDB;";

        dbDelta($sql);
    }
}
