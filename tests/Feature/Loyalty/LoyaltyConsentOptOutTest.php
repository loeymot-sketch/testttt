<?php

namespace Tests\Feature\Loyalty;

use App\Models\LoyaltyConsent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithoutMiddleware;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * [W-REM T-R3.3 Q-4 RGPD 2026-06-12]
 *
 * Constat audit : la voie DIRECTE `POST /frontend/loyalty/register` (publique,
 * appelée par la borne quand le consentement est déjà coché côté store)
 * persistait des PII (nom/téléphone/email) SANS AUCUNE ligne
 * `loyalty_consents` — seul `/loyalty/opt-in` journalisait. Et AUCUNE route
 * d'opt-out (retrait du consentement, droit de retrait CNIL) n'existait.
 *
 * Contrat verrouillé ici :
 *  1. register() création de NOUVEAU compte → consent_accepted obligatoire
 *     (422 sinon, aucune PII persistée) + ligne loyalty_consents (IP/UA hashés).
 *  2. register() sur compte EXISTANT (update email) → pas de re-consentement
 *     exigé (le compte existe déjà).
 *  3. /loyalty/opt-in ne double-journalise PAS (1 seule ligne consent).
 *  4. POST /frontend/loyalty/opt-out (auth) : journalise consent_accepted=false,
 *     retire le client du programme (code null, points 0, ledger 'opt_out').
 *  5. opt-out d'un tiers interdit aux non-staff ; staff peut assister un client
 *     au comptoir (param code/phone).
 */
class LoyaltyConsentOptOutTest extends TestCase
{
    use RefreshDatabase, WithoutMiddleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        config(['app.api_key' => '123456']);
        $this->withHeaders(['x-api-key' => '123456', 'Accept' => 'application/json']);
    }

    private function consentPayload(array $overrides = []): array
    {
        return array_merge([
            'name'                   => 'Client RGPD',
            'phone'                  => '+33611112222',
            'consent_accepted'       => true,
            'privacy_notice_version' => '2026-04-18',
        ], $overrides);
    }

    public function test_register_new_account_without_consent_is_rejected_and_persists_nothing(): void
    {
        $response = $this->postJson('/api/frontend/loyalty/register', [
            'name'  => 'Sans Consentement',
            'phone' => '+33644445555',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('users', ['phone' => '+33644445555']);
        $this->assertSame(0, LoyaltyConsent::count(), 'aucun consent ne doit être journalisé');
    }

    public function test_register_new_account_with_consent_creates_account_and_hashed_consent_row(): void
    {
        $response = $this->postJson('/api/frontend/loyalty/register', $this->consentPayload());

        $response->assertStatus(200)->assertJsonPath('status', true);

        $user = User::where('phone', '+33611112222')->firstOrFail();
        $this->assertNotEmpty($user->loyalty_code);

        $consent = LoyaltyConsent::where('user_id', $user->id)->first();
        $this->assertNotNull($consent, 'le register direct doit journaliser le consentement');
        $this->assertTrue((bool) $consent->consent_accepted);
        $this->assertSame('2026-04-18', $consent->privacy_notice_version);
        // RGPD/CNIL : jamais d'IP ou UA bruts.
        $this->assertNotSame('127.0.0.1', $consent->ip_hash);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $consent->ip_hash);
    }

    public function test_register_existing_account_update_does_not_require_consent(): void
    {
        $this->postJson('/api/frontend/loyalty/register', $this->consentPayload())->assertStatus(200);

        // Même téléphone, ajout d'email, SANS consent → toujours 200 (pas une création).
        $update = $this->postJson('/api/frontend/loyalty/register', [
            'phone' => '+33611112222',
            'email' => 'rgpd@example.fr',
        ]);

        $update->assertStatus(200);
        $this->assertSame('rgpd@example.fr', User::where('phone', '+33611112222')->value('email'));
    }

    public function test_opt_in_path_logs_exactly_one_consent_row(): void
    {
        $response = $this->postJson('/api/frontend/loyalty/opt-in', $this->consentPayload([
            'phone' => '+33622223333',
        ]));

        $response->assertStatus(200);
        $user = User::where('phone', '+33622223333')->firstOrFail();
        $this->assertSame(
            1,
            LoyaltyConsent::where('user_id', $user->id)->count(),
            'opt-in ne doit PAS double-journaliser (register délégué + optIn)'
        );
    }

    public function test_opt_out_self_revokes_consent_and_removes_from_program(): void
    {
        $this->postJson('/api/frontend/loyalty/register', $this->consentPayload())->assertStatus(200);
        $user = User::where('phone', '+33611112222')->firstOrFail();
        $user->loyalty_points = 120;
        $user->save();

        Sanctum::actingAs($user, []);
        $response = $this->postJson('/api/frontend/loyalty/opt-out');

        $response->assertStatus(200)->assertJsonPath('status', true);

        $user->refresh();
        $this->assertNull($user->loyalty_code, 'opt-out = retrait du programme');
        $this->assertSame(0, (int) $user->loyalty_points);

        $revocation = LoyaltyConsent::where('user_id', $user->id)
            ->where('consent_accepted', false)->first();
        $this->assertNotNull($revocation, 'le retrait doit être journalisé (droit de retrait CNIL)');

        // La colonne type est un ENUM figé (migration appliquée) : le ledger
        // trace le retrait en manual_deduct + description opt-out explicite ;
        // le journal RGPD autoritatif est loyalty_consents (assert ci-dessus).
        $this->assertDatabaseHas('loyalty_transactions', [
            'user_id'     => $user->id,
            'type'        => 'manual_deduct',
            'description' => 'Retrait du programme fidélité (opt-out RGPD)',
        ]);
    }

    public function test_opt_out_requires_authentication(): void
    {
        // WithoutMiddleware désactive auth:sanctum — on vérifie le guard interne.
        $response = $this->postJson('/api/frontend/loyalty/opt-out');
        $response->assertStatus(401);
    }

    public function test_non_staff_cannot_opt_out_a_third_party(): void
    {
        $this->postJson('/api/frontend/loyalty/register', $this->consentPayload())->assertStatus(200);
        $victim = User::where('phone', '+33611112222')->firstOrFail();

        $guest = User::factory()->create();
        Sanctum::actingAs($guest, ['kiosk:order']);

        $this->postJson('/api/frontend/loyalty/opt-out', ['code' => $victim->loyalty_code])
            ->assertStatus(403);

        $victim->refresh();
        $this->assertNotNull($victim->loyalty_code, 'le compte du tiers doit rester intact');
    }

    public function test_staff_can_assist_counter_opt_out_by_code(): void
    {
        $this->postJson('/api/frontend/loyalty/register', $this->consentPayload())->assertStatus(200);
        $customer = User::where('phone', '+33611112222')->firstOrFail();

        $staff = User::factory()->create();
        $staff->assignRole('Admin');
        Sanctum::actingAs($staff, []);

        $this->postJson('/api/frontend/loyalty/opt-out', ['code' => $customer->loyalty_code])
            ->assertStatus(200);

        $customer->refresh();
        $this->assertNull($customer->loyalty_code);
        $this->assertNotNull(
            LoyaltyConsent::where('user_id', $customer->id)->where('consent_accepted', false)->first()
        );
    }
}
