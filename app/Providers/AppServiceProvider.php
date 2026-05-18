<?php

namespace App\Providers;

use App\Models\Branch;
use App\Models\FrontendOrder;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Hardware\PrinterTransport\NullPrinterTransport;
use App\Services\Hardware\PrinterTransport\PrinterTransportInterface;
use App\Services\Hardware\PrinterTransport\TcpPrinterTransport;
use App\Services\Observability\SyncMetricsRecorder;
use App\Observers\ItemObserver;
use App\Observers\SoftDeleteAuditObserver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->bind(PrinterTransportInterface::class, function () {
            // [BYPASS-P3 / GATE_BYPASS_PRINTING_2026-05-08] NullPrinterTransport
            // also when printing.bypass.enabled — short-circuits TCP/IP send for
            // E2E flow validation. Production guard in boot() prevents activation
            // in APP_ENV=production.
            if ($this->app->environment('testing') || (bool) config('printing.bypass.enabled', false)) {
                return new NullPrinterTransport();
            }

            return new TcpPrinterTransport();
        });

        // [NEW-04] Single recorder instance per request — keeps internal state
        // (correlation cache, etc.) consistent across the call stack and avoids
        // re-instantiating the service for every metric write.
        $this->app->singleton(SyncMetricsRecorder::class);

        // [F-VERIFY-09-02 / PLAN_P11] HTTP idempotency repository.
        // Backed by Cache::store() — uses redis NX EX in prod, array in tests.
        $this->app->bind(
            \App\Services\Idempotency\IdempotencyKeyRepository::class,
            fn ($app) => new \App\Services\Idempotency\RedisIdempotencyKeyRepository(
                cacheStore: config('idempotency.cache_store'),
            ),
        );
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        Schema::defaultStringLength(191);

        $audit = SoftDeleteAuditObserver::class;
        Order::observe($audit);
        FrontendOrder::observe($audit);
        OrderItem::observe($audit);
        Branch::observe($audit);
        Item::observe(ItemObserver::class);
        ItemCategory::observe($audit);

        // SQLite (tests CI / phpunit.xml :memory:) n'implémente pas REGEXP par défaut.
        // OrderService / FrontendOrderService filtrent queue_number avec REGEXP '^A[0-9]+$'.
        $this->registerSqliteRegexpIfNeeded();

        if (app()->environment('production')) {
            // [P0 V1 Cloud-Prep insights 2026-05-18] POS hardware simulation
            // production guard. CLAUDE.md §8 forbids env-flag bypass of fiscal
            // invariants — POS_SIMULATION_HARDWARE is a dev convenience while
            // the physical cash drawer is not wired, and MUST be false in
            // production. Refusing to boot is the most explicit failure mode
            // (vs silent NF525 cash-trail corruption).
            if ((bool) config('pos.simulation_hardware', false)) {
                throw new \RuntimeException(
                    'POS_SIMULATION_HARDWARE must be false in production (NF525 compliance). '
                    . 'Set POS_SIMULATION_HARDWARE=false in your .env file or unset it, then '
                    . 'run `php artisan config:cache`.'
                );
            }

            // [BYPASS-P1 / GATE_BYPASS_MODE_2026-05-08] Production guard — refuse
            // boot if any bypass flag is enabled in production. Bypass mode is
            // strictly for local dev / staging E2E flow validation. Activating
            // it in prod would skip TPE validation and TCP/IP printer spool.
            if ((bool) config('payment.bypass.enabled', false)) {
                throw new \RuntimeException(
                    'PAYMENT_BYPASS_MODE=true is forbidden in production: bypass mode '
                    . 'short-circuits TPE validation. Set PAYMENT_BYPASS_MODE=false in '
                    . 'your .env file or unset it. See docs/runbooks/BYPASS_MODE_OPERATIONAL.md.'
                );
            }
            if ((bool) config('printing.bypass.enabled', false)) {
                throw new \RuntimeException(
                    'PRINTING_BYPASS_MODE=true is forbidden in production: bypass mode '
                    . 'short-circuits TCP/IP send to thermal printer. Set PRINTING_BYPASS_MODE=false '
                    . 'in your .env file or unset it. See docs/runbooks/BYPASS_MODE_OPERATIONAL.md.'
                );
            }

            // [GOAL-CMS-2026-05-18 M-R3-P0-C heal] R3 T-2.4.2 Sec S-1+S-5:
            // APP_DEBUG=true in production leaks stack traces, SQL, DB creds,
            // HMAC context, and even cache state via Whoops. SiteService:48
            // ALLOWS admin-UI writing of APP_DEBUG into .env (an env-edit
            // attack surface — see M-P0-D/E/F V1.0.2 backlog). Until the
            // EnvEditor allowlist heal lands, the production boot guard
            // refuses to boot when APP_DEBUG=true, mirroring the
            // POS_SIMULATION_HARDWARE / PAYMENT_BYPASS / PRINTING_BYPASS
            // patterns above. config('app.debug') reads the boot-cached
            // value so this fires before any request.
            if ((bool) config('app.debug', false)) {
                throw new \RuntimeException(
                    'APP_DEBUG=true is forbidden in production: enabling debug leaks '
                    . 'stack traces, SQL queries, DB credentials, and HMAC context. '
                    . 'Set APP_DEBUG=false in your .env file then run `php artisan config:cache`. '
                    . 'If APP_DEBUG was set true via the admin UI (SiteService), revoke and '
                    . 'redeploy. See R3 T-2.4.2 Sec for the env-edit lockdown V1.0.2 backlog.'
                );
            }

            // [Foundation Audit F-3 P0 / 2026-05-18] Idempotency middleware MUST
            // be enabled in production. Default in config/idempotency.php is
            // false for safe dev/CI roll-out (per PLAN_P11 §2). In production,
            // missing the IDEMPOTENCY_MIDDLEWARE_ENABLED env var silently
            // disables 23 idempotency-required routes (cash-drawer open/close,
            // refund-with-counter-entry, change-status × 6, kiosk paid order
            // confirm, etc — see config/idempotency.php `required_routes`).
            // Duplicate POST retries would become double-charges,
            // double-drawer-opens, double-status-changes. Same defensive
            // pattern as POS_SIMULATION_HARDWARE / APP_DEBUG / BROADCAST_DRIVER
            // guards above.
            if (! (bool) config('idempotency.enabled', false)) {
                throw new \RuntimeException(
                    'IDEMPOTENCY_MIDDLEWARE_ENABLED must be true in production '
                    . '(NF525-adjacent: prevents double-execute on 23 mutation routes '
                    . '— cash-drawer, refunds, status changes, payment confirms). '
                    . 'Set IDEMPOTENCY_MIDDLEWARE_ENABLED=true in your .env file '
                    . 'then run `php artisan config:cache`.'
                );
            }

            if (in_array(config('broadcasting.default'), [null, 'null'], true)) {
                throw new \RuntimeException(
                    'BROADCAST_DRIVER must be explicitly set in production (expected: pusher|redis). '
                    . 'Set BROADCAST_DRIVER in your .env file.'
                );
            }
            if (config('queue.default') === 'sync') {
                throw new \RuntimeException(
                    'QUEUE_CONNECTION must not be sync in production (expected: redis|database). '
                    . 'Set QUEUE_CONNECTION in your .env file.'
                );
            }

            /*
             * [W9-AUDIT B1-OPS] AuditLogService::write uses Cache::lock(audit_chain_b{n})
             * to serialize hash-chain inserts across concurrent workers. With CACHE_DRIVER=array
             * (or any in-process driver), the lock is per-process only, so two PHP-FPM workers
             * can write rows that collide on the UNIQUE(branch_id, prev_hash) index OR worse,
             * silently break the chain if the index is missing on a legacy install.
             * Fail-fast at boot if a non-shared cache driver is configured in production.
             */
            $cacheDriver = config('cache.default');
            $forbiddenCacheDrivers = ['array', 'null'];
            if (in_array($cacheDriver, $forbiddenCacheDrivers, true)) {
                throw new \RuntimeException(
                    "CACHE_DRIVER='{$cacheDriver}' is forbidden in production: NF525 audit chain integrity "
                    . 'requires a shared cache driver (redis or memcached) for cross-worker locks. '
                    . 'Set CACHE_DRIVER=redis (recommended) or CACHE_DRIVER=memcached in your .env file.'
                );
            }
        }
    }

    private function registerSqliteRegexpIfNeeded(): void
    {
        try {
            if (DB::connection()->getDriverName() !== 'sqlite') {
                return;
            }
            $pdo = DB::connection()->getPdo();
            if (!$pdo || $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
                return;
            }
            $pdo->sqliteCreateFunction(
                'regexp',
                static function ($pattern, $value) {
                    if ($value === null || $pattern === null || $pattern === '') {
                        return 0;
                    }
                    set_error_handler(static fn () => true);
                    $ok = @preg_match((string) $pattern, (string) $value);
                    restore_error_handler();

                    return ($ok === 1) ? 1 : 0;
                },
                2
            );
        } catch (\Throwable) {
            // PDO pas encore prêt ou fonction déjà enregistrée
        }
    }
}
