<?php

namespace Tests\Feature\Hygiene;

use App\Models\Branch;
use App\Models\HygieneEquipment;
use App\Models\HygieneOilControl;
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
 * [CAYENNE HYGIÈNE 2026-10-17 · méga-fichier §7] Les rappels : ce qui est en retard ou à traiter MAINTENANT, une phrase chacun, relié à
 * son écran — et qui fait passer la tuile du domaine en alerte. Un rappel qui n'a pas de sens pour ce restaurant ne se déclenche pas.
 */
class RemindersTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/admin/hygiene/overview';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Branch::factory()->create(['id' => 1]);
        Branch::factory()->create(['id' => 2]);
        config(['hygiene.reminders.temperature.morning' => '11:30', 'hygiene.reminders.temperature.evening' => '22:30', 'hygiene.reminders.oil_test' => '15:00', 'hygiene.reminders.trace_silent_days' => 2]);
        $this->now('2026-10-20 10:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function now(string $when): void
    {
        Carbon::setTestNow(Carbon::parse($when, config('app.timezone')));
    }

    private function staff(int $branch = 1): User
    {
        $u = User::factory()->create(['branch_id' => $branch, 'name' => 'Karim Benali']);
        $u->givePermissionTo('pos-orders');
        $u->assignRole('POS Operator');
        Sanctum::actingAs($u, ['*']);

        return $u;
    }

    private function equipment(string $name, string $kind = 'fridge', int $branch = 1): HygieneEquipment
    {
        $e = new HygieneEquipment(['name' => $name, 'kind' => $kind, 'min_c' => $kind === 'fridge' ? 0 : null, 'max_c' => $kind === 'fridge' ? 4 : null, 'sort_order' => 10, 'is_active' => true]);
        $e->branch_id = $branch;
        $e->created_at = Carbon::parse('2026-10-01');
        $e->save();

        return $e;
    }

    private function reading(HygieneEquipment $e, string $at, string $period = 'morning', ?bool $voided = false): void
    {
        $r = new HygieneTemperatureReading([
            'equipment_id' => $e->id, 'equipment_name' => $e->name, 'equipment_kind' => $e->kind, 'min_c' => 0, 'max_c' => 4, 'value_c' => 3, 'status' => 'ok',
            'period' => $period, 'recorded_at' => Carbon::parse($at, config('app.timezone')), 'operator_name' => 'Karim Benali',
            'voided_at' => $voided ? now() : null, 'void_reason' => $voided ? 'erreur' : null,
        ]);
        $r->branch_id = $e->branch_id;
        $r->save();
    }

    private function oil(HygieneEquipment $f, string $type, string $status, ?float $polar, string $at, ?string $action = null): void
    {
        $o = new HygieneOilControl([
            'equipment_id' => $f->id, 'equipment_name' => $f->name, 'type' => $type, 'polar_pct' => $polar, 'status' => $status, 'corrective_action' => $action,
            'performed_at' => Carbon::parse($at, config('app.timezone')), 'operator_name' => 'Karim Benali',
        ]);
        $o->branch_id = $f->branch_id;
        $o->save();
    }

    /** @return array{0: \Illuminate\Support\Collection, 1: \Illuminate\Support\Collection} [rappels par clé, tuiles par clé] */
    private function overview(): array
    {
        $json = $this->getJson(self::BASE)->assertOk()->json();

        return [collect($json['meta']['reminders'])->keyBy('key'), collect($json['data'])->keyBy('key')];
    }

    public function test_rien_n_est_configure_rien_n_est_en_retard(): void
    {
        $this->staff();
        $this->now('2026-10-20 23:30');
        [$reminders] = $this->overview();
        $this->assertCount(0, $reminders);
    }

    public function test_releve_du_matin_non_fait_apres_l_heure_rappelle_les_equipements_manquants_et_passe_la_tuile_en_alerte(): void
    {
        $this->staff();
        $f1 = $this->equipment('Frigo 1');
        $this->equipment('Frigo 2');
        $this->equipment('Congélateur', 'freezer');

        [$reminders] = $this->overview();
        $this->assertCount(0, $reminders, '10:00 : avant l’heure du rappel');

        $this->now('2026-10-20 11:30');
        [$reminders, $tiles] = $this->overview();
        $this->assertSame('alert', $reminders['temperature-late-morning']['level']);
        $this->assertSame('/admin/hygiene/temperature', $reminders['temperature-late-morning']['route']);
        $this->assertSame('Relevé du matin non fait : Frigo 1, Frigo 2, Congélateur.', $reminders['temperature-late-morning']['text']);
        $this->assertSame('alert', $tiles['temperature']['status']);
        $this->assertSame('Relevé du matin non fait : Frigo 1, Frigo 2, Congélateur.', $tiles['temperature']['detail']);
        $this->assertSame(1, $tiles['temperature']['reminders']);

        // un relevé fait : seuls les manquants restent nommés ; un relevé ANNULÉ ne compte pas
        $this->reading($f1, '2026-10-20 08:00');
        [$reminders] = $this->overview();
        $this->assertSame('Relevé du matin non fait : Frigo 2, Congélateur.', $reminders['temperature-late-morning']['text']);
        $this->reading($this->equipment('Vitrine'), '2026-10-20 08:00', 'morning', true);
        [$reminders] = $this->overview();
        $this->assertStringContainsString('Vitrine', $reminders['temperature-late-morning']['text']);

        // tout relevé : plus de rappel, la tuile n'est plus en alerte
        foreach (HygieneEquipment::query()->get() as $e) {
            $this->reading($e, '2026-10-20 09:00');
        }
        [$reminders, $tiles] = $this->overview();
        $this->assertArrayNotHasKey('temperature-late-morning', $reminders->all());
        $this->assertNotSame('alert', $tiles['temperature']['status']);
    }

    public function test_le_rappel_du_soir_n_existe_que_si_le_restaurant_releve_le_soir(): void
    {
        $this->staff();
        $f = $this->equipment('Frigo 1');
        $this->reading($f, '2026-10-20 08:00'); // matin fait
        $this->now('2026-10-20 23:00');
        [$reminders] = $this->overview();
        $this->assertArrayNotHasKey('temperature-late-evening', $reminders->all(), 'jamais de relevé du soir : pas de rappel qui râle pour rien');

        $this->reading($f, '2026-10-17 21:00', 'evening'); // il relève le soir, d'habitude
        [$reminders] = $this->overview();
        $this->assertSame('Relevé du soir non fait : Frigo 1.', $reminders['temperature-late-evening']['text']);

        $this->now('2026-10-20 22:00');
        [$reminders] = $this->overview();
        $this->assertArrayNotHasKey('temperature-late-evening', $reminders->all(), 'avant 22:30');
    }

    public function test_une_heure_de_rappel_vide_desactive_le_rappel(): void
    {
        config(['hygiene.reminders.temperature.morning' => null]);
        $this->staff();
        $this->equipment('Frigo 1');
        $this->now('2026-10-20 15:00');
        [$reminders] = $this->overview();
        $this->assertArrayNotHasKey('temperature-late-morning', $reminders->all());
    }

    public function test_nettoyages_en_retard_jusqu_a_trois_une_phrase_chacun_au_dela_une_seule_phrase(): void
    {
        $this->staff();
        $ids = [];
        foreach (range(1, 4) as $n) {
            $t = new HygieneTaskTemplate(['name' => "Fiche $n", 'checklist' => [['key' => 'k1', 'label' => 'Sols']], 'frequency' => 'daily', 'due_time' => '09:00', 'sort_order' => 10 + $n, 'is_active' => true]);
            $t->branch_id = 1;
            $t->created_at = Carbon::parse('2026-10-01');
            $t->save();
            $ids[] = $t->id;
            if ($n === 3) {
                $keys = array_keys($this->overview()[0]->all());
                $this->assertCount(3, array_filter($keys, fn ($k) => str_starts_with($k, 'tasks-late-')), '3 fiches : une phrase chacune');
                $this->assertNotContains('tasks-late-many', $keys);
            }
        }
        $rem = $this->overview()[0]->all();
        $late = array_filter($rem, fn ($r) => $r['domain'] === 'tasks');
        $this->assertCount(1, $late, '4 fiches : UNE phrase, pas quatre');
        $this->assertSame('4 nettoyages en retard : « Fiche 1 », « Fiche 2 », « Fiche 3 » et 1 autre.', $rem['tasks-late-many']['text']);
        // la tuile Nettoyage garde son propre détail court (« 4 en retard »), jamais la longue phrase du rappel
        $tile = $this->overview()[1]['tasks'];
        $this->assertSame('4 en retard', $tile['detail']);
        $this->assertSame('alert', $tile['status']);
        $this->assertSame('alert', $rem['tasks-late-many']['level']);
        $this->assertSame('/admin/hygiene/tasks', $rem['tasks-late-many']['route']);
    }

    public function test_nettoyages_regroupes_nomment_les_trois_premiers_selon_l_ordre_de_l_ecran_pas_au_hasard_de_l_index(): void
    {
        $this->staff();
        // sort_order inverse de l'id : l'écran trie par (sort_order, id) ; le rappel doit nommer les mêmes fiches
        foreach ([5 => 'Cinquième', 4 => 'Quatrième', 3 => 'Troisième', 2 => 'Deuxième', 1 => 'Première'] as $order => $name) {
            $t = new HygieneTaskTemplate(['name' => $name, 'checklist' => [['key' => 'k1', 'label' => 'Sols']], 'frequency' => 'daily', 'due_time' => '09:00', 'sort_order' => $order, 'is_active' => true]);
            $t->branch_id = 1;
            $t->created_at = Carbon::parse('2026-10-01');
            $t->save();
        }
        // l'ordre ne doit pas dépendre du hasard de l'index (SQLite et MySQL ne le garantissent pas sans ORDER BY) : on exige la clause elle-même
        $sql = [];
        \DB::listen(function ($q) use (&$sql) { if (str_contains($q->sql, 'hygiene_task_templates') && str_contains($q->sql, 'is_active')) $sql[] = $q->sql; });
        $text = $this->overview()[0]['tasks-late-many']['text'];
        $ordered = array_filter($sql, fn ($q) => (bool) preg_match('/order by [`"]?sort_order[`"]?[^,]*,\s*[`"]?id[`"]?/i', $q));
        $this->assertNotEmpty($ordered, 'la lecture des fiches pour les rappels doit trier par (sort_order, id), comme l\'écran');
        $this->assertSame('5 nettoyages en retard : « Première », « Deuxième », « Troisième » et 2 autres.', $text);
    }

    public function test_nettoyage_en_retard_puis_fait(): void
    {
        $this->staff();
        $t = new HygieneTaskTemplate(['name' => 'Cuisine et labo', 'checklist' => [['key' => 'k1', 'label' => 'Sols']], 'frequency' => 'daily', 'due_time' => '09:00', 'sort_order' => 10, 'is_active' => true]);
        $t->branch_id = 1;
        $t->created_at = Carbon::parse('2026-10-01');
        $t->save();
        [$reminders] = $this->overview();
        $this->assertSame('Nettoyage en retard : « Cuisine et labo » (prévu à 09:00).', $reminders["tasks-late-{$t->id}"]['text']);

        $c = new HygieneTaskCompletion(['template_id' => $t->id, 'task_name' => $t->name, 'due_date' => '2026-10-20', 'items' => [], 'items_total' => 1, 'items_done' => 1, 'complete' => true,
            'completed_at' => now(), 'operator_name' => 'Karim Benali']);
        $c->branch_id = 1;
        $c->save();
        [$reminders] = $this->overview();
        $this->assertCount(0, $reminders);
    }

    public function test_huile_a_changer_a_prevoir_puis_changee(): void
    {
        $this->staff();
        $f = $this->equipment('Friteuse 1', 'fryer');
        $this->oil($f, 'test', 'warn', 22.5, '2026-10-20 08:00');
        [$reminders, $tiles] = $this->overview();
        $this->assertSame('todo', $reminders["oil-change-{$f->id}"]['level']);
        $this->assertSame('Huile « Friteuse 1 » : 22,5 % de composés polaires — changement à prévoir.', $reminders["oil-change-{$f->id}"]['text']);

        $this->oil($f, 'test', 'alert', 27.0, '2026-10-20 09:00');
        [$reminders, $tiles] = $this->overview();
        $this->assertSame('alert', $reminders["oil-change-{$f->id}"]['level']);
        $this->assertStringContainsString('au-dessus de la limite légale — à changer', $reminders["oil-change-{$f->id}"]['text']);
        $this->assertSame('alert', $tiles['oil']['status']);

        // huile changée APRÈS ce test : plus de rappel
        $this->oil($f, 'change', 'ok', null, '2026-10-20 09:30');
        [$reminders] = $this->overview();
        $this->assertArrayNotHasKey("oil-change-{$f->id}", $reminders->all());
    }

    public function test_huile_changee_declaree_sur_le_test_solde_le_rappel_la_tuile_et_l_ecran(): void
    {
        $this->staff();
        $f = $this->equipment('Friteuse 1', 'fryer');
        // test à 28 % : l'opérateur déclare « Huile changée » (aucune vidange saisie à part)
        $this->oil($f, 'test', 'alert', 28.0, '2026-10-20 09:00', 'oil_changed');
        [$reminders, $tiles] = $this->overview();
        $this->assertArrayNotHasKey("oil-change-{$f->id}", $reminders->all(), 'plus de « à changer » ni d\'e-mail de relance : l\'huile a été changée');
        $this->assertSame('ok', $tiles['oil']['status']);
        $this->assertSame(0, $tiles['oil']['counts']['alerts']);
        $screen = collect($this->getJson('/api/admin/hygiene/oil/today')->json('data'))->first();
        $this->assertSame('ok', $screen['state'], 'même verdict sur l\'écran Huile');
        // les autres actions ne solderaient rien : l'écart reste à traiter
        $g = $this->equipment('Friteuse 2', 'fryer');
        $this->oil($g, 'test', 'alert', 28.0, '2026-10-20 09:05', 'fryer_stopped');
        [$reminders, $tiles] = $this->overview();
        $this->assertArrayHasKey("oil-change-{$g->id}", $reminders->all());
        $this->assertSame('alert', $tiles['oil']['status']);
    }

    public function test_huile_changee_ensuite_solde_aussi_la_tuile_pas_seulement_le_rappel(): void
    {
        $this->staff();
        $f = $this->equipment('Friteuse 1', 'fryer');
        $this->oil($f, 'test', 'alert', 28.0, '2026-10-20 09:00', 'fryer_stopped');
        $this->oil($f, 'change', 'ok', null, '2026-10-20 09:30');
        [$reminders, $tiles] = $this->overview();
        $this->assertArrayNotHasKey("oil-change-{$f->id}", $reminders->all());
        $this->assertNotSame('alert', $tiles['oil']['status'], 'tuile rouge sans rappel : « 1 écart » alors que l\'huile est changée');
        $screen = collect($this->getJson('/api/admin/hygiene/oil/today')->json('data'))->first();
        $this->assertSame('ok', $screen['state']);
    }

    public function test_huile_a_surveiller_n_est_pas_une_tuile_verte(): void
    {
        $this->staff();
        $f = $this->equipment('Friteuse 1', 'fryer');
        $this->oil($f, 'test', 'warn', 22.0, '2026-10-20 09:00');
        [, $tiles] = $this->overview();
        $this->assertSame('todo', $tiles['oil']['status'], 'à surveiller (écran ambre) : la tuile ne dit pas « conforme »');
        $this->assertSame('1 à surveiller', $tiles['oil']['detail']);
    }

    public function test_une_etiquette_reservee_sans_verdict_depuis_plus_de_dix_minutes_est_surveillee_comme_l_ecran_dlc(): void
    {
        $this->staff();
        foreach ([['Ancienne', 30], ['Récente', 5]] as [$name, $minutes]) {
            $l = new \App\Models\LabelPrintLog(['kind' => 'dlc', 'status' => 'claimed', 'copies' => 1, 'product_name' => $name, 'opened_at' => now()->subHours(3), 'expires_at' => now()->subHours(2), 'claimed_at' => now()->subMinutes($minutes)]);
            $l->branch_id = 1;
            $l->save();
        }
        [$reminders, $tiles] = $this->overview();
        $this->assertSame('1 étiquette DLC dépassée : à jeter, ou à marquer « utilisé ».', $reminders->first(fn ($r) => $r['domain'] === 'dlc')['text'], 'seule la réservée de plus de 10 min est comptée, comme l\'écran');
        $this->assertSame('alert', $tiles['dlc']['status']);
    }

    public function test_test_d_huile_du_jour_non_fait_seulement_si_le_restaurant_teste(): void
    {
        $this->staff();
        $f = $this->equipment('Friteuse 1', 'fryer');
        $this->now('2026-10-20 16:00');
        [$reminders] = $this->overview();
        $this->assertArrayNotHasKey('oil-test-late', $reminders->all(), 'aucun test jamais fait : pas de rappel');

        $this->oil($f, 'test', 'ok', 12.0, '2026-10-18 10:00');
        [$reminders] = $this->overview();
        $this->assertSame('Test d’huile non fait : Friteuse 1.', $reminders['oil-test-late']['text']);
        $this->assertSame('todo', $reminders['oil-test-late']['level']);

        $this->oil($f, 'test', 'ok', 13.0, '2026-10-20 12:00');
        [$reminders] = $this->overview();
        $this->assertArrayNotHasKey('oil-test-late', $reminders->all());
    }

    public function test_dlc_depassees_comptees(): void
    {
        $this->staff();
        foreach ([now()->subHours(3), now()->subDay(), now()->addHours(5)] as $i => $exp) {
            $l = new LabelPrintLog(['kind' => 'dlc', 'status' => 'printed', 'copies' => 1, 'product_name' => "P$i", 'opened_at' => now()->subDays(2), 'expires_at' => $exp]);
            $l->branch_id = 1;
            $l->save();
        }
        [$reminders, $tiles] = $this->overview();
        $this->assertSame('2 étiquettes DLC dépassées : à jeter, ou à marquer « utilisé ».', $reminders['dlc-expired-2']['text']);
        $this->assertSame('/admin/labels/dlc', $reminders['dlc-expired-2']['route']);
        $this->assertSame('alert', $tiles['dlc']['status']);
    }

    public function test_rien_trace_depuis_deux_jours(): void
    {
        $this->staff();
        [$reminders] = $this->overview();
        $this->assertArrayNotHasKey('trace-silent', $reminders->all(), 'la traçabilité ne sert pas encore');

        $mk = function (string $at, bool $voided = false) {
            $e = new HygieneTraceEntry(['product_name' => 'Yaourt', 'dlc_kind' => 'dlc', 'status' => 'ok', 'traced_at' => Carbon::parse($at, config('app.timezone')), 'operator_name' => 'Karim',
                'voided_at' => $voided ? now() : null, 'void_reason' => $voided ? 'erreur' : null]);
            $e->branch_id = 1;
            $e->save();
        };
        $mk('2026-10-19 09:00'); // hier : 1 jour, sous le seuil
        [$reminders] = $this->overview();
        $this->assertArrayNotHasKey('trace-silent', $reminders->all());

        $this->now('2026-10-22 10:00'); // 3 jours après
        [$reminders, $tiles] = $this->overview();
        $this->assertSame('Aucun produit tracé depuis 3 jours.', $reminders['trace-silent']['text']);
        $this->assertSame('todo', $reminders['trace-silent']['level']);
        $this->assertNotSame('alert', $tiles['trace']['status'], 'soft : à faire, pas une alerte');

        $mk('2026-10-22 08:00', true); // un relevé annulé ne remet rien à zéro
        [$reminders] = $this->overview();
        $this->assertArrayHasKey('trace-silent', $reminders->all());
        $mk('2026-10-22 08:30');
        [$reminders] = $this->overview();
        $this->assertArrayNotHasKey('trace-silent', $reminders->all());
    }

    public function test_chaque_branche_ne_voit_que_ses_rappels_et_chaque_tuile_a_son_bouton(): void
    {
        $this->equipment('Frigo autre branche', 'fridge', 2);
        $this->staff(1);
        $this->now('2026-10-20 12:00');
        [$reminders, $tiles] = $this->overview();
        $this->assertCount(0, $reminders);

        $this->staff(2);
        [$reminders] = $this->overview();
        $this->assertSame('Relevé du matin non fait : Frigo autre branche.', $reminders['temperature-late-morning']['text']);

        // les boutons d'action des tuiles : un geste, jamais « Ouvrir »
        $this->staff(1);
        $this->assertSame('Configurer', $tiles['temperature']['cta']);
        $this->assertSame('Imprimer une étiquette', $tiles['dlc']['cta']);
        $this->assertSame('Réceptionner une livraison', $tiles['reception']['cta']);
        $this->assertSame('Tracer un produit', $tiles['trace']['cta']);
    }

    // ───────────── relecture adverse (2026-10-17) : fausses alertes ─────────────

    public function test_un_releve_unique_a_16_heures_ne_laisse_pas_une_alerte_du_matin_permanente(): void
    {
        $this->staff();
        $f = $this->equipment('Frigo 1');
        $this->now('2026-10-20 16:00');
        // aucun relevé encore : le matin est bien en retard
        [$reminders] = $this->overview();
        $this->assertArrayHasKey('temperature-late-morning', $reminders->all());
        // relevé pris à 16 h (période « soir » par l'horloge) : le relevé DU JOUR est fait, plus d'alerte
        $this->reading($f, '2026-10-20 16:00', 'evening');
        [$reminders, $tiles] = $this->overview();
        $this->assertArrayNotHasKey('temperature-late-morning', $reminders->all());
        $this->assertNotSame('alert', $tiles['temperature']['status']);
    }

    public function test_ce_qui_est_cree_apres_l_heure_du_rappel_n_etait_pas_encore_attendu(): void
    {
        $this->staff();
        $this->now('2026-10-20 14:00');
        $e = new HygieneEquipment(['name' => 'Frigo neuf', 'kind' => 'fridge', 'min_c' => 0, 'max_c' => 4, 'sort_order' => 10, 'is_active' => true]);
        $e->branch_id = 1; // créé à 14 h, rappel du matin à 11 h 30
        $e->save();
        $t = new HygieneTaskTemplate(['name' => 'Hotte', 'checklist' => [['key' => 'k1', 'label' => 'Filtres']], 'frequency' => 'daily', 'due_time' => '10:00', 'sort_order' => 10, 'is_active' => true]);
        $t->branch_id = 1; // créée à 14 h, prévue à 10 h
        $t->save();
        $f = new HygieneEquipment(['name' => 'Friteuse neuve', 'kind' => 'fryer', 'sort_order' => 10, 'is_active' => true]);
        $f->branch_id = 1;
        $f->save();
        $this->oil($f, 'test', 'ok', 10.0, '2026-10-18 10:00');
        [$reminders] = $this->overview();
        $this->assertCount(0, $reminders, json_encode($reminders->all(), JSON_UNESCAPED_UNICODE));
        // le lendemain, tout est bien attendu
        $this->now('2026-10-21 16:00');
        [$reminders] = $this->overview();
        $this->assertArrayHasKey('temperature-late-morning', $reminders->all());
        $this->assertArrayHasKey("tasks-late-{$t->id}", $reminders->all());
        $this->assertArrayHasKey('oil-test-late', $reminders->all());
    }

    public function test_un_bain_marie_n_est_pas_exige_au_releve_du_matin(): void
    {
        $this->staff();
        $this->equipment('Frigo 1');
        $this->equipment('Bain-marie', 'hot_holding');
        $this->now('2026-10-20 12:00');
        [$reminders] = $this->overview();
        $this->assertSame('Relevé du matin non fait : Frigo 1.', $reminders['temperature-late-morning']['text']);
    }

    public function test_un_bain_trop_chaud_avec_peu_de_polaires_dit_le_vrai_motif(): void
    {
        $this->staff();
        $f = $this->equipment('Friteuse 1', 'fryer');
        $o = new HygieneOilControl(['equipment_id' => $f->id, 'equipment_name' => $f->name, 'type' => 'test', 'polar_pct' => 10.0, 'bath_temp_c' => 190.0, 'status' => 'alert',
            'reasons' => 'bath_hot', 'performed_at' => Carbon::parse('2026-10-20 08:00', config('app.timezone')), 'operator_name' => 'Karim']);
        $o->branch_id = 1;
        $o->save();
        [$reminders] = $this->overview();
        $this->assertSame('alert', $reminders["oil-change-{$f->id}"]['level']);
        $this->assertSame('Huile « Friteuse 1 » : bain à 190 °C, trop chaud — à régler.', $reminders["oil-change-{$f->id}"]['text']);
    }
}
