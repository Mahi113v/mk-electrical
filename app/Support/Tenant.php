<?php

namespace App\Support;

use App\Models\AccountYear;
use App\Models\Company;
use App\Models\User;

/**
 * The company + account year the current request works in.
 * Filled by the TenantContext middleware (headers X-Company-Id / X-Year-Id) after the access check,
 * or by the public order form, so controllers and models can trust it.
 *
 * Own database: when the company has its own database, $database is its name and every business table
 * is read / written there (connection "tenant"). $tables = the tables that exist in it.
 */
class Tenant
{
    public ?Company $company = null;
    public ?AccountYear $year = null;
    public ?User $user = null;
    public array $permissions = [];
    public bool $all = false;
    public bool $locked = false;
    public ?string $database = null;
    public ?array $tables = null;

    public function set(User $user, Company $company, AccountYear $year, array $permissions, bool $all, bool $locked): void
    {
        $this->user = $user;
        $this->company = $company;
        $this->year = $year;
        $this->permissions = $permissions;
        $this->all = $all;
        $this->locked = $locked;
        TenantDb::use($company);
    }

    /** public order form: a company + year, no user */
    public function setPublic(Company $company, AccountYear $year): void
    {
        $this->user = null;
        $this->company = $company;
        $this->year = $year;
        $this->permissions = [];
        $this->all = false;
        $this->locked = false;
        TenantDb::use($company);
    }

    public function clear(): void
    {
        $this->company = $this->year = $this->user = null;
        $this->permissions = [];
        $this->all = $this->locked = false;
        TenantDb::use(null);
    }

    public function isSet(): bool
    {
        return $this->company !== null && $this->year !== null;
    }

    public function hasCompany(): bool
    {
        return $this->company !== null;
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

    /** connection name of the company's own database, null = the normal (shared) connection */
    public function connectionName(): ?string
    {
        return $this->database ? TenantDb::CONNECTION : null;
    }

    /** does this company have the table? (always true when it uses the shared database) */
    public function hasTable(string $table): bool
    {
        return $this->tables === null || in_array($table, $this->tables, true);
    }
}
