<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Enums\Status;
use App\Models\Printer;
use App\Models\User;
use App\Services\Hardware\EscPosCommandBuilder;
use App\Services\Hardware\EscPosPrinterService;
use App\Services\Hardware\PrinterTransport\NullPrinterTransport;
use App\Services\Hardware\PrinterTransport\PrinterTransportInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrinterServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_test_print_returns_true_and_captures_escpos_payload(): void
    {
        $transport = new NullPrinterTransport();
        $service = new EscPosPrinterService($transport);
        $printer = $this->makePrinter();

        $ok = $service->testPrint($printer);

        $this->assertTrue($ok);
        $this->assertCount(1, $transport->sent);
        $this->assertStringStartsWith("\x1B@", $transport->sent[0]['bytes']);
    }

    public function test_test_print_embeds_the_printer_name_in_payload(): void
    {
        $transport = new NullPrinterTransport();
        $service = new EscPosPrinterService($transport);
        $printer = $this->makePrinter(['name' => 'Caisse Bar']);

        $service->testPrint($printer);

        $this->assertStringContainsString('Caisse Bar', $transport->sent[0]['bytes']);
    }

    public function test_open_drawer_sends_the_cash_drawer_sequence(): void
    {
        $transport = new NullPrinterTransport();
        $service = new EscPosPrinterService($transport);
        $printer = $this->makePrinter();

        $result = $service->openDrawer((int) $printer->id, (int) $printer->branch_id);

        $this->assertTrue($result['success']);
        $this->assertSame("\x1B\x70\x00\x19\xFA", $transport->sent[0]['bytes']);
    }

    public function test_send_raw_returns_false_when_transport_fails(): void
    {
        $service = new EscPosPrinterService(new FailingPrinterTransport());
        $printer = $this->makePrinter();

        $ok = $service->sendRaw($printer, EscPosCommandBuilder::init() . 'FAIL');

        $this->assertFalse($ok);
    }

    public function test_printer_model_is_branch_scoped(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();

        $printerA = Printer::query()->create($this->printerData($branchA->id, ['name' => 'A']));
        Printer::query()->create($this->printerData($branchB->id, ['name' => 'B']));

        $userA = User::factory()->create(['branch_id' => $branchA->id]);
        $userA->assignRole('POS Operator');

        $this->actingAs($userA);

        $ids = Printer::query()->pluck('id')->all();

        $this->assertSame([$printerA->id], $ids);
    }

    public function test_line_kv_respects_width_chars(): void
    {
        $line32 = EscPosCommandBuilder::lineKV('Printer', 'Kitchen', 32);
        $line48 = EscPosCommandBuilder::lineKV('Printer', 'Kitchen', 48);

        $this->assertSame(32, strlen(rtrim($line32, "\n")));
        $this->assertSame(48, strlen(rtrim($line48, "\n")));
        $this->assertNotSame($line32, $line48);
    }

    /**
     * [V14 C-β / SENTINEL FINDING C-β-T15-1 P2]
     * `selectCodePage` must emit the ESC/POS sequence ESC t <n>. Default
     * page (no arg) is 19 (CP858 — most common European thermal default).
     */
    public function test_select_code_page_emits_esc_t_sequence(): void
    {
        $this->assertSame("\x1Bt\x13", EscPosCommandBuilder::selectCodePage());
        $this->assertSame("\x1Bt\x00", EscPosCommandBuilder::selectCodePage(0));
        $this->assertSame("\x1Bt\x10", EscPosCommandBuilder::selectCodePage(16));
    }

    /**
     * [V14 C-β / SENTINEL FINDING C-β-T15-1 P2]
     * `encodeForPrinter` must transcode UTF-8 multibyte chars to a
     * single-byte encoding so that printer column counters (which work on
     * bytes) stay coherent and accents do not print as "?". Empty input
     * stays empty; ASCII passes through unchanged.
     */
    public function test_encode_for_printer_returns_single_byte_string(): void
    {
        $this->assertSame('', EscPosCommandBuilder::encodeForPrinter(''));
        $this->assertSame('Hello', EscPosCommandBuilder::encodeForPrinter('Hello'));

        $accented = 'Café';
        $encoded = EscPosCommandBuilder::encodeForPrinter($accented);

        $this->assertNotSame($accented, $encoded);
        $this->assertSame(4, strlen($encoded), 'CP858 transcode must be 1 byte per glyph (4 chars).');
        $this->assertStringStartsWith('Caf', $encoded);
    }

    /**
     * [V14 C-β / SENTINEL FINDING C-β-T15-1 P2]
     * `testPrint` must inject the codepage selection sequence (ESC t)
     * into the payload before any text, otherwise accents will print as
     * mojibake on most ESC/POS hardware.
     */
    public function test_test_print_injects_codepage_selection_in_payload(): void
    {
        $transport = new NullPrinterTransport();
        $service = new EscPosPrinterService($transport);
        $printer = $this->makePrinter();

        $service->testPrint($printer);

        $this->assertStringContainsString("\x1Bt", $transport->sent[0]['bytes']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    private function makePrinter(array $overrides = []): Printer
    {
        $branch = Branch::factory()->create();

        return Printer::query()->create($this->printerData($branch->id, $overrides));
    }

    private function printerData(int $branchId, array $overrides = []): array
    {
        return array_merge([
            'branch_id' => $branchId,
            'name' => 'Receipt Printer',
            'type' => 'escpos_tcp',
            'host' => '127.0.0.1',
            'port' => 9100,
            'station' => 'receipt',
            'width_chars' => 48,
            'status' => Status::ACTIVE,
            'options' => ['cut' => true],
        ], $overrides);
    }
}

class FailingPrinterTransport implements PrinterTransportInterface
{
    public function send(string $bytes, array $config): bool
    {
        return false;
    }

    public function lastError(): ?string
    {
        return 'forced_failure';
    }
}
