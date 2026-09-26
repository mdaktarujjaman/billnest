<?php
namespace BillNest\Admin;

use BillNest\DB\CategoryRepository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CategoriesPage extends AdminPage {

    public function get_slug(): string {
        return 'billnest-categories';
    }

    public function get_title(): string {
        return __( 'Categories', 'billnest' );
    }

    public function render(): void {
        if ( ! current_user_can( $this->get_capability() ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'billnest' ) );
        }

        $this->handle_delete();
        $this->handle_form_submission();

        $repository = new CategoryRepository();
        $categories = $repository->get_all();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html( $this->get_title() ) . '</h1>';

        $this->render_form();

        if ( empty( $categories ) ) {
            echo '<p>' . esc_html__( 'No categories yet.', 'billnest' ) . '</p>';
            echo '</div>';
            return;
        }

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__( 'Name', 'billnest' ) . '</th>';
        echo '<th>' . esc_html__( 'Action', 'billnest' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $categories as $category ) {
            $delete_url = wp_nonce_url(
                admin_url( 'admin.php?page=' . $this->get_slug() . '&action=delete&id=' . $category->id ),
                'billnest_delete_category_' . $category->id
            );

            echo '<tr>';
            echo '<td>' . esc_html( $category->name ) . '</td>';
            echo '<td><a href="' . esc_url( $delete_url ) . '" onclick="return confirm(\'' . esc_js( __( 'Delete this category?', 'billnest' ) ) . '\');">' . esc_html__( 'Delete', 'billnest' ) . '</a></td>';
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

        check_admin_referer( 'billnest_delete_category_' . $id );

        ( new CategoryRepository() )->delete( $id );

        wp_safe_redirect( admin_url( 'admin.php?page=' . $this->get_slug() ) );
        exit;
    }

    private function handle_form_submission(): void {
        if ( ! isset( $_POST['billnest_category_nonce'] ) ) {
            return;
        }

        if ( ! wp_verify_nonce( $_POST['billnest_category_nonce'], 'billnest_save_category' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'billnest' ) );
        }

        $name = sanitize_text_field( $_POST['name'] ?? '' );

        if ( $name !== '' ) {
            ( new CategoryRepository() )->create( $name );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=' . $this->get_slug() ) );
        exit;
    }

    private function render_form(): void {
        echo '<h2>' . esc_html__( 'Add Category', 'billnest' ) . '</h2>';
        echo '<form method="post">';
        wp_nonce_field( 'billnest_save_category', 'billnest_category_nonce' );

        echo '<table class="form-table">';
        echo '<tr><th><label for="name">' . esc_html__( 'Name', 'billnest' ) . '</label></th>';
        echo '<td><input type="text" name="name" id="name" required></td></tr>';
        echo '</table>';

        submit_button( __( 'Add Category', 'billnest' ) );
        echo '</form>';
    }
}