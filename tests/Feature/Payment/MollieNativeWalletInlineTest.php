<?php

namespace Tests\Feature\Payment;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\Source;
use App\Http\PaymentGateways\Gateways\Mollie;
use App\Models\Branch;
use App\Models\FrontendOrder;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * [P0 ARGENT 2026-08-08 · JUMEAU OUBLIÉ] Les portefeuilles NATIFS encaissaient puis le site
 * annonçait « rien n'a été débité ».
 *
 * Les jetons Apple Pay / Google Pay natifs ont été ajoutés à `createPayment()` sans mettre à jour
 * ses DEUX gardes, toutes deux indexées sur le seul `$cardToken` :
 *   · `if ($paymentId === '' || ($cardToken === '' && $checkoutUrl === ''))` → un encaissement
 *     direct par portefeuille (donc SANS URL hébergée, cas nominal) levait une exception → le
 *     contrôleur répondait 502 → le site basculait sur son repli comptoir et affichait un écran
 *     de succès disant « rien n'a été débité », ALORS QUE Face ID venait d'autoriser le débit ;
 *   · `'inline' => $cardToken !== '' && …` → `inline` était structurellement INATTEIGNABLE pour
 *     un portefeuille, si bien que le site renvoyait le client vers une page de paiement pour un
 *     montant qu'il venait d'autoriser.
 *
 * Suite 100 % mockée (`Http::fake`) : l'API Mollie n'est jamais appelée.
 *
 * Ce qui est verrouillé, et dans cet ordre d'importance :
 *   1. un portefeuille natif encaissé SANS URL n'échoue PLUS et rend `inline = true` ;
 *   2. un REFUS synchrone ne passe JAMAIS `inline` (la garde `status === 'paid'` reste stricte) —
 *      c'est la propriété qu'il ne faut pas perdre en élargissant la condition ;
 *   3. le parcours carte est INCHANGÉ ;
 *   4. une réponse sans `id` ni URL et sans jeton reste une erreur.
 */
class MollieNativeWalletInlineTest extends TestCase
{
    use RefreshDatabase;

    private bool $flagCree = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        if (! file_exists(storage_path('installed'))) {
            touch(storage_path('installed'));
            $this->flagCree = true;
        }
        Config::set('payment.mollie.enabled', true);
        Config::set('payment.mollie.api_key', 'test_cleFactice1234567890');
    }

    protected function tearDown(): void
    {
        if ($this->flagCree && file_exists(storage_path('installed'))) {
            unlink(storage_path('installed'));
        }
        parent::tearDown();
    }

    private function commande(): FrontendOrder
    {
        $branch = Branch::factory()->create();
        $client = User::factory()->create(['branch_id' => 0]);

        $o = Order::factory()->create([
            'branch_id' => $branch->id, 'user_id' => $client->id,
            'order_type' => OrderType::TAKEAWAY, 'source' => Source::WEB, 'source_surface' => 'web',
            'payment_method' => PaymentGateway::CARD, 'payment_status' => PaymentStatus::UNPAID,
            'status' => OrderStatus::PENDING, 'subtotal' => 12.40, 'total' => 12.40,
        ]);

        // `FrontendOrder` et `Order` partagent la table `orders` : c'est le MÊME enregistrement,
        // vu par le modèle que `createPayment()` exige.
        return FrontendOrder::withoutGlobalScopes()->findOrFail($o->id);
    }

    /** Réponse Mollie d'un encaissement direct : payé, AUCUNE URL hébergée. */
    private function reponsePayeeSansUrl(string $id = 'tr_NATIF01'): void
    {
        Http::fake(['api.mollie.com/*' => Http::response([
            'resource' => 'payment', 'id' => $id, 'mode' => 'test', 'status' => 'paid',
            'amount' => ['value' => '12.40', 'currency' => 'EUR'],
            'metadata' => ['order_id' => '1'],
        ], 201)]);
    }

    public function test_apple_pay_natif_encaisse_sans_url_rend_inline_et_ne_leve_PLUS(): void
    {
        $this->reponsePayeeSansUrl();
        $r = (new Mollie())->createPayment($this->commande(), '', 'applepay', '{"paymentData":"jeton"}');

        $this->assertTrue($r['inline'], "un portefeuille natif encaissé DOIT être « payé dans la page »");
        $this->assertSame('', $r['checkout_url'], 'aucune page intermédiaire ne doit être proposée');
        $this->assertSame('paid', $r['status']);
    }

    public function test_google_pay_natif_encaisse_sans_url_rend_inline(): void
    {
        $this->reponsePayeeSansUrl('tr_NATIF02');
        $r = (new Mollie())->createPayment($this->commande(), '', 'googlepay', '', '{"signature":"jeton"}');

        $this->assertTrue($r['inline'], 'jumeau Google Pay : même exigence');
        $this->assertSame('paid', $r['status']);
    }

    /**
     * LA PROPRIÉTÉ À NE PAS PERDRE : élargir la condition ne doit pas laisser passer un REFUS.
     * Un « payé dans la page » sur une autorisation refusée serait pire que le défaut d'origine.
     */
    public function test_un_refus_synchrone_par_portefeuille_ne_passe_JAMAIS_inline(): void
    {
        Http::fake(['api.mollie.com/*' => Http::response([
            'resource' => 'payment', 'id' => 'tr_REFUS01', 'mode' => 'test', 'status' => 'failed',
            'amount' => ['value' => '12.40', 'currency' => 'EUR'],
        ], 201)]);

        $r = (new Mollie())->createPayment($this->commande(), '', 'applepay', '{"paymentData":"jeton"}');

        $this->assertFalse($r['inline'], 'un refus ne doit JAMAIS être annoncé comme payé');
        $this->assertSame('failed', $r['status']);
    }

    public function test_le_parcours_carte_reste_inchange(): void
    {
        $this->reponsePayeeSansUrl('tr_CARTE01');
        $r = (new Mollie())->createPayment($this->commande(), 'tkn_carte_valide_abc12345');

        $this->assertTrue($r['inline'], 'régression carte : le jeton carte doit rester inline');
    }

    /** Sans aucun jeton, l'URL hébergée est le seul moyen de payer : son absence reste une erreur. */
    public function test_sans_jeton_ni_url_la_reponse_reste_une_erreur(): void
    {
        Http::fake(['api.mollie.com/*' => Http::response([
            'resource' => 'payment', 'id' => 'tr_VIDE01', 'mode' => 'test', 'status' => 'open',
            'amount' => ['value' => '12.40', 'currency' => 'EUR'],
        ], 201)]);

        $this->expectException(\RuntimeException::class);
        (new Mollie())->createPayment($this->commande(), '', 'applepay');
    }
}
