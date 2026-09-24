<?php
namespace BillNest\Admin;

use BillNest\DB\ProductRepository;
use BillNest\DB\CategoryRepository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ProductsPage extends AdminPage {

    public function get_slug(): string {
        return 'billnest-products';
    }

    public function get_title(): string {
        return __( 'Products', 'billnest' );
    }

    public function render(): void {
        if ( ! current_user_can( $this->get_capability() ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'billnest' ) );
        }

        $this->handle_delete();
        $this->handle_form_submission();

        $repository = new ProductRepository();
        $products   = $repository->get_all();

        $editing_product = null;
        if ( ( $_GET['action'] ?? '' ) === 'edit' && ! empty( $_GET['id'] ) ) {
            $editing_product = $repository->get_by_id( absint( $_GET['id'] ) );
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html( $this->get_title() ) . '</h1>';

        if ( ( $_GET['billnest_error'] ?? '' ) === 'duplicate_code' ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'That product code is already in use. Please choose a different code.', 'billnest' ) . '</p></div>';
        }

        $this->render_form( $editing_product );

        if ( empty( $products ) ) {
            echo '<p>' . esc_html__( 'No products yet.', 'billnest' ) . '</p>';
            echo '</div>';
            return;
        }

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__( 'Code', 'billnest' ) . '</th>';
        echo '<th>' . esc_html__( 'Name', 'billnest' ) . '</th>';
        echo '<th>' . esc_html__( 'Category', 'billnest' ) . '</th>';
        echo '<th>' . esc_html__( 'Sale Price', 'billnest' ) . '</th>';
        echo '<th>' . esc_html__( 'Stock', 'billnest' ) . '</th>';
        echo '<th>' . esc_html__( 'Action', 'billnest' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $products as $product ) {
            $edit_url = admin_url( 'admin.php?page=' . $this->get_slug() . '&action=edit&id=' . $product->id );

            $delete_url = wp_nonce_url(
                admin_url( 'admin.php?page=' . $this->get_slug() . '&action=delete&id=' . $product->id ),
                'billnest_delete_product_' . $product->id
            );

            echo '<tr>';
            echo '<td>' . esc_html( $product->code ) . '</td>';
            echo '<td>' . esc_html( $product->name ) . '</td>';
            echo '<td>' . esc_html( $product->category_name ?: '—' ) . '</td>';
            echo '<td>' . esc_html( $product->sale_price ) . '</td>';
            echo '<td>' . esc_html( $product->stock_qty ) . '</td>';
            echo '<td>';
            echo '<a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit', 'billnest' ) . '</a> | ';
            echo '<a href="' . esc_url( $delete_url ) . '" onclick="return confirm(\'' . esc_js( __( 'Delete this product?', 'billnest' ) ) . '\');">' . esc_html__( 'Delete', 'billnest' ) . '</a>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    private function handle_delete(): void {
        if ( ( $_GET['action'] ?? '' ) !== 'delete' || empty( $_GET['id'] ) ) {
            return;
        }

        $id = absint( $_GET['id'] );

        check_admin_referer( 'billnest_delete_product_' . $id );

        ( new ProductRepository() )->delete( $id );

        wp_safe_redirect( admin_url( 'admin.php?page=' . $this->get_slug() ) );
        exit;
    }

    private function handle_form_submission(): void {
        if ( ! isset( $_POST['billnest_product_nonce'] ) ) {
            return;
        }

        if ( ! wp_verify_nonce( $_POST['billnest_product_nonce'], 'billnest_save_product' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'billnest' ) );
        }

        $repository = new ProductRepository();

        $data = [
            'code'        => sanitize_text_field( $_POST['code'] ?? '' ),
            'name'        => sanitize_text_field( $_POST['name'] ?? '' ),
            'category_id' => absint( $_POST['category_id'] ?? 0 ),
            'sale_price'  => floatval( $_POST['sale_price'] ?? 0 ),
            'stock_qty'   => floatval( $_POST['stock_qty'] ?? 0 ),
        ];

        $product_id = absint( $_POST['product_id'] ?? 0 );

        if ( $product_id > 0 ) {
            $success = $repository->update( $product_id, $data );
        } else {
            $success = $repository->create( $data ) !== false;
        }

        $redirect_url = admin_url( 'admin.php?page=' . $this->get_slug() );

        if ( ! $success ) {
            $redirect_url = add_query_arg( 'billnest_error', 'duplicate_code', $redirect_url );
        }

        wp_safe_redirect( $redirect_url );
        exit;
    }

    private function render_form( ?object $editing_product ): void {
        $is_editing = $editing_product !== null;
        $categories = ( new CategoryRepository() )->get_all();

        echo '<h2>' . ( $is_editing ? esc_html__( 'Edit Product', 'billnest' ) : esc_html__( 'Add Product', 'billnest' ) ) . '</h2>';

        echo '<form method="post">';
        wp_nonce_field( 'billnest_save_product', 'billnest_product_nonce' );

        if ( $is_editing ) {
            echo '<input type="hidden" name="product_id" value="' . esc_attr( $editing_product->id ) . '">';
        }

        echo '<table class="form-table">';
        echo '<tr><th><label for="code">' . esc_html__( 'Code', 'billnest' ) . '</label></th>';
        echo '<td><input type="text" name="code" id="code" value="' . esc_attr( $editing_product->code ?? '' ) . '" required></td></tr>';

        echo '<tr><th><label for="name">' . esc_html__( 'Name', 'billnest' ) . '</label></th>';
        echo '<td><input type="text" name="name" id="name" value="' . esc_attr( $editing_product->name ?? '' ) . '" required></td></tr>';

        echo '<tr><th><label for="category_id">' . esc_html__( 'Category', 'billnest' ) . '</label></th>';
        echo '<td><select name="category_id" id="category_id">';
        echo '<option value="0">' . esc_html__( '-- None --', 'billnest' ) . '</option>';
        foreach ( $categories as $category ) {
            $selected = isset( $editing_product->category_id ) && (int) $editing_product->category_id === (int) $category->id;
            echo '<option value="' . esc_attr( $category->id ) . '"' . selected( $selected, true, false ) . '>' . esc_html( $category->name ) . '</option>';
        }
        echo '</select></td></tr>';

        echo '<tr><th><label for="sale_price">' . esc_html__( 'Sale Price', 'billnest' ) . '</label></th>';
        echo '<td><input type="number" step="0.01" name="sale_price" id="sale_price" value="' . esc_attr( $editing_product->sale_price ?? '' ) . '" required></td></tr>';

        echo '<tr><th><label for="stock_qty">' . esc_html__( 'Stock Qty', 'billnest' ) . '</label></th>';
        echo '<td><input type="number" step="0.01" name="stock_qty" id="stock_qty" value="' . esc_attr( $editing_product->stock_qty ?? '' ) . '" required></td></tr>';
        echo '</table>';

        submit_button( $is_editing ? __( 'Update Product', 'billnest' ) : __( 'Add Product', 'billnest' ) );
        echo '</form>';
    }
}