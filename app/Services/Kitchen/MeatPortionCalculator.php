<?php

namespace App\Services\Kitchen;

/**
 * [GOAL CUISSON 2026-08-06 · owner] SOURCE DE VÉRITÉ UNIQUE des portions de viande.
 *
 * POURQUOI CE SERVICE EXISTE
 * --------------------------
 * Le cuisinier ne doit plus lire toute la commande pour savoir quoi mettre à cuire. Il lui faut
 * UNE ligne, en haut, qui agrège toutes les viandes de la commande entière — puis il prépare
 * pains, sauces et crudités pendant que ça cuit.
 *
 * Les MÊMES portions alimentent la consommation de stock du jour. Écran, ticket et stock
 * doivent donc partager CE calcul et lui seul : la campagne d'audit précédente a montré que le
 * motif de défaut dominant de ce projet est « un correctif appliqué à une moitié du mécanisme,
 * pas à sa jumelle ». Une portion calculée à deux endroits finirait par diverger.
 *
 * LA RÈGLE DE PORTION (owner)
 * ---------------------------
 * « Portion complète » = 2 pièces.
 *   · un produit à UNE viande        → 2 pièces de cette viande   (Cayenne hachée = 2K)
 *   · un produit à DEUX viandes      → 1 pièce de chacune         (Méga poulet+hachée = 1P 1K)
 *   · deux fois la même viande       → elles s'additionnent       (Méga tout hachée = 2K)
 *
 * Cette règle est STRUCTURELLE : elle se déduit du nombre d'emplacements « Viande N » que le
 * produit porte réellement, tel qu'il est SCELLÉ dans le composition_snapshot. Aucun tableau
 * produit par produit à maintenir pour les 9 produits à choix de viande — donc aucune dérive
 * possible le jour où l'owner ajoute un sandwich.
 *
 * CE QUI N'EST PAS ENCORE CONNU
 * -----------------------------
 * Les produits à viande FIXE (burgers, Suprême, menus enfants) n'ont AUCUNE viande déclarée en
 * base : leur recette n'est écrite nulle part. Tant que l'owner ne l'a pas donnée, ils sont
 * marqués INCONNU et le bandeau affiche « ? » — jamais un chiffre deviné. Une portion inventée
 * ferait cuire la mauvaise quantité ET fausserait le stock : mieux vaut un point d'interrogation
 * visible qu'un chiffre faux invisible.
 *
 * @see plans/GOAL_CUISSON_ET_STOCK_VIANDE_2026-08-06.md §2 pour la liste des questions ouvertes.
 */
final class MeatPortionCalculator
{
    /** Nombre de pièces d'une portion complète (owner : « la portion c'est 2 »). */
    public const PORTION_COMPLETE = 2;

    /**
     * Produits dont la viande est FIXE (aucun attribut « Viande N ») et dont la recette n'est
     * PAS encore connue. Clé = motif sur le nom de l'article.
     *
     * @var array<int, string>
     */
    private const RECETTE_INCONNUE = [
        '/burger/iu',
        '/supr[êe]me/iu',
        '/menu\s*enfant/iu',
        '/nugget/iu',
    ];

    public function __construct(
        private readonly \App\Services\Hardware\KitchenTicketSymbolicFormatter $formatter = new \App\Services\Hardware\KitchenTicketSymbolicFormatter(),
    ) {
    }

    /**
     * Compte les pièces de viande à cuire pour UNE ligne de commande.
     *
     * @param  string      $itemName     nom de l'article vendu
     * @param  array       $snapshot     composition_snapshot scellé (variations + extras)
     * @param  int         $quantity     quantité de la ligne
     * @param  string|null $instruction  texte libre où vit le NOM de la viande supplémentaire
     * @return array{pieces: array<string,int>, inconnu: bool}
     */
    public function forLine(string $itemName, array $snapshot, int $quantity = 1, ?string $instruction = null): array
    {
        $quantity = max(1, $quantity);

        // 1. Les emplacements « Viande N » réellement scellés sur cette ligne.
        //    La clé canonique du composition_snapshot est `lines` — c'est celle que lit le ticket
        //    cuisine en production (KitchenTicketSymbolicFormatter:170). `variations` n'est accepté
        //    qu'en repli, pour les charges utiles plus anciennes. Mon premier jet ne lisait QUE
        //    `variations` : la fixture de test encodait le défaut et le rendait invisible.
        $viandes = [];
        $sources = $snapshot['lines'] ?? $snapshot['variations'] ?? [];
        foreach ($sources as $v) {
            $attribut = (string) ($v['attribute_name'] ?? $v['group'] ?? '');
            if (! preg_match('/viande/iu', $attribut)) {
                continue;
            }
            $nom = trim((string) ($v['variation_name'] ?? $v['name'] ?? $v['value'] ?? ''));
            if ($nom !== '') {
                $viandes[] = $nom;
            }
        }

        $pieces = [];

        if ($viandes !== []) {
            // Règle structurelle : 1 emplacement → portion complète ; N emplacements → 1 chacun.
            $parViande = count($viandes) === 1 ? self::PORTION_COMPLETE : 1;
            foreach ($viandes as $nom) {
                foreach ($this->symbolesPour($nom) as $symbole => $facteur) {
                    $this->ajoute($pieces, $symbole, $parViande * $facteur * $quantity);
                }
            }
        }

        // 2. Supplément viande : portion COMPLÈTE (owner : « on le note un supplément complet »).
        //    Les wizards sont FROZEN et facturent un extra GÉNÉRIQUE et sans nom ; l'identité de la
        //    viande ne survit que dans l'instruction libre. On réutilise l'extracteur déjà éprouvé
        //    du ticket cuisine (extraViandeNames) plutôt que d'en écrire un second qui dériverait.
        //    Sans nom récupérable on compte quand même les pièces sous « ? » : le cuisinier doit
        //    voir qu'il y a une viande de plus à cuire, même si le ticket ne dit pas laquelle.
        $nomsSupp = $this->formatter->extraViandeNames($instruction);
        $iSupp = 0;
        foreach (($snapshot['extras'] ?? []) as $e) {
            $nomExtra = (string) ($e['extra_name'] ?? $e['name'] ?? '');
            if (! preg_match('/viande\s*suppl/iu', $nomExtra)) {
                continue;
            }
            $n = max(1, (int) ($e['quantity'] ?? 1));
            for ($k = 0; $k < $n; $k++) {
                $nom = $nomsSupp[$iSupp] ?? null;
                $iSupp++;
                if ($nom === null) {
                    $this->ajoute($pieces, '?', self::PORTION_COMPLETE * $quantity);
                    continue;
                }
                // Le wizard peut préfixer « 2× Poulet » : le multiplicateur est porté par le nom.
                $mult = 1;
                if (preg_match('/^(\d+)\s*[x×]\s*(.+)$/iu', trim($nom), $m)) {
                    $mult = max(1, (int) $m[1]);
                    $nom = trim($m[2]);
                }
                foreach ($this->symbolesPour($nom) as $symbole => $facteur) {
                    $this->ajoute($pieces, $symbole, self::PORTION_COMPLETE * $facteur * $mult * $quantity);
                }
            }
        }

        // 3. Recette fixe non encore connue → on le DIT, on ne devine pas.
        $inconnu = false;
        if ($viandes === []) {
            foreach (self::RECETTE_INCONNUE as $motif) {
                if (preg_match($motif, $itemName)) {
                    $inconnu = true;
                    break;
                }
            }
        }

        return ['pieces' => $pieces, 'inconnu' => $inconnu];
    }

    /**
     * Agrège toutes les lignes d'une commande en UNE ligne de cuisson.
     *
     * @param  array<int, array{name: string, snapshot: array, quantity: int, instruction?: ?string}> $lignes
     * @return array{pieces: array<string,int>, inconnus: int, texte: string}
     */
    public function forOrder(array $lignes): array
    {
        $total = [];
        $inconnus = 0;

        foreach ($lignes as $l) {
            $r = $this->forLine(
                (string) ($l['name'] ?? ''),
                (array) ($l['snapshot'] ?? []),
                (int) ($l['quantity'] ?? 1),
                isset($l['instruction']) ? (string) $l['instruction'] : null,
            );
            foreach ($r['pieces'] as $symbole => $n) {
                $this->ajoute($total, $symbole, $n);
            }
            if ($r['inconnu']) {
                $inconnus += max(1, (int) ($l['quantity'] ?? 1));
            }
        }

        return ['pieces' => $total, 'inconnus' => $inconnus, 'texte' => $this->rendu($total, $inconnus)];
    }

    /**
     * Rend la ligne lue par le cuisinier : « 9K 3P 2Cordon ».
     * K vient toujours en tête — c'est la viande la plus longue à cuire, donc celle qu'il met
     * en premier sur la plancha.
     */
    public function rendu(array $pieces, int $inconnus = 0): string
    {
        if ($pieces === [] && $inconnus === 0) {
            return '';
        }

        uksort($pieces, static function (string $a, string $b): int {
            $rang = static fn (string $s): int => $s === 'K' ? 0 : ($s === 'P' ? 1 : ($s === '?' ? 3 : 2));

            return [$rang($a), $a] <=> [$rang($b), $b];
        });

        $parts = [];
        foreach ($pieces as $symbole => $n) {
            $parts[] = $n . $symbole;
        }
        if ($inconnus > 0) {
            $parts[] = $inconnus . '×?';
        }

        return implode(' ', $parts);
    }

    /**
     * Résout un nom de viande en symbole(s) cuisine, avec le poids de chacun dans l'emplacement.
     *
     * La table de symboles n'est PAS redéfinie ici : elle est empruntée à
     * KitchenTicketSymbolicFormatter::meatSymbol, déjà en parité verrouillée avec la jumelle JS.
     * Une seconde table finirait par diverger de la première — c'est exactement le défaut que ce
     * projet a payé le plus cher.
     *
     * Un nom « mixte » rend PLUSIEURS symboles (« P K ») : l'emplacement est alors partagé à
     * parts égales entre ses viandes, conformément à la règle owner du Méga (moitié-moitié).
     *
     * @return array<string, float|int> symbole => part de l'emplacement
     */
    private function symbolesPour(string $nom): array
    {
        $rendu = trim($this->formatter->meatSymbol($nom));

        if ($rendu === '') {
            // Viande absente de la table : 3 premières lettres, comme le veut la convention owner.
            $court = preg_replace('/[^a-z]/', '', $this->normalise($nom));

            return [($court !== '' ? ucfirst(mb_substr($court, 0, 3)) : '?') => 1];
        }

        $syms = preg_split('/\s+/', $rendu) ?: [$rendu];
        $part = 1 / max(1, count($syms));

        $out = [];
        foreach ($syms as $s) {
            $out[$s] = ($out[$s] ?? 0) + $part;
        }

        return $out;
    }

    private function ajoute(array &$pieces, string $symbole, float|int $n): void
    {
        $pieces[$symbole] = (int) round(($pieces[$symbole] ?? 0) + $n);
    }

    private function normalise(string $s): string
    {
        $s = \Normalizer::isNormalized($s) ? $s : \Normalizer::normalize($s);
        $s = preg_replace('/\p{Mn}/u', '', \Normalizer::normalize($s, \Normalizer::FORM_D) ?: $s);

        return mb_strtolower(trim((string) $s));
    }
}
