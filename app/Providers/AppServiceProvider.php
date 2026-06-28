<?php

namespace App\Providers;

use App\Models\Branch;
use App\Models\FrontendOrder;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ZReport;
use App\Services\Fiscal\AuditLogService;
use App\Services\Hardware\PrinterTransport\NullPrinterTransport;
use App\Services\Hardware\PrinterTransport\PrinterTransportInterface;
use App\Services\Hardware\PrinterTransport\TcpPrinterTransport;
use App\Services\Hardware\PrinterTransport\WindowsRawPrinterTransport;
use App\Services\Observability\SyncMetricsRecorder;
use App\Observers\ItemObserver;
use App\Observers\SoftDeleteAuditObserver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

            // [PRINT-SAGA 2026-06-24] Real-mode transport selection. Default 'tcp'
            // (network ESC/POS, port 9100). 'windows_raw' = USB thermal printer on a
            // Windows caisse (spool RAW via winspool). config('printing.driver').
            return match ((string) config('printing.driver', 'tcp')) {
                'windows_raw' => new WindowsRawPrinterTransport(),
                default => new TcpPrinterTransport(),
            };
        });

        // [CUSTOMER-DISPLAY 2026-06-28] SAGA pole display transport. 'windows_serial'
        // = USB→COM on the caisse PC ; 'null' = dev / no hardware (swallows bytes).
        $this->app->bind(
            \App\Services\Hardware\DisplayTransport\CustomerDisplayTransportInterface::class,
            fn () => match ((string) config('printing.customer_display.driver', 'windows_serial')) {
                'null' => new \App\Services\Hardware\DisplayTransport\NullCustomerDisplayTransport(),
                default => new \App\Services\Hardware\DisplayTransport\WindowsSerialDisplayTransport(),
            }
        );

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

        // [GOAL-K2-HEAL-06 2026-05-24] Phase K.7 K7-FIND-2 P2:
        // Z-close did NOT write any audit_logs HMAC anchor entry — only
        // logged to file channel 'fiscal'. NF525 chain has Z-chain
        // (z_reports.signature) AND audit_logs chain but no cross-chain
        // anchor binding the two. Forensic concern: a Z-close had no
        // audit_logs row, so an audit_logs walk alone could not detect
        // Z-close existence.
        //
        // Fix: ZReport::updated hook gated by `wasChanged('status')` and
        // status === STATUS_CLOSED + non-null signature writes an audit
        // entry 'z_report.closed' with sequence_no + signature + prev_hash.
        // Cross-chain anchor: forensic walker on audit_logs sees Z-close
        // events + can cross-reference z_reports table.
        //
        // ZReportService.php is FROZEN §7 — NOT modified. Hook applied at
        // the Eloquent model layer (ZReport.php, not frozen) wrapping the
        // frozen service without touching it.
        //
        // Why `updated` not `created`: open() calls ZReport::create() with
        // status=OPEN and no signature, while close() reuses the same row
        // via $open->forceFill(...)->save(), so the close event is an
        // UPDATE on the existing OPEN row. wasChanged('status') confirms
        // the transition fired in this save. The signature non-null guard
        // is belt-and-suspenders against a future save path that flips to
        // CLOSED without a signature.
        //
        // Best-effort wrap matches CashDrawerService::writeAuditLog: a
        // cache outage failing the audit append MUST NOT abort the Z
        // close itself — the Z-chain via z_reports.signature is still
        // valid; the cross-chain anchor is forensic-only.
        ZReport::updated(function (ZReport $zReport): void {
            try {
                if (! $zReport->wasChanged('status')) {
                    return;
                }
                if ($zReport->status !== ZReport::STATUS_CLOSED) {
                    return;
                }
                if (empty($zReport->signature)) {
                    return;
                }

                app(AuditLogService::class)->write([
                    'branch_id'   => (int) $zReport->branch_id,
                    'action'      => 'z_report.closed',
                    'resource'    => 'z_report',
                    'resource_id' => (int) $zReport->id,
                    'payload'     => [
                        'z_report_id' => (int) $zReport->id,
                        'sequence_no' => (int) $zReport->sequence_no,
                        'signature'   => (string) $zReport->signature,
                        'prev_hash'   => $zReport->prev_hash !== null
                            ? (string) $zReport->prev_hash
                            : null,
                        'closed_at'   => optional($zReport->closed_at)->toIso8601String(),
                        'closed_by'   => $zReport->closed_by !== null
                            ? (int) $zReport->closed_by
                            : null,
                        'total_ttc'   => (float) $zReport->total_ttc,
                        'order_count' => (int) $zReport->order_count,
                    ],
                ]);
            } catch (\Throwable $e) {
                // Cross-chain anchor missing = forensic limitation, NOT a
                // fiscal break. The Z-chain (z_reports.signature) remains
                // valid via its own HMAC. Surface the failure but do not
                // abort the Z close transaction.
                Log::warning('[GOAL-K2-HEAL-06] z_report.closed audit_logs anchor failed', [
                    'z_report_id' => $zReport->id,
                    'branch_id'   => $zReport->branch_id,
                    'sequence_no' => $zReport->sequence_no,
                    'exception'   => get_class($e),
                    'message'     => $e->getMessage(),
                ]);
            }
        });

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

            // [LCS-S-001 / 2026-05-19] Loyalty QR signing secret must be set
            // in production. Without it, LoyaltyQrSigner::sign / verify cannot
            // produce/validate a signed token — falling back to legacy plaintext
            // `FK:<code>` permanently, defeating the cross-surface fraud heal.
            // Mirrors the FISCAL_*_SECRET / POS_SIMULATION_HARDWARE guard pattern.
            // dev_sentinels + min_secret_length are enforced lazily by the signer
            // itself; this boot guard catches the "forgot to rotate" deploy mistake
            // at startup rather than at first QR mint.
            $loyaltyQrSecret = (string) config('loyalty.qr.secret', '');
            if ($loyaltyQrSecret === '') {
                throw new \RuntimeException(
                    'LOYALTY_QR_SECRET must be set in production (LCS-S-001). Without it, '
                    . 'the loyalty QR scan endpoint cannot verify signed tokens — every scan '
                    . 'falls back to the legacy plaintext FK:<code> path which is the '
                    . 'cross-surface fraud vector being healed. Generate with `openssl rand -hex 32` '
                    . 'and set LOYALTY_QR_SECRET=<value> in your .env file then '
                    . 'run `php artisan config:cache`. See config/loyalty.php for the rotation policy.'
                );
            }

            // [Foundation Audit F-3 P0 / 2026-05-18] APP_URL must be set in
            // production. config/cors.php derives `allowed_origins` from
            // array_filter([APP_URL, KIOSK_DOMAIN, ADMIN_DOMAIN]) — when
            // APP_URL is empty AND the kiosk browser hits the server via a
            // host alias (localhost vs 127.0.0.1 vs FQDN), CORS silently
            // rejects /api/broadcasting/auth. Polling fallback masks the
            // failure → real-time push has been silently dead. This guard
            // catches the "forgot to set APP_URL" deploy mistake.
            if (empty(config('app.url'))) {
                throw new \RuntimeException(
                    'APP_URL must be set in production (CORS allowed_origins '
                    . 'derives from it). Empty APP_URL means /api/broadcasting/auth '
                    . 'is silently rejected for kiosk/POS browsers when their host '
                    . 'differs from the server\'s default. Set APP_URL=https://your-domain '
                    . 'in your .env file then run `php artisan config:cache`. '
                    . 'For kiosk/admin on distinct domains, also set KIOSK_DOMAIN and '
                    . 'ADMIN_DOMAIN — config/cors.php allows all three.'
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

            // [GOAL-L2-HEAL-05 2026-05-24] Phase K.8 F-07 P2:
            // STRIPE_WEBHOOK_SECRET required for signature verification of
            // Stripe webhooks. Previously runtime soft-guard at Stripe.php
            // line 204-213 returned HTTP 500 'misconfigured' lazily on first
            // webhook hit; now refuses boot in production for fail-fast
            // surfacing (mirrors LOYALTY_QR_SECRET / FISCAL_*_SECRET pattern).
            //
            // Only enforced if the Stripe payment gateway is enabled in the
            // payment_gateways table (slug='stripe', status=Activity::ENABLE=5)
            // — same predicate Stripe.php:113 uses for its `paymentRequest()`
            // entry point. Config-driven (not env-driven) because Stripe is a
            // V1.0.X cloud-prep path and a fresh local install ships Stripe
            // disabled — boot-time refusal would break dev/test/seed flow.
            //
            // Try/catch guards against fresh-install windows where the
            // payment_gateways table is not yet migrated. In that state we
            // treat Stripe as disabled (safe: there is no gateway row that
            // could route a webhook), so the missing secret is not yet a
            // production hazard.
            try {
                $stripeEnabled = \DB::table('payment_gateways')
                    ->where('slug', 'stripe')
                    ->where('status', \App\Enums\Activity::ENABLE)
                    ->exists();
            } catch (\Throwable $e) {
                $stripeEnabled = false; // table not ready (fresh install / migration)
            }
            if ($stripeEnabled && empty(env('STRIPE_WEBHOOK_SECRET'))) {
                throw new \RuntimeException(
                    'STRIPE_WEBHOOK_SECRET must be set in production while the Stripe '
                    . 'payment gateway is enabled. Webhook signature verification at '
                    . '/payment/stripe-webhook/ returns HTTP 500 misconfigured when the '
                    . 'secret is empty. Configure the secret in Stripe Dashboard → '
                    . 'Developers → Webhooks, then set STRIPE_WEBHOOK_SECRET=<whsec_...> '
                    . 'in your .env file and run `php artisan config:cache`. To disable '
                    . 'the boot guard, deactivate the Stripe gateway in admin.'
                );
            }

            // [GOAL-L2-HEAL-06 2026-05-24] Phase L1.1 F-002 — SenangPay webhook
            // secret boot guard. Parity with LOYALTY_QR_SECRET above and with
            // L2-HEAL-05 (STRIPE_WEBHOOK_SECRET) immediately above.
            //
            // Why: Senangpay::webhook() (app/Http/PaymentGateways/Gateways/Senangpay.php:96-104)
            // verifies inbound HMAC-SHA-256 signatures with `senangpay_secret_key`.
            // If the operator activates the SenangPay gateway (payment_gateways
            // slug='senangpay', status=Activity::ENABLE=5) without configuring
            // the secret, EVERY webhook hit returns HTTP 500 'misconfigured' —
            // payments silently fail to capture and SenangPay enters its retry
            // storm (any non-200 is retried per the spec doc-block in
            // Senangpay.php line 24).
            //
            // Refuse-to-boot is the correct failure mode (vs. lazy 500): an
            // operator who can ssh + restart is far more likely to notice a
            // boot crash with a clear pointer than to scan webhook logs.
            //
            // Storage note (differs from Stripe): SenangPay's secret lives in
            // `gateway_options` (polymorphic morph rows under PaymentGateway),
            // NOT in env. We mirror the same lookup Senangpay::webhook performs
            // at line 94-95. The boot guard query is a single JOIN:
            //
            //   SELECT go.value
            //   FROM payment_gateways pg
            //   JOIN gateway_options go
            //     ON go.model_id = pg.id
            //    AND go.model_type = 'App\\Models\\PaymentGateway'
            //   WHERE pg.slug = 'senangpay'
            //     AND pg.status = 5  -- Activity::ENABLE
            //     AND go.option = 'senangpay_secret_key'
            //
            // Try/catch guards against fresh-install windows where the
            // payment_gateways / gateway_options tables are not yet migrated.
            // In that state we treat SenangPay as disabled (safe: there is no
            // gateway row that could route a webhook), so the missing secret
            // is not yet a production hazard. Matches the Stripe block's
            // soft-degrade.
            try {
                $senangpayEnabled = \DB::table('payment_gateways')
                    ->where('slug', 'senangpay')
                    ->where('status', \App\Enums\Activity::ENABLE)
                    ->exists();
            } catch (\Throwable $e) {
                $senangpayEnabled = false; // table not ready (fresh install / migration)
            }
            if ($senangpayEnabled) {
                try {
                    $senangpaySecret = (string) \DB::table('payment_gateways')
                        ->join('gateway_options', function ($join) {
                            $join->on('gateway_options.model_id', '=', 'payment_gateways.id')
                                ->where('gateway_options.model_type', '=', \App\Models\PaymentGateway::class);
                        })
                        ->where('payment_gateways.slug', 'senangpay')
                        ->where('payment_gateways.status', \App\Enums\Activity::ENABLE)
                        ->where('gateway_options.option', 'senangpay_secret_key')
                        ->value('gateway_options.value');
                } catch (\Throwable $e) {
                    // gateway_options table not ready — same soft-degrade as
                    // the existence probe above.
                    $senangpaySecret = '';
                }
                if ($senangpaySecret === '') {
                    throw new \RuntimeException(
                        'SENANGPAY_SECRET_KEY must be set in production while the SenangPay '
                        . 'payment gateway is enabled. Webhook signature verification at '
                        . '/senangpay-webhook returns HTTP 500 misconfigured when the '
                        . '`senangpay_secret_key` option is empty — payments silently fail '
                        . 'to capture and SenangPay enters a retry storm. Configure the '
                        . 'secret via Admin → Payment Gateway → SenangPay → Secret Key '
                        . '(stored as gateway_options.option=\'senangpay_secret_key\' under '
                        . 'payment_gateways.slug=\'senangpay\' status=5). To disable the boot '
                        . 'guard, deactivate the SenangPay gateway in admin.'
                    );
                }
            }

            // [GOAL-L2-HEAL-04 2026-05-24] L7.2 F-02 P1:
            // Production refuses to boot if MAIL_HOST resolves to a forbidden
            // IP range (loopback / link-local / RFC1918 / cloud-metadata).
            // Defends against an owner-self-target SSRF where the admin-UI
            // .env writer (MailService::update) had previously set
            // MAIL_HOST=169.254.169.254 or 192.168.x.x — any subsequent
            // Mail::send (password reset, order confirmation, invoice email)
            // would open TCP to that host, providing an internal-VPC probe
            // primitive post AWS cutover. Mirrors the
            // POS_SIMULATION_HARDWARE / APP_DEBUG / IDEMPOTENCY / APP_URL
            // / BROADCAST_DRIVER / CACHE_DRIVER prod-boot-guard pattern.
            //
            // Reads config('mail.mailers.smtp.host') (canonical Laravel 9+
            // path) with env('MAIL_HOST') fallback for the rare config-not-
            // booted edge case.
            //
            // Empty / unset MAIL_HOST is INTENTIONALLY NOT a fail-closed
            // condition — that is a config-completeness concern outside the
            // SSRF threat model and would otherwise brick boot on a fresh
            // install before the admin has configured mail. Garbage (non-
            // IP-literal) values also PASS — Mail::send fails downstream
            // anyway and we do not want to brick boot on parseable junk.
            $mailHost = config('mail.mailers.smtp.host') ?? env('MAIL_HOST');
            if (is_string($mailHost) && $mailHost !== '') {
                $rule = new \App\Rules\SafeRemoteHost();
                if (!$rule->passes('mail_host', $mailHost)) {
                    throw new \RuntimeException(
                        'MAIL_HOST is in a forbidden IP range in production '
                        . '(loopback / link-local / RFC1918 / cloud-metadata). '
                        . 'Set MAIL_HOST to a public mail server hostname (e.g. '
                        . 'smtp.mailgun.org, smtp.sendgrid.net, smtp.gmail.com), '
                        . 'then run `php artisan config:cache`. Configured value: ' . $mailHost
                    );
                }
            }
        }

        // [GOAL-F2-HEAL-02 2026-05-23] Per-session innodb_lock_wait_timeout
        // bounded at 5s to prevent FPM pool starvation under hot-row
        // contention (fiscal_sequence allocation + Idempotency-Key locks +
        // OrderStateMachine lockForUpdate). MySQL default 50s × worker pool
        // = realistic DoS surface. SQLite ignores this (SQLite has busyTimeout
        // 5s by default per Laravel); guard ensures noop on non-MySQL.
        // Env override: DB_LOCK_WAIT_TIMEOUT (default 5).
        if (app('db')->connection()->getDriverName() === 'mysql') {
            $timeout = (int) env('DB_LOCK_WAIT_TIMEOUT', 5);
            try {
                \DB::statement("SET SESSION innodb_lock_wait_timeout = ?", [$timeout]);
            } catch (\Throwable $e) {
                // Non-fatal — log + continue. Pool starvation is a perf risk,
                // not a correctness risk.
                \Log::warning('[F2-HEAL-02] Failed to set innodb_lock_wait_timeout', [
                    'error' => $e->getMessage(),
                ]);
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
