<?php

namespace App\Support;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Who may use the admin login (/admin) and over what.
 *  - product admin  (users.is_admin)            : everything, every company
 *  - company admin  (Company Admin role)        : only the companies he is Company Admin of
 * Everybody else (manager, staff, user ...) cannot use the admin login at all.
 */
class AdminScope
{
    public static function managedCompanies(User $user): Collection
    {
        if ($user->is_admin) {
            return Company::orderBy('name')->get();
        }

        $roleId = Role::where('code', 'company_admin')->whereNull('company_id')->value('id');

        return $user->companies()->wherePivot('role_id', $roleId)->where('companies.status', 'active')->orderBy('name')->get();
    }

    /** @return int[] */
    public static function ids(User $user): array
    {
        return self::managedCompanies($user)->pluck('id')->all();
    }

    public static function canUsePortal(User $user): bool
    {
        return $user->is_active && ($user->is_admin || self::ids($user) !== []);
    }

    /** null = no limit (product admin), otherwise the company ids the person may manage */
    public static function limit(User $user): ?array
    {
        return $user->is_admin ? null : self::ids($user);
    }
}
