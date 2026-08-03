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
        $this->assertSame('BUR', $this->code('Menu Enfant Burger'));
        $this->assertSame('CAY', $this->code('Cayenne'));
    }
}
