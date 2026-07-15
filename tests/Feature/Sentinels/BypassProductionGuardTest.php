<?php

namespace Tests\Feature\Sentinels;

use Tests\TestCase;

/**
 * @FK-ID FK-BYPASS-P5-PROD-GUARD
 * @source docs/audit/BYPASS_MAPPING_2026-05-08.md
 *
 * Sentinel garantit que les flags PAYMENT_BYPASS_MODE / PRINTING_BYPASS_MODE
 * ne peuvent JAMAIS être activés en APP_ENV=production. AppServiceProvider::boot()
 * doit lever RuntimeException si un de ces flags est true en prod.
 *
 * Anti-régression : si quelqu'un retire le bloc prod-guard, ce test casse.
 */
class BypassProductionGuardTest extends TestCase
{
    public function test_payment_bypass_throws_in_production(): void
    {
        $this->app['env'] = 'production';
        config(['daily_book.pin' => '9137']); // [TEST-E2E-HEAL 2026-07-15] guard PIN Carnet ne préempte pas
        config(['payment.bypass.enabled' => true]);
        config(['printing.bypass.enabled' => false]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/PAYMENT_BYPASS_MODE=true is forbidden in production/');

        $provider = new \App\Providers\AppServiceProvider($this->app);
        $provider->boot();
    }

    public function test_printing_bypass_throws_in_production(): void
    {
        $this->app['env'] = 'production';
        config(['daily_book.pin' => '9137']); // [TEST-E2E-HEAL 2026-07-15] guard PIN Carnet ne préempte pas
        config(['payment.bypass.enabled' => false]);
        config(['printing.bypass.enabled' => true]);
        // Set the other prod-required configs so we hit the printing guard, not the broadcast guard
        config(['broadcasting.default' => 'pusher']);
        config(['queue.default' => 'redis']);
        config(['cache.default' => 'redis']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/PRINTING_BYPASS_MODE=true is forbidden in production/');

        $provider = new \App\Providers\AppServiceProvider($this->app);
        $provider->boot();
    }

    public function test_both_bypass_disabled_in_production_is_ok_for_bypass_check(): void
    {
        $this->app['env'] = 'production';
        config(['daily_book.pin' => '9137']); // [TEST-E2E-HEAL 2026-07-15] guard PIN Carnet ne préempte pas
        config(['payment.bypass.enabled' => false]);
        config(['printing.bypass.enabled' => false]);
        // Set required prod configs so the broadcast/queue/cache guards don't throw
        config(['broadcasting.default' => 'pusher']);
        config(['queue.default' => 'redis']);
        config(['cache.default' => 'redis']);

        $provider = new \App\Providers\AppServiceProvider($this->app);
        // Should not throw the bypass guards (may still throw other prod guards
        // depending on test env, so we just assert no bypass-related exception)
        try {
            $provider->boot();
            $this->assertTrue(true);
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString('BYPASS_MODE', $e->getMessage());
        }
    }

    public function test_bypass_allowed_in_local_env(): void
    {
        $this->app['env'] = 'local';
        config(['payment.bypass.enabled' => true]);
        config(['printing.bypass.enabled' => true]);

        $provider = new \App\Providers\AppServiceProvider($this->app);
        // Should NOT throw — bypass is allowed outside production
        $provider->boot();
        $this->assertTrue(true);
    }

    public function test_null_printer_transport_resolved_when_printing_bypass_enabled(): void
    {
        // Clear any existing binding cache by re-registering
        config(['printing.bypass.enabled' => true]);
        $provider = new \App\Providers\AppServiceProvider($this->app);
        $provider->register();

        $transport = $this->app->make(\App\Services\Hardware\PrinterTransport\PrinterTransportInterface::class);
        $this->assertInstanceOf(
            \App\Services\Hardware\PrinterTransport\NullPrinterTransport::class,
            $transport,
            'When PRINTING_BYPASS_MODE=true, PrinterTransportInterface must resolve to NullPrinterTransport'
        );
    }
}
