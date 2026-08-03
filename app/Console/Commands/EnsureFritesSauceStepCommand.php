<?php

namespace App\Console\Commands;

use App\Enums\Status;
use App\Events\CatalogChanged;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * [OWNER 2026-07-28] Frites borne+caisse → étape CHOIX DE SAUCE multi (1ère gratuite, +0,50 chacune).
 *
 * Plainte owner (test live) : « pour ajouter des frites je trouve pas l'option de choisir la sauce,
 * la sauce si crucial, je pourrais choisir plusieurs et chacune de plus c'est compter 0,50 € comme
 * la logique d'avant ». Les frites (cat 7) n'avaient AUCUNE étape sauce.
 *
 * MÉCANISME (choisi après audit adversarial 2026-07-28) — « comme un sandwich », SANS profil composer :
 *  - On ajoute l'attribut sauce (id 5) + ses variations (prix 0) sur chaque item frites.
 *  - On bascule la CATÉGORIE frites (cat 7) de wizard_template 'custom' → 'snacking' : la borne
 *    (KioskWizardComponent, switch template) affiche alors l'étape `sauce` (KioskStepSauce, multi,
 *    1ère gratuite + 0,50 chacune) — cat 7 has_menu=false → aucune étape menu/boisson parasite.
 *  - La caisse (pos-wizard renderSinglePage) est data-driven : `hasSauce` (attribut) affiche la
 *    section sauce multi automatiquement.
 *  - La 2ᵉ+ sauce est facturée par l'ItemExtra « Sauce supplémentaire » @0,50 (group_label='sauce'),
 *    posé par EnsureSauceSupplementExtrasCommand — routé par la logique sauce-order des deux surfaces.
 *
 * POURQUOI PAS UN PROFIL COMPOSER PUBLIÉ (ancienne approche, RÉGRESSION 422 attrapée par 2 agents
 * adversaires) : publier un profil ACTIVE `PricingService::assertComposerSelectionsBelongToPublishedProfile`,
 * qui EXIGE que chaque item_extra envoyé soit projeté par une étape `extra_group`. La caisse
 * (renderSinglePage, FROZEN) facture pourtant TOUJOURS la 2ᵉ sauce via l'extra générique
 * group_label='sauce' — non projetable sans rendre une case parasite. Résultat : 2 sauces = 422
 * (commande bloquée). SANS profil, le contrôle belongs-to-profile est skippé (comme les sandwiches)
 * → affiché == scellé, aucune 422, multi-sauce facturé au centime. DATA/CONFIG UNIQUEMENT — 0 frozen.
 *
 * Idempotent. La migration jumelle 2026_07_28 rejoue ensure() au déploiement.
 */
class EnsureFritesSauceStepCommand extends Command
{
    protected $signature = 'menu:ensure-frites-sauce-step {--dry-run : Compter sans écrire}';

    protected $description = "Frites : étape sauce multi (1ère gratuite, +0,50 chacune) via template catégorie 'snacking' (sans profil composer → 0 régression 422). Idempotent.";

    /** Catégorie Frites (V1 Le Cayenne). */
    public const FRITES_CATEGORY_ID = 7;

    /** Template borne qui expose l'étape sauce sans étape menu/boisson (cat has_menu=false). */
    public const WIZARD_TEMPLATE = 'snacking';

    /** Repli si aucun item de référence vivant (fresh install). */
    public const CANONICAL_SAUCES = [
        'Mayonnaise', 'Ketchup', 'Blanche', 'Hannibal', 'Samouraï', 'Algérienne',
        'Andalouse', 'Curry', 'Barbecue', 'Harissa', 'Fromagère maison', 'Spicy maison',
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $r = self::ensure($dry);
        $this->info(($dry ? '[dry-run] ' : '')
            ."Frites sauce — sauces+{$r['variations']} var, catégorie template→'".self::WIZARD_TEMPLATE."' ({$r['category']}), profils dépubliés {$r['profiles_unpublished']}, sauce-supplément+{$r['sauce_extra']}.");

        return self::SUCCESS;
    }

    /**
     * @return array{variations:int,category:int,profiles_unpublished:int,sauce_extra:int}
     */
    public static function ensure(bool $dryRun = false): array
    {
        $out = ['variations' => 0, 'category' => 0, 'profiles_unpublished' => 0, 'sauce_extra' => 0];

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

        if ($sauceAttr === null) {
            return $out; // pas d'attribut sauce → rien à faire
        }

        // ── Liste de sauces = celles de l'item ACTIF au plus de sauces (jamais un frites).
        $refItemId = DB::table('item_variations as v')
            ->join('items as i', 'i.id', '=', 'v.item_id')
            ->where('v.item_attribute_id', $sauceAttr->id)
            ->where('v.status', Status::ACTIVE)->whereNull('v.deleted_at')
            ->where('i.status', Status::ACTIVE)->whereNull('i.deleted_at')
            ->where('i.item_category_id', '!=', self::FRITES_CATEGORY_ID)
            ->selectRaw('v.item_id, COUNT(*) as n')
            ->groupBy('v.item_id')->orderByDesc('n')->orderBy('v.item_id')
            ->value('v.item_id');

        $sauces = $refItemId === null ? self::CANONICAL_SAUCES : DB::table('item_variations')
            ->where('item_id', $refItemId)
            ->where('item_attribute_id', $sauceAttr->id)
            ->where('status', Status::ACTIVE)->whereNull('deleted_at')
            ->orderBy('id')->pluck('name')->unique()->values()->all();

        $fritesItems = DB::table('items')
            ->where('item_category_id', self::FRITES_CATEGORY_ID)
            ->where('status', Status::ACTIVE)
            ->whereNull('deleted_at')
            ->pluck('id');

        foreach ($fritesItems as $itemId) {
            // (a) variations sauce (prix 0) — 1ère gratuite ; la sauce en plus @0,50 via l'extra.
            foreach ($sauces as $sauce) {
                $exists = DB::table('item_variations')
                    ->where('item_id', $itemId)->where('item_attribute_id', $sauceAttr->id)
                    ->where('name', $sauce)->whereNull('deleted_at')->exists();
                if ($exists) {
                    continue;
                }
                $out['variations']++;
                if (! $dryRun) {
                    DB::table('item_variations')->insert([
                        'item_id' => $itemId,
                        'item_attribute_id' => $sauceAttr->id,
                        'name' => $sauce,
                        'price' => 0,
                        'status' => Status::ACTIVE,
                        'visible_on' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            // (b) DÉPUBLIER tout profil composer frites (l'ancienne approche 422 en publiait un ;
            //     sans profil la seal est skippée → affiché==scellé, cf. docblock). Idempotent.
            $publishedProfileIds = DB::table('item_wizard_profiles')
                ->where('item_id', $itemId)->where('is_published', 1)->pluck('id');
            foreach ($publishedProfileIds as $pid) {
                $out['profiles_unpublished']++;
                if (! $dryRun) {
                    DB::table('item_wizard_profiles')->where('id', $pid)->update([
                        'is_published' => 0, 'updated_at' => now(),
                    ]);
                }
            }
        }

        // ── Catégorie frites → template 'snacking' (borne affiche l'étape sauce, has_menu=false).
        $cat = DB::table('item_categories')->where('id', self::FRITES_CATEGORY_ID)->first(['id', 'wizard_template']);
        if ($cat !== null && $cat->wizard_template !== self::WIZARD_TEMPLATE) {
            $out['category'] = 1;
            if (! $dryRun) {
                DB::table('item_categories')->where('id', self::FRITES_CATEGORY_ID)
                    ->update(['wizard_template' => self::WIZARD_TEMPLATE, 'updated_at' => now()]);
            }
        }

        // ── Véhicule sauce en plus (@0,50) sur tout item à attribut sauce (couvre les frites).
        $out['sauce_extra'] = EnsureSauceSupplementExtrasCommand::ensure($dryRun);

        // ── Synchro borne/caisse : prévenir les SPA d'un changement catalogue.
        if (! $dryRun && ($out['variations'] + $out['category'] + $out['profiles_unpublished'] + $out['sauce_extra'] > 0)) {
            event(new CatalogChanged(
                entityType: 'catalogue',
                entityId: 0,
                changeType: 'frites_sauce_step',
                branchId: 1,
                payloadDiff: $out,
            ));
        }

        return $out;
    }
}
