<?php

namespace BillNest\Admin;

use BillNest\Services\SettingsService;

if (! defined('ABSPATH')) {
    exit;
}

class SettingsPage extends AdminPage
{

    public function get_slug(): string
    {
        return 'billnest-settings';
    }

    public function get_title(): string
    {
        return __('Settings', 'billnest');
    }

    public function render(): void
    {
        if (! current_user_can($this->get_capability())) {
            wp_die(esc_html__('You do not have permission to access this page.', 'billnest'));
        }

        $service = new SettingsService();

        $this->handle_form_submission($service);

        $settings = $service->all();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html($this->get_title()) . '</h1>';

        if (($_GET['updated'] ?? '') === '1') {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Settings saved.', 'billnest') . '</p></div>';
        }

        echo '<form method="post">';
        wp_nonce_field('billnest_save_settings', 'billnest_settings_nonce');

        echo '<h2>' . esc_html__('Business', 'billnest') . '</h2>';
        echo '<table class="form-table">';

        echo '<tr><th><label for="business_name">' . esc_html__('Business Name', 'billnest') . '</label></th>';
        echo '<td><input type="text" class="regular-text" name="business_name" id="business_name" value="' . esc_attr($settings['business_name']) . '"></td></tr>';

        echo '<tr><th><label for="business_phone">' . esc_html__('Phone', 'billnest') . '</label></th>';
        echo '<td><input type="text" class="regular-text" name="business_phone" id="business_phone" value="' . esc_attr($settings['business_phone']) . '"></td></tr>';

        echo '<tr><th><label for="business_address">' . esc_html__('Address', 'billnest') . '</label></th>';
        echo '<td><textarea name="business_address" id="business_address" rows="3" cols="40">' . esc_textarea($settings['business_address']) . '</textarea></td></tr>';

        echo '</table>';

        echo '<h2>' . esc_html__('Currency', 'billnest') . '</h2>';
        echo '<table class="form-table">';

        echo '<tr><th><label for="currency_symbol">' . esc_html__('Currency Symbol', 'billnest') . '</label></th>';
        echo '<td><input type="text" class="small-text" name="currency_symbol" id="currency_symbol" value="' . esc_attr($settings['currency_symbol']) . '"></td></tr>';

        echo '<tr><th><label for="currency_position">' . esc_html__('Symbol Position', 'billnest') . '</label></th>';
        echo '<td><select name="currency_position" id="currency_position">';
        echo '<option value="before"' . selected($settings['currency_position'], 'before', false) . '>' . esc_html__('Before amount (৳500)', 'billnest') . '</option>';
        echo '<option value="after"' . selected($settings['currency_position'], 'after', false) . '>' . esc_html__('After amount (500৳)', 'billnest') . '</option>';
        echo '</select></td></tr>';

        echo '</table>';

        submit_button(__('Save Settings', 'billnest'));
        echo '</form>';
        echo '</div>';
    }

    private function handle_form_submission(SettingsService $service): void
    {
        if (! isset($_POST['billnest_settings_nonce'])) {
            return;
        }

        if (! wp_verify_nonce($_POST['billnest_settings_nonce'], 'billnest_save_settings')) {
            wp_die(esc_html__('Security check failed.', 'billnest'));
        }

        $service->save(wp_unslash($_POST));

        wp_safe_redirect(admin_url('admin.php?page=' . $this->get_slug() . '&updated=1'));
        exit;
    }
}