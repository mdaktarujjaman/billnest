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

        $result = $this->handle_form_submission();

        $customers = (new CustomerRepository())->get_all();
        $products  = (new ProductRepository())->get_all();
        $settings  = new SettingsService();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html($this->get_title()) . '</h1>';

        if ($result) {
            $class = $result['type'] === 'success' ? 'notice-success' : 'notice-error';
            echo '<div class="notice ' . esc_attr($class) . '"><p>' . esc_html($result['message']) . '</p></div>';
        }

        $this->render_form($customers, $products, $settings);
        $this->render_script($products, $settings);

        echo '</div>';
    }

    private function render_form(array $customers, array $products, SettingsService $settings): void
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

    private function render_script(array $products, SettingsService $settings): void
    {
        $product_data = [];
        foreach ($products as $product) {
            $product_data[] = [
                'id'    => (int) $product->id,
                'name'  => $product->name,
                'price' => (float) $product->sale_price,
            ];
        }

        $rule_data = $this->active_rule_data();

?>
        <script>
            (function() {
                var products = <?php echo wp_json_encode($product_data); ?>;
                var methods = <?php echo wp_json_encode(PaymentMethods::all()); ?>;
                var rules = <?php echo wp_json_encode($rule_data); ?>;
                var currencySymbol = <?php echo wp_json_encode($settings->get('currency_symbol')); ?>;
                var currencyBefore = <?php echo wp_json_encode($settings->get('currency_position') !== 'after'); ?>;
                var body = document.getElementById('billnest-items-body');
                var payBody = document.getElementById('billnest-payments-body');
                var discountType = document.getElementById('discount_type');
                var discountValue = document.getElementById('discount_value');
                var submitBtn = document.querySelector('#billnest-pos-form input[type="submit"]');
                var subtotal = 0;
                var rowIndex = 0;
                var payIndex = 0;

                function buildOptions() {
                    var html = '';
                    products.forEach(function(p) {
                        html += '<option value="' + p.id + '" data-price="' + p.price + '">' + p.name + ' — ' + p.price + '</option>';
                    });
                    return html;
                }

                function methodOptions() {
                    var html = '';
                    Object.keys(methods).forEach(function(key) {
                        html += '<option value="' + key + '">' + methods[key] + '</option>';
                    });
                    return html;
                }

                function round2(number) {
                    return Math.round(number * 100) / 100;
                }

                function calcDiscount() {
                    var type = discountType.value;
                    var value = Math.max(0, parseFloat(discountValue.value) || 0);

                    if (type === 'percent') {
                        value = Math.min(value, 100);
                        return round2(subtotal * (value / 100));
                    }

                    if (type === 'flat') {
                        return Math.min(value, subtotal);
                    }

                    return 0;
                }

                function readLines() {
                    var lines = [];

                    payBody.querySelectorAll('tr').forEach(function(row) {
                        lines.push({
                            method: row.querySelector('.billnest-pay-method').value,
                            amount: Math.max(0, parseFloat(row.querySelector('.billnest-pay-amount').value) || 0)
                        });
                    });

                    return lines;
                }

                function findRule(lines) {
                    if (subtotal <= 0) {
                        return null;
                    }

                    var used = {};
                    var count = 0;
                    var paid = 0;

                    lines.forEach(function(line) {
                        if (line.amount > 0) {
                            if (!used[line.method]) {
                                used[line.method] = true;
                                count++;
                            }
                            paid += line.amount;
                        }
                    });

                    if (count !== 1) {
                        return null;
                    }

                    var method = Object.keys(used)[0];

                    if (method === 'cash') {
                        return null;
                    }

                    var best = null;

                    rules.forEach(function(rule) {
                        if (rule.method !== method || subtotal < rule.min_order) {
                            return;
                        }

                        var amount = rule.type === 'percent' ? subtotal * rule.value / 100 : rule.value;

                        if (rule.max_discount !== null) {
                            amount = Math.min(amount, rule.max_discount);
                        }

                        amount = round2(Math.min(amount, subtotal));

                        if (amount > 0 && (best === null || amount > best.amount)) {
                            best = {
                                amount: amount
                            };
                        }
                    });

                    if (best === null) {
                        return null;
                    }

                    if (paid + 0.005 < round2(subtotal - best.amount)) {
                        return null;
                    }

                    return best;
                }

                function updateTotals() {
                    var lines = readLines();
                    var manual = calcDiscount();
                    var rule = manual > 0 ? null : findRule(lines);
                    var discount = rule ? rule.amount : manual;
                    var total = round2(subtotal - discount);
                    var cash = 0;
                    var nonCash = 0;

                    lines.forEach(function(line) {
                        if (line.method === 'cash') {
                            cash += line.amount;
                        } else {
                            nonCash += line.amount;
                        }
                    });

                    var limit = rule ? subtotal : total;
                    var over = nonCash > limit + 0.005;
                    var nonCashKept = rule ? Math.min(nonCash, total) : nonCash;
                    var cashKept = Math.min(cash, Math.max(0, total - nonCashKept));
                    var paid = over ? nonCash : cashKept + nonCashKept;
                    var shownPaid = over ? nonCash : cash + nonCashKept;
                    var due = round2(total - paid);
                    var change = round2(cash - cashKept);

                    var balanceRow = document.getElementById('billnest-balance-row');
                    var balanceLabel = document.getElementById('billnest-balance-label');
                    var balanceAmount = document.getElementById('billnest-balance-amount');

                    document.getElementById('billnest-subtotal').textContent = subtotal.toFixed(2);
                    document.getElementById('billnest-discount-label').textContent = rule ? 'Online payment discount' : 'Discount';
                    document.getElementById('billnest-discount-display').textContent = discount.toFixed(2);
                    document.getElementById('billnest-total-display').textContent = total.toFixed(2);
                    document.getElementById('billnest-paid-display').textContent = shownPaid.toFixed(2);
                    document.getElementById('billnest-pay-warning').style.display = over ? '' : 'none';
                    submitBtn.disabled = over;

                    if (over) {
                        balanceRow.className = 'billnest-row billnest-balance-row billnest-due';
                        balanceLabel.textContent = 'Online payment exceeds total by';
                        balanceAmount.textContent = round2(nonCash - limit).toFixed(2);
                    } else if (due > 0) {
                        balanceRow.className = 'billnest-row billnest-balance-row billnest-due';
                        balanceLabel.textContent = 'Due Amount';
                        balanceAmount.textContent = due.toFixed(2);
                    } else {
                        balanceRow.className = 'billnest-row billnest-balance-row billnest-change';
                        balanceLabel.textContent = 'Change to Return';
                        balanceAmount.textContent = change.toFixed(2);
                    }
                }

                function updatePayments() {
                    updateTotals();
                }

                function recalcSubtotal() {
                    var sum = 0;
                    body.querySelectorAll('tr').forEach(function(row) {
                        sum += parseFloat(row.querySelector('.billnest-line-total-value').textContent) || 0;
                    });
                    subtotal = sum;
                    updateTotals();
                }

                function recalcRow(row) {
                    var price = parseFloat(row.querySelector('.billnest-product-select').selectedOptions[0].dataset.price);
                    var qty = parseFloat(row.querySelector('.billnest-qty-input').value) || 0;
                    var lineTotal = price * qty;
                    row.querySelector('.billnest-line-total-value').textContent = lineTotal.toFixed(2);
                    recalcSubtotal();
                }

                function addRow() {
                    var index = rowIndex++;
                    var row = document.createElement('tr');
                    row.innerHTML =
                        '<td><select name="items[' + index + '][product_id]" class="billnest-product-select">' + buildOptions() + '</select></td>' +
                        '<td><input type="number" step="0.01" min="0.01" value="1" name="items[' + index + '][qty]" class="billnest-qty-input"></td>' +
                        '<td class="billnest-line-total">' + (currencyBefore ? currencySymbol : '') + '<span class="billnest-line-total-value">0.00</span>' + (currencyBefore ? '' : currencySymbol) + '</td>' +
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

                function syncReference(row) {
                    var isCash = row.querySelector('.billnest-pay-method').value === 'cash';
                    var reference = row.querySelector('.billnest-pay-reference');

                    reference.disabled = isCash;
                    reference.required = !isCash;

                    if (isCash) {
                        reference.value = '';
                    }
                }

                function addPayment() {
                    var index = payIndex++;
                    var row = document.createElement('tr');
                    row.innerHTML =
                        '<td><select name="payments[' + index + '][method]" class="billnest-pay-method">' + methodOptions() + '</select></td>' +
                        '<td><input type="number" step="0.01" min="0" value="0" name="payments[' + index + '][amount]" class="billnest-pay-amount"></td>' +
                        '<td><input type="text" maxlength="100" name="payments[' + index + '][reference]" class="billnest-pay-reference"></td>' +
                        '<td><button type="button" class="button billnest-remove-pay">&times;</button></td>';

                    payBody.appendChild(row);

                    row.querySelector('.billnest-pay-method').addEventListener('change', function() {
                        syncReference(row);
                        updatePayments();
                    });
                    row.querySelector('.billnest-pay-amount').addEventListener('input', updatePayments);
                    row.querySelector('.billnest-remove-pay').addEventListener('click', function() {
                        row.remove();
                        updatePayments();
                    });

                    syncReference(row);
                    updatePayments();
                }

                discountType.addEventListener('change', function() {
                    var hasDiscount = discountType.value !== '';
                    discountValue.disabled = !hasDiscount;
                    if (!hasDiscount) {
                        discountValue.value = 0;
                    }
                    updateTotals();
                });
                discountValue.addEventListener('input', updateTotals);
                document.getElementById('billnest-add-item').addEventListener('click', addRow);
                document.getElementById('billnest-add-payment').addEventListener('click', addPayment);

                addRow();
                addPayment();
            })();
        </script>
<?php
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