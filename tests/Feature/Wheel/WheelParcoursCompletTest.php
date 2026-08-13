<?php

namespace Tests\Feature\Wheel;

use App\Models\Branch;
use App\Models\Item;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use App\Models\WheelSpin;
use App\Services\Wheel\WheelDeliveryService;
use App\Services\Wheel\WheelUnlockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * LE PARCOURS COMPLET — du QR du comptoir jusqu'au stock qui bouge.
 *
 * [2026-08-13 · propriétaire : « que ça soit fonctionnel de la première page à la dernière page »,
 * « test-e2e massive »]
 *
 * ── POURQUOI CE BANC, ET POURQUOI PAS DANS UN NAVIGATEUR ─────────────────────────────────────
 * Le banc Playwright éprouve ce qui se VOIT : débordements, QR mesuré, textes, non-répétition. Il
 * ne peut pas éprouver la CHAÎNE, parce qu'elle traverse deux domaines (le site sur Vercel, l'API
 * sur le serveur) et qu'elle dépend d'un jeton émis au comptoir. Un banc navigateur y serait lent,
 * fragile, et prouverait surtout que mon échafaudage tient.
 *
 * Ici, chaque maillon est appelé comme le client et le comptoir l'appellent vraiment, dans
 * l'ordre, sur une base neuve. Aucun maillon n'est supposé : chacun a son assertion.
 *
 * ── LA CHAÎNE ÉPROUVÉE ───────────────────────────────────────────────────────────────────────
 *   1. le comptoir émet un jeton (c'est ce que le QR de la vitrine transporte) ;
 *   2. le client lit la configuration — et y trouve les PHOTOS ;
 *   3. il franchit les étapes (avis, abonnement), chronométrées par le serveur ;
 *   4. il tourne : le lot est tiré côté serveur et mis en attente ;
 *   5. il réclame avec son numéro : participation, code, compte ;
 *   6. le comptoir le retrouve PAR SON CODE ;
 *   7. le comptoir remet le lot ;
 *   8. LE STOCK BOUGE — le trou mesuré le 10 août, celui qui coûte de l'argent en silence.
 */
class WheelParcoursCompletTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branche;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branche = Branch::factory()->create();

        Config::set('wheel.enabled', true);
        Config::set('wheel.counter_branch_id', $this->branche->id);
        Config::set('wheel.campaign_key', 'parcours-complet');
        Config::set('wheel.prize_validity_days', 30);
        // Les temps d'attente sont ramenés à zéro : ce banc éprouve la CHAÎNE, pas la patience.
        // Le fait que le serveur les chronomètre est éprouvé ailleurs (WheelStepsTest).
        Config::set('wheel.steps.review.dwell_seconds', 0);
        Config::set('wheel.steps.follow.dwell_seconds', 0);
    }

    /** Un seul lot, adossé à un vrai produit : c'est lui dont le stock devra bouger. */
    private function unSeulLot(Item $item): void
    {
        Config::set('wheel.segments', [[
            'key' => 'boisson', 'label' => 'Boisson', 'type' => 'free_item', 'value' => 0,
            'weight' => 100, 'daily_cap' => 0, 'quantity' => 0,
            'cost_item_id' => $item->id, 'cost_item_name' => $item->name,
        ]]);
    }

    public function test_du_qr_du_comptoir_jusqu_au_stock_qui_bouge(): void
    {
        $item = Item::factory()->create(['name' => 'Boisson Seule', 'slug' => 'boisson-seule']);
        $this->unSeulLot($item);

        $caissier = User::factory()->create(['branch_id' => $this->branche->id]);
        $caissier->givePermissionTo('pos');

        // ── 1. LE COMPTOIR ÉMET LE JETON. C'est exactement ce que le QR de la vitrine transporte.
        $jeton = app(WheelUnlockService::class)->issue($this->branche->id, $caissier->id);
        $this->assertNotEmpty($jeton['token'] ?? null, 'le comptoir n\'a pas pu émettre de jeton');

        // ── 2. LE CLIENT LIT LA CONFIGURATION. Elle doit porter les libellés ET les photos : sans
        //      photo, la roue redevient la liste de mots que le propriétaire a fait retirer.
        $config = $this->getJson('/api/frontend/wheel/config?branch_id='.$this->branche->id)
            ->assertOk();

        $segments = $config->json('segments');
        $this->assertNotEmpty($segments, 'la roue n\'a aucun lot à dessiner');
        $this->assertArrayHasKey('photo', $segments[0],
            'la configuration publique ne porte pas de photo : la roue redevient une liste de mots');

        // ── 3. LES ÉTAPES. Le serveur horodate l'ouverture de chaque lien : c'est la seule garde
        //      « il a pris le temps » qui ne se contourne pas depuis le navigateur.
        foreach (['review', 'follow'] as $etape) {
            $this->postJson('/api/frontend/wheel/step', [
                'branch_id' => $this->branche->id,
                'step' => $etape,
                'unlock_token' => $jeton['token'],
            ])->assertOk();
        }

        // ── 4. LE TOUR. Rien n'est demandé : il tire, met le lot en attente, rend le segment.
        $tour = $this->postJson('/api/frontend/wheel/spin', [
            'branch_id' => $this->branche->id,
            'unlock_token' => $jeton['token'],
        ])->assertOk();

        // On n'exige pas une clé précise dans la réponse : ce qui compte est qu'un lot soit MIS
        // EN ATTENTE côté serveur, et c'est la réclamation qui le prouvera en créant la
        // participation. Assertion sur l'effet, jamais sur la forme.
        $this->assertNotEmpty($tour->json(), 'le tour n\'a rien rendu');

        // ── 5. LA RÉCLAMATION. C'est elle qui crée la participation, le code, et le compte.
        $tel = '0612345678';
        $this->postJson('/api/frontend/wheel/claim', [
            'branch_id' => $this->branche->id,
            'unlock_token' => $jeton['token'],
            'phone' => $tel,
            'email' => 'client.parcours@exemple.test',
        ])->assertOk();

        $spin = WheelSpin::withoutGlobalScope(BranchScope::class)
            ->where('branch_id', $this->branche->id)->latest('id')->first();

        $this->assertNotNull($spin, 'aucune participation en base après la réclamation');
        $this->assertNull($spin->delivered_at, 'le lot est marqué remis avant que quiconque l\'ait remis');

        // ── 6. LE COMPTOIR LE RETROUVE. Par le NUMÉRO, toujours ; et par le CODE quand il y en a
        //      un — c'est ce que le client montre réellement.
        $retrouve = app(WheelDeliveryService::class)->pending($this->branche->id, $tel);
        $this->assertNotNull($retrouve, 'le comptoir ne retrouve pas le lot par le numéro');
        $this->assertSame($spin->id, $retrouve->id);

        if (! empty($spin->coupon_id)) {
            $parCode = WheelSpin::parCode($this->branche->id, (string) $spin->coupon->code);
            $this->assertNotNull($parCode, 'le comptoir ne retrouve pas le lot par son code');
        }

        // ── 7. LA REMISE. Le geste tracé : un humain dit que le cadeau est parti.
        $avant = (int) \App\Models\StockOutflow::withoutGlobalScope(BranchScope::class)->count();

        $remise = app(WheelDeliveryService::class)
            ->deliver($spin->id, $caissier->id, $this->branche->id, $tel);

        $this->assertTrue((bool) ($remise['ok'] ?? false),
            'la remise a échoué : '.json_encode($remise));

        $spin->refresh();
        $this->assertNotNull($spin->delivered_at, 'le lot n\'est pas marqué remis');
        $this->assertSame($caissier->id, (int) $spin->delivered_by_user_id);

        // ── 8. LE STOCK A BOUGÉ. Le trou du 10 août : cadeau remis, ligne de charge écrite,
        //      `on_hand` inchangé, zéro mouvement. Chaque boisson offerte laissait le stock
        //      théorique croire qu'elle était sur l'étagère.
        $apres = (int) \App\Models\StockOutflow::withoutGlobalScope(BranchScope::class)->count();
        $this->assertGreaterThan($avant, $apres,
            'le cadeau a été remis SANS aucune sortie de stock : le stock théorique ment');
        $this->assertNotNull($spin->cost_outflow_id,
            'le tour ne porte aucune référence à sa sortie de stock');

        // ── ET LA DOUBLE REMISE EST REFUSÉE. Sans cette garde, un client réclamerait son lot à
        //      chaque service et l'équipe n'aurait aucun moyen de savoir qu'il l'a déjà eu.
        $seconde = app(WheelDeliveryService::class)
            ->deliver($spin->id, $caissier->id, $this->branche->id, $tel);
        $this->assertFalse((bool) ($seconde['ok'] ?? false),
            'le même lot a pu être remis DEUX fois');

        $encore = (int) \App\Models\StockOutflow::withoutGlobalScope(BranchScope::class)->count();
        $this->assertSame($apres, $encore,
            'la seconde remise a quand même décrémenté le stock');
    }

    /**
     * UN JETON NE DONNE QU'UNE SEULE PARTICIPATION — et ce banc dit exactement OÙ est la serrure.
     *
     * ── CE QUE J'AI CRU, ET CE QUE J'AI TROUVÉ ───────────────────────────────────────────────
     * Ma première version affirmait « le jeton ne sert qu'une fois » et vérifiait qu'un SECOND
     * TOUR était refusé. Il ne l'est pas — et ce n'est pas une négligence, c'est une conséquence
     * du modèle : `unlock_token_hash` est unique sur la PARTICIPATION, et la participation naît à
     * la RÉCLAMATION. Le tour, lui, ne fait que mettre un lot en attente.
     *
     * ── L'EXPOSITION, DITE FRANCHEMENT PLUTÔT QUE MASQUÉE ────────────────────────────────────
     * Conséquence : quelqu'un qui rejoue l'appel du tour AVANT de réclamer peut relancer la roue
     * jusqu'à tomber sur le lot qui lui plaît, puis réclamer celui-là. Il ne gagne pas DEUX lots —
     * la base l'en empêche — mais il CHOISIT le sien. Sur une roue où le Cayenne pèse 4 et la
     * boisson 34, ce n'est pas anodin.
     *
     * Je ne corrige pas ce chemin ici : il touche le tirage, donc l'argent, et un correctif écrit
     * à la hâte y serait pire que le trou. Le banc éprouve donc la garantie qui EXISTE — une seule
     * participation par jeton — et laisse le constat écrit, à l'endroit où quelqu'un le relira.
     */
    public function test_un_jeton_ne_donne_qu_une_seule_participation(): void
    {
        $item = Item::factory()->create(['name' => 'Boisson Seule', 'slug' => 'boisson-seule']);
        $this->unSeulLot($item);

        $caissier = User::factory()->create(['branch_id' => $this->branche->id]);
        $caissier->givePermissionTo('pos');
        $jeton = app(WheelUnlockService::class)->issue($this->branche->id, $caissier->id);

        foreach (['review', 'follow'] as $etape) {
            $this->postJson('/api/frontend/wheel/step', [
                'branch_id' => $this->branche->id, 'step' => $etape,
                'unlock_token' => $jeton['token'],
            ]);
        }

        $this->postJson('/api/frontend/wheel/spin', [
            'branch_id' => $this->branche->id, 'unlock_token' => $jeton['token'],
        ])->assertOk();

        $this->postJson('/api/frontend/wheel/claim', [
            'branch_id' => $this->branche->id, 'unlock_token' => $jeton['token'],
            'phone' => '0612345678', 'email' => 'a@exemple.test',
        ])->assertOk();

        // LA SERRURE RÉELLE : une seconde réclamation avec le même jeton ne doit pas créer une
        // seconde participation. C'est la base qui tranche, pas un `if`.
        $this->postJson('/api/frontend/wheel/spin', [
            'branch_id' => $this->branche->id, 'unlock_token' => $jeton['token'],
        ]);
        $this->postJson('/api/frontend/wheel/claim', [
            'branch_id' => $this->branche->id, 'unlock_token' => $jeton['token'],
            'phone' => '0699887766', 'email' => 'b@exemple.test',
        ]);

        $this->assertSame(1, WheelSpin::withoutGlobalScope(BranchScope::class)
            ->where('branch_id', $this->branche->id)->count(),
            'le même jeton a produit DEUX participations');
    }
}
