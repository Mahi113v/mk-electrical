<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\ProductCodeGenerator;
use App\Services\StockService;
use App\Support\TenantDb;
use App\Support\TenantRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Each company has its own products. "stock" is for the selected company + year. */
class ProductsController extends Controller
{
    public function __construct(private StockService $stock, private ProductCodeGenerator $codes)
    {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $products = Product::with('user:id,name')
                ->when($request->filled('search'), function ($q) use ($request) {
                    $s = $request->search;
                    $q->where(fn ($w) => $w->where('product_name', 'LIKE', "%{$s}%")
                        ->orWhere('product_code', 'LIKE', "%{$s}%")
                        ->orWhere('rate', 'LIKE', "%{$s}%"));
                })
                ->orderBy('product_name')->get();

            $t = $this->tenant();
            $stock = $this->stock->movements($t->companyId(), $t->yearId());
            $products->each(fn ($p) => $p->setAttribute('stock', (float) ($stock[$p->id]['closing'] ?? 0)));

            return $this->ok($products, 'Product fetched successfully');
        } catch (\Throwable $e) {
            return $this->serverError($e, 'Product fetch server error');
        }
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_name' => ['required', 'string', 'max:255'],
            'product_code' => ['nullable', 'string', 'max:255', TenantRules::unique(Product::class, 'product_code')],
            'rate'         => ['required', 'numeric', 'min:0'],
        ], ['product_code.unique' => 'Product code already exists']);

        if (empty($data['product_code'])) {
            // leave it empty and the system makes a unique code (PRD-00001 ...)
            $product = $this->codes->create($data['product_name'], (float) $data['rate'], $request->user()->id);
        } else {
            $product = Product::create($data + ['user_id' => $request->user()->id]);
        }

        return $this->ok($product, 'Product created successfully', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $product = Product::findOrFail($id);

        $data = $request->validate([
            'product_name' => ['required', 'string', 'max:255'],
            'product_code' => ['required', 'string', 'max:255', TenantRules::unique(Product::class, 'product_code')->ignore($product->id)],
            'rate'         => ['required', 'numeric', 'min:0'],
        ], ['product_code.unique' => 'Product code already exists']);

        $product->update($data);

        return $this->ok($product->fresh(), 'Product updated successfully');
    }

    public function destroy(int $id): JsonResponse
    {
        $product = Product::findOrFail($id);

        // only look in the tables this company has
        $used = (TenantDb::has('order_items') && $product->orderItems()->exists())
            || (TenantDb::has('purchase_items') && $product->purchaseItems()->exists())
            || (TenantDb::has('sale_items') && $product->saleItems()->exists())
            || (TenantDb::has('stock_adjustment_items') && $product->adjustmentItems()->exists());

        if ($used) {
            return $this->fail('This product is used in orders / purchases / sales and cannot be deleted.', 422);
        }

        $product->delete();   // its (empty) opening stock rows cascade

        return $this->ok(null, 'Product deleted successfully');
    }
}
