<?php
namespace BillNest\Admin;

use BillNest\DB\CustomerRepository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CustomersPage extends AdminPage {

    public function get_slug(): string {
        return 'billnest-customers';
    }

    public function get_title(): string {
        return __( 'Customers', 'billnest' );
    }

    public function render(): void {
        if ( ! current_user_can( $this->get_capability() ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'billnest' ) );
        }

        $this->handle_delete();
        $this->handle_form_submission();

        $repository = new CustomerRepository();
        $customers  = $repository->get_all();

        $editing_customer = null;
        if ( ( $_GET['action'] ?? '' ) === 'edit' && ! empty( $_GET['id'] ) ) {
            $editing_customer = $repository->get_by_id( absint( $_GET['id'] ) );
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html( $this->get_title() ) . '</h1>';

        $this->render_form( $editing_customer );

        if ( empty( $customers ) ) {
            echo '<p>' . esc_html__( 'No customers yet.', 'billnest' ) . '</p>';
            echo '</div>';
            return;
        }

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__( 'Name', 'billnest' ) . '</th>';
        echo '<th>' . esc_html__( 'Phone', 'billnest' ) . '</th>';
        echo '<th>' . esc_html__( 'Email', 'billnest' ) . '</th>';
        echo '<th>' . esc_html__( 'Current Due', 'billnest' ) . '</th>';
        echo '<th>' . esc_html__( 'Action', 'billnest' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $customers as $customer ) {
            $edit_url = admin_url( 'admin.php?page=' . $this->get_slug() . '&action=edit&id=' . $customer->id );

            $delete_url = wp_nonce_url(
                admin_url( 'admin.php?page=' . $this->get_slug() . '&action=delete&id=' . $customer->id ),
                'billnest_delete_customer_' . $customer->id
            );

            echo '<tr>';
            echo '<td>' . esc_html( $customer->name ) . '</td>';
            echo '<td>' . esc_html( $customer->phone ) . '</td>';
            echo '<td>' . esc_html( $customer->email ) . '</td>';
            echo '<td>' . esc_html( $customer->current_due ) . '</td>';
            echo '<td>';
            echo '<a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit', 'billnest' ) . '</a> | ';
            echo '<a href="' . esc_url( $delete_url ) . '" onclick="return confirm(\'' . esc_js( __( 'Delete this customer?', 'billnest' ) ) . '\');">' . esc_html__( 'Delete', 'billnest' ) . '</a>';
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

        check_admin_referer( 'billnest_delete_customer_' . $id );

        ( new CustomerRepository() )->delete( $id );

        wp_safe_redirect( admin_url( 'admin.php?page=' . $this->get_slug() ) );
        exit;
    }

    private function handle_form_submission(): void {
        if ( ! isset( $_POST['billnest_customer_nonce'] ) ) {
            return;
        }

        if ( ! wp_verify_nonce( $_POST['billnest_customer_nonce'], 'billnest_save_customer' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'billnest' ) );
        }

        $repository = new CustomerRepository();

        $data = [
            'name'            => sanitize_text_field( $_POST['name'] ?? '' ),
            'phone'           => sanitize_text_field( $_POST['phone'] ?? '' ),
            'email'           => sanitize_email( $_POST['email'] ?? '' ),
            'address'         => sanitize_textarea_field( $_POST['address'] ?? '' ),
            'opening_balance' => floatval( $_POST['opening_balance'] ?? 0 ),
        ];

        $customer_id = absint( $_POST['customer_id'] ?? 0 );

        if ( $customer_id > 0 ) {
            $repository->update( $customer_id, $data );
        } else {
            $repository->create( $data );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=' . $this->get_slug() ) );
        exit;
    }

    private function render_form( ?object $editing_customer ): void {
        $is_editing = $editing_customer !== null;

        echo '<h2>' . ( $is_editing ? esc_html__( 'Edit Customer', 'billnest' ) : esc_html__( 'Add Customer', 'billnest' ) ) . '</h2>';

        echo '<form method="post">';
        wp_nonce_field( 'billnest_save_customer', 'billnest_customer_nonce' );

        if ( $is_editing ) {
            echo '<input type="hidden" name="customer_id" value="' . esc_attr( $editing_customer->id ) . '">';
        }

        echo '<table class="form-table">';
        echo '<tr><th><label for="name">' . esc_html__( 'Name', 'billnest' ) . '</label></th>';
        echo '<td><input type="text" name="name" id="name" value="' . esc_attr( $editing_customer->name ?? '' ) . '" required></td></tr>';

        echo '<tr><th><label for="phone">' . esc_html__( 'Phone', 'billnest' ) . '</label></th>';
        echo '<td><input type="text" name="phone" id="phone" value="' . esc_attr( $editing_customer->phone ?? '' ) . '"></td></tr>';

        echo '<tr><th><label for="email">' . esc_html__( 'Email', 'billnest' ) . '</label></th>';
        echo '<td><input type="email" name="email" id="email" value="' . esc_attr( $editing_customer->email ?? '' ) . '"></td></tr>';

        echo '<tr><th><label for="address">' . esc_html__( 'Address', 'billnest' ) . '</label></th>';
        echo '<td><textarea name="address" id="address" rows="3" cols="40">' . esc_textarea( $editing_customer->address ?? '' ) . '</textarea></td></tr>';

        if ( ! $is_editing ) {
            echo '<tr><th><label for="opening_balance">' . esc_html__( 'Opening Balance', 'billnest' ) . '</label></th>';
            echo '<td><input type="number" step="0.01" name="opening_balance" id="opening_balance" value="0"></td></tr>';
        }

        echo '</table>';

        submit_button( $is_editing ? __( 'Update Customer', 'billnest' ) : __( 'Add Customer', 'billnest' ) );
        echo '</form>';
    }
}