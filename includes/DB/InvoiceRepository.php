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

    public function search(array $filters, int $limit, int $offset): array
    {
        global $wpdb;

        [$where, $args] = $this->build_filter($filters);

        $args[] = max(1, $limit);
        $args[] = max(0, $offset);

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT i.*, c.name AS customer_name
                 FROM {$this->table} i
                 LEFT JOIN {$wpdb->prefix}billnest_customers c ON i.customer_id = c.id
                 WHERE {$where}
                 ORDER BY i.id DESC
                 LIMIT %d OFFSET %d",
                $args
            )
        );
    }

    public function summarize(array $filters): object
    {
        global $wpdb;

        [$where, $args] = $this->build_filter($filters);

        $sql = "SELECT COUNT(*) AS invoice_count,
                       COALESCE(SUM(CASE WHEN i.status = 'completed' THEN i.total ELSE 0 END), 0) AS total_sales,
                       COALESCE(SUM(CASE WHEN i.status = 'completed' THEN i.due_amount ELSE 0 END), 0) AS total_due
                FROM {$this->table} i
                LEFT JOIN {$wpdb->prefix}billnest_customers c ON i.customer_id = c.id
                WHERE {$where}";

        $row = $wpdb->get_row($args ? $wpdb->prepare($sql, $args) : $sql);

        return $row ?: (object) [
            'invoice_count' => 0,
            'total_sales'   => 0,
            'total_due'     => 0,
        ];
    }

    private function build_filter(array $filters): array
    {
        global $wpdb;

        $where = ["i.status != 'cancelled'"];
        $args  = [];

        if (! empty($filters['status'])) {
            $where[] = 'i.status = %s';
            $args[]  = (string) $filters['status'];
        }

        if (! empty($filters['date_from'])) {
            $where[] = 'i.invoice_date >= %s';
            $args[]  = (string) $filters['date_from'];
        }

        if (! empty($filters['date_to'])) {
            $where[] = 'i.invoice_date <= %s';
            $args[]  = (string) $filters['date_to'];
        }

        if (! empty($filters['search'])) {
            $like    = '%' . $wpdb->esc_like((string) $filters['search']) . '%';
            $where[] = '(i.invoice_no LIKE %s OR c.name LIKE %s)';
            $args[]  = $like;
            $args[]  = $like;
        }

        return [implode(' AND ', $where), $args];
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

    public function get_open_invoices_for_customer(int $customer_id): array
    {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table}
                 WHERE customer_id = %d AND status = 'completed' AND due_amount > 0
                 ORDER BY id ASC",
                $customer_id
            )
        );
    }

    public function create_invoice(array $data): int
    {
        global $wpdb;

        $wpdb->insert(
            $this->table,
            [
                'invoice_no'       => $data['invoice_no'],
                'customer_id'      => $data['customer_id'] ?: null,
                'invoice_date'     => $data['invoice_date'],
                'subtotal'         => $data['subtotal'],
                'discount_type'    => $data['discount_type'],
                'discount_value'   => $data['discount_value'],
                'discount_amount'  => $data['discount_amount'],
                'discount_rule_id' => $data['discount_rule_id'] ?? null,
                'total'            => $data['total'],
                'received_amount'  => $data['received_amount'],
                'due_amount'       => $data['due_amount'],
                'remarks'          => $data['remarks'],
                'status'           => 'completed',
                'created_by'       => $data['created_by'],
                'created_at'       => current_time('mysql'),
            ],
            ['%s', '%d', '%s', '%f', '%s', '%f', '%f', '%d', '%f', '%f', '%f', '%s', '%s', '%d', '%s']
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

    public function apply_payment(int $id, float $amount): void
    {
        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table}
                 SET received_amount = received_amount + %f, due_amount = due_amount - %f
                 WHERE id = %d",
                $amount,
                $amount,
                $id
            )
        );
    }
}