<?php
namespace BillNest\Modules;

// This file defines the ProductsModule class, which implements the Module interface for the BillNest Plugin.
use BillNest\Module;
use BillNest\Admin\AdminMenu;
use BillNest\Admin\ProductsPage;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ProductsModule implements Module {

    public function init(): void {
        $menu = new AdminMenu();
        $menu-> register_page( new ProductsPage() );
        $menu-> init();
    }
}