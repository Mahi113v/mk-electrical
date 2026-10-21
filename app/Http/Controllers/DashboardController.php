<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;

/** Company dashboard (selected company + year) */
class DashboardController extends Controller
{
    public function index(StockService $stock): JsonResponse
    {
        try {
            $t = $this->tenant();
            $orders = Order::forTenant();

            $byStatus = (clone $orders)->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');

            $movements = $stock->movements($t->companyId(), $t->yearId());
            $names = Product::pluck('product_name', 'id');
            $low = collect($movements)->filter(fn ($r) => $r['closing'] <= 5)
                ->map(fn ($r, $pid) => ['id' => $pid, 'product_name' => $names[$pid] ?? "#$pid", 'stock' => $r['closing']])
                ->sortBy('stock')->take(8)->values();

            // 12 months of the account year, April to March
            $start = $t->year->start_date->copy();
            $orderRows = (clone $orders)->where('status', 'completed')->get(['order_date', 'total'])->groupBy(fn ($o) => optional($o->order_date)->format('Y-m'));
            $saleRows = Sale::forTenant()->get(['sale_date', 'total'])->groupBy(fn ($s) => optional($s->sale_date)->format('Y-m'));
            $monthly = collect(range(0, 11))->map(function ($i) use ($start, $orderRows, $saleRows) {
                $m = $start->copy()->addMonths($i);
                $key = $m->format('Y-m');

                return [
                    'month' => $m->format('M'),
                    'total' => (float) (($orderRows[$key] ?? collect())->sum('total') + ($saleRows[$key] ?? collect())->sum('total')),
                ];
            });

            return $this->ok([
                'orders_total'     => (clone $orders)->count(),
                'orders_by_status' => collect(Order::STATUSES)->mapWithKeys(fn ($s) => [$s => (int) ($byStatus[$s] ?? 0)]),
                'revenue_orders'   => (float) (clone $orders)->where('status', 'completed')->sum('total'),
                'sales_total'      => (float) Sale::forTenant()->sum('total'),
                'purchase_total'   => (float) Purchase::forTenant()->sum('total'),
                'pending_amount'   => (float) (clone $orders)->whereIn('status', ['pending', 'upcoming', 'active'])->sum('total'),
                'customers'        => Customer::count(),
                'products'         => Product::count(),
                'stock_units'      => round(collect($movements)->sum(fn ($r) => max(0, $r['closing'])), 2),
                'low_stock'        => $low,
                'monthly_revenue'  => $monthly,
                'recent_orders'    => (clone $orders)->latest('id')->limit(6)
                    ->get(['id', 'order_name', 'customer_name', 'total', 'status', 'order_date', 'source']),
                'recent_sales'     => Sale::forTenant()->latest('id')->limit(5)->get(['id', 'sale_no', 'customer_name', 'reference', 'total', 'sale_date']),
            ]);
        } catch (\Throwable $e) {
            return $this->serverError($e, 'Could not load dashboard');
        }
    }
}
