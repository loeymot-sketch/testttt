<?php

namespace App\Console\Commands;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use App\Models\ItemWizardProfile;
use App\Models\ItemWizardStep;
use App\Models\RawMaterialRecipeLine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * [OWNER TACOS-XL 2026-08-24] Deux demandes du propriétaire, une seule opération de données :
 *
 *   1. Le tacos DEUX viandes (« Tacos L », #97) passe de 7,90 € à **8,90 €**.
 *   2. Le tacos TROIS viandes est un produit NOUVEAU : il n'existait pas en base. Il est créé
 *      sous le nom **« Tacos XL » à 10,90 €**, avec la MÊME photo que les autres tacos et
 *      « toute la logique » des tacos existants — trois viandes AU CHOIX comprises dans le prix,
 *      la sauce, les suppléments, la formule menu, la recette matière première.
 *
 * POURQUOI LE NOM « Tacos XL » — ce n'est pas un choix esthétique
 * ---------------------------------------------------------------
 * Le nombre de viandes n'est écrit nulle part comme un nombre : chaque surface le DÉDUIT. Trois
 * mécanismes indépendants, dont deux sont en zone gelée, connaissent déjà la table des tailles et
 * y lisent XL = 3 :
 *   · caisse — `public/js/pos-wizard.js` (FROZEN) : `detectViandeCount()`, `/tacos\s*xl\b/ → 3`
 *   · borne  — `resources/js/helpers/kioskTacosSize.js` : `SIZE_TO_VIANDE_COUNT.XL = 3`
 *   · cuisine— `KitchenTicketSymbolicFormatter::produitAndSize()` : `/\s+(XL|L|M)\s*$/`
 * Nommer le produit « Tacos XL » fait donc converger les trois sans TOUCHER une seule ligne de
 * zone gelée. Tout autre nom (« Tacos 3 viandes », « Triple Tacos ») aurait obligé à modifier
 * `pos-wizard.js`, qui est protégé — et le produit serait sorti du wizard à 1 viande.
 *
 * COMMENT LES TROIS VIANDES SONT RENDUES OBLIGATOIRES ET GRATUITES
 * ----------------------------------------------------------------
 * Un emplacement de viande = un attribut « Viande N » portant des variations à 0 €. Tacos M en a
 * un, Tacos L en a deux, Tacos XL en a **trois** (« Viande 1 » / « Viande 2 » / « Viande 3 »,
 * mêmes 7 viandes sous chacun). C'est cette structure — et rien d'autre — qui pilote :
 *   · le compteur de la borne : `KioskMenuService` publie `viande_count` = nombre d'attributs
 *     « Viande N » ayant des variations actives → 3 ;
 *   · la répartition au panier : la borne place la i-ème viande choisie sur le i-ème attribut
 *     (verrouillé par `tests/js/kioskWizardMultiViande.spec.js`, qui cite déjà « Tacos L/XL/XXL ») ;
 *   · l'obligation serveur : `MultiVariationConstraint` exige la présence de TOUT attribut à
 *     `min_select >= 1` porté par l'article. « Viande 3 » est donc passé à min 1 / max 1, comme
 *     « Viande 1 » et « Viande 2 ». Aucun autre article ne porte cet attribut (vérifié en base),
 *     la contrainte ne peut donc atteindre que le Tacos XL.
 *   · la gratuité : les variations sont à 0 €. Le prix des 3 viandes est DANS les 10,90 €. Seule
 *     une 4ᵉ viande passe par l'extra payant « Viande supplémentaire » @2,50, cloné à l'identique.
 *
 * Le prix reste scellé côté serveur (`PricingService`) : la caisse et la borne n'envoient que des
 * identifiants. Rien ici ne touche au chemin de l'argent, aux zones gelées ni à la chaîne NF525.
 *
 * IDEMPOTENT : ré-exécutable sans doublon (article par slug, variations par attribut+nom, extras
 * par groupe+nom, formules par produit+rôle, recette par matière première, étape wizard par clé).
 */
class EnsureTacosXl3ViandesCommand extends Command
{
    protected $signature = 'menu:ensure-tacos-xl {--dry-run : compter sans écrire}';

    protected $description = 'Tacos L (2 viandes) à 8,90 € + création du Tacos XL (3 viandes au choix comprises) à 10,90 €, même photo et même logique. Idempotent.';

    /** Le tacos DEUX viandes déjà en carte — sert de gabarit ET voit son prix corrigé. */
    public const SOURCE_SLUG = 'tacos-l';

    public const SOURCE_NAME = 'Tacos L';

    /** [OWNER 2026-08-24] « le prix de tacos 2 viande à 8,90 € ». */
    public const SOURCE_NEW_PRICE = 8.90;

    public const TARGET_NAME = 'Tacos XL';

    public const TARGET_SLUG = 'tacos-xl';

    /** [OWNER 2026-08-24] « le tacos trois viandes à dix euros 90 ». */
    public const TARGET_PRICE = 10.90;

    /**
     * Rédigée sur le patron EXACT de Tacos M / Tacos L (« Galette de blé, N viandes au choix,
     * frites maison et sauce. »). Ce n'est pas cosmétique : `pos-wizard.js` lit « N viandes »
     * dans la description en second recours si le nom ne suffit pas — les deux voies concordent.
     */
    public const TARGET_DESCRIPTION = 'Galette de blé, 3 viandes au choix, frites maison et sauce.';

    /** Attributs résolus par NOM (robuste aux ids ; les steps wizard ciblent ces libellés). */
    public const ATTR_VIANDE_1_NAME = 'Viande 1';

    public const ATTR_VIANDE_3_NAME = 'Viande 3';

    /** Clé de l'étape wizard du 3ᵉ emplacement, jumelle de `viande_2`. */
    public const STEP_KEY_VIANDE_3 = 'viande_3';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $stats = self::ensure($dry);

        $prefix = $dry ? '[dry-run] ' : '';
        if (($stats['skipped'] ?? null) !== null) {
            $this->warn("{$prefix}Rien fait — {$stats['skipped']}");

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%sTacos L %s€ · Tacos XL %s — variations:%d extras:%d formules:%d recette:%d étape wizard:%d',
            $prefix,
            number_format(self::SOURCE_NEW_PRICE, 2, ',', ' '),
            $stats['target_id'] !== null ? '#'.$stats['target_id'] : 'À CRÉER',
            $stats['variations'],
            $stats['extras'],
            $stats['addons'],
            $stats['recipe_lines'],
            $stats['wizard_steps'],
        ));

        return self::SUCCESS;
    }

    /**
     * @return array{
     *   skipped?: string, target_id?: int|null, price_updated: bool, variations: int,
     *   extras: int, addons: int, recipe_lines: int, wizard_steps: int
     * }
     */
    public static function ensure(bool $dryRun = false): array
    {
        $stats = [
            'target_id' => null, 'price_updated' => false, 'variations' => 0,
            'extras' => 0, 'addons' => 0, 'recipe_lines' => 0, 'wizard_steps' => 0,
        ];

        $source = Item::withoutGlobalScopes()
            ->where(fn ($q) => $q->where('slug', self::SOURCE_SLUG)->orWhere('name', self::SOURCE_NAME))
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->first();

        if (! $source) {
            // Base sans le menu Le Cayenne (installation neuve, fixture partielle) : on ne
            // FABRIQUE pas un tacos à partir de rien — sans gabarit, « la même logique » serait
            // devinée. Mieux vaut ne rien faire que poser un produit à moitié câblé en carte.
            $stats['skipped'] = 'article « '.self::SOURCE_NAME.' » introuvable — aucun gabarit à cloner.';

            return $stats;
        }

        // ─── 1. Le tacos 2 viandes passe à 8,90 € ────────────────────────────────────────────
        if ((float) $source->price !== self::SOURCE_NEW_PRICE) {
            $stats['price_updated'] = true;
            if (! $dryRun) {
                $source->forceFill(['price' => self::SOURCE_NEW_PRICE])->save();
            }
        }

        // ─── 2. Le 3ᵉ emplacement de viande, obligatoire et unique comme les deux autres ─────
        $viande3 = self::ensureViande3Attribute($dryRun);

        // ─── 3. L'article ────────────────────────────────────────────────────────────────────
        $target = self::ensureItem($source, $dryRun);
        if ($target === null) {
            // Dry-run sur une base où l'article n'existe pas encore : impossible de cloner dans
            // le vide, mais annoncer « 0 » laisserait croire qu'il n'y a rien à faire. On compte
            // donc ce qui SERAIT créé, d'après le gabarit.
            $stats['item_created'] = true;
            $meats = ItemVariation::withoutGlobalScopes()
                ->where('item_id', $source->id)
                ->where('item_attribute_id', self::attrIdByName(self::ATTR_VIANDE_1_NAME))
                ->where('status', Status::ACTIVE)
                ->whereNull('deleted_at')
                ->count();
            $stats['variations'] = ItemVariation::withoutGlobalScopes()
                ->where('item_id', $source->id)->where('status', Status::ACTIVE)
                ->whereNull('deleted_at')->count() + $meats;
            $stats['extras'] = ItemExtra::withoutGlobalScopes()
                ->where('item_id', $source->id)->where('status', Status::ACTIVE)
                ->whereNull('deleted_at')->count();
            $stats['addons'] = DB::table('item_addons')
                ->where('item_id', $source->id)->whereNull('deleted_at')->count();
            $stats['recipe_lines'] = RawMaterialRecipeLine::withoutGlobalScopes()
                ->where('subject_type', Item::class)->where('subject_id', $source->id)->count();
            $stats['wizard_steps'] = self::ensureViande3WizardStep((int) $source->item_category_id, true);

            return $stats;
        }
        $stats['target_id'] = (int) $target->id;

        // ─── 4. Choix et personnalisation, clonés à l'identique depuis le Tacos L ────────────
        $stats['variations'] = self::cloneVariations($source, $target, $viande3, $dryRun);
        $stats['extras'] = self::cloneExtras((int) $source->id, (int) $target->id, $dryRun);
        $stats['addons'] = self::cloneAddons((int) $source->id, (int) $target->id, $dryRun);
        $stats['recipe_lines'] = self::cloneRecipeLines((int) $source->id, (int) $target->id, $dryRun);
        $stats['wizard_steps'] = self::ensureViande3WizardStep((int) $source->item_category_id, $dryRun);

        return $stats;
    }

    /**
     * « Viande 3 » existe déjà en base (id 3) mais en min 0 / max 1 et SANS aucun article qui le
     * porte — c'était un emplacement dormant. On l'aligne sur « Viande 2 » (min 1 / max 1) pour
     * que les trois viandes du Tacos XL soient RÉELLEMENT obligatoires côté serveur.
     *
     * `min_select` vit sur l'attribut, qui est PARTAGÉ entre articles : le resserrer ne peut
     * atteindre que les articles qui portent des variations sous cet attribut. Ici, le Tacos XL
     * et lui seul (constaté en base : zéro article sous « Viande 3 » avant cette commande).
     * Même précaution que l'incident borne du 2026-08-01 documenté dans EnsureCayenneMixteCommand.
     */
    private static function ensureViande3Attribute(bool $dryRun): ?ItemAttribute
    {
        $attr = ItemAttribute::where('name', self::ATTR_VIANDE_3_NAME)->first();

        if (! $attr) {
            if ($dryRun) {
                return null;
            }
            $attr = ItemAttribute::create([
                'name' => self::ATTR_VIANDE_3_NAME,
                'min_select' => 1,
                'max_select' => 1,
                'allow_repeat' => false,
                'is_available' => true,
                'status' => Status::ACTIVE,
            ]);

            return $attr;
        }

        if (! $dryRun && ((int) $attr->min_select !== 1 || (int) $attr->max_select !== 1)) {
            $attr->forceFill([
                'min_select' => 1,
                'max_select' => 1,
                'allow_repeat' => false,
                'status' => Status::ACTIVE,
                'is_available' => true,
            ])->save();
        }

        return $attr;
    }

    /**
     * Crée l'article en copiant les colonnes de présentation du Tacos L (catégorie, TVA, poste
     * cuisine, canaux, allergènes, régimes) — c'est ce qui garantit qu'il apparaît AU MÊME
     * ENDROIT sur les trois surfaces et part au MÊME poste de cuisson.
     *
     * La photo n'est pas une colonne : elle se résout par `slug` via `config/menu_images.php`,
     * où `tacos-xl` pointe sur le MÊME fichier que `tacos-m` / `tacos-l` (owner : « la même photo »).
     */
    private static function ensureItem(Item $source, bool $dryRun): ?Item
    {
        $existing = Item::withoutGlobalScopes()->withTrashed()->where('slug', self::TARGET_SLUG)->first();

        if ($existing) {
            if (! $dryRun) {
                if ($existing->trashed()) {
                    $existing->restore();
                }
                // Ré-affirme prix / libellés / disponibilité : la commande est le point de vérité
                // du produit, pas un « create if missing » qui laisserait dériver un prix corrigé
                // à la main puis re-corrigé ici. Le reste (photo, choix) est traité plus bas.
                $existing->forceFill([
                    'name' => self::TARGET_NAME,
                    'price' => self::TARGET_PRICE,
                    'description' => self::TARGET_DESCRIPTION,
                    'status' => Status::ACTIVE,
                    'is_available' => 1,
                ])->save();
            }

            return $existing;
        }

        if ($dryRun) {
            return null;
        }

        $payload = $source->only([
            'item_category_id', 'tax_id', 'item_type', 'is_featured', 'is_upsell',
            'is_chef_pick', 'is_spicy', 'is_vegetarian', 'is_pork_free', 'is_halal',
            'is_gluten_free', 'channels', 'allergen_flags', 'kiosk_emoji', 'kds_station',
        ]);
        $payload['name'] = self::TARGET_NAME;
        $payload['slug'] = self::TARGET_SLUG;
        $payload['price'] = self::TARGET_PRICE;
        $payload['description'] = self::TARGET_DESCRIPTION;
        $payload['status'] = Status::ACTIVE;
        $payload['is_available'] = 1;
        // [OWNER 2026-08-24] « c'est un nouveau » — la borne pose un bandeau NOUVEAU sur les
        // articles `is_new` (KioskCategoriesComponent). Le tacos 3 viandes arrive en carte : il
        // le porte, ses deux aînés non.
        $payload['is_new'] = 1;
        // Se range juste après le Tacos L dans la grille (M, L, puis XL) sur les trois surfaces.
        $payload['order'] = (int) $source->order + 1;

        return Item::create($payload);
    }

    /**
     * Clone les choix du Tacos L et peuple le 3ᵉ emplacement de viande.
     *
     * Le 3ᵉ emplacement reçoit exactement la même liste que le 1ᵉʳ : le client doit pouvoir
     * composer trois fois n'importe laquelle des 7 viandes (y compris trois fois la même — la
     * borne place alors une variation distincte sous chaque attribut, ce qui satisfait
     * `allow_repeat = false` sans brider le client).
     *
     * ORDRE DE CRÉATION : les trois emplacements viande d'ABORD, la sauce ensuite. Les payloads
     * groupent les variations par ordre d'insertion, et la caisse (`pos-wizard.js`, gelé) reporte
     * les viandes choisies dans les listes déroulantes du modal **dans l'ordre du DOM**. Créer la
     * « Viande 3 » après la sauce l'intercalerait derrière — ça marcherait encore, mais par
     * chance. Contiguës, les trois emplacements se lisent dans l'ordre partout : caisse, borne,
     * admin.
     */
    private static function cloneVariations(Item $source, Item $target, ?ItemAttribute $viande3, bool $dryRun): int
    {
        $created = 0;

        $rows = ItemVariation::withoutGlobalScopes()
            ->with('itemAttribute')
            ->where('item_id', $source->id)
            ->where('status', Status::ACTIVE)
            ->whereNull('deleted_at')
            ->get();

        $viande1Id = self::attrIdByName(self::ATTR_VIANDE_1_NAME);
        $isMeatSlot = fn ($row): bool => stripos((string) optional($row->itemAttribute)->name, 'viande') !== false
            || (int) $row->item_attribute_id === $viande1Id;

        $clone = function ($row, int $attributeId) use ($target, $dryRun): int {
            return self::ensureVariation(
                (int) $target->id,
                $attributeId,
                (string) $row->name,
                (float) $row->price,
                $row->visible_on,
                (int) $row->status,
                $dryRun,
            );
        };

        // 1. Les emplacements viande du gabarit (Viande 1, Viande 2), dans l'ordre.
        foreach ($rows->filter($isMeatSlot)->sortBy('item_attribute_id') as $row) {
            $created += $clone($row, (int) $row->item_attribute_id);
        }

        // 2. Le 3ᵉ emplacement, garni des mêmes viandes que le 1ᵉʳ.
        if ($viande3 !== null && $viande1Id !== 0) {
            foreach ($rows->where('item_attribute_id', $viande1Id) as $meat) {
                $created += $clone($meat, (int) $viande3->id);
            }
        }

        // 3. Tout le reste (sauce…).
        foreach ($rows->reject($isMeatSlot) as $row) {
            $created += $clone($row, (int) $row->item_attribute_id);
        }

        return $created;
    }

    /** Résout un attribut par NOM plutôt que par id — les ids ne sont pas garantis entre bases. */
    private static function attrIdByName(string $name): int
    {
        return (int) (ItemAttribute::where('name', $name)->value('id') ?? 0);
    }

    private static function ensureVariation(
        int $itemId,
        int $attributeId,
        string $name,
        float $price,
        mixed $visibleOn,
        int $status,
        bool $dryRun
    ): int {
        $exists = ItemVariation::withoutGlobalScopes()
            ->where('item_id', $itemId)
            ->where('item_attribute_id', $attributeId)
            ->where('name', $name)
            ->whereNull('deleted_at')
            ->exists();

        if ($exists) {
            return 0;
        }
        if ($dryRun) {
            return 1;
        }

        ItemVariation::create([
            'item_id' => $itemId,
            'item_attribute_id' => $attributeId,
            'name' => $name,
            'price' => $price,
            'status' => $status,
            'visible_on' => $visibleOn,
        ]);

        return 1;
    }

    /** Suppléments payants, crudités, sauce supplémentaire — la personnalisation, à l'identique. */
    private static function cloneExtras(int $sourceItemId, int $targetItemId, bool $dryRun): int
    {
        $created = 0;
        $rows = ItemExtra::withoutGlobalScopes()
            ->where('item_id', $sourceItemId)
            ->where('status', Status::ACTIVE)
            ->whereNull('deleted_at')
            ->get();

        foreach ($rows as $row) {
            $exists = ItemExtra::withoutGlobalScopes()
                ->where('item_id', $targetItemId)
                ->where('group_label', $row->group_label)
                ->where('name', $row->name)
                ->whereNull('deleted_at')
                ->exists();
            if ($exists) {
                continue;
            }
            $created++;
            if ($dryRun) {
                continue;
            }
            ItemExtra::create([
                'item_id' => $targetItemId,
                'name' => $row->name,
                'description' => $row->description,
                'status' => $row->status,
                'price' => $row->price,
                'visible_on' => $row->visible_on,
                'group_label' => $row->group_label,
                'is_available' => $row->is_available,
            ]);
        }

        return $created;
    }

    /**
     * Formules « Menu (Frites + Boisson) », « Frites seules », « Boisson seule ».
     *
     * Sans ces lignes, le bloc Formule du wizard reste VIDE et le tacos ne peut pas être passé en
     * menu — exactement le trou constaté sur le Sandwich Classique le 2026-08-19.
     */
    private static function cloneAddons(int $sourceItemId, int $targetItemId, bool $dryRun): int
    {
        $created = 0;
        $rows = DB::table('item_addons')
            ->where('item_id', $sourceItemId)
            ->whereNull('deleted_at')
            ->get();

        foreach ($rows as $row) {
            $exists = DB::table('item_addons')
                ->where('item_id', $targetItemId)
                ->where('addon_item_id', $row->addon_item_id)
                ->where('role', $row->role)
                ->whereNull('deleted_at')
                ->exists();
            if ($exists) {
                continue;
            }
            $created++;
            if ($dryRun) {
                continue;
            }
            DB::table('item_addons')->insert([
                'item_id' => $targetItemId,
                'addon_item_id' => $row->addon_item_id,
                'addon_item_variation' => $row->addon_item_variation,
                'role' => $row->role,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $created;
    }

    /**
     * Recette matière première (galette, frites, sauce…) — la base du tacos, identique.
     *
     * La VIANDE n'est volontairement pas ici : elle est déduite du composition_snapshot scellé à
     * la vente (MeatPortionCalculator / MeatMaterialResolver), donc du nombre d'emplacements
     * réellement choisis. Recopier la base sans la viande est le comportement correct.
     */
    private static function cloneRecipeLines(int $sourceItemId, int $targetItemId, bool $dryRun): int
    {
        $created = 0;

        $rows = RawMaterialRecipeLine::withoutGlobalScopes()
            ->where('subject_type', Item::class)
            ->where('subject_id', $sourceItemId)
            ->get();

        foreach ($rows as $row) {
            $exists = RawMaterialRecipeLine::withoutGlobalScopes()
                ->where('subject_type', Item::class)
                ->where('subject_id', $targetItemId)
                ->where('raw_material_id', $row->raw_material_id)
                ->exists();
            if ($exists) {
                continue;
            }
            $created++;
            if ($dryRun) {
                continue;
            }
            RawMaterialRecipeLine::create([
                'branch_id' => $row->branch_id,
                'subject_type' => Item::class,
                'subject_id' => $targetItemId,
                'subject_group' => $row->subject_group,
                'raw_material_id' => $row->raw_material_id,
                'qty' => $row->qty,
                'note' => $row->note,
            ]);
        }

        return $created;
    }

    /**
     * Ajoute l'étape `viande_3` au profil wizard de la catégorie Tacos, jumelle de `viande_2`.
     *
     * Le profil est porté par la CATÉGORIE : les Tacos M et L héritent donc aussi de l'étape.
     * C'est sans effet pour eux, et ce n'est pas une supposition — c'est déjà le cas aujourd'hui
     * pour `viande_2`, que le Tacos M projette avec ZÉRO choix (vérifié sur la base : Tacos M →
     * `viande_2 choices=0`). Une étape sans choix est écartée à l'affichage, sur la borne comme
     * à la caisse. Le 3ᵉ emplacement n'apparaît donc QUE sur le produit qui le porte.
     */
    private static function ensureViande3WizardStep(int $categoryId, bool $dryRun): int
    {
        $profile = ItemWizardProfile::withoutGlobalScopes()
            ->where('item_category_id', $categoryId)
            ->where('is_published', true)
            ->orderByDesc('version')
            ->first();

        if (! $profile) {
            return 0;
        }

        $exists = ItemWizardStep::where('profile_id', $profile->id)
            ->where('step_key', self::STEP_KEY_VIANDE_3)
            ->exists();
        if ($exists) {
            return 0;
        }
        if ($dryRun) {
            return 1;
        }

        $after = ItemWizardStep::where('profile_id', $profile->id)
            ->where('step_key', 'viande_2')
            ->first();
        $position = $after ? (int) $after->position : 2;

        // Décale les étapes suivantes pour insérer le 3ᵉ emplacement juste après le 2ᵉ, et non
        // en fin de parcours (le client choisit ses viandes d'affilée, puis la sauce).
        ItemWizardStep::where('profile_id', $profile->id)
            ->where('position', '>', $position)
            ->increment('position');

        ItemWizardStep::create([
            'profile_id' => $profile->id,
            'step_key' => self::STEP_KEY_VIANDE_3,
            'label' => 'Viande 3',
            'source_type' => 'item_attribute',
            'source_ref' => self::ATTR_VIANDE_3_NAME,
            'source_item_attribute_id' => null,
            // Jumelle STRICTE de `viande_2` (min 0 / max 4) : la contrainte d'obligation est
            // portée par l'attribut, pas par l'étape. Diverger ici rendrait l'étape bloquante
            // sur les Tacos M et L, qui héritent du profil de catégorie sans porter l'attribut.
            'min_select' => 0,
            'max_select' => 4,
            'allow_repeat' => false,
            'visible_on' => ['pos', 'kiosk'],
            'stockable_choices' => false,
            'position' => $position + 1,
            'is_active' => true,
            'addon_role' => null,
        ]);

        return 1;
    }
}
