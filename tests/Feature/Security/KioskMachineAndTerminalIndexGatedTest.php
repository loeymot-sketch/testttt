<?php

namespace Tests\Feature\Security;

use App\Models\Branch;
use App\Models\KioskMachine;
use App\Models\PaymentTerminal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [AUDIT-E E2/E3 2026-08-06] Les index « lookup » qui exposent des SECRETS
 * opérationnels exigent une permission, comme leurs écritures.
 *
 *  E2 [P1] `setting/kiosk-machine` index → `username` + `machine_id` de login
 *          borne (moitié d'un couple d'identifiants dont le défaut n'est bloqué
 *          qu'en `production`) était lisible par un compte SANS permission.
 *  E3 [P2] `payment-terminals` index → `serial_number` + grille de commissions,
 *          même trou. Gaté `settings|pos` : le caissier en a besoin pour la
 *          modale d'encaissement (choix du TPE), pas un compte sans rôle.
 *
 * Les deux étaient mal classés « non-PII » dans l'allowlist gelée
 * AdminRoutePermissionFloorTest — retirés par le même commit.
 */
class KioskMachineAndTerminalIndexGatedTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        $this->branch = Branch::factory()->create();

        PaymentTerminal::create([
            'branch_id' => $this->branch->id, 'name' => 'TPE Secret',
            'gateway_type' => PaymentTerminal::GATEWAY_MANUAL,
            'serial_number' => 'SN-CONFIDENTIEL-1',
            'fee_percent' => 1.75, 'fee_fixed' => 0.10,
            'status' => PaymentTerminal::STATUS_ACTIVE,
        ]);
        $kioskUser = User::factory()->create(['branch_id' => $this->branch->id]);
        KioskMachine::withoutGlobalScopes()->forceCreate([
            'user_id' => $kioskUser->id,
            'branch_id' => $this->branch->id,
            'machine_id' => 'KM-AUDIT-1',
            'username' => 'kiosk-secret-login',
            'password' => bcrypt('kiosk123'),
            'status' => 1,
        ]);
    }

    private function zeroPermissionStaff(): User
    {
        // Compte staff authentifié SANS aucun rôle/permission métier.
        return User::factory()->create(['branch_id' => $this->branch->id]);
    }

    private function apiGet(User $as, string $url)
    {
        return $this->actingAs($as, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->getJson($url);
    }

    public function test_zero_permission_staff_cannot_read_kiosk_machine_credentials(): void
    {
        $res = $this->apiGet($this->zeroPermissionStaff(), '/api/admin/setting/kiosk-machine');

        $this->assertSame(403, $res->status(),
            'le username de login borne ne doit PAS être lisible sans permission settings');
        $this->assertStringNotContainsString('kiosk-secret-login', $res->getContent());
    }

    public function test_zero_permission_staff_cannot_read_terminal_serials(): void
    {
        $res = $this->apiGet($this->zeroPermissionStaff(), '/api/admin/payment-terminals');

        $this->assertSame(403, $res->status(),
            'le serial_number TPE + les commissions ne doivent PAS fuiter sans permission');
        $this->assertStringNotContainsString('SN-CONFIDENTIEL-1', $res->getContent());
    }

    public function test_cashier_still_reads_terminals_for_the_collect_modal(): void
    {
        $cashier = User::factory()->create(['branch_id' => $this->branch->id]);
        $cashier->assignRole('POS Operator');

        $this->apiGet($cashier, '/api/admin/payment-terminals')->assertOk();
    }

    public function test_admin_still_reads_kiosk_machines(): void
    {
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');

        $this->apiGet($admin, '/api/admin/setting/kiosk-machine')->assertOk();
    }
}
