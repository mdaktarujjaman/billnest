<?php

namespace BillNest\Services;

use BillNest\DB\CashbookRepository;
use BillNest\DB\CustomerRepository;
use BillNest\DB\InvoicePaymentRepository;
use BillNest\DB\InvoiceRepository;
use BillNest\DB\InvoiceReturnRepository;

if (! defined('ABSPATH')) {
    exit;
}

class ReturnService
{

    private const EPSILON = 0.005;

    private InvoiceRepository $invoices;
    private CustomerRepository $customers;
    private InvoicePaymentRepository $payments;
    private InvoiceReturnRepository $returns;
    private CashbookRepository $cashbook;
    private StockService $stock;

    public function __construct()
    {
        $this->invoices  = new InvoiceRepository();
        $this->customers = new CustomerRepository();
        $this->payments  = new InvoicePaymentRepository();
        $this->returns   = new InvoiceReturnRepository();
        $this->cashbook  = new CashbookRepository();
        $this->stock     = new StockService();
    }

    public function process_full_return(int $invoice_id): void
    {
        $this->process($invoice_id, null);
    }

    /**
     * @param array<int, float|string> $quantities invoice_item_id => quantity to return
     */
    public function process_partial_return(int $invoice_id, array $quantities): void
    {
        $this->process($invoice_id, $quantities);
    }

    private function process(int $invoice_id, ?array $requested): void
    {
        global $wpdb;

        $wpdb->query('START TRANSACTION');

        try {
            $invoice = $this->invoices->get_for_update($invoice_id);

            if (! $invoice || $invoice->status !== 'completed') {
                throw new \RuntimeException('This invoice cannot be returned.');
            }

            $items    = $this->invoices->get_items($invoice_id);
            $returned = $this->returns->get_returned_qty_by_item($invoice_id);
            $lines    = $this->build_lines($items, $returned, $requested);

            if (empty($lines)) {
                throw new \InvalidArgumentException('Nothing to return.');
            }

            $fully  = $this->is_fully_returned($items, $returned, $lines);
            $totals = $this->calculate_totals($invoice, $lines, $fully);

            $refunds = $this->allocate_refunds(
                $invoice,
                $this->payments->get_by_invoice($invoice_id),
                $totals['refund_total']
            );

            $return_id = $this->returns->create([
                'invoice_id'     => $invoice_id,
                'items_total'    => $totals['items_total'],
                'discount_share' => $totals['discount_share'],
                'return_total'   => $totals['return_total'],
                'due_reduced'    => $totals['due_reduced'],
                'refund_total'   => $totals['refund_total'],
                'remarks'        => '',
                'created_by'     => get_current_user_id(),
            ]);

            foreach ($lines as $line) {
                $this->returns->create_item($return_id, $line);

                $this->stock->increment_stock(
                    $line['product_id'],
                    $line['qty'],
                    'sales_return',
                    $invoice_id,
                    'invoice'
                );
            }

            if ($totals['due_reduced'] > 0) {
                $this->invoices->reduce_due($invoice_id, $totals['due_reduced']);

                if ($invoice->customer_id) {
                    $this->customers->increment_due((int) $invoice->customer_id, -$totals['due_reduced']);
                }
            }

            foreach ($refunds as $method => $amount) {
                $this->payments->create($invoice_id, (string) $method, -$amount, 'Refund');
            }

            $cash_refund = (float) ($refunds['cash'] ?? 0);

            if ($cash_refund > 0) {
                $this->cashbook->create([
                    'type'       => 'out',
                    'amount'     => $cash_refund,
                    'source'     => 'sales_return',
                    'ref_id'     => $invoice_id,
                    'remarks'    => 'Return for invoice ' . $invoice->invoice_no,
                    'created_by' => get_current_user_id(),
                ]);
            }

            if ($fully) {
                $this->invoices->update_status($invoice_id, 'returned');
            }

            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }

    private function build_lines(array $items, array $returned, ?array $requested): array
    {
        $known = [];

        foreach ($items as $item) {
            $known[(int) $item->id] = true;
        }

        if ($requested !== null) {
            foreach (array_keys($requested) as $item_id) {
                if (! isset($known[(int) $item_id])) {
                    throw new \InvalidArgumentException('Unknown invoice item.');
                }
            }
        }

        $lines = [];

        foreach ($items as $item) {
            $item_id = (int) $item->id;
            $left    = round((float) $item->qty - (float) ($returned[$item_id] ?? 0), 2);
            $qty     = $requested === null ? $left : round((float) ($requested[$item_id] ?? 0), 2);

            if ($qty < 0) {
                throw new \InvalidArgumentException('Return quantity cannot be negative.');
            }

            if ($qty <= 0) {
                continue;
            }

            if ($qty - $left > self::EPSILON) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'Cannot return %s of "%s", only %s left.',
                        $this->format_qty($qty),
                        (string) ($item->product_name ?: 'item'),
                        $this->format_qty(max(0.0, $left))
                    )
                );
            }

            $unit_price = round((float) $item->unit_price, 2);

            $lines[$item_id] = [
                'item_id'    => $item_id,
                'product_id' => (int) $item->product_id,
                'qty'        => $qty,
                'unit_price' => $unit_price,
                'amount'     => round($qty * $unit_price, 2),
            ];
        }

        return $lines;
    }

    private function is_fully_returned(array $items, array $returned, array $lines): bool
    {
        foreach ($items as $item) {
            $item_id = (int) $item->id;
            $left    = (float) $item->qty - (float) ($returned[$item_id] ?? 0);
            $now     = (float) ($lines[$item_id]['qty'] ?? 0);

            if ($left - $now > self::EPSILON) {
                return false;
            }
        }

        return true;
    }

    private function calculate_totals(object $invoice, array $lines, bool $fully): array
    {
        $items_total = round(array_sum(array_column($lines, 'amount')), 2);
        $total       = round((float) $invoice->total, 2);
        $subtotal    = (float) $invoice->subtotal;
        $already     = round($this->returns->get_returned_total((int) $invoice->id), 2);
        $room        = max(0.0, round($total - $already, 2));

        if ($fully) {
            $return_total = $room;
        } else {
            $share        = $subtotal > 0 ? round($items_total * (float) $invoice->discount_amount / $subtotal, 2) : 0.0;
            $return_total = min($room, max(0.0, round($items_total - $share, 2)));
        }

        $discount_share = max(0.0, round($items_total - $return_total, 2));
        $invoice_due    = max(0.0, round((float) $invoice->due_amount, 2));
        $due_reduced    = round(min($return_total, $invoice_due), 2);
        $refund_total   = round($return_total - $due_reduced, 2);

        return [
            'items_total'    => $items_total,
            'discount_share' => $discount_share,
            'return_total'   => $return_total,
            'due_reduced'    => $due_reduced,
            'refund_total'   => $refund_total,
        ];
    }

    private function allocate_refunds(object $invoice, array $payments, float $refund_total): array
    {
        if ($refund_total <= 0) {
            return [];
        }

        $paid_by     = [];
        $refunded_by = [];

        foreach ($payments as $payment) {
            $amount = round((float) $payment->amount, 2);
            $method = (string) $payment->method;

            if ($amount > 0) {
                $paid_by[$method] = round(($paid_by[$method] ?? 0) + $amount, 2);
            } elseif ($amount < 0) {
                $refunded_by[$method] = round(($refunded_by[$method] ?? 0) + abs($amount), 2);
            }
        }

        if (empty($paid_by)) {
            $received = round((float) $invoice->received_amount, 2);

            if ($received > 0) {
                $paid_by['cash'] = $received;
            }
        }

        $refundable = [];

        foreach ($paid_by as $method => $paid) {
            $left = round($paid - ($refunded_by[$method] ?? 0), 2);

            if ($left > 0) {
                $refundable[$method] = $left;
            }
        }

        $pool = round(array_sum($refundable), 2);

        if ($refund_total - $pool > self::EPSILON) {
            throw new \RuntimeException('The refund is more than the payments recorded for this invoice.');
        }

        $refunds   = [];
        $remaining = $refund_total;
        $last      = array_key_last($refundable);

        foreach ($refundable as $method => $left) {
            $share = $method === $last ? $remaining : round($refund_total * $left / $pool, 2);
            $share = round(min($share, $left, $remaining), 2);

            if ($share > 0) {
                $refunds[$method] = $share;
                $remaining        = round($remaining - $share, 2);
            }
        }

        foreach ($refundable as $method => $left) {
            if ($remaining <= 0) {
                break;
            }

            $extra = round(min($left - ($refunds[$method] ?? 0), $remaining), 2);

            if ($extra > 0) {
                $refunds[$method] = round(($refunds[$method] ?? 0) + $extra, 2);
                $remaining        = round($remaining - $extra, 2);
            }
        }

        if ($remaining > self::EPSILON) {
            throw new \RuntimeException('Could not split the refund across the original payment methods.');
        }

        return $refunds;
    }

    private function format_qty(float $qty): string
    {
        return rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.');
    }
}