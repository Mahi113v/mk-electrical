<?php

namespace App\Support;

use App\Models\Company;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Everything that touches a company's business tables goes through here, so it works the same
 * for a company in the shared database and for a company with its OWN database.
 */
class TenantDb
{
    public const CONNECTION = 'tenant';

    /** Point the "tenant" connection at the company's own database (or back to the shared one). */
    public static function use(?Company $company): void
    {
        $t = app(Tenant::class);
        $db = $company?->database_name ?: null;

        if ($db !== $t->database || ($db && !in_array(self::CONNECTION, array_keys(DB::getConnections()), true))) {
            config(['database.connections.' . self::CONNECTION => self::configFor($db ?? '')]);
            DB::purge(self::CONNECTION);
        }
        $t->database = $db;
        $t->tables = $db ? array_values($company->database_tables ?? []) : null;
    }

    /** same server / user / password as the main connection, another database */
    public static function configFor(string $database): array
    {
        $main = config('database.connections.' . config('database.default'));

        return array_merge($main, ['database' => $database]);
    }

    /** the connection the business tables live on */
    public static function conn(): Connection
    {
        return DB::connection(app(Tenant::class)->connectionName());
    }

    public static function table(string $table): Builder
    {
        return self::conn()->table($table);
    }

    public static function transaction(Closure $callback)
    {
        return self::conn()->transaction($callback);
    }

    /** name to use in "exists:" / "unique:" rules -> a model class, so the right connection is picked */
    public static function has(string $table): bool
    {
        return app(Tenant::class)->hasTable($table);
    }

    public static function mainDatabase(): string
    {
        return DB::connection(config('database.default'))->getDatabaseName();
    }
}
