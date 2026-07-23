<?php

namespace Tests\Feature\RawMaterials;

use App\Enums\Status;
use App\Events\OrderCanceled;
use App\Events\OrderCreated;
use App\Listeners\ConsumeRawMaterialsOnOrderCreated;
use App\Listeners\ReverseRawMaterialsOnOrderCanceled;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\RawMaterial;
use App\Models\RawMaterialMovement;
use App\Models\RawMaterialRecipeLine;
use App\Models\RawMaterialStock;
use App\Models\User;
use App\Services\RawMaterials\RawMaterialConsumptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * [ARCH_STOCK_INTELLIGENT_BOM_2026-07-23 / P2a — B3] Moteur de consommation
 * matière théorique : résolution recette (produit/variation/extra), agrégation
 * somme-puis-consomme, idempotence de rejeu, groupes de nom, multiplicateurs,
 * skip loggé, stock signé, câblage listener.
 *
 * NF525 : domaine ADDITIF — lit les snapshots, aucune assertion fiscale.
 */
class RawMaterialConsumptionTest extends TestCase
{
    use RefreshDatabase;

    private ItemCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = ItemCategory::create([
            'name' => 'Sandwichs',
            'slug' => 'sandwichs-'.Str::random(6),
            'status' => Status::ACTIVE,
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function service(): RawMaterialConsumptionService
    {
        return app(RawMaterialConsumptionService::class);
    }

    private function material(string $name, string $unit = 'g'): RawMaterial
    {
        return RawMaterial::create([
            'branch_id' => 1,
            'name' => $name,
            'unit' => $unit,
            'is_active' => true,
        ]);
    }

    /** Ligne de recette générique (branch 1). group non-null → ligne "groupe". */
    private function recipe(string $subjectType, int $subjectId, RawMaterial $mat, float $qty, ?string $group = null): RawMaterialRecipeLine
    {
        return RawMaterialRecipeLine::create([
            'branch_id' => 1,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'subject_group' => $group,
            'raw_material_id' => $mat->id,
            'qty' => $qty,
        ]);
    }

    private function makeItem(string $name): Item
    {
        return Item::forceCreate([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(6),
            'item_category_id' => $this->category->id,
            'item_type' => 1,
            'price' => 10,
            'status' => Status::ACTIVE,
        ]);
    }

    private function makeOrder(): Order
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);

        return Order::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => $user->id,
        ]);
    }

    /** @param array<string,mixed> $snapshot */
    private function makeOrderItem(Order $order, Item $item, int $qty, array $snapshot = []): OrderItem
    {
        return OrderItem::create([
            'order_id' => $order->id,
            'branch_id' => 1,
            'item_id' => $item->id,
            'quantity' => $qty,
            'price' => 10,
            'discount' => 0,
            'item_variation_total' => 0,
            'item_extra_total' => 0,
            'total_price' => 10 * $qty,
            'item_variations' => '[]',
            'item_extras' => '[]',
            'composition_snapshot' => $snapshot ?: ['lines' => [], 'extras' => [], 'addons' => []],
            'instruction' => '',
        ]);
    }

    private function onHand(RawMaterial $mat): float
    {
        return (float) RawMaterialStock::where('raw_material_id', $mat->id)->value('on_hand');
    }

    // ── 1. Produit + extra : bonnes matières, bonnes quantités (+ stock signé). ─
    public function test_product_and_extra_consume_correct_materials_and_quantities(): void
    {
        $pain = $this->material('Pain', 'piece');
        $poulet = $this->material('Poulet');
        $champ = $this->material('Champignons');

        $cayenne = $this->makeItem('Cayenne');
        $this->recipe(Item::class, $cayenne->id, $pain, 1);
        $this->recipe(Item::class, $cayenne->id, $poulet, 200);
        $this->recipe(ItemExtra::class, 430, $champ, 30); // extra par id

        $order = $this->makeOrder();
        $this->makeOrderItem($order, $cayenne, 1, [
            'lines' => [],
            'extras' => [['extra_id' => 430, 'extra_name' => 'Champignons', 'quantity' => 1]],
            'addons' => [],
        ]);

        $summary = $this->service()->consumeForOrder($order);

        $this->assertEqualsWithDelta(-1.0, $this->onHand($pain), 0.001);
        $this->assertEqualsWithDelta(-200.0, $this->onHand($poulet), 0.001); // stock signé négatif (pas d'appro)
        $this->assertEqualsWithDelta(-30.0, $this->onHand($champ), 0.001);
        $this->assertCount(3, $summary['consumed']);
        $this->assertSame([], $summary['skipped']);
    }

    // ── 2. Multiplicateurs : order_item.quantity ET extra.quantity. ────────────
    public function test_quantities_multiply_by_line_and_extra_quantity(): void
    {
        $poulet = $this->material('Poulet');
        $bacon = $this->material('Bacon');

        $item = $this->makeItem('Chicken Burger');
        $this->recipe(Item::class, $item->id, $poulet, 200);
        $this->recipe(ItemExtra::class, 88, $bacon, 10);

        $order = $this->makeOrder();
        $this->makeOrderItem($order, $item, 3, [ // 3 sandwichs
            'lines' => [],
            'extras' => [['extra_id' => 88, 'extra_name' => 'Bacon', 'quantity' => 2]], // ×2 bacon
            'addons' => [],
        ]);

        $this->service()->consumeForOrder($order);

        $this->assertEqualsWithDelta(-600.0, $this->onHand($poulet), 0.001); // 200 × 3
        $this->assertEqualsWithDelta(-60.0, $this->onHand($bacon), 0.001);   // 10 × 3 × 2
    }

    // ── 3. Extra résolu par GROUPE de nom (subject_group), pas par id. ─────────
    public function test_extra_resolved_by_subject_group_when_no_id_line(): void
    {
        $sauce = $this->material('Sauce maison');

        // Ligne "groupe" : subject_id sentinelle 0, subject_group normalisé.
        $this->recipe(
            ItemExtra::class,
            0,
            $sauce,
            25,
            RawMaterialConsumptionService::normalizeGroup('Sauce  Algérienne')
        );

        $item = $this->makeItem('Tacos');
        $order = $this->makeOrder();
        // extra_id 9999 n'a AUCUNE ligne id → seul le groupe (nom) doit matcher.
        $this->makeOrderItem($order, $item, 1, [
            'lines' => [],
            'extras' => [['extra_id' => 9999, 'extra_name' => 'Sauce Algérienne', 'quantity' => 1]],
            'addons' => [],
        ]);

        $summary = $this->service()->consumeForOrder($order);

        $this->assertEqualsWithDelta(-25.0, $this->onHand($sauce), 0.001);
        $this->assertCount(1, $summary['consumed']);
    }

    // ── 4. Variation résolue par variation_id (snapshot.lines). ────────────────
    public function test_variation_resolved_by_variation_id(): void
    {
        $viande = $this->material('Viande hachée');

        $this->recipe(ItemVariation::class, 570, $viande, 75);

        $item = $this->makeItem('Méga');
        $order = $this->makeOrder();
        $this->makeOrderItem($order, $item, 1, [
            'lines' => [['variation_id' => 570, 'attribute_id' => 1, 'quantity' => 1]],
            'extras' => [],
            'addons' => [],
        ]);

        $this->service()->consumeForOrder($order);

        $this->assertEqualsWithDelta(-75.0, $this->onHand($viande), 0.001);
    }

    // ── 5. Produit + extra sur la MÊME matière = SOMMÉ, un seul mouvement. ─────
    //     (crux : agréger AVANT consume, sinon l'idempotence droppe la 2ᵉ qty.)
    public function test_same_material_from_product_and_extra_is_summed_into_one_movement(): void
    {
        $sauce = $this->material('Sauce maison');

        $item = $this->makeItem('Tacos');
        // Recette produit : sauce 25 g.
        $this->recipe(Item::class, $item->id, $sauce, 25);
        // Recette extra "sauce supplémentaire" (par groupe) : sauce 25 g.
        $this->recipe(ItemExtra::class, 0, $sauce, 25, RawMaterialConsumptionService::normalizeGroup('Sauce supplémentaire'));

        $order = $this->makeOrder();
        $this->makeOrderItem($order, $item, 1, [
            'lines' => [],
            'extras' => [['extra_id' => 430, 'extra_name' => 'Sauce supplémentaire', 'quantity' => 1]],
            'addons' => [],
        ]);

        $orderItemId = OrderItem::where('order_id', $order->id)->value('id');

        $this->service()->consumeForOrder($order);

        // 25 (produit) + 25 (extra) = 50, PAS 25 (dédup) ni 2 mouvements.
        $this->assertEqualsWithDelta(-50.0, $this->onHand($sauce), 0.001);
        $movements = RawMaterialMovement::where('raw_material_id', $sauce->id)
            ->where('source_type', 'order_item')
            ->where('source_id', $orderItemId)
            ->get();
        $this->assertCount(1, $movements);
        $this->assertEqualsWithDelta(-50.0, (float) $movements->first()->delta, 0.001);
    }

    // ── 6. Rejeu de la commande = pas de double consommation (idempotence). ────
    public function test_replaying_order_does_not_double_consume(): void
    {
        $poulet = $this->material('Poulet');
        $item = $this->makeItem('Cayenne');
        $this->recipe(Item::class, $item->id, $poulet, 200);

        $order = $this->makeOrder();
        $this->makeOrderItem($order, $item, 1);

        $this->service()->consumeForOrder($order);
        $this->service()->consumeForOrder($order); // rejeu (retry queue / replay historique)

        $this->assertEqualsWithDelta(-200.0, $this->onHand($poulet), 0.001); // pas -400
        $this->assertSame(1, RawMaterialMovement::where('raw_material_id', $poulet->id)->count());
    }

    // ── 7. Produit sans recette = 0 conso, aucune erreur. ─────────────────────
    public function test_product_without_recipe_consumes_nothing(): void
    {
        $item = $this->makeItem('Boisson Coca'); // aucune ligne de recette
        $order = $this->makeOrder();
        $this->makeOrderItem($order, $item, 2);

        $summary = $this->service()->consumeForOrder($order);

        $this->assertSame([], $summary['consumed']);
        $this->assertSame(0, RawMaterialMovement::count());
        $this->assertSame(0, RawMaterialStock::count());
        $this->assertNotEmpty($summary['skipped']); // rangé en skipped (no_recipe)
    }

    // ── 8. Supplément générique sans recette = skip LOGGÉ (pas d'erreur). ──────
    public function test_generic_supplement_without_recipe_is_skipped_and_logged(): void
    {
        Log::spy();

        $poulet = $this->material('Poulet');
        $item = $this->makeItem('Cayenne');
        $this->recipe(Item::class, $item->id, $poulet, 200);

        $order = $this->makeOrder();
        $this->makeOrderItem($order, $item, 1, [
            'lines' => [],
            // "Viande supplémentaire" générique : AUCUNE ligne de recette (ni id ni groupe).
            'extras' => [['extra_id' => 777, 'extra_name' => 'Viande supplémentaire', 'quantity' => 1]],
            'addons' => [],
        ]);

        $summary = $this->service()->consumeForOrder($order);

        // Le produit est bien consommé ; seul l'extra sans recette est ignoré.
        $this->assertEqualsWithDelta(-200.0, $this->onHand($poulet), 0.001);
        $this->assertCount(1, $summary['skipped']);
        $this->assertSame('extra_no_recipe', $summary['skipped'][0]['kind']);
        $this->assertSame('Viande supplémentaire', $summary['skipped'][0]['extra_name']);
        Log::shouldHaveReceived('info')->atLeast()->once();
    }

    // ── 9. Listener câblé sur OrderCreated. ───────────────────────────────────
    public function test_listener_is_registered_for_order_created(): void
    {
        Event::fake();

        Event::assertListening(OrderCreated::class, ConsumeRawMaterialsOnOrderCreated::class);
    }

    // ── 10. handle() du listener déclenche bien la consommation. ──────────────
    public function test_listener_handle_triggers_consumption(): void
    {
        $poulet = $this->material('Poulet');
        $item = $this->makeItem('Cayenne');
        $this->recipe(Item::class, $item->id, $poulet, 200);

        $order = $this->makeOrder();
        $this->makeOrderItem($order, $item, 1);

        (new ConsumeRawMaterialsOnOrderCreated())->handle(new OrderCreated($order));

        $this->assertEqualsWithDelta(-200.0, $this->onHand($poulet), 0.001);
        $this->assertSame(1, RawMaterialMovement::where('raw_material_id', $poulet->id)->count());
    }

    // ══ B-1 — Reprise (rendu) du stock sur annulation/refus/retour ═════════════
    //   Convergence avec le replay (qui EXCLUT CANCELED/REJECTED/RETURNED) : la
    //   conso LIVE doit RENDRE le stock quand l'order atteint un de ces statuts,
    //   sinon `on_hand` sur-consomme définitivement (B-1). Miroir raw-material du
    //   ReleaseStockOnOrderCanceled (stock_levels), câblé sur le même OrderCanceled.

    // ── 11. reverseForOrder rend EXACTEMENT le stock consommé (net nul). ───────
    public function test_reverse_for_order_credits_back_exactly_what_was_consumed(): void
    {
        $poulet = $this->material('Poulet');
        $pain = $this->material('Pain', 'piece');
        $item = $this->makeItem('Cayenne');
        $this->recipe(Item::class, $item->id, $poulet, 200);
        $this->recipe(Item::class, $item->id, $pain, 1);

        $order = $this->makeOrder();
        $this->makeOrderItem($order, $item, 1);

        $this->service()->consumeForOrder($order);
        $this->assertEqualsWithDelta(-200.0, $this->onHand($poulet), 0.001);
        $this->assertEqualsWithDelta(-1.0, $this->onHand($pain), 0.001);

        $summary = $this->service()->reverseForOrder($order);

        // Rendu exact → net nul sur chaque matière.
        $this->assertEqualsWithDelta(0.0, $this->onHand($poulet), 0.001);
        $this->assertEqualsWithDelta(0.0, $this->onHand($pain), 0.001);
        $this->assertCount(2, $summary['reversed']);
        // Le mouvement de reprise porte un source_type DÉDIÉ (≠ conso).
        $this->assertSame(
            1,
            RawMaterialMovement::where('source_type', 'order_item_reversal')
                ->where('raw_material_id', $poulet->id)->count()
        );
    }

    // ── 12. Double reverse = pas de double crédit (idempotent). ────────────────
    public function test_reverse_is_idempotent_no_double_credit(): void
    {
        $poulet = $this->material('Poulet');
        $item = $this->makeItem('Cayenne');
        $this->recipe(Item::class, $item->id, $poulet, 200);

        $order = $this->makeOrder();
        $this->makeOrderItem($order, $item, 1);

        $this->service()->consumeForOrder($order);
        $this->service()->reverseForOrder($order);
        $second = $this->service()->reverseForOrder($order); // ré-annulation

        $this->assertEqualsWithDelta(0.0, $this->onHand($poulet), 0.001); // pas +200
        $this->assertSame([], $second['reversed']);
        $this->assertNotEmpty($second['skipped']); // rangé "already_reversed"
        $this->assertSame(
            1,
            RawMaterialMovement::where('source_type', 'order_item_reversal')
                ->where('raw_material_id', $poulet->id)->count()
        );
    }

    // ── 13. Commande jamais consommée → reverse = no-op total. ─────────────────
    public function test_reverse_never_consumed_order_is_noop(): void
    {
        $item = $this->makeItem('Boisson Coca'); // aucune recette → aucune conso
        $order = $this->makeOrder();
        $this->makeOrderItem($order, $item, 1);

        $summary = $this->service()->reverseForOrder($order);

        $this->assertSame([], $summary['reversed']);
        $this->assertSame(0, RawMaterialMovement::count()); // rien consommé, rien rendu
    }

    // ── 14. Après reverse, un rejeu de conso ne re-consomme pas (net stable). ──
    public function test_reverse_then_replay_stays_coherent(): void
    {
        $poulet = $this->material('Poulet');
        $item = $this->makeItem('Cayenne');
        $this->recipe(Item::class, $item->id, $poulet, 200);

        $order = $this->makeOrder();
        $this->makeOrderItem($order, $item, 1);

        $this->service()->consumeForOrder($order);
        $this->service()->reverseForOrder($order);
        // Rejeu conso (retry queue / replay) : idempotence source 'order_item' → no-op.
        $this->service()->consumeForOrder($order);

        $this->assertEqualsWithDelta(0.0, $this->onHand($poulet), 0.001); // reste 0, pas -200
        $this->assertSame(
            1,
            RawMaterialMovement::where('source_type', 'order_item')
                ->where('raw_material_id', $poulet->id)->count()
        );
        $this->assertSame(
            1,
            RawMaterialMovement::where('source_type', 'order_item_reversal')
                ->where('raw_material_id', $poulet->id)->count()
        );
    }

    // ── 15. Listener câblé sur OrderCanceled (CANCELED/REJECTED/RETURNED). ─────
    public function test_reverse_listener_is_registered_for_order_canceled(): void
    {
        Event::fake();

        Event::assertListening(OrderCanceled::class, ReverseRawMaterialsOnOrderCanceled::class);
    }

    // ── 16. handle() du listener rend le stock. ───────────────────────────────
    public function test_reverse_listener_handle_credits_stock_back(): void
    {
        $poulet = $this->material('Poulet');
        $item = $this->makeItem('Cayenne');
        $this->recipe(Item::class, $item->id, $poulet, 200);

        $order = $this->makeOrder();
        $this->makeOrderItem($order, $item, 1);
        $this->service()->consumeForOrder($order);

        (new ReverseRawMaterialsOnOrderCanceled())->handle(new OrderCanceled($order));

        $this->assertEqualsWithDelta(0.0, $this->onHand($poulet), 0.001);
    }
}
