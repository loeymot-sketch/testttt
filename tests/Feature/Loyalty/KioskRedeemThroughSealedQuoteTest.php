<?php

namespace Tests\Feature\Loyalty;

use App\Enums\Ask;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\Source;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\KioskMachine;
use App\Models\LoyaltyTransaction;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Smartisan\Settings\Facades\Settings;
use Tests\Feature\Concerns\HasPosQuoteBinding;
use Tests\TestCase;

/**
 * [FIDÉLITÉ BORNE 2026-08-19] LE CLIENT PEUT-IL VRAIMENT DÉPENSER SES POINTS À LA BORNE ?
 *
 * ── POURQUOI CE FICHIER N'EXISTAIT PAS ───────────────────────────────────────────────────────
 * Le rachat de points borne est construit et testé côté serveur depuis des mois
 * (`FrontendOrderService::applyKioskLoyaltyDiscount` : calcul, débit atomique, grand-livre).
 * Mais TOUS ses tests contournent le sceau du devis — `KioskLoyaltyLedgerAtomicTest` porte même
 * une méthode nommée `bypassKioskQuoteSealForLoyaltySentinel()` qui remplace
 * `OrderQuoteService` par un double. L'interaction entre le DÉBIT et le SCEAU n'a donc jamais
 * été éprouvée, alors que c'est elle que la borne rencontre en production.
 *
 * Ce fichier joue le parcours COMPLET, sceau compris — le seul qui ressemble à un vrai client
 * devant la borne.
 */
class KioskRedeemThroughSealedQuoteTest extends TestCase
{
    use RefreshDatabase;
    use HasPosQuoteBinding;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        config(['app.api_key' => 'test-api-key']);

        // Barème réel de production (mesuré le 2026-08-19).
        Settings::group('loyalty_setup')->set([
            'loyalty_points_per_euro' => 10,
            'loyalty_points_for_1_euro_discount' => 100,
            'loyalty_min_redeem_points' => 1000,
        ]);
    }

    /** @return array{0:User,1:Branch,2:Item,3:User} */
    private function borne(int $soldeClient): array
    {
        $branch = Branch::factory()->create();
        $kioskUser = User::factory()->create(['branch_id' => $branch->id]);
        KioskMachine::factory()->create(['branch_id' => $branch->id, 'user_id' => $kioskUser->id]);

        $tax = Tax::factory()->create(['tax_rate' => 0, 'type' => TaxType::PERCENTAGE, 'status' => Status::ACTIVE]);
        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
            'price' => 20.00,
            'status' => Status::ACTIVE,
        ]);

        $client = User::factory()->create([
            'branch_id' => 0,
            'status' => Status::ACTIVE,
            'loyalty_code' => 'BORNE001',
            'loyalty_points' => $soldeClient,
        ]);

        return [$kioskUser, $branch, $item, $client];
    }

    /**
     * @param int $points Points que le client dépense. La borne envoie une QUANTITÉ, jamais un
     *                    montant : son payload ne doit porter aucun champ monétaire (SSOT/NF525).
     * @return array<string, mixed>
     */
    private function commande(Branch $branch, Item $item, int $points): array
    {
        return [
            'branch_id' => $branch->id,
            'order_type' => OrderType::TAKEAWAY,
            'is_advance_order' => Ask::NO,
            'source' => Source::WEB,
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'loyalty_code' => 'BORNE001',
            'loyalty_redeem_points' => $points,
            'delivery_charge' => 0,
            'items' => json_encode([[
                'item_id' => $item->id,
                'quantity' => 1,
                'item_variations' => [],
                'item_extras' => [],
            ]]),
        ];
    }

    /**
     * LE PARCOURS DU CLIENT : 20 € au panier, 2000 points en poche, il en dépense 1500 (= 15 €).
     * Il doit payer 5 €, et ses points doivent réellement partir.
     *
     * C'est précisément le cas que le contournement du sceau empêchait de voir : après le débit,
     * il ne reste que 500 points — sous le plancher de 1000. Si le sceau RECALCULE le rachat sur
     * ce solde amputé, il conclut « remise 0 », compare 20 € à 5 € et refuse la commande.
     */
    public function test_le_client_paie_le_montant_reduit_et_ses_points_partent(): void
    {
        [$kioskUser, $branch, $item, $client] = $this->borne(2000);

        Sanctum::actingAs($kioskUser, ['kiosk:order']);
        // [SUPERVISION 2026-08-19] `POST api/frontend/order` figure dans
        // `idempotency.required_routes` : sans cet en-tête, la requête est refusée AVANT
        // d'atteindre le contrôleur. Ce test passait parce que le banc de la session
        // fidélité portait un `.env.testing` (NON VERSIONNÉ, .gitignore:14) qui ne définit
        // pas IDEMPOTENCY_MIDDLEWARE_ENABLED — la valeur retombait donc sur le défaut
        // `false` de config/idempotency.php:28 et le filtre s'effaçait. Sur un clone neuf,
        // en CI, ou avec `.env.example` (qui pose `true`, ligne 421, comme l'exige le garde
        // de démarrage NF525 en production), le test échouait. On emprunte désormais le
        // chemin réel : une borne envoie sa clé, comme les autres suites du dépôt.
        $reponse = $this->withHeader('x-api-key', 'test-api-key')
            ->withHeaders(['X-Idempotency-Key' => 'kio-'.bin2hex(random_bytes(6))])
            ->postJson('/api/frontend/order', $this->payloadWithKioskQuote($kioskUser, $this->commande($branch, $item, 1500)));

        $this->assertContains(
            $reponse->status(),
            [200, 201],
            'La borne doit accepter une commande payée en partie avec des points. Réponse : '.$reponse->getContent()
        );

        $commande = \App\Models\FrontendOrder::withoutGlobalScopes()->latest('id')->first();
        $this->assertNotNull($commande);
        $this->assertSame(15.00, round((float) $commande->discount, 2), 'la remise fidélité doit être appliquée');
        $this->assertSame(5.00, round((float) $commande->total, 2), 'le client paie le montant réduit');

        $client->refresh();
        $this->assertSame(500, (int) $client->loyalty_points, 'les points doivent réellement partir');

        $this->assertDatabaseHas('loyalty_transactions', [
            'user_id' => $client->id,
            'type' => 'redeem',
            'points' => -1500,
        ]);
        $this->assertSame(1, LoyaltyTransaction::where('type', 'redeem')->count(), 'un seul débit, pas deux');
    }

    /**
     * LE CONTRE-EXEMPLE : sans demande de rachat, rien ne bouge. Sans lui, le test précédent
     * passerait même si le débit se déclenchait tout seul.
     */
    public function test_sans_rachat_le_solde_est_intact(): void
    {
        [$kioskUser, $branch, $item, $client] = $this->borne(2000);

        Sanctum::actingAs($kioskUser, ['kiosk:order']);
        // [SUPERVISION 2026-08-19] Même raison que le test précédent : la clé d'idempotence
        // est exigée sur cette route dès que le filtre est actif (donc partout sauf sur un
        // banc portant un `.env.testing` qui l'omet).
        $reponse = $this->withHeader('x-api-key', 'test-api-key')
            ->withHeaders(['X-Idempotency-Key' => 'kio-'.bin2hex(random_bytes(6))])
            ->postJson('/api/frontend/order', $this->payloadWithKioskQuote($kioskUser, $this->commande($branch, $item, 0)));

        $this->assertContains($reponse->status(), [200, 201], $reponse->getContent());

        $commande = \App\Models\FrontendOrder::withoutGlobalScopes()->latest('id')->first();
        $this->assertSame(0.0, round((float) $commande->discount, 2));
        $this->assertSame(20.00, round((float) $commande->total, 2));

        $client->refresh();
        $this->assertSame(2000, (int) $client->loyalty_points);
    }
}
