<?php

namespace BillNest\Services;

use BillNest\DB\InvoiceRepository;
use BillNest\DB\CustomerRepository;
use BillNest\DB\CashbookRepository;
use BillNest\DB\InvoicePaymentRepository;

if (! defined('ABSPATH')) {
    exit;
}

class ReturnService
{

    private InvoiceRepository $invoices;
    private CustomerRepository $customers;
    private InvoicePaymentRepository $payments;
    private StockService $stock;

    public function __construct()
    {
        $this->invoices  = new InvoiceRepository();
        $this->customers = new CustomerRepository();
        $this->payments  = new InvoicePaymentRepository();
        $this->stock     = new StockService();
    }

    public function process_full_return(int $invoice_id): void
    {
        global $wpdb;

        $invoice = $this->invoices->get_by_id($invoice_id);

        if (! $invoice || $invoice->status !== 'completed') {
            throw new \RuntimeException('This invoice cannot be returned.');
        }

        $items   = $this->invoices->get_items($invoice_id);
        $paid    = $this->payments->get_by_invoice($invoice_id);
        $refunds = $this->build_refunds($invoice, $paid);

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

            if (! empty($paid)) {
                foreach ($refunds as $method => $amount) {
                    $this->payments->create($invoice_id, $method, -$amount, 'Refund');
                }
            }

            $cash_refund = (float) ($refunds['cash'] ?? 0);

            if ($cash_refund > 0) {
                (new CashbookRepository())->create([
                    'type'       => 'out',
                    'amount'     => $cash_refund,
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

    private function build_refunds(object $invoice, array $paid): array
    {
        if (empty($paid)) {
            $received = round((float) $invoice->received_amount, 2);

            return $received > 0 ? ['cash' => $received] : [];
        }

        $refunds = [];

        foreach ($paid as $payment) {
            $amount = round((float) $payment->amount, 2);

            if ($amount <= 0) {
                continue;
            }

            $method = (string) $payment->method;

            $refunds[$method] = round(($refunds[$method] ?? 0) + $amount, 2);
        }

        return $refunds;
    }
}