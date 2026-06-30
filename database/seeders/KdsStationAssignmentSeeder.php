<?php

namespace Database\Seeders;

use App\Models\Item;
use App\Models\ItemCategory;
use Illuminate\Database\Seeder;

/**
 * [SYNC-BORNE 2026-07-01] Assigne une STATION KDS à chaque article.
 *
 * Contexte : le rapport cowork a relevé `kds_station = null/'none'` sur TOUS les
 * articles. Le KDS affiche quand même tout en vue « Toutes les stations », mais le
 * FILTRE par poste (bar / cuisine chaude / cuisine froide) est inutilisable tant
 * qu'aucun article n'a de station réelle. Ce seeder résout ce trou de configuration.
 *
 * Enum valide (migration 2026_04_20_230000) : bar | cuisine_chaude | cuisine_froide | none.
 *
 * Mapping par NOM de catégorie (portable même si les IDs diffèrent sur le VPS) :
 *   - « boisson »            → bar
 *   - « dessert »            → cuisine_froide
 *   - interne/upsell/technique → none (modificateurs internes, pas un plat autonome)
 *   - tout le reste (sandwichs, galette, burgers, tacos, bols, frites, suppléments,
 *     menu enfant…)          → cuisine_chaude
 *
 * Idempotent : ré-exécutable sans effet de bord (réapplique le même mapping).
 */
class KdsStationAssignmentSeeder extends Seeder
{
    public function run(): void
    {
        $resolve = static function (string $catName): string {
            $n = mb_strtolower(trim($catName));
            if (str_contains($n, 'boisson') || str_contains($n, 'drink')) {
                return 'bar';
            }
            if (str_contains($n, 'dessert') || str_contains($n, 'glace')) {
                return 'cuisine_froide';
            }
            if (str_contains($n, 'technique') || str_contains($n, 'interne') || str_contains($n, 'upsell')) {
                return 'none';
            }

            return 'cuisine_chaude';
        };

        $counts = ['bar' => 0, 'cuisine_chaude' => 0, 'cuisine_froide' => 0, 'none' => 0];

        foreach (ItemCategory::all() as $cat) {
            $station = $resolve((string) ($cat->name ?? ''));
            $n = Item::where('item_category_id', $cat->id)->update(['kds_station' => $station]);
            $counts[$station] += $n;
        }

        // Articles sans catégorie → cuisine chaude (jamais orpheliner un plat chaud).
        $orphan = Item::whereNull('item_category_id')->update(['kds_station' => 'cuisine_chaude']);
        $counts['cuisine_chaude'] += $orphan;

        $msg = sprintf(
            'Stations KDS assignées — bar:%d, cuisine_chaude:%d, cuisine_froide:%d, none(interne):%d',
            $counts['bar'], $counts['cuisine_chaude'], $counts['cuisine_froide'], $counts['none']
        );
        $this->command?->info($msg);
    }
}
