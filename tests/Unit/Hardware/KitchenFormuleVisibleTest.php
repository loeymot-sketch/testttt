<?php

namespace Tests\Unit\Hardware;

use App\Services\Hardware\KitchenBundledAddonCollapser;
use App\Services\Hardware\KitchenTicketSymbolicFormatter;
use PHPUnit\Framework\TestCase;

/**
 * [OWNER 2026-08-19, 2ᵉ passe] « LES MENUS ON LES VOIT PLUS, LES BOISSONS ON LES VOIT PLUS »
 *
 * CE QUE LE REPLI A CASSÉ
 * -----------------------
 * Le repli du doublon de formule (826020f3c puis 5a3b85e0f) supprimait la ligne de formule
 * de l'affichage cuisine. Il supprimait AUSSI, sans le dire, deux choses qu'elle était la
 * seule à porter :
 *
 *   1. la NATURE de la formule. Le badge du produit parent vient de `menuLine()`, qui lit
 *      `composition_snapshot['addons']`. Or la caisse ne scelle AUCUN addon sur le parent —
 *      mesuré en base : `"addons": []` sur 100 % des lignes. Le mot « MENU » ne venait donc
 *      QUE de la ligne repliée. Depuis le repli, un menu complet s'affiche « FRITES » (repli
 *      de sauce), c'est-à-dire un MENSONGE : la cuisine ne sert plus la boisson.
 *   2. les consignes écrites uniquement sur la ligne de formule. Mesuré en base : 5 lignes
 *      de formule dont la « Sauce frites : … » n'existe que là (commande 5544 : Andalouse),
 *      et 17 parents qui revendiquent un menu sans porter la moindre consigne — leur badge
 *      tombait à VIDE : plus de menu, plus de boisson, RIEN.
 *
 * Le bandeau CUISSON, lui, n'a jamais été touché (il lit TOUTES les lignes, repli ou pas) —
 * ce que l'owner a confirmé de lui-même : « en cuisson on le trouve toujours, vu que c'est
 * calculé comme frites ».
 *
 * CE QUE CE TEST VERROUILLE
 * -------------------------
 * Un repli n'est pas une suppression : la ligne repliée LÈGUE au parent ce qu'elle portait,
 * et le parent affiche la vraie nature de la formule (MENU / FRITES / BOISSON) lue sur la
 * revendication « + <nom de la formule> » que le wizard écrit dans son instruction.
 *
 * Jumeau strict : tests/js/kdsFormuleVisible.spec.js — mêmes cas des deux côtés.
 */
class KitchenFormuleVisibleTest extends TestCase
{
    /** Commande 6598 — la sauce frites est chez le parent ET sur la ligne de formule. */
    private const PARENT_6598 = "CAYENNE\n"
        ."Pain Viandes : Poulet mariné - Salade, Tomate Sauce : Algérienne\n"
        ."+ Menu (Frites + Boisson) (+2,50 €)\n"
        ."↳ Sauce frites: Mayonnaise\n"
        .'BOISSON: Coca-Cola 33cl';

    /** Commande 5544 — le parent ne porte AUCUNE sauce frites : elle vit sur la formule. */
    private const PARENT_5544 = "CAYENNE\n"
        ."Pain - Salade, Tomate, Oignons cuits Sauce : Algérienne\n"
        ."+ Menu (Frites + Boisson) (+2,50 €)\n"
        ."BOISSON: Hawaï 33cl\n"
        .'[bien cuit svp]';

    private KitchenBundledAddonCollapser $collapser;

    private KitchenTicketSymbolicFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->collapser = new KitchenBundledAddonCollapser();
        $this->formatter = new KitchenTicketSymbolicFormatter();
    }

    private function ligne(string $name, string $instruction, int $quantity = 1, array $snapshot = []): object
    {
        $o = new \stdClass();
        $o->name = $name;
        $o->quantity = $quantity;
        $o->instruction = $instruction;
        $o->composition_snapshot = $snapshot;

        return $o;
    }

    /** Badge tel qu'il sera IMPRIMÉ pour la ligne, après repli. */
    private function badge(object $ligne): string
    {
        $snap = is_array($ligne->composition_snapshot ?? null) ? $ligne->composition_snapshot : [];

        return $this->formatter->menuBadge($snap, (string) $ligne->name, (string) $ligne->instruction);
    }

    public function test_commande_5544_la_sauce_frites_de_la_ligne_repliee_arrive_chez_le_parent(): void
    {
        $out = $this->collapser->collapse([
            $this->ligne('Cayenne', self::PARENT_5544),
            $this->ligne('Menu (Frites + Boisson)', "MENU\n↳ Sauce frites: Andalouse"),
        ]);

        $this->assertCount(1, $out, 'la ligne de formule reste repliée');
        $this->assertStringContainsString('Sauce frites: Andalouse', $out[0]->instruction);
    }

    public function test_commande_5544_le_badge_dit_MENU_la_ou_il_ne_disait_plus_RIEN(): void
    {
        $out = $this->collapser->collapse([
            $this->ligne('Cayenne', self::PARENT_5544),
            $this->ligne('Menu (Frites + Boisson)', "MENU\n↳ Sauce frites: Andalouse"),
        ]);

        // Avant correctif : '' — aucun badge, la cuisine ne préparait ni frites ni boisson.
        $this->assertSame('MENU : AND', $this->badge($out[0]));
    }

    public function test_commande_6598_le_badge_dit_MENU_et_non_FRITES(): void
    {
        $out = $this->collapser->collapse([
            $this->ligne('Cayenne', self::PARENT_6598),
            $this->ligne('Menu (Frites + Boisson)', 'Sauce frites: Mayonnaise'),
            $this->ligne('Coca-Cola 33cl', 'COCA-COLA 33CL'),
        ]);

        $this->assertCount(2, $out, 'la vraie 2ᵉ boisson commandée à part reste affichée');
        // Avant correctif : « FRITES : MAY » — un menu complet annoncé comme des frites seules.
        $this->assertSame('MENU : MAY', $this->badge($out[0]));
        $this->assertSame('Coca-Cola 33cl', $out[1]->name);
    }

    public function test_la_boisson_de_la_formule_reste_lisible_sur_le_bloc_du_parent(): void
    {
        $out = $this->collapser->collapse([
            $this->ligne('Cayenne', self::PARENT_5544),
            $this->ligne('Menu (Frites + Boisson)', "MENU\n↳ Sauce frites: Andalouse"),
        ]);

        $notes = array_values(array_filter(array_map(
            'trim',
            explode("\n", $this->formatter->cleanInstruction($out[0]->instruction, $out[0]->name, []))
        )));

        $this->assertContains('BOISSON: Hawaï 33cl', $notes);
    }

    public function test_pas_de_sauce_frites_en_double_quand_le_parent_la_porte_deja(): void
    {
        $out = $this->collapser->collapse([
            $this->ligne('Cayenne', self::PARENT_6598),
            $this->ligne('Menu (Frites + Boisson)', 'Sauce frites: Mayonnaise'),
        ]);

        $this->assertSame(
            1,
            preg_match_all('/sauce\s*frites\s*:/iu', $out[0]->instruction),
            'une seule ligne « Sauce frites : … » — la seconde ne serait que du bruit'
        );
    }

    public function test_la_note_libre_du_caissier_reste_la_derniere_ligne(): void
    {
        $out = $this->collapser->collapse([
            $this->ligne('Cayenne', self::PARENT_5544),
            $this->ligne('Menu (Frites + Boisson)', "MENU\n↳ Sauce frites: Andalouse"),
        ]);

        $lignes = explode("\n", $out[0]->instruction);
        $this->assertSame('[bien cuit svp]', trim(end($lignes)));
    }

    /**
     * @dataProvider formules
     */
    public function test_nature_de_la_formule_revendiquee(string $claim, string $attendu): void
    {
        $this->assertSame($attendu, $this->formatter->claimedFormuleBadge("CAYENNE\nPain\n".$claim));
    }

    public static function formules(): array
    {
        return [
            'menu complet nommé'  => ['+ Menu (Frites + Boisson) (+2,50 €)', 'MENU'],
            'menu complet legacy' => ['+ Menu complet', 'MENU'],
            'formule générique'   => ['+ Formule du midi (+3,00 €)', 'MENU'],
            'frites seules'       => ['+ Frites seules (+1,50 €)', 'FRITES'],
            'boisson seule'       => ['+ Boisson Seule (+1,90 €)', 'BOISSON'],
            'supplément payant'   => ['+ Cheddar (+0,90 €)', ''],
            'aucune revendication' => ['Sauce : Algérienne', ''],
        ];
    }

    public function test_une_note_libre_ne_fabrique_pas_de_badge_fantome(): void
    {
        // La note du caissier est un <textarea> : elle peut contenir une ligne « + Frites ».
        $instruction = "CAYENNE\nPain\n[+ Frites\nMerci]";

        $this->assertSame('', $this->formatter->claimedFormuleBadge($instruction));
    }

    public function test_le_canal_addon_scelle_garde_la_priorite_sur_la_revendication(): void
    {
        // Borne : la formule EST scellée dans le snapshot. C'est la source la plus fiable ;
        // la revendication n'est qu'un repli pour la caisse, qui ne scelle rien.
        $snapshot = ['addons' => [['role' => 'menu_frites', 'addon_name' => 'Frites seules']]];

        $this->assertSame(
            'FRITES',
            $this->formatter->menuBadge($snapshot, 'Cayenne', "+ Menu (Frites + Boisson) (+2,50 €)")
        );
    }

    public function test_une_formule_commandee_seule_n_est_ni_repliee_ni_deshabillee(): void
    {
        $seule = $this->ligne('Menu (Frites + Boisson)', "MENU\n↳ Sauce frites: Andalouse");

        $out = $this->collapser->collapse([$seule]);

        $this->assertCount(1, $out);
        $this->assertSame($seule, $out[0], 'aucune revendication : la liste est rendue telle quelle');
    }

    public function test_le_legs_ne_mute_jamais_la_ligne_source(): void
    {
        $parent = $this->ligne('Cayenne', self::PARENT_5544);
        $formule = $this->ligne('Menu (Frites + Boisson)', "MENU\n↳ Sauce frites: Andalouse");

        $out = $this->collapser->collapse([$parent, $formule]);

        $this->assertStringNotContainsString('Andalouse', $parent->instruction, 'la ligne comptable reste intacte');
        $this->assertNotSame($parent, $out[0], 'le legs est porté par un CLONE');
    }

    public function test_les_options_de_frites_de_la_formule_ne_disparaissent_plus(): void
    {
        // « Grande Portion » et « Cheddar Fondu » sont des gestes de CUISINE : elles ne
        // vivent que sur la ligne de formule, le repli les effaçait toutes les deux.
        $out = $this->collapser->collapse([
            $this->ligne('Cayenne', "CAYENNE\nPain Sauce : Algérienne\n+ Menu (Frites + Boisson) (+2,50 €)"),
            $this->ligne('Menu (Frites + Boisson)', "MENU\n↳ Grande Portion (+0,50 €)\n↳ Cheddar Fondu (+1,00 €)\n↳ Sauce frites: Ketchup, Mayonnaise"),
        ]);

        $this->assertSame('MENU : KTP MAY', $this->badge($out[0]));

        $notes = array_values(array_filter(array_map(
            'trim',
            explode("\n", $this->formatter->cleanInstruction($out[0]->instruction, $out[0]->name, []))
        )));
        $this->assertContains('↳ Grande Portion', $notes);
        $this->assertContains('↳ Cheddar Fondu', $notes);
    }

    public function test_deux_menus_deux_parents_chacun_recoit_sa_consigne(): void
    {
        $out = $this->collapser->collapse([
            $this->ligne('Cayenne', "CAYENNE\nPain\n+ Menu (Frites + Boisson) (+2,50 €)"),
            $this->ligne('Tacos', "TACOS\n+ Menu (Frites + Boisson) (+2,50 €)"),
            $this->ligne('Menu (Frites + Boisson)', 'Sauce frites: Andalouse'),
            $this->ligne('Menu (Frites + Boisson)', 'Sauce frites: Ketchup'),
        ]);

        $this->assertCount(2, $out);
        $this->assertSame('MENU : AND', $this->badge($out[0]));
        $this->assertSame('MENU : KTP', $this->badge($out[1]));
    }
}
