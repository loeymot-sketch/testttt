<?php

namespace Tests\Feature\Frontend;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\FrontendOrder;
use App\Models\User;
use App\Services\FrontendOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [CYCLE 9 · GOAL_WEB_ADVERSARIAL_UX_TOTAL 2026-08-05]
 *
 * LES CHEMINS D'ANNULATION DE PAIEMENT — la deuxième moitié de la plainte owner :
 * « j'annule le paiement et si le paiement est validé la commande est passée bizarrement ».
 *
 * Aucun test ne couvrait ce cycle de vie. On l'exerce ici de bout en bout, en APPELANT le
 * service (pas en répliquant ses conditions) :
 *
 *   création (carte, impayée)  → RETENUE : rien ne part en cuisine
 *   paiement échoué/annulé     → ANNULÉE : et l'annulation est idempotente (rejeu du webhook)
 *   paiement confirmé          → LIBÉRÉE : promue, scellée
 *
 * Les deux issues doivent être exclusives et terminales : une commande annulée ne doit jamais
 * pouvoir être « rattrapée » ensuite, sinon on retombe sur le ticket fantôme.
 */
class PaymentCancellationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function commandeCarteImpayee(): FrontendOrder
    {
        $branch = Branch::query()->first() ?? Branch::factory()->create();
        $user = User::factory()->create();

        return FrontendOrder::query()->create([
            'user_id'          => $user->id,
            'branch_id'        => $branch->id,
            'idempotency_key'  => 'CANCEL-' . uniqid(),
            'total'            => 10.80,
            'subtotal'         => 10.80,
            'discount'         => 0,
            'payment_status'   => PaymentStatus::UNPAID,
            'payment_method'   => PaymentGateway::CARD,
            'status'           => OrderStatus::PENDING,
            'order_type'       => OrderType::TAKEAWAY,
            'source_surface'   => 'web',
            'order_datetime'   => now(),
            'preparation_time' => 15,
            'business_date'    => now()->toDateString(),
        ]);
    }

    /** Paiement ANNULÉ ou ÉCHOUÉ → la commande doit être annulée, pas laissée en suspens. */
    public function test_a_failed_online_payment_cancels_the_order(): void
    {
        $order = $this->commandeCarteImpayee();

        $annulee = app(FrontendOrderService::class)->cancelForFailedOnlinePayment($order, 'failed');

        $this->assertTrue($annulee, "Un paiement carte échoué doit ANNULER la commande : sinon elle reste PENDING indéfiniment, invisible pour le client comme pour la cuisine.");
        $this->assertSame(
            (int) OrderStatus::CANCELED,
            (int) $order->fresh()->status,
            "Le statut doit être CANCELED après l'échec du paiement en ligne."
        );
    }

    /**
     * Le webhook peut être rejoué par le prestataire. L'annulation doit être IDEMPOTENTE :
     * un second passage ne doit rien changer ni relancer d'effet de bord.
     */
    public function test_cancelling_twice_is_idempotent(): void
    {
        $order = $this->commandeCarteImpayee();
        app(FrontendOrderService::class)->cancelForFailedOnlinePayment($order, 'failed');

        $secondPassage = app(FrontendOrderService::class)->cancelForFailedOnlinePayment($order->fresh(), 'failed');

        $this->assertFalse($secondPassage, "Le rejeu du webhook ne doit produire AUCUN second effet.");
        $this->assertSame((int) OrderStatus::CANCELED, (int) $order->fresh()->status);
    }

    /**
     * Une commande DÉJÀ PAYÉE ne doit jamais être annulée par un webhook d'échec arrivé en
     * retard — c'est l'inverse du défaut, et il coûterait une commande payée jamais préparée.
     */
    public function test_a_paid_order_is_never_cancelled_by_a_late_failure_webhook(): void
    {
        $order = $this->commandeCarteImpayee();
        $order->payment_status = PaymentStatus::PAID;
        $order->save();

        $annulee = app(FrontendOrderService::class)->cancelForFailedOnlinePayment($order->fresh(), 'failed');

        $this->assertFalse($annulee, "Une commande PAYÉE ne doit pas être annulée par un webhook d'échec tardif.");
        $this->assertNotSame(
            (int) OrderStatus::CANCELED,
            (int) $order->fresh()->status,
            "Le client a payé : sa commande doit vivre."
        );
    }

    /** Les deux issues sont EXCLUSIVES : une commande annulée ne peut plus être libérée. */
    public function test_a_cancelled_order_can_no_longer_be_released_by_the_paid_path(): void
    {
        $order = $this->commandeCarteImpayee();
        app(FrontendOrderService::class)->cancelForFailedOnlinePayment($order, 'failed');

        // Le webhook « payé » arrive APRÈS l'annulation (course réelle chez le prestataire).
        $order->refresh();
        $order->payment_status = PaymentStatus::PAID;
        $order->save();

        $promue = app(FrontendOrderService::class)->finalizePaidKioskOrder($order->fresh());

        $this->assertFalse(
            $promue,
            "Une commande ANNULÉE ne doit pas être ressuscitée par un webhook de paiement tardif : "
            . "c'est exactement le ticket fantôme que cette campagne a passé neuf cycles à fermer."
        );
    }
}
