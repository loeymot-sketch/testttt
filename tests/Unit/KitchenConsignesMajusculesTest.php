<?php

namespace Tests\Unit;

use App\Services\Hardware\KitchenTicketSymbolicFormatter;
use Tests\TestCase;

/**
 * [CONSIGNES 2026-11-04 · décision propriétaire « Garder toute consigne »] Ticket cuisine : même table que l'écran
 * cuisine (tests/js/kdsConsignesMajuscules.spec.js) — les deux jumeaux décident pareil.
 */
class KitchenConsignesMajusculesTest extends TestCase
{
    public function test_table_commune_des_consignes_en_majuscules(): void
    {
        $table = json_decode(file_get_contents(base_path('tests/fixtures/consignes-majuscules-2026-11-04.json')), true);
        $f = app(KitchenTicketSymbolicFormatter::class);

        foreach ($table['cas'] as $c) {
            $this->assertSame(! $c['garde'], KitchenTicketSymbolicFormatter::estEchoDuProduit($c['ligne'], $c['produit']), "écho ? {$c['ligne']}");
            $sortie = $f->cleanInstruction($c['ligne'], $c['produit']);
            if ($c['garde']) {
                $this->assertStringContainsString($c['ligne'], $sortie, "« {$c['ligne']} » doit rester sur le ticket");
            } else {
                $this->assertSame('', $sortie, "« {$c['ligne']} » est l'écho du produit");
            }
        }
    }
}
