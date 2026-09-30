<?php

namespace BillNest\Modules;

use BillNest\Module;
use BillNest\Admin\AdminMenu;
use BillNest\Admin\ProductsPage;
use BillNest\Admin\CategoriesPage;
use BillNest\Admin\CustomersPage;
use BillNest\Admin\PosPage;
use BillNest\Admin\SalesListPage;
use BillNest\Admin\SettingsPage;
use BillNest\Admin\CashbookPage;

if (! defined('ABSPATH')) {
    exit;
}

class ProductsModule implements Module
{

        public function init(): void
    {
        $menu = new AdminMenu();
        $menu->register_page(new ProductsPage());
        $menu->register_page(new CategoriesPage());
        $menu->register_page(new CustomersPage());
        $menu->register_page(new PosPage());
        $menu->register_page(new SalesListPage());
        $menu->register_page(new CashbookPage());
        $menu->register_page(new SettingsPage());
        $menu->init();
    }
}