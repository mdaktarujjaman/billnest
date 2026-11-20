<?php

namespace BillNest\DB;

if (! defined('ABSPATH')) {
    exit;
}

class SupplierPaymentRepository
{

    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'billnest_supplier_payments';
    }

    public function create(array $data): int
    {
        global $wpdb;

        $inserted = $wpdb->insert(
            $this->table,
            [
                'supplier_id'  => $data['supplier_id'],
                'amount'       => $data['amount'],
                'method'       => $data['method'],
                'reference'    => $data['reference'] !== '' ? $data['reference'] : null,
                'remarks'      => $data['remarks'],
                'payment_date' => $data['payment_date'],
                'created_by'   => $data['created_by'],
                'created_at'   => current_time('mysql'),
            ],
            ['%d', '%f', '%s', '%s', '%s', '%s', '%d', '%s']
        );

        if ($inserted === false) {
            throw new \RuntimeException('Could not save the supplier payment.');
        }

        return (int) $wpdb->insert_id;
    }

    public function get_by_supplier(int $supplier_id): array
    {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE supplier_id = %d ORDER BY id DESC",
                $supplier_id
            )
        );
    }
}