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

    /**
     * @test
     * LA COURSE ENTRE DEUX POSTES — la garde que l'endpoint ne peut PAS atteindre.
     *
     * Découvert par mutation le 2026-08-12 : retirer la condition de péremption dans
     * `claimForBridge()` ne faisait tomber AUCUN des 19 tests de la file. Non parce que la garde
     * est inutile, mais parce qu'elle est INATTEIGNABLE par l'endpoint : la requête de la file
     * écarte déjà la commande quand le verrou est frais, donc `claimForBridge()` n'est jamais
     * appelé dans ce cas.
     *
     * Elle sert dans le seul cas que l'endpoint ne sait pas produire : deux postes qui LISENT la
     * file au même instant, avant que l'un ait écrit. Tous deux voient la commande candidate,
     * tous deux appellent la réclamation. Le premier gagne par l'INSERT ; sans la condition de
     * péremption, le second « reprendrait » le verrou tout frais du premier — et le ticket
     * sortirait DEUX fois.
     *
     * On vise donc le service directement. Une garde qu'aucun test ne peut atteindre n'est pas
     * une garde : c'est une intention.
     */
    public function un_second_poste_ne_reprend_pas_un_verrou_tout_frais(): void
    {
        $service = app(\App\Services\Kitchen\KitchenTicketAutoPrinter::class);

        $this->assertTrue(
            $service->claimForBridge(9101, 'counter'),
            'le premier poste doit obtenir le ticket'
        );

        $this->assertFalse(
            $service->claimForBridge(9101, 'counter'),
            'le second poste ne doit PAS reprendre un verrou frais — sinon le ticket sort deux fois'
        );

        $this->assertSame(1, DB::table('kitchen_ticket_claims')->where('order_id', 9101)->count());
    }

    /** @test …mais passé le délai, le verrou d'un poste mort est bien repris. */
    public function un_verrou_abandonne_est_repris_apres_le_delai(): void
    {
        $service = app(\App\Services\Kitchen\KitchenTicketAutoPrinter::class);

        $this->assertTrue($service->claimForBridge(9102, 'counter'));

        $this->travel(config('kds.bridge_claim_ttl_seconds', 90) + 30)->seconds();

        $this->assertTrue(
            $service->claimForBridge(9102, 'counter'),
            'un verrou abandonné doit être repris, sinon le ticket est perdu pour toujours'
        );
        $this->assertSame(1, DB::table('kitchen_ticket_claims')->where('order_id', 9102)->count());
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
