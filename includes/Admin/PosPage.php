<?php

namespace BillNest\Admin;

use BillNest\DB\CustomerRepository;
use BillNest\DB\DiscountRuleRepository;
use BillNest\DB\ProductRepository;
use BillNest\Services\InvoiceService;
use BillNest\Services\PaymentMethods;
use BillNest\Services\SettingsService;

if (! defined('ABSPATH')) {
    exit;
}

class PosPage extends AdminPage
{

    private const HANDLE = 'billnest-pos';

    public function get_slug(): string
    {
        return 'billnest-pos';
    }

    public function get_title(): string
    {
        return __('New Sale', 'billnest');
    }

    private function asset_version(string $relative_path): string
    {
        $file = dirname(__DIR__, 2) . '/' . $relative_path;

        return file_exists($file) ? (string) filemtime($file) : BILLNEST_VERSION;
    }

    public function enqueue_assets(): void
    {
        wp_enqueue_style(
            self::HANDLE,
            BILLNEST_PLUGIN_URL . 'assets/admin/css/pos.css',
            [],
            $this->asset_version('assets/admin/css/pos.css')
        );

        wp_enqueue_script(
            self::HANDLE,
            BILLNEST_PLUGIN_URL . 'assets/admin/js/pos.js',
            [],
            $this->asset_version('assets/admin/js/pos.js'),
            true
        );
    }

    public function render(): void
    {
        if (! current_user_can($this->get_capability())) {
            wp_die(esc_html__('You do not have permission to access this page.', 'billnest'));
        }

        $result  = $this->handle_form_submission();
        $restore = ($result && $result['type'] === 'error') ? $this->posted_form_data() : null;

        $customers = (new CustomerRepository())->get_all();
        $products  = (new ProductRepository())->get_all();
        $settings  = new SettingsService();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html($this->get_title()) . '</h1>';

        if ($result) {
            $class = $result['type'] === 'success' ? 'notice-success' : 'notice-error';
            echo '<div class="notice ' . esc_attr($class) . '"><p>' . esc_html($result['message']) . '</p></div>';
        }

        $this->render_form($settings);
        $this->provide_script_data($products, $customers, $settings, $restore);

        echo '</div>';
    }

    private function render_form(SettingsService $settings): void
    {
        echo '<div class="billnest-pos-wrap">';
        echo '<div class="billnest-pos-header">' . esc_html__('Create New Invoice', 'billnest') . '</div>';
        echo '<div class="billnest-pos-body">';

        echo '<form method="post" id="billnest-pos-form">';
        wp_nonce_field('billnest_create_invoice', 'billnest_invoice_nonce');

        echo '<table class="form-table">';
        echo '<tr><th><label for="customer_search">' . esc_html__('Customer', 'billnest') . '</label></th>';
        echo '<td><div class="billnest-customer-search">';
        echo '<input type="text" id="customer_search" class="billnest-customer-input" placeholder="' . esc_attr__('Walk-in customer (search name or phone to pick one)', 'billnest') . '" autocomplete="off">';
        echo '<input type="hidden" name="customer_id" id="customer_id" value="0">';
        echo '<button type="button" class="button billnest-customer-clear" id="billnest-customer-clear" title="' . esc_attr__('Back to walk-in customer', 'billnest') . '" hidden>&times;</button>';
        echo '<ul class="billnest-customer-results" id="billnest-customer-results" hidden></ul>';
        echo '<div class="billnest-customer-note" id="billnest-customer-note" hidden></div>';
        echo '</div></td></tr>';
        echo '</table>';

        echo '<table class="wp-list-table widefat fixed striped" id="billnest-items-table">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Product', 'billnest') . '</th>';
        echo '<th style="width:100px;">' . esc_html__('Qty', 'billnest') . '</th>';
        echo '<th style="width:130px;">' . esc_html__('Line Total', 'billnest') . '</th>';
        echo '<th style="width:50px;"></th>';
        echo '</tr></thead>';
        echo '<tbody id="billnest-items-body"></tbody>';
        echo '</table>';

        echo '<button type="button" class="button" id="billnest-add-item">' . esc_html__('+ Add Item', 'billnest') . '</button>';

        $symbol = esc_html($settings->get('currency_symbol'));
        $before = $settings->get('currency_position') !== 'after';

        echo '<div class="billnest-totals-box">';
        echo '<div class="billnest-row"><span>' . esc_html__('Subtotal', 'billnest') . '</span><span>' . ($before ? $symbol : '') . '<span id="billnest-subtotal">0.00</span>' . ($before ? '' : $symbol) . '</span></div>';
        echo '<div class="billnest-row"><span id="billnest-discount-label">' . esc_html__('Discount', 'billnest') . '</span><span>-' . ($before ? $symbol : '') . '<span id="billnest-discount-display">0.00</span>' . ($before ? '' : $symbol) . '</span></div>';
        echo '<div class="billnest-row billnest-total-row"><span>' . esc_html__('Total', 'billnest') . '</span><span>' . ($before ? $symbol : '') . '<span id="billnest-total-display">0.00</span>' . ($before ? '' : $symbol) . '</span></div>';
        echo '<div class="billnest-row"><span>' . esc_html__('Paid', 'billnest') . '</span><span>' . ($before ? $symbol : '') . '<span id="billnest-paid-display">0.00</span>' . ($before ? '' : $symbol) . '</span></div>';
        echo '<div class="billnest-row billnest-balance-row billnest-change" id="billnest-balance-row"><span id="billnest-balance-label">' . esc_html__('Change to Return', 'billnest') . '</span><span>' . ($before ? $symbol : '') . '<span id="billnest-balance-amount">0.00</span>' . ($before ? '' : $symbol) . '</span></div>';
        echo '</div>';

        echo '<table class="form-table">';
        echo '<tr><th><label for="discount_type">' . esc_html__('Discount', 'billnest') . '</label></th>';
        echo '<td><select name="discount_type" id="discount_type">';
        echo '<option value="">' . esc_html__('None', 'billnest') . '</option>';
        echo '<option value="flat">' . esc_html__('Flat amount', 'billnest') . '</option>';
        echo '<option value="percent">' . esc_html__('Percent (%)', 'billnest') . '</option>';
        echo '</select> ';
        echo '<input type="number" step="0.01" min="0" name="discount_value" id="discount_value" value="0" disabled></td></tr>';
        echo '</table>';

        echo '<h3>' . esc_html__('Payments', 'billnest') . '</h3>';
        echo '<table class="wp-list-table widefat fixed striped" id="billnest-payments-table">';
        echo '<thead><tr>';
        echo '<th style="width:160px;">' . esc_html__('Method', 'billnest') . '</th>';
        echo '<th style="width:140px;">' . esc_html__('Amount', 'billnest') . '</th>';
        echo '<th>' . esc_html__('Reference (trx ID / approval code)', 'billnest') . '</th>';
        echo '<th style="width:50px;"></th>';
        echo '</tr></thead>';
        echo '<tbody id="billnest-payments-body"></tbody>';
        echo '</table>';

        echo '<button type="button" class="button" id="billnest-add-payment">' . esc_html__('+ Add Payment', 'billnest') . '</button>';

        echo '<div class="notice notice-warning inline" id="billnest-pay-warning" style="display:none;"><p>'
            . esc_html__('Card and mobile payments cannot be more than the invoice total.', 'billnest')
            . '</p></div>';

        submit_button(__('Save Invoice', 'billnest'));
        echo '</form>';
        echo '</div>';
        echo '</div>';
    }

    private function active_rule_data(): array
    {
        $data = [];

        try {
            $today = wp_date('Y-m-d');

            foreach ((new DiscountRuleRepository())->all() as $rule) {
                if ($rule['status'] !== 'active' || $rule['start_date'] > $today || $rule['end_date'] < $today) {
                    continue;
                }

                $data[] = [
                    'method'       => $rule['method'],
                    'type'         => $rule['type'],
                    'value'        => (float) $rule['value'],
                    'max_discount' => $rule['max_discount'] === null ? null : (float) $rule['max_discount'],
                    'min_order'    => (float) $rule['min_order'],
                ];
            }
        } catch (\Throwable $e) {
            error_log('BillNest POS could not load discount rules: ' . $e->getMessage());
        }

        return $data;
    }

    private function provide_script_data(array $products, array $customers, SettingsService $settings, ?array $restore): void
    {
        if (! wp_script_is(self::HANDLE, 'enqueued')) {
            $this->enqueue_assets();
        }

        $product_data = [];
        foreach ($products as $product) {
            $product_data[] = [
                'id'    => (int) $product->id,
                'code'  => (string) $product->code,
                'name'  => (string) $product->name,
                'model' => (string) ($product->model ?? ''),
                'price' => (float) $product->sale_price,
                'stock' => (float) $product->stock_qty,
            ];
        }

        $customer_data = [];
        foreach ($customers as $customer) {
            $customer_data[] = [
                'id'    => (int) $customer->id,
                'name'  => (string) $customer->name,
                'phone' => (string) ($customer->phone ?? ''),
                'email' => (string) ($customer->email ?? ''),
                'due'   => (float) $customer->current_due,
            ];
        }

        $data = [
            'products'       => $product_data,
            'customers'      => $customer_data,
            'methods'        => PaymentMethods::all(),
            'rules'          => $this->active_rule_data(),
            'restore'        => $restore,
            'allowNegative'  => $settings->allow_negative_stock(),
            'currencySymbol' => $settings->get('currency_symbol'),
            'currencyBefore' => $settings->get('currency_position') !== 'after',
        ];

        wp_add_inline_script(
            self::HANDLE,
            'window.billnestPos = ' . wp_json_encode($data) . ';',
            'before'
        );
    }

    private function read_payment_lines(): array
    {
        $lines = [];

        foreach ((array) ($_POST['payments'] ?? []) as $line) {
            if (! is_array($line)) {
                continue;
            }

            $lines[] = [
                'method'    => sanitize_key(wp_unslash($line['method'] ?? '')),
                'amount'    => floatval($line['amount'] ?? 0),
                'reference' => sanitize_text_field(wp_unslash($line['reference'] ?? '')),
            ];
        }

        return $lines;
    }

    private function posted_form_data(): array
    {
        $items = [];

        foreach ((array) ($_POST['items'] ?? []) as $raw_item) {
            if (! is_array($raw_item)) {
                continue;
            }

            $product_id = absint($raw_item['product_id'] ?? 0);

            if ($product_id <= 0) {
                continue;
            }

            $items[] = [
                'product_id' => $product_id,
                'qty'        => floatval($raw_item['qty'] ?? 1),
            ];
        }

        $discount_type = sanitize_key(wp_unslash($_POST['discount_type'] ?? ''));

        if (! in_array($discount_type, ['flat', 'percent'], true)) {
            $discount_type = '';
        }

        return [
            'customer_id'    => absint($_POST['customer_id'] ?? 0),
            'items'          => $items,
            'discount_type'  => $discount_type,
            'discount_value' => $discount_type ? max(0, floatval($_POST['discount_value'] ?? 0)) : 0,
            'payments'       => $this->read_payment_lines(),
        ];
    }

    private function handle_form_submission(): ?array
    {
        if (! isset($_POST['billnest_invoice_nonce'])) {
            return null;
        }

        if (! wp_verify_nonce($_POST['billnest_invoice_nonce'], 'billnest_create_invoice')) {
            wp_die(esc_html__('Security check failed.', 'billnest'));
        }

        $raw_items = $_POST['items'] ?? [];

        if (empty($raw_items)) {
            return null;
        }

        $product_repo = new ProductRepository();
        $items        = [];

        foreach ($raw_items as $raw_item) {
            $product_id = absint($raw_item['product_id'] ?? 0);
            $qty        = floatval($raw_item['qty'] ?? 0);

            if ($product_id <= 0 || $qty <= 0) {
                continue;
            }

            $product = $product_repo->get_by_id($product_id);

            if (! $product) {
                continue;
            }

            $items[] = [
                'product_id' => $product_id,
                'qty'        => $qty,
                'unit_price' => (float) $product->sale_price,
            ];
        }

        if (empty($items)) {
            return null;
        }

        $discount_type = sanitize_key(wp_unslash($_POST['discount_type'] ?? ''));

        if (! in_array($discount_type, ['flat', 'percent'], true)) {
            $discount_type = null;
        }

        $discount_value = $discount_type ? max(0, floatval($_POST['discount_value'] ?? 0)) : 0;

        if ($discount_type === 'percent') {
            $discount_value = min($discount_value, 100);
        }

        $service = new InvoiceService();

        try {
            $invoice_id = $service->create_invoice([
                'customer_id'     => absint($_POST['customer_id'] ?? 0) ?: null,
                'discount_type'   => $discount_type,
                'discount_value'  => $discount_value,
                'payments'        => $this->read_payment_lines(),
                'received_amount' => 0,
                'remarks'         => '',
                'created_by'      => get_current_user_id(),
                'items'           => $items,
            ]);
        } catch (\InvalidArgumentException $e) {
            return ['type' => 'error', 'message' => $e->getMessage()];
        } catch (\Throwable $e) {
            error_log('BillNest invoice save failed: ' . $e->getMessage());

            return [
                'type'    => 'error',
                'message' => __('Could not save the invoice. Please check the debug log.', 'billnest'),
            ];
        }

        return [
            'type'    => 'success',
            'message' => sprintf(__('Invoice #%d created successfully.', 'billnest'), $invoice_id),
        ];
    }
}