<?php
namespace BillNest\Modules;

use BillNest\Module;
use BillNest\Admin\AdminMenu;
use BillNest\Admin\ProductsPage;
use BillNest\Admin\CategoriesPage;
use BillNest\Admin\CustomersPage;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ProductsModule implements Module {

    public function init(): void {
        $menu = new AdminMenu();
        $menu->register_page( new ProductsPage() );
        $menu->register_page( new CategoriesPage() );
        $menu->register_page( new CustomersPage() );
        $menu->init();
    }
}