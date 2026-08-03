<?php

namespace Tests\Feature\Bypass;

use App\Services\Bypass\BypassAuditLogger;
use App\Services\Hardware\EscPosPrinterService;
use App\Services\Hardware\PrinterTransport\NullPrinterTransport;
use App\Services\Hardware\PrinterTransport\PrinterTransportInterface;
use App\Models\Printer;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * @FK-ID FK-BYPASS-AUDIT-HEAL-P2
 * @source docs/audit/RED_BYPASS_AUDIT_2026-05-08.md
 *
 * RED-AUDIT a critiqué (P2) que 9/11 cases de BypassPaymentInvariantsTest
 * sont du source-grep (assertStringContainsString) — le "19 PHPUnit PASS"
 * était partiellement gonflé. Ce test ajoute des assertions RUNTIME RÉELLES :
 *
 *   1. BypassAuditLogger::paymentBypassed émet bien un Log::warning quand flag ON
 *   2. BypassAuditLogger::paymentBypassed est NO-OP quand flag OFF (pas de log)
 *   3. BypassAuditLogger::printingBypassed émet bien un Log::warning quand flag ON
 *   4. EscPosPrinterService::sendRaw appelle bien printingBypassed (anti-régression
 *      P1 dead-code RED-trouvé)
 *   5. NullPrinterTransport est bien retourné par container quand flag ON
 *
 * Anti-régression : si quelqu'un retire un wiring ou une assertion réelle,
 * ce test casse pour de vrai (pas juste un grep).
 */
class BypassRuntimeBehaviorTest extends TestCase
{
    public function test_paymentBypassed_emits_warning_when_flag_enabled(): void
    {
        config(['payment.bypass.enabled' => true]);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($message, $context) {
                return str_contains($message, '[BYPASS-PAYMENT]')
                    && isset($context['gate'])
                    && isset($context['env'])
                    && isset($context['order_id'])
                    && $context['order_id'] === 4242;
            });

        BypassAuditLogger::paymentBypassed(['order_id' => 4242]);
    }

    public function test_paymentBypassed_is_silent_when_flag_disabled(): void
    {
        config(['payment.bypass.enabled' => false]);

        Log::shouldReceive('warning')->never();

        BypassAuditLogger::paymentBypassed(['order_id' => 4242]);
        $this->assertTrue(true);
    }

    public function test_printingBypassed_emits_warning_when_flag_enabled(): void
    {
        config(['printing.bypass.enabled' => true]);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($message, $context) {
                return str_contains($message, '[BYPASS-PRINTING]')
                    && isset($context['gate'])
                    && isset($context['service'])
                    && $context['service'] === 'EscPosPrinterService::sendRaw';
            });

        BypassAuditLogger::printingBypassed(['service' => 'EscPosPrinterService::sendRaw']);
    }

    public function test_EscPosPrinterService_sendRaw_calls_printingBypassed(): void
    {
        // RED P1: BypassAuditLogger::printingBypassed était DEAD CODE avant le HEAL.
        // Ce test runtime prouve que sendRaw l'invoque maintenant.
        config(['printing.bypass.enabled' => true]);

        Log::shouldReceive('warning')
            ->atLeast()->once()
            ->withArgs(function ($message, $context) {
                return str_contains($message, '[BYPASS-PRINTING]')
                    && ($context['service'] ?? '') === 'EscPosPrinterService::sendRaw';
            });

        // Build a minimal Printer model in-memory (no DB write required for sendRaw)
        $printer = new Printer();
        $printer->id = 9999;
        $printer->host = '127.0.0.1';
        $printer->port = 9100;
        $printer->type = 'escpos_tcp';
        $printer->station = 'receipt';
        $printer->branch_id = 1;

        // Use NullPrinterTransport so no real socket call happens
        $service = new EscPosPrinterService(new NullPrinterTransport());
        $service->sendRaw($printer, "TEST_BYTES");
    }

    public function test_NullPrinterTransport_resolved_via_container_when_flag_enabled(): void
    {
        config(['printing.bypass.enabled' => true]);

        // Re-register to apply the flag (binding evaluates the closure on resolve)
        $provider = new \App\Providers\AppServiceProvider($this->app);
        $provider->register();

        $resolved = $this->app->make(PrinterTransportInterface::class);
        $this->assertInstanceOf(NullPrinterTransport::class, $resolved);
    }

    public function test_NullPrinterTransport_send_returns_true_silently(): void
    {
        // RED hypothesis: NullPrinterTransport must swallow without error
        $transport = new NullPrinterTransport();
        $result = $transport->send("ANY_BYTES", ['host' => '127.0.0.1', 'port' => 9100]);
        $this->assertTrue($result, 'NullPrinterTransport::send must return true to allow caller to proceed.');
    }
}
