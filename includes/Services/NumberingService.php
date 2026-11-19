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

    /**
     * Must be called inside the caller's transaction. It takes the counter row lock
     * and leaves the commit or rollback to the caller, so a failed purchase gives its number back.
     */
    public function get_next_purchase_number(): string {
        global $wpdb;

        $table = $wpdb->prefix . 'billnest_counters';

        $created = $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO $table (counter_key, current_value) VALUES (%s, 0) ON DUPLICATE KEY UPDATE counter_key = counter_key",
                'purchase'
            )
        );

        if ( $created === false ) {
            throw new \RuntimeException( 'Could not prepare the purchase counter.' );
        }

        $current = $wpdb->get_var(
            $wpdb->prepare( "SELECT current_value FROM $table WHERE counter_key = %s FOR UPDATE", 'purchase' )
        );

        if ( $current === null ) {
            throw new \RuntimeException( 'Could not read the purchase counter.' );
        }

        $next = (int) $current + 1;

        $updated = $wpdb->update(
            $table,
            [ 'current_value' => $next ],
            [ 'counter_key' => 'purchase' ],
            [ '%d' ],
            [ '%s' ]
        );

        if ( $updated === false ) {
            throw new \RuntimeException( 'Could not update the purchase counter.' );
        }

        return 'P' . date( 'ym' ) . str_pad( (string) $next, 4, '0', STR_PAD_LEFT );
    }

    private function format_invoice_number( int $number ): string {
        return date( 'ym' ) . str_pad( (string) $number, 4, '0', STR_PAD_LEFT );
    }
}