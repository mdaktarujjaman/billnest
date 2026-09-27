<?php
namespace BillNest\Services;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NumberingService {

    public function get_next_invoice_number(): string {
        global $wpdb;

        $table = $wpdb->prefix . 'billnest_counters';

        $wpdb->query( 'START TRANSACTION' );

        $current = $wpdb->get_var(
            $wpdb->prepare( "SELECT current_value FROM $table WHERE counter_key = %s FOR UPDATE", 'invoice' )
        );

        $next = (int) $current + 1;

        $wpdb->update(
            $table,
            [ 'current_value' => $next ],
            [ 'counter_key' => 'invoice' ],
            [ '%d' ],
            [ '%s' ]
        );

        $wpdb->query( 'COMMIT' );

        return $this->format_invoice_number( $next );
    }

    private function format_invoice_number( int $number ): string {
        return date( 'ym' ) . str_pad( (string) $number, 4, '0', STR_PAD_LEFT );
    }
}