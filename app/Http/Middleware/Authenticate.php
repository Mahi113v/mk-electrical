<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * This app is a SPA + token API, so there is no server-side login page to
     * redirect to (the old code pointed at a route('login') that does not exist).
     */
    protected function redirectTo(Request $request): ?string
    {
        return null;
    }
}
