<?php

namespace BillNest\Services;

use BillNest\DB\DiscountRuleRepository;

if (! defined('ABSPATH')) {
    exit;
}

class DiscountRuleService
{
    private DiscountRuleRepository $rules;

    public function __construct(?DiscountRuleRepository $rules = null)
    {
        $this->rules = $rules ?? new DiscountRuleRepository();
    }

    public function calculate(float $subtotal, array $payments, ?string $date = null): ?array
    {
        if ($subtotal <= 0) {
            return null;
        }

        $method = $this->single_online_method($payments);
        if ($method === null) {
            return null;
        }

        $date = $date ?? wp_date('Y-m-d');
        $best = null;

        foreach ($this->rules->find_active_for_method($method, $date) as $rule) {
            if ($subtotal < (float) $rule['min_order']) {
                continue;
            }

            $amount = $this->amount_for($rule, $subtotal);
            if ($amount <= 0) {
                continue;
            }

            if ($best === null || $amount > $best['amount']) {
                $best = [
                    'rule_id' => (int) $rule['id'],
                    'method'  => $method,
                    'type'    => $rule['type'],
                    'value'   => (float) $rule['value'],
                    'amount'  => $amount,
                ];
            }
        }

        if ($best === null) {
            return null;
        }

        $payable = round($subtotal - $best['amount'], 2);
        if ($this->paid_amount($payments) + 0.005 < $payable) {
            return null;
        }

        return $best;
    }

    private function amount_for(array $rule, float $subtotal): float
    {
        $value = (float) $rule['value'];
        $amount = $rule['type'] === 'percent' ? $subtotal * $value / 100 : $value;

        if ($rule['max_discount'] !== null) {
            $amount = min($amount, (float) $rule['max_discount']);
        }

        return round(min($amount, $subtotal), 2);
    }

    private function single_online_method(array $payments): ?string
    {
        $methods = [];

        foreach ($payments as $line) {
            if ((float) ($line['amount'] ?? 0) <= 0) {
                continue;
            }
            $methods[sanitize_key($line['method'] ?? '')] = true;
        }

        $methods = array_keys($methods);

        if (count($methods) !== 1 || $methods[0] === '' || $methods[0] === 'cash') {
            return null;
        }

        return $methods[0];
    }

    private function paid_amount(array $payments): float
    {
        $total = 0.0;

        foreach ($payments as $line) {
            $amount = (float) ($line['amount'] ?? 0);
            if ($amount > 0) {
                $total += $amount;
            }
        }

        return $total;
    }
}