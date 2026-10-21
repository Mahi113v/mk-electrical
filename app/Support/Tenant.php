<?php

namespace App\Support;

use App\Models\AccountYear;
use App\Models\Company;
use App\Models\User;

/**
 * The company + account year the current request works in.
 * Filled by the TenantContext middleware (headers X-Company-Id / X-Year-Id) after the
 * access check, so controllers can trust it.
 */
class Tenant
{
    public ?Company $company = null;
    public ?AccountYear $year = null;
    public ?User $user = null;
    public array $permissions = [];
    public bool $all = false;
    public bool $locked = false;

    public function set(User $user, Company $company, AccountYear $year, array $permissions, bool $all, bool $locked): void
    {
        $this->user = $user;
        $this->company = $company;
        $this->year = $year;
        $this->permissions = $permissions;
        $this->all = $all;
        $this->locked = $locked;
    }

    public function isSet(): bool
    {
        return $this->company !== null && $this->year !== null;
    }

    public function companyId(): int
    {
        return (int) $this->company?->id;
    }

    public function yearId(): int
    {
        return (int) $this->year?->id;
    }

    public function can(string $permission): bool
    {
        return $this->all || in_array($permission, $this->permissions, true);
    }

    public function allowNegativeStock(): bool
    {
        return (bool) $this->company?->allow_negative_stock;
    }
}
