<?php

namespace Tests\Feature\Hygiene;

use App\Models\Branch;
use App\Models\HygieneEquipment;
use App\Models\HygieneEquipmentChange;
use App\Models\HygieneTemperatureReading;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * [CAYENNE HYGIÈNE 2026-11-18 · audit E2E, lot Températures / Accueil / Alertes] Chaque constat reproduit côté serveur :
 *   P1-2 un bain-marie éteint s'enregistre ; P1-3 masquer ne fait pas disparaître l'écart du jour, la structure est réservée au responsable ;
 *   P1-4 journal en ajout seul des changements de structure ; P1-5 « à relever » = moment EN COURS, partout ; P2 messages et journal.
 */
class TemperatureAuditFixes20261118Test extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/admin/hygiene/temperature';

    private const HY = '/api/admin/hygiene';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Branch::factory()->create(['id' => 1]);
        Branch::factory()->create(['id' => 2]);
        config(['hygiene.branch_id' => 1, 'mail.default' => 'smtp', 'hygiene.reminders.temperature.morning' => '11:30', 'hygiene.reminders.temperature.evening' => '22:30']);
        $this->clockAt('2026-11-18 08:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function clockAt(string $when): void
    {
        Carbon::setTestNow(Carbon::parse($when, config('app.timezone')));
    }

    /** Le responsable (droit `settings`) : structure du registre permise. */
    private function manager(string $name = 'Sarah Lopez', int $branch = 1): User
    {
        $u = User::factory()->create(['branch_id' => $branch, 'name' => $name]);
        $u->givePermissionTo(['pos-orders', 'settings']);
        $u->assignRole('Branch Manager');
        Sanctum::actingAs($u, ['*']);

        return $u;
    }

    /** Le caissier qui tient la tablette : saisie oui, structure non. */
    private function cashier(): User
    {
        $u = User::factory()->create(['branch_id' => 1, 'name' => 'Karim Caisse']);
        $u->givePermissionTo('pos-orders');
        $u->assignRole('POS Operator');
        Sanctum::actingAs($u, ['*']);

        return $u;
    }

    /** @return array<string,int> nom → id */
    private function three(): array
    {
        $this->manager();
        $this->postJson(self::BASE.'/equipment/quick-setup', ['fridge' => 3])->assertCreated();
        foreach (HygieneEquipment::query()->get() as $e) { $e->created_at = Carbon::parse('2026-11-01'); $e->save(); }

        return HygieneEquipment::query()->pluck('id', 'name')->all();
    }

    private function read(int $id, float $value, ?string $action = null): void
    {
        $r = ['equipment_id' => $id, 'value' => $value] + ($action ? ['corrective_action' => $action] : []);
        $this->postJson(self::BASE.'/readings', ['readings' => [$r]])->assertCreated();
    }

    private function tile(): array
    {
        return collect($this->getJson(self::HY.'/overview')->assertOk()->json('data'))->firstWhere('key', 'temperature');
    }

    // ───────────── P1-2 : un bain-marie éteint entre au registre ─────────────

    public function test_p1_2_un_bain_marie_eteint_a_15_degres_s_enregistre_avec_son_action_corrective(): void
    {
        $this->manager();
        $this->postJson(self::BASE.'/equipment/quick-setup', ['hot_holding' => 1])->assertCreated();
        $id = HygieneEquipment::query()->value('id');

        $this->postJson(self::BASE.'/readings', ['readings' => [['equipment_id' => $id, 'value' => 15]]])
            ->assertStatus(422)->assertJsonValidationErrors('readings.0.corrective_action'); // écart : l'action reste obligatoire
        $this->postJson(self::BASE.'/readings', ['readings' => [['equipment_id' => $id, 'value' => 15, 'corrective_action' => 'products_discarded']]])->assertCreated();
        $row = HygieneTemperatureReading::query()->firstOrFail();
        $this->assertSame('alert', $row->status);
        $this->assertSame('too_cold', $row->alert_reason);
        $this->postJson(self::BASE.'/readings', ['readings' => [['equipment_id' => $id, 'value' => 0, 'corrective_action' => 'products_discarded']]])->assertCreated(); // la borne elle-même

        // une vraie faute de frappe reste refusée, et le message DIT la plage
        $r = $this->postJson(self::BASE.'/readings', ['readings' => [['equipment_id' => $id, 'value' => -5, 'corrective_action' => 'products_discarded']]])->assertStatus(422);
        $this->assertStringContainsString('en dehors de la plage plausible pour un équipement chaud (0 à 99,9 °C)', $r->json('errors')['readings.0.value'][0]);
        $this->postJson(self::BASE.'/readings', ['readings' => [['equipment_id' => $id, 'value' => 150]]])->assertStatus(422)->assertJsonValidationErrors('readings.0.value');
    }

    // ───────────── P1-3 (c) : la structure est réservée au responsable, la saisie reste ouverte ─────────────

    public function test_p1_3_le_caissier_ne_touche_pas_a_la_structure_mais_saisit_annule_et_clot_un_suivi(): void
    {
        $ids = $this->three();
        $id = $ids['Frigo 1'];
        $this->cashier();

        $this->postJson(self::BASE.'/equipment', ['name' => 'Frigo X', 'kind' => 'fridge'])->assertForbidden();
        $this->postJson(self::BASE.'/equipment/quick-setup', ['fridge' => 5])->assertForbidden();
        $this->putJson(self::BASE."/equipment/$id", ['max_c' => 8])->assertForbidden();
        $this->putJson(self::BASE."/equipment/$id", ['is_active' => false])->assertForbidden();
        $this->deleteJson(self::BASE."/equipment/$id")->assertForbidden();
        $this->assertSame(4.0, (float) HygieneEquipment::query()->find($id)->max_c);
        $this->assertTrue(HygieneEquipment::query()->find($id)->is_active);
        $this->assertSame(0, HygieneEquipmentChange::query()->where('equipment_id', $id)->where('field', '!=', 'created')->count());

        // la saisie, l'annulation, la clôture motivée d'un suivi : tout le personnel
        $this->getJson(self::BASE.'/today')->assertOk()->assertJsonPath('meta.can_manage', false);
        $this->getJson(self::BASE.'/equipment')->assertOk()->assertJsonPath('meta.can_manage', false);
        $this->read($id, 9, 'door_closed');
        $reading = HygieneTemperatureReading::query()->firstOrFail();
        $this->postJson(self::BASE."/readings/{$reading->id}/followup-close", ['reason' => 'Frigo en panne'])->assertOk();
        $this->postJson(self::BASE."/readings/{$reading->id}/void", ['reason' => 'faute de frappe'])->assertOk();

        $this->manager('Chef Manager');
        $this->getJson(self::BASE.'/today')->assertJsonPath('meta.can_manage', true);
        $this->putJson(self::BASE."/equipment/$id", ['max_c' => 5])->assertOk();
    }

    // ───────────── P1-3 (a) : masquer ne fait pas disparaître l'écart du jour ─────────────

    public function test_p1_3_masquer_un_equipement_garde_son_ecart_du_jour_dans_la_tuile_et_l_ecran_jusqu_a_ce_soir(): void
    {
        $ids = $this->three();
        $this->read($ids['Frigo 1'], 3);
        $this->read($ids['Frigo 2'], 9, 'products_discarded');
        $this->read($ids['Frigo 3'], 10, 'products_moved');
        $before = $this->tile();
        $this->assertSame('3/3 relevés', $before['headline']);
        $this->assertSame('2 écarts', $before['detail']);

        $this->clockAt('2026-11-18 09:00');
        $this->putJson(self::BASE."/equipment/{$ids['Frigo 3']}", ['is_active' => false])->assertOk();

        $after = $this->tile();
        $this->assertSame('alert', $after['status']);
        $this->assertSame(2, $after['counts']['alerts'], 'l’écart de l’équipement masqué reste compté');
        $this->assertSame(1, $after['counts']['hidden_alerts']);
        $this->assertSame('2 écarts (dont 1 sur un équipement masqué)', $after['detail']);
        $this->assertSame('2/2 relevés', $after['headline'], 'masqué = plus attendu au relevé');

        $today = $this->getJson(self::BASE.'/today')->assertOk();
        $this->assertSame(2, $today->json('meta.summary.alert'));
        $this->assertSame(1, $today->json('meta.summary.hidden_alert'));
        $this->assertSame(2, $today->json('meta.summary.total'));
        $hidden = collect($today->json('data'))->firstWhere('id', $ids['Frigo 3']);
        $this->assertTrue($hidden['hidden_today']);
        $this->assertFalse($hidden['due']);
        $this->assertSame('alert', $hidden['state']);

        // le lendemain, l'équipement masqué n'est plus à l'écran ni dans la tuile
        $this->clockAt('2026-11-19 08:00');
        $next = $this->getJson(self::BASE.'/today')->assertOk();
        $this->assertNull(collect($next->json('data'))->firstWhere('id', $ids['Frigo 3']));
        $this->assertSame(0, $next->json('meta.summary.alert'));
    }

    public function test_p1_3_un_suivi_ouvert_d_un_equipement_masque_aujourd_hui_reste_dans_a_traiter_maintenant(): void
    {
        config(['hygiene.followup.minutes' => 30]);
        $ids = $this->three();
        $this->read($ids['Frigo 2'], 9, 'door_closed'); // « porte refermée » : une nouvelle mesure est attendue
        $this->clockAt('2026-11-18 08:05');
        $this->putJson(self::BASE."/equipment/{$ids['Frigo 2']}", ['is_active' => false])->assertOk();

        $this->clockAt('2026-11-18 08:45'); // échéance dépassée
        $reminders = collect($this->getJson(self::HY.'/overview')->assertOk()->json('meta.reminders'));
        $this->assertNotNull($reminders->first(fn ($r) => str_starts_with($r['key'], 'temperature-followup-'.$ids['Frigo 2'].'-')), 'le suivi de l’équipement masqué reste rappelé');
        $this->assertStringContainsString('Frigo 2', $reminders->first(fn ($r) => str_starts_with($r['key'], 'temperature-followup-'))['text']);
    }

    public function test_p2_tous_masques_l_accueil_dit_reactiver_comme_l_ecran(): void
    {
        $ids = $this->three();
        $this->clockAt('2026-11-17 18:00'); // masqués HIER : plus rien à garder aujourd'hui
        foreach ($ids as $id) $this->putJson(self::BASE."/equipment/$id", ['is_active' => false])->assertOk();
        $this->clockAt('2026-11-18 08:00');

        $t = $this->tile();
        $this->assertSame('empty', $t['status']);
        $this->assertSame('Tous masqués', $t['headline']);
        $this->assertSame('Réactiver un équipement', $t['cta']);
        $step = collect($this->getJson(self::HY.'/overview')->json('meta.setup.steps'))->firstWhere('key', 'equipment');
        $this->assertFalse($step['done']);
        $this->assertSame('Réactiver un équipement', $step['cta']);
        $this->assertStringContainsString('réactivez-en un', $step['detail']);
    }

    // ───────────── P1-4 : journal en ajout seul des changements de structure ─────────────

    public function test_p1_4_un_changement_une_ligne_aucun_changement_aucune_ligne(): void
    {
        $ids = $this->three();
        $id = $ids['Frigo 1'];
        $this->assertSame(['created'], HygieneEquipmentChange::query()->where('equipment_id', $id)->pluck('field')->all(), 'la création est tracée avec ses seuils');

        $this->clockAt('2026-11-18 09:12');
        $this->putJson(self::BASE."/equipment/$id", ['max_c' => 8])->assertOk();
        $rows = HygieneEquipmentChange::query()->where('equipment_id', $id)->where('field', '!=', 'created')->get();
        $this->assertCount(1, $rows);
        $this->assertSame('max_c', $rows[0]->field);
        $this->assertSame('4', $rows[0]->old_value);
        $this->assertSame('8', $rows[0]->new_value);
        $this->assertSame('Sarah Lopez', $rows[0]->user_name);
        $this->assertSame('2026-11-18 09:12', $rows[0]->changed_at->setTimezone(config('app.timezone'))->format('Y-m-d H:i'));
        $this->assertSame(1, $rows[0]->branch_id);

        // la même valeur renvoyée : aucun changement, aucune ligne
        $this->putJson(self::BASE."/equipment/$id", ['max_c' => 8, 'name' => 'Frigo 1'])->assertOk();
        $this->assertSame(1, HygieneEquipmentChange::query()->where('equipment_id', $id)->where('field', '!=', 'created')->count());

        // remis à 4 : une ligne ; renommé + seuil bas en un envoi : deux lignes ; masqué puis réactivé : deux lignes
        $this->putJson(self::BASE."/equipment/$id", ['max_c' => 4])->assertOk();
        $this->putJson(self::BASE."/equipment/$id", ['name' => 'Frigo boissons', 'min_c' => 1])->assertOk();
        $this->putJson(self::BASE."/equipment/$id", ['is_active' => false])->assertOk();
        $this->putJson(self::BASE."/equipment/$id", ['is_active' => true])->assertOk();
        $this->assertSame(['max_c', 'max_c', 'name', 'min_c', 'is_active', 'is_active'],
            HygieneEquipmentChange::query()->where('equipment_id', $id)->where('field', '!=', 'created')->orderBy('id')->pluck('field')->all());

        // un refus (seuil hors bornes) n'écrit rien
        $this->putJson(self::BASE."/equipment/$id", ['max_c' => 60])->assertStatus(422);
        $this->assertSame(6, HygieneEquipmentChange::query()->where('equipment_id', $id)->where('field', '!=', 'created')->count());

        // l'historique est fourni par la réponse EXISTANTE « équipements », dit en clair, le plus récent d'abord
        $eq = collect($this->getJson(self::BASE.'/equipment')->assertOk()->json('data'))->firstWhere('id', $id);
        $texts = array_column($eq['changes'], 'text');
        $this->assertSame(7, $eq['changes_total']);
        $this->assertContains('Seuil max 4 °C → 8 °C', $texts);
        $this->assertContains('Seuil max 8 °C → 4 °C', $texts);
        $this->assertContains('Nom « Frigo 1 » → « Frigo boissons »', $texts);
        $this->assertContains('Seuil min 0 °C → 1 °C', $texts);
        $this->assertContains('Masqué', $texts);
        $this->assertContains('Réactivé', $texts);
        $this->assertContains('Créé (seuils 0 à 4 °C)', $texts);
        $this->assertSame('Sarah Lopez', $eq['changes'][0]['by']);
        $this->assertSame('Réactivé', $eq['changes'][0]['text']);
    }

    public function test_p1_4_la_suppression_et_un_congelateur_negatif_sont_traces(): void
    {
        $this->manager();
        $this->postJson(self::BASE.'/equipment/quick-setup', ['freezer' => 1])->assertCreated();
        $id = HygieneEquipment::query()->value('id');
        $this->putJson(self::BASE."/equipment/$id", ['max_c' => -20])->assertOk();
        $this->deleteJson(self::BASE."/equipment/$id")->assertOk();
        $rows = HygieneEquipmentChange::query()->where('equipment_id', $id)->orderBy('id')->get();
        $this->assertSame(['created', 'max_c', 'deleted'], $rows->pluck('field')->all());
        $this->assertSame('≤ −18 °C', $rows[0]->new_value);
        $this->assertSame(['-18', '-20'], [$rows[1]->old_value, $rows[1]->new_value]);
    }

    public function test_p1_4_une_ligne_du_journal_ne_se_modifie_ni_ne_se_supprime(): void
    {
        $ids = $this->three();
        $this->putJson(self::BASE."/equipment/{$ids['Frigo 1']}", ['max_c' => 8])->assertOk();
        $row = HygieneEquipmentChange::query()->where('field', 'max_c')->firstOrFail();

        // aucune route n'écrit dans ce journal (hors les actions de structure qui l'alimentent)
        $writers = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_contains($r->uri(), 'hygiene') && str_contains($r->uri(), 'change'));
        $this->assertCount(0, $writers, 'aucune route ne vise le journal des changements');

        // et le modèle refuse toute réécriture
        try {
            $row->new_value = '4';
            $row->save();
            $this->fail('une ligne du journal a pu être modifiée');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('ne se modifie pas', $e->getMessage());
        }
        try {
            $row->delete();
            $this->fail('une ligne du journal a pu être supprimée');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('ne se supprime pas', $e->getMessage());
        }
        $this->assertSame('8', HygieneEquipmentChange::query()->find($row->id)->new_value);
    }

    public function test_p1_4_le_journal_est_isole_par_branche(): void
    {
        $ids = $this->three();
        $this->putJson(self::BASE."/equipment/{$ids['Frigo 1']}", ['max_c' => 8])->assertOk();
        $this->assertGreaterThan(0, HygieneEquipmentChange::query()->count());

        $this->manager('Autre Restaurant', 2);
        $this->assertSame(0, HygieneEquipmentChange::query()->count(), 'BranchScope : la branche 2 ne voit pas le journal de la branche 1');
    }

    // ───────────── P1-5 : « à relever » = moment EN COURS, partout ─────────────

    public function test_p1_5_matin_tout_releve_puis_soir_sans_releve_du_soir_dans_les_habitudes(): void
    {
        $ids = $this->three();
        foreach ($ids as $id) $this->read($id, 3);

        $morning = $this->getJson(self::BASE.'/today')->assertOk();
        $this->assertSame('morning', $morning->json('meta.period'));
        $this->assertSame(0, $morning->json('meta.summary.todo'));
        $this->assertSame('3/3 relevés', $this->tile()['headline']);

        $this->clockAt('2026-11-18 16:05');
        $evening = $this->getJson(self::BASE.'/today')->assertOk();
        $this->assertSame('evening', $evening->json('meta.period'));
        $this->assertFalse($evening->json('meta.evening_expected'));
        $this->assertSame(0, $evening->json('meta.summary.todo'));
        // la pastille et le bouton « Saisir tout ce qui reste » lisent la MÊME chose
        $this->assertSame(0, collect($evening->json('data'))->where('done_this_period', false)->count());
        $this->assertSame('ok', $this->tile()['status']);
    }

    public function test_p1_5_le_soir_quand_le_restaurant_releve_le_soir_la_pastille_et_le_bouton_disent_3_et_la_tuile_matin_et_soir(): void
    {
        $ids = $this->three();
        // habitude : des relevés du soir sur DEUX jours de la semaine passée (un seul ne fait pas une habitude)
        $this->clockAt('2026-11-15 19:00');
        $this->read($ids['Frigo 1'], 3);
        $this->clockAt('2026-11-16 19:00');
        $this->read($ids['Frigo 1'], 3);
        $this->clockAt('2026-11-18 08:00');
        foreach ($ids as $id) $this->read($id, 3);

        $this->clockAt('2026-11-18 16:05');
        $r = $this->getJson(self::BASE.'/today')->assertOk();
        $this->assertTrue($r->json('meta.evening_expected'));
        $this->assertSame(3, $r->json('meta.summary.todo'));
        $this->assertSame(3, collect($r->json('data'))->where('done_this_period', false)->count(), 'pastille = bouton');
        $this->assertSame(0, $r->json('meta.summary.ok'));
        $tile = $this->tile();
        $this->assertSame('Matin 3/3 · Soir 0/3', $tile['headline']);
        $this->assertSame('todo', $tile['status']);
        $this->assertSame('3 à relever ce soir', $tile['detail']);

        $this->read($ids['Frigo 1'], 3);
        $this->assertSame(2, $this->getJson(self::BASE.'/today')->json('meta.summary.todo'));
        $this->assertSame('Matin 3/3 · Soir 1/3', $this->tile()['headline']);
    }

    public function test_p1_5_un_ecart_du_matin_reste_compte_le_soir_meme_quand_l_equipement_est_a_relever(): void
    {
        $ids = $this->three();
        $this->clockAt('2026-11-15 19:00');
        $this->read($ids['Frigo 1'], 3);
        $this->clockAt('2026-11-16 19:00');
        $this->read($ids['Frigo 1'], 3);
        $this->clockAt('2026-11-18 08:00');
        $this->read($ids['Frigo 2'], 9, 'products_discarded');
        $this->clockAt('2026-11-18 16:05');
        $r = $this->getJson(self::BASE.'/today')->assertOk();
        $this->assertSame(1, $r->json('meta.summary.alert'));
        $this->assertSame(3, $r->json('meta.summary.todo'));
        $this->assertSame('alert', $this->tile()['status']);
    }

    /** [relecture adverse P1-2] Un contrôle ponctuel l'après-midi (ou une mesure de suivi) n'arme pas « Soir 0/n » pour une semaine. */
    public function test_p1_5_un_seul_releve_tardif_ne_cree_pas_l_obligation_du_soir(): void
    {
        $ids = $this->three();
        $this->clockAt('2026-11-17 15:30');
        $this->read($ids['Frigo 1'], 3); // contrôle ponctuel, la veille
        $this->clockAt('2026-11-18 08:00');
        foreach ($ids as $id) $this->read($id, 3);
        $this->clockAt('2026-11-18 16:00');
        $r = $this->getJson(self::BASE.'/today')->assertOk();
        $this->assertFalse($r->json('meta.evening_expected'));
        $this->assertSame(0, $r->json('meta.summary.todo'));
        $this->assertSame('ok', $this->tile()['status']);
    }

    // ───────────── P2 : journal ─────────────

    public function test_p2_le_journal_dit_qui_a_annule_et_s_il_est_tronque(): void
    {
        $ids = $this->three();
        $this->read($ids['Frigo 1'], 3);
        $reading = HygieneTemperatureReading::query()->firstOrFail();
        $this->postJson(self::BASE."/readings/{$reading->id}/void", ['reason' => 'faute de frappe'])->assertOk()->assertJsonPath('data.voided_by_name', 'Sarah Lopez');
        $this->read($ids['Frigo 2'], 3);

        $h = $this->getJson(self::BASE.'/history?limit=1')->assertOk();
        $this->assertTrue($h->json('meta.truncated'));
        $this->assertSame(1, $h->json('meta.limit'));
        $this->assertCount(1, $h->json('data'));
        $all = $this->getJson(self::BASE.'/history')->assertOk();
        $this->assertFalse($all->json('meta.truncated'));
        $voided = collect($all->json('data'))->firstWhere('id', $reading->id);
        $this->assertSame('Sarah Lopez', $voided['voided_by_name']);
        $this->assertNotNull($voided['voided_at']);
        $only = $this->getJson(self::BASE.'/history?equipment_id='.$ids['Frigo 2'])->assertOk();
        $this->assertSame([$ids['Frigo 2']], array_values(array_unique(array_column($only->json('data'), 'equipment_id'))));
    }

    // ───────────── P2 : alertes, messages en français clair ─────────────

    public function test_p2_alertes_les_refus_de_validation_sont_en_francais_clair(): void
    {
        $this->manager();
        $base = ['enabled' => false, 'recipients' => [], 'domains' => ['temperature'], 'send_from' => '09:00', 'send_until' => '23:30', 'repeat_minutes' => 60, 'max_sends' => 3];

        $r = $this->putJson(self::HY.'/alerts', array_diff_key($base, ['domains' => 1]))->assertStatus(422);
        $this->assertStringContainsString('Choisissez au moins un type d\'alerte', $r->json('errors.domains.0'));
        $r = $this->putJson(self::HY.'/alerts', array_diff_key($base, ['send_from' => 1]))->assertStatus(422);
        $this->assertStringContainsString('heure de début', $r->json('errors.send_from.0'));
        $this->assertStringNotContainsString('send from', $r->json('errors.send_from.0'));
        $r = $this->putJson(self::HY.'/alerts', ['repeat_minutes' => 5] + $base)->assertStatus(422);
        $this->assertStringNotContainsString('repeat', $r->json('errors.repeat_minutes.0'));
        $this->putJson(self::HY.'/alerts', ['enabled' => true] + $base)->assertStatus(422)->assertJsonPath('errors.recipients.0', 'Ajoutez au moins une adresse e-mail pour activer les alertes.');
    }
}
