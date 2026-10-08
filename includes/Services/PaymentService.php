<?php

namespace BillNest\Services;

use BillNest\DB\CashbookRepository;
use BillNest\DB\CustomerRepository;
use BillNest\DB\InvoiceRepository;
use BillNest\DB\PaymentRepository;

if (! defined('ABSPATH')) {
    exit;
}

class PaymentService
{

    private PaymentRepository $payments;
    private CustomerRepository $customers;
    private InvoiceRepository $invoices;
    private CashbookRepository $cashbook;

    public function __construct()
    {
        $this->payments  = new PaymentRepository();
        $this->customers = new CustomerRepository();
        $this->invoices  = new InvoiceRepository();
        $this->cashbook  = new CashbookRepository();
    }

    public function collect(int $customer_id, float $amount, string $method, string $remarks, int $user_id): int
    {
        global $wpdb;

        $customer = $this->customers->get_by_id($customer_id);

        if (! $customer) {
            throw new \RuntimeException(__('Customer not found.', 'billnest'));
        }

        if ($amount <= 0) {
            throw new \RuntimeException(__('Amount must be greater than zero.', 'billnest'));
        }

        if ($amount - (float) $customer->current_due > 0.005) {
            throw new \RuntimeException(__('Amount cannot be more than the customer due.', 'billnest'));
        }

        $wpdb->query('START TRANSACTION');

        try {
            $payment_id = $this->payments->create([
                'customer_id'  => $customer_id,
                'amount'       => $amount,
                'payment_date' => current_time('Y-m-d'),
                'method'       => $method,
                'remarks'      => $remarks,
                'created_by'   => $user_id,
            ]);

            $remaining = $amount;

            foreach ($this->invoices->get_open_invoices_for_customer($customer_id) as $invoice) {
                if ($remaining <= 0) {
                    break;
                }

                $apply = min($remaining, (float) $invoice->due_amount);

                $this->invoices->apply_payment((int) $invoice->id, $apply);

                $remaining -= $apply;
            }

            $this->customers->increment_due($customer_id, -$amount);

            $this->cashbook->create([
                'type'       => 'in',
                'amount'     => $amount,
                'source'     => 'payment',
                'ref_id'     => $payment_id,
                'remarks'    => 'Due collection: ' . $customer->name,
                'created_by' => $user_id,
            ]);

            $wpdb->query('COMMIT');

            return $payment_id;
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }
}