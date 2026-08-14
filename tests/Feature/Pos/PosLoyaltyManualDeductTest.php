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
 * RETIRER MANUELLEMENT DES POINTS — le geste de correction du 2026-08-14 :
 * « comme j'ai fait ajouter [...] je préfère ça diminuer ici [...] je veux pas annuler ».
 *
 * Né d'un incident réel : `PosManualCreditService::credit()` utilisait le mauvais barème
 * (remise 100 pts/€ au lieu du gain 10 pts/€) — un crédit de 17,30€ a posé 1730 points au lieu
 * de 173. Le propriétaire a demandé un moyen de CORRIGER le solde SANS effacer l'écriture
 * fautive (grand-livre append-only). Ce banc couvre l'outil de correction, pas l'incident
 * lui-même (couvert par `PosLoyaltyManualCreditTest`).
 */
class PosLoyaltyManualDeductTest extends TestCase
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
        $this->caissier = User::factory()->create(['branch_id' => $this->branche->id, 'phone' => '0100000011']);
        $this->caissier->assignRole('POS Operator');

        $this->client = User::factory()->create(['phone' => '0699887788', 'is_guest' => Ask::YES]);
        DB::table('users')->where('id', $this->client->id)
            ->update(['loyalty_code' => 'DEDUCT001', 'loyalty_points' => 1730]);
        $this->client->refresh();
    }

    private function retirer(array $corps, ?User $agent = null)
    {
        return $this->actingAs($agent ?? $this->caissier, 'sanctum')
            ->withHeader('X-Idempotency-Key', 'ded-'.bin2hex(random_bytes(8)))
            ->postJson('/api/admin/pos-loyalty/deduct-manual', $corps);
    }

    /** LE CAS RÉEL : correction exacte de l'incident du 14 août — 1557 points retirés de 1730. */
    public function test_retrait_corrige_le_sur_credit_reel(): void
    {
        $reponse = $this->retirer(['loyalty_code' => 'DEDUCT001', 'points' => 1557]);

        $reponse->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.points_removed', 1557)
            ->assertJsonPath('data.balance_after', 173);

        $this->assertSame(173, (int) DB::table('users')->where('id', $this->client->id)->value('loyalty_points'));

        $ligne = DB::table('loyalty_transactions')->where('loyalty_code', 'DEDUCT001')->where('type', 'manual_deduct')->first();
        $this->assertNotNull($ligne);
        $this->assertSame(-1557, (int) $ligne->points);
        $this->assertSame(173, (int) $ligne->balance_after);
    }

    /** L'écriture fautive d'origine reste intacte — on ne l'efface ni ne la modifie jamais. */
    public function test_retrait_ne_touche_pas_les_lignes_precedentes(): void
    {
        DB::table('loyalty_transactions')->insert([
            'user_id'        => $this->client->id,
            'loyalty_code'   => 'DEDUCT001',
            'order_id'       => null,
            'type'           => 'manual_add',
            'points'         => 1730,
            'balance_after'  => 1730,
            'source_surface' => 'pos',
            'description'    => 'Crédit manuel de 17,30€ par caissier #3',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $this->retirer(['loyalty_code' => 'DEDUCT001', 'points' => 1557])->assertOk();

        $originale = DB::table('loyalty_transactions')
            ->where('loyalty_code', 'DEDUCT001')->where('type', 'manual_add')->first();
        $this->assertNotNull($originale);
        $this->assertSame(1730, (int) $originale->points);
        $this->assertSame(1730, (int) $originale->balance_after);
        $this->assertSame(2, DB::table('loyalty_transactions')->where('loyalty_code', 'DEDUCT001')->count());
    }

    /** Le plancher : jamais de solde négatif, même si on demande plus que le solde. */
    public function test_retrait_plafonne_au_solde_jamais_negatif(): void
    {
        $reponse = $this->retirer(['loyalty_code' => 'DEDUCT001', 'points' => 5000]);

        $reponse->assertOk()
            ->assertJsonPath('data.points_removed', 1730)
            ->assertJsonPath('data.balance_after', 0);

        $this->assertSame(0, (int) DB::table('users')->where('id', $this->client->id)->value('loyalty_points'));
    }

    public function test_code_inconnu_refuse_404(): void
    {
        $this->retirer(['loyalty_code' => 'INTROUVABLE', 'points' => 5])
            ->assertStatus(404)
            ->assertJsonPath('code', 'CUSTOMER_NOT_FOUND');
    }

    public function test_points_negatifs_refuse_422(): void
    {
        $this->retirer(['loyalty_code' => 'DEDUCT001', 'points' => -1])
            ->assertStatus(422);
    }

    public function test_compte_equipe_refuse(): void
    {
        DB::table('users')->where('id', $this->caissier->id)->update(['loyalty_code' => 'STAFF002']);

        $this->retirer(['loyalty_code' => 'STAFF002', 'points' => 5])
            ->assertStatus(422)
            ->assertJsonPath('code', 'STAFF_ACCOUNT');
    }

    public function test_sans_permission_pos_refuse_403(): void
    {
        $intrus = User::factory()->create(['branch_id' => $this->branche->id]);

        $this->retirer(['loyalty_code' => 'DEDUCT001', 'points' => 5], $intrus)
            ->assertStatus(403);
    }
}
