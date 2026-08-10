<?php

namespace Tests\Feature\Wheel;

use App\Models\Branch;
use App\Models\Coupon;
use App\Models\Item;
use App\Models\Scopes\BranchScope;
use App\Models\StockOutflow;
use App\Models\User;
use App\Models\WheelSpin;
use App\Services\Wheel\WheelDeliveryService;
use App\Services\Wheel\WheelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * LA REMISE DU LOT — le maillon qui manquait, et sans lequel tout le reste était du théâtre.
 *
 * Trois audits adversaires ont convergé : la roue TIRAIT bien mais ne LIVRAIT rien. Les points
 * étaient écrits sur une ligne que personne ne lisait ; les produits offerts passaient par un coupon
 * à 0,00 € qui brûlait son usage unique, si bien que le client payait plein tarif ET que la
 * comptabilité enregistrait le coût d'un cadeau jamais donné.
 *
 * Ce que cette suite verrouille :
 *   1. un `free_item` ne crée PLUS aucun coupon — il n'y a rien à déduire, il y a un objet à tendre ;
 *   2. les POINTS sont RÉELLEMENT crédités sur le compte du client, par le chemin canonique du
 *      programme de fidélité — pas une seconde comptabilité qui divergerait du solde affiché ;
 *   3. aucun compte à ce numéro → on ne crédite rien, on GARDE le lot, et on le DIT. Inventer un
 *      compte serait créer un client sans son consentement ; promettre des points qui n'arriveront
 *      jamais serait mentir ;
 *   4. la charge du produit offert est inscrite AU MOMENT DE LA REMISE — un humain vient de
 *      confirmer que le cadeau a été donné, c'est plus juste qu'une consommation de coupon ;
 *   5. DOUBLE REMISE IMPOSSIBLE, même sous concurrence : la relecture se fait sous verrou DANS la
 *      transaction, pas par un `if` que deux caisses simultanées franchiraient toutes les deux ;
 *   6. l'écran est ferme aux comptes sans droit caisse.
 */
class WheelDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private int $branchId;
    private int $itemId;
    private WheelDeliveryService $delivery;
    private User $caissier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        if (! file_exists(storage_path('installed'))) {
            touch(storage_path('installed'));
        }

        $this->branchId = Branch::factory()->create()->id;
        $this->itemId = (int) Item::factory()->create()->id;
        $this->delivery = app(WheelDeliveryService::class);

        $this->caissier = User::factory()->create(['branch_id' => $this->branchId]);
        $this->caissier->givePermissionTo('pos');

        Config::set('wheel.enabled', true);
        Config::set('wheel.campaign_key', 'livraison');
        Config::set('wheel.daily_total_cap', 500);
        Config::set('wheel.record_cost_on_claim', true);
        Config::set('wheel.unlock_methods', ['staff' => true, 'order' => true, 'declaratif' => false]);
    }

    private function segment(string $type, string $label, int $value = 0, ?int $costItem = null): void
    {
        Config::set('wheel.segments', [[
            'key' => 'seg', 'label' => $label, 'type' => $type, 'value' => $value,
            'weight' => 1, 'daily_cap' => 0,
            'cost_item_id' => $costItem ?? 0,
            'max_discount' => $type === 'coupon_percent' ? 4.0 : 0,
        ]]);
    }

    private function tourner(string $tel): WheelSpin
    {
        return app(WheelService::class)->spin($this->branchId, $tel, 'Client', ['method' => 'staff']);
    }

    // ── 1. PLUS DE COUPON À 0 € ───────────────────────────────────────────────────────────────

    public function test_un_produit_offert_ne_cree_AUCUN_coupon(): void
    {
        $this->segment('free_item', 'Boisson offerte', 0, $this->itemId);

        $spin = $this->tourner('0611000001');

        $this->assertNull($spin->coupon_id,
            'un coupon a été créé pour un produit offert : avec `discount = 0`, le client saisit le '
            . 'code, le total ne bouge PAS, et l\'usage unique est BRÛLÉ — il paie plein tarif');
        $this->assertSame(0, Coupon::withoutGlobalScopes()->where('code', 'like', 'ROUE-%')->count());
    }

    public function test_une_remise_en_pourcentage_cree_TOUJOURS_son_coupon(): void
    {
        // Contre-preuve : sans elle, on aurait pu « corriger » en cassant les lots qui marchaient.
        $this->segment('coupon_percent', '-10%', 10);

        $spin = $this->tourner('0611000002');

        $this->assertNotNull($spin->coupon_id, 'les lots en remise doivent garder leur code');
    }

    // ── 2. LES POINTS SONT RÉELLEMENT CRÉDITÉS ────────────────────────────────────────────────

    public function test_les_points_sont_REELLEMENT_credites_au_compte_du_client(): void
    {
        $this->segment('points', '100 points', 100);

        $client = User::factory()->create(['branch_id' => 0, 'phone' => '0611000003', 'loyalty_points' => 20]);
        $spin = $this->tourner('0611000003');

        $r = $this->delivery->deliver($spin->id, $this->caissier->id);

        $this->assertTrue($r['ok'], $r['message']);
        $this->assertTrue($r['points_credited'],
            'les points ne sont pas crédités : le client lit « 100 points » et son solde ne bouge pas');
        $this->assertSame(120, (int) $client->fresh()->loyalty_points,
            'le solde du client doit avoir augmenté de 100');
        $this->assertSame($client->id, (int) $spin->fresh()->points_credited_user_id);
    }

    /** Le numéro est écrit autrement : c'est la MÊME personne, le crédit doit la trouver. */
    public function test_le_credit_trouve_le_client_meme_si_le_numero_est_ecrit_autrement(): void
    {
        $this->segment('points', '50 points', 50);

        $client = User::factory()->create(['branch_id' => 0, 'phone' => '0611000004', 'loyalty_points' => 0]);
        $spin = $this->tourner('06 11 00 00 04');

        $r = $this->delivery->deliver($spin->id, $this->caissier->id);

        $this->assertTrue($r['points_credited'],
            'le crédit ne retrouve pas le client : « 06 11 00 00 04 » et « 0611000004 » sont la même '
            . 'personne, le numéro est normalisé au tirage');
        $this->assertSame(50, (int) $client->fresh()->loyalty_points);
    }

    /**
     * AUCUN COMPTE : on ne crédite rien, on garde le lot, et on le DIT. Inventer un compte à partir
     * d'un numéro créerait un client sans son consentement ; promettre des points qui n'arriveront
     * jamais serait un mensonge.
     *
     * ── [P0 2026-08-10] CE TEST ENCODAIT LE DÉFAUT ────────────────────────────────────────────
     * Il exigeait `ok = true` avec pour justification « le lot est acquis ». C'était un raccourci de
     * raisonnement : « le lot est DÛ » et « le lot a été REMIS » ne sont pas la même chose. Comme
     * `deliver()` posait `delivered_at` dans tous les cas, le lot passait pour remis sans qu'un seul
     * point ne soit crédité — et le client qui revenait avec son compte créé, exactement comme le
     * message le lui demandait, s'entendait répondre « ses lots sont déjà remis ». Les points
     * mouraient là.
     *
     * La remise ÉCHOUE donc, et c'est le bon comportement : rien n'a été remis. Le lot reste dû, il
     * reste visible dans les lots en attente, et il sera créditable au retour du client.
     * Voir `WheelPointsDeliveryTest` pour la suite de l'histoire.
     */
    public function test_sans_compte_a_ce_numero_on_ne_credite_rien_et_le_lot_reste_DU(): void
    {
        $this->segment('points', '100 points', 100);
        $spin = $this->tourner('0611000005');

        $r = $this->delivery->deliver($spin->id, $this->caissier->id);

        $this->assertFalse($r['ok'],
            'la remise est déclarée réussie alors que rien n\'a été remis : le lot va être marqué '
            . 'comme donné et les points seront perdus');
        $this->assertFalse($r['points_credited']);
        $this->assertMatchesRegularExpression('/cr[ée]er son compte/iu', $r['message'],
            'le message doit dire au client quoi faire pour récupérer ses points');
        $this->assertNull($spin->fresh()->delivered_at,
            'le lot ne doit PAS être marqué remis : c\'est cette marque qui tuait les points');
        $this->assertSame(0, User::withoutGlobalScopes()->where('phone', '0611000005')->count(),
            'un compte fantôme a été créé sans consentement');
    }

    // ── 3. LA CHARGE, AU MOMENT DE LA REMISE ──────────────────────────────────────────────────

    public function test_la_charge_est_inscrite_au_moment_de_la_remise(): void
    {
        $this->segment('free_item', 'Menu offert', 0, $this->itemId);
        $spin = $this->tourner('0611000006');

        $this->assertSame(0, StockOutflow::withoutGlobalScope(BranchScope::class)->count(),
            'aucune charge avant la remise : le cadeau n\'a pas encore été donné');

        $this->delivery->deliver($spin->id, $this->caissier->id);

        $sortie = StockOutflow::withoutGlobalScope(BranchScope::class)->firstOrFail();
        $this->assertSame(StockOutflow::TYPE_PROMO_GIFT, $sortie->type);
        $this->assertSame('Menu offert', $sortie->item_name);
        $this->assertSame($this->itemId, (int) $sortie->item_id);
        $this->assertSame($this->caissier->id, (int) $sortie->user_id,
            'la charge doit dire QUI a remis le cadeau');
    }

    /** Un réglage manquant ne doit pas empêcher de servir un client. */
    public function test_sans_produit_de_reference_le_lot_est_remis_quand_meme(): void
    {
        $this->segment('free_item', 'Frites offertes', 0, null);
        $spin = $this->tourner('0611000007');

        $r = $this->delivery->deliver($spin->id, $this->caissier->id);

        $this->assertTrue($r['ok'],
            'refuser de servir un client parce qu\'un réglage manque serait absurde');
        $this->assertNotNull($spin->fresh()->delivered_at);
        $this->assertSame(0, StockOutflow::withoutGlobalScope(BranchScope::class)->count(),
            'la charge n\'est pas chiffrée — c\'est signalé ailleurs, pas bloquant ici');
    }

    /**
     * LE REPLI PAR NOM. Un trou comptable ne doit pas dépendre d'une variable d'environnement que
     * quelqu'un a pensé à poser : si aucun identifiant n'est réglé, on cherche le produit par son
     * NOM dans la carte. L'exploitant garde la main (son identifiant prime toujours).
     */
    public function test_sans_identifiant_le_produit_de_cout_est_trouve_par_son_NOM(): void
    {
        $vrai = Item::factory()->create(['name' => 'Boisson Seule']);

        Config::set('wheel.segments', [[
            'key' => 'seg', 'label' => 'Boisson offerte', 'type' => 'free_item', 'value' => 0,
            'weight' => 1, 'daily_cap' => 0,
            'cost_item_id' => 0,                  // rien de réglé
            'cost_item_name' => 'Boisson Seule',  // le repli
        ]]);

        $spin = $this->tourner('0611000020');
        $this->delivery->deliver($spin->id, $this->caissier->id);

        $sortie = StockOutflow::withoutGlobalScope(BranchScope::class)->firstOrFail();
        $this->assertSame((int) $vrai->id, (int) $sortie->item_id,
            'le repli par nom n\'a pas trouvé le produit : le cadeau resterait non chiffré');
    }

    /**
     * ET IL NE DEVINE PAS. Un nom qui ne correspond à rien (produit renommé) ne doit PAS retomber
     * sur « le premier produit trouvé » : mieux vaut un cadeau non chiffré SIGNALÉ qu'un cadeau
     * chiffré sur le mauvais produit, qui ferait dériver l'inventaire de celui-là en silence.
     */
    public function test_un_nom_qui_ne_correspond_a_rien_ne_devine_AUCUN_produit(): void
    {
        Item::factory()->create(['name' => 'Boisson Seule']);

        Config::set('wheel.segments', [[
            'key' => 'seg', 'label' => 'Boisson offerte', 'type' => 'free_item', 'value' => 0,
            'weight' => 1, 'daily_cap' => 0,
            'cost_item_id' => 0,
            'cost_item_name' => 'Produit Qui N Existe Pas',
        ]]);

        $spin = $this->tourner('0611000021');
        $r = $this->delivery->deliver($spin->id, $this->caissier->id);

        $this->assertTrue($r['ok'], 'le client doit être servi même si le chiffrage échoue');
        $this->assertSame(0, StockOutflow::withoutGlobalScope(BranchScope::class)->count(),
            'un produit a été DEVINÉ : l\'inventaire de ce produit dériverait en silence');
    }

    // ── 4. DOUBLE REMISE ──────────────────────────────────────────────────────────────────────

    public function test_un_lot_ne_se_remet_JAMAIS_deux_fois(): void
    {
        $this->segment('free_item', 'Boisson offerte', 0, $this->itemId);
        $spin = $this->tourner('0611000008');

        $premier = $this->delivery->deliver($spin->id, $this->caissier->id);
        $second = $this->delivery->deliver($spin->id, $this->caissier->id);

        $this->assertTrue($premier['ok']);
        $this->assertFalse($second['ok'],
            'un client réclamerait son lot à chaque service et l\'équipe n\'aurait aucun moyen de '
            . 'savoir qu\'il l\'a déjà eu');
        $this->assertStringContainsString('déjà été remis', $second['message']);
        $this->assertSame(1, StockOutflow::withoutGlobalScope(BranchScope::class)->count(),
            'la charge a été inscrite deux fois pour un seul cadeau');
    }

    public function test_les_points_ne_sont_credites_qu_une_fois(): void
    {
        $this->segment('points', '100 points', 100);
        $client = User::factory()->create(['branch_id' => 0, 'phone' => '0611000009', 'loyalty_points' => 0]);
        $spin = $this->tourner('0611000009');

        $this->delivery->deliver($spin->id, $this->caissier->id);
        $this->delivery->deliver($spin->id, $this->caissier->id);
        $this->delivery->deliver($spin->id, $this->caissier->id);

        $this->assertSame(100, (int) $client->fresh()->loyalty_points,
            'les points ont été crédités plusieurs fois : c\'est de la monnaie fabriquée');
    }

    /** Un lot en remise n'a rien à tendre : le code fait le travail sur le site. */
    public function test_un_lot_en_remise_n_est_PAS_remis_au_comptoir(): void
    {
        $this->segment('coupon_percent', '-10%', 10);
        $spin = $this->tourner('0611000010');

        $this->assertNull($this->delivery->pending($this->branchId, '0611000010'),
            'un lot en pourcentage ne doit pas apparaître comme « à remettre » au comptoir');

        $r = $this->delivery->deliver($spin->id, $this->caissier->id);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('code', $r['message']);
    }

    // ── 5. L'ÉCRAN ────────────────────────────────────────────────────────────────────────────

    public function test_l_ecran_de_remise_est_ferme_sans_droit_caisse(): void
    {
        $quidam = User::factory()->create(['branch_id' => $this->branchId]);

        $this->actingAs($quidam)->get('/admin/roue-lot')->assertStatus(403);
        $this->actingAs($quidam)->post('/admin/roue-lot/remettre', ['spin_id' => 1])->assertStatus(403);

        // L'anonyme est refusé par la garde de DROIT (403) avant même l'authentification, selon
        // l'ordre des intergiciels. Les trois formes conviennent : ce qui compte est que la porte
        // soit FERMÉE, pas par quel mécanisme. Figer un code précis ferait échouer ce test au
        // premier réagencement des intergiciels, sans qu'aucune sécurité n'ait bougé.
        $anonyme = $this->get('/admin/roue-lot');
        $this->assertContains($anonyme->status(), [401, 403, 302],
            'l\'écran de remise est accessible sans connexion : n\'importe qui distribuerait des lots');
    }

    public function test_l_ecran_trouve_le_lot_et_permet_de_le_remettre(): void
    {
        $this->segment('free_item', 'Menu offert', 0, $this->itemId);
        $spin = $this->tourner('0611000011');

        $r = $this->actingAs($this->caissier, 'web')->get('/admin/roue-lot?phone=0611000011')->assertOk();
        $r->assertSee('Menu offert', false);
        $r->assertSee('REMIS AU CLIENT', false);

        $r2 = $this->actingAs($this->caissier, 'web')->post('/admin/roue-lot/remettre', [
            'spin_id' => $spin->id, 'phone' => '0611000011',
        ])->assertOk();

        $r2->assertSee('Remis', false);
        $this->assertNotNull($spin->fresh()->delivered_at);

        // Après la remise, plus de bouton : un second bouton juste après un succès est la meilleure
        // façon de provoquer un double clic.
        $r2->assertDontSee('REMIS AU CLIENT', false);
    }

    public function test_un_numero_inconnu_le_dit_clairement(): void
    {
        $r = $this->actingAs($this->caissier, 'web')->get('/admin/roue-lot?phone=0699999999')->assertOk();

        $r->assertSee('Aucun tour à ce numéro', false);
        $r->assertDontSee('REMIS AU CLIENT', false);
    }
}
