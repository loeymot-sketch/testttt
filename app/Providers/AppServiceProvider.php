<?php

namespace App\Providers;

use App\Models\Branch;
use App\Models\FrontendOrder;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Observers\ItemObserver;
use App\Observers\SoftDeleteAuditObserver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
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

        // [SEC-1 / P1-11] Kiosk-specific token TTL bypass.
        //
        // Sanctum's Guard enforces TWO expiration checks per token (Guard::isValidAccessToken):
        //   1. created_at > now() - config('sanctum.expiration')   ← global window (480m staff)
        //   2. expires_at is not in the past                       ← per-token column
        //
        // Both must pass. We CANNOT bump the global window to 30 days without
        // weakening admin/POS sessions (LoginController, RefreshTokenController,
        // and ForgotPasswordController all read the same key to compute their
        // own expires_at). So we install an authentication callback that, for
        // tokens carrying the `kiosk:order` ability, ignores the global window
        // and trusts the column-level expires_at exclusively. Kiosk tokens are
        // gated solely by config('sanctum.kiosk_expiration') (default 30 days)
        // written into the personal_access_tokens.expires_at column at login
        // and refresh time. Admin/POS/staff tokens keep both checks unchanged.
        //
        // Rationale: kiosk hardware runs unattended; an 8h forced re-login is
        // operationally unrealistic. 30-day TTL bounds the blast radius of a
        // leaked token while remaining compatible with branch operation.
        Sanctum::authenticateAccessTokensUsing(function ($accessToken, bool $isValid) {
            $abilities = $accessToken->abilities ?? [];
            if (in_array('kiosk:order', $abilities, true)) {
                // Kiosk path: trust expires_at column only. Provider check is
                // already part of $isValid via Guard::hasValidProvider, but
                // since this app uses the default 'web' provider with no
                // custom provider binding on the sanctum guard, that check is
                // a no-op for our tokens — we don't need to recompute it.
                return ! $accessToken->expires_at || ! $accessToken->expires_at->isPast();
            }
            return $isValid;
        });

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
