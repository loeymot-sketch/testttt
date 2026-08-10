<?php

namespace Tests\Feature\Pos;

use App\Enums\Ask;
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
use Illuminate\Support\Facades\DB;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * RATTACHER LE CLIENT À SA VENTE — le geste qui fait enfin tourner le programme de fidélité.
 *
 * ── LE CONSTAT ───────────────────────────────────────────────────────────────────────────────
 * Mesuré en base le 10 août : **1411 ventes de caisse arrivées à DELIVERED, UNE SEULE rattachée à
 * un client.** Le crédit fonctionnait (`AwardLoyaltyPointsOnDelivery`) ; personne ne pouvait dire à
 * qui créditer. Le programme existait sur le papier et ne tournait que pour la borne et le site.
 *
 * ── CE QUI REND CE BANC INDISPENSABLE ────────────────────────────────────────────────────────
 * Une vente de caisse atteint DELIVERED tout de suite : le guetteur de points est DÉJÀ passé quand
 * le caissier rattache. Rattacher sans relancer le crédit laisserait le client à zéro point tout en
 * lui affichant « c'est noté » — la pire des deux issues, parce qu'invisible.
 *
 * Et on relance le guetteur SEUL. `OrderStatusChanged` porte quatre auditeurs : rediffuser
 * l'événement ferait ressortir un second ticket cuisine et une seconde notification pour une vente
 * déjà servie. Ce banc l'épingle.
 */
class PosLoyaltyAttachTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branche;

    private User $caissier;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        Config::set('fiscal.audit_secret', 'test-fiscal-secret-'.str_repeat('e', 40));
        Config::set('loyalty.qr.secret', 'test-qr-secret-'.str_repeat('e', 40));

        Settings::group('loyalty_setup')->set([
            'loyalty_points_per_euro'            => 10,
            'loyalty_points_for_1_euro_discount' => 100,
            'loyalty_min_redeem_points'          => 1000,
        ]);

        $this->branche = Branch::factory()->create();

        $this->caissier = User::factory()->create(['branch_id' => $this->branche->id, 'phone' => '0100000003']);
        $this->caissier->assignRole('POS Operator');

        $this->client = User::factory()->create(['phone' => '0622334455', 'is_guest' => Ask::YES]);
        DB::table('users')->where('id', $this->client->id)
            ->update(['loyalty_code' => 'RATTACH1', 'loyalty_points' => 0]);
        $this->client->refresh();
    }

    private function vente(int $statut, float $montant = 24.00, array $extra = []): Order
    {
        return Order::factory()->create(array_merge([
            'branch_id'          => $this->branche->id,
            'subtotal'           => $montant,
            'discount'           => 0.00,
            'total_tax'          => 0.00,
            'delivery_charge'    => 0.00,
            'total'              => $montant,
            'status'             => $statut,
            'payment_status'     => PaymentStatus::PAID,
            'payment_method'     => PaymentGateway::CASH_ON_DELIVERY,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'order_type'         => OrderType::TAKEAWAY,
            'source_surface'     => 'pos',
        ], $extra));
    }

    /**
     * PIÈGE LATENT ÉPINGLÉ ICI. `AwardLoyaltyPointsOnDelivery:98-104` lit
     * `$order->order_amount ?? $order->total` pour les commandes non-borne, et son commentaire
     * affirme « Order (POS) uses 'order_amount' ». Cette colonne N'EXISTE PAS — vérifié sur la base
     * de production comme sur celle des tests. La chaîne `??` sauve la mise et tout le monde lit
     * `total`.
     *
     * Le jour où quelqu'un ajoute une colonne `order_amount` avec un défaut à 0.00, `??` ne
     * déclenchera plus : `(float) 0.00` est une valeur, pas un null. TOUTES les ventes de caisse
     * vaudraient alors zéro point, en silence, et le commentaire donnerait raison à celui qui a
     * ajouté la colonne. Ce banc calcule sur `total` et le dira.
     */
    private function rattacher(Order $commande, string $code = 'RATTACH1', ?User $agent = null)
    {
        return $this->actingAs($agent ?? $this->caissier, 'sanctum')
            ->withHeader('X-Idempotency-Key', 'att-'.bin2hex(random_bytes(8)))
            ->postJson("/api/admin/pos-order/{$commande->id}/attach-loyalty", ['loyalty_code' => $code]);
    }

    // ── LE CŒUR ──────────────────────────────────────────────────────────────────────────────

    /**
     * LE CAS RÉEL : la vente est déjà servie (DELIVERED), le guetteur est passé à vide. Le
     * rattachement doit RELANCER le crédit, sinon le client repart avec zéro point.
     */
    public function test_sur_une_vente_deja_servie_le_rattachement_CREDITE_vraiment(): void
    {
        $commande = $this->vente(OrderStatus::DELIVERED, 24.00);
        $this->assertNull($commande->loyalty_points_awarded, 'départ : aucun point crédité');

        $r = $this->rattacher($commande)->assertOk();

        // 24 € × 10 points/€ = 240 points, le barème de la maison.
        $this->assertSame(240, $r->json('data.points_awarded'),
            'le rattachement n\'a rien crédité : le client verrait « c\'est noté » et zéro point');
        $this->assertSame(240, (int) $this->client->fresh()->loyalty_points);
        $this->assertSame('RATTACH1', $commande->fresh()->loyalty_customer_code);
        $this->assertSame(240, (int) $commande->fresh()->loyalty_points_awarded);

        // Et le grand-livre porte la ligne : sans elle, le solde est un chiffre sans histoire.
        $ligne = DB::table('loyalty_transactions')->where('order_id', $commande->id)->first();
        $this->assertNotNull($ligne, 'aucune ligne au grand-livre pour ce gain');
        $this->assertSame('earn', $ligne->type);
        $this->assertSame(240, (int) $ligne->points);
        $this->assertSame('pos', $ligne->source_surface);
    }

    /**
     * ET L'ÉCRAN SAIT CE QU'IL A FAIT. La réponse porte le solde à jour, pour que le caissier puisse
     * l'annoncer sans relancer une recherche.
     */
    public function test_la_reponse_porte_le_solde_a_jour(): void
    {
        $commande = $this->vente(OrderStatus::DELIVERED, 24.00);

        $c = $this->rattacher($commande)->assertOk()->json('data.customer');

        $this->assertSame(240, $c['balance']);
        $this->assertSame(2.40, $c['balance_eur']);
        $this->assertFalse($c['can_use'], 'sous le seuil de 1000, rien n\'est encore utilisable');
        $this->assertSame(760, $c['missing_points'], 'et on dit ce qui manque');
    }

    /**
     * UN DOUBLE APPUI NE CRÉDITE PAS DEUX FOIS. La garde atomique du guetteur
     * (`orders.loyalty_points_awarded`) tient, et le second appel rend 0 point de plus.
     */
    public function test_un_second_rattachement_ne_credite_pas_une_seconde_fois(): void
    {
        $commande = $this->vente(OrderStatus::DELIVERED, 24.00);

        $this->rattacher($commande)->assertOk();
        $second = $this->rattacher($commande)->assertOk();

        $this->assertSame(0, $second->json('data.points_awarded'), 'second crédit pour la même vente');
        $this->assertSame(240, (int) $this->client->fresh()->loyalty_points);
        $this->assertSame(1, DB::table('loyalty_transactions')->where('order_id', $commande->id)->count());
    }

    /**
     * ON RELANCE LE GUETTEUR DES POINTS, PAS L'ÉVÉNEMENT. Rediffuser `OrderStatusChanged` ferait
     * ressortir un SECOND ticket cuisine (`AutoPrintKitchenTicketOnKitchenEntry`) et une seconde
     * notification pour une vente déjà servie. Le client verrait sa commande repartir en cuisine.
     */
    public function test_le_rattachement_ne_rejoue_ni_ticket_cuisine_ni_notification(): void
    {
        \Illuminate\Support\Facades\Event::fake([\App\Events\OrderStatusChanged::class]);

        $commande = $this->vente(OrderStatus::DELIVERED, 24.00);
        $this->rattacher($commande)->assertOk();

        \Illuminate\Support\Facades\Event::assertNotDispatched(\App\Events\OrderStatusChanged::class,
            'l\'événement a été rediffusé : ticket cuisine et notification vont ressortir');
    }

    // ── QUAND LE GUETTEUR N'EST PAS ENCORE PASSÉ ─────────────────────────────────────────────

    /**
     * Sur une commande encore en cuisine, on ne devance PAS le crédit : créditer maintenant, ce
     * serait donner des points pour une vente qui peut encore être annulée. Le guetteur créditera
     * par la voie normale, et la réponse dit 0 — l'écran doit annoncer « c'est rattaché », pas
     * « voici vos points ».
     */
    public function test_sur_une_commande_encore_en_cuisine_on_ne_devance_pas_le_credit(): void
    {
        $commande = $this->vente(OrderStatus::PREPARING, 24.00);

        $r = $this->rattacher($commande)->assertOk();

        $this->assertSame(0, $r->json('data.points_awarded'));
        $this->assertSame(0, (int) $this->client->fresh()->loyalty_points);
        $this->assertSame('RATTACH1', $commande->fresh()->loyalty_customer_code,
            'le rattachement doit être acquis, même sans crédit immédiat');
    }

    // ── LES REFUS ────────────────────────────────────────────────────────────────────────────

    /** Une vente annulée ou remboursée ne fait gagner aucun point : ne pas le promettre. */
    public function test_une_vente_annulee_ou_remboursee_est_refusee(): void
    {
        foreach ([OrderStatus::CANCELED, OrderStatus::REJECTED, OrderStatus::RETURNED] as $mort) {
            $commande = $this->vente($mort, 24.00);

            $r = $this->rattacher($commande)->assertStatus(422);
            $this->assertSame('ORDER_TERMINAL', $r->json('code'));
            $this->assertNull($commande->fresh()->loyalty_customer_code);
        }

        $this->assertSame(0, (int) $this->client->fresh()->loyalty_points);
    }

    /**
     * DÉJÀ AU NOM DE QUELQU'UN D'AUTRE : on refuse. Réécrire le code déplacerait les points d'un
     * humain vers un autre, et si le crédit a déjà eu lieu, le premier ne les rendrait pas.
     */
    public function test_une_vente_deja_au_nom_d_un_autre_client_n_est_pas_reecrite(): void
    {
        $autre = User::factory()->create(['phone' => '0633445566', 'is_guest' => Ask::YES]);
        DB::table('users')->where('id', $autre->id)->update(['loyalty_code' => 'AUTRE001', 'loyalty_points' => 0]);

        $commande = $this->vente(OrderStatus::DELIVERED, 24.00, ['loyalty_customer_code' => 'AUTRE001']);

        $r = $this->rattacher($commande, 'RATTACH1')->assertStatus(422);

        $this->assertSame('ALREADY_ATTACHED_OTHER', $r->json('code'));
        $this->assertStringContainsString('responsable', $r->json('message'));
        $this->assertSame('AUTRE001', $commande->fresh()->loyalty_customer_code);
        $this->assertSame(0, (int) $this->client->fresh()->loyalty_points);
    }

    /** Le MÊME client rattaché deux fois n'est pas un conflit : c'est un caissier qui recommence. */
    public function test_rattacher_le_meme_client_deux_fois_n_est_pas_un_conflit(): void
    {
        $commande = $this->vente(OrderStatus::DELIVERED, 24.00, ['loyalty_customer_code' => 'RATTACH1']);

        $this->rattacher($commande, 'RATTACH1')->assertOk();
    }

    /** Un code inconnu ne rattache rien. */
    public function test_un_code_inconnu_ne_rattache_rien(): void
    {
        $commande = $this->vente(OrderStatus::DELIVERED, 24.00);

        $r = $this->rattacher($commande, 'INCONNU9')->assertStatus(422);
        $this->assertSame('NO_ACCOUNT', $r->json('code'));
        $this->assertNull($commande->fresh()->loyalty_customer_code);
    }

    /** Le code d'un membre de l'ÉQUIPE non plus : un caissier ne se crédite pas lui-même. */
    public function test_le_code_d_un_membre_de_l_equipe_ne_rattache_rien(): void
    {
        $collegue = User::factory()->create(['phone' => '0699000333', 'branch_id' => $this->branche->id]);
        $collegue->assignRole('POS Operator');
        DB::table('users')->where('id', $collegue->id)
            ->update(['loyalty_code' => 'STAFF09', 'loyalty_points' => 0]);

        $commande = $this->vente(OrderStatus::DELIVERED, 24.00);

        $this->rattacher($commande, 'STAFF09')->assertStatus(422);
        $this->assertSame(0, (int) $collegue->fresh()->loyalty_points);
    }

    // ── LA PORTE ─────────────────────────────────────────────────────────────────────────────

    /**
     * UN CAISSIER D'UNE AUTRE CAISSE NE RATTACHE PAS. La permission Spatie est GLOBALE par
     * utilisateur, pas liée à une caisse : sans contrôle après lecture, un caissier de la caisse 5
     * créditerait un client sur une vente de la caisse 3.
     */
    public function test_un_caissier_d_une_autre_caisse_est_refuse(): void
    {
        $autreBranche = Branch::factory()->create();
        $intrus = User::factory()->create(['branch_id' => $autreBranche->id, 'phone' => '0100000004']);
        $intrus->assignRole('POS Operator');

        $commande = $this->vente(OrderStatus::DELIVERED, 24.00);

        $this->rattacher($commande, 'RATTACH1', $intrus)->assertStatus(403);
        $this->assertNull($commande->fresh()->loyalty_customer_code);
        $this->assertSame(0, (int) $this->client->fresh()->loyalty_points);
    }

    /** Sans le droit caisse, personne ne rattache. */
    public function test_sans_le_droit_caisse_personne_ne_rattache(): void
    {
        $commande = $this->vente(OrderStatus::DELIVERED, 24.00);

        $this->postJson("/api/admin/pos-order/{$commande->id}/attach-loyalty", ['loyalty_code' => 'RATTACH1'])
            ->assertStatus(401);

        // ATTENTION : ce quidam doit porter LA MÊME caisse que la commande. Sinon c'est le contrôle
        // de caisse qui renvoie 403, et la garde de PERMISSION n'est jamais éprouvée — une porte
        // grande ouverte passerait inaperçue derrière la seconde. Et le cas est réel : 1 des 151
        // comptes fidélité de la base porte `branch_id = 1`, la caisse du restaurant.
        $quidam = User::factory()->create(['is_guest' => Ask::NO, 'branch_id' => $this->branche->id]);
        $quidam->assignRole('Customer');
        $this->rattacher($commande, 'RATTACH1', $quidam)->assertStatus(403);

        $this->assertSame(0, (int) $this->client->fresh()->loyalty_points);
        $this->assertNull($commande->fresh()->loyalty_customer_code);
    }

    /** Une commande introuvable rend 404, pas une erreur de programme. */
    public function test_une_commande_introuvable_rend_404(): void
    {
        $this->actingAs($this->caissier, 'sanctum')
            ->withHeader('X-Idempotency-Key', 'att-'.bin2hex(random_bytes(8)))
            ->postJson('/api/admin/pos-order/999999/attach-loyalty', ['loyalty_code' => 'RATTACH1'])
            ->assertStatus(404);
    }
}
