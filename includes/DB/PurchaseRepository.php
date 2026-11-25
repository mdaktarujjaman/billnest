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

    public function create_payment(int $purchase_id, string $method, float $amount, ?string $reference, ?int $supplier_payment_id = null): void
    {
        global $wpdb;

        $inserted = $wpdb->insert(
            $this->payments_table,
            [
                'purchase_id'         => $purchase_id,
                'supplier_payment_id' => $supplier_payment_id,
                'method'              => $method,
                'amount'              => $amount,
                'reference'           => $reference,
                'created_at'          => current_time('mysql'),
            ],
            ['%d', '%d', '%s', '%f', '%s', '%s']
        );

        if ($inserted === false) {
            throw new \RuntimeException('Could not save the purchase payment.');
        }
    }

    /**
     * Purchases of a supplier that still have a balance, oldest first.
     * Locks the rows, so call it inside a transaction.
     */
    public function get_open_for_supplier(int $supplier_id): array
    {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, purchase_no, due_amount FROM {$this->table}
                 WHERE supplier_id = %d AND status = 'completed' AND due_amount > 0
                 ORDER BY purchase_date ASC, id ASC
                 FOR UPDATE",
                $supplier_id
            )
        );
    }

    public function apply_payment(int $purchase_id, float $amount): void
    {
        global $wpdb;

        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table}
                 SET paid_amount = paid_amount + %f, due_amount = due_amount - %f
                 WHERE id = %d AND due_amount + 0.005 >= %f",
                $amount,
                $amount,
                $purchase_id,
                $amount
            )
        );

        if ($updated === false || $updated === 0) {
            throw new \RuntimeException('Could not apply the payment to the purchase.');
        }
    }

    public function search(array $filters, int $limit, int $offset): array
    {
        global $wpdb;

        [$where, $args] = $this->build_filter($filters);

        $args[] = max(1, $limit);
        $args[] = max(0, $offset);

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.*, s.name AS supplier_name
                 FROM {$this->table} p
                 LEFT JOIN {$wpdb->prefix}billnest_suppliers s ON p.supplier_id = s.id
                 WHERE {$where}
                 ORDER BY p.id DESC
                 LIMIT %d OFFSET %d",
                $args
            )
        );
    }

    public function summarize(array $filters): object
    {
        global $wpdb;

        [$where, $args] = $this->build_filter($filters);

        $sql = "SELECT COUNT(*) AS purchase_count,
                       COALESCE(SUM(p.total), 0) AS total_purchased,
                       COALESCE(SUM(p.paid_amount), 0) AS total_paid,
                       COALESCE(SUM(p.due_amount), 0) AS total_due
                FROM {$this->table} p
                LEFT JOIN {$wpdb->prefix}billnest_suppliers s ON p.supplier_id = s.id
                WHERE {$where}";

        $row = $wpdb->get_row($args ? $wpdb->prepare($sql, $args) : $sql);

        return $row ?: (object) [
            'purchase_count'  => 0,
            'total_purchased' => 0,
            'total_paid'      => 0,
            'total_due'       => 0,
        ];
    }

    private function build_filter(array $filters): array
    {
        global $wpdb;

        $where = ["p.status != 'cancelled'"];
        $args  = [];

        if (! empty($filters['date_from'])) {
            $where[] = 'p.purchase_date >= %s';
            $args[]  = (string) $filters['date_from'];
        }

        if (! empty($filters['date_to'])) {
            $where[] = 'p.purchase_date <= %s';
            $args[]  = (string) $filters['date_to'];
        }

        if (($filters['balance'] ?? '') === 'owing') {
            $where[] = 'p.due_amount > 0';
        }

        if (! empty($filters['search'])) {
            $like    = '%' . $wpdb->esc_like((string) $filters['search']) . '%';
            $where[] = '(p.purchase_no LIKE %s OR s.name LIKE %s)';
            $args[]  = $like;
            $args[]  = $like;
        }

        return [implode(' AND ', $where), $args];
    }

    public function get_by_id(int $id): ?object
    {
        global $wpdb;

        $purchase = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT p.*, s.name AS supplier_name
                 FROM {$this->table} p
                 LEFT JOIN {$wpdb->prefix}billnest_suppliers s ON p.supplier_id = s.id
                 WHERE p.id = %d",
                $id
            )
        );

        return $purchase ?: null;
    }

    public function get_items(int $purchase_id): array
    {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT pi.*, pr.code AS product_code, pr.name AS product_name
                 FROM {$this->items_table} pi
                 LEFT JOIN {$wpdb->prefix}billnest_products pr ON pi.product_id = pr.id
                 WHERE pi.purchase_id = %d
                 ORDER BY pi.id ASC",
                $purchase_id
            )
        );
    }

    public function get_payments(int $purchase_id): array
    {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->payments_table} WHERE purchase_id = %d ORDER BY id ASC",
                $purchase_id
            )
        );
    }
}