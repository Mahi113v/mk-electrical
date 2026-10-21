<?php

namespace App\Http\Middleware;

use App\Support\AdminScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Admin console: product admin, or a company admin of at least one company. Nobody else. */
class AdminPortal
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->user() || !AdminScope::canUsePortal($request->user())) {
            return response()->json(['success' => false, 'message' => 'You do not have permission to do this.'], 403);
        }

        return $next($request);
    }
}
