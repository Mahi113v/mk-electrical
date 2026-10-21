<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Support\AdminScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Product admin: every user, any company, can make other product admins.
 * Company admin : only users of his own companies. He gives them a role there and the account years they can open.
 *                 A user who ALSO works for another company is "shared": the company admin may change that
 *                 user's role in HIS company but not the name, password or status (those belong to everybody).
 */
class UsersController extends Controller
{
    private function rules(?int $ignore, bool $platform): array
    {
        return [
            'name'                   => ['required', 'string', 'max:255'],
            'email'                  => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($ignore), 'required_without:phone'],
            'phone'                  => ['nullable', 'string', 'max:15', Rule::unique('users', 'phone')->ignore($ignore), 'required_without:email'],
            'password'               => $ignore ? ['nullable', 'string', 'min:8'] : ['required', 'string', 'min:8'],
            'is_admin'               => $platform ? ['boolean'] : ['prohibited'],
            'is_active'              => ['boolean'],
            'companies'              => $platform ? ['nullable', 'array'] : ['required', 'array', 'min:1'],
            'companies.*.company_id' => ['required', 'integer', 'exists:companies,id', 'distinct'],
            'companies.*.role_id'    => ['required', 'integer', 'exists:roles,id'],
            'companies.*.is_default' => ['boolean'],
            'year_ids'               => ['nullable', 'array'],
            'year_ids.*'             => ['integer', 'exists:account_years,id'],
        ];
    }

    private function format(User $u, ?array $limit): array
    {
        $roles = Role::pluck('name', 'id');
        $companies = $u->companies->filter(fn ($c) => $limit === null || in_array($c->id, $limit, true));

        return array_merge($u->toArray(), [
            'companies' => $companies->map(fn ($c) => [
                'company_id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'status' => $c->status,
                'role_id' => $c->pivot->role_id, 'role_name' => $roles[$c->pivot->role_id] ?? null, 'is_default' => (bool) $c->pivot->is_default,
            ])->values(),
            'year_ids'    => $u->years->pluck('id')->values(),
            'year_labels' => $u->years->sortBy('start_date')->pluck('label')->values(),
            'shared'      => $limit !== null && $u->companies->pluck('id')->diff($limit)->isNotEmpty(),
        ]);
    }

    /** the user must belong to a company the company admin manages */
    private function findManaged(int $id, ?array $limit): User
    {
        $user = User::with(['companies', 'years'])->findOrFail($id);

        if ($limit !== null) {
            abort_if($user->is_admin || $user->companies->pluck('id')->intersect($limit)->isEmpty(), 404);
        }

        return $user;
    }

    public function index(Request $request): JsonResponse
    {
        $limit = AdminScope::limit($request->user());

        $users = User::with(['companies', 'years'])
            ->when($limit !== null, fn ($q) => $q->where('is_admin', false)->whereHas('companies', fn ($c) => $c->whereIn('companies.id', $limit)))
            ->when($request->filled('company_id'), fn ($q) => $q->whereHas('companies', fn ($c) => $c->where('companies.id', $request->company_id)))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w->where('name', 'LIKE', "%{$request->search}%")
                ->orWhere('email', 'LIKE', "%{$request->search}%")->orWhere('phone', 'LIKE', "%{$request->search}%")))
            ->when($request->input('active') === '1', fn ($q) => $q->where('is_active', true))
            ->when($request->input('active') === '0', fn ($q) => $q->where('is_active', false))
            ->orderBy('id')->get();

        return $this->ok($users->map(fn ($u) => $this->format($u, $limit)));
    }

    public function store(Request $request): JsonResponse
    {
        $limit = AdminScope::limit($request->user());
        $data = $request->validate($this->rules(null, $limit === null));
        $this->checkCompaniesAndRoles($data['companies'] ?? [], $limit);

        $user = DB::transaction(function () use ($data, $limit) {
            $user = User::create(collect($data)->only(['name', 'email', 'phone', 'password', 'is_admin', 'is_active'])->all() + ['is_user' => true, 'is_active' => true]);
            $this->syncMemberships($user, $data['companies'] ?? [], $limit);
            $user->years()->sync($data['year_ids'] ?? []);

            return $user;
        });

        return $this->ok($this->format($user->fresh(['companies', 'years']), $limit), 'User created', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $limit = AdminScope::limit($request->user());
        $platform = $limit === null;
        $user = $this->findManaged($id, $limit);
        $shared = !$platform && $user->companies->pluck('id')->diff($limit)->isNotEmpty();
        $self = $user->id === $request->user()->id;

        $data = $request->validate($this->rules($user->id, $platform));

        if ($platform && $self && (($data['is_admin'] ?? $user->is_admin) === false || ($data['is_active'] ?? $user->is_active) === false)) {
            return $this->fail('You cannot remove your own product admin access or disable yourself.', 422);
        }
        if (!$platform && $self) {
            return $this->fail('Ask another admin to change your own access.', 422);
        }

        $this->checkCompaniesAndRoles($data['companies'] ?? [], $limit);

        DB::transaction(function () use ($user, $data, $limit, $platform, $shared) {
            if (!$shared) {   // a shared user's own details belong to every company he works for
                $fill = collect($data)->only($platform ? ['name', 'email', 'phone', 'is_active', 'is_admin'] : ['name', 'email', 'phone', 'is_active'])->all();
                if (!empty($data['password'])) {
                    $fill['password'] = $data['password'];
                }
                $user->update($fill);

                if (array_key_exists('year_ids', $data)) {
                    $user->years()->sync($data['year_ids'] ?? []);
                }
                if (!empty($data['password']) || ($data['is_active'] ?? true) === false) {
                    $user->tokens()->delete();   // force re-login
                }
            }

            if (array_key_exists('companies', $data)) {
                $this->syncMemberships($user, $data['companies'], $limit);
            }
        });

        return $this->ok($this->format($user->fresh(['companies', 'years']), $limit), 'User updated');
    }

    /** product admin only (route middleware) */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        if ($user->id === $request->user()->id) {
            return $this->fail('You cannot delete your own account.', 422);
        }

        $user->tokens()->delete();
        $user->delete();   // orders, products ... keep their data (user_id becomes empty)

        return $this->ok(null, 'User deleted');
    }

    /** every company must be managed by the company admin, and the role must be shared or made for THAT company */
    private function checkCompaniesAndRoles(array $rows, ?array $limit): void
    {
        $roles = Role::whereIn('id', collect($rows)->pluck('role_id'))->get()->keyBy('id');

        foreach ($rows as $i => $row) {
            if ($limit !== null && !in_array((int) $row['company_id'], $limit, true)) {
                abort(403, 'You can only give access to your own company.');
            }
            $role = $roles[$row['role_id']] ?? null;
            if ($role && $role->company_id !== null && $role->company_id !== (int) $row['company_id']) {
                throw ValidationException::withMessages(["companies.$i.role_id" => ['This role belongs to another company.']]);
            }
        }
    }

    /**
     * Product admin: the list IS the user's access (companies not in it are removed).
     * Company admin: only his own companies are touched; memberships in other companies stay as they are.
     */
    private function syncMemberships(User $user, array $rows, ?array $limit): void
    {
        $rows = collect($rows);
        $current = $user->companies()->pluck('companies.id')->all();
        $scope = $limit ?? $current;                        // company ids this call may add / change / remove

        foreach ($current as $cid) {
            if (in_array($cid, $scope, true) && !$rows->contains('company_id', $cid)) {
                $user->companies()->detach($cid);
            }
        }
        foreach ($rows as $row) {
            $user->companies()->syncWithoutDetaching([$row['company_id'] => ['role_id' => $row['role_id'], 'is_default' => (bool) ($row['is_default'] ?? false)]]);
        }

        // exactly one default company
        $pivots = DB::table('company_user')->where('user_id', $user->id)->orderBy('id')->get();
        $keep = $pivots->firstWhere('is_default', 1) ?? $pivots->first();
        if ($keep) {
            DB::table('company_user')->where('user_id', $user->id)->update(['is_default' => false]);
            DB::table('company_user')->where('id', $keep->id)->update(['is_default' => true]);
        }
    }
}
