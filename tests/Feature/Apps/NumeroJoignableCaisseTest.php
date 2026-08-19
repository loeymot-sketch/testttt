<?php

namespace Tests\Feature\Apps;

use App\Enums\Ask;
use App\Enums\Status;
use App\Http\Resources\SimpleOrderResource;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * [APPS 2026-08-19] Le restaurant doit POUVOIR APPELER le client — c'est toute la demande.
 *
 * POURQUOI CE TEST EXISTE
 * -----------------------
 * Le numéro déclaré après une connexion Apple ou Google a été déplacé dans une colonne à
 * part (`contact_phone`), pour qu'il ne serve jamais de clé d'identité — un numéro non
 * prouvé qui donne accès à quelque chose, c'est un compte qu'on peut squatter.
 *
 * Mais un déplacement, c'est aussi une occasion parfaite de PERDRE la donnée en route. Si le
 * numéro n'arrive plus jusqu'à la caisse, on a résolu un problème de sécurité en cassant
 * exactement ce que l'exploitant avait demandé : « en cas de problème sur une commande, on
 * doit pouvoir l'appeler ». Ces tests vérifient donc les deux bouts de la chaîne.
 */
class NumeroJoignableCaisseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (! file_exists(storage_path('installed'))) {
            touch(storage_path('installed'));
        }
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    private function client(array $attrs): User
    {
        $user = User::factory()->create(array_merge([
            'branch_id' => 0,
            'status'    => Status::ACTIVE,
            'is_guest'  => Ask::YES,
        ], $attrs));
        $user->assignRole('Customer');

        return $user;
    }

    // ------------------------------------------------------ le résolveur lui-même

    /** @test */
    public function un_numero_prouve_est_le_numero_joignable(): void
    {
        $u = $this->client(['phone' => '0611111111']);
        $this->assertSame('0611111111', $u->numeroJoignable());
    }

    /** @test */
    public function a_defaut_le_numero_declare_fait_office(): void
    {
        // Le cas d'un compte ouvert par connexion Apple/Google : `phone` porte la sentinelle
        // posée par User::creating, et le vrai numéro est dans `contact_phone`.
        $u = $this->client(['phone' => null]);
        $u->contact_phone = '0622222222';
        $u->save();

        $this->assertSame('0622222222', $u->fresh()->numeroJoignable());
    }

    /** @test */
    public function la_sentinelle_n_est_jamais_prise_pour_un_numero(): void
    {
        // Sans ce garde, un caissier lirait « PENDING_CREATE_9f2a… » à la place d'un numéro,
        // et croirait avoir un contact alors qu'il n'en a aucun.
        $u = $this->client(['phone' => null]);

        $this->assertStringStartsWith('PENDING_', (string) $u->phone, 'Prérequis du scénario.');
        $this->assertNull($u->numeroJoignable());
    }

    // ------------------------------------------------ jusqu'à l'écran de la caisse

    /** @test */
    public function la_caisse_voit_le_numero_declare_d_une_commande_venue_de_l_application(): void
    {
        $branch = Branch::factory()->create();

        $client = $this->client(['phone' => null, 'name' => 'Client App']);
        $client->contact_phone = '0633333333';
        $client->save();

        $commande = Order::factory()->create([
            'user_id'        => $client->id,
            'branch_id'      => $branch->id,
            'source_surface' => 'web',
        ]);
        $commande->setRelation('user', $client->fresh());

        $rendu = (new SimpleOrderResource($commande))->toArray(Request::create('/'));

        $this->assertSame(
            '0633333333',
            $rendu['customer_phone'],
            "La caisse doit voir le numéro déclaré : sans lui, personne ne peut rappeler ce "
            . "client si un produit manque ou s'il ne vient pas chercher sa commande."
        );
    }

    /** @test */
    public function la_caisse_ne_voit_jamais_la_sentinelle(): void
    {
        $branch = Branch::factory()->create();

        // Compte sans aucun numéro : ni prouvé, ni déclaré.
        $client = $this->client(['phone' => null, 'name' => 'Sans numéro']);

        $commande = Order::factory()->create([
            'user_id'        => $client->id,
            'branch_id'      => $branch->id,
            'source_surface' => 'web',
        ]);
        $commande->setRelation('user', $client->fresh());

        $rendu = (new SimpleOrderResource($commande))->toArray(Request::create('/'));

        $this->assertNull(
            $rendu['customer_phone'],
            'Mieux vaut un champ vide, qui se voit, qu\'un « PENDING_… » qu\'on prend pour un numéro.'
        );
    }
}
