<?php

namespace BillNest\DB;

if (! defined('ABSPATH')) {
    exit;
}

class InvoiceReturnRepository
{

    private string $table;
    private string $items_table;

    public function __construct()
    {
        global $wpdb;
        $this->table       = $wpdb->prefix . 'billnest_invoice_returns';
        $this->items_table = $wpdb->prefix . 'billnest_invoice_return_items';
    }

    public function create(array $data): int
    {
        global $wpdb;

        $inserted = $wpdb->insert(
            $this->table,
            [
                'invoice_id'     => $data['invoice_id'],
                'items_total'    => $data['items_total'],
                'discount_share' => $data['discount_share'],
                'return_total'   => $data['return_total'],
                'due_reduced'    => $data['due_reduced'],
                'refund_total'   => $data['refund_total'],
                'remarks'        => $data['remarks'] ?? '',
                'created_by'     => $data['created_by'] ?? null,
                'created_at'     => current_time('mysql'),
            ],
            ['%d', '%f', '%f', '%f', '%f', '%f', '%s', '%d', '%s']
        );

        if ($inserted === false) {
            throw new \RuntimeException('Could not save the return.');
        }

        return (int) $wpdb->insert_id;
    }

    public function create_item(int $return_id, array $line): void
    {
        global $wpdb;

        $inserted = $wpdb->insert(
            $this->items_table,
            [
                'return_id'       => $return_id,
                'invoice_item_id' => $line['item_id'],
                'product_id'      => $line['product_id'],
                'qty'             => $line['qty'],
                'unit_price'      => $line['unit_price'],
                'amount'          => $line['amount'],
            ],
            ['%d', '%d', '%d', '%f', '%f', '%f']
        );

        if ($inserted === false) {
            throw new \RuntimeException('Could not save the returned item.');
        }
    }

    public function get_returned_qty_by_item(int $invoice_id): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ri.invoice_item_id, SUM(ri.qty) AS qty
                 FROM {$this->items_table} ri
                 INNER JOIN {$this->table} r ON r.id = ri.return_id
                 WHERE r.invoice_id = %d
                 GROUP BY ri.invoice_item_id",
                $invoice_id
            )
        );

        $result = [];

        foreach ($rows as $row) {
            $result[(int) $row->invoice_item_id] = (float) $row->qty;
        }

        return $result;
    }

    public function get_returned_total(int $invoice_id): float
    {
        global $wpdb;

        return (float) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(return_total), 0) FROM {$this->table} WHERE invoice_id = %d",
                $invoice_id
            )
        );
    }
}