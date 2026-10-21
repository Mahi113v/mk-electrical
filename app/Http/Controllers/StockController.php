<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Current stock of every product for the selected company + year */
class StockController extends Controller
{
    public function index(Request $request, StockService $stock): JsonResponse
    {
        [$from, $to] = $this->range($request);
        $t = $this->tenant();

        // no dates = the whole account year; with dates = a stock register for that period
        $m = ($from || $to)
            ? $stock->register($t->companyId(), $t->yearId(), $t->year->start_date->toDateString(), $from ?? $t->year->start_date->toDateString(), $to ?? $t->year->end_date->toDateString())
            : $stock->movements($t->companyId(), $t->yearId());
        $blank = ['opening' => 0.0, 'purchased' => 0.0, 'sold' => 0.0, 'ordered' => 0.0, 'adjusted' => 0.0, 'closing' => 0.0];

        $rows = Product::orderBy('product_name')
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w->where('product_name', 'LIKE', "%{$request->search}%")
                ->orWhere('product_code', 'LIKE', "%{$request->search}%")))
            ->get(['id', 'product_name', 'product_code', 'rate'])
            ->map(fn ($p) => ['product_id' => $p->id, 'product_name' => $p->product_name, 'product_code' => $p->product_code, 'rate' => (float) $p->rate]
                + ($m[$p->id] ?? $blank))
            ->values();

        return $this->ok([
            'rows'   => $rows,
            'totals' => [
                'closing' => round($rows->sum('closing'), 2),
                'value'   => round($rows->sum(fn ($r) => max(0, $r['closing']) * $r['rate']), 2),
            ],
        ]);
    }
}
