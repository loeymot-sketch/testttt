<?php

namespace Tests\Feature\Kitchen;

use App\Services\Kitchen\MeatPortionCalculator;
use Tests\TestCase;

/**
 * [PARITÉ CUISSON PHP↔JS 2026-08-07] Verrou de non-dérive du JUMEAU.
 *
 * `MeatPortionCalculator` (PHP : ticket + stock) et `resources/js/helpers/kdsSymbolic.js` (écran KDS)
 * sont DEUX implémentations séparées, maintenues à la main. La campagne d'audit a montré que le
 * défaut #1 du projet est « un correctif appliqué à une moitié du mécanisme, pas à sa jumelle ».
 * Jusqu'ici la parité reposait sur des chaînes attendues DUPLIQUÉES dans deux suites disjointes —
 * une édition d'un seul moteur passait CI verte.
 *
 * Ce test et son jumeau JS (`tests/js/cuissonPhpJsParityFixture.spec.js`) consomment désormais LE
 * MÊME fichier golden (`tests/fixtures/cuisson/parity_cases.json`) : toute divergence écran↔ticket↔stock
 * fait rougir l'un des deux, et un changement de règle se fait en UN seul endroit.
 */
class CuissonPhpJsParityFixtureTest extends TestCase
{
    /**
     * @dataProvider casesFromSharedFixture
     *
     * @param  array<int,string>  $viandes
     * @param  array<int,array<string,mixed>>  $extras
     */
    public function test_php_engine_matches_shared_golden(string $desc, string $item, array $viandes, int $quantity, array $extras, string $instruction, string $expected): void
    {
        $moteur = new MeatPortionCalculator();
        $r = $moteur->forLine($item, $this->snap($viandes, $extras), $quantity, $instruction !== '' ? $instruction : null);

        $this->assertSame(
            $expected,
            $moteur->rendu($r['pieces'], $r['inconnu'] ? $quantity : 0),
            'Parité PHP↔JS rompue sur : ' . $desc
        );
    }

    /** @return array<int, array{0:string,1:string,2:array,3:int,4:array,5:string,6:string}> */
    public static function casesFromSharedFixture(): array
    {
        $json = json_decode((string) file_get_contents(__DIR__ . '/../../fixtures/cuisson/parity_cases.json'), true);

        return array_map(static fn (array $c): array => [
            (string) ($c['desc'] ?? $c['item']),
            (string) $c['item'],
            (array) ($c['viandes'] ?? []),
            (int) ($c['quantity'] ?? 1),
            (array) ($c['extras'] ?? []),
            (string) ($c['instruction'] ?? ''),
            (string) $c['expected'],
        ], (array) $json);
    }

    /**
     * Miroir EXACT de MeatPortionCalculatorTest::snap (forme canonique de production, clé `lines`) —
     * ET de kdsCuisson.spec.js::snap. Les 3 doivent produire la même charge utile.
     *
     * @param  array<int,string>  $viandes
     * @param  array<int,array<string,mixed>>  $extras
     * @return array<string,mixed>
     */
    private function snap(array $viandes, array $extras = []): array
    {
        $lines = [];
        foreach (array_values($viandes) as $i => $nom) {
            $lines[] = ['attribute_name' => 'Viande ' . ($i + 1), 'variation_name' => $nom];
        }
        $lines[] = ['attribute_name' => 'Sauce 1', 'variation_name' => 'Algérienne'];

        return ['lines' => $lines, 'extras' => $extras];
    }
}
