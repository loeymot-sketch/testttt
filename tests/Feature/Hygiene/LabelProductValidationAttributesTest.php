<?php

namespace Tests\Feature\Hygiene;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * [ÉTIQUETTES · audit E2E 2026-11-18 P2-a] Une durée d'événement non entière sur une fiche DLC : le message est en français,
 * jamais « Le champ actions.0.value doit être un nombre entier. »
 */
class LabelProductValidationAttributesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Branch::factory()->create(['id' => 1]);
        $u = User::factory()->create(['branch_id' => 1]);
        $u->givePermissionTo('pos-orders');
        $u->assignRole('POS Operator');
        Sanctum::actingAs($u, ['*']);
    }

    public function test_a_non_integer_event_duration_is_named_in_french(): void
    {
        $r = $this->postJson('/api/admin/labels/products', [
            'name' => 'Oignons émincés', 'storage' => '0 à +4 °C',
            'actions' => [['key' => 'prepared', 'value' => 'abc', 'unit' => 'hours']],
        ])->assertStatus(422)->assertJsonValidationErrors('actions.0.value');

        $message = $r->json('errors')['actions.0.value'][0];
        $this->assertStringNotContainsString('actions.0', $message);
        $this->assertStringContainsString('durée de l’événement', $message);
    }
}
