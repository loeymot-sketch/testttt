<?php

namespace App\Console\Commands;

use App\Enums\Status;
use App\Events\CatalogChanged;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemCategory;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use App\Models\RawMaterialRecipeLine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * [OWNER CATALOGUE 2026-11-11] Demandes du propriétaire, en une seule opération de données,
 * rejouable sur n'importe quelle base (locale, production) : tout est résolu par SLUG ou par NOM,
 * jamais par identifiant (les ids diffèrent entre la base locale et la production).
 *
 *   1. « 5 Tenders » à 5,00 € — produit simple, « il n'y a pas de sauce, il n'y a rien » :
 *      aucune variation, aucun supplément, aucune formule. Rangé dans une catégorie « Tenders »
 *      (créée si absente) pour apparaître dans la barre des catégories de toutes les surfaces.
 *   2. « Sandwich 2 Viandes » à 9,00 € — « le sandwich classique, personnalisable, qu'on pourra
 *      mettre en deux viandes ». Produit NOUVEAU cloné du Sandwich Classique (sauces, pain,
 *      crudités, suppléments, formules, recette), avec deux emplacements viande comprises dans
 *      le prix (« Viande 1 » + « Viande 2 », mêmes viandes). Le Sandwich Classique reste à son
 *      prix et à une viande. Le nombre de viandes incluses est publié par
 *      config/menu_meat_rules.php (`Sandwich 2 Viandes` => 2) ; la caisse le lit aussi dans la
 *      description (« 2 viandes au choix »), comme pour le Méga et le Terminator.
 *   3. Supplément « Façon Cayenne » à 1,50 € (base fromage et crème fraîche) sur chaque sandwich
 *      de la catégorie Sandwichs qui n'est pas déjà un Cayenne. Supplément ordinaire (groupe
 *      `supplement`) : facturé par PricingService comme le cheddar ou le jambon, imprimé en
 *      cuisine sous son nom.
 *   4. Libellés : steaks « frais », « base fromage et crème fraîche » au lieu de « mélange
 *      fromager » / « sauce fromagère », plus de « Galette croustillante » redondant sur les
 *      galettes. Descriptions seulement : les NOMS des viandes et des sauces ne changent pas
 *      (ils servent de clé aux photos, au ticket cuisine et au calcul des portions).
 *
 * Rien ici ne touche au chemin de l'argent (PricingService gelé facture ce que la commande
 * contient, depuis ces lignes), aux zones gelées ni à la chaîne NF525. Aucun article n'est
 * supprimé : un produit retiré garde son historique fiscal.
 *
 * IDEMPOTENT : article par slug, variation par article+attribut+nom, supplément par
 * article+groupe+nom, formule par article+produit+rôle, recette par matière première.
 */
class SyncOwnerCatalog20261111Command extends Command
{
    protected $signature = 'menu:owner-2026-11-11 {--dry-run : compter sans écrire}';

    protected $description = '5 Tenders 5 €, Sandwich 2 Viandes 9 €, supplément Façon Cayenne 1,50 €, libellés steak frais / base fromage et crème fraîche. Idempotent.';

    public const TENDERS_CATEGORY_SLUG = 'tenders';

    public const TENDERS_CATEGORY_NAME = 'Tenders';

    public const TENDERS_SLUG = 'tenders-5';

    public const TENDERS_NAME = '5 Tenders';

    public const TENDERS_PRICE = 5.00;

    public const TENDERS_DESCRIPTION = '5 tenders de poulet croustillants.';

    public const SANDWICH_SOURCE_SLUG = 'sandwich-classique';

    public const SANDWICH_SOURCE_CATEGORY = 'Sandwichs';

    public const SANDWICH2_SLUG = 'sandwich-2-viandes';

    public const SANDWICH2_NAME = 'Sandwich 2 Viandes';

    public const SANDWICH2_PRICE = 9.00;

    /** Sandwich de référence à deux viandes : ses viandes sont celles du Sandwich 2 Viandes. */
    public const SANDWICH2_MEATS_REFERENCE_SLUG = 'terminator';

    /** « 2 viandes au choix » : la caisse (gelée) lit ce nombre dans la description, comme pour le Méga. */
    public const SANDWICH2_DESCRIPTION = '2 viandes au choix, crudités et sauces au choix.';

    public const FACON_CAYENNE_NAME = 'Façon Cayenne';

    public const FACON_CAYENNE_PRICE = 1.50;

    public const FACON_CAYENNE_DESCRIPTION = 'Base fromage et crème fraîche, comme le Cayenne.';

    public const FACON_CAYENNE_GROUP = 'supplement';

    /**
     * Descriptions réécrites. Clé = slug ; valeur = [ancienne attendue (null = toujours), nouvelle].
     * Une description déjà modifiée à la main en admin (≠ ancienne attendue) n'est PAS écrasée :
     * le geste du restaurant prime.
     *
     * @var array<string, array{0: string|null, 1: string}>
     */
    public const DESCRIPTIONS = [
        'cayenne' => [
            'Poulet mariné, cheddar, oignons rouges, tomates, salade, mélange maison de fromage et crème fraîche.',
            'Poulet mariné, cheddar, oignons rouges, tomates, salade, base fromage et crème fraîche.',
        ],
        'sandwich-classique' => [
            'Poulet mariné, crudités et sauce au choix, comme le Cayenne — sans le mélange fromager.',
            'Poulet mariné, crudités et sauce au choix, comme le Cayenne — sans la base fromage et crème fraîche.',
        ],
        'supreme' => [
            'Steak haché, cordon bleu, cheddar, oignons rouges, tomates, salade, sauce au choix.',
            'Steak haché frais, cordon bleu, cheddar, oignons rouges, tomates, salade, sauce au choix.',
        ],
        // « galette croustillante », jamais « galette classique » : c'est le mot du propriétaire.
        'galette-cayenne' => [
            'Galette croustillante, sauce Cayenne maison incluse et crudités fraîches.',
            'Galette croustillante, sauce Cayenne maison (base fromage et crème fraîche) incluse et crudités fraîches.',
        ],
        'galette-classique' => [
            'Galette croustillante, crudités et sauce au choix, comme la Galette Cayenne — sans la sauce fromagère maison.',
            'Galette croustillante, crudités et sauce au choix, comme la Galette Cayenne — sans la base fromage et crème fraîche.',
        ],
        'cheese-burger' => [
            'Steak, cheddar, salade, tomate, oignon, sauce.',
            'Steak haché frais, cheddar, salade, tomate, oignon, sauce.',
        ],
        'double-cheese' => [
            '2 steaks, 2 cheddars, salade, tomate, oignon, sauce.',
            '2 steaks hachés frais, 2 cheddars, salade, tomate, oignon, sauce.',
        ],
        'grill-burger' => [
            '2 steaks, 2 cheddars, jambon de dinde, salade, tomate, oignon, sauce.',
            '2 steaks hachés frais, 2 cheddars, jambon de dinde, salade, tomate, oignon, sauce.',
        ],
        'big-burger' => [
            '3 steaks, 3 cheddars, 2 jambons de dinde, salade, tomate, oignon, sauce.',
            '3 steaks hachés frais, 3 cheddars, 2 jambons de dinde, salade, tomate, oignon, sauce.',
        ],
        'sauce-fromagere' => [
            null,
            'Sauce maison : base fromage et crème fraîche.',
        ],
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $stats = self::ensure($dry);
        $prefix = $dry ? '[dry-run] ' : '';

        foreach ($stats['notes'] as $note) {
            $this->warn($prefix.$note);
        }

        $this->info(sprintf(
            '%s5 Tenders %s · Sandwich 2 Viandes %s (variations:%d suppléments:%d formules:%d recette:%d) · Façon Cayenne sur %d sandwich(s) · descriptions:%d',
            $prefix,
            $stats['tenders_id'] !== null ? '#'.$stats['tenders_id'] : 'À CRÉER',
            $stats['sandwich2_id'] !== null ? '#'.$stats['sandwich2_id'] : 'À CRÉER',
            $stats['variations'],
            $stats['extras'],
            $stats['addons'],
            $stats['recipe_lines'],
            $stats['facon_cayenne'],
            $stats['descriptions'],
        ));

        return self::SUCCESS;
    }

    /**
     * @return array{tenders_id: int|null, sandwich2_id: int|null, variations: int, extras: int,
     *   addons: int, recipe_lines: int, facon_cayenne: int, descriptions: int, notes: list<string>,
     *   touched: list<int>}
     */
    public static function ensure(bool $dryRun = false): array
    {
        $stats = [
            'tenders_id' => null, 'sandwich2_id' => null, 'variations' => 0, 'extras' => 0,
            'addons' => 0, 'recipe_lines' => 0, 'facon_cayenne' => 0, 'descriptions' => 0,
            'notes' => [], 'touched' => [],
        ];

        // Base sans la carte Le Cayenne (installation neuve, base de test vide) : on ne fabrique
        // rien à partir de rien — la migration qui appelle cette commande reste alors sans effet.
        if (! Item::query()->where('slug', 'cayenne')->whereNull('deleted_at')->exists()) {
            $stats['notes'][] = 'Carte Le Cayenne absente (aucun article « cayenne ») — rien fait.';

            return $stats;
        }

        $run = function () use ($dryRun, &$stats): void {
            self::ensureTenders($dryRun, $stats);
            self::ensureSandwich2Viandes($dryRun, $stats);
            self::ensureFaconCayenne($dryRun, $stats);
            self::ensureDescriptions($dryRun, $stats);
        };

        if ($dryRun) {
            $run();

            return $stats;
        }

        DB::transaction($run);

        // Après la transaction : les écrans (borne, caisse, site) rechargent la carte. Le trait
        // DispatchableAfterCommit garantit de toute façon l'émission après validation.
        foreach (array_unique($stats['touched']) as $itemId) {
            CatalogChanged::dispatch('item', (int) $itemId, 'updated');
        }

        return $stats;
    }

    // ─── 1. 5 Tenders ────────────────────────────────────────────────────────────────────────

    private static function ensureTenders(bool $dryRun, array &$stats): void
    {
        $category = ItemCategory::withTrashed()
            ->where('slug', self::TENDERS_CATEGORY_SLUG)
            ->first();

        if (! $category && ! $dryRun) {
            // Même gabarit que les Desserts : produits simples, ajoutés au panier sans assistant.
            $category = ItemCategory::create([
                'name' => self::TENDERS_CATEGORY_NAME,
                'slug' => self::TENDERS_CATEGORY_SLUG,
                'description' => 'Tenders de poulet croustillants',
                'status' => Status::ACTIVE,
                // Après Bols (6) et Frites (7), avant Desserts (9) et Boissons (10).
                'sort' => 8,
                'wizard_template' => 'simple',
                'has_menu' => 0,
                'default_menu_kiosk' => 0,
                'sauce_included_menu' => 0,
                'kiosk_upsell_include' => 0,
                'kiosk_upsell_skip_after_cart' => 0,
            ]);
        } elseif ($category && ! $dryRun) {
            if ($category->trashed()) {
                $category->restore();
            }
            if ((int) $category->status !== Status::ACTIVE) {
                $category->forceFill(['status' => Status::ACTIVE])->save();
            }
        }

        $template = Item::query()
            ->where('slug', 'cayenne')->whereNull('deleted_at')->first();

        $existing = Item::withTrashed()->where('slug', self::TENDERS_SLUG)->first();

        if ($dryRun) {
            $stats['tenders_id'] = $existing?->id;

            return;
        }

        $fields = [
            'name' => self::TENDERS_NAME,
            'price' => self::TENDERS_PRICE,
            'description' => self::TENDERS_DESCRIPTION,
            'status' => Status::ACTIVE,
            'is_available' => 1,
            'item_category_id' => $category->id,
        ];

        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }
            $existing->forceFill($fields)->save();
            $item = $existing;
        } else {
            $payload = $template
                ? $template->only(['tax_id', 'item_type', 'channels', 'kds_station', 'is_halal'])
                : ['item_type' => 10];
            $item = Item::create($payload + $fields + [
                'slug' => self::TENDERS_SLUG,
                'is_new' => 1,
                'is_featured' => 0,
                'order' => 1,
            ]);
        }

        // « Juste les tenders » : un produit simple ne porte AUCUNE option. Si une option a été
        // ajoutée à la main, elle est désactivée (jamais supprimée) pour que la borne, la caisse
        // et le site l'ajoutent au panier d'un geste, au prix scellé de 5,00 €.
        ItemVariation::query()->where('item_id', $item->id)
            ->where('status', Status::ACTIVE)->update(['status' => Status::INACTIVE]);
        ItemExtra::query()->where('item_id', $item->id)
            ->where('status', Status::ACTIVE)->update(['status' => Status::INACTIVE]);

        $stats['tenders_id'] = (int) $item->id;
        $stats['touched'][] = (int) $item->id;
    }

    // ─── 2. Sandwich 2 Viandes ───────────────────────────────────────────────────────────────

    private static function ensureSandwich2Viandes(bool $dryRun, array &$stats): void
    {
        $sandwichCategoryId = (int) (ItemCategory::query()
            ->where('name', self::SANDWICH_SOURCE_CATEGORY)->whereNull('deleted_at')->value('id') ?? 0);

        $source = Item::query()
            ->where('slug', self::SANDWICH_SOURCE_SLUG)
            ->whereNull('deleted_at')
            ->when($sandwichCategoryId > 0, fn ($q) => $q->where('item_category_id', $sandwichCategoryId))
            ->orderBy('id')
            ->first();

        if (! $source) {
            $stats['notes'][] = 'Sandwich 2 Viandes : Sandwich Classique introuvable — aucun gabarit, rien créé.';

            return;
        }

        $viande1 = ItemAttribute::where('name', 'Viande 1')->first();
        $viande2 = ItemAttribute::where('name', 'Viande 2')->first();
        if (! $viande1 || ! $viande2) {
            $stats['notes'][] = 'Sandwich 2 Viandes : attributs « Viande 1 » / « Viande 2 » absents — rien créé.';

            return;
        }

        $existing = Item::withTrashed()->where('slug', self::SANDWICH2_SLUG)->first();

        if ($dryRun && ! $existing) {
            $stats['variations'] = ItemVariation::query()->where('item_id', $source->id)
                ->where('status', Status::ACTIVE)->whereNull('deleted_at')->count()
                + ItemVariation::query()->where('item_id', $source->id)
                    ->where('item_attribute_id', $viande1->id)->where('status', Status::ACTIVE)
                    ->whereNull('deleted_at')->count();

            return;
        }

        $fields = [
            'name' => self::SANDWICH2_NAME,
            'price' => self::SANDWICH2_PRICE,
            'description' => self::SANDWICH2_DESCRIPTION,
            'status' => Status::ACTIVE,
            'is_available' => 1,
        ];

        if ($existing) {
            if (! $dryRun) {
                if ($existing->trashed()) {
                    $existing->restore();
                }
                $existing->forceFill($fields)->save();
            }
            $target = $existing;
        } else {
            $payload = $source->only([
                'item_category_id', 'tax_id', 'item_type', 'is_upsell', 'is_chef_pick', 'is_spicy',
                'is_vegetarian', 'is_pork_free', 'is_halal', 'is_gluten_free', 'channels',
                'allergen_flags', 'kiosk_emoji', 'kds_station',
            ]);
            $target = Item::create($payload + $fields + [
                'slug' => self::SANDWICH2_SLUG,
                'is_new' => 1,
                'is_featured' => 0,
                'order' => (int) $source->order + 1,
            ]);
        }

        $stats['sandwich2_id'] = (int) $target->id;
        $stats['touched'][] = (int) $target->id;

        // Variations : les viandes d'abord (Viande 1 puis Viande 2, mêmes viandes), le reste
        // ensuite — la caisse gelée lit les emplacements dans l'ordre du DOM (cf. Tacos XL).
        $rows = ItemVariation::query()
            ->where('item_id', $source->id)->where('status', Status::ACTIVE)->whereNull('deleted_at')
            ->orderBy('id')->get();

        $meats = $rows->where('item_attribute_id', $viande1->id);
        foreach ($meats as $row) {
            $stats['variations'] += self::ensureVariation((int) $target->id, (int) $viande1->id, $row, $dryRun);
        }
        foreach ($meats as $row) {
            $stats['variations'] += self::ensureVariation((int) $target->id, (int) $viande2->id, $row, $dryRun);
        }
        foreach ($rows->whereNotIn('item_attribute_id', [$viande1->id, $viande2->id]) as $row) {
            $stats['variations'] += self::ensureVariation((int) $target->id, (int) $row->item_attribute_id, $row, $dryRun);
        }

        $stats['variations'] += self::ensureMeatsFromReference((int) $target->id, $viande1, $viande2, $dryRun, $stats);

        $stats['extras'] += self::cloneExtras((int) $source->id, (int) $target->id, $dryRun);
        $stats['addons'] += self::cloneAddons((int) $source->id, (int) $target->id, $dryRun);
        $stats['recipe_lines'] += self::cloneRecipeLines((int) $source->id, (int) $target->id, $dryRun);
    }

    /**
     * [OWNER 2026-11-11 · correctif du soir] Les viandes du Sandwich 2 Viandes = celles du sandwich de référence à deux
     * viandes (Terminator, 9,00 € aussi). Copié sur le Sandwich Classique, le produit n'offrait en ligne et à la borne
     * QUE « Poulet mariné » (le Classique est un sandwich au poulet ; Viande Hachée et Mixte y sont réservées à la
     * caisse) : deux viandes exigées, une seule proposée → impossible à commander sur l'application.
     *
     * AJOUT SEUL : aucune variation n'est supprimée (commandes passées). Une viande déjà présente mais réservée à la caisse
     * prend la visibilité qu'elle a sur la référence (Viande Hachée : partout ; Mixte : caisse, comme sur le Terminator).
     */
    private static function ensureMeatsFromReference(int $targetId, ItemAttribute $viande1, ItemAttribute $viande2, bool $dryRun, array &$stats): int
    {
        $reference = Item::query()->where('slug', self::SANDWICH2_MEATS_REFERENCE_SLUG)->whereNull('deleted_at')->first();
        if (! $reference) {
            $stats['notes'][] = 'Sandwich 2 Viandes : référence « '.self::SANDWICH2_MEATS_REFERENCE_SLUG.' » introuvable — viandes du Classique gardées.';

            return 0;
        }

        $count = 0;
        foreach ([$viande1, $viande2] as $attribute) {
            $meats = ItemVariation::query()
                ->where('item_id', $reference->id)->where('item_attribute_id', $attribute->id)
                ->where('status', Status::ACTIVE)->whereNull('deleted_at')->orderBy('id')->get();
            foreach ($meats as $row) {
                $count += self::ensureVariation($targetId, (int) $attribute->id, $row, $dryRun);

                $mine = ItemVariation::query()
                    ->where('item_id', $targetId)->where('item_attribute_id', $attribute->id)
                    ->where('name', $row->name)->whereNull('deleted_at')->first();
                if ($mine && ! $dryRun && json_encode($mine->visible_on) !== json_encode($row->visible_on)) {
                    $mine->forceFill(['visible_on' => $row->visible_on])->save();
                    $count++;
                }
            }
        }

        return $count;
    }

    private static function ensureVariation(int $itemId, int $attributeId, ItemVariation $row, bool $dryRun): int
    {
        $exists = ItemVariation::query()
            ->where('item_id', $itemId)->where('item_attribute_id', $attributeId)
            ->where('name', $row->name)->whereNull('deleted_at')->exists();
        if ($exists) {
            return 0;
        }
        if (! $dryRun) {
            ItemVariation::create([
                'item_id' => $itemId,
                'item_attribute_id' => $attributeId,
                'name' => $row->name,
                // Viandes incluses : 0 €, comme le gabarit. Le prix est celui du produit (9,00 €).
                'price' => $row->price,
                'status' => $row->status,
                'visible_on' => $row->visible_on,
            ]);
        }

        return 1;
    }

    private static function cloneExtras(int $sourceItemId, int $targetItemId, bool $dryRun): int
    {
        $created = 0;
        $rows = ItemExtra::query()
            ->where('item_id', $sourceItemId)->where('status', Status::ACTIVE)->whereNull('deleted_at')
            ->orderBy('id')->get();

        foreach ($rows as $row) {
            $exists = ItemExtra::query()
                ->where('item_id', $targetItemId)->where('group_label', $row->group_label)
                ->where('name', $row->name)->whereNull('deleted_at')->exists();
            if ($exists) {
                continue;
            }
            $created++;
            if ($dryRun) {
                continue;
            }
            $clone = ItemExtra::create([
                'item_id' => $targetItemId,
                'name' => $row->name,
                'status' => $row->status,
                'price' => $row->price,
                'visible_on' => $row->visible_on,
                'group_label' => $row->group_label,
                'is_available' => $row->is_available,
            ]);
            if (self::extraHasDescription() && $row->description !== null) {
                $clone->forceFill(['description' => $row->description])->save();
            }
        }

        return $created;
    }

    /** Formules « Menu (Frites + Boisson) » etc. — sans elles, le sandwich ne passe pas en menu. */
    private static function cloneAddons(int $sourceItemId, int $targetItemId, bool $dryRun): int
    {
        $created = 0;
        $rows = DB::table('item_addons')->where('item_id', $sourceItemId)->whereNull('deleted_at')->get();

        foreach ($rows as $row) {
            $exists = DB::table('item_addons')
                ->where('item_id', $targetItemId)->where('addon_item_id', $row->addon_item_id)
                ->where('role', $row->role)->whereNull('deleted_at')->exists();
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

    /** Recette de base (pain, crudités…). La viande se déduit du snapshot scellé à la vente. */
    private static function cloneRecipeLines(int $sourceItemId, int $targetItemId, bool $dryRun): int
    {
        $created = 0;
        $rows = RawMaterialRecipeLine::query()
            ->where('subject_type', Item::class)->where('subject_id', $sourceItemId)->get();

        foreach ($rows as $row) {
            $exists = RawMaterialRecipeLine::query()
                ->where('subject_type', Item::class)->where('subject_id', $targetItemId)
                ->where('raw_material_id', $row->raw_material_id)->exists();
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

    private static function extraHasDescription(): bool
    {
        static $has = null;

        return $has ??= Schema::hasColumn('item_extras', 'description');
    }

    // ─── 3. Supplément « Façon Cayenne » ─────────────────────────────────────────────────────

    private static function ensureFaconCayenne(bool $dryRun, array &$stats): void
    {
        $categoryId = (int) (ItemCategory::query()
            ->where('name', self::SANDWICH_SOURCE_CATEGORY)->whereNull('deleted_at')->value('id') ?? 0);
        if ($categoryId === 0) {
            $stats['notes'][] = 'Façon Cayenne : catégorie « Sandwichs » introuvable.';

            return;
        }

        $sandwiches = Item::query()
            ->where('item_category_id', $categoryId)
            ->where('status', Status::ACTIVE)
            ->whereNull('deleted_at')
            ->get();

        foreach ($sandwiches as $item) {
            // Le Cayenne EST la façon Cayenne : lui proposer le supplément le ferait payer deux fois.
            if (stripos((string) $item->name, 'cayenne') !== false) {
                continue;
            }

            $extra = ItemExtra::query()
                ->where('item_id', $item->id)->where('name', self::FACON_CAYENNE_NAME)
                ->whereNull('deleted_at')->first();

            if ($extra) {
                if (! $dryRun && ((float) $extra->price !== self::FACON_CAYENNE_PRICE
                    || (int) $extra->status !== Status::ACTIVE
                    || $extra->group_label !== self::FACON_CAYENNE_GROUP)) {
                    $extra->forceFill([
                        'price' => self::FACON_CAYENNE_PRICE,
                        'status' => Status::ACTIVE,
                        'is_available' => 1,
                        'group_label' => self::FACON_CAYENNE_GROUP,
                    ])->save();
                    $stats['touched'][] = (int) $item->id;
                }

                continue;
            }

            $stats['facon_cayenne']++;
            if ($dryRun) {
                continue;
            }
            $created = ItemExtra::create([
                'item_id' => $item->id,
                'name' => self::FACON_CAYENNE_NAME,
                'price' => self::FACON_CAYENNE_PRICE,
                'status' => Status::ACTIVE,
                'visible_on' => null,
                'group_label' => self::FACON_CAYENNE_GROUP,
                'is_available' => 1,
            ]);
            // `description` n'est ni « fillable » ni présente sur toutes les bases (colonne héritée,
            // absente d'une base construite par les migrations) : écrite seulement si elle existe.
            if (self::extraHasDescription()) {
                $created->forceFill(['description' => self::FACON_CAYENNE_DESCRIPTION])->save();
            }
            $stats['touched'][] = (int) $item->id;
        }
    }

    // ─── 4. Libellés ─────────────────────────────────────────────────────────────────────────

    private static function ensureDescriptions(bool $dryRun, array &$stats): void
    {
        foreach (self::DESCRIPTIONS as $slug => [$expectedOld, $new]) {
            $item = Item::query()->where('slug', $slug)->whereNull('deleted_at')->first();
            if (! $item) {
                continue;
            }
            $current = trim((string) $item->description);
            if ($current === $new) {
                continue;
            }
            if ($expectedOld !== null && $current !== $expectedOld) {
                $stats['notes'][] = "Description de « {$item->name} » modifiée en admin — laissée telle quelle.";

                continue;
            }
            $stats['descriptions']++;
            if (! $dryRun) {
                $item->forceFill(['description' => $new])->save();
                $stats['touched'][] = (int) $item->id;
            }
        }
    }
}
