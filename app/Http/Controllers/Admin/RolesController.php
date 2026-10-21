<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Role;
use App\Support\AdminScope;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Roles = what a person may do. Product admin edits the shared roles (every company) and can make roles for one company.
 * A company admin sees the shared roles (read only) and makes / edits roles for HIS company.
 */
class RolesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $limit = AdminScope::limit($request->user());
        $counts = DB::table('company_user')->selectRaw('role_id, COUNT(*) as c')->groupBy('role_id')->pluck('c', 'role_id');

        $roles = Role::with('company:id,code,name')
            ->when($limit !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('company_id')->orWhereIn('company_id', $limit)))
            ->orderByRaw('company_id IS NOT NULL')->orderBy('id')->get();

        return response()->json([
            'success'   => true,
            'data'      => $roles->map(fn ($r) => $r->toArray() + [
                'users_count'  => (int) ($counts[$r->id] ?? 0),
                'company_code' => $r->company?->code,
                'editable'     => $limit === null || ($r->company_id !== null && in_array($r->company_id, $limit, true)),
            ]),
            'catalogue' => collect(Permissions::CATALOGUE)->map(fn ($m, $key) => ['key' => $key, 'label' => $m['label'], 'actions' => $m['actions']])->values(),
        ]);
    }

    private function rules(): array
    {
        return [
            'name'          => ['required', 'string', 'max:100'],
            'description'   => ['nullable', 'string', 'max:255'],
            'company_id'    => ['nullable', 'integer', 'exists:companies,id'],
            'permissions'   => ['array'],
            'permissions.*' => ['string'],
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $limit = AdminScope::limit($request->user());
        $data = $request->validate($this->rules());

        if ($limit !== null) {
            abort_unless(!empty($data['company_id']) && in_array((int) $data['company_id'], $limit, true), 403, 'A company admin can only make roles for his own company.');
        }

        $base = Str::slug($data['name'], '_') ?: 'role';
        if (!empty($data['company_id'])) {
            $base .= '_' . strtolower(Company::find($data['company_id'])->code);
        }
        $code = $base;
        for ($i = 2; Role::where('code', $code)->exists(); $i++) {
            $code = $base . '_' . $i;
        }

        $role = Role::create([
            'company_id' => $data['company_id'] ?? null, 'code' => $code, 'name' => $data['name'], 'description' => $data['description'] ?? null,
            'permissions' => Permissions::clean($data['permissions'] ?? []), 'is_system' => false,
        ]);

        return $this->ok($role, 'Role created', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $limit = AdminScope::limit($request->user());
        $role = $this->findAllowed($id, $limit);
        $data = $request->validate($this->rules());

        $role->update([
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
            // the Company Admin role always keeps every permission
            'permissions' => $role->code === 'company_admin' ? Permissions::all() : Permissions::clean($data['permissions'] ?? []),
        ]);

        return $this->ok($role->fresh(), 'Role updated');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $role = $this->findAllowed($id, AdminScope::limit($request->user()));

        if ($role->is_system) {
            return $this->fail('System roles cannot be deleted. You can edit their permissions.', 422);
        }
        if (DB::table('company_user')->where('role_id', $role->id)->exists()) {
            return $this->fail('This role is given to some users. Change their role first.', 422);
        }

        $role->delete();

        return $this->ok(null, 'Role deleted');
    }

    private function findAllowed(int $id, ?array $limit): Role
    {
        $role = Role::findOrFail($id);

        if ($limit !== null) {
            abort_unless($role->company_id !== null && in_array($role->company_id, $limit, true), 403, 'Shared roles can only be changed by the product admin.');
        }

        return $role;
    }
}
