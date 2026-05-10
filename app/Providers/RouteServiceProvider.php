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
            $userKey = $request->user()?->id ?? 'guest';

            return Limit::perMinute(
                (int) config('kiosk.order_rate_limit', 5)
            )->by(sprintf('kiosk:%s|%s', $userKey, $request->ip()))->response(function () {
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
            if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
                return Limit::perMinute(300)->by($request->user()?->id ?: $request->ip());
            }

            // POS = high-frequency cashier ops (SSOT preview / encaissement / payment / split / tip).
            // Admin-mutation 30/min CRUD bucket caused 429 "Too Many Attempts" on entire POS payment
            // flow when applied via nested route group. Whole pos/* namespace lifted to 120/min.
            // iter15-BUG-RATE-LIMIT 2026-05-10
            if ($request->is('api/admin/pos/*')) {
                return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
            }

            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('pos-quote', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('pos-order-create', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('pos-order-update', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });

        // [iter15-mega-fix D-001 2026-05-10] Kiosk-machine login uses a dedicated
        // limiter instead of the human-oriented `login-lockout` (10 attempts /
        // 10 min). KioskLoginComponent.vue retries up to 10× with backoff on
        // failure → a single misconfigured credential or a back-to-back test
        // run was burning the human-budget and triggering 429 "Too Many
        // Attempts" on the legitimate borne. The kiosk username is a hardware
        // credential, not a brute-force target; keying by `kiosk:<username>|<ip>`
        // and allowing 30/min preserves anti-abuse guardrails (still blocks
        // password-spray) while absorbing legitimate machine-retry behaviour
        // and CI test rerun cadence. Uses `username` field (not `email`).
        RateLimiter::for('kiosk-login', function (Request $request) {
            $username = $request->input('username');
            $rawIdentifier = is_string($username) && $username !== '' ? $username : 'anon';
            $identifier = Str::lower(trim($rawIdentifier));
            $key = 'kiosk:'.$identifier.'|'.$request->ip();
            $maxAttempts = max(1, (int) config('kiosk.login_rate_limit', 30));

            return Limit::perMinute($maxAttempts)->by($key)->response(function () {
                return response()->json([
                    'message' => 'Too many kiosk login attempts. Please try again shortly.',
                    'retry_after' => 60,
                ], 429);
            });
        });

        RateLimiter::for('login-lockout', function (Request $request) {
            // [W8.B REM B3] Fuzz protection : si client malveillant envoie email[]=foo,
            // is_string() empêche TypeError sur cast (string) array.
            $email    = $request->input('email');
            $username = $request->input('username');
            $rawIdentifier = is_string($email) && $email !== ''
                ? $email
                : (is_string($username) && $username !== '' ? $username : 'anon');
            $identifier = Str::lower($rawIdentifier);
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
