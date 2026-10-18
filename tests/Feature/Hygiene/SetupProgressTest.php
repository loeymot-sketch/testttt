<?php

namespace Tests\Feature\Hygiene;

use App\Models\Branch;
use App\Models\DlcLabelProduct;
use App\Models\HygieneAlertSetting;
use App\Models\HygieneEquipment;
use App\Models\HygieneTaskTemplate;
use App\Models\HygieneTemperatureReading;
use App\Models\LabelPrintLog;
use App\Models\User;
use App\Services\Hygiene\OperatorPins;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * [CAYENNE HYGIÈNE 2026-10-18 · rapport Komia « blocage total pour un nouveau client »] La progression de mise en place se déduit des journaux réels :
 * jamais cochée à la main, jamais mélangée entre restaurants, et « prochaine étape » désigne toujours une étape obligatoire d'abord.
 */
class SetupProgressTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/admin/hygiene/overview';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Branch::factory()->create(['id' => 1]);
        Branch::factory()->create(['id' => 2]);
        $u = User::factory()->create(['branch_id' => 1, 'name' => 'Karim Benali']);
        $u->givePermissionTo('pos-orders');
        $u->assignRole('POS Operator');
        Sanctum::actingAs($u, ['*']);
    }

    private function setup_(): array
    {
        return $this->getJson(self::URL)->assertOk()->json('meta.setup');
    }

    private function step(array $setup, string $key): array
    {
        return collect($setup['steps'])->firstWhere('key', $key);
    }

    private function equipment(int $branch = 1, string $kind = 'fridge'): HygieneEquipment
    {
        $e = new HygieneEquipment(['name' => 'Frigo', 'kind' => $kind, 'min_c' => 0, 'max_c' => 4, 'sort_order' => 1, 'is_active' => true]);
        $e->branch_id = $branch;
        $e->save();

        return $e;
    }

    private function product(string $source, bool $checked = false): DlcLabelProduct
    {
        $p = new DlcLabelProduct([
            'name' => 'Oignons '.uniqid(), 'category' => 'Légumes', 'shelf_life_value' => 3, 'shelf_life_unit' => 'days', 'actions' => [['key' => 'prepared', 'value' => 3, 'unit' => 'days']],
            'storage' => '0 à +4 °C', 'is_active' => true, 'sort_order' => 1, 'duration_source' => $source, 'durations_checked_at' => $checked ? now() : null,
        ]);
        $p->branch_id = 1;
        $p->save();

        return $p;
    }

    public function test_a_brand_new_restaurant_starts_at_zero_and_is_pointed_to_the_first_step(): void
    {
        $s = $this->setup_();
        $this->assertSame(5, $s['required_total']);
        $this->assertSame(0, $s['required_done']);
        $this->assertFalse($s['complete']);
        $this->assertSame('equipment', $s['next']['key']);
        $this->assertSame(['equipment', 'tasks', 'durations', 'reading', 'label', 'fryer', 'team', 'alerts'], array_column($s['steps'], 'key'));
        $this->assertSame([false, false, false, false, false, true, true, true], array_column($s['steps'], 'optional'));
    }

    public function test_steps_follow_the_real_journals_not_a_manual_tick(): void
    {
        $e = $this->equipment();
        $t = new HygieneTaskTemplate(['name' => 'Salle', 'checklist' => [['key' => 'k1', 'label' => 'Sols']], 'frequency' => 'daily', 'sort_order' => 1, 'is_active' => true]);
        $t->branch_id = 1;
        $t->save();
        $s = $this->setup_();
        $this->assertTrue($this->step($s, 'equipment')['done']);
        $this->assertTrue($this->step($s, 'tasks')['done']);
        $this->assertFalse($this->step($s, 'reading')['done']);
        $this->assertSame(2, $s['required_done']);
        $this->assertSame('durations', $s['next']['key']);

        $r = new HygieneTemperatureReading(['equipment_id' => $e->id, 'equipment_name' => 'Frigo', 'equipment_kind' => 'fridge', 'min_c' => 0, 'max_c' => 4, 'value_c' => 3, 'status' => 'ok', 'period' => 'morning', 'recorded_at' => now(), 'operator_name' => 'K']);
        $r->branch_id = 1;
        $r->save();
        $this->assertTrue($this->step($this->setup_(), 'reading')['done']);
    }

    public function test_a_voided_reading_does_not_count_as_a_first_reading(): void
    {
        $e = $this->equipment();
        $r = new HygieneTemperatureReading(['equipment_id' => $e->id, 'equipment_name' => 'Frigo', 'equipment_kind' => 'fridge', 'min_c' => 0, 'max_c' => 4, 'value_c' => 3, 'status' => 'ok', 'period' => 'morning', 'recorded_at' => now(), 'operator_name' => 'K']);
        $r->branch_id = 1;
        $r->save();
        $r->forceFill(['voided_at' => now()])->save();
        $this->assertFalse($this->step($this->setup_(), 'reading')['done']);
    }

    public function test_durations_are_done_only_when_a_catalogue_exists_and_every_starting_value_is_validated(): void
    {
        $this->assertFalse($this->step($this->setup_(), 'durations')['done']);

        $this->product(DlcLabelProduct::SOURCE_STARTING);
        $d = $this->step($this->setup_(), 'durations');
        $this->assertFalse($d['done']);
        $this->assertStringContainsString('1 durée de départ', $d['detail']);

        DlcLabelProduct::query()->update(['durations_checked_at' => now()]);
        $this->assertTrue($this->step($this->setup_(), 'durations')['done']);

        $this->product(DlcLabelProduct::SOURCE_IMPORTED);
        $this->assertFalse($this->step($this->setup_(), 'durations')['done']);
    }

    public function test_a_restaurants_own_durations_need_no_validation(): void
    {
        $this->product(DlcLabelProduct::SOURCE_OWN);
        $this->assertTrue($this->step($this->setup_(), 'durations')['done']);
    }

    public function test_a_first_label_counts_only_when_it_was_really_printed(): void
    {
        $mk = function (string $status) {
            $l = new LabelPrintLog(['kind' => 'dlc', 'status' => $status, 'copies' => 1, 'product_name' => 'P', 'opened_at' => now(), 'expires_at' => now()->addDay()]);
            $l->branch_id = 1;
            $l->save();
        };
        $mk(LabelPrintLog::STATUS_CLAIMED);
        $mk(LabelPrintLog::STATUS_FAILED);
        $mk(LabelPrintLog::STATUS_UNCONFIRMED);
        $this->assertFalse($this->step($this->setup_(), 'label')['done']);
        $mk('printed');
        $this->assertTrue($this->step($this->setup_(), 'label')['done']);
    }

    public function test_another_restaurants_setup_never_leaks_in(): void
    {
        $this->equipment(2);
        $this->assertFalse($this->step($this->setup_(), 'equipment')['done']);
    }

    public function test_optional_steps_never_block_completion_and_next_falls_back_to_them(): void
    {
        $e = $this->equipment();
        $t = new HygieneTaskTemplate(['name' => 'Salle', 'checklist' => [['key' => 'k1', 'label' => 'Sols']], 'frequency' => 'daily', 'sort_order' => 1, 'is_active' => true]);
        $t->branch_id = 1;
        $t->save();
        $this->product(DlcLabelProduct::SOURCE_OWN);
        $r = new HygieneTemperatureReading(['equipment_id' => $e->id, 'equipment_name' => 'Frigo', 'equipment_kind' => 'fridge', 'min_c' => 0, 'max_c' => 4, 'value_c' => 3, 'status' => 'ok', 'period' => 'morning', 'recorded_at' => now(), 'operator_name' => 'K']);
        $r->branch_id = 1;
        $r->save();
        $l = new LabelPrintLog(['kind' => 'dlc', 'status' => 'printed', 'copies' => 1, 'product_name' => 'P', 'opened_at' => now(), 'expires_at' => now()->addDay()]);
        $l->branch_id = 1;
        $l->save();

        $s = $this->setup_();
        $this->assertTrue($s['complete']);
        $this->assertSame(5, $s['required_done']);
        $this->assertSame('fryer', $s['next']['key']);
    }

    public function test_optional_steps_detect_fryer_pin_and_alerts(): void
    {
        $this->equipment(1, 'fryer');
        $other = User::factory()->create(['branch_id' => 1, 'name' => 'Sam Test']);
        app(OperatorPins::class)->set(1, $other->id, '4821', $other->id);
        $a = new HygieneAlertSetting(['enabled' => true, 'recipients' => ['a@b.fr']]);
        $a->branch_id = 1;
        $a->save();

        $s = $this->setup_();
        $this->assertTrue($this->step($s, 'fryer')['done']);
        $this->assertTrue($this->step($s, 'team')['done']);
        $this->assertTrue($this->step($s, 'alerts')['done']);
        $this->assertSame(0, $s['required_done']);
    }

    public function test_a_fryer_is_not_a_temperature_equipment(): void
    {
        $this->equipment(1, 'fryer');
        $this->assertFalse($this->step($this->setup_(), 'equipment')['done']);
    }
}
