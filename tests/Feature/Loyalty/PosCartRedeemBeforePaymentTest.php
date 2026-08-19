<?php

namespace Tests\Feature\Loyalty;

use App\Enums\PosPaymentMethod;
use App\Enums\Source;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Order;
use App\Models\Tax;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Smartisan\Settings\Facades\Settings;
use Tests\Feature\Concerns\HasPosQuoteBinding;
use Tests\Feature\Pos\Traits\SeedsOpenCashDrawerSession;
use Tests\TestCase;

/**
 * [FIDÉLITÉ CAISSE 2026-08-19] « UTILISER SES POINTS » AU COMPTOIR — LE GESTE QUI NE PASSAIT PAS.
 *
 * ── LE DÉFAUT, REPRODUIT AVANT CORRECTION ────────────────────────────────────────────────────
 * `POST /api/admin/pos-order/{id}/redeem-loyalty` sur une vente de caisse réelle rendait
 * **409 ORDER_ALREADY_FINALIZED — « Cette commande est deja payee »**. Ce n'était pas un cas
 * limite : `PosRedemptionService` opère sur une commande DÉJÀ CRÉÉE et refuse tout état payé ou
 * terminal, alors qu'une vente de comptoir naît PAYÉE et LIVRÉE dans le même geste. La fenêtre
 * autorisée était donc VIDE. Le propriétaire disait « ça passe jamais » : c'était exact, et
 * structurel — aucun réglage, aucune permission n'y changeait quoi que ce soit.
 *
 * ── CE QUI EST VÉRIFIÉ ICI ───────────────────────────────────────────────────────────────────
 * Que la réduction existe AVANT que l'argent soit compté. C'est la seule fenêtre correcte : si
 * la remise arrive après, le caissier a déjà encaissé le mauvais montant.
 *
 * Le test vaut aussi comme garde anti-divergence : la réduction est calculée DEUX fois — au
 * devis scellé (`OrderQuoteService`) puis à la création (`OrderService`) — et le sceau refuse la
 * vente au moindre écart de centime. Un 201 ici prouve que les deux chemins s'accordent ; c'est
 * précisément ce que le motif « jumeau oublié » casse en premier dans ce projet.
 */
class PosCartRedeemBeforePaymentTest extends TestCase
{
    use RefreshDatabase;
    use HasPosQuoteBinding;
    use SeedsOpenCashDrawerSession;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();

        // Barème EXPLICITE : 10 pts gagnés par euro, 100 pts = 1 € de remise, plancher 1000 pts.
        // Ce sont les valeurs réelles de production (mesurées le 2026-08-19) — un test sur des
        // valeurs par défaut différentes prouverait quelque chose que le comptoir ne vit pas.
        Settings::group('loyalty_setup')->set([
            'loyalty_points_per_euro' => 10,
            'loyalty_points_for_1_euro_discount' => 100,
            'loyalty_min_redeem_points' => 1000,
        ]);
    }

    /** @return array{0:\App\Models\Branch,1:\App\Models\User,2:\App\Models\Item} */
    private function comptoir(): array
    {
        $branch = \Database\Factories\BranchFactory::new()->create();
        $caissier = \Database\Factories\UserFactory::new()->create(['branch_id' => $branch->id]);
        $caissier->assignRole('Admin');
        $this->seedOpenSessionFor($caissier, $branch);

        $categorie = \Database\Factories\ItemCategoryFactory::new()->create();
        $taxe = Tax::factory()->create(['tax_rate' => 0, 'type' => TaxType::FIXED]);
        $article = \Database\Factories\ItemFactory::new()->create([
            'item_category_id' => $categorie->id,
            'tax_id' => $taxe->id,
            'price' => 30.00,
        ]);

        return [$branch, $caissier, $article];
    }

    private function client(int $solde): \App\Models\User
    {
        return \Database\Factories\UserFactory::new()->create([
            'branch_id' => 0,
            'status' => Status::ACTIVE,
            'loyalty_code' => 'REDEEM01',
            'loyalty_points' => $solde,
        ]);
    }

    /** @param array<string,mixed> $extra */
    private function payload(\App\Models\Branch $b, \App\Models\User $caissier, \App\Models\Item $a, array $extra = []): array
    {
        return array_merge([
            'order_type' => \App\Enums\OrderType::TAKEAWAY,
            'source' => Source::POS,
            'customer_id' => $caissier->id,
            'branch_id' => $b->id,
            'is_advance_order' => 0,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'items' => json_encode([[
                'item_id' => $a->id,
                'price' => 30.00,
                'quantity' => 1,
            ]]),
        ], $extra);
    }

    private function envoyer(\App\Models\User $caissier, array $payload)
    {
        return $this->actingAs($caissier, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->withHeader('X-Idempotency-Key', 'test-redeem-'.uniqid('', true))
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($caissier, $payload));
    }

    /**
     * LE PARCOURS DU PROPRIÉTAIRE : 30 € au panier, le client a 2000 points, il en dépense 1000
     * (= 10 €). Il paie 20 € — pas 30 € suivis d'un refus.
     */
    public function test_le_client_paie_le_montant_deja_reduit_et_ses_points_sont_debites(): void
    {
        [$branch, $caissier, $article] = $this->comptoir();
        $client = $this->client(2000);

        $this->envoyer($caissier, $this->payload($branch, $caissier, $article, [
            'loyalty_customer_code' => 'REDEEM01',
            'loyalty_redeem_points' => 1000,
            // Le caissier encaisse le montant RÉDUIT. Si la réduction n'était pas prise en compte
            // par le devis, la garde « reçu >= total » rejetterait cette vente — ce test échouerait
            // donc AUSSI si la remise n'arrivait qu'après le calcul du prix.
            'pos_received_amount' => 20.00,
        ]))->assertStatus(201);

        $commande = Order::withoutGlobalScopes()->latest('id')->first();
        $this->assertNotNull($commande);
        $this->assertSame(10.00, round((float) $commande->discount, 2), '1000 points = 10 € au taux de 100');
        $this->assertSame(20.00, round((float) $commande->total, 2), 'le total encaissé est déjà net');
        $this->assertSame('REDEEM01', $commande->loyalty_customer_code);

        // Les points sont réellement partis, et le grand-livre le dit.
        $client->refresh();
        $this->assertSame(1000, (int) $client->loyalty_points);
        $this->assertDatabaseHas('loyalty_transactions', [
            'user_id' => $client->id,
            'order_id' => $commande->id,
            'type' => 'redeem',
            'points' => -1000,
            'balance_after' => 1000,
            'source_surface' => 'pos',
        ]);
    }

    /**
     * SOUS LE PLANCHER, ON REFUSE — ET ON LE DIT.
     *
     * Un refus SILENCIEUX serait pire que le défaut d'origine : le caissier annonce une réduction
     * au client, la vente part au prix plein, et personne ne comprend. On veut un échec bruyant.
     */
    public function test_un_solde_sous_le_plancher_refuse_la_vente_sans_debiter(): void
    {
        [$branch, $caissier, $article] = $this->comptoir();
        $client = $this->client(900); // plancher = 1000

        $reponse = $this->envoyer($caissier, $this->payload($branch, $caissier, $article, [
            'loyalty_customer_code' => 'REDEEM01',
            'loyalty_redeem_points' => 900,
            'pos_received_amount' => 30.00,
        ]));

        $this->assertNotSame(201, $reponse->getStatusCode(), 'un rachat sous le plancher ne doit pas aboutir');

        $client->refresh();
        $this->assertSame(900, (int) $client->loyalty_points, 'aucun point ne doit avoir bougé');
        $this->assertDatabaseMissing('loyalty_transactions', [
            'user_id' => $client->id,
            'type' => 'redeem',
        ]);
    }

    /**
     * LE PLAFOND DE LA COMMANDE. On ne débite jamais plus de points que la vente ne peut absorber :
     * sinon le client paie 0 € et perd des points pour une valeur qui n'a pas été livrée.
     */
    public function test_le_rachat_ne_depasse_jamais_le_sous_total(): void
    {
        [$branch, $caissier, $article] = $this->comptoir();
        $client = $this->client(100000); // 1000 € de points sur une vente de 30 €

        $this->envoyer($caissier, $this->payload($branch, $caissier, $article, [
            'loyalty_customer_code' => 'REDEEM01',
            'loyalty_redeem_points' => 100000,
            'pos_received_amount' => 0.00,
        ]))->assertStatus(201);

        $commande = Order::withoutGlobalScopes()->latest('id')->first();
        $this->assertSame(30.00, round((float) $commande->discount, 2), 'la remise s’arrête au sous-total');
        $this->assertSame(0.00, round((float) $commande->total, 2));

        // 30 € au taux de 100 = 3000 points, et pas un de plus.
        $client->refresh();
        $this->assertSame(100000 - 3000, (int) $client->loyalty_points);
    }

    /**
     * ANNULER LA VENTE REND LES POINTS. Le point de second ordre qui coûte le plus cher quand on
     * l'oublie : un client dont la commande est annulée après avoir « payé » avec ses points les
     * perdrait deux fois (pas de repas, pas de points), et rien dans l'écran ne le montrerait.
     *
     * Le remboursement existait déjà (`LoyaltyService::refundPoints`) mais il ne retrouve QUE les
     * lignes de grand-livre de forme exacte (order_id + type `redeem`) sur une commande portant un
     * `loyalty_customer_code`. Ce test verrouille le fait que la nouvelle écriture a bien cette
     * forme — la supposer aurait été supposer sur de l'argent.
     */
    public function test_annuler_la_vente_rend_les_points_au_client(): void
    {
        [$branch, $caissier, $article] = $this->comptoir();
        $client = $this->client(2000);

        $this->envoyer($caissier, $this->payload($branch, $caissier, $article, [
            'loyalty_customer_code' => 'REDEEM01',
            'loyalty_redeem_points' => 1000,
            'pos_received_amount' => 20.00,
        ]))->assertStatus(201);

        $commande = Order::withoutGlobalScopes()->latest('id')->first();
        $client->refresh();
        $this->assertSame(1000, (int) $client->loyalty_points, 'points bien débités avant annulation');

        /*
         * On appelle le rembourseur DIRECTEMENT, et c'est délibéré.
         *
         * Que l'annulation d'une commande appelle `refundPoints` est un comportement ANCIEN, déjà
         * couvert (OrderCancellationLoyaltyTest, LoyaltyClawbackOnUnpaidCancelTest) et gardé par sa
         * propre matrice de permissions. Ce qui est NEUF ici, c'est la FORME de l'écriture que
         * pose le rachat au panier : `refundPoints` ne retrouve que les lignes (order_id + type
         * `redeem`) portées par une commande ayant un `loyalty_customer_code`. Une écriture de
         * forme légèrement différente serait invisible pour lui — et les points seraient perdus en
         * silence. C'est cette compatibilité-là qu'on verrouille, pas le routage de l'annulation.
         */
        app(\App\Services\LoyaltyService::class)->refundPoints($commande->refresh(), 'pos');

        $client->refresh();
        $this->assertSame(2000, (int) $client->loyalty_points, 'les points rachetés doivent revenir');
    }

    /**
     * SANS DEMANDE DE RACHAT, RIEN NE BOUGE — le contre-exemple qui donne sa valeur aux autres.
     */
    public function test_une_vente_sans_rachat_ne_touche_pas_au_solde(): void
    {
        [$branch, $caissier, $article] = $this->comptoir();
        $client = $this->client(2000);

        $this->envoyer($caissier, $this->payload($branch, $caissier, $article, [
            'loyalty_customer_code' => 'REDEEM01',
            'pos_received_amount' => 30.00,
        ]))->assertStatus(201);

        $commande = Order::withoutGlobalScopes()->latest('id')->first();
        $this->assertSame(0.00, round((float) $commande->discount, 2));
        $this->assertSame(30.00, round((float) $commande->total, 2));

        $client->refresh();
        $this->assertSame(2000, (int) $client->loyalty_points);
    }
}
