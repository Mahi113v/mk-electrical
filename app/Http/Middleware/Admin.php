<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class Admin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->is_admin && $user->is_active) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => 'You do not have permission to do this.',
        ], 403);
    }
}
