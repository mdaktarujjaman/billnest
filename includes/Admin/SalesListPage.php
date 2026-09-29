<?php

namespace BillNest\Admin;

use BillNest\DB\CustomerRepository;
use BillNest\DB\InvoiceRepository;
use BillNest\Services\ReturnService;
use BillNest\Services\SettingsService;

if (! defined('ABSPATH')) {
    exit;
}

class SalesListPage extends AdminPage
{

    public function get_slug(): string
    {
        return 'billnest-sales';
    }

    public function get_title(): string
    {
        return __('Sales List', 'billnest');
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

        $this->handle_return_submission();

        if (($_GET['action'] ?? '') === 'view' && ! empty($_GET['id'])) {
            $this->render_detail(absint($_GET['id']));
            return;
        }

        $this->render_list();
    }

    private function handle_return_submission(): void
    {
        if (! isset($_POST['billnest_return_nonce'])) {
            return;
        }

        $invoice_id = absint($_POST['invoice_id'] ?? 0);

        check_admin_referer('billnest_process_return_' . $invoice_id, 'billnest_return_nonce');

        $redirect = admin_url('admin.php?page=' . $this->get_slug() . '&action=view&id=' . $invoice_id);

        try {
            (new ReturnService())->process_full_return($invoice_id);
            $redirect = add_query_arg('returned', '1', $redirect);
        } catch (\Throwable $e) {
            $redirect = add_query_arg('return_error', '1', $redirect);
        }

        wp_safe_redirect($redirect);
        exit;
    }

    private function render_list(): void
    {
        $invoices = (new InvoiceRepository())->get_all();
        $settings = new SettingsService();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html($this->get_title()) . '</h1>';

        if (empty($invoices)) {
            echo '<p>' . esc_html__('No invoices yet.', 'billnest') . '</p>';
            echo '</div>';
            return;
        }

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Invoice No', 'billnest') . '</th>';
        echo '<th>' . esc_html__('Date', 'billnest') . '</th>';
        echo '<th>' . esc_html__('Customer', 'billnest') . '</th>';
        echo '<th>' . esc_html__('Total', 'billnest') . '</th>';
        echo '<th>' . esc_html__('Received', 'billnest') . '</th>';
        echo '<th>' . esc_html__('Due', 'billnest') . '</th>';
        echo '<th>' . esc_html__('Status', 'billnest') . '</th>';
        echo '<th>' . esc_html__('Action', 'billnest') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($invoices as $invoice) {
            $view_url = admin_url('admin.php?page=' . $this->get_slug() . '&action=view&id=' . $invoice->id);

            echo '<tr>';
            echo '<td>' . esc_html($invoice->invoice_no) . '</td>';
            echo '<td>' . esc_html($invoice->invoice_date) . '</td>';
            echo '<td>' . esc_html($invoice->customer_name ?: __('Walk-in', 'billnest')) . '</td>';
            echo '<td>' . esc_html($settings->format_amount((float) $invoice->total)) . '</td>';
            echo '<td>' . esc_html($settings->format_amount((float) $invoice->received_amount)) . '</td>';
            echo '<td>' . esc_html($settings->format_amount((float) $invoice->due_amount)) . '</td>';
            echo '<td>' . esc_html(ucfirst($invoice->status)) . '</td>';
            echo '<td><a href="' . esc_url($view_url) . '">' . esc_html__('View', 'billnest') . '</a></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    private function render_detail(int $invoice_id): void
    {
        $repository = new InvoiceRepository();
        $invoice    = $repository->get_by_id($invoice_id);
        $list_url   = admin_url('admin.php?page=' . $this->get_slug());

        echo '<div class="wrap">';

        if (! $invoice) {
            echo '<h1>' . esc_html__('Invoice not found', 'billnest') . '</h1>';
            echo '<p><a href="' . esc_url($list_url) . '">&larr; ' . esc_html__('Back to Sales List', 'billnest') . '</a></p>';
            echo '</div>';
            return;
        }

        if (($_GET['returned'] ?? '') === '1') {
            echo '<div class="notice notice-success is-dismissible billnest-no-print"><p>' . esc_html__('Invoice returned. Stock and customer due have been updated.', 'billnest') . '</p></div>';
        }

        if (($_GET['return_error'] ?? '') === '1') {
            echo '<div class="notice notice-error is-dismissible billnest-no-print"><p>' . esc_html__('This invoice could not be returned.', 'billnest') . '</p></div>';
        }

        $items         = $repository->get_items($invoice_id);
        $customer      = $invoice->customer_id ? (new CustomerRepository())->get_by_id((int) $invoice->customer_id) : null;
        $customer_name = $customer ? $customer->name : __('Walk-in Customer', 'billnest');
        $settings      = new SettingsService();

        echo '<p class="billnest-no-print"><a href="' . esc_url($list_url) . '">&larr; ' . esc_html__('Back to Sales List', 'billnest') . '</a></p>';

        echo '<div class="billnest-invoice">';

        $business_name    = $settings->get('business_name');
        $business_phone   = $settings->get('business_phone');
        $business_address = $settings->get('business_address');

        if ($business_name) {
            echo '<div class="billnest-business-header">';
            echo '<strong>' . esc_html($business_name) . '</strong>';
            if ($business_address) {
                echo '<span>' . esc_html($business_address) . '</span>';
            }
            if ($business_phone) {
                echo '<span>' . esc_html__('Phone:', 'billnest') . ' ' . esc_html($business_phone) . '</span>';
            }
            echo '</div>';
        }

        echo '<div class="billnest-invoice-header">';
        echo '<div>';
        echo '<h2>' . esc_html__('Invoice', 'billnest') . ' #' . esc_html($invoice->invoice_no) . '</h2>';
        echo '<span class="billnest-status billnest-status-' . esc_attr($invoice->status) . '">' . esc_html(ucfirst($invoice->status)) . '</span>';
        echo '</div>';

        echo '<div class="billnest-no-print billnest-header-actions">';
        echo '<button type="button" class="button button-primary" onclick="window.print()">' . esc_html__('Print', 'billnest') . '</button>';

        if ($invoice->status === 'completed') {
            echo '<form method="post" style="display:inline;" onsubmit="return confirm(\'' . esc_js(__('Process full return? This will restock all items and reverse the due amount.', 'billnest')) . '\');">';
            wp_nonce_field('billnest_process_return_' . $invoice->id, 'billnest_return_nonce');
            echo '<input type="hidden" name="invoice_id" value="' . esc_attr($invoice->id) . '">';
            echo '<button type="submit" class="button billnest-return-btn">' . esc_html__('Process Return', 'billnest') . '</button>';
            echo '</form>';
        }

        echo '</div>';
        echo '</div>';

        echo '<div class="billnest-invoice-meta">';
        echo '<div><span class="billnest-meta-label">' . esc_html__('Customer', 'billnest') . '</span><strong>' . esc_html($customer_name) . '</strong></div>';
        echo '<div><span class="billnest-meta-label">' . esc_html__('Date', 'billnest') . '</span><strong>' . esc_html($invoice->invoice_date) . '</strong></div>';
        echo '</div>';

        echo '<table class="billnest-invoice-items">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Product', 'billnest') . '</th>';
        echo '<th class="billnest-num">' . esc_html__('Qty', 'billnest') . '</th>';
        echo '<th class="billnest-num">' . esc_html__('Unit Price', 'billnest') . '</th>';
        echo '<th class="billnest-num">' . esc_html__('Total', 'billnest') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($items as $item) {
            echo '<tr>';
            echo '<td>' . esc_html($item->product_name ?: __('(deleted product)', 'billnest')) . '</td>';
            echo '<td class="billnest-num">' . esc_html(rtrim(rtrim(number_format((float) $item->qty, 2), '0'), '.')) . '</td>';
            echo '<td class="billnest-num">' . esc_html($settings->format_amount((float) $item->unit_price)) . '</td>';
            echo '<td class="billnest-num">' . esc_html($settings->format_amount((float) $item->total)) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        echo '<div class="billnest-invoice-summary">';
        echo '<div class="billnest-summary-row"><span>' . esc_html__('Subtotal', 'billnest') . '</span><span>' . esc_html($settings->format_amount((float) $invoice->subtotal)) . '</span></div>';

        if ((float) $invoice->discount_amount > 0) {
            echo '<div class="billnest-summary-row"><span>' . esc_html__('Discount', 'billnest') . '</span><span>-' . esc_html($settings->format_amount((float) $invoice->discount_amount)) . '</span></div>';
        }

        echo '<div class="billnest-summary-row billnest-summary-total"><span>' . esc_html__('Total', 'billnest') . '</span><span>' . esc_html($settings->format_amount((float) $invoice->total)) . '</span></div>';
        echo '<div class="billnest-summary-row"><span>' . esc_html__('Received', 'billnest') . '</span><span>' . esc_html($settings->format_amount((float) $invoice->received_amount)) . '</span></div>';
        echo '<div class="billnest-summary-row billnest-summary-due"><span>' . esc_html__('Due', 'billnest') . '</span><span>' . esc_html($settings->format_amount((float) $invoice->due_amount)) . '</span></div>';
        echo '</div>';

        if (! empty($invoice->remarks)) {
            echo '<p class="billnest-invoice-remarks"><strong>' . esc_html__('Remarks:', 'billnest') . '</strong> ' . esc_html($invoice->remarks) . '</p>';
        }

        echo '</div>';
        echo '</div>';
    }
}