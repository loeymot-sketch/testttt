<?php

namespace Tests\Feature\Purchasing;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\PurchaseDocument;
use App\Models\PurchaseLine;
use App\Models\RawMaterial;
use App\Models\RawMaterialMovement;
use App\Models\RawMaterialStock;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Services\Purchasing\PurchaseService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * [ARCH_STOCK_INTELLIGENT_BOM_2026-07-23 / P3a] Domaine ACHATS/FACTURES.
 *
 * Prouve : validation d'un document → matière reçue (+ mouvement source
 * purchase_line) + avg_cost recalculé en MOYENNE PONDÉRÉE au centime ; ligne
 * boisson → stock_levels +N unités ; ligne charge → 0 stock ; idempotence
 * (re-valider = no-op) ; doc_hash unique ; lignes `proposed` ignorées ;
 * soft-delete fournisseur.
 *
 * NF525 : domaine ADDITIF — aucune écriture fiscale, aucune assertion fiscale.
 */
class PurchaseServiceTest extends TestCase
{
    use RefreshDatabase;

    private ItemCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        // Branche 1 requise par la FK stock_levels/stock_movements (boisson).
        if (! Branch::query()->whereKey(1)->exists()) {
            Branch::factory()->create(['id' => 1]);
        }

        $this->category = ItemCategory::create([
            'name' => 'Boissons',
            'slug' => 'boissons-'.Str::random(6),
            'status' => Status::ACTIVE,
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function service(): PurchaseService
    {
        return app(PurchaseService::class);
    }

    private function material(string $name, ?float $avgCost = null, string $unit = 'g'): RawMaterial
    {
        return RawMaterial::create([
            'branch_id' => 1,
            'name' => $name,
            'unit' => $unit,
            'avg_cost' => $avgCost,
            'is_active' => true,
        ]);
    }

    private function item(string $name): Item
    {
        return Item::forceCreate([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(6),
            'item_category_id' => $this->category->id,
            'item_type' => 1,
            'price' => 2,
            'status' => Status::ACTIVE,
        ]);
    }

    private function document(string $hash, string $status = PurchaseDocument::STATUS_DRAFT): PurchaseDocument
    {
        return PurchaseDocument::create([
            'branch_id' => 1,
            'doc_date' => '2026-07-24',
            'source' => PurchaseDocument::SOURCE_FACTURE,
            'status' => $status,
            'doc_hash' => $hash,
        ]);
    }

    /** @param array<string,mixed> $overrides */
    private function line(PurchaseDocument $doc, string $targetType, ?int $targetId, array $overrides = []): PurchaseLine
    {
        return PurchaseLine::create(array_merge([
            'purchase_document_id' => $doc->id,
            'raw_label' => 'LIGNE BRUTE',
            'qty' => 1,
            'unit' => 'piece',
            'unit_price' => null,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'status' => PurchaseLine::STATUS_VALIDATED,
        ], $overrides));
    }

    // ── Tests ───────────────────────────────────────────────────────────────

    public function test_validates_raw_material_line_credits_stock_and_records_movement(): void
    {
        $mat = $this->material('Poulet');
        $doc = $this->document('hash-rm-1');
        $line = $this->line($doc, PurchaseLine::TARGET_RAW_MATERIAL, $mat->id, [
            'qty' => 5, 'unit' => 'g', 'unit_price' => 1.50,
        ]);

        $result = $this->service()->validateDocument($doc);

        $this->assertSame('validated', $result['status']);
        $this->assertSame(1, $result['applied']['raw_material']);

        $onHand = RawMaterialStock::query()
            ->where('raw_material_id', $mat->id)->where('branch_id', 1)->value('on_hand');
        $this->assertEqualsWithDelta(5.0, (float) $onHand, 0.0001);

        $movement = RawMaterialMovement::query()
            ->where('raw_material_id', $mat->id)
            ->where('source_type', 'purchase_line')
            ->where('source_id', $line->id)
            ->first();
        $this->assertNotNull($movement, 'Le mouvement matière doit être tracé par (purchase_line, line.id).');
        $this->assertSame('purchase', $movement->reason);
        $this->assertEqualsWithDelta(5.0, (float) $movement->delta, 0.0001);
    }

    public function test_recomputes_weighted_average_cost_to_the_cent(): void
    {
        $mat = $this->material('Viande hachée'); // avg_cost NULL au départ

        // 1er achat : 10 unités @ 2,00 → premier achat fixe avg_cost = prix unitaire.
        $doc1 = $this->document('hash-avg-1');
        $this->line($doc1, PurchaseLine::TARGET_RAW_MATERIAL, $mat->id, ['qty' => 10, 'unit' => 'g', 'unit_price' => 2.00]);
        $this->service()->validateDocument($doc1);

        $this->assertEqualsWithDelta(2.0, (float) $mat->fresh()->avg_cost, 0.0001);
        $this->assertEqualsWithDelta(10.0, (float) RawMaterialStock::query()
            ->where('raw_material_id', $mat->id)->where('branch_id', 1)->value('on_hand'), 0.0001);

        // 2e achat : 10 unités @ 3,00 → (10×2 + 10×3) / 20 = 2,50 pile.
        $doc2 = $this->document('hash-avg-2');
        $this->line($doc2, PurchaseLine::TARGET_RAW_MATERIAL, $mat->id, ['qty' => 10, 'unit' => 'g', 'unit_price' => 3.00]);
        $this->service()->validateDocument($doc2);

        $this->assertEqualsWithDelta(2.5, (float) $mat->fresh()->avg_cost, 0.0001);
        $this->assertEqualsWithDelta(20.0, (float) RawMaterialStock::query()
            ->where('raw_material_id', $mat->id)->where('branch_id', 1)->value('on_hand'), 0.0001);
    }

    public function test_raw_material_line_without_unit_price_leaves_avg_cost_null(): void
    {
        $mat = $this->material('Cheddar', null, 'tranche');
        $doc = $this->document('hash-noprice');
        // unit_price NULL (héritée du helper) → stock crédité mais avg_cost intouché.
        $this->line($doc, PurchaseLine::TARGET_RAW_MATERIAL, $mat->id, ['qty' => 40, 'unit' => 'tranche']);

        $this->service()->validateDocument($doc);

        $this->assertNull($mat->fresh()->avg_cost, 'Sans prix connu, avg_cost ne doit PAS devenir 0 trompeur.');
        $this->assertEqualsWithDelta(40.0, (float) RawMaterialStock::query()
            ->where('raw_material_id', $mat->id)->where('branch_id', 1)->value('on_hand'), 0.0001);
    }

    /**
     * [S2 V3 D-3 2026-07-29] Une RÉCEPTION doit LEVER la rupture automatique.
     *
     * Défaut trouvé en vague 3 : PurchaseService écrivait `stock_levels.on_hand`
     * en direct, sans passer par la synchro de disponibilité de StockService.
     * Conséquence terrain : un produit auto-86 (on_hand=0) restait INVENDABLE
     * après réception de la marchandise, jusqu'à un 86 manuel — et le cron
     * préventif qui aurait rattrapé est désactivé. Vente perdue silencieuse.
     */
    public function test_stock_item_reception_lifts_auto_rupture_and_notifies(): void
    {
        \Illuminate\Support\Facades\Event::fake([\App\Events\ItemAvailabilityChanged::class]);

        $boisson = $this->item('Perrier 33cl');

        // État de départ : rupture AUTOMATIQUE (stock épuisé par les ventes).
        StockLevel::query()->create([
            'branch_id' => 1,
            'stockable_type' => Item::class,
            'stockable_id' => $boisson->id,
            'on_hand' => 0,
        ]);
        \App\Models\ItemBranchAvailability::query()->create([
            'item_id' => $boisson->id,
            'branch_id' => 1,
            'is_available' => false,
            'unavailable_reason' => 'stock_rupture',
            'manual_unavailable_since' => null,
            'daily_reset_at' => now()->toDateString(),
        ]);

        $doc = $this->document('hash-reception-86');
        $this->line($doc, PurchaseLine::TARGET_STOCK_ITEM, $boisson->id, [
            'qty' => 12, 'unit' => 'piece', 'unit_price' => 0.60,
        ]);

        $this->service()->validateDocument($doc);

        $row = \App\Models\ItemBranchAvailability::query()
            ->where('item_id', $boisson->id)->where('branch_id', 1)->first();

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->is_available,
            'Après réception de 12 unités, le produit doit redevenir vendable.'
        );

        // Les surfaces (caisse / borne / KDS) doivent être notifiées du retour en stock.
        \Illuminate\Support\Facades\Event::assertDispatched(
            \App\Events\ItemAvailabilityChanged::class,
            fn ($e) => (int) $e->itemId === (int) $boisson->id && $e->isAvailable === true
        );
    }

    /**
     * [S2 V3 D-3 2026-07-29] Miroir négatif : un 86 MANUEL (posé par un humain)
     * ne doit JAMAIS être levé par une réception — sinon la réception
     * s'approprierait la décision du gérant (bug « friteuse » déjà tranché
     * côté StockService, on garde la même règle ici).
     */
    public function test_stock_item_reception_never_lifts_manual_86(): void
    {
        $boisson = $this->item('Oasis 33cl');

        StockLevel::query()->create([
            'branch_id' => 1,
            'stockable_type' => Item::class,
            'stockable_id' => $boisson->id,
            'on_hand' => 0,
        ]);
        \App\Models\ItemBranchAvailability::query()->create([
            'item_id' => $boisson->id,
            'branch_id' => 1,
            'is_available' => false,
            'unavailable_reason' => 'stock_rupture',
            'manual_unavailable_since' => now(),
            'daily_reset_at' => now()->toDateString(),
        ]);

        $doc = $this->document('hash-reception-manual86');
        $this->line($doc, PurchaseLine::TARGET_STOCK_ITEM, $boisson->id, [
            'qty' => 24, 'unit' => 'piece', 'unit_price' => 0.60,
        ]);

        $this->service()->validateDocument($doc);

        $row = \App\Models\ItemBranchAvailability::query()
            ->where('item_id', $boisson->id)->where('branch_id', 1)->first();

        $this->assertFalse(
            (bool) $row->is_available,
            'Un 86 manuel doit survivre à la réception de marchandise.'
        );
    }

    public function test_stock_item_line_increments_stock_levels_by_units(): void
    {
        $boisson = $this->item('Coca 33cl');
        $doc = $this->document('hash-boisson');
        $line = $this->line($doc, PurchaseLine::TARGET_STOCK_ITEM, $boisson->id, [
            'qty' => 24, 'unit' => 'piece', 'unit_price' => 0.60,
        ]);

        $result = $this->service()->validateDocument($doc);
        $this->assertSame(1, $result['applied']['stock_item']);

        $level = StockLevel::query()
            ->where('branch_id', 1)
            ->where('stockable_type', Item::class)
            ->where('stockable_id', $boisson->id)
            ->first();
        $this->assertNotNull($level);
        $this->assertSame(24, (int) $level->on_hand);

        $movement = StockMovement::query()
            ->where('idempotency_key', 'purchase_line:'.$line->id)->first();
        $this->assertNotNull($movement, 'Un mouvement stock_levels manual_in doit être tracé.');
        $this->assertSame('manual_in', $movement->reason);
        $this->assertSame(24, (int) $movement->delta);

        // La couche matière ne doit PAS avoir été touchée (1 vérité par objet).
        $this->assertSame(0, RawMaterialMovement::query()->count());
    }

    public function test_charge_line_writes_no_stock(): void
    {
        $doc = $this->document('hash-charge');
        $this->line($doc, PurchaseLine::TARGET_CHARGE, null, [
            'raw_label' => 'ELECTRICITE', 'qty' => 1, 'unit_price' => 120.00,
        ]);

        $result = $this->service()->validateDocument($doc);

        $this->assertSame(1, $result['applied']['charge']);
        $this->assertSame(0, RawMaterialMovement::query()->count());
        $this->assertSame(0, RawMaterialStock::query()->count());
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_revalidating_a_document_is_a_noop(): void
    {
        $mat = $this->material('Pain');
        $doc = $this->document('hash-idem');
        $this->line($doc, PurchaseLine::TARGET_RAW_MATERIAL, $mat->id, ['qty' => 8, 'unit' => 'g', 'unit_price' => 0.25]);

        $this->service()->validateDocument($doc);
        $afterFirst = (float) $mat->fresh()->avg_cost;

        // Re-valider : gate statut → no-op (stock, avg_cost, mouvements inchangés).
        $result = $this->service()->validateDocument($doc->fresh());

        $this->assertSame('noop', $result['status']);
        $this->assertEqualsWithDelta($afterFirst, (float) $mat->fresh()->avg_cost, 0.0001);
        $this->assertEqualsWithDelta(8.0, (float) RawMaterialStock::query()
            ->where('raw_material_id', $mat->id)->where('branch_id', 1)->value('on_hand'), 0.0001);
        $this->assertSame(1, RawMaterialMovement::query()->where('raw_material_id', $mat->id)->count());
        $this->assertSame(PurchaseDocument::STATUS_VALIDATED, $doc->fresh()->status);
    }

    public function test_proposed_lines_are_skipped(): void
    {
        $mat = $this->material('Sauce');
        $doc = $this->document('hash-proposed');
        // Ligne encore PROPOSED (owner n'a pas validé la cible) → ignorée.
        $this->line($doc, PurchaseLine::TARGET_RAW_MATERIAL, $mat->id, [
            'qty' => 3, 'unit_price' => 1.00, 'status' => PurchaseLine::STATUS_PROPOSED,
        ]);

        $result = $this->service()->validateDocument($doc);

        $this->assertSame(0, $result['applied']['raw_material']);
        $this->assertSame(1, $result['applied']['skipped_proposed']);
        $this->assertSame(0, RawMaterialMovement::query()->count());
        $this->assertNull($mat->fresh()->avg_cost);
    }

    public function test_doc_hash_is_unique(): void
    {
        $this->document('hash-dup');

        $this->expectException(QueryException::class);
        $this->document('hash-dup'); // même hash → contrainte UNIQUE viole.
    }

    public function test_supplier_soft_delete(): void
    {
        $supplier = Supplier::create(['branch_id' => 1, 'name' => 'Metro', 'contact' => 'x']);

        $supplier->delete();

        $this->assertNull(Supplier::query()->find($supplier->id), 'Le scope par défaut exclut les trashed.');
        $this->assertNotNull(Supplier::withTrashed()->find($supplier->id));
        $this->assertTrue($supplier->fresh()->trashed());
    }
}
