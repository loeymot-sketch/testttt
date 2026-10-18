<?php

namespace Tests\Feature\Labels;

use App\Models\Branch;
use App\Models\DlcLabelProduct;
use App\Models\LabelPrintLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * [CAYENNE HYGIÈNE 2026-10-18 · rapport Komia « Repas témoin : Moment de la journée obligatoire »] Un repas témoin dit de QUEL SERVICE il est issu.
 * Jamais deviné d'après l'heure ; jamais exigé pour les autres événements ; hors ligne il est signalé, pas refusé (l'étiquette est déjà sur le plat).
 */
class DlcWitnessMealTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Branch::factory()->create(['id' => 1]);
        config(['hygiene.branch_id' => 1]);
        Carbon::setTestNow(Carbon::parse('2026-10-20 14:00:00', config('app.timezone')));
        $u = User::factory()->create(['branch_id' => 1, 'name' => 'Tablette Cuisine']);
        $u->givePermissionTo('pos-orders');
        $u->assignRole('POS Operator');
        Sanctum::actingAs($u, ['*']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function product(array $actions = null): DlcLabelProduct
    {
        $p = new DlcLabelProduct([
            'name' => 'Poulet rôti', 'category' => 'Plats', 'shelf_life_value' => 3, 'shelf_life_unit' => 'days',
            'actions' => $actions ?? [['key' => 'prepared', 'value' => 3, 'unit' => 'days'], ['key' => 'witness', 'value' => 5, 'unit' => 'days']],
            'storage' => '0 à +4 °C', 'is_active' => true, 'sort_order' => 1, 'no_allergens' => true,
        ]);
        $p->branch_id = 1;
        $p->save();

        return $p;
    }

    private function print(DlcLabelProduct $p, array $extra = [])
    {
        return $this->postJson('/api/admin/labels/dlc/print', ['product_id' => $p->id, 'copies' => 1] + $extra);
    }

    public function test_un_repas_temoin_sans_service_est_refuse_et_rien_n_est_journalise(): void
    {
        $p = $this->product();
        $this->print($p, ['action' => 'witness'])->assertStatus(422)->assertJsonPath('status', 'meal_slot_required')->assertJsonValidationErrors('meal_slot');
        $this->assertSame(0, LabelPrintLog::query()->count());
    }

    public function test_le_service_n_est_jamais_devine_d_apres_l_heure(): void
    {
        // 14 h : un « midi » plausible — le serveur ne le déduit pas pour autant
        $this->print($this->product(), ['action' => 'witness'])->assertStatus(422);
    }

    public function test_un_service_inconnu_est_refuse(): void
    {
        $this->print($this->product(), ['action' => 'witness', 'meal_slot' => 'night'])->assertStatus(422)->assertJsonValidationErrors('meal_slot');
    }

    public function test_chaque_service_est_journalise_et_renvoye_pour_le_papier(): void
    {
        foreach (['morning', 'noon', 'evening'] as $slot) {
            $r = $this->print($this->product(), ['action' => 'witness', 'meal_slot' => $slot])->assertOk();
            $r->assertJsonPath('meal_slot', $slot);
            $this->assertSame($slot, LabelPrintLog::query()->findOrFail($r->json('log_id'))->meal_slot);
        }
    }

    public function test_les_autres_evenements_n_exigent_pas_de_service_et_l_ignorent(): void
    {
        $p = $this->product();
        $r = $this->print($p, ['action' => 'prepared'])->assertOk();
        $this->assertNull(LabelPrintLog::query()->findOrFail($r->json('log_id'))->meal_slot);
        $r2 = $this->print($p, ['action' => 'prepared', 'meal_slot' => 'noon'])->assertOk();
        $this->assertNull(LabelPrintLog::query()->findOrFail($r2->json('log_id'))->meal_slot);
    }

    public function test_le_journal_expose_le_service(): void
    {
        $this->print($this->product(), ['action' => 'witness', 'meal_slot' => 'evening'])->assertOk();
        $row = collect($this->getJson('/api/admin/labels/history')->assertOk()->json('data'))->firstWhere('action', 'witness');
        $this->assertSame('evening', $row['meal_slot']);
    }

    private function offline(array $extra = []): array
    {
        return $this->postJson('/api/admin/labels/dlc/offline', array_merge([
            'client_ref' => 'ref-'.uniqid(), 'product_id' => $this->product()->id, 'action' => 'witness', 'copies' => 1,
            'opened_at' => now()->subMinutes(5)->toIso8601String(), 'expires_at' => now()->addDays(5)->subMinutes(5)->toIso8601String(),
            'storage' => DlcLabelProduct::WITNESS_STORAGE, 'status' => 'printed',
        ], $extra))->assertOk()->json();
    }

    public function test_hors_ligne_le_service_est_garde(): void
    {
        $r = $this->offline(['meal_slot' => 'noon']);
        $log = LabelPrintLog::query()->findOrFail($r['log_id']);
        $this->assertSame('noon', $log->meal_slot);
        $this->assertFalse((bool) $log->anomaly);
    }

    public function test_hors_ligne_un_service_envoye_pour_un_autre_evenement_est_ignore(): void
    {
        $r = $this->offline(['action' => 'prepared', 'meal_slot' => 'noon', 'storage' => '0 à +4 °C']);
        $this->assertNull(LabelPrintLog::query()->findOrFail($r['log_id'])->meal_slot);
    }

    public function test_hors_ligne_un_service_manquant_signale_sans_perdre_l_etiquette(): void
    {
        $r = $this->offline();
        $log = LabelPrintLog::query()->findOrFail($r['log_id']);
        $this->assertNull($log->meal_slot);
        $this->assertTrue((bool) $log->anomaly);
        $this->assertStringContainsString('service du repas témoin non précisé', $log->message);
    }
}
