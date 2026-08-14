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

    /**
     * LE CAS RÉEL : 7€ crédités au barème de GAIN 10 pts/€ → 70 points.
     *
     * [CORRIGÉ 2026-08-14, ERREUR RÉELLE EN PRODUCTION] La version d'origine de ce test
     * attendait 700 (barème de REMISE, 100 pts/€) — c'était le bug lui-même, pas juste le test :
     * `PosManualCreditService::credit()` lisait `LoyaltyRules::rate()` (remise) au lieu de
     * `pointsPerEuro()` (gain). Mesuré en production le jour même : 17,30€ → 1730 pts au lieu de
     * 173, facteur 10 exact, repéré par le propriétaire au comptoir.
     */
    public function test_credit_7_euros_convertit_au_bareme_de_gain(): void
    {
        $reponse = $this->crediter(['loyalty_code' => 'CREDIT001', 'euros' => 7]);

        $reponse->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.points_added', 70)
            ->assertJsonPath('data.balance_after', 70);

        $this->assertSame(70, (int) DB::table('users')->where('id', $this->client->id)->value('loyalty_points'));

        $ligne = DB::table('loyalty_transactions')->where('loyalty_code', 'CREDIT001')->first();
        $this->assertNotNull($ligne);
        $this->assertSame('manual_add', $ligne->type);
        $this->assertSame(70, (int) $ligne->points);
        $this->assertNull($ligne->order_id);
    }

    /** Les deux taux (gain vs remise) doivent rester distincts, sinon la divergence redevient invisible. */
    public function test_credit_utilise_bien_le_taux_de_gain_pas_le_taux_de_remise(): void
    {
        Settings::group('loyalty_setup')->set([
            'loyalty_points_per_euro'            => 10,
            'loyalty_points_for_1_euro_discount' => 100,
            'loyalty_min_redeem_points'          => 50,
        ]);

        $this->crediter(['loyalty_code' => 'CREDIT001', 'euros' => 1])
            ->assertOk()
            ->assertJsonPath('data.points_added', 10);
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

    /**
     * [BUG RÉEL 2026-08-14] Un motif au plafond validé par la FormRequest (255) + le préfixe
     * (« Crédit manuel de X€ par caissier #Y — ») dépasse la colonne VARCHAR(255) et faisait
     * échouer l'INSERT en pleine transaction — constaté en tinker en appliquant la correction
     * du sur-crédit du jour même.
     */
    public function test_motif_au_plafond_ne_fait_pas_echouer_l_ecriture(): void
    {
        $motif = str_repeat('x', 255);

        $this->crediter(['loyalty_code' => 'CREDIT001', 'euros' => 1, 'reason' => $motif])
            ->assertOk();

        $ligne = DB::table('loyalty_transactions')->where('loyalty_code', 'CREDIT001')->first();
        $this->assertNotNull($ligne);
        $this->assertLessThanOrEqual(255, mb_strlen($ligne->description));
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
