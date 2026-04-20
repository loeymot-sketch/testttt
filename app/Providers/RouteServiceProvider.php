<?php

namespace App\Providers;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     *
     * @return void
     */
    public function boot()
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
            $this->mapWebRoutes();
        });
    }

    /**
     * Configure the rate limiters for the application.
     *
     * @return void
     */
    protected function configureRateLimiting()
    {
        // [AUDIT-P1] req/min per user (authenticated) or per IP (guest/kiosk) — config(app.api_throttle_per_minute).
        // Per-route stricter limits (order creation, login-lockout, etc.) still apply.
        RateLimiter::for('api', function (Request $request) {
            $perMinute = max(1, (int) config('app.api_throttle_per_minute', 120));

            return Limit::perMinute($perMinute)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('kiosk-orders', function (Request $request) {
            return Limit::perMinute(
                (int) config('kiosk.order_rate_limit', 5)
            )->by($request->ip())->response(function () {
                return response()->json([
                    'message' => 'Trop de commandes. Veuillez patienter.',
                    'retry_after' => 60,
                ], 429);
            });
        });

        RateLimiter::for('kiosk-menu', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('admin-mutation', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('pos-order-create', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('pos-order-update', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('login-lockout', function (Request $request) {
            $identifier = Str::lower((string) $request->input('email', ''));
            if ($identifier === '') {
                $identifier = Str::lower((string) $request->input('username', ''));
            }
            $key = $identifier.'|'.$request->ip();
            $maxAttempts = max(1, (int) config('auth.login_lockout.max_attempts', 10));
            $decayMinutes = max(1, (int) config('auth.login_lockout.decay_minutes', 10));

            return Limit::perMinutes($decayMinutes, $maxAttempts)->by($key)->response(function () use ($decayMinutes) {
                return response()->json([
                    'message' => 'Too many login attempts. Please try again later.',
                    'retry_after' => $decayMinutes * 60,
                ], 429);
            });
        });
    }

    protected function mapWebRoutes()
    {
        if (file_exists(storage_path('installed'))) {

            try {
                $files = scandir(__DIR__ . '/../Http/PaymentGateways/Routes');
                if (count($files) > 2) {
                    foreach ($files as $file) {
                        if ($file != '.' && $file != '..') {
                            Route::middleware('web')
                                ->group(__DIR__ . "/../Http/PaymentGateways/Routes/{$file}");
                        }
                    }
                }
            } catch (Exception $e) {
                Log::info($e->getMessage());
            }
        }
    }
}
