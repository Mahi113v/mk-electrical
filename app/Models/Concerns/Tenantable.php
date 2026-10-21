<?php

namespace App\Models\Concerns;

use App\Support\Tenant;

/**
 * For every table that is kept separately per company + account year.
 * Always read through forTenant() - that is what keeps one company's data away from another.
 */
trait Tenantable
{
    public static function bootTenantable(): void
    {
        static::creating(function ($model) {
            $tenant = app(Tenant::class);
            if ($tenant->isSet()) {
                $model->company_id ??= $tenant->companyId();
                $model->account_year_id ??= $tenant->yearId();
            }
        });
    }

    public function scopeForTenant($query)
    {
        $tenant = app(Tenant::class);

        return $query->where($this->getTable() . '.company_id', $tenant->companyId())
            ->where($this->getTable() . '.account_year_id', $tenant->yearId());
    }
}
