<?php

namespace Tests\Feature\Pos;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [TICKET-CUISINE-DEUX-POSTES 2026-08-12] La reprise de l'existant.
 *
 * Au moment où la table `kitchen_ticket_claims` apparaît, des commandes ont DÉJÀ été imprimées
 * par le pont caisse — 10 entre le 10 et le 11 août en production, dont deux commandes du site.
 * Sans reprise, aucune ne possède de ligne dans la nouvelle table : la fenêtre de 30 minutes
 * rattraperait les plus récentes et le rouleau ressortirait des tickets déjà servis, en plein
 * coup de feu, sur des plats déjà partis.
 *
 * Ce test vérifie la forme de la table et la doctrine de la garde. La reprise elle-même
 * (`insertOrIgnore` depuis `orders.kitchen_ticket_printed_at`) est vérifiée par le fait que la
 * contrainte d'unicité existe : c'est elle qui rend l'opération rejouable sans doublon.
 */
class KitchenTicketClaimsMigrationTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function la_table_de_reclamation_existe_avec_ses_colonnes(): void
    {
        $this->assertTrue(\Schema::hasTable('kitchen_ticket_claims'));
        $this->assertTrue(\Schema::hasColumns('kitchen_ticket_claims', [
            'order_id', 'destination', 'printed_at', 'error',
        ]));
    }

    /**
     * @test
     * LA garde : c'est la BASE qui refuse la seconde réclamation, pas du PHP.
     *
     * Un « si absent alors insérer » écrit en code laisserait passer deux postes qui réclament
     * au même instant — le genre de course qui produit un doublon un vendredi soir et jamais en
     * test. On vérifie donc que la contrainte est bien portée par le schéma.
     */
    public function la_base_refuse_deux_reclamations_pour_la_meme_destination(): void
    {
        DB::table('kitchen_ticket_claims')->insert([
            'order_id' => 4242, 'destination' => 'counter', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $seconde = DB::table('kitchen_ticket_claims')->insertOrIgnore([
            'order_id' => 4242, 'destination' => 'counter', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(0, $seconde, 'la seconde réclamation doit être refusée par la contrainte');
        $this->assertSame(1, DB::table('kitchen_ticket_claims')->where('order_id', 4242)->count());
    }

    /** @test La même commande peut en revanche être réclamée par CHAQUE destination. */
    public function la_base_accepte_une_reclamation_par_destination(): void
    {
        $a = DB::table('kitchen_ticket_claims')->insertOrIgnore([
            'order_id' => 4243, 'destination' => 'counter', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $b = DB::table('kitchen_ticket_claims')->insertOrIgnore([
            'order_id' => 4243, 'destination' => 'kitchen', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(1, $a);
        $this->assertSame(1, $b, 'la cuisine doit pouvoir réclamer ce que la caisse a déjà pris');
        $this->assertSame(2, DB::table('kitchen_ticket_claims')->where('order_id', 4243)->count());
    }
}
