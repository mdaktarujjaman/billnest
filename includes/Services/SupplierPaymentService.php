<?php

namespace BillNest\Services;

use BillNest\DB\CashbookRepository;
use BillNest\DB\PurchaseRepository;
use BillNest\DB\SupplierPaymentRepository;
use BillNest\DB\SupplierRepository;

if (! defined('ABSPATH')) {
    exit;
}

class SupplierPaymentService
{

    private const EPSILON       = 0.005;
    private const MAX_REFERENCE = 60;

    private SupplierRepository $suppliers;
    private PurchaseRepository $purchases;
    private SupplierPaymentRepository $payments;
    private CashbookRepository $cashbook;

    public function __construct()
    {
        $this->suppliers = new SupplierRepository();
        $this->purchases = new PurchaseRepository();
        $this->payments  = new SupplierPaymentRepository();
        $this->cashbook  = new CashbookRepository();
    }

    public function pay(int $supplier_id, float $amount, string $method, string $remarks, int $user_id, string $reference = ''): int
    {
        global $wpdb;

        $amount    = round($amount, 2);
        $reference = trim($reference);

        $supplier = $this->suppliers->get_by_id($supplier_id);

        if (! $supplier || $supplier->status !== 'active') {
            throw new \InvalidArgumentException(__('Supplier not found.', 'billnest'));
        }

        if (! PaymentMethods::is_valid($method)) {
            throw new \InvalidArgumentException(__('Choose a valid payment method.', 'billnest'));
        }

        if ($amount <= 0) {
            throw new \InvalidArgumentException(__('Amount must be greater than zero.', 'billnest'));
        }

        if (PaymentMethods::requires_reference($method) && $reference === '') {
            throw new \InvalidArgumentException(__('A reference is required for card and mobile payments.', 'billnest'));
        }

        if (mb_strlen($reference) > self::MAX_REFERENCE) {
            throw new \InvalidArgumentException(__('The reference is too long.', 'billnest'));
        }

        $wpdb->query('START TRANSACTION');

        try {
            $locked = $this->suppliers->get_for_update($supplier_id);

            if (! $locked) {
                throw new \InvalidArgumentException(__('Supplier not found.', 'billnest'));
            }

            if ($amount - (float) $locked->current_payable > self::EPSILON) {
                throw new \InvalidArgumentException(__('Amount cannot be more than the balance owed to this supplier.', 'billnest'));
            }

            $payment_id = $this->payments->create([
                'supplier_id'  => $supplier_id,
                'amount'       => $amount,
                'method'       => $method,
                'reference'    => $reference,
                'remarks'      => $remarks,
                'payment_date' => current_time('Y-m-d'),
                'created_by'   => $user_id,
            ]);

            $remaining = $amount;

            foreach ($this->purchases->get_open_for_supplier($supplier_id) as $purchase) {
                if ($remaining <= 0.004) {
                    break;
                }

                $apply = round(min($remaining, (float) $purchase->due_amount), 2);

                if ($apply <= 0) {
                    continue;
                }

                $this->purchases->apply_payment((int) $purchase->id, $apply);
                $this->purchases->create_payment(
                    (int) $purchase->id,
                    $method,
                    $apply,
                    $reference !== '' ? $reference : null,
                    $payment_id
                );

                $remaining = round($remaining - $apply, 2);
            }

            $this->suppliers->increment_payable($supplier_id, -$amount);

            if ($method === 'cash') {
                $this->cashbook->create([
                    'type'       => 'out',
                    'amount'     => $amount,
                    'source'     => 'supplier_payment',
                    'ref_id'     => $payment_id,
                    'remarks'    => 'Supplier payment: ' . $supplier->name,
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