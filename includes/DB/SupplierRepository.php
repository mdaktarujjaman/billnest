<?php

namespace BillNest\DB;

if (! defined('ABSPATH')) {
    exit;
}

class SupplierRepository
{

    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'billnest_suppliers';
    }

    public function get_all(): array
    {
        global $wpdb;

        return $wpdb->get_results(
            "SELECT * FROM {$this->table} WHERE status = 'active' ORDER BY name ASC"
        );
    }

    public function get_by_id(int $id): ?object
    {
        global $wpdb;

        $supplier = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $id)
        );

        return $supplier ?: null;
    }

    public function get_for_update(int $id): ?object
    {
        global $wpdb;

        $supplier = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d FOR UPDATE", $id)
        );

        return $supplier ?: null;
    }

    public function create(array $data): int
    {
        global $wpdb;

        $inserted = $wpdb->insert(
            $this->table,
            [
                'name'            => $data['name'],
                'phone'           => $data['phone'],
                'email'           => $data['email'],
                'address'         => $data['address'],
                'opening_balance' => $data['opening_balance'],
                'current_payable' => $data['opening_balance'],
                'status'          => 'active',
                'created_at'      => current_time('mysql'),
            ],
            ['%s', '%s', '%s', '%s', '%f', '%f', '%s', '%s']
        );

        if ($inserted === false) {
            throw new \RuntimeException('Could not save the supplier.');
        }

        return (int) $wpdb->insert_id;
    }

    public function update(int $id, array $data): void
    {
        global $wpdb;

        $updated = $wpdb->update(
            $this->table,
            [
                'name'    => $data['name'],
                'phone'   => $data['phone'],
                'email'   => $data['email'],
                'address' => $data['address'],
            ],
            ['id' => $id],
            ['%s', '%s', '%s', '%s'],
            ['%d']
        );

        if ($updated === false) {
            throw new \RuntimeException('Could not update the supplier.');
        }
    }

    public function delete(int $id): void
    {
        global $wpdb;

        $updated = $wpdb->update(
            $this->table,
            ['status' => 'inactive'],
            ['id' => $id],
            ['%s'],
            ['%d']
        );

        if ($updated === false) {
            throw new \RuntimeException('Could not delete the supplier.');
        }
    }

    public function increment_payable(int $id, float $amount): void
    {
        global $wpdb;

        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table} SET current_payable = current_payable + %f WHERE id = %d",
                $amount,
                $id
            )
        );

        if ($updated === false) {
            throw new \RuntimeException('Could not update the supplier balance.');
        }
    }
}
