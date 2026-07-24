<?php

namespace Tests\Feature\Purchasing;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\PurchaseDocument;
use App\Models\PurchaseLine;
use App\Models\RawMaterial;
use App\Models\RawMaterialStock;
use App\Models\StockLevel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * [ARCH_STOCK_INTELLIGENT_BOM_2026-07-23 / P3c] Endpoint de VALIDATION owner —
 * POST /api/admin/purchasing/{document}/validate.
 *
 * Couvre : application des propositions confirmées au stock (matière + boisson +
 * charge), redirection de cible par l'owner, sous-ensemble (lignes non soumises
 * restent proposed), idempotence (re-valider = no-op), gate permission:items_create,
 * isolation branche, et rejet d'une cible matière/produit sans id.
 *
 * NF525 : domaine ADDITIF — aucune écriture ni assertion fiscale.
 */
class PurchasingValidateEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        Storage::fake('local');

        if (! Branch::query()->whereKey(1)->exists()) {
            Branch::factory()->create(['id' => 1]);
        }

        ItemCategory::create([
            'name' => 'Boissons',
            'slug' => 'boissons-'.Str::random(6),
            'status' => Status::ACTIVE,
        ]);
    }

    private function actingAdmin(): User
    {
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $admin->givePermissionTo(['items_create']);
        Sanctum::actingAs($admin, ['*']);

        return $admin;
    }

    private function drink(string $name): Item
    {
        return Item::forceCreate([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(6),
            'item_category_id' => ItemCategory::query()->where('name', 'Boissons')->value('id'),
            'item_type' => 1,
            'price' => 2,
            'status' => Status::ACTIVE,
        ]);
    }

    private function photo(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('facture-metro.jpg', $content);
    }

    /** Scanne (mock) → renvoie la réponse (document + propositions). */
    private function scan(string $bytes): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/admin/purchasing/scan', ['photo' => $this->photo($bytes)])->assertOk();
    }

    /** Rejoue les propositions telles quelles (owner confirme la cible IA). */
    private function echoProposals(array $proposals): array
    {
        return array_map(fn (array $p): array => [
            'id' => $p['id'],
            'target_type' => $p['target_type'],
            'target_id' => $p['target_id'],
        ], $proposals);
    }

    private function draftDocWithLine(int $branchId = 1): array
    {
        $doc = PurchaseDocument::create([
            'branch_id' => $branchId,
            'doc_date' => now()->toDateString(),
            'source' => PurchaseDocument::SOURCE_FACTURE,
            'status' => PurchaseDocument::STATUS_DRAFT,
            'doc_hash' => 'sha256:'.Str::random(40),
        ]);

        $line = PurchaseLine::create([
            'purchase_document_id' => $doc->id,
            'raw_label' => 'Article inconnu',
            'qty' => 5,
            'unit' => 'piece',
            'unit_price' => 1.0,
            'target_type' => PurchaseLine::TARGET_CHARGE,
            'target_id' => null,
            'status' => PurchaseLine::STATUS_PROPOSED,
        ]);

        return [$doc, $line];
    }

    public function test_validate_applies_all_confirmed_proposals_to_stock(): void
    {
        $this->actingAdmin();
        $poulet = RawMaterial::create(['branch_id' => 1, 'name' => 'Poulet', 'unit' => 'kg', 'is_active' => true]);
        $cheddar = RawMaterial::create(['branch_id' => 1, 'name' => 'Cheddar', 'unit' => 'tranche', 'is_active' => true]);
        $coca = $this->drink('Coca 33cl');

        $scan = $this->scan('METRO-VALIDATE-A');
        $docId = $scan->json('document.id');
        $lines = $this->echoProposals($scan->json('proposals'));

        $response = $this->postJson("/api/admin/purchasing/{$docId}/validate", ['lines' => $lines]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('document.status', 'validated')
            ->assertJsonPath('applied.status', 'validated')
            ->assertJsonPath('applied.applied.raw_material', 2)
            ->assertJsonPath('applied.applied.stock_item', 1)
            ->assertJsonPath('applied.applied.charge', 1);

        // Stock réellement appliqué (flux P3b prouvé).
        $this->assertEqualsWithDelta(3.0, (float) RawMaterialStock::query()
            ->where('raw_material_id', $poulet->id)->where('branch_id', 1)->value('on_hand'), 0.0001);
        $this->assertEqualsWithDelta(6.0, (float) $poulet->fresh()->avg_cost, 0.0001);
        $this->assertEqualsWithDelta(100.0, (float) RawMaterialStock::query()
            ->where('raw_material_id', $cheddar->id)->where('branch_id', 1)->value('on_hand'), 0.0001);
        $this->assertSame(24, (int) StockLevel::query()
            ->where('branch_id', 1)->where('stockable_type', Item::class)->where('stockable_id', $coca->id)->value('on_hand'));
    }

    public function test_owner_redirect_and_subset_only_applies_submitted_lines(): void
    {
        $this->actingAdmin();
        RawMaterial::create(['branch_id' => 1, 'name' => 'Poulet', 'unit' => 'kg', 'is_active' => true]);
        $poulet2 = RawMaterial::create(['branch_id' => 1, 'name' => 'Blanc de poulet', 'unit' => 'kg', 'is_active' => true]);

        $scan = $this->scan('METRO-VALIDATE-B');
        $docId = $scan->json('document.id');
        $proposals = $scan->json('proposals');

        // L'owner NE confirme QUE la 1ʳᵉ ligne (Poulet frais 3kg) ET la redirige vers Poulet2.
        $firstLineId = $proposals[0]['id'];
        $response = $this->postJson("/api/admin/purchasing/{$docId}/validate", [
            'lines' => [[
                'id' => $firstLineId,
                'target_type' => PurchaseLine::TARGET_RAW_MATERIAL,
                'target_id' => $poulet2->id,
            ]],
        ]);

        $response->assertOk()
            ->assertJsonPath('applied.applied.raw_material', 1)
            ->assertJsonPath('applied.applied.skipped_proposed', 3);

        // La cible REDIRIGÉE reçoit le stock ; l'originale reste vide.
        $this->assertEqualsWithDelta(3.0, (float) RawMaterialStock::query()
            ->where('raw_material_id', $poulet2->id)->where('branch_id', 1)->value('on_hand'), 0.0001);

        // Les 3 lignes non soumises restent `proposed`.
        $this->assertSame(3, PurchaseLine::query()
            ->where('purchase_document_id', $docId)
            ->where('status', PurchaseLine::STATUS_PROPOSED)->count());
    }

    public function test_revalidating_is_idempotent_noop(): void
    {
        $this->actingAdmin();
        $poulet = RawMaterial::create(['branch_id' => 1, 'name' => 'Poulet', 'unit' => 'kg', 'is_active' => true]);

        $scan = $this->scan('METRO-VALIDATE-C');
        $docId = $scan->json('document.id');
        $lines = $this->echoProposals($scan->json('proposals'));

        $this->postJson("/api/admin/purchasing/{$docId}/validate", ['lines' => $lines])->assertOk();
        // Re-valider = NO-OP (ne double PAS le stock).
        $this->postJson("/api/admin/purchasing/{$docId}/validate", ['lines' => $lines])
            ->assertOk()
            ->assertJsonPath('applied.status', 'noop');

        $this->assertEqualsWithDelta(3.0, (float) RawMaterialStock::query()
            ->where('raw_material_id', $poulet->id)->where('branch_id', 1)->value('on_hand'), 0.0001);
    }

    public function test_validate_requires_items_create_permission(): void
    {
        [$doc] = $this->draftDocWithLine();

        $chef = User::factory()->create(['branch_id' => 0]);
        $chef->assignRole('Chef'); // Chef n'a PAS items_create.
        Sanctum::actingAs($chef, ['*']);

        $this->postJson("/api/admin/purchasing/{$doc->id}/validate", ['lines' => []])
            ->assertForbidden();
    }

    public function test_staff_cannot_validate_other_branch_document(): void
    {
        [$doc] = $this->draftDocWithLine(1);

        $staff = User::factory()->create(['branch_id' => 2]);
        $staff->givePermissionTo(['items_create']);
        Sanctum::actingAs($staff, ['*']);

        $this->postJson("/api/admin/purchasing/{$doc->id}/validate", ['lines' => []])
            ->assertForbidden();
    }

    public function test_validate_rejects_non_charge_target_without_id(): void
    {
        $this->actingAdmin();
        [$doc, $line] = $this->draftDocWithLine();

        $this->postJson("/api/admin/purchasing/{$doc->id}/validate", [
            'lines' => [[
                'id' => $line->id,
                'target_type' => PurchaseLine::TARGET_RAW_MATERIAL,
                'target_id' => null,
            ]],
        ])->assertStatus(422);
    }

    public function test_targets_lists_branch_materials_and_drink_items(): void
    {
        $this->actingAdmin();
        RawMaterial::create(['branch_id' => 1, 'name' => 'Poulet', 'unit' => 'kg', 'is_active' => true]);
        RawMaterial::create(['branch_id' => 1, 'name' => 'Inactive', 'unit' => 'kg', 'is_active' => false]);
        RawMaterial::create(['branch_id' => 2, 'name' => 'AutreBranche', 'unit' => 'kg', 'is_active' => true]);
        $this->drink('Coca 33cl');

        $response = $this->getJson('/api/admin/purchasing/targets')->assertOk();

        // Seules les matières ACTIVES de la branche 1 (pas l'inactive, pas la branche 2).
        $this->assertSame(['Poulet'], array_column($response->json('raw_materials'), 'name'));
        $this->assertSame(['Coca 33cl'], array_column($response->json('drink_items'), 'name'));
    }
}
