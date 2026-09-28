<?php

namespace BillNest\Admin;

use BillNest\DB\CustomerRepository;
use BillNest\DB\InvoiceRepository;

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

        if (($_GET['action'] ?? '') === 'view' && ! empty($_GET['id'])) {
            $this->render_detail(absint($_GET['id']));
            return;
        }

        $this->render_list();
    }

    private function render_list(): void
    {
        $invoices = (new InvoiceRepository())->get_all();

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
            echo '<td>' . esc_html(number_format((float) $invoice->total, 2)) . '</td>';
            echo '<td>' . esc_html(number_format((float) $invoice->received_amount, 2)) . '</td>';
            echo '<td>' . esc_html(number_format((float) $invoice->due_amount, 2)) . '</td>';
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

        $items         = $repository->get_items($invoice_id);
        $customer      = $invoice->customer_id ? (new CustomerRepository())->get_by_id((int) $invoice->customer_id) : null;
        $customer_name = $customer ? $customer->name : __('Walk-in Customer', 'billnest');

        echo '<p class="billnest-no-print"><a href="' . esc_url($list_url) . '">&larr; ' . esc_html__('Back to Sales List', 'billnest') . '</a></p>';

        echo '<div class="billnest-invoice">';

        echo '<div class="billnest-invoice-header">';
        echo '<div>';
        echo '<h2>' . esc_html__('Invoice', 'billnest') . ' #' . esc_html($invoice->invoice_no) . '</h2>';
        echo '<span class="billnest-status billnest-status-' . esc_attr($invoice->status) . '">' . esc_html(ucfirst($invoice->status)) . '</span>';
        echo '</div>';
        echo '<button type="button" class="button button-primary billnest-no-print" onclick="window.print()">' . esc_html__('Print', 'billnest') . '</button>';
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
            echo '<td class="billnest-num">' . esc_html(number_format((float) $item->unit_price, 2)) . '</td>';
            echo '<td class="billnest-num">' . esc_html(number_format((float) $item->total, 2)) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        echo '<div class="billnest-invoice-summary">';
        echo '<div class="billnest-summary-row"><span>' . esc_html__('Subtotal', 'billnest') . '</span><span>' . esc_html(number_format((float) $invoice->subtotal, 2)) . '</span></div>';

        if ((float) $invoice->discount_amount > 0) {
            echo '<div class="billnest-summary-row"><span>' . esc_html__('Discount', 'billnest') . '</span><span>-' . esc_html(number_format((float) $invoice->discount_amount, 2)) . '</span></div>';
        }

        echo '<div class="billnest-summary-row billnest-summary-total"><span>' . esc_html__('Total', 'billnest') . '</span><span>' . esc_html(number_format((float) $invoice->total, 2)) . '</span></div>';
        echo '<div class="billnest-summary-row"><span>' . esc_html__('Received', 'billnest') . '</span><span>' . esc_html(number_format((float) $invoice->received_amount, 2)) . '</span></div>';
        echo '<div class="billnest-summary-row billnest-summary-due"><span>' . esc_html__('Due', 'billnest') . '</span><span>' . esc_html(number_format((float) $invoice->due_amount, 2)) . '</span></div>';
        echo '</div>';

        if (! empty($invoice->remarks)) {
            echo '<p class="billnest-invoice-remarks"><strong>' . esc_html__('Remarks:', 'billnest') . '</strong> ' . esc_html($invoice->remarks) . '</p>';
        }

        echo '</div>';
        echo '</div>';
    }
}