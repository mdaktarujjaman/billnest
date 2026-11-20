<?php

namespace BillNest\Admin;

use BillNest\DB\ProductRepository;
use BillNest\DB\SupplierRepository;
use BillNest\Services\PaymentMethods;
use BillNest\Services\PurchaseService;
use BillNest\Services\SettingsService;

if (! defined('ABSPATH')) {
    exit;
}

class PurchasePage extends AdminPage
{

    private const HANDLE = 'billnest-purchase';

    public function get_slug(): string
    {
        return 'billnest-purchases';
    }

    public function get_title(): string
    {
        return __('Purchases', 'billnest');
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
            BILLNEST_PLUGIN_URL . 'assets/admin/css/purchase.css',
            [],
            $this->asset_version('assets/admin/css/purchase.css')
        );

        wp_enqueue_script(
            self::HANDLE,
            BILLNEST_PLUGIN_URL . 'assets/admin/js/purchase.js',
            [],
            $this->asset_version('assets/admin/js/purchase.js'),
            true
        );
    }

    public function render(): void
    {
        if (! current_user_can($this->get_capability())) {
            wp_die(esc_html__('You do not have permission to access this page.', 'billnest'));
        }

        $form  = $this->read_form();
        $error = null;

        if (isset($_POST['billnest_purchase_nonce'])) {
            $error = $this->handle_submission($form);
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html($this->get_title()) . '</h1>';

        $saved = sanitize_text_field(wp_unslash($_GET['saved'] ?? ''));

        if ($saved !== '') {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(
                /* translators: %s: purchase number */
                __('Purchase %s saved. Stock, supplier balance and cashbook are updated.', 'billnest'),
                $saved
            )) . '</p></div>';
        }

        if ($error) {
            echo '<div class="notice notice-error"><p>' . esc_html($error) . '</p></div>';
        }

        $this->render_form($form);
        $this->provide_script_data($error ? $form : null);

        echo '</div>';
    }

    private function field(string $key): string
    {
        $value = $_POST[$key] ?? '';

        return is_scalar($value) ? wp_unslash((string) $value) : '';
    }

    private function cell(array $row, string $key): string
    {
        $value = $row[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }

    private function post_array(string $key): array
    {
        return isset($_POST[$key]) && is_array($_POST[$key]) ? wp_unslash($_POST[$key]) : [];
    }

    private function read_form(): array
    {
        $items = [];

        foreach (array_values($this->post_array('items')) as $row) {
            $row     = is_array($row) ? $row : [];
            $items[] = [
                'product_id' => absint($this->cell($row, 'product_id')),
                'label'      => sanitize_text_field($this->cell($row, 'label')),
                'qty'        => sanitize_text_field($this->cell($row, 'qty')),
                'cost'       => sanitize_text_field($this->cell($row, 'cost')),
            ];
        }

        $payments = [];

        foreach (array_values($this->post_array('payments')) as $row) {
            $row        = is_array($row) ? $row : [];
            $payments[] = [
                'method'    => sanitize_key($this->cell($row, 'method')),
                'amount'    => sanitize_text_field($this->cell($row, 'amount')),
                'reference' => sanitize_text_field($this->cell($row, 'reference')),
            ];
        }

        return [
            'supplier_id' => absint($this->field('supplier_id')),
            'date'        => sanitize_text_field($this->field('purchase_date')),
            'remarks'     => sanitize_text_field($this->field('remarks')),
            'items'       => $items,
            'payments'    => $payments,
        ];
    }

    private function handle_submission(array $form): ?string
    {
        check_admin_referer('billnest_save_purchase', 'billnest_purchase_nonce');

        try {
            $input       = $this->build_input($form);
            $purchase_no = (new PurchaseService())->create_purchase($input, get_current_user_id());
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        } catch (\Throwable $e) {
            error_log('BillNest purchase failed: ' . $e->getMessage());

            return __('Something went wrong. The purchase was not saved.', 'billnest');
        }

        wp_safe_redirect(admin_url('admin.php?page=' . $this->get_slug() . '&saved=' . rawurlencode($purchase_no)));
        exit;
    }

    private function build_input(array $form): array
    {
        $items = [];

        foreach ($form['items'] as $index => $row) {
            if ($row['product_id'] === 0 && $row['label'] === '' && $row['qty'] === '' && $row['cost'] === '') {
                continue;
            }

            $line = $index + 1;

            if ($row['product_id'] === 0) {
                throw new \InvalidArgumentException(sprintf(__('Line %d: choose a product from the list.', 'billnest'), $line));
            }

            $items[] = [
                'row'        => $line,
                'product_id' => $row['product_id'],
                'qty'        => is_numeric($row['qty']) ? (float) $row['qty'] : 0.0,
                'unit_cost'  => is_numeric($row['cost']) ? (float) $row['cost'] : 0.0,
            ];
        }

        $payments = [];

        foreach ($form['payments'] as $index => $row) {
            if ($row['amount'] === '') {
                continue;
            }

            $payments[] = [
                'row'       => $index + 1,
                'method'    => $row['method'],
                'amount'    => is_numeric($row['amount']) ? (float) $row['amount'] : 0.0,
                'reference' => $row['reference'],
            ];
        }

        return [
            'supplier_id'   => $form['supplier_id'],
            'purchase_date' => $form['date'] !== '' ? $form['date'] : current_time('Y-m-d'),
            'remarks'       => $form['remarks'],
            'items'         => $items,
            'payments'      => $payments,
        ];
    }

    private function provide_script_data(?array $restore): void
    {
        if (! wp_script_is(self::HANDLE, 'enqueued')) {
            $this->enqueue_assets();
        }

        $settings     = new SettingsService();
        $product_data = [];

        foreach ((new ProductRepository())->get_all() as $product) {
            $product_data[] = [
                'id'    => (int) $product->id,
                'code'  => (string) $product->code,
                'name'  => (string) $product->name,
                'model' => (string) ($product->model ?? ''),
                'cost'  => (float) ($product->purchase_price ?? 0),
                'stock' => (float) $product->stock_qty,
            ];
        }

        $data = [
            'products'       => $product_data,
            'methods'        => PaymentMethods::all(),
            'restore'        => $restore,
            'currencySymbol' => $settings->get('currency_symbol'),
            'currencyBefore' => $settings->get('currency_position') !== 'after',
        ];

        wp_add_inline_script(
            self::HANDLE,
            'window.billnestPurchase = ' . wp_json_encode($data) . ';',
            'before'
        );
    }

    private function render_form(array $form): void
    {
        $suppliers = (new SupplierRepository())->get_all();
        $date      = $form['date'] !== '' ? $form['date'] : current_time('Y-m-d');

        echo '<h2>' . esc_html__('New Purchase', 'billnest') . '</h2>';

        echo '<form method="post" id="billnest-purchase-form">';
        wp_nonce_field('billnest_save_purchase', 'billnest_purchase_nonce');

        echo '<table class="form-table">';

        echo '<tr><th><label for="supplier_id">' . esc_html__('Supplier', 'billnest') . '</label></th><td>';
        echo '<select name="supplier_id" id="supplier_id">';
        echo '<option value="0">' . esc_html__('No supplier (pay in full)', 'billnest') . '</option>';
        foreach ($suppliers as $supplier) {
            echo '<option value="' . esc_attr($supplier->id) . '"' . selected($form['supplier_id'], (int) $supplier->id, false) . '>' . esc_html($supplier->name) . '</option>';
        }
        echo '</select></td></tr>';

        echo '<tr><th><label for="purchase_date">' . esc_html__('Date', 'billnest') . '</label></th>';
        echo '<td><input type="date" name="purchase_date" id="purchase_date" value="' . esc_attr($date) . '" required></td></tr>';

        echo '</table>';

        echo '<h3>' . esc_html__('Products', 'billnest') . '</h3>';
        echo '<p class="description">' . esc_html__('Search by code, name or model and pick a product. The unit cost starts at the last purchase price and can be changed.', 'billnest') . '</p>';
        echo '<table class="widefat striped billnest-pur-table">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Product', 'billnest') . '</th>';
        echo '<th style="width:130px;">' . esc_html__('Quantity', 'billnest') . '</th>';
        echo '<th style="width:150px;">' . esc_html__('Unit cost', 'billnest') . '</th>';
        echo '<th style="width:130px;">' . esc_html__('Line total', 'billnest') . '</th>';
        echo '<th style="width:50px;"></th>';
        echo '</tr></thead>';
        echo '<tbody id="billnest-pur-items"></tbody>';
        echo '</table>';
        echo '<p><button type="button" class="button" id="billnest-pur-add-item">' . esc_html__('+ Add Item', 'billnest') . '</button></p>';

        echo '<h3>' . esc_html__('Payments', 'billnest') . '</h3>';
        echo '<p class="description">' . esc_html__('Whatever is not paid now stays owed to the supplier. Card and mobile payments need a reference. Leave the amount empty to skip a row.', 'billnest') . '</p>';
        echo '<table class="widefat striped billnest-pur-table">';
        echo '<thead><tr>';
        echo '<th style="width:160px;">' . esc_html__('Method', 'billnest') . '</th>';
        echo '<th style="width:150px;">' . esc_html__('Amount', 'billnest') . '</th>';
        echo '<th>' . esc_html__('Reference', 'billnest') . '</th>';
        echo '<th style="width:50px;"></th>';
        echo '</tr></thead>';
        echo '<tbody id="billnest-pur-payments"></tbody>';
        echo '</table>';
        echo '<p><button type="button" class="button" id="billnest-pur-add-payment">' . esc_html__('+ Add Payment', 'billnest') . '</button></p>';

        echo '<div class="billnest-pur-totals">';
        echo '<div><span>' . esc_html__('Subtotal', 'billnest') . '</span><span id="billnest-pur-subtotal"></span></div>';
        echo '<div><span>' . esc_html__('Paid', 'billnest') . '</span><span id="billnest-pur-paid"></span></div>';
        echo '<div class="billnest-pur-balance-row"><span>' . esc_html__('Owed to supplier', 'billnest') . '</span><span id="billnest-pur-balance"></span></div>';
        echo '</div>';

        echo '<div class="notice notice-warning inline" id="billnest-pur-warning" hidden><p></p></div>';

        echo '<table class="form-table">';
        echo '<tr><th><label for="remarks">' . esc_html__('Remarks', 'billnest') . '</label></th>';
        echo '<td><input type="text" class="regular-text" name="remarks" id="remarks" value="' . esc_attr($form['remarks']) . '"></td></tr>';
        echo '</table>';

        submit_button(__('Save Purchase', 'billnest'));
        echo '</form>';
    }
}