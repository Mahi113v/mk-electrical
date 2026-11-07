<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Which tables can live in a company's own database, and how they depend on each other.
 *
 * A table is "company data" when it has a company_id column and is not one of the shared control tables.
 * The known tables below have a label, a group and the tables they cannot work without.
 * A table added by a future migration (with company_id) shows up automatically as an extra choice.
 */
class TenantTables
{
    /** table => [label, group, requires[]] */
    public const KNOWN = [
        'products'               => ['Products', 'Masters', []],
        'customers'              => ['Customers', 'Masters', []],
        'orders'                 => ['Orders', 'Orders', ['customers', 'order_items', 'products']],
        'order_items'            => ['Order items', 'Orders', ['orders', 'products']],
        'purchases'              => ['Purchases', 'Purchase', ['purchase_items', 'products']],
        'purchase_items'         => ['Purchase items', 'Purchase', ['purchases', 'products']],
        'sales'                  => ['Sales', 'Sales', ['sale_items', 'customers', 'products']],
        'sale_items'             => ['Sale items', 'Sales', ['sales', 'products']],
        'opening_stocks'         => ['Opening stock', 'Stock', ['products']],
        'stock_adjustments'      => ['Stock adjustments', 'Stock', ['stock_adjustment_items', 'products']],
        'stock_adjustment_items' => ['Stock adjustment items', 'Stock', ['stock_adjustments', 'products']],
        'work_status'            => ['Work status', 'Work', ['work_list']],
        'work_list'              => ['Work list', 'Work', ['work_status']],
    ];

    /** shared control tables: always in the main database, never in a company database */
    public const CENTRAL = [
        'companies', 'company_years', 'company_user', 'roles', 'users', 'user_year_access', 'account_years',
        'personal_access_tokens', 'password_reset_tokens', 'failed_jobs', 'sessions', 'migrations', 'cache', 'cache_locks', 'jobs',
    ];

    /** permission module => tables it needs (a module whose tables are missing disappears from the menu and is refused by the server) */
    public const MODULES = [
        'products'          => ['products'],
        'customers'         => ['customers'],
        'orders'            => ['orders', 'order_items', 'products', 'customers'],
        'purchases'         => ['purchases', 'purchase_items', 'products'],
        'sales'             => ['sales', 'sale_items', 'products'],
        'opening_stock'     => ['opening_stocks', 'products'],
        'stock_adjustments' => ['stock_adjustments', 'stock_adjustment_items', 'products'],
        'stock'             => ['products'],
        'work_status'       => ['work_status', 'work_list'],
    ];

    /** every table of the main database, marked as selectable (company data) or shared */
    public static function inMainDatabase(): array
    {
        if (DB::getDriverName() !== 'mysql') {
            return self::knownOnly();   // sqlite (tests): no information_schema, use the known tables
        }
        $db = TenantDb::mainDatabase();
        $rows = DB::select('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = ? AND table_type = \'BASE TABLE\' ORDER BY table_name', [$db]);
        $withCompany = collect(DB::select('SELECT DISTINCT table_name AS t FROM information_schema.columns WHERE table_schema = ? AND column_name = \'company_id\'', [$db]))
            ->pluck('t')->map(fn ($x) => strtolower($x))->all();

        $out = [];
        foreach ($rows as $r) {
            $name = $r->t;
            $isCentral = in_array($name, self::CENTRAL, true);
            $selectable = !$isCentral && in_array(strtolower($name), $withCompany, true);
            $known = self::KNOWN[$name] ?? null;
            $out[] = [
                'name'       => $name,
                'label'      => $known[0] ?? ucfirst(str_replace('_', ' ', $name)),
                'group'      => $known[1] ?? ($selectable ? 'Other' : 'Shared (always in the main database)'),
                'requires'   => $known[2] ?? [],
                'selectable' => $selectable,
                'rows'       => $selectable ? (int) DB::table($name)->count() : null,
            ];
        }

        return $out;
    }

    private static function knownOnly(): array
    {
        $out = [];
        foreach (self::KNOWN as $name => [$label, $group, $requires]) {
            if (\Illuminate\Support\Facades\Schema::hasTable($name)) {
                $out[] = ['name' => $name, 'label' => $label, 'group' => $group, 'requires' => $requires, 'selectable' => true, 'rows' => (int) DB::table($name)->count()];
            }
        }

        return $out;
    }

    /** names of the selectable tables */
    public static function selectable(): array
    {
        return collect(self::inMainDatabase())->where('selectable', true)->pluck('name')->all();
    }

    /** the chosen tables + everything they cannot work without (a missing parent / items table is added) */
    public static function withDependencies(array $chosen): array
    {
        $allowed = self::selectable();
        $out = [];
        $walk = function (string $t) use (&$walk, &$out, $allowed) {
            if (isset($out[$t]) || !in_array($t, $allowed, true)) {
                return;
            }
            $out[$t] = true;
            foreach (self::KNOWN[$t][2] ?? [] as $dep) {
                $walk($dep);
            }
        };
        foreach ($chosen as $t) {
            $walk((string) $t);
        }

        return array_values(array_filter($allowed, fn ($t) => isset($out[$t])));   // main-database order
    }

    /** permission keys that cannot work because their tables are not in the company database */
    public static function filterPermissions(array $permissions, ?array $tables): array
    {
        if ($tables === null) {
            return $permissions;
        }
        $off = [];
        foreach (self::MODULES as $module => $needs) {
            if (array_diff($needs, $tables)) {
                $off[] = $module . '.';
            }
        }

        return array_values(array_filter($permissions, function ($p) use ($off) {
            foreach ($off as $prefix) {
                if (str_starts_with($p, $prefix)) {
                    return false;
                }
            }

            return true;
        }));
    }
}
