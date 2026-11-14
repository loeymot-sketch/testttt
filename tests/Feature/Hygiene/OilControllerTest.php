<?php

namespace Tests\Feature\Hygiene;

use App\Models\Branch;
use App\Models\HygieneEquipment;
use App\Models\HygieneOilControl;
use App\Models\User;
use App\Services\Hygiene\OilRules;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * [CAYENNE HYGIÈNE 2026-10-17] Huile de friture : test (composés polaires 25 % = limite légale, bain), filtrage, vidange ; écart =
 * action corrective obligatoire ; friteuses = équipements, jamais montrés à l'écran des températures.
 */
class OilControllerTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/admin/hygiene/oil';
    private const TEMP = '/api/admin/hygiene/temperature';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Branch::factory()->create(['id' => 1]);
        Branch::factory()->create(['id' => 2]);
        Carbon::setTestNow(Carbon::parse('2026-10-20 10:00:00', config('app.timezone')));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function staff(int $branch = 1): User
    {
        $u = User::factory()->create(['branch_id' => $branch, 'name' => 'Karim Benali']);
        $u->givePermissionTo('pos-orders');
        $u->assignRole('POS Operator');
        Sanctum::actingAs($u, ['*']);

        return $u;
    }

    private function fryers(int $n = 2): array
    {
        $this->postJson(self::BASE.'/fryers/quick-setup', ['count' => $n])->assertCreated();

        return HygieneEquipment::query()->where('kind', 'fryer')->orderBy('sort_order')->pluck('id')->all();
    }

    public function test_rules_have_inclusive_limits(): void
    {
        $this->assertSame('ok', OilRules::evaluate(19.9, null)['status']);
        $this->assertSame('warn', OilRules::evaluate(20.0, null)['status']);
        $this->assertSame('warn', OilRules::evaluate(25.0, null)['status'], '25,0 % = la limite elle-même est tolérée');
        $this->assertSame('alert', OilRules::evaluate(25.1, null)['status']);
        $this->assertSame('ok', OilRules::evaluate(10, 180.0)['status']);
        $r = OilRules::evaluate(10, 180.1);
        $this->assertSame('alert', $r['status']);
        $this->assertSame(['bath_hot'], $r['reasons']);
        $this->assertSame(['polar_over', 'bath_hot'], OilRules::evaluate(30, 200)['reasons']);
    }

    public function test_quick_setup_names_fryers_and_is_idempotent(): void
    {
        $this->staff();
        $this->fryers(3);
        $this->assertSame(['Friteuse 1', 'Friteuse 2', 'Friteuse 3'], HygieneEquipment::query()->orderBy('sort_order')->pluck('name')->all());
        $this->postJson(self::BASE.'/fryers/quick-setup', ['count' => 3])->assertOk()->assertJsonPath('data.added', 0);
        $this->postJson(self::BASE.'/fryers/quick-setup', ['count' => 0])->assertStatus(422);
    }

    public function test_a_single_fryer_has_no_number(): void
    {
        $this->staff();
        $this->fryers(1);
        $this->assertSame(['Friteuse'], HygieneEquipment::query()->pluck('name')->all());
    }

    public function test_fryers_never_appear_on_the_temperature_screen_and_cannot_be_edited_there(): void
    {
        $this->staff();
        [$f1] = $this->fryers(1);
        $this->postJson(self::TEMP.'/equipment/quick-setup', ['fridge' => 1])->assertCreated();
        $names = collect($this->getJson(self::TEMP.'/today')->json('data'))->pluck('name')->all();
        $this->assertSame(['Frigo'], $names);
        $this->assertSame(['Frigo'], collect($this->getJson(self::TEMP.'/equipment')->json('data'))->pluck('name')->all());
        $this->putJson(self::TEMP."/equipment/$f1", ['name' => 'Piraté'])->assertNotFound();
        $this->deleteJson(self::TEMP."/equipment/$f1")->assertNotFound();
        $this->postJson(self::TEMP.'/equipment', ['name' => 'Autre', 'kind' => 'fryer'])->assertStatus(422);
        $kinds = collect($this->getJson(self::TEMP.'/today')->json('meta.kinds'))->pluck('key')->all();
        $this->assertNotContains('fryer', $kinds);
        // et réciproquement : un nom pris par une friteuse est pris pour toute l'installation
        $this->postJson(self::TEMP.'/equipment', ['name' => 'friteuse', 'kind' => 'fridge'])->assertStatus(422);
    }

    public function test_a_conforming_test_needs_no_action(): void
    {
        $this->staff();
        [$f1] = $this->fryers();
        $r = $this->postJson(self::BASE.'/controls', ['equipment_id' => $f1, 'type' => 'test', 'polar_pct' => 14.5, 'bath_temp_c' => 175])->assertCreated();
        $r->assertJsonPath('data.status', 'ok')->assertJsonPath('data.operator_name', 'Karim Benali')->assertJsonPath('data.equipment_name', 'Friteuse 1');
        $this->assertEquals(25, HygieneOilControl::query()->firstOrFail()->polar_limit, 'la limite du moment est copiée');
    }

    public function test_a_test_requires_the_polar_value_and_a_plausible_one(): void
    {
        $this->staff();
        [$f1] = $this->fryers();
        $this->postJson(self::BASE.'/controls', ['equipment_id' => $f1, 'type' => 'test'])->assertStatus(422)->assertJsonValidationErrors('polar_pct');
        $this->postJson(self::BASE.'/controls', ['equipment_id' => $f1, 'type' => 'test', 'polar_pct' => 80])->assertStatus(422)->assertJsonValidationErrors('polar_pct');
        $this->postJson(self::BASE.'/controls', ['equipment_id' => $f1, 'type' => 'test', 'polar_pct' => 12, 'bath_temp_c' => 18])->assertStatus(422)->assertJsonValidationErrors('bath_temp_c');
        $this->assertSame(0, HygieneOilControl::query()->count());
    }

    public function test_warn_is_recorded_without_any_required_action(): void
    {
        $this->staff();
        [$f1] = $this->fryers();
        $this->postJson(self::BASE.'/controls', ['equipment_id' => $f1, 'type' => 'test', 'polar_pct' => 22])->assertCreated()->assertJsonPath('data.status', 'warn');
    }

    public function test_over_the_legal_limit_requires_a_corrective_action(): void
    {
        $this->staff();
        [$f1] = $this->fryers();
        $bad = ['equipment_id' => $f1, 'type' => 'test', 'polar_pct' => 27];
        $this->postJson(self::BASE.'/controls', $bad)->assertStatus(422)->assertJsonValidationErrors('corrective_action');
        $this->postJson(self::BASE.'/controls', $bad + ['corrective_action' => 'other'])->assertStatus(422)->assertJsonValidationErrors('corrective_note');
        $this->postJson(self::BASE.'/controls', $bad + ['corrective_action' => 'bogus'])->assertStatus(422);
        $r = $this->postJson(self::BASE.'/controls', $bad + ['corrective_action' => 'oil_changed'])->assertCreated();
        $r->assertJsonPath('data.status', 'alert')->assertJsonPath('data.reasons.0', 'polar_over')->assertJsonPath('data.corrective_label', 'Huile changée');
    }

    public function test_a_hot_bath_is_a_deviation_too_and_a_conforming_test_drops_any_action(): void
    {
        $this->staff();
        [$f1] = $this->fryers();
        $this->postJson(self::BASE.'/controls', ['equipment_id' => $f1, 'type' => 'test', 'polar_pct' => 10, 'bath_temp_c' => 195])->assertStatus(422)->assertJsonValidationErrors('corrective_action');
        $r = $this->postJson(self::BASE.'/controls', ['equipment_id' => $f1, 'type' => 'test', 'polar_pct' => 10, 'bath_temp_c' => 170, 'corrective_action' => 'oil_changed'])->assertCreated();
        $this->assertNull($r->json('data.corrective_action'));
    }

    public function test_change_and_filter_need_no_measure_and_ignore_polar_values(): void
    {
        $this->staff();
        [$f1] = $this->fryers();
        $c = $this->postJson(self::BASE.'/controls', ['equipment_id' => $f1, 'type' => 'change', 'oil_litres' => 18, 'oil_brand' => 'Colza 15 L', 'polar_pct' => 99])->assertCreated();
        $c->assertJsonPath('data.status', 'ok')->assertJsonPath('data.oil_litres', 18)->assertJsonPath('data.polar_pct', null);
        $this->postJson(self::BASE.'/controls', ['equipment_id' => $f1, 'type' => 'filter'])->assertCreated();
        $this->postJson(self::BASE.'/controls', ['equipment_id' => $f1, 'type' => 'change', 'oil_litres' => 900])->assertStatus(422);
        $this->postJson(self::BASE.'/controls', ['equipment_id' => $f1, 'type' => 'nope'])->assertStatus(422);
    }

    public function test_today_state_follows_the_last_test_and_tracks_days_since_change(): void
    {
        $this->staff();
        [$f1, $f2] = $this->fryers();
        $this->postJson(self::BASE.'/controls', ['equipment_id' => $f1, 'type' => 'test', 'polar_pct' => 12])->assertCreated();
        $row = new HygieneOilControl(['equipment_id' => $f2, 'equipment_name' => 'Friteuse 2', 'type' => 'change', 'status' => 'ok', 'performed_at' => now()->subDays(6), 'timezone' => 'Europe/Paris']);
        $row->branch_id = 1;
        $row->save();

        $r = $this->getJson(self::BASE.'/today')->assertOk();
        $by = collect($r->json('data'))->keyBy('id');
        $this->assertSame('ok', $by[$f1]['state']);
        $this->assertTrue($by[$f1]['tested_today']);
        $this->assertSame('todo', $by[$f2]['state']);
        $this->assertSame(6, $by[$f2]['days_since_change']);
        $this->assertNull($by[$f1]['days_since_change']);
        $this->assertSame(['total' => 2, 'todo' => 1, 'ok' => 1, 'warn' => 0, 'alert' => 0], $r->json('meta.summary'));
        $this->assertEquals(25, $r->json('meta.limits.polar_limit'));

        $this->postJson(self::BASE.'/controls', ['equipment_id' => $f2, 'type' => 'test', 'polar_pct' => 26, 'corrective_action' => 'fryer_stopped'])->assertCreated();
        $this->assertSame(1, $this->getJson(self::BASE.'/today')->json('meta.summary.alert'));
    }

    public function test_replay_void_and_history(): void
    {
        $this->staff();
        [$f1] = $this->fryers();
        $body = ['equipment_id' => $f1, 'type' => 'test', 'polar_pct' => 12, 'client_ref' => 'oil-1'];
        $id = $this->postJson(self::BASE.'/controls', $body)->assertCreated()->json('data.id');
        $this->assertSame($id, $this->postJson(self::BASE.'/controls', $body)->assertOk()->json('data.id'));
        $this->postJson(self::BASE.'/controls', ['equipment_id' => $f1, 'type' => 'test', 'polar_pct' => 28, 'corrective_action' => 'fryer_stopped'])->assertCreated(); // « Huile changée » solderait l'écart (OilRules::isOpen) : on veut ici un écart qui reste ouvert

        $this->postJson(self::BASE."/controls/$id/void", ['reason' => 'x'])->assertStatus(422);
        $this->postJson(self::BASE."/controls/$id/void", ['reason' => 'Mauvaise friteuse'])->assertOk()->assertJsonPath('data.voided', true);
        $this->assertSame(2, HygieneOilControl::query()->count());
        $this->assertSame('alert', collect($this->getJson(self::BASE.'/today')->json('data'))->firstWhere('id', $f1)['state'], 'le test annulé ne compte plus ; reste le test en écart');
        $this->assertCount(1, $this->getJson(self::BASE.'/history?alerts_only=1')->json('data'));
        $this->assertCount(2, $this->getJson(self::BASE.'/history')->json('data'));
        $this->assertCount(1, $this->getJson(self::BASE.'/history?type=test&equipment_id='.$f1.'&alerts_only=1')->json('data'));
    }

    public function test_branch_isolation_and_permission(): void
    {
        $this->staff(1);
        [$f1] = $this->fryers(1);
        $id = $this->postJson(self::BASE.'/controls', ['equipment_id' => $f1, 'type' => 'filter'])->assertCreated()->json('data.id');
        $this->staff(2);
        $this->assertCount(0, $this->getJson(self::BASE.'/today')->json('data'));
        $this->postJson(self::BASE.'/controls', ['equipment_id' => $f1, 'type' => 'filter'])->assertStatus(422);
        $this->postJson(self::BASE."/controls/$id/void", ['reason' => 'piratage'])->assertNotFound();
        $this->putJson(self::BASE."/fryers/$f1", ['name' => 'Piraté'])->assertNotFound();

        Sanctum::actingAs(User::factory()->create(['branch_id' => 1]), ['*']);
        $this->getJson(self::BASE.'/today')->assertForbidden();
    }
}
