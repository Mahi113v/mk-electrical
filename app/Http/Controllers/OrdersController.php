<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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

    private function prepare(Request $request): void
    {
        // rows with quantity 0 are just empty rows of the form
        $request->merge([
            'status' => strtolower($request->input('status') ?: 'pending'),
            'items'  => collect($request->input('items', []))->filter(fn ($i) => (float) ($i['quantity'] ?? 0) > 0)->values()->all(),
        ]);
    }

    private function requireCancelPermission(?string $status): void
    {
        if ($status === 'cancelled' && !$this->tenant()->can('orders.cancel')) {
            throw new \Illuminate\Auth\Access\AuthorizationException('You do not have permission to cancel orders.');
        }
    }

    public function index(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        try {
            $orders = Order::forTenant()
                ->with(['user:id,name', 'customer:id,customer_name,customer_phone', 'items.product:id,product_name,product_code'])
                ->when($request->filled('search'), function ($q) use ($request) {
                    $s = $request->search;
                    $q->where(fn ($w) => $w->where('order_name', 'LIKE', "%{$s}%")
                        ->orWhere('customer_name', 'LIKE', "%{$s}%")
                        ->orWhere('customer_phone', 'LIKE', "%{$s}%"));
                })
                ->when($from, fn ($q) => $q->whereDate('order_date', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('order_date', '<=', $to))
                ->when($request->filled('status'), fn ($q) => $q->where('status', strtolower($request->status)))
                ->when($request->filled('source'), fn ($q) => $q->where('source', $request->source))
                ->orderByDesc('id')->get();

            return $this->ok($orders, 'Order fetched successfully');
        } catch (\Throwable $e) {
            return $this->serverError($e, 'Order fetch server error');
        }
    }

    public function show(int $id): JsonResponse
    {
        return $this->ok(Order::forTenant()->with(['user:id,name', 'customer', 'items.product'])->findOrFail($id));
    }

    public function store(Request $request): JsonResponse
    {
        $this->prepare($request);
        $data = $request->validate(self::rules());

        if ($data['status'] === 'cancelled') {
            throw ValidationException::withMessages(['status' => ['A new order cannot be cancelled.']]);
        }
        $data['order_date'] = $this->dateInYear($data['order_date'] ?? null, 'order_date');

        $t = $this->tenant();
        $order = $this->orders->save(null, $data, $t->company, $t->year, $t->user->id, true);

        return $this->ok($order, 'Order created successfully', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $order = Order::forTenant()->findOrFail($id);

        $this->prepare($request);
        $data = $request->validate(self::rules());
        $this->requireCancelPermission($data['status'] === 'cancelled' && $order->status !== 'cancelled' ? 'cancelled' : null);
        $data['order_date'] = $this->dateInYear($data['order_date'] ?? null, 'order_date');

        $t = $this->tenant();
        $order = $this->orders->save($order, $data, $t->company, $t->year, $order->user_id, true, $order->source);

        return $this->ok($order, 'Order updated successfully');
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $order = Order::forTenant()->findOrFail($id);

        $request->merge(['status' => strtolower((string) $request->status)]);
        $data = $request->validate(['status' => ['required', Rule::in(Order::STATUSES)]]);
        $this->requireCancelPermission($data['status']);

        $t = $this->tenant();
        $order = $this->orders->changeStatus($order, $data['status'], $t->company, $t->year, $t->user->id);

        return $this->ok($order->fresh(), 'Order status updated');
    }

    public function destroy(int $id): JsonResponse
    {
        $order = Order::forTenant()->findOrFail($id);
        $this->orders->delete($order);

        return $this->ok(null, 'Order deleted successfully');
    }

    /** Printable invoice (open the returned HTML in a new tab and press Ctrl+P). */
    public function print(int $id)
    {
        $order = Order::forTenant()->with(['items.product', 'user:id,name'])->findOrFail($id);

        return response()->view('orders.print', ['order' => $order, 'company' => $this->tenant()->company]);
    }
}
