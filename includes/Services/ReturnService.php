<?php

namespace BillNest\Services;

use BillNest\DB\InvoiceRepository;
use BillNest\DB\CustomerRepository;
use BillNest\DB\CashbookRepository;

if (! defined('ABSPATH')) {
    exit;
}

class ReturnService
{

    private InvoiceRepository $invoices;
    private CustomerRepository $customers;
    private StockService $stock;

    public function __construct()
    {
        $this->invoices  = new InvoiceRepository();
        $this->customers = new CustomerRepository();
        $this->stock     = new StockService();
    }

    public function process_full_return(int $invoice_id): void
    {
        global $wpdb;

        $invoice = $this->invoices->get_by_id($invoice_id);

        if (! $invoice || $invoice->status !== 'completed') {
            throw new \RuntimeException('This invoice cannot be returned.');
        }

        $items = $this->invoices->get_items($invoice_id);

        $wpdb->query('START TRANSACTION');

        try {
            foreach ($items as $item) {
                $this->stock->increment_stock(
                    (int) $item->product_id,
                    (float) $item->qty,
                    'sales_return',
                    $invoice_id,
                    'invoice'
                );
            }

            if ($invoice->customer_id) {
                $this->customers->increment_due((int) $invoice->customer_id, -(float) $invoice->due_amount);
            }

            if ((float) $invoice->received_amount > 0) {
                (new CashbookRepository())->create([
                    'type'       => 'out',
                    'amount'     => (float) $invoice->received_amount,
                    'source'     => 'sales_return',
                    'ref_id'     => $invoice_id,
                    'remarks'    => 'Return for invoice ' . $invoice->invoice_no,
                    'created_by' => get_current_user_id(),
                ]);
            }

            $this->invoices->update_status($invoice_id, 'returned');

            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }
}
