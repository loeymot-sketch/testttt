<?php

namespace Tests\Feature\Payment;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\Source;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * [OWNER 2026-08-06 · PORTEFEUILLES] Apple Pay / Google Pay sur le checkout web.
 *
 * Suite 100 % MOCKÉE (Http::fake) — l'API Mollie réelle n'est JAMAIS appelée depuis un test.
 *
 * Constat mesuré le 2026-08-06 sur le profil pfl_Ymr3Tb6vvp (E.DELICE / www.lecayenne.fr, live) :
 * `applepay` et `googlepay` sont déjà `activated` côté Mollie. Le site ne les proposait pas parce
 * que le backend n'envoyait AUCUN `method` — Mollie servait alors la page générique « choisissez
 * un moyen » et la feuille du portefeuille ne s'ouvrait jamais.
 *
 * Ce que la suite verrouille :
 *  - whitelist stricte (`applepay` | `googlepay`) — toute autre valeur = 422 en français ;
 *  - `method` + `card_token` = incohérence explicite (422), jamais une précédence devinée ;
 *  - le bon `method` est réellement transmis à Mollie, et la raison rendue au site vaut `wallet` ;
 *  - le parcours carte classique (card_token seul) est INCHANGÉ ;
 *  - le montant reste le total SCELLÉ BACKEND, et le corps envoyé à Mollie est identique à celui
 *    du parcours hébergé à la seule clé `method` près → aucun nouveau chemin fiscal.
 */
class MollieWalletMethodTest extends TestCase
{
    use RefreshDatabase;

    private bool $createdInstalledFlag = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        $this->ensureInstalledFlag();
        // Posture par défaut = celle du produit : FAIL-CLOSED (flag OFF, clé '').
        Config::set('payment.mollie.enabled', false);
        Config::set('payment.mollie.api_key', '');
    }

    protected function tearDown(): void
    {
        if ($this->createdInstalledFlag && file_exists(storage_path('installed'))) {
            unlink(storage_path('installed'));
        }
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // (a) Whitelist stricte
    // ------------------------------------------------------------------

    /**
     * La valeur part telle quelle chez Mollie comme `method` : tout ce qui n'est pas un
     * portefeuille connu doit être arrêté CHEZ NOUS, jamais relayé. Y compris les tentatives
     * plausibles (`creditcard`, `paypal`, `bancontact`) : elles contourneraient la logique carte.
     */
    public function test_unknown_method_is_rejected_422_in_french_without_calling_mollie(): void
    {
        $this->configureMollie();
        Http::fake();
        [$customer, $order] = $this->webCardOrder();

        // NB : pas de variante à espaces ici — le middleware GLOBAL TrimStrings les retire avant
        // le contrôleur (cas couvert par test_whitespace_and_empty_method_are_normalised_by_the_framework).
        foreach (['paypal', 'creditcard', 'bancontact', 'APPLEPAY', 'ideal', 'x'] as $bogus) {
            $this->actingAs($customer, 'sanctum')
                ->postJson("/api/frontend/order/{$order->id}/mollie-checkout", ['method' => $bogus])
                ->assertStatus(422)
                ->assertJsonPath('status', false)
                ->assertJsonPath('message', 'Moyen de paiement en ligne non pris en charge.');
        }

        // Types non-scalaires (front cassé / sonde) : refusés proprement, pas de 500.
        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/frontend/order/{$order->id}/mollie-checkout", ['method' => ['applepay']])
            ->assertStatus(422);
        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/frontend/order/{$order->id}/mollie-checkout", ['method' => 42])
            ->assertStatus(422);

        // Un refus ne doit RIEN créer chez Mollie ni toucher la commande.
        Http::assertNothingSent();
        $fresh = $order->fresh();
        $this->assertSame(PaymentStatus::UNPAID, (int) $fresh->payment_status);
        $this->assertNull($fresh->fiscal_sequence_no);
    }

    // ------------------------------------------------------------------
    // (b) Incohérence portefeuille + jeton carte
    // ------------------------------------------------------------------

    /**
     * Un portefeuille n'a PAS de jeton carte (la carte reste dans le téléphone). Recevoir les deux
     * signale un front incohérent : on refuse au lieu de faire primer l'un en silence — deviner
     * ferait débiter le client par un rail qu'il n'a pas choisi.
     */
    public function test_wallet_method_together_with_card_token_is_refused(): void
    {
        $this->configureMollie();
        Http::fake();
        [$customer, $order] = $this->webCardOrder();

        foreach (['applepay', 'googlepay'] as $wallet) {
            $this->actingAs($customer, 'sanctum')
                ->postJson("/api/frontend/order/{$order->id}/mollie-checkout", [
                    'method'     => $wallet,
                    'card_token' => 'tkn_valid_abc12345',
                ])
                ->assertStatus(422)
                ->assertJsonPath('status', false)
                ->assertJsonPath(
                    'message',
                    'Choisissez soit le paiement par carte, soit un portefeuille — pas les deux.'
                );
        }

        Http::assertNothingSent();
        $this->assertSame(PaymentStatus::UNPAID, (int) $order->fresh()->payment_status);
    }

    // ------------------------------------------------------------------
    // (c) applepay / googlepay acceptés + (e) montant scellé
    // ------------------------------------------------------------------

    /**
     * Cœur du lot, cas Apple Pay : le `method` choisi est réellement transmis à Mollie (sans lui,
     * la feuille du portefeuille ne s'ouvre jamais), la checkout URL est rendue au site avec
     * `reason=wallet` pour qu'il affiche un écran calme, et le montant est le total SCELLÉ
     * BACKEND — jamais une valeur venue du client.
     */
    public function test_applepay_is_accepted_and_forwarded_with_sealed_amount(): void
    {
        $this->assertWalletIsForwarded('applepay', 'tr_APPLE01', 12.40);
    }

    /** Même preuve pour Google Pay — un test SÉPARÉ : deux Http::fake() successifs dans une même
     *  méthode n'écrasent pas le stub déjà posé pour la même URL (le 1er gagnerait en silence). */
    public function test_googlepay_is_accepted_and_forwarded_with_sealed_amount(): void
    {
        $this->assertWalletIsForwarded('googlepay', 'tr_GOOGL01', 9.30);
    }

    private function assertWalletIsForwarded(string $wallet, string $paymentId, float $total): void
    {
        $this->configureMollie();
        [$customer, $order] = $this->webCardOrder(['total' => $total, 'subtotal' => $total]);
        $sealed = number_format($total, 2, '.', '');

        Http::fake([
            'https://api.mollie.com/v2/payments' => Http::response(
                $this->molliePaymentPayload($paymentId, $order->id, 'open', $sealed),
                201
            ),
        ]);

        // Le client tente aussi de forcer un montant : il doit être IGNORÉ (total = SSOT DB).
        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/frontend/order/{$order->id}/mollie-checkout", [
                'method' => $wallet,
                'amount' => '0.01',
            ])
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('payment_id', $paymentId)
            ->assertJsonPath('inline', false)
            // Raison DISTINCTE : le site sait qu'il ouvre une feuille de portefeuille et non
            // une page « choisissez un moyen de paiement ».
            ->assertJsonPath('reason', 'wallet')
            ->assertJsonPath('checkout_url', 'https://www.mollie.com/checkout/select-method/' . $paymentId);

        Http::assertSent(function (ClientRequest $request) use ($order, $wallet, $sealed): bool {
            $body = $request->data();

            return str_ends_with($request->url(), '/v2/payments')
                // (c) le bon portefeuille est transmis…
                && ($body['method'] ?? null) === $wallet
                // …et SANS cardToken (le portefeuille n'en produit pas).
                && !array_key_exists('cardToken', $body)
                // (e) montant = total scellé backend, au centime, jamais le '0.01' du client.
                && ($body['amount']['value'] ?? null) === $sealed
                && ($body['amount']['currency'] ?? null) === 'EUR'
                && ($body['metadata']['order_id'] ?? null) === (string) $order->id;
        });
        Http::assertSentCount(1);

        // Le checkout ne paie RIEN : seul le webhook (vérité re-fetchée) scelle la commande.
        $fresh = $order->fresh();
        $this->assertSame(PaymentStatus::UNPAID, (int) $fresh->payment_status, $wallet);
        $this->assertNull($fresh->transaction_id, $wallet);
        $this->assertNull($fresh->fiscal_sequence_no, $wallet);
    }

    /**
     * Comportement RÉEL mesuré, pas supposé : deux middlewares GLOBAUX passent avant le
     * contrôleur — TrimStrings (« applepay » entouré d'espaces arrive déjà taillé) et
     * ConvertEmptyStringsToNull (`method: ""` arrive en `null`). Conséquences vérifiées ici :
     * un espace parasite ne fait pas échouer un vrai portefeuille, une chaîne vide retombe sur
     * le parcours hébergé, et ce qui part chez Mollie est TOUJOURS la valeur canonique exacte.
     */
    public function test_whitespace_and_empty_method_are_normalised_by_the_framework(): void
    {
        $this->configureMollie();
        [$customer, $order] = $this->webCardOrder(['total' => 12.40, 'subtotal' => 12.40]);

        Http::fake([
            'https://api.mollie.com/v2/payments' => Http::response(
                $this->molliePaymentPayload('tr_TRIM01', $order->id, 'open', '12.40'),
                201
            ),
        ]);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/frontend/order/{$order->id}/mollie-checkout", ['method' => '  applepay  '])
            ->assertOk()
            ->assertJsonPath('reason', 'wallet');

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/frontend/order/{$order->id}/mollie-checkout", ['method' => ''])
            ->assertOk()
            ->assertJsonPath('reason', 'hosted');

        $sent = [];
        Http::assertSent(function (ClientRequest $request) use (&$sent): bool {
            $sent[] = $request->data()['method'] ?? null;

            return true;
        });
        // Jamais de valeur rembourrée relayée : Mollie ne reçoit que « applepay », puis rien.
        $this->assertSame(['applepay', null], $sent);
    }

    /**
     * Preuve structurelle qu'AUCUN nouveau chemin n'apparaît : à commande égale, le corps envoyé à
     * Mollie pour un portefeuille est identique à celui du parcours hébergé existant, à la SEULE
     * clé `method` près. Un futur ajout (montant recalculé, autre webhook, autre redirection) sur
     * la branche portefeuille casserait ce test.
     */
    public function test_wallet_request_body_differs_from_hosted_only_by_the_method_key(): void
    {
        $this->configureMollie();
        [$customer, $order] = $this->webCardOrder(['total' => 12.40, 'subtotal' => 12.40]);

        Http::fake([
            'https://api.mollie.com/v2/payments' => Http::response(
                $this->molliePaymentPayload('tr_CMP001', $order->id, 'open', '12.40'),
                201
            ),
        ]);

        // 1) Parcours hébergé historique (aucun method, aucun jeton).
        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/frontend/order/{$order->id}/mollie-checkout")
            ->assertOk()
            ->assertJsonPath('reason', 'hosted');

        // 2) Même commande, en Apple Pay.
        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/frontend/order/{$order->id}/mollie-checkout", ['method' => 'applepay'])
            ->assertOk()
            ->assertJsonPath('reason', 'wallet');

        $bodies = [];
        Http::assertSent(function (ClientRequest $request) use (&$bodies): bool {
            if (str_ends_with($request->url(), '/v2/payments')) {
                $bodies[] = $request->data();
            }

            return true;
        });
        $this->assertCount(2, $bodies, 'deux créations de paiement observées');

        [$hosted, $wallet] = $bodies;
        $this->assertSame('applepay', $wallet['method'] ?? null);
        $this->assertArrayNotHasKey('method', $hosted);

        unset($wallet['method']);
        // Montant, description, redirectUrl, webhookUrl, locale, metadata : strictement identiques.
        $this->assertSame($hosted, $wallet, 'le portefeuille ne change QUE la clé method');
        $this->assertSame('12.40', $hosted['amount']['value'] ?? null);
        $this->assertSame('fr_FR', $hosted['locale'] ?? null);
        $this->assertStringContainsString('/api/webhook/mollie', (string) ($hosted['webhookUrl'] ?? ''));
    }

    // ------------------------------------------------------------------
    // (d) Non-régression du parcours carte
    // ------------------------------------------------------------------

    /**
     * Le parcours carte dans la page est INCHANGÉ par l'ajout du portefeuille : method=creditcard
     * + cardToken, et les raisons existantes (inline / 3ds / hosted) gardent leur sens.
     */
    public function test_classic_card_token_flow_is_unchanged(): void
    {
        $this->configureMollie();
        [$customer, $order] = $this->webCardOrder(['total' => 11.80, 'subtotal' => 11.80]);

        $payload = $this->molliePaymentPayload('tr_CARD001', $order->id, 'paid', '11.80');
        unset($payload['_links']['checkout']); // paiement direct : pas de page hébergée
        Http::fake(['https://api.mollie.com/v2/payments' => Http::response($payload, 201)]);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/frontend/order/{$order->id}/mollie-checkout", ['card_token' => 'tkn_inline_abc123'])
            ->assertOk()
            ->assertJsonPath('inline', true)
            ->assertJsonPath('checkout_url', null)
            // La raison du parcours carte réussi reste `null` — jamais 'wallet'.
            ->assertJsonPath('reason', null);

        Http::assertSent(function (ClientRequest $request): bool {
            $body = $request->data();

            return ($body['method'] ?? null) === 'creditcard'
                && ($body['cardToken'] ?? null) === 'tkn_inline_abc123'
                && ($body['amount']['value'] ?? null) === '11.80';
        });
    }

    /**
     * Un front qui envoie explicitement `method: null` (champ toujours présent dans son payload)
     * fait un paiement carte NORMAL — l'absence de portefeuille ne doit pas être punie d'un 422.
     */
    public function test_null_or_absent_method_keeps_the_historical_hosted_flow(): void
    {
        $this->configureMollie();
        [$customer, $order] = $this->webCardOrder(['total' => 11.80, 'subtotal' => 11.80]);

        Http::fake([
            'https://api.mollie.com/v2/payments' => Http::response(
                $this->molliePaymentPayload('tr_NULL001', $order->id, 'open', '11.80'),
                201
            ),
        ]);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/frontend/order/{$order->id}/mollie-checkout", ['method' => null])
            ->assertOk()
            ->assertJsonPath('reason', 'hosted')
            ->assertJsonPath('checkout_url', 'https://www.mollie.com/checkout/select-method/tr_NULL001');

        Http::assertSent(fn (ClientRequest $request): bool => !array_key_exists('method', $request->data()));
    }

    // ------------------------------------------------------------------
    // Gardes d'entrée conservées sur le chemin portefeuille
    // ------------------------------------------------------------------

    /**
     * Les portefeuilles sont la MÊME famille « carte en ligne » (payment_method=4) : la garde
     * d'entrée du contrôleur est inchangée et s'applique telle quelle. Une commande cash reste
     * refusée même avec un `method` valide, et le fail-closed 503 prime sur toute la validation.
     */
    public function test_wallet_does_not_open_a_side_door_around_existing_guards(): void
    {
        Http::fake();

        // Non configuré (défaut produit) → 503 AVANT toute validation, aucun appel sortant.
        [$customer, $order] = $this->webCardOrder();
        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/frontend/order/{$order->id}/mollie-checkout", ['method' => 'applepay'])
            ->assertStatus(503)
            ->assertJsonPath('message', 'Mollie non configuré.');

        $this->configureMollie();

        // Commande d'un autre client → 403 (propriété avant tout).
        $stranger = User::factory()->create(['branch_id' => $order->branch_id]);
        $this->actingAs($stranger, 'sanctum')
            ->postJson("/api/frontend/order/{$order->id}/mollie-checkout", ['method' => 'applepay'])
            ->assertStatus(403);

        // Commande cash (payment_method ≠ 4) → 422 : le portefeuille n'ouvre pas ce funnel.
        [$customer2, $cashOrder] = $this->webCardOrder(['payment_method' => PaymentGateway::CASH_ON_DELIVERY]);
        $this->actingAs($customer2, 'sanctum')
            ->postJson("/api/frontend/order/{$cashOrder->id}/mollie-checkout", ['method' => 'googlepay'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cette commande n\'attend pas un paiement carte en ligne.');

        // Commande déjà payée → 409, jamais un 2e encaissement par portefeuille.
        [$customer3, $paidOrder] = $this->webCardOrder(['payment_status' => PaymentStatus::PAID]);
        $this->actingAs($customer3, 'sanctum')
            ->postJson("/api/frontend/order/{$paidOrder->id}/mollie-checkout", ['method' => 'applepay'])
            ->assertStatus(409);

        Http::assertNothingSent();
    }

    /**
     * Défense en profondeur de la passerelle elle-même : `createPayment` est publique, donc elle
     * re-vérifie la whitelist et l'exclusivité — un futur appelant qui oublierait de filtrer ne
     * peut pas relayer un `method` arbitraire à Mollie.
     */
    public function test_gateway_itself_refuses_unknown_method_and_wallet_plus_token(): void
    {
        $this->configureMollie();
        Http::fake();
        [, $order] = $this->webCardOrder();
        // createPayment est typée FrontendOrder (modèle frère sur la même table 'orders').
        $frontendOrder = \App\Models\FrontendOrder::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
            ->findOrFail($order->id);
        $gateway = new \App\Http\PaymentGateways\Gateways\Mollie();

        try {
            $gateway->createPayment($frontendOrder, '', 'paypal');
            $this->fail('un method hors whitelist doit lever');
        } catch (\RuntimeException $e) {
            $this->assertSame('Moyen de paiement en ligne non pris en charge.', $e->getMessage());
        }

        try {
            $gateway->createPayment($frontendOrder, 'tkn_valid_abc12345', 'applepay');
            $this->fail('portefeuille + jeton carte doit lever');
        } catch (\RuntimeException $e) {
            $this->assertSame('Portefeuille et jeton carte sont exclusifs.', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------
    // Fixtures (miroir MollieStructureTest)
    // ------------------------------------------------------------------

    private function configureMollie(): void
    {
        Config::set('payment.mollie.enabled', true);
        Config::set('payment.mollie.api_key', 'test_dummyMollieKey123');
    }

    /**
     * Commande carte WEB (client authentifié) — le funnel 'card' du site (paymentMethod=4).
     *
     * @return array{0: User, 1: Order}
     */
    private function webCardOrder(array $overrides = []): array
    {
        $branch = Branch::factory()->create();
        $customer = User::factory()->create(['branch_id' => $branch->id]);

        $order = Order::factory()->create($overrides + [
            'branch_id' => $branch->id,
            'user_id' => $customer->id,
            'order_type' => OrderType::TAKEAWAY,
            'source' => Source::WEB,
            'source_surface' => 'web',
            'payment_method' => PaymentGateway::CARD,
            'payment_status' => PaymentStatus::UNPAID,
            'status' => OrderStatus::PENDING,
            'transaction_id' => null,
            'fiscal_sequence_no' => null,
            'subtotal' => 11.80,
            'total' => 11.80,
        ]);

        return [$customer, $order];
    }

    private function molliePaymentPayload(string $id, int $orderId, string $status, string $value): array
    {
        return [
            'resource' => 'payment',
            'id' => $id,
            'mode' => 'test',
            'status' => $status,
            'amount' => ['value' => $value, 'currency' => 'EUR'],
            'description' => 'Le Cayenne — Commande test',
            'metadata' => ['order_id' => (string) $orderId],
            '_links' => [
                'checkout' => ['href' => 'https://www.mollie.com/checkout/select-method/' . $id],
            ],
        ];
    }

    private function ensureInstalledFlag(): void
    {
        if (file_exists(storage_path('installed'))) {
            return;
        }
        touch(storage_path('installed'));
        $this->createdInstalledFlag = true;
    }
}
