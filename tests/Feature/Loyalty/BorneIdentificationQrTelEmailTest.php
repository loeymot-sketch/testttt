<?php

namespace Tests\Feature\Loyalty;

use App\Enums\Ask;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\KioskMachine;
use App\Models\User;
use App\Services\Loyalty\LoyaltyQrSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * [DÉCISION PROPRIÉTAIRE 2026-11-06 · borne « QR, téléphone, e-mail, plein de choix »] La borne identifie un client
 * par le QR SIGNÉ de son application (à usage unique), par son E-MAIL, ou par son code / téléphone. Le compte d'une
 * borne ou de l'équipe ne s'ouvre jamais comme un compte fidélité. La remise annoncée porte sur les points DÉPENSABLES.
 */
class BorneIdentificationQrTelEmailTest extends TestCase
{
    use RefreshDatabase;

    private User $borne;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        config(['app.api_key' => '123456', 'loyalty.qr.secret' => str_repeat('ab', 32)]);
        Settings::group('loyalty_setup')->set(['loyalty_points_per_euro' => 10, 'loyalty_points_for_1_euro_discount' => 100, 'loyalty_min_redeem_points' => 100]);
        $branch = Branch::factory()->create();
        $this->borne = User::factory()->create(['branch_id' => $branch->id]);
        $this->borne->assignRole('Admin'); // en production la borne appartient au compte admin
        KioskMachine::withoutGlobalScopes()->forceCreate(['user_id' => $this->borne->id, 'branch_id' => $branch->id,
            'machine_id' => 'm-ident-01', 'username' => 'borne-ident', 'password' => bcrypt('x'), 'is_login' => Ask::NO, 'status' => Status::ACTIVE]);
    }

    private function client(array $attrs = []): User
    {
        $u = User::factory()->create(array_merge(['branch_id' => 0, 'status' => Status::ACTIVE, 'loyalty_points' => 1200,
            'loyalty_code' => 'IDT' . strtoupper(substr(uniqid(), -5)), 'email' => 'client.' . uniqid() . '@example.test', 'phone' => '06' . random_int(10000000, 99999999)], $attrs));
        $u->assignRole('Customer');

        return $u;
    }

    private function verifier(string $saisie)
    {
        Sanctum::actingAs($this->borne, ['kiosk:order']);

        return $this->withHeader('x-api-key', '123456')->postJson('/api/frontend/loyalty/check', ['code' => $saisie]);
    }

    public function test_par_qr_signe_de_l_application(): void
    {
        $c = $this->client();
        $jeton = app(LoyaltyQrSigner::class)->sign($c->id, $c->loyalty_code)['token'];

        $r = $this->verifier($jeton)->assertOk();
        $this->assertSame($c->loyalty_code, $r->json('data.loyalty_code'));

        $this->verifier($jeton)->assertStatus(422); // usage unique : un QR photographié ne resservira pas
    }

    public function test_par_e_mail_sans_tenir_compte_des_majuscules(): void
    {
        $c = $this->client(['email' => 'Marie.Dupont@Example.test']);

        $r = $this->verifier('marie.dupont@example.TEST')->assertOk();
        $this->assertSame($c->loyalty_code, $r->json('data.loyalty_code'));
    }

    public function test_par_telephone_et_par_code_comme_avant(): void
    {
        $c = $this->client(['phone' => '0612345678']);

        $this->assertSame($c->loyalty_code, $this->verifier('0612345678')->assertOk()->json('data.loyalty_code'));
        $this->assertSame($c->loyalty_code, $this->verifier($c->loyalty_code)->assertOk()->json('data.loyalty_code'));
    }

    public function test_e_mail_inconnu_ou_invalide(): void
    {
        $this->verifier('personne@example.test')->assertStatus(404);
        $this->verifier('pas un@@mail')->assertStatus(422);
    }

    public function test_le_compte_de_l_equipe_ne_s_ouvre_pas_a_la_borne(): void
    {
        $admin = User::factory()->create(['branch_id' => 0, 'status' => Status::ACTIVE, 'loyalty_points' => 5000,
            'loyalty_code' => 'ADMINXX', 'email' => 'patron@example.test', 'phone' => '0700000000']);
        $admin->assignRole('Admin');

        $this->verifier('patron@example.test')->assertStatus(404);
        $this->verifier('0700000000')->assertStatus(404);
    }

    public function test_la_remise_annoncee_porte_sur_les_points_depensables(): void
    {
        $c = $this->client(['loyalty_points' => 1500]);
        $o = \App\Models\Order::factory()->create(['user_id' => $c->id, 'branch_id' => Branch::query()->first()->id,
            // PRÉPAYÉE en ligne : seule une commande prépayée bloque ses points jusqu'à la remise (payée sur place = remise).
            'source_surface' => 'web', 'status' => \App\Enums\OrderStatus::ACCEPT, 'payment_status' => \App\Enums\PaymentStatus::PAID,
            'payment_method' => \App\Enums\PaymentGateway::CARD, 'total' => 50]);
        DB::table('loyalty_transactions')->insert(['user_id' => $c->id, 'loyalty_code' => $c->loyalty_code, 'order_id' => $o->id,
            'type' => 'earn', 'points' => 500, 'balance_after' => 1500, 'source_surface' => 'web', 'created_at' => now(), 'updated_at' => now()]);

        $d = $this->verifier($c->loyalty_code)->assertOk()->json('data');

        $this->assertSame(1500, (int) $d['points']);
        $this->assertSame(1000, (int) $d['points_depensables']);
        $this->assertSame(500, (int) $d['points_pending']);
        $this->assertEquals(10.0, (float) $d['discount_value'], 'remise sur les 1000 points dépensables, pas sur 1500');
    }
}
