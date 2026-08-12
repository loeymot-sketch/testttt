<?php

namespace Tests\Feature\Wheel;

use App\Models\Branch;
use App\Models\Scopes\BranchScope;
use App\Models\WheelSpin;
use App\Services\Wheel\WheelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * LE PROPRIÉTAIRE RÈGLE SES CADEAUX LUI-MÊME — probabilité et nombre.
 *
 * ── SA DEMANDE ───────────────────────────────────────────────────────────────────────────────
 * « Je veux permettre de faire la probabilité et le nombre de cadeaux que je veux faire gagner aux
 *   gens : 50 tiramisu, 50 boissons, 10 sandwiches, 10 burgers pour le mois ; plus de probabilité
 *   sur les boissons aujourd'hui. Et un Terminator qu'on affiche mais que personne ne gagne, parce
 *   que ça ferait mal à notre production — la probabilité, ça va être zéro. »
 *
 * ── LES TROIS CHOSES QUI DOIVENT TENIR ENSEMBLE ──────────────────────────────────────────────
 * 1. Ce qu'il règle PRIME sur le fichier, partout — tirage, affichage, tableau de bord. Une surface
 *    qui ignore ses réglages, c'est une roue qui montre des chances qu'elle n'applique pas.
 * 2. Probabilité 0 = AFFICHÉ, JAMAIS TIRÉ. C'est la seule exception à « on ne montre que ce qu'on
 *    donne » : le lot existe, il peut être activé d'un curseur. Mais l'animation de la vitrine ne
 *    doit jamais s'arrêter dessus, sinon on ment dix fois par minute en salle.
 * 3. Quantité épuisée = le lot DISPARAÎT de la roue. Là, il n'y a plus rien à montrer.
 */
class WheelPrizeControlTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branche;

    private WheelService $roue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();

        Config::set('wheel.campaign_key', 'test-campagne');
        Config::set('wheel.enabled', true);
        // Une liste courte et explicite : on éprouve le MOTEUR, pas la carte du restaurant.
        Config::set('wheel.segments', [
            ['key' => 'boisson',    'label' => 'Boisson',    'type' => 'free_item', 'value' => 0, 'weight' => 30, 'daily_cap' => 0, 'quantity' => 0],
            ['key' => 'frites',     'label' => 'Frites',     'type' => 'free_item', 'value' => 0, 'weight' => 20, 'daily_cap' => 0, 'quantity' => 0],
            ['key' => 'terminator', 'label' => 'Terminator', 'type' => 'free_item', 'value' => 0, 'weight' => 0,  'daily_cap' => 0, 'quantity' => 0],
        ]);

        $this->branche = Branch::factory()->create();
        $this->roue = app(WheelService::class);
    }

    private function reglage(array $valeurs): void
    {
        Settings::group('wheel')->set($valeurs);
    }

    /** Une participation déjà partie, pour faire monter les compteurs. */
    private function tourJoue(string $prizeKey, ?string $campagne = null, ?string $quand = null): void
    {
        DB::table('wheel_spins')->insert([
            'branch_id' => $this->branche->id,
            'phone' => '06'.random_int(10000000, 99999999),
            'prize_key' => $prizeKey,
            'prize_label' => $prizeKey,
            'prize_type' => 'free_item',
            'prize_value' => 0,
            'campaign_key' => $campagne ?? 'test-campagne',
            'unlock_method' => 'review',
            'created_at' => $quand ?? now(),
            'updated_at' => $quand ?? now(),
        ]);
    }

    // ── CE QUE LE PROPRIÉTAIRE RÈGLE PRIME ───────────────────────────────────────────────────

    /** Sans rien régler, les valeurs du fichier tiennent : son écran n'impose rien par défaut. */
    public function test_sans_reglage_le_fichier_tient(): void
    {
        $lots = collect($this->roue->segments())->keyBy('key');

        $this->assertSame(30, (int) $lots['boisson']['weight']);
        $this->assertSame(20, (int) $lots['frites']['weight']);
        $this->assertSame(0, (int) $lots['terminator']['weight']);
    }

    /**
     * LE CŒUR. « Plus de probabilité sur les boissons aujourd'hui » : il pose 90, et c'est 90 qui
     * s'applique — au tirage comme partout ailleurs.
     */
    public function test_la_probabilite_reglee_par_le_proprietaire_prime_sur_le_fichier(): void
    {
        $this->reglage(['prize_boisson_weight' => 90, 'prize_frites_weight' => 5]);

        $lots = collect($this->roue->segments())->keyBy('key');

        $this->assertSame(90, (int) $lots['boisson']['weight']);
        $this->assertSame(5, (int) $lots['frites']['weight']);
    }

    /**
     * ET UN ZÉRO EST UNE DÉCISION, pas un champ vide. « Ne le fais plus gagner » doit s'appliquer —
     * c'est la même règle que pour les liens de réseaux, où jeter les valeurs vides rendait le retrait
     * d'un compte impossible.
     */
    public function test_une_probabilite_reglee_a_zero_est_respectee(): void
    {
        $this->reglage(['prize_frites_weight' => 0]);

        $lots = collect($this->roue->segments())->keyBy('key');
        $this->assertSame(0, (int) $lots['frites']['weight']);

        // Et le tirage ne la sort plus jamais : 200 tours, aucune frite.
        for ($i = 0; $i < 200; $i++) {
            $this->assertNotSame('frites', $this->tirerUneFois());
        }
    }

    /** Une valeur négative ne passe pas : elle fausserait le total du tirage. */
    public function test_une_valeur_negative_est_ramenee_a_zero(): void
    {
        $this->reglage(['prize_boisson_weight' => -50, 'prize_frites_quantity' => -3]);

        $lots = collect($this->roue->segments())->keyBy('key');
        $this->assertSame(0, (int) $lots['boisson']['weight']);
        $this->assertSame(0, (int) $lots['frites']['quantity']);
    }

    // ── LA VITRINE : AFFICHÉ, JAMAIS TIRÉ ────────────────────────────────────────────────────

    /**
     * LE TERMINATOR DU PROPRIÉTAIRE. Il est SUR la roue — c'est sa raison d'être — et il ne sort
     * jamais. 500 tirages pour le prouver, parce qu'un défaut de probabilité ne se voit pas sur dix.
     */
    public function test_un_lot_a_probabilite_nulle_est_affiche_mais_jamais_tire(): void
    {
        $libelles = array_column($this->roue->publicSegments($this->branche->id), 'label');
        $this->assertContains('Terminator', $libelles,
            'le lot vitrine a disparu de la roue : le propriétaire veut qu\'il soit VU');

        for ($i = 0; $i < 500; $i++) {
            $this->assertNotSame('terminator', $this->tirerUneFois(),
                'un lot à probabilité nulle a été gagné');
        }
    }

    /**
     * ET L'ANIMATION DE LA VITRINE NE S'ARRÊTE PAS DESSUS. Sans cette liste, la tablette désignait
     * gagnant n'importe quel secteur — 1 arrêt sur 7 sur le Terminator, toutes les dix secondes, en
     * salle. Rien n'est misé, mais le client qui le voit s'arrêter dix fois n'a pas tort de se sentir
     * mené en bateau.
     */
    public function test_l_animation_de_la_vitrine_ne_s_arrete_pas_sur_un_lot_vitrine(): void
    {
        $arretables = $this->roue->spinnableKeys($this->branche->id);

        $this->assertContains('boisson', $arretables);
        $this->assertContains('frites', $arretables);
        $this->assertNotContains('terminator', $arretables,
            'la vitrine peut s\'arrêter sur un lot que personne ne gagnera jamais');
    }

    // ── LA QUANTITÉ DU MOIS ──────────────────────────────────────────────────────────────────

    /**
     * « 10 burgers pour le mois. » Le dixième parti, le lot n'est plus tiré ET n'est plus montré :
     * là, contrairement à la vitrine, il n'y a plus rien à promettre.
     */
    public function test_un_lot_epuise_disparait_de_la_roue_et_du_tirage(): void
    {
        $this->reglage(['prize_frites_quantity' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $this->tourJoue('frites');
        }

        $libelles = array_column($this->roue->publicSegments($this->branche->id), 'label');
        $this->assertNotContains('Frites', $libelles, 'un lot épuisé reste affiché sur la roue');
        $this->assertNotContains('frites', $this->roue->spinnableKeys($this->branche->id));

        for ($i = 0; $i < 200; $i++) {
            $this->assertNotSame('frites', $this->tirerUneFois(), 'un lot épuisé a encore été donné');
        }
    }

    /** Tant qu'il en reste, il tourne : un seuil est une limite, pas une exclusion. */
    public function test_tant_qu_il_en_reste_le_lot_tourne(): void
    {
        $this->reglage(['prize_frites_quantity' => 3]);
        $this->tourJoue('frites');
        $this->tourJoue('frites');

        $this->assertContains('frites', $this->roue->spinnableKeys($this->branche->id));
    }

    /** Quantité 0 = illimité. C'est ce que dit l'écran, ce doit être ce que fait le moteur. */
    public function test_une_quantite_a_zero_est_illimitee(): void
    {
        $this->reglage(['prize_frites_quantity' => 0]);

        for ($i = 0; $i < 40; $i++) {
            $this->tourJoue('frites');
        }

        $this->assertContains('frites', $this->roue->spinnableKeys($this->branche->id));
    }

    /**
     * LA QUANTITÉ SE COMPTE SUR LA CAMPAGNE, PAS SUR TOUTE L'HISTOIRE. Changer de campagne est le
     * levier qui relance le jeu : si les compteurs ne repartaient pas, un lot épuisé le resterait à
     * jamais et le propriétaire ne comprendrait pas pourquoi son relancement ne change rien.
     */
    public function test_la_quantite_repart_a_la_campagne_suivante(): void
    {
        $this->reglage(['prize_frites_quantity' => 2]);
        $this->tourJoue('frites', 'campagne-precedente');
        $this->tourJoue('frites', 'campagne-precedente');

        $this->assertContains('frites', $this->roue->spinnableKeys($this->branche->id),
            'les participations d\'une AUTRE campagne comptent encore dans le quota');
    }

    /**
     * Et une autre caisse ne consomme pas le quota de celle-ci.
     *
     * ON REMPLIT LE QUOTA EN ENTIER chez la voisine — pas une seule participation. Avec un quota de 2
     * et un seul tour ailleurs, retirer le filtre de caisse donnerait 1 < 2 : le lot resterait
     * tirable, le test passerait, et l'isolation ne serait pas éprouvée du tout. Un test qui ne peut
     * pas échouer ne protège rien (mutation M7, d'abord survivante).
     */
    public function test_une_autre_caisse_ne_consomme_pas_le_quota(): void
    {
        $autre = Branch::factory()->create();
        $this->reglage(['prize_frites_quantity' => 2]);

        foreach (['0611111111', '0622222222'] as $tel) {
            DB::table('wheel_spins')->insert([
                'branch_id' => $autre->id, 'phone' => $tel, 'prize_key' => 'frites',
                'prize_label' => 'Frites', 'prize_type' => 'free_item', 'prize_value' => 0,
                'campaign_key' => 'test-campagne', 'unlock_method' => 'review',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->assertContains('frites', $this->roue->spinnableKeys($this->branche->id),
            'le quota de cette caisse a été consommé par une autre');
    }

    // ── LE PLAFOND DU JOUR, ENFIN VISIBLE ────────────────────────────────────────────────────

    /**
     * LE DÉFAUT RÉPARÉ À MOITIÉ EN AOÛT. Le plafond journalier était appliqué par le tirage SEUL :
     * un lot au plafond restait affiché sur la roue et ne pouvait plus être gagné. Même faute que
     * pour les lots en rupture, laissée derrière parce qu'« un compte n'est pas une propriété du
     * lot ». Un compte qui change dans la journée n'est pas une raison de montrer un lot impossible.
     */
    public function test_un_lot_au_plafond_du_jour_disparait_aussi_de_la_roue(): void
    {
        Config::set('wheel.segments', [
            ['key' => 'boisson', 'label' => 'Boisson', 'type' => 'free_item', 'value' => 0, 'weight' => 30, 'daily_cap' => 0, 'quantity' => 0],
            ['key' => 'frites',  'label' => 'Frites',  'type' => 'free_item', 'value' => 0, 'weight' => 20, 'daily_cap' => 2, 'quantity' => 0],
        ]);

        $this->tourJoue('frites');
        $this->tourJoue('frites');

        $libelles = array_column($this->roue->publicSegments($this->branche->id), 'label');
        $this->assertNotContains('Frites', $libelles,
            'un lot au plafond du jour reste affiché : la roue s\'arrête dessus sans jamais le donner');
        $this->assertNotContains('frites', $this->roue->spinnableKeys($this->branche->id));
    }

    /** Et demain il revient : un plafond journalier n'est pas un retrait définitif. */
    public function test_le_plafond_du_jour_ne_retire_pas_le_lot_pour_toujours(): void
    {
        Config::set('wheel.segments', [
            ['key' => 'boisson', 'label' => 'Boisson', 'type' => 'free_item', 'value' => 0, 'weight' => 30, 'daily_cap' => 0, 'quantity' => 0],
            ['key' => 'frites',  'label' => 'Frites',  'type' => 'free_item', 'value' => 0, 'weight' => 20, 'daily_cap' => 2, 'quantity' => 0],
        ]);

        $this->tourJoue('frites', null, now()->subDays(2)->toDateTimeString());
        $this->tourJoue('frites', null, now()->subDays(2)->toDateTimeString());

        $this->assertContains('frites', $this->roue->spinnableKeys($this->branche->id),
            'les tours d\'avant-hier bloquent encore le plafond du JOUR');
    }

    // ── LE FILET ─────────────────────────────────────────────────────────────────────────────

    /**
     * TOUT ÉPUISÉ : on ne dessine pas une roue vide. La page retomberait sur sa liste de secours —
     * des lots qui ne sont plus les vrais. On republie tout, et le serveur refuse le tour avec un
     * message honnête. Une roue pleine qu'on ne peut pas lancer vaut mieux qu'une roue qui ment.
     */
    public function test_si_tout_est_epuise_la_roue_reste_dessinee(): void
    {
        $this->reglage(['prize_boisson_quantity' => 1, 'prize_frites_quantity' => 1, 'prize_terminator_quantity' => 1]);
        $this->tourJoue('boisson');
        $this->tourJoue('frites');
        $this->tourJoue('terminator');

        $this->assertCount(3, $this->roue->publicSegments($this->branche->id));
        $this->assertSame([], $this->roue->spinnableKeys($this->branche->id));
    }

    // ── outil ────────────────────────────────────────────────────────────────────────────────

    /** Un tirage, sans écrire en base : on veut la clé choisie, pas une participation. */
    private function tirerUneFois(): string
    {
        $m = new \ReflectionMethod($this->roue, 'draw');
        $m->setAccessible(true);

        return (string) $m->invoke($this->roue, $this->branche->id)['key'];
    }
}
