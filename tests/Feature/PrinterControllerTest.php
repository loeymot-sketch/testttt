<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Enums\Status;
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

        // [GOAL-L2-HEAL-03 2026-05-24] SafeRemoteHost now blocks RFC1918 +
        // loopback on PrinterRequest::host. Pre-existing happy-path cases
        // in this suite use 192.168.x and 127.0.0.1 (representative of a
        // real LAN-hosted ESC/POS printer), so we allowlist those ranges
        // for THIS test class only. Production .env stays closed by default.
        // The dedicated security sentinel (PrinterHostAllowlistSentinelTest)
        // verifies the blocklist with a *cleared* allowlist.
        // [FIX 2026-08-25] Format host+port obligatoire depuis le durcissement de
        // `App\Rules\SafeRemoteHost` : une entrée en CIDR nu est désormais REFUSÉE, parce
        // qu'elle ouvrirait les 65535 ports d'une plage privée entière — pour une imprimante
        // ESC/POS on n'a besoin que de 9100-9103. Les tests portaient encore l'ancien format et
        // recevaient donc un 422 dont le message disait exactement quoi faire ; personne n'y
        // avait donné suite. On aligne sur le format attendu, sans élargir la portée.
        config(['security.safe_remote_host_allowlist' => [
            '127.0.0.0/8:9100-9103',
            '192.168.0.0/16:9100-9103',
        ]]);

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
            'status' => Status::ACTIVE,
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

    /**
     * [GOAL_ADMIN_NAV_BREADTH_CONVERGENCE_2026-08-13] Adversarial-audit
     * finding: PrintersComponent.vue's "ESC/POS réseau" dropdown option (and
     * its EMPTY_FORM default, and its edit-form fallback) send
     * type=escpos_network, but PrinterRequest::rules() only accepts
     * escpos_tcp/escpos_usb/browser_html. The frontend never binds
     * errors.type in the template, so every save with the default option
     * silently 422s with no visible field error — an admin configuring a
     * network printer (the most common real setup) cannot save at all.
     */
    public function test_create_rejects_the_stale_frontend_network_type_value(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')->postJson(
            '/api/admin/printers',
            $this->printerData($this->branch->id, ['type' => 'escpos_network'])
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }

    public function test_create_accepts_the_corrected_network_type_value(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')->postJson(
            '/api/admin/printers',
            $this->printerData($this->branch->id, ['type' => 'escpos_tcp'])
        );

        $response->assertCreated();
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
