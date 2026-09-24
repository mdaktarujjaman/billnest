<?php
namespace BillNest\DB;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CategoryRepository {

    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'billnest_categories';
    }

    public function get_all(): array {
        global $wpdb;

        return $wpdb->get_results( "SELECT * FROM {$this->table} ORDER BY name ASC" );
    }

    public function create( string $name ): int|false {
        global $wpdb;

        $inserted = $wpdb->insert(
            $this->table,
            [ 'name' => $name ],
            [ '%s' ]
        );

        return $inserted ? $wpdb->insert_id : false;
    }

    public function delete( int $id ): bool {
        global $wpdb;

        return $wpdb->delete( $this->table, [ 'id' => $id ], [ '%d' ] ) !== false;
    }
}