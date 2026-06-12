<?php

namespace Tests\Feature\Loyalty;

use App\Models\Branch;
use App\Models\KioskMachine;
use App\Models\LoyaltyTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithoutMiddleware;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * [W-REM T-R3.3 F1-02 2026-06-12] Welcome bonus au LAZY-MINT.
 *
 * Décision produit documentée : les clients promettent « +25 pts à
 * l'inscription ». Le bonus était crédité UNIQUEMENT sur le chemin
 * register()/opt-in. Or un client créé à la caisse (add-customer, par
 * téléphone, sans loyalty_code) rejoint le programme via le LAZY-MINT
 * (check()/balance() borne, generateQr web) — il ne touchait JAMAIS les
 * +25 promis. Iniquité pure entre canaux d'adhésion.
 *
 * Contrat : le bonus de bienvenue est crédité au moment où le compte
 * fidélité est effectivement créé (mint du loyalty_code), QUEL QUE SOIT le
 * canal — une seule fois par client (idempotent via le ledger).
 */
class LoyaltyWelcomeLazyMintTest extends TestCase
{
    use RefreshDatabase, WithoutMiddleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        config(['app.api_key' => '123456']);
        // generateQr exige un secret HMAC non vide (LOYALTY_QR_SECRET absent
        // de l'env de test) — prérequis d'infra, pas le comportement testé.
        config(['loyalty.qr.secret' => 'phpunit-qr-secret']);
        $this->withHeaders(['x-api-key' => '123456', 'Accept' => 'application/json']);
    }

    private function actAsKioskMachine(): void
    {
        $branch = Branch::factory()->create();
        $kioskUser = User::factory()->create(['branch_id' => $branch->id]);
        KioskMachine::forceCreate([
            'user_id'    => $kioskUser->id,
            'branch_id'  => $branch->id,
            'machine_id' => 'K-LAZY-1',
            'username'   => 'kiosk_lazy',
            'password'   => bcrypt('kioskpass'),
            'is_login'   => 1,
            'status'     => 1,
        ]);
        Sanctum::actingAs($kioskUser, ['kiosk:order']);
    }

    private function makePhoneOnlyCustomer(string $phone = '0655667788'): User
    {
        // Client créé à la caisse : téléphone, status actif 5, AUCUN code.
        return User::forceCreate([
            'name'           => 'Caisse Client',
            'username'       => 'caisse_' . uniqid(),
            'email'          => null,
            'phone'          => $phone,
            'password'       => bcrypt('secret'),
            'status'         => 5,
            'loyalty_points' => 0,
        ]);
    }

    public function test_lazy_mint_via_kiosk_check_awards_welcome_bonus_once(): void
    {
        $customer = $this->makePhoneOnlyCustomer();
        $this->actAsKioskMachine();

        $first = $this->postJson('/api/frontend/loyalty/check', ['code' => '0655667788']);
        $first->assertStatus(200);
        $this->assertSame(25, (int) $first->json('data.points'), 'le +25 promis doit être crédité au lazy-mint');

        $customer->refresh();
        $this->assertNotEmpty($customer->loyalty_code);
        $this->assertSame(25, (int) $customer->loyalty_points);
        $this->assertSame(
            1,
            LoyaltyTransaction::where('user_id', $customer->id)
                ->where('description', 'Bonus de bienvenue')->count()
        );

        // Second check : pas de double crédit.
        $second = $this->postJson('/api/frontend/loyalty/check', ['code' => '0655667788']);
        $second->assertStatus(200);
        $this->assertSame(25, (int) $second->json('data.points'));
        $this->assertSame(
            1,
            LoyaltyTransaction::where('user_id', $customer->id)
                ->where('description', 'Bonus de bienvenue')->count(),
            'le bonus est UNE fois par client (idempotent)'
        );
    }

    public function test_register_then_check_never_double_awards(): void
    {
        $this->postJson('/api/frontend/loyalty/register', [
            'name'                   => 'Inscrit Borne',
            'phone'                  => '0699880011',
            'consent_accepted'       => true,
            'privacy_notice_version' => '2026-04-18',
        ])->assertStatus(200);

        $user = User::where('phone', '0699880011')->firstOrFail();
        $this->assertSame(25, (int) $user->loyalty_points);

        $this->actAsKioskMachine();
        $this->postJson('/api/frontend/loyalty/check', ['code' => '0699880011'])->assertStatus(200);

        $this->assertSame(
            1,
            LoyaltyTransaction::where('user_id', $user->id)
                ->where('description', 'Bonus de bienvenue')->count()
        );
    }

    public function test_lazy_mint_via_generate_qr_awards_welcome_bonus(): void
    {
        $customer = $this->makePhoneOnlyCustomer('0612340000');
        Sanctum::actingAs($customer, []);

        $this->postJson('/api/frontend/loyalty/qr')->assertStatus(200);

        $customer->refresh();
        $this->assertNotEmpty($customer->loyalty_code);
        $this->assertSame(25, (int) $customer->loyalty_points, 'mint via QR = même promesse +25');
        $this->assertSame(
            1,
            LoyaltyTransaction::where('user_id', $customer->id)
                ->where('description', 'Bonus de bienvenue')->count()
        );
    }
}
