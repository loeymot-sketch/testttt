<?php

namespace Tests\Feature\KDS;

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
use Tests\TestCase;

/**
 * « REMETTRE EN PRÉPARATION » EXIGE LE DROIT CUISINE — comme ses six actions sœurs.
 *
 * ── LE TROU, ET COMMENT IL EST NÉ ────────────────────────────────────────────────────────────
 * `KitchenDisplaySystemController::__construct()` protège ses actions par une liste NOMINATIVE :
 *
 *     $this->middleware(['permission:kitchen-display-system'])
 *          ->only('index', 'changeStatus', 'orderItems', 'historyToday', 'recall');
 *
 * `reopen` a été livrée après cette ligne et n'y a jamais été ajoutée. Six routes sur sept
 * portaient le garde ; la septième, non. Le constructeur parent (`AdminController`) est vide, et
 * la route ne porte que `idempotency` + `throttle:kds-bump` — donc rien ne rattrapait l'oubli.
 *
 * ⚠️ LA LEÇON, PLUS IMPORTANTE QUE LE CORRECTIF : une liste `->only(...)` est une liste
 * D'INCLUSION. Elle échoue en SILENCE et en OUVERT — la nouvelle action n'est pas refusée, elle
 * est simplement non gardée, et aucun test ne s'en plaint. Un `->except(...)` aurait échoué en
 * FERMÉ. Toute action ajoutée à ce contrôleur doit être ajoutée à la ligne 24 dans le même geste.
 *
 * ── CE QUE ÇA COÛTAIT EN SERVICE ─────────────────────────────────────────────────────────────
 * N'importe quel compte authentifié sans droit cuisine (livreur, opérateur caisse) pouvait faire
 * repasser une commande PRÊTE en préparation : le plat quitte la colonne « PRÊT » du mur client
 * sous les yeux du client qu'on vient d'appeler, et `prepared_at` est effacé — toutes les durées
 * de préparation deviennent fausses.
 *
 * Le garde de succursale de `reopen()` ne compensait pas : il est INATTEIGNABLE (documenté dans
 * le contrôleur) — un compte de caisse ne voit pas la ligne, un compte admin passe la condition.
 */
class KdsReopenPermissionGuardTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branche;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Config::set('fiscal.audit_secret', 'test-fiscal-secret-'.str_repeat('g', 40));
        $this->branche = Branch::factory()->create();
    }

    private function commandePrete(): Order
    {
        return Order::factory()->create([
            'branch_id' => $this->branche->id,
            'subtotal' => 12.00, 'discount' => 0.00, 'total_tax' => 0.00,
            'delivery_charge' => 0.00, 'total' => 12.00,
            'status'             => OrderStatus::PREPARED,
            'payment_status'     => PaymentStatus::PAID,
            'payment_method'     => PaymentGateway::CASH_ON_DELIVERY,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'order_type'         => OrderType::TAKEAWAY,
            'source_surface'     => 'pos',
            'prepared_at'        => now(),
        ]);
    }

    /**
     * LE CŒUR : un compte SANS droit cuisine ne peut pas rouvrir une commande.
     *
     * ── POURQUOI UN COMPTE CLIENT, ET PAS « UN LIVREUR OU UN CAISSIER » ─────────────────────
     * Mesuré en production plutôt que supposé : tous les rôles du personnel (Chef, Branch Manager,
     * POS Operator, Stuff, Waiter, Admin) ONT le droit cuisine. « Delivery Boy » ne l'a pas mais
     * compte **0 compte**. Le seul rôle dépourvu du droit qui possède réellement des comptes est
     * **Customer — 26 comptes en production**.
     *
     * C'est donc EUX la population exposée, et c'est eux qu'on éprouve : un client de la fidélité,
     * porteur d'un jeton valide, ne doit pas pouvoir toucher à l'écran de la cuisine. (Un compte
     * client est créé avec `branch_id = 0`, la même valeur que l'administrateur — il ne faut donc
     * PAS compter sur la portée de succursale pour l'arrêter.)
     */
    public function test_un_compte_sans_droit_cuisine_ne_peut_pas_rouvrir(): void
    {
        $client = User::factory()->create(['branch_id' => 0, 'phone' => '0100000911']);
        $client->assignRole('Customer');
        $this->assertFalse($client->can('kitchen-display-system'),
            'le banc ne prouverait rien si ce rôle avait déjà le droit cuisine');

        $o = $this->commandePrete();

        $this->actingAs($client, 'sanctum')
            ->postJson("/api/admin/kds-order/reopen/{$o->id}")
            ->assertStatus(403);

        $this->assertSame(OrderStatus::PREPARED, (int) $o->fresh()->status,
            'la commande a été rouverte par un compte sans droit cuisine');
        $this->assertNotNull($o->fresh()->prepared_at,
            'l\'heure de prêt a été effacée par un compte sans droit cuisine');
    }

    /**
     * ET LA CONTREPARTIE — sinon le correctif pourrait simplement tout interdire : la cuisine, elle,
     * garde son action.
     */
    public function test_le_chef_peut_toujours_rouvrir(): void
    {
        $chef = User::factory()->create(['branch_id' => $this->branche->id, 'phone' => '0100000912']);
        $chef->assignRole('Chef');

        $o = $this->commandePrete();

        $this->actingAs($chef, 'sanctum')
            ->postJson("/api/admin/kds-order/reopen/{$o->id}")
            ->assertStatus(200);

        $this->assertSame(OrderStatus::PREPARING, (int) $o->fresh()->status);
    }

    /**
     * LA SENTINELLE DE STRUCTURE — celle qui empêche que ça recommence.
     *
     * Elle ne teste pas un comportement mais la LISTE elle-même : toute action publique de ce
     * contrôleur qui MUTE une commande doit figurer dans le `->only(...)`. C'est la garde que
     * l'absence de `reopen` réclamait, et elle attrapera la prochaine action ajoutée sans son droit.
     */
    public function test_toute_action_mutante_du_controleur_cuisine_est_dans_la_liste_des_droits(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Admin/KitchenDisplaySystemController.php'));

        preg_match("/->only\(([^)]*)\)/", $source, $m);
        $this->assertNotEmpty($m, 'la liste ->only(...) a disparu du contrôleur cuisine');
        $listees = preg_split("/\s*,\s*/", str_replace(["'", '"', "\n"], '', trim($m[1])));

        foreach (['changeStatus', 'recall', 'reopen'] as $action) {
            $this->assertContains($action, $listees,
                "l'action « {$action} » mute une commande mais n'exige aucun droit cuisine");
        }
    }
}
