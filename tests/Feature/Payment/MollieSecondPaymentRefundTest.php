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
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * [P0 ARGENT 2026-08-08 · JUMEAU OUBLIÉ] Un SECOND paiement sur une commande DÉJÀ payée était
 * encaissé et GARDÉ, sans jamais être remboursé.
 *
 * Le webhook Mollie sait rembourser dans deux situations, et le faisait :
 *   · montant non attribuable au total scellé  (`Mollie.php` ~l.501) ;
 *   · paiement sur une commande terminale      (`Mollie.php` ~l.565).
 * Le troisième cas de la même famille — « paiement sur commande déjà payée » — se contentait de
 * marquer l'événement traité et de rendre 200 à Mollie. L'argent restait chez nous : hors Z, hors
 * NF525, invisible du client qui voit deux débits pour un seul repas.
 *
 * Comment un client se fait débiter deux fois, concrètement : deux onglets sur le tunnel (la clé
 * d'idempotence du site vit dans `sessionStorage`, donc PAR onglet), ou un retour bancaire lent
 * suivi d'un nouvel essai. Rien d'exotique.
 *
 * LA PROPRIÉTÉ QU'IL NE FAUT SURTOUT PAS PERDRE : Mollie RÉESSAIE ses webhooks. Rembourser sur la
 * simple relance du MÊME paiement annulerait une vente parfaitement légitime — soit exactement le
 * défaut inverse, et pire. C'est pourquoi le remboursement est conditionné à un identifiant de
 * paiement DIFFÉRENT de celui déjà rattaché à la commande, et pourquoi le second test ci-dessous
 * compte autant que le premier.
 *
 * Suite 100 % mockée (`Http::fake`) : l'API Mollie n'est jamais appelée.
 */
class MollieSecondPaymentRefundTest extends TestCase
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

    /** Une commande WEB DÉJÀ PAYÉE, dont la vente est rattachée au premier paiement. */
    private function commandeDejaPayee(string $premierPaiement = 'tr_PREMIER01'): Order
    {
        $branch = Branch::factory()->create();
        $client = User::factory()->create(['branch_id' => 0]);

        return Order::factory()->create([
            'branch_id' => $branch->id, 'user_id' => $client->id,
            'order_type' => OrderType::TAKEAWAY, 'source' => Source::WEB, 'source_surface' => 'web',
            'payment_method' => PaymentGateway::CARD,
            'payment_status' => PaymentStatus::PAID,
            'transaction_id' => 'mollie:' . $premierPaiement,
            'card_type' => 'mollie',
            'status' => OrderStatus::PENDING,
            'subtotal' => 12.40, 'total' => 12.40,
        ]);
    }

    /**
     * Mollie répond « payé » au re-fetch, et accepte le remboursement.
     * Le fake distingue le GET du paiement du POST de remboursement.
     */
    private function fakeMollie(string $paymentId, int $orderId): void
    {
        Http::fake([
            '*/payments/' . $paymentId . '/refunds' => Http::response([
                'resource' => 'refund', 'id' => 're_TEST01', 'status' => 'pending',
                'amount' => ['value' => '12.40', 'currency' => 'EUR'],
            ], 201),
            '*/payments/*' => Http::response([
                'resource' => 'payment', 'id' => $paymentId, 'mode' => 'test', 'status' => 'paid',
                'amount' => ['value' => '12.40', 'currency' => 'EUR'],
                'amountRefunded' => ['value' => '0.00', 'currency' => 'EUR'],
                'metadata' => ['order_id' => (string) $orderId],
            ], 200),
        ]);
    }

    private function webhook(string $paymentId)
    {
        return $this->postJson('/api/webhook/mollie', ['id' => $paymentId]);
    }

    private function remboursementsEnvoyes(string $paymentId): int
    {
        $n = 0;
        foreach (Http::recorded() as [$requete]) {
            if (str_contains($requete->url(), '/payments/' . $paymentId . '/refunds')
                && $requete->method() === 'POST') {
                $n++;
            }
        }

        return $n;
    }

    /**
     * LE DÉFAUT : deuxième débit encaissé, jamais rendu.
     */
    public function test_un_SECOND_paiement_sur_commande_deja_payee_est_AUTO_REMBOURSE(): void
    {
        $commande = $this->commandeDejaPayee('tr_PREMIER01');
        $this->fakeMollie('tr_SECOND02', $commande->id);

        $this->webhook('tr_SECOND02')->assertOk();

        $this->assertSame(1, $this->remboursementsEnvoyes('tr_SECOND02'),
            'le second encaissement DOIT être rendu au client : gardé, il est hors Z et hors NF525');
    }

    /**
     * LA VENTE D'ORIGINE EST INTOUCHABLE : on rend le second débit, on n'annule pas le repas.
     * Sans cette assertion, un remboursement qui casserait la vente passerait pour un succès.
     */
    public function test_la_vente_d_origine_survit_intacte_au_remboursement_du_second_debit(): void
    {
        $commande = $this->commandeDejaPayee('tr_PREMIER01');
        $this->fakeMollie('tr_SECOND02', $commande->id);

        $this->webhook('tr_SECOND02')->assertOk();

        $commande->refresh();
        $this->assertSame(PaymentStatus::PAID, (int) $commande->payment_status,
            'la commande doit RESTER payée : le client a bien commandé et sera servi');
        $this->assertSame('mollie:tr_PREMIER01', (string) $commande->transaction_id,
            'la vente doit rester rattachée au PREMIER paiement, pas au doublon remboursé');
        $this->assertSame(0, $this->remboursementsEnvoyes('tr_PREMIER01'),
            'le premier paiement — la vraie vente — ne doit JAMAIS être remboursé');
    }

    /**
     * LA CONTRE-PROPRIÉTÉ VITALE : Mollie réessaie ses webhooks. Une relance du MÊME paiement ne
     * doit rien rembourser, sinon on annule une vente légitime — l'inverse du défaut, en pire.
     */
    public function test_une_RELANCE_du_meme_paiement_ne_rembourse_RIEN(): void
    {
        $commande = $this->commandeDejaPayee('tr_PREMIER01');
        $this->fakeMollie('tr_PREMIER01', $commande->id);

        // Trois notifications du MÊME paiement : c'est le comportement normal de Mollie.
        $this->webhook('tr_PREMIER01');
        $this->webhook('tr_PREMIER01');
        $this->webhook('tr_PREMIER01');

        $this->assertSame(0, $this->remboursementsEnvoyes('tr_PREMIER01'),
            'rembourser une relance annulerait la vente : la garde doit comparer les identifiants');

        $commande->refresh();
        $this->assertSame(PaymentStatus::PAID, (int) $commande->payment_status);
    }

    /**
     * Garde anti-test-vide : le harnais doit être capable d'observer un POST de remboursement.
     * Sans elle, un fake mal branché rendrait les assertions « 0 remboursement » triomphalement
     * vraies pour la mauvaise raison — c'est exactement le piège des tests creux de ce projet.
     */
    public function test_le_harnais_sait_reellement_detecter_un_remboursement(): void
    {
        $commande = $this->commandeDejaPayee('tr_PREMIER01');
        $this->fakeMollie('tr_SONDE03', $commande->id);

        Http::withToken('x')->post(config('payment.mollie.api_base') . '/payments/tr_SONDE03/refunds', []);

        $this->assertSame(1, $this->remboursementsEnvoyes('tr_SONDE03'),
            'le compteur de remboursements doit savoir compter, sinon rien au-dessus ne prouve quoi que ce soit');
    }
}
