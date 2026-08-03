<?php

namespace Tests\Unit\Hardware;

use App\Services\Hardware\CustomerDisplayCommandBuilder as CD;
use App\Services\Hardware\CustomerDisplayService;
use App\Services\Hardware\DisplayTransport\NullCustomerDisplayTransport;
use PHPUnit\Framework\TestCase;

/**
 * [CUSTOMER-DISPLAY 2026-06-28] The SAGA pole shows ONLY the running total while
 * ringing up, and a welcome line when idle. Bytes are CD5220 (2x20), CP858.
 */
class CustomerDisplayServiceTest extends TestCase
{
    private function service(array $cfg = []): CustomerDisplayService
    {
        return new CustomerDisplayService(new NullCustomerDisplayTransport, array_merge([
            'enabled' => true,
            'code_page' => 19,
            'welcome_line1' => 'LE CAYENNE',
            'welcome_line2' => 'Soyez les bienvenus !',
            'total_label' => 'TOTAL',
        ], $cfg));
    }

    public function test_total_bytes_show_only_the_amount_right_aligned(): void
    {
        $bytes = $this->service()->totalBytes(24.20);
        $this->assertStringContainsString('TOTAL', $bytes);
        $this->assertStringContainsString('24,20 EUR', $bytes);
        // ESC @ init, clear, ESC Q A upper, ESC Q B lower.
        $this->assertStringContainsString("\x1B\x40", $bytes);
        $this->assertStringContainsString("\x1B\x51\x41", $bytes);
        $this->assertStringContainsString("\x1B\x51\x42", $bytes);
        // The amount line is right-aligned within 20 columns.
        $this->assertStringContainsString('           24,20 EUR', $bytes);
    }

    public function test_welcome_bytes_show_the_idle_message(): void
    {
        $bytes = $this->service()->welcomeBytes();
        $this->assertStringContainsString('LE CAYENNE', $bytes);
        $this->assertStringContainsString('Soyez les bienvenus', $bytes);
    }

    public function test_lines_are_capped_to_20_columns(): void
    {
        $svc = $this->service(['welcome_line1' => 'CECI EST UN MESSAGE BEAUCOUP TROP LONG']);
        $bytes = $svc->welcomeBytes();
        // 38-char welcome must be cut to 20 (no overflow on a 2x20 panel).
        $this->assertStringContainsString('CECI EST UN MESSAGE ', $bytes);
        $this->assertStringNotContainsString('TROP LONG', $bytes);
    }

    public function test_disabled_service_sends_nothing(): void
    {
        $svc = $this->service(['enabled' => false]);
        $this->assertFalse($svc->showTotal(10.0));
        $this->assertFalse($svc->showWelcome());
    }

    public function test_enabled_service_sends_via_transport(): void
    {
        $this->assertTrue($this->service()->showTotal(10.0));
        $this->assertTrue($this->service()->showWelcome());
    }
}
