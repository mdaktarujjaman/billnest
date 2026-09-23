<?php
namespace BillNest;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Loader {

    private array $modules = [];

    public function register( Module $module ): void {
        $this->modules[] = $module;
    }

    public function run(): void {
        foreach ( $this->modules as $module ) {
            try {
                $module->init();
            } catch ( \Throwable $e ) {
                \error_log( 'BillNest module failed: ' . get_class( $module ) . ' — ' . $e->getMessage() );
            }
        }
    }
}