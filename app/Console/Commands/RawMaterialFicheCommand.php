<?php

namespace App\Console\Commands;

use App\Enums\Status;
use App\Models\Item;
use App\Models\RawMaterial;
use App\Models\RawMaterialRecipeLine;
use Database\Seeders\RawMaterialBaselineSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * [ARCH_STOCK_INTELLIGENT_BOM_2026-07-23 / P1b] Fiche paramètres owner.
 *
 * Génère la FICHE PARAMÈTRES demandée par l'owner (plan §"Ce qu'il faut de
 * l'owner" P1) : PAS une page blanche — un tableau PRÉ-REMPLI de recettes
 * (produit → matières premières + quantités) que l'owner corrige (surtout les
 * lignes ⚠️ À CONFIRMER).
 *
 * Source = DB LOCALE uniquement (Eloquent : Item ACTIF + catégorie). Les règles
 * de prefill ci-dessous sont HARDCODÉES et ASSUMÉES POUR NOTRE RESTO (Le Cayenne,
 * mono-branche V1) — plan §RÉPONSES OWNER. Elles ne réinventent aucune donnée :
 * elles proposent des quantités de départ à partir du nom/description/catégorie
 * réels de chaque produit.
 *
 * Effets :
 *   1. `raw_material_recipe_lines` PRODUITS upsertées (subject_type=Item).
 *   2. [B-2] `raw_material_recipe_lines` EXTRAS upsertées pour les suppléments
 *      payants CONNUS (subject_type='extra_group', mappés par subject_group de
 *      nom) : Cheddar, Cordon bleu, Œuf, Viande supplémentaire (75 g mix assumé),
 *      Sauce supplémentaire (25 g). Une matière absente → aucune ligne.
 *   3. Fiche Markdown écrite dans reports/goal-mega-2026-07-22/ (avec section Extras).
 *
 * Toutes idempotentes (updateOrCreate sur l'UNIQUE
 * (branch_id, subject_type, subject_id, raw_material_id)).
 *
 * ⚠️ HONNÊTETÉ PÉRIMÈTRE : les VIANDES choisies par variation/extra (protéine des
 * Tacos/Assiettes/Bols, qui passe par une option NON mappée) et les suppléments
 * sans matière connue restent OWNER-DATA — aucune fausse ligne, ils partent en
 * `skipped[]` du moteur jusqu'à confirmation owner.
 *
 * NF525 : couche matières premières ADDITIVE — ne touche JAMAIS la chaîne
 * fiscale (aucun prix, aucune séquence, aucun audit_log). Branch isolation :
 * hard-scope explicite branch_id=1 (pattern DailyBookEntry, exemption sentinelle).
 * Idempotent : double run = même contenu, même nombre de lignes.
 */
class RawMaterialFicheCommand extends Command
{
    protected $signature = 'raw-materials:fiche {--dry-run : Calculer et afficher sans écrire (ni DB, ni fichier)}';

    protected $description = 'Génère la fiche paramètres owner (recettes pré-remplies produit→matières) + upsert des lignes de recette. Idempotent.';

    /** Branche unique V1 (hard-scope). */
    public const BRANCH_ID = 1;

    public const FICHE_PATH = 'reports/goal-mega-2026-07-22/FICHE_PARAMETRES_INGREDIENTS.md';

    /** Mots-clés catégorie → produit à base de pain/galette (recette garnie). */
    private const CAT_BREAD = ['sandwich', 'burger', 'tacos', 'galette', 'bagel', 'wrap', 'panini'];

    /** Mots-clés catégorie → frites. */
    private const CAT_FRITES = ['frite'];

    /**
     * [OWNER-CONFIRMED] Corrections owner par produit : ces (produit → matières) passent
     * en ✅ pré-rempli (l'owner a validé la valeur). S'enrichit à chaque correction.
     */
    private const OWNER_CONFIRMED = [
        'Suprême' => ['Viande hachée', 'Cordon bleu'], // owner 2026-07-23 : 75 g + 1 pièce
    ];

    /** Ordre d'affichage stable des matières dans une recette. */
    private const MATERIAL_ORDER = [
        'Pain' => 1, 'Galette' => 1, 'Portion frites' => 1,
        'Sauce maison' => 2, 'Viande hachée' => 3, 'Poulet' => 4, 'Cordon bleu' => 4,
        'Cheddar' => 5, 'Jambon' => 6, 'Salade' => 7, 'Tomate' => 8, 'Oignon' => 9,
    ];

    /**
     * [B-2] Marqueur `subject_type` des lignes de recette EXTRAS (mappées par
     * GROUPE de nom). VOLONTAIREMENT ≠ ItemExtra::class : la branche id du moteur
     * ({@see RawMaterialConsumptionService::recipeLinesForExtra} — subject_type =
     * ItemExtra AND subject_id = extra_id) ne doit JAMAIS matcher ces lignes
     * contre un id d'extra réel. Seul le `OR subject_group` les résout.
     */
    private const EXTRA_SUBJECT_TYPE = 'extra_group';

    /**
     * [B-2] Recettes pré-remplies des SUPPLÉMENTS payants « évidents » — données
     * SÛRES connues seulement. Mappées par `subject_group` = normalizeGroup(nom).
     * `subject_id` = ordinal (clé) STABLE : distinct par groupe pour respecter
     * l'UNIQUE (branch_id, subject_type, subject_id, raw_material_id) même quand
     * deux groupes pointent la MÊME matière (cheddar/cheese, viande/steak).
     *
     * Format : ordinal => [groupe normalisé, matière, qty, àConfirmer(bool), note].
     * Matière absente de la baseline → AUCUNE ligne écrite (pas de fausse ligne).
     *
     * ⚠️ [R2-1] ANTI-COLLISION COLLATION : `subject_group` est en collation
     * ligature/accent-INSENSIBLE (utf8mb4_unicode_ci : `œ`≡`oe`, `é`≡`e`). NE PAS
     * ajouter deux alias qui deviennent ÉGAUX sous cette collation pour la MÊME
     * matière (ex. l'ancien couple `œuf`/`oeuf` créait 2 lignes que
     * {@see RawMaterialConsumptionService::recipeLinesForExtra} SOMMAIT → double
     * décompte). Un seul alias suffit : la collation résout déjà les deux
     * orthographes. `cheddar`/`cheese` et `viande…`/`steak…` sont des chaînes
     * distinctes sous la collation → PAS une collision (OK).
     *
     * ⚠️ Restent OWNER-DATA (aucune ligne ici) : les VIANDES choisies par
     * variation/extra (Tacos/Assiettes/Bols) et les suppléments sans matière
     * mappée (Champignons, Raclette, Emmental, Boursin, Légumes sautés…).
     */
    private const EXTRA_RECIPES = [
        1 => ['cheddar',               'Cheddar',       1.0,  false, '1 pièce'],
        2 => ['cheese',                'Cheddar',       1.0,  false, '1 pièce (variante EN)'],
        3 => ['cordon bleu',           'Cordon bleu',   1.0,  false, '1 pièce'],
        // [R2-1] Un SEUL alias œuf : la collation ligature-insensible résout aussi
        // « oeuf » sans ligature (l'ancien alias 5 doublait la matière → retiré).
        4 => ['œuf',                   'Œuf',           1.0,  false, '1 œuf par supplément (couvre « oeuf » via collation)'],
        6 => ['viande supplémentaire', 'Viande hachée', 75.0, true,  'mix moyen assumé 75 g'],
        7 => ['steak supplémentaire',  'Viande hachée', 75.0, true,  'mix moyen assumé 75 g'],
        8 => ['sauce supplémentaire',  'Sauce maison',  25.0, false, 'dose 25 g (owner gravé)'],
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $r = self::generate($dry);

        $this->info(($dry ? '[dry-run] ' : '')
            ."Fiche paramètres — {$r['products']} produits pré-remplis, "
            ."{$r['lines']} lignes de recette upsertées, "
            ."{$r['unitaire']} produits à l'unité/hors périmètre P1."
            .($dry ? '' : " Fiche : {$r['path']}"));

        return self::SUCCESS;
    }

    /**
     * @return array{products:int,lines:int,unitaire:int,path:string}
     */
    public static function generate(bool $dryRun = false): array
    {
        // 1. S'assurer que la baseline matières a tourné (call silencieux si vide).
        if (RawMaterial::query()->where('branch_id', self::BRANCH_ID)->count() === 0) {
            (new RawMaterialBaselineSeeder())->run();
        }

        // Map nom → matière (unité SSOT lue sur la row, pas hardcodée).
        $materials = RawMaterial::query()
            ->where('branch_id', self::BRANCH_ID)
            ->get()
            ->keyBy('name');

        $items = Item::query()
            ->where('status', Status::ACTIVE)
            ->with('category:id,name')
            ->orderBy('item_category_id')
            ->orderBy('order')
            ->orderBy('name')
            ->get(['id', 'name', 'description', 'item_category_id']);

        $recipeGroups = [];   // catName => [ ['item'=>, 'lines'=> [ [name,qty,unit,confirm] ] ] ]
        $unitaireGroups = []; // catName => [itemName, ...]
        $lineCount = 0;
        $productCount = 0;
        $extraLineCount = 0;

        foreach ($items as $item) {
            $catName = $item->category->name ?? 'Sans catégorie';
            $kind = self::categoryKind($catName);

            if ($kind === 'unitaire') {
                $unitaireGroups[$catName][] = $item->name;
                continue;
            }

            $specs = $kind === 'frites'
                ? self::fritesRecipe()
                : self::breadRecipe($catName, $item->name, (string) $item->description);

            // Résoudre chaque spec sur une matière réelle + upsert.
            $lines = [];
            foreach ($specs as $spec) {
                [$matName, $qty, $confirm, $note] = $spec;
                $material = $materials->get($matName);
                if ($material === null) {
                    continue; // matière absente de la baseline → on saute proprement.
                }

                $lines[] = [
                    'name' => $matName,
                    'qty' => $qty,
                    'unit' => self::unitLabel($material->unit),
                    'confirm' => $confirm,
                ];

                if (! $dryRun) {
                    RawMaterialRecipeLine::updateOrCreate(
                        [
                            'branch_id' => self::BRANCH_ID,
                            'subject_type' => Item::class,
                            'subject_id' => $item->id,
                            'raw_material_id' => $material->id,
                        ],
                        [
                            'qty' => $qty,
                            'note' => 'prefill',
                        ],
                    );
                }
                $lineCount++;
            }

            if ($lines !== []) {
                $recipeGroups[$catName][] = ['item' => $item->name, 'lines' => $lines];
                $productCount++;
            }
        }

        // [B-2] Recettes des SUPPLÉMENTS payants (mappées par subject_group).
        // Indépendantes du catalogue, écrites APRÈS les produits. Matière absente
        // de la baseline → aucune ligne (pas de fausse ligne), juste ⚠️ dans la fiche.
        $extraLines = [];
        foreach (self::EXTRA_RECIPES as $ordinal => [$group, $matName, $qty, $confirm, $note]) {
            $material = $materials->get($matName);

            $extraLines[] = [
                'group' => $group,
                'material' => $matName,
                'qty' => $qty,
                'unit' => $material !== null ? self::unitLabel($material->unit) : '—',
                'confirm' => $confirm,
                'present' => $material !== null,
                'note' => $note,
            ];

            if ($material === null) {
                continue;
            }

            if (! $dryRun) {
                RawMaterialRecipeLine::updateOrCreate(
                    [
                        'branch_id' => self::BRANCH_ID,
                        'subject_type' => self::EXTRA_SUBJECT_TYPE,
                        'subject_id' => $ordinal,
                        'raw_material_id' => $material->id,
                    ],
                    [
                        'subject_group' => $group,
                        'qty' => $qty,
                        'note' => 'prefill_extra',
                    ],
                );
            }
            $lineCount++;
            $extraLineCount++;
        }

        // [R2-1] CONVERGENCE : purge les lignes extra_group d'ordinaux RETIRÉS
        // d'EXTRA_RECIPES (ex. l'ancien alias 5 « oeuf » ≡ « œuf » sous la collation
        // utf8mb4_unicode_ci, qui double-comptait). `updateOrCreate` upsert mais ne
        // supprime jamais → sans cette purge une DB déjà semée garderait la ligne
        // fantôme. Idempotent (delete borné à extra_group + branche 1 + ordinaux
        // hors du jeu courant → ne touche JAMAIS les lignes produit Item::). Après
        // un run, un groupe = une seule ligne (plus de collision collation).
        if (! $dryRun) {
            RawMaterialRecipeLine::query()
                ->where('branch_id', self::BRANCH_ID)
                ->where('subject_type', self::EXTRA_SUBJECT_TYPE)
                ->whereNotIn('subject_id', array_keys(self::EXTRA_RECIPES))
                ->delete();
        }

        $unitaireCount = array_sum(array_map('count', $unitaireGroups));
        $path = base_path(self::FICHE_PATH);

        if (! $dryRun) {
            File::ensureDirectoryExists(dirname($path));
            File::put($path, self::renderFiche($recipeGroups, $unitaireGroups, $extraLines, $productCount, $lineCount));
        }

        return [
            'products' => $productCount,
            'lines' => $lineCount,
            'unitaire' => $unitaireCount,
            'path' => self::FICHE_PATH,
        ];
    }

    /** Classe une catégorie : 'bread' | 'frites' | 'unitaire'. */
    private static function categoryKind(string $catName): string
    {
        $c = mb_strtolower($catName);
        foreach (self::CAT_FRITES as $kw) {
            if (str_contains($c, $kw)) {
                return 'frites';
            }
        }
        foreach (self::CAT_BREAD as $kw) {
            if (str_contains($c, $kw)) {
                return 'bread';
            }
        }

        return 'unitaire';
    }

    /**
     * Recette pré-remplie d'un produit à base de pain/galette.
     * Chaque spec = [matière, qty, confirmÀConfirmer(bool), note].
     *
     * @return array<int, array{0:string,1:float,2:bool,3:string}>
     */
    private static function breadRecipe(string $catName, string $itemName, string $description): array
    {
        $text = mb_strtolower($itemName.' '.strip_tags($description));
        $nameLower = mb_strtolower($itemName);

        // Pain OU Galette (catégorie/nom galette ou tacos → galette).
        $isGalette = str_contains(mb_strtolower($catName), 'galette')
            || str_contains(mb_strtolower($catName), 'tacos')
            || str_contains($nameLower, 'galette')
            || str_contains($nameLower, 'tacos');

        $specs = [];
        $specs[] = [$isGalette ? 'Galette' : 'Pain', 1.0, false, 'pain de base'];
        $specs[] = ['Sauce maison', 25.0, false, 'dose 25 g (owner gravé)'];

        // Viande — détection ADDITIVE (un produit peut cumuler plusieurs viandes ;
        // ex. Suprême = steak haché + cordon bleu). « au choix »/poisson = aucune
        // ligne viande (owner précise). Le dédoublonnage fusionne les répétitions.
        if (str_contains($text, 'mixte')) {
            // Mixte = combo défini : steak + poulet (exclusif des détections ci-dessous).
            $specs[] = ['Viande hachée', 75.0, true, 'mixte : steak 75 g'];
            $specs[] = ['Poulet', 120.0, true, 'mixte : ~120 g poulet'];
        } else {
            if (str_contains($text, 'poulet') || str_contains($text, 'chicken')) {
                $specs[] = ['Poulet', 200.0, true, '~200 g/sandwich (à confirmer par produit)'];
            }
            if (self::containsAny($text, ['steak', 'haché', 'hache', 'beef', 'bœuf', 'boeuf'])) {
                $specs[] = ['Viande hachée', 75.0, true, 'steak 75 g (compte selon produit)'];
            }
            // [OWNER 2026-07-23] Cordon bleu = matière À LA PIÈCE (Suprême = steak 75 g
            // + 1 cordon bleu) — plus de ligne « Poulet 0 g » générique pour lui.
            if (str_contains($text, 'cordon bleu')) {
                $specs[] = ['Cordon bleu', 1.0, false, '1 pièce (owner 2026-07-23)'];
            }
            if (self::containsAny($text, ['tenders', 'nuggets'])) {
                $specs[] = ['Poulet', 0.0, true, 'panure poulet (tenders/nuggets) — à confirmer'];
            }
            if (self::containsAny($text, ['fricadelle', 'mexicanos'])) {
                $specs[] = ['Viande hachée', 0.0, true, 'à confirmer'];
            }
        }

        // Cheddar (à la pièce — owner gravé) : 2 pour Méga/Terminator/Double.
        if (str_contains($text, 'cheddar') || str_contains($text, 'cheese')) {
            $qtyCheddar = self::containsAny($nameLower, ['méga', 'mega', 'terminator', 'double']) ? 2.0 : 1.0;
            $specs[] = ['Cheddar', $qtyCheddar, false, 'compté à la pièce'];
        }

        // Jambon (tranche) si mentionné.
        if (str_contains($text, 'jambon')) {
            $specs[] = ['Jambon', 1.0, false, 'compté à la tranche'];
        }

        // Crudités — grammages À CONFIRMER (plan).
        $specs[] = ['Salade', 30.0, true, 'grammage à confirmer'];
        $specs[] = ['Tomate', 30.0, true, 'grammage à confirmer'];
        $specs[] = ['Oignon', 15.0, true, 'grammage à confirmer'];

        $specs = self::dedupeOrder($specs);
        // [OWNER-CONFIRMED] statut ✅ forcé sur les matières validées par l'owner pour CE produit.
        foreach (self::OWNER_CONFIRMED as $productName => $materials) {
            if (mb_strtolower($productName) === mb_strtolower(trim($itemName))) {
                foreach ($specs as &$sp) {
                    if (in_array($sp[0], $materials, true)) { $sp[2] = false; }
                }
                unset($sp);
            }
        }

        return $specs;
    }

    /**
     * Recette d'une portion de frites (catégorie Frites).
     *
     * @return array<int, array{0:string,1:float,2:bool,3:string}>
     */
    private static function fritesRecipe(): array
    {
        return self::dedupeOrder([
            ['Portion frites', 1.0, false, 'portion'],
            ['Sauce maison', 25.0, false, 'pot 25 g (owner gravé)'],
        ]);
    }

    /**
     * Fusionne les doublons de matière (UNIQUE 1 ligne/matière/produit) et trie
     * selon MATERIAL_ORDER. Sur doublon : qty max, À CONFIRMER si l'un l'est.
     *
     * @param  array<int, array{0:string,1:float,2:bool,3:string}>  $specs
     * @return array<int, array{0:string,1:float,2:bool,3:string}>
     */
    private static function dedupeOrder(array $specs): array
    {
        $byName = [];
        foreach ($specs as [$name, $qty, $confirm, $note]) {
            if (! isset($byName[$name])) {
                $byName[$name] = [$name, $qty, $confirm, $note];
                continue;
            }
            $existing = $byName[$name];
            $byName[$name] = [
                $name,
                max($existing[1], $qty), // préfère la quantité non nulle/plus grande
                $existing[2] || $confirm,
                $existing[3],
            ];
        }

        $out = array_values($byName);
        usort($out, fn ($a, $b) => (self::MATERIAL_ORDER[$a[0]] ?? 99) <=> (self::MATERIAL_ORDER[$b[0]] ?? 99));

        return $out;
    }

    private static function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $n) {
            if (str_contains($haystack, $n)) {
                return true;
            }
        }

        return false;
    }

    /** Code unité matière → libellé fiche FR. */
    private static function unitLabel(string $unit): string
    {
        return [
            'piece' => 'pièce',
            'tranche' => 'tranche',
            'g' => 'g',
            'cl' => 'cl',
        ][$unit] ?? $unit;
    }

    /** Quantité décimale → affichage propre (entier si rond, sinon décimales utiles). */
    private static function fmtQty(float $qty): string
    {
        if ($qty == 0.0) {
            return '—';
        }

        return rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.');
    }

    /**
     * @param  array<string, array<int, array{item:string,lines:array}>>  $recipeGroups
     * @param  array<string, array<int, string>>  $unitaireGroups
     * @param  array<int, array{group:string,material:string,qty:float,unit:string,confirm:bool,present:bool,note:string}>  $extraLines
     */
    private static function renderFiche(array $recipeGroups, array $unitaireGroups, array $extraLines, int $products, int $lines): string
    {
        $date = now()->format('Y-m-d H:i');
        $unitaireCount = array_sum(array_map('count', $unitaireGroups));
        $total = $products + $unitaireCount;
        $md = [];
        $md[] = '# Fiche paramètres — Ingrédients par produit (Le Cayenne)';
        $md[] = '';
        $md[] = "> **Owner : corrige les quantités, surtout les lignes ⚠️ À CONFIRMER.**";
        $md[] = '> Ce n\'est PAS une page blanche : chaque ligne est pré-remplie d\'après nos règles maison.';
        $md[] = "> Généré le {$date} par `php artisan raw-materials:fiche` — {$total} produits couverts "
            ."({$products} pré-remplis, {$unitaireCount} à l'unité), {$lines} lignes de recette.";
        $md[] = '>';
        $md[] = '> **Rappels gravés** : steak haché façonné maison = **75 g/pièce** (3 kg ≈ 40 pièces ; ';
        $md[] = '> incohérence 68 g vs 75 g à trancher ici) · sauce maison = **25 g/dose** · cheddar & jambon ';
        $md[] = '> comptés à la **pièce/tranche**. Les crudités (grammages) et les viandes restent ⚠️ à confirmer.';
        $md[] = '';
        $md[] = 'Légende statut : ✅ pré-rempli (règle stable) · ⚠️ À CONFIRMER (à corriger par l\'owner).';
        $md[] = '';

        foreach ($recipeGroups as $catName => $products2) {
            $md[] = "## {$catName}";
            $md[] = '';
            $md[] = '| Produit | Ingrédient | Quantité | Unité | Statut |';
            $md[] = '|---|---|---|---|---|';
            foreach ($products2 as $p) {
                foreach ($p['lines'] as $l) {
                    $status = $l['confirm'] ? '⚠️ À CONFIRMER' : '✅ pré-rempli';
                    $qty = self::fmtQty($l['qty']);
                    $md[] = "| {$p['item']} | {$l['name']} | {$qty} | {$l['unit']} | {$status} |";
                }
            }
            $md[] = '';
        }

        // [B-2] Section EXTRAS (suppléments payants mappés par groupe de nom).
        $md[] = '## Extras (suppléments payants)';
        $md[] = '';
        $md[] = 'Décrémentés par le moteur quand le client AJOUTE le supplément (résolus par';
        $md[] = 'nom de groupe, pas par id). Une matière absente de la baseline reste ⚠️ owner.';
        $md[] = '';
        $md[] = '| Extra (groupe) | Ingrédient | Quantité | Unité | Statut |';
        $md[] = '|---|---|---|---|---|';
        foreach ($extraLines as $e) {
            $status = ! $e['present']
                ? '⚠️ matière absente (owner)'
                : ($e['confirm'] ? '⚠️ À CONFIRMER' : '✅ pré-rempli');
            $qty = self::fmtQty($e['qty']);
            $md[] = "| {$e['group']} | {$e['material']} | {$qty} | {$e['unit']} | {$status} |";
        }
        $md[] = '';
        $md[] = '> **Restent OWNER-DATA — aucune ligne pré-remplie, partent en `skipped`** : les';
        $md[] = '> VIANDES choisies par variation/extra (Tacos, Assiettes, Bols — la protéine';
        $md[] = '> passe par une option non mappée, donc leur recette ci-dessus est SANS viande)';
        $md[] = '> et les suppléments sans matière connue (Champignons, Raclette, Emmental,';
        $md[] = '> Boursin, Légumes sautés, Option Gratiné…). À confirmer avant de brancher.';
        $md[] = '';

        // Section produits à l'unité / hors périmètre P1.
        $md[] = '## Comptés à l\'unité / hors périmètre P1 (à détailler plus tard)';
        $md[] = '';
        $md[] = 'Ces produits restent comptés à l\'unité par le stock existant (boissons, desserts, menus';
        $md[] = 'enfants) ou seront paramétrés dans une prochaine vague (bols, suppléments). **Aucune ligne';
        $md[] = 'matière pré-remplie** ici — déploiement progressif (plan RÉPONSES OWNER #6).';
        $md[] = '';
        foreach ($unitaireGroups as $catName => $names) {
            $md[] = "- **{$catName}** (".count($names).') : '.implode(', ', $names);
        }
        $md[] = '';
        $md[] = '---';
        $md[] = '**Rappel : steak haché 75 g gravé.** Corrige les ⚠️, puis on branche la consommation';
        $md[] = 'automatique depuis les tickets scellés (P2 — NF525 intouché, lecture des snapshots seulement).';
        $md[] = '';

        return implode("\n", $md);
    }
}
