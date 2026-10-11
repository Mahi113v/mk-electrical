<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public (not logged in) order form for customers.
 * Old code required Auth::user() here, so every web order failed with 401.
 */
class WebOrderController extends Controller
{
    public function __construct(private OrderService $orders)
    {
    }

    /** Only what the public form needs - no stock numbers, no user info. */
    public function products(): JsonResponse
    {
        $products = Product::where(fn ($q) => $q->whereNull('stock')->orWhere('stock', '>', 0))
            ->orderBy('product_name')
            ->get(['id', 'product_name', 'product_code', 'rate']);

        return $this->ok($products);
    }

    public function create(Request $request): JsonResponse
    {
        $request->merge(['status' => 'pending']);   // customers cannot choose a status

        // customers can never set prices: drop any client supplied rate/amount/total
        $request->merge(['items' => collect($request->input('items', []))
            ->map(fn ($i) => is_array($i) ? \Illuminate\Support\Arr::only($i, ['product_id', 'quantity']) : $i)
            ->all()]);

        $rules = OrdersController::rules(true);
        $rules['order_name'] = ['nullable', 'string', 'max:255'];
        unset($rules['referral'], $rules['service_charge']);
        $data = $request->validate($rules);

        $data['order_name']     = $data['order_name'] ?? ('Web order - ' . $data['customer_name']);
        $data['service_charge'] = 0;

        try {
            $order = $this->orders->save(null, $data, null, false, 'web');

            return $this->ok(['id' => $order->id, 'total' => $order->total], 'Order placed successfully', 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->serverError($e, 'Server error while creating order');
        }
    }
}
