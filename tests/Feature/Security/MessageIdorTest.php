<?php

namespace Tests\Feature\Security;

use App\Models\Branch;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * [ULTRA-AUDIT V4-DEPLOY 2026-07-02 — P2 IDOR] Les routes frontend/message (auth:sanctum seul, modèle
 * Message non branch-scopé) ne doivent pas laisser un appelant lire/supprimer le message d'autrui par ID.
 */
class MessageIdorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    private function makeMessage(User $owner): Message
    {
        // Une "Message" = une conversation (user_id = propriétaire) ; le contenu vit dans message_histories.
        return Message::forceCreate([
            'branch_id' => $owner->branch_id ?: 1,
            'user_id' => $owner->id,
        ]);
    }

    /** @test */
    public function un_appelant_ne_peut_ni_lire_ni_supprimer_le_message_d_autrui(): void
    {
        $branch = Branch::factory()->create();
        $victim = User::factory()->create(['branch_id' => $branch->id, 'status' => 5]);
        $attacker = User::factory()->create(['branch_id' => $branch->id, 'status' => 5]);
        $message = $this->makeMessage($victim);

        Sanctum::actingAs($attacker, ['kiosk:order']);

        // Lecture du message d'autrui → 404 (pas de fuite).
        $this->withHeader('x-api-key', config('app.api_key'))
            ->getJson("/api/frontend/message/show/{$message->id}")
            ->assertStatus(404);

        // Suppression du message d'autrui → 404, et le message EXISTE toujours.
        $this->withHeader('x-api-key', config('app.api_key'))
            ->deleteJson("/api/frontend/message/{$message->id}")
            ->assertStatus(404);
        $this->assertDatabaseHas('messages', ['id' => $message->id]);
    }

    /** @test */
    public function le_proprietaire_peut_lire_et_supprimer_son_propre_message(): void
    {
        $branch = Branch::factory()->create();
        $owner = User::factory()->create(['branch_id' => $branch->id, 'status' => 5]);
        $message = $this->makeMessage($owner);

        Sanctum::actingAs($owner, ['kiosk:order']);

        $this->withHeader('x-api-key', config('app.api_key'))
            ->getJson("/api/frontend/message/show/{$message->id}")
            ->assertStatus(200);

        $this->withHeader('x-api-key', config('app.api_key'))
            ->deleteJson("/api/frontend/message/{$message->id}")
            ->assertStatus(202);
    }

    /**
     * [W7-D D-001 2026-11-04] `POST /api/frontend/message` prenait `receiver_id` (jamais validé) comme propriétaire du
     * fil, puis la ressource renvoyait le NOM et le TÉLÉPHONE en clair de ce user_id arbitraire : énumération de tous
     * les comptes (admin, caissier, livreur…) et injection d'un message dans le fil d'autrui. Côté client, le fil
     * appartient TOUJOURS à l'appelant.
     *
     * @test
     */
    public function un_message_envoye_avec_le_receiver_id_d_autrui_reste_dans_le_fil_de_l_appelant(): void
    {
        $branch = Branch::factory()->create();
        $victim = User::factory()->create(['branch_id' => $branch->id, 'status' => 5, 'phone' => '0699000001']);
        $attacker = User::factory()->create(['branch_id' => $branch->id, 'status' => 5, 'phone' => '0699000002']);

        Sanctum::actingAs($attacker, ['kiosk:order']);

        $r = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/frontend/message', [
                'branch_id'   => $branch->id,
                'user_id'     => $victim->id,
                'receiver_id' => $victim->id,
                'is_read'     => 10,
                'text'        => 'bonjour',
            ]);

        $this->assertContains($r->getStatusCode(), [200, 201], $r->getContent());
        $userId = $r->json('data.user_id') ?? $r->json('user_id');
        $this->assertSame((int) $attacker->id, (int) $userId, 'le fil doit appartenir à l\'appelant, pas au receiver_id envoyé');
        $this->assertStringNotContainsString('0699000001', $r->getContent(), 'le téléphone de la victime ne doit jamais sortir');
        $this->assertDatabaseMissing('messages', ['user_id' => $victim->id]);
        $this->assertDatabaseHas('messages', ['user_id' => $attacker->id]);
        $this->assertDatabaseMissing('message_histories', ['user_id' => $victim->id]);
    }

    /**
     * [W7-D D-001 2026-11-04] `message_id` d'un fil qui n'est pas à l'appelant → 404, rien d'écrit.
     *
     * @test
     */
    public function on_ne_peut_pas_ecrire_dans_le_fil_d_autrui_via_message_id(): void
    {
        $branch = Branch::factory()->create();
        $victim = User::factory()->create(['branch_id' => $branch->id, 'status' => 5]);
        $attacker = User::factory()->create(['branch_id' => $branch->id, 'status' => 5]);
        $message = $this->makeMessage($victim);

        Sanctum::actingAs($attacker, ['kiosk:order']);

        $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/frontend/message', [
                'branch_id'   => $branch->id,
                'message_id'  => $message->id,
                'user_id'     => $attacker->id,
                'receiver_id' => $attacker->id,
                'is_read'     => 10,
                'text'        => 'intrus',
            ])
            ->assertStatus(404);

        $this->assertDatabaseMissing('message_histories', ['message_id' => $message->id]);
    }
}
