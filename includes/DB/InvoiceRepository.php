<?php

namespace BillNest\DB;

if (! defined('ABSPATH')) {
    exit;
}

class InvoiceRepository
{

    private string $table;
    private string $items_table;

    public function __construct()
    {
        global $wpdb;
        $this->table       = $wpdb->prefix . 'billnest_invoices';
        $this->items_table = $wpdb->prefix . 'billnest_invoice_items';
    }

    public function get_all(): array
    {
        global $wpdb;

        return $wpdb->get_results(
            "SELECT i.*, c.name AS customer_name
             FROM {$this->table} i
             LEFT JOIN {$wpdb->prefix}billnest_customers c ON i.customer_id = c.id
             WHERE i.status != 'cancelled'
             ORDER BY i.id DESC"
        );
    }

    public function get_by_id(int $id): ?object
    {
        global $wpdb;

        $invoice = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $id)
        );

        return $invoice ?: null;
    }

    public function get_items(int $invoice_id): array
    {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ii.*, p.name AS product_name
                 FROM {$this->items_table} ii
                 LEFT JOIN {$wpdb->prefix}billnest_products p ON ii.product_id = p.id
                 WHERE ii.invoice_id = %d",
                $invoice_id
            )
        );
    }

    public function create_invoice(array $data): int
    {
        global $wpdb;

        $wpdb->insert(
            $this->table,
            [
                'invoice_no'      => $data['invoice_no'],
                'customer_id'     => $data['customer_id'] ?: null,
                'invoice_date'    => $data['invoice_date'],
                'subtotal'        => $data['subtotal'],
                'discount_type'   => $data['discount_type'],
                'discount_value'  => $data['discount_value'],
                'discount_amount' => $data['discount_amount'],
                'total'           => $data['total'],
                'received_amount' => $data['received_amount'],
                'due_amount'      => $data['due_amount'],
                'remarks'         => $data['remarks'],
                'status'          => 'completed',
                'created_by'      => $data['created_by'],
                'created_at'      => current_time('mysql'),
            ],
            ['%s', '%d', '%s', '%f', '%s', '%f', '%f', '%f', '%f', '%f', '%s', '%s', '%d', '%s']
        );

        return $wpdb->insert_id;
    }

    public function create_item(int $invoice_id, array $item): void
    {
        global $wpdb;

        $wpdb->insert(
            $this->items_table,
            [
                'invoice_id' => $invoice_id,
                'product_id' => $item['product_id'],
                'qty'        => $item['qty'],
                'unit_price' => $item['unit_price'],
                'total'      => $item['total'],
            ],
            ['%d', '%d', '%f', '%f', '%f']
        );
    }

    public function update_status(int $id, string $status): void
    {
        global $wpdb;

        $wpdb->update(
            $this->table,
            ['status' => $status],
            ['id' => $id],
            ['%s'],
            ['%d']
        );
    }
}