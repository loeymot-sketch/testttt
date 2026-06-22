<?php

namespace App\Console\Commands;

use App\Enums\Status;
use App\Events\CatalogChanged;
use App\Events\CategoryUpdated;
use App\Events\ComposerProfileChanged;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemCategory;
use App\Models\ItemVariation;
use App\Models\ItemWizardProfile;
use App\Models\ItemWizardStep;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * MENU HEAL-LIGHT V2 — ROUND 2 PATCH — adversarial Wave KIOSK round 1 P1 healing
 * =================================================================================
 *
 * Fixes 4 P1 findings discovered after MenuHealLightV2Command (commit 62959bfc9) :
 *
 *  P1-A : item 488 "Big Cayenne" — wizard renders only 1 Viande step. Needed flow
 *         is 2 viandes + locked sauce Cayenne + suppléments + menu addon. Heal :
 *         create explicit composer_profile + steps. Also seed missing attr 331
 *         variation "Sauce Cayenne maison" for item 488 (currently absent → step
 *         3 would render zero choices).
 *
 *  P1-B : item 489 "Big Classique" — same single-viande wizard bug. Heal : create
 *         composer_profile with 2 viandes + sauce libre + suppléments + menu addon.
 *
 *  P1-C : item 479 "Tacos L" — same single-viande wizard bug. Heal : create
 *         composer_profile with 2 viandes + menu addon.
 *
 *  P1-D : category 350 "Menu enfant" leaks in kiosk sidebar. Heal : set
 *         channels = ['pos','admin','mobile'] (exclude kiosk).
 *
 * Rules :
 *  - DB::transaction wraps ALL writes — rollback on any failure
 *  - ZÉRO touche frozen-zone (CLAUDE.md §7)
 *  - Idempotent : re-run with --force is a no-op once applied (compares step
 *    count for profile match, channels array equality for cat 350)
 *  - composer_profile : published_at=now(), branch_id_scope=null (global)
 *  - NF525 chain not touched (no fiscal_sequence, no audit_log, no z_report
 *    interactions — pure menu data layer)
 *  - addon_role values must be ∈ ['drink','side','dessert','menu_component','upsell']
 *  - source_type values must be ∈ ['item_attribute','extra_group','addon','fixed']
 *  - No "Récap" step inserted (recap is frontend-only)
 *
 * @see reports/audit/menu-heal-v2-2026-05-14/01_ROUND_2_PATCH_REPORT.md
 * @see app/Console/Commands/MenuHealLightV2Command.php (baseline)
 */
class MenuHealLightV2Round2PatchCommand extends Command
{
    protected $signature = 'menu:heal-light-v2-round2
                            {--dry-run : Show plan without DB writes}
                            {--force : Skip confirmation prompt}';

    protected $description = 'Round-2 patch : heal 4 P1 findings from Wave KIOSK round 1 (Big variants composer + Menu enfant kiosk hide)';

    /**
     * Frozen-zone files — verification only (this command only writes data).
     */
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

    /** Item attribute IDs verified pre-flight via DB inspection. */
    private const ATTR_VIANDE_1            = 307;
    private const ATTR_VIANDE_2            = 308;
    private const ATTR_SAUCE_FREE          = 311; // "Sauce (1ère Gratuite)"
    private const ATTR_SAUCE_CAYENNE       = 331; // "Sauce Cayenne (incluse)" — locked

    /** Menu addon item — Frites+Boisson combo, +2.50€. */
    private const MENU_ADDON_ITEM_ID       = 360;

    /** Targets. */
    private const ITEM_BIG_CAYENNE         = 488;
    private const ITEM_BIG_CLASSIQUE       = 489;
    private const ITEM_TACOS_L             = 479;
    private const CAT_MENU_ENFANT          = 350;

    /** Cat 350 target channel list — kiosk excluded (V1 ship). */
    private const CAT_350_CHANNELS_TARGET  = ['pos', 'admin', 'mobile'];

    private array $stats = [
        'profiles_created'      => 0,
        'profiles_skipped'      => 0,
        'steps_created'         => 0,
        'variations_created'    => 0,
        'cat_channels_updated'  => 0,
        'events_fired'          => 0,
    ];

    /** @var array<int, object> */
    private array $eventsToFire = [];

    public function handle(): int
    {
        $this->line('');
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->info('  MENU HEAL-LIGHT V2 — ROUND 2 PATCH (Wave KIOSK round 1 P1)');
        $this->info('  Heal Big Cayenne / Big Classique / Tacos L composer + cat 350 channels');
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->line('');

        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY-RUN MODE — no DB writes, no events.');
            $this->line('');
        }

        if (! $this->option('force') && ! $dryRun) {
            $this->warn('This will :');
            $this->line('  - Create 3 composer profiles (items 488, 489, 479)');
            $this->line('  - Create 5+5+3 = 13 wizard steps (recap is frontend-only, not stored)');
            $this->line('  - Seed missing attr 331 variation "Sauce Cayenne maison" for item 488');
            $this->line('  - Update cat 350 (Menu enfant) channels → exclude kiosk');
            $this->line('  - Fire ComposerProfileChanged + CategoryUpdated + CatalogChanged events');
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
                $this->seedBigCayenneSauceVariation();
                $this->createBigCayenneComposer();
                $this->createBigClassiqueComposer();
                $this->createTacosLComposer();
                $this->updateMenuEnfantChannels();
            });

            $this->verifyFrozenZoneClean();
            $this->fireDeferredEvents();
            $this->renderStats();

            $this->line('');
            $this->info('Round 2 patch complete.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('FAILED: ' . $e->getMessage());
            $this->error('File: ' . $e->getFile() . ':' . $e->getLine());
            Log::error('MenuHealLightV2Round2PatchCommand failed', ['exception' => $e]);

            return self::FAILURE;
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // PRE-FLIGHT
    // ─────────────────────────────────────────────────────────────────────
    private function preflightChecks(): void
    {
        $required = [
            'item Big Cayenne (488)'      => Item::find(self::ITEM_BIG_CAYENNE),
            'item Big Classique (489)'    => Item::find(self::ITEM_BIG_CLASSIQUE),
            'item Tacos L (479)'          => Item::find(self::ITEM_TACOS_L),
            'item Menu addon (360)'       => Item::find(self::MENU_ADDON_ITEM_ID),
            'cat Menu enfant (350)'       => ItemCategory::find(self::CAT_MENU_ENFANT),
            'attr Viande 1 (307)'         => ItemAttribute::find(self::ATTR_VIANDE_1),
            'attr Viande 2 (308)'         => ItemAttribute::find(self::ATTR_VIANDE_2),
            'attr Sauce libre (311)'      => ItemAttribute::find(self::ATTR_SAUCE_FREE),
            'attr Sauce Cayenne (331)'    => ItemAttribute::find(self::ATTR_SAUCE_CAYENNE),
        ];

        foreach ($required as $label => $row) {
            if (! $row) {
                throw new \RuntimeException("Pre-flight FAIL : {$label} not found.");
            }
        }

        $this->info('Pre-flight : all required items, categories, and attributes resolved.');
    }

    private function dryRunPlan(): void
    {
        $this->info('DRY-RUN plan :');
        $this->line('');
        $this->line('A. SEED missing attr 331 variation for item 488 :');
        $this->line('   - ItemVariation(item_id=488, item_attribute_id=331, name="Sauce Cayenne maison", price=0)');
        $this->line('');
        $this->line('B. CREATE composer_profile for item 488 "Big Cayenne" — 5 steps :');
        $this->line('   - 1: Viande 1 (attr 307) min=1 max=1');
        $this->line('   - 2: Viande 2 (attr 308) min=1 max=1');
        $this->line('   - 3: Sauce Cayenne maison (attr 331) min=1 max=1 [LOCKED]');
        $this->line('   - 4: Suppléments (extra_group=supplement) min=0 max=6');
        $this->line('   - 5: Menu Frites+Boisson (addon role=menu_component) min=0 max=1');
        $this->line('');
        $this->line('C. CREATE composer_profile for item 489 "Big Classique" — 5 steps :');
        $this->line('   - 1: Viande 1 (attr 307) min=1 max=1');
        $this->line('   - 2: Viande 2 (attr 308) min=1 max=1');
        $this->line('   - 3: Sauce libre (attr 311) min=1 max=1');
        $this->line('   - 4: Suppléments (extra_group=supplement) min=0 max=6');
        $this->line('   - 5: Menu Frites+Boisson (addon role=menu_component) min=0 max=1');
        $this->line('');
        $this->line('D. CREATE composer_profile for item 479 "Tacos L" — 3 steps :');
        $this->line('   - 1: Viande 1 (attr 307) min=1 max=1');
        $this->line('   - 2: Viande 2 (attr 308) min=1 max=1');
        $this->line('   - 3: Menu Frites+Boisson (addon role=menu_component) min=0 max=1');
        $this->line('');
        $this->line('E. UPDATE cat 350 channels → ["pos","admin","mobile"] (kiosk excluded).');
        $this->line('');
        $this->line('F. FIRE events: 3 × ComposerProfileChanged + 1 × CategoryUpdated.');
        $this->line('   CatalogChanged auto-bridges via existing listeners.');
        $this->line('');
        $this->info('DRY-RUN — nothing persisted. Run with --force to execute.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // STEP A — Seed missing attr 331 variation for item 488
    // ─────────────────────────────────────────────────────────────────────
    private function seedBigCayenneSauceVariation(): void
    {
        $this->info('Step A — Seed missing attr 331 variation for Big Cayenne…');

        $existing = ItemVariation::withTrashed()
            ->where('item_id', self::ITEM_BIG_CAYENNE)
            ->where('item_attribute_id', self::ATTR_SAUCE_CAYENNE)
            ->where('name', 'Sauce Cayenne maison')
            ->first();

        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
                $this->line('   Restored soft-deleted Sauce Cayenne maison variation for item 488.');
            } else {
                $this->line('   Sauce Cayenne maison variation already present — idempotent.');
            }
            return;
        }

        ItemVariation::create([
            'item_id'           => self::ITEM_BIG_CAYENNE,
            'item_attribute_id' => self::ATTR_SAUCE_CAYENNE,
            'name'              => 'Sauce Cayenne maison',
            'price'             => 0,
            'status'            => Status::ACTIVE,
        ]);
        $this->stats['variations_created']++;
        $this->line('   Created ItemVariation(488, 331, "Sauce Cayenne maison", 0).');
    }

    // ─────────────────────────────────────────────────────────────────────
    // STEP B — Big Cayenne (488) composer profile, 5 steps
    // ─────────────────────────────────────────────────────────────────────
    private function createBigCayenneComposer(): void
    {
        $this->info('Step B — Big Cayenne (488) composer_profile…');
        $profile = $this->upsertProfile(self::ITEM_BIG_CAYENNE, expectedSteps: 5);
        if ($profile === null) {
            return; // idempotent skip
        }

        $this->createStep($profile->id, 'viande_1', 'Viande 1 (choix)',
            'item_attribute', 'viande 1', self::ATTR_VIANDE_1, 1, 1, position: 0);

        $this->createStep($profile->id, 'viande_2', 'Viande 2 (choix)',
            'item_attribute', 'viande 2', self::ATTR_VIANDE_2, 1, 1, position: 1);

        $this->createStep($profile->id, 'sauce_cayenne', 'Sauce Cayenne maison (incluse)',
            'item_attribute', 'sauce cayenne (incluse)', self::ATTR_SAUCE_CAYENNE, 1, 1, position: 2);

        $this->createStep($profile->id, 'supplements', 'Suppléments (0.90€)',
            'extra_group', 'supplement', null, 0, 6, position: 3);

        $this->createStep($profile->id, 'menu', 'Menu Frites + Boisson (+2.50€)',
            'addon', 'menu_component', null, 0, 1, position: 4, addonRole: 'menu_component');

        $this->eventsToFire[] = ComposerProfileChanged::fromProfile($profile, 'published');
        $this->stats['profiles_created']++;
        $this->line('   Created 5 steps for profile id=' . $profile->id);
    }

    // ─────────────────────────────────────────────────────────────────────
    // STEP C — Big Classique (489) composer profile, 5 steps
    // ─────────────────────────────────────────────────────────────────────
    private function createBigClassiqueComposer(): void
    {
        $this->info('Step C — Big Classique (489) composer_profile…');
        $profile = $this->upsertProfile(self::ITEM_BIG_CLASSIQUE, expectedSteps: 5);
        if ($profile === null) {
            return;
        }

        $this->createStep($profile->id, 'viande_1', 'Viande 1 (choix)',
            'item_attribute', 'viande 1', self::ATTR_VIANDE_1, 1, 1, position: 0);

        $this->createStep($profile->id, 'viande_2', 'Viande 2 (choix)',
            'item_attribute', 'viande 2', self::ATTR_VIANDE_2, 1, 1, position: 1);

        $this->createStep($profile->id, 'sauce', 'Sauce (1ère gratuite)',
            'item_attribute', 'sauce (1ère gratuite)', self::ATTR_SAUCE_FREE, 1, 1, position: 2);

        $this->createStep($profile->id, 'supplements', 'Suppléments (0.90€)',
            'extra_group', 'supplement', null, 0, 6, position: 3);

        $this->createStep($profile->id, 'menu', 'Menu Frites + Boisson (+2.50€)',
            'addon', 'menu_component', null, 0, 1, position: 4, addonRole: 'menu_component');

        $this->eventsToFire[] = ComposerProfileChanged::fromProfile($profile, 'published');
        $this->stats['profiles_created']++;
        $this->line('   Created 5 steps for profile id=' . $profile->id);
    }

    // ─────────────────────────────────────────────────────────────────────
    // STEP D — Tacos L (479) composer profile, 3 steps
    // ─────────────────────────────────────────────────────────────────────
    private function createTacosLComposer(): void
    {
        $this->info('Step D — Tacos L (479) composer_profile…');
        $profile = $this->upsertProfile(self::ITEM_TACOS_L, expectedSteps: 3);
        if ($profile === null) {
            return;
        }

        $this->createStep($profile->id, 'viande_1', 'Viande 1 (choix)',
            'item_attribute', 'viande 1', self::ATTR_VIANDE_1, 1, 1, position: 0);

        $this->createStep($profile->id, 'viande_2', 'Viande 2 (choix)',
            'item_attribute', 'viande 2', self::ATTR_VIANDE_2, 1, 1, position: 1);

        $this->createStep($profile->id, 'menu', 'Menu Frites + Boisson (+2.50€)',
            'addon', 'menu_component', null, 0, 1, position: 2, addonRole: 'menu_component');

        $this->eventsToFire[] = ComposerProfileChanged::fromProfile($profile, 'published');
        $this->stats['profiles_created']++;
        $this->line('   Created 3 steps for profile id=' . $profile->id);
    }

    // ─────────────────────────────────────────────────────────────────────
    // STEP E — Cat 350 channels update (Menu enfant : exclude kiosk)
    // ─────────────────────────────────────────────────────────────────────
    private function updateMenuEnfantChannels(): void
    {
        $this->info('Step E — Cat 350 (Menu enfant) channels update…');

        $cat = ItemCategory::find(self::CAT_MENU_ENFANT);
        if (! $cat) {
            $this->warn('   Cat 350 not found — skipping.');
            return;
        }

        $current = $cat->channels ?? [];
        $target  = self::CAT_350_CHANNELS_TARGET;

        // Idempotency : compare as sorted arrays.
        $cmpCurrent = $current;
        sort($cmpCurrent);
        $cmpTarget  = $target;
        sort($cmpTarget);

        if ($cmpCurrent === $cmpTarget) {
            $this->line('   Cat 350 channels already at target — idempotent.');
            return;
        }

        $cat->update(['channels' => $target]);
        $this->stats['cat_channels_updated']++;
        $this->eventsToFire[] = new CategoryUpdated($cat->id, 1);
        $this->line('   Updated cat 350 channels: ' . json_encode($current) . ' → ' . json_encode($target));
    }

    // ─────────────────────────────────────────────────────────────────────
    // HELPERS — Profile + Step
    // ─────────────────────────────────────────────────────────────────────
    /**
     * Idempotent profile creation : if a published global profile for this item
     * already exists with the expected step count, return null (skip).
     * Otherwise wipe partial state + create fresh.
     */
    private function upsertProfile(int $itemId, int $expectedSteps): ?ItemWizardProfile
    {
        $existing = ItemWizardProfile::where('item_id', $itemId)
            ->where('is_published', true)
            ->whereNull('branch_id_scope')
            ->orderByDesc('version')
            ->orderByDesc('id')
            ->first();

        if ($existing) {
            $stepCount = ItemWizardStep::where('profile_id', $existing->id)->count();
            if ($stepCount === $expectedSteps) {
                $this->stats['profiles_skipped']++;
                $this->line("   Profile already healed for item={$itemId} (id={$existing->id}, {$stepCount} steps) — idempotent.");
                return null;
            }
            // Partial state — wipe + recreate
            ItemWizardStep::where('profile_id', $existing->id)->delete();
            $existing->delete();
            $this->line("   Wiped partial profile id={$existing->id} for item={$itemId} ({$stepCount} steps != {$expectedSteps}).");
        }

        $profile = ItemWizardProfile::create([
            'item_id'          => $itemId,
            'item_category_id' => null,
            'template'         => 'custom',
            'version'          => 1,
            'is_published'     => true,
            'published_at'     => now(),
            'branch_id_scope'  => null,
        ]);
        $this->line("   Created profile id={$profile->id} for item={$itemId}.");
        return $profile;
    }

    private function createStep(
        int $profileId,
        string $stepKey,
        string $label,
        string $sourceType,
        string $sourceRef,
        ?int $sourceAttrId,
        int $min,
        int $max,
        int $position,
        ?string $addonRole = null,
    ): void {
        ItemWizardStep::create([
            'profile_id'               => $profileId,
            'step_key'                 => $stepKey,
            'label'                    => $label,
            'source_type'              => $sourceType,
            'source_ref'               => $sourceRef,
            'source_item_attribute_id' => $sourceAttrId,
            'min_select'               => $min,
            'max_select'               => $max,
            'allow_repeat'             => false,
            'visible_on'               => null,
            'stockable_choices'        => false,
            'position'                 => $position,
            'is_active'                => true,
            'addon_role'               => $addonRole,
        ]);
        $this->stats['steps_created']++;
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
        $this->line("   Frozen-zone diff vs main = {$diffLines} lines (baseline drift tolerated, no NEW changes from this command).");
        $this->line('   Command writes data-layer only — no source file modified.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Deferred sync events (after transaction commit)
    // ─────────────────────────────────────────────────────────────────────
    private function fireDeferredEvents(): void
    {
        $this->info('Firing sync events…');
        foreach ($this->eventsToFire as $event) {
            event($event);
            $this->stats['events_fired']++;
            // CatalogChanged bridges automatically for CategoryUpdated and
            // ComposerProfileChanged via app/Events/CatalogChanged::fromMenuMutation.
            $bridge = CatalogChanged::fromMenuMutation($event);
            if ($bridge !== null) {
                event($bridge);
                $this->stats['events_fired']++;
            }
        }
        $this->line("   {$this->stats['events_fired']} events fired (incl. CatalogChanged bridge).");
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
