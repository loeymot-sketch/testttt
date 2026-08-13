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
use App\Services\Purchasing\PurchaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * [ARCH_STOCK_INTELLIGENT_BOM_2026-07-23 / P3b] Flux COMPLET end-to-end SANS clé.
 *
 * POST /api/admin/purchasing/scan (photo) → mock lit le fixture (4 lignes) →
 * classification propose (Poulet/Cheddar matière, Coca boisson, Sac charge) →
 * PurchaseDocument `draft` + 4 PurchaseLine `proposed` créés → l'owner valide →
 * PurchaseService::validateDocument applique au stock (matière + boisson +
 * charge, avg_cost pondéré). Idempotence : re-scan même photo = no-op (doc_hash).
 * Gate permission : sans items_create → 403.
 *
 * NF525 : domaine ADDITIF — aucune écriture ni assertion fiscale.
 */
class PurchasingScanFlowTest extends TestCase
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

    public function test_scan_creates_draft_document_with_four_classified_proposals(): void
    {
        $this->actingAdmin();
        $poulet = RawMaterial::create(['branch_id' => 1, 'name' => 'Poulet', 'unit' => 'kg', 'is_active' => true]);
        $cheddar = RawMaterial::create(['branch_id' => 1, 'name' => 'Cheddar', 'unit' => 'tranche', 'is_active' => true]);
        $coca = $this->drink('Coca 33cl');

        $response = $this->postJson('/api/admin/purchasing/scan', ['photo' => $this->photo('METRO-INVOICE-A')]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('idempotent', false)
            ->assertJsonPath('document.status', 'draft')
            ->assertJsonCount(4, 'proposals');

        // Document draft + 4 lignes proposed persistées.
        $doc = PurchaseDocument::query()->firstOrFail();
        $this->assertSame('draft', $doc->status);
        $this->assertSame(4, $doc->lines()->count());
        $this->assertSame(4, $doc->lines()->where('status', PurchaseLine::STATUS_PROPOSED)->count());

        // Classification correcte, dans l'ordre du fixture.
        $lines = $doc->lines()->orderBy('id')->get();
        $this->assertSame([PurchaseLine::TARGET_RAW_MATERIAL, $poulet->id], [$lines[0]->target_type, (int) $lines[0]->target_id]);
        $this->assertSame([PurchaseLine::TARGET_RAW_MATERIAL, $cheddar->id], [$lines[1]->target_type, (int) $lines[1]->target_id]);
        $this->assertSame([PurchaseLine::TARGET_STOCK_ITEM, $coca->id], [$lines[2]->target_type, (int) $lines[2]->target_id]);
        $this->assertSame(PurchaseLine::TARGET_CHARGE, $lines[3]->target_type);
        $this->assertNull($lines[3]->target_id);

        // BRUT stocké (donnée d'apprentissage B6).
        $this->assertNotNull($doc->photo_path);
        Storage::disk('local')->assertExists($doc->photo_path);
    }

    public function test_owner_validation_applies_stock_across_all_three_targets(): void
    {
        $this->actingAdmin();
        $poulet = RawMaterial::create(['branch_id' => 1, 'name' => 'Poulet', 'unit' => 'kg', 'is_active' => true]);
        $cheddar = RawMaterial::create(['branch_id' => 1, 'name' => 'Cheddar', 'unit' => 'tranche', 'is_active' => true]);
        $coca = $this->drink('Coca 33cl');

        $this->postJson('/api/admin/purchasing/scan', ['photo' => $this->photo('METRO-INVOICE-B')])->assertOk();
        $doc = PurchaseDocument::query()->firstOrFail();

        // L'owner confirme toutes les propositions (proposed → validated) puis applique.
        PurchaseLine::query()->where('purchase_document_id', $doc->id)->update(['status' => PurchaseLine::STATUS_VALIDATED]);
        $applied = app(PurchaseService::class)->validateDocument($doc->fresh());

        $this->assertSame('validated', $applied['status']);
        $this->assertSame(2, $applied['applied']['raw_material']);
        $this->assertSame(1, $applied['applied']['stock_item']);
        $this->assertSame(1, $applied['applied']['charge']);

        // Matière Poulet : +3 (kg du fixture), avg_cost = 6,00 (1er achat).
        $this->assertEqualsWithDelta(3.0, (float) RawMaterialStock::query()
            ->where('raw_material_id', $poulet->id)->where('branch_id', 1)->value('on_hand'), 0.0001);
        $this->assertEqualsWithDelta(6.0, (float) $poulet->fresh()->avg_cost, 0.0001);

        // Matière Cheddar : +100 tranches, avg_cost = 0,12.
        $this->assertEqualsWithDelta(100.0, (float) RawMaterialStock::query()
            ->where('raw_material_id', $cheddar->id)->where('branch_id', 1)->value('on_hand'), 0.0001);
        $this->assertEqualsWithDelta(0.12, (float) $cheddar->fresh()->avg_cost, 0.0001);

        // Boisson Coca : stock_levels +24 unités.
        $this->assertSame(24, (int) StockLevel::query()
            ->where('branch_id', 1)->where('stockable_type', Item::class)->where('stockable_id', $coca->id)->value('on_hand'));

        // Charge (Sac) : aucun stock matière créé au-delà des 2 matières.
        $this->assertSame(2, RawMaterialStock::query()->count());
    }

    public function test_rescanning_the_same_photo_is_idempotent(): void
    {
        $this->actingAdmin();
        RawMaterial::create(['branch_id' => 1, 'name' => 'Poulet', 'unit' => 'kg', 'is_active' => true]);

        $first = $this->postJson('/api/admin/purchasing/scan', ['photo' => $this->photo('SAME-BYTES')]);
        $first->assertOk()->assertJsonPath('idempotent', false);
        $firstId = $first->json('document.id');

        // Même contenu → même doc_hash → renvoie l'existant, ne duplique pas.
        $second = $this->postJson('/api/admin/purchasing/scan', ['photo' => $this->photo('SAME-BYTES')]);
        $second->assertOk()->assertJsonPath('idempotent', true);

        $this->assertSame($firstId, $second->json('document.id'));
        $this->assertSame(1, PurchaseDocument::query()->count());
        $this->assertSame(4, PurchaseLine::query()->count(), 'Pas de doublon de lignes au re-scan.');
    }

    public function test_scan_requires_items_create_permission(): void
    {
        $user = User::factory()->create(['branch_id' => 0]);
        $user->assignRole('Chef'); // Chef n'a PAS items_create (seedSpatieRoles).
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/admin/purchasing/scan', ['photo' => $this->photo('X')])
            ->assertForbidden();
    }

    /**
     * [GOAL_ADMIN_NAV_BREADTH_CONVERGENCE_2026-08-13] Every other upload
     * FormRequest in this codebase (Item photos, Message/PushNotification
     * images) applies NoDangerousFileExtension — the codebase-wide answer to
     * a documented .pht polyglot RCE finding (GOAL-L2-HEAL-02 2026-05-24).
     * This scan endpoint's inline Request::validate() array never got it.
     */
    public function test_scan_rejects_a_dangerous_file_extension(): void
    {
        $this->actingAdmin();

        $dangerous = \Illuminate\Http\UploadedFile::fake()->createWithContent('shell.pht', 'anything');

        $response = $this->postJson('/api/admin/purchasing/scan', ['photo' => $dangerous]);

        $response->assertStatus(422);
        $this->assertSame(0, PurchaseDocument::query()->count());
    }
}
