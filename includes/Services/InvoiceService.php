<?php

namespace BillNest\Services;

use BillNest\DB\InvoiceRepository;
use BillNest\DB\CustomerRepository;
use BillNest\DB\CashbookRepository;

if (! defined('ABSPATH')) {
    exit;
}

class InvoiceService
{

    private InvoiceRepository $invoices;
    private CustomerRepository $customers;
    private NumberingService $numbering;
    private StockService $stock;

    public function __construct()
    {
        $this->invoices  = new InvoiceRepository();
        $this->customers = new CustomerRepository();
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
        $received        = min((float) $data['received_amount'], $total);
        $due_amount      = $total - $received;

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

            if ($data['customer_id']) {
                $this->customers->increment_due($data['customer_id'], $due_amount);
            }

            if ($received > 0) {
                (new CashbookRepository())->create([
                    'type'       => 'in',
                    'amount'     => $received,
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

    private function calculate_discount(float $subtotal, ?string $type, float $value): float
    {
        if ($type === 'percent') {
            return round($subtotal * ($value / 100), 2);
        }

        if ($type === 'flat') {
            return $value;
        }

        return 0;
    }
}