<?php

namespace BillNest\Services;

use BillNest\DB\InvoiceRepository;
use BillNest\DB\CustomerRepository;
use BillNest\DB\CashbookRepository;
use BillNest\DB\InvoicePaymentRepository;

if (! defined('ABSPATH')) {
    exit;
}

class InvoiceService
{

    private InvoiceRepository $invoices;
    private CustomerRepository $customers;
    private InvoicePaymentRepository $payments;
    private NumberingService $numbering;
    private StockService $stock;

    public function __construct()
    {
        $this->invoices  = new InvoiceRepository();
        $this->customers = new CustomerRepository();
        $this->payments  = new InvoicePaymentRepository();
        $this->numbering = new NumberingService();
        $this->stock     = new StockService();
    }

    public function create_invoice(array $data): int
    {
        global $wpdb;

        $subtotal = 0;
        foreach ($data['items'] as $item) {
            $subtotal += $item['qty'] * $item['unit_price'];
        }

        $discount_amount = $this->calculate_discount($subtotal, $data['discount_type'], $data['discount_value']);
        $total           = $subtotal - $discount_amount;
        $payments        = $this->prepare_payments($data, $total);
        $received        = round(array_sum(array_column($payments, 'amount')), 2);
        $cash_received   = $this->sum_by_method($payments, 'cash');
        $due_amount      = round($total - $received, 2);

        $wpdb->query('START TRANSACTION');

        try {
            $invoice_no = $this->numbering->get_next_invoice_number();

            $invoice_id = $this->invoices->create_invoice([
                'invoice_no'      => $invoice_no,
                'customer_id'     => $data['customer_id'],
                'invoice_date'    => current_time('Y-m-d'),
                'subtotal'        => $subtotal,
                'discount_type'   => $data['discount_type'],
                'discount_value'  => $data['discount_value'],
                'discount_amount' => $discount_amount,
                'total'           => $total,
                'received_amount' => $received,
                'due_amount'      => $due_amount,
                'remarks'         => $data['remarks'],
                'created_by'      => $data['created_by'],
            ]);

            foreach ($data['items'] as $item) {
                $item_total = $item['qty'] * $item['unit_price'];

                $this->invoices->create_item($invoice_id, [
                    'product_id' => $item['product_id'],
                    'qty'        => $item['qty'],
                    'unit_price' => $item['unit_price'],
                    'total'      => $item_total,
                ]);

                $this->stock->decrement_stock($item['product_id'], $item['qty'], 'sale', $invoice_id, 'invoice');
            }

            foreach ($payments as $payment) {
                $this->payments->create($invoice_id, $payment['method'], $payment['amount'], $payment['reference']);
            }

            if ($data['customer_id']) {
                $this->customers->increment_due($data['customer_id'], $due_amount);
            }

            if ($cash_received > 0) {
                (new CashbookRepository())->create([
                    'type'       => 'in',
                    'amount'     => $cash_received,
                    'source'     => 'sale',
                    'ref_id'     => $invoice_id,
                    'remarks'    => 'Invoice ' . $invoice_no,
                    'created_by' => $data['created_by'],
                ]);
            }

            $wpdb->query('COMMIT');

            return $invoice_id;
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }

    private function prepare_payments(array $data, float $total): array
    {
        $lines = $data['payments'] ?? [];

        if (empty($lines) && (float) ($data['received_amount'] ?? 0) > 0) {
            $lines = [[
                'method'    => 'cash',
                'amount'    => $data['received_amount'],
                'reference' => null,
            ]];
        }

        $cash_total = 0.0;
        $non_cash   = [];

        foreach ($lines as $line) {
            $method    = (string) ($line['method'] ?? '');
            $amount    = round((float) ($line['amount'] ?? 0), 2);
            $reference = trim((string) ($line['reference'] ?? ''));

            if (! PaymentMethods::is_valid($method)) {
                throw new \InvalidArgumentException('Unknown payment method.');
            }

            if ($amount < 0) {
                throw new \InvalidArgumentException('Payment amount cannot be negative.');
            }

            if ($amount == 0) {
                continue;
            }

            if ($method === 'cash') {
                $cash_total += $amount;
                continue;
            }

            if (PaymentMethods::requires_reference($method) && $reference === '') {
                throw new \InvalidArgumentException('A reference is required for this payment method.');
            }

            $non_cash[] = [
                'method'    => $method,
                'amount'    => $amount,
                'reference' => $reference,
            ];
        }

        $non_cash_total = round(array_sum(array_column($non_cash, 'amount')), 2);

        if ($non_cash_total > round($total, 2)) {
            throw new \InvalidArgumentException('Non-cash payments cannot exceed the invoice total.');
        }

        $cash_kept = round(min($cash_total, max(0, $total - $non_cash_total)), 2);

        $result = [];

        if ($cash_kept > 0) {
            $result[] = [
                'method'    => 'cash',
                'amount'    => $cash_kept,
                'reference' => null,
            ];
        }

        return array_merge($result, $non_cash);
    }

    private function sum_by_method(array $payments, string $method): float
    {
        $sum = 0.0;

        foreach ($payments as $payment) {
            if ($payment['method'] === $method) {
                $sum += $payment['amount'];
            }
        }

        return round($sum, 2);
    }

    private function calculate_discount(float $subtotal, ?string $type, float $value): float
    {
        $value = max(0, $value);

        if ($type === 'percent') {
            $value = min($value, 100);

            return round($subtotal * ($value / 100), 2);
        }

        if ($type === 'flat') {
            return round(min($value, $subtotal), 2);
        }

        return 0;
    }
}