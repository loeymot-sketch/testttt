<?php

namespace Tests\Feature\Frontend;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Http\Requests\OrderStatusRequest;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [PROCUREUR cycle 7/8 · GOAL_WEB_ADVERSARIAL_UX_TOTAL 2026-08-05 · P1 F-E]
 *
 * VÉRIFICATION COMPORTEMENTALE — on APPELLE `changeStatus`, on ne réplique pas sa condition.
 *
 * LA GARDE : une commande CARTE dont le paiement en ligne n'a pas abouti (UNPAID) ne doit
 * JAMAIS être acceptée en caisse — sinon une annulation 3DS laisse un zombie ACCEPT+UNPAID,
 * invisible de la cuisine.
 *
 * LE DÉFAUT : la garde ne connaissait que la surface `'web'`. Or `FrontendOrder::creating`
 * force `source_surface = 'delivery'` dès que `order_type === DELIVERY`, et le site n'envoie
 * pas la surface (il envoie `source: 5`). A/B exécuté par le procureur :
 *
 *   surface 'web'      → BLOQUÉE (422)   status=1
 *   surface 'delivery' → ACCEPTÉE        status=4  payment_status=10   ← zombie
 *
 * Les deux valeurs désignent la même chose : une commande passée depuis le site. C'est le motif
 * dominant de la campagne — un correctif appliqué à la surface regardée, pas à ses jumelles.
 */
class R1GuardCoversDeliverySurfaceTest extends TestCase
{
    use RefreshDatabase;

    private function makeUnpaidCardOrder(string $surface, int $orderType): Order
    {
        $branch = Branch::query()->first() ?? Branch::factory()->create();
        $user = User::factory()->create();

        return Order::query()->create([
            'user_id'          => $user->id,
            'branch_id'        => $branch->id,
            'idempotency_key'  => 'R1-' . $surface . '-' . uniqid(),
            'total'            => 12.50,
            'subtotal'         => 12.50,
            'discount'         => 0,
            'payment_status'   => PaymentStatus::UNPAID,
            'payment_method'   => PaymentGateway::CARD,
            'status'           => OrderStatus::PENDING,
            'order_type'       => $orderType,
            'source_surface'   => $surface,
            'order_datetime'   => now(),
            'preparation_time' => 15,
            'business_date'    => now()->toDateString(),
        ]);
    }

    private function tenterAcceptation(Order $order): ?string
    {
        $request = new OrderStatusRequest();
        $request->merge(['status' => OrderStatus::ACCEPT]);

        try {
            app(OrderService::class)->changeStatus($order, $request);

            return null; // aucune exception → l'acceptation est passée
        } catch (Exception $e) {
            return $e->getMessage();
        }
    }

    /** @dataProvider surfacesDuSite */
    public function test_an_unpaid_card_order_from_the_website_cannot_be_accepted(string $surface, int $orderType): void
    {
        $order = $this->makeUnpaidCardOrder($surface, $orderType);

        $message = $this->tenterAcceptation($order);

        $this->assertNotNull(
            $message,
            "Une commande CARTE IMPAYÉE de surface « {$surface} » a été ACCEPTÉE : c'est le zombie "
            . "ACCEPT+UNPAID que cette garde existe pour empêcher. Les surfaces 'web' et 'delivery' "
            . "désignent la même chose — une commande passée depuis le site."
        );
        $this->assertStringContainsString(
            'Paiement en ligne en cours',
            $message,
            "Le refus doit venir de la garde R1, pas d'une autre erreur."
        );
    }

    public static function surfacesDuSite(): array
    {
        return [
            "surface 'web' (le cas déjà couvert)"                 => ['web', OrderType::TAKEAWAY],
            "surface 'delivery' (celle qui échappait à la garde)" => ['delivery', OrderType::DELIVERY],
        ];
    }
}
