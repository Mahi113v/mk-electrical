<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccountYear;
use App\Models\Company;
use App\Models\CompanyYear;
use App\Support\AdminScope;
use App\Support\Bootstrapper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccountYearsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $limit = AdminScope::limit($request->user());
        $companies = Company::when($limit !== null, fn ($q) => $q->whereIn('id', $limit))->orderBy('code')->get(['id', 'code', 'name']);
        $locks = CompanyYear::where('is_locked', true)->whereIn('company_id', $companies->pluck('id'))->get()->groupBy('account_year_id');

        return response()->json([
            'success'    => true,
            'can_create' => $limit === null,
            'companies'  => $companies,
            'data'       => AccountYear::orderByDesc('start_date')->get()->map(fn ($y) => $y->toArray() + [
                'locked_company_ids' => ($locks[$y->id] ?? collect())->pluck('company_id')->values(),
            ]),
        ]);
    }

    /** Product admin only (route middleware). Next year = the day after the last year ends. */
    public function store(): JsonResponse
    {
        $latest = AccountYear::orderByDesc('start_date')->first();
        $year = DB::transaction(function () use ($latest) {
            $year = Bootstrapper::year($latest ? $latest->start_date->year + 1 : Bootstrapper::fyStart());

            if ($latest) {
                foreach (DB::table('user_year_access')->where('account_year_id', $latest->id)->pluck('user_id') as $uid) {
                    DB::table('user_year_access')->insertOrIgnore(['user_id' => $uid, 'account_year_id' => $year->id]);
                }
            }

            return $year;
        });

        return $this->ok($year, "Account year {$year->label} created", 201);
    }

    /** Lock = view only for that company. A company admin can only lock his own company. */
    public function lock(Request $request, int $yearId, int $companyId): JsonResponse
    {
        $limit = AdminScope::limit($request->user());
        AccountYear::findOrFail($yearId);
        Company::findOrFail($companyId);
        abort_if($limit !== null && !in_array($companyId, $limit, true), 403, 'You can only lock years of your own company.');

        $data = $request->validate(['is_locked' => ['required', 'boolean']]);
        CompanyYear::updateOrCreate(['company_id' => $companyId, 'account_year_id' => $yearId], ['is_locked' => $data['is_locked']]);

        return $this->ok(null, $data['is_locked'] ? 'Year locked (view only)' : 'Year opened');
    }
}
