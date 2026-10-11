<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Blocks deactivated users even if they still hold a valid token. */
class ActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && !$user->is_active) {
            $user->tokens()->delete();

            return response()->json(['success' => false, 'message' => 'Account is disabled.'], 401);
        }

        return $next($request);
    }
}
