<?php

namespace Tests\Feature\Frontend;

use App\Enums\PosPaymentMethod;
use App\Models\Branch;
use App\Models\FrontendOrder;
use App\Models\Item;
use App\Models\KioskMachine;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [TICKET-BORNE-SERVEUR 2026-07-06] FIX B — le ticket borne du flux Plan B (paiement
 * à la caisse, pos_payment_method=COUNTER_DEFERRED) doit sortir du RENDERER SERVEUR
 * (design caisse) via GET /api/frontend/order/show/{id}/escpos :
 *   - les octets contiennent « ** A REGLER EN CAISSE ** » (rendu serveur, pas le
 *     libellé hardcodé du builder client legacy) ;
 *   - AUCUNE ligne de paiement (COUNTER_DEFERRED n'est PAS un règlement — l'ancien
 *     bug « PAIEMENT 6 : 0,00 € » ne doit jamais revenir) ;
 *   - garde de propriété : une AUTRE machine borne (autre user) reçoit 403.
 */
class KioskEscposCounterDeferredTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:FrontendOrder,1:string} order + token borne propriétaire */
    private function setupCounterDeferredOrder(): array
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['username' => 'kiosk_cd_' . uniqid(), 'branch_id' => $branch->id]);
        KioskMachine::create([
            'machine_id' => 'cd-test-' . uniqid(),
            'branch_id'  => $branch->id,
            'user_id'    => $user->id,
            'username'   => 'kiosk-cd',
            'password'   => bcrypt('123456'),
            'is_login'   => \App\Enums\Ask::NO,
            'status'     => \App\Enums\Status::ACTIVE,
        ]);
        $item = Item::factory()->create(['price' => 12.40]);
        // FrontendOrder partage la table `orders` — pas de factory dédiée → forceFill direct.
        $order = new FrontendOrder;
        $order->forceFill([
            'branch_id'          => $branch->id,
            'user_id'            => $user->id,
            'total'              => 12.40,
            'subtotal'           => 12.40,
            'discount'           => 0,
            'total_tax'          => 0,
            'order_type'         => \App\Enums\OrderType::TAKEAWAY,
            'source'             => \App\Enums\Source::WEB,
            'source_surface'     => 'kiosk',
            'payment_status'     => \App\Enums\PaymentStatus::PENDING_COUNTER,
            'pos_payment_method' => PosPaymentMethod::COUNTER_DEFERRED,
            'status'             => \App\Enums\OrderStatus::ACCEPT,
            'queue_number'       => 'A0007',
            'order_datetime'     => now(),
        ]);
        $order->save();
        (new OrderItem)->forceFill([
            'order_id' => $order->id,
            'branch_id' => $branch->id,
            'item_id' => $item->id,
            'quantity' => 1,
            'price' => 12.40,
            'total_price' => 12.40,
            'discount' => 0,
            'item_variation_total' => 0,
            'item_extra_total' => 0,
            'tax_amount' => 0,
        ])->save();

        $token = $user->createToken('kiosk-token', ['kiosk:order'])->plainTextToken;

        return [$order, $token];
    }

    /** @test */
    public function ticket_client_counter_deferred_contient_a_regler_en_caisse_et_zero_ligne_paiement(): void
    {
        [$order, $token] = $this->setupCounterDeferredOrder();

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'x-api-key'     => config('app.api_key'),
        ])->getJson("/api/frontend/order/show/{$order->id}/escpos?ticket=client");

        $res->assertOk();
        $b64 = $res->json('escpos_b64');
        $this->assertNotEmpty($b64, 'escpos_b64 vide');
        $bytes = base64_decode($b64);

        // Rendu serveur = design caisse : bandeau « A REGLER EN CAISSE » + total dû.
        $this->assertStringContainsString('** A REGLER EN CAISSE **', $bytes);
        $this->assertStringContainsString('A REGLER TTC :', $bytes);

        // COUNTER_DEFERRED n'est PAS un règlement → AUCUNE ligne de paiement/rendu.
        $this->assertStringNotContainsString('PAIEMENT', $bytes, 'régression « PAIEMENT 6 : 0,00 € »');
        $this->assertStringNotContainsString('RENDU :', $bytes);
        $this->assertStringNotContainsString('CARTE :', $bytes);
        $this->assertStringNotContainsString('*** PAY', $bytes, 'ne doit pas afficher PAYÉ avant encaissement');
    }

    /** @test */
    public function machine_borne_etrangere_meme_branche_recoit_403(): void
    {
        [$order] = $this->setupCounterDeferredOrder();

        // 2e borne (autre user, MÊME branche → passe le BranchScope, atteint la garde
        // user_id du contrôleur) → ne peut PAS imprimer la commande de la 1re machine.
        $otherUser = User::factory()->create([
            'username'  => 'kiosk_cd2_' . uniqid(),
            'branch_id' => $order->branch_id,
        ]);
        KioskMachine::create([
            'machine_id' => 'cd-test2-' . uniqid(),
            'branch_id'  => $order->branch_id,
            'user_id'    => $otherUser->id,
            'username'   => 'kiosk-cd2',
            'password'   => bcrypt('123456'),
            'is_login'   => \App\Enums\Ask::NO,
            'status'     => \App\Enums\Status::ACTIVE,
        ]);
        $foreignToken = $otherUser->createToken('kiosk-token', ['kiosk:order'])->plainTextToken;

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$foreignToken}",
            'x-api-key'     => config('app.api_key'),
        ])->getJson("/api/frontend/order/show/{$order->id}/escpos?ticket=client");

        $res->assertStatus(403);
        $this->assertNull($res->json('escpos_b64'));
    }

    /** @test */
    public function machine_borne_autre_branche_ne_voit_meme_pas_la_commande_404(): void
    {
        [$order] = $this->setupCounterDeferredOrder();

        // Cross-BRANCHE : le BranchScope masque la commande AVANT la garde contrôleur
        // (défense en profondeur) → 404, la commande n'existe pas pour cette borne.
        $otherBranch = Branch::factory()->create();
        $otherUser = User::factory()->create(['username' => 'kiosk_cd3_' . uniqid(), 'branch_id' => $otherBranch->id]);
        KioskMachine::create([
            'machine_id' => 'cd-test3-' . uniqid(),
            'branch_id'  => $otherBranch->id,
            'user_id'    => $otherUser->id,
            'username'   => 'kiosk-cd3',
            'password'   => bcrypt('123456'),
            'is_login'   => \App\Enums\Ask::NO,
            'status'     => \App\Enums\Status::ACTIVE,
        ]);
        $foreignToken = $otherUser->createToken('kiosk-token', ['kiosk:order'])->plainTextToken;

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$foreignToken}",
            'x-api-key'     => config('app.api_key'),
        ])->getJson("/api/frontend/order/show/{$order->id}/escpos?ticket=client");

        $res->assertStatus(404);
    }
}
