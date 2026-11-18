<?php

namespace BillNest\Services;

use BillNest\DB\CashbookRepository;
use BillNest\DB\CustomerRepository;
use BillNest\DB\InvoicePaymentRepository;
use BillNest\DB\InvoiceRepository;
use BillNest\DB\PaymentRepository;

if (! defined('ABSPATH')) {
    exit;
}

class PaymentService
{

    private const MAX_REFERENCE = 60;

    private PaymentRepository $payments;
    private CustomerRepository $customers;
    private InvoiceRepository $invoices;
    private InvoicePaymentRepository $invoice_payments;
    private CashbookRepository $cashbook;

    public function __construct()
    {
        $this->payments         = new PaymentRepository();
        $this->customers        = new CustomerRepository();
        $this->invoices         = new InvoiceRepository();
        $this->invoice_payments = new InvoicePaymentRepository();
        $this->cashbook         = new CashbookRepository();
    }

    public function collect(int $customer_id, float $amount, string $method, string $remarks, int $user_id, string $reference = ''): int
    {
        global $wpdb;

        $amount    = round($amount, 2);
        $reference = trim($reference);

        $customer = $this->customers->get_by_id($customer_id);

        if (! $customer) {
            throw new \RuntimeException(__('Customer not found.', 'billnest'));
        }

        if (! PaymentMethods::is_valid($method)) {
            throw new \RuntimeException(__('Choose a valid payment method.', 'billnest'));
        }

        if ($amount <= 0) {
            throw new \RuntimeException(__('Amount must be greater than zero.', 'billnest'));
        }

        if ($amount - (float) $customer->current_due > 0.005) {
            throw new \RuntimeException(__('Amount cannot be more than the customer due.', 'billnest'));
        }

        if (PaymentMethods::requires_reference($method) && $reference === '') {
            throw new \RuntimeException(__('A reference is required for card and mobile payments.', 'billnest'));
        }

        if (mb_strlen($reference) > self::MAX_REFERENCE) {
            throw new \RuntimeException(__('The reference is too long.', 'billnest'));
        }

        $note = $reference !== '' ? trim($remarks . ' (Ref: ' . $reference . ')') : $remarks;

        $wpdb->query('START TRANSACTION');

        try {
            $payment_id = $this->payments->create([
                'customer_id'  => $customer_id,
                'amount'       => $amount,
                'payment_date' => current_time('Y-m-d'),
                'method'       => $method,
                'remarks'      => $note,
                'created_by'   => $user_id,
            ]);

            if ($payment_id <= 0) {
                throw new \RuntimeException('Could not save the payment.');
            }

            $remaining = $amount;

            foreach ($this->invoices->get_open_invoices_for_customer($customer_id) as $invoice) {
                if ($remaining <= 0.004) {
                    break;
                }

                $apply = round(min($remaining, (float) $invoice->due_amount), 2);

                if ($apply <= 0) {
                    continue;
                }

                $this->invoices->apply_payment((int) $invoice->id, $apply);

                $this->invoice_payments->create(
                    (int) $invoice->id,
                    $method,
                    $apply,
                    $reference !== '' ? 'Due collection: ' . $reference : 'Due collection'
                );

                $remaining = round($remaining - $apply, 2);
            }

            $this->customers->increment_due($customer_id, -$amount);

            if ($method === 'cash') {
                $this->cashbook->create([
                    'type'       => 'in',
                    'amount'     => $amount,
                    'source'     => 'payment',
                    'ref_id'     => $payment_id,
                    'remarks'    => 'Due collection: ' . $customer->name,
                    'created_by' => $user_id,
                ]);
            }

            $wpdb->query('COMMIT');

            return $payment_id;
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }
}