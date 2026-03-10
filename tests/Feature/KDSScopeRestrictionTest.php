<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class KDSScopeRestrictionTest extends TestCase
{
    use RefreshDatabase;

    public function test_kds_cannot_access_global_settings()
    {
        $user = User::factory()->create(['username' => 'kdsuser_' . uniqid()]);
        // Sans rôle Spatie explicitement chargé dans la base in-memory, le user n'est pas Admin System

        // Try to access global settings
        $response = $this->actingAs($user)->getJson('/api/admin/setting');

        // Must be forbidden (User a pas le rôle "Admin" créé par les seeders)
        $this->assertTrue(in_array($response->status(), [401, 403]));
    }
}
