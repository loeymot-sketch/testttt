<?php

namespace Tests\Feature\Pos;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [WEB-PAYEE-MUETTE 2026-08-10 · P0 owner] La file des tickets cuisine réclamée par le PC caisse.
 *
 * CE QUE CETTE FILE RÉPARE
 * ------------------------
 * L'impression serveur→imprimante n'a JAMAIS fonctionné en production : le serveur est chez
 * l'hébergeur, l'imprimante au bout du réseau du restaurant. Constat du 2026-08-10 : table
 * `printers` vide, `printOnce()` sort en `no_printer`, zéro ligne de journal depuis l'origine.
 * La commande #440 (31,40 € encaissés) n'a produit aucun papier.
 *
 * LES DEUX FAÇONS DE SE TROMPER ICI, ET LEUR COÛT
 * ----------------------------------------------
 *  - Réclamer trop : le premier sondage vide l'historique entier sur le rouleau (toutes les
 *    commandes passées ont `kitchen_ticket_printed_at` à NULL). D'où la fenêtre bornée.
 *  - Réclamer puis perdre : une commande marquée « imprimée » dont aucun papier n'est sorti
 *    est le pire des deux mondes — la cuisine ne l'a pas et plus rien ne la lui donnera.
 *    D'où l'accusé de réception qui remet en file.
 */
class KitchenTicketQueueTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->branch = Branch::factory()->create();
        $this->cashier = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->cashier->assignRole('POS Operator');
    }

    private function commandeWeb(array $overrides = []): Order
    {
        return Order::factory()->create(array_merge([
            'branch_id'                 => $this->branch->id,
            'source_surface'            => 'web',
            'order_type'                => OrderType::TAKEAWAY,
            'status'                    => OrderStatus::PREPARING,
            'payment_status'            => PaymentStatus::PAID,
            'payment_method'            => PaymentGateway::CARD,
            'kitchen_ticket_printed_at' => null,
        ], $overrides));
    }

    private function reclamer(): array
    {
        $res = $this->actingAs($this->cashier, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos/kitchen-tickets/pending');
        $res->assertOk();

        return collect($res->json('orders'))->pluck('id')->all();
    }

    private function accuser(int $orderId, bool $success, ?string $error = null): void
    {
        $this->actingAs($this->cashier, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->postJson("/api/admin/pos/kitchen-tickets/{$orderId}/ack", [
                'success' => $success,
                'error'   => $error,
            ])->assertOk();
    }

    /** @test */
    public function une_commande_web_payee_est_reclamee_pour_impression(): void
    {
        $web = $this->commandeWeb();

        $this->assertContains($web->id, $this->reclamer());
    }

    /** @test La garde « une seule fois » : deux onglets caisse ne sortent pas le même ticket. */
    public function une_commande_deja_reclamee_ne_ressort_pas_au_sondage_suivant(): void
    {
        $web = $this->commandeWeb();

        $this->assertContains($web->id, $this->reclamer(), 'premier sondage : le ticket doit sortir');
        $this->assertNotContains($web->id, $this->reclamer(), 'second sondage : le ticket ne doit PAS sortir deux fois');

        $this->assertNotNull(
            Order::withoutGlobalScopes()->find($web->id)->kitchen_ticket_printed_at,
            'la réclamation doit être inscrite en base — c\'est elle qui arbitre entre deux postes'
        );
    }

    /** @test Le filet : si le papier n'est pas sorti, la commande RETOURNE en file. */
    public function un_echec_d_impression_remet_la_commande_en_file(): void
    {
        $web = $this->commandeWeb();
        $this->reclamer();

        $this->accuser($web->id, false, 'Pont d\'impression indisponible');

        $this->assertNull(
            Order::withoutGlobalScopes()->find($web->id)->kitchen_ticket_printed_at,
            'un échec doit effacer la réclamation, sinon le ticket est perdu pour toujours'
        );
        $this->assertContains($web->id, $this->reclamer(), 'la commande doit être re-proposée après un échec');
    }

    /** @test */
    public function un_succes_laisse_la_commande_hors_de_la_file(): void
    {
        $web = $this->commandeWeb();
        $this->reclamer();

        $this->accuser($web->id, true);

        $this->assertNotNull(Order::withoutGlobalScopes()->find($web->id)->kitchen_ticket_printed_at);
        $this->assertNotContains($web->id, $this->reclamer());
    }

    /** @test Le garde-fou anti-rouleau : sans lui, la première mise en service vide l'historique. */
    public function une_commande_plus_vieille_que_la_fenetre_n_est_jamais_reclamee(): void
    {
        $vieille = $this->commandeWeb([
            'created_at' => now()->subMinutes((int) config('kds.bridge_print_window_minutes', 30) + 5),
        ]);

        $this->assertNotContains($vieille->id, $this->reclamer());
    }

    /** @test Caisse et téléphone impriment déjà au checkout — les inclure doublerait le papier. */
    public function les_ventes_caisse_et_telephone_ne_sont_pas_reclamees(): void
    {
        $caisse = $this->commandeWeb(['source_surface' => 'pos']);
        $tel    = $this->commandeWeb(['source_surface' => 'phone']);

        $ids = $this->reclamer();

        $this->assertNotContains($caisse->id, $ids);
        $this->assertNotContains($tel->id, $ids);
    }

    /** @test */
    public function une_commande_dont_le_paiement_est_en_vol_ne_produit_pas_de_papier(): void
    {
        $enVol = $this->commandeWeb([
            'status'         => OrderStatus::PENDING,
            'payment_status' => PaymentStatus::UNPAID,
        ]);

        $this->assertNotContains($enVol->id, $this->reclamer());
    }

    /** @test La borne, elle, doit continuer d'imprimer — c'est le comportement historique. */
    public function une_commande_borne_relachee_est_reclamee(): void
    {
        $borne = $this->commandeWeb([
            'source_surface' => 'kiosk',
            'order_type'     => OrderType::KIOSK,
            'payment_status' => PaymentStatus::PENDING_COUNTER,
            'status'         => OrderStatus::ACCEPT,
        ]);

        $this->assertContains($borne->id, $this->reclamer());
    }

    /** @test */
    public function isolation_branche_un_poste_ne_reclame_que_ses_commandes(): void
    {
        $autre = $this->commandeWeb(['branch_id' => Branch::factory()->create()->id]);

        $this->assertNotContains($autre->id, $this->reclamer());
    }

    /** @test Un compte sans droit caisse ni cuisine ne doit pas pouvoir vider la file. */
    public function un_compte_sans_droit_est_refuse(): void
    {
        $quidam = User::factory()->create(['branch_id' => $this->branch->id]);

        $this->actingAs($quidam, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos/kitchen-tickets/pending')
            ->assertForbidden();
    }
}
