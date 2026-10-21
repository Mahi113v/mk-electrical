<?php

namespace App\Services;

use App\Models\Product;

/** PRD-00001, PRD-00002 ... The number after the highest existing PRD- code, so it is always unique. */
class ProductCodeGenerator
{
    public const PREFIX = 'PRD-';

    public function next(int $skip = 0): string
    {
        $max = Product::where('product_code', 'like', self::PREFIX . '%')->pluck('product_code')
            ->map(fn ($c) => (int) substr($c, strlen(self::PREFIX)))
            ->max() ?? 0;

        return self::PREFIX . str_pad((string) ($max + 1 + $skip), 5, '0', STR_PAD_LEFT);
    }

    /** Create a product with a fresh code; retries if two requests picked the same number at once. */
    public function create(string $name, float $rate, ?int $userId): Product
    {
        for ($try = 0; $try < 5; $try++) {
            try {
                return \Illuminate\Support\Facades\DB::transaction(fn () => Product::create([
                    'product_name' => $name,
                    'product_code' => $this->next(),
                    'rate'         => $rate,
                    'user_id'      => $userId,
                ]));
            } catch (\Illuminate\Database\QueryException $e) {
                if ($try === 4 || !str_contains(strtolower($e->getMessage()), 'unique') && !str_contains(strtolower($e->getMessage()), 'duplicate')) {
                    throw $e;
                }
            }
        }
    }
}
