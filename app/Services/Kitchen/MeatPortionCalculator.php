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
     * RECETTES FIXES — produits sans attribut « Viande N », dont la composition est immuable.
     *
     * Données de l'owner (2026-08-06), CONFIRMÉES une à une contre la colonne `description` de
     * la table items — il avait demandé vérification plutôt que d'être cru sur parole :
     *   Cheese Burger  « Steak »                          → 1K
     *   Double Cheese  « 2 steaks »                       → 2K
     *   Grill Burger   « 2 steaks, jambon de dinde »      → 2K
     *   Big Burger     « 3 steaks, 2 jambons de dinde »   → 3K
     *   Fish Burger    « Poisson pané »                   → 1 Poi
     *   Chicken Burger (nom)                              → 1 Chick
     *   Suprême        « Steak haché, cordon bleu »       → 1K + 1 Cordon
     *   Menu Enf. Nuggets  « 6 nuggets, frites »          → 6 Nug + 1F
     *   Menu Enf. Chicken  « Chicken burger, frites »     → 1 Chick + 1F
     *
     * Le jambon de dinde et le cheddar ne figurent pas ici : ils ne passent pas à la plancha,
     * et le bandeau ne doit dire que ce qu'il faut CUIRE.
     *
     * ⚠️ L'ORDRE COMPTE : le premier motif qui matche gagne. « Menu Enfant Chicken Burger »
     * doit être reconnu AVANT « Chicken Burger », et « Double Cheese » avant « Cheese ».
     *
     * @var array<int, array{0:string, 1:array<string,int>}>
     */
    private const RECETTES_FIXES = [
        ['/menu\s*enfant.*nugget|nugget.*menu\s*enfant/iu', ['Nug' => 6, 'F' => 1]],
        ['/menu\s*enfant/iu',                               ['Chick' => 1, 'F' => 1]],
        ['/double\s*cheese/iu',                             ['K' => 2]],
        ['/big\s*burger/iu',                                ['K' => 3]],
        ['/grill\s*burger/iu',                              ['K' => 2]],
        ['/fish\s*burger|burger.*poisson/iu',               ['Poi' => 1]],
        ['/chicken\s*burger/iu',                            ['Chick' => 1]],
        ['/cheese\s*burger/iu',                             ['K' => 1]],
        ['/supr[êe]me/iu',                                  ['K' => 1, 'Cordon' => 1]],
    ];

    /**
     * Produits à recette fixe encore NON documentée. Vide depuis que l'owner a donné les 9
     * recettes ci-dessus ; la garde reste en place pour tout burger futur qui arriverait sans
     * recette — il s'affichera « ? » plutôt que de disparaître silencieusement de la plancha.
     *
     * @var array<int, string>
     */
    private const RECETTE_INCONNUE = [
        '/burger/iu',
        '/supr[êe]me/iu',
        '/menu\s*enfant/iu',
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

        // 3. Recette FIXE (burgers, Suprême, menus enfants) — la composition ne dépend d'aucun
        //    choix client, elle est documentée par l'owner et vérifiée contre la description item.
        $recetteConnue = false;
        if ($viandes === []) {
            foreach (self::RECETTES_FIXES as [$motif, $recette]) {
                if (! preg_match($motif, $itemName)) {
                    continue;
                }
                foreach ($recette as $symbole => $n) {
                    $this->ajoute($pieces, $symbole, $n * $quantity);
                }
                $recetteConnue = true;
                break;
            }
        }

        // 4. FRITES (owner) — « le nombre de menu tu mets 5F ; une grande frite c'est
        //    automatiquement 2F ». Elles vont au bain de friture : elles font partie de ce qu'il
        //    faut CUIRE, donc du bandeau. Un menu complet apporte une portion ; une frite vendue
        //    seule aussi ; une GRANDE en apporte deux.
        $this->ajoute($pieces, 'F', $this->portionsFrites($itemName, $snapshot) * $quantity);
        if (($pieces['F'] ?? 0) === 0) {
            unset($pieces['F']);
        }

        // 5. Recette fixe encore non documentée → on le DIT, on ne devine pas.
        $inconnu = false;
        if ($viandes === [] && ! $recetteConnue) {
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
            // Les viandes d'abord (K en tête : la plus longue à cuire, donc la première sur la
            // plancha), puis les frites, puis l'inconnu.
            $rang = static fn (string $s): int => match ($s) {
                'K' => 0,
                'P' => 1,
                'F' => 3,
                '?' => 4,
                default => 2,
            };

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

    /**
     * Portions de frites d'UN exemplaire de l'article (owner 2026-08-06).
     *
     * Une GRANDE frite compte double — c'est le seul cas où une portion vaut 2. La taille peut
     * être portée par le nom de l'article ou par une variation scellée, selon la surface de
     * vente : on regarde les deux, sinon une grande frite prise en caisse compterait pour une.
     * Les menus ENFANTS ne passent pas ici : leur frite est déjà dans RECETTES_FIXES, la
     * compter deux fois enverrait le cuisinier au bain de friture pour rien.
     */
    private function portionsFrites(string $itemName, array $snapshot): int
    {
        if (preg_match('/menu\s*enfant/iu', $itemName)) {
            return 0;
        }

        $grande = static function (string $texte): bool {
            return (bool) preg_match('/\bgrande?\b|\bgrosse\b|\bxl\b|\blarge\b|\bmax[ii]?\b/iu', $texte);
        };

        // Frite vendue SEULE (article dont le nom est la frite elle-même).
        if (preg_match('/\bfrites?\b/iu', $itemName) && ! preg_match('/\bmenu\b|\bformule\b/iu', $itemName)) {
            $taille = '';
            foreach (($snapshot['lines'] ?? $snapshot['variations'] ?? []) as $l) {
                $taille .= ' '.(string) ($l['variation_name'] ?? $l['name'] ?? $l['value'] ?? '');
            }

            return $grande($itemName.$taille) ? 2 : 1;
        }

        // Frite portée par un MENU / une FORMULE (canal addon).
        $portions = 0;
        foreach (($snapshot['addons'] ?? []) as $a) {
            $role = mb_strtolower((string) ($a['role'] ?? ''));
            if ($role !== 'menu_frites' && $role !== 'menu_full' && $role !== 'menu_formule') {
                continue;
            }
            $nom = (string) ($a['addon_name'] ?? $a['name'] ?? '');
            $portions += ($grande($nom) ? 2 : 1) * max(1, (int) ($a['quantity'] ?? 1));
        }

        return $portions;
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
