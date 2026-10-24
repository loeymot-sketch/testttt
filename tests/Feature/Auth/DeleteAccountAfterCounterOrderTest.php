<?php

namespace Tests\Feature\Auth;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * [GOAL STORES T-3.1.3 · 2026-09-30] Un client peut supprimer son compte même s'il a
 * laissé une commande « payer sur place » que personne n'a jamais commencée.
 *
 * AVANT : `DeactivateController` refusait (422 « commande active ») dès qu'une commande
 * n'était ni livrée ni annulée — y compris une commande PENDING jamais acceptée par la
 * cuisine, jamais payée. Or c'est exactement ce que fait un examinateur Apple ou Google :
 * il commande « sur place » pour tester, ne vient jamais retirer, puis essaie de supprimer
 * son compte — et se voit refuser (Apple 5.1.1(v), Play « Suppression du compte »).
 *
 * Le test existant `AccountDeletionTest::la_suppression_est_refusee_tant_qu_une_commande_est_en_cours`
 * figeait ce refus avec une commande PENDING, alors que son propre commentaire ne le
 * justifie que « quand la cuisine est en train de préparer ». Le test était plus large
 * que sa raison.
 *
 * RÈGLE désormais : les commandes que le client aurait pu annuler lui-même (jamais
 * acceptées / jamais commencées selon le seuil du service, jamais payées) sont ANNULÉES
 * par le chemin existant d'annulation client — même service, même journal, même raison
 * obligatoire, même remboursement de points — puis le compte est effacé. Dès que la
 * cuisine a commencé, ou que de l'argent a été encaissé, le refus reste, avec un message
 * qui dit POURQUOI et QUOI FAIRE.
 */
class DeleteAccountAfterCounterOrderTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'test-api-key';

    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        if (! file_exists(storage_path('installed'))) {
            touch(storage_path('installed'));
        }
        config(['app.api_key' => self::API_KEY]);
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        $this->branch = Branch::factory()->create();
        $table = config('settings.repositories.database.table', 'settings');
        if (Schema::hasTable($table)) {
            DB::table($table)->updateOrInsert(
                ['key' => 'site_default_branch', 'group' => 'site'],
                ['payload' => json_encode((string) $this->branch->id), 'created_at' => now(), 'updated_at' => now()]
            );
        }
        $this->withHeaders(['x-api-key' => self::API_KEY, 'Accept' => 'application/json']);
    }

    private function client(): User
    {
        $user = User::factory()->create([
            'branch_id'         => 0,
            'name'              => 'Examinateur Store',
            'phone'             => '0612345678',
            'email'             => 'examinateur@example.test',
            'email_verified_at' => now()->timestamp,
            'status'            => Status::ACTIVE,
            'is_guest'          => Ask::YES,
        ]);
        $user->assignRole('Customer');

        return $user;
    }

    private function jeton(User $user): string
    {
        return $user->createToken('auth_token', ['kiosk:order'])->plainTextToken;
    }

    /** Commande « payer sur place » passée depuis l'app : web, espèces, jamais payée. */
    private function commandeSurPlace(User $user, int $statut, int $paiement = PaymentStatus::UNPAID): Order
    {
        return Order::factory()->create([
            'user_id'        => $user->id,
            'branch_id'      => $this->branch->id,
            'status'         => $statut,
            'payment_status' => $paiement,
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'order_type'     => OrderType::TAKEAWAY,
            'source_surface' => 'web',
            'total'          => 11.40,
            'subtotal'       => 11.40,
        ]);
    }

    private function supprimer(User $user)
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->jeton($user))
            ->postJson('/api/auth/delete-account');
    }

    /** @test */
    public function une_commande_sur_place_jamais_commencee_est_annulee_puis_le_compte_est_efface(): void
    {
        $user = $this->client();
        $commande = $this->commandeSurPlace($user, OrderStatus::PENDING);

        $this->supprimer($user)->assertStatus(200)->assertJson(['status' => true]);

        $apresCommande = Order::withoutGlobalScopes()->find($commande->id);
        $this->assertSame(
            OrderStatus::CANCELED,
            (int) $apresCommande->status,
            'La commande jamais commencée doit être ANNULÉE (jamais supprimée) avant l\'effacement.'
        );
        $this->assertNotNull($apresCommande, 'La commande reste en base : la loi impose de garder les tickets.');

        $apres = User::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)->withTrashed()->find($user->id);
        $this->assertNotNull($apres->deleted_at, 'Le compte doit être supprimé.');
        $this->assertNull($apres->email, 'L\'e-mail doit avoir disparu.');
        $this->assertNotSame('0612345678', $apres->phone, 'Le téléphone doit avoir disparu.');
    }

    /** @test */
    public function l_annulation_passe_par_le_journal_des_transitions_avec_une_raison(): void
    {
        $user = $this->client();
        $commande = $this->commandeSurPlace($user, OrderStatus::PENDING);

        $this->supprimer($user)->assertStatus(200);

        $transition = DB::table('order_status_transitions')
            ->where('order_id', $commande->id)
            ->where('to_status', OrderStatus::CANCELED)
            ->first();
        $this->assertNotNull($transition, 'L\'annulation doit être journalisée comme toute annulation client.');
        $this->assertNotSame(
            '',
            trim((string) ($transition->reason ?? '')),
            'Une annulation sans raison est interdite par la machine à états : la suppression de compte doit en donner une.'
        );
    }

    /** @test */
    public function le_refus_demeure_quand_la_cuisine_a_commence(): void
    {
        $user = $this->client();
        $commande = $this->commandeSurPlace($user, OrderStatus::PREPARING);

        $reponse = $this->supprimer($user)->assertStatus(422)->assertJson(['status' => false]);

        $this->assertSame(OrderStatus::PREPARING, (int) Order::withoutGlobalScopes()->find($commande->id)->status, 'Un refus ne touche pas la commande.');
        $apres = User::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)->withTrashed()->find($user->id);
        $this->assertNull($apres->deleted_at, 'Un refus n\'efface rien.');
        $this->assertSame('0612345678', $apres->phone, 'Un refus n\'efface rien.');
        $this->assertMatchesRegularExpression(
            '/prépar|prepar|cuisine|kitchen|retir|collect/iu',
            (string) $reponse->json('message'),
            'Le message doit dire POURQUOI (la cuisine prépare) et QUOI FAIRE (retirer / attendre) — en français ou dans la locale de la suite.'
        );
    }

    /** @test */
    public function le_refus_demeure_quand_de_l_argent_a_ete_encaisse(): void
    {
        $user = $this->client();
        $commande = $this->commandeSurPlace($user, OrderStatus::PENDING, PaymentStatus::PAID);

        $this->supprimer($user)->assertStatus(422)->assertJson(['status' => false]);

        $this->assertSame(OrderStatus::PENDING, (int) Order::withoutGlobalScopes()->find($commande->id)->status, 'Une commande payée n\'est jamais annulée en douce.');
        $apres = User::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)->withTrashed()->find($user->id);
        $this->assertNull($apres->deleted_at);
    }

    /** @test */
    public function plusieurs_commandes_jamais_commencees_sont_toutes_annulees(): void
    {
        $user = $this->client();
        $a = $this->commandeSurPlace($user, OrderStatus::PENDING);
        $b = $this->commandeSurPlace($user, OrderStatus::PENDING);

        $this->supprimer($user)->assertStatus(200);

        foreach ([$a, $b] as $c) {
            $this->assertSame(OrderStatus::CANCELED, (int) Order::withoutGlobalScopes()->find($c->id)->status);
        }
    }

    /** @test */
    public function une_commande_deja_livree_ne_bloque_pas_et_n_est_pas_touchee(): void
    {
        $user = $this->client();
        $livree = $this->commandeSurPlace($user, OrderStatus::DELIVERED, PaymentStatus::PAID);

        $this->supprimer($user)->assertStatus(200);

        $this->assertSame(OrderStatus::DELIVERED, (int) Order::withoutGlobalScopes()->find($livree->id)->status, 'Le ticket livré reste tel quel : conservation fiscale.');
    }
}
