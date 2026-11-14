<?php

namespace Tests\Feature\Hygiene;

use App\Models\Branch;
use App\Models\HygieneAttachment;
use App\Models\HygieneReception;
use App\Models\Supplier;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * [CAYENNE HYGIÈNE 2026-10-17] Contrôle à réception : trois familles de température avec limites, contrôles visuels, décision
 * obligatoire en cas d'écart, fournisseurs, journal append-only à contexte copié.
 */
class ReceptionControllerTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/admin/hygiene/receptions';

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

    private function staff(int $branch = 1, string $role = 'POS Operator', string $name = 'Karim Benali'): User
    {
        $u = User::factory()->create(['branch_id' => $branch, 'name' => $name]);
        $u->givePermissionTo('pos-orders');
        $u->assignRole($role);
        Sanctum::actingAs($u, ['*']);

        return $u;
    }

    private function supplier(string $name = 'Metro', int $branch = 1): Supplier
    {
        return Supplier::query()->firstOrCreate(['branch_id' => $branch, 'name' => $name]);
    }

    private function allOk(): array
    {
        return ['packaging' => true, 'dlc' => true, 'truck' => true, 'labelling' => true, 'order' => true];
    }

    private function body(array $over = []): array
    {
        return $over + [
            'supplier_id' => $this->supplier()->id,
            'temperatures' => ['chilled' => 3, 'frozen' => -19, 'ambient' => 18],
            'checks' => $this->allOk(),
            'delivery_note_no' => 'BL-2210',
        ];
    }

    public function test_conforming_delivery_is_accepted_without_comment(): void
    {
        $this->staff();
        $r = $this->postJson(self::BASE, $this->body())->assertCreated();
        $r->assertJsonPath('data.status', 'ok')->assertJsonPath('data.decision', 'accepted')->assertJsonPath('data.supplier_name', 'Metro')
            ->assertJsonPath('data.operator_name', 'Karim Benali');
        $row = HygieneReception::query()->firstOrFail();
        $this->assertCount(3, $row->temperatures);
        $this->assertEquals(4, $row->temperatures[0]['max_c'], 'la limite du moment est copiée');
        $this->assertSame('Emballages intacts et propres', $row->checks[0]['label']);
    }

    public function test_limits_are_inclusive_and_per_family(): void
    {
        $this->staff();
        // pile sur la limite : conforme ; ambiant : aucune limite, même 40 °C ne déclenche rien
        $this->postJson(self::BASE, $this->body(['temperatures' => ['chilled' => 4, 'frozen' => -18, 'ambient' => 40]]))->assertCreated()->assertJsonPath('data.status', 'ok');
        // 4,1 en frais : écart
        $this->postJson(self::BASE, $this->body(['temperatures' => ['chilled' => 4.1]]))->assertStatus(422)->assertJsonValidationErrors('decision');
        // -17,9 en surgelé : écart (trop chaud)
        $this->postJson(self::BASE, $this->body(['temperatures' => ['frozen' => -17.9]]))->assertStatus(422)->assertJsonValidationErrors('decision');
    }

    public function test_a_deviation_forbids_plain_acceptance_and_requires_a_comment(): void
    {
        $this->staff();
        $bad = $this->body(['temperatures' => ['chilled' => 8]]);
        $this->postJson(self::BASE, $bad)->assertStatus(422)->assertJsonValidationErrors('decision');
        $this->postJson(self::BASE, $bad + ['decision' => 'accepted'])->assertStatus(422)->assertJsonValidationErrors('decision');
        $this->postJson(self::BASE, $bad + ['decision' => 'refused'])->assertStatus(422)->assertJsonValidationErrors('note');
        $this->assertSame(0, HygieneReception::query()->count());

        $r = $this->postJson(self::BASE, $bad + ['decision' => 'refused', 'note' => 'Viande à 8 °C, renvoyée'])->assertCreated();
        $r->assertJsonPath('data.status', 'alert')->assertJsonPath('data.decision', 'refused')->assertJsonPath('data.decision_label', 'Refusé');
    }

    public function test_a_failed_visual_check_is_also_a_deviation(): void
    {
        $this->staff();
        $checks = ['order' => false] + $this->allOk();
        $this->postJson(self::BASE, $this->body(['checks' => $checks]))->assertStatus(422)->assertJsonValidationErrors('decision');
        $r = $this->postJson(self::BASE, $this->body(['checks' => $checks, 'decision' => 'reserve', 'note' => 'Il manque 2 cartons']))->assertCreated();
        $r->assertJsonPath('data.status', 'alert')->assertJsonPath('data.decision', 'reserve');
    }

    public function test_every_visual_check_must_be_answered(): void
    {
        $this->staff();
        $checks = $this->allOk();
        unset($checks['dlc']);
        $this->postJson(self::BASE, $this->body(['checks' => $checks]))->assertStatus(422)->assertJsonValidationErrors('checks.dlc');
        $this->postJson(self::BASE, $this->body(['checks' => $this->allOk() + ['inconnu' => true]]))->assertStatus(422);
    }

    public function test_at_least_one_plausible_temperature(): void
    {
        $this->staff();
        $this->postJson(self::BASE, $this->body(['temperatures' => []]))->assertStatus(422);
        $this->postJson(self::BASE, $this->body(['temperatures' => ['chilled' => null]]))->assertStatus(422)->assertJsonValidationErrors('temperatures');
        $this->postJson(self::BASE, $this->body(['temperatures' => ['chilled' => 55]]))->assertStatus(422)->assertJsonValidationErrors('temperatures.chilled');
        $this->postJson(self::BASE, $this->body(['temperatures' => ['frozen' => 18]]))->assertStatus(422)->assertJsonValidationErrors('temperatures.frozen');
        $this->postJson(self::BASE, $this->body(['temperatures' => ['chaud' => 60]]))->assertStatus(422);
    }

    public function test_supplier_is_chosen_or_created_on_the_fly_without_duplicates(): void
    {
        $this->staff();
        $b = $this->body();
        unset($b['supplier_id']);
        $this->postJson(self::BASE, $b)->assertStatus(422)->assertJsonValidationErrors('supplier_id');

        $this->postJson(self::BASE, $b + ['supplier_name' => 'Pomona'])->assertCreated();
        $this->postJson(self::BASE, $b + ['supplier_name' => '  pomona '])->assertCreated();
        $this->assertSame(1, Supplier::query()->where('name', 'Pomona')->count());

        $other = $this->supplier('Autre', 2);
        $this->postJson(self::BASE, $this->body(['supplier_id' => $other->id]))->assertStatus(422);
    }

    public function test_renaming_a_supplier_does_not_rewrite_the_journal(): void
    {
        $this->staff();
        $s = $this->supplier('Metro');
        $this->postJson(self::BASE, $this->body(['supplier_id' => $s->id]))->assertCreated();
        $s->update(['name' => 'Metro Cash']);
        $this->assertSame('Metro', HygieneReception::query()->firstOrFail()->supplier_name);
    }

    public function test_quick_supplier_endpoint_is_idempotent_and_branch_scoped(): void
    {
        $this->staff();
        $a = $this->postJson(self::BASE.'/suppliers', ['name' => 'Boucherie Dupont'])->assertCreated()->json('data.id');
        $b = $this->postJson(self::BASE.'/suppliers', ['name' => 'boucherie dupont'])->assertOk()->json('data.id');
        $this->assertSame($a, $b);
        $this->supplier('Voisin', 2);
        $names = collect($this->getJson(self::BASE.'/suppliers')->json('data'))->pluck('name')->all();
        $this->assertContains('Boucherie Dupont', $names);
        $this->assertNotContains('Voisin', $names);
    }

    public function test_replay_with_same_client_ref_is_not_doubled_and_void_keeps_the_row(): void
    {
        $this->staff();
        $b = $this->body(['client_ref' => 'rcp-1']);
        $id = $this->postJson(self::BASE, $b)->assertCreated()->json('data.id');
        $this->assertSame($id, $this->postJson(self::BASE, $b)->assertOk()->json('data.id'));
        $this->assertSame(1, HygieneReception::query()->count());

        $this->postJson(self::BASE."/$id/void", ['reason' => 'x'])->assertStatus(422);
        $this->postJson(self::BASE."/$id/void", ['reason' => 'Saisie sur le mauvais BL'])->assertOk()->assertJsonPath('data.voided', true);
        $this->assertSame(1, HygieneReception::query()->count());
        $today = $this->getJson(self::BASE.'/today');
        $this->assertSame(0, $today->json('meta.summary.count'));
        $this->assertCount(1, $today->json('data'), 'annulée mais visible, marquée');
    }

    public function test_today_summary_counts_alerts_and_refusals(): void
    {
        $this->staff();
        $this->postJson(self::BASE, $this->body())->assertCreated();
        $this->postJson(self::BASE, $this->body(['temperatures' => ['chilled' => 9], 'decision' => 'refused', 'note' => 'Trop chaud']))->assertCreated();
        $this->postJson(self::BASE, $this->body(['temperatures' => ['frozen' => -10], 'decision' => 'reserve', 'note' => 'Légèrement décongelé']))->assertCreated();
        $s = $this->getJson(self::BASE.'/today')->json('meta.summary');
        $this->assertSame(['count' => 3, 'alerts' => 2, 'refused' => 1], $s);
        $this->assertCount(2, $this->getJson(self::BASE.'/history?alerts_only=1')->json('data'));
    }

    public function test_photo_must_belong_to_this_restaurant(): void
    {
        $this->staff(1);
        $mine = new HygieneAttachment(['kind' => 'bl', 'path' => 'x.jpg', 'mime' => 'image/jpeg', 'bytes' => 1]);
        $mine->branch_id = 1;
        $mine->save();
        $theirs = new HygieneAttachment(['kind' => 'bl', 'path' => 'y.jpg', 'mime' => 'image/jpeg', 'bytes' => 1]);
        $theirs->branch_id = 2;
        $theirs->save();
        $this->postJson(self::BASE, $this->body(['attachment_id' => $theirs->id]))->assertStatus(422);
        $this->postJson(self::BASE, $this->body(['attachment_id' => $mine->id, 'attachment_ids' => [$mine->id]]))->assertCreated()->assertJsonPath('data.attachment_id', $mine->id);
    }

    public function test_branch_isolation_and_permission(): void
    {
        $this->staff(1);
        $id = $this->postJson(self::BASE, $this->body())->assertCreated()->json('data.id');
        $this->staff(2);
        $this->assertCount(0, $this->getJson(self::BASE.'/today')->json('data'));
        $this->postJson(self::BASE."/$id/void", ['reason' => 'piratage'])->assertNotFound();

        $this->app['auth']->forgetGuards();
        $u = User::factory()->create(['branch_id' => 1]);
        Sanctum::actingAs($u, ['*']);
        $this->getJson(self::BASE.'/today')->assertForbidden();
    }
}
