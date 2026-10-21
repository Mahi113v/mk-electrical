<?php

namespace App\Support;

use App\Models\AccountYear;
use App\Models\Company;
use App\Models\Role;
use Carbon\Carbon;

/** Reference data every install needs (roles, account years, a first company). Safe to run many times. */
class Bootstrapper
{
    public static function roles(): void
    {
        foreach (Permissions::systemRoles() as $code => [$name, $description, $permissions]) {
            Role::firstOrCreate(
                ['code' => $code],
                ['name' => $name, 'description' => $description, 'permissions' => $permissions, 'is_system' => true]
            );
        }
    }

    /** Financial year starting in $startYear: 1 April $startYear to 31 March next year. */
    public static function year(int $startYear): AccountYear
    {
        $end = $startYear + 1;

        return AccountYear::firstOrCreate(
            ['code' => sprintf('%02d%02d', $startYear % 100, $end % 100)],
            [
                'label'      => sprintf('%d-%02d', $startYear, $end % 100),
                'start_date' => Carbon::create($startYear, 4, 1)->toDateString(),
                'end_date'   => Carbon::create($end, 3, 31)->toDateString(),
            ]
        );
    }

    public static function fyStart(?Carbon $date = null): int
    {
        $date ??= now();

        return $date->month >= 4 ? $date->year : $date->year - 1;
    }

    public static function years(): void
    {
        $start = self::fyStart();
        self::year($start - 1);
        self::year($start);
    }

    public static function defaultCompany(): Company
    {
        return Company::firstOrCreate(
            ['code' => 'MK'],
            ['name' => 'MK Electrical', 'city' => 'Sivakasi', 'status' => 'active']
        );
    }
}
