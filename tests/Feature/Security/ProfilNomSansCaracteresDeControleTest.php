<?php

namespace Tests\Feature\Security;

use App\Enums\Status;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * [W7-D D-016 2026-11-04] `PUT /api/profile` persistait NUL, ESC, zéro-largeur dans `users.name` (renvoyé par
 * `/api/profile`, imprimé sur le ticket, affiché en cuisine) alors que le nom de commande était assaini. Même
 * assainisseur (`NomClient`) : contrôle → espace, formatage invisible retiré ; un nom sans lettre → 422.
 */
class ProfilNomSansCaracteresDeControleTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        config(['app.api_key' => 'test-api-key']);
        $this->withHeaders(['x-api-key' => 'test-api-key', 'Accept' => 'application/json']);

        $this->client = User::forceCreate([
            'name' => 'Ana Dupont', 'email' => 'ana-w7@example.test', 'username' => 'ana-w7', 'phone' => '0699870001',
            'country_code' => '+33', 'password' => bcrypt('x'), 'status' => Status::ACTIVE, 'branch_id' => 0,
        ]);
        Sanctum::actingAs($this->client, ['kiosk:order']);
    }

    private function charge(array $surcharge): array
    {
        return array_merge([
            'first_name' => 'Ana', 'last_name' => 'Dupont', 'email' => 'ana-w7@example.test',
            'phone' => '0699870001', 'country_code' => '+33',
        ], $surcharge);
    }

    /** @test */
    public function les_caracteres_de_controle_sont_retires_du_nom(): void
    {
        $this->putJson('/api/profile', $this->charge(['first_name' => "An\0a\x1b@", 'last_name' => "Du\u{200B}pont\t"]))
            ->assertStatus(200);

        $nom = (string) $this->client->fresh()->name;
        $this->assertSame('An a @ Dupont', $nom);
        $this->assertStringNotContainsString("\0", $nom);
        $this->assertStringNotContainsString("\x1b", $nom);
    }

    /** @test */
    public function un_nom_sans_aucune_lettre_est_refuse(): void
    {
        $this->putJson('/api/profile', $this->charge(['first_name' => "\0\x1b", 'last_name' => '...']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['first_name', 'last_name']);

        $this->assertSame('Ana Dupont', $this->client->fresh()->name);
    }
}
