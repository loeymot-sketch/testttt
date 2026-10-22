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

    /**
     * [VG-2026-10-21 M8-104] Deux rejeux SIMULTANÉS du même relevé (deux onglets qui vident la même file) : celui qui perd la course d'insertion
     * recevait 404 « Commande introuvable » alors que le relevé était enregistré. Sous MySQL (REPEATABLE READ), la relecture faite DANS la
     * transaction lit l'instantané pris avant l'insertion concurrente : elle ne voit pas la ligne. SQLite n'a pas cet instantané ; on l'émule :
     * dans la transaction, la ligne insérée « par l'autre requête » est invisible — exactement ce que voit InnoDB.
     */
    public function test_un_rejeu_concurrent_du_meme_releve_repond_succes_avec_la_ligne_existante(): void
    {
        $this->staff();
        $this->postJson(self::BASE.'/equipment/quick-setup', ['fridge' => 1])->assertCreated();
        $id = HygieneEquipment::query()->value('id');
        $base = \Illuminate\Support\Facades\DB::transactionLevel(); // RefreshDatabase ouvre déjà une transaction
        $otherId = null;
        $active = true;
        HygieneTemperatureReading::addGlobalScope('vg_m8_104_snapshot', function ($q) use (&$otherId, &$active, $base): void {
            if ($active && $otherId !== null && \Illuminate\Support\Facades\DB::transactionLevel() > $base) $q->whereKeyNot($otherId);
        });
        // l'autre requête insère la même référence juste avant nous (entre notre lecture « déjà enregistré ? » et notre insertion)
        HygieneTemperatureReading::creating(function (HygieneTemperatureReading $row) use (&$otherId, &$active): void {
            if (! $active || $otherId !== null) return;
            $other = $row->replicate();
            $other->saveQuietly();
            $otherId = $other->id;
        });
        try {
            $r = $this->postJson(self::BASE.'/readings', ['readings' => [['equipment_id' => $id, 'value' => 3, 'client_ref' => 'race-1']]]);
            $r->assertCreated(); // succès, comme tout rejeu idempotent (jamais un 404 sur un relevé enregistré)
            $this->assertSame($otherId, $r->json('data.0.id'), 'la réponse rend la ligne déjà enregistrée');
            $this->assertSame(1, HygieneTemperatureReading::query()->withoutGlobalScope('vg_m8_104_snapshot')->where('client_ref', 'race-1')->count());
        } finally {
            $active = false; // la portée globale est statique : la rendre inerte pour les tests suivants
        }
    }

    /** [VG-2026-10-21 M8-101] la tablette ne dit « enregistré » que si SA référence d'envoi revient : la réponse la rend, ligne par ligne. */
    public function test_la_reponse_d_un_envoi_rend_la_reference_de_chaque_releve(): void
    {
        $this->staff();
        $this->postJson(self::BASE.'/equipment/quick-setup', ['fridge' => 2])->assertCreated();
        $ids = HygieneEquipment::query()->orderBy('sort_order')->pluck('id')->all();
        $r = $this->postJson(self::BASE.'/readings', ['readings' => [
            ['equipment_id' => $ids[0], 'value' => 3, 'client_ref' => 'ref-a'],
            ['equipment_id' => $ids[1], 'value' => 2, 'client_ref' => 'ref-b'],
        ]])->assertCreated();
        $this->assertSame(['ref-a', 'ref-b'], array_column($r->json('data'), 'client_ref'));
        $this->assertNotEmpty($r->json('meta.server_now'));
        // le rejeu rend la MÊME ligne avec la même référence
        $again = $this->postJson(self::BASE.'/readings', ['readings' => [['equipment_id' => $ids[0], 'value' => 3, 'client_ref' => 'ref-a']]])->assertCreated();
        $this->assertSame('ref-a', $again->json('data.0.client_ref'));
        $this->assertSame($r->json('data.0.id'), $again->json('data.0.id'));
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

    // ───────────── équipements chauds · bain-marie (2026-10-17 · méga-fichier P1.1) ─────────────

    public function test_un_bain_marie_se_cree_avec_63_degres_minimum_et_sans_seuil_haut(): void
    {
        $this->staff();
        $this->postJson(self::BASE.'/equipment/quick-setup', ['fridge' => 1, 'hot_holding' => 2])->assertCreated();
        $names = HygieneEquipment::query()->orderBy('sort_order')->pluck('name')->all();
        $this->assertSame(['Frigo', 'Bain-marie 1', 'Bain-marie 2'], $names);
        $bm = HygieneEquipment::query()->where('name', 'Bain-marie 1')->firstOrFail();
        $this->assertSame('hot_holding', $bm->kind);
        $this->assertEquals(63.0, (float) $bm->min_c);
        $this->assertNull($bm->max_c);
        $row = collect($this->getJson(self::BASE.'/today')->json('data'))->firstWhere('name', 'Bain-marie 1');
        $this->assertTrue($row['hot']);
        $this->assertSame('Bain-marie / maintien au chaud', $row['kind_label']);
        // le frigo, lui, n'est pas « chaud »
        $this->assertFalse(collect($this->getJson(self::BASE.'/today')->json('data'))->firstWhere('name', 'Frigo')['hot']);
    }

    public function test_un_plat_sous_63_degres_est_un_ecart_et_exige_une_action_adaptee_au_chaud(): void
    {
        $this->staff();
        $this->postJson(self::BASE.'/equipment/quick-setup', ['hot_holding' => 1])->assertCreated();
        // 71 °C : conforme ; 63,0 : conforme (borne incluse) ; 62,9 : écart
        $this->postJson(self::BASE.'/readings', $this->readings(['Bain-marie' => 71]))->assertCreated();
        $this->postJson(self::BASE.'/readings', $this->readings(['Bain-marie' => 63.0]))->assertCreated();
        $this->assertSame(['ok', 'ok'], HygieneTemperatureReading::query()->orderBy('id')->pluck('status')->all());

        $this->postJson(self::BASE.'/readings', $this->readings(['Bain-marie' => 62.9]))->assertStatus(422);
        $this->postJson(self::BASE.'/readings', $this->readings(['Bain-marie' => ['value' => 62.9, 'corrective_action' => 'reheated']]))->assertCreated();
        $alert = HygieneTemperatureReading::query()->orderByDesc('id')->firstOrFail();
        $this->assertSame('alert', $alert->status);
        $this->assertSame('too_cold', $alert->alert_reason);
        $this->assertSame('reheated', $alert->corrective_action);
        $this->assertEquals(63.0, (float) $alert->min_c);

        // les actions proposées disent à quel type elles servent (l'écran ne propose pas « dégivrage » à un bain-marie)
        $actions = collect($this->getJson(self::BASE.'/today')->json('meta.corrective_actions'))->keyBy('key');
        $this->assertSame('hot', $actions['reheated']['for']);
        $this->assertSame('cold', $actions['defrost']['for']);
        $this->assertArrayNotHasKey('for', $actions['recheck']);
    }

    public function test_une_valeur_chaude_plausible_passe_et_une_faute_de_frappe_est_refusee(): void
    {
        $this->staff();
        $this->postJson(self::BASE.'/equipment/quick-setup', ['hot_holding' => 1, 'fridge' => 1])->assertCreated();
        $this->postJson(self::BASE.'/readings', $this->readings(['Bain-marie' => 95]))->assertCreated();   // 95 °C : un bain-marie bouillant, plausible
        $r = $this->postJson(self::BASE.'/readings', $this->readings(['Bain-marie' => 5]))->assertStatus(422);  // 5 °C : un thermomètre de frigo
        $this->assertStringContainsString('équipement chaud', json_encode($r->json('errors'), JSON_UNESCAPED_UNICODE));
        // et 95 °C reste absurde pour un frigo
        $f = $this->postJson(self::BASE.'/readings', $this->readings(['Frigo' => 95]))->assertStatus(422);
        $this->assertStringContainsString('enceinte froide', json_encode($f->json('errors'), JSON_UNESCAPED_UNICODE));
    }

    public function test_le_seuil_bas_d_un_equipement_chaud_se_regle_dans_des_bornes_et_ne_se_desarme_pas(): void
    {
        $this->staff();
        $id = $this->postJson(self::BASE.'/equipment', ['name' => 'Vitrine chaude', 'kind' => 'hot_holding'])->assertCreated()->json('data.id');
        $this->assertEquals(63.0, $this->getJson(self::BASE.'/equipment')->json('data.0.min_c'));
        // désarmer (vider le seuil bas) : refusé
        $this->putJson(self::BASE.'/equipment/'.$id, ['min_c' => null])->assertStatus(422)->assertJsonValidationErrors('min_c');
        // trop bas (50 °C : ce n'est plus du maintien au chaud) / trop haut (80)
        $this->putJson(self::BASE.'/equipment/'.$id, ['min_c' => 50])->assertStatus(422);
        $this->putJson(self::BASE.'/equipment/'.$id, ['min_c' => 80])->assertStatus(422);
        // un réglage dans les bornes passe ; un seuil haut facultatif ne peut pas être sous le seuil bas
        $this->putJson(self::BASE.'/equipment/'.$id, ['min_c' => 65])->assertOk();
        $this->putJson(self::BASE.'/equipment/'.$id, ['max_c' => 60])->assertStatus(422)->assertJsonValidationErrors('max_c');
        $this->putJson(self::BASE.'/equipment/'.$id, ['max_c' => 90])->assertOk();
    }

    // ───────────── relecture adverse (2026-10-17) ─────────────

    public function test_le_seuil_bas_d_un_equipement_chaud_ne_descend_jamais_sous_63_degres(): void
    {
        $this->staff();
        $id = $this->postJson(self::BASE.'/equipment', ['name' => 'Vitrine chaude', 'kind' => 'hot_holding'])->assertCreated()->json('data.id');
        $this->putJson(self::BASE.'/equipment/'.$id, ['min_c' => 55])->assertStatus(422)->assertJsonValidationErrors('min_c');
        $this->putJson(self::BASE.'/equipment/'.$id, ['min_c' => 62.9])->assertStatus(422);
        $this->putJson(self::BASE.'/equipment/'.$id, ['min_c' => 63])->assertOk();
        $this->putJson(self::BASE.'/equipment/'.$id, ['min_c' => 68])->assertOk(); // plus strict que la loi : permis
    }

    public function test_une_action_corrective_du_chaud_n_est_pas_acceptee_pour_un_frigo_ni_l_inverse(): void
    {
        $this->staff();
        $this->postJson(self::BASE.'/equipment/quick-setup', ['fridge' => 1, 'hot_holding' => 1])->assertCreated();
        $this->postJson(self::BASE.'/readings', $this->readings(['Frigo' => ['value' => 9, 'corrective_action' => 'reheated']]))->assertStatus(422);
        $this->postJson(self::BASE.'/readings', $this->readings(['Bain-marie' => ['value' => 50, 'corrective_action' => 'defrost']]))->assertStatus(422);
        $this->postJson(self::BASE.'/readings', $this->readings(['Frigo' => ['value' => 9, 'corrective_action' => 'defrost'], 'Bain-marie' => ['value' => 50, 'corrective_action' => 'reheated']]))->assertCreated();
    }

    public function test_j_en_ai_n_se_compte_au_total_meme_renomme_et_ne_cree_que_ce_qui_manque(): void
    {
        $this->staff();
        $this->postJson(self::BASE.'/equipment/quick-setup', ['fridge' => 1])->assertCreated();
        HygieneEquipment::query()->where('name', 'Frigo')->update(['name' => 'Frigo burgers']);
        $this->postJson(self::BASE.'/equipment/quick-setup', ['fridge' => 1])->assertOk(); // déjà 1 : rien à créer
        $this->assertSame(1, HygieneEquipment::query()->where('kind', 'fridge')->count());
        $this->postJson(self::BASE.'/equipment/quick-setup', ['fridge' => 3])->assertCreated();
        $this->assertSame(3, HygieneEquipment::query()->where('kind', 'fridge')->count(), '3 au total, pas 1 + 3');
        $this->postJson(self::BASE.'/equipment/quick-setup', ['fridge' => 3])->assertOk();
    }
    // ───────────── « Qui ? » sur une tablette partagée ─────────────

    public function test_le_releve_est_signe_de_la_personne_choisie_pas_du_compte_de_la_tablette(): void
    {
        $this->staff(); // compte connecté de la tablette : « Karim Test »
        $sarah = User::factory()->create(['branch_id' => 1, 'name' => 'Sarah Lopez']);
        $sarah->assignRole('Chef');
        $this->setupSix();

        $this->postJson(self::BASE.'/readings', $this->readings(['Frigo 1' => 3.0]) + ['operator_user_id' => $sarah->id])
            ->assertCreated()->assertJsonPath('data.0.operator_name', 'Sarah Lopez');

        $row = HygieneTemperatureReading::query()->firstOrFail();
        $this->assertSame($sarah->id, $row->user_id);
        $this->assertSame('Sarah Lopez', $row->operator_name);
    }

    public function test_sans_choix_le_releve_reste_signe_du_compte_connecte(): void
    {
        $user = $this->staff();
        $this->setupSix();

        $this->postJson(self::BASE.'/readings', $this->readings(['Frigo 1' => 3.0]))->assertCreated();

        $row = HygieneTemperatureReading::query()->firstOrFail();
        $this->assertSame($user->id, $row->user_id);
        $this->assertSame('Karim Test', $row->operator_name);
    }

    public function test_une_personne_hors_du_personnel_est_refusee_et_rien_n_est_enregistre(): void
    {
        $this->staff();
        $outsider = User::factory()->create(['branch_id' => 2, 'name' => 'Autre Site']);
        $outsider->assignRole('Chef');
        $customer = User::factory()->create(['branch_id' => 1, 'name' => 'Un Client']);
        $customer->assignRole('Customer');
        $this->setupSix();

        foreach ([$outsider->id, $customer->id, 999999] as $bad) {
            $this->postJson(self::BASE.'/readings', $this->readings(['Frigo 1' => 3.0]) + ['operator_user_id' => $bad])
                ->assertStatus(422)->assertJsonValidationErrors('operator_user_id');
        }
        $this->assertSame(0, HygieneTemperatureReading::query()->count());
    }

    // ───────────── relevé saisi hors ligne, envoyé plus tard ─────────────

    public function test_un_releve_saisi_hors_ligne_garde_l_heure_de_la_mesure_pas_celle_de_l_envoi(): void
    {
        $this->staff();
        $this->setupSix();
        Carbon::setTestNow(Carbon::parse('2026-10-20 16:40', config('app.timezone')));

        $r = $this->postJson(self::BASE.'/readings', $this->readings(['Frigo 1' => ['value' => 3.0, 'measured_at' => '2026-10-20T10:12:00+02:00', 'client_ref' => 'off-1']]))
            ->assertCreated();

        $row = HygieneTemperatureReading::query()->firstOrFail();
        $this->assertSame('2026-10-20 10:12', $row->recorded_at->setTimezone(config('app.timezone'))->format('Y-m-d H:i'));
        $this->assertSame('morning', $row->period, 'la période suit l\'heure de la MESURE (matin), pas celle de l\'envoi (soir)');
        $this->assertSame('offline', $row->source);
        $r->assertJsonPath('data.0.offline', true)->assertJsonPath('data.0.synced_at', fn ($v) => is_string($v) && str_contains($v, '2026-10-20T16:40'));
    }

    public function test_un_releve_en_ligne_garde_l_heure_du_serveur_et_la_source_manuelle(): void
    {
        $this->staff();
        $this->setupSix();
        Carbon::setTestNow(Carbon::parse('2026-10-20 10:00', config('app.timezone')));

        $this->postJson(self::BASE.'/readings', $this->readings(['Frigo 1' => 3.0]))->assertCreated()->assertJsonPath('data.0.offline', false);

        $row = HygieneTemperatureReading::query()->firstOrFail();
        $this->assertSame('manual', $row->source);
        $this->assertSame('2026-10-20 10:00', $row->recorded_at->setTimezone(config('app.timezone'))->format('Y-m-d H:i'));
    }

    public function test_une_courte_coupure_est_aussi_dite_hors_ligne(): void
    {
        $this->staff();
        $this->setupSix();
        Carbon::setTestNow(Carbon::parse('2026-10-20 10:00:30', config('app.timezone')));

        // la tablette a gardé le relevé 30 s : l'heure de mesure est envoyée, donc c'est un envoi différé, dit comme tel
        $this->postJson(self::BASE.'/readings', $this->readings(['Frigo 2' => ['value' => 3.0, 'measured_at' => '2026-10-20T10:00:00+02:00']]))
            ->assertCreated()->assertJsonPath('data.0.offline', true);
        $this->assertSame('offline', HygieneTemperatureReading::query()->firstOrFail()->source);
    }

    public function test_une_heure_de_mesure_dans_le_futur_est_refusee_avec_la_raison_et_rien_n_est_enregistre(): void
    {
        $this->staff();
        $this->setupSix();
        Carbon::setTestNow(Carbon::parse('2026-10-20 10:00', config('app.timezone')));

        $this->postJson(self::BASE.'/readings', $this->readings(['Frigo 1' => ['value' => 3.0, 'measured_at' => '2026-10-20T12:00:00+02:00']]))
            ->assertStatus(422)->assertJsonValidationErrors('readings.0.measured_at');
        $this->assertSame(0, HygieneTemperatureReading::query()->count());
    }

    public function test_une_mesure_de_plus_de_72_heures_est_refusee(): void
    {
        $this->staff();
        $this->setupSix();
        Carbon::setTestNow(Carbon::parse('2026-10-20 10:00', config('app.timezone')));

        $this->postJson(self::BASE.'/readings', $this->readings(['Frigo 1' => ['value' => 3.0, 'measured_at' => '2026-10-16T10:00:00+02:00']]))
            ->assertStatus(422)->assertJsonValidationErrors('readings.0.measured_at');
        // juste dans la limite : accepté
        $this->postJson(self::BASE.'/readings', $this->readings(['Frigo 1' => ['value' => 3.0, 'measured_at' => '2026-10-17T10:30:00+02:00']]))->assertCreated();
        $this->assertSame(1, HygieneTemperatureReading::query()->count());
    }

    public function test_le_rejeu_d_un_releve_hors_ligne_ne_double_jamais_le_journal(): void
    {
        $this->staff();
        $this->setupSix();
        Carbon::setTestNow(Carbon::parse('2026-10-20 16:40', config('app.timezone')));
        $body = $this->readings(['Frigo 1' => ['value' => 3.0, 'measured_at' => '2026-10-20T10:12:00+02:00', 'client_ref' => 'off-2']]);

        $this->postJson(self::BASE.'/readings', $body)->assertCreated();
        $this->postJson(self::BASE.'/readings', $body)->assertCreated(); // la tablette ne sait pas si le premier envoi a abouti : elle rejoue

        $this->assertSame(1, HygieneTemperatureReading::query()->count());
    }

    public function test_un_ecart_saisi_hors_ligne_exige_toujours_l_action_corrective(): void
    {
        $this->staff();
        $this->setupSix();
        Carbon::setTestNow(Carbon::parse('2026-10-20 16:40', config('app.timezone')));

        $this->postJson(self::BASE.'/readings', $this->readings(['Frigo 1' => ['value' => 9.0, 'measured_at' => '2026-10-20T10:12:00+02:00']]))
            ->assertStatus(422)->assertJsonValidationErrors('readings.0.corrective_action');
        $this->postJson(self::BASE.'/readings', $this->readings(['Frigo 1' => ['value' => 9.0, 'corrective_action' => 'door_closed', 'measured_at' => '2026-10-20T10:12:00+02:00']]))
            ->assertCreated()->assertJsonPath('data.0.status', 'alert');
    }

    // ───────────── revue adverse : horloge, période, équipement désactivé, rejeu ─────────────

    public function test_la_periode_se_deduit_de_l_heure_du_releve_jamais_du_champ_envoye(): void
    {
        $this->staff();
        $this->setupSix();
        Carbon::setTestNow(Carbon::parse('2026-10-20 10:00', config('app.timezone')));

        $this->postJson(self::BASE.'/readings', $this->readings(['Frigo 1' => ['value' => 3.0, 'period' => 'evening']]))->assertCreated()->assertJsonPath('data.0.period', 'morning');
        $this->assertSame('morning', HygieneTemperatureReading::query()->firstOrFail()->period);
    }

    public function test_une_tablette_en_retard_de_trois_heures_est_remise_a_l_heure_du_serveur(): void
    {
        $this->staff();
        $this->setupSix();
        Carbon::setTestNow(Carbon::parse('2026-10-20 14:00:00', config('app.timezone')));

        // l'horloge de la tablette indique 11:00 à l'envoi ; elle a pris la mesure à 10:55 (heure tablette) = 13:55 réelle
        $body = $this->readings(['Frigo 1' => ['value' => 3.0, 'measured_at' => '2026-10-20T10:55:00+02:00']]) + ['client_now' => '2026-10-20T11:00:00+02:00'];
        $this->postJson(self::BASE.'/readings', $body)->assertCreated();

        $row = HygieneTemperatureReading::query()->firstOrFail();
        $this->assertSame('2026-10-20 13:55', $row->recorded_at->setTimezone(config('app.timezone'))->format('Y-m-d H:i'));
        $this->assertSame('morning', $row->period, 'la période suit l\'heure CORRIGÉE (13 h 55, avant la bascule de 15 h), pas l\'heure brute de la tablette ni celle de l\'envoi (14 h)');
    }

    public function test_sans_client_now_ou_pour_une_derive_de_moins_de_30_secondes_aucune_correction(): void
    {
        $this->staff();
        $this->setupSix();
        Carbon::setTestNow(Carbon::parse('2026-10-20 14:00:00', config('app.timezone')));

        $this->postJson(self::BASE.'/readings', $this->readings(['Frigo 1' => ['value' => 3.0, 'measured_at' => '2026-10-20T13:50:00+02:00']]))->assertCreated();
        $this->postJson(self::BASE.'/readings', $this->readings(['Frigo 2' => ['value' => 3.0, 'measured_at' => '2026-10-20T13:50:00+02:00']]) + ['client_now' => '2026-10-20T13:59:45+02:00'])->assertCreated();

        $this->assertSame(['13:50', '13:50'], HygieneTemperatureReading::query()->orderBy('id')->get()->map(fn ($r) => $r->recorded_at->setTimezone(config('app.timezone'))->format('H:i'))->all());
    }

    public function test_une_tablette_en_avance_dont_la_mesure_corrigee_reste_dans_le_futur_est_refusee(): void
    {
        $this->staff();
        $this->setupSix();
        Carbon::setTestNow(Carbon::parse('2026-10-20 10:00:00', config('app.timezone')));

        // tablette en avance de 2 h (13:00 pour 10:00 réelles), mesure « à 13:00 » = maintenant corrigé : acceptée à 10:00
        $this->postJson(self::BASE.'/readings', $this->readings(['Frigo 1' => ['value' => 3.0, 'measured_at' => '2026-10-20T13:00:00+03:00']]) + ['client_now' => '2026-10-20T13:00:00+03:00'])->assertCreated();
        // la même tablette sans correction (pas de client_now) : l'heure est dans le futur, refusée
        $this->postJson(self::BASE.'/readings', $this->readings(['Frigo 2' => ['value' => 3.0, 'measured_at' => '2026-10-20T13:00:00+02:00']]))->assertStatus(422)->assertJsonValidationErrors('readings.0.measured_at');
    }

    public function test_le_rejeu_d_un_lot_deja_enregistre_n_est_pas_refuse_parce_qu_un_equipement_a_ete_desactive_depuis(): void
    {
        $this->staff();
        $this->setupSix();
        $ids = HygieneEquipment::query()->whereIn('name', ['Frigo 1', 'Frigo 2'])->pluck('id', 'name');
        $body = ['readings' => [
            ['equipment_id' => $ids['Frigo 1'], 'value' => 3.0, 'client_ref' => 'lot-a'],
            ['equipment_id' => $ids['Frigo 2'], 'value' => 2.0, 'client_ref' => 'lot-b'],
        ]];
        $this->postJson(self::BASE.'/readings', $body)->assertCreated();

        HygieneEquipment::query()->where('id', $ids['Frigo 2'])->update(['is_active' => false]);
        $this->postJson(self::BASE.'/readings', $body)->assertCreated(); // la réponse du premier envoi s'était perdue : rejeu reconnu, rien refusé

        $this->assertSame(2, HygieneTemperatureReading::query()->count());
    }

    public function test_un_releve_garde_hors_ligne_reste_valable_si_l_equipement_a_ete_desactive_depuis_mais_pas_un_releve_en_direct(): void
    {
        $this->staff();
        $this->setupSix();
        Carbon::setTestNow(Carbon::parse('2026-10-20 12:00', config('app.timezone')));
        $id = HygieneEquipment::query()->where('name', 'Frigo 1')->value('id');
        HygieneEquipment::query()->where('id', $id)->update(['is_active' => false]);

        $this->postJson(self::BASE.'/readings', ['readings' => [['equipment_id' => $id, 'value' => 3.0]]])->assertStatus(422)->assertJsonValidationErrors('readings.0.equipment_id');
        $this->postJson(self::BASE.'/readings', ['readings' => [['equipment_id' => $id, 'value' => 3.0, 'measured_at' => '2026-10-20T10:00:00+02:00']]])->assertCreated();
    }

    public function test_une_reference_reutilisee_pour_un_autre_equipement_reste_refusee_meme_apres_desactivation(): void
    {
        $this->staff();
        $this->setupSix();
        $ids = HygieneEquipment::query()->whereIn('name', ['Frigo 1', 'Frigo 2'])->pluck('id', 'name');
        $this->postJson(self::BASE.'/readings', ['readings' => [['equipment_id' => $ids['Frigo 1'], 'value' => 3.0, 'client_ref' => 'ref-x']]])->assertCreated();
        $this->postJson(self::BASE.'/readings', ['readings' => [['equipment_id' => $ids['Frigo 2'], 'value' => 3.0, 'client_ref' => 'ref-x']]])->assertStatus(409);
        $this->assertSame(1, HygieneTemperatureReading::query()->count());
    }
}
