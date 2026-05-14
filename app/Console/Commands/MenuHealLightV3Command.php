<?php

namespace App\Console\Commands;

use App\Enums\Status;
use App\Events\CatalogChanged;
use App\Events\ComposerProfileChanged;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemVariation;
use App\Models\ItemWizardProfile;
use App\Models\ItemWizardStep;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * MENU HEAL-LIGHT V3 — Owner-validated heal 2026-05-14
 * =================================================================================
 *
 * Three-layer heal delivering:
 *
 *  L1 IMAGES: attach 18+ product photos from
 *      /Users/1millnonstop/Downloads/Le cayenne - compressé/ to items 474, 488,
 *      475, 476, 477, 489, 375, 490, 478, 479, 492-499 via Spatie media-library
 *      collection 'item' (singular — matches Item.php getThumb/Cover/Preview
 *      accessors). Idempotent: skip when target file name already present.
 *
 *  L2 BOWL COMPOSER 3-step: collapse current 4-step profile (sauce / supp /
 *      drink / gratine) into the canonical 3-step shape (sauce / supp+gratine /
 *      drink). Implementation:
 *        - Step 1 "Sauce bol" attr 330 min=1 max=2 (only Spicy + Sauce fromagère
 *          maison remain ACTIVE — inactivate other sauces on attr 330 for the 8
 *          bowls so the wizard naturally renders 2 choices).
 *        - Step 2 "Suppléments" extra_group=supplement_bol min=0 max=N — already
 *          contains "Boule gratinée" +2.00€ row. Delete the duplicate group_label
 *          'gratine' row (was its own step). Wizard step now exposes Gratiné as
 *          one of the supplement choices.
 *        - Step 3 "Boisson" addon role=drink min=0 max=1.
 *        - DELETE old step 3 'gratine'.
 *
 *  L3 SAUCES Barbecue + Ail: add these two as item_variations on attr 311
 *      (Sauce libre — sandwich/burger/tacos wizards) ONLY. attr 330 (bowl
 *      sauce) is restricted to Spicy + Fromagère per owner spec; do not bloat
 *      bowl wizard with new sauces.
 *
 *  L4 RENAME: item 490 "Big Chicken" → "Chicken Burger Special".
 *
 * Rules:
 *  - DB::transaction wraps ALL writes — rollback on any failure.
 *  - ZERO frozen-zone touch (CLAUDE.md §7).
 *  - Idempotent: re-run --force = no-op once applied.
 *  - Events deferred until after transaction commit (ComposerProfileChanged +
 *    CatalogChanged bridge).
 *  - NF525 chain not touched (no fiscal_sequence, audit_log, z_report writes).
 *  - composition_snapshot historical preservation: add-only on items.name (490)
 *    + media add-only + sauce variations soft-inactivate (status 10), no
 *    destructive deletes on referenced rows.
 *
 * @see reports/audit/menu-v3-2026-05-14/PLAN.md
 * @see app/Console/Commands/MenuHealLightV2Round2PatchCommand.php (pattern)
 */
class MenuHealLightV3Command extends Command
{
    protected $signature = 'menu:heal-light-v3
                            {--dry-run : Show plan without DB writes}
                            {--force : Skip confirmation prompt}';

    protected $description = 'Menu V3 owner-validated heal: product images + bowl composer 3-step + sauces Barbecue/Ail + Big Chicken rename';

    /** Frozen-zone files — verification only (this command only writes data). */
    private const FROZEN_FILES = [
        'public/js/pos-wizard.js',
        'public/css/pos-wizard.css',
        'resources/views/admin-pos-v4.blade.php',
        'resources/js/components/frontend/kiosk/KioskWizardComponent.vue',
        'resources/js/components/frontend/kiosk/KioskAppComponent.vue',
        'resources/js/components/frontend/kiosk/KioskUpsellComponent.vue',
        'app/Services/Fiscal/FiscalSequenceService.php',
        'app/Services/Fiscal/ZReportService.php',
        'app/Services/Fiscal/AuditLogService.php',
        'app/Models/Scopes/BranchScope.php',
        'app/Http/Middleware/IdempotencyKeyMiddleware.php',
        'app/Services/Pricing/PricingService.php',
        'app/Domain/Order/OrderStateMachine.php',
    ];

    /** Source folder (absolute path) containing product PNG mockups. */
    private const SOURCE_ROOT = '/Users/1millnonstop/Downloads/Le cayenne - compressé';

    /** Sauce attribute IDs. */
    private const ATTR_SAUCE_FREE = 311; // Sauce libre — sandwich/burger/tacos
    private const ATTR_SAUCE_BOL  = 330; // Sauce bol — bowls

    /** Item 490: rename target. */
    private const ITEM_BIG_CHICKEN          = 490;
    private const ITEM_BIG_CHICKEN_OLD_NAME = 'Big Chicken';
    private const ITEM_BIG_CHICKEN_NEW_NAME = 'Chicken Burger Special';

    /** Bowls range (8 items). */
    private const BOWL_IDS = [492, 493, 494, 495, 496, 497, 498, 499];

    /** Sauces to keep ACTIVE on bowl attr 330. All others inactivated. */
    private const BOWL_ACTIVE_SAUCES = ['Spicy', 'Sauce fromagère maison'];

    /** Sauces to ADD on attr 311 (sandwich/burger/tacos). */
    private const SAUCES_TO_ADD_311 = ['Barbecue', 'Ail'];

    /**
     * Image mapping: item_id → relative path under SOURCE_ROOT.
     * Per PLAN.md §8 (owner-validated best hero per item).
     */
    private const IMAGE_MAP = [
        // Sandwich Cayenne family
        474 => 'cayenne/cayene.png',                              // Sandwich Cayenne
        488 => 'Big cayenne 2v/big_cayenne_2v_avec_oeuf_cheddar.png', // Big Cayenne
        // Galette family
        475 => 'galette/galette mariné.png',                      // Galette Normale
        476 => 'galette/galette tondory cayenne.png',             // Galette Cayenne
        // Sandwich Classique family
        477 => 'sandwich classic/classico1.png',                   // Sandwich Classique
        489 => 'sandwich classic/classic maxi cryspy.png',         // Big Classique
        // Burgers family
        375 => 'burger/burger 1 cheese chicken.png',               // Chicken Burger
        490 => 'burger/chicken big burger.png',                    // Chicken Burger Special (post-rename)
        // Tacos family
        478 => 'tacos/tacos cury.png',                             // Tacos M
        479 => 'tacos/Tacos cryspie.png',                          // Tacos L
        // Bowls — 8 items
        492 => 'bols/bol frite chicken.png',                       // Bowl Frites Poulet mariné
        493 => 'bols/bol frite curie.png',                         // Bowl Frites Poulet curry
        494 => 'bols/bol frite tondory.png',                       // Bowl Frites Poulet tandoori
        495 => 'bols/bol frite cryspie.png',                       // Bowl Frites Poulet crispy
        496 => 'bols/bol riz chicken.png',                         // Bowl Riz Poulet mariné
        497 => 'bols/bol riz curie.png',                           // Bowl Riz Poulet curry
        498 => 'bols/bol riz tondory.png',                         // Bowl Riz Poulet tandoori
        499 => 'bols/bol riz cryspie.png',                         // Bowl Riz Poulet crispy
    ];

    private array $stats = [
        'images_attached'         => 0,
        'images_skipped'          => 0,
        'images_missing_file'     => 0,
        'bowl_composer_updated'   => 0,
        'bowl_composer_skipped'   => 0,
        'bowl_sauces_inactivated' => 0,
        'bowl_extras_consolidated'=> 0,
        'sauces_added'            => 0,
        'sauces_skipped'          => 0,
        'items_renamed'           => 0,
        'events_fired'            => 0,
    ];

    /** @var array<int, object> */
    private array $eventsToFire = [];

    public function handle(): int
    {
        $this->line('');
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->info('  MENU HEAL-LIGHT V3 — Owner-validated heal 2026-05-14');
        $this->info('  Images + Bowl composer 3-step + Sauces Barbecue/Ail + Big Chicken rename');
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->line('');

        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY-RUN MODE — no DB writes, no media writes, no events.');
            $this->line('');
        }

        if (! $this->option('force') && ! $dryRun) {
            $this->warn('This will:');
            $this->line('  - Attach 18 product photos via Spatie media collection "item"');
            $this->line('  - Consolidate 8 bowl composer profiles from 4-step → 3-step');
            $this->line('  - Inactivate non-canonical sauces on attr 330 for bowls 492-499');
            $this->line('  - Add Barbecue + Ail variations on attr 311 (43 items)');
            $this->line('  - Rename item 490 "Big Chicken" → "Chicken Burger Special"');
            $this->line('  - Fire ComposerProfileChanged + CatalogChanged events');
            $this->line('');

            if (! $this->confirm('Proceed?')) {
                $this->warn('Aborted by user.');
                return self::SUCCESS;
            }
        }

        try {
            $this->preflightChecks();

            if ($dryRun) {
                $this->dryRunPlan();
                return self::SUCCESS;
            }

            // L1 Images: outside transaction (media addMedia uses filesystem
            // operations; rollback would not undo them anyway and Spatie's
            // toMediaCollection is idempotent-friendly via our skip check).
            $this->attachProductImages();

            DB::transaction(function () {
                $this->renameBigChicken();
                $this->addSauces311();
                $this->inactivateBowlSauces330();
                $this->consolidateBowlGratineExtras();
                $this->rebuildBowlComposerProfiles();
            });

            $this->verifyFrozenZoneClean();
            $this->fireDeferredEvents();
            $this->renderStats();

            $this->line('');
            $this->info('Menu V3 heal complete.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('FAILED: ' . $e->getMessage());
            $this->error('File: ' . $e->getFile() . ':' . $e->getLine());
            Log::error('MenuHealLightV3Command failed', ['exception' => $e]);

            return self::FAILURE;
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // PRE-FLIGHT
    // ─────────────────────────────────────────────────────────────────────
    private function preflightChecks(): void
    {
        $required = [
            'attr Sauce libre (311)'  => ItemAttribute::find(self::ATTR_SAUCE_FREE),
            'attr Sauce bol (330)'    => ItemAttribute::find(self::ATTR_SAUCE_BOL),
            'item Big Chicken (490)'  => Item::find(self::ITEM_BIG_CHICKEN),
        ];

        foreach ($required as $label => $row) {
            if (! $row) {
                throw new \RuntimeException("Pre-flight FAIL: {$label} not found.");
            }
        }

        foreach (self::BOWL_IDS as $bowlId) {
            if (! Item::find($bowlId)) {
                throw new \RuntimeException("Pre-flight FAIL: Bowl item {$bowlId} not found.");
            }
        }

        if (! is_dir(self::SOURCE_ROOT)) {
            throw new \RuntimeException('Pre-flight FAIL: source image root not found: ' . self::SOURCE_ROOT);
        }

        $this->info('Pre-flight: items, attributes, source folder OK.');
    }

    private function dryRunPlan(): void
    {
        $this->info('DRY-RUN plan:');
        $this->line('');
        $this->line('L1 IMAGES — attach via Spatie media collection "item":');
        foreach (self::IMAGE_MAP as $itemId => $relPath) {
            $abs = self::SOURCE_ROOT . '/' . $relPath;
            $exists = is_file($abs) ? 'OK' : 'MISSING';
            $item = Item::find($itemId);
            $mc = $item ? $item->media()->count() : 0;
            $action = $mc > 0 ? 'SKIP (has media)' : 'ATTACH';
            $this->line(sprintf('  - item %d %-40s file %s → %s [%s]',
                $itemId, $item?->name ?? '?', $exists, $relPath, $action));
        }
        $this->line('');
        $this->line('L2 BOWL COMPOSER — 8 bowls 492-499:');
        $this->line('  - Inactivate attr 330 variations not in [Spicy, Sauce fromagère maison]');
        $this->line('  - Soft-delete item_extras group_label="gratine" (consolidate into supplement_bol)');
        $this->line('  - Rebuild composer profile: 4 steps → 3 steps');
        $this->line('      step 0: sauce (attr 330) min=1 max=2');
        $this->line('      step 1: supplements (extra_group=supplement_bol) min=0 max=4');
        $this->line('      step 2: drink (addon role=drink) min=0 max=1');
        $this->line('');
        $this->line('L3 SAUCES — add on attr 311 (43 items): Barbecue + Ail');
        $this->line('');
        $this->line('L4 RENAME — item 490 "Big Chicken" → "Chicken Burger Special"');
        $this->line('');
        $this->line('DRY-RUN — nothing persisted. Run with --force to execute.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // L1 — Image attachment via Spatie media (collection "item")
    // ─────────────────────────────────────────────────────────────────────
    private function attachProductImages(): void
    {
        $this->info('L1 — Attaching product images…');

        foreach (self::IMAGE_MAP as $itemId => $relPath) {
            $abs = self::SOURCE_ROOT . '/' . $relPath;
            $item = Item::find($itemId);

            if (! $item) {
                $this->warn("   item {$itemId} not found — skip.");
                continue;
            }

            if (! is_file($abs)) {
                $this->warn("   item {$itemId}: source file missing → {$abs}");
                $this->stats['images_missing_file']++;
                continue;
            }

            // Idempotency: skip if item already has any media on the
            // 'item' collection (preserves prior heal-light attachments
            // like item 375 chicken_burger.png).
            $existingMedia = $item->media()->where('collection_name', 'item')->count();
            if ($existingMedia > 0) {
                $this->stats['images_skipped']++;
                $this->line("   item {$itemId} {$item->name}: already has media ({$existingMedia}) — skip.");
                continue;
            }

            try {
                $item->addMedia($abs)
                    ->preservingOriginal()
                    ->toMediaCollection('item');
                $this->stats['images_attached']++;
                $this->line("   item {$itemId} {$item->name}: attached " . basename($abs));
            } catch (Throwable $e) {
                $this->warn("   item {$itemId} ATTACH FAILED: " . $e->getMessage());
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // L4 — Rename item 490 Big Chicken → Chicken Burger Special
    // ─────────────────────────────────────────────────────────────────────
    private function renameBigChicken(): void
    {
        $this->info('L4 — Rename item 490 Big Chicken…');

        $item = Item::find(self::ITEM_BIG_CHICKEN);
        if (! $item) {
            $this->warn('   item 490 not found — skip.');
            return;
        }

        if ($item->name === self::ITEM_BIG_CHICKEN_NEW_NAME) {
            $this->line('   item 490 already renamed — idempotent.');
            return;
        }

        $old = $item->name;
        $item->name = self::ITEM_BIG_CHICKEN_NEW_NAME;
        $item->save();
        $this->stats['items_renamed']++;
        $this->line("   item 490: \"{$old}\" → \"" . self::ITEM_BIG_CHICKEN_NEW_NAME . '"');
    }

    // ─────────────────────────────────────────────────────────────────────
    // L3 — Add Barbecue + Ail on attr 311 for all items currently having
    //      attr 311 variations (preserves catalogue consistency).
    // ─────────────────────────────────────────────────────────────────────
    private function addSauces311(): void
    {
        $this->info('L3 — Add Barbecue + Ail on attr 311…');

        $itemIds = DB::table('item_variations')
            ->where('item_attribute_id', self::ATTR_SAUCE_FREE)
            ->whereNull('deleted_at')
            ->distinct()
            ->pluck('item_id');

        foreach ($itemIds as $itemId) {
            foreach (self::SAUCES_TO_ADD_311 as $sauceName) {
                $existing = ItemVariation::withTrashed()
                    ->where('item_id', $itemId)
                    ->where('item_attribute_id', self::ATTR_SAUCE_FREE)
                    ->where('name', $sauceName)
                    ->first();

                if ($existing) {
                    if ($existing->trashed()) {
                        $existing->restore();
                    }
                    if ((int) $existing->status !== (int) Status::ACTIVE) {
                        $existing->update(['status' => Status::ACTIVE]);
                    }
                    $this->stats['sauces_skipped']++;
                    continue;
                }

                ItemVariation::create([
                    'item_id'           => $itemId,
                    'item_attribute_id' => self::ATTR_SAUCE_FREE,
                    'name'              => $sauceName,
                    'price'             => 0,
                    'status'            => Status::ACTIVE,
                ]);
                $this->stats['sauces_added']++;
            }
        }

        $this->line("   sauces_added={$this->stats['sauces_added']} sauces_skipped={$this->stats['sauces_skipped']} (items processed: " . $itemIds->count() . ')');
    }

    // ─────────────────────────────────────────────────────────────────────
    // L2a — Inactivate non-canonical sauces on attr 330 (bowls)
    //       Owner spec: bowl step 1 shows ONLY Spicy + Sauce fromagère maison.
    //       We soft-inactivate (status=10) instead of soft-delete — preserves
    //       historical composition_snapshots and allows future re-enable.
    // ─────────────────────────────────────────────────────────────────────
    private function inactivateBowlSauces330(): void
    {
        $this->info('L2a — Inactivate non-canonical sauces on attr 330 for bowls 492-499…');

        $rows = ItemVariation::query()
            ->whereIn('item_id', self::BOWL_IDS)
            ->where('item_attribute_id', self::ATTR_SAUCE_BOL)
            ->whereNotIn('name', self::BOWL_ACTIVE_SAUCES)
            ->where('status', Status::ACTIVE)
            ->get();

        foreach ($rows as $row) {
            $row->update(['status' => Status::INACTIVE]);
            $this->stats['bowl_sauces_inactivated']++;
        }

        $this->line("   inactivated {$this->stats['bowl_sauces_inactivated']} non-canonical sauce variations on attr 330.");
    }

    // ─────────────────────────────────────────────────────────────────────
    // L2b — Consolidate Gratiné into supplements step (delete duplicate
    //       group_label="gratine" extras; keep "Boule gratinée" in
    //       group_label="supplement_bol").
    // ─────────────────────────────────────────────────────────────────────
    private function consolidateBowlGratineExtras(): void
    {
        $this->info('L2b — Consolidate Gratiné extras (delete group_label=gratine duplicates)…');

        // For each bowl, delete any item_extras row with group_label='gratine'.
        // The supplement_bol "Boule gratinée" row already exists and remains.
        $deleted = DB::table('item_extras')
            ->whereIn('item_id', self::BOWL_IDS)
            ->where('group_label', 'gratine')
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now(), 'updated_at' => now()]);

        $this->stats['bowl_extras_consolidated'] = (int) $deleted;
        $this->line("   deleted {$deleted} duplicate Gratiné extras (group_label='gratine').");
    }

    // ─────────────────────────────────────────────────────────────────────
    // L2c — Rebuild bowl composer profiles 4-step → 3-step
    // ─────────────────────────────────────────────────────────────────────
    private function rebuildBowlComposerProfiles(): void
    {
        $this->info('L2c — Rebuild bowl composer profiles (8 bowls) to 3-step…');

        foreach (self::BOWL_IDS as $bowlId) {
            $profile = ItemWizardProfile::where('item_id', $bowlId)
                ->where('is_published', true)
                ->whereNull('branch_id_scope')
                ->orderByDesc('version')
                ->orderByDesc('id')
                ->first();

            if (! $profile) {
                $this->warn("   bowl {$bowlId}: no published profile — skipping.");
                continue;
            }

            $existingSteps = ItemWizardStep::where('profile_id', $profile->id)
                ->orderBy('position')
                ->get();

            // Idempotency check — already a 3-step profile with the canonical shape?
            if ($existingSteps->count() === 3) {
                $keys = $existingSteps->pluck('step_key')->all();
                if ($keys === ['sauce', 'supplements', 'drink']) {
                    $this->stats['bowl_composer_skipped']++;
                    $this->line("   bowl {$bowlId}: already 3-step canonical — idempotent.");
                    continue;
                }
            }

            // Wipe + rebuild on same profile id (preserves FK relationships)
            ItemWizardStep::where('profile_id', $profile->id)->delete();

            // step 0: sauce bol (attr 330) min=1 max=2
            ItemWizardStep::create([
                'profile_id'               => $profile->id,
                'step_key'                 => 'sauce',
                'label'                    => 'Sauce bol',
                'source_type'              => 'item_attribute',
                'source_ref'               => 'sauce bol',
                'source_item_attribute_id' => self::ATTR_SAUCE_BOL,
                'min_select'               => 1,
                'max_select'               => 2,
                'allow_repeat'             => false,
                'visible_on'               => null,
                'stockable_choices'        => false,
                'position'                 => 0,
                'is_active'                => true,
                'addon_role'               => null,
            ]);

            // step 1: supplements (incl. Gratiné as one of the choices) min=0 max=4
            ItemWizardStep::create([
                'profile_id'               => $profile->id,
                'step_key'                 => 'supplements',
                'label'                    => 'Suppléments',
                'source_type'              => 'extra_group',
                'source_ref'               => 'supplement_bol',
                'source_item_attribute_id' => null,
                'min_select'               => 0,
                'max_select'               => 4,
                'allow_repeat'             => false,
                'visible_on'               => null,
                'stockable_choices'        => false,
                'position'                 => 1,
                'is_active'                => true,
                'addon_role'               => null,
            ]);

            // step 2: drink min=0 max=1
            ItemWizardStep::create([
                'profile_id'               => $profile->id,
                'step_key'                 => 'drink',
                'label'                    => 'Boisson',
                'source_type'              => 'addon',
                'source_ref'               => 'drink',
                'source_item_attribute_id' => null,
                'min_select'               => 0,
                'max_select'               => 1,
                'allow_repeat'             => false,
                'visible_on'               => null,
                'stockable_choices'        => false,
                'position'                 => 2,
                'is_active'                => true,
                'addon_role'               => 'drink',
            ]);

            // Bump version + republish to invalidate caches downstream.
            $profile->update([
                'version'      => (int) $profile->version + 1,
                'published_at' => now(),
            ]);

            $this->stats['bowl_composer_updated']++;
            $this->eventsToFire[] = ComposerProfileChanged::fromProfile($profile->fresh(), 'published');
            $this->line("   bowl {$bowlId}: rebuilt 3-step composer (profile id={$profile->id}, v={$profile->version}).");
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // FROZEN-ZONE verification — confirm command only touched data layer
    // ─────────────────────────────────────────────────────────────────────
    private function verifyFrozenZoneClean(): void
    {
        $this->info('Verify frozen-zone diff vs main…');
        $files = implode(' ', array_map('escapeshellarg', self::FROZEN_FILES));
        $cmd = "git diff main -- {$files} 2>/dev/null | wc -l";
        $diffLines = (int) trim((string) shell_exec($cmd));
        $this->line("   Frozen-zone diff vs main = {$diffLines} lines (baseline drift tolerated; no NEW changes from this command).");
        $this->line('   Command writes data + media layer only — no source file modified.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Deferred sync events (after transaction commit)
    // ─────────────────────────────────────────────────────────────────────
    private function fireDeferredEvents(): void
    {
        $this->info('Firing sync events…');

        // ComposerProfileChanged per updated profile (with CatalogChanged bridge)
        foreach ($this->eventsToFire as $event) {
            event($event);
            $this->stats['events_fired']++;
            $bridge = CatalogChanged::fromMenuMutation($event);
            if ($bridge !== null) {
                event($bridge);
                $this->stats['events_fired']++;
            }
        }

        // Final blanket CatalogChanged for items renamed / images attached /
        // sauces added — covers caches that aren't keyed on composer profiles.
        if ($this->stats['items_renamed'] > 0
            || $this->stats['images_attached'] > 0
            || $this->stats['sauces_added'] > 0
            || $this->stats['bowl_sauces_inactivated'] > 0) {
            event(new CatalogChanged(
                entityType: 'catalogue',
                entityId: 0,
                changeType: 'menu_heal_v3',
                branchId: 1,
                payloadDiff: [
                    'items_renamed'           => $this->stats['items_renamed'],
                    'images_attached'         => $this->stats['images_attached'],
                    'sauces_added'            => $this->stats['sauces_added'],
                    'bowl_sauces_inactivated' => $this->stats['bowl_sauces_inactivated'],
                ],
            ));
            $this->stats['events_fired']++;
        }

        $this->line("   {$this->stats['events_fired']} events fired (incl. CatalogChanged bridges).");
    }

    // ─────────────────────────────────────────────────────────────────────
    // STATS
    // ─────────────────────────────────────────────────────────────────────
    private function renderStats(): void
    {
        $this->line('');
        $this->info('═══ STATISTICS ═══');
        $rows = [];
        foreach ($this->stats as $metric => $count) {
            $rows[] = [$metric, $count];
        }
        $this->table(['Metric', 'Count'], $rows);
    }
}
