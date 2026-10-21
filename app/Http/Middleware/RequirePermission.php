<?php

namespace App\Http\Middleware;

use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** ->middleware('can_do:sales.create')  (any one of several: 'can_do:orders.create,orders.update') */
class RequirePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $tenant = app(Tenant::class);

        foreach ($permissions as $permission) {
            if ($tenant->can($permission)) {
                return $next($request);
            }
        }

        return response()->json(['success' => false, 'message' => 'You do not have permission to do this.'], 403);
    }
}
