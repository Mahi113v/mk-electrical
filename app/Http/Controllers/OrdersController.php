<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrdersController extends Controller
{
    public function __construct(private OrderService $orders)
    {
    }

    public static function rules(bool $allowRate = true): array
    {
        return [
            'order_name'         => ['required', 'string', 'max:255'],
            'order_date'         => ['nullable', 'date'],
            'customer_name'      => ['required', 'string', 'max:255'],
            'customer_phone'     => ['required', 'string', 'max:15'],
            'whatsapp'           => ['nullable', 'string', 'max:15'],
            'alternate_phone'    => ['nullable', 'string', 'max:15'],
            'area'               => ['nullable', 'string', 'max:255'],
            'city'               => ['nullable', 'string', 'max:255'],
            'address'            => ['nullable', 'string', 'max:500'],
            'service_charge'     => ['nullable', 'numeric', 'min:0'],
            'referral'           => ['nullable', 'string', 'max:255'],
            'notes'              => ['nullable', 'string', 'max:2000'],
            'status'             => ['nullable', Rule::in(Order::STATUSES)],
            'items'              => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.quantity'   => ['required', 'numeric', 'min:0.01'],
            'items.*.rate'       => $allowRate ? ['nullable', 'numeric', 'min:0'] : ['prohibited'],
        ];
    }

    /** Admin: any order. User: only the orders they created. */
    private function canManage(Request $request, Order $order): bool
    {
        $user = $request->user();

        return $user->is_admin || $order->user_id === $user->id;
    }

    private function normalise(Request $request): void
    {
        $request->merge(['status' => strtolower($request->input('status') ?: 'pending')]);
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $orders = Order::with(['user:id,name', 'customer:id,customer_name,customer_phone', 'items.product:id,product_name,product_code'])
                ->when($request->filled('search'), function ($q) use ($request) {
                    $s = $request->search;
                    $q->where(fn ($w) => $w->where('order_name', 'LIKE', "%{$s}%")
                        ->orWhere('customer_name', 'LIKE', "%{$s}%")
                        ->orWhere('customer_phone', 'LIKE', "%{$s}%"));
                })
                ->when($request->filled('status'), fn ($q) => $q->where('status', strtolower($request->status)))
                ->when($request->filled('source'), fn ($q) => $q->where('source', $request->source))
                ->orderByDesc('id')->get();

            return $this->ok($orders, 'Order fetched successfully');
        } catch (\Throwable $e) {
            return $this->serverError($e, 'Order fetch server error');
        }
    }

    public function show(Order $order): JsonResponse
    {
        return $this->ok($order->load(['user:id,name', 'customer', 'items.product']));
    }

    public function store(Request $request): JsonResponse
    {
        $this->normalise($request);
        $data = $request->validate(self::rules());

        try {
            $order = $this->orders->save(null, $data, $request->user()->id, true);

            return $this->ok($order, 'Order created successfully', 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->serverError($e, 'Server error while creating order');
        }
    }

    public function update(Request $request, Order $order): JsonResponse
    {
        if (!$this->canManage($request, $order)) {
            return $this->fail('You can only edit your own orders.', 403);
        }

        $this->normalise($request);
        $data = $request->validate(self::rules());

        try {
            $order = $this->orders->save($order, $data, $order->user_id, true, $order->source);

            return $this->ok($order, 'Order updated successfully');
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->serverError($e, 'Server error while updating order');
        }
    }

    public function updateStatus(Request $request, Order $order): JsonResponse
    {
        $request->merge(['status' => strtolower((string) $request->status)]);
        $data = $request->validate(['status' => ['required', Rule::in(Order::STATUSES)]]);

        $order->update($data);

        return $this->ok($order->fresh(), 'Order status updated');
    }

    public function destroy(Request $request, Order $order): JsonResponse
    {
        if (!$this->canManage($request, $order)) {
            return $this->fail('You can only delete your own orders.', 403);
        }

        $this->orders->delete($order);

        return $this->ok(null, 'Order deleted successfully');
    }

    /** Printable invoice (open the returned HTML in a new tab and press Ctrl+P). */
    public function print(Order $order)
    {
        $order->load(['items.product', 'user:id,name']);

        return response()->view('orders.print', ['order' => $order]);
    }
}
