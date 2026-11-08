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
        $file    = dirname(__DIR__, 2) . '/assets/admin/css/pos.css';
        $version = file_exists($file) ? (string) filemtime($file) : BILLNEST_VERSION;

        wp_enqueue_style(
            'billnest-pos',
            BILLNEST_PLUGIN_URL . 'assets/admin/css/pos.css',
            [],
            $version
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

        $this->render_form($settings);
        $this->render_script($products, $customers, $settings);

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

    private function render_script(array $products, array $customers, SettingsService $settings): void
    {
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

        $rule_data = $this->active_rule_data();

?>
        <script>
            (function() {
                var products = <?php echo wp_json_encode($product_data); ?>;
                var customers = <?php echo wp_json_encode($customer_data); ?>;
                var methods = <?php echo wp_json_encode(PaymentMethods::all()); ?>;
                var rules = <?php echo wp_json_encode($rule_data); ?>;
                var allowNegative = <?php echo wp_json_encode($settings->allow_negative_stock()); ?>;
                var currencySymbol = <?php echo wp_json_encode($settings->get('currency_symbol')); ?>;
                var currencyBefore = <?php echo wp_json_encode($settings->get('currency_position') !== 'after'); ?>;
                var body = document.getElementById('billnest-items-body');
                var payBody = document.getElementById('billnest-payments-body');
                var discountType = document.getElementById('discount_type');
                var discountValue = document.getElementById('discount_value');
                var submitBtn = document.querySelector('#billnest-pos-form input[type="submit"]');
                var productsById = {};
                var subtotal = 0;
                var rowIndex = 0;
                var payIndex = 0;
                var maxResults = 10;
                var customerPending = false;

                products.forEach(function(p) {
                    productsById[p.id] = p;
                });

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

                function money(number) {
                    var text = number.toFixed(2);

                    return currencyBefore ? currencySymbol + text : text + currencySymbol;
                }

                function fmtQty(number) {
                    return String(parseFloat(number.toFixed(4)));
                }

                function stockLabel(product) {
                    if (!allowNegative && product.stock <= 0) {
                        return 'Out of stock';
                    }

                    return 'Stock: ' + fmtQty(product.stock);
                }

                function searchProducts(query) {
                    var tokens = query.toLowerCase().split(/\s+/).filter(Boolean);
                    var joined = tokens.join(' ');
                    var found = [];

                    products.forEach(function(p) {
                        var code = p.code.toLowerCase();
                        var name = p.name.toLowerCase();
                        var haystack = code + ' ' + name + ' ' + p.model.toLowerCase();

                        for (var i = 0; i < tokens.length; i++) {
                            if (haystack.indexOf(tokens[i]) === -1) {
                                return;
                            }
                        }

                        var score = 3;

                        if (tokens.length) {
                            if (code === joined) {
                                score = 0;
                            } else if (code.indexOf(tokens[0]) === 0) {
                                score = 1;
                            } else if (name.indexOf(tokens[0]) === 0) {
                                score = 2;
                            }
                        }

                        found.push({
                            product: p,
                            score: score
                        });
                    });

                    found.sort(function(a, b) {
                        return a.score - b.score || a.product.name.localeCompare(b.product.name);
                    });

                    return found.slice(0, maxResults).map(function(item) {
                        return item.product;
                    });
                }

                function searchCustomers(query) {
                    var tokens = query.toLowerCase().split(/\s+/).filter(Boolean);
                    var found = [];

                    customers.forEach(function(c) {
                        var name = c.name.toLowerCase();
                        var phone = c.phone.toLowerCase();
                        var haystack = name + ' ' + phone + ' ' + c.email.toLowerCase();

                        for (var i = 0; i < tokens.length; i++) {
                            if (haystack.indexOf(tokens[i]) === -1) {
                                return;
                            }
                        }

                        var score = 2;

                        if (tokens.length) {
                            if (name.indexOf(tokens[0]) === 0) {
                                score = 0;
                            } else if (phone.indexOf(tokens[0]) === 0) {
                                score = 1;
                            }
                        }

                        found.push({
                            customer: c,
                            score: score
                        });
                    });

                    found.sort(function(a, b) {
                        return a.score - b.score || a.customer.name.localeCompare(b.customer.name);
                    });

                    return found.slice(0, maxResults).map(function(item) {
                        return item.customer;
                    });
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

                function hasSelectedProduct() {
                    var found = false;

                    body.querySelectorAll('.billnest-product-id').forEach(function(field) {
                        if (field.value !== '') {
                            found = true;
                        }
                    });

                    return found;
                }

                function refreshStock() {
                    var totals = {};
                    var rows = body.querySelectorAll('tr');

                    rows.forEach(function(row) {
                        var id = row.querySelector('.billnest-product-id').value;

                        if (id !== '') {
                            totals[id] = (totals[id] || 0) + (parseFloat(row.querySelector('.billnest-qty-input').value) || 0);
                        }
                    });

                    rows.forEach(function(row) {
                        var id = row.querySelector('.billnest-product-id').value;
                        var note = row.querySelector('.billnest-stock-note');
                        var product = productsById[id];

                        if (id === '' || !product) {
                            note.hidden = true;
                            note.className = 'billnest-stock-note';
                            note.textContent = '';
                            return;
                        }

                        var requested = totals[id];

                        note.hidden = false;

                        if (!allowNegative && requested - product.stock > 0.0001) {
                            note.className = 'billnest-stock-note is-warn';
                            note.textContent = product.stock <= 0 ?
                                'Out of stock, the sale will be rejected' :
                                'Only ' + fmtQty(product.stock) + ' in stock, ' + fmtQty(requested) + ' requested';
                        } else {
                            note.className = 'billnest-stock-note';
                            note.textContent = (allowNegative ? 'Stock: ' : 'Available: ') + fmtQty(product.stock);
                        }
                    });
                }

                function updateTotals() {
                    refreshStock();

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
                    submitBtn.disabled = over || !hasSelectedProduct() || customerPending;

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
                    var price = parseFloat(row.dataset.price) || 0;
                    var qty = parseFloat(row.querySelector('.billnest-qty-input').value) || 0;
                    var lineTotal = price * qty;
                    row.querySelector('.billnest-line-total-value').textContent = lineTotal.toFixed(2);
                    recalcSubtotal();
                }

                function wireCustomerSearch() {
                    var input = document.getElementById('customer_search');
                    var idField = document.getElementById('customer_id');
                    var list = document.getElementById('billnest-customer-results');
                    var note = document.getElementById('billnest-customer-note');
                    var clearBtn = document.getElementById('billnest-customer-clear');
                    var shown = [];
                    var active = -1;

                    function closeList() {
                        list.hidden = true;
                        active = -1;
                    }

                    function highlight() {
                        list.querySelectorAll('li[data-index]').forEach(function(li) {
                            var isActive = Number(li.dataset.index) === active;
                            li.classList.toggle('is-active', isActive);

                            if (isActive) {
                                li.scrollIntoView({
                                    block: 'nearest'
                                });
                            }
                        });
                    }

                    function showNote(text, warn) {
                        note.hidden = text === '';
                        note.textContent = text;
                        note.className = 'billnest-customer-note' + (warn ? ' is-warn' : '');
                    }

                    function choose(customer) {
                        idField.value = customer.id;
                        input.value = customer.name + (customer.phone ? ' (' + customer.phone + ')' : '');
                        input.classList.add('is-selected');
                        clearBtn.hidden = false;
                        customerPending = false;
                        closeList();

                        if (customer.due > 0) {
                            showNote('Current due: ' + money(customer.due), true);
                        } else {
                            showNote('', false);
                        }

                        updateTotals();
                    }

                    function renderList() {
                        shown = searchCustomers(input.value);
                        list.innerHTML = '';

                        if (!shown.length) {
                            var empty = document.createElement('li');
                            empty.className = 'billnest-no-result';
                            empty.textContent = 'No customers found';
                            list.appendChild(empty);
                        }

                        shown.forEach(function(customer, i) {
                            var li = document.createElement('li');
                            var title = document.createElement('span');
                            var meta = document.createElement('span');
                            var details = [customer.phone, customer.email].filter(Boolean).join(' · ');

                            li.dataset.index = i;
                            title.className = 'billnest-result-name';
                            title.textContent = customer.name;
                            meta.className = 'billnest-result-meta';
                            meta.textContent = details;

                            if (customer.due > 0) {
                                var due = document.createElement('span');
                                due.className = 'billnest-result-stock is-low';
                                due.textContent = (details ? ' · ' : '') + 'Due: ' + money(customer.due);
                                meta.appendChild(due);
                            }

                            li.appendChild(title);
                            li.appendChild(meta);
                            li.addEventListener('mousedown', function(event) {
                                event.preventDefault();
                                choose(customer);
                            });

                            list.appendChild(li);
                        });

                        active = shown.length ? 0 : -1;
                        list.hidden = false;
                        highlight();
                    }

                    input.addEventListener('input', function() {
                        idField.value = '0';
                        input.classList.remove('is-selected');
                        clearBtn.hidden = input.value === '';
                        customerPending = input.value.trim() !== '';

                        if (customerPending) {
                            showNote('Pick a customer from the list, or clear the box for a walk-in sale', true);
                        } else {
                            showNote('', false);
                        }

                        updateTotals();
                        renderList();
                    });

                    input.addEventListener('focus', function() {
                        if (idField.value === '0') {
                            renderList();
                        } else {
                            input.select();
                        }
                    });

                    input.addEventListener('blur', closeList);

                    input.addEventListener('keydown', function(event) {
                        if (event.key === 'ArrowDown') {
                            event.preventDefault();

                            if (list.hidden) {
                                renderList();
                            } else if (shown.length) {
                                active = Math.min(active + 1, shown.length - 1);
                                highlight();
                            }
                        } else if (event.key === 'ArrowUp') {
                            event.preventDefault();

                            if (!list.hidden && shown.length) {
                                active = Math.max(active - 1, 0);
                                highlight();
                            }
                        } else if (event.key === 'Enter') {
                            event.preventDefault();

                            if (!list.hidden && active >= 0 && shown[active]) {
                                choose(shown[active]);
                            }
                        } else if (event.key === 'Escape') {
                            closeList();
                        }
                    });

                    clearBtn.addEventListener('click', function() {
                        idField.value = '0';
                        input.value = '';
                        input.classList.remove('is-selected');
                        clearBtn.hidden = true;
                        customerPending = false;
                        showNote('', false);
                        updateTotals();
                        input.focus();
                    });
                }

                function wireProductSearch(row) {
                    var input = row.querySelector('.billnest-product-input');
                    var idField = row.querySelector('.billnest-product-id');
                    var list = row.querySelector('.billnest-product-results');
                    var qty = row.querySelector('.billnest-qty-input');
                    var shown = [];
                    var active = -1;

                    function closeList() {
                        list.hidden = true;
                        active = -1;
                    }

                    function highlight() {
                        list.querySelectorAll('li[data-index]').forEach(function(li) {
                            var isActive = Number(li.dataset.index) === active;
                            li.classList.toggle('is-active', isActive);

                            if (isActive) {
                                li.scrollIntoView({
                                    block: 'nearest'
                                });
                            }
                        });
                    }

                    function choose(product) {
                        idField.value = product.id;
                        row.dataset.price = product.price;
                        input.value = product.name + ' (' + product.code + ')';
                        input.classList.add('is-selected');
                        closeList();
                        recalcRow(row);
                        qty.focus();
                        qty.select();
                    }

                    function renderList() {
                        shown = searchProducts(input.value);
                        list.innerHTML = '';

                        if (!shown.length) {
                            var empty = document.createElement('li');
                            empty.className = 'billnest-no-result';
                            empty.textContent = 'No products found';
                            list.appendChild(empty);
                        }

                        shown.forEach(function(product, i) {
                            var li = document.createElement('li');
                            var title = document.createElement('span');
                            var meta = document.createElement('span');
                            var stock = document.createElement('span');
                            var details = [product.code, product.model].filter(Boolean).join(' · ');

                            li.dataset.index = i;
                            title.className = 'billnest-result-name';
                            title.textContent = product.name + ' — ' + money(product.price);
                            meta.className = 'billnest-result-meta';
                            meta.textContent = details;
                            stock.className = 'billnest-result-stock' + (product.stock <= 0 ? ' is-low' : '');
                            stock.textContent = (details ? ' · ' : '') + stockLabel(product);
                            meta.appendChild(stock);

                            li.appendChild(title);
                            li.appendChild(meta);
                            li.addEventListener('mousedown', function(event) {
                                event.preventDefault();
                                choose(product);
                            });

                            list.appendChild(li);
                        });

                        active = shown.length ? 0 : -1;
                        list.hidden = false;
                        highlight();
                    }

                    input.addEventListener('input', function() {
                        idField.value = '';
                        row.dataset.price = '0';
                        input.classList.remove('is-selected');
                        recalcRow(row);
                        renderList();
                    });

                    input.addEventListener('focus', function() {
                        if (idField.value === '') {
                            renderList();
                        } else {
                            input.select();
                        }
                    });

                    input.addEventListener('blur', closeList);

                    input.addEventListener('keydown', function(event) {
                        if (event.key === 'ArrowDown') {
                            event.preventDefault();

                            if (list.hidden) {
                                renderList();
                            } else if (shown.length) {
                                active = Math.min(active + 1, shown.length - 1);
                                highlight();
                            }
                        } else if (event.key === 'ArrowUp') {
                            event.preventDefault();

                            if (!list.hidden && shown.length) {
                                active = Math.max(active - 1, 0);
                                highlight();
                            }
                        } else if (event.key === 'Enter') {
                            event.preventDefault();

                            if (!list.hidden && active >= 0 && shown[active]) {
                                choose(shown[active]);
                            }
                        } else if (event.key === 'Escape') {
                            closeList();
                        }
                    });
                }

                function addRow(focusSearch) {
                    var index = rowIndex++;
                    var row = document.createElement('tr');
                    row.dataset.price = '0';
                    row.innerHTML =
                        '<td><div class="billnest-product-search">' +
                        '<input type="text" class="billnest-product-input" placeholder="Search code, name or model" autocomplete="off">' +
                        '<input type="hidden" name="items[' + index + '][product_id]" class="billnest-product-id" value="">' +
                        '<div class="billnest-stock-note" hidden></div>' +
                        '<ul class="billnest-product-results" hidden></ul>' +
                        '</div></td>' +
                        '<td><input type="number" step="0.01" min="0.01" value="1" name="items[' + index + '][qty]" class="billnest-qty-input"></td>' +
                        '<td class="billnest-line-total">' + (currencyBefore ? currencySymbol : '') + '<span class="billnest-line-total-value">0.00</span>' + (currencyBefore ? '' : currencySymbol) + '</td>' +
                        '<td><button type="button" class="button billnest-remove-row">&times;</button></td>';

                    body.appendChild(row);

                    wireProductSearch(row);

                    row.querySelector('.billnest-qty-input').addEventListener('input', function() {
                        recalcRow(row);
                    });
                    row.querySelector('.billnest-remove-row').addEventListener('click', function() {
                        row.remove();
                        recalcSubtotal();
                    });

                    recalcRow(row);

                    if (focusSearch === true) {
                        row.querySelector('.billnest-product-input').focus();
                    }
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
                document.getElementById('billnest-add-item').addEventListener('click', function() {
                    addRow(true);
                });
                document.getElementById('billnest-add-payment').addEventListener('click', addPayment);

                wireCustomerSearch();
                addRow(false);
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