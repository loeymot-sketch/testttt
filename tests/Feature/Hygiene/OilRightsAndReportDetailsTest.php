<?php

namespace Tests\Feature\Hygiene;

use App\Models\Branch;
use App\Models\HygieneAttachment;
use App\Models\HygieneEquipment;
use App\Models\HygieneOilControl;
use App\Models\HygieneTaskTemplate;
use App\Models\User;
use App\Services\Hygiene\ReportBuilder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * [AUDIT E2E 2026-11-18] Huile et dossier :
 *  - P0 droits : déclarer/renommer/masquer/supprimer une friteuse est réservé au responsable (`settings`) ; tester, filtrer, vidanger, annuler restent ouverts ;
 *  - P1 : la photo de preuve (nettoyage, huile) est dite dans le PDF et le CSV ;
 *  - P2 : annulation = motif + QUI + QUAND ; verdict CSV vide hors test ; marque d'huile au PDF ; jour concerné au format français ; volume « 0,5 ».
 */
class OilRightsAndReportDetailsTest extends TestCase
{
    use RefreshDatabase;

    private const OIL = '/api/admin/hygiene/oil';
    private const TASKS = '/api/admin/hygiene/tasks';
    private const REPORT = '/api/admin/hygiene/report';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Branch::factory()->create(['id' => 1]);
        Carbon::setTestNow(Carbon::parse('2026-11-18 10:00:00', config('app.timezone')));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(bool $manager, string $name): User
    {
        $u = User::factory()->create(['branch_id' => 1, 'name' => $name]);
        $u->givePermissionTo($manager ? ['pos-orders', 'settings'] : ['pos-orders']);
        $u->assignRole($manager ? 'Branch Manager' : 'POS Operator');
        Sanctum::actingAs($u, ['*']);

        return $u;
    }

    private function fryer(string $name = 'Friteuse 1'): HygieneEquipment
    {
        $e = new HygieneEquipment(['name' => $name, 'kind' => 'fryer', 'sort_order' => 10, 'is_active' => true]);
        $e->branch_id = 1;
        $e->save();

        return $e;
    }

    private function photo(): int
    {
        $a = new HygieneAttachment(['kind' => 'strip', 'path' => 'hygiene/x.jpg', 'mime' => 'image/jpeg', 'bytes' => 10, 'sha256' => str_repeat('a', 64)]);
        $a->branch_id = 1;
        $a->save();

        return $a->id;
    }

    private function csv(string $module): array
    {
        $csv = $this->get(self::REPORT."/csv?module=$module&from=2026-11-18&to=2026-11-18")->assertOk()->streamedContent();
        $lines = array_values(array_filter(explode("\n", trim(substr($csv, 3)))));

        return array_map(fn ($l) => str_getcsv($l, ';'), $lines);
    }

    // ───────── droits ─────────

    public function test_un_caissier_ne_gere_pas_les_friteuses_mais_fait_les_controles(): void
    {
        $this->user(false, 'Caissier');
        $f = $this->fryer();

        $this->postJson(self::OIL.'/fryers/quick-setup', ['count' => 2])->assertForbidden();
        $this->postJson(self::OIL.'/fryers', ['name' => 'Friteuse pirate'])->assertForbidden();
        $this->putJson(self::OIL."/fryers/{$f->id}", ['name' => 'Renommée'])->assertForbidden();
        $this->putJson(self::OIL."/fryers/{$f->id}", ['is_active' => false])->assertForbidden();
        $this->deleteJson(self::OIL."/fryers/{$f->id}")->assertForbidden();
        $this->assertSame(['Friteuse 1'], HygieneEquipment::query()->pluck('name')->all());
        $this->assertTrue($f->fresh()->is_active);

        // les gestes du jour restent ouverts à tout le personnel
        $this->getJson(self::OIL.'/fryers')->assertOk();
        $this->getJson(self::OIL.'/today')->assertOk();
        $id = $this->postJson(self::OIL.'/controls', ['equipment_id' => $f->id, 'type' => 'test', 'polar_pct' => 12])->assertCreated()->json('data.id');
        $this->postJson(self::OIL.'/controls', ['equipment_id' => $f->id, 'type' => 'filter'])->assertCreated();
        $this->postJson(self::OIL.'/controls', ['equipment_id' => $f->id, 'type' => 'change', 'oil_litres' => 12])->assertCreated();
        $this->postJson(self::OIL."/controls/$id/void", ['reason' => 'Mauvaise friteuse'])->assertOk();
    }

    public function test_le_responsable_gere_les_friteuses(): void
    {
        $this->user(true, 'Responsable');
        $this->postJson(self::OIL.'/fryers/quick-setup', ['count' => 2])->assertCreated();
        $id = $this->postJson(self::OIL.'/fryers', ['name' => 'Friteuse frites'])->assertCreated()->json('data.id');
        $this->putJson(self::OIL."/fryers/$id", ['name' => 'Friteuse à frites'])->assertOk();
        $this->deleteJson(self::OIL."/fryers/$id")->assertOk();
    }

    // ───────── dossier : photo, annulation, verdict, marque, format ─────────

    public function test_le_dossier_dit_la_photo_l_annulation_complete_et_ne_juge_que_les_tests(): void
    {
        $this->user(true, 'Sarah Lopez');
        $f = $this->fryer();
        $t = new HygieneTaskTemplate(['name' => 'Salle', 'checklist' => [['key' => 'k1', 'label' => 'Sols']], 'frequency' => 'daily', 'sort_order' => 10, 'is_active' => true]);
        $t->branch_id = 1;
        $t->save();
        $p1 = $this->photo();
        $p2 = $this->photo();

        $task = $this->postJson(self::TASKS.'/completions', ['template_id' => $t->id, 'all' => true, 'attachment_id' => $p1])->assertCreated()->json('data.id');
        Carbon::setTestNow(Carbon::parse('2026-11-18 11:30:00', config('app.timezone')));
        $this->postJson(self::TASKS."/completions/$task/void", ['reason' => 'Mauvaise fiche'])->assertOk();
        $this->postJson(self::OIL.'/controls', ['equipment_id' => $f->id, 'type' => 'test', 'polar_pct' => 14, 'attachment_id' => $p2])->assertCreated();
        $this->postJson(self::OIL.'/controls', ['equipment_id' => $f->id, 'type' => 'change', 'oil_litres' => 12.5, 'oil_brand' => 'Frial Pro'])->assertCreated();
        $filter = $this->postJson(self::OIL.'/controls', ['equipment_id' => $f->id, 'type' => 'filter'])->assertCreated()->json('data.id');
        $this->postJson(self::OIL."/controls/$filter/void", ['reason' => 'Doublon'])->assertOk();

        // CSV nettoyage : jour concerné à la française, photo, annulation avec qui et quand
        $rows = $this->csv('tasks');
        $head = $rows[0];
        $row = array_combine($head, $rows[1]);
        $this->assertSame('18/11/2026', $row['Jour concerné']);
        $this->assertSame('oui', $row['Photo']);
        $this->assertSame('ANNULÉ : Mauvaise fiche — par Sarah Lopez le 18/11/2026 11:30', $row['Annulation']);

        // CSV huile : verdict d'un test seulement ; photo ; annulation complète
        $rows = $this->csv('oil');
        $head = $rows[0];
        $byType = collect(array_slice($rows, 1))->map(fn ($r) => array_combine($head, $r))->keyBy('Opération');
        $this->assertSame('conforme', $byType['Test de l’huile']['Verdict']);
        $this->assertSame('oui', $byType['Test de l’huile']['Photo']);
        $this->assertSame('', $byType['Vidange / changement']['Verdict'], 'une vidange n’est pas « conforme » : elle n’est pas jugée');
        $this->assertSame('Frial Pro', $byType['Vidange / changement']['Huile']);
        $this->assertSame('', $byType['Filtrage']['Verdict']);
        $this->assertSame('ANNULÉ : Doublon — par Sarah Lopez le 18/11/2026 11:30', $byType['Filtrage']['Annulation']);

        // PDF : marque d'huile, photo, annulation complète
        $html = view('pdf.hygiene_report', ['report' => ReportBuilder::between('2026-11-18', '2026-11-18')->build(true), 'company' => [], 'generatedAt' => 'x', 'generatedBy' => 'y', 'fingerprint' => 'z'])->render();
        $this->assertStringContainsString('Frial Pro', $html);
        $this->assertSame(2, substr_count($html, 'Photo : oui'), 'une mention par ligne avec photo (nettoyage + huile)');
        $this->assertStringContainsString('annulé : Mauvaise fiche — par Sarah Lopez le 18/11/2026 11:30', $html);
        $this->assertStringContainsString('annulé : Doublon — par Sarah Lopez le 18/11/2026 11:30', $html);

        // l'écran (résumé) ne reçoit toujours pas les lignes
        $this->assertArrayNotHasKey('rows', $this->getJson(self::REPORT.'/summary?from=2026-11-18&to=2026-11-18')->json('data.oil'));
    }

    public function test_le_volume_hors_bornes_est_dit_a_la_francaise(): void
    {
        $this->user(false, 'Caissier');
        $f = $this->fryer();
        $r = $this->postJson(self::OIL.'/controls', ['equipment_id' => $f->id, 'type' => 'change', 'oil_litres' => 0.2])->assertStatus(422);
        $this->assertSame('Le volume doit être compris entre 0,5 et 200 litres.', $r->json('errors.oil_litres.0'));
        $this->assertSame(0, HygieneOilControl::query()->count());
    }
}
