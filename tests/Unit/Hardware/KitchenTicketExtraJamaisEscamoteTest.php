<?php

namespace Tests\Unit\Hardware;

use App\Services\Hardware\KitchenTicketSymbolicFormatter;
use PHPUnit\Framework\TestCase;

/**
 * [FIX-1 2026-08-25 · P0 cuisine] UN EXTRA FACTURÉ NE DISPARAÎT JAMAIS DU TICKET IMPRIMÉ.
 *
 * Jumeau PHP de tests/js/kdsExtraJamaisEscamote.spec.js. Le formateur sautait toute
 * entrée d'extra dépourvue de champ de nom (`if ($name === '') { continue; }`) —
 * exactement le même trou que l'écran V2, donc le même produit servi faux, cette
 * fois sur le papier que le cuisinier a en main.
 *
 * La forme concernée existe en base sur la colonne brute `item_extras`
 * (ex. ligne #5904 : [{"id":269,"quantity":1}, …]), servie dès que l'instantané
 * NF525 ne porte pas d'extras.
 *
 * Ce test vérifie une PRÉSENCE (la ligne sort), pas l'absence d'un symptôme.
 */
class KitchenTicketExtraJamaisEscamoteTest extends TestCase
{
    private KitchenTicketSymbolicFormatter $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = new KitchenTicketSymbolicFormatter;
    }

    public function test_un_extra_sans_aucun_nom_est_annonce_au_lieu_de_disparaitre(): void
    {
        $snap = ['extras' => [
            ['id' => 269, 'quantity' => 1, 'unit_price' => 0.9, 'line_total' => 0.9],
        ]];

        $this->assertSame(['+ Supplément'], $this->f->supplementLines($snap));
    }

    public function test_la_quantite_d_un_extra_anonyme_survit(): void
    {
        $snap = ['extras' => [
            ['extra_id' => 269, 'quantity' => 2, 'unit_price' => 0.9, 'line_total' => 1.8],
        ]];

        $this->assertSame(['+ Supplément ×2'], $this->f->supplementLines($snap));
    }

    public function test_un_extra_nomme_et_un_anonyme_coexistent(): void
    {
        $snap = ['extras' => [
            ['extra_id' => 53, 'extra_name' => 'Cheddar', 'quantity' => 1, 'unit_price' => 0.9, 'line_total' => 0.9],
            ['extra_id' => 269, 'quantity' => 1, 'unit_price' => 0.9, 'line_total' => 0.9],
        ]];

        $this->assertSame(['+ Cheddar', '+ Supplément'], $this->f->supplementLines($snap));
    }

    public function test_non_regression_une_garniture_gratuite_nommee_reste_repliee_en_ligne_1(): void
    {
        $snap = ['extras' => [
            ['extra_id' => 49, 'extra_name' => 'Salade', 'quantity' => 1, 'unit_price' => 0, 'line_total' => 0],
        ]];

        $this->assertSame([], $this->f->supplementLines($snap));
        $this->assertStringContainsString('S', $this->f->mainLine('Cayenne', $snap));
    }
}
