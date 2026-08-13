<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * LE SOLDE DE POINTS D'UN CLIENT DOIT ÊTRE VISIBLE DEPUIS L'ADMINISTRATION.
 *
 * ── LE DÉFAUT, ET CE QU'IL EMPÊCHE CONCRÈTEMENT ──────────────────────────────────────────────
 * L'écran « Clients » n'affichait AUCUN point : ni dans la liste, ni sur la fiche. Mesuré en
 * production le 2026-08-13 : **25 adhérents** à la fidélité, et pas un seul endroit dans
 * l'administration pour voir ce qu'ils ont.
 *
 * Ce n'est pas un confort manquant, c'est deux gestes de patron rendus impossibles :
 *   · répondre à « pourquoi j'ai ce solde ? » quand un client conteste au comptoir ;
 *   · décider quoi que ce soit sur la fidélité — on ne pilote pas ce qu'on ne voit pas.
 *
 * ── CE QUI EXISTAIT DÉJÀ, ET POURQUOI LE DÉFAUT EST PASSÉ INAPERÇU ───────────────────────────
 * Le solde était DÉJÀ servi par `UserResource:38`. Il ne manquait que dans `CustomerResource`,
 * qui est la ressource de CET écran-là. Deux ressources pour la même personne, une seule à jour :
 * c'est exactement le motif du « jumeau oublié » rencontré trois fois dans ce projet — un correctif
 * posé sur une des deux copies, et personne ne voit la seconde.
 *
 * ⛔ Ce banc verrouille la RESSOURCE, pas l'écran : c'est elle qui décide ce que l'administration
 * peut montrer. Un jour où l'on refera l'écran, le test tiendra encore.
 */
class CustomerLoyaltyVisibleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * LE CŒUR : la ressource des clients porte le solde et le code de fidélité.
     */
    public function test_la_ressource_client_porte_le_solde_et_le_code(): void
    {
        $client = User::factory()->create(['phone' => '0655112233']);
        DB::table('users')->where('id', $client->id)
            ->update(['loyalty_code' => 'VISIBLE1', 'loyalty_points' => 2400]);

        $rendu = (new \App\Http\Resources\CustomerResource($client->fresh()))
            ->toArray(request());

        $this->assertArrayHasKey('loyalty_points', $rendu,
            'la liste des clients ne peut pas afficher les points : la ressource ne les porte pas');
        $this->assertSame(2400, $rendu['loyalty_points']);
        $this->assertSame('VISIBLE1', $rendu['loyalty_code']);
    }

    /**
     * Un client SANS fidélité ne doit pas casser l'écran ni afficher un vide ambigu : zéro point
     * est une information, `null` n'en est pas une.
     */
    public function test_un_client_sans_fidelite_rend_zero_et_pas_null(): void
    {
        $client = User::factory()->create(['phone' => '0655112244']);

        $rendu = (new \App\Http\Resources\CustomerResource($client->fresh()))
            ->toArray(request());

        $this->assertSame(0, $rendu['loyalty_points'], 'un solde absent doit valoir 0, jamais null');
        $this->assertNull($rendu['loyalty_code']);
    }
}
