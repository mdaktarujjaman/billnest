<?php

namespace BillNest\DB;

if (! defined('ABSPATH')) {
    exit;
}

class PurchaseRepository
{

    private string $table;
    private string $items_table;
    private string $payments_table;

    public function __construct()
    {
        global $wpdb;
        $this->table          = $wpdb->prefix . 'billnest_purchases';
        $this->items_table    = $wpdb->prefix . 'billnest_purchase_items';
        $this->payments_table = $wpdb->prefix . 'billnest_purchase_payments';
    }

    public function create(array $data): int
    {
        global $wpdb;

        $inserted = $wpdb->insert(
            $this->table,
            [
                'purchase_no'   => $data['purchase_no'],
                'supplier_id'   => $data['supplier_id'] ?: null,
                'purchase_date' => $data['purchase_date'],
                'subtotal'      => $data['subtotal'],
                'total'         => $data['total'],
                'paid_amount'   => $data['paid_amount'],
                'due_amount'    => $data['due_amount'],
                'remarks'       => $data['remarks'],
                'status'        => 'completed',
                'created_by'    => $data['created_by'],
                'created_at'    => current_time('mysql'),
            ],
            ['%s', '%d', '%s', '%f', '%f', '%f', '%f', '%s', '%s', '%d', '%s']
        );

        if ($inserted === false) {
            throw new \RuntimeException('Could not save the purchase.');
        }

        return (int) $wpdb->insert_id;
    }

    public function create_item(int $purchase_id, array $line): void
    {
        global $wpdb;

        $inserted = $wpdb->insert(
            $this->items_table,
            [
                'purchase_id' => $purchase_id,
                'product_id'  => $line['product_id'],
                'qty'         => $line['qty'],
                'unit_cost'   => $line['unit_cost'],
                'total'       => $line['total'],
            ],
            ['%d', '%d', '%f', '%f', '%f']
        );

        if ($inserted === false) {
            throw new \RuntimeException('Could not save the purchased item.');
        }
    }

    public function create_payment(int $purchase_id, string $method, float $amount, ?string $reference): void
    {
        global $wpdb;

        $inserted = $wpdb->insert(
            $this->payments_table,
            [
                'purchase_id' => $purchase_id,
                'method'      => $method,
                'amount'      => $amount,
                'reference'   => $reference,
                'created_at'  => current_time('mysql'),
            ],
            ['%d', '%s', '%f', '%s', '%s']
        );

        if ($inserted === false) {
            throw new \RuntimeException('Could not save the purchase payment.');
        }
    }
}