<?php
namespace BillNest\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

abstract class AdminPage {

    abstract public function get_slug(): string;
    abstract public function get_title(): string;
    abstract public function render(): void;

    public function get_capability(): string {
        return 'manage_billnest';
    }

    public function enqueue_assets(): void {}
}