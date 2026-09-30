<?php

namespace BillNest\Admin;

use BillNest\DB\CashbookRepository;
use BillNest\Services\SettingsService;

if (! defined('ABSPATH')) {
    exit;
}

class CashbookPage extends AdminPage
{

    public function get_slug(): string
    {
        return 'billnest-cashbook';
    }

    public function get_title(): string
    {
        return __('Cashbook', 'billnest');
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

        $this->handle_form_submission();

        $repository = new CashbookRepository();
        $entries    = $repository->get_all();
        $balance    = $repository->get_balance();
        $settings   = new SettingsService();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html($this->get_title()) . '</h1>';

        echo '<div class="billnest-cashbook-summary">';
        echo '<div class="billnest-cash-box billnest-cash-in"><span>' . esc_html__('Total In', 'billnest') . '</span><strong>' . esc_html($settings->format_amount($balance['in'])) . '</strong></div>';
        echo '<div class="billnest-cash-box billnest-cash-out"><span>' . esc_html__('Total Out', 'billnest') . '</span><strong>' . esc_html($settings->format_amount($balance['out'])) . '</strong></div>';
        echo '<div class="billnest-cash-box billnest-cash-balance"><span>' . esc_html__('Balance', 'billnest') . '</span><strong>' . esc_html($settings->format_amount($balance['balance'])) . '</strong></div>';
        echo '</div>';

        $this->render_manual_form();

        if (empty($entries)) {
            echo '<p>' . esc_html__('No cashbook entries yet.', 'billnest') . '</p>';
            echo '</div>';
            return;
        }

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Date', 'billnest') . '</th>';
        echo '<th>' . esc_html__('Type', 'billnest') . '</th>';
        echo '<th>' . esc_html__('Source', 'billnest') . '</th>';
        echo '<th>' . esc_html__('Amount', 'billnest') . '</th>';
        echo '<th>' . esc_html__('Remarks', 'billnest') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($entries as $entry) {
            $type_class = $entry->type === 'in' ? 'billnest-cash-in-text' : 'billnest-cash-out-text';

            echo '<tr>';
            echo '<td>' . esc_html($entry->created_at) . '</td>';
            echo '<td class="' . esc_attr($type_class) . '">' . esc_html(ucfirst($entry->type)) . '</td>';
            echo '<td>' . esc_html(ucfirst(str_replace('_', ' ', $entry->source))) . '</td>';
            echo '<td class="' . esc_attr($type_class) . '">' . esc_html($settings->format_amount((float) $entry->amount)) . '</td>';
            echo '<td>' . esc_html($entry->remarks) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    private function render_manual_form(): void
    {
        echo '<h2>' . esc_html__('Add Manual Entry', 'billnest') . '</h2>';
        echo '<form method="post">';
        wp_nonce_field('billnest_add_cashbook_entry', 'billnest_cashbook_nonce');

        echo '<table class="form-table">';
        echo '<tr><th><label for="type">' . esc_html__('Type', 'billnest') . '</label></th>';
        echo '<td><select name="type" id="type">';
        echo '<option value="in">' . esc_html__('Cash In', 'billnest') . '</option>';
        echo '<option value="out">' . esc_html__('Cash Out', 'billnest') . '</option>';
        echo '</select></td></tr>';

        echo '<tr><th><label for="amount">' . esc_html__('Amount', 'billnest') . '</label></th>';
        echo '<td><input type="number" step="0.01" min="0.01" name="amount" id="amount" required></td></tr>';

        echo '<tr><th><label for="remarks">' . esc_html__('Remarks', 'billnest') . '</label></th>';
        echo '<td><input type="text" class="regular-text" name="remarks" id="remarks"></td></tr>';
        echo '</table>';

        submit_button(__('Add Entry', 'billnest'));
        echo '</form>';
    }

    private function handle_form_submission(): void
    {
        if (! isset($_POST['billnest_cashbook_nonce'])) {
            return;
        }

        if (! wp_verify_nonce($_POST['billnest_cashbook_nonce'], 'billnest_add_cashbook_entry')) {
            wp_die(esc_html__('Security check failed.', 'billnest'));
        }

        $type   = ($_POST['type'] ?? '') === 'out' ? 'out' : 'in';
        $amount = floatval($_POST['amount'] ?? 0);

        if ($amount <= 0) {
            return;
        }

        (new CashbookRepository())->create([
            'type'       => $type,
            'amount'     => $amount,
            'source'     => 'manual',
            'ref_id'     => null,
            'remarks'    => sanitize_text_field($_POST['remarks'] ?? ''),
            'created_by' => get_current_user_id(),
        ]);

        wp_safe_redirect(admin_url('admin.php?page=' . $this->get_slug()));
        exit;
    }
}