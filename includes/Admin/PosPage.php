<?php

namespace BillNest\Admin;

use BillNest\DB\CustomerRepository;
use BillNest\DB\ProductRepository;
use BillNest\Services\InvoiceService;

if (! defined('ABSPATH')) {
    exit;
}

class PosPage extends AdminPage
{

    public function get_slug(): string
    {
        return 'billnest-pos';
    }

    public function get_title(): string
    {
        return __('New Sale', 'billnest');
    }

    public function enqueue_assets(): void
    {
        wp_enqueue_style(
            'billnest-pos',
            BILLNEST_PLUGIN_URL . 'assets/admin/css/pos.css',
            [],
            BILLNEST_VERSION
        );
    }

    public function render(): void
    {
        if (! current_user_can($this->get_capability())) {
            wp_die(esc_html__('You do not have permission to access this page.', 'billnest'));
        }

        $message = $this->handle_form_submission();

        $customers = (new CustomerRepository())->get_all();
        $products  = (new ProductRepository())->get_all();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html($this->get_title()) . '</h1>';

        if ($message) {
            echo '<div class="notice notice-success"><p>' . esc_html($message) . '</p></div>';
        }

        $this->render_form($customers, $products);
        $this->render_script($products);

        echo '</div>';
    }

    private function render_form(array $customers, array $products): void
    {
        echo '<div class="billnest-pos-wrap">';
        echo '<div class="billnest-pos-header">' . esc_html__('Create New Invoice', 'billnest') . '</div>';
        echo '<div class="billnest-pos-body">';

        echo '<form method="post" id="billnest-pos-form">';
        wp_nonce_field('billnest_create_invoice', 'billnest_invoice_nonce');

        echo '<table class="form-table">';
        echo '<tr><th><label for="customer_id">' . esc_html__('Customer', 'billnest') . '</label></th>';
        echo '<td><select name="customer_id" id="customer_id">';
        echo '<option value="0">' . esc_html__('-- Walk-in Customer --', 'billnest') . '</option>';
        foreach ($customers as $customer) {
            echo '<option value="' . esc_attr($customer->id) . '">' . esc_html($customer->name) . '</option>';
        }
        echo '</select></td></tr>';
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

        echo '<div class="billnest-totals-box">';
        echo '<div class="billnest-row"><span>' . esc_html__('Subtotal', 'billnest') . '</span><span id="billnest-subtotal">0.00</span></div>';
        echo '<div class="billnest-row billnest-total-row"><span>' . esc_html__('Total', 'billnest') . '</span><span id="billnest-total-display">0.00</span></div>';
        echo '</div>';

        echo '<table class="form-table">';
        echo '<tr><th><label for="received_amount">' . esc_html__('Received Amount', 'billnest') . '</label></th>';
        echo '<td><input type="number" step="0.01" name="received_amount" id="received_amount" value="0" required></td></tr>';
        echo '</table>';

        submit_button(__('Save Invoice', 'billnest'));
        echo '</form>';
        echo '</div>';
        echo '</div>';
    }

    private function render_script(array $products): void
    {
        $product_data = [];
        foreach ($products as $product) {
            $product_data[] = [
                'id'    => (int) $product->id,
                'name'  => $product->name,
                'price' => (float) $product->sale_price,
            ];
        }

?>
        <script>
            (function() {
                var products = <?php echo wp_json_encode($product_data); ?>;
                var body = document.getElementById('billnest-items-body');
                var rowIndex = 0;

                function buildOptions() {
                    var html = '';
                    products.forEach(function(p) {
                        html += '<option value="' + p.id + '" data-price="' + p.price + '">' + p.name + ' — ' + p.price + '</option>';
                    });
                    return html;
                }

                function recalcRow(row) {
                    var price = parseFloat(row.querySelector('.billnest-product-select').selectedOptions[0].dataset.price);
                    var qty = parseFloat(row.querySelector('.billnest-qty-input').value) || 0;
                    var lineTotal = price * qty;
                    row.querySelector('.billnest-line-total').textContent = lineTotal.toFixed(2);
                    recalcSubtotal();
                }

                function recalcSubtotal() {
                    var total = 0;
                    body.querySelectorAll('tr').forEach(function(row) {
                        total += parseFloat(row.querySelector('.billnest-line-total').textContent) || 0;
                    });
                    document.getElementById('billnest-subtotal').textContent = total.toFixed(2);
                    document.getElementById('billnest-total-display').textContent = total.toFixed(2);
                }

                function addRow() {
                    var index = rowIndex++;
                    var row = document.createElement('tr');
                    row.innerHTML =
                        '<td><select name="items[' + index + '][product_id]" class="billnest-product-select">' + buildOptions() + '</select></td>' +
                        '<td><input type="number" step="0.01" min="0.01" value="1" name="items[' + index + '][qty]" class="billnest-qty-input"></td>' +
                        '<td class="billnest-line-total">0.00</td>' +
                        '<td><button type="button" class="button billnest-remove-row">&times;</button></td>';

                    body.appendChild(row);

                    row.querySelector('.billnest-product-select').addEventListener('change', function() {
                        recalcRow(row);
                    });
                    row.querySelector('.billnest-qty-input').addEventListener('input', function() {
                        recalcRow(row);
                    });
                    row.querySelector('.billnest-remove-row').addEventListener('click', function() {
                        row.remove();
                        recalcSubtotal();
                    });

                    recalcRow(row);
                }

                document.getElementById('billnest-add-item').addEventListener('click', addRow);

                addRow();
            })();
        </script>
<?php
    }

    private function handle_form_submission(): ?string
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

        $service = new InvoiceService();

        $invoice_id = $service->create_invoice([
            'customer_id'     => absint($_POST['customer_id'] ?? 0) ?: null,
            'discount_type'   => null,
            'discount_value'  => 0,
            'received_amount' => floatval($_POST['received_amount'] ?? 0),
            'remarks'         => '',
            'created_by'      => get_current_user_id(),
            'items'           => $items,
        ]);

        return sprintf(__('Invoice #%d created successfully.', 'billnest'), $invoice_id);
    }
}
