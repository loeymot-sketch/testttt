<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Enums\Status;
use App\Models\Printer;
use App\Services\Hardware\EscPosCommandBuilder;
use App\Services\Hardware\EscPosPrinterService;
use App\Services\Hardware\PrinterTransport\NullPrinterTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EscPosOpenDrawerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    public function test_open_drawer_command_emits_correct_esc_p_sequence(): void
    {
        $this->assertSame("\x1B\x70\x00\x19\xFA", EscPosCommandBuilder::openDrawerCommand());
    }

    public function test_open_drawer_uses_default_receipt_printer_for_branch(): void
    {
        $transport = new NullPrinterTransport();
        $service = new EscPosPrinterService($transport);

        $branch = Branch::factory()->create();

        Printer::query()->create([
            'branch_id' => $branch->id,
            'name' => 'Receipt',
            'type' => 'escpos_tcp',
            'host' => '127.0.0.1',
            'port' => 9100,
            'station' => 'receipt',
            'width_chars' => 48,
            'status' => Status::ACTIVE,
            'options' => null,
        ]);

        $result = $service->openDrawer(null, (int) $branch->id);

        $this->assertTrue($result['success']);
        $this->assertGreaterThan(0, $result['bytes_sent']);
        $this->assertArrayHasKey('printer_id', $result);
        $this->assertCount(1, $transport->sent);
        $this->assertSame("\x1B\x70\x00\x19\xFA", $transport->sent[0]['bytes']);
    }

    public function test_open_drawer_returns_error_when_no_printer_configured(): void
    {
        $transport = new NullPrinterTransport();
        $service = new EscPosPrinterService($transport);

        $branch = Branch::factory()->create();

        $result = $service->openDrawer(null, (int) $branch->id);

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
        $this->assertCount(0, $transport->sent);
    }
}
