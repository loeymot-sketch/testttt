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
            if ($this->app->environment('testing')) {
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
