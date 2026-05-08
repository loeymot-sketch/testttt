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
        // [AUDIT-P1] 120 req/min per user (authenticated) or per IP (guest/kiosk).
        // 120 is safe for kiosk boot (menu + categories) while blocking abuse.
        // Per-route stricter limits (order creation = 10/min, login = 5/min) still apply.
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
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

        // [V1 SYNC_ROBUSTNESS 3.4] /api/broadcasting/auth was unthrottled.
        // 20/min per user (or per IP for unauthenticated requests) blocks
        // token brute-force and replay-amplification while leaving plenty
        // of headroom for legitimate channel subscriptions on POS/Kiosk/KDS.
        RateLimiter::for('broadcasting-auth', function (Request $request) {
            return Limit::perMinute(20)->by($request->user()?->id ?: $request->ip());
        });

        // [PARALLEL-TRACK-1.2] Delivery-platform webhook ingest.
        // 1000/min is generous (Uber Eats peak ≈ 200/min on a 50-restaurant
        // tenant) but blocks runaway retries from a misbehaving aggregator.
        // Keyed by platform + IP so a misconfigured Deliveroo cannot starve
        // Uber Eats traffic. The numeric ceiling lives in
        // config('delivery_platforms.rate_limit.webhooks') so ops can tune
        // without redeploying.
        RateLimiter::for('delivery-webhooks', function (Request $request) {
            $raw = (string) config('delivery_platforms.rate_limit.webhooks', '1000,1');
            $parts = explode(',', $raw);
            $max = (int) ($parts[0] ?? 1000);
            $minutes = (int) ($parts[1] ?? 1);
            $platform = (string) $request->route('platform', 'unknown');
            return Limit::perMinutes(max(1, $minutes), max(1, $max))
                ->by('delivery:' . $platform . ':' . $request->ip())
                ->response(function () {
                    return response()->json(['status' => 'rate_limited'], 429);
                });
        });

        RateLimiter::for('login-lockout', function (Request $request) {
            $key = Str::lower($request->input('email', '')).'|'.$request->ip();

            return Limit::perMinutes(10, 10)->by($key)->response(function () {
                return response()->json([
                    'message' => 'Too many login attempts. Please try again later.',
                    'retry_after' => 900,
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
