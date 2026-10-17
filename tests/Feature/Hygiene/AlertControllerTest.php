<?php

namespace Tests\Feature\Hygiene;

use App\Mail\HygieneAlertMail;
use App\Models\Branch;
use App\Models\HygieneAlertSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** [CAYENNE HYGIÈNE 2026-10-18] Réglage des alertes e-mail : réservé au responsable (`settings`), validé côté serveur, honnête sur ce qui peut partir. */
class AlertControllerTest extends TestCase
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
        config(['hygiene.branch_id' => 1, 'mail.default' => 'smtp']);
        Mail::fake();
    }

    private function manager(): User
    {
        $u = User::factory()->create(['branch_id' => 1, 'name' => 'Responsable']);
        $u->givePermissionTo(['pos-orders', 'settings']);
        $u->assignRole('Branch Manager');
        Sanctum::actingAs($u, ['*']);

        return $u;
    }

    private function payload(array $over = []): array
    {
        return $over + [
            'enabled' => true, 'recipients' => ['chef@example.test'], 'domains' => ['temperature', 'tasks'],
            'send_from' => '09:00', 'send_until' => '23:00', 'repeat_minutes' => 60, 'max_sends' => 3,
        ];
    }

    public function test_sans_authentification_et_sans_la_permission_settings_c_est_refuse(): void
    {
        $this->getJson(self::BASE)->assertUnauthorized();

        $tablet = User::factory()->create(['branch_id' => 1]);
        $tablet->givePermissionTo('pos-orders'); // le personnel qui tient la tablette, pas le responsable
        $tablet->assignRole('POS Operator');
        Sanctum::actingAs($tablet, ['*']);
        $this->getJson(self::BASE)->assertForbidden();
        $this->putJson(self::BASE, $this->payload())->assertForbidden();
        $this->postJson(self::BASE.'/test')->assertForbidden();
        $this->assertSame(0, HygieneAlertSetting::withoutGlobalScopes()->count());
    }

    public function test_par_defaut_tout_est_desactive_et_on_voit_qui_peut_etre_prevenu(): void
    {
        $this->manager();
        $sarah = User::factory()->create(['branch_id' => 1, 'name' => 'Sarah Lopez', 'email' => 'sarah@example.test']);
        $sarah->assignRole('Chef');
        $client = User::factory()->create(['branch_id' => 1, 'name' => 'Un Client', 'email' => 'client@example.test']);
        $client->assignRole('Customer');

        $r = $this->getJson(self::BASE)->assertOk();

        $r->assertJsonPath('data.settings.enabled', false)->assertJsonPath('data.settings.recipients', [])
            ->assertJsonPath('meta.mail_ready', true);
        $names = collect($r->json('data.staff'))->pluck('name')->all();
        $this->assertContains('Sarah Lopez', $names);
        $this->assertNotContains('Un Client', $names);
        $this->assertCount(5, $r->json('meta.domains'));
    }

    public function test_enregistrer_active_et_normalise_les_adresses(): void
    {
        $this->manager();

        $this->putJson(self::BASE, $this->payload(['recipients' => [' Chef@Example.test ', 'chef@example.test', 'autre@example.test']]))
            ->assertOk()->assertJsonPath('data.settings.recipients', ['chef@example.test', 'autre@example.test'])
            ->assertJsonPath('data.settings.enabled', true)->assertJsonPath('data.settings.domains', ['temperature', 'tasks']);

        $this->getJson(self::BASE)->assertJsonPath('data.settings.send_until', '23:00');
    }

    public function test_les_valeurs_invalides_sont_refusees_avec_un_message_clair(): void
    {
        $this->manager();
        $cases = [
            'recipients' => $this->payload(['recipients' => ['pas-une-adresse']]),
            'domains' => $this->payload(['domains' => []]),
            'send_from' => $this->payload(['send_from' => '25:99']),
            'repeat_minutes' => $this->payload(['repeat_minutes' => 5]),
            'max_sends' => $this->payload(['max_sends' => 50]),
            'send_until' => $this->payload(['send_from' => '20:00', 'send_until' => '10:00']),
        ];
        foreach ($cases as $field => $body) {
            $this->putJson(self::BASE, $body)->assertStatus(422)->assertJsonValidationErrors($field === 'recipients' ? 'recipients.0' : $field);
        }
        $this->putJson(self::BASE, $this->payload(['domains' => ['temperature', 'inconnu']]))->assertStatus(422);
        $this->putJson(self::BASE, $this->payload(['recipients' => array_map(fn ($i) => "a$i@example.test", range(1, 11))]))->assertStatus(422);
        $this->assertSame(0, HygieneAlertSetting::withoutGlobalScopes()->count());
    }

    public function test_activer_sans_destinataire_est_refuse_mais_desactiver_sans_destinataire_est_permis(): void
    {
        $this->manager();
        $this->putJson(self::BASE, $this->payload(['recipients' => []]))->assertStatus(422)->assertJsonValidationErrors('recipients');
        $this->putJson(self::BASE, $this->payload(['recipients' => [], 'enabled' => false]))->assertOk();
    }

    public function test_activer_sans_messagerie_configuree_est_refuse_honnetement(): void
    {
        $this->manager();
        config(['mail.default' => 'log']);

        $this->putJson(self::BASE, $this->payload())->assertStatus(422)->assertJsonValidationErrors('enabled');
        $this->getJson(self::BASE)->assertJsonPath('meta.mail_ready', false);
        $this->putJson(self::BASE, $this->payload(['enabled' => false]))->assertOk(); // on peut quand même préparer les réglages
    }

    public function test_le_message_d_essai_part_vers_les_destinataires_enregistres(): void
    {
        $this->manager();
        $this->putJson(self::BASE, $this->payload())->assertOk();

        $this->postJson(self::BASE.'/test')->assertOk()->assertJsonPath('data.sent', 1);

        Mail::assertSent(HygieneAlertMail::class, fn ($m) => $m->isTest && $m->hasTo('chef@example.test'));
        $this->getJson(self::BASE)->assertJsonPath('data.recent.0.status', 'test')->assertJsonPath('data.recent.0.recipient', 'chef@example.test');
    }

    public function test_l_essai_sans_destinataire_ou_sans_messagerie_est_refuse(): void
    {
        $this->manager();
        $this->postJson(self::BASE.'/test')->assertStatus(422)->assertJsonValidationErrors('recipients');
        $this->putJson(self::BASE, $this->payload())->assertOk();
        config(['mail.default' => 'log']);
        $this->postJson(self::BASE.'/test')->assertStatus(422);
        Mail::assertNothingSent();
    }

    public function test_un_echec_d_envoi_de_l_essai_est_dit_et_journalise(): void
    {
        $this->manager();
        $this->putJson(self::BASE, $this->payload())->assertOk();
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));

        $this->postJson(self::BASE.'/test')->assertStatus(502)->assertJsonPath('data.failed', 1);
        $this->getJson(self::BASE)->assertJsonPath('data.recent.0.status', 'failed')->assertJsonPath('data.recent.0.error', 'smtp down');
    }
}
