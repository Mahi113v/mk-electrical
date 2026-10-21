<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Role;
use App\Support\AdminScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Product admin: all companies. Company admin: only the companies he manages (and only the profile fields). */
class CompaniesController extends Controller
{
    private function rules(?int $ignore = null): array
    {
        return [
            'code'                 => ['required', 'alpha_num', 'max:10', Rule::unique('companies', 'code')->ignore($ignore)],
            'name'                 => ['required', 'string', 'max:255'],
            'gstin'                => ['nullable', 'string', 'max:20'],
            'phone'                => ['nullable', 'string', 'max:20'],
            'email'                => ['nullable', 'email', 'max:255'],
            'address'              => ['nullable', 'string', 'max:500'],
            'city'                 => ['nullable', 'string', 'max:255'],
            'state'                => ['nullable', 'string', 'max:255'],
            'pincode'              => ['nullable', 'string', 'max:10'],
            'status'               => ['required', Rule::in(['active', 'suspended', 'inactive'])],
            'allow_negative_stock' => ['boolean'],
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $limit = AdminScope::limit($request->user());

        $rows = Company::withCount('users')
            ->when($limit !== null, fn ($q) => $q->whereIn('id', $limit))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w->where('code', 'LIKE', "%{$request->search}%")
                ->orWhere('name', 'LIKE', "%{$request->search}%")->orWhere('gstin', 'LIKE', "%{$request->search}%")))
            ->orderBy('code')->get();

        return $this->ok($rows);
    }

    /** product admin only (route middleware) */
    public function store(Request $request): JsonResponse
    {
        $request->merge(['code' => strtoupper((string) $request->code)]);
        $data = $request->validate($this->rules());

        return $this->ok(Company::create($data), 'Company created', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $limit = AdminScope::limit($request->user());
        $company = Company::findOrFail($id);

        if ($limit !== null) {
            abort_unless(in_array($company->id, $limit, true), 403, 'You can only change your own company.');
            // a company admin edits the profile only - not the code and not the status
            $request->merge(['code' => $company->code, 'status' => $company->status]);
        } else {
            $request->merge(['code' => strtoupper((string) $request->code)]);
        }

        $company->update($request->validate($this->rules($company->id)));

        return $this->ok($company->fresh(), 'Company updated');
    }

    /** product admin only (route middleware) */
    public function destroy(int $id): JsonResponse
    {
        $company = Company::findOrFail($id);

        $used = collect(['orders', 'purchases', 'sales', 'stock_adjustments', 'opening_stocks', 'work_status'])
            ->first(fn ($table) => DB::table($table)->where('company_id', $company->id)->exists());

        if ($used) {
            return $this->fail('This company already has data (' . str_replace('_', ' ', $used) . '). Mark it inactive instead of deleting.', 422);
        }

        $company->delete();

        return $this->ok(null, 'Company deleted');
    }

    /** Users who can open this company, with their role there */
    public function users(Request $request, int $id): JsonResponse
    {
        $limit = AdminScope::limit($request->user());
        $company = Company::findOrFail($id);
        abort_if($limit !== null && !in_array($company->id, $limit, true), 403, 'You can only see your own company.');

        $roles = Role::pluck('name', 'id');

        return $this->ok($company->users()->orderBy('name')->get()->map(fn ($u) => [
            'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'is_active' => $u->is_active,
            'role' => $roles[$u->pivot->role_id] ?? null, 'is_default' => (bool) $u->pivot->is_default,
        ]));
    }
}
