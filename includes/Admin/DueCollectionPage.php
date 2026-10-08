<?php

namespace BillNest\Admin;

use BillNest\DB\CustomerRepository;
use BillNest\DB\PaymentRepository;
use BillNest\Services\PaymentService;
use BillNest\Services\SettingsService;

if (! defined('ABSPATH')) {
    exit;
}

class DueCollectionPage extends AdminPage
{

    private const METHODS = [
        'cash'   => 'Cash',
        'bank'   => 'Bank Transfer',
        'mobile' => 'Mobile Banking',
    ];

    public function get_slug(): string
    {
        return 'billnest-due-collection';
    }

    public function get_title(): string
    {
        return __('Due Collection', 'billnest');
    }

    public function enqueue_assets(): void
    {
        wp_enqueue_style(
            'billnest-invoice',
            BILLNEST_PLUGIN_URL . 'assets/admin/css/invoice.css',
            [],
            BILLNEST_VERSION
        );
    }

    public function render(): void
    {
        if (! current_user_can($this->get_capability())) {
            wp_die(esc_html__('You do not have permission to access this page.', 'billnest'));
        }

        $error = $this->handle_form_submission();

        if (($_GET['action'] ?? '') === 'collect' && ! empty($_GET['id'])) {
            $this->render_collect(absint($_GET['id']), $error);
            return;
        }

        $this->render_list();
    }

    private function handle_form_submission(): ?string
    {
        if (! isset($_POST['billnest_payment_nonce'])) {
            return null;
        }

        $customer_id = absint($_POST['customer_id'] ?? 0);

        check_admin_referer('billnest_collect_payment_' . $customer_id, 'billnest_payment_nonce');

        $method = sanitize_key($_POST['method'] ?? 'cash');

        if (! isset(self::METHODS[$method])) {
            $method = 'cash';
        }

        try {
            (new PaymentService())->collect(
                $customer_id,
                floatval($_POST['amount'] ?? 0),
                $method,
                sanitize_text_field(wp_unslash($_POST['remarks'] ?? '')),
                get_current_user_id()
            );
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        } catch (\Throwable $e) {
            error_log('BillNest due collection failed: ' . $e->getMessage());

            return __('Something went wrong. The payment was not saved.', 'billnest');
        }

        wp_safe_redirect(admin_url('admin.php?page=' . $this->get_slug() . '&collected=1'));
        exit;
    }

    private function render_list(): void
    {
        $customers = (new CustomerRepository())->get_with_due();
        $total_due = (new CustomerRepository())->get_total_due();
        $recent    = (new PaymentRepository())->get_recent();
        $settings  = new SettingsService();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html($this->get_title()) . '</h1>';

        if (($_GET['collected'] ?? '') === '1') {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Payment collected.', 'billnest') . '</p></div>';
        }

        echo '<div class="billnest-cashbook-summary">';
        echo '<div class="billnest-cash-box billnest-cash-out"><span>' . esc_html__('Total Outstanding Due', 'billnest') . '</span><strong>' . esc_html($settings->format_amount($total_due)) . '</strong></div>';
        echo '</div>';

        if (empty($customers)) {
            echo '<p>' . esc_html__('No customers have any due right now.', 'billnest') . '</p>';
        } else {
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__('Customer', 'billnest') . '</th>';
            echo '<th>' . esc_html__('Phone', 'billnest') . '</th>';
            echo '<th>' . esc_html__('Current Due', 'billnest') . '</th>';
            echo '<th>' . esc_html__('Action', 'billnest') . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($customers as $customer) {
                $collect_url = admin_url('admin.php?page=' . $this->get_slug() . '&action=collect&id=' . $customer->id);

                echo '<tr>';
                echo '<td>' . esc_html($customer->name) . '</td>';
                echo '<td>' . esc_html($customer->phone) . '</td>';
                echo '<td class="billnest-cash-out-text">' . esc_html($settings->format_amount((float) $customer->current_due)) . '</td>';
                echo '<td><a class="button button-primary" href="' . esc_url($collect_url) . '">' . esc_html__('Collect', 'billnest') . '</a></td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }

        echo '<h2>' . esc_html__('Recent Collections', 'billnest') . '</h2>';

        if (empty($recent)) {
            echo '<p>' . esc_html__('No collections yet.', 'billnest') . '</p>';
        } else {
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__('Date', 'billnest') . '</th>';
            echo '<th>' . esc_html__('Customer', 'billnest') . '</th>';
            echo '<th>' . esc_html__('Amount', 'billnest') . '</th>';
            echo '<th>' . esc_html__('Method', 'billnest') . '</th>';
            echo '<th>' . esc_html__('Remarks', 'billnest') . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($recent as $payment) {
                echo '<tr>';
                echo '<td>' . esc_html($payment->payment_date) . '</td>';
                echo '<td>' . esc_html($payment->customer_name) . '</td>';
                echo '<td class="billnest-cash-in-text">' . esc_html($settings->format_amount((float) $payment->amount)) . '</td>';
                echo '<td>' . esc_html(self::METHODS[$payment->method] ?? ucfirst($payment->method)) . '</td>';
                echo '<td>' . esc_html($payment->remarks) . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }

        echo '</div>';
    }

    private function render_collect(int $customer_id, ?string $error): void
    {
        $customer = (new CustomerRepository())->get_by_id($customer_id);
        $list_url = admin_url('admin.php?page=' . $this->get_slug());
        $settings = new SettingsService();

        echo '<div class="wrap">';

        if (! $customer) {
            echo '<h1>' . esc_html__('Customer not found', 'billnest') . '</h1>';
            echo '<p><a href="' . esc_url($list_url) . '">&larr; ' . esc_html__('Back to Due Collection', 'billnest') . '</a></p>';
            echo '</div>';
            return;
        }

        $due = (float) $customer->current_due;

        echo '<p><a href="' . esc_url($list_url) . '">&larr; ' . esc_html__('Back to Due Collection', 'billnest') . '</a></p>';
        echo '<h1>' . esc_html__('Collect Payment', 'billnest') . ': ' . esc_html($customer->name) . '</h1>';

        if ($error) {
            echo '<div class="notice notice-error"><p>' . esc_html($error) . '</p></div>';
        }

        echo '<div class="billnest-cashbook-summary">';
        echo '<div class="billnest-cash-box billnest-cash-out"><span>' . esc_html__('Current Due', 'billnest') . '</span><strong>' . esc_html($settings->format_amount($due)) . '</strong></div>';
        echo '</div>';

        if ($due <= 0) {
            echo '<p>' . esc_html__('This customer has no due.', 'billnest') . '</p>';
        } else {
            echo '<form method="post">';
            wp_nonce_field('billnest_collect_payment_' . $customer->id, 'billnest_payment_nonce');
            echo '<input type="hidden" name="customer_id" value="' . esc_attr($customer->id) . '">';

            echo '<table class="form-table">';
            echo '<tr><th><label for="amount">' . esc_html__('Amount', 'billnest') . '</label></th>';
            echo '<td><input type="number" step="0.01" min="0.01" max="' . esc_attr($due) . '" name="amount" id="amount" value="' . esc_attr(number_format($due, 2, '.', '')) . '" required></td></tr>';

            echo '<tr><th><label for="method">' . esc_html__('Method', 'billnest') . '</label></th>';
            echo '<td><select name="method" id="method">';
            foreach (self::METHODS as $key => $label) {
                echo '<option value="' . esc_attr($key) . '">' . esc_html($label) . '</option>';
            }
            echo '</select></td></tr>';

            echo '<tr><th><label for="remarks">' . esc_html__('Remarks', 'billnest') . '</label></th>';
            echo '<td><input type="text" class="regular-text" name="remarks" id="remarks"></td></tr>';
            echo '</table>';

            submit_button(__('Collect Payment', 'billnest'));
            echo '</form>';
        }

        $history = (new PaymentRepository())->get_by_customer($customer_id);

        echo '<h2>' . esc_html__('Payment History', 'billnest') . '</h2>';

        if (empty($history)) {
            echo '<p>' . esc_html__('No payments from this customer yet.', 'billnest') . '</p>';
        } else {
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__('Date', 'billnest') . '</th>';
            echo '<th>' . esc_html__('Amount', 'billnest') . '</th>';
            echo '<th>' . esc_html__('Method', 'billnest') . '</th>';
            echo '<th>' . esc_html__('Remarks', 'billnest') . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($history as $payment) {
                echo '<tr>';
                echo '<td>' . esc_html($payment->payment_date) . '</td>';
                echo '<td class="billnest-cash-in-text">' . esc_html($settings->format_amount((float) $payment->amount)) . '</td>';
                echo '<td>' . esc_html(self::METHODS[$payment->method] ?? ucfirst($payment->method)) . '</td>';
                echo '<td>' . esc_html($payment->remarks) . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }

        echo '</div>';
    }
}