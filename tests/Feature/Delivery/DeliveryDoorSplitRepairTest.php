<?php

namespace Tests\Feature\Delivery;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\DeliveryStop;
use App\Models\Order;
use App\Models\OrderPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Feature\Delivery\Concerns\HandoverFixtures;
use Tests\TestCase;

/**
 * [RED-TEAM 2026-11-02 · P2-1 / P2-2] Deux états d'arrêt faux sur le chemin « règlement en plusieurs moyens ».
 *  P2-1 : un jumeau (QR / ancien écran) livre la commande entre le contrôle et le verrou → l'arrêt était marqué
 *         « split » avec la somme des tranches alors qu'AUCUNE ligne de paiement n'existait.
 *  P2-2 : commande livrée et payée mais arrêt non fermé (échec après le COMMIT) → le rejeu avec `payments` restait
 *         coincé en 422 pour toujours (seul le bouton historique réparait).
 */
class DeliveryDoorSplitRepairTest extends TestCase
{
    use RefreshDatabase;
    use HandoverFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpHandover();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        DeliveryStop::flushEventListeners();
        AuditLog::flushEventListeners();
        $this->tearDownHandover();
        parent::tearDown();
    }

    private function envoyer(string $url, array $body)
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($this->courier, 'sanctum')
            ->withHeaders(['x-api-key' => 'test-api-key', 'X-Idempotency-Key' => (string) Str::uuid(), 'Accept' => 'application/json'])
            ->postJson($url, $body);
    }

    public function test_replay_with_payments_finishes_a_stop_left_open_after_commit(): void
    {
        $stop = $this->departed($this->delivery());
        $url = "/api/frontend/delivery-run/stops/{$stop->id}/deliver";
        $this->envoyer($url, ['payments' => [['mode' => 'cash', 'amount' => 17.5], ['mode' => 'card', 'amount' => 10]]])->assertOk();
        $lignes = OrderPayment::query()->withoutGlobalScopes()->where('order_id', $stop->order_id)->count();
        // l'état laissé par un échec APRÈS le COMMIT : commande réglée et livrée, arrêt non fermé
        DeliveryStop::query()->withoutGlobalScopes()->whereKey($stop->id)->update(['delivered_at' => null, 'amount_collected_cents' => 0, 'collect_method' => 'cash']);

        $this->envoyer($url, ['payments' => [['mode' => 'cash', 'amount' => 17.5], ['mode' => 'card', 'amount' => 10]]])->assertOk();

        $s = DeliveryStop::query()->withoutGlobalScopes()->find($stop->id);
        $this->assertNotNull($s->delivered_at, 'le rejeu doit terminer l\'arrêt');
        $this->assertSame('split', $s->collect_method);
        $this->assertSame(2750, (int) $s->amount_collected_cents);
        $this->assertSame($lignes, OrderPayment::query()->withoutGlobalScopes()->where('order_id', $stop->order_id)->count(), 'aucun paiement en double');
    }

    public function test_replay_on_a_never_paid_delivered_order_is_still_refused(): void
    {
        $stop = $this->departed($this->delivery());
        // livrée + payée SANS aucune ligne de porte (ex. encaissée au comptoir) : rien à terminer, refus conservé
        Order::query()->withoutGlobalScopes()->whereKey($stop->order_id)->update(['status' => OrderStatus::DELIVERED, 'payment_status' => PaymentStatus::PAID]);

        $this->envoyer("/api/frontend/delivery-run/stops/{$stop->id}/deliver", ['payments' => [['mode' => 'cash', 'amount' => 27.5]]])->assertStatus(422);
    }

    public function test_stop_is_not_marked_split_when_a_twin_delivered_without_writing_payments(): void
    {
        $stop = $this->departed($this->delivery());
        $real = app(\App\Services\Delivery\DeliveryWaveService::class);
        $orderId = $stop->order_id;
        $this->partialMock(\App\Services\Delivery\DeliveryWaveService::class, function ($m) use ($real, $orderId) {
            $m->shouldReceive('collectionFor')->andReturnUsing(function ($o) use ($real, $orderId) {
                $r = $real->collectionFor($o);
                Order::query()->withoutGlobalScopes()->whereKey($orderId)->update(['status' => OrderStatus::DELIVERED, 'payment_status' => PaymentStatus::PAID, 'pos_payment_method' => 1]);

                return $r;
            });
        });

        $this->envoyer("/api/frontend/delivery-run/stops/{$stop->id}/deliver", ['payments' => [['mode' => 'card', 'amount' => 17.5], ['mode' => 'meal_voucher', 'amount' => 10]]]);

        $s = DeliveryStop::query()->withoutGlobalScopes()->find($stop->id);
        $this->assertSame(0, OrderPayment::query()->withoutGlobalScopes()->where('order_id', $stop->order_id)->count());
        $this->assertNotSame('split', $s->collect_method, 'pas de « plusieurs moyens » sans aucune ligne de paiement');
    }
}
