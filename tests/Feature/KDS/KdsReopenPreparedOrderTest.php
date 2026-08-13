<?php

namespace Tests\Feature\KDS;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Events\OrderStatusChanged;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderStatusTransition;
use App\Models\User;
use App\Services\KitchenDisplaySystemOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * « ROUVRIR UNE COMMANDE » — LE COMPLÉMENT, PAS LE DOUBLON.
 *
 * ── LIRE D'ABORD : L'ESSENTIEL EST TESTÉ AILLEURS ────────────────────────────────────────────
 * `tests/Feature/Kitchen/KdsReopenOrderTest.php` couvre DÉJÀ, par la route et en vert : le retour
 * en préparation, l'effacement de `prepared_at`, l'inscription au registre, l'absence de fenêtre de
 * 60 s, les refus (commande remise / déjà en préparation / annulée), l'isolation de succursale, et
 * le fait que `recall()` ne touche toujours pas au statut. RIEN de cela n'est repris ici.
 *
 * ⚠️ POURQUOI CE COMMENTAIRE EXISTE : j'avais d'abord écrit un banc de 8 tests en croyant cette
 * action non couverte, et il recoupait le leur à 60 %. La cause était une faute de MA méthode — mon
 * relevé `grep -rln reopen tests/ | head -4` avait TRONQUÉ la sortie, et leur fichier tombait juste
 * en dessous. Un inventaire tronqué produit exactement la conclusion « ce n'est pas couvert » qu'on
 * croyait vérifier. Avant d'affirmer qu'une chose n'a pas de test : compter, sans `head`.
 *
 * ── CE QUE CE BANC AJOUTE, ET QUE RIEN N'ÉPROUVAIT ───────────────────────────────────────────
 * Quatre angles absents de leur banc, parce qu'ils ne portent pas sur `reopen()` mais sur ce que son
 * aller-retour réveille AILLEURS, ou sur ce qu'il laisse voir :
 *
 * 1. Le SECOND TICKET CUISINE : `PREPARING` déclenche l'impression automatique, et
 *    `OrderStatusChanged` porte QUATRE auditeurs. Rouvrir peut-il refaire sortir un ticket ?
 * 2. Le DOUBLE CRÉDIT : `PREPARED` crédite les points d'une commande à emporter. Rouvrir puis
 *    re-valider peut-il payer le client deux fois ?
 * 3. QUI a rouvert : leur banc vérifie la ligne de registre, pas son `actor_id`. Sans nom, la trace
 *    ne sert à rien le lendemain.
 * 4. Les DEUX STATUTS qu'ils ne couvrent pas — REJETÉE et PARTIE EN LIVRAISON. Le second est le
 *    risque concret : un plat déjà chez le livreur qui repart en cuisine.
 * 5. Ce que le refus MONTRE : leur banc accepte 403 ou 404 sans regarder le corps. Ici on vérifie
 *    qu'aucun détail interne (message anglais, chemin de classe) n'atteint l'écran de la cuisine.
 */
class KdsReopenPreparedOrderTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branche;

    private User $chef;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        Config::set('fiscal.audit_secret', 'test-fiscal-secret-'.str_repeat('k', 40));
        Settings::group('loyalty_setup')->set(['loyalty_points_per_euro' => 10]);

        $this->branche = Branch::factory()->create();
        $this->chef = User::factory()->create(['branch_id' => $this->branche->id, 'phone' => '0100000777']);
        $this->chef->assignRole('Chef');
        $this->actingAs($this->chef, 'sanctum');
    }

    private function commande(int $statut, array $extra = []): Order
    {
        return Order::factory()->create(array_merge([
            'branch_id'          => $this->branche->id,
            'subtotal'           => 20.00, 'discount' => 0.00, 'total_tax' => 0.00,
            'delivery_charge'    => 0.00, 'total' => 20.00,
            'status'             => $statut,
            'payment_status'     => PaymentStatus::PAID,
            'payment_method'     => PaymentGateway::CASH_ON_DELIVERY,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'order_type'         => OrderType::TAKEAWAY,
            'source_surface'     => 'pos',
            'prepared_at'        => now(),
        ], $extra));
    }

    private function rouvrir(Order $o): array
    {
        return app(KitchenDisplaySystemOrderService::class)->reopen($o->fresh());
    }

    /**
     * 1 + 3. LE SECOND TICKET CUISINE QUI NE SORT PAS — ET LE NOM DE QUI A ROUVERT.
     *
     * `reopen()` ne diffuse aucun `OrderStatusChanged`, et c'est un choix qui PROTÈGE : cet événement
     * porte l'outbox, l'impression automatique du ticket cuisine, le crédit des points et la
     * notification au client. Le diffuser ici ferait ressortir un ticket pour un plat déjà en cours.
     *
     * ⛔ Ne pas « réparer » cette absence sans lire ceci : les surfaces (caisse, écran client) lisent
     * le statut EN DIRECT à chaque sondage — il n'y a pas d'état périmé qu'un événement viendrait
     * rattraper. On y gagnerait un doublon, rien d'autre.
     */
    public function test_rouvrir_ne_rejoue_pas_le_ticket_cuisine_et_nomme_son_auteur(): void
    {
        Event::fake([OrderStatusChanged::class]);

        $o = $this->commande(OrderStatus::PREPARED);
        $this->rouvrir($o);

        // Sans ceci le banc serait creux : il faut prouver que la réouverture a bien eu lieu avant
        // d'affirmer que l'événement n'a pas été diffusé.
        $this->assertSame(OrderStatus::PREPARING, (int) $o->fresh()->status);

        Event::assertNotDispatched(OrderStatusChanged::class,
            'l\'événement a été diffusé : un second ticket cuisine va sortir pour le même plat');

        $t = OrderStatusTransition::query()->where('order_id', $o->id)
            ->where('reason', 'kitchen_reopen')->latest('id')->first();
        $this->assertNotNull($t);
        $this->assertSame((int) $this->chef->id, (int) $t->actor_id,
            'la trace ne dit pas QUI a rouvert : inutile le lendemain');
    }

    /**
     * 2. LE CLIENT N'EST PAS PAYÉ DEUX FOIS.
     *
     * `PREPARED` crédite les points d'une commande à emporter. Un aller-retour complet
     * (prêt → rouvert → prêt) ne doit pas doubler le solde. Ce banc l'éprouve BOUT EN BOUT au lieu
     * de le supposer — et la campagne de mutation a corrigé ce que je croyais savoir.
     *
     * ── CE QUI PROTÈGE RÉELLEMENT : DEUX COUCHES, PAS UNE ────────────────────────────────────
     * J'avais écrit ici que « la sentinelle atomique `orders.loyalty_points_awarded` est ce qui
     * tient ». C'est incomplet. Mesuré, en désarmant les couches une à une :
     *
     *  1. **La sentinelle atomique** (`whereNull('loyalty_points_awarded')` + `if ($updated === 0)`)
     *     arrête le 2ᵉ passage AVANT tout calcul. Désarmée seule → toujours pas de double crédit.
     *  2. **L'index UNIQUE `loyalty_transactions (user_id, order_id, type)`**, et surtout le fait que
     *     l'incrément du solde soit DANS la même `DB::transaction` que l'écriture au grand-livre :
     *     la collision annule la transaction, donc rembobine le solde. Désarmée seule → toujours
     *     pas de double crédit.
     *
     * Chacune suffit ; il faut perdre LES DEUX pour payer deux fois (mutation vérifiée : solde à
     * 400 au lieu de 200). ⚠️ La fragilité concrète à ne pas introduire : sortir
     * `increment('loyalty_points')` de la `DB::transaction`. L'index continuerait de refuser la
     * ligne, mais ne rembobinerait plus le solde — un solde qui bouge sans ligne au grand-livre,
     * exactement le défaut trouvé le même jour dans `WheelDeliveryService`.
     *
     * ── ET UN PIÈGE DE MÉTHODE, POUR LA PROCHAINE FOIS ───────────────────────────────────────
     * Ma première mutation « contourner la clé d'unicité » changeait `type` de `earn` à `manual_add`
     * — donc sur LES DEUX passages, laissant le tuple identique. Mutation ÉQUIVALENTE : elle ne
     * prouvait rien, et son « ❌ SURVIT » ressemblait à un test creux. Une mutation qui modifie la
     * même valeur des deux côtés d'une comparaison ne teste rien.
     */
    public function test_rouvrir_puis_revalider_ne_credite_pas_les_points_deux_fois(): void
    {
        $client = User::factory()->create(['phone' => '0644009900', 'is_guest' => \App\Enums\Ask::YES]);
        DB::table('users')->where('id', $client->id)
            ->update(['loyalty_code' => 'KDSREOP1', 'loyalty_points' => 0]);

        $o = $this->commande(OrderStatus::PREPARED, ['loyalty_customer_code' => 'KDSREOP1']);

        // 1ʳᵉ validation : la cuisine dit « prêt » → le crédit part par la voie normale.
        app(\App\Listeners\AwardLoyaltyPointsOnDelivery::class)->handle(
            new OrderStatusChanged($o->fresh(), OrderStatus::PREPARING, OrderStatus::PREPARED)
        );
        $this->assertSame(200, (int) $client->fresh()->loyalty_points, '20 € × 10 points/€');

        // On rouvre, puis la cuisine re-valide.
        $this->rouvrir($o);
        DB::table('orders')->where('id', $o->id)->update(['status' => OrderStatus::PREPARED]);
        app(\App\Listeners\AwardLoyaltyPointsOnDelivery::class)->handle(
            new OrderStatusChanged($o->fresh(), OrderStatus::PREPARING, OrderStatus::PREPARED)
        );

        $this->assertSame(200, (int) $client->fresh()->loyalty_points,
            'le client a été payé deux fois pour la même commande');
        $this->assertSame(1, (int) DB::table('loyalty_transactions')->where('order_id', $o->id)->count(),
            'deux lignes au grand-livre pour un seul gain');
    }

    /**
     * 4. LES DEUX STATUTS QUE L'AUTRE BANC NE COUVRE PAS.
     *
     * Il éprouve REMISE, EN PRÉPARATION et ANNULÉE. Restent REJETÉE et PARTIE EN LIVRAISON — le
     * second est le vrai risque : le plat est chez le livreur, la cuisine ne doit pas le refaire.
     */
    public function test_une_commande_rejetee_ou_partie_en_livraison_ne_se_rouvre_pas(): void
    {
        foreach ([OrderStatus::REJECTED, OrderStatus::OUT_FOR_DELIVERY] as $statut) {
            $o = $this->commande($statut);

            $this->postJson("/api/admin/kds-order/reopen/{$o->id}")->assertStatus(422);

            $this->assertSame($statut, (int) $o->fresh()->status,
                "statut {$statut} : la commande a bougé malgré le refus");
        }
    }

    /**
     * 5. LE REFUS NE FUIT RIEN À L'ÉCRAN DE LA CUISINE.
     *
     * ── CE QUE CE TEST A CORRIGÉ CHEZ MOI ────────────────────────────────────────────────────
     * J'avais annoncé que le message interne anglais (« No query results for model … ») remontait à
     * la cuisine pour une commande d'une autre caisse. C'est FAUX, et c'est ce test qui me l'a montré :
     * `Order` porte `BranchScope`, donc la LIAISON IMPLICITE de modèle échoue AVANT le contrôleur et
     * renvoie un 404 standard qui ne dit rien. Le filet français que j'ai posé dans le contrôleur ne
     * couvre qu'une fenêtre étroite (la ligne disparaît entre la liaison et la relecture sous verrou)
     * — et le `abort(403)` « autre succursale » de `reopen()` est, lui, inatteignable.
     *
     * Le test reste : il verrouille le fait que rien d'interne ne s'affiche, quel que soit le chemin.
     */
    public function test_le_refus_d_une_autre_caisse_ne_fuit_aucun_detail_interne(): void
    {
        $autre = Branch::factory()->create();
        $o = $this->commande(OrderStatus::PREPARED, ['branch_id' => $autre->id]);

        $r = $this->postJson("/api/admin/kds-order/reopen/{$o->id}")->assertStatus(404);

        $corps = (string) $r->getContent();
        $this->assertStringNotContainsString('No query results', $corps,
            'un message interne en anglais atteint l\'écran de la cuisine');
        $this->assertStringNotContainsString('App\\Models', $corps,
            'un chemin de classe interne est affiché à la cuisine');

        $this->assertSame(OrderStatus::PREPARED, (int) $o->fresh()->status,
            'la commande d\'une autre caisse a bougé');
    }
}
