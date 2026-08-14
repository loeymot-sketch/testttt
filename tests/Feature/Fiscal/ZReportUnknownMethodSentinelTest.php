<?php

namespace Tests\Feature\Fiscal;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * UNE VENTE PAYÉE NE DOIT JAMAIS DISPARAÎTRE DE LA VENTILATION ESPÈCES/CARTE DU Z SIGNÉ.
 *
 * ── LE DÉFAUT MESURÉ EN PRODUCTION (GOAL_CAYENNE_FINITION §1.2) ──────────────────────────────
 * 19 lignes `orders` avaient `pos_payment_method IS NULL` alors que `payment_status = PAID`.
 * `ZReportService::applyOrderToTotals` (ZONE GELÉE §7) se rabat alors sur `payment_method` — une
 * énumération DIFFÉRENTE qui collisionne avec `PosPaymentMethod` sur les mêmes nombres (voir
 * `PosMethodFromGateway`). La vente n'apparaît dans AUCUNE colonne reconnue de la ventilation du
 * document fiscal signé et archivé 6 ans.
 *
 * ── LA CAUSE TROUVÉE EN AUDITANT (pas supposée) ──────────────────────────────────────────────
 * `PaymentService::payment()` (callback passerelle en ligne) appelait déjà
 * `PosMethodFromGateway::appliquer()` depuis le 2026-08-13 (commit 63d9c6fe5) — mais c'est le
 * SEUL point de scellage à le faire. `OrderService::changePaymentStatus()` — le chemin "marquer
 * payé" depuis le dropdown admin, partagé par PosOrderController / TableOrderController /
 * OnlineOrderController — scellait aussi des ventes PAID sans jamais poser `pos_payment_method`.
 * C'est ce second chemin que ce test verrouille.
 *
 * ── LE CORRECTIF, ET POURQUOI PAS DANS LE Z ──────────────────────────────────────────────────
 * `ZReportService` reste intouché (ZONE GELÉE §7). Le correctif est EN AMONT, dans
 * `OrderService::changePaymentStatus()`, qui appelle désormais `PosMethodFromGateway::appliquer()`
 * juste après avoir scellé PAID — même garde-fou que le chemin passerelle : jamais d'écrasement
 * d'un mode déjà posé au comptoir, jamais de correspondance inventée pour une passerelle ambiguë.
 *
 * ── CE QUI N'EST PAS CORRIGÉ, ET POURQUOI (documenté, pas deviné) ────────────────────────────
 * Les passerelles E_WALLET et PAYPAL n'ont PAS d'équivalent caisse certain — `PosMethodFromGateway`
 * les laisse volontairement NULL. Une vente marquée payée via ce chemin pour l'une de ces
 * passerelles reste donc hors ventilation nommée. C'est une DÉCISION MÉTIER (owner), pas un bug :
 * inventer une correspondance écrirait un chiffre faux dans un document fiscal.
 */
class ZReportUnknownMethodSentinelTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Config::set('fiscal.audit_secret', 'test-fiscal-secret-'.str_repeat('z', 40));

        $this->branch = Branch::factory()->create();
        $this->cashier = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->cashier->assignRole('POS Operator');
    }

    private function makeOrder(array $attrs = []): Order
    {
        return Order::factory()->create(array_merge([
            'user_id'            => $this->cashier->id,
            'branch_id'          => $this->branch->id,
            'order_type'         => OrderType::DELIVERY,
            'payment_status'     => PaymentStatus::UNPAID,
            'status'             => OrderStatus::ACCEPT,
            'fiscal_sequence_no' => null,
            'pos_payment_method' => null,
            'source_surface'     => 'web',
        ], $attrs));
    }

    private function markPaid(Order $order)
    {
        return $this->actingAs($this->cashier, 'sanctum')
            ->postJson('/api/admin/pos-order/change-payment-status/'.$order->id, [
                'payment_status' => PaymentStatus::PAID,
            ]);
    }

    /**
     * LE CŒUR DU SENTINEL : une vente carte marquée payée via le dropdown admin ne peut PLUS
     * rester avec `pos_payment_method` NULL — elle doit porter CARD, la seule lecture certaine.
     */
    public function test_une_vente_carte_marquee_payee_via_le_dropdown_admin_recoit_un_mode(): void
    {
        $order = $this->makeOrder(['payment_method' => PaymentGateway::CARD]);
        $this->assertNull($order->pos_payment_method, 'précondition : pas encore de mode posé');

        $response = $this->markPaid($order);
        $response->assertStatus(200);

        $fresh = Order::withoutGlobalScopes()->findOrFail($order->id);
        $this->assertSame(PaymentStatus::PAID, (int) $fresh->payment_status);
        $this->assertSame(
            PosPaymentMethod::CARD,
            (int) $fresh->pos_payment_method,
            "une vente carte reste sans pos_payment_method après changePaymentStatus — elle échappe à la ventilation du Z signé"
        );
    }

    /** Même garde pour le titre-restaurant — équivalence certaine des deux côtés. */
    public function test_une_vente_titre_restaurant_marquee_payee_recoit_un_mode(): void
    {
        $order = $this->makeOrder(['payment_method' => PaymentGateway::TICKET_RESTAURANT]);

        $response = $this->markPaid($order);
        $response->assertStatus(200);

        $fresh = Order::withoutGlobalScopes()->findOrFail($order->id);
        $this->assertSame(PosPaymentMethod::TICKET_RESTAURANT, (int) $fresh->pos_payment_method);
    }

    /**
     * NON-RÉGRESSION : un mode déjà posé au comptoir (avant ce dropdown, ou par un autre chemin)
     * n'est jamais écrasé — la personne qui a encaissé fait foi.
     */
    public function test_un_mode_deja_pose_n_est_pas_ecrase_par_changePaymentStatus(): void
    {
        $order = $this->makeOrder([
            'payment_method'     => PaymentGateway::CARD,
            'pos_payment_method' => PosPaymentMethod::CASH,
        ]);

        $response = $this->markPaid($order);
        $response->assertStatus(200);

        $fresh = Order::withoutGlobalScopes()->findOrFail($order->id);
        $this->assertSame(
            PosPaymentMethod::CASH,
            (int) $fresh->pos_payment_method,
            'le mode posé au comptoir a été écrasé par la traduction passerelle'
        );
    }

    /**
     * ⛔ DOCUMENTÉ, PAS CORRIGÉ : une passerelle ambiguë (PayPal / e-wallet) reste NULL après ce
     * chemin — décision métier owner en attente (aucune équivalence caisse certaine). Ce test
     * verrouille le comportement ACTUEL (ne pas inventer une correspondance) plutôt que de faire
     * disparaître silencieusement le gap.
     */
    public function test_une_passerelle_ambigue_reste_documentee_sans_mode_invente(): void
    {
        $order = $this->makeOrder(['payment_method' => PaymentGateway::PAYPAL]);

        $response = $this->markPaid($order);
        $response->assertStatus(200);

        $fresh = Order::withoutGlobalScopes()->findOrFail($order->id);
        $this->assertSame(PaymentStatus::PAID, (int) $fresh->payment_status);
        $this->assertNull(
            $fresh->pos_payment_method,
            'une correspondance a été inventée pour une passerelle dont le sens caisse est incertain — gate owner requis avant de changer ce comportement'
        );
    }
}
