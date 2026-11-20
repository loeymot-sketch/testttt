<?php

namespace Tests\Feature\Hygiene;

use App\Models\Branch;
use App\Models\DlcLabelProduct;
use App\Models\HygieneReception;
use App\Models\HygieneTraceEntry;
use App\Models\LabelPrintLog;
use App\Models\Supplier;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * [CAYENNE HYGIÈNE 2026-10-17] Traçabilité amont : lot obligatoire (ou explication), DLC dépassée = écart, rattachement à la
 * réception, journal append-only — et la fiche de RAPPEL d'un lot (entrées, réceptions, étiquettes DLC et leurs descendantes).
 */
class TraceabilityControllerTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/admin/hygiene/trace';

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

    private function body(array $over = []): array
    {
        return $over + ['product_name' => 'Steak haché surgelé', 'lot' => 'L2210-A', 'dlc_date' => '2026-12-01', 'supplier_name' => 'Metro'];
    }

    private function reception(string $bl = 'BL-1'): HygieneReception
    {
        $r = new HygieneReception([
            'supplier_name' => 'Metro', 'delivery_note_no' => $bl, 'temperatures' => [], 'checks' => [], 'status' => 'ok', 'decision' => 'accepted',
            'received_at' => now(), 'timezone' => 'Europe/Paris',
        ]);
        $r->branch_id = 1;
        $r->save();

        return $r;
    }

    private function label(array $attrs): LabelPrintLog
    {
        $l = new LabelPrintLog($attrs + ['kind' => 'dlc', 'status' => 'printed', 'copies' => 1, 'opened_at' => now()->subHours(2), 'expires_at' => now()->addDay()]);
        $l->branch_id = 1;
        $l->save();

        return $l;
    }

    public function test_a_complete_entry_is_recorded_with_operator_and_copied_context(): void
    {
        $this->staff();
        $r = $this->postJson(self::BASE, $this->body())->assertCreated();
        $r->assertJsonPath('data.status', 'ok')->assertJsonPath('data.lot', 'L2210-A')->assertJsonPath('data.dlc_date', '2026-12-01')
            ->assertJsonPath('data.supplier_name', 'Metro')->assertJsonPath('data.operator_name', 'Karim Benali');
        $this->assertSame(1, HygieneTraceEntry::query()->count());
    }

    public function test_a_product_from_the_dlc_catalogue_is_named_by_the_server(): void
    {
        $this->staff();
        $p = new DlcLabelProduct(['name' => 'Cheddar tranché', 'shelf_life_value' => 5, 'shelf_life_unit' => 'days', 'storage' => '0 à +4 °C', 'is_active' => true]);
        $p->branch_id = 1;
        $p->save();
        $this->postJson(self::BASE, ['dlc_product_id' => $p->id, 'product_name' => 'FAUX', 'lot' => 'C1'])->assertCreated()->assertJsonPath('data.product_name', 'Cheddar tranché');
        $this->postJson(self::BASE, ['dlc_product_id' => 99999, 'lot' => 'C1'])->assertStatus(422)->assertJsonValidationErrors('dlc_product_id');
    }

    public function test_lot_is_required_unless_the_absence_is_explained(): void
    {
        $this->staff();
        $this->postJson(self::BASE, ['product_name' => 'Pain burger'])->assertStatus(422)->assertJsonValidationErrors('lot');
        $this->postJson(self::BASE, ['product_name' => 'Pain burger', 'note' => 'ab'])->assertStatus(422)->assertJsonValidationErrors('lot');
        $this->postJson(self::BASE, ['product_name' => 'Pain burger', 'note' => 'Aucun lot sur le sachet'])->assertCreated()->assertJsonPath('data.lot', null);
        $this->postJson(self::BASE, ['lot' => 'X1'])->assertStatus(422)->assertJsonValidationErrors('product_name');
    }

    public function test_an_already_expired_dlc_is_a_deviation_that_needs_a_comment(): void
    {
        $this->staff();
        $this->postJson(self::BASE, $this->body(['dlc_date' => '2026-10-19']))->assertStatus(422)->assertJsonValidationErrors('note');
        $r = $this->postJson(self::BASE, $this->body(['dlc_date' => '2026-10-19', 'note' => 'Refusé et rendu au livreur']))->assertCreated();
        $r->assertJsonPath('data.status', 'alert');
        // le jour même reste valable : la DLC « 20/10 » est bonne jusqu'à minuit
        $this->postJson(self::BASE, $this->body(['dlc_date' => '2026-10-20', 'lot' => 'L2']))->assertCreated()->assertJsonPath('data.status', 'ok');
        $this->postJson(self::BASE, $this->body(['dlc_date' => '2040-01-01', 'lot' => 'L3']))->assertStatus(422)->assertJsonValidationErrors('dlc_date');
    }

    public function test_linking_a_reception_brings_its_supplier_and_delivery_note(): void
    {
        $this->staff();
        $rc = $this->reception('BL-77');
        $b = $this->body(['reception_id' => $rc->id]);
        unset($b['supplier_name']);
        $this->postJson(self::BASE, $b)->assertCreated()->assertJsonPath('data.supplier_name', 'Metro')->assertJsonPath('data.delivery_note_no', 'BL-77')->assertJsonPath('data.reception_id', $rc->id);
        $rc->forceFill(['voided_at' => now()])->save();
        $this->postJson(self::BASE, $this->body(['reception_id' => $rc->id, 'lot' => 'Z']))->assertStatus(422)->assertJsonValidationErrors('reception_id');
    }

    public function test_replay_void_and_history_search(): void
    {
        $this->staff();
        $b = $this->body(['client_ref' => 'tr-1']);
        $id = $this->postJson(self::BASE, $b)->assertCreated()->json('data.id');
        $this->assertSame($id, $this->postJson(self::BASE, $b)->assertOk()->json('data.id'));
        $this->postJson(self::BASE, $this->body(['product_name' => 'Cheddar', 'lot' => 'CH-9']))->assertCreated();

        $this->assertCount(1, $this->getJson(self::BASE.'/history?q=ch-9')->json('data'));
        $this->assertCount(1, $this->getJson(self::BASE.'/history?q=steak')->json('data'));
        $this->assertCount(0, $this->getJson(self::BASE.'/history?q=%25')->json('data'), 'le % est littéral, pas un joker');

        $this->postJson(self::BASE."/$id/void", ['reason' => 'x'])->assertStatus(422);
        $this->postJson(self::BASE."/$id/void", ['reason' => 'Mauvais produit'])->assertOk()->assertJsonPath('data.voided', true);
        $this->assertSame(2, HygieneTraceEntry::query()->count());
        $this->assertSame(1, $this->getJson(self::BASE.'/today')->json('meta.summary.count'));
    }

    public function test_recall_sheet_gathers_entries_receptions_and_derived_labels(): void
    {
        $this->staff();
        $rc = $this->reception('BL-500');
        $this->postJson(self::BASE, $this->body(['lot' => 'LOT-77', 'reception_id' => $rc->id]))->assertCreated();
        $this->postJson(self::BASE, $this->body(['lot' => 'AUTRE', 'product_name' => 'Cheddar']))->assertCreated();

        $root = $this->label(['product_name' => 'Steak haché décongelé', 'supplier_lot' => 'lot-77 ', 'lot' => 'L261020-0001']);
        $child = $this->label(['product_name' => 'Steak façonné', 'lot' => 'L261020-0002', 'parent_log_id' => $root->id]);
        $grand = $this->label(['product_name' => 'Burger cuit', 'lot' => 'L261020-0003', 'parent_log_id' => $child->id, 'discarded_at' => now(), 'discard_reason' => 'discarded']);
        $this->label(['product_name' => 'Sans rapport', 'supplier_lot' => 'AUTRE', 'lot' => 'L261020-0009']);
        $this->label(['product_name' => 'Annulée', 'supplier_lot' => 'LOT-77', 'lot' => 'L261020-0010', 'discarded_at' => now(), 'discard_reason' => 'cancelled']);

        $r = $this->getJson(self::BASE.'/recall?lot=LOT-77')->assertOk();
        $this->assertCount(1, $r->json('data.entries'));
        $this->assertSame('BL-500', $r->json('data.receptions.0.delivery_note_no'));
        $labels = collect($r->json('data.labels'));
        $this->assertSame(['Steak haché décongelé', 'Steak façonné', 'Burger cuit'], $labels->pluck('product_name')->all());
        $this->assertSame([0, 1, 2], $labels->pluck('depth')->all());
        $this->assertSame('discarded', $labels->firstWhere('id', $grand->id)['discard_reason']);

        $this->getJson(self::BASE.'/recall?lot=a')->assertStatus(422);
        $empty = $this->getJson(self::BASE.'/recall?lot=INCONNU')->assertOk();
        $this->assertSame([], $empty->json('data.labels'));
    }

    public function test_recall_survives_a_cycle_in_the_label_chain(): void
    {
        $this->staff();
        $a = $this->label(['product_name' => 'A', 'supplier_lot' => 'LOOP']);
        $b = $this->label(['product_name' => 'B', 'parent_log_id' => $a->id]);
        $a->forceFill(['parent_log_id' => $b->id])->save();
        $r = $this->getJson(self::BASE.'/recall?lot=LOOP')->assertOk();
        $this->assertCount(2, $r->json('data.labels'));
    }

    public function test_branch_isolation_and_permission(): void
    {
        $this->staff(1);
        $id = $this->postJson(self::BASE, $this->body())->assertCreated()->json('data.id');
        $this->label(['product_name' => 'X', 'supplier_lot' => 'L2210-A']);
        $this->staff(2);
        $this->assertCount(0, $this->getJson(self::BASE.'/today')->json('data'));
        $this->assertCount(0, $this->getJson(self::BASE.'/recall?lot=L2210-A')->json('data.entries'));
        $this->assertCount(0, $this->getJson(self::BASE.'/recall?lot=L2210-A')->json('data.labels'));
        $this->postJson(self::BASE."/$id/void", ['reason' => 'piratage'])->assertNotFound();

        Sanctum::actingAs(User::factory()->create(['branch_id' => 1]), ['*']);
        $this->getJson(self::BASE.'/today')->assertForbidden();
    }
}
