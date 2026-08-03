<?php

namespace App\Console\Commands;

use App\Enums\Status;
use App\Events\CatalogChanged;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * [OWNER 2026-07-17 → refondu 2026-07-28] Menus enfants borne+caisse :
 *  - « Menu Enfant Nuggets » (menu-enfant-nuggets) → étape CHOIX DE SAUCE (1ère gratuite, +0,50).
 *  - « Menu Enfant Chicken Burger » (menu-enfant-burger) → SAUCE + CRUDITÉS (Salade/Tomate/Oignon)
 *    + SUPPLÉMENTS standard (≤1 €, sans « Viande supplémentaire ») — « la sauce, les crus, les
 *    suppléments : trois choses » (plainte owner 2026-07-28, le chicken burger n'affichait que crudités).
 *
 * MÉCANISME (refondu 2026-07-28 après audit adversarial) — « comme un sandwich », SANS profil composer :
 *  - On ajoute l'attribut sauce (variations prix 0) sur les 2 menus enfants + les crudités/suppléments
 *    (extras) sur le chicken burger.
 *  - On bascule la CATÉGORIE menu-enfant de wizard_template 'simple' → 'sandwich' : la borne affiche
 *    alors, par data-gating (shouldShowStep), l'étape `sauce` sur les deux + `garnitures`/`supplements`
 *    là où la donnée existe (⇒ Nuggets = [sauce], Chicken Burger = [sauce, garnitures, supplements]).
 *    has_menu=false ⇒ aucune étape menu/boisson parasite ; pas de pain/viande ⇒ étapes masquées.
 *  - La caisse (renderSinglePage) est data-driven : sauce/crudités/suppléments s'affichent depuis la donnée.
 *  - La 2ᵉ+ sauce est facturée par « Sauce supplémentaire » @0,50 (EnsureSauceSupplementExtrasCommand).
 *
 * POURQUOI PLUS DE PROFIL COMPOSER PUBLIÉ (l'ancienne approche introduisait une RÉGRESSION 422 —
 * attrapée par 2 agents adversaires 2026-07-28) : un profil publié ACTIVE
 * `PricingService::assertComposerSelectionsBelongToPublishedProfile`, qui rejette (422) l'extra
 * générique « Sauce supplémentaire » (group_label='sauce') que la caisse facture TOUJOURS pour la
 * 2ᵉ sauce — car il n'est projeté par aucune étape `extra_group`. Sans profil, le contrôle est skippé
 * (comme les sandwiches) → affiché == scellé, 0 régression 422. Corrige AUSSI le Nuggets (même piège
 * pré-existant). DATA/CONFIG UNIQUEMENT — 0 fichier frozen. Idempotent ; migration jumelle rejoue ensure().
 */
class EnsureKidsMenuStepsCommand extends Command
{
    protected $signature = 'menu:ensure-kids-menu-steps {--dry-run : Compter sans écrire}';

    protected $description = "Menus enfants : sauce (Nuggets+Burger) + crudités/suppléments (Burger) via template catégorie 'sandwich' (sans profil composer → 0 régression 422). Idempotent.";

    public const NUGGETS_SLUG = 'menu-enfant-nuggets';

    public const KIDS_BURGER_SLUG = 'menu-enfant-burger';

    /** Template borne qui expose sauce + garnitures + suppléments par data-gating (has_menu=false). */
    public const WIZARD_TEMPLATE = 'sandwich';

    /** Repli si aucun burger de référence vivant (fresh install). */
    public const CANONICAL_SAUCES = [
        'Mayonnaise', 'Ketchup', 'Blanche', 'Hannibal', 'Samouraï', 'Algérienne',
        'Andalouse', 'Curry', 'Barbecue', 'Harissa', 'Fromagère maison', 'Spicy maison',
    ];

    /** Repli liste suppléments standard (nom => prix). */
    public const FALLBACK_SUPPLEMENTS = [
        'Cheddar' => 0.90, 'Fromage à raclette' => 0.90, 'Emmental' => 0.90, 'Œuf' => 0.90,
        'Boursin' => 0.90, 'Légumes sautés' => 0.90, 'Jambon de dinde' => 0.90,
        'Champignons' => 0.90, 'Oignons frits' => 0.90,
    ];

    public const CRUDITES = ['Salade', 'Tomate', 'Oignon'];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $r = self::ensure($dry);
        $this->info(($dry ? '[dry-run] ' : '')
            ."Kids menu steps — sauces+{$r['variations']} var, extras+{$r['extras']}, catégories template→'".self::WIZARD_TEMPLATE."' ({$r['categories']}), profils dépubliés {$r['profiles_unpublished']}, sauce-supplément+{$r['sauce_extra']}.");

        return self::SUCCESS;
    }

    /**
     * @return array{variations:int,extras:int,categories:int,profiles_unpublished:int,sauce_extra:int}
     */
    public static function ensure(bool $dryRun = false): array
    {
        $out = ['variations' => 0, 'extras' => 0, 'categories' => 0, 'profiles_unpublished' => 0, 'sauce_extra' => 0];

        // ── Attribut sauce = celui (nom %sauce%, hors « bol ») portant le plus de variations actives.
        $sauceAttr = DB::table('item_attributes')
            ->whereRaw('LOWER(name) LIKE ?', ['%sauce%'])
            ->whereRaw('LOWER(name) NOT LIKE ?', ['%bol%'])
            ->where('status', Status::ACTIVE)
            ->get(['id', 'name'])
            ->sortByDesc(fn ($a) => DB::table('item_variations')
                ->where('item_attribute_id', $a->id)->where('status', Status::ACTIVE)
                ->whereNull('deleted_at')->count())
            ->first();

        // ── Burger de référence = item ACTIF au plus de sauces (jamais un menu enfant).
        $refItemId = $sauceAttr === null ? null : DB::table('item_variations as v')
            ->join('items as i', 'i.id', '=', 'v.item_id')
            ->where('v.item_attribute_id', $sauceAttr->id)
            ->where('v.status', Status::ACTIVE)->whereNull('v.deleted_at')
            ->where('i.status', Status::ACTIVE)->whereNull('i.deleted_at')
            ->whereNotIn('i.slug', [self::NUGGETS_SLUG, self::KIDS_BURGER_SLUG])
            ->selectRaw('v.item_id, COUNT(*) as n')
            ->groupBy('v.item_id')->orderByDesc('n')->orderBy('v.item_id')
            ->value('v.item_id');

        $sauces = $refItemId === null ? self::CANONICAL_SAUCES : DB::table('item_variations')
            ->where('item_id', $refItemId)
            ->where('item_attribute_id', $sauceAttr->id)
            ->where('status', Status::ACTIVE)->whereNull('deleted_at')
            ->orderBy('id')->pluck('name')->unique()->values()->all();

        // ── Liste suppléments standard = extras 'supplement' ≤1 € du burger de réf (sans « viande »).
        $supplements = self::FALLBACK_SUPPLEMENTS;
        if ($refItemId !== null) {
            $refSupps = DB::table('item_extras')
                ->where('item_id', $refItemId)->where('group_label', 'supplement')
                ->where('status', Status::ACTIVE)->whereNull('deleted_at')
                ->where('price', '<=', 1.00)
                ->whereRaw('LOWER(name) NOT LIKE ?', ['%viande%'])
                ->orderBy('id')->get(['name', 'price']);
            if ($refSupps->isNotEmpty()) {
                $supplements = $refSupps->mapWithKeys(fn ($e) => [$e->name => (float) $e->price])->all();
            }
        }

        $nuggets = DB::table('items')->where('slug', self::NUGGETS_SLUG)->whereNull('deleted_at')->first(['id', 'item_category_id']);
        $kidsBurger = DB::table('items')->where('slug', self::KIDS_BURGER_SLUG)->whereNull('deleted_at')->first(['id', 'item_category_id']);
        $kidsCategoryIds = [];

        // ── (a) Menu Enfant Nuggets : sauces (exige l'attribut sauce). Pas de profil.
        if ($nuggets !== null) {
            $kidsCategoryIds[(int) $nuggets->item_category_id] = true;
            if ($sauceAttr !== null) {
                $out['variations'] += self::ensureSauceVariations((int) $nuggets->id, (int) $sauceAttr->id, $sauces, $dryRun);
            }
            $out['profiles_unpublished'] += self::unpublishProfiles((int) $nuggets->id, $dryRun);
        }

        // ── (b) Menu Enfant Chicken Burger : sauces + crudités + suppléments. Pas de profil.
        if ($kidsBurger !== null) {
            $kidsCategoryIds[(int) $kidsBurger->item_category_id] = true;
            if ($sauceAttr !== null) {
                $out['variations'] += self::ensureSauceVariations((int) $kidsBurger->id, (int) $sauceAttr->id, $sauces, $dryRun);
            }
            foreach (self::CRUDITES as $crudite) {
                $out['extras'] += self::ensureExtra((int) $kidsBurger->id, $crudite, 0.0, 'crudite', $dryRun);
            }
            foreach ($supplements as $name => $price) {
                $out['extras'] += self::ensureExtra((int) $kidsBurger->id, (string) $name, (float) $price, 'supplement', $dryRun);
            }
            $out['profiles_unpublished'] += self::unpublishProfiles((int) $kidsBurger->id, $dryRun);
        }

        // ── Catégorie(s) menu-enfant → template 'sandwich' (borne data-gate sauce/garnitures/suppléments).
        foreach (array_keys($kidsCategoryIds) as $catId) {
            $cat = DB::table('item_categories')->where('id', $catId)->first(['id', 'wizard_template']);
            if ($cat !== null && $cat->wizard_template !== self::WIZARD_TEMPLATE) {
                $out['categories']++;
                if (! $dryRun) {
                    DB::table('item_categories')->where('id', $catId)
                        ->update(['wizard_template' => self::WIZARD_TEMPLATE, 'updated_at' => now()]);
                }
            }
        }

        // ── Véhicule 2e sauce (@0,50) sur tout item à attribut sauce (couvre les menus enfants).
        $out['sauce_extra'] = EnsureSauceSupplementExtrasCommand::ensure($dryRun);

        // ── Synchro borne/caisse : prévenir les SPA d'un changement catalogue.
        if (! $dryRun && ($out['variations'] + $out['extras'] + $out['categories'] + $out['profiles_unpublished'] + $out['sauce_extra'] > 0)) {
            event(new CatalogChanged(
                entityType: 'catalogue',
                entityId: 0,
                changeType: 'kids_menu_steps',
                branchId: 1,
                payloadDiff: $out,
            ));
        }

        return $out;
    }

    /** Ajoute les variations sauce manquantes (prix 0) sur un item. Retourne le nombre ajouté. */
    private static function ensureSauceVariations(int $itemId, int $sauceAttrId, array $sauces, bool $dryRun): int
    {
        $added = 0;
        foreach ($sauces as $sauce) {
            $exists = DB::table('item_variations')
                ->where('item_id', $itemId)->where('item_attribute_id', $sauceAttrId)
                ->where('name', $sauce)->whereNull('deleted_at')->exists();
            if ($exists) {
                continue;
            }
            $added++;
            if (! $dryRun) {
                DB::table('item_variations')->insert([
                    'item_id' => $itemId,
                    'item_attribute_id' => $sauceAttrId,
                    'name' => $sauce,
                    'price' => 0,
                    'status' => Status::ACTIVE,
                    'visible_on' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        return $added;
    }

    /**
     * Dépublie tout profil composer publié d'un item (l'ancienne approche 2026-07-17→07-28 en
     * publiait un ; il déclenchait la régression 422 — cf. docblock). Retourne le nombre dépublié.
     */
    private static function unpublishProfiles(int $itemId, bool $dryRun): int
    {
        $ids = DB::table('item_wizard_profiles')
            ->where('item_id', $itemId)->where('is_published', 1)->pluck('id');
        if ($ids->isEmpty()) {
            return 0;
        }
        if (! $dryRun) {
            DB::table('item_wizard_profiles')->whereIn('id', $ids)
                ->update(['is_published' => 0, 'updated_at' => now()]);
        }

        return $ids->count();
    }

    /** Crée l'extra vivant s'il manque. Retourne 1 si créé. */
    private static function ensureExtra(int $itemId, string $name, float $price, string $group, bool $dryRun): int
    {
        $exists = DB::table('item_extras')
            ->where('item_id', $itemId)->where('name', $name)
            ->where('group_label', $group)->whereNull('deleted_at')
            ->exists();
        if ($exists) {
            return 0;
        }
        if (! $dryRun) {
            DB::table('item_extras')->insert([
                'item_id' => $itemId,
                'name' => $name,
                'price' => $price,
                'group_label' => $group,
                'status' => Status::ACTIVE,
                'visible_on' => null,
                'is_available' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return 1;
    }
}
