<?php

namespace Tests\Feature\Hygiene;

use App\Models\Branch;
use App\Models\HygieneEquipment;
use App\Models\HygieneTemperatureReading;
use App\Models\User;
use App\Services\Hygiene\TemperatureRules;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * [CAYENNE HYGIÈNE 2026-10-17] Relevés de température : quatre frigos positifs, deux congélateurs négatifs, saisie du jour en
 * un geste, verdict calculé par le serveur, journal append-only avec contexte copié.
 */
class TemperatureControllerTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/admin/hygiene/temperature';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Branch::factory()->create(['id' => 1]);
        Branch::factory()->create(['id' => 2]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function staff(int $branch = 1, array $perms = ['pos-orders']): User
    {
        $user = User::factory()->create(['branch_id' => $branch, 'name' => 'Karim Test']);
        $user->givePermissionTo($perms);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    /** 4 frigos + 2 congélateurs, comme chez le propriétaire. */
    private function setupSix(): array
    {
        $this->postJson(self::BASE.'/equipment/quick-setup', ['fridge' => 4, 'freezer' => 2])->assertCreated();

        return HygieneEquipment::query()->orderBy('sort_order')->get()->all();
    }

    private function readings(array $byName): array
    {
        $ids = HygieneEquipment::query()->pluck('id', 'name');

        return ['readings' => collect($byName)->map(fn ($v, $name) => (is_array($v) ? $v : ['value' => $v]) + ['equipment_id' => $ids[$name]])->values()->all()];
    }

    // ───────────── accès ─────────────

    public function test_sans_authentification_ni_permission_tout_est_refuse(): void
    {
        $this->getJson(self::BASE.'/today')->assertUnauthorized();
        $this->staff(1, []);
        $this->getJson(self::BASE.'/today')->assertForbidden();
        $this->postJson(self::BASE.'/readings', [])->assertForbidden();
    }

    public function test_les_trois_permissions_du_personnel_ouvrent_l_ecran(): void
    {
        foreach (['pos-orders', 'pos', 'kitchen-display-system'] as $perm) {
            $this->staff(1, [$perm]);
            $this->getJson(self::BASE.'/today')->assertOk();
        }
    }

    // ───────────── équipements ─────────────

    public function test_demarrage_en_un_geste_quatre_frigos_et_deux_congelateurs_avec_leurs_seuils(): void
    {
        $this->staff();
        $this->setupSix();
        $list = $this->getJson(self::BASE.'/equipment')->assertOk();
        $this->assertSame(['Frigo 1', 'Frigo 2', 'Frigo 3', 'Frigo 4', 'Congélateur 1', 'Congélateur 2'], array_column($list->json('data'), 'name'));
        $fridge = $list->json('data.0');
        $this->assertSame('fridge', $fridge['kind']);
        $this->assertEquals(0.0, $fridge['min_c']);
        $this->assertEquals(4.0, $fridge['max_c']);
        $this->assertFalse($fridge['negative']);
        $freezer = $list->json('data.4');
        $this->assertSame('freezer', $freezer['kind']);
        $this->assertNull($freezer['min_c']);
        $this->assertEquals(-18.0, $freezer['max_c']);
        $this->assertTrue($freezer['negative']);
        $this->assertNotEmpty($list->json('meta.kinds'));
    }

    public function test_le_demarrage_est_idempotent_et_complete_sans_doublon(): void
    {
        $this->staff();
        $this->setupSix();
        $this->postJson(self::BASE.'/equipment/quick-setup', ['fridge' => 4, 'freezer' => 2])->assertOk()->assertJsonPath('data.added', 0);
        $this->postJson(self::BASE.'/equipment/quick-setup', ['fridge' => 5, 'freezer' => 2])->assertCreated()->assertJsonPath('data.added', 1);
        $this->assertSame(7, HygieneEquipment::query()->count());
        $this->assertSame(1, HygieneEquipment::query()->where('name', 'Frigo 5')->count());
    }

    public function test_un_seul_equipement_d_un_type_n_est_pas_numerote(): void
    {
        $this->staff();
        $this->postJson(self::BASE.'/equipment/quick-setup', ['cold_room' => 1])->assertCreated();
        $this->assertSame(['Chambre froide'], HygieneEquipment::query()->pluck('name')->all());
    }

    public function test_creer_modifier_supprimer_un_equipement_avec_les_seuils_du_type_par_defaut(): void
    {
        $this->staff();
        $r = $this->postJson(self::BASE.'/equipment', ['name' => 'Saladette', 'kind' => 'display', 'location' => 'Comptoir'])->assertCreated();
        $id = $r->json('data.id');
        $this->assertEquals(4.0, $r->json('data.max_c'));
        $this->patchJson(self::BASE."/equipment/$id", ['max_c' => 5])->assertOk()->assertJsonPath('data.max_c', 5);
        $this->postJson(self::BASE.'/equipment', ['name' => 'saladette', 'kind' => 'fridge'])->assertStatus(422)->assertJsonPath('errors.name.0', 'Un équipement porte déjà ce nom.');
        $this->patchJson(self::BASE."/equipment/$id", ['min_c' => 9, 'max_c' => 2])->assertStatus(422);
        $this->postJson(self::BASE.'/equipment', ['name' => 'X', 'kind' => 'oven'])->assertStatus(422);
        $this->deleteJson(self::BASE."/equipment/$id")->assertOk();
        $this->assertSame(0, count($this->getJson(self::BASE.'/equipment')->json('data')));
    }

    // ───────────── la règle ─────────────

    public function test_les_seuils_aux_bornes(): void
    {
        // frigo 0 à +4 : bornes incluses
        $this->assertSame('ok', TemperatureRules::evaluate(4.0, 0.0, 4.0)['status']);
        $this->assertSame('ok', TemperatureRules::evaluate(0.0, 0.0, 4.0)['status']);
        $this->assertSame(['status' => 'alert', 'reason' => 'too_warm'], TemperatureRules::evaluate(4.1, 0.0, 4.0));
        $this->assertSame(['status' => 'alert', 'reason' => 'too_cold'], TemperatureRules::evaluate(-0.5, 0.0, 4.0));
        // congélateur −18 ou moins, sans borne basse
        $this->assertSame('ok', TemperatureRules::evaluate(-18.0, null, -18.0)['status']);
        $this->assertSame('ok', TemperatureRules::evaluate(-30.0, null, -18.0)['status']);
        $this->assertSame(['status' => 'alert', 'reason' => 'too_warm'], TemperatureRules::evaluate(-17.9, null, -18.0));
        // 4.04 s'écrit 4,0 sur le thermomètre : conforme
        $this->assertSame('ok', TemperatureRules::evaluate(4.04, 0.0, 4.0)['status']);
    }

    public function test_matin_avant_15_heures_soir_apres(): void
    {
        $this->assertSame('morning', TemperatureRules::periodFor(Carbon::parse('2026-10-17 09:30', 'Europe/Paris')));
        $this->assertSame('morning', TemperatureRules::periodFor(Carbon::parse('2026-10-17 14:59', 'Europe/Paris')));
        $this->assertSame('evening', TemperatureRules::periodFor(Carbon::parse('2026-10-17 15:00', 'Europe/Paris')));
    }

    // ───────────── saisie du jour ─────────────

    public function test_la_saisie_du_proprietaire_quatre_frigos_a_3_deux_congelateurs_a_moins_18_en_un_envoi(): void
    {
        $this->staff();
        $this->setupSix();
        Carbon::setTestNow(Carbon::parse('2026-10-17 08:15', 'Europe/Paris'));

        $today = $this->getJson(self::BASE.'/today')->assertOk();
        $this->assertSame(6, $today->json('meta.summary.todo'));
        $this->assertSame('morning', $today->json('meta.period'));

        $res = $this->postJson(self::BASE.'/readings', $this->readings([
            'Frigo 1' => 3, 'Frigo 2' => 3.5, 'Frigo 3' => 2, 'Frigo 4' => 3, 'Congélateur 1' => -18, 'Congélateur 2' => -20,
        ]))->assertCreated();
        $this->assertSame(0, $res->json('meta.alerts'));
        $this->assertCount(6, $res->json('data'));
        $this->assertSame(6, HygieneTemperatureReading::query()->where('status', 'ok')->count());

        $row = HygieneTemperatureReading::query()->where('equipment_name', 'Frigo 2')->first();
        $this->assertEquals(3.5, $row->value_c);
        $this->assertSame('morning', $row->period);
        $this->assertSame('Karim Test', $row->operator_name);
        $this->assertSame('manual', $row->source);
        $this->assertSame('Europe/Paris', $row->timezone);
        $this->assertSame('fridge', $row->equipment_kind);
        $this->assertEquals(4.0, $row->max_c);

        $after = $this->getJson(self::BASE.'/today')->assertOk();
        $this->assertSame(0, $after->json('meta.summary.todo'));
        $this->assertSame(6, $after->json('meta.summary.ok'));
        $this->assertSame('ok', $after->json('data.0.state'));
        $this->assertEquals(3.0, $after->json('data.0.last_today.value_c'));
        $this->assertTrue($after->json('data.0.done_this_period'));
    }

    public function test_un_ecart_sans_action_corrective_refuse_tout_le_lot(): void
    {
        $this->staff();
        $this->setupSix();
        $res = $this->postJson(self::BASE.'/readings', $this->readings(['Frigo 1' => 3, 'Frigo 2' => 7, 'Congélateur 1' => -18]))->assertStatus(422);
        $this->assertSame(['readings.1.corrective_action'], array_keys($res->json('errors')));
        $this->assertStringContainsString('Frigo 2', $res->json('errors')['readings.1.corrective_action'][0]);
        $this->assertSame(0, HygieneTemperatureReading::query()->count(), 'tout ou rien');
    }

    public function test_un_ecart_avec_action_est_enregistre_comme_alerte_avec_son_contexte(): void
    {
        $this->staff();
        $this->setupSix();
        $res = $this->postJson(self::BASE.'/readings', $this->readings([
            'Frigo 1' => 3,
            'Frigo 2' => ['value' => 7.5, 'corrective_action' => 'products_checked', 'corrective_note' => 'porte restée ouverte'],
            'Congélateur 1' => ['value' => -12, 'corrective_action' => 'technician'],
        ]))->assertCreated();
        $this->assertSame(2, $res->json('meta.alerts'));
        $a = HygieneTemperatureReading::query()->where('equipment_name', 'Frigo 2')->first();
        $this->assertSame('alert', $a->status);
        $this->assertSame('too_warm', $a->alert_reason);
        $this->assertSame('products_checked', $a->corrective_action);
        $this->assertSame('porte restée ouverte', $a->corrective_note);
        $this->assertSame('too_warm', HygieneTemperatureReading::query()->where('equipment_name', 'Congélateur 1')->value('alert_reason'));

        $today = $this->getJson(self::BASE.'/today')->json();
        $this->assertSame(2, $today['meta']['summary']['alert']);
        $this->assertSame(1, $today['meta']['summary']['ok']);
        $this->assertSame(3, $today['meta']['summary']['todo']);
        $this->assertSame('Produits contrôlés (température à cœur)', collect($today['data'])->firstWhere('name', 'Frigo 2')['last_today']['corrective_label']);
    }

    public function test_l_action_autre_exige_un_commentaire_et_une_action_inconnue_est_refusee(): void
    {
        $this->staff();
        $this->postJson(self::BASE.'/equipment/quick-setup', ['fridge' => 1])->assertCreated();
        $id = HygieneEquipment::query()->value('id');
        $this->postJson(self::BASE.'/readings', ['readings' => [['equipment_id' => $id, 'value' => 9, 'corrective_action' => 'other']]])
            ->assertStatus(422)->assertJsonPath('errors', ['readings.0.corrective_note' => ['« Frigo » : précisez l’action corrective.']]);
        $this->postJson(self::BASE.'/readings', ['readings' => [['equipment_id' => $id, 'value' => 9, 'corrective_action' => 'magie']]])->assertStatus(422);
        $this->postJson(self::BASE.'/readings', ['readings' => [['equipment_id' => $id, 'value' => 9, 'corrective_action' => 'other', 'corrective_note' => 'compresseur bruyant']]])->assertCreated();
    }

    public function test_un_releve_conforme_ne_garde_ni_action_ni_commentaire(): void
    {
        $this->staff();
        $this->postJson(self::BASE.'/equipment/quick-setup', ['fridge' => 1])->assertCreated();
        $id = HygieneEquipment::query()->value('id');
        $this->postJson(self::BASE.'/readings', ['readings' => [['equipment_id' => $id, 'value' => 3, 'corrective_action' => 'defrost', 'corrective_note' => 'x']]])->assertCreated();
        $row = HygieneTemperatureReading::query()->first();
        $this->assertNull($row->corrective_action);
        $this->assertNull($row->corrective_note);
    }

    public function test_une_valeur_absurde_est_refusee_comme_faute_de_frappe(): void
    {
        $this->staff();
        $this->postJson(self::BASE.'/equipment/quick-setup', ['fridge' => 1])->assertCreated();
        $id = HygieneEquipment::query()->value('id');
        $res = $this->postJson(self::BASE.'/readings', ['readings' => [['equipment_id' => $id, 'value' => 300]]])->assertStatus(422);
        $this->assertStringContainsString('faute de frappe', $res->json('errors')['readings.0.value'][0]);
        $this->postJson(self::BASE.'/readings', ['readings' => [['equipment_id' => $id, 'value' => 'abc']]])->assertStatus(422);
        $this->assertSame(0, HygieneTemperatureReading::query()->count());
    }

    public function test_un_equipement_inconnu_desactive_ou_en_double_est_refuse(): void
    {
        $this->staff();
        $this->postJson(self::BASE.'/equipment/quick-setup', ['fridge' => 2])->assertCreated();
        $ids = HygieneEquipment::query()->pluck('id', 'name');
        $this->postJson(self::BASE.'/readings', ['readings' => [['equipment_id' => 9999, 'value' => 3]]])->assertStatus(422);
        $this->postJson(self::BASE.'/readings', ['readings' => [['equipment_id' => $ids['Frigo 1'], 'value' => 3], ['equipment_id' => $ids['Frigo 1'], 'value' => 3]]])->assertStatus(422);
        $this->patchJson(self::BASE.'/equipment/'.$ids['Frigo 2'], ['is_active' => false])->assertOk();
        $this->postJson(self::BASE.'/readings', ['readings' => [['equipment_id' => $ids['Frigo 2'], 'value' => 3]]])->assertStatus(422);
    }

    public function test_un_envoi_rejoue_apres_une_coupure_ne_double_jamais_un_releve(): void
    {
        $this->staff();
        $this->postJson(self::BASE.'/equipment/quick-setup', ['fridge' => 1])->assertCreated();
        $id = HygieneEquipment::query()->value('id');
        $body = ['readings' => [['equipment_id' => $id, 'value' => 3, 'client_ref' => 't-abc-1']]];
        $first = $this->postJson(self::BASE.'/readings', $body)->assertCreated();
        $second = $this->postJson(self::BASE.'/readings', $body)->assertCreated();
        $this->assertSame($first->json('data.0.id'), $second->json('data.0.id'));
        $this->assertSame(1, HygieneTemperatureReading::query()->count());
    }

    public function test_renommer_ou_changer_un_seuil_ne_reecrit_pas_l_historique(): void
    {
        $this->staff();
        $this->postJson(self::BASE.'/equipment/quick-setup', ['fridge' => 1])->assertCreated();
        $id = HygieneEquipment::query()->value('id');
        $this->postJson(self::BASE.'/readings', ['readings' => [['equipment_id' => $id, 'value' => 3]]])->assertCreated();
        $this->patchJson(self::BASE."/equipment/$id", ['name' => 'Frigo cuisine', 'max_c' => 8])->assertOk();
        $row = HygieneTemperatureReading::query()->first();
        $this->assertSame('Frigo', $row->equipment_name);
        $this->assertEquals(4.0, $row->max_c);
    }

    // ───────────── annulation et journal ─────────────

    public function test_annuler_un_releve_exige_un_motif_et_le_garde_au_journal(): void
    {
        $this->staff();
        $this->postJson(self::BASE.'/equipment/quick-setup', ['fridge' => 1])->assertCreated();
        $id = HygieneEquipment::query()->value('id');
        // relevé saisi par erreur (3,3 au lieu de 2,3) puis annulé
        $rid = $this->postJson(self::BASE.'/readings', ['readings' => [['equipment_id' => $id, 'value' => 3.3]]])->assertCreated()->json('data.0.id');

        $this->postJson(self::BASE."/readings/$rid/void", [])->assertStatus(422);
        $this->postJson(self::BASE."/readings/$rid/void", ['reason' => 'faute de frappe'])->assertOk()->assertJsonPath('data.voided', true);

        $this->assertSame(1, HygieneTemperatureReading::query()->count(), 'jamais supprimé');
        $this->assertSame('todo', $this->getJson(self::BASE.'/today')->json('data.0.state'), 'plus compté aujourd’hui');
        $hist = $this->getJson(self::BASE.'/history')->assertOk();
        $this->assertTrue($hist->json('data.0.voided'));
        $this->assertSame('faute de frappe', $hist->json('data.0.void_reason'));
    }

    public function test_le_journal_filtre_par_periode_equipement_et_ecarts(): void
    {
        $this->staff();
        $this->setupSix();
        $ids = HygieneEquipment::query()->pluck('id', 'name');
        Carbon::setTestNow(Carbon::parse('2026-10-10 08:00', 'Europe/Paris'));
        $this->postJson(self::BASE.'/readings', $this->readings(['Frigo 1' => 3, 'Frigo 2' => ['value' => 6, 'corrective_action' => 'door_closed']]))->assertCreated();
        Carbon::setTestNow(Carbon::parse('2026-10-17 08:00', 'Europe/Paris'));
        $this->postJson(self::BASE.'/readings', $this->readings(['Frigo 1' => 2]))->assertCreated();

        $all = $this->getJson(self::BASE.'/history?from=2026-10-01&to=2026-10-17')->json('data');
        $this->assertCount(3, $all);
        $this->assertSame('2026-10-17', substr($all[0]['recorded_at'], 0, 10), 'le plus récent d’abord');
        $this->assertCount(1, $this->getJson(self::BASE.'/history?from=2026-10-12&to=2026-10-17')->json('data'));
        $this->assertCount(2, $this->getJson(self::BASE.'/history?from=2026-10-01&to=2026-10-17&equipment_id='.$ids['Frigo 1'])->json('data'));
        $alerts = $this->getJson(self::BASE.'/history?from=2026-10-01&to=2026-10-17&alerts_only=1')->json('data');
        $this->assertCount(1, $alerts);
        $this->assertSame('Frigo 2', $alerts[0]['equipment_name']);
    }

    public function test_hier_figure_a_cote_de_la_valeur_du_jour(): void
    {
        $this->staff();
        $this->postJson(self::BASE.'/equipment/quick-setup', ['fridge' => 1])->assertCreated();
        $id = HygieneEquipment::query()->value('id');
        Carbon::setTestNow(Carbon::parse('2026-10-16 18:00', 'Europe/Paris'));
        $this->postJson(self::BASE.'/readings', ['readings' => [['equipment_id' => $id, 'value' => 2.5]]])->assertCreated();
        Carbon::setTestNow(Carbon::parse('2026-10-17 08:00', 'Europe/Paris'));
        $today = $this->getJson(self::BASE.'/today')->json('data.0');
        $this->assertSame('todo', $today['state']);
        $this->assertEquals(2.5, $today['previous']['value_c']);
    }

    public function test_chaque_branche_ne_voit_que_ses_equipements_et_ses_releves(): void
    {
        $this->staff(1);
        $this->postJson(self::BASE.'/equipment/quick-setup', ['fridge' => 1])->assertCreated();
        $id = HygieneEquipment::query()->value('id');
        $this->postJson(self::BASE.'/readings', ['readings' => [['equipment_id' => $id, 'value' => 3]]])->assertCreated();

        $this->staff(2);
        $this->assertSame([], $this->getJson(self::BASE.'/equipment')->json('data'));
        $this->assertSame([], $this->getJson(self::BASE.'/history')->json('data'));
        $this->postJson(self::BASE.'/readings', ['readings' => [['equipment_id' => $id, 'value' => 3]]])->assertStatus(422);
        $this->patchJson(self::BASE."/equipment/$id", ['name' => 'Pirate'])->assertNotFound();
    }
}
