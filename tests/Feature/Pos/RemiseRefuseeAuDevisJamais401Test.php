<?php

namespace Tests\Feature\Pos;

use App\Enums\Ask;
use App\Enums\OrderType;
use App\Enums\PosPaymentMethod;
use App\Enums\Source;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Order;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Pos\Traits\SeedsOpenCashDrawerSession;
use Tests\TestCase;

/**
 * [AUDIT CAISSE 2026-09-29 · P0 — REPRODUIT PAR L'ÉCRAN] Appliquer une remise en caisse
 * DÉCONNECTAIT le caissier en pleine vente.
 *
 * Chaîne mesurée (Playwright, arbre :8000, base de dev) :
 *   devis 200 AVEC remise → commande SANS remise (PaymentComponent retire `discount`,
 *   zone gelée) → « Order quote intent mismatch. » en 401 → l'intercepteur axios global
 *   traite tout 401 comme « session expirée » → /login. Zéro commande, zéro numéro
 *   fiscal, et le client avait déjà tendu son argent.
 *
 * Deux correctifs, hors zone gelée, figés ici :
 *   1. le coupe-circuit V1 des remises manuelles (`pos.manual_discount_enabled`, défaut
 *      false) s'applique AU DEVIS — le refus tombe avant la modale de paiement, avec le
 *      message prévu, avant qu'un centime ne change de main ;
 *   2. un refus MÉTIER du devis (absent, invalide, signature ou intention divergente)
 *      est un 409, jamais un 401. Un 401 n'appartient qu'à l'authentification.
 */
class RemiseRefuseeAuDevisJamais401Test extends TestCase
{
    use RefreshDatabase;
    use SeedsOpenCashDrawerSession;

    public function test_une_remise_est_refusee_au_devis_avec_le_message_prevu_quand_le_coupe_circuit_est_ferme(): void
    {
        config(['pos.manual_discount_enabled' => false]);
        [$operator, $payload] = $this->fixture();

        $devis = $this->actingAs($operator, 'sanctum')
            ->postJson('/api/admin/pos/quote', array_merge($payload, ['discount' => 0.74, 'discount_reason' => 'Geste commercial'])); // array_merge : `+` gardait le discount=0 du gabarit

        $devis->assertStatus(422);
        $this->assertStringContainsString(
            'remises manuelles sont désactivées',
            (string) $devis->json('errors.discount.0'),
            'Le caissier doit lire POURQUOI, au moment où il clique « Payer », pas après l\'encaissement.'
        );
        $this->assertSame(0, Order::count(), 'Aucune commande ne doit naître d\'un devis refusé.');
    }

    public function test_sans_remise_le_devis_passe_toujours(): void
    {
        config(['pos.manual_discount_enabled' => false]);
        [$operator, $payload] = $this->fixture();

        $this->actingAs($operator, 'sanctum')
            ->postJson('/api/admin/pos/quote', $payload)
            ->assertOk();
    }

    /**
     * LE CŒUR DU DÉFAUT : un commit dont le devis ne correspond pas ne doit JAMAIS
     * répondre 401 — c'est ce code qui éjecte la caisse.
     */
    public function test_un_devis_qui_ne_correspond_pas_repond_409_jamais_401(): void
    {
        config(['app.api_key' => 'test-api-key']);
        [$operator, $payload] = $this->fixture();

        $devis = $this->actingAs($operator, 'sanctum')
            ->postJson('/api/admin/pos/quote', $payload)
            ->assertOk()
            ->json('data');

        // Même devis, mais la commande arrive avec une intention DIFFÉRENTE (quantité
        // changée) — c'est la forme exacte du cas remise : le devis et la commande ne
        // décrivent plus la même vente.
        $items = json_decode($payload['items'], true);
        $items[0]['quantity'] = 3;

        $reponse = $this->actingAs($operator, 'sanctum')
            ->withHeader('x-api-key', 'test-api-key')
            ->postJson('/api/admin/pos', array_merge($payload, [
                'items' => json_encode($items),
                'quote_token' => $devis['quote_token'],
                'quote_signature' => $devis['signature'],
                'total' => $devis['total_ttc'],
                'pos_received_amount' => $devis['total_ttc'],
            ]));

        $this->assertNotSame(401, $reponse->status(), 'Un 401 ici déconnecte le caissier en pleine vente.');
        $this->assertSame(409, $reponse->status(), $reponse->getContent());
        $this->assertSame(0, Order::count());
    }

    public function test_un_commit_sans_devis_repond_409_jamais_401(): void
    {
        config(['app.api_key' => 'test-api-key']);
        [$operator, $payload] = $this->fixture();

        $reponse = $this->actingAs($operator, 'sanctum')
            ->withHeader('x-api-key', 'test-api-key')
            ->postJson('/api/admin/pos', $payload);

        $this->assertNotSame(401, $reponse->status());
        $this->assertSame(409, $reponse->status(), $reponse->getContent());
    }

    private function fixture(): array
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $branch = Branch::factory()->create();
        $operator = User::factory()->create(['branch_id' => $branch->id]);
        $operator->assignRole('POS Operator');
        $operator->givePermissionTo('pos');
        $this->seedOpenSessionFor($operator, $branch);
        $customer = User::factory()->create(['branch_id' => $branch->id]);
        $customer->assignRole('Customer');

        $tax = Tax::factory()->create(['tax_rate' => 0, 'type' => TaxType::PERCENTAGE, 'status' => Status::ACTIVE]);
        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
            'price' => 7.40,
            'status' => Status::ACTIVE,
        ]);

        return [$operator, [
            'token' => null,
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'subtotal' => 0,
            'discount' => 0,
            'coupon_id' => 0,
            'total' => 0,
            'order_type' => OrderType::TAKEAWAY,
            'is_advance_order' => Ask::NO,
            'source' => Source::POS,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'pos_received_amount' => 0,
            'items' => json_encode([[
                'item_id' => $item->id,
                'quantity' => 1,
                'item_variations' => [],
                'item_extras' => [],
            ]]),
        ]];
    }
}
