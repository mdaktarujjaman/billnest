<?php

namespace BillNest\Admin;

use BillNest\DB\SupplierPaymentRepository;
use BillNest\DB\SupplierRepository;
use BillNest\Services\PaymentMethods;
use BillNest\Services\SettingsService;
use BillNest\Services\SupplierPaymentService;

if (! defined('ABSPATH')) {
    exit;
}

class SuppliersPage extends AdminPage
{

    public function get_slug(): string
    {
        return 'billnest-suppliers';
    }

    public function get_title(): string
    {
        return __('Suppliers', 'billnest');
    }

    public function render(): void
    {
        if (! current_user_can($this->get_capability())) {
            wp_die(esc_html__('You do not have permission to access this page.', 'billnest'));
        }

        $error = $this->handle_delete();

        if ($error === null) {
            $error = $this->handle_form_submission();
        }

        if ($error === null) {
            $error = $this->handle_payment();
        }

        if (($_GET['action'] ?? '') === 'pay' && ! empty($_GET['id'])) {
            $this->render_pay(absint($_GET['id']), $error);
            return;
        }

        $repository = new SupplierRepository();
        $suppliers  = $repository->get_all();
        $settings   = new SettingsService();

        $editing = null;

        if (($_GET['action'] ?? '') === 'edit' && ! empty($_GET['id'])) {
            $editing = $repository->get_by_id(absint($_GET['id']));
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html($this->get_title()) . '</h1>';

        if (($_GET['saved'] ?? '') === '1') {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Supplier saved.', 'billnest') . '</p></div>';
        }

        if (($_GET['deleted'] ?? '') === '1') {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Supplier deleted.', 'billnest') . '</p></div>';
        }

        if (($_GET['paid'] ?? '') === '1') {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Payment to the supplier saved.', 'billnest') . '</p></div>';
        }

        if ($error) {
            echo '<div class="notice notice-error"><p>' . esc_html($error) . '</p></div>';
        }

        $this->render_form($editing);

        if (empty($suppliers)) {
            echo '<p>' . esc_html__('No suppliers yet.', 'billnest') . '</p>';
            echo '</div>';
            return;
        }

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Name', 'billnest') . '</th>';
        echo '<th>' . esc_html__('Phone', 'billnest') . '</th>';
        echo '<th>' . esc_html__('Email', 'billnest') . '</th>';
        echo '<th>' . esc_html__('Current Payable', 'billnest') . '</th>';
        echo '<th>' . esc_html__('Action', 'billnest') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($suppliers as $supplier) {
            $edit_url = admin_url('admin.php?page=' . $this->get_slug() . '&action=edit&id=' . $supplier->id);
            $pay_url  = admin_url('admin.php?page=' . $this->get_slug() . '&action=pay&id=' . $supplier->id);

            $delete_url = wp_nonce_url(
                admin_url('admin.php?page=' . $this->get_slug() . '&action=delete&id=' . $supplier->id),
                'billnest_delete_supplier_' . $supplier->id
            );

            echo '<tr>';
            echo '<td>' . esc_html($supplier->name) . '</td>';
            echo '<td>' . esc_html($supplier->phone) . '</td>';
            echo '<td>' . esc_html($supplier->email) . '</td>';
            echo '<td>' . esc_html($settings->format_amount((float) $supplier->current_payable)) . '</td>';
            echo '<td>';

            if ((float) $supplier->current_payable > 0.004) {
                echo '<a href="' . esc_url($pay_url) . '">' . esc_html__('Pay', 'billnest') . '</a> | ';
            }

            echo '<a href="' . esc_url($edit_url) . '">' . esc_html__('Edit', 'billnest') . '</a> | ';
            echo '<a href="' . esc_url($delete_url) . '" onclick="return confirm(\'' . esc_js(__('Delete this supplier?', 'billnest')) . '\');">' . esc_html__('Delete', 'billnest') . '</a>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    private function post_text(string $key): string
    {
        $value = $_POST[$key] ?? '';

        return is_scalar($value) ? wp_unslash((string) $value) : '';
    }

    private function handle_delete(): ?string
    {
        if (($_GET['action'] ?? '') !== 'delete' || empty($_GET['id'])) {
            return null;
        }

        $id = absint($_GET['id']);

        check_admin_referer('billnest_delete_supplier_' . $id);

        $repository = new SupplierRepository();
        $supplier   = $repository->get_by_id($id);

        if (! $supplier) {
            return __('Supplier not found.', 'billnest');
        }

        if (abs((float) $supplier->current_payable) > 0.004) {
            return __('Pay the balance owed to this supplier before deleting it.', 'billnest');
        }

        try {
            $repository->delete($id);
        } catch (\Throwable $e) {
            error_log('BillNest supplier delete failed: ' . $e->getMessage());

            return __('Something went wrong. The supplier was not deleted.', 'billnest');
        }

        wp_safe_redirect(admin_url('admin.php?page=' . $this->get_slug() . '&deleted=1'));
        exit;
    }

    private function handle_form_submission(): ?string
    {
        if (! isset($_POST['billnest_supplier_nonce'])) {
            return null;
        }

        check_admin_referer('billnest_save_supplier', 'billnest_supplier_nonce');

        $name = sanitize_text_field($this->post_text('name'));

        if ($name === '') {
            return __('Name is required.', 'billnest');
        }

        $supplier_id = absint($this->post_text('supplier_id'));
        $opening     = round((float) $this->post_text('opening_balance'), 2);

        if ($supplier_id === 0 && $opening < 0) {
            return __('Opening balance cannot be negative.', 'billnest');
        }

        $data = [
            'name'            => $name,
            'phone'           => sanitize_text_field($this->post_text('phone')),
            'email'           => sanitize_email($this->post_text('email')),
            'address'         => sanitize_textarea_field($this->post_text('address')),
            'opening_balance' => $opening,
        ];

        $repository = new SupplierRepository();

        try {
            if ($supplier_id > 0) {
                $repository->update($supplier_id, $data);
            } else {
                $repository->create($data);
            }
        } catch (\Throwable $e) {
            error_log('BillNest supplier save failed: ' . $e->getMessage());

            return __('Something went wrong. The supplier was not saved.', 'billnest');
        }

        wp_safe_redirect(admin_url('admin.php?page=' . $this->get_slug() . '&saved=1'));
        exit;
    }

    private function handle_payment(): ?string
    {
        if (! isset($_POST['billnest_supplier_pay_nonce'])) {
            return null;
        }

        $supplier_id = absint($this->post_text('supplier_id'));

        check_admin_referer('billnest_pay_supplier_' . $supplier_id, 'billnest_supplier_pay_nonce');

        try {
            (new SupplierPaymentService())->pay(
                $supplier_id,
                (float) $this->post_text('amount'),
                sanitize_key($this->post_text('method')),
                sanitize_text_field($this->post_text('remarks')),
                get_current_user_id(),
                sanitize_text_field($this->post_text('reference'))
            );
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        } catch (\Throwable $e) {
            error_log('BillNest supplier payment failed: ' . $e->getMessage());

            return __('Something went wrong. The payment was not saved.', 'billnest');
        }

        wp_safe_redirect(admin_url('admin.php?page=' . $this->get_slug() . '&paid=1'));
        exit;
    }

    private function render_pay(int $supplier_id, ?string $error): void
    {
        $supplier = (new SupplierRepository())->get_by_id($supplier_id);
        $list_url = admin_url('admin.php?page=' . $this->get_slug());
        $settings = new SettingsService();

        echo '<div class="wrap">';

        if (! $supplier || $supplier->status !== 'active') {
            echo '<h1>' . esc_html__('Supplier not found', 'billnest') . '</h1>';
            echo '<p><a href="' . esc_url($list_url) . '">&larr; ' . esc_html__('Back to Suppliers', 'billnest') . '</a></p>';
            echo '</div>';
            return;
        }

        $payable = (float) $supplier->current_payable;

        echo '<p><a href="' . esc_url($list_url) . '">&larr; ' . esc_html__('Back to Suppliers', 'billnest') . '</a></p>';
        echo '<h1>' . esc_html__('Pay Supplier', 'billnest') . ': ' . esc_html($supplier->name) . '</h1>';

        if ($error) {
            echo '<div class="notice notice-error"><p>' . esc_html($error) . '</p></div>';
        }

        echo '<p><strong>' . esc_html__('Balance owed', 'billnest') . ':</strong> ' . esc_html($settings->format_amount($payable)) . '</p>';

        if ($payable <= 0.004) {
            echo '<p>' . esc_html__('Nothing is owed to this supplier.', 'billnest') . '</p>';
        } else {
            $posted    = isset($_POST['billnest_supplier_pay_nonce']);
            $amount    = $posted ? sanitize_text_field($this->post_text('amount')) : number_format($payable, 2, '.', '');
            $method    = $posted ? sanitize_key($this->post_text('method')) : 'cash';
            $reference = $posted ? sanitize_text_field($this->post_text('reference')) : '';
            $remarks   = $posted ? sanitize_text_field($this->post_text('remarks')) : '';

            echo '<form method="post">';
            wp_nonce_field('billnest_pay_supplier_' . $supplier->id, 'billnest_supplier_pay_nonce');
            echo '<input type="hidden" name="supplier_id" value="' . esc_attr($supplier->id) . '">';

            echo '<table class="form-table">';
            echo '<tr><th><label for="amount">' . esc_html__('Amount', 'billnest') . '</label></th>';
            echo '<td><input type="number" step="0.01" min="0.01" max="' . esc_attr(number_format($payable, 2, '.', '')) . '" name="amount" id="amount" value="' . esc_attr($amount) . '" required></td></tr>';

            echo '<tr><th><label for="method">' . esc_html__('Method', 'billnest') . '</label></th>';
            echo '<td><select name="method" id="method">';
            foreach (PaymentMethods::all() as $key => $label) {
                echo '<option value="' . esc_attr($key) . '"' . selected($method, $key, false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select></td></tr>';

            echo '<tr><th><label for="reference">' . esc_html__('Reference', 'billnest') . '</label></th>';
            echo '<td><input type="text" class="regular-text" name="reference" id="reference" maxlength="60" value="' . esc_attr($reference) . '">';
            echo '<p class="description">' . esc_html__('Transaction ID or approval code. Required for card and mobile payments.', 'billnest') . '</p></td></tr>';

            echo '<tr><th><label for="remarks">' . esc_html__('Remarks', 'billnest') . '</label></th>';
            echo '<td><input type="text" class="regular-text" name="remarks" id="remarks" value="' . esc_attr($remarks) . '"></td></tr>';
            echo '</table>';

            submit_button(__('Pay Supplier', 'billnest'));
            echo '</form>';
        }

        $history = (new SupplierPaymentRepository())->get_by_supplier($supplier_id);

        echo '<h2>' . esc_html__('Payment History', 'billnest') . '</h2>';

        if (empty($history)) {
            echo '<p>' . esc_html__('No payments to this supplier yet.', 'billnest') . '</p>';
        } else {
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__('Date', 'billnest') . '</th>';
            echo '<th>' . esc_html__('Amount', 'billnest') . '</th>';
            echo '<th>' . esc_html__('Method', 'billnest') . '</th>';
            echo '<th>' . esc_html__('Reference', 'billnest') . '</th>';
            echo '<th>' . esc_html__('Remarks', 'billnest') . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($history as $payment) {
                echo '<tr>';
                echo '<td>' . esc_html($payment->payment_date) . '</td>';
                echo '<td>' . esc_html($settings->format_amount((float) $payment->amount)) . '</td>';
                echo '<td>' . esc_html(PaymentMethods::label((string) $payment->method)) . '</td>';
                echo '<td>' . esc_html((string) $payment->reference) . '</td>';
                echo '<td>' . esc_html((string) $payment->remarks) . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }

        echo '</div>';
    }

    private function render_form(?object $editing): void
    {
        $is_editing = $editing !== null;

        echo '<h2>' . ($is_editing ? esc_html__('Edit Supplier', 'billnest') : esc_html__('Add Supplier', 'billnest')) . '</h2>';

        echo '<form method="post">';
        wp_nonce_field('billnest_save_supplier', 'billnest_supplier_nonce');

        if ($is_editing) {
            echo '<input type="hidden" name="supplier_id" value="' . esc_attr($editing->id) . '">';
        }

        echo '<table class="form-table">';

        echo '<tr><th><label for="name">' . esc_html__('Name', 'billnest') . '</label></th>';
        echo '<td><input type="text" class="regular-text" name="name" id="name" value="' . esc_attr($editing->name ?? '') . '" required></td></tr>';

        echo '<tr><th><label for="phone">' . esc_html__('Phone', 'billnest') . '</label></th>';
        echo '<td><input type="text" name="phone" id="phone" value="' . esc_attr($editing->phone ?? '') . '"></td></tr>';

        echo '<tr><th><label for="email">' . esc_html__('Email', 'billnest') . '</label></th>';
        echo '<td><input type="email" class="regular-text" name="email" id="email" value="' . esc_attr($editing->email ?? '') . '"></td></tr>';

        echo '<tr><th><label for="address">' . esc_html__('Address', 'billnest') . '</label></th>';
        echo '<td><textarea name="address" id="address" rows="3" cols="40">' . esc_textarea($editing->address ?? '') . '</textarea></td></tr>';

        if (! $is_editing) {
            echo '<tr><th><label for="opening_balance">' . esc_html__('Opening Balance', 'billnest') . '</label></th>';
            echo '<td><input type="number" step="0.01" min="0" name="opening_balance" id="opening_balance" value="0">';
            echo '<p class="description">' . esc_html__('The amount you already owe this supplier.', 'billnest') . '</p></td></tr>';
        }

        echo '</table>';

        submit_button($is_editing ? __('Update Supplier', 'billnest') : __('Add Supplier', 'billnest'));
        echo '</form>';
    }
}