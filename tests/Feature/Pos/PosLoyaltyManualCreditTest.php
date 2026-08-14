<?php

namespace Tests\Feature\Pos;

use App\Enums\Ask;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * CRÉDITER MANUELLEMENT DES POINTS — le geste du propriétaire du 2026-08-14 :
 * « quand je lui ajoute un montant équivalent fidélité, par exemple sept euros, je veux
 *   directement les rajouter dans son compte. »
 *
 * Distinct de `PosLoyaltyAttachTest` (crédit AUTOMATIQUE proportionnel à une vente) : ici le
 * caissier choisit lui-même la somme.
 */
class PosLoyaltyManualCreditTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branche;

    private User $caissier;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        Settings::group('loyalty_setup')->set([
            'loyalty_points_per_euro'            => 10,
            'loyalty_points_for_1_euro_discount' => 100,
            'loyalty_min_redeem_points'          => 50,
        ]);

        $this->branche = Branch::factory()->create();
        $this->caissier = User::factory()->create(['branch_id' => $this->branche->id, 'phone' => '0100000009']);
        $this->caissier->assignRole('POS Operator');

        $this->client = User::factory()->create(['phone' => '0699887766', 'is_guest' => Ask::YES]);
        DB::table('users')->where('id', $this->client->id)
            ->update(['loyalty_code' => 'CREDIT001', 'loyalty_points' => 0]);
        $this->client->refresh();
    }

    private function crediter(array $corps, ?User $agent = null)
    {
        return $this->actingAs($agent ?? $this->caissier, 'sanctum')
            ->withHeader('X-Idempotency-Key', 'cred-'.bin2hex(random_bytes(8)))
            ->postJson('/api/admin/pos-loyalty/credit-manual', $corps);
    }

    /** LE CAS RÉEL : 7€ crédités au barème 100 pts/€ → 700 points. */
    public function test_credit_7_euros_convertit_au_bareme(): void
    {
        $reponse = $this->crediter(['loyalty_code' => 'CREDIT001', 'euros' => 7]);

        $reponse->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.points_added', 700)
            ->assertJsonPath('data.balance_after', 700);

        $this->assertSame(700, (int) DB::table('users')->where('id', $this->client->id)->value('loyalty_points'));

        $ligne = DB::table('loyalty_transactions')->where('loyalty_code', 'CREDIT001')->first();
        $this->assertNotNull($ligne);
        $this->assertSame('manual_add', $ligne->type);
        $this->assertSame(700, (int) $ligne->points);
        $this->assertNull($ligne->order_id);
    }

    public function test_credit_trace_la_commande_quand_fournie(): void
    {
        $reponse = $this->crediter(['loyalty_code' => 'CREDIT001', 'euros' => 2, 'order_id' => 999]);

        $reponse->assertOk();
        $ligne = DB::table('loyalty_transactions')->where('loyalty_code', 'CREDIT001')->first();
        $this->assertSame(999, (int) $ligne->order_id);
    }

    public function test_code_inconnu_refuse_404(): void
    {
        $this->crediter(['loyalty_code' => 'INTROUVABLE', 'euros' => 5])
            ->assertStatus(404)
            ->assertJsonPath('code', 'CUSTOMER_NOT_FOUND');
    }

    public function test_montant_negatif_refuse_422(): void
    {
        $this->crediter(['loyalty_code' => 'CREDIT001', 'euros' => -1])
            ->assertStatus(422);
    }

    public function test_montant_au_dela_du_plafond_refuse_422(): void
    {
        $this->crediter(['loyalty_code' => 'CREDIT001', 'euros' => 500])
            ->assertStatus(422);
    }

    /** Un caissier de l'équipe ne se crédite pas lui-même via ce chemin. */
    public function test_compte_equipe_refuse(): void
    {
        DB::table('users')->where('id', $this->caissier->id)->update(['loyalty_code' => 'STAFF001']);

        $this->crediter(['loyalty_code' => 'STAFF001', 'euros' => 5])
            ->assertStatus(422)
            ->assertJsonPath('code', 'STAFF_ACCOUNT');
    }

    public function test_sans_permission_pos_refuse_403(): void
    {
        $intrus = User::factory()->create(['branch_id' => $this->branche->id]);
        // Aucun rôle assigné → aucune permission `pos`.

        $this->crediter(['loyalty_code' => 'CREDIT001', 'euros' => 5], $intrus)
            ->assertStatus(403);
    }
}
