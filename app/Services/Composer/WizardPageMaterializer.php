<?php

namespace App\Services\Composer;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemAddon;
use App\Models\ItemAttribute;
use App\Models\ItemCategory;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use App\Models\ItemWizardProfile;
use App\Models\ItemWizardStep;
use App\Models\WizardPage;
use App\Models\WizardPageChoice;
use Illuminate\Support\Collection;

/**
 * Matérialise les pages de wizard d'une catégorie sur ses produits.
 *
 * Le contrat lu par la caisse, la borne et PricingService ne change pas : les choix d'une page sont
 * écrits dans `item_variations` (pages attribut), `item_extras` (pages groupe d'extras) et
 * `item_addons` (pages addon) de CHAQUE produit, et l'étape reçoit la `source_ref` qui permet à
 * `ComposerProfileProjection` de retrouver ces lignes.
 *
 * Règles :
 *  · idempotent — un second passage ne change rien ;
 *  · jamais de suppression dure — une ligne hors page est DÉSACTIVÉE (variation/extra) ou soft-supprimée
 *    (addon), l'historique des commandes reste intact ;
 *  · une ligne existante est réutilisée (renommage de casse toléré, réactivation, prix aligné) plutôt que
 *    dupliquée ;
 *  · `--dry-run` : même parcours, aucune écriture, rapport identique.
 */
final class WizardPageMaterializer
{
    public function materializeCategory(ItemCategory $category, ?ItemWizardProfile $profile = null, bool $dryRun = false): MaterializationReport
    {
        $report = new MaterializationReport();
        $report->dryRun = $dryRun;

        $profile ??= ItemWizardProfile::query()
            ->where('item_category_id', $category->id)
            ->orderByDesc('id')
            ->first();

        if (! $profile) {
            $report->warn(sprintf('Catégorie #%d : aucun wizard — rien à matérialiser.', $category->id));

            return $report;
        }

        $steps = $this->materializableSteps($profile, $report);
        $this->bindSteps($steps, $report, $dryRun);

        $items = Item::query()
            ->where('item_category_id', $category->id)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get();

        foreach ($items as $item) {
            $this->applySteps($item, $steps, $report, $dryRun);
        }

        return $report;
    }

    public function materializeItem(Item $item, ItemWizardProfile $profile, bool $dryRun = false): MaterializationReport
    {
        $report = new MaterializationReport();
        $report->dryRun = $dryRun;

        $steps = $this->materializableSteps($profile, $report);
        $this->bindSteps($steps, $report, $dryRun);
        $this->applySteps($item, $steps, $report, $dryRun);

        return $report;
    }

    /**
     * Étapes actives reliées à une page active.
     *
     * @return Collection<int, ItemWizardStep>
     */
    /**
     * [2026-09-02 · audit adverse] Avant, une étape ACTIVE sans page reliée (ou dont la page est
     * éteinte) était écartée sans un mot : la commande annonçait « 0 changement » alors qu'elle
     * n'avait rien pu écrire pour cette étape. Un faux vert. On la signale désormais.
     */
    private function materializableSteps(ItemWizardProfile $profile, ?MaterializationReport $report = null): Collection
    {
        $steps = $profile->steps()
            ->with(['page.choices', 'page.itemAttribute'])
            ->orderBy('position')
            ->get();

        if ($report !== null) {
            foreach ($steps as $step) {
                if (! (bool) $step->is_active) {
                    continue;
                }
                if (! $step->page instanceof WizardPage) {
                    $report->warn(sprintf(
                        'Étape « %s » : aucune page reliée — rien ne peut être écrit sur les produits.',
                        $step->label ?: $step->step_key,
                    ));

                    continue;
                }
                if (! (bool) $step->page->is_active) {
                    $report->warn(sprintf(
                        'Étape « %s » : la page « %s » est éteinte — elle n\'apparaît ni en caisse ni sur la borne.',
                        $step->label ?: $step->step_key,
                        $step->page->label,
                    ));
                }
            }
        }

        return $steps
            ->filter(fn (ItemWizardStep $step): bool => (bool) $step->is_active
                && $step->page instanceof WizardPage
                && (bool) $step->page->is_active)
            ->values();
    }

    /**
     * Aligne `source_type` / `source_ref` / `source_item_attribute_id` / `addon_role` de chaque étape sur
     * sa page, pour que la projection retrouve les lignes matérialisées.
     *
     * @param  Collection<int, ItemWizardStep>  $steps
     */
    private function bindSteps(Collection $steps, MaterializationReport $report, bool $dryRun): void
    {
        foreach ($steps as $step) {
            $page = $step->page;
            if ($page->source_type === 'item_attribute') {
                $this->ensureAttribute($page, $report, $dryRun);
            }

            $expected = [
                'source_type' => (string) $page->source_type,
                'source_ref' => $page->effectiveSourceRef(),
                'source_item_attribute_id' => $page->source_type === 'item_attribute' ? $page->item_attribute_id : null,
                'addon_role' => $page->source_type === 'addon' ? $page->addon_role : null,
                'step_key' => $page->effectiveStepKey(),
            ];

            $diff = [];
            foreach ($expected as $field => $value) {
                $current = $step->{$field};
                if ($field === 'source_item_attribute_id') {
                    $current = $current === null ? null : (int) $current;
                    $value = $value === null ? null : (int) $value;
                }
                if ((string) $current !== (string) $value) {
                    $diff[$field] = $value;
                }
            }

            if ($diff === []) {
                continue;
            }

            $report->stepsBound++;
            $report->line(sprintf('  ≡ étape « %s » reliée à la page « %s » (%s)', $step->label, $page->label, implode(', ', array_keys($diff))));
            if (! $dryRun) {
                $step->forceFill($diff)->save();
            }
        }
    }

    /**
     * @param  Collection<int, ItemWizardStep>  $steps
     */
    private function applySteps(Item $item, Collection $steps, MaterializationReport $report, bool $dryRun): void
    {
        $touched = false;
        foreach ($steps as $step) {
            $page = $step->page;
            $touched = true;
            match ((string) $page->source_type) {
                'item_attribute' => $this->syncVariations($item, $page, $report, $dryRun),
                'extra_group' => $this->syncExtras($item, $page, $report, $dryRun),
                'addon' => $this->syncAddons($item, $page, $report, $dryRun),
                default => $report->warn(sprintf('Page « %s » : type de source inconnu « %s ».', $page->label, $page->source_type)),
            };
        }
        if ($touched) {
            $report->itemsTouched++;
        }
    }

    private function ensureAttribute(WizardPage $page, MaterializationReport $report, bool $dryRun): ?ItemAttribute
    {
        if ($page->item_attribute_id) {
            $attribute = $page->relationLoaded('itemAttribute') ? $page->itemAttribute : ItemAttribute::query()->find($page->item_attribute_id);
            if ($attribute) {
                return $attribute;
            }
        }

        $report->bump('attributes_created');
        $report->line(sprintf('  + attribut « %s » créé pour la page « %s »', $page->label, $page->label));
        if ($dryRun) {
            return null;
        }

        $attribute = ItemAttribute::create([
            'name' => $page->label,
            'status' => Status::ACTIVE,
            'min_select' => (int) $page->min_select,
            'max_select' => max((int) $page->max_select, (int) $page->min_select),
            'allow_repeat' => (bool) $page->allow_repeat,
        ]);
        $page->forceFill(['item_attribute_id' => $attribute->id])->save();
        $page->setRelation('itemAttribute', $attribute);

        return $attribute;
    }

    private function syncVariations(Item $item, WizardPage $page, MaterializationReport $report, bool $dryRun): void
    {
        $attribute = $this->ensureAttribute($page, $report, $dryRun);
        if (! $attribute) {
            $report->warn(sprintf('Produit #%d : attribut absent pour la page « %s » (simulation).', $item->id, $page->label));

            return;
        }

        $rows = ItemVariation::query()
            ->where('item_id', $item->id)
            ->where('item_attribute_id', $attribute->id)
            ->orderBy('id')
            ->get();

        $wanted = $this->wantedChoices($page);
        $seen = [];

        foreach ($rows as $row) {
            $key = WizardPageChoice::normalizeName($row->name);
            $choice = $wanted[$key] ?? null;

            if ($choice === null || isset($seen[$key])) {
                if ((int) $row->status === Status::ACTIVE) {
                    $report->bump('variations_deactivated');
                    $report->line(sprintf('  − produit #%d « %s » : variation « %s » désactivée (hors page « %s »)', $item->id, $item->name, $row->name, $page->label));
                    if (! $dryRun) {
                        $row->forceFill(['status' => Status::INACTIVE])->save();
                    }
                }
                continue;
            }

            $seen[$key] = true;
            $changes = $this->rowChanges($row, $choice);
            if ($changes !== []) {
                $report->bump('variations_updated');
                $report->line(sprintf('  ~ produit #%d « %s » : variation « %s » (%s)', $item->id, $item->name, $row->name, implode(', ', array_keys($changes))));
                if (! $dryRun) {
                    $row->forceFill($changes)->save();
                }
            }
        }

        foreach ($wanted as $key => $choice) {
            if (isset($seen[$key])) {
                continue;
            }
            $report->bump('variations_created');
            $report->line(sprintf('  + produit #%d « %s » : variation « %s » (%s €)', $item->id, $item->name, $choice->name, number_format((float) $choice->price, 2, ',', ' ')));
            if (! $dryRun) {
                ItemVariation::create([
                    'item_id' => $item->id,
                    'item_attribute_id' => $attribute->id,
                    'name' => $choice->name,
                    'price' => (float) $choice->price,
                    'status' => Status::ACTIVE,
                    'visible_on' => $choice->visible_on,
                ]);
            }
        }
    }

    private function syncExtras(Item $item, WizardPage $page, MaterializationReport $report, bool $dryRun): void
    {
        $groupLabel = (string) ($page->extra_group_label ?: $page->key);
        $normalizedGroup = mb_strtolower(trim($groupLabel));

        $rows = ItemExtra::query()
            ->where('item_id', $item->id)
            ->orderBy('id')
            ->get()
            ->filter(function (ItemExtra $extra) use ($normalizedGroup): bool {
                $label = mb_strtolower(trim((string) ($extra->group_label ?? '')));
                if ($label === '') {
                    $label = 'default';
                }

                return $label === $normalizedGroup;
            });

        $wanted = $this->wantedChoices($page);
        $seen = [];

        foreach ($rows as $row) {
            $key = WizardPageChoice::normalizeName($row->name);
            $choice = $wanted[$key] ?? null;

            if ($choice === null || isset($seen[$key])) {
                if ((int) $row->status === Status::ACTIVE) {
                    $report->bump('extras_deactivated');
                    $report->line(sprintf('  − produit #%d « %s » : extra « %s » désactivé (hors page « %s »)', $item->id, $item->name, $row->name, $page->label));
                    if (! $dryRun) {
                        $row->forceFill(['status' => Status::INACTIVE])->save();
                    }
                }
                continue;
            }

            $seen[$key] = true;
            $changes = $this->rowChanges($row, $choice);
            if ((string) $row->group_label !== $groupLabel) {
                $changes['group_label'] = $groupLabel;
            }
            if ($changes !== []) {
                $report->bump('extras_updated');
                $report->line(sprintf('  ~ produit #%d « %s » : extra « %s » (%s)', $item->id, $item->name, $row->name, implode(', ', array_keys($changes))));
                if (! $dryRun) {
                    $row->forceFill($changes)->save();
                }
            }
        }

        foreach ($wanted as $key => $choice) {
            if (isset($seen[$key])) {
                continue;
            }
            $report->bump('extras_created');
            $report->line(sprintf('  + produit #%d « %s » : extra « %s » (%s €)', $item->id, $item->name, $choice->name, number_format((float) $choice->price, 2, ',', ' ')));
            if (! $dryRun) {
                ItemExtra::create([
                    'item_id' => $item->id,
                    'name' => $choice->name,
                    'price' => (float) $choice->price,
                    'status' => Status::ACTIVE,
                    'group_label' => $groupLabel,
                    'visible_on' => $choice->visible_on,
                ]);
            }
        }
    }

    private function syncAddons(Item $item, WizardPage $page, MaterializationReport $report, bool $dryRun): void
    {
        $role = $page->addon_role;
        if ($role === null || $role === '') {
            $report->warn(sprintf('Page « %s » : aucun rôle d\'addon défini — produit #%d ignoré.', $page->label, $item->id));

            return;
        }

        $wantedIds = $page->choices
            ->filter(fn (WizardPageChoice $choice): bool => (int) $choice->status === Status::ACTIVE && $choice->addon_item_id !== null)
            ->map(fn (WizardPageChoice $choice): int => (int) $choice->addon_item_id)
            ->unique()
            ->values();

        // [2026-09-02 · audit adverse P0-3] Une page « formule » dont aucun choix ne désigne un
        // produit du catalogue (`addon_item_id` nul — la saisie l'autorise) supprimait TOUS les
        // addons du rôle et n'en recréait aucun : l'étape devenait vide en caisse et sur la borne,
        // sur toute la catégorie, sans que rien ne le signale. On ne touche à rien et on alerte.
        if ($wantedIds->isEmpty()) {
            $report->warn(sprintf(
                'Page « %s » : aucun choix ne désigne un produit du catalogue — les formules du produit #%d sont laissées en place.',
                $page->label,
                $item->id,
            ));

            return;
        }

        $rows = ItemAddon::query()
            ->where('item_id', $item->id)
            ->where('role', $role)
            ->orderBy('id')
            ->get();

        $present = [];
        foreach ($rows as $row) {
            $addonId = (int) $row->addon_item_id;
            if (! $wantedIds->contains($addonId) || isset($present[$addonId]) || $addonId === (int) $item->id) {
                $report->bump('addons_removed');
                $report->line(sprintf('  − produit #%d « %s » : addon #%d retiré (hors page « %s »)', $item->id, $item->name, $addonId, $page->label));
                if (! $dryRun) {
                    $row->delete();
                }
                continue;
            }
            $present[$addonId] = true;
        }

        foreach ($wantedIds as $addonId) {
            if (isset($present[$addonId]) || $addonId === (int) $item->id) {
                continue;
            }
            $report->bump('addons_created');
            $report->line(sprintf('  + produit #%d « %s » : addon #%d (%s)', $item->id, $item->name, $addonId, $role));
            if (! $dryRun) {
                ItemAddon::create([
                    'item_id' => $item->id,
                    'addon_item_id' => $addonId,
                    'role' => $role,
                ]);
            }
        }
    }

    /**
     * @return array<string, WizardPageChoice> choix actifs indexés par nom normalisé (le premier gagne)
     */
    private function wantedChoices(WizardPage $page): array
    {
        $wanted = [];
        foreach ($page->choices as $choice) {
            if ((int) $choice->status !== Status::ACTIVE) {
                continue;
            }
            $key = WizardPageChoice::normalizeName($choice->name);
            if ($key === '' || isset($wanted[$key])) {
                continue;
            }
            $wanted[$key] = $choice;
        }

        return $wanted;
    }

    /**
     * @return array<string, mixed>
     */
    private function rowChanges(ItemVariation|ItemExtra $row, WizardPageChoice $choice): array
    {
        $changes = [];
        if (abs((float) $row->price - (float) $choice->price) > 0.000001) {
            $changes['price'] = (float) $choice->price;
        }
        if ((int) $row->status !== Status::ACTIVE) {
            $changes['status'] = Status::ACTIVE;
        }
        if ((string) $row->name !== (string) $choice->name) {
            // Même nom à la casse près : on aligne sur la bibliothèque sans créer de doublon.
            $changes['name'] = (string) $choice->name;
        }
        if ($choice->visible_on !== null) {
            $current = is_array($row->visible_on) ? array_values($row->visible_on) : null;
            $expected = array_values((array) $choice->visible_on);
            sort($expected);
            if ($current !== null) {
                sort($current);
            }
            if ($current !== $expected) {
                $changes['visible_on'] = $expected;
            }
        }

        return $changes;
    }
}
