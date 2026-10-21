<?php

namespace App\Http\Controllers;

use App\Models\OpeningStock;
use App\Models\Product;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Opening stock of the selected company + year.
 *  - index   : saved opening stock + what the previous year closed with (purchase, sales ... shown)
 *  - refresh : "Fetch / Refresh stock" button. Calculates the previous year's closing
 *              (opening + purchases - sales - orders +/- adjustments) and saves it as this year's opening stock.
 *  - update  : type a number by hand (first year, or a correction). Refresh will not overwrite it unless "force".
 */
class OpeningStockController extends Controller
{
    public function __construct(private StockService $stock)
    {
    }

    public function index(): JsonResponse
    {
        return $this->ok($this->payload());
    }

    public function refresh(Request $request): JsonResponse
    {
        $request->validate(['force' => ['nullable', 'boolean']]);
        $force = $request->boolean('force');
        $t = $this->tenant();

        $previous = $t->year->previous();
        if (!$previous) {
            return $this->fail('There is no earlier account year, so there is nothing to carry forward. Enter the opening stock by hand.', 422);
        }

        $stats = DB::transaction(function () use ($t, $previous, $force) {
            $closing = $this->stock->closingOfYear($t->company, $previous);
            $existing = OpeningStock::forTenant()->get()->keyBy('product_id');
            $stats = ['updated' => 0, 'skipped_manual' => 0, 'unchanged' => 0];

            foreach ($closing as $productId => $qty) {
                $row = $existing[$productId] ?? null;

                if ($row && $row->source === 'manual' && !$force) {
                    $stats['skipped_manual']++;
                    continue;
                }
                if (!$row && abs($qty) < 0.005) {
                    continue;   // nothing to carry forward
                }
                if ($row && abs((float) $row->quantity - $qty) < 0.005 && $row->source === 'calculated') {
                    $stats['unchanged']++;
                    continue;
                }

                OpeningStock::updateOrCreate(
                    ['company_id' => $t->companyId(), 'account_year_id' => $t->yearId(), 'product_id' => $productId],
                    ['quantity' => $qty, 'source' => 'calculated', 'calculated_at' => now(), 'updated_by' => $t->user->id]
                );
                $stats['updated']++;
            }

            return $stats;
        });

        return $this->ok(['summary' => $stats + ['previous_year' => $previous->label]] + $this->payload(),
            "Opening stock fetched from {$previous->label}");
    }

    public function update(Request $request, int $productId): JsonResponse
    {
        Product::findOrFail($productId);
        $data = $request->validate(['quantity' => ['required', 'numeric', 'min:0', 'max:99999999']]);
        $t = $this->tenant();

        DB::transaction(function () use ($t, $productId, $data) {
            OpeningStock::updateOrCreate(
                ['company_id' => $t->companyId(), 'account_year_id' => $t->yearId(), 'product_id' => $productId],
                ['quantity' => $data['quantity'], 'source' => 'manual', 'calculated_at' => null, 'updated_by' => $t->user->id]
            );
            $this->stock->assertNotNegative($t->company, $t->yearId(), [$productId]);
        });

        return $this->ok($this->payload(), 'Opening stock saved');
    }

    private function payload(): array
    {
        $t = $this->tenant();
        $previous = $t->year->previous();

        $saved = OpeningStock::forTenant()->get()->keyBy('product_id');
        $prev = $previous ? $this->movementsWithOpening($previous) : [];

        $rows = Product::orderBy('product_name')->get(['id', 'product_name', 'product_code'])->map(function ($p) use ($saved, $prev) {
            $row = $saved[$p->id] ?? null;
            $calc = $prev[$p->id] ?? null;

            return [
                'product_id'   => $p->id,
                'product_name' => $p->product_name,
                'product_code' => $p->product_code,
                'quantity'     => $row ? (float) $row->quantity : null,
                'source'       => $row?->source,
                'calculated_at' => $row?->calculated_at,
                'previous'     => $calc,                        // opening/purchased/sold/ordered/adjusted/closing of the year before
                'suggested'    => $calc['closing'] ?? 0.0,
            ];
        })->values();

        return [
            'year'          => $t->year->only(['id', 'label']),
            'previous_year' => $previous?->only(['id', 'label']),
            'rows'          => $rows,
        ];
    }

    /** Movements of the previous year, with the opening that year effectively started with */
    private function movementsWithOpening($year): array
    {
        $t = $this->tenant();
        $m = $this->stock->movements($t->companyId(), $year->id);
        $closing = $this->stock->closingOfYear($t->company, $year);

        foreach ($closing as $pid => $qty) {
            $m[$pid] ??= ['opening' => 0.0, 'purchased' => 0.0, 'sold' => 0.0, 'ordered' => 0.0, 'adjusted' => 0.0, 'closing' => 0.0];
            $m[$pid]['closing'] = $qty;
            $m[$pid]['opening'] = round($qty - $m[$pid]['purchased'] + $m[$pid]['sold'] + $m[$pid]['ordered'] - $m[$pid]['adjusted'], 2);
        }

        return $m;
    }
}
