<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Admin only: manage staff / admin accounts. */
class UsersController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $users = User::query()
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->search;
                $q->where(fn ($w) => $w->where('name', 'LIKE', "%{$s}%")
                    ->orWhere('email', 'LIKE', "%{$s}%")
                    ->orWhere('phone', 'LIKE', "%{$s}%"));
            })
            ->orderBy('id')->get();

        return $this->ok($users);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'      => ['required', 'string', 'max:255'],
            'email'     => ['nullable', 'email', 'max:255', 'unique:users,email', 'required_without:phone'],
            'phone'     => ['nullable', 'string', 'max:15', 'unique:users,phone', 'required_without:email'],
            'password'  => ['required', 'string', 'min:8'],
            'is_admin'  => ['boolean'],
            'is_active' => ['boolean'],
        ]);

        $user = User::create($data + ['is_user' => true]);

        return $this->ok($user, 'User created', 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'name'      => ['required', 'string', 'max:255'],
            'email'     => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone'     => ['nullable', 'string', 'max:15', Rule::unique('users', 'phone')->ignore($user->id)],
            'password'  => ['nullable', 'string', 'min:8'],
            'is_admin'  => ['boolean'],
            'is_active' => ['boolean'],
        ]);

        if ($user->id === $request->user()->id &&
            (($data['is_admin'] ?? $user->is_admin) === false || ($data['is_active'] ?? $user->is_active) === false)) {
            return $this->fail('You cannot remove your own admin access or disable yourself.', 422);
        }

        if (empty($data['password'])) {
            unset($data['password']);
        }

        $user->update($data);

        if (isset($data['password']) || ($data['is_active'] ?? true) === false) {
            $user->tokens()->delete();   // force re-login
        }

        return $this->ok($user->fresh(), 'User updated');
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            return $this->fail('You cannot delete your own account.', 422);
        }

        if ($user->orders()->exists()) {
            return $this->fail('This user has orders. Disable the account instead of deleting it.', 422);
        }

        $user->tokens()->delete();
        $user->delete();

        return $this->ok(null, 'User deleted');
    }
}
