<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Services\DocNumber;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Direct sales - not through an order. "reference" = who the goods were given to. */
class SalesController extends Controller
{
    public function __construct(private StockService $stock)
    {
    }

    private function rules(): array
    {
        return [
            'sale_date'          => ['nullable', 'date'],
            'customer_id'        => ['nullable', 'integer', 'exists:customers,id'],
            'customer_name'      => ['nullable', 'string', 'max:255'],
            'reference'          => ['required', 'string', 'max:255'],
            'payment_mode'       => ['nullable', 'in:cash,upi,card,credit,other'],
            'notes'              => ['nullable', 'string', 'max:2000'],
            'items'              => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity'   => ['required', 'numeric', 'min:0.01'],
            'items.*.rate'       => ['nullable', 'numeric', 'min:0'],
        ];
    }

    private function clean(Request $request): void
    {
        $request->merge(['items' => collect($request->input('items', []))
            ->filter(fn ($i) => (float) ($i['quantity'] ?? 0) > 0 && !empty($i['product_id']))->values()->all()]);
    }

    public function index(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);
        $rows = Sale::forTenant()->with(['items.product:id,product_name,product_code', 'user:id,name'])
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->search;
                $q->where(fn ($w) => $w->where('customer_name', 'LIKE', "%{$s}%")
                    ->orWhere('reference', 'LIKE', "%{$s}%")->orWhere('sale_no', 'LIKE', "%{$s}%"));
            })
            ->when($from, fn ($q) => $q->whereDate('sale_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('sale_date', '<=', $to))
            ->orderByDesc('sale_date')->orderByDesc('id')->get();

        return $this->ok($rows);
    }

    public function show(int $id): JsonResponse
    {
        return $this->ok(Sale::forTenant()->with(['items.product', 'user:id,name'])->findOrFail($id));
    }

    public function store(Request $request): JsonResponse
    {
        $this->clean($request);
        $data = $request->validate($this->rules());
        $data['sale_date'] = $this->dateInYear($data['sale_date'] ?? null, 'sale_date');

        return $this->ok(DB::transaction(fn () => $this->persist(null, $data)), 'Sale saved', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $sale = Sale::forTenant()->findOrFail($id);

        $this->clean($request);
        $data = $request->validate($this->rules());
        $data['sale_date'] = $this->dateInYear($data['sale_date'] ?? null, 'sale_date');

        return $this->ok(DB::transaction(fn () => $this->persist($sale, $data)), 'Sale updated');
    }

    public function destroy(int $id): JsonResponse
    {
        Sale::forTenant()->findOrFail($id)->delete();   // items cascade, stock comes back automatically

        return $this->ok(null, 'Sale deleted');
    }

    private function persist(?Sale $sale, array $data): Sale
    {
        $t = $this->tenant();

        if ($sale) {
            $sale->items()->delete();
        }

        $customer = !empty($data['customer_id']) ? Customer::find($data['customer_id']) : null;
        $products = Product::whereIn('id', collect($data['items'])->pluck('product_id'))->get()->keyBy('id');

        $merged = [];   // same product twice -> one line
        foreach ($data['items'] as $row) {
            $pid = (int) $row['product_id'];
            $merged[$pid]['quantity'] = ($merged[$pid]['quantity'] ?? 0) + (float) $row['quantity'];
            $merged[$pid]['rate'] = $row['rate'] ?? $merged[$pid]['rate'] ?? null;
        }

        $lines = [];
        $total = 0.0;
        foreach ($merged as $pid => $row) {
            $rate = (float) ($row['rate'] ?? $products[$pid]->rate);
            $amount = round($rate * $row['quantity'], 2);
            $total += $amount;
            $lines[] = ['product_id' => $pid, 'quantity' => $row['quantity'], 'rate' => $rate, 'amount' => $amount];
        }

        $payload = [
            'sale_date'     => $data['sale_date'],
            'customer_id'   => $customer?->id,
            'customer_name' => $customer?->customer_name ?? ($data['customer_name'] ?? null ?: 'Walk-in'),
            'reference'     => $data['reference'],
            'payment_mode'  => $data['payment_mode'] ?? null,
            'notes'         => $data['notes'] ?? null,
            'total'         => round($total, 2),
        ];

        if ($sale) {
            $sale->update($payload);
        } else {
            $sale = Sale::create($payload + [
                'sale_no'    => DocNumber::next(Sale::class, 'sale_no', 'SAL', $t->companyId(), $t->yearId(), $t->year->code),
                'created_by' => $t->user->id,
            ]);
        }

        $sale->items()->createMany($lines);
        $this->stock->assertNotNegative($t->company, $t->yearId(), array_keys($merged));

        return $sale->load('items.product');
    }
}
