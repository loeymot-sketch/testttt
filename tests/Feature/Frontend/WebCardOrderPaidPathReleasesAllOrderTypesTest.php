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
 * [PROCUREUR cycle 7/8 · GOAL_WEB_ADVERSARIAL_UX_TOTAL 2026-08-05 · P1 F-D]
 *
 * VÉRIFICATION COMPORTEMENTALE — on APPELLE le service, on ne réplique pas sa règle.
 *
 * C'est la demande explicite du procureur de convergence, et la leçon centrale de cette
 * campagne : trois correctifs ont été VERTS tout en étant MORTS, et la sentinelle du dépôt est
 * restée verte avec six P1 ouverts, parce que ses assertions lisaient du texte source au lieu
 * d'exercer un comportement. Un test qui recopie la condition qu'il prétend vérifier ne prouve
 * rien : il reste vert quand l'implémentation change.
 *
 * LE DÉFAUT : au cycle 6 les deux gardes du chemin carte-web ont été « alignées » en EXCLUANT
 * les `order_type` hors {TAKEAWAY, DELIVERY} des DEUX côtés. Une commande carte web d'un autre
 * type était donc retenue à la création ET jamais libérée au paiement. Reproduit en base par le
 * procureur :
 *
 *   order_type=20  payment_status=PAID  status=PENDING  fiscal_sequence_no=NULL   ← hors NF525
 *   order_type=10  payment_status=PAID  status=PREPARING fiscal_sequence_no=2705  ← jumelle saine
 *
 * Vente PAYÉE hors chaîne fiscale, non rattrapable (le cron de reprise exige
 * `fiscal_alloc_error_at`, resté NULL), avec en prime le ticket cuisine sorti avant paiement.
 */
class WebCardOrderPaidPathReleasesAllOrderTypesTest extends TestCase
{
    use RefreshDatabase;

    private function makeWebCardOrder(int $orderType): FrontendOrder
    {
        $branch = Branch::query()->first() ?? Branch::factory()->create();
        $user = User::factory()->create();

        return FrontendOrder::query()->create([
            'user_id'          => $user->id,
            'branch_id'        => $branch->id,
            'idempotency_key'  => 'CONV8-' . $orderType . '-' . uniqid(),
            'total'            => 10.80,
            'subtotal'         => 10.80,
            'discount'         => 0,
            'payment_status'   => PaymentStatus::PAID,
            'payment_method'   => PaymentGateway::CARD,
            'status'           => OrderStatus::PENDING,
            'order_type'       => $orderType,
            'source_surface'   => 'web',
            'order_datetime'   => now(),
            'preparation_time' => 15,
            'business_date'    => now()->toDateString(),
        ]);
    }

    /**
     * @dataProvider orderTypes
     *
     * Le chemin « payé » doit libérer la commande QUEL QUE SOIT le type : c'est la seule façon
     * d'éviter qu'une moitié du mécanisme retienne ce que l'autre ne libère jamais.
     */
    public function test_paid_web_card_order_is_released_whatever_the_order_type(int $orderType, string $libelle): void
    {
        $order = $this->makeWebCardOrder($orderType);

        $promoted = app(FrontendOrderService::class)->finalizePaidKioskOrder($order);

        $this->assertTrue(
            $promoted,
            "Une commande carte web PAYÉE de type {$libelle} ({$orderType}) doit être libérée par le "
            . "chemin payé. Sinon elle reste PENDING avec fiscal_sequence_no NULL — payée, jamais en "
            . "cuisine, et HORS de la chaîne fiscale NF525."
        );

        $frais = $order->fresh();
        $this->assertNotSame(
            OrderStatus::PENDING,
            (int) $frais->status,
            "Le statut doit avoir quitté PENDING après libération (type {$libelle})."
        );
    }

    public static function orderTypes(): array
    {
        return [
            'à emporter (le cas nominal du web)' => [OrderType::TAKEAWAY, 'TAKEAWAY'],
            'livraison'                          => [OrderType::DELIVERY, 'DELIVERY'],
            // Le type qui a produit le défaut : `OrderRequest` valide `order_type` en
            // ['required','numeric'] SANS liste blanche, donc n'importe quel jeton client web
            // peut l'atteindre. Il doit être traité comme les autres, pas laissé en suspens.
            'type arbitraire atteignable (20)'   => [20, '20'],
        ];
    }
}
