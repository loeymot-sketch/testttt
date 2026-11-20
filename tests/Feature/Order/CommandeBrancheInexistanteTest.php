<?php

namespace Tests\Feature\Order;

use App\Enums\OrderType;
use App\Enums\Source;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * [W7-D D-004 2026-11-04] `branch_id` n'était que `numeric` : une commande web rattachée à une branche inexistante,
 * supprimée ou inactive était PERSISTÉE, puis la requête finissait en **500** silencieux
 * (`OrderMailNotificationBuilder::$order` typé `object` recevait `null`). Le client voyait « Server Error » alors que
 * sa commande existait, orpheline. Règle : la branche doit exister, ne pas être supprimée ni inactive → 422 propre,
 * rien d'écrit.
 */
class CommandeBrancheInexistanteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        config(['app.api_key' => 'test-api-key']);
        $this->withHeaders(['x-api-key' => 'test-api-key', 'Accept' => 'application/json']);
    }

    private function charge(int $branchId): array
    {
        $category = ItemCategory::forceCreate(['name' => 'Cat', 'slug' => 'cat-w7', 'status' => Status::ACTIVE]);
        $item = Item::forceCreate([
            'name' => 'Article W7', 'slug' => 'article-w7', 'price' => 10.00,
            'status' => Status::ACTIVE, 'item_category_id' => $category->id,
        ]);

        return [
            'branch_id'        => $branchId,
            'subtotal'         => 10,
            'total'            => 10,
            'order_type'       => OrderType::TAKEAWAY,
            'is_advance_order' => 0,
            'source'           => Source::WEB,
            'items'            => json_encode([
                ['item_id' => $item->id, 'quantity' => 1, 'item_variations' => [], 'item_extras' => []],
            ]),
        ];
    }

    private function client(): User
    {
        $u = User::forceCreate([
            'name' => 'Client W7', 'email' => 'client-w7@example.test', 'username' => 'client-w7',
            'password' => bcrypt('x'), 'status' => 5, 'branch_id' => 0,
        ]);
        Sanctum::actingAs($u, ['kiosk:order']);

        return $u;
    }

    /** @test */
    public function une_branche_inexistante_est_refusee_proprement(): void
    {
        Branch::forceCreate(['name' => 'Le Cayenne', 'city' => 'Hénin', 'state' => 'HdF', 'zip_code' => '62110', 'address' => '1 rue', 'status' => Status::ACTIVE]);
        $this->client();

        $r = $this->postJson('/api/frontend/order', $this->charge(999999));

        $r->assertStatus(422);
        $r->assertJsonValidationErrors(['branch_id']);
        $this->assertDatabaseMissing('orders', ['branch_id' => 999999]);
    }

    /** @test */
    public function une_branche_supprimee_ou_inactive_est_refusee_proprement(): void
    {
        $supprimee = Branch::forceCreate(['name' => 'Fermée', 'city' => 'X', 'state' => 'Y', 'zip_code' => '00000', 'address' => '-', 'status' => Status::ACTIVE]);
        $supprimee->delete();
        $inactive = Branch::forceCreate(['name' => 'Inactive', 'city' => 'X', 'state' => 'Y', 'zip_code' => '00000', 'address' => '-', 'status' => Status::INACTIVE]);
        $this->client();

        $this->postJson('/api/frontend/order', $this->charge($supprimee->id))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['branch_id']);
        $this->postJson('/api/frontend/order', $this->charge($inactive->id))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['branch_id']);

        $this->assertDatabaseMissing('orders', ['branch_id' => $supprimee->id]);
        $this->assertDatabaseMissing('orders', ['branch_id' => $inactive->id]);
    }

    /**
     * [W7-D D-004 · cycle C 2026-11-04] Une branche au statut 1 (ancien seed de production, convention `BranchFactory`)
     * est commandable : la commande est persistée sur CETTE branche et la requête répond 201, jamais 500 — les neuf
     * constructeurs de notification tolèrent désormais une commande relue hors périmètre de branche (`?object $order`).
     * Un statut inconnu (ni 5 ni 1) est refusé sans commande orpheline.
     *
     * @test
     */
    public function une_branche_au_statut_historique_1_est_commandable_sans_500_et_un_statut_inconnu_est_refuse(): void
    {
        $ancienne = Branch::forceCreate(['name' => 'Ancienne', 'city' => 'X', 'state' => 'Y', 'zip_code' => '00000', 'address' => '-', 'status' => 1]);
        $inconnue = Branch::forceCreate(['name' => 'Inconnue', 'city' => 'X', 'state' => 'Y', 'zip_code' => '00000', 'address' => '-', 'status' => 3]);
        $this->client();

        $this->postJson('/api/frontend/order', $this->charge($ancienne->id))->assertStatus(201);
        $this->assertDatabaseHas('orders', ['branch_id' => $ancienne->id]);

        $this->postJson('/api/frontend/order', $this->charge($inconnue->id))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['branch_id']);
        $this->assertDatabaseMissing('orders', ['branch_id' => $inconnue->id]);
    }
}
