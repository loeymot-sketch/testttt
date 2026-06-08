<?php

namespace App\Console\Commands;

use App\Models\ItemCategory;
use App\Models\ItemWizardProfile;
use App\Services\Composer\ComposerProfileService;
use App\Services\Composer\ComposerTemplateService;
use Illuminate\Console\Command;

/**
 * [GOAL_WIZARD_DYNAMIC_BUILDER Wave 4 — T-4.2.1] Provision the real Le Cayenne
 * CATEGORY wizards, turnkey + idempotent.
 *
 * Uses the turnkey ComposerTemplateService::buildPayload (binds each step's
 * source_ref to the category's real attributes/groups by name), createForCategory
 * + publish. Per the source-binding contract: NO price authored here — options
 * keep their catalog prices; the wizard is purely structural. Safe to run on the
 * disposable foodking_e2e clone; running on the operating catalog is an owner
 * decision (a real, reversible catalog change — published profiles only).
 */
class ProvisionCayenneWizardsCommand extends Command
{
    protected $signature = 'foodking:provision-cayenne-wizards {--force : create a fresh published profile even if one already exists}';

    protected $description = 'Provision the real Le Cayenne category wizards (turnkey source_ref binding, idempotent).';

    /** category name => composer template */
    private const MAP = [
        'Sandwich Cayenne'   => 'sandwich',
        'Sandwich Classique' => 'sandwich',
        'Galette'            => 'sandwich',
        'Burgers'            => 'sandwich',
        'Tacos'              => 'tacos',
        'Bols Gourmands'     => 'sandwich',
    ];

    public function handle(ComposerTemplateService $templates, ComposerProfileService $profiles): int
    {
        $done = 0;
        $skipped = 0;
        $failed = 0;

        foreach (self::MAP as $name => $template) {
            $category = ItemCategory::query()->where('name', $name)->first();
            if (! $category) {
                $this->warn("- skip '{$name}' (category not found)");
                $skipped++;
                continue;
            }

            // Pick the RICHEST item (most distinct attributes + group labels) as the
            // source representative — the first item may be an outlier missing the
            // category's attributes, which would publish an empty/unbound wizard.
            $rep = $category->items()->whereNull('deleted_at')
                ->with(['variations.itemAttribute', 'extras'])
                ->get()
                ->sortByDesc(fn ($it) => $it->variations->map(fn ($v) => $v->itemAttribute?->name)->filter()->unique()->count()
                    + $it->extras->pluck('group_label')->filter()->unique()->count())
                ->first();
            if (! $rep) {
                $this->warn("- skip '{$name}' (no items to derive sources from)");
                $skipped++;
                continue;
            }

            $existing = ItemWizardProfile::query()
                ->whereNull('item_id')
                ->where('item_category_id', $category->id)
                ->where('is_published', true)
                ->first();
            if ($existing && ! $this->option('force')) {
                $this->line("- skip '{$name}' (already published #{$existing->id}; use --force to replace)");
                $skipped++;
                continue;
            }

            try {
                // --force = REPLACE, not duplicate. The runtime resolver
                // (ComposerProfileService::showForCategory) picks the LATEST id, so a
                // fresh profile already wins — but leaving the prior profile published
                // is stale clutter and makes the unordered is_published skip-guard
                // above non-deterministic. Unpublish any prior published profile(s)
                // first so exactly one stays published per category.
                if ($this->option('force')) {
                    $stale = ItemWizardProfile::query()
                        ->whereNull('item_id')
                        ->where('item_category_id', $category->id)
                        ->where('is_published', true)
                        ->get();
                    foreach ($stale as $old) {
                        $profiles->unpublish($old);
                    }
                    if ($stale->isNotEmpty()) {
                        $this->line("  ↳ unpublished {$stale->count()} prior published profile(s) for '{$name}'");
                    }
                }

                $payload = $templates->buildPayload($template, $rep);
                $profile = $profiles->createForCategory($category, $payload);
                $profiles->publish($profile->fresh());

                $fresh = $profile->fresh('steps');
                $active = $fresh->steps->where('is_active', true);
                $bound = $active->filter(fn ($s) => trim((string) $s->source_ref) !== '' || $s->source_type === 'addon')->count();
                $this->info("✓ '{$name}' → {$template} (profile #{$profile->id}, {$active->count()} active steps, {$bound} bound)");
                $done++;
            } catch (\Throwable $e) {
                $this->error("✗ '{$name}': " . $e->getMessage());
                $failed++;
            }
        }

        $this->newLine();
        $this->info("Provisioned: {$done} · skipped: {$skipped} · failed: {$failed}");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
