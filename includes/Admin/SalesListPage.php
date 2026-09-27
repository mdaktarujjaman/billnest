<?php
namespace BillNest\Admin;

use BillNest\DB\InvoiceRepository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SalesListPage extends AdminPage {

    public function get_slug(): string {
        return 'billnest-sales';
    }

    public function get_title(): string {
        return __( 'Sales List', 'billnest' );
    }

    public function render(): void {
        if ( ! current_user_can( $this->get_capability() ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'billnest' ) );
        }

        $repository = new InvoiceRepository();
        $invoices   = $repository->get_all();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html( $this->get_title() ) . '</h1>';

        if ( empty( $invoices ) ) {
            echo '<p>' . esc_html__( 'No invoices yet.', 'billnest' ) . '</p>';
            echo '</div>';
            return;
        }

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__( 'Invoice No', 'billnest' ) . '</th>';
        echo '<th>' . esc_html__( 'Date', 'billnest' ) . '</th>';
        echo '<th>' . esc_html__( 'Customer', 'billnest' ) . '</th>';
        echo '<th>' . esc_html__( 'Total', 'billnest' ) . '</th>';
        echo '<th>' . esc_html__( 'Received', 'billnest' ) . '</th>';
        echo '<th>' . esc_html__( 'Due', 'billnest' ) . '</th>';
        echo '<th>' . esc_html__( 'Status', 'billnest' ) . '</th>';
        echo '<th>' . esc_html__( 'Action', 'billnest' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $invoices as $invoice ) {
            $view_url = admin_url( 'admin.php?page=' . $this->get_slug() . '&action=view&id=' . $invoice->id );

            echo '<tr>';
            echo '<td>' . esc_html( $invoice->invoice_no ) . '</td>';
            echo '<td>' . esc_html( $invoice->invoice_date ) . '</td>';
            echo '<td>' . esc_html( $invoice->customer_name ?: __( 'Walk-in', 'billnest' ) ) . '</td>';
            echo '<td>' . esc_html( $invoice->total ) . '</td>';
            echo '<td>' . esc_html( $invoice->received_amount ) . '</td>';
            echo '<td>' . esc_html( $invoice->due_amount ) . '</td>';
            echo '<td>' . esc_html( ucfirst( $invoice->status ) ) . '</td>';
            echo '<td><a href="' . esc_url( $view_url ) . '">' . esc_html__( 'View', 'billnest' ) . '</a></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }
}