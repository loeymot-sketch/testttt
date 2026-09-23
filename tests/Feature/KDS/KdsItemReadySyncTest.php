<?php

namespace Tests\Feature\KDS;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Models\Branch;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [KDS-ITEM-READY-SYNC 2026-09-23] Audit "Plan de correction complet" finding
 * #4 : "États KDS non synchronisés. L'interface indique que certaines
 * pastilles « prêt » sont stockées dans le navigateur et non synchronisées."
 *
 * Confirmé par audit read-only (resources/js/store/modules/kds.js) : la
 * pastille "prêt" par article vivait EXCLUSIVEMENT en localStorage
 * (STORAGE_BUMPED), aucun appel réseau. Un second écran/appareil affichait un
 * état différent pour la même commande en cours de préparation.
 *
 * Ce fichier prouve le nouveau SSOT serveur : OrderItem.kitchen_bumped_at +
 * KitchenDisplaySystemController::itemBump/itemRecall.
 */
class KdsItemReadySyncTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branche;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    private function commandeEnPreparation(): Order
    {
        $this->branche = Branch::factory()->create();

        return Order::factory()->create([
            'branch_id' => $this->branche->id,
            'subtotal' => 12.00, 'discount' => 0.00, 'total_tax' => 0.00,
            'delivery_charge' => 0.00, 'total' => 12.00,
            'status'             => OrderStatus::PREPARING,
            'payment_status'     => PaymentStatus::PAID,
            'payment_method'     => PaymentGateway::CASH_ON_DELIVERY,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'order_type'         => OrderType::TAKEAWAY,
            'source_surface'     => 'pos',
        ]);
    }

    private function ligne(Order $order): OrderItem
    {
        $item = Item::factory()->create();

        return OrderItem::create([
            'order_id' => $order->id,
            'branch_id' => $order->branch_id,
            'item_id' => $item->id,
            'quantity' => 1,
            'price' => 12,
            'discount' => 0,
            'item_variation_total' => 0,
            'item_extra_total' => 0,
            'total_price' => 12,
            'item_variations' => '[]',
            'item_extras' => '[]',
            'instruction' => '',
        ]);
    }

    private function chef(): User
    {
        $chef = User::factory()->create(['branch_id' => $this->branche->id]);
        $chef->assignRole('Chef');

        return $chef;
    }

    /** @test */
    public function bumper_un_article_persiste_kitchen_bumped_at_cote_serveur(): void
    {
        $order = $this->commandeEnPreparation();
        $ligne = $this->ligne($order);

        $this->assertNull($ligne->fresh()->kitchen_bumped_at,
            'un article frais ne doit pas déjà être marqué prêt');

        $this->actingAs($this->chef(), 'sanctum')
            ->postJson("/api/admin/kds-order/items/{$ligne->id}/bump")
            ->assertStatus(200)
            ->assertJson(['status' => true]);

        $this->assertNotNull($ligne->fresh()->kitchen_bumped_at,
            'un second écran KDS qui recharge cette commande doit voir le même article marqué prêt');
    }

    /** @test */
    public function bumper_deux_fois_le_meme_article_est_idempotent_pas_d_erreur_pas_de_second_horodatage(): void
    {
        $order = $this->commandeEnPreparation();
        $ligne = $this->ligne($order);
        $chef = $this->chef();

        $this->actingAs($chef, 'sanctum')
            ->postJson("/api/admin/kds-order/items/{$ligne->id}/bump")
            ->assertStatus(200);
        $premierHorodatage = $ligne->fresh()->kitchen_bumped_at;

        $this->travel(5)->seconds();

        $this->actingAs($chef, 'sanctum')
            ->postJson("/api/admin/kds-order/items/{$ligne->id}/bump")
            ->assertStatus(200);

        $this->assertTrue($premierHorodatage->equalTo($ligne->fresh()->kitchen_bumped_at),
            'un rebump ne doit jamais avancer l\'horodatage déjà posé');
    }

    /** @test */
    public function annuler_le_bump_dans_la_fenetre_de_grace_efface_l_horodatage(): void
    {
        $order = $this->commandeEnPreparation();
        $ligne = $this->ligne($order);
        $chef = $this->chef();

        $this->actingAs($chef, 'sanctum')
            ->postJson("/api/admin/kds-order/items/{$ligne->id}/bump")
            ->assertStatus(200);

        $this->actingAs($chef, 'sanctum')
            ->postJson("/api/admin/kds-order/items/{$ligne->id}/recall")
            ->assertStatus(200)
            ->assertJson(['status' => true, 'recalled' => true]);

        $this->assertNull($ligne->fresh()->kitchen_bumped_at);
    }

    /** @test */
    public function annuler_le_bump_apres_la_fenetre_de_60s_est_refuse_cote_serveur(): void
    {
        // [robustesse] Le délai de grâce 60s existe déjà côté client
        // (kds.js:71 `now - b[itemId] >= 60000`) — un appel API direct ne
        // doit pas pouvoir le contourner.
        $order = $this->commandeEnPreparation();
        $ligne = $this->ligne($order);
        $chef = $this->chef();

        $this->actingAs($chef, 'sanctum')
            ->postJson("/api/admin/kds-order/items/{$ligne->id}/bump")
            ->assertStatus(200);

        $this->travel(61)->seconds();

        $this->actingAs($chef, 'sanctum')
            ->postJson("/api/admin/kds-order/items/{$ligne->id}/recall")
            ->assertStatus(422);

        $this->assertNotNull($ligne->fresh()->kitchen_bumped_at,
            'un rappel refusé ne doit pas effacer l\'horodatage');
    }

    /** @test */
    public function annuler_un_article_jamais_bumpe_est_refuse(): void
    {
        $order = $this->commandeEnPreparation();
        $ligne = $this->ligne($order);

        $this->actingAs($this->chef(), 'sanctum')
            ->postJson("/api/admin/kds-order/items/{$ligne->id}/recall")
            ->assertStatus(422);
    }

    /** @test */
    public function un_compte_sans_droit_cuisine_ne_peut_pas_bumper(): void
    {
        // Miroir exact de KdsReopenPermissionGuardTest — même liste
        // d'inclusion `->only(...)`, même risque d'oubli si jamais retiré.
        $order = $this->commandeEnPreparation();
        $ligne = $this->ligne($order);

        $client = User::factory()->create(['branch_id' => 0]);
        $client->assignRole('Customer');
        $this->assertFalse($client->can('kitchen-display-system'),
            'le banc ne prouverait rien si ce rôle avait déjà le droit cuisine');

        $this->actingAs($client, 'sanctum')
            ->postJson("/api/admin/kds-order/items/{$ligne->id}/bump")
            ->assertStatus(403);

        $this->assertNull($ligne->fresh()->kitchen_bumped_at);
    }

    /** @test */
    public function un_article_d_une_autre_succursale_est_hors_de_portee_404_a_la_liaison(): void
    {
        $order = $this->commandeEnPreparation();
        $ligne = $this->ligne($order);

        $autreBranche = Branch::factory()->create();
        $chefAutreBranche = User::factory()->create(['branch_id' => $autreBranche->id]);
        $chefAutreBranche->assignRole('Chef');

        $this->actingAs($chefAutreBranche, 'sanctum')
            ->postJson("/api/admin/kds-order/items/{$ligne->id}/bump")
            ->assertStatus(404);

        $this->assertNull($ligne->fresh()->kitchen_bumped_at);
    }
}
