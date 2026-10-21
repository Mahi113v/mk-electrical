<?php

namespace App\Services;

use App\Models\AccountYear;
use App\Models\Company;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stock = opening + purchases - sales - orders + adjustments   (per company + account year)
 *
 * Cancelled orders do not need special handling here: cancelling an order creates an automatic
 * stock adjustment (+qty), so the numbers stay right and the history is visible.
 */
class StockService
{
    /**
     * @param string|null $from,$to  only count documents dated inside this range (inclusive)
     * @param bool        $withOpening  include the year's opening stock (false = only the movements)
     * @return array<int, array{opening: float, purchased: float, sold: float, ordered: float, adjusted: float, closing: float}>
     *         keyed by product_id (only products that have any movement or opening stock)
     */
    public function movements(int $companyId, int $yearId, ?array $productIds = null, ?string $from = null, ?string $to = null, bool $withOpening = true): array
    {
        $rows = [];
        $blank = ['opening' => 0.0, 'purchased' => 0.0, 'sold' => 0.0, 'ordered' => 0.0, 'adjusted' => 0.0, 'closing' => 0.0];
        $add = function ($query, string $key, ?string $dateColumn = null) use (&$rows, $blank, $productIds, $from, $to) {
            if ($productIds !== null) {
                $query->whereIn($query->from === 'opening_stocks' ? 'product_id' : $this->productColumn($query), $productIds);
            }
            if ($dateColumn && $from) {
                $query->whereDate($dateColumn, '>=', $from);
            }
            if ($dateColumn && $to) {
                $query->whereDate($dateColumn, '<=', $to);
            }
            foreach ($query->groupBy($this->productColumn($query))->pluck('qty', 'product_id') as $pid => $qty) {
                $rows[$pid] ??= $blank;
                $rows[$pid][$key] += (float) $qty;
            }
        };

        if ($withOpening) {
            $add(DB::table('opening_stocks')->where('company_id', $companyId)->where('account_year_id', $yearId)
                ->select('product_id', DB::raw('SUM(quantity) as qty')), 'opening');
        }

        $add(DB::table('purchase_items')->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->where('purchases.company_id', $companyId)->where('purchases.account_year_id', $yearId)
            ->select('purchase_items.product_id', DB::raw('SUM(purchase_items.quantity) as qty')), 'purchased', 'purchases.purchase_date');

        $add(DB::table('sale_items')->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.company_id', $companyId)->where('sales.account_year_id', $yearId)
            ->select('sale_items.product_id', DB::raw('SUM(sale_items.quantity) as qty')), 'sold', 'sales.sale_date');

        $add(DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.company_id', $companyId)->where('orders.account_year_id', $yearId)
            ->select('order_items.product_id', DB::raw('SUM(order_items.quantity) as qty')), 'ordered', 'orders.order_date');

        $add(DB::table('stock_adjustment_items')->join('stock_adjustments', 'stock_adjustments.id', '=', 'stock_adjustment_items.stock_adjustment_id')
            ->where('stock_adjustments.company_id', $companyId)->where('stock_adjustments.account_year_id', $yearId)
            ->select('stock_adjustment_items.product_id', DB::raw('SUM(stock_adjustment_items.quantity_change) as qty')), 'adjusted', 'stock_adjustments.adjustment_date');

        foreach ($rows as $pid => &$r) {
            $r['closing'] = round($r['opening'] + $r['purchased'] - $r['sold'] - $r['ordered'] + $r['adjusted'], 2);
        }

        return $rows;
    }

    /** the product column of the query's first select ("purchase_items.product_id" or "product_id") */
    private function productColumn($query): string
    {
        $first = $query->columns[0] ?? 'product_id';

        return is_string($first) ? $first : 'product_id';
    }

    /**
     * Stock register for a period: opening at the FROM date, movements between FROM and TO, closing at the TO date.
     * Opening at the from date = the year's opening stock + everything that moved before the from date.
     */
    public function register(int $companyId, int $yearId, string $yearStart, string $from, string $to): array
    {
        $opening = DB::table('opening_stocks')->where('company_id', $companyId)->where('account_year_id', $yearId)
            ->groupBy('product_id')->select('product_id', DB::raw('SUM(quantity) as qty'))->pluck('qty', 'product_id');

        $beforeEnd = date('Y-m-d', strtotime($from . ' -1 day'));
        $before = $from > $yearStart ? $this->movements($companyId, $yearId, null, $yearStart, $beforeEnd, false) : [];
        $during = $this->movements($companyId, $yearId, null, $from, $to, false);

        $rows = [];
        foreach (collect($opening->keys())->merge(array_keys($before))->merge(array_keys($during))->unique() as $pid) {
            $b = $before[$pid] ?? null;
            $d = $during[$pid] ?? ['purchased' => 0.0, 'sold' => 0.0, 'ordered' => 0.0, 'adjusted' => 0.0];
            $open = (float) ($opening[$pid] ?? 0) + ($b ? $b['purchased'] - $b['sold'] - $b['ordered'] + $b['adjusted'] : 0);

            $rows[$pid] = [
                'opening'   => round($open, 2),
                'purchased' => $d['purchased'], 'sold' => $d['sold'], 'ordered' => $d['ordered'], 'adjusted' => $d['adjusted'],
                'closing'   => round($open + $d['purchased'] - $d['sold'] - $d['ordered'] + $d['adjusted'], 2),
            ];
        }

        return $rows;
    }

    /** Current stock of the given products (0 for products without any movement) */
    public function available(int $companyId, int $yearId, array $productIds): array
    {
        $m = $this->movements($companyId, $yearId, $productIds);

        return collect($productIds)->mapWithKeys(fn ($id) => [$id => (float) ($m[$id]['closing'] ?? 0)])->all();
    }

    /**
     * Throws a validation error when any product's stock is below zero.
     * Call it INSIDE the transaction, AFTER the change was written - the transaction rolls back on failure.
     * Product rows are locked first so two people cannot sell the last piece at the same time.
     */
    public function assertNotNegative(Company $company, int $yearId, array $productIds, string $field = 'items'): void
    {
        if ($company->allow_negative_stock || !$productIds) {
            return;
        }

        Product::whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get();
        $available = $this->available($company->id, $yearId, $productIds);
        $names = Product::whereIn('id', $productIds)->pluck('product_name', 'id');

        foreach ($available as $pid => $qty) {
            if ($qty < 0) {
                throw ValidationException::withMessages([
                    $field => [sprintf(
                        'Not enough stock for %s (short by %s). Add a purchase or opening stock first.',
                        $names[$pid] ?? "#$pid",
                        rtrim(rtrim(number_format(abs($qty), 2, '.', ''), '0'), '.')
                    )],
                ]);
            }
        }
    }

    /** Closing stock of $year for every product - used as next year's opening stock */
    public function closingOfYear(Company $company, AccountYear $year, int $depth = 0): array
    {
        $movements = $this->movements($company->id, $year->id);
        $hasStoredOpening = DB::table('opening_stocks')->where('company_id', $company->id)->where('account_year_id', $year->id)->exists();

        // no opening stock saved for that year: derive it from the year before (safe guard against endless loops)
        if (!$hasStoredOpening && $depth < 15 && ($previous = $year->previous())) {
            foreach ($this->closingOfYear($company, $previous, $depth + 1) as $pid => $qty) {
                $movements[$pid] ??= ['opening' => 0.0, 'purchased' => 0.0, 'sold' => 0.0, 'ordered' => 0.0, 'adjusted' => 0.0, 'closing' => 0.0];
                $movements[$pid]['opening'] += $qty;
                $movements[$pid]['closing'] = round($movements[$pid]['closing'] + $qty, 2);
            }
        }

        return array_map(fn ($r) => $r['closing'], $movements);
    }
}
