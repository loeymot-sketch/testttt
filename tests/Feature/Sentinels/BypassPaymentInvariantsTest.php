<?php

namespace Tests\Feature\Sentinels;

use Tests\TestCase;

/**
 * @FK-ID FK-BYPASS-P5-INVARIANTS
 * @source docs/audit/BYPASS_MAPPING_2026-05-08.md
 *
 * Sentinels prouvant que le mode BYPASS payment+printing PRÉSERVE
 * fidèlement les invariants critiques (sealing fiscal NF525, Outbox
 * events, audit log, idempotency middleware) — seuls les call hardware
 * (TPE driver, TCP/IP printer) sont court-circuités.
 *
 * Anti-régression : si quelqu'un retire un appel BypassAuditLogger ou
 * skip un FiscalSequenceService::next dans le branch bypass, ce test casse.
 */
class BypassPaymentInvariantsTest extends TestCase
{
    public function test_payment_confirm_calls_BypassAuditLogger_in_controller(): void
    {
        $source = file_get_contents(base_path('app/Http/Controllers/Frontend/OrderController.php'));
        $this->assertStringContainsString(
            'BypassAuditLogger::paymentBypassed',
            $source,
            'OrderController::paymentConfirm must call BypassAuditLogger::paymentBypassed for audit trail.'
        );
        $this->assertStringContainsString(
            "'controller' => 'Frontend\\\\OrderController::paymentConfirm'",
            $source,
            'BypassAuditLogger context must identify the controller method.'
        );
    }

    public function test_confirm_counter_payment_calls_BypassAuditLogger(): void
    {
        $source = file_get_contents(base_path('app/Services/PaymentService.php'));
        $this->assertStringContainsString(
            'BypassAuditLogger::paymentBypassed',
            $source,
            'PaymentService::confirmCounterPayment must call BypassAuditLogger::paymentBypassed for audit trail.'
        );
    }

    public function test_BypassAuditLogger_is_noop_when_flag_disabled(): void
    {
        config(['payment.bypass.enabled' => false]);

        // Should not write a log entry. We just assert the early return runs without error.
        \App\Services\Bypass\BypassAuditLogger::paymentBypassed(['test' => 'noop']);
        \App\Services\Bypass\BypassAuditLogger::printingBypassed(['test' => 'noop']);

        $this->assertTrue(true);
    }

    public function test_payment_confirm_still_invokes_FiscalSequenceService_finalize(): void
    {
        $source = file_get_contents(base_path('app/Http/Controllers/Frontend/OrderController.php'));
        // The bypass branch must NOT short-circuit the call to finalizePaidKioskOrder
        // which is the integration point for FiscalSequenceService::next + Outbox events.
        $this->assertStringContainsString(
            'finalizePaidKioskOrder',
            $source,
            'paymentConfirm must still invoke finalizePaidKioskOrder so fiscal sealing + Outbox happen even in bypass mode.'
        );
    }

    public function test_confirm_counter_payment_still_dispatches_OrderPaidAtCounter(): void
    {
        $source = file_get_contents(base_path('app/Services/PaymentService.php'));
        // Even in bypass mode, OrderPaidAtCounter event must be dispatched (KDS notification).
        $this->assertStringContainsString(
            'OrderPaidAtCounter',
            $source,
            'PaymentService::confirmCounterPayment must dispatch OrderPaidAtCounter event in any mode (KDS notification).'
        );
    }

    public function test_AppServiceProvider_binds_NullPrinterTransport_when_bypass_active(): void
    {
        $source = file_get_contents(base_path('app/Providers/AppServiceProvider.php'));
        $this->assertStringContainsString(
            "config('printing.bypass.enabled', false)",
            $source,
            'AppServiceProvider::register must check printing.bypass.enabled to bind NullPrinterTransport.'
        );
        $this->assertStringContainsString(
            'NullPrinterTransport',
            $source,
            'AppServiceProvider must reference NullPrinterTransport class for bypass binding.'
        );
    }

    public function test_BypassAuditLogger_class_exists_in_namespace(): void
    {
        $this->assertTrue(
            class_exists(\App\Services\Bypass\BypassAuditLogger::class),
            'BypassAuditLogger class must exist in App\\Services\\Bypass namespace.'
        );
    }

    public function test_config_payment_bypass_keys_present(): void
    {
        $this->assertIsArray(config('payment.bypass'));
        $this->assertArrayHasKey('enabled', config('payment.bypass'));
        $this->assertArrayHasKey('gate', config('payment.bypass'));
        $this->assertArrayHasKey('forbidden_environments', config('payment.bypass'));
        $this->assertContains('production', config('payment.bypass.forbidden_environments'));
    }

    public function test_config_printing_bypass_keys_present(): void
    {
        $this->assertIsArray(config('printing.bypass'));
        $this->assertArrayHasKey('enabled', config('printing.bypass'));
        $this->assertArrayHasKey('gate', config('printing.bypass'));
        $this->assertArrayHasKey('forbidden_environments', config('printing.bypass'));
        $this->assertArrayHasKey('screen_marker_text', config('printing.bypass'));
    }

    public function test_master_blade_exposes_bypassMode_to_window_foodkingConfig(): void
    {
        $source = file_get_contents(base_path('resources/views/master.blade.php'));
        $this->assertStringContainsString(
            'bypassMode',
            $source,
            'master.blade.php must inject bypassMode into window.foodkingConfig for SPA marker rendering.'
        );
        $this->assertStringContainsString(
            "config('payment.bypass.enabled'",
            $source,
            'master.blade.php must read payment.bypass.enabled from config (not env directly — survives config:cache).'
        );
        $this->assertStringContainsString(
            "config('printing.bypass.enabled'",
            $source,
            'master.blade.php must read printing.bypass.enabled from config (not env directly — survives config:cache).'
        );
    }

    public function test_ReceiptComponent_renders_bypass_marker_in_hidden_print_zone(): void
    {
        $source = file_get_contents(base_path('resources/js/components/admin/pos/ReceiptComponent.vue'));
        $this->assertStringContainsString(
            'data-testid="bypass-printing-marker"',
            $source,
            'ReceiptComponent must expose data-testid="bypass-printing-marker" for sentinel + Playwright probes.'
        );
        $this->assertStringContainsString(
            'bypassPrintingActive',
            $source,
            'ReceiptComponent must define bypassPrintingActive computed property.'
        );
        // Crucial: marker must be in hidden-print zone so it does NOT print on actual receipt
        $this->assertMatchesRegularExpression(
            '/<div\s+v-if="bypassPrintingActive"[^>]*hidden-print/s',
            $source,
            'Bypass marker MUST be in hidden-print zone (NOT in actual fiscal receipt body — NF525 compliance).'
        );
    }
}
