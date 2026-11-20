<?php

namespace App\Services\Labels;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * [ETIQUETTES 2026-10-19 · owner] Rapprochement LOCAL d'une phrase et du catalogue DLC, sans réseau.
 *
 * Sert de filet quand la dictée n'a pas de modèle sous la main (clé absente, fournisseur en panne) et
 * de référence testable. Il est volontairement PRUDENT : un produit n'est retenu que si tous ses mots
 * significatifs ont été dits (« Tomates tranchées » demande « tomates » ET « tranchées »). Un mot qui
 * convient à plusieurs fiches (« tomates » seul) n'est jamais tranché au hasard : il est rendu à
 * l'écran comme « pas compris » et la personne choisit. Une étiquette de DLC sur le mauvais produit
 * est pire qu'une étiquette qu'on retape.
 *
 * Le modèle, lui, comprend « tomates » = la fiche de tomates la plus courante ; ce moteur ne le fait pas.
 */
class DlcVoiceLocalMatcher
{
    /** Mots vides : ne comptent ni dans un nom de produit ni dans la phrase. */
    private const STOP = ['de', 'du', 'des', 'd', 'la', 'le', 'les', 'l', 'et', 'au', 'aux', 'en', 'a', 'un', 'une'];

    /** Mots de commande (racines) : jamais rendus comme « pas compris ». */
    private const COMMAND = ['imprim', 'auss', 'pour', 'moi', 'donn', 'met', 'ajout', 'etiquett', 'boit', 'fois', 'svp', 'plait', 'plus', 'encor', 'veux', 'voudr', 'besoin', 'fai', 'fait', 'sil', 'ete', 'tout', 'avec', 'sans'];

    /**
     * @param  Collection<int, \App\Models\DlcLabelProduct>  $products
     * @return array{items: list<array{product_id: int, name: string, copies: int, heard: string}>, unmatched: list<string>}
     */
    public static function match(string $text, Collection $products): array
    {
        $words = self::words($text);
        $claimed = array_fill(0, count($words), false);
        $items = [];
        $picked = [];

        $profiles = [];
        foreach ($products as $p) {
            [$required, $optional] = self::profile((string) $p->name);
            if ($required !== []) {
                $profiles[$p->id] = ['p' => $p, 'req' => $required, 'opt' => $optional];
            }
        }

        // 1) Produits dont TOUS les mots significatifs ont été dits — les plus précis d'abord.
        $full = array_filter($profiles, fn (array $pr): bool => self::allPresent($pr['req'], $words, $claimed));
        uasort($full, fn (array $a, array $b): int => [count($b['req']), count(array_intersect($b['opt'], $words))] <=> [count($a['req']), count(array_intersect($a['opt'], $words))]);
        foreach ($full as $id => $pr) {
            if (! self::allPresent($pr['req'], $words, $claimed)) {
                continue; // un produit plus précis vient de prendre ces mots
            }
            self::claim($pr['req'], $words, $claimed);
            self::claim($pr['opt'], $words, $claimed);
            $items[] = self::item($pr['p'], implode(' ', $pr['req']));
            $picked[$id] = true;
        }

        // 2) Mots restants : un mot qui ne désigne qu'UNE fiche (la meilleure) suffit ; ambigu ou inconnu → « pas compris ».
        $unmatched = [];
        foreach ($words as $i => $w) {
            if ($claimed[$i] || strlen($w) < 4 || in_array($w, self::COMMAND, true)) {
                continue;
            }
            $candidates = [];
            foreach ($profiles as $id => $pr) {
                if (isset($picked[$id]) || ! in_array($w, array_merge($pr['req'], $pr['opt']), true)) {
                    continue;
                }
                $candidates[$id] = count(array_filter(array_unique(array_merge($pr['req'], $pr['opt'])), fn ($t): bool => self::present($t, $words, $claimed)));
            }
            arsort($candidates);
            $top = array_slice($candidates, 0, 2, true);
            if ($top !== [] && (count($top) === 1 || array_values($top)[0] > array_values($top)[1])) {
                $id = array_key_first($top);
                $pr = $profiles[$id];
                self::claim(array_merge($pr['req'], $pr['opt']), $words, $claimed);
                $items[] = self::item($pr['p'], $w);
                $picked[$id] = true;

                continue;
            }
            $claimed[$i] = true;
            $unmatched[] = $w;
        }

        return ['items' => $items, 'unmatched' => array_values(array_unique($unmatched))];
    }

    /** Racine d'un mot : minuscule sans accent, sans pluriel ni « e » final (« coupée », « coupés », « coupe » → « coup »). */
    public static function stem(string $word): string
    {
        $w = preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii($word))) ?? '';
        $w = (string) preg_replace('/[sx]$/', '', $w);
        while (strlen($w) > 3 && str_ends_with($w, 'e')) {
            $w = substr($w, 0, -1);
        }

        return $w;
    }

    /** @return list<string> */
    private static function words(string $text): array
    {
        $out = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', Str::lower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $raw) {
            $ascii = Str::ascii($raw);
            if (in_array($ascii, self::STOP, true)) {
                continue;
            }
            $s = self::stem($raw);
            if ($s !== '') {
                $out[] = $s;
            }
        }

        return $out;
    }

    /** @return array{0: list<string>, 1: list<string>} mots requis (hors parenthèses) et facultatifs (entre parenthèses) */
    private static function profile(string $name): array
    {
        $optional = '';
        $main = (string) preg_replace_callback('/\(([^)]*)\)/u', function (array $m) use (&$optional): string {
            $optional .= ' '.$m[1];

            return ' ';
        }, $name);

        return [array_values(array_unique(self::words($main))), array_values(array_unique(self::words($optional)))];
    }

    private static function present(string $stem, array $words, array $claimed): bool
    {
        foreach ($words as $i => $w) {
            if (! $claimed[$i] && $w === $stem) {
                return true;
            }
        }

        return false;
    }

    private static function allPresent(array $stems, array $words, array $claimed): bool
    {
        foreach ($stems as $s) {
            if (! self::present($s, $words, $claimed)) {
                return false;
            }
        }

        return true;
    }

    private static function claim(array $stems, array $words, array &$claimed): void
    {
        foreach ($stems as $s) {
            foreach ($words as $i => $w) {
                if (! $claimed[$i] && $w === $s) {
                    $claimed[$i] = true;
                    break;
                }
            }
        }
    }

    private static function item($product, string $heard): array
    {
        return ['product_id' => (int) $product->id, 'name' => (string) $product->name, 'copies' => 1, 'heard' => $heard];
    }
}
