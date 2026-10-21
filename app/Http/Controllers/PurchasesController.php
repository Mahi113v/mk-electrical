<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Purchase;
use App\Services\DocNumber;
use App\Services\ProductCodeGenerator;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Add products" = save a purchase.
 *   - line with an existing product  -> its stock goes up
 *   - line with a NEW product name   -> product is created in the master with an automatic unique code
 *   - same name as an existing one   -> treated as that existing product (no duplicates)
 */
class PurchasesController extends Controller
{
    public function __construct(private StockService $stock, private ProductCodeGenerator $codes)
    {
    }

    private function rules(): array
    {
        return [
            'purchase_date'        => ['nullable', 'date'],
            'supplier_name'        => ['required', 'string', 'max:255'],
            'supplier_phone'       => ['nullable', 'string', 'max:20'],
            'invoice_no'           => ['nullable', 'string', 'max:255'],
            'notes'                => ['nullable', 'string', 'max:2000'],
            'items'                => ['required', 'array', 'min:1'],
            'items.*.product_id'   => ['nullable', 'integer', 'exists:products,id'],
            'items.*.product_name' => ['nullable', 'string', 'max:255', 'required_without:items.*.product_id'],
            'items.*.quantity'     => ['required', 'numeric', 'min:0.01'],
            'items.*.rate'         => ['required', 'numeric', 'min:0'],      // purchase rate
            'items.*.selling_rate' => ['nullable', 'numeric', 'min:0'],      // only used when a NEW product is created
        ];
    }

    private function clean(Request $request): void
    {
        // an empty row of the form is not an item
        $request->merge(['items' => collect($request->input('items', []))
            ->filter(fn ($i) => (float) ($i['quantity'] ?? 0) > 0 && (!empty($i['product_id']) || trim((string) ($i['product_name'] ?? '')) !== ''))
            ->values()->all()]);
    }

    public function index(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);
        $rows = Purchase::forTenant()->with(['items.product:id,product_name,product_code', 'user:id,name'])
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->search;
                $q->where(fn ($w) => $w->where('supplier_name', 'LIKE', "%{$s}%")
                    ->orWhere('purchase_no', 'LIKE', "%{$s}%")->orWhere('invoice_no', 'LIKE', "%{$s}%"));
            })
            ->when($from, fn ($q) => $q->whereDate('purchase_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('purchase_date', '<=', $to))
            ->orderByDesc('purchase_date')->orderByDesc('id')->get();

        return $this->ok($rows);
    }

    public function show(int $id): JsonResponse
    {
        return $this->ok(Purchase::forTenant()->with(['items.product', 'user:id,name'])->findOrFail($id));
    }

    public function store(Request $request): JsonResponse
    {
        $this->clean($request);
        $data = $request->validate($this->rules());
        $data['purchase_date'] = $this->dateInYear($data['purchase_date'] ?? null, 'purchase_date');

        $result = DB::transaction(fn () => $this->persist(null, $data));

        return $this->ok($result, 'Products added to stock', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $purchase = Purchase::forTenant()->findOrFail($id);

        $this->clean($request);
        $data = $request->validate($this->rules());
        $data['purchase_date'] = $this->dateInYear($data['purchase_date'] ?? null, 'purchase_date');

        $result = DB::transaction(fn () => $this->persist($purchase, $data));

        return $this->ok($result, 'Purchase updated');
    }

    public function destroy(int $id): JsonResponse
    {
        $purchase = Purchase::forTenant()->findOrFail($id);
        $t = $this->tenant();

        DB::transaction(function () use ($purchase, $t) {
            $ids = $purchase->items()->pluck('product_id')->all();
            $purchase->delete();   // items cascade
            $this->stock->assertNotNegative($t->company, $t->yearId(), $ids);   // removing stock may not make it negative
        });

        return $this->ok(null, 'Purchase deleted');
    }

    private function persist(?Purchase $purchase, array $data): array
    {
        $t = $this->tenant();
        $affected = $purchase ? $purchase->items()->pluck('product_id')->all() : [];

        if ($purchase) {
            $purchase->items()->delete();
        }

        $lines = [];
        $created = [];
        $total = 0.0;
        $byName = Product::all()->keyBy(fn ($p) => mb_strtolower(trim($p->product_name)));

        foreach ($data['items'] as $row) {
            $isNew = false;

            if (!empty($row['product_id'])) {
                $product = Product::findOrFail($row['product_id']);
            } else {
                $name = trim($row['product_name']);
                $product = $byName[mb_strtolower($name)] ?? null;

                if (!$product) {
                    $selling = (float) ($row['selling_rate'] ?? $row['rate']);
                    $product = $this->codes->create($name, $selling, $t->user->id);
                    $byName[mb_strtolower($name)] = $product;
                    $created[] = $product->only(['id', 'product_name', 'product_code']);
                    $isNew = true;
                }
            }

            $amount = round((float) $row['quantity'] * (float) $row['rate'], 2);
            $total += $amount;
            $lines[] = ['product_id' => $product->id, 'quantity' => $row['quantity'], 'rate' => $row['rate'], 'amount' => $amount];
            $affected[] = $product->id;
        }

        $payload = [
            'purchase_date'  => $data['purchase_date'],
            'supplier_name'  => $data['supplier_name'],
            'supplier_phone' => $data['supplier_phone'] ?? null,
            'invoice_no'     => $data['invoice_no'] ?? null,
            'notes'          => $data['notes'] ?? null,
            'total'          => round($total, 2),
        ];

        if ($purchase) {
            $purchase->update($payload);
        } else {
            $purchase = Purchase::create($payload + [
                'purchase_no' => DocNumber::next(Purchase::class, 'purchase_no', 'PUR', $t->companyId(), $t->yearId(), $t->year->code),
                'created_by'  => $t->user->id,
            ]);
        }

        $purchase->items()->createMany($lines);

        // editing a purchase can lower stock of the old products
        $this->stock->assertNotNegative($t->company, $t->yearId(), array_values(array_unique($affected)));

        return [
            'purchase'     => $purchase->load('items.product'),
            'new_products' => $created,
        ];
    }
}
