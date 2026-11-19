<?php

namespace BillNest\Admin;

use BillNest\DB\ProductRepository;
use BillNest\DB\SupplierRepository;
use BillNest\Services\PaymentMethods;
use BillNest\Services\PurchaseService;

if (! defined('ABSPATH')) {
    exit;
}

class PurchasePage extends AdminPage
{

    private const ITEM_ROWS    = 8;
    private const PAYMENT_ROWS = 3;

    public function get_slug(): string
    {
        return 'billnest-purchases';
    }

    public function get_title(): string
    {
        return __('Purchases', 'billnest');
    }

    public function render(): void
    {
        if (! current_user_can($this->get_capability())) {
            wp_die(esc_html__('You do not have permission to access this page.', 'billnest'));
        }

        $products = (new ProductRepository())->get_all();
        $lookup   = $this->build_lookup($products);
        $form     = $this->read_form();
        $error    = null;

        if (isset($_POST['billnest_purchase_nonce'])) {
            $error = $this->handle_submission($form, $lookup);
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

        $this->render_form($form, $products);

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
                'product' => sanitize_text_field($this->cell($row, 'product')),
                'qty'     => sanitize_text_field($this->cell($row, 'qty')),
                'cost'    => sanitize_text_field($this->cell($row, 'cost')),
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

    private function build_lookup(array $products): array
    {
        $labels = [];
        $codes  = [];

        foreach ($products as $product) {
            $labels[$this->product_label($product)]   = (int) $product->id;
            $codes[strtolower((string) $product->code)] = (int) $product->id;
        }

        return ['labels' => $labels, 'codes' => $codes];
    }

    private function product_label(object $product): string
    {
        return $product->code . ' - ' . $product->name;
    }

    private function handle_submission(array $form, array $lookup): ?string
    {
        check_admin_referer('billnest_save_purchase', 'billnest_purchase_nonce');

        try {
            $input       = $this->build_input($form, $lookup);
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

    private function build_input(array $form, array $lookup): array
    {
        $items = [];

        foreach ($form['items'] as $index => $row) {
            if ($row['product'] === '' && $row['qty'] === '' && $row['cost'] === '') {
                continue;
            }

            $line       = $index + 1;
            $product_id = $lookup['labels'][$row['product']] ?? ($lookup['codes'][strtolower($row['product'])] ?? 0);

            if ($product_id === 0) {
                throw new \InvalidArgumentException(sprintf(__('Line %d: choose a product from the list.', 'billnest'), $line));
            }

            $items[] = [
                'row'        => $line,
                'product_id' => $product_id,
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

    private function render_form(array $form, array $products): void
    {
        $suppliers = (new SupplierRepository())->get_all();
        $date      = $form['date'] !== '' ? $form['date'] : current_time('Y-m-d');

        echo '<h2>' . esc_html__('New Purchase', 'billnest') . '</h2>';

        echo '<datalist id="billnest-purchase-products">';
        foreach ($products as $product) {
            echo '<option value="' . esc_attr($this->product_label($product)) . '"></option>';
        }
        echo '</datalist>';

        echo '<form method="post">';
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
        echo '<p class="description">' . esc_html__('Pick a product from the list or type its code. Leave unused rows empty.', 'billnest') . '</p>';
        echo '<table class="widefat striped" style="max-width:900px;">';
        echo '<thead><tr><th style="width:40px;">#</th><th>' . esc_html__('Product', 'billnest') . '</th><th style="width:130px;">' . esc_html__('Quantity', 'billnest') . '</th><th style="width:150px;">' . esc_html__('Unit cost', 'billnest') . '</th></tr></thead><tbody>';

        $item_count = max(self::ITEM_ROWS, count($form['items']));

        for ($i = 0; $i < $item_count; $i++) {
            $row = $form['items'][$i] ?? ['product' => '', 'qty' => '', 'cost' => ''];

            echo '<tr>';
            echo '<td>' . esc_html((string) ($i + 1)) . '</td>';
            echo '<td><input type="text" list="billnest-purchase-products" name="items[' . esc_attr((string) $i) . '][product]" value="' . esc_attr($row['product']) . '" class="regular-text" style="width:100%;" autocomplete="off"></td>';
            echo '<td><input type="number" step="0.01" min="0" name="items[' . esc_attr((string) $i) . '][qty]" value="' . esc_attr($row['qty']) . '" style="width:100%;"></td>';
            echo '<td><input type="number" step="0.01" min="0" name="items[' . esc_attr((string) $i) . '][cost]" value="' . esc_attr($row['cost']) . '" style="width:100%;"></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        echo '<h3>' . esc_html__('Payments', 'billnest') . '</h3>';
        echo '<p class="description">' . esc_html__('Whatever is not paid now stays owed to the supplier. Card and mobile payments need a reference. Leave unused rows empty.', 'billnest') . '</p>';
        echo '<table class="widefat striped" style="max-width:900px;">';
        echo '<thead><tr><th style="width:40px;">#</th><th style="width:160px;">' . esc_html__('Method', 'billnest') . '</th><th style="width:150px;">' . esc_html__('Amount', 'billnest') . '</th><th>' . esc_html__('Reference', 'billnest') . '</th></tr></thead><tbody>';

        $payment_count = max(self::PAYMENT_ROWS, count($form['payments']));

        for ($i = 0; $i < $payment_count; $i++) {
            $row = $form['payments'][$i] ?? ['method' => 'cash', 'amount' => '', 'reference' => ''];

            echo '<tr>';
            echo '<td>' . esc_html((string) ($i + 1)) . '</td>';
            echo '<td><select name="payments[' . esc_attr((string) $i) . '][method]">';
            foreach (PaymentMethods::all() as $key => $label) {
                echo '<option value="' . esc_attr($key) . '"' . selected($row['method'], $key, false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select></td>';
            echo '<td><input type="number" step="0.01" min="0" name="payments[' . esc_attr((string) $i) . '][amount]" value="' . esc_attr($row['amount']) . '" style="width:100%;"></td>';
            echo '<td><input type="text" name="payments[' . esc_attr((string) $i) . '][reference]" value="' . esc_attr($row['reference']) . '" maxlength="60" style="width:100%;"></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        echo '<table class="form-table">';
        echo '<tr><th><label for="remarks">' . esc_html__('Remarks', 'billnest') . '</label></th>';
        echo '<td><input type="text" class="regular-text" name="remarks" id="remarks" value="' . esc_attr($form['remarks']) . '"></td></tr>';
        echo '</table>';

        submit_button(__('Save Purchase', 'billnest'));
        echo '</form>';
    }
}