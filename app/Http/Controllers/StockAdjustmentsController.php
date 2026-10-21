<?php

namespace App\Http\Controllers;

use App\Models\StockAdjustment;
use App\Services\DocNumber;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Manual stock corrections (damage, lost, found ...).
 * Automatic ones (reason order_cancel) are created when an order is cancelled and cannot be edited by hand.
 */
class StockAdjustmentsController extends Controller
{
    public function __construct(private StockService $stock)
    {
    }

    public function index(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);
        $rows = StockAdjustment::forTenant()->with(['items.product:id,product_name,product_code', 'order:id,order_name', 'user:id,name'])
            ->when($request->filled('reason'), fn ($q) => $q->where('reason', $request->reason))
            ->when($from, fn ($q) => $q->whereDate('adjustment_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('adjustment_date', '<=', $to))
            ->orderByDesc('adjustment_date')->orderByDesc('id')->get();

        return $this->ok($rows);
    }

    public function store(Request $request): JsonResponse
    {
        $request->merge(['items' => collect($request->input('items', []))
            ->filter(fn ($i) => (float) ($i['quantity'] ?? 0) > 0 && !empty($i['product_id']))->values()->all()]);

        $data = $request->validate([
            'adjustment_date'    => ['nullable', 'date'],
            'reason'             => ['required', Rule::in(StockAdjustment::REASONS)],
            'notes'              => ['nullable', 'string', 'max:2000'],
            'items'              => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.type'       => ['required', 'in:increase,decrease'],
            'items.*.quantity'   => ['required', 'numeric', 'min:0.01'],
        ]);
        $date = $this->dateInYear($data['adjustment_date'] ?? null, 'adjustment_date');
        $t = $this->tenant();

        $adjustment = DB::transaction(function () use ($data, $date, $t) {
            $adjustment = StockAdjustment::create([
                'adjustment_no'   => DocNumber::next(StockAdjustment::class, 'adjustment_no', 'ADJ', $t->companyId(), $t->yearId(), $t->year->code),
                'adjustment_date' => $date,
                'reason'          => $data['reason'],
                'notes'           => $data['notes'] ?? null,
                'is_auto'         => false,
                'created_by'      => $t->user->id,
            ]);

            $adjustment->items()->createMany(collect($data['items'])->map(fn ($i) => [
                'product_id'      => $i['product_id'],
                'quantity_change' => $i['type'] === 'decrease' ? -abs($i['quantity']) : abs($i['quantity']),
            ])->all());

            $this->stock->assertNotNegative($t->company, $t->yearId(), collect($data['items'])->pluck('product_id')->unique()->all());

            return $adjustment->load('items.product');
        });

        return $this->ok($adjustment, 'Stock adjusted', 201);
    }

    public function destroy(int $id): JsonResponse
    {
        $adjustment = StockAdjustment::forTenant()->findOrFail($id);

        if ($adjustment->is_auto) {
            return $this->fail('This adjustment was made by the system when an order was cancelled. Re-open the order to remove it.', 422);
        }

        $t = $this->tenant();
        DB::transaction(function () use ($adjustment, $t) {
            $ids = $adjustment->items()->pluck('product_id')->all();
            $adjustment->delete();
            $this->stock->assertNotNegative($t->company, $t->yearId(), $ids);
        });

        return $this->ok(null, 'Adjustment deleted');
    }
}
