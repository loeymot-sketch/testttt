<?php

namespace Tests\Feature\Hygiene;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\HygieneEquipment;
use App\Models\HygieneTaskCompletion;
use App\Models\HygieneTaskTemplate;
use App\Models\HygieneTemperatureReading;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Hygiene\ReportBuilder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * [CAYENNE HYGIÈNE 2026-10-17] Non-régression des défauts trouvés par la revue adverse : un seuil ne se désarme pas, une référence d'envoi
 * ne masque pas un relevé perdu, un salarié désactivé n'est pas opérateur, le dossier ne ment pas sur de grandes périodes.
 */
class ReviewHardeningTest extends TestCase
{
    use RefreshDatabase;

    private const TEMP = '/api/admin/hygiene/temperature';
    private const BASE = '/api/admin/hygiene';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Branch::factory()->create(['id' => 1]);
        Branch::factory()->create(['id' => 2]);
        Carbon::setTestNow(Carbon::parse('2026-10-20 10:00:00', config('app.timezone')));
        Storage::fake('local');
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

    private function fridge(string $name = 'Frigo 1'): HygieneEquipment
    {
        $this->postJson(self::TEMP.'/equipment', ['name' => $name, 'kind' => 'fridge'])->assertCreated();

        return HygieneEquipment::query()->where('name', $name)->firstOrFail();
    }

    // ───────── seuils : on les règle, on ne les désarme pas ─────────

    public function test_a_threshold_cannot_be_disarmed_or_raised_out_of_bounds(): void
    {
        $this->staff();
        $f = $this->fridge();
        $this->putJson(self::TEMP."/equipment/{$f->id}", ['min_c' => null, 'max_c' => null])->assertStatus(422)->assertJsonValidationErrors('max_c');
        $this->putJson(self::TEMP."/equipment/{$f->id}", ['max_c' => 60])->assertStatus(422)->assertJsonValidationErrors('max_c');
        $this->putJson(self::TEMP."/equipment/{$f->id}", ['max_c' => 8.1])->assertStatus(422);
        $this->putJson(self::TEMP."/equipment/{$f->id}", ['max_c' => 8, 'min_c' => 0])->assertOk(); // la borne haute elle-même est permise
        // et surtout : le relevé qui aurait passé avec le seuil désarmé est bien un écart
        $this->putJson(self::TEMP."/equipment/{$f->id}", ['max_c' => 4])->assertOk();
        $this->postJson(self::TEMP.'/readings', ['readings' => [['equipment_id' => $f->id, 'value' => 30]]])->assertStatus(422)->assertJsonValidationErrors('readings.0.corrective_action');
    }

    public function test_a_partial_update_cannot_produce_incoherent_thresholds(): void
    {
        $this->staff();
        $f = $this->fridge();
        $this->putJson(self::TEMP."/equipment/{$f->id}", ['max_c' => -30])->assertStatus(422); // min 0 déjà enregistré, max −30 hors bornes
        $this->putJson(self::TEMP."/equipment/{$f->id}", ['min_c' => 5])->assertStatus(422)->assertJsonValidationErrors('min_c'); // 5 > max 4
        $this->putJson(self::TEMP."/equipment/{$f->id}", ['name' => 'Frigo renommé'])->assertOk(); // un simple renommage ne touche pas aux seuils
        $this->assertEquals(4, $f->refresh()->max_c);
    }

    public function test_freezer_bounds_are_its_own(): void
    {
        $this->staff();
        $this->postJson(self::TEMP.'/equipment/quick-setup', ['freezer' => 1])->assertCreated();
        $z = HygieneEquipment::query()->where('kind', 'freezer')->firstOrFail();
        $this->putJson(self::TEMP."/equipment/{$z->id}", ['max_c' => 4])->assertStatus(422);
        $this->putJson(self::TEMP."/equipment/{$z->id}", ['max_c' => -10])->assertStatus(422);
        $this->putJson(self::TEMP."/equipment/{$z->id}", ['max_c' => -20])->assertOk();
        $this->postJson(self::TEMP.'/equipment', ['name' => 'Congélateur bis', 'kind' => 'freezer', 'max_c' => 20])->assertStatus(422);
    }

    // ───────── références d'envoi ─────────

    public function test_a_client_ref_cannot_hide_a_reading_for_another_equipment_or_value(): void
    {
        $this->staff();
        $a = $this->fridge('A');
        $b = $this->fridge('B');
        $this->postJson(self::TEMP.'/readings', ['readings' => [['equipment_id' => $a->id, 'value' => 3, 'client_ref' => 'same']]])->assertCreated();
        // même référence, autre équipement : refusé (jamais « 201 » avec la ligne de A)
        $this->postJson(self::TEMP.'/readings', ['readings' => [['equipment_id' => $b->id, 'value' => 3, 'client_ref' => 'same']]])->assertStatus(409);
        // même équipement, valeur corrigée : refusé aussi (la correction ne doit pas disparaître sous un « enregistré »)
        $this->postJson(self::TEMP.'/readings', ['readings' => [['equipment_id' => $a->id, 'value' => 2, 'client_ref' => 'same']]])->assertStatus(409);
        // le VRAI rejeu (même contenu) reste silencieux et sûr
        $this->postJson(self::TEMP.'/readings', ['readings' => [['equipment_id' => $a->id, 'value' => 3, 'client_ref' => 'same']]])->assertCreated();
        $this->assertSame(1, HygieneTemperatureReading::query()->count());
        // deux relevés d'un même lot avec la même référence
        $this->postJson(self::TEMP.'/readings', ['readings' => [['equipment_id' => $a->id, 'value' => 3, 'client_ref' => 'dup'], ['equipment_id' => $b->id, 'value' => 3, 'client_ref' => 'dup']]])->assertStatus(422);
    }

    public function test_task_replay_with_another_template_is_a_conflict(): void
    {
        $this->staff();
        $this->postJson(self::BASE.'/tasks/templates/install-recommended')->assertCreated();
        [$t1, $t2] = HygieneTaskTemplate::query()->where('frequency', 'daily')->orderBy('id')->get()->all();
        $this->postJson(self::BASE.'/tasks/completions', ['template_id' => $t1->id, 'all' => true, 'client_ref' => 'k1'])->assertCreated();
        $this->postJson(self::BASE.'/tasks/completions', ['template_id' => $t2->id, 'all' => true, 'client_ref' => 'k1'])->assertStatus(409);
        $this->postJson(self::BASE.'/tasks/completions', ['template_id' => $t1->id, 'all' => true, 'client_ref' => 'k1'])->assertOk();
    }

    // ───────── opérateur, fiches, fournisseurs, photos ─────────

    public function test_a_deactivated_employee_is_neither_listed_nor_accepted_as_operator(): void
    {
        $this->staff();
        $gone = User::factory()->create(['branch_id' => 1, 'name' => 'Ancien Salarié', 'status' => Status::INACTIVE]);
        $gone->assignRole('Chef');
        $names = collect($this->getJson(self::BASE.'/operators')->json('data'))->pluck('name')->all();
        $this->assertNotContains('Ancien Salarié', $names);
        $this->postJson(self::BASE.'/tasks/templates/install-recommended')->assertCreated();
        $t = HygieneTaskTemplate::query()->where('frequency', 'daily')->firstOrFail();
        $this->postJson(self::BASE.'/tasks/completions', ['template_id' => $t->id, 'all' => true, 'operator_user_id' => $gone->id])->assertStatus(422)->assertJsonValidationErrors('operator_user_id');
    }

    public function test_a_partial_cleaning_sheet_is_not_counted_as_done_on_the_overview(): void
    {
        $this->staff();
        $t = new HygieneTaskTemplate(['name' => 'Salle', 'checklist' => [['key' => 'k1', 'label' => 'A'], ['key' => 'k2', 'label' => 'B']], 'frequency' => 'daily', 'sort_order' => 10, 'is_active' => true]);
        $t->branch_id = 1;
        $t->save();
        $this->postJson(self::BASE.'/tasks/completions', ['template_id' => $t->id, 'done' => ['k1'], 'note' => 'Plus de produit'])->assertCreated();
        $tile = collect($this->getJson(self::BASE.'/overview')->json('data'))->firstWhere('key', 'tasks');
        $this->assertSame('todo', $tile['status']);
        $this->assertSame('0/1 fiches', $tile['headline']);
        $this->assertStringContainsString('1 incomplète', $tile['detail']);
        $this->postJson(self::BASE.'/tasks/completions', ['template_id' => $t->id, 'all' => true])->assertCreated(); // la reprise reste possible
        $this->assertSame('ok', collect($this->getJson(self::BASE.'/overview')->json('data'))->firstWhere('key', 'tasks')['status']);
    }

    public function test_malformed_checklist_items_are_a_422_not_a_500(): void
    {
        $this->staff();
        foreach ([[['label' => ['a']]], [['key' => ['a'], 'label' => 'ok']], [123], [['nolabel' => 'x']]] as $bad) {
            $this->postJson(self::BASE.'/tasks/templates', ['name' => 'X'.uniqid(), 'frequency' => 'daily', 'checklist' => $bad])->assertStatus(422);
        }
    }

    public function test_a_soft_deleted_supplier_is_restored_instead_of_breaking_receptions(): void
    {
        $this->staff();
        $s = Supplier::query()->create(['branch_id' => 1, 'name' => 'Metro']);
        $s->delete();
        $body = ['supplier_name' => 'Metro', 'temperatures' => ['chilled' => 3], 'checks' => ['packaging' => true, 'dlc' => true, 'truck' => true, 'labelling' => true, 'order' => true]];
        $this->postJson(self::BASE.'/receptions', $body)->assertCreated()->assertJsonPath('data.supplier_name', 'Metro');
        $this->assertFalse(Supplier::query()->withTrashed()->where('name', 'Metro')->firstOrFail()->trashed());
        $this->assertSame(1, Supplier::query()->withTrashed()->where('name', 'Metro')->count());
    }

    public function test_a_photo_declaring_a_huge_canvas_is_refused_before_decoding(): void
    {
        $this->staff();
        // PNG minimal dont l'en-tête annonce 30 000 × 30 000 px (900 Mpx) : seul IHDR est lu, rien n'est décodé
        $ihdr = pack('N', 30000).pack('N', 30000)."\x08\x00\x00\x00\x00";
        $png = "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.$ihdr.pack('N', crc32('IHDR'.$ihdr)).pack('N', 0).'IEND'.pack('N', crc32('IEND'));
        $f = tempnam(sys_get_temp_dir(), 'hy').'.png';
        file_put_contents($f, $png);
        $this->post(self::BASE.'/attachments', ['file' => new UploadedFile($f, 'big.png', 'image/png', null, true)], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('file');
    }

    // ───────── dossier : pas de mensonge sur les grandes périodes ─────────

    private function completion(HygieneTaskTemplate $t, string $day, int $i): void
    {
        $c = new HygieneTaskCompletion([
            'template_id' => $t->id, 'task_name' => $t->name, 'due_date' => $day, 'items' => [['key' => 'k1', 'label' => 'A', 'done' => true]], 'items_total' => 1, 'items_done' => 1,
            'complete' => true, 'completed_at' => Carbon::parse("$day 08:00:00", config('app.timezone'))->addSeconds($i), 'operator_name' => 'K',
        ]);
        $c->branch_id = 1;
        $c->save();
    }

    public function test_csv_gives_every_row_and_the_pdf_cap_says_how_many_are_missing(): void
    {
        $this->staff();
        $t = new HygieneTaskTemplate(['name' => 'Salle', 'checklist' => [['key' => 'k1', 'label' => 'A']], 'frequency' => 'daily', 'sort_order' => 10, 'is_active' => true]);
        $t->branch_id = 1;
        $t->save();
        for ($i = 0; $i < 300; $i++) $this->completion($t, '2026-10-19', $i);

        $csv = $this->get(self::BASE.'/report/csv?module=tasks&from=2026-10-19&to=2026-10-20')->assertOk()->streamedContent();
        $this->assertSame(301, count(array_filter(explode("\n", trim(substr($csv, 3))))), '300 lignes + l’en-tête : jamais tronqué');

        $capped = ReportBuilder::between('2026-10-19', '2026-10-20')->capRows(ReportBuilder::PDF_ROW_CAP)->build(true)['tasks'];
        $this->assertSame(250, count($capped['rows']));
        $this->assertSame(300, $capped['rows_total']);
        $this->assertSame(300, $capped['count'], 'les compteurs portent sur la période entière, pas sur l’échantillon');
        $this->assertSame(Carbon::parse('2026-10-19 08:04:59', config('app.timezone'))->toIso8601String(), end($capped['rows'])['at'], 'ce sont les PLUS RÉCENTES qui sont gardées');
        $this->get(self::BASE.'/report/pdf?from=2026-10-19&to=2026-10-20')->assertOk();
    }

    public function test_dlc_counters_are_computed_on_the_whole_period(): void
    {
        $this->staff();
        $rows = [];
        for ($i = 0; $i < 3050; $i++) {
            $rows[] = ['branch_id' => 1, 'kind' => 'dlc', 'status' => 'printed', 'copies' => 1, 'product_name' => 'P', 'opened_at' => '2026-10-19 08:00:00', 'expires_at' => $i < 5 ? '2026-10-19 12:00:00' : '2026-10-25 12:00:00', 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 500) as $chunk) \DB::table('label_print_logs')->insert($chunk);
        $dlc = $this->getJson(self::BASE.'/report/summary?from=2026-10-19&to=2026-10-20')->json('data.dlc');
        $this->assertSame(3050, $dlc['count']);
        $this->assertSame(5, $dlc['expired_not_out']);
    }

    public function test_renommer_un_equipement_masque_ne_deplace_pas_sa_date_de_masquage_dans_le_dossier(): void
    {
        $this->staff();
        $f = $this->fridge();
        $f->forceFill(['created_at' => Carbon::parse('2026-10-01')])->save();
        // masqué le 10/10
        Carbon::setTestNow(Carbon::parse('2026-10-10 12:00:00', config('app.timezone')));
        $this->putJson(self::TEMP."/equipment/{$f->id}", ['is_active' => false])->assertOk();
        // renommé le 18/10 : le masquage reste daté du 10/10 (sinon 8 jours « non faits » inventés dans le dossier)
        Carbon::setTestNow(Carbon::parse('2026-10-18 09:00:00', config('app.timezone')));
        $before = $this->getJson(self::BASE.'/report/summary?from=2026-10-01&to=2026-10-18')->json('data.temperature.expected');
        $this->putJson(self::TEMP."/equipment/{$f->id}", ['name' => 'Frigo renommé'])->assertOk();
        $this->assertSame('2026-10-10', $f->fresh()->updated_at->toDateString(), 'renommer un élément masqué ne touche pas updated_at');
        $after = $this->getJson(self::BASE.'/report/summary?from=2026-10-01&to=2026-10-18')->json('data.temperature.expected');
        $this->assertSame($before, $after, 'le dossier ne crée aucun jour manqué fantôme');
        // le réactiver puis le masquer de nouveau DATE bien le nouveau masquage
        Carbon::setTestNow(Carbon::parse('2026-10-19 09:00:00', config('app.timezone')));
        $this->putJson(self::TEMP."/equipment/{$f->id}", ['is_active' => true])->assertOk();
        $this->assertSame('2026-10-19', $f->fresh()->updated_at->toDateString());
        Carbon::setTestNow(Carbon::parse('2026-10-20 09:00:00', config('app.timezone')));
        $this->putJson(self::TEMP."/equipment/{$f->id}", ['is_active' => false])->assertOk();
        $this->assertSame('2026-10-20', $f->fresh()->updated_at->toDateString());
    }

    public function test_renommer_une_fiche_masquee_ne_cree_pas_de_non_faites_fantomes(): void
    {
        $this->staff();
        $t = new \App\Models\HygieneTaskTemplate(['name' => 'Salle', 'checklist' => [['key' => 'k1', 'label' => 'Sols']], 'frequency' => 'daily', 'sort_order' => 10, 'is_active' => true]);
        $t->branch_id = 1;
        $t->created_at = Carbon::parse('2026-10-01');
        $t->save();
        Carbon::setTestNow(Carbon::parse('2026-10-10 12:00:00', config('app.timezone')));
        $this->patchJson(self::BASE.'/tasks/templates/'.$t->id, ['is_active' => false])->assertOk();
        $maskedAt = $t->fresh()->updated_at;
        Carbon::setTestNow(Carbon::parse('2026-10-18 09:00:00', config('app.timezone')));
        $this->patchJson(self::BASE.'/tasks/templates/'.$t->id, ['name' => 'Salle renommée'])->assertOk();
        $this->assertSame($maskedAt->toDateTimeString(), $t->fresh()->updated_at->toDateTimeString());
        // une fiche ACTIVE renommée, elle, est bien horodatée (la règle ne vaut que pour un élément resté masqué)
        $g = new \App\Models\HygieneTaskTemplate(['name' => 'Cuisine', 'checklist' => [['key' => 'k1', 'label' => 'Sols']], 'frequency' => 'daily', 'sort_order' => 20, 'is_active' => true]);
        $g->branch_id = 1;
        $g->save();
        Carbon::setTestNow(Carbon::parse('2026-10-18 10:00:00', config('app.timezone')));
        $this->patchJson(self::BASE.'/tasks/templates/'.$g->id, ['name' => 'Cuisine renommée'])->assertOk();
        $this->assertSame('2026-10-18 10:00:00', $g->fresh()->updated_at->toDateTimeString());
    }

    // [VG-2026-10-21 M8-102] supprimer un élément DÉJÀ masqué ne repousse pas sa fin : le masquage reste la date de fin dans le dossier
    public function test_supprimer_une_fiche_masquee_ne_cree_pas_de_non_faites_fantomes(): void
    {
        $this->staff(); // un caissier peut supprimer la fiche (« Modifier » puis « Supprimer la fiche ») : chemin réel du constat
        $t = new HygieneTaskTemplate(['name' => 'Salle', 'checklist' => [['key' => 'k1', 'label' => 'Sols']], 'frequency' => 'daily', 'sort_order' => 10, 'is_active' => true]);
        $t->branch_id = 1;
        $t->created_at = Carbon::parse('2026-10-01 08:00:00', config('app.timezone'));
        $t->save();
        // masquée le 05/10 sans aucune validation : 02, 03, 04/10 non faites (le 01 = création, le 05 = retrait, jamais « manqués »)
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00', config('app.timezone')));
        $this->patchJson(self::BASE.'/tasks/templates/'.$t->id, ['is_active' => false])->assertOk();
        Carbon::setTestNow(Carbon::parse('2026-10-22 09:00:00', config('app.timezone')));
        $url = self::BASE.'/report/summary?from=2026-10-01&to=2026-10-21';
        $before = $this->getJson($url)->json('data.tasks');
        $this->assertSame(3, $before['missed']);
        // supprimée dix-sept jours plus tard
        $this->deleteJson(self::BASE.'/tasks/templates/'.$t->id)->assertOk();
        $this->assertNotNull(HygieneTaskTemplate::withTrashed()->find($t->id)->deleted_at);
        $after = $this->getJson($url)->json('data.tasks');
        $this->assertSame(3, $after['missed'], 'la suppression d\'une fiche masquée n\'invente aucun jour « non fait » après le masquage');
        $this->assertSame(['2026-10-02', '2026-10-03', '2026-10-04'], array_column($after['missed_list'], 'date'));
    }

    // [VG-2026-10-21 M8-102] même règle pour un équipement (frigo) masqué puis supprimé
    public function test_supprimer_un_equipement_masque_ne_cree_pas_de_jours_attendus_fantomes(): void
    {
        $this->staff();
        $f = $this->fridge();
        $f->forceFill(['created_at' => Carbon::parse('2026-10-01 08:00:00', config('app.timezone'))])->save();
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00', config('app.timezone')));
        $this->putJson(self::TEMP."/equipment/{$f->id}", ['is_active' => false])->assertOk();
        Carbon::setTestNow(Carbon::parse('2026-10-22 09:00:00', config('app.timezone')));
        $url = self::BASE.'/report/summary?from=2026-10-01&to=2026-10-21';
        $before = $this->getJson($url)->json('data.temperature.expected');
        $this->assertSame(3, $before);
        $this->deleteJson(self::TEMP."/equipment/{$f->id}")->assertOk();
        $this->assertSame($before, $this->getJson($url)->json('data.temperature.expected'), 'aucun jour attendu inventé après le masquage');
    }

    public function test_hiding_an_equipment_does_not_erase_its_past_gaps_from_the_report(): void
    {
        $this->staff();
        $f = $this->fridge();
        $f->forceFill(['created_at' => Carbon::parse('2026-10-10')])->save();
        // masqué le 15/10 sans un seul relevé depuis le 10/10
        Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:00', config('app.timezone')));
        $this->putJson(self::TEMP."/equipment/{$f->id}", ['is_active' => false])->assertOk();
        Carbon::setTestNow(Carbon::parse('2026-10-20 10:00:00', config('app.timezone')));
        $t = $this->getJson(self::BASE.'/report/summary?from=2026-10-10&to=2026-10-20')->json('data.temperature');
        // 11 à 14 : les jours manqués AVANT le masquage restent attendus. Le 10 (création) et le 15 (masquage) sont des journées partielles dont l'heure est
        // inconnue : comptées seulement si couvertes, jamais « manquées » (un frigo créé puis retiré le même jour ne salit pas un dossier d'inspection).
        $this->assertSame(4, $t['expected'], '11 à 14 : les jours manqués avant le masquage restent attendus');
        $this->assertSame(0, $t['covered']);
        $this->assertSame(0, $t['equipment'], 'plus actif');
        // une période entièrement APRÈS le masquage n'attend plus rien de lui
        $this->assertSame(0, $this->getJson(self::BASE.'/report/summary?from=2026-10-17&to=2026-10-20')->json('data.temperature.expected'));
    }

    public function test_summary_without_a_period_defaults_to_the_current_month_on_the_server_clock(): void
    {
        $this->staff();
        $r = $this->getJson(self::BASE.'/report/summary')->assertOk();
        $this->assertSame('2026-10-01', $r->json('data.period.from'));
        $this->assertSame('2026-10-20', $r->json('data.period.to'));
        $this->assertSame('Europe/Paris', $r->json('meta.timezone'));
    }
}
