<?php

namespace Tests\Feature\Loyalty;

use App\Enums\OrderStatus;
use App\Enums\PosPaymentMethod;
use App\Enums\Source;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Order;
use App\Models\Tax;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\HasPosQuoteBinding;
use Tests\Feature\Pos\Traits\SeedsOpenCashDrawerSession;
use Tests\TestCase;

/**
 * [FIDÉLITÉ PANIER 2026-08-19] LA VENTE DE CAISSE PORTE-T-ELLE VRAIMENT SON CLIENT ?
 *
 * ── POURQUOI CE FICHIER EXISTE ───────────────────────────────────────────────────────────────
 * Mesuré sur la base réelle le 2026-08-19 : **1817 ventes de caisse, 12 portant un code
 * fidélité**, et 5 lignes « earn » de surface caisse dans TOUT le grand-livre. Le programme
 * était construit de bout en bout côté serveur — et ne tournait pas.
 *
 * La raison n'était pas un moteur absent mais une chaîne dont un maillon n'était jamais écrit :
 * `PosOrderRequest:215` accepte `loyalty_customer_code`, `OrderService` le persiste,
 * `AwardLoyaltyPointsOnDelivery` le lit pour créditer — mais AUCUNE surface de caisse ne le
 * renseignait (le modal d'identification était gaté sur une commande DÉJÀ validée).
 *
 * Cette sentinelle verrouille la chaîne complète côté serveur, pour que le jour où quelqu'un
 * « nettoie » ce champ jugé inutilisé, l'échec soit bruyant et immédiat plutôt que silencieux
 * pendant des mois de service.
 *
 * Le pendant côté écran est `tests/js/posLoyaltyAttachCart.spec.js`.
 */
class PosOrderCarriesLoyaltyCodeTest extends TestCase
{
    use RefreshDatabase;
    use HasPosQuoteBinding;
    use SeedsOpenCashDrawerSession;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    /** @return array{0:\App\Models\Branch,1:\App\Models\User,2:\App\Models\Item} */
    private function comptoir(): array
    {
        $branch = \Database\Factories\BranchFactory::new()->create();
        $caissier = \Database\Factories\UserFactory::new()->create(['branch_id' => $branch->id]);
        $caissier->assignRole('Admin');
        $this->seedOpenSessionFor($caissier, $branch);

        $categorie = \Database\Factories\ItemCategoryFactory::new()->create();
        // TVA fixe à 0 : ce test porte sur le RATTACHEMENT, pas sur le calcul fiscal — laisser
        // une taxe aléatoire ferait échouer la garde « reçu >= total » pour une raison sans
        // rapport avec ce qu'on prétend prouver.
        $taxe = Tax::factory()->create(['tax_rate' => 0, 'type' => TaxType::FIXED]);
        $article = \Database\Factories\ItemFactory::new()->create([
            'item_category_id' => $categorie->id,
            'tax_id' => $taxe->id,
            'price' => 10.00,
        ]);

        return [$branch, $caissier, $article];
    }

    private function client(int $soldeInitial = 0): \App\Models\User
    {
        return \Database\Factories\UserFactory::new()->create([
            'branch_id' => 0,
            'status' => Status::ACTIVE,
            'loyalty_code' => 'FIDTEST1',
            'loyalty_points' => $soldeInitial,
        ]);
    }

    /**
     * LE PARCOURS COMPLET : le caissier a rattaché le client au panier, la vente part avec son
     * code, et les points tombent quand la commande est servie.
     */
    public function test_une_vente_caisse_creee_avec_le_code_fidelite_credite_le_client(): void
    {
        [$branch, $caissier, $article] = $this->comptoir();
        $client = $this->client(0);

        $payload = [
            'order_type' => \App\Enums\OrderType::TAKEAWAY,
            'subtotal' => 10.00,
            'total' => 10.00,
            'source' => Source::POS,
            'customer_id' => $caissier->id,
            'branch_id' => $branch->id,
            'is_advance_order' => 0,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'pos_received_amount' => 10.00,
            // LE CHAMP EN CAUSE — ce que l'écran de caisse envoie désormais quand le caissier
            // a rattaché un client au panier avant d'encaisser.
            'loyalty_customer_code' => 'FIDTEST1',
            'items' => json_encode([[
                'item_id' => $article->id,
                'price' => 10.00,
                'quantity' => 1,
            ]]),
        ];

        $this->actingAs($caissier, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            // Le vrai client caisse génère cette clé à chaque envoi (store posOrder/save) : la
            // poser ici garde le test aligné sur le chemin réel, middleware d'idempotence inclus.
            ->withHeader('X-Idempotency-Key', 'test-fid-'.uniqid('', true))
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($caissier, $payload))
            ->assertStatus(201);

        $commande = Order::withoutGlobalScopes()->latest('id')->first();
        $this->assertNotNull($commande);

        // 1. Le code a SURVÉCU à la requête (c'est le maillon qui n'était jamais écrit).
        $this->assertSame('FIDTEST1', $commande->loyalty_customer_code);

        // 2. La vente servie crédite le bon compte, au barème de la maison.
        $commande->refresh();
        if ((int) $commande->status !== OrderStatus::DELIVERED) {
            $this->actingAs($caissier, 'sanctum')
                ->withHeader('x-api-key', config('app.api_key'))
                ->withHeader('X-Idempotency-Key', 'test-fid-st-'.uniqid('', true))
                ->postJson('/api/admin/pos-order/change-status/' . $commande->id, [
                    'status' => OrderStatus::DELIVERED,
                ]);
        }

        $pointsParEuro = (int) \Smartisan\Settings\Facades\Settings::group('loyalty_setup')
            ->get('loyalty_points_per_euro', 10);
        $attendu = (int) floor(10.00 * $pointsParEuro);

        $client->refresh();
        $this->assertSame(
            $attendu,
            (int) $client->loyalty_points,
            'La vente rattachée doit créditer le client au barème (points_per_euro).'
        );

        // 3. Le grand-livre garde la trace — un solde sans écriture ne se défend pas devant
        //    un client qui conteste.
        $this->assertDatabaseHas('loyalty_transactions', [
            'user_id' => $client->id,
            'loyalty_code' => 'FIDTEST1',
            'order_id' => $commande->id,
            'type' => 'earn',
        ]);
    }

    /**
     * LE CONTRE-EXEMPLE QUI DONNE SON SENS AU TEST PRÉCÉDENT.
     *
     * Sans code, la même vente ne doit créditer PERSONNE. Sinon le premier test passerait même
     * si le crédit se déclenchait tout seul, et ne prouverait rien du rattachement.
     */
    public function test_une_vente_sans_code_ne_credite_personne(): void
    {
        [$branch, $caissier, $article] = $this->comptoir();
        $client = $this->client(0);

        $payload = [
            'order_type' => \App\Enums\OrderType::TAKEAWAY,
            'subtotal' => 10.00,
            'total' => 10.00,
            'source' => Source::POS,
            'customer_id' => $caissier->id,
            'branch_id' => $branch->id,
            'is_advance_order' => 0,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'pos_received_amount' => 10.00,
            'items' => json_encode([[
                'item_id' => $article->id,
                'price' => 10.00,
                'quantity' => 1,
            ]]),
        ];

        $this->actingAs($caissier, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            // Le vrai client caisse génère cette clé à chaque envoi (store posOrder/save) : la
            // poser ici garde le test aligné sur le chemin réel, middleware d'idempotence inclus.
            ->withHeader('X-Idempotency-Key', 'test-fid-'.uniqid('', true))
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($caissier, $payload))
            ->assertStatus(201);

        $commande = Order::withoutGlobalScopes()->latest('id')->first();
        $this->assertNull($commande->loyalty_customer_code);

        // La commande DOIT réellement atteindre « livrée », sinon l'assertion « 0 point »
        // passerait à vide et ce contre-exemple ne prouverait plus rien.
        $this->actingAs($caissier, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->withHeader('X-Idempotency-Key', 'test-fid-st2-'.uniqid('', true))
            ->postJson('/api/admin/pos-order/change-status/' . $commande->id, [
                'status' => OrderStatus::DELIVERED,
            ])
            ->assertOk();

        $commande->refresh();
        $this->assertSame(OrderStatus::DELIVERED, (int) $commande->status);

        $client->refresh();
        $this->assertSame(0, (int) $client->loyalty_points);
    }
}
