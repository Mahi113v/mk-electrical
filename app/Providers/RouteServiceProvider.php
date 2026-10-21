<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });

        // Sign in: 8 tries a minute for ONE account from ONE address (guessing a password), plus a wide ceiling per address.
        // Counting per address alone would lock a whole office (everybody behind one router) out at 9 o'clock.
        RateLimiter::for('login', function (Request $request) {
            $who = strtolower(trim((string) ($request->input('login') ?? $request->input('email')))) . '|' . $request->ip();

            return [Limit::perMinute(8)->by($who), Limit::perMinute(120)->by($request->ip())];
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
