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
}
