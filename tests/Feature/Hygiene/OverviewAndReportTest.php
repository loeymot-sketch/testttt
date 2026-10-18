<?php

namespace Tests\Feature\Hygiene;

use App\Models\Branch;
use App\Models\HygieneEquipment;
use App\Models\HygieneOilControl;
use App\Models\HygieneReception;
use App\Models\HygieneTaskCompletion;
use App\Models\HygieneTaskTemplate;
use App\Models\HygieneTemperatureReading;
use App\Models\HygieneTraceEntry;
use App\Models\LabelPrintLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * [CAYENNE HYGIÈNE 2026-10-17] Accueil à tuiles (reste à faire + alertes du jour) et dossier de contrôle sanitaire (taux de couverture
 * RÉELS sur ce qui était attendu, incidents avec action corrective, PDF, CSV).
 */
class OverviewAndReportTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/admin/hygiene';

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

    protected function staff(int $branch = 1): User
    {
        $u = User::factory()->create(['branch_id' => $branch, 'name' => 'Karim Benali']);
        $u->givePermissionTo('pos-orders');
        $u->assignRole('POS Operator');
        Sanctum::actingAs($u, ['*']);

        return $u;
    }

    protected function equipment(string $name, string $kind, string $created, int $branch = 1): HygieneEquipment
    {
        $e = new HygieneEquipment(['name' => $name, 'kind' => $kind, 'min_c' => $kind === 'fridge' ? 0 : null, 'max_c' => $kind === 'fridge' ? 4 : null, 'sort_order' => 10, 'is_active' => true]);
        $e->branch_id = $branch;
        $e->created_at = Carbon::parse($created);
        $e->save();

        return $e;
    }

    protected function reading(HygieneEquipment $e, string $at, float $v, string $status = 'ok', array $extra = []): HygieneTemperatureReading
    {
        $r = new HygieneTemperatureReading($extra + [
            'equipment_id' => $e->id, 'equipment_name' => $e->name, 'equipment_kind' => $e->kind, 'min_c' => 0, 'max_c' => 4, 'value_c' => $v, 'status' => $status,
            'alert_reason' => $status === 'alert' ? 'too_warm' : null, 'period' => 'morning', 'recorded_at' => Carbon::parse($at, config('app.timezone')), 'operator_name' => 'Karim Benali',
        ]);
        $r->branch_id = $e->branch_id;
        $r->save();

        return $r;
    }

    protected function template(string $created, int $branch = 1): HygieneTaskTemplate
    {
        $t = new HygieneTaskTemplate(['name' => 'Salle', 'checklist' => [['key' => 'k1', 'label' => 'Sols']], 'frequency' => 'daily', 'sort_order' => 10, 'is_active' => true]);
        $t->branch_id = $branch;
        $t->created_at = Carbon::parse($created);
        $t->save();

        return $t;
    }

    protected function completion(HygieneTaskTemplate $t, string $day, bool $complete, array $extra = []): HygieneTaskCompletion
    {
        $c = new HygieneTaskCompletion($extra + [
            'template_id' => $t->id, 'task_name' => $t->name, 'due_date' => $day, 'items' => [['key' => 'k1', 'label' => 'Sols', 'done' => $complete]], 'items_total' => 1,
            'items_done' => $complete ? 1 : 0, 'complete' => $complete, 'note' => $complete ? null : 'Panne', 'completed_at' => Carbon::parse("$day 20:00", config('app.timezone')), 'operator_name' => 'Karim Benali',
        ]);
        $c->branch_id = $t->branch_id;
        $c->save();

        return $c;
    }

    // ───────────── accueil ─────────────

    public function test_overview_is_empty_before_anything_is_configured(): void
    {
        $this->staff();
        $tiles = collect($this->getJson(self::BASE.'/overview')->assertOk()->json('data'))->keyBy('key');
        $this->assertSame(['temperature', 'tasks', 'dlc', 'reception', 'trace', 'oil'], $tiles->keys()->all());
        $this->assertSame('empty', $tiles['temperature']['status']);
        $this->assertSame('empty', $tiles['tasks']['status']);
        $this->assertSame('empty', $tiles['oil']['status']);
        // aucune étiquette en cours : neutre aussi, JAMAIS vert (relecture adverse 2026-10-18 : « Aucune alerte » en vert au premier jour disait « conforme » sur rien)
        $this->assertSame('idle', $tiles['dlc']['status']);
        // rien reçu, rien tracé : neutre, JAMAIS vert (« vert » = fait et conforme)
        $this->assertSame('idle', $tiles['reception']['status']);
        $this->assertSame('idle', $tiles['trace']['status']);
        $this->assertSame('/admin/labels/dlc', $tiles['dlc']['route']);
        $this->assertSame('/admin/hygiene/temperature', $tiles['temperature']['route']);
    }

    public function test_overview_counts_what_is_left_to_do_and_raises_alerts(): void
    {
        $this->staff();
        $f1 = $this->equipment('Frigo 1', 'fridge', '2026-10-01');
        $f2 = $this->equipment('Frigo 2', 'fridge', '2026-10-01');
        $this->equipment('Friteuse', 'fryer', '2026-10-01'); // jamais comptée côté températures
        $this->reading($f1, '2026-10-20 08:00', 3);
        $tiles = collect($this->getJson(self::BASE.'/overview')->json('data'))->keyBy('key');
        $this->assertSame('todo', $tiles['temperature']['status']);
        $this->assertSame('1/2 relevés', $tiles['temperature']['headline']);
        $this->assertSame('1 à relever', $tiles['temperature']['detail']);
        $this->assertSame('todo', $tiles['oil']['status']);
        $this->assertSame('0/1 testées', $tiles['oil']['headline']);

        $this->reading($f2, '2026-10-20 08:05', 7, 'alert');
        $t = collect($this->getJson(self::BASE.'/overview')->json('data'))->keyBy('key')['temperature'];
        $this->assertSame('alert', $t['status']);
        $this->assertSame(1, $t['counts']['alerts']);
    }

    public function test_overview_tasks_overdue_and_dlc_expired(): void
    {
        $this->staff();
        $tpl = $this->template('2026-10-01');
        $tpl->forceFill(['due_time' => '09:00'])->save();
        $this->template('2026-10-01')->forceFill(['name' => 'Autre'])->save();
        $tiles = collect($this->getJson(self::BASE.'/overview')->json('data'))->keyBy('key');
        $this->assertSame('alert', $tiles['tasks']['status']);
        $this->assertSame('1 en retard', $tiles['tasks']['detail']);

        foreach ([['expires' => now()->subHours(3), 'out' => false], ['expires' => now()->addHours(5), 'out' => false], ['expires' => now()->subHours(2), 'out' => true]] as $i => $c) {
            $l = new LabelPrintLog(['kind' => 'dlc', 'status' => 'printed', 'copies' => 1, 'product_name' => "P$i", 'opened_at' => now()->subDay(), 'expires_at' => $c['expires'], 'discarded_at' => $c['out'] ? now() : null, 'discard_reason' => $c['out'] ? 'discarded' : null]);
            $l->branch_id = 1;
            $l->save();
        }
        $d = collect($this->getJson(self::BASE.'/overview')->json('data'))->keyBy('key')['dlc'];
        $this->assertSame('alert', $d['status']);
        $this->assertSame(['expired' => 1, 'soon' => 1, 'live' => 1, 'unconfirmed' => 0, 'anomaly' => 0], $d['counts']);
    }

    // ───────────── DLC : seule une étiquette DÉPASSÉE appelle une action ─────────────

    private function dlcLabel(string $name, $expires, bool $discarded = false): void
    {
        $l = new LabelPrintLog(['kind' => 'dlc', 'status' => 'printed', 'copies' => 1, 'product_name' => $name, 'opened_at' => now()->subDay(), 'expires_at' => $expires, 'discarded_at' => $discarded ? now() : null, 'discard_reason' => $discarded ? 'discarded' : null]);
        $l->branch_id = 1;
        $l->save();
    }

    private function dlcTile(): array
    {
        return collect($this->getJson(self::BASE.'/overview')->json('data'))->keyBy('key')['dlc'];
    }

    public function test_dlc_aucune_etiquette_en_cours_est_neutre_jamais_verte(): void
    {
        $this->staff();
        $d = $this->dlcTile();
        $this->assertSame('idle', $d['status']);
        $this->assertSame('Aucune étiquette en cours', $d['headline']);
        // une étiquette jetée ou périmée depuis longtemps ne rend pas la tuile « verte » : rien n'est en cours
        $this->dlcLabel('Jetée', now()->addDays(2), true);
        $this->assertSame('idle', $this->dlcTile()['status']);
    }

    public function test_dlc_une_etiquette_qui_expire_dans_la_journee_ne_retient_plus_la_journee_en_regle(): void
    {
        $this->staff();
        $this->dlcLabel('Sauce maison', now()->addHours(12));
        $this->dlcLabel('Oignons', now()->addDays(3));
        $d = $this->dlcTile();
        $this->assertSame('ok', $d['status'], 'le fonctionnement normal d\'une cuisine (DLC de 24 h) n\'est pas « à compléter »');
        $this->assertSame('2 étiquettes en cours', $d['headline']);
        $this->assertSame("dont 1 à\u{00A0}échéance sous 24\u{00A0}h", $d['detail'], '« 24 h » est insécable');
        $this->assertSame(['expired' => 0, 'soon' => 1, 'live' => 2, 'unconfirmed' => 0, 'anomaly' => 0], $d['counts']);
        // et la bannière du hub ne reçoit aucun « domaine à compléter » à cause d'elle
        $this->assertSame(0, $this->getJson(self::BASE.'/overview')->json('meta.todo'));
    }

    public function test_dlc_ne_compte_en_cours_que_les_etiquettes_dlc_imprimees_pas_les_echecs_ni_les_commandes(): void
    {
        $this->staff();
        // une étiquette DE COMMANDE (kind=order), un échec, une réservation récente : aucune n'est une étiquette DLC « en cours »
        foreach ([['order', 'printed'], ['dlc', 'failed'], ['dlc', 'claimed']] as $i => [$kind, $status]) {
            $l = new LabelPrintLog(['kind' => $kind, 'status' => $status, 'copies' => 1, 'product_name' => "X$i", 'opened_at' => now()->subHour(), 'expires_at' => now()->addDays(2)]);
            $l->branch_id = 1;
            $l->save();
        }
        $d = $this->dlcTile();
        $this->assertSame('idle', $d['status']);
        $this->assertSame(0, $d['counts']['live']);
    }

    public function test_dlc_une_seule_etiquette_se_dit_au_singulier(): void
    {
        $this->staff();
        $this->dlcLabel('Seule', now()->addDays(2));
        $d = $this->dlcTile();
        $this->assertSame('1 étiquette en cours', $d['headline']);
        $this->assertSame('', $d['detail']);
    }

    public function test_dlc_non_confirmee_et_a_verifier_sont_dites_sans_retenir_la_journee(): void
    {
        $this->staff();
        $this->dlcLabel('Imprimée', now()->addDays(2));
        $u = new LabelPrintLog(['kind' => 'dlc', 'status' => 'unconfirmed', 'copies' => 1, 'product_name' => 'Non confirmée', 'opened_at' => now()->subHour(), 'expires_at' => now()->addDays(2)]);
        $u->branch_id = 1;
        $u->save();
        $a = new LabelPrintLog(['kind' => 'dlc', 'status' => 'printed', 'copies' => 1, 'product_name' => 'Anomalie', 'opened_at' => now()->subHour(), 'expires_at' => now()->addDays(2), 'anomaly' => true]);
        $a->branch_id = 1;
        $a->save();
        $d = $this->dlcTile();
        // une impression « non confirmée » ne se lève par aucune action (seule la jeter ou la réimprimer) : l'afficher comme bloquante retiendrait
        // « Journée en règle » jusqu'à l'expiration. On la DIT, on ne s'en sert pas pour refuser un bravo.
        $this->assertSame('ok', $d['status']);
        $this->assertSame('3 étiquettes en cours', $d['headline']);
        $this->assertSame("dont 1 non confirmée, 1 à\u{00A0}vérifier", $d['detail'], '« à » ne reste jamais seul au bout de la ligne');
        $this->assertSame(1, $d['counts']['unconfirmed']);
        $this->assertSame(1, $d['counts']['anomaly']);
    }

    public function test_dlc_depassee_reste_une_alerte_avec_le_detail_des_echeances_proches(): void
    {
        $this->staff();
        $this->dlcLabel('Périmée', now()->subHours(2));
        $this->dlcLabel('Bientôt', now()->addHours(5));
        $d = $this->dlcTile();
        $this->assertSame('alert', $d['status']);
        $this->assertSame('1 DLC dépassée', $d['headline']);
        $this->assertSame("1 à\u{00A0}échéance sous 24\u{00A0}h", $d['detail']);
    }

    // ───────────── nettoyage : la tuile dit la même chose que les rappels ─────────────

    public function test_tuile_nettoyage_une_fiche_creee_apres_son_heure_limite_n_est_pas_en_retard(): void
    {
        $this->staff();
        $t = $this->template('2026-10-20 09:30'); // créée à 09:30, aujourd'hui, APRÈS son heure limite
        $t->forceFill(['due_time' => '09:00'])->save();
        $tile = collect($this->getJson(self::BASE.'/overview')->json('data'))->keyBy('key')['tasks'];
        $this->assertSame(0, $tile['counts']['overdue']);
        $this->assertSame('todo', $tile['status'], 'à faire, pas « en retard » : aucun rappel n\'existe pour elle');
        $this->assertSame('1 à faire', $tile['detail']);
    }

    public function test_tuile_nettoyage_aucune_fiche_due_ne_dit_pas_tout_est_fait(): void
    {
        $this->staff();
        $t = $this->template('2026-10-01');
        // fiche hebdomadaire d'un AUTRE jour que le mardi 20 octobre
        $t->forceFill(['frequency' => 'weekly', 'weekday' => 1])->save();
        $tile = collect($this->getJson(self::BASE.'/overview')->json('data'))->keyBy('key')['tasks'];
        $this->assertSame('Rien à faire', $tile['headline']);
        $this->assertSame('', $tile['detail'], '« Rien à faire » + « Tout est fait » se contredisent');
        $this->assertSame('idle', $tile['status'], 'aucune fiche due : neutre, jamais vert (« vert » = fait et conforme)');
    }

    public function test_tuile_nettoyage_et_ecran_du_jour_disent_la_meme_chose_du_retard(): void
    {
        $this->staff();
        // fiche créée APRÈS son heure limite : « à faire » PARTOUT (tuile, rappels ET écran du jour) ; fiche créée avant : « en retard » PARTOUT
        $this->template('2026-10-20 09:30')->forceFill(['name' => 'Après', 'due_time' => '09:00'])->save();
        $this->template('2026-10-01')->forceFill(['name' => 'Avant', 'due_time' => '09:00', 'sort_order' => 20])->save();
        $screen = collect($this->getJson(self::BASE.'/tasks/today')->json('data'))->keyBy('name');
        $this->assertFalse($screen['Après']['overdue'], 'l\'écran ne crie pas « en retard » pour une fiche pas encore attendue');
        $this->assertTrue($screen['Avant']['overdue']);
        $tile = collect($this->getJson(self::BASE.'/overview')->json('data'))->keyBy('key')['tasks'];
        $this->assertSame(1, $tile['counts']['overdue']);
        $this->assertSame($this->getJson(self::BASE.'/tasks/today')->json('meta.summary.overdue'), $tile['counts']['overdue'], 'même compte sur l\'écran et sur la tuile');
    }

    public function test_une_fiche_ancienne_sans_date_de_creation_ne_fait_pas_tomber_l_accueil(): void
    {
        $this->staff();
        $t = $this->template('2026-10-01');
        $t->forceFill(['due_time' => '09:00'])->save();
        \DB::table('hygiene_task_templates')->where('id', $t->id)->update(['created_at' => null]);
        $this->getJson(self::BASE.'/overview')->assertOk();
    }

    public function test_tuile_temperatures_une_nouvelle_mesure_attendue_n_est_pas_tout_est_releve(): void
    {
        $this->staff();
        $e = $this->equipment('Frigo', 'fridge', '2026-10-10');
        // écart à 09:40 traité par « porte refermée » (action à suivi), puis 3 °C à 09:43 : trop tôt pour prouver le retour à la normale
        $this->reading($e, '2026-10-20 09:40', 9.0, 'alert', ['corrective_action' => 'door_closed']);
        $this->reading($e, '2026-10-20 09:43', 3.0);
        $res = $this->getJson(self::BASE.'/overview');
        $tile = collect($res->json('data'))->keyBy('key')['temperature'];
        $this->assertSame('todo', $tile['status'], 'l\'écran des relevés dit « nouvelle mesure à faire » : le hub ne dit pas « Tout est relevé »');
        $this->assertSame('Nouvelle mesure à faire avant 10:10', $tile['detail']);
        $this->assertSame(1, $tile['counts']['followups']);
        $this->assertGreaterThanOrEqual(1, $res->json('meta.todo'), 'la bannière ne peut pas dire « Journée en règle »');
        // une mesure prise assez longtemps après l'écart referme l'attente : la tuile redevient verte
        $this->reading($e, '2026-10-20 09:55', 3.0);
        $tile = collect($this->getJson(self::BASE.'/overview')->json('data'))->keyBy('key')['temperature'];
        $this->assertSame('ok', $tile['status']);
        $this->assertSame('Tout est relevé', $tile['detail']);
    }

    public function test_dlc_reservee_sans_verdict_est_comptee_par_la_tuile_et_le_dossier_comme_par_l_ecran(): void
    {
        $this->staff();
        $l = new LabelPrintLog(['kind' => 'dlc', 'status' => 'claimed', 'copies' => 1, 'product_name' => 'Sauce', 'opened_at' => now()->subHours(3), 'expires_at' => now()->subHours(2), 'claimed_at' => now()->subMinutes(30)]);
        $l->branch_id = 1;
        $l->save();
        $this->assertSame('alert', $this->dlcTile()['status']);
        $d = $this->getJson(self::BASE.'/report/summary?from=2026-10-20&to=2026-10-20')->assertOk()->json('data.dlc');
        $this->assertSame(1, $d['expired_not_out'], 'le dossier ne dit pas « 0 échue non sortie » quand l\'écran DLC dit « EXPIRÉE »');
    }

    public function test_dossier_nettoyage_aujourd_hui_est_dit_a_part_comme_la_tuile(): void
    {
        $this->staff();
        $a = $this->template('2026-10-10');
        $this->template('2026-10-10')->forceFill(['name' => 'Autre'])->save();
        $this->completion($a, '2026-10-20', true);
        $k = $this->getJson(self::BASE.'/report/summary?from=2026-10-20&to=2026-10-20')->assertOk()->json('data.tasks');
        $this->assertSame(['expected' => 0, 'done' => 0, 'missed' => 0], collect($k)->only(['expected', 'done', 'missed'])->all());
        $this->assertNull($k['completion_pct']);
        $this->assertSame(['expected' => 2, 'done' => 1, 'partial' => 0], $k['today']);
    }

    public function test_le_pdf_dit_la_journee_en_cours_a_part_et_n_affiche_pas_100_pour_cent(): void
    {
        $this->staff();
        $a = $this->equipment('Frigo 1', 'fridge', '2026-10-10');
        $this->equipment('Frigo 2', 'fridge', '2026-10-10');
        $this->reading($a, '2026-10-20 08:00', 3);
        $report = \App\Services\Hygiene\ReportBuilder::between('2026-10-20', '2026-10-20')->build(true);
        $html = view('pdf.hygiene_report', ['report' => $report, 'company' => [], 'generatedAt' => 'x', 'generatedBy' => 'y', 'fingerprint' => 'z'])->render();
        $this->assertStringContainsString('Aucun jour écoulé : pas de taux.', $html);
        $this->assertStringContainsString('Aujourd\'hui (journée en cours, hors taux) : 1 sur 2.', $html);
        $this->assertStringNotContainsString('100 %', $html, 'un dossier d\'inspection généré à 10 h ne dit pas 100 %');
    }

    public function test_le_dossier_donne_les_jours_ecoules_pour_que_l_ecran_ne_deduise_pas_aucun_jour_de_expected_zero(): void
    {
        $this->staff();
        $this->equipment('Frigo créé aujourd\'hui', 'fridge', '2026-10-20 08:00');
        $r = $this->getJson(self::BASE.'/report/summary?from=2026-10-01&to=2026-10-20')->assertOk()->json('data');
        $this->assertSame(0, $r['temperature']['expected'], 'aucun jour attendu pour un frigo créé aujourd\'hui…');
        $this->assertSame(19, $r['period']['past_days'], '…alors que 19 jours se sont écoulés : l\'écran ne doit pas dire « aucun jour écoulé »');
        $this->assertSame(0, $this->getJson(self::BASE.'/report/summary?from=2026-10-20&to=2026-10-20')->json('data.period.past_days'));
        $html = view('pdf.hygiene_report', ['report' => \App\Services\Hygiene\ReportBuilder::between('2026-10-01', '2026-10-20')->build(true), 'company' => [], 'generatedAt' => 'x', 'generatedBy' => 'y', 'fingerprint' => 'z'])->render();
        $this->assertStringNotContainsString('Aucun jour écoulé', $html);
        $this->assertStringContainsString('Aucun jour attendu : pas de taux.', $html);
    }

    public function test_dossier_un_equipement_retire_n_est_pas_dans_le_bloc_aujourd_hui(): void
    {
        $this->staff();
        $this->equipment('Actif', 'fridge', '2026-10-10');
        $gone = $this->equipment('Retiré', 'fridge', '2026-10-10');
        // retiré AUJOURD'HUI : encore « pertinent » pour la période (sa fin tombe dans la période), mais plus attendu aujourd'hui, comme sur l'accueil
        $gone->forceFill(['deleted_at' => '2026-10-20 08:00:00'])->save();
        $t = $this->getJson(self::BASE.'/report/summary?from=2026-10-20&to=2026-10-20')->json('data.temperature');
        $this->assertSame(['expected' => 1, 'done' => 0], $t['today'], 'seul l\'équipement actif est attendu aujourd\'hui, comme sur l\'accueil');
    }

    public function test_dossier_une_fiche_retiree_n_est_pas_dans_le_bloc_aujourd_hui_et_son_jour_de_retrait_n_est_pas_manque(): void
    {
        $this->staff();
        $this->template('2026-10-10');
        $gone = $this->template('2026-10-10');
        $gone->forceFill(['name' => 'Retirée', 'deleted_at' => '2026-10-19 10:00:00'])->save();
        $k = $this->getJson(self::BASE.'/report/summary?from=2026-10-19&to=2026-10-20')->json('data.tasks');
        $this->assertSame(['expected' => 1, 'done' => 0, 'partial' => 0], $k['today'], 'seule la fiche active est due aujourd\'hui');
        // le 19 : « Salle » est active et manquée ; « Retirée » est retirée CE jour-là (journée partielle, jamais manquée)
        $this->assertSame(1, $k['missed']);
        $this->assertSame(['Salle'], array_column($k['missed_list'], 'task'));
    }

    public function test_une_reservee_sans_verdict_est_comptee_listee_et_jetable_dans_le_dossier(): void
    {
        $this->staff();
        $l = new LabelPrintLog(['kind' => 'dlc', 'status' => 'claimed', 'copies' => 2, 'product_name' => 'Sauce', 'opened_at' => now()->subHours(3), 'expires_at' => now()->subHours(2), 'claimed_at' => now()->subMinutes(30)]);
        $l->branch_id = 1;
        $l->save();
        $d = $this->getJson(self::BASE.'/report/summary?from=2026-10-20&to=2026-10-20')->json('data.dlc');
        $this->assertSame([1, 2, 2], [$d['count'], $d['copies'], $d['expired_not_out']], 'unité = l\'étiquette physique : 2 copies échues sur 2 ; l\'échue ne dépasse jamais le total');
        $this->assertLessThanOrEqual($d['copies'], $d['expired_not_out'], 'même unité (l\'étiquette) : l\'échue ne dépasse jamais le total');
        // le geste « Jeté » sur cette étiquette doit apparaître au dossier
        $l->forceFill(['discarded_at' => now(), 'discard_reason' => 'discarded'])->save();
        $d = $this->getJson(self::BASE.'/report/summary?from=2026-10-20&to=2026-10-20')->json('data.dlc');
        $this->assertSame([1, 2, 0], [$d['count'], $d['discarded'], $d['expired_not_out']], 'jeté : 2 étiquettes (copies), 1 impression');
    }

    public function test_frontiere_de_dix_minutes_des_reservees(): void
    {
        $this->staff();
        foreach ([['Neuf', 9], ['Onze', 11]] as [$name, $m]) {
            $l = new LabelPrintLog(['kind' => 'dlc', 'status' => 'claimed', 'copies' => 1, 'product_name' => $name, 'opened_at' => now()->subHours(3), 'expires_at' => now()->subHours(2), 'claimed_at' => now()->subMinutes($m)]);
            $l->branch_id = 1;
            $l->save();
        }
        $this->assertSame(1, $this->dlcTile()['counts']['expired'], 'réservée depuis 9 min : écartée ; depuis 11 min : surveillée');
    }

    public function test_tuile_temperatures_annonce_l_echeance_la_plus_proche_pas_la_plus_lointaine(): void
    {
        $this->staff();
        $a = $this->equipment('Frigo A', 'fridge', '2026-10-10');
        $b = $this->equipment('Frigo B', 'fridge', '2026-10-10');
        $this->reading($a, '2026-10-20 09:40', 9.0, 'alert', ['corrective_action' => 'door_closed']); // échéance 10:10
        $this->reading($b, '2026-10-20 09:50', 9.0, 'alert', ['corrective_action' => 'door_closed']); // échéance 10:20
        $tile = collect($this->getJson(self::BASE.'/overview')->json('data'))->keyBy('key')['temperature'];
        $this->assertSame(2, $tile['counts']['followups']);
        $this->assertSame('alert', $tile['status'], 'deux écarts hors seuil du jour : alerte (le détail suit alors le compte d\'écarts)');
        // deux relevés conformes ensuite (trop tôt pour solder) : la tuile est « à faire » avec l'échéance la plus proche
        $this->reading($a, '2026-10-20 09:43', 3.0);
        $this->reading($b, '2026-10-20 09:53', 3.0);
        $tile = collect($this->getJson(self::BASE.'/overview')->json('data'))->keyBy('key')['temperature'];
        $this->assertSame('todo', $tile['status']);
        $this->assertSame('Nouvelle mesure à faire avant 10:10', $tile['detail']);
    }

    public function test_dossier_une_etiquette_qui_expire_dans_l_heure_n_est_pas_echue(): void
    {
        $this->staff();
        $this->dlcLabel('Bientôt', now()->addMinutes(30));
        $this->dlcLabel('Échue', now()->subMinutes(30));
        $d = $this->getJson(self::BASE.'/report/summary?from=2026-10-19&to=2026-10-20')->json('data.dlc'); // le helper ouvre l'étiquette la veille
        $this->assertSame(2, $d['count']);
        $this->assertSame(1, $d['expired_not_out'], 'seule celle dont la DLC est passée est « échue non sortie »');
    }

    public function test_dlc_une_seule_unite_l_etiquette_physique_pour_la_tuile_le_rappel_et_le_dossier(): void
    {
        $this->staff();
        foreach ([['Poulet', 3, 2], ['Sauce', 1, 1], ['Sauce', 1, 1]] as [$name, $copies, $hours]) {
            $l = new LabelPrintLog(['kind' => 'dlc', 'status' => 'printed', 'copies' => $copies, 'product_name' => $name, 'opened_at' => now()->subDay(), 'expires_at' => now()->subHours($hours)]);
            $l->branch_id = 1;
            $l->save();
        }
        // 5 étiquettes physiques dans 3 lignes de journal
        $res = $this->getJson(self::BASE.'/overview');
        $tile = collect($res->json('data'))->keyBy('key')['dlc'];
        $this->assertSame('5 DLC dépassées', $tile['headline']);
        $this->assertSame(5, $tile['counts']['expired']);
        $reminder = collect($res->json('meta.reminders'))->first(fn ($r) => $r['domain'] === 'dlc');
        $this->assertSame('5 étiquettes DLC dépassées : à jeter, ou à marquer « utilisé ».', $reminder['text']);
        $d = $this->getJson(self::BASE.'/report/summary?from=2026-10-19&to=2026-10-20')->json('data.dlc');
        $this->assertSame([3, 5, 5], [$d['count'], $d['copies'], $d['expired_not_out']], '3 impressions, 5 étiquettes, 5 échues : la carte ne se contredit pas');
        $html = view('pdf.hygiene_report', ['report' => \App\Services\Hygiene\ReportBuilder::between('2026-10-19', '2026-10-20')->build(true), 'company' => [], 'generatedAt' => 'x', 'generatedBy' => 'y', 'fingerprint' => 'z'])->render();
        $this->assertStringContainsString('3 impression(s)', $html);
        $this->assertMatchesRegularExpression('/Étiquettes DLC<\/p><p class="kpi-v">5<\/p>/u', $html, 'le chiffre principal de la carte est le nombre d\'ÉTIQUETTES (5), pas de lignes de journal (3)');
        $this->assertStringContainsString('Étiquettes DLC imprimées (5 étiquette(s), 3 impression(s))', $html);
    }

    public function test_dlc_une_non_confirmee_plus_lointaine_que_l_ecran_n_est_pas_annoncee_mais_une_anomalie_oui(): void
    {
        $this->staff();
        $mk = function (string $name, array $extra) {
            $l = new LabelPrintLog(['kind' => 'dlc', 'status' => 'unconfirmed', 'copies' => 1, 'product_name' => $name, 'opened_at' => now()->subHour()] + $extra);
            $l->branch_id = 1;
            $l->save();
        };
        $mk('Proche', ['expires_at' => now()->addHours(48)]);   // dans les 72 h que l'écran « À surveiller » liste
        $mk('Lointaine', ['expires_at' => now()->addDays(5)]);  // au-delà : introuvable à l'écran
        $d = $this->dlcTile();
        $this->assertSame('2 étiquettes en cours', $d['headline']);
        $this->assertSame('dont 1 non confirmée', $d['detail'], 'seule la non confirmée visible à l\'écran est annoncée');
        $mk('Anomalie lointaine', ['status' => 'printed', 'anomaly' => true, 'expires_at' => now()->addDays(9)]);
        $this->assertStringContainsString("1 à\u{00A0}vérifier", $this->dlcTile()['detail'], 'une anomalie est signalée tout de suite, quelle que soit la DLC');
    }

    public function test_un_taux_ne_dit_jamais_100_tant_qu_il_manque_quelque_chose(): void
    {
        $rate = new \ReflectionMethod(\App\Services\Hygiene\ReportBuilder::class, 'rate');
        $rate->setAccessible(true);
        $rb = \App\Services\Hygiene\ReportBuilder::between('2026-10-19', '2026-10-20');
        $this->assertSame(99.9, $rate->invoke($rb, 2189, 2190), '2189 sur 2190 s\'arrondissait à 100 %');
        $this->assertSame(99.9, $rate->invoke($rb, 1999, 2000));
        $this->assertSame(100.0, $rate->invoke($rb, 2190, 2190));
        $this->assertSame(33.3, $rate->invoke($rb, 1, 3));
        $this->assertSame(66.7, $rate->invoke($rb, 2, 3), 'l\'arrondi ordinaire reste inchangé sous 100');
        $this->assertSame(0.0, $rate->invoke($rb, 0, 5));
        $this->assertNull($rate->invoke($rb, 0, 0));
    }

    // ───────────── rapport : taux de couverture ─────────────

    public function test_temperature_coverage_counts_only_days_that_were_expected(): void
    {
        $this->staff();
        $e = $this->equipment('Frigo', 'fridge', '2026-10-15');
        foreach (['2026-10-15', '2026-10-17', '2026-10-19'] as $d) $this->reading($e, "$d 08:00", 3);
        $this->reading($e, '2026-10-18 08:00', 3)->forceFill(['voided_at' => now(), 'void_reason' => 'erreur'])->save(); // annulé : ne couvre pas
        $this->reading($e, '2026-10-14 08:00', 3); // avant la période

        $t = $this->getJson(self::BASE.'/report/summary?from=2026-10-15&to=2026-10-20')->assertOk()->json('data.temperature');
        $this->assertSame(5, $t['expected'], '15,16,17,18,19 — aujourd’hui n’est pas encore attendu');
        $this->assertSame(3, $t['covered']);
        $this->assertEquals(60, $t['coverage_pct']);
        $this->assertSame(1, $t['voided']);

        // aujourd'hui, même couvert, n'entre PAS dans le taux (journée en cours) : il est dit à part, comme la tuile de l'accueil
        $this->reading($e, '2026-10-20 07:30', 3);
        $t = $this->getJson(self::BASE.'/report/summary?from=2026-10-15&to=2026-10-20')->json('data.temperature');
        $this->assertSame([5, 3], [$t['expected'], $t['covered']]);
        $this->assertSame(['expected' => 1, 'done' => 1], $t['today']);
    }

    public function test_le_dossier_d_aujourd_hui_ne_dit_pas_100_pour_cent_quand_l_accueil_dit_un_sur_trois(): void
    {
        $this->staff();
        $a = $this->equipment('Frigo 1', 'fridge', '2026-10-10');
        $this->equipment('Frigo 2', 'fridge', '2026-10-10');
        $this->equipment('Frigo 3', 'fridge', '2026-10-10');
        $this->reading($a, '2026-10-20 08:00', 3);
        $home = collect($this->getJson(self::BASE.'/overview')->json('data'))->keyBy('key')['temperature'];
        $this->assertSame('1/3 relevés', $home['headline']);
        $t = $this->getJson(self::BASE.'/report/summary?from=2026-10-20&to=2026-10-20')->assertOk()->json('data.temperature');
        $this->assertNull($t['coverage_pct'], 'aucun jour écoulé : pas de taux (jamais « 100 % — 1 sur 1 »)');
        $this->assertSame(0, $t['expected']);
        $this->assertSame(['expected' => 3, 'done' => 1], $t['today'], 'même chiffre que l\'accueil : 1 sur 3');
        // une période qui ne contient pas aujourd'hui n'a pas de bloc « aujourd'hui »
        $this->assertNull($this->getJson(self::BASE.'/report/summary?from=2026-10-12&to=2026-10-14')->json('data.temperature.today'));
    }

    public function test_un_equipement_ou_une_fiche_cree_puis_retire_le_meme_jour_n_est_jamais_manque(): void
    {
        $this->staff();
        $e = $this->equipment('Frigo 4 (erreur)', 'fridge', '2026-10-18 15:00');
        $e->forceFill(['deleted_at' => '2026-10-18 15:10:00'])->save();
        $tpl = $this->template('2026-10-18 15:00');
        $tpl->forceFill(['deleted_at' => '2026-10-18 15:10:00'])->save();
        $ok = $this->equipment('Frigo 1', 'fridge', '2026-10-10');
        $this->reading($ok, '2026-10-18 08:00', 3);
        $k = $this->getJson(self::BASE.'/report/summary?from=2026-10-18&to=2026-10-20')->assertOk()->json('data');
        // le frigo de l'erreur n'a jamais été attendu : seul « Frigo 1 » compte (18 couvert, 19 manqué)
        $this->assertSame([2, 1], [$k['temperature']['expected'], $k['temperature']['covered']]);
        $this->assertSame(0, $k['tasks']['missed'], 'une fiche créée puis supprimée le même jour n\'est pas « non faite »');
        $this->assertSame([], $k['tasks']['missed_list']);
    }

    public function test_le_jour_de_creation_compte_quand_l_element_a_ete_traite(): void
    {
        $this->staff();
        $e = $this->equipment('Frigo neuf', 'fridge', '2026-10-18 12:00');
        $this->reading($e, '2026-10-18 13:00', 3);
        $t = $this->getJson(self::BASE.'/report/summary?from=2026-10-10&to=2026-10-20')->json('data.temperature');
        $this->assertSame([2, 1], [$t['expected'], $t['covered']], '18 (créé et relevé) et 19 (manqué)');
    }

    public function test_an_equipment_created_mid_period_is_only_expected_from_its_creation(): void
    {
        $this->staff();
        $this->equipment('Frigo neuf', 'fridge', '2026-10-18 12:00');
        $t = $this->getJson(self::BASE.'/report/summary?from=2026-10-10&to=2026-10-20')->json('data.temperature');
        $this->assertSame(1, $t['expected'], '19 seulement : le 18 est le jour de création (heure inconnue), compté seulement s\'il est couvert');
        $this->assertSame(0, $t['covered']);
    }

    public function test_task_coverage_distinguishes_done_partial_and_missed(): void
    {
        $this->staff();
        $t = $this->template('2026-10-16'); // le 16 est son jour de création : journée partielle, jamais « manquée »
        $this->completion($t, '2026-10-18', true);
        $this->completion($t, '2026-10-19', false);
        $this->completion($t, '2026-10-17', true)->forceFill(['voided_at' => now(), 'void_reason' => 'erreur'])->save();

        $k = $this->getJson(self::BASE.'/report/summary?from=2026-10-15&to=2026-10-20')->json('data.tasks');
        $this->assertSame(['expected' => 3, 'done' => 1, 'partial' => 1, 'missed' => 1], collect($k)->only(['expected', 'done', 'partial', 'missed'])->all());
        $this->assertEquals(33.3, $k['completion_pct']);
        $this->assertSame('Salle', $k['missed_list'][0]['task']);
        $this->assertSame('2026-10-17', $k['missed_list'][0]['date']);
        $this->assertCount(1, $k['incidents']);
        $this->assertSame('Panne', $k['incidents'][0]['action']);
    }

    public function test_incidents_gather_every_domain_with_the_corrective_action(): void
    {
        $this->staff();
        $e = $this->equipment('Frigo 2', 'fridge', '2026-10-01');
        $this->reading($e, '2026-10-19 08:00', 7, 'alert', ['corrective_action' => 'products_discarded']);
        $rc = new HygieneReception(['supplier_name' => 'Metro', 'delivery_note_no' => 'BL-1', 'temperatures' => [], 'checks' => [], 'status' => 'alert', 'decision' => 'refused', 'note' => 'Trop chaud', 'received_at' => now()->subDay(), 'operator_name' => 'K']);
        $rc->branch_id = 1;
        $rc->save();
        $o = new HygieneOilControl(['equipment_id' => 1, 'equipment_name' => 'Friteuse 1', 'type' => 'test', 'polar_pct' => 27, 'status' => 'alert', 'reasons' => 'polar_over', 'corrective_action' => 'oil_changed', 'performed_at' => now()->subHours(5), 'operator_name' => 'K']);
        $o->branch_id = 1;
        $o->save();
        $z = new HygieneTraceEntry(['product_name' => 'Yaourt', 'lot' => 'Y1', 'status' => 'alert', 'note' => 'Rendu', 'traced_at' => now()->subHours(30), 'operator_name' => 'K']);
        $z->branch_id = 1;
        $z->save();

        $inc = $this->getJson(self::BASE.'/report/summary?from=2026-10-18&to=2026-10-20')->json('data.incidents');
        $this->assertSame(['oil', 'receptions', 'temperature', 'trace'], collect($inc)->pluck('module')->sort()->values()->all());
        $byModule = collect($inc)->keyBy('module');
        $this->assertStringContainsString('Produits jetés', $byModule['temperature']['action']);
        $this->assertStringContainsString('7 °C', $byModule['temperature']['text']);
        $this->assertStringContainsString('27 % de composés polaires', $byModule['oil']['text']);
        $this->assertStringContainsString('Huile changée', $byModule['oil']['action']);
        $this->assertStringContainsString('refusé', $byModule['receptions']['text']);
        $this->assertSame($inc, collect($inc)->sortByDesc('at')->values()->all(), 'du plus récent au plus ancien');
    }

    public function test_summary_does_not_ship_the_full_journals(): void
    {
        $this->staff();
        $e = $this->equipment('Frigo', 'fridge', '2026-10-01');
        $this->reading($e, '2026-10-19 08:00', 3);
        $d = $this->getJson(self::BASE.'/report/summary?from=2026-10-19&to=2026-10-20')->json('data');
        foreach (['temperature', 'tasks', 'receptions', 'trace', 'oil', 'dlc'] as $m) $this->assertArrayNotHasKey('rows', $d[$m]);
    }

    public function test_period_is_validated(): void
    {
        $this->staff();
        $this->getJson(self::BASE.'/report/summary')->assertOk(); // sans période : mois en cours (horloge serveur)
        $this->getJson(self::BASE.'/report/summary?from=2026-10-01')->assertStatus(422); // une moitié de période est refusée
        $this->getJson(self::BASE.'/report/summary?from=2026-10-20&to=2026-10-19')->assertStatus(422);
        $this->getJson(self::BASE.'/report/summary?from=2025-01-01&to=2026-10-20')->assertStatus(422)->assertJsonValidationErrors('to');
        $this->getJson(self::BASE.'/report/summary?from=2025-10-20&to=2026-10-20')->assertOk();
        $this->getJson(self::BASE.'/report/pdf?from=bad&to=2026-10-20')->assertStatus(422);
    }

    // ───────────── PDF et CSV ─────────────

    public function test_pdf_is_a_real_document(): void
    {
        $this->staff();
        $e = $this->equipment('Frigo 2', 'fridge', '2026-10-01');
        $this->reading($e, '2026-10-19 08:00', 7, 'alert', ['corrective_action' => 'door_closed']);
        $this->template('2026-10-01');
        $r = $this->get(self::BASE.'/report/pdf?from=2026-10-18&to=2026-10-20', ['Accept' => 'application/pdf'])->assertOk();
        $this->assertSame('application/pdf', $r->headers->get('Content-Type'));
        $this->assertStringContainsString('dossier-hygiene-2026-10-18-2026-10-20.pdf', (string) $r->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $r->streamedContent());
    }

    public function test_le_dossier_distingue_un_releve_saisi_hors_ligne_et_dit_quand_il_a_ete_envoye_et_les_suivis_clos(): void
    {
        $this->staff();
        $e = $this->equipment('Frigo', 'fridge', '2026-10-01');
        $this->reading($e, '2026-10-19 08:00', 3.0);
        $this->reading($e, '2026-10-19 18:00', 3.0, 'ok', ['source' => 'offline']);
        $this->reading($e, '2026-10-19 19:00', 9.0, 'alert', ['corrective_action' => 'recheck'])
            ->forceFill(['followup_closed_at' => now(), 'followup_close_reason' => 'équipement éteint en fin de service'])->save();

        $csv = $this->get(self::BASE.'/report/csv?module=temperature&from=2026-10-19&to=2026-10-20')->assertOk()->streamedContent();
        $lines = array_values(array_filter(explode("\n", trim(substr($csv, 3)))));
        $this->assertStringContainsString(';Saisie;', $lines[0]);
        $this->assertStringContainsString(';direct;', $lines[1]);
        $this->assertStringContainsString(';"hors ligne, envoyé le ', $lines[2], 'le relevé hors ligne est reconnaissable, avec sa date d\'envoi');
        $this->assertStringContainsString('équipement éteint en fin de service', $lines[3], 'la clôture motivée du suivi figure au dossier');

        $sum = $this->getJson(self::BASE.'/report/summary?from=2026-10-19&to=2026-10-20')->assertOk();
        $this->assertSame(1, $sum->json('data.temperature.offline'));
        $this->get(self::BASE.'/report/pdf?from=2026-10-19&to=2026-10-20')->assertOk();
    }

    public function test_dlc_csv_names_the_service_of_a_witness_meal_and_only_of_that(): void
    {
        $this->staff();
        foreach ([['witness', 'noon'], ['prepared', null]] as $i => [$action, $slot]) {
            $l = new LabelPrintLog(['kind' => 'dlc', 'status' => 'printed', 'copies' => 1, 'product_name' => "Plat$i", 'action' => $action, 'meal_slot' => $slot, 'opened_at' => Carbon::parse('2026-10-20 09:00'), 'expires_at' => Carbon::parse('2026-10-25 09:00')]);
            $l->branch_id = 1;
            $l->save();
        }
        $csv = $this->get(self::BASE.'/report/csv?module=dlc&from=2026-10-19&to=2026-10-20')->assertOk()->streamedContent();
        $this->assertStringContainsString('Plat0;"Repas témoin (Midi)"', $csv);
        $this->assertMatchesRegularExpression('/Plat1;Préparation;/u', $csv);
    }

    public function test_csv_has_bom_semicolons_french_decimals_and_neutralises_formulas(): void
    {
        $this->staff();
        $e = $this->equipment('=CMD()', 'fridge', '2026-10-01');
        $this->reading($e, '2026-10-19 08:00', 3.5, 'ok', ['equipment_name' => '=CMD()']);
        $this->reading($e, '2026-10-19 18:00', -2, 'ok', ['equipment_name' => 'Frigo']);
        $csv = $this->get(self::BASE.'/report/csv?module=temperature&from=2026-10-19&to=2026-10-20')->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $lines = array_values(array_filter(explode("\n", trim(substr($csv, 3)))));
        $this->assertStringStartsWith('Date;Équipement;"Température °C";', $lines[0], 'fputcsv met entre guillemets les en-têtes à espace');
        $this->assertStringContainsString("'=CMD();3,5;", $lines[1], 'formule neutralisée, virgule décimale');
        $this->assertStringContainsString(';-2;', $lines[2], 'un nombre négatif reste un nombre');
        $this->getJson(self::BASE.'/report/csv?module=inconnu&from=2026-10-19&to=2026-10-20')->assertStatus(422);
        foreach (['tasks', 'receptions', 'trace', 'oil', 'dlc'] as $m) {
            $this->get(self::BASE."/report/csv?module=$m&from=2026-10-19&to=2026-10-20")->assertOk();
        }
    }

    public function test_branch_isolation_and_permission(): void
    {
        $this->staff(1);
        $this->equipment('Frigo', 'fridge', '2026-10-01');
        $this->staff(2);
        $t = $this->getJson(self::BASE.'/report/summary?from=2026-10-15&to=2026-10-20')->json('data.temperature');
        $this->assertSame(0, $t['expected']);
        $this->assertSame('empty', collect($this->getJson(self::BASE.'/overview')->json('data'))->firstWhere('key', 'temperature')['status']);

        Sanctum::actingAs(User::factory()->create(['branch_id' => 1]), ['*']);
        $this->getJson(self::BASE.'/overview')->assertForbidden();
        $this->getJson(self::BASE.'/report/summary?from=2026-10-15&to=2026-10-20')->assertForbidden();
    }
}
