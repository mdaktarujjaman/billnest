<?php

namespace BillNest\Services;

use BillNest\DB\CashbookRepository;
use BillNest\DB\ProductRepository;
use BillNest\DB\PurchaseRepository;
use BillNest\DB\SupplierRepository;

if (! defined('ABSPATH')) {
    exit;
}

class PurchaseService
{

    private const EPSILON       = 0.005;
    private const MAX_REFERENCE = 60;

    private PurchaseRepository $purchases;
    private ProductRepository $products;
    private SupplierRepository $suppliers;
    private CashbookRepository $cashbook;
    private StockService $stock;

    public function __construct()
    {
        $this->purchases = new PurchaseRepository();
        $this->products  = new ProductRepository();
        $this->suppliers = new SupplierRepository();
        $this->cashbook  = new CashbookRepository();
        $this->stock     = new StockService();
    }

    /**
     * @param array $data supplier_id, purchase_date, remarks, items[] (row, product_id, qty, unit_cost), payments[] (row, method, amount, reference)
     * @return string the new purchase number
     */
    public function create_purchase(array $data, int $user_id): string
    {
        global $wpdb;

        $date = (string) ($data['purchase_date'] ?? '');

        if (! $this->is_valid_date($date)) {
            throw new \InvalidArgumentException(__('Enter a valid purchase date.', 'billnest'));
        }

        $supplier_id = (int) ($data['supplier_id'] ?? 0);

        if ($supplier_id > 0) {
            $supplier = $this->suppliers->get_by_id($supplier_id);

            if (! $supplier || $supplier->status !== 'active') {
                throw new \InvalidArgumentException(__('Choose a valid supplier.', 'billnest'));
            }
        }

        $lines    = $this->prepare_lines((array) ($data['items'] ?? []));
        $payments = $this->prepare_payments((array) ($data['payments'] ?? []));

        $total = round(array_sum(array_column($lines, 'total')), 2);
        $paid  = round(array_sum(array_column($payments, 'amount')), 2);

        if ($paid - $total > self::EPSILON) {
            throw new \InvalidArgumentException(__('The payments are more than the purchase total.', 'billnest'));
        }

        $due = round($total - $paid, 2);

        if ($due <= self::EPSILON) {
            $due = 0.0;
        }

        if ($due > 0 && $supplier_id === 0) {
            throw new \InvalidArgumentException(__('Choose a supplier to leave a balance, or pay the full amount.', 'billnest'));
        }

        $cash = 0.0;

        foreach ($payments as $payment) {
            if ($payment['method'] === 'cash') {
                $cash += $payment['amount'];
            }
        }

        $cash = round($cash, 2);

        $wpdb->query('START TRANSACTION');

        try {
            $purchase_no = (new NumberingService())->get_next_purchase_number();

            $purchase_id = $this->purchases->create([
                'purchase_no'   => $purchase_no,
                'supplier_id'   => $supplier_id,
                'purchase_date' => $date,
                'subtotal'      => $total,
                'total'         => $total,
                'paid_amount'   => $paid,
                'due_amount'    => $due,
                'remarks'       => (string) ($data['remarks'] ?? ''),
                'created_by'    => $user_id,
            ]);

            foreach ($lines as $line) {
                $this->purchases->create_item($purchase_id, $line);

                $this->stock->increment_stock(
                    $line['product_id'],
                    $line['qty'],
                    'purchase',
                    $purchase_id,
                    'purchase'
                );

                $this->products->set_purchase_price($line['product_id'], $line['unit_cost']);
            }

            foreach ($payments as $payment) {
                $this->purchases->create_payment(
                    $purchase_id,
                    $payment['method'],
                    $payment['amount'],
                    $payment['reference'] !== '' ? $payment['reference'] : null
                );
            }

            if ($cash > 0) {
                $this->cashbook->create([
                    'type'       => 'out',
                    'amount'     => $cash,
                    'source'     => 'purchase',
                    'ref_id'     => $purchase_id,
                    'remarks'    => 'Purchase ' . $purchase_no,
                    'created_by' => $user_id,
                ]);
            }

            if ($due > 0) {
                $this->suppliers->increment_payable($supplier_id, $due);
            }

            $wpdb->query('COMMIT');

            return $purchase_no;
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }

    private function prepare_lines(array $items): array
    {
        if (empty($items)) {
            throw new \InvalidArgumentException(__('Add at least one product.', 'billnest'));
        }

        $lines = [];

        foreach (array_values($items) as $position => $item) {
            $row        = (int) ($item['row'] ?? ($position + 1));
            $product_id = (int) ($item['product_id'] ?? 0);
            $qty        = round((float) ($item['qty'] ?? 0), 2);
            $unit_cost  = round((float) ($item['unit_cost'] ?? 0), 2);

            $product = $product_id > 0 ? $this->products->get_by_id($product_id) : null;

            if (! $product || $product->status !== 'active') {
                throw new \InvalidArgumentException(sprintf(__('Line %d: choose an active product.', 'billnest'), $row));
            }

            if ($qty <= 0) {
                throw new \InvalidArgumentException(sprintf(__('Line %d: the quantity must be above zero.', 'billnest'), $row));
            }

            if ($unit_cost <= 0) {
                throw new \InvalidArgumentException(sprintf(__('Line %d: the unit cost must be above zero.', 'billnest'), $row));
            }

            $lines[] = [
                'product_id' => $product_id,
                'qty'        => $qty,
                'unit_cost'  => $unit_cost,
                'total'      => round($qty * $unit_cost, 2),
            ];
        }

        return $lines;
    }

    private function prepare_payments(array $payments): array
    {
        $result = [];

        foreach (array_values($payments) as $position => $payment) {
            $row       = (int) ($payment['row'] ?? ($position + 1));
            $method    = (string) ($payment['method'] ?? '');
            $amount    = round((float) ($payment['amount'] ?? 0), 2);
            $reference = trim((string) ($payment['reference'] ?? ''));

            if (! PaymentMethods::is_valid($method)) {
                throw new \InvalidArgumentException(sprintf(__('Payment %d: choose a valid method.', 'billnest'), $row));
            }

            if ($amount <= 0) {
                throw new \InvalidArgumentException(sprintf(__('Payment %d: the amount must be above zero.', 'billnest'), $row));
            }

            if (PaymentMethods::requires_reference($method) && $reference === '') {
                throw new \InvalidArgumentException(sprintf(__('Payment %d: a reference is required for card and mobile payments.', 'billnest'), $row));
            }

            if (mb_strlen($reference) > self::MAX_REFERENCE) {
                throw new \InvalidArgumentException(sprintf(__('Payment %d: the reference is too long.', 'billnest'), $row));
            }

            $result[] = [
                'method'    => $method,
                'amount'    => $amount,
                'reference' => $reference,
            ];
        }

        return $result;
    }

    private function is_valid_date(string $value): bool
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year);
    }
}