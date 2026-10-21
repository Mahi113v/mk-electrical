<?php

namespace App\Http\Middleware;

use App\Models\AccountYear;
use App\Models\Company;
use App\Models\CompanyYear;
use App\Models\Role;
use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every company screen sends X-Company-Id and X-Year-Id. They are checked on EVERY request:
 * the user must be assigned to that company and allowed to open that year.
 * A locked year is view-only.
 */
class TenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $companyId = (int) $request->header('X-Company-Id');
        $yearId = (int) $request->header('X-Year-Id');

        if (!$companyId || !$yearId) {
            return $this->deny('Select a company and an account year first.', 422);
        }

        $company = Company::find($companyId);
        $year = AccountYear::find($yearId);

        if (!$company || !$year) {
            return $this->deny('Company or account year not found.', 404);
        }

        if (!$company->isActive()) {
            return $this->deny('This company is ' . $company->status . '.', 403);
        }

        // the product admin signs in at /admin and has no company screens at all
        if ($user->is_admin) {
            return $this->deny('This account cannot open company screens.', 403);
        }

        $membership = $user->companies()->where('companies.id', $companyId)->first();
        if (!$membership) {
            return $this->deny('You do not have access to this company.', 403);
        }
        if (!$user->years()->where('account_years.id', $yearId)->exists()) {
            return $this->deny('You do not have access to this account year.', 403);
        }
        $role = Role::find($membership->pivot->role_id);
        $permissions = $role?->permissions ?? [];
        $all = $role?->code === 'company_admin';

        $locked = (bool) CompanyYear::where('company_id', $companyId)->where('account_year_id', $yearId)->value('is_locked');

        if ($locked && !in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $this->deny('This account year is locked for ' . $company->name . ' (view only).', 423);
        }

        app(Tenant::class)->set($user, $company, $year, $permissions, $all, $locked);

        return $next($request);
    }

    private function deny(string $message, int $status): Response
    {
        return response()->json(['success' => false, 'message' => $message], $status);
    }
}
