<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Support\DatabaseDump;
use App\Support\TenantDb;
use App\Support\TenantProvisioner;
use App\Support\TenantTables;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** PRODUCT ADMIN ONLY (route middleware "admin"): a separate database per company + database download. */
class DatabasesController extends Controller
{
    public function __construct(private TenantProvisioner $provisioner)
    {
    }

    /** Every table of the main database, to choose from when a company gets its own database */
    public function tables(): JsonResponse
    {
        return $this->ok(['database' => TenantDb::mainDatabase(), 'tables' => TenantTables::inMainDatabase()]);
    }

    /** One company's database: its tables (live), what can still be added, row counts */
    public function show(int $id): JsonResponse
    {
        $company = Company::findOrFail($id);
        if (!$company->database_name) {
            return $this->ok(['company_id' => $company->id, 'database' => null, 'tables' => [], 'available' => TenantTables::inMainDatabase()]);
        }
        $counts = $this->provisioner->counts($company);
        $mine = array_keys($counts);

        return $this->ok([
            'company_id' => $company->id,
            'database'   => $company->database_name,
            'created_at' => $company->database_created_at,
            'tables'     => collect($counts)->map(fn ($n, $t) => ['name' => $t, 'rows' => $n, 'custom' => !isset(TenantTables::KNOWN[$t]) && !in_array($t, TenantTables::selectable(), true)])->values(),
            'available'  => collect(TenantTables::inMainDatabase())->where('selectable', true)->reject(fn ($t) => in_array($t['name'], $mine, true))->values(),
        ]);
    }

    /** Give an EXISTING company its own database. Its current rows are copied into it (copy_data). */
    public function create(Request $request, int $id): JsonResponse
    {
        $company = Company::findOrFail($id);
        abort_if($company->database_name, 422, 'This company already has its own database.');
        $data = $request->validate([
            'database_name' => ['required', 'string', 'max:48'],
            'tables'        => ['required', 'array', 'min:1'],
            'tables.*'      => ['string', 'max:64'],
            'copy_data'     => ['boolean'],
        ]);
        $company = $this->provisioner->provision($company, $data['database_name'], $data['tables'], (bool) ($data['copy_data'] ?? true));

        return $this->ok($company, 'Database created');
    }

    /** More tables for a company that already has its own database */
    public function addTables(Request $request, int $id): JsonResponse
    {
        $company = Company::findOrFail($id);
        $data = $request->validate(['tables' => ['required', 'array', 'min:1'], 'tables.*' => ['string', 'max:64']]);

        return $this->ok($this->provisioner->addTables($company, $data['tables']), 'Tables added');
    }

    /** A table of your own (name + columns) in the company database */
    public function customTable(Request $request, int $id): JsonResponse
    {
        $company = Company::findOrFail($id);
        $data = $request->validate([
            'table'              => ['required', 'string', 'max:48'],
            'columns'            => ['required', 'array', 'min:1', 'max:40'],
            'columns.*.name'     => ['required', 'string', 'max:41'],
            'columns.*.type'     => ['required', 'in:string,text,integer,decimal,date,datetime,boolean'],
        ]);

        return $this->ok($this->provisioner->createCustomTable($company, $data['table'], $data['columns']), 'Table created');
    }

    /** For the download screen: every company and the databases it can be downloaded from */
    public function options(): JsonResponse
    {
        $main = TenantDb::mainDatabase();

        return $this->ok(Company::orderBy('code')->get(['id', 'code', 'name', 'database_name'])->map(fn ($c) => [
            'id'        => $c->id,
            'code'      => $c->code,
            'name'      => $c->name,
            'databases' => array_values(array_filter([
                $c->database_name ? ['name' => $c->database_name, 'kind' => 'own', 'label' => $c->database_name . '  (own database)'] : null,
                ['name' => $main, 'kind' => 'shared', 'label' => $main . '  (shared database, ' . $c->code . ' rows only)'],
            ])),
        ])->values());
    }

    /** GET download?company_id=1&database=name  -> .sql file */
    public function download(Request $request)
    {
        $data = $request->validate(['company_id' => ['required', 'integer', 'exists:companies,id'], 'database' => ['required', 'string', 'max:64']]);
        $company = Company::findOrFail($data['company_id']);

        if ($company->database_name && $data['database'] === $company->database_name) {
            $kind = 'own';
        } elseif ($data['database'] === TenantDb::mainDatabase()) {
            $kind = 'shared';
        } else {
            throw ValidationException::withMessages(['database' => ['This database does not belong to the selected company.']]);
        }

        $file = sprintf('%s_%s_%s.sql', $company->code, $data['database'], now()->format('Ymd_His'));

        return response()->streamDownload(function () use ($company, $kind, $data) {
            if ($kind === 'own') {
                TenantDb::use($company);
                $conn = DB::connection(TenantDb::CONNECTION);
                $tables = $this->provisioner->existingTables($data['database']);
                DatabaseDump::write($conn, $data['database'], $tables, null, fn ($s) => print($s));
            } else {
                $conn = DB::connection();
                $tables = TenantTables::selectable();
                DatabaseDump::write($conn, $data['database'], $tables, $company->id, fn ($s) => print($s));
            }
        }, $file, ['Content-Type' => 'application/sql; charset=utf-8', 'X-Filename' => $file, 'Access-Control-Expose-Headers' => 'Content-Disposition, X-Filename']);
    }
}
