<?php

namespace BillNest\Services;

if (! defined('ABSPATH')) {
    exit;
}

class SettingsService
{

    private const OPTION = 'billnest_settings';

    public function defaults(): array
    {
        return [
            'business_name'     => get_bloginfo('name'),
            'business_phone'    => '',
            'business_address'  => '',
            'currency_symbol'   => '$',
            'currency_position' => 'before',
        ];
    }

    public function all(): array
    {
        $saved = get_option(self::OPTION, []);

        return wp_parse_args(is_array($saved) ? $saved : [], $this->defaults());
    }

    public function get(string $key): string
    {
        $settings = $this->all();

        return (string) ($settings[$key] ?? '');
    }

    public function save(array $data): void
    {
        $position = $data['currency_position'] ?? 'before';

        $clean = [
            'business_name'     => sanitize_text_field($data['business_name'] ?? ''),
            'business_phone'    => sanitize_text_field($data['business_phone'] ?? ''),
            'business_address'  => sanitize_textarea_field($data['business_address'] ?? ''),
            'currency_symbol'   => sanitize_text_field($data['currency_symbol'] ?? ''),
            'currency_position' => in_array($position, ['before', 'after'], true) ? $position : 'before',
        ];

        update_option(self::OPTION, $clean);
    }
}