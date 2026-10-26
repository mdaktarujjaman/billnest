<?php

namespace BillNest\Admin;

use BillNest\DB\DiscountRuleRepository;
use BillNest\Services\PaymentMethods;

if (! defined('ABSPATH')) {
    exit;
}

class DiscountRulesPage extends AdminPage
{
    private DiscountRuleRepository $rules;
    private ?string $error = null;
    private array $form = [];

    public function __construct()
    {
        $this->rules = new DiscountRuleRepository();
        add_action('admin_init', [$this, 'handle_request']);
    }

    public function get_slug(): string
    {
        return 'billnest-discount-rules';
    }

    public function get_title(): string
    {
        return __('Discount Rules', 'billnest');
    }

    public function handle_request(): void
    {
        if (sanitize_key(wp_unslash($_GET['page'] ?? '')) !== $this->get_slug()) {
            return;
        }

        if (! current_user_can($this->get_capability())) {
            return;
        }

        if (sanitize_key(wp_unslash($_GET['action'] ?? '')) === 'deactivate') {
            $this->handle_deactivate();
        }

        if (isset($_POST['billnest_rule_nonce'])) {
            $this->handle_save();
        }
    }

    public function render(): void
    {
        if (! current_user_can($this->get_capability())) {
            wp_die(esc_html__('You do not have permission to access this page.', 'billnest'));
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html($this->get_title()) . '</h1>';

        $this->render_notices();
        $this->render_form($this->current_form());
        $this->render_table();

        echo '</div>';
    }

    private function handle_deactivate(): void
    {
        $id = absint($_GET['id'] ?? 0);

        check_admin_referer('billnest_deactivate_rule_' . $id);

        if ($id > 0) {
            $this->rules->deactivate($id);
        }

        $this->redirect('deactivated');
    }

    private function handle_save(): void
    {
        check_admin_referer('billnest_save_rule', 'billnest_rule_nonce');

        $id = absint($_POST['rule_id'] ?? 0);
        $data = $this->collect_input();
        $this->form = $data + ['id' => $id];

        try {
            $this->validate_method($data['method']);

            if ($id > 0) {
                if (! $this->rules->find($id)) {
                    throw new \InvalidArgumentException(__('Rule not found.', 'billnest'));
                }
                $saved = $this->rules->update($id, $data);
            } else {
                $saved = $this->rules->create($data);
            }

            if ($saved === false) {
                throw new \RuntimeException('Rule save returned false.');
            }
        } catch (\InvalidArgumentException $e) {
            $this->error = $e->getMessage();
            return;
        } catch (\Throwable $e) {
            error_log('BillNest discount rule save failed: ' . $e->getMessage());
            $this->error = __('Could not save the rule. Please try again.', 'billnest');
            return;
        }

        $this->redirect('saved');
    }

    private function collect_input(): array
    {
        return [
            'method'       => sanitize_key(wp_unslash($_POST['method'] ?? '')),
            'type'         => sanitize_key(wp_unslash($_POST['type'] ?? '')),
            'value'        => sanitize_text_field(wp_unslash($_POST['value'] ?? '')),
            'max_discount' => sanitize_text_field(wp_unslash($_POST['max_discount'] ?? '')),
            'min_order'    => sanitize_text_field(wp_unslash($_POST['min_order'] ?? '')),
            'start_date'   => sanitize_text_field(wp_unslash($_POST['start_date'] ?? '')),
            'end_date'     => sanitize_text_field(wp_unslash($_POST['end_date'] ?? '')),
            'status'       => sanitize_key(wp_unslash($_POST['status'] ?? 'active')),
        ];
    }

    private function validate_method(string $method): void
    {
        if (! PaymentMethods::is_valid($method) || $method === 'cash') {
            throw new \InvalidArgumentException(__('Choose an online payment method.', 'billnest'));
        }
    }

    private function redirect(string $message): void
    {
        wp_safe_redirect(add_query_arg('message', $message, admin_url('admin.php?page=' . $this->get_slug())));
        exit;
    }

    private function current_form(): array
    {
        if (! empty($this->form)) {
            return $this->form;
        }

        $action = sanitize_key(wp_unslash($_GET['action'] ?? ''));
        $id = absint($_GET['id'] ?? 0);

        if ($action === 'edit' && $id > 0) {
            $rule = $this->rules->find($id);
            if ($rule) {
                return $rule;
            }
        }

        return [
            'id'           => 0,
            'method'       => 'bkash',
            'type'         => 'percent',
            'value'        => '',
            'max_discount' => '',
            'min_order'    => '0',
            'start_date'   => wp_date('Y-m-d'),
            'end_date'     => '',
            'status'       => 'active',
        ];
    }

    private function render_notices(): void
    {
        if ($this->error !== null) {
            echo '<div class="notice notice-error"><p>' . esc_html($this->error) . '</p></div>';
        }

        $messages = [
            'saved'       => __('Rule saved.', 'billnest'),
            'deactivated' => __('Rule deactivated.', 'billnest'),
        ];
        $key = sanitize_key(wp_unslash($_GET['message'] ?? ''));

        if (isset($messages[$key])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($messages[$key]) . '</p></div>';
        }
    }

    private function render_form(array $values): void
    {
        $id = (int) ($values['id'] ?? 0);
        $heading = $id > 0 ? __('Edit Rule', 'billnest') : __('Add Rule', 'billnest');

        echo '<h2>' . esc_html($heading) . '</h2>';
        echo '<p>' . esc_html__('A rule applies only when the whole bill is paid with this one method.', 'billnest') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=' . $this->get_slug())) . '">';
        wp_nonce_field('billnest_save_rule', 'billnest_rule_nonce');
        echo '<input type="hidden" name="rule_id" value="' . esc_attr((string) $id) . '">';

        echo '<table class="form-table">';

        echo '<tr><th><label for="method">' . esc_html__('Payment method', 'billnest') . '</label></th><td>';
        echo '<select name="method" id="method">';
        foreach (PaymentMethods::all() as $key => $label) {
            if ($key === 'cash') {
                continue;
            }
            echo '<option value="' . esc_attr($key) . '"' . selected($values['method'] ?? '', $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></td></tr>';

        echo '<tr><th><label for="type">' . esc_html__('Discount type', 'billnest') . '</label></th><td>';
        echo '<select name="type" id="type">';
        echo '<option value="percent"' . selected($values['type'] ?? '', 'percent', false) . '>' . esc_html__('Percent', 'billnest') . '</option>';
        echo '<option value="flat"' . selected($values['type'] ?? '', 'flat', false) . '>' . esc_html__('Flat amount', 'billnest') . '</option>';
        echo '</select></td></tr>';

        echo '<tr><th><label for="value">' . esc_html__('Value', 'billnest') . '</label></th>';
        echo '<td><input type="number" step="0.01" min="0.01" name="value" id="value" value="' . esc_attr((string) ($values['value'] ?? '')) . '" required></td></tr>';

        echo '<tr><th><label for="max_discount">' . esc_html__('Maximum discount', 'billnest') . '</label></th>';
        echo '<td><input type="number" step="0.01" min="0.01" name="max_discount" id="max_discount" value="' . esc_attr((string) ($values['max_discount'] ?? '')) . '">';
        echo ' <span class="description">' . esc_html__('Leave empty for no limit.', 'billnest') . '</span></td></tr>';

        echo '<tr><th><label for="min_order">' . esc_html__('Minimum order', 'billnest') . '</label></th>';
        echo '<td><input type="number" step="0.01" min="0" name="min_order" id="min_order" value="' . esc_attr((string) ($values['min_order'] ?? '0')) . '"></td></tr>';

        echo '<tr><th><label for="start_date">' . esc_html__('Start date', 'billnest') . '</label></th>';
        echo '<td><input type="date" name="start_date" id="start_date" value="' . esc_attr((string) ($values['start_date'] ?? '')) . '" required></td></tr>';

        echo '<tr><th><label for="end_date">' . esc_html__('End date', 'billnest') . '</label></th>';
        echo '<td><input type="date" name="end_date" id="end_date" value="' . esc_attr((string) ($values['end_date'] ?? '')) . '" required></td></tr>';

        echo '<tr><th><label for="status">' . esc_html__('Status', 'billnest') . '</label></th><td>';
        echo '<select name="status" id="status">';
        echo '<option value="active"' . selected($values['status'] ?? '', 'active', false) . '>' . esc_html__('Active', 'billnest') . '</option>';
        echo '<option value="inactive"' . selected($values['status'] ?? '', 'inactive', false) . '>' . esc_html__('Inactive', 'billnest') . '</option>';
        echo '</select></td></tr>';

        echo '</table>';

        submit_button($id > 0 ? __('Update Rule', 'billnest') : __('Add Rule', 'billnest'));

        if ($id > 0) {
            echo '<p><a href="' . esc_url(admin_url('admin.php?page=' . $this->get_slug())) . '">' . esc_html__('Cancel editing', 'billnest') . '</a></p>';
        }

        echo '</form>';
    }

    private function render_table(): void
    {
        $rules = $this->rules->all();

        echo '<h2>' . esc_html__('All Rules', 'billnest') . '</h2>';

        if (empty($rules)) {
            echo '<p>' . esc_html__('No discount rules yet.', 'billnest') . '</p>';
            return;
        }

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Method', 'billnest') . '</th>';
        echo '<th>' . esc_html__('Discount', 'billnest') . '</th>';
        echo '<th>' . esc_html__('Max', 'billnest') . '</th>';
        echo '<th>' . esc_html__('Min order', 'billnest') . '</th>';
        echo '<th>' . esc_html__('Valid', 'billnest') . '</th>';
        echo '<th>' . esc_html__('Status', 'billnest') . '</th>';
        echo '<th>' . esc_html__('Action', 'billnest') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($rules as $rule) {
            $id = (int) $rule['id'];
            $value = number_format_i18n((float) $rule['value'], 2);
            $discount = $rule['type'] === 'percent' ? $value . '%' : $value;
            $max = $rule['max_discount'] === null ? '—' : number_format_i18n((float) $rule['max_discount'], 2);

            $edit_url = admin_url('admin.php?page=' . $this->get_slug() . '&action=edit&id=' . $id);
            $deactivate_url = wp_nonce_url(
                admin_url('admin.php?page=' . $this->get_slug() . '&action=deactivate&id=' . $id),
                'billnest_deactivate_rule_' . $id
            );

            echo '<tr>';
            echo '<td>' . esc_html(PaymentMethods::label($rule['method'])) . '</td>';
            echo '<td>' . esc_html($discount) . '</td>';
            echo '<td>' . esc_html($max) . '</td>';
            echo '<td>' . esc_html(number_format_i18n((float) $rule['min_order'], 2)) . '</td>';
            echo '<td>' . esc_html($rule['start_date'] . ' → ' . $rule['end_date']) . '</td>';
            echo '<td>' . esc_html(ucfirst($rule['status'])) . '</td>';
            echo '<td><a href="' . esc_url($edit_url) . '">' . esc_html__('Edit', 'billnest') . '</a>';

            if ($rule['status'] === 'active') {
                echo ' | <a href="' . esc_url($deactivate_url) . '" onclick="return confirm(\'' . esc_js(__('Deactivate this rule?', 'billnest')) . '\');">' . esc_html__('Deactivate', 'billnest') . '</a>';
            }

            echo '</td></tr>';
        }

        echo '</tbody></table>';
    }
}