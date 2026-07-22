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

        // [GOAL RUPTURE-CARNET 2026-07-15 / W6 heal P2] Anti-bruteforce PIN du
        // Carnet : couche par-IP (5/min) + plafond GLOBAL (15/min toutes IP
        // confondues) — le throttle IP seul est contournable en spoofant
        // X-Forwarded-For (TrustProxies '*'), le plafond global ferme ce vecteur
        // sur un PIN à 10^4-10^6 combinaisons.
        RateLimiter::for('daily-book-pin', function (Request $request) {
            return [
                Limit::perMinute(5)->by('dbp-ip:'.$request->ip()),
                Limit::perMinute(15)->by('dbp-global'),
            ];
        });

        // [GOAL MEGA W-MOBILE 2026-07-22] Anti-bruteforce PIN du Stock mobile (/m).
        // Miroir de daily-book-pin : couche par-IP (5/min) + plafond GLOBAL (15/min
        // toutes IP confondues) — le PIN 4 chiffres exposé sur Internet est faible,
        // le plafond global ferme le vecteur X-Forwarded-For (TrustProxies '*').
        RateLimiter::for('mobile-stock-pin', function (Request $request) {
            return [
                Limit::perMinute(5)->by('msp-ip:'.$request->ip()),
                Limit::perMinute(15)->by('msp-global'),
            ];
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

        // [RATE-FIX 2026-07-10] /frontend/order/quote a SON PROPRE bucket. Avant, il partageait
        // celui de /frontend/order (kiosk-orders) : composer une commande déclenche plusieurs
        // quotes (aperçu tarif / gate panier) qui vidaient le budget de commandes → 2 commandes
        // d'affilée = « Trop de requêtes » (429). Le quote est un calcul de prix en lecture seule,
        // donc limite généreuse (défaut 120/min).
        RateLimiter::for('kiosk-quote', function (Request $request) {
            $userKey = $request->user()?->id ?? 'guest';

            return Limit::perMinute(
                (int) config('kiosk.quote_rate_limit', 120)
            )->by(sprintf('kioskq:%s|%s', $userKey, $request->ip()));
        });

        RateLimiter::for('kiosk-menu', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // [Wave Y RATE-LIMIT 2026-05-21] env-configurable admin-mutation ceiling.
        // Default 60/min (doubled from prior hardcoded 30/min) — still tight
        // enough for brute-force protection against admin-CRUD abuse but
        // generous enough that owner manual-test bursts (rapid Livré clicks
        // on online-order list, Cancel chains, etc.) don't burn the bucket.
        // Local dev raises via ADMIN_MUTATION_RATE_LIMIT=1000 in `.env` for
        // parity with POS/KDS knobs. NF525 chain unaffected — controllers
        // own the audit chain insert inside their own DB transaction.
        //
        // [GOAL Phase F.1 2026-05-23] Config lookup MOVED INSIDE the closure
        // so test-suite Config::set overrides take effect (Wave Y captured the
        // value at boot via `use ($adminMutationCap)`, freezing the limiter to
        // whatever ADMIN_MUTATION_RATE_LIMIT was set when the provider booted —
        // making test_admin_mutation_rate_limit_returns_429 silently break
        // once .env raised the local-dev ceiling). Per-request config() is the
        // canonical Laravel pattern for env-driven limiters.
        RateLimiter::for('admin-mutation', function (Request $request) {
            $adminMutationCap = max(1, (int) config('app.admin_mutation_rate_limit', 60));
            if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
                return Limit::perMinute(300)->by($request->user()?->id ?: $request->ip());
            }

            // POS = high-frequency cashier ops (SSOT preview / encaissement / payment / split / tip).
            // Admin-mutation 30/min CRUD bucket caused 429 "Too Many Attempts" on entire POS payment
            // flow when applied via nested route group. Whole pos/* namespace lifted to 120/min.
            // iter15-BUG-RATE-LIMIT 2026-05-10
            //
            // [Wave P R2 POS 2026-05-20] Pattern `api/admin/pos/*` only matches
            // paths WITH a trailing segment (e.g. `/pos/quote`). The bare
            // POST `/api/admin/pos` (PosController@store — the order create
            // endpoint) was falling through to the 30/min CRUD ceiling,
            // triggering "Trop de requêtes" on legitimate single-click confirms
            // during back-to-back cashier flows. Add `api/admin/pos` to the
            // lift so the endpoint itself benefits from the 120/min ceiling
            // (its own throttle:pos-order-create is the SSOT — env-knob 120/min
            // default, raised to 1000/min in local dev via O5). The stacked
            // admin-mutation ceiling here is the safety net against accidental
            // burst, not the primary cap.
            if ($request->is('api/admin/pos/*') || $request->is('api/admin/pos')) {
                return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
            }

            // [Wave R-1 P-OWNER 2026-05-20] KDS chef bump CTA hits
            // `/api/admin/kds-order/change-status/{order}` rapidly when multiple
            // orders sit in the queue (chef chains 3-4 orders in parallel per
            // owner's fast-food multi-chef workflow). Default 30/min CRUD
            // ceiling triggered "Trop de demandes, réessayer dans 30s" after
            // a handful of clicks. Lift KDS change-status namespace to
            // env-configurable 120/min (matches POS lift ceiling). The
            // dedicated `kds-bump` limiter below is the primary cap;
            // admin-mutation here is the safety net against accidental burst.
            if ($request->is('api/admin/kds-order/change-status/*')) {
                return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
            }

            // [Wave Y RATE-LIMIT 2026-05-21] Owner-facing rapid CTA family —
            // `online-order/change-status` (Livré on online orders list) and
            // `table-order/change-status` (table service status flips) are
            // owner-tested at burst rates (2-3 clicks/sec). The dedicated
            // `idempotency` middleware on these routes prevents duplicate
            // state transitions; throttle here is the safety net against
            // accidental burst, not the primary cap. Lifted to 120/min
            // (matches POS/KDS lift ceiling). NF525 chain unaffected (audit
            // chain insert is inside controller TX, not in the throttle).
            if ($request->is('api/admin/online-order/change-status/*')
                || $request->is('api/admin/table-order/change-status/*')) {
                return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
            }

            return Limit::perMinute($adminMutationCap)->by($request->user()?->id ?: $request->ip());
        });

        // [Wave O O-5 P-OWNER-5 2026-05-20] env-configurable ceilings
        // (config/pos.php → POS_RATE_LIMIT_*). Defaults match the prior
        // hard-coded values so production behaviour is unchanged; local
        // dev raises the ceiling in `.env` to absorb owner manual-test
        // bursts (repeated TPE retries while wiring simulation fixes
        // burned the 60/min order-create bucket → blanket 429).
        RateLimiter::for('pos-quote', function (Request $request) {
            $perMinute = max(1, (int) config('pos.rate_limit.quote', 120));
            return Limit::perMinute($perMinute)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('pos-order-create', function (Request $request) {
            $perMinute = max(1, (int) config('pos.rate_limit.order_create', 60));
            return Limit::perMinute($perMinute)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('pos-order-update', function (Request $request) {
            $perMinute = max(1, (int) config('pos.rate_limit.order_update', 120));
            return Limit::perMinute($perMinute)->by($request->user()?->id ?: $request->ip());
        });

        // [GOAL Phase F.1 2026-05-23] Menu availability toggle dedicated limiter.
        // The original hardcoded `throttle:60,1` at routes/api.php:256 tripped
        // empirically at call #60 with retry_after=25s when a manager bulk-86'd
        // items from StockRuptureDashboard during morning rush (9 POSTs in 1.4s
        // already known to trigger 429 per A-005/A-006/A-013 comment 2026-05-21).
        // Owner pain verbatim: "Trop de requêtes — patientez 30s/60s" after
        // validating N orders. Sibling-group structure (not nested) preserved
        // to avoid stacking; bucket name now matches a named limiter so the
        // ceiling is env-configurable. Prod default 60/min (matches prior
        // hardcoded ceiling for backwards compatibility); local dev raises via
        // MENU_AVAILABILITY_RATE_LIMIT=1000 in .env.
        RateLimiter::for('menu-availability', function (Request $request) {
            $perMinute = max(1, (int) config('app.menu_availability_rate_limit', 60));
            return Limit::perMinute($perMinute)->by($request->user()?->id ?: $request->ip());
        });

        // [Wave R-1 P-OWNER 2026-05-20] KDS chef bump CTA dedicated limiter.
        // Owner mandate: in a fast-food kitchen multiple chefs bump 3-4 orders
        // chained back-to-back. Default admin-mutation 30/min triggered 429
        // toast persistently after rapid clicks. Env-configurable ceiling so
        // local dev can absorb owner manual-test bursts (raise via
        // KDS_RATE_LIMIT_BUMP=1000 in `.env`). Prod default 120/min — generous
        // for any realistic fast-food kitchen pace while still capping abuse.
        RateLimiter::for('kds-bump', function (Request $request) {
            $perMinute = max(1, (int) config('kds.rate_limit_bump', 120));
            return Limit::perMinute($perMinute)->by($request->user()?->id ?: $request->ip());
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

        // [Sprint H5-B Z4-P2-05 2026-05-17] Public OSS endpoints
        // (/api/frontend/oss-order, /api/frontend/oss-order/popular-items)
        // are unauthenticated by design — they feed the customer-wall TV
        // in the restaurant lobby and must work without a session.
        //
        // Because they accept `?branch_id=N`, an attacker can probe the
        // existence of branches AND measure throughput / catalog size by
        // sweeping branch_ids. We mitigate by IP-keying a hard ceiling
        // (60 req/min/IP across BOTH endpoints — a single TV polls every
        // ~5-10s, ~12 req/min, so a fleet of 5 walls behind one NAT still
        // fits with headroom). Anything beyond is either a misconfigured
        // poll or an enumeration sweep.
        //
        // Logging of >10 distinct branch_id values from the same IP within
        // 5 min is deferred to V1.0.2 (see plans/v1-0-2 backlog).
        RateLimiter::for('oss-public', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip())->response(function () {
                return response()->json([
                    'message' => 'OSS rate limit exceeded.',
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
