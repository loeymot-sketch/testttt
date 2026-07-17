<?php

namespace App\Console\Commands;

use App\Enums\Status;
use App\Events\CatalogChanged;
use App\Events\ComposerProfileChanged;
use App\Models\ItemWizardProfile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * [OWNER 2026-07-17] Menus enfants borne+caisse :
 *  - « Menu Enfant Nuggets » (slug menu-enfant-nuggets) → étape CHOIX DE SAUCE
 *    (12 sauces copiées du burger de référence, 1ère gratuite, 2e @0,50 via
 *    « Sauce supplémentaire ») ;
 *  - « Menu Enfant Chicken Burger » (slug menu-enfant-burger) → étape CRUDITÉS
 *    (Salade, Tomate, Oignon @0) PUIS SUPPLÉMENTS standard (liste ≤1 € copiée du
 *    burger de référence, sans « Viande supplémentaire »).
 *
 * Pourquoi un profil composer PUBLIÉ niveau item : la cat menu-enfant est en
 * wizard_template 'simple' → l'heuristique borne (KioskWizardComponent
 * effectiveWizardTemplate) n'affiche JAMAIS sauce/garnitures ; seul un profil
 * item publié est projeté par NormalItemResource/ItemResource et piloté à
 * l'identique par la caisse (pos-wizard composer-aware, flag ON). DATA UNIQUEMENT
 * — aucun fichier frozen. Idempotente (skip si profil déjà canonique).
 *
 * Contrat [RED F3] : « ensure » = ENFORCE l'état publié. Relancer la commande
 * re-publie un profil dépublié à la main ; le rollback (dépublier, cf. down()
 * de la migration jumelle) suppose de NE PAS relancer la commande ensuite.
 */
class EnsureKidsMenuStepsCommand extends Command
{
    protected $signature = 'menu:ensure-kids-menu-steps {--dry-run : Compter sans écrire}';

    protected $description = "Menus enfants : étape sauce (Nuggets) + crudités puis suppléments (Chicken Burger) sur borne+caisse via profil composer publié. Idempotent.";

    public const NUGGETS_SLUG = 'menu-enfant-nuggets';

    public const KIDS_BURGER_SLUG = 'menu-enfant-burger';

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
            ."Kids menu steps — sauces+{$r['variations']} var, extras+{$r['extras']}, profils publiés/rebâtis {$r['profiles']}, sauce-supplément+{$r['sauce_extra']}.");

        return self::SUCCESS;
    }

    /**
     * @return array{variations:int,extras:int,profiles:int,sauce_extra:int}
     */
    public static function ensure(bool $dryRun = false): array
    {
        $out = ['variations' => 0, 'extras' => 0, 'profiles' => 0, 'sauce_extra' => 0];

        // ── Attribut sauce = celui (nom %sauce%) portant le plus de variations actives.
        $sauceAttr = DB::table('item_attributes')
            ->whereRaw('LOWER(name) LIKE ?', ['%sauce%'])
            ->where('status', Status::ACTIVE)
            ->get(['id', 'name'])
            ->sortByDesc(fn ($a) => DB::table('item_variations')
                ->where('item_attribute_id', $a->id)->where('status', Status::ACTIVE)
                ->whereNull('deleted_at')->count())
            ->first();

        // NB : pas d'early-return si l'attribut sauce manque — la partie (b)
        // Chicken Burger (crudités/suppléments) n'en dépend pas [RED F5].

        // ── Burger de référence = item ACTIF au plus de sauces actives sur cet attribut.
        //    Jamais un item enfant (ce sont les cibles) ; tie-break id asc (déterministe).
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

        // ── Liste suppléments standard = extras 'supplement' ≤1 € du burger de réf
        //    (exclut « Viande supplémentaire » @2,50 — pas pour un menu enfant).
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

        $nuggets = DB::table('items')->where('slug', self::NUGGETS_SLUG)->whereNull('deleted_at')->first(['id']);
        $kidsBurger = DB::table('items')->where('slug', self::KIDS_BURGER_SLUG)->whereNull('deleted_at')->first(['id']);
        $touchedProfiles = [];

        // ── (a) Menu Enfant Nuggets : sauces + profil [sauce] (exige l'attribut sauce).
        if ($nuggets !== null && $sauceAttr !== null) {
            foreach ($sauces as $sauce) {
                $exists = DB::table('item_variations')
                    ->where('item_id', $nuggets->id)->where('item_attribute_id', $sauceAttr->id)
                    ->where('name', $sauce)->whereNull('deleted_at')->exists();
                if ($exists) {
                    continue;
                }
                $out['variations']++;
                if (! $dryRun) {
                    DB::table('item_variations')->insert([
                        'item_id' => $nuggets->id,
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

            $changed = self::ensureProfile($nuggets->id, [[
                'step_key' => 'sauce',
                'label' => 'Choisis ta sauce',
                'source_type' => 'item_attribute',
                'source_ref' => $sauceAttr->name,
                'source_item_attribute_id' => $sauceAttr->id,
                'min_select' => 1,
                'max_select' => 1,
            ]], $dryRun, $touchedProfiles);
            $out['profiles'] += $changed;
        }

        // ── (b) Menu Enfant Chicken Burger : crudités + suppléments + profil.
        if ($kidsBurger !== null) {
            foreach (self::CRUDITES as $crudite) {
                $out['extras'] += self::ensureExtra($kidsBurger->id, $crudite, 0.0, 'crudite', $dryRun);
            }
            foreach ($supplements as $name => $price) {
                $out['extras'] += self::ensureExtra($kidsBurger->id, (string) $name, (float) $price, 'supplement', $dryRun);
            }

            $changed = self::ensureProfile($kidsBurger->id, [
                [
                    'step_key' => 'garnitures',
                    'label' => 'Choisis tes garnitures',
                    'source_type' => 'extra_group',
                    'source_ref' => 'crudite',
                    'source_item_attribute_id' => null,
                    'min_select' => 0,
                    'max_select' => 6,
                ],
                [
                    'step_key' => 'supplements',
                    'label' => 'Suppléments',
                    'source_type' => 'extra_group',
                    'source_ref' => 'supplement',
                    'source_item_attribute_id' => null,
                    'min_select' => 0,
                    'max_select' => 5,
                ],
            ], $dryRun, $touchedProfiles);
            $out['profiles'] += $changed;
        }

        // ── Véhicule 2e sauce (@0,50) sur tout item à attribut sauce (couvre Nuggets).
        $out['sauce_extra'] = EnsureSauceSupplementExtrasCommand::ensure($dryRun);

        // ── Synchro borne/caisse : invalider caches + prévenir les SPA.
        if (! $dryRun && ($touchedProfiles !== [] || $out['variations'] + $out['extras'] + $out['sauce_extra'] > 0)) {
            foreach ($touchedProfiles as $profileId) {
                $profile = ItemWizardProfile::query()->find($profileId);
                if ($profile !== null) {
                    event(ComposerProfileChanged::fromProfile($profile, 'published'));
                }
            }
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

    /**
     * Publie (ou rebâtit) le profil composer item avec EXACTEMENT ces steps actifs.
     * Skip si déjà canonique. Retourne 1 si modifié.
     *
     * @param array<int, array<string, mixed>> $steps
     * @param array<int, int> $touchedProfiles
     */
    private static function ensureProfile(int $itemId, array $steps, bool $dryRun, array &$touchedProfiles): int
    {
        $profile = DB::table('item_wizard_profiles')->where('item_id', $itemId)->orderByDesc('id')->first();

        $wantedKeys = array_column($steps, 'step_key');
        if ($profile !== null && (int) $profile->is_published === 1) {
            $activeKeys = DB::table('item_wizard_steps')
                ->where('profile_id', $profile->id)->where('is_active', 1)
                ->orderBy('position')->pluck('step_key')->all();
            $inactiveCount = DB::table('item_wizard_steps')
                ->where('profile_id', $profile->id)->where('is_active', 0)->count();
            if ($activeKeys === $wantedKeys && $inactiveCount === 0) {
                return 0; // déjà canonique — idempotent
            }
        }

        if ($dryRun) {
            return 1;
        }

        if ($profile === null) {
            $profileId = (int) DB::table('item_wizard_profiles')->insertGetId([
                'item_id' => $itemId,
                'item_category_id' => null,
                'template' => 'custom',
                'version' => 1,
                'is_published' => 1,
                'published_at' => now(),
                'branch_id_scope' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $profileId = (int) $profile->id;
            DB::table('item_wizard_steps')->where('profile_id', $profileId)->delete();
            DB::table('item_wizard_profiles')->where('id', $profileId)->update([
                'template' => 'custom',
                'version' => (int) $profile->version + 1,
                'is_published' => 1,
                'published_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach ($steps as $position => $step) {
            DB::table('item_wizard_steps')->insert([
                'profile_id' => $profileId,
                'step_key' => $step['step_key'],
                'label' => $step['label'],
                'source_type' => $step['source_type'],
                'source_ref' => $step['source_ref'],
                'source_item_attribute_id' => $step['source_item_attribute_id'] ?? null,
                'min_select' => $step['min_select'],
                'max_select' => $step['max_select'],
                'allow_repeat' => 0,
                'visible_on' => null,
                'stockable_choices' => 0,
                'position' => $position,
                'is_active' => 1,
                'addon_role' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $touchedProfiles[] = $profileId;

        return 1;
    }
}
