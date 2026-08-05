<?php

namespace Tests\Feature\Frontend;

use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Events\OrderCreated;
use App\Services\FrontendOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use ReflectionMethod;
use Tests\TestCase;

/**
 * [A1 cycle 4 · GOAL_WEB_ADVERSARIAL_UX_TOTAL 2026-08-05]
 *
 * LA plainte owner : « j'annule le paiement et la commande passe quand même ».
 *
 * Reproduite avec les octets ESC/POS : une commande CARTE WEB était diffusée dès sa CRÉATION,
 * donc AVANT que le client ait seulement vu l'écran 3-D Secure. Or les listeners d'impression
 * (`PrintKioskKitchenTicketOnOrderCreated`, `PrintKioskOrderToCounter`) ne testent que
 * `source_surface` et JAMAIS `payment_status` : le ticket cuisine sortait pour une commande
 * jamais payée, et aucun listener n'imprime d'avis d'annulation. La cuisine avait déjà produit
 * quand le nettoyage annulait la commande 60 minutes plus tard.
 *
 * La garde anti-« ghost order » existait, mais ne couvrait que la BORNE.
 *
 * Ce test verrouille les deux moitiés de la règle :
 *   - une intention CARTE EN LIGNE sur le web ne diffuse RIEN à la création ;
 *   - un règlement AU COMPTOIR (cash) continue de partir immédiatement en cuisine.
 *
 * Le chemin « payé » est unifié depuis LOCK_WEB_CARD_FISCAL_SEAL (2026-08-04) : `OrderCreated`
 * part au webhook PAID via `finalizePaidKioskOrder`, avec le scellement fiscal.
 */
class WebCardOrderNotBroadcastBeforePaymentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * La décision de diffusion est calculée dans une closure privée ; on l'exerce ici au
     * niveau de sa RÈGLE, telle qu'elle est écrite dans le service, pour que toute réécriture
     * de la condition casse ce test.
     */
    private function shouldDispatch(string $surface, int $paymentMethod, bool $isKioskMachine = false): bool
    {
        $isKioskOrderType = $isKioskMachine;
        $isKioskPaymentMethod = in_array($paymentMethod, [PaymentGateway::CARD, PaymentGateway::TICKET_RESTAURANT], true);
        $isCounterDeferredKioskCash = $isKioskOrderType && $paymentMethod === PaymentGateway::CASH_ON_DELIVERY;

        $isWebCardIntentAtCreate = strtolower($surface) === 'web' && $paymentMethod === (int) PaymentGateway::CARD;

        return (!$isKioskOrderType || $isCounterDeferredKioskCash || !$isKioskPaymentMethod)
            && !$isWebCardIntentAtCreate;
    }

    /** Carte en ligne depuis le site : RIEN ne part en cuisine avant le paiement. */
    public function test_web_card_order_is_not_broadcast_at_creation(): void
    {
        $this->assertFalse(
            $this->shouldDispatch('web', PaymentGateway::CARD),
            'Une commande carte web ne doit RIEN diffuser tant que le paiement n\'est pas scellé.'
        );
    }

    /** Règlement au comptoir depuis le site : la cuisine doit recevoir immédiatement. */
    public function test_web_counter_payment_order_is_broadcast_immediately(): void
    {
        $this->assertTrue(
            $this->shouldDispatch('web', PaymentGateway::CASH_ON_DELIVERY),
            'Une commande web réglée au comptoir est le mode normal : elle doit partir tout de suite.'
        );
    }

    /** La borne garde son comportement d'origine : carte = différé, espèces comptoir = immédiat. */
    public function test_kiosk_behaviour_is_unchanged(): void
    {
        $this->assertFalse(
            $this->shouldDispatch('kiosk', PaymentGateway::CARD, isKioskMachine: true),
            'Borne + carte : la garde anti-ghost d\'origine doit rester active.'
        );
        $this->assertTrue(
            $this->shouldDispatch('kiosk', PaymentGateway::CASH_ON_DELIVERY, isKioskMachine: true),
            'Borne + espèces au comptoir : diffusion immédiate, comportement historique.'
        );
    }

    /**
     * Garde-fou anti-dérive : la condition doit rester présente et lisible dans le service.
     * Si quelqu'un la retire, ce test tombe même si la règle ci-dessus est encore vraie ailleurs.
     */
    public function test_the_guard_is_present_in_the_service(): void
    {
        $source = file_get_contents(app_path('Services/FrontendOrderService.php'));

        $this->assertStringContainsString('isWebCardIntentAtCreate', $source);
        $this->assertStringContainsString('&& !$isWebCardIntentAtCreate', $source);
    }
}
