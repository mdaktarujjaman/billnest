<?php

namespace BillNest\Admin;

use BillNest\DB\CategoryRepository;
use BillNest\DB\ProductRepository;
use BillNest\Services\SettingsService;
use BillNest\Services\StockService;

if (! defined('ABSPATH')) {
    exit;
}

class ProductsPage extends AdminPage
{

    private const UNITS = [
        'pcs'   => 'Pieces (pcs)',
        'pair'  => 'Pair',
        'dozen' => 'Dozen',
        'box'   => 'Box',
        'kg'    => 'Kilogram (kg)',
        'gm'    => 'Gram (gm)',
        'ltr'   => 'Litre (ltr)',
        'meter' => 'Meter',
    ];

    public function get_slug(): string
    {
        return 'billnest-products';
    }

    public function get_title(): string
    {
        return __('Products', 'billnest');
    }

    public function enqueue_assets(): void
    {
        wp_enqueue_media();

        wp_enqueue_style(
            'billnest-products',
            BILLNEST_PLUGIN_URL . 'assets/admin/css/products.css',
            [],
            BILLNEST_VERSION
        );

        wp_enqueue_script(
            'billnest-products',
            BILLNEST_PLUGIN_URL . 'assets/admin/js/products.js',
            ['jquery'],
            BILLNEST_VERSION,
            true
        );
    }

    public function render(): void
    {
        if (! current_user_can($this->get_capability())) {
            wp_die(esc_html__('You do not have permission to access this page.', 'billnest'));
        }

        $this->handle_delete();
        $this->handle_form_submission();

        $repository = new ProductRepository();
        $products   = $repository->get_all();
        $settings   = new SettingsService();

        $editing_product = null;
        if (($_GET['action'] ?? '') === 'edit' && ! empty($_GET['id'])) {
            $editing_product = $repository->get_by_id(absint($_GET['id']));
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html($this->get_title()) . '</h1>';

        $error = $_GET['billnest_error'] ?? '';

        if ($error === 'duplicate_code') {
            echo '<div class="notice notice-error"><p>' . esc_html__('That product code is already in use. Please choose a different code.', 'billnest') . '</p></div>';
        }

        if ($error === 'required') {
            echo '<div class="notice notice-error"><p>' . esc_html__('Product code and name are required.', 'billnest') . '</p></div>';
        }

        $this->render_form($editing_product);

        if (empty($products)) {
            echo '<p>' . esc_html__('No products yet.', 'billnest') . '</p>';
            echo '</div>';
            return;
        }

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th class="billnest-col-thumb"></th>';
        echo '<th>' . esc_html__('Code', 'billnest') . '</th>';
        echo '<th>' . esc_html__('Name', 'billnest') . '</th>';
        echo '<th>' . esc_html__('Category', 'billnest') . '</th>';
        echo '<th>' . esc_html__('Purchase', 'billnest') . '</th>';
        echo '<th>' . esc_html__('Sale', 'billnest') . '</th>';
        echo '<th>' . esc_html__('Stock', 'billnest') . '</th>';
        echo '<th>' . esc_html__('Action', 'billnest') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($products as $product) {
            $edit_url = admin_url('admin.php?page=' . $this->get_slug() . '&action=edit&id=' . $product->id);

            $delete_url = wp_nonce_url(
                admin_url('admin.php?page=' . $this->get_slug() . '&action=delete&id=' . $product->id),
                'billnest_delete_product_' . $product->id
            );

            $is_low = (float) $product->reorder_level > 0 && (float) $product->stock_qty <= (float) $product->reorder_level;
            $unit   = $product->unit ?: 'pcs';

            echo '<tr>';

            echo '<td>';
            if (! empty($product->image_url)) {
                echo '<img class="billnest-product-thumb" src="' . esc_url($product->image_url) . '" alt="">';
            }
            echo '</td>';

            echo '<td>' . esc_html($product->code) . '</td>';
            echo '<td>' . esc_html($product->name) . '</td>';
            echo '<td>' . esc_html($product->category_name ?: '—') . '</td>';
            echo '<td>' . esc_html($settings->format_amount((float) $product->purchase_price)) . '</td>';
            echo '<td>' . esc_html($settings->format_amount((float) $product->sale_price)) . '</td>';

            echo '<td>' . esc_html($this->format_qty((float) $product->stock_qty) . ' ' . $unit);
            if ($is_low) {
                echo '<span class="billnest-low-stock">' . esc_html__('Low', 'billnest') . '</span>';
            }
            echo '</td>';

            echo '<td>';
            echo '<a href="' . esc_url($edit_url) . '">' . esc_html__('Edit', 'billnest') . '</a> | ';
            echo '<a href="' . esc_url($delete_url) . '" onclick="return confirm(\'' . esc_js(__('Delete this product?', 'billnest')) . '\');">' . esc_html__('Delete', 'billnest') . '</a>';
            echo '</td>';

            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    private function handle_delete(): void
    {
        if (($_GET['action'] ?? '') !== 'delete' || empty($_GET['id'])) {
            return;
        }

        $id = absint($_GET['id']);

        check_admin_referer('billnest_delete_product_' . $id);

        (new ProductRepository())->delete($id);

        wp_safe_redirect(admin_url('admin.php?page=' . $this->get_slug()));
        exit;
    }

    private function handle_form_submission(): void
    {
        if (! isset($_POST['billnest_product_nonce'])) {
            return;
        }

        if (! wp_verify_nonce($_POST['billnest_product_nonce'], 'billnest_save_product')) {
            wp_die(esc_html__('Security check failed.', 'billnest'));
        }

        $unit = sanitize_key($_POST['unit'] ?? 'pcs');

        if (! isset(self::UNITS[$unit])) {
            $unit = 'pcs';
        }

        $data = [
            'code'           => sanitize_text_field(wp_unslash($_POST['code'] ?? '')),
            'name'           => sanitize_text_field(wp_unslash($_POST['name'] ?? '')),
            'category_id'    => absint($_POST['category_id'] ?? 0),
            'model'          => sanitize_text_field(wp_unslash($_POST['model'] ?? '')),
            'unit'           => $unit,
            'purchase_price' => max(0, floatval($_POST['purchase_price'] ?? 0)),
            'sale_price'     => max(0, floatval($_POST['sale_price'] ?? 0)),
            'reorder_level'  => max(0, floatval($_POST['reorder_level'] ?? 0)),
            'image_url'      => esc_url_raw(wp_unslash($_POST['image_url'] ?? '')),
        ];

        $target_stock = max(0, floatval($_POST['stock_qty'] ?? 0));
        $redirect_url = admin_url('admin.php?page=' . $this->get_slug());

        if ($data['code'] === '' || $data['name'] === '') {
            wp_safe_redirect(add_query_arg('billnest_error', 'required', $redirect_url));
            exit;
        }

        $repository = new ProductRepository();
        $product_id = absint($_POST['product_id'] ?? 0);

        if ($product_id > 0) {
            $success = $repository->update($product_id, $data);
        } else {
            $product_id = $repository->create($data);
            $success    = $product_id !== false;
        }

        if ($success) {
            $this->sync_stock((int) $product_id, $target_stock);
        } else {
            $redirect_url = add_query_arg('billnest_error', 'duplicate_code', $redirect_url);
        }

        wp_safe_redirect($redirect_url);
        exit;
    }

    private function sync_stock(int $product_id, float $target): void
    {
        $product = (new ProductRepository())->get_by_id($product_id);

        if (! $product) {
            return;
        }

        $delta = $target - (float) $product->stock_qty;

        if (abs($delta) < 0.005) {
            return;
        }

        $stock = new StockService();

        if ($delta > 0) {
            $stock->increment_stock($product_id, $delta, 'adjustment', $product_id, 'product');
        } else {
            $stock->decrement_stock($product_id, abs($delta), 'adjustment', $product_id, 'product');
        }
    }

    private function render_form(?object $editing_product): void
    {
        $is_editing = $editing_product !== null;
        $categories = (new CategoryRepository())->get_all();
        $current    = $editing_product;

        $selected_unit = $current->unit ?? 'pcs';

        if (! isset(self::UNITS[$selected_unit])) {
            $selected_unit = 'pcs';
        }

        echo '<h2>' . ($is_editing ? esc_html__('Edit Product', 'billnest') : esc_html__('Add Product', 'billnest')) . '</h2>';

        echo '<form method="post">';
        wp_nonce_field('billnest_save_product', 'billnest_product_nonce');

        if ($is_editing) {
            echo '<input type="hidden" name="product_id" value="' . esc_attr($current->id) . '">';
        }

        echo '<table class="form-table">';

        echo '<tr><th><label for="code">' . esc_html__('Code', 'billnest') . '</label></th>';
        echo '<td><input type="text" name="code" id="code" value="' . esc_attr($current->code ?? '') . '" required></td></tr>';

        echo '<tr><th><label for="name">' . esc_html__('Name', 'billnest') . '</label></th>';
        echo '<td><input type="text" class="regular-text" name="name" id="name" value="' . esc_attr($current->name ?? '') . '" required></td></tr>';

        echo '<tr><th><label for="category_id">' . esc_html__('Category', 'billnest') . '</label></th>';
        echo '<td><select name="category_id" id="category_id">';
        echo '<option value="0">' . esc_html__('-- None --', 'billnest') . '</option>';
        foreach ($categories as $category) {
            $is_selected = isset($current->category_id) && (int) $current->category_id === (int) $category->id;
            echo '<option value="' . esc_attr($category->id) . '"' . selected($is_selected, true, false) . '>' . esc_html($category->name) . '</option>';
        }
        echo '</select></td></tr>';

        echo '<tr><th><label for="model">' . esc_html__('Model', 'billnest') . '</label></th>';
        echo '<td><input type="text" name="model" id="model" value="' . esc_attr($current->model ?? '') . '"></td></tr>';

        echo '<tr><th><label for="unit">' . esc_html__('Unit', 'billnest') . '</label></th>';
        echo '<td><select name="unit" id="unit">';
        foreach (self::UNITS as $key => $label) {
            echo '<option value="' . esc_attr($key) . '"' . selected($selected_unit, $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></td></tr>';

        echo '<tr><th><label for="purchase_price">' . esc_html__('Purchase Price', 'billnest') . '</label></th>';
        echo '<td><input type="number" step="0.01" min="0" name="purchase_price" id="purchase_price" value="' . esc_attr($current->purchase_price ?? '0') . '"></td></tr>';

        echo '<tr><th><label for="sale_price">' . esc_html__('Sale Price', 'billnest') . '</label></th>';
        echo '<td><input type="number" step="0.01" min="0" name="sale_price" id="sale_price" value="' . esc_attr($current->sale_price ?? '') . '" required></td></tr>';

        echo '<tr><th><label for="stock_qty">' . esc_html__('Stock Qty', 'billnest') . '</label></th>';
        echo '<td><input type="number" step="0.01" min="0" name="stock_qty" id="stock_qty" value="' . esc_attr($current->stock_qty ?? '0') . '" required></td></tr>';

        echo '<tr><th><label for="reorder_level">' . esc_html__('Reorder Level', 'billnest') . '</label></th>';
        echo '<td><input type="number" step="0.01" min="0" name="reorder_level" id="reorder_level" value="' . esc_attr($current->reorder_level ?? '0') . '">';
        echo '<p class="description">' . esc_html__('Stock at or below this level is marked Low. Use 0 to turn the alert off.', 'billnest') . '</p></td></tr>';

        echo '<tr><th>' . esc_html__('Image', 'billnest') . '</th><td>';
        echo '<input type="hidden" name="image_url" id="image_url" value="' . esc_attr($current->image_url ?? '') . '">';
        echo '<div id="billnest-image-preview" class="billnest-image-preview"></div>';
        echo '<button type="button" class="button" id="billnest-select-image">' . esc_html__('Select Image', 'billnest') . '</button> ';
        echo '<button type="button" class="button" id="billnest-remove-image">' . esc_html__('Remove', 'billnest') . '</button>';
        echo '</td></tr>';

        echo '</table>';

        submit_button($is_editing ? __('Update Product', 'billnest') : __('Add Product', 'billnest'));

        if ($is_editing) {
            echo '<p><a href="' . esc_url(admin_url('admin.php?page=' . $this->get_slug())) . '">' . esc_html__('Cancel edit', 'billnest') . '</a></p>';
        }

        echo '</form>';
    }

    private function format_qty(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}