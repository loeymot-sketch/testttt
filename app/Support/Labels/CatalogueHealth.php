<?php

namespace App\Support\Labels;

use App\Models\DlcLabelProduct;
use Illuminate\Support\Collection;

/**
 * [CAYENNE HYGIÈNE 2026-10-17 · méga-fichier P0.3/P0.4/P0.6] Le catalogue est-il FIABLE ? Quatre contrôles, tous calculés
 * côté serveur (testables), qui alimentent l'écran « Contrôle du catalogue » :
 *
 *   · starting     — durées de DÉPART du catalogue jamais validées par le responsable (« À valider ») ;
 *   · allergens    — ni allergène coché ni « aucun des 14 » : la fiche n'affirme rien, il reste à la renseigner ;
 *   · no_category  — fiches sans famille (avec la famille PROBABLE, d'après le catalogue recommandé, jamais appliquée seule) ;
 *   · neighbours   — fiches voisines : même produit écrit deux fois (« Tomate » / « Tomates »), ou « Poivron » à 0/+3 °C face à
 *                    « Poivrons coupés » à 0/+4 °C — la conservation du même produit devrait être la même partout.
 *
 * Lecture seule : rien n'est corrigé ici. Un contrôle ne devine jamais à la place du responsable (allergènes, durées).
 */
final class CatalogueHealth
{
    /** Au plus ce nombre de fiches voisines remontées (l'écran n'est pas un tableau de bord de 200 lignes). */
    private const MAX_NEIGHBOURS = 40;

    /**
     * @param  Collection<int, DlcLabelProduct>  $products
     * @return array{starting:list<int>, allergens:list<int>, no_category:list<array{id:int,name:string,suggested:?string}>, neighbours:list<array<string,mixed>>}
     */
    public static function analyse(Collection $products): array
    {
        $active = $products->filter(fn (DlcLabelProduct $p) => $p->is_active)->values();
        $catalogueNames = array_flip(array_map(fn ($r) => mb_strtolower($r['name']), RecommendedDlcCatalog::all()));

        return [
            'starting' => $active->filter(fn (DlcLabelProduct $p) => $p->durationReview() === 'starting')->pluck('id')->map(fn ($i) => (int) $i)->values()->all(),
            'allergens' => $active->filter(fn (DlcLabelProduct $p) => ! $p->no_allergens && empty($p->allergens))->pluck('id')->map(fn ($i) => (int) $i)->values()->all(),
            'no_category' => $active->filter(fn (DlcLabelProduct $p) => trim((string) $p->category) === '')
                ->map(fn (DlcLabelProduct $p) => ['id' => (int) $p->id, 'name' => (string) $p->name, 'suggested' => self::suggestCategory((string) $p->name)])
                ->values()->all(),
            'neighbours' => self::neighbours($active, $catalogueNames),
        ];
    }

    /** « Oignons émincés » → « oignon emince » : sans accent ni majuscule, pluriels ramenés au singulier (mots de plus de 3 lettres). */
    public static function normalize(string $name): string
    {
        return implode(' ', self::tokens($name));
    }

    /** @return list<string> */
    private static function tokens(string $name): array
    {
        $s = mb_strtolower($name);
        $s = str_replace(['œ', 'æ'], ['oe', 'ae'], $s);
        $s = \Illuminate\Support\Str::ascii($s);
        $s = trim((string) preg_replace('/[^a-z0-9]+/', ' ', $s));
        if ($s === '') return [];

        return array_map(fn (string $w) => strlen($w) > 3 && ($w[strlen($w) - 1] === 's' || $w[strlen($w) - 1] === 'x') ? substr($w, 0, -1) : $w, explode(' ', $s));
    }

    /**
     * La famille PROBABLE d'une fiche sans famille : celle que le catalogue donne au même premier mot (« Tomate » → la famille de
     * « Tomates tranchées »). La plus fréquente l'emporte ; aucune correspondance → null (on ne devine pas).
     */
    public static function suggestCategory(string $name): ?string
    {
        $first = self::tokens($name)[0] ?? null;
        if ($first === null) return null;
        $counts = [];
        foreach (RecommendedDlcCatalog::all() as $row) {
            if ((self::tokens($row['name'])[0] ?? null) === $first) {
                $counts[$row['category']] = ($counts[$row['category']] ?? 0) + 1;
            }
        }
        if ($counts === []) return null;
        arsort($counts);

        return (string) array_key_first($counts);
    }

    /**
     * @param  Collection<int, DlcLabelProduct>  $active
     * @param  array<string,int>  $catalogueNames  noms du catalogue (minuscules) — deux fiches du catalogue entre elles sont cohérentes par construction
     * @return list<array<string,mixed>>
     */
    private static function neighbours(Collection $active, array $catalogueNames): array
    {
        $rows = $active->map(fn (DlcLabelProduct $p) => [
            'p' => $p,
            'tokens' => self::tokens((string) $p->name),
            'own' => ! isset($catalogueNames[mb_strtolower(trim((string) $p->name))]),
        ])->filter(fn ($r) => $r['tokens'] !== [])->values()->all();

        $out = [];
        $n = count($rows);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                [$a, $b] = [$rows[$i], $rows[$j]];
                // deux fiches du catalogue recommandé : cohérentes entre elles par construction
                if (! $a['own'] && ! $b['own']) continue;
                $same = $a['tokens'] === $b['tokens'];
                $nested = ! $same && $a['tokens'][0] === $b['tokens'][0]
                    && (self::subset($a['tokens'], $b['tokens']) || self::subset($b['tokens'], $a['tokens']));
                if (! $same && ! $nested) continue;
                $sa = self::normalize((string) $a['p']->storage);
                $sb = self::normalize((string) $b['p']->storage);
                // « même produit écrit deux fois » se signale toujours ; « voisines » seulement si la conservation diffère
                if (! $same && $sa === $sb) continue;
                $out[] = [
                    'kind' => $same ? 'duplicate' : 'storage_differs',
                    'ids' => [(int) $a['p']->id, (int) $b['p']->id],
                    'names' => [(string) $a['p']->name, (string) $b['p']->name],
                    'storages' => [(string) $a['p']->storage, (string) $b['p']->storage],
                ];
                if (count($out) >= self::MAX_NEIGHBOURS) return $out;
            }
        }

        return $out;
    }

    /** @param list<string> $small @param list<string> $big */
    private static function subset(array $small, array $big): bool
    {
        return count($small) < count($big) && array_diff($small, $big) === [];
    }
}
