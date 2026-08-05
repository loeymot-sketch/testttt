<?php

namespace Tests\Feature\Hardware;

use App\Services\Hardware\KitchenTicketSymbolicFormatter;
use PHPUnit\Framework\TestCase;

/**
 * [GOAL-8AXES V2/V4 2026-08-05] Matrice de vérité anti-duplication du ticket cuisine.
 *
 * Trois duplications structurelles confirmées par l'ancrage du GOAL
 * (plans/GOAL_OWNER_8AXES_CUISINE_CAISSE_WEB_2026-08-05.md §6.0) :
 *
 *  D-1  La boisson de formule sort DEUX FOIS quand l'addon `menu_boisson` porte
 *       le VRAI nom de la boisson (« Coca 33cl ») ET que l'instruction contient
 *       la ligne formule « Formule : … (Coca 33cl) » : canal A = drinkLines()
 *       → "1 Coca 33cl", canal B = extractFormuleDrinkLines() → "BOISSON: Coca 33cl".
 *       Le dédoublonnage (cleanInstruction, W6-ADV C-P1-1) ne compare que les
 *       lignes BOISSON: entre elles — il ignore le canal addon.
 *
 *  D-2  « MENU » apparaît deux fois quand un SKU conteneur Menu/Formule séparé
 *       coexiste avec un produit portant des addons menu_* (Renderer:316-324 vs
 *       Formatter menuLine:469-471). G-9 : règle owner = DISTINGUER sans ambiguïté.
 *
 *  D-3  « Frites » produit standalone (FRI) + addon menu_frites (FRITES) dans la
 *       même commande — deux portions réelles, à distinguer, jamais fusionner.
 *
 * Ces tests écrivent le comportement ATTENDU (rouge d'abord, TDD).
 * Pure-unit : aucun accès DB (le formatter est sans état).
 */
class KitchenTicketNoDuplicateLabelTest extends TestCase
{
    private KitchenTicketSymbolicFormatter $fmt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fmt = new KitchenTicketSymbolicFormatter();
    }

    /**
     * D-1 — cas déclencheur exact : addon menu_boisson avec vrai nom de boisson
     * + instruction formule mentionnant la même boisson.
     * ATTENDU : la boisson n'apparaît qu'UNE fois sur l'ensemble
     * {drinkLines() ∪ cleanInstruction()}.
     */
    public function test_d1_formule_drink_not_duplicated_between_addon_and_instruction(): void
    {
        $snapshot = [
            'addons' => [
                ['role' => 'menu_frites', 'quantity' => 1, 'addon_name' => 'Frites'],
                ['role' => 'menu_boisson', 'quantity' => 1, 'addon_name' => 'Coca 33cl'],
            ],
        ];
        $instruction = "Formule : Menu (Frites + Boisson) (Coca 33cl)\nSans oignons";

        $drinks = $this->fmt->drinkLines($snapshot);
        $notes = $this->fmt->cleanInstruction($instruction, 'Tacos M', $drinks);

        // Canal A émet bien la boisson…
        $this->assertSame(['1 Coca 33cl'], $drinks, 'drinkLines doit émettre la vraie boisson');

        // …donc le canal B (notes) ne doit PAS la ré-émettre.
        $this->assertStringNotContainsStringIgnoringCase(
            'coca',
            $notes,
            "D-1: la boisson émise par drinkLines ne doit pas ressortir en note BOISSON:.\nNotes obtenues :\n{$notes}"
        );

        // La note libre du client survit.
        $this->assertStringContainsString('Sans oignons', $notes);
    }

    /**
     * D-1 (garde inverse) — si l'addon porte le nom du CONTENEUR (rejeté par
     * isDrinkItem), le canal instruction reste le SEUL porteur : la boisson doit
     * sortir en note, exactement une fois. Comportement CLUSTER-6 préservé.
     */
    public function test_d1_container_addon_keeps_instruction_channel(): void
    {
        $snapshot = [
            'addons' => [
                ['role' => 'menu_full', 'quantity' => 1, 'addon_name' => 'Menu (Frites + Boisson)'],
            ],
        ];
        $instruction = 'Formule : Menu (Frites + Boisson) (Oasis Tropical)';

        $this->assertSame([], $this->fmt->drinkLines($snapshot), 'le conteneur ne doit pas passer pour une boisson');

        $notes = $this->fmt->cleanInstruction($instruction, 'Galette Cayenne');
        $this->assertSame(1, substr_count(mb_strtolower($notes), 'oasis'), "La boisson doit sortir exactement une fois en note.\nNotes :\n{$notes}");
    }

    /**
     * D-1 (idempotence des formats) — même boisson via addon "2 Coca 33cl"
     * (quantité) et instruction : la normalisation doit rapprocher les deux
     * malgré la quantité en préfixe.
     */
    public function test_d1_dedupe_survives_quantity_prefix(): void
    {
        $snapshot = [
            'addons' => [
                ['role' => 'menu_boisson', 'quantity' => 2, 'addon_name' => 'Fanta 33cl'],
            ],
        ];
        $instruction = 'Formule : Menu XL (Fanta 33cl)';

        $drinks = $this->fmt->drinkLines($snapshot);
        $notes = $this->fmt->cleanInstruction($instruction, 'Big Tacos', $drinks);

        $this->assertSame(['2 Fanta 33cl'], $drinks);
        $this->assertStringNotContainsStringIgnoringCase('fanta', $notes,
            "D-1: quantité 2 côté addon ne doit pas faire ressortir la boisson en note.\nNotes :\n{$notes}");
    }
}
