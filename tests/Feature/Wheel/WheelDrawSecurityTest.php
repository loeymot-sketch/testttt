<?php

namespace Tests\Feature\Wheel;

use App\Models\Branch;
use App\Models\Coupon;
use App\Models\Scopes\BranchScope;
use App\Models\WheelSpin;
use App\Services\Wheel\WheelException;
use App\Services\Wheel\WheelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * ROUE — les propriétés de sécurité, sans lesquelles le jeu est un robinet à cadeaux.
 *
 * Un jeu à lots est une cible : le gain est immédiat, mesurable, et l'attaquant n'a rien à perdre.
 * Ce qui est verrouillé ici, par ordre de gravité si ça cède :
 *
 *   1. le navigateur ne connaît NI les poids NI les plafonds — les publier, c'est publier la
 *      probabilité de chaque case, et surtout laisser croire qu'elles se négocient côté client ;
 *   2. aucune valeur de lot ne peut venir de la requête : tout est lu dans la configuration ;
 *   3. un tour par téléphone, garanti par une CONTRAINTE D'UNICITÉ en base et non par un `if` —
 *      deux requêtes simultanées passent un `if` toutes les deux ;
 *   4. les plafonds journaliers tiennent, par lot et au total : `max_uses_global` protège un CODE,
 *      pas un BUDGET ;
 *   5. un déverrouillage non autorisé (le mode déclaratif, celui qu'on peut se donner soi-même)
 *      est refusé ;
 *   6. le lot matérialisé est un coupon NOMINATIF À USAGE UNIQUE — pas un code qui circule.
 */
class WheelDrawSecurityTest extends TestCase
{
    use RefreshDatabase;

    private WheelService $roue;
    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        $this->roue = app(WheelService::class);
        $this->branchId = Branch::factory()->create()->id;

        Config::set('wheel.campaign_key', 'test-campagne');
        // [ROUE × CAISSE 2026-08-10] La roue ne tire plus de lot en REMISE quand la caisse
        // refuse les codes. Ce banc parle d'autre chose : on accepte donc les codes ici, pour
        // qu'un interrupteur de la caisse ne décide pas de ce qu'il éprouve.
        Config::set('pos.coupon_codes_enabled', true);
        Config::set('wheel.daily_total_cap', 500);
        Config::set('wheel.unlock_methods', ['staff' => true, 'order' => true, 'declaratif' => false]);
    }

    private function segments(array $s): void
    {
        Config::set('wheel.segments', $s);
    }

    private function tourner(string $tel, array $unlock = ['method' => 'staff']): WheelSpin
    {
        return $this->roue->spin($this->branchId, $tel, 'Dorian', $unlock);
    }

    // ── 1. CE QUE LE NAVIGATEUR A LE DROIT DE SAVOIR ─────────────────────────────────────────

    public function test_les_segments_publics_n_exposent_NI_poids_NI_plafonds(): void
    {
        $this->segments([
            ['key' => 'a', 'label' => '-10%', 'type' => 'coupon_percent', 'value' => 10, 'weight' => 90, 'daily_cap' => 0],
            ['key' => 'b', 'label' => 'Menu offert', 'type' => 'free_item', 'value' => 0, 'weight' => 1, 'daily_cap' => 2],
        ]);

        $publics = $this->roue->publicSegments();

        $this->assertCount(2, $publics, 'la roue doit rester dessinable');
        foreach ($publics as $s) {
            $this->assertSame(['key', 'label'], array_keys($s),
                'un segment public expose autre chose que sa clé et son libellé : publier le poids, '
                . "c'est publier la probabilité de chaque case — " . json_encode($s));
        }

        $brut = json_encode($publics);
        $this->assertStringNotContainsString('weight', $brut);
        $this->assertStringNotContainsString('daily_cap', $brut);
        $this->assertStringNotContainsString('90', $brut, 'le poids fuit dans une valeur');
    }

    /**
     * GARDE STRUCTURELLE : il ne doit exister AUCUN moyen de passer un lot depuis l'extérieur.
     * Une assertion sur la signature vaut mieux qu'une assertion sur un comportement : elle
     * échoue au moment où quelqu'un ajoute le paramètre, pas des mois plus tard.
     */
    public function test_aucun_parametre_ne_permet_d_imposer_le_lot(): void
    {
        $params = array_map(
            fn ($p) => $p->getName(),
            (new \ReflectionMethod(WheelService::class, 'spin'))->getParameters()
        );

        foreach (['prize', 'segment', 'lot', 'prizeKey', 'result'] as $interdit) {
            $this->assertNotContains($interdit, $params,
                "`spin()` accepte « $interdit » : le client pourrait choisir son lot");
        }
    }

    // ── 2. UN SEUL TOUR PAR PERSONNE ─────────────────────────────────────────────────────────

    public function test_un_seul_tour_par_telephone(): void
    {
        $this->segments([['key' => 'a', 'label' => '-10%', 'type' => 'coupon_percent', 'value' => 10, 'weight' => 1, 'daily_cap' => 0]]);

        $this->tourner('0612345678');

        $this->expectException(WheelException::class);
        $this->tourner('0612345678');
    }

    /** Les trois écritures d'un même numéro sont UNE personne — sinon l'unicité ne protège rien. */
    public function test_le_meme_numero_ecrit_autrement_ne_rejoue_pas(): void
    {
        $this->segments([['key' => 'a', 'label' => '-10%', 'type' => 'coupon_percent', 'value' => 10, 'weight' => 1, 'daily_cap' => 0]]);

        $this->tourner('0612345678');

        foreach (['06 12 34 56 78', '+33612345678', '06.12.34.56.78'] as $variante) {
            try {
                $this->tourner($variante);
                $this->fail("« $variante » a rejoué : la normalisation du numéro ne tient pas");
            } catch (WheelException $e) {
                $this->assertSame(409, $e->status());
            }
        }
    }

    /** La garde vit en BASE : on le prouve en contournant le service et en insérant en double. */
    public function test_l_unicite_est_garantie_par_la_BASE_et_pas_par_un_if(): void
    {
        $this->segments([['key' => 'a', 'label' => '-10%', 'type' => 'coupon_percent', 'value' => 10, 'weight' => 1, 'daily_cap' => 0]]);
        $premier = $this->tourner('0612345678');

        $this->expectException(\Illuminate\Database\QueryException::class);

        // Insertion DIRECTE, sans passer par le service : seule une contrainte d'unicité peut
        // encore refuser. Si ce test passe sans exception, c'est que la garde n'existe qu'en PHP —
        // et deux requêtes simultanées la franchiraient toutes les deux.
        $doublon = $premier->replicate();
        $doublon->save();
    }

    // ── 3. LES PLAFONDS ──────────────────────────────────────────────────────────────────────

    public function test_un_lot_au_plafond_du_jour_n_est_PLUS_jamais_tire(): void
    {
        // Le gros lot est plafonné à 1/jour, le petit est illimité. Après le premier tour, le gros
        // ne doit plus JAMAIS sortir aujourd'hui — même avec un poids écrasant.
        $this->segments([
            ['key' => 'gros', 'label' => 'Menu offert', 'type' => 'free_item', 'value' => 0, 'weight' => 10000, 'daily_cap' => 1],
            ['key' => 'petit', 'label' => '50 points', 'type' => 'points', 'value' => 50, 'weight' => 1, 'daily_cap' => 0],
        ]);

        $premier = $this->tourner('0600000001');
        $this->assertSame('gros', $premier->prize_key, 'poids 10000 contre 1 : le gros lot doit sortir en premier');

        for ($i = 2; $i <= 12; $i++) {
            $s = $this->tourner('06000000' . str_pad((string) $i, 2, '0', STR_PAD_LEFT));
            $this->assertSame('petit', $s->prize_key,
                'le gros lot est ressorti alors que son plafond du jour était atteint — tour ' . $i);
        }

        $this->assertSame(1, WheelSpin::withoutGlobalScope(BranchScope::class)->where('prize_key', 'gros')->count());
    }

    public function test_le_plafond_journalier_GLOBAL_ferme_la_roue_honnetement(): void
    {
        $this->segments([['key' => 'a', 'label' => '-10%', 'type' => 'coupon_percent', 'value' => 10, 'weight' => 1, 'daily_cap' => 0]]);
        Config::set('wheel.daily_total_cap', 2);

        $this->tourner('0600000001');
        $this->tourner('0600000002');

        try {
            $this->tourner('0600000003');
            $this->fail('le plafond global n\'a pas fermé la roue');
        } catch (WheelException $e) {
            $this->assertSame(429, $e->status());
            $this->assertStringContainsString('demain', $e->getMessage(),
                'le refus doit dire quoi faire — un refus incompris est vécu comme une panne');
        }
    }

    /** Tous les lots épuisés : on le DIT, on ne donne pas « rien » en silence. */
    public function test_tous_les_lots_epuises_donne_un_refus_explicite(): void
    {
        $this->segments([['key' => 'a', 'label' => '-10%', 'type' => 'coupon_percent', 'value' => 10, 'weight' => 5, 'daily_cap' => 1]]);

        $this->tourner('0600000001');

        try {
            $this->tourner('0600000002');
            $this->fail('un tour a été accordé alors qu\'aucun lot n\'était disponible');
        } catch (WheelException $e) {
            $this->assertSame(429, $e->status());
        }
    }

    // ── 4. LE DÉVERROUILLAGE ─────────────────────────────────────────────────────────────────

    /**
     * LE MODE DÉCLARATIF EST REFUSÉ. Un bouton « j'ai mis mon avis » sur lequel on clique soi-même
     * ne vérifie rien et se rejoue à l'infini — c'est exactement ce que le propriétaire refuse.
     */
    public function test_un_deverrouillage_declaratif_est_REFUSE(): void
    {
        $this->segments([['key' => 'a', 'label' => '-10%', 'type' => 'coupon_percent', 'value' => 10, 'weight' => 1, 'daily_cap' => 0]]);

        try {
            $this->tourner('0612345678', ['method' => 'declaratif']);
            $this->fail('un tour auto-déclaré a été accordé');
        } catch (WheelException $e) {
            $this->assertSame(403, $e->status());
        }
    }

    public function test_un_deverrouillage_inconnu_est_refuse(): void
    {
        $this->segments([['key' => 'a', 'label' => '-10%', 'type' => 'coupon_percent', 'value' => 10, 'weight' => 1, 'daily_cap' => 0]]);

        $this->expectException(WheelException::class);
        $this->tourner('0612345678', ['method' => 'bidon']);
    }

    // ── 5. LE LOT MATÉRIALISÉ ────────────────────────────────────────────────────────────────

    public function test_une_remise_devient_un_coupon_NOMINATIF_a_usage_unique(): void
    {
        $this->segments([['key' => 'a', 'label' => '-15%', 'type' => 'coupon_percent', 'value' => 15, 'weight' => 1, 'daily_cap' => 0]]);

        $spin = $this->tourner('0612345678');

        $this->assertNotNull($spin->coupon_id, 'aucun coupon créé : le client repart avec une promesse vide');
        $coupon = Coupon::withoutGlobalScopes()->find($spin->coupon_id);

        $this->assertSame(1, (int) $coupon->max_uses_global,
            'le code est réutilisable : il circulera sur les réseaux dans la journée');
        $this->assertSame(1, (int) $coupon->limit_per_user);
        $this->assertSame(15.0, (float) $coupon->discount);
        $this->assertNotNull($coupon->end_date, 'un lot sans date se remet à plus tard, et plus tard ne revient jamais');
    }

    public function test_un_lot_en_points_n_emet_PAS_de_coupon(): void
    {
        $this->segments([['key' => 'p', 'label' => '100 points', 'type' => 'points', 'value' => 100, 'weight' => 1, 'daily_cap' => 0]]);

        $spin = $this->tourner('0612345678');

        $this->assertSame(100, (int) $spin->points_awarded);
        $this->assertNull($spin->coupon_id, 'des points ne doivent pas créer un coupon fantôme en plus');
    }

    /** Le lot est FIGÉ : changer la configuration après coup ne réécrit pas ce qui a été promis. */
    public function test_le_lot_promis_est_fige_et_survit_a_un_changement_de_configuration(): void
    {
        $this->segments([['key' => 'a', 'label' => '-15%', 'type' => 'coupon_percent', 'value' => 15, 'weight' => 1, 'daily_cap' => 0]]);
        $spin = $this->tourner('0612345678');

        $this->segments([['key' => 'a', 'label' => '-5%', 'type' => 'coupon_percent', 'value' => 5, 'weight' => 1, 'daily_cap' => 0]]);

        $relu = WheelSpin::withoutGlobalScope(BranchScope::class)->find($spin->id);
        $this->assertSame('-15%', $relu->prize_label,
            'le lot promis a changé sous les pieds du client : on doit toujours pouvoir dire ce qui '
            . 'lui a été annoncé ce jour-là');
        $this->assertSame(15.0, (float) $relu->prize_value);
    }

    /**
     * PROPRIÉTÉ NON OBSERVABLE À L'EXÉCUTION, donc vérifiée sur le SOURCE. La suite de `mt_rand`
     * se reconstitue à partir de quelques tirages observés : sur un jeu à lots, quelqu'un qui note
     * ses résultats peut prédire quand tombe le gros lot. `random_int` s'appuie sur le générateur
     * cryptographique du système et n'a pas cette faiblesse. Aucun test comportemental ne peut
     * distinguer les deux — d'où cette sentinelle, qui est le seul garde possible.
     */
    public function test_le_tirage_utilise_un_generateur_CRYPTOGRAPHIQUE(): void
    {
        // On scanne le CODE, pas les commentaires : la première version de cette sentinelle se
        // déclenchait sur son propre docbloc (« pas `rand()`/`mt_rand()` »), c'est-à-dire sur la
        // phrase qui explique justement qu'on ne les utilise pas. Un détecteur dont le motif est
        // écrit en clair se trouve lui-même — piège déjà rencontré sur ce projet.
        $brut = file_get_contents(app_path('Services/Wheel/WheelService.php'));
        $src = '';
        foreach (token_get_all($brut) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $src .= is_array($t) ? $t[1] : $t;
        }

        $this->assertStringContainsString('random_int(', $src,
            'le tirage n\'utilise plus `random_int` : sur un jeu à lots, un générateur prédictible '
            . 'laisse deviner quand tombe le gros lot');
        $this->assertDoesNotMatchRegularExpression('/\b(mt_rand|rand|array_rand|shuffle)\s*\(/', $src,
            'un générateur prédictible est apparu dans le tirage');
    }

    // ── 6. LA PORTE PUBLIQUE ─────────────────────────────────────────────────────────────────

    public function test_la_roue_est_FERMEE_au_public_par_defaut(): void
    {
        Config::set('wheel.enabled', false);
        $this->assertFalse($this->roue->isOpenToPublic(),
            'la roue serait ouverte au public sans validation du propriétaire');
    }
}
