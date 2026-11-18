<?php

namespace BillNest\Admin;

use BillNest\DB\SupplierRepository;
use BillNest\Services\SettingsService;

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