<?php
namespace BillNest;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

interface Module {
    public function init(): void;
}