<?php

namespace Tests\Feature\RawMaterials;

use App\Models\Branch;
use App\Models\RawMaterial;
use App\Models\RawMaterialMovement;
use App\Models\RawMaterialStock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * [GOAL_CAYENNE_FINITION_2026-08-13 / §6 Vague 5] Écran d'ajustement inventaire
 * matière première — la seule porte d'écriture manuelle du domaine (casse, vol,
 * pesée fausse). Couvre : ajustement réussi (stock + mouvement traçable), raison
 * obligatoire (422 sans elle), gate permission (403 sans items_create), branch
 * isolation (403 cross-branche), historique en lecture, idempotence NON appliquée
 * à deux ajustements manuels distincts (chacun doit s'appliquer).
 *
 * NF525 : domaine ADDITIF — aucune assertion fiscale.
 */
class RawMaterialAdjustEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMinimalSettings();
        $this->seedSpatieRoles();

        if (! Branch::query()->whereKey(1)->exists()) {
            Branch::factory()->create(['id' => 1]);
        }
    }

    private function actingAdmin(): User
    {
        $admin = User::factory()->create(['branch_id' => 0, 'name' => 'Kossay Owner']);
        $admin->assignRole('Admin');
        $admin->givePermissionTo(['items_create', 'items_show']);
        Sanctum::actingAs($admin, ['*']);

        return $admin;
    }

    private function material(string $name = 'Viande hachée'): RawMaterial
    {
        return RawMaterial::create([
            'branch_id' => 1,
            'name' => $name,
            'unit' => 'g',
            'is_active' => true,
        ]);
    }

    // ── Ajustement réussi ────────────────────────────────────────────────────

    public function test_adjust_changes_stock_and_creates_a_traceable_movement(): void
    {
        $admin = $this->actingAdmin();
        $material = $this->material();

        // Stock théorique initial = 10 (via le service receive, hors périmètre HTTP ici).
        RawMaterialStock::create(['raw_material_id' => $material->id, 'branch_id' => 1, 'on_hand' => 10]);

        $response = $this->postJson("/api/admin/raw-materials/{$material->id}/adjust", [
            'target_on_hand' => 6.5,
            'reason' => 'casse',
            'note' => 'Plaque tombée en cuisine ce matin',
        ]);

        $response->assertOk()->assertJsonPath('ok', true);
        // [json_encode whole-float quirk] PHP encodes 10.0 as `10` (no
        // JSON_PRESERVE_ZERO_FRACTION) — assertJsonPath's identical-match would
        // fail 10 !== 10.0. Compare via numeric cast on the decoded payload instead.
        $this->assertEqualsWithDelta(10.0, (float) $response->json('previous_on_hand'), 0.001);
        $this->assertEqualsWithDelta(6.5, (float) $response->json('on_hand'), 0.001);
        $this->assertEqualsWithDelta(-3.5, (float) $response->json('delta'), 0.001);

        $this->assertEqualsWithDelta(
            6.5,
            (float) RawMaterialStock::where('raw_material_id', $material->id)->value('on_hand'),
            0.001
        );

        $movement = RawMaterialMovement::where('raw_material_id', $material->id)
            ->where('source_type', 'manual_adjustment')
            ->first();

        $this->assertNotNull($movement, 'Un mouvement traçable doit être créé.');
        $this->assertEqualsWithDelta(-3.5, (float) $movement->delta, 0.001);
        $this->assertSame('casse', $movement->reason);
        $this->assertNull($movement->source_id, 'source_id doit rester NULL pour ne jamais dédupliquer un ajustement manuel.');

        // Traçabilité qui/pourquoi dans meta (append-only movement n'a pas de colonne user_id).
        $meta = (array) $movement->meta;
        $this->assertSame($admin->id, $meta['adjusted_by_user_id']);
        $this->assertSame('Kossay Owner', $meta['adjusted_by_name']);
        $this->assertSame('Plaque tombée en cuisine ce matin', $meta['note']);
        $this->assertEqualsWithDelta(10.0, (float) $meta['previous_on_hand'], 0.001);
        $this->assertEqualsWithDelta(6.5, (float) $meta['target_on_hand'], 0.001);
    }

    public function test_adjust_works_from_no_prior_stock_row(): void
    {
        $this->actingAdmin();
        $material = $this->material('Poulet');

        $response = $this->postJson("/api/admin/raw-materials/{$material->id}/adjust", [
            'target_on_hand' => 4,
            'reason' => 'comptage',
        ]);

        $response->assertOk();
        $this->assertEqualsWithDelta(0.0, (float) $response->json('previous_on_hand'), 0.001);
        $this->assertEqualsWithDelta(4.0, (float) $response->json('on_hand'), 0.001);
    }

    public function test_two_distinct_manual_adjustments_both_apply(): void
    {
        $this->actingAdmin();
        $material = $this->material();

        $this->postJson("/api/admin/raw-materials/{$material->id}/adjust", ['target_on_hand' => 5, 'reason' => 'comptage'])->assertOk();
        $this->postJson("/api/admin/raw-materials/{$material->id}/adjust", ['target_on_hand' => 3, 'reason' => 'comptage'])->assertOk();

        // Deux ajustements manuels distincts (même raison) doivent TOUS DEUX s'appliquer —
        // pas de dédup source_id=null (mirror du pattern manual_in testé côté service).
        $this->assertSame(
            2,
            RawMaterialMovement::where('raw_material_id', $material->id)->where('source_type', 'manual_adjustment')->count()
        );
        $this->assertEqualsWithDelta(
            3.0,
            (float) RawMaterialStock::where('raw_material_id', $material->id)->value('on_hand'),
            0.001
        );
    }

    // ── Raison obligatoire ───────────────────────────────────────────────────

    public function test_adjust_without_reason_is_rejected(): void
    {
        $this->actingAdmin();
        $material = $this->material();

        $response = $this->postJson("/api/admin/raw-materials/{$material->id}/adjust", [
            'target_on_hand' => 5,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->assertSame(0, RawMaterialMovement::where('raw_material_id', $material->id)->count());
    }

    public function test_adjust_with_blank_reason_is_rejected(): void
    {
        $this->actingAdmin();
        $material = $this->material();

        $response = $this->postJson("/api/admin/raw-materials/{$material->id}/adjust", [
            'target_on_hand' => 5,
            'reason' => '',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    public function test_adjust_without_target_on_hand_is_rejected(): void
    {
        $this->actingAdmin();
        $material = $this->material();

        $response = $this->postJson("/api/admin/raw-materials/{$material->id}/adjust", [
            'reason' => 'comptage',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('target_on_hand');
    }

    public function test_adjust_rejects_a_negative_target(): void
    {
        $this->actingAdmin();
        $material = $this->material();

        $response = $this->postJson("/api/admin/raw-materials/{$material->id}/adjust", [
            'target_on_hand' => -1,
            'reason' => 'comptage',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('target_on_hand');
    }

    // ── Gate permission ──────────────────────────────────────────────────────

    public function test_adjust_requires_items_create_permission(): void
    {
        $user = User::factory()->create(['branch_id' => 0]);
        $user->assignRole('Chef'); // Chef n'a PAS items_create (seedSpatieRoles).
        Sanctum::actingAs($user, ['*']);

        $material = $this->material();

        $response = $this->postJson("/api/admin/raw-materials/{$material->id}/adjust", [
            'target_on_hand' => 5,
            'reason' => 'comptage',
        ]);

        $response->assertForbidden();
        $this->assertSame(0, RawMaterialMovement::where('raw_material_id', $material->id)->count());
    }

    public function test_history_requires_items_show_permission(): void
    {
        $user = User::factory()->create(['branch_id' => 0]);
        // Aucun rôle assigné → aucune permission.
        Sanctum::actingAs($user, ['*']);

        $material = $this->material();

        $this->getJson("/api/admin/raw-materials/{$material->id}/movements")->assertForbidden();
    }

    // ── Branch isolation ─────────────────────────────────────────────────────

    public function test_a_branch_scoped_user_cannot_adjust_another_branchs_material(): void
    {
        Branch::factory()->create(['id' => 2]);

        $manager = User::factory()->create(['branch_id' => 2]);
        $manager->assignRole('Branch Manager');
        $manager->givePermissionTo(['items_create']);
        Sanctum::actingAs($manager, ['*']);

        $material = $this->material(); // branch_id = 1, l'utilisateur est de la branche 2.

        $response = $this->postJson("/api/admin/raw-materials/{$material->id}/adjust", [
            'target_on_hand' => 5,
            'reason' => 'comptage',
        ]);

        $response->assertForbidden();
        $this->assertSame(0, RawMaterialMovement::where('raw_material_id', $material->id)->count());
    }

    // ── Historique (lecture) ─────────────────────────────────────────────────

    public function test_history_returns_manual_adjustments_most_recent_first(): void
    {
        $this->actingAdmin();
        $material = $this->material();

        $this->postJson("/api/admin/raw-materials/{$material->id}/adjust", ['target_on_hand' => 5, 'reason' => 'comptage'])->assertOk();
        $this->postJson("/api/admin/raw-materials/{$material->id}/adjust", ['target_on_hand' => 2, 'reason' => 'vol', 'note' => 'Vitrine forcée'])->assertOk();

        $response = $this->getJson("/api/admin/raw-materials/{$material->id}/movements");

        $response->assertOk()->assertJsonCount(2, 'movements');
        $this->assertSame('vol', $response->json('movements.0.reason'));
        $this->assertSame('Vitrine forcée', $response->json('movements.0.note'));
        $this->assertSame('comptage', $response->json('movements.1.reason'));
    }
}
