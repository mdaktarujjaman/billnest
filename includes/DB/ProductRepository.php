<?php
namespace BillNest\DB;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ProductRepository {

    private string $table;
    private string $categories_table;

    public function __construct() {
        global $wpdb;
        $this->table            = $wpdb->prefix . 'billnest_products';
        $this->categories_table = $wpdb->prefix . 'billnest_categories';
    }

    public function get_all(): array {
        global $wpdb;

        return $wpdb->get_results(
            "SELECT p.*, c.name AS category_name
             FROM {$this->table} p
             LEFT JOIN {$this->categories_table} c ON p.category_id = c.id
             WHERE p.status = 'active'
             ORDER BY p.name ASC"
        );
    }

    public function get_by_id( int $id ): ?object {
        global $wpdb;

        $product = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id )
        );

        return $product ?: null;
    }

    public function create( array $data ): int|false {
        global $wpdb;

        $inserted = $wpdb->insert(
            $this->table,
            [
                'code'        => $data['code'],
                'name'        => $data['name'],
                'category_id' => $data['category_id'] ?: null,
                'sale_price'  => $data['sale_price'],
                'stock_qty'   => $data['stock_qty'],
                'status'      => 'active',
                'created_at'  => current_time( 'mysql' ),
                'updated_at'  => current_time( 'mysql' ),
            ],
            [ '%s', '%s', '%d', '%f', '%f', '%s', '%s', '%s' ]
        );

        return $inserted ? $wpdb->insert_id : false;
    }

    public function update( int $id, array $data ): bool {
        global $wpdb;

        $updated = $wpdb->update(
            $this->table,
            [
                'code'        => $data['code'],
                'name'        => $data['name'],
                'category_id' => $data['category_id'] ?: null,
                'sale_price'  => $data['sale_price'],
                'stock_qty'   => $data['stock_qty'],
                'updated_at'  => current_time( 'mysql' ),
            ],
            [ 'id' => $id ],
            [ '%s', '%s', '%d', '%f', '%f', '%s' ],
            [ '%d' ]
        );

        return $updated !== false;
    }

    public function delete( int $id ): bool {
        global $wpdb;

        $product = $this->get_by_id( $id );
        if ( ! $product ) {
            return false;
        }

        $updated = $wpdb->update(
            $this->table,
            [
                'code'       => $product->code . '-deleted-' . $id,
                'status'     => 'inactive',
                'updated_at' => current_time( 'mysql' ),
            ],
            [ 'id' => $id ],
            [ '%s', '%s', '%s' ],
            [ '%d' ]
        );

        return $updated !== false;
    }
}