<?php

namespace App\Http\Controllers;

use App\Models\AccountYear;
use App\Models\CompanyYear;
use App\Models\Role;
use App\Models\User;
use App\Support\AdminScope;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    /**
     * USER login (/login): company admin, manager, stock keeper, sales, staff, viewer ...
     * Needs the account year chosen on the login page. A product admin can NOT sign in here.
     */
    public function login(Request $request): JsonResponse
    {
        [$user, $error] = $this->authenticate($request);
        if ($error) {
            return $error;
        }

        if ($user->is_admin) {
            return $this->fail('This product admin account cannot sign in here.', 403);
        }

        $context = $this->context($user);

        if (!$context['companies']) {
            return $this->fail('No active company is assigned to your account. Please contact the admin.', 403);
        }
        if (!$context['years']) {
            return $this->fail('No account year is assigned to your account. Please contact the admin.', 403);
        }

        $years = collect($context['years']);
        $yearId = $request->input('year_id');
        if ($yearId && !$years->contains('id', (int) $yearId)) {
            return $this->fail('You do not have access to the selected account year.', 403);
        }
        $yearId = $yearId ? (int) $yearId : ($years->firstWhere('is_current', true)['id'] ?? $years->first()['id'] ?? null);

        $default = collect($context['companies'])->firstWhere('is_default', true) ?? $context['companies'][0];

        return response()->json([
            'success'    => true,
            'message'    => 'Login successful',
            'token'      => $this->issueToken($user, 'app'),
            'user'       => $user,
            'portal'     => 'app',
            'companies'  => $context['companies'],
            'years'      => $context['years'],
            'year_id'    => $yearId,
            'company_id' => $default['id'],
        ]);
    }

    /**
     * ADMIN login (/admin): product admin and company admin only.
     * The token it gives works on the admin screens only (and the user token works on company screens only).
     */
    public function adminLogin(Request $request): JsonResponse
    {
        [$user, $error] = $this->authenticate($request);
        if ($error) {
            return $error;
        }

        if (!AdminScope::canUsePortal($user)) {
            return $this->fail('This account cannot sign in here.', 403);
        }

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'token'   => $this->issueToken($user, 'admin'),
            'user'    => $user,
        ] + $this->adminContext($user));
    }

    /** @return array{0: ?User, 1: ?JsonResponse} */
    private function authenticate(Request $request): array
    {
        $login = trim((string) ($request->input('login') ?? $request->input('email')));
        $request->merge(['login' => $login]);
        $request->validate([
            'login'    => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'year_id'  => ['nullable', 'integer'],
        ]);

        $field = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';
        $user  = User::where($field, $login)->first();

        // same message for "no such user" and "wrong password"
        if (!$user || !Hash::check($request->password, $user->password)) {
            return [null, $this->fail('The provided credentials do not match our records.', 401)];
        }
        if (!$user->is_active) {
            return [null, $this->fail('Your account is disabled. Please contact the admin.', 403)];
        }

        return [$user, null];
    }

    private function issueToken(User $user, string $portal): string
    {
        $user->forceFill(['last_login_at' => now()])->save();

        // keep the 5 most recent logins (phone + computer + ...) and drop older tokens
        $user->tokens()->orderByDesc('id')->skip(4)->take(100)->get()->each->delete();

        return $user->createToken($portal . '-token', [$portal])->plainTextToken;
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return $this->ok(null, 'Logout successful');
    }

    /** Fresh user + companies (with role permissions) + years. The frontend calls this after reload / switching. */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->tokenCan('admin') && !$user->tokenCan('app')) {
            return response()->json(['success' => true, 'user' => $user] + $this->adminContext($user));
        }

        return response()->json(['success' => true, 'user' => $user, 'portal' => 'app'] + $this->context($user));
    }

    /** Public: years shown on the login page */
    public function years(): JsonResponse
    {
        return $this->ok(AccountYear::orderByDesc('start_date')->get(['id', 'code', 'label', 'start_date', 'end_date']));
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name'  => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:15', Rule::unique('users', 'phone')->ignore($user->id)],
        ]);

        $user->update($data);

        return $this->ok($user->fresh(), 'Profile updated');
    }

    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'string'],
            'password'         => ['required', 'string', 'min:8', 'confirmed', 'different:current_password'],
        ]);

        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return $this->fail('Current password is incorrect.', 422, [
                'errors' => ['current_password' => ['Current password is incorrect.']],
            ]);
        }

        $user->update(['password' => $request->password]);
        $user->tokens()->where('id', '!=', $user->currentAccessToken()->id)->delete();

        return $this->ok(null, 'Password changed');
    }

    /** What the admin console needs: who he is and which companies he manages */
    private function adminContext(User $user): array
    {
        return [
            'portal'  => 'admin',
            'scope'   => $user->is_admin ? 'platform' : 'company',
            'managed' => AdminScope::managedCompanies($user)->map(fn ($c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name])->values()->all(),
        ];
    }

    /** @return array{companies: array, years: array}  companies of a company user, each with his role and permissions */
    private function context(User $user): array
    {
        $locks = CompanyYear::where('is_locked', true)->get()->groupBy('company_id');
        $roles = Role::all()->keyBy('id');

        $companies = $user->companies()->where('status', 'active')->orderBy('name')->get()->map(function ($c) use ($roles) {
            $role = $roles[$c->pivot->role_id] ?? null;

            return [
                'company' => $c, 'role' => $role ? ['id' => $role->id, 'code' => $role->code, 'name' => $role->name] : null,
                'permissions' => $role?->code === 'company_admin' ? Permissions::all() : ($role?->permissions ?? []),
                'is_default' => (bool) $c->pivot->is_default,
            ];
        });
        $years = $user->years()->orderByDesc('start_date')->get();

        return [
            'companies' => $companies->map(fn ($r) => [
                'id'                   => $r['company']->id,
                'code'                 => $r['company']->code,
                'name'                 => $r['company']->name,
                'gstin'                => $r['company']->gstin,
                'phone'                => $r['company']->phone,
                'email'                => $r['company']->email,
                'address'              => collect([$r['company']->address, $r['company']->city, $r['company']->state, $r['company']->pincode])->filter()->implode(', '),
                'allow_negative_stock' => $r['company']->allow_negative_stock,
                'role'                 => $r['role'],
                'permissions'          => $r['permissions'],
                'is_default'           => $r['is_default'],
                'locked_year_ids'      => ($locks[$r['company']->id] ?? collect())->pluck('account_year_id')->values(),
            ])->values()->all(),
            'years' => $years->map(fn ($y) => [
                'id' => $y->id, 'code' => $y->code, 'label' => $y->label, 'is_current' => $y->is_current,
                'start_date' => $y->start_date->toDateString(), 'end_date' => $y->end_date->toDateString(),
            ])->values()->all(),
        ];
    }
}
