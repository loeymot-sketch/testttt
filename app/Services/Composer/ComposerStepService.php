<?php

namespace App\Services\Composer;

use App\Events\ComposerProfileChanged;
use App\Models\ItemWizardProfile;
use App\Models\ItemWizardStep;
use App\Models\WizardPage;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ComposerStepService
{
    private const SOURCE_TYPES = ['item_attribute', 'extra_group', 'addon'];
    private const SURFACES = ['pos', 'kiosk', 'web'];

    public function create(ItemWizardProfile $profile, array $payload, bool $emitSync = true): ItemWizardStep
    {
        if ($emitSync) {
            $this->assertDraft($profile);
        }

        $attributes = $this->normalize($payload);
        $attributes['step_key'] = $this->uniqueStepKey($profile, (string) $attributes['step_key']);
        $step = $profile->steps()->create($attributes);
        $this->dispatchProfileChanged($profile->fresh(), 'steps_updated', $emitSync);

        return $step;
    }

    public function update(ItemWizardStep $step, array $payload): ItemWizardStep
    {
        $this->assertDraft($step->profile);
        $step->update($this->normalize($payload));
        $fresh = $step->fresh('profile');
        $this->dispatchProfileChanged($fresh->profile, 'steps_updated');

        return $fresh;
    }

    public function delete(ItemWizardStep $step): void
    {
        $profile = $step->profile;
        $this->assertDraft($profile);
        $step->delete();
        $this->dispatchProfileChanged($profile, 'steps_updated');
    }

    public function normalize(array $payload): array
    {
        $payload = $this->applyPage($payload);
        $this->assertContract($payload);

        return [
            'wizard_page_id' => isset($payload['wizard_page_id']) && (int) $payload['wizard_page_id'] > 0 ? (int) $payload['wizard_page_id'] : null,
            'step_key' => $payload['step_key'],
            'label' => $payload['label'],
            'source_type' => $payload['source_type'],
            'source_ref' => $payload['source_ref'] ?? '',
            'source_item_attribute_id' => $this->resolveSourceItemAttributeId($payload),
            'min_select' => (int) ($payload['min_select'] ?? 0),
            'max_select' => (int) ($payload['max_select'] ?? 1),
            'allow_repeat' => (bool) ($payload['allow_repeat'] ?? false),
            'visible_on' => $payload['visible_on'] ?? null,
            'stockable_choices' => (bool) ($payload['stockable_choices'] ?? false),
            'position' => (int) ($payload['position'] ?? 0),
            'is_active' => (bool) ($payload['is_active'] ?? true),
            'addon_role' => $payload['addon_role'] ?? null,
        ];
    }

    /**
     * Une étape reliée à une page de la bibliothèque tire d'elle son type de source, sa référence,
     * son attribut, son rôle d'addon et sa clé (celle que la caisse et la borne reconnaissent) : le
     * libellé, le minimum/maximum et les canaux restent propres à la catégorie.
     */
    private function applyPage(array $payload): array
    {
        $pageId = isset($payload['wizard_page_id']) ? (int) $payload['wizard_page_id'] : 0;
        if ($pageId <= 0) {
            return $payload;
        }

        $page = WizardPage::query()->find($pageId);
        if (! $page) {
            throw ValidationException::withMessages([
                'wizard_page_id' => 'Cette page de wizard n\'existe plus.',
            ]);
        }

        $payload['source_type'] = (string) $page->source_type;
        $payload['source_ref'] = $page->effectiveSourceRef();
        $payload['source_item_attribute_id'] = $page->source_type === 'item_attribute' ? $page->item_attribute_id : null;
        $payload['addon_role'] = $page->source_type === 'addon' ? $page->addon_role : null;
        $payload['step_key'] = $page->effectiveStepKey();
        if (! isset($payload['label']) || trim((string) $payload['label']) === '') {
            $payload['label'] = (string) $page->label;
        }

        return $payload;
    }

    /**
     * (profile_id, step_key) est unique en base : deux pages « viande » dans un même parcours
     * reçoivent « viande » puis « viande_2 » — la seconde passe par l'écran générique, comme avant.
     */
    private function uniqueStepKey(ItemWizardProfile $profile, string $key): string
    {
        $base = $key !== '' ? $key : 'page';
        $candidate = $base;
        $suffix = 2;
        while ($profile->steps()->where('step_key', $candidate)->exists()) {
            $candidate = $base.'_'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function resolveSourceItemAttributeId(array $payload): ?int
    {
        $explicit = $payload['source_item_attribute_id'] ?? null;
        if ($explicit !== null && is_numeric($explicit) && (int) $explicit > 0) {
            return (int) $explicit;
        }

        if (($payload['source_type'] ?? null) !== 'item_attribute') {
            return null;
        }

        $ref = trim((string) ($payload['source_ref'] ?? ''));
        if ($ref !== '' && ctype_digit($ref)) {
            $asInt = (int) $ref;
            if ($asInt > 0) {
                return $asInt;
            }
        }

        return null;
    }

    /**
     * Avant : réordonner / supprimer une page d'un wizard publié
     * changeait la caisse sans passer par Publier.
     */
    private function assertDraft(?ItemWizardProfile $profile): void
    {
        if ($profile && $profile->is_published) {
            throw ValidationException::withMessages([
                'steps' => 'Ce wizard est en caisse. Enregistre un brouillon, puis publie.',
            ]);
        }
    }

    private function assertContract(array $payload): void
    {
        $sourceType = (string) ($payload['source_type'] ?? '');
        if (! in_array($sourceType, self::SOURCE_TYPES, true)) {
            throw new InvalidArgumentException('Unsupported composer source type.');
        }

        $visibleOn = $payload['visible_on'] ?? null;
        if ($visibleOn !== null) {
            if (! is_array($visibleOn)) {
                throw new InvalidArgumentException('Composer visible_on must be an array.');
            }

            foreach ($visibleOn as $surface) {
                if (! in_array((string) $surface, self::SURFACES, true)) {
                    throw new InvalidArgumentException('Unsupported composer surface.');
                }
            }
        }

        $min = $payload['min_select'] ?? null;
        $max = $payload['max_select'] ?? null;
        if ($min !== null && $max !== null && is_numeric($min) && is_numeric($max) && (int) $max < (int) $min) {
            throw new InvalidArgumentException('Composer max_select must be greater than or equal to min_select.');
        }
    }

    private function dispatchProfileChanged(?ItemWizardProfile $profile, string $changeType, bool $emitSync = true): void
    {
        if (! $emitSync || ! $profile || ! $profile->is_published) {
            return;
        }

        $profile->forceFill([
            'version' => ((int) $profile->version) + 1,
        ])->save();
        $profile->refresh();

        ComposerProfileChanged::dispatch(
            (int) $profile->id,
            $changeType,
            $profile->branch_id_scope !== null ? (int) $profile->branch_id_scope : null,
            [
                'item_id' => (int) $profile->item_id,
                'version' => (int) $profile->version,
                'is_published' => (bool) $profile->is_published,
            ],
        );
    }
}
