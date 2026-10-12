<?php

namespace BillNest\DB;

if (! defined('ABSPATH')) {
    exit;
}

class InvoicePaymentRepository
{

    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'billnest_invoice_payments';
    }

    public function create(int $invoice_id, string $method, float $amount, ?string $reference = null): int
    {
        global $wpdb;

        $inserted = $wpdb->insert(
            $this->table,
            [
                'invoice_id' => $invoice_id,
                'method'     => $method,
                'amount'     => $amount,
                'reference'  => $reference,
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%s', '%f', '%s', '%s']
        );

        if ($inserted === false) {
            throw new \RuntimeException('Could not save the invoice payment.');
        }

        return (int) $wpdb->insert_id;
    }

    public function get_by_invoice(int $invoice_id): array
    {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE invoice_id = %d ORDER BY id ASC",
                $invoice_id
            )
        );
    }
}