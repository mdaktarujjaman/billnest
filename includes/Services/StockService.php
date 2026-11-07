<?php

namespace BillNest\Services;

use BillNest\DB\ProductRepository;

if (! defined('ABSPATH')) {
    exit;
}

class StockService
{

    public function assert_available(array $items): void
    {
        if ((new SettingsService())->allow_negative_stock()) {
            return;
        }

        $needed = [];

        foreach ($items as $item) {
            $id          = (int) $item['product_id'];
            $needed[$id] = ($needed[$id] ?? 0.0) + (float) $item['qty'];
        }

        ksort($needed);

        $products = new ProductRepository();

        foreach ($needed as $id => $qty) {
            $product = $products->get_stock_for_update($id);

            if ($product === null) {
                throw new \InvalidArgumentException('Product not found.');
            }

            $available = (float) $product->stock_qty;

            if (round($qty, 4) - round($available, 4) > 0.0001) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'Not enough stock for "%s". Available: %s, requested: %s.',
                        $product->name,
                        $this->format_qty($available),
                        $this->format_qty($qty)
                    )
                );
            }
        }
    }

    public function decrement_stock(int $product_id, float $qty, string $type, int $ref_id, string $ref_type): void
    {
        global $wpdb;

        $products_table = $wpdb->prefix . 'billnest_products';

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE $products_table SET stock_qty = stock_qty - %f WHERE id = %d",
                $qty,
                $product_id
            )
        );

        $balance_after = $wpdb->get_var(
            $wpdb->prepare("SELECT stock_qty FROM $products_table WHERE id = %d", $product_id)
        );

        $this->log_stock_change($product_id, -$qty, $type, $ref_id, $ref_type, (float) $balance_after);
    }

    public function increment_stock(int $product_id, float $qty, string $type, int $ref_id, string $ref_type): void
    {
        global $wpdb;

        $products_table = $wpdb->prefix . 'billnest_products';

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE $products_table SET stock_qty = stock_qty + %f WHERE id = %d",
                $qty,
                $product_id
            )
        );

        $balance_after = $wpdb->get_var(
            $wpdb->prepare("SELECT stock_qty FROM $products_table WHERE id = %d", $product_id)
        );

        $this->log_stock_change($product_id, $qty, $type, $ref_id, $ref_type, (float) $balance_after);
    }

    private function format_qty(float $qty): string
    {
        return rtrim(rtrim(number_format($qty, 4, '.', ''), '0'), '.');
    }

    private function log_stock_change(int $product_id, float $change_qty, string $type, int $ref_id, string $ref_type, float $balance_after): void
    {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'billnest_stock_log',
            [
                'product_id'    => $product_id,
                'change_qty'    => $change_qty,
                'type'          => $type,
                'ref_id'        => $ref_id,
                'ref_type'      => $ref_type,
                'balance_after' => $balance_after,
                'created_at'    => current_time('mysql'),
            ],
            ['%d', '%f', '%s', '%d', '%s', '%f', '%s']
        );
    }
}