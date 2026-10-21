<?php

namespace App\Http\Controllers;

use App\Models\AccountYear;
use App\Models\Company;
use App\Models\CompanyYear;
use App\Models\Product;
use App\Services\OrderService;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * Public (no login) order form for customers.  /order-data?company=MK
 * The order lands in that company's CURRENT account year with status "pending".
 */
class WebOrderController extends Controller
{
    public function __construct(private OrderService $orders, private StockService $stock)
    {
    }

    private function resolve(Request $request): array
    {
        $companies = Company::where('status', 'active');
        $company = $request->filled('company')
            ? (clone $companies)->where('code', strtoupper((string) $request->company))->first()
            : ((clone $companies)->count() === 1 ? (clone $companies)->first() : null);

        if (!$company) {
            throw ValidationException::withMessages(['company' => ['Company not found. Use the link given by the shop.']]);
        }

        $today = now()->toDateString();
        $year = AccountYear::where('start_date', '<=', $today)->where('end_date', '>=', $today)->first();
        if (!$year) {
            throw ValidationException::withMessages(['company' => ['Orders are closed right now.']]);
        }

        return [$company, $year];
    }

    /** Only what the public form needs - no stock numbers, no user info. */
    public function products(Request $request): JsonResponse
    {
        [$company, $year] = $this->resolve($request);

        $products = Product::orderBy('product_name')->get(['id', 'product_name', 'product_code', 'rate']);
        $available = $this->stock->available($company->id, $year->id, $products->pluck('id')->all());

        return $this->ok([
            'company'  => ['code' => $company->code, 'name' => $company->name],
            'products' => $products->filter(fn ($p) => $company->allow_negative_stock || $available[$p->id] > 0)->values(),
        ]);
    }

    public function create(Request $request): JsonResponse
    {
        [$company, $year] = $this->resolve($request);

        if (CompanyYear::where('company_id', $company->id)->where('account_year_id', $year->id)->value('is_locked')) {
            return $this->fail('Orders are closed right now.', 423);
        }

        // customers can never set prices or status: drop everything except product + quantity
        $request->merge([
            'status' => 'pending',
            'items'  => collect($request->input('items', []))->map(fn ($i) => is_array($i) ? Arr::only($i, ['product_id', 'quantity']) : $i)->all(),
        ]);

        $rules = OrdersController::rules(true);
        $rules['order_name'] = ['nullable', 'string', 'max:255'];
        unset($rules['referral'], $rules['service_charge'], $rules['order_date']);
        $data = $request->validate($rules);

        $data['order_name']     = $data['order_name'] ?? ('Web order - ' . $data['customer_name']);
        $data['service_charge'] = 0;
        $data['order_date']     = now()->toDateString();

        try {
            $order = $this->orders->save(null, $data, $company, $year, null, false, 'web');

            return $this->ok(['id' => $order->id, 'total' => $order->total], 'Order placed successfully', 201);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->serverError($e, 'Server error while creating order');
        }
    }
}
