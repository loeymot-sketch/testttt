<?php

namespace Tests\Feature\Hardware;

use App\Services\Hardware\KitchenTicketSymbolicFormatter;
use Tests\TestCase;

/**
 * [F-KITCHEN-BOL-BASE 2026-07-15 / P1] Le code produit cuisine réduisait « Bol Frites » ET
 * « Bol Riz » à « BOL » (1er mot significatif) → le cuisinier ne savait pas quelle base préparer
 * (plat faux). Ces bols n'ont AUCUNE variation « base » : le nom est le seul porteur. Le fix
 * concatène le 2e mot significatif pour un mot-base (« BOL FRI » / « BOL RIZ »). Parité stricte
 * avec le JS kdsSymbolic.js (ticket == écran).
 */
class KitchenTicketBolBaseTest extends TestCase
{
    private function code(string $produit): string
    {
        $f = new KitchenTicketSymbolicFormatter;
        $m = new \ReflectionMethod($f, 'produitCode');
        $m->setAccessible(true);
        return $m->invoke($f, $produit);
    }

    public function test_bol_bases_are_distinguished(): void
    {
        $this->assertSame('BOL FRI', $this->code('Bol Frites'));
        $this->assertSame('BOL RIZ', $this->code('Bol Riz'));
        $this->assertNotSame($this->code('Bol Frites'), $this->code('Bol Riz'),
            'Le cuisinier doit pouvoir distinguer la base du bol.');
    }

    public function test_non_bol_products_keep_their_compact_three_letter_code(): void
    {
        // Non-régression : les autres produits gardent leur code 3 lettres.
        $this->assertSame('TAC', $this->code('Tacos M'));
        $this->assertSame('COC', $this->code('Coca 33cl'));
        $this->assertSame('CAY', $this->code('Cayenne'));

        // [F-01 AUDIT CUISINIER 2026-08-01 · P0] Cette ligne attendait « BUR » tout court —
        // c'est-à-dire EXACTEMENT le même code que le « Burger » adulte. L'audit a prouvé la
        // collision en cuisine (deux lignes byte-identiques sur le ticket A0035, portion enfant
        // invisible).
        // [OWNER 2026-08-10] Le marqueur « ENF » ne suffisait pas : trop discret pour un
        // cuisinier en coup de feu. Un menu enfant s'écrit désormais EN TOUTES LETTRES. La
        // propriété protégée par ce test est INCHANGÉE — jamais la même ligne que l'adulte —
        // seule sa formulation devient explicite.
        $this->assertSame('MENU ENFANT BURGER', $this->code('Menu Enfant Burger'));
        $this->assertNotSame($this->code('Burger'), $this->code('Menu Enfant Burger'),
            'Un menu enfant ne doit JAMAIS rendre la même ligne que son produit adulte homonyme.');
    }

    /**
     * [OWNER 2026-08-10 · « la cuisine se trompe entre CHEESE et CHICKEN »]
     *
     * Le code 3 lettres rendait « CHE » pour Cheese Burger ET pour Cheddar, et « CHI » pour
     * Chicken Burger — une lettre d'écart, lue à deux mètres sur un écran, en plein service.
     * Ces familles s'écrivent maintenant en entier.
     */
    public function test_les_familles_confondues_sont_ecrites_en_toutes_lettres(): void
    {
        $this->assertSame('CHEESE BURGER', $this->code('Cheese Burger'));
        $this->assertSame('CHICKEN BURGER', $this->code('Chicken Burger'));
        $this->assertSame('DOUBLE CHEESE', $this->code('Double Cheese'));
        $this->assertSame('MENU ENFANT CHICKEN BURGER', $this->code('Menu Enfant Chicken Burger'));
        $this->assertSame('MENU ENFANT NUGGETS', $this->code('Menu Enfant Nuggets'));

        // Aucune de ces lignes ne doit pouvoir se confondre avec une autre.
        $rendus = array_map(fn (string $n): string => $this->code($n), [
            'Cheese Burger', 'Chicken Burger', 'Double Cheese', 'Cheddar',
            'Menu Enfant Chicken Burger', 'Menu Enfant Nuggets',
        ]);
        $this->assertSame($rendus, array_values(array_unique($rendus)),
            'Deux produits distincts rendent la même ligne : le cuisinier ne peut pas les distinguer.');
    }

    /**
     * [OWNER 2026-08-10] Même défaut, même remède, sur une famille trouvée au passage : trois
     * galettes ACTIVES du catalogue rendaient toutes « GAL », et rien d'autre sur la ligne ne
     * les distinguait.
     */
    public function test_les_galettes_sont_distinguees_comme_les_bols(): void
    {
        $this->assertSame('GAL CAY', $this->code('Galette Cayenne'));
        $this->assertSame('GAL NOR', $this->code('Galette Normale'));
        $this->assertSame('GAL POM', $this->code('Galette pommes de terre'));
    }
}
