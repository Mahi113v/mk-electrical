<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    /** Login with e-mail OR phone. Works for both admin and normal users. */
    public function login(Request $request): JsonResponse
    {
        // 'email' is kept for backwards compatibility with the old frontend
        $login = trim((string) ($request->input('login') ?? $request->input('email')));

        $request->merge(['login' => $login]);
        $request->validate([
            'login'    => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $field = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';
        $user  = User::where($field, $login)->first();

        // Same message for "no such user" and "wrong password" (no user enumeration)
        if (!$user || !Hash::check($request->password, $user->password)) {
            return $this->fail('The provided credentials do not match our records.', 401);
        }

        if (!$user->is_active) {
            return $this->fail('Your account is disabled. Please contact the admin.', 403);
        }

        $user->tokens()->delete();
        $token = $user->createToken($user->is_admin ? 'admin-token' : 'user-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'user'    => $user,
            'token'   => $token,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return $this->ok(null, 'Logout successful');
    }

    public function me(Request $request): JsonResponse
    {
        return $this->ok($request->user());
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

        $user->update(['password' => $request->password]);   // hashed by model cast
        $user->tokens()->where('id', '!=', $user->currentAccessToken()->id)->delete();

        return $this->ok(null, 'Password changed');
    }
}
