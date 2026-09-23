<?php
namespace BillNest\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Adminpage class is an abstract class that defines the structure for admin pages in the BillNest Plugin. 
class ProductsPage extends AdminPage {

    public function get_slug(): string {
        return 'billnest-products';
    }

    public function get_title(): string {
        return __( 'Products', 'billnest' );
    }

    public function render(): void {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html( $this->get_title() ) . '</h1>';
        echo '<p>' . esc_html__( 'Product list coming next.', 'billnest' ) . '</p>';
        echo '</div>';
    }
}