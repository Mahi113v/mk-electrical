<?php

namespace App\Models\Concerns;

use App\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tables that carry company_id (products, customers, items ...).
 *  - every query is limited to the current company automatically
 *  - company_id is filled on create
 *  - the model reads / writes the company's OWN database when it has one
 * Without a current company (artisan, seeders) nothing is limited.
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope('company', function (Builder $query) {
            $tenant = app(Tenant::class);
            if ($tenant->hasCompany()) {
                $query->where($query->getModel()->getTable() . '.company_id', $tenant->companyId());
            }
        });

        static::creating(function ($model) {
            $tenant = app(Tenant::class);
            if ($tenant->hasCompany()) {
                $model->company_id ??= $tenant->companyId();
            }
        });
    }

    public function getConnectionName()
    {
        return app(Tenant::class)->connectionName() ?? parent::getConnectionName();
    }
}
