<?php

namespace Tests\Feature\Kitchen;

use App\Services\Kitchen\MeatMaterialResolver;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * [CUISSON fail-loud 2026-08-07 · audit R1 P2] Une viande NON MAPPÉE (l'owner a ajouté une viande
 * au menu sans la câbler dans SYMBOLE_VERS_MATIERE) consommait ZÉRO stock EN SILENCE — surfacée
 * seulement dans `skipped[]`, jamais loggée. Résultat : food-cost faussé, fausse rupture, invisible.
 * On exige désormais un `Log::warning` : le trou de stock doit être VISIBLE, jamais silencieux.
 */
class MeatMaterialResolverFailLoudTest extends TestCase
{
    public function test_unmapped_meat_symbol_logs_a_warning_and_consumes_nothing(): void
    {
        Log::spy();

        // 'Mer' (Merguez) n'est pas dans SYMBOLE_VERS_MATIERE → chemin non-mappé (aucun accès DB).
        $r = app(MeatMaterialResolver::class)->toMaterialQuantities(['Mer' => 2.0], 1);

        $this->assertSame([], $r['totals'], 'Un symbole non mappé ne doit consommer AUCUNE matière.');
        $this->assertNotEmpty($r['skipped']);
        $this->assertSame('symbole_non_mappe', $r['skipped'][0]['reason']);

        Log::shouldHaveReceived('warning')->withArgs(function ($message): bool {
            return str_contains((string) $message, 'NON MAPPÉ');
        })->once();
    }
}
