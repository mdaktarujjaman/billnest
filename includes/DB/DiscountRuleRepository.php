<?php

namespace BillNest\DB;

if (! defined('ABSPATH')) {
    exit;
}

class DiscountRuleRepository
{
    private const TYPES = ['flat', 'percent'];
    private const STATUSES = ['active', 'inactive'];

    private function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'billnest_discount_rules';
    }

    public function find(int $id): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->table()} WHERE id = %d", $id),
            ARRAY_A
        );

        return $row ?: null;
    }

    public function all(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT * FROM {$this->table()} ORDER BY status ASC, start_date DESC, id DESC",
            ARRAY_A
        );

        return $rows ?: [];
    }

    public function find_active_for_method(string $method, string $date): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table()}
                WHERE method = %s AND status = 'active' AND start_date <= %s AND end_date >= %s
                ORDER BY id ASC",
                sanitize_key($method),
                $date,
                $date
            ),
            ARRAY_A
        );

        return $rows ?: [];
    }

    public function create(array $data): int|false
    {
        global $wpdb;

        $row = $this->normalize($data);

        $inserted = $wpdb->insert(
            $this->table(),
            $row,
            ['%s', '%s', '%f', '%f', '%f', '%s', '%s', '%s']
        );

        return $inserted ? (int) $wpdb->insert_id : false;
    }

    public function update(int $id, array $data): bool
    {
        global $wpdb;

        $row = $this->normalize($data);

        $updated = $wpdb->update(
            $this->table(),
            $row,
            ['id' => $id],
            ['%s', '%s', '%f', '%f', '%f', '%s', '%s', '%s'],
            ['%d']
        );

        return $updated !== false;
    }

    public function deactivate(int $id): bool
    {
        global $wpdb;

        $updated = $wpdb->update(
            $this->table(),
            ['status' => 'inactive'],
            ['id' => $id],
            ['%s'],
            ['%d']
        );

        return $updated !== false;
    }

    private function normalize(array $data): array
    {
        $method = sanitize_key($data['method'] ?? '');
        if ($method === '') {
            throw new \InvalidArgumentException(__('Payment method is required.', 'billnest'));
        }

        $type = sanitize_key($data['type'] ?? '');
        if (! in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException(__('Invalid discount type.', 'billnest'));
        }

        $status = sanitize_key($data['status'] ?? 'active');
        if (! in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException(__('Invalid status.', 'billnest'));
        }

        $value = (float) ($data['value'] ?? 0);
        if ($value <= 0) {
            throw new \InvalidArgumentException(__('Discount value must be greater than zero.', 'billnest'));
        }
        if ($type === 'percent' && $value > 100) {
            throw new \InvalidArgumentException(__('Percent discount cannot exceed 100.', 'billnest'));
        }

        $max_discount = $data['max_discount'] ?? null;
        if ($max_discount === null || $max_discount === '') {
            $max_discount = null;
        } else {
            $max_discount = (float) $max_discount;
            if ($max_discount <= 0) {
                throw new \InvalidArgumentException(__('Maximum discount must be greater than zero.', 'billnest'));
            }
        }

        $min_order = (float) ($data['min_order'] ?? 0);
        if ($min_order < 0) {
            throw new \InvalidArgumentException(__('Minimum order cannot be negative.', 'billnest'));
        }

        $start_date = $this->valid_date((string) ($data['start_date'] ?? ''));
        $end_date = $this->valid_date((string) ($data['end_date'] ?? ''));
        if ($end_date < $start_date) {
            throw new \InvalidArgumentException(__('End date cannot be before start date.', 'billnest'));
        }

        return [
            'method'       => $method,
            'type'         => $type,
            'value'        => $value,
            'max_discount' => $max_discount,
            'min_order'    => $min_order,
            'start_date'   => $start_date,
            'end_date'     => $end_date,
            'status'       => $status,
        ];
    }

    private function valid_date(string $date): string
    {
        $parsed = \DateTime::createFromFormat('Y-m-d', $date);

        if (! $parsed || $parsed->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException(__('Invalid date. Use YYYY-MM-DD.', 'billnest'));
        }

        return $date;
    }
}