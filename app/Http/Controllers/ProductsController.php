<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductsController extends Controller
{
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

            return $this->ok($products, 'Product fetched successfully');
        } catch (\Throwable $e) {
            return $this->serverError($e, 'Product fetch server error');
        }
    }

    public function show(Product $product): JsonResponse
    {
        return $this->ok($product->load('user:id,name'));
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'product_name' => ['required', 'string', 'max:255'],
                'product_code' => ['required', 'string', 'max:255', 'unique:products,product_code'],
                'rate'         => ['required', 'numeric', 'min:0'],
                'stock'        => ['nullable', 'integer', 'min:0'],
            ], [
                'product_code.unique' => 'Product code already exists',
            ]);

            $product = Product::create($data + ['user_id' => $request->user()->id]);

            return $this->ok($product, 'Product created successfully', 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->serverError($e, 'Server error while creating product');
        }
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        try {
            $data = $request->validate([
                'product_name' => ['required', 'string', 'max:255'],
                'product_code' => ['required', 'string', 'max:255', Rule::unique('products', 'product_code')->ignore($product->id)],
                'rate'         => ['required', 'numeric', 'min:0'],
                'stock'        => ['nullable', 'integer', 'min:0'],
            ], [
                'product_code.unique' => 'Product code already exists',
            ]);

            $product->update($data);

            return $this->ok($product->fresh(), 'Product updated successfully');
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->serverError($e, 'Server error while updating product');
        }
    }

    public function destroy(Product $product): JsonResponse
    {
        if ($product->orderItems()->exists()) {
            return $this->fail('This product is used in orders and cannot be deleted.', 422);
        }

        $product->delete();

        return $this->ok(null, 'Product deleted successfully');
    }
}
