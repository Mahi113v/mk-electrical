<?php

namespace App\Support;

use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\Rule;

/**
 * Validation rules for the company's own tables: they look in the right database
 * (shared or the company's own) and only see THIS company's rows.
 */
class TenantRules
{
    /** the id must be a row of the current company, e.g. TenantRules::exists(Product::class) */
    public static function exists(string $modelClass, string $column = 'id'): Exists
    {
        return Rule::exists($modelClass, $column)->where('company_id', app(Tenant::class)->companyId());
    }

    /** unique inside the current company */
    public static function unique(string $modelClass, string $column): Unique
    {
        return Rule::unique($modelClass, $column)->where('company_id', app(Tenant::class)->companyId());
    }
}
