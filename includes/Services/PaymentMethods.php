<?php

namespace BillNest\Services;

if (! defined('ABSPATH')) {
    exit;
}

class PaymentMethods
{

    private const METHODS = [
        'cash'   => 'Cash',
        'card'   => 'Card',
        'bkash'  => 'bKash',
        'nagad'  => 'Nagad',
        'rocket' => 'Rocket',
    ];

    public static function all(): array
    {
        return self::METHODS;
    }

    public static function is_valid(string $method): bool
    {
        return isset(self::METHODS[$method]);
    }

    public static function label(string $method): string
    {
        return self::METHODS[$method] ?? $method;
    }

    public static function requires_reference(string $method): bool
    {
        return $method !== 'cash';
    }
}