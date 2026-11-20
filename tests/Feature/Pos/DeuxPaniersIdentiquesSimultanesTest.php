<?php

namespace Tests\Feature\Pos;

use App\Enums\Ask;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PosPaymentMethod;
use App\Enums\Source;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\KioskMachine;
use App\Models\Order;
use App\Models\OrderQuote;
use App\Models\Tax;
use App\Models\User;
use App\Services\Order\OrderQuoteService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Pos\Traits\SeedsOpenCashDrawerSession;
use Tests\TestCase;

/**
 * [VG-2026-10-21 R2-001] Deux paniers IDENTIQUES, même compte, même instant : les deux ventes passent.
 *
 * Avant : `OrderQuoteService::findOpenQuote` rendait au second appelant le devis OUVERT du premier
 * (même branche, surface, acteur, intention). Les deux clients tenaient le MÊME jeton à usage unique :
 * la seconde vente tombait en 409 « Ce devis a déjà servi… ». Sous MySQL concurrent, le même partage
 * produisait aussi un interblocage (verrous d'intervalle du `lockForUpdate` sur une ligne absente) et un
 * 422 « No query results for model [App\Models\OrderQuote] » (le devis trouvé par lecture VERROUILLÉE
 * était invisible à la relecture NON verrouillée de l'instantané) — message technique brut à l'écran.
 *
 * Cas réel : deux bornes sur le compte machine, deux caisses `pos@` (multi-terminaux), même article.
 * SQLite ne rejoue pas la concurrence ; la cause, elle, est déterministe : deux devis successifs
 * identiques DOIVENT être deux devis distincts.
 */
class DeuxPaniersIdentiquesSimultanesTest extends TestCase
{
    use RefreshDatabase;
    use SeedsOpenCashDrawerSession;

    public function test_caisse_deux_paniers_identiques_ont_chacun_leur_devis_et_passent_tous_les_deux(): void
    {
        config(['app.api_key' => 'test-api-key']);
        [$operator, $payload] = $this->posFixture();

        $a = $this->actingAs($operator, 'sanctum')->postJson('/api/admin/pos/quote', $payload)->assertOk()->json('data');
        $b = $this->actingAs($operator, 'sanctum')->postJson('/api/admin/pos/quote', $payload)->assertOk()->json('data');

        $this->assertNotSame($a['quote_token'], $b['quote_token'], 'Deux paniers identiques partagent le même devis à usage unique.');
        $this->assertSame(2, OrderQuote::count());

        $ids = [];
        foreach ([$a, $b] as $i => $quote) {
            $response = $this->actingAs($operator, 'sanctum')
                ->withHeader('x-api-key', 'test-api-key')
                ->withHeader('X-Idempotency-Key', 'r2-001-pos-'.$i)
                ->postJson('/api/admin/pos', array_merge($payload, [
                    'quote_token' => $quote['quote_token'],
                    'quote_signature' => $quote['signature'],
                    'total' => $quote['total_ttc'],
                    'pos_received_amount' => $quote['total_ttc'],
                ]));
            $this->assertContains($response->status(), [200, 201], "Vente #{$i} refusée : ".$response->getContent());
            $ids[] = (int) $response->json('data.id');
        }

        $this->assertCount(2, array_unique($ids));
        $this->assertSame(2, Order::withoutGlobalScopes()->whereIn('id', $ids)->count());
    }

    public function test_borne_deux_paniers_identiques_ont_chacun_leur_devis_et_passent_tous_les_deux(): void
    {
        config(['app.api_key' => 'test-api-key']);
        [$kioskUser, $payload] = $this->kioskFixture();

        $a = $this->actingAs($kioskUser, 'sanctum')->postJson('/api/frontend/order/quote', $payload)->assertOk()->json('data');
        $b = $this->actingAs($kioskUser, 'sanctum')->postJson('/api/frontend/order/quote', $payload)->assertOk()->json('data');

        $this->assertNotSame($a['quote_token'], $b['quote_token'], 'Deux paniers identiques partagent le même devis à usage unique.');

        $ids = [];
        foreach ([$a, $b] as $i => $quote) {
            $response = $this->actingAs($kioskUser, 'sanctum')
                ->withHeader('x-api-key', 'test-api-key')
                ->withHeader('X-Idempotency-Key', 'r2-001-kiosk-'.$i)
                ->postJson('/api/frontend/order', $payload + [
                    'quote_token' => $quote['quote_token'],
                    'quote_signature' => $quote['signature'],
                    'total' => $quote['total_ttc'],
                ]);
            $this->assertContains($response->status(), [200, 201], "Commande borne #{$i} refusée : ".$response->getContent());
            $ids[] = (int) $response->json('data.id');
        }

        $this->assertCount(2, array_unique($ids));
    }

    public function test_un_devis_rejoue_avec_son_jeton_reste_le_meme_devis(): void
    {
        [$operator, $payload] = $this->posFixture();

        $a = $this->actingAs($operator, 'sanctum')->postJson('/api/admin/pos/quote', $payload)->assertOk()->json('data');
        $replay = $this->actingAs($operator, 'sanctum')->postJson('/api/admin/pos/quote', $payload + [
            'quote_token' => $a['quote_token'],
            'quote_signature' => $a['signature'],
        ])->assertOk()->json('data');

        $this->assertSame($a['quote_token'], $replay['quote_token']);
        $this->assertSame(1, OrderQuote::count());
    }

    public function test_une_meme_cle_d_idempotence_rejouee_rend_la_meme_commande(): void
    {
        config(['app.api_key' => 'test-api-key', 'idempotency.enabled' => true]);
        [$operator, $payload] = $this->posFixture();

        $quote = $this->actingAs($operator, 'sanctum')->postJson('/api/admin/pos/quote', $payload)->assertOk()->json('data');
        $body = array_merge($payload, [
            'quote_token' => $quote['quote_token'],
            'quote_signature' => $quote['signature'],
            'total' => $quote['total_ttc'],
            'pos_received_amount' => $quote['total_ttc'],
        ]);

        $first = $this->actingAs($operator, 'sanctum')->withHeader('x-api-key', 'test-api-key')
            ->withHeader('X-Idempotency-Key', 'r2-001-meme-cle')->postJson('/api/admin/pos', $body);
        $second = $this->actingAs($operator, 'sanctum')->withHeader('x-api-key', 'test-api-key')
            ->withHeader('X-Idempotency-Key', 'r2-001-meme-cle')->postJson('/api/admin/pos', $body);

        $this->assertContains($first->status(), [200, 201], $first->getContent());
        $this->assertSame($first->status(), $second->status());
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Order::withoutGlobalScopes()->count());
    }

    /**
     * @dataProvider pannesTechniques
     */
    public function test_une_panne_technique_du_devis_ne_montre_jamais_le_message_brut(\Closure $panne, string $brut): void
    {
        [$operator, $payload] = $this->posFixture();
        $this->mock(OrderQuoteService::class, function ($mock) use ($panne): void {
            $mock->shouldReceive('quote')->andThrow($panne());
        });

        $response = $this->actingAs($operator, 'sanctum')->postJson('/api/admin/pos/quote', $payload);

        $this->assertGreaterThanOrEqual(400, $response->status());
        $this->assertStringNotContainsString($brut, (string) $response->getContent());
        $this->assertStringContainsString('Relance', (string) $response->json('message'));
    }

    public static function pannesTechniques(): array
    {
        return [
            'modèle introuvable' => [
                fn () => (new ModelNotFoundException())->setModel(OrderQuote::class),
                'No query results',
            ],
            'interblocage SQL' => [
                fn () => new QueryException('select * from `order_quotes`', [], new \PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found')),
                'SQLSTATE',
            ],
        ];
    }

    /**
     * @return array{0: User, 1: array<string, mixed>}
     */
    private function posFixture(): array
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $branch = Branch::factory()->create();
        $operator = User::factory()->create(['branch_id' => $branch->id]);
        $operator->assignRole('POS Operator');
        $operator->givePermissionTo('pos');
        $this->seedOpenSessionFor($operator, $branch);

        $item = $this->item(7.00);

        return [$operator, [
            'token' => null,
            'branch_id' => $branch->id,
            'subtotal' => 0,
            'discount' => 0,
            'coupon_id' => 0,
            'total' => 0,
            'order_type' => OrderType::TAKEAWAY,
            'is_advance_order' => Ask::NO,
            'source' => Source::POS,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'pos_received_amount' => 0,
            'items' => json_encode([[
                'item_id' => $item->id,
                'quantity' => 1,
                'item_variations' => [],
                'item_extras' => [],
            ]]),
        ]];
    }

    /**
     * @return array{0: User, 1: array<string, mixed>}
     */
    private function kioskFixture(): array
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $branch = Branch::factory()->create();
        $kioskUser = User::factory()->create(['branch_id' => $branch->id]);
        KioskMachine::factory()->create(['branch_id' => $branch->id, 'user_id' => $kioskUser->id]);
        $item = $this->item(6.50);

        return [$kioskUser, [
            'branch_id' => $branch->id,
            'order_type' => OrderType::TAKEAWAY,
            'is_advance_order' => Ask::NO,
            'source' => Source::WEB,
            'payment_method' => PaymentGateway::CARD,
            'items' => json_encode([[
                'item_id' => $item->id,
                'quantity' => 1,
                'item_variations' => [],
                'item_extras' => [],
            ]]),
        ]];
    }

    private function item(float $price): Item
    {
        $tax = Tax::factory()->create(['tax_rate' => 0, 'type' => TaxType::PERCENTAGE, 'status' => Status::ACTIVE]);
        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);

        return Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
            'price' => $price,
            'status' => Status::ACTIVE,
        ]);
    }
}
