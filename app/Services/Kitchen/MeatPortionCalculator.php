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
    /**
     * Valeur d'une PORTION COMPLÈTE, par viande. L'unité n'est pas la même selon la viande —
     * c'est la correction owner du 2026-08-07 :
     *
     *   · VIANDE HACHÉE (K) : 2 STEAKS (2 × 75 g).
     *   · POULET (P)        : 1 PORTION de 200 g. Seule viande au poids continu, donc la
     *                          seule qui puisse valoir une DEMI-portion « 0,5P » (100 g) —
     *                          d'où « 2,5P » pour 3 sandwichs mixtes + 1 Cayenne entier.
     *   · NUGGETS (Nug)     : 4 nuggets.
     *   · TENDERS (Tender)  : 3 tenders.
     *   · les autres        : 2 pièces (cordon bleu, mexicanos, fricadelle…), confirmé par
     *                          l'owner — « un Tacos L 2 viandes au cordon bleu affiche 2Cordon ».
     *
     * Toutes ces viandes sont des PIÈCES ENTIÈRES : un demi-nugget n'existe pas en cuisine.
     * Une demi-portion en donne donc la moitié ARRONDIE (2 nuggets, 1 tender, 1 cordon), jamais
     * un nombre à virgule — seul le poulet, vendu au poids, garde ses décimales.
     */
    public const PORTION_COMPLETE = 2;

    /** @var array<string, float> symbole => valeur d'une portion complète */
    private const PORTION_PAR_VIANDE = [
        'K' => 2.0,   // 2 steaks
        'P' => 1.0,   // 1 portion de 200 g — la seule fractionnable
        'Nug' => 4.0, // 4 nuggets
        'Tender' => 3.0, // 3 tenders
    ];

    /**
     * Seules ces viandes acceptent une demi-portion à la virgule. Les autres se comptent en
     * pièces entières : afficher « 1,5Tender » enverrait le cuisinier couper un tender en deux.
     *
     * @var array<int, string>
     */
    private const VIANDES_FRACTIONNABLES = ['P'];

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
            // Règle structurelle : 1 emplacement → portion COMPLÈTE ; N emplacements → une
            // DEMI-portion chacun. La valeur d'une portion dépend ensuite de la viande
            // (2 steaks pour la hachée, 1 portion de 200 g pour le poulet).
            $partEmplacement = count($viandes) === 1 ? 1.0 : 0.5;
            foreach ($viandes as $nom) {
                foreach ($this->symbolesPour($nom) as $symbole => $facteur) {
                    $this->ajoute($pieces, $symbole, $this->portion($symbole) * $partEmplacement * $facteur * $quantity);
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
                    $this->ajoute($pieces, $symbole, $this->portion($symbole) * $facteur * $mult * $quantity);
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
        //    On n'ajoute la clé que si elle est non nulle : depuis que les portions sont
        //    décimales, un `=== 0` strict ne reconnaissait plus le `0.0` et laissait traîner
        //    un « 0F » sur chaque sandwich vendu seul.
        $frites = $this->portionsFrites($itemName, $snapshot, $instruction) * $quantity;
        if ($frites > 0) {
            $this->ajoute($pieces, 'F', $frites);
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
            $parts[] = $this->nombre((float) $n) . $symbole;
        }
        if ($inconnus > 0) {
            $parts[] = $inconnus . '×?';
        }

        return implode(' ', $parts);
    }

    /** « 2 » et non « 2,0 » ; « 2,5 » à la française (locale FR, ADR-007). */
    private function nombre(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, ',', ''), '0'), ',');
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
     *
     * [OWNER 2026-08-10 · « quand on prend un menu, ça doit compter une frite »]
     * -------------------------------------------------------------------------
     * Le menu n'arrive PAS de la même façon selon la surface de vente, et seul le canal de la
     * BORNE était lu. Vérifié en base sur les commandes réelles :
     *
     *   · BORNE / WEB  → le menu est un ADDON du produit (role `menu_full` / `menu_frites`)
     *                    porté par la ligne du sandwich.                        → déjà compté.
     *   · CAISSE       → le menu est une LIGNE DE COMMANDE À PART ENTIÈRE, l'article
     *                    « Menu (Frites + Boisson) », et le produit parent n'en garde qu'un écho
     *                    dans son texte libre (« + Menu (Frites + Boisson) (+2,50 €) »).
     *                    Cette ligne tombait dans le trou : son nom contient « Frites », mais la
     *                    garde anti-menu de la règle « frite vendue seule » l'excluait →  0F.
     *   · PROFIL COMPOSÉ (bols) → aucun addon, aucune ligne : la formule ne vit que dans le
     *                    texte libre (« Formule : Avec frites »).                → 0F.
     *
     * Résultat terrain : un menu pris à la caisse n'ajoutait RIEN au bandeau de cuisson — le
     * cuisinier ne voyait pas la frite à plonger. Les trois canaux sont désormais lus, dans un
     * ordre qui rend le double comptage IMPOSSIBLE :
     *   (A) l'article EST le conteneur de menu          → il porte la frite, personne d'autre ;
     *   (B) l'article EST une frite vendue seule        → inchangé ;
     *   (C) canal ADDON (borne/web)                     → inchangé ;
     *   (D) repli TEXTE LIBRE « Formule : … frites … »  → UNIQUEMENT si (C) n'a rien donné.
     *
     * (D) ne peut pas doubler avec la ligne dédiée de la caisse : l'écho du parent s'écrit
     * « + Menu (…) » et ne comporte JAMAIS « Formule : ». Et « Sauce frites : Andalouse » —
     * présent sur des tickets sans aucune frite — n'est pas non plus une formule.
     */
    private function portionsFrites(string $itemName, array $snapshot, ?string $instruction = null): int
    {
        if (preg_match('/menu\s*enfant/iu', $itemName)) {
            return 0;
        }

        $grande = static function (string $texte): bool {
            return (bool) preg_match('/\bgrande?\b|\bgrosse\b|\bxl\b|\blarge\b|\bmax[ii]?\b/iu', $texte);
        };

        // (A) L'article EST le conteneur de menu, vendu comme sa propre ligne (caisse) :
        //     « Menu (Frites + Boisson) », « Formule … frites ». Testé AVANT la règle (B), dont
        //     la garde anti-menu l'écartait justement.
        if ($this->estConteneurMenuAvecFrites($itemName)) {
            return $grande($itemName) ? 2 : 1;
        }

        // (B) Frite vendue SEULE (article dont le nom est la frite elle-même).
        if (preg_match('/\bfrites?\b/iu', $itemName) && ! preg_match('/\bmenu\b|\bformule\b/iu', $itemName)) {
            $taille = '';
            foreach (($snapshot['lines'] ?? $snapshot['variations'] ?? []) as $l) {
                $taille .= ' '.(string) ($l['variation_name'] ?? $l['name'] ?? $l['value'] ?? '');
            }

            return $grande($itemName.$taille) ? 2 : 1;
        }

        // (C) Frite portée par un MENU / une FORMULE (canal addon — borne, web).
        $portions = 0;
        foreach (($snapshot['addons'] ?? []) as $a) {
            $role = mb_strtolower((string) ($a['role'] ?? ''));
            if ($role !== 'menu_frites' && $role !== 'menu_full' && $role !== 'menu_formule') {
                continue;
            }
            $nom = (string) ($a['addon_name'] ?? $a['name'] ?? '');
            $portions += ($grande($nom) ? 2 : 1) * max(1, (int) ($a['quantity'] ?? 1));
        }

        // (D) Repli TEXTE LIBRE, uniquement si aucun addon n'a porté la formule.
        if ($portions === 0) {
            $portions = $this->portionsFritesDepuisInstruction($instruction, $grande);
        }

        return $portions;
    }

    /**
     * L'article vendu EST-IL le conteneur d'un menu qui comprend des frites ?
     *
     * Même grammaire de conteneur que {@see \App\Services\Hardware\KitchenTicketSymbolicFormatter::isMenuItem()}
     * — « Menu ( … ) » ou « Formule … » — de sorte qu'un vrai produit dont le nom contient le mot
     * « menu » (« Menu Enfant Nuggets ») ne soit jamais confondu avec le conteneur. On exige EN PLUS
     * que le nom nomme les frites : « Boisson Seule » est aussi une part de menu, et elle ne se
     * plonge pas dans l'huile.
     */
    private function estConteneurMenuAvecFrites(string $itemName): bool
    {
        return (bool) preg_match('/\bmenu\s*\(|\bformule\b/iu', $itemName)
            && (bool) preg_match('/\bfrites?\b/iu', $itemName);
    }

    /**
     * Formule déclarée dans le TEXTE LIBRE (« Formule : Avec frites », « Formule : Menu complet
     * (frites + boisson) (Hawaï 33cl) ») — dernier canal, celui des profils composés qui n'écrivent
     * ni addon ni ligne dédiée. On lit le SEGMENT de la formule et lui seul : le reste de
     * l'instruction contient couramment « Sauce frites : … », qui ne prouve aucune frite.
     */
    private function portionsFritesDepuisInstruction(?string $instruction, callable $grande): int
    {
        if (! is_string($instruction) || trim($instruction) === '') {
            return 0;
        }
        // Le segment s'arrête au séparateur de composition ('.' borne, '|' legacy, saut de ligne)
        // pour ne pas avaler la « Sauce frites » qui suit très souvent la formule.
        if (! preg_match('/\bformules?\s*:\s*([^\n.|]+)/iu', $instruction, $m)) {
            return 0;
        }
        $segment = $m[1];
        if (! preg_match('/\bfrites?\b/iu', $segment)) {
            return 0;
        }

        return $grande($segment) ? 2 : 1;
    }

    /** Valeur d'une portion complète pour cette viande (2 steaks, 1 portion de poulet…). */
    private function portion(string $symbole): float
    {
        return self::PORTION_PAR_VIANDE[$symbole] ?? (float) self::PORTION_COMPLETE;
    }

    /**
     * Accumule en conservant les DEMI-portions du poulet : arrondir à l'entier ici ferait
     * disparaître le « 0,5P » d'un sandwich mixte, et trois mixtes ne feraient plus 1,5 portion
     * mais 3. Les viandes en pièces entières, elles, sont arrondies AU PLUS PROCHE dès
     * l'accumulation — un demi-nugget n'existe pas.
     */
    private function ajoute(array &$pieces, string $symbole, float|int $n): void
    {
        $total = ((float) ($pieces[$symbole] ?? 0)) + (float) $n;

        $pieces[$symbole] = in_array($symbole, self::VIANDES_FRACTIONNABLES, true)
            ? round($total, 2)
            : (float) round($total);
    }

    private function normalise(string $s): string
    {
        $s = \Normalizer::isNormalized($s) ? $s : \Normalizer::normalize($s);
        $s = preg_replace('/\p{Mn}/u', '', \Normalizer::normalize($s, \Normalizer::FORM_D) ?: $s);

        return mb_strtolower(trim((string) $s));
    }
}
