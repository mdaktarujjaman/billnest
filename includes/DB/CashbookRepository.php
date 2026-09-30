<?php

namespace BillNest\DB;

if (! defined('ABSPATH')) {
    exit;
}

class CashbookRepository
{

    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'billnest_cashbook';
    }

    public function get_all(): array
    {
        global $wpdb;

        return $wpdb->get_results("SELECT * FROM {$this->table} ORDER BY id DESC");
    }

    public function create(array $data): int
    {
        global $wpdb;

        $wpdb->insert(
            $this->table,
            [
                'type'       => $data['type'],
                'amount'     => $data['amount'],
                'source'     => $data['source'],
                'ref_id'     => $data['ref_id'] ?? null,
                'remarks'    => $data['remarks'] ?? '',
                'created_by' => $data['created_by'] ?? null,
                'created_at' => current_time('mysql'),
            ],
            ['%s', '%f', '%s', '%d', '%s', '%d', '%s']
        );

        return $wpdb->insert_id;
    }

    public function get_balance(): array
    {
        global $wpdb;

        $in = (float) $wpdb->get_var(
            $wpdb->prepare("SELECT SUM(amount) FROM {$this->table} WHERE type = %s", 'in')
        );

        $out = (float) $wpdb->get_var(
            $wpdb->prepare("SELECT SUM(amount) FROM {$this->table} WHERE type = %s", 'out')
        );

        return [
            'in'      => $in,
            'out'     => $out,
            'balance' => $in - $out,
        ];
    }
}