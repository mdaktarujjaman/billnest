<?php

namespace BillNest\Admin;

if (! defined('ABSPATH')) {
    exit;
}

class AdminMenu
{

    private array $pages = [];

    public function register_page(AdminPage $page): void
    {
        $this->pages[] = $page;
    }

    public function init(): void
    {
        add_action('admin_menu', [$this, 'build_menu']);
        add_action('admin_enqueue_scripts', [$this, 'maybe_enqueue_assets']);
    }

    public function maybe_enqueue_assets(string $hook): void
    {
        foreach ($this->pages as $page) {
            if (str_contains($hook, $page->get_slug())) {
                $page->enqueue_assets();
            }
        }
    }

    public function build_menu(): void
    {
        add_menu_page(
            __('BillNest', 'billnest'),
            __('BillNest', 'billnest'),
            'manage_billnest',
            'billnest',
            [$this, 'render_dashboard'],
            'dashicons-cart',
            56
        );

        foreach ($this->pages as $page) {
            add_submenu_page(
                'billnest',
                $page->get_title(),
                $page->get_title(),
                $page->get_capability(),
                $page->get_slug(),
                [$page, 'render']
            );
        }
    }

    public function render_dashboard(): void
    {
        echo '<div class="wrap"><h1>' . esc_html__('BillNest Dashboard', 'billnest') . '</h1></div>';
    }
}
