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
 * [SIGNALEMENT PROPRIÉTAIRE 2026-09-29] « J'arrive pas à rajouter un supplément
 * libre, ça met toujours erreur. Le chiffre s'ajoute au panier, mais j'arrive
 * pas à le passer en commande, ni sur le ticket ni sur l'écran de cuisine —
 * ça rentre pas dans le système. »
 *
 * POURQUOI CE FICHIER EXISTE ALORS QU'UN TEST VERT COUVRE DÉJÀ LE SUPPLÉMENT LIBRE
 * --------------------------------------------------------------------------------
 * `QuoteBindingTest::test_pos_commit_persists_a_sealed_manual_supplement_as_a_fiscal_line`
 * passe — mais il envoie un panier qui ne contient QUE le supplément libre, une
 * seule ligne. Le panier RÉEL du propriétaire (capture d'écran du 2026-09-28) est
 * MIXTE : « Bol Frites 10,80 € » avec deux suppléments catalogue, PLUS une ligne
 * « Portion pô… · Supplément libre » à 5,90 €, total 16,70 €.
 *
 * Un banc vert sur le mauvais périmètre ne prouve rien sur le périmètre voisin.
 * Ce fichier mesure donc le panier tel qu'il est réellement saisi en caisse.
 *
 * Les cas sont ordonnés du plus proche du test vert au plus proche de la capture,
 * pour que l'échec DÉSIGNE la différence qui casse, au lieu de dire seulement
 * « ça casse ».
 */
class SupplementLibreDansUnPanierMixteTest extends TestCase
{
    use RefreshDatabase;
    use SeedsOpenCashDrawerSession;

    /**
     * Cas 1 — la plus petite différence avec le test vert : une ligne catalogue
     * À CÔTÉ du supplément libre. Rien d'autre ne change.
     */
    public function test_une_ligne_catalogue_et_un_supplement_libre_passent_en_commande(): void
    {
        [$operator, $payload, $branch, $item] = $this->fixture();

        $payload['items'] = json_encode([
            [
                'item_id' => $item->id,
                'quantity' => 1,
                'item_variations' => [],
                'item_extras' => [],
            ],
            [
                'line_type' => 'manual_supplement',
                'manual_label' => 'Portion pôélée',
                'manual_amount' => 5.90,
                'quantity' => 1,
            ],
        ]);

        $reponse = $this->commander($operator, $payload);

        $this->assertContains(
            $reponse->status(),
            [200, 201],
            "Panier MIXTE (1 ligne catalogue + 1 supplément libre) refusé. "
            . "C'est le panier réel du caissier ; le test existant ne couvre que le "
            . "supplément SEUL. Réponse serveur : " . $reponse->getContent()
        );

        $commande = Order::findOrFail((int) $reponse->json('data.id'));
        $this->assertEqualsWithDelta(13.90, (float) $commande->total, 0.001,
            '8,00 € (article) + 5,90 € (supplément libre) = 13,90 €.');

        $this->assertDatabaseHas('order_items', [
            'order_id' => $commande->id,
            'line_type' => 'manual_supplement',
            'item_id' => null,
            'total_price' => 5.90,
        ]);
    }

    /**
     * Cas 2 — le supplément libre en PREMIÈRE position. L'ordre des lignes ne
     * devrait rien changer ; s'il change quelque chose, c'est une indexation
     * fragile (boucle qui suppose que l'index 0 est une ligne catalogue).
     */
    public function test_l_ordre_des_lignes_ne_change_rien(): void
    {
        [$operator, $payload, $branch, $item] = $this->fixture();

        $payload['items'] = json_encode([
            [
                'line_type' => 'manual_supplement',
                'manual_label' => 'Portion pôélée',
                'manual_amount' => 5.90,
                'quantity' => 1,
            ],
            [
                'item_id' => $item->id,
                'quantity' => 1,
                'item_variations' => [],
                'item_extras' => [],
            ],
        ]);

        $reponse = $this->commander($operator, $payload);

        $this->assertContains(
            $reponse->status(),
            [200, 201],
            'Supplément libre en première position refusé : ' . $reponse->getContent()
        );
    }

    /**
     * Cas 3 — plus de deux lignes, et DEUX suppléments libres.
     *
     * Note sur la capture : la ligne « Bol Frites » y porte aussi des suppléments
     * CATALOGUE (Raclette +0,90 €, Gratiné +2,00 €). Ils ne sont pas reproduits
     * ici, et ce n'est pas un oubli : un supplément catalogue vit dans les
     * colonnes JSON de SA ligne (`item_extras`, `item_extra_total`) — il ne
     * change donc pas le JEU DE COLONNES, qui est le cœur du défaut. Les inclure
     * exigerait de créer de vraies lignes de catalogue actives et disponibles sur
     * la surface, sans rien prouver de plus. (Vérifié en écrivant ce test : avec
     * des identifiants inventés le serveur répond « Supplément ID 1 introuvable »,
     * ce qui est un refus LÉGITIME — c'était mon instrument qui était faux, pas
     * le produit.)
     */
    public function test_plusieurs_supplements_libres_et_un_article_passent_en_commande(): void
    {
        [$operator, $payload, $branch, $item] = $this->fixture();

        $payload['items'] = json_encode([
            [
                'item_id' => $item->id,
                'quantity' => 1,
                'item_variations' => [],
                'item_extras' => [],
            ],
            [
                'line_type' => 'manual_supplement',
                'manual_label' => 'Portion pôélée',
                'manual_amount' => 5.90,
                'quantity' => 1,
            ],
            [
                'line_type' => 'manual_supplement',
                'manual_label' => 'Sauce maison',
                'manual_amount' => 1.00,
                'quantity' => 2,
            ],
        ]);

        $reponse = $this->commander($operator, $payload);

        $this->assertContains(
            $reponse->status(),
            [200, 201],
            'Panier à trois lignes (1 article + 2 suppléments libres) refusé : '
            . $reponse->getContent()
        );

        $commande = Order::findOrFail((int) $reponse->json('data.id'));
        $this->assertEqualsWithDelta(15.90, (float) $commande->total, 0.001,
            '8,00 € + 5,90 € + (1,00 € × 2) = 15,90 €.');

        $this->assertSame(
            3,
            $commande->orderItems()->withoutGlobalScopes()->count(),
            'Les trois lignes doivent exister en base — sinon rien ne peut partir '
            . 'au ticket ni en cuisine.'
        );
    }

    /**
     * Enchaîne devis puis validation, exactement comme le fait la caisse :
     * `quotePosCartForPayment()` POST /admin/pos/quote, puis POST /admin/pos
     * avec le jeton et la signature renvoyés (PosComponent.vue:6395-6418).
     */
    private function commander(User $operator, array $payload)
    {
        $devis = $this->actingAs($operator, 'sanctum')
            ->postJson('/api/admin/pos/quote', $payload);

        $this->assertSame(
            200,
            $devis->status(),
            "Le DEVIS lui-même a échoué — or le caissier voit bien le total à l'écran, "
            . "donc le devis passe en vrai. Réponse : " . $devis->getContent()
        );

        $devis = $devis->json('data');

        return $this->actingAs($operator, 'sanctum')
            ->withHeader('x-api-key', 'test-api-key')
            ->postJson('/api/admin/pos', array_merge($payload, [
                'quote_token' => $devis['quote_token'],
                'quote_signature' => $devis['signature'],
                'total' => $devis['total_ttc'],
                'pos_received_amount' => $devis['total_ttc'],
            ]));
    }

    /**
     * Même montage que QuoteBindingTest::fixture(), recopié plutôt que partagé :
     * ce fichier ne doit pas pouvoir casser le test d'un autre auteur.
     */
    private function fixture(): array
    {
        config(['app.api_key' => 'test-api-key']);
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $branch = Branch::factory()->create();
        $operator = User::factory()->create(['branch_id' => $branch->id]);
        $operator->assignRole('POS Operator');
        $operator->givePermissionTo('pos');
        $this->seedOpenSessionFor($operator, $branch);
        $customer = User::factory()->create(['branch_id' => $branch->id]);
        $customer->assignRole('Customer');

        $tax = Tax::factory()->create([
            'tax_rate' => 0,
            'type' => TaxType::PERCENTAGE,
            'status' => Status::ACTIVE,
        ]);
        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
            'price' => 8.00,
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
            'items' => json_encode([]),
        ], $branch, $item];
    }
}
