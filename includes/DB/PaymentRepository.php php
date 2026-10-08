<?php

namespace BillNest\DB;

if (! defined('ABSPATH')) {
    exit;
}

class PaymentRepository
{

    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'billnest_payments';
    }

    public function create(array $data): int
    {
        global $wpdb;

        $wpdb->insert(
            $this->table,
            [
                'customer_id'  => $data['customer_id'],
                'amount'       => $data['amount'],
                'payment_date' => $data['payment_date'],
                'method'       => $data['method'],
                'remarks'      => $data['remarks'],
                'created_by'   => $data['created_by'],
                'created_at'   => current_time('mysql'),
            ],
            ['%d', '%f', '%s', '%s', '%s', '%d', '%s']
        );

        return $wpdb->insert_id;
    }

    public function get_recent(int $limit = 15): array
    {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.*, c.name AS customer_name
                 FROM {$this->table} p
                 LEFT JOIN {$wpdb->prefix}billnest_customers c ON p.customer_id = c.id
                 ORDER BY p.id DESC
                 LIMIT %d",
                $limit
            )
        );
    }

    public function get_by_customer(int $customer_id, int $limit = 20): array
    {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE customer_id = %d ORDER BY id DESC LIMIT %d",
                $customer_id,
                $limit
            )
        );
    }
}