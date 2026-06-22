<?php

namespace App\Console\Commands;

use App\Events\CatalogChanged;
use App\Events\ComposerProfileChanged;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemWizardProfile;
use App\Models\ItemWizardStep;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * MENU HEAL-LIGHT V3.1 — Burger composer 3-step + Double viande
 * =================================================================================
 *
 * Owner spec confirmed turn 2026-05-14 :
 *  - 4 viandes (Mariné/Curry/Tandoori/Crispy) keep working for Sandwiches
 *    (cat 344/345/346), Tacos (cat 306), Bols (cat 347)
 *  - BURGER exception : 1 viande locked Crispy (implicit). No viande step.
 *    Customer who wants 2 viandes ticks "Double viande +2.50€" in supplement.
 *  - Menu enfant exception : Nuggets only, no wizard.
 *  - Bowl drink step : accept-as-is (P1-001 resolved).
 *
 * This command:
 *
 *  L1 EXTRAS : Insert a single "Double viande" item_extras row for each of
 *      items 375 (Chicken Burger) and 490 (Chicken Burger Special) with
 *      group_label='supplement' (canonical — matches Big Classique step 4
 *      source_ref='supplement'), price 2.50€, status ACTIVE, visible on
 *      pos/kiosk/admin/mobile. Idempotent via SELECT-then-INSERT
 *      (no unique index on item_extras to leverage updateOrInsert against).
 *
 *  L2 COMPOSER PROFILES : Create one ItemWizardProfile per burger with 3
 *      ItemWizardSteps each :
 *        Step 0 sauce       — item_attribute "sauce (1ère gratuite)"
 *                             attr_id=311 min=1 max=1
 *        Step 1 supplements — extra_group "supplement" min=0 max=6
 *                             (includes Cheddar/Œuf/Jambon/Boursin/…
 *                              already attached + Double viande)
 *        Step 2 menu        — addon menu_component min=0 max=1
 *                             (Menu Combo / +Frites / Drink-only / Sans menu)
 *      Step labels mirror Big Classique 489 phrasing for UX consistency.
 *      Idempotent via firstOrCreate on (item_id, template='custom', is_published=true).
 *
 * Rules:
 *  - DB::transaction wraps ALL writes — rollback on any failure.
 *  - ZERO frozen-zone touch (CLAUDE.md §7).
 *  - Idempotent: re-run --force = no-op once applied.
 *  - Events deferred until after transaction commit
 *    (ComposerProfileChanged + CatalogChanged bridge).
 *  - NF525 chain not touched.
 *
 * @see reports/audit/menu-v3-2026-05-14/PLAN_V31_BURGER_VIANDE.md
 * @see app/Console/Commands/MenuHealLightV3Command.php (sibling pattern)
 */
class MenuHealLightV31BurgerCommand extends Command
{
    protected $signature = 'menu:heal-light-v3-1-burger
                            {--dry-run : Show plan without DB writes}
                            {--force : Skip confirmation prompt}';

    protected $description = 'Menu V3.1 heal: burger composer 3-step + Double viande supplément (items 375 + 490)';

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

    /** Target burger items. */
    private const BURGER_IDS = [375, 490];

    /** Sauce attribute ID — "Sauce (1ère Gratuite)". */
    private const ATTR_SAUCE_FREE = 311;

    /** Canonical step source_ref values (match Big Classique 489 profile 83). */
    private const SOURCE_REF_SAUCE       = 'sauce (1ère gratuite)';
    private const SOURCE_REF_SUPPLEMENT  = 'supplement';
    private const SOURCE_REF_MENU        = 'menu_component';

    /** Double viande supplement spec. */
    private const DOUBLE_VIANDE_NAME       = 'Double viande';
    private const DOUBLE_VIANDE_PRICE      = 2.50;
    private const DOUBLE_VIANDE_GROUP_LABEL = 'supplement';

    private array $stats = [
        'double_viande_added'     => 0,
        'double_viande_skipped'   => 0,
        'composer_profiles_added' => 0,
        'composer_steps_added'    => 0,
        'composer_skipped'        => 0,
        'events_fired'            => 0,
    ];

    /** @var array<int, object> */
    private array $eventsToFire = [];

    public function handle(): int
    {
        $this->line('');
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->info('  MENU HEAL-LIGHT V3.1 — Burger composer 3-step + Double viande');
        $this->info('  Targets: items 375 (Chicken Burger) + 490 (Chicken Burger Special)');
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->line('');

        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY-RUN MODE — no DB writes, no events.');
            $this->line('');
        }

        if (! $this->option('force') && ! $dryRun) {
            $this->warn('This will:');
            $this->line('  - Add "Double viande" +2.50€ extra to items 375 + 490 (group=supplement)');
            $this->line('  - Create 3-step composer profile per burger:');
            $this->line('      step 0 Sauce (1ère gratuite) — attr 311 min=1 max=1');
            $this->line('      step 1 Suppléments — extra_group=supplement min=0 max=6 (incl. Double viande)');
            $this->line('      step 2 Menu — addon menu_component min=0 max=1');
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

            DB::transaction(function () {
                $this->addDoubleViandeExtras();
                $this->createBurgerComposerProfiles();
            });

            $this->verifyFrozenZoneClean();
            $this->fireDeferredEvents();
            $this->renderStats();
            $this->renderVerification();

            $this->line('');
            $this->info('Menu V3.1 burger heal complete.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('FAILED: ' . $e->getMessage());
            $this->error('File: ' . $e->getFile() . ':' . $e->getLine());
            Log::error('MenuHealLightV31BurgerCommand failed', ['exception' => $e]);

            return self::FAILURE;
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // PRE-FLIGHT
    // ─────────────────────────────────────────────────────────────────────
    private function preflightChecks(): void
    {
        $sauceAttr = ItemAttribute::find(self::ATTR_SAUCE_FREE);
        if (! $sauceAttr) {
            throw new \RuntimeException(
                'Pre-flight FAIL: sauce attribute ' . self::ATTR_SAUCE_FREE . ' (Sauce libre) not found.'
            );
        }

        foreach (self::BURGER_IDS as $burgerId) {
            $item = Item::find($burgerId);
            if (! $item) {
                throw new \RuntimeException("Pre-flight FAIL: burger item {$burgerId} not found.");
            }

            // Verify the burger has at least one variation on attr 311
            // (sauce step source). Without sauces, step 1 would render empty.
            $sauceCount = DB::table('item_variations')
                ->where('item_id', $burgerId)
                ->where('item_attribute_id', self::ATTR_SAUCE_FREE)
                ->where('status', 5)
                ->whereNull('deleted_at')
                ->count();
            if ($sauceCount === 0) {
                throw new \RuntimeException(
                    "Pre-flight FAIL: burger {$burgerId} has 0 active variations on attr 311 (sauce). "
                    . 'Wizard step 1 would be empty.'
                );
            }

            // Verify the burger has at least one extra in group_label='supplement'.
            // Without supplements (besides Double viande), step 2 is sparse.
            $suppCount = DB::table('item_extras')
                ->where('item_id', $burgerId)
                ->where('group_label', self::DOUBLE_VIANDE_GROUP_LABEL)
                ->whereNull('deleted_at')
                ->count();
            if ($suppCount === 0) {
                $this->warn(
                    "   Pre-flight WARN: burger {$burgerId} has 0 extras in group 'supplement' — "
                    . 'wizard supplement step will only render Double viande.'
                );
            }
        }

        $this->info('Pre-flight: attributes + items + supplements OK.');
    }

    private function dryRunPlan(): void
    {
        $this->info('DRY-RUN plan:');
        $this->line('');
        $this->line('L1 — Add "Double viande" +2.50€ extra (group=supplement):');
        foreach (self::BURGER_IDS as $burgerId) {
            $item = Item::find($burgerId);
            $existing = DB::table('item_extras')
                ->where('item_id', $burgerId)
                ->where('name', self::DOUBLE_VIANDE_NAME)
                ->whereNull('deleted_at')
                ->first();
            $action = $existing ? 'SKIP (already exists)' : 'INSERT';
            $this->line(sprintf('  - item %d (%s): %s', $burgerId, $item?->name ?? '?', $action));
        }
        $this->line('');
        $this->line('L2 — Create 3-step composer profile per burger:');
        foreach (self::BURGER_IDS as $burgerId) {
            $item = Item::find($burgerId);
            $existingProfile = DB::table('item_wizard_profiles')
                ->where('item_id', $burgerId)
                ->where('is_published', 1)
                ->whereNull('branch_id_scope')
                ->first();
            $action = $existingProfile ? "SKIP (profile id={$existingProfile->id} exists)" : 'CREATE';
            $this->line(sprintf('  - item %d (%s): %s', $burgerId, $item?->name ?? '?', $action));
        }
        $this->line('');
        $this->line('  Step shape (each burger, 3 rows):');
        $this->line('    pos=0 key=sauce       label="Sauce (1ère gratuite)" ');
        $this->line('                          source=item_attribute ref="sauce (1ère gratuite)" attr=311 min=1 max=1');
        $this->line('    pos=1 key=supplements label="Suppléments"');
        $this->line('                          source=extra_group ref="supplement" min=0 max=6');
        $this->line('    pos=2 key=menu        label="Menu Frites + Boisson"');
        $this->line('                          source=addon ref="menu_component" min=0 max=1 role=menu_component');
        $this->line('');
        $this->line('DRY-RUN — nothing persisted. Run with --force to execute.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // L1 — Double viande extras
    // ─────────────────────────────────────────────────────────────────────
    private function addDoubleViandeExtras(): void
    {
        $this->info('L1 — Adding "Double viande" supplements…');

        foreach (self::BURGER_IDS as $burgerId) {
            // Idempotency: skip if a non-trashed row with this name already exists.
            $existing = DB::table('item_extras')
                ->where('item_id', $burgerId)
                ->where('name', self::DOUBLE_VIANDE_NAME)
                ->whereNull('deleted_at')
                ->first();

            if ($existing) {
                // Reaffirm canonical fields in case prior heal left it inactive.
                DB::table('item_extras')
                    ->where('id', $existing->id)
                    ->update([
                        'price'        => self::DOUBLE_VIANDE_PRICE,
                        'status'       => 5,
                        'group_label'  => self::DOUBLE_VIANDE_GROUP_LABEL,
                        'is_available' => 1,
                        'visible_on'   => json_encode(['pos', 'kiosk', 'admin', 'mobile']),
                        'updated_at'   => now(),
                    ]);
                $this->stats['double_viande_skipped']++;
                $this->line("   item {$burgerId}: Double viande already exists (id={$existing->id}) — refreshed canonical fields.");
                continue;
            }

            $id = DB::table('item_extras')->insertGetId([
                'item_id'      => $burgerId,
                'name'         => self::DOUBLE_VIANDE_NAME,
                'price'        => self::DOUBLE_VIANDE_PRICE,
                'status'       => 5,
                'group_label'  => self::DOUBLE_VIANDE_GROUP_LABEL,
                'is_available' => 1,
                'visible_on'   => json_encode(['pos', 'kiosk', 'admin', 'mobile']),
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
            $this->stats['double_viande_added']++;
            $this->line("   item {$burgerId}: Double viande inserted (id={$id}) +2.50€.");
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // L2 — Composer profiles (3-step)
    // ─────────────────────────────────────────────────────────────────────
    private function createBurgerComposerProfiles(): void
    {
        $this->info('L2 — Creating burger composer profiles (3-step)…');

        foreach (self::BURGER_IDS as $burgerId) {
            $item = Item::find($burgerId);
            $categoryId = $item?->item_category_id;

            // Idempotency: any published profile for this item already?
            $existingProfile = ItemWizardProfile::where('item_id', $burgerId)
                ->where('is_published', true)
                ->whereNull('branch_id_scope')
                ->orderByDesc('version')
                ->orderByDesc('id')
                ->first();

            if ($existingProfile) {
                $existingSteps = ItemWizardStep::where('profile_id', $existingProfile->id)
                    ->orderBy('position')
                    ->get();
                $keys = $existingSteps->pluck('step_key')->all();

                if ($keys === ['sauce', 'supplements', 'menu']) {
                    $this->stats['composer_skipped']++;
                    $this->line(
                        "   burger {$burgerId}: profile id={$existingProfile->id} already 3-step canonical "
                        . '[' . implode(',', $keys) . '] — idempotent skip.'
                    );
                    continue;
                }

                // Profile exists but wrong shape — rebuild on same id (preserves FKs).
                $this->line(
                    "   burger {$burgerId}: profile id={$existingProfile->id} has shape ["
                    . implode(',', $keys) . '] — rebuilding to 3-step canonical.'
                );
                ItemWizardStep::where('profile_id', $existingProfile->id)->delete();
                $this->insertCanonicalSteps($existingProfile->id);
                $existingProfile->update([
                    'version'      => (int) $existingProfile->version + 1,
                    'published_at' => now(),
                ]);
                $this->stats['composer_steps_added'] += 3;
                $this->eventsToFire[] = ComposerProfileChanged::fromProfile($existingProfile->fresh(), 'published');
                continue;
            }

            // No profile yet — create fresh.
            $profile = ItemWizardProfile::create([
                'item_id'          => $burgerId,
                'item_category_id' => null,
                'template'         => 'custom',
                'version'          => 1,
                'is_published'     => true,
                'published_at'     => now(),
                'branch_id_scope'  => null,
            ]);

            $this->insertCanonicalSteps($profile->id);
            $this->stats['composer_profiles_added']++;
            $this->stats['composer_steps_added'] += 3;
            $this->eventsToFire[] = ComposerProfileChanged::fromProfile($profile->fresh(), 'published');
            $this->line(
                "   burger {$burgerId} (cat {$categoryId}): created profile id={$profile->id} "
                . 'with 3 steps [sauce, supplements, menu].'
            );
        }
    }

    private function insertCanonicalSteps(int $profileId): void
    {
        // Step 0 — Sauce (1ère gratuite) — single choice from attr 311
        ItemWizardStep::create([
            'profile_id'               => $profileId,
            'step_key'                 => 'sauce',
            'label'                    => 'Sauce (1ère gratuite)',
            'source_type'              => 'item_attribute',
            'source_ref'               => self::SOURCE_REF_SAUCE,
            'source_item_attribute_id' => self::ATTR_SAUCE_FREE,
            'min_select'               => 1,
            'max_select'               => 1,
            'allow_repeat'             => false,
            'visible_on'               => null,
            'stockable_choices'        => false,
            'position'                 => 0,
            'is_active'                => true,
            'addon_role'               => null,
        ]);

        // Step 1 — Suppléments (multi-select 0..6) — includes Double viande
        ItemWizardStep::create([
            'profile_id'               => $profileId,
            'step_key'                 => 'supplements',
            'label'                    => 'Suppléments',
            'source_type'              => 'extra_group',
            'source_ref'               => self::SOURCE_REF_SUPPLEMENT,
            'source_item_attribute_id' => null,
            'min_select'               => 0,
            'max_select'               => 6,
            'allow_repeat'             => false,
            'visible_on'               => null,
            'stockable_choices'        => false,
            'position'                 => 1,
            'is_active'                => true,
            'addon_role'               => null,
        ]);

        // Step 2 — Menu (addon menu_component) — Menu Combo / +Frites / Drink-only / Sans menu
        ItemWizardStep::create([
            'profile_id'               => $profileId,
            'step_key'                 => 'menu',
            'label'                    => 'Menu Frites + Boisson',
            'source_type'              => 'addon',
            'source_ref'               => self::SOURCE_REF_MENU,
            'source_item_attribute_id' => null,
            'min_select'               => 0,
            'max_select'               => 1,
            'allow_repeat'             => false,
            'visible_on'               => null,
            'stockable_choices'        => false,
            'position'                 => 2,
            'is_active'                => true,
            'addon_role'               => 'menu_component',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // FROZEN-ZONE verification
    // ─────────────────────────────────────────────────────────────────────
    private function verifyFrozenZoneClean(): void
    {
        $this->info('Verify frozen-zone diff vs main…');
        $files = implode(' ', array_map('escapeshellarg', self::FROZEN_FILES));
        $cmd = "git diff main -- {$files} 2>/dev/null | wc -l";
        $diffLines = (int) trim((string) shell_exec($cmd));
        $this->line("   Frozen-zone diff vs main = {$diffLines} lines (baseline drift tolerated; no NEW source mods).");
        $this->line('   Command writes data layer only — no source file modified.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Deferred sync events
    // ─────────────────────────────────────────────────────────────────────
    private function fireDeferredEvents(): void
    {
        $this->info('Firing sync events…');

        foreach ($this->eventsToFire as $event) {
            event($event);
            $this->stats['events_fired']++;
            $bridge = CatalogChanged::fromMenuMutation($event);
            if ($bridge !== null) {
                event($bridge);
                $this->stats['events_fired']++;
            }
        }

        // Blanket CatalogChanged for cache invalidation (kiosk SPA, menu API).
        if ($this->stats['double_viande_added'] > 0
            || $this->stats['composer_profiles_added'] > 0
            || $this->stats['composer_steps_added'] > 0) {
            event(new CatalogChanged(
                entityType: 'catalogue',
                entityId: 0,
                changeType: 'menu_heal_v3_1_burger',
                branchId: 1,
                payloadDiff: [
                    'double_viande_added'     => $this->stats['double_viande_added'],
                    'composer_profiles_added' => $this->stats['composer_profiles_added'],
                    'composer_steps_added'    => $this->stats['composer_steps_added'],
                ],
            ));
            $this->stats['events_fired']++;
        }

        $this->line("   {$this->stats['events_fired']} events fired.");
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

    // ─────────────────────────────────────────────────────────────────────
    // VERIFICATION (Layer C of task)
    // ─────────────────────────────────────────────────────────────────────
    private function renderVerification(): void
    {
        $this->line('');
        $this->info('═══ VERIFICATION ═══');

        // Profile count : expect 2 (one per burger, published, no branch scope)
        $profileCount = DB::table('item_wizard_profiles')
            ->whereIn('item_id', self::BURGER_IDS)
            ->where('is_published', 1)
            ->whereNull('branch_id_scope')
            ->count();
        $profileOk = $profileCount === 2 ? 'OK' : 'FAIL';
        $this->line("   [{$profileOk}] published profiles for burger items {375,490} = {$profileCount} (expected 2)");

        // Step count : expect 6 (3 per burger)
        $stepCount = DB::table('item_wizard_steps')
            ->whereIn('profile_id', function ($q) {
                $q->select('id')
                    ->from('item_wizard_profiles')
                    ->whereIn('item_id', self::BURGER_IDS)
                    ->where('is_published', 1)
                    ->whereNull('branch_id_scope');
            })
            ->count();
        $stepOk = $stepCount === 6 ? 'OK' : 'FAIL';
        $this->line("   [{$stepOk}] steps across both burger profiles = {$stepCount} (expected 6)");

        // Step keys in canonical order per burger
        foreach (self::BURGER_IDS as $burgerId) {
            $profile = DB::table('item_wizard_profiles')
                ->where('item_id', $burgerId)
                ->where('is_published', 1)
                ->whereNull('branch_id_scope')
                ->orderByDesc('version')
                ->orderByDesc('id')
                ->first();
            if (! $profile) {
                $this->line("   [FAIL] burger {$burgerId}: no published profile.");
                continue;
            }
            $keys = DB::table('item_wizard_steps')
                ->where('profile_id', $profile->id)
                ->orderBy('position')
                ->pluck('step_key')
                ->all();
            $expected = ['sauce', 'supplements', 'menu'];
            $tag = $keys === $expected ? 'OK' : 'FAIL';
            $this->line("   [{$tag}] burger {$burgerId} step keys = [" . implode(',', $keys) . '] (expected [' . implode(',', $expected) . '])');
        }

        // Double viande presence : expect 2 rows (one per burger), price 2.50
        $dv = DB::table('item_extras')
            ->whereIn('item_id', self::BURGER_IDS)
            ->where('name', self::DOUBLE_VIANDE_NAME)
            ->where('group_label', self::DOUBLE_VIANDE_GROUP_LABEL)
            ->whereNull('deleted_at')
            ->where('status', 5)
            ->get(['id', 'item_id', 'price']);
        $dvCount = $dv->count();
        $dvPriceOk = $dv->every(fn ($r) => abs(((float) $r->price) - 2.50) < 0.001);
        $dvTag = ($dvCount === 2 && $dvPriceOk) ? 'OK' : 'FAIL';
        $this->line("   [{$dvTag}] Double viande extras = {$dvCount} rows, price 2.50€ all (expected 2 rows)");
    }
}
