<?php

namespace Tests\Feature\Hygiene;

use App\Mail\HygieneAlertMail;
use App\Models\Branch;
use App\Models\HygieneAlertSetting;
use App\Models\HygieneEquipment;
use App\Models\HygieneTemperatureReading;
use App\Models\User;
use App\Services\Hygiene\AlertNotifier;
use App\Services\Hygiene\Reminders;
use App\Services\Hygiene\TemperatureFollowUp;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * [CAYENNE HYGIÈNE 2026-10-18 · rapport Komia P1 « relevé de suivi après écart »] Un écart « réglé » (porte refermée, dégivrage, plats
 * réchauffés, nouvelle mesure) n'est clos que par une NOUVELLE MESURE : attendue 30 min après, rappelée en urgent tant qu'elle manque.
 */
class TemperatureFollowUpTest extends TestCase
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
        config(['hygiene.branch_id' => 1, 'hygiene.followup.minutes' => 30, 'mail.default' => 'smtp',
            'hygiene.reminders.temperature.morning' => '23:00', 'hygiene.reminders.temperature.evening' => '23:30']);
        $this->moment('2026-10-20 10:00');
        $u = User::factory()->create(['branch_id' => 1, 'name' => 'Karim Test']);
        $u->givePermissionTo('pos-orders');
        $u->assignRole('POS Operator');
        Sanctum::actingAs($u, ['*']);
        $this->postJson(self::BASE.'/equipment/quick-setup', ['fridge' => 2, 'freezer' => 0, 'hot_holding' => 1])->assertCreated();
        foreach (HygieneEquipment::query()->get() as $e) { $e->created_at = Carbon::parse('2026-10-01'); $e->save(); }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function moment(string $when): void
    {
        Carbon::setTestNow(Carbon::parse($when, config('app.timezone')));
    }

    private function eq(string $name): int
    {
        return (int) HygieneEquipment::query()->where('name', $name)->value('id');
    }

    private function take(string $name, float $value, ?string $action = null): void
    {
        $this->postJson(self::BASE.'/readings', ['readings' => [['equipment_id' => $this->eq($name), 'value' => $value] + ($action ? ['corrective_action' => $action] : [])]])->assertCreated();
    }

    private function followup(string $name): ?array
    {
        $item = collect($this->getJson(self::BASE.'/today')->assertOk()->json('data'))->firstWhere('id', $this->eq($name));

        return $item['followup'];
    }

    private function reminderKeys(): array
    {
        return array_column((new Reminders)->all(), 'key');
    }

    /** Le rappel de suite d'un équipement (sa clé porte aussi l'identifiant du relevé d'écart : un nouvel écart = un nouveau rappel). */
    private function followupReminder(string $name): ?array
    {
        return collect((new Reminders)->all())->first(fn ($i) => str_starts_with($i['key'], 'temperature-followup-'.$this->eq($name).'-'));
    }

    public function test_un_ecart_regle_par_la_porte_refermee_attend_une_nouvelle_mesure_dans_les_30_minutes(): void
    {
        $this->take('Frigo 1', 9.0, 'door_closed');

        $this->moment('2026-10-20 10:20');
        $f = $this->followup('Frigo 1');
        $this->assertNotNull($f);
        $this->assertFalse($f['overdue']);
        $this->assertSame(30, $f['minutes']);
        $this->assertStringContainsString('2026-10-20T10:30', $f['due_at']);
        $this->assertNull($this->followupReminder('Frigo 1'), 'pas encore dû : pas de rappel');
    }

    public function test_passe_le_delai_un_rappel_urgent_nomme_l_equipement_la_valeur_et_les_heures(): void
    {
        $this->take('Frigo 1', 9.0, 'recheck');

        $this->moment('2026-10-20 10:31');
        $this->assertTrue($this->followup('Frigo 1')['overdue']);
        $item = $this->followupReminder('Frigo 1');
        $this->assertNotNull($item);
        $this->assertSame(['temperature', 'alert', '/admin/hygiene/temperature'], [$item['domain'], $item['level'], $item['route']]);
        $this->assertSame('Contrôle de suite à faire : « Frigo 1 » était à 9 °C à 10:00 — nouvelle mesure attendue depuis 10:30.', $item['text']);
    }

    public function test_une_nouvelle_mesure_conforme_ou_non_cloture_l_attente(): void
    {
        $this->take('Frigo 1', 9.0, 'recheck');
        $this->moment('2026-10-20 10:40');
        $this->assertNotNull($this->followup('Frigo 1'));

        $this->take('Frigo 1', 3.0);
        $this->assertNull($this->followup('Frigo 1'));
        $this->assertNull($this->followupReminder('Frigo 1'));
    }

    public function test_un_nouvel_ecart_ouvre_son_propre_suivi_a_partir_de_sa_propre_heure(): void
    {
        $this->take('Frigo 1', 9.0, 'recheck');
        $this->moment('2026-10-20 10:40');
        $this->take('Frigo 1', 8.0, 'door_closed'); // la nouvelle mesure est encore hors seuil

        $this->moment('2026-10-20 11:00');
        $f = $this->followup('Frigo 1');
        $this->assertFalse($f['overdue']);
        $this->assertStringContainsString('2026-10-20T11:10', $f['due_at']);
    }

    public function test_les_actions_qui_ne_disent_pas_que_c_est_regle_ne_demandent_pas_de_suivi(): void
    {
        foreach (['technician', 'products_discarded', 'products_moved'] as $action) {
            HygieneTemperatureReading::query()->delete();
            $this->take('Frigo 1', 9.0, $action);
            $this->assertNull($this->followup('Frigo 1'), $action);
        }
    }

    public function test_le_chaud_est_suivi_aussi(): void
    {
        $this->take('Bain-marie', 55.0, 'reheated');
        $this->moment('2026-10-20 10:45');
        $this->assertTrue($this->followup('Bain-marie')['overdue']);
        $this->assertNotNull($this->followupReminder('Bain-marie'));
    }

    public function test_le_releve_d_ecart_annule_pour_erreur_supprime_l_attente(): void
    {
        $this->take('Frigo 1', 9.0, 'recheck');
        $id = HygieneTemperatureReading::query()->firstOrFail()->id;
        $this->postJson(self::BASE."/readings/{$id}/void", ['reason' => 'faute de frappe'])->assertOk();
        $this->moment('2026-10-20 10:45');
        $this->assertNull($this->followup('Frigo 1'));
        $this->assertNull($this->followupReminder('Frigo 1'));
    }

    public function test_la_mesure_d_un_autre_equipement_ne_cloture_pas_l_attente(): void
    {
        $this->take('Frigo 1', 9.0, 'recheck');
        $this->moment('2026-10-20 10:40');
        $this->take('Frigo 2', 3.0);
        $this->assertNotNull($this->followup('Frigo 1'));
    }

    public function test_un_vieil_ecart_de_plus_de_24_heures_n_est_plus_reclame(): void
    {
        $this->take('Frigo 1', 9.0, 'recheck');
        $this->moment('2026-10-21 11:00'); // 25 h plus tard, aucune nouvelle mesure
        $this->assertNull($this->followup('Frigo 1'));
    }

    public function test_le_delai_est_reglable(): void
    {
        config(['hygiene.followup.minutes' => 10]);
        $this->take('Frigo 1', 9.0, 'recheck');
        $this->moment('2026-10-20 10:11');
        $this->assertTrue($this->followup('Frigo 1')['overdue']);
    }

    public function test_les_actions_a_suivi_sont_annoncees_a_l_ecran_pour_prevenir_la_personne(): void
    {
        $actions = collect($this->getJson(self::BASE.'/today')->json('meta.corrective_actions'))->keyBy('key');
        $this->assertTrue($actions['recheck']['followup']);
        $this->assertTrue($actions['door_closed']['followup']);
        $this->assertArrayNotHasKey('followup', $actions['technician']);
    }

    public function test_le_rappel_de_suite_part_aussi_par_courriel_aux_responsables(): void
    {
        Mail::fake();
        HygieneAlertSetting::withoutGlobalScopes()->create(['branch_id' => 1, 'enabled' => true, 'recipients' => ['chef@example.test'], 'domains' => null,
            'send_from' => '09:00', 'send_until' => '23:30', 'repeat_minutes' => 60, 'max_sends' => 3]);
        $this->take('Frigo 1', 9.0, 'recheck');

        $this->moment('2026-10-20 10:20');
        $this->assertSame('nothing_due', app(AlertNotifier::class)->run()['status']); // pas encore dû

        $this->moment('2026-10-20 10:35');
        $this->assertSame('done', app(AlertNotifier::class)->run()['status']);
        Mail::assertSent(HygieneAlertMail::class, fn (HygieneAlertMail $m) => str_contains($m->items[0]['text'], 'Contrôle de suite à faire'));
    }

    public function test_un_second_ecart_le_meme_jour_est_rappele_meme_apres_le_plafond_d_envois_du_premier(): void
    {
        Mail::fake();
        HygieneAlertSetting::withoutGlobalScopes()->create(['branch_id' => 1, 'enabled' => true, 'recipients' => ['chef@example.test'], 'domains' => null,
            'send_from' => '09:00', 'send_until' => '23:30', 'repeat_minutes' => 60, 'max_sends' => 1]);
        $this->take('Frigo 1', 9.0, 'recheck');             // épisode 1 : écart à 10:00
        $this->moment('2026-10-20 10:35');
        $this->assertSame('done', app(AlertNotifier::class)->run()['status']);
        $this->moment('2026-10-20 10:40');
        $this->take('Frigo 1', 3.0);                         // résolu
        $this->moment('2026-10-20 14:00');
        $this->take('Frigo 1', 9.5, 'recheck');             // épisode 2 : nouvel écart à 14:00

        $this->moment('2026-10-20 14:35');
        $this->assertSame('done', app(AlertNotifier::class)->run()['status']); // le plafond d'envois de l'épisode 1 ne le fait pas taire
        Mail::assertSent(HygieneAlertMail::class, 2);
    }

    public function test_le_service_ne_rend_rien_sans_equipement(): void
    {
        $this->assertSame([], TemperatureFollowUp::pending([]));
    }
}
