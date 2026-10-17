<?php

namespace Tests\Feature\Hygiene;

use App\Mail\HygieneAlertMail;
use App\Models\Branch;
use App\Models\HygieneAlertDispatch;
use App\Models\HygieneAlertSetting;
use App\Models\HygieneEquipment;
use App\Models\User;
use App\Services\Hygiene\AlertNotifier;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * [CAYENNE HYGIÈNE 2026-10-18 · rapport Komia P0 « alertes hors écran »] Les alertes d'hygiène par e-mail : rien ne part tant que le
 * responsable ne l'a pas voulu, jamais en rafale, jamais hors plage, et chaque envoi est au journal.
 */
class AlertNotifierTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/admin/hygiene/alerts';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Branch::factory()->create(['id' => 1]);
        Branch::factory()->create(['id' => 2]);
        config([
            'hygiene.branch_id' => 1, 'mail.default' => 'smtp',
            'hygiene.reminders.temperature.morning' => '11:30', 'hygiene.reminders.temperature.evening' => '22:30',
            'hygiene.reminders.oil_test' => '15:00', 'hygiene.reminders.trace_silent_days' => 2,
        ]);
        Mail::fake();
        $this->moment('2026-10-20 12:00');
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

    /** Un frigo existant depuis 3 semaines, jamais relevé : « Relevé du matin non fait » à partir de 11 h 30. */
    private function lateFridge(string $name = 'Frigo 1'): void
    {
        $e = new HygieneEquipment(['name' => $name, 'kind' => 'fridge', 'min_c' => 0, 'max_c' => 4, 'sort_order' => 10, 'is_active' => true]);
        $e->branch_id = 1;
        $e->created_at = Carbon::parse('2026-10-01');
        $e->save();
    }

    private function settings(array $over = []): void
    {
        HygieneAlertSetting::withoutGlobalScopes()->updateOrCreate(['branch_id' => 1], $over + [
            'enabled' => true, 'recipients' => ['chef@example.test'], 'domains' => null,
            'send_from' => '09:00', 'send_until' => '23:30', 'repeat_minutes' => 60, 'max_sends' => 3,
        ]);
    }

    private function passage(): array
    {
        return app(AlertNotifier::class)->run();
    }

    // ───────────── rien ne part sans volonté du responsable ─────────────

    public function test_desactive_par_defaut_rien_ne_part(): void
    {
        $this->lateFridge();
        $this->assertSame('disabled', $this->passage()['status']);
        Mail::assertNothingSent();
        $this->assertSame(0, HygieneAlertDispatch::withoutGlobalScopes()->count());
    }

    public function test_active_sans_destinataire_rien_ne_part(): void
    {
        $this->lateFridge();
        $this->settings(['recipients' => []]);
        $this->assertSame('no_recipients', $this->passage()['status']);
        Mail::assertNothingSent();
    }

    public function test_rien_a_signaler_rien_ne_part(): void
    {
        $this->settings(); // aucun équipement : aucun rappel
        $this->assertSame('nothing_due', $this->passage()['status']);
        Mail::assertNothingSent();
    }

    // ───────────── l'envoi ─────────────

    public function test_un_rappel_en_retard_part_une_fois_avec_le_meme_texte_que_l_accueil(): void
    {
        $this->lateFridge();
        $this->settings();

        $r = $this->passage();

        $this->assertSame(['status' => 'done', 'due' => 1, 'sent' => 1, 'failed' => 0], $r);
        Mail::assertSent(HygieneAlertMail::class, 1);
        Mail::assertSent(HygieneAlertMail::class, function (HygieneAlertMail $m) {
            $this->assertTrue($m->hasTo('chef@example.test'));
            $this->assertStringContainsString('Frigo 1', $m->items[0]['text']);
            $this->assertStringContainsString('1 à traiter', $m->build()->subject);

            return true;
        });
        $row = HygieneAlertDispatch::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(['temperature-late-morning', 'sent', 'chef@example.test', 'mail'], [$row->reminder_key, $row->status, $row->recipient, $row->channel]);
    }

    public function test_pas_de_rafale_le_meme_rappel_n_est_pas_renvoye_avant_le_delai(): void
    {
        $this->lateFridge();
        $this->settings();
        $this->passage();

        $this->moment('2026-10-20 12:05');
        $this->assertSame('nothing_due', $this->passage()['status']);
        $this->moment('2026-10-20 12:59');
        $this->assertSame('nothing_due', $this->passage()['status']);
        Mail::assertSent(HygieneAlertMail::class, 1);
    }

    public function test_relance_apres_le_delai_puis_s_arrete_au_maximum(): void
    {
        $this->lateFridge();
        $this->settings(['max_sends' => 3, 'repeat_minutes' => 60]);

        $this->passage();                       // 12:00
        $this->moment('2026-10-20 13:00'); $this->passage();   // relance 1
        $this->moment('2026-10-20 14:00'); $this->passage();   // relance 2
        $this->moment('2026-10-20 15:00');
        $this->assertSame('nothing_due', $this->passage()['status']); // plafond atteint : 3 envois

        Mail::assertSent(HygieneAlertMail::class, 3);
    }

    public function test_le_lendemain_le_compteur_repart(): void
    {
        $this->lateFridge();
        $this->settings(['max_sends' => 1]);
        $this->passage();
        $this->moment('2026-10-21 12:00');
        $this->assertSame('done', $this->passage()['status']);
        Mail::assertSent(HygieneAlertMail::class, 2);
    }

    public function test_le_rappel_resolu_ne_part_plus(): void
    {
        $this->lateFridge();
        $this->settings();
        $this->passage();
        HygieneEquipment::query()->update(['is_active' => false]); // plus rien à relever
        $this->moment('2026-10-20 13:30');
        $this->assertSame('nothing_due', $this->passage()['status']);
        Mail::assertSent(HygieneAlertMail::class, 1);
    }

    public function test_hors_plage_d_envoi_rien_ne_part_meme_si_c_est_en_retard(): void
    {
        $this->lateFridge();
        $this->settings(['send_from' => '09:00', 'send_until' => '11:00']);
        $this->assertSame('outside_window', $this->passage()['status']);
        $this->moment('2026-10-20 03:00');
        $this->assertSame('outside_window', $this->passage()['status']);
        Mail::assertNothingSent();
    }

    public function test_les_types_non_voulus_sont_filtres(): void
    {
        $this->lateFridge();
        $this->settings(['domains' => ['oil', 'dlc']]); // pas la température
        $this->assertSame('nothing_due', $this->passage()['status']);
        Mail::assertNothingSent();
    }

    public function test_un_seul_courriel_recapitulatif_par_destinataire(): void
    {
        $this->lateFridge('Frigo 1');
        // un nettoyage prévu à 00:01, jamais fait : second rappel, d'un autre domaine
        \DB::table('hygiene_task_templates')->insert([
            'branch_id' => 1, 'name' => 'Hotte et filtres', 'zone' => 'Cuisine', 'how_to' => 'Dégraisser', 'checklist' => json_encode([['key' => 'k1', 'label' => 'Filtres']]),
            'frequency' => 'daily', 'due_time' => '00:01', 'sort_order' => 10, 'is_active' => 1, 'created_at' => now()->subDays(3), 'updated_at' => now()->subDays(3),
        ]);
        $this->settings(['recipients' => ['a@example.test', 'b@example.test']]);

        $r = $this->passage();

        $this->assertSame(['status' => 'done', 'due' => 2, 'sent' => 2, 'failed' => 0], $r);
        Mail::assertSent(HygieneAlertMail::class, 2); // un par destinataire, pas un par rappel
        Mail::assertSent(HygieneAlertMail::class, fn (HygieneAlertMail $m) => count($m->items) === 2 && str_contains($m->build()->subject, '2 à traiter'));
        $this->assertSame(4, HygieneAlertDispatch::withoutGlobalScopes()->where('status', 'sent')->count()); // 2 rappels × 2 destinataires au journal
    }

    public function test_un_rappel_sans_urgence_n_est_jamais_envoye_par_courriel(): void
    {
        // test d'huile pratiqué ces derniers jours mais pas aujourd'hui, après 15 h : rappel de niveau « à faire », pas « alerte »
        $f = new HygieneEquipment(['name' => 'Friteuse 1', 'kind' => 'fryer', 'sort_order' => 20, 'is_active' => true]);
        $f->branch_id = 1;
        $f->created_at = Carbon::parse('2026-10-01');
        $f->save();
        \DB::table('hygiene_oil_controls')->insert([
            'branch_id' => 1, 'equipment_id' => $f->id, 'equipment_name' => 'Friteuse 1', 'type' => 'test', 'polar_pct' => 12, 'status' => 'ok',
            'performed_at' => Carbon::parse('2026-10-18 15:00', config('app.timezone')), 'timezone' => 'Europe/Paris', 'operator_name' => 'X', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->moment('2026-10-20 16:00');
        $this->assertContains('oil-test-late', array_column((new \App\Services\Hygiene\Reminders)->all(), 'key')); // le rappel existe bien…
        $this->settings();

        $this->assertSame('nothing_due', $this->passage()['status']);  // …mais il n'est qu'à l'écran
        Mail::assertNothingSent();
    }

    // ───────────── l'échec est dit, pas avalé ─────────────

    public function test_un_envoi_en_echec_est_journalise_sans_planter_et_n_est_pas_marque_comme_envoye(): void
    {
        $this->lateFridge();
        $this->settings();
        Mail::shouldReceive('to')->andThrow(new \RuntimeException("Connection could not be established with host smtp.invalid :stream_socket_client()"));

        $r = $this->passage();

        $this->assertSame(['status' => 'done', 'due' => 1, 'sent' => 0, 'failed' => 1], $r);
        $row = HygieneAlertDispatch::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('failed', $row->status);
        $this->assertNull($row->sent_at);
        $this->assertStringContainsString('Connection could not be established', (string) $row->error);
    }

    public function test_apres_trop_d_echecs_on_renonce_pour_la_journee(): void
    {
        $this->lateFridge();
        $this->settings();
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));
        for ($i = 0; $i < 3; $i++) { $this->passage(); }
        $this->assertSame('nothing_due', $this->passage()['status']);
        $this->assertSame(3, HygieneAlertDispatch::withoutGlobalScopes()->where('status', 'failed')->count());
    }

    // ───────────── le message d'essai ─────────────

    public function test_le_message_d_essai_part_et_est_marque_comme_essai(): void
    {
        $this->settings();
        $r = app(AlertNotifier::class)->sendTest();
        $this->assertSame(['sent' => 1, 'failed' => 0], $r);
        Mail::assertSent(HygieneAlertMail::class, fn (HygieneAlertMail $m) => $m->isTest && str_starts_with($m->build()->subject, 'Test'));
        $this->assertSame('test', HygieneAlertDispatch::withoutGlobalScopes()->firstOrFail()->status);
        // un essai ne compte jamais comme une alerte déjà envoyée
        $this->lateFridge();
        $this->assertSame('done', $this->passage()['status']);
    }

    public function test_le_canal_mail_n_est_pas_pret_avec_le_pilote_log_ou_array(): void
    {
        foreach (['log', 'array'] as $driver) {
            config(['mail.default' => $driver]);
            $this->assertFalse(AlertNotifier::mailReady());
        }
        config(['mail.default' => 'smtp']);
        $this->assertTrue(AlertNotifier::mailReady());
    }

    public function test_la_commande_planifiee_existe_et_ne_fait_rien_si_desactive(): void
    {
        $this->artisan('hygiene:notify')->expectsOutputToContain('disabled')->assertExitCode(0);
        $this->assertContains('hygiene:notify', collect(\Artisan::all())->keys()->all());
    }
}
