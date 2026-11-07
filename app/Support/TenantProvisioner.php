<?php

namespace App\Support;

use App\Models\Company;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Creates a company's OWN database and the tables the product admin picked.
 *   CREATE TABLE <own db>.<table> LIKE <main db>.<table>   -> always the same columns / indexes as the app's migrations
 * Foreign keys between the company's own tables are added again (the ones to users / companies cannot cross databases).
 * MySQL / MariaDB only.
 */
class TenantProvisioner
{
    public const RESERVED = ['mysql', 'information_schema', 'performance_schema', 'sys', 'test'];

    /** child table, column, parent table, on delete */
    private const FOREIGN_KEYS = [
        ['order_items', 'order_id', 'orders', 'CASCADE'],
        ['order_items', 'product_id', 'products', 'RESTRICT'],
        ['purchase_items', 'purchase_id', 'purchases', 'CASCADE'],
        ['purchase_items', 'product_id', 'products', 'RESTRICT'],
        ['sale_items', 'sale_id', 'sales', 'CASCADE'],
        ['sale_items', 'product_id', 'products', 'RESTRICT'],
        ['sales', 'customer_id', 'customers', 'SET NULL'],
        ['opening_stocks', 'product_id', 'products', 'CASCADE'],
        ['stock_adjustments', 'order_id', 'orders', 'CASCADE'],
        ['stock_adjustment_items', 'stock_adjustment_id', 'stock_adjustments', 'CASCADE'],
        ['stock_adjustment_items', 'product_id', 'products', 'RESTRICT'],
        ['work_list', 'work_status_id', 'work_status', 'CASCADE'],
    ];

    public function validateName(string $name, ?int $ignoreCompany = null): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw ValidationException::withMessages(['database_name' => ['A separate database per company needs MySQL / MariaDB.']]);
        }
        $lower = strtolower($name);
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{2,47}$/', $name)) {
            throw ValidationException::withMessages(['database_name' => ['Database name: 3 to 48 letters, numbers or underscore, starting with a letter.']]);
        }
        if (in_array($lower, self::RESERVED, true) || $lower === strtolower(TenantDb::mainDatabase())) {
            throw ValidationException::withMessages(['database_name' => ['This database name is reserved. Choose another one.']]);
        }
        if (Company::where('database_name', $name)->when($ignoreCompany, fn ($q) => $q->where('id', '!=', $ignoreCompany))->exists()) {
            throw ValidationException::withMessages(['database_name' => ['Another company already uses this database.']]);
        }
        if ($this->databaseExists($name)) {
            throw ValidationException::withMessages(['database_name' => ['A database with this name already exists on the server. Choose a new name.']]);
        }
    }

    public function databaseExists(string $name): bool
    {
        return DB::selectOne('SELECT SCHEMA_NAME AS n FROM information_schema.schemata WHERE SCHEMA_NAME = ?', [$name]) !== null;
    }

    /** tables that really exist in the company's database */
    public function existingTables(string $database): array
    {
        return collect(DB::select('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = ? AND table_type = \'BASE TABLE\' ORDER BY table_name', [$database]))
            ->pluck('t')->all();
    }

    /**
     * Create the database + tables and switch the company to it.
     * @param bool $copyData copy the company's existing rows from the main database into the new tables
     */
    public function provision(Company $company, string $database, array $chosenTables, bool $copyData = true): Company
    {
        $this->validateName($database, $company->id);
        $tables = TenantTables::withDependencies($chosenTables);
        if (!$tables) {
            throw ValidationException::withMessages(['tables' => ['Select at least one table.']]);
        }

        $created = false;
        try {
            $this->run("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $created = true;
            $this->createTables($database, $tables, $copyData ? $company->id : null);
        } catch (\Throwable $e) {
            if ($created) {
                DB::statement("DROP DATABASE IF EXISTS `$database`");
            }
            if ($e instanceof ValidationException) {
                throw $e;
            }
            report($e);
            throw ValidationException::withMessages(['database_name' => [$this->friendly($e)]]);
        }

        $company->update(['database_name' => $database, 'database_tables' => $tables, 'database_created_at' => now()]);

        return $company->fresh();
    }

    /** Add tables later (the table list of the main database that this company does not have yet). */
    public function addTables(Company $company, array $chosenTables): Company
    {
        $this->requireDatabase($company);
        $have = $this->existingTables($company->database_name);
        $tables = array_values(array_diff(TenantTables::withDependencies(array_merge($chosenTables, $company->database_tables ?? [])), $have));
        if (!$tables) {
            throw ValidationException::withMessages(['tables' => ['These tables already exist in the company database.']]);
        }
        try {
            $this->createTables($company->database_name, $tables, $company->id, $have);
        } catch (\Throwable $e) {
            report($e);
            throw ValidationException::withMessages(['tables' => [$this->friendly($e)]]);
        }
        $company->update(['database_tables' => array_values(array_unique(array_merge($company->database_tables ?? [], $tables)))]);

        return $company->fresh();
    }

    /**
     * An extra table that is not in the app (for the company's own use / reports):
     * id, company_id, your columns, created_at, updated_at.
     * @param array<int, array{name: string, type: string, nullable?: bool}> $columns
     */
    public function createCustomTable(Company $company, string $table, array $columns): Company
    {
        $this->requireDatabase($company);
        $table = strtolower($table);
        $taken = array_merge(array_keys(TenantTables::KNOWN), TenantTables::CENTRAL, $this->existingTables($company->database_name), TenantTables::selectable());
        if (!preg_match('/^[a-z][a-z0-9_]{2,47}$/', $table) || in_array($table, $taken, true)) {
            throw ValidationException::withMessages(['table' => ['Table name: 3 to 48 lowercase letters, numbers or underscore, not already used.']]);
        }
        $types = ['string' => 'VARCHAR(255)', 'text' => 'TEXT', 'integer' => 'BIGINT', 'decimal' => 'DECIMAL(15,2)', 'date' => 'DATE', 'datetime' => 'DATETIME', 'boolean' => 'TINYINT(1)'];
        $defs = ['`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY', '`company_id` BIGINT UNSIGNED NOT NULL', 'INDEX (`company_id`)'];
        $seen = ['id', 'company_id', 'created_at', 'updated_at'];
        foreach ($columns as $c) {
            $name = strtolower((string) ($c['name'] ?? ''));
            $type = $types[$c['type'] ?? ''] ?? null;
            if (!preg_match('/^[a-z][a-z0-9_]{0,40}$/', $name) || in_array($name, $seen, true) || !$type) {
                throw ValidationException::withMessages(['columns' => ["Column \"$name\" is not valid or repeated."]]);
            }
            $seen[] = $name;
            $defs[] = "`$name` $type " . (!empty($c['nullable']) ? 'NULL' : 'NULL');   // always nullable: the app does not know these columns
        }
        $defs[] = '`created_at` TIMESTAMP NULL';
        $defs[] = '`updated_at` TIMESTAMP NULL';
        try {
            DB::statement("CREATE TABLE `{$company->database_name}`.`$table` (" . implode(', ', $defs) . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['table' => [$this->friendly($e)]]);
        }
        $company->update(['database_tables' => array_values(array_unique(array_merge($company->database_tables ?? [], [$table])))]);

        return $company->fresh();
    }

    /** DROP the company database (only used when the company is deleted and has no data) */
    public function drop(Company $company): void
    {
        if ($company->database_name) {
            DB::statement("DROP DATABASE IF EXISTS `{$company->database_name}`");
        }
    }

    /** rows of every company table in the company's own database (for the "has data?" check and the admin screen) */
    public function counts(Company $company): array
    {
        TenantDb::use($company);
        $out = [];
        foreach ($this->existingTables($company->database_name) as $t) {
            $out[$t] = (int) DB::connection(TenantDb::CONNECTION)->table($t)->count();
        }

        return $out;
    }

    // ------------------------------------------------------------------

    private function createTables(string $database, array $tables, ?int $copyCompanyId, array $alreadyThere = []): void
    {
        $main = TenantDb::mainDatabase();
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($tables as $t) {
                DB::statement("CREATE TABLE `$database`.`$t` LIKE `$main`.`$t`");
            }
            if ($copyCompanyId !== null) {
                foreach ($tables as $t) {
                    DB::statement("INSERT INTO `$database`.`$t` SELECT * FROM `$main`.`$t` WHERE company_id = ?", [$copyCompanyId]);
                }
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        $present = array_merge($alreadyThere, $tables);
        foreach (self::FOREIGN_KEYS as [$child, $col, $parent, $onDelete]) {
            if (in_array($child, $tables, true) && in_array($parent, $present, true)) {
                DB::statement("ALTER TABLE `$database`.`$child` ADD CONSTRAINT `{$child}_{$col}_fk` FOREIGN KEY (`$col`) REFERENCES `$database`.`$parent` (`id`) ON DELETE $onDelete");
            }
        }
    }

    private function requireDatabase(Company $company): void
    {
        if (!$company->database_name) {
            throw ValidationException::withMessages(['database_name' => ['This company does not have its own database yet.']]);
        }
    }

    private function run(string $sql): void
    {
        DB::statement($sql);
    }

    private function friendly(\Throwable $e): string
    {
        $m = $e->getMessage();
        if ($e instanceof QueryException && (str_contains($m, 'Access denied') || str_contains($m, 'denied to user'))) {
            return 'The database user does not have permission to create databases. Give CREATE privilege to the user in .env (DB_USERNAME).';
        }

        return config('app.debug') ? $m : 'Could not create the database. Check the server log.';
    }
}
