<?php

namespace Tests\Feature\Pos;

use App\Models\Branch;
use App\Models\Printer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [KITCHEN-SYMBOLS / PRINT-ACTIVATION 2026-06-28] One-shot setup of the SAGA
 * receipt printer so the POS prints ESC/POS directly instead of falling back to
 * the browser window.print() (which mangles accents/€ and adds a URL footer).
 */
class SetupReceiptPrinterCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_active_receipt_printer_row(): void
    {
        Branch::factory()->create(["id" => 1]);
        $this->artisan('pos:setup-receipt-printer', ['name' => 'SAGA-80mm', '--branch' => 1])
            ->assertExitCode(0);

        $p = Printer::withoutGlobalScopes()->where('branch_id', 1)->where('station', 'receipt')->first();
        $this->assertNotNull($p);
        $this->assertSame('SAGA-80mm', $p->host);
        $this->assertSame(\App\Enums\Status::ACTIVE, (int) $p->status);
        $this->assertSame(48, (int) $p->width_chars);
    }

    public function test_is_idempotent_updates_existing_row(): void
    {
        Branch::factory()->create(["id" => 1]);
        $this->artisan('pos:setup-receipt-printer', ['name' => 'OLD', '--branch' => 1])->assertExitCode(0);
        $this->artisan('pos:setup-receipt-printer', ['name' => 'NEW', '--branch' => 1])->assertExitCode(0);

        $rows = Printer::withoutGlobalScopes()->where('branch_id', 1)->where('station', 'receipt')->get();
        $this->assertCount(1, $rows);
        $this->assertSame('NEW', $rows->first()->host);
    }
}
