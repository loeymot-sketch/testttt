<?php

namespace Tests\Feature\Composer;

use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemWizardProfile;
use App\Models\ItemWizardStep;
use App\Services\Composer\ComposerStepService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ComposerStepServiceContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_rejects_unsupported_fixed_source_type(): void
    {
        $profile = $this->profile();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported composer source type');

        app(ComposerStepService::class)->create($profile, $this->payload([
            'source_type' => 'fixed',
        ]));
    }

    public function test_service_rejects_invalid_visible_surface(): void
    {
        $profile = $this->profile();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported composer surface');

        app(ComposerStepService::class)->create($profile, $this->payload([
            'visible_on' => ['kiosk', 'terminal'],
        ]));
    }

    public function test_service_rejects_max_select_below_min_select(): void
    {
        $step = ItemWizardStep::factory()->create([
            'profile_id' => $this->profile()->id,
            'source_type' => 'item_attribute',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('max_select');

        app(ComposerStepService::class)->update($step, $this->payload([
            'min_select' => 3,
            'max_select' => 1,
        ]));
    }

    public function test_service_derives_source_item_attribute_id_from_numeric_source_ref(): void
    {
        $profile = $this->profile();
        $attribute = ItemAttribute::factory()->create();

        $step = app(ComposerStepService::class)->create($profile, $this->payload([
            'source_type' => 'item_attribute',
            'source_ref' => (string) $attribute->id,
        ]), emitSync: false);

        $this->assertSame($attribute->id, $step->source_item_attribute_id);
    }

    public function test_service_keeps_source_item_attribute_id_null_for_non_attribute_sources(): void
    {
        $profile = $this->profile();

        $step = app(ComposerStepService::class)->create($profile, $this->payload([
            'source_type' => 'addon',
            'source_ref' => 'drink',
        ]), emitSync: false);

        $this->assertNull($step->source_item_attribute_id);
    }

    private function profile(): ItemWizardProfile
    {
        return ItemWizardProfile::factory()->create([
            'item_id' => Item::factory(),
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'step_key' => 'crudites',
            'label' => 'Crudites',
            'source_type' => 'item_attribute',
            'source_ref' => '',
            'min_select' => 0,
            'max_select' => 2,
            'allow_repeat' => false,
            'visible_on' => ['pos', 'kiosk'],
            'position' => 0,
        ], $overrides);
    }
}
