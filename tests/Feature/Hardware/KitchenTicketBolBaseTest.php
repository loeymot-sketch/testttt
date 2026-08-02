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
        // invisible). Le code produit compact reste « BUR » ; le marqueur « ENF » le précède
        // pour que le cuisinier sache qu'il monte le menu enfant.
        $this->assertSame('ENF BUR', $this->code('Menu Enfant Burger'));
        $this->assertNotSame($this->code('Burger'), $this->code('Menu Enfant Burger'),
            'Un menu enfant ne doit JAMAIS rendre la même ligne que son produit adulte homonyme.');
    }
}
