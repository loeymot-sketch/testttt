<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Printer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrinterControllerTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branch = Branch::factory()->create();
        $this->user = User::factory()->create([
            'branch_id' => $this->branch->id,
        ]);
        $this->user->assignRole('Admin');
    }

    public function test_admin_can_create_a_printer(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/admin/printers', [
            'name' => 'Bar Printer',
            'type' => 'escpos_tcp',
            'host' => '192.168.1.20',
            'port' => 9100,
            'station' => 'bar',
            'width_chars' => 48,
            'status' => 1,
            'options' => ['cut' => true],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Bar Printer')
            ->assertJsonPath('data.branch_id', $this->branch->id);

        $this->assertDatabaseHas('printers', [
            'branch_id' => $this->branch->id,
            'name' => 'Bar Printer',
            'station' => 'bar',
        ]);
    }

    public function test_index_returns_only_printers_for_the_current_branch(): void
    {
        $otherBranch = Branch::factory()->create();

        Printer::query()->create($this->printerData($this->branch->id, ['name' => 'Visible printer']));
        Printer::query()->create($this->printerData($otherBranch->id, ['name' => 'Hidden printer']));

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/admin/printers');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Visible printer');
    }

    public function test_test_print_returns_ok_with_null_transport_in_testing(): void
    {
        $printer = Printer::query()->create($this->printerData($this->branch->id));

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/admin/printers/{$printer->id}/test-print")
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'printer_id' => $printer->id,
            ]);
    }

    public function test_update_changes_the_station(): void
    {
        $printer = Printer::query()->create($this->printerData($this->branch->id, ['station' => 'receipt']));

        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/admin/printers/{$printer->id}", [
                'name' => $printer->name,
                'type' => $printer->type,
                'host' => $printer->host,
                'port' => $printer->port,
                'station' => 'kitchen_hot',
                'width_chars' => $printer->width_chars,
                'status' => $printer->status,
                'options' => $printer->options,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.station', 'kitchen_hot');

        $this->assertDatabaseHas('printers', [
            'id' => $printer->id,
            'station' => 'kitchen_hot',
        ]);
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
            'status' => 1,
            'options' => ['cut' => true],
        ], $overrides);
    }
}
