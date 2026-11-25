<?php

namespace BillNest\Admin;

use BillNest\DB\PurchaseRepository;
use BillNest\Services\PaymentMethods;
use BillNest\Services\SettingsService;

if (! defined('ABSPATH')) {
    exit;
}

class PurchaseListPage extends AdminPage
{

    private const PER_PAGE = 20;

    public function get_slug(): string
    {
        return 'billnest-purchase-list';
    }

    public function get_title(): string
    {
        return __('Purchase List', 'billnest');
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

    private function format_qty(float $qty): string
    {
        return rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.');
    }

    private function clean_date(string $value): string
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return '';
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year) ? $value : '';
    }

    private function read_filters(): array
    {
        $balance = sanitize_key(wp_unslash($_GET['balance'] ?? ''));

        if ($balance !== 'owing') {
            $balance = '';
        }

        $from = $this->clean_date(sanitize_text_field(wp_unslash($_GET['date_from'] ?? '')));
        $to   = $this->clean_date(sanitize_text_field(wp_unslash($_GET['date_to'] ?? '')));

        if ($from !== '' && $to !== '' && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [
            'search'    => sanitize_text_field(wp_unslash($_GET['search'] ?? '')),
            'balance'   => $balance,
            'date_from' => $from,
            'date_to'   => $to,
        ];
    }

    private function render_filter_form(array $filters): void
    {
        $reset_url = admin_url('admin.php?page=' . $this->get_slug());

        echo '<form method="get" class="billnest-filter-form" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;margin:16px 0;">';
        echo '<input type="hidden" name="page" value="' . esc_attr($this->get_slug()) . '">';

        echo '<div><label for="billnest-pf-search" style="display:block;font-weight:600;margin-bottom:4px;">' . esc_html__('Purchase no or supplier', 'billnest') . '</label>';
        echo '<input type="text" id="billnest-pf-search" name="search" value="' . esc_attr($filters['search']) . '" class="regular-text" style="min-width:240px;"></div>';

        echo '<div><label for="billnest-pf-from" style="display:block;font-weight:600;margin-bottom:4px;">' . esc_html__('From', 'billnest') . '</label>';
        echo '<input type="date" id="billnest-pf-from" name="date_from" value="' . esc_attr($filters['date_from']) . '"></div>';

        echo '<div><label for="billnest-pf-to" style="display:block;font-weight:600;margin-bottom:4px;">' . esc_html__('To', 'billnest') . '</label>';
        echo '<input type="date" id="billnest-pf-to" name="date_to" value="' . esc_attr($filters['date_to']) . '"></div>';

        echo '<div><label for="billnest-pf-balance" style="display:block;font-weight:600;margin-bottom:4px;">' . esc_html__('Balance', 'billnest') . '</label>';
        echo '<select id="billnest-pf-balance" name="balance">';
        echo '<option value="">' . esc_html__('All', 'billnest') . '</option>';
        echo '<option value="owing"' . selected($filters['balance'], 'owing', false) . '>' . esc_html__('Owing only', 'billnest') . '</option>';
        echo '</select></div>';

        echo '<div>';
        echo '<button type="submit" class="button button-primary">' . esc_html__('Filter', 'billnest') . '</button> ';
        echo '<a class="button" href="' . esc_url($reset_url) . '">' . esc_html__('Reset', 'billnest') . '</a>';
        echo '</div>';

        echo '</form>';
    }

    private function render_list(): void
    {
        $repository = new PurchaseRepository();
        $settings   = new SettingsService();
        $filters    = $this->read_filters();
        $summary    = $repository->summarize($filters);
        $count      = (int) $summary->purchase_count;
        $pages      = max(1, (int) ceil($count / self::PER_PAGE));
        $page       = min(max(1, absint($_GET['paged'] ?? 1)), $pages);
        $purchases  = $repository->search($filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE);
        $has_filter = implode('', $filters) !== '';
        $reset_url  = admin_url('admin.php?page=' . $this->get_slug());

        echo '<div class="wrap">';
        echo '<h1>' . esc_html($this->get_title()) . '</h1>';

        if ($count === 0 && ! $has_filter) {
            echo '<p>' . esc_html__('No purchases yet.', 'billnest') . '</p>';
            echo '</div>';
            return;
        }

        $this->render_filter_form($filters);

        echo '<p class="billnest-list-summary" style="margin:8px 0 12px;">';
        echo '<strong>' . esc_html(sprintf(
            /* translators: %s: number of purchases */
            _n('%s purchase', '%s purchases', $count, 'billnest'),
            number_format_i18n($count)
        )) . '</strong>';
        echo ' &middot; ' . esc_html__('Purchased:', 'billnest') . ' <strong>' . esc_html($settings->format_amount((float) $summary->total_purchased)) . '</strong>';
        echo ' &middot; ' . esc_html__('Paid:', 'billnest') . ' <strong>' . esc_html($settings->format_amount((float) $summary->total_paid)) . '</strong>';
        echo ' &middot; ' . esc_html__('Owed to suppliers:', 'billnest') . ' <strong>' . esc_html($settings->format_amount((float) $summary->total_due)) . '</strong>';
        echo '</p>';

        if (empty($purchases)) {
            echo '<p>' . esc_html__('No purchases match these filters.', 'billnest') . ' <a href="' . esc_url($reset_url) . '">' . esc_html__('Reset filters', 'billnest') . '</a></p>';
            echo '</div>';
            return;
        }

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Purchase No', 'billnest') . '</th>';
        echo '<th>' . esc_html__('Date', 'billnest') . '</th>';
        echo '<th>' . esc_html__('Supplier', 'billnest') . '</th>';
        echo '<th>' . esc_html__('Total', 'billnest') . '</th>';
        echo '<th>' . esc_html__('Paid', 'billnest') . '</th>';
        echo '<th>' . esc_html__('Due', 'billnest') . '</th>';
        echo '<th>' . esc_html__('Action', 'billnest') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($purchases as $purchase) {
            $view_url  = admin_url('admin.php?page=' . $this->get_slug() . '&action=view&id=' . $purchase->id);
            $due       = (float) $purchase->due_amount;
            $due_class = $due > 0.004 ? ' class="billnest-cash-out-text"' : '';

            echo '<tr>';
            echo '<td>' . esc_html($purchase->purchase_no) . '</td>';
            echo '<td>' . esc_html($purchase->purchase_date) . '</td>';
            echo '<td>' . esc_html($purchase->supplier_name ?: __('No supplier', 'billnest')) . '</td>';
            echo '<td>' . esc_html($settings->format_amount((float) $purchase->total)) . '</td>';
            echo '<td>' . esc_html($settings->format_amount((float) $purchase->paid_amount)) . '</td>';
            echo '<td' . $due_class . '>' . esc_html($settings->format_amount($due)) . '</td>';
            echo '<td><a href="' . esc_url($view_url) . '">' . esc_html__('View', 'billnest') . '</a></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        if ($pages > 1) {
            $this->render_pagination($filters, $page, $pages, $count);
        }

        echo '</div>';
    }

    private function render_pagination(array $filters, int $page, int $pages, int $count): void
    {
        $args = ['page' => $this->get_slug()];

        foreach ($filters as $key => $value) {
            if ($value !== '') {
                $args[$key] = rawurlencode($value);
            }
        }

        $links = paginate_links([
            'base'      => add_query_arg($args, admin_url('admin.php')) . '&paged=%#%',
            'format'    => '',
            'current'   => $page,
            'total'     => $pages,
            'prev_text' => '&lsaquo;',
            'next_text' => '&rsaquo;',
            'type'      => 'plain',
        ]);

        echo '<div class="tablenav bottom"><div class="tablenav-pages">';
        echo '<span class="displaying-num">' . esc_html(sprintf(
            /* translators: 1: current page, 2: total pages, 3: number of purchases */
            __('Page %1$s of %2$s (%3$s purchases)', 'billnest'),
            number_format_i18n($page),
            number_format_i18n($pages),
            number_format_i18n($count)
        )) . '</span> ';
        echo '<span class="pagination-links" style="display:inline-flex;gap:8px;">' . wp_kses_post((string) $links) . '</span>';
        echo '</div></div>';
    }

    private function render_detail(int $purchase_id): void
    {
        $repository = new PurchaseRepository();
        $purchase   = $repository->get_by_id($purchase_id);
        $list_url   = admin_url('admin.php?page=' . $this->get_slug());

        echo '<div class="wrap">';

        if (! $purchase) {
            echo '<h1>' . esc_html__('Purchase not found', 'billnest') . '</h1>';
            echo '<p><a href="' . esc_url($list_url) . '">&larr; ' . esc_html__('Back to Purchase List', 'billnest') . '</a></p>';
            echo '</div>';
            return;
        }

        $items         = $repository->get_items($purchase_id);
        $payments      = $repository->get_payments($purchase_id);
        $settings      = new SettingsService();
        $supplier_name = $purchase->supplier_name ?: __('No supplier', 'billnest');

        echo '<p class="billnest-no-print"><a href="' . esc_url($list_url) . '">&larr; ' . esc_html__('Back to Purchase List', 'billnest') . '</a></p>';

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
        echo '<h2>' . esc_html__('Purchase', 'billnest') . ' #' . esc_html($purchase->purchase_no) . '</h2>';
        echo '<span class="billnest-status billnest-status-' . esc_attr($purchase->status) . '">' . esc_html(ucfirst($purchase->status)) . '</span>';
        echo '</div>';
        echo '<div class="billnest-no-print billnest-header-actions">';
        echo '<button type="button" class="button button-primary" onclick="window.print()">' . esc_html__('Print', 'billnest') . '</button>';
        echo '</div>';
        echo '</div>';

        echo '<div class="billnest-invoice-meta">';
        echo '<div><span class="billnest-meta-label">' . esc_html__('Supplier', 'billnest') . '</span><strong>' . esc_html($supplier_name) . '</strong></div>';
        echo '<div><span class="billnest-meta-label">' . esc_html__('Date', 'billnest') . '</span><strong>' . esc_html($purchase->purchase_date) . '</strong></div>';
        echo '</div>';

        echo '<table class="billnest-invoice-items">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Product', 'billnest') . '</th>';
        echo '<th class="billnest-num">' . esc_html__('Qty', 'billnest') . '</th>';
        echo '<th class="billnest-num">' . esc_html__('Unit cost', 'billnest') . '</th>';
        echo '<th class="billnest-num">' . esc_html__('Total', 'billnest') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($items as $item) {
            $label = $item->product_name
                ? ($item->product_code ? $item->product_code . ' - ' : '') . $item->product_name
                : __('(deleted product)', 'billnest');

            echo '<tr>';
            echo '<td>' . esc_html($label) . '</td>';
            echo '<td class="billnest-num">' . esc_html($this->format_qty((float) $item->qty)) . '</td>';
            echo '<td class="billnest-num">' . esc_html($settings->format_amount((float) $item->unit_cost)) . '</td>';
            echo '<td class="billnest-num">' . esc_html($settings->format_amount((float) $item->total)) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        echo '<div class="billnest-invoice-summary">';
        echo '<div class="billnest-summary-row billnest-summary-total"><span>' . esc_html__('Total', 'billnest') . '</span><span>' . esc_html($settings->format_amount((float) $purchase->total)) . '</span></div>';
        echo '<div class="billnest-summary-row"><span>' . esc_html__('Paid', 'billnest') . '</span><span>' . esc_html($settings->format_amount((float) $purchase->paid_amount)) . '</span></div>';
        echo '<div class="billnest-summary-row billnest-summary-due"><span>' . esc_html__('Owed to supplier', 'billnest') . '</span><span>' . esc_html($settings->format_amount((float) $purchase->due_amount)) . '</span></div>';
        echo '</div>';

        if (! empty($payments)) {
            $this->render_payments($payments, $settings);
        }

        if (! empty($purchase->remarks)) {
            echo '<p class="billnest-invoice-remarks"><strong>' . esc_html__('Remarks:', 'billnest') . '</strong> ' . esc_html($purchase->remarks) . '</p>';
        }

        echo '</div>';
        echo '</div>';
    }

    private function render_payments(array $payments, SettingsService $settings): void
    {
        echo '<div class="billnest-invoice-payments">';
        echo '<h3>' . esc_html__('Payments', 'billnest') . '</h3>';
        echo '<table class="billnest-invoice-items">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Date', 'billnest') . '</th>';
        echo '<th>' . esc_html__('Method', 'billnest') . '</th>';
        echo '<th>' . esc_html__('Reference', 'billnest') . '</th>';
        echo '<th>' . esc_html__('Paid', 'billnest') . '</th>';
        echo '<th class="billnest-num">' . esc_html__('Amount', 'billnest') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($payments as $payment) {
            $note = $payment->supplier_payment_id
                ? __('Supplier payment', 'billnest')
                : __('With the purchase', 'billnest');

            echo '<tr>';
            echo '<td>' . esc_html($payment->created_at) . '</td>';
            echo '<td>' . esc_html(PaymentMethods::label((string) $payment->method)) . '</td>';
            echo '<td>' . esc_html($payment->reference ?: '—') . '</td>';
            echo '<td>' . esc_html($note) . '</td>';
            echo '<td class="billnest-num">' . esc_html($settings->format_amount((float) $payment->amount)) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }
}