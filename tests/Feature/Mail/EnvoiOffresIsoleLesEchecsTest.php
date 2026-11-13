<?php

namespace Tests\Feature\Mail;

use App\Models\Subscriber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Permission;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\TestCase;

/**
 * [SUPERVISION 2026-11-13] Depuis S13-04 (un e-mail par abonné, pour un lien de désinscription propre à chacun), une
 * seule adresse refusée par le serveur d'envoi (« 550 mailbox unavailable ») interrompait la boucle : les abonnés
 * suivants ne recevaient rien, l'admin voyait une erreur 422, et une relance renvoyait l'offre à ceux d'avant.
 * Chaque destinataire est désormais isolé, et la réponse dit la vérité (combien sont partis, combien ont été refusés).
 */
class EnvoiOffresIsoleLesEchecsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        Permission::findOrCreate('subscribers', 'sanctum');
        $admin->givePermissionTo('subscribers');

        return $admin;
    }

    /** @param array<int,string> $refuses */
    private function transportQuiRefuse(array $refuses): void
    {
        $GLOBALS['__offres_envoyees'] = [];
        Mail::extend('refus_partiel', fn () => new class($refuses) extends AbstractTransport {
            public function __construct(private array $refuses) { parent::__construct(); }
            protected function doSend(SentMessage $message): void
            {
                $to = $message->getEnvelope()->getRecipients()[0]->getAddress();
                if (in_array($to, $this->refuses, true)) {
                    throw new TransportException('550 mailbox unavailable');
                }
                $GLOBALS['__offres_envoyees'][] = $to;
            }
            public function __toString(): string { return 'refus_partiel://'; }
        });
        config(['mail.mailers.refus_partiel' => ['transport' => 'refus_partiel'], 'mail.default' => 'refus_partiel']);
    }

    private function envoyer(User $admin)
    {
        return $this->actingAs($admin, 'sanctum')->postJson('/api/admin/subscriber/send-email', [
            'subject' => 'Nouvelle carte',
            'message' => 'Venez découvrir nos nouveautés.',
        ]);
    }

    public function test_une_adresse_refusee_n_empeche_pas_les_suivantes(): void
    {
        $admin = $this->admin();
        $this->transportQuiRefuse(['b@example.test']);
        foreach (['a@example.test', 'b@example.test', 'c@example.test'] as $e) {
            Subscriber::query()->create(['email' => $e]);
        }

        $reponse = $this->envoyer($admin)->assertOk();

        $this->assertSame(['a@example.test', 'c@example.test'], $GLOBALS['__offres_envoyees']);
        $this->assertSame(2, $reponse->json('count'));
        $this->assertSame(1, $reponse->json('failed'));
        $this->assertStringContainsString('2', (string) $reponse->json('message'));
        $this->assertStringContainsString('1', (string) $reponse->json('message'));
    }

    public function test_si_toutes_les_adresses_sont_refusees_l_admin_le_sait(): void
    {
        $admin = $this->admin();
        $this->transportQuiRefuse(['a@example.test', 'b@example.test']);
        foreach (['a@example.test', 'b@example.test'] as $e) {
            Subscriber::query()->create(['email' => $e]);
        }

        $reponse = $this->envoyer($admin)->assertStatus(422);

        $this->assertSame([], $GLOBALS['__offres_envoyees']);
        $this->assertFalse((bool) $reponse->json('status'));
    }
}
