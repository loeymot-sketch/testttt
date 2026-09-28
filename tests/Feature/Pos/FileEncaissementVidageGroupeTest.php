<?php

namespace Tests\Feature\Pos;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [CAISSE 2026-09-29 · demande propriétaire] Vidage groupé de la file
 * d'encaissement.
 *
 * Le besoin : « il y a une grande liste de commandes en attente parce que ça
 * fait plusieurs jours, des clients qui sont pas venus ; je veux commencer une
 * nouvelle journée, je veux tout supprimer. »
 *
 * Ce banc existe surtout pour les choses que le vidage NE DOIT PAS faire. Un
 * bouton « tout vider » est par nature le plus dangereux de l'écran : il agit
 * sur des lignes que le caissier ne relit pas une par une. Les cas négatifs
 * (service en cours épargné, commande fiscalisée intouchable, autre branche
 * épargnée) comptent donc plus que le cas nominal.
 */
class FileEncaissementVidageGroupeTest extends TestCase
{
    use RefreshDatabase;

    /** Le cas nominal : les fantômes d'hier partent. */
    public function test_les_commandes_des_journees_passees_sont_annulees(): void
    {
        [$operator, $branch] = $this->caissier();
        $hier = $this->commandeTelephoneEnAttente($branch, now()->subDays(2));

        $reponse = $this->actingAs($operator, 'sanctum')
            ->postJson('/api/admin/pos/counter-collect/cancel-stale');

        $reponse->assertOk();
        $this->assertSame(1, $reponse->json('canceled'), $reponse->getContent());

        $hier->refresh();
        $this->assertSame(OrderStatus::CANCELED, (int) $hier->status);
        $this->assertNotSame(
            PaymentStatus::PENDING_COUNTER,
            (int) $hier->payment_status,
            'La commande doit quitter la file d\'encaissement.'
        );
        $this->assertNull(
            $hier->deleted_at,
            'On ANNULE, on ne supprime pas : Order::restoring() interdit tout retour '
            . 'en arrière et OrderService::destroy détruit les lignes filles. Une '
            . 'suppression groupée serait irrattrapable.'
        );
    }

    /**
     * LE CAS QUI COMPTE LE PLUS. Une commande du service EN COURS est un client
     * qui peut franchir la porte dans la minute. La toucher, c'est perdre une
     * vente et laisser un plat déjà parti en cuisine sans encaissement.
     */
    public function test_le_service_en_cours_n_est_jamais_touche(): void
    {
        [$operator, $branch] = $this->caissier();
        $aujourdhui = $this->commandeTelephoneEnAttente($branch, now());

        $reponse = $this->actingAs($operator, 'sanctum')
            ->postJson('/api/admin/pos/counter-collect/cancel-stale');

        $reponse->assertOk();
        $this->assertSame(0, $reponse->json('canceled'), $reponse->getContent());

        $aujourdhui->refresh();
        $this->assertSame(
            PaymentStatus::PENDING_COUNTER,
            (int) $aujourdhui->payment_status,
            'La commande du jour doit rester encaissable.'
        );
        $this->assertNotSame(OrderStatus::CANCELED, (int) $aujourdhui->status);
    }

    /**
     * Garde NF525. Une commande porteuse d'un numéro fiscal est entrée dans la
     * chaîne signée : on n'y touche jamais, quelle que soit sa date.
     */
    public function test_une_commande_fiscalisee_est_intouchable(): void
    {
        [$operator, $branch] = $this->caissier();
        $fiscalisee = $this->commandeTelephoneEnAttente($branch, now()->subDays(3));
        $fiscalisee->forceFill(['fiscal_sequence_no' => 4242])->saveQuietly();

        $reponse = $this->actingAs($operator, 'sanctum')
            ->postJson('/api/admin/pos/counter-collect/cancel-stale');

        $reponse->assertOk();
        $this->assertSame(0, $reponse->json('canceled'), $reponse->getContent());

        $fiscalisee->refresh();
        $this->assertSame(PaymentStatus::PENDING_COUNTER, (int) $fiscalisee->payment_status);
        $this->assertSame(4242, (int) $fiscalisee->fiscal_sequence_no);
    }

    /** Le vidage d'une caisse ne doit pas atteindre la file d'une autre branche. */
    public function test_le_vidage_reste_dans_sa_branche(): void
    {
        [$operator, $branch] = $this->caissier();
        $autreBranche = Branch::factory()->create();
        $voisine = $this->commandeTelephoneEnAttente($autreBranche, now()->subDays(2));

        $reponse = $this->actingAs($operator, 'sanctum')
            ->postJson('/api/admin/pos/counter-collect/cancel-stale');

        $reponse->assertOk();
        $this->assertSame(0, $reponse->json('canceled'), $reponse->getContent());

        $voisine->refresh();
        $this->assertSame(PaymentStatus::PENDING_COUNTER, (int) $voisine->payment_status);
    }

    /** `dry_run` doit compter sans rien changer — c'est ce qui alimente la confirmation chiffrée. */
    public function test_le_comptage_a_blanc_ne_change_rien(): void
    {
        [$operator, $branch] = $this->caissier();
        $this->commandeTelephoneEnAttente($branch, now()->subDays(2));
        $this->commandeTelephoneEnAttente($branch, now()->subDays(4));

        $reponse = $this->actingAs($operator, 'sanctum')
            ->postJson('/api/admin/pos/counter-collect/cancel-stale', ['dry_run' => true]);

        $reponse->assertOk();
        $this->assertSame(2, $reponse->json('count'));
        $this->assertSame(
            2,
            Order::where('payment_status', PaymentStatus::PENDING_COUNTER)->count(),
            'Un comptage à blanc qui modifie quoi que ce soit est un piège.'
        );
    }

    /**
     * ÉQUIVALENCE DES DEUX DÉFINITIONS. Le scope `counterCollectQueue` est une
     * transcription du prédicat de la route d'affichage, qui n'a délibérément pas
     * été réécrite. Si les deux divergent un jour, le vidage mordra sur des
     * commandes que le caissier ne voit pas — ce test est le seul filet.
     */
    public function test_le_scope_voit_exactement_ce_que_la_file_affiche(): void
    {
        [$operator, $branch] = $this->caissier();
        $this->commandeTelephoneEnAttente($branch, now()->subDays(2));
        $this->commandeTelephoneEnAttente($branch, now());

        $affichees = collect(
            $this->actingAs($operator, 'sanctum')
                ->getJson('/api/admin/pos/counter-collect/pending')
                ->assertOk()
                ->json('data')
        )->pluck('id')->sort()->values()->all();

        $vuesParLeScope = Order::query()
            ->counterCollectQueue()
            ->where('branch_id', $branch->id)
            ->pluck('id')->sort()->values()->all();

        $this->assertSame(
            $affichees,
            $vuesParLeScope,
            'Le scope et la route d\'affichage doivent désigner le MÊME ensemble.'
        );
    }

    private function caissier(): array
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $branch = Branch::factory()->create();
        $operator = User::factory()->create(['branch_id' => $branch->id]);
        $operator->assignRole('POS Operator');
        $operator->givePermissionTo('pos');

        return [$operator, $branch];
    }

    /** Une commande téléphone telle que la crée réellement la caisse (OrderService:786-792, 1284). */
    private function commandeTelephoneEnAttente(Branch $branch, $quand): Order
    {
        return Order::factory()->create([
            'branch_id' => $branch->id,
            'status' => OrderStatus::PREPARING,
            'payment_status' => PaymentStatus::PENDING_COUNTER,
            'pos_payment_method' => PosPaymentMethod::COUNTER_DEFERRED,
            'source_surface' => 'phone',
            'order_type' => OrderType::TAKEAWAY,
            'order_datetime' => $quand,
            'created_at' => $quand,
            'fiscal_sequence_no' => null,
        ]);
    }
}
