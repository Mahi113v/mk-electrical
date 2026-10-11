<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $user    = $request->user();
            $isAdmin = (bool) $user->is_admin;

            // normal users only see their own numbers, admin sees everything
            $orders = Order::query()->when(!$isAdmin, fn ($q) => $q->where('user_id', $user->id));

            $byStatus = (clone $orders)->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');

            $monthStart = now()->startOfMonth()->toDateString();

            $stats = [
                'orders_total'   => (clone $orders)->count(),
                'orders_by_status' => collect(Order::STATUSES)->mapWithKeys(fn ($s) => [$s => (int) ($byStatus[$s] ?? 0)]),
                'revenue_total'  => (float) (clone $orders)->where('status', 'completed')->sum('total'),
                'revenue_month'  => (float) (clone $orders)->where('status', 'completed')
                                        ->whereDate('order_date', '>=', $monthStart)->sum('total'),
                'pending_amount' => (float) (clone $orders)->whereIn('status', ['pending', 'upcoming', 'active'])->sum('total'),
                'customers'      => Customer::count(),
                'products'       => Product::count(),
                'low_stock'      => Product::whereNotNull('stock')->where('stock', '<=', 5)
                                        ->orderBy('stock')->limit(8)->get(['id', 'product_name', 'product_code', 'stock']),
                'recent_orders'  => (clone $orders)->with('user:id,name')->latest('id')->limit(6)
                                        ->get(['id', 'order_name', 'customer_name', 'total', 'status', 'order_date', 'user_id', 'source']),
            ];

            // last 6 months revenue (grouped in PHP so it works on MySQL and SQLite)
            $from = now()->subMonths(5)->startOfMonth();
            $rows = (clone $orders)->where('status', 'completed')
                ->whereDate('order_date', '>=', $from->toDateString())
                ->get(['order_date', 'total'])
                ->groupBy(fn ($o) => optional($o->order_date)->format('Y-m'));

            $stats['monthly_revenue'] = collect(range(0, 5))->map(function ($i) use ($from, $rows) {
                $m = $from->copy()->addMonths($i);

                return [
                    'month' => $m->format('M'),
                    'total' => (float) ($rows[$m->format('Y-m')] ?? collect())->sum('total'),
                ];
            })->values();

            if ($isAdmin) {
                $stats['top_products'] = OrderItem::selectRaw('product_id, SUM(quantity) as qty, SUM(total) as amount')
                    ->groupBy('product_id')->orderByDesc('amount')->limit(5)->with('product:id,product_name')->get()
                    ->map(fn ($i) => [
                        'product' => $i->product?->product_name ?? 'Deleted product',
                        'qty'     => (float) $i->qty,
                        'amount'  => (float) $i->amount,
                    ]);
                $stats['users'] = User::count();
                $stats['web_orders_pending'] = Order::where('source', 'web')->where('status', 'pending')->count();
            }

            return $this->ok($stats);
        } catch (\Throwable $e) {
            return $this->serverError($e, 'Could not load dashboard');
        }
    }
}
