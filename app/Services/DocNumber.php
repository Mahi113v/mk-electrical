<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;

/** PUR-2627-0001 style numbers, counted separately for every company + year. */
class DocNumber
{
    public static function next(string $modelClass, string $column, string $prefix, int $companyId, int $yearId, string $yearCode): string
    {
        $stem = "$prefix-$yearCode-";

        /** @var Model $m */
        $max = $modelClass::query()->where('company_id', $companyId)->where('account_year_id', $yearId)
            ->where($column, 'like', $stem . '%')->pluck($column)
            ->map(fn ($n) => (int) substr($n, strlen($stem)))->max() ?? 0;

        return $stem . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }
}
