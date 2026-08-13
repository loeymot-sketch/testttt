<?php

namespace Tests\Feature\Kitchen;

use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [REMETTRE-EN-PRÉPARATION 2026-08-13 · owner] « Au cas où je valide une commande alors qu'elle
 * n'est pas terminée. »
 *
 * CE QUI MANQUAIT
 * ---------------
 * Deux mécanismes existaient et aucun ne rendait ce service :
 *  - le bandeau « Annuler » de l'écran cuisine dure 3 SECONDES et annule seulement l'envoi ;
 *  - `recall()` dure 60 s et, par contrat verrouillé, NE TOUCHE PAS au statut — la commande reste
 *    PRÊTE avec un badge « RAPPELÉ ».
 *
 * Le cuisinier qui appuie sur « Prêt » trop tôt s'en aperçoit en regardant le PLAT, pas l'horloge :
 * une ou deux minutes plus tard. Passé ce délai, la commande partait au comptoir comme terminée —
 * client servi incomplet, ou client qui attend devant une commande que plus personne ne prépare.
 */
class KdsReopenOrderTest extends TestCase
{
    use RefreshDatabase;

    private User $chef;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->branch = Branch::factory()->create();
        $this->chef = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->chef->assignRole('Chef');
    }

    private function commande(array $overrides = []): Order
    {
        return Order::factory()->create(array_merge([
            'branch_id'   => $this->branch->id,
            'status'      => OrderStatus::PREPARED,
            'prepared_at' => now(),
        ], $overrides));
    }

    private function remettreEnPreparation(Order $order, ?User $acteur = null)
    {
        return $this->actingAs($acteur ?? $this->chef, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->withHeader('X-Idempotency-Key', 'reopen-'.$order->id.'-'.uniqid())
            ->postJson("/api/admin/kds-order/reopen/{$order->id}");
    }

    /** @test */
    public function une_commande_validee_trop_tot_redevient_en_preparation(): void
    {
        $order = $this->commande();

        $this->remettreEnPreparation($order)->assertOk();

        $this->assertSame(
            OrderStatus::PREPARING,
            (int) Order::withoutGlobalScopes()->find($order->id)->status,
            'la commande doit REDEVENIR en préparation — c\'est tout l\'objet de l\'action'
        );
    }

    /**
     * @test
     * L'heure de « prêt » doit être effacée : elle n'était pas vraie.
     *
     * La laisser en place ferait mentir tous les calculs de durée de préparation, et l'écran
     * client continuerait d'annoncer une commande prête depuis dix minutes alors qu'on est en
     * train de la refaire.
     */
    public function l_heure_de_pret_mensongere_est_effacee(): void
    {
        $order = $this->commande(['prepared_at' => now()->subMinutes(3)]);

        $this->remettreEnPreparation($order)->assertOk();

        $this->assertNull(
            Order::withoutGlobalScopes()->find($order->id)->prepared_at,
            'une commande remise en préparation n\'a plus d\'heure de fin'
        );
    }

    /** @test Le fait doit rester lisible après coup — registre append-only. */
    public function la_remise_en_preparation_est_inscrite_au_registre(): void
    {
        $order = $this->commande();

        $this->remettreEnPreparation($order)->assertOk();

        $this->assertDatabaseHas('order_status_transitions', [
            'order_id'    => $order->id,
            'from_status' => OrderStatus::PREPARED,
            'to_status'   => OrderStatus::PREPARING,
            'reason'      => 'kitchen_reopen',
        ]);
    }

    /**
     * @test
     * PAS de fenêtre de temps — c'est la raison d'être de cette action.
     *
     * `recall()` expire au bout de 60 s. Si celle-ci expirait aussi, elle ne servirait à rien :
     * on s'aperçoit d'un « Prêt » prématuré en regardant le plat, pas le chronomètre.
     */
    public function elle_fonctionne_encore_bien_apres_la_fenetre_de_soixante_secondes(): void
    {
        $order = $this->commande(['prepared_at' => now()]);

        $this->travel(15)->minutes();

        $this->remettreEnPreparation($order)->assertOk();

        $this->assertSame(
            OrderStatus::PREPARING,
            (int) Order::withoutGlobalScopes()->find($order->id)->status
        );
    }

    /**
     * @test
     * Une commande DÉJÀ REMISE au client ne se rouvre pas.
     *
     * Elle n'est plus l'affaire de la cuisine : la rouvrir ferait refaire un plat pour quelqu'un
     * qui est parti.
     */
    public function une_commande_deja_remise_ne_se_rouvre_pas(): void
    {
        $order = $this->commande(['status' => OrderStatus::DELIVERED]);

        $this->remettreEnPreparation($order)->assertStatus(422);

        $this->assertSame(
            OrderStatus::DELIVERED,
            (int) Order::withoutGlobalScopes()->find($order->id)->status
        );
    }

    /** @test Une commande encore en préparation n'a rien à rouvrir. */
    public function une_commande_deja_en_preparation_est_refusee(): void
    {
        $order = $this->commande(['status' => OrderStatus::PREPARING, 'prepared_at' => null]);

        $this->remettreEnPreparation($order)->assertStatus(422);
    }

    /** @test Une commande annulée ne repart pas en cuisine. */
    public function une_commande_annulee_est_refusee(): void
    {
        $order = $this->commande(['status' => OrderStatus::CANCELED]);

        $this->remettreEnPreparation($order)->assertStatus(422);
    }

    /** @test Isolation de branche : on ne rouvre pas la commande du voisin. */
    public function la_commande_d_une_autre_branche_est_refusee(): void
    {
        $autre = Branch::factory()->create();
        $order = $this->commande(['branch_id' => $autre->id]);

        $reponse = $this->remettreEnPreparation($order);

        $this->assertContains(
            $reponse->getStatusCode(),
            [403, 404],
            'une commande d\'une autre succursale ne doit jamais être rouverte'
        );
        $this->assertSame(
            OrderStatus::PREPARED,
            (int) Order::withoutGlobalScopes()->find($order->id)->status
        );
    }

    /**
     * @test
     * Le contrat de `recall()` reste intact : lui ne touche JAMAIS au statut.
     *
     * On ajoute une action, on n'en abîme pas une autre. Sans ce test, élargir `reopen` un jour
     * pourrait faire dériver `recall` vers la même chose et détruire sa garantie de traçabilité.
     */
    public function le_rappel_historique_ne_change_toujours_pas_le_statut(): void
    {
        $order = $this->commande(['updated_at' => now()]);

        $this->actingAs($this->chef, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->withHeader('X-Idempotency-Key', 'recall-'.$order->id)
            ->postJson("/api/admin/kds-order/recall/{$order->id}");

        $this->assertSame(
            OrderStatus::PREPARED,
            (int) Order::withoutGlobalScopes()->find($order->id)->status,
            'le rappel historique doit rester une action compensatoire, sans effet sur le statut'
        );
        $this->assertDatabaseHas('order_status_transitions', [
            'order_id' => $order->id,
            'reason'   => 'kitchen_recall',
        ]);
    }
}
