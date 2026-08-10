<?php

namespace Tests\Feature\Wheel;

use App\Http\Middleware\EnsureWheelAccess;
use App\Models\Branch;
use App\Models\Item;
use App\Models\StockLevel;
use App\Models\WheelSpin;
use App\Services\Wheel\WheelDeliveryService;
use App\Services\Wheel\WheelReportService;
use App\Services\Wheel\WheelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * CE QUE LA ROUE A DONNÉ — la lecture qui manquait au dispositif de contrôle.
 *
 * ── LE MANQUE ────────────────────────────────────────────────────────────────────────────────
 * Le jeu avait des PLAFONDS — un par lot, un pour la journée — et **aucun endroit où lire ce qui
 * était réellement sorti**. Le propriétaire réglait donc des limites à l'aveugle, et la seule
 * restitution existante était une commande en ligne de commande qu'il n'exécutera jamais.
 *
 * Un dispositif de contrôle sans lecture n'est pas un contrôle : c'est une intention.
 *
 * ── L'HONNÊTETÉ DU CHIFFRE ───────────────────────────────────────────────────────────────────
 * `items` ne porte que le prix de VENTE : il n'existe aucun prix d'achat dans cette base. Le tableau
 * annonce donc « valeur offerte » — le chiffre d'affaires abandonné — et l'écran le DIT. Appeler ça
 * un coût serait inventer une marge.
 */
class WheelReportTest extends TestCase
{
    use RefreshDatabase;

    private int $branchId;
    private int $itemId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        if (! file_exists(storage_path('installed'))) {
            touch(storage_path('installed'));
        }

        $this->branchId = Branch::factory()->create()->id;
        $this->itemId = (int) Item::factory()->create(['price' => 2.50])->id;
        StockLevel::create([
            'branch_id' => $this->branchId, 'stockable_type' => Item::class,
            'stockable_id' => $this->itemId, 'on_hand' => 50, 'is_tracked' => 1,
        ]);

        Config::set('wheel.enabled', true);
        Config::set('wheel.campaign_key', 'test-bilan');
        Config::set('wheel.daily_total_cap', 120);
        Config::set('wheel.record_cost_on_claim', true);
        Config::set('wheel.access.pin', '481526');
        // [ROUE × CAISSE 2026-08-10] La roue ne tire plus de lot en REMISE quand la caisse
        // refuse les codes. Ce banc parle d'autre chose : on accepte donc les codes ici, pour
        // qu'un interrupteur de la caisse ne décide pas de ce qu'il éprouve.
        Config::set('pos.coupon_codes_enabled', true);
        Config::set('wheel.counter_branch_id', $this->branchId);
        Config::set('wheel.unlock_methods', ['staff' => true, 'order' => true, 'declaratif' => false]);
        Config::set('wheel.segments', [[
            'key' => 'g', 'label' => 'Boisson offerte', 'type' => 'free_item', 'value' => 0,
            'weight' => 1, 'daily_cap' => 0, 'cost_item_id' => $this->itemId,
        ]]);
    }

    private function tour(int $branchId, string $tel): WheelSpin
    {
        return app(WheelService::class)->spin(
            $branchId, $tel, 'Client', ['method' => 'staff'], null, null, $tel . '@exemple.fr'
        );
    }

    // ── 1. LES CHIFFRES ──────────────────────────────────────────────────────────────────────

    public function test_le_tableau_compte_les_tours_et_chiffre_ce_qui_a_ete_offert(): void
    {
        $a = $this->tour($this->branchId, '0611000901');
        $b = $this->tour($this->branchId, '0611000902');
        $this->tour($this->branchId, '0611000903');   // gagné mais PAS remis

        app(WheelDeliveryService::class)->deliver($a->id, null, $this->branchId);
        app(WheelDeliveryService::class)->deliver($b->id, null, $this->branchId);

        $t = app(WheelReportService::class)->tableau($this->branchId);
        $jour = $t['periodes']['aujourdhui'];

        $this->assertSame(3, $jour['tours']);
        $this->assertSame(2, $jour['cadeaux_remis']);
        $this->assertSame(1, $jour['cadeaux_dus'],
            'un lot dû qu\'on ne compte pas est de l\'argent promis qui dort sans que personne le sache');
        $this->assertSame(5.00, $jour['valeur_offerte'],
            'deux boissons à 2,50 € = 5,00 € de chiffre d\'affaires abandonné');
        $this->assertSame(3, $t['lots_dus'] + 2, 'le total des lots dus doit rester cohérent');
    }

    public function test_le_plafond_du_jour_est_lisible(): void
    {
        $this->tour($this->branchId, '0611000904');

        $t = app(WheelReportService::class)->tableau($this->branchId);

        $this->assertSame(1, $t['plafond_jour']['utilise']);
        $this->assertSame(120, $t['plafond_jour']['plafond'],
            'un plafond réglé sans être lu quelque part est une intention, pas un contrôle');
    }

    public function test_les_codes_de_remise_sont_comptes_emis_et_utilises(): void
    {
        Config::set('wheel.segments', [[
            'key' => 'p', 'label' => '-10%', 'type' => 'coupon_percent', 'value' => 10,
            'weight' => 1, 'daily_cap' => 0, 'max_discount' => 4.0,
        ]]);

        $un = $this->tour($this->branchId, '0611000905');
        $this->tour($this->branchId, '0611000906');

        // Un des deux codes est consommé sur une commande.
        \App\Models\Coupon::withoutGlobalScopes()->whereKey($un->coupon_id)->update(['usage_count' => 1]);

        $jour = app(WheelReportService::class)->tableau($this->branchId)['periodes']['aujourdhui'];

        $this->assertSame(2, $jour['codes_emis']);
        $this->assertSame(1, $jour['codes_utilises']);
        $this->assertSame(4.00, $jour['exposition_max'],
            'l\'exposition doit porter sur les codes NON utilisés : c\'est le pire cas qui reste dehors');
    }

    /** Chaque caisse voit SES chiffres. Un bilan qui additionne les points de vente ne pilote rien. */
    public function test_le_bilan_ne_compte_que_les_tours_de_CETTE_caisse(): void
    {
        $voisin = Branch::factory()->create();

        $this->tour($this->branchId, '0611000907');
        $this->tour($voisin->id, '0611000908');
        $this->tour($voisin->id, '0611000909');

        $this->assertSame(1, app(WheelReportService::class)
            ->tableau($this->branchId)['periodes']['aujourdhui']['tours']);
        $this->assertSame(2, app(WheelReportService::class)
            ->tableau($voisin->id)['periodes']['aujourdhui']['tours']);
    }

    // ── 1bis. LES TROIS FUITES DU CHIFFRE EN EUROS ───────────────────────────────────────────

    /**
     * [P0 2026-08-10 · audit ronde 2 vague D] LE CHIFFRE EN EUROS ÉTAIT FAUX D'UN FACTEUR 9,5.
     *
     * `Coupon::query()->withoutGlobalScopes()->where('code','like','ROUE-%')` ramassait TOUT :
     *   · `withoutGlobalScopes()` retire AUSSI le filtre de suppression douce → les coupons
     *     supprimés étaient comptés (mesuré : 179,00 € sur 237,00 €) ;
     *   · aucun filtre de caisse → un coupon d'un autre point de vente comptait (33,00 €) ;
     *   · aucune jointure sur `wheel_spins.coupon_id` → un coupon simplement NOMMÉ « ROUE-… »,
     *     créé à la main, comptait comme un lot de la roue.
     *
     * Un tableau de contrôle qui exagère l'exposition de dix fois est pire que pas de tableau : on
     * règle les plafonds sur un chiffre inventé.
     *
     * C'est le piège `withoutGlobalScopes()` que j'avais consigné le matin même et dans lequel je
     * suis retombé l'après-midi.
     */
    public function test_les_coupons_SUPPRIMES_ne_comptent_plus(): void
    {
        Config::set('wheel.segments', [[
            'key' => 'p', 'label' => '-10%', 'type' => 'coupon_percent', 'value' => 10,
            'weight' => 1, 'daily_cap' => 0, 'max_discount' => 4.0,
        ]]);

        $vivant = $this->tour($this->branchId, '0611000920');
        $mort = $this->tour($this->branchId, '0611000921');
        \App\Models\Coupon::withoutGlobalScopes()->whereKey($mort->coupon_id)->delete();

        $jour = app(WheelReportService::class)->tableau($this->branchId)['periodes']['aujourdhui'];

        $this->assertSame(1, $jour['codes_emis'],
            'un coupon SUPPRIMÉ est encore compté : le tableau exagère ce qui est dehors');
        $this->assertSame(4.00, $jour['exposition_max']);
        $this->assertNotNull($vivant->coupon_id);
    }

    public function test_un_coupon_d_une_AUTRE_caisse_ne_compte_pas(): void
    {
        $voisin = Branch::factory()->create();
        Config::set('wheel.segments', [[
            'key' => 'p', 'label' => '-10%', 'type' => 'coupon_percent', 'value' => 10,
            'weight' => 1, 'daily_cap' => 0, 'max_discount' => 4.0,
        ]]);

        $this->tour($this->branchId, '0611000922');
        $this->tour($voisin->id, '0611000923');

        $this->assertSame(1, app(WheelReportService::class)
            ->tableau($this->branchId)['periodes']['aujourdhui']['codes_emis'],
            'le coupon d\'un autre point de vente est compté ici : chaque caisse doit voir SES chiffres');
    }

    /** Un coupon simplement NOMMÉ « ROUE-… », créé à la main, n'est pas un lot de la roue. */
    public function test_un_coupon_juste_NOMME_ROUE_ne_compte_pas(): void
    {
        Config::set('wheel.segments', [[
            'key' => 'p', 'label' => '-10%', 'type' => 'coupon_percent', 'value' => 10,
            'weight' => 1, 'daily_cap' => 0, 'max_discount' => 4.0,
        ]]);

        $this->tour($this->branchId, '0611000924');

        \App\Models\Coupon::query()->forceCreate([
            'name' => 'promo maison', 'code' => 'ROUE-FAUX01', 'discount' => 50,
            'discount_type' => \App\Enums\DiscountType::FIXED, 'start_date' => now(),
            'end_date' => now()->addDays(30), 'status' => \App\Enums\Status::ACTIVE,
            'max_uses_global' => 1, 'limit_per_user' => 1, 'minimum_order' => 0,
            'maximum_discount' => 999.0, 'usage_count' => 0,
        ]);

        $jour = app(WheelReportService::class)->tableau($this->branchId)['periodes']['aujourdhui'];

        $this->assertSame(1, $jour['codes_emis'],
            'un coupon qui porte juste le bon préfixe est compté comme un lot de la roue');
        $this->assertSame(4.00, $jour['exposition_max'],
            'et son plafond de 999 € gonfle l\'exposition annoncée');
    }

    /**
     * UN POURCENTAGE SANS PLAFOND EST LE SEUL LOT RÉELLEMENT ILLIMITÉ — et il comptait pour 0 €.
     * `config/wheel.php` documente lui-même « 0 = illimité côté moteur de coupons ». Le chiffre censé
     * être « le pire cas, et le seul honnête » devenait donc muet précisément là où il compte.
     */
    public function test_un_pourcentage_SANS_plafond_est_signale_comme_illimite(): void
    {
        Config::set('wheel.segments', [[
            'key' => 'p', 'label' => '-15%', 'type' => 'coupon_percent', 'value' => 15,
            'weight' => 1, 'daily_cap' => 0, 'max_discount' => 0,
        ]]);

        $this->tour($this->branchId, '0611000925');

        $t = app(WheelReportService::class)->tableau($this->branchId);
        $jour = $t['periodes']['aujourdhui'];

        $this->assertSame(1, $jour['codes_sans_plafond'],
            'un pourcentage sans plafond doit être COMPTÉ à part : son exposition est inconnue, pas nulle');
        $this->assertMatchesRegularExpression('/sans plafond/iu', implode(' ', $t['avertissements']),
            'et l\'avertir, sinon un chiffre à 0 € rassure exactement là où il ne faut pas');
    }

    /**
     * LES POINTS NE SONT VALORISÉS NULLE PART. Codes de remise éteints, ils représentent la majorité
     * des lots — et le tableau qui sert à régler les plafonds affichait 0,00 €. Barème mesuré en base :
     * 100 points = 1 € de remise.
     */
    public function test_les_points_remis_sont_VALORISES_au_bareme(): void
    {
        Config::set('wheel.segments', [[
            'key' => 'pt', 'label' => '100 points', 'type' => 'points', 'value' => 100,
            'weight' => 1, 'daily_cap' => 0,
        ]]);

        $spin = $this->tour($this->branchId, '0611000926');
        \App\Models\User::factory()->create([
            'phone' => '0611000926', 'branch_id' => 0,
            'is_guest' => \App\Enums\Ask::YES, 'loyalty_points' => 0,
        ]);
        app(WheelDeliveryService::class)->deliver($spin->id, null, $this->branchId);

        $jour = app(WheelReportService::class)->tableau($this->branchId)['periodes']['aujourdhui'];

        $this->assertSame(100, $jour['points_remis']);
        $this->assertSame(1.00, $jour['valeur_points'],
            '100 points valent 1 € au barème de la maison : les afficher à 0 € cache la majorité du coût');
    }

    /**
     * DEUX HORLOGES, JAMAIS RÉCONCILIÉES. « tours » se mesurait sur la participation, « cadeaux
     * remis » et « valeur offerte » sur la ligne de sortie de stock, sans jointure. Un lot gagné le 1
     * et retiré le 20 comptait donc dans « Aujourd'hui » alors que son tour n'y figurait pas — et le
     * panneau affichait « 2 tours / 3 cadeaux remis », un écart que personne ne peut expliquer.
     */
    public function test_les_deux_horloges_sont_reconciliees_sur_le_TOUR(): void
    {
        $spin = $this->tour($this->branchId, '0611000927');

        // Le cadeau est retiré 20 jours plus tard : c'est le TOUR qui date le lot, pas le retrait.
        $this->travel(20)->days();
        app(WheelDeliveryService::class)->deliver($spin->id, null, $this->branchId);

        $t = app(WheelReportService::class)->tableau($this->branchId);

        $this->assertSame(0, $t['periodes']['aujourdhui']['tours']);
        $this->assertSame(0, $t['periodes']['aujourdhui']['cadeaux_remis'],
            'un cadeau retiré aujourd\'hui mais GAGNÉ il y a 20 jours est compté dans « aujourd\'hui » '
            . 'alors que son tour n\'y est pas : les deux colonnes ne parlent pas du même jour');
        $this->assertSame(1, $t['periodes']['mois']['tours']);
        $this->assertSame(1, $t['periodes']['mois']['cadeaux_remis']);
    }

    // ── 2. LES AVERTISSEMENTS ────────────────────────────────────────────────────────────────

    /**
     * Un lot sans produit de référence ne sera JAMAIS chiffré dans les charges. La commande de
     * réconciliation le dit déjà — mais personne ne la lance. Le dire là où le propriétaire passe.
     */
    public function test_un_lot_sans_produit_de_reference_est_SIGNALE(): void
    {
        Config::set('wheel.segments', [[
            'key' => 'g', 'label' => 'Boisson offerte', 'type' => 'free_item', 'value' => 0,
            'weight' => 1, 'daily_cap' => 0, 'cost_item_id' => 0,
        ]]);

        $avert = app(WheelReportService::class)->tableau($this->branchId)['avertissements'];

        $this->assertNotEmpty($avert);
        $this->assertMatchesRegularExpression('/jamais chiffr/iu', implode(' ', $avert));
        $this->assertMatchesRegularExpression('/Boisson offerte/u', implode(' ', $avert),
            'l\'avertissement doit NOMMER le lot en cause, sinon il est inactionnable');
    }

    /** Un cadeau parti sans décrément de stock est un écart d'inventaire : il doit se voir. */
    public function test_un_cadeau_sans_decrement_de_stock_est_SIGNALE(): void
    {
        $composite = (int) Item::factory()->create(['price' => 9.90])->id;  // aucun StockLevel
        Config::set('wheel.segments', [[
            'key' => 'g', 'label' => 'Menu offert', 'type' => 'free_item', 'value' => 0,
            'weight' => 1, 'daily_cap' => 0, 'cost_item_id' => $composite,
        ]]);

        $spin = $this->tour($this->branchId, '0611000910');
        app(WheelDeliveryService::class)->deliver($spin->id, null, $this->branchId);

        $t = app(WheelReportService::class)->tableau($this->branchId);

        $this->assertSame(1, $t['periodes']['aujourdhui']['cadeaux_sans_stock']);

        $avert = implode(' ', $t['avertissements']);
        $this->assertMatchesRegularExpression('/sans sortir du stock/iu', $avert);
        // « À corriger à l'inventaire » laissait croire que l'avertissement s'éteindrait. Il ne peut
        // PAS : `stock_outflows` est immuable par déclencheur. Un signal qu'on croit cassé est un
        // signal qu'on cesse de lire, donc il doit se présenter comme un HISTORIQUE.
        $this->assertMatchesRegularExpression('/historique/iu', $avert,
            'le relevé doit se dire historique, sinon on le prend pour une tâche qui ne se coche jamais');
        $this->assertMatchesRegularExpression('/inventaire/iu', $avert,
            'et dire ce qu\'il faut faire : reprendre l\'écart au prochain inventaire');
    }

    /**
     * L'INTERRUPTEUR LE PLUS COÛTEUX DU LOT. Éteint, il retire de la roue tous les lots en
     * pourcentage — et l'exploitant n'a aucune raison de deviner qu'un réglage de la CAISSE décide de
     * ce que sa roue peut offrir. On le lui dit, avec la part de roue concernée et le nom du réglage.
     */
    public function test_les_codes_eteints_sont_SIGNALES(): void
    {
        Config::set('pos.coupon_codes_enabled', false);
        Config::set('pos.manual_discount_enabled', false);
        Config::set('wheel.segments', [
            ['key' => 'g', 'label' => 'Boisson offerte', 'type' => 'free_item', 'value' => 0,
             'weight' => 6, 'daily_cap' => 0, 'cost_item_id' => $this->itemId],
            ['key' => 'p', 'label' => '-10%', 'type' => 'coupon_percent', 'value' => 10,
             'weight' => 4, 'daily_cap' => 0, 'max_discount' => 4],
        ]);

        $avert = implode(' ', app(WheelReportService::class)->tableau($this->branchId)['avertissements']);

        $this->assertMatchesRegularExpression('/ÉTEINTS/u', $avert);
        $this->assertMatchesRegularExpression('/40 %/u', $avert,
            'la part de roue concernée doit être chiffrée : « des lots » ne dit pas l\'ampleur');
        $this->assertMatchesRegularExpression('/POS_COUPON_CODES_ENABLED/u', $avert,
            'le nom du réglage doit être écrit, sinon l\'avertissement est inactionnable');
    }

    /** Et rallumés, plus d'avertissement : un signal permanent devient un signal ignoré. */
    public function test_codes_rallumes_l_avertissement_disparait(): void
    {
        Config::set('pos.coupon_codes_enabled', true);
        Config::set('wheel.segments', [
            ['key' => 'p', 'label' => '-10%', 'type' => 'coupon_percent', 'value' => 10,
             'weight' => 4, 'daily_cap' => 0, 'max_discount' => 4],
        ]);

        $avert = implode(' ', app(WheelReportService::class)->tableau($this->branchId)['avertissements']);

        $this->assertDoesNotMatchRegularExpression('/ÉTEINTS/u', $avert);
    }

    // ── 3. LA PORTE ──────────────────────────────────────────────────────────────────────────

    /**
     * LES CHIFFRES DU RESTAURANT NE S'AFFICHENT PAS SUR L'ÉCRAN DE CODE. Quelqu'un qui n'est pas
     * entré n'a pas à lire ce que la maison a donné aujourd'hui.
     */
    public function test_les_chiffres_ne_sont_pas_affiches_avant_d_avoir_ouvert_la_porte(): void
    {
        $this->tour($this->branchId, '0611000911');

        $ferme = $this->withHeaders(['Accept' => 'text/html'])->get('/admin/roue')->assertOk();
        $ferme->assertDontSee('Ce que la roue a donné', false);
        $ferme->assertDontSee('valeur offerte', false);

        $this->withHeaders(['Accept' => 'text/html'])
            ->post('/admin/roue/ouvrir', ['pin' => '481526']);
        $this->assertNotNull(session(EnsureWheelAccess::SESSION_KEY));

        $ouvert = $this->withHeaders(['Accept' => 'text/html'])->get('/admin/roue')->assertOk();
        $ouvert->assertSee('Ce que la roue a donné', false);
        $ouvert->assertSee('valeur offerte', false);
    }

    /**
     * ET ON NE CALCULE MÊME PAS LE BILAN PORTE FERMÉE.
     *
     * La fuite est déjà structurellement impossible — le panneau vit dans la branche « porte
     * ouverte » du gabarit — donc la garde du contrôleur est de la défense en profondeur, et une
     * mutation passerait inaperçue. Son vrai rôle est ailleurs : ne pas faire tourner quatre requêtes
     * d'agrégation à chaque affichage de l'écran de code, qui est la page la plus vue du lot.
     * On l'assertit donc pour ce qu'elle fait : le service n'est pas appelé du tout.
     */
    public function test_porte_fermee_le_bilan_n_est_meme_pas_CALCULE(): void
    {
        $this->app->bind(WheelReportService::class, function () {
            throw new \RuntimeException('le bilan a été calculé alors que la porte est fermée');
        });

        $this->withHeaders(['Accept' => 'text/html'])->get('/admin/roue')->assertOk();
    }
}
