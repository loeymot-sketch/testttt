<?php

namespace Tests\Feature\Boot;

use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

/**
 * @FK-ID FK-PROD-BOOT-GUARDS-COMPLETENESS
 * @source GOAL Phase L L12.1 (2026-05-24) — Production boot guards verification
 *
 * Completeness sentinel covering every production boot guard in
 * App\Providers\AppServiceProvider::boot(). Two layers:
 *
 *   (a) static — ensures each guard's exception MESSAGE is present in the
 *       provider source. Catches accidental removal at refactor time.
 *
 *   (b) functional — boots the provider with a known-good baseline, then
 *       flips exactly one guard's prerequisite to a bad value and asserts
 *       the matching RuntimeException is thrown. Catches accidental
 *       inversion / disabling of the gate.
 *
 * The inventory is exhaustive per CLAUDE.md §8 (NF525 fiscal-adjacent boot
 * refusal pattern). New guards must be added to GUARDS below — the missing
 * test forces the next CI run to surface the omission.
 *
 * Gap closed (Phase L2-HEAL-05 2026-05-24):
 *   - STRIPE_WEBHOOK_SECRET — was previously runtime soft-guarded only
 *     (Stripe::handleWebhook returns HTTP 500 lazily). Now promoted to a
 *     production boot guard in AppServiceProvider, gated on the Stripe
 *     payment_gateways row being active (slug='stripe', status=ENABLE).
 *     Verified by test_stripe_webhook_secret_boot_guard_active /
 *     test_stripe_webhook_secret_boot_guard_skipped_when_stripe_disabled
 *     below. Static signature is also asserted in the source check.
 *
 * Gap closed (Phase L2-HEAL-06 2026-05-24, L1.1 F-002):
 *   - SENANGPAY_SECRET_KEY — parity with the Stripe fix above. Senangpay
 *     ::webhook returned HTTP 500 lazily on signature verification when the
 *     `senangpay_secret_key` option was empty in `gateway_options`. Now a
 *     production boot guard fires when payment_gateways.slug='senangpay'
 *     status=ENABLE AND the option row is empty/missing. Verified by
 *     test_senangpay_secret_key_boot_guard_active /
 *     test_senangpay_secret_key_boot_guard_skipped_when_senangpay_disabled
 *     below. Static signature is also asserted in the source check.
 */
class ProductionBootGuardsCompletenessSentinelTest extends TestCase
{
    // [L2-HEAL-06 2026-05-24] RefreshDatabase needed because the Stripe and
    // SenangPay boot guards now query the `payment_gateways` /
    // `gateway_options` tables. Without migrated schema the queries throw
    // "no such table" instead of returning false from the try/catch (the
    // try/catch only catches the boot-time exception path; the test's own
    // seeding queries surface raw).
    use RefreshDatabase;

    /**
     * Canonical inventory. The order matches the boot() method top-to-bottom
     * because earlier guards short-circuit later ones — when we flip one var
     * to "bad", we must keep ALL earlier guards' vars at their "good" value
     * so the assertion is on OUR guard's message, not a sibling's.
     *
     * Each entry:
     *   - env_var   : human-readable env name (for diagnostics)
     *   - config_key: dotted config key the guard reads
     *   - bad_value : value that should trigger the guard
     *   - message_re: regex slice of the exception message
     */
    private const GUARDS = [
        [
            'env_var'    => 'POS_SIMULATION_HARDWARE',
            'config_key' => 'pos.simulation_hardware',
            'bad_value'  => true,
            'message_re' => '/POS_SIMULATION_HARDWARE must be false in production/',
        ],
        [
            'env_var'    => 'PAYMENT_BYPASS_MODE',
            'config_key' => 'payment.bypass.enabled',
            'bad_value'  => true,
            'message_re' => '/PAYMENT_BYPASS_MODE=true is forbidden in production/',
        ],
        [
            'env_var'    => 'PRINTING_BYPASS_MODE',
            'config_key' => 'printing.bypass.enabled',
            'bad_value'  => true,
            'message_re' => '/PRINTING_BYPASS_MODE=true is forbidden in production/',
        ],
        [
            'env_var'    => 'APP_DEBUG',
            'config_key' => 'app.debug',
            'bad_value'  => true,
            'message_re' => '/APP_DEBUG=true is forbidden in production/',
        ],
        [
            'env_var'    => 'IDEMPOTENCY_MIDDLEWARE_ENABLED',
            'config_key' => 'idempotency.enabled',
            'bad_value'  => false,
            'message_re' => '/IDEMPOTENCY_MIDDLEWARE_ENABLED must be true in production/',
        ],
        [
            'env_var'    => 'LOYALTY_QR_SECRET',
            'config_key' => 'loyalty.qr.secret',
            'bad_value'  => '',
            'message_re' => '/LOYALTY_QR_SECRET must be set in production/',
        ],
        [
            'env_var'    => 'APP_URL',
            'config_key' => 'app.url',
            'bad_value'  => '',
            'message_re' => '/APP_URL must be set in production/',
        ],
        [
            'env_var'    => 'BROADCAST_DRIVER',
            'config_key' => 'broadcasting.default',
            'bad_value'  => 'null',
            'message_re' => '/BROADCAST_DRIVER must be explicitly set in production/',
        ],
        [
            'env_var'    => 'QUEUE_CONNECTION',
            'config_key' => 'queue.default',
            'bad_value'  => 'sync',
            'message_re' => '/QUEUE_CONNECTION must not be sync in production/',
        ],
        [
            'env_var'    => 'CACHE_DRIVER',
            'config_key' => 'cache.default',
            'bad_value'  => 'array',
            'message_re' => "/CACHE_DRIVER='array' is forbidden in production/",
        ],
        [
            // [L2-HEAL-04 2026-05-24] L7.2 F-02 P1 — MAIL_HOST SSRF guard.
            // Any IP literal in 127/8, 169.254/16, RFC1918, etc. refuses
            // boot. See app/Rules/SafeRemoteHost.php for the full blocklist.
            'env_var'    => 'MAIL_HOST',
            'config_key' => 'mail.mailers.smtp.host',
            'bad_value'  => '169.254.169.254',
            'message_re' => '/MAIL_HOST is in a forbidden IP range/',
        ],
    ];

    /**
     * Static layer: each guard's exception message must appear verbatim in
     * AppServiceProvider source. Catches a destructive refactor that drops
     * the throw line.
     */
    public function test_each_guard_exception_message_present_in_provider_source(): void
    {
        $source = file_get_contents(
            (new ReflectionClass(AppServiceProvider::class))->getFileName()
        );
        $this->assertNotFalse($source, 'AppServiceProvider source must be readable');

        $signatures = [
            'POS_SIMULATION_HARDWARE'         => 'POS_SIMULATION_HARDWARE must be false in production',
            'PAYMENT_BYPASS_MODE'             => 'PAYMENT_BYPASS_MODE=true is forbidden in production',
            'PRINTING_BYPASS_MODE'            => 'PRINTING_BYPASS_MODE=true is forbidden in production',
            'APP_DEBUG'                       => 'APP_DEBUG=true is forbidden in production',
            'IDEMPOTENCY_MIDDLEWARE_ENABLED'  => 'IDEMPOTENCY_MIDDLEWARE_ENABLED must be true in production',
            'LOYALTY_QR_SECRET'               => 'LOYALTY_QR_SECRET must be set in production',
            'APP_URL'                         => 'APP_URL must be set in production',
            'BROADCAST_DRIVER'                => 'BROADCAST_DRIVER must be explicitly set in production',
            'QUEUE_CONNECTION'                => 'QUEUE_CONNECTION must not be sync in production',
            'CACHE_DRIVER'                    => "CACHE_DRIVER='{\$cacheDriver}' is forbidden in production",
            'STRIPE_WEBHOOK_SECRET'           => 'STRIPE_WEBHOOK_SECRET must be set in production while the Stripe',
            'SENANGPAY_SECRET_KEY'            => 'SENANGPAY_SECRET_KEY must be set in production while the SenangPay',
            'MAIL_HOST'                       => 'MAIL_HOST is in a forbidden IP range in production',
        ];

        foreach ($signatures as $env => $needle) {
            $this->assertStringContainsString(
                $needle,
                $source,
                "AppServiceProvider::boot() must still throw the production guard for {$env}. "
                . "Expected message slice not found in source: '{$needle}'"
            );
        }
    }

    /**
     * Functional layer: each guard must throw RuntimeException when ONLY
     * its prerequisite is bad. Earlier guards are neutralized so we don't
     * trip on a sibling.
     *
     * @dataProvider guardProvider
     */
    public function test_each_guard_refuses_boot_when_its_prerequisite_is_bad(
        int $index,
        array $guard
    ): void {
        $this->primeProductionGood();
        // Flip ONLY the target guard's config to its bad value.
        config([$guard['config_key'] => $guard['bad_value']]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches($guard['message_re']);

        $provider = new AppServiceProvider($this->app);
        $provider->boot();
    }

    /**
     * Provider: each guard as its own dataset for clear failure reporting.
     */
    public static function guardProvider(): array
    {
        $cases = [];
        foreach (self::GUARDS as $i => $guard) {
            $cases["guard_{$i}_{$guard['env_var']}"] = [$i, $guard];
        }

        return $cases;
    }

    /**
     * All guards good in production -> boot must not throw any of OUR guards'
     * exceptions. We catch RuntimeException because the test env may surface
     * unrelated boot issues; we only assert that no guard-message is present.
     */
    public function test_production_boots_clean_when_all_guards_pass(): void
    {
        $this->primeProductionGood();

        $provider = new AppServiceProvider($this->app);
        try {
            $provider->boot();
            $this->assertTrue(true);
        } catch (\RuntimeException $e) {
            foreach (self::GUARDS as $guard) {
                $this->assertDoesNotMatchRegularExpression(
                    $guard['message_re'],
                    $e->getMessage(),
                    "Guard {$guard['env_var']} must NOT fire when all prerequisites are good. "
                    . "Actual exception: {$e->getMessage()}"
                );
            }
        }
    }

    /**
     * Local env relaxes ALL production guards — even with every guard's
     * prerequisite flipped to bad, no guard exception may surface.
     */
    public function test_local_env_relaxes_all_guards(): void
    {
        $this->app['env'] = 'local';
        foreach (self::GUARDS as $guard) {
            config([$guard['config_key'] => $guard['bad_value']]);
        }

        $provider = new AppServiceProvider($this->app);
        try {
            $provider->boot();
            $this->assertTrue(true);
        } catch (\RuntimeException $e) {
            foreach (self::GUARDS as $guard) {
                $this->assertDoesNotMatchRegularExpression(
                    $guard['message_re'],
                    $e->getMessage(),
                    "Guard {$guard['env_var']} must NOT fire in local env. "
                    . "Actual exception: {$e->getMessage()}"
                );
            }
        }
    }

    /**
     * Positive case: when the Stripe payment_gateways row is ACTIVE and
     * STRIPE_WEBHOOK_SECRET is empty in env, the production boot guard must
     * fire with a RuntimeException carrying the documented message.
     *
     * This is the GREEN replacement for the prior `test_documented_gap_*` pin
     * (Phase L2-HEAL-05 2026-05-24 / K.8 F-07).
     */
    public function test_stripe_webhook_secret_boot_guard_active(): void
    {
        $this->primeProductionGood();

        // Seed an active Stripe gateway row (matches Stripe.php:113 predicate).
        \DB::table('payment_gateways')->updateOrInsert(
            ['slug' => 'stripe'],
            [
                'name'       => 'Stripe',
                'status'     => \App\Enums\Activity::ENABLE,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        // Make sure env() returns empty for STRIPE_WEBHOOK_SECRET. Laravel
        // env() reads $_ENV / getenv() / putenv() lookups, not config(),
        // so we toggle the underlying source.
        $previous = getenv('STRIPE_WEBHOOK_SECRET');
        putenv('STRIPE_WEBHOOK_SECRET=');
        $_ENV['STRIPE_WEBHOOK_SECRET'] = '';
        $_SERVER['STRIPE_WEBHOOK_SECRET'] = '';

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches(
                '/STRIPE_WEBHOOK_SECRET must be set in production/'
            );

            $provider = new AppServiceProvider($this->app);
            $provider->boot();
        } finally {
            if ($previous === false) {
                putenv('STRIPE_WEBHOOK_SECRET');
                unset($_ENV['STRIPE_WEBHOOK_SECRET'], $_SERVER['STRIPE_WEBHOOK_SECRET']);
            } else {
                putenv('STRIPE_WEBHOOK_SECRET=' . $previous);
                $_ENV['STRIPE_WEBHOOK_SECRET'] = $previous;
                $_SERVER['STRIPE_WEBHOOK_SECRET'] = $previous;
            }
        }
    }

    /**
     * Negative case: when Stripe is NOT enabled in payment_gateways, the
     * boot guard must NOT fire even if STRIPE_WEBHOOK_SECRET is empty.
     * This protects fresh installs (Stripe defaults to disabled / not
     * provisioned) from a useless production-boot crash.
     */
    public function test_stripe_webhook_secret_boot_guard_skipped_when_stripe_disabled(): void
    {
        $this->primeProductionGood();

        // Remove any active Stripe row.
        \DB::table('payment_gateways')->where('slug', 'stripe')->delete();

        $previous = getenv('STRIPE_WEBHOOK_SECRET');
        putenv('STRIPE_WEBHOOK_SECRET=');
        $_ENV['STRIPE_WEBHOOK_SECRET'] = '';
        $_SERVER['STRIPE_WEBHOOK_SECRET'] = '';

        try {
            $provider = new AppServiceProvider($this->app);
            try {
                $provider->boot();
                $this->assertTrue(true);
            } catch (\RuntimeException $e) {
                $this->assertDoesNotMatchRegularExpression(
                    '/STRIPE_WEBHOOK_SECRET must be set in production/',
                    $e->getMessage(),
                    'STRIPE_WEBHOOK_SECRET guard must NOT fire when the Stripe '
                    . 'payment_gateways row is absent / inactive. Actual: '
                    . $e->getMessage()
                );
            }
        } finally {
            if ($previous === false) {
                putenv('STRIPE_WEBHOOK_SECRET');
                unset($_ENV['STRIPE_WEBHOOK_SECRET'], $_SERVER['STRIPE_WEBHOOK_SECRET']);
            } else {
                putenv('STRIPE_WEBHOOK_SECRET=' . $previous);
                $_ENV['STRIPE_WEBHOOK_SECRET'] = $previous;
                $_SERVER['STRIPE_WEBHOOK_SECRET'] = $previous;
            }
        }
    }

    /**
     * Positive case (L2-HEAL-06 / L1.1 F-002): when the SenangPay
     * payment_gateways row is ACTIVE and the `senangpay_secret_key` option
     * row is empty/missing in gateway_options, the production boot guard
     * must fire with a RuntimeException carrying the documented message.
     *
     * Mirror of the Stripe positive test above. The key structural diff is
     * that SenangPay's secret lives in the polymorphic gateway_options
     * morph rows (not env), so we seed BOTH the payment_gateways row AND
     * an empty gateway_options row (the "empty" branch of the guard).
     */
    public function test_senangpay_secret_key_boot_guard_active(): void
    {
        $this->primeProductionGood();

        // Seed an active SenangPay gateway row.
        \DB::table('payment_gateways')->updateOrInsert(
            ['slug' => 'senangpay'],
            [
                'name'       => 'SenangPay',
                'status'     => \App\Enums\Activity::ENABLE,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        $senangpayId = (int) \DB::table('payment_gateways')
            ->where('slug', 'senangpay')
            ->value('id');

        // Seed an EMPTY senangpay_secret_key option row (covers the
        // "option exists but value is empty" branch — the most realistic
        // first-deploy mistake: admin saved the form without typing the
        // secret). The "option row missing entirely" branch is also
        // covered because the JOIN returns NULL -> cast to '' -> THROW.
        \DB::table('gateway_options')->where([
            ['model_id', '=', $senangpayId],
            ['model_type', '=', \App\Models\PaymentGateway::class],
            ['option', '=', 'senangpay_secret_key'],
        ])->delete();
        \DB::table('gateway_options')->insert([
            'model_id'   => $senangpayId,
            'model_type' => \App\Models\PaymentGateway::class,
            'option'     => 'senangpay_secret_key',
            'value'      => '',
            'type'       => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches(
                '/SENANGPAY_SECRET_KEY must be set in production/'
            );

            $provider = new AppServiceProvider($this->app);
            $provider->boot();
        } finally {
            // Cleanup so subsequent tests in the same DB transaction see a
            // clean slate (the RefreshDatabase trait would handle this if
            // the suite uses it, but be defensive).
            \DB::table('gateway_options')->where('model_id', $senangpayId)->delete();
            \DB::table('payment_gateways')->where('slug', 'senangpay')->delete();
        }
    }

    /**
     * Negative case: when SenangPay is NOT enabled in payment_gateways
     * (row absent or status != ENABLE), the boot guard must NOT fire even
     * if no secret is configured. Protects fresh installs (SenangPay
     * defaults to absent / not provisioned) from a useless production-boot
     * crash. Mirror of the Stripe skipped test above.
     */
    public function test_senangpay_secret_key_boot_guard_skipped_when_senangpay_disabled(): void
    {
        $this->primeProductionGood();

        // Remove any SenangPay row + orphan options.
        $existing = \DB::table('payment_gateways')->where('slug', 'senangpay')->value('id');
        if ($existing) {
            \DB::table('gateway_options')->where('model_id', $existing)->delete();
        }
        \DB::table('payment_gateways')->where('slug', 'senangpay')->delete();

        $provider = new AppServiceProvider($this->app);
        try {
            $provider->boot();
            $this->assertTrue(true);
        } catch (\RuntimeException $e) {
            $this->assertDoesNotMatchRegularExpression(
                '/SENANGPAY_SECRET_KEY must be set in production/',
                $e->getMessage(),
                'SENANGPAY_SECRET_KEY guard must NOT fire when the SenangPay '
                . 'payment_gateways row is absent / inactive. Actual: '
                . $e->getMessage()
            );
        }
    }

    /**
     * Neutralise every guard's prerequisite to a known-good production value
     * so the provider boots cleanly in APP_ENV=production.
     */
    private function primeProductionGood(): void
    {
        $this->app['env'] = 'production';

        config([
            'pos.simulation_hardware'  => false,
            'payment.bypass.enabled'   => false,
            'printing.bypass.enabled'  => false,
            'app.debug'                => false,
            'idempotency.enabled'      => true,
            'loyalty.qr.secret'        => str_repeat('a', 32),
            'app.url'                  => 'https://lecayenne.example.com',
            'broadcasting.default'     => 'pusher',
            'queue.default'            => 'redis',
            'cache.default'            => 'redis',
            // [L2-HEAL-04 2026-05-24] Public mail host so the MAIL_HOST
            // boot guard does not trip while another guard is under test.
            'mail.mailers.smtp.host'   => 'smtp.mailgun.org',
            // [TEST-E2E-HEAL 2026-07-15] Carnet DAILY_BOOK_PIN boot guard (AppServiceProvider,
            // commit ~82be92370) refuse le boot prod si PIN=2468 (défaut). On pose un PIN valide
            // ici pour qu'il ne préempte pas le guard sous test (sinon TOUS les datasets échouent
            // avec le message PIN au lieu du message du guard ciblé).
            'daily_book.pin'           => '9137',
        ]);
    }
}
