<?php
namespace BillNest\DB;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CustomerRepository {

    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'billnest_customers';
    }

    public function get_all(): array {
        global $wpdb;

        return $wpdb->get_results(
            "SELECT * FROM {$this->table} WHERE status = 'active' ORDER BY name ASC"
        );
    }

    public function get_by_id( int $id ): ?object {
        global $wpdb;

        $customer = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id )
        );

        return $customer ?: null;
    }

    public function create( array $data ): int|false {
        global $wpdb;

        $inserted = $wpdb->insert(
            $this->table,
            [
                'name'             => $data['name'],
                'phone'            => $data['phone'],
                'email'            => $data['email'],
                'address'          => $data['address'],
                'opening_balance'  => $data['opening_balance'],
                'current_due'      => $data['opening_balance'],
                'status'           => 'active',
                'created_at'       => current_time( 'mysql' ),
            ],
            [ '%s', '%s', '%s', '%s', '%f', '%f', '%s', '%s' ]
        );

        return $inserted ? $wpdb->insert_id : false;
    }

    public function update( int $id, array $data ): bool {
        global $wpdb;

        $updated = $wpdb->update(
            $this->table,
            [
                'name'    => $data['name'],
                'phone'   => $data['phone'],
                'email'   => $data['email'],
                'address' => $data['address'],
            ],
            [ 'id' => $id ],
            [ '%s', '%s', '%s', '%s' ],
            [ '%d' ]
        );

        return $updated !== false;
    }

    public function delete( int $id ): bool {
        global $wpdb;

        return $wpdb->update(
            $this->table,
            [ 'status' => 'inactive' ],
            [ 'id' => $id ],
            [ '%s' ],
            [ '%d' ]
        ) !== false;
    }
}