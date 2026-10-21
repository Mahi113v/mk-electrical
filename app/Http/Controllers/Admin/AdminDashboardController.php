<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccountYear;
use App\Models\Company;
use App\Models\User;
use App\Support\AdminScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminDashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $limit = AdminScope::limit($request->user());
        $today = now()->toDateString();
        $year = AccountYear::where('start_date', '<=', $today)->where('end_date', '>=', $today)->first();

        $companies = Company::when($limit !== null, fn ($q) => $q->whereIn('id', $limit));
        $users = User::where('is_admin', false)
            ->when($limit !== null, fn ($q) => $q->whereHas('companies', fn ($c) => $c->whereIn('companies.id', $limit)));

        return $this->ok([
            'scope'                 => $limit === null ? 'platform' : 'company',
            'companies'             => (clone $companies)->count(),
            'companies_by_status'   => (clone $companies)->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status'),
            'users'                 => (clone $users)->count(),
            'users_active'          => (clone $users)->where('is_active', true)->count(),
            'users_without_company' => $limit === null ? User::where('is_admin', false)->doesntHave('companies')->count() : null,
            'current_year'          => $year?->only(['id', 'label', 'start_date', 'end_date']),
            'recent_companies'      => (clone $companies)->withCount('users')->latest('id')->limit(6)->get(['id', 'code', 'name', 'city', 'status']),
        ]);
    }
}
