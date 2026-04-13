<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests sécurité IDOR sur AddressController
 * Vérifie que les users ne peuvent pas accéder aux adresses des autres users
 */
class AddressSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $userA;
    protected User $userB;
    protected Address $addressA;
    protected Address $addressB;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->seedSpatieRoles();
        
        $this->branch = Branch::factory()->create();
        
        // User A avec adresse
        $this->userA = User::factory()->create([
            'branch_id' => $this->branch->id,
        ]);
        $this->userA->assignRole('Customer');
        
        $this->addressA = Address::create([
            'user_id' => $this->userA->id,
            'label' => 'Home',
            'address' => '123 Rue A',
            'latitude' => '48.8566',
            'longitude' => '2.3522',
        ]);
        
        // User B avec adresse
        $this->userB = User::factory()->create([
            'branch_id' => $this->branch->id,
        ]);
        $this->userB->assignRole('Customer');
        
        $this->addressB = Address::create([
            'user_id' => $this->userB->id,
            'label' => 'Work',
            'address' => '456 Rue B',
            'latitude' => '45.7640',
            'longitude' => '4.8357',
        ]);
    }

    /**
     * Test IDOR-01: User A ne peut pas voir l'adresse de User B
     */
    public function test_user_cannot_view_other_users_address(): void
    {
        $response = $this->actingAs($this->userA)
            ->getJson("/api/frontend/address/{$this->addressB->id}");
        
        $response->assertStatus(403);
    }

    /**
     * Test IDOR-02: User A ne peut pas modifier l'adresse de User B
     */
    public function test_user_cannot_update_other_users_address(): void
    {
        $response = $this->actingAs($this->userA)
            ->putJson("/api/frontend/address/{$this->addressB->id}", [
                'label' => 'Hacked',
                'address' => 'Hacked Address',
            ]);
        
        $response->assertStatus(403);
        
        // Vérifier que l'adresse n'a pas été modifiée
        $this->addressB->refresh();
        $this->assertEquals('Work', $this->addressB->label);
        $this->assertEquals('456 Rue B', $this->addressB->address);
    }

    /**
     * Test IDOR-03: User A ne peut pas supprimer l'adresse de User B
     */
    public function test_user_cannot_delete_other_users_address(): void
    {
        $response = $this->actingAs($this->userA)
            ->deleteJson("/api/frontend/address/{$this->addressB->id}");
        
        $response->assertStatus(403);
        
        // Vérifier que l'adresse existe toujours
        $this->assertDatabaseHas('addresses', [
            'id' => $this->addressB->id,
            'label' => 'Work',
        ]);
    }

    /**
     * Test IDOR-04: User peut voir sa propre adresse
     */
    public function test_user_can_view_own_address(): void
    {
        $response = $this->actingAs($this->userA)
            ->getJson("/api/frontend/address/{$this->addressA->id}");
        
        $response->assertStatus(200)
            ->assertJsonPath('data.label', 'Home')
            ->assertJsonPath('data.address', '123 Rue A');
    }

    /**
     * Test IDOR-05: User peut modifier sa propre adresse
     */
    public function test_user_can_update_own_address(): void
    {
        $response = $this->actingAs($this->userA)
            ->putJson("/api/frontend/address/{$this->addressA->id}", [
                'label' => 'Updated Home',
                'address' => '789 Rue C',
                'latitude' => '43.2965',
                'longitude' => '5.3698',
            ]);
        
        $response->assertStatus(200);
        
        $this->addressA->refresh();
        $this->assertEquals('Updated Home', $this->addressA->label);
        $this->assertEquals('789 Rue C', $this->addressA->address);
    }

    /**
     * Test IDOR-06: User peut supprimer sa propre adresse
     */
    public function test_user_can_delete_own_address(): void
    {
        $response = $this->actingAs($this->userA)
            ->deleteJson("/api/frontend/address/{$this->addressA->id}");
        
        $response->assertStatus(202);
        
        $this->assertDatabaseMissing('addresses', [
            'id' => $this->addressA->id,
        ]);
    }

    /**
     * Test IDOR-07: Tentative accès adresse inexistante retourne 403 (pas 404)
     * Note: Laravel route model binding retourne 404 si l'adresse n'existe pas
     * mais si elle existe mais n'appartient pas au user → 403
     */
    public function test_access_nonexistent_address_returns_404(): void
    {
        $response = $this->actingAs($this->userA)
            ->getJson('/api/frontend/address/99999');
        
        $response->assertStatus(404);
    }
}
