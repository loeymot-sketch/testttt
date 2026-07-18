<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\KioskMachine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * [P2-u 2026-07-18] /loyalty/scan — parité anti-énumération PII avec /check + /redeem.
 *
 * `scan` n'était gardé que par `tokenCan('kiosk:order')`. Or GuestSignupController
 * émet des tokens INVITÉS porteurs de l'ability `kiosk:order` : un invité pouvait
 * donc scanner N'IMPORTE quel code fidélité et récolter le profil (prénom, solde de
 * points, allergènes) = énumération PII. Ses jumeaux `check`(:88) et `redeem`(:324)
 * exigent une VRAIE KioskMachine (ligne présente pour l'utilisateur) OU staff OU le
 * propriétaire ; `scan` avait été oublié. On aligne : sinon → réponse NEUTRE
 * (indiscernable de « non trouvé », HTTP 200 pour ne pas casser le parcours §12).
 */
class LoyaltyScanRequiresKioskMachineTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        config(['loyalty.qr.accept_legacy_plaintext' => true]);

        $this->branch = Branch::factory()->create(['status' => Status::ACTIVE]);
        // status ACTIVE(5) : le propriétaire doit pouvoir s'AUTHENTIFIER (EnsureUserStatusActive
        // exige ACTIVE) pour tester le chemin owner. isCustomerActive() accepte 1 OU 5.
        $this->customer = User::factory()->create([
            'branch_id'      => $this->branch->id,
            'name'           => 'Alice Martin',
            'loyalty_code'   => 'SCANME01',
            'loyalty_points' => 250,
            'status'         => Status::ACTIVE,
        ]);
    }

    private function realKioskUser(): User
    {
        $kioskUser = User::factory()->create(['branch_id' => $this->branch->id, 'status' => Status::ACTIVE]);
        KioskMachine::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id'   => $kioskUser->id,
            'status'    => Status::ACTIVE,
            'is_login'  => Ask::NO,
        ]);
        return $kioskUser;
    }

    private function scan(string $raw): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/frontend/loyalty/scan', [
            'method'   => 'qr',
            'raw_data' => $raw,
        ]);
    }

    /** Vraie borne (KioskMachine réelle) → profil résolu (parcours légitime préservé). */
    public function test_real_kiosk_machine_resolves_profile(): void
    {
        Sanctum::actingAs($this->realKioskUser(), ['kiosk:order']);

        $resp = $this->scan('FK:SCANME01');

        $resp->assertStatus(200);
        $resp->assertJsonPath('data.ok', true);
        $resp->assertJsonPath('data.display_name', 'Alice');
        $resp->assertJsonPath('data.loyalty_balance_points', 250);
    }

    /** Token invité (kiosk:order mais AUCUNE KioskMachine) → réponse neutre, zéro PII. */
    public function test_guest_token_cannot_enumerate_pii(): void
    {
        $guest = User::factory()->create(['branch_id' => 0, 'status' => Status::ACTIVE]);
        Sanctum::actingAs($guest, ['kiosk:order']); // ability portée, mais PAS une vraie borne

        $resp = $this->scan('FK:SCANME01');

        $resp->assertStatus(200);
        $resp->assertJsonPath('data.ok', false);
        $resp->assertJsonPath('data.display_name', null);
        $resp->assertJsonPath('data.loyalty_balance_points', 0);
    }

    /** Le propriétaire du code peut consulter SON propre profil. */
    public function test_owner_resolves_own_profile(): void
    {
        Sanctum::actingAs($this->customer, ['kiosk:order']);

        $resp = $this->scan('FK:SCANME01');

        $resp->assertStatus(200);
        $resp->assertJsonPath('data.ok', true);
        $resp->assertJsonPath('data.display_name', 'Alice');
    }
}
