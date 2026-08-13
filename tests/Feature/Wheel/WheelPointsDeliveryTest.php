<?php

namespace Tests\Feature\Wheel;

use App\Enums\Ask;
use App\Models\Branch;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use App\Models\WheelSpin;
use App\Services\Wheel\WheelDeliveryService;
use App\Services\Wheel\WheelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * LA REMISE D'UN LOT EN POINTS — et le P0 qu'elle cachait.
 *
 * ── CE QUI SE PASSAIT ────────────────────────────────────────────────────────────────────────
 * `delivered_at` était posé quel que soit le résultat du crédit. Quand aucun compte ne portait le
 * numéro du gagnant, l'écran du comptoir affichait un bandeau VERT : « points en attente : dis-lui
 * de créer son compte avec CE numéro, les points y seront ajoutés » — et, soixante pixels plus bas,
 * « remis le 10/08/2026 ».
 *
 * La promesse était donc impossible à tenir : le client revenait avec son compte créé, l'équipe
 * cherchait son numéro, et lisait « rien à remettre : ses lots sont déjà remis ». Les points
 * mouraient là, sans trace, avec l'air d'avoir été donnés.
 *
 * ── LA RÈGLE ─────────────────────────────────────────────────────────────────────────────────
 * Un lot n'est marqué REMIS que si quelque chose a réellement été remis. Rien d'autre ne peut le
 * marquer — ni une bonne intention, ni un message d'attente.
 */
class WheelPointsDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branchId = Branch::factory()->create()->id;

        Config::set('wheel.enabled', true);
        // [ROUE × CAISSE 2026-08-10] La roue ne tire plus de lot en REMISE quand la caisse
        // refuse les codes — c'est une garde neuve, et elle est juste. Ce banc parle d'autre
        // chose : on accepte donc les codes ici, pour qu'un interrupteur de la caisse ne
        // décide pas de ce qu'il éprouve.
        Config::set('pos.coupon_codes_enabled', true);
        Config::set('wheel.campaign_key', 'test-points');
        Config::set('wheel.daily_total_cap', 500);
        Config::set('wheel.unlock_methods', ['staff' => true, 'order' => true, 'declaratif' => false]);
        Config::set('wheel.segments', [
            ['key' => 'p', 'label' => '50 points', 'type' => 'points', 'value' => 50,
             'weight' => 1, 'daily_cap' => 0],
        ]);
    }

    private function tour(string $tel, string $mail): WheelSpin
    {
        return app(WheelService::class)->spin(
            $this->branchId, $tel, 'Client', ['method' => 'staff'], null, null, $mail
        );
    }

    /**
     * AUCUN COMPTE À CE NUMÉRO → le lot est CONSERVÉ, pas marqué remis.
     *
     * C'est le cœur du défaut. Le message d'attente et la marque de remise ne peuvent pas coexister.
     */
    /**
     * LE GRAND-LIVRE DOIT PORTER LE CADEAU — sinon le solde monte sans explication.
     *
     * ── LE DÉFAUT, MESURÉ ────────────────────────────────────────────────────────────────────
     * `WheelDeliveryService` est le SEUL mouvement de solde de toute l'application qui n'écrit rien
     * dans `loyalty_transactions` : zéro occurrence de la table ET du modèle dans le fichier, alors
     * que les six autres chemins (gain sur commande, débit caisse, débit site/borne, ajout par
     * l'équipe, remboursement, reprise) en écrivent tous.
     *
     * ── POURQUOI ÇA COMPTE MAINTENANT ────────────────────────────────────────────────────────
     * L'écran de fidélité du comptoir affiche désormais l'HISTORIQUE des points, lu dans ce
     * grand-livre. Un client qui gagne 50 points à la roue voit donc son solde monter de 50 sans
     * qu'aucune ligne l'explique — et le caissier, à qui le client demande « d'où viennent ces
     * points ? », n'a rien à lui montrer. C'est exactement le « solde sans histoire » que cet écran
     * a été construit pour supprimer.
     *
     * ── CE QUE CE BANC EXIGE ─────────────────────────────────────────────────────────────────
     * Une ligne, avec le solde APRÈS (pour pouvoir rejouer la suite), la surface « wheel » (pour
     * qu'on sache d'où ça vient), aucun identifiant de commande (un cadeau de roue n'en a pas), et
     * une description qui NOMME la roue.
     */
    public function test_le_cadeau_en_points_laisse_une_ligne_au_GRAND_LIVRE(): void
    {
        $spin = $this->tour('0611002200', 'grandlivre@exemple.fr');

        $u = User::factory()->create(['phone' => '0611002200', 'is_guest' => Ask::YES]);
        \Illuminate\Support\Facades\DB::table('users')->where('id', $u->id)
            ->update(['loyalty_code' => 'GLIVRE01', 'loyalty_points' => 120]);

        $r = app(WheelDeliveryService::class)->deliver($spin->id, null);
        $this->assertTrue($r['points_credited'], 'le crédit lui-même a échoué : le banc ne parle pas de ça');
        $this->assertSame(170, (int) $u->fresh()->loyalty_points, '120 + 50');

        $lignes = \Illuminate\Support\Facades\DB::table('loyalty_transactions')
            ->where('user_id', $u->id)->get();

        $this->assertCount(1, $lignes,
            'aucune ligne au grand-livre : le solde du client monte sans explication, et l\'historique '
            . 'du comptoir ne peut rien lui montrer');

        $l = $lignes->first();
        $this->assertSame('earn', $l->type, 'un cadeau est un GAIN');
        $this->assertSame(50, (int) $l->points);
        $this->assertSame(170, (int) $l->balance_after, 'le solde APRÈS, pour pouvoir rejouer la suite');
        $this->assertSame('wheel', $l->source_surface, 'la surface dit d\'où vient le point');
        $this->assertNull($l->order_id, 'un cadeau de roue n\'est rattaché à aucune commande');
        $this->assertMatchesRegularExpression('/roue/i', (string) $l->description,
            'la description doit NOMMER la roue : « earn » seul se lit « gagné sur une commande »');
    }

    /**
     * ET UNE SEULE LIGNE, même si l'équipe appuie deux fois sur « remettre ».
     *
     * Le crédit est déjà protégé par sa garde atomique ; l'écriture au grand-livre doit vivre DANS
     * la même transaction, sinon un incident entre les deux laisse un solde sans sa ligne — ou une
     * ligne sans son solde, ce qui est pire : le grand-livre deviendrait faux.
     */
    public function test_une_double_remise_ne_laisse_pas_deux_lignes(): void
    {
        $spin = $this->tour('0611002201', 'glivre2@exemple.fr');

        $u = User::factory()->create(['phone' => '0611002201', 'is_guest' => Ask::YES]);
        \Illuminate\Support\Facades\DB::table('users')->where('id', $u->id)
            ->update(['loyalty_code' => 'GLIVRE02', 'loyalty_points' => 0]);

        app(WheelDeliveryService::class)->deliver($spin->id, null);
        app(WheelDeliveryService::class)->deliver($spin->id, null);

        $this->assertSame(1, (int) \Illuminate\Support\Facades\DB::table('loyalty_transactions')
            ->where('user_id', $u->id)->count(), 'deux lignes pour un seul cadeau');
        $this->assertSame(50, (int) $u->fresh()->loyalty_points, 'et le solde n\'a pas doublé');
    }

    public function test_sans_compte_les_points_sont_CONSERVES_et_le_lot_n_est_PAS_marque_remis(): void
    {
        $spin = $this->tour('0611000901', 'sanscompte@exemple.fr');

        $r = app(WheelDeliveryService::class)->deliver($spin->id, null);

        $this->assertFalse($r['ok'], 'la remise a été acceptée alors que rien n\'a été remis');
        $this->assertFalse($r['points_credited']);
        $this->assertMatchesRegularExpression('/CONSERV/iu', (string) $r['message'],
            'le message doit dire que le lot est gardé — sinon l\'équipe croit l\'avoir donné');
        $this->assertMatchesRegularExpression('/compte/iu', (string) $r['message'],
            'le message doit dire QUOI FAIRE : créer un compte avec ce numéro');

        $this->assertNull($spin->fresh()->delivered_at,
            'LES POINTS SONT MORTS : le lot est marqué remis alors qu\'aucun point n\'a été crédité, '
            . 'donc toute nouvelle tentative répondra « déjà remis »');
    }

    /**
     * LA SUITE DE L'HISTOIRE, et la preuve que la promesse tient : le client crée son compte, revient,
     * et l'équipe peut enfin lui créditer ses points. C'est exactement ce que le message annonce.
     */
    public function test_le_client_revient_avec_son_compte_et_les_points_sont_ENFIN_credites(): void
    {
        $spin = $this->tour('0611000902', 'revient@exemple.fr');

        app(WheelDeliveryService::class)->deliver($spin->id, null);
        $this->assertNull($spin->fresh()->delivered_at);

        // Il crée son compte avec CE numéro, comme on lui a dit.
        $u = User::factory()->create([
            'phone' => '0611000902', 'branch_id' => 0, 'is_guest' => Ask::YES, 'loyalty_points' => 12,
        ]);

        $r = app(WheelDeliveryService::class)->deliver($spin->id, null);

        $this->assertTrue($r['ok'], 'le lot conservé n\'a pas pu être remis au retour du client');
        $this->assertTrue($r['points_credited']);
        $this->assertSame(62, (int) $u->fresh()->loyalty_points);
        $this->assertNotNull($spin->fresh()->delivered_at, 'là, il est bien remis');
    }

    /** Et une fois vraiment remis, la double remise reste refusée en nommant la date. */
    public function test_une_fois_credites_la_double_remise_reste_refusee(): void
    {
        $spin = $this->tour('0611000903', 'double@exemple.fr');
        User::factory()->create([
            'phone' => '0611000903', 'branch_id' => 0, 'is_guest' => Ask::YES, 'loyalty_points' => 0,
        ]);

        app(WheelDeliveryService::class)->deliver($spin->id, null);
        $r = app(WheelDeliveryService::class)->deliver($spin->id, null);

        $this->assertFalse($r['ok']);
        $this->assertMatchesRegularExpression('/d[ée]j[àa] .*remis/iu', (string) $r['message']);
        $this->assertSame(50, (int) User::withoutGlobalScopes()
            ->where('phone', '0611000903')->value('loyalty_points'),
            'les points ont été crédités DEUX FOIS : la maison paie deux fois le même lot');
    }

    // ── LES TROIS FAÇONS DE NE PAS TROUVER LE BON COMPTE ─────────────────────────────────────

    /**
     * [P1 2026-08-10 · audit ronde 2] LE JUMEAU OUBLIÉ, ET C'EST MOI QUI L'AI OUBLIÉ.
     *
     * `CustomerAccountProvisioner::variantes()` cherche « ce numéro » sous ses QUATRE écritures — parce que la
     * base contient « 0612345678 », « 612345678 » et « +33612345678 » pour le même humain. Son jumeau
     * `creditPoints()`, trente lignes plus loin, n'en cherchait QU'UNE.
     *
     * Mesuré en base : 62 comptes sur 348 portent une forme non normalisée. Pour eux, le compte
     * EXISTE, la réclamation l'a retrouvé — et la remise répondait « aucun compte à ce numéro, dis-lui
     * de créer son compte avec CE numéro puis reviens ». Une consigne impossible à exécuter, sur ce
     * qui est le lot le plus fréquent quand les codes de remise sont éteints.
     *
     * Une seule définition de « ce numéro », partagée. C'est la leçon « un correctif est complet sur la
     * surface regardée, pas sur ses jumelles » — recommise le jour même où je l'ai écrite.
     */
    public function test_les_points_trouvent_le_compte_meme_ecrit_SANS_le_zero(): void
    {
        $spin = $this->tour('0611000910', 'variante@exemple.fr');

        // Forme réellement présente en base (constatée : « 600099482 »).
        $u = User::factory()->create([
            'phone' => '611000910', 'branch_id' => 0, 'is_guest' => Ask::YES, 'loyalty_points' => 3,
        ]);

        $r = app(WheelDeliveryService::class)->deliver($spin->id, null);

        $this->assertTrue($r['ok'], $r['message']);
        $this->assertTrue($r['points_credited'],
            'le compte existe sous une autre écriture du numéro : le comptoir envoie le client créer '
            . 'un compte qu\'il a déjà, ce qui est impossible à faire');
        $this->assertSame(53, (int) $u->fresh()->loyalty_points);
    }

    public function test_les_points_trouvent_le_compte_ecrit_au_format_international(): void
    {
        $spin = $this->tour('0611000911', 'intl@exemple.fr');
        $u = User::factory()->create([
            'phone' => '+33611000911', 'branch_id' => 0, 'is_guest' => Ask::YES, 'loyalty_points' => 0,
        ]);

        $this->assertTrue(app(WheelDeliveryService::class)->deliver($spin->id, null)['points_credited']);
        $this->assertSame(50, (int) $u->fresh()->loyalty_points);
    }

    /**
     * [P1] LES POINTS ÉTAIENT CRÉDITÉS SUR UN COMPTE SUPPRIMÉ. `withoutGlobalScopes()` retire aussi le
     * filtre de suppression douce : le lot était clos, l'écran annonçait « points crédités sur son
     * compte », et le client vivant n'avait rien.
     */
    public function test_les_points_ne_sont_PAS_credites_sur_un_compte_supprime(): void
    {
        $spin = $this->tour('0611000912', 'mort@exemple.fr');
        $mort = User::factory()->create([
            'phone' => '0611000912', 'branch_id' => 0, 'is_guest' => Ask::YES, 'loyalty_points' => 0,
        ]);
        $mort->delete();

        $r = app(WheelDeliveryService::class)->deliver($spin->id, null);

        $this->assertFalse($r['ok'],
            'les points sont crédités sur un compte SUPPRIMÉ : le lot est clos et personne ne les a');
        $this->assertNull($spin->fresh()->delivered_at, 'le lot doit rester dû');
        $this->assertSame(0, (int) User::withoutGlobalScopes()->withTrashed()
            ->whereKey($mort->id)->value('loyalty_points'));
    }

    /** Ni sur un compte de l'ÉQUIPE : un numéro n'est pas une preuve d'identité. */
    public function test_les_points_ne_sont_PAS_credites_sur_un_compte_de_l_equipe(): void
    {
        $spin = $this->tour('0611000913', 'staff@exemple.fr');
        $staff = User::factory()->create([
            'phone' => '0611000913', 'branch_id' => 1, 'is_guest' => Ask::NO, 'loyalty_points' => 0,
        ]);

        $r = app(WheelDeliveryService::class)->deliver($spin->id, null);

        $this->assertFalse($r['ok']);
        $this->assertSame(0, (int) $staff->fresh()->loyalty_points,
            'les points d\'un client ont été versés sur un compte de l\'équipe');
    }

    /** Le lot reste visible comme EN ATTENTE tant qu'il n'est pas remis — sinon l'équipe l'oublie. */
    public function test_le_lot_conserve_reste_visible_dans_les_lots_en_attente(): void
    {
        $spin = $this->tour('0611000904', 'attente@exemple.fr');
        app(WheelDeliveryService::class)->deliver($spin->id, null);

        $enAttente = app(WheelDeliveryService::class)->pending($this->branchId, '0611000904');

        $this->assertNotNull($enAttente,
            'le lot conservé a disparu des lots en attente : personne ne saura qu\'il est dû');
        $this->assertSame($spin->id, $enAttente->id);
        $this->assertSame(1, WheelSpin::withoutGlobalScope(BranchScope::class)
            ->whereNull('delivered_at')->count());
    }
}
